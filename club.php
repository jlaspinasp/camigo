<?php
session_start();
require("db.php");
require("auth.php");
require("events-lib.php");

$me  = require_user_row($conn);
$cid = (int)($_GET['id'] ?? $_POST['club_id'] ?? 0);

// ---- the club (approved clubs only) ----
$cr = db_run($conn,
    "SELECT c.id, c.name, c.category, c.description, c.created_at,
            u.username AS creator_username, u.first_name AS creator_first, u.last_name AS creator_last
     FROM clubs c LEFT JOIN users u ON u.id = c.created_by
     WHERE c.id = ? AND c.status = 'approved' LIMIT 1",
    "i", array($cid));
$club = $cr['rows'] ? $cr['rows'][0] : null;

// ---- my place in it ----
$myRole = null;
$myStatus = null;
if ($club) {
    $mr = db_run($conn, "SELECT role, status FROM club_members WHERE club_id = ? AND user_id = ? LIMIT 1", "ii", array($cid, $me['id']));
    if ($mr['rows']) {
        $myRole = $mr['rows'][0]['role'];
        $myStatus = $mr['rows'][0]['status'];
    }
}
$isOwner   = ($myRole === 'owner' && $myStatus === 'active');
$isPending = ($myStatus === 'pending');
$isMember  = ($myRole === 'member' && $myStatus === 'active');
$canSeePrivate = ($isOwner || $isMember);   // members-only announcements are visible to these people

// has the announcements upgrade (club-announcements.sql) been run?
$annReady = false;
if ($club) {
    $cc = mysqli_query($conn, "SHOW COLUMNS FROM club_posts LIKE 'kind'");
    $annReady = ($cc && mysqli_num_rows($cc) > 0);
}

// validates the announcement form. Returns array(title|null, kind, visibility, body) or null (and sets $err)
function ann_clean($post, &$err) {
    $title = trim(preg_replace('/\s+/u', ' ', (string)($post['title'] ?? '')));
    $body  = trim(str_replace("\r\n", "\n", (string)($post['body'] ?? '')));
    $body  = preg_replace("/\n{3,}/", "\n\n", $body);
    $kind  = (string)($post['kind'] ?? 'announcement');
    if (!in_array($kind, array('announcement', 'reminder', 'important'), true)) $kind = 'announcement';
    $vis   = (($post['visibility'] ?? 'public') === 'members') ? 'members' : 'public';

    if ($body === '')              { $err = "Write something first."; return null; }
    if (str_len($body) > 1000)     { $err = "Announcements can be up to 1,000 characters."; return null; }
    if (str_len($title) > 100)     { $err = "Keep the headline under 100 characters."; return null; }
    return array($title === '' ? null : $title, $kind, $vis, $body);
}

// the "type" and "who can see it" pickers (shared by the composer and the edit form)
function ann_pickers($kind, $vis) {
    $kinds = array(
        'announcement' => array('Announcement', 'megaphone'),
        'reminder'     => array('Reminder', 'bell'),
        'important'    => array('Important', 'alert'),
    );
    echo '<div class="ann-opts"><div class="ann-pills" role="radiogroup" aria-label="Type">';
    foreach ($kinds as $k => $v) {
        echo '<label class="ann-pill k-' . $k . '"><input type="radio" name="kind" value="' . $k . '"' . ($kind === $k ? ' checked' : '') . '><span>' . event_icon($v[1]) . $v[0] . '</span></label>';
    }
    echo '</div><div class="ann-pills" role="radiogroup" aria-label="Who can see it">';
    echo '<label class="ann-pill"><input type="radio" name="visibility" value="public"' . ($vis !== 'members' ? ' checked' : '') . '><span>' . event_icon('globe') . 'Everyone</span></label>';
    echo '<label class="ann-pill excl"><input type="radio" name="visibility" value="members"' . ($vis === 'members' ? ' checked' : '') . '><span>' . event_icon('lock') . 'Members only</span></label>';
    echo '</div></div>';
}

// ---- actions ----
if ($club && $_SERVER['REQUEST_METHOD'] === 'POST') {

    $hash = '';

    $action = $_POST['action'] ?? '';
    $mid    = (int)($_POST['member_id'] ?? 0);

    if (!csrf_ok()) {
        flash_set("Your session expired. Please try again.", "error");

    } elseif ($action === 'join') {
        $r = db_run($conn,
            "INSERT IGNORE INTO club_members (club_id, user_id, role, status) VALUES (?, ?, 'member', 'pending')",
            "ii", array($cid, $me['id']));
        if ($r['ok'] && $r['affected'] > 0) {
            notify_join_request($conn, $cid, $me);
            flash_set("Request sent. The club owner will review it.", "success");
        } else {
            flash_set("You've already requested or joined this club.", "error");
        }

    } elseif ($action === 'cancel') {
        $r = db_run($conn, "DELETE FROM club_members WHERE club_id = ? AND user_id = ? AND role = 'member' AND status = 'pending'", "ii", array($cid, $me['id']));
        flash_set(($r['ok'] && $r['affected'] > 0) ? "Request cancelled." : "There was no request to cancel.", ($r['ok'] && $r['affected'] > 0) ? "success" : "error");

    } elseif ($action === 'leave') {
        $r = db_run($conn, "DELETE FROM club_members WHERE club_id = ? AND user_id = ? AND role = 'member' AND status = 'active'", "ii", array($cid, $me['id']));
        if ($r['ok'] && $r['affected'] > 0) event_purge_member_rsvps($conn, $cid, $me['id']);
        flash_set(($r['ok'] && $r['affected'] > 0) ? "You left the club." : "You can't leave this club.", ($r['ok'] && $r['affected'] > 0) ? "success" : "error");

    } elseif (in_array($action, array('post_update', 'edit_post', 'delete_post', 'pin_post'), true)) {

        $hash = '#updates';
        $pid  = (int)($_POST['post_id'] ?? 0);

        if (!$isOwner) {
            flash_set("Only the club owner can do that.", "error");

        } elseif ($action === 'post_update') {
            $err = '';
            $c = $annReady ? ann_clean($_POST, $err) : null;
            $rl = db_run($conn, "SELECT COUNT(*) AS n FROM club_posts WHERE user_id = ? AND created_at > (NOW() - INTERVAL 1 HOUR)", "i", array($me['id']));

            if (!$annReady) {
                flash_set("Announcements need a one-time upgrade. Run club-announcements.sql in phpMyAdmin.", "error");
            } elseif (!$c) {
                flash_set($err, "error");
            } elseif ($rl['ok'] && $rl['rows'] && (int)$rl['rows'][0]['n'] >= 15) {
                flash_set("You've posted a lot in the last hour. Please wait a bit.", "error");
            } else {
                $r = db_run($conn, "INSERT INTO club_posts (club_id, user_id, title, kind, visibility, body) VALUES (?, ?, ?, ?, ?, ?)", "iissss", array($cid, $me['id'], $c[0], $c[1], $c[2], $c[3]));
                if ($r['ok']) {
                    if (!empty($_POST['notify'])) {
                        $lead = ($c[2] === 'members') ? ' posted a members-only announcement: ' : ' posted an announcement: ';
                        $what = ($c[0] !== null) ? $c[0] : str_cut(preg_replace('/\s+/', ' ', $c[3]), 90);
                        $note = str_cut($club['name'] . $lead . $what, 255);
                        db_run($conn,
                            "INSERT INTO notifications (user_id, type, message, link)
                             SELECT user_id, 'club_update', ?, ? FROM club_members
                             WHERE club_id = ? AND status = 'active' AND user_id <> ?",
                            "ssii", array($note, 'club?id=' . $cid . '#updates', $cid, (int)$me['id']));
                    }
                    flash_set($c[2] === 'members' ? "Announcement posted. Only your club members can see it." : "Announcement posted.", "success");
                } else {
                    flash_set(($r['errno'] == 1146 || $r['errno'] == 1054) ? "Announcements need a one-time upgrade. Run club-announcements.sql in phpMyAdmin." : "Something went wrong. Please try again.", "error");
                }
            }

        } elseif ($action === 'edit_post') {
            $err = '';
            $c = $annReady ? ann_clean($_POST, $err) : null;

            if (!$annReady) {
                flash_set("Announcements need a one-time upgrade. Run club-announcements.sql in phpMyAdmin.", "error");
            } elseif (!$c) {
                flash_set($err, "error");
            } else {
                $cur = db_run($conn, "SELECT title, body FROM club_posts WHERE id = ? AND club_id = ? LIMIT 1", "ii", array($pid, $cid));
                if (!$cur['rows']) {
                    flash_set("That announcement was already removed.", "error");
                } else {
                    $textChanged = ((string)$cur['rows'][0]['title'] !== (string)$c[0]) || ((string)$cur['rows'][0]['body'] !== $c[3]);
                    $r = db_run($conn,
                        "UPDATE club_posts SET title = ?, kind = ?, visibility = ?, body = ?" . ($textChanged ? ", edited_at = NOW()" : "") . " WHERE id = ? AND club_id = ?",
                        "ssssii", array($c[0], $c[1], $c[2], $c[3], $pid, $cid));
                    flash_set($r['ok'] ? "Announcement updated." : "Something went wrong. Please try again.", $r['ok'] ? "success" : "error");
                }
            }

        } elseif ($action === 'delete_post') {
            $r = db_run($conn, "DELETE FROM club_posts WHERE id = ? AND club_id = ?", "ii", array($pid, $cid));
            flash_set(($r['ok'] && $r['affected'] > 0) ? "Announcement deleted." : "That announcement was already removed.", ($r['ok'] && $r['affected'] > 0) ? "success" : "error");

        } else {
            // pin one update at a time; pressing it again unpins
            $cur = db_run($conn, "SELECT pinned FROM club_posts WHERE id = ? AND club_id = ? LIMIT 1", "ii", array($pid, $cid));
            if (!$cur['rows']) {
                flash_set("That announcement was already removed.", "error");
            } elseif ((int)$cur['rows'][0]['pinned'] === 1) {
                db_run($conn, "UPDATE club_posts SET pinned = 0 WHERE id = ? AND club_id = ?", "ii", array($pid, $cid));
                flash_set("Announcement unpinned.", "success");
            } else {
                db_run($conn, "UPDATE club_posts SET pinned = 0 WHERE club_id = ?", "i", array($cid));
                db_run($conn, "UPDATE club_posts SET pinned = 1 WHERE id = ? AND club_id = ?", "ii", array($pid, $cid));
                flash_set("Announcement pinned to the top.", "success");
            }
        }

    } elseif (in_array($action, array('accept', 'decline', 'remove'), true)) {

        if (!$isOwner || $mid <= 0) {
            flash_set("Only the club owner can do that.", "error");
        } elseif ($action === 'accept') {
            $tu = member_info($conn, $mid);
            $r = db_run($conn, "UPDATE club_members SET status = 'active' WHERE id = ? AND club_id = ? AND status = 'pending'", "ii", array($mid, $cid));
            if ($r['ok'] && $r['affected'] > 0 && $tu) {
                notify($conn, $tu['user_id'], 'join_accepted', 'Your request to join ' . $club['name'] . ' was accepted.', 'club?id=' . $cid);
            }
            flash_set(($r['ok'] && $r['affected'] > 0) ? "Member accepted." : "That request was already handled.", ($r['ok'] && $r['affected'] > 0) ? "success" : "error");
        } elseif ($action === 'decline') {
            $tu = member_info($conn, $mid);
            $r = db_run($conn, "DELETE FROM club_members WHERE id = ? AND club_id = ? AND status = 'pending'", "ii", array($mid, $cid));
            if ($r['ok'] && $r['affected'] > 0 && $tu) {
                notify($conn, $tu['user_id'], 'join_declined', 'Your request to join ' . $club['name'] . ' was declined.', 'clubs');
            }
            flash_set(($r['ok'] && $r['affected'] > 0) ? "Request declined." : "That request was already handled.", ($r['ok'] && $r['affected'] > 0) ? "success" : "error");
        } else {
            // owners can't be removed
            $tu = member_info($conn, $mid);
            $r = db_run($conn, "DELETE FROM club_members WHERE id = ? AND club_id = ? AND role = 'member' AND status = 'active'", "ii", array($mid, $cid));
            if ($r['ok'] && $r['affected'] > 0 && $tu) {
                event_purge_member_rsvps($conn, $cid, $tu['user_id']);
                notify($conn, $tu['user_id'], 'removed', 'You were removed from ' . $club['name'] . '.', 'clubs');
            }
            flash_set(($r['ok'] && $r['affected'] > 0) ? "Member removed." : "That member couldn't be removed.", ($r['ok'] && $r['affected'] > 0) ? "success" : "error");
        }
    }

    header("Location: club?id=" . $cid . $hash);
    exit();
}

// ---- members + (for the owner) pending requests ----
$members = array();
$pending = array();
if ($club) {
    $sqlBase = "SELECT m.id AS mid, m.role, m.joined_at, u.username, u.first_name, u.last_name, u.course, u.year_level, u.photo, u.profile_visibility
                FROM club_members m JOIN users u ON u.id = m.user_id
                WHERE m.club_id = ? AND m.status = ";

    $members = db_run($conn, $sqlBase . "'active' ORDER BY (m.role = 'owner') DESC, m.joined_at ASC LIMIT 200", "i", array($cid))['rows'];

    if ($isOwner) {
        $pending = db_run($conn, $sqlBase . "'pending' ORDER BY m.joined_at ASC", "i", array($cid))['rows'];
    }
}

// ---- club updates (the news feed) ----
$postLimit = !empty($_GET['more']) ? 30 : 5;
$posts = array();
$postsMore = false;
$postsSetupMissing = false;
$hiddenPosts = 0;
if ($club) {
    if ($annReady) {
        $pr = db_run($conn,
            "SELECT p.id, p.title, p.kind, p.visibility, p.body, p.pinned, (p.edited_at IS NOT NULL) AS edited, UNIX_TIMESTAMP(p.created_at) AS ts,
                    u.username, u.first_name, u.last_name, u.photo
             FROM club_posts p JOIN users u ON u.id = p.user_id
             WHERE p.club_id = ? AND (p.visibility = 'public' OR 1 = ?) ORDER BY p.pinned DESC, p.id DESC LIMIT " . ($postLimit + 1),
            "ii", array($cid, $canSeePrivate ? 1 : 0));

        // outsiders only learn that members-only announcements exist (no titles or text)
        if (!$canSeePrivate) {
            $hp = db_run($conn, "SELECT COUNT(*) AS n FROM club_posts WHERE club_id = ? AND visibility = 'members'", "i", array($cid));
            if ($hp['ok'] && $hp['rows']) $hiddenPosts = (int)$hp['rows'][0]['n'];
        }
    } else {
        $pr = db_run($conn,
            "SELECT p.id, p.body, p.pinned, UNIX_TIMESTAMP(p.created_at) AS ts,
                    u.username, u.first_name, u.last_name, u.photo
             FROM club_posts p JOIN users u ON u.id = p.user_id
             WHERE p.club_id = ? ORDER BY p.pinned DESC, p.id DESC LIMIT " . ($postLimit + 1),
            "i", array($cid));
    }
    $postsSetupMissing = !$pr['ok'];
    $posts = $pr['rows'];
    if (count($posts) > $postLimit) {
        $postsMore = true;
        $posts = array_slice($posts, 0, $postLimit);
    }
}

// turns plain text into safe HTML with clickable links
function post_html($text) {
    $h = e($text);
    $h = preg_replace_callback('~https?://[^\s<]+~i', function ($m) {
        $url = $m[0];
        $tail = '';
        while ($url !== '' && strpos('.,;:!?)', substr($url, -1)) !== false) {
            $tail = substr($url, -1) . $tail;
            $url = substr($url, 0, -1);
        }
        return '<a href="' . $url . '" target="_blank" rel="noopener noreferrer nofollow">' . $url . '</a>' . $tail;
    }, $h);
    return nl2br($h);
}

// ---- upcoming events for this club ----
$clubEvents = array();
if ($club) {
    $evr = events_query($conn, $me['id'], EVENT_END_SQL . " >= ? AND e.club_id = ?", "si", array(event_now_sql(), $cid), "e.start_at ASC", 5);
    $clubEvents = $evr['rows'];
}

// members-only events outsiders can't see: only a teaser count is shown (no titles or details)
$hiddenEvents = 0;
if ($club && !$isMember && !$isOwner) {
    $hr = db_run($conn,
        "SELECT COUNT(*) AS n FROM events e WHERE e.club_id = ? AND e.status = 'active' AND e.visibility = 'members' AND " . EVENT_END_SQL . " >= ?",
        "is", array($cid, event_now_sql()));
    if ($hr['ok'] && $hr['rows']) $hiddenEvents = (int)$hr['rows'][0]['n'];
}

$flash = flash_get();
$message = $flash ? $flash[0] : "";
$message_class = $flash ? $flash[1] : "";

function member_line($m) {
    $parts = array();
    if (!empty($m['course'])) $parts[] = $m['course'];
    if (!empty($m['year_level'])) $parts[] = $m['year_level'];
    return implode(' · ', $parts);
}
// every member links to their profile; a private profile shows a "private" notice there
function member_link($m, $meUser) {
    if ($m['username'] === $meUser) return 'profile';
    return 'profile?u=' . urlencode($m['username']);
}

$n = count($members);
$creator = $club ? trim(($club['creator_first'] ?? '') . ' ' . ($club['creator_last'] ?? '')) : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo $club ? e($club['name']) . ' | ' : ''; ?>Club | Camigo</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:wght@600;700;800&family=Instrument+Sans:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
</head>
<body>

<?php include("topbar.php"); ?>

<main class="main narrow">

    <?php if ($message != "") { ?>
        <div class="msg <?php echo e($message_class); ?>"><?php echo e($message); ?></div>
    <?php } ?>

    <?php if (!$club) { ?>

        <section class="panel center">
            <h2>Club not found</h2>
            <p class="empty">This club doesn't exist, or it hasn't been approved yet. <a href="clubs">Browse clubs</a></p>
        </section>

    <?php } else { ?>

        <p class="crumb"><a href="clubs">&larr; All clubs</a></p>

        <section class="profile-card">
            <div class="cover"></div>
            <div class="profile-body">
                <div class="club-tile big t<?php echo (int)$club['id'] % 3; ?>"><?php echo e(first_letter($club['name'])); ?></div>

                <div class="p-head">
                    <div>
                        <h1 class="p-name"><?php echo e($club['name']); ?></h1>
                        <div class="p-user">
                            <span class="chip"><?php echo e($club['category']); ?></span>
                            <?php if ($isOwner) { ?><span class="badge approved">You're the owner</span><?php } ?>
                        </div>
                    </div>

                    <div class="top-actions">
                        <?php if ($isOwner) { ?>
                            <a href="edit-club?id=<?php echo (int)$club['id']; ?>" class="btn-sm btn-ghost">Edit club</a>
                        <?php } else { ?>
                            <form method="POST" class="foot-right">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="club_id" value="<?php echo (int)$club['id']; ?>">
                                <?php if ($isPending) { ?>
                                    <span class="badge pending">Request sent</span>
                                    <button type="submit" name="action" value="cancel" class="btn-sm btn-ghost">Cancel</button>
                                <?php } elseif ($isMember) { ?>
                                    <span class="badge approved">Member</span>
                                    <button type="submit" name="action" value="leave" class="btn-sm btn-ghost">Leave</button>
                                <?php } else { ?>
                                    <button type="submit" name="action" value="join" class="btn-sm btn-dark">Request to join</button>
                                <?php } ?>
                            </form>
                        <?php } ?>
                    </div>
                </div>

                <p class="p-meta">
                    <?php echo $n; ?> member<?php echo $n === 1 ? '' : 's'; ?>
                    &middot; Created <?php echo e(date('M j, Y', strtotime($club['created_at']))); ?>
                    <?php if ($creator !== '') { ?>&middot; by <?php echo e($creator); ?><?php } ?>
                </p>

                <p class="p-bio"><?php echo nl2br(e($club['description'])); ?></p>
            </div>
        </section>

        <?php if ($isOwner && $pending) { ?>
            <section class="panel">
                <h2>Join requests (<?php echo count($pending); ?>)</h2>
                <div class="club-list">
                    <?php foreach ($pending as $p) { $line = member_line($p); ?>
                        <div class="club-item">
                            <div class="req">
                                <div class="req-who">
                                    <div class="p-avatar tiny"><?php echo avatar_inner($p); ?></div>
                                    <div>
                                        <strong><?php echo e(trim($p['first_name'] . ' ' . ($p['last_name'] ?? ''))); ?></strong>
                                        <div class="club-meta">@<?php echo e($p['username']); ?><?php if ($line !== '') { ?> &middot; <?php echo e($line); ?><?php } ?></div>
                                    </div>
                                </div>
                                <form method="POST" class="req-actions">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="club_id" value="<?php echo (int)$club['id']; ?>">
                                    <input type="hidden" name="member_id" value="<?php echo (int)$p['mid']; ?>">
                                    <button type="submit" name="action" value="accept" class="btn-sm btn-approve">Accept</button>
                                    <button type="submit" name="action" value="decline" class="btn-sm btn-reject">Decline</button>
                                </form>
                            </div>
                        </div>
                    <?php } ?>
                </div>
            </section>
        <?php } ?>

        <section class="panel" id="updates">
            <div class="club-head" style="margin-bottom:16px">
                <h2 style="margin:0">Announcements</h2>
            </div>

            <?php if ($isOwner && !$postsSetupMissing && !$annReady) { ?>
                <div class="msg error">Announcements need a one-time upgrade. Run <strong>club-announcements.sql</strong> in phpMyAdmin to unlock headlines, types and members-only posts.</div>
            <?php } ?>

            <?php if ($isOwner && $annReady && !$postsSetupMissing) { ?>
                <form method="POST" class="ann-compose">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="club_id" value="<?php echo (int)$club['id']; ?>">
                    <div class="ann-compose-head">
                        <div class="p-avatar tiny"><?php echo avatar_inner($me); ?></div>
                        <div><strong>Post an announcement</strong><span>Share news, reminders or important changes with your club.</span></div>
                    </div>
                    <input type="text" name="title" maxlength="100" class="ann-title-in" placeholder="Headline (optional)">
                    <textarea name="body" id="updBody" maxlength="1000" placeholder="What does your club need to know?" required></textarea>
                    <?php ann_pickers('announcement', 'public'); ?>
                    <div class="ann-bar">
                        <label class="upd-notify"><input type="checkbox" name="notify" value="1" checked> Notify members</label>
                        <span class="counter"><span id="updCount">0</span> / 1000</span>
                        <button type="submit" name="action" value="post_update" class="btn-sm btn-dark">Post announcement</button>
                    </div>
                </form>
                <script>
                (function () {
                    var t = document.getElementById("updBody"), c = document.getElementById("updCount");
                    if (!t || !c) return;
                    function up() { c.textContent = t.value.length; }
                    t.addEventListener("input", up); up();
                })();
                </script>
            <?php } ?>

            <?php if ($postsSetupMissing) { ?>
                <?php if ($isOwner) { ?><div class="msg error">Updates aren't set up yet. Run <strong>club-updates.sql</strong> in phpMyAdmin.</div><?php } ?>
            <?php } elseif (!$posts && $hiddenPosts === 0) { ?>
                <div class="ann-empty">
                    <div class="ev-empty-ic"><?php echo event_icon('megaphone'); ?></div>
                    <strong><?php echo $isOwner ? "No announcements yet" : "Nothing posted yet"; ?></strong>
                    <p><?php echo $isOwner ? "Your first announcement will appear here for your club." : "When the club owner posts news, you'll see it here."; ?></p>
                </div>
            <?php } else { ?>
                <div class="ann-feed">
                    <?php foreach ($posts as $p) {
                        $isPinned = ((int)$p['pinned'] === 1);
                        $kind     = $p['kind'] ?? 'announcement';
                        $vis      = $p['visibility'] ?? 'public';
                        $title    = $p['title'] ?? '';
                        $kinds    = array('announcement' => array('Announcement', 'megaphone'), 'reminder' => array('Reminder', 'bell'), 'important' => array('Important', 'alert'));
                        if (!isset($kinds[$kind])) $kind = 'announcement';
                        $plink    = ($p['username'] === $me['username']) ? 'profile' : 'profile?u=' . urlencode($p['username']);
                        $fullDate = (new DateTime('@' . (int)$p['ts']))->setTimezone(event_tz())->format('M j, Y \a\t g:i A');
                    ?>
                        <article class="ann k-<?php echo e($kind); ?><?php echo $isPinned ? ' pinned' : ''; ?><?php echo $vis === 'members' ? ' is-members' : ''; ?>">
                            <div class="ann-top">
                                <span class="ann-kind k-<?php echo e($kind); ?>"><?php echo event_icon($kinds[$kind][1]); ?><?php echo e($kinds[$kind][0]); ?></span>
                                <?php if ($vis === 'members') { ?><span class="badge members"><?php echo event_icon('lock'); ?>Members only</span><?php } ?>
                                <?php if ($isPinned) { ?><span class="ann-pinned"><?php echo event_icon('pushpin'); ?>Pinned</span><?php } ?>
                                <time class="ann-time" title="<?php echo e($fullDate); ?>"><?php echo e(time_ago($p['ts'])); ?></time>
                            </div>

                            <?php if ($title !== '') { ?><h3 class="ann-title"><?php echo e($title); ?></h3><?php } ?>

                            <div class="ann-body" data-ann-body><?php echo post_html($p['body']); ?></div>
                            <button type="button" class="ann-more" hidden>Read more</button>

                            <div class="ann-foot">
                                <a href="<?php echo e($plink); ?>" class="ann-by">
                                    <span class="p-avatar tiny"><?php echo avatar_inner($p); ?></span>
                                    <span><strong><?php echo e(full_name($p)); ?></strong><em>Club owner<?php echo !empty($p['edited']) ? ' · Edited' : ''; ?></em></span>
                                </a>

                                <?php if ($isOwner) { ?>
                                    <div class="ann-actions">
                                        <form method="POST">
                                            <?php echo csrf_field(); ?>
                                            <input type="hidden" name="club_id" value="<?php echo (int)$club['id']; ?>">
                                            <input type="hidden" name="post_id" value="<?php echo (int)$p['id']; ?>">
                                            <button type="submit" name="action" value="pin_post"><?php echo event_icon('pushpin'); ?><?php echo $isPinned ? 'Unpin' : 'Pin'; ?></button>
                                        </form>
                                        <?php if ($annReady) { ?>
                                            <details class="ann-edit">
                                                <summary><?php echo event_icon('edit'); ?>Edit</summary>
                                                <form method="POST" class="ann-editform">
                                                    <?php echo csrf_field(); ?>
                                                    <input type="hidden" name="club_id" value="<?php echo (int)$club['id']; ?>">
                                                    <input type="hidden" name="post_id" value="<?php echo (int)$p['id']; ?>">
                                                    <input type="text" name="title" maxlength="100" class="ann-title-in" placeholder="Headline (optional)" value="<?php echo e($title); ?>">
                                                    <textarea name="body" maxlength="1000" required><?php echo e($p['body']); ?></textarea>
                                                    <?php ann_pickers($kind, $vis); ?>
                                                    <div class="ann-bar"><button type="submit" name="action" value="edit_post" class="btn-sm btn-dark">Save changes</button></div>
                                                </form>
                                            </details>
                                        <?php } ?>
                                        <form method="POST" onsubmit="return confirm('Delete this announcement?');">
                                            <?php echo csrf_field(); ?>
                                            <input type="hidden" name="club_id" value="<?php echo (int)$club['id']; ?>">
                                            <input type="hidden" name="post_id" value="<?php echo (int)$p['id']; ?>">
                                            <button type="submit" name="action" value="delete_post" class="del"><?php echo event_icon('trash'); ?>Delete</button>
                                        </form>
                                    </div>
                                <?php } ?>
                            </div>
                        </article>
                    <?php } ?>

                    <?php if ($hiddenPosts > 0) { ?>
                        <div class="ev-teaser">
                            <span class="ev-teaser-ic"><?php echo event_icon('lock'); ?></span>
                            <div>
                                <strong><?php echo $hiddenPosts; ?> members-only announcement<?php echo $hiddenPosts === 1 ? '' : 's'; ?></strong>
                                <p><?php echo $isPending ? 'Your request is pending. Once the owner accepts you, you will see them here.' : 'Join this club to read them.'; ?></p>
                            </div>
                        </div>
                    <?php } ?>
                </div>
                <?php if ($postsMore) { ?>
                    <p style="margin-top:16px"><a href="club?id=<?php echo (int)$club['id']; ?>&amp;more=1#updates">Show older announcements</a></p>
                <?php } ?>

                <script>
                (function () {
                    document.querySelectorAll("[data-ann-body]").forEach(function (el) {
                        el.classList.add("clamp");
                        var btn = el.nextElementSibling;
                        if (!btn || !btn.classList.contains("ann-more")) return;
                        if (el.scrollHeight > el.clientHeight + 2) {
                            btn.hidden = false;
                            btn.addEventListener("click", function () {
                                var open = el.classList.toggle("clamp");
                                btn.textContent = open ? "Read more" : "Show less";
                            });
                        } else {
                            el.classList.remove("clamp");
                        }
                    });
                })();
                </script>
            <?php } ?>
        </section>

        <?php if ($clubEvents || $isOwner || $hiddenEvents > 0) { ?>
            <section class="panel">
                <div class="club-head" style="margin-bottom:16px">
                    <h2 style="margin:0">Upcoming events</h2>
                    <?php if ($isOwner) { ?><a href="create-event?club=<?php echo (int)$club['id']; ?>" class="btn-sm btn-dark">Post an event</a><?php } ?>
                </div>
                <?php if ($clubEvents) { ?>
                    <div class="ev-list">
                        <?php foreach ($clubEvents as $ce) { render_event_card($ce, 'club?id=' . (int)$club['id']); } ?>
                    </div>
                <?php } elseif ($hiddenEvents === 0) { ?>
                    <p class="empty">No events planned yet. Post one so members know what's coming.</p>
                <?php } ?>
                <?php if ($hiddenEvents > 0) { ?>
                    <div class="ev-teaser">
                        <span class="ev-teaser-ic"><?php echo event_icon('lock'); ?></span>
                        <div>
                            <strong><?php echo $hiddenEvents; ?> members-only event<?php echo $hiddenEvents === 1 ? '' : 's'; ?> coming up</strong>
                            <p><?php echo $isPending ? 'Your request is pending. Once the owner accepts you, you will see them here.' : 'Join this club to see the details and RSVP.'; ?></p>
                        </div>
                    </div>
                <?php } ?>
            </section>
        <?php } ?>

        <section class="panel">
            <h2>Members (<?php echo $n; ?>)</h2>

            <?php if (!$members) { ?>
                <p class="empty">No members yet.</p>
            <?php } else { ?>
                <div class="member-list">
                    <?php foreach ($members as $m) {
                        $link = member_link($m, $me['username']);
                        $line = member_line($m);
                        $full = trim($m['first_name'] . ' ' . ($m['last_name'] ?? ''));
                    ?>
                        <div class="member">
                            <div class="p-avatar tiny"><?php echo avatar_inner($m); ?></div>
                            <div class="grow">
                                <strong>
                                    <?php if ($link !== '') { ?><a href="<?php echo e($link); ?>" class="plain"><?php echo e($full); ?></a><?php } else { echo e($full); } ?>
                                </strong>
                                <div class="club-meta">@<?php echo e($m['username']); ?><?php if ($line !== '') { ?> &middot; <?php echo e($line); ?><?php } ?></div>
                            </div>

                            <?php if ($m['role'] === 'owner') { ?>
                                <span class="badge approved">Owner</span>
                            <?php } elseif ($isOwner) { ?>
                                <form method="POST" onsubmit="return confirm('Remove this member from the club?');">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="club_id" value="<?php echo (int)$club['id']; ?>">
                                    <input type="hidden" name="member_id" value="<?php echo (int)$m['mid']; ?>">
                                    <button type="submit" name="action" value="remove" class="btn-sm btn-ghost">Remove</button>
                                </form>
                            <?php } ?>
                        </div>
                    <?php } ?>
                </div>
            <?php } ?>
        </section>

    <?php } ?>

</main>

</body>
</html>