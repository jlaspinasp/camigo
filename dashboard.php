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

// newest projects that are looking for teammates
$freshRes = project_query($conn, $urow ? $urow['id'] : 0, "p.is_open = 1", "", array(), "p.created_at DESC, p.id DESC", 50);
$freshProjects = $freshRes['rows'];

// ---- home feed: news from the clubs this person has joined ----
$myClubs = array();
$week = array();
$requests = array();
$discover = array();
$featured = null;
$feed = array();
$feedMore = false;
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

    if ($myClubs) {
        $feed = club_feed($conn, $urow['id'], 11);   // one extra tells us whether to keep loading on scroll
        $feedMore = (count($feed) > 10);
        $feed = array_slice($feed, 0, 10);
    }
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
    <link rel="icon" href="favicon.svg" type="image/svg+xml">
    <link rel="icon" href="favicon.ico" sizes="any">
    <link rel="apple-touch-icon" href="apple-touch-icon.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:wght@600;700;800&family=Instrument+Sans:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="style.css?v=<?php echo (int)@filemtime(__DIR__ . '/style.css'); ?>">
</head>
<body>

<header class="topbar">
    <?php include("logo.php"); ?>

    <div class="user">
        <nav class="tb-nav" aria-label="Main">
            <a href="dashboard" class="tb-link is-active" aria-current="page">Home</a>
            <a href="clubs" class="tb-link">Clubs</a>
            <a href="projects" class="tb-link">Projects</a>
            <a href="events" class="tb-link">Events</a>
            <a href="students" class="tb-link">Students</a>
            <?php if ($isAdmin) { ?><a href="admin-verifications" class="tb-link">Admin</a><?php } ?>
        </nav>
        <?php include("msg-icon.php"); ?>
        <?php include("bell.php"); ?>
        <?php include("profile-menu.php"); ?>
    </div>
</header>
<?php $rtUserId = $urow ? $urow['id'] : 0; include("realtime.php"); ?>

<main class="main home-page">

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
                <section class="panel center hf-empty" style="margin-top:0">
                    <h2>Your feed starts with a club</h2>
                    <p class="empty">Join a club and its announcements and events will show up here, including the members-only ones.</p>
                    <p style="margin-top:18px"><a href="clubs" class="btn-sm btn-dark">Browse clubs</a></p>
                </section>
            <?php } elseif (!$feed) { ?>
                <section class="panel center hf-empty" style="margin-top:0">
                    <h2>You're all caught up</h2>
                    <p class="empty">New announcements and events from your clubs will appear here.</p>
                </section>
            <?php } else { ?>
                <div class="hf-list" id="hfList">
                    <?php foreach ($feed as $it) { render_feed_item($it); } ?>
                </div>
                <p class="empty hf-none" id="hfNone" hidden>Nothing of that kind yet.</p>
                <div class="hf-end" id="hfEnd" aria-live="polite"></div>
                <script>
                // endless scroll: the next 10 posts load as you near the bottom; the tabs reload from the server
                (function () {
                    var list = document.getElementById("hfList"),
                        none = document.getElementById("hfNone"),
                        end  = document.getElementById("hfEnd"),
                        btns = document.querySelectorAll("[data-filter]"),
                        offset = list.querySelectorAll(".hf").length,
                        type = "all", done = <?php echo $feedMore ? 'false' : 'true'; ?>,
                        loading = false, gen = 0, io = null;

                    function status() {
                        if (loading) { end.textContent = "Loading…"; return; }
                        end.textContent = (done && offset > 0) ? "You're all caught up" : "";
                    }

                    function load() {
                        if (loading || done) return;
                        loading = true; status();
                        var g = gen;
                        fetch("feed-more?offset=" + offset + "&type=" + type, { credentials: "same-origin" })
                            .then(function (r) { if (!r.ok) throw new Error(); return r.json(); })
                            .then(function (d) {
                                if (g !== gen) return;                        // the tab changed while this was loading
                                var before = list.querySelectorAll(".hf").length;
                                list.insertAdjacentHTML("beforeend", d.html || "");
                                offset += list.querySelectorAll(".hf").length - before;
                                done = !d.more || list.querySelectorAll(".hf").length === before;
                                loading = false;
                                none.hidden = offset > 0;
                                status();
                                recheck();
                            })
                            .catch(function () {
                                if (g !== gen) return;
                                loading = false;
                                end.innerHTML = 'Couldn\u2019t load more. <button type="button" class="hf-retry">Try again</button>';
                            });
                    }

                    function recheck() {                                       // fires again if the end is still on screen
                        if (!io) return;
                        io.unobserve(end); io.observe(end);
                    }

                    end.addEventListener("click", function (e) {
                        if (e.target.classList && e.target.classList.contains("hf-retry")) { status(); load(); }
                    });

                    btns.forEach(function (b) {
                        b.addEventListener("click", function () {
                            var t = b.getAttribute("data-filter");
                            if (t === type) return;
                            type = t; gen++; offset = 0; done = false; loading = false;
                            btns.forEach(function (x) { x.classList.toggle("on", x === b); });
                            list.innerHTML = ""; none.hidden = true; end.textContent = "";
                            window.scrollTo(0, Math.min(window.pageYOffset, list.getBoundingClientRect().top + window.pageYOffset - 200));
                            load();
                        });
                    });

                    if ("IntersectionObserver" in window) {
                        io = new IntersectionObserver(function (entries) {
                            if (entries[0].isIntersecting) load();
                        }, { rootMargin: "600px 0px" });
                        io.observe(end);
                    }
                    status();
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
                                <span><strong><?php echo name_badge($rq); ?></strong><em>wants to join <?php echo e($rq['club_name']); ?></em></span>
                            </a>
                            <form method="POST" action="club" class="req-btns">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="club_id" value="<?php echo (int)$rq['club_id']; ?>">
                                <input type="hidden" name="member_id" value="<?php echo (int)$rq['mid']; ?>">
                                <input type="hidden" name="back" value="dashboard">
                                <button type="submit" name="action" value="accept" class="btn-sm btn-approve">Accept</button>
                                <button type="submit" name="action" value="decline" class="btn-sm btn-reject">Decline</button>
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
                    <span class="feat-flag"><?php echo event_icon('star'); ?>Featured club</span>
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

            <?php if ($myClubs) { ?>
                <section class="panel rail-mine">
                    <div class="rail-head">
                        <h2>Your clubs</h2>
                        <a href="clubs">All clubs</a>
                    </div>
                    <?php foreach (array_slice($myClubs, 0, 10) as $mc) {
                        $role = (string)($mc['role'] ?? 'member');
                        $roleLabel = ($role === 'owner') ? 'Owner' : (($role === 'officer') ? 'Officer' : '');
                    ?>
                        <a class="rail-club" href="club?id=<?php echo (int)$mc['id']; ?>">
                            <?php echo club_tile_html($mc, '', 'span'); ?>
                            <span><strong><?php echo e($mc['name']); ?></strong><em><?php echo $roleLabel !== '' ? e($roleLabel) . ' &middot; ' : ''; ?><?php echo e($mc['category']); ?></em></span>
                        </a>
                    <?php } ?>
                    <?php if (count($myClubs) > 10) { ?>
                        <a class="wk-more" href="clubs">+ <?php echo count($myClubs) - 10; ?> more</a>
                    <?php } ?>
                </section>
            <?php } ?>

            <?php if ($freshProjects) { ?>
                <section class="panel rail-pj">
                    <div class="rail-head">
                        <h2>Looking for teammates</h2>
                        <a href="projects">All projects</a>
                    </div>
                    <?php foreach (array_slice($freshProjects, 0, 5) as $fp) {
                        $left = project_open_spots($fp['roles'] ?? array());
                    ?>
                        <a class="rail-pj-row" href="project?id=<?php echo (int)$fp['id']; ?>">
                            <span class="pj-mini-ic t<?php echo project_tone($fp['id']); ?><?php echo project_tile_class($fp); ?>"><?php echo project_tile_inner($fp); ?></span>
                            <span class="rail-pj-txt">
                                <strong><?php echo e($fp['title']); ?></strong>
                                <em><?php echo e($fp['category']); ?><?php if ($left > 0) { echo ' &middot; ' . $left . ' spot' . ($left === 1 ? '' : 's') . ' open'; } ?></em>
                            </span>
                        </a>
                    <?php } ?>
                    <?php if (count($freshProjects) > 5) { $pjMore = count($freshProjects) - 5; ?>
                        <a class="wk-more" href="projects">+ <?php echo $pjMore >= 45 ? '45+' : $pjMore; ?> more</a>
                    <?php } ?>
                </section>
            <?php } ?>

            <?php if ($clubCount > 0 || $week) {
                $wkTotal = 0;
                foreach ($week as $wd) { $wkTotal += count($wd['events']); }
                $wkShown = 0;
                $wkMax = 5;
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
                            $dayText = !empty($day['is_today']) ? 'Today' : (($day['label'] === 'Tomorrow') ? 'Tomorrow' : $day['date']);
                            foreach ($day['events'] as $we) {
                                if ($wkShown >= $wkMax) break;
                                $wkShown++;
                                $wm = (($we['visibility'] ?? 'public') === 'members');
                        ?>
                            <a class="rail-pj-row wk-row" href="event?id=<?php echo (int)$we['id']; ?>">
                                <?php $wkD = event_dt($we['start_at']); ?>
                                <span class="date-badge" aria-hidden="true"><small><?php echo e($wkD->format('M')); ?></small><b><?php echo e($wkD->format('j')); ?></b></span>
                                <span class="rail-pj-txt">
                                    <strong><?php echo e($we['title']); ?></strong>
                                    <em><?php echo e($dayText); ?> &middot; <?php echo e(event_dt($we['start_at'])->format('g:i A')); ?> &middot; <?php echo e($we['club_name']); ?></em>
                                </span>
                                <?php if ($wm) { ?><span class="wk-lock" title="Members only"><?php echo event_icon('lock'); ?></span><?php } ?>
                                <?php if ((int)$we['me_going'] > 0) { ?><span class="wk-going" title="You're going"><?php echo event_icon('check'); ?></span><?php } ?>
                            </a>
                        <?php } } ?>
                        </div>
                        <?php if ($wkTotal > $wkShown) { ?>
                            <a class="wk-more" href="events">+ <?php echo $wkTotal - $wkShown; ?> more this week</a>
                        <?php } ?>
                    <?php } ?>
                </section>
            <?php } ?>

            <?php if ($discover) { ?>
                <section class="panel rail-disc">
                    <div class="rail-head">
                        <h2>Discover clubs</h2>
                        <a href="clubs">Browse all</a>
                    </div>
                    <?php foreach (array_slice($discover, 0, 5) as $dc) { $mc = (int)$dc['members']; ?>
                        <a class="rail-club" href="club?id=<?php echo (int)$dc['id']; ?>">
                            <?php echo club_tile_html($dc, '', 'span'); ?>
                            <span><strong><?php echo e($dc['name']); ?></strong><em><?php echo e($dc['category']); ?> &middot; <?php echo $mc; ?> member<?php echo $mc === 1 ? '' : 's'; ?></em></span>
                        </a>
                    <?php } ?>
                    <?php if (count($discover) > 5) { $dcMore = count($discover) - 5; ?>
                        <a class="wk-more" href="clubs">+ <?php echo $dcMore >= 45 ? '45+' : $dcMore; ?> more</a>
                    <?php } ?>
                </section>
            <?php } ?>

        </aside>
        <?php } ?>
    </div>

</main>

<script>
// the feed header and the side rail stick just under the top bar, whatever its height
(function () {
    var t = document.querySelector(".topbar");
    function set() { if (t) document.documentElement.style.setProperty("--topbar-h", t.offsetHeight + "px"); }
    set();
    window.addEventListener("resize", set);
    window.addEventListener("load", set);
})();

// "Needs your attention": Accept / Decline reloads the page, so remember the scroll and put it back
(function () {
    var KEY = "dash-scroll";
    function read() { try { return sessionStorage.getItem(KEY); } catch (e) { return null; } }
    function clear() { try { sessionStorage.removeItem(KEY); } catch (e) {} }

    document.addEventListener("submit", function (e) {
        if (!e.target.closest || !e.target.closest(".rail-req")) return;
        try { sessionStorage.setItem(KEY, String(Math.round(window.pageYOffset))); } catch (err) {}
    });

    var y = parseInt(read(), 10);
    if (!isNaN(y) && y > 0) {
        clear();
        if ("scrollRestoration" in history) history.scrollRestoration = "manual";
        var tries = 0;
        (function go() {
            window.scrollTo(0, y);
            // the feed can still be growing; keep trying briefly until we land
            if (Math.abs(window.pageYOffset - y) > 2 && tries++ < 15) setTimeout(go, 100);
        })();
    }
})();
</script>

</body>
</html>