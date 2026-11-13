<?php
if (isset($_GET['file'])) { @ini_set('display_errors', '0'); ob_start(); }   // attachments: nothing may print before the file
session_start();
require("db.php");
require("auth.php");

$me = require_user_row($conn);
$meId = (int)$me['id'];
$embed = !empty($_GET['embed']);   // shown inside the floating chat window: just the chat, no top bar

// attachments are served from this same address, so they work wherever messages already works
if (isset($_GET['file'])) {
    session_write_close();
    chat_serve_file($conn, $me, isset($_GET['k']) ? $_GET['k'] : 'u', $_GET['file']);
}
// temporary diagnostics: only available while chat-check.php exists on the server
if (isset($_GET['check']) && is_file(__DIR__ . '/chat-check.php')) {
    define('CHAT_CHECK_EMBED', 1);
    require __DIR__ . '/chat-check.php';
    exit();
}

$rt = function_exists('pusher_enabled') && pusher_enabled();
$schemaOk = chat_ensure_schema($conn);

// every club has a group chat: make sure it exists and I'm in the ones I should be (cheap, at most every 30s)
if ($schemaOk && $_SERVER['REQUEST_METHOD'] !== 'POST' && (time() - (int)($_SESSION['club_chat_sync'] ?? 0)) > 30) {
    chat_club_sync($conn);
    $_SESSION['club_chat_sync'] = time();
}

function json_out($a) {
    header('Content-Type: application/json');
    echo json_encode($a, JSON_UNESCAPED_UNICODE);
    exit();
}
function safe_photo($row) {
    return (!empty($row['photo']) && preg_match('/^[a-f0-9]{24}\.jpg$/', $row['photo'])) ? $row['photo'] : '';
}
function person_out($u) {
    $gone = user_gone($u);   // their account was deleted: the chat stays, shown as "Deleted user"
    return array('id' => (int)$u['id'], 'username' => $gone ? '' : $u['username'], 'name' => full_name($u),
                 'photo' => safe_photo($u), 'initial' => $gone ? '?' : first_letter($u['first_name']), 'deleted' => $gone,
                 'v' => (!$gone && user_is_verified($u)) ? 1 : 0);
}

// a private profile can't be messaged first, but an existing conversation can continue
function can_message($conn, $meId, $p) {
    if (!empty($p['profile_visibility']) && $p['profile_visibility'] !== 'public') {
        $r = db_run($conn,
            "SELECT id FROM messages WHERE (sender_id = ? AND recipient_id = ?) OR (sender_id = ? AND recipient_id = ?) LIMIT 1",
            "iiii", array($meId, $p['id'], $p['id'], $meId));
        return (bool)$r['rows'];
    }
    return true;
}

// one message row -> what the browser needs
function out_msg($r, $k) {
    $gone = !empty($r['deleted_at']);
    $o = array('id' => (int)$r['id'], 'from' => (int)$r['sender_id'], 'ts' => (int)$r['ts'],
               'kind' => isset($r['kind']) ? $r['kind'] : 'msg', 'gone' => $gone,
               'body' => $gone ? '' : (string)$r['body'], 'file' => null);
    if (!$gone && !empty($r['file_path'])) {
        $f = chat_file_public($r['file_path'], $r['file_name'], $r['file_mime'], $r['file_size']);
        $f['url'] = 'messages?file=' . $o['id'] . '&k=' . $k;
        $o['file'] = $f;
    }
    if ($k === 'g' && $o['kind'] === 'msg') {
        $o['name'] = full_name($r); $o['username'] = $r['username'] ?? '';
        $o['photo'] = safe_photo($r); $o['initial'] = first_letter($r['first_name'] ?? '?');
        $o['deleted'] = user_gone($r);
        $o['v'] = (!$o['deleted'] && user_is_verified($r)) ? 1 : 0;
    }
    return $o;
}

$DM_COLS  = "m.id, m.sender_id, m.body, m.is_read, m.deleted_at, m.file_path, m.file_name, m.file_mime, m.file_size, UNIX_TIMESTAMP(m.created_at) AS ts";
$GRP_COLS = "m.id, m.sender_id, m.kind, m.body, m.deleted_at, m.file_path, m.file_name, m.file_mime, m.file_size, UNIX_TIMESTAMP(m.created_at) AS ts, u.first_name, u.last_name, u.username, u.photo" . vsel($conn);
$logoOk   = function_exists('club_identity_ready') && club_identity_ready($conn);
$LOGO_SEL = $logoOk ? "cb.logo" : "NULL";
$PLOGO_SEL = chat_project_logo_ready($conn) ? "pj.logo" : "NULL";   // project team chats show the project's logo

// a group I'm in (club_id > 0 means the club manages who is in it)
function load_group($conn, $gid, $meId) {
    $r = db_run($conn,
        "SELECT g.id, g.name, g.created_by, g.club_id, g.project_id, g.photo, gm.role FROM chat_groups g
         JOIN chat_group_members gm ON gm.group_id = g.id AND gm.user_id = ? WHERE g.id = ? LIMIT 1",
        "ii", array($meId, (int)$gid));
    return $r['rows'] ? $r['rows'][0] : null;
}
function group_logo($conn, $g, $logoOk) {
    $club = ''; $proj = '';
    if (!empty($g['club_id']) && $logoOk) {
        $r = db_run($conn, "SELECT logo FROM clubs WHERE id = ? LIMIT 1", "i", array((int)$g['club_id']));
        if ($r['rows']) $club = $r['rows'][0]['logo'];
    }
    if (!empty($g['project_id']) && chat_project_logo_ready($conn)) {
        $r = db_run($conn, "SELECT logo FROM projects WHERE id = ? LIMIT 1", "i", array((int)$g['project_id']));
        if ($r['rows']) $proj = $r['rows'][0]['logo'];
    }
    return chat_group_logo_url($club, $g['photo'] ?? '', $proj);
}

// ================= JSON ACTIONS =================
// a file bigger than the host allows arrives as an empty POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST) && !empty($_SERVER['CONTENT_LENGTH'])) {
    json_out(array('ok' => false, 'error' => "That file is too big for this server (limit " . chat_size_label(chat_max_file()) . ")."));
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['act'])) {
    if (!csrf_ok()) json_out(array('ok' => false, 'error' => 'Your session expired. Please reload the page.'));
    if (!$schemaOk) json_out(array('ok' => false, 'error' => "Messaging isn't fully set up yet. Reload and try again."));

    $act = (string)$_POST['act'];
    $k   = (($_POST['k'] ?? 'u') === 'g') ? 'g' : 'u';
    $tid = (int)($_POST['id'] ?? 0);
    $unreadNow = function () use ($conn, $meId) {
        $n = chat_unread_total($conn, $meId);
        return $n === null ? unread_messages($conn, $meId) : $n;
    };

    // ---- who is on the other end of a direct chat ----
    $partner = null;
    $ghostId = 0;   // the other person deleted their account: the old chat stays, but it is read-only
    if ($k === 'u' && $tid > 0) {
        $pr = db_run($conn, "SELECT * FROM users WHERE id = ? LIMIT 1", "i", array($tid));
        if ($pr['rows']) { if ((int)$pr['rows'][0]['id'] !== $meId) $partner = $pr['rows'][0]; }
        elseif ($tid !== $meId) $ghostId = $tid;
    }

    // ---------- mark as read ----------
    if ($act === 'read') {
        if ($k === 'u' && $partner) {
            $r = db_run($conn, "UPDATE messages SET is_read = 1 WHERE recipient_id = ? AND sender_id = ? AND is_read = 0", "ii", array($meId, $partner['id']));
            if ($r['ok'] && $r['affected'] > 0) chat_push(array($partner['id']), 'seen', array('by' => $meId));
        } elseif ($k === 'u' && $ghostId) {
            db_run($conn, "UPDATE messages SET is_read = 1 WHERE recipient_id = ? AND sender_id = ? AND is_read = 0", "ii", array($meId, $ghostId));
        } elseif ($k === 'g' && load_group($conn, $tid, $meId)) {
            db_run($conn, "UPDATE chat_group_members SET last_read_id = (SELECT COALESCE(MAX(id),0) FROM group_messages WHERE group_id = ?) WHERE group_id = ? AND user_id = ?", "iii", array($tid, $tid, $meId));
        }
        json_out(array('ok' => true, 'unread' => $unreadNow()));
    }

    // ---------- send (text and/or a file) ----------
    if ($act === 'send') {
        $body = trim(str_replace("\r\n", "\n", $_POST['body'] ?? ''));
        $body = preg_replace("/\n{3,}/", "\n\n", $body);
        $hasFile = !empty($_FILES['file']) && ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
        $ts = time();
        $group = null;

        if ($k === 'u') {
            if ($ghostId) json_out(array('ok' => false, 'error' => "This account was deleted, so you can't reply."));
            if (!$partner) json_out(array('ok' => false, 'error' => "We couldn't find that student."));
            if (chat_blocked_by_me($conn, $meId, $partner['id'])) json_out(array('ok' => false, 'error' => "Unblock " . $partner['first_name'] . " to send a message."));
            if (chat_blocked_me($conn, $meId, $partner['id']))   json_out(array('ok' => false, 'error' => "You can't message this person."));
            if (!can_message($conn, $meId, $partner))            json_out(array('ok' => false, 'error' => "This student isn't taking new messages."));
        } else {
            $group = load_group($conn, $tid, $meId);
            if (!$group) json_out(array('ok' => false, 'error' => "You're not in this group."));
        }
        if ($body === '' && !$hasFile) json_out(array('ok' => false, 'error' => "Write a message first."));
        if (str_len($body) > 1000)     json_out(array('ok' => false, 'error' => "Messages can be up to 1,000 characters."));

        $tbl = ($k === 'u') ? 'messages' : 'group_messages';
        $rl = db_run($conn, "SELECT COUNT(*) AS n FROM $tbl WHERE sender_id = ? AND created_at > (NOW() - INTERVAL 1 MINUTE)", "i", array($meId));
        if ($rl['ok'] && $rl['rows'] && (int)$rl['rows'][0]['n'] >= 20) json_out(array('ok' => false, 'error' => "You're sending messages too fast. Wait a moment and try again."));

        $file = null;
        if ($hasFile) {
            $ferr = '';
            $file = chat_save_upload($_FILES['file'], $ferr);
            if (!$file) json_out(array('ok' => false, 'error' => $ferr));
        }
        $fp = $file ? $file['path'] : null; $fn = $file ? $file['name'] : null;
        $fm = $file ? $file['mime'] : null; $fs = $file ? $file['size'] : null;

        if ($k === 'u') {
            $r = db_run($conn, "INSERT INTO messages (sender_id, recipient_id, body, file_path, file_name, file_mime, file_size) VALUES (?, ?, ?, ?, ?, ?, ?)",
                "iissssi", array($meId, $partner['id'], $body, $fp, $fn, $fm, $fs));
        } else {
            $r = db_run($conn, "INSERT INTO group_messages (group_id, sender_id, body, file_path, file_name, file_mime, file_size) VALUES (?, ?, ?, ?, ?, ?, ?)",
                "iissssi", array($tid, $meId, $body, $fp, $fn, $fm, $fs));
        }
        if (!$r['ok']) { if ($file) chat_delete_file($file['path']); json_out(array('ok' => false, 'error' => "We couldn't send that. Please try again.")); }
        $mid = (int)mysqli_insert_id($conn);

        $fpub = $file ? chat_file_public($fp, $fn, $fm, $fs) : null;
        if ($fpub) $fpub['url'] = 'messages?file=' . $mid . '&k=' . $k;
        $evt = array('k' => $k, 'id' => $mid, 'from' => $meId, 'body' => $body, 'ts' => $ts, 'file' => $fpub,
                     'name' => full_name($me), 'username' => $me['username'], 'photo' => safe_photo($me), 'initial' => first_letter($me['first_name']), 'v' => user_is_verified($me) ? 1 : 0);

        if ($k === 'u') {
            chat_push(array($partner['id']), 'new-message', $evt);
        } else {
            $evt['gid'] = $tid; $evt['gname'] = $group['name'];
            db_run($conn, "UPDATE chat_group_members SET last_read_id = ? WHERE group_id = ? AND user_id = ?", "iii", array($mid, $tid, $meId));
            chat_push(array_diff(chat_group_member_ids($conn, $tid), array($meId)), 'new-message', $evt);
        }
        json_out(array('ok' => true, 'message' => array('id' => $mid, 'ts' => $ts, 'file' => $fpub)));
    }

    // ---------- unsend (only your own message) ----------
    if ($act === 'unsend') {
        $mid = (int)($_POST['mid'] ?? 0);
        $tbl = ($k === 'u') ? 'messages' : 'group_messages';
        $r = db_run($conn, "SELECT * FROM $tbl WHERE id = ? AND sender_id = ? AND deleted_at IS NULL LIMIT 1", "ii", array($mid, $meId));
        if (!$r['rows']) json_out(array('ok' => false, 'error' => "That message can't be unsent."));
        $row = $r['rows'][0];
        if ($k === 'g' && $row['kind'] !== 'msg') json_out(array('ok' => false, 'error' => "That message can't be unsent."));
        if ($k === 'u') $ok = db_run($conn, "UPDATE messages SET deleted_at = NOW(), body = '', file_path = NULL, file_name = NULL, file_mime = NULL, file_size = NULL, is_read = 1 WHERE id = ?", "i", array($mid));
        else            $ok = db_run($conn, "UPDATE group_messages SET deleted_at = NOW(), body = '', file_path = NULL, file_name = NULL, file_mime = NULL, file_size = NULL WHERE id = ?", "i", array($mid));
        if (!$ok['ok']) json_out(array('ok' => false, 'error' => "We couldn't unsend that. Please try again."));
        chat_delete_file($row['file_path']);

        if ($k === 'u') chat_push(array((int)$row['recipient_id']), 'unsent', array('k' => 'u', 'id' => $mid, 'from' => $meId));
        else            chat_push(array_diff(chat_group_member_ids($conn, (int)$row['group_id']), array($meId)), 'unsent', array('k' => 'g', 'id' => $mid, 'gid' => (int)$row['group_id'], 'from' => $meId));
        json_out(array('ok' => true, 'unread' => $unreadNow()));
    }

    // ---------- mute notifications ----------
    if ($act === 'mute') {
        if ($k === 'u' && !$partner) json_out(array('ok' => false, 'error' => 'Not found.'));
        if ($k === 'g' && !load_group($conn, $tid, $meId)) json_out(array('ok' => false, 'error' => 'Not found.'));
        $on = !empty($_POST['on']) ? 1 : 0;
        chat_pref_set($conn, $meId, $k, $tid, 'muted', $on);
        json_out(array('ok' => true, 'muted' => (bool)$on, 'unread' => $unreadNow()));
    }

    // ---------- block / unblock ----------
    if ($act === 'block') {
        if (!$partner) json_out(array('ok' => false, 'error' => 'Not found.'));
        if (!empty($_POST['on'])) db_run($conn, "INSERT IGNORE INTO chat_blocks (blocker_id, blocked_id) VALUES (?, ?)", "ii", array($meId, $partner['id']));
        else                      db_run($conn, "DELETE FROM chat_blocks WHERE blocker_id = ? AND blocked_id = ?", "ii", array($meId, $partner['id']));
        json_out(array('ok' => true, 'blocked' => !empty($_POST['on']), 'unread' => $unreadNow()));
    }

    // ---------- delete chat (for me only) ----------
    if ($act === 'clear') {
        if ($k === 'u') {
            $clearId = $partner ? (int)$partner['id'] : $ghostId;
            if (!$clearId) json_out(array('ok' => false, 'error' => 'Not found.'));
            $m = db_run($conn, "SELECT COALESCE(MAX(id),0) AS mx FROM messages WHERE (sender_id = ? AND recipient_id = ?) OR (sender_id = ? AND recipient_id = ?)", "iiii", array($meId, $clearId, $clearId, $meId));
            if ($ghostId && !(int)$m['rows'][0]['mx']) json_out(array('ok' => false, 'error' => 'Not found.'));
        } else {
            if (!load_group($conn, $tid, $meId)) json_out(array('ok' => false, 'error' => 'Not found.'));
            $m = db_run($conn, "SELECT COALESCE(MAX(id),0) AS mx FROM group_messages WHERE group_id = ?", "i", array($tid));
            db_run($conn, "UPDATE chat_group_members SET last_read_id = ? WHERE group_id = ? AND user_id = ?", "iii", array((int)$m['rows'][0]['mx'], $tid, $meId));
        }
        chat_pref_set($conn, $meId, $k, $tid, 'cleared_id', (int)$m['rows'][0]['mx']);
        if ($k === 'u') db_run($conn, "UPDATE messages SET is_read = 1 WHERE recipient_id = ? AND sender_id = ?", "ii", array($meId, $clearId));
        json_out(array('ok' => true, 'unread' => $unreadNow()));
    }

    // ---------- people picker for new groups / adding members ----------
    if ($act === 'people') {
        $q = trim((string)($_POST['q'] ?? ''));
        $skip = chat_block_ids($conn, $meId);
        $skip[] = $meId;
        if ($k === 'g' && $tid) $skip = array_merge($skip, chat_group_member_ids($conn, $tid));
        if ($q !== '') {
            $like = '%' . str_replace(array('%', '_'), array('\\%', '\\_'), $q) . '%';
            $r = db_run($conn, "SELECT id, username, first_name, last_name, photo" . vsel($conn, 'users') . " FROM users
                WHERE CONCAT(first_name, ' ', last_name) LIKE ? OR username LIKE ? ORDER BY first_name LIMIT 30", "ss", array($like, $like));
        } else {
            $r = db_run($conn, "SELECT u.id, u.username, u.first_name, u.last_name, u.photo" . vsel($conn) . " FROM users u
                JOIN (SELECT IF(sender_id = ?, recipient_id, sender_id) AS pid, MAX(id) AS mx FROM messages
                      WHERE sender_id = ? OR recipient_id = ? GROUP BY pid ORDER BY mx DESC LIMIT 20) t ON t.pid = u.id
                ORDER BY t.mx DESC", "iii", array($meId, $meId, $meId));
            if (!$r['rows']) $r = db_run($conn, "SELECT id, username, first_name, last_name, photo" . vsel($conn, 'users') . " FROM users ORDER BY first_name LIMIT 20");
        }
        $out = array();
        foreach ($r['rows'] as $u) {
            if (in_array((int)$u['id'], $skip, true)) continue;
            $out[] = person_out($u);
            if (count($out) >= 12) break;
        }
        json_out(array('ok' => true, 'people' => $out));
    }

    // ---------- groups ----------
    $pickIds = function () use ($conn, $meId) {
        $ids = array();
        foreach ((array)($_POST['members'] ?? array()) as $x) { $x = (int)$x; if ($x > 0 && $x !== $meId) $ids[$x] = $x; }
        $ids = array_values($ids);
        if (!$ids) return array();
        $blocked = chat_block_ids($conn, $meId);
        $r = db_run($conn, "SELECT id FROM users WHERE id IN (" . implode(',', array_fill(0, count($ids), '?')) . ")", str_repeat('i', count($ids)), $ids);
        $ok = array();
        foreach ($r['rows'] as $row) if (!in_array((int)$row['id'], $blocked, true)) $ok[] = (int)$row['id'];
        return $ok;
    };
    $names = function ($ids) use ($conn) {
        if (!$ids) return '';
        $r = db_run($conn, "SELECT first_name FROM users WHERE id IN (" . implode(',', array_fill(0, count($ids), '?')) . ")", str_repeat('i', count($ids)), $ids);
        $n = array(); foreach ($r['rows'] as $x) $n[] = $x['first_name'];
        return implode(', ', $n);
    };

    if ($act === 'group_create') {
        $name = trim(preg_replace('/\s+/', ' ', (string)($_POST['name'] ?? '')));
        $ids = $pickIds();
        if ($name === '' || str_len($name) > 60) json_out(array('ok' => false, 'error' => "Give your group a name (up to 60 characters)."));
        if (count($ids) < 1) json_out(array('ok' => false, 'error' => "Add at least one person."));
        if (count($ids) > CHAT_GROUP_MAX - 1) json_out(array('ok' => false, 'error' => "Groups can have up to " . CHAT_GROUP_MAX . " people."));

        $photoName = null;
        if (!empty($_FILES['file']) && ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            if ($_FILES['file']['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($_FILES['file']['tmp_name'])) json_out(array('ok' => false, 'error' => "That photo didn't upload. Try a smaller image."));
            $perr = '';
            $photoName = save_club_image($_FILES['file']['tmp_name'], 'logo', $perr, chat_group_photo_dir());
            if (!$photoName) json_out(array('ok' => false, 'error' => $perr));
        }

        $r = db_run($conn, "INSERT INTO chat_groups (name, created_by, photo) VALUES (?, ?, ?)", "sis", array($name, $meId, $photoName));
        if (!$r['ok']) { if ($photoName) delete_club_image($photoName, chat_group_photo_dir()); json_out(array('ok' => false, 'error' => "We couldn't create the group. Please try again.")); }
        $gid = (int)mysqli_insert_id($conn);
        db_run($conn, "INSERT INTO chat_group_members (group_id, user_id, role) VALUES (?, ?, 'admin')", "ii", array($gid, $meId));
        foreach ($ids as $uid) db_run($conn, "INSERT IGNORE INTO chat_group_members (group_id, user_id) VALUES (?, ?)", "ii", array($gid, $uid));
        $text = $me['first_name'] . " created the group";
        $sid = chat_group_sys($conn, $gid, $meId, $text);
        db_run($conn, "UPDATE chat_group_members SET last_read_id = ? WHERE group_id = ?", "ii", array($sid, $gid));

        chat_push($ids, 'group-added', array('convo' => chat_group_convo($gid, $name, count($ids) + 1, $text, $photoName ? 'uploads/groups/' . $photoName : '')));
        json_out(array('ok' => true, 'id' => $gid));
    }

    if (in_array($act, array('group_info', 'group_rename', 'group_add', 'group_remove', 'group_leave', 'group_photo', 'group_photo_remove'), true)) {
        $g = load_group($conn, $tid, $meId);
        if (!$g) json_out(array('ok' => false, 'error' => "You're not in this group."));
        $isManaged = !empty($g['club_id']) || !empty($g['project_id']);
        $isAdmin = ($g['role'] === 'admin') && !$isManaged;   // club and project chats are managed by the club / project itself
        $others  = function () use ($conn, $tid, $meId) { return array_values(array_diff(chat_group_member_ids($conn, $tid), array($meId))); };

        if ($act === 'group_info') {
            $r = db_run($conn, "SELECT u.id, u.username, u.first_name, u.last_name, u.photo, gm.role AS grole" . vsel($conn) . " FROM chat_group_members gm
                JOIN users u ON u.id = gm.user_id WHERE gm.group_id = ? ORDER BY (gm.role = 'admin') DESC, gm.joined_at ASC", "i", array($tid));
            $mem = array();
            foreach ($r['rows'] as $u) { $p = person_out($u); $p['role'] = $u['grole']; $mem[] = $p; }
            json_out(array('ok' => true, 'name' => $g['name'], 'admin' => $isAdmin, 'club' => (int)$g['club_id'], 'project' => (int)$g['project_id'], 'members' => $mem));
        }

        if ($isManaged) json_out(array('ok' => false, 'error' => "This chat belongs to the club or project. People join and leave it there."));

        if ($act === 'group_rename') {
            if (!$isAdmin) json_out(array('ok' => false, 'error' => "Only group admins can do that."));
            $name = trim(preg_replace('/\s+/', ' ', (string)($_POST['name'] ?? '')));
            if ($name === '' || str_len($name) > 60) json_out(array('ok' => false, 'error' => "Group names can be up to 60 characters."));
            db_run($conn, "UPDATE chat_groups SET name = ? WHERE id = ?", "si", array($name, $tid));
            $text = $me['first_name'] . " renamed the group to " . $name;
            $sid = chat_group_sys($conn, $tid, $meId, $text);
            $sys = array('id' => $sid, 'ts' => time(), 'body' => $text);
            chat_push($others(), 'group-updated', array('gid' => $tid, 'name' => $name, 'sys' => $sys));
            json_out(array('ok' => true, 'name' => $name, 'sys' => $sys));
        }

        if ($act === 'group_photo' || $act === 'group_photo_remove') {
            if (!$isAdmin) json_out(array('ok' => false, 'error' => "Only group admins can do that."));
            $newName = null;
            if ($act === 'group_photo') {
                $up = $_FILES['file'] ?? null;
                if (!$up || ($up['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($up['tmp_name'])) json_out(array('ok' => false, 'error' => "Choose an image (JPG, PNG or WebP) up to " . chat_size_label(chat_max_file()) . "."));
                $perr = '';
                $newName = save_club_image($up['tmp_name'], 'logo', $perr, chat_group_photo_dir());
                if (!$newName) json_out(array('ok' => false, 'error' => $perr));
            }
            db_run($conn, "UPDATE chat_groups SET photo = ? WHERE id = ?", "si", array($newName, $tid));
            if (!empty($g['photo'])) delete_club_image($g['photo'], chat_group_photo_dir());
            $logo = $newName ? 'uploads/groups/' . $newName : '';
            $text = $me['first_name'] . ($newName ? " changed the group photo" : " removed the group photo");
            $sid = chat_group_sys($conn, $tid, $meId, $text);
            $sys = array('id' => $sid, 'ts' => time(), 'body' => $text);
            chat_push($others(), 'group-updated', array('gid' => $tid, 'name' => $g['name'], 'logo' => $logo, 'sys' => $sys));
            json_out(array('ok' => true, 'logo' => $logo, 'sys' => $sys));
        }

        if ($act === 'group_add') {
            if (!$isAdmin) json_out(array('ok' => false, 'error' => "Only group admins can do that."));
            $have = chat_group_member_ids($conn, $tid);
            $ids = array_values(array_diff($pickIds(), $have));
            if (!$ids) json_out(array('ok' => false, 'error' => "Choose at least one person to add."));
            if (count($have) + count($ids) > CHAT_GROUP_MAX) json_out(array('ok' => false, 'error' => "Groups can have up to " . CHAT_GROUP_MAX . " people."));
            foreach ($ids as $uid) db_run($conn, "INSERT IGNORE INTO chat_group_members (group_id, user_id) VALUES (?, ?)", "ii", array($tid, $uid));
            $text = $me['first_name'] . " added " . $names($ids);
            $sid = chat_group_sys($conn, $tid, $meId, $text);
            db_run($conn, "UPDATE chat_group_members SET last_read_id = ? WHERE group_id = ? AND user_id IN (" . implode(',', array_fill(0, count($ids) + 1, '?')) . ")",
                "ii" . str_repeat('i', count($ids) + 1), array_merge(array($sid, $tid, $meId), $ids));
            $sys = array('id' => $sid, 'ts' => time(), 'body' => $text);
            chat_push($ids, 'group-added', array('convo' => chat_group_convo($tid, $g['name'], count($have) + count($ids), $text, chat_group_logo_url('', $g['photo']))));
            chat_push(array_diff($have, array($meId)), 'group-updated', array('gid' => $tid, 'name' => $g['name'], 'sys' => $sys));
            json_out(array('ok' => true, 'sys' => $sys));
        }

        if ($act === 'group_remove') {
            $uid = (int)($_POST['uid'] ?? 0);
            if (!$isAdmin || $uid === $meId) json_out(array('ok' => false, 'error' => "You can't do that."));
            $nm = $names(array($uid));
            $r = db_run($conn, "DELETE FROM chat_group_members WHERE group_id = ? AND user_id = ?", "ii", array($tid, $uid));
            if (!$r['affected']) json_out(array('ok' => false, 'error' => "That person isn't in the group."));
            $text = $me['first_name'] . " removed " . $nm;
            $sid = chat_group_sys($conn, $tid, $meId, $text);
            $sys = array('id' => $sid, 'ts' => time(), 'body' => $text);
            chat_push(array($uid), 'group-removed', array('gid' => $tid));
            chat_push($others(), 'group-updated', array('gid' => $tid, 'name' => $g['name'], 'sys' => $sys));
            json_out(array('ok' => true, 'sys' => $sys));
        }

        if ($act === 'group_leave') {
            db_run($conn, "DELETE FROM chat_group_members WHERE group_id = ? AND user_id = ?", "ii", array($tid, $meId));
            $left = chat_group_member_ids($conn, $tid);
            if (!$left) {
                $files = db_run($conn, "SELECT file_path FROM group_messages WHERE group_id = ? AND file_path IS NOT NULL", "i", array($tid));
                foreach ($files['rows'] as $f) chat_delete_file($f['file_path']);
                db_run($conn, "DELETE FROM group_messages WHERE group_id = ?", "i", array($tid));
                db_run($conn, "DELETE FROM chat_groups WHERE id = ?", "i", array($tid));
                if (!empty($g['photo'])) delete_club_image($g['photo'], chat_group_photo_dir());
            } else {
                $ad = db_run($conn, "SELECT 1 FROM chat_group_members WHERE group_id = ? AND role = 'admin' LIMIT 1", "i", array($tid));
                if (!$ad['rows']) db_run($conn, "UPDATE chat_group_members SET role = 'admin' WHERE group_id = ? ORDER BY joined_at ASC LIMIT 1", "i", array($tid));
                $text = $me['first_name'] . " left the group";
                $sid = chat_group_sys($conn, $tid, $meId, $text);
                chat_push($left, 'group-updated', array('gid' => $tid, 'name' => $g['name'], 'sys' => array('id' => $sid, 'ts' => time(), 'body' => $text)));
            }
            db_run($conn, "DELETE FROM chat_prefs WHERE user_id = ? AND kind = 'g' AND target_id = ?", "ii", array($meId, $tid));
            json_out(array('ok' => true, 'unread' => $unreadNow()));
        }
    }

    json_out(array('ok' => false, 'error' => 'Unknown action.'));
}

// ================= PAGE =================
$wantU = trim($_GET['u'] ?? '');
$wantG = (int)($_GET['g'] ?? 0);
$wantI = ($wantU === '') ? (int)($_GET['uid'] ?? 0) : 0;   // a chat with someone who deleted their account has no username to open it by

$boot = array('me' => $meId, 'meName' => full_name($me), 'deletedName' => DELETED_USER, 'csrf' => csrf_token(), 'rt' => $rt, 'embed' => $embed, 'convos' => array(), 'open' => null,
              'maxFile' => chat_max_file(), 'maxFiles' => CHAT_MAX_FILES, 'groupMax' => CHAT_GROUP_MAX,
              'accept' => '.' . implode(',.', array_keys(chat_allowed_types())));
$boot['draft'] = str_cut(trim((string)($_GET['draft'] ?? '')), 1000);   // text typed in the Students card
$setupMissing = !$schemaOk;

if (!$setupMissing) {
    $blockedIds = chat_block_ids($conn, $meId);
    $myBlocks = array();
    $br = db_run($conn, "SELECT blocked_id FROM chat_blocks WHERE blocker_id = ?", "i", array($meId));
    foreach ($br['rows'] as $x) $myBlocks[] = (int)$x['blocked_id'];

    // ---- direct chats: the latest message with each person ----
    $dm = db_run($conn,
        "SELECT IF(m.sender_id = ?, m.recipient_id, m.sender_id) AS pid,
                u.username, u.first_name, u.last_name, u.photo" . vsel($conn) . ",
                m.id AS mid, m.body, m.sender_id, m.deleted_at, m.file_path, m.file_mime, UNIX_TIMESTAMP(m.created_at) AS ts,
                COALESCE(p.muted, 0) AS muted,
                (SELECT COUNT(*) FROM messages x WHERE x.sender_id = IF(m.sender_id = ?, m.recipient_id, m.sender_id) AND x.recipient_id = ? AND x.is_read = 0 AND x.deleted_at IS NULL AND x.id > COALESCE(p.cleared_id, 0)) AS unread
         FROM messages m
         LEFT JOIN users u ON u.id = IF(m.sender_id = ?, m.recipient_id, m.sender_id)
         LEFT JOIN chat_prefs p ON p.user_id = ? AND p.kind = 'u' AND p.target_id = IF(m.sender_id = ?, m.recipient_id, m.sender_id)
         WHERE m.id IN (SELECT MAX(id) FROM messages WHERE sender_id = ? OR recipient_id = ? GROUP BY IF(sender_id = ?, recipient_id, sender_id))
           AND m.id > COALESCE(p.cleared_id, 0)
         ORDER BY m.id DESC LIMIT 60",
        "iiiiiiiii", array($meId, $meId, $meId, $meId, $meId, $meId, $meId, $meId, $meId));
    foreach ($dm['rows'] as $c) {
        $c['id'] = (int)$c['pid'];   // the other person's id, even when their account is gone (username is then NULL)
        $blocked = in_array((int)$c['id'], $blockedIds, true);
        $o = person_out($c);
        $o['k'] = 'u';
        $o['last'] = array('mine' => ((int)$c['sender_id'] === $meId), 'kind' => 'msg', 'gone' => !empty($c['deleted_at']),
                           'body' => (string)$c['body'], 'file' => !empty($c['file_path']) ? (chat_is_image($c['file_mime']) ? 'image' : 'file') : false,
                           'sender' => user_gone($c) ? DELETED_USER : $c['first_name']);
        $o['ts'] = (int)$c['ts'];
        $o['mid'] = (int)$c['mid'];
        $o['unread'] = $blocked ? 0 : (int)$c['unread'];
        $o['muted'] = (bool)$c['muted'];
        $o['blocked'] = in_array((int)$c['id'], $myBlocks, true);
        $boot['convos'][] = $o;
    }

    // ---- group chats ----
    $gr = db_run($conn,
        "SELECT g.id, g.name, g.club_id, g.project_id, g.photo AS gphoto, $LOGO_SEL AS logo, $PLOGO_SEL AS plogo, COALESCE(p.muted, 0) AS muted,
                lm.id AS mid, lm.sender_id, lm.kind, lm.body, lm.deleted_at, lm.file_path, lm.file_mime,
                UNIX_TIMESTAMP(COALESCE(lm.created_at, g.created_at)) AS ts, su.first_name AS s_first,
                (SELECT COUNT(*) FROM chat_group_members c2 WHERE c2.group_id = g.id) AS members,
                (SELECT COUNT(*) FROM group_messages x WHERE x.group_id = g.id AND x.id > gm.last_read_id AND x.sender_id <> ? AND x.kind = 'msg' AND x.deleted_at IS NULL AND x.id > COALESCE(p.cleared_id, 0)) AS unread
         FROM chat_group_members gm
         JOIN chat_groups g ON g.id = gm.group_id
         LEFT JOIN clubs cb ON cb.id = g.club_id
         LEFT JOIN projects pj ON pj.id = g.project_id
         LEFT JOIN chat_prefs p ON p.user_id = gm.user_id AND p.kind = 'g' AND p.target_id = g.id
         LEFT JOIN group_messages lm ON lm.id = (SELECT MAX(id) FROM group_messages WHERE group_id = g.id)
         LEFT JOIN users su ON su.id = lm.sender_id
         WHERE gm.user_id = ? AND (lm.id IS NULL OR lm.id > COALESCE(p.cleared_id, 0))
         ORDER BY ts DESC LIMIT 60",
        "ii", array($meId, $meId));
    foreach ($gr['rows'] as $c) {
        $boot['convos'][] = array('k' => 'g', 'id' => (int)$c['id'], 'name' => $c['name'], 'hue' => chat_group_hue($c['id']),
            'logo' => chat_group_logo_url($c['logo'], $c['gphoto'], $c['plogo']), 'club' => (int)$c['club_id'], 'project' => (int)$c['project_id'],
            'members' => (int)$c['members'], 'ts' => (int)$c['ts'], 'mid' => (int)$c['mid'], 'unread' => (int)$c['unread'], 'muted' => (bool)$c['muted'],
            'last' => array('mine' => ((int)$c['sender_id'] === $meId), 'kind' => $c['kind'] ?? 'msg', 'gone' => !empty($c['deleted_at']),
                            'body' => (string)($c['body'] ?? ''), 'file' => !empty($c['file_path']) ? (chat_is_image($c['file_mime']) ? 'image' : 'file') : false,
                            'sender' => ($c['s_first'] ?? '') !== '' ? $c['s_first'] : (($c['kind'] ?? '') === 'msg' && !empty($c['mid']) ? DELETED_USER : '')));
    }
    usort($boot['convos'], function ($a, $b) { return $b['ts'] <=> $a['ts']; });

    // ---- the open conversation ----
    if ($wantG > 0) {
        $g = load_group($conn, $wantG, $meId);
        if ($g) {
            $pf = chat_pref($conn, $meId, 'g', $wantG);
            $tr = db_run($conn, "SELECT $GRP_COLS FROM group_messages m LEFT JOIN users u ON u.id = m.sender_id
                WHERE m.group_id = ? AND m.id > ? ORDER BY m.id DESC LIMIT 100", "ii", array($wantG, $pf['cleared']));
            $thread = array();
            foreach (array_reverse($tr['rows']) as $row) $thread[] = out_msg($row, 'g');
            $cnt = db_run($conn, "SELECT COUNT(*) AS n FROM chat_group_members WHERE group_id = ?", "i", array($wantG));
            db_run($conn, "UPDATE chat_group_members SET last_read_id = (SELECT COALESCE(MAX(id),0) FROM group_messages WHERE group_id = ?) WHERE group_id = ? AND user_id = ?", "iii", array($wantG, $wantG, $meId));
            $boot['open'] = array('k' => 'g', 'id' => (int)$g['id'], 'name' => $g['name'], 'hue' => chat_group_hue($g['id']),
                'logo' => group_logo($conn, $g, $logoOk), 'club' => (int)$g['club_id'], 'project' => (int)$g['project_id'],
                'members' => (int)$cnt['rows'][0]['n'], 'admin' => ($g['role'] === 'admin' && empty($g['club_id']) && empty($g['project_id'])), 'muted' => (bool)$pf['muted'],
                'allowed' => true, 'thread' => $thread, 'channel' => 'private-group-' . (int)$g['id'], 'seen' => false);
        } else {
            $boot['open'] = array('k' => 'g', 'missing' => true);
        }
    } elseif ($wantU !== '' || $wantI > 0) {
        $deleted = false;
        if ($wantU !== '') {
            $p = get_user_row($conn, $wantU);
        } else {
            $ur = db_run($conn, "SELECT * FROM users WHERE id = ? LIMIT 1", "i", array($wantI));
            $p = $ur['rows'] ? $ur['rows'][0] : null;
            if (!$p && $wantI !== $meId) {
                // account deleted: only open it when there really is a conversation to read
                $had = db_run($conn, "SELECT 1 FROM messages WHERE (sender_id = ? AND recipient_id = ?) OR (sender_id = ? AND recipient_id = ?) LIMIT 1", "iiii", array($meId, $wantI, $wantI, $meId));
                if ($had['rows']) { $deleted = true; $p = array('id' => $wantI, 'username' => null, 'first_name' => null, 'last_name' => null, 'photo' => null, 'profile_visibility' => null); }
            }
        }
        if ($p && (int)$p['id'] !== $meId) {
            $pid = (int)$p['id'];
            $pf = chat_pref($conn, $meId, 'u', $pid);
            $tr = db_run($conn, "SELECT $DM_COLS FROM messages m
                WHERE ((m.sender_id = ? AND m.recipient_id = ?) OR (m.sender_id = ? AND m.recipient_id = ?)) AND m.id > ?
                ORDER BY m.id DESC LIMIT 100", "iiiii", array($meId, $pid, $pid, $meId, $pf['cleared']));
            $thread = array();
            foreach (array_reverse($tr['rows']) as $row) $thread[] = out_msg($row, 'u');
            $seen = false;
            if ($tr['rows']) { $lm = $tr['rows'][0]; $seen = ((int)$lm['sender_id'] === $meId && (int)$lm['is_read'] === 1 && empty($lm['deleted_at'])); }

            $r = db_run($conn, "UPDATE messages SET is_read = 1 WHERE recipient_id = ? AND sender_id = ? AND is_read = 0", "ii", array($meId, $pid));
            if ($r['ok'] && $r['affected'] > 0) chat_push(array($pid), 'seen', array('by' => $meId));

            $boot['open'] = array_merge(person_out($p), array('k' => 'u', 'muted' => (bool)$pf['muted'],
                'blockedByMe' => $deleted ? false : chat_blocked_by_me($conn, $meId, $pid), 'blockedMe' => $deleted ? false : chat_blocked_me($conn, $meId, $pid),
                'allowed' => $deleted ? false : can_message($conn, $meId, $p), 'thread' => $thread, 'seen' => $seen,
                'channel' => chat_channel($meId, $pid)));
        } else {
            $boot['open'] = array('k' => 'u', 'missing' => true);
        }
    }

    // the chat I'm looking at is read
    if ($boot['open'] && !empty($boot['open']['id'])) {
        foreach ($boot['convos'] as &$cv) {
            if ($cv['k'] === $boot['open']['k'] && $cv['id'] === $boot['open']['id']) $cv['unread'] = 0;
        }
        unset($cv);
    }
}

$flash = flash_get();
$message = $flash ? $flash[0] : "";
$message_class = $flash ? $flash[1] : "";
$title = ($boot['open'] && !empty($boot['open']['name'])) ? $boot['open']['name'] . ' | ' : '';
$hasThread = ($wantU !== '' || $wantI > 0 || $wantG > 0);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo e($title); ?>Messages | Camigo</title>
    <link rel="icon" href="favicon.svg" type="image/svg+xml">
    <link rel="icon" href="favicon.ico" sizes="any">
    <link rel="apple-touch-icon" href="apple-touch-icon.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:wght@600;700;800&family=Instrument+Sans:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="messages.css?v=<?php echo (int)@filemtime(__DIR__ . '/messages.css'); ?>">
</head>
<body class="page-messages<?php echo $embed ? ' is-embed' : ''; ?>">

<?php
$noChatPopout = true;   // this page IS the chat, so the floating window stays out of its way
if ($embed) {
    // inside the floating window there is no top bar, but the chat still needs its live connection
    $unreadMsg = unread_messages($conn, $me['id']);
    $rtUserId  = $me['id'];
    include("realtime.php");
?>
<script>
// keep the page behind the window in step: its unread badge, and which chat is open right now
(function () {
    var RT = window.CamigoRT, P = null;
    try { if (window.parent && window.parent !== window && window.parent.CamigoPop) P = window.parent; } catch (e) {}
    if (!RT || !P) return;
    var base = RT.setBadge, open = "";
    RT.setBadge = function (n) {
        base(n);
        try { if (P.CamigoRT) { P.CamigoRT.muted = RT.muted; P.CamigoRT.setBadge(n); } } catch (e) {}
    };
    Object.defineProperty(RT, "open", {
        configurable: true,
        get: function () { return open; },
        set: function (v) { open = v; try { P.CamigoPop.watch(v); } catch (e) {} }
    });
})();
</script>
<?php } else { include("topbar.php"); } ?>

<main class="main main-wide">

    <?php if ($message != "") { ?>
        <div class="msg <?php echo e($message_class); ?>"><?php echo e($message); ?></div>
    <?php } ?>

    <?php if ($setupMissing) { ?>

        <div class="msg error">Messaging couldn't finish setting itself up. Run <strong>messages.sql</strong> in phpMyAdmin, then reload this page.</div>

    <?php } else { ?>

    <div class="chat <?php echo $hasThread ? 'has-thread' : ''; ?>" id="chat">
        <aside class="chat-list" id="chat-list">
            <div class="chat-list-head">
                <h1>Chats</h1>
                <div class="head-actions">
                    <button type="button" class="icon-btn" id="btn-new-group" title="Create group" aria-label="Create group">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="8" r="3.2"/><path d="M3 19c0-3 2.7-5 6-5s6 2 6 5"/><path d="M18 8v6M15 11h6"/></svg>
                    </button>
                    <a href="students" class="icon-btn" title="New message" aria-label="New message">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 20h4L19.5 8.5a2.1 2.1 0 00-3-3L5 17v3z"/><path d="M14.5 7.5l3 3"/></svg>
                    </a>
                </div>
            </div>
            <div class="chat-search">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><circle cx="11" cy="11" r="6.5"/><path d="M16 16l4 4"/></svg>
                <input type="search" id="list-search" placeholder="Search chats" autocomplete="off" aria-label="Search chats">
            </div>
            <div class="chat-tabs" role="tablist" id="chat-tabs">
                <button type="button" role="tab" class="on" data-f="all">All</button>
                <button type="button" role="tab" data-f="unread">Unread <span class="tab-n" id="tab-unread" hidden></span></button>
                <button type="button" role="tab" data-f="groups">Groups</button>
            </div>
            <div class="chat-items" id="chat-items"></div>
        </aside>

        <section class="chat-pane" id="chat-pane"></section>
    </div>

    <div class="modal" id="modal" hidden>
        <div class="modal-card" role="dialog" aria-modal="true" id="modal-card"></div>
    </div>
    <div class="toast" id="toast" hidden></div>

    <?php } ?>

</main>

<?php if (!$setupMissing) { ?>
<script>window.CHAT_BOOT = <?php echo json_encode($boot, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
<script src="confirm.js?v=<?php echo (int)@filemtime(__DIR__ . '/confirm.js'); ?>"></script>
<script src="messages.js?v=<?php echo (int)@filemtime(__DIR__ . '/messages.js'); ?>"></script>
<?php } ?>

</body>
</html>