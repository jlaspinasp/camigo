<?php
session_start();
require("db.php");

if (isset($_SESSION['username'])) {
    header("Location: dashboard.php");
    exit();
}

$message = "";

if (isset($_POST['login'])) {

    $username = trim($_POST['username']);
    $password = $_POST['password'];

    $stmt = mysqli_prepare($conn, "SELECT * FROM users WHERE username = ?");
    mysqli_stmt_bind_param($stmt, "s", $username);
    mysqli_stmt_execute($stmt);

    $result = mysqli_stmt_get_result($stmt);

    if (mysqli_num_rows($result) > 0) {

        $user = mysqli_fetch_assoc($result);

        if (password_verify($password, $user['password'])) {

            session_regenerate_id(true);

            $_SESSION['username'] = $user['username'];
            $_SESSION['first_name'] = $user['first_name'];

            // "Remember me" keeps the login cookie for 30 days
            if (isset($_POST['remember'])) {
                setcookie(session_name(), session_id(), time() + 60 * 60 * 24 * 30, "/");
            }

            header("Location: dashboard.php");
            exit();

        } else {
            $message = "Incorrect password. Please try again.";
        }

    } else {
        $message = "We couldn't find that username.";
    }

    mysqli_stmt_close($stmt);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Log in | Camigo</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:wght@600;700;800&family=Instrument+Sans:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
</head>
<body>

<div class="auth">

    <aside class="aside">
        <?php include("logo.php"); ?>

        <div>
            <h2 class="headline">Your campus, all in one place.</h2>
            <p class="sub">Find clubs, join student projects, and meet the people building things at your university.</p>

            <div class="feed">
                <div class="feed-card">
                    <div class="av">RC</div>
                    <div class="txt"><strong>Robotics Club</strong><small>Build night this Thursday</small></div>
                    <span class="chip">Open</span>
                </div>
                <div class="feed-card">
                    <div class="av">CS</div>
                    <div class="txt"><strong>Capstone Showcase</strong><small>Looking for 2 designers</small></div>
                    <span class="chip">Recruiting</span>
                </div>
                <div class="feed-card">
                    <div class="av">DS</div>
                    <div class="txt"><strong>Debate Society</strong><small>New members welcome</small></div>
                    <span class="chip">Open</span>
                </div>
            </div>
        </div>
    </aside>

    <main class="form-wrap">
        <div class="form">

            <h1>Welcome back</h1>
            <p class="lead">Log in to your Camigo account to pick up where you left off.</p>

            <?php if ($message != "") { ?>
                <div class="msg error"><?php echo htmlspecialchars($message); ?></div>
            <?php } ?>

            <form method="POST">

                <div class="field">
                    <label for="username">Username</label>
                    <input type="text" name="username" id="username" placeholder="Enter your username"
                           autocomplete="username" required
                           value="<?php echo isset($_POST['username']) ? htmlspecialchars($_POST['username']) : ''; ?>">
                </div>

                <div class="field">
                    <label for="password">Password</label>
                    <div class="input-wrap">
                        <input type="password" name="password" id="password" placeholder="Enter your password"
                               autocomplete="current-password" required>
                        <button type="button" class="eye" data-target="password" aria-pressed="false" aria-label="Show password"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s4-7 10-7 10 7 10 7-4 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/><path class="slash" d="M4 4l16 16"/></svg></button>
                    </div>
                </div>

                <label class="remember">
                    <input type="checkbox" name="remember"> Keep me logged in for 30 days
                </label>

                <button type="submit" name="login" class="btn">
                    Log in
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
                </button>

            </form>

            <div class="divider">or</div>

            <button type="button" class="btn-google">
                <svg viewBox="0 0 48 48"><path fill="#EA4335" d="M24 9.5c3.5 0 6.6 1.2 9.1 3.6l6.8-6.8C35.8 2.4 30.3 0 24 0 14.6 0 6.5 5.4 2.6 13.2l7.9 6.1C12.4 13.6 17.7 9.5 24 9.5z"/><path fill="#4285F4" d="M46.5 24.5c0-1.6-.1-3.1-.4-4.5H24v9h12.7c-.6 3-2.3 5.5-4.8 7.2l7.5 5.8c4.4-4 7.1-10 7.1-17.5z"/><path fill="#FBBC05" d="M10.5 28.7c-.5-1.4-.8-3-.8-4.7s.3-3.2.8-4.7l-7.9-6.1C.9 16.4 0 20.1 0 24s.9 7.6 2.6 10.8l7.9-6.1z"/><path fill="#34A853" d="M24 48c6.5 0 11.9-2.1 15.9-5.8l-7.5-5.8c-2.1 1.4-4.9 2.3-8.4 2.3-6.3 0-11.6-4.1-13.5-9.8l-7.9 6.1C6.5 42.6 14.6 48 24 48z"/></svg>
                Continue with Google
            </button>

            <p class="switch">New to Camigo? <a href="register.php">Create an account</a></p>

        </div>
    </main>

</div>

<script src="script.js"></script>
</body>
</html>