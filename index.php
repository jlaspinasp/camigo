<?php
session_start();

// already logged in? skip the splash
if (isset($_SESSION['username'])) {
    header("Location: dashboard");
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Camigo</title>
    <noscript><meta http-equiv="refresh" content="2;url=login"></noscript>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:wght@700;800&family=Instrument+Sans:wght@500&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }

        :root {
            --ease: cubic-bezier(0.22, 0.8, 0.2, 1);
        }

        body {
            min-height: 100vh;
            min-height: 100dvh;
            display: grid;
            place-items: center;
            overflow: hidden;
            color: #fff;
            font-family: "Instrument Sans", "Segoe UI", system-ui, sans-serif;
            background: #0a0c2e;
            -webkit-font-smoothing: antialiased;
            transition: opacity 0.6s ease;
        }
        body.leave { opacity: 0; }
        body.leave .stage { transform: scale(1.03); }

        /* ---------- background: still, soft light ---------- */
        .bg {
            position: fixed; inset: 0;
            background:
                radial-gradient(900px 600px at 88% -5%, rgba(255, 200, 87, 0.16), transparent 62%),
                radial-gradient(900px 700px at -5% 105%, rgba(70, 80, 255, 0.30), transparent 62%),
                radial-gradient(ellipse at center, transparent 40%, rgba(4, 5, 24, 0.55) 100%),
                #0a0c2e;
        }
        /* fine film grain for a less flat, more tactile finish */
        .bg::after {
            content: "";
            position: absolute; inset: 0;
            opacity: 0.05;
            mix-blend-mode: overlay;
            background-image: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='160' height='160'><filter id='n'><feTurbulence type='fractalNoise' baseFrequency='0.9' numOctaves='2' stitchTiles='stitch'/></filter><rect width='100%' height='100%' filter='url(%23n)'/></svg>");
            pointer-events: none;
        }

        /* ---------- stage ---------- */
        .stage {
            position: relative;
            display: flex; flex-direction: column; align-items: center;
            padding: 24px;
            text-align: center;
            transition: transform 0.9s var(--ease);
        }

        .mark {
            --mk: clamp(84px, 13vmin, 120px);
            position: relative;
            width: var(--mk); height: var(--mk);
            margin-bottom: clamp(22px, 4vmin, 34px);
        }
        .mark svg { position: relative; z-index: 1; width: 100%; height: 100%; overflow: visible; }

        /* one quiet halo behind the mark */
        .glow {
            position: absolute; left: 50%; top: 50%;
            width: 260%; height: 260%;
            transform: translate(-50%, -50%);
            background: radial-gradient(circle, rgba(255, 150, 100, 0.22), transparent 60%);
            opacity: 0;
            animation: fadein 1.6s 0.1s ease forwards, breathe 6s 1.8s ease-in-out infinite;
        }

        .ring {
            stroke-dasharray: 100;
            stroke-dashoffset: 100;
            animation: draw 1.1s 0.25s var(--ease) forwards;
        }
        .dot {
            opacity: 0;
            transform-origin: 39.5px 24px;
            animation: land 0.7s 1s var(--ease) forwards;
        }

        /* wordmark */
        .word {
            font-family: "Bricolage Grotesque", "Segoe UI", system-ui, sans-serif;
            font-weight: 800;
            font-size: clamp(40px, 8vmin, 72px);
            line-height: 1;
            letter-spacing: 0.01em;
            padding-bottom: 0.08em;
            opacity: 0;
            transform: translateY(14px);
            filter: blur(8px);
            animation: reveal 1.1s 1.05s var(--ease) forwards;
        }

        /* hairline + tagline */
        .rule {
            width: 0; height: 1px;
            margin: clamp(16px, 3vmin, 24px) 0 clamp(14px, 2.6vmin, 20px);
            background: linear-gradient(90deg, transparent, rgba(255, 200, 87, 0.7), transparent);
            animation: grow 0.9s 1.5s var(--ease) forwards;
        }
        .tag {
            font-size: clamp(11px, 1.6vmin, 13px);
            font-weight: 500;
            letter-spacing: 0.24em;
            text-transform: uppercase;
            color: rgba(255, 255, 255, 0.6);
            opacity: 0;
            animation: rise 0.9s 1.65s var(--ease) forwards;
        }

        @keyframes draw   { to { stroke-dashoffset: 0; } }
        @keyframes land   { from { opacity: 0; transform: translateX(-10px) scale(0.4); } to { opacity: 1; transform: none; } }
        @keyframes reveal { to { opacity: 1; transform: none; filter: blur(0); letter-spacing: -0.03em; } }
        @keyframes grow   { to { width: clamp(90px, 14vmin, 140px); } }
        @keyframes rise   { from { opacity: 0; transform: translateY(8px); } to { opacity: 1; transform: none; } }
        @keyframes fadein { to { opacity: 1; } }
        @keyframes breathe { 0%, 100% { transform: translate(-50%, -50%) scale(1); } 50% { transform: translate(-50%, -50%) scale(1.08); } }

        @media (prefers-reduced-motion: reduce) {
            *, *::before, *::after { animation: none !important; transition-duration: 0.01s !important; }
            .ring { stroke-dashoffset: 0; }
            .dot, .tag, .glow { opacity: 1; }
            .word { opacity: 1; transform: none; filter: none; letter-spacing: -0.03em; }
            .rule { width: 110px; }
        }
    </style>
</head>
<body>

<div class="bg"></div>

<div class="stage">

    <div class="mark">
        <div class="glow"></div>
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

    <h1 class="word" aria-label="Camigo">Camigo</h1>
    <div class="rule"></div>
    <p class="tag">Your campus, all in one place</p>

</div>

<script>
    var go = function () {
        document.body.classList.add("leave");
        setTimeout(function () { window.location.href = "login"; }, 600);
    };

    var reduce = window.matchMedia("(prefers-reduced-motion: reduce)").matches;
    setTimeout(go, reduce ? 1200 : 3200);
</script>

</body>
</html>