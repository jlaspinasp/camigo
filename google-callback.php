<?php
session_start();
require("db.php");
require("google_config.php");
require("auth.php");

function fail($msg) {
    $_SESSION['login_error'] = $msg;
    header("Location: login");
    exit();
}

// 1. Basic checks: must be a POST from Google with a matching CSRF token
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: login");
    exit();
}

$idToken    = $_POST['credential'] ?? '';
$csrfPost   = $_POST['g_csrf_token'] ?? '';
$csrfCookie = $_COOKIE['g_csrf_token'] ?? '';

if ($idToken === '' || $csrfPost === '' || !hash_equals((string)$csrfCookie, (string)$csrfPost)) {
    fail("Google sign-in could not be verified. Please try again.");
}

// 2. Ask Google to verify the token (checks signature and expiry)
$url  = "https://oauth2.googleapis.com/tokeninfo?id_token=" . urlencode($idToken);
$json = false;
$code = 0;

if (function_exists('curl_init')) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $json = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($json === false) {
        error_log("Google tokeninfo cURL error: " . curl_error($ch));
    }
    curl_close($ch);
} else {
    $json = @file_get_contents($url, false, stream_context_create(['http' => ['timeout' => 10]]));
    $code = ($json === false) ? 0 : 200;
}

if ($json === false || $code !== 200) {
    fail("We couldn't reach Google to confirm your sign-in. Please try again.");
}

$data = json_decode($json, true);

if (!is_array($data)
    || ($data['aud'] ?? '') !== GOOGLE_CLIENT_ID
    || !in_array($data['iss'] ?? '', ['accounts.google.com', 'https://accounts.google.com'], true)
    || ($data['email_verified'] ?? '') !== 'true'
    || empty($data['sub'])
    || empty($data['email'])) {
    fail("Google sign-in failed. Please try again.");
}

$googleId = $data['sub'];
$email    = strtolower($data['email']);
$given    = trim($data['given_name'] ?? '');
$family   = trim($data['family_name'] ?? '');
$full     = trim($data['name'] ?? '');
$picture  = $data['picture'] ?? '';

// 3. Find the user: by Google ID first, then by email (links an existing account)
$user = null;

$stmt = mysqli_prepare($conn, "SELECT * FROM users WHERE google_id = ? LIMIT 1");
mysqli_stmt_bind_param($stmt, "s", $googleId);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);
$user = mysqli_fetch_assoc($res);
mysqli_stmt_close($stmt);

if (!$user) {
    $stmt = mysqli_prepare($conn, "SELECT * FROM users WHERE email = ? LIMIT 1");
    mysqli_stmt_bind_param($stmt, "s", $email);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $byEmail = mysqli_fetch_assoc($res);
    mysqli_stmt_close($stmt);

    if ($byEmail) {
        $stmt = mysqli_prepare($conn, "UPDATE users SET google_id = ? WHERE id = ?");
        mysqli_stmt_bind_param($stmt, "si", $googleId, $byEmail['id']);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        $user = $byEmail;

        // use their Google picture if they have no photo yet
        if (empty($byEmail['photo'])) {
            set_google_photo($conn, $byEmail['username'], $picture);
        }
    }
}

// 4. Brand new user: create the account from what Google gave us
if (!$user) {

    $local = strstr($email, '@', true);
    $base  = preg_replace('/[^a-z0-9._]/', '', strtolower($local));
    $base  = substr($base !== '' ? $base : 'user', 0, 24);
    $username = $base;

    for ($i = 0; $i < 20; $i++) {
        $stmt = mysqli_prepare($conn, "SELECT id FROM users WHERE username = ? LIMIT 1");
        mysqli_stmt_bind_param($stmt, "s", $username);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_store_result($stmt);
        $taken = mysqli_stmt_num_rows($stmt) > 0;
        mysqli_stmt_close($stmt);

        if (!$taken) break;
        $username = $base . mt_rand(100, 9999);
    }

    $first = $given !== '' ? $given : ($full !== '' ? $full : $base);
    $last  = $family !== '' ? $family : null;
    $mi    = "";
    $bday  = null;
    $pass  = null;

    $stmt = mysqli_prepare($conn, "INSERT INTO users
        (first_name, last_name, middle_initial, birthday, username, password, email, google_id)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    mysqli_stmt_bind_param($stmt, "ssssssss", $first, $last, $mi, $bday, $username, $pass, $email, $googleId);

    if (!mysqli_stmt_execute($stmt)) {
        error_log("Google signup insert failed: " . mysqli_stmt_error($stmt));
        mysqli_stmt_close($stmt);
        fail("We couldn't create your account. Please try again.");
    }
    mysqli_stmt_close($stmt);

    // new account: start with their Google picture (ignored if it can't be fetched)
    set_google_photo($conn, $username, $picture);

    $user = [
        'username'   => $username,
        'first_name' => $first,
        'birthday'   => null,
    ];
}

// 5. Log them in, same session keys as the normal login
session_regenerate_id(true);
$_SESSION['username']   = $user['username'];
$_SESSION['first_name'] = $user['first_name'];

if (empty($user['birthday'])) {
    $_SESSION['needs_profile'] = true;
    header("Location: complete-profile");
} else {
    unset($_SESSION['needs_profile']);
    header("Location: dashboard");
}
exit();