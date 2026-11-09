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
$f = array();
$fRoles = array();
$clubChoices = array();

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
        'title' => $p['title'], 'tagline' => $p['tagline'], 'category' => $p['category'], 'stage' => $p['stage'],
        'description' => $p['description'], 'skills' => (string)$p['skills'], 'link' => (string)$p['link'],
        'is_open' => (int)$p['is_open'], 'club_id' => (int)$p['club_id'],
    );
    foreach ($roles as $r) $fRoles[] = array('id' => (int)$r['id'], 'title' => $r['title'], 'spots' => (int)$r['spots']);

    if (isset($_POST['save'])) {

        foreach (array('title', 'tagline', 'category', 'stage', 'description', 'skills', 'link') as $k) {
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

            if ($clean && $error === "") {
                $newClub = (int)($_POST['club_id'] ?? 0);
                if (!isset($clubChoices[$newClub])) $newClub = 0;

                // moving a launched project back to a working stage counts as running again
                if ($p['stage'] === 'launched' && $clean['stage'] !== 'launched') {
                    $run = db_run($conn, "SELECT COUNT(*) AS n FROM projects WHERE owner_id = ? AND stage <> 'launched'", "i", array($me['id']));
                    if ($run['ok'] && $run['rows'] && (int)$run['rows'][0]['n'] >= PROJECT_MAX_RUNNING) {
                        $error = "You're already running " . PROJECT_MAX_RUNNING . " projects. Mark one as launched first.";
                    }
                }
            }

            if ($clean && $error === "") {
                $r = db_run($conn,
                    "UPDATE projects SET title = ?, tagline = ?, description = ?, category = ?, stage = ?, skills = ?, link = ?, is_open = ?, club_id = ?
                     WHERE id = ? AND owner_id = ?",
                    "sssssssiiii",
                    array($clean['title'], $clean['tagline'], $clean['description'], $clean['category'], $clean['stage'],
                          $clean['skills'], $clean['link'], $clean['is_open'], $newClub > 0 ? $newClub : null, $pid, $me['id']));

                if ($r['ok']) {
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

                    if ($p['stage'] !== $clean['stage']) {
                        project_notify_team($conn, $pid, $me['id'], 'project_update',
                            $clean['title'] . ' moved to ' . project_stage_label($clean['stage']) . '.', 'project?id=' . $pid);
                    }

                    flash_set("Your project was updated.", "success");
                    header("Location: project?id=" . $pid);
                    exit();
                }
                $error = "Something went wrong. Please try again.";
            }
        }
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

        <form method="POST" class="pj-form">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="project_id" value="<?php echo $pid; ?>">

            <?php include("project-fields.php"); ?>

            <div class="form-actions">
                <button type="submit" name="save" value="1" class="btn inline">Save changes</button>
                <a href="project?id=<?php echo $pid; ?>" class="cancel">Cancel</a>
            </div>
        </form>

    <?php } ?>

</main>

</body>
</html>
