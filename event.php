<?php
session_start();
require("db.php");
require("auth.php");
require("events-lib.php");

$me  = require_user_row($conn);
$eid = (int)($_GET['id'] ?? $_POST['event_id'] ?? 0);

// ================= ACTIONS (RSVP) =================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $action = $_POST['action'] ?? '';
    $back   = event_safe_back($_POST['back'] ?? '', 'event?id=' . $eid);

    if (!csrf_ok()) {
        flash_set("Your session expired. Please try again.", "error");

    } elseif ($action === 'rsvp' || $action === 'unrsvp') {
        $r = event_rsvp($conn, $eid, $me['id'], $action === 'rsvp');
        flash_set($r[0], $r[1]);

    }

    header("Location: " . $back);
    exit();
}

// ================= THE EVENT =================
$er = db_run($conn,
    "SELECT e.id, e.club_id, e.created_by, e.title, e.description, e.location, e.start_at, e.end_at, e.capacity, e.status, e.visibility,
            hu.username AS host_username, hu.first_name AS host_first, hu.last_name AS host_last, hu.photo AS host_photo,
            c.name AS club_name" . (club_identity_ready($conn) ? ", c.logo AS club_logo, c.cover AS club_cover" : "") . (events_banner_ready($conn) ? ", e.banner" : "") . ", c.category AS club_category
     FROM events e LEFT JOIN clubs c ON c.id = e.club_id LEFT JOIN users hu ON hu.id = e.created_by
     WHERE e.id = ? AND (e.club_id IS NULL OR c.status = 'approved') LIMIT 1", "i", array($eid));
$setupMissing = !$er['ok'];
$ev = $er['rows'] ? $er['rows'][0] : null;

$myRole   = $ev ? club_role($conn, $ev['club_id'], $me['id']) : null;
$isOwner   = ($myRole === 'owner');
$isStaff   = ($myRole === 'owner' || $myRole === 'officer');
$canCancel = $ev ? event_can_cancel($conn, $ev, $me['id']) : false;
$noClub    = $ev && empty($ev['club_id']);
$isHosting = $isStaff || ($noClub && (int)$ev['created_by'] === (int)$me['id']);
$isMember  = $ev ? event_is_club_member($conn, $ev['club_id'], $me['id']) : false;
$exclusive = $ev && !$noClub && ($ev['visibility'] === 'members');
$locked    = $exclusive && !$isMember;   // members-only and the viewer is not in the club
$cancelled = $ev && $ev['status'] !== 'active';
$state     = $ev ? event_state($ev) : 'over';
$isOver    = ($state === 'over');

// ---- add to calendar (.ics file) ----
if ($ev && !$locked && !$cancelled && isset($_GET['ics'])) {
    $utc  = new DateTimeZone('UTC');
    $s = event_dt($ev['start_at']); $s->setTimezone($utc);
    $f = event_end_dt($ev);         $f->setTimezone($utc);

    $esc = function ($t) { return str_replace(array('\\', ';', ',', "\r\n", "\n"), array('\\\\', '\\;', '\\,', '\\n', '\\n'), (string)$t); };
    $fold = function ($line) {
        $chars = preg_split('//u', $line, -1, PREG_SPLIT_NO_EMPTY);
        $out = ''; $n = 0;
        foreach ($chars as $ch) {
            if ($n >= 60) { $out .= "\r\n "; $n = 0; }
            $out .= $ch; $n++;
        }
        return $out;
    };

    $lines = array(
        'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Camigo//Events//EN', 'CALSCALE:GREGORIAN', 'BEGIN:VEVENT',
        'UID:event-' . (int)$ev['id'] . '@camigo',
        'DTSTAMP:' . gmdate('Ymd\THis\Z'),
        'DTSTART:' . $s->format('Ymd\THis\Z'),
        'DTEND:' . $f->format('Ymd\THis\Z'),
        'SUMMARY:' . $esc($ev['title']),
        'LOCATION:' . $esc($ev['location']),
        'DESCRIPTION:' . $esc((!empty($ev['club_name']) ? $ev['club_name'] . ' - ' : '') . $ev['description']),
        'END:VEVENT', 'END:VCALENDAR',
    );
    header('Content-Type: text/calendar; charset=utf-8');
    header('Content-Disposition: attachment; filename="camigo-event-' . (int)$ev['id'] . '.ics"');
    echo implode("\r\n", array_map($fold, $lines)) . "\r\n";
    exit();
}

// ---- who's going ----
$going = 0;
$meGoing = false;
$attendees = array();
$hiddenCount = 0;
$guestsPrivate = ($ev && !$isMember && ($me['role'] ?? 'user') !== 'admin' && club_is_invite_only($conn, $ev['club_id']));
if ($ev && !$locked) {
    $ar = db_run($conn,
        "SELECT u.id, u.username, u.first_name, u.last_name, u.course, u.year_level, u.photo, u.profile_visibility
         FROM event_rsvps r JOIN users u ON u.id = r.user_id
         WHERE r.event_id = ? ORDER BY r.created_at ASC LIMIT 300", "i", array($eid));
    $all = $ar['rows'];
    $cnt = db_run($conn, "SELECT COUNT(*) AS n FROM event_rsvps WHERE event_id = ?", "i", array($eid));
    $going = ($cnt['ok'] && $cnt['rows']) ? (int)$cnt['rows'][0]['n'] : count($all);

    foreach ($all as $a) {
        $isMe = ((int)$a['id'] === (int)$me['id']);
        if ($isMe) $meGoing = true;
        $public = (empty($a['profile_visibility']) || $a['profile_visibility'] === 'public');
        // people with a private profile are only listed to themselves and the club owner
        if ($public || $isMe || $isOwner) $attendees[] = $a;
    }
    if ($guestsPrivate) $attendees = array();
    $hiddenCount = max(0, $going - count($attendees));
}

$cap  = ($ev && $ev['capacity'] !== null) ? (int)$ev['capacity'] : 0;
$full = ($cap > 0 && $going >= $cap);
$pct  = $cap > 0 ? min(100, (int)round($going / $cap * 100)) : 0;
$left = $cap > 0 ? max(0, $cap - $going) : 0;

$flash = flash_get();
$message = $flash ? $flash[0] : "";
$message_class = $flash ? $flash[1] : "";

// Google Calendar link
$gcal = '';
if ($ev && !$locked) {
    $u = new DateTimeZone('UTC');
    $gs = event_dt($ev['start_at']); $gs->setTimezone($u);
    $ge = event_end_dt($ev);         $ge->setTimezone($u);
    $gcal = 'https://calendar.google.com/calendar/render?' . http_build_query(array(
        'action'   => 'TEMPLATE',
        'text'     => $ev['title'],
        'dates'    => $gs->format('Ymd\THis\Z') . '/' . $ge->format('Ymd\THis\Z'),
        'details'  => str_cut($ev['description'], 500),
        'location' => $ev['location'],
    ));
}

$selfBack = 'event?id=' . $eid;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo ($ev && !$locked) ? e($ev['title']) . ' | ' : ($locked ? 'Members-only event | ' : ''); ?>Event | Camigo</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:wght@600;700;800&family=Instrument+Sans:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
</head>
<body>

<?php include("topbar.php"); ?>

<main class="main">

    <?php if ($message != "") { ?>
        <div class="msg <?php echo e($message_class); ?>"><?php echo e($message); ?></div>
    <?php } ?>

    <?php if ($setupMissing) { ?>

        <div class="msg error">Events aren't fully set up yet. Run <strong>events.sql</strong>, then <strong>events-members-only.sql</strong> in phpMyAdmin.</div>

    <?php } elseif (!$ev) { ?>

        <section class="panel center">
            <h2>Event not found</h2>
            <p class="empty">This event doesn't exist, or it isn't available. <a href="events">Browse events</a></p>
        </section>

    <?php } elseif ($locked) { ?>

        <p class="crumb"><a href="events">&larr; All events</a></p>

        <section class="panel center ev-locked">
            <div class="ev-lock-ic"><?php echo event_icon('lock'); ?></div>
            <span class="badge members"><?php echo event_icon('lock'); ?>Members only</span>
            <h2>This event is just for club members</h2>
            <p class="empty">It's hosted by <strong><?php echo e($ev['club_name']); ?></strong> and only their members can see the details or join. Join the club to get in.</p>
            <div class="ev-locked-actions">
                <a href="club?id=<?php echo (int)$ev['club_id']; ?>" class="btn-sm btn-dark">View <?php echo e($ev['club_name']); ?></a>
                <a href="events" class="btn-sm btn-ghost">Browse public events</a>
            </div>
        </section>

    <?php } else {
        $s = event_dt($ev['start_at']);
        $when = event_when_label($ev);
        $heroImg = event_image_url($ev);   // own banner, else the club's banner, else '' (a color is used)
    ?>

        <p class="crumb"><a href="events">&larr; All events</a></p>

        <section class="ev-hero<?php echo $exclusive ? ' is-exclusive' : ''; ?><?php echo $heroImg !== '' ? ' has-banner' : ''; ?>" data-g="<?php echo event_gradient($ev); ?>"<?php if ($heroImg !== '') { ?> style="--ev-banner:url('<?php echo e($heroImg); ?>')"<?php } ?>>
            <div class="ev-badges">
                <?php if ($noClub) { ?>
                    <?php $ht = event_host_tags($ev, 'chip'); echo $ht[0]; ?>Hosted by <?php echo e(event_host_name($ev)); ?><?php echo $ht[1]; ?>
                <?php } else { ?>
                    <a class="chip" href="club?id=<?php echo (int)$ev['club_id']; ?>"><?php echo e($ev['club_name']); ?></a>
                <?php } ?>
                <?php if ($exclusive) { ?><span class="badge members"><?php echo event_icon('lock'); ?>Members only</span><?php } ?>
                <?php if ($cancelled) { ?><span class="badge rejected">Cancelled</span>
                <?php } elseif ($state === 'live') { ?><span class="badge live">Live now</span>
                <?php } elseif ($isOver) { ?><span class="badge ended">Ended</span>
                <?php } elseif ($when !== '') { ?><span class="badge soon"><?php echo e($when); ?></span><?php } ?>
                <?php if ($isHosting) { ?><span class="badge approved">You're hosting</span><?php } ?>
            </div>

            <h1><?php echo e($ev['title']); ?></h1>

            <div class="ev-facts">
                <div class="fact"><span class="fi"><?php echo event_icon('calendar'); ?></span><div><small>Date</small><strong><?php echo e(event_date_long($ev)); ?></strong></div></div>
                <div class="fact"><span class="fi"><?php echo event_icon('clock'); ?></span><div><small>Time</small><strong><?php echo e(event_time_range($ev)); ?></strong></div></div>
                <div class="fact"><span class="fi"><?php echo event_icon('pin'); ?></span><div><small>Where</small><strong><?php echo e($ev['location']); ?></strong></div></div>
            </div>

            <?php if ($isHosting || $canCancel) { ?>
                <div class="ev-owner">
                    <?php if ($isHosting) { ?>
                        <a href="edit-event?id=<?php echo $eid; ?>" class="btn-sm btn-light">Edit event</a>
                    <?php } ?>
                </div>
            <?php } ?>
        </section>

        <div class="ev-layout">

            <div class="ev-main">
                <section class="panel" style="margin-top:0">
                    <h2>About this event</h2>
                    <p class="ev-about"><?php echo nl2br(e($ev['description'])); ?></p>
                </section>

                <section class="panel">
                    <h2><?php echo $isOver ? "Who went" : "Who's going"; ?> (<?php echo $going; ?>)</h2>

                    <?php if ($going === 0) { ?>
                        <p class="empty"><?php echo $isOver ? "Nobody joined this event." : "No one yet. Be the first."; ?></p>
                    <?php } else { ?>
                        <?php if ($attendees) { ?>
                            <div class="member-list">
                                <?php foreach ($attendees as $a) {
                                    $line = array();
                                    if (!empty($a['course'])) $line[] = $a['course'];
                                    if (!empty($a['year_level'])) $line[] = $a['year_level'];
                                    $link = ($a['username'] === $me['username']) ? 'profile' : 'profile?u=' . urlencode($a['username']);
                                ?>
                                    <div class="member">
                                        <div class="p-avatar tiny"><?php echo avatar_inner($a); ?></div>
                                        <div class="grow">
                                            <strong><a href="<?php echo e($link); ?>" class="plain"><?php echo e(full_name($a)); ?></a></strong>
                                            <div class="club-meta">@<?php echo e($a['username']); ?><?php if ($line) { ?> &middot; <?php echo e(implode(' · ', $line)); ?><?php } ?></div>
                                        </div>
                                    </div>
                                <?php } ?>
                            </div>
                        <?php } ?>
                        <?php if ($hiddenCount > 0) { ?>
                            <p class="club-meta" style="margin-top:14px"><?php if ($guestsPrivate) { ?>The guest list is private to this club's members.<?php } else { echo $attendees ? '+ ' : ''; echo $hiddenCount; ?> other<?php echo $hiddenCount === 1 ? '' : 's'; ?> who keep their profile private.<?php } ?></p>
                        <?php } ?>
                    <?php } ?>
                </section>
            </div>

            <aside class="ev-side">
                <section class="panel ev-rsvp" style="margin-top:0">

                    <?php if ($cancelled) { ?>
                        <h2>This event was cancelled</h2>
                        <p class="empty">The organizers called it off. <a href="events">See other events</a></p>

                    <?php } elseif ($isOver) { ?>
                        <h2>This event has ended</h2>
                        <p class="empty"><?php echo $going; ?> <?php echo $going === 1 ? 'person' : 'people'; ?> went. <a href="events">See upcoming events</a></p>

                    <?php } else { ?>

                        <div class="ev-count">
                            <b><?php echo $going; ?></b>
                            <span><?php echo $cap > 0 ? 'of ' . $cap . ' spots taken' : ($going === 1 ? 'person going' : 'people going'); ?></span>
                        </div>

                        <?php if ($cap > 0) { ?>
                            <div class="progress ev-bar"><i style="width: <?php echo $pct; ?>%"></i></div>
                            <p class="club-meta" style="margin-top:8px"><?php echo $full ? 'This event is full.' : ($left === 1 ? 'Only 1 spot left.' : $left . ' spots left.'); ?></p>
                        <?php } ?>

                        <form method="POST" action="event" style="margin-top:18px">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="event_id" value="<?php echo $eid; ?>">
                            <input type="hidden" name="back" value="<?php echo e($selfBack); ?>">
                            <?php if ($meGoing) { ?>
                                <button type="submit" name="action" value="unrsvp" class="btn going-big"><?php echo event_icon('check'); ?>You're going</button>
                                <p class="club-meta" style="margin-top:8px;text-align:center">Plans changed? Press it again to cancel.</p>
                                <?php if ($state === 'upcoming') { $rp = $me['reminder_pref'] ?? 'going'; ?>
                                    <?php if ($rp === 'off') { ?>
                                        <p class="ev-remind off"><?php echo event_icon('bell'); ?><span>Reminders are off. <a href="notification-preferences#event-reminders">Turn them on</a> to get a nudge before this starts.</span></p>
                                    <?php } else { ?>
                                        <p class="ev-remind"><?php echo event_icon('bell'); ?><span>We'll remind you the day before and again about an hour before it starts.</span></p>
                                    <?php } ?>
                                <?php } ?>
                            <?php } elseif ($full) { ?>
                                <button type="button" class="btn" disabled style="opacity:.5;cursor:not-allowed">Event is full</button>
                            <?php } else { ?>
                                <button type="submit" name="action" value="rsvp" class="btn">I'm going</button>
                            <?php } ?>
                        </form>

                        <?php if ($exclusive) { ?>
                            <p class="ev-excl-note"><?php echo event_icon('lock'); ?><span>Only members of <?php echo e($ev['club_name']); ?> can see this event. Please don't share the details outside the club.</span></p>
                        <?php } ?>

                        <div class="ev-cal">
                            <small>Add to calendar</small>
                            <div>
                                <a href="event?id=<?php echo $eid; ?>&amp;ics=1" class="link-pill">Apple / Outlook</a>
                                <a href="<?php echo e($gcal); ?>" target="_blank" rel="noopener noreferrer" class="link-pill">Google</a>
                            </div>
                        </div>
                    <?php } ?>
                </section>

                <section class="panel ev-host">
                    <small>Hosted by</small>
                    <?php if ($noClub) { ?>
<?php $ht = event_host_tags($ev, 'ev-host-row'); echo $ht[0]; ?>
                            <span class="p-avatar tiny"><?php echo avatar_inner(array('username' => $ev['host_username'] ?? null, 'photo' => $ev['host_photo'] ?? null, 'first_name' => $ev['host_first'] ?? '?')); ?></span>
                            <span><strong><?php echo e(event_host_name($ev)); ?></strong><em>Student host</em></span>
                        <?php echo $ht[1]; ?>
                    <?php } else { ?>
                    <a href="club?id=<?php echo (int)$ev['club_id']; ?>" class="ev-host-row">
                        <?php echo club_tile_html(array('id' => $ev['club_id'], 'name' => $ev['club_name'], 'logo' => $ev['club_logo'] ?? null), '', 'span'); ?>
                        <span><strong><?php echo e($ev['club_name']); ?></strong><em><?php echo e($ev['club_category']); ?></em></span>
                    </a>
                    <?php } ?>
                </section>
            </aside>
        </div>

    <?php } ?>

</main>

</body>
</html>