<?php
session_start();
require("db.php");
require("auth.php");

$me = require_login($conn);

$categories = array("Academic", "Arts & Culture", "Sports", "Technology", "Community Service", "Other");
$max_pending = 3;

$message = "";
$message_class = "";

$flash = flash_get();
if ($flash) {
    $message = $flash[0];
    $message_class = $flash[1];
}

if (isset($_POST['create'])) {

    $name        = trim(preg_replace('/\s+/', ' ', $_POST['name'] ?? ''));
    $category    = $_POST['category'] ?? '';
    $description = trim($_POST['description'] ?? '');
    $key         = club_key($name);
    $error       = "";

    if (!csrf_ok()) {
        $error = "Your session expired. Please try again.";
    } elseif (str_len($name) < 3 || str_len($name) > 60) {
        $error = "Club names need 3 to 60 characters.";
    } elseif (str_len($key) < 3) {
        $error = "Please use a name with at least 3 letters or numbers.";
    } elseif (!in_array($category, $categories, true)) {
        $error = "Please pick a category.";
    } elseif (str_len($description) < 20 || str_len($description) > 500) {
        $error = "Describe your club in 20 to 500 characters.";
    }

    // limit how many requests one person can have waiting
    if ($error === "") {
        $r = db_run($conn, "SELECT COUNT(*) AS n FROM clubs WHERE created_by = ? AND status = 'pending'", "i", array($me['id']));
        if (!$r['ok']) {
            $error = "Clubs aren't set up yet. Please try again later.";
        } elseif ((int)$r['rows'][0]['n'] >= $max_pending) {
            $error = "You already have $max_pending requests waiting for approval. Please wait for an admin to review them.";
        }
    }

    // duplicate check (approved and pending clubs both reserve the name)
    if ($error === "") {
        $r = db_run($conn, "SELECT status FROM clubs WHERE name_key = ? LIMIT 1", "s", array($key));
        if ($r['rows']) {
            $error = ($r['rows'][0]['status'] === 'pending')
                ? "A club with this name is already waiting for approval."
                : "A club with this name already exists.";
        }
    }

    if ($error === "") {
        $r = db_run(
            $conn,
            "INSERT INTO clubs (name, name_key, category, description, created_by, status) VALUES (?, ?, ?, ?, ?, 'pending')",
            "ssssi",
            array($name, $key, $category, $description, $me['id'])
        );

        if ($r['ok']) {
            flash_set("Your club request was sent. An admin will review it soon.", "success");
            header("Location: create-club");
            exit();
        }

        $error = ($r['errno'] == 1062)
            ? "A club with this name already exists or is waiting for approval."
            : "Something went wrong. Please try again.";
    }

    $message = $error;
    $message_class = "error";
}

function old_val($k) {
    return isset($_POST[$k]) ? e($_POST[$k]) : "";
}

$mine = db_run($conn, "SELECT name, category, status, review_note, created_at FROM clubs WHERE created_by = ? ORDER BY id DESC LIMIT 20", "i", array($me['id']));
$initial = strtoupper(substr($me['first_name'], 0, 1));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Create a club | Camigo</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:wght@600;700;800&family=Instrument+Sans:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
</head>
<body>

<?php include("topbar.php"); ?>

<main class="main narrow">

    <h1 class="page-title">Create a club</h1>
    <p class="page-sub">Every new club is reviewed by an admin before it goes live, so we can keep the list free of duplicates.</p>

    <section class="panel">
        <h2>Club details</h2>

        <?php if ($message != "") { ?>
            <div class="msg <?php echo e($message_class); ?>"><?php echo e($message); ?></div>
        <?php } ?>

        <form method="POST">
            <?php echo csrf_field(); ?>

            <div class="field">
                <label for="name">Club name</label>
                <input type="text" name="name" id="name" maxlength="60" required value="<?php echo old_val('name'); ?>">
            </div>

            <div class="field">
                <label for="category">Category</label>
                <select name="category" id="category" required>
                    <?php foreach ($categories as $c) { ?>
                        <option value="<?php echo e($c); ?>" <?php echo (($_POST['category'] ?? '') === $c) ? 'selected' : ''; ?>><?php echo e($c); ?></option>
                    <?php } ?>
                </select>
            </div>

            <div class="field">
                <label for="description">What is your club about?</label>
                <textarea name="description" id="description" maxlength="500" required><?php echo old_val('description'); ?></textarea>
            </div>

            <button type="submit" name="create" class="btn inline">Submit for approval</button>
        </form>
    </section>

    <section class="panel">
        <h2>Your requests</h2>
        <?php if (!$mine['rows']) { ?>
            <p class="empty">You haven't requested any clubs yet.</p>
        <?php } else { ?>
            <div class="club-list">
                <?php foreach ($mine['rows'] as $c) { ?>
                    <div class="club-item">
                        <div class="club-head">
                            <strong><?php echo e($c['name']); ?></strong>
                            <span class="badge <?php echo e($c['status']); ?>"><?php echo e(ucfirst($c['status'])); ?></span>
                        </div>
                        <div class="club-meta"><?php echo e($c['category']); ?> &middot; <?php echo e(date('M j, Y', strtotime($c['created_at']))); ?></div>
                        <?php if ($c['status'] === 'rejected' && $c['review_note']) { ?>
                            <p class="club-desc">Admin note: <?php echo e($c['review_note']); ?></p>
                        <?php } ?>
                    </div>
                <?php } ?>
            </div>
        <?php } ?>
    </section>

</main>

</body>
</html>