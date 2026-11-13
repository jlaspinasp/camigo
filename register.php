<?php
require("db.php");
require("validate-lib.php");

// inline feedback: one slot per field, plus a general one for the form
$errors = ["birthday" => "", "initial" => "", "username" => "", "password" => "", "confirm" => "", "general" => ""];
$success = "";

if (isset($_POST['register'])) {

    $first_name = proper_name($_POST['first_name']);
    $last_name = proper_name($_POST['last_name']);
    $middle_initial = trim($_POST['middle_initial']);
    if ($middle_initial !== "") {
        $middle_initial = function_exists('mb_strtoupper') ? mb_strtoupper($middle_initial, 'UTF-8') : strtoupper($middle_initial);
    }
    $birthday = $_POST['birthday'];
    $username = trim($_POST['username']);

    if (!username_ok($username)) {

        $errors["username"] = "Use 3–30 letters, numbers, dots or underscores only.";

    } elseif (!birthday_ok($birthday)) {

        $errors["birthday"] = "You need to be at least " . MIN_AGE . " to join Camigo.";

    } elseif ($_POST['password'] !== $_POST['confirm_password']) {

        $errors["confirm"] = "Passwords don’t match.";
        $errors["password"] = " "; // marks the password field too, without repeating the message

    } elseif ($middle_initial !== "" && !preg_match('/^\p{L}$/u', $middle_initial)) {

        $errors["initial"] = "Middle initial must be a single letter.";

    } elseif (strlen($_POST['password']) < 8) {

        $errors["password"] = "Use at least 8 characters.";

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
            $success = "Your account is ready.";
            $_POST = array(); // clear the form
        } else {
            if (mysqli_stmt_errno($stmt) == 1062) {
                $errors["username"] = "That username is taken. Try another one.";
            } else {
                $errors["general"] = "Something went wrong. Please try again.";
            }
        }

        mysqli_stmt_close($stmt);
    }
}

// keeps what the user typed if there is an error
function old($name) {
    return isset($_POST[$name]) ? htmlspecialchars($_POST[$name]) : "";
}

// helpers for the inline feedback markup
$icon_err = '<svg viewBox="0 0 16 16" aria-hidden="true"><circle cx="8" cy="8" r="8"/><path d="M8 4.4v4.2" stroke="#fff" stroke-width="1.7" stroke-linecap="round"/><circle cx="8" cy="11.4" r="1" fill="#fff"/></svg>';
$icon_ok  = '<svg viewBox="0 0 16 16" aria-hidden="true"><circle cx="8" cy="8" r="8"/><path d="M4.7 8.3l2.2 2.2 4.4-4.7" fill="none" stroke="#fff" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg>';

function invalid_class($msg) { return $msg !== "" ? " is-invalid" : ""; }
function invalid_attr($msg, $id) {
    return $msg !== "" ? 'aria-invalid="true" aria-describedby="' . $id . '"' : "";
}
function error_slot($id, $msg, $icon) {
    $inner = trim($msg) !== "" ? $icon . htmlspecialchars($msg) : "";
    return '<p class="field-error" id="' . $id . '" role="alert">' . $inner . '</p>';
}

$focus = "";
if ($errors["username"] !== "") $focus = "username";
elseif ($errors["birthday"] !== "") $focus = "birthday";
elseif ($errors["initial"] !== "") $focus = "middle_initial";
elseif ($errors["password"] !== "") $focus = "password";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Create account | Camigo</title>
    <link rel="icon" href="favicon.svg" type="image/svg+xml">
    <link rel="icon" href="favicon.ico" sizes="any">
    <link rel="apple-touch-icon" href="apple-touch-icon.png">
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

            <form method="POST">

                <?php if ($success !== "") { ?>
                    <p class="form-error ok" role="status"><?php echo $icon_ok . htmlspecialchars($success); ?> <a href="login">Go to log in</a></p>
                <?php } ?>
                <?php if ($errors["general"] !== "") { ?>
                    <p class="form-error" role="alert"><?php echo $icon_err . htmlspecialchars($errors["general"]); ?></p>
                <?php } ?>

                <div class="field has-slot">
                    <div class="row-name">
                        <div>
                            <label for="first_name">First name</label>
                            <input type="text" name="first_name" id="first_name" maxlength="100" autocapitalize="words" required value="<?php echo old('first_name'); ?>">
                        </div>
                        <div>
                            <label for="last_name">Last name</label>
                            <input type="text" name="last_name" id="last_name" maxlength="100" autocapitalize="words" required value="<?php echo old('last_name'); ?>">
                        </div>
                        <div class="<?php echo trim(invalid_class($errors["initial"])); ?>">
                            <label for="middle_initial">M.I. <span class="opt">(opt.)</span></label>
                            <input type="text" name="middle_initial" id="middle_initial" maxlength="1" autocomplete="off" <?php echo invalid_attr($errors["initial"], "initial-error"); ?><?php echo $focus === "middle_initial" ? " autofocus" : ""; ?> value="<?php echo old('middle_initial'); ?>">
                        </div>
                    </div>
                    <?php echo error_slot("initial-error", $errors["initial"], $icon_err); ?>
                </div>

                <div class="field has-slot<?php echo invalid_class($errors["birthday"]); ?>">
                    <label for="birthday">Birthday</label>
                    <input type="date" name="birthday" id="birthday" max="<?php echo min_birthday_date(); ?>" data-min-age="<?php echo MIN_AGE; ?>" required <?php echo invalid_attr($errors["birthday"], "birthday-error"); ?><?php echo $focus === "birthday" ? " autofocus" : ""; ?> value="<?php echo old('birthday'); ?>">
                    <?php echo error_slot("birthday-error", $errors["birthday"], $icon_err); ?>
                </div>

                <div class="field has-slot<?php echo invalid_class($errors["username"]); ?>">
                    <label for="username">Username</label>
                    <input type="text" name="username" id="username" placeholder="Letters, numbers, . and _" maxlength="30" autocomplete="username" required <?php echo invalid_attr($errors["username"], "username-error"); ?><?php echo $focus === "username" ? " autofocus" : ""; ?> value="<?php echo old('username'); ?>">
                    <?php echo error_slot("username-error", $errors["username"], $icon_err); ?>
                </div>

                <div class="field has-slot<?php echo invalid_class($errors["password"]); ?>" id="pw-field">
                    <label for="password">Password</label>
                    <div class="input-wrap">
                        <input type="password" name="password" id="password" placeholder="At least 8 characters" minlength="8" autocomplete="new-password" required <?php echo invalid_attr($errors["password"], "password-error"); ?><?php echo $focus === "password" ? " autofocus" : ""; ?>>
                        <button type="button" class="eye" data-target="password" aria-pressed="false" aria-label="Show password"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s4-7 10-7 10 7 10 7-4 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/><path class="slash" d="M4 4l16 16"/></svg></button>
                    </div>
                    <div class="meter" id="meter" data-level="0"><i></i><i></i><i></i><i></i></div>
                    <?php echo error_slot("password-error", $errors["password"], $icon_err); ?>
                </div>

                <div class="field has-slot<?php echo invalid_class($errors["confirm"]); ?>" id="confirm-field">
                    <label for="confirm_password">Confirm password</label>
                    <div class="input-wrap">
                        <input type="password" name="confirm_password" id="confirm_password" placeholder="Type it again" autocomplete="new-password" required <?php echo invalid_attr($errors["confirm"], "confirm-error"); ?>>
                        <button type="button" class="eye" data-target="confirm_password" aria-pressed="false" aria-label="Show password"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s4-7 10-7 10 7 10 7-4 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/><path class="slash" d="M4 4l16 16"/></svg></button>
                    </div>
                    <?php echo error_slot("confirm-error", $errors["confirm"], $icon_err); ?>
                </div>

                <button type="submit" name="register" class="btn">
                    Create account
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
                </button>

            </form>

            <p class="switch">Already have an account? <a href="login">Log in</a></p>

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