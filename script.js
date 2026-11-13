// show / hide password
document.querySelectorAll(".eye").forEach(function (btn) {
    btn.addEventListener("click", function () {
        var input = document.getElementById(btn.dataset.target);
        var show = input.type === "password";
        input.type = show ? "text" : "password";
        btn.setAttribute("aria-pressed", show ? "true" : "false");
    });
});

// ---------- inline field feedback (errors and successes share one slot) ----------
var FB_ICON = {
    error: '<svg viewBox="0 0 16 16" aria-hidden="true"><circle cx="8" cy="8" r="8"/><path d="M8 4.4v4.2" stroke="#fff" stroke-width="1.7" stroke-linecap="round"/><circle cx="8" cy="11.4" r="1" fill="#fff"/></svg>',
    ok: '<svg viewBox="0 0 16 16" aria-hidden="true"><circle cx="8" cy="8" r="8"/><path d="M4.7 8.3l2.2 2.2 4.4-4.7" fill="none" stroke="#fff" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg>'
};

// holder = the element that carries .is-invalid / .is-valid (a .field, or one column inside it)
// state  = "error" | "ok" | null (clear). The slot is always reserved, so nothing moves.
function setFeedback(holder, state, text) {
    var field = holder.closest(".field") || holder;
    var slot = field.querySelector(".field-error");
    var input = holder.querySelector("input");
    if (!slot) return;
    clearTimeout(slot._t);
    delete holder.dataset.server;
    holder.classList.remove("is-invalid", "is-valid", "is-clearing");
    field.classList.remove("is-clearing");
    slot.classList.remove("ok");
    if (input) input.removeAttribute("aria-invalid");
    if (!state) { slot.textContent = ""; return; }
    slot.innerHTML = FB_ICON[state];
    slot.appendChild(document.createTextNode(text));
    slot.classList.toggle("ok", state === "ok");
    holder.classList.add(state === "ok" ? "is-valid" : "is-invalid");
    if (state === "error" && input) input.setAttribute("aria-invalid", "true");
}

function fadeFeedback(holder) {
    var field = holder.closest(".field") || holder;
    var slot = field.querySelector(".field-error");
    holder.classList.add("is-clearing");
    holder.classList.remove("is-invalid");
    if (!slot) return;
    slot._t = setTimeout(function () { setFeedback(holder, null); }, 160);
}

// errors that came back from the server clear as soon as the person edits
document.querySelectorAll(".is-invalid").forEach(function (h) { h.dataset.server = "1"; });
document.querySelectorAll("form").forEach(function (form) {
    form.addEventListener("input", function (e) {
        var holder = e.target.closest("[data-server]");
        if (!holder) return;
        if (form.dataset.errorScope === "form") {
            form.querySelectorAll("[data-server]").forEach(fadeFeedback);
        } else {
            fadeFeedback(holder);
        }
    });
});

// password strength meter + live feedback (register page only)
var pw = document.getElementById("password");
var meter = document.getElementById("meter");
var cpw = document.getElementById("confirm_password");
var pwField = document.getElementById("pw-field");
var cField = document.getElementById("confirm-field");

function passwordFeedback(onBlur) {
    var v = pw.value;
    if (!v.length) return setFeedback(pwField, null);
    if (v.length < 8) {
        return onBlur ? setFeedback(pwField, "error", "Use at least 8 characters.") : setFeedback(pwField, null);
    }
    var lvl = +meter.dataset.level;
    setFeedback(pwField, "ok", lvl >= 4 ? "Strong password" : lvl >= 2 ? "Good password" : "Meets the minimum length");
}

function matchFeedback(onBlur) {
    if (!cpw.value) return setFeedback(cField, null);
    if (pw.value === cpw.value) return setFeedback(cField, "ok", "Passwords match");
    if (onBlur || cpw.value.length >= pw.value.length) return setFeedback(cField, "error", "Passwords don\u2019t match.");
    setFeedback(cField, null);
}

if (pw && meter && pwField) {
    pw.addEventListener("input", function () {
        var v = pw.value, score = 0;
        if (v.length >= 8) score++;
        if (/[A-Z]/.test(v) && /[a-z]/.test(v)) score++;
        if (/\d/.test(v)) score++;
        if (/[^A-Za-z0-9]/.test(v) || v.length >= 12) score++;
        meter.dataset.level = v.length ? Math.max(score, 1) : 0;
        passwordFeedback(false);
        if (cpw && cpw.value) matchFeedback(false);
    });
    pw.addEventListener("blur", function () { passwordFeedback(true); });
}
if (pw && cpw && cField) {
    cpw.addEventListener("input", function () { matchFeedback(false); });
    cpw.addEventListener("blur", function () { matchFeedback(true); });
}

// middle initial: one letter only, always uppercase
var mi = document.getElementById("middle_initial");
if (mi) {
    mi.addEventListener("input", function () {
        mi.value = mi.value.replace(/[^\p{L}]/gu, "").slice(0, 1).toUpperCase();
    });
}

// ---------- signup rules: names, username, birthday (register page) ----------
// names: people can type them any way they like; the server saves and shows them as "Zach"

// username: letters, numbers, "." and "_" only
var un = document.getElementById("username");
if (un && document.getElementById("username-error")) {
    var unField = un.closest(".field");
    var unBad = /[^A-Za-z0-9._]/;
    function usernameFeedback(onBlur) {
        var v = un.value;
        if (!v) return setFeedback(unField, null);
        if (unBad.test(v)) return setFeedback(unField, "error", "Only letters, numbers, . and _ are allowed.");
        if (onBlur && v.length < 3) return setFeedback(unField, "error", "Use at least 3 characters.");
        setFeedback(unField, null);
    }
    un.addEventListener("input", function () { usernameFeedback(false); });
    un.addEventListener("blur", function () { usernameFeedback(true); });
}

// birthday: 16 or older
var bd = document.getElementById("birthday");
if (bd && document.getElementById("birthday-error")) {
    var bdField = bd.closest(".field");
    var minAge = +bd.dataset.minAge || 16;
    function birthdayFeedback() {
        if (!bd.value) return setFeedback(bdField, null);
        var d = new Date(bd.value + "T00:00:00"), limit = new Date();
        limit.setHours(0, 0, 0, 0);
        limit.setFullYear(limit.getFullYear() - minAge);
        if (isNaN(d) || d > limit) return setFeedback(bdField, "error", "You need to be at least " + minAge + " to join Camigo.");
        setFeedback(bdField, null);
    }
    bd.addEventListener("change", birthdayFeedback);
    bd.addEventListener("blur", birthdayFeedback);
}

// block submit while the username or birthday is invalid (the server checks again anyway)
var regBtn = document.querySelector("form button[name='register'], form button[name='save']");
if (regBtn) {
    regBtn.closest("form").addEventListener("submit", function (e) {
        if (un) usernameFeedback(true);
        if (bd) birthdayFeedback();
        var bad = (un && unField.classList.contains("is-invalid")) ? un : (bd && bdField.classList.contains("is-invalid")) ? bd : null;
        if (bad) { e.preventDefault(); bad.focus(); }
    });
}