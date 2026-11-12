<?php
session_start();
require("db.php");
require("auth.php");
require_once("projects-lib.php");

$me = require_user_row($conn);
require_verified($conn, $me, 'start a project');

// clubs this person owns or is an officer of (they can tag a project with one)
$clubChoices = array();
foreach (event_postable_clubs($conn, $me['id']) as $c) {
    $clubChoices[(int)$c['id']] = $c['name'];
}

// project banner: an upload is optional (without one the project keeps its coloured cover)
$bannerReady = projects_banner_ready($conn);
$bannerCur   = '';
$bannerTone  = 0;

$message = "";
// a file larger than the server's post limit arrives as an empty form
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    $message = "That upload was too large for the server. Try a smaller image (up to 10 MB).";
}
$f = array('title' => '', 'tagline' => '', 'category' => '', 'description' => '', 'skills' => '', 'link' => '', 'is_open' => 1, 'club_id' => 0);
$fRoles = array();

if (isset($_POST['post'])) {

    // keep what they typed if something goes wrong
    foreach (array('title', 'tagline', 'category', 'description', 'skills', 'link') as $k) {
        $f[$k] = (string)($_POST[$k] ?? '');
    }
    $f['is_open'] = empty($_POST['is_open']) ? 0 : 1;
    $f['club_id'] = (int)($_POST['club_id'] ?? 0);
    if (!empty($_POST['role_title']) && is_array($_POST['role_title'])) {
        foreach ($_POST['role_title'] as $i => $t) {
            if (!is_string($t) || trim($t) === '') continue;
            $fRoles[] = array('id' => 0, 'title' => $t, 'spots' => (int)(($_POST['role_spots'][$i] ?? 1)));
        }
    }

    $error = "";

    if (!csrf_ok()) {
        $error = "Your session expired. Please try again.";
    } else {
        $clean = project_clean($_POST, $error);

        if ($clean) {
            $clubId = isset($clubChoices[(int)($_POST['club_id'] ?? 0)]) ? (int)$_POST['club_id'] : 0;

            $rl = db_run($conn, "SELECT COUNT(*) AS n FROM projects WHERE owner_id = ? AND created_at > (NOW() - INTERVAL 1 HOUR)", "i", array($me['id']));

            if ($rl['ok'] && $rl['rows'] && (int)$rl['rows'][0]['n'] >= 5) {
                $error = "You've posted a lot of projects in the last hour. Please wait a bit.";
            } else {
                // the banner: saved only once everything else checks out, so a rejected form leaves no stray file
                $bannerName = null;
                if ($bannerReady) {
                    $bannerName = project_banner_upload('banner', $error);
                } elseif (!empty($_FILES['banner']['name'])) {
                    $error = "Project banners need a one-time upgrade. Run projects-banner.sql in phpMyAdmin.";
                }

                $r = array('ok' => false, 'errno' => 0);
                if ($error === "") {
                    if ($bannerReady) {
                        $r = db_run($conn,
                            "INSERT INTO projects (owner_id, club_id, title, tagline, description, category, skills, link, is_open, banner)
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                            "iissssssis",
                            array($me['id'], $clubId > 0 ? $clubId : null, $clean['title'], $clean['tagline'], $clean['description'],
                                  $clean['category'], $clean['skills'], $clean['link'], $clean['is_open'], $bannerName));
                    } else {
                        $r = db_run($conn,
                            "INSERT INTO projects (owner_id, club_id, title, tagline, description, category, skills, link, is_open)
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
                            "iissssssi",
                            array($me['id'], $clubId > 0 ? $clubId : null, $clean['title'], $clean['tagline'], $clean['description'],
                                  $clean['category'], $clean['skills'], $clean['link'], $clean['is_open']));
                    }
                    if (!$r['ok']) project_banner_delete($bannerName);   // nothing was posted, so don't keep the image
                }

                if ($r['ok']) {
                    $pid = (int)mysqli_insert_id($conn);

                    // the founder is the first team member
                    db_run($conn,
                        "INSERT INTO project_members (project_id, user_id, role, status, role_label) VALUES (?, ?, 'owner', 'active', 'Founder')",
                        "ii", array($pid, $me['id']));

                    foreach ($clean['roles'] as $role) {
                        db_run($conn, "INSERT INTO project_roles (project_id, title, spots) VALUES (?, ?, ?)",
                               "isi", array($pid, $role['title'], $role['spots']));
                    }

                    if ($clubId > 0) {
                        project_notify_club($conn, $clubId, $me['id'],
                            full_name($me) . ' started a project in ' . $clubChoices[$clubId] . ': ' . $clean['title'], 'project?id=' . $pid);
                    }

                    flash_set("Your project is live. Students can find it now" . ($clean['is_open'] ? " and apply to join." : "."), "success");
                    header("Location: project?id=" . $pid);
                    exit();
                }
                if ($error === "") $error = ($r['errno'] == 1146 || $r['errno'] == 1054)
                    ? "Projects aren't set up yet. Run projects.sql, then projects-banner.sql in phpMyAdmin."
                    : "Something went wrong. Please try again.";
            }
        }
    }
    $message = $error;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Post a project | Camigo</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:wght@600;700;800&family=Instrument+Sans:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
</head>
<body>

<?php include("topbar.php"); ?>

<main class="main narrow wide-ev">

    <p class="crumb"><a href="projects">&larr; All projects</a></p>
    <h1 class="page-title">Post a project</h1>
    <p class="page-sub">Share your idea, say who you need, and let the right people find you.</p>

    <div class="pj-tips">
        <div><?php echo project_icon('bulb'); ?><span><strong>Lead with the problem.</strong> Say what you want to fix before how.</span></div>
        <div><?php echo project_icon('seat'); ?><span><strong>Be specific about roles.</strong> "Mobile dev, 5 hrs a week" beats "developer".</span></div>
        <div><?php echo project_icon('rocket'); ?><span><strong>Show momentum.</strong> A link to a sketch or repo builds trust fast.</span></div>
    </div>

    <?php if ($message != "") { ?>
        <div class="msg error" style="margin-top:20px"><?php echo e($message); ?></div>
    <?php } ?>

    <form method="POST" enctype="multipart/form-data" class="pj-form">
        <?php echo csrf_field(); ?>

        <?php include("project-fields.php"); ?>

        <div class="form-actions">
            <button type="submit" name="post" value="1" class="btn inline">Publish project</button>
            <a href="projects" class="cancel">Cancel</a>
        </div>
    </form>

</main>

<?php include("image-framer.php"); ?>

</body>
</html>