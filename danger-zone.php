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
<section class="danger-zone<?php echo $dzTodo ? ' has-todo' : ''; ?>">
    <div class="dz-text">
        <h2><?php echo e($dzTitle); ?></h2>
        <?php if ($dzKind === 'account') { ?>
            <p>Deleting your account permanently removes your profile, settings, verification and photos, and takes you out of every club, project and event. Your posts, updates and messages in shared spaces stay, shown as &ldquo;Deleted user&rdquo;. This action cannot be undone.</p>
        <?php } else { ?>
            <p>Deleting this <?php echo e($dzKind); ?> will permanently remove all of its data. This action cannot be undone.</p>
        <?php } ?>
        <?php if ($dzTodo) { ?>
            <div class="dz-todo">
                <strong>Delete these first</strong>
                <ul>
                    <?php foreach ($dzTodo as $t) { ?>
                        <li><a href="<?php echo e($t[0]); ?>"><?php echo e($t[1]); ?></a><span><?php echo e($t[2]); ?></span></li>
                    <?php } ?>
                </ul>
            </div>
        <?php } ?>
    </div>
    <?php if (!$dzTodo) { ?><button type="button" class="dz-open" id="<?php echo $dzId; ?>-open">Delete <?php echo e(ucfirst($dzKind)); ?></button><?php } ?>
</section>

<?php if ($dzTodo) return; ?>

<dialog class="dz-modal" id="<?php echo $dzId; ?>">
    <form method="POST" autocomplete="off">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="<?php echo e($dzIdField); ?>" value="<?php echo (int)$dzIdValue; ?>">
        <h3><?php echo e($dzTitle); ?></h3>
        <?php if ($dzPassword) { ?>
        <p>This cannot be undone. Enter your password to confirm.</p>
        <input type="password" name="confirm_password" class="dz-input" autocomplete="current-password" aria-label="Enter your password to confirm" data-password="1">
        <?php } else { ?>
        <p>This cannot be undone. Type <strong><?php echo e($dzName); ?></strong> to confirm.</p>
        <input type="text" class="dz-input" spellcheck="false" autocapitalize="off" aria-label="Type the <?php echo e($dzKind); ?> name to confirm" data-expect="<?php echo e($dzName); ?>">
        <?php } ?>
        <div class="dz-foot">
            <button type="button" class="dz-cancel">Cancel</button>
            <button type="submit" name="<?php echo e($dzField); ?>" value="1" class="dz-confirm" disabled>Delete <?php echo e($dzKind); ?></button>
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
    dlg.querySelector('.dz-cancel').addEventListener('click', close);
    dlg.addEventListener('click', function (e) { if (e.target === dlg) close(); });
    input.addEventListener('input', check);
    dlg.querySelector('form').addEventListener('submit', function (e) { check(); if (go.disabled) e.preventDefault(); });
})();
</script>