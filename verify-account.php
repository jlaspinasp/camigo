<?php
session_start();
require("db.php");
require("auth.php");
require("validate-lib.php");

$me = require_login($conn);

$ready = verify_ready($conn);

// two photos are required: both go in together
$slots = array(
    'proof_id'   => array('School ID (front)', 'A clear photo of the front of your school ID.'),
    'proof_load' => array('Study load', "This term's study load, registration form, or a screenshot of your student portal."),
);
$twoReady = $ready && verify_two_ready($conn);

$message = "";
$message_class = "";

// a file larger than the server's post limit arrives as an empty form
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    $message = "That upload was too large for the server. Try a smaller photo (under 10 MB).";
    $message_class = "error";
}

$flash = flash_get();
if ($flash) {
    $message = $flash[0];
    $message_class = $flash[1];
}

$latest     = $ready ? verify_latest($conn, $me['id']) : null;
$isAdmin    = (($me['role'] ?? 'user') === 'admin');
$isVerified = user_is_verified($me);
$isPending  = ($latest && $latest['status'] === 'pending');
$waitLeft   = verify_wait_left($latest);
$canSubmit  = ($ready && $twoReady && !$isVerified && !$isPending && $waitLeft === 0);

$f = array(
    'first_name' => (string)($me['first_name'] ?? ''),
    'last_name'  => (string)($me['last_name'] ?? ''),
    'id_number'  => '',
);

// ---- send a request ----
if ($canSubmit && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send'])) {

    foreach (array('first_name', 'last_name', 'id_number') as $k) {
        $f[$k] = trim(preg_replace('/\s+/', ' ', (string)($_POST[$k] ?? '')));
    }
    $f['first_name'] = proper_name($f['first_name']);
    $f['last_name']  = proper_name($f['last_name']);

    $error = "";

    if (!csrf_ok()) {
        $error = "Your session expired. Please try again.";
    } elseif ($f['first_name'] === '' || str_len($f['first_name']) > 100) {
        $error = "Enter your first name (up to 100 characters).";
    } elseif ($f['last_name'] === '' || str_len($f['last_name']) > 100) {
        $error = "Enter your last name (up to 100 characters).";
    } elseif (verify_id_error($f['id_number']) !== "") {
        $error = verify_id_error($f['id_number']);
    } elseif (empty($_POST['confirm'])) {
        $error = "Please confirm that the photos are yours and the details are correct.";
    }

    // both photos are checked the same way
    $ups = array();
    if ($error === "") {
        foreach ($slots as $key => $t) {
            $up = $_FILES[$key] ?? null;
            if (!$up || ($up['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                $error = "Add a photo of your " . strtolower($t[0]) . ".";
            } elseif ($up['error'] === UPLOAD_ERR_INI_SIZE || $up['error'] === UPLOAD_ERR_FORM_SIZE || $up['size'] > VERIFY_MAX_UPLOAD) {
                $error = "Your " . strtolower($t[0]) . " photo is too big. Please choose one under 10 MB.";
            } elseif ($up['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($up['tmp_name'])) {
                $error = "Your " . strtolower($t[0]) . " photo didn't upload. Please try again.";
            }
            if ($error !== "") break;
            $ups[$key] = $up;
        }
    }

    // one waiting request at a time (also covers a double click)
    if ($error === "") {
        $chk = db_run($conn, "SELECT COUNT(*) AS n FROM verifications WHERE user_id = ? AND status = 'pending'", "i", array($me['id']));
        if (!$chk['ok']) {
            $error = "Verification isn't set up yet. Please try again later.";
        } elseif ((int)$chk['rows'][0]['n'] > 0) {
            $error = "You already have a request waiting for review.";
        }
    }

    if ($error === "") {
        $fileId   = save_proof_image($ups['proof_id']['tmp_name'], $error);
        $fileLoad = $fileId ? save_proof_image($ups['proof_load']['tmp_name'], $error) : null;
        if ($fileId && $fileLoad) {
            $r = db_run($conn,
                "INSERT INTO verifications (user_id, first_name, last_name, id_number, id_hash, proof_type, proof, proof2, status)
                 VALUES (?, ?, ?, ?, ?, 'both', ?, ?, 'pending')",
                "issssss",
                array($me['id'], $f['first_name'], $f['last_name'], $f['id_number'], verify_hash_id($f['id_number']), $fileId, $fileLoad));

            if ($r['ok']) {
                // let the admins know
                db_run($conn,
                    "INSERT INTO notifications (user_id, type, message, link)
                     SELECT id, 'verify_request', ?, 'admin-verifications' FROM users WHERE role = 'admin'",
                    "s", array(str_cut(full_name($me) . ' asked to be verified.', 255)));

                flash_set("Request sent. An admin will review it, and you'll get a notification when it's decided.", "success");
                header("Location: verify-account");
                exit();
            }
            $error = "Something went wrong. Please try again.";
        }
        if ($error !== "") { delete_proof($fileId); delete_proof($fileLoad); }
    }

    $message = $error;
    $message_class = "error";
}

// icons
$svg = function ($inner, $w = 24) {
    return '<svg viewBox="0 0 24 24" width="' . $w . '" height="' . $w . '" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $inner . '</svg>';
};
$icShield = '<path d="M12 3l7.5 2.8v5.6c0 4.5-3 7.9-7.5 9.6-4.5-1.7-7.5-5.1-7.5-9.6V5.8L12 3z"/><path d="M8.6 12.2l2.5 2.5 4.4-4.9"/>';
$icCheck  = '<path d="M5 12.5l4.5 4.5L19 7.5"/>';
$icLock   = '<rect x="5" y="10.5" width="14" height="10" rx="2.5"/><path d="M8.5 10.5V8a3.5 3.5 0 017 0v2.5"/>';
$icUpload = '<path d="M12 16V5m0 0L7.5 9.5M12 5l4.5 4.5"/><path d="M4.5 15.5v2A2.5 2.5 0 007 20h10a2.5 2.5 0 002.5-2.5v-2"/>';
$icClock  = '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>';
$icCross  = '<path d="M6 6l12 12M18 6L6 18"/>';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Verify your account | Camigo</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:wght@600;700;800&family=Instrument+Sans:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="verify.css">
</head>
<body>

<?php include("topbar.php"); ?>

<main class="main narrow">

    <section class="vf-hero">
        <div class="vf-hero-top">
            <div class="vf-seal"><?php echo $svg($icShield, 30); ?></div>
            <div>
                <h1>Verify your account</h1>
                <p>Confirm you're a student here to create on Camigo. You can join clubs, projects and events without it.</p>
            </div>
        </div>
        <div class="vf-unlocks">
            <span class="vf-unlock"><?php echo $svg($icCheck, 15); ?>Create clubs</span>
            <span class="vf-unlock"><?php echo $svg($icCheck, 15); ?>Post events</span>
            <span class="vf-unlock"><?php echo $svg($icCheck, 15); ?>Start projects</span>
        </div>
    </section>

    <?php if ($message != "") { ?>
        <div class="msg <?php echo e($message_class); ?>" style="margin-top:20px"><?php echo e($message); ?></div>
    <?php } ?>

    <?php if (!$ready) { ?>

        <div class="msg error" style="margin-top:20px">Verification isn't set up yet. Run <strong>verification.sql</strong> in phpMyAdmin first.</div>

    <?php } elseif (!$twoReady && !$isAdmin && !$isVerified) { ?>

        <div class="msg error" style="margin-top:20px">Verification isn't fully set up yet. Run <strong>verification-two-proofs.sql</strong> in phpMyAdmin first.</div>

    <?php } elseif ($isAdmin) { ?>

        <section class="panel">
            <div class="vf-state">
                <div class="vf-state-ic good"><?php echo $svg($icCheck); ?></div>
                <div>
                    <h2>Admin accounts are verified</h2>
                    <p>You can create clubs, events and projects without sending anything.</p>
                    <div class="btn-row"><a href="admin-verifications" class="btn-sm btn-dark">Review requests</a></div>
                </div>
            </div>
        </section>

    <?php } elseif ($isVerified) { ?>

        <section class="panel">
            <div class="vf-state">
                <div class="vf-state-ic good"><?php echo $svg($icCheck); ?></div>
                <div>
                    <h2>You're verified</h2>
                    <p>Your Verified badge shows on your profile and student card. You can now create on Camigo.</p>
                    <div class="btn-row">
                        <a href="create-club" class="btn-sm btn-dark">Create a club</a>
                        <a href="create-event" class="btn-sm btn-ghost">Post an event</a>
                        <a href="create-project" class="btn-sm btn-ghost">Start a project</a>
                    </div>
                </div>
            </div>
        </section>

    <?php } elseif ($isPending) { ?>

        <section class="panel">
            <div class="vf-state">
                <div class="vf-state-ic wait"><?php echo $svg($icClock); ?></div>
                <div>
                    <h2>Your request is being reviewed</h2>
                    <p>An admin will check your photos against the details you sent. You'll get a notification as soon as it's decided.</p>
                    <dl class="vf-facts">
                        <dt>Sent</dt><dd><?php echo e(date('M j, Y', strtotime($latest['created_at']))); ?></dd>
                        <dt>Name</dt><dd><?php echo e(trim($latest['first_name'] . ' ' . $latest['last_name'])); ?></dd>
                        <?php if (!empty($latest['id_number'])) { ?><dt>ID number</dt><dd>ending in <?php echo e(verify_id_tail($latest['id_number'])); ?></dd><?php } ?>
                        <dt>Proof</dt><dd>School ID + Study load</dd>
                    </dl>
                    <div class="vf-proof-thumbs">
                        <?php if (proof_ok($latest['proof'] ?? '')) { ?>
                            <img class="vf-proof-thumb" src="verification-proof?id=<?php echo (int)$latest['id']; ?>" alt="The school ID you sent">
                        <?php } ?>
                        <?php if (proof_ok($latest['proof2'] ?? '')) { ?>
                            <img class="vf-proof-thumb" src="verification-proof?id=<?php echo (int)$latest['id']; ?>&amp;n=2" alt="The study load you sent">
                        <?php } ?>
                    </div>
                </div>
            </div>
        </section>

    <?php } else { ?>

        <?php if ($latest && $latest['status'] === 'rejected') { ?>
            <div class="msg error" style="margin-top:20px">
                <strong>Your last request wasn't approved.</strong>
                <?php if (!empty($latest['review_note'])) { ?> Reason: <?php echo e($latest['review_note']); ?><?php } ?>
                <?php if ($waitLeft > 0) { ?> You can send a new one in <?php echo e(verify_wait_text($waitLeft)); ?>.<?php } else { ?> You can send a new request below.<?php } ?>
            </div>
        <?php } elseif ($latest && $latest['status'] === 'revoked') { ?>
            <div class="msg error" style="margin-top:20px">
                <strong>Your verification was removed by an admin.</strong>
                <?php if (!empty($latest['review_note'])) { ?> Reason: <?php echo e($latest['review_note']); ?><?php } ?>
                You can send a new request below.
            </div>
        <?php } ?>

        <?php if ($waitLeft > 0) { ?>

            <section class="panel">
                <div class="vf-state">
                    <div class="vf-state-ic bad"><?php echo $svg($icClock); ?></div>
                    <div>
                        <h2>Please wait before sending another</h2>
                        <p>New requests open <?php echo e(verify_wait_text($waitLeft)); ?> after a rejection. Use the time to get clear photos of your ID and your study load.</p>
                    </div>
                </div>
            </section>

        <?php } else { ?>

            <ol class="vf-steps">
                <li><strong>Send your details</strong><span>Your name, ID number, and a photo of your school ID and study load.</span></li>
                <li><strong>An admin reviews it</strong><span>They check both photos against what you entered.</span></li>
                <li><strong>Your photos are deleted</strong><span>Once it's reviewed, only the result is kept.</span></li>
            </ol>

            <form method="POST" enctype="multipart/form-data" id="vf-form">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="send" value="1">

                <section class="panel vf-panel">
                    <h2>Your details</h2>
                    <p class="vf-lead">Enter them exactly as they appear on your proof. Your name should match your Camigo profile.</p>

                    <div class="vf-grid">
                        <div class="field">
                            <label for="first_name">First name</label>
                            <input type="text" name="first_name" id="first_name" maxlength="100" required autocomplete="given-name" value="<?php echo e($f['first_name']); ?>">
                        </div>
                        <div class="field">
                            <label for="last_name">Last name</label>
                            <input type="text" name="last_name" id="last_name" maxlength="100" required autocomplete="family-name" value="<?php echo e($f['last_name']); ?>">
                        </div>
                        <div class="field full">
                            <label for="id_number">Student ID number</label>
                            <input type="text" name="id_number" id="id_number" class="vf-idin" maxlength="30" required autocomplete="off" placeholder="e.g. 2021-00123" value="<?php echo e($f['id_number']); ?>">
                        </div>
                    </div>
                </section>

                <section class="panel vf-panel">
                    <h2>Your proofs</h2>
                    <p class="vf-lead">Add a photo of both. The school ID shows who you are, and your study load shows you're enrolled this term.</p>

                    <div class="vf-slots">
                        <?php foreach ($slots as $key => $t) { ?>
                            <div class="field vf-slot" data-slot>
                                <label class="vf-slot-label"><?php echo e($t[0]); ?></label>
                                <p class="vf-slot-help"><?php echo e($t[1]); ?></p>

                                <input type="file" name="<?php echo e($key); ?>" accept="image/jpeg,image/png,image/webp" hidden data-input>

                                <div class="vf-drop" data-drop role="button" tabindex="0" aria-label="Choose a photo of your <?php echo e(strtolower($t[0])); ?>">
                                    <div class="vf-drop-ic"><?php echo $svg($icUpload, 22); ?></div>
                                    <strong>Choose a photo or drop it here</strong>
                                    <small>JPG, PNG or WebP, up to 10 MB</small>
                                </div>

                                <div class="vf-file" data-chosen hidden>
                                    <img data-prev alt="Preview of your <?php echo e(strtolower($t[0])); ?>">
                                    <div class="vf-file-info"><strong data-name></strong><span data-size></span></div>
                                    <button type="button" class="btn-sm btn-ghost" data-remove>Change photo</button>
                                </div>

                                <div class="hint" data-err role="alert"></div>
                            </div>
                        <?php } ?>
                    </div>

                    <div class="vf-note">
                        <?php echo $svg($icLock, 18); ?>
                        <div><strong>Cover what we don't need.</strong> Hide your birthday and anything else except your name and ID number. Only Camigo admins can see these photos, and they're deleted as soon as it's reviewed.</div>
                    </div>

                    <label class="vf-confirm">
                        <input type="checkbox" name="confirm" value="1" required>
                        <span>These are my own ID and study load, and the details above are correct.</span>
                    </label>

                    <div class="vf-submit">
                        <button type="submit" class="btn inline" id="vf-go">Send for review</button>
                    </div>
                </section>
            </form>

            <script>
            (function () {
                var form = document.getElementById('vf-form');
                var go = document.getElementById('vf-go');
                var MAX = 10 * 1024 * 1024;
                var OK = ['image/jpeg', 'image/png', 'image/webp'];
                var slots = Array.prototype.slice.call(document.querySelectorAll('[data-slot]'));

                slots.forEach(function (slot) {
                    var input = slot.querySelector('[data-input]');
                    var drop = slot.querySelector('[data-drop]');
                    var chosen = slot.querySelector('[data-chosen]');
                    var prev = slot.querySelector('[data-prev]');
                    var nameEl = slot.querySelector('[data-name]');
                    var sizeEl = slot.querySelector('[data-size]');
                    var err = slot.querySelector('[data-err]');
                    var url = null;

                    slot.say = function (text) { err.textContent = text; err.className = text ? 'hint bad' : 'hint'; };
                    slot.drop = drop;
                    slot.input = input;

                    function clearFile() {
                        input.value = '';
                        if (url) { URL.revokeObjectURL(url); url = null; }
                        chosen.hidden = true;
                        drop.hidden = false;
                    }

                    function showFile(f) {
                        slot.say('');
                        if (OK.indexOf(f.type) === -1) { clearFile(); slot.say('Please choose a JPG, PNG or WebP photo.'); return; }
                        if (f.size > MAX) { clearFile(); slot.say('That photo is over 10 MB. Please choose a smaller one.'); return; }
                        if (url) URL.revokeObjectURL(url);
                        url = URL.createObjectURL(f);
                        prev.src = url;
                        nameEl.textContent = f.name;
                        sizeEl.textContent = (f.size / 1048576).toFixed(1) + ' MB';
                        drop.hidden = true;
                        chosen.hidden = false;
                    }

                    drop.addEventListener('click', function () { input.click(); });
                    drop.addEventListener('keydown', function (e) {
                        if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); input.click(); }
                    });
                    ['dragenter', 'dragover'].forEach(function (n) {
                        drop.addEventListener(n, function (e) { e.preventDefault(); drop.classList.add('over'); });
                    });
                    ['dragleave', 'drop'].forEach(function (n) {
                        drop.addEventListener(n, function (e) { e.preventDefault(); drop.classList.remove('over'); });
                    });
                    drop.addEventListener('drop', function (e) {
                        var files = e.dataTransfer && e.dataTransfer.files;
                        if (!files || !files.length) return;
                        try { input.files = files; } catch (x) { return; }
                        showFile(files[0]);
                    });
                    input.addEventListener('change', function () {
                        if (input.files && input.files[0]) showFile(input.files[0]);
                    });
                    slot.querySelector('[data-remove]').addEventListener('click', function () { clearFile(); input.click(); });
                });

                form.addEventListener('submit', function (e) {
                    var missing = null;
                    slots.forEach(function (slot) {
                        if (!slot.input.files || !slot.input.files[0]) {
                            slot.say('Add a photo for this one.');
                            if (!missing) missing = slot;
                        }
                    });
                    if (missing) {
                        e.preventDefault();
                        missing.drop.focus();
                        return;
                    }
                    go.disabled = true;
                    go.textContent = 'Sending…';
                });
            })();
            </script>

        <?php } ?>

    <?php } ?>

</main>

</body>
</html>