<?php
// Shared helpers for pages that need the logged-in user, admin checks, and safe DB calls.
// Pages must call session_start() and require("db.php") before using these.

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
    $r = db_run($conn, "SELECT id, username, first_name, last_name, role FROM users WHERE username = ? LIMIT 1", "s", array($_SESSION['username']));
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