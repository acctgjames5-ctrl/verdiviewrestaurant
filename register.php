<?php
session_start();

require_once "config.php";

$error = "";
$success = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $full_name = trim($_POST["full_name"] ?? "");
    $email = trim($_POST["email"] ?? "");
    $username = trim($_POST["username"] ?? "");
    $password = $_POST["password"] ?? "";
    $confirm_password = $_POST["confirm_password"] ?? "";

    /* =========================
       VALIDATION
    ========================= */

    if (
        $full_name === "" ||
        $email === "" ||
        $username === "" ||
        $password === "" ||
        $confirm_password === ""
    ) {

        $error = "Please complete all required fields.";

    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {

        $error = "Please enter a valid email address.";

    } elseif (strlen($username) < 4) {

        $error = "Username must be at least 4 characters.";

    } elseif (strlen($password) < 6) {

        $error = "Password must be at least 6 characters.";

    } elseif ($password !== $confirm_password) {

        $error = "Passwords do not match.";

    } else {

        /* =========================
           CHECK EXISTING USERNAME
        ========================= */

        $checkUsername = $pdo->prepare("
            SELECT UserId
            FROM `user`
            WHERE Username = ?
            LIMIT 1
        ");

        $checkUsername->execute([$username]);

        if ($checkUsername->fetch()) {

            $error = "Username already exists.";

        } else {

            /* =========================
               CHECK EXISTING EMAIL
            ========================= */

            $checkEmail = $pdo->prepare("
                SELECT UserId
                FROM `user`
                WHERE Email = ?
                LIMIT 1
            ");

            $checkEmail->execute([$email]);

            if ($checkEmail->fetch()) {

                $error = "Email address is already registered.";

            } else {

                /* =========================
                   INSERT USER
                ========================= */

                $passwordHash = md5($password);

                $stmt = $pdo->prepare("
                    INSERT INTO `user`
                    (
                        Full_Name,
                        Email,
                        Username,
                        Password,
                        RegDate
                    )
                    VALUES
                    (?, ?, ?, ?, NOW())
                ");

                $stmt->execute([
                    $full_name,
                    $email,
                    $username,
                    $passwordHash
                ]);

                $success = "Registration successful! You can now sign in.";
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">

<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Register | VERDIVIEW</title>

<style>

* {
    box-sizing: border-box;
    margin: 0;
    padding: 0;
}

body {
    font-family: "Segoe UI", Arial, sans-serif;
    min-height: 100vh;
    background: #f4f7fb;
    color: #102a43;
}

.page {
    width: 100%;
    min-height: 100vh;
    display: flex;
}

/* =========================
   LEFT SIDE
========================= */

.brand-panel {

    width: 48%;
    min-height: 100vh;

    background:
        linear-gradient(
            145deg,
            #071f42 0%,
            #0b2d5c 55%,
            #123e78 100%
        );

    position: relative;

    display: flex;
    align-items: center;
    justify-content: center;

    padding: 50px;

    overflow: hidden;
}

.brand-panel::before {

    content: "";

    position: absolute;

    width: 500px;
    height: 500px;

    border-radius: 50%;

    border: 1px solid rgba(255,255,255,.08);

    top: -180px;
    left: -180px;
}

.brand-panel::after {

    content: "";

    position: absolute;

    width: 600px;
    height: 600px;

    border-radius: 50%;

    border: 1px solid rgba(255,255,255,.06);

    bottom: -350px;
    right: -250px;
}

.brand-content {

    position: relative;
    z-index: 2;

    width: 100%;
    max-width: 520px;

    color: white;
}

.brand-logo {

    display: flex;
    align-items: center;

    gap: 14px;

    margin-bottom: 55px;
}

.logo-box {

    width: 58px;
    height: 58px;

    border-radius: 12px;

    background: #ffb21c;

    color: #0a2850;

    display: flex;
    align-items: center;
    justify-content: center;

    font-size: 34px;
    font-weight: 900;

    font-style: italic;

    box-shadow: 0 8px 20px rgba(0,0,0,.18);
}

.logo-name {
    line-height: 1;
}

.logo-name strong {

    display: block;

    font-size: 25px;

    letter-spacing: .5px;
}

.logo-name small {

    display: block;

    margin-top: 5px;

    font-size: 9px;

    font-weight: 700;

    letter-spacing: 1.2px;

    opacity: .75;
}

.brand-content h1 {

    font-size: clamp(36px, 4vw, 58px);

    line-height: 1.08;

    margin-bottom: 20px;

    letter-spacing: -1.5px;
}

.brand-content h1 span {
    color: #ffb21c;
}

.brand-content p {

    max-width: 440px;

    font-size: 16px;

    line-height: 1.7;

    color: rgba(255,255,255,.72);
}

.feature-list {

    margin-top: 38px;

    display: grid;

    gap: 14px;
}

.feature {

    display: flex;

    align-items: center;

    gap: 13px;

    color: rgba(255,255,255,.9);

    font-size: 14px;
}

.feature-icon {

    width: 34px;
    height: 34px;

    border-radius: 9px;

    background: rgba(255,255,255,.09);

    display: flex;

    align-items: center;
    justify-content: center;

    color: #ffb21c;

    font-weight: 800;
}

/* =========================
   RIGHT SIDE
========================= */

.register-panel {

    width: 52%;

    min-height: 100vh;

    background: #ffffff;

    display: flex;

    align-items: center;

    justify-content: center;

    padding: 40px;
}

.register-box {

    width: 100%;

    max-width: 455px;
}

/* =========================
   HEADER
========================= */

.register-heading {

    margin-bottom: 28px;
}

.register-heading .welcome {

    color: #3478e5;

    font-size: 13px;

    font-weight: 700;

    text-transform: uppercase;

    letter-spacing: 1px;

    margin-bottom: 8px;
}

.register-heading h2 {

    font-size: 32px;

    color: #102a43;

    margin-bottom: 9px;
}

.register-heading p {

    color: #7890a8;

    font-size: 14px;
}

/* =========================
   ALERTS
========================= */

.alert {

    padding: 13px 15px;

    border-radius: 9px;

    margin-bottom: 20px;

    font-size: 13px;
}

.alert-error {

    background: #fff1f1;

    border: 1px solid #ffcaca;

    color: #e53935;
}

.alert-success {

    background: #effcf6;

    border: 1px solid #bcebd4;

    color: #168457;
}

/* =========================
   FORM
========================= */

.form-group {
    margin-bottom: 17px;
}

.form-group label {

    display: block;

    font-size: 13px;

    font-weight: 700;

    color: #233f5c;

    margin-bottom: 8px;
}

.input-wrap {
    position: relative;
}

.form-control {

    width: 100%;

    height: 50px;

    border: 1px solid #dce5ef;

    border-radius: 9px;

    background: #fbfcfe;

    color: #102a43;

    font-size: 14px;

    padding: 0 16px;

    outline: none;

    transition: .2s ease;
}

.form-control::placeholder {
    color: #a6b4c3;
}

.form-control:focus {

    background: #fff;

    border-color: #3478e5;

    box-shadow:
        0 0 0 4px rgba(52,120,229,.10);
}

/* =========================
   PASSWORD
========================= */

.password-toggle {

    position: absolute;

    right: 14px;

    top: 50%;

    transform: translateY(-50%);

    border: 0;

    background: transparent;

    color: #8196ab;

    cursor: pointer;

    font-size: 12px;

    font-weight: 700;
}

.password-toggle:hover {
    color: #3478e5;
}

.password-input {
    padding-right: 70px;
}

/* =========================
   REGISTER BUTTON
========================= */

.register-btn {

    width: 100%;

    height: 53px;

    border: none;

    border-radius: 9px;

    background:
        linear-gradient(
            135deg,
            #3478e5,
            #2164cf
        );

    color: #fff;

    font-size: 15px;

    font-weight: 800;

    cursor: pointer;

    box-shadow:
        0 8px 18px rgba(52,120,229,.20);

    transition: .2s ease;

    margin-top: 5px;
}

.register-btn:hover {

    transform: translateY(-1px);

    box-shadow:
        0 11px 23px rgba(52,120,229,.27);
}

/* =========================
   LOGIN LINK
========================= */

.login-link {

    text-align: center;

    margin-top: 22px;

    color: #8a9caf;

    font-size: 13px;
}

.login-link a {

    color: #3478e5;

    font-weight: 700;

    text-decoration: none;
}

.login-link a:hover {
    text-decoration: underline;
}

/* =========================
   FOOTER
========================= */

.secure-note {

    margin-top: 22px;

    padding-top: 20px;

    border-top: 1px solid #edf1f5;

    text-align: center;

    color: #9aabba;

    font-size: 11px;
}

.secure-note span {

    color: #2ebd85;

    font-weight: 700;
}

.copyright {

    margin-top: 10px;

    text-align: center;

    color: #a4b1bf;

    font-size: 11px;
}

/* =========================
   MOBILE
========================= */

@media (max-width: 850px) {

    .page {
        display: block;
    }

    .brand-panel {
        display: none;
    }

    .register-panel {

        width: 100%;

        min-height: 100vh;

        padding: 25px;
    }

    .register-box {
        max-width: 450px;
    }
}

@media (max-width: 430px) {

    .register-panel {
        padding: 22px 18px;
    }

    .register-heading h2 {
        font-size: 28px;
    }
}

</style>

</head>

<body>

<div class="page">

    <!-- LEFT -->

    <section class="brand-panel">

        <div class="brand-content">

            <div class="brand-logo">

                <div class="logo-box">
                    V
                </div>

                <div class="logo-name">

                    <strong>VERDIVIEW</strong>

                    <small>
                        SALES &amp; EXPENSES SYSTEM
                    </small>

                </div>

            </div>

            <h1>
                Create your<br>
                <span>account</span> today.
            </h1>

            <p>
                Register an authorized account to access
                the VERDIVIEW Sales &amp; Expenses System.
            </p>

            <div class="feature-list">

                <div class="feature">

                    <div class="feature-icon">
                        ✓
                    </div>

                    <span>
                        Secure user registration
                    </span>

                </div>

                <div class="feature">

                    <div class="feature-icon">
                        ✓
                    </div>

                    <span>
                        Access sales and expense records
                    </span>

                </div>

                <div class="feature">

                    <div class="feature-icon">
                        ✓
                    </div>

                    <span>
                        Manage your account securely
                    </span>

                </div>

            </div>

        </div>

    </section>


    <!-- RIGHT -->

    <section class="register-panel">

        <div class="register-box">

            <div class="register-heading">

                <div class="welcome">
                    Get started
                </div>

                <h2>
                    Create an account
                </h2>

                <p>
                    Fill in your information to register.
                </p>

            </div>


            <?php if ($error): ?>

                <div class="alert alert-error">

                    <?= htmlspecialchars(
                        $error,
                        ENT_QUOTES,
                        'UTF-8'
                    ) ?>

                </div>

            <?php endif; ?>


            <?php if ($success): ?>

                <div class="alert alert-success">

                    <?= htmlspecialchars(
                        $success,
                        ENT_QUOTES,
                        'UTF-8'
                    ) ?>

                </div>

            <?php endif; ?>


            <form
                method="POST"
                action="register.php"
                autocomplete="off"
            >

                <!-- FULL NAME -->

                <div class="form-group">

                    <label for="full_name">
                        Full Name
                    </label>

                    <input
                        type="text"
                        id="full_name"
                        name="full_name"
                        class="form-control"
                        placeholder="Enter your full name"
                        required
                        value="<?= htmlspecialchars(
                            $_POST["full_name"] ?? "",
                            ENT_QUOTES,
                            "UTF-8"
                        ) ?>"
                    >

                </div>


                <!-- EMAIL -->

                <div class="form-group">

                    <label for="email">
                        Email Address
                    </label>

                    <input
                        type="email"
                        id="email"
                        name="email"
                        class="form-control"
                        placeholder="Enter your email"
                        required
                        value="<?= htmlspecialchars(
                            $_POST["email"] ?? "",
                            ENT_QUOTES,
                            "UTF-8"
                        ) ?>"
                    >

                </div>


                <!-- USERNAME -->

                <div class="form-group">

                    <label for="username">
                        Username
                    </label>

                    <input
                        type="text"
                        id="username"
                        name="username"
                        class="form-control"
                        placeholder="Create a username"
                        required
                        value="<?= htmlspecialchars(
                            $_POST["username"] ?? "",
                            ENT_QUOTES,
                            "UTF-8"
                        ) ?>"
                    >

                </div>


                <!-- PASSWORD -->

                <div class="form-group">

                    <label for="password">
                        Password
                    </label>

                    <div class="input-wrap">

                        <input
                            type="password"
                            id="password"
                            name="password"
                            class="form-control password-input"
                            placeholder="Create a password"
                            required
                        >

                        <button
                            type="button"
                            class="password-toggle"
                            onclick="togglePassword('password', this)"
                        >
                            SHOW
                        </button>

                    </div>

                </div>


                <!-- CONFIRM PASSWORD -->

                <div class="form-group">

                    <label for="confirm_password">
                        Confirm Password
                    </label>

                    <div class="input-wrap">

                        <input
                            type="password"
                            id="confirm_password"
                            name="confirm_password"
                            class="form-control password-input"
                            placeholder="Confirm your password"
                            required
                        >

                        <button
                            type="button"
                            class="password-toggle"
                            onclick="togglePassword('confirm_password', this)"
                        >
                            SHOW
                        </button>

                    </div>

                </div>


                <!-- BUTTON -->

                <button
                    type="submit"
                    class="register-btn"
                >
                    CREATE ACCOUNT
                </button>

            </form>


            <div class="login-link">

                Already have an account?

                <a href="login.php">
                    Sign in
                </a>

            </div>


            <div class="secure-note">

                <span>
                    ● Secure Registration
                </span>

                &nbsp;|&nbsp;

                Authorized users only

            </div>


            <div class="copyright">

                © <?= date('Y') ?>
                VERDIVIEW Sales &amp; Expenses System

            </div>

        </div>

    </section>

</div>


<script>

function togglePassword(id, button) {

    const input = document.getElementById(id);

    if (input.type === "password") {

        input.type = "text";

        button.textContent = "HIDE";

    } else {

        input.type = "password";

        button.textContent = "SHOW";
    }
}

</script>

</body>

</html>