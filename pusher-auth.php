<?php
// Pusher calls this to ask: may this logged-in person join this private channel?
session_start();
require("db.php");
require("auth.php");

header('Content-Type: application/json');

function deny() {
    http_response_code(403);
    echo json_encode(array('error' => 'forbidden'));
    exit();
}

if (!function_exists('pusher_enabled') || !pusher_enabled() || $_SERVER['REQUEST_METHOD'] !== 'POST') deny();

$user = isset($_SESSION['username']) ? get_user_row($conn, $_SESSION['username']) : null;
if (!$user || !empty($_SESSION['needs_profile'])) deny();

$socket = (string)($_POST['socket_id'] ?? '');
$chan   = (string)($_POST['channel_name'] ?? '');
if (!preg_match('/^\d+\.\d+$/', $socket)) deny();

$me = (int)$user['id'];
$ok = false;

if ($chan === 'private-user-' . $me) {
    // your own channel
    $ok = true;
} elseif (preg_match('/^private-chat-(\d+)-(\d+)$/', $chan, $m)) {
    // a chat channel, only for the two people in it
    $a = (int)$m[1];
    $b = (int)$m[2];
    if ($a < $b && ($a === $me || $b === $me)) {
        $other = ($a === $me) ? $b : $a;
        $r = db_run($conn, "SELECT id FROM users WHERE id = ? LIMIT 1", "i", array($other));
        $ok = (bool)$r['rows'];
    }
} elseif (preg_match('/^private-group-(\d+)$/', $chan, $m)) {
    // a group's channel (typing), only for its members
    $r = db_run($conn, "SELECT 1 FROM chat_group_members WHERE group_id = ? AND user_id = ? LIMIT 1", "ii", array((int)$m[1], $me));
    $ok = (bool)$r['rows'];
}

if (!$ok) deny();

echo json_encode(array('auth' => pusher_sign($socket, $chan)));