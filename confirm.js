/*
 * Shared confirmation dialogs (replaces the browser's native confirm()).
 *
 * Markup: add data-confirm to a <form> or a submit <button>.
 *
 *   <form method="POST"
 *         data-confirm="This person will be removed from the team."   (body text)
 *         data-confirm-title="Remove from team?"                       (heading)
 *         data-confirm-ok="Remove"                                     (confirm button label)
 *         data-confirm-cancel="Cancel"                                 (optional)
 *         data-confirm-tone="warning"                                  (danger | warning | info)
 *         data-confirm-icon="userminus">                               (optional)
 *
 * Script: UIConfirm.ask({ title, text, ok, cancel, tone, icon, run }) -> Promise<boolean>
 *   run (optional) is an async action; the dialog shows a busy state and stays open if it returns false.
 *
 * Tone guide:  danger  = permanent / cannot be undone   (red)
 *              warning = removes access or is hard to undo (amber)
 *              info    = routine, reversible              (indigo)
 */
(function () {
    "use strict";
    if (window.UIConfirm) return;

    var SVG_OPEN = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">';
    var ICONS = {
        trash: '<path d="M4 7h16"/><path d="M10 11v6M14 11v6"/><path d="M6 7l1 12a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2l1-12"/><path d="M9 7V4h6v3"/>',
        alert: '<path d="M12 3.5 2.8 19.5a1 1 0 0 0 .9 1.5h16.6a1 1 0 0 0 .9-1.5L12 3.5z"/><path d="M12 10v4.2"/><path d="M12 17.4v.1"/>',
        exit: '<path d="M9 4H6a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h3"/><path d="M16 8l4 4-4 4"/><path d="M20 12H9"/>',
        userminus: '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20a6.5 6.5 0 0 1 13 0"/><path d="M16.5 11h5"/>',
        star: '<path d="M12 3.5l2.6 5.3 5.9.9-4.3 4.1 1 5.8L12 16.8 6.8 19.6l1-5.8-4.3-4.1 5.9-.9L12 3.5z"/>',
        block: '<circle cx="12" cy="12" r="8.5"/><path d="M6 6l12 12"/>',
        info: '<circle cx="12" cy="12" r="8.5"/><path d="M12 11v5"/><path d="M12 7.8v.1"/>'
    };
    var DEFAULT_ICON = { danger: "trash", warning: "alert", info: "info" };

    var active = null;

    function node(tag, cls, text) {
        var n = document.createElement(tag);
        if (cls) n.className = cls;
        if (text != null) n.textContent = text;
        return n;
    }

    function ask(o) {
        o = o || {};
        if (active) active(false);

        return new Promise(function (resolve) {
            var tone = o.tone === "danger" || o.tone === "info" ? o.tone : "warning";
            var iconName = ICONS[o.icon] ? o.icon : DEFAULT_ICON[tone];
            var uid = "cf" + Math.random().toString(36).slice(2, 8);
            var prevFocus = document.activeElement;
            var busy = false;

            var overlay = node("div", "cfm-overlay");
            var card = node("div", "cfm-card cfm-" + tone);
            card.setAttribute("role", "alertdialog");
            card.setAttribute("aria-modal", "true");
            card.setAttribute("aria-labelledby", uid + "-t");

            var body = node("div", "cfm-body");
            var xBtn = node("button", "cfm-x");
            xBtn.type = "button";
            xBtn.setAttribute("aria-label", "Close");
            xBtn.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg>';

            var head = node("div", "cfm-head");
            var icon = node("span", "cfm-icon");
            icon.innerHTML = "<span>" + SVG_OPEN + ICONS[iconName] + "</svg></span>";
            var copy = node("div", "cfm-copy");
            var title = node("h2", "cfm-title", o.title || "Are you sure?");
            title.id = uid + "-t";
            copy.appendChild(title);
            if (o.text) {
                var text = node("p", "cfm-text", o.text);
                text.id = uid + "-d";
                card.setAttribute("aria-describedby", uid + "-d");
                copy.appendChild(text);
            }
            head.appendChild(icon);
            head.appendChild(copy);

            var foot = node("div", "cfm-foot");
            var cancel = node("button", "cfm-btn cfm-btn-ghost", o.cancel || "Cancel");
            cancel.type = "button";
            var ok = node("button", "cfm-btn cfm-btn-" + tone, o.ok || "Confirm");
            ok.type = "button";
            // Routine questions read "Cancel | Confirm"; destructive ones lead with the action: "Delete | Cancel".
            if (tone === "info") { foot.appendChild(cancel); foot.appendChild(ok); }
            else { foot.appendChild(ok); foot.appendChild(cancel); }

            body.appendChild(xBtn);
            body.appendChild(head);
            body.appendChild(foot);
            card.appendChild(body);
            overlay.appendChild(card);

            function close(result) {
                if (active !== close) return;
                active = null;
                window.removeEventListener("keydown", onKey, true);
                overlay.remove();
                document.body.classList.remove("cfm-lock");
                if (prevFocus && document.contains(prevFocus) && prevFocus.focus) {
                    try { prevFocus.focus(); } catch (e) { /* ignore */ }
                }
                resolve(result);
            }

            function onKey(e) {
                if (e.key === "Escape") {
                    e.preventDefault();
                    e.stopImmediatePropagation();
                    if (!busy) close(false);
                } else if (e.key === "Tab") {
                    var order = tone === "info" ? [cancel, ok, xBtn] : [ok, cancel, xBtn];
                    var i = order.indexOf(document.activeElement);
                    e.preventDefault();
                    if (busy) return;
                    var next = e.shiftKey ? (i <= 0 ? order.length - 1 : i - 1) : (i === order.length - 1 ? 0 : i + 1);
                    order[next].focus();
                }
            }

            cancel.addEventListener("click", function () { if (!busy) close(false); });
            xBtn.addEventListener("click", function () { if (!busy) close(false); });
            overlay.addEventListener("mousedown", function (e) { if (e.target === overlay && !busy) close(false); });
            ok.addEventListener("click", function () {
                if (busy) return;
                if (typeof o.run !== "function") { close(true); return; }
                busy = true;
                ok.disabled = true;
                ok.classList.add("is-busy");
                Promise.resolve().then(o.run).then(function (keep) {
                    if (keep === false) { busy = false; ok.disabled = false; ok.classList.remove("is-busy"); return; }
                    busy = false;
                    close(true);
                }, function () {
                    busy = false; ok.disabled = false; ok.classList.remove("is-busy");
                });
            });

            active = close;
            document.body.appendChild(overlay);
            document.body.classList.add("cfm-lock");
            window.addEventListener("keydown", onKey, true);
            // Safer default: the harmless choice gets focus unless the action is routine.
            (tone === "info" ? ok : cancel).focus();
        });
    }

    /* ---------- declarative use: data-confirm on a form or a submit button ---------- */
    function read(n) {
        var d = n.dataset;
        return { title: d.confirmTitle, text: d.confirm, ok: d.confirmOk, cancel: d.confirmCancel, tone: d.confirmTone, icon: d.confirmIcon };
    }

    function send(form, submitter) {
        form.__cfOk = true;
        try {
            if (form.requestSubmit) {
                form.requestSubmit(submitter || undefined);
            } else {
                if (submitter && submitter.name) {
                    var h = document.createElement("input");
                    h.type = "hidden"; h.name = submitter.name; h.value = submitter.value;
                    form.appendChild(h);
                }
                form.submit();
            }
        } finally {
            form.__cfOk = false;
        }
    }

    // A submit button carrying data-confirm (for forms that hold several actions).
    document.addEventListener("click", function (e) {
        var b = e.target.closest ? e.target.closest("button[data-confirm], input[type=submit][data-confirm]") : null;
        if (!b || b.disabled || !b.form) return;
        e.preventDefault();
        ask(read(b)).then(function (yes) { if (yes) send(b.form, b); });
    }, true);

    // A whole form carrying data-confirm.
    document.addEventListener("submit", function (e) {
        var f = e.target;
        if (!f || !f.hasAttribute || !f.hasAttribute("data-confirm") || f.__cfOk) return;
        e.preventDefault();
        var submitter = e.submitter || null;
        ask(read(f)).then(function (yes) { if (yes) send(f, submitter); });
    }, true);

    window.UIConfirm = { ask: ask };
})();