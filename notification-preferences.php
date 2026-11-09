<?php
session_start();
require("db.php");
require("auth.php");

$me = require_user_row($conn);
$cats = notif_categories();
$saved = false;
$setupMissing = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (csrf_ok()) {
        foreach ($cats as $key => $c) {
            $on = !empty($_POST['cat'][$key]) ? 1 : 0;
            $r = db_run($conn,
                "INSERT INTO notification_prefs (user_id, category, enabled) VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE enabled = VALUES(enabled)",
                "isi", array($me['id'], $key, $on));
            if (!$r['ok']) $setupMissing = true;
        }
        $saved = !$setupMissing;
    }
}

// current state (default: everything on)
$state = array();
foreach ($cats as $key => $c) $state[$key] = 1;
$r = db_run($conn, "SELECT category, enabled FROM notification_prefs WHERE user_id = ?", "i", array($me['id']));
foreach ($r['rows'] as $row) { if (isset($state[$row['category']])) $state[$row['category']] = (int)$row['enabled']; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Notification preferences | Camigo</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:wght@600;700;800&family=Instrument+Sans:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
</head>
<body>

<?php include("topbar.php"); ?>

<main class="main narrow">

    <div class="page-top">
        <div>
            <h1 class="page-title">Notification preferences</h1>
            <p class="page-sub">Choose which updates appear in your notifications.</p>
        </div>
        <a href="notifications" class="btn-sm btn-ghost">Back to notifications</a>
    </div>

    <?php if ($saved) { ?><div class="msg success" style="margin-top:20px">Preferences saved.</div><?php } ?>
    <?php if ($setupMissing) { ?><div class="msg error" style="margin-top:20px">Preferences aren't set up yet. Run <strong>notification_prefs.sql</strong> in phpMyAdmin.</div><?php } ?>

    <form method="POST" class="pref-list">
        <?php echo csrf_field(); ?>
        <?php foreach ($cats as $key => $c) { ?>
            <label class="pref">
                <span class="pref-text">
                    <strong><?php echo e($c[0]); ?></strong>
                    <span><?php echo e($c[1]); ?></span>
                </span>
                <input type="checkbox" name="cat[<?php echo e($key); ?>]" value="1"<?php echo $state[$key] ? ' checked' : ''; ?>>
                <span class="pj-switch-ui" aria-hidden="true"></span>
            </label>
        <?php } ?>
        <button type="submit" class="btn-sm btn-dark pref-save">Save preferences</button>
    </form>

</main>

</body>
</html>