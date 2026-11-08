<?php
require("db.php");

$message = "";
$message_class = "";

if (isset($_POST['register'])) {

    $first_name = trim($_POST['first_name']);
    $last_name = trim($_POST['last_name']);
    $middle_initial = trim($_POST['middle_initial']);
    $birthday = $_POST['birthday'];
    $username = trim($_POST['username']);

    if ($_POST['password'] !== $_POST['confirm_password']) {

        $message = "Your passwords do not match.";
        $message_class = "error";

    } elseif (strlen($_POST['password']) < 8) {

        $message = "Your password needs at least 8 characters.";
        $message_class = "error";

    } else {

        $password = password_hash($_POST['password'], PASSWORD_DEFAULT);

        $stmt = mysqli_prepare($conn, "INSERT INTO users
            (first_name, last_name, middle_initial, birthday, username, password)
            VALUES (?, ?, ?, ?, ?, ?)");

        mysqli_stmt_bind_param(
            $stmt,
            "ssssss",
            $first_name,
            $last_name,
            $middle_initial,
            $birthday,
            $username,
            $password
        );

        if (mysqli_stmt_execute($stmt)) {
            $message = "Your account is ready. You can log in now.";
            $message_class = "success";
            $_POST = array(); // clear the form
        } else {
            if (mysqli_stmt_errno($stmt) == 1062) {
                $message = "That username is already taken. Try another one.";
            } else {
                $message = "Something went wrong. Please try again.";
            }

            $message_class = "error";
        }

        mysqli_stmt_close($stmt);
    }
}

// keeps what the user typed if there is an error
function old($name) {
    return isset($_POST[$name]) ? htmlspecialchars($_POST[$name]) : "";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Create account | Camigo</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:wght@600;700;800&family=Instrument+Sans:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
</head>
<body>

<div class="auth flip">

    <main class="form-wrap">
        <div class="form">

            <h1>Create your account</h1>
            <p class="lead">It takes a minute. Then your campus is yours to explore.</p>

            <?php if ($message != "") { ?>
                <div class="msg <?php echo $message_class; ?>">
                    <?php echo htmlspecialchars($message); ?>
                    <?php if ($message_class == "success") { ?>
                        <a href="index.php">Go to log in</a>
                    <?php } ?>
                </div>
            <?php } ?>

            <form method="POST">

                <div class="field">
                    <div class="row-name">
                        <div>
                            <label for="first_name">First name</label>
                            <input type="text" name="first_name" id="first_name" required value="<?php echo old('first_name'); ?>">
                        </div>
                        <div>
                            <label for="last_name">Last name</label>
                            <input type="text" name="last_name" id="last_name" required value="<?php echo old('last_name'); ?>">
                        </div>
                        <div>
                            <label for="middle_initial">M.I.</label>
                            <input type="text" name="middle_initial" id="middle_initial" maxlength="5" value="<?php echo old('middle_initial'); ?>">
                        </div>
                    </div>
                </div>

                <div class="field">
                    <label for="birthday">Birthday</label>
                    <input type="date" name="birthday" id="birthday" max="<?php echo date('Y-m-d'); ?>" required value="<?php echo old('birthday'); ?>">
                </div>

                <div class="field">
                    <label for="username">Username</label>
                    <input type="text" name="username" id="username" placeholder="Choose a username" autocomplete="username" required value="<?php echo old('username'); ?>">
                </div>

                <div class="field">
                    <label for="password">Password</label>
                    <div class="input-wrap">
                        <input type="password" name="password" id="password" placeholder="At least 8 characters" minlength="8" autocomplete="new-password" required>
                        <button type="button" class="eye" data-target="password" aria-pressed="false" aria-label="Show password"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s4-7 10-7 10 7 10 7-4 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/><path class="slash" d="M4 4l16 16"/></svg></button>
                    </div>
                    <div class="meter" id="meter" data-level="0"><i></i><i></i><i></i><i></i></div>
                </div>

                <div class="field">
                    <label for="confirm_password">Confirm password</label>
                    <div class="input-wrap">
                        <input type="password" name="confirm_password" id="confirm_password" placeholder="Type it again" autocomplete="new-password" required>
                        <button type="button" class="eye" data-target="confirm_password" aria-pressed="false" aria-label="Show password"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s4-7 10-7 10 7 10 7-4 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/><path class="slash" d="M4 4l16 16"/></svg></button>
                    </div>
                    <div class="hint" id="match-hint"></div>
                </div>

                <button type="submit" name="register" class="btn">
                    Create account
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
                </button>

            </form>

            <p class="switch">Already have an account? <a href="index.php">Log in</a></p>

        </div>
    </main>

    <aside class="aside warm">
        <?php include("logo.php"); ?>

        <div>
            <h2 class="headline">Join the people building your campus.</h2>
            <p class="sub">One account for every club, project and event at your university.</p>

            <ol class="steps">
                <li>
                    <span class="n">1</span>
                    <div><strong>Create your profile</strong><span>Add your name and birthday and pick a username.</span></div>
                </li>
                <li>
                    <span class="n">2</span>
                    <div><strong>Find your people</strong><span>Browse clubs and follow the ones that fit you.</span></div>
                </li>
                <li>
                    <span class="n">3</span>
                    <div><strong>Start something</strong><span>Post a project and invite teammates to join.</span></div>
                </li>
            </ol>
        </div>
    </aside>

</div>

<script src="script.js"></script>
</body>
</html>