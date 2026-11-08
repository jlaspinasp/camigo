<?php
session_start();

if (!isset($_SESSION['username'])) {
    header("Location: login.php");
    exit();
}

$first = $_SESSION['first_name'];
$initial = strtoupper(substr($first, 0, 1));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Dashboard | Camigo</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:wght@600;700;800&family=Instrument+Sans:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
</head>
<body>

<header class="topbar">
    <?php include("logo.php"); ?>

    <div class="user">
        <span class="avatar"><?php echo htmlspecialchars($initial); ?></span>
        <span class="name"><?php echo htmlspecialchars($first); ?></span>
        <a href="logout.php" class="out">Log out</a>
    </div>
</header>

<main class="main">

    <section class="hero">
        <h1>Welcome, <?php echo htmlspecialchars($first); ?>.</h1>
        <p>You're in. Clubs, student projects and campus events will show up here as Camigo grows.</p>
    </section>

    <h2 class="section-title">What's coming</h2>

    <section class="tiles">
        <div class="tile">
            <div class="ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="8" r="3.5"/><path d="M2.5 20c0-3.5 3-5.5 6.5-5.5s6.5 2 6.5 5.5"/><circle cx="17" cy="9" r="2.5"/><path d="M17.5 14.5c2.5 0 4 1.5 4 4"/></svg></div>
            <h3>Clubs</h3>
            <p>Discover student organizations and follow the ones you like.</p>
            <span class="soon">Coming soon</span>
        </div>
        <div class="tile">
            <div class="ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="16" rx="3"/><path d="M8 10l2.5 2.5L16 8"/></svg></div>
            <h3>Projects</h3>
            <p>Post your idea, find teammates, and build it together.</p>
            <span class="soon">Coming soon</span>
        </div>
        <div class="tile">
            <div class="ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="16" rx="3"/><path d="M3 10h18M8 3v4M16 3v4"/></svg></div>
            <h3>Events</h3>
            <p>See what's happening on campus this week.</p>
            <span class="soon">Coming soon</span>
        </div>
    </section>

</main>

</body>
</html>