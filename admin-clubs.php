<?php
session_start();
require("db.php");
require("auth.php");
require("events-lib.php");
require("admin-lib.php");

$me = require_admin($conn);
if ($_SERVER['REQUEST_METHOD'] === 'POST') register_shutdown_function('chat_club_sync_quiet', $conn);


// ---- approve / reject ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $id     = (int)($_POST['club_id'] ?? 0);
    $action = $_POST['action'] ?? '';

    // needed to tell the creator what happened
    $cbRows = ($id > 0) ? db_run($conn, "SELECT * FROM clubs WHERE id = ? LIMIT 1", "i", array($id))['rows'] : array();
    $cb = $cbRows ? $cbRows[0] : null;

    if (!csrf_ok() || $id <= 0) {
        flash_set("Your session expired. Please try again.", "error");

    } elseif ($action === 'approve') {

        $r = db_run($conn,
            "UPDATE clubs SET status = 'approved', review_note = NULL, reviewed_by = ?, reviewed_at = NOW() WHERE id = ? AND status = 'pending'",
            "ii", array($me['id'], $id));
        if ($r['ok'] && $r['affected'] > 0) {
            // the person who created the club becomes its owner
            db_run($conn,
                "INSERT IGNORE INTO club_members (club_id, user_id, role) SELECT id, created_by, 'owner' FROM clubs WHERE id = ?",
                "i", array($id));
            if ($cb) {
                notify($conn, $cb['created_by'], 'club_approved',
                    'Your club "' . $cb['name'] . '" was approved. You are now its owner.', 'club?id=' . $id);
            }
            flash_set("Club approved.", "success");
        } else {
            flash_set("That request was already reviewed.", "error");
        }

    } elseif ($action === 'reject') {

        $note = trim($_POST['note'] ?? '');
        $note = function_exists('mb_substr') ? mb_substr($note, 0, 255, 'UTF-8') : substr($note, 0, 255);

        // name_key becomes NULL so the name can be requested again later
        $r = db_run($conn,
            "UPDATE clubs SET status = 'rejected', name_key = NULL, review_note = ?, reviewed_by = ?, reviewed_at = NOW() WHERE id = ? AND status = 'pending'",
            "sii", array($note, $me['id'], $id));
        if ($r['ok'] && $r['affected'] > 0) {
            if ($cb) {
                delete_club_image($cb['logo'] ?? null);
                delete_club_image($cb['cover'] ?? null);
                notify($conn, $cb['created_by'], 'club_rejected',
                    'Your club request "' . $cb['name'] . '" was not approved.' . ($note !== '' ? ' Reason: ' . $note : ''), 'create-club');
            }
            flash_set("Club rejected.", "success");
        } else {
            flash_set("That request was already reviewed.", "error");
        }
    }

    header("Location: admin-clubs");
    exit();
}

$message = "";
$message_class = "";
$flash = flash_get();
if ($flash) {
    $message = $flash[0];
    $message_class = $flash[1];
}

$pending = db_run($conn,
    "SELECT c.id, c.name, c.name_key, c.category, c.description, c.created_at" . (club_identity_ready($conn) ? ", c.logo, c.cover, c.invite_only" : "") . ", u.username, u.first_name, u.last_name
     FROM clubs c LEFT JOIN users u ON u.id = c.created_by
     WHERE c.status = 'pending' ORDER BY c.created_at ASC");

$tableMissing = !$pending['ok'];

$q = str_cut(trim((string)($_GET['q'] ?? '')), 60);
$clubTotal = $tableMissing ? 0 : admin_clubs_search($conn, '', 'all', 0, 0)['total'];
$found = ($q !== '' && !$tableMissing) ? admin_clubs_search($conn, $q, 'all', ADMIN_PEEK, 0) : array('rows' => array(), 'total' => 0);

// approved clubs with a similar name, to help the admin spot near-duplicates
function similar_clubs($conn, $id, $key) {
    if (strlen($key) < 3) return array();
    $r = db_run($conn,
        "SELECT name FROM clubs WHERE status = 'approved' AND id <> ?
         AND (name_key LIKE CONCAT('%', ?, '%') OR ? LIKE CONCAT('%', name_key, '%')) LIMIT 3",
        "iss", array($id, $key, $key));
    return $r['rows'];
}

$initial = strtoupper(substr($me['first_name'], 0, 1));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Club requests | Camigo</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:wght@600;700;800&family=Instrument+Sans:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="verify.css">
</head>
<body>

<?php include("topbar.php"); ?>

<main class="main narrow">

    <?php $adminActive = 'clubs'; include("admin-nav.php"); ?>

    <h1 class="page-title">Club requests</h1>
    <p class="page-sub">Review new clubs before they appear on Camigo. Check for duplicates, then approve or reject.</p>

    <?php if ($message != "") { ?>
        <div class="msg <?php echo e($message_class); ?>" style="margin-top:20px"><?php echo e($message); ?></div>
    <?php } ?>

    <?php if ($tableMissing) { ?>
        <div class="msg error" style="margin-top:20px">The clubs table doesn't exist yet. Run <strong>clubs.sql</strong> in phpMyAdmin first.</div>
    <?php } else { ?>

    <section class="panel">
        <h2>Waiting for review (<?php echo count($pending['rows']); ?>)</h2>

        <?php if (!$pending['rows']) { ?>
            <p class="empty">No requests right now.</p>
        <?php } else { ?>
            <div class="club-list">
                <?php foreach ($pending['rows'] as $c) {
                    $similar = similar_clubs($conn, $c['id'], (string)$c['name_key']);
                    $by = trim(($c['first_name'] ?? '') . ' ' . ($c['last_name'] ?? ''));
                ?>
                    <div class="club-item">
                        <div class="club-head">
                            <strong><?php echo e($c['name']); ?></strong>
                            <span class="badge pending">Pending</span>
                        </div>
                        <div class="club-meta">
                            <?php echo e($c['category']); ?> &middot;
                            requested by <?php echo e($by !== '' ? $by : 'unknown'); ?><?php if (!empty($c['username'])) { ?> (@<?php echo e($c['username']); ?>)<?php } ?> &middot;
                            <?php echo e(date('M j, Y', strtotime($c['created_at']))); ?>
                        </div>
                        <p class="club-desc"><?php echo nl2br(e($c['description'])); ?></p>

                        <?php if (!empty($c['cover']) || !empty($c['logo']) || !empty($c['invite_only'])) { ?>
                            <div style="margin:10px 0">
                                <?php if (club_image_ok($c['cover'] ?? '')) { ?><img src="uploads/clubs/<?php echo e($c['cover']); ?>" alt="Cover" style="display:block;width:100%;max-height:130px;object-fit:cover;border-radius:12px"><?php } ?>
                                <div style="display:flex;align-items:center;gap:10px;margin-top:8px">
                                    <?php if (club_image_ok($c['logo'] ?? '')) { ?><img src="uploads/clubs/<?php echo e($c['logo']); ?>" alt="Logo" style="width:48px;height:48px;object-fit:cover;border-radius:12px"><?php } ?>
                                    <?php if (!empty($c['invite_only'])) { ?><span class="badge invite"><?php echo event_icon('lock'); ?>Invite only</span><?php } ?>
                                </div>
                            </div>
                        <?php } ?>

                        <?php if ($similar) { ?>
                            <div class="similar">Similar approved clubs: <?php echo e(implode(", ", array_column($similar, 'name'))); ?></div>
                        <?php } ?>

                        <form method="POST" class="actions">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="club_id" value="<?php echo (int)$c['id']; ?>">
                            <input type="text" name="note" maxlength="255" placeholder="Reason if rejecting (optional)">
                            <button type="submit" name="action" value="approve" class="btn-sm btn-approve">Approve</button>
                            <button type="submit" name="action" value="reject" class="btn-sm btn-reject">Reject</button>
                        </form>
                    </div>
                <?php } ?>
            </div>
        <?php } ?>
    </section>

    <section class="panel">
        <div class="va-panel-head">
            <h2>Reviewed clubs (<?php echo (int)$clubTotal; ?>)</h2>
            <a href="admin-club-list" class="btn-sm btn-ghost">View all clubs</a>
        </div>

        <?php echo admin_search_form('admin-clubs', $q, 'Search by club, category or requester', 'Search clubs'); ?>

        <?php if ($q === '') { ?>
            <p class="empty">Search to find an approved or rejected club, or open the full list.</p>
        <?php } else { ?>
            <div class="va-bar">
                <span class="dir-count"><?php echo (int)$found['total']; ?> <?php echo $found['total'] === 1 ? 'club' : 'clubs'; ?> match &ldquo;<?php echo e($q); ?>&rdquo;</span>
                <a href="admin-clubs" class="va-clear">Clear search</a>
            </div>
            <?php $backSearch = 'admin-clubs?' . http_build_query(array('q' => $q)); ?>
            <?php if (!$found['rows']) { ?>
                <p class="empty">No club matches that search.</p>
            <?php } else { ?>
                <div class="club-list">
                    <?php foreach ($found['rows'] as $cl) { echo admin_club_row($cl, $backSearch); } ?>
                </div>
                <?php if ($found['total'] > ADMIN_PEEK) { ?>
                    <p class="va-more">Showing the first <?php echo ADMIN_PEEK; ?>. <a href="admin-club-list?<?php echo e(http_build_query(array('q' => $q))); ?>">See all <?php echo (int)$found['total']; ?> matches</a></p>
                <?php } ?>
            <?php } ?>
        <?php } ?>
    </section>

    <?php } ?>

</main>

</body>
</html>