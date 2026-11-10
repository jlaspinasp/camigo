<?php
session_start();
require("db.php");
require("auth.php");

$me  = require_user_row($conn);
if ($_SERVER['REQUEST_METHOD'] === 'POST') register_shutdown_function('chat_club_sync_quiet', $conn);

$cid = (int)($_GET['id'] ?? $_POST['club_id'] ?? 0);

$cr = db_run($conn, "SELECT * FROM clubs WHERE id = ? AND status = 'approved' LIMIT 1", "i", array($cid));
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
$identity   = club_identity_ready($conn);
$message    = "";

// a file larger than the server's post limit arrives as an empty form, so the save button never registers
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    $message = "That upload was too large for the server. Try smaller images (up to 2 MB each).";
}

// Reads one uploaded image field. Returns the saved file name, or null (sets $error if something was wrong).
function club_upload_field($field, $kind, &$error) {
    $up = $_FILES[$field] ?? null;
    if (!$up || ($up['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($up['error'] !== UPLOAD_ERR_OK) {
        $error = "That image couldn't be uploaded. Please try a smaller file.";
        return null;
    }
    if ($up['size'] > 2 * 1024 * 1024) {
        $error = "Images can be up to 2 MB.";
        return null;
    }
    return save_club_image($up['tmp_name'], $kind, $error);
}

if (isset($_POST['save'])) {

    $category    = $_POST['category'] ?? '';
    $description = trim($_POST['description'] ?? '');
    $error = "";
    $newLogo = null;
    $newCover = null;

    if (!csrf_ok()) {
        $error = "Your session expired. Please try again.";
    } elseif (!in_array($category, $categories, true)) {
        $error = "Please pick a category.";
    } elseif (str_len($description) < 20 || str_len($description) > 500) {
        $error = "Describe your club in 20 to 500 characters.";
    } else {

        if ($identity) {
            $newLogo = club_upload_field('logo', 'logo', $error);
            if ($error === "") {
                $newCover = club_upload_field('cover', 'cover', $error);
            }
        }

        if ($error === "") {
            $sql    = "UPDATE clubs SET category = ?, description = ?";
            $types  = "ss";
            $params = array($category, $description);

            $oldLogo = $club['logo'] ?? null;
            $oldCover = $club['cover'] ?? null;
            $logoFinal = $oldLogo;
            $coverFinal = $oldCover;

            if ($identity) {
                if ($newLogo !== null) {
                    $logoFinal = $newLogo;
                } elseif (!empty($_POST['remove_logo'])) {
                    $logoFinal = null;
                }
                if ($newCover !== null) {
                    $coverFinal = $newCover;
                } elseif (!empty($_POST['remove_cover'])) {
                    $coverFinal = null;
                }

                // invite-only: a fresh link is made when it's switched on, or when the owner asks for one
                $wantInvite = !empty($_POST['invite_only']) ? 1 : 0;
                $token = $club['invite_token'] ?? null;
                if (!$wantInvite) {
                    $token = null;
                } elseif (empty($club['invite_only']) || empty($token) || !empty($_POST['new_link'])) {
                    $token = bin2hex(random_bytes(10));
                }

                $sql   .= ", logo = ?, cover = ?, invite_only = ?, invite_token = ?";
                $types .= "ssis";
                array_push($params, $logoFinal, $coverFinal, $wantInvite, $token);
            }

            $sql    .= " WHERE id = ?";
            $types  .= "i";
            $params[] = $cid;

            $r = db_run($conn, $sql, $types, $params);
            if ($r['ok']) {
                if ($identity) {
                    if ($logoFinal !== $oldLogo) delete_club_image($oldLogo);
                    if ($coverFinal !== $oldCover) delete_club_image($oldCover);
                }
                flash_set("Your club was updated.", "success");
                header("Location: club?id=" . $cid);
                exit();
            }
            $error = "Something went wrong. Please try again.";
        }
    }

    // a failed save shouldn't leave stray uploads behind
    if ($error !== "") {
        delete_club_image($newLogo);
        delete_club_image($newCover);
    }

    $message = $error;
}

$curCat  = isset($_POST['category']) ? $_POST['category'] : $club['category'];
$curDesc = isset($_POST['description']) ? $_POST['description'] : $club['description'];
$curInvite = isset($_POST['save']) ? !empty($_POST['invite_only']) : !empty($club['invite_only']);

$coverImg = ($identity && club_image_ok($club['cover'] ?? '')) ? $club['cover'] : '';
$logoImg  = ($identity && club_image_ok($club['logo'] ?? '')) ? $club['logo'] : '';

// the shareable invite link
$inviteUrl = '';
if ($identity && !empty($club['invite_only']) && !empty($club['invite_token'])) {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
    $inviteUrl = $scheme . '://' . $_SERVER['HTTP_HOST'] . $dir . '/club?id=' . (int)$club['id'] . '&invite=' . $club['invite_token'];
}
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

    <form method="POST" enctype="multipart/form-data">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="club_id" value="<?php echo (int)$club['id']; ?>">

        <?php if ($identity) { ?>
        <section class="panel">
            <h2>Club identity</h2>
            <p class="club-meta">A logo and a cover make your page easy to recognise. Click an image to upload it, and use the &times; to remove it.</p>

            <div class="id-preview">
                <div class="cover id-cover<?php echo $coverImg ? ' has-img' : ''; ?>" id="cover-preview" role="button" tabindex="0" aria-label="Change cover image"
                     data-gd="<?php echo (int)$club['id'] % 6; ?>"
                     <?php if ($coverImg) { ?>style="background-image:url('uploads/clubs/<?php echo e($coverImg); ?>')"<?php } else { ?>data-g="<?php echo (int)$club['id'] % 6; ?>"<?php } ?>>
                    <div class="id-cta"><svg viewBox="0 0 24 24" width="26" height="26" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2"/><circle cx="9" cy="10" r="1.6"/><path d="m21 16-5-5-9 9"/></svg><strong>Click to add a cover image</strong></div>
                    <button type="button" class="id-x" id="cover-remove" aria-label="Remove cover image"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg></button>
                </div>

                <div class="id-logo-row">
                    <div class="id-logo-wrap">
                        <div class="club-tile big t<?php echo (int)$club['id'] % 3; ?><?php echo $logoImg ? ' has-logo' : ''; ?>" id="logo-preview" role="button" tabindex="0" aria-label="Change logo" data-letter="<?php echo e(first_letter($club['name'])); ?>">
                            <?php if ($logoImg) { ?><img src="uploads/clubs/<?php echo e($logoImg); ?>" alt=""><?php } else { echo e(first_letter($club['name'])); } ?>
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
            <p class="hint" id="id-msg">JPG, PNG or WebP, up to 2 MB. Covers are cropped wide, logos square.</p>
        </section>
        <?php } else { ?>
            <div class="msg error" style="margin-top:20px">Logos, covers and invite-only clubs need a one-time upgrade. Run <strong>club-identity.sql</strong> in phpMyAdmin to unlock them.</div>
        <?php } ?>

        <section class="panel">
            <h2>About</h2>

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
        </section>

        <?php if ($identity) { ?>
        <section class="panel">
            <h2>Privacy</h2>

            <label class="remember" style="margin:0"><input type="checkbox" name="invite_only" value="1" id="invite-only" <?php echo $curInvite ? 'checked' : ''; ?>> <strong>Invite-only club</strong></label>
            <p class="club-meta" style="margin-top:8px">People can still find your club, but they can't ask to join and they can't see who's in it. Anyone with your invite link joins straight away.</p>

            <div id="invite-box" class="invite-box" <?php echo ($curInvite && $inviteUrl !== '') ? '' : 'hidden'; ?>>
                <label for="invite-url">Your invite link</label>
                <div class="invite-row">
                    <input type="text" id="invite-url" value="<?php echo e($inviteUrl); ?>" readonly onclick="this.select()">
                    <button type="button" class="btn-sm btn-dark" id="copy-invite">Copy</button>
                </div>
                <label class="remember" style="margin:10px 0 0"><input type="checkbox" name="new_link" value="1"> Create a new link when I save (the old one stops working)</label>
            </div>
            <?php if ($curInvite && $inviteUrl === '') { ?>
                <p class="club-meta" style="margin-top:12px">Save your changes to get your invite link.</p>
            <?php } ?>
        </section>
        <?php } ?>

        <div class="form-actions">
            <button type="submit" name="save" class="btn inline">Save changes</button>
            <a href="club?id=<?php echo (int)$club['id']; ?>" class="cancel">Cancel</a>
        </div>
    </form>

</main>

<?php if ($identity) { ?>
<script>
(function () {
    var MAX = 2 * 1024 * 1024;
    var TYPES = /^image\/(jpeg|png|webp)$/;
    var msg = document.getElementById("id-msg");
    var msgText = msg.textContent;

    function say(text) { msg.textContent = text; msg.classList.add("bad"); }
    function calm() { msg.textContent = msgText; msg.classList.remove("bad"); }

    // one picker per image: click, keyboard, drag and drop, change and remove
    function picker(o) {
        var input = document.getElementById(o.input);
        var area = document.getElementById(o.area);
        var removeField = document.getElementById(o.removeField);

        function open() { input.click(); }

        function accept(file) {
            if (!file) return false;
            if (!TYPES.test(file.type)) { say("Please choose a JPG, PNG or WebP image."); return false; }
            if (file.size > MAX) { say("That image is over 2 MB. Please choose a smaller one."); return false; }
            calm();
            removeField.value = "0";
            o.show(URL.createObjectURL(file));
            return true;
        }

        input.addEventListener("change", function () {
            var f = input.files && input.files[0];
            if (!f) return;
            if (!accept(f)) input.value = "";
        });

        o.openers.forEach(function (id) {
            var el = document.getElementById(id);
            el.addEventListener("click", function (e) { e.stopPropagation(); open(); });
        });
        area.addEventListener("click", open);
        area.addEventListener("keydown", function (e) {
            if (e.key === "Enter" || e.key === " ") { e.preventDefault(); open(); }
        });

        ["dragenter", "dragover"].forEach(function (t) {
            area.addEventListener(t, function (e) { e.preventDefault(); area.classList.add("id-drag"); });
        });
        ["dragleave", "drop"].forEach(function (t) {
            area.addEventListener(t, function () { area.classList.remove("id-drag"); });
        });
        area.addEventListener("drop", function (e) {
            e.preventDefault();
            var f = e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[0];
            if (!f) return;
            try {
                var dt = new DataTransfer();
                dt.items.add(f);
                input.files = dt.files;
            } catch (err) { say("Dragging isn't supported in this browser. Click to browse instead."); return; }
            if (!accept(f)) input.value = "";
        });

        document.getElementById(o.remove).addEventListener("click", function (e) {
            e.stopPropagation();
            input.value = "";
            removeField.value = "1";
            calm();
            o.reset();
        });
    }

    var cover = document.getElementById("cover-preview");
    picker({
        input: "cover", area: "cover-preview", removeField: "remove-cover", remove: "cover-remove", openers: [],
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
    picker({
        input: "logo", area: "logo-preview", removeField: "remove-logo", remove: "logo-remove", openers: [],
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

    // invite link: copy button and show/hide
    var copy = document.getElementById("copy-invite");
    if (copy) {
        copy.addEventListener("click", function () {
            var box = document.getElementById("invite-url");
            box.select();
            var done = function () { copy.textContent = "Copied"; setTimeout(function () { copy.textContent = "Copy"; }, 1800); };
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(box.value).then(done, function () { document.execCommand("copy"); done(); });
            } else {
                document.execCommand("copy");
                done();
            }
        });
    }
    var toggle = document.getElementById("invite-only");
    var panel = document.getElementById("invite-box");
    if (toggle && panel) {
        toggle.addEventListener("change", function () {
            panel.hidden = !(toggle.checked && document.getElementById("invite-url").value !== "");
        });
    }
})();
</script>
<?php } ?>

</body>
</html>