<?php
session_start();
require("db.php");
require("auth.php");
require("events-lib.php");

$me = require_user_row($conn);
require_verified($conn, $me, 'post an event');

// owners and officers of approved clubs can post events
$owned = event_postable_clubs($conn, $me['id']);
$ownedIds = array();
foreach ($owned as $c) $ownedIds[(int)$c['id']] = $c['name'];

$wantClub = (int)($_POST['club_id'] ?? $_GET['club'] ?? 0);
if (!isset($ownedIds[$wantClub])) {
    $wantClub = $owned ? (int)$owned[0]['id'] : 0;
}

$message = "";
$f = array('title' => '', 'event_date' => '', 'start_time' => '', 'end_time' => '', 'location' => '', 'capacity' => '', 'description' => '', 'visibility' => 'public');

if ($owned && isset($_POST['post'])) {
    foreach ($f as $k => $v) $f[$k] = (string)($_POST[$k] ?? '');
    $error = "";

    if (!csrf_ok()) {
        $error = "Your session expired. Please try again.";
    } elseif (!isset($ownedIds[(int)($_POST['club_id'] ?? 0)])) {
        $error = "You can only post events for clubs where you're an owner or officer.";
    } else {
        $clean = event_clean($_POST, true, $error);

        if ($clean) {
            $rl = db_run($conn, "SELECT COUNT(*) AS n FROM events WHERE created_by = ? AND created_at > (NOW() - INTERVAL 1 HOUR)", "i", array($me['id']));
            if ($rl['ok'] && $rl['rows'] && (int)$rl['rows'][0]['n'] >= 10) {
                $error = "You've posted a lot of events in the last hour. Please wait a bit.";
            } else {
                $clubId = (int)$_POST['club_id'];
                $r = db_run($conn,
                    "INSERT INTO events (club_id, created_by, title, description, location, start_at, end_at, capacity, visibility)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
                    "iisssssis",
                    array($clubId, $me['id'], $clean['title'], $clean['description'], $clean['location'], $clean['start_at'], $clean['end_at'], $clean['capacity'], $clean['visibility']));

                if ($r['ok']) {
                    $newId = (int)mysqli_insert_id($conn);
                    $excl = ($clean['visibility'] === 'members');
                    event_notify_members($conn, $clubId, $me['id'], full_name($me) . ' posted a new ' . ($excl ? 'members-only ' : '') . 'event in ' . $ownedIds[$clubId] . ': ' . $clean['title'], 'event?id=' . $newId);
                    flash_set($excl ? "Your members-only event is live. Only club members can see it, and they've been notified." : "Your event is live. Club members have been notified.", "success");
                    header("Location: event?id=" . $newId);
                    exit();
                }
                $error = ($r['errno'] == 1146 || $r['errno'] == 1054) ? "Events aren't fully set up yet. Run events.sql, then events-members-only.sql in phpMyAdmin." : "Something went wrong. Please try again.";
            }
        }
    }
    $message = $error;
}

$minDate = event_now()->format('Y-m-d');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Post an event | Camigo</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:wght@600;700;800&family=Instrument+Sans:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
</head>
<body>

<?php include("topbar.php"); ?>

<main class="main narrow">

    <p class="crumb"><a href="events">&larr; All events</a></p>
    <h1 class="page-title">Post an event</h1>
    <p class="page-sub">Tell students what's coming up. Your club members get a notification straight away.</p>

    <?php if (!$owned) { ?>

        <section class="panel center">
            <h2>Only club owners and officers can post events</h2>
            <p class="empty">Own a club, or ask a club owner to make you an officer. <a href="create-club">Create a club</a> or <a href="clubs?mine=1">see your clubs</a>.</p>
        </section>

    <?php } else { ?>

        <?php if ($message != "") { ?>
            <div class="msg error" style="margin-top:20px"><?php echo e($message); ?></div>
        <?php } ?>

        <form method="POST" class="panel ev-form">
            <?php echo csrf_field(); ?>

            <?php if (count($owned) > 1) { ?>
                <div class="field">
                    <label for="club_id">Hosting club</label>
                    <select name="club_id" id="club_id">
                        <?php foreach ($owned as $c) { ?>
                            <option value="<?php echo (int)$c['id']; ?>" <?php echo ((int)$c['id'] === $wantClub) ? 'selected' : ''; ?>><?php echo e($c['name']); ?></option>
                        <?php } ?>
                    </select>
                </div>
            <?php } else { ?>
                <input type="hidden" name="club_id" value="<?php echo $wantClub; ?>">
                <div class="ev-hosting">Hosting as <strong><?php echo e($ownedIds[$wantClub]); ?></strong></div>
            <?php } ?>

            <?php include("event-fields.php"); ?>

            <div class="form-actions">
                <button type="submit" name="post" class="btn inline">Publish event</button>
                <a href="events" class="cancel">Cancel</a>
            </div>
        </form>

    <?php } ?>

</main>

</body>
</html>