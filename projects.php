<?php
session_start();
require("db.php");
require("auth.php");
require_once("projects-lib.php");

$me = require_user_row($conn);

$categories = project_categories();
$interests  = project_tags($me['interests'] ?? '');

// ---- which list ----
$tab = $_GET['tab'] ?? 'discover';
if (!in_array($tab, array('discover', 'mine', 'applied'), true)) $tab = 'discover';
$q   = trim($_GET['q'] ?? '');
if (str_len($q) > 60) $q = str_cut($q, 60);
$cat = $_GET['cat'] ?? '';
if (!isset($categories[$cat])) $cat = '';

$where  = "";
$types  = "";
$params = array();
$order  = "p.created_at DESC, p.id DESC";
$limit  = PROJECT_PER_PAGE;

if ($tab === 'discover') {
    $where = "p.is_open = 1";
} elseif ($tab === 'mine') {
    $where = "mine.status = 'active'";
    $order = "p.updated_at DESC, p.id DESC";
} elseif ($tab === 'applied') {
    $where = "mine.status = 'pending'";
    $order = "mine.created_at DESC";
}

if ($cat !== '') {
    $where .= " AND p.category = ?";
    $types .= "s";
    $params[] = $cat;
}
if ($q !== '') {
    $like = '%' . str_replace(array('\\', '%', '_'), array('\\\\', '\\%', '\\_'), $q) . '%';
    $where .= " AND (p.title LIKE ? OR p.tagline LIKE ? OR p.skills LIKE ? OR p.category LIKE ?
                     OR EXISTS (SELECT 1 FROM project_roles sr WHERE sr.project_id = p.id AND sr.title LIKE ?))";
    $types .= "sssss";
    array_push($params, $like, $like, $like, $like, $like);
}

$res = project_query($conn, $me['id'], $where, $types, $params, $order, $limit);
$setupMissing = !$res['ok'];
$projects = $res['rows'];

// ---- numbers for the header ----
$openN = 0; $spotsN = 0; $waiting = 0;
if (!$setupMissing) {
    $st = db_run($conn, "SELECT COALESCE(SUM(is_open = 1), 0) AS open_n FROM projects");
    if ($st['ok'] && $st['rows']) {
        $openN = (int)$st['rows'][0]['open_n'];
    }
    $sp = db_run($conn,
        "SELECT COALESCE(SUM(GREATEST(CAST(r.spots AS SIGNED) - (SELECT COUNT(*) FROM project_members m WHERE m.role_id = r.id AND m.status = 'active'), 0)), 0) AS n
         FROM project_roles r JOIN projects p ON p.id = r.project_id
         WHERE p.is_open = 1");
    if ($sp['ok'] && $sp['rows']) $spotsN = (int)$sp['rows'][0]['n'];

    // applications waiting on projects this person founded
    $wr = db_run($conn,
        "SELECT COUNT(*) AS n FROM project_members a
         JOIN project_members o ON o.project_id = a.project_id AND o.user_id = ? AND o.role = 'owner'
         WHERE a.status = 'pending'", "i", array($me['id']));
    if ($wr['ok'] && $wr['rows']) $waiting = (int)$wr['rows'][0]['n'];
}

$flash = flash_get();
$message = $flash ? $flash[0] : "";
$message_class = $flash ? $flash[1] : "";

$filtered = ($q !== '' || $cat !== '');

function pj_tab_url($t, $q, $cat) {
    $a = array();
    if ($t !== 'discover') $a['tab'] = $t;
    if ($q !== '')   $a['q'] = $q;
    if ($cat !== '') $a['cat'] = $cat;
    return 'projects' . ($a ? '?' . http_build_query($a) : '');
}

$emptyTitle = array(
    'discover' => "No projects are recruiting yet",
    'mine'     => "You're not on any project yet",
    'applied'  => "No applications waiting",
);
$emptyText = array(
    'discover' => "Be the first to post an idea and find your team.",
    'mine'     => "Post your own idea, or apply to one that excites you.",
    'applied'  => "When you apply to a project, you can follow its answer here.",
);
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
</head>
<body>

<?php include("topbar.php"); ?>

<main class="main">

    <?php if ($tab === 'mine' || $tab === 'applied') { ?>
        <p class="crumb"><a href="projects">&larr; All projects</a></p>
    <?php } ?>

    <div class="page-top">
        <div>
            <h1 class="page-title">Projects</h1>
            <p class="page-sub">Turn an idea into a team. Post what you want to build and find students to build it with.</p>
        </div>
        <div class="top-actions">
            <?php if ($waiting > 0) { ?>
                <a href="project-applications" class="create-link join-link">Applications <span class="count"><?php echo $waiting; ?></span></a>
            <?php } ?>
            <a href="projects?tab=mine" class="create-link join-link<?php echo $tab === 'mine' ? ' is-active' : ''; ?>"<?php echo $tab === 'mine' ? ' aria-current="page"' : ''; ?>>My projects</a>
            <a href="create-project" class="create-link">Post a project</a>
        </div>
    </div>

    <?php if ($message != "") { ?>
        <div class="msg <?php echo e($message_class); ?>" style="margin-top:20px"><?php echo e($message); ?></div>
    <?php } ?>

    <form method="GET" class="dir-row cl-row" role="search">
        <?php if ($tab !== 'discover') { ?><input type="hidden" name="tab" value="<?php echo e($tab); ?>"><?php } ?>
        <div class="dir-search">
            <div class="dir-field">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="6.5"/><path d="M16 16l4.5 4.5"/></svg>
                <input type="search" name="q" maxlength="60" placeholder="Search by project, skill or role" value="<?php echo e($q); ?>" aria-label="Search projects" autocomplete="off">
            </div>
        </div>
        <select name="cat" aria-label="Category">
            <option value="">All categories</option>
            <?php foreach ($categories as $c => $ic) { ?>
                <option value="<?php echo e($c); ?>" <?php echo ($cat === $c) ? 'selected' : ''; ?>><?php echo e($c); ?></option>
            <?php } ?>
        </select>
        <button type="submit" class="btn-sm btn-dark">Search</button>
        <?php if ($filtered) { ?><a href="<?php echo e(pj_tab_url($tab, '', '')); ?>" class="cancel">Clear search</a><?php } ?>
    </form>

    <?php if ($setupMissing) { ?>

        <div class="msg error">Projects aren't set up yet. Run <strong>projects.sql</strong> in phpMyAdmin.</div>

    <?php } elseif (!$projects) { ?>

        <section class="panel center">
            <div class="ev-empty-ic"><?php echo project_icon('rocket'); ?></div>
            <h2><?php echo $filtered ? "No projects match your search" : e($emptyTitle[$tab]); ?></h2>
            <p class="empty">
                <?php if ($filtered) { ?>Try a different word or category.
                <?php } else { echo e($emptyText[$tab]); ?>
                <?php } ?>
            </p>
            <?php if (!$filtered) { ?>
                <div class="pj-empty-cta"><a href="create-project" class="btn-sm btn-dark">Post a project</a></div>
            <?php } ?>
        </section>

    <?php } else { ?>

        <div class="pj-grid">
            <?php foreach ($projects as $pr) { render_project_card($pr, $interests); } ?>
        </div>

        <?php if (count($projects) >= PROJECT_PER_PAGE) { ?>
            <p class="empty" style="margin-top:18px">Showing the first <?php echo (int)PROJECT_PER_PAGE; ?> projects. Use search to narrow it down.</p>
        <?php } ?>

    <?php } ?>

</main>

<script src="keep-scroll.js?v=<?php echo (int)@filemtime(__DIR__ . '/keep-scroll.js'); ?>"></script>

</body>
</html>