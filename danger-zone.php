<?php
// Shared delete block for the edit pages.
// Expects: $dzKind ('club' | 'project' | 'account'), $dzName, $dzField, $dzIdField, $dzIdValue
// Optional: $dzPassword = true asks for the account password instead of typing the name.
$dzPassword = !empty($dzPassword);
$dzId = 'dz-' . $dzKind;
$dzTitle = ucfirst($dzKind) . ' deletion';
// Account only: $dzBlockers = what the person still owns (from account_blockers()). While any of it is left, the button is replaced by the list.
$dzTodo = array();
if ($dzKind === 'account' && !empty($dzBlockers)) {
    foreach ($dzBlockers['clubs'] as $b)    $dzTodo[] = array('club?id=' . (int)$b['id'], $b['name'], 'Club');
    foreach ($dzBlockers['projects'] as $b) $dzTodo[] = array('project?id=' . (int)$b['id'], $b['title'], 'Project');
    foreach ($dzBlockers['events'] as $b)   $dzTodo[] = array('event?id=' . (int)$b['id'], $b['title'], 'Event');
}
?>
<section class="danger-zone">
    <div class="dz-text">
        <h2><?php echo e($dzTitle); ?></h2>
        <?php if ($dzKind === 'account') { ?>
            <p>Deleting your account permanently removes your profile, settings, verification and photos, and takes you out of every club, project and event. Your posts, updates and messages in shared spaces stay, shown as &ldquo;Deleted user&rdquo;. This action cannot be undone.</p>
        <?php } else { ?>
            <p>Deleting this <?php echo e($dzKind); ?> will permanently remove all of its data. This action cannot be undone.</p>
        <?php } ?>
    </div>
    <button type="button" class="dz-open" id="<?php echo $dzId; ?>-open">Delete <?php echo e(ucfirst($dzKind)); ?></button>
</section>

<?php if ($dzTodo) { ?>
<dialog class="dz-modal cfm-card cfm-danger" id="<?php echo $dzId; ?>" aria-labelledby="<?php echo $dzId; ?>-title">
    <div class="cfm-body">
        <button type="button" class="cfm-x dz-cancel" aria-label="Close"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg></button>
        <div class="cfm-head">
            <span class="cfm-icon"><span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3.5 2.8 19.5a1 1 0 0 0 .9 1.5h16.6a1 1 0 0 0 .9-1.5L12 3.5z"/><path d="M12 10v4.2"/><path d="M12 17.4v.1"/></svg></span></span>
            <div class="cfm-copy">
                <h3 class="cfm-title" id="<?php echo $dzId; ?>-title">Delete these first</h3>
                <p class="cfm-text">Your account can&rsquo;t be deleted while you still own the items below. Delete them, then come back.</p>
            </div>
        </div>
        <ul class="cfm-todo">
            <?php foreach ($dzTodo as $t) { ?>
                <li><a href="<?php echo e($t[0]); ?>"><?php echo e($t[1]); ?></a><span><?php echo e($t[2]); ?></span></li>
            <?php } ?>
        </ul>
        <div class="cfm-foot cfm-foot-one">
            <button type="button" class="cfm-btn cfm-btn-ghost dz-cancel">Close</button>
        </div>
    </div>
</dialog>
<script>
(function () {
    var dlg = document.getElementById('<?php echo $dzId; ?>');
    document.getElementById('<?php echo $dzId; ?>-open').addEventListener('click', function () {
        if (dlg.showModal) dlg.showModal(); else dlg.setAttribute('open', '');
    });
    function close() { if (dlg.close) dlg.close(); else dlg.removeAttribute('open'); }
    Array.prototype.forEach.call(dlg.querySelectorAll('.dz-cancel'), function (b) { b.addEventListener('click', close); });
    dlg.addEventListener('click', function (e) { if (e.target === dlg) close(); });
})();
</script>
<?php return; } ?>

<dialog class="dz-modal cfm-card cfm-danger" id="<?php echo $dzId; ?>" aria-labelledby="<?php echo $dzId; ?>-title">
    <form method="POST" autocomplete="off" class="cfm-body">
        <?php echo csrf_field(); ?>
        <button type="button" class="cfm-x dz-cancel" aria-label="Close"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg></button>
        <input type="hidden" name="<?php echo e($dzIdField); ?>" value="<?php echo (int)$dzIdValue; ?>">
        <div class="cfm-head">
            <span class="cfm-icon"><span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3.5 2.8 19.5a1 1 0 0 0 .9 1.5h16.6a1 1 0 0 0 .9-1.5L12 3.5z"/><path d="M12 10v4.2"/><path d="M12 17.4v.1"/></svg></span></span>
            <div class="cfm-copy">
                <h3 class="cfm-title" id="<?php echo $dzId; ?>-title"><?php echo e($dzTitle); ?></h3>
                <?php if ($dzPassword) { ?>
                <p class="cfm-text">This cannot be undone. Enter your password to confirm.</p>
                <?php } else { ?>
                <p class="cfm-text">This cannot be undone. Type <strong><?php echo e($dzName); ?></strong> to confirm.</p>
                <?php } ?>
            </div>
        </div>
        <?php if ($dzPassword) { ?>
        <input type="password" name="confirm_password" class="dz-input" autocomplete="current-password" aria-label="Enter your password to confirm" data-password="1">
        <?php } else { ?>
        <input type="text" class="dz-input" spellcheck="false" autocapitalize="off" aria-label="Type the <?php echo e($dzKind); ?> name to confirm" data-expect="<?php echo e($dzName); ?>">
        <?php } ?>
        <div class="cfm-foot">
            <button type="submit" name="<?php echo e($dzField); ?>" value="1" class="cfm-btn cfm-btn-danger dz-confirm" disabled>Delete <?php echo e($dzKind); ?></button>
            <button type="button" class="cfm-btn cfm-btn-ghost dz-cancel">Cancel</button>
        </div>
    </form>
</dialog>

<script>
(function () {
    var dlg = document.getElementById('<?php echo $dzId; ?>');
    var input = dlg.querySelector('.dz-input');
    var go = dlg.querySelector('.dz-confirm');
    function check() { go.disabled = input.hasAttribute('data-password') ? input.value === '' : input.value !== input.getAttribute('data-expect'); }
    document.getElementById('<?php echo $dzId; ?>-open').addEventListener('click', function () {
        input.value = ''; check();
        if (dlg.showModal) dlg.showModal(); else dlg.setAttribute('open', '');
        input.focus();
    });
    function close() { if (dlg.close) dlg.close(); else dlg.removeAttribute('open'); }
    Array.prototype.forEach.call(dlg.querySelectorAll('.dz-cancel'), function (b) { b.addEventListener('click', close); });
    dlg.addEventListener('click', function (e) { if (e.target === dlg) close(); });
    input.addEventListener('input', check);
    dlg.querySelector('form').addEventListener('submit', function (e) { check(); if (go.disabled) e.preventDefault(); });
})();
</script>