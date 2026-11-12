<?php
session_start();
require("db.php");
require("auth.php");
require("admin-lib.php");

$me = require_admin($conn);

// ---- delete a club ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    register_shutdown_function('chat_club_sync_quiet', $conn);   // removes the club's group chat

    $id     = (int)($_POST['club_id'] ?? 0);
    $action = $_POST['action'] ?? '';
    $note   = str_cut(trim((string)($_POST['note'] ?? '')), 200);

    if (!csrf_ok() || $id <= 0 || $action !== 'delete') {
        flash_set("Your session expired. Please try again.", "error");
    } else {
        $cbRows = db_run($conn, "SELECT * FROM clubs WHERE id = ? LIMIT 1", "i", array($id))['rows'];
        $cb = $cbRows ? $cbRows[0] : null;

        if (!$cb) {
            flash_set("That club no longer exists.", "error");
        } else {
            if (club_delete_everything($conn, $cb, 'an admin', $note)) {
                flash_set("Club deleted.", "success");
            } else {
                flash_set("Couldn't delete that club. Please try again.", "error");
            }
        }
    }

    $back = (string)($_POST['back'] ?? '');
    $dest = preg_match('/^admin-(club-list|clubs)(\?[A-Za-z0-9=&%_.+\-]*)?$/', $back) ? $back : 'admin-club-list';
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

$q      = str_cut(trim((string)($_GET['q'] ?? '')), 60);
$page   = admin_page_param();

$total = admin_clubs_search($conn, $q, 'all', 0, 0)['total'];
$pages = max(1, (int)ceil($total / ADMIN_PER_PAGE));
if ($page > $pages) $page = $pages;
$rows  = admin_clubs_search($conn, $q, 'all', ADMIN_PER_PAGE, ($page - 1) * ADMIN_PER_PAGE)['rows'];

$keep = ($q !== '') ? array('q' => $q) : array();
$backParams = $keep;
if ($page > 1) $backParams['page'] = $page;
$back = 'admin-club-list' . ($backParams ? '?' . http_build_query($backParams) : '');

$adminActive = 'clubs';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>All clubs | Camigo</title>
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

    <a href="admin-clubs" class="va-back">&larr; Back to requests</a>
    <h1 class="page-title">All clubs</h1>
    <p class="page-sub">Every approved and rejected club, A to Z. Search by club name, category, or who requested it.</p>

    <?php if ($message != "") { ?>
        <div class="msg <?php echo e($message_class); ?>" style="margin-top:20px"><?php echo e($message); ?></div>
    <?php } ?>

    <section class="panel">
        <?php echo admin_search_form('admin-club-list', $q, 'Search by club, category or requester', 'Search clubs'); ?>

        <div class="va-bar">
            <span class="dir-count">
                <?php if ($q !== '') { ?>
                    <?php echo (int)$total; ?> <?php echo $total === 1 ? 'club' : 'clubs'; ?> match &ldquo;<?php echo e($q); ?>&rdquo;
                <?php } else { ?>
                    <?php echo (int)$total; ?> <?php echo $total === 1 ? 'club' : 'clubs'; ?>
                <?php } ?>
            </span>
            <?php if ($q !== '') { ?><a href="admin-club-list" class="va-clear">Clear search</a><?php } ?>
        </div>

        <?php if (!$rows) { ?>
            <p class="empty"><?php echo $q !== '' ? 'No club matches that search.' : 'No clubs have been reviewed yet.'; ?></p>
        <?php } else { ?>
            <div class="club-list">
                <?php foreach ($rows as $c) { echo admin_club_row($c, $back); } ?>
            </div>
            <?php echo admin_pager('admin-club-list', $keep, $page, $pages); ?>
        <?php } ?>
    </section>

</main>

</body>
</html>