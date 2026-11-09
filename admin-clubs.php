<?php
session_start();
require("db.php");
require("auth.php");

$me = require_admin($conn);

// ---- approve / reject ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $id     = (int)($_POST['club_id'] ?? 0);
    $action = $_POST['action'] ?? '';

    // needed to tell the creator what happened
    $cbRows = ($id > 0) ? db_run($conn, "SELECT name, created_by FROM clubs WHERE id = ? LIMIT 1", "i", array($id))['rows'] : array();
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
    "SELECT c.id, c.name, c.name_key, c.category, c.description, c.created_at, u.username, u.first_name, u.last_name
     FROM clubs c LEFT JOIN users u ON u.id = c.created_by
     WHERE c.status = 'pending' ORDER BY c.created_at ASC");

$tableMissing = !$pending['ok'];

$reviewed = db_run($conn,
    "SELECT name, category, status, review_note, reviewed_at FROM clubs
     WHERE status IN ('approved', 'rejected') ORDER BY reviewed_at DESC LIMIT 15");

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
</head>
<body>

<?php include("topbar.php"); ?>

<main class="main narrow">

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
        <h2>Recently reviewed</h2>
        <?php if (!$reviewed['rows']) { ?>
            <p class="empty">Nothing reviewed yet.</p>
        <?php } else { ?>
            <div class="club-list">
                <?php foreach ($reviewed['rows'] as $c) { ?>
                    <div class="club-item">
                        <div class="club-head">
                            <strong><?php echo e($c['name']); ?></strong>
                            <span class="badge <?php echo e($c['status']); ?>"><?php echo e(ucfirst($c['status'])); ?></span>
                        </div>
                        <div class="club-meta">
                            <?php echo e($c['category']); ?><?php if ($c['reviewed_at']) { ?> &middot; <?php echo e(date('M j, Y', strtotime($c['reviewed_at']))); ?><?php } ?>
                            <?php if ($c['review_note']) { ?> &middot; Note: <?php echo e($c['review_note']); ?><?php } ?>
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