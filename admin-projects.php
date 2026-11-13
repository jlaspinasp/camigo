<?php
session_start();
require("db.php");
require("auth.php");
require("events-lib.php");
require("projects-lib.php");
require("admin-lib.php");

$me = require_admin($conn);

$message = "";
$message_class = "";
$flash = flash_get();
if ($flash) {
    $message = $flash[0];
    $message_class = $flash[1];
}

$q = str_cut(trim((string)($_GET['q'] ?? '')), 60);
$probe = db_run($conn, "SELECT COUNT(*) AS n FROM projects");
$tableMissing = !$probe['ok'];

$total = $tableMissing ? 0 : admin_projects_search($conn, '', 0, 0)['total'];
$found = ($q !== '' && !$tableMissing) ? admin_projects_search($conn, $q, ADMIN_PEEK, 0) : array('rows' => array(), 'total' => 0);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Projects | Camigo</title>
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

    <?php $adminActive = 'projects'; include("admin-nav.php"); ?>

    <h1 class="page-title">Projects</h1>
    <p class="page-sub">Every project posted on Camigo. Projects don't need review, but you can find one and delete it if needed.</p>

    <?php if ($message != "") { ?>
        <div class="msg <?php echo e($message_class); ?>" style="margin-top:20px"><?php echo e($message); ?></div>
    <?php } ?>

    <?php if ($tableMissing) { ?>
        <div class="msg error" style="margin-top:20px">The projects table doesn't exist yet.</div>
    <?php } else { ?>

    <section class="panel">
        <div class="va-panel-head">
            <h2>All projects (<?php echo (int)$total; ?>)</h2>
            <a href="admin-project-list" class="btn-sm btn-ghost">View all projects</a>
        </div>

        <?php echo admin_search_form('admin-projects', $q, 'Search by project, category or founder', 'Search projects'); ?>

        <?php if ($q === '') { ?>
            <p class="empty">Search to find a project, or open the full list.</p>
        <?php } else { ?>
            <div class="va-bar">
                <span class="dir-count"><?php echo (int)$found['total']; ?> <?php echo $found['total'] === 1 ? 'project' : 'projects'; ?> match &ldquo;<?php echo e($q); ?>&rdquo;</span>
                <a href="admin-projects" class="va-clear">Clear search</a>
            </div>
            <?php $backSearch = 'admin-projects?' . http_build_query(array('q' => $q)); ?>
            <?php if (!$found['rows']) { ?>
                <p class="empty">No project matches that search.</p>
            <?php } else { ?>
                <div class="club-list">
                    <?php foreach ($found['rows'] as $row) { echo admin_project_row($row, $backSearch); } ?>
                </div>
                <?php if ($found['total'] > ADMIN_PEEK) { ?>
                    <p class="va-more">Showing the first <?php echo ADMIN_PEEK; ?>. <a href="admin-project-list?<?php echo e(http_build_query(array('q' => $q))); ?>">See all <?php echo (int)$found['total']; ?> matches</a></p>
                <?php } ?>
            <?php } ?>
        <?php } ?>
    </section>

    <?php } ?>

</main>

</body>
</html>