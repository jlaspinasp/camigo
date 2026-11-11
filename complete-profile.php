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

$message = "";

if (isset($_POST['save'])) {

    $username       = trim($_POST['username']);
    $middle_initial = trim($_POST['middle_initial']);
    $birthday       = $_POST['birthday'];
    if ($middle_initial !== "") {
        $middle_initial = function_exists('mb_strtoupper') ? mb_strtoupper($middle_initial, 'UTF-8') : strtoupper($middle_initial);
    }

    $d = DateTime::createFromFormat('Y-m-d', $birthday);
    $validDate = birthday_ok($birthday);

    if (!username_ok($username)) {
        $message = "Usernames need 3 to 30 letters, numbers, dots or underscores.";
    } elseif ($middle_initial !== "" && !preg_match('/^\p{L}$/u', $middle_initial)) {
        $message = "Middle initial must be a single letter.";
    } elseif (!$validDate) {
        $message = "You need to be at least " . MIN_AGE . " to join Camigo.";
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
            $message = "That username is already taken. Try another one.";
        } else {
            $message = "Something went wrong. Please try again.";
        }
        mysqli_stmt_close($stmt);
    }
}

function val($post, $fallback) {
    return htmlspecialchars(isset($_POST[$post]) ? $_POST[$post] : (string)$fallback);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Finish your profile | Camigo</title>
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

            <?php if ($message != "") { ?>
                <div class="msg error"><?php echo htmlspecialchars($message); ?></div>
            <?php } ?>

            <form method="POST">

                <div class="field">
                    <label for="username">Username</label>
                    <input type="text" name="username" id="username" required
                           value="<?php echo val('username', $user['username']); ?>">
                </div>

                <div class="field">
                    <label for="middle_initial">Middle initial <span class="opt">(optional)</span></label>
                    <input type="text" name="middle_initial" id="middle_initial" maxlength="1" autocomplete="off"
                           value="<?php echo val('middle_initial', $user['middle_initial']); ?>">
                </div>

                <div class="field">
                    <label for="birthday">Birthday</label>
                    <input type="date" name="birthday" id="birthday" max="<?php echo min_birthday_date(); ?>" required
                           value="<?php echo val('birthday', $user['birthday']); ?>">
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