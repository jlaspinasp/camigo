<?php
// Profile dropdown for the top bar (the avatar button). Same look as the notification dropdown.
// Expects $conn and either $me or $urow (the logged-in user's row).
$pmUser = (isset($me['id']) && is_array($me)) ? $me : ((isset($urow['id']) && is_array($urow)) ? $urow : null);
if (!$pmUser) { ?>
    <a href="profile" class="me-link" title="Your profile"><span class="avatar"><?php echo e(isset($initial) ? $initial : '?'); ?></span></a>
    <a href="logout" class="out">Log out</a>
<?php return; }

$pmId       = (int)$pmUser['id'];
$pmFirst    = (string)($pmUser['first_name'] ?? '');
$pmFull     = trim($pmFirst . ' ' . (string)($pmUser['last_name'] ?? ''));
$pmUsername = (string)($pmUser['username'] ?? '');
$pmAdmin    = (($pmUser['role'] ?? 'user') === 'admin');

// Subtitle under "Verify account"
$pmVerify = 'Not verified';
if (function_exists('user_is_verified') && user_is_verified($pmUser)) {
    $pmVerify = 'Verified';
} elseif (function_exists('verify_latest')) {
    $pmLatest = verify_latest($conn, $pmId);
    if ($pmLatest && $pmLatest['status'] === 'pending') $pmVerify = 'Under review';
}

// Subtitle under "My clubs"
$pmClubs = 0;
$pmc = db_run($conn, "SELECT COUNT(*) AS n FROM club_members WHERE user_id = ? AND status = 'active'", "i", array($pmId));
if (!empty($pmc['rows'])) $pmClubs = (int)$pmc['rows'][0]['n'];

$pmIcons = array(
    'user'   => '<circle cx="12" cy="8" r="3.6"/><path d="M4.5 20c.8-3.6 3.8-5.6 7.5-5.6s6.7 2 7.5 5.6"/>',
    'edit'   => '<path d="M4 20h4L19 9a2.1 2.1 0 00-3-3L5 17v3zM14.5 7.5l3 3"/>',
    'shield' => '<path d="M12 3l7.5 2.8v5.6c0 4.5-3 7.9-7.5 9.6-4.5-1.7-7.5-5.1-7.5-9.6V5.8L12 3z"/><path d="M8.6 12.2l2.5 2.5 4.4-4.9"/>',
    'clubs'  => '<circle cx="9" cy="8.5" r="3"/><path d="M3.5 19c.6-3 2.8-4.7 5.5-4.7s4.9 1.7 5.5 4.7"/><path d="M16 5.8a3 3 0 010 5.4M17.5 14.5c1.9.5 3.1 2 3.5 4.5"/>',
    'folder' => '<path d="M3.5 7a1.5 1.5 0 011.5-1.5h4l2 2.5h8A1.5 1.5 0 0120.5 9.5v8A1.5 1.5 0 0119 19H5a1.5 1.5 0 01-1.5-1.5V7z"/>',
    'cal'    => '<rect x="3.5" y="5" width="17" height="15.5" rx="2.5"/><path d="M3.5 10h17M8 3v4M16 3v4"/>',
    'gear'   => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 00.3 1.8l.1.1a2 2 0 11-2.8 2.8l-.1-.1a1.7 1.7 0 00-1.8-.3 1.7 1.7 0 00-1 1.5V21a2 2 0 11-4 0v-.1a1.7 1.7 0 00-1.1-1.5 1.7 1.7 0 00-1.8.3l-.1.1a2 2 0 11-2.8-2.8l.1-.1a1.7 1.7 0 00.3-1.8 1.7 1.7 0 00-1.5-1H3a2 2 0 110-4h.1a1.7 1.7 0 001.5-1.1 1.7 1.7 0 00-.3-1.8l-.1-.1a2 2 0 112.8-2.8l.1.1a1.7 1.7 0 001.8.3H9a1.7 1.7 0 001-1.5V3a2 2 0 114 0v.1a1.7 1.7 0 001 1.5 1.7 1.7 0 001.8-.3l.1-.1a2 2 0 112.8 2.8l-.1.1a1.7 1.7 0 00-.3 1.8V9a1.7 1.7 0 001.5 1H21a2 2 0 110 4h-.1a1.7 1.7 0 00-1.5 1z"/>',
    'admin'  => '<path d="M4 7h10M18 7h2M4 17h2M10 17h10"/><circle cx="16" cy="7" r="2"/><circle cx="8" cy="17" r="2"/>',
    'out'    => '<path d="M9 4.5H6A1.5 1.5 0 004.5 6v12A1.5 1.5 0 006 19.5h3M15 8l4 4-4 4M19 12H9.5"/>',
);
$GLOBALS['pmIcons'] = $pmIcons;
if (!function_exists('pm_item')) {
function pm_item($href, $icon, $label, $sub = '', $cls = '') {
    $pmIcons = $GLOBALS['pmIcons'];
    echo '<a href="' . e($href) . '" class="pm-item' . ($cls ? ' ' . $cls : '') . '" role="menuitem">'
       . '<span class="n-ic info"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $pmIcons[$icon] . '</svg></span>'
       . '<span class="pm-text"><span class="pm-label">' . e($label) . '</span>'
       . ($sub !== '' ? '<span class="pm-sub">' . e($sub) . '</span>' : '')
       . '</span></a>';
}
}
?>
<div class="pm-wrap" id="pm-wrap">
    <button type="button" class="pm-btn" id="pm-btn" aria-haspopup="true" aria-expanded="false" aria-controls="pm-panel" aria-label="Your account menu">
        <span class="avatar"><?php echo avatar_inner($pmUser); ?></span>
    </button>

    <div class="notif-panel pm-panel" id="pm-panel" role="menu" aria-label="Your account" hidden>
        <a href="profile" class="pm-head" role="menuitem">
            <span class="avatar pm-head-av"><?php echo avatar_inner($pmUser); ?></span>
            <span class="pm-text">
                <span class="pm-name"><span class="pm-name-t"><?php echo e($pmFull !== '' ? $pmFull : $pmFirst); ?></span><?php echo function_exists('verified_badge') ? verified_badge($pmUser) : ''; ?></span>
                <span class="pm-sub"><?php echo $pmUsername !== '' ? '@' . e($pmUsername) : 'View profile'; ?></span>
            </span>
        </a>

        <div class="pm-list">
            <?php
            pm_item('edit-profile', 'edit', 'Edit profile');
            pm_item('verify-account', 'shield', 'Verify account', $pmVerify);
            pm_item('clubs?mine=1', 'clubs', 'My clubs', $pmClubs === 1 ? '1 club' : $pmClubs . ' clubs');
            pm_item('projects?tab=mine', 'folder', 'My projects');
            pm_item('events?tab=going', 'cal', "Events I'm going to");
            pm_item('notification-preferences', 'gear', 'Notification preferences');
            if ($pmAdmin) pm_item('admin-verifications', 'admin', 'Admin');
            pm_item('logout', 'out', 'Log out', '', 'pm-out');
            ?>
        </div>
    </div>
</div>
<?php if (empty($GLOBALS['pm_js_done'])) { $GLOBALS['pm_js_done'] = true; ?>
<script>
(function () {
    var wrap = document.getElementById("pm-wrap");
    if (!wrap) return;
    var btn = document.getElementById("pm-btn");
    var panel = document.getElementById("pm-panel");
    function setOpen(open) {
        panel.hidden = !open;
        btn.setAttribute("aria-expanded", open ? "true" : "false");
    }
    btn.addEventListener("click", function () { setOpen(panel.hidden); });
    document.addEventListener("click", function (ev) {
        if (!panel.hidden && !wrap.contains(ev.target)) setOpen(false);
    });
    document.addEventListener("keydown", function (ev) {
        if (ev.key === "Escape" && !panel.hidden) { setOpen(false); btn.focus(); }
    });
})();
</script>
<?php } ?>