<?php
// ONE-TIME: re-saves every existing user's first/last name as "Zach" style.
// Upload, open it once in the browser (yoursite/fix-names.php), then DELETE it.
require("db.php");
require("validate-lib.php");

$res = mysqli_query($conn, "SELECT id, first_name, last_name FROM users");
$n = 0;
while ($row = mysqli_fetch_assoc($res)) {
    $f = proper_name($row['first_name']);
    $l = $row['last_name'] === null ? null : proper_name($row['last_name']);
    if ($f !== $row['first_name'] || $l !== $row['last_name']) {
        $stmt = mysqli_prepare($conn, "UPDATE users SET first_name = ?, last_name = ? WHERE id = ?");
        mysqli_stmt_bind_param($stmt, "ssi", $f, $l, $row['id']);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        $n++;
    }
}
echo "Fixed $n user(s). Now delete fix-names.php.";