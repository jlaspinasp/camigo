<?php

// Turns connection errors into a readable message instead of a blank white page.
mysqli_report(MYSQLI_REPORT_OFF);

$host = $_SERVER['HTTP_HOST'] ?? 'localhost';

if ($host === 'localhost' || $host === '127.0.0.1' || strpos($host, 'localhost:') === 0) {

    // ---- on your own computer (XAMPP) ----
    $db_host = "localhost";
    $db_user = "root";
    $db_pass = "";
    $db_name = "camigo";

} else {

    // ---- on InfinityFree: replace these 4 values with yours ----
    $db_host = "sql312.infinityfree.com";   // e.g. sql123.infinityfree.com
    $db_user = "if0_43389483";   // e.g. if0_12345678
    $db_pass = "Jj0909Aa";         // your hosting account password
    $db_name = "if0_43389483_camigo";    // e.g. if0_12345678_camigo
}

$conn = @mysqli_connect($db_host, $db_user, $db_pass, $db_name);

if (!$conn) {
    die("Database connection failed: " . mysqli_connect_error());
}

?>