<?php
// Notification bell + dropdown. Expects $unread (a number) and either $me or $urow (the logged-in user's row).
$bellUser = isset($me['id']) ? (int)$me['id'] : (isset($urow['id']) ? (int)$urow['id'] : 0);
$bellItems = array();
if ($bellUser) {
    $bm = notif_mute_sql($conn, $bellUser);
    $br = db_run($conn,
        "SELECT id, type, message, is_read, UNIX_TIMESTAMP(created_at) AS ts
         FROM notifications WHERE user_id = ?" . $bm[0] . " ORDER BY id DESC LIMIT 8",
        "i" . $bm[1], array_merge(array($bellUser), $bm[2]));
    $bellItems = $br['rows'];
}
?>
<div class="notif-wrap" id="notif-wrap">
    <button type="button" class="bell" id="notif-btn" aria-haspopup="true" aria-expanded="false" aria-controls="notif-panel"
            aria-label="Notifications<?php echo $unread > 0 ? ' (' . (int)$unread . ' unread)' : ''; ?>">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M6 16v-5a6 6 0 0112 0v5l1.5 2h-15L6 16zM10 21h4"/></svg>
        <span class="dot" id="notif-dot"<?php echo $unread > 0 ? '' : ' hidden'; ?>><?php echo $unread > 9 ? '9+' : (int)$unread; ?></span>
    </button>

    <div class="notif-panel" id="notif-panel" role="dialog" aria-label="Notifications" hidden>
        <div class="np-head">
            <h3>Notifications</h3>
            <div class="np-tools">
                <button type="button" class="np-icon" id="notif-readall" title="Mark all as read" aria-label="Mark all as read"<?php echo $unread > 0 ? '' : ' disabled'; ?>>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg>
                </button>
                <a href="notification-preferences" class="np-icon" title="Notification preferences" aria-label="Notification preferences">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 00.3 1.8l.1.1a2 2 0 11-2.8 2.8l-.1-.1a1.7 1.7 0 00-1.8-.3 1.7 1.7 0 00-1 1.5V21a2 2 0 11-4 0v-.1a1.7 1.7 0 00-1.1-1.5 1.7 1.7 0 00-1.8.3l-.1.1a2 2 0 11-2.8-2.8l.1-.1a1.7 1.7 0 00.3-1.8 1.7 1.7 0 00-1.5-1H3a2 2 0 110-4h.1a1.7 1.7 0 001.5-1.1 1.7 1.7 0 00-.3-1.8l-.1-.1a2 2 0 112.8-2.8l.1.1a1.7 1.7 0 001.8.3H9a1.7 1.7 0 001-1.5V3a2 2 0 114 0v.1a1.7 1.7 0 001 1.5 1.7 1.7 0 001.8-.3l.1-.1a2 2 0 112.8 2.8l-.1.1a1.7 1.7 0 00-.3 1.8V9a1.7 1.7 0 001.5 1H21a2 2 0 110 4h-.1a1.7 1.7 0 00-1.5 1z"/></svg>
                </a>
            </div>
        </div>

        <div class="np-list">
            <?php if (!$bellItems) { ?>
                <div class="np-empty">
                    <span class="np-empty-ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M6 16v-5a6 6 0 0112 0v5l1.5 2h-15L6 16zM10 21h4"/></svg></span>
                    <strong>You're all caught up</strong>
                    <span>New activity from your clubs, events and projects shows up here.</span>
                </div>
            <?php } else { foreach ($bellItems as $bi) {
                $look = notif_look($bi['type']);
                $isUnread = !(int)$bi['is_read'];
            ?>
                <div class="n-row" data-id="<?php echo (int)$bi['id']; ?>">
                    <a href="notifications?open=<?php echo (int)$bi['id']; ?>" class="np-item<?php echo $isUnread ? ' unread' : ''; ?>">
                        <span class="n-ic <?php echo e($look[0]); ?>">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><?php echo $look[1]; ?></svg>
                        </span>
                        <span class="n-text">
                            <span class="n-msg"><?php echo e($bi['message']); ?></span>
                            <span class="n-time"><?php echo e(time_ago($bi['ts'])); ?></span>
                        </span>
                        <?php if ($isUnread) { ?><span class="u-dot" aria-label="Unread"></span><?php } ?>
                    </a>
                    <?php include("notif-more.php"); ?>
                </div>
            <?php } } ?>
        </div>

        <a href="notifications" class="np-foot">See all notifications</a>
    </div>
</div>
<?php if (empty($GLOBALS['notif_js_done'])) { $GLOBALS['notif_js_done'] = true; ?>
<script>
(function () {
    var wrap = document.getElementById("notif-wrap");
    if (!wrap) return;
    var btn = document.getElementById("notif-btn");
    var panel = document.getElementById("notif-panel");
    var dot = document.getElementById("notif-dot");
    var readAll = document.getElementById("notif-readall");
    var csrf = <?php echo json_encode(csrf_token()); ?>;

    // ---- the one shared "..." menu ----
    var menu = document.createElement("div");
    menu.className = "n-menu";
    menu.setAttribute("role", "menu");
    menu.hidden = true;
    menu.innerHTML =
        '<button type="button" role="menuitem" data-act="toggle"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg><span></span></button>' +
        '<button type="button" role="menuitem" data-act="delete" class="danger"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 7h16M10 11v6M14 11v6M6 7l1 12a2 2 0 002 2h6a2 2 0 002-2l1-12M9 7V4h6v3"/></svg><span>Delete notification</span></button>';
    document.body.appendChild(menu);
    var current = null; // {btn, row}

    function closeMenu() {
        menu.hidden = true;
        if (current) current.btn.setAttribute("aria-expanded", "false");
        current = null;
    }
    function openMenu(b) {
        var row = b.closest(".n-row");
        var link = row.querySelector("a");
        var isUnread = link.classList.contains("unread");
        menu.querySelector("[data-act=toggle] span").textContent = isUnread ? "Mark as read" : "Mark as unread";
        current = { btn: b, row: row };
        b.setAttribute("aria-expanded", "true");
        menu.hidden = false;
        var r = b.getBoundingClientRect(), w = menu.offsetWidth, h = menu.offsetHeight;
        var top = r.bottom + 6;
        if (top + h > window.innerHeight - 8) top = Math.max(8, r.top - h - 6);
        menu.style.top = top + "px";
        menu.style.left = Math.max(8, Math.min(r.right - w, window.innerWidth - w - 8)) + "px";
    }

    function setBadge(n) {
        n = Math.max(0, n | 0);
        dot.textContent = n > 9 ? "9+" : n;
        dot.hidden = n === 0;
        readAll.disabled = n === 0;
    }
    function send(data) {
        var fd = new FormData();
        for (var k in data) fd.append(k, data[k]);
        fd.append("csrf", csrf);
        fd.append("ajax", "1");
        return fetch("notifications", { method: "POST", body: fd, credentials: "same-origin" }).then(function (r) { return r.json(); });
    }
    function showEmpty() {
        var list = panel.querySelector(".np-list");
        if (!list.querySelector(".n-row")) {
            list.innerHTML = '<div class="np-empty"><span class="np-empty-ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M6 16v-5a6 6 0 0112 0v5l1.5 2h-15L6 16zM10 21h4"/></svg></span><strong>You\'re all caught up</strong><span>New activity from your clubs, events and projects shows up here.</span></div>';
        }
    }
    // same notification can appear in the dropdown and on the full page
    function rowsFor(id) { return document.querySelectorAll('.n-row[data-id="' + id + '"]'); }

    menu.addEventListener("click", function (ev) {
        var b = ev.target.closest("button[data-act]");
        if (!b || !current) return;
        var row = current.row, id = row.dataset.id;
        var wasUnread = row.querySelector("a").classList.contains("unread");
        var act = b.dataset.act === "delete" ? "delete" : (wasUnread ? "read" : "unread");
        closeMenu();
        send({ act: act, id: id }).then(function (d) {
            if (!d.ok) return;
            rowsFor(id).forEach(function (r) {
                var a = r.querySelector("a");
                if (act === "delete") { r.remove(); return; }
                a.classList.toggle("unread", act === "unread");
                var u = a.querySelector(".u-dot");
                if (act === "read" && u) u.remove();
                if (act === "unread" && !u) { u = document.createElement("span"); u.className = "u-dot"; a.appendChild(u); }
            });
            setBadge(d.unread);
            showEmpty();
            var page = document.querySelector(".notif-list");
            if (page && !page.querySelector(".n-row")) location.reload();
        });
    });

    document.addEventListener("click", function (ev) {
        var more = ev.target.closest(".n-more");
        if (more) {
            ev.preventDefault();
            if (current && current.btn === more) closeMenu(); else { closeMenu(); openMenu(more); }
            return;
        }
        if (!menu.hidden && !menu.contains(ev.target)) closeMenu();
        if (!panel.hidden && !wrap.contains(ev.target) && !menu.contains(ev.target)) setOpen(false);
    });
    document.addEventListener("scroll", closeMenu, true);
    window.addEventListener("resize", closeMenu);

    // ---- the dropdown itself ----
    function setOpen(open) {
        panel.hidden = !open;
        btn.setAttribute("aria-expanded", open ? "true" : "false");
        if (!open) closeMenu();
    }
    btn.addEventListener("click", function () { setOpen(panel.hidden); });
    document.addEventListener("keydown", function (ev) {
        if (ev.key !== "Escape") return;
        if (!menu.hidden) { var b = current && current.btn; closeMenu(); if (b) b.focus(); return; }
        if (!panel.hidden) { setOpen(false); btn.focus(); }
    });

    readAll.addEventListener("click", function () {
        readAll.disabled = true;
        send({ read_all: "1" }).then(function (d) {
            if (!d.ok) { readAll.disabled = false; return; }
            setBadge(0);
            document.querySelectorAll(".n-row a.unread").forEach(function (el) {
                el.classList.remove("unread");
                var u = el.querySelector(".u-dot"); if (u) u.remove();
            });
        }).catch(function () { readAll.disabled = false; });
    });
})();
</script>
<?php } ?>