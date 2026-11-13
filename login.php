<?php
session_start();
require("db.php");
require("google_config.php");

if (isset($_SESSION['username'])) {
    header("Location: dashboard");
    exit();
}

// inline errors: one slot per field, plus a general one (e.g. Google sign-in)
$errors = ["username" => "", "password" => "", "general" => ""];

// errors sent back from google-callback.php
if (isset($_SESSION['login_error'])) {
    $errors["general"] = $_SESSION['login_error'];
    unset($_SESSION['login_error']);
}

if (isset($_POST['login'])) {

    $username = trim($_POST['username']);
    $password = $_POST['password'];

    $stmt = mysqli_prepare($conn, "SELECT * FROM users WHERE username = ?");
    mysqli_stmt_bind_param($stmt, "s", $username);
    mysqli_stmt_execute($stmt);

    $result = mysqli_stmt_get_result($stmt);

    if (mysqli_num_rows($result) > 0) {

        $user = mysqli_fetch_assoc($result);

        if (empty($user['password'])) {
            $errors["username"] = "This account uses Google sign-in. Use Continue with Google below.";

        } elseif (password_verify($password, $user['password'])) {

            session_regenerate_id(true);

            $_SESSION['username'] = $user['username'];
            $_SESSION['first_name'] = $user['first_name'];

            // "Remember me" keeps the login cookie for 30 days
            if (isset($_POST['remember'])) {
                setcookie(session_name(), session_id(), time() + 60 * 60 * 24 * 30, "/");
            }

            header("Location: dashboard");
            exit();

        } else {
            // same wording as an unknown username, so the form never reveals which usernames exist
            $errors["password"] = "Username or password is incorrect.";
        }

    } else {
        $errors["password"] = "Username or password is incorrect.";
    }

    mysqli_stmt_close($stmt);
}

// a wrong username/password marks both fields; the message itself shows once, under the password
$bad_credentials = $errors["password"] !== "";
$user_invalid = $bad_credentials || $errors["username"] !== "";
$pass_invalid = $bad_credentials;

$err_icon = '<svg viewBox="0 0 16 16" aria-hidden="true"><circle cx="8" cy="8" r="8"/><path d="M8 4.4v4.2" stroke="#fff" stroke-width="1.7" stroke-linecap="round"/><circle cx="8" cy="11.4" r="1" fill="#fff"/></svg>';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Log in | Camigo</title>
    <link rel="icon" href="favicon.svg" type="image/svg+xml">
    <link rel="icon" href="favicon.ico" sizes="any">
    <link rel="apple-touch-icon" href="apple-touch-icon.png">
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

        </div>
    </aside>

    <main class="form-wrap">
        <div class="form">

            <h1>Welcome back</h1>
            <p class="lead">Log in to your Camigo account to pick up where you left off.</p>

            <form method="POST" data-error-scope="form">

                <?php if ($errors["general"] !== "") { ?>
                    <p class="form-error" role="alert"><?php echo $err_icon . htmlspecialchars($errors["general"]); ?></p>
                <?php } ?>

                <div class="field has-slot<?php echo $user_invalid ? ' is-invalid' : ''; ?>">
                    <label for="username">Username</label>
                    <input type="text" name="username" id="username" placeholder="Enter your username"
                           autocomplete="username" required
                           <?php if ($user_invalid) { ?>aria-invalid="true"<?php } ?>
                           <?php if ($errors["username"] !== "") { ?>aria-describedby="username-error" autofocus<?php } ?>
                           value="<?php echo isset($_POST['username']) ? htmlspecialchars($_POST['username']) : ''; ?>">
                    <p class="field-error" id="username-error" role="alert"><?php
                        if ($errors["username"] !== "") echo $err_icon . htmlspecialchars($errors["username"]);
                    ?></p>
                </div>

                <div class="field has-slot<?php echo $pass_invalid ? ' is-invalid' : ''; ?>">
                    <label for="password">Password</label>
                    <div class="input-wrap">
                        <input type="password" name="password" id="password" placeholder="Enter your password"
                               autocomplete="current-password" required
                               <?php if ($pass_invalid) { ?>aria-invalid="true" aria-describedby="password-error" autofocus<?php } ?>>
                        <button type="button" class="eye" data-target="password" aria-pressed="false" aria-label="Show password"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s4-7 10-7 10 7 10 7-4 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/><path class="slash" d="M4 4l16 16"/></svg></button>
                    </div>
                    <p class="field-error" id="password-error" role="alert"><?php
                        if ($errors["password"] !== "") echo $err_icon . htmlspecialchars($errors["password"]);
                    ?></p>
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

            <div id="google-btn" class="google-wrap"></div>

            <p class="switch">New to Camigo? <a href="register">Create an account</a></p>

        </div>
    </main>

</div>

<script src="https://accounts.google.com/gsi/client?hl=en" async defer></script>
<script>
window.addEventListener("load", function () {
    if (!window.google || !google.accounts) return;
    google.accounts.id.initialize({
        client_id: "<?php echo GOOGLE_CLIENT_ID; ?>",
        ux_mode: "redirect",
        login_uri: "<?php echo htmlspecialchars(google_login_uri()); ?>"
    });
    var el = document.getElementById("google-btn");
    google.accounts.id.renderButton(el, {
        theme: "outline",
        size: "large",
        text: "continue_with",
        shape: "rectangular",
        locale: "en",
        width: Math.min(el.offsetWidth, 400)
    });
});
</script>
<script src="script.js"></script>
</body>
</html>