/* Camigo messages: chat list, thread, composer (emoji + attachments), groups, unsend, block, mute. */
(function () {
    "use strict";
    var B = window.CHAT_BOOT;
    if (!B) return;
    var RT = window.CamigoRT || null;

    var listEl = document.getElementById("chat-items");
    var paneEl = document.getElementById("chat-pane");
    var chatEl = document.getElementById("chat");
    var modalEl = document.getElementById("modal");
    var cardEl = document.getElementById("modal-card");
    var toastEl = document.getElementById("toast");

    var S = {
        convos: B.convos || [],
        filter: "all",
        q: "",
        open: (B.open && !B.open.missing) ? B.open : null,
        msgs: [],
        seen: false,
        files: [],
        tmp: 0
    };
    if (S.open) { S.msgs = (S.open.thread || []).slice(); S.seen = !!S.open.seen; }

    var el = {};   // live nodes of the open chat (thread, composer...)

    /* ---------------------------------------------------------------- helpers */
    function h(tag, a, kids) {
        var n = document.createElement(tag);
        if (a) for (var k in a) {
            var v = a[k];
            if (v === null || v === undefined || v === false) continue;
            if (k === "class") n.className = v;
            else if (k === "text") n.textContent = v;
            else if (k === "html") n.innerHTML = v;
            else if (k.slice(0, 2) === "on") n.addEventListener(k.slice(2), v);
            else n.setAttribute(k, v === true ? "" : v);
        }
        (kids || []).forEach(function (c) {
            if (c === null || c === undefined || c === false) return;
            n.appendChild(typeof c === "string" ? document.createTextNode(c) : c);
        });
        return n;
    }

    var ICONS = {
        clip: '<path d="M20 11.5l-7.8 7.8a4.6 4.6 0 01-6.5-6.5l8-8a3.1 3.1 0 014.4 4.4l-8 8a1.5 1.5 0 01-2.2-2.2l7.3-7.3"/>',
        smile: '<circle cx="12" cy="12" r="9"/><path d="M8.5 14c.9 1.4 2 2.1 3.5 2.1s2.6-.7 3.5-2.1"/><path d="M9 9.5h.01M15 9.5h.01"/>',
        send: '<path d="M4 12l16-8-6 16-3-7-7-1z"/><path d="M11 13l9-9"/>',
        like: '<path d="M7 11v9H4v-9h3zM7 11l4-7c1.5 0 2.5 1 2.2 2.6L12.8 9H19a1.7 1.7 0 011.7 2.1l-1.5 7A2 2 0 0117.3 20H7"/>',
        more: '<circle cx="5" cy="12" r="1.4"/><circle cx="12" cy="12" r="1.4"/><circle cx="19" cy="12" r="1.4"/>',
        info: '<circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 7.8h.01"/>',
        bell: '<path d="M6 16v-5a6 6 0 0112 0v5l1.5 2h-15L6 16zM10 21h4"/>',
        bellOff: '<path d="M6 16v-5c0-1.2.3-2.3.9-3.2M9.2 5.4A6 6 0 0118 11v5l1.5 2H8M10 21h4M4 4l16 16"/>',
        block: '<circle cx="12" cy="12" r="9"/><path d="M5.6 5.6l12.8 12.8"/>',
        trash: '<path d="M4 7h16M9 7V4.5h6V7M6.5 7l1 13h9l1-13M10 11v6M14 11v6"/>',
        undo: '<path d="M9 8L4 12.5 9 17M4.5 12.5H15a5 5 0 010 10"/>',
        copy: '<rect x="8" y="8" width="11" height="12" rx="2"/><path d="M5 15V6a2 2 0 012-2h8"/>',
        download: '<path d="M12 4v11M7.5 11L12 15.5 16.5 11M5 20h14"/>',
        close: '<path d="M6 6l12 12M18 6L6 18"/>',
        group: '<circle cx="9" cy="8" r="3.2"/><path d="M3 19c0-3 2.7-5 6-5s6 2 6 5"/><path d="M16 5a3 3 0 010 6M18 14c2 .6 3 2.2 3 5"/>',
        plus: '<path d="M12 5v14M5 12h14"/>',
        exit: '<path d="M10 4H6a2 2 0 00-2 2v12a2 2 0 002 2h4M15 8l4 4-4 4M19 12H9"/>',
        chat: '<path d="M5 4.5h14a1.5 1.5 0 011.5 1.5v9a1.5 1.5 0 01-1.5 1.5h-8L6.5 20.5V16.5H5A1.5 1.5 0 013.5 15V6A1.5 1.5 0 015 4.5z"/>',
        check: '<path d="M5 12.5l4.5 4.5L19 7.5"/>',
        chev: '<path d="M9 6l6 6-6 6"/>',
        image: '<rect x="3.5" y="5" width="17" height="14" rx="2.2"/><circle cx="9" cy="10" r="1.6"/><path d="M4 17l5-4.5 3.5 3L16 12l4 4.5"/>',
        camera: '<path d="M4 8.5A1.5 1.5 0 015.5 7H8l1.2-2h5.6L16 7h2.5A1.5 1.5 0 0120 8.5v9a1.5 1.5 0 01-1.5 1.5h-13A1.5 1.5 0 014 17.5v-9z"/><circle cx="12" cy="13" r="3.2"/>',
        club: '<path d="M12 3l2.6 5.3 5.8.8-4.2 4.1 1 5.8L12 16.3 6.8 19l1-5.8L3.6 9.1l5.8-.8L12 3z"/>'
    };
    function ico(name, cls) {
        return h("span", { class: "ico" + (cls ? " " + cls : ""), html: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + ICONS[name] + "</svg>" });
    }
    function iconBtn(name, label, fn, cls) {
        return h("button", { type: "button", class: "icon-btn" + (cls ? " " + cls : ""), title: label, "aria-label": label, onclick: fn }, [ico(name)]);
    }

    function pad(n) { return n < 10 ? "0" + n : "" + n; }
    function fmtTime(ts) {
        var d = new Date(ts * 1000), hr = d.getHours(), ap = hr >= 12 ? "PM" : "AM";
        return (hr % 12 || 12) + ":" + pad(d.getMinutes()) + " " + ap;
    }
    function dayKey(ts) { var d = new Date(ts * 1000); return d.getFullYear() + "-" + d.getMonth() + "-" + d.getDate(); }
    function dayLabel(ts) {
        var now = new Date(), y = new Date(); y.setDate(now.getDate() - 1);
        if (dayKey(ts) === dayKey(now / 1000)) return "Today";
        if (dayKey(ts) === dayKey(y / 1000)) return "Yesterday";
        return new Date(ts * 1000).toLocaleDateString(undefined, { weekday: "short", month: "short", day: "numeric", year: new Date(ts * 1000).getFullYear() === now.getFullYear() ? undefined : "numeric" });
    }
    function listTime(ts) {
        var now = Date.now() / 1000, d = now - ts;
        if (dayKey(ts) === dayKey(now)) return fmtTime(ts);
        if (d < 6 * 86400) return new Date(ts * 1000).toLocaleDateString(undefined, { weekday: "short" });
        return new Date(ts * 1000).toLocaleDateString(undefined, { month: "short", day: "numeric" });
    }
    function oneLine(s, n) { s = String(s).replace(/\s+/g, " ").trim(); return s.length > n ? s.slice(0, n - 1) + "\u2026" : s; }
    function first(name) { return String(name || "").split(" ")[0]; }
    function sizeLabel(b) {
        if (b >= 1048576) return (Math.round(b / 104857.6) / 10) + " MB";
        if (b >= 1024) return Math.max(1, Math.round(b / 1024)) + " KB";
        return b + " B";
    }
    function ext(name) { var m = /\.([a-z0-9]+)$/i.exec(name || ""); return m ? m[1].toLowerCase() : ""; }
    function profileUrl(u) { return "profile?u=" + encodeURIComponent(u); }
    function chatUrl(k, id, username) { return k === "g" ? "messages?g=" + id : "messages?u=" + encodeURIComponent(username); }
    function same(c, k, id) { return c && c.k === k && c.id === id; }
    function openIs(k, id) { return same(S.open, k, id); }

    var toastTimer = null;
    function toast(msg) {
        toastEl.textContent = msg; toastEl.hidden = false;
        clearTimeout(toastTimer);
        toastTimer = setTimeout(function () { toastEl.hidden = true; }, 3600);
    }

    function api(act, data, file) {
        var fd = new FormData();
        fd.append("act", act); fd.append("csrf", B.csrf);
        for (var k in (data || {})) {
            var v = data[k];
            if (Array.isArray(v)) v.forEach(function (x) { fd.append(k + "[]", x); });
            else if (v !== null && v !== undefined) fd.append(k, typeof v === "boolean" ? (v ? "1" : "") : v);
        }
        if (file) fd.append("file", file, file.name);
        return fetch(window.location.pathname, { method: "POST", body: fd, credentials: "same-origin" })
            .then(function (r) { return r.json(); })
            .catch(function () { return { ok: false, error: "Network problem. Check your connection and try again." }; });
    }

    /* ---------------------------------------------------------------- avatars */
    function avatar(c, extra) {
        var a = h("div", { class: "dir-avatar av" + (extra ? " " + extra : "") });
        if (c.k === "g") {
            if (c.logo) { a.classList.add("has-img"); a.appendChild(h("img", { src: c.logo, alt: "" })); }
            else {
                var hue = c.hue === undefined ? (c.id * 47) % 360 : c.hue;
                a.classList.add("grp");
                a.style.background = "linear-gradient(135deg, hsl(" + hue + " 78% 62%), hsl(" + ((hue + 45) % 360) + " 76% 46%))";
                a.appendChild(document.createTextNode((c.name || "G").charAt(0).toUpperCase()));
            }
        } else if (c.photo) {
            a.appendChild(h("img", { src: "uploads/avatars/" + c.photo, alt: "" }));
        } else {
            a.appendChild(document.createTextNode(c.initial || (c.name || "?").charAt(0).toUpperCase()));
        }
        return a;
    }
    function personAvatar(p, extra) { return avatar({ k: "u", photo: p.photo, initial: p.initial, name: p.name }, extra); }

    /* ---------------------------------------------------------------- menus + modals */
    var menuEl = null;
    function closeMenu() { if (menuEl) { menuEl.remove(); menuEl = null; } }
    function openMenu(anchor, items) {
        closeMenu();
        var m = h("div", { class: "menu", role: "menu" });
        items.forEach(function (it) {
            if (it === "-") { m.appendChild(h("div", { class: "menu-sep" })); return; }
            m.appendChild(h("button", { type: "button", role: "menuitem", class: "menu-item" + (it.danger ? " danger" : ""), onclick: function () { closeMenu(); it.fn(); } }, [ico(it.icon), h("span", { text: it.label })]));
        });
        document.body.appendChild(m);
        var r = anchor.getBoundingClientRect(), mw = m.offsetWidth, mh = m.offsetHeight;
        var left = Math.min(Math.max(8, r.right - mw), window.innerWidth - mw - 8);
        var top = r.bottom + 6;
        if (top + mh > window.innerHeight - 8) top = Math.max(8, r.top - mh - 6);
        m.style.left = left + "px"; m.style.top = top + "px";
        menuEl = m;
        var fb = m.querySelector("button"); if (fb) fb.focus();
    }
    document.addEventListener("mousedown", function (e) { if (menuEl && !menuEl.contains(e.target)) closeMenu(); });
    window.addEventListener("resize", closeMenu);

    function closeModal() { modalEl.hidden = true; cardEl.innerHTML = ""; document.body.classList.remove("modal-open"); }
    function openModal(nodes, wide) {
        closeMenu();
        cardEl.innerHTML = "";
        cardEl.className = "modal-card" + (wide ? " wide" : "");
        nodes.forEach(function (n) { cardEl.appendChild(n); });
        modalEl.hidden = false;
        document.body.classList.add("modal-open");
        var f = cardEl.querySelector("input, textarea, button.primary");
        if (f) setTimeout(function () { f.focus(); }, 30);
    }
    modalEl.addEventListener("mousedown", function (e) { if (e.target === modalEl) closeModal(); });
    document.addEventListener("keydown", function (e) {
        if (e.key !== "Escape") return;
        if (menuEl) closeMenu();
        else if (!modalEl.hidden) closeModal();
        else if (el.emoji && !el.emoji.hidden) toggleEmoji(false);
    });
    function modalHead(title, sub) {
        return h("div", { class: "modal-head" }, [
            h("div", null, [h("h2", { text: title }), sub ? h("p", { text: sub }) : null]),
            iconBtn("close", "Close", closeModal)
        ]);
    }
    function confirmBox(o) {
        var ok = h("button", { type: "button", class: "btn-sm " + (o.danger ? "btn-danger" : "btn-dark") + " primary", text: o.ok });
        ok.addEventListener("click", function () {
            ok.disabled = true;
            Promise.resolve(o.fn()).then(function (keep) { if (keep !== false) closeModal(); else ok.disabled = false; });
        });
        openModal([
            h("div", { class: "modal-body center" }, [h("h2", { text: o.title }), h("p", { class: "muted", text: o.text })]),
            h("div", { class: "modal-foot" }, [h("button", { type: "button", class: "btn-sm btn-ghost", text: "Cancel", onclick: closeModal }), ok])
        ]);
    }

    /* ---------------------------------------------------------------- chat list */
    function previewText(c) {
        var l = c.last;
        if (!l) return "No messages yet";
        if (l.kind === "sys") return l.body;
        var who = l.mine ? "You" : (l.sender || "");
        if (l.gone) return (who || "Someone") + " unsent a message";
        if (!l.body) return (who ? who + " " : "") + (l.file === "image" ? "sent a photo" : "sent an attachment");
        return (l.mine ? "You: " : (c.k === "g" && who ? who + ": " : "")) + oneLine(l.body, 70);
    }

    function renderList() {
        var q = S.q.toLowerCase();
        var rows = S.convos.slice().sort(function (a, b) { return b.ts - a.ts; }).filter(function (c) {
            if (S.filter === "unread" && !(c.unread > 0)) return false;
            if (S.filter === "groups" && c.k !== "g") return false;
            if (q && (c.name + " " + (c.username || "")).toLowerCase().indexOf(q) < 0) return false;
            return true;
        });

        var unreadN = S.convos.filter(function (c) { return c.unread > 0; }).length;
        var tn = document.getElementById("tab-unread");
        if (tn) { tn.hidden = unreadN === 0; tn.textContent = unreadN; }

        listEl.innerHTML = "";
        if (!rows.length) {
            var msg = S.q ? "No chats match your search." : S.filter === "unread" ? "You're all caught up. No unread chats." : S.filter === "groups" ? "No groups yet. Create one, or join a club to get its group chat." : "No conversations yet. Find someone in the student directory and say hi.";
            listEl.appendChild(h("div", { class: "chat-none" }, [h("p", { text: msg }), S.filter === "groups" ? h("button", { type: "button", class: "btn-sm btn-dark", text: "Create group", onclick: newGroupModal }) : null]));
            return;
        }
        rows.forEach(function (c) {
            var active = openIs(c.k, c.id);
            var a = h("a", { href: chatUrl(c.k, c.id, c.username), class: "convo" + (active ? " active" : "") + (c.unread > 0 ? " unread" : "") + (c.muted ? " muted" : ""), "data-k": c.k, "data-id": c.id });
            a.appendChild(avatar(c));
            var top = h("div", { class: "convo-top" }, [
                h("strong", { text: c.name }),
                c.k === "g" && (c.club || c.project) ? h("span", { class: "tag-club", text: c.club ? "Club" : "Project" }) : null,
                c.muted ? ico("bellOff", "mute-ico") : null,
                h("span", { class: "convo-time", text: listTime(c.ts) })
            ]);
            a.appendChild(h("div", { class: "convo-main" }, [top, h("div", { class: "convo-prev", text: previewText(c) })]));
            if (c.unread > 0) a.appendChild(h("span", { class: "convo-badge", text: c.unread > 9 ? "9+" : c.unread }));
            listEl.appendChild(a);
        });
    }

    function findConvo(k, id) {
        for (var i = 0; i < S.convos.length; i++) if (same(S.convos[i], k, id)) return S.convos[i];
        return null;
    }
    function ensureConvo(k, id, info) {
        var c = findConvo(k, id);
        if (!c) {
            c = k === "g"
                ? { k: "g", id: id, name: info.name, hue: (id * 47) % 360, logo: info.logo || "", club: info.club || 0, project: info.project || 0, members: info.members || 0, unread: 0, muted: false, ts: 0, last: null }
                : { k: "u", id: id, name: info.name, username: info.username, photo: info.photo, initial: info.initial, unread: 0, muted: false, blocked: false, ts: 0, last: null };
            S.convos.push(c);
        }
        return c;
    }
    function setLast(c, m, mine) {
        c.last = { mine: mine, kind: m.kind || "msg", gone: !!m.gone, body: m.body || "", file: m.file ? (m.file.image ? "image" : "file") : false, sender: m.name ? first(m.name) : (c.k === "u" ? first(c.name) : "") };
        c.mid = m.id; c.ts = m.ts || Math.floor(Date.now() / 1000);
    }

    /* ---------------------------------------------------------------- emoji data + helpers */
    var EMOJI = {
        "Smileys": "😀 😃 😄 😁 😆 😅 😂 🤣 🥲 😊 😇 🙂 🙃 😉 😌 😍 🥰 😘 😗 😙 😚 😋 😛 😝 😜 🤪 🤨 🧐 🤓 😎 🥸 🤩 🥳 😏 😒 😞 😔 😟 😕 🙁 ☹️ 😣 😖 😫 😩 🥺 😢 😭 😤 😠 😡 🤬 🤯 😳 🥵 🥶 😱 😨 😰 😥 😓 🤗 🤔 🫡 🤭 🫢 🤫 🤥 😶 😐 😑 😬 🙄 😯 😦 😧 😮 😲 🥱 😴 🤤 😪 😵 🤐 🥴 🤢 🤮 🤧 😷 🤒 🤕",
        "Gestures": "👍 👎 👌 🤌 🤏 ✌️ 🤞 🫰 🤟 🤘 🤙 👈 👉 👆 👇 ☝️ ✋ 🤚 🖐️ 🖖 👋 🤝 🙏 👏 🙌 🫶 👐 🤲 💪 🦾 ✍️ 🤳 🙋 🙆 🙅 🤦 🤷 💁 🙇",
        "Hearts": "❤️ 🧡 💛 💚 💙 💜 🖤 🤍 🤎 💔 ❤️‍🔥 💕 💞 💓 💗 💖 💘 💝 💟 ✨ ⭐ 🌟 💫 🔥 💯 💥 💢 💤 🎉 🎊",
        "Campus": "📚 📖 📝 ✏️ 🎓 🏫 🧪 🔬 💻 🖥️ 📱 🎧 🎤 🎸 🎹 🎨 🎭 ⚽ 🏀 🏐 🏸 🏓 🎯 🎮 🏆 🥇 🚀 💡 📅 ⏰ 📍 📎 🗂️ 🧠 ☕ 🍕 🍔 🍟 🍜 🍣 🍩 🍪 🧋 🍿",
        "Nature": "🌞 🌤️ ☁️ 🌧️ ⛈️ 🌈 ❄️ 🌊 🌸 🌺 🌻 🌹 🌴 🌳 🍀 🍃 🐶 🐱 🐭 🐰 🦊 🐻 🐼 🐨 🐯 🦁 🐸 🐵 🐧 🐥 🦋 🐢 🐙"
    };
    var EMOJI_CATS = Object.keys(EMOJI);
    var SHORTCODES = [
        [/(^|\s)(:-?\))\s$/, "🙂"], [/(^|\s)(:-?D)\s$/, "😃"], [/(^|\s)(:-?\()\s$/, "🙁"], [/(^|\s)(;-?\))\s$/, "😉"],
        [/(^|\s)(:-?[pP])\s$/, "😛"], [/(^|\s)(<3)\s$/, "❤️"], [/(^|\s)(:-?[oO])\s$/, "😮"], [/(^|\s)(:'\()\s$/, "😢"], [/(^|\s)(xD)\s$/, "😆"]
    ];
    function recentEmoji() { try { return JSON.parse(localStorage.getItem("camigo_emoji") || "[]"); } catch (e) { return []; } }
    function rememberEmoji(e) {
        try { var r = recentEmoji().filter(function (x) { return x !== e; }); r.unshift(e); localStorage.setItem("camigo_emoji", JSON.stringify(r.slice(0, 24))); } catch (x) {}
    }
    var EMOJI_ONLY = null;
    try { EMOJI_ONLY = new RegExp("^(?:\\p{Extended_Pictographic}[\\u{FE0F}\\u{200D}\\u{1F3FB}-\\u{1F3FF}]*\\s*){1,3}$", "u"); } catch (e) {}
    function isJumbo(t) { return !!(EMOJI_ONLY && t && t.length <= 24 && EMOJI_ONLY.test(t.trim())); }

    /* ---------------------------------------------------------------- message pieces */
    function linkify(text) {
        var frag = document.createDocumentFragment();
        text.split("\n").forEach(function (line, li) {
            if (li) frag.appendChild(document.createElement("br"));
            var last = 0, re = /https?:\/\/[^\s<]+/g, m;
            while ((m = re.exec(line))) {
                var url = m[0], trail = "";
                var t = /[.,!?;:)\]]+$/.exec(url); if (t) { trail = t[0]; url = url.slice(0, -trail.length); }
                if (m.index > last) frag.appendChild(document.createTextNode(line.slice(last, m.index)));
                frag.appendChild(h("a", { href: url, target: "_blank", rel: "noopener noreferrer nofollow", text: url }));
                last = m.index + url.length;
                re.lastIndex = last;
            }
            if (last < line.length) frag.appendChild(document.createTextNode(line.slice(last)));
        });
        return frag;
    }

    function fileCard(f, mine) {
        var e = ext(f.name), cls = { pdf: "pdf", doc: "doc", docx: "doc", xls: "xls", xlsx: "xls", csv: "xls", ppt: "ppt", pptx: "ppt", zip: "zip", txt: "txt" }[e] || "txt";
        var inner = [h("span", { class: "file-ico " + cls, text: e.toUpperCase().slice(0, 4) }),
            h("span", { class: "file-meta" }, [h("strong", { text: f.name }), h("small", { text: sizeLabel(f.size) })])];
        if (f.pending || !f.url) return h("div", { class: "file-card" }, inner);
        return h("a", { class: "file-card", href: f.url + "&dl=1", download: f.name, title: "Download" }, inner.concat([ico("download", "file-dl")]));
    }
    function imageThumb(f) {
        var src = f.local || f.url;
        var img = h("img", { src: src, alt: f.name });
        if (f.w && f.h) {                                   // known size: reserve the exact box so the thread never jumps
            var sc = Math.min(1, 340 / f.w, 320 / f.h);
            img.style.width = Math.round(f.w * sc) + "px";
            img.style.height = "auto";
            img.style.aspectRatio = f.w + " / " + f.h;
        }
        img.addEventListener("load", function () { repin(); });
        img.addEventListener("error", function () {
            var fb = h("div", { class: "att-broken", title: f.name }, [ico("image"), h("span", null, [h("strong", { text: "Photo couldn't be loaded" }), h("small", { text: f.name })])]);
            if (a.parentNode) a.parentNode.replaceChild(fb, a);
        });
        var a = h("button", { type: "button", class: "att-img", "aria-label": "View photo: " + f.name, onclick: function () { lightbox(f); } }, [img]);
        if (f.pending) a.classList.add("busy");
        return a;
    }
    function lightbox(f) {
        var box = h("div", { class: "lightbox", role: "dialog", "aria-modal": "true" }, [
            h("div", { class: "lb-bar" }, [
                h("span", { class: "lb-name", text: f.name }),
                f.url ? h("a", { class: "icon-btn light", href: f.url + "&dl=1", download: f.name, title: "Download", "aria-label": "Download" }, [ico("download")]) : null,
                h("button", { type: "button", class: "icon-btn light", title: "Close", "aria-label": "Close", onclick: close }, [ico("close")])
            ]),
            h("img", { src: f.local || f.url, alt: f.name })
        ]);
        function close() { box.remove(); document.removeEventListener("keydown", esc); }
        function esc(e) { if (e.key === "Escape") close(); }
        box.addEventListener("click", function (e) { if (e.target === box) close(); });
        document.addEventListener("keydown", esc);
        document.body.appendChild(box);
    }

    /* ---------------------------------------------------------------- thread */
    function msgActions(m) {
        var items = [];
        if (m.body) items.push({ icon: "copy", label: "Copy text", fn: function () {
            if (navigator.clipboard) navigator.clipboard.writeText(m.body).then(function () { toast("Copied"); }, function () { toast("Couldn't copy"); });
        } });
        if (m.file && m.file.url) items.push({ icon: "download", label: "Download", fn: function () { var a = h("a", { href: m.file.url + "&dl=1", download: m.file.name }); document.body.appendChild(a); a.click(); a.remove(); } });
        if (m.from === B.me && m.id && !m.pending) {
            if (items.length) items.push("-");
            items.push({ icon: "undo", label: "Unsend", danger: true, fn: function () { unsendModal(m); } });
        }
        return items;
    }

    function buildRow(m, prev, next, isGroup) {
        var mine = m.from === B.me;
        var firstOf = !prev || prev.kind === "sys" || prev.from !== m.from || (m.ts - prev.ts) > 300 || dayKey(m.ts) !== dayKey(prev.ts);
        var lastOf = !next || next.kind === "sys" || next.from !== m.from || (next.ts - m.ts) > 300 || dayKey(next.ts) !== dayKey(m.ts);
        var pos = firstOf && lastOf ? "single" : firstOf ? "top" : lastOf ? "bottom" : "mid";

        var row = h("div", { class: "row " + (mine ? "me" : "them") + " " + pos, "data-id": m.id || "", "data-tmp": m.tmp || "" });

        if (isGroup && !mine) {
            var av = h("div", { class: "row-av" });
            if (lastOf) {
                var link = h("a", { href: profileUrl(m.username), title: m.name }, [personAvatar({ photo: m.photo, initial: m.initial, name: m.name }, "xs")]);
                av.appendChild(link);
            }
            row.appendChild(av);
        }

        var stack = h("div", { class: "stack" });
        if (isGroup && !mine && firstOf) stack.appendChild(h("a", { class: "sender", href: profileUrl(m.username), text: m.name }));

        var line = h("div", { class: "line" });
        var bubble;
        if (m.gone) {
            bubble = h("div", { class: "bubble gone", text: (mine ? "You" : first(m.name || (S.open && S.open.name) || "Someone")) + " unsent a message" });
        } else {
            var parts = [];
            var imageOnly = m.file && m.file.image && !m.body;
            if (m.file) parts.push(m.file.image ? imageThumb(m.file) : fileCard(m.file, mine));
            if (m.body) parts.push(h("div", { class: "txt" }, [linkify(m.body)]));
            bubble = h("div", { class: "bubble" + (imageOnly ? " bare" : "") + (isJumbo(m.body) && !m.file ? " jumbo" : "") + (m.pending ? " pending" : "") + (m.failed ? " failed" : ""), title: fmtTime(m.ts) }, parts);
        }
        line.appendChild(bubble);

        var acts = (m.gone || (m.pending && !m.failed)) ? [] : msgActions(m);
        if (acts.length) {
            var mb = h("button", { type: "button", class: "msg-more", title: "More", "aria-label": "Message options" }, [ico("more")]);
            mb.addEventListener("click", function (e) { e.stopPropagation(); openMenu(mb, acts); });
            line.appendChild(mb);
        }
        stack.appendChild(line);

        if (m.failed) {
            stack.appendChild(h("div", { class: "failed-note" }, [
                h("span", { text: "Not sent. " }),
                h("button", { type: "button", class: "link-btn", text: "Retry", onclick: function () { retry(m); } }),
                h("span", { text: " \u00b7 " }),
                h("button", { type: "button", class: "link-btn", text: "Remove", onclick: function () { S.msgs = S.msgs.filter(function (x) { return x !== m; }); renderThread(); } })
            ]));
        }
        row.appendChild(stack);
        return row;
    }

    var stuck = true;                       // true while the reader is at the bottom (so late-loading images keep them there)
    function nearBottom() { var t = el.thread; return !t || t.scrollHeight - t.scrollTop - t.clientHeight < 180; }
    function toBottom() { if (el.thread) el.thread.scrollTop = el.thread.scrollHeight; }
    // Keep the newest message in view while things settle (photos, fonts, layout). Stops as soon as the reader scrolls up.
    var pinTimers = [];
    function repin() { if (stuck) toBottom(); }
    function pinSoon() {
        pinTimers.forEach(clearTimeout); pinTimers = [];
        repin();
        if (window.requestAnimationFrame) window.requestAnimationFrame(repin);
        [60, 250, 700, 1500].forEach(function (ms) { pinTimers.push(setTimeout(repin, ms)); });
    }

    function renderThread(forceBottom) {
        if (!el.thread) return;
        var stick = forceBottom || stuck, prevH = el.thread.scrollHeight, prevTop = el.thread.scrollTop;
        var t = el.thread, isGroup = S.open.k === "g";
        t.innerHTML = "";
        var msgs = S.msgs;

        if (!msgs.length) {
            t.appendChild(h("div", { class: "thread-empty" }, [
                personOrGroupAvatar(S.open, "lg"),
                h("strong", { text: S.open.name }),
                h("p", { text: isGroup ? "This is the start of the group. Say hello!" : "This is the start of your conversation with " + first(S.open.name) + ". Say hello." })
            ]));
        }

        var lastTs = 0, lastDay = "";
        msgs.forEach(function (m, i) {
            var dk = dayKey(m.ts);
            if (dk !== lastDay || m.ts - lastTs > 900) {
                t.appendChild(h("div", { class: "day", text: (dk !== lastDay ? dayLabel(m.ts) + " \u00b7 " : "") + fmtTime(m.ts) }));
                lastDay = dk;
            }
            lastTs = m.ts;
            if (m.kind === "sys") { t.appendChild(h("div", { class: "sys", text: m.body })); return; }
            t.appendChild(buildRow(m, msgs[i - 1], msgs[i + 1], isGroup));
        });

        var last = msgs[msgs.length - 1];
        el.seen.hidden = !(S.open.k === "u" && S.seen && last && last.from === B.me && !last.pending && !last.failed && !last.gone);
        t.appendChild(el.typing);
        t.appendChild(el.seen);

        if (stick) { toBottom(); stuck = true; } else el.thread.scrollTop = prevTop + (el.thread.scrollHeight - prevH);
    }
    function personOrGroupAvatar(o, size) { return avatar(o, size); }

    /* ---------------------------------------------------------------- pane (header, thread, composer) */
    function renderPane() {
        paneEl.innerHTML = "";
        var meta = B.open;

        if (!meta) {
            paneEl.appendChild(h("div", { class: "chat-empty" }, [
                h("div", { class: "empty-art" }, [ico("chat")]),
                h("h2", { text: "Your messages" }),
                h("p", { class: "muted", text: "Pick a chat on the left, start a new message, or create a group for your project team." }),
                h("div", { class: "empty-actions" }, [
                    h("button", { type: "button", class: "btn-sm btn-dark", text: "Create group", onclick: newGroupModal }),
                    h("a", { class: "btn-sm btn-ghost", href: "students", text: "Find students" })
                ])
            ]));
            return;
        }
        if (meta.missing) {
            paneEl.appendChild(h("div", { class: "chat-empty" }, [
                h("h2", { text: meta.k === "g" ? "Group not found" : "Student not found" }),
                h("p", { class: "muted", text: meta.k === "g" ? "You may have left this group, or it no longer exists." : "We couldn't find anyone with that username." }),
                h("a", { class: "btn-sm btn-ghost", href: "messages", text: "Back to chats" })
            ]));
            return;
        }

        var o = S.open, isGroup = o.k === "g";
        paneEl.appendChild(buildHead(o, isGroup));

        el.thread = h("div", { class: "thread", id: "thread" });
        el.typing = h("div", { class: "typing", hidden: true, "aria-label": "Someone is typing" }, [h("span"), h("span"), h("span")]);
        el.seen = h("div", { class: "seen", hidden: true, text: "Seen" });
        paneEl.appendChild(el.thread);
        paneEl.appendChild(buildFooter(o));
        var lastTop = 0;
        el.thread.addEventListener("scroll", function () {
            var t = el.thread, dist = t.scrollHeight - t.scrollTop - t.clientHeight;
            if (dist < 40) stuck = true;                    // back at the bottom
            else if (t.scrollTop < lastTop - 2) stuck = false;   // the reader scrolled UP (content growing doesn't count)
            lastTop = t.scrollTop;
        });
        stuck = true;
        renderThread(true);
        pinSoon();
        window.addEventListener("load", repin);
        wireDrop();
    }

    function buildHead(o, isGroup) {
        var sub = isGroup ? (o.members + " member" + (o.members === 1 ? "" : "s") + (o.club ? " \u00b7 Club chat" : o.project ? " \u00b7 Team chat" : "")) : "@" + o.username;
        var who = h("div", { class: "pane-who" }, [
            h("strong", null, [document.createTextNode(o.name), ico("chev", "pane-chev"), o.muted ? ico("bellOff", "mute-ico") : null]),
            h("small", { text: sub })
        ]);
        // clicking the name or photo opens the profile (people) or the group details (groups)
        var idBlock = isGroup
            ? h("button", { type: "button", class: "pane-id", "aria-label": o.name + ", group details", onclick: groupInfoModal }, [avatar(o), who])
            : h("a", { class: "pane-id", href: profileUrl(o.username), "aria-label": o.name + ", view profile" }, [avatar(o), who]);

        var more = h("button", { type: "button", class: "icon-btn", title: "More", "aria-label": "Chat options" }, [ico("more")]);
        more.addEventListener("click", function () { openMenu(more, headMenu(o, isGroup)); });
        var right = h("div", { class: "head-actions" }, [more]);
        var head = h("div", { class: "pane-head" }, [h("a", { href: "messages", class: "pane-back", "aria-label": "Back to chats" }, [h("span", { html: "&larr;" })]), idBlock, right]);
        el.head = head;
        return head;
    }

    function headMenu(o, isGroup) {
        var items = [{ icon: o.muted ? "bell" : "bellOff", label: o.muted ? "Unmute notifications" : "Mute notifications", fn: toggleMute }];
        if (isGroup) {
            if (o.club) items.push({ icon: "club", label: "View club", fn: function () { location.href = "club?id=" + o.club; } });
            if (o.project) items.push({ icon: "club", label: "View project", fn: function () { location.href = "project?id=" + o.project; } });
        } else {
            items.push({ icon: "block", label: o.blockedByMe ? "Unblock " + first(o.name) : "Block " + first(o.name), fn: o.blockedByMe ? function () { setBlock(false); } : blockModal });
        }
        items.push("-");
        items.push({ icon: "trash", label: "Delete chat", danger: true, fn: deleteModal });
        if (isGroup && !o.club && !o.project) items.push({ icon: "exit", label: "Leave group", danger: true, fn: leaveModal });
        return items;
    }

    /* ---------------------------------------------------------------- composer */
    function buildFooter(o) {
        var wrap = h("div", { class: "footer" });
        el.footer = wrap;
        renderFooter();
        return wrap;
    }

    function renderFooter() {
        var o = S.open, wrap = el.footer;
        wrap.innerHTML = "";
        el.composer = el.ta = el.tray = el.emoji = null;

        if (o.k === "u" && o.blockedByMe) {
            wrap.appendChild(h("div", { class: "composer-note blocked" }, [
                h("div", null, [h("strong", { text: "You blocked " + o.name }), h("p", { text: "You can't message each other. Unblock to chat again." })]),
                h("button", { type: "button", class: "btn-sm btn-dark", text: "Unblock", onclick: function () { setBlock(false); } })
            ]));
            return;
        }
        if (o.k === "u" && o.blockedMe) { wrap.appendChild(h("div", { class: "composer-note", text: "You can't reply to this conversation." })); return; }
        if (o.k === "u" && !o.allowed) { wrap.appendChild(h("div", { class: "composer-note", text: "This student isn't taking new messages." })); return; }

        el.err = h("div", { class: "composer-error", hidden: true });
        el.tray = h("div", { class: "tray", hidden: true });
        el.emoji = buildEmoji();
        el.ta = h("textarea", { rows: "1", maxlength: "1000", placeholder: "Aa", "aria-label": "Message" });
        el.fileInput = h("input", { type: "file", multiple: true, accept: B.accept, hidden: true });
        el.sendBtn = h("button", { type: "button", class: "send-btn", "aria-label": "Send" });
        var clipBtn = iconBtn("clip", "Attach files", function () { el.fileInput.click(); }, "tool");
        el.emojiBtn = iconBtn("smile", "Emoji", function () { toggleEmoji(); }, "tool");
        var field = h("div", { class: "field-box" }, [el.ta, el.emojiBtn]);
        el.composer = h("div", { class: "composer" }, [clipBtn, field, el.sendBtn]);

        wrap.appendChild(el.err); wrap.appendChild(el.tray); wrap.appendChild(el.emoji); wrap.appendChild(el.composer); wrap.appendChild(el.fileInput);

        el.fileInput.addEventListener("change", function () { addFiles(el.fileInput.files); el.fileInput.value = ""; });
        el.sendBtn.addEventListener("click", submit);
        el.ta.addEventListener("keydown", function (e) {
            if (e.key === "Enter" && !e.shiftKey && !e.isComposing) { e.preventDefault(); submit(); }
        });
        el.ta.addEventListener("input", function (e) { grow(); shortcode(e); updateSend(); typingPing(); });
        el.ta.addEventListener("paste", function (e) {
            var items = (e.clipboardData && e.clipboardData.files) || [];
            if (items.length) { e.preventDefault(); addFiles(items); }
        });
        updateSend(); renderTray();
        if (!/Mobi|Android/i.test(navigator.userAgent)) el.ta.focus();
    }

    function grow() { var t = el.ta; t.style.height = "auto"; t.style.height = Math.min(t.scrollHeight, 140) + "px"; }
    function updateSend() {
        var has = el.ta.value.trim() !== "" || S.files.length > 0;
        el.sendBtn.className = "send-btn " + (has ? "has" : "like");
        el.sendBtn.innerHTML = "";
        el.sendBtn.appendChild(ico(has ? "send" : "like"));
        el.sendBtn.setAttribute("aria-label", has ? "Send" : "Send a like");
    }

    function shortcode(e) {
        if (!e || e.data !== " ") return;
        var t = el.ta, pos = t.selectionStart, before = t.value.slice(0, pos);
        for (var i = 0; i < SHORTCODES.length; i++) {
            var m = SHORTCODES[i][0].exec(before);
            if (m) {
                var tokenStart = before.length - m[0].length + m[1].length;
                t.value = before.slice(0, tokenStart) + SHORTCODES[i][1] + " " + t.value.slice(pos);
                var np = tokenStart + SHORTCODES[i][1].length + 1;
                t.setSelectionRange(np, np);
                return;
            }
        }
    }
    function insertAtCaret(txt) {
        var t = el.ta, s = t.selectionStart, e = t.selectionEnd;
        if (t.value.length - (e - s) + txt.length > 1000) return;
        t.value = t.value.slice(0, s) + txt + t.value.slice(e);
        t.setSelectionRange(s + txt.length, s + txt.length);
        grow(); updateSend(); t.focus();
    }

    function buildEmoji() {
        var panel = h("div", { class: "emoji-panel", hidden: true, role: "dialog", "aria-label": "Emoji" });
        var tabs = h("div", { class: "emoji-tabs" }), grid = h("div", { class: "emoji-grid" });
        var cats = EMOJI_CATS.slice(), cur = recentEmoji().length ? "Recent" : EMOJI_CATS[0];
        function list(c) { return c === "Recent" ? recentEmoji() : EMOJI[c].split(" "); }
        function draw() {
            tabs.innerHTML = ""; grid.innerHTML = "";
            var all = (recentEmoji().length ? ["Recent"] : []).concat(cats);
            all.forEach(function (c) { tabs.appendChild(h("button", { type: "button", class: "emoji-tab" + (c === cur ? " on" : ""), text: c, onclick: function () { cur = c; draw(); } })); });
            list(cur).forEach(function (e) {
                grid.appendChild(h("button", { type: "button", class: "emoji", text: e, "aria-label": e, onclick: function () { rememberEmoji(e); insertAtCaret(e); } }));
            });
            grid.scrollTop = 0;
        }
        draw();
        panel.appendChild(tabs); panel.appendChild(grid);
        panel.redraw = draw;
        return panel;
    }
    function toggleEmoji(force) {
        if (!el.emoji) return;
        var show = force === undefined ? el.emoji.hidden : force;
        if (show) el.emoji.redraw();
        el.emoji.hidden = !show;
        if (el.emojiBtn) el.emojiBtn.classList.toggle("on", show);
    }
    document.addEventListener("mousedown", function (e) {
        if (el.emoji && !el.emoji.hidden && !el.emoji.contains(e.target) && !(el.emojiBtn && el.emojiBtn.contains(e.target))) toggleEmoji(false);
    });

    /* ---- attachments ---- */
    var okExt = (B.accept || "").split(",").map(function (x) { return x.replace(".", "").toLowerCase(); });
    function addFiles(list) {
        var err = "";
        Array.prototype.forEach.call(list, function (f) {
            if (S.files.length >= B.maxFiles) { err = "You can send up to " + B.maxFiles + " files at a time."; return; }
            if (okExt.indexOf(ext(f.name)) < 0) { err = "\u201c" + f.name + "\u201d isn't a supported file type."; return; }
            if (f.size > B.maxFile) { err = "\u201c" + f.name + "\u201d is over " + sizeLabel(B.maxFile) + "."; return; }
            if (f.size === 0) { err = "\u201c" + f.name + "\u201d is empty."; return; }
            S.files.push(f);
        });
        if (err) showErr(err);
        renderTray(); updateSend();
    }
    function renderTray() {
        if (!el.tray) return;
        el.tray.innerHTML = "";
        el.tray.hidden = S.files.length === 0;
        S.files.forEach(function (f, i) {
            var thumb;
            if (/^image\//.test(f.type) && window.URL) { thumb = h("img", { src: URL.createObjectURL(f), alt: "" }); thumb.addEventListener("load", function () { URL.revokeObjectURL(thumb.src); }); }
            else thumb = h("span", { class: "file-ico " + ({ pdf: "pdf", doc: "doc", docx: "doc", xls: "xls", xlsx: "xls", csv: "xls", ppt: "ppt", pptx: "ppt", zip: "zip" }[ext(f.name)] || "txt"), text: ext(f.name).toUpperCase().slice(0, 4) });
            el.tray.appendChild(h("div", { class: "tray-item" }, [
                h("div", { class: "tray-thumb" }, [thumb]),
                h("div", { class: "tray-meta" }, [h("strong", { text: f.name }), h("small", { text: sizeLabel(f.size) })]),
                h("button", { type: "button", class: "tray-x", "aria-label": "Remove " + f.name, onclick: function () { S.files.splice(i, 1); renderTray(); updateSend(); } }, [ico("close")])
            ]));
        });
    }
    function wireDrop() {
        var depth = 0;
        paneEl.addEventListener("dragenter", function (e) { if (hasFiles(e) && el.ta) { depth++; paneEl.classList.add("dropping"); } });
        paneEl.addEventListener("dragover", function (e) { if (hasFiles(e) && el.ta) e.preventDefault(); });
        paneEl.addEventListener("dragleave", function () { depth = Math.max(0, depth - 1); if (!depth) paneEl.classList.remove("dropping"); });
        paneEl.addEventListener("drop", function (e) {
            depth = 0; paneEl.classList.remove("dropping");
            if (hasFiles(e) && el.ta) { e.preventDefault(); addFiles(e.dataTransfer.files); }
        });
    }
    function hasFiles(e) { return e.dataTransfer && Array.prototype.indexOf.call(e.dataTransfer.types || [], "Files") > -1; }
    function showErr(t) { if (!el.err) return toast(t); el.err.textContent = t; el.err.hidden = false; clearTimeout(showErr.t); showErr.t = setTimeout(function () { el.err.hidden = true; }, 6000); }

    /* ---- sending ---- */
    function submit() {
        if (!el.ta) return;
        var text = el.ta.value.trim(), files = S.files.slice();
        if (!text && !files.length) { sendOne("\u{1F44D}", null); return; }        // the "like" button
        el.ta.value = ""; S.files = []; grow(); renderTray(); updateSend(); el.err.hidden = true; toggleEmoji(false);
        if (!files.length) { sendOne(text, null); return; }
        files.forEach(function (f, i) { sendOne(i === 0 ? text : "", f); });
    }

    var chain = Promise.resolve();
    function sendOne(text, file) {
        var o = S.open, ts = Math.floor(Date.now() / 1000);
        var m = { id: 0, tmp: ++S.tmp, from: B.me, ts: ts, kind: "msg", gone: false, body: text, pending: true, failed: false, file: null };
        if (file) m.file = { name: file.name, size: file.size, image: /^image\//.test(file.type), pending: true, local: /^image\//.test(file.type) && window.URL ? URL.createObjectURL(file) : "" };
        m._file = file;
        S.msgs.push(m); S.seen = false;
        stuck = true;
        renderThread(true);
        pinSoon();
        var c = ensureConvo(o.k, o.id, o); setLast(c, m, true); c.unread = 0; renderList();
        chain = chain.then(function () { return deliver(m); });
    }
    function deliver(m) {
        var o = S.open;
        m.pending = true; m.failed = false;
        return api("send", { k: o.k, id: o.id, body: m.body }, m._file).then(function (j) {
            m.pending = false;
            if (j && j.ok) {
                m.id = j.message.id; m.ts = j.message.ts || m.ts;
                if (m.file) {                                   // keep showing the local copy: no reload flash, no jump
                    var keep = m.file.local;
                    m.file = j.message.file || m.file; m.file.pending = false; m.file.local = keep || "";
                }
                m._file = null;
            } else { m.failed = true; showErr((j && j.error) || "That message didn't send."); }
            renderThread();
            repin();
        });
    }
    function retry(m) { chain = chain.then(function () { return deliver(m); }); renderThread(); }

    /* ---- typing ---- */
    var chanTyping = null, lastTyping = 0, typingTimer = null;
    function typingPing() {
        var now = Date.now();
        if (chanTyping && chanTyping.subscribed && now - lastTyping > 2500) {
            lastTyping = now;
            chanTyping.trigger("client-typing", { from: B.me, name: B.meName || "" });
        }
    }
    function showTyping(name) {
        if (!el.typing) return;
        var stick = nearBottom();
        el.typing.hidden = false;
        el.typing.title = name ? name + " is typing" : "Typing";
        if (stick) toBottom();
        clearTimeout(typingTimer); typingTimer = setTimeout(hideTyping, 4500);
    }
    function hideTyping() { if (el.typing) el.typing.hidden = true; clearTimeout(typingTimer); }

    /* ---------------------------------------------------------------- actions */
    function unsendModal(m) {
        confirmBox({
            title: "Unsend this message?", ok: "Unsend", danger: true,
            text: "It will be removed for everyone in this chat. People may have already seen it.",
            fn: function () {
                return api("unsend", { k: S.open.k, mid: m.id }).then(function (j) {
                    if (!j.ok) { toast(j.error || "We couldn't unsend that."); return false; }
                    markGone(S.open.k, S.open.id, m.id);
                    if (RT && j.unread !== undefined) RT.setBadge(j.unread);
                });
            }
        });
    }
    function markGone(k, cid, mid) {
        var c = findConvo(k, cid);
        if (c && c.mid === mid && c.last) { c.last.gone = true; c.last.body = ""; c.last.file = false; }
        if (openIs(k, cid)) {
            S.msgs.forEach(function (x) { if (x.id === mid) { x.gone = true; x.body = ""; x.file = null; } });
            renderThread();
        }
        renderList();
    }

    function toggleMute() { setMute(!S.open.muted); }
    function setMute(on) {
        var o = S.open;
        return api("mute", { k: o.k, id: o.id, on: on }).then(function (j) {
            if (!j.ok) { toast(j.error || "Something went wrong."); return; }
            o.muted = !!j.muted;
            var c = findConvo(o.k, o.id); if (c) c.muted = o.muted;
            if (RT) {
                var arr = RT.muted[o.k] = (RT.muted[o.k] || []).filter(function (x) { return x !== o.id; });
                if (o.muted) arr.push(o.id);
                RT.setBadge(j.unread);
            }
            replaceHead(); renderList();
            toast(o.muted ? "Notifications muted for " + o.name : "Notifications on for " + o.name);
        });
    }
    function replaceHead() { var nh = buildHead(S.open, S.open.k === "g"); paneEl.replaceChild(nh, el.head); el.head = nh; }

    function blockModal() {
        var o = S.open;
        confirmBox({
            title: "Block " + o.name + "?", ok: "Block", danger: true,
            text: "Neither of you will be able to send messages in this chat. They won't be told. You can unblock them any time.",
            fn: function () { return setBlock(true).then(function (ok) { return ok; }); }
        });
    }
    function setBlock(on) {
        var o = S.open;
        return api("block", { id: o.id, on: on }).then(function (j) {
            if (!j.ok) { toast(j.error || "Something went wrong."); return false; }
            o.blockedByMe = !!j.blocked;
            var c = findConvo("u", o.id); if (c) { c.blocked = o.blockedByMe; if (o.blockedByMe) c.unread = 0; }
            if (RT && j.unread !== undefined) RT.setBadge(j.unread);
            renderFooter(); replaceHead(); renderList();
            toast(on ? o.name + " is blocked" : o.name + " is unblocked");
            return true;
        });
    }

    function deleteModal() {
        var o = S.open;
        confirmBox({
            title: "Delete this chat?", ok: "Delete chat", danger: true,
            text: "This clears the conversation from your account only. " + (o.k === "g" ? "Everyone else keeps the history." : first(o.name) + " will still have their copy.") + " It comes back if someone writes again.",
            fn: function () {
                return api("clear", { k: o.k, id: o.id }).then(function (j) {
                    if (!j.ok) { toast(j.error || "We couldn't delete that chat."); return false; }
                    location.href = "messages";
                });
            }
        });
    }
    function leaveModal() {
        var o = S.open;
        confirmBox({
            title: "Leave " + o.name + "?", ok: "Leave group", danger: true,
            text: "You won't get new messages from this group. An admin can add you back.",
            fn: function () {
                return api("group_leave", { id: o.id }).then(function (j) {
                    if (!j.ok) { toast(j.error || "We couldn't do that."); return false; }
                    location.href = "messages";
                });
            }
        });
    }


    /* ---------------------------------------------------------------- group photo */
    function pickImage(done) {
        var inp = h("input", { type: "file", accept: "image/jpeg,image/png,image/webp", hidden: true });
        inp.addEventListener("change", function () {
            var f = inp.files && inp.files[0]; inp.remove();
            if (!f) return;
            if (!/^image\/(jpeg|png|webp)$/.test(f.type)) { toast("Please choose a JPG, PNG or WebP image."); return; }
            if (f.size > B.maxFile) { toast("That image is over " + sizeLabel(B.maxFile) + "."); return; }
            done(f);
        });
        document.body.appendChild(inp); inp.click();
    }
    function setGroupLogo(gid, logo, sys) {
        var c = findConvo("g", gid); if (c) c.logo = logo;
        if (openIs("g", gid)) { S.open.logo = logo; if (el.head) replaceHead(); renderThreadAvatarOnly(); }
        addSys(gid, sys); renderList();
    }
    function renderThreadAvatarOnly() { if (el.thread && !S.msgs.length) renderThread(); }

    /* ---------------------------------------------------------------- people picker (groups) */
    function picker(opts) {
        var sel = {}, order = [];
        var chips = h("div", { class: "pick-chips" });
        var results = h("div", { class: "pick-list", role: "listbox" });
        var input = h("input", { type: "search", class: "pick-search", placeholder: "Search students by name or username", autocomplete: "off", "aria-label": "Search students" });
        var root = h("div", { class: "picker" }, [input, chips, results]);
        var t = null, seq = 0;

        function drawChips() {
            chips.innerHTML = ""; chips.hidden = order.length === 0;
            order.forEach(function (p) {
                chips.appendChild(h("span", { class: "pick-chip" }, [personAvatar(p, "xs"), h("span", { text: first(p.name) }),
                    h("button", { type: "button", "aria-label": "Remove " + p.name, onclick: function () { toggle(p); } }, [ico("close")])]));
            });
            if (opts.onChange) opts.onChange(order.length);
        }
        function toggle(p) {
            if (sel[p.id]) { delete sel[p.id]; order = order.filter(function (x) { return x.id !== p.id; }); }
            else { if (order.length >= opts.max) { toast("Groups can have up to " + B.groupMax + " people."); return; } sel[p.id] = p; order.push(p); }
            drawChips(); draw(lastPeople);
        }
        var lastPeople = [];
        function draw(people) {
            people = people || []; lastPeople = people; results.innerHTML = "";
            if (!people.length) { results.appendChild(h("p", { class: "muted pick-empty", text: input.value.trim() ? "No students found." : "Search for someone to add." })); return; }
            if (!input.value.trim()) results.appendChild(h("div", { class: "pick-label", text: "Recent" }));
            people.forEach(function (p) {
                var on = !!sel[p.id];
                results.appendChild(h("button", { type: "button", class: "pick-row" + (on ? " on" : ""), role: "option", "aria-selected": on ? "true" : "false", onclick: function () { toggle(p); } }, [
                    personAvatar(p, "sm"),
                    h("span", { class: "pick-who" }, [h("strong", { text: p.name }), h("small", { text: "@" + p.username })]),
                    h("span", { class: "check" + (on ? " on" : "") }, [on ? ico("check", "tick") : null])
                ]));
            });
        }
        function load() {
            var my = ++seq;
            api("people", { q: input.value, k: opts.k || "u", id: opts.id || 0 }).then(function (j) { if (my === seq && j.ok) draw(j.people); });
        }
        input.addEventListener("input", function () { clearTimeout(t); t = setTimeout(load, 220); });
        load(); drawChips();
        return { root: root, ids: function () { return order.map(function (p) { return p.id; }); }, focus: function () { input.focus(); } };
    }

    function newGroupModal() {
        var name = h("input", { type: "text", class: "name-input", maxlength: "60", placeholder: "Group name", "aria-label": "Group name" });
        var err = h("div", { class: "composer-error", hidden: true });
        var create = h("button", { type: "button", class: "btn-sm btn-dark primary", text: "Create group" });
        var p = picker({ max: B.groupMax - 1, onChange: function () {} });
        var photo = null, preview = h("button", { type: "button", class: "photo-pick", "aria-label": "Choose a group photo" });
        function drawPreview() {
            preview.innerHTML = "";
            if (photo) preview.appendChild(h("img", { src: URL.createObjectURL(photo), alt: "" }));
            else preview.appendChild(ico("camera"));
            preview.classList.toggle("has", !!photo);
        }
        preview.addEventListener("click", function () { pickImage(function (f) { photo = f; drawPreview(); }); });
        drawPreview();
        create.addEventListener("click", function () {
            err.hidden = true; create.disabled = true;
            api("group_create", { name: name.value, members: p.ids() }, photo).then(function (j) {
                if (j.ok) { location.href = "messages?g=" + j.id; return; }
                create.disabled = false; err.textContent = j.error || "Something went wrong."; err.hidden = false;
            });
        });
        openModal([
            modalHead("Create group", "Chat with your project team, study group or friends."),
            h("div", { class: "modal-body" }, [h("div", { class: "name-row" }, [preview, h("div", { class: "name-col" }, [h("label", { class: "lbl", text: "Name" }), name])]), h("label", { class: "lbl", text: "Add people" }), p.root, err]),
            h("div", { class: "modal-foot" }, [h("button", { type: "button", class: "btn-sm btn-ghost", text: "Cancel", onclick: closeModal }), create])
        ], true);
    }

    function groupInfoModal() {
        var o = S.open;
        var body = h("div", { class: "modal-body" }, [h("p", { class: "muted", text: "Loading\u2026" })]);
        openModal([modalHead("Group details"), body], true);
        api("group_info", { id: o.id }).then(function (j) {
            body.innerHTML = "";
            if (!j.ok) { body.appendChild(h("p", { class: "error-text", text: j.error || "Couldn't load the group." })); return; }
            o.name = j.name;
            var title = h("div", { class: "gi-top" });
            if (j.admin) {
                var av = h("button", { type: "button", class: "gi-photo", "aria-label": "Change group photo", title: "Change photo" }, [avatar(o, "lg"), h("span", { class: "gi-cam" }, [ico("camera")])]);
                av.addEventListener("click", function () {
                    pickImage(function (f) {
                        api("group_photo", { id: o.id }, f).then(function (r) {
                            if (!r.ok) { toast(r.error || "Couldn't update the photo."); return; }
                            setGroupLogo(o.id, r.logo, r.sys); toast("Group photo updated"); groupInfoModal();
                        });
                    });
                });
                title.appendChild(av);
            } else {
                title.appendChild(avatar(o, "lg"));
            }
            if (j.admin) {
                var nm = h("input", { type: "text", class: "name-input", maxlength: "60", value: j.name, "aria-label": "Group name" });
                var save = h("button", { type: "button", class: "btn-sm btn-ghost", text: "Save name" });
                save.addEventListener("click", function () {
                    api("group_rename", { id: o.id, name: nm.value }).then(function (r) {
                        if (!r.ok) { toast(r.error || "Couldn't rename."); return; }
                        applyGroupName(o.id, r.name, r.sys); toast("Group renamed");
                    });
                });
                var kids = [nm, save];
                if (o.logo && !j.club) kids.push(h("button", { type: "button", class: "link-btn", text: "Remove photo", onclick: function () {
                    api("group_photo_remove", { id: o.id }).then(function (r) {
                        if (!r.ok) { toast(r.error || "Couldn't remove the photo."); return; }
                        setGroupLogo(o.id, "", r.sys); toast("Photo removed"); groupInfoModal();
                    });
                } }));
                title.appendChild(h("div", { class: "gi-name" }, kids));
            } else {
                title.appendChild(h("div", { class: "gi-name" }, [h("strong", { class: "gi-title", text: j.name }),
                    h("small", { class: "muted", text: j.members.length + " member" + (j.members.length === 1 ? "" : "s") })]));
            }
            body.appendChild(title);
            if (j.project) body.appendChild(h("div", { class: "note club-note" }, [ico("club"), h("p", null, [document.createTextNode("This is the project's team chat. Team members are added and removed automatically as they join or leave the project. "), h("a", { href: "project?id=" + j.project, text: "View project" })])]));
            if (j.club) body.appendChild(h("div", { class: "note club-note" }, [ico("club"), h("p", null, [document.createTextNode("This is the club's group chat. Members are added and removed automatically as they join or leave the club. "), h("a", { href: "club?id=" + j.club, text: "View club" })])]));

            var head = h("div", { class: "gi-head" }, [h("strong", { text: "Members \u00b7 " + j.members.length })]);
            if (j.admin) head.appendChild(h("button", { type: "button", class: "btn-sm btn-ghost", onclick: function () { addMembersModal(j.members.length); } }, [ico("plus"), document.createTextNode("Add people")]));
            body.appendChild(head);

            var list = h("div", { class: "gi-list" });
            j.members.forEach(function (p) {
                var row = h("div", { class: "gi-row" }, [
                    h("a", { href: profileUrl(p.username), class: "gi-link" }, [personAvatar(p, "sm"), h("span", { class: "pick-who" }, [h("strong", { text: p.name + (p.id === B.me ? " (You)" : "") }), h("small", { text: "@" + p.username })])]),
                    p.role === "admin" ? h("span", { class: "tag-admin", text: j.club ? "Staff" : j.project ? "Owner" : "Admin" }) : null
                ]);
                if (j.admin && p.id !== B.me) {
                    row.appendChild(h("button", { type: "button", class: "icon-btn danger", title: "Remove " + p.name, "aria-label": "Remove " + p.name, onclick: function () {
                        confirmBox({ title: "Remove " + p.name + "?", ok: "Remove", danger: true, text: "They'll no longer see new messages in this group.", fn: function () {
                            return api("group_remove", { id: o.id, uid: p.id }).then(function (r) {
                                if (!r.ok) { toast(r.error || "Couldn't remove."); return false; }
                                addSys(o.id, r.sys); o.members = Math.max(1, o.members - 1); replaceHead(); toast(p.name + " was removed");
                                setTimeout(groupInfoModal, 40);
                            });
                        } });
                    } }, [ico("close")]));
                }
                list.appendChild(row);
            });
            body.appendChild(list);

            var foot = [];
            if (!j.club && !j.project) foot.push(h("button", { type: "button", class: "btn-sm btn-danger-ghost", onclick: leaveModal }, [ico("exit"), document.createTextNode("Leave group")]));
            if (foot.length) body.appendChild(h("div", { class: "gi-actions" }, foot));
        });
    }
    function addMembersModal() {
        var o = S.open, count = 0;
        var add = h("button", { type: "button", class: "btn-sm btn-dark primary", text: "Add", disabled: true });
        var p = picker({ k: "g", id: o.id, max: B.groupMax, onChange: function (n) { add.disabled = n === 0; } });
        add.addEventListener("click", function () {
            add.disabled = true;
            api("group_add", { id: o.id, members: p.ids() }).then(function (j) {
                if (!j.ok) { toast(j.error || "Couldn't add them."); add.disabled = false; return; }
                o.members += p.ids().length; addSys(o.id, j.sys); replaceHead(); toast("Added to the group"); setTimeout(groupInfoModal, 40);
            });
        });
        openModal([modalHead("Add people", "Pick who joins " + o.name), h("div", { class: "modal-body" }, [p.root]),
            h("div", { class: "modal-foot" }, [h("button", { type: "button", class: "btn-sm btn-ghost", text: "Back", onclick: groupInfoModal }), add])], true);
    }
    function addSys(gid, sys) {
        if (!sys) return;
        var c = findConvo("g", gid);
        if (c) { c.last = { mine: false, kind: "sys", gone: false, body: sys.body, file: false, sender: "" }; c.ts = sys.ts; c.mid = sys.id; }
        if (openIs("g", gid) && !S.msgs.some(function (x) { return x.id === sys.id; })) {
            S.msgs.push({ id: sys.id, from: 0, ts: sys.ts, kind: "sys", body: sys.body, gone: false, file: null });
            renderThread();
        }
        renderList();
    }
    function applyGroupName(gid, name, sys) {
        var c = findConvo("g", gid); if (c) c.name = name;
        if (openIs("g", gid)) { S.open.name = name; if (el.head) replaceHead(); document.title = name + " | Messages | Camigo"; }
        addSys(gid, sys); renderList();
    }

    /* ---------------------------------------------------------------- reading */
    var pendingRead = false;
    function markRead() {
        if (!S.open) return;
        if (document.hidden) { pendingRead = true; return; }
        pendingRead = false;
        var o = S.open;
        api("read", { k: o.k, id: o.id }).then(function (j) {
            if (j && j.ok) {
                if (RT) RT.setBadge(j.unread);
                var c = findConvo(o.k, o.id); if (c) { c.unread = 0; renderList(); }
            }
        });
    }
    document.addEventListener("visibilitychange", function () { if (!document.hidden && pendingRead) markRead(); });

    /* ---------------------------------------------------------------- realtime */
    function bindRealtime() {
        if (!RT) return;
        if (S.open) RT.open = S.open.k + ":" + S.open.id;

        RT.userChannel.bind("new-message", function (d) {
            var k = d.k === "g" ? "g" : "u", id = k === "g" ? d.gid : d.from;
            var m = { id: d.id, from: d.from, ts: d.ts, kind: "msg", gone: false, body: d.body, file: d.file || null, name: d.name, username: d.username, photo: d.photo, initial: d.initial };
            var info = k === "g" ? { name: d.gname } : { name: d.name, username: d.username, photo: d.photo, initial: d.initial };
            var c = ensureConvo(k, id, info);
            setLast(c, m, false);
            c.last.sender = first(d.name);
            if (openIs(k, id)) {
                hideTyping();
                if (!S.msgs.some(function (x) { return x.id === d.id; })) { S.msgs.push(m); S.seen = false; renderThread(); }
                c.unread = 0;
                markRead();
            } else {
                c.unread = (c.unread || 0) + 1;
            }
            renderList();
        });

        RT.userChannel.bind("unsent", function (d) { markGone(d.k === "g" ? "g" : "u", d.k === "g" ? d.gid : d.from, d.id); });

        RT.userChannel.bind("seen", function (d) {
            if (S.open && S.open.k === "u" && d.by === S.open.id) { S.seen = true; renderThread(); }
        });

        RT.userChannel.bind("group-added", function (d) {
            var c = d.convo; if (!c || findConvo("g", c.id)) return;
            S.convos.push(c); renderList(); toast("You were added to " + c.name);
        });
        RT.userChannel.bind("group-removed", function (d) {
            S.convos = S.convos.filter(function (c) { return !same(c, "g", d.gid); });
            renderList();
            if (openIs("g", d.gid)) { toast("You were removed from this group"); setTimeout(function () { location.href = "messages"; }, 900); }
        });
        RT.userChannel.bind("group-updated", function (d) {
            var c = findConvo("g", d.gid); if (c) { c.name = d.name; if (d.logo !== undefined) c.logo = d.logo; }
            if (openIs("g", d.gid)) { S.open.name = d.name; if (d.logo !== undefined) S.open.logo = d.logo; if (el.head) replaceHead(); }
            addSys(d.gid, d.sys);
            if (openIs("g", d.gid)) { var cc = findConvo("g", d.gid); if (cc) cc.unread = 0; }
        });

        if (S.open) {
            chanTyping = RT.pusher.subscribe(S.open.channel);
            chanTyping.bind("client-typing", function (d) {
                if (d && d.from !== B.me) showTyping(d.name);
            });
        }

        var wasDown = false;
        RT.pusher.connection.bind("state_change", function (s) {
            if (s.current === "unavailable" || s.current === "failed" || s.current === "disconnected") wasDown = true;
            if (s.current === "connected" && wasDown) { wasDown = false; if (idle()) location.reload(); }
        });
    }
    function idle() { return (!el.ta || el.ta.value.trim() === "") && !S.files.length && modalEl.hidden; }

    /* ---------------------------------------------------------------- boot */
    document.getElementById("btn-new-group").addEventListener("click", newGroupModal);
    document.getElementById("list-search").addEventListener("input", function (e) { S.q = e.target.value.trim(); renderList(); });
    document.getElementById("chat-tabs").addEventListener("click", function (e) {
        var b = e.target.closest("button[data-f]"); if (!b) return;
        S.filter = b.dataset.f;
        Array.prototype.forEach.call(this.querySelectorAll("button"), function (x) { x.classList.toggle("on", x === b); });
        renderList();
    });

    // when you open a group from a chat that's in the list, show it with its latest info
    if (S.open && S.open.k === "g") { var gc = findConvo("g", S.open.id); if (gc) { gc.members = S.open.members; } }

    renderList();
    renderPane();
    bindRealtime();
    if (S.open) markRead();

    // the live connection is optional; without it the page refreshes itself now and then
    setInterval(function () {
        if (document.hidden || !idle()) return;
        if (RT && RT.pusher.connection.state === "connected") return;
        location.reload();
    }, 20000);
})();