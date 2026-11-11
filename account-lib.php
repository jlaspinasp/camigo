<?php
// Deleting an account.
//
// What goes:   their private data (settings, verification and its proof photos, profile photos),
//              their memberships and RSVPs, and their notifications.
// What stays:  what they contributed to shared spaces, so other people's content isn't broken:
//              club announcements, project updates, group messages and direct-message history.
//              Those rows keep the old user id, which no longer matches anyone, so every page shows the
//              author as "Deleted user" (see user_gone() / full_name() in auth.php).
// What blocks: things they run that nobody else can: they have to delete those first
//              (account_blockers() lists them; the edit page shows the list).
require_once __DIR__ . '/events-lib.php';
require_once __DIR__ . '/projects-lib.php';
require_once __DIR__ . '/chat-lib.php';
require_once __DIR__ . '/verification-lib.php';

// What this person still has to delete before the account can go. Empty arrays = free to delete.
//   clubs    approved clubs where they are the only active owner
//   projects projects they founded
//   events   events they posted without a club that haven't finished yet
function account_blockers($conn, $uid) {
    $uid = (int)$uid;
    $out = array('clubs' => array(), 'projects' => array(), 'events' => array());
    if ($uid <= 0) return $out;

    $out['clubs'] = db_run($conn,
        "SELECT c.id, c.name FROM clubs c
         JOIN club_members m ON m.club_id = c.id AND m.user_id = ? AND m.role = 'owner' AND m.status = 'active'
         WHERE c.status = 'approved'
           AND NOT EXISTS (SELECT 1 FROM club_members o WHERE o.club_id = c.id AND o.role = 'owner' AND o.status = 'active' AND o.user_id <> ?)
         ORDER BY c.name ASC",
        "ii", array($uid, $uid))['rows'];

    $out['projects'] = db_run($conn, "SELECT id, title FROM projects WHERE owner_id = ? ORDER BY title ASC", "i", array($uid))['rows'];

    foreach (db_run($conn,
        "SELECT id, title, status, start_at, end_at FROM events WHERE created_by = ? AND club_id IS NULL AND status = 'active' ORDER BY start_at ASC",
        "i", array($uid))['rows'] as $ev) {
        if (event_state($ev) !== 'over') $out['events'][] = array('id' => (int)$ev['id'], 'title' => $ev['title']);
    }
    return $out;
}

function account_has_blockers($b) {
    return !empty($b['clubs']) || !empty($b['projects']) || !empty($b['events']);
}

// One plain sentence for the edit page, e.g. "Delete your 2 clubs, 1 project first."
function account_blockers_text($b) {
    $parts = array();
    $n = count($b['clubs']);    if ($n) $parts[] = $n . ($n === 1 ? ' club' : ' clubs');
    $n = count($b['projects']); if ($n) $parts[] = $n . ($n === 1 ? ' project' : ' projects');
    $n = count($b['events']);   if ($n) $parts[] = $n . ($n === 1 ? ' event' : ' events');
    return $parts ? ("You still own " . implode(', ', $parts) . ". Delete them first, then you can delete your account.") : '';
}

// LIKE pattern for "<name> ..." with the wildcard characters in the name made literal
function account_like_prefix($name) {
    return str_replace(array('\\', '%', '_'), array('\\\\', '\\%', '\\_'), $name) . ' %';
}

// $u is the full users row. Returns true when the account was removed.
function account_delete_everything($conn, $u) {
    $uid = (int)$u['id'];
    if ($uid <= 0) return false;

    // never leave a club, project or event with nobody able to run it
    if (account_has_blockers(account_blockers($conn, $uid))) return false;

    $name  = full_name($u);
    $first = trim((string)($u['first_name'] ?? ''));

    // ---- club requests that never became a club (pending / rejected): nothing is shared yet, so they just go ----
    foreach (db_run($conn, "SELECT * FROM clubs WHERE created_by = ? AND status <> 'approved'", "i", array($uid))['rows'] as $c) {
        club_delete_everything($conn, $c, 'its owner', '', $uid);
    }

    // ---- activity that goes with the person ----
    db_run($conn, "DELETE FROM event_rsvps WHERE user_id = ?", "i", array($uid));
    db_run($conn, "DELETE FROM event_reminders WHERE user_id = ?", "i", array($uid));
    db_run($conn, "DELETE FROM club_members WHERE user_id = ?", "i", array($uid));
    db_run($conn, "DELETE FROM project_members WHERE user_id = ?", "i", array($uid));
    // club_posts, project_updates and the events they hosted stay; their user id now matches nobody

    // ---- group chats: they leave; the messages they sent stay ----
    $groups = db_run($conn, "SELECT g.id, g.photo FROM chat_groups g JOIN chat_group_members gm ON gm.group_id = g.id WHERE gm.user_id = ?", "i", array($uid))['rows'];

    // the "Jane left / Jane added Sam" lines name people, so those go (or, in other people's lines, the name is swapped out)
    db_run($conn, "DELETE FROM group_messages WHERE sender_id = ? AND kind <> 'msg'", "i", array($uid));
    if ($first !== '') {
        $pat = '/(?<![\p{L}\p{N}])' . preg_quote($first, '/') . '(?![\p{L}\p{N}])/u';
        foreach ($groups as $g) {
            $sys = db_run($conn, "SELECT id, body FROM group_messages WHERE group_id = ? AND kind <> 'msg' AND body LIKE ?", "is",
                array((int)$g['id'], '%' . str_replace(array('\\', '%', '_'), array('\\\\', '\\%', '\\_'), $first) . '%'))['rows'];
            foreach ($sys as $m) {
                $new = preg_replace($pat, DELETED_USER, (string)$m['body']);
                if ($new !== null && $new !== $m['body']) {
                    db_run($conn, "UPDATE group_messages SET body = ? WHERE id = ?", "si", array(str_cut($new, 250), (int)$m['id']));
                }
            }
        }
    }

    foreach ($groups as $g) {
        $gid = (int)$g['id'];
        db_run($conn, "DELETE FROM chat_group_members WHERE group_id = ? AND user_id = ?", "ii", array($gid, $uid));
        $left = chat_group_member_ids($conn, $gid);
        if (!$left) {
            // nobody is left to read it
            $gf = db_run($conn, "SELECT file_path FROM group_messages WHERE group_id = ? AND file_path IS NOT NULL", "i", array($gid))['rows'];
            foreach ($gf as $f) chat_delete_file($f['file_path']);
            db_run($conn, "DELETE FROM group_messages WHERE group_id = ?", "i", array($gid));
            db_run($conn, "DELETE FROM chat_prefs WHERE kind = 'g' AND target_id = ?", "i", array($gid));
            db_run($conn, "DELETE FROM chat_groups WHERE id = ?", "i", array($gid));
            if (!empty($g['photo'])) delete_club_image($g['photo'], chat_group_photo_dir());
        } else {
            $ad = db_run($conn, "SELECT 1 FROM chat_group_members WHERE group_id = ? AND role = 'admin' LIMIT 1", "i", array($gid));
            if (!$ad['rows']) db_run($conn, "UPDATE chat_group_members SET role = 'admin' WHERE group_id = ? ORDER BY joined_at ASC LIMIT 1", "i", array($gid));
        }
    }

    // ---- direct messages: they stay for the other person, unless that person is gone too ----
    $peers = db_run($conn,
        "SELECT DISTINCT IF(sender_id = ?, recipient_id, sender_id) AS pid FROM messages WHERE sender_id = ? OR recipient_id = ?",
        "iii", array($uid, $uid, $uid))['rows'];
    foreach ($peers as $p) {
        $pid = (int)$p['pid'];
        if ($pid === $uid) continue;
        $alive = db_run($conn, "SELECT 1 FROM users WHERE id = ? LIMIT 1", "i", array($pid));
        if ($alive['rows']) continue;
        $files = db_run($conn, "SELECT file_path FROM messages WHERE ((sender_id = ? AND recipient_id = ?) OR (sender_id = ? AND recipient_id = ?)) AND file_path IS NOT NULL",
            "iiii", array($uid, $pid, $pid, $uid))['rows'];
        foreach ($files as $f) chat_delete_file($f['file_path']);
        db_run($conn, "DELETE FROM messages WHERE (sender_id = ? AND recipient_id = ?) OR (sender_id = ? AND recipient_id = ?)", "iiii", array($uid, $pid, $pid, $uid));
        db_run($conn, "DELETE FROM chat_prefs WHERE kind = 'u' AND target_id IN (?, ?)", "ii", array($uid, $pid));
    }

    // ---- their chat settings (other people's mute / clear settings about this chat are left alone) ----
    db_run($conn, "DELETE FROM chat_prefs WHERE user_id = ?", "i", array($uid));
    db_run($conn, "DELETE FROM chat_blocks WHERE blocker_id = ? OR blocked_id = ?", "ii", array($uid, $uid));

    // ---- notifications: theirs, and other people's that name them ("Jane requested to join ...") ----
    db_run($conn, "DELETE FROM notifications WHERE user_id = ?", "i", array($uid));
    if ($name !== '') {
        db_run($conn, "DELETE FROM notifications WHERE message LIKE ? ESCAPE '\\\\'", "s", array(account_like_prefix($name)));
    }
    db_run($conn, "DELETE FROM notification_prefs WHERE user_id = ?", "i", array($uid));

    // ---- verification requests and their private proof photos ----
    foreach (db_run($conn, "SELECT * FROM verifications WHERE user_id = ?", "i", array($uid))['rows'] as $v) {
        foreach (array('proof', 'proof2') as $col) {
            if (!empty($v[$col]) && proof_ok($v[$col])) @unlink(proof_dir() . '/' . $v[$col]);
        }
    }
    db_run($conn, "DELETE FROM verifications WHERE user_id = ?", "i", array($uid));

    // ---- the account itself, then its photos ----
    $r = db_run($conn, "DELETE FROM users WHERE id = ?", "i", array($uid));
    if (!($r['ok'] && $r['affected'] > 0)) return false;

    if (!empty($u['photo'])) delete_avatar($u['photo']);
    if (!empty($u['cover'])) delete_club_image($u['cover'], user_cover_dir());
    return true;
}