<?php
session_start();
require("db.php");
require("auth.php");
require("events-lib.php");
require("projects-lib.php");
require("admin-lib.php");

$me = require_admin($conn);

$message = "";
$message_class = "";
$flash = flash_get();
if ($flash) {
    $message = $flash[0];
    $message_class = $flash[1];
}

$q = str_cut(trim((string)($_GET['q'] ?? '')), 60);
$probe = db_run($conn, "SELECT COUNT(*) AS n FROM events");
$tableMissing = !$probe['ok'];

$total = $tableMissing ? 0 : admin_events_search($conn, '', 0, 0)['total'];
$found = ($q !== '' && !$tableMissing) ? admin_events_search($conn, $q, ADMIN_PEEK, 0) : array('rows' => array(), 'total' => 0);
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
    <link rel="stylesheet" href="verify.css">
</head>
<body>

<?php include("topbar.php"); ?>

<main class="main narrow">

    <?php $adminActive = 'events'; include("admin-nav.php"); ?>

    <h1 class="page-title">Events</h1>
    <p class="page-sub">Every event posted on Camigo. Events don't need review, but you can find one and delete it if needed.</p>

    <?php if ($message != "") { ?>
        <div class="msg <?php echo e($message_class); ?>" style="margin-top:20px"><?php echo e($message); ?></div>
    <?php } ?>

    <?php if ($tableMissing) { ?>
        <div class="msg error" style="margin-top:20px">The events table doesn't exist yet.</div>
    <?php } else { ?>

    <section class="panel">
        <div class="va-panel-head">
            <h2>All events (<?php echo (int)$total; ?>)</h2>
            <a href="admin-event-list" class="btn-sm btn-ghost">View all events</a>
        </div>

        <?php echo admin_search_form('admin-events', $q, 'Search by event, club, place or poster', 'Search events'); ?>

        <?php if ($q === '') { ?>
            <p class="empty">Search to find an event, or open the full list.</p>
        <?php } else { ?>
            <div class="va-bar">
                <span class="dir-count"><?php echo (int)$found['total']; ?> <?php echo $found['total'] === 1 ? 'event' : 'events'; ?> match &ldquo;<?php echo e($q); ?>&rdquo;</span>
                <a href="admin-events" class="va-clear">Clear search</a>
            </div>
            <?php $backSearch = 'admin-events?' . http_build_query(array('q' => $q)); ?>
            <?php if (!$found['rows']) { ?>
                <p class="empty">No event matches that search.</p>
            <?php } else { ?>
                <div class="club-list">
                    <?php foreach ($found['rows'] as $row) { echo admin_event_row($row, $backSearch); } ?>
                </div>
                <?php if ($found['total'] > ADMIN_PEEK) { ?>
                    <p class="va-more">Showing the first <?php echo ADMIN_PEEK; ?>. <a href="admin-event-list?<?php echo e(http_build_query(array('q' => $q))); ?>">See all <?php echo (int)$found['total']; ?> matches</a></p>
                <?php } ?>
            <?php } ?>
        <?php } ?>
    </section>

    <?php } ?>

</main>

</body>
</html>