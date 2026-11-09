<?php
session_start();
require("db.php");
require("auth.php");
require("events-lib.php");

$me  = require_user_row($conn);
$eid = (int)($_GET['id'] ?? $_POST['event_id'] ?? 0);

$er = db_run($conn,
    "SELECT e.id, e.club_id, e.title, e.description, e.location, e.start_at, e.end_at, e.capacity, e.status, e.visibility, c.name AS club_name
     FROM events e JOIN clubs c ON c.id = e.club_id AND c.status = 'approved'
     WHERE e.id = ? LIMIT 1", "i", array($eid));
$ev = $er['rows'] ? $er['rows'][0] : null;

if (!$ev) {
    header("Location: events");
    exit();
}

// only an owner of the hosting club can edit
if (!event_is_club_owner($conn, $ev['club_id'], $me['id'])) {
    header("Location: event?id=" . $eid);
    exit();
}

if ($ev['status'] !== 'active' || event_state($ev) === 'over') {
    flash_set("This event is cancelled or already over, so it can't be edited.", "error");
    header("Location: event?id=" . $eid);
    exit();
}

$cnt = db_run($conn, "SELECT COUNT(*) AS n FROM event_rsvps WHERE event_id = ?", "i", array($eid));
$going = ($cnt['ok'] && $cnt['rows']) ? (int)$cnt['rows'][0]['n'] : 0;

$message = "";
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
$visHint = ($ev['visibility'] === 'members') ? '' : 'Switching to members only removes RSVPs from people who are not in your club, and they will be told.';

if (isset($_POST['save'])) {
    foreach ($f as $k => $v) $f[$k] = (string)($_POST[$k] ?? '');
    $error = "";

    if (!csrf_ok()) {
        $error = "Your session expired. Please try again.";
    } else {
        // a date that hasn't moved doesn't have to be in the future (the event may be happening today)
        $moved = ($f['event_date'] !== $s->format('Y-m-d') || $f['start_time'] !== $s->format('H:i'));
        $clean = event_clean($_POST, $moved, $error);

        if ($clean && $clean['capacity'] !== null && $clean['capacity'] < $going) {
            $error = "Spots can't be fewer than the " . $going . " " . ($going === 1 ? "person" : "people") . " already going.";
            $clean = null;
        }

        if ($clean) {
            $r = db_run($conn,
                "UPDATE events SET title = ?, description = ?, location = ?, start_at = ?, end_at = ?, capacity = ?, visibility = ? WHERE id = ?",
                "sssssisi",
                array($clean['title'], $clean['description'], $clean['location'], $clean['start_at'], $clean['end_at'], $clean['capacity'], $clean['visibility'], $eid));

            if ($r['ok']) {
                // public -> members only: people outside the club lose their spot
                $removed = array();
                if ($clean['visibility'] === 'members' && $ev['visibility'] !== 'members') {
                    $removed = event_purge_outsider_rsvps($conn, $eid, $ev['club_id']);
                    foreach ($removed as $uid) {
                        notify($conn, $uid, 'event_update', $clean['title'] . ' is now open to club members only, so your RSVP was removed.', 'events');
                    }
                    $going = max(0, $going - count($removed));
                }
                $timeChanged = ($clean['start_at'] !== $ev['start_at'] || (string)$clean['end_at'] !== (string)$ev['end_at'] || $clean['location'] !== $ev['location']);
                if ($timeChanged && $going > 0) {
                    event_notify_going($conn, $eid, $me['id'], 'event_update', 'The time or place for ' . $clean['title'] . ' changed. Check the new details.', 'event?id=' . $eid);
                }
                flash_set($removed ? "Your event was updated. It's now members only, and " . count($removed) . ($removed && count($removed) === 1 ? " RSVP from an outsider was removed." : " RSVPs from outsiders were removed.") : "Your event was updated.", "success");
                header("Location: event?id=" . $eid);
                exit();
            }
            $error = "Something went wrong. Please try again.";
        }
    }
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
</head>
<body>

<?php include("topbar.php"); ?>

<main class="main narrow">

    <p class="crumb"><a href="event?id=<?php echo $eid; ?>">&larr; Back to event</a></p>
    <h1 class="page-title">Edit event</h1>
    <p class="page-sub">Hosted by <?php echo e($ev['club_name']); ?>. If you change the time or place, everyone who is going gets a notification.</p>

    <?php if ($message != "") { ?>
        <div class="msg error" style="margin-top:20px"><?php echo e($message); ?></div>
    <?php } ?>

    <form method="POST" class="panel ev-form">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="event_id" value="<?php echo $eid; ?>">

        <?php include("event-fields.php"); ?>

        <div class="form-actions">
            <button type="submit" name="save" class="btn inline">Save changes</button>
            <a href="event?id=<?php echo $eid; ?>" class="cancel">Cancel</a>
        </div>
    </form>

</main>

</body>
</html>