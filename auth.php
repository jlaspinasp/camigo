<?php
// Shared helpers for pages that need the logged-in user, admin checks, and safe DB calls.
// Pages must call session_start() and require("db.php") before using these.

// Realtime messaging settings (optional). The site works without this file.
if (is_file(__DIR__ . '/pusher_config.php')) {
    require_once __DIR__ . '/pusher_config.php';
}

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

// key => [label, required domain ('' = any site), example link]
function social_networks() {
    return array(
        'instagram' => array('Instagram', 'instagram.com', 'https://instagram.com/yourname'),
        'facebook'  => array('Facebook',  'facebook.com',  'https://facebook.com/yourname'),
        'github'    => array('GitHub',    'github.com',    'https://github.com/yourname'),
        'linkedin'  => array('LinkedIn',  'linkedin.com',  'https://linkedin.com/in/yourname'),
        'website'   => array('Website',   '',              'https://yourwebsite.com'),
    );
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
        $suffix = '.' . $domain;
        if ($host !== $domain && substr($host, -strlen($suffix)) !== $suffix) {
            $error = "Your $label link needs to go to $domain.";
            return null;
        }
    }
    return $u;
}

// "Robotics, Chess" -> "Robotics,Chess". Returns null (and sets $error) on bad input.
function parse_interests($raw, &$error) {
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

    if (count($out) > 8) {
        $error = "Please pick up to 8 interests.";
        return null;
    }
    return implode(',', $out);
}

// ---- profile photos: re-drawn as a 400x400 JPEG with a random name ----
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

    $dst = imagecreatetruecolor(400, 400);
    imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
    imagecopyresampled($dst, $src, 0, 0, $sx, $sy, 400, 400, $side, $side);

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
    $ok = @imagejpeg($dst, $dir . '/' . $name, 85);

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

    // ask for a larger version (…=s96-c becomes …=s400-c)
    $url = preg_replace('/=s\d+-c$/', '=s400-c', $url);

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

function unread_count($conn, $userId) {
    $r = db_run($conn, "SELECT COUNT(*) AS n FROM notifications WHERE user_id = ? AND is_read = 0", "i", array((int)$userId));
    return ($r['ok'] && $r['rows']) ? (int)$r['rows'][0]['n'] : 0;
}

// Unread direct messages. Returns 0 if the messages table doesn't exist yet.
function unread_messages($conn, $userId) {
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

    switch ($type) {
        case 'club_approved':
        case 'join_accepted': return array('good', $check);
        case 'club_rejected':
        case 'join_declined':
        case 'removed':       return array('bad', $cross);
        case 'join_request':  return array('info', $userp);
        case 'club_invite':   return array('invite', $mail);
        default:              return array('info', $bell);
    }
}