<?php
session_start();
require("db.php");
require("auth.php");

$me = require_user_row($conn);
if ($_SERVER['REQUEST_METHOD'] === 'POST') register_shutdown_function('chat_club_sync_quiet', $conn);


// ---- accept / decline ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $id     = (int)($_POST['request_id'] ?? 0);
    $action = $_POST['action'] ?? '';

    if (!csrf_ok() || $id <= 0 || !in_array($action, array('accept', 'decline'), true)) {
        flash_set("Your session expired. Please try again.", "error");
    } else {

        // only the owner of that club may handle its requests
        $chk = db_run($conn,
            "SELECT m.id FROM club_members m
             JOIN club_members o ON o.club_id = m.club_id AND o.user_id = ? AND o.role = 'owner'
             WHERE m.id = ? AND m.status = 'pending' LIMIT 1",
            "ii", array($me['id'], $id));

        if (!$chk['rows']) {
            flash_set("That request was already handled.", "error");
        } elseif ($action === 'accept') {
            $info = member_info($conn, $id);
            $r = db_run($conn, "UPDATE club_members SET status = 'active' WHERE id = ? AND status = 'pending'", "i", array($id));
            if ($r['ok'] && $r['affected'] > 0 && $info) {
                notify($conn, $info['user_id'], 'join_accepted', 'Your request to join ' . $info['club_name'] . ' was accepted.', 'club?id=' . (int)$info['club_id']);
            }
            flash_set($r['ok'] ? "Member accepted." : "Something went wrong. Please try again.", $r['ok'] ? "success" : "error");
        } else {
            $info = member_info($conn, $id);
            $r = db_run($conn, "DELETE FROM club_members WHERE id = ? AND status = 'pending'", "i", array($id));
            if ($r['ok'] && $r['affected'] > 0 && $info) {
                notify($conn, $info['user_id'], 'join_declined', 'Your request to join ' . $info['club_name'] . ' was declined.', 'clubs');
            }
            flash_set($r['ok'] ? "Request declined." : "Something went wrong. Please try again.", $r['ok'] ? "success" : "error");
        }
    }

    header("Location: club-requests");
    exit();
}

$list = db_run($conn,
    "SELECT m.id AS request_id, m.joined_at, c.name AS club_name,
            u.username, u.first_name, u.last_name, u.course, u.year_level, u.photo, u.profile_visibility" . vsel($conn) . "
     FROM club_members m
     JOIN clubs c ON c.id = m.club_id AND c.status = 'approved'
     JOIN club_members o ON o.club_id = m.club_id AND o.user_id = ? AND o.role = 'owner'
     JOIN users u ON u.id = m.user_id
     WHERE m.status = 'pending'
     ORDER BY m.joined_at ASC",
    "i", array($me['id']));

$setupMissing = !$list['ok'];
$requests = $list['rows'];

$flash = flash_get();
$message = $flash ? $flash[0] : "";
$message_class = $flash ? $flash[1] : "";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Join requests | Camigo</title>
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

    <h1 class="page-title">Join requests</h1>
    <p class="page-sub">Students waiting to join the clubs you own. <a href="clubs">Back to clubs</a></p>

    <?php if ($message != "") { ?>
        <div class="msg <?php echo e($message_class); ?>" style="margin-top:20px"><?php echo e($message); ?></div>
    <?php } ?>

    <?php if ($setupMissing) { ?>
        <div class="msg error" style="margin-top:20px">Join requests aren't set up yet. Run <strong>members-update.sql</strong> in phpMyAdmin.</div>
    <?php } else { ?>

    <section class="panel rq-panel">
        <h2>Waiting for you (<?php echo count($requests); ?>)</h2>

        <?php if (!$requests) { ?>
            <p class="empty">No requests right now.</p>
        <?php } else { ?>
            <div class="club-list">
                <?php foreach ($requests as $rq) {
                    $name = trim($rq['first_name'] . ' ' . ($rq['last_name'] ?? ''));
                    $sub = array();
                    if (!empty($rq['course'])) $sub[] = $rq['course'];
                    if (!empty($rq['year_level'])) $sub[] = $rq['year_level'];
                ?>
                    <div class="club-item rq-item">
                        <div class="req">
                            <div class="req-who">
                                <div class="p-avatar tiny"><?php echo avatar_inner($rq); ?></div>
                                <div class="rq-text">
                                    <a class="rq-name" href="profile?u=<?php echo e(urlencode($rq['username'])); ?>"><?php echo name_badge($rq, $name); ?></a>
                                    <div class="rq-meta">
                                        @<?php echo e($rq['username']); ?>
                                        <?php if ($sub) { ?> &middot; <?php echo e(implode(' · ', $sub)); ?><?php } ?>
                                    </div>
                                    <div class="rq-meta">
                                        Wants to join <span class="rq-club"><?php echo e($rq['club_name']); ?></span>
                                        &middot; <?php echo e(date('M j, Y', strtotime($rq['joined_at']))); ?>
                                    </div>
                                </div>
                            </div>

                            <form method="POST" class="req-actions">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="request_id" value="<?php echo (int)$rq['request_id']; ?>">
                                <button type="submit" name="action" value="accept" class="btn-sm btn-approve">Accept</button>
                                <button type="submit" name="action" value="decline" class="btn-sm btn-reject">Decline</button>
                            </form>
                        </div>
                    </div>
                <?php } ?>
            </div>
        <?php } ?>
    </section>

    <?php } ?>

</main>

</body>
</html>