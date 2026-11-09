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
if (!in_array($tab, array('discover', 'foryou', 'mine', 'applied', 'showcase'), true)) $tab = 'discover';
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
    $where = "p.is_open = 1 AND p.stage <> 'launched'";
} elseif ($tab === 'foryou') {
    // everything open that you aren't already part of; we rank it by your interests below
    $where = "p.is_open = 1 AND p.stage <> 'launched' AND mine.status IS NULL";
    $limit = 100;
} elseif ($tab === 'mine') {
    $where = "mine.status = 'active'";
    $order = "p.updated_at DESC, p.id DESC";
} elseif ($tab === 'applied') {
    $where = "mine.status = 'pending'";
    $order = "mine.created_at DESC";
} else {
    $where = "p.stage = 'launched'";
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

// "For you": keep what matches the person's interests, best matches first
if ($tab === 'foryou' && $projects) {
    $scored = array();
    foreach ($projects as $pr) {
        $n = count(project_matches($pr['skills'], $pr['category'], $pr['roles'], $interests));
        if ($n > 0) $scored[] = array($n, $pr);
    }
    usort($scored, function ($a, $b) {
        if ($a[0] !== $b[0]) return $b[0] - $a[0];
        return (int)$b[1]['created_ts'] - (int)$a[1]['created_ts'];
    });
    $projects = array();
    foreach (array_slice($scored, 0, PROJECT_PER_PAGE) as $s) $projects[] = $s[1];
}

// ---- numbers for the header ----
$openN = 0; $launchedN = 0; $spotsN = 0; $waiting = 0;
if (!$setupMissing) {
    $st = db_run($conn, "SELECT COALESCE(SUM(is_open = 1 AND stage <> 'launched'), 0) AS open_n, COALESCE(SUM(stage = 'launched'), 0) AS launched_n FROM projects");
    if ($st['ok'] && $st['rows']) {
        $openN = (int)$st['rows'][0]['open_n'];
        $launchedN = (int)$st['rows'][0]['launched_n'];
    }
    $sp = db_run($conn,
        "SELECT COALESCE(SUM(GREATEST(CAST(r.spots AS SIGNED) - (SELECT COUNT(*) FROM project_members m WHERE m.role_id = r.id AND m.status = 'active'), 0)), 0) AS n
         FROM project_roles r JOIN projects p ON p.id = r.project_id
         WHERE p.is_open = 1 AND p.stage <> 'launched'");
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
    'foryou'   => $interests ? "No matches yet" : "Add interests to get matches",
    'mine'     => "You're not on any project yet",
    'applied'  => "No applications waiting",
    'showcase' => "Nothing has launched yet",
);
$emptyText = array(
    'discover' => "Be the first to post an idea and find your team.",
    'foryou'   => $interests ? "When a project asks for skills like yours, it shows up here. Check Discover in the meantime." : "Add a few interests to your profile and we'll show projects that fit them.",
    'mine'     => "Post your own idea, or apply to one that excites you.",
    'applied'  => "When you apply to a project, you can follow its answer here.",
    'showcase' => "Projects that reach the Launched stage are celebrated here.",
);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Projects | Camigo</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:wght@600;700;800&family=Instrument+Sans:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
</head>
<body>

<?php include("topbar.php"); ?>

<main class="main">

    <section class="pj-banner">
        <div class="pj-banner-copy">
            <span class="pj-eyebrow"><?php echo project_icon('rocket'); ?>Projects</span>
            <h1>Turn an idea into a team.</h1>
            <p>Post what you want to build, say who you need, and build it together with students from your campus.</p>
            <div class="pj-banner-actions">
                <a href="create-project" class="btn-sm btn-amber">Post a project</a>
                <?php if ($waiting > 0) { ?>
                    <a href="projects?tab=mine" class="btn-sm btn-light-ghost">Applications <span class="count"><?php echo $waiting; ?></span></a>
                <?php } ?>
            </div>
        </div>
        <?php if (!$setupMissing) { ?>
        <div class="pj-stats">
            <div><b><?php echo $openN; ?></b><span>recruiting now</span></div>
            <div><b><?php echo $spotsN; ?></b><span>open spots</span></div>
            <div><b><?php echo $launchedN; ?></b><span>launched</span></div>
        </div>
        <?php } ?>
    </section>

    <?php if ($message != "") { ?>
        <div class="msg <?php echo e($message_class); ?>" style="margin-top:20px"><?php echo e($message); ?></div>
    <?php } ?>

    <div class="tabs">
        <a href="<?php echo e(pj_tab_url('discover', $q, $cat)); ?>" class="<?php echo $tab === 'discover' ? 'active' : ''; ?>">Discover</a>
        <a href="<?php echo e(pj_tab_url('foryou', $q, $cat)); ?>" class="<?php echo $tab === 'foryou' ? 'active' : ''; ?>"><?php echo project_icon('sparkle'); ?>For you</a>
        <a href="<?php echo e(pj_tab_url('mine', $q, $cat)); ?>" class="<?php echo $tab === 'mine' ? 'active' : ''; ?>">My projects</a>
        <a href="<?php echo e(pj_tab_url('applied', $q, $cat)); ?>" class="<?php echo $tab === 'applied' ? 'active' : ''; ?>">Applied</a>
        <a href="<?php echo e(pj_tab_url('showcase', $q, $cat)); ?>" class="<?php echo $tab === 'showcase' ? 'active' : ''; ?>">Showcase</a>
    </div>

    <form method="GET" class="filters">
        <?php if ($tab !== 'discover') { ?><input type="hidden" name="tab" value="<?php echo e($tab); ?>"><?php } ?>
        <input type="text" name="q" maxlength="60" placeholder="Search by project, skill or role" value="<?php echo e($q); ?>">
        <select name="cat" aria-label="Category">
            <option value="">All categories</option>
            <?php foreach ($categories as $c => $ic) { ?>
                <option value="<?php echo e($c); ?>" <?php echo ($cat === $c) ? 'selected' : ''; ?>><?php echo e($c); ?></option>
            <?php } ?>
        </select>
        <button type="submit" class="btn-sm btn-dark">Search</button>
        <?php if ($filtered) { ?><a href="<?php echo e(pj_tab_url($tab, '', '')); ?>" class="cancel">Clear</a><?php } ?>
    </form>

    <?php if ($setupMissing) { ?>

        <div class="msg error">Projects aren't set up yet. Run <strong>projects.sql</strong> in phpMyAdmin.</div>

    <?php } elseif (!$projects) { ?>

        <section class="panel center">
            <div class="ev-empty-ic"><?php echo project_icon($tab === 'foryou' ? 'sparkle' : 'rocket'); ?></div>
            <h2><?php echo $filtered ? "No projects match your search" : e($emptyTitle[$tab]); ?></h2>
            <p class="empty">
                <?php if ($filtered) { ?>Try a different word or category.
                <?php } else { echo e($emptyText[$tab]); ?>
                    <?php if ($tab === 'foryou' && !$interests) { ?> <a href="edit-profile">Edit your profile</a><?php } ?>
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

</body>
</html>
