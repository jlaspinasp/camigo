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

// 0 = no hosting club. Anyone verified can post with no club; clubs are only listed if you own or officer them.
$wantClub = null;
if (isset($_POST['club_id']))     $wantClub = (int)$_POST['club_id'];
elseif (isset($_GET['club']))     $wantClub = (int)$_GET['club'];
if ($wantClub === null || ($wantClub !== 0 && !isset($ownedIds[$wantClub]))) {
    $wantClub = $owned ? (int)$owned[0]['id'] : 0;
}

// event banner: an upload is optional. Preview data for the form (club covers are the fallback for club events).
$bannerReady = events_banner_ready($conn);
$bannerCur   = '';
$bannerClubs = !empty($owned);
$bannerCoversIn = array();
foreach ($owned as $c) { if (!empty($c['cover'])) $bannerCoversIn[(int)$c['id']] = $c['cover']; }
$bannerClub  = $wantClub;

$message = "";
// a file larger than the server's post limit arrives as an empty form
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    $message = "That upload was too large for the server. Try a smaller image (up to 10 MB).";
}
$f = array('title' => '', 'event_date' => '', 'start_time' => '', 'end_time' => '', 'location' => '', 'capacity' => '', 'description' => '', 'visibility' => 'public');
$visHint = 'Members only needs a hosting club.';

if (isset($_POST['post'])) {
    foreach ($f as $k => $v) $f[$k] = (string)($_POST[$k] ?? '');
    $error = "";

    if (!csrf_ok()) {
        $error = "Your session expired. Please try again.";
    } elseif ((int)($_POST['club_id'] ?? 0) !== 0 && !isset($ownedIds[(int)($_POST['club_id'] ?? 0)])) {
        $error = "You can only host with a club where you're an owner or officer.";
    } elseif ((int)($_POST['club_id'] ?? 0) === 0 && !events_clubless_ready($conn)) {
        $error = "Posting without a club isn't set up yet. Run events-no-club.sql in phpMyAdmin.";
    } else {
        $clean = event_clean($_POST, true, $error);
        // no club, no members-only
        if ($clean && (int)($_POST['club_id'] ?? 0) === 0) $clean['visibility'] = 'public';

        if ($clean) {
            $rl = db_run($conn, "SELECT COUNT(*) AS n FROM events WHERE created_by = ? AND created_at > (NOW() - INTERVAL 1 HOUR)", "i", array($me['id']));
            if ($rl['ok'] && $rl['rows'] && (int)$rl['rows'][0]['n'] >= 10) {
                $error = "You've posted a lot of events in the last hour. Please wait a bit.";
            } else {
                $clubId = (int)($_POST['club_id'] ?? 0);
                $clubParam = $clubId > 0 ? $clubId : null;

                // the banner: saved only once everything else checks out, so a rejected form leaves no stray file
                $bannerName = null;
                if ($bannerReady) {
                    $bannerName = event_banner_upload('banner', $error);
                } elseif (!empty($_FILES['banner']['name'])) {
                    $error = "Event banners need a one-time upgrade. Run events-banner.sql in phpMyAdmin.";
                }

                $r = array('ok' => false, 'errno' => 0);
                if ($error === "") {
                    if ($bannerReady) {
                        $r = db_run($conn,
                            "INSERT INTO events (club_id, created_by, title, description, location, start_at, end_at, capacity, visibility, banner)
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                            "iisssssiss",
                            array($clubParam, $me['id'], $clean['title'], $clean['description'], $clean['location'], $clean['start_at'], $clean['end_at'], $clean['capacity'], $clean['visibility'], $bannerName));
                    } else {
                        $r = db_run($conn,
                            "INSERT INTO events (club_id, created_by, title, description, location, start_at, end_at, capacity, visibility)
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
                            "iisssssis",
                            array($clubParam, $me['id'], $clean['title'], $clean['description'], $clean['location'], $clean['start_at'], $clean['end_at'], $clean['capacity'], $clean['visibility']));
                    }
                    if (!$r['ok']) event_banner_delete($bannerName);   // nothing was posted, so don't keep the image
                }

                if ($r['ok']) {
                    $newId = (int)mysqli_insert_id($conn);
                    $excl = ($clean['visibility'] === 'members');
                    if ($clubId > 0) {
                        event_notify_members($conn, $clubId, $me['id'], full_name($me) . ' posted a new ' . ($excl ? 'members-only ' : '') . 'event in ' . $ownedIds[$clubId] . ': ' . $clean['title'], 'event?id=' . $newId);
                        flash_set($excl ? "Your members-only event is live. Only club members can see it, and they've been notified." : "Your event is live. Club members have been notified.", "success");
                    } else {
                        flash_set("Your event is live.", "success");
                    }
                    header("Location: event?id=" . $newId);
                    exit();
                }
                if ($error === "") $error = ($r['errno'] == 1146 || $r['errno'] == 1054) ? "Events aren't fully set up yet. Run events.sql, then events-members-only.sql, then events-banner.sql in phpMyAdmin." : "Something went wrong. Please try again.";
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
    <link rel="icon" href="favicon.svg" type="image/svg+xml">
    <link rel="icon" href="favicon.ico" sizes="any">
    <link rel="apple-touch-icon" href="apple-touch-icon.png">
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
    <p class="page-sub">Tell students what's coming up. Pick a club to host it and its members get a notification, or post it on your own.</p>

    <?php { ?>

        <?php if ($message != "") { ?>
            <div class="msg error" style="margin-top:20px"><?php echo e($message); ?></div>
        <?php } ?>

        <form method="POST" enctype="multipart/form-data" class="panel ev-form">
            <?php echo csrf_field(); ?>

            <?php if ($owned) { ?>
                <div class="field">
                    <label for="club_id">Hosting club</label>
                    <select name="club_id" id="club_id">
                        <?php foreach ($owned as $c) { ?>
                            <option value="<?php echo (int)$c['id']; ?>" <?php echo ((int)$c['id'] === $wantClub) ? 'selected' : ''; ?>><?php echo e($c['name']); ?></option>
                        <?php } ?>
                        <option value="0" <?php echo ($wantClub === 0) ? 'selected' : ''; ?>>No club (hosted by me)</option>
                    </select>
                </div>
            <?php } else { ?>
                <input type="hidden" name="club_id" id="club_id" value="0">
                <div class="ev-hosting">Hosted by <strong>you</strong>. <a href="create-club">Create a club</a> if you want one to host your events.</div>
            <?php } ?>

            <?php include("event-fields.php"); ?>

            <div class="form-actions">
                <button type="submit" name="post" class="btn inline">Publish event</button>
                <a href="events" class="cancel">Cancel</a>
            </div>
        </form>

    <?php } ?>

</main>

<script>
(function () {
    // no hosting club = no members-only option
    var sel = document.getElementById("club_id");
    var mem = document.querySelector('input[name="visibility"][value="members"]');
    var pub = document.querySelector('input[name="visibility"][value="public"]');
    if (!sel || !mem || !pub) return;
    var card = mem.closest(".vis-opt");
    function sync() {
        var none = (sel.value === "0");
        mem.disabled = none;
        if (card) card.style.opacity = none ? "0.5" : "";
        if (none && mem.checked) pub.checked = true;
    }
    sel.addEventListener("change", sync);
    sync();
})();
</script>

<?php include("image-framer.php"); ?>

</body>
</html>