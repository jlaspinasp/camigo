<?php // Form fields shared by create-event.php and edit-event.php. Expects $f (current values) and $minDate (earliest date, Y-m-d). ?>
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