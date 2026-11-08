<?php
// Only the Client ID goes here. It is public by design.
// The Client SECRET is NOT used by this login method, so never put it in any file.
define("GOOGLE_CLIENT_ID", "394711939312-1e276t3ol4fghubet3ejlcd448iqp1ol.apps.googleusercontent.com");

// The address Google sends the signed-in user back to
function google_login_uri() {
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $isLocal = ($host === 'localhost' || $host === '127.0.0.1' || strpos($host, 'localhost:') === 0);
    $scheme = $isLocal ? "http" : "https";
    $dir = rtrim(str_replace("\\", "/", dirname($_SERVER['SCRIPT_NAME'])), "/");
    return $scheme . "://" . $host . $dir . "/google-callback";
}
