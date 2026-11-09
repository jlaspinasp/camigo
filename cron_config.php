<?php
// Secret for the reminder cron job (send-reminders.php).
// The cron service must call:  https://YOUR-SITE/send-reminders.php?key=THIS_VALUE
// Keep this file private. To rotate the key, change the value here and in your cron service.
define("CRON_KEY", "ca11f3b20a81670c6696f251537b4185");