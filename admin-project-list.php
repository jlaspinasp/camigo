<?php
session_start();
require("db.php");
require("auth.php");
require("events-lib.php");
require("projects-lib.php");
require("admin-lib.php");

$me = require_admin($conn);

// ---- delete project ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id     = (int)($_POST['project_id'] ?? 0);
    $action = $_POST['action'] ?? '';
    $note   = str_cut(trim((string)($_POST['note'] ?? '')), 200);

    if (!csrf_ok() || $id <= 0 || $action !== 'delete') {
        flash_set("Your session expired. Please try again.", "error");
    } else {
        $rows = db_run($conn, "SELECT id, title FROM projects WHERE id = ? LIMIT 1", "i", array($id))['rows'];
        $p = $rows ? $rows[0] : null;

        if (!$p) {
            flash_set("That project no longer exists.", "error");
        } else {
            // tell the team before the rows go (the founder is on the team too)
            project_notify_team($conn, $id, 0, 'project_removed',
                'The project "' . $p['title'] . '" was removed by an admin.' . ($note !== '' ? ' Reason: ' . $note : ''), 'projects');

            $banner = project_banner_of($conn, $id);   // read before the row is gone
            $logo   = project_logo_of($conn, $id);

            // children first, then the project itself
            db_run($conn, "DELETE FROM project_updates WHERE project_id = ?", "i", array($id));
            db_run($conn, "DELETE FROM project_members WHERE project_id = ?", "i", array($id));
            db_run($conn, "DELETE FROM project_roles WHERE project_id = ?", "i", array($id));
            db_run($conn, "DELETE FROM notifications WHERE link = ?", "s", array('project?id=' . $id));  // old links would lead nowhere
            $r = db_run($conn, "DELETE FROM projects WHERE id = ?", "i", array($id));
            if ($r['ok'] && $r['affected'] > 0 && $banner) project_banner_delete($banner);
            if ($r['ok'] && $r['affected'] > 0 && $logo)   project_logo_delete($logo);

            flash_set(($r['ok'] && $r['affected'] > 0) ? "Project deleted." : "Couldn't delete that project. Please try again.", ($r['ok'] && $r['affected'] > 0) ? "success" : "error");
        }
    }

    $back = (string)($_POST['back'] ?? '');
    $dest = preg_match('/^admin-(project-list|projects)(\?[A-Za-z0-9=&%_.+\-]*)?$/', $back) ? $back : 'admin-project-list';
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

$total = admin_projects_search($conn, $q, 0, 0)['total'];
$pages = max(1, (int)ceil($total / ADMIN_PER_PAGE));
if ($page > $pages) $page = $pages;
$rows  = admin_projects_search($conn, $q, ADMIN_PER_PAGE, ($page - 1) * ADMIN_PER_PAGE)['rows'];

$keep = ($q !== '') ? array('q' => $q) : array();
$backParams = $keep;
if ($page > 1) $backParams['page'] = $page;
$back = 'admin-project-list' . ($backParams ? '?' . http_build_query($backParams) : '');

$adminActive = 'projects';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>All projects | Camigo</title>
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

    <a href="admin-projects" class="va-back">&larr; Back to projects</a>
    <h1 class="page-title">All projects</h1>
    <p class="page-sub">Every project, A to Z. Search by title, category, or who started it.</p>

    <?php if ($message != "") { ?>
        <div class="msg <?php echo e($message_class); ?>" style="margin-top:20px"><?php echo e($message); ?></div>
    <?php } ?>

    <section class="panel">
        <?php echo admin_search_form('admin-project-list', $q, 'Search by project, category or founder', 'Search projects'); ?>

        <div class="va-bar">
            <span class="dir-count">
                <?php if ($q !== '') { ?>
                    <?php echo (int)$total; ?> <?php echo $total === 1 ? 'project' : 'projects'; ?> match &ldquo;<?php echo e($q); ?>&rdquo;
                <?php } else { ?>
                    <?php echo (int)$total; ?> <?php echo $total === 1 ? 'project' : 'projects'; ?>
                <?php } ?>
            </span>
            <?php if ($q !== '') { ?><a href="admin-project-list" class="va-clear">Clear search</a><?php } ?>
        </div>

        <?php if (!$rows) { ?>
            <p class="empty"><?php echo $q !== '' ? 'No project matches that search.' : 'No projects have been posted yet.'; ?></p>
        <?php } else { ?>
            <div class="club-list">
                <?php foreach ($rows as $row) { echo admin_project_row($row, $back); } ?>
            </div>
            <?php echo admin_pager('admin-project-list', $keep, $page, $pages); ?>
        <?php } ?>
    </section>

</main>

</body>
</html>