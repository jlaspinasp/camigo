<?php // Shared top bar for profile pages. Expects $me (the logged-in user's row). ?>
<header class="topbar">
    <?php include("logo.php"); ?>
    <div class="user">
        <nav class="tb-nav" aria-label="Main">
            <a href="dashboard" class="tb-link">Home</a>
            <a href="clubs" class="tb-link">Clubs</a>
            <a href="projects" class="tb-link">Projects</a>
            <a href="events" class="tb-link">Events</a>
            <a href="students" class="tb-link">Students</a>
            <?php if (($me['role'] ?? 'user') === 'admin') { ?><a href="admin-verifications" class="tb-link">Admin</a><?php } ?>
        </nav>
        <?php $unreadMsg = unread_messages($conn, $me['id']); include("msg-icon.php"); ?>
        <?php $unread = unread_count($conn, $me['id']); include("bell.php"); ?>
        <?php include("profile-menu.php"); ?>
    </div>
</header>
<?php $rtUserId = $me['id']; include("realtime.php"); ?>