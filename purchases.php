<?php

session_start();

require_once "auth.php";
require_once "config.php";

$pageTitle = "Purchases";

$userId = (int)(
    $_SESSION['user_id']
    ?? $_SESSION['id']
    ?? 0
);


/* =========================================================
   HELPER FUNCTIONS
========================================================= */

function h($value): string
{
    return htmlspecialchars(
        (string)($value ?? ''),
        ENT_QUOTES,
        'UTF-8'
    );
}


function cleanMoney($value): float
{
    $value = str_replace(
        ['₱', 'PHP', 'php', ',', ' '],
        '',
        (string)$value
    );

    return (float)$value;
}


function getNextVoucherNumber(PDO $pdo): string
{
    /*
     * PostgreSQL version.
     *
     * PVR-000001
     * PVR-000002
     * PVR-000003
     */

    $sql = "
        SELECT COALESCE(
            MAX(
                CAST(
                    SUBSTRING(voucher_no FROM 5)
                    AS INTEGER
                )
            ),
            0
        )
        FROM purchases
        WHERE voucher_no ~ '^PVR-[0-9]+$'
    ";

    $stmt = $pdo->query($sql);

    $lastNumber = (int)$stmt->fetchColumn();

    return 'PVR-' . str_pad(
        (string)($lastNumber + 1),
        6,
        '0',
        STR_PAD_LEFT
    );
}


function redirectPurchaseError(
    int $branchId,
    string $message
): void {
    header(
        "Location: purchases.php?branch="
        . urlencode((string)$branchId)
        . "&error="
        . urlencode($message)
    );

    exit;
}


function purchaseStatusTotal(
    PDO $pdo,
    string $whereSql,
    array $params,
    string $status
): float {

    $sql = "
        SELECT COALESCE(
            SUM(p.total_amount),
            0
        )
        FROM purchases p
        $whereSql
    ";

    if ($whereSql !== '') {
        $sql .= " AND p.status = ?";
    } else {
        $sql .= " WHERE p.status = ?";
    }

    $stmt = $pdo->prepare($sql);

    $stmt->execute(
        array_merge(
            $params,
            [$status]
        )
    );

    return (float)$stmt->fetchColumn();
}


/* =========================================================
   BRANCHES
========================================================= */

$branches = $pdo->query("
    SELECT
        id,
        branch_name
    FROM branches
    WHERE is_active = 1
    ORDER BY branch_name
")->fetchAll(PDO::FETCH_ASSOC);


$currentBranch = isset($_GET['branch'])
    ? (int)$_GET['branch']
    : (int)($_SESSION['branch_id'] ?? 0);


/* =========================================================
   EDIT PURCHASE
========================================================= */

$editId = (int)(
    $_GET['edit']
    ?? 0
);

$editPurchase = null;

if ($editId > 0) {

    $stmt = $pdo->prepare("
        SELECT *
        FROM purchases
        WHERE id = ?
        LIMIT 1
    ");

    $stmt->execute([
        $editId
    ]);

    $editPurchase =
        $stmt->fetch(PDO::FETCH_ASSOC);
}


/* =========================================================
   VOUCHER NUMBER
========================================================= */

if (
    $editPurchase
    && !empty($editPurchase['voucher_no'])
) {

    $nextVoucherNo =
        $editPurchase['voucher_no'];

} else {

    $nextVoucherNo =
        getNextVoucherNumber($pdo);
}


/* =========================================================
   SAVE PURCHASE
========================================================= */

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['save_purchase'])
) {

    $purchaseId = (int)(
        $_POST['purchase_id']
        ?? 0
    );

    $supplier = trim(
        (string)(
            $_POST['supplier']
            ?? ''
        )
    );

    $branchId = (int)(
        $_POST['branch_id']
        ?? 0
    );

    $submittedVoucherNo = trim(
        (string)(
            $_POST['voucher_no']
            ?? ''
        )
    );

    $paymentMethod = trim(
        (string)(
            $_POST['payment_method']
            ?? 'Cash'
        )
    );

    $status = trim(
        (string)(
            $_POST['status']
            ?? 'Paid'
        )
    );

    $receivedBy = trim(
        (string)(
            $_POST['received_by']
            ?? ''
        )
    );

    $notes = trim(
        (string)(
            $_POST['notes']
            ?? ''
        )
    );


    $documentType = strtoupper(
        trim(
            (string)(
                $_POST['document_type']
                ?? 'SI'
            )
        )
    );


    if (!in_array(
        $documentType,
        ['SI', 'DR'],
        true
    )) {

        $documentType = 'SI';
    }


    $receiptDates =
        $_POST['receipt_date']
        ?? [];

    $receiptNos =
        $_POST['receipt_no']
        ?? [];

    $amounts =
        $_POST['amount']
        ?? [];


    /* =====================================================
       VALIDATION
    ===================================================== */

    if ($supplier === '') {

        redirectPurchaseError(
            $branchId,
            "Supplier is required."
        );
    }


    if ($receivedBy === '') {

        redirectPurchaseError(
            $branchId,
            "Received By is required."
        );
    }


    if ($submittedVoucherNo === '') {

        redirectPurchaseError(
            $branchId,
            "Voucher number is required."
        );
    }


    if (!preg_match(
        '/^PVR-\d{6}$/',
        $submittedVoucherNo
    )) {

        redirectPurchaseError(
            $branchId,
            "Invalid voucher number."
        );
    }


    $allowedPaymentMethods = [
        'Cash',
        'Bank',
        'Credit',
        'Other'
    ];


    if (!in_array(
        $paymentMethod,
        $allowedPaymentMethods,
        true
    )) {

        $paymentMethod = 'Cash';
    }


    $allowedStatuses = [
        'Paid',
        'Unpaid',
        'Partial'
    ];


    if (!in_array(
        $status,
        $allowedStatuses,
        true
    )) {

        $status = 'Paid';
    }


    /* =====================================================
       VALIDATE BRANCH
    ===================================================== */

    $branchCheck = $pdo->prepare("
        SELECT id
        FROM branches
        WHERE id = ?
          AND is_active = 1
        LIMIT 1
    ");

    $branchCheck->execute([
        $branchId
    ]);


    if (!$branchCheck->fetchColumn()) {

        redirectPurchaseError(
            $branchId,
            "Please select a valid active branch."
        );
    }


    /* =====================================================
       RECEIPT ROWS
    ===================================================== */

    $rows = [];

    $rowCount = max(
        count($receiptNos),
        count($receiptDates),
        count($amounts)
    );


    for (
        $index = 0;
        $index < $rowCount;
        $index++
    ) {

        $receiptNo = trim(
            (string)(
                $receiptNos[$index]
                ?? ''
            )
        );


        $receiptDate = trim(
            (string)(
                $receiptDates[$index]
                ?? ''
            )
        );


        $amount = cleanMoney(
            $amounts[$index]
            ?? 0
        );


        /*
         * Completely empty row.
         */

        if (
            $receiptNo === ''
            && $receiptDate === ''
            && $amount <= 0
        ) {

            continue;
        }


        if ($receiptDate === '') {

            redirectPurchaseError(
                $branchId,
                "Receipt date is required on row "
                . ($index + 1)
                . "."
            );
        }


        $dateObject =
            DateTime::createFromFormat(
                'Y-m-d',
                $receiptDate
            );


        if (
            !$dateObject
            || $dateObject->format('Y-m-d')
                !== $receiptDate
        ) {

            redirectPurchaseError(
                $branchId,
                "Invalid receipt date on row "
                . ($index + 1)
                . "."
            );
        }


        if ($receiptNo === '') {

            redirectPurchaseError(
                $branchId,
                "Receipt number is required on row "
                . ($index + 1)
                . "."
            );
        }


        if ($amount <= 0) {

            redirectPurchaseError(
                $branchId,
                "Amount must be greater than zero on row "
                . ($index + 1)
                . "."
            );
        }


        $rows[] = [
            'receipt_no' => $receiptNo,
            'purchase_date' => $receiptDate,
            'amount' => $amount
        ];
    }


    if (!$rows) {

        redirectPurchaseError(
            $branchId,
            "Please add at least one receipt."
        );
    }


    /* =====================================================
       NOTES
    ===================================================== */

    $voucherNotes = $notes;

    $receivedInformation =
        "RECEIVED BY: " . $receivedBy;


    if ($voucherNotes !== '') {

        $voucherNotes .=
            " | "
            . $receivedInformation;

    } else {

        $voucherNotes =
            $receivedInformation;
    }


    /* =====================================================
       DATABASE TRANSACTION
    ===================================================== */

    try {

        $pdo->beginTransaction();


        /* =================================================
           EDIT EXISTING PURCHASE
        ================================================= */

        if ($purchaseId > 0) {

            $check = $pdo->prepare("
                SELECT
                    id,
                    voucher_no
                FROM purchases
                WHERE id = ?
                LIMIT 1
                FOR UPDATE
            ");

            $check->execute([
                $purchaseId
            ]);


            $existing =
                $check->fetch(PDO::FETCH_ASSOC);


            if (!$existing) {

                $pdo->rollBack();

                redirectPurchaseError(
                    $branchId,
                    "Purchase record not found."
                );
            }


            /*
             * Never change voucher number during edit.
             */

            $voucherNo =
                (string)(
                    $existing['voucher_no']
                    ?? $submittedVoucherNo
                );


            $row = $rows[0];


            $totalAmount =
                (float)$row['amount'];


            /* =============================================
               TAX COMPUTATION
            ============================================= */

            if ($documentType === 'SI') {

                $vatableAmount =
                    $totalAmount / 1.12;

                $ewtAmount =
                    $vatableAmount * 0.01;

            } else {

                $vatableAmount = 0;
                $ewtAmount = 0;
            }


            /* =============================================
               UPDATE
            ============================================= */

            $stmt = $pdo->prepare("
                UPDATE purchases
                SET
                    voucher_no = ?,
                    document_type = ?,
                    purchase_date = ?,
                    supplier = ?,
                    invoice_no = ?,
                    description = ?,
                    quantity = ?,
                    unit_cost = ?,
                    total_amount = ?,
                    vatable_amount = ?,
                    ewt_amount = ?,
                    payment_method = ?,
                    status = ?,
                    branch_id = ?,
                    notes = ?,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ");


            $stmt->execute([
                $voucherNo,
                $documentType,
                $row['purchase_date'],
                $supplier,
                $row['receipt_no'],
                'Purchase',
                1,
                $totalAmount,
                $totalAmount,
                $vatableAmount,
                $ewtAmount,
                $paymentMethod,
                $status,
                $branchId,
                $voucherNotes !== ''
                    ? $voucherNotes
                    : null,
                $purchaseId
            ]);


            $pdo->commit();


            header(
                "Location: purchases.php?branch="
                . urlencode((string)$branchId)
                . "&updated=1"
                . "&voucher="
                . urlencode($voucherNo)
            );

            exit;
        }


        /* =================================================
           NEW PURCHASE
        ================================================= */

        $voucherNo =
            $submittedVoucherNo;


        /* =================================================
           DUPLICATE VOUCHER CHECK
        ================================================= */

        $duplicateCheck = $pdo->prepare("
            SELECT id
            FROM purchases
            WHERE voucher_no = ?
            LIMIT 1
            FOR UPDATE
        ");

        $duplicateCheck->execute([
            $voucherNo
        ]);


        if ($duplicateCheck->fetchColumn()) {

            $pdo->rollBack();

            redirectPurchaseError(
                $branchId,
                "Voucher number "
                . $voucherNo
                . " is already used. Please refresh the page."
            );
        }


        /* =================================================
           INSERT
        ================================================= */

        $stmt = $pdo->prepare("
            INSERT INTO purchases
            (
                voucher_no,
                document_type,
                purchase_date,
                supplier,
                invoice_no,
                description,
                quantity,
                unit_cost,
                total_amount,
                vatable_amount,
                ewt_amount,
                payment_method,
                status,
                branch_id,
                notes,
                created_by
            )
            VALUES
            (
                ?, ?, ?, ?, ?, ?, ?, ?, ?,
                ?, ?, ?, ?, ?, ?, ?
            )
        ");


        foreach ($rows as $row) {

            $totalAmount =
                (float)$row['amount'];


            /* =============================================
               TAX COMPUTATION
            ============================================= */

            if ($documentType === 'SI') {

                $vatableAmount =
                    $totalAmount / 1.12;

                $ewtAmount =
                    $vatableAmount * 0.01;

            } else {

                $vatableAmount = 0;
                $ewtAmount = 0;
            }


            $stmt->execute([
                $voucherNo,
                $documentType,
                $row['purchase_date'],
                $supplier,
                $row['receipt_no'],
                'Purchase',
                1,
                $totalAmount,
                $totalAmount,
                $vatableAmount,
                $ewtAmount,
                $paymentMethod,
                $status,
                $branchId,
                $voucherNotes !== ''
                    ? $voucherNotes
                    : null,
                $userId
            ]);
        }


        $pdo->commit();


        header(
            "Location: purchases.php?branch="
            . urlencode((string)$branchId)
            . "&saved="
            . count($rows)
            . "&voucher="
            . urlencode($voucherNo)
        );

        exit;


    } catch (Throwable $e) {

        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }


        header(
            "Location: purchases.php?branch="
            . urlencode((string)$branchId)
            . "&error="
            . urlencode(
                "Failed to save purchase: "
                . $e->getMessage()
            )
        );

        exit;
    }
}


/* =========================================================
   DELETE PURCHASE
========================================================= */

if (isset($_GET['delete'])) {

    $deleteId = (int)(
        $_GET['delete']
    );


    if ($deleteId > 0) {

        try {

            $stmt = $pdo->prepare("
                DELETE FROM purchases
                WHERE id = ?
            ");

            $stmt->execute([
                $deleteId
            ]);

        } catch (Throwable $e) {

            header(
                "Location: purchases.php?branch="
                . urlencode((string)$currentBranch)
                . "&error="
                . urlencode(
                    "Failed to delete purchase: "
                    . $e->getMessage()
                )
            );

            exit;
        }
    }


    header(
        "Location: purchases.php?branch="
        . urlencode((string)$currentBranch)
        . "&deleted=1"
    );

    exit;
}


/* =========================================================
   FILTERS
========================================================= */

$where = [];

$params = [];


if ($currentBranch > 0) {

    $where[] =
        "p.branch_id = ?";

    $params[] =
        $currentBranch;
}


/* =========================================================
   DATE FROM
========================================================= */

$from = trim(
    (string)(
        $_GET['from']
        ?? ''
    )
);


if ($from !== '') {

    $where[] =
        "p.purchase_date >= ?";

    $params[] =
        $from;
}


/* =========================================================
   DATE TO
========================================================= */

$to = trim(
    (string)(
        $_GET['to']
        ?? ''
    )
);


if ($to !== '') {

    $where[] =
        "p.purchase_date <= ?";

    $params[] =
        $to;
}


/* =========================================================
   SUPPLIER
========================================================= */

$supplierFilter = trim(
    (string)(
        $_GET['supplier']
        ?? ''
    )
);


if ($supplierFilter !== '') {

    $where[] =
        "p.supplier ILIKE ?";

    $params[] =
        "%" . $supplierFilter . "%";
}


/* =========================================================
   RECEIPT / INVOICE
========================================================= */

$invoiceFilter = trim(
    (string)(
        $_GET['invoice_no']
        ?? ''
    )
);


if ($invoiceFilter !== '') {

    $where[] =
        "p.invoice_no ILIKE ?";

    $params[] =
        "%" . $invoiceFilter . "%";
}


/* =========================================================
   VOUCHER
========================================================= */

$voucherFilter = trim(
    (string)(
        $_GET['voucher_no']
        ?? ''
    )
);


if ($voucherFilter !== '') {

    $where[] =
        "p.voucher_no ILIKE ?";

    $params[] =
        "%" . $voucherFilter . "%";
}


/* =========================================================
   DOCUMENT TYPE
========================================================= */

$documentTypeFilter =
    strtoupper(
        trim(
            (string)(
                $_GET['document_type']
                ?? ''
            )
        )
    );


if (!in_array(
    $documentTypeFilter,
    ['SI', 'DR'],
    true
)) {

    $documentTypeFilter = '';
}


if ($documentTypeFilter !== '') {

    $where[] =
        "p.document_type = ?";

    $params[] =
        $documentTypeFilter;
}


/* =========================================================
   PAYMENT
========================================================= */

$paymentFilter = trim(
    (string)(
        $_GET['payment_method']
        ?? ''
    )
);


if ($paymentFilter !== '') {

    $where[] =
        "p.payment_method = ?";

    $params[] =
        $paymentFilter;
}


/* =========================================================
   STATUS
========================================================= */

$statusFilter = trim(
    (string)(
        $_GET['status']
        ?? ''
    )
);


if ($statusFilter !== '') {

    $where[] =
        "p.status = ?";

    $params[] =
        $statusFilter;
}


/* =========================================================
   WHERE SQL
========================================================= */

$whereSql =
    $where
        ? "WHERE " . implode(
            " AND ",
            $where
        )
        : "";


/* =========================================================
   PURCHASE LIST
========================================================= */

$sql = "
    SELECT
        p.*,
        COALESCE(
            b.branch_name,
            'All Branches'
        ) AS branch_name
    FROM purchases p
    LEFT JOIN branches b
        ON b.id = p.branch_id
    $whereSql
    ORDER BY
        p.purchase_date DESC,
        p.id DESC
";


$stmt =
    $pdo->prepare($sql);

$stmt->execute($params);

$purchases =
    $stmt->fetchAll(
        PDO::FETCH_ASSOC
    );


/* =========================================================
   TOTAL PURCHASES
========================================================= */

$stmt = $pdo->prepare("
    SELECT COALESCE(
        SUM(p.total_amount),
        0
    )
    FROM purchases p
    $whereSql
");

$stmt->execute($params);

$totalPurchases =
    (float)$stmt->fetchColumn();


/* =========================================================
   TOTAL VATABLE
========================================================= */

$stmt = $pdo->prepare("
    SELECT COALESCE(
        SUM(p.vatable_amount),
        0
    )
    FROM purchases p
    $whereSql
");

$stmt->execute($params);

$totalVatable =
    (float)$stmt->fetchColumn();


/* =========================================================
   TOTAL EWT
========================================================= */

$stmt = $pdo->prepare("
    SELECT COALESCE(
        SUM(p.ewt_amount),
        0
    )
    FROM purchases p
    $whereSql
");

$stmt->execute($params);

$totalEwt =
    (float)$stmt->fetchColumn();


/* =========================================================
   STATUS TOTALS
========================================================= */

$totalPaid =
    purchaseStatusTotal(
        $pdo,
        $whereSql,
        $params,
        'Paid'
    );


$totalUnpaid =
    purchaseStatusTotal(
        $pdo,
        $whereSql,
        $params,
        'Unpaid'
    );


$totalPartial =
    purchaseStatusTotal(
        $pdo,
        $whereSql,
        $params,
        'Partial'
    );

?>


<?php include "header.php"; ?>


<style>

:root{
    --navy:#09264b;
    --blue:#2169e8;
    --green:#28c884;
    --red:#ff4b4b;
    --orange:#f0a000;
    --text:#172642;
    --muted:#7a879b;
    --line:#e7ebf2;
    --bg:#f7f9fc;
}

*{
    box-sizing:border-box;
}

body{
    margin:0;
    background:var(--bg);
    color:var(--text);
    font-family:Inter,"Segoe UI",Arial,sans-serif;
    font-size:14px;
}

.page-container{
    padding:25px 18px 30px;
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

.page-actions{
    display:flex;
    align-items:center;
    gap:10px;
}

.btn-add{
    height:40px;
    background:var(--green);
    color:#fff;
    border:0;
    padding:0 18px;
    border-radius:7px;
    font-weight:700;
    display:flex;
    align-items:center;
    justify-content:center;
    white-space:nowrap;
}

.btn-print-purchase{
    height:40px;
    background:#fff;
    color:#172642;
    border:1px solid #d7dee9;
    padding:0 16px;
    border-radius:7px;
    font-weight:700;
    display:flex;
    align-items:center;
    justify-content:center;
    gap:7px;
    text-decoration:none;
    white-space:nowrap;
}

.summary-card{
    background:#fff;
    border:1px solid var(--line);
    border-radius:10px;
    padding:17px;
    box-shadow:0 3px 12px rgba(30,50,80,.035);
    height:100%;
}

.summary-label{
    color:#69788e;
    font-size:10px;
    text-transform:uppercase;
    font-weight:700;
}

.summary-value{
    font-size:20px;
    font-weight:800;
    margin-top:5px;
}

.summary-paid{
    color:#16ad6c;
}

.summary-unpaid{
    color:#dc3545;
}

.summary-partial{
    color:#d58b00;
}

.summary-ewt{
    color:#c62828;
}

.system-card{
    background:#fff;
    border:1px solid var(--line);
    border-radius:10px;
    box-shadow:0 3px 12px rgba(30,50,80,.035);
    margin-bottom:18px;
    overflow:hidden;
}

.card-header-custom{
    padding:15px 16px;
    border-bottom:1px solid var(--line);
    font-weight:700;
    display:flex;
    align-items:center;
    gap:10px;
}

.card-header-custom i{
    color:var(--blue);
}

.filter-body{
    padding:15px 16px;
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
    border:1px solid #dfe5ef;
    border-radius:7px;
    font-size:13px;
}

.filter-btn{
    height:38px;
    width:100%;
    background:#172b4d;
    color:#fff;
    border:0;
    border-radius:7px;
}

.receipt-row{
    background:#f8fafc;
    border:1px solid #e5eaf1;
    border-radius:8px;
    padding:10px;
    margin-bottom:8px;
}

.receipt-row-number{
    width:32px;
    height:32px;
    border-radius:50%;
    background:#e9f0ff;
    color:#2169e8;
    display:flex;
    align-items:center;
    justify-content:center;
    font-size:12px;
    font-weight:800;
}

.receipt-total-box{
    background:#f0fff8;
    border:1px solid #ccefe0;
    border-radius:8px;
    padding:14px;
}

.receipt-total-label{
    color:#617087;
    font-size:11px;
    text-transform:uppercase;
    font-weight:700;
}

.receipt-total{
    font-size:22px;
    font-weight:800;
    color:#16ad6c;
}

.amount{
    font-weight:800;
    color:#16ad6c!important;
}

.ewt-column{
    font-weight:800;
    color:#c62828!important;
}

.status-paid{
    background:#dff8eb;
    color:#138a59;
    border:1px solid #bcebd6;
}

.status-unpaid{
    background:#ffe3e3;
    color:#c62828;
    border:1px solid #ffc4c4;
}

.status-partial{
    background:#fff1cf;
    color:#9a6900;
    border:1px solid #f5d98c;
}

.empty-row{
    height:105px;
    text-align:center;
    color:#7b8799;
}

.voucher-locked{
    background:#eef2f7!important;
    border:2px solid #09264b!important;
    color:#09264b!important;
    font-weight:900!important;
    font-size:15px!important;
    letter-spacing:.7px;
    cursor:not-allowed!important;
}

.locked-badge{
    background:#09264b;
    color:#fff;
    font-size:9px;
    padding:4px 7px;
    border-radius:4px;
    font-weight:800;
}

.document-type-select{
    height:42px!important;
    border:2px solid #2169e8!important;
    background:#f4f8ff!important;
    color:#09264b!important;
    font-weight:900!important;
    font-size:15px!important;
}

.document-type-help{
    font-size:10px;
    color:#69788e;
    margin-top:4px;
}

#voucherPreviewModal .modal-dialog{
    max-width:950px;
}

#voucherPrintArea{
    background:#fff;
    color:#000;
    padding:10px;
}

.voucher-copy{
    border:1px solid #222;
    padding:18px 22px;
    margin-bottom:12px;
    min-height:390px;
}

.voucher-header{
    text-align:center;
    border-bottom:1px solid #222;
    padding-bottom:8px;
    margin-bottom:10px;
}

.company-name{
    font-size:19px;
    font-weight:900;
    letter-spacing:.5px;
}

.voucher-title{
    font-size:17px;
    font-weight:900;
    margin-top:4px;
}

.voucher-copy-label{
    font-size:10px;
    font-weight:800;
    letter-spacing:1px;
    margin-top:3px;
}

.voucher-info{
    width:100%;
    border-collapse:collapse;
    margin-top:5px;
    font-size:12px;
}

.voucher-info td{
    padding:4px 5px;
}

.voucher-info .label{
    width:15%;
    font-weight:700;
}

.voucher-info .value{
    border-bottom:1px solid #777;
}

.voucher-number-print{
    font-weight:900;
    font-size:14px;
    letter-spacing:.7px;
}

.document-type-print{
    display:inline-block;
    border:2px solid #000;
    padding:3px 9px;
    font-weight:900;
    font-size:13px;
    letter-spacing:1px;
}

.voucher-table{
    width:100%;
    border-collapse:collapse;
    margin-top:10px;
    font-size:11px;
}

.voucher-table th,
.voucher-table td{
    border:1px solid #555;
    padding:5px 6px;
}

.voucher-table th{
    background:#f2f2f2;
    text-align:center;
}

.voucher-table .right{
    text-align:right;
}

.voucher-total{
    text-align:right;
    font-size:15px;
    font-weight:900;
    margin-top:8px;
}

.tax-computation{
    width:100%;
    margin-top:10px;
    border:1px solid #555;
    border-collapse:collapse;
    font-size:11px;
}

.tax-computation td{
    padding:5px 7px;
    border-bottom:1px solid #ddd;
}

.tax-computation tr:last-child td{
    border-bottom:0;
}

.tax-computation .tax-label{
    font-weight:800;
}

.tax-computation .tax-value{
    text-align:right;
    font-weight:900;
}

.tax-computation .tax-main{
    background:#f5f5f5;
}

.tax-computation .tax-one-percent{
    font-size:13px;
}

.tax-computation .total-to-pay{
    background:#eafaf3;
    font-size:14px;
    border-top:2px solid #222;
}

.tax-computation .total-to-pay .tax-value{
    font-size:16px;
}

.voucher-signatures{
    display:flex;
    justify-content:space-between;
    gap:30px;
    margin-top:28px;
}

.signature-box{
    width:31%;
    text-align:center;
    font-size:11px;
}

.signature-line{
    border-bottom:1px solid #222;
    height:24px;
    margin-bottom:4px;
}

.receipt-date-input{
    height:38px!important;
    font-size:13px!important;
}

@media print{

    body *{
        visibility:hidden;
    }

    #voucherPrintArea,
    #voucherPrintArea *{
        visibility:visible;
    }

    #voucherPrintArea{
        position:absolute;
        left:0;
        top:0;
        width:100%;
        padding:8mm;
    }

    .voucher-copy{
        min-height:0;
        margin-bottom:5mm;
        page-break-inside:avoid;
    }

    @page{
        size:A4 portrait;
        margin:5mm;
    }
}

@media(max-width:1000px){

    .page-heading{
        align-items:flex-start;
        gap:15px;
    }

    .page-actions{
        flex-wrap:wrap;
    }
}

</style>


<div class="page-container">


<!-- =========================================================
     PAGE HEADER
========================================================= -->

<div class="page-heading">

    <div>

        <h2>Purchases</h2>

        <p>
            Record and monitor purchases and receipts
        </p>

    </div>


    <div class="page-actions">

        <a
            href="print_purchase.php?branch=<?=urlencode($currentBranch)?>&from=<?=urlencode($from)?>&to=<?=urlencode($to)?>&supplier=<?=urlencode($supplierFilter)?>&invoice_no=<?=urlencode($invoiceFilter)?>&voucher_no=<?=urlencode($voucherFilter)?>&document_type=<?=urlencode($documentTypeFilter)?>&payment_method=<?=urlencode($paymentFilter)?>&status=<?=urlencode($statusFilter)?>"
            target="_blank"
            class="btn-print-purchase"
        >
            <i class="fa-solid fa-print"></i>
            Print Purchase
        </a>


        <button
            type="button"
            class="btn btn-add"
            data-bs-toggle="modal"
            data-bs-target="#purchaseModal"
            onclick="prepareAdd()"
        >
            <i class="fa-solid fa-plus me-1"></i>
            Add Purchase
        </button>

    </div>

</div>


<!-- =========================================================
     ALERTS
========================================================= -->

<?php if(isset($_GET['saved'])): ?>

<div class="alert alert-success">

    <i class="fa-solid fa-check-circle me-2"></i>

    <?=h($_GET['saved'])?>
    purchase receipt(s) successfully saved.

    <?php if(!empty($_GET['voucher'])): ?>

        <strong>
            Voucher No.:
            <?=h($_GET['voucher'])?>
        </strong>

    <?php endif; ?>

</div>

<?php endif; ?>


<?php if(isset($_GET['updated'])): ?>

<div class="alert alert-success">

    <i class="fa-solid fa-check-circle me-2"></i>

    Purchase successfully updated.

    <?php if(!empty($_GET['voucher'])): ?>

        <strong>
            Voucher No.:
            <?=h($_GET['voucher'])?>
        </strong>

    <?php endif; ?>

</div>

<?php endif; ?>


<?php if(isset($_GET['deleted'])): ?>

<div class="alert alert-danger">

    <i class="fa-solid fa-trash me-2"></i>

    Purchase deleted successfully.

</div>

<?php endif; ?>


<?php if(isset($_GET['error'])): ?>

<div class="alert alert-danger">

    <i class="fa-solid fa-triangle-exclamation me-2"></i>

    <?=h($_GET['error'])?>

</div>

<?php endif; ?>


<!-- =========================================================
     SUMMARY
========================================================= -->

<div class="row g-3 mb-3">

    <div class="col-xl-3 col-md-6">

        <div class="summary-card">

            <div class="summary-label">
                Total Purchases
            </div>

            <div class="summary-value">
                ₱<?=number_format($totalPurchases,2)?>
            </div>

        </div>

    </div>


    <div class="col-xl-3 col-md-6">

        <div class="summary-card">

            <div class="summary-label">
                Paid
            </div>

            <div class="summary-value summary-paid">
                ₱<?=number_format($totalPaid,2)?>
            </div>

        </div>

    </div>


    <div class="col-xl-3 col-md-6">

        <div class="summary-card">

            <div class="summary-label">
                Unpaid
            </div>

            <div class="summary-value summary-unpaid">
                ₱<?=number_format($totalUnpaid,2)?>
            </div>

        </div>

    </div>


    <div class="col-xl-3 col-md-6">

        <div class="summary-card">

            <div class="summary-label">
                EWT 1%
            </div>

            <div class="summary-value summary-ewt">
                ₱<?=number_format($totalEwt,2)?>
            </div>

        </div>

    </div>

</div>


<div class="row g-3 mb-3">

    <div class="col-xl-3 col-md-6">

        <div class="summary-card">

            <div class="summary-label">
                Partial
            </div>

            <div class="summary-value summary-partial">
                ₱<?=number_format($totalPartial,2)?>
            </div>

        </div>

    </div>


    <div class="col-xl-3 col-md-6">

        <div class="summary-card">

            <div class="summary-label">
                VATable Purchases
            </div>

            <div class="summary-value">
                ₱<?=number_format($totalVatable,2)?>
            </div>

        </div>

    </div>

</div>


<!-- =========================================================
     FILTER
========================================================= -->

<div class="system-card">

    <div class="card-header-custom">

        <i class="fa-solid fa-filter"></i>

        Search &amp; Filter Purchases

    </div>


    <div class="filter-body">

        <form method="GET">

            <input
                type="hidden"
                name="branch"
                value="<?=$currentBranch?>"
            >


            <div class="row g-2">

                <div class="col-lg-2">

                    <label class="form-label-custom">
                        From
                    </label>

                    <input
                        type="date"
                        name="from"
                        value="<?=h($from)?>"
                        class="form-control"
                    >

                </div>


                <div class="col-lg-2">

                    <label class="form-label-custom">
                        To
                    </label>

                    <input
                        type="date"
                        name="to"
                        value="<?=h($to)?>"
                        class="form-control"
                    >

                </div>


                <div class="col-lg-2">

                    <label class="form-label-custom">
                        Supplier
                    </label>

                    <input
                        type="text"
                        name="supplier"
                        value="<?=h($supplierFilter)?>"
                        class="form-control"
                        placeholder="Supplier"
                    >

                </div>


                <div class="col-lg-2">

                    <label class="form-label-custom">
                        Receipt No.
                    </label>

                    <input
                        type="text"
                        name="invoice_no"
                        value="<?=h($invoiceFilter)?>"
                        class="form-control"
                        placeholder="Receipt no."
                    >

                </div>


                <div class="col-lg-2">

                    <label class="form-label-custom">
                        Voucher No.
                    </label>

                    <input
                        type="text"
                        name="voucher_no"
                        value="<?=h($voucherFilter)?>"
                        class="form-control"
                        placeholder="PVR-000001"
                    >

                </div>


                <div class="col-lg-2">

                    <label class="form-label-custom">
                        Document Type
                    </label>

                    <select
                        name="document_type"
                        class="form-select"
                    >

                        <option value="">
                            All Documents
                        </option>

                        <option
                            value="SI"
                            <?=$documentTypeFilter === 'SI'
                                ? 'selected'
                                : ''?>
                        >
                            SI — Sales Invoice
                        </option>

                        <option
                            value="DR"
                            <?=$documentTypeFilter === 'DR'
                                ? 'selected'
                                : ''?>
                        >
                            DR — Delivery Receipt
                        </option>

                    </select>

                </div>


                <div class="col-lg-2">

                    <label class="form-label-custom">
                        Payment
                    </label>

                    <select
                        name="payment_method"
                        class="form-select"
                    >

                        <option value="">
                            All
                        </option>

                        <?php foreach(
                            ['Cash','Bank','Credit','Other']
                            as $pm
                        ): ?>

                        <option
                            value="<?=$pm?>"
                            <?=$paymentFilter === $pm
                                ? 'selected'
                                : ''?>
                        >
                            <?=h($pm)?>
                        </option>

                        <?php endforeach; ?>

                    </select>

                </div>


                <div class="col-lg-2">

                    <label class="form-label-custom">
                        Status
                    </label>

                    <select
                        name="status"
                        class="form-select"
                    >

                        <option value="">
                            All Status
                        </option>

                        <?php foreach(
                            ['Paid','Unpaid','Partial']
                            as $st
                        ): ?>

                        <option
                            value="<?=$st?>"
                            <?=$statusFilter === $st
                                ? 'selected'
                                : ''?>
                        >
                            <?=h($st)?>
                        </option>

                        <?php endforeach; ?>

                    </select>

                </div>


                <div class="col-lg-2">

                    <label class="form-label-custom">
                        &nbsp;
                    </label>

                    <button
                        type="submit"
                        class="filter-btn"
                    >
                        <i class="fa-solid fa-search me-1"></i>
                        Filter
                    </button>

                </div>


                <div class="col-lg-2">

                    <label class="form-label-custom">
                        &nbsp;
                    </label>

                    <a
                        href="purchases.php?branch=<?=$currentBranch?>"
                        class="btn btn-light border w-100"
                        style="height:38px"
                    >
                        <i class="fa-solid fa-rotate-left me-1"></i>
                        Reset
                    </a>

                </div>

            </div>

        </form>

    </div>

</div>


<!-- =========================================================
     PURCHASE RECORDS
========================================================= -->

<div class="system-card">

    <div class="card-header-custom">

        <i class="fa-solid fa-bag-shopping"></i>

        Purchase Records

        <span
            class="text-muted"
            style="font-size:11px"
        >
            <?=count($purchases)?> record(s)
        </span>


        <div class="ms-auto">

            <strong
                style="color:#16ad6c;font-size:16px"
            >
                ₱<?=number_format($totalPurchases,2)?>
            </strong>

        </div>

    </div>


    <div class="table-responsive">

        <table class="table table-hover mb-0">

            <thead>

                <tr>

                    <th>Date</th>
                    <th>Voucher No.</th>
                    <th>Document</th>
                    <th>Branch</th>
                    <th>Supplier</th>
                    <th>Receipt No.</th>
                    <th>Amount</th>
                    <th>VATable</th>
                    <th>EWT</th>
                    <th>Payment</th>
                    <th>Status</th>
                    <th>Notes</th>
                    <th>Action</th>

                </tr>

            </thead>


            <tbody>

            <?php if(!$purchases): ?>

                <tr>

                    <td
                        colspan="13"
                        class="empty-row"
                    >

                        <i
                            class="fa-solid fa-inbox mb-2"
                            style="
                                font-size:28px;
                                display:block
                            "
                        ></i>

                        No purchases found.

                    </td>

                </tr>

            <?php endif; ?>


            <?php foreach(
                $purchases as $row
            ): ?>

            <tr>

                <td>
                    <?=h($row['purchase_date'])?>
                </td>


                <td>

                    <strong style="color:#09264b">

                        <?=h(
                            $row['voucher_no']
                            ?? '—'
                        )?>

                    </strong>

                </td>


                <td>

                    <?php

                    $rowDocument =
                        strtoupper(
                            trim(
                                (string)(
                                    $row['document_type']
                                    ?? 'SI'
                                )
                            )
                        );

                    $documentBadgeClass =
                        $rowDocument === 'DR'
                            ? 'bg-warning text-dark'
                            : 'bg-primary';

                    ?>

                    <span
                        class="badge <?=$documentBadgeClass?>"
                    >
                        <?=h($rowDocument)?>
                    </span>

                </td>


                <td>

                    <span
                        class="badge bg-light text-dark border"
                    >
                        <?=h(
                            $row['branch_name']
                            ?? 'All Branches'
                        )?>
                    </span>

                </td>


                <td>

                    <strong>
                        <?=h($row['supplier'])?>
                    </strong>

                </td>


                <td>
                    <?=h(
                        $row['invoice_no']
                        ?? ''
                    )?>
                </td>


                <td class="amount">

                    ₱<?=number_format(
                        (float)(
                            $row['total_amount']
                            ?? 0
                        ),
                        2
                    )?>

                </td>


                <td>

                    <?php if(
                        $rowDocument === 'SI'
                    ): ?>

                        ₱<?=number_format(
                            (float)(
                                $row['vatable_amount']
                                ?? 0
                            ),
                            2
                        )?>

                    <?php else: ?>

                        —

                    <?php endif; ?>

                </td>


                <td class="ewt-column">

                    <?php if(
                        $rowDocument === 'SI'
                    ): ?>

                        ₱<?=number_format(
                            (float)(
                                $row['ewt_amount']
                                ?? 0
                            ),
                            2
                        )?>

                    <?php else: ?>

                        —

                    <?php endif; ?>

                </td>


                <td>

                    <span
                        class="badge bg-light text-dark border"
                    >
                        <?=h(
                            $row['payment_method']
                            ?? ''
                        )?>
                    </span>

                </td>


                <td>

                    <?php

                    $rowStatus =
                        $row['status']
                        ?? 'Unpaid';

                    $statusClass =
                        match($rowStatus) {

                            'Paid'
                                => 'status-paid',

                            'Partial'
                                => 'status-partial',

                            default
                                => 'status-unpaid'
                        };

                    ?>

                    <span
                        class="badge <?=$statusClass?>"
                    >
                        <?=h($rowStatus)?>
                    </span>

                </td>


                <td>

                    <?php

                    $noteText =
                        trim(
                            (string)(
                                $row['notes']
                                ?? ''
                            )
                        );

                    ?>

                    <?php if(
                        $noteText !== ''
                    ): ?>

                        <span
                            title="<?=h($noteText)?>"
                            style="
                                display:inline-block;
                                max-width:180px;
                                overflow:hidden;
                                text-overflow:ellipsis;
                                white-space:nowrap
                            "
                        >
                            <?=h($noteText)?>
                        </span>

                    <?php else: ?>

                        <span class="text-muted">
                            —
                        </span>

                    <?php endif; ?>

                </td>


                <td style="white-space:nowrap">

                    <a
                        href="purchases.php?edit=<?=$row['id']?>&branch=<?=$currentBranch?>"
                        class="btn btn-sm btn-outline-primary"
                        title="Edit"
                    >
                        <i class="fa-solid fa-pen"></i>
                    </a>


                    <a
                        href="purchases.php?delete=<?=$row['id']?>&branch=<?=$currentBranch?>"
                        class="btn btn-sm btn-outline-danger"
                        title="Delete"
                        onclick="
                            return confirm(
                                'Delete this purchase?'
                            );
                        "
                    >
                        <i class="fa-solid fa-trash"></i>
                    </a>

                </td>

            </tr>

            <?php endforeach; ?>

            </tbody>

        </table>

    </div>

</div>

</div>


<!-- =========================================================
     PURCHASE MODAL
========================================================= -->

<div
    class="modal fade"
    id="purchaseModal"
    tabindex="-1"
>

<div class="modal-dialog modal-lg">

<div class="modal-content">

<form
    method="POST"
    id="purchaseForm"
    onsubmit="return showVoucherPreview(event)"
>

<input
    type="hidden"
    name="purchase_id"
    id="purchase_id"
    value="<?=h(
        $editPurchase['id']
        ?? ''
    )?>"
>


<input
    type="hidden"
    name="voucher_no"
    id="voucher_no"
    value="<?=h($nextVoucherNo)?>"
>


<div class="modal-header">

    <h5 class="modal-title">

        <i
            class="fa-solid fa-receipt text-primary me-2"
        ></i>

        <span id="modalTitle">
            <?=$editPurchase
                ? 'Edit Purchase'
                : 'Add Purchase'?>
        </span>

    </h5>


    <button
        type="button"
        class="btn-close"
        data-bs-dismiss="modal"
    ></button>

</div>


<div class="modal-body">

<div class="row g-3 mb-3">


<div class="col-md-4">

    <label class="form-label">

        Voucher No.

        <span class="locked-badge ms-1">
            🔒 LOCKED
        </span>

    </label>


    <input
        type="text"
        id="voucher_no_display"
        class="form-control voucher-locked"
        value="<?=h($nextVoucherNo)?>"
        readonly
        tabindex="-1"
    >


    <div
        class="text-muted mt-1"
        style="font-size:10px"
    >
        Automatic voucher series.
        Cannot be manually changed.
    </div>

</div>


<div class="col-md-4">

    <label class="form-label">

        Document Type

        <span class="badge bg-primary ms-1">
            SI / DR
        </span>

    </label>


    <select
        name="document_type"
        id="document_type"
        class="form-select document-type-select"
        onchange="calculateGrandTotal()"
    >

        <option
            value="SI"
            <?=
                (
                    ($editPurchase['document_type']
                    ?? 'SI') === 'SI'
                )
                    ? 'selected'
                    : ''
            ?>
        >
            SI — Sales Invoice
        </option>


        <option
            value="DR"
            <?=
                (
                    ($editPurchase['document_type']
                    ?? 'SI') === 'DR'
                )
                    ? 'selected'
                    : ''
            ?>
        >
            DR — Delivery Receipt
        </option>

    </select>


    <div class="document-type-help">

        <span id="documentTaxHelp">
            SI = VAT + EWT computation.
        </span>

    </div>

</div>


<div class="col-md-4">

    <label class="form-label">
        Supplier
    </label>


    <input
        type="text"
        name="supplier"
        id="supplier"
        class="form-control"
        value="<?=h(
            $editPurchase['supplier']
            ?? ''
        )?>"
        placeholder="Example: Rodina"
        required
    >

</div>


<div class="col-md-6">

    <label class="form-label">
        Branch
    </label>


    <select
        name="branch_id"
        id="branch_id"
        class="form-select"
        required
    >

        <?php foreach(
            $branches as $b
        ): ?>

        <option
            value="<?=$b['id']?>"
            <?=
                (
                    (int)(
                        $editPurchase['branch_id']
                        ?? $currentBranch
                    )
                    ===
                    (int)$b['id']
                )
                    ? 'selected'
                    : ''
            ?>
        >
            <?=h($b['branch_name'])?>
        </option>

        <?php endforeach; ?>

    </select>

</div>


<div class="col-md-3">

    <label class="form-label">
        Payment Method
    </label>


    <select
        name="payment_method"
        id="payment_method"
        class="form-select"
    >

        <?php foreach(
            ['Cash','Bank','Credit','Other']
            as $pm
        ): ?>

        <option
            value="<?=$pm?>"
            <?=
                (
                    $editPurchase['payment_method']
                    ?? 'Cash'
                ) === $pm
                    ? 'selected'
                    : ''
            ?>
        >
            <?=h($pm)?>
        </option>

        <?php endforeach; ?>

    </select>

</div>


<div class="col-md-3">

    <label class="form-label">
        Status
    </label>


    <select
        name="status"
        id="status"
        class="form-select"
    >

        <?php foreach(
            ['Paid','Unpaid','Partial']
            as $st
        ): ?>

        <option
            value="<?=$st?>"
            <?=
                (
                    $editPurchase['status']
                    ?? 'Paid'
                ) === $st
                    ? 'selected'
                    : ''
            ?>
        >
            <?=h($st)?>
        </option>

        <?php endforeach; ?>

    </select>

</div>


<div class="col-md-6">

    <label class="form-label">
        Received By
    </label>


    <input
        type="text"
        name="received_by"
        id="received_by"
        class="form-control"
        value=""
        placeholder="Name of receiver"
        required
    >

</div>


<div class="col-md-6">

    <div
        class="alert alert-light border mb-0"
        style="font-size:12px"
    >

        <strong>Status:</strong>

        <span class="text-success">
            Paid
        </span>
        = fully paid

        &nbsp;|&nbsp;

        <span class="text-danger">
            Unpaid
        </span>
        = not yet paid

        &nbsp;|&nbsp;

        <span style="color:#a06d00">
            Partial
        </span>
        = partially paid

    </div>

</div>

</div>


<!-- =====================================================
     RECEIPTS
===================================================== -->

<div class="d-flex align-items-center justify-content-between mb-2">

    <div>

        <strong>
            Receipts
        </strong>

        <div
            class="text-muted"
            style="font-size:11px"
        >
            Bawat receipt ay may sariling date,
            receipt number at amount.
        </div>

    </div>


    <button
        type="button"
        class="btn btn-sm btn-success"
        onclick="addReceiptRow()"
    >
        <i class="fa-solid fa-plus me-1"></i>
        Add Row
    </button>

</div>


<div class="row g-2 mb-1 px-2">

    <div class="col-1">
        <small class="text-muted fw-bold">
            #
        </small>
    </div>

    <div class="col-4">
        <small class="text-muted fw-bold">
            RECEIPT NO.
        </small>
    </div>

    <div class="col-3">
        <small class="text-muted fw-bold">
            DATE
        </small>
    </div>

    <div class="col-3">
        <small class="text-muted fw-bold">
            AMOUNT
        </small>
    </div>

</div>


<div id="receiptRows">

<?php if($editPurchase): ?>

<div class="receipt-row">

<div class="row g-2 align-items-center">

    <div class="col-1">

        <div class="receipt-row-number">
            1
        </div>

    </div>


    <div class="col-4">

        <input
            type="text"
            name="receipt_no[]"
            class="form-control"
            value="<?=h(
                $editPurchase['invoice_no']
                ?? ''
            )?>"
            required
        >

    </div>


    <div class="col-3">

        <input
            type="date"
            name="receipt_date[]"
            class="form-control receipt-date-input"
            value="<?=h(
                $editPurchase['purchase_date']
                ?? date('Y-m-d')
            )?>"
            required
        >

    </div>


    <div class="col-3">

        <input
            type="number"
            name="amount[]"
            class="form-control amount-input"
            step="0.01"
            min="0.01"
            value="<?=h(
                $editPurchase['total_amount']
                ?? ''
            )?>"
            oninput="calculateGrandTotal()"
            required
        >

    </div>


    <div class="col-1">

        <button
            type="button"
            class="btn btn-sm btn-outline-danger"
            onclick="removeReceiptRow(this)"
        >
            <i class="fa-solid fa-trash"></i>
        </button>

    </div>

</div>

</div>


<?php else: ?>

<div class="receipt-row">

<div class="row g-2 align-items-center">

    <div class="col-1">

        <div class="receipt-row-number">
            1
        </div>

    </div>


    <div class="col-4">

        <input
            type="text"
            name="receipt_no[]"
            class="form-control"
            placeholder="Receipt No."
            required
        >

    </div>


    <div class="col-3">

        <input
            type="date"
            name="receipt_date[]"
            class="form-control receipt-date-input"
            value="<?=date('Y-m-d')?>"
            required
        >

    </div>


    <div class="col-3">

        <input
            type="number"
            name="amount[]"
            class="form-control amount-input"
            step="0.01"
            min="0.01"
            placeholder="0.00"
            oninput="calculateGrandTotal()"
            required
        >

    </div>


    <div class="col-1">

        <button
            type="button"
            class="btn btn-sm btn-outline-danger"
            onclick="removeReceiptRow(this)"
        >
            <i class="fa-solid fa-trash"></i>
        </button>

    </div>

</div>

</div>

<?php endif; ?>

</div>


<!-- =====================================================
     TOTAL
===================================================== -->

<div class="receipt-total-box mt-3">

<div class="d-flex justify-content-between align-items-center">

    <div>

        <div class="receipt-total-label">
            Total Receipts
        </div>

        <div
            id="receiptCount"
            class="text-muted"
            style="font-size:11px"
        >
            1 receipt
        </div>

    </div>


    <div class="text-end">

        <div class="receipt-total-label">
            Grand Total
        </div>

        <div
            id="grandTotal"
            class="receipt-total"
        >
            ₱0.00
        </div>

    </div>

</div>

</div>


<div class="mt-3">

    <label class="form-label">
        Notes
    </label>


    <textarea
        name="notes"
        id="notes"
        class="form-control"
        rows="2"
        placeholder="Optional notes..."
    ><?=h(
        $editPurchase['notes']
        ?? ''
    )?></textarea>

</div>

</div>


<div class="modal-footer">

    <button
        type="button"
        class="btn btn-secondary"
        data-bs-dismiss="modal"
    >
        Cancel
    </button>


    <button
        type="submit"
        class="btn btn-primary"
    >
        <i class="fa-solid fa-eye me-1"></i>
        Preview Voucher
    </button>

</div>

</form>

</div>

</div>

</div>


<!-- =========================================================
     VOUCHER PREVIEW
========================================================= -->

<div
    class="modal fade"
    id="voucherPreviewModal"
    tabindex="-1"
>

<div class="modal-dialog modal-xl">

<div class="modal-content">

<div class="modal-header">

    <h5 class="modal-title">

        <i class="fa-solid fa-file-invoice me-2"></i>

        Voucher Preview

    </h5>


    <button
        type="button"
        class="btn-close"
        data-bs-dismiss="modal"
    ></button>

</div>


<div class="modal-body">

    <div id="voucherPrintArea"></div>

</div>


<div class="modal-footer">

    <button
        type="button"
        class="btn btn-secondary"
        data-bs-dismiss="modal"
    >
        Back / Edit
    </button>


    <button
        type="button"
        class="btn btn-dark"
        onclick="printVoucher()"
    >
        <i class="fa-solid fa-print me-1"></i>
        Print Voucher
    </button>


    <button
        type="button"
        class="btn btn-success"
        onclick="confirmAndSave()"
    >
        <i class="fa-solid fa-check me-1"></i>
        Confirm &amp; Save
    </button>

</div>

</div>

</div>

</div>


<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>


<script>

/* =========================================================
   TODAY
========================================================= */

function getTodayDate(){

    const now = new Date();

    const year =
        now.getFullYear();

    const month =
        String(
            now.getMonth() + 1
        ).padStart(2, '0');

    const day =
        String(
            now.getDate()
        ).padStart(2, '0');

    return (
        year
        + '-'
        + month
        + '-'
        + day
    );
}


/* =========================================================
   DOCUMENT TAX HELP
========================================================= */

function updateDocumentTaxHelp(){

    const documentType =
        document.getElementById(
            'document_type'
        );

    const help =
        document.getElementById(
            'documentTaxHelp'
        );


    if(
        !documentType
        || !help
    ){
        return;
    }


    if(
        documentType.value === 'SI'
    ){

        help.textContent =
            'SI = VAT + EWT computation.';

    } else {

        help.textContent =
            'DR = No VAT and no EWT computation.';
    }
}


/* =========================================================
   ADD RECEIPT ROW
========================================================= */

function addReceiptRow(){

    const container =
        document.getElementById(
            'receiptRows'
        );


    if(!container){
        return;
    }


    const row =
        document.createElement('div');


    row.className =
        'receipt-row';


    row.innerHTML = `

        <div class="row g-2 align-items-center">

            <div class="col-1">

                <div class="receipt-row-number">
                    #
                </div>

            </div>


            <div class="col-4">

                <input
                    type="text"
                    name="receipt_no[]"
                    class="form-control"
                    placeholder="Receipt No."
                    required
                >

            </div>


            <div class="col-3">

                <input
                    type="date"
                    name="receipt_date[]"
                    class="form-control receipt-date-input"
                    value="${getTodayDate()}"
                    required
                >

            </div>


            <div class="col-3">

                <input
                    type="number"
                    name="amount[]"
                    class="form-control amount-input"
                    step="0.01"
                    min="0.01"
                    placeholder="0.00"
                    oninput="calculateGrandTotal()"
                    required
                >

            </div>


            <div class="col-1">

                <button
                    type="button"
                    class="btn btn-sm btn-outline-danger"
                    onclick="removeReceiptRow(this)"
                >
                    <i class="fa-solid fa-trash"></i>
                </button>

            </div>

        </div>

    `;


    container.appendChild(row);

    renumberRows();

    calculateGrandTotal();


    const input =
        row.querySelector(
            'input[name="receipt_no[]"]'
        );


    if(input){
        input.focus();
    }
}


/* =========================================================
   REMOVE RECEIPT ROW
========================================================= */

function removeReceiptRow(button){

    const container =
        document.getElementById(
            'receiptRows'
        );


    if(!container){
        return;
    }


    const rows =
        container.querySelectorAll(
            '.receipt-row'
        );


    if(rows.length <= 1){

        const receiptInput =
            rows[0].querySelector(
                'input[name="receipt_no[]"]'
            );


        const amountInput =
            rows[0].querySelector(
                'input[name="amount[]"]'
            );


        if(receiptInput){
            receiptInput.value = '';
        }


        if(amountInput){
            amountInput.value = '';
        }


        calculateGrandTotal();

        return;
    }


    const row =
        button.closest(
            '.receipt-row'
        );


    if(row){
        row.remove();
    }


    renumberRows();

    calculateGrandTotal();
}


/* =========================================================
   RENUMBER
========================================================= */

function renumberRows(){

    const rows =
        document.querySelectorAll(
            '#receiptRows .receipt-row'
        );


    rows.forEach(
        function(row, index){

            const number =
                row.querySelector(
                    '.receipt-row-number'
                );


            if(number){
                number.textContent =
                    index + 1;
            }

        }
    );


    const receiptCount =
        document.getElementById(
            'receiptCount'
        );


    if(receiptCount){

        receiptCount.textContent =
            rows.length
            + (
                rows.length === 1
                    ? ' receipt'
                    : ' receipts'
            );
    }
}


/* =========================================================
   CALCULATE GRAND TOTAL
========================================================= */

function calculateGrandTotal(){

    const inputs =
        document.querySelectorAll(
            '#receiptRows .amount-input'
        );


    let total = 0;


    inputs.forEach(
        function(input){

            total +=
                parseFloat(
                    input.value
                ) || 0;

        }
    );


    const grandTotal =
        document.getElementById(
            'grandTotal'
        );


    if(grandTotal){

        grandTotal.textContent =
            '₱'
            + total.toLocaleString(
                'en-PH',
                {
                    minimumFractionDigits:2,
                    maximumFractionDigits:2
                }
            );
    }


    updateDocumentTaxHelp();

    renumberRows();
}


/* =========================================================
   NUMBER TO WORDS
========================================================= */

function numberToWords(num){

    num =
        parseFloat(num) || 0;


    if(num === 0){
        return 'ZERO PESOS ONLY';
    }


    const ones = [
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


    const tens = [
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


    function convert(n){

        if(n < 20){
            return ones[n];
        }


        if(n < 100){

            return tens[
                Math.floor(n / 10)
            ]
            + (
                n % 10
                    ? ' ' + ones[n % 10]
                    : ''
            );
        }


        if(n < 1000){

            return ones[
                Math.floor(n / 100)
            ]
            + ' HUNDRED'
            + (
                n % 100
                    ? ' ' + convert(n % 100)
                    : ''
            );
        }


        if(n < 1000000){

            return convert(
                Math.floor(n / 1000)
            )
            + ' THOUSAND'
            + (
                n % 1000
                    ? ' ' + convert(n % 1000)
                    : ''
            );
        }


        if(n < 1000000000){

            return convert(
                Math.floor(n / 1000000)
            )
            + ' MILLION'
            + (
                n % 1000000
                    ? ' ' + convert(n % 1000000)
                    : ''
            );
        }


        return convert(
            Math.floor(n / 1000000000)
        )
        + ' BILLION'
        + (
            n % 1000000000
                ? ' ' + convert(n % 1000000000)
                : ''
        );
    }


    const pesos =
        Math.floor(num);


    const cents =
        Math.round(
            (num - pesos) * 100
        );


    let result =
        convert(pesos)
        + ' PESOS';


    result +=
        cents > 0
            ? ' AND '
                + String(cents)
                    .padStart(2, '0')
                + '/100'
            : ' ONLY';


    return result;
}


/* =========================================================
   ESCAPE HTML
========================================================= */

function escapeHtml(value){

    return String(
        value ?? ''
    )
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;');
}


/* =========================================================
   FORMAT MONEY
========================================================= */

function formatMoney(value){

    return '₱'
        + Number(
            value || 0
        ).toLocaleString(
            'en-PH',
            {
                minimumFractionDigits:2,
                maximumFractionDigits:2
            }
        );
}


/* =========================================================
   PRINT DATE
========================================================= */

function formatPrintDate(value){

    if(!value){
        return '';
    }


    const parts =
        value.split('-');


    if(parts.length !== 3){
        return value;
    }


    return (
        parts[1]
        + '/'
        + parts[2]
        + '/'
        + parts[0]
    );
}


/* =========================================================
   GET VOUCHER DATA
========================================================= */

function getVoucherData(){

    const branch =
        document.getElementById(
            'branch_id'
        );


    const documentTypeElement =
        document.getElementById(
            'document_type'
        );


    const rows = [];


    document
        .querySelectorAll(
            '#receiptRows .receipt-row'
        )
        .forEach(
            function(row){

                const receiptInput =
                    row.querySelector(
                        'input[name="receipt_no[]"]'
                    );


                const dateInput =
                    row.querySelector(
                        'input[name="receipt_date[]"]'
                    );


                const amountInput =
                    row.querySelector(
                        'input[name="amount[]"]'
                    );


                const receipt =
                    receiptInput
                        ? receiptInput.value.trim()
                        : '';


                const receiptDate =
                    dateInput
                        ? dateInput.value
                        : '';


                const amount =
                    amountInput
                        ? parseFloat(
                            amountInput.value
                        ) || 0
                        : 0;


                if(
                    receipt !== ''
                    && receiptDate !== ''
                    && amount > 0
                ){

                    rows.push({
                        receipt: receipt,
                        date: receiptDate,
                        amount: amount
                    });
                }
            }
        );


    let total = 0;


    rows.forEach(
        function(row){
            total += row.amount;
        }
    );


    const documentType =
        documentTypeElement
            ? documentTypeElement.value
                .trim()
                .toUpperCase()
            : 'SI';


    let vatableAmount = 0;

    let withholdingOnePercent = 0;

    let totalToPay = total;


    if(documentType === 'SI'){

        vatableAmount =
            total / 1.12;

        withholdingOnePercent =
            vatableAmount * 0.01;

        totalToPay =
            total - withholdingOnePercent;

    } else {

        vatableAmount = 0;

        withholdingOnePercent = 0;

        totalToPay = total;
    }


    return {

        voucherNo:
            document
                .getElementById(
                    'voucher_no'
                )
                .value
                .trim(),

        documentType:

            documentType,

        supplier:
            document
                .getElementById(
                    'supplier'
                )
                .value
                .trim(),

        branch:
            branch
                ? (
                    branch.options[
                        branch.selectedIndex
                    ]?.text || ''
                )
                : '',

        payment:
            document
                .getElementById(
                    'payment_method'
                )
                .value,

        status:
            document
                .getElementById(
                    'status'
                )
                .value,

        receivedBy:
            document
                .getElementById(
                    'received_by'
                )
                .value
                .trim(),

        notes:
            document
                .getElementById(
                    'notes'
                )
                .value
                .trim(),

        rows: rows,

        total: total,

        vatableAmount:
            vatableAmount,

        withholdingOnePercent:
            withholdingOnePercent,

        totalToPay:
            totalToPay
    };
}


/* =========================================================
   BUILD VOUCHER
========================================================= */

function buildVoucher(
    data,
    copyLabel
){

    let rowsHtml = '';


    data.rows.forEach(
        function(row, index){

            rowsHtml += `

                <tr>

                    <td style="text-align:center">
                        ${index + 1}
                    </td>

                    <td>
                        ${escapeHtml(
                            row.receipt
                        )}
                    </td>

                    <td style="text-align:center">
                        ${escapeHtml(
                            formatPrintDate(
                                row.date
                            )
                        )}
                    </td>

                    <td class="right">
                        ${formatMoney(
                            row.amount
                        )}
                    </td>

                </tr>

            `;
        }
    );


    let statusClass =
        data.status === 'Paid'
            ? 'color:#138a59;'
            : data.status === 'Unpaid'
                ? 'color:#c62828;'
                : 'color:#9a6900;';


    const documentType =
        data.documentType === 'DR'
            ? 'DR'
            : 'SI';


    let taxComputationHtml = '';


    if(documentType === 'SI'){

        taxComputationHtml = `

            <table class="tax-computation">

                <tr class="tax-main">

                    <td class="tax-label">

                        VATABLE AMOUNT
                        (TOTAL ÷ 1.12)

                    </td>

                    <td class="tax-value">

                        ${formatMoney(
                            data.vatableAmount
                        )}

                    </td>

                </tr>


                <tr>

                    <td class="tax-label">

                        1% EWT
                        (VATABLE AMOUNT × 1%)

                    </td>

                    <td
                        class="tax-value tax-one-percent"
                    >

                        ${formatMoney(
                            data.withholdingOnePercent
                        )}

                    </td>

                </tr>


                <tr class="total-to-pay">

                    <td class="tax-label">

                        TOTAL TO PAY
                        (TOTAL RECEIPT − EWT)

                    </td>

                    <td class="tax-value">

                        ${formatMoney(
                            data.totalToPay
                        )}

                    </td>

                </tr>

            </table>

        `;

    } else {

        taxComputationHtml = `

            <table class="tax-computation">

                <tr class="total-to-pay">

                    <td class="tax-label">

                        TOTAL TO PAY

                    </td>

                    <td class="tax-value">

                        ${formatMoney(
                            data.total
                        )}

                    </td>

                </tr>

            </table>

        `;
    }


    return `

        <div class="voucher-copy">

            <div class="voucher-header">

                <div class="company-name">
                    VERDIVIEW RESTAURANT INC.
                </div>

                <div class="voucher-title">
                    DISBURSEMENT VOUCHER
                </div>

                <div class="voucher-copy-label">
                    ${escapeHtml(copyLabel)}
                </div>

            </div>


            <table class="voucher-info">

                <tr>

                    <td class="label">
                        Voucher No.
                    </td>

                    <td
                        class="value voucher-number-print"
                    >
                        ${escapeHtml(
                            data.voucherNo
                        )}
                    </td>


                    <td class="label">
                        Document
                    </td>

                    <td class="value">

                        <span class="document-type-print">

                            ${escapeHtml(
                                documentType
                            )}

                        </span>

                    </td>

                </tr>


                <tr>

                    <td class="label">
                        Status
                    </td>

                    <td
                        class="value"
                        style="
                            font-weight:900;
                            ${statusClass}
                        "
                    >
                        ${escapeHtml(
                            data.status
                        )}
                    </td>


                    <td class="label">
                        Branch
                    </td>

                    <td class="value">

                        ${escapeHtml(
                            data.branch
                        )}

                    </td>

                </tr>


                <tr>

                    <td class="label">
                        Supplier
                    </td>

                    <td
                        class="value"
                        colspan="3"
                    >

                        ${escapeHtml(
                            data.supplier
                        )}

                    </td>

                </tr>


                <tr>

                    <td class="label">
                        Payment
                    </td>

                    <td class="value">

                        ${escapeHtml(
                            data.payment
                        )}

                    </td>


                    <td class="label">
                        Received By
                    </td>

                    <td class="value">

                        ${escapeHtml(
                            data.receivedBy
                        )}

                    </td>

                </tr>

            </table>


            <table class="voucher-table">

                <thead>

                    <tr>

                        <th style="width:8%">
                            #
                        </th>

                        <th>
                            RECEIPT NO.
                        </th>

                        <th style="width:20%">
                            DATE
                        </th>

                        <th style="width:25%">
                            AMOUNT
                        </th>

                    </tr>

                </thead>


                <tbody>

                    ${rowsHtml}


                    <tr>

                        <td
                            colspan="3"
                            style="
                                text-align:right;
                                font-weight:900
                            "
                        >
                            TOTAL RECEIPT
                        </td>


                        <td
                            class="right"
                            style="font-weight:900"
                        >
                            ${formatMoney(
                                data.total
                            )}
                        </td>

                    </tr>

                </tbody>

            </table>


            ${taxComputationHtml}


            <div class="voucher-total">

                AMOUNT IN WORDS:

                <span
                    style="
                        font-size:11px;
                        font-weight:700
                    "
                >

                    ${numberToWords(
                        data.totalToPay
                    )}

                </span>

            </div>


            ${
                data.notes !== ''
                    ? `

                        <div
                            style="
                                margin-top:10px;
                                font-size:11px
                            "
                        >

                            <strong>
                                NOTES:
                            </strong>

                            ${escapeHtml(
                                data.notes
                            )}

                        </div>

                    `
                    : ''
            }


            <div class="voucher-signatures">

                <div class="signature-box">

                    <div class="signature-line"></div>

                    Prepared By

                </div>


                <div class="signature-box">

                    <div class="signature-line"></div>

                    Approved By

                </div>


                <div class="signature-box">

                    <div class="signature-line">

                        ${escapeHtml(
                            data.receivedBy
                        )}

                    </div>

                    Received By

                </div>

            </div>

        </div>

    `;
}


/* =========================================================
   PREVIEW
========================================================= */

function showVoucherPreview(event){

    event.preventDefault();


    const form =
        document.getElementById(
            'purchaseForm'
        );


    if(!form.checkValidity()){

        form.reportValidity();

        return false;
    }


    const data =
        getVoucherData();


    if(data.voucherNo === ''){

        alert(
            'Voucher number is missing.'
        );

        return false;
    }


    if(
        !/^PVR-\d{6}$/.test(
            data.voucherNo
        )
    ){

        alert(
            'Invalid voucher number.'
        );

        return false;
    }


    if(
        data.documentType !== 'SI'
        && data.documentType !== 'DR'
    ){

        alert(
            'Please select SI or DR.'
        );

        return false;
    }


    if(data.rows.length === 0){

        alert(
            'Please add at least one receipt.'
        );

        return false;
    }


    for(
        let i = 0;
        i < data.rows.length;
        i++
    ){

        if(!data.rows[i].date){

            alert(
                'Receipt date is required on row '
                + (i + 1)
                + '.'
            );

            return false;
        }
    }


    if(data.total <= 0){

        alert(
            'Total amount must be greater than zero.'
        );

        return false;
    }


    document.getElementById(
        'voucherPrintArea'
    ).innerHTML =

        buildVoucher(
            data,
            'ORIGINAL'
        )

        +

        buildVoucher(
            data,
            'DUPLICATE'
        );


    const purchaseModal =
        bootstrap.Modal.getInstance(
            document.getElementById(
                'purchaseModal'
            )
        );


    if(purchaseModal){
        purchaseModal.hide();
    }


    new bootstrap.Modal(
        document.getElementById(
            'voucherPreviewModal'
        )
    ).show();


    return false;
}


/* =========================================================
   CONFIRM & SAVE
========================================================= */

function confirmAndSave(){

    if(
        !confirm(
            'Confirm this voucher and save the purchase records?'
        )
    ){
        return;
    }


    const form =
        document.getElementById(
            'purchaseForm'
        );


    form.removeAttribute(
        'onsubmit'
    );


    let hidden =
        form.querySelector(
            'input[name="save_purchase"]'
        );


    if(!hidden){

        hidden =
            document.createElement(
                'input'
            );

        hidden.type =
            'hidden';

        hidden.name =
            'save_purchase';

        hidden.value =
            '1';

        form.appendChild(hidden);
    }


    form.submit();
}


/* =========================================================
   PRINT
========================================================= */

function printVoucher(){

    window.print();
}


/* =========================================================
   PREPARE ADD
========================================================= */

function prepareAdd(){

    const purchaseId =
        document.getElementById(
            'purchase_id'
        );


    const modalTitle =
        document.getElementById(
            'modalTitle'
        );


    const supplier =
        document.getElementById(
            'supplier'
        );


    const documentType =
        document.getElementById(
            'document_type'
        );


    const paymentMethod =
        document.getElementById(
            'payment_method'
        );


    const status =
        document.getElementById(
            'status'
        );


    const receivedBy =
        document.getElementById(
            'received_by'
        );


    const notes =
        document.getElementById(
            'notes'
        );


    const branch =
        document.getElementById(
            'branch_id'
        );


    const voucherHidden =
        document.getElementById(
            'voucher_no'
        );


    const voucherDisplay =
        document.getElementById(
            'voucher_no_display'
        );


    if(purchaseId){
        purchaseId.value = '';
    }


    if(modalTitle){
        modalTitle.textContent =
            'Add Purchase';
    }


    if(supplier){
        supplier.value = '';
    }


    if(documentType){
        documentType.value = 'SI';
    }


    if(paymentMethod){
        paymentMethod.value = 'Cash';
    }


    if(status){
        status.value = 'Paid';
    }


    if(receivedBy){
        receivedBy.value = '';
    }


    if(notes){
        notes.value = '';
    }


    if(branch){

        const currentBranch =
            <?=json_encode(
                (int)$currentBranch
            )?>;

        if(
            currentBranch > 0
        ){

            branch.value =
                String(currentBranch);
        }
    }


    /*
     * Get the current next voucher number
     * from PHP-generated value.
     *
     * This page refreshes after every save,
     * so the number remains sequential.
     */

    const voucherNo =
        <?=json_encode(
            getNextVoucherNumber($pdo)
        )?>;


    if(voucherHidden){
        voucherHidden.value =
            voucherNo;
    }


    if(voucherDisplay){
        voucherDisplay.value =
            voucherNo;
    }


    const receiptRows =
        document.getElementById(
            'receiptRows'
        );


    if(receiptRows){

        receiptRows.innerHTML = `

            <div class="receipt-row">

                <div class="row g-2 align-items-center">

                    <div class="col-1">

                        <div class="receipt-row-number">
                            1
                        </div>

                    </div>


                    <div class="col-4">

                        <input
                            type="text"
                            name="receipt_no[]"
                            class="form-control"
                            placeholder="Receipt No."
                            required
                        >

                    </div>


                    <div class="col-3">

                        <input
                            type="date"
                            name="receipt_date[]"
                            class="form-control receipt-date-input"
                            value="${getTodayDate()}"
                            required
                        >

                    </div>


                    <div class="col-3">

                        <input
                            type="number"
                            name="amount[]"
                            class="form-control amount-input"
                            step="0.01"
                            min="0.01"
                            placeholder="0.00"
                            oninput="calculateGrandTotal()"
                            required
                        >

                    </div>


                    <div class="col-1">

                        <button
                            type="button"
                            class="btn btn-sm btn-outline-danger"
                            onclick="removeReceiptRow(this)"
                        >
                            <i class="fa-solid fa-trash"></i>
                        </button>

                    </div>

                </div>

            </div>

        `;
    }


    calculateGrandTotal();

    updateDocumentTaxHelp();
}


/* =========================================================
   PAGE LOAD
========================================================= */

document.addEventListener(
    'DOMContentLoaded',
    function(){

        calculateGrandTotal();

        updateDocumentTaxHelp();

    }
);


/* =========================================================
   AUTO OPEN EDIT
========================================================= */

<?php if($editPurchase): ?>

document.addEventListener(
    'DOMContentLoaded',
    function(){

        const modalElement =
            document.getElementById(
                'purchaseModal'
            );


        if(!modalElement){
            return;
        }


        const documentType =
            document.getElementById(
                'document_type'
            );


        if(documentType){

            documentType.value =
                <?=json_encode(
                    $editPurchase['document_type']
                    ?? 'SI'
                )?>;
        }


        updateDocumentTaxHelp();

        calculateGrandTotal();


        new bootstrap.Modal(
            modalElement
        ).show();

    }
);

<?php endif; ?>

</script>


</main>


<?php include "footer.php"; ?>