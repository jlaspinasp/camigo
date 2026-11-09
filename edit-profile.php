<?php
session_start();
require("db.php");
require("auth.php");

$me = require_user_row($conn);

$years    = year_levels();
// only the networks whose column exists (new ones need profile-more.sql)
$allNetworks = social_networks();
$networks = array();
foreach ($allNetworks as $k => $n) { if (array_key_exists($k, $me)) $networks[$k] = $n; }
$moreLinks   = count($networks) === count($allNetworks);
$hasCurrently = array_key_exists('currently_into', $me);
$message = "";
$message_class = "";
$coverCol = array_key_exists('cover', $me);   // true once profile-cover.sql has been run

if (isset($_POST['save'])) {

    $error = "";

    $first = trim($_POST['first_name'] ?? '');
    $last  = trim($_POST['last_name'] ?? '');
    $mi    = trim($_POST['middle_initial'] ?? '');
    if ($mi !== "") {
        $mi = function_exists('mb_strtoupper') ? mb_strtoupper($mi, 'UTF-8') : strtoupper($mi);
    }
    $bio    = trim($_POST['bio'] ?? '');
    $course = trim($_POST['course'] ?? '');
    $year   = $_POST['year_level'] ?? '';
    $vis    = $_POST['visibility'] ?? 'public';

    if (!csrf_ok()) {
        $error = "Your session expired. Please try again.";
    } elseif ($first === '' || str_len($first) > 100) {
        $error = "Please enter your first name (up to 100 characters).";
    } elseif (str_len($last) > 100) {
        $error = "Your last name is too long.";
    } elseif ($mi !== "" && !preg_match('/^\p{L}$/u', $mi)) {
        $error = "Middle initial must be a single letter.";
    } elseif (str_len($bio) > 300) {
        $error = "Your bio can be up to 300 characters.";
    } elseif (str_len($course) > 100) {
        $error = "Your course can be up to 100 characters.";
    } elseif ($year !== '' && !in_array($year, $years, true)) {
        $error = "Please pick a year level from the list.";
    } elseif (!in_array($vis, array('public', 'private'), true)) {
        $error = "Please choose who can see your profile.";
    }

    // interests
    $interests = '';
    if ($error === "") {
        $interests = parse_interests($_POST['interests'] ?? '', $error, 0);   // 0 = no limit
    }

    // currently into (optional rows)
    $currentlyJson = null;
    if ($error === "" && $hasCurrently) {
        $currentlyJson = parse_currently($_POST['currently'] ?? array(), $error);
    }

    // social links
    $linkValues = array();
    if ($error === "") {
        foreach ($networks as $key => $net) {
            $linkValues[$key] = normalize_url($_POST[$key] ?? '', $net[1], $net[0], $error);
            if ($error !== "") break;
        }
    }

    // photo
    $photo = $me['photo'] ?? null;
    $newPhoto = null;
    if ($error === "") {
        $up = $_FILES['photo'] ?? null;

        if ($up && $up['error'] !== UPLOAD_ERR_NO_FILE) {
            if ($up['error'] === UPLOAD_ERR_INI_SIZE || $up['error'] === UPLOAD_ERR_FORM_SIZE || $up['size'] > 2 * 1024 * 1024) {
                $error = "Your photo is too big. Please choose one under 2 MB.";
            } elseif ($up['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($up['tmp_name'])) {
                $error = "Your photo didn't upload. Please try again.";
            } else {
                $newPhoto = save_avatar($up['tmp_name'], $error);
                if ($newPhoto) $photo = $newPhoto;
            }
        } elseif (!empty($_POST['remove_photo'])) {
            $photo = null;
        }
    }

    // cover photo
    $cover = $coverCol ? ($me['cover'] ?? null) : null;
    $newCover = null;
    if ($error === "" && $coverCol) {
        $up = $_FILES['cover'] ?? null;

        if ($up && $up['error'] !== UPLOAD_ERR_NO_FILE) {
            if ($up['error'] === UPLOAD_ERR_INI_SIZE || $up['error'] === UPLOAD_ERR_FORM_SIZE || $up['size'] > 2 * 1024 * 1024) {
                $error = "Your cover photo is too big. Please choose one under 2 MB.";
            } elseif ($up['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($up['tmp_name'])) {
                $error = "Your cover photo didn't upload. Please try again.";
            } else {
                $newCover = save_club_image($up['tmp_name'], 'cover', $error, user_cover_dir());
                if ($newCover) $cover = $newCover;
            }
        } elseif (!empty($_POST['remove_cover'])) {
            $cover = null;
        }
    }

    // a bad cover shouldn't leave a freshly saved photo behind
    if ($error !== "" && $newPhoto) {
        delete_avatar($newPhoto);
        $newPhoto = null;
    }

    // save
    if ($error === "") {
        $nn = function ($v) { return ($v === '' || $v === null) ? null : $v; };

        // build the UPDATE from the columns that exist
        $sets = array("first_name = ?", "last_name = ?", "middle_initial = ?", "bio = ?", "course = ?",
                      "year_level = ?", "interests = ?");
        $vals = array($first, $nn($last), $mi, $nn($bio), $nn($course), $nn($year), $nn($interests));
        foreach ($networks as $key => $net) {
            $sets[] = "$key = ?";
            $vals[]  = $linkValues[$key];
        }
        if ($hasCurrently) { $sets[] = "currently_into = ?"; $vals[] = $currentlyJson; }
        $sets[] = "profile_visibility = ?"; $vals[] = $vis;
        $sets[] = "photo = ?";              $vals[] = $photo;
        if ($coverCol) { $sets[] = "cover = ?"; $vals[] = $cover; }
        $vals[] = $me['username'];

        $r = db_run($conn,
            "UPDATE users SET " . implode(", ", $sets) . " WHERE username = ?",
            str_repeat("s", count($vals)), $vals);

        if ($r['ok']) {
            // clean up the old cover if it was replaced or removed
            if ($coverCol && !empty($me['cover']) && $me['cover'] !== $cover) {
                delete_club_image($me['cover'], user_cover_dir());
            }
            // clean up the old photo if it was replaced or removed
            if (!empty($me['photo']) && $me['photo'] !== $photo) {
                delete_avatar($me['photo']);
            }
            $_SESSION['first_name'] = $first;
            flash_set("Your profile was saved.", "success");
            header("Location: profile");
            exit();
        }

        if ($newPhoto) delete_avatar($newPhoto);
        if ($newCover) delete_club_image($newCover, user_cover_dir());
        $error = ($r['errno'] == 1054)
            ? "Profile fields aren't set up yet. Run profile.sql and profile-more.sql in phpMyAdmin first."
            : "Something went wrong. Please try again.";
    }

    $message = $error;
    $message_class = "error";
}

// value to show in a field: what was just posted, otherwise what is saved
function fv($key, $row) {
    return e(isset($_POST[$key]) ? $_POST[$key] : ($row[$key] ?? ''));
}
$curYear = isset($_POST['year_level']) ? $_POST['year_level'] : ($me['year_level'] ?? '');
$curVis  = isset($_POST['visibility']) ? $_POST['visibility'] : ($me['profile_visibility'] ?? 'public');
$hasPhoto = !empty($me['photo']);
$coverImg = ($coverCol && club_image_ok($me['cover'] ?? '')) ? $me['cover'] : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Edit profile | Camigo</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:wght@600;700;800&family=Instrument+Sans:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
</head>
<body>

<?php include("topbar.php"); ?>

<main class="main narrow">

    <h1 class="page-title">Edit profile</h1>
    <p class="page-sub">Tell people who you are. You decide what others can see.</p>

    <?php if ($message != "") { ?>
        <div class="msg <?php echo e($message_class); ?>" style="margin-top:20px"><?php echo e($message); ?></div>
    <?php } ?>

    <form method="POST" enctype="multipart/form-data">
        <?php echo csrf_field(); ?>

        <section class="panel">
            <h2>Photo and cover</h2>
            <p class="club-meta">Click an image to upload it, and use the &times; to remove it.</p>

            <div class="id-preview<?php echo $coverCol ? '' : ' no-cover'; ?>">
                <?php if ($coverCol) { ?>
                <div class="cover id-cover<?php echo $coverImg ? ' has-img' : ''; ?>" id="cover-preview" role="button" tabindex="0" aria-label="Upload cover photo"
                     <?php if ($coverImg) { ?>style="background-image:url('uploads/covers/<?php echo e($coverImg); ?>')"<?php } ?>>
                    <div class="id-cta"><svg viewBox="0 0 24 24" width="26" height="26" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2"/><circle cx="9" cy="10" r="1.6"/><path d="m21 16-5-5-9 9"/></svg><strong>Click to add a cover photo</strong></div>
                    <button type="button" class="id-x" id="cover-remove" aria-label="Remove cover photo"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg></button>
                </div>
                <?php } ?>

                <div class="id-logo-row">
                    <div class="id-logo-wrap id-avatar-wrap">
                        <div class="p-avatar id-avatar" id="photo-preview" role="button" tabindex="0" aria-label="Upload profile photo" data-letter="<?php echo e(first_letter($me['first_name'] ?? '?')); ?>"><?php echo avatar_inner($me); ?></div>
                        <button type="button" class="id-x" id="photo-remove" aria-label="Remove profile photo" <?php echo $hasPhoto ? '' : 'hidden'; ?>><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg></button>
                    </div>
                    <div class="id-logo-text"><strong>Profile photo</strong><span>Click your photo to upload one.</span></div>
                </div>
            </div>

            <input type="file" name="photo" id="photo" accept="image/jpeg,image/png,image/webp" hidden>
            <?php if ($coverCol) { ?><input type="file" name="cover" id="cover" accept="image/jpeg,image/png,image/webp" hidden><?php } ?>
            <input type="hidden" name="remove_photo" id="remove-photo" value="0">
            <input type="hidden" name="remove_cover" id="remove-cover" value="0">
            <?php if (!$coverCol) { ?><p class="hint">Cover photos need a one-time upgrade. Run <strong>profile-cover.sql</strong> in phpMyAdmin to turn them on.</p><?php } ?>
            <p class="hint" id="id-msg">JPG, PNG or WebP, up to 2 MB. Your photo is cropped square<?php echo $coverCol ? ', your cover wide' : ''; ?>.</p>
        </section>

        <section class="panel">
            <h2>Basics</h2>

            <div class="field">
                <div class="row-name">
                    <div>
                        <label for="first_name">First name</label>
                        <input type="text" name="first_name" id="first_name" maxlength="100" required value="<?php echo fv('first_name', $me); ?>">
                    </div>
                    <div>
                        <label for="last_name">Last name</label>
                        <input type="text" name="last_name" id="last_name" maxlength="100" value="<?php echo fv('last_name', $me); ?>">
                    </div>
                    <div>
                        <label for="middle_initial">M.I. <span class="opt">(opt.)</span></label>
                        <input type="text" name="middle_initial" id="middle_initial" maxlength="1" autocomplete="off" value="<?php echo fv('middle_initial', $me); ?>">
                    </div>
                </div>
            </div>

            <div class="field">
                <div class="two-col">
                    <div>
                        <label for="course">Course</label>
                        <input type="text" name="course" id="course" maxlength="100" placeholder="e.g. BS Computer Science" value="<?php echo fv('course', $me); ?>">
                    </div>
                    <div>
                        <label for="year_level">Year level</label>
                        <select name="year_level" id="year_level">
                            <option value="">Select</option>
                            <?php foreach ($years as $y) { ?>
                                <option value="<?php echo e($y); ?>" <?php echo ($curYear === $y) ? 'selected' : ''; ?>><?php echo e($y); ?></option>
                            <?php } ?>
                        </select>
                    </div>
                </div>
            </div>
        </section>

        <section class="panel">
            <h2>About you</h2>

            <div class="field">
                <label for="bio">Bio</label>
                <textarea name="bio" id="bio" maxlength="300" placeholder="A line or two about you"><?php echo fv('bio', $me); ?></textarea>
                <div class="hint" id="bio-count"></div>
            </div>

            <div class="field">
                <label for="chip-input">Interests <span class="opt">(add as many as you like)</span></label>
                <div class="chips" id="chips">
                    <input type="text" id="chip-input" placeholder="Type an interest and press Enter" maxlength="24" autocomplete="off">
                </div>
                <input type="hidden" name="interests" id="interests" value="<?php echo fv('interests', $me); ?>">
            </div>
        </section>

        <?php if ($hasCurrently) { ?>
        <section class="panel">
            <h2>Currently into</h2>
            <p class="club-meta">Everything here is optional. Fill in only what fits, and leave the rest empty.</p>
            <?php $curVals = isset($_POST['currently']) && is_array($_POST['currently']) ? $_POST['currently'] : array();
                  if (!$curVals) { foreach (currently_list($me['currently_into']) as $c) { $curVals[$c[0]] = $c[1]; } } ?>
            <div class="now-grid">
                <?php foreach (currently_categories() as $i => $cat) { ?>
                    <div class="field">
                        <label for="now<?php echo $i; ?>"><?php echo e($cat); ?></label>
                        <input type="text" name="currently[<?php echo e($cat); ?>]" id="now<?php echo $i; ?>" maxlength="60" value="<?php echo e($curVals[$cat] ?? ''); ?>">
                    </div>
                <?php } ?>
            </div>
        </section>
        <?php } ?>

        <section class="panel">
            <h2>Links</h2>
            <?php if (!$moreLinks || !$hasCurrently) { ?><p class="hint">More links and "Currently into" need a one-time upgrade. Run <strong>profile-more.sql</strong> in phpMyAdmin to turn them on.</p><?php } ?>
            <?php foreach ($networks as $key => $net) { ?>
                <div class="field">
                    <label for="<?php echo e($key); ?>"><?php echo e($net[0]); ?> <span class="opt">(optional)</span></label>
                    <input type="text" name="<?php echo e($key); ?>" id="<?php echo e($key); ?>" maxlength="200" placeholder="<?php echo e($net[2]); ?>" value="<?php echo fv($key, $me); ?>">
                </div>
            <?php } ?>
        </section>

        <section class="panel">
            <h2>Who can see your profile?</h2>

            <label class="radio-card">
                <input type="radio" name="visibility" value="public" <?php echo ($curVis !== 'private') ? 'checked' : ''; ?>>
                <span><strong>Public</strong><small>Any logged-in Camigo student can view your profile.</small></span>
            </label>
            <label class="radio-card">
                <input type="radio" name="visibility" value="private" <?php echo ($curVis === 'private') ? 'checked' : ''; ?>>
                <span><strong>Private</strong><small>Only you can see your profile.</small></span>
            </label>
        </section>

        <div class="form-actions">
            <button type="submit" name="save" class="btn inline">Save profile</button>
            <a href="profile" class="cancel">Cancel</a>
        </div>

    </form>

</main>

<script src="script.js"></script>
<script>
(function () {
    // ---- photo + cover pickers: click (or drop) to upload, x to remove ----
    var IMG_MAX = 2 * 1024 * 1024, TYPES = /^image\/(jpeg|png|webp)$/;
    var msg = document.getElementById("id-msg"), msgText = msg.textContent;
    function say(t) { msg.textContent = t; msg.classList.add("bad"); }
    function calm() { msg.textContent = msgText; msg.classList.remove("bad"); }

    function picker(o) {
        var input = document.getElementById(o.input), area = document.getElementById(o.area), rf = document.getElementById(o.removeField);

        function accept(f) {
            if (!f) return false;
            if (!TYPES.test(f.type)) { say("Please choose a JPG, PNG or WebP image."); return false; }
            if (f.size > IMG_MAX) { say("That image is over 2 MB. Please choose a smaller one."); return false; }
            calm(); rf.value = "0"; o.show(URL.createObjectURL(f));
            return true;
        }
        input.addEventListener("change", function () {
            var f = input.files && input.files[0];
            if (f && !accept(f)) input.value = "";
        });
        area.addEventListener("click", function () { input.click(); });
        area.addEventListener("keydown", function (e) {
            if (e.key === "Enter" || e.key === " ") { e.preventDefault(); input.click(); }
        });
        ["dragenter", "dragover"].forEach(function (t) { area.addEventListener(t, function (e) { e.preventDefault(); area.classList.add("id-drag"); }); });
        ["dragleave", "drop"].forEach(function (t) { area.addEventListener(t, function () { area.classList.remove("id-drag"); }); });
        area.addEventListener("drop", function (e) {
            e.preventDefault();
            var f = e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[0];
            if (!f) return;
            try { var dt = new DataTransfer(); dt.items.add(f); input.files = dt.files; }
            catch (err) { say("Dragging isn't supported in this browser. Click to browse instead."); return; }
            if (!accept(f)) input.value = "";
        });
        document.getElementById(o.remove).addEventListener("click", function (e) {
            e.stopPropagation(); input.value = ""; rf.value = "1"; calm(); o.reset();
        });
    }

    var avatar = document.getElementById("photo-preview"), avatarX = document.getElementById("photo-remove");
    picker({
        input: "photo", area: "photo-preview", removeField: "remove-photo", remove: "photo-remove",
        show: function (url) {
            var img = document.createElement("img");
            img.src = url; img.alt = "";
            avatar.innerHTML = ""; avatar.appendChild(img); avatarX.hidden = false;
        },
        reset: function () { avatar.textContent = avatar.getAttribute("data-letter"); avatarX.hidden = true; }
    });

    var cover = document.getElementById("cover-preview");
    if (cover) {
        picker({
            input: "cover", area: "cover-preview", removeField: "remove-cover", remove: "cover-remove",
            show: function (url) { cover.classList.add("has-img"); cover.style.backgroundImage = "url('" + url + "')"; },
            reset: function () { cover.classList.remove("has-img"); cover.style.backgroundImage = ""; }
        });
    }

    // ---- bio counter ----
    var bio = document.getElementById("bio");
    var count = document.getElementById("bio-count");
    function updateCount() { count.textContent = bio.value.length + " / 300"; }
    bio.addEventListener("input", updateCount);
    updateCount();

    // ---- interests as chips ----
    var box = document.getElementById("chips");
    var input = document.getElementById("chip-input");
    var hidden = document.getElementById("interests");
    var tags = hidden.value ? hidden.value.split(",").filter(Boolean) : [];

    function sync() { hidden.value = tags.join(","); }

    function render() {
        box.querySelectorAll(".chip").forEach(function (c) { c.remove(); });
        tags.forEach(function (t, i) {
            var chip = document.createElement("span");
            chip.className = "chip";
            chip.appendChild(document.createTextNode(t));
            var x = document.createElement("button");
            x.type = "button";
            x.textContent = "\u00d7";
            x.setAttribute("aria-label", "Remove " + t);
            x.addEventListener("click", function () { tags.splice(i, 1); sync(); render(); });
            chip.appendChild(x);
            box.insertBefore(chip, input);
        });
    }

    function add(raw) {
        var t = raw.replace(/,/g, " ").replace(/\s+/g, " ").trim();
        if (!t) return;
        var lower = t.toLowerCase();
        if (tags.some(function (x) { return x.toLowerCase() === lower; })) return;
        tags.push(t.slice(0, 24));
        sync();
        render();
    }

    input.addEventListener("keydown", function (e) {
        if (e.key === "Enter" || e.key === ",") {
            e.preventDefault();
            add(input.value);
            input.value = "";
        } else if (e.key === "Backspace" && !input.value && tags.length) {
            tags.pop(); sync(); render();
        }
    });
    input.addEventListener("blur", function () { add(input.value); input.value = ""; });
    box.addEventListener("click", function () { input.focus(); });
    box.closest("form").addEventListener("submit", function () { add(input.value); input.value = ""; });

    render();
})();
</script>
</body>
</html>