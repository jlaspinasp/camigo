<?php
session_start();
require("db.php");
require("auth.php");
require("admin-lib.php");

$me = require_admin($conn);
$ready = verify_ready($conn);

// lower-case letters and digits only, so "Ana-Marie" and "ana marie" compare equal
function va_key($s) {
    $s = function_exists('mb_strtolower') ? mb_strtolower((string)$s, 'UTF-8') : strtolower((string)$s);
    return preg_replace('/[^\p{L}\p{N}]+/u', '', $s);
}

// ---- approve / reject / remove ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $ready) {

    $id     = (int)($_POST['vid'] ?? 0);
    $action = $_POST['action'] ?? '';
    $note   = str_cut(trim((string)($_POST['note'] ?? '')), 255);

    if (!csrf_ok() || $id <= 0) {
        flash_set("Your session expired. Please try again.", "error");
    } else {
        $rows = db_run($conn, "SELECT * FROM verifications WHERE id = ? LIMIT 1", "i", array($id))['rows'];
        $v = $rows ? $rows[0] : null;

        if (!$v) {
            flash_set("That request no longer exists.", "error");

        } elseif ($action === 'approve') {

            if ($v['status'] !== 'pending') {
                flash_set("That request was already reviewed.", "error");
            } else {
                $blocked = false;
                foreach (verify_duplicates($conn, $v['id_hash'], $v['user_id']) as $d) {
                    if ($d['status'] === 'approved') $blocked = true;
                }
                if ($blocked) {
                    flash_set("That ID number is already verified on another account. Reject this request, or remove the other verification first.", "error");
                } else {
                    $r = db_run($conn,
                        "UPDATE verifications SET status = 'approved', id_number = NULL, proof = NULL, proof2 = NULL, review_note = NULL, reviewed_by = ?, reviewed_at = NOW()
                         WHERE id = ? AND status = 'pending'",
                        "ii", array($me['id'], $id));
                    if ($r['ok'] && $r['affected'] > 0) {
                        // the name they typed and you approved becomes their permanent name (edit-profile locks it for verified accounts)
                        db_run($conn, "UPDATE users SET is_verified = 1, verified_at = NOW(), first_name = ?, last_name = ? WHERE id = ?",
                            "ssi", array($v['first_name'], $v['last_name'], $v['user_id']));
                        delete_proof($v['proof']);
                        delete_proof($v['proof2'] ?? '');
                        notify($conn, $v['user_id'], 'verify_approved',
                            "You're verified. You can now create clubs, events and projects.", 'verify-account');
                        flash_set("Account verified.", "success");
                    } else {
                        flash_set("That request was already reviewed.", "error");
                    }
                }
            }

        } elseif ($action === 'reject') {

            $r = db_run($conn,
                "UPDATE verifications SET status = 'rejected', id_number = NULL, proof = NULL, proof2 = NULL, review_note = ?, reviewed_by = ?, reviewed_at = NOW()
                 WHERE id = ? AND status = 'pending'",
                "sii", array($note, $me['id'], $id));
            if ($r['ok'] && $r['affected'] > 0) {
                delete_proof($v['proof']);
                        delete_proof($v['proof2'] ?? '');
                notify($conn, $v['user_id'], 'verify_rejected',
                    "Your verification request wasn't approved." . ($note !== '' ? ' Reason: ' . $note : '') . ' You can send a new one after ' . VERIFY_WAIT_HOURS . ' hours.',
                    'verify-account');
                flash_set("Request rejected.", "success");
            } else {
                flash_set("That request was already reviewed.", "error");
            }

        } elseif ($action === 'revoke') {

            if ($v['status'] !== 'approved') {
                flash_set("That account isn't verified.", "error");
            } else {
                $r = db_run($conn,
                    "UPDATE verifications SET status = 'revoked', review_note = ?, reviewed_by = ?, reviewed_at = NOW()
                     WHERE id = ? AND status = 'approved'",
                    "sii", array($note, $me['id'], $id));
                if ($r['ok'] && $r['affected'] > 0) {
                    db_run($conn, "UPDATE users SET is_verified = 0, verified_at = NULL WHERE id = ?", "i", array($v['user_id']));
                    notify($conn, $v['user_id'], 'verify_revoked',
                        "Your verification was removed." . ($note !== '' ? ' Reason: ' . $note : ''), 'verify-account');
                    flash_set("Verification removed.", "success");
                } else {
                    flash_set("That account isn't verified.", "error");
                }
            }
        }
    }

    // go back to the page the admin was on (only our own admin pages are allowed)
    $back = (string)($_POST['back'] ?? '');
    $dest = preg_match('/^admin-(verifications|verified)(\?[A-Za-z0-9=&%_.+\-]*)?$/', $back) ? $back : 'admin-verifications';
    header("Location: " . $dest);
    exit();
}

$message = "";
$message_class = "";
$flash = flash_get();
if ($flash) {
    $message = $flash[0];
    $message_class = $flash[1];
}

$q = str_cut(trim((string)($_GET['q'] ?? '')), 60);
$pending = array();
$verifiedTotal = 0;
$found = array('rows' => array(), 'total' => 0);
if ($ready) {
    $pending = db_run($conn,
        "SELECT v.*, u.username, u.first_name AS p_first, u.last_name AS p_last, u.course, u.year_level
         FROM verifications v LEFT JOIN users u ON u.id = v.user_id
         WHERE v.status = 'pending' ORDER BY v.created_at ASC")['rows'];

    $verifiedTotal = admin_verified_search($conn, '', 0, 0)['total'];
    if ($q !== '') $found = admin_verified_search($conn, $q, ADMIN_PEEK, 0);
}

$proofLabels = array('id' => 'School ID (front)', 'enrollment' => 'Study load', 'both' => 'School ID + Study load');
$adminActive = 'verifications';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Verifications | Camigo</title>
    <link rel="icon" href="favicon.svg" type="image/svg+xml">
    <link rel="icon" href="favicon.ico" sizes="any">
    <link rel="apple-touch-icon" href="apple-touch-icon.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:wght@600;700;800&family=Instrument+Sans:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
    <script src="confirm.js?v=<?php echo (int)@filemtime(__DIR__ . '/confirm.js'); ?>" defer></script>
    <link rel="stylesheet" href="verify.css">
</head>
<body>

<?php include("topbar.php"); ?>

<main class="main narrow">

    <?php include("admin-nav.php"); ?>

    <h1 class="page-title">Verification requests</h1>
    <p class="page-sub">Compare the photo with the name and ID number the student typed, then approve or reject. The photo is deleted as soon as you decide.</p>

    <?php if ($message != "") { ?>
        <div class="msg <?php echo e($message_class); ?>" style="margin-top:20px"><?php echo e($message); ?></div>
    <?php } ?>

    <?php if (!$ready) { ?>
        <div class="msg error" style="margin-top:20px">Verification isn't set up yet. Run <strong>verification.sql</strong> in phpMyAdmin first.</div>
    <?php } else { ?>

    <section class="panel hf-flat">
        <h2>Waiting for review (<?php echo count($pending); ?>)</h2>

        <?php if (!$pending) { ?>
            <p class="empty">No requests right now.</p>
        <?php } else { ?>
            <div class="club-list">
                <?php foreach ($pending as $v) {
                    $typedName = trim($v['first_name'] . ' ' . $v['last_name']);
                    $profName  = trim(($v['p_first'] ?? '') . ' ' . ($v['p_last'] ?? ''));
                    $nameMatch = (va_key($v['first_name']) === va_key($v['p_first'] ?? '') && va_key($v['last_name']) === va_key($v['p_last'] ?? ''));

                    $dups = verify_duplicates($conn, $v['id_hash'], $v['user_id']);
                    $dupApproved = array();
                    $dupPending  = array();
                    foreach ($dups as $d) {
                        if ($d['status'] === 'approved') $dupApproved[] = $d; else $dupPending[] = $d;
                    }

                    $courseLine = array();
                    if (!empty($v['course'])) $courseLine[] = $v['course'];
                    if (!empty($v['year_level'])) $courseLine[] = $v['year_level'];
                ?>
                    <article class="va-item">
                        <div class="va-thumbs">
                            <?php foreach (array(1 => array('proof', 'School ID'), 2 => array('proof2', 'Study load')) as $n => $pp) {
                                if (proof_ok($v[$pp[0]] ?? '')) { ?>
                                <a class="va-thumb" href="verification-proof?id=<?php echo (int)$v['id']; ?>&amp;n=<?php echo $n; ?>" target="_blank" rel="noopener">
                                    <img src="verification-proof?id=<?php echo (int)$v['id']; ?>&amp;n=<?php echo $n; ?>" alt="<?php echo e($pp[1]); ?> sent by <?php echo e($typedName); ?>">
                                    <span><?php echo e($pp[1]); ?> &middot; open full size</span>
                                </a>
                            <?php } else { ?>
                                <div class="va-gone"><?php echo e($pp[1]); ?> photo is missing. Reject this request so the student can send it again.</div>
                            <?php } } ?>
                        </div>

                        <div class="va-body">
                            <div class="va-head">
                                <div>
                                    <strong><?php
                                        $shownName = $profName !== '' ? $profName : $typedName;
                                        if (!empty($v['username'])) { ?><a href="profile?u=<?php echo e(urlencode($v['username'])); ?>"><?php echo e($shownName); ?></a><?php } else { echo e($shownName); }
                                    ?></strong>
                                    <small>@<?php echo e($v['username'] ?? 'unknown'); ?> &middot; sent <?php echo e(date('M j, Y', strtotime($v['created_at']))); ?></small>
                                </div>
                                <span class="badge pending">Pending</span>
                            </div>

                            <dl class="va-dl">
                                <dt>ID number</dt>
                                <dd><span class="va-id"><?php echo e($v['id_number'] ?? ''); ?></span></dd>

                                <dt>Name sent</dt>
                                <dd>
                                    <?php echo e($typedName); ?>
                                    <?php if ($nameMatch) { ?><span class="va-chip ok">Matches profile</span>
                                    <?php } else { ?><span class="va-chip warn">Differs from profile</span><?php } ?>
                                </dd>

                                <dt>Proof</dt>
                                <dd><?php echo e($proofLabels[$v['proof_type']] ?? 'Photo'); ?></dd>

                                <?php if ($courseLine) { ?>
                                    <dt>Profile says</dt>
                                    <dd><?php echo e(implode(' · ', $courseLine)); ?></dd>
                                <?php } ?>
                            </dl>

                            <?php if ($dupApproved) { ?>
                                <div class="va-dup">
                                    <strong>This ID number is already verified on another account:</strong>
                                    <?php foreach ($dupApproved as $i => $d) { echo ($i ? ', ' : ' ') . '@' . e($d['username']) . ' (' . e(trim($d['first_name'] . ' ' . $d['last_name'])) . ')'; } ?>.
                                    You can't approve this request unless that verification is removed first.
                                </div>
                            <?php } ?>
                            <?php if ($dupPending) { ?>
                                <div class="va-dup soft">
                                    <strong>Another request is waiting with the same ID number:</strong>
                                    <?php foreach ($dupPending as $i => $d) { echo ($i ? ', ' : ' ') . '@' . e($d['username']); } ?>.
                                </div>
                            <?php } ?>

                            <form method="POST" class="actions">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="vid" value="<?php echo (int)$v['id']; ?>">
                                <input type="text" name="note" maxlength="255" placeholder="Reason if rejecting (optional)">
                                <button type="submit" name="action" value="approve" class="btn-sm btn-approve"<?php echo $dupApproved ? ' disabled title="This ID number is already verified on another account"' : ''; ?>>Approve</button>
                                <button type="submit" name="action" value="reject" class="btn-sm btn-reject">Reject</button>
                            </form>
                        </div>
                    </article>
                <?php } ?>
            </div>
        <?php } ?>
    </section>

    <section class="panel hf-flat">
        <div class="va-panel-head">
            <h2>Verified students (<?php echo (int)$verifiedTotal; ?>)</h2>
            <a href="admin-verified" class="btn-sm btn-ghost">View all verified students</a>
        </div>

        <?php echo admin_search_form('admin-verifications', $q, 'Search by name, username or ID number', 'Search verified students'); ?>

        <?php if ($q === '') { ?>
            <p class="empty">Search to find a verified student, or open the full list.</p>
        <?php } else {
            $backSearch = 'admin-verifications?' . http_build_query(array('q' => $q)); ?>
            <div class="va-bar">
                <span class="dir-count"><?php echo (int)$found['total']; ?> <?php echo $found['total'] === 1 ? 'match' : 'matches'; ?> for &ldquo;<?php echo e($q); ?>&rdquo;</span>
                <a href="admin-verifications" class="va-clear">Clear search</a>
            </div>
            <?php if (!$found['rows']) { ?>
                <p class="empty">No verified student matches that search.</p>
            <?php } else { ?>
                <div class="club-list">
                    <?php foreach ($found['rows'] as $s) { echo admin_verified_row($s, $backSearch); } ?>
                </div>
                <?php if ($found['total'] > ADMIN_PEEK) { ?>
                    <p class="va-more">Showing the first <?php echo ADMIN_PEEK; ?>. <a href="admin-verified?<?php echo e(http_build_query(array('q' => $q))); ?>">See all <?php echo (int)$found['total']; ?> matches</a></p>
                <?php } ?>
            <?php } ?>
        <?php } ?>
    </section>

    <?php } ?>

</main>

</body>
</html>