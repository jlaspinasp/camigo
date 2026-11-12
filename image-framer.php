<?php
// Shared image framing editor: the same one Edit profile uses.
// Click an image (or drop one), drag and zoom to frame it, press Apply. The framed image is what gets uploaded.
//
// Include once per page, near the end of <body>, before the page's own scripts. It defines CamigoPicker(options):
//   input, area, removeField, remove  element ids: the hidden file input, the click area, the remove_* hidden field, the x button
//   msg      id of the hint line used for error messages
//   shape    'wide' (10:3 banner, the default), 'round' (round photo) or 'square' (square logo)
//   title    heading of the framing window
//   fileName name of the framed file that is uploaded
//   show(url) and reset()  paint the preview when an image is chosen / removed
// The window's styles (.crop-*) live in style.css.
?>
<div class="crop-modal" id="crop" hidden role="dialog" aria-modal="true" aria-labelledby="crop-title">
    <div class="crop-card">
        <h3 id="crop-title">Frame your photo</h3>
        <p class="crop-help">Drag to move. Pinch, scroll or use the slider to zoom.</p>
        <div class="crop-stage" id="crop-stage" tabindex="0" aria-label="Image frame. Use arrow keys to move and plus or minus to zoom.">
            <img id="crop-img" alt="" draggable="false">
        </div>
        <div class="crop-zoom">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M8 12h8"/></svg>
            <input type="range" id="crop-zoom" min="0" max="1000" step="1" value="0" aria-label="Zoom">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M8 12h8M12 8v8"/></svg>
        </div>
        <div class="crop-actions">
            <button type="button" class="btn btn-ghost inline" id="crop-cancel">Cancel</button>
            <button type="button" class="btn inline" id="crop-ok">Apply</button>
        </div>
    </div>
</div>

<script>
window.CamigoPicker = (function () {
    var IMG_MAX = 10 * 1024 * 1024, TYPES = /^image\/(jpeg|png|webp)$/;
    var ed = document.getElementById("crop"), stage = document.getElementById("crop-stage"),
        eimg = document.getElementById("crop-img"), zoomEl = document.getElementById("crop-zoom"),
        etitle = document.getElementById("crop-title"),
        MAXZ = 6;                      // zoom goes from "fits the frame" up to 6x that
    var st = null;                     // the image being framed right now

    function clampPos() {
        st.x = Math.min(0, Math.max(st.sw - st.nw * st.s, st.x));
        st.y = Math.min(0, Math.max(st.sh - st.nh * st.s, st.y));
    }
    function paint() {
        eimg.style.transform = "translate(" + st.x + "px," + st.y + "px) scale(" + st.s + ")";
        zoomEl.value = Math.round((st.s / st.min - 1) / (MAXZ - 1) * 1000);
    }
    // change the zoom, keeping the point under (cx, cy) where it is
    function zoomAt(ns, cx, cy) {
        ns = Math.min(st.min * MAXZ, Math.max(st.min, ns));
        var px = (cx - st.x) / st.s, py = (cy - st.y) / st.s;
        st.s = ns; st.x = cx - px * ns; st.y = cy - py * ns;
        clampPos(); paint();
    }
    function measure() {
        var r = stage.getBoundingClientRect();
        st.sw = r.width; st.sh = r.height;
        st.min = Math.max(st.sw / st.nw, st.sh / st.nh);
    }

    var onKey = null;
    function closeEditor() {
        ed.hidden = true; document.body.style.overflow = "";
        if (st) { URL.revokeObjectURL(st.url); eimg.removeAttribute("src"); }
        st = null;
        if (onKey) { document.removeEventListener("keydown", onKey); onKey = null; }
        window.removeEventListener("resize", onResize);
    }
    function onResize() {
        if (!st) return;
        var ratio = st.s / st.min, cx = (st.sw / 2 - st.x) / st.s, cy = (st.sh / 2 - st.y) / st.s;
        measure();
        st.s = st.min * ratio;
        st.x = st.sw / 2 - cx * st.s; st.y = st.sh / 2 - cy * st.s;
        clampPos(); paint();
    }

    // frame(file, {shape, title, name, say}, done): done(file) on Apply, done(null) on Cancel or if the image can't be read
    function frame(file, o, done) {
        var url = URL.createObjectURL(file), im = new Image();
        im.onerror = function () {
            URL.revokeObjectURL(url);
            o.say("We couldn't read that image. Please try a JPG, PNG or WebP.");
            done(null);
        };
        im.onload = function () {
            st = { url: url, img: im, nw: im.naturalWidth, nh: im.naturalHeight, o: o, done: done, x: 0, y: 0, s: 1, sw: 0, sh: 0, min: 1 };
            etitle.textContent = o.title;
            stage.className = "crop-stage" + (o.shape === "round" ? " round" : (o.shape === "square" ? " square" : ""));
            eimg.style.width = st.nw + "px"; eimg.style.height = st.nh + "px";
            eimg.src = url;
            ed.hidden = false; document.body.style.overflow = "hidden";
            measure();
            st.s = st.min;
            st.x = (st.sw - st.nw * st.s) / 2; st.y = (st.sh - st.nh * st.s) / 2;   // start centred
            clampPos(); paint();
            stage.focus({ preventScroll: true });
            window.addEventListener("resize", onResize);
            onKey = function (e) { if (e.key === "Escape") cancel(); };
            document.addEventListener("keydown", onKey);
        };
        im.src = url;
    }

    function cancel() { var d = st && st.done; closeEditor(); if (d) d(null); }

    function apply() {
        if (!st) return;
        var s = st, o = s.o;
        var w = s.sw / s.s, h = s.sh / s.s;
        var sx = Math.min(Math.max(0, -s.x / s.s), s.nw - w), sy = Math.min(Math.max(0, -s.y / s.s), s.nh - h);
        var ow, oh;
        if (o.shape === "round" || o.shape === "square") { ow = oh = Math.max(1, Math.min(1200, Math.round(w))); }                // up to 1200px, never scaled up, only down
        else                                              { ow = Math.max(10, Math.min(3840, Math.floor(w / 10) * 10)); oh = ow * 3 / 10; }   // up to 4K wide, exactly 10:3
        var c = document.createElement("canvas"); c.width = ow; c.height = oh;
        var ctx = c.getContext("2d");
        ctx.fillStyle = "#fff"; ctx.fillRect(0, 0, ow, oh);     // PNG transparency -> white, same as the server does
        ctx.imageSmoothingEnabled = true; ctx.imageSmoothingQuality = "high";
        ctx.drawImage(s.img, sx, sy, w, h, 0, 0, ow, oh);
        var d = s.done;
        // ultra HD: quality 0.97 first. If a very detailed photo ever tops the 10 MB limit, step the quality down until it fits.
        (function enc(q) {
            c.toBlob(function (blob) {
                if (blob && blob.size > 9.5 * 1024 * 1024 && q > 0.6) return enc(q - 0.1);
                closeEditor();
                if (!blob) { o.say("We couldn't prepare that image. Please try again."); d(null); return; }
                d(new File([blob], o.name, { type: "image/jpeg" }));
            }, "image/jpeg", q);
        })(0.97);
    }

    document.getElementById("crop-ok").addEventListener("click", apply);
    document.getElementById("crop-cancel").addEventListener("click", cancel);
    zoomEl.addEventListener("input", function () {
        if (st) zoomAt(st.min * (1 + (zoomEl.value / 1000) * (MAXZ - 1)), st.sw / 2, st.sh / 2);
    });
    stage.addEventListener("wheel", function (e) {            // mouse wheel / trackpad pinch
        if (!st) return;
        e.preventDefault();
        var r = stage.getBoundingClientRect();
        zoomAt(st.s * Math.exp(-e.deltaY * (e.ctrlKey ? 0.01 : 0.0015)), e.clientX - r.left, e.clientY - r.top);
    }, { passive: false });
    stage.addEventListener("keydown", function (e) {
        if (!st) return;
        var step = 20, k = e.key, used = true;
        if (k === "ArrowLeft") st.x += step; else if (k === "ArrowRight") st.x -= step;
        else if (k === "ArrowUp") st.y += step; else if (k === "ArrowDown") st.y -= step;
        else if (k === "+" || k === "=") { zoomAt(st.s * 1.1, st.sw / 2, st.sh / 2); return e.preventDefault(); }
        else if (k === "-" || k === "_") { zoomAt(st.s / 1.1, st.sw / 2, st.sh / 2); return e.preventDefault(); }
        else used = false;
        if (used) { e.preventDefault(); clampPos(); paint(); }
    });

    // one finger / mouse = move, two fingers = pinch to zoom (and move)
    var ptrs = {};
    stage.addEventListener("pointerdown", function (e) {
        if (!st) return;
        try { stage.setPointerCapture(e.pointerId); } catch (err) {}
        ptrs[e.pointerId] = { x: e.clientX, y: e.clientY };
        stage.classList.add("dragging");
    });
    stage.addEventListener("pointermove", function (e) {
        var p = ptrs[e.pointerId];
        if (!st || !p) return;
        var ids = Object.keys(ptrs);
        if (ids.length === 1) {
            st.x += e.clientX - p.x; st.y += e.clientY - p.y;
            p.x = e.clientX; p.y = e.clientY;
            clampPos(); paint();
        } else {
            var a = ptrs[ids[0]], b = ptrs[ids[1]];
            var d0 = Math.hypot(a.x - b.x, a.y - b.y), m0x = (a.x + b.x) / 2, m0y = (a.y + b.y) / 2;
            p.x = e.clientX; p.y = e.clientY;
            var d1 = Math.hypot(a.x - b.x, a.y - b.y), m1x = (a.x + b.x) / 2, m1y = (a.y + b.y) / 2;
            var r = stage.getBoundingClientRect();
            if (d0 > 0) zoomAt(st.s * (d1 / d0), m0x - r.left, m0y - r.top);
            st.x += m1x - m0x; st.y += m1y - m0y;
            clampPos(); paint();
        }
    });
    function lift(e) {
        delete ptrs[e.pointerId];
        if (!Object.keys(ptrs).length) stage.classList.remove("dragging");
    }
    stage.addEventListener("pointerup", lift);
    stage.addEventListener("pointercancel", lift);

    // ---------- one picker per image ----------
    return function picker(o) {
        var input = document.getElementById(o.input), area = document.getElementById(o.area), rf = document.getElementById(o.removeField);
        var msg = o.msg ? document.getElementById(o.msg) : null, msgText = msg ? msg.textContent : "";
        var good = null;     // the last framed image that was accepted, so Cancel can put it back
        if (!input || !area) return;

        function say(t) { if (msg) { msg.textContent = t; msg.classList.add("bad"); } }
        function calm() { if (msg) { msg.textContent = msgText; msg.classList.remove("bad"); } }
        function setFiles(f) { var dt = new DataTransfer(); dt.items.add(f); input.files = dt.files; }
        function restore() {
            try { if (good) { setFiles(good); } else { input.value = ""; } } catch (err) { input.value = ""; }
        }

        function accept(f) {
            if (!f) return false;
            if (!TYPES.test(f.type)) { say("Please choose a JPG, PNG or WebP image."); return false; }
            if (f.size > IMG_MAX) { say("That image is over 10 MB. Please choose a smaller one."); return false; }
            calm();
            frame(f, { shape: o.shape || "wide", title: o.title, name: o.fileName, say: say }, function (framed) {
                if (!framed) { restore(); return; }          // cancelled: keep whatever was there before
                try {
                    setFiles(framed); good = framed;
                    rf.value = "0"; o.show(URL.createObjectURL(framed));
                } catch (err) {
                    // very old browser: can't swap the file, so the original goes up and the server centres it
                    good = f; rf.value = "0"; o.show(URL.createObjectURL(f));
                    say("Your browser can't save the framing, so this image will be centred instead.");
                }
            });
            return true;
        }
        input.addEventListener("change", function () {
            var f = input.files && input.files[0];
            if (f && !accept(f)) restore();
        });
        area.addEventListener("click", function () { input.click(); });
        area.addEventListener("keydown", function (e) {
            if (e.key === "Enter" || e.key === " ") { e.preventDefault(); input.click(); }
        });
        ["dragenter", "dragover"].forEach(function (t) { area.addEventListener(t, function (e) { e.preventDefault(); area.classList.add("id-drag"); }); });
        ["dragleave", "drop"].forEach(function (t) { area.addEventListener(t, function () { area.classList.remove("id-drag"); }); });
        area.addEventListener("drop", function (e) {
            e.preventDefault();
            var f = e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[0];
            if (!f) return;
            if (!accept(f)) restore();
        });
        document.getElementById(o.remove).addEventListener("click", function (e) {
            e.stopPropagation(); input.value = ""; good = null; rf.value = "1"; calm(); o.reset();
        });
    };
})();
</script>