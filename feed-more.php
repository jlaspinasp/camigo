<?php
// Next page of the home feed, for the dashboard's endless scroll. Returns JSON: { html, more }.
session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['username'])) {
    http_response_code(401);
    echo json_encode(array('html' => '', 'more' => false));
    exit();
}

require("db.php");
require("auth.php");
require("events-lib.php");
require("feed-lib.php");

$urow = get_user_row($conn, $_SESSION['username']);
if (!$urow) {
    http_response_code(401);
    echo json_encode(array('html' => '', 'more' => false));
    exit();
}

$PAGE   = 10;
$offset = isset($_GET['offset']) ? max(0, min(1000, (int)$_GET['offset'])) : 0;
$type   = isset($_GET['type']) ? $_GET['type'] : 'all';
if ($type !== 'post' && $type !== 'event') $type = 'all';

// ask for one extra so we know whether another page exists
$items = club_feed($conn, $urow['id'], $PAGE + 1, $offset, $type);
$more  = (count($items) > $PAGE);
$items = array_slice($items, 0, $PAGE);

ob_start();
foreach ($items as $it) { render_feed_item($it); }
$html = ob_get_clean();

echo json_encode(array('html' => $html, 'more' => $more));