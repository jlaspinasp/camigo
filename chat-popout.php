<?php
// The floating chat window (bottom right). realtime.php loads it on every page that has the top bar;
// the Messages page itself switches it off with $noChatPopout. Needs $rtUserId.
if (empty($rtUserId) || !empty($noChatPopout)) return;
?>
<link rel="stylesheet" href="chat-popout.css?v=<?php echo (int)@filemtime(__DIR__ . '/chat-popout.css'); ?>">
<section class="cpop" id="cpop" role="dialog" aria-label="Messages" data-state="" hidden>
    <header class="cpop-bar">
        <button type="button" class="cpop-title" data-act="toggle" aria-label="Minimize or open Messages">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 4.5h14a1.5 1.5 0 011.5 1.5v9a1.5 1.5 0 01-1.5 1.5h-8L6.5 20.5V16.5H5A1.5 1.5 0 013.5 15V6A1.5 1.5 0 015 4.5z"/></svg>
            <span>Messages</span>
            <span class="cpop-count" hidden></span>
        </button>
        <div class="cpop-tools">
            <button type="button" class="cpop-btn" data-act="expand" title="Open full page" aria-label="Open Messages full page">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 4h6v6M20 4l-8 8M18 14v4a2 2 0 01-2 2H6a2 2 0 01-2-2V8a2 2 0 012-2h4"/></svg>
            </button>
            <button type="button" class="cpop-btn" data-act="min" title="Minimize" aria-label="Minimize Messages">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg>
            </button>
            <button type="button" class="cpop-btn" data-act="close" title="Close" aria-label="Close Messages">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg>
            </button>
        </div>
    </header>
    <div class="cpop-body"><div class="cpop-loading">Loading&hellip;</div></div>
</section>
<script src="chat-popout.js?v=<?php echo (int)@filemtime(__DIR__ . '/chat-popout.js'); ?>"></script>