<?php // Shared top bar for profile pages. Expects $me (the logged-in user's row). ?>
<header class="topbar">
    <?php include("logo.php"); ?>
    <div class="user">
        <?php if (($me['role'] ?? 'user') === 'admin') { ?><a href="admin-clubs" class="out">Admin</a><?php } ?>
        <a href="dashboard" class="out">Dashboard</a>
        <a href="profile" class="me-link">
            <span class="avatar"><?php echo avatar_inner($me); ?></span>
            <span class="name"><?php echo e($me['first_name']); ?></span>
        </a>
        <a href="logout" class="out">Log out</a>
    </div>
</header>