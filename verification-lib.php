<?php
// Account verification helpers.
// Loaded from the bottom of auth.php, so every page that already requires auth.php can use these.
//
// Rule: verified students (and admins) can create clubs, projects and events.
// Everyone else can still join, browse and take part.

if (is_file(__DIR__ . '/verify_config.php')) {
    require_once __DIR__ . '/verify_config.php';
}

define('VERIFY_WAIT_HOURS', 24);     // how long someone waits after a rejection before sending a new request
define('VERIFY_MAX_UPLOAD', 10 * 1024 * 1024);

// Has verification.sql been run? Checked once per request. Until it has, nobody is locked out of anything.
function verify_ready($conn) {
    static $ready = null;
    if ($ready === null) {
        $a = @mysqli_query($conn, "SHOW COLUMNS FROM users LIKE 'is_verified'");
        $b = @mysqli_query($conn, "SHOW TABLES LIKE 'verifications'");
        $ready = ($a && mysqli_num_rows($a) > 0 && $b && mysqli_num_rows($b) > 0);
    }
    return $ready;
}

// Has verification-two-proofs.sql been run? (adds the second photo column)
function verify_two_ready($conn) {
    static $ok = null;
    if ($ok === null) {
        $a = @mysqli_query($conn, "SHOW COLUMNS FROM verifications LIKE 'proof2'");
        $ok = ($a && mysqli_num_rows($a) > 0);
    }
    return $ok;
}

// Admins count as verified from the start.
function user_is_verified($row) {
    return (($row['role'] ?? 'user') === 'admin') || !empty($row['is_verified']);
}

function can_create($conn, $me) {
    if (!verify_ready($conn)) return true;
    return user_is_verified($me);
}

// Put at the top of any page that creates something. Sends unverified people to the verify page.
function require_verified($conn, $me, $what) {
    if (can_create($conn, $me)) return;
    flash_set("Verify your account to " . $what . ". It only takes a minute.", "error");
    header("Location: verify-account");
    exit();
}

// ---- ID numbers ----

// "2021-0123" and "20210123 " become the same thing
function verify_norm_id($raw) {
    $s = trim((string)$raw);
    $s = function_exists('mb_strtoupper') ? mb_strtoupper($s, 'UTF-8') : strtoupper($s);
    return preg_replace('/[^A-Z0-9]/', '', $s);
}

// Returns an error message, or "" when the ID number looks fine
function verify_id_error($raw) {
    $raw = trim((string)$raw);
    if ($raw === '' || strlen($raw) > 30 || !preg_match('/^[A-Za-z0-9][A-Za-z0-9 \-\/.]*$/', $raw)) {
        return "Enter your ID number using letters, numbers and dashes only.";
    }
    $n = strlen(verify_norm_id($raw));
    if ($n < 4 || $n > 24) {
        return "That ID number looks too short or too long. Please check it.";
    }
    return "";
}

// One-way fingerprint: lets us notice a reused ID number without keeping a readable list of them
function verify_hash_id($raw) {
    $secret = defined('VERIFY_SECRET') ? VERIFY_SECRET : 'camigo-verify';
    return hash_hmac('sha256', verify_norm_id($raw), $secret);
}

// "2021-0123" -> "ending in 0123"
function verify_id_tail($raw) {
    $n = verify_norm_id($raw);
    return $n === '' ? '' : substr($n, -4);
}

// ---- requests ----

function verify_latest($conn, $userId) {
    $r = db_run($conn,
        "SELECT *, TIMESTAMPDIFF(SECOND, reviewed_at, NOW()) AS since_review
         FROM verifications WHERE user_id = ? ORDER BY id DESC LIMIT 1",
        "i", array((int)$userId));
    return $r['rows'] ? $r['rows'][0] : null;
}

// Seconds left before this person may send a new request (0 = they can send one now)
function verify_wait_left($latest) {
    if ($latest && $latest['status'] === 'rejected' && $latest['since_review'] !== null) {
        return max(0, VERIFY_WAIT_HOURS * 3600 - (int)$latest['since_review']);
    }
    return 0;
}

function verify_wait_text($seconds) {
    $h = (int)ceil($seconds / 3600);
    if ($h <= 1) return "about an hour";
    return "about " . $h . " hours";
}

// Other accounts that used the same ID number (waiting or already verified)
function verify_duplicates($conn, $hash, $exceptUserId) {
    $r = db_run($conn,
        "SELECT v.id, v.user_id, v.status, u.username, u.first_name, u.last_name
         FROM verifications v LEFT JOIN users u ON u.id = v.user_id
         WHERE v.id_hash = ? AND v.user_id <> ?
           AND (v.status = 'pending' OR (v.status = 'approved' AND u.id IS NOT NULL))
         ORDER BY v.id DESC LIMIT 5",
        "si", array($hash, (int)$exceptUserId));
    return $r['rows'];
}

function verify_pending_count($conn) {
    if (!verify_ready($conn)) return 0;
    $r = db_run($conn, "SELECT COUNT(*) AS n FROM verifications WHERE status = 'pending'");
    return ($r['ok'] && $r['rows']) ? (int)$r['rows'][0]['n'] : 0;
}

// ---- proof photos: kept in a private folder, never in uploads/ ----

function proof_dir() {
    return __DIR__ . '/private/verification';
}

function proof_ok($name) {
    return is_string($name) && preg_match('/^[a-f0-9]{32}\.jpg$/', $name) === 1;
}

// Creates the private folder and locks it so the web server never serves its files directly.
function proof_ensure_dir() {
    $dir = proof_dir();
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    if (!is_dir($dir) || !is_writable($dir)) {
        return false;
    }
    $rules = "# Nothing in this folder is ever served directly.\n"
           . "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n"
           . "<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n";
    foreach (array($dir, dirname($dir)) as $d) {
        if (!is_file($d . '/.htaccess')) @file_put_contents($d . '/.htaccess', $rules);
        if (!is_file($d . '/index.html')) @file_put_contents($d . '/index.html', '');
    }
    return true;
}

// Re-draws the upload as a clean JPEG (this also removes phone location data), long side up to 2000px.
// Returns the private file name, or null (and sets $error).
function save_proof_image($tmpPath, &$error) {
    if (!function_exists('imagecreatetruecolor') || !function_exists('imagejpeg')) {
        $error = "Image uploads aren't available on this server.";
        return null;
    }

    $info = @getimagesize($tmpPath);
    if (!$info) {
        $error = "That file isn't a valid image.";
        return null;
    }
    if ($info[0] * $info[1] > 40000000) {
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

    $w = imagesx($src);
    $h = imagesy($src);
    if (max($w, $h) < 500) {
        imagedestroy($src);
        $error = "That photo is too small to read. Please upload a clearer one.";
        return null;
    }

    // never scale up; shrink only if the long side is above 2000px
    $scale = min(1, 2000 / max($w, $h));
    $tw = max(1, (int)round($w * $scale));
    $th = max(1, (int)round($h * $scale));

    $dst = imagecreatetruecolor($tw, $th);
    imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $tw, $th, $w, $h);
    sharpen_after_resize($dst, $w, $tw);

    if (!proof_ensure_dir()) {
        imagedestroy($src);
        imagedestroy($dst);
        error_log("Verification folder missing or not writable: " . proof_dir());
        $error = "Proof uploads aren't set up yet. The private folder can't be created on this server.";
        return null;
    }

    $name = bin2hex(random_bytes(16)) . '.jpg';
    $ok = @imagejpeg($dst, proof_dir() . '/' . $name, 88);

    imagedestroy($src);
    imagedestroy($dst);

    if (!$ok) {
        $error = "We couldn't save that photo. Please try again.";
        return null;
    }
    return $name;
}

function delete_proof($name) {
    if (proof_ok($name)) {
        @unlink(proof_dir() . '/' . $name);
    }
}

// ---- the "Verified" badge shown next to names ----
function verified_badge($row, $extraClass = '') {
    if (!user_is_verified($row)) return '';
    return '<span class="vbadge' . ($extraClass !== '' ? ' ' . e($extraClass) : '') . '" title="Verified student" role="img" aria-label="Verified student">'
         . '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="11" fill="currentColor"/>'
         . '<path d="M7.2 12.4l3.2 3.2 6.4-6.8" fill="none" stroke="#fff" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/></svg></span>';
}