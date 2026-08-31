<?php

session_start();
require_once "auth.php";
require "config.php";

$pageTitle = "Expenses";

$action = $_GET['action'] ?? '';
$id = (int)($_GET['id'] ?? 0);

$selectedBranch = (int)(
    $_GET['branch']
    ?? $_SESSION['branch_id']
    ?? 0
);


/* =========================================================
   AUTO REFERENCE NUMBER
========================================================= */

function getNextExpenseReference(PDO $pdo): string
{
    $stmt = $pdo->query("
        SELECT reference_no
        FROM expenses
        WHERE reference_no LIKE 'VR-%'
        ORDER BY id DESC
        LIMIT 1
    ");

    $lastReference = $stmt->fetchColumn();

    if (!$lastReference) {
        return 'VR-000001';
    }

    $number = (int)str_replace('VR-', '', $lastReference);

    $number++;

    return 'VR-' . str_pad(
        $number,
        6,
        '0',
        STR_PAD_LEFT
    );
}


/* =========================================================
   NUMBER TO WORDS
========================================================= */

function numberToWords(int $number): string
{
    $ones = [
        '',
        'ONE',
        'TWO',
        'THREE',
        'FOUR',
        'FIVE',
        'SIX',
        'SEVEN',
        'EIGHT',
        'NINE',
        'TEN',
        'ELEVEN',
        'TWELVE',
        'THIRTEEN',
        'FOURTEEN',
        'FIFTEEN',
        'SIXTEEN',
        'SEVENTEEN',
        'EIGHTEEN',
        'NINETEEN'
    ];

    $tens = [
        '',
        '',
        'TWENTY',
        'THIRTY',
        'FORTY',
        'FIFTY',
        'SIXTY',
        'SEVENTY',
        'EIGHTY',
        'NINETY'
    ];

    if ($number < 20) {
        return $ones[$number];
    }

    if ($number < 100) {
        return $tens[(int)($number / 10)]
            . (
                $number % 10
                ? ' ' . $ones[$number % 10]
                : ''
            );
    }

    if ($number < 1000) {
        return $ones[(int)($number / 100)]
            . ' HUNDRED'
            . (
                $number % 100
                ? ' ' . numberToWords($number % 100)
                : ''
            );
    }

    if ($number < 1000000) {
        return numberToWords((int)($number / 1000))
            . ' THOUSAND'
            . (
                $number % 1000
                ? ' ' . numberToWords($number % 1000)
                : ''
            );
    }

    if ($number < 1000000000) {
        return numberToWords((int)($number / 1000000))
            . ' MILLION'
            . (
                $number % 1000000
                ? ' ' . numberToWords($number % 1000000)
                : ''
            );
    }

    return numberToWords((int)($number / 1000000000))
        . ' BILLION'
        . (
            $number % 1000000000
            ? ' ' . numberToWords($number % 1000000000)
            : ''
        );
}


function amountInWords(float $amount): string
{
    $amount = round($amount, 2);

    $whole = (int)floor($amount);

    $decimal = (int)round(
        ($amount - $whole) * 100
    );

    if ($decimal === 100) {
        $whole++;
        $decimal = 0;
    }

    $words = $whole > 0
        ? numberToWords($whole)
        : 'ZERO';

    return $words
        . ' PESOS AND '
        . str_pad(
            $decimal,
            2,
            '0',
            STR_PAD_LEFT
        )
        . '/100';
}


/* =========================================================
   DELETE
========================================================= */

if ($action === 'delete' && $id) {

    $s = $pdo->prepare("
        DELETE FROM expenses
        WHERE id = ?
    ");

    $s->execute([$id]);

    header(
        "Location: expenses.php?branch="
        . $selectedBranch
    );

    exit;
}


/* =========================================================
   SAVE PENDING EXPENSE
========================================================= */

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['save_pending'])
) {

    header('Content-Type: application/json');

    $pending =
        $_SESSION['pending_expense']
        ?? null;

    if (!$pending) {

        echo json_encode([
            'success' => false,
            'message' => 'No pending expense found.'
        ]);

        exit;
    }

    try {

        $editId =
            (int)(
                $pending['id']
                ?? 0
            );


        /* =================================================
           UPDATE
        ================================================= */

        if ($editId > 0) {

            $s = $pdo->prepare("
                UPDATE expenses SET

                    branch_id = ?,
                    expense_date = ?,
                    document_type = ?,
                    si_dr_no = ?,
                    payment_method = ?,
                    check_no = ?,
                    supplier = ?,
                    category = ?,
                    description = ?,
                    amount = ?

                WHERE id = ?
            ");

            $s->execute([

                $pending['branch_id'] ?? 0,

                $pending['expense_date']
                    ?? date('Y-m-d'),

                $pending['document_type']
                    ?? 'SI',

                $pending['si_dr_no']
                    ?? '',

                $pending['payment_method']
                    ?? 'CASH',

                $pending['check_no']
                    ?? '',

                $pending['supplier']
                    ?? '',

                $pending['category']
                    ?? '',

                $pending['description']
                    ?? '',

                $pending['amount']
                    ?? 0,

                $editId

            ]);

            $savedId = $editId;

        }

        /* =================================================
           INSERT
        ================================================= */

        else {

            $referenceNo =
                getNextExpenseReference($pdo);

            $s = $pdo->prepare("
                INSERT INTO expenses
                (
                    branch_id,
                    expense_date,
                    reference_no,
                    document_type,
                    si_dr_no,
                    payment_method,
                    check_no,
                    supplier,
                    category,
                    description,
                    amount
                )

                VALUES
                (
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?
                )
            ");

            $s->execute([

                $pending['branch_id'] ?? 0,

                $pending['expense_date']
                    ?? date('Y-m-d'),

                $referenceNo,

                $pending['document_type']
                    ?? 'SI',

                $pending['si_dr_no']
                    ?? '',

                $pending['payment_method']
                    ?? 'CASH',

                $pending['check_no']
                    ?? '',

                $pending['supplier']
                    ?? '',

                $pending['category']
                    ?? '',

                $pending['description']
                    ?? '',

                $pending['amount']
                    ?? 0

            ]);

            $savedId =
                (int)$pdo->lastInsertId();

            $_SESSION['pending_expense']
                ['reference_no'] =
                $referenceNo;
        }


        $_SESSION['pending_expense']
            ['saved'] = true;

        $_SESSION['pending_expense']
            ['saved_id'] = $savedId;


        echo json_encode([

            'success' => true,

            'id' => $savedId

        ]);

        exit;

    } catch (Throwable $e) {

        echo json_encode([

            'success' => false,

            'message' =>
                $e->getMessage()

        ]);

        exit;
    }
}


/* =========================================================
   PREPARE EXPENSE
========================================================= */

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && !isset($_POST['save_pending'])
) {

    $postId =
        (int)(
            $_POST['id']
            ?? 0
        );

    $branch =
        (int)(
            $_POST['branch_id']
            ?? 0
        );

    $date =
        $_POST['expense_date']
        ?? date('Y-m-d');

    $documentType =
        strtoupper(
            trim(
                $_POST['document_type']
                ?? 'SI'
            )
        );

    $siDrNo =
        trim(
            (string)(
                $_POST['si_dr_no']
                ?? ''
            )
        );

    $paymentMethod =
        strtoupper(
            trim(
                $_POST['payment_method']
                ?? 'CASH'
            )
        );

    $checkNo =
        trim(
            (string)(
                $_POST['check_no']
                ?? ''
            )
        );

    $supplier =
        trim(
            (string)(
                $_POST['supplier']
                ?? ''
            )
        );

    $category =
        trim(
            (string)(
                $_POST['category']
                ?? ''
            )
        );

    $desc =
        trim(
            (string)(
                $_POST['description']
                ?? ''
            )
        );

    $amount =
        (float)(
            $_POST['amount']
            ?? 0
        );


    /* =====================================================
       DOCUMENT VALIDATION
    ===================================================== */

    if (!in_array(
        $documentType,
        ['SI', 'DR'],
        true
    )) {

        $documentType = 'SI';
    }


    /* =====================================================
       PAYMENT VALIDATION
    ===================================================== */

    $allowedPayments = [

        'CASH',
        'GCASH',
        'CHEQUE',
        'BANK TRANSFER',
        'DEBIT'

    ];

    if (!in_array(
        $paymentMethod,
        $allowedPayments,
        true
    )) {

        $paymentMethod = 'CASH';
    }


    /* =====================================================
       CHECK NO ONLY FOR CHEQUE
    ===================================================== */

    if (
        $paymentMethod !== 'CHEQUE'
    ) {

        $checkNo = '';
    }


    /* =====================================================
       VOUCHER TYPE
    ===================================================== */

    $voucherType =
        strtoupper(
            trim(
                $_POST['voucher_type']
                ?? (
                    $paymentMethod === 'CHEQUE'
                    ? 'CHEQUE'
                    : 'CASH'
                )
            )
        );

    if (!in_array(
        $voucherType,
        ['CASH', 'CHEQUE'],
        true
    )) {

        $voucherType =
            $paymentMethod === 'CHEQUE'
            ? 'CHEQUE'
            : 'CASH';
    }


    /* =====================================================
       EXISTING REFERENCE
    ===================================================== */

    $existingReference = '';

    if ($postId > 0) {

        $s = $pdo->prepare("
            SELECT reference_no
            FROM expenses
            WHERE id = ?
            LIMIT 1
        ");

        $s->execute([
            $postId
        ]);

        $existingReference =
            $s->fetchColumn()
            ?: '';
    }


    /* =====================================================
       NEW REFERENCE
    ===================================================== */

    if (
        !$existingReference
        && $postId <= 0
    ) {

        $existingReference =
            getNextExpenseReference($pdo);
    }


    /* =====================================================
       STORE PENDING
    ===================================================== */

    $_SESSION['pending_expense'] = [

        'id' =>
            $postId,

        'branch_id' =>
            $branch,

        'expense_date' =>
            $date,

        'reference_no' =>
            $existingReference,

        'document_type' =>
            $documentType,

        'si_dr_no' =>
            $siDrNo,

        'payment_method' =>
            $paymentMethod,

        'check_no' =>
            $checkNo,

        'supplier' =>
            $supplier,

        'category' =>
            $category,

        'description' =>
            $desc,

        'amount' =>
            $amount,

        'voucher_type' =>
            $voucherType,

        'saved' =>
            false
    ];


    header(
        "Location: expenses.php"
        . "?action=voucher"
        . "&branch="
        . $branch
    );

    exit;
}


/* =========================================================
   EDIT
========================================================= */

$edit = null;

if (
    $action === 'edit'
    && $id
) {

    $s = $pdo->prepare("
        SELECT *
        FROM expenses
        WHERE id = ?
        LIMIT 1
    ");

    $s->execute([
        $id
    ]);

    $edit =
        $s->fetch();
}


/* =========================================================
   VOUCHER
========================================================= */

$voucher = null;

if (
    $action === 'voucher'
    && isset(
        $_SESSION['pending_expense']
    )
) {

    $pending =
        $_SESSION['pending_expense'];


    if (
        !empty(
            $pending['saved']
        )
        &&
        !empty(
            $pending['saved_id']
        )
    ) {

        $s = $pdo->prepare("
            SELECT
                e.*,
                b.branch_name

            FROM expenses e

            INNER JOIN branches b
                ON b.id = e.branch_id

            WHERE e.id = ?

            LIMIT 1
        ");

        $s->execute([
            $pending['saved_id']
        ]);

        $voucher =
            $s->fetch();

    } else {

        $s = $pdo->prepare("
            SELECT branch_name
            FROM branches
            WHERE id = ?
            LIMIT 1
        ");

        $s->execute([
            $pending['branch_id']
        ]);

        $branchName =
            $s->fetchColumn()
            ?: 'COMPANY';

        $voucher =
            $pending;

        $voucher['branch_name'] =
            $branchName;
    }
}


/* =========================================================
   BRANCHES
========================================================= */

$branches = $pdo->query("
    SELECT *
    FROM branches
    WHERE is_active = 1
    ORDER BY branch_name
")->fetchAll();


/* =========================================================
   FILTERS
========================================================= */

$where = [];

$params = [];

$from =
    $_GET['from']
    ?? '';

$to =
    $_GET['to']
    ?? '';

$q =
    trim(
        $_GET['q']
        ?? ''
    );


if ($selectedBranch > 0) {

    $where[] =
        "e.branch_id = ?";

    $params[] =
        $selectedBranch;
}


if ($from) {

    $where[] =
        "e.expense_date >= ?";

    $params[] =
        $from;
}


if ($to) {

    $where[] =
        "e.expense_date <= ?";

    $params[] =
        $to;
}


if ($q) {

    $where[] = "
        (
            e.reference_no LIKE ?
            OR e.document_type LIKE ?
            OR e.si_dr_no LIKE ?
            OR e.payment_method LIKE ?
            OR e.check_no LIKE ?
            OR e.supplier LIKE ?
            OR e.category LIKE ?
            OR e.description LIKE ?
        )
    ";

    for (
        $i = 0;
        $i < 8;
        $i++
    ) {

        $params[] =
            "%{$q}%";
    }
}


/* =========================================================
   RECORDS
========================================================= */

$sql = "
    SELECT
        e.*,
        b.branch_name

    FROM expenses e

    INNER JOIN branches b
        ON b.id = e.branch_id
";

if ($where) {

    $sql .=
        " WHERE "
        . implode(
            " AND ",
            $where
        );
}

$sql .= "
    ORDER BY
        e.expense_date DESC,
        e.id DESC
";

$s =
    $pdo->prepare($sql);

$s->execute(
    $params
);

$rows =
    $s->fetchAll();


/* =========================================================
   TOTAL
========================================================= */

$total =
    array_sum(
        array_map(
            fn($r) =>
                (float)(
                    $r['amount']
                    ?? 0
                ),
            $rows
        )
    );


include "header.php";

?>

<style>

/* =========================================================
   PAGE
========================================================= */

.expenses-page{
    width:100%;
}

.expenses-title{
    font-size:24px;
    font-weight:800;
    color:#162747;
}

.expenses-subtitle{
    color:#8995a8;
    font-size:12px;
}


/* =========================================================
   CARD
========================================================= */

.expense-card{
    background:#fff;
    border:1px solid #edf0f5;
    border-radius:11px;
    box-shadow:0 4px 16px rgba(30,50,80,.04);
    overflow:hidden;
}

.expense-card-header{
    padding:14px 18px;
    border-bottom:1px solid #edf0f5;
    font-size:14px;
    font-weight:800;
    color:#162747;
}

.expense-card-body{
    padding:18px;
}


/* =========================================================
   FORM
========================================================= */

.expense-label{
    display:block;
    font-size:11px;
    font-weight:800;
    color:#4b5a73;
    margin-bottom:5px;
    text-transform:uppercase;
}

.expense-input,
.document-type,
.payment-select{
    height:40px !important;
    min-height:40px !important;
    border:1px solid #dfe5ee;
    border-radius:7px;
    font-size:12px;
    padding:7px 10px;
    box-shadow:none;
}

.expense-input:focus,
.document-type:focus,
.payment-select:focus{
    border-color:#2169e8;
    box-shadow:
        0 0 0 3px
        rgba(33,105,232,.09);
}

.reference-fixed{
    background:#f5f7fa !important;
    color:#5c6b82 !important;
    font-weight:800;
}


/* =========================================================
   TABLE
========================================================= */

.expense-table{
    font-size:11px;
    width:100%;
}

.expense-table thead th{
    background:#f8fafd;
    color:#53617a;
    font-size:9px;
    text-transform:uppercase;
    font-weight:800;
    padding:11px 9px;
    white-space:nowrap;
}

.expense-table tbody td{
    padding:11px 9px;
    vertical-align:middle;
    white-space:nowrap;
}


/* =========================================================
   BADGES
========================================================= */

.document-badge{
    display:inline-block;
    min-width:28px;
    text-align:center;
    padding:4px 7px;
    border-radius:5px;
    font-size:9px;
    font-weight:900;
}

.document-si{
    background:#e8f1ff;
    color:#2169e8;
}

.document-dr{
    background:#fff1e6;
    color:#d66a00;
}

.payment-badge{
    display:inline-block;
    padding:4px 7px;
    border-radius:5px;
    background:#f1f4f8;
    color:#44516a;
    font-size:9px;
    font-weight:800;
}


/* =========================================================
   AMOUNT
========================================================= */

.expense-amount{
    color:#e04b4b;
    font-weight:900;
    white-space:nowrap;
}

.total-expense{
    color:#e04b4b;
    font-size:17px;
    font-weight:900;
}


/* =========================================================
   VOUCHER OVERLAY
========================================================= */

.voucher-overlay{
    position:fixed;
    inset:0;
    z-index:99999;
    background:
        rgba(15,23,42,.70);

    display:flex;
    align-items:center;
    justify-content:center;

    padding:20px;
}

.voucher-modal{
    width:100%;
    max-width:700px;

    max-height:96vh;

    background:#fff;

    border-radius:8px;

    box-shadow:
        0 25px 70px
        rgba(0,0,0,.35);

    overflow:hidden;

    display:flex;
    flex-direction:column;
}


/* =========================================================
   MODAL HEADER
========================================================= */

.voucher-header{
    height:52px;

    padding:0 15px;

    border-bottom:
        1px solid #e5e7eb;

    display:flex;

    justify-content:space-between;

    align-items:center;

    flex-shrink:0;
}

.voucher-header-title{
    font-size:15px;

    font-weight:800;

    color:#24344f;
}

.voucher-close{
    border:0;

    background:transparent;

    color:#7b8492;

    font-size:20px;

    line-height:1;

    cursor:pointer;
}


/* =========================================================
   VOUCHER BODY
========================================================= */

.voucher-body{
    padding:18px;

    overflow:auto;

    background:#f7f7f8;
}


/* =========================================================
   VOUCHER PAPER
========================================================= */

.voucher-paper{
    width:100%;

    background:#fff;

    border:1px solid #222;

    padding:14px 16px;

    box-sizing:border-box;
}


/* =========================================================
   TWO COPIES
========================================================= */

.voucher-copy{
    width:100%;

    min-height:330px;

    box-sizing:border-box;

    position:relative;

    padding:3px 0 8px 0;
}

.voucher-copy + .voucher-copy{
    border-top:
        1px dashed #555;

    margin-top:8px;

    padding-top:12px;
}


/* =========================================================
   COPY LABEL
========================================================= */

.copy-label{
    text-align:center;

    font-size:9px;

    font-weight:900;

    letter-spacing:.8px;

    margin-top:1px;

    margin-bottom:5px;

    color:#111;
}


/* =========================================================
   COMPANY
========================================================= */

.voucher-company{
    text-align:center;

    font-size:17px;

    font-weight:900;

    line-height:1.05;

    color:#111;

    margin-top:0;
}

.voucher-system{
    text-align:center;

    font-size:7px;

    font-weight:600;

    color:#444;

    margin-top:2px;
}


/* =========================================================
   TITLE
========================================================= */

.voucher-title{
    text-align:center;

    font-size:14px;

    font-weight:900;

    color:#111;

    margin-top:5px;

    line-height:1.1;
}


/* =========================================================
   VOUCHER TOP LINE
========================================================= */

.voucher-divider{
    border-top:
        1px solid #222;

    margin:
        8px 0 5px 0;
}


/* =========================================================
   INFO GRID
========================================================= */

.voucher-info{
    display:grid;

    grid-template-columns:
        1fr 1fr;

    column-gap:25px;

    row-gap:0;

    margin-bottom:6px;
}

.voucher-info-row{
    display:grid;

    grid-template-columns:
        75px 1fr;

    min-height:19px;

    border-bottom:
        1px solid #888;

    align-items:center;
}

.voucher-info-label{
    font-size:8px;

    font-weight:900;

    color:#111;
}

.voucher-info-value{
    font-size:8px;

    color:#111;

    white-space:nowrap;

    overflow:hidden;

    text-overflow:ellipsis;
}


/* =========================================================
   DETAILS TABLE
========================================================= */

.voucher-details-table{
    width:100%;

    border-collapse:collapse;

    margin-top:5px;

    table-layout:fixed;
}

.voucher-details-table th,
.voucher-details-table td{
    border:
        1px solid #555;

    padding:4px 5px;

    font-size:8px;

    color:#111;
}

.voucher-details-table th{
    text-align:center;

    font-weight:900;

    background:#f5f5f5;

    text-transform:uppercase;
}

.voucher-details-table th:nth-child(1){
    width:6%;
}

.voucher-details-table th:nth-child(2){
    width:66%;
}

.voucher-details-table th:nth-child(3){
    width:28%;
}

.voucher-details-table td{
    height:19px;
}

.voucher-details-table .center{
    text-align:center;
}

.voucher-details-table .right{
    text-align:right;
}


/* =========================================================
   TOTAL
========================================================= */

.voucher-total-row td{
    font-weight:900;

    background:#fafafa;
}

.voucher-total-label{
    text-align:right;

    font-size:8px !important;

    font-weight:900 !important;
}


/* =========================================================
   AMOUNT IN WORDS
========================================================= */

.amount-words{
    text-align:center;

    margin-top:7px;

    font-size:8px;

    line-height:1.2;

    color:#111;
}

.amount-words strong{
    font-size:10px;

    font-weight:900;
}


/* =========================================================
   SIGNATURES
========================================================= */

.voucher-signatures{
    display:grid;

    grid-template-columns:
        1fr 1fr 1fr;

    gap:20px;

    margin-top:25px;

    padding-bottom:4px;
}

.voucher-sign{
    text-align:center;

    border-top:
        1px solid #222;

    padding-top:3px;

    min-height:18px;

    font-size:7px;

    line-height:1.2;

    color:#111;
}

.voucher-sign-name{
    display:block;

    min-height:10px;

    font-size:8px;

    font-weight:800;

    margin-bottom:2px;
}


/* =========================================================
   STATUS
========================================================= */

.status-paid{
    color:#008f4c;

    font-weight:900;
}


/* =========================================================
   ACTIONS
========================================================= */

.voucher-actions{
    height:62px;

    padding:
        10px 15px;

    border-top:
        1px solid #e5e7eb;

    display:flex;

    justify-content:flex-end;

    align-items:center;

    gap:7px;

    flex-shrink:0;

    background:#fff;
}

.btn-voucher-back{
    background:#6c757d;

    color:#fff;

    border:0;

    border-radius:5px;

    padding:
        8px 13px;

    font-size:11px;

    font-weight:700;
}

.btn-voucher-print{
    background:#212529;

    color:#fff;

    border:0;

    border-radius:5px;

    padding:
        8px 13px;

    font-size:11px;

    font-weight:800;
}

.btn-voucher-save{
    background:#198754;

    color:#fff;

    border:0;

    border-radius:5px;

    padding:
        8px 13px;

    font-size:11px;

    font-weight:800;
}

.btn-voucher-back:hover,
.btn-voucher-print:hover,
.btn-voucher-save:hover{
    opacity:.90;

    color:#fff;
}


/* =========================================================
   MOBILE
========================================================= */

@media(max-width:768px){

    .voucher-overlay{
        padding:5px;
    }

    .voucher-modal{
        max-height:99vh;
    }

    .voucher-body{
        padding:8px;
    }

    .voucher-paper{
        padding:10px;
    }

    .voucher-info{
        grid-template-columns:1fr;
    }

    .voucher-actions{
        flex-wrap:wrap;

        height:auto;
    }

}


/* =========================================================
   PRINT
========================================================= */

@media print{

    @page{
        size:A4 portrait;

        margin:0;
    }


    html,
    body{

        width:210mm !important;

        height:297mm !important;

        margin:0 !important;

        padding:0 !important;

        background:#fff !important;

        overflow:hidden !important;
    }


    body *{
        visibility:hidden !important;
    }


    .voucher-overlay,
    .voucher-overlay *{
        visibility:visible !important;
    }


    .voucher-overlay{

        position:absolute !important;

        inset:0 !important;

        width:210mm !important;

        height:297mm !important;

        padding:0 !important;

        margin:0 !important;

        display:block !important;

        background:#fff !important;

        overflow:hidden !important;
    }


    .voucher-modal{

        position:absolute !important;

        left:5mm !important;

        top:5mm !important;

        width:200mm !important;

        height:287mm !important;

        max-width:none !important;

        max-height:none !important;

        border:0 !important;

        border-radius:0 !important;

        box-shadow:none !important;

        overflow:hidden !important;

        display:block !important;

        background:#fff !important;
    }


    .voucher-header,
    .voucher-actions{

        display:none !important;

        visibility:hidden !important;
    }


    .voucher-body{

        display:block !important;

        width:200mm !important;

        height:287mm !important;

        margin:0 !important;

        padding:0 !important;

        overflow:hidden !important;

        background:#fff !important;
    }


    .voucher-paper{

        width:200mm !important;

        height:287mm !important;

        margin:0 !important;

        padding:5mm !important;

        border:1px solid #222 !important;

        box-sizing:border-box !important;

        background:#fff !important;

        overflow:hidden !important;
    }


    .voucher-copy{

        width:100% !important;

        height:136mm !important;

        min-height:136mm !important;

        max-height:136mm !important;

        box-sizing:border-box !important;

        padding:
            3mm 2mm 2mm 2mm !important;

        margin:0 !important;

        overflow:hidden !important;

        page-break-inside:avoid !important;

        break-inside:avoid !important;
    }


    .voucher-copy + .voucher-copy{

        height:136mm !important;

        min-height:136mm !important;

        max-height:136mm !important;

        margin-top:2mm !important;

        padding-top:3mm !important;

        border-top:
            1px dashed #555 !important;

        box-sizing:border-box !important;

        page-break-inside:avoid !important;

        break-inside:avoid !important;
    }


    .copy-label{

        font-size:18px !important;

        margin:
            0 0 2mm 0 !important;
    }


    .voucher-company{

        font-size:18px !important;

        line-height:1 !important;

    }


    .voucher-system{

        font-size:18px !important;

        line-height:1 !important;

        margin-top:1mm !important;
    }


    .voucher-title{

        font-size:18px !important;

        line-height:1 !important;

        margin-top:2mm !important;
    }


    .voucher-divider{

        margin:
            3mm 0 2mm 0 !important;
    }


    .voucher-info{

        display:grid !important;

        grid-template-columns:
            1fr 1fr !important;

        column-gap:8mm !important;

        row-gap:0 !important;

        margin-bottom:2mm !important;
    }


    .voucher-info-row{

        grid-template-columns:
            22mm 1fr !important;

        min-height:6mm !important;

        height:6mm !important;

        border-bottom:
            1px solid #777 !important;
    }


    .voucher-info-label{

        font-size:14px !important;

        line-height:1 !important;
    }


    .voucher-info-value{

        font-size:16px !important;

        line-height:1 !important;

        overflow:hidden !important;

        text-overflow:ellipsis !important;

        white-space:nowrap !important;
    }


    .voucher-details-table{

        margin-top:2mm !important;

        width:100% !important;

        border-collapse:collapse !important;
    }


    .voucher-details-table th,
    .voucher-details-table td{

        font-size:14px !important;

        padding:
            1.5mm 2mm !important;

        border:
            1px solid #555 !important;

    }


    .voucher-details-table th{

        height:7mm !important;

    }


    .voucher-details-table td{

        height:6mm !important;

    }


    .amount-words{

        margin-top:3mm !important;

        font-size:14px !important;

        line-height:1.1 !important;
    }


    .amount-words strong{

        font-size:14px !important;
    }


    .voucher-signatures{

        display:grid !important;

        grid-template-columns:
            1fr 1fr 1fr !important;

        gap:7mm !important;

        margin-top:11mm !important;

        padding:0 !important;
    }


    .voucher-sign{

        font-size:14px !important;

        padding-top:2mm !important;

        min-height:814mm !important;

    }


    .voucher-sign-name{

        font-size:12px !important;

        line-height:1 !important;

    }


    *{

        -webkit-print-color-adjust:
            exact !important;

        print-color-adjust:
            exact !important;

    }

}

</style>


<div class="expenses-page">


<!-- =========================================================
     PAGE HEADER
========================================================= -->

<div class="d-flex justify-content-between align-items-center mb-3">

    <div>

        <h2 class="expenses-title mb-0">
            Expenses
        </h2>

        <div class="expenses-subtitle">
            Manage and monitor your expense transactions
        </div>

    </div>


    <a
        href="expenses.php?action=add&branch=<?=$selectedBranch?>"
        class="btn btn-danger"
    >

        <i class="fa-solid fa-plus me-1"></i>

        Add Expense

    </a>

</div>


<!-- =========================================================
     FORM
========================================================= -->

<?php if (
    $action === 'add'
    || $edit
): ?>

<div class="expense-card mb-4">

    <div class="expense-card-header">

        <i class="fa-solid fa-pen-to-square me-1"></i>

        <?= $edit
            ? 'Edit Expense'
            : 'Add Expense'
        ?>

    </div>


    <div class="expense-card-body">

        <form
            method="post"
            class="row g-2"
        >

            <input
                type="hidden"
                name="id"
                value="<?=$edit['id'] ?? 0?>"
            >


            <!-- BRANCH -->

            <div class="col-md-3">

                <label class="expense-label">
                    Branch
                </label>

                <select
                    name="branch_id"
                    class="form-select expense-input"
                    required
                >

                    <?php foreach (
                        $branches
                        as $b
                    ): ?>

                    <option
                        value="<?=$b['id']?>"

                        <?=(
                            (int)(
                                $edit['branch_id']
                                ?? $selectedBranch
                            )
                            ===
                            (int)$b['id']
                        )
                            ? 'selected'
                            : ''
                        ?>
                    >

                        <?=htmlspecialchars(
                            $b['branch_name']
                        )?>

                    </option>

                    <?php endforeach; ?>

                </select>

            </div>


            <!-- DATE -->

            <div class="col-md-2">

                <label class="expense-label">
                    Date
                </label>

                <input
                    type="date"
                    name="expense_date"
                    class="form-control expense-input"
                    required
                    value="<?=htmlspecialchars(
                        $edit['expense_date']
                        ?? date('Y-m-d')
                    )?>"
                >

            </div>


            <!-- REFERENCE -->

            <div class="col-md-2">

                <label class="expense-label">
                    Reference No.
                </label>

                <input
                    type="text"
                    class="form-control expense-input reference-fixed"
                    value="<?=htmlspecialchars(
                        $edit['reference_no']
                        ?? getNextExpenseReference($pdo)
                    )?>"
                    readonly
                >

            </div>


            <!-- DOCUMENT -->

            <div class="col-md-1">

                <label class="expense-label">
                    Doc.
                </label>

                <?php

                $currentDocumentType =
                    strtoupper(
                        $edit['document_type']
                        ?? 'SI'
                    );

                ?>

                <select
                    name="document_type"
                    class="form-select document-type"
                    required
                >

                    <option
                        value="SI"
                        <?=$currentDocumentType === 'SI'
                            ? 'selected'
                            : ''
                        ?>
                    >
                        SI
                    </option>

                    <option
                        value="DR"
                        <?=$currentDocumentType === 'DR'
                            ? 'selected'
                            : ''
                        ?>
                    >
                        DR
                    </option>

                </select>

            </div>


            <!-- SI / DR -->

            <div class="col-md-2">

                <label class="expense-label">
                    SI / DR No.
                </label>

                <input
                    type="text"
                    name="si_dr_no"
                    class="form-control expense-input"
                    placeholder="SI / DR No."
                    value="<?=htmlspecialchars(
                        $edit['si_dr_no']
                        ?? ''
                    )?>"
                >

            </div>


            <!-- PAYMENT -->

            <div class="col-md-2">

                <label class="expense-label">
                    Payment
                </label>

                <?php

                $currentPayment =
                    strtoupper(
                        $edit['payment_method']
                        ?? 'CASH'
                    );

                $paymentOptions = [

                    'CASH',
                    'GCASH',
                    'CHEQUE',
                    'BANK TRANSFER',
                    'DEBIT'

                ];

                ?>

                <select
                    name="payment_method"
                    id="payment_method"
                    class="form-select payment-select"
                    required
                >

                    <?php foreach (
                        $paymentOptions
                        as $payment
                    ): ?>

                    <option
                        value="<?=htmlspecialchars(
                            $payment,
                            ENT_QUOTES,
                            'UTF-8'
                        )?>"

                        <?=$currentPayment === $payment
                            ? 'selected'
                            : ''
                        ?>
                    >

                        <?=htmlspecialchars(
                            $payment
                        )?>

                    </option>

                    <?php endforeach; ?>

                </select>

            </div>


            <!-- CHECK NUMBER -->

            <div
                class="col-md-2"
                id="checkNoContainer"

                style="<?= $currentPayment === 'CHEQUE'
                    ? ''
                    : 'display:none;'
                ?>"
            >

                <label class="expense-label">
                    Check No.
                </label>

                <input
                    type="text"
                    name="check_no"
                    id="check_no"
                    class="form-control expense-input"
                    placeholder="Enter Check No."
                    autocomplete="off"

                    value="<?=htmlspecialchars(
                        $edit['check_no'] ?? '',
                        ENT_QUOTES,
                        'UTF-8'
                    )?>"

                    <?= $currentPayment === 'CHEQUE'
                        ? 'required'
                        : ''
                    ?>
                >

            </div>


            <!-- SUPPLIER -->

            <div class="col-md-3">

                <label class="expense-label">
                    Supplier
                </label>

                <input
                    type="text"
                    name="supplier"
                    class="form-control expense-input"

                    value="<?=htmlspecialchars(
                        $edit['supplier']
                        ?? ''
                    )?>"
                >

            </div>


            <!-- CATEGORY -->

            <div class="col-md-3">

                <label class="expense-label">
                    Category
                </label>

                <input
                    type="text"
                    name="category"
                    class="form-control expense-input"

                    value="<?=htmlspecialchars(
                        $edit['category']
                        ?? ''
                    )?>"
                >

            </div>


            <!-- AMOUNT -->

            <div class="col-md-2">

                <label class="expense-label">
                    Amount
                </label>

                <div class="input-group">

                    <span class="input-group-text">
                        ₱
                    </span>

                    <input
                        type="number"
                        step="0.01"
                        min="0"
                        name="amount"
                        class="form-control expense-input"
                        required

                        value="<?=htmlspecialchars(
                            $edit['amount']
                            ?? ''
                        )?>"
                    >

                </div>

            </div>


            <!-- DESCRIPTION -->

            <div class="col-md-4">

                <label class="expense-label">
                    Description
                </label>

                <input
                    type="text"
                    name="description"
                    class="form-control expense-input"
                    required

                    value="<?=htmlspecialchars(
                        $edit['description']
                        ?? ''
                    )?>"
                >

            </div>


            <!-- SAVE -->

            <div class="col-12 pt-2">

                <button
                    type="submit"
                    class="btn btn-primary"
                >

                    <i class="fa-solid fa-file-invoice me-1"></i>

                    SAVE &amp; CREATE VOUCHER

                </button>


                <a
                    href="expenses.php?branch=<?=$selectedBranch?>"
                    class="btn btn-secondary"
                >

                    Cancel

                </a>

            </div>

        </form>

    </div>

</div>

<?php endif; ?>


<!-- =========================================================
     FILTER
========================================================= -->

<form
    class="card card-body shadow-sm mb-3"
    method="get"
>

    <input
        type="hidden"
        name="branch"
        value="<?=$selectedBranch?>"
    >

    <div class="row g-2 align-items-end">

        <div class="col-md-2">

            <label>From</label>

            <input
                type="date"
                name="from"
                class="form-control"
                value="<?=htmlspecialchars($from)?>"
            >

        </div>


        <div class="col-md-2">

            <label>To</label>

            <input
                type="date"
                name="to"
                class="form-control"
                value="<?=htmlspecialchars($to)?>"
            >

        </div>


        <div class="col-md-4">

            <label>Search</label>

            <input
                type="text"
                name="q"
                class="form-control"

                value="<?=htmlspecialchars($q)?>"

                placeholder="Reference, SI/DR, check no., supplier, payment..."
            >

        </div>


        <div class="col-md-2">

            <button
                type="submit"
                class="btn btn-dark w-100"
            >

                <i class="fa-solid fa-filter me-1"></i>

                Filter

            </button>

        </div>


        <div class="col-md-2">

            <a
                href="expenses.php?branch=<?=$selectedBranch?>"
                class="btn btn-outline-secondary w-100"
            >

                Reset

            </a>

        </div>

    </div>

</form>


<!-- =========================================================
     RECORDS
========================================================= -->

<div class="expense-card">

    <div
        class="
            expense-card-header
            d-flex
            justify-content-between
            align-items-center
        "
    >

        <span>

            <i class="fa-solid fa-receipt me-1"></i>

            Expense Records

        </span>


        <div class="d-flex align-items-center gap-2">

            <a
                href="print-expenses.php?branch=<?=$selectedBranch?>&from=<?=urlencode($from)?>&to=<?=urlencode($to)?>&q=<?=urlencode($q)?>"
                target="_blank"
                class="btn btn-dark btn-sm"
            >

                <i class="fa-solid fa-print me-1"></i>

                PRINT EXPENSE

            </a>


            <strong class="total-expense">

                Total:

                ₱<?=number_format(
                    $total,
                    2
                )?>

            </strong>

        </div>

    </div>


    <div class="table-responsive">

        <table
            class="
                table
                table-striped
                table-hover
                mb-0
                expense-table
            "
        >

            <thead>

                <tr>

                    <th>Date</th>
                    <th>Branch</th>
                    <th>Reference</th>
                    <th>Document</th>
                    <th>SI / DR No.</th>
                    <th>Supplier</th>
                    <th>Category</th>
                    <th>Payment</th>
                    <th>Check No.</th>
                    <th>Description</th>

                    <th class="text-end">
                        Amount
                    </th>

                    <th>
                        Action
                    </th>

                </tr>

            </thead>


            <tbody>

            <?php if ($rows): ?>

                <?php foreach (
                    $rows
                    as $r
                ): ?>

                <?php

                $docType =
                    strtoupper(
                        $r['document_type']
                        ?? 'SI'
                    );

                $docClass =
                    $docType === 'DR'
                    ? 'document-dr'
                    : 'document-si';

                ?>

                <tr>

                    <td>
                        <?=htmlspecialchars(
                            $r['expense_date']
                        )?>
                    </td>


                    <td>
                        <?=htmlspecialchars(
                            $r['branch_name']
                        )?>
                    </td>


                    <td>

                        <strong>

                            <?=htmlspecialchars(
                                $r['reference_no']
                            )?>

                        </strong>

                    </td>


                    <td>

                        <span
                            class="
                                document-badge
                                <?=$docClass?>
                            "
                        >

                            <?=htmlspecialchars(
                                $docType
                            )?>

                        </span>

                    </td>


                    <td>
                        <?=htmlspecialchars(
                            $r['si_dr_no']
                            ?? ''
                        )?>
                    </td>


                    <td>
                        <?=htmlspecialchars(
                            $r['supplier']
                        )?>
                    </td>


                    <td>
                        <?=htmlspecialchars(
                            $r['category']
                        )?>
                    </td>


                    <td>

                        <span class="payment-badge">

                            <?=htmlspecialchars(
                                $r['payment_method']
                                ?? 'CASH'
                            )?>

                        </span>

                    </td>


                    <td>

                        <?php if (
                            strtoupper(
                                $r['payment_method']
                                ?? ''
                            ) === 'CHEQUE'

                            &&

                            trim(
                                (string)(
                                    $r['check_no']
                                    ?? ''
                                )
                            ) !== ''
                        ): ?>

                            <strong>

                                <?=htmlspecialchars(
                                    $r['check_no'],
                                    ENT_QUOTES,
                                    'UTF-8'
                                )?>

                            </strong>

                        <?php else: ?>

                            -

                        <?php endif; ?>

                    </td>


                    <td>

                        <?=htmlspecialchars(
                            $r['description']
                        )?>

                    </td>


                    <td
                        class="
                            text-end
                            expense-amount
                        "
                    >

                        ₱<?=number_format(
                            (float)$r['amount'],
                            2
                        )?>

                    </td>


                    <td>

                        <a
                            class="
                                btn
                                btn-sm
                                btn-outline-primary
                            "

                            href="?action=edit&id=<?=$r['id']?>&branch=<?=$selectedBranch?>"
                        >

                            Edit

                        </a>


                        <a
                            class="
                                btn
                                btn-sm
                                btn-outline-danger
                            "

                            onclick="
                                return confirm(
                                    'Delete this record?'
                                )
                            "

                            href="?action=delete&id=<?=$r['id']?>&branch=<?=$selectedBranch?>"
                        >

                            Delete

                        </a>

                    </td>

                </tr>

                <?php endforeach; ?>

            <?php else: ?>

                <tr>

                    <td
                        colspan="12"
                        class="
                            text-center
                            text-muted
                            py-5
                        "
                    >

                        No expense records found.

                    </td>

                </tr>

            <?php endif; ?>

            </tbody>

        </table>

    </div>

</div>

</div>


<!-- =========================================================
     VOUCHER
========================================================= -->

<?php if ($voucher): ?>

<?php

$voucherPayment =
    strtoupper(
        $voucher['payment_method']
        ?? 'CASH'
    );


/* =========================================================
   DOCUMENT TYPE FOR VOUCHER
========================================================= */

$docType =
    strtoupper(
        trim(
            $voucher['document_type']
            ?? 'SI'
        )
    );

if (!in_array(
    $docType,
    ['SI', 'DR'],
    true
)) {
    $docType = 'SI';
}


$voucherType =
    strtoupper(
        $voucher['voucher_type']
        ?? (
            $voucherPayment === 'CHEQUE'
            ? 'CHEQUE'
            : 'CASH'
        )
    );

$isCheque =
    $voucherType === 'CHEQUE'
    ||
    $voucherPayment === 'CHEQUE';

$voucherTitle =
    $isCheque
    ? 'CHECK VOUCHER'
    : 'CASH VOUCHER';


$voucherSaved =
    !empty(
        $_SESSION['pending_expense']
            ['saved']
    );


$displayReference =
    trim(
        $voucher['reference_no']
        ?? ''
    );

if (
    $displayReference === ''
) {

    $displayReference =
        getNextExpenseReference($pdo);
}


$displayCheckNo =
    trim(
        (string)(
            $voucher['check_no']
            ?? ''
        )
    );


$voucherAmount =
    (float)(
        $voucher['amount']
        ?? 0
    );


$words =
    amountInWords(
        $voucherAmount
    );


$voucherStatus =
    $voucherSaved
    ? 'PAID'
    : 'PENDING';


$receivedBy =
    trim(
        (string)(
            $voucher['supplier']
            ?? ''
        )
    );

?>

<div class="voucher-overlay">

    <div class="voucher-modal">


        <!-- =====================================================
             MODAL HEADER
        ====================================================== -->

        <div class="voucher-header">

            <div class="voucher-header-title">

                <i class="fa-solid fa-file-invoice-dollar me-1"></i>

                Voucher Preview

            </div>


            <button
                type="button"
                class="voucher-close"
                onclick="closeVoucher()"
            >

                &times;

            </button>

        </div>


        <!-- =====================================================
             VOUCHER BODY
        ====================================================== -->

        <div class="voucher-body">

            <div class="voucher-paper">


                <!-- =================================================
                     ORIGINAL
                ================================================== -->

                <div class="voucher-copy">

                    <div class="copy-label">
                        ORIGINAL
                    </div>


                    <div class="voucher-company">
                        VERDIVIEW RESTAURANT INC.
                    </div>


                    <div class="voucher-title">

                        <?=htmlspecialchars(
                            $voucherTitle
                        )?>

                    </div>


                    <div class="voucher-divider"></div>


                    <!-- TOP INFORMATION -->

                    <div class="voucher-info">


                        <!-- DATE -->

                        <div class="voucher-info-row">

                            <span class="voucher-info-label">
                                Date
                            </span>

                            <span class="voucher-info-value">

                                <?=htmlspecialchars(
                                    $voucher['expense_date']
                                    ?? ''
                                )?>

                            </span>

                        </div>


                        <!-- BRANCH -->

                        <div class="voucher-info-row">

                            <span class="voucher-info-label">
                                Branch
                            </span>

                            <span class="voucher-info-value">

                                <?=htmlspecialchars(
                                    $voucher['branch_name']
                                    ?? ''
                                )?>

                            </span>

                        </div>


                        <!-- SUPPLIER -->

                        <div class="voucher-info-row">

                            <span class="voucher-info-label">
                                Supplier
                            </span>

                            <span class="voucher-info-value">

                                <?=htmlspecialchars(
                                    $voucher['supplier']
                                    ?? ''
                                )?>

                            </span>

                        </div>


                        <!-- PAYMENT -->

                        <div class="voucher-info-row">

                            <span class="voucher-info-label">
                                Payment
                            </span>

                            <span class="voucher-info-value">

                                <?=htmlspecialchars(
                                    $voucherPayment
                                )?>

                            </span>

                        </div>


                    </div>


                    <!-- DETAILS TABLE -->

                    <table class="voucher-details-table">

                        <thead>

                            <tr>

                                <th>
                                    #
                                </th>

                                <th>
                                    EXPENSE DETAILS
                                </th>

                                <th>
                                    AMOUNT
                                </th>

                            </tr>

                        </thead>


                        <tbody>

                            <tr>

                                <td class="center">
                                    1
                                </td>

                                <td>

                                    <?=htmlspecialchars(
                                        $voucher['description']
                                        ?? ''
                                    )?>

                                    <?php if (
                                        !empty(
                                            $voucher['category']
                                        )
                                    ): ?>

                                        <br>

                                        <small>
                                            Category:
                                            <?=htmlspecialchars(
                                                $voucher['category']
                                            )?>
                                        </small>

                                    <?php endif; ?>

                                </td>

                                <td class="right">

                                    ₱<?=number_format(
                                        $voucherAmount,
                                        2
                                    )?>

                                </td>

                            </tr>


                            <?php if (
                                !empty(
                                    $voucher['si_dr_no']
                                )
                                ||
                                !empty(
                                    $displayCheckNo
                                )
                                ||
                                !empty(
                                    $displayReference
                                )
                            ): ?>

                            <tr>

                                <td></td>

                                <td>

                                    <strong>
                                        Reference:
                                    </strong>

                                    <?=htmlspecialchars(
                                        $displayReference
                                    )?>


                                    <?php if (
                                        !empty(
                                            $voucher['si_dr_no']
                                        )
                                    ): ?>

                                        &nbsp;&nbsp;

                                        <strong>
                                            <?= $docType === 'DR'
                                                ? 'DR No:'
                                                : 'SI No:'
                                            ?>
                                        </strong>

                                        <?=htmlspecialchars(
                                            $voucher['si_dr_no']
                                        )?>

                                    <?php endif; ?>


                                    <?php if (
                                        $isCheque
                                        &&
                                        $displayCheckNo !== ''
                                    ): ?>

                                        &nbsp;&nbsp;

                                        <strong>
                                            Check No:
                                        </strong>

                                        <?=htmlspecialchars(
                                            $displayCheckNo
                                        )?>

                                    <?php endif; ?>

                                </td>

                                <td></td>

                            </tr>

                            <?php endif; ?>


                            <tr
                                class="
                                    voucher-total-row
                                "
                            >

                                <td></td>

                                <td
                                    class="
                                        voucher-total-label
                                    "
                                >

                                    TOTAL

                                </td>

                                <td class="right">

                                    ₱<?=number_format(
                                        $voucherAmount,
                                        2
                                    )?>

                                </td>

                            </tr>

                        </tbody>

                    </table>


                    <!-- AMOUNT IN WORDS -->

                    <div class="amount-words">

                        <strong>
                            AMOUNT IN WORDS:
                        </strong>

                        <?=htmlspecialchars(
                            $words
                        )?>

                    </div>


                    <!-- SIGNATURES -->

                    <div class="voucher-signatures">


                        <div class="voucher-sign">

                            <span class="voucher-sign-name">
                                &nbsp;
                            </span>

                            Prepared By

                        </div>


                        <div class="voucher-sign">

                            <span class="voucher-sign-name">
                                &nbsp;
                            </span>

                            Approved By

                        </div>


                        <div class="voucher-sign">

                            <span class="voucher-sign-name">

                                <?=htmlspecialchars(
                                    $receivedBy
                                )?>

                            </span>

                            Paid / Received By

                        </div>


                    </div>

                </div>


                <!-- =================================================
                     DUPLICATE
                ================================================== -->

                <div class="voucher-copy">

                    <div class="copy-label">
                        DUPLICATE
                    </div>


                    <div class="voucher-company">
                        VERDIVIEW RESTAURANT INC.
                    </div>


                    <div class="voucher-title">

                        <?=htmlspecialchars(
                            $voucherTitle
                        )?>

                    </div>


                    <div class="voucher-divider"></div>


                    <div class="voucher-info">


                        <!-- DATE -->

                        <div class="voucher-info-row">

                            <span class="voucher-info-label">
                                Date
                            </span>

                            <span class="voucher-info-value">

                                <?=htmlspecialchars(
                                    $voucher['expense_date']
                                    ?? ''
                                )?>

                            </span>

                        </div>


                        <!-- BRANCH -->

                        <div class="voucher-info-row">

                            <span class="voucher-info-label">
                                Branch
                            </span>

                            <span class="voucher-info-value">

                                <?=htmlspecialchars(
                                    $voucher['branch_name']
                                    ?? ''
                                )?>

                            </span>

                        </div>


                        <!-- SUPPLIER -->

                        <div class="voucher-info-row">

                            <span class="voucher-info-label">
                                Supplier
                            </span>

                            <span class="voucher-info-value">

                                <?=htmlspecialchars(
                                    $voucher['supplier']
                                    ?? ''
                                )?>

                            </span>

                        </div>


                        <!-- PAYMENT -->

                        <div class="voucher-info-row">

                            <span class="voucher-info-label">
                                Payment
                            </span>

                            <span class="voucher-info-value">

                                <?=htmlspecialchars(
                                    $voucherPayment
                                )?>

                            </span>

                        </div>


                    </div>


                    <table class="voucher-details-table">

                        <thead>

                            <tr>

                                <th>
                                    #
                                </th>

                                <th>
                                    EXPENSE DETAILS
                                </th>

                                <th>
                                    AMOUNT
                                </th>

                            </tr>

                        </thead>


                        <tbody>

                            <tr>

                                <td class="center">
                                    1
                                </td>

                                <td>

                                    <?=htmlspecialchars(
                                        $voucher['description']
                                        ?? ''
                                    )?>

                                    <?php if (
                                        !empty(
                                            $voucher['category']
                                        )
                                    ): ?>

                                        <br>

                                        <small>
                                            Category:
                                            <?=htmlspecialchars(
                                                $voucher['category']
                                            )?>
                                        </small>

                                    <?php endif; ?>

                                </td>

                                <td class="right">

                                    ₱<?=number_format(
                                        $voucherAmount,
                                        2
                                    )?>

                                </td>

                            </tr>


                            <?php if (
                                !empty(
                                    $voucher['si_dr_no']
                                )
                                ||
                                !empty(
                                    $displayCheckNo
                                )
                                ||
                                !empty(
                                    $displayReference
                                )
                            ): ?>

                            <tr>

                                <td></td>

                                <td>

                                    <strong>
                                        Reference:
                                    </strong>

                                    <?=htmlspecialchars(
                                        $displayReference
                                    )?>


                                    <?php if (
                                        !empty(
                                            $voucher['si_dr_no']
                                        )
                                    ): ?>

                                        &nbsp;&nbsp;

                                        <strong>
                                            <?= $docType === 'DR'
                                                ? 'DR No:'
                                                : 'SI No:'
                                            ?>
                                        </strong>

                                        <?=htmlspecialchars(
                                            $voucher['si_dr_no']
                                        )?>

                                    <?php endif; ?>


                                    <?php if (
                                        $isCheque
                                        &&
                                        $displayCheckNo !== ''
                                    ): ?>

                                        &nbsp;&nbsp;

                                        <strong>
                                            Check No:
                                        </strong>

                                        <?=htmlspecialchars(
                                            $displayCheckNo
                                        )?>

                                    <?php endif; ?>

                                </td>

                                <td></td>

                            </tr>

                            <?php endif; ?>


                            <tr
                                class="
                                    voucher-total-row
                                "
                            >

                                <td></td>

                                <td
                                    class="
                                        voucher-total-label
                                    "
                                >

                                    TOTAL

                                </td>

                                <td class="right">

                                    ₱<?=number_format(
                                        $voucherAmount,
                                        2
                                    )?>

                                </td>

                            </tr>

                        </tbody>

                    </table>


                    <div class="amount-words">

                        <strong>
                            AMOUNT IN WORDS:
                        </strong>

                        <?=htmlspecialchars(
                            $words
                        )?>

                    </div>


                    <div class="voucher-signatures">


                        <div class="voucher-sign">

                            <span class="voucher-sign-name">
                                &nbsp;
                            </span>

                            Prepared By

                        </div>


                        <div class="voucher-sign">

                            <span class="voucher-sign-name">
                                &nbsp;
                            </span>

                            Approved By

                        </div>


                        <div class="voucher-sign">

                            <span class="voucher-sign-name">

                                <?=htmlspecialchars(
                                    $receivedBy
                                )?>

                            </span>

                            Paid / Received By

                        </div>


                    </div>

                </div>


            </div>

        </div>


        <!-- =====================================================
             ACTION BUTTONS
        ====================================================== -->

        <div class="voucher-actions">


            <button
                type="button"
                class="btn-voucher-back"
                onclick="closeVoucher()"
            >

                <i class="fa-solid fa-arrow-left me-1"></i>

                BACK / EDIT

            </button>


            <button
                type="button"
                class="btn-voucher-print"
                onclick="saveThenPrint()"
            >

                <i class="fa-solid fa-print me-1"></i>

                PRINT VOUCHER

            </button>


            <?php if (!$voucherSaved): ?>

            <button
                type="button"
                class="btn-voucher-save"
                onclick="saveOnly()"
            >

                <i class="fa-solid fa-check me-1"></i>

                CONFIRM &amp; SAVE

            </button>

            <?php else: ?>

            <button
                type="button"
                class="btn-voucher-save"
                onclick="window.print()"
            >

                <i class="fa-solid fa-print me-1"></i>

                PRINT AGAIN

            </button>

            <?php endif; ?>


        </div>

    </div>

</div>

<?php endif; ?>


<script>

/* =========================================================
   CHECK NUMBER
========================================================= */

function toggleCheckNo(){

    const payment =
        document.getElementById(
            'payment_method'
        );

    const container =
        document.getElementById(
            'checkNoContainer'
        );

    const input =
        document.getElementById(
            'check_no'
        );


    if(
        !payment ||
        !container ||
        !input
    ){

        return;

    }


    const value =
        payment.value
            .trim()
            .toUpperCase();


    if(
        value === 'CHEQUE'
    ){

        container.style.display = '';

        input.required = true;

    }else{

        container.style.display = 'none';

        input.required = false;

        input.value = '';

    }

}


/* =========================================================
   DOM READY
========================================================= */

document.addEventListener(
    'DOMContentLoaded',
    function(){

        const payment =
            document.getElementById(
                'payment_method'
            );


        if(payment){

            toggleCheckNo();


            payment.addEventListener(
                'change',
                toggleCheckNo
            );

        }

    }
);


/* =========================================================
   CLOSE VOUCHER
========================================================= */

function closeVoucher(){

    window.location.href =
        'expenses.php?branch=<?=$selectedBranch?>';

}


/* =========================================================
   SAVE PENDING
========================================================= */

async function savePendingExpense(){

    const formData =
        new FormData();

    formData.append(
        'save_pending',
        '1'
    );


    const response =
        await fetch(
            'expenses.php',
            {
                method:'POST',
                body:formData
            }
        );


    return await response.json();

}


/* =========================================================
   CONFIRM & SAVE
========================================================= */

async function saveOnly(){

    const button =
        document.querySelector(
            '.btn-voucher-save'
        );


    if(button){

        button.disabled = true;

        button.innerHTML =
            '<i class="fa-solid fa-spinner fa-spin me-1"></i> SAVING...';

    }


    try{

        const result =
            await savePendingExpense();


        if(!result.success){

            alert(
                result.message
                ||
                'Unable to save expense.'
            );


            if(button){

                button.disabled = false;

                button.innerHTML =
                    '<i class="fa-solid fa-check me-1"></i> CONFIRM & SAVE';

            }

            return;
        }


        alert(
            'Expense saved successfully.'
        );


        window.location.reload();


    }catch(error){

        console.error(error);


        alert(
            'An error occurred while saving.'
        );


        if(button){

            button.disabled = false;

            button.innerHTML =
                '<i class="fa-solid fa-check me-1"></i> CONFIRM & SAVE';

        }

    }

}


/* =========================================================
   SAVE THEN PRINT
========================================================= */

async function saveThenPrint(){

    const button =
        document.querySelector(
            '.btn-voucher-print'
        );


    if(button){

        button.disabled = true;

        button.innerHTML =
            '<i class="fa-solid fa-spinner fa-spin me-1"></i> SAVING...';

    }


    try{

        const result =
            await savePendingExpense();


        if(!result.success){

            alert(
                result.message
                ||
                'Unable to save expense.'
            );


            if(button){

                button.disabled = false;

                button.innerHTML =
                    '<i class="fa-solid fa-print me-1"></i> PRINT VOUCHER';

            }

            return;
        }


        if(button){

            button.innerHTML =
                '<i class="fa-solid fa-print me-1"></i> PRINTING...';

        }


        setTimeout(
            function(){

                window.print();

            },
            350
        );


    }catch(error){

        console.error(error);


        alert(
            'An error occurred while saving.'
        );


        if(button){

            button.disabled = false;

            button.innerHTML =
                '<i class="fa-solid fa-print me-1"></i> PRINT VOUCHER';

        }

    }

}

</script>


<?php

include "footer.php";

?>