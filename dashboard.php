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

// next events happening on campus
$soon = events_query($conn, $urow ? $urow['id'] : 0, EVENT_END_SQL . " >= ?", "s", array(event_now_sql()), "e.start_at ASC", 3);
$upcoming = $soon['rows'];

// ---- home feed: news from the clubs this person has joined ----
$myClubs = array();
$week = array();
$requests = array();
$discover = array();
$feed = array();
if ($urow) {
    $myClubs = db_run($conn,
        "SELECT c.id, c.name, c.category, m.role
         FROM club_members m JOIN clubs c ON c.id = m.club_id AND c.status = 'approved'
         WHERE m.user_id = ? AND m.status = 'active'
         ORDER BY (m.role = 'owner') DESC, (m.role = 'officer') DESC, c.name ASC LIMIT 50",
        "i", array($urow['id']))['rows'];

    $week     = rail_week($conn, $urow['id']);
    $requests = rail_requests($conn, $urow['id']);
    $discover = rail_discover($conn, $urow['id'], array_column($myClubs, 'category'));

    if ($myClubs) $feed = club_feed($conn, $urow['id'], 10);
}
$clubCount = count($myClubs);
$hasRail = ($clubCount > 0 || $week || $requests || $discover);

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

    <section class="hero">
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
                <script>
                (function () {
                    var btns = document.querySelectorAll("[data-filter]"),
                        items = document.querySelectorAll("#hfList .hf"),
                        none = document.getElementById("hfNone");
                    btns.forEach(function (b) {
                        b.addEventListener("click", function () {
                            var f = b.getAttribute("data-filter"), shown = 0;
                            btns.forEach(function (x) { x.classList.toggle("on", x === b); });
                            items.forEach(function (it) {
                                var ok = (f === "all" || it.getAttribute("data-type") === f);
                                it.hidden = !ok;
                                if (ok) shown++;
                            });
                            none.hidden = shown > 0;
                        });
                    });
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

            <?php if ($clubCount > 0 || $week) { ?>
                <section class="panel rail-week">
                    <div class="rail-head">
                        <h2>This week</h2>
                        <a href="events">All events</a>
                    </div>
                    <?php if (!$week) { ?>
                        <p class="empty">Nothing scheduled in the next 7 days.</p>
                    <?php } else { foreach ($week as $day) { ?>
                        <div class="wk-day"><b><?php echo e($day['label']); ?></b><span><?php echo e($day['date']); ?></span></div>
                        <?php foreach ($day['events'] as $we) {
                            $wm = (($we['visibility'] ?? 'public') === 'members');
                        ?>
                            <a class="wk-item" href="event?id=<?php echo (int)$we['id']; ?>">
                                <span class="wk-time"><?php echo e(event_dt($we['start_at'])->format('g:i A')); ?></span>
                                <span class="wk-main">
                                    <strong><?php echo e($we['title']); ?></strong>
                                    <em><?php if ($wm) { echo event_icon('lock'); } ?><?php echo e($we['club_name']); ?></em>
                                </span>
                                <?php if ((int)$we['me_going'] > 0) { ?><span class="wk-going" title="You're going"><?php echo event_icon('check'); ?></span><?php } ?>
                            </a>
                        <?php } ?>
                    <?php } } ?>
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
                            <span class="club-tile t<?php echo (int)$dc['id'] % 3; ?>"><?php echo e(first_letter($dc['name'])); ?></span>
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

    <?php if ($upcoming) { ?>
    <div class="strip-head">
        <h2 class="section-title">Upcoming events</h2>
        <a href="events">See all events</a>
    </div>
    <div class="ev-list">
        <?php foreach ($upcoming as $ue) { render_event_card($ue, 'dashboard'); } ?>
    </div>
    <?php } ?>

    <h2 class="section-title">What's coming</h2>

    <section class="tiles">
        <div class="tile">
            <div class="ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="8" r="3.5"/><path d="M2.5 20c0-3.5 3-5.5 6.5-5.5s6.5 2 6.5 5.5"/><circle cx="17" cy="9" r="2.5"/><path d="M17.5 14.5c2.5 0 4 1.5 4 4"/></svg></div>
            <h3>Clubs</h3>
            <p>Discover student organizations and join the ones you like.</p>
            <a href="clubs" class="go">Browse clubs</a>
        </div>
        <div class="tile">
            <div class="ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="16" rx="3"/><path d="M8 10l2.5 2.5L16 8"/></svg></div>
            <h3>Projects</h3>
            <p>Post your idea, find teammates, and build it together.</p>
            <span class="soon">Coming soon</span>
        </div>
        <div class="tile">
            <div class="ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="16" rx="3"/><path d="M3 10h18M8 3v4M16 3v4"/></svg></div>
            <h3>Events</h3>
            <p>See what's happening on campus this week.</p>
            <a href="events" class="go">Browse events</a>
        </div>
    </section>

</main>

</body>
</html>