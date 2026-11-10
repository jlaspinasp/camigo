<?php
session_start();
require("db.php");
require("auth.php");
require("events-lib.php");

$me = require_user_row($conn);

// ---- which list ----
$tab = $_GET['tab'] ?? 'upcoming';
if (!in_array($tab, array('upcoming', 'mine', 'going', 'past'), true)) $tab = 'upcoming';
$q = trim($_GET['q'] ?? '');
if (str_len($q) > 60) $q = str_cut($q, 60);

$now = event_now_sql();
$endSql = EVENT_END_SQL;

$where = "";
$types = "";
$params = array();
$order = "e.start_at ASC";

if ($tab === 'past') {
    $where = "$endSql < ?";
    $order = "e.start_at DESC";
} else {
    $where = "$endSql >= ?";
}
$types .= "s";
$params[] = $now;

if ($tab === 'mine') {
    $where .= " AND e.club_id IN (SELECT club_id FROM club_members WHERE user_id = ? AND status = 'active')";
    $types .= "i";
    $params[] = (int)$me['id'];
} elseif ($tab === 'going') {
    $where .= " AND e.id IN (SELECT event_id FROM event_rsvps WHERE user_id = ?)";
    $types .= "i";
    $params[] = (int)$me['id'];
}

if ($q !== '') {
    $like = '%' . str_replace(array('\\', '%', '_'), array('\\\\', '\\%', '\\_'), $q) . '%';
    $where .= " AND (e.title LIKE ? OR e.location LIKE ? OR c.name LIKE ?)";
    $types .= "sss";
    array_push($params, $like, $like, $like);
}

$LIMIT = 40;
$res = events_query($conn, $me['id'], $where, $types, $params, $order, $LIMIT);
$setupMissing = !$res['ok'];
$events = $res['rows'];

// can this person post events?
$owned = $setupMissing ? array() : event_postable_clubs($conn, $me['id']);

$flash = flash_get();
$message = $flash ? $flash[0] : "";
$message_class = $flash ? $flash[1] : "";

$backUrl = 'events' . ($tab !== 'upcoming' || $q !== '' ? '?' . http_build_query(array_filter(array('tab' => $tab !== 'upcoming' ? $tab : '', 'q' => $q))) : '');
$filtered = ($q !== '');

function tab_url($t, $q) {
    $a = array();
    if ($t !== 'upcoming') $a['tab'] = $t;
    if ($q !== '') $a['q'] = $q;
    return 'events' . ($a ? '?' . http_build_query($a) : '');
}

$emptyTitle = array(
    'upcoming' => "No upcoming events yet",
    'mine'     => "Nothing coming up in your clubs",
    'going'    => "You haven't RSVP'd to anything yet",
    'past'     => "No past events",
);
$emptyText = array(
    'upcoming' => "When a club posts an event, it shows up here.",
    'mine'     => "Join a club, or check back when your clubs plan something.",
    'going'    => "Find something you like and press “I'm going”.",
    'past'     => "Events that already happened will be listed here.",
);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Events | Camigo</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:wght@600;700;800&family=Instrument+Sans:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
</head>
<body>

<?php include("topbar.php"); ?>

<main class="main narrow wide-ev">

    <div class="page-top">
        <div>
            <h1 class="page-title">Events</h1>
            <p class="page-sub">See what's happening around campus and let the organizers know you're coming.</p>
        </div>
        <?php if ($owned) { ?>
            <div class="top-actions">
                <a href="create-event" class="btn-sm btn-dark">Post an event</a>
            </div>
        <?php } ?>
    </div>

    <?php if ($message != "") { ?>
        <div class="msg <?php echo e($message_class); ?>" style="margin-top:20px"><?php echo e($message); ?></div>
    <?php } ?>

    <div class="tabs">
        <a href="<?php echo e(tab_url('upcoming', $q)); ?>" class="<?php echo $tab === 'upcoming' ? 'active' : ''; ?>">Upcoming</a>
        <a href="<?php echo e(tab_url('mine', $q)); ?>" class="<?php echo $tab === 'mine' ? 'active' : ''; ?>">My clubs</a>
        <a href="<?php echo e(tab_url('going', $q)); ?>" class="<?php echo $tab === 'going' ? 'active' : ''; ?>">I'm going</a>
        <a href="<?php echo e(tab_url('past', $q)); ?>" class="<?php echo $tab === 'past' ? 'active' : ''; ?>">Past</a>
    </div>

    <form method="GET" class="filters">
        <?php if ($tab !== 'upcoming') { ?><input type="hidden" name="tab" value="<?php echo e($tab); ?>"><?php } ?>
        <label class="ev-search">
            <?php echo event_icon('search'); ?>
            <input type="text" name="q" maxlength="60" placeholder="Search by event, club or place" value="<?php echo e($q); ?>">
        </label>
        <button type="submit" class="btn-sm btn-dark">Search</button>
        <?php if ($filtered) { ?><a href="<?php echo e(tab_url($tab, '')); ?>" class="cancel">Clear</a><?php } ?>
    </form>

    <?php if ($setupMissing) { ?>

        <div class="msg error">Events aren't fully set up yet. Run <strong>events.sql</strong>, then <strong>events-members-only.sql</strong> in phpMyAdmin.</div>

    <?php } elseif (!$events) { ?>

        <section class="panel center">
            <div class="ev-empty-ic"><?php echo event_icon('calendar'); ?></div>
            <h2><?php echo $filtered ? "No events match your search" : e($emptyTitle[$tab]); ?></h2>
            <p class="empty"><?php echo $filtered ? "Try a different word." : e($emptyText[$tab]); ?></p>
        </section>

    <?php } else { ?>

        <div class="ev-list">
            <?php
            $lastMonth = '';
            foreach ($events as $ev) {
                $month = event_dt($ev['start_at'])->format('F Y');
                if ($month !== $lastMonth) {
                    $lastMonth = $month;
                    echo '<h2 class="ev-month">' . e($month) . '</h2>';
                }
                render_event_card($ev, $backUrl);
            }
            ?>
        </div>

        <?php if (count($events) >= $LIMIT) { ?>
            <p class="empty" style="margin-top:18px">Showing the first <?php echo (int)$LIMIT; ?> events. Use search to narrow it down.</p>
        <?php } ?>

    <?php } ?>

</main>

</body>
</html>