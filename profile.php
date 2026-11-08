<?php
session_start();
require("db.php");
require("auth.php");

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
            <p class="empty">This student hasn't made their profile visible to others.</p>
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
            <p class="empty"><?php echo $isOwn ? "You haven't joined any clubs yet." : "No clubs to show yet."; ?> Clubs you join will appear here.</p>
        </section>

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