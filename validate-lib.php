<?php
// Shared signup rules (register, Google profile step, edit profile).

// "zAcH" -> "Zach", "mary-ann o'neil" -> "Mary-Ann O'Neil"
function proper_name($s) {
    $s = trim(preg_replace('/\s+/u', ' ', (string)$s));
    if ($s === "") return "";
    $s = function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
    return preg_replace_callback('/(^|[\s\-\'’])(\p{L})/u', function ($m) {
        $up = function_exists('mb_strtoupper') ? mb_strtoupper($m[2], 'UTF-8') : strtoupper($m[2]);
        return $m[1] . $up;
    }, $s);
}

const MIN_AGE = 16;

// true when the date is real, not in the future, and the person is at least MIN_AGE
function birthday_ok($birthday) {
    $d = DateTime::createFromFormat('Y-m-d', (string)$birthday);
    if (!$d || $d->format('Y-m-d') !== $birthday) return false;
    return $d <= new DateTime('-' . MIN_AGE . ' years');
}

function min_birthday_date() { return date('Y-m-d', strtotime('-' . MIN_AGE . ' years')); }

// letters, numbers, dot and underscore only
function username_ok($u) { return (bool)preg_match('/^[A-Za-z0-9._]{3,30}$/', (string)$u); }