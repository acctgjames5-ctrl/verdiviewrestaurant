<?php

/* =========================================================
   SESSION
========================================================= */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}


/* =========================================================
   PAGE TITLE
========================================================= */

if (!isset($pageTitle)) {
    $pageTitle = "Vianchris Sales & Expenses";
}


/* =========================================================
   LOGGED-IN USER
========================================================= */

$currentUserName = 'User';
$currentUserRole = 'User';

$userId = (int)(
    $_SESSION['user_id']
    ?? $_SESSION['id']
    ?? 0
);


/* =========================================================
   SESSION USER DATA
========================================================= */

$sessionUserName =
    $_SESSION['full_name']
    ?? $_SESSION['fullname']
    ?? $_SESSION['name']
    ?? $_SESSION['user_name']
    ?? $_SESSION['username']
    ?? null;

$sessionUserRole =
    $_SESSION['role']
    ?? $_SESSION['user_role']
    ?? $_SESSION['position']
    ?? null;


/* =========================================================
   SESSION NAME
========================================================= */

if (!empty($sessionUserName)) {

    $currentUserName =
        trim(
            (string)$sessionUserName
        );

}


/* =========================================================
   SESSION ROLE
========================================================= */

if (!empty($sessionUserRole)) {

    $currentUserRole =
        trim(
            (string)$sessionUserRole
        );

}


/* =========================================================
   GET USER FROM DATABASE
========================================================= */

if ($userId > 0) {

    try {

        $stmtUser = $pdo->prepare("
            SELECT *
            FROM `user`
            WHERE UserId = ?
            LIMIT 1
        ");

        $stmtUser->execute([
            $userId
        ]);

        $loggedUser =
            $stmtUser->fetch(
                PDO::FETCH_ASSOC
            );


        if ($loggedUser) {

            /* =============================================
               NAME
            ============================================= */

            $possibleNameFields = [
                'Full_Name',
                'full_name',
                'fullname',
                'name',
                'username',
                'Username',
                'user_name',
                'email',
                'Email'
            ];


            foreach (
                $possibleNameFields
                as $field
            ) {

                if (
                    isset($loggedUser[$field]) &&
                    trim(
                        (string)$loggedUser[$field]
                    ) !== ''
                ) {

                    $currentUserName =
                        trim(
                            (string)$loggedUser[$field]
                        );

                    break;

                }

            }


            /* =============================================
               ROLE
            ============================================= */

            $possibleRoleFields = [
                'role',
                'Role',
                'user_role',
                'position',
                'Position',
                'job_title',
                'type'
            ];


            foreach (
                $possibleRoleFields
                as $field
            ) {

                if (
                    isset($loggedUser[$field]) &&
                    trim(
                        (string)$loggedUser[$field]
                    ) !== ''
                ) {

                    $currentUserRole =
                        trim(
                            (string)$loggedUser[$field]
                        );

                    /*
                     * Keep session synchronized
                     */

                    $_SESSION['role'] =
                        $currentUserRole;

                    break;

                }

            }

        }

    } catch (Throwable $e) {

        /*
         * Keep page working even if
         * database structure differs.
         */

    }

}


/* =========================================================
   FALLBACK
========================================================= */

if (
    trim(
        (string)$currentUserName
    ) === ''
) {

    $currentUserName = 'User';

}


if (
    trim(
        (string)$currentUserRole
    ) === ''
) {

    $currentUserRole = 'User';

}


/* =========================================================
   VIEWER
========================================================= */

$normalizedRole =
    strtolower(
        trim(
            (string)$currentUserRole
        )
    );


$isViewer = in_array(
    $normalizedRole,
    [
        'viewer',
        'view only',
        'view-only'
    ],
    true
);


/* =========================================================
   BRANCHES
========================================================= */

$branches = [];

try {

    $stmtBranches = $pdo->query("
        SELECT *
        FROM branches
        WHERE is_active = 1
        ORDER BY branch_name
    ");

    $branches =
        $stmtBranches->fetchAll(
            PDO::FETCH_ASSOC
        );

} catch (Throwable $e) {

    $branches = [];

}


/* =========================================================
   CURRENT BRANCH
========================================================= */

$currentBranch = isset($_GET['branch'])
    ? (int)$_GET['branch']
    : (int)(
        $_SESSION['branch_id'] ?? 0
    );


/* =========================================================
   SAVE CURRENT BRANCH
========================================================= */

if (isset($_GET['branch'])) {

    $_SESSION['branch_id'] =
        $currentBranch;

}


/* =========================================================
   CURRENT FILE
========================================================= */

$currentFile =
    basename(
        $_SERVER['PHP_SELF']
    );


/* =========================================================
   ACTIVE MENU
========================================================= */

$isDashboard =
    in_array(
        strtolower($currentFile),
        [
            'index.php',
            'dashboard.php'
        ],
        true
    );

$isSales =
    strtolower($currentFile)
    === 'sales.php';

$isExpenses =
    strtolower($currentFile)
    === 'expenses.php';

$isPurchases =
    strtolower($currentFile)
    === 'purchases.php';

$isVERDIVIEW =
    strtolower($currentFile)
    === 'inventory.php';

$isBankRecon =
    strtolower($currentFile)
    === 'bank_reconciliation.php';

$isReports =
    strtolower($currentFile)
    === 'reports.php';

?>

<!doctype html>

<html lang="en">

<head>

<meta charset="utf-8">

<meta
    name="viewport"
    content="width=device-width, initial-scale=1"
>

<title>
    <?=htmlspecialchars($pageTitle)?>
</title>


<link
    href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
    rel="stylesheet"
>

<link
    rel="stylesheet"
    href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css"
>


<style>

:root{

    --navy:#09264b;
    --navy2:#0c315e;
    --blue:#2169e8;
    --green:#28c884;
    --red:#ff4b4b;
    --orange:#ff9d21;
    --purple:#7b45e6;
    --text:#172642;
    --muted:#7a879b;
    --line:#e7ebf2;
    --bg:#f7f9fc;
    --sidebar-width:238px;

}

*{
    box-sizing:border-box;
}

html,
body{
    margin:0;
    padding:0;
    min-height:100%;
}

body{
    background:var(--bg);
    font-family:
        Inter,
        Segoe UI,
        Arial,
        sans-serif;
    color:var(--text);
    font-size:14px;
}

.app-shell{
    min-height:100vh;
    display:flex;
}

.sidebar{
    width:var(--sidebar-width);
    background:
        linear-gradient(
            180deg,
            #071f42 0%,
            #092b54 100%
        );
    color:#fff;
    position:fixed;
    left:0;
    top:0;
    bottom:0;
    z-index:1030;
    transition:
        width .30s ease,
        transform .30s ease;
    box-shadow:
        8px 0 30px
        rgba(4,24,50,.08);
    overflow-x:hidden;
    overflow-y:auto;
}

.brand{
    height:80px;
    display:flex;
    align-items:center;
    padding:0 24px;
    border-bottom:
        1px solid
        rgba(255,255,255,.08);
    gap:12px;
    white-space:nowrap;
    overflow:hidden;
    text-decoration:none;
}

.brand-mark{
    width:39px;
    height:39px;
    min-width:39px;
    border-radius:9px;
    display:grid;
    place-items:center;
    background:#f7a51c;
    color:#0b2850;
    font-weight:900;
    font-size:24px;
    font-style:italic;
}

.brand-title{
    font-size:19px;
    font-weight:800;
    letter-spacing:.3px;
}

.brand-sub{
    font-size:8px;
    letter-spacing:.8px;
    opacity:.8;
    margin-top:-2px;
}

.side-nav{
    padding:20px 12px;
}

.nav-section{
    font-size:10px;
    letter-spacing:.7px;
    color:#9db0ca;
    font-weight:700;
    padding:
        15px 12px 9px;
    text-transform:uppercase;
    white-space:nowrap;
}

.side-link{
    display:flex;
    align-items:center;
    gap:14px;
    color:#dce7f7;
    text-decoration:none;
    padding:13px 14px;
    border-radius:8px;
    margin:3px 0;
    font-weight:500;
    white-space:nowrap;
    overflow:hidden;
    transition:.20s;
}

.side-link i{
    width:20px;
    min-width:20px;
    text-align:center;
    font-size:16px;
}

.side-link:hover{
    background:
        rgba(255,255,255,.08);
    color:#fff;
}

.side-link.active{
    background:
        linear-gradient(
            90deg,
            #2b72ef,
            #2a62df
        );
    color:#fff;
    box-shadow:
        0 6px 16px
        rgba(34,102,232,.25);
}


/* =========================================================
   VIEWER LOCKED MENU
========================================================= */

.side-link.viewer-locked{

    color:#71839e;

    background:
        rgba(255,255,255,.025);

    cursor:not-allowed;

    opacity:.58;

    pointer-events:none;

    user-select:none;

}


.side-link.viewer-locked i{

    color:#647892;

}


.side-link.viewer-locked .lock-icon{

    margin-left:auto;

    width:auto;

    min-width:auto;

    font-size:11px;

    color:#8395ae;

}


.viewer-notice{

    margin:
        8px 2px 14px;

    padding:
        10px 11px;

    border-radius:8px;

    background:
        rgba(255,255,255,.055);

    border:
        1px solid
        rgba(255,255,255,.08);

    color:#aebed2;

    font-size:10px;

    line-height:1.45;

}

.viewer-notice i{

    color:#f7a51c;

    margin-right:5px;

}


.main-wrap{
    margin-left:var(--sidebar-width);
    width:
        calc(100% - var(--sidebar-width));
    min-width:0;
    transition:
        margin-left .30s ease,
        width .30s ease;
}

.topbar{
    height:80px;
    background:#fff;
    border-bottom:
        1px solid #edf0f5;
    display:flex;
    align-items:center;
    padding:0 22px;
    gap:22px;
    position:sticky;
    top:0;
    z-index:1020;
}

.hamburger{
    width:42px;
    height:42px;
    display:grid;
    place-items:center;
    font-size:21px;
    color:#173258;
    cursor:pointer;
    border-radius:8px;
    background:transparent;
    border:0;
    padding:0;
    transition:
        background .20s ease,
        color .20s ease,
        transform .20s ease;
}

.hamburger:hover{
    background:#edf3ff;
    color:var(--blue);
}

.hamburger:active{
    transform:scale(.90);
}

.page-title{
    font-size:23px;
    font-weight:750;
    margin:0;
}

.page-subtitle{
    color:#9aa5b5;
    margin-left:-10px;
}

.top-actions{
    margin-left:auto;
    display:flex;
    align-items:center;
    gap:10px;
}

.top-select{
    height:42px;
    border:
        1px solid #e0e6ef;
    background:#fff;
    border-radius:7px;
    padding:0 13px;
    font-weight:600;
    color:#23324a;
    min-width:205px;
    cursor:pointer;
}

.top-select:focus{
    outline:none;
    border-color:#9bb9f5;
    box-shadow:
        0 0 0 3px
        rgba(33,105,232,.08);
}

.top-date{
    height:42px;
    border:
        1px solid #e0e6ef;
    border-radius:7px;
    background:#fff;
    padding:0 12px;
    color:#23324a;
    font-weight:600;
    display:flex;
    align-items:center;
}

.user{
    display:flex;
    align-items:center;
    gap:9px;
    padding-left:8px;
    font-weight:650;
}

.avatar{
    width:38px;
    height:38px;
    border-radius:50%;
    background:#edf1f7;
    color:#9aa8ba;
    display:grid;
    place-items:center;
    font-size:20px;
}

.user-menu-btn{
    background:transparent;
    border:0;
    cursor:pointer;
    color:var(--text);
}

.user-menu-btn:hover{
    color:var(--blue);
}

.user-menu-btn:focus{
    outline:none;
    box-shadow:none;
}

.user-menu-btn::after{
    display:none;
}

.user-menu-btn .avatar{
    transition:.20s;
}

.user-menu-btn:hover .avatar{
    background:#e5edff;
    color:var(--blue);
}

.user-menu-btn + .dropdown-menu{
    min-width:250px;
    margin-top:10px;
    border-radius:10px;
    padding:8px;
}

.dropdown-header{
    padding:8px 10px;
}

.dropdown-item{
    border-radius:7px;
    padding:10px 12px;
    font-weight:600;
}

.dropdown-item:hover{
    background:#fff0f0;
}

.dropdown-item.text-danger:hover{
    color:#dc3545 !important;
}

.dropdown-divider{
    margin:6px 0;
}

.logged-user-name{
    max-width:160px;
    overflow:hidden;
    text-overflow:ellipsis;
    white-space:nowrap;
}

.logged-user-role{
    font-size:11px;
    color:#7a879b;
    font-weight:500;
    margin-top:2px;
}

.content{
    padding:
        24px 20px 18px;
}


/* =========================================================
   VIEWER DASHBOARD
========================================================= */

.viewer-dashboard-grid{

    display:grid;

    grid-template-columns:
        repeat(3,minmax(0,1fr));

    gap:14px;

}


.viewer-stat-card{

    background:#fff;

    border:
        1px solid #edf0f5;

    border-radius:11px;

    box-shadow:
        0 4px 16px
        rgba(30,50,80,.04);

    padding:20px;

    min-height:155px;

    position:relative;

    overflow:hidden;

}


.viewer-stat-icon{

    width:58px;

    height:58px;

    border-radius:50%;

    display:grid;

    place-items:center;

    font-size:24px;

    margin-bottom:12px;

}


.viewer-stat-label{

    font-weight:700;

    font-size:12px;

    color:#465574;

    text-transform:uppercase;

    position:absolute;

    left:90px;

    top:31px;

}


.viewer-stat-value{

    font-size:27px;

    font-weight:800;

    position:absolute;

    left:90px;

    top:55px;

}


.viewer-stat-foot{

    position:absolute;

    left:20px;

    bottom:15px;

    font-size:12px;

    color:#718097;

}


.icon-green{
    background:#c9f5df;
    color:#21bb78;
}

.icon-orange{
    background:#ffe4bd;
    color:#f59416;
}

.icon-purple{
    background:#e3d5ff;
    color:#7b45e6;
}

.value-green{
    color:#22bd79;
}

.value-orange{
    color:#f58b13;
}

.value-purple{
    color:#7b45e6;
}


.viewer-welcome{

    background:#fff;

    border:
        1px solid #edf0f5;

    border-radius:11px;

    box-shadow:
        0 4px 16px
        rgba(30,50,80,.04);

    margin-top:14px;

    padding:18px 20px;

    color:#52627b;

}


.viewer-welcome strong{

    color:#172642;

}


.viewer-welcome i{

    color:#2169e8;

    margin-right:7px;

}


/* =========================================================
   NORMAL DASHBOARD STYLES
========================================================= */

.dashboard-grid{
    display:grid;
    grid-template-columns:
        repeat(5,minmax(0,1fr));
    gap:14px;
}

.stat-card,
.panel{
    background:#fff;
    border:
        1px solid #edf0f5;
    border-radius:11px;
    box-shadow:
        0 4px 16px
        rgba(30,50,80,.04);
}

.stat-card{
    padding:18px;
    min-height:143px;
    position:relative;
    overflow:hidden;
}

.stat-icon{
    width:58px;
    height:58px;
    border-radius:50%;
    display:grid;
    place-items:center;
    font-size:24px;
    margin-bottom:8px;
}

.stat-label{
    font-weight:700;
    font-size:12px;
    color:#465574;
    text-transform:uppercase;
    position:absolute;
    left:78px;
    top:28px;
}

.stat-value{
    font-size:26px;
    font-weight:800;
    position:absolute;
    left:78px;
    top:50px;
}

.stat-foot{
    position:absolute;
    left:18px;
    bottom:14px;
    font-size:12px;
    color:#718097;
}

.trend{
    position:absolute;
    right:18px;
    bottom:14px;
    font-size:12px;
    font-weight:700;
}

.up{
    color:#1db777;
}

.down{
    color:#ff4b4b;
}

.value-red{
    color:#ff4545;
}

.value-blue{
    color:#2268e4;
}

.panel{
    padding:0;
}

.panel-head{
    padding:16px 18px;
    border-bottom:
        1px solid #eef1f5;
    display:flex;
    align-items:center;
    justify-content:space-between;
}

.panel-title{
    font-size:14px;
    font-weight:800;
    color:#162747;
}

.panel-title i{
    margin-right:8px;
    color:#2169e8;
}

.mini-select{
    border:
        1px solid #e2e7ef;
    border-radius:6px;
    padding:
        7px 28px 7px 10px;
    background:#fff;
    color:#24334e;
    font-size:12px;
}

.middle-grid{
    display:grid;
    grid-template-columns:
        minmax(0,2.1fr)
        minmax(260px,.95fr);
    gap:14px;
    margin-top:14px;
}

.chart-wrap{
    height:285px;
    padding:
        12px 16px 10px;
}

.summary{
    padding:14px 18px;
}

.summary-row{
    display:flex;
    justify-content:space-between;
    align-items:center;
    padding:12px 0;
    border-bottom:
        1px solid #edf0f4;
}

.summary-row:last-child{
    border-bottom:0;
}

.summary-row span:first-child{
    color:#34425f;
}

.summary-value{
    font-weight:800;
}

.summary-green{
    color:#18ad6d;
}

.summary-red{
    color:#ff4646;
}

.summary-blue{
    color:#2369e5;
}

.summary-orange{
    color:#f58c15;
}

.lower-grid{
    display:grid;
    grid-template-columns:
        minmax(0,1.25fr)
        minmax(0,1.25fr)
        minmax(250px,.75fr);
    gap:14px;
    margin-top:14px;
}

.table-card{
    min-height:350px;
}

.table-wrap{
    overflow:auto;
}

.table{
    font-size:11px;
    margin-bottom:0;
}

.table thead th{
    font-weight:700;
    color:#34425f;
    background:#fbfcfe;
    border-bottom:
        1px solid #e7ebf1;
    padding:11px 9px;
    white-space:nowrap;
}

.table tbody td{
    padding:12px 9px;
    border-color:#edf0f4;
    white-space:nowrap;
    color:#33415d;
}

.amount-green{
    color:#16ad6c !important;
    font-weight:800;
}

.amount-red{
    color:#ff4646 !important;
    font-weight:800;
}

.amount-orange{
    color:#f58c15 !important;
    font-weight:800;
}

.view-btn{
    font-size:11px;
    color:#14aa6b;
    border:
        1px solid #8fe2bf;
    padding:
        7px 12px;
    border-radius:6px;
    text-decoration:none;
    font-weight:700;
}

.view-btn.red{
    color:#ff4b4b;
    border-color:#ffb0b0;
}

.quick{
    padding:16px 16px 15px;
}

.quick-btn{
    height:53px;
    border-radius:7px;
    color:#fff;
    text-decoration:none;
    display:flex;
    align-items:center;
    justify-content:space-between;
    padding:0 15px;
    margin-bottom:8px;
    font-weight:750;
}

.quick-btn i{
    font-size:20px;
}

.quick-green{
    background:
        linear-gradient(
            90deg,
            #27c984,
            #4edb99
        );
}

.quick-red{
    background:
        linear-gradient(
            90deg,
            #ff4848,
            #ff6060
        );
}

.quick-blue{
    background:
        linear-gradient(
            90deg,
            #2169e8,
            #357bed
        );
}

.quick-purple{
    background:
        linear-gradient(
            90deg,
            #7541df,
            #8d5be8
        );
}

.quick-orange{
    background:
        linear-gradient(
            90deg,
            #ff9515,
            #ffac34
        );
}

.quick-small{
    font-size:10px;
    display:block;
    opacity:.85;
    font-weight:500;
    margin-top:2px;
}

.footer{
    padding:
        17px 14px 5px;
    color:#6d7890;
    font-size:10px;
    display:flex;
    justify-content:space-between;
}

body.sidebar-collapsed .sidebar{
    width:0 !important;
    transform:
        translateX(-100%);
    box-shadow:none;
}

body.sidebar-collapsed .main-wrap{
    margin-left:0 !important;
    width:100% !important;
}

body.sidebar-collapsed
.sidebar .brand,

body.sidebar-collapsed
.sidebar .side-nav{
    opacity:0;
    pointer-events:none;
}

@media(max-width:1200px){

    .dashboard-grid{
        grid-template-columns:
            repeat(2,1fr);
    }

    .viewer-dashboard-grid{
        grid-template-columns:
            repeat(2,1fr);
    }

    .lower-grid{
        grid-template-columns:
            1fr 1fr;
    }

    .quick-card{
        grid-column:
            1/-1;
    }

    .middle-grid{
        grid-template-columns:1fr;
    }

}

@media(max-width:850px){

    .sidebar{
        width:238px;
        transform:
            translateX(-100%);
    }

    .main-wrap{
        margin-left:0;
        width:100%;
    }

    body.mobile-sidebar-open .sidebar{
        transform:
            translateX(0);
    }

    body.mobile-sidebar-open
    .sidebar .brand,

    body.mobile-sidebar-open
    .sidebar .side-nav{
        opacity:1;
        pointer-events:auto;
    }

    body.mobile-sidebar-open::after{
        content:"";
        position:fixed;
        inset:0;
        background:
            rgba(0,0,0,.45);
        z-index:1025;
    }

    .topbar{
        padding:0 12px;
        gap:10px;
    }

    .page-subtitle{
        display:none;
    }

    .top-select{
        min-width:150px;
    }

    .logged-user-name{
        display:none;
    }

    .user-menu-btn .fa-chevron-down{
        display:none;
    }

    .content{
        padding:16px 12px;
    }

    .lower-grid{
        grid-template-columns:1fr;
    }

    .top-date{
        display:none;
    }

}

@media(max-width:600px){

    .dashboard-grid{
        grid-template-columns:1fr;
    }

    .viewer-dashboard-grid{
        grid-template-columns:1fr;
    }

    .page-title{
        font-size:19px;
    }

    .top-actions{
        gap:4px;
    }

    .top-select{
        min-width:120px;
        max-width:150px;
        font-size:11px;
    }

    .avatar{
        width:36px;
        height:36px;
    }

    .footer{
        display:block;
    }

    .footer span{
        display:block;
        margin-bottom:4px;
    }

}

</style>

</head>


<body>

<div class="app-shell">


<!-- =====================================================
     SIDEBAR
===================================================== -->

<aside
    class="sidebar"
    id="mainSidebar"
>


<a
    href="index.php<?= $currentBranch ? '?branch='.$currentBranch : '' ?>"
    class="brand text-decoration-none text-white"
>

    <div class="brand-mark">
        V
    </div>

    <div class="brand-title-wrap">

        <div class="brand-title">
            VERDIVIEW
        </div>

        <div class="brand-sub">
            SALES &amp; EXPENSES SYSTEM
        </div>

    </div>

</a>


<nav class="side-nav">


<!-- =====================================================
     VIEWER NOTICE
===================================================== -->

<?php if ($isViewer): ?>

    <div class="viewer-notice">

        <i class="fa-solid fa-eye"></i>

        Viewer access:
        Dashboard only.

    </div>

<?php endif; ?>


<!-- =====================================================
     DASHBOARD
===================================================== -->

<a
    class="side-link <?= $isDashboard ? 'active' : '' ?>"
    href="index.php<?= $currentBranch ? '?branch='.$currentBranch : '' ?>"
>

    <i class="fa-solid fa-house"></i>

    <span>
        Dashboard
    </span>

</a>


<!-- =====================================================
     TRANSACTIONS
===================================================== -->

<div class="nav-section">
    Transactions
</div>


<!-- SALES -->

<?php if ($isViewer): ?>

    <div
        class="side-link viewer-locked"
        title="Viewer access restricted"
    >

        <i class="fa-solid fa-cart-shopping"></i>

        <span>
            Sales
        </span>

        <i class="fa-solid fa-lock lock-icon"></i>

    </div>

<?php else: ?>

    <a
        class="side-link <?= $isSales ? 'active' : '' ?>"
        href="sales.php<?= $currentBranch ? '?branch='.$currentBranch : '' ?>"
    >

        <i class="fa-solid fa-cart-shopping"></i>

        <span>
            Sales
        </span>

    </a>

<?php endif; ?>


<!-- EXPENSES -->

<?php if ($isViewer): ?>

    <div
        class="side-link viewer-locked"
        title="Viewer access restricted"
    >

        <i class="fa-solid fa-wallet"></i>

        <span>
            Expenses
        </span>

        <i class="fa-solid fa-lock lock-icon"></i>

    </div>

<?php else: ?>

    <a
        class="side-link <?= $isExpenses ? 'active' : '' ?>"
        href="expenses.php<?= $currentBranch ? '?branch='.$currentBranch : '' ?>"
    >

        <i class="fa-solid fa-wallet"></i>

        <span>
            Expenses
        </span>

    </a>

<?php endif; ?>


<!-- PURCHASES -->

<?php if ($isViewer): ?>

    <div
        class="side-link viewer-locked"
        title="Viewer access restricted"
    >

        <i class="fa-solid fa-bag-shopping"></i>

        <span>
            Purchases
        </span>

        <i class="fa-solid fa-lock lock-icon"></i>

    </div>

<?php else: ?>

    <a
        class="side-link <?= $isPurchases ? 'active' : '' ?>"
        href="purchases.php<?= $currentBranch ? '?branch='.$currentBranch : '' ?>"
    >

        <i class="fa-solid fa-bag-shopping"></i>

        <span>
            Purchases
        </span>

    </a>

<?php endif; ?>


<!-- INVENTORY -->

<?php if ($isViewer): ?>

    <div
        class="side-link viewer-locked"
        title="Viewer access restricted"
    >

        <i class="fa-solid fa-coins"></i>

        <span>
            Inventory
        </span>

        <i class="fa-solid fa-lock lock-icon"></i>

    </div>

<?php else: ?>

    <a
        class="side-link <?= $isVERDIVIEW ? 'active' : '' ?>"
        href="Inventory.php<?= $currentBranch ? '?branch='.$currentBranch : '' ?>"
    >

        <i class="fa-solid fa-coins"></i>

        <span>
            Inventory
        </span>

    </a>

<?php endif; ?>


<!-- =====================================================
     BANKING
===================================================== -->

<div class="nav-section">
    Banking
</div>


<?php if ($isViewer): ?>

    <div
        class="side-link viewer-locked"
        title="Viewer access restricted"
    >

        <i class="fa-solid fa-building-columns"></i>

        <span>
            Bank Reconciliation
        </span>

        <i class="fa-solid fa-lock lock-icon"></i>

    </div>

<?php else: ?>

    <a
        class="side-link <?= $isBankRecon ? 'active' : '' ?>"
        href="bank_reconciliation.php<?= $currentBranch ? '?branch='.$currentBranch : '' ?>"
    >

        <i class="fa-solid fa-building-columns"></i>

        <span>
            Bank Reconciliation
        </span>

    </a>

<?php endif; ?>


<!-- =====================================================
     REPORTS
===================================================== -->

<div class="nav-section">
    Reports
</div>


<?php if ($isViewer): ?>

    <div
        class="side-link viewer-locked"
        title="Viewer access restricted"
    >

        <i class="fa-solid fa-chart-line"></i>

        <span>
            Reports
        </span>

        <i class="fa-solid fa-lock lock-icon"></i>

    </div>

<?php else: ?>

    <a
        class="side-link <?= $isReports ? 'active' : '' ?>"
        href="reports.php<?= $currentBranch ? '?branch='.$currentBranch : '' ?>"
    >

        <i class="fa-solid fa-chart-line"></i>

        <span>
            Reports
        </span>

    </a>

<?php endif; ?>


</nav>

</aside>


<!-- =====================================================
     MAIN WRAP
===================================================== -->

<div class="main-wrap">


<header class="topbar">


<button
    type="button"
    id="sidebarToggle"
    class="hamburger"
    aria-label="Hide / Show Sidebar"
    title="Hide / Show Sidebar"
>

    <i
        id="sidebarToggleIcon"
        class="fa-solid fa-bars"
    ></i>

</button>


<h1 class="page-title">

    <?=htmlspecialchars($pageTitle)?>

</h1>


<?php if ($isDashboard): ?>

    <span class="page-subtitle">
        System Overview
    </span>

<?php endif; ?>


<div class="top-actions">


<!-- =====================================================
     BRANCH
===================================================== -->

<form
    method="get"
    action="<?=htmlspecialchars($currentFile)?>"
    class="m-0"
>

    <select
        name="branch"
        class="top-select"
        onchange="this.form.submit()"
        title="Select Branch"
    >

        <option value="0">
            ▥ &nbsp; All Branches
        </option>


        <?php foreach ($branches as $b): ?>

            <option
                value="<?=(int)$b['id']?>"
                <?=$currentBranch == (int)$b['id']
                    ? 'selected'
                    : ''?>
            >

                ▥ &nbsp;

                <?=htmlspecialchars(
                    $b['branch_name']
                )?>

            </option>

        <?php endforeach; ?>

    </select>

</form>


<!-- DATE -->

<div class="top-date">

    <i
        class="fa-regular fa-calendar me-2"
    ></i>

    <?=date('M d, Y')?>

</div>


<!-- USER -->

<div class="dropdown">

    <button
        type="button"
        class="user user-menu-btn"
        data-bs-toggle="dropdown"
        aria-expanded="false"
        title="<?=htmlspecialchars(
            $currentUserName
        )?>"
    >

        <div class="avatar">

            <i class="fa-solid fa-user"></i>

        </div>


        <span class="logged-user-name">

            <?=htmlspecialchars(
                $currentUserName
            )?>

        </span>


        <i
            class="fa-solid fa-chevron-down small"
        ></i>

    </button>


    <ul
        class="dropdown-menu dropdown-menu-end shadow-sm border-0"
    >

        <li>

            <div class="dropdown-header">

                <strong>

                    <?=htmlspecialchars(
                        $currentUserName
                    )?>

                </strong>


                <div class="logged-user-role">

                    <?=htmlspecialchars(
                        $currentUserRole
                    )?>

                </div>

            </div>

        </li>


        <li>

            <hr class="dropdown-divider">

        </li>


        <li>

            <a
                class="dropdown-item text-danger"
                href="logout.php"
                onclick="
                    return confirm(
                        'Are you sure you want to logout?'
                    );
                "
            >

                <i
                    class="fa-solid fa-right-from-bracket me-2"
                ></i>

                Logout

            </a>

        </li>

    </ul>

</div>


</div>

</header>


<main class="content">


<script>

document.addEventListener(
    "DOMContentLoaded",
    function(){

        const toggleButton =
            document.getElementById(
                "sidebarToggle"
            );

        const toggleIcon =
            document.getElementById(
                "sidebarToggleIcon"
            );


        if(!toggleButton){
            return;
        }


        let sidebarCollapsed =
            localStorage.getItem(
                "verdiview_sidebar"
            ) === "collapsed";


        function applySidebarState(){

            if(window.innerWidth <= 850){

                document.body.classList.remove(
                    "sidebar-collapsed"
                );

                if(sidebarCollapsed){

                    document.body.classList.remove(
                        "mobile-sidebar-open"
                    );

                }

                toggleIcon.className =
                    "fa-solid fa-bars";

                return;

            }


            if(sidebarCollapsed){

                document.body.classList.add(
                    "sidebar-collapsed"
                );

                toggleIcon.className =
                    "fa-solid fa-bars";

            }
            else{

                document.body.classList.remove(
                    "sidebar-collapsed"
                );

                toggleIcon.className =
                    "fa-solid fa-xmark";

            }

        }


        applySidebarState();


        toggleButton.addEventListener(
            "click",
            function(e){

                e.preventDefault();


                if(window.innerWidth <= 850){

                    document.body.classList.toggle(
                        "mobile-sidebar-open"
                    );


                    const isOpen =
                        document.body.classList.contains(
                            "mobile-sidebar-open"
                        );


                    toggleIcon.className =
                        isOpen
                        ? "fa-solid fa-xmark"
                        : "fa-solid fa-bars";


                    return;

                }


                document.body.classList.toggle(
                    "sidebar-collapsed"
                );


                sidebarCollapsed =
                    document.body.classList.contains(
                        "sidebar-collapsed"
                    );


                localStorage.setItem(
                    "verdiview_sidebar",
                    sidebarCollapsed
                        ? "collapsed"
                        : "open"
                );


                toggleIcon.className =
                    sidebarCollapsed
                    ? "fa-solid fa-bars"
                    : "fa-solid fa-xmark";

            }
        );


        document.addEventListener(
            "click",
            function(e){

                if(window.innerWidth > 850){
                    return;
                }


                if(
                    !document.body.classList.contains(
                        "mobile-sidebar-open"
                    )
                ){
                    return;
                }


                const sidebar =
                    document.getElementById(
                        "mainSidebar"
                    );


                if(
                    sidebar &&
                    !sidebar.contains(e.target) &&
                    !toggleButton.contains(e.target)
                ){

                    document.body.classList.remove(
                        "mobile-sidebar-open"
                    );


                    toggleIcon.className =
                        "fa-solid fa-bars";

                }

            }
        );


        window.addEventListener(
            "resize",
            function(){

                applySidebarState();

            }
        );

    }
);

</script>