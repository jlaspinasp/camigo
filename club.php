<?php
session_start();
require("db.php");
require("auth.php");

$me  = require_user_row($conn);
$cid = (int)($_GET['id'] ?? $_POST['club_id'] ?? 0);

// ---- the club (approved clubs only) ----
$cr = db_run($conn,
    "SELECT c.id, c.name, c.category, c.description, c.created_at,
            u.username AS creator_username, u.first_name AS creator_first, u.last_name AS creator_last
     FROM clubs c LEFT JOIN users u ON u.id = c.created_by
     WHERE c.id = ? AND c.status = 'approved' LIMIT 1",
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
$isPending = ($myStatus === 'pending');
$isMember  = ($myRole === 'member' && $myStatus === 'active');

// ---- actions ----
if ($club && $_SERVER['REQUEST_METHOD'] === 'POST') {

    $action = $_POST['action'] ?? '';
    $mid    = (int)($_POST['member_id'] ?? 0);

    if (!csrf_ok()) {
        flash_set("Your session expired. Please try again.", "error");

    } elseif ($action === 'join') {
        $r = db_run($conn,
            "INSERT IGNORE INTO club_members (club_id, user_id, role, status) VALUES (?, ?, 'member', 'pending')",
            "ii", array($cid, $me['id']));
        if ($r['ok'] && $r['affected'] > 0) {
            notify_join_request($conn, $cid, $me);
            flash_set("Request sent. The club owner will review it.", "success");
        } else {
            flash_set("You've already requested or joined this club.", "error");
        }

    } elseif ($action === 'cancel') {
        $r = db_run($conn, "DELETE FROM club_members WHERE club_id = ? AND user_id = ? AND role = 'member' AND status = 'pending'", "ii", array($cid, $me['id']));
        flash_set(($r['ok'] && $r['affected'] > 0) ? "Request cancelled." : "There was no request to cancel.", ($r['ok'] && $r['affected'] > 0) ? "success" : "error");

    } elseif ($action === 'leave') {
        $r = db_run($conn, "DELETE FROM club_members WHERE club_id = ? AND user_id = ? AND role = 'member' AND status = 'active'", "ii", array($cid, $me['id']));
        flash_set(($r['ok'] && $r['affected'] > 0) ? "You left the club." : "You can't leave this club.", ($r['ok'] && $r['affected'] > 0) ? "success" : "error");

    } elseif (in_array($action, array('accept', 'decline', 'remove'), true)) {

        if (!$isOwner || $mid <= 0) {
            flash_set("Only the club owner can do that.", "error");
        } elseif ($action === 'accept') {
            $tu = member_info($conn, $mid);
            $r = db_run($conn, "UPDATE club_members SET status = 'active' WHERE id = ? AND club_id = ? AND status = 'pending'", "ii", array($mid, $cid));
            if ($r['ok'] && $r['affected'] > 0 && $tu) {
                notify($conn, $tu['user_id'], 'join_accepted', 'Your request to join ' . $club['name'] . ' was accepted.', 'club?id=' . $cid);
            }
            flash_set(($r['ok'] && $r['affected'] > 0) ? "Member accepted." : "That request was already handled.", ($r['ok'] && $r['affected'] > 0) ? "success" : "error");
        } elseif ($action === 'decline') {
            $tu = member_info($conn, $mid);
            $r = db_run($conn, "DELETE FROM club_members WHERE id = ? AND club_id = ? AND status = 'pending'", "ii", array($mid, $cid));
            if ($r['ok'] && $r['affected'] > 0 && $tu) {
                notify($conn, $tu['user_id'], 'join_declined', 'Your request to join ' . $club['name'] . ' was declined.', 'clubs');
            }
            flash_set(($r['ok'] && $r['affected'] > 0) ? "Request declined." : "That request was already handled.", ($r['ok'] && $r['affected'] > 0) ? "success" : "error");
        } else {
            // owners can't be removed
            $tu = member_info($conn, $mid);
            $r = db_run($conn, "DELETE FROM club_members WHERE id = ? AND club_id = ? AND role = 'member' AND status = 'active'", "ii", array($mid, $cid));
            if ($r['ok'] && $r['affected'] > 0 && $tu) {
                notify($conn, $tu['user_id'], 'removed', 'You were removed from ' . $club['name'] . '.', 'clubs');
            }
            flash_set(($r['ok'] && $r['affected'] > 0) ? "Member removed." : "That member couldn't be removed.", ($r['ok'] && $r['affected'] > 0) ? "success" : "error");
        }
    }

    header("Location: club?id=" . $cid);
    exit();
}

// ---- members + (for the owner) pending requests ----
$members = array();
$pending = array();
if ($club) {
    $sqlBase = "SELECT m.id AS mid, m.role, m.joined_at, u.username, u.first_name, u.last_name, u.course, u.year_level, u.photo, u.profile_visibility
                FROM club_members m JOIN users u ON u.id = m.user_id
                WHERE m.club_id = ? AND m.status = ";

    $members = db_run($conn, $sqlBase . "'active' ORDER BY (m.role = 'owner') DESC, m.joined_at ASC LIMIT 200", "i", array($cid))['rows'];

    if ($isOwner) {
        $pending = db_run($conn, $sqlBase . "'pending' ORDER BY m.joined_at ASC", "i", array($cid))['rows'];
    }
}

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

$n = count($members);
$creator = $club ? trim(($club['creator_first'] ?? '') . ' ' . ($club['creator_last'] ?? '')) : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo $club ? e($club['name']) . ' | ' : ''; ?>Club | Camigo</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:wght@600;700;800&family=Instrument+Sans:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
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

        <p class="crumb"><a href="clubs">&larr; All clubs</a></p>

        <section class="profile-card">
            <div class="cover"></div>
            <div class="profile-body">
                <div class="club-tile big t<?php echo (int)$club['id'] % 3; ?>"><?php echo e(first_letter($club['name'])); ?></div>

                <div class="p-head">
                    <div>
                        <h1 class="p-name"><?php echo e($club['name']); ?></h1>
                        <div class="p-user">
                            <span class="chip"><?php echo e($club['category']); ?></span>
                            <?php if ($isOwner) { ?><span class="badge approved">You're the owner</span><?php } ?>
                        </div>
                    </div>

                    <div class="top-actions">
                        <?php if ($isOwner) { ?>
                            <a href="edit-club?id=<?php echo (int)$club['id']; ?>" class="btn-sm btn-ghost">Edit club</a>
                        <?php } else { ?>
                            <form method="POST" class="foot-right">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="club_id" value="<?php echo (int)$club['id']; ?>">
                                <?php if ($isPending) { ?>
                                    <span class="badge pending">Request sent</span>
                                    <button type="submit" name="action" value="cancel" class="btn-sm btn-ghost">Cancel</button>
                                <?php } elseif ($isMember) { ?>
                                    <span class="badge approved">Member</span>
                                    <button type="submit" name="action" value="leave" class="btn-sm btn-ghost">Leave</button>
                                <?php } else { ?>
                                    <button type="submit" name="action" value="join" class="btn-sm btn-dark">Request to join</button>
                                <?php } ?>
                            </form>
                        <?php } ?>
                    </div>
                </div>

                <p class="p-meta">
                    <?php echo $n; ?> member<?php echo $n === 1 ? '' : 's'; ?>
                    &middot; Created <?php echo e(date('M j, Y', strtotime($club['created_at']))); ?>
                    <?php if ($creator !== '') { ?>&middot; by <?php echo e($creator); ?><?php } ?>
                </p>

                <p class="p-bio"><?php echo nl2br(e($club['description'])); ?></p>
            </div>
        </section>

        <?php if ($isOwner && $pending) { ?>
            <section class="panel">
                <h2>Join requests (<?php echo count($pending); ?>)</h2>
                <div class="club-list">
                    <?php foreach ($pending as $p) { $line = member_line($p); ?>
                        <div class="club-item">
                            <div class="req">
                                <div class="req-who">
                                    <div class="p-avatar tiny"><?php echo avatar_inner($p); ?></div>
                                    <div>
                                        <strong><?php echo e(trim($p['first_name'] . ' ' . ($p['last_name'] ?? ''))); ?></strong>
                                        <div class="club-meta">@<?php echo e($p['username']); ?><?php if ($line !== '') { ?> &middot; <?php echo e($line); ?><?php } ?></div>
                                    </div>
                                </div>
                                <form method="POST" class="req-actions">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="club_id" value="<?php echo (int)$club['id']; ?>">
                                    <input type="hidden" name="member_id" value="<?php echo (int)$p['mid']; ?>">
                                    <button type="submit" name="action" value="accept" class="btn-sm btn-approve">Accept</button>
                                    <button type="submit" name="action" value="decline" class="btn-sm btn-reject">Decline</button>
                                </form>
                            </div>
                        </div>
                    <?php } ?>
                </div>
            </section>
        <?php } ?>

        <section class="panel">
            <h2>Members (<?php echo $n; ?>)</h2>

            <?php if (!$members) { ?>
                <p class="empty">No members yet.</p>
            <?php } else { ?>
                <div class="member-list">
                    <?php foreach ($members as $m) {
                        $link = member_link($m, $me['username']);
                        $line = member_line($m);
                        $full = trim($m['first_name'] . ' ' . ($m['last_name'] ?? ''));
                    ?>
                        <div class="member">
                            <div class="p-avatar tiny"><?php echo avatar_inner($m); ?></div>
                            <div class="grow">
                                <strong>
                                    <?php if ($link !== '') { ?><a href="<?php echo e($link); ?>" class="plain"><?php echo e($full); ?></a><?php } else { echo e($full); } ?>
                                </strong>
                                <div class="club-meta">@<?php echo e($m['username']); ?><?php if ($line !== '') { ?> &middot; <?php echo e($line); ?><?php } ?></div>
                            </div>

                            <?php if ($m['role'] === 'owner') { ?>
                                <span class="badge approved">Owner</span>
                            <?php } elseif ($isOwner) { ?>
                                <form method="POST" onsubmit="return confirm('Remove this member from the club?');">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="club_id" value="<?php echo (int)$club['id']; ?>">
                                    <input type="hidden" name="member_id" value="<?php echo (int)$m['mid']; ?>">
                                    <button type="submit" name="action" value="remove" class="btn-sm btn-ghost">Remove</button>
                                </form>
                            <?php } ?>
                        </div>
                    <?php } ?>
                </div>
            <?php } ?>
        </section>

    <?php } ?>

</main>

</body>
</html>