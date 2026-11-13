<?php
session_start();
require("db.php");
require("auth.php");
require("admin-lib.php");

$me = require_admin($conn);
$ready = verify_ready($conn);

$q    = str_cut(trim((string)($_GET['q'] ?? '')), 60);
$page = admin_page_param();

$total = 0;
$rows  = array();
$pages = 1;
if ($ready) {
    $total = admin_verified_search($conn, $q, 0, 0)['total'];
    $pages = max(1, (int)ceil($total / ADMIN_PER_PAGE));
    if ($page > $pages) $page = $pages;
    $rows = admin_verified_search($conn, $q, ADMIN_PER_PAGE, ($page - 1) * ADMIN_PER_PAGE)['rows'];
}

$keep = ($q !== '') ? array('q' => $q) : array();
$backParams = $keep;
if ($page > 1) $backParams['page'] = $page;
$back = 'admin-verified' . ($backParams ? '?' . http_build_query($backParams) : '');

$message = "";
$message_class = "";
$flash = flash_get();
if ($flash) {
    $message = $flash[0];
    $message_class = $flash[1];
}

$adminActive = 'verifications';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Verified students | Camigo</title>
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

    <a href="admin-verifications" class="va-back">&larr; Back to requests</a>
    <h1 class="page-title">Verified students</h1>
    <p class="page-sub">Everyone who is verified, A to Z. Search by name, username or a full ID number.</p>

    <?php if ($message != "") { ?>
        <div class="msg <?php echo e($message_class); ?>" style="margin-top:20px"><?php echo e($message); ?></div>
    <?php } ?>

    <?php if (!$ready) { ?>
        <div class="msg error" style="margin-top:20px">Verification isn't set up yet. Run <strong>verification.sql</strong> in phpMyAdmin first.</div>
    <?php } else { ?>

    <section class="panel hf-flat">
        <?php echo admin_search_form('admin-verified', $q, 'Search by name, username or ID number', 'Search verified students'); ?>

        <div class="va-bar">
            <span class="dir-count">
                <?php if ($q !== '') { ?>
                    <?php echo (int)$total; ?> <?php echo $total === 1 ? 'match' : 'matches'; ?> for &ldquo;<?php echo e($q); ?>&rdquo;
                <?php } else { ?>
                    <?php echo (int)$total; ?> verified <?php echo $total === 1 ? 'student' : 'students'; ?>
                <?php } ?>
            </span>
            <?php if ($q !== '') { ?><a href="admin-verified" class="va-clear">Clear search</a><?php } ?>
        </div>

        <?php if (!$rows) { ?>
            <p class="empty"><?php echo $q !== '' ? 'No verified student matches that search.' : 'Nobody is verified yet.'; ?></p>
        <?php } else { ?>
            <div class="club-list">
                <?php foreach ($rows as $s) { echo admin_verified_row($s, $back); } ?>
            </div>
            <?php echo admin_pager('admin-verified', $keep, $page, $pages); ?>
        <?php } ?>
    </section>

    <?php } ?>

</main>

</body>
</html>