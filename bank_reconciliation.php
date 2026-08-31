<?php

/*
|--------------------------------------------------------------------------
| BANK RECONCILIATION
|--------------------------------------------------------------------------
| Same layout/style as Sales / Expenses / Purchases
|--------------------------------------------------------------------------
*/

session_start();

require_once 'config.php';


/*
|--------------------------------------------------------------------------
| PAGE SETTINGS
|--------------------------------------------------------------------------
*/

$pageTitle = "Bank Reconciliation";

$currentFile = basename($_SERVER['PHP_SELF']);

$isDashboard = $currentFile === 'index.php';
$isSales = $currentFile === 'sales.php';
$isExpenses = $currentFile === 'expenses.php';
$isPurchases = $currentFile === 'purchases.php';
$isVERDIVIEW = $currentFile === 'Inventory.php';
$isBankRecon = $currentFile === 'bank_reconciliation.php';
$isReports = $currentFile === 'reports.php';


/*
|--------------------------------------------------------------------------
| BRANCHES
|--------------------------------------------------------------------------
*/

$branches = $pdo->query("
    SELECT *
    FROM branches
    WHERE is_active = 1
    ORDER BY branch_name
")->fetchAll(PDO::FETCH_ASSOC);


$currentBranch = isset($_GET['branch'])
    ? (int)$_GET['branch']
    : (int)($_SESSION['branch_id'] ?? 0);


/*
|--------------------------------------------------------------------------
| BANK ACCOUNTS
|--------------------------------------------------------------------------
*/

$accounts = $pdo->query("
    SELECT *
    FROM bank_accounts
    WHERE is_active = 1
    ORDER BY bank_name ASC
")->fetchAll(PDO::FETCH_ASSOC);


/*
|--------------------------------------------------------------------------
| SAVE RECONCILIATION
|--------------------------------------------------------------------------
*/

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['save_reconciliation'])
) {

    $bankAccountId = (int)($_POST['bank_account_id'] ?? 0);

    $statementDate = $_POST['statement_date'] ?? date('Y-m-d');

    $statementBalance =
        (float)($_POST['statement_balance'] ?? 0);

    $outstandingChecks =
        (float)($_POST['outstanding_checks'] ?? 0);

    $depositsInTransit =
        (float)($_POST['deposits_in_transit'] ?? 0);

    $bookBalance =
        (float)($_POST['book_balance'] ?? 0);


    /*
    |--------------------------------------------------------------------------
    | RECONCILIATION FORMULA
    |--------------------------------------------------------------------------
    |
    | Adjusted Bank Balance
    | = Statement Balance
    | - Outstanding Checks
    | + Deposits in Transit
    |
    */

    $adjustedBankBalance =
        $statementBalance
        - $outstandingChecks
        + $depositsInTransit;


    $difference =
        $adjustedBankBalance
        - $bookBalance;


    $status =
        abs($difference) < 0.01
        ? 'Reconciled'
        : 'Open';


    $reconciledAt =
        $status === 'Reconciled'
        ? date('Y-m-d H:i:s')
        : null;


    /*
    |--------------------------------------------------------------------------
    | INSERT
    |--------------------------------------------------------------------------
    */

    $stmt = $pdo->prepare("
        INSERT INTO bank_reconciliations
        (
            bank_account_id,
            statement_date,
            statement_balance,
            outstanding_checks,
            deposits_in_transit,
            adjusted_bank_balance,
            book_balance,
            difference,
            status,
            reconciled_at
        )
        VALUES
        (
            ?, ?, ?, ?, ?, ?, ?, ?, ?, ?
        )
    ");


    $stmt->execute([
        $bankAccountId,
        $statementDate,
        $statementBalance,
        $outstandingChecks,
        $depositsInTransit,
        $adjustedBankBalance,
        $bookBalance,
        $difference,
        $status,
        $reconciledAt
    ]);


    /*
    |--------------------------------------------------------------------------
    | REDIRECT
    |--------------------------------------------------------------------------
    */

    header(
        "Location: bank_reconciliation.php?branch="
        . $currentBranch
        . "&saved=1"
    );

    exit;
}


/*
|--------------------------------------------------------------------------
| FILTERS
|--------------------------------------------------------------------------
*/

$from = $_GET['from'] ?? '';

$to = $_GET['to'] ?? '';

$filterBank =
    (int)($_GET['bank_account_id'] ?? 0);


/*
|--------------------------------------------------------------------------
| RECONCILIATION HISTORY
|--------------------------------------------------------------------------
*/

$where = [];

$params = [];


if ($filterBank > 0) {

    $where[] = "r.bank_account_id = ?";

    $params[] = $filterBank;

}


if ($from !== '') {

    $where[] = "r.statement_date >= ?";

    $params[] = $from;

}


if ($to !== '') {

    $where[] = "r.statement_date <= ?";

    $params[] = $to;

}


$whereSQL = '';

if (!empty($where)) {

    $whereSQL =
        "WHERE " . implode(" AND ", $where);

}


$stmt = $pdo->prepare("
    SELECT
        r.*,
        b.bank_name,
        b.account_name,
        b.account_number

    FROM bank_reconciliations r

    LEFT JOIN bank_accounts b
        ON b.id = r.bank_account_id

    $whereSQL

    ORDER BY
        r.statement_date DESC,
        r.id DESC
");


$stmt->execute($params);

$history =
    $stmt->fetchAll(PDO::FETCH_ASSOC);


/*
|--------------------------------------------------------------------------
| SUMMARY
|--------------------------------------------------------------------------
*/

$summaryStmt = $pdo->prepare("
    SELECT

        COALESCE(
            SUM(r.statement_balance),
            0
        ) AS statement_balance,

        COALESCE(
            SUM(r.outstanding_checks),
            0
        ) AS outstanding_checks,

        COALESCE(
            SUM(r.deposits_in_transit),
            0
        ) AS deposits_in_transit

    FROM bank_reconciliations r

    $whereSQL
");


$summaryStmt->execute($params);

$summary =
    $summaryStmt->fetch(PDO::FETCH_ASSOC);


$summaryStatementBalance =
    (float)($summary['statement_balance'] ?? 0);

$summaryOutstandingChecks =
    (float)($summary['outstanding_checks'] ?? 0);

$summaryDepositsInTransit =
    (float)($summary['deposits_in_transit'] ?? 0);


/*
|--------------------------------------------------------------------------
| RECENT BANK TRANSACTIONS
|--------------------------------------------------------------------------
*/

$transactions = [];

try {

    $transactionStmt = $pdo->query("
        SELECT
            bt.*,
            ba.bank_name,
            ba.account_name

        FROM bank_transactions bt

        LEFT JOIN bank_accounts ba
            ON ba.id = bt.bank_account_id

        ORDER BY
            bt.transaction_date DESC,
            bt.id DESC

        LIMIT 20
    ");


    $transactions =
        $transactionStmt->fetchAll(PDO::FETCH_ASSOC);

} catch (Exception $e) {

    $transactions = [];

}

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

/* =========================================================
   ROOT
========================================================= */


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

}


/* =========================================================
   GLOBAL
========================================================= */

*{
    box-sizing:border-box;
}


body{

    margin:0;

    background:var(--bg);

    font-family:
        Inter,
        "Segoe UI",
        Arial,
        sans-serif;

    color:var(--text);

    font-size:14px;

}


/* =========================================================
   APP SHELL
========================================================= */

.app-shell{

    min-height:100vh;

    display:flex;

}



/* =========================================================
   SIDEBAR
========================================================= */

.sidebar{

    width:238px;

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

    box-shadow:
        8px 0 30px
        rgba(4,24,50,.08);

    transition:
        transform .25s ease;

}


/* =========================================================
   BRAND
========================================================= */

.brand{

    height:80px;

    display:flex;

    align-items:center;

    padding:0 24px;

    border-bottom:
        1px solid
        rgba(255,255,255,.08);

    gap:12px;

}


.brand-mark{

    width:39px;
    height:39px;

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


/* =========================================================
   NAVIGATION
========================================================= */

.side-nav{

    padding:20px 12px;

}


.nav-section{

    font-size:10px;

    letter-spacing:.7px;

    color:#9db0ca;

    font-weight:700;

    padding:
        15px
        12px
        9px;

    text-transform:uppercase;

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

    transition:
        background .2s ease,
        color .2s ease;

}


.side-link i{

    width:20px;

    text-align:center;

    font-size:16px;

}


.side-link:hover{

    background:
        rgba(255,255,255,.08);

    color:#fff;

}


.side-link.active{

    background:#2d6be6;

    color:#fff;

    box-shadow:
        0 5px 14px
        rgba(33,105,232,.25);

}


/* =========================================================
   MAIN
========================================================= */

.main-content{

    margin-left:238px;

    width:calc(100% - 238px);

    min-height:100vh;

}


/* =========================================================
   TOP HEADER
========================================================= */

.top-header{

    height:74px;

    background:#fff;

    border-bottom:
        1px solid
        var(--line);

    display:flex;

    align-items:center;

    justify-content:space-between;

    padding:
        0 24px;

}


.top-left{

    display:flex;

    align-items:center;

    gap:20px;

}


.menu-btn{

    border:0;

    background:none;

    font-size:20px;

    color:var(--navy);

    cursor:pointer;

}


.top-title{

    font-size:21px;

    font-weight:800;

}


.top-right{

    display:flex;

    align-items:center;

    gap:10px;

}


.branch-select{

    height:40px;

    min-width:190px;

    border:
        1px solid
        #dfe5ef;

    border-radius:8px;

    padding:
        0 12px;

    background:#fff;

    font-weight:600;

    color:var(--text);

}


.header-date{

    height:40px;

    display:flex;

    align-items:center;

    gap:8px;

    padding:
        0 13px;

    border:
        1px solid
        #dfe5ef;

    border-radius:8px;

    background:#fff;

}


.admin-box{

    display:flex;

    align-items:center;

    gap:8px;

    margin-left:5px;

    font-weight:700;

}


.admin-avatar{

    width:36px;
    height:36px;

    border-radius:50%;

    background:#eef2f7;

    display:grid;

    place-items:center;

    color:#91a0b5;

}


/* =========================================================
   PAGE
========================================================= */

.page-container{

    padding:
        25px
        18px
        30px;

}


.page-heading{

    display:flex;

    align-items:center;

    justify-content:space-between;

    margin-bottom:20px;

}


.page-heading h2{

    margin:0;

    font-size:24px;

    font-weight:800;

}


.page-heading p{

    margin:3px 0 0;

    color:var(--muted);

}


.btn-primary-custom{

    background:var(--blue);

    border:0;

    color:#fff;

    padding:
        11px 18px;

    border-radius:7px;

    font-weight:600;

    cursor:pointer;

}


.btn-primary-custom:hover{

    background:#185bcf;

    color:#fff;

}


/* =========================================================
   SUMMARY
========================================================= */

.summary-grid{

    display:grid;

    grid-template-columns:
        repeat(4,1fr);

    gap:16px;

    margin-bottom:18px;

}


.summary-card{

    background:#fff;

    border:
        1px solid
        var(--line);

    border-radius:10px;

    padding:
        19px 17px;

    box-shadow:
        0 3px 12px
        rgba(30,50,80,.035);

}


.summary-label{

    color:#69788e;

    font-size:11px;

    text-transform:uppercase;

    font-weight:500;

    margin-bottom:8px;

}


.summary-value{

    font-size:21px;

    font-weight:800;

}


.value-blue{

    color:var(--blue);

}


.value-red{

    color:#e83945;

}


.value-green{

    color:#14945c;

}


.value-orange{

    color:#f08b18;

}


/* =========================================================
   SYSTEM CARD
========================================================= */

.system-card{

    background:#fff;

    border:
        1px solid
        var(--line);

    border-radius:10px;

    box-shadow:
        0 3px 12px
        rgba(30,50,80,.035);

    margin-bottom:18px;

    overflow:hidden;

}


.card-header-custom{

    padding:
        15px 16px;

    border-bottom:
        1px solid
        var(--line);

    font-weight:700;

    display:flex;

    align-items:center;

    gap:10px;

}


.card-header-custom i{

    color:var(--blue);

}


/* =========================================================
   FILTER
========================================================= */

.filter-body{

    padding:
        15px 16px;

}


.form-label-custom{

    display:block;

    font-size:11px;

    font-weight:600;

    color:#617087;

    margin-bottom:6px;

}


.form-control,
.form-select{

    height:38px;

    border:
        1px solid
        #dfe5ef;

    border-radius:7px;

    font-size:13px;

}


.form-control:focus,
.form-select:focus{

    border-color:#8eb3ff;

    box-shadow:
        0 0 0 3px
        rgba(33,105,232,.08);

}


.filter-btn{

    height:38px;

    width:100%;

    background:#172b4d;

    color:#fff;

    border:0;

    border-radius:7px;

    font-weight:600;

}


.filter-btn:hover{

    background:#0e203d;

}


.reset-btn{

    height:38px;

    width:100%;

    background:#fff;

    color:#52647e;

    border:
        1px solid
        #dfe5ef;

    border-radius:7px;

    font-weight:600;

}


.reset-btn:hover{

    background:#f7f9fc;

}


/* =========================================================
   TABLE
========================================================= */

.table{

    margin:0;

}


.table thead th{

    background:#fbfcfe;

    color:#607088;

    font-size:10px;

    text-transform:uppercase;

    letter-spacing:.3px;

    font-weight:700;

    padding:
        12px 10px;

    border-bottom:
        1px solid
        var(--line);

    white-space:nowrap;

}


.table tbody td{

    padding:
        13px 10px;

    font-size:12px;

    vertical-align:middle;

    border-bottom:
        1px solid
        #edf0f5;

}


.table tbody tr:hover{

    background:#fafcff;

}


.empty-row{

    height:105px;

    text-align:center;

    color:#7b8799;

}


.badge-status{

    display:inline-block;

    padding:
        5px 9px;

    border-radius:5px;

    font-size:10px;

    font-weight:700;

}


.badge-reconciled{

    background:#e6f8ef;

    color:#15945c;

}


.badge-open{

    background:#fff3d9;

    color:#c87900;

}


.amount{

    font-weight:700;

}


.amount-red{

    color:#e83945;

}


.amount-green{

    color:#15945c;

}


/* =========================================================
   FOOTER
========================================================= */

.footer{

    display:flex;

    justify-content:space-between;

    padding:
        15px 0 0;

    color:#77859a;

    font-size:10px;

}


/* =========================================================
   MODAL
========================================================= */

.modal-header{

    border-bottom:
        1px solid
        var(--line);

}


.modal-title{

    font-weight:800;

}


.modal-footer{

    border-top:
        1px solid
        var(--line);

}


/* =========================================================
   MOBILE
========================================================= */

@media(max-width:1000px){

    .summary-grid{

        grid-template-columns:
            repeat(2,1fr);

    }

}


@media(max-width:768px){

    .sidebar{

        transform:
            translateX(-100%);

    }


    .sidebar.show-mobile{

        transform:
            translateX(0);

    }


    .main-content{

        margin-left:0;

        width:100%;

    }


    .summary-grid{

        grid-template-columns:1fr;

    }


    .top-right{

        display:none;

    }


    .page-heading{

        align-items:flex-start;

        gap:15px;

        flex-direction:column;

    }


    .page-heading .btn-primary-custom{

        width:100%;

    }


    .page-container{

        padding:
            18px 12px 25px;

    }

}


@media(max-width:480px){

    .top-header{

        padding:
            0 15px;

    }


    .top-title{

        font-size:18px;

    }


    .footer{

        flex-direction:column;

        gap:5px;

    }

}

</style>

</head>


<body>


<div class="app-shell">


<!-- ======================================================
     SIDEBAR
======================================================= -->

<aside class="sidebar">


<div class="brand">

    <div class="brand-mark">
        V
    </div>


    <div>

        <div class="brand-title">
            VERDIVIEW
        </div>

        <div class="brand-sub">
            SALES &amp; EXPENSES SYSTEM
        </div>

    </div>

</div>


<nav class="side-nav">


<a
    href="index.php"
    class="side-link <?= $isDashboard ? 'active' : '' ?>"
>

    <i class="fa-solid fa-house"></i>

    <span>
        Dashboard
    </span>

</a>


<div class="nav-section">
    Transactions
</div>


<a
    href="sales.php<?= $currentBranch ? '?branch='.$currentBranch : '' ?>"
    class="side-link <?= $isSales ? 'active' : '' ?>"
>

    <i class="fa-solid fa-cart-shopping"></i>

    <span>
        Sales
    </span>

</a>


<a
    href="expenses.php<?= $currentBranch ? '?branch='.$currentBranch : '' ?>"
    class="side-link <?= $isExpenses ? 'active' : '' ?>"
>

    <i class="fa-solid fa-wallet"></i>

    <span>
        Expenses
    </span>

</a>


<a
    href="purchases.php<?= $currentBranch ? '?branch='.$currentBranch : '' ?>"
    class="side-link <?= $isPurchases ? 'active' : '' ?>"
>

    <i class="fa-solid fa-bag-shopping"></i>

    <span>
        Purchases
    </span>

</a>


<a
    href="Inventory.php<?= $currentBranch ? '?branch='.$currentBranch : '' ?>"
    class="side-link <?= $isVERDIVIEW ? 'active' : '' ?>"
>

    <i class="fa-solid fa-coins"></i>

    <span>
        Inventory
    </span>

</a>


<div class="nav-section">
    Banking
</div>


<a
    href="bank_reconciliation.php<?= $currentBranch ? '?branch='.$currentBranch : '' ?>"
    class="side-link <?= $isBankRecon ? 'active' : '' ?>"
>

    <i class="fa-solid fa-building-columns"></i>

    <span>
        Bank Reconciliation
    </span>

</a>


<div class="nav-section">
    Reports
</div>


<a
    href="reports.php<?= $currentBranch ? '?branch='.$currentBranch : '' ?>"
    class="side-link <?= $isReports ? 'active' : '' ?>"
>

    <i class="fa-solid fa-chart-line"></i>

    <span>
        Reports
    </span>

</a>


</nav>

</aside>


<!-- ======================================================
     MAIN
======================================================= -->

<main class="main-content">


<!-- ======================================================
     TOP HEADER
======================================================= -->

<header class="top-header">


<div class="top-left">

<button
    type="button"
    class="menu-btn"
    aria-label="Toggle menu"
>

    <i class="fa-solid fa-bars"></i>

</button>


<div class="top-title">
    Bank Reconciliation
</div>

</div>


<div class="top-right">


<select
    class="branch-select"
    onchange="changeBranch(this.value)"
>

<?php foreach($branches as $branch): ?>

<option
    value="<?=$branch['id']?>"
    <?=$currentBranch == $branch['id'] ? 'selected' : ''?>
>

<?=htmlspecialchars(
    $branch['branch_name']
)?>

</option>

<?php endforeach; ?>

</select>


<div class="header-date">

    <i class="fa-regular fa-calendar"></i>

    <?=date('M d, Y')?>

</div>


<div class="admin-box">

    <div class="admin-avatar">

        <i class="fa-solid fa-user"></i>

    </div>

    <span>
        Admin
    </span>

    <i
        class="fa-solid fa-chevron-down"
        style="font-size:10px"
    ></i>

</div>


</div>

</header>


<!-- ======================================================
     PAGE CONTENT
======================================================= -->

<div class="page-container">


<!-- ======================================================
     TITLE
======================================================= -->

<div class="page-heading">


<div>

<h2>
    Bank Reconciliation
</h2>

<p>
    Match bank statement against your recorded transactions.
</p>

</div>


<button
    type="button"
    class="btn-primary-custom"
    data-bs-toggle="modal"
    data-bs-target="#reconciliationModal"
>

    <i class="fa-solid fa-plus me-1"></i>

    New Reconciliation

</button>


</div>


<!-- ======================================================
     SUCCESS MESSAGE
======================================================= -->

<?php if(isset($_GET['saved']) && $_GET['saved'] == '1'): ?>

<div
    class="alert alert-success alert-dismissible fade show"
    role="alert"
>

    <i class="fa-solid fa-circle-check me-2"></i>

    <strong>Reconciliation saved successfully.</strong>

    <button
        type="button"
        class="btn-close"
        data-bs-dismiss="alert"
    ></button>

</div>

<?php endif; ?>


<!-- ======================================================
     SUMMARY
======================================================= -->

<div class="summary-grid">


<div class="summary-card">

    <div class="summary-label">
        Statement Balance
    </div>

    <div class="summary-value value-blue">

        ₱<?=number_format(
            $summaryStatementBalance,
            2
        )?>

    </div>

</div>


<div class="summary-card">

    <div class="summary-label">
        Outstanding Checks
    </div>

    <div class="summary-value value-red">

        ₱<?=number_format(
            $summaryOutstandingChecks,
            2
        )?>

    </div>

</div>


<div class="summary-card">

    <div class="summary-label">
        Deposits in Transit
    </div>

    <div class="summary-value value-green">

        ₱<?=number_format(
            $summaryDepositsInTransit,
            2
        )?>

    </div>

</div>


<div class="summary-card">

    <div class="summary-label">
        Status
    </div>

    <div class="summary-value value-blue">

        <?php if(count($history) > 0): ?>

            Review

        <?php else: ?>

            Ready

        <?php endif; ?>

    </div>

</div>


</div>


<!-- ======================================================
     FILTER
======================================================= -->

<div class="system-card">


<div class="card-header-custom">

    <i class="fa-solid fa-filter"></i>

    <span>
        Search &amp; Filter Reconciliations
    </span>

</div>


<div class="filter-body">


<form method="GET">


<input
    type="hidden"
    name="branch"
    value="<?=$currentBranch?>"
>


<div class="row g-2">


<div class="col-md-3">

<label class="form-label-custom">
    Bank Account
</label>


<select
    name="bank_account_id"
    class="form-select"
>

<option value="0">
    All Accounts
</option>


<?php foreach($accounts as $account): ?>

<option
    value="<?=$account['id']?>"
    <?=$filterBank == $account['id'] ? 'selected' : ''?>
>

<?=htmlspecialchars(
    $account['bank_name']
)?>
 -
<?=htmlspecialchars(
    $account['account_name']
)?>

</option>

<?php endforeach; ?>

</select>

</div>


<div class="col-md-2">

<label class="form-label-custom">
    From
</label>


<input
    type="date"
    name="from"
    value="<?=htmlspecialchars($from)?>"
    class="form-control"
>

</div>


<div class="col-md-2">

<label class="form-label-custom">
    To
</label>


<input
    type="date"
    name="to"
    value="<?=htmlspecialchars($to)?>"
    class="form-control"
>

</div>


<div class="col-md-3">

<label class="form-label-custom">
    &nbsp;
</label>


<button
    type="submit"
    class="filter-btn"
>

    <i class="fa-solid fa-magnifying-glass me-1"></i>

    Filter

</button>

</div>


<div class="col-md-2">

<label class="form-label-custom">
    &nbsp;
</label>


<a
    href="bank_reconciliation.php?branch=<?=$currentBranch?>"
    class="reset-btn d-flex align-items-center justify-content-center text-decoration-none"
>

    <i class="fa-solid fa-rotate-left me-1"></i>

    Reset

</a>

</div>


</div>

</form>

</div>

</div>


<!-- ======================================================
     RECONCILIATION HISTORY
======================================================= -->

<div class="system-card">


<div class="card-header-custom">

    <i class="fa-solid fa-clock-rotate-left"></i>

    <span>
        Reconciliation History
    </span>

</div>


<div class="table-responsive">


<table class="table">


<thead>

<tr>

<th>
    Date
</th>

<th>
    Bank Account
</th>

<th>
    Statement Balance
</th>

<th>
    Outstanding Checks
</th>

<th>
    Deposits in Transit
</th>

<th>
    Adjusted Bank
</th>

<th>
    Book Balance
</th>

<th>
    Difference
</th>

<th>
    Status
</th>

<th>
    Action
</th>

</tr>

</thead>


<tbody>


<?php if(empty($history)): ?>

<tr>

<td
    colspan="10"
    class="empty-row"
>

    <i class="fa-regular fa-folder-open fa-2x mb-2"></i>

    <br>

    No reconciliation records yet.

</td>

</tr>

<?php endif; ?>


<?php foreach($history as $row): ?>


<tr>


<td>

<?=htmlspecialchars(
    date(
        'Y-m-d',
        strtotime($row['statement_date'])
    )
)?>

</td>


<td>

<strong>

<?=htmlspecialchars(
    $row['bank_name'] ?? ''
)?>

</strong>

<br>

<small class="text-muted">

<?=htmlspecialchars(
    $row['account_name'] ?? ''
)?>

</small>

<?php if(!empty($row['account_number'])): ?>

<br>

<small class="text-muted">

•••• <?=htmlspecialchars(
    substr(
        (string)$row['account_number'],
        -4
    )
)?>

</small>

<?php endif; ?>

</td>


<td class="amount">

₱<?=number_format(
    (float)$row['statement_balance'],
    2
)?>

</td>


<td class="amount amount-red">

₱<?=number_format(
    (float)$row['outstanding_checks'],
    2
)?>

</td>


<td class="amount amount-green">

₱<?=number_format(
    (float)$row['deposits_in_transit'],
    2
)?>

</td>


<td class="amount">

₱<?=number_format(
    (float)$row['adjusted_bank_balance'],
    2
)?>

</td>


<td class="amount">

₱<?=number_format(
    (float)$row['book_balance'],
    2
)?>

</td>


<td class="amount">

<?php

$differenceValue =
    (float)$row['difference'];

?>

<span
    class="<?=$differenceValue == 0 ? 'amount-green' : 'amount-red'?>"
>

₱<?=number_format(
    $differenceValue,
    2
)?>

</span>

</td>


<td>

<?php if(
    $row['status'] === 'Reconciled'
): ?>

<span
    class="badge-status badge-reconciled"
>

    ✓ Reconciled

</span>

<?php else: ?>

<span
    class="badge-status badge-open"
>

    Open

</span>

<?php endif; ?>

</td>


<td>

<button
    type="button"
    class="btn btn-sm btn-outline-primary"
    title="View"
    onclick="viewReconciliation(
        <?=htmlspecialchars(
            json_encode($row),
            ENT_QUOTES,
            'UTF-8'
        )?>
    )"
>

    <i class="fa-solid fa-eye"></i>

</button>

</td>


</tr>


<?php endforeach; ?>


</tbody>

</table>

</div>

</div>


<!-- ======================================================
     RECENT BANK TRANSACTIONS
======================================================= -->

<div class="system-card">


<div class="card-header-custom">

    <i class="fa-solid fa-building-columns"></i>

    <span>
        Recent Bank Transactions
    </span>

</div>


<div class="table-responsive">


<table class="table">


<thead>

<tr>

<th>
    Date
</th>

<th>
    Reference No.
</th>

<th>
    Description
</th>

<th>
    Deposit
</th>

<th>
    Withdrawal
</th>

<th>
    Book Balance
</th>

<th>
    Bank Balance
</th>

<th>
    Status
</th>

</tr>

</thead>


<tbody>


<?php if(empty($transactions)): ?>

<tr>

<td
    colspan="8"
    class="empty-row"
>

    <i class="fa-regular fa-folder-open fa-2x mb-2"></i>

    <br>

    No transactions to display.

</td>

</tr>

<?php endif; ?>


<?php foreach($transactions as $transaction): ?>


<tr>


<td>

<?=htmlspecialchars(
    date(
        'Y-m-d',
        strtotime(
            $transaction['transaction_date']
        )
    )
)?>

</td>


<td>

<?=htmlspecialchars(
    $transaction['reference_no'] ?? ''
)?>

</td>


<td>

<?=htmlspecialchars(
    $transaction['description'] ?? ''
)?>

</td>


<td class="amount amount-green">

₱<?=number_format(
    (float)($transaction['deposit'] ?? 0),
    2
)?>

</td>


<td class="amount amount-red">

₱<?=number_format(
    (float)($transaction['withdrawal'] ?? 0),
    2
)?>

</td>


<td>

₱<?=number_format(
    (float)($transaction['book_balance'] ?? 0),
    2
)?>

</td>


<td>

₱<?=number_format(
    (float)($transaction['bank_balance'] ?? 0),
    2
)?>

</td>


<td>

<?php

$transactionStatus =
    $transaction['status'] ?? '';

?>


<?php if(
    $transactionStatus === 'Reconciled'
): ?>

<span
    class="badge-status badge-reconciled"
>

    ✓ Reconciled

</span>

<?php elseif(
    $transactionStatus === 'Matched'
): ?>

<span
    class="badge-status badge-reconciled"
>

    Matched

</span>

<?php else: ?>

<span
    class="badge-status badge-open"
>

    Unmatched

</span>

<?php endif; ?>

</td>


</tr>


<?php endforeach; ?>


</tbody>

</table>

</div>

</div>


<!-- ======================================================
     FOOTER
======================================================= -->

<div class="footer">

<span>

© <?=date('Y')?> VIANCHRIS Sales &amp; Expenses System.
All rights reserved.

</span>


<span>

Version 1.0.0

</span>

</div>


</div>

</main>

</div>


<!-- ======================================================
     NEW RECONCILIATION MODAL
======================================================= -->

<div
    class="modal fade"
    id="reconciliationModal"
    tabindex="-1"
    aria-hidden="true"
>


<div class="modal-dialog modal-lg">


<div class="modal-content">


<form method="POST">


<div class="modal-header">

<h5 class="modal-title">

<i
    class="fa-solid fa-building-columns text-primary me-2"
></i>

New Bank Reconciliation

</h5>


<button
    type="button"
    class="btn-close"
    data-bs-dismiss="modal"
></button>

</div>


<div class="modal-body">


<div class="row g-3">


<div class="col-md-6">

<label class="form-label">
    Bank Account
</label>


<select
    name="bank_account_id"
    class="form-select"
    required
>

<option value="">
    Select Bank Account
</option>


<?php foreach($accounts as $account): ?>

<option
    value="<?=$account['id']?>"
>

<?=htmlspecialchars(
    $account['bank_name']
)?>

 -

<?=htmlspecialchars(
    $account['account_name']
)?>

</option>

<?php endforeach; ?>


</select>

</div>


<div class="col-md-6">

<label class="form-label">
    Statement Date
</label>


<input
    type="date"
    name="statement_date"
    value="<?=date('Y-m-d')?>"
    class="form-control"
    required
>

</div>


<div class="col-md-6">

<label class="form-label">
    Bank Statement Balance
</label>


<input
    type="number"
    step="0.01"
    min="0"
    name="statement_balance"
    id="statement_balance"
    value="0"
    class="form-control"
>


</div>


<div class="col-md-6">

<label class="form-label">
    Outstanding Checks
</label>


<input
    type="number"
    step="0.01"
    min="0"
    name="outstanding_checks"
    id="outstanding_checks"
    value="0"
    class="form-control"
>

</div>


<div class="col-md-6">

<label class="form-label">
    Deposits in Transit
</label>


<input
    type="number"
    step="0.01"
    min="0"
    name="deposits_in_transit"
    id="deposits_in_transit"
    value="0"
    class="form-control"
>

</div>


<div class="col-md-6">

<label class="form-label">
    Book Balance
</label>


<input
    type="number"
    step="0.01"
    name="book_balance"
    id="book_balance"
    value="0"
    class="form-control"
>

</div>


<div class="col-12">


<div
    class="p-3 rounded"
    style="
        background:#f5f8fd;
        border:1px solid #e3eaf4;
    "
>


<div
    class="d-flex justify-content-between"
>

<span>
    Adjusted Bank Balance
</span>


<strong
    id="adjustedBankBalance"
>

₱0.00

</strong>

</div>


<hr>


<div
    class="d-flex justify-content-between"
>

<span>
    Difference
</span>


<strong
    id="reconciliationDifference"
>

₱0.00

</strong>

</div>


<div
    id="reconciliationStatus"
    class="mt-2 fw-bold text-success"
>

✓ BALANCED

</div>


</div>

</div>


</div>

</div>


<div class="modal-footer">


<button
    type="button"
    class="btn btn-light"
    data-bs-dismiss="modal"
>

    Cancel

</button>


<button
    type="submit"
    name="save_reconciliation"
    class="btn btn-primary"
>

    <i class="fa-solid fa-check me-1"></i>

    Save Reconciliation

</button>


</div>


</form>

</div>

</div>

</div>


<!-- ======================================================
     VIEW RECONCILIATION MODAL
======================================================= -->

<div
    class="modal fade"
    id="viewReconciliationModal"
    tabindex="-1"
    aria-hidden="true"
>


<div class="modal-dialog modal-lg">


<div class="modal-content">


<div class="modal-header">

<h5 class="modal-title">

<i class="fa-solid fa-eye text-primary me-2"></i>

Reconciliation Details

</h5>


<button
    type="button"
    class="btn-close"
    data-bs-dismiss="modal"
></button>

</div>


<div class="modal-body">


<div class="row g-3">


<div class="col-md-6">

<div class="text-muted small">
    Statement Date
</div>

<strong id="viewStatementDate">
    -
</strong>

</div>


<div class="col-md-6">

<div class="text-muted small">
    Bank Account
</div>

<strong id="viewBankAccount">
    -
</strong>

</div>


<div class="col-md-6">

<div class="text-muted small">
    Statement Balance
</div>

<strong id="viewStatementBalance">
    ₱0.00
</strong>

</div>


<div class="col-md-6">

<div class="text-muted small">
    Outstanding Checks
</div>

<strong
    id="viewOutstandingChecks"
    class="text-danger"
>
    ₱0.00
</strong>

</div>


<div class="col-md-6">

<div class="text-muted small">
    Deposits in Transit
</div>

<strong
    id="viewDeposits"
    class="text-success"
>
    ₱0.00
</strong>

</div>


<div class="col-md-6">

<div class="text-muted small">
    Adjusted Bank Balance
</div>

<strong id="viewAdjustedBank">
    ₱0.00
</strong>

</div>


<div class="col-md-6">

<div class="text-muted small">
    Book Balance
</div>

<strong id="viewBookBalance">
    ₱0.00
</strong>

</div>


<div class="col-md-6">

<div class="text-muted small">
    Difference
</div>

<strong id="viewDifference">
    ₱0.00
</strong>

</div>


<div class="col-12">

<div class="text-muted small mb-1">
    Status
</div>

<span
    id="viewStatus"
    class="badge-status badge-reconciled"
>
    Reconciled
</span>

</div>


</div>

</div>


<div class="modal-footer">

<button
    type="button"
    class="btn btn-light"
    data-bs-dismiss="modal"
>

Close

</button>

</div>


</div>

</div>

</div>


<script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
></script>


<script>

/*
|--------------------------------------------------------------------------
| BRANCH SWITCH
|--------------------------------------------------------------------------
*/

function changeBranch(branch){

    let url =
        "bank_reconciliation.php";


    if(branch){

        url += "?branch=" + encodeURIComponent(branch);

    }


    window.location.href = url;

}


/*
|--------------------------------------------------------------------------
| MONEY FORMAT
|--------------------------------------------------------------------------
*/

function formatMoney(value){

    value =
        parseFloat(value) || 0;


    return "₱" +
        value.toLocaleString(
            "en-PH",
            {
                minimumFractionDigits:2,
                maximumFractionDigits:2
            }
        );

}


/*
|--------------------------------------------------------------------------
| RECONCILIATION CALCULATOR
|--------------------------------------------------------------------------
*/

function calculateReconciliation(){

    const statementElement =
        document.getElementById(
            "statement_balance"
        );


    const checksElement =
        document.getElementById(
            "outstanding_checks"
        );


    const depositsElement =
        document.getElementById(
            "deposits_in_transit"
        );


    const bookElement =
        document.getElementById(
            "book_balance"
        );


    if(
        !statementElement ||
        !checksElement ||
        !depositsElement ||
        !bookElement
    ){

        return;

    }


    const statement =
        parseFloat(
            statementElement.value
        ) || 0;


    const checks =
        parseFloat(
            checksElement.value
        ) || 0;


    const deposits =
        parseFloat(
            depositsElement.value
        ) || 0;


    const book =
        parseFloat(
            bookElement.value
        ) || 0;


    /*
    |--------------------------------------------------------------------------
    | FORMULA
    |--------------------------------------------------------------------------
    */

    const adjusted =
        statement
        - checks
        + deposits;


    const difference =
        adjusted - book;


    /*
    |--------------------------------------------------------------------------
    | DISPLAY ADJUSTED
    |--------------------------------------------------------------------------
    */

    const adjustedElement =
        document.getElementById(
            "adjustedBankBalance"
        );


    if(adjustedElement){

        adjustedElement.innerText =
            formatMoney(adjusted);

    }


    /*
    |--------------------------------------------------------------------------
    | DISPLAY DIFFERENCE
    |--------------------------------------------------------------------------
    */

    const differenceElement =
        document.getElementById(
            "reconciliationDifference"
        );


    if(differenceElement){

        differenceElement.innerText =
            formatMoney(difference);

    }


    /*
    |--------------------------------------------------------------------------
    | STATUS
    |--------------------------------------------------------------------------
    */

    const status =
        document.getElementById(
            "reconciliationStatus"
        );


    if(!status){

        return;

    }


    if(Math.abs(difference) < 0.01){

        status.innerHTML =
            "✓ BALANCED — Ready to Reconcile";

        status.className =
            "mt-2 fw-bold text-success";

    }
    else{

        status.innerHTML =
            "⚠ NOT BALANCED — Difference "
            +
            formatMoney(
                Math.abs(difference)
            );

        status.className =
            "mt-2 fw-bold text-danger";

    }

}


/*
|--------------------------------------------------------------------------
| CALCULATE WHILE TYPING
|--------------------------------------------------------------------------
*/

document
.querySelectorAll(
    "#reconciliationModal input[type=number]"
)
.forEach(function(input){

    input.addEventListener(
        "input",
        calculateReconciliation
    );

});


/*
|--------------------------------------------------------------------------
| CALCULATE WHEN MODAL OPENS
|--------------------------------------------------------------------------
*/

const reconciliationModal =
    document.getElementById(
        "reconciliationModal"
    );


if(reconciliationModal){

    reconciliationModal.addEventListener(
        "shown.bs.modal",
        function(){

            calculateReconciliation();

        }
    );

}


/*
|--------------------------------------------------------------------------
| MOBILE MENU
|--------------------------------------------------------------------------
*/

(function(){

    const menuBtn =
        document.querySelector(
            ".menu-btn"
        );


    const sidebar =
        document.querySelector(
            ".sidebar"
        );


    if(menuBtn && sidebar){

        menuBtn.addEventListener(
            "click",
            function(){

                sidebar.classList.toggle(
                    "show-mobile"
                );

            }
        );

    }

})();


/*
|--------------------------------------------------------------------------
| CLOSE MOBILE SIDEBAR WHEN CLICKING LINK
|--------------------------------------------------------------------------
*/

document
.querySelectorAll(".side-link")
.forEach(function(link){

    link.addEventListener(
        "click",
        function(){

            const sidebar =
                document.querySelector(
                    ".sidebar"
                );


            if(sidebar){

                sidebar.classList.remove(
                    "show-mobile"
                );

            }

        }
    );

});


/*
|--------------------------------------------------------------------------
| VIEW RECONCILIATION
|--------------------------------------------------------------------------
*/

function viewReconciliation(row){

    if(!row){

        return;

    }


    document.getElementById(
        "viewStatementDate"
    ).innerText =
        row.statement_date || "-";


    document.getElementById(
        "viewBankAccount"
    ).innerText =
        (row.bank_name || "")
        +
        (
            row.account_name
            ? " - " + row.account_name
            : ""
        );


    document.getElementById(
        "viewStatementBalance"
    ).innerText =
        formatMoney(
            row.statement_balance
        );


    document.getElementById(
        "viewOutstandingChecks"
    ).innerText =
        formatMoney(
            row.outstanding_checks
        );


    document.getElementById(
        "viewDeposits"
    ).innerText =
        formatMoney(
            row.deposits_in_transit
        );


    document.getElementById(
        "viewAdjustedBank"
    ).innerText =
        formatMoney(
            row.adjusted_bank_balance
        );


    document.getElementById(
        "viewBookBalance"
    ).innerText =
        formatMoney(
            row.book_balance
        );


    document.getElementById(
        "viewDifference"
    ).innerText =
        formatMoney(
            row.difference
        );


    const status =
        document.getElementById(
            "viewStatus"
        );


    if(row.status === "Reconciled"){

        status.innerText =
            "✓ Reconciled";

        status.className =
            "badge-status badge-reconciled";

    }
    else{

        status.innerText =
            "Open";

        status.className =
            "badge-status badge-open";

    }


    const modalElement =
        document.getElementById(
            "viewReconciliationModal"
        );


    const modal =
        bootstrap.Modal.getOrCreateInstance(
            modalElement
        );


    modal.show();

}


</script>


</body>

</html>