// show / hide password
document.querySelectorAll(".eye").forEach(function (btn) {
    btn.addEventListener("click", function () {
        var input = document.getElementById(btn.dataset.target);
        var show = input.type === "password";
        input.type = show ? "text" : "password";
        btn.setAttribute("aria-pressed", show ? "true" : "false");
    });
});

// password strength meter (register page only)
var pw = document.getElementById("password");
var meter = document.getElementById("meter");
if (pw && meter) {
    pw.addEventListener("input", function () {
        var v = pw.value, score = 0;
        if (v.length >= 8) score++;
        if (/[A-Z]/.test(v) && /[a-z]/.test(v)) score++;
        if (/\d/.test(v)) score++;
        if (/[^A-Za-z0-9]/.test(v) || v.length >= 12) score++;
        meter.dataset.level = v.length ? Math.max(score, 1) : 0;
    });
}

// confirm password match hint (register page only)
var cpw = document.getElementById("confirm_password");
var hint = document.getElementById("match-hint");
if (pw && cpw && hint) {
    function check() {
        if (!cpw.value) { hint.textContent = ""; hint.className = "hint"; return; }
        var ok = pw.value === cpw.value;
        hint.textContent = ok ? "Passwords match" : "Passwords do not match yet";
        hint.className = "hint " + (ok ? "good" : "bad");
    }
    pw.addEventListener("input", check);
    cpw.addEventListener("input", check);
}