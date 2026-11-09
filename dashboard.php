<?php
session_start();

if (!isset($_SESSION['username'])) {
    header("Location: login");
    exit();
}

// Google sign-ups must finish their profile first
if (!empty($_SESSION['needs_profile'])) {
    header("Location: complete-profile");
    exit();
}

require("db.php");
require("auth.php");
require("events-lib.php");
require("feed-lib.php");
require_once("projects-lib.php");
$me = current_user($conn);
$isAdmin = $me && ($me['role'] ?? 'user') === 'admin';

$urow = get_user_row($conn, $_SESSION['username']);
$unread = $urow ? unread_count($conn, $urow['id']) : 0;
$unreadMsg = $urow ? unread_messages($conn, $urow['id']) : 0;

// people who share at least one club with you
$know = array();
if ($urow) {
    $know = db_run($conn,
        "SELECT u.id, u.username, u.first_name, u.last_name, u.course, u.year_level, u.photo,
                COUNT(DISTINCT a.club_id) AS shared
         FROM club_members a
         JOIN club_members b ON b.club_id = a.club_id AND b.user_id = ? AND b.status = 'active'
         JOIN clubs c ON c.id = a.club_id AND c.status = 'approved'
         JOIN users u ON u.id = a.user_id
         WHERE a.status = 'active' AND a.user_id <> ?
           AND (u.profile_visibility IS NULL OR u.profile_visibility = 'public') AND u.birthday IS NOT NULL
         GROUP BY u.id, u.username, u.first_name, u.last_name, u.course, u.year_level, u.photo
         ORDER BY shared DESC, u.first_name ASC LIMIT 6",
        "ii", array($urow['id'], $urow['id']))['rows'];
}

// newest projects that are looking for teammates
$freshRes = project_query($conn, $urow ? $urow['id'] : 0, "p.is_open = 1 AND p.stage <> 'launched'", "", array(), "p.created_at DESC, p.id DESC", 3);
$freshProjects = $freshRes['rows'];

// ---- home feed: news from the clubs this person has joined ----
$myClubs = array();
$week = array();
$requests = array();
$discover = array();
$featured = null;
$feed = array();
if ($urow) {
    $myClubs = db_run($conn,
        "SELECT c.id, c.name, c.category, m.role" . (club_identity_ready($conn) ? ", c.logo" : "") . "
         FROM club_members m JOIN clubs c ON c.id = m.club_id AND c.status = 'approved'
         WHERE m.user_id = ? AND m.status = 'active'
         ORDER BY (m.role = 'owner') DESC, (m.role = 'officer') DESC, c.name ASC LIMIT 50",
        "i", array($urow['id']))['rows'];

    $week     = rail_week($conn, $urow['id']);
    $requests = rail_requests($conn, $urow['id']);
    $discover = rail_discover($conn, $urow['id'], array_column($myClubs, 'category'));
    $featured = rail_featured($conn, $urow['id']);
    if ($featured) {
        // don't show the featured club twice
        $discover = array_values(array_filter($discover, function ($dc) use ($featured) { return (int)$dc['id'] !== (int)$featured['id']; }));
    }

    if ($myClubs) $feed = club_feed($conn, $urow['id'], 10);
}
$clubCount = count($myClubs);
$hasRail = ($clubCount > 0 || $week || $requests || $discover || $freshProjects || $featured);

$flash = flash_get();
$flashText = $flash ? $flash[0] : "";
$flashClass = $flash ? $flash[1] : "";

$first = $_SESSION['first_name'];
$initial = strtoupper(substr($first, 0, 1));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Dashboard | Camigo</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:wght@600;700;800&family=Instrument+Sans:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
</head>
<body>

<header class="topbar">
    <?php include("logo.php"); ?>

    <div class="user">
        <?php if ($isAdmin) { ?><a href="admin-clubs" class="out">Admin</a><?php } ?>
        <a href="students" class="out">Students</a>
        <a href="clubs" class="out">Clubs</a>
        <a href="projects" class="out">Projects</a>
        <a href="events" class="out">Events</a>
        <?php include("msg-icon.php"); ?>
        <?php include("bell.php"); ?>
        <a href="profile" class="me-link" title="Your profile">
            <span class="avatar"><?php echo $urow ? avatar_inner($urow) : htmlspecialchars($initial); ?></span>
            <span class="name"><?php echo htmlspecialchars($first); ?></span>
        </a>
        <a href="logout" class="out">Log out</a>
    </div>
</header>
<?php $rtUserId = $urow ? $urow['id'] : 0; include("realtime.php"); ?>

<main class="main">

    <?php if ($flashText != "") { ?>
        <div class="msg <?php echo e($flashClass); ?>" style="margin-bottom:20px"><?php echo e($flashText); ?></div>
    <?php } ?>

    <section class="hero hero-slim">
        <h1>Welcome, <?php echo htmlspecialchars($first); ?>.</h1>
        <p><?php echo $clubCount > 0 ? "Here's what's new across your clubs." : "Join a club to start seeing its announcements and events right here."; ?></p>
    </section>

    <div class="home-grid<?php echo $hasRail ? '' : ' no-rail'; ?>">
        <section class="home-main">
            <div class="home-feed-head">
                <h2 class="section-title" style="margin:0">From your clubs</h2>
                <?php if ($feed) { ?>
                    <div class="hf-filter" role="tablist" aria-label="Filter the feed">
                        <button type="button" class="on" data-filter="all">All</button>
                        <button type="button" data-filter="post">Announcements</button>
                        <button type="button" data-filter="event">Events</button>
                    </div>
                <?php } ?>
            </div>

            <?php if (!$clubCount) { ?>
                <section class="panel center" style="margin-top:0">
                    <div class="ev-empty-ic"><?php echo event_icon('megaphone'); ?></div>
                    <h2>Your feed starts with a club</h2>
                    <p class="empty">Join a club and its announcements and events will show up here, including the members-only ones.</p>
                    <p style="margin-top:18px"><a href="clubs" class="btn-sm btn-dark">Browse clubs</a></p>
                </section>
            <?php } elseif (!$feed) { ?>
                <section class="panel center" style="margin-top:0">
                    <div class="ev-empty-ic"><?php echo event_icon('check'); ?></div>
                    <h2>You're all caught up</h2>
                    <p class="empty">New announcements and events from your clubs will appear here.</p>
                </section>
            <?php } else { ?>
                <div class="hf-list" id="hfList">
                    <?php foreach ($feed as $it) { render_feed_item($it); } ?>
                </div>
                <p class="empty hf-none" id="hfNone" hidden>Nothing of that kind yet.</p>
                <button type="button" class="btn-sm btn-ghost hf-more" id="hfMore" hidden>Show more</button>
                <script>
                (function () {
                    var LIMIT = 5,
                        btns = document.querySelectorAll("[data-filter]"),
                        items = document.querySelectorAll("#hfList .hf"),
                        none = document.getElementById("hfNone"),
                        more = document.getElementById("hfMore"),
                        filter = "all", expanded = false;

                    function render() {
                        var matched = 0, shown = 0;
                        items.forEach(function (it) {
                            var ok = (filter === "all" || it.getAttribute("data-type") === filter);
                            if (ok) matched++;
                            var visible = ok && (expanded || matched <= LIMIT);
                            it.hidden = !visible;
                            if (visible) shown++;
                        });
                        none.hidden = matched > 0;
                        more.hidden = expanded || matched <= LIMIT;
                    }

                    btns.forEach(function (b) {
                        b.addEventListener("click", function () {
                            filter = b.getAttribute("data-filter");
                            btns.forEach(function (x) { x.classList.toggle("on", x === b); });
                            render();
                        });
                    });
                    more.addEventListener("click", function () { expanded = true; render(); });
                    render();
                })();
                </script>
                <script>
                // "See more" on long announcements: expand the text in place
                (function () {
                    var list = document.getElementById("hfList");
                    if (!list) return;

                    function measure() {
                        list.querySelectorAll("[data-hf-text]").forEach(function (el) {
                            var btn = el.nextElementSibling;
                            if (!btn || !btn.classList.contains("hf-expand")) return;
                            var card = el.closest(".hf");
                            if (card && card.hidden) return;            // can't measure what isn't showing
                            var want = el.classList.contains("open") ? false : !(el.scrollHeight > el.clientHeight + 2);
                            if (btn.hidden !== want) btn.hidden = want;   // only touch it when it changes, or the observer below loops
                        });
                    }

                    list.addEventListener("click", function (e) {
                        var btn = e.target.closest ? e.target.closest(".hf-expand") : null;
                        if (!btn) return;
                        var el = btn.previousElementSibling;
                        var open = el.classList.toggle("open");
                        btn.textContent = open ? "See less" : "See more";
                    });

                    measure();
                    window.addEventListener("resize", measure);
                    window.addEventListener("load", measure);
                    if (window.MutationObserver) {
                        new MutationObserver(function (muts) {
                            // only react when a post card is shown or hidden, never to our own button changes
                            for (var i = 0; i < muts.length; i++) {
                                if (muts[i].target.classList && muts[i].target.classList.contains("hf")) { measure(); return; }
                            }
                        }).observe(list, { subtree: true, attributes: true, attributeFilter: ["hidden"] });
                    }
                })();
                </script>
            <?php } ?>
        </section>

        <?php if ($hasRail) { ?>
        <aside class="rail">

            <?php if ($requests) { ?>
                <section class="panel rail-req">
                    <div class="rail-head">
                        <h2>Needs your attention</h2>
                        <span class="rail-count"><?php echo count($requests); ?></span>
                    </div>
                    <?php foreach (array_slice($requests, 0, 3) as $rq) { ?>
                        <div class="req-row">
                            <a class="req-who" href="profile?u=<?php echo e(urlencode($rq['username'])); ?>">
                                <span class="faces"><span class="face lg"><?php echo avatar_inner($rq); ?></span></span>
                                <span><strong><?php echo e(full_name($rq)); ?></strong><em>wants to join <?php echo e($rq['club_name']); ?></em></span>
                            </a>
                            <form method="POST" action="club" class="req-btns">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="club_id" value="<?php echo (int)$rq['club_id']; ?>">
                                <input type="hidden" name="member_id" value="<?php echo (int)$rq['mid']; ?>">
                                <input type="hidden" name="back" value="dashboard">
                                <button type="submit" name="action" value="accept" class="btn-sm btn-dark">Accept</button>
                                <button type="submit" name="action" value="decline" class="btn-sm btn-ghost">Decline</button>
                            </form>
                        </div>
                    <?php } ?>
                    <?php if (count($requests) > 3) { ?>
                        <p class="club-meta" style="margin-top:10px">+ <?php echo count($requests) - 3; ?> more waiting. Open the club to review them.</p>
                    <?php } ?>
                </section>
            <?php } ?>

            <?php if ($featured) { $fm = (int)$featured['members']; ?>
                <section class="panel rail-feat">
                    <span class="feat-badge">&#9733; Featured club</span>
                    <a class="feat-main" href="club?id=<?php echo (int)$featured['id']; ?>">
                        <?php echo club_tile_html($featured, '', 'span'); ?>
                        <span>
                            <strong><?php echo e($featured['name']); ?></strong>
                            <em><?php echo e($featured['category']); ?> &middot; <?php echo $fm; ?> member<?php echo $fm === 1 ? '' : 's'; ?></em>
                        </span>
                    </a>
                    <?php if ($featured['blurb'] !== '') { ?><p class="feat-desc"><?php echo e($featured['blurb']); ?></p><?php } ?>
                    <a class="feat-btn" href="club?id=<?php echo (int)$featured['id']; ?>">View club</a>
                </section>
            <?php } ?>

            <?php if ($clubCount > 0 || $week) {
                $wkTotal = 0;
                foreach ($week as $wd) { $wkTotal += count($wd['events']); }
                $wkShown = 0;
                $wkMax = 4;
            ?>
                <section class="panel rail-week">
                    <div class="rail-head">
                        <h2>This week</h2>
                        <a href="events">All events</a>
                    </div>
                    <?php if (!$week) { ?>
                        <p class="empty">Nothing scheduled in the next 7 days.</p>
                    <?php } else { ?>
                        <div class="wk-list">
                        <?php foreach ($week as $day) {
                            if ($wkShown >= $wkMax) break;
                        ?>
                            <div class="wk-day">
                                <span class="wk-chip<?php echo !empty($day['is_today']) ? ' is-today' : ''; ?>" title="<?php echo e($day['label'] . ', ' . $day['date']); ?>">
                                    <small><?php echo e($day['dow']); ?></small>
                                    <b><?php echo e($day['num']); ?></b>
                                </span>
                                <div class="wk-events">
                                <?php foreach ($day['events'] as $we) {
                                    if ($wkShown >= $wkMax) break;
                                    $wkShown++;
                                    $wm = (($we['visibility'] ?? 'public') === 'members');
                                ?>
                                    <a class="wk-item" href="event?id=<?php echo (int)$we['id']; ?>">
                                        <span class="wk-main">
                                            <strong><?php echo e($we['title']); ?></strong>
                                            <em><span class="wk-time"><?php echo e(event_dt($we['start_at'])->format('g:i A')); ?></span><span class="wk-dot">&middot;</span><?php if ($wm) { echo event_icon('lock'); } ?><span class="wk-club"><?php echo e($we['club_name']); ?></span></em>
                                        </span>
                                        <?php if ((int)$we['me_going'] > 0) { ?><span class="wk-going" title="You're going"><?php echo event_icon('check'); ?></span><?php } ?>
                                    </a>
                                <?php } ?>
                                </div>
                            </div>
                        <?php } ?>
                        </div>
                        <?php if ($wkTotal > $wkShown) { ?>
                            <a class="wk-more" href="events">+ <?php echo $wkTotal - $wkShown; ?> more this week</a>
                        <?php } ?>
                    <?php } ?>
                </section>
            <?php } ?>

            <?php if ($myClubs) { ?>
                <section class="panel rail-mine">
                    <div class="rail-head">
                        <h2>Your clubs</h2>
                        <a href="clubs">All clubs</a>
                    </div>
                    <?php foreach (array_slice($myClubs, 0, 4) as $mc) {
                        $role = (string)($mc['role'] ?? 'member');
                        $roleLabel = ($role === 'owner') ? 'Owner' : (($role === 'officer') ? 'Officer' : '');
                    ?>
                        <a class="rail-club" href="club?id=<?php echo (int)$mc['id']; ?>">
                            <?php echo club_tile_html($mc, '', 'span'); ?>
                            <span><strong><?php echo e($mc['name']); ?></strong><em><?php echo $roleLabel !== '' ? e($roleLabel) . ' &middot; ' : ''; ?><?php echo e($mc['category']); ?></em></span>
                        </a>
                    <?php } ?>
                    <?php if (count($myClubs) > 4) { ?>
                        <a class="wk-more" href="clubs">+ <?php echo count($myClubs) - 4; ?> more</a>
                    <?php } ?>
                </section>
            <?php } ?>

            <?php if ($freshProjects) { ?>
                <section class="panel rail-pj">
                    <div class="rail-head">
                        <h2>Looking for teammates</h2>
                        <a href="projects">All projects</a>
                    </div>
                    <?php foreach (array_slice($freshProjects, 0, 3) as $fp) {
                        $left = project_open_spots($fp['roles'] ?? array());
                    ?>
                        <a class="rail-pj-row" href="project?id=<?php echo (int)$fp['id']; ?>">
                            <span class="pj-mini-ic t<?php echo project_tone($fp['id']); ?>"><?php echo project_category_icon($fp['category']); ?></span>
                            <span class="rail-pj-txt">
                                <strong><?php echo e($fp['title']); ?></strong>
                                <em><?php echo e($fp['category']); ?><?php if ($left > 0) { echo ' &middot; ' . $left . ' spot' . ($left === 1 ? '' : 's') . ' open'; } ?></em>
                            </span>
                        </a>
                    <?php } ?>
                </section>
            <?php } ?>

            <?php if ($discover) { ?>
                <section class="panel rail-disc">
                    <div class="rail-head">
                        <h2>Discover clubs</h2>
                        <a href="clubs">Browse all</a>
                    </div>
                    <?php foreach ($discover as $dc) { $mc = (int)$dc['members']; ?>
                        <a class="rail-club" href="club?id=<?php echo (int)$dc['id']; ?>">
                            <?php echo club_tile_html($dc, '', 'span'); ?>
                            <span><strong><?php echo e($dc['name']); ?></strong><em><?php echo e($dc['category']); ?> &middot; <?php echo $mc; ?> member<?php echo $mc === 1 ? '' : 's'; ?></em></span>
                        </a>
                    <?php } ?>
                </section>
            <?php } ?>

        </aside>
        <?php } ?>
    </div>

    <?php if ($know) { ?>
    <div class="strip-head">
        <h2 class="section-title">People you may know</h2>
        <a href="students">See all students</a>
    </div>
    <section class="dir-strip">
        <?php foreach ($know as $k) {
            $sub = array();
            if (!empty($k['course'])) $sub[] = $k['course'];
            if (!empty($k['year_level'])) $sub[] = $k['year_level'];
            $n = (int)$k['shared'];
        ?>
            <a class="dir-mini" href="profile?u=<?php echo e(urlencode($k['username'])); ?>">
                <div class="dir-avatar"><?php echo avatar_inner($k); ?></div>
                <strong><?php echo e(full_name($k)); ?></strong>
                <span class="mini-sub"><?php echo e($sub ? implode(' · ', $sub) : '@' . $k['username']); ?></span>
                <span class="mini-shared"><?php echo $n; ?> shared club<?php echo $n === 1 ? '' : 's'; ?></span>
            </a>
        <?php } ?>
    </section>
    <?php } ?>

</main>

</body>
</html>