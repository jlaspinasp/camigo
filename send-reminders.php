<?php
// Event reminders.
// A cron service opens this page every 5 to 15 minutes:
//     https://YOUR-SITE/send-reminders.php?key=YOUR_CRON_KEY
// It sends three kinds of in-app notification (each person gets each one at most once per event):
//     day   - the day before (to people who RSVP'd)
//     hour  - about an hour before (to people who RSVP'd)
//     nudge - the day before, to club members who haven't RSVP'd (only for people who chose "going + my clubs")
// It is safe to run as often as you like.

require(__DIR__ . "/db.php");
require(__DIR__ . "/auth.php");
require(__DIR__ . "/events-lib.php");
if (is_file(__DIR__ . '/cron_config.php')) {
    require_once __DIR__ . '/cron_config.php';
}

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');

if (PHP_SAPI !== 'cli') {
    $given = (string)($_GET['key'] ?? '');
    if (!defined('CRON_KEY') || CRON_KEY === '' || !hash_equals(CRON_KEY, $given)) {
        http_response_code(403);
        echo "Forbidden\n";
        exit();
    }
}

// ---- is everything set up? ----
$t = mysqli_query($conn, "SHOW TABLES LIKE 'event_reminders'");
if (!$t || mysqli_num_rows($t) === 0) {
    echo "Not set up: run event-reminders.sql in phpMyAdmin first.\n";
    exit();
}

$c = mysqli_query($conn, "SHOW COLUMNS FROM users LIKE 'reminder_pref'");
$prefReady = ($c && mysqli_num_rows($c) > 0);

// if notifications.type is an ENUM that doesn't know 'event_reminder', fall back to an existing type
$ntype = 'event_reminder';
$c = mysqli_query($conn, "SHOW COLUMNS FROM notifications LIKE 'type'");
if ($c && ($col = mysqli_fetch_assoc($c))) {
    $ty = strtolower((string)($col['Type'] ?? ''));
    if (strpos($ty, 'enum(') === 0 && strpos($ty, "'event_reminder'") === false) {
        $ntype = 'event_update';
    }
}

// ---- helpers ----
// "today at 6:00 PM" / "tomorrow at 6:00 PM" / "Wed, Nov 11 at 6:00 PM"
function rem_when($start, $now) {
    $t   = $start->format('g:i A');
    $d1  = $start->format('Y-m-d');
    if ($d1 === $now->format('Y-m-d')) return 'today at ' . $t;
    $tom = (clone $now)->modify('+1 day')->format('Y-m-d');
    if ($d1 === $tom) return 'tomorrow at ' . $t;
    return $start->format('D, M j') . ' at ' . $t;
}

// Claims the people who still need this reminder (the unique key skips anyone already reminded),
// then notifies exactly the people it claimed. Returns how many were notified.
function rem_send($conn, $eventId, $kind, $claimSql, $ntype, $message) {
    $batch = bin2hex(random_bytes(8));
    $r = db_run($conn, $claimSql, "ssi", array($kind, $batch, (int)$eventId));
    if (!$r['ok'] || $r['affected'] < 1) return 0;

    $n = db_run($conn,
        "INSERT INTO notifications (user_id, type, message, link)
         SELECT user_id, ?, ?, ? FROM event_reminders WHERE batch = ? AND event_id = ? AND kind = ?",
        "ssssis", array($ntype, str_cut($message, 255), 'event?id=' . (int)$eventId, $batch, (int)$eventId, $kind));

    if (!$n['ok']) {
        // couldn't notify: release the claim so the next run tries again
        db_run($conn, "DELETE FROM event_reminders WHERE batch = ?", "s", array($batch));
        error_log("Reminder notify failed for event " . (int)$eventId . " ($kind)");
        return 0;
    }
    return (int)$r['affected'];
}

$rsvpPref = $prefReady ? " AND u.reminder_pref IN ('going', 'all')" : "";

$claimRsvp = "INSERT IGNORE INTO event_reminders (event_id, user_id, kind, batch)
              SELECT r.event_id, r.user_id, ?, ? FROM event_rsvps r JOIN users u ON u.id = r.user_id
              WHERE r.event_id = ?" . $rsvpPref;

$claimNudge = "INSERT IGNORE INTO event_reminders (event_id, user_id, kind, batch)
               SELECT e.id, m.user_id, ?, ? FROM events e
               JOIN club_members m ON m.club_id = e.club_id AND m.status = 'active'
               JOIN users u ON u.id = m.user_id
               WHERE e.id = ? AND u.reminder_pref = 'all'
                 AND m.user_id NOT IN (SELECT x.user_id FROM event_rsvps x WHERE x.event_id = e.id)";

// ---- find events that start within the next 25 hours ----
$now    = event_now();
$nowSql = $now->format('Y-m-d H:i:s');
$until  = (clone $now)->modify('+25 hours')->format('Y-m-d H:i:s');

$ev = db_run($conn,
    "SELECT e.id, e.title, e.location, e.start_at, e.capacity, c.name AS club_name,
            TIMESTAMPDIFF(MINUTE, ?, e.start_at) AS mins,
            (SELECT COUNT(*) FROM event_rsvps r WHERE r.event_id = e.id) AS going
     FROM events e JOIN clubs c ON c.id = e.club_id AND c.status = 'approved'
     WHERE e.status = 'active' AND e.start_at >= ? AND e.start_at <= ?
     ORDER BY e.start_at ASC",
    "sss", array($nowSql, $nowSql, $until));

if (!$ev['ok']) {
    echo "Couldn't read events. Have events.sql and the other event upgrades been run?\n";
    exit();
}

$sent = array('day' => 0, 'hour' => 0, 'nudge' => 0);

foreach ($ev['rows'] as $e) {
    $mins  = (int)$e['mins'];
    $start = event_dt($e['start_at']);
    $when  = rem_when($start, $now);
    $loc   = trim((string)$e['location']);
    $where = ($loc !== '') ? ' · ' . $loc : '';

    // about an hour before
    if ($mins >= 0 && $mins <= 75) {
        $in = ($mins >= 50) ? 'in about an hour' : ($mins < 5 ? 'now' : 'in ' . (int)(round($mins / 5) * 5) . ' min');
        $sent['hour'] += rem_send($conn, $e['id'], 'hour', $claimRsvp, $ntype,
            'Starting soon: ' . $e['title'] . ' begins ' . $in . ' (' . $start->format('g:i A') . ')' . $where);
    }

    // the day before (not for events that are only a couple of hours away: the hour reminder covers those)
    if ($mins > 180 && $mins <= 1500) {
        $sent['day'] += rem_send($conn, $e['id'], 'day', $claimRsvp, $ntype,
            'Reminder: ' . $e['title'] . ' is ' . $when . $where);

        $full = ($e['capacity'] !== null && (int)$e['going'] >= (int)$e['capacity']);
        if ($prefReady && !$full) {
            $sent['nudge'] += rem_send($conn, $e['id'], 'nudge', $claimNudge, $ntype,
                $e['club_name'] . ': ' . $e['title'] . ' is ' . $when . '. Haven\'t RSVP\'d yet? Tap to join.');
        }
    }
}

// tidy up old bookkeeping
db_run($conn, "DELETE FROM event_reminders WHERE sent_at < (NOW() - INTERVAL 30 DAY)");

echo "Camigo reminders  " . $nowSql . "\n";
echo "Events checked: " . count($ev['rows']) . "\n";
echo "Sent: " . $sent['day'] . " day-before, " . $sent['hour'] . " hour-before, " . $sent['nudge'] . " club nudges\n";
echo "OK\n";