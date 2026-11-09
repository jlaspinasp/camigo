<?php
session_start();
require("db.php");
require("auth.php");

$me = require_user_row($conn);

$years   = year_levels();
$perPage = 24;

// who may appear in the directory: public, finished profiles
$VISIBLE = "(u.profile_visibility IS NULL OR u.profile_visibility = 'public') AND u.birthday IS NOT NULL";

// ================= INVITE A STUDENT TO A CLUB =================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $action = $_POST['action'] ?? '';
    $uid    = (int)($_POST['user_id'] ?? 0);
    $cid    = (int)($_POST['club_id'] ?? 0);

    if (!csrf_ok() || $action !== 'invite' || $uid <= 0 || $cid <= 0) {
        flash_set("Your session expired. Please try again.", "error");
    } else {
        // only an active owner of an approved club can invite
        $own = db_run($conn,
            "SELECT c.name FROM club_members m JOIN clubs c ON c.id = m.club_id AND c.status = 'approved'
             WHERE m.club_id = ? AND m.user_id = ? AND m.role = 'owner' AND m.status = 'active' LIMIT 1",
            "ii", array($cid, $me['id']));

        $tg = db_run($conn,
            "SELECT u.id, u.first_name FROM users u WHERE u.id = ? AND u.id <> ? AND " . $VISIBLE . " LIMIT 1",
            "ii", array($uid, $me['id']));

        if (!$own['rows']) {
            flash_set("Only the owner of a club can send invites.", "error");
        } elseif (!$tg['rows']) {
            flash_set("That student isn't available to invite.", "error");
        } else {
            $first = $tg['rows'][0]['first_name'];
            $club  = $own['rows'][0]['name'];

            $ex = db_run($conn, "SELECT status FROM club_members WHERE club_id = ? AND user_id = ? LIMIT 1", "ii", array($cid, $uid));
            if ($ex['rows']) {
                flash_set(($ex['rows'][0]['status'] === 'active')
                    ? $first . " is already in " . $club . "."
                    : $first . " already asked to join " . $club . ". You can accept them in Join requests.", "error");
            } else {
                $link = 'club?id=' . $cid;
                if (club_identity_ready($conn)) {
                    $ik = db_run($conn, "SELECT invite_only, invite_token FROM clubs WHERE id = ? LIMIT 1", "i", array($cid));
                    if ($ik['rows'] && (int)$ik['rows'][0]['invite_only'] === 1 && !empty($ik['rows'][0]['invite_token'])) {
                        $link .= '&invite=' . $ik['rows'][0]['invite_token'];
                    }
                }
                $dup = db_run($conn,
                    "SELECT id FROM notifications WHERE user_id = ? AND type = 'club_invite' AND link = ?
                     AND created_at > (NOW() - INTERVAL 7 DAY) LIMIT 1",
                    "is", array($uid, $link));

                if ($dup['rows']) {
                    flash_set("You already invited " . $first . " to " . $club . " this week.", "error");
                } elseif (notify($conn, $uid, 'club_invite', full_name($me) . ' invited you to join ' . $club . '.', $link)) {
                    flash_set("Invite sent to " . $first . ".", "success");
                } else {
                    flash_set("We couldn't send that invite. Please try again.", "error");
                }
            }
        }
    }

    // return to the same search
    $back = array();
    $bq = str_cut(trim($_POST['q'] ?? ''), 60);
    if ($bq !== '') $back['q'] = $bq;
    if (in_array($_POST['year'] ?? '', $years, true)) $back['year'] = $_POST['year'];
    if (in_array($_POST['sort'] ?? '', array('new', 'shared'), true)) $back['sort'] = $_POST['sort'];
    if ((int)($_POST['page'] ?? 1) > 1) $back['page'] = (int)$_POST['page'];
    header("Location: students" . ($back ? "?" . http_build_query($back) : ""));
    exit();
}

// ================= SEARCH =================
$q    = trim(preg_replace('/\s+/', ' ', $_GET['q'] ?? ''));
$q    = str_cut($q, 60);
$year = $_GET['year'] ?? '';
$sort = $_GET['sort'] ?? 'name';
$page = max(1, (int)($_GET['page'] ?? 1));

if (!in_array($year, $years, true)) $year = '';
if (!in_array($sort, array('name', 'new', 'shared'), true)) $sort = 'name';

$where   = " WHERE u.id <> ? AND " . $VISIBLE;
$wTypes  = "i";
$wParams = array($me['id']);

// every word must match the name, username, course or an interest
if ($q !== '') {
    $words = array_slice(array_filter(explode(' ', $q)), 0, 5);
    foreach ($words as $w) {
        $like = '%' . addcslashes($w, '%_\\') . '%';
        $where .= " AND (u.first_name LIKE ? OR u.last_name LIKE ? OR u.username LIKE ? OR u.course LIKE ? OR u.interests LIKE ?)";
        $wTypes .= "sssss";
        array_push($wParams, $like, $like, $like, $like, $like);
    }
}
if ($year !== '') {
    $where .= " AND u.year_level = ?";
    $wTypes .= "s";
    $wParams[] = $year;
}

$cnt = db_run($conn, "SELECT COUNT(*) AS n FROM users u" . $where, $wTypes, $wParams);
$setupMissing = !$cnt['ok'];
$total = ($cnt['ok'] && $cnt['rows']) ? (int)$cnt['rows'][0]['n'] : 0;
$pages = max(1, (int)ceil($total / $perPage));
if ($page > $pages) $page = $pages;
$offset = ($page - 1) * $perPage;

$students = array();
if (!$setupMissing && $total > 0) {
    $order = array(
        'name'   => "u.first_name ASC, u.last_name ASC",
        'new'    => "u.id DESC",
        'shared' => "shared DESC, u.first_name ASC",
    );

    $sql = "SELECT u.id, u.username, u.first_name, u.middle_initial, u.last_name, u.course, u.year_level, u.interests, u.photo,
                (SELECT COUNT(*) FROM club_members a
                 JOIN club_members b ON b.club_id = a.club_id AND b.user_id = ? AND b.status = 'active'
                 JOIN clubs c ON c.id = a.club_id AND c.status = 'approved'
                 WHERE a.user_id = u.id AND a.status = 'active') AS shared
            FROM users u" . $where . " ORDER BY " . $order[$sort] . " LIMIT ? OFFSET ?";

    $res = db_run($conn, $sql, "i" . $wTypes . "ii", array_merge(array($me['id']), $wParams, array($perPage, $offset)));
    $students = $res['rows'];
}

// ---- clubs I own (for the Invite button) ----
$ownedClubs = db_run($conn,
    "SELECT c.id, c.name FROM club_members m JOIN clubs c ON c.id = m.club_id AND c.status = 'approved'
     WHERE m.user_id = ? AND m.role = 'owner' AND m.status = 'active' ORDER BY c.name ASC",
    "i", array($me['id']))['rows'];

// which of these students are already in (or asked to join) my clubs
$inClub = array();
if ($ownedClubs && $students) {
    $uids = implode(',', array_map('intval', array_column($students, 'id')));
    $cids = implode(',', array_map('intval', array_column($ownedClubs, 'id')));
    $mr = db_run($conn, "SELECT user_id, club_id FROM club_members WHERE user_id IN ($uids) AND club_id IN ($cids)");
    foreach ($mr['rows'] as $row) {
        $inClub[$row['user_id'] . ':' . $row['club_id']] = true;
    }
}

$flash = flash_get();
$message = $flash ? $flash[0] : "";
$message_class = $flash ? $flash[1] : "";

$filtered = ($q !== '' || $year !== '');

function dir_url($over) {
    global $q, $year, $sort, $page;
    $a = array_merge(array('q' => $q, 'year' => $year, 'sort' => $sort, 'page' => $page), $over);
    foreach ($a as $k => $v) {
        if ($v === '' || $v === null || ($k === 'sort' && $v === 'name') || ($k === 'page' && (int)$v <= 1)) unset($a[$k]);
    }
    return 'students' . ($a ? '?' . http_build_query($a) : '');
}

function dir_name($r) {
    return trim($r['first_name']
        . (!empty($r['middle_initial']) ? ' ' . $r['middle_initial'] . '.' : '')
        . (!empty($r['last_name']) ? ' ' . $r['last_name'] : ''));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Students | Camigo</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:wght@600;700;800&family=Instrument+Sans:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
</head>
<body>

<?php include("topbar.php"); ?>

<main class="main">

    <header class="dir-hero">
        <h1 class="page-title">Students</h1>
        <p class="page-sub">Find classmates by name, course or interest, and open their profiles.<?php if ($ownedClubs) { ?> You can also invite them to the clubs you own.<?php } ?></p>

        <form method="GET" action="students" class="dir-search" role="search">
            <div class="dir-field">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="6.5"/><path d="M16 16l4.5 4.5"/></svg>
                <input type="search" name="q" maxlength="60" placeholder="Search by name, course or interest" value="<?php echo e($q); ?>" aria-label="Search students" autocomplete="off">
            </div>

            <select name="year" aria-label="Year level">
                <option value="">Any year</option>
                <?php foreach ($years as $y) { ?>
                    <option value="<?php echo e($y); ?>" <?php echo ($year === $y) ? 'selected' : ''; ?>><?php echo e($y); ?></option>
                <?php } ?>
            </select>

            <select name="sort" aria-label="Sort by">
                <option value="name"   <?php echo $sort === 'name'   ? 'selected' : ''; ?>>Name A to Z</option>
                <option value="shared" <?php echo $sort === 'shared' ? 'selected' : ''; ?>>Most shared clubs</option>
                <option value="new"    <?php echo $sort === 'new'    ? 'selected' : ''; ?>>Newest</option>
            </select>

            <button type="submit" class="btn-sm btn-dark">Search</button>
        </form>
    </header>

    <?php if ($message != "") { ?>
        <div class="msg <?php echo e($message_class); ?>" style="margin-top:20px"><?php echo e($message); ?></div>
    <?php } ?>

    <?php if ($setupMissing) { ?>

        <div class="msg error" style="margin-top:20px">The directory needs the profile fields. Run <strong>profile.sql</strong> in phpMyAdmin first.</div>

    <?php } else { ?>

        <div class="dir-bar">
            <span class="dir-count">
                <?php if ($total === 0) { echo 'No students found'; }
                      else { echo $total . ' student' . ($total === 1 ? '' : 's'); if ($q !== '') echo ' matching &ldquo;' . e($q) . '&rdquo;'; } ?>
            </span>
            <?php if ($filtered) { ?><a href="students" class="cancel">Clear search</a><?php } ?>
        </div>

        <?php if (!$students) { ?>

            <section class="panel center">
                <h2><?php echo $filtered ? "Nobody matches that search" : "No students to show yet"; ?></h2>
                <p class="empty">
                    <?php if ($filtered) { ?>Try fewer words, a different spelling, or any year level.
                    <?php } else { ?>Students with public profiles will appear here.<?php } ?>
                </p>
            </section>

        <?php } else { ?>

            <div class="dir-grid">
                <?php foreach ($students as $s) {
                    $name = dir_name($s);
                    $line = array();
                    if (!empty($s['course']))     $line[] = $s['course'];
                    if (!empty($s['year_level'])) $line[] = $s['year_level'];
                    $allTags = !empty($s['interests']) ? array_values(array_filter(explode(',', $s['interests']))) : array();
                    $tags = array_slice($allTags, 0, 3);
                    $more = count($allTags) - count($tags);
                    $shared = (int)$s['shared'];

                    // my clubs this student could still be invited to
                    $invitable = array();
                    foreach ($ownedClubs as $oc) {
                        if (empty($inClub[$s['id'] . ':' . $oc['id']])) $invitable[] = $oc;
                    }
                ?>
                    <article class="dir-card">
                        <div class="dir-top">
                            <div class="dir-avatar"><?php echo avatar_inner($s); ?></div>
                            <div class="dir-id">
                                <h3><a class="dir-link" href="profile?u=<?php echo e(urlencode($s['username'])); ?>"><?php echo e($name); ?></a></h3>
                                <span class="dir-user">@<?php echo e($s['username']); ?></span>
                            </div>
                        </div>

                        <?php if ($line) { ?>
                            <p class="dir-course"><?php echo e(implode(' · ', $line)); ?></p>
                        <?php } else { ?>
                            <p class="dir-course none">No course added</p>
                        <?php } ?>

                        <?php if ($tags) { ?>
                            <div class="dir-tags">
                                <?php foreach ($tags as $t) { ?><span class="chip"><?php echo e($t); ?></span><?php } ?>
                                <?php if ($more > 0) { ?><span class="dir-more">+<?php echo (int)$more; ?></span><?php } ?>
                            </div>
                        <?php } ?>

                        <div class="dir-foot">
                            <?php if ($shared > 0) { ?>
                                <span class="dir-shared"><?php echo $shared; ?> shared club<?php echo $shared === 1 ? '' : 's'; ?></span>
                            <?php } else { ?>
                                <span class="dir-shared none">No shared clubs</span>
                            <?php } ?>

                            <div class="dir-actions">
                                <a class="dir-btn" href="messages?u=<?php echo e(urlencode($s['username'])); ?>">Message</a>

                                <?php if ($invitable) { ?>
                                    <form method="POST" class="dir-invite-form">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="action" value="invite">
                                        <input type="hidden" name="user_id" value="<?php echo (int)$s['id']; ?>">
                                        <input type="hidden" name="q" value="<?php echo e($q); ?>">
                                        <input type="hidden" name="year" value="<?php echo e($year); ?>">
                                        <input type="hidden" name="sort" value="<?php echo e($sort); ?>">
                                        <input type="hidden" name="page" value="<?php echo (int)$page; ?>">

                                        <?php if (count($invitable) === 1) { ?>
                                            <button type="submit" name="club_id" value="<?php echo (int)$invitable[0]['id']; ?>" class="dir-btn" title="Invite to <?php echo e($invitable[0]['name']); ?>">Invite</button>
                                        <?php } else { ?>
                                            <details class="dir-invite">
                                                <summary class="dir-btn">Invite</summary>
                                                <div class="dir-menu">
                                                    <span class="menu-label">Invite to which club?</span>
                                                    <?php foreach ($invitable as $ic) { ?>
                                                        <button type="submit" name="club_id" value="<?php echo (int)$ic['id']; ?>"><?php echo e($ic['name']); ?></button>
                                                    <?php } ?>
                                                </div>
                                            </details>
                                        <?php } ?>
                                    </form>
                                <?php } ?>
                            </div>
                        </div>
                    </article>
                <?php } ?>
            </div>

            <?php if ($pages > 1) { ?>
                <nav class="dir-pager" aria-label="Pages">
                    <?php if ($page > 1) { ?><a href="<?php echo e(dir_url(array('page' => $page - 1))); ?>" class="btn-sm btn-ghost">Previous</a><?php } ?>
                    <span class="dir-page">Page <?php echo $page; ?> of <?php echo $pages; ?></span>
                    <?php if ($page < $pages) { ?><a href="<?php echo e(dir_url(array('page' => $page + 1))); ?>" class="btn-sm btn-ghost">Next</a><?php } ?>
                </nav>
            <?php } ?>

        <?php } ?>

    <?php } ?>

</main>

<script>
// close an open "Invite to club" menu when you click elsewhere
document.addEventListener("click", function (e) {
    document.querySelectorAll(".dir-invite[open]").forEach(function (d) {
        if (!d.contains(e.target)) d.removeAttribute("open");
    });
});
</script>

</body>
</html>