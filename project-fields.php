<?php
// Form fields shared by create-project.php and edit-project.php.
// Expects: $f (current values: title, tagline, category, description, skills, link, is_open, club_id),
//          $fRoles (array of role rows: id, title, spots), $clubChoices (array of id => name the person may attach).
$cats   = project_categories();

// show the roles already filled in, plus one empty row (at least two rows in total)
$rows = $fRoles;
$rows[] = array('id' => 0, 'title' => '', 'spots' => 1);
while (count($rows) < 2) $rows[] = array('id' => 0, 'title' => '', 'spots' => 1);
$rows = array_slice($rows, 0, PROJECT_MAX_ROLES);

// Banner picker (optional): $bannerReady (projects-banner.sql has been run), $bannerCur (uploaded banner file name, or ''),
// $bannerTone (0-2, the colour the project uses without a banner).
$bannerReady = !empty($bannerReady);
$bannerCur   = (!empty($bannerCur) && club_image_ok($bannerCur)) ? $bannerCur : '';
$bannerTone  = (int)($bannerTone ?? 0) % 3;
// Logo picker (optional): $logoReady (projects-logo.sql has been run), $logoCur (uploaded logo file name, or '').
$logoReady   = !empty($logoReady);
$logoCur     = (!empty($logoCur) && club_image_ok($logoCur)) ? $logoCur : '';
$logoLetter  = trim((string)($f['title'] ?? '')) !== '' ? first_letter($f['title']) : 'P';
?>
<section class="pj-form-block">
    <div class="pj-block-head"><span class="pj-step">1</span><div><h2>The idea</h2><p>What are you building, and why would someone want to join?</p></div></div>

    <?php if ($bannerReady || $logoReady) { ?>
    <div class="field ev-banner-field">
        <label><?php echo ($bannerReady && $logoReady) ? 'Banner and logo' : ($logoReady ? 'Logo' : 'Banner'); ?> <span class="opt">(optional)</span></label>
        <p class="club-meta" style="margin:0 0 10px">Click an image to upload it, then drag and zoom to frame it. Use the &times; to remove it.</p>
        <div class="<?php echo $logoReady ? 'id-preview' . ($bannerReady ? '' : ' no-cover') : 'ev-banner-up'; ?>">
            <?php if ($bannerReady) { ?>
            <div class="cover id-cover pj-banner-prev t<?php echo $bannerTone; ?><?php echo $bannerCur !== '' ? ' has-img' : ''; ?>" id="banner-preview" role="button" tabindex="0" aria-label="Change banner image"
                 <?php if ($bannerCur !== '') { ?>style="background-image:url('uploads/projects/<?php echo e($bannerCur); ?>')"<?php } ?>>
                <div class="id-cta"><svg viewBox="0 0 24 24" width="26" height="26" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2"/><circle cx="9" cy="10" r="1.6"/><path d="m21 16-5-5-9 9"/></svg><strong>Click to add a banner</strong><span>Without one, your project gets a color.</span></div>
                <button type="button" class="id-x" id="banner-remove" aria-label="Remove banner image"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg></button>
            </div>
            <?php } ?>
            <?php if ($logoReady) { ?>
            <div class="id-logo-row">
                <div class="id-logo-wrap">
                    <div class="club-tile big t<?php echo $bannerTone; ?><?php echo $logoCur !== '' ? ' has-logo' : ''; ?>" id="logo-preview" role="button" tabindex="0" aria-label="Change project logo" data-letter="<?php echo e($logoLetter); ?>">
                        <?php if ($logoCur !== '') { ?><img src="uploads/projects/<?php echo e($logoCur); ?>" alt=""><?php } else { echo e($logoLetter); } ?>
                    </div>
                    <button type="button" class="id-x" id="logo-remove" aria-label="Remove project logo" <?php echo $logoCur !== '' ? '' : 'hidden'; ?>><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg></button>
                </div>
                <div class="id-logo-text"><strong>Project logo</strong><span>Click the logo to upload one. It's the icon shown on your dashboard and project cards.</span></div>
            </div>
            <?php } ?>
        </div>
        <?php if ($bannerReady) { ?>
        <input type="file" name="banner" id="banner" accept="image/jpeg,image/png,image/webp" hidden>
        <input type="hidden" name="remove_banner" id="remove-banner" value="0">
        <?php } ?>
        <?php if ($logoReady) { ?>
        <input type="file" name="logo" id="logo" accept="image/jpeg,image/png,image/webp" hidden>
        <input type="hidden" name="remove_logo" id="remove-logo" value="0">
        <?php } ?>
        <p class="hint" id="banner-msg">JPG, PNG or WebP, up to 10 MB. You'll get to frame each image after you choose it.<?php echo $logoReady ? ' Without a logo, your project keeps its category icon.' : ''; ?></p>
    </div>
    <?php } ?>
    <?php if (!$bannerReady || !$logoReady) { ?>
    <div class="msg error" style="margin-bottom:18px">Project <?php echo (!$bannerReady && !$logoReady) ? 'banners and logos need' : (!$bannerReady ? 'banners need' : 'logos need'); ?> a one-time upgrade. Run <strong><?php echo (!$bannerReady && !$logoReady) ? 'projects-banner.sql and projects-logo.sql' : (!$bannerReady ? 'projects-banner.sql' : 'projects-logo.sql'); ?></strong> in phpMyAdmin to unlock <?php echo (!$bannerReady && !$logoReady) ? 'them' : 'it'; ?>.</div>
    <?php } ?>

    <div class="field">
        <label for="title">Project name</label>
        <input type="text" name="title" id="title" maxlength="80" placeholder="e.g. CampusRide, a carpool app for our campus" value="<?php echo e($f['title']); ?>" required>
    </div>

    <div class="field">
        <label for="tagline">One-line pitch</label>
        <input type="text" name="tagline" id="tagline" maxlength="120" placeholder="Students share rides to campus and split the fuel cost." value="<?php echo e($f['tagline']); ?>" required>
        <div class="counter"><span id="tagCount">0</span> / 120</div>
    </div>

    <div class="field">
        <label for="category">Category</label>
        <select name="category" id="category" required>
            <option value="">Choose one</option>
            <?php foreach ($cats as $c => $ic) { ?>
                <option value="<?php echo e($c); ?>" <?php echo ($f['category'] === $c) ? 'selected' : ''; ?>><?php echo e($c); ?></option>
            <?php } ?>
        </select>
    </div>

    <div class="field">
        <label for="description">About the project</label>
        <textarea name="description" id="description" maxlength="2000" placeholder="The problem you want to solve, your plan, and what a teammate would actually do. Be specific, it is the best way to attract the right people." required><?php echo e($f['description']); ?></textarea>
        <div class="counter"><span id="descCount">0</span> / 2000</div>
    </div>
</section>

<section class="pj-form-block">
    <div class="pj-block-head"><span class="pj-step">2</span><div><h2>Who you need</h2><p>List the roles you are looking for. People apply for a specific role.</p></div></div>

    <div class="pj-roles" id="pjRoles">
        <?php foreach ($rows as $r) { ?>
            <div class="pj-role-row">
                <input type="hidden" name="role_id[]" value="<?php echo (int)$r['id']; ?>">
                <input type="text" name="role_title[]" maxlength="40" placeholder="e.g. UI/UX designer" value="<?php echo e($r['title']); ?>" aria-label="Role">
                <label class="pj-spots">
                    <span>Spots</span>
                    <select name="role_spots[]" aria-label="Spots">
                        <?php for ($n = 1; $n <= PROJECT_MAX_SPOTS; $n++) { ?>
                            <option value="<?php echo $n; ?>" <?php echo ((int)$r['spots'] === $n) ? 'selected' : ''; ?>><?php echo $n; ?></option>
                        <?php } ?>
                    </select>
                </label>
                <button type="button" class="pj-role-x" aria-label="Remove this role">&times;</button>
            </div>
        <?php } ?>
    </div>
    <button type="button" class="btn-sm btn-ghost pj-add-role" id="pjAddRole"><?php echo event_icon('plus'); ?>Add another role</button>
    <p class="hint-line">Up to <?php echo (int)PROJECT_MAX_ROLES; ?> roles. Leave this empty if you are open to anyone who is excited about the idea.</p>

    <div class="field" style="margin-top:20px">
        <label for="chip-input">Skills &amp; tools <span class="opt">(up to 8)</span></label>
        <div class="chips" id="chips">
            <input type="text" id="chip-input" placeholder="Type a skill and press Enter, e.g. React, Figma, Marketing" maxlength="24" autocomplete="off">
        </div>
        <input type="hidden" name="skills" id="skills" value="<?php echo e($f['skills']); ?>">
    </div>

    <div class="field">
        <label for="link">Link <span class="opt">(optional)</span></label>
        <input type="text" name="link" id="link" maxlength="200" placeholder="A prototype, repo, pitch deck or Figma file" value="<?php echo e($f['link']); ?>">
    </div>
</section>

<section class="pj-form-block">
    <div class="pj-block-head"><span class="pj-step">3</span><div><h2>Recruiting</h2><p>Decide whether people can apply right now.</p></div></div>

    <label class="pj-switch">
        <input type="checkbox" name="is_open" value="1"<?php echo !empty($f['is_open']) ? ' checked' : ''; ?>>
        <span class="pj-switch-ui" aria-hidden="true"></span>
        <span class="pj-switch-txt"><strong>Open to teammates</strong><em>Students can apply and you get a notification. Turn it off any time.</em></span>
    </label>

    <?php if (!empty($clubChoices)) { ?>
        <div class="field" style="margin-top:18px">
            <label for="club_id">Part of a club <span class="opt">(optional)</span></label>
            <select name="club_id" id="club_id">
                <option value="0">Not tied to a club</option>
                <?php foreach ($clubChoices as $cid => $cname) { ?>
                    <option value="<?php echo (int)$cid; ?>" <?php echo ((int)$f['club_id'] === (int)$cid) ? 'selected' : ''; ?>><?php echo e($cname); ?></option>
                <?php } ?>
            </select>
            <p class="hint-line" style="margin-top:6px">Tagged projects show the club's name, and its members get a notification when you post.</p>
        </div>
    <?php } ?>
</section>

<script>
(function () {
    // ---- character counters ----
    function counter(inputId, outId) {
        var t = document.getElementById(inputId), c = document.getElementById(outId);
        if (!t || !c) return;
        function up() { c.textContent = t.value.length; }
        t.addEventListener("input", up); up();
    }
    counter("description", "descCount");
    counter("tagline", "tagCount");

    // ---- roles: add / remove rows ----
    var wrap = document.getElementById("pjRoles");
    var addBtn = document.getElementById("pjAddRole");
    var MAXR = <?php echo (int)PROJECT_MAX_ROLES; ?>;

    function rowCount() { return wrap.querySelectorAll(".pj-role-row").length; }
    function refresh() { addBtn.style.display = rowCount() >= MAXR ? "none" : ""; }

    function bindRow(row) {
        row.querySelector(".pj-role-x").addEventListener("click", function () {
            if (rowCount() <= 1) {
                row.querySelector("input[name='role_title[]']").value = "";
                row.querySelector("select").value = "1";
                return;
            }
            row.remove();
            refresh();
        });
    }
    wrap.querySelectorAll(".pj-role-row").forEach(bindRow);

    addBtn.addEventListener("click", function () {
        if (rowCount() >= MAXR) return;
        var clone = wrap.querySelector(".pj-role-row").cloneNode(true);
        clone.querySelector("input[name='role_id[]']").value = "0";
        clone.querySelector("input[name='role_title[]']").value = "";
        clone.querySelector("select").value = "1";
        wrap.appendChild(clone);
        bindRow(clone);
        clone.querySelector("input[name='role_title[]']").focus();
        refresh();
    });
    refresh();

    // ---- skills as chips ----
    var box = document.getElementById("chips");
    var input = document.getElementById("chip-input");
    var hidden = document.getElementById("skills");
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

<?php if ($bannerReady || $logoReady) { ?>
<script>
// the pickers + framing editor come from image-framer.php (included at the end of the page), so wait for the page to load
document.addEventListener("DOMContentLoaded", function () {
    if (!window.CamigoPicker) return;

    var area = document.getElementById("banner-preview");
    if (area) {
        CamigoPicker({
            input: "banner", area: "banner-preview", removeField: "remove-banner", remove: "banner-remove", msg: "banner-msg",
            shape: "wide", title: "Frame your banner", fileName: "banner.jpg",
            show: function (url) { area.classList.add("has-img"); area.style.backgroundImage = "url('" + url + "')"; },
            reset: function () { area.classList.remove("has-img"); area.style.backgroundImage = ""; }
        });
    }

    var logo = document.getElementById("logo-preview");
    var logoX = document.getElementById("logo-remove");
    if (logo) {
        CamigoPicker({
            input: "logo", area: "logo-preview", removeField: "remove-logo", remove: "logo-remove", msg: "banner-msg",
            shape: "square", title: "Frame your project logo", fileName: "logo.jpg",
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

        // the letter on the logo follows the project name while no logo is chosen
        var nm = document.getElementById("title");
        if (nm) {
            nm.addEventListener("input", function () {
                var t = nm.value.trim();
                var l = t ? t.charAt(0).toUpperCase() : "P";
                logo.setAttribute("data-letter", l);
                if (!logo.classList.contains("has-logo")) logo.textContent = l;
            });
        }
    }
});
</script>
<?php } ?>