<?php
session_start();
require("db.php");
require("auth.php");

$me = require_user_row($conn);
$cats = notif_categories();
$saved = false;
$setupMissing = false;
$hasRemind = array_key_exists('reminder_pref', $me);   // true once event-reminders.sql was run

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
        // event reminder choice (switched-off Events sends nothing, so the saved choice stays as it was)
        $rp = $_POST['reminder_pref'] ?? '';
        if ($hasRemind && in_array($rp, array('going', 'all', 'off'), true)) {
            $rr = db_run($conn, "UPDATE users SET reminder_pref = ? WHERE username = ?", "ss", array($rp, $me['username']));
            if ($rr['ok']) $me['reminder_pref'] = $rp;
        }
        $saved = !$setupMissing;
    }
}
$curRemind = $me['reminder_pref'] ?? 'going';
if (!in_array($curRemind, array('going', 'all', 'off'), true)) $curRemind = 'going';

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
            <?php if ($key === 'events' && $hasRemind) { ?>
                <div class="pref-remind<?php echo $state['events'] ? '' : ' is-off'; ?>" id="event-reminders">
                    <h2>Event reminders</h2>
                    <p class="remind-lead">A nudge before events so you don't miss them. <span class="remind-off-note">Turn Events on to use reminders.</span></p>
                    <label class="radio-card">
                        <input type="radio" name="reminder_pref" value="going" <?php echo ($curRemind === 'going') ? 'checked' : ''; ?><?php echo $state['events'] ? '' : ' disabled'; ?>>
                        <span><strong>Events I'm going to</strong><small>A reminder the day before and about an hour before every event you're going to.</small></span>
                    </label>
                    <label class="radio-card">
                        <input type="radio" name="reminder_pref" value="all" <?php echo ($curRemind === 'all') ? 'checked' : ''; ?><?php echo $state['events'] ? '' : ' disabled'; ?>>
                        <span><strong>Going, plus my clubs' events</strong><small>Everything above, and a heads-up the day before club events you haven't responded to yet.</small></span>
                    </label>
                    <label class="radio-card">
                        <input type="radio" name="reminder_pref" value="off" <?php echo ($curRemind === 'off') ? 'checked' : ''; ?><?php echo $state['events'] ? '' : ' disabled'; ?>>
                        <span><strong>Off</strong><small>No reminders. You'll still hear when an event is posted, changed or cancelled.</small></span>
                    </label>
                </div>
            <?php } ?>
        <?php } ?>
        <button type="submit" class="btn-sm btn-dark pref-save">Save preferences</button>
    </form>

</main>

<script>
(function () {
    // reminders only make sense while Events is on
    var box = document.querySelector('input[name="cat[events]"]');
    var wrap = document.getElementById("event-reminders");
    if (!box || !wrap) return;
    function sync() {
        wrap.classList.toggle("is-off", !box.checked);
        wrap.querySelectorAll('input[type="radio"]').forEach(function (r) { r.disabled = !box.checked; });
    }
    box.addEventListener("change", sync);
    sync();
})();
</script>
</body>
</html>