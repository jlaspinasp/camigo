<?php
session_start();
require("db.php");
require("auth.php");
require_once("projects-lib.php");

$me = require_user_row($conn);

// thought bubble: edited right on the profile page (own profile only)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bubble'])) {
    $berr = "";
    if (!csrf_ok()) {
        $berr = "Your session expired. Please try again.";
    } elseif (!array_key_exists('status_emoji', $me) || !array_key_exists('status_text', $me)) {
        $berr = "Thought bubbles aren't set up yet. Run profile-bubble.sql and profile-bubble-text.sql in phpMyAdmin first.";
    } else {
        $bem = parse_status_emoji($_POST['status_emoji'] ?? '', $berr);
        $btx = ($berr === "") ? parse_status_text($_POST['status_text'] ?? '', $berr) : '';
        if ($berr === "") {
            $r = db_run($conn, "UPDATE users SET status_emoji = ?, status_text = ? WHERE username = ?", "sss",
                array(($bem === '' ? null : $bem), ($btx === '' ? null : $btx), $me['username']));
            if (!$r['ok']) $berr = "Something went wrong. Please try again.";
        }
    }
    if ($berr !== "") flash_set($berr, "error");
    header("Location: profile");
    exit();
}

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
            $links[] = array($net[0], $row[$key], $key);
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

    // clubs this person belongs to (invite-only ones stay private to outsiders)
    $icFilter = "";
    if (!$isOwn && club_identity_ready($conn)) {
        $icFilter = " AND (c.invite_only = 0 OR EXISTS (SELECT 1 FROM club_members v WHERE v.club_id = c.id AND v.user_id = " . (int)$me['id'] . " AND v.status = 'active'))";
    }
    $cr = db_run($conn,
        "SELECT c.id, c.name, c.category, m.role" . (club_identity_ready($conn) ? ", c.logo, c.cover" : "") . " FROM club_members m JOIN clubs c ON c.id = m.club_id
         WHERE m.user_id = ? AND m.status = 'active' AND c.status = 'approved'" . $icFilter . " ORDER BY m.joined_at DESC",
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

// Cover photo (or a colour gradient when the club has none).
if (!function_exists('club_media_html')) {
    function club_media_html($c, $class) {
        $img = !empty($c['cover']) && club_image_ok($c['cover']);
        $h = '<div class="' . $class . ($img ? ' has-img' : '') . '" data-g="' . ((int)$c['id'] % 6) . '">';
        if ($img) {
            $h .= '<img src="uploads/clubs/' . e($c['cover']) . '" alt="" loading="lazy" decoding="async">';
        }
        return $h . '</div>';
    }
}

// Icon for a social network (key from social_networks()). Solid glyphs, like the brand marks.
if (!function_exists('social_icon')) {
    function social_icon($key) {
        $solid = array(
            'github'   => 'M12 .297c-6.63 0-12 5.373-12 12 0 5.303 3.438 9.8 8.205 11.385.6.113.82-.258.82-.577 0-.285-.01-1.04-.015-2.04-3.338.724-4.042-1.61-4.042-1.61C4.422 18.07 3.633 17.7 3.633 17.7c-1.087-.744.084-.729.084-.729 1.205.084 1.838 1.236 1.838 1.236 1.07 1.835 2.809 1.305 3.495.998.108-.776.417-1.305.76-1.605-2.665-.3-5.466-1.332-5.466-5.93 0-1.31.465-2.38 1.235-3.22-.135-.303-.54-1.523.105-3.176 0 0 1.005-.322 3.3 1.23.96-.267 1.98-.399 3-.405 1.02.006 2.04.138 3 .405 2.28-1.552 3.285-1.23 3.285-1.23.645 1.653.24 2.873.12 3.176.765.84 1.23 1.91 1.23 3.22 0 4.61-2.805 5.625-5.475 5.92.42.36.81 1.096.81 2.22 0 1.606-.015 2.896-.015 3.286 0 .315.21.69.825.57C20.565 22.092 24 17.592 24 12.297c0-6.627-5.373-12-12-12',
            'facebook' => 'M18 2h-3a5 5 0 0 0-5 5v3H7v4h3v8h4v-8h3l1-4h-4V7a1 1 0 0 1 1-1h3z',
            'linkedin' => 'M20.447 20.452h-3.554v-5.569c0-1.328-.027-3.037-1.852-3.037-1.853 0-2.136 1.445-2.136 2.939v5.667H9.351V9h3.414v1.561h.046c.477-.9 1.637-1.85 3.37-1.85 3.601 0 4.267 2.37 4.267 5.455v6.286zM5.337 7.433c-1.144 0-2.063-.926-2.063-2.065 0-1.138.92-2.063 2.063-2.063 1.14 0 2.064.925 2.064 2.063 0 1.139-.925 2.065-2.064 2.065zm1.782 13.019H3.555V9h3.564v11.452zM22.225 0H1.771C.792 0 0 .774 0 1.729v20.542C0 23.227.792 24 1.771 24h20.451C23.2 24 24 23.227 24 22.271V1.729C24 .774 23.2 0 22.222 0h.003z',
        );
        $line = array(
            'instagram' => '<rect x="3" y="3" width="18" height="18" rx="5.5"/><circle cx="12" cy="12" r="4.2"/><circle cx="17.6" cy="6.4" r="0.5" fill="currentColor"/>',
            'youtube'   => '<rect x="2.5" y="5.5" width="19" height="13" rx="4"/><path d="M10 9.5v5l4.5-2.5z" fill="currentColor"/>',
            'discord'   => '<path d="M7 6.5c1.6-.8 3.2-1.1 5-1.1s3.4.3 5 1.1c1.6 2.4 2.5 5 2.7 8-1.3 1-2.7 1.6-4.2 2l-.9-1.5c-1.7.5-3.5.5-5.2 0l-.9 1.5c-1.5-.4-2.9-1-4.2-2 .2-3 1.1-5.6 2.7-8z"/><circle cx="9.3" cy="12" r="1.1" fill="currentColor"/><circle cx="14.7" cy="12" r="1.1" fill="currentColor"/>',
            'tiktok'    => '<path d="M14 4v10.5a3.5 3.5 0 1 1-3.5-3.5"/><path d="M14 4c.3 2.3 1.8 3.8 4 4"/>',
            'x'         => '<path d="M5 4l14 16M19 4L5 20"/>',
            'twitch'    => '<path d="M5 3h15v11l-4 4h-4l-3 3v-3H5z"/><path d="M12 8v4M16 8v4"/>',
            'spotify'   => '<circle cx="12" cy="12" r="9.5"/><path d="M7 9.5c3.5-1 7.5-.7 10.5 1M7.5 12.5c3-.8 6-.5 8.5 1M8 15.3c2.3-.6 4.5-.4 6.5.7"/>',
            'website'   => '<circle cx="12" cy="12" r="9.5"/><path d="M2.5 12h19"/><path d="M12 2.5a14.5 14.5 0 0 1 3.8 9.5A14.5 14.5 0 0 1 12 21.5 14.5 14.5 0 0 1 8.2 12 14.5 14.5 0 0 1 12 2.5z"/>',
        );
        if (isset($solid[$key])) {
            return '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="' . $solid[$key] . '"/></svg>';
        }
        $d = isset($line[$key]) ? $line[$key] : $line['website'];
        return '<svg viewBox="0 0 24 24" aria-hidden="true"><g fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">' . $d . '</g></svg>';
    }
}

// "Currently into": only the rows this person filled in
$currently = ($row && !$hidden && !empty($row['currently_into'])) ? currently_list($row['currently_into']) : array();
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

<main class="main <?php echo ($row && !$hidden) ? 'pf-main' : 'narrow'; ?>">

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

        <?php
            $pc  = club_image_ok($row['cover'] ?? '') ? $row['cover'] : '';
            $vis = (($row['profile_visibility'] ?? 'public') === 'public');
        ?>
        <div class="pf">
            <div class="pf-cover<?php echo $pc ? ' has-img' : ''; ?>"<?php if ($pc) { ?> style="background-image:url('uploads/covers/<?php echo e($pc); ?>')"<?php } else { ?> data-g="<?php echo (int)$row['id'] % 6; ?>"<?php } ?>></div>

            <div class="pf-grid">

                <aside class="pf-side">
                    <div class="pf-avatar"><?php echo avatar_inner($row); ?></div>
                    <?php
                        $bEmoji = trim((string)($row['status_emoji'] ?? ''));
                        $bText  = trim((string)($row['status_text'] ?? ''));
                        $bubbleReady = array_key_exists('status_emoji', $row) && array_key_exists('status_text', $row);
                        $prompts = bubble_prompts();
                        $prompt  = $prompts[(int)date('z') % count($prompts)];
                    ?>
                    <?php if ($bubbleReady && ($bEmoji !== '' || $bText !== '' || $isOwn)) { ?>
                        <div class="pf-bubble">
                            <i class="b1"></i><i class="b2"></i>
                            <?php if ($isOwn) { ?>
                                <button type="button" class="pf-pill<?php echo ($bText === '' && $bEmoji !== '') ? ' only-emoji' : ''; ?><?php echo ($bText === '' && $bEmoji === '') ? ' add' : ''; ?>" id="bubble-open" aria-label="Edit your thought bubble">
                            <?php } else { ?>
                                <div class="pf-pill<?php echo ($bText === '') ? ' only-emoji' : ''; ?>">
                            <?php } ?>
                                <?php if ($bText === '' && $bEmoji === '') { ?>
                                    <span class="plus">+</span><em><?php echo e($prompt); ?></em>
                                <?php } else { ?>
                                    <?php if ($bEmoji !== '') { ?><span class="em"><?php echo e($bEmoji); ?></span><?php } ?>
                                    <?php if ($bText !== '') { ?><span class="tx"><?php echo e($bText); ?></span><?php } ?>
                                <?php } ?>
                            <?php echo $isOwn ? '</button>' : '</div>'; ?>

                            <?php if ($isOwn) { ?>
                                <form method="POST" action="profile" class="pf-bubble-edit" id="bubble-form" hidden>
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="bubble" value="1">
                                    <strong>Your thought bubble</strong>
                                    <div class="be-row">
                                        <input type="text" name="status_emoji" id="be-emoji" maxlength="16" autocomplete="off" value="<?php echo e($bEmoji); ?>" aria-label="Emoji" placeholder="🙂">
                                        <input type="text" name="status_text" id="be-text" maxlength="40" autocomplete="off" value="<?php echo e($bText); ?>" aria-label="Text" placeholder="<?php echo e($prompt); ?>">
                                    </div>
                                    <div class="be-picks">
                                        <?php foreach (bubble_picks() as $em) { ?><button type="button" data-em="<?php echo e($em); ?>"><?php echo e($em); ?></button><?php } ?>
                                    </div>
                                    <div class="be-actions">
                                        <button type="submit" class="btn-sm btn-dark">Save</button>
                                        <button type="button" class="btn-sm btn-ghost" id="be-clear">Clear</button>
                                        <button type="button" class="btn-sm btn-ghost" id="be-cancel">Cancel</button>
                                    </div>
                                </form>
                            <?php } ?>
                        </div>
                    <?php } ?>

                    <h1 class="pf-name"><?php echo e($full); ?></h1>
                    <div class="pf-user">
                        <span>@<?php echo e($row['username']); ?></span>
                        <?php if ($isOwn && !$vis) { ?><span class="badge pending">Private</span><?php } ?>
                    </div>
                    <?php if ($meta) { ?><p class="pf-meta"><?php echo e(implode(' · ', $meta)); ?></p><?php } ?>

                    <?php if ($links) { ?>
                        <div class="pf-social">
                            <?php foreach ($links as $l) { ?>
                                <a href="<?php echo e($l[1]); ?>" target="_blank" rel="noopener noreferrer nofollow" aria-label="<?php echo e($l[0]); ?>" title="<?php echo e($l[0]); ?>"><?php echo social_icon($l[2]); ?></a>
                            <?php } ?>
                        </div>
                    <?php } elseif ($isOwn) { ?>
                        <p class="pf-hint"><a href="edit-profile">Add your links</a></p>
                    <?php } ?>

                    <?php if ($isOwn) { ?>
                        <a href="edit-profile" class="btn-sm btn-dark pf-btn">Edit profile</a>
                    <?php } else { ?>
                        <a href="messages?u=<?php echo e(urlencode($row['username'])); ?>" class="btn-sm btn-dark pf-btn">Message</a>
                    <?php } ?>

                    <?php if ($interests || $isOwn) { ?>
                        <h2 class="pf-side-h">Interests</h2>
                        <?php if ($interests) { ?>
                            <div class="chip-row">
                                <?php foreach ($interests as $t) { ?><span class="chip"><?php echo e($t); ?></span><?php } ?>
                            </div>
                        <?php } else { ?>
                            <p class="pf-hint"><a href="edit-profile">Add your interests</a></p>
                        <?php } ?>
                    <?php } ?>

                    <?php if ($currently) { ?>
                        <h2 class="pf-side-h">Currently into</h2>
                        <dl class="pf-now">
                            <?php foreach ($currently as $c) { ?>
                                <div><dt><?php echo e($c[0]); ?></dt><dd><?php echo e($c[1]); ?></dd></div>
                            <?php } ?>
                        </dl>
                    <?php } elseif ($isOwn && array_key_exists('currently_into', $row)) { ?>
                        <h2 class="pf-side-h">Currently into</h2>
                        <p class="pf-hint"><a href="edit-profile">Share what you're into right now</a></p>
                    <?php } ?>
                </aside>

                <div class="pf-content">

                    <?php if ($isOwn && $pct < 100) { ?>
                        <section class="pf-card pf-strength">
                            <div class="pf-row">
                                <h2 class="pf-h">Profile strength</h2>
                                <span class="club-meta"><?php echo $pct; ?>% complete</span>
                            </div>
                            <div class="progress"><i style="width: <?php echo $pct; ?>%"></i></div>
                            <p class="club-meta" style="margin-top:10px">A photo, bio, course, interests and a link get you to 100%. <a href="edit-profile">Finish your profile</a></p>
                        </section>
                    <?php } ?>

                    <?php if (!empty($row['bio']) || $isOwn) { ?>
                        <section class="pf-card pf-about">
                            <div class="pf-about-art" aria-hidden="true">
                                <svg viewBox="0 0 120 120" fill="none">
                                    <defs>
                                        <linearGradient id="pfb-body" x1="22" y1="14" x2="92" y2="96" gradientUnits="userSpaceOnUse">
                                            <stop offset="0" stop-color="#ffffff"/>
                                            <stop offset="0.5" stop-color="#e4e4e7"/>
                                            <stop offset="1" stop-color="#8a8a92"/>
                                        </linearGradient>
                                        <linearGradient id="pfb-edge" x1="20" y1="90" x2="98" y2="30" gradientUnits="userSpaceOnUse">
                                            <stop offset="0" stop-color="#5c5c63"/>
                                            <stop offset="1" stop-color="#f4f4f5"/>
                                        </linearGradient>
                                        <radialGradient id="pfb-shine" cx="0.3" cy="0.2" r="0.6">
                                            <stop offset="0" stop-color="#fff" stop-opacity="0.95"/>
                                            <stop offset="1" stop-color="#fff" stop-opacity="0"/>
                                        </radialGradient>
                                        <filter id="pfb-glow" x="-40%" y="-40%" width="180%" height="180%">
                                            <feGaussianBlur stdDeviation="6"/>
                                        </filter>
                                    </defs>
                                    <ellipse cx="60" cy="62" rx="44" ry="36" fill="#ffffff" opacity="0.28" filter="url(#pfb-glow)"/>
                                    <path d="M30 28h58a14 14 0 0 1 14 14v28a14 14 0 0 1-14 14H64L44 100V84H30a14 14 0 0 1-14-14V42a14 14 0 0 1 14-14z" fill="#3a3a40" transform="translate(0 5)"/>
                                    <path d="M30 28h58a14 14 0 0 1 14 14v28a14 14 0 0 1-14 14H64L44 100V84H30a14 14 0 0 1-14-14V42a14 14 0 0 1 14-14z" fill="url(#pfb-body)" stroke="url(#pfb-edge)" stroke-width="2"/>
                                    <path d="M30 32h58a10 10 0 0 1 10 10v6c-14 6-44 8-78 0v-6a10 10 0 0 1 10-10z" fill="url(#pfb-shine)" opacity="0.8"/>
                                    <circle cx="42" cy="56" r="6" fill="#111113"/>
                                    <circle cx="60" cy="56" r="6" fill="#111113"/>
                                    <circle cx="78" cy="56" r="6" fill="#111113"/>
                                    <circle cx="40.5" cy="54" r="1.8" fill="#fff" opacity="0.9"/>
                                    <circle cx="58.5" cy="54" r="1.8" fill="#fff" opacity="0.9"/>
                                    <circle cx="76.5" cy="54" r="1.8" fill="#fff" opacity="0.9"/>
                                    <path class="sp sp1" d="M100 10l2.6 7.4L110 20l-7.4 2.6L100 30l-2.6-7.4L90 20l7.4-2.6z" fill="#ffffff"/>
                                    <path class="sp sp2" d="M14 12l1.8 5.2L21 19l-5.2 1.8L14 26l-1.8-5.2L7 19l5.2-1.8z" fill="#fff"/>
                                    <path class="sp sp3" d="M106 84l1.6 4.4L112 90l-4.4 1.6L106 96l-1.6-4.4L100 90l4.4-1.6z" fill="#a1a1aa"/>
                                </svg>
                            </div>
                            <?php if (!empty($row['bio'])) { ?>
                                <p class="pf-bio"><?php echo nl2br(e($row['bio'])); ?></p>
                            <?php } else { ?>
                                <p class="pf-bio empty">Say something about yourself. <a href="edit-profile">Add a bio</a></p>
                            <?php } ?>
                        </section>
                    <?php } ?>

                    <section class="pf-card" data-rail>
                        <div class="pf-row">
                            <h2 class="pf-h">Clubs</h2>
                            <?php if ($myClubs) { ?>
                                <div class="pf-arrows" hidden>
                                    <button type="button" aria-label="Scroll left"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m15 6-6 6 6 6"/></svg></button>
                                    <button type="button" aria-label="Scroll right"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9 6 6 6-6 6"/></svg></button>
                                </div>
                            <?php } ?>
                        </div>
                        <?php if ($myClubs) { ?>
                            <div class="pf-track">
                                <?php foreach ($myClubs as $mc) { ?>
                                    <a class="pf-club" href="club?id=<?php echo (int)$mc['id']; ?>">
                                        <?php echo club_media_html($mc, 'cc-media pf-media'); ?>
                                        <div class="pf-club-body">
                                            <?php echo club_tile_html($mc, 'pf-club-logo', 'span'); ?>
                                            <span class="pf-club-txt">
                                                <strong><?php echo e($mc['name']); ?></strong>
                                                <em><?php echo ($mc['role'] === 'owner') ? 'Owner' : (($mc['role'] === 'officer') ? 'Officer' : e($mc['category'])); ?></em>
                                            </span>
                                        </div>
                                    </a>
                                <?php } ?>
                            </div>
                        <?php } elseif ($isOwn) { ?>
                            <p class="empty">You haven't joined any clubs yet. <a href="clubs">Browse clubs</a></p>
                        <?php } else { ?>
                            <p class="empty">No clubs to show yet.</p>
                        <?php } ?>
                    </section>

                    <?php if ($myProjects || $isOwn) { ?>
                        <section class="pf-card" data-rail>
                            <div class="pf-row">
                                <h2 class="pf-h">Projects</h2>
                                <div class="pf-row-end">
                                    <?php if ($isOwn) { ?><a href="create-project" class="club-meta">Post a project</a><?php } ?>
                                    <?php if ($myProjects) { ?>
                                <div class="pf-arrows" hidden>
                                    <button type="button" aria-label="Scroll left"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m15 6-6 6 6 6"/></svg></button>
                                    <button type="button" aria-label="Scroll right"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9 6 6 6-6 6"/></svg></button>
                                </div>
                                    <?php } ?>
                                </div>
                            </div>
                            <?php if ($myProjects) { ?>
                                <div class="pf-track">
                                    <?php foreach ($myProjects as $mp) { ?>
                                        <a class="pf-club pf-pj" href="project?id=<?php echo (int)$mp['id']; ?>">
                                            <div class="pf-pj-media t<?php echo project_tone($mp['id']); ?>">
                                                <span class="pf-pj-watermark"><?php echo project_category_icon($mp['category']); ?></span>
                                                <span class="pf-pj-stage"><?php echo project_stage_badge($mp['stage']); ?></span>
                                            </div>
                                            <div class="pf-club-body">
                                                <span class="pj-mini-ic pf-pj-ic t<?php echo project_tone($mp['id']); ?>"><?php echo project_category_icon($mp['category']); ?></span>
                                                <span class="pf-club-txt">
                                                    <strong><?php echo e($mp['title']); ?></strong>
                                                    <em><?php echo e($mp['role'] === 'owner' ? 'Founder' : (!empty($mp['role_label']) ? $mp['role_label'] : 'Teammate')); ?></em>
                                                </span>
                                            </div>
                                        </a>
                                    <?php } ?>
                                </div>
                            <?php } else { ?>
                                <p class="empty">You haven't joined a project yet. <a href="projects">Find one</a> or <a href="create-project">post your own</a>.</p>
                            <?php } ?>
                        </section>
                    <?php } ?>

                </div>
            </div>
        </div>

    <?php } ?>

</main>
<script>
(function () {
    var reduce = window.matchMedia && window.matchMedia("(prefers-reduced-motion: reduce)").matches;
    document.querySelectorAll("[data-rail]").forEach(function (rail) {
        var track = rail.querySelector(".pf-track"), arrows = rail.querySelector(".pf-arrows");
        if (!track || !arrows) return;
        var prev = arrows.children[0], next = arrows.children[1];
        function update() {
            arrows.hidden = !(track.scrollWidth > track.clientWidth + 2);
            prev.disabled = track.scrollLeft <= 2;
            next.disabled = track.scrollLeft + track.clientWidth >= track.scrollWidth - 2;
        }
        function go(d) { track.scrollBy({ left: d * track.clientWidth * 0.9, behavior: reduce ? "auto" : "smooth" }); }
        prev.addEventListener("click", function () { go(-1); });
        next.addEventListener("click", function () { go(1); });
        track.addEventListener("scroll", update, { passive: true });
        window.addEventListener("resize", update);
        update();
    });
})();
</script>

<?php if ($isOwn && !empty($bubbleReady)) { ?>
<script>
(function () {
    var open = document.getElementById("bubble-open"), form = document.getElementById("bubble-form");
    if (!open || !form) return;
    var emoji = document.getElementById("be-emoji"), text = document.getElementById("be-text");

    // the emoji box only ever holds one emoji: anything else typed or pasted is dropped
    var EMOJI = /(?:\p{Regional_Indicator}{2}|\p{Extended_Pictographic}(?:\uFE0F|\p{Emoji_Modifier})?(?:\u200D\p{Extended_Pictographic}(?:\uFE0F|\p{Emoji_Modifier})?)*)/u;
    var composing = false;
    function cleanEmoji() {
        if (composing) return;
        var m = emoji.value.match(EMOJI);
        emoji.value = m ? m[0] : "";
    }
    emoji.addEventListener("compositionstart", function () { composing = true; });
    emoji.addEventListener("compositionend", function () { composing = false; cleanEmoji(); });
    emoji.addEventListener("input", cleanEmoji);
    emoji.addEventListener("focus", function () { emoji.select(); });
    function show() { form.hidden = false; (emoji.value ? text : emoji).focus(); }
    function hide() { form.hidden = true; }
    open.addEventListener("click", function () { form.hidden ? show() : hide(); });
    document.getElementById("be-cancel").addEventListener("click", hide);
    document.getElementById("be-clear").addEventListener("click", function () { emoji.value = ""; text.value = ""; emoji.focus(); });
    form.querySelectorAll(".be-picks button").forEach(function (b) {
        b.addEventListener("click", function () { emoji.value = b.getAttribute("data-em"); text.focus(); });
    });
    document.addEventListener("keydown", function (e) { if (e.key === "Escape") hide(); });
    document.addEventListener("click", function (e) { if (!form.hidden && !form.contains(e.target) && !open.contains(e.target)) hide(); });
})();
</script>
<?php } ?>
</body>
</html>