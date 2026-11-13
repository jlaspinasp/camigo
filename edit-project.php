<?php
session_start();
require("db.php");
require("auth.php");
require_once("projects-lib.php");

$me  = require_user_row($conn);
$pid = (int)($_GET['id'] ?? $_POST['project_id'] ?? 0);

$p = $pid > 0 ? project_get($conn, $pid) : null;
$allowed = $p && ((int)$p['owner_id'] === (int)$me['id']);

$message = "";
// a file larger than the server's post limit arrives as an empty form
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    $message = "That upload was too large for the server. Try a smaller image (up to 10 MB).";
}
$f = array();
$fRoles = array();
$clubChoices = array();

// project banner: its own upload, otherwise the project keeps its coloured cover
$bannerReady = projects_banner_ready($conn);
$oldBanner   = ($bannerReady && $p && !empty($p['banner']) && club_image_ok($p['banner'])) ? $p['banner'] : '';
$bannerCur   = $oldBanner;
$bannerTone  = $pid > 0 ? project_tone($pid) : 0;
$logoReady   = projects_logo_ready($conn);   // the small square project icon
$oldLogo     = ($logoReady && $p && !empty($p['logo']) && club_image_ok($p['logo'])) ? $p['logo'] : '';
$logoCur     = $oldLogo;

if ($allowed) {
    $roles = project_roles($conn, $pid);
    $existing = array();   // role id => filled
    foreach ($roles as $r) $existing[(int)$r['id']] = (int)$r['filled'];

    // clubs they can tag, plus the one already attached
    foreach (event_postable_clubs($conn, $me['id']) as $c) $clubChoices[(int)$c['id']] = $c['name'];
    if (!empty($p['club_id']) && !empty($p['club_name']) && !isset($clubChoices[(int)$p['club_id']])) {
        $clubChoices[(int)$p['club_id']] = $p['club_name'];
    }

    $f = array(
        'title' => $p['title'], 'tagline' => $p['tagline'], 'category' => $p['category'],
        'description' => $p['description'], 'skills' => (string)$p['skills'], 'link' => (string)$p['link'],
        'is_open' => (int)$p['is_open'], 'club_id' => (int)$p['club_id'],
    );
    foreach ($roles as $r) $fRoles[] = array('id' => (int)$r['id'], 'title' => $r['title'], 'spots' => (int)$r['spots']);

    if (isset($_POST['delete_project'])) {

        if (!csrf_ok()) {
            $message = "Your session expired. Please try again.";
        } else {
            project_notify_team($conn, $pid, $me['id'], 'project_removed', $p['title'] . ' was removed by its founder.', 'projects');
            db_run($conn, "DELETE FROM project_updates WHERE project_id = ?", "i", array($pid));
            db_run($conn, "DELETE FROM project_members WHERE project_id = ?", "i", array($pid));
            db_run($conn, "DELETE FROM project_roles WHERE project_id = ?", "i", array($pid));
            db_run($conn, "DELETE FROM notifications WHERE link = ?", "s", array('project?id=' . $pid));
            $r = db_run($conn, "DELETE FROM projects WHERE id = ? AND owner_id = ?", "ii", array($pid, $me['id']));
            if ($r['ok'] && $r['affected'] > 0) {
                if ($oldBanner !== '') project_banner_delete($oldBanner);
                if ($oldLogo !== '')   project_logo_delete($oldLogo);
                flash_set("Your project was deleted.", "success");
                header("Location: projects");
                exit();
            }
            $message = "Something went wrong. Please try again.";
        }

    } elseif (isset($_POST['save'])) {

        foreach (array('title', 'tagline', 'category', 'description', 'skills', 'link') as $k) {
            $f[$k] = (string)($_POST[$k] ?? '');
        }
        $f['is_open'] = empty($_POST['is_open']) ? 0 : 1;
        $f['club_id'] = (int)($_POST['club_id'] ?? 0);
        $fRoles = array();
        if (!empty($_POST['role_title']) && is_array($_POST['role_title'])) {
            foreach ($_POST['role_title'] as $i => $t) {
                if (!is_string($t) || trim($t) === '') continue;
                $fRoles[] = array('id' => (int)(($_POST['role_id'][$i] ?? 0)), 'title' => $t, 'spots' => (int)(($_POST['role_spots'][$i] ?? 1)));
            }
        }

        $error = "";

        if (!csrf_ok()) {
            $error = "Your session expired. Please try again.";
        } else {
            $clean = project_clean($_POST, $error);

            if ($clean) {
                // role ids must belong to this project, and spots can't drop below the people already in the role
                foreach ($clean['roles'] as $k => $role) {
                    if ($role['id'] > 0 && !isset($existing[$role['id']])) {
                        $clean['roles'][$k]['id'] = 0;
                    } elseif ($role['id'] > 0 && $role['spots'] < $existing[$role['id']]) {
                        $error = $role['title'] . " already has " . $existing[$role['id']] . " people on the team, so it needs at least that many spots.";
                        break;
                    }
                }
            }

            $newClub = 0;
            if ($clean && $error === "") {
                $newClub = (int)($_POST['club_id'] ?? 0);
                if (!isset($clubChoices[$newClub])) $newClub = 0;
            }

            // the banner: a new upload replaces the old one, the x removes it, otherwise it stays as it is
            $newBanner   = null;
            $bannerFinal = $oldBanner !== '' ? $oldBanner : null;
            if ($clean && $error === "") {
                if ($bannerReady) {
                    $newBanner = project_banner_upload('banner', $error);
                    if ($error === "") {
                        if ($newBanner !== null)                   $bannerFinal = $newBanner;
                        elseif (!empty($_POST['remove_banner']))   $bannerFinal = null;
                    }
                } elseif (!empty($_FILES['banner']['name'])) {
                    $error = "Project banners need a one-time upgrade. Run projects-banner.sql in phpMyAdmin.";
                }
            }

            // the logo works the same way: a new upload replaces the old one, the x removes it
            $newLogo   = null;
            $logoFinal = $oldLogo !== '' ? $oldLogo : null;
            if ($clean && $error === "") {
                if ($logoReady) {
                    $newLogo = project_logo_upload('logo', $error);
                    if ($error === "") {
                        if ($newLogo !== null)                   $logoFinal = $newLogo;
                        elseif (!empty($_POST['remove_logo']))   $logoFinal = null;
                    }
                } elseif (!empty($_FILES['logo']['name'])) {
                    $error = "Project logos need a one-time upgrade. Run projects-logo.sql in phpMyAdmin.";
                }
            }

            if ($clean && $error === "") {
                $sql    = "UPDATE projects SET title = ?, tagline = ?, description = ?, category = ?, skills = ?, link = ?, is_open = ?, club_id = ?"
                        . ($bannerReady ? ", banner = ?" : "") . ($logoReady ? ", logo = ?" : "") . " WHERE id = ? AND owner_id = ?";
                $types  = "ssssssii" . ($bannerReady ? "s" : "") . ($logoReady ? "s" : "") . "ii";
                $params = array($clean['title'], $clean['tagline'], $clean['description'], $clean['category'],
                                $clean['skills'], $clean['link'], $clean['is_open'], $newClub > 0 ? $newClub : null);
                if ($bannerReady) $params[] = $bannerFinal;
                if ($logoReady)   $params[] = $logoFinal;
                $params[] = $pid;
                $params[] = $me['id'];
                $r = db_run($conn, $sql, $types, $params);

                if ($r['ok']) {
                    // the old image is no longer used once the new one (or none) is saved
                    if ($bannerReady && $oldBanner !== '' && $bannerFinal !== $oldBanner) project_banner_delete($oldBanner);
                    if ($logoReady && $oldLogo !== '' && $logoFinal !== $oldLogo) project_logo_delete($oldLogo);

                    // roles: update the ones we know, add new ones, drop the ones that were removed
                    $keep = array();
                    foreach ($clean['roles'] as $role) {
                        if ($role['id'] > 0) {
                            db_run($conn, "UPDATE project_roles SET title = ?, spots = ? WHERE id = ? AND project_id = ?", "siii",
                                   array($role['title'], $role['spots'], $role['id'], $pid));
                            db_run($conn, "UPDATE project_members SET role_label = ? WHERE role_id = ? AND project_id = ?", "sii",
                                   array($role['title'], $role['id'], $pid));
                            $keep[$role['id']] = true;
                        } else {
                            db_run($conn, "INSERT INTO project_roles (project_id, title, spots) VALUES (?, ?, ?)", "isi",
                                   array($pid, $role['title'], $role['spots']));
                            $keep[(int)mysqli_insert_id($conn)] = true;
                        }
                    }
                    foreach ($existing as $rid => $filled) {
                        if (isset($keep[$rid])) continue;
                        db_run($conn, "UPDATE project_members SET role_id = NULL WHERE role_id = ? AND project_id = ?", "ii", array($rid, $pid));
                        db_run($conn, "DELETE FROM project_roles WHERE id = ? AND project_id = ?", "ii", array($rid, $pid));
                    }

                    flash_set("Your project was updated.", "success");
                    header("Location: project?id=" . $pid);
                    exit();
                }
                $error = "Something went wrong. Please try again.";
            }
        }

        // a failed save shouldn't leave stray uploads behind
        if ($error !== "" && !empty($newBanner)) project_banner_delete($newBanner);
        if ($error !== "" && !empty($newLogo))   project_logo_delete($newLogo);

        $message = $error;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Edit project | Camigo</title>
    <link rel="icon" href="favicon.svg" type="image/svg+xml">
    <link rel="icon" href="favicon.ico" sizes="any">
    <link rel="apple-touch-icon" href="apple-touch-icon.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:wght@600;700;800&family=Instrument+Sans:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
</head>
<body>

<?php include("topbar.php"); ?>

<main class="main narrow wide-ev">

    <?php if (!$allowed) { ?>

        <section class="panel center">
            <h2><?php echo $p ? "Only the founder can edit this project" : "Project not found"; ?></h2>
            <p class="empty"><?php echo $p ? "Ask the founder if you'd like something changed." : "It may have been removed."; ?> <a href="projects">Browse projects</a></p>
        </section>

    <?php } else { ?>

        <p class="crumb"><a href="project?id=<?php echo $pid; ?>">&larr; Back to project</a></p>
        <h1 class="page-title">Edit project</h1>
        <p class="page-sub">Keep your post fresh. People are more likely to join a project that looks active.</p>

        <?php if ($message != "") { ?>
            <div class="msg error" style="margin-top:20px"><?php echo e($message); ?></div>
        <?php } ?>

        <form method="POST" enctype="multipart/form-data" class="pj-form">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="project_id" value="<?php echo $pid; ?>">

            <?php include("project-fields.php"); ?>

            <div class="form-actions">
                <button type="submit" name="save" value="1" class="btn inline">Save changes</button>
                <a href="project?id=<?php echo $pid; ?>" class="cancel">Cancel</a>
            </div>
        </form>

        <?php
        $dzKind = 'project'; $dzName = $p['title']; $dzField = 'delete_project'; $dzIdField = 'project_id'; $dzIdValue = $pid;
        include("danger-zone.php");
        ?>

    <?php } ?>

</main>

<?php include("image-framer.php"); ?>

</body>
</html>