<?php
// Realtime messaging (Pusher Channels). Fill in the 4 values from your Pusher app:
// Pusher dashboard -> your app -> App Keys. Leave them empty to turn realtime off.
// Keep this file private. The SECRET must never go in JavaScript or be shared.
define("PUSHER_APP_ID",  "2210558");
define("PUSHER_KEY",     "cc5eb3cee8185856932e");
define("PUSHER_SECRET",  "fe13d4e96fa7bd7a19f0");
define("PUSHER_CLUSTER", "ap1");   // ap1 = Singapore, the closest to the Philippines

function pusher_enabled() {
    return PUSHER_APP_ID !== "" && PUSHER_KEY !== "" && PUSHER_SECRET !== "" && PUSHER_CLUSTER !== "";
}

// the channel two people share (used for the "typing..." signal)
function chat_channel($a, $b) {
    $a = (int)$a; $b = (int)$b;
    return 'private-chat-' . min($a, $b) . '-' . max($a, $b);
}

// each person's own channel (new messages and "seen" arrive here, on any page)
function user_channel($id) {
    return 'private-user-' . (int)$id;
}

// proves to Pusher that this logged-in person may join a private channel
function pusher_sign($socketId, $channel) {
    return PUSHER_KEY . ':' . hash_hmac('sha256', $socketId . ':' . $channel, PUSHER_SECRET);
}

// sends one event to Pusher. Never breaks the page if Pusher can't be reached.
function pusher_trigger($channels, $event, $data) {
    if (!pusher_enabled() || !function_exists('curl_init')) return false;

    $body = json_encode(array(
        'name'     => $event,
        'channels' => array_values($channels),
        'data'     => json_encode($data, JSON_UNESCAPED_UNICODE),
    ), JSON_UNESCAPED_UNICODE);

    $path   = '/apps/' . PUSHER_APP_ID . '/events';
    $params = array(
        'auth_key'       => PUSHER_KEY,
        'auth_timestamp' => time(),
        'auth_version'   => '1.0',
        'body_md5'       => md5($body),
    );
    ksort($params);
    $qs  = http_build_query($params);
    $sig = hash_hmac('sha256', "POST\n" . $path . "\n" . $qs, PUSHER_SECRET);
    $url = 'https://api-' . PUSHER_CLUSTER . '.pusher.com' . $path . '?' . $qs . '&auth_signature=' . $sig;

    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_HTTPHEADER     => array('Content-Type: application/json'),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_TIMEOUT        => 3,
        CURLOPT_SSL_VERIFYPEER => true,
    ));
    $out  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($out === false) error_log("Pusher trigger failed: " . curl_error($ch));
    curl_close($ch);
    return $code === 200;
}

