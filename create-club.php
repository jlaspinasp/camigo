<?php
session_start();
require("db.php");
require("auth.php");

$me = require_login($conn);
require_verified($conn, $me, 'create a club');

$categories = array("Academic", "Arts & Culture", "Sports", "Technology", "Community Service", "Other");
$max_pending = 3;

$message = "";
$message_class = "";

$identity = club_identity_ready($conn);
$coverImg = '';
$logoImg  = '';
$ph = first_letter(trim($_POST['name'] ?? ''));
if ($ph === '') $ph = 'A';

// one uploaded image field: returns the saved file name, or null (sets $error if something was wrong)
function club_upload_field($field, $kind, &$error) {
    $up = $_FILES[$field] ?? null;
    if (!$up || ($up['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null;
    if ($up['error'] !== UPLOAD_ERR_OK) { $error = "That image couldn't be uploaded. Please try a smaller file."; return null; }
    if ($up['size'] > 10 * 1024 * 1024) { $error = "Images can be up to 10 MB."; return null; }
    return save_club_image($up['tmp_name'], $kind, $error);
}

// a file larger than the server's post limit arrives as an empty form
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    $message = "That upload was too large for the server. Try smaller images (up to 10 MB each).";
    $message_class = "error";
}

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
        $logoName = null;
        $coverName = null;
        $inviteOnly = 0;
        $token = null;

        if ($identity) {
            $logoName = club_upload_field('logo', 'logo', $error);
            if ($error === "") $coverName = club_upload_field('cover', 'cover', $error);
            $inviteOnly = !empty($_POST['invite_only']) ? 1 : 0;
            $token = $inviteOnly ? bin2hex(random_bytes(10)) : null;
        }

        if ($error === "") {
            if ($identity) {
                $r = db_run($conn,
                    "INSERT INTO clubs (name, name_key, category, description, created_by, status, logo, cover, invite_only, invite_token)
                     VALUES (?, ?, ?, ?, ?, 'pending', ?, ?, ?, ?)",
                    "ssssissis", array($name, $key, $category, $description, $me['id'], $logoName, $coverName, $inviteOnly, $token));
            } else {
                $r = db_run($conn,
                    "INSERT INTO clubs (name, name_key, category, description, created_by, status) VALUES (?, ?, ?, ?, ?, 'pending')",
                    "ssssi", array($name, $key, $category, $description, $me['id']));
            }

            if ($r['ok']) {
                flash_set("Your club request was sent. An admin will review it soon.", "success");
                header("Location: create-club");
                exit();
            }

            $error = ($r['errno'] == 1062)
                ? "A club with this name already exists or is waiting for approval."
                : "Something went wrong. Please try again.";
        }

        // a failed request shouldn't leave stray uploads behind
        delete_club_image($logoName);
        delete_club_image($coverName);
    }

    $message = $error;
    $message_class = "error";
}

function old_val($k) {
    return isset($_POST[$k]) ? e($_POST[$k]) : "";
}

// ---- delete one of my own requests that hasn't been approved (approved clubs are deleted from the club page) ----
if (isset($_POST['delete_request'])) {
    $did = (int)($_POST['club_id'] ?? 0);
    if (!csrf_ok()) {
        $message = "Your session expired. Please try again.";
        $message_class = "error";
    } else {
        $cr = db_run($conn, "SELECT * FROM clubs WHERE id = ? AND created_by = ? AND status IN ('pending', 'rejected') LIMIT 1", "ii", array($did, $me['id']));
        if ($cr['rows'] && club_delete_everything($conn, $cr['rows'][0], 'its owner', '', (int)$me['id'])) {
            $message = "Your club request was deleted.";
            $message_class = "success";
        } else {
            $message = "That request couldn't be deleted. It may already be gone.";
            $message_class = "error";
        }
    }
}

$mine = db_run($conn, "SELECT id, name, category, status, review_note, created_at FROM clubs WHERE created_by = ? ORDER BY id DESC LIMIT 20", "i", array($me['id']));
$initial = strtoupper(substr($me['first_name'], 0, 1));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Create a club | Camigo</title>
    <link rel="icon" href="favicon.svg" type="image/svg+xml">
    <link rel="icon" href="favicon.ico" sizes="any">
    <link rel="apple-touch-icon" href="apple-touch-icon.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:wght@600;700;800&family=Instrument+Sans:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
    <script src="confirm.js?v=<?php echo (int)@filemtime(__DIR__ . '/confirm.js'); ?>" defer></script>
</head>
<body>

<?php include("topbar.php"); ?>

<main class="main narrow">

    <h1 class="page-title">Create a club</h1>
    <p class="page-sub">Every new club is reviewed by an admin before it goes live, so we can keep the list free of duplicates.</p>

    <?php if ($message != "") { ?>
        <div class="msg <?php echo e($message_class); ?>" style="margin-top:20px"><?php echo e($message); ?></div>
    <?php } ?>

    <form method="POST" enctype="multipart/form-data">
        <?php echo csrf_field(); ?>

        <?php if ($identity) { ?>
        <section class="panel">
            <h2>Club identity</h2>
            <p class="club-meta">A logo and a cover make your page easy to recognise. Click an image to upload it, then drag and zoom to frame it. Use the &times; to remove it.</p>

            <div class="id-preview">
                <div class="cover id-cover<?php echo $coverImg ? ' has-img' : ''; ?>" id="cover-preview" role="button" tabindex="0" aria-label="Change cover image"
                     data-gd="<?php echo 0; ?>"
                     <?php if ($coverImg) { ?>style="background-image:url('uploads/clubs/<?php echo e($coverImg); ?>')"<?php } else { ?>data-g="<?php echo 0; ?>"<?php } ?>>
                    <div class="id-cta"><svg viewBox="0 0 24 24" width="26" height="26" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2"/><circle cx="9" cy="10" r="1.6"/><path d="m21 16-5-5-9 9"/></svg><strong>Click to add a cover image</strong></div>
                    <button type="button" class="id-x" id="cover-remove" aria-label="Remove cover image"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg></button>
                </div>

                <div class="id-logo-row">
                    <div class="id-logo-wrap">
                        <div class="club-tile big t<?php echo 0; ?><?php echo $logoImg ? ' has-logo' : ''; ?>" id="logo-preview" role="button" tabindex="0" aria-label="Change logo" data-letter="<?php echo e($ph); ?>">
                            <?php if ($logoImg) { ?><img src="uploads/clubs/<?php echo e($logoImg); ?>" alt=""><?php } else { echo e($ph); } ?>
                        </div>
                        <button type="button" class="id-x" id="logo-remove" aria-label="Remove logo" <?php echo $logoImg ? '' : 'hidden'; ?>><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg></button>
                    </div>
                    <div class="id-logo-text"><strong>Club logo</strong><span>Click the logo to upload one.</span></div>
                </div>
            </div>

            <input type="file" name="cover" id="cover" accept="image/jpeg,image/png,image/webp" hidden>
            <input type="file" name="logo" id="logo" accept="image/jpeg,image/png,image/webp" hidden>
            <input type="hidden" name="remove_cover" id="remove-cover" value="0">
            <input type="hidden" name="remove_logo" id="remove-logo" value="0">
            <p class="hint" id="id-msg">JPG, PNG or WebP, up to 10 MB. You'll get to frame each image after you choose it.</p>
        </section>
        <?php } else { ?>
            <div class="msg error" style="margin-top:20px">Logos, covers and invite-only clubs need a one-time upgrade. Run <strong>club-identity.sql</strong> in phpMyAdmin to unlock them.</div>
        <?php } ?>

        <section class="panel">
            <h2>Club details</h2>

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
        </section>

        <?php if ($identity) { ?>
        <section class="panel">
            <h2>Privacy</h2>
            <label class="remember" style="margin:0"><input type="checkbox" name="invite_only" value="1" <?php echo !empty($_POST['invite_only']) ? 'checked' : ''; ?>> <strong>Invite-only club</strong></label>
            <p class="club-meta" style="margin-top:8px">People can still find your club, but they can't ask to join and they can't see who's in it. Anyone with your invite link joins straight away. You'll get your link as soon as an admin approves the club.</p>
        </section>
        <?php } ?>

        <button type="submit" name="create" class="btn inline">Submit for approval</button>
    </form>

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
                        <?php if ($c['status'] === 'pending' || $c['status'] === 'rejected') { ?>
                            <form method="POST" class="tool-delete" data-confirm="Your club request will be deleted." data-confirm-title="Delete this request?" data-confirm-ok="Delete request" data-confirm-tone="danger" data-confirm-icon="trash">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="club_id" value="<?php echo (int)$c['id']; ?>">
                                <button type="submit" name="delete_request" value="1" class="btn-sm btn-ghost danger">Delete request</button>
                            </form>
                        <?php } ?>
                    </div>
                <?php } ?>
            </div>
        <?php } ?>
    </section>

</main>

<?php if ($identity) { ?>
<?php include("image-framer.php"); ?>
<script>
(function () {
    var cover = document.getElementById("cover-preview");
    CamigoPicker({
        input: "cover", area: "cover-preview", removeField: "remove-cover", remove: "cover-remove", msg: "id-msg",
        shape: "wide", title: "Frame your cover photo", fileName: "cover.jpg",
        show: function (url) {
            cover.classList.add("has-img");
            cover.removeAttribute("data-g");
            cover.style.backgroundImage = "url('" + url + "')";
        },
        reset: function () {
            cover.classList.remove("has-img");
            cover.style.backgroundImage = "";
            cover.setAttribute("data-g", cover.getAttribute("data-gd"));
        }
    });

    var logo = document.getElementById("logo-preview");
    var logoX = document.getElementById("logo-remove");
    CamigoPicker({
        input: "logo", area: "logo-preview", removeField: "remove-logo", remove: "logo-remove", msg: "id-msg",
        shape: "square", title: "Frame your logo", fileName: "logo.jpg",
        show: function (url) {
            logo.classList.add("has-logo");
            logo.innerHTML = "";
            var img = document.createElement("img");
            img.src = url;
            img.alt = "";
            logo.appendChild(img);
            logoX.hidden = false;
        },
        reset: function () {
            logo.classList.remove("has-logo");
            logo.textContent = logo.getAttribute("data-letter");
            logoX.hidden = true;
        }
    });

    // the letter on the logo follows the club name while no logo is chosen
    var nm = document.getElementById("name");
    if (nm) {
        nm.addEventListener("input", function () {
            var l = (nm.value.trim().charAt(0) || "A").toUpperCase();
            logo.setAttribute("data-letter", l);
            if (!logo.classList.contains("has-logo")) logo.textContent = l;
        });
    }
})();
</script>
<?php } ?>

</body>
</html>