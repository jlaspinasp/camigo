<?php
// Shared helpers for pages that need the logged-in user, admin checks, and safe DB calls.
// Pages must call session_start() and require("db.php") before using these.

// Realtime messaging settings (optional). The site works without this file.
if (is_file(__DIR__ . '/pusher_config.php')) {
    require_once __DIR__ . '/pusher_config.php';
}
require_once __DIR__ . '/chat-lib.php';

function e($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

// Runs one prepared statement. Never crashes the page if a table or column is missing.
function db_run($conn, $sql, $types = "", $params = array()) {
    $out = array('ok' => false, 'rows' => array(), 'affected' => 0, 'errno' => 0);

    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        error_log("DB prepare failed: " . mysqli_error($conn));
        $out['errno'] = mysqli_errno($conn);
        return $out;
    }

    if ($types !== "") {
        mysqli_stmt_bind_param($stmt, $types, ...$params);
    }

    $out['ok'] = mysqli_stmt_execute($stmt);
    $out['errno'] = mysqli_stmt_errno($stmt);

    if ($out['ok']) {
        $res = mysqli_stmt_get_result($stmt);
        if ($res) {
            while ($r = mysqli_fetch_assoc($res)) {
                $out['rows'][] = $r;
            }
        }
        $out['affected'] = mysqli_stmt_affected_rows($stmt);
    }

    mysqli_stmt_close($stmt);
    return $out;
}

function current_user($conn) {
    if (!isset($_SESSION['username'])) {
        return null;
    }
    $r = db_run($conn, "SELECT * FROM users WHERE username = ? LIMIT 1", "s", array($_SESSION['username']));
    return $r['rows'] ? $r['rows'][0] : null;
}

function require_login($conn) {
    $u = current_user($conn);
    if (!$u) {
        header("Location: login");
        exit();
    }
    if (!empty($_SESSION['needs_profile'])) {
        header("Location: complete-profile");
        exit();
    }
    return $u;
}

// Admin pages: anyone who is not an admin goes back to the dashboard.
// The role is read from the database on every request, so demoting someone takes effect immediately.
function require_admin($conn) {
    $u = require_login($conn);
    if (($u['role'] ?? 'user') !== 'admin') {
        header("Location: dashboard");
        exit();
    }
    return $u;
}

// ---- CSRF protection for forms ----
function csrf_token() {
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf'];
}
function csrf_field() {
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}
function csrf_ok() {
    return isset($_POST['csrf'], $_SESSION['csrf']) && hash_equals($_SESSION['csrf'], (string)$_POST['csrf']);
}

// ---- one-time messages that survive a redirect ----
function flash_set($text, $class) {
    $_SESSION['flash'] = array($text, $class);
}
function flash_get() {
    if (!isset($_SESSION['flash'])) return null;
    $f = $_SESSION['flash'];
    unset($_SESSION['flash']);
    return $f;
}

// ---- club helpers ----

// Officers: owners promote a few trusted members who can post announcements and events.
// Officers can NOT edit the club, handle join requests, invite people, manage members or touch the owner.
define('CLUB_MAX_OFFICERS', 8);

// 'owner', 'officer', 'member' (active only), or null if the person isn't an active member
function club_role($conn, $clubId, $userId) {
    $r = db_run($conn,
        "SELECT role FROM club_members WHERE club_id = ? AND user_id = ? AND status = 'active' LIMIT 1",
        "ii", array((int)$clubId, (int)$userId));
    return $r['rows'] ? $r['rows'][0]['role'] : null;
}

// Owners and officers may post announcements and events
function club_is_staff($conn, $clubId, $userId) {
    $role = club_role($conn, $clubId, $userId);
    return ($role === 'owner' || $role === 'officer');
}

// Has club-officers.sql been run? (If role is still an ENUM without 'officer', promoting would silently blank the role.)
function officers_ready($conn) {
    $c = mysqli_query($conn, "SHOW COLUMNS FROM club_members LIKE 'role'");
    if (!$c || !($row = mysqli_fetch_assoc($c))) return false;
    $type = strtolower((string)($row['Type'] ?? ''));
    return (strpos($type, 'enum(') !== 0) || (strpos($type, "'officer'") !== false);
}

function role_label($role) {
    return $role === 'owner' ? 'Owner' : ($role === 'officer' ? 'Officer' : 'Member');
}
// "Robotics Club!" and "robotics-club" both become "roboticsclub"
function club_key($name) {
    $n = function_exists('mb_strtolower') ? mb_strtolower($name, 'UTF-8') : strtolower($name);
    return preg_replace('/[^\p{L}\p{N}]+/u', '', $n);
}
function str_len($s) {
    return function_exists('mb_strlen') ? mb_strlen($s, 'UTF-8') : strlen($s);
}

// ================= PROFILE HELPERS =================

function get_user_row($conn, $username) {
    $r = db_run($conn, "SELECT * FROM users WHERE username = ? LIMIT 1", "s", array($username));
    return $r['rows'] ? $r['rows'][0] : null;
}

// For profile pages: needs a login, returns the full user row.
function require_user_row($conn) {
    if (!isset($_SESSION['username'])) {
        header("Location: login");
        exit();
    }
    if (!empty($_SESSION['needs_profile'])) {
        header("Location: complete-profile");
        exit();
    }
    $row = get_user_row($conn, $_SESSION['username']);
    if (!$row) {
        header("Location: logout");
        exit();
    }
    return $row;
}

function year_levels() {
    return array("1st year", "2nd year", "3rd year", "4th year", "5th year", "Graduate student");
}

// key => [label, allowed domain(s) separated by | ('' = any site), example link]
// "Instagram" .. "GitHub" always exist; the rest need profile-more.sql (edit-profile hides what isn't set up yet).
function social_networks() {
    return array(
        'instagram' => array('Instagram', 'instagram.com',            'https://instagram.com/yourname'),
        'facebook'  => array('Facebook',  'facebook.com',             'https://facebook.com/yourname'),
        'github'    => array('GitHub',    'github.com',               'https://github.com/yourname'),
        'discord'   => array('Discord',   'discord.gg|discord.com',   'https://discord.gg/yourinvite'),
        'youtube'   => array('YouTube',   'youtube.com|youtu.be',     'https://youtube.com/@yourchannel'),
        'tiktok'    => array('TikTok',    'tiktok.com',               'https://tiktok.com/@yourname'),
        'x'         => array('X',         'x.com|twitter.com',        'https://x.com/yourname'),
        'twitch'    => array('Twitch',    'twitch.tv',                'https://twitch.tv/yourname'),
        'spotify'   => array('Spotify',   'spotify.com',              'https://open.spotify.com/user/yourname'),
    );
}

// Thought-bubble emoji next to the profile photo: one emoji, or empty. Returns null (and sets $error) on bad input.
function parse_status_emoji($raw, &$error) {
    $v = trim((string)$raw);
    if ($v === '') return '';
    $len = function_exists('mb_strlen') ? mb_strlen($v, 'UTF-8') : strlen($v);
    if ($len > 8 || preg_match('/[\x00-\x7F]/', $v) || !preg_match('/^[^\p{L}\p{N}\s\p{P}\p{Sc}\p{Sm}\p{Cc}]+$/u', $v)) {
        $error = "Please pick a single emoji for your thought bubble.";
        return null;
    }
    return $v;
}

// Short text inside the thought bubble (optional, up to 40 characters). Returns null (and sets $error) if too long.
function parse_status_text($raw, &$error) {
    $v = trim(preg_replace('/\s+/', ' ', (string)$raw));
    if (str_len($v) > 40) {
        $error = "Your thought bubble can be up to 40 characters.";
        return null;
    }
    return $v;
}

// Prompts shown in an empty bubble on your own profile (changes daily)
function bubble_prompts() {
    return array('Pick a mythical creature as a pet', "What's your comfort show?", 'One song on repeat lately?', 'Coffee or tea person?', 'Your go-to study snack?', 'Dream elective you would take?', 'Best thing you ate this week?');
}

// A few quick picks for the thought bubble
function bubble_picks() {
    return array('🐱','🐶','🎧','🎮','📚','☕','💻','🎨','🎸','⚽','🏀','🍜','🌱','🚀','✨','🔥','🌙','🎬','📷','✈️','🧠','💪','🎯','😴');
}

// "Currently into" rows. Every one is optional; people only fill in what fits.
function currently_categories() {
    return array('Listening to', 'Reading', 'Watching', 'Playing', 'Learning', 'Building', 'Cooking', 'Training', 'Exploring', 'Obsessed with');
}

// Turns the saved JSON into array(array('Reading', 'Atomic Habits'), ...) in a fixed order
function currently_list($json) {
    $data = json_decode((string)$json, true);
    $out = array();
    if (!is_array($data)) return $out;
    foreach (currently_categories() as $cat) {
        if (isset($data[$cat]) && is_string($data[$cat]) && trim($data[$cat]) !== '') {
            $out[] = array($cat, trim($data[$cat]));
        }
    }
    return $out;
}

// $_POST['currently'] (category => text) -> JSON string or null. Sets $error on bad input.
function parse_currently($post, &$error) {
    $clean = array();
    if (!is_array($post)) return null;
    foreach (currently_categories() as $cat) {
        $v = isset($post[$cat]) && is_string($post[$cat]) ? trim(preg_replace('/\s+/', ' ', $post[$cat])) : '';
        if ($v === '') continue;
        if (str_len($v) > 60) {
            $error = "\"$cat\" can be up to 60 characters.";
            return null;
        }
        $clean[$cat] = $v;
    }
    return $clean ? json_encode($clean, JSON_UNESCAPED_UNICODE) : null;
}

function first_letter($s) {
    $s = (string)$s;
    $c = function_exists('mb_substr') ? mb_substr($s, 0, 1, 'UTF-8') : substr($s, 0, 1);
    return function_exists('mb_strtoupper') ? mb_strtoupper($c, 'UTF-8') : strtoupper($c);
}

// Photo if there is one, otherwise the first letter of the first name
function avatar_inner($row) {
    if (!empty($row['photo']) && preg_match('/^[a-f0-9]{24}\.jpg$/', $row['photo'])) {
        return '<img src="uploads/avatars/' . e($row['photo']) . '" alt="">';
    }
    return e(first_letter($row['first_name'] ?? '?'));
}

// Returns a clean https link, or null (and sets $error) if it isn't valid
function normalize_url($input, $domain, $label, &$error) {
    $u = trim((string)$input);
    if ($u === '') return null;

    if (!preg_match('#^https?://#i', $u)) {
        $u = 'https://' . $u;
    }

    if (strlen($u) > 200 || !filter_var($u, FILTER_VALIDATE_URL)) {
        $error = "That $label link doesn't look right.";
        return null;
    }

    if ($domain !== '') {
        $host = strtolower((string)parse_url($u, PHP_URL_HOST));
        $okHost = false;
        foreach (explode('|', $domain) as $d) {
            $suffix = '.' . $d;
            if ($host === $d || substr($host, -strlen($suffix)) === $suffix) { $okHost = true; break; }
        }
        if (!$okHost) {
            $error = "Your $label link needs to go to " . str_replace('|', ' or ', $domain) . ".";
            return null;
        }
    }
    return $u;
}

// "Robotics, Chess" -> "Robotics,Chess". Returns null (and sets $error) on bad input.
function parse_interests($raw, &$error, $max = 8) {
    $out = array();
    $seen = array();

    foreach (explode(',', (string)$raw) as $t) {
        $t = trim(preg_replace('/\s+/', ' ', $t));
        if ($t === '') continue;

        if (str_len($t) > 24) {
            $error = "Each interest can be up to 24 characters.";
            return null;
        }
        if (!preg_match('/^[\p{L}\p{N} &+#.\/\'-]+$/u', $t)) {
            $error = "Interests can use letters, numbers and these symbols: & + # . / - '";
            return null;
        }

        $k = function_exists('mb_strtolower') ? mb_strtolower($t, 'UTF-8') : strtolower($t);
        if (isset($seen[$k])) continue;
        $seen[$k] = true;
        $out[] = $t;
    }

    if ($max > 0 && count($out) > $max) {
        $error = "Please pick up to $max interests.";
        return null;
    }
    return implode(',', $out);
}

// Gentle sharpening after a big downscale so photos stay crisp instead of soft.
function sharpen_after_resize($img, $srcW, $dstW) {
    if ($dstW > 0 && $srcW / $dstW >= 1.4 && function_exists('imageconvolution')) {
        @imageconvolution($img, array(array(-1, -1, -1), array(-1, 16, -1), array(-1, -1, -1)), 8, 0);
    }
}

// ---- profile photos: re-drawn as a square JPEG (up to 800x800) with a random name ----
function avatar_dir() {
    return __DIR__ . '/uploads/avatars';
}

function save_avatar($tmpPath, &$error) {
    if (!function_exists('imagecreatetruecolor') || !function_exists('imagejpeg')) {
        $error = "Photo uploads aren't available on this server.";
        return null;
    }

    $info = @getimagesize($tmpPath);
    if (!$info) {
        $error = "That file isn't a valid image.";
        return null;
    }
    if ($info[0] * $info[1] > 20000000) {
        $error = "That image is too large. Please choose a smaller one.";
        return null;
    }

    $src = false;
    if ($info[2] === IMAGETYPE_JPEG && function_exists('imagecreatefromjpeg')) {
        $src = @imagecreatefromjpeg($tmpPath);
    } elseif ($info[2] === IMAGETYPE_PNG && function_exists('imagecreatefrompng')) {
        $src = @imagecreatefrompng($tmpPath);
    } elseif (defined('IMAGETYPE_WEBP') && $info[2] === IMAGETYPE_WEBP && function_exists('imagecreatefromwebp')) {
        $src = @imagecreatefromwebp($tmpPath);
    }
    if (!$src) {
        $error = "Please upload a JPG, PNG or WebP photo.";
        return null;
    }

    // phone photos: turn them the right way up
    if ($info[2] === IMAGETYPE_JPEG && function_exists('exif_read_data') && function_exists('imagerotate')) {
        $exif = @exif_read_data($tmpPath);
        $o = isset($exif['Orientation']) ? (int)$exif['Orientation'] : 1;
        $angle = ($o === 3) ? 180 : (($o === 6) ? -90 : (($o === 8) ? 90 : 0));
        if ($angle !== 0) {
            $rot = imagerotate($src, $angle, 0);
            if ($rot) {
                imagedestroy($src);
                $src = $rot;
            }
        }
    }

    // crop to a centered square, then shrink
    $w = imagesx($src);
    $h = imagesy($src);
    $side = min($w, $h);
    $sx = (int)(($w - $side) / 2);
    $sy = (int)(($h - $side) / 2);

    // up to 800px (sharp on retina screens); a small photo is never scaled UP, that only adds blur
    $out = max(1, min(800, $side));
    $dst = imagecreatetruecolor($out, $out);
    imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
    imagecopyresampled($dst, $src, 0, 0, $sx, $sy, $out, $out, $side, $side);
    sharpen_after_resize($dst, $side, $out);

    $dir = avatar_dir();
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }

    if (!is_dir($dir) || !is_writable($dir)) {
        imagedestroy($src);
        imagedestroy($dst);
        error_log("Avatar folder missing or not writable: " . $dir);
        $error = "The photo folder isn't writable. Create uploads/avatars next to profile.php and allow writing to it.";
        return null;
    }

    $name = bin2hex(random_bytes(12)) . '.jpg';
    $ok = @imagejpeg($dst, $dir . '/' . $name, 90);

    imagedestroy($src);
    imagedestroy($dst);

    if (!$ok) {
        $error = "We couldn't save your photo. Please try again.";
        return null;
    }
    return $name;
}

function delete_avatar($name) {
    if ($name && preg_match('/^[a-f0-9]{24}\.jpg$/', $name)) {
        @unlink(avatar_dir() . '/' . $name);
    }
}

// ---- Google profile picture ----
// Downloads the picture Google gives us and stores it like any uploaded photo.
// Only accepts https addresses on googleusercontent.com.
function import_google_photo($url) {
    if (!function_exists('curl_init')) return null;

    $scheme = parse_url($url, PHP_URL_SCHEME);
    $host   = strtolower((string)parse_url($url, PHP_URL_HOST));
    $suffix = '.googleusercontent.com';
    if ($scheme !== 'https' || ($host !== 'googleusercontent.com' && substr($host, -strlen($suffix)) !== $suffix)) {
        return null;
    }

    // ask for a larger version (…=s96-c becomes …=s800-c)
    $url = preg_replace('/=s\d+-c$/', '=s800-c', $url);

    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_MAXFILESIZE    => 3 * 1024 * 1024,
        CURLOPT_SSL_VERIFYPEER => true,
    ));
    $data = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($data === false) {
        error_log("Google photo download failed: " . curl_error($ch));
    }
    curl_close($ch);

    if ($data === false || $code !== 200 || strlen($data) > 3 * 1024 * 1024) {
        return null;
    }

    $tmp = tempnam(sys_get_temp_dir(), 'gph');
    if ($tmp === false || file_put_contents($tmp, $data) === false) {
        return null;
    }

    $err = "";
    $name = save_avatar($tmp, $err);
    @unlink($tmp);
    return $name;
}

// Sets the Google photo only if the user has no photo yet. Never overwrites one they chose.
function set_google_photo($conn, $username, $pictureUrl) {
    if (!$pictureUrl) return false;

    $name = import_google_photo($pictureUrl);
    if (!$name) return false;

    $r = db_run($conn,
        "UPDATE users SET photo = ? WHERE username = ? AND (photo IS NULL OR photo = '')",
        "ss", array($name, $username));

    if (!$r['ok'] || $r['affected'] < 1) {
        delete_avatar($name);
        return false;
    }
    return true;
}

// ---- clubs ----
function club_categories() {
    return array("Academic", "Arts & Culture", "Sports", "Technology", "Community Service", "Other");
}

// ================= NOTIFICATIONS =================

function full_name($row) {
    return trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
}

function str_cut($s, $max) {
    $s = (string)$s;
    return function_exists('mb_substr') ? mb_substr($s, 0, $max, 'UTF-8') : substr($s, 0, $max);
}

// Sends one notification. Never breaks the page if the table doesn't exist yet.
function notify($conn, $userId, $type, $message, $link = null) {
    if (!$userId) return false;
    $r = db_run($conn,
        "INSERT INTO notifications (user_id, type, message, link) VALUES (?, ?, ?, ?)",
        "isss", array((int)$userId, $type, str_cut($message, 255), $link));
    return $r['ok'];
}

// Notification categories people can switch off (label, description, types inside it)
function notif_categories() {
    return array(
        'clubs' => array('Clubs', 'Join requests, invites, approvals and officer changes.',
            array('club_approved','club_rejected','join_request','join_accepted','join_declined','club_invite','club_update','officer_promoted','officer_removed','removed')),
        'events' => array('Events', 'New events, changes, cancellations and reminders.',
            array('event_new','event_update','event_cancelled','event_reminder')),
        'projects' => array('Projects', 'Applications, team updates and new projects.',
            array('project_new','project_update','project_apply','project_accepted','project_declined','project_removed')),
    );
}

// Types this person has switched off. Empty if the preferences table doesn't exist yet.
function notif_muted_types($conn, $userId) {
    $r = db_run($conn, "SELECT category FROM notification_prefs WHERE user_id = ? AND enabled = 0", "i", array((int)$userId));
    $cats = notif_categories();
    $types = array();
    if ($r['ok']) {
        foreach ($r['rows'] as $row) {
            if (isset($cats[$row['category']])) $types = array_merge($types, $cats[$row['category']][2]);
        }
    }
    return $types;
}

// SQL piece that hides muted types: returns array(sql, types, params)
function notif_mute_sql($conn, $userId) {
    $m = notif_muted_types($conn, $userId);
    if (!$m) return array('', '', array());
    return array(' AND type NOT IN (' . implode(',', array_fill(0, count($m), '?')) . ')', str_repeat('s', count($m)), $m);
}

function unread_count($conn, $userId) {
    $mute = notif_mute_sql($conn, $userId);
    $r = db_run($conn, "SELECT COUNT(*) AS n FROM notifications WHERE user_id = ? AND is_read = 0" . $mute[0],
        "i" . $mute[1], array_merge(array((int)$userId), $mute[2]));
    return ($r['ok'] && $r['rows']) ? (int)$r['rows'][0]['n'] : 0;
}

// Unread messages (people + groups). Muted, blocked and cleared chats don't count.
// Falls back to the plain count if the new chat tables haven't been created yet.
function unread_messages($conn, $userId) {
    $n = chat_unread_total($conn, $userId);
    if ($n !== null) return $n;
    $r = db_run($conn, "SELECT COUNT(*) AS n FROM messages WHERE recipient_id = ? AND is_read = 0", "i", array((int)$userId));
    return ($r['ok'] && $r['rows']) ? (int)$r['rows'][0]['n'] : 0;
}

// Tells the owner(s) of a club that someone asked to join
function notify_join_request($conn, $clubId, $requesterRow) {
    $c = db_run($conn, "SELECT name FROM clubs WHERE id = ? LIMIT 1", "i", array((int)$clubId));
    if (!$c['rows']) return;
    $msg = str_cut(full_name($requesterRow) . ' requested to join ' . $c['rows'][0]['name'] . '.', 255);
    db_run($conn,
        "INSERT INTO notifications (user_id, type, message, link)
         SELECT user_id, 'join_request', ?, ? FROM club_members
         WHERE club_id = ? AND role = 'owner' AND status = 'active'",
        "ssi", array($msg, 'club?id=' . (int)$clubId, (int)$clubId));
}

// Who a membership row belongs to, and which club it's for
function member_info($conn, $memberId) {
    $r = db_run($conn,
        "SELECT m.user_id, m.club_id, c.name AS club_name
         FROM club_members m JOIN clubs c ON c.id = m.club_id WHERE m.id = ? LIMIT 1",
        "i", array((int)$memberId));
    return $r['rows'] ? $r['rows'][0] : null;
}

function time_ago($ts) {
    $d = time() - (int)$ts;
    if ($d < 60)     return "Just now";
    if ($d < 3600)   { $m = (int)floor($d / 60);   return $m . " min ago"; }
    if ($d < 86400)  { $h = (int)floor($d / 3600); return $h . " hour" . ($h === 1 ? "" : "s") . " ago"; }
    if ($d < 604800) { $x = (int)floor($d / 86400); return $x . " day" . ($x === 1 ? "" : "s") . " ago"; }
    return date('M j, Y', (int)$ts);
}

// style + icon for each kind of notification
function notif_look($type) {
    $check = '<path d="M5 12.5l4.5 4.5L19 7.5"/>';
    $cross = '<path d="M6 6l12 12M18 6L6 18"/>';
    $userp = '<circle cx="10" cy="8" r="3.5"/><path d="M3.5 20c0-3.3 2.9-5.5 6.5-5.5s6.5 2.2 6.5 5.5M19 8v6M16 11h6"/>';
    $bell  = '<path d="M6 16v-5a6 6 0 0112 0v5l1.5 2h-15L6 16zM10 21h4"/>';
    $mail  = '<rect x="3" y="5" width="18" height="14" rx="3"/><path d="M4 7.5l8 6 8-6"/>';
    $chat  = '<path d="M5 4.5h14a1.5 1.5 0 011.5 1.5v9a1.5 1.5 0 01-1.5 1.5h-8L6.5 20.5V16.5H5A1.5 1.5 0 013.5 15V6A1.5 1.5 0 015 4.5z"/>';
    $cal   = '<rect x="3" y="5" width="18" height="16" rx="3"/><path d="M3 10h18M8 3v4M16 3v4"/>';
    $clock = '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>';
    $rocket = '<path d="M12 3c3.5 1.6 6 5 6 9.5L15.5 15h-7L6 12.5C6 8 8.5 4.6 12 3z"/><circle cx="12" cy="9.5" r="1.6"/><path d="M9 15l-2 4.5 3.2-1.4M15 15l2 4.5-3.2-1.4"/>';

    switch ($type) {
        case 'club_approved':
        case 'join_accepted':
        case 'officer_promoted': return array('good', $check);
        case 'club_rejected':
        case 'join_declined':
        case 'removed':       return array('bad', $cross);
        case 'join_request':  return array('info', $userp);
        case 'club_invite':   return array('invite', $mail);
        case 'club_update':
        case 'officer_removed': return array('info', $chat);
        case 'event_new':
        case 'event_update':  return array('info', $cal);
        case 'event_cancelled': return array('bad', $cal);
        case 'event_reminder': return array('remind', $clock);
        case 'project_accepted': return array('good', $check);
        case 'project_declined':
        case 'project_removed':  return array('bad', $cross);
        case 'project_apply':    return array('info', $userp);
        case 'project_new':
        case 'project_update':   return array('info', $rocket);
        default:              return array('info', $bell);
    }
}

// ================= CLUB IDENTITY (logo, cover) + INVITE-ONLY CLUBS =================

// Has club-identity.sql been run? Checked once per request so older databases keep working.
function club_identity_ready($conn) {
    static $ready = null;
    if ($ready === null) {
        $c = @mysqli_query($conn, "SHOW COLUMNS FROM clubs LIKE 'invite_only'");
        $ready = ($c && mysqli_num_rows($c) > 0);
    }
    return $ready;
}

function club_is_invite_only($conn, $clubId) {
    if (!club_identity_ready($conn)) return false;
    $r = db_run($conn, "SELECT invite_only FROM clubs WHERE id = ? LIMIT 1", "i", array((int)$clubId));
    return ($r['rows'] && (int)$r['rows'][0]['invite_only'] === 1);
}

function club_image_dir() {
    return __DIR__ . '/uploads/clubs';
}

function club_image_ok($name) {
    return is_string($name) && preg_match('/^[a-f0-9]{24}\.jpg$/', $name) === 1;
}

// Re-draws an upload as a clean JPEG with a random name.
// $kind 'logo' = 400x400 square, 'cover' = 10:3 banner, up to 3840x1152. Returns the file name, or null (and sets $error).
function save_club_image($tmpPath, $kind, &$error, $dir = null) {
    if (!function_exists('imagecreatetruecolor') || !function_exists('imagejpeg')) {
        $error = "Image uploads aren't available on this server.";
        return null;
    }

    $info = @getimagesize($tmpPath);
    if (!$info) {
        $error = "That file isn't a valid image.";
        return null;
    }
    if ($info[0] * $info[1] > 20000000) {
        $error = "That image is too large. Please choose a smaller one.";
        return null;
    }

    $src = false;
    if ($info[2] === IMAGETYPE_JPEG && function_exists('imagecreatefromjpeg')) {
        $src = @imagecreatefromjpeg($tmpPath);
    } elseif ($info[2] === IMAGETYPE_PNG && function_exists('imagecreatefrompng')) {
        $src = @imagecreatefrompng($tmpPath);
    } elseif (defined('IMAGETYPE_WEBP') && $info[2] === IMAGETYPE_WEBP && function_exists('imagecreatefromwebp')) {
        $src = @imagecreatefromwebp($tmpPath);
    }
    if (!$src) {
        $error = "Please upload a JPG, PNG or WebP image.";
        return null;
    }

    // phone photos: turn them the right way up
    if ($info[2] === IMAGETYPE_JPEG && function_exists('exif_read_data') && function_exists('imagerotate')) {
        $exif = @exif_read_data($tmpPath);
        $o = isset($exif['Orientation']) ? (int)$exif['Orientation'] : 1;
        $angle = ($o === 3) ? 180 : (($o === 6) ? -90 : (($o === 8) ? 90 : 0));
        if ($angle !== 0) {
            $rot = imagerotate($src, $angle, 0);
            if ($rot) {
                imagedestroy($src);
                $src = $rot;
            }
        }
    }

    // the shape of the result (logo = square, cover = 10:3 banner)
    $rw = ($kind === 'cover') ? 1600 : 400;
    $rh = ($kind === 'cover') ? 480 : 400;

    // largest centered crop with that shape, then shrink
    $w = imagesx($src);
    $h = imagesy($src);
    $cw = $w;
    $ch = (int)round($w * $rh / $rw);
    if ($ch > $h) {
        $ch = $h;
        $cw = (int)round($h * $rw / $rh);
    }
    $sx = (int)(($w - $cw) / 2);
    $sy = (int)(($h - $ch) / 2);

    // covers are shown large (and on retina screens twice as sharp), so keep up to 3840px wide (4K)
    // and never scale a small photo UP (that only adds blur)
    if ($kind === 'cover') {
        $tw = max(1, min(3840, $cw));
        $th = (int)round($tw * $rh / $rw);
    } else {
        // logos: up to 800px, never scaled up
        $tw = max(1, min(800, $cw));
        $th = $tw;
    }

    $dst = imagecreatetruecolor($tw, $th);
    imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
    imagecopyresampled($dst, $src, 0, 0, $sx, $sy, $tw, $th, $cw, $ch);
    sharpen_after_resize($dst, $cw, $tw);

    if ($dir === null) $dir = club_image_dir();
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    if (!is_dir($dir) || !is_writable($dir)) {
        imagedestroy($src);
        imagedestroy($dst);
        error_log("Club image folder missing or not writable: " . $dir);
        $error = "The image folder isn't writable. Create the uploads/" . basename($dir) . " folder and allow writing to it.";
        return null;
    }

    $name = bin2hex(random_bytes(12)) . '.jpg';
    if ($kind === 'cover') imageinterlace($dst, 1);   // progressive: loads blurry-to-sharp instead of top-down
    $ok = @imagejpeg($dst, $dir . '/' . $name, ($kind === 'cover') ? 95 : 90);

    imagedestroy($src);
    imagedestroy($dst);

    if (!$ok) {
        $error = "We couldn't save that image. Please try again.";
        return null;
    }
    return $name;
}

function delete_club_image($name, $dir = null) {
    if (club_image_ok($name)) {
        @unlink(($dir === null ? club_image_dir() : $dir) . '/' . $name);
    }
}

// The square club mark: the logo if there is one, otherwise the first letter on a colour tile.
// $club needs id, name and (optionally) logo.
function club_tile_html($club, $size = '', $tag = 'div') {
    $cls = 'club-tile' . ($size !== '' ? ' ' . $size : '') . ' t' . ((int)$club['id'] % 3);
    if (!empty($club['logo']) && club_image_ok($club['logo'])) {
        return '<' . $tag . ' class="' . $cls . ' has-logo"><img src="uploads/clubs/' . e($club['logo']) . '" alt=""></' . $tag . '>';
    }
    return '<' . $tag . ' class="' . $cls . '">' . e(first_letter($club['name'])) . '</' . $tag . '>';
}


// profile cover photos live in their own folder
function user_cover_dir() {
    return __DIR__ . '/uploads/covers';
}

// Icon for a social network (shared by the profile page and the student directory)
if (!function_exists('social_icon')) {
    function social_icon($key) {
        $solid = array(
            'github'   => 'M12 .297c-6.63 0-12 5.373-12 12 0 5.303 3.438 9.8 8.205 11.385.6.113.82-.258.82-.577 0-.285-.01-1.04-.015-2.04-3.338.724-4.042-1.61-4.042-1.61C4.422 18.07 3.633 17.7 3.633 17.7c-1.087-.744.084-.729.084-.729 1.205.084 1.838 1.236 1.838 1.236 1.07 1.835 2.809 1.305 3.495.998.108-.776.417-1.305.76-1.605-2.665-.3-5.466-1.332-5.466-5.93 0-1.31.465-2.38 1.235-3.22-.135-.303-.54-1.523.105-3.176 0 0 1.005-.322 3.3 1.23.96-.267 1.98-.399 3-.405 1.02.006 2.04.138 3 .405 2.28-1.552 3.285-1.23 3.285-1.23.645 1.653.24 2.873.12 3.176.765.84 1.23 1.91 1.23 3.22 0 4.61-2.805 5.625-5.475 5.92.42.36.81 1.096.81 2.22 0 1.606-.015 2.896-.015 3.286 0 .315.21.69.825.57C20.565 22.092 24 17.592 24 12.297c0-6.627-5.373-12-12-12',
            'facebook' => 'M18 2h-3a5 5 0 0 0-5 5v3H7v4h3v8h4v-8h3l1-4h-4V7a1 1 0 0 1 1-1h3z',
            'linkedin' => 'M20.447 20.452h-3.554v-5.569c0-1.328-.027-3.037-1.852-3.037-1.853 0-2.136 1.445-2.136 2.939v5.667H9.351V9h3.414v1.561h.046c.477-.9 1.637-1.85 3.37-1.85 3.601 0 4.267 2.37 4.267 5.455v6.286zM5.337 7.433c-1.144 0-2.063-.926-2.063-2.065 0-1.138.92-2.063 2.063-2.063 1.14 0 2.064.925 2.064 2.063 0 1.139-.925 2.065-2.064 2.065zm1.782 13.019H3.555V9h3.564v11.452zM22.225 0H1.771C.792 0 0 .774 0 1.729v20.542C0 23.227.792 24 1.771 24h20.451C23.2 24 24 23.227 24 22.271V1.729C24 .774 23.2 0 22.222 0h.003z',
        );
        $line = array(
            'instagram' => '<rect x="3" y="3" width="18" height="18" rx="5.5"/><circle cx="12" cy="12" r="4.2"/><circle cx="17.6" cy="6.4" r="0.5" fill="currentColor"/>',
            'youtube'   => '<rect x="2.5" y="5.5" width="19" height="13" rx="4"/><path d="M10 9.5v5l4.5-2.5z" fill="currentColor"/>',
            'discord'   => '<path d="M7 6.5c1.6-.8 3.2-1.1 5-1.1s3.4.3 5 1.1c1.6 2.4 2.5 5 2.7 8-1.3 1-2.7 1.6-4.2 2l-.9-1.5c-1.7.5-3.5.5-5.2 0l-.9 1.5c-1.5-.4-2.9-1-4.2-2 .2-3 1.1-5.6 2.7-8z"/><circle cx="9.3" cy="12" r="1.1" fill="currentColor"/><circle cx="14.7" cy="12" r="1.1" fill="currentColor"/>',
            'tiktok'    => '<path d="M14 4v10.5a3.5 3.5 0 1 1-3.5-3.5"/><path d="M14 4c.3 2.3 1.8 3.8 4 4"/>',
            'x'         => '<path d="M5 4l14 16M19 4L5 20"/>',
            'twitch'    => '<path d="M5 3h15v11l-4 4h-4l-3 3v-3H5z"/><path d="M12 8v4M16 8v4"/>',
            'spotify'   => '<circle cx="12" cy="12" r="9.5"/><path d="M7 9.5c3.5-1 7.5-.7 10.5 1M7.5 12.5c3-.8 6-.5 8.5 1M8 15.3c2.3-.6 4.5-.4 6.5.7"/>',
            'website'   => '<circle cx="12" cy="12" r="9.5"/><path d="M2.5 12h19"/><path d="M12 2.5a14.5 14.5 0 0 1 3.8 9.5A14.5 14.5 0 0 1 12 21.5 14.5 14.5 0 0 1 8.2 12 14.5 14.5 0 0 1 12 2.5z"/>',
        );
        if (isset($solid[$key])) {
            return '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="' . $solid[$key] . '"/></svg>';
        }
        $d = isset($line[$key]) ? $line[$key] : $line['website'];
        return '<svg viewBox="0 0 24 24" aria-hidden="true"><g fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">' . $d . '</g></svg>';
    }
}