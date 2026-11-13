<?php
session_start();
require("db.php");
require("validate-lib.php");

if (!isset($_SESSION['username'])) {
    header("Location: login");
    exit();
}

// Only people who still need to finish their profile belong here
if (empty($_SESSION['needs_profile'])) {
    header("Location: dashboard");
    exit();
}

$current = $_SESSION['username'];

$stmt = mysqli_prepare($conn, "SELECT id, first_name, username, middle_initial, birthday FROM users WHERE username = ? LIMIT 1");
mysqli_stmt_bind_param($stmt, "s", $current);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);
$user = mysqli_fetch_assoc($res);
mysqli_stmt_close($stmt);

if (!$user) {
    header("Location: logout");
    exit();
}

$errors = ["birthday" => "", "initial" => "", "username" => "", "general" => ""];

if (isset($_POST['save'])) {

    $username       = trim($_POST['username']);
    $middle_initial = trim($_POST['middle_initial']);
    $birthday       = $_POST['birthday'];
    if ($middle_initial !== "") {
        $middle_initial = function_exists('mb_strtoupper') ? mb_strtoupper($middle_initial, 'UTF-8') : strtoupper($middle_initial);
    }

    if (!username_ok($username)) {

        $errors["username"] = "Use 3–30 letters, numbers, dots or underscores only.";

    } elseif (!birthday_ok($birthday)) {

        $errors["birthday"] = "You need to be at least " . MIN_AGE . " to join Camigo.";

    } elseif ($middle_initial !== "" && !preg_match('/^\p{L}$/u', $middle_initial)) {

        $errors["initial"] = "Middle initial must be a single letter.";

    } else {

        $stmt = mysqli_prepare($conn, "UPDATE users SET username = ?, middle_initial = ?, birthday = ? WHERE id = ?");
        mysqli_stmt_bind_param($stmt, "sssi", $username, $middle_initial, $birthday, $user['id']);

        if (mysqli_stmt_execute($stmt)) {
            $_SESSION['username'] = $username;
            unset($_SESSION['needs_profile']);
            mysqli_stmt_close($stmt);
            header("Location: dashboard");
            exit();
        }

        if (mysqli_stmt_errno($stmt) == 1062) {
            $errors["username"] = "That username is taken. Try another one.";
        } else {
            $errors["general"] = "Something went wrong. Please try again.";
        }
        mysqli_stmt_close($stmt);
    }
}

function val($post, $fallback) {
    return htmlspecialchars(isset($_POST[$post]) ? $_POST[$post] : (string)$fallback);
}
// helpers for the inline feedback markup (same as register.php)
$icon_err = '<svg viewBox="0 0 16 16" aria-hidden="true"><circle cx="8" cy="8" r="8"/><path d="M8 4.4v4.2" stroke="#fff" stroke-width="1.7" stroke-linecap="round"/><circle cx="8" cy="11.4" r="1" fill="#fff"/></svg>';

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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Finish your profile | Camigo</title>
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
            <h2 class="headline">Almost there, <?php echo htmlspecialchars($user['first_name']); ?>.</h2>
            <p class="sub">Google gave us your name and email. We just need a few more details to finish your Camigo profile.</p>
        </div>
    </aside>

    <main class="form-wrap">
        <div class="form">

            <h1>Finish your profile</h1>
            <p class="lead">This only takes a moment.</p>

            <form method="POST">

                <?php if ($errors["general"] !== "") { ?>
                    <p class="form-error" role="alert"><?php echo $icon_err . htmlspecialchars($errors["general"]); ?></p>
                <?php } ?>

                <div class="field has-slot<?php echo invalid_class($errors["initial"]); ?>">
                    <label for="middle_initial">Middle initial <span class="opt">(optional)</span></label>
                    <input type="text" name="middle_initial" id="middle_initial" maxlength="1" autocomplete="off" <?php echo invalid_attr($errors["initial"], "initial-error"); ?><?php echo $focus === "middle_initial" ? " autofocus" : ""; ?> value="<?php echo val('middle_initial', $user['middle_initial']); ?>">
                    <?php echo error_slot("initial-error", $errors["initial"], $icon_err); ?>
                </div>

                <div class="field has-slot<?php echo invalid_class($errors["birthday"]); ?>">
                    <label for="birthday">Birthday</label>
                    <input type="date" name="birthday" id="birthday" max="<?php echo min_birthday_date(); ?>" data-min-age="<?php echo MIN_AGE; ?>" required <?php echo invalid_attr($errors["birthday"], "birthday-error"); ?><?php echo $focus === "birthday" ? " autofocus" : ""; ?> value="<?php echo val('birthday', $user['birthday']); ?>">
                    <?php echo error_slot("birthday-error", $errors["birthday"], $icon_err); ?>
                </div>

                <div class="field has-slot<?php echo invalid_class($errors["username"]); ?>">
                    <label for="username">Username</label>
                    <input type="text" name="username" id="username" placeholder="Letters, numbers, . and _" maxlength="30" autocomplete="username" required <?php echo invalid_attr($errors["username"], "username-error"); ?><?php echo $focus === "username" ? " autofocus" : ""; ?> value="<?php echo val('username', $user['username']); ?>">
                    <?php echo error_slot("username-error", $errors["username"], $icon_err); ?>
                </div>

                <button type="submit" name="save" class="btn">
                    Save and continue
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
                </button>

            </form>

        </div>
    </main>

</div>

<script src="script.js"></script>
</body>
</html>