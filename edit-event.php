<?php
session_start();
require("db.php");
require("auth.php");
require("events-lib.php");

$me  = require_user_row($conn);
$eid = (int)($_GET['id'] ?? $_POST['event_id'] ?? 0);

$bannerReady = events_banner_ready($conn);
$er = db_run($conn,
    "SELECT e.id, e.club_id, e.title, e.description, e.location, e.start_at, e.end_at, e.capacity, e.status, e.visibility, e.created_by, c.name AS club_name"
     . ($bannerReady ? ", e.banner" : "") . (club_identity_ready($conn) ? ", c.cover AS club_cover" : "") . "
     FROM events e LEFT JOIN clubs c ON c.id = e.club_id
     WHERE e.id = ? AND (e.club_id IS NULL OR c.status = 'approved') LIMIT 1", "i", array($eid));
$ev = $er['rows'] ? $er['rows'][0] : null;

if (!$ev) {
    header("Location: events");
    exit();
}

// owners and officers of the hosting club can edit; with no club, the person who posted it can
$noClub = empty($ev['club_id']);
$noVis  = $noClub;
$canEdit = $noClub ? ((int)$ev['created_by'] === (int)$me['id']) : event_is_club_staff($conn, $ev['club_id'], $me['id']);
if (!$canEdit) {
    header("Location: event?id=" . $eid);
    exit();
}

// a finished (or old cancelled) event can't be changed any more, but it can still be cancelled to clear it out
$locked    = ($ev['status'] !== 'active' || event_state($ev) === 'over');
$canCancel = event_can_cancel($conn, $ev, $me['id']);

$cnt = db_run($conn, "SELECT COUNT(*) AS n FROM event_rsvps WHERE event_id = ?", "i", array($eid));
$going = ($cnt['ok'] && $cnt['rows']) ? (int)$cnt['rows'][0]['n'] : 0;

// event banner: its own upload, else the club's banner, else a color (see event_image_url)
$oldBanner   = ($bannerReady && !empty($ev['banner']) && club_image_ok($ev['banner'])) ? $ev['banner'] : '';
$bannerCur   = $oldBanner;
$bannerClubs = !$noClub;
$bannerClub  = $noClub ? 0 : (int)$ev['club_id'];
$bannerCoversIn = (!$noClub && !empty($ev['club_cover'])) ? array((int)$ev['club_id'] => $ev['club_cover']) : array();

$message = "";
// a file larger than the server's post limit arrives as an empty form
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    $message = "That upload was too large for the server. Try a smaller image (up to 10 MB).";
}
$s = event_dt($ev['start_at']);
$f = array(
    'title'       => $ev['title'],
    'event_date'  => $s->format('Y-m-d'),
    'start_time'  => $s->format('H:i'),
    'end_time'    => !empty($ev['end_at']) ? event_dt($ev['end_at'])->format('H:i') : '',
    'location'    => $ev['location'],
    'capacity'    => $ev['capacity'] !== null ? (string)(int)$ev['capacity'] : '',
    'description' => $ev['description'],
    'visibility'  => ($ev['visibility'] === 'members') ? 'members' : 'public',
);
$visHint = ($noClub || $ev['visibility'] === 'members') ? '' : 'Switching to members only takes anyone outside your club off the going list, and they will be told.';

// ---------- cancel event: tells the people who were going, then clears the event completely ----------
if (isset($_POST['cancel_event'])) {
    if (!csrf_ok()) {
        $message = "Your session expired. Please try again.";
    } elseif (!$canCancel) {
        $message = "Only the club owner, or the person who posted this event, can cancel it.";
    } else {
        // people who were going hear about it first, while the list still exists
        if (!$locked && $going > 0) {
            event_notify_going($conn, $eid, $me['id'], 'event_cancelled', $ev['title'] . ' was cancelled.', 'events');
        }
        if (event_delete_rows($conn, $eid)) {
            flash_set("Your event was cancelled and removed.", "success");
            header("Location: events");
            exit();
        }
        $message = "Something went wrong. Please try again.";
    }
}

if (isset($_POST['save']) && !$locked) {
    foreach ($f as $k => $v) $f[$k] = (string)($_POST[$k] ?? '');
    $error = "";
    $newBanner = null;

    if (!csrf_ok()) {
        $error = "Your session expired. Please try again.";
    } else {
        // a date that hasn't moved doesn't have to be in the future (the event may be happening today)
        $moved = ($f['event_date'] !== $s->format('Y-m-d') || $f['start_time'] !== $s->format('H:i'));
        $clean = event_clean($_POST, $moved, $error);
        if ($clean && $noClub) $clean['visibility'] = 'public';

        if ($clean && $clean['capacity'] !== null && $clean['capacity'] < $going) {
            $error = "Spots can't be fewer than the " . $going . " " . ($going === 1 ? "person" : "people") . " already going.";
            $clean = null;
        }

        // the banner: a new upload replaces the old one, the x removes it, otherwise it stays as it is
        $newBanner   = null;
        $bannerFinal = $oldBanner !== '' ? $oldBanner : null;
        if ($clean) {
            if ($bannerReady) {
                $newBanner = event_banner_upload('banner', $error);
                if ($error !== "")                         { $clean = null; }
                elseif ($newBanner !== null)               { $bannerFinal = $newBanner; }
                elseif (!empty($_POST['remove_banner']))   { $bannerFinal = null; }
            } elseif (!empty($_FILES['banner']['name'])) {
                $error = "Event banners need a one-time upgrade. Run events-banner.sql in phpMyAdmin.";
                $clean = null;
            }
        }

        if ($clean) {
            $sql    = "UPDATE events SET title = ?, description = ?, location = ?, start_at = ?, end_at = ?, capacity = ?, visibility = ?" . ($bannerReady ? ", banner = ?" : "") . " WHERE id = ?";
            $types  = "sssssis" . ($bannerReady ? "s" : "") . "i";
            $params = array($clean['title'], $clean['description'], $clean['location'], $clean['start_at'], $clean['end_at'], $clean['capacity'], $clean['visibility']);
            if ($bannerReady) $params[] = $bannerFinal;
            $params[] = $eid;
            $r = db_run($conn, $sql, $types, $params);

            if ($r['ok']) {
                // the old image is no longer used once the new one (or none) is saved
                if ($bannerReady && $oldBanner !== '' && $bannerFinal !== $oldBanner) event_banner_delete($oldBanner);

                // public -> members only: people outside the club lose their spot
                $removed = array();
                if (!$noClub && $clean['visibility'] === 'members' && $ev['visibility'] !== 'members') {
                    $removed = event_purge_outsider_rsvps($conn, $eid, $ev['club_id']);
                    foreach ($removed as $uid) {
                        notify($conn, $uid, 'event_update', $clean['title'] . ' is now open to club members only, so you were taken off the going list.', 'events');
                    }
                    $going = max(0, $going - count($removed));
                }
                // a new start time means the reminders have to be sent again for the new time
                if ($clean['start_at'] !== $ev['start_at']) {
                    db_run($conn, "DELETE FROM event_reminders WHERE event_id = ?", "i", array($eid));
                }
                $timeChanged = ($clean['start_at'] !== $ev['start_at'] || (string)$clean['end_at'] !== (string)$ev['end_at'] || $clean['location'] !== $ev['location']);
                if ($timeChanged && $going > 0) {
                    event_notify_going($conn, $eid, $me['id'], 'event_update', 'The time or place for ' . $clean['title'] . ' changed. Check the new details.', 'event?id=' . $eid);
                }
                flash_set($removed ? "Your event was updated. It's now members only, and " . count($removed) . ($removed && count($removed) === 1 ? " person outside your club was taken off the going list." : " people outside your club were taken off the going list.") : "Your event was updated.", "success");
                header("Location: event?id=" . $eid);
                exit();
            }
            $error = "Something went wrong. Please try again.";
        }
    }

    // a failed save shouldn't leave stray uploads behind
    if ($error !== "" && !empty($newBanner)) event_banner_delete($newBanner);

    $message = $error;
}

$minDate = min(event_now()->format('Y-m-d'), $s->format('Y-m-d'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Edit event | Camigo</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:wght@600;700;800&family=Instrument+Sans:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
    <script src="confirm.js?v=<?php echo (int)@filemtime(__DIR__ . '/confirm.js'); ?>" defer></script>
</head>
<body>

<?php include("topbar.php"); ?>

<main class="main narrow">

    <p class="crumb"><a href="event?id=<?php echo $eid; ?>">&larr; Back to event</a></p>
    <h1 class="page-title">Edit event</h1>
    <p class="page-sub"><?php echo $noClub ? 'Hosted by you.' : 'Hosted by ' . e($ev['club_name']) . '.'; ?> If you change the time or place, everyone who is going gets a notification.</p>

    <?php if ($message != "") { ?>
        <div class="msg error" style="margin-top:20px"><?php echo e($message); ?></div>
    <?php } ?>

    <?php
    $cancelAsk = ((!$locked && $going > 0) ? 'Everyone who is going will be told. ' : '') . 'It will be removed completely and this cannot be undone.';
    $cancelBtn = $canCancel ? '<button type="submit" form="cancel-form" name="cancel_event" value="1" class="btn inline btn-danger">Cancel event</button>' : '';
    ?>

    <?php if ($locked) { ?>
        <div class="msg error" style="margin-top:20px">This event is cancelled or already over, so it can't be edited.</div>
        <div class="panel ev-form">
            <div class="form-actions" style="margin-top:0">
                <a href="event?id=<?php echo $eid; ?>" class="cancel">Back</a>
                <span style="margin-left:auto"><?php echo $cancelBtn; ?></span>
            </div>
        </div>
    <?php } else { ?>
    <form method="POST" enctype="multipart/form-data" class="panel ev-form">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="event_id" value="<?php echo $eid; ?>">

        <?php include("event-fields.php"); ?>

        <div class="form-actions">
            <button type="submit" name="save" class="btn inline">Save changes</button>
            <a href="event?id=<?php echo $eid; ?>" class="cancel">Back</a>
            <span style="margin-left:auto"><?php echo $cancelBtn; ?></span>
        </div>
    </form>
    <?php } ?>

    <?php if ($canCancel) { ?>
    <form id="cancel-form" method="POST" data-confirm="<?php echo e($cancelAsk); ?>" data-confirm-title="Cancel this event?" data-confirm-ok="Cancel event" data-confirm-cancel="Keep event" data-confirm-tone="danger" data-confirm-icon="alert">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="event_id" value="<?php echo $eid; ?>">
    </form>
    <?php } ?>

</main>

<?php include("image-framer.php"); ?>

</body>
</html>