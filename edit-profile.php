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
            if ($up['error'] === UPLOAD_ERR_INI_SIZE || $up['error'] === UPLOAD_ERR_FORM_SIZE || $up['size'] > 10 * 1024 * 1024) {
                $error = "Your photo is too big. Please choose one under 10 MB.";
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
            if ($up['error'] === UPLOAD_ERR_INI_SIZE || $up['error'] === UPLOAD_ERR_FORM_SIZE || $up['size'] > 10 * 1024 * 1024) {
                $error = "Your cover photo is too big. Please choose one under 10 MB.";
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
    <link rel="stylesheet" href="verify.css">
<style>
/* ---- image framing editor (drag to move, pinch / scroll / slider to zoom) ---- */
.crop-modal { position: fixed; inset: 0; z-index: 1000; display: flex; align-items: center; justify-content: center; padding: 16px; background: rgba(12, 15, 58, 0.62); -webkit-backdrop-filter: blur(3px); backdrop-filter: blur(3px); }
.crop-modal[hidden] { display: none; }
.crop-card { width: 100%; max-width: 760px; max-height: 100%; overflow: auto; padding: 20px; background: var(--white); border-radius: 18px; box-shadow: 0 24px 60px rgba(12, 15, 58, 0.35); }
.crop-card h3 { margin: 0; font-family: var(--display); font-size: 18px; }
.crop-card .crop-help { margin: 4px 0 0; font-size: 13px; color: var(--muted); }
.crop-stage { position: relative; width: 100%; margin: 14px auto 0; overflow: hidden; background: #14163a; border-radius: 12px; cursor: grab; touch-action: none; user-select: none; -webkit-user-select: none; aspect-ratio: 10 / 3; outline-offset: 3px; }
.crop-stage.round { width: min(320px, 100%); aspect-ratio: 1 / 1; }
.crop-stage.dragging { cursor: grabbing; }
.crop-stage:focus { outline: none; }
.crop-stage:focus-visible { outline: 2px solid var(--indigo); }
.crop-stage img { position: absolute; left: 0; top: 0; max-width: none; transform-origin: 0 0; will-change: transform; pointer-events: none; -webkit-user-drag: none; }
/* round mode: dim everything outside the circle the photo will be shown in */
.crop-stage.round::after { content: ""; position: absolute; inset: 0; border-radius: 50%; box-shadow: 0 0 0 999px rgba(12, 15, 58, 0.55); border: 2px solid rgba(255, 255, 255, 0.85); pointer-events: none; }
/* wide mode: faint thirds grid while moving */
.crop-stage:not(.round)::after { content: ""; position: absolute; inset: 0; pointer-events: none; opacity: 0; transition: opacity 0.15s;
    background: linear-gradient(90deg, transparent calc(33.33% - 0.5px), rgba(255,255,255,.55) 0 calc(33.33% + 0.5px), transparent 0 calc(66.66% - 0.5px), rgba(255,255,255,.55) 0 calc(66.66% + 0.5px), transparent 0),
                linear-gradient(180deg, transparent calc(33.33% - 0.5px), rgba(255,255,255,.55) 0 calc(33.33% + 0.5px), transparent 0 calc(66.66% - 0.5px), rgba(255,255,255,.55) 0 calc(66.66% + 0.5px), transparent 0); }
.crop-stage.dragging:not(.round)::after { opacity: 1; }
.crop-zoom { display: flex; align-items: center; gap: 12px; margin: 16px auto 0; width: min(420px, 100%); color: var(--muted); }
.crop-zoom svg { flex: none; width: 18px; height: 18px; }
.crop-zoom input[type=range] { flex: 1; min-width: 0; accent-color: var(--indigo); height: 28px; margin: 0; padding: 0; border: 0; background: none; box-shadow: none; }
.crop-actions { display: flex; justify-content: flex-end; gap: 10px; margin-top: 18px; }
.crop-actions .btn { margin: 0; }
</style>
</head>
<body>

<?php include("topbar.php"); ?>

<main class="main narrow">

    <h1 class="page-title">Edit profile</h1>
    <p class="page-sub">Tell people who you are. You decide what others can see.</p>

    <?php if ($message != "") { ?>
        <div class="msg <?php echo e($message_class); ?>" style="margin-top:20px"><?php echo e($message); ?></div>
    <?php } ?>

    <?php if (verify_ready($conn)) {
        $vLatest = verify_latest($conn, $me['id']);
        $vWait   = verify_wait_left($vLatest);
        $vBtn    = 'Verify account';
        if (user_is_verified($me)) {
            $vCls = 'good'; $vTitle = 'Verified student'; $vText = 'You can create clubs, events and projects.'; $vBtn = 'View status';
        } elseif ($vLatest && $vLatest['status'] === 'pending') {
            $vCls = 'wait'; $vTitle = 'Verification under review'; $vText = "An admin is checking your proof. You'll get a notification when it's decided."; $vBtn = 'View request';
        } elseif ($vLatest && $vLatest['status'] === 'rejected') {
            $vCls = 'bad'; $vTitle = 'Verification not approved';
            $vText = ($vWait > 0) ? 'You can send a new request in ' . verify_wait_text($vWait) . '.' : 'You can send a new request now.';
            $vBtn = ($vWait > 0) ? 'See details' : 'Try again';
        } else {
            $vCls = ''; $vTitle = 'Verify your account'; $vText = 'Verify to create clubs, events and projects. You can join without it.';
        }
    ?>
    <div class="vc-card">
        <div class="vc-ic <?php echo e($vCls); ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3l7.5 2.8v5.6c0 4.5-3 7.9-7.5 9.6-4.5-1.7-7.5-5.1-7.5-9.6V5.8L12 3z"/><path d="M8.6 12.2l2.5 2.5 4.4-4.9"/></svg></div>
        <div class="vc-text"><strong><?php echo e($vTitle); ?></strong><span><?php echo e($vText); ?></span></div>
        <a href="verify-account" class="btn-sm <?php echo ($vCls === 'good' || $vCls === 'wait') ? 'btn-ghost' : 'btn-dark'; ?>"><?php echo e($vBtn); ?></a>
    </div>
    <?php } ?>

    <form method="POST" enctype="multipart/form-data">
        <?php echo csrf_field(); ?>

        <section class="panel">
            <h2>Photo and cover</h2>
            <p class="club-meta">Click an image to upload it, then drag and zoom to frame it. Use the &times; to remove it.</p>

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
            <p class="hint" id="id-msg">JPG, PNG or WebP, up to 10 MB. You'll get to frame each image after you choose it.</p>
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
                        <label for="course">Course <span class="opt">(optional)</span></label>
                        <input type="text" name="course" id="course" maxlength="100" placeholder="e.g. BS Computer Science" value="<?php echo fv('course', $me); ?>">
                    </div>
                    <div>
                        <label for="year_level">Year level <span class="opt">(optional)</span></label>
                        <select name="year_level" id="year_level">
                            <option value="">Not set</option>
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
                <label for="bio">Bio <span class="opt">(optional)</span></label>
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
            <p class="club-meta">Optional. Tap what fits and fill it in.</p>
            <?php $curVals = isset($_POST['currently']) && is_array($_POST['currently']) ? $_POST['currently'] : array();
                  if (!$curVals) { foreach (currently_list($me['currently_into']) as $c) { $curVals[$c[0]] = $c[1]; } } ?>
            <div class="ar" data-ar>
                <div class="ar-rows now-grid">
                    <?php foreach (currently_categories() as $i => $cat) { $v = isset($curVals[$cat]) && is_string($curVals[$cat]) ? $curVals[$cat] : ''; ?>
                        <div class="ar-row" data-label="<?php echo e($cat); ?>"<?php echo trim($v) === '' ? ' hidden' : ''; ?>>
                            <label for="now<?php echo $i; ?>"><?php echo e($cat); ?></label>
                            <div class="ar-in">
                                <input type="text" name="currently[<?php echo e($cat); ?>]" id="now<?php echo $i; ?>" maxlength="60" value="<?php echo e($v); ?>">
                                <button type="button" class="ar-x" aria-label="Remove <?php echo e($cat); ?>">&times;</button>
                            </div>
                        </div>
                    <?php } ?>
                </div>
                <div class="ar-add" aria-label="Add more"></div>
            </div>
        </section>
        <?php } ?>

        <section class="panel">
            <h2>Links</h2>
            <p class="club-meta">Optional. Tap a network to add your link.</p>
            <?php if (!$moreLinks || !$hasCurrently) { ?><p class="hint">More links and "Currently into" need a one-time upgrade. Run <strong>profile-more.sql</strong> in phpMyAdmin to turn them on.</p><?php } ?>
            <div class="ar" data-ar>
                <div class="ar-rows now-grid">
                    <?php foreach ($networks as $key => $net) { $raw = isset($_POST[$key]) ? (string)$_POST[$key] : (string)($me[$key] ?? ''); ?>
                        <div class="ar-row" data-label="<?php echo e($net[0]); ?>"<?php echo trim($raw) === '' ? ' hidden' : ''; ?>>
                            <label for="<?php echo e($key); ?>"><?php echo e($net[0]); ?></label>
                            <div class="ar-in">
                                <input type="text" name="<?php echo e($key); ?>" id="<?php echo e($key); ?>" maxlength="200" placeholder="<?php echo e($net[2]); ?>" value="<?php echo e($raw); ?>">
                                <button type="button" class="ar-x" aria-label="Remove <?php echo e($net[0]); ?>">&times;</button>
                            </div>
                        </div>
                    <?php } ?>
                </div>
                <div class="ar-add" aria-label="Add more"></div>
            </div>
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


<div class="crop-modal" id="crop" hidden role="dialog" aria-modal="true" aria-labelledby="crop-title">
    <div class="crop-card">
        <h3 id="crop-title">Frame your photo</h3>
        <p class="crop-help">Drag to move. Pinch, scroll or use the slider to zoom.</p>
        <div class="crop-stage" id="crop-stage" tabindex="0" aria-label="Image frame. Use arrow keys to move and plus or minus to zoom.">
            <img id="crop-img" alt="" draggable="false">
        </div>
        <div class="crop-zoom">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M8 12h8"/></svg>
            <input type="range" id="crop-zoom" min="0" max="1000" step="1" value="0" aria-label="Zoom">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M8 12h8M12 8v8"/></svg>
        </div>
        <div class="crop-actions">
            <button type="button" class="btn btn-ghost inline" id="crop-cancel">Cancel</button>
            <button type="button" class="btn inline" id="crop-ok">Apply</button>
        </div>
    </div>
</div>

<script src="script.js"></script>
<script>
(function () {
    // ---- photo + cover pickers: click (or drop) to choose, then drag / pinch / scroll to frame it ----
    var IMG_MAX = 10 * 1024 * 1024, TYPES = /^image\/(jpeg|png|webp)$/;
    var msg = document.getElementById("id-msg"), msgText = msg.textContent;
    function say(t) { msg.textContent = t; msg.classList.add("bad"); }
    function calm() { msg.textContent = msgText; msg.classList.remove("bad"); }

    // ---------- the framing editor (shared by both pickers) ----------
    var ed = document.getElementById("crop"), stage = document.getElementById("crop-stage"),
        eimg = document.getElementById("crop-img"), zoomEl = document.getElementById("crop-zoom"),
        etitle = document.getElementById("crop-title"),
        MAXZ = 6;                      // zoom goes from "fits the frame" up to 6x that
    var st = null;                     // the image being framed right now

    function clampPos() {
        st.x = Math.min(0, Math.max(st.sw - st.nw * st.s, st.x));
        st.y = Math.min(0, Math.max(st.sh - st.nh * st.s, st.y));
    }
    function paint() {
        eimg.style.transform = "translate(" + st.x + "px," + st.y + "px) scale(" + st.s + ")";
        zoomEl.value = Math.round((st.s / st.min - 1) / (MAXZ - 1) * 1000);
    }
    // change the zoom, keeping the point under (cx, cy) where it is
    function zoomAt(ns, cx, cy) {
        ns = Math.min(st.min * MAXZ, Math.max(st.min, ns));
        var px = (cx - st.x) / st.s, py = (cy - st.y) / st.s;
        st.s = ns; st.x = cx - px * ns; st.y = cy - py * ns;
        clampPos(); paint();
    }
    function measure() {
        var r = stage.getBoundingClientRect();
        st.sw = r.width; st.sh = r.height;
        st.min = Math.max(st.sw / st.nw, st.sh / st.nh);
    }

    var onKey = null;
    function closeEditor() {
        ed.hidden = true; document.body.style.overflow = "";
        if (st) { URL.revokeObjectURL(st.url); eimg.removeAttribute("src"); }
        st = null;
        if (onKey) { document.removeEventListener("keydown", onKey); onKey = null; }
        window.removeEventListener("resize", onResize);
    }
    function onResize() {
        if (!st) return;
        var ratio = st.s / st.min, cx = (st.sw / 2 - st.x) / st.s, cy = (st.sh / 2 - st.y) / st.s;
        measure();
        st.s = st.min * ratio;
        st.x = st.sw / 2 - cx * st.s; st.y = st.sh / 2 - cy * st.s;
        clampPos(); paint();
    }

    // frame(file, {round, title, name}, done): done(file) on Apply, done(null) on Cancel or if the image can't be read
    function frame(file, o, done) {
        var url = URL.createObjectURL(file), im = new Image();
        im.onerror = function () {
            URL.revokeObjectURL(url);
            say("We couldn't read that image. Please try a JPG, PNG or WebP.");
            done(null);
        };
        im.onload = function () {
            st = { url: url, img: im, nw: im.naturalWidth, nh: im.naturalHeight, o: o, done: done, x: 0, y: 0, s: 1, sw: 0, sh: 0, min: 1 };
            etitle.textContent = o.title;
            stage.className = "crop-stage" + (o.round ? " round" : "");
            eimg.style.width = st.nw + "px"; eimg.style.height = st.nh + "px";
            eimg.src = url;
            ed.hidden = false; document.body.style.overflow = "hidden";
            measure();
            st.s = st.min;
            st.x = (st.sw - st.nw * st.s) / 2; st.y = (st.sh - st.nh * st.s) / 2;   // start centred, like before
            clampPos(); paint();
            stage.focus({ preventScroll: true });
            window.addEventListener("resize", onResize);
            onKey = function (e) { if (e.key === "Escape") cancel(); };
            document.addEventListener("keydown", onKey);
        };
        im.src = url;
    }

    function cancel() { var d = st && st.done; closeEditor(); if (d) d(null); }

    function apply() {
        if (!st) return;
        var s = st, o = s.o;
        var w = s.sw / s.s, h = s.sh / s.s;
        var sx = Math.min(Math.max(0, -s.x / s.s), s.nw - w), sy = Math.min(Math.max(0, -s.y / s.s), s.nh - h);
        var ow, oh;
        if (o.round) { ow = oh = Math.max(1, Math.min(800, Math.round(w))); }                 // never scaled up, only down
        else         { ow = Math.max(10, Math.min(2400, Math.floor(w / 10) * 10)); oh = ow * 3 / 10; }   // exactly 10:3
        var c = document.createElement("canvas"); c.width = ow; c.height = oh;
        var ctx = c.getContext("2d");
        ctx.fillStyle = "#fff"; ctx.fillRect(0, 0, ow, oh);     // PNG transparency -> white, same as the server does
        ctx.imageSmoothingEnabled = true; ctx.imageSmoothingQuality = "high";
        ctx.drawImage(s.img, sx, sy, w, h, 0, 0, ow, oh);
        var d = s.done;
        c.toBlob(function (blob) {
            closeEditor();
            if (!blob) { say("We couldn't prepare that image. Please try again."); d(null); return; }
            d(new File([blob], o.name, { type: "image/jpeg" }));
        }, "image/jpeg", 0.92);
    }

    document.getElementById("crop-ok").addEventListener("click", apply);
    document.getElementById("crop-cancel").addEventListener("click", cancel);
    zoomEl.addEventListener("input", function () {
        if (st) zoomAt(st.min * (1 + (zoomEl.value / 1000) * (MAXZ - 1)), st.sw / 2, st.sh / 2);
    });
    stage.addEventListener("wheel", function (e) {            // mouse wheel / trackpad pinch
        if (!st) return;
        e.preventDefault();
        var r = stage.getBoundingClientRect();
        zoomAt(st.s * Math.exp(-e.deltaY * (e.ctrlKey ? 0.01 : 0.0015)), e.clientX - r.left, e.clientY - r.top);
    }, { passive: false });
    stage.addEventListener("keydown", function (e) {
        if (!st) return;
        var step = 20, k = e.key, used = true;
        if (k === "ArrowLeft") st.x += step; else if (k === "ArrowRight") st.x -= step;
        else if (k === "ArrowUp") st.y += step; else if (k === "ArrowDown") st.y -= step;
        else if (k === "+" || k === "=") { zoomAt(st.s * 1.1, st.sw / 2, st.sh / 2); return e.preventDefault(); }
        else if (k === "-" || k === "_") { zoomAt(st.s / 1.1, st.sw / 2, st.sh / 2); return e.preventDefault(); }
        else used = false;
        if (used) { e.preventDefault(); clampPos(); paint(); }
    });

    // one finger / mouse = move, two fingers = pinch to zoom (and move)
    var ptrs = {};
    stage.addEventListener("pointerdown", function (e) {
        if (!st) return;
        try { stage.setPointerCapture(e.pointerId); } catch (err) {}
        ptrs[e.pointerId] = { x: e.clientX, y: e.clientY };
        stage.classList.add("dragging");
    });
    stage.addEventListener("pointermove", function (e) {
        var p = ptrs[e.pointerId];
        if (!st || !p) return;
        var ids = Object.keys(ptrs);
        if (ids.length === 1) {
            st.x += e.clientX - p.x; st.y += e.clientY - p.y;
            p.x = e.clientX; p.y = e.clientY;
            clampPos(); paint();
        } else {
            var a = ptrs[ids[0]], b = ptrs[ids[1]];
            var d0 = Math.hypot(a.x - b.x, a.y - b.y), m0x = (a.x + b.x) / 2, m0y = (a.y + b.y) / 2;
            p.x = e.clientX; p.y = e.clientY;
            var d1 = Math.hypot(a.x - b.x, a.y - b.y), m1x = (a.x + b.x) / 2, m1y = (a.y + b.y) / 2;
            var r = stage.getBoundingClientRect();
            if (d0 > 0) zoomAt(st.s * (d1 / d0), m0x - r.left, m0y - r.top);
            st.x += m1x - m0x; st.y += m1y - m0y;
            clampPos(); paint();
        }
    });
    function lift(e) {
        delete ptrs[e.pointerId];
        if (!Object.keys(ptrs).length) stage.classList.remove("dragging");
    }
    stage.addEventListener("pointerup", lift);
    stage.addEventListener("pointercancel", lift);

    // ---------- pickers ----------
    function picker(o) {
        var input = document.getElementById(o.input), area = document.getElementById(o.area), rf = document.getElementById(o.removeField);
        var good = null;     // the last framed image that was accepted, so Cancel can put it back

        function setFiles(f) { var dt = new DataTransfer(); dt.items.add(f); input.files = dt.files; }
        function restore() {
            try { if (good) { setFiles(good); } else { input.value = ""; } } catch (err) { input.value = ""; }
        }

        function accept(f) {
            if (!f) return false;
            if (!TYPES.test(f.type)) { say("Please choose a JPG, PNG or WebP image."); return false; }
            if (f.size > IMG_MAX) { say("That image is over 10 MB. Please choose a smaller one."); return false; }
            calm();
            frame(f, { round: o.round, title: o.title, name: o.fileName }, function (framed) {
                if (!framed) { restore(); return; }          // cancelled: keep whatever was there before
                try {
                    setFiles(framed); good = framed;
                    rf.value = "0"; o.show(URL.createObjectURL(framed));
                } catch (err) {
                    // very old browser: can't swap the file, so the original goes up and the server centres it
                    good = f; rf.value = "0"; o.show(URL.createObjectURL(f));
                    say("Your browser can't save the framing, so this image will be centred instead.");
                }
            });
            return true;
        }
        input.addEventListener("change", function () {
            var f = input.files && input.files[0];
            if (f && !accept(f)) restore();
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
            if (!accept(f)) restore();
        });
        document.getElementById(o.remove).addEventListener("click", function (e) {
            e.stopPropagation(); input.value = ""; good = null; rf.value = "1"; calm(); o.reset();
        });
    }

    var avatar = document.getElementById("photo-preview"), avatarX = document.getElementById("photo-remove");
    picker({
        input: "photo", area: "photo-preview", removeField: "remove-photo", remove: "photo-remove",
        round: true, title: "Frame your photo", fileName: "photo.jpg",
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
            round: false, title: "Frame your cover photo", fileName: "cover.jpg",
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
// ---- "add what fits" lists (Currently into, Links): only filled rows show, the rest are + pills ----
(function () {
    document.querySelectorAll("[data-ar]").forEach(function (box) {
        var rows = Array.prototype.slice.call(box.querySelectorAll(".ar-row")), add = box.querySelector(".ar-add");
        function draw() {
            add.innerHTML = "";
            rows.forEach(function (r) {
                if (!r.hidden) return;
                var b = document.createElement("button");
                b.type = "button"; b.className = "ar-pill";
                b.textContent = "+ " + r.getAttribute("data-label");
                b.addEventListener("click", function () { r.hidden = false; draw(); r.querySelector("input").focus(); });
                add.appendChild(b);
            });
            add.hidden = !add.children.length;
        }
        rows.forEach(function (r) {
            r.querySelector(".ar-x").addEventListener("click", function () { r.querySelector("input").value = ""; r.hidden = true; draw(); });
        });
        draw();
    });
})();
</script>
</body>
</html>