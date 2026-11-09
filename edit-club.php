<?php
session_start();
require("db.php");
require("auth.php");

$me  = require_user_row($conn);
$cid = (int)($_GET['id'] ?? $_POST['club_id'] ?? 0);

$cr = db_run($conn, "SELECT id, name, category, description FROM clubs WHERE id = ? AND status = 'approved' LIMIT 1", "i", array($cid));
$club = $cr['rows'] ? $cr['rows'][0] : null;

if (!$club) {
    header("Location: clubs");
    exit();
}

// only the club's owner can edit it
$ow = db_run($conn, "SELECT id FROM club_members WHERE club_id = ? AND user_id = ? AND role = 'owner' AND status = 'active' LIMIT 1", "ii", array($cid, $me['id']));
if (!$ow['rows']) {
    header("Location: club?id=" . $cid);
    exit();
}

$categories = club_categories();
$message = "";

if (isset($_POST['save'])) {

    $category    = $_POST['category'] ?? '';
    $description = trim($_POST['description'] ?? '');
    $error = "";

    if (!csrf_ok()) {
        $error = "Your session expired. Please try again.";
    } elseif (!in_array($category, $categories, true)) {
        $error = "Please pick a category.";
    } elseif (str_len($description) < 20 || str_len($description) > 500) {
        $error = "Describe your club in 20 to 500 characters.";
    } else {
        $r = db_run($conn, "UPDATE clubs SET category = ?, description = ? WHERE id = ?", "ssi", array($category, $description, $cid));
        if ($r['ok']) {
            flash_set("Your club was updated.", "success");
            header("Location: club?id=" . $cid);
            exit();
        }
        $error = "Something went wrong. Please try again.";
    }

    $message = $error;
}

$curCat  = isset($_POST['category']) ? $_POST['category'] : $club['category'];
$curDesc = isset($_POST['description']) ? $_POST['description'] : $club['description'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Edit club | Camigo</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:wght@600;700;800&family=Instrument+Sans:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
</head>
<body>

<?php include("topbar.php"); ?>

<main class="main narrow">

    <p class="crumb"><a href="club?id=<?php echo (int)$club['id']; ?>">&larr; Back to club</a></p>
    <h1 class="page-title">Edit <?php echo e($club['name']); ?></h1>
    <p class="page-sub">Update how your club appears to other students.</p>

    <?php if ($message != "") { ?>
        <div class="msg error" style="margin-top:20px"><?php echo e($message); ?></div>
    <?php } ?>

    <form method="POST" class="panel">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="club_id" value="<?php echo (int)$club['id']; ?>">

        <div class="field">
            <label for="name">Club name</label>
            <input type="text" id="name" value="<?php echo e($club['name']); ?>" disabled>
            <div class="hint">Names can't be changed, so every club stays unique. Ask an admin if a club needs to be renamed.</div>
        </div>

        <div class="field">
            <label for="category">Category</label>
            <select name="category" id="category">
                <?php foreach ($categories as $c) { ?>
                    <option value="<?php echo e($c); ?>" <?php echo ($curCat === $c) ? 'selected' : ''; ?>><?php echo e($c); ?></option>
                <?php } ?>
            </select>
        </div>

        <div class="field">
            <label for="description">What is your club about?</label>
            <textarea name="description" id="description" maxlength="500" required><?php echo e($curDesc); ?></textarea>
        </div>

        <div class="form-actions">
            <button type="submit" name="save" class="btn inline">Save changes</button>
            <a href="club?id=<?php echo (int)$club['id']; ?>" class="cancel">Cancel</a>
        </div>
    </form>

</main>

</body>
</html>