<?php
session_start();
require("db.php");
require("auth.php");
require("events-lib.php");
require("feed-lib.php");

$me = require_user_row($conn);
if ($_SERVER['REQUEST_METHOD'] === 'POST') register_shutdown_function('chat_club_sync_quiet', $conn);


$categories = club_categories();

// ---- request to join / cancel / leave ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $id     = (int)($_POST['club_id'] ?? 0);
    $action = $_POST['action'] ?? '';

    if (!csrf_ok() || $id <= 0) {
        flash_set("Your session expired. Please try again.", "error");

    } elseif ($action === 'join' && club_is_invite_only($conn, $id)) {
        flash_set("That club is invite-only. Ask a member for an invite link.", "error");

    } elseif ($action === 'join') {

        // joining is a request: it stays 'pending' until the club owner accepts it
        $r = db_run($conn,
            "INSERT IGNORE INTO club_members (club_id, user_id, role, status)
             SELECT id, ?, 'member', 'pending' FROM clubs WHERE id = ? AND status = 'approved'",
            "ii", array($me['id'], $id));
        if ($r['ok'] && $r['affected'] > 0) {
            notify_join_request($conn, $id, $me);
            flash_set("Request sent. The club owner will review it.", "success");
        } elseif ($r['ok']) {
            flash_set("You've already requested or joined that club.", "error");
        } else {
            flash_set("Joining clubs isn't set up yet. Please try again later.", "error");
        }

    } elseif ($action === 'cancel') {

        $r = db_run($conn,
            "DELETE FROM club_members WHERE club_id = ? AND user_id = ? AND role = 'member' AND status = 'pending'",
            "ii", array($id, $me['id']));
        flash_set(($r['ok'] && $r['affected'] > 0) ? "Request cancelled." : "There was no request to cancel.",
                  ($r['ok'] && $r['affected'] > 0) ? "success" : "error");

    } elseif ($action === 'leave') {

        // owners can't leave their own club (officers and members can)
        $r = db_run($conn,
            "DELETE FROM club_members WHERE club_id = ? AND user_id = ? AND role IN ('member', 'officer') AND status = 'active'",
            "ii", array($id, $me['id']));
        flash_set(($r['ok'] && $r['affected'] > 0) ? "You left the club." : "You can't leave that club.",
                  ($r['ok'] && $r['affected'] > 0) ? "success" : "error");
    }

    // go back to the same search / filter
    $back = array();
    if (!empty($_POST['q']))    $back['q'] = $_POST['q'];
    if (!empty($_POST['cat']))  $back['cat'] = $_POST['cat'];
    if (!empty($_POST['mine'])) $back['mine'] = 1;
    header("Location: clubs" . ($back ? "?" . http_build_query($back) : ""));
    exit();
}

// ---- search / filter ----
$q    = trim($_GET['q'] ?? '');
$cat  = $_GET['cat'] ?? '';
$mine = !empty($_GET['mine']);
if (!in_array($cat, $categories, true)) $cat = '';

$sql = "SELECT c.*,
            (SELECT COUNT(*) FROM club_members m WHERE m.club_id = c.id AND m.status = 'active') AS members,
            (SELECT COUNT(*) FROM club_members p WHERE p.club_id = c.id AND p.status = 'pending') AS pending_n,
            mine.role AS my_role, mine.status AS my_status
        FROM clubs c
        LEFT JOIN club_members mine ON mine.club_id = c.id AND mine.user_id = ?
        WHERE c.status = 'approved'";
$types  = "i";
$params = array($me['id']);

if ($cat !== '') {
    $sql .= " AND c.category = ?";
    $types .= "s";
    $params[] = $cat;
}
if ($q !== '') {
    $like = '%' . addcslashes($q, '%_\\') . '%';
    $sql .= " AND (c.name LIKE ? OR c.description LIKE ?)";
    $types .= "ss";
    $params[] = $like;
    $params[] = $like;
}
if ($mine) {
    $sql .= " AND mine.status = 'active'";
}
$sql .= " ORDER BY c.name ASC LIMIT 100";

$res = db_run($conn, $sql, $types, $params);
$tableMissing = !$res['ok'];
$clubs = $res['rows'];

// join requests waiting for this person to review (clubs they own)
$reqCount = 0;
$pn = db_run($conn,
    "SELECT COUNT(*) AS n FROM club_members m
     JOIN club_members o ON o.club_id = m.club_id AND o.user_id = ? AND o.role = 'owner'
     JOIN clubs c ON c.id = m.club_id
     WHERE m.status = 'pending' AND c.status = 'approved'",
    "i", array($me['id']));
if ($pn['ok'] && $pn['rows']) {
    $reqCount = (int)$pn['rows'][0]['n'];
}

$flash = flash_get();
$message = $flash ? $flash[0] : "";
$message_class = $flash ? $flash[1] : "";

$filtered = ($q !== '' || $cat !== '');

// Cover photo (or a colour gradient when the club has none).
function club_media_html($c, $class) {
    $img = !empty($c['cover']) && club_image_ok($c['cover']);
    $h = '<div class="' . $class . ($img ? ' has-img' : '') . '" data-g="' . ((int)$c['id'] % 6) . '">';
    if ($img) {
        $h .= '<img src="uploads/clubs/' . e($c['cover']) . '" alt="" loading="lazy" decoding="async">';
    }
    return $h . '</div>';
}

// ---- featured club: the same daily pick the dashboard shows (rail_featured) ----
// Only on the plain "All clubs" view. Its cover and details come from the list below.
$featured = null;
$gridClubs = $clubs;
if (!$tableMissing && !$filtered && !$mine) {
    $pick = rail_featured($conn, $me['id']);
    if ($pick) {
        foreach ($clubs as $fc) {
            if ((int)$fc['id'] === (int)$pick['id']) {
                $featured = $fc;
                break;
            }
        }
    }
    if ($featured) {
        $fid = (int)$featured['id'];
        $gridClubs = array_values(array_filter($clubs, function ($gc) use ($fid) { return (int)$gc['id'] !== $fid; }));
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Clubs | Camigo</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:wght@600;700;800&family=Instrument+Sans:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
</head>
<body>

<?php include("topbar.php"); ?>

<main class="main">

    <div class="page-top">
        <div>
            <h1 class="page-title">Clubs</h1>
            <p class="page-sub">Find your people. Request to join the clubs that fit you.</p>
        </div>
        <div class="top-actions">
            <?php if ($reqCount > 0) { ?>
                <a href="club-requests" class="create-link join-link">Join requests <span class="count"><?php echo $reqCount; ?></span></a>
            <?php } ?>
            <a href="create-club" class="create-link">Create a club</a>
        </div>
    </div>

    <?php if ($message != "") { ?>
        <div class="msg <?php echo e($message_class); ?>" style="margin-top:20px"><?php echo e($message); ?></div>
    <?php } ?>

    <?php if ($featured) {
        $f = $featured;
        $fn = (int)$f['members'];
        $fOwner   = ($f['my_role'] === 'owner');
        $fPending = ($f['my_status'] === 'pending');
        $fMember  = (($f['my_role'] === 'member' || $f['my_role'] === 'officer') && $f['my_status'] === 'active');
        $fJoin    = (!$fOwner && !$fPending && !$fMember && empty($f['invite_only']));
    ?>
        <section class="cf" aria-label="Featured club">
            <?php echo club_media_html($f, 'cf-media'); ?>
            <div class="cf-body">
                <span class="cf-flag"><?php echo event_icon('star'); ?>Featured today</span>
                <div class="cf-id">
                    <?php echo club_tile_html($f, 'cf-logo'); ?>
                    <div class="cf-titles">
                        <h2 class="cf-name"><a href="club?id=<?php echo (int)$f['id']; ?>"><?php echo e($f['name']); ?></a></h2>
                        <div class="cf-meta">
                            <span class="cf-cat"><?php echo e($f['category']); ?></span>
                            <span class="cf-count"><?php echo event_icon('users'); ?><?php echo $fn; ?> member<?php echo $fn === 1 ? '' : 's'; ?></span>
                        </div>
                    </div>
                </div>
                <?php if (trim((string)$f['description']) !== '') { ?><p class="cf-desc"><?php echo e($f['description']); ?></p><?php } ?>
                <div class="cf-actions">
                    <?php if ($fOwner) { ?><span class="cf-state">You own this club</span>
                    <?php } elseif ($fMember || $fPending) { ?>
                        <form method="POST" class="text-form">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="club_id" value="<?php echo (int)$f['id']; ?>">
                            <span class="cf-state"><?php echo $fMember ? "You're a member" : "Request sent"; ?></span>
                            <button type="submit" name="action" value="<?php echo $fMember ? 'leave' : 'cancel'; ?>" class="text-action on-dark"><?php echo $fMember ? 'Leave' : 'Cancel'; ?></button>
                        </form>
                    <?php } elseif ($fJoin) { ?>
                        <form method="POST" class="text-form">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="club_id" value="<?php echo (int)$f['id']; ?>">
                            <button type="submit" name="action" value="join" class="text-action on-dark">Request to join</button>
                        </form>
                    <?php } else { ?><span class="cf-state">Invite only</span><?php } ?>
                </div>
            </div>
        </section>
    <?php } ?>



    <form method="GET" class="filters">
        <?php if ($mine) { ?><input type="hidden" name="mine" value="1"><?php } ?>
        <input type="text" name="q" maxlength="60" placeholder="Search clubs" value="<?php echo e($q); ?>">
        <select name="cat">
            <option value="">All categories</option>
            <?php foreach ($categories as $c) { ?>
                <option value="<?php echo e($c); ?>" <?php echo ($cat === $c) ? 'selected' : ''; ?>><?php echo e($c); ?></option>
            <?php } ?>
        </select>
        <button type="submit" class="btn-sm btn-dark">Search</button>
        <?php if ($filtered) { ?><a href="clubs<?php echo $mine ? '?mine=1' : ''; ?>" class="cancel">Clear</a><?php } ?>
    </form>

    <div class="tabs">
        <a href="clubs" class="<?php echo $mine ? '' : 'active'; ?>">All clubs</a>
        <a href="clubs?mine=1" class="<?php echo $mine ? 'active' : ''; ?>">My clubs</a>
    </div>

    <?php if ($tableMissing) { ?>

        <div class="msg error">Clubs aren't set up yet. Run <strong>clubs.sql</strong> and <strong>clubs-members.sql</strong> in phpMyAdmin. If you ran <strong>clubs-members.sql</strong> before, also run <strong>members-update.sql</strong>.</div>

    <?php } elseif (!$clubs) { ?>

        <section class="panel center">
            <h2><?php echo $filtered ? "No clubs match your search" : ($mine ? "You haven't joined any clubs yet" : "No clubs yet"); ?></h2>
            <p class="empty">
                <?php if ($filtered) { ?>Try a different word or category.
                <?php } elseif ($mine) { ?><a href="clubs">Browse all clubs</a> to find one to join.
                <?php } else { ?>Be the first, <a href="create-club">create a club</a>.<?php } ?>
            </p>
        </section>

    <?php } else { ?>

        <?php if ($gridClubs) { ?>
        <div class="cc-grid">
            <?php foreach ($gridClubs as $c) {
                $n = (int)$c['members'];
                $isOwner   = ($c['my_role'] === 'owner');
                $isPending = ($c['my_status'] === 'pending');
                $isOfficer = ($c['my_role'] === 'officer' && $c['my_status'] === 'active');
                $isMember  = (($c['my_role'] === 'member' || $isOfficer) && $c['my_status'] === 'active');
            ?>
                <article class="cc-card">
                    <div class="cc-top">
                        <?php echo club_media_html($c, 'cc-media'); ?>
                    </div>

                    <div class="cc-body">
                        <?php echo club_tile_html($c, 'cc-logo'); ?>
                        <div class="cc-head">
                            <h3><a href="club?id=<?php echo (int)$c['id']; ?>"><?php echo e($c['name']); ?></a></h3>
                            <span class="chip"><?php echo e($c['category']); ?></span>
                        </div>

                        <p class="club-text"><?php echo e($c['description']); ?></p>

                        <div class="club-foot">
                            <span class="club-meta cc-count"><?php echo event_icon('users'); ?><?php echo $n; ?> member<?php echo $n === 1 ? '' : 's'; ?></span>

                            <?php if ($isOwner) { ?>
                                <div class="foot-right">
                                    <?php if ((int)$c['pending_n'] > 0) { ?>
                                        <a href="club-requests" class="text-action"><?php echo (int)$c['pending_n']; ?> request<?php echo ((int)$c['pending_n'] === 1) ? '' : 's'; ?></a>
                                    <?php } ?>
                                    <span class="status-text status-role">Owner</span>
                                </div>
                            <?php } else { ?>
                                <form method="POST" class="foot-right">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="club_id" value="<?php echo (int)$c['id']; ?>">
                                    <input type="hidden" name="q" value="<?php echo e($q); ?>">
                                    <input type="hidden" name="cat" value="<?php echo e($cat); ?>">
                                    <?php if ($mine) { ?><input type="hidden" name="mine" value="1"><?php } ?>

                                    <?php if ($isPending) { ?>
                                        <span class="status-text status-sent">Request sent</span>
                                        <button type="submit" name="action" value="cancel" class="text-action">Cancel</button>
                                    <?php } elseif ($isMember) { ?>
                                        <?php if ($isOfficer) { ?><span class="status-text status-role">Officer</span><?php } ?>
                                        <button type="submit" name="action" value="leave" class="text-action">Leave</button>
                                    <?php } else { ?>
                                        <?php if (!empty($c['invite_only'])) { ?>
                                            <span class="club-meta">Ask a member for an invite</span>
                                        <?php } else { ?>
                                            <button type="submit" name="action" value="join" class="text-action">Request to join</button>
                                        <?php } ?>
                                    <?php } ?>
                                </form>
                            <?php } ?>
                        </div>
                    </div>
                </article>
            <?php } ?>
        </div>
        <?php } ?>

    <?php } ?>

</main>

</body>
</html>