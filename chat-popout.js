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