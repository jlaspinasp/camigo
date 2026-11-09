<?php
session_start();
require("db.php");
require("auth.php");

$me = require_user_row($conn);

// ---- open one notification: mark it read, then go to its page ----
if (isset($_GET['open'])) {
    $nid = (int)$_GET['open'];
    $r = db_run($conn, "SELECT link FROM notifications WHERE id = ? AND user_id = ? LIMIT 1", "ii", array($nid, $me['id']));
    db_run($conn, "UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?", "ii", array($nid, $me['id']));

    $link = $r['rows'] ? (string)$r['rows'][0]['link'] : '';
    // only ever go to a page on this site
    if (!preg_match('/^[a-z-]+(\?[A-Za-z0-9=&_%-]*)?$/', $link)) {
        $link = 'notifications';
    }
    header("Location: " . $link);
    exit();
}

// ---- mark all as read ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['read_all'])) {
    if (csrf_ok()) {
        db_run($conn, "UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0", "i", array($me['id']));
    }
    header("Location: notifications");
    exit();
}

// tidy up very old notifications
db_run($conn, "DELETE FROM notifications WHERE user_id = ? AND created_at < (NOW() - INTERVAL 60 DAY)", "i", array($me['id']));

$res = db_run($conn,
    "SELECT id, type, message, link, is_read, UNIX_TIMESTAMP(created_at) AS ts
     FROM notifications WHERE user_id = ? ORDER BY id DESC LIMIT 50",
    "i", array($me['id']));

$setupMissing = !$res['ok'];
$items = $res['rows'];
$unreadHere = 0;
foreach ($items as $it) { if (!(int)$it['is_read']) $unreadHere++; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Notifications | Camigo</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:wght@600;700;800&family=Instrument+Sans:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
</head>
<body>

<?php include("topbar.php"); ?>

<main class="main narrow">

    <div class="page-top">
        <div>
            <h1 class="page-title">Notifications</h1>
            <p class="page-sub">Updates about your clubs, events and projects.</p>
        </div>
        <?php if ($unreadHere > 0) { ?>
            <form method="POST">
                <?php echo csrf_field(); ?>
                <button type="submit" name="read_all" value="1" class="btn-sm btn-ghost">Mark all as read</button>
            </form>
        <?php } ?>
    </div>

    <?php if ($setupMissing) { ?>

        <div class="msg error" style="margin-top:20px">Notifications aren't set up yet. Run <strong>notifications.sql</strong> in phpMyAdmin.</div>

    <?php } elseif (!$items) { ?>

        <section class="panel center">
            <h2>You're all caught up</h2>
            <p class="empty">When something happens with your clubs or requests, it shows up here.</p>
        </section>

    <?php } else { ?>

        <div class="notif-list">
            <?php foreach ($items as $it) {
                $look = notif_look($it['type']);
                $unread = !(int)$it['is_read'];
            ?>
                <a href="notifications?open=<?php echo (int)$it['id']; ?>" class="notif <?php echo $unread ? 'unread' : ''; ?>">
                    <span class="n-ic <?php echo e($look[0]); ?>">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><?php echo $look[1]; ?></svg>
                    </span>
                    <span class="n-text">
                        <span class="n-msg"><?php echo e($it['message']); ?></span>
                        <span class="n-time"><?php echo e(time_ago($it['ts'])); ?></span>
                    </span>
                    <?php if ($unread) { ?><span class="u-dot" aria-label="Unread"></span><?php } ?>
                </a>
            <?php } ?>
        </div>

    <?php } ?>

</main>

</body>
</html>