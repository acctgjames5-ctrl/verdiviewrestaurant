<?php

session_start();

require "config.php";


/* =========================================================
   FILTERS
========================================================= */

$selectedBranch = (int)(
    $_GET['branch']
    ?? $_SESSION['branch_id']
    ?? 0
);

$from = trim(
    $_GET['from'] ?? ''
);

$to = trim(
    $_GET['to'] ?? ''
);

$q = trim(
    $_GET['q'] ?? ''
);


/* =========================================================
   STATUS FILTER
========================================================= */

$status = strtoupper(
    trim(
        $_GET['status'] ?? ''
    )
);

if (!in_array(
    $status,
    [
        'PAID',
        'UNPAID',
        'PARTIAL'
    ],
    true
)) {

    $status = '';

}


/* =========================================================
   WHERE
========================================================= */

$where = [];

$params = [];


/* =========================================================
   BRANCH
========================================================= */

if ($selectedBranch > 0) {

    $where[] = "p.branch_id = ?";

    $params[] = $selectedBranch;

}


/* =========================================================
   FROM DATE
========================================================= */

if ($from !== '') {

    $where[] = "p.purchase_date >= ?";

    $params[] = $from;

}


/* =========================================================
   TO DATE
========================================================= */

if ($to !== '') {

    $where[] = "p.purchase_date <= ?";

    $params[] = $to;

}


/* =========================================================
   SEARCH
========================================================= */

if ($q !== '') {

    $where[] = "
        (
            p.supplier LIKE ?
            OR p.invoice_no LIKE ?
            OR p.description LIKE ?
            OR p.payment_method LIKE ?
            OR p.status LIKE ?
            OR p.notes LIKE ?
        )
    ";

    $search = "%{$q}%";

    for ($i = 0; $i < 6; $i++) {

        $params[] = $search;

    }

}


/* =========================================================
   STATUS
========================================================= */

if ($status !== '') {

    $where[] = "UPPER(p.status) = ?";

    $params[] = $status;

}


/* =========================================================
   GET PURCHASES
========================================================= */

$sql = "
    SELECT
        p.*,
        b.branch_name

    FROM purchases p

    LEFT JOIN branches b
        ON b.id = p.branch_id
";


if ($where) {

    $sql .=
        " WHERE " .
        implode(
            " AND ",
            $where
        );

}


$sql .= "
    ORDER BY
        p.purchase_date ASC,
        p.id ASC
";


$stmt = $pdo->prepare($sql);

$stmt->execute($params);

$rows = $stmt->fetchAll();


/* =========================================================
   TOTALS
========================================================= */

$grandTotal = 0;

$totalPaid = 0;

$totalUnpaid = 0;

$totalPartial = 0;

$totalQuantity = 0;


foreach ($rows as $r) {

    $amount = (float)(
        $r['total_amount'] ?? 0
    );

    $quantity = (float)(
        $r['quantity'] ?? 0
    );

    $grandTotal += $amount;

    $totalQuantity += $quantity;


    $rowStatus = strtoupper(
        trim(
            $r['status'] ?? ''
        )
    );


    switch ($rowStatus) {

        case 'PAID':

            $totalPaid += $amount;

            break;


        case 'UNPAID':

            $totalUnpaid += $amount;

            break;


        case 'PARTIAL':

            $totalPartial += $amount;

            break;

    }

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

    $branchStmt->execute([
        $selectedBranch
    ]);

    $branchName =
        $branchStmt->fetchColumn()
        ?: "All Branches";

}

?>
<!DOCTYPE html>

<html lang="en">

<head>

<meta charset="UTF-8">

<title>Purchase Report</title>


<style>

*{
    box-sizing:border-box;
}


body{

    margin:0;

    padding:25px;

    font-family:
        Arial,
        Helvetica,
        sans-serif;

    color:#222;

    background:#fff;

    font-size:12px;
}


.report{

    width:100%;

    max-width:1400px;

    margin:auto;

}


/* =========================================================
   HEADER
========================================================= */

.report-header{

    text-align:center;

    margin-bottom:18px;

    border-bottom:
        2px solid #222;

    padding-bottom:12px;

}


.report-title{

    font-size:24px;

    font-weight:bold;

    margin-bottom:5px;

}


.report-subtitle{

    font-size:13px;

    color:#555;

}


.report-date{

    margin-top:5px;

    font-size:11px;

    color:#777;

}


/* =========================================================
   FILTER INFO
========================================================= */

.filter-info{

    display:flex;

    justify-content:space-between;

    margin-bottom:15px;

    font-size:12px;

}


.filter-info strong{

    font-weight:bold;

}


/* =========================================================
   SUMMARY
========================================================= */

.summary-grid{

    display:grid;

    grid-template-columns:
        repeat(5, 1fr);

    gap:8px;

    margin-bottom:18px;

}


.summary-box{

    border:
        1px solid #bbb;

    padding:9px;

    text-align:center;

}


.summary-label{

    font-size:9px;

    font-weight:bold;

    color:#555;

    text-transform:uppercase;

    margin-bottom:4px;

}


.summary-value{

    font-size:13px;

    font-weight:bold;

}


.summary-paid{

    color:#15945b;

}


.summary-unpaid{

    color:#d96b19;

}


.summary-partial{

    color:#2169e8;

}


.summary-grand{

    border:
        2px solid #222;

}


/* =========================================================
   TABLE
========================================================= */

table{

    width:100%;

    border-collapse:collapse;

}


th{

    background:#eee;

    border:
        1px solid #999;

    padding:7px 6px;

    font-size:9px;

    text-transform:uppercase;

    white-space:nowrap;

}


td{

    border:
        1px solid #bbb;

    padding:7px 6px;

    vertical-align:middle;

}


.text-right{

    text-align:right;

}


.text-center{

    text-align:center;

}


.money{

    white-space:nowrap;

    text-align:right;

}


/* =========================================================
   STATUS
========================================================= */

.status-paid{

    font-weight:bold;

}


.status-unpaid{

    font-weight:bold;

}


.status-partial{

    font-weight:bold;

}


/* =========================================================
   FOOTER
========================================================= */

tfoot td{

    font-weight:bold;

    background:#f1f1f1;

    border:
        1px solid #777;

}


.grand-total-row td{

    font-size:13px;

    font-weight:bold;

    background:#e8e8e8;

    border-top:
        2px solid #222;

}


/* =========================================================
   PRINT BUTTON
========================================================= */

.print-button{

    margin-bottom:20px;

    padding:9px 18px;

    background:#172642;

    color:#fff;

    border:0;

    border-radius:5px;

    font-weight:bold;

    cursor:pointer;

}


/* =========================================================
   PRINT
========================================================= */

@media print{

    body{

        padding:0;

        font-size:10px;

    }


    .print-button{

        display:none;

    }


    .report{

        max-width:none;

    }


    @page{

        size:landscape;

        margin:10mm;

    }


    th{

        font-size:8px;

        padding:5px;

    }


    td{

        font-size:8.5px;

        padding:5px;

    }


    .summary-grid{

        gap:4px;

    }


    .summary-box{

        padding:6px;

    }


    .summary-label{

        font-size:7px;

    }


    .summary-value{

        font-size:10px;

    }

}

</style>

</head>


<body>


<div class="report">


<!-- =====================================================
     PRINT BUTTON
====================================================== -->

<button
    class="print-button"
    onclick="window.print()"
>

    🖨 PRINT

</button>


<!-- =====================================================
     HEADER
====================================================== -->

<div class="report-header">

    <div class="report-title">

        VERDIVIEW RESTAURANT INC.

        <br>

        PURCHASE REPORT

    </div>


    <div class="report-subtitle">

        <?=htmlspecialchars(
            $branchName
        )?>

    </div>


    <div class="report-date">

        <?php if ($from && $to): ?>

            Period:
            <?=htmlspecialchars($from)?>
            to
            <?=htmlspecialchars($to)?>

        <?php elseif ($from): ?>

            From:
            <?=htmlspecialchars($from)?>

        <?php elseif ($to): ?>

            Until:
            <?=htmlspecialchars($to)?>

        <?php else: ?>

            All Dates

        <?php endif; ?>

    </div>

</div>


<!-- =====================================================
     FILTER INFO
====================================================== -->

<div class="filter-info">

    <div>

        <strong>
            Records:
        </strong>

        <?=count($rows)?>

    </div>


    <?php if ($q): ?>

    <div>

        <strong>
            Search:
        </strong>

        <?=htmlspecialchars($q)?>

    </div>

    <?php endif; ?>


    <?php if ($status): ?>

    <div>

        <strong>
            Status:
        </strong>

        <?=htmlspecialchars($status)?>

    </div>

    <?php endif; ?>


    <div>

        <strong>
            Printed:
        </strong>

        <?=date('Y-m-d h:i A')?>

    </div>

</div>


<!-- =====================================================
     SUMMARY
====================================================== -->

<div class="summary-grid">


<!-- PAID -->

<div class="summary-box">

    <div class="summary-label">
        Paid
    </div>

    <div class="
        summary-value
        summary-paid
    ">

        ₱<?=number_format(
            $totalPaid,
            2
        )?>

    </div>

</div>


<!-- UNPAID -->

<div class="summary-box">

    <div class="summary-label">
        Unpaid
    </div>

    <div class="
        summary-value
        summary-unpaid
    ">

        ₱<?=number_format(
            $totalUnpaid,
            2
        )?>

    </div>

</div>


<!-- PARTIAL -->

<div class="summary-box">

    <div class="summary-label">
        Partial
    </div>

    <div class="
        summary-value
        summary-partial
    ">

        ₱<?=number_format(
            $totalPartial,
            2
        )?>

    </div>

</div>


<!-- QUANTITY -->

<div class="summary-box">

    <div class="summary-label">
        Total Quantity
    </div>

    <div class="summary-value">

        <?=number_format(
            $totalQuantity,
            2
        )?>

    </div>

</div>


<!-- GRAND TOTAL -->

<div class="
    summary-box
    summary-grand
">

    <div class="summary-label">
        Grand Total
    </div>

    <div class="summary-value">

        ₱<?=number_format(
            $grandTotal,
            2
        )?>

    </div>

</div>


</div>


<!-- =====================================================
     PURCHASE TABLE
====================================================== -->

<table>


<thead>

<tr>

    <th>
        #
    </th>

    <th>
        Date
    </th>

    <th>
        Branch
    </th>

    <th>
        Supplier
    </th>

    <th>
        Invoice No.
    </th>

    <th>
        Description
    </th>

    <th class="text-right">
        Qty
    </th>

    <th class="text-right">
        Unit Cost
    </th>

    <th class="text-right">
        Total Amount
    </th>

    <th>
        Payment
    </th>

    <th>
        Status
    </th>

    <th>
        Notes
    </th>

</tr>

</thead>


<tbody>


<?php if ($rows): ?>


<?php foreach (
    $rows as $index => $r
): ?>


<?php

$quantity =
    (float)(
        $r['quantity']
        ?? 0
    );


$unitCost =
    (float)(
        $r['unit_cost']
        ?? 0
    );


$totalAmount =
    (float)(
        $r['total_amount']
        ?? 0
    );


$rowStatus =
    strtoupper(
        trim(
            $r['status']
            ?? ''
        )
    );


?>


<tr>


<!-- NUMBER -->

<td class="text-center">

    <?=$index + 1?>

</td>


<!-- DATE -->

<td>

    <?=htmlspecialchars(
        $r['purchase_date']
        ?? ''
    )?>

</td>


<!-- BRANCH -->

<td>

    <?=htmlspecialchars(
        $r['branch_name']
        ?? ''
    )?>

</td>


<!-- SUPPLIER -->

<td>

    <?=htmlspecialchars(
        $r['supplier']
        ?? ''
    )?>

</td>


<!-- INVOICE -->

<td>

    <?=htmlspecialchars(
        $r['invoice_no']
        ?? ''
    )?>

</td>


<!-- DESCRIPTION -->

<td>

    <?=htmlspecialchars(
        $r['description']
        ?? ''
    )?>

</td>


<!-- QUANTITY -->

<td class="text-right">

    <?=number_format(
        $quantity,
        2
    )?>

</td>


<!-- UNIT COST -->

<td class="money">

    ₱<?=number_format(
        $unitCost,
        2
    )?>

</td>


<!-- TOTAL -->

<td class="money">

    ₱<?=number_format(
        $totalAmount,
        2
    )?>

</td>


<!-- PAYMENT -->

<td>

    <?=htmlspecialchars(
        $r['payment_method']
        ?? ''
    )?>

</td>


<!-- STATUS -->

<td class="text-center">

<?php if (
    $rowStatus === 'PAID'
): ?>

<span class="status-paid">

    PAID

</span>

<?php elseif (
    $rowStatus === 'UNPAID'
): ?>

<span class="status-unpaid">

    UNPAID

</span>

<?php elseif (
    $rowStatus === 'PARTIAL'
): ?>

<span class="status-partial">

    PARTIAL

</span>

<?php else: ?>

    <?=htmlspecialchars(
        $rowStatus
    )?>

<?php endif; ?>

</td>


<!-- NOTES -->

<td>

    <?=htmlspecialchars(
        $r['notes']
        ?? ''
    )?>

</td>


</tr>


<?php endforeach; ?>


<?php else: ?>


<tr>

<td
    colspan="12"
    style="
        text-align:center;
        padding:30px;
    "
>

    No purchase records found.

</td>

</tr>


<?php endif; ?>


</tbody>


<!-- =====================================================
     TOTAL
====================================================== -->

<tfoot>


<tr>

<td
    colspan="8"
    class="text-right"
>

    TOTAL

</td>


<td class="money">

    ₱<?=number_format(
        $grandTotal,
        2
    )?>

</td>


<td colspan="3"></td>

</tr>


<tr class="grand-total-row">

<td
    colspan="8"
    class="text-right"
>

    GRAND TOTAL

</td>


<td class="money">

    ₱<?=number_format(
        $grandTotal,
        2
    )?>

</td>


<td colspan="3"></td>

</tr>


</tfoot>


</table>


</div>


</body>

</html>