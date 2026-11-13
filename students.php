<?php
session_start();
require("db.php");
require("auth.php");

$me = require_user_row($conn);

$perPage = 24;

// who may appear in the directory: public, finished profiles
$VISIBLE = "(u.profile_visibility IS NULL OR u.profile_visibility = 'public') AND u.birthday IS NOT NULL";

// ================= SEARCH =================
$q    = trim(preg_replace('/\s+/', ' ', $_GET['q'] ?? ''));
$q    = str_cut($q, 60);
$page = max(1, (int)($_GET['page'] ?? 1));


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

$cnt = db_run($conn, "SELECT COUNT(*) AS n FROM users u" . $where, $wTypes, $wParams);
$setupMissing = !$cnt['ok'];
$total = ($cnt['ok'] && $cnt['rows']) ? (int)$cnt['rows'][0]['n'] : 0;
$pages = max(1, (int)ceil($total / $perPage));
if ($page > $pages) $page = $pages;
$offset = ($page - 1) * $perPage;

$students = array();
if (!$setupMissing && $total > 0) {

    // cover, thought bubble and social links only exist once their migrations have been run
    $extra = "";
    foreach (array('cover', 'status_emoji', 'status_text') as $col) {
        if (array_key_exists($col, $me)) $extra .= ", u." . $col;
    }
    foreach (array_keys(social_networks()) as $col) {
        if (array_key_exists($col, $me)) $extra .= ", u." . $col;
    }
    if (array_key_exists('is_verified', $me)) $extra .= ", u.is_verified, u.role";

    $sql = "SELECT u.id, u.username, u.first_name, u.middle_initial, u.last_name, u.course, u.year_level, u.interests, u.photo" . $extra . "
            FROM users u" . $where . " ORDER BY u.first_name ASC, u.last_name ASC LIMIT ? OFFSET ?";

    $res = db_run($conn, $sql, $wTypes . "ii", array_merge($wParams, array($perPage, $offset)));
    $students = $res['rows'];
}

$flash = flash_get();
$message = $flash ? $flash[0] : "";
$message_class = $flash ? $flash[1] : "";

$filtered = ($q !== '');

function dir_url($over) {
    global $q, $page;
    $a = array_merge(array('q' => $q, 'page' => $page), $over);
    foreach ($a as $k => $v) {
        if ($v === '' || $v === null || ($k === 'page' && (int)$v <= 1)) unset($a[$k]);
    }
    return 'students' . ($a ? '?' . http_build_query($a) : '');
}

// Playful "nothing here yet" copy (same lines as the profile page). One stable line per student.
function dir_empty($kind, $name, $seed) {
    $all = array(
        'interests' => array(
            array('🎨', "A blank canvas.", "$name hasn't picked any interests yet."),
            array('🫥', "Still figuring it out.", "Ask what they're into!"),
            array('🔮', "Interests unknown.", "The crystal ball is cloudy."),
        ),
        'links' => array(
            array('🥷', "Off the grid.", "No links added yet."),
            array('🐚', "No links yet.", "$name hasn't shared any socials."),
            array('📵', "Low-key online.", "No socials to show."),
        ),
    );
    $c = $all[$kind][abs((int)$seed) % count($all[$kind])];
    return '<p class="dir-empty"><span class="dir-empty-tx">' . e($c[2]) . '</span></p>';
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

    <header class="dir-hero">
        <h1 class="page-title">Students</h1>
        <p class="page-sub">Find classmates by name, course or interest, and open their profiles.</p>

        <form method="GET" action="students" class="dir-row cl-row" role="search">
            <div class="dir-search">
                <div class="dir-field">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="6.5"/><path d="M16 16l4.5 4.5"/></svg>
                    <input type="search" name="q" maxlength="60" placeholder="Search by name, course or interest" value="<?php echo e($q); ?>" aria-label="Search students" autocomplete="off">
                </div>
            </div>
            <button type="submit" class="btn-sm btn-dark">Search</button>
            <?php if ($filtered) { ?><a href="students" class="cancel">Clear search</a><?php } ?>
        </form>
    </header>

    <?php if ($message != "") { ?>
        <div class="msg <?php echo e($message_class); ?>" style="margin-top:20px"><?php echo e($message); ?></div>
    <?php } ?>

    <?php if ($setupMissing) { ?>

        <div class="msg error" style="margin-top:20px">The directory needs the profile fields. Run <strong>profile.sql</strong> in phpMyAdmin first.</div>

    <?php } else { ?>

        <?php if (!$students) { ?>

            <section class="panel center">
                <h2><?php echo $filtered ? "Nobody matches that search" : "No students to show yet"; ?></h2>
                <p class="empty">
                    <?php if ($filtered) { ?>Try fewer words or a different spelling.
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
                    // render a generous batch; the card's fit script hides what doesn't fit and adds "+N"
                    $shownTags = array_slice($allTags, 0, 30);
                    $extraTags = count($allTags) - count($shownTags);

                ?>
                    <?php
                        $cv  = (!empty($s['cover']) && club_image_ok($s['cover'])) ? $s['cover'] : '';
                        $sEm = trim((string)($s['status_emoji'] ?? ''));
                        $sTx = trim((string)($s['status_text'] ?? ''));

                        $links = array();
                        foreach (social_networks() as $key => $net) {
                            if (!empty($s[$key]) && preg_match('#^https?://#i', $s[$key])) $links[] = array($net[0], $s[$key], $key);
                        }
                    ?>
                    <article class="dir-card">
                        <div class="dir-banner<?php echo $cv ? ' has-img' : ''; ?>"<?php if ($cv) { ?> style="background-image:url('uploads/covers/<?php echo e($cv); ?>')"<?php } else { ?> data-g="<?php echo (int)$s['id'] % 6; ?>"<?php } ?>></div>
                        <a href="messages?u=<?php echo e(urlencode($s['username'])); ?>" class="dir-msg" aria-label="Message <?php echo e($s['first_name']); ?>" title="Message <?php echo e($s['first_name']); ?>"><svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M12 2.5c-5.25 0-9.5 3.7-9.5 8.4 0 2.5 1.2 4.7 3.2 6.2-.1 1.3-.6 2.6-1.6 3.7a.6.6 0 00.5 1c2-.1 3.6-.8 4.8-1.7.8.2 1.7.3 2.6.3 5.25 0 9.5-3.7 9.5-8.5S17.25 2.5 12 2.5z"/><circle cx="7.5" cy="11.25" r="1.35" fill="#111"/><circle cx="12" cy="11.25" r="1.35" fill="#111"/><circle cx="16.5" cy="11.25" r="1.35" fill="#111"/></svg></a>

                        <div class="dir-body">
                            <div class="dir-avatar"><?php echo avatar_inner($s); ?></div>

                            <?php if ($sEm !== '' || $sTx !== '') { ?>
                                <div class="dir-bubble">
                                    <i class="b1"></i><i class="b2"></i>
                                    <div class="dir-pill<?php echo ($sTx === '') ? ' only-emoji' : ''; ?>">
                                        <?php if ($sEm !== '') { ?><span class="em"><?php echo e($sEm); ?></span><?php } ?>
                                        <?php if ($sTx !== '') { ?><span class="tx"><?php echo e($sTx); ?></span><?php } ?>
                                    </div>
                                </div>
                            <?php } ?>

                            <div class="dir-id">
                                <h3><a class="dir-link" href="profile?u=<?php echo e(urlencode($s['username'])); ?>"><?php echo e($name); ?></a><?php echo verified_badge($s); ?></h3>
                                <span class="dir-user">@<?php echo e($s['username']); ?></span>
                            </div>

                            <p class="dir-meta<?php echo $line ? '' : ' none'; ?>"<?php if ($line) { ?> title="<?php echo e(implode(' · ', $line)); ?>"<?php } ?>><?php echo $line ? e(implode(' · ', $line)) : 'No course yet'; ?></p>

                            <div class="dir-social<?php echo $links ? '' : ' is-empty'; ?>">
                                <?php foreach (array_slice($links, 0, 6) as $l) { ?>
                                    <a href="<?php echo e($l[1]); ?>" target="_blank" rel="noopener noreferrer nofollow" aria-label="<?php echo e($l[0]); ?>" title="<?php echo e($l[0]); ?>"><?php echo social_icon($l[2]); ?></a>
                                <?php } ?>
                                <?php if (!$links) echo dir_empty('links', $s['first_name'], $s['id']); ?>
                            </div>

                            <h4 class="dir-h">Interests</h4>
                            <div class="chip-row dir-chips" data-fit data-extra="<?php echo (int)$extraTags; ?>">
                                <?php if ($shownTags) { foreach ($shownTags as $t) { ?><span class="chip"><?php echo e(trim($t)); ?></span><?php } }
                                      else { echo dir_empty('interests', $s['first_name'], $s['id']); } ?>
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
// Interests: show only the chips that fit in the card's two rows, then a "+N" chip for the rest.
(function () {
    function fit(box) {
        var chips = Array.prototype.slice.call(box.querySelectorAll(".chip:not(.chip-more)"));
        var old = box.querySelector(".chip-more");
        if (old) old.remove();
        if (!chips.length) return;

        var extra = parseInt(box.getAttribute("data-extra"), 10) || 0;
        chips.forEach(function (c) { c.hidden = false; });

        function fits(el) { return el.offsetTop + el.offsetHeight <= box.clientHeight + 1; }
        if (!extra && chips.every(fits)) return;

        var more = document.createElement("span");
        more.className = "chip chip-more";
        box.appendChild(more);

        // hide chips from the end until the last visible chip and the "+N" chip both sit inside the two rows
        for (var n = chips.length; n >= 0; n--) {
            chips.forEach(function (c, i) { c.hidden = i >= n; });
            more.textContent = "+" + (chips.length - n + extra);
            more.title = chips.slice(n).map(function (c) { return c.textContent; }).join(", ");
            if (n === chips.length && !extra) continue;
            if (fits(more) && (n === 0 || fits(chips[n - 1]))) return;
        }
    }
    function run() { document.querySelectorAll("[data-fit]").forEach(fit); }
    run();
    if (document.fonts && document.fonts.ready) document.fonts.ready.then(run);
    var t; window.addEventListener("resize", function () { clearTimeout(t); t = setTimeout(run, 120); });
})();

</script>

<script src="keep-scroll.js?v=<?php echo (int)@filemtime(__DIR__ . '/keep-scroll.js'); ?>"></script>

</body>
</html>