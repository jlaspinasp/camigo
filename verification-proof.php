<?php
// Streams one verification proof photo. The photos live in a private folder that the web server never
// serves directly, so this is the only way to see one: admins, and the student who sent it.
session_start();
require("db.php");
require("auth.php");

$me = require_login($conn);

function proof_fail($code) {
    http_response_code($code);
    header("Content-Type: text/plain; charset=utf-8");
    echo $code === 404 ? "Not found" : "Forbidden";
    exit();
}

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0 || !verify_ready($conn)) proof_fail(404);

// n=1 is the school ID photo, n=2 is the study load photo
$n = ((int)($_GET['n'] ?? 1) === 2) ? 2 : 1;
$col = ($n === 2) ? 'proof2' : 'proof';
if ($n === 2 && !verify_two_ready($conn)) proof_fail(404);

$r = db_run($conn, "SELECT user_id, $col AS proof, status FROM verifications WHERE id = ? LIMIT 1", "i", array($id));
if (!$r['rows']) proof_fail(404);
$v = $r['rows'][0];

$isAdmin = (($me['role'] ?? 'user') === 'admin');
if (!$isAdmin && (int)$v['user_id'] !== (int)$me['id']) proof_fail(403);

// once a request is decided the photo is deleted, so there is nothing left to show
if ($v['status'] !== 'pending' || !proof_ok($v['proof'] ?? '')) proof_fail(404);

$path = proof_dir() . '/' . $v['proof'];
if (!is_file($path)) proof_fail(404);

header("Content-Type: image/jpeg");
header("Content-Length: " . filesize($path));
header("Cache-Control: private, no-store, max-age=0");
header("X-Robots-Tag: noindex, nofollow");
header("X-Content-Type-Options: nosniff");
readfile($path);
exit();