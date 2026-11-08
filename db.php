<?php

$conn = mysqli_connect("localhost", "root", "", "camigo");

if (!$conn) {
    die("Database connection failed: " . mysqli_connect_error());
}

?>