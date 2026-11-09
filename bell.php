<?php // Notification bell. Expects $unread (a number). ?>
<a href="notifications" class="bell" aria-label="Notifications<?php echo $unread > 0 ? ' (' . (int)$unread . ' unread)' : ''; ?>">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M6 16v-5a6 6 0 0112 0v5l1.5 2h-15L6 16zM10 21h4"/></svg>
    <?php if ($unread > 0) { ?><span class="dot"><?php echo $unread > 9 ? '9+' : (int)$unread; ?></span><?php } ?>
</a>