<?php
session_start();

// already logged in? skip the splash
if (isset($_SESSION['username'])) {
    header("Location: dashboard.php");
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Camigo</title>
    <noscript><meta http-equiv="refresh" content="2;url=index.php"></noscript>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:wght@700;800&family=Instrument+Sans:wght@400;500&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            min-height: 100vh;
            display: grid;
            place-items: center;
            overflow: hidden;
            color: #fff;
            font-family: "Instrument Sans", "Segoe UI", system-ui, sans-serif;
            background: #0a0c33;
                transition: opacity 0.55s ease;
        }
        body.leave { opacity: 0; }
        body.leave .stage { transform: scale(1.08); }

        /* ---------- background ---------- */
        .bg { position: fixed; inset: 0; overflow: hidden; }
        .blob { position: absolute; border-radius: 50%; filter: blur(80px); opacity: 0.55; }
        .blob.a { width: 60vmax; height: 60vmax; left: -20vmax; bottom: -25vmax; background: #3a40e8; animation: drift1 14s ease-in-out infinite alternate; }
        .blob.b { width: 50vmax; height: 50vmax; right: -18vmax; top: -22vmax; background: #ff9d4d; opacity: 0.38; animation: drift2 16s ease-in-out infinite alternate; }
        .blob.c { width: 34vmax; height: 34vmax; left: 38%; top: 55%; background: #ff5f8f; opacity: 0.22; animation: drift1 18s ease-in-out infinite alternate-reverse; }
        .grid {
            position: absolute; inset: 0;
            background-image: radial-gradient(rgba(255, 255, 255, 0.11) 1px, transparent 1px);
            background-size: 28px 28px;
            -webkit-mask-image: radial-gradient(ellipse at center, #000 10%, transparent 72%);
            mask-image: radial-gradient(ellipse at center, #000 10%, transparent 72%);
        }

        /* ---------- stage ---------- */
        .stage {
            position: relative;
            text-align: center;
            padding: 24px;
            transition: transform 0.6s ease;
        }

        .mark {
            --mk: clamp(96px, 15vmin, 150px);
            position: relative;
            width: var(--mk);
            height: var(--mk);
            margin: 0 auto clamp(10px, 2.2vmin, 22px);
        }
        .mark svg { position: relative; z-index: 2; width: 100%; height: 100%; overflow: visible; filter: drop-shadow(0 12px 40px rgba(255, 130, 90, 0.35)); }

        /* pulsing glow */
        .glow {
            position: absolute; left: 50%; top: 50%;
            width: 220%; height: 220%;
            transform: translate(-50%, -50%);
            background: radial-gradient(circle, rgba(255, 160, 110, 0.32), transparent 62%);
            opacity: 0;
            animation: fadein 1.4s 0.2s ease forwards, breathe 4s 1.6s ease-in-out infinite;
        }

        /* ripples sent out when the dot lands */
        .ripple {
            position: absolute; left: 50%; top: 50%;
            width: 100%; height: 100%;
            margin: calc(var(--mk) / -2 + 0px) 0 0 calc(var(--mk) / -2);
            border-radius: 50%;
            border: 2px solid rgba(255, 200, 140, 0.55);
            opacity: 0;
            animation: ripple 3.2s ease-out infinite;
            z-index: 1;
        }
        .ripple:nth-of-type(1) { animation-delay: 1.75s; }
        .ripple:nth-of-type(2) { animation-delay: 2.55s; }
        .ripple:nth-of-type(3) { animation-delay: 3.35s; }

        /* orbits: people gathering around Camigo */
        .wrap {
            position: absolute; left: 50%; top: 50%;
            width: 0; height: 0;
            z-index: 1;
            opacity: 0;
            animation: orbitin 1.6s 0.4s cubic-bezier(0.2, 0.7, 0.2, 1) forwards;
        }
        .w1 { --r: clamp(100px, 15vmin, 180px); }
        .w2 { --r: clamp(160px, 24vmin, 290px); animation-delay: 0.7s; }
        .track {
            position: absolute; left: 0; top: 0;
            width: calc(var(--r) * 2); height: calc(var(--r) * 2);
            margin: calc(var(--r) * -1) 0 0 calc(var(--r) * -1);
            border: 1px dashed rgba(255, 255, 255, 0.14);
            border-radius: 50%;
        }
        .orbit { position: absolute; left: 0; top: 0; width: 0; height: 0; animation: spin 28s linear infinite; }
        .w2 .orbit { animation-duration: 46s; animation-direction: reverse; }
        .d {
            position: absolute; left: 0; top: 0;
            width: var(--s); height: var(--s);
            margin: calc(var(--s) / -2) 0 0 calc(var(--s) / -2);
            border-radius: 50%;
            background: var(--c);
            box-shadow: 0 0 22px var(--c);
            transform: rotate(var(--a)) translateX(var(--r));
        }

        /* ring + dot of the logo */
        .ring {
            stroke-dasharray: 100;
            stroke-dashoffset: 100;
            animation: draw 1.2s 0.35s cubic-bezier(0.6, 0, 0.2, 1) forwards;
        }
        .dot {
            opacity: 0;
            transform-origin: 39.5px 24px;
            animation: drop 0.8s 1.3s cubic-bezier(0.3, 1.7, 0.5, 1) forwards;
        }

        /* wordmark, letter by letter */
        .word {
            display: flex;
            justify-content: center;
            overflow: hidden;
            padding: 0.04em 0.06em 0.16em;
            font-family: "Bricolage Grotesque", "Segoe UI", system-ui, sans-serif;
            font-weight: 800;
            font-size: clamp(46px, 9.5vmin, 96px);
            letter-spacing: -0.035em;
            line-height: 1;
        }
        .word span {
            display: inline-block;
            transform: translateY(110%);
            animation: up 0.9s cubic-bezier(0.2, 0.8, 0.2, 1) forwards;
            animation-delay: calc(1.5s + var(--i) * 0.07s);
        }

        .tag {
            margin-top: clamp(4px, 1.4vmin, 14px);
            font-size: clamp(14px, 2.1vmin, 18px);
            color: rgba(255, 255, 255, 0.72);
            opacity: 0;
            animation: rise 0.9s 2.1s cubic-bezier(0.2, 0.7, 0.2, 1) forwards;
        }

        .bar {
            width: clamp(120px, 16vmin, 180px); height: 3px;
            margin: clamp(22px, 4.5vmin, 40px) auto 0;
            border-radius: 4px;
            background: rgba(255, 255, 255, 0.14);
            overflow: hidden;
            opacity: 0;
            animation: fadein 0.5s 2.2s ease forwards;
        }
        .bar i {
            display: block; height: 100%; width: 0;
            border-radius: 4px;
            background: linear-gradient(90deg, #ffc857, #ff6b6b);
            animation: fill 2.2s 2.3s ease-in-out forwards;
        }


        @keyframes draw    { to { stroke-dashoffset: 0; } }
        @keyframes drop    { from { opacity: 0; transform: translateX(-18px) scale(0.2); } to { opacity: 1; transform: none; } }
        @keyframes up      { to { transform: none; } }
        @keyframes rise    { from { opacity: 0; transform: translateY(14px); } to { opacity: 1; transform: none; } }
        @keyframes fadein  { to { opacity: 1; } }
        @keyframes fill    { to { width: 100%; } }
        @keyframes spin    { to { transform: rotate(360deg); } }
        @keyframes orbitin { from { opacity: 0; transform: scale(0.55); } to { opacity: 1; transform: scale(1); } }
        @keyframes ripple  { 0% { opacity: 0.55; transform: scale(1); } 100% { opacity: 0; transform: scale(3.4); } }
        @keyframes breathe { 0%, 100% { transform: translate(-50%, -50%) scale(1); } 50% { transform: translate(-50%, -50%) scale(1.12); } }
        @keyframes drift1  { to { transform: translate(6vmax, -5vmax) scale(1.1); } }
        @keyframes drift2  { to { transform: translate(-6vmax, 5vmax) scale(1.08); } }

        @media (prefers-reduced-motion: reduce) {
            *, *::before, *::after { animation: none !important; }
            .ring { stroke-dashoffset: 0; }
            .dot, .tag, .bar, .glow, .wrap { opacity: 1; }
            .word span { transform: none; }
            .bar i { width: 100%; }
            .ripple { display: none; }
        }
    </style>
</head>
<body>

<div class="bg">
    <div class="blob a"></div>
    <div class="blob b"></div>
    <div class="blob c"></div>
    <div class="grid"></div>
</div>

<div class="stage">

    <div class="mark">
        <div class="glow"></div>

        <!-- two orbits of "students" gathering around Camigo -->
        <div class="wrap w1">
            <div class="track"></div>
            <div class="orbit">
                <i class="d" style="--s:14px; --c:#ffc857; --a:20deg"></i>
                <i class="d" style="--s:10px; --c:#9fb0ff; --a:140deg"></i>
                <i class="d" style="--s:12px; --c:#ff8aa5; --a:255deg"></i>
            </div>
        </div>
        <div class="wrap w2">
            <div class="track"></div>
            <div class="orbit">
                <i class="d" style="--s:10px; --c:#ffffff; --a:60deg"></i>
                <i class="d" style="--s:16px; --c:#ff6b6b; --a:170deg"></i>
                <i class="d" style="--s:11px; --c:#8f9bff; --a:230deg"></i>
                <i class="d" style="--s:9px;  --c:#ffc857; --a:310deg"></i>
            </div>
        </div>

        <i class="ripple"></i><i class="ripple"></i><i class="ripple"></i>

        <svg viewBox="0 0 48 48" aria-hidden="true">
            <defs>
                <linearGradient id="sg" x1="0" y1="0" x2="1" y2="1">
                    <stop offset="0" stop-color="#ffc857"/>
                    <stop offset="1" stop-color="#ff6b6b"/>
                </linearGradient>
            </defs>
            <path class="ring" pathLength="100" d="M34.72 33 A14 14 0 1 1 34.72 15" fill="none" stroke="url(#sg)" stroke-width="6" stroke-linecap="round"/>
            <circle class="dot" cx="39.5" cy="24" r="3.4" fill="#fff"/>
        </svg>
    </div>

    <h1 class="word" aria-label="Camigo">
        <span style="--i:0">C</span><span style="--i:1">a</span><span style="--i:2">m</span><span style="--i:3">i</span><span style="--i:4">g</span><span style="--i:5">o</span>
    </h1>
    <p class="tag">Campus Amigo. Your campus, all in one place.</p>

    <div class="bar"><i></i></div>
</div>

<script>
    var go = function () {
        document.body.classList.add("leave");
        setTimeout(function () { window.location.href = "index.php"; }, 550);
    };

    var reduce = window.matchMedia("(prefers-reduced-motion: reduce)").matches;
    setTimeout(go, reduce ? 1500 : 4800);
</script>

</body>
</html>