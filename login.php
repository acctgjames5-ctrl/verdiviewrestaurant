<?php

/* =========================================================
   SESSION
========================================================= */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}


/* =========================================================
   DATABASE
========================================================= */

require_once "config.php";


/* =========================================================
   ERROR
========================================================= */

$error = "";


/* =========================================================
   LOGIN PROCESS
========================================================= */

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $username = trim($_POST["username"] ?? "");
    $password = $_POST["password"] ?? "";


    /* =====================================================
       VALIDATION
    ===================================================== */

    if ($username === "" || $password === "") {

        $error = "Please enter your username and password.";

    } else {

        try {

            /* =================================================
               GET USER ACCOUNT

               PostgreSQL / Neon version
            ================================================= */

            $stmt = $pdo->prepare("
                SELECT
                    \"UserId\",
                    \"Full_Name\",
                    \"Email\",
                    \"Username\",
                    \"Password\",
                    \"RegDate\",
                    \"role\",
                    branch_id
                FROM users
                WHERE \"Username\" = ?
                LIMIT 1
            ");

            $stmt->execute([$username]);

            $user = $stmt->fetch(PDO::FETCH_ASSOC);


            /* =================================================
               CHECK LOGIN

               Existing passwords are MD5 hashes.
            ================================================= */

            if (
                $user &&
                md5($password) === (string)$user["Password"]
            ) {

                /* =============================================
                   REGENERATE SESSION ID
                ============================================= */

                session_regenerate_id(true);


                /* =============================================
                   LOGIN STATUS
                ============================================= */

                $_SESSION["logged_in"] = true;


                /* =============================================
                   USER ID
                ============================================= */

                $_SESSION["UserId"] =
                    (int)$user["UserId"];

                $_SESSION["user_id"] =
                    (int)$user["UserId"];


                /* =============================================
                   FULL NAME
                ============================================= */

                $_SESSION["Full_Name"] =
                    trim((string)$user["Full_Name"]);

                $_SESSION["full_name"] =
                    trim((string)$user["Full_Name"]);


                /* =============================================
                   EMAIL
                ============================================= */

                $_SESSION["Email"] =
                    trim((string)$user["Email"]);

                $_SESSION["email"] =
                    trim((string)$user["Email"]);


                /* =============================================
                   USERNAME
                ============================================= */

                $_SESSION["Username"] =
                    trim((string)$user["Username"]);

                $_SESSION["username"] =
                    trim((string)$user["Username"]);


                /* =============================================
                   ROLE
                ============================================= */

                $role = trim(
                    (string)($user["role"] ?? "")
                );

                $_SESSION["Role"] = $role;
                $_SESSION["role"] = $role;
                $_SESSION["user_role"] = $role;
                $_SESSION["position"] = $role;


                /* =============================================
                   BRANCH

                   NULL means user can use the general/
                   all-branch access logic.
                ============================================= */

                if (
                    isset($user["branch_id"]) &&
                    $user["branch_id"] !== null &&
                    $user["branch_id"] !== ""
                ) {

                    $_SESSION["branch_id"] =
                        (int)$user["branch_id"];

                } else {

                    $_SESSION["branch_id"] = 0;

                }


                /* =============================================
                   REDIRECT
                ============================================= */

                header("Location: index.php");

                exit;

            } else {

                $error =
                    "Invalid username or password.";

            }

        } catch (PDOException $e) {

            /*
             * For troubleshooting, write the actual
             * database error to the PHP error log.
             *
             * Do NOT display the database password
             * or connection string to users.
             */

            error_log(
                "LOGIN DATABASE ERROR: " .
                $e->getMessage()
            );

            $error =
                "Unable to login at this time. Please try again.";

        }

    }
}

?>

<!DOCTYPE html>

<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>
        Login | VERDIVIEW
    </title>


    <style>

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family:
                "Segoe UI",
                Arial,
                sans-serif;

            min-height: 100vh;

            background: #f4f7fb;

            color: #102a43;
        }

        .page {
            width: 100%;
            min-height: 100vh;
            display: flex;
        }

        /* =====================================================
           LEFT SIDE
        ===================================================== */

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

            border:
                1px solid
                rgba(255,255,255,.08);

            top: -180px;
            left: -180px;
        }

        .brand-panel::after {
            content: "";

            position: absolute;

            width: 600px;
            height: 600px;

            border-radius: 50%;

            border:
                1px solid
                rgba(255,255,255,.06);

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

        /* =====================================================
           LOGO
        ===================================================== */

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

            box-shadow:
                0 8px 20px
                rgba(0,0,0,.18);
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

        /* =====================================================
           TITLE
        ===================================================== */

        .brand-content h1 {
            font-size:
                clamp(36px, 4vw, 58px);

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

            color:
                rgba(255,255,255,.72);
        }

        /* =====================================================
           FEATURES
        ===================================================== */

        .feature-list {
            margin-top: 38px;

            display: grid;

            gap: 14px;
        }

        .feature {
            display: flex;

            align-items: center;

            gap: 13px;

            color:
                rgba(255,255,255,.9);

            font-size: 14px;
        }

        .feature-icon {
            width: 34px;
            height: 34px;

            border-radius: 9px;

            background:
                rgba(255,255,255,.09);

            display: flex;

            align-items: center;

            justify-content: center;

            color: #ffb21c;

            font-weight: 800;
        }

        /* =====================================================
           RIGHT SIDE
        ===================================================== */

        .login-panel {
            width: 52%;

            min-height: 100vh;

            background: #ffffff;

            display: flex;

            align-items: center;

            justify-content: center;

            padding: 40px;
        }

        .login-box {
            width: 100%;

            max-width: 455px;
        }

        .mobile-logo {
            display: none;
        }

        /* =====================================================
           LOGIN HEADER
        ===================================================== */

        .login-heading {
            margin-bottom: 34px;
        }

        .login-heading .welcome {
            color: #3478e5;

            font-size: 13px;

            font-weight: 700;

            text-transform: uppercase;

            letter-spacing: 1px;

            margin-bottom: 8px;
        }

        .login-heading h2 {
            font-size: 32px;

            color: #102a43;

            margin-bottom: 9px;
        }

        .login-heading p {
            color: #7890a8;

            font-size: 14px;
        }

        /* =====================================================
           ERROR
        ===================================================== */

        .alert {
            background: #fff1f1;

            border:
                1px solid
                #ffcaca;

            color: #e53935;

            padding: 13px 15px;

            border-radius: 9px;

            margin-bottom: 20px;

            font-size: 13px;
        }

        /* =====================================================
           FORM
        ===================================================== */

        .form-group {
            margin-bottom: 20px;
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

        .input-icon {
            position: absolute;

            left: 16px;

            top: 50%;

            transform:
                translateY(-50%);

            color: #8aa0b7;

            width: 19px;

            height: 19px;

            pointer-events: none;
        }

        .form-control {
            width: 100%;

            height: 52px;

            border:
                1px solid
                #dce5ef;

            border-radius: 9px;

            background: #fbfcfe;

            color: #102a43;

            font-size: 14px;

            padding:
                0 70px 0 48px;

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
                0 0 0 4px
                rgba(52,120,229,.10);
        }

        /* =====================================================
           PASSWORD BUTTON
        ===================================================== */

        .password-toggle {
            position: absolute;

            right: 14px;

            top: 50%;

            transform:
                translateY(-50%);

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

        /* =====================================================
           OPTIONS
        ===================================================== */

        .login-options {
            display: flex;

            align-items: center;

            justify-content: space-between;

            margin:
                2px 0 25px;
        }

        .remember {
            display: flex;

            align-items: center;

            gap: 8px;

            color: #71879c;

            font-size: 13px;

            cursor: pointer;
        }

        .remember input {
            width: 16px;

            height: 16px;

            accent-color: #3478e5;

            cursor: pointer;
        }

        .forgot {
            color: #3478e5;

            text-decoration: none;

            font-size: 13px;

            font-weight: 600;
        }

        .forgot:hover {
            text-decoration: underline;
        }

        /* =====================================================
           LOGIN BUTTON
        ===================================================== */

        .login-btn {
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
                0 8px 18px
                rgba(52,120,229,.20);

            transition: .2s ease;
        }

        .login-btn:hover {
            transform:
                translateY(-1px);

            box-shadow:
                0 11px 23px
                rgba(52,120,229,.27);
        }

        .login-btn:active {
            transform:
                translateY(0);
        }

        /* =====================================================
           REGISTER
        ===================================================== */

        .register-link {
            text-align: center;

            margin-top: 18px;

            color: #7890a8;

            font-size: 13px;
        }

        .register-link a {
            color: #3478e5;

            text-decoration: none;

            font-weight: 700;
        }

        .register-link a:hover {
            text-decoration: underline;
        }

        /* =====================================================
           FOOTER
        ===================================================== */

        .secure-note {
            margin-top: 27px;

            padding-top: 22px;

            border-top:
                1px solid
                #edf1f5;

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

        /* =====================================================
           RESPONSIVE
        ===================================================== */

        @media (max-width: 850px) {

            body {
                overflow: auto;
            }

            .page {
                display: block;
            }

            .brand-panel {
                display: none;
            }

            .login-panel {
                width: 100%;

                min-height: 100vh;

                padding: 25px;
            }

            .mobile-logo {
                display: flex;

                align-items: center;

                justify-content: center;

                gap: 10px;

                margin-bottom: 42px;
            }

            .mobile-logo .logo-box {
                width: 48px;

                height: 48px;

                font-size: 28px;
            }

            .mobile-logo strong {
                color: #102a43;

                font-size: 22px;
            }

            .mobile-logo small {
                display: block;

                color: #7890a8;

                font-size: 8px;

                font-weight: 700;

                letter-spacing: 1px;

                margin-top: 3px;
            }

            .login-box {
                max-width: 450px;
            }
        }

        @media (max-width: 430px) {

            .login-panel {
                padding:
                    22px 18px;
            }

            .login-heading h2 {
                font-size: 28px;
            }

            .login-options {
                align-items: flex-start;

                gap: 10px;
            }
        }

    </style>

</head>

<body>

<div class="page">

    <!-- =====================================================
         LEFT BRANDING
    ====================================================== -->

    <section class="brand-panel">

        <div class="brand-content">

            <div class="brand-logo">

                <div class="logo-box">
                    V
                </div>

                <div class="logo-name">

                    <strong>
                        VERDIVIEW
                    </strong>

                    <small>
                        SALES &amp; EXPENSES SYSTEM
                    </small>

                </div>

            </div>

            <h1>
                Manage your<br>

                <span>
                    business
                </span>

                with ease.
            </h1>

            <p>
                Welcome to the VERDIVIEW Sales &amp; Expenses
                System. Monitor sales, expenses, puhunan and
                branch performance from one simple dashboard.
            </p>

            <div class="feature-list">

                <div class="feature">

                    <div class="feature-icon">
                        ✓
                    </div>

                    <span>
                        Track daily sales and expenses
                    </span>

                </div>

                <div class="feature">

                    <div class="feature-icon">
                        ✓
                    </div>

                    <span>
                        Manage multiple branches
                    </span>

                </div>

                <div class="feature">

                    <div class="feature-icon">
                        ✓
                    </div>

                    <span>
                        View financial reports and summaries
                    </span>

                </div>

            </div>

        </div>

    </section>


    <!-- =====================================================
         LOGIN
    ====================================================== -->

    <section class="login-panel">

        <div class="login-box">

            <!-- MOBILE LOGO -->

            <div class="mobile-logo">

                <div class="logo-box">
                    V
                </div>

                <div>

                    <strong>
                        VERDIVIEW
                    </strong>

                    <small>
                        SALES &amp; EXPENSES SYSTEM
                    </small>

                </div>

            </div>


            <!-- LOGIN TITLE -->

            <div class="login-heading">

                <div class="welcome">
                    Welcome back
                </div>

                <h2>
                    Sign in to your account
                </h2>

                <p>
                    Enter your credentials to access the dashboard.
                </p>

            </div>


            <!-- ERROR -->

            <?php if ($error !== ""): ?>

                <div class="alert">

                    <?= htmlspecialchars(
                        $error,
                        ENT_QUOTES,
                        "UTF-8"
                    ) ?>

                </div>

            <?php endif; ?>


            <!-- LOGIN FORM -->

            <form
                method="POST"
                action="login.php"
                autocomplete="on"
            >

                <!-- USERNAME -->

                <div class="form-group">

                    <label for="username">
                        Username
                    </label>

                    <div class="input-wrap">

                        <svg
                            class="input-icon"
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="2"
                        >

                            <path
                                d="M20 21a8 8 0 0 0-16 0">
                            </path>

                            <circle
                                cx="12"
                                cy="7"
                                r="4">
                            </circle>

                        </svg>

                        <input
                            type="text"
                            id="username"
                            name="username"
                            class="form-control"
                            placeholder="Enter your username"
                            required
                            autofocus
                            autocomplete="username"
                            value="<?= htmlspecialchars(
                                $_POST["username"] ?? "",
                                ENT_QUOTES,
                                "UTF-8"
                            ) ?>"
                        >

                    </div>

                </div>


                <!-- PASSWORD -->

                <div class="form-group">

                    <label for="password">
                        Password
                    </label>

                    <div class="input-wrap">

                        <svg
                            class="input-icon"
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="2"
                        >

                            <rect
                                x="3"
                                y="11"
                                width="18"
                                height="10"
                                rx="2">
                            </rect>

                            <path
                                d="M7 11V7a5 5 0 0 1 10 0v4">
                            </path>

                        </svg>

                        <input
                            type="password"
                            id="password"
                            name="password"
                            class="form-control"
                            placeholder="Enter your password"
                            required
                            autocomplete="current-password"
                        >

                        <button
                            type="button"
                            class="password-toggle"
                            onclick="togglePassword()"
                        >
                            SHOW
                        </button>

                    </div>

                </div>


                <!-- OPTIONS -->

                <div class="login-options">

                    <label class="remember">

                        <input
                            type="checkbox"
                            name="remember"
                            value="1"
                        >

                        Remember me

                    </label>

                    <a
                        href="#"
                        class="forgot"
                        onclick="return false;"
                    >
                        Forgot password?
                    </a>

                </div>


                <!-- LOGIN BUTTON -->

                <button
                    type="submit"
                    class="login-btn"
                >
                    SIGN IN
                </button>


                <!-- REGISTER -->

                <div class="register-link">

                    Don't have an account?

                    <a href="register.php">
                        Create Account
                    </a>

                </div>

            </form>


            <!-- SECURITY -->

            <div class="secure-note">

                <span>
                    ● Secure Login
                </span>

                &nbsp;|&nbsp;

                Authorized users only

            </div>


            <!-- COPYRIGHT -->

            <div class="copyright">

                © <?= date("Y") ?>

                VERDIVIEW Sales &amp; Expenses System

            </div>

        </div>

    </section>

</div>


<script>

/* =========================================================
   PASSWORD TOGGLE
========================================================= */

function togglePassword() {

    const password =
        document.getElementById("password");

    const button =
        document.querySelector(".password-toggle");

    if (!password || !button) {
        return;
    }

    if (password.type === "password") {

        password.type = "text";

        button.textContent = "HIDE";

    } else {

        password.type = "password";

        button.textContent = "SHOW";

    }

}

</script>

</body>

</html>
