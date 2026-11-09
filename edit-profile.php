<?php
session_start();
require("db.php");
require("auth.php");

$me = require_user_row($conn);

$years    = year_levels();
$networks = social_networks();
$message = "";
$message_class = "";

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
        $interests = parse_interests($_POST['interests'] ?? '', $error);
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

    // save
    if ($error === "") {
        $nn = function ($v) { return ($v === '' || $v === null) ? null : $v; };

        $r = db_run($conn,
            "UPDATE users SET first_name = ?, last_name = ?, middle_initial = ?, bio = ?, course = ?,
                year_level = ?, interests = ?, instagram = ?, facebook = ?, github = ?, linkedin = ?, website = ?,
                profile_visibility = ?, photo = ?
             WHERE username = ?",
            str_repeat("s", 15),
            array(
                $first, $nn($last), $mi, $nn($bio), $nn($course),
                $nn($year), $nn($interests),
                $linkValues['instagram'], $linkValues['facebook'], $linkValues['github'], $linkValues['linkedin'], $linkValues['website'],
                $vis, $photo,
                $me['username']
            ));

        if ($r['ok']) {
            // event reminder choice (only exists after event-reminders.sql was run)
            $rp = $_POST['reminder_pref'] ?? '';
            if (array_key_exists('reminder_pref', $me) && in_array($rp, array('going', 'all', 'off'), true)) {
                db_run($conn, "UPDATE users SET reminder_pref = ? WHERE username = ?", "ss", array($rp, $me['username']));
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
        $error = ($r['errno'] == 1054)
            ? "Profile fields aren't set up yet. Run profile.sql in phpMyAdmin first."
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
$hasRemind = array_key_exists('reminder_pref', $me);
$curRemind = isset($_POST['reminder_pref']) ? $_POST['reminder_pref'] : ($me['reminder_pref'] ?? 'going');
if (!in_array($curRemind, array('going', 'all', 'off'), true)) $curRemind = 'going';
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
            <h2>Photo</h2>
            <div class="photo-edit">
                <div class="p-avatar small" id="photo-preview"><?php echo avatar_inner($me); ?></div>
                <div>
                    <input type="file" name="photo" id="photo" accept="image/jpeg,image/png,image/webp">
                    <p class="club-meta">JPG, PNG or WebP, up to 2 MB. We'll crop it to a square.</p>
                    <?php if ($hasPhoto) { ?>
                        <label class="remember" style="margin:8px 0 0"><input type="checkbox" name="remove_photo" value="1"> Remove my current photo</label>
                    <?php } ?>
                </div>
            </div>
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
                <label for="chip-input">Interests <span class="opt">(up to 8)</span></label>
                <div class="chips" id="chips">
                    <input type="text" id="chip-input" placeholder="Type an interest and press Enter" maxlength="24" autocomplete="off">
                </div>
                <input type="hidden" name="interests" id="interests" value="<?php echo fv('interests', $me); ?>">
            </div>
        </section>

        <section class="panel">
            <h2>Links</h2>
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

        <?php if ($hasRemind) { ?>
        <section class="panel">
            <h2>Event reminders</h2>
            <p class="remind-lead">Camigo can nudge you before events so you don't miss them. Reminders show up in your notifications.</p>

            <label class="radio-card">
                <input type="radio" name="reminder_pref" value="going" <?php echo ($curRemind === 'going') ? 'checked' : ''; ?>>
                <span><strong>Events I'm going to</strong><small>A reminder the day before and about an hour before every event you've RSVP'd to.</small></span>
            </label>
            <label class="radio-card">
                <input type="radio" name="reminder_pref" value="all" <?php echo ($curRemind === 'all') ? 'checked' : ''; ?>>
                <span><strong>Going, plus my clubs' events</strong><small>Everything above, and a heads-up the day before club events you haven't responded to yet.</small></span>
            </label>
            <label class="radio-card">
                <input type="radio" name="reminder_pref" value="off" <?php echo ($curRemind === 'off') ? 'checked' : ''; ?>>
                <span><strong>Off</strong><small>No reminders. You'll still hear when an event is posted, changed or cancelled.</small></span>
            </label>
        </section>
        <?php } ?>

        <div class="form-actions">
            <button type="submit" name="save" class="btn inline">Save profile</button>
            <a href="profile" class="cancel">Cancel</a>
        </div>

    </form>

</main>

<script src="script.js"></script>
<script>
(function () {
    // ---- photo preview ----
    var file = document.getElementById("photo");
    var prev = document.getElementById("photo-preview");
    file.addEventListener("change", function () {
        var f = file.files[0];
        if (!f) return;
        if (f.size > 2 * 1024 * 1024) {
            alert("Please choose a photo under 2 MB.");
            file.value = "";
            return;
        }
        var img = document.createElement("img");
        img.src = URL.createObjectURL(f);
        img.alt = "";
        prev.innerHTML = "";
        prev.appendChild(img);
    });

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
    var MAX = 8;
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
        input.style.display = tags.length >= MAX ? "none" : "";
    }

    function add(raw) {
        var t = raw.replace(/,/g, " ").replace(/\s+/g, " ").trim();
        if (!t || tags.length >= MAX) return;
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