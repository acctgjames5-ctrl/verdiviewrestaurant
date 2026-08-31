<?php
session_start();
require "config.php";

$pageTitle = "Expense Report";

/* =========================================================
   FILTERS
========================================================= */

$selectedBranch = (int)(
    $_GET['branch']
    ?? $_SESSION['branch_id']
    ?? 0
);

$from = trim($_GET['from'] ?? '');
$to   = trim($_GET['to'] ?? '');
$q    = trim($_GET['q'] ?? '');

/* =========================================================
   WHERE
========================================================= */

$where  = [];
$params = [];

/* BRANCH */
if ($selectedBranch > 0) {
    $where[] = "e.branch_id = ?";
    $params[] = $selectedBranch;
}

/* FROM DATE */
if ($from !== '') {
    $where[] = "e.expense_date >= ?";
    $params[] = $from;
}

/* TO DATE
   Since expense_date is normally DATE, <= selected date
   includes the entire selected day.
*/
if ($to !== '') {
    $where[] = "e.expense_date <= ?";
    $params[] = $to;
}

/* SEARCH */
if ($q !== '') {
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

    for ($i = 0; $i < 8; $i++) {
        $params[] = "%{$q}%";
    }
}

/* =========================================================
   GET FILTERED EXPENSES
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
    $sql .= " WHERE " . implode(" AND ", $where);
}

$sql .= "
    ORDER BY
        e.expense_date ASC,
        e.id ASC
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* =========================================================
   TOTAL
========================================================= */

$grandTotal = 0;

foreach ($rows as $r) {
    $grandTotal += (float)($r['amount'] ?? 0);
}

/* =========================================================
   BRANCH NAME
========================================================= */

$branchName = "All Branches";

if ($selectedBranch > 0) {
    $branchStmt = $pdo->prepare("
        SELECT branch_name
        FROM branches
        WHERE id = ?
        LIMIT 1
    ");

    $branchStmt->execute([$selectedBranch]);

    $branchName = $branchStmt->fetchColumn() ?: "All Branches";
}

/* =========================================================
   PERIOD LABEL
========================================================= */

if ($from !== '' && $to !== '') {
    $periodLabel = "Period: " . $from . " to " . $to;
} elseif ($from !== '') {
    $periodLabel = "From: " . $from;
} elseif ($to !== '') {
    $periodLabel = "Until: " . $to;
} else {
    $periodLabel = "All Dates";
}

function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Expense Report</title>

<style>
*{
    box-sizing:border-box;
}

html,
body{
    margin:0;
    padding:0;
    background:#fff;
    color:#111;
    font-family:Arial, Helvetica, sans-serif;
    font-size:11px;
}

body{
    padding:18px;
}

.report{
    width:100%;
    max-width:1500px;
    margin:0 auto;
}

.print-bar{
    display:flex;
    justify-content:flex-end;
    margin-bottom:15px;
}

.print-button{
    border:0;
    border-radius:5px;
    padding:9px 18px;
    background:#172642;
    color:#fff;
    font-weight:bold;
    cursor:pointer;
}

.report-header{
    text-align:center;
    border-bottom:2px solid #222;
    padding-bottom:10px;
    margin-bottom:12px;
}

.report-title{
    font-size:23px;
    font-weight:900;
    line-height:1.15;
}

.report-subtitle{
    margin-top:4px;
    font-size:12px;
    font-weight:700;
}

.report-period{
    margin-top:4px;
    font-size:11px;
}

.filter-info{
    display:flex;
    justify-content:space-between;
    gap:15px;
    margin-bottom:12px;
    font-size:10px;
}

.filter-left{
    display:flex;
    flex-wrap:wrap;
    gap:15px;
}

.summary{
    display:flex;
    gap:7px;
    margin-bottom:14px;
}

.summary-box{
    flex:1;
    border:1px solid #999;
    padding:7px;
    text-align:center;
}

.summary-label{
    font-size:8px;
    font-weight:800;
    text-transform:uppercase;
    color:#555;
}

.summary-value{
    margin-top:3px;
    font-size:12px;
    font-weight:900;
}

.summary-total{
    border:2px solid #222;
}

table{
    width:100%;
    border-collapse:collapse;
    table-layout:fixed;
}

th{
    background:#eee;
    border:1px solid #888;
    padding:6px 5px;
    font-size:8px;
    font-weight:900;
    text-transform:uppercase;
    text-align:center;
}

td{
    border:1px solid #aaa;
    padding:6px 5px;
    font-size:9px;
    vertical-align:middle;
    word-wrap:break-word;
}

.text-center{
    text-align:center;
}

.text-right{
    text-align:right;
}

.amount{
    text-align:right;
    white-space:nowrap;
    font-weight:700;
}

tfoot td{
    background:#f1f1f1;
    font-weight:900;
}

.grand-total td{
    background:#e8e8e8;
    border-top:2px solid #222;
    font-size:11px;
    font-weight:900;
}

.no-records{
    text-align:center;
    padding:25px;
    color:#666;
}

/* Column widths */
.col-no{width:3%}
.col-date{width:7%}
.col-branch{width:9%}
.col-ref{width:9%}
.col-doc{width:5%}
.col-sidr{width:8%}
.col-supplier{width:11%}
.col-category{width:9%}
.col-payment{width:9%}
.col-check{width:8%}
.col-description{width:14%}
.col-amount{width:8%}

@media print{

    @page{
        size:A4 landscape;
        margin:8mm;
    }

    html,
    body{
        width:100%;
        margin:0;
        padding:0;
        background:#fff;
        font-size:8px;
    }

    .print-bar{
        display:none !important;
    }

    .report{
        width:100%;
        max-width:none;
    }

    .report-header{
        margin-bottom:8px;
        padding-bottom:6px;
    }

    .report-title{
        font-size:17px;
    }

    .report-subtitle{
        font-size:9px;
    }

    .report-period{
        font-size:8px;
    }

    .filter-info{
        font-size:7px;
        margin-bottom:7px;
    }

    .summary{
        gap:4px;
        margin-bottom:8px;
    }

    .summary-box{
        padding:4px;
    }

    .summary-label{
        font-size:6px;
    }

    .summary-value{
        font-size:8px;
    }

    th{
        padding:4px 3px;
        font-size:6.5px;
    }

    td{
        padding:4px 3px;
        font-size:7px;
    }

    .grand-total td{
        font-size:8px;
    }

    thead{
        display:table-header-group;
    }

    tfoot{
        display:table-row-group;
    }

    tr{
        page-break-inside:avoid;
        break-inside:avoid;
    }
}
</style>
</head>

<body>

<div class="report">

    <div class="print-bar">
        <button
            type="button"
            class="print-button"
            onclick="window.print()"
        >
            🖨 PRINT EXPENSE REPORT
        </button>
    </div>

    <div class="report-header">
        <div class="report-title">
            VERDIVIEW RESTAURANT INC.
            <br>
            EXPENSE REPORT
        </div>

        <div class="report-subtitle">
            <?=h($branchName)?>
        </div>

        <div class="report-period">
            <?=h($periodLabel)?>
        </div>
    </div>

    <div class="filter-info">

        <div class="filter-left">

            <div>
                <strong>Records:</strong>
                <?=count($rows)?>
            </div>

            <?php if ($q !== ''): ?>
                <div>
                    <strong>Search:</strong>
                    <?=h($q)?>
                </div>
            <?php endif; ?>

        </div>

        <div>
            <strong>Printed:</strong>
            <?=date('Y-m-d h:i A')?>
        </div>

    </div>

    <div class="summary">

        <div class="summary-box">
            <div class="summary-label">Cash</div>
            <div class="summary-value">
                <?php
                $cashTotal = 0;
                foreach ($rows as $r) {
                    if (strtoupper(trim($r['payment_method'] ?? '')) === 'CASH') {
                        $cashTotal += (float)($r['amount'] ?? 0);
                    }
                }
                ?>
                ₱<?=number_format($cashTotal, 2)?>
            </div>
        </div>

        <div class="summary-box">
            <div class="summary-label">GCASH</div>
            <div class="summary-value">
                <?php
                $gcashTotal = 0;
                foreach ($rows as $r) {
                    if (strtoupper(trim($r['payment_method'] ?? '')) === 'GCASH') {
                        $gcashTotal += (float)($r['amount'] ?? 0);
                    }
                }
                ?>
                ₱<?=number_format($gcashTotal, 2)?>
            </div>
        </div>

        <div class="summary-box">
            <div class="summary-label">Cheque</div>
            <div class="summary-value">
                <?php
                $chequeTotal = 0;
                foreach ($rows as $r) {
                    if (strtoupper(trim($r['payment_method'] ?? '')) === 'CHEQUE') {
                        $chequeTotal += (float)($r['amount'] ?? 0);
                    }
                }
                ?>
                ₱<?=number_format($chequeTotal, 2)?>
            </div>
        </div>

        <div class="summary-box">
            <div class="summary-label">Bank Transfer</div>
            <div class="summary-value">
                <?php
                $bankTotal = 0;
                foreach ($rows as $r) {
                    if (strtoupper(trim($r['payment_method'] ?? '')) === 'BANK TRANSFER') {
                        $bankTotal += (float)($r['amount'] ?? 0);
                    }
                }
                ?>
                ₱<?=number_format($bankTotal, 2)?>
            </div>
        </div>

        <div class="summary-box">
            <div class="summary-label">Debit</div>
            <div class="summary-value">
                <?php
                $debitTotal = 0;
                foreach ($rows as $r) {
                    if (strtoupper(trim($r['payment_method'] ?? '')) === 'DEBIT') {
                        $debitTotal += (float)($r['amount'] ?? 0);
                    }
                }
                ?>
                ₱<?=number_format($debitTotal, 2)?>
            </div>
        </div>

        <div class="summary-box summary-total">
            <div class="summary-label">Grand Total</div>
            <div class="summary-value">
                ₱<?=number_format($grandTotal, 2)?>
            </div>
        </div>

    </div>

    <table>

        <thead>
            <tr>
                <th class="col-no">#</th>
                <th class="col-date">Date</th>
                <th class="col-branch">Branch</th>
                <th class="col-ref">Reference</th>
                <th class="col-doc">Doc.</th>
                <th class="col-sidr">SI / DR No.</th>
                <th class="col-supplier">Supplier</th>
                <th class="col-category">Category</th>
                <th class="col-payment">Payment</th>
                <th class="col-check">Check No.</th>
                <th class="col-description">Description</th>
                <th class="col-amount">Amount</th>
            </tr>
        </thead>

        <tbody>

        <?php if ($rows): ?>

            <?php foreach ($rows as $index => $r): ?>

                <tr>

                    <td class="text-center">
                        <?=$index + 1?>
                    </td>

                    <td>
                        <?=h($r['expense_date'] ?? '')?>
                    </td>

                    <td>
                        <?=h($r['branch_name'] ?? '')?>
                    </td>

                    <td>
                        <strong>
                            <?=h($r['reference_no'] ?? '')?>
                        </strong>
                    </td>

                    <td class="text-center">
                        <?=h($r['document_type'] ?? '')?>
                    </td>

                    <td>
                        <?=h($r['si_dr_no'] ?? '')?>
                    </td>

                    <td>
                        <?=h($r['supplier'] ?? '')?>
                    </td>

                    <td>
                        <?=h($r['category'] ?? '')?>
                    </td>

                    <td>
                        <?=h($r['payment_method'] ?? 'CASH')?>
                    </td>

                    <td>
                        <?php
                        $payment = strtoupper(trim($r['payment_method'] ?? ''));
                        $checkNo = trim((string)($r['check_no'] ?? ''));
                        ?>
                        <?php if ($payment === 'CHEQUE' && $checkNo !== ''): ?>
                            <?=h($checkNo)?>
                        <?php else: ?>
                            -
                        <?php endif; ?>
                    </td>

                    <td>
                        <?=h($r['description'] ?? '')?>
                    </td>

                    <td class="amount">
                        ₱<?=number_format((float)($r['amount'] ?? 0), 2)?>
                    </td>

                </tr>

            <?php endforeach; ?>

        <?php else: ?>

            <tr>
                <td colspan="12" class="no-records">
                    No expense records found for the selected filter.
                </td>
            </tr>

        <?php endif; ?>

        </tbody>

        <tfoot>

            <tr>
                <td colspan="11" class="text-right">
                    TOTAL EXPENSES
                </td>
                <td class="amount">
                    ₱<?=number_format($grandTotal, 2)?>
                </td>
            </tr>

            <tr class="grand-total">
                <td colspan="11" class="text-right">
                    GRAND TOTAL
                </td>
                <td class="amount">
                    ₱<?=number_format($grandTotal, 2)?>
                </td>
            </tr>

        </tfoot>

    </table>

</div>

</body>
</html>
