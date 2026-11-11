<?php
// Form fields shared by create-event.php and edit-event.php.
// Expects $f (current values) and $minDate (earliest date, Y-m-d).
// Banner picker (optional): $bannerReady (events-banner.sql has been run), $bannerCur (uploaded banner file name, or ''),
// $bannerCoversIn (club id => club cover file name, for the live preview), $bannerClub (the hosting club id, 0 = none),
// $bannerClubs (true when the person can host this with a club, so the hint can mention the club banner).
$bannerReady  = !empty($bannerReady);
$bannerCur    = (!empty($bannerCur) && club_image_ok($bannerCur)) ? $bannerCur : '';
$bannerClub   = (int)($bannerClub ?? 0);
$bannerCovers = array();
foreach ((isset($bannerCoversIn) && is_array($bannerCoversIn)) ? $bannerCoversIn : array() as $cid => $cv) {
    if (club_image_ok($cv)) $bannerCovers[(int)$cid] = $cv;
}
$bannerInherit = ($bannerCur === '' && $bannerClub > 0 && isset($bannerCovers[$bannerClub])) ? $bannerCovers[$bannerClub] : '';
$bannerG       = $bannerClub > 0 ? $bannerClub % 6 : 0;
?>
<?php if ($bannerReady) { ?>
<div class="field ev-banner-field">
    <label>Banner <span class="opt">(optional)</span></label>
    <div class="ev-banner-up">
        <div class="cover id-cover<?php echo ($bannerCur !== '' || $bannerInherit !== '') ? ' has-img' : ''; ?><?php echo $bannerInherit !== '' ? ' is-inherited' : ''; ?>" id="banner-preview" role="button" tabindex="0" aria-label="Change banner image"
             data-gd="<?php echo $bannerG; ?>"
             <?php if ($bannerCur !== '') { ?>style="background-image:url('uploads/events/<?php echo e($bannerCur); ?>')"
             <?php } elseif ($bannerInherit !== '') { ?>style="background-image:url('uploads/clubs/<?php echo e($bannerInherit); ?>')"
             <?php } else { ?>data-g="<?php echo $bannerG; ?>"<?php } ?>>
            <div class="id-cta"><svg viewBox="0 0 24 24" width="26" height="26" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2"/><circle cx="9" cy="10" r="1.6"/><path d="m21 16-5-5-9 9"/></svg><strong id="banner-cta"><?php echo $bannerInherit !== '' ? 'Using your club&rsquo;s banner. Click to use a different one.' : 'Click to add a banner'; ?></strong><span id="banner-cta-sub">Without one, your event gets a color.</span></div>
            <button type="button" class="id-x" id="banner-remove" aria-label="Remove banner image"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg></button>
        </div>
    </div>
    <input type="file" name="banner" id="banner" accept="image/jpeg,image/png,image/webp" hidden>
    <input type="hidden" name="remove_banner" id="remove-banner" value="0">
    <p class="hint" id="banner-msg">Shown on your event page and its card. JPG, PNG or WebP, up to 10 MB, cropped wide.<?php echo !empty($bannerClubs) ? ' With no banner, a club event uses its club&rsquo;s banner.' : ''; ?></p>
</div>
<?php } else { ?>
<div class="msg error" style="margin-bottom:18px">Event banners need a one-time upgrade. Run <strong>events-banner.sql</strong> in phpMyAdmin to unlock them.</div>
<?php } ?>

<div class="field">
    <label for="title">Event title</label>
    <input type="text" name="title" id="title" maxlength="80" placeholder="e.g. Intro to Web Dev Workshop" value="<?php echo e($f['title']); ?>" required>
</div>

<div class="ev-row">
    <div class="field">
        <label for="event_date">Date</label>
        <input type="date" name="event_date" id="event_date" min="<?php echo e($minDate); ?>" value="<?php echo e($f['event_date']); ?>" required>
    </div>
    <div class="field">
        <label for="start_time">Starts</label>
        <input type="time" name="start_time" id="start_time" value="<?php echo e($f['start_time']); ?>" required>
    </div>
    <div class="field">
        <label for="end_time">Ends <span class="opt">(optional)</span></label>
        <input type="time" name="end_time" id="end_time" value="<?php echo e($f['end_time']); ?>">
    </div>
</div>

<div class="ev-row two">
    <div class="field">
        <label for="location">Where</label>
        <input type="text" name="location" id="location" maxlength="120" placeholder="e.g. Room 204, Main Building" value="<?php echo e($f['location']); ?>" required>
    </div>
    <div class="field">
        <label for="capacity">Spots <span class="opt">(optional)</span></label>
        <input type="number" name="capacity" id="capacity" min="1" max="5000" inputmode="numeric" placeholder="No limit" value="<?php echo e($f['capacity']); ?>">
    </div>
</div>

<?php if (!empty($noVis)) { ?>
<input type="hidden" name="visibility" value="public">
<?php } else { ?>
<fieldset class="vis-field">
    <legend>Who can see this event?</legend>
    <div class="vis-options">
        <label class="vis-opt">
            <input type="radio" name="visibility" value="public"<?php echo ($f['visibility'] !== 'members') ? ' checked' : ''; ?>>
            <span class="vis-card">
                <span class="vis-ic"><?php echo event_icon('globe'); ?></span>
                <span class="vis-txt"><strong>Everyone on Camigo</strong><em>Listed for all students. Anyone can join.</em></span>
            </span>
        </label>
        <label class="vis-opt">
            <input type="radio" name="visibility" value="members"<?php echo ($f['visibility'] === 'members') ? ' checked' : ''; ?>>
            <span class="vis-card excl">
                <span class="vis-ic"><?php echo event_icon('lock'); ?></span>
                <span class="vis-txt"><strong>Club members only</strong><em>Hidden from outsiders. Only your club's members can see it and join.</em></span>
            </span>
        </label>
    </div>
    <?php if (!empty($visHint)) { ?><p class="vis-hint"><?php echo e($visHint); ?></p><?php } ?>
</fieldset>
<?php } ?>

<div class="field">
    <label for="description">What's it about?</label>
    <textarea name="description" id="description" maxlength="1500" placeholder="What will happen, who it's for, and anything people should bring." required><?php echo e($f['description']); ?></textarea>
    <div class="counter"><span id="descCount">0</span> / 1500</div>
</div>

<script>
(function () {
    var t = document.getElementById("description"), c = document.getElementById("descCount");
    if (!t || !c) return;
    function up() { c.textContent = t.value.length; }
    t.addEventListener("input", up); up();
})();
</script>

<?php if ($bannerReady) { ?>
<script>
(function () {
    var area = document.getElementById("banner-preview");
    var input = document.getElementById("banner");
    var removeField = document.getElementById("remove-banner");
    var removeBtn = document.getElementById("banner-remove");
    var cta = document.getElementById("banner-cta");
    var ctaSub = document.getElementById("banner-cta-sub");
    var msg = document.getElementById("banner-msg");
    if (!area || !input) return;

    var MAX = 10 * 1024 * 1024;
    var TYPES = /^image\/(jpeg|png|webp)$/;
    var msgText = msg.textContent;
    var covers = <?php echo json_encode($bannerCovers, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_FORCE_OBJECT); ?>;
    var fixedClub = <?php echo (int)$bannerClub; ?>;
    var existing = <?php echo json_encode($bannerCur !== '' ? 'uploads/events/' . $bannerCur : ''); ?>;
    var sel = document.getElementById("club_id");   // only on the create page
    var chosenUrl = "";
    var removed = false;

    function say(t) { msg.textContent = t; msg.classList.add("bad"); }
    function calm() { msg.textContent = msgText; msg.classList.remove("bad"); }
    function club() { return sel ? (parseInt(sel.value, 10) || 0) : fixedClub; }

    // the same order the site uses: own upload, then the club's banner, then a color
    function paint() {
        var c = club();
        var own = chosenUrl || (removed ? "" : existing);
        var inherited = (!own && covers[c]) ? "uploads/clubs/" + covers[c] : "";
        var url = own || inherited;
        if (url) {
            area.classList.add("has-img");
            area.classList.toggle("is-inherited", !own);
            area.removeAttribute("data-g");
            area.style.backgroundImage = "url('" + url + "')";
            cta.textContent = "Using your club\u2019s banner. Click to use a different one.";
        } else {
            area.classList.remove("has-img", "is-inherited");
            area.style.backgroundImage = "";
            area.setAttribute("data-g", String(c > 0 ? c % 6 : 0));
            cta.textContent = "Click to add a banner";
        }
        ctaSub.style.display = url ? "none" : "";
    }

    function accept(file) {
        if (!file) return false;
        if (!TYPES.test(file.type)) { say("Please choose a JPG, PNG or WebP image."); return false; }
        if (file.size > MAX) { say("That image is over 10 MB. Please choose a smaller one."); return false; }
        calm();
        if (chosenUrl) URL.revokeObjectURL(chosenUrl);
        chosenUrl = URL.createObjectURL(file);
        removed = false;
        removeField.value = "0";
        paint();
        return true;
    }

    function open() { input.click(); }
    input.addEventListener("change", function () {
        var f = input.files && input.files[0];
        if (f && !accept(f)) input.value = "";
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
    removeBtn.addEventListener("click", function (e) {
        e.stopPropagation();
        input.value = "";
        if (chosenUrl) { URL.revokeObjectURL(chosenUrl); chosenUrl = ""; }
        removed = true;
        removeField.value = "1";
        calm();
        paint();
    });

    if (sel) sel.addEventListener("change", paint);
    paint();
})();
</script>
<?php } ?>