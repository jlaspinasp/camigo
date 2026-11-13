<?php
// Messaging helpers: schema, blocks, mute, unread counts, attachments and groups.
// Safe to include more than once. Never crashes a page if a table is missing.

define('CHAT_MAX_FILE', 10 * 1024 * 1024);   // 10 MB per file
define('CHAT_MAX_FILES', 5);                  // per send
define('CHAT_GROUP_MAX', 50);                 // members per group

function chat_upload_dir() { return __DIR__ . '/uploads/chat'; }

// Creates uploads/chat if needed, tries to make it writable, and locks it against direct access.
// Returns true when PHP can save files there.
function chat_prepare_dir() {
    $dir = chat_upload_dir();
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    if (is_dir($dir) && !is_writable($dir)) @chmod($dir, 0755);
    if (!is_dir($dir) || !is_writable($dir)) {
        error_log("Chat upload folder is missing or not writable: " . $dir);
        return false;
    }
    if (!is_file($dir . '/.htaccess')) {
        @file_put_contents($dir . '/.htaccess', "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nOrder allow,deny\nDeny from all\n</IfModule>\n");
    }
    if (!is_file($dir . '/index.html')) @file_put_contents($dir . '/index.html', '');
    return true;
}

// the biggest file this server will actually accept (our 10 MB cap or the host's own limit, whichever is smaller)
function chat_ini_bytes($v) {
    $v = trim((string)$v);
    if ($v === '') return 0;
    $n = (float)$v; $u = strtolower(substr($v, -1));
    if ($u === 'g') $n *= 1073741824; elseif ($u === 'm') $n *= 1048576; elseif ($u === 'k') $n *= 1024;
    return (int)$n;
}
function chat_max_file() {
    $m = CHAT_MAX_FILE;
    foreach (array('upload_max_filesize', 'post_max_size') as $k) {
        $b = chat_ini_bytes(ini_get($k));
        if ($b > 0) $m = min($m, ($b > 131072) ? $b - 65536 : $b);
    }
    return max(262144, $m);
}

// ---------- schema: creates what's missing, once per session ----------
function chat_ensure_schema($conn) {
    if (!empty($_SESSION['chat_schema_v4'])) return true;

    $has = function ($table, $col) use ($conn) {
        $r = db_run($conn, "SHOW COLUMNS FROM `$table` LIKE '$col'");
        return $r['ok'] && $r['rows'];
    };

    $ok = true;

    // existing table gets the new columns
    foreach (array(
        'deleted_at'  => "ALTER TABLE messages ADD COLUMN deleted_at DATETIME NULL DEFAULT NULL",
        'file_path'   => "ALTER TABLE messages ADD COLUMN file_path VARCHAR(64) NULL DEFAULT NULL",
        'file_name'   => "ALTER TABLE messages ADD COLUMN file_name VARCHAR(180) NULL DEFAULT NULL",
        'file_mime'   => "ALTER TABLE messages ADD COLUMN file_mime VARCHAR(100) NULL DEFAULT NULL",
        'file_size'   => "ALTER TABLE messages ADD COLUMN file_size INT UNSIGNED NULL DEFAULT NULL",
    ) as $col => $sql) {
        if (!$has('messages', $col)) {
            db_run($conn, $sql);
            if (!$has('messages', $col)) $ok = false;      // couldn't add it: don't pretend it worked
        }
    }

    $q = array(
        "CREATE TABLE IF NOT EXISTS chat_blocks (
            blocker_id INT NOT NULL, blocked_id INT NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (blocker_id, blocked_id), KEY (blocked_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        // per-person settings for one conversation: kind 'u' = person, 'g' = group
        "CREATE TABLE IF NOT EXISTS chat_prefs (
            user_id INT NOT NULL, kind CHAR(1) NOT NULL, target_id INT NOT NULL,
            muted TINYINT(1) NOT NULL DEFAULT 0,
            cleared_id INT NOT NULL DEFAULT 0,
            PRIMARY KEY (user_id, kind, target_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS chat_groups (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(60) NOT NULL,
            created_by INT NOT NULL,
            club_id INT NULL DEFAULT NULL,
            project_id INT NULL DEFAULT NULL,
            photo VARCHAR(40) NULL DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY (club_id),
            UNIQUE KEY (project_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS chat_group_members (
            group_id INT NOT NULL, user_id INT NOT NULL,
            role VARCHAR(10) NOT NULL DEFAULT 'member',
            last_read_id INT NOT NULL DEFAULT 0,
            joined_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (group_id, user_id), KEY (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS group_messages (
            id INT AUTO_INCREMENT PRIMARY KEY,
            group_id INT NOT NULL, sender_id INT NOT NULL,
            kind VARCHAR(8) NOT NULL DEFAULT 'msg',
            body TEXT NOT NULL,
            file_path VARCHAR(64) NULL, file_name VARCHAR(180) NULL,
            file_mime VARCHAR(100) NULL, file_size INT UNSIGNED NULL,
            deleted_at DATETIME NULL DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY (group_id, id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    );
    foreach ($q as $sql) { $r = db_run($conn, $sql); if (!$r['ok']) $ok = false; }

    if ($ok && !$has('chat_groups', 'club_id')) {
        db_run($conn, "ALTER TABLE chat_groups ADD COLUMN club_id INT NULL DEFAULT NULL");
        db_run($conn, "ALTER TABLE chat_groups ADD UNIQUE KEY club_id (club_id)");
    }

    if ($ok && !$has('chat_groups', 'project_id')) {
        db_run($conn, "ALTER TABLE chat_groups ADD COLUMN project_id INT NULL DEFAULT NULL");
        db_run($conn, "ALTER TABLE chat_groups ADD UNIQUE KEY project_id (project_id)");
        if (!$has('chat_groups', 'project_id')) $ok = false;
    }
    if ($ok && !$has('chat_groups', 'photo')) {
        db_run($conn, "ALTER TABLE chat_groups ADD COLUMN photo VARCHAR(40) NULL DEFAULT NULL");
        if (!$has('chat_groups', 'photo')) $ok = false;
    }

    chat_prepare_dir();

    if ($ok) $_SESSION['chat_schema_v4'] = 1;
    return $ok;
}

// ---------- blocks ----------
function chat_blocked_by_me($conn, $me, $other) {
    $r = db_run($conn, "SELECT 1 FROM chat_blocks WHERE blocker_id = ? AND blocked_id = ? LIMIT 1", "ii", array((int)$me, (int)$other));
    return (bool)$r['rows'];
}
function chat_blocked_me($conn, $me, $other) {
    $r = db_run($conn, "SELECT 1 FROM chat_blocks WHERE blocker_id = ? AND blocked_id = ? LIMIT 1", "ii", array((int)$other, (int)$me));
    return (bool)$r['rows'];
}
// ids of everyone I blocked or who blocked me
function chat_block_ids($conn, $me) {
    $r = db_run($conn, "SELECT blocked_id AS x FROM chat_blocks WHERE blocker_id = ? UNION SELECT blocker_id FROM chat_blocks WHERE blocked_id = ?", "ii", array((int)$me, (int)$me));
    $o = array();
    foreach ($r['rows'] as $row) $o[] = (int)$row['x'];
    return $o;
}

// ---------- mute / clear ----------
function chat_pref($conn, $uid, $kind, $target) {
    $r = db_run($conn, "SELECT muted, cleared_id FROM chat_prefs WHERE user_id = ? AND kind = ? AND target_id = ?", "isi", array((int)$uid, $kind, (int)$target));
    return $r['rows'] ? array('muted' => (int)$r['rows'][0]['muted'], 'cleared' => (int)$r['rows'][0]['cleared_id']) : array('muted' => 0, 'cleared' => 0);
}
function chat_pref_set($conn, $uid, $kind, $target, $col, $val) {
    if (!in_array($col, array('muted', 'cleared_id'), true)) return false;
    $r = db_run($conn,
        "INSERT INTO chat_prefs (user_id, kind, target_id, $col) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE $col = VALUES($col)",
        "isii", array((int)$uid, $kind, (int)$target, (int)$val));
    return $r['ok'];
}
// {u:[ids], g:[ids]} of conversations this person muted (used by the live badge on every page)
function chat_muted_ids($conn, $uid) {
    $r = db_run($conn, "SELECT kind, target_id FROM chat_prefs WHERE user_id = ? AND muted = 1", "i", array((int)$uid));
    $o = array('u' => array(), 'g' => array());
    foreach ($r['rows'] as $row) $o[$row['kind'] === 'g' ? 'g' : 'u'][] = (int)$row['target_id'];
    return $o;
}

// ---------- unread totals (muted, blocked and cleared chats don't count) ----------
function chat_unread_total($conn, $uid) {
    $uid = (int)$uid;
    $dm = db_run($conn,
        "SELECT COUNT(*) AS n FROM messages m
         LEFT JOIN chat_prefs p ON p.user_id = m.recipient_id AND p.kind = 'u' AND p.target_id = m.sender_id
         WHERE m.recipient_id = ? AND m.is_read = 0 AND m.deleted_at IS NULL
           AND COALESCE(p.muted, 0) = 0 AND m.id > COALESCE(p.cleared_id, 0)
           AND NOT EXISTS (SELECT 1 FROM chat_blocks b WHERE (b.blocker_id = m.recipient_id AND b.blocked_id = m.sender_id) OR (b.blocker_id = m.sender_id AND b.blocked_id = m.recipient_id))",
        "i", array($uid));
    if (!$dm['ok']) return null;           // new columns missing: caller falls back
    $n = $dm['rows'] ? (int)$dm['rows'][0]['n'] : 0;

    $g = db_run($conn,
        "SELECT COUNT(*) AS n FROM group_messages gm
         JOIN chat_group_members gmem ON gmem.group_id = gm.group_id AND gmem.user_id = ?
         LEFT JOIN chat_prefs p ON p.user_id = gmem.user_id AND p.kind = 'g' AND p.target_id = gm.group_id
         WHERE gm.id > gmem.last_read_id AND gm.sender_id <> ? AND gm.kind = 'msg' AND gm.deleted_at IS NULL
           AND COALESCE(p.muted, 0) = 0 AND gm.id > COALESCE(p.cleared_id, 0)",
        "ii", array($uid, $uid));
    if ($g['ok'] && $g['rows']) $n += (int)$g['rows'][0]['n'];
    return $n;
}

// ---------- attachments ----------
function chat_allowed_types() {
    return array(
        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp',
        'pdf' => 'application/pdf', 'txt' => 'text/plain', 'csv' => 'text/csv',
        'doc' => 'application/msword', 'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls' => 'application/vnd.ms-excel', 'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'ppt' => 'application/vnd.ms-powerpoint', 'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'zip' => 'application/zip',
    );
}
function chat_is_image($mime) { return in_array($mime, array('image/jpeg', 'image/png', 'image/gif', 'image/webp'), true); }

// Validates and stores one uploaded file. Returns array(path, name, mime, size) or null (sets $err).
function chat_save_upload($f, &$err) {
    if (!is_array($f) || !isset($f['error']) || $f['error'] === UPLOAD_ERR_NO_FILE) { $err = "No file was selected."; return null; }
    if ($f['error'] === UPLOAD_ERR_INI_SIZE || $f['error'] === UPLOAD_ERR_FORM_SIZE || (int)$f['size'] > chat_max_file()) { $err = "Files can be up to " . chat_size_label(chat_max_file()) . " on this server."; return null; }
    if ($f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) { $err = "That file didn't upload. Please try again."; return null; }

    $name = preg_replace('/[\x00-\x1f\/\\\\]+/', '', (string)$f['name']);
    $ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $types = chat_allowed_types();
    if (!isset($types[$ext])) { $err = "That file type isn't allowed. Try an image, PDF, Office file, text or ZIP."; return null; }

    $mime = $types[$ext];
    if (chat_is_image($mime)) {
        $info = @getimagesize($f['tmp_name']);
        if (!$info || !in_array($info[2], array(IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_GIF, defined('IMAGETYPE_WEBP') ? IMAGETYPE_WEBP : -1), true)) {
            $err = "That image looks damaged."; return null;
        }
    }

    if (!chat_prepare_dir()) { $err = "File uploads aren't set up on this server yet. Please tell the site admin."; return null; }
    $dir = chat_upload_dir();
    $stored = bin2hex(random_bytes(16)) . '.' . $ext;       // never the user's own name or extension trick
    if (!@move_uploaded_file($f['tmp_name'], $dir . '/' . $stored)) {
        error_log("Chat upload could not be saved to " . $dir);
        $err = "File uploads aren't set up on this server yet. Please tell the site admin."; return null;
    }

    return array('path' => $stored, 'name' => str_cut($name !== '' ? $name : 'file.' . $ext, 150), 'mime' => $mime, 'size' => (int)$f['size']);
}
function chat_delete_file($path) {
    if ($path && preg_match('/^[a-f0-9]{32}\.[a-z0-9]{2,4}$/', $path)) @unlink(chat_upload_dir() . '/' . $path);
}
function chat_file_public($path, $name, $mime, $size) {
    if (!$path) return null;
    $o = array('name' => (string)$name, 'mime' => (string)$mime, 'size' => (int)$size, 'image' => chat_is_image($mime));
    if ($o['image'] && preg_match('/^[a-f0-9]{32}\.[a-z0-9]{2,4}$/', (string)$path)) {
        // lets the page reserve the photo's exact space, so nothing jumps when it finishes loading
        $i = @getimagesize(chat_upload_dir() . '/' . $path);
        if ($i && $i[0] > 0 && $i[1] > 0) { $o['w'] = (int)$i[0]; $o['h'] = (int)$i[1]; }
    }
    return $o;
}

// ---------- groups ----------
function chat_group_role($conn, $gid, $uid) {
    $r = db_run($conn, "SELECT role FROM chat_group_members WHERE group_id = ? AND user_id = ? LIMIT 1", "ii", array((int)$gid, (int)$uid));
    return $r['rows'] ? $r['rows'][0]['role'] : null;
}
function chat_group_member_ids($conn, $gid) {
    $r = db_run($conn, "SELECT user_id FROM chat_group_members WHERE group_id = ?", "i", array((int)$gid));
    $o = array();
    foreach ($r['rows'] as $row) $o[] = (int)$row['user_id'];
    return $o;
}
function chat_group_sys($conn, $gid, $actor, $text) {
    $r = db_run($conn, "INSERT INTO group_messages (group_id, sender_id, kind, body) VALUES (?, ?, 'sys', ?)", "iis", array((int)$gid, (int)$actor, str_cut($text, 250)));
    return $r['ok'] ? (int)mysqli_insert_id($conn) : 0;
}
// colour for a group avatar, stable per group
function chat_group_photo_dir() { return __DIR__ . '/uploads/groups'; }
// has projects-logo.sql been run? (projects.logo = the project's uploaded logo)
function chat_project_logo_ready($conn) {
    static $ok = null;
    if ($ok === null) {
        $a = @mysqli_query($conn, "SHOW COLUMNS FROM projects LIKE 'logo'");
        $ok = ($a && mysqli_num_rows($a) > 0);
    }
    return $ok;
}
// the picture for a group: the club's logo for club chats, the project's logo for team chats, otherwise the photo an admin chose ('' = none)
function chat_group_logo_url($clubLogo, $photo, $projectLogo = '') {
    if (function_exists('club_image_ok') && club_image_ok($clubLogo)) return 'uploads/clubs/' . $clubLogo;
    if (function_exists('club_image_ok') && club_image_ok($projectLogo)) return 'uploads/projects/' . $projectLogo;
    if (function_exists('club_image_ok') && club_image_ok($photo))    return 'uploads/groups/' . $photo;
    return '';
}
function chat_group_hue($gid) { return ((int)$gid * 47) % 360; }

function chat_size_label($b) {
    if ($b >= 1048576) return round($b / 1048576, 1) . ' MB';
    if ($b >= 1024) return max(1, round($b / 1024)) . ' KB';
    return (int)$b . ' B';
}

// ---------- realtime push (to people's own channels) ----------
function chat_push($ids, $event, $data) {
    if (!function_exists('pusher_enabled') || !pusher_enabled() || !$ids) return;
    $ch = array();
    foreach (array_unique($ids) as $id) $ch[] = user_channel($id);
    foreach (array_chunk($ch, 90) as $part) pusher_trigger($part, $event, $data);
}

// what the browser needs to draw one group in the chat list
function chat_group_convo($gid, $name, $count, $text, $logo = '', $clubId = 0, $projectId = 0) {
    return array('k' => 'g', 'id' => (int)$gid, 'name' => $name, 'hue' => chat_group_hue($gid), 'members' => (int)$count,
                 'logo' => (string)$logo, 'club' => (int)$clubId, 'project' => (int)$projectId, 'ts' => time(), 'unread' => 0, 'muted' => false,
                 'last' => array('mine' => false, 'kind' => 'sys', 'gone' => false, 'body' => $text, 'file' => false, 'sender' => ''));
}

// ---------- clubs and projects get a group chat; their members are in it automatically ----------
function chat_club_sync_quiet($conn) {
    if (function_exists('fastcgi_finish_request')) @fastcgi_finish_request();
    chat_club_sync($conn);
}

// keeps every club chat and every project team chat in step with who is actually in the club / team
function chat_club_sync($conn) {
    if (!chat_ensure_schema($conn)) return;
    chat_sync_managed($conn, 'club');
    chat_sync_managed($conn, 'project');
}

function chat_sync_cfg($type) {
    if ($type === 'club') {
        return array('src' => 'clubs', 'title' => 'name', 'owner' => 'created_by', 'where' => "c.status = 'approved'",
                     'mem' => 'club_members', 'fk' => 'club_id', 'col' => 'club_id', 'staff' => "'owner','officer'",
                     'noun' => 'club', 'chat' => 'group chat', 'who' => 'Club members');
    }
    return array('src' => 'projects', 'title' => 'title', 'owner' => 'owner_id', 'where' => '1 = 1',
                 'mem' => 'project_members', 'fk' => 'project_id', 'col' => 'project_id', 'staff' => "'owner'",
                 'noun' => 'project', 'chat' => 'team chat', 'who' => 'Team members');
}

function chat_sync_managed($conn, $type) {
    $x = chat_sync_cfg($type);
    $col = $x['col']; $src = $x['src']; $title = $x['title']; $mem = $x['mem']; $fk = $x['fk'];
    foreach (array($src, $mem) as $tbl) {
        $t = db_run($conn, "SELECT 1 FROM $tbl LIMIT 1");
        if (!$t['ok']) return;                                   // that feature isn't installed yet
    }
    $logoOk = ($type === 'club' && function_exists('club_identity_ready') && club_identity_ready($conn))
              || ($type === 'project' && chat_project_logo_ready($conn));

    // 1. a group for each one, and keep the name in step
    db_run($conn, "INSERT INTO chat_groups (name, created_by, $col)
                   SELECT LEFT(c.$title, 60), c.{$x['owner']}, c.id FROM $src c
                   WHERE {$x['where']} AND NOT EXISTS (SELECT 1 FROM chat_groups g WHERE g.$col = c.id)");
    db_run($conn, "UPDATE chat_groups g JOIN $src c ON c.id = g.$col SET g.name = LEFT(c.$title, 60) WHERE g.name <> LEFT(c.$title, 60)");

    // 2. deleted (or no longer approved) clubs and projects lose their chat
    $dead = db_run($conn, "SELECT g.id FROM chat_groups g LEFT JOIN $src c ON c.id = g.$col
                           WHERE g.$col IS NOT NULL AND (c.id IS NULL OR NOT ({$x['where']}))");
    foreach ($dead['rows'] as $d) {
        $gid = (int)$d['id'];
        $files = db_run($conn, "SELECT file_path FROM group_messages WHERE group_id = ? AND file_path IS NOT NULL", "i", array($gid));
        foreach ($files['rows'] as $f) chat_delete_file($f['file_path']);
        $members = chat_group_member_ids($conn, $gid);
        db_run($conn, "DELETE FROM group_messages WHERE group_id = ?", "i", array($gid));
        db_run($conn, "DELETE FROM chat_group_members WHERE group_id = ?", "i", array($gid));
        db_run($conn, "DELETE FROM chat_prefs WHERE kind = 'g' AND target_id = ?", "i", array($gid));
        db_run($conn, "DELETE FROM chat_groups WHERE id = ?", "i", array($gid));
        chat_push($members, 'group-removed', array('gid' => $gid));
    }

    // 3. active members who aren't in the chat yet
    $add = db_run($conn,
        "SELECT g.id AS gid, g.name, c.id AS cid, " . ($logoOk ? "c.logo" : "NULL") . " AS logo, cm.user_id AS uid, cm.role, u.first_name,
                (SELECT COUNT(*) FROM group_messages x WHERE x.group_id = g.id) AS msgs
         FROM $mem cm
         JOIN chat_groups g ON g.$col = cm.$fk
         JOIN $src c ON c.id = cm.$fk
         JOIN users u ON u.id = cm.user_id
         LEFT JOIN chat_group_members gm ON gm.group_id = g.id AND gm.user_id = cm.user_id
         WHERE cm.status = 'active' AND gm.user_id IS NULL
         ORDER BY g.id, cm.id LIMIT 800");
    $byGroup = array();
    foreach ($add['rows'] as $r) $byGroup[(int)$r['gid']][] = $r;

    foreach ($byGroup as $gid => $rows) {
        $fresh = ((int)$rows[0]['msgs'] === 0);              // brand-new chat: add everyone quietly
        $newIds = array(); $firstNames = array();
        foreach ($rows as $r) {
            $role = in_array($r['role'], array('owner', 'officer'), true) ? 'admin' : 'member';
            db_run($conn, "INSERT IGNORE INTO chat_group_members (group_id, user_id, role) VALUES (?, ?, ?)", "iis", array($gid, (int)$r['uid'], $role));
            $newIds[] = (int)$r['uid']; $firstNames[] = $r['first_name'];
        }
        if ($fresh) $text = "Welcome! This is the " . $rows[0]['name'] . " " . $x['chat'] . ". " . $x['who'] . " are added automatically.";
        elseif (count($newIds) <= 3) $text = implode(', ', $firstNames) . " joined the " . $x['noun'];
        else $text = count($newIds) . " new members joined the " . $x['noun'];
        $sid = chat_group_sys($conn, $gid, $newIds[0], $text);
        // newcomers start "caught up": the welcome line isn't an unread message anyway
        db_run($conn, "UPDATE chat_group_members SET last_read_id = ? WHERE group_id = ? AND user_id IN (" . implode(',', array_fill(0, count($newIds), '?')) . ")",
            "ii" . str_repeat('i', count($newIds)), array_merge(array($sid, $gid), $newIds));

        $total = count(chat_group_member_ids($conn, $gid));
        $convo = chat_group_convo($gid, $rows[0]['name'], $total, $text, chat_group_logo_url($type === 'club' ? $rows[0]['logo'] : '', '', $type === 'project' ? $rows[0]['logo'] : ''),
                                  $type === 'club' ? (int)$rows[0]['cid'] : 0, $type === 'project' ? (int)$rows[0]['cid'] : 0);
        chat_push($newIds, 'group-added', array('convo' => $convo));
        $others = array_diff(chat_group_member_ids($conn, $gid), $newIds);
        chat_push($others, 'group-updated', array('gid' => $gid, 'name' => $rows[0]['name'], 'sys' => array('id' => $sid, 'ts' => time(), 'body' => $text)));
    }

    // 4. people who left (or were removed) leave the chat
    $gone = db_run($conn,
        "SELECT gm.group_id AS gid, gm.user_id AS uid, g.name, u.first_name
         FROM chat_group_members gm
         JOIN chat_groups g ON g.id = gm.group_id AND g.$col IS NOT NULL
         JOIN users u ON u.id = gm.user_id
         WHERE NOT EXISTS (SELECT 1 FROM $mem cm WHERE cm.$fk = g.$col AND cm.user_id = gm.user_id AND cm.status = 'active')
         LIMIT 800");
    $byGroup = array();
    foreach ($gone['rows'] as $r) $byGroup[(int)$r['gid']][] = $r;
    foreach ($byGroup as $gid => $rows) {
        $ids = array(); $names = array();
        foreach ($rows as $r) { $ids[] = (int)$r['uid']; $names[] = $r['first_name']; }
        $in = implode(',', array_fill(0, count($ids), '?')); $ty = str_repeat('i', count($ids));
        db_run($conn, "DELETE FROM chat_group_members WHERE group_id = ? AND user_id IN ($in)", "i" . $ty, array_merge(array($gid), $ids));
        db_run($conn, "DELETE FROM chat_prefs WHERE kind = 'g' AND target_id = ? AND user_id IN ($in)", "i" . $ty, array_merge(array($gid), $ids));
        $left = chat_group_member_ids($conn, $gid);
        if ($left) {
            $text = (count($ids) <= 3 ? implode(', ', $names) : count($ids) . " people") . " left the " . $x['noun'];
            $sid = chat_group_sys($conn, $gid, $ids[0], $text);
            chat_push($left, 'group-updated', array('gid' => $gid, 'name' => $rows[0]['name'], 'sys' => array('id' => $sid, 'ts' => time(), 'body' => $text)));
        }
        chat_push($ids, 'group-removed', array('gid' => $gid));
    }

    // 5. owners (and club officers) run the chat; everyone else is a member
    db_run($conn, "UPDATE chat_group_members gm
                   JOIN chat_groups g ON g.id = gm.group_id AND g.$col IS NOT NULL
                   JOIN $mem cm ON cm.$fk = g.$col AND cm.user_id = gm.user_id AND cm.status = 'active'
                   SET gm.role = IF(cm.role IN ({$x['staff']}), 'admin', 'member')");
}

function club_image_ok_safe($name) {
    return (function_exists('club_image_ok') && club_image_ok($name)) ? $name : '';
}

// the chat group for a club, or 0 (used for the "Group chat" button on the club page)
function chat_group_for_club($conn, $clubId) {
    $r = db_run($conn, "SELECT id FROM chat_groups WHERE club_id = ? LIMIT 1", "i", array((int)$clubId));
    return $r['rows'] ? (int)$r['rows'][0]['id'] : 0;
}

// the team chat for a project, or 0
function chat_group_for_project($conn, $projectId) {
    $r = db_run($conn, "SELECT id FROM chat_groups WHERE project_id = ? LIMIT 1", "i", array((int)$projectId));
    return $r['rows'] ? (int)$r['rows'][0]['id'] : 0;
}


// Streams one chat attachment to someone who is in that chat, then exits.
function chat_serve_file($conn, $me, $k, $id) {
    $k = ($k === 'g') ? 'g' : 'u';
    $id = (int)$id;
    if ($k === 'u') {
        $r = db_run($conn,
            "SELECT file_path, file_name, file_mime FROM messages
             WHERE id = ? AND deleted_at IS NULL AND file_path IS NOT NULL AND (sender_id = ? OR recipient_id = ?) LIMIT 1",
            "iii", array($id, $me['id'], $me['id']));
    } else {
        $r = db_run($conn,
            "SELECT m.file_path, m.file_name, m.file_mime FROM group_messages m
             JOIN chat_group_members gm ON gm.group_id = m.group_id AND gm.user_id = ?
             WHERE m.id = ? AND m.deleted_at IS NULL AND m.file_path IS NOT NULL LIMIT 1",
            "ii", array($me['id'], $id));
    }
    $f = $r['rows'] ? $r['rows'][0] : null;
    $path = $f ? chat_upload_dir() . '/' . $f['file_path'] : '';
    while (ob_get_level() > 0) { ob_end_clean(); }            // drop anything printed so far (blank lines, notices...)
    if (!$f || !preg_match('/^[a-f0-9]{32}\.[a-z0-9]{2,4}$/', $f['file_path']) || !is_file($path)) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo "File not found.";
        exit();
    }
    $isImage = chat_is_image($f['file_mime']);
    $inline  = $isImage && empty($_GET['dl']);
    $name    = preg_replace('/[\r\n"]+/', '', $f['file_name']);
    header('Content-Type: ' . ($isImage ? $f['file_mime'] : 'application/octet-stream'));
    header('X-Content-Type-Options: nosniff');
    header("Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'unsafe-inline'");
    header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . addcslashes($name, '"\\') . '"; filename*=UTF-8\'\'' . rawurlencode($f['file_name']));
    header('Cache-Control: private, max-age=86400');
    readfile($path);
    exit();
}