<?php // Shared top bar for profile pages. Expects $me (the logged-in user's row). ?>
<header class="topbar">
    <?php include("logo.php"); ?>
    <div class="user">
        <?php if (($me['role'] ?? 'user') === 'admin') { ?><a href="admin-verifications" class="out">Admin</a><?php } ?>
        <a href="students" class="out">Students</a>
        <a href="clubs" class="out">Clubs</a>
        <a href="projects" class="out">Projects</a>
        <a href="events" class="out">Events</a>
        <?php $unreadMsg = unread_messages($conn, $me['id']); include("msg-icon.php"); ?>
        <?php $unread = unread_count($conn, $me['id']); include("bell.php"); ?>
        <a href="profile" class="me-link">
            <span class="avatar"><?php echo avatar_inner($me); ?></span>
            <span class="name"><?php echo e($me['first_name']); ?></span>
        </a>
        <a href="logout" class="out">Log out</a>
    </div>
</header>
<?php $rtUserId = $me['id']; include("realtime.php"); ?>