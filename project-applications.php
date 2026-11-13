<?php
session_start();
require("db.php");
require("auth.php");
require_once("projects-lib.php");
require_once("feed-lib.php");

$me = require_user_row($conn);
if ($_SERVER['REQUEST_METHOD'] === 'POST') register_shutdown_function('chat_club_sync_quiet', $conn);


// ---- accept / decline ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $mid    = (int)($_POST['member_id'] ?? 0);
    $action = $_POST['action'] ?? '';

    if (!csrf_ok() || $mid <= 0 || !in_array($action, array('accept', 'decline'), true)) {
        flash_set("Your session expired. Please try again.", "error");
    } else {

        // only the founder of that project may handle its applications
        $chk = db_run($conn,
            "SELECT a.id, a.user_id, a.project_id, p.title
             FROM project_members a
             JOIN projects p ON p.id = a.project_id AND p.owner_id = ?
             WHERE a.id = ? AND a.status = 'pending' LIMIT 1",
            "ii", array($me['id'], $mid));
        $app = $chk['rows'] ? $chk['rows'][0] : null;

        if (!$app) {
            flash_set("That application was already handled.", "error");
        } elseif ($action === 'accept') {
            $pid = (int)$app['project_id'];
            $roles = project_roles($conn, $pid);
            $assignId = (int)($_POST['role_id'] ?? 0);
            $assign = null;
            foreach ($roles as $r) { if ((int)$r['id'] === $assignId) $assign = $r; }

            if ($assignId > 0 && (!$assign || (int)$assign['filled'] >= (int)$assign['spots'])) {
                flash_set("That role is full. Raise its spots in Edit project, or pick another role.", "error");
            } else {
                $label = $assign ? $assign['title'] : 'Teammate';
                $r = db_run($conn,
                    "UPDATE project_members SET status = 'active', role_id = ?, role_label = ? WHERE id = ? AND project_id = ? AND status = 'pending'",
                    "isii", array($assign ? (int)$assign['id'] : null, $label, $mid, $pid));
                if ($r['ok'] && $r['affected'] > 0) {
                    notify($conn, $app['user_id'], 'project_accepted',
                        "You're in! " . $app['title'] . " accepted you" . ($assign ? ' as ' . $assign['title'] : '') . '.', 'project?id=' . $pid);
                    flash_set("They're on the team now, and they've been told.", "success");
                } else {
                    flash_set("Something went wrong. Please try again.", "error");
                }
            }
        } else {
            $r = db_run($conn, "DELETE FROM project_members WHERE id = ? AND project_id = ? AND status = 'pending'", "ii", array($mid, (int)$app['project_id']));
            if ($r['ok'] && $r['affected'] > 0) {
                notify($conn, $app['user_id'], 'project_declined',
                    $app['title'] . " couldn't offer you a spot this time. Plenty of other projects are looking for people.", 'projects');
                flash_set("Application declined.", "success");
            } else {
                flash_set("Something went wrong. Please try again.", "error");
            }
        }
    }

    header("Location: project-applications");
    exit();
}

$list = db_run($conn,
    "SELECT a.id AS mid, a.project_id, a.role_id, a.role_label, a.message, UNIX_TIMESTAMP(a.created_at) AS ts,
            p.title AS project_title,
            u.username, u.first_name, u.last_name, u.course, u.year_level, u.photo" . vsel($conn) . "
     FROM project_members a
     JOIN projects p ON p.id = a.project_id AND p.owner_id = ?
     JOIN users u ON u.id = a.user_id
     WHERE a.status = 'pending'
     ORDER BY a.created_at ASC",
    "i", array($me['id']));

$setupMissing = !$list['ok'];
$applications = $list['rows'];

// open roles per project, for the "role to give" picker
$freeRolesBy = array();
foreach ($applications as $a) {
    $pj = (int)$a['project_id'];
    if (isset($freeRolesBy[$pj])) continue;
    $freeRolesBy[$pj] = array('all' => 0, 'free' => array());
    $rs = project_roles($conn, $pj);
    $freeRolesBy[$pj]['all'] = count($rs);
    foreach ($rs as $r) { if ((int)$r['filled'] < (int)$r['spots']) $freeRolesBy[$pj]['free'][] = $r; }
}

$flash = flash_get();
$message = $flash ? $flash[0] : "";
$message_class = $flash ? $flash[1] : "";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Applications | Camigo</title>
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

    <h1 class="page-title">Applications</h1>
    <p class="page-sub">Students waiting to join the projects you started. <a href="projects">Back to projects</a></p>

    <?php if ($message != "") { ?>
        <div class="msg <?php echo e($message_class); ?>" style="margin-top:20px"><?php echo e($message); ?></div>
    <?php } ?>

    <?php if ($setupMissing) { ?>
        <div class="msg error" style="margin-top:20px">Projects aren't set up yet. Run <strong>projects.sql</strong> in phpMyAdmin.</div>
    <?php } else { ?>

    <section class="panel rq-panel" id="applications">
        <h2>Waiting for you (<?php echo count($applications); ?>)</h2>

        <?php if (!$applications) { ?>
            <p class="empty">No applications right now.</p>
        <?php } else { ?>
            <div class="club-list">
                <?php foreach ($applications as $a) {
                    $line = array();
                    if (!empty($a['course'])) $line[] = $a['course'];
                    if (!empty($a['year_level'])) $line[] = $a['year_level'];
                    $hasMsg = trim((string)$a['message']) !== '';
                    $hasApp = $hasMsg || !empty($a['role_label']);
                    $apId = 'ap-' . (int)$a['mid'];
                    $pj = (int)$a['project_id'];
                    $freeRoles = $freeRolesBy[$pj]['free'];
                    $hasRoles = $freeRolesBy[$pj]['all'] > 0;
                    $link = ($a['username'] === $me['username']) ? 'profile' : 'profile?u=' . urlencode($a['username']);
                ?>
                    <div class="club-item rq-item pj-rq">
                        <div class="req">
                            <div class="req-who">
                                <div class="p-avatar tiny"><?php echo avatar_inner($a); ?></div>
                                <div class="rq-text">
                                    <a class="rq-name" href="<?php echo e($link); ?>"><?php echo name_badge($a); ?></a>
                                    <div class="rq-meta">
                                        @<?php echo e($a['username']); ?>
                                        <?php if ($line) { ?> &middot; <?php echo e(implode(' · ', $line)); ?><?php } ?>
                                    </div>
                                    <div class="rq-meta">
                                        Wants to join <a class="rq-club" href="project?id=<?php echo $pj; ?>"><?php echo e($a['project_title']); ?></a>
                                        <?php if (!empty($a['role_label'])) { ?>as <?php echo e($a['role_label']); ?><?php } ?>
                                        &middot; <?php echo e(date('M j, Y', (int)$a['ts'])); ?>
                                    </div>
                                </div>
                            </div>

                            <form method="POST" class="req-actions">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="member_id" value="<?php echo (int)$a['mid']; ?>">
                                <?php if ($hasRoles) { ?>
                                    <select name="role_id" class="rq-select" aria-label="Role to give">
                                        <option value="0">Teammate (no set role)</option>
                                        <?php foreach ($freeRoles as $r) { ?>
                                            <option value="<?php echo (int)$r['id']; ?>" <?php echo ((int)$a['role_id'] === (int)$r['id']) ? 'selected' : ''; ?>><?php echo e($r['title']); ?></option>
                                        <?php } ?>
                                    </select>
                                <?php } ?>
                                <?php if ($hasApp) { ?>
                                    <button type="button" class="btn-sm rq-view" data-ap-open="<?php echo e($apId); ?>">View application</button>
                                <?php } ?>
                                <button type="submit" name="action" value="accept" class="btn-sm btn-approve">Accept</button>
                                <button type="submit" name="action" value="decline" class="btn-sm btn-reject">Decline</button>
                            </form>
                        </div>

                        <?php if ($hasApp) { ?>
                            <dialog class="dz-modal cfm-card cfm-info ap-modal" id="<?php echo e($apId); ?>" aria-labelledby="<?php echo e($apId); ?>-t">
                                <div class="cfm-body">
                                    <button type="button" class="cfm-x" data-ap-close aria-label="Close"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg></button>
                                    <div class="cfm-head">
                                        <div class="p-avatar ap-avatar"><?php echo avatar_inner($a); ?></div>
                                        <div class="cfm-copy">
                                            <h3 class="cfm-title" id="<?php echo e($apId); ?>-t"><?php echo name_badge($a); ?></h3>
                                            <p class="cfm-text">@<?php echo e($a['username']); ?><?php if ($line) { ?> &middot; <?php echo e(implode(' · ', $line)); ?><?php } ?> &middot; <?php echo e(time_ago($a['ts'])); ?></p>
                                        </div>
                                    </div>
                                    <div class="ap-letter">
                                        <div class="ap-role"><span>Project</span><b><?php echo e($a['project_title']); ?></b></div>
                                        <?php if (!empty($a['role_label'])) { ?>
                                            <div class="ap-role"><span>Applying as</span><b><?php echo e($a['role_label']); ?></b></div>
                                        <?php } ?>
                                        <?php if ($hasMsg) { ?>
                                            <p><?php echo nl2br(e($a['message'])); ?></p>
                                        <?php } else { ?>
                                            <p class="ap-none">They didn&rsquo;t add a message.</p>
                                        <?php } ?>
                                    </div>
                                    <div class="cfm-foot">
                                        <a href="messages?u=<?php echo e(urlencode($a['username'])); ?>" class="cfm-btn cfm-btn-info ap-msg">Message</a>
                                        <button type="button" class="cfm-btn cfm-btn-ghost" data-ap-close>Close</button>
                                    </div>
                                </div>
                            </dialog>
                        <?php } ?>
                    </div>
                <?php } ?>
            </div>

            <script>
            (function () {
                var root = document.getElementById('applications');
                function closeDlg(d) { if (d.close) d.close(); else d.removeAttribute('open'); }
                root.addEventListener('click', function (e) {
                    var open = e.target.closest('[data-ap-open]');
                    if (open) {
                        var d = document.getElementById(open.getAttribute('data-ap-open'));
                        if (d) { if (d.showModal) d.showModal(); else d.setAttribute('open', ''); }
                        return;
                    }
                    var shut = e.target.closest('[data-ap-close]');
                    if (shut) { closeDlg(shut.closest('dialog')); return; }
                    if (e.target.tagName === 'DIALOG') closeDlg(e.target);
                });
            })();
            </script>
        <?php } ?>
    </section>

    <?php } ?>

</main>

</body>
</html>