<?php
session_start();
require("db.php");
require("auth.php");
require_once("projects-lib.php");

$me = require_user_row($conn);

// ?u=username shows someone else's profile (if they made it public)
$want  = isset($_GET['u']) ? trim($_GET['u']) : '';
$isOwn = ($want === '' || strcasecmp($want, $me['username']) === 0);

$row = $isOwn ? $me : get_user_row($conn, $want);
$notFound = !$row;
$hidden   = false;

if ($row && !$isOwn && ($row['profile_visibility'] ?? 'public') !== 'public') {
    $hidden = true;
}

$flash = flash_get();
$message = $flash ? $flash[0] : "";
$message_class = $flash ? $flash[1] : "";

$full = "";
$meta = array();
$interests = array();
$links = array();
$pct = 0;
$myClubs = array();
$myProjects = array();

if ($row && !$hidden) {
    $full = trim($row['first_name']
        . (!empty($row['middle_initial']) ? ' ' . $row['middle_initial'] . '.' : '')
        . (!empty($row['last_name']) ? ' ' . $row['last_name'] : ''));

    foreach (array('course', 'year_level') as $k) {
        if (!empty($row[$k])) $meta[] = $row[$k];
    }

    if (!empty($row['interests'])) {
        $interests = array_filter(explode(',', $row['interests']));
    }

    foreach (social_networks() as $key => $net) {
        if (!empty($row[$key]) && preg_match('#^https?://#i', $row[$key])) {
            $links[] = array($net[0], $row[$key]);
        }
    }

    // how complete is the profile (owner only)
    $done = array(
        !empty($row['photo']),
        !empty($row['bio']),
        !empty($row['course']) || !empty($row['year_level']),
        !empty($row['interests']),
        count($links) > 0,
    );
    $pct = (int)round(count(array_filter($done)) / count($done) * 100);

    // clubs this person belongs to
    $cr = db_run($conn,
        "SELECT c.id, c.name, m.role FROM club_members m JOIN clubs c ON c.id = m.club_id
         WHERE m.user_id = ? AND m.status = 'active' AND c.status = 'approved' ORDER BY m.joined_at DESC",
        "i", array($row['id']));
    $myClubs = $cr['rows'];

    // projects this person founded or joined
    $pjr = db_run($conn,
        "SELECT p.id, p.title, p.tagline, p.stage, p.category, m.role, m.role_label
         FROM project_members m JOIN projects p ON p.id = m.project_id
         WHERE m.user_id = ? AND m.status = 'active' ORDER BY p.updated_at DESC LIMIT 12",
        "i", array($row['id']));
    $myProjects = $pjr['rows'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo ($row && !$hidden) ? e($full) . ' | ' : ''; ?>Profile | Camigo</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:wght@600;700;800&family=Instrument+Sans:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
</head>
<body>

<?php include("topbar.php"); ?>

<main class="main narrow">

    <?php if ($message != "") { ?>
        <div class="msg <?php echo e($message_class); ?>"><?php echo e($message); ?></div>
    <?php } ?>

    <?php if ($notFound) { ?>

        <section class="panel center">
            <h2>Profile not found</h2>
            <p class="empty">We couldn't find anyone with that username.</p>
        </section>

    <?php } elseif ($hidden) { ?>

        <section class="panel center">
            <div class="lock">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="11" width="16" height="10" rx="3"/><path d="M8 11V8a4 4 0 018 0v3"/></svg>
            </div>
            <h2>This profile is private</h2>
            <p class="empty">@<?php echo e($row['username']); ?> has set their profile to private, so only they can see it.</p>
            <p class="empty" style="margin-top:14px"><a href="javascript:history.back()">&larr; Go back</a></p>
        </section>

    <?php } else { ?>

        <section class="profile-card">
            <div class="cover"></div>
            <div class="profile-body">
                <div class="p-avatar"><?php echo avatar_inner($row); ?></div>

                <div class="p-head">
                    <div>
                        <h1 class="p-name"><?php echo e($full); ?></h1>
                        <div class="p-user">
                            @<?php echo e($row['username']); ?>
                            <?php if ($isOwn) { ?>
                                <span class="badge <?php echo (($row['profile_visibility'] ?? 'public') === 'public') ? 'approved' : 'pending'; ?>">
                                    <?php echo (($row['profile_visibility'] ?? 'public') === 'public') ? 'Public' : 'Private'; ?>
                                </span>
                            <?php } ?>
                        </div>
                    </div>
                    <?php if ($isOwn) { ?>
                        <a href="edit-profile" class="btn-sm btn-dark">Edit profile</a>
                    <?php } else { ?>
                        <a href="messages?u=<?php echo e(urlencode($row['username'])); ?>" class="btn-sm btn-dark">Message</a>
                    <?php } ?>
                </div>

                <?php if ($meta) { ?>
                    <p class="p-meta"><?php echo e(implode(' · ', $meta)); ?></p>
                <?php } ?>

                <?php if (!empty($row['bio'])) { ?>
                    <p class="p-bio"><?php echo nl2br(e($row['bio'])); ?></p>
                <?php } elseif ($isOwn) { ?>
                    <p class="p-bio empty">Add a short bio so people know who you are.</p>
                <?php } ?>
            </div>
        </section>

        <?php if ($isOwn && $pct < 100) { ?>
            <section class="panel">
                <div class="club-head">
                    <h2 style="margin:0">Profile strength</h2>
                    <span class="club-meta"><?php echo $pct; ?>% complete</span>
                </div>
                <div class="progress"><i style="width: <?php echo $pct; ?>%"></i></div>
                <p class="club-meta" style="margin-top:10px">A photo, bio, course, interests and a link get you to 100%. <a href="edit-profile">Finish your profile</a></p>
            </section>
        <?php } ?>

        <?php if ($interests || $isOwn) { ?>
            <section class="panel">
                <h2>Interests</h2>
                <?php if ($interests) { ?>
                    <div class="chip-row">
                        <?php foreach ($interests as $t) { ?><span class="chip"><?php echo e($t); ?></span><?php } ?>
                    </div>
                <?php } else { ?>
                    <p class="empty">No interests yet.</p>
                <?php } ?>
            </section>
        <?php } ?>

        <section class="panel">
            <h2>Clubs</h2>
            <?php if ($myClubs) { ?>
                <div class="chip-row">
                    <?php foreach ($myClubs as $mc) { ?>
                        <a class="chip" href="club?id=<?php echo (int)$mc['id']; ?>"><?php echo e($mc['name']); ?><?php if ($mc['role'] === 'owner') { ?> <small>&middot; Owner</small><?php } elseif ($mc['role'] === 'officer') { ?> <small>&middot; Officer</small><?php } ?></a>
                    <?php } ?>
                </div>
            <?php } elseif ($isOwn) { ?>
                <p class="empty">You haven't joined any clubs yet. <a href="clubs">Browse clubs</a></p>
            <?php } else { ?>
                <p class="empty">No clubs to show yet.</p>
            <?php } ?>
        </section>

        <?php if ($myProjects || $isOwn) { ?>
            <section class="panel">
                <div class="club-head">
                    <h2 style="margin:0">Projects</h2>
                    <?php if ($isOwn) { ?><a href="create-project" class="club-meta">Post a project</a><?php } ?>
                </div>
                <?php if ($myProjects) { ?>
                    <div class="pj-mini-list">
                        <?php foreach ($myProjects as $mp) { ?>
                            <a class="pj-mini" href="project?id=<?php echo (int)$mp['id']; ?>">
                                <span class="pj-mini-ic t<?php echo project_tone($mp['id']); ?>"><?php echo project_category_icon($mp['category']); ?></span>
                                <span class="pj-mini-txt">
                                    <strong><?php echo e($mp['title']); ?></strong>
                                    <em><?php echo e($mp['role'] === 'owner' ? 'Founder' : (!empty($mp['role_label']) ? $mp['role_label'] : 'Teammate')); ?> &middot; <?php echo e(project_stage_label($mp['stage'])); ?></em>
                                </span>
                                <?php echo project_stage_badge($mp['stage']); ?>
                            </a>
                        <?php } ?>
                    </div>
                <?php } else { ?>
                    <p class="empty" style="margin-top:14px">You haven't joined a project yet. <a href="projects">Find one</a> or <a href="create-project">post your own</a>.</p>
                <?php } ?>
            </section>
        <?php } ?>

        <?php if ($links || $isOwn) { ?>
            <section class="panel">
                <h2>Links</h2>
                <?php if ($links) { ?>
                    <div class="chip-row">
                        <?php foreach ($links as $l) { ?>
                            <a class="link-pill" href="<?php echo e($l[1]); ?>" target="_blank" rel="noopener noreferrer nofollow"><?php echo e($l[0]); ?></a>
                        <?php } ?>
                    </div>
                <?php } else { ?>
                    <p class="empty">No links added yet.</p>
                <?php } ?>
            </section>
        <?php } ?>

    <?php } ?>

</main>

</body>
</html>