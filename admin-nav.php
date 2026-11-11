<?php
// Tabs shared by the admin pages. Expects $conn and $adminActive ('verifications', 'clubs', 'projects' or 'events').
$__vc = verify_pending_count($conn);
$__cc = 0;
$__r = db_run($conn, "SELECT COUNT(*) AS n FROM clubs WHERE status = 'pending'");
if ($__r['ok'] && $__r['rows']) $__cc = (int)$__r['rows'][0]['n'];
?>
<nav class="admin-tabs" aria-label="Admin sections">
    <a href="admin-verifications"<?php echo ($adminActive === 'verifications') ? ' class="on" aria-current="page"' : ''; ?>>Verifications<?php if ($__vc > 0) { ?><span class="n"><?php echo $__vc; ?></span><?php } ?></a>
    <a href="admin-clubs"<?php echo ($adminActive === 'clubs') ? ' class="on" aria-current="page"' : ''; ?>>Clubs<?php if ($__cc > 0) { ?><span class="n"><?php echo $__cc; ?></span><?php } ?></a>
    <a href="admin-projects"<?php echo ($adminActive === 'projects') ? ' class="on" aria-current="page"' : ''; ?>>Projects</a>
    <a href="admin-events"<?php echo ($adminActive === 'events') ? ' class="on" aria-current="page"' : ''; ?>>Events</a>
</nav>