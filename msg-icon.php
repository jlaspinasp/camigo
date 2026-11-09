<?php // Messages icon for the top bar. Expects $unreadMsg (a number). ?>
<a href="messages" class="bell" aria-label="Messages<?php echo $unreadMsg > 0 ? ' (' . (int)$unreadMsg . ' unread)' : ''; ?>">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M5 4.5h14a1.5 1.5 0 011.5 1.5v9a1.5 1.5 0 01-1.5 1.5h-8L6.5 20.5V16.5H5A1.5 1.5 0 013.5 15V6A1.5 1.5 0 015 4.5z"/></svg>
    <span class="dot" id="msg-dot"<?php echo $unreadMsg > 0 ? '' : ' hidden'; ?>><?php echo $unreadMsg > 9 ? '9+' : (int)$unreadMsg; ?></span>
</a>