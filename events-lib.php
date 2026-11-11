<?php
// Events: shared helpers. Load after auth.php (it needs db_run, e, csrf_field, avatar_inner, str_cut).

// Event times are entered and shown in campus time (Philippines), whatever the server's clock says.
define('EVENT_TZ', 'Asia/Manila');

// An event with no end time counts as over 3 hours after it starts.
define('EVENT_END_SQL', "COALESCE(e.end_at, DATE_ADD(e.start_at, INTERVAL 3 HOUR))");

// ---------- time ----------
function event_tz()      { return new DateTimeZone(EVENT_TZ); }
function event_now()     { return new DateTime('now', event_tz()); }
function event_now_sql() { return event_now()->format('Y-m-d H:i:s'); }
function event_dt($s)    { return new DateTime((string)$s, event_tz()); }

function event_end_dt($ev) {
    if (!empty($ev['end_at'])) return event_dt($ev['end_at']);
    $d = event_dt($ev['start_at']);
    $d->modify('+3 hours');
    return $d;
}

// 'upcoming', 'live' or 'over'
function event_state($ev) {
    $now = event_now();
    if ($now > event_end_dt($ev)) return 'over';
    if ($now >= event_dt($ev['start_at'])) return 'live';
    return 'upcoming';
}

function event_time_range($ev) {
    $t = event_dt($ev['start_at'])->format('g:i A');
    if (!empty($ev['end_at'])) $t .= ' – ' . event_dt($ev['end_at'])->format('g:i A');
    return $t;
}

function event_date_long($ev) {
    return event_dt($ev['start_at'])->format('l, F j, Y');
}

// "Today", "Tomorrow", "In 3 days"... (empty when it's further away)
function event_when_label($ev) {
    $st = event_state($ev);
    if ($st === 'live') return 'Happening now';
    if ($st === 'over') return 'Ended';
    $today = event_now();
    $today->setTime(0, 0, 0);
    $day = event_dt($ev['start_at']);
    $day->setTime(0, 0, 0);
    $diff = (int)$today->diff($day)->format('%r%a');
    if ($diff <= 0) return 'Today';
    if ($diff === 1) return 'Tomorrow';
    if ($diff < 7)  return 'In ' . $diff . ' days';
    return '';
}

// ---------- small bits ----------
function event_icon($name) {
    $p = array(
        'search'   => '<circle cx="11" cy="11" r="6.5"/><path d="M16 16l4.5 4.5"/>',
        'clock'    => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'pin'      => '<path d="M12 21s-6.5-5.2-6.5-10.5a6.5 6.5 0 0113 0C18.5 15.8 12 21 12 21z"/><circle cx="12" cy="10.5" r="2.3"/>',
        'users'    => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20c0-3.5 3-5.5 6.5-5.5s6.5 2 6.5 5.5"/><circle cx="17" cy="9" r="2.5"/><path d="M17.5 14.5c2.5 0 4 1.5 4 4"/>',
        'calendar' => '<rect x="3" y="5" width="18" height="16" rx="3"/><path d="M3 10h18M8 3v4M16 3v4"/>',
        'check'    => '<path d="M5 12.5l4.5 4.5L19 7.5"/>',
        'plus'     => '<path d="M12 5v14M5 12h14"/>',
        'megaphone'=> '<path d="M4 10v4a1 1 0 001 1h2l7 4V5L7 9H5a1 1 0 00-1 1z"/><path d="M18 9a4 4 0 010 6"/>',
        'bell'     => '<path d="M6 16v-5a6 6 0 0112 0v5l1.5 2h-15L6 16zM10 21h4"/>',
        'alert'    => '<path d="M12 4l9 16H3L12 4z"/><path d="M12 10v4M12 17.5v.01"/>',
        'pushpin'  => '<path d="M9 4h6l-1 5 3 3H7l3-3-1-5zM12 12v8"/>',
        'edit'     => '<path d="M4 20h4l10-10-4-4L4 16v4zM13 7l4 4"/>',
        'trash'    => '<path d="M5 7h14M10 7V4h4v3M7 7l1 13h8l1-13"/>',
        'lock'     => '<rect x="5" y="10.5" width="14" height="10" rx="3"/><path d="M8 10.5V8a4 4 0 018 0v2.5"/>',
        'star'     => '<path d="M12 3.5l2.6 5.3 5.9.9-4.3 4.1 1 5.8L12 16.9l-5.2 2.7 1-5.8L3.5 9.7l5.9-.9L12 3.5z"/>',
        'crown'    => '<path d="M3.5 8l4.5 4 4-7 4 7 4.5-4-1.5 10h-14L3.5 8z"/>',
        'userplus' => '<circle cx="10" cy="8" r="3.5"/><path d="M3.5 20c0-3.3 2.9-5.5 6.5-5.5s6.5 2.2 6.5 5.5M19 8v6M16 11h6"/>',
        'dots'     => '<circle cx="5.5" cy="12" r="1.2"/><circle cx="12" cy="12" r="1.2"/><circle cx="18.5" cy="12" r="1.2"/>',
        'shield'   => '<path d="M12 3.5l7 2.8v5.4c0 4.2-2.8 7.4-7 9-4.2-1.6-7-4.8-7-9V6.3l7-2.8z"/><path d="M9 12l2.2 2.2L15.2 10"/>',
        'globe'    => '<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c2.6 2.7 3.9 5.7 3.9 9s-1.3 6.3-3.9 9c-2.6-2.7-3.9-5.7-3.9-9S9.4 5.7 12 3z"/>',
    );
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ($p[$name] ?? '') . '</svg>';
}

// Only lets a redirect go back to one of our own pages
function event_safe_back($s, $fallback) {
    $s = (string)$s;
    return preg_match('/^(events|event|club|dashboard)(\?[A-Za-z0-9=&_%.-]*)?$/', $s) ? $s : $fallback;
}

// Owner only (editing the club, managing members, deleting other people's posts)
function event_is_club_owner($conn, $clubId, $userId) {
    $r = db_run($conn,
        "SELECT id FROM club_members WHERE club_id = ? AND user_id = ? AND role = 'owner' AND status = 'active' LIMIT 1",
        "ii", array((int)$clubId, (int)$userId));
    return (bool)$r['rows'];
}

// Owner or officer: may post and edit events and announcements
function event_is_club_staff($conn, $clubId, $userId) {
    return club_is_staff($conn, $clubId, $userId);
}

// Owners can cancel any event; officers only the events they posted themselves
function event_can_cancel($conn, $ev, $userId) {
    $role = club_role($conn, $ev['club_id'], $userId);
    if ($role === 'owner') return true;
    return ($role === 'officer' && isset($ev['created_by']) && (int)$ev['created_by'] === (int)$userId);
}

// Is this person an active member (owner, officer or regular member) of the club?
function event_is_club_member($conn, $clubId, $userId) {
    $r = db_run($conn,
        "SELECT id FROM club_members WHERE club_id = ? AND user_id = ? AND status = 'active' LIMIT 1",
        "ii", array((int)$clubId, (int)$userId));
    return (bool)$r['rows'];
}

// Members-only events: only active members of the hosting club may see or join them.
// SQL fragment for event lists. It contains ONE ? mark (the viewer's user id).
define('EVENT_VISIBLE_SQL', "(e.visibility = 'public' OR EXISTS (SELECT 1 FROM club_members vm WHERE vm.club_id = e.club_id AND vm.user_id = ? AND vm.status = 'active'))");

// When someone leaves (or is removed from) a club, they lose their spot at that club's members-only events.
function event_purge_member_rsvps($conn, $clubId, $userId) {
    db_run($conn,
        "DELETE r FROM event_rsvps r JOIN events e ON e.id = r.event_id
         WHERE e.club_id = ? AND e.visibility = 'members' AND r.user_id = ?",
        "ii", array((int)$clubId, (int)$userId));
}

// When an event becomes members-only, RSVPs from people outside the club are removed.
// Returns the user ids that were removed (so they can be told).
function event_purge_outsider_rsvps($conn, $eventId, $clubId) {
    $res = db_run($conn,
        "SELECT r.user_id FROM event_rsvps r
         WHERE r.event_id = ? AND r.user_id NOT IN
               (SELECT user_id FROM club_members WHERE club_id = ? AND status = 'active')",
        "ii", array((int)$eventId, (int)$clubId));
    $ids = array();
    foreach ($res['rows'] as $row) $ids[] = (int)$row['user_id'];
    if ($ids) {
        db_run($conn,
            "DELETE FROM event_rsvps WHERE event_id = ? AND user_id NOT IN
             (SELECT user_id FROM club_members WHERE club_id = ? AND status = 'active')",
            "ii", array((int)$eventId, (int)$clubId));
    }
    return $ids;
}

// Approved clubs this person owns or is an officer of (they can post events for these)
function event_postable_clubs($conn, $userId) {
    $r = db_run($conn,
        "SELECT c.id, c.name FROM clubs c
         JOIN club_members m ON m.club_id = c.id AND m.user_id = ? AND m.role IN ('owner', 'officer') AND m.status = 'active'
         WHERE c.status = 'approved' ORDER BY c.name ASC",
        "i", array((int)$userId));
    return $r['rows'];
}

// ---------- reading events ----------
// Returns array('ok' => bool, 'rows' => [...]). $where may use ? marks; pass their types and values.
// Every row has: going (count), me_going (0/1), faces (a few people who are going and have public profiles).
function events_query($conn, $meId, $where, $types, $params, $order, $limit) {
    $sql = "SELECT e.id, e.club_id, e.title, e.description, e.location, e.start_at, e.end_at, e.capacity, e.status, e.visibility, UNIX_TIMESTAMP(e.created_at) AS created_ts,
                   c.name AS club_name" . (club_identity_ready($conn) ? ", c.logo AS club_logo, c.cover AS club_cover" : "") . ",
                   (SELECT COUNT(*) FROM event_rsvps r WHERE r.event_id = e.id) AS going,
                   (SELECT COUNT(*) FROM event_rsvps r WHERE r.event_id = e.id AND r.user_id = ?) AS me_going,
                   " . (club_identity_ready($conn) ? "(c.invite_only = 1 AND NOT EXISTS (SELECT 1 FROM club_members hm WHERE hm.club_id = e.club_id AND hm.user_id = ? AND hm.status = 'active'))" : "0") . " AS hide_faces
            FROM events e
            JOIN clubs c ON c.id = e.club_id AND c.status = 'approved'
            WHERE e.status = 'active' AND " . EVENT_VISIBLE_SQL . " AND " . $where . "
            ORDER BY " . $order . " LIMIT " . (int)$limit;

    $withHide = club_identity_ready($conn);
    $res = db_run($conn, $sql, ($withHide ? "iii" : "ii") . $types,
        array_merge($withHide ? array((int)$meId, (int)$meId, (int)$meId) : array((int)$meId, (int)$meId), $params));
    if (!$res['ok']) return array('ok' => false, 'rows' => array());

    $rows = $res['rows'];
    foreach ($rows as $i => $r) $rows[$i]['faces'] = array();

    if ($rows) {
        $ids = array();
        foreach ($rows as $r) $ids[] = (int)$r['id'];
        $in = implode(',', array_fill(0, count($ids), '?'));

        // first four public-profile attendees per event
        $fr = db_run($conn,
            "SELECT r.event_id, SUBSTRING_INDEX(GROUP_CONCAT(r.user_id ORDER BY r.created_at ASC), ',', 4) AS uids
             FROM event_rsvps r
             JOIN users u ON u.id = r.user_id AND (u.profile_visibility IS NULL OR u.profile_visibility = 'public')
             WHERE r.event_id IN ($in) GROUP BY r.event_id",
            str_repeat("i", count($ids)), $ids);

        $byEvent = array();
        $allUsers = array();
        foreach ($fr['rows'] as $f) {
            $list = array_filter(array_map('intval', explode(',', (string)$f['uids'])));
            $byEvent[(int)$f['event_id']] = $list;
            foreach ($list as $u) $allUsers[$u] = true;
        }

        if ($allUsers) {
            $uids = array_keys($allUsers);
            $in2 = implode(',', array_fill(0, count($uids), '?'));
            $ur = db_run($conn, "SELECT id, first_name, photo FROM users WHERE id IN ($in2)", str_repeat("i", count($uids)), $uids);
            $users = array();
            foreach ($ur['rows'] as $u) $users[(int)$u['id']] = $u;

            foreach ($rows as $i => $r) {
                if (!empty($r['hide_faces'])) continue;
                foreach (($byEvent[(int)$r['id']] ?? array()) as $uid) {
                    if (isset($users[$uid])) $rows[$i]['faces'][] = $users[$uid];
                }
            }
        }
    }
    return array('ok' => true, 'rows' => $rows);
}

// ---------- RSVP ----------
// Returns array(message, 'success'|'error')
function event_rsvp($conn, $eventId, $userId, $go) {
    $er = db_run($conn,
        "SELECT e.id, e.club_id, e.title, e.status, e.visibility, e.capacity, e.start_at, e.end_at
         FROM events e JOIN clubs c ON c.id = e.club_id AND c.status = 'approved'
         WHERE e.id = ? LIMIT 1", "i", array((int)$eventId));
    if (!$er['rows']) return array("That event doesn't exist.", "error");

    $ev = $er['rows'][0];
    if ($ev['status'] !== 'active')   return array("This event was cancelled.", "error");
    if (event_state($ev) === 'over')  return array("This event has already ended.", "error");

    // members-only: outsiders can't RSVP (leaving is always allowed)
    if ($go && $ev['visibility'] === 'members' && !event_is_club_member($conn, $ev['club_id'], $userId)) {
        return array("This event is for club members only.", "error");
    }

    if (!$go) {
        $r = db_run($conn, "DELETE FROM event_rsvps WHERE event_id = ? AND user_id = ?", "ii", array((int)$eventId, (int)$userId));
        return array(($r['ok'] && $r['affected'] > 0) ? "You're no longer going to " . $ev['title'] . "." : "You weren't on the list.", ($r['ok']) ? "success" : "error");
    }

    // one statement, so two people can't both take the last spot
    $r = db_run($conn,
        "INSERT IGNORE INTO event_rsvps (event_id, user_id)
         SELECT e.id, ? FROM events e
         WHERE e.id = ? AND (e.capacity IS NULL OR (SELECT COUNT(*) FROM event_rsvps x WHERE x.event_id = e.id) < e.capacity)",
        "ii", array((int)$userId, (int)$eventId));

    if ($r['ok'] && $r['affected'] > 0) return array("You're going to " . $ev['title'] . ".", "success");

    $ex = db_run($conn, "SELECT id FROM event_rsvps WHERE event_id = ? AND user_id = ? LIMIT 1", "ii", array((int)$eventId, (int)$userId));
    if ($ex['rows']) return array("You're already going.", "success");
    return array("Sorry, this event is full.", "error");
}

// ---------- notifications ----------
function event_notify_members($conn, $clubId, $exceptUserId, $message, $link) {
    db_run($conn,
        "INSERT INTO notifications (user_id, type, message, link)
         SELECT user_id, 'event_new', ?, ? FROM club_members
         WHERE club_id = ? AND status = 'active' AND user_id <> ?",
        "ssii", array(str_cut($message, 255), $link, (int)$clubId, (int)$exceptUserId));
}

function event_notify_going($conn, $eventId, $exceptUserId, $type, $message, $link) {
    db_run($conn,
        "INSERT INTO notifications (user_id, type, message, link)
         SELECT user_id, ?, ?, ? FROM event_rsvps WHERE event_id = ? AND user_id <> ?",
        "sssii", array($type, str_cut($message, 255), $link, (int)$eventId, (int)$exceptUserId));
}

// ---------- validating the post/edit form ----------
// Returns the clean values, or null (and sets $err).
function event_clean($post, $requireFuture, &$err) {
    $title = trim(preg_replace('/\s+/u', ' ', (string)($post['title'] ?? '')));
    $loc   = trim(preg_replace('/\s+/u', ' ', (string)($post['location'] ?? '')));
    $desc  = trim(str_replace("\r\n", "\n", (string)($post['description'] ?? '')));
    $desc  = preg_replace("/\n{3,}/", "\n\n", $desc);
    $date  = trim((string)($post['event_date'] ?? ''));
    $start = trim((string)($post['start_time'] ?? ''));
    $end   = trim((string)($post['end_time'] ?? ''));
    $capIn = trim((string)($post['capacity'] ?? ''));

    if (str_len($title) < 3 || str_len($title) > 80) { $err = "Give your event a title of 3 to 80 characters."; return null; }

    $d = DateTime::createFromFormat('Y-m-d', $date, event_tz());
    if (!$d || $d->format('Y-m-d') !== $date)        { $err = "Pick the date of your event."; return null; }
    if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $start)) { $err = "Pick a start time."; return null; }
    if ($end !== '' && !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $end)) { $err = "That end time doesn't look right."; return null; }
    if ($end !== '' && $end <= $start)                { $err = "The end time has to be after the start time."; return null; }

    $startAt = $date . ' ' . $start . ':00';
    $endAt   = ($end !== '') ? ($date . ' ' . $end . ':00') : null;

    if ($requireFuture) {
        if (event_dt($startAt) <= event_now()) { $err = "Pick a date and time in the future."; return null; }
        $limit = event_now();
        $limit->modify('+2 years');
        if (event_dt($startAt) > $limit)       { $err = "That date is too far ahead. Pick one within the next two years."; return null; }
    }

    if (str_len($loc) < 2 || str_len($loc) > 120)       { $err = "Say where it's happening (2 to 120 characters)."; return null; }
    if (str_len($desc) < 20 || str_len($desc) > 1500)   { $err = "Describe the event in 20 to 1,500 characters."; return null; }

    $cap = null;
    if ($capIn !== '') {
        if (!preg_match('/^\d{1,4}$/', $capIn) || (int)$capIn < 1 || (int)$capIn > 5000) {
            $err = "Capacity should be a number from 1 to 5000, or left empty for no limit.";
            return null;
        }
        $cap = (int)$capIn;
    }

    $vis = (($post['visibility'] ?? 'public') === 'members') ? 'members' : 'public';

    return array(
        'title' => $title, 'description' => $desc, 'location' => $loc,
        'start_at' => $startAt, 'end_at' => $endAt, 'capacity' => $cap, 'visibility' => $vis,
    );
}

// ---------- one event card ----------
// $back = the page to return to after pressing the RSVP button (see event_safe_back)
function render_event_card($ev, $back) {
    $id      = (int)$ev['id'];
    $state   = event_state($ev);
    $s       = event_dt($ev['start_at']);
    $going   = (int)$ev['going'];
    $cap     = ($ev['capacity'] !== null) ? (int)$ev['capacity'] : 0;
    $meGoing = ((int)$ev['me_going'] > 0);
    $full    = ($cap > 0 && $going >= $cap);
    $when    = event_when_label($ev);
    $faces   = $ev['faces'] ?? array();
    $excl    = (($ev['visibility'] ?? 'public') === 'members');
    $blurb   = trim(preg_replace('/\s+/u', ' ', (string)($ev['description'] ?? '')));
    $hasCover = (!empty($ev['club_cover']) && club_image_ok($ev['club_cover']));
    $showWhen = ($state === 'upcoming' && $when !== '');
    $showFull = ($full && $state !== 'over');
    $hasFlags = ($excl || $state === 'live' || $state === 'over' || $showWhen || $showFull);
    ?>
    <article class="evc<?php echo $state === 'over' ? ' is-over' : ''; ?><?php echo $excl ? ' is-exclusive' : ''; ?>">
        <div class="evc-cover" data-g="<?php echo (int)$ev['club_id'] % 6; ?>">
            <?php if ($hasCover) { ?><img src="uploads/clubs/<?php echo e($ev['club_cover']); ?>" alt="" loading="lazy" decoding="async"><?php } ?>

            <div class="evc-date" aria-hidden="true">
                <small><?php echo e($s->format('M')); ?></small>
                <b><?php echo e($s->format('j')); ?></b>
            </div>

            <?php if ($hasFlags) { ?>
                <div class="evc-flags">
                    <?php if ($excl) { ?><span class="evc-flag members"><?php echo event_icon('lock'); ?>Members only</span><?php } ?>
                    <?php if ($state === 'live') { ?><span class="evc-flag live">Live now</span>
                    <?php } elseif ($state === 'over') { ?><span class="evc-flag ended">Ended</span>
                    <?php } elseif ($showWhen) { ?><span class="evc-flag soon"><?php echo e($when); ?></span><?php } ?>
                    <?php if ($showFull) { ?><span class="evc-flag full">Full</span><?php } ?>
                </div>
            <?php } ?>
        </div>

        <div class="evc-body">
            <a class="evc-club" href="club?id=<?php echo (int)$ev['club_id']; ?>"><?php echo club_tile_html(array('id' => $ev['club_id'], 'name' => $ev['club_name'], 'logo' => $ev['club_logo'] ?? null), 'xs', 'span'); ?><span><?php echo e($ev['club_name']); ?></span></a>

            <h3 class="evc-title"><a href="event?id=<?php echo $id; ?>"><?php echo e($ev['title']); ?></a></h3>

            <?php if ($blurb !== '') { ?><p class="evc-blurb"><?php echo e(str_cut($blurb, 220)); ?></p><?php } ?>

            <div class="evc-meta">
                <span><?php echo event_icon('clock'); ?><i><?php echo e($s->format('D') . ' · ' . event_time_range($ev)); ?></i></span>
                <span><?php echo event_icon('pin'); ?><i><?php echo e($ev['location']); ?></i></span>
            </div>

            <div class="evc-foot">
                <div class="evc-going">
                    <?php if ($faces) { ?>
                        <span class="faces">
                            <?php foreach ($faces as $f) { ?><span class="face"><?php echo avatar_inner($f); ?></span><?php } ?>
                        </span>
                    <?php } ?>
                    <span>
                        <?php if ($going === 0) { echo $state === 'over' ? 'No one joined' : 'Be the first to join'; }
                              else { echo '<b>' . $going . ($cap > 0 ? ' / ' . $cap : '') . '</b>' . ($state === 'over' ? ' went' : ' going'); } ?>
                    </span>
                </div>

                <?php if ($state !== 'over') { ?>
                    <form method="POST" action="event" class="ev-rsvp-form evc-rsvp">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="event_id" value="<?php echo $id; ?>">
                        <input type="hidden" name="back" value="<?php echo e($back); ?>">
                        <?php if ($meGoing) { ?>
                            <button type="submit" name="action" value="unrsvp" class="btn-sm going" title="Click to cancel"><?php echo event_icon('check'); ?>Going</button>
                        <?php } elseif ($full) { ?>
                            <button type="button" class="btn-sm btn-ghost" disabled>Full</button>
                        <?php } else { ?>
                            <button type="submit" name="action" value="rsvp" class="btn-sm btn-dark">I’m going</button>
                        <?php } ?>
                    </form>
                <?php } ?>
            </div>
        </div>
    </article>
    <?php
}