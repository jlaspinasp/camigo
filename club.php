<?php
session_start();
require("db.php");
require("auth.php");
require("events-lib.php");

$me  = require_user_row($conn);
if ($_SERVER['REQUEST_METHOD'] === 'POST') register_shutdown_function('chat_club_sync_quiet', $conn);

$cid = (int)($_GET['id'] ?? $_POST['club_id'] ?? 0);

// ---- the club (approved clubs only) ----
$cr = db_run($conn,
    "SELECT c.*,
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
$isOfficer = ($myRole === 'officer' && $myStatus === 'active');
$isPending = ($myStatus === 'pending');
$isMember  = ($myRole === 'member' && $myStatus === 'active');
$isStaff   = ($isOwner || $isOfficer);          // may post announcements and events
$isInside  = ($isStaff || $isMember);           // any active member of the club
$canSeePrivate = $isInside;                     // members-only announcements are visible to these people

// ---- the club's group chat (created automatically; members are added automatically) ----
$clubChatId = 0;
if ($club && $isInside && chat_ensure_schema($conn)) {
    $clubChatId = chat_group_for_club($conn, $cid);
    if (!$clubChatId || !chat_group_role($conn, $clubChatId, $me['id'])) {
        chat_club_sync($conn);
        $clubChatId = chat_group_for_club($conn, $cid);
    }
}

// ---- club identity + invite-only (club-identity.sql) ----
$identityReady  = ($club && club_identity_ready($conn));
$coverImg       = ($identityReady && club_image_ok($club['cover'] ?? '')) ? $club['cover'] : '';
$inviteOnly     = ($identityReady && !empty($club['invite_only']));
$inviteToken    = $inviteOnly ? (string)($club['invite_token'] ?? '') : '';
$givenInvite    = (string)($_GET['invite'] ?? $_POST['invite'] ?? '');
$hasValidInvite = ($inviteOnly && !$isInside && $inviteToken !== '' && $givenInvite !== '' && hash_equals($inviteToken, $givenInvite));
$memberTotal    = 0;

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

    } elseif ($action === 'join' && $inviteOnly && !$hasValidInvite) {
        flash_set("This club is invite-only. Ask a member for an invite link.", "error");

    } elseif ($action === 'join' && $inviteOnly) {
        // a valid invite link: no request needed, they're in straight away
        $r = db_run($conn,
            "INSERT INTO club_members (club_id, user_id, role, status) VALUES (?, ?, 'member', 'active')
             ON DUPLICATE KEY UPDATE status = IF(status = 'pending', 'active', status)",
            "ii", array($cid, $me['id']));
        if ($r['ok'] && $r['affected'] > 0) {
            db_run($conn,
                "INSERT INTO notifications (user_id, type, message, link)
                 SELECT user_id, 'club_update', ?, ? FROM club_members WHERE club_id = ? AND role = 'owner' AND status = 'active'",
                "ssi", array(str_cut(full_name($me) . ' joined ' . $club['name'] . ' with your invite link.', 255), 'club-members?id=' . $cid, $cid));
            flash_set("Welcome to " . $club['name'] . "! You're in.", "success");
        } else {
            flash_set("You're already in this club.", "error");
        }

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
        $r = db_run($conn, "DELETE FROM club_members WHERE club_id = ? AND user_id = ? AND role IN ('member', 'officer') AND status = 'active'", "ii", array($cid, $me['id']));
        if ($r['ok'] && $r['affected'] > 0) event_purge_member_rsvps($conn, $cid, $me['id']);
        flash_set(($r['ok'] && $r['affected'] > 0) ? "You left the club." : "You can't leave this club.", ($r['ok'] && $r['affected'] > 0) ? "success" : "error");

    } elseif (in_array($action, array('post_update', 'edit_post', 'delete_post', 'pin_post'), true)) {

        $hash = '#updates';
        $pid  = (int)($_POST['post_id'] ?? 0);

        if (!$isStaff) {
            flash_set("Only club owners and officers can do that.", "error");

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
                    $newPostId = (int)mysqli_insert_id($conn);
                    if (!empty($_POST['notify'])) {
                        $lead = ($c[2] === 'members') ? ' posted a members-only announcement in ' : ' posted an announcement in ';
                        $what = ($c[0] !== null) ? $c[0] : str_cut(preg_replace('/\s+/', ' ', $c[3]), 90);
                        $note = str_cut(full_name($me) . $lead . $club['name'] . ': ' . $what, 255);
                        db_run($conn,
                            "INSERT INTO notifications (user_id, type, message, link)
                             SELECT user_id, 'club_update', ?, ? FROM club_members
                             WHERE club_id = ? AND status = 'active' AND user_id <> ?",
                            "ssii", array($note, 'club?id=' . $cid . ($newPostId > 0 ? '&more=1#post-' . $newPostId : '#updates'), $cid, (int)$me['id']));
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
            // owners can delete any announcement; officers only the ones they posted
            $r = db_run($conn, "DELETE FROM club_posts WHERE id = ? AND club_id = ? AND (? = 1 OR user_id = ?)", "iiii", array($pid, $cid, $isOwner ? 1 : 0, $me['id']));
            $gone = ($r['ok'] && $r['affected'] > 0);
            flash_set($gone ? "Announcement deleted." : ($isOwner ? "That announcement was already removed." : "You can only delete announcements you posted."), $gone ? "success" : "error");

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

    } elseif (in_array($action, array('accept', 'decline'), true)) {

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
        }
    }

    $dest = ((($_POST['back'] ?? '') === 'dashboard') ? 'dashboard' : 'club?id=' . $cid . $hash);
    header("Location: " . $dest);
    exit();
}

// ---- member count + (for the owner) pending requests ----
$pending = array();
if ($club) {
    $sqlBase = "SELECT m.id AS mid, m.role, m.joined_at, u.username, u.first_name, u.last_name, u.course, u.year_level, u.photo, u.profile_visibility
                FROM club_members m JOIN users u ON u.id = m.user_id
                WHERE m.club_id = ? AND m.status = ";

    $tc = db_run($conn, "SELECT COUNT(*) AS n FROM club_members WHERE club_id = ? AND status = 'active'", "i", array($cid));
    $memberTotal = $tc['rows'] ? (int)$tc['rows'][0]['n'] : 0;

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
                    u.username, u.first_name, u.last_name, u.photo, pm.role AS author_role
             FROM club_posts p LEFT JOIN users u ON u.id = p.user_id
             LEFT JOIN club_members pm ON pm.club_id = p.club_id AND pm.user_id = p.user_id AND pm.status = 'active'
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
                    u.username, u.first_name, u.last_name, u.photo, pm.role AS author_role
             FROM club_posts p LEFT JOIN users u ON u.id = p.user_id
             LEFT JOIN club_members pm ON pm.club_id = p.club_id AND pm.user_id = p.user_id AND pm.status = 'active'
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

// ---- sidebar: who leads the club + a peek at the community ----
// (same rule as club-members: outsiders never see an invite-only member list)
$canSeeMembers = ($club && (!$inviteOnly || $isInside || (($me['role'] ?? 'user') === 'admin')));
$leaders = array();
$stackMembers = array();
if ($club && $canSeeMembers) {
    $leaders = db_run($conn,
        "SELECT m.role, u.username, u.first_name, u.last_name, u.course, u.year_level, u.photo
         FROM club_members m JOIN users u ON u.id = m.user_id
         WHERE m.club_id = ? AND m.status = 'active' AND m.role IN ('owner', 'officer')
         ORDER BY (m.role = 'owner') DESC, m.joined_at ASC",
        "i", array($cid))['rows'];
    $stackMembers = db_run($conn,
        "SELECT u.username, u.first_name, u.last_name, u.photo
         FROM club_members m JOIN users u ON u.id = m.user_id
         WHERE m.club_id = ? AND m.status = 'active'
         ORDER BY (m.role = 'owner') DESC, (m.role = 'officer') DESC, m.joined_at ASC LIMIT 5",
        "i", array($cid))['rows'];
}
function side_profile_link($u, $meUser) {
    return ($u['username'] === $meUser) ? 'profile' : 'profile?u=' . urlencode($u['username']);
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
if ($club && !$isInside) {
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

$n = (int)$memberTotal;
$creator = $club ? trim(($club['creator_first'] ?? '') . ' ' . ($club['creator_last'] ?? '')) : '';
if ($club && $creator === '' && !empty($club['created_by'])) $creator = DELETED_USER;   // the person who started it deleted their account

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

<main class="main<?php echo $club ? ' club-wide' : ' narrow'; ?>">

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

        <?php if ($hasValidInvite) { ?>
            <div class="msg success invite-banner">You've been invited to join <strong><?php echo e($club['name']); ?></strong>. Tap <strong>Accept invite</strong> to get in.</div>
        <?php } ?>

        <section class="profile-card">
            <div class="cover<?php echo $coverImg ? ' has-img' : ''; ?>"<?php if ($coverImg) { ?> style="background-image:url('uploads/clubs/<?php echo e($coverImg); ?>')"<?php } else { ?> data-g="<?php echo (int)$club['id'] % 6; ?>"<?php } ?>></div>
            <div class="profile-body">
                <?php echo club_tile_html($club, 'big'); ?>

                <div class="p-head">
                    <div>
                        <h1 class="p-name"><?php echo e($club['name']); ?></h1>
                        <div class="p-user">
                            <span class="chip"><?php echo e($club['category']); ?></span>
                            <?php if ($inviteOnly) { ?><span class="badge invite"><?php echo event_icon('lock'); ?>Invite only</span><?php } ?>
                            <?php if ($isOwner) { ?><span class="badge owner"><?php echo event_icon('crown'); ?>You're the owner</span><?php } ?>
                            <?php if ($isOfficer) { ?><span class="badge officer"><?php echo event_icon('star'); ?>You're an officer</span><?php } ?>
                        </div>
                    </div>

                    <div class="top-actions">
                        <?php if ($clubChatId) { ?>
                            <a href="messages?g=<?php echo (int)$clubChatId; ?>" class="btn-sm btn-ghost"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M5 4.5h14a1.5 1.5 0 011.5 1.5v9a1.5 1.5 0 01-1.5 1.5h-8L6.5 20.5V16.5H5A1.5 1.5 0 013.5 15V6A1.5 1.5 0 015 4.5z"/></svg>Group chat</a>
                        <?php } ?>
                        <?php if ($isOwner) { ?>
                            <a href="edit-club?id=<?php echo (int)$club['id']; ?>" class="btn-sm btn-ghost">Edit club</a>
                        <?php } else { ?>
                            <form method="POST" class="foot-right">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="club_id" value="<?php echo (int)$club['id']; ?>">
                                <?php if ($isPending) { ?>
                                    <span class="badge pending">Request sent</span>
                                    <button type="submit" name="action" value="cancel" class="btn-sm btn-ghost">Cancel</button>
                                <?php } elseif ($isMember || $isOfficer) { ?>
                                    <?php if (!$isOfficer) { ?><span class="badge approved">Member</span><?php } ?>
                                    <button type="submit" name="action" value="leave" class="btn-sm btn-ghost" onclick="return confirm('Leave this club?<?php echo $isOfficer ? ' You will lose your officer role.' : ''; ?>');">Leave</button>
                                <?php } else { ?>
                                    <?php if ($inviteOnly && !$hasValidInvite) { ?>
                                        <span class="badge invite"><?php echo event_icon('lock'); ?>Invite only</span>
                                    <?php } elseif ($hasValidInvite) { ?>
                                        <input type="hidden" name="invite" value="<?php echo e($givenInvite); ?>">
                                        <button type="submit" name="action" value="join" class="btn-sm btn-dark">Accept invite</button>
                                    <?php } else { ?>
                                        <button type="submit" name="action" value="join" class="btn-sm btn-dark">Request to join</button>
                                    <?php } ?>
                                <?php } ?>
                            </form>
                        <?php } ?>
                    </div>
                </div>

                <p class="p-bio"><?php echo nl2br(e($club['description'])); ?></p>
            </div>
        </section>

        <div class="club-layout">
        <div class="club-main">

        <?php if ($isOwner && $pending) { ?>
            <section class="panel" id="join-requests">
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

            <?php if ($isStaff && !$postsSetupMissing && !$annReady) { ?>
                <div class="msg error">Announcements need a one-time upgrade. Run <strong>club-announcements.sql</strong> in phpMyAdmin to unlock headlines, types and members-only posts.</div>
            <?php } ?>

            <?php if ($isStaff && $annReady && !$postsSetupMissing) { ?>
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
                <?php if ($isStaff) { ?><div class="msg error">Updates aren't set up yet. Run <strong>club-updates.sql</strong> in phpMyAdmin.</div><?php } ?>
            <?php } elseif (!$posts && $hiddenPosts === 0) { ?>
                <div class="ann-empty">
                    <div class="ev-empty-ic"><?php echo event_icon('megaphone'); ?></div>
                    <strong><?php echo $isStaff ? "No announcements yet" : "Nothing posted yet"; ?></strong>
                    <p><?php echo $isStaff ? "Your first announcement will appear here for your club." : "When the club's owner or officers post news, you'll see it here."; ?></p>
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
                        $gone     = user_gone($p);   // the author deleted their account: shown as "Deleted user", no profile to open
                        $plink    = $gone ? '' : (($p['username'] === $me['username']) ? 'profile' : 'profile?u=' . urlencode($p['username']));
                        $fullDate = (new DateTime('@' . (int)$p['ts']))->setTimezone(event_tz())->format('M j, Y \a\t g:i A');
                    ?>
                        <article id="post-<?php echo (int)$p['id']; ?>" class="ann k-<?php echo e($kind); ?><?php echo $isPinned ? ' pinned' : ''; ?><?php echo $vis === 'members' ? ' is-members' : ''; ?>">
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
                                <<?php echo $gone ? 'span' : 'a href="' . e($plink) . '"'; ?> class="ann-by<?php echo $gone ? ' is-gone' : ''; ?>">
                                    <span class="p-avatar tiny"><?php echo avatar_inner($p); ?></span>
                                    <span><strong><?php echo e(full_name($p)); ?></strong><em><?php
                                        $roleTxt = $gone ? '' : (($p['author_role'] ?? '') === 'owner' ? 'Club owner' : ((($p['author_role'] ?? '') === 'officer') ? 'Club officer' : 'Former officer'));
                                        echo e(trim($roleTxt . (!empty($p['edited']) ? ($roleTxt !== '' ? ' · ' : '') . 'Edited' : ''))); ?></em></span>
                                </<?php echo $gone ? 'span' : 'a'; ?>>

                                <?php if ($isStaff) { ?>
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
                                        <?php if ($isOwner || $p['username'] === $me['username']) { ?>
                                        <form method="POST" onsubmit="return confirm('Delete this announcement?');">
                                            <?php echo csrf_field(); ?>
                                            <input type="hidden" name="club_id" value="<?php echo (int)$club['id']; ?>">
                                            <input type="hidden" name="post_id" value="<?php echo (int)$p['id']; ?>">
                                            <button type="submit" name="action" value="delete_post" class="del"><?php echo event_icon('trash'); ?>Delete</button>
                                        </form>
                                        <?php } ?>
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

        </div><!-- /.club-main -->

        <aside class="club-side" aria-label="Club leaders and community">

            <?php if ($leaders) { ?>
                <section class="side-card">
                    <h2 class="side-label">Led by</h2>
                    <div class="lead-list">
                        <?php foreach ($leaders as $l) {
                            $isOwn = ($l['role'] === 'owner');
                            $line  = member_line($l);
                            $meta  = ($isOwn ? 'Club owner' : 'Officer') . ($line !== '' ? ' · ' . $line : '');
                        ?>
                            <a class="lead" href="<?php echo e(side_profile_link($l, $me['username'])); ?>">
                                <span class="lead-av"><?php echo avatar_inner($l); ?></span>
                                <span class="lead-info">
                                    <strong><?php echo e(full_name($l)); ?></strong>
                                    <em><?php echo e($meta); ?></em>
                                </span>
                                <span class="lead-badge <?php echo $isOwn ? 'owner' : 'officer'; ?>" title="<?php echo $isOwn ? 'Club owner' : 'Officer'; ?>"><?php echo event_icon($isOwn ? 'crown' : 'star'); ?></span>
                            </a>
                        <?php } ?>
                    </div>
                </section>
            <?php } ?>

            <section class="side-card">
                <h2 class="side-label">Community</h2>
                <?php if ($stackMembers) { ?>
                    <div class="av-stack">
                        <?php foreach ($stackMembers as $sm) { ?>
                            <a class="av-stack-item" href="<?php echo e(side_profile_link($sm, $me['username'])); ?>" title="<?php echo e(full_name($sm)); ?>"><?php echo avatar_inner($sm); ?></a>
                        <?php } ?>
                        <?php if ($n > count($stackMembers)) { ?>
                            <a class="av-stack-item more" href="club-members?id=<?php echo (int)$club['id']; ?>">+<?php echo $n - count($stackMembers); ?></a>
                        <?php } ?>
                    </div>
                <?php } else { ?>
                    <p class="side-count"><strong><?php echo $n; ?></strong> member<?php echo $n === 1 ? '' : 's'; ?></p>
                <?php } ?>

                <a class="side-link" href="club-members?id=<?php echo (int)$club['id']; ?>">
                    <?php echo $isOwner ? 'See all &amp; manage officers' : 'See all members'; ?> <span aria-hidden="true">&rarr;</span>
                </a>

                <p class="side-foot">
                    Started<?php if ($creator !== '') { ?> by <?php echo e($creator); ?><?php } ?> on <?php echo e(date('M j, Y', strtotime($club['created_at']))); ?>
                </p>
            </section>


            <?php if ($clubEvents || $isStaff || $hiddenEvents > 0) { ?>
                <section class="side-card">
                    <h2 class="side-label">Upcoming events</h2>

                    <?php if ($clubEvents) { ?>
                        <div class="side-evs">
                            <?php foreach (array_slice($clubEvents, 0, 3) as $ce) {
                                $cs   = event_dt($ce['start_at']);
                                $cid2 = (int)$ce['id'];
                                $cx   = (($ce['visibility'] ?? 'public') === 'members');
                                $cst  = event_state($ce);
                            ?>
                                <a class="side-ev" href="event?id=<?php echo $cid2; ?>">
                                    <span class="side-ev-date t<?php echo $cid2 % 3; ?>">
                                        <small><?php echo e($cs->format('M')); ?></small>
                                        <b><?php echo e($cs->format('j')); ?></b>
                                    </span>
                                    <span class="side-ev-info">
                                        <strong><?php echo e($ce['title']); ?><?php if ($cx) { ?><span class="side-ev-lock" title="Members only"><?php echo event_icon('lock'); ?></span><?php } ?></strong>
                                        <em><?php echo $cst === 'live' ? 'Happening now' : e($cs->format('D') . ' · ' . event_time_range($ce)); ?></em>
                                        <?php if (!empty($ce['location'])) { ?><em class="loc"><?php echo e($ce['location']); ?></em><?php } ?>
                                    </span>
                                </a>
                            <?php } ?>
                        </div>
                    <?php } elseif ($hiddenEvents === 0) { ?>
                        <p class="side-empty">No events planned yet.</p>
                    <?php } ?>

                    <?php if ($hiddenEvents > 0) { ?>
                        <div class="side-lock">
                            <span><?php echo event_icon('lock'); ?></span>
                            <p><strong><?php echo $hiddenEvents; ?> members-only event<?php echo $hiddenEvents === 1 ? '' : 's'; ?></strong><br><?php echo $isPending ? 'Available once the owner accepts you.' : 'Join this club to see them.'; ?></p>
                        </div>
                    <?php } ?>

                    <?php if ($clubEvents) { ?>
                        <a class="side-link" href="events">See all events <span aria-hidden="true">&rarr;</span></a>
                    <?php } ?>
                    <?php if ($isStaff) { ?>
                        <a href="create-event?club=<?php echo (int)$club['id']; ?>" class="btn-sm btn-dark side-btn">Post an event</a>
                    <?php } ?>
                </section>
            <?php } ?>

            <?php if ($isOwner) {
                $scheme    = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                $dir       = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
                $inviteUrl = ($inviteOnly && $inviteToken !== '') ? $scheme . '://' . $_SERVER['HTTP_HOST'] . $dir . '/club?id=' . (int)$club['id'] . '&invite=' . $inviteToken : '';
            ?>
                <section class="side-card">
                    <h2 class="side-label">Owner tools</h2>
                    <div class="tool-list">
                        <a class="tool" href="edit-club?id=<?php echo (int)$club['id']; ?>">
                            <span class="tool-ic"><?php echo event_icon('edit'); ?></span><span class="tool-name">Edit club</span><i aria-hidden="true">&rarr;</i>
                        </a>
                        <a class="tool" href="club-members?id=<?php echo (int)$club['id']; ?>">
                            <span class="tool-ic"><?php echo event_icon('users'); ?></span><span class="tool-name">Members &amp; officers</span><i aria-hidden="true">&rarr;</i>
                        </a>
                        <?php if ($pending) { ?>
                            <a class="tool" href="#join-requests">
                                <span class="tool-ic"><?php echo event_icon('plus'); ?></span><span class="tool-name">Join requests</span><b class="tool-count"><?php echo count($pending); ?></b>
                            </a>
                        <?php } else { ?>
                            <div class="tool is-idle">
                                <span class="tool-ic"><?php echo event_icon('plus'); ?></span><span class="tool-name">No pending requests</span>
                            </div>
                        <?php } ?>
                    </div>

                    <?php if ($inviteUrl !== '') { ?>
                        <div class="tool-invite">
                            <small>Invite link</small>
                            <div class="invite-row">
                                <input type="text" id="inviteUrl" readonly value="<?php echo e($inviteUrl); ?>" onclick="this.select()">
                                <button type="button" id="inviteCopy" class="btn-sm btn-ghost">Copy</button>
                            </div>
                        </div>
                        <script>
                        (function () {
                            var b = document.getElementById("inviteCopy"), i = document.getElementById("inviteUrl");
                            if (!b || !i) return;
                            b.addEventListener("click", function () {
                                function done() { b.textContent = "Copied"; setTimeout(function () { b.textContent = "Copy"; }, 1500); }
                                if (navigator.clipboard && navigator.clipboard.writeText) {
                                    navigator.clipboard.writeText(i.value).then(done, function () { i.select(); document.execCommand("copy"); done(); });
                                } else { i.select(); document.execCommand("copy"); done(); }
                            });
                        })();
                        </script>
                    <?php } ?>
                </section>
            <?php } ?>

        </aside>
        </div><!-- /.club-layout -->

    <?php } ?>

</main>

</body>
</html>