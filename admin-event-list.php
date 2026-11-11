<?php
session_start();
require("db.php");
require("auth.php");
require("events-lib.php");
require("projects-lib.php");
require("admin-lib.php");

$me = require_admin($conn);

// ---- delete event ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id     = (int)($_POST['event_id'] ?? 0);
    $action = $_POST['action'] ?? '';
    $note   = str_cut(trim((string)($_POST['note'] ?? '')), 200);

    if (!csrf_ok() || $id <= 0 || $action !== 'delete') {
        flash_set("Your session expired. Please try again.", "error");
    } else {
        $rows = db_run($conn, "SELECT id, title, status, created_by FROM events WHERE id = ? LIMIT 1", "i", array($id))['rows'];
        $ev = $rows ? $rows[0] : null;

        if (!$ev) {
            flash_set("That event no longer exists.", "error");
        } else {
            // tell the people who were going, and whoever posted it, before the rows go
            if ($ev['status'] === 'active') {
                event_notify_going($conn, $id, (int)$ev['created_by'], 'event_cancelled',
                    'The event "' . $ev['title'] . '" was removed by an admin.' . ($note !== '' ? ' Reason: ' . $note : ''), 'events');
            }
            if (!empty($ev['created_by'])) {
                notify($conn, $ev['created_by'], 'event_cancelled',
                    'Your event "' . $ev['title'] . '" was removed by an admin.' . ($note !== '' ? ' Reason: ' . $note : ''), 'events');
            }

            // children first, then the event itself
            db_run($conn, "DELETE FROM event_reminders WHERE event_id = ?", "i", array($id));
            db_run($conn, "DELETE FROM event_rsvps WHERE event_id = ?", "i", array($id));
            db_run($conn, "DELETE FROM notifications WHERE link = ?", "s", array('event?id=' . $id));  // old links would lead nowhere
            $r = db_run($conn, "DELETE FROM events WHERE id = ?", "i", array($id));

            flash_set(($r['ok'] && $r['affected'] > 0) ? "Event deleted." : "Couldn't delete that event. Please try again.", ($r['ok'] && $r['affected'] > 0) ? "success" : "error");
        }
    }

    $back = (string)($_POST['back'] ?? '');
    $dest = preg_match('/^admin-(event-list|events)(\?[A-Za-z0-9=&%_.+\-]*)?$/', $back) ? $back : 'admin-event-list';
    header("Location: " . $dest);
    exit();
}

$message = "";
$message_class = "";
$flash = flash_get();
if ($flash) {
    $message = $flash[0];
    $message_class = $flash[1];
}

$q      = str_cut(trim((string)($_GET['q'] ?? '')), 60);
$page   = admin_page_param();

$total = admin_events_search($conn, $q, 0, 0)['total'];
$pages = max(1, (int)ceil($total / ADMIN_PER_PAGE));
if ($page > $pages) $page = $pages;
$rows  = admin_events_search($conn, $q, ADMIN_PER_PAGE, ($page - 1) * ADMIN_PER_PAGE)['rows'];

$keep = ($q !== '') ? array('q' => $q) : array();
$backParams = $keep;
if ($page > 1) $backParams['page'] = $page;
$back = 'admin-event-list' . ($backParams ? '?' . http_build_query($backParams) : '');

$adminActive = 'events';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>All events | Camigo</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:wght@600;700;800&family=Instrument+Sans:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="verify.css">
</head>
<body>

<?php include("topbar.php"); ?>

<main class="main narrow">

    <?php include("admin-nav.php"); ?>

    <a href="admin-events" class="va-back">&larr; Back to events</a>
    <h1 class="page-title">All events</h1>
    <p class="page-sub">Every event, newest first. Search by title, place, club, or who posted it.</p>

    <?php if ($message != "") { ?>
        <div class="msg <?php echo e($message_class); ?>" style="margin-top:20px"><?php echo e($message); ?></div>
    <?php } ?>

    <section class="panel">
        <?php echo admin_search_form('admin-event-list', $q, 'Search by event, club, place or poster', 'Search events'); ?>

        <div class="va-bar">
            <span class="dir-count">
                <?php if ($q !== '') { ?>
                    <?php echo (int)$total; ?> <?php echo $total === 1 ? 'event' : 'events'; ?> match &ldquo;<?php echo e($q); ?>&rdquo;
                <?php } else { ?>
                    <?php echo (int)$total; ?> <?php echo $total === 1 ? 'event' : 'events'; ?>
                <?php } ?>
            </span>
            <?php if ($q !== '') { ?><a href="admin-event-list" class="va-clear">Clear search</a><?php } ?>
        </div>

        <?php if (!$rows) { ?>
            <p class="empty"><?php echo $q !== '' ? 'No event matches that search.' : 'No events have been posted yet.'; ?></p>
        <?php } else { ?>
            <div class="club-list">
                <?php foreach ($rows as $row) { echo admin_event_row($row, $back); } ?>
            </div>
            <?php echo admin_pager('admin-event-list', $keep, $page, $pages); ?>
        <?php } ?>
    </section>

</main>

</body>
</html>