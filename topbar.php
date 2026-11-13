<?php // Shared top bar for profile pages. Expects $me (the logged-in user's row). ?>
<header class="topbar">
    <?php include("logo.php"); ?>
    <div class="user">
        <?php
        // which main link is the page we're on (pages that belong to a section keep that section lit)
        $tbPage = basename($_SERVER['SCRIPT_NAME'] ?? '', '.php');
        $tbMap = array(
            'dashboard' => array('dashboard'),
            'clubs'     => array('clubs', 'club', 'create-club', 'edit-club', 'club-members', 'club-requests'),
            'projects'  => array('projects', 'project', 'create-project', 'edit-project', 'project-applications'),
            'events'    => array('events', 'event', 'create-event', 'edit-event'),
            'students'  => array('students'),
            'admin'     => array('admin-verifications', 'admin-verified', 'admin-clubs', 'admin-events', 'admin-projects'),
        );
        $tbOn = function ($key) use ($tbPage, $tbMap) { return in_array($tbPage, $tbMap[$key], true) ? ' is-active" aria-current="page' : ''; };
        ?>
        <nav class="tb-nav" aria-label="Main">
            <a href="dashboard" class="tb-link<?php echo $tbOn('dashboard'); ?>">Home</a>
            <a href="clubs" class="tb-link<?php echo $tbOn('clubs'); ?>">Clubs</a>
            <a href="projects" class="tb-link<?php echo $tbOn('projects'); ?>">Projects</a>
            <a href="events" class="tb-link<?php echo $tbOn('events'); ?>">Events</a>
            <a href="students" class="tb-link<?php echo $tbOn('students'); ?>">Students</a>
            <?php if (($me['role'] ?? 'user') === 'admin') { ?><a href="admin-verifications" class="tb-link<?php echo $tbOn('admin'); ?>">Admin</a><?php } ?>
        </nav>
        <?php $unreadMsg = unread_messages($conn, $me['id']); include("msg-icon.php"); ?>
        <?php $unread = unread_count($conn, $me['id']); include("bell.php"); ?>
        <?php include("profile-menu.php"); ?>
    </div>
</header>
<?php $rtUserId = $me['id']; include("realtime.php"); ?>