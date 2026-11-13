<?php
// The floating chat window (bottom right). realtime.php loads it on every page that has the top bar;
// the Messages page itself switches it off with $noChatPopout. Needs $rtUserId.
if (empty($rtUserId) || !empty($noChatPopout)) return;
?>
<style>
/* ==========================================================
   FLOATING CHAT WINDOW  -  Messages, docked at the bottom right of every page
   ========================================================== */
.cpop {
    position: fixed; right: 28px; bottom: 0; z-index: 80;
    display: flex; flex-direction: column;
    width: min(750px, calc(100vw - 56px));
    height: min(570px, calc(100vh - 96px));
    background: var(--white, #fff);
    border: 1px solid var(--line, #e3e6ee); border-bottom: 0;
    border-radius: 16px 16px 0 0;
    box-shadow: 0 -2px 6px rgba(12, 15, 58, 0.05), 0 18px 48px rgba(12, 15, 58, 0.22);
    overflow: hidden;
    animation: cpop-in 0.2s ease-out;
}
.cpop[hidden] { display: none; }
@keyframes cpop-in { from { opacity: 0; transform: translateY(14px); } to { opacity: 1; transform: none; } }

/* minimized: just the bar, like a docked tab */
.cpop[data-state="min"] { width: 210px; height: auto; }
.cpop[data-state="min"] .cpop-body { display: none; }

.cpop-bar {
    flex: none; display: flex; align-items: center; justify-content: space-between; gap: 8px;
    height: 46px; padding: 0 7px 0 5px;
    background: var(--white, #fff); border-bottom: 1px solid var(--line, #e3e6ee);
}
.cpop[data-state="min"] .cpop-bar { border-bottom: 0; }
.cpop-title {
    flex: 1; min-width: 0; display: flex; align-items: center; gap: 9px;
    height: 35px; padding: 0 10px; margin: 0;
    font: inherit; font-size: 14.75px; font-weight: 700; color: var(--ink, #14172b); text-align: left;
    background: none; border: 0; border-radius: 10px; cursor: pointer;
}
.cpop-title:hover { background: #f2f3fb; }
.cpop-title svg { flex: none; width: 20px; height: 20px; color: #000; }
.cpop-count {
    min-width: 20px; height: 20px; padding: 0 6px; display: inline-grid; place-items: center;
    font-size: 11.5px; font-weight: 700; line-height: 1; color: #fff; background: #e5484d; border-radius: 999px;
}
.cpop-count[hidden] { display: none; }
.cpop-tools { flex: none; display: flex; align-items: center; gap: 2px; }
.cpop-btn {
    display: inline-grid; place-items: center; width: 33px; height: 33px; padding: 0;
    color: var(--muted, #5b6075); background: none; border: 0; border-radius: 50%; cursor: pointer;
    transition: background 0.15s, color 0.15s;
}
.cpop-btn:hover { background: #eceefd; color: var(--indigo, #3338c9); }
.cpop-btn svg { width: 17px; height: 17px; }
.cpop[data-state="min"] .cpop-btn[data-act="expand"] { display: none; }
/* collapsed bar only: no down arrow, and the X shows while the pointer is on the bar (or the button has keyboard focus).
   When the window is expanded the down arrow and the X are always there. */
.cpop[data-state="min"] .cpop-btn[data-act="min"] { display: none; }
/* collapsed bar: no hover highlight on the bar or on the X */
.cpop[data-state="min"] .cpop-title:hover, .cpop[data-state="min"] .cpop-btn:hover { background: none; }
.cpop[data-state="min"] .cpop-btn:hover { color: var(--muted, #5b6075); }
.cpop[data-state="min"] .cpop-btn[data-act="close"] { opacity: 0; pointer-events: none; }
.cpop[data-state="min"] .cpop-bar:hover .cpop-btn[data-act="close"], .cpop[data-state="min"] .cpop-bar:focus-within .cpop-btn[data-act="close"] { opacity: 1; pointer-events: auto; }

.cpop-body { position: relative; flex: 1; min-height: 0; background: #fff; }
.cpop-frame { position: absolute; inset: 0; width: 100%; height: 100%; border: 0; background: #fff; opacity: 0; transition: opacity 0.15s; }
.cpop.is-ready .cpop-frame { opacity: 1; }
.cpop-loading { position: absolute; inset: 0; display: grid; place-items: center; font-size: 14px; color: var(--muted, #5b6075); }
.cpop.is-ready .cpop-loading { display: none; }

/* phones: Messages stays a normal full page */
@media (max-width: 759px) { .cpop { display: none !important; } }
@media (prefers-reduced-motion: reduce) { .cpop { animation: none; } .cpop-frame { transition: none; } }
/* collapsed bar: no dead area. The whole bar is one big "open Messages" button, and the X sits on top of its right end (only when hovering) */
.cpop[data-state="min"] .cpop-bar { position: relative; padding: 0; gap: 0; }
.cpop[data-state="min"] .cpop-title { height: 100%; padding: 0 15px; border-radius: 0; }
.cpop[data-state="min"] .cpop-tools { position: absolute; top: 0; right: 0; bottom: 0; gap: 0; }
.cpop[data-state="min"] .cpop-btn[data-act="close"] { width: 48px; height: 100%; border-radius: 0; }
</style>
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
<script>
/* Camigo floating chat window: Messages docked at the bottom right of every page.
   It shows the normal Messages page (messages?embed=1) in a frame, so everything in the chat works as it does there.
   The state (open / minimized, and which chat) is remembered for this tab, so it stays with you as you browse. */
(function () {
    "use strict";
    var root = document.getElementById("cpop");
    if (!root) return;

    var body = root.querySelector(".cpop-body");
    var countEl = root.querySelector(".cpop-count");
    var KEY = "camigo_chat_window";
    var MIN_WIDTH = 760;           // narrower screens keep the full Messages page
    var frame = null;
    var st = readState();          // { s: "open" | "min" | "", q: "?u=someone" }

    function readState() {
        try {
            var j = JSON.parse(sessionStorage.getItem(KEY) || "null");
            if (j && (j.s === "open" || j.s === "min")) return { s: j.s, q: typeof j.q === "string" ? j.q : "" };
        } catch (e) {}
        return { s: "", q: "" };
    }
    function save() {
        try { if (st.s) sessionStorage.setItem(KEY, JSON.stringify(st)); else sessionStorage.removeItem(KEY); } catch (e) {}
    }
    function wide() { return window.innerWidth >= MIN_WIDTH; }

    // "?u=sam&embed=1" -> "?u=sam"  (what we remember) and back to the frame's address
    function cleanQ(search) {
        var p = new URLSearchParams(search || "");
        p.delete("embed"); p.delete("file"); p.delete("check"); p.delete("draft"); p.delete("k");
        var s = p.toString();
        return s ? "?" + s : "";
    }
    function srcFor(q) {
        var p = new URLSearchParams(q || "");
        p.set("embed", "1");
        return "messages?" + p.toString();
    }

    /* ---------- the frame ---------- */
    function mount() {
        if (frame) return;
        frame = document.createElement("iframe");
        frame.className = "cpop-frame";
        frame.title = "Messages";
        frame.addEventListener("load", function () {
            root.classList.add("is-ready");
            try {   // remember which chat is showing, so the window comes back to it on the next page
                var loc = frame.contentWindow.location;
                if (/(^|\/)messages(\.php)?$/.test(loc.pathname)) { st.q = cleanQ(loc.search); save(); }
            } catch (e) {}
        });
        frame.src = srcFor(st.q);
        body.appendChild(frame);
    }
    function unmount() {
        if (frame) { frame.remove(); frame = null; }
        root.classList.remove("is-ready");
        watch("");
    }

    /* ---------- state -> screen ---------- */
    function render() {
        var show = st.s !== "" && wide();
        root.hidden = !show;
        root.setAttribute("data-state", st.s);
        var t = root.querySelector('[data-act="min"]');
        if (t) { var label = st.s === "min" ? "Open Messages" : "Minimize Messages"; t.title = label; t.setAttribute("aria-label", label); }
        if (show && st.s === "open") mount(); else unmount();
    }
    function openChat(search) {
        if (search !== null) {
            st.q = cleanQ(search);
            if (frame) frame.src = srcFor(st.q);
        }
        st.s = "open"; save(); render();
    }
    function minimize() { st.s = "min"; save(); render(); }
    function closeIt() { st.s = ""; st.q = ""; save(); render(); }
    function toggle() { if (st.s === "open") minimize(); else openChat(null); }
    function expand() {
        var q = st.q;
        st.s = ""; save();
        window.location.href = "messages" + q;
    }

    /* ---------- keep the page's unread badge honest ---------- */
    var dot = document.getElementById("msg-dot");
    function paintCount() {
        var t = (dot && !dot.hidden) ? dot.textContent : "";
        countEl.textContent = t;
        countEl.hidden = !t;
    }
    var RT = window.CamigoRT;
    if (RT && typeof RT.setBadge === "function") {
        var baseBadge = RT.setBadge;
        RT.setBadge = function (n) { baseBadge(n); paintCount(); };
    }
    paintCount();

    // the chat inside the frame tells us which conversation is open, so messages you're reading don't add to the badge
    function watch(v) {
        if (window.CamigoRT) window.CamigoRT.open = (v && st.s === "open" && wide()) ? v : "";
    }
    window.CamigoPop = { watch: watch };

    /* ---------- controls ---------- */
    root.addEventListener("click", function (e) {
        var b = e.target.closest ? e.target.closest("[data-act]") : null;
        if (!b) return;
        var a = b.getAttribute("data-act");
        if (a === "close") closeIt();
        else if (a === "min" || a === "toggle") toggle();
        else if (a === "expand") expand();
    });

    // any link to Messages on the site (the top bar icon, a profile's Message button, a club's Group chat...) opens the window
    document.addEventListener("click", function (e) {
        if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
        var a = e.target.closest ? e.target.closest("a[href]") : null;
        if (!a || root.contains(a)) return;
        if (a.target && a.target !== "_self") return;
        if (a.hasAttribute("download") || !wide()) return;
        var u;
        try { u = new URL(a.href, window.location.href); } catch (x) { return; }
        if (u.origin !== window.location.origin) return;
        if (!/(^|\/)messages(\.php)?$/.test(u.pathname)) return;
        if (u.searchParams.has("file") || u.searchParams.has("check") || u.searchParams.has("draft")) return;
        e.preventDefault();
        if (u.search === "") toggle();       // the top bar icon: open, or tuck it away again
        else openChat(u.search);             // a specific chat
    });

    window.addEventListener("resize", render);
    render();
})();
</script>