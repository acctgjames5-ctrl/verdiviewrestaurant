<?php
/*
|--------------------------------------------------------------------------
| VIANCHRIS LOCK SYSTEM
|--------------------------------------------------------------------------
| Password muna bago makapasok sa system.
| Password: 123456
|--------------------------------------------------------------------------
*/

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/*
|--------------------------------------------------------------------------
| SETTINGS
|--------------------------------------------------------------------------
*/

$LOCK_PASSWORD = "123456";

/*
|--------------------------------------------------------------------------
| CHECK IF ALREADY UNLOCKED
|--------------------------------------------------------------------------
*/

if (isset($_SESSION['site_unlocked']) && $_SESSION['site_unlocked'] === true) {
    return;
}

/*
|--------------------------------------------------------------------------
| LOGOUT / LOCK AGAIN
|--------------------------------------------------------------------------
*/

if (isset($_GET['lock']) && $_GET['lock'] === '1') {
    unset($_SESSION['site_unlocked']);
    header("Location: lock.php");
    exit;
}

/*
|--------------------------------------------------------------------------
| PASSWORD SUBMISSION
|--------------------------------------------------------------------------
*/

$error = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $password = $_POST['password'] ?? '';

    if (hash_equals($LOCK_PASSWORD, $password)) {

        session_regenerate_id(true);

        $_SESSION['site_unlocked'] = true;
        $_SESSION['unlock_time'] = time();

        header("Location: dashboard.php");
        exit;

    } else {

        $error = "Incorrect password. Please try again.";

    }
}

/*
|--------------------------------------------------------------------------
| LOCK SCREEN
|--------------------------------------------------------------------------
*/

if (!isset($_SESSION['site_unlocked']) || $_SESSION['site_unlocked'] !== true):
?>

<!DOCTYPE html>
<html lang="en">
<head>

<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Locked - VIANCHRIS</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

<link rel="stylesheet"
      href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">

<style>

*{
    box-sizing:border-box;
}

body{
    margin:0;
    min-height:100vh;
    display:flex;
    align-items:center;
    justify-content:center;
    font-family:Arial, sans-serif;

    background:
        radial-gradient(circle at top left, #e8f7ef, transparent 35%),
        radial-gradient(circle at bottom right, #e8eefc, transparent 35%),
        #f5f7fa;
}

/* MAIN BOX */

.lock-box{
    width:100%;
    max-width:420px;
    padding:42px 38px;

    background:rgba(255,255,255,.96);

    border-radius:24px;

    box-shadow:
        0 25px 60px rgba(0,0,0,.12);

    text-align:center;
}

/* LOCK ICON */

.lock-icon{
    width:85px;
    height:85px;

    margin:0 auto 20px;

    display:flex;
    align-items:center;
    justify-content:center;

    border-radius:50%;

    background:#e9f8f0;

    color:#198754;

    font-size:34px;
}

/* TITLE */

.logo{
    font-size:30px;
    font-weight:800;
    color:#222;
    letter-spacing:1px;
}

.system-name{
    color:#888;
    font-size:14px;
    margin-top:5px;
    margin-bottom:28px;
}

/* ERROR */

.error-box{
    background:#fff0f0;
    border:1px solid #ffd0d0;

    color:#d32f2f;

    padding:12px 15px;

    border-radius:10px;

    margin-bottom:20px;

    font-size:14px;
}

/* INPUT */

.password-wrapper{
    position:relative;
}

.password-wrapper input{
    width:100%;
    height:54px;

    border:1px solid #ddd;

    border-radius:12px;

    padding:0 50px 0 17px;

    font-size:16px;

    outline:none;
}

.password-wrapper input:focus{
    border-color:#198754;

    box-shadow:0 0 0 3px rgba(25,135,84,.10);
}

.toggle-password{
    position:absolute;

    right:16px;
    top:50%;

    transform:translateY(-50%);

    cursor:pointer;

    color:#888;
}

/* BUTTON */

.unlock-btn{
    width:100%;
    height:54px;

    margin-top:16px;

    border:none;

    border-radius:12px;

    background:#198754;

    color:#fff;

    font-size:16px;

    font-weight:700;

    cursor:pointer;

    transition:.2s;
}

.unlock-btn:hover{
    background:#157347;
    transform:translateY(-1px);
}

/* FOOTER */

.footer-text{
    margin-top:25px;

    color:#aaa;

    font-size:12px;
}

</style>

</head>

<body>

<div class="lock-box">

    <div class="lock-icon">
        <i class="fa-solid fa-lock"></i>
    </div>

    <div class="logo">
        VIANCHRIS
    </div>

    <div class="system-name">
        Sales & Expense Management System
    </div>

    <?php if ($error): ?>

        <div class="error-box">
            <i class="fa-solid fa-circle-exclamation"></i>
            <?=htmlspecialchars($error)?>
        </div>

    <?php endif; ?>

    <form method="POST">

        <div class="password-wrapper">

            <input
                type="password"
                name="password"
                id="password"
                placeholder="Enter password"
                autocomplete="off"
                autofocus
                required
            >

            <span
                class="toggle-password"
                onclick="togglePassword()"
            >
                <i class="fa-solid fa-eye" id="eyeIcon"></i>
            </span>

        </div>

        <button type="submit" class="unlock-btn">

            <i class="fa-solid fa-unlock me-2"></i>

            UNLOCK SYSTEM

        </button>

    </form>

    <div class="footer-text">
        Authorized personnel only
    </div>

</div>

<script>

function togglePassword(){

    const password = document.getElementById("password");
    const icon = document.getElementById("eyeIcon");

    if(password.type === "password"){

        password.type = "text";

        icon.classList.remove("fa-eye");
        icon.classList.add("fa-eye-slash");

    }else{

        password.type = "password";

        icon.classList.remove("fa-eye-slash");
        icon.classList.add("fa-eye");

    }

}

</script>

</body>
</html>

<?php
exit;
endif;
?>