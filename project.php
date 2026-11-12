<?php
session_start();
require("db.php");
require("auth.php");
require_once("projects-lib.php");
require_once("feed-lib.php");

$me  = require_user_row($conn);
if ($_SERVER['REQUEST_METHOD'] === 'POST') register_shutdown_function('chat_club_sync_quiet', $conn);

$pid = (int)($_GET['id'] ?? $_POST['project_id'] ?? 0);

// ================= ACTIONS =================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $action = $_POST['action'] ?? '';
    $self   = "project?id=" . $pid;
    $dest   = $self;

    $p = $pid > 0 ? project_get($conn, $pid) : null;

    if (!csrf_ok()) {
        flash_set("Your session expired. Please try again.", "error");

    } elseif (!$p) {
        flash_set("That project no longer exists.", "error");
        $dest = "projects";

    } else {
        $isOwner = ((int)$p['owner_id'] === (int)$me['id']);
        $mr = db_run($conn, "SELECT id, role, status FROM project_members WHERE project_id = ? AND user_id = ? LIMIT 1", "ii", array($pid, $me['id']));
        $mine = $mr['rows'] ? $mr['rows'][0] : null;
        $isActive  = ($mine && $mine['status'] === 'active');
        $isPending = ($mine && $mine['status'] === 'pending');

        // ---------- someone applies ----------
        if ($action === 'apply') {
            $roles = project_roles($conn, $pid);
            $free  = project_open_spots($roles);
            $note  = trim(str_replace("\r\n", "\n", (string)($_POST['message'] ?? '')));
            $roleId = (int)($_POST['role_id'] ?? 0);
            $pick  = null;
            foreach ($roles as $r) { if ((int)$r['id'] === $roleId) $pick = $r; }

            if ($isOwner) {
                flash_set("This is your own project.", "error");
            } elseif ($mine) {
                flash_set($isActive ? "You're already on this team." : "You've already applied to this project.", "error");
            } elseif (empty($p['is_open'])) {
                flash_set("This project isn't taking applications right now.", "error");
            } elseif ($roles && $free === 0) {
                flash_set("Every spot on this team is taken right now.", "error");
            } elseif ($roleId > 0 && (!$pick || (int)$pick['filled'] >= (int)$pick['spots'])) {
                flash_set("That role has no spots left. Pick another one.", "error");
            } elseif (str_len($note) < 10 || str_len($note) > 400) {
                flash_set("Tell the founder a little about you and why you want to join (10 to 400 characters).", "error");
            } else {
                $cnt = db_run($conn, "SELECT COUNT(*) AS n FROM project_members WHERE user_id = ? AND status = 'pending'", "i", array($me['id']));
                if ($cnt['ok'] && $cnt['rows'] && (int)$cnt['rows'][0]['n'] >= PROJECT_MAX_PENDING) {
                    flash_set("You have " . PROJECT_MAX_PENDING . " applications waiting. Wait for answers or withdraw one first.", "error");
                } else {
                    $r = db_run($conn,
                        "INSERT INTO project_members (project_id, user_id, role, status, role_id, role_label, message)
                         VALUES (?, ?, 'member', 'pending', ?, ?, ?)",
                        "iiiss", array($pid, $me['id'], $pick ? (int)$pick['id'] : null, $pick ? $pick['title'] : null, $note));
                    if ($r['ok']) {
                        notify($conn, $p['owner_id'], 'project_apply',
                            full_name($me) . ' applied to join ' . $p['title'] . ($pick ? ' as ' . $pick['title'] : '') . '.', 'project?id=' . $pid);
                        flash_set("Application sent. The founder will review it and you'll get a notification.", "success");
                    } else {
                        flash_set("Something went wrong. Please try again.", "error");
                    }
                }
            }

        // ---------- take the application back ----------
        } elseif ($action === 'withdraw') {
            $r = db_run($conn, "DELETE FROM project_members WHERE project_id = ? AND user_id = ? AND role = 'member' AND status = 'pending'", "ii", array($pid, $me['id']));
            flash_set(($r['ok'] && $r['affected'] > 0) ? "Application withdrawn." : "There was no application to withdraw.", ($r['ok'] && $r['affected'] > 0) ? "success" : "error");

        // ---------- a team member leaves ----------
        } elseif ($action === 'leave') {
            $r = db_run($conn, "DELETE FROM project_members WHERE project_id = ? AND user_id = ? AND role = 'member' AND status = 'active'", "ii", array($pid, $me['id']));
            if ($r['ok'] && $r['affected'] > 0) {
                notify($conn, $p['owner_id'], 'project_left', full_name($me) . ' left ' . $p['title'] . '.', 'project?id=' . $pid);
                flash_set("You left the project.", "success");
            } else {
                flash_set("You can't leave that project.", "error");
            }

        // ---------- founder: accept ----------
        } elseif ($action === 'accept' && $isOwner) {
            $mid = (int)($_POST['member_id'] ?? 0);
            $ar = db_run($conn, "SELECT id, user_id FROM project_members WHERE id = ? AND project_id = ? AND status = 'pending' LIMIT 1", "ii", array($mid, $pid));
            $app = $ar['rows'] ? $ar['rows'][0] : null;

            $roles = project_roles($conn, $pid);
            $assignId = (int)($_POST['role_id'] ?? 0);
            $assign = null;
            foreach ($roles as $r) { if ((int)$r['id'] === $assignId) $assign = $r; }

            if (!$app) {
                flash_set("That application is no longer waiting.", "error");
            } elseif ($assignId > 0 && (!$assign || (int)$assign['filled'] >= (int)$assign['spots'])) {
                flash_set("That role is full. Raise its spots in Edit project, or pick another role.", "error");
            } else {
                $label = $assign ? $assign['title'] : 'Teammate';
                $r = db_run($conn,
                    "UPDATE project_members SET status = 'active', role_id = ?, role_label = ? WHERE id = ? AND project_id = ? AND status = 'pending'",
                    "isii", array($assign ? (int)$assign['id'] : null, $label, $mid, $pid));
                if ($r['ok'] && $r['affected'] > 0) {
                    notify($conn, $app['user_id'], 'project_accepted',
                        "You're in! " . $p['title'] . " accepted you" . ($assign ? ' as ' . $assign['title'] : '') . '.', 'project?id=' . $pid);
                    flash_set("They're on the team now, and they've been told.", "success");
                } else {
                    flash_set("Something went wrong. Please try again.", "error");
                }
            }

        // ---------- founder: decline ----------
        } elseif ($action === 'decline' && $isOwner) {
            $mid = (int)($_POST['member_id'] ?? 0);
            $ar = db_run($conn, "SELECT user_id FROM project_members WHERE id = ? AND project_id = ? AND status = 'pending' LIMIT 1", "ii", array($mid, $pid));
            if ($ar['rows']) {
                db_run($conn, "DELETE FROM project_members WHERE id = ? AND project_id = ? AND status = 'pending'", "ii", array($mid, $pid));
                notify($conn, $ar['rows'][0]['user_id'], 'project_declined',
                    $p['title'] . " couldn't offer you a spot this time. Plenty of other projects are looking for people.", 'projects');
                flash_set("Application declined.", "success");
            } else {
                flash_set("That application is no longer waiting.", "error");
            }

        // ---------- founder: remove a teammate ----------
        } elseif ($action === 'remove' && $isOwner) {
            $mid = (int)($_POST['member_id'] ?? 0);
            $ar = db_run($conn, "SELECT user_id FROM project_members WHERE id = ? AND project_id = ? AND status = 'active' AND role = 'member' LIMIT 1", "ii", array($mid, $pid));
            if ($ar['rows']) {
                db_run($conn, "DELETE FROM project_members WHERE id = ? AND project_id = ? AND role = 'member'", "ii", array($mid, $pid));
                notify($conn, $ar['rows'][0]['user_id'], 'project_removed', "You were removed from " . $p['title'] . ".", 'projects');
                flash_set("They were removed from the team.", "success");
            } else {
                flash_set("That person isn't on the team.", "error");
            }

        // ---------- team: post a progress update ----------
        } elseif ($action === 'post_update' && ($isOwner || $isActive)) {
            $body = trim(str_replace("\r\n", "\n", (string)($_POST['body'] ?? '')));
            if (str_len($body) < 3 || str_len($body) > 600) {
                flash_set("Updates can be 3 to 600 characters.", "error");
            } else {
                $rl = db_run($conn, "SELECT COUNT(*) AS n FROM project_updates WHERE user_id = ? AND created_at > (NOW() - INTERVAL 1 HOUR)", "i", array($me['id']));
                if ($rl['ok'] && $rl['rows'] && (int)$rl['rows'][0]['n'] >= 10) {
                    flash_set("You've posted a lot of updates in the last hour. Please wait a bit.", "error");
                } else {
                    $r = db_run($conn, "INSERT INTO project_updates (project_id, user_id, body) VALUES (?, ?, ?)", "iis", array($pid, $me['id'], $body));
                    if ($r['ok']) {
                        db_run($conn, "UPDATE projects SET updated_at = CURRENT_TIMESTAMP WHERE id = ?", "i", array($pid));
                        project_notify_team($conn, $pid, $me['id'], 'project_update',
                            full_name($me) . ' posted an update in ' . $p['title'] . '.', 'project?id=' . $pid);
                        flash_set("Update posted.", "success");
                    } else {
                        flash_set("Something went wrong. Please try again.", "error");
                    }
                }
            }

        // ---------- team: delete an update ----------
        } elseif ($action === 'delete_update') {
            $uid = (int)($_POST['update_id'] ?? 0);
            if ($isOwner) {
                $r = db_run($conn, "DELETE FROM project_updates WHERE id = ? AND project_id = ?", "ii", array($uid, $pid));
            } else {
                $r = db_run($conn, "DELETE FROM project_updates WHERE id = ? AND project_id = ? AND user_id = ?", "iii", array($uid, $pid, $me['id']));
            }
            flash_set(($r['ok'] && $r['affected'] > 0) ? "Update deleted." : "You can't delete that update.", ($r['ok'] && $r['affected'] > 0) ? "success" : "error");
        }
    }

    header("Location: " . $dest);
    exit();
}

// ================= THE PROJECT =================
$probe = db_run($conn, "SELECT COUNT(*) AS n FROM projects WHERE id = ?", "i", array($pid));
$setupMissing = !$probe['ok'];
$p = (!$setupMissing && $pid > 0) ? project_get($conn, $pid) : null;

$roles = array();
$team = array();
$applicants = array();
$updates = array();
$myApp = null;
$isOwner = $isMember = $isPending = false;

if ($p) {
    $isOwner = ((int)$p['owner_id'] === (int)$me['id']);
    $roles   = project_roles($conn, $pid);

    $tr = db_run($conn,
        "SELECT m.id AS mid, m.user_id, m.role, m.role_label, m.role_id, UNIX_TIMESTAMP(m.created_at) AS ts,
                u.username, u.first_name, u.last_name, u.course, u.year_level, u.photo, u.profile_visibility
         FROM project_members m JOIN users u ON u.id = m.user_id
         WHERE m.project_id = ? AND m.status = 'active'
         ORDER BY (m.role = 'owner') DESC, m.created_at ASC LIMIT 60",
        "i", array($pid));
    $team = $tr['rows'];
    foreach ($team as $t) { if ((int)$t['user_id'] === (int)$me['id']) $isMember = true; }

    // the founder sees everyone who applied; an applicant sees only their own application
    if ($isOwner) {
        $ar = db_run($conn,
            "SELECT m.id AS mid, m.user_id, m.role_id, m.role_label, m.message, UNIX_TIMESTAMP(m.created_at) AS ts,
                    u.username, u.first_name, u.last_name, u.course, u.year_level, u.photo
             FROM project_members m JOIN users u ON u.id = m.user_id
             WHERE m.project_id = ? AND m.status = 'pending'
             ORDER BY m.created_at ASC LIMIT 100",
            "i", array($pid));
        $applicants = $ar['rows'];
    } elseif (!$isMember) {
        $mr = db_run($conn, "SELECT id, role_label, message FROM project_members WHERE project_id = ? AND user_id = ? AND status = 'pending' LIMIT 1", "ii", array($pid, $me['id']));
        if ($mr['rows']) { $myApp = $mr['rows'][0]; $isPending = true; }
    }

    $ur = db_run($conn,
        "SELECT up.id, up.user_id, up.body, UNIX_TIMESTAMP(up.created_at) AS ts, u.username, u.first_name, u.last_name, u.photo
         FROM project_updates up LEFT JOIN users u ON u.id = up.user_id
         WHERE up.project_id = ? ORDER BY up.id DESC LIMIT 20",
        "i", array($pid));
    $updates = $ur['rows'];
}

$flash = flash_get();
$message = $flash ? $flash[0] : "";
$message_class = $flash ? $flash[1] : "";

$openSpots = $roles ? project_open_spots($roles) : 0;
$totalSpots = 0; foreach ($roles as $r) $totalSpots += (int)$r['spots'];
$filledSpots = $totalSpots - $openSpots;
$status    = $p ? project_status($p, $roles) : array('', '');
$canApply  = $p && !$isOwner && !$isMember && !$isPending && !empty($p['is_open']) && (!$roles || $openSpots > 0);
$freeRoles = array();
foreach ($roles as $r) { if ((int)$r['filled'] < (int)$r['spots']) $freeRoles[] = $r; }
$wantRole  = (int)($_GET['role'] ?? 0);
$tone      = $p ? project_tone($p['id']) : 0;
$skills    = $p ? project_tags($p['skills']) : array();
$linkUrl   = ($p && !empty($p['link']) && preg_match('#^https?://#i', $p['link'])) ? $p['link'] : '';
$canPost   = $p && ($isOwner || $isMember);

// ---- the team's group chat (created automatically; team members are added automatically) ----
$teamChatId = 0;
if ($p && ($isOwner || $isMember) && chat_ensure_schema($conn)) {
    $teamChatId = chat_group_for_project($conn, $pid);
    if (!$teamChatId || !chat_group_role($conn, $teamChatId, $me['id'])) {
        chat_club_sync($conn);
        $teamChatId = chat_group_for_project($conn, $pid);
    }
}
$teamChatHref = $teamChatId ? 'messages?g=' . (int)$teamChatId : 'messages';
$profileLink = function ($row) use ($me) {
    return ($row['username'] === $me['username']) ? 'profile' : 'profile?u=' . urlencode($row['username']);
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo $p ? e($p['title']) . ' | ' : ''; ?>Project | Camigo</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:wght@600;700;800&family=Instrument+Sans:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
    <script src="confirm.js?v=<?php echo (int)@filemtime(__DIR__ . '/confirm.js'); ?>" defer></script>
</head>
<body>

<?php include("topbar.php"); ?>

<main class="main pj-page">

    <?php if ($message != "") { ?>
        <div class="msg <?php echo e($message_class); ?>"><?php echo e($message); ?></div>
    <?php } ?>

    <?php if ($setupMissing) { ?>

        <div class="msg error">Projects aren't set up yet. Run <strong>projects.sql</strong> in phpMyAdmin.</div>

    <?php } elseif (!$p) { ?>

        <section class="panel center">
            <h2>Project not found</h2>
            <p class="empty">It may have been removed. <a href="projects">Browse projects</a></p>
        </section>

    <?php } else { ?>

        <p class="crumb"><a href="projects">&larr; All projects</a></p>

        <?php $heroImg = project_banner_url($p); ?>
        <section class="ev-hero pj-hero t<?php echo $tone; ?><?php echo $heroImg !== '' ? ' has-banner' : ''; ?>"<?php if ($heroImg !== '') { ?> style="--ev-banner:url('<?php echo e($heroImg); ?>')"<?php } ?>>
            <div class="ev-badges">
                <span class="chip"><?php echo project_category_icon($p['category']); ?><?php echo e($p['category']); ?></span>
                <?php if (!empty($p['club_name'])) { ?><a class="chip" href="club?id=<?php echo (int)$p['club_id']; ?>"><?php echo event_icon('users'); ?><?php echo e($p['club_name']); ?></a><?php } ?>
            </div>

            <h1><?php echo e($p['title']); ?></h1>
            <p class="pj-hero-tag"><?php echo e($p['tagline']); ?></p>

            <div class="ev-facts">
                <div class="fact"><span class="fi"><?php echo event_icon('crown'); ?></span><div><small>Founder</small><strong><?php echo e(full_name($p)); ?></strong></div></div>
                <div class="fact"><span class="fi"><?php echo event_icon('users'); ?></span><div><small>Team</small><strong><?php echo count($team); ?> <?php echo count($team) === 1 ? 'person' : 'people'; ?></strong></div></div>
            </div>
        </section>

        <div class="ev-layout<?php echo ($isOwner || $isMember) ? ' pj-has-actions' : ''; ?>">

            <?php if ($isOwner || $isMember) { ?>
            <div class="top-actions pj-actions">
                <a href="<?php echo e($teamChatHref); ?>" class="btn-sm btn-ghost"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M5 4.5h14a1.5 1.5 0 011.5 1.5v9a1.5 1.5 0 01-1.5 1.5h-8L6.5 20.5V16.5H5A1.5 1.5 0 013.5 15V6A1.5 1.5 0 015 4.5z"/></svg>Team chat</a>
                <?php if ($isOwner) { ?><a href="edit-project?id=<?php echo $pid; ?>" class="btn-sm btn-ghost">Edit project</a><?php } ?>
                <?php if (!$isOwner) { ?>
                <form method="POST" data-confirm="You will be removed from this project's team." data-confirm-title="Leave this project?" data-confirm-ok="Leave project" data-confirm-tone="warning" data-confirm-icon="exit">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="project_id" value="<?php echo $pid; ?>">
                    <button type="submit" name="action" value="leave" class="btn-sm btn-ghost">Leave project</button>
                </form>
                <?php } ?>
            </div>
            <?php } ?>

            <div class="ev-main">

                <?php if ($isOwner && $applicants) { ?>
                <section class="panel rq-panel pj-plain pj-apps" style="margin-top:0" id="applications">
                    <h2>Applications (<?php echo count($applicants); ?>)</h2>
                    <div class="club-list">
                        <?php foreach ($applicants as $a) {
                            $line = array();
                            if (!empty($a['course'])) $line[] = $a['course'];
                            if (!empty($a['year_level'])) $line[] = $a['year_level'];
                            $hasMsg = trim((string)$a['message']) !== '';
                            $hasApp = $hasMsg || !empty($a['role_label']);
                            $apId = 'ap-' . (int)$a['mid'];
                        ?>
                            <div class="club-item rq-item pj-rq">
                                <div class="req">
                                    <div class="req-who">
                                        <div class="p-avatar tiny"><?php echo avatar_inner($a); ?></div>
                                        <div class="rq-text">
                                            <a class="rq-name" href="<?php echo e($profileLink($a)); ?>"><?php echo e(full_name($a)); ?></a>
                                            <div class="rq-meta">@<?php echo e($a['username']); ?><?php if ($line) { ?> &middot; <?php echo e(implode(' · ', $line)); ?><?php } ?></div>
                                        </div>
                                    </div>

                                    <form method="POST" class="req-actions">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="project_id" value="<?php echo $pid; ?>">
                                        <input type="hidden" name="member_id" value="<?php echo (int)$a['mid']; ?>">
                                        <?php if ($roles) { ?>
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
                                                    <h3 class="cfm-title" id="<?php echo e($apId); ?>-t"><?php echo e(full_name($a)); ?></h3>
                                                    <p class="cfm-text">@<?php echo e($a['username']); ?><?php if ($line) { ?> &middot; <?php echo e(implode(' · ', $line)); ?><?php } ?> &middot; <?php echo e(time_ago($a['ts'])); ?></p>
                                                </div>
                                            </div>
                                            <div class="ap-letter">
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
                </section>
                <?php } ?>

                <section class="panel pj-plain" style="<?php echo ($isOwner && $applicants) ? '' : 'margin-top:0'; ?>">
                    <h2>About this project</h2>
                    <p class="ev-about"><?php echo nl2br(e($p['description'])); ?></p>

                    <?php if ($skills || $linkUrl) { ?>
                        <div class="pj-about-foot">
                            <?php if ($skills) { ?>
                                <div>
                                    <small>Skills &amp; tools</small>
                                    <div class="chip-row">
                                        <?php foreach ($skills as $t) { ?><span class="chip"><?php echo e($t); ?></span><?php } ?>
                                    </div>
                                </div>
                            <?php } ?>
                            <?php if ($linkUrl) { ?>
                                <div>
                                    <small>Link</small>
                                    <a class="link-pill pj-link" href="<?php echo e($linkUrl); ?>" target="_blank" rel="noopener noreferrer nofollow"><?php echo project_icon('link'); ?><?php echo e(preg_replace('#^https?://(www\.)?#i', '', rtrim($linkUrl, '/'))); ?></a>
                                </div>
                            <?php } ?>
                        </div>
                    <?php } ?>
                </section>

                <?php if ($roles) { ?>
                <section class="panel">
                    <div class="club-head">
                        <h2 style="margin:0">Looking for</h2>
                        <span class="club-meta"><?php echo $filledSpots; ?> of <?php echo $totalSpots; ?> spots filled</span>
                    </div>
                    <div class="pj-role-list">
                        <?php foreach ($roles as $r) {
                            $sp = (int)$r['spots']; $fl = min($sp, (int)$r['filled']);
                            $left = $sp - $fl;
                            $pc = $sp > 0 ? (int)round($fl / $sp * 100) : 0;
                        ?>
                            <div class="pj-role-row<?php echo $left === 0 ? ' is-full' : ''; ?>">
                                <strong><?php echo e($r['title']); ?></strong>
                                <span class="club-meta"><?php echo $left === 0 ? 'Filled' : ($left === 1 ? '1 spot open' : $left . ' spots open'); ?></span>
                            </div>
                        <?php } ?>
                    </div>
                </section>
                <?php } ?>

                <section class="panel pj-plain">
                    <h2>The team (<?php echo count($team); ?>)</h2>
                    <div class="pj-team">
                        <?php foreach ($team as $t) {
                            $line = array();
                            if (!empty($t['course'])) $line[] = $t['course'];
                            if (!empty($t['year_level'])) $line[] = $t['year_level'];
                            $tOwner = ($t['role'] === 'owner');
                            $tMe = ((int)$t['user_id'] === (int)$me['id']);
                        ?>
                            <div class="member pj-member">
                                <div class="p-avatar tiny"><?php echo avatar_inner($t); ?></div>
                                <div class="grow">
                                    <strong><a href="<?php echo e($profileLink($t)); ?>" class="plain"><?php echo e(full_name($t)); ?></a></strong>
                                    <div class="club-meta"><?php echo e(!empty($t['role_label']) ? $t['role_label'] : ($tOwner ? 'Founder' : 'Teammate')); ?><?php if ($line) { ?> &middot; <?php echo e(implode(' · ', $line)); ?><?php } ?></div>
                                </div>
                                <?php if ($tOwner) { ?>
                                    <span class="lead-badge owner" title="Founder"><?php echo event_icon('crown'); ?></span>
                                <?php } elseif ($isOwner) { ?>
                                    <form method="POST" data-confirm="This person will be removed from the project team." data-confirm-title="Remove from team?" data-confirm-ok="Remove" data-confirm-tone="warning" data-confirm-icon="userminus" style="margin:0">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="project_id" value="<?php echo $pid; ?>">
                                        <input type="hidden" name="member_id" value="<?php echo (int)$t['mid']; ?>">
                                        <button type="submit" name="action" value="remove" class="tb-link pj-remove">Remove</button>
                                    </form>
                                <?php } ?>
                            </div>
                        <?php } ?>
                    </div>
                </section>

                <section class="panel hf-flat">
                    <h2>Progress updates</h2>

                    <?php if ($canPost) { ?>
                        <form method="POST" class="pj-compose">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="project_id" value="<?php echo $pid; ?>">
                            <textarea name="body" maxlength="600" placeholder="Share what you shipped, learned or need help with. Your team gets a notification." required></textarea>
                            <div class="pj-compose-foot">
                                <span class="club-meta">Your team will be notified.</span>
                                <button type="submit" name="action" value="post_update" class="btn-sm btn-dark">Post update</button>
                            </div>
                        </form>
                    <?php } ?>

                    <?php if (!$updates) { ?>
                        <p class="empty"><?php echo $canPost ? "No updates yet. Post the first one to show momentum." : "No updates yet."; ?></p>
                    <?php } else { ?>
                        <div class="hf-list hf-full-list">
                            <?php foreach ($updates as $u) {
                                $canDel = ($isOwner || (int)$u['user_id'] === (int)$me['id']);
                                $gone   = user_gone($u);   // the author deleted their account: shown as "Deleted user", no profile to open
                                $ulink  = $gone ? '' : (($u['username'] === $me['username']) ? 'profile' : 'profile?u=' . urlencode($u['username']));

                                // delete button, at the right of the card's footer
                                ob_start();
                                if ($canDel) { ?>
                                    <form method="POST" data-confirm="This update will be deleted from the project." data-confirm-title="Delete this update?" data-confirm-ok="Delete" data-confirm-tone="danger" data-confirm-icon="trash" style="margin:0">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="project_id" value="<?php echo $pid; ?>">
                                        <input type="hidden" name="update_id" value="<?php echo (int)$u['id']; ?>">
                                        <button type="submit" name="action" value="delete_update" class="pj-icon-btn" aria-label="Delete update" title="Delete"><?php echo event_icon('trash'); ?></button>
                                    </form>
                                <?php }
                                $deleteHtml = ob_get_clean();

                                feed_post_full(array(
                                    'dom_id'      => 'update-' . (int)$u['id'],
                                    'tile'        => '<span class="pj-mini-ic t' . project_tone($p['id']) . '">' . project_category_icon($p['category']) . '</span>',
                                    'name'        => $p['title'],
                                    'meta'        => array(
                                        '<span class="hf-kind">' . project_icon('rocket') . 'Progress update</span>',
                                        '<time>' . e(time_ago($u['ts'])) . '</time>',
                                    ),
                                    'text_html'   => feed_text_html($u['body']),
                                    'author'      => $u,
                                    'author_href' => $ulink,
                                    'actions'     => $deleteHtml,
                                ));
                            } ?>
                        </div>
                        <?php if (count($updates) < 20) { ?>
                            <div class="hf-end">You're all caught up</div>
                        <?php } ?>
                    <?php } ?>
                </section>
            </div>

            <aside class="ev-side">

                <section class="panel pj-cta" id="apply" style="margin-top:0">

                    <?php if ($isOwner) { ?>

                        <div class="ev-count">
                            <b><?php echo count($team); ?></b>
                            <span><?php echo count($team) === 1 ? 'person on your team' : 'people on your team'; ?></span>
                        </div>
                        <?php if ($roles) { ?>
                            <div class="progress ev-bar"><i style="width: <?php echo $totalSpots > 0 ? (int)round($filledSpots / $totalSpots * 100) : 0; ?>%"></i></div>
                            <p class="club-meta" style="margin-top:8px"><?php echo $filledSpots; ?> of <?php echo $totalSpots; ?> role spots filled.</p>
                        <?php } ?>
                        <p class="club-meta" style="margin-top:12px"><?php echo count($applicants); ?> application<?php echo count($applicants) === 1 ? '' : 's'; ?> waiting.</p>
                        <p class="pj-note"><?php echo project_icon('send'); ?><span><?php echo !empty($p['is_open']) ? 'Your project is open. New applications show up on this page and in your notifications.' : 'Applications are closed. Reopen them any time from Edit project.'; ?></span></p>

                    <?php } elseif ($isMember) { ?>

                        <h2>You're on the team</h2>
                        <?php foreach ($team as $t) { if ((int)$t['user_id'] === (int)$me['id']) { ?>
                            <p class="club-meta">Role: <strong><?php echo e(!empty($t['role_label']) ? $t['role_label'] : 'Teammate'); ?></strong></p>
                        <?php } } ?>

                    <?php } elseif ($isPending) { ?>

                        <h2>Application sent</h2>
                        <p class="club-meta">The founder will review it. You'll get a notification when they answer.</p>
                        <?php if (!empty($myApp['role_label'])) { ?><p class="club-meta" style="margin-top:10px">Role: <strong><?php echo e($myApp['role_label']); ?></strong></p><?php } ?>
                        <blockquote class="pj-quote" style="margin-top:12px"><?php echo nl2br(e($myApp['message'])); ?></blockquote>
                        <form method="POST" style="margin-top:14px">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="project_id" value="<?php echo $pid; ?>">
                            <button type="submit" name="action" value="withdraw" class="btn-sm btn-ghost" style="width:100%">Withdraw application</button>
                        </form>

                    <?php } elseif ($canApply) { ?>

                        <h2>Join this project</h2>
                        <p class="club-meta" style="margin:-6px 0 14px">Tell <?php echo e($p['first_name']); ?> who you are and what you'd bring.</p>
                        <form method="POST">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="project_id" value="<?php echo $pid; ?>">

                            <?php if ($roles) { ?>
                                <div class="field">
                                    <label for="role_id">Role</label>
                                    <select name="role_id" id="role_id">
                                        <option value="0">Open to anything</option>
                                        <?php foreach ($freeRoles as $r) { ?>
                                            <option value="<?php echo (int)$r['id']; ?>" <?php echo ($wantRole === (int)$r['id']) ? 'selected' : ''; ?>><?php echo e($r['title']); ?></option>
                                        <?php } ?>
                                    </select>
                                </div>
                            <?php } ?>

                            <div class="field">
                                <label for="message">Your message</label>
                                <textarea name="message" id="message" maxlength="400" placeholder="A bit about you, what you can do, and how much time you have." required></textarea>
                            </div>
                            <button type="submit" name="action" value="apply" class="btn">Send application</button>
                        </form>

                    <?php } else { ?>

                        <h2><?php echo $roles && $openSpots === 0 && !empty($p['is_open']) ? 'The team is full' : "Not recruiting right now"; ?></h2>
                        <p class="club-meta">You can still message the founder, or <a href="projects">find another project</a>.</p>
                        <a href="messages?u=<?php echo e(urlencode($p['username'])); ?>" class="btn" style="margin-top:16px"><?php echo project_icon('chat'); ?>Message the founder</a>

                    <?php } ?>
                </section>

                <section class="panel ev-host" style="margin-top:0">
                    <small>Started by</small>
                    <a class="ev-host-row" href="<?php echo e($profileLink($p)); ?>">
                        <div class="p-avatar tiny"><?php echo avatar_inner($p); ?></div>
                        <div>
                            <strong><?php echo e(full_name($p)); ?></strong>
                            <em><?php
                                $line = array();
                                if (!empty($p['course'])) $line[] = $p['course'];
                                if (!empty($p['year_level'])) $line[] = $p['year_level'];
                                echo e($line ? implode(' · ', $line) : '@' . $p['username']);
                            ?></em>
                        </div>
                    </a>
                    <p class="club-meta" style="margin-top:12px">Posted <?php echo e(time_ago($p['created_ts'])); ?></p>
                </section>
            </aside>
        </div>

    <?php } ?>

</main>

<script>
// the side column sticks just under the top bar, whatever its height
(function () {
    var t = document.querySelector(".topbar");
    function set() { if (t) document.documentElement.style.setProperty("--topbar-h", t.offsetHeight + "px"); }
    set();
    window.addEventListener("resize", set);
    window.addEventListener("load", set);
})();
</script>

</body>
</html>