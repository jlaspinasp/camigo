<?php
session_start();
require("db.php");
require("auth.php");
require("events-lib.php");

$me  = require_user_row($conn);
if ($_SERVER['REQUEST_METHOD'] === 'POST') register_shutdown_function('chat_club_sync_quiet', $conn);

$cid = (int)($_GET['id'] ?? $_POST['club_id'] ?? 0);

// ---- the club (approved clubs only) ----
$cr = db_run($conn,
    "SELECT c.* FROM clubs c WHERE c.id = ? AND c.status = 'approved' LIMIT 1",
    "i", array($cid));
$club = $cr['rows'] ? $cr['rows'][0] : null;

// ---- my place in it ----
$myRole = null;
$myStatus = null;
if ($club) {
    $mr = db_run($conn, "SELECT role, status FROM club_members WHERE club_id = ? AND user_id = ? LIMIT 1", "ii", array($cid, $me['id']));
    if ($mr['rows']) {
        $myRole = $mr['rows'][0]['role'];
        $myStatus = $mr['rows'][0]['status'];
    }
}
$isOwner   = ($myRole === 'owner' && $myStatus === 'active');
$isOfficer = ($myRole === 'officer' && $myStatus === 'active');
$isPending = ($myStatus === 'pending');
$isMember  = ($myRole === 'member' && $myStatus === 'active');
$isInside  = ($isOwner || $isOfficer || $isMember);

// ---- club identity + invite-only (same rules as the club page) ----
$identityReady = ($club && club_identity_ready($conn));
$inviteOnly    = ($identityReady && !empty($club['invite_only']));
$isSiteAdmin   = (($me['role'] ?? 'user') === 'admin');
$canSeeMembers = (!$inviteOnly || $isInside || $isSiteAdmin);   // outsiders never see an invite-only member list

// has the officer upgrade (club-officers.sql) been run?
$officersReady = $club ? officers_ready($conn) : false;

// ---- actions: only the club owner can manage members ----
if ($club && $_SERVER['REQUEST_METHOD'] === 'POST') {

    $action = $_POST['action'] ?? '';
    $mid    = (int)($_POST['member_id'] ?? 0);

    if (!csrf_ok()) {
        flash_set("Your session expired. Please try again.", "error");

    } elseif (!in_array($action, array('promote', 'demote', 'remove'), true)) {
        flash_set("Something went wrong. Please try again.", "error");

    } elseif (!$isOwner || $mid <= 0) {
        flash_set("Only the club owner can do that.", "error");

    } elseif ($action === 'promote') {
        // The target must be an active regular member of THIS club.
        $tu = member_info($conn, $mid);
        $cnt = db_run($conn, "SELECT COUNT(*) AS n FROM club_members WHERE club_id = ? AND role = 'officer' AND status = 'active'", "i", array($cid));
        $have = ($cnt['ok'] && $cnt['rows']) ? (int)$cnt['rows'][0]['n'] : 0;

        if (!$officersReady) {
            flash_set("Officer roles need a one-time upgrade. Run club-officers.sql in phpMyAdmin.", "error");
        } elseif ($have >= CLUB_MAX_OFFICERS) {
            flash_set("A club can have up to " . CLUB_MAX_OFFICERS . " officers. Remove one before adding another.", "error");
        } else {
            $r = db_run($conn, "UPDATE club_members SET role = 'officer' WHERE id = ? AND club_id = ? AND role = 'member' AND status = 'active'", "ii", array($mid, $cid));
            if ($r['ok'] && $r['affected'] > 0 && $tu) {
                notify($conn, $tu['user_id'], 'officer_promoted', 'You are now an officer of ' . $club['name'] . '. You can post announcements and events.', 'club?id=' . $cid);
            }
            flash_set(($r['ok'] && $r['affected'] > 0) ? "Officer added. They can now post announcements and events." : "That member couldn't be promoted.", ($r['ok'] && $r['affected'] > 0) ? "success" : "error");
        }

    } elseif ($action === 'demote') {
        $tu = member_info($conn, $mid);
        $r = db_run($conn, "UPDATE club_members SET role = 'member' WHERE id = ? AND club_id = ? AND role = 'officer' AND status = 'active'", "ii", array($mid, $cid));
        if ($r['ok'] && $r['affected'] > 0 && $tu) {
            notify($conn, $tu['user_id'], 'officer_removed', 'You are no longer an officer of ' . $club['name'] . '.', 'club?id=' . $cid);
        }
        flash_set(($r['ok'] && $r['affected'] > 0) ? "Officer role removed. They're a regular member again." : "That officer couldn't be changed.", ($r['ok'] && $r['affected'] > 0) ? "success" : "error");

    } else {
        // the owner can't be removed; officers and members can
        $tu = member_info($conn, $mid);
        $r = db_run($conn, "DELETE FROM club_members WHERE id = ? AND club_id = ? AND role IN ('member', 'officer') AND status = 'active'", "ii", array($mid, $cid));
        if ($r['ok'] && $r['affected'] > 0 && $tu) {
            event_purge_member_rsvps($conn, $cid, $tu['user_id']);
            notify($conn, $tu['user_id'], 'removed', 'You were removed from ' . $club['name'] . '.', 'clubs');
        }
        flash_set(($r['ok'] && $r['affected'] > 0) ? "Member removed." : "That member couldn't be removed.", ($r['ok'] && $r['affected'] > 0) ? "success" : "error");
    }

    header("Location: club-members?id=" . $cid);
    exit();
}

// ---- members ----
$members = array();
$hiddenCount = 0;
if ($club) {
    if ($canSeeMembers) {
        $members = db_run($conn,
            "SELECT m.id AS mid, m.role, m.joined_at, u.username, u.first_name, u.last_name, u.course, u.year_level, u.photo, u.profile_visibility" . vsel($conn) . "
             FROM club_members m JOIN users u ON u.id = m.user_id
             WHERE m.club_id = ? AND m.status = 'active'
             ORDER BY (m.role = 'owner') DESC, (m.role = 'officer') DESC, m.joined_at ASC LIMIT 500",
            "i", array($cid))['rows'];
    } else {
        $hc = db_run($conn, "SELECT COUNT(*) AS n FROM club_members WHERE club_id = ? AND status = 'active'", "i", array($cid));
        $hiddenCount = $hc['rows'] ? (int)$hc['rows'][0]['n'] : 0;
    }
}
$n = $canSeeMembers ? count($members) : $hiddenCount;

$leaders = array();
$regular = array();
foreach ($members as $m) {
    if ($m['role'] === 'owner' || $m['role'] === 'officer') $leaders[] = $m; else $regular[] = $m;
}
$officerCount = 0;
foreach ($leaders as $l) { if ($l['role'] === 'officer') $officerCount++; }

$flash = flash_get();
$message = $flash ? $flash[0] : "";
$message_class = $flash ? $flash[1] : "";

function member_line($m) {
    $parts = array();
    if (!empty($m['course'])) $parts[] = $m['course'];
    if (!empty($m['year_level'])) $parts[] = $m['year_level'];
    return implode(' · ', $parts);
}
// every member links to their profile; a private profile shows a "private" notice there
function member_link($m, $meUser) {
    if ($m['username'] === $meUser) return 'profile';
    return 'profile?u=' . urlencode($m['username']);
}

// one member card. $m is a row from the members query.
$renderMember = function ($m) use ($club, $me, $isOwner, $officersReady) {
    $link = member_link($m, $me['username']);
    $line = member_line($m);
    $full = trim($m['first_name'] . ' ' . ($m['last_name'] ?? ''));
    $role = $m['role'];
    $fid  = '<input type="hidden" name="club_id" value="' . (int)$club['id'] . '"><input type="hidden" name="member_id" value="' . (int)$m['mid'] . '">';
    $find = strtolower($full . ' ' . $m['username'] . ' ' . $line);
    ?>
    <div class="member<?php echo $role === 'owner' ? ' is-owner' : ($role === 'officer' ? ' is-officer' : ''); ?>" data-find="<?php echo e($find); ?>">
        <div class="p-avatar tiny"><?php echo avatar_inner($m); ?></div>
        <div class="grow">
            <strong><a href="<?php echo e($link); ?>" class="plain"><?php echo name_badge($m, $full); ?></a></strong>
            <div class="club-meta">@<?php echo e($m['username']); ?><?php if ($line !== '') { ?> &middot; <?php echo e($line); ?><?php } ?></div>
        </div>

        <?php if ($role === 'owner') { ?>
            <span class="badge owner"><?php echo event_icon('crown'); ?>Owner</span>
        <?php } elseif ($role === 'officer') { ?>
            <span class="badge officer"><?php echo event_icon('star'); ?>Officer</span>
        <?php } ?>

        <?php if ($isOwner && $role !== 'owner') { ?>
            <details class="m-menu">
                <summary aria-label="Manage <?php echo e($full); ?>"><?php echo event_icon('dots'); ?></summary>
                <div class="m-pop">
                    <?php if ($role === 'member') { ?>
                        <form method="POST" data-confirm="They will be able to post announcements and events for this club." data-confirm-title="Make <?php echo e($m['first_name']); ?> an officer?" data-confirm-ok="Make officer" data-confirm-tone="info" data-confirm-icon="star">
                            <?php echo csrf_field(); echo $fid; ?>
                            <button type="submit" name="action" value="promote"<?php echo $officersReady ? '' : ' disabled title="Run club-officers.sql first"'; ?>><?php echo event_icon('star'); ?>Make officer</button>
                        </form>
                    <?php } else { ?>
                        <form method="POST" data-confirm="They stay in the club as a regular member." data-confirm-title="Remove officer role?" data-confirm-ok="Remove role" data-confirm-tone="warning" data-confirm-icon="userminus">
                            <?php echo csrf_field(); echo $fid; ?>
                            <button type="submit" name="action" value="demote"><?php echo event_icon('userplus'); ?>Remove officer role</button>
                        </form>
                    <?php } ?>
                    <form method="POST" data-confirm="This person will be removed from the club." data-confirm-title="Remove from club?" data-confirm-ok="Remove" data-confirm-tone="warning" data-confirm-icon="userminus">
                        <?php echo csrf_field(); echo $fid; ?>
                        <button type="submit" name="action" value="remove" class="danger"><?php echo event_icon('trash'); ?>Remove from club</button>
                    </form>
                </div>
            </details>
        <?php } ?>
    </div>
    <?php
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Members<?php echo $club ? ' · ' . e($club['name']) : ''; ?> | Camigo</title>
    <link rel="icon" href="favicon.svg" type="image/svg+xml">
    <link rel="icon" href="favicon.ico" sizes="any">
    <link rel="apple-touch-icon" href="apple-touch-icon.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:wght@600;700;800&family=Instrument+Sans:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
    <script src="confirm.js?v=<?php echo (int)@filemtime(__DIR__ . '/confirm.js'); ?>" defer></script>
</head>
<body>

<?php include("topbar.php"); ?>

<main class="main narrow">

    <?php if ($message != "") { ?>
        <div class="msg <?php echo e($message_class); ?>"><?php echo e($message); ?></div>
    <?php } ?>

    <?php if (!$club) { ?>

        <section class="panel center">
            <h2>Club not found</h2>
            <p class="empty">This club doesn't exist, or it hasn't been approved yet. <a href="clubs">Browse clubs</a></p>
        </section>

    <?php } else { ?>

        <p class="crumb"><a href="club?id=<?php echo (int)$club['id']; ?>">&larr; Back to <?php echo e($club['name']); ?></a></p>

        <div class="page-top">
            <div>
                <h1 class="page-title">Members</h1>
                <p class="page-sub"><?php echo e($club['name']); ?> &middot; <?php echo (int)$n; ?> <?php echo $n === 1 ? 'person' : 'people'; ?></p>
            </div>
        </div>

        <?php if (!$canSeeMembers) { ?>

            <section class="panel center locked-panel">
                <div class="lock"><?php echo event_icon('lock'); ?></div>
                <h2>Members are private</h2>
                <p class="empty"><?php echo (int)$n; ?> member<?php echo $n === 1 ? '' : 's'; ?>. This club is invite-only, so only its members can see who's in it.</p>
            </section>

        <?php } else { ?>

            <?php if ($isOwner) { ?>
                <div class="role-note" style="margin-top:20px">
                    <span class="role-note-ic"><?php echo event_icon('shield'); ?></span>
                    <div>
                        <strong>Officers <span class="role-count"><?php echo $officerCount; ?> / <?php echo CLUB_MAX_OFFICERS; ?></span></strong>
                        <p>Promote trusted members from the <b>&hellip;</b> menu. Officers can post and edit announcements and events. They can't edit the club, handle requests, manage members or remove you.</p>
                        <?php if (!$officersReady) { ?><p class="role-warn">One-time upgrade needed: run <strong>club-officers.sql</strong> in phpMyAdmin to turn on officer roles.</p><?php } ?>
                    </div>
                </div>
            <?php } ?>

            <div class="filters">
                <input type="text" id="memberSearch" maxlength="60" placeholder="Search members" autocomplete="off" aria-label="Search members">
            </div>

            <section class="panel" style="margin-top:0">
                <?php if (!$members) { ?>
                    <p class="empty">No members yet.</p>
                <?php } else { ?>
                    <?php if ($leaders) { ?>
                        <div class="member-group">
                            <div class="m-group">Leadership</div>
                            <div class="member-list">
                                <?php foreach ($leaders as $m) { $renderMember($m); } ?>
                            </div>
                        </div>
                    <?php } ?>
                    <?php if ($regular) { ?>
                        <div class="member-group"<?php echo $leaders ? ' style="margin-top:24px"' : ''; ?>>
                            <?php if ($leaders) { ?><div class="m-group">Members</div><?php } ?>
                            <div class="member-list">
                                <?php foreach ($regular as $m) { $renderMember($m); } ?>
                            </div>
                        </div>
                    <?php } ?>
                    <p class="empty" id="memberNone" style="display:none">No one matches that search.</p>
                <?php } ?>
            </section>

            <script>
            (function () {
                var input = document.getElementById("memberSearch");
                var none  = document.getElementById("memberNone");
                if (!input) return;
                var cards  = Array.prototype.slice.call(document.querySelectorAll(".member[data-find]"));
                var groups = Array.prototype.slice.call(document.querySelectorAll(".member-group"));

                input.addEventListener("input", function () {
                    var q = input.value.trim().toLowerCase(), shown = 0;
                    cards.forEach(function (c) {
                        var hit = q === "" || (c.getAttribute("data-find") || "").indexOf(q) !== -1;
                        c.style.display = hit ? "" : "none";
                        if (hit) shown++;
                    });
                    groups.forEach(function (g) {
                        var any = Array.prototype.some.call(g.querySelectorAll(".member"), function (c) { return c.style.display !== "none"; });
                        g.style.display = any ? "" : "none";
                    });
                    if (none) none.style.display = (shown === 0) ? "" : "none";
                });

                // only one manage menu open at a time; click elsewhere or press Esc to close
                var menus = document.querySelectorAll(".m-menu");
                function closeAll(except) { menus.forEach(function (m) { if (m !== except) m.removeAttribute("open"); }); }
                menus.forEach(function (m) { m.addEventListener("toggle", function () { if (m.open) closeAll(m); }); });
                document.addEventListener("click", function (e) { if (!e.target.closest(".m-menu")) closeAll(null); });
                document.addEventListener("keydown", function (e) { if (e.key === "Escape") closeAll(null); });
            })();
            </script>

        <?php } ?>

    <?php } ?>

</main>

</body>
</html>