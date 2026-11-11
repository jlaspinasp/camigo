<?php
// Home feed ("From your clubs"). Load after auth.php and events-lib.php.

function feed_kinds() {
    return array(
        'announcement' => array('Announcement', 'megaphone'),
        'reminder'     => array('Reminder', 'bell'),
        'important'    => array('Important', 'alert'),
    );
}

// Newest announcements and events from the clubs this person is an active member of.
// Members-only items are included, because the person is in those clubs.
function club_feed($conn, $userId, $limit, $offset = 0, $type = 'all') {
    $items = array();
    $need  = (int)$limit + (int)$offset;   // each source is fetched far enough to fill this page after merging

    // announcements (works before and after club-announcements.sql)
    if ($type !== 'event') {
    $cc = mysqli_query($conn, "SHOW COLUMNS FROM club_posts LIKE 'kind'");
    $ready = ($cc && mysqli_num_rows($cc) > 0);
    $cols = $ready ? ", p.title, p.kind, p.visibility" : "";

    $pr = db_run($conn,
        "SELECT p.id, p.club_id, p.body, UNIX_TIMESTAMP(p.created_at) AS ts" . $cols . ",
                c.name AS club_name" . (club_identity_ready($conn) ? ", c.logo AS club_logo" : "") . ", u.username, u.first_name, u.last_name, u.photo
         FROM club_posts p
         JOIN clubs c ON c.id = p.club_id AND c.status = 'approved'
         JOIN club_members m ON m.club_id = p.club_id AND m.user_id = ? AND m.status = 'active'
         LEFT JOIN users u ON u.id = p.user_id
         ORDER BY p.created_at DESC LIMIT " . $need,
        "i", array((int)$userId));
    foreach ($pr['rows'] as $r) { $r['type'] = 'post'; $items[] = $r; }
    }

    // events that are still ahead, newest posted first
    if ($type !== 'post') {
    $er = events_query($conn, $userId,
        EVENT_END_SQL . " >= ? AND e.club_id IN (SELECT club_id FROM club_members WHERE user_id = ? AND status = 'active')",
        "si", array(event_now_sql(), (int)$userId), "e.created_at DESC", $need);
    foreach ($er['rows'] as $r) { $r['type'] = 'event'; $r['ts'] = (int)($r['created_ts'] ?? 0); $items[] = $r; }
    }

    usort($items, function ($a, $b) { return (int)$b['ts'] <=> (int)$a['ts']; });
    return array_slice($items, (int)$offset, (int)$limit);
}

// one card in the feed
function render_feed_item($it) {
    $clubId = (int)$it['club_id'];
    $tile   = 't' . ($clubId % 3);
    $when   = time_ago($it['ts']);

    if ($it['type'] === 'post') {
        $kinds = feed_kinds();
        $kind  = $it['kind'] ?? 'announcement';
        if (!isset($kinds[$kind])) $kind = 'announcement';
        $members = (($it['visibility'] ?? 'public') === 'members');
        $title   = (string)($it['title'] ?? '');
        // keep paragraph breaks, tidy the rest; the card clamps it and "See more" opens the rest
        $text    = str_replace(array("\r\n", "\r"), "\n", (string)$it['body']);
        $text    = preg_replace('/[ \t]+/u', ' ', $text);
        $text    = preg_replace('/\n{3,}/', "\n\n", $text);
        $text    = str_cut(trim($text), 1500);
        $link    = 'club?id=' . $clubId . '#updates';
        ?>
        <article class="hf" data-type="post">
            <a class="hf-open" href="<?php echo e($link); ?>" aria-label="Open this announcement in <?php echo e($it['club_name']); ?>"></a>
            <div class="hf-head">
                <a class="hf-club" href="club?id=<?php echo $clubId; ?>">
                    <?php echo club_tile_html(array('id' => $clubId, 'name' => $it['club_name'], 'logo' => $it['club_logo'] ?? null), 'sm', 'span'); ?>
                    <span><strong><?php echo e($it['club_name']); ?></strong><em>
                        <span class="hf-kind k-<?php echo e($kind); ?>"><?php echo event_icon($kinds[$kind][1]); ?><?php echo e($kinds[$kind][0]); ?></span>
                        <span class="hf-dot">&middot;</span><?php echo e($when); ?>
                        <?php if ($members) { ?><span class="hf-dot">&middot;</span><span class="hf-note"><?php echo event_icon('lock'); ?>Members only</span><?php } ?>
                    </em></span>
                </a>
            </div>
            <?php if ($title !== '') { ?><h3 class="hf-title"><a href="<?php echo e($link); ?>"><?php echo e($title); ?></a></h3><?php } ?>
            <p class="hf-text" data-hf-text><?php echo e($text); ?></p>
            <div class="hf-foot">
                <?php if (user_gone($it)) { ?>
                <span class="hf-by">
                    <span class="faces"><span class="face"><?php echo avatar_inner($it); ?></span></span>
                    <span>By <?php echo e(full_name($it)); ?></span>
                </span>
                <?php } else { ?>
                <span class="hf-by">
                    <a class="hf-author" href="profile?u=<?php echo e(urlencode($it['username'])); ?>"><span class="faces"><span class="face"><?php echo avatar_inner($it); ?></span></span></a>
                    <span>By <a class="hf-author" href="profile?u=<?php echo e(urlencode($it['username'])); ?>"><?php echo e(full_name($it)); ?></a></span>
                </span>
                <?php } ?>
            </div>
        </article>
        <?php
        return;
    }

    // event
    $id      = (int)$it['id'];
    $state   = event_state($it);
    $s       = event_dt($it['start_at']);
    $going   = (int)$it['going'];
    $cap     = ($it['capacity'] !== null) ? (int)$it['capacity'] : 0;
    $full    = ($cap > 0 && $going >= $cap);
    $meGoing = ((int)$it['me_going'] > 0);
    $members = (($it['visibility'] ?? 'public') === 'members');
    $soon    = event_when_label($it);
    $faces   = $it['faces'] ?? array();
    $desc    = str_replace(array("\r\n", "\r"), "\n", trim((string)($it['description'] ?? '')));
    $desc    = preg_replace('/\s*\n\s*/u', ' ', $desc);
    $desc    = str_cut($desc, 400);
    ?>
    <article class="hf" data-type="event">
        <a class="hf-open" href="event?id=<?php echo $id; ?>" aria-label="Open event: <?php echo e($it['title']); ?>"></a>
        <div class="hf-head">
            <a class="hf-club" href="club?id=<?php echo $clubId; ?>">
                <?php echo club_tile_html(array('id' => $clubId, 'name' => $it['club_name'], 'logo' => $it['club_logo'] ?? null), 'sm', 'span'); ?>
                <span><strong><?php echo e($it['club_name']); ?></strong><em>
                    New event <span class="hf-dot">&middot;</span><?php echo e($when); ?>
                    <?php if ($members) { ?><span class="hf-dot">&middot;</span><span class="hf-note"><?php echo event_icon('lock'); ?>Members only</span><?php } ?>
                    <?php if ($state === 'live') { ?><span class="hf-dot">&middot;</span><span class="hf-note hf-live">Live now</span>
                    <?php } elseif ($soon !== '') { ?><span class="hf-dot">&middot;</span><span class="hf-note"><?php echo e($soon); ?></span><?php } ?>
                </em></span>
            </a>
        </div>

        <div class="hf-event">
            <a class="date-badge" href="event?id=<?php echo $id; ?>" aria-label="<?php echo e($s->format('l, F j')); ?>">
                <small><?php echo e($s->format('M')); ?></small>
                <b><?php echo e($s->format('j')); ?></b>
            </a>
            <div class="hf-ev-main">
                <h3 class="hf-title"><a href="event?id=<?php echo $id; ?>"><?php echo e($it['title']); ?></a></h3>
                <div class="ev-meta">
                    <span><?php echo event_icon('clock'); ?><?php echo e($s->format('D') . ' · ' . event_time_range($it)); ?></span>
                    <span><?php echo event_icon('pin'); ?><?php echo e($it['location']); ?></span>
                </div>
                <?php if ($desc !== '') { ?><p class="hf-text hf-ev-desc"><?php echo e($desc); ?></p><?php } ?>
            </div>
        </div>

        <div class="hf-foot">
            <div class="ev-going">
                <?php if ($faces) { ?>
                    <span class="faces"><?php foreach ($faces as $f) { ?><span class="face"><?php echo avatar_inner($f); ?></span><?php } ?></span>
                <?php } ?>
                <span><?php echo $going === 0 ? 'Be the first to join' : $going . ($cap > 0 ? ' / ' . $cap : '') . ' going'; ?></span>
            </div>
        </div>
    </article>
    <?php
}

// ================= dashboard side rail =================

// Events in the next 7 days from my clubs (members-only included) or that I RSVP'd to, grouped by day.
function rail_week($conn, $userId) {
    $end = event_now();
    $end->modify('+7 days');
    $r = events_query($conn, $userId,
        "e.start_at < ? AND " . EVENT_END_SQL . " >= ? AND (e.club_id IN (SELECT club_id FROM club_members WHERE user_id = ? AND status = 'active') OR e.id IN (SELECT event_id FROM event_rsvps WHERE user_id = ?))",
        "ssii", array($end->format('Y-m-d H:i:s'), event_now_sql(), (int)$userId, (int)$userId),
        "e.start_at ASC", 50);

    $today = event_now()->format('Y-m-d');
    $tom = event_now(); $tom->modify('+1 day'); $tomorrow = $tom->format('Y-m-d');

    $days = array();
    foreach ($r['rows'] as $ev) {
        $d = event_dt($ev['start_at']);
        $key = $d->format('Y-m-d');
        if (!isset($days[$key])) {
            $label = ($key === $today) ? 'Today' : (($key === $tomorrow) ? 'Tomorrow' : $d->format('l'));
            $days[$key] = array('label' => $label, 'date' => $d->format('M j'), 'dow' => ($key === $today) ? 'Today' : $d->format('D'), 'num' => $d->format('j'), 'is_today' => ($key === $today), 'events' => array());
        }
        $days[$key]['events'][] = $ev;
    }
    return $days;
}

// Join requests waiting on clubs I own.
function rail_requests($conn, $userId) {
    $r = db_run($conn,
        "SELECT m.id AS mid, m.club_id, c.name AS club_name,
                u.username, u.first_name, u.last_name, u.course, u.year_level, u.photo
         FROM club_members m
         JOIN clubs c ON c.id = m.club_id AND c.status = 'approved'
         JOIN club_members o ON o.club_id = m.club_id AND o.user_id = ? AND o.role = 'owner' AND o.status = 'active'
         JOIN users u ON u.id = m.user_id
         WHERE m.status = 'pending'
         ORDER BY m.joined_at ASC LIMIT 20",
        "i", array((int)$userId));
    return $r['rows'];
}

// A few clubs I haven't joined, favouring the categories of the clubs I'm already in.
function rail_discover($conn, $userId, $myCategories) {
    $cats = array_values(array_unique(array_filter($myCategories)));
    $types = "i";
    $params = array((int)$userId);
    $order = "members DESC";
    if ($cats) {
        $order = "(c.category IN (" . implode(',', array_fill(0, count($cats), '?')) . ")) DESC, members DESC";
        $types .= str_repeat("s", count($cats));
        $params = array_merge($params, $cats);
    }
    $r = db_run($conn,
        "SELECT c.id, c.name, c.category" . (club_identity_ready($conn) ? ", c.logo" : "") . ",
                (SELECT COUNT(*) FROM club_members x WHERE x.club_id = c.id AND x.status = 'active') AS members
         FROM clubs c
         WHERE c.status = 'approved'" . (club_identity_ready($conn) ? " AND c.invite_only = 0" : "") . " AND c.id NOT IN (SELECT club_id FROM club_members WHERE user_id = ?)
         ORDER BY " . $order . ", c.id DESC LIMIT 50",
        $types, $params);
    return $r['rows'];
}


// One club picked for the day and shown to everyone who hasn't joined it.
// The seed is today's date, so it stays the same all day and changes tomorrow.
function rail_featured($conn, $userId) {
    $seed = (int)date('Ymd');
    $r = db_run($conn,
        "SELECT c.id, c.name, c.category, c.description" . (club_identity_ready($conn) ? ", c.logo" : "") . ",
                (SELECT COUNT(*) FROM club_members x WHERE x.club_id = c.id AND x.status = 'active') AS members
         FROM clubs c
         WHERE c.status = 'approved'" . (club_identity_ready($conn) ? " AND c.invite_only = 0" : "") . " AND c.id NOT IN (SELECT club_id FROM club_members WHERE user_id = ?)
         ORDER BY RAND(" . $seed . ") LIMIT 1",
        "i", array((int)$userId));
    if (empty($r['rows'])) return null;
    $club = $r['rows'][0];
    $txt = trim((string)($club['description'] ?? ''));
    if (function_exists('mb_strimwidth')) {
        $club['blurb'] = mb_strimwidth($txt, 0, 130, '...', 'UTF-8');
    } else {
        $club['blurb'] = (strlen($txt) > 130) ? substr($txt, 0, 127) . '...' : $txt;
    }
    return $club;
}