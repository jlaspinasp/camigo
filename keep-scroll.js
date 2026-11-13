/* Search / filter boxes on Clubs, Projects, Events and Students, and the "I'm going" / "Cancel" buttons
   on Events, reload the page. Remember where the person was scrolled and put it back after the reload. */
(function () {
    var KEY = "keep-scroll";

    function save() {
        try {
            sessionStorage.setItem(KEY, JSON.stringify({ p: location.pathname, y: Math.round(window.pageYOffset), t: Date.now() }));
        } catch (e) {}
    }

    document.addEventListener("submit", function (e) {
        var f = e.target;
        if (!f || !f.matches) return;
        if (f.matches('form[role="search"]') || f.querySelector('button[value="rsvp"], button[value="unrsvp"]')) save();
    });
    document.addEventListener("click", function (e) {
        if (e.target.closest && e.target.closest('form[role="search"] a.cancel')) save();
    });

    var saved = null;
    try { saved = JSON.parse(sessionStorage.getItem(KEY) || "null"); sessionStorage.removeItem(KEY); } catch (e) {}
    if (!saved || saved.p !== location.pathname || !(saved.y > 0) || Date.now() - saved.t > 15000) return;

    if ("scrollRestoration" in history) history.scrollRestoration = "manual";
    var tries = 0;
    (function go() {
        window.scrollTo(0, saved.y);
        if (Math.abs(window.pageYOffset - saved.y) > 2 && tries++ < 8) setTimeout(go, 100);
    })();
})();