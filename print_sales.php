```php
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
   PAYMENT STATUS FILTER
========================================================= */

$status = strtoupper(
    trim(
        $_GET['status'] ?? ''
    )
);

if (!in_array(
    $status,
    ['PAID', 'UNPAID'],
    true
)) {

    $status = '';

}


/* =========================================================
   SERVICE CHARGE FILTER
========================================================= */

$sc = strtoupper(
    trim(
        $_GET['sc'] ?? ''
    )
);

if (!in_array(
    $sc,
    [
        'WITH_SC',
        'WITHOUT_SC',
        'ALL'
    ],
    true
)) {

    $sc = '';

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

    $where[] = "
        s.branch_id = ?
    ";

    $params[] =
        $selectedBranch;

}


/* =========================================================
   FROM DATE
========================================================= */

if ($from !== '') {

    $where[] = "
        s.sale_date >= ?
    ";

    $params[] =
        $from;

}


/* =========================================================
   TO DATE
========================================================= */

if ($to !== '') {

    $where[] = "
        s.sale_date <= ?
    ";

    $params[] =
        $to;

}


/* =========================================================
   SEARCH
========================================================= */

if ($q !== '') {

    $qUpper =
        strtoupper($q);


    /* =====================================================
       SERVICE CHARGE SEARCH
    ===================================================== */

    if (
        $qUpper === 'SC'
        ||
        $qUpper === 'WITH SC'
        ||
        $qUpper === 'WITH_SC'
    ) {

        $where[] = "
            COALESCE(
                s.Service_charge,
                0
            ) > 0
        ";

    }


    /* =====================================================
       WITHOUT SERVICE CHARGE
    ===================================================== */

    elseif (
        $qUpper === 'NO SC'
        ||
        $qUpper === 'WITHOUT SC'
        ||
        $qUpper === 'NO_SC'
        ||
        $qUpper === 'WITHOUT_SC'
    ) {

        $where[] = "
            COALESCE(
                s.Service_charge,
                0
            ) <= 0
        ";

    }


    /* =====================================================
       NORMAL SEARCH
    ===================================================== */

    else {

        $where[] = "
            (
                s.reference_no LIKE ?
                OR s.customer LIKE ?
                OR s.description LIKE ?
                OR s.remarks LIKE ?
                OR CAST(s.Pax AS CHAR) LIKE ?
                OR CAST(s.Discount AS CHAR) LIKE ?
                OR CAST(s.Service_charge AS CHAR) LIKE ?
                OR CAST(s.amount AS CHAR) LIKE ?
            )
        ";

        $search =
            "%{$q}%";


        for (
            $i = 0;
            $i < 8;
            $i++
        ) {

            $params[] =
                $search;

        }

    }

}


/* =========================================================
   SERVICE CHARGE FILTER
========================================================= */

if ($sc === 'WITH_SC') {

    $where[] = "
        COALESCE(
            s.Service_charge,
            0
        ) > 0
    ";

}

elseif ($sc === 'WITHOUT_SC') {

    $where[] = "
        COALESCE(
            s.Service_charge,
            0
        ) <= 0
    ";

}


/* =========================================================
   PAYMENT STATUS
========================================================= */

if ($status !== '') {

    $where[] = "
        UPPER(
            COALESCE(
                s.remarks,
                'PAID'
            )
        ) = ?
    ";

    $params[] =
        $status;

}


/* =========================================================
   GET SALES
========================================================= */

$sql = "

    SELECT

        s.*,

        b.branch_name

    FROM sales s

    INNER JOIN branches b
        ON b.id = s.branch_id

";


if ($where) {

    $sql .=
        " WHERE " .
        implode(
            " AND ",
            $where
        );

}


/* =========================================================
   ORDER
========================================================= */

$sql .= "

    ORDER BY

        s.sale_date DESC,
        s.id DESC

";


$stmt =
    $pdo->prepare($sql);


$stmt->execute(
    $params
);


$rows =
    $stmt->fetchAll();


/* =========================================================
   TOTALS
========================================================= */

$totalSales = 0;

$totalDiscount = 0;

$totalServiceCharge = 0;

$totalCash = 0;

$totalGcash = 0;

$totalBankTransfer = 0;

$totalTerminal = 0;

$totalPaid = 0;

$totalUnpaid = 0;

$totalAccountsReceivable = 0;

$grandTotal = 0;


/* =========================================================
   COMPUTE TOTALS
========================================================= */

foreach ($rows as $r) {

    $amount =
        (float)(
            $r['amount']
            ?? 0
        );


    $discount =
        (float)(
            $r['Discount']
            ?? 0
        );


    $serviceCharge =
        (float)(
            $r['Service_charge']
            ?? 0
        );


    /*
        TOTAL =
        AMOUNT
        + SERVICE CHARGE
        - DISCOUNT
    */

    $rowTotal =
        $amount
        +
        $serviceCharge
        -
        $discount;


    $rowTotal =
        max(
            0,
            $rowTotal
        );


    /* =====================================================
       STATUS
    ===================================================== */

    $remarks =
        strtoupper(
            trim(
                $r['remarks']
                ?? 'PAID'
            )
        );


    /* =====================================================
       ACCOUNT RECEIVABLE
    ===================================================== */

    $rowAR =
        (
            $remarks === 'UNPAID'
        )
        ? $rowTotal
        : 0;


    /* =====================================================
       BASIC TOTALS
    ===================================================== */

    $totalSales +=
        $amount;


    $totalDiscount +=
        $discount;


    $totalServiceCharge +=
        $serviceCharge;


    $grandTotal +=
        $rowTotal;


    /* =====================================================
       PAID / UNPAID
    ===================================================== */

    if (
        $remarks === 'PAID'
    ) {

        $totalPaid +=
            $rowTotal;

    }

    else {

        $totalUnpaid +=
            $rowTotal;


        $totalAccountsReceivable +=
            $rowAR;

    }


    /* =====================================================
       PAYMENT METHOD
    ===================================================== */

    $paymentMethod =
        strtolower(
            trim(
                $r['description']
                ?? ''
            )
        );


    if (
        $remarks === 'PAID'
    ) {

        switch (
            $paymentMethod
        ) {

            case 'cash':

                $totalCash +=
                    $rowTotal;

                break;


            case 'gcash':

                $totalGcash +=
                    $rowTotal;

                break;


            case 'bank transfer':

                $totalBankTransfer +=
                    $rowTotal;

                break;


            case 'terminal':

                $totalTerminal +=
                    $rowTotal;

                break;

        }

    }

}


/* =========================================================
   BRANCH NAME
========================================================= */

$branchName =
    "All Branches";


if (
    $selectedBranch > 0
) {

    $branchStmt =
        $pdo->prepare("

            SELECT
                branch_name

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


/* =========================================================
   STATUS LABEL
========================================================= */

if (
    $status === 'PAID'
) {

    $statusLabel =
        'PAID ONLY';

}

elseif (
    $status === 'UNPAID'
) {

    $statusLabel =
        'UNPAID ONLY';

}

else {

    $statusLabel =
        'ALL';

}


/* =========================================================
   SC LABEL
========================================================= */

if (
    $sc === 'WITH_SC'
) {

    $scLabel =
        'WITH SERVICE CHARGE';

}

elseif (
    $sc === 'WITHOUT_SC'
) {

    $scLabel =
        'WITHOUT SERVICE CHARGE';

}

else {

    $scLabel =
        'ALL';

}


/* =========================================================
   SEARCH LABEL
========================================================= */

$searchLabel =
    $q !== ''
    ? $q
    : 'ALL';


/* =========================================================
   GROUP UNPAID BY CUSTOMER
========================================================= */

$groupedUnpaid = [];


if (
    $status === 'UNPAID'
) {

    foreach (
        $rows as $r
    ) {

        $customer =
            trim(
                (string)(
                    $r['customer']
                    ?? ''
                )
            );


        if (
            $customer === ''
        ) {

            $customer =
                'NO CUSTOMER';

        }


        $groupedUnpaid[$customer][] =
            $r;

    }

}

?>
<!DOCTYPE html>

<html lang="en">

<head>

<meta charset="UTF-8">

<title>
    Sales Report
</title>


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

    flex-wrap:wrap;

    gap:10px;

    margin-bottom:15px;

    font-size:12px;

}


.filter-info strong{

    font-weight:bold;

}


/* =========================================================
   FILTER BADGES
========================================================= */

.filter-badge{

    display:inline-block;

    padding:5px 10px;

    border:
        1px solid #777;

    border-radius:4px;

    font-weight:bold;

    font-size:11px;

}


.status-paid{

    background:#e8f8ef;

    color:#15945b;

    border-color:#9bd9b7;

}


.status-unpaid{

    background:#fff1e7;

    color:#d96b19;

    border-color:#f3b88d;

}


.status-all{

    background:#eee;

    color:#333;

}


.sc-with{

    background:#e8f8ef;

    color:#15945b;

    border-color:#9bd9b7;

}


.sc-without{

    background:#f1f1f1;

    color:#555;

    border-color:#aaa;

}


/* =========================================================
   SUMMARY
========================================================= */

.summary-grid{

    display:grid;

    grid-template-columns:
        repeat(8, 1fr);

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


.summary-grand{

    border:
        2px solid #222;

}


.summary-ar{

    border:
        2px solid #e67e22;

}


/* =========================================================
   CUSTOMER GROUP
========================================================= */

.customer-group{

    margin-top:18px;

    page-break-inside:avoid;

}


.customer-header{

    background:#172642;

    color:#fff;

    padding:9px 10px;

    font-size:14px;

    font-weight:bold;

    border:
        1px solid #172642;

}


.customer-total{

    background:#f3f3f3;

    font-weight:bold;

}


.customer-total td{

    border:
        1px solid #777;

}


.customer-grand-label{

    text-align:right;

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

    font-size:10px;

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
   UNPAID TABLE
========================================================= */

.unpaid-table{

    width:100%;

    table-layout:auto;

}


.unpaid-table th,
.unpaid-table td{

    padding:9px 8px;

}


/* CUSTOMER */

.unpaid-table th:nth-child(4),
.unpaid-table td:nth-child(4){

    min-width:180px;

}


/* PAYMENT */

.unpaid-table th:nth-child(5),
.unpaid-table td:nth-child(5){

    min-width:130px;

}


/* REFERENCE */

.unpaid-table th:nth-child(3),
.unpaid-table td:nth-child(3){

    min-width:110px;

}


/* MONEY */

.unpaid-table th:nth-child(n+6),
.unpaid-table td:nth-child(n+6){

    white-space:nowrap;

}


/* =========================================================
   STATUS
========================================================= */

.paid{

    font-weight:bold;

    color:#15945b;

}


.unpaid{

    font-weight:bold;

    color:#d96b19;

}


.ar{

    font-weight:bold;

    color:#e67e22;

    white-space:nowrap;

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

        font-size:9px;

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


    .customer-header{

        font-size:13px;

        padding:7px 9px;

    }


    .customer-group{

        page-break-inside:auto;

    }


    /* =====================================================
       UNPAID PRINT
    ===================================================== */

    .unpaid-table{

        width:100%;

        table-layout:auto;

        font-size:11px;

    }


    .unpaid-table th{

        font-size:9px;

        padding:7px 6px;

    }


    .unpaid-table td{

        font-size:10px;

        padding:7px 6px;

    }


    .unpaid-table th:nth-child(4),
    .unpaid-table td:nth-child(4){

        min-width:180px;

    }


    .unpaid-table th:nth-child(5),
    .unpaid-table td:nth-child(5){

        min-width:130px;

    }


    .customer-total td{

        font-size:10px;

        padding:7px 6px;

    }


    .unpaid-grand-total td{

        font-size:11px;

        padding:8px 6px;

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

        <?php if ($status === 'UNPAID'): ?>

            ACCOUNT RECEIVABLE

        <?php else: ?>

            SALES REPORT

        <?php endif; ?>

    </div>


    <div class="report-subtitle">

        <?=htmlspecialchars(
            $branchName
        )?>

    </div>


    <div class="report-date">

        <?php if (
            $from &&
            $to
        ): ?>

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
     ACTIVE FILTERS
====================================================== -->

<div class="filter-info">


    <div>

        <strong>
            Records:
        </strong>

        <?=count($rows)?>

    </div>


    <!-- STATUS -->

    <div>

        <strong>
            Payment Status:
        </strong>


        <?php if (
            $status === 'PAID'
        ): ?>

            <span
                class="
                    filter-badge
                    status-paid
                "
            >

                PAID ONLY

            </span>

        <?php elseif (
            $status === 'UNPAID'
        ): ?>

            <span
                class="
                    filter-badge
                    status-unpaid
                "
            >

                UNPAID ONLY

            </span>

        <?php else: ?>

            <span
                class="
                    filter-badge
                    status-all
                "
            >

                ALL

            </span>

        <?php endif; ?>

    </div>


    <!-- SERVICE CHARGE -->

    <div>

        <strong>
            Service Charge:
        </strong>


        <?php if (
            $sc === 'WITH_SC'
        ): ?>

            <span
                class="
                    filter-badge
                    sc-with
                "
            >

                WITH SC

            </span>

        <?php elseif (
            $sc === 'WITHOUT_SC'
        ): ?>

            <span
                class="
                    filter-badge
                    sc-without
                "
            >

                WITHOUT SC

            </span>

        <?php else: ?>

            <span
                class="
                    filter-badge
                    status-all
                "
            >

                ALL

            </span>

        <?php endif; ?>

    </div>


    <!-- SEARCH -->

    <?php if (
        $q !== ''
    ): ?>

        <div>

            <strong>
                Search:
            </strong>

            <?=htmlspecialchars(
                $q
            )?>

        </div>

    <?php endif; ?>


    <div>

        <strong>
            Printed:
        </strong>

        <?=date(
            'Y-m-d h:i A'
        )?>

    </div>

</div>


<!-- =====================================================
     SUMMARY
====================================================== -->

<div class="summary-grid">


    <div class="summary-box">

        <div class="summary-label">
            Cash
        </div>

        <div class="summary-value">

            ₱<?=number_format(
                $totalCash,
                2
            )?>

        </div>

    </div>


    <div class="summary-box">

        <div class="summary-label">
            GCash
        </div>

        <div class="summary-value">

            ₱<?=number_format(
                $totalGcash,
                2
            )?>

        </div>

    </div>


    <div class="summary-box">

        <div class="summary-label">
            Bank Transfer
        </div>

        <div class="summary-value">

            ₱<?=number_format(
                $totalBankTransfer,
                2
            )?>

        </div>

    </div>


    <div class="summary-box">

        <div class="summary-label">
            Terminal
        </div>

        <div class="summary-value">

            ₱<?=number_format(
                $totalTerminal,
                2
            )?>

        </div>

    </div>


    <div class="summary-box">

        <div class="summary-label">
            Paid
        </div>

        <div class="summary-value">

            ₱<?=number_format(
                $totalPaid,
                2
            )?>

        </div>

    </div>


    <div class="summary-box">

        <div class="summary-label">
            Unpaid
        </div>

        <div class="summary-value">

            ₱<?=number_format(
                $totalUnpaid,
                2
            )?>

        </div>

    </div>


    <div class="
        summary-box
        summary-ar
    ">

        <div class="summary-label">
            Account Receivable
        </div>

        <div class="summary-value">

            ₱<?=number_format(
                $totalAccountsReceivable,
                2
            )?>

        </div>

    </div>


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
     UNPAID GROUPED BY CUSTOMER
====================================================== -->

<?php if (
    $status === 'UNPAID'
): ?>


    <?php if (
        $groupedUnpaid
    ): ?>


        <?php foreach (
            $groupedUnpaid as $customerName => $customerRows
        ): ?>


            <?php

            $customerAmount = 0;

            $customerDiscount = 0;

            $customerServiceCharge = 0;

            $customerTotal = 0;

            $customerAR = 0;

            ?>


            <div class="customer-group">


                <div class="customer-header">

                    CUSTOMER:
                    <?=htmlspecialchars(
                        $customerName
                    )?>

                </div>


                <!-- =================================================
                     UNPAID TABLE
                     BRANCH AND PAX REMOVED
                ================================================== -->

                <table class="unpaid-table">


                    <thead>

                    <tr>

                        <th>
                            #
                        </th>

                        <th>
                            Date
                        </th>

                        <th>
                            Reference
                        </th>

                        <th>
                            Customer
                        </th>

                        <th>
                            Payment
                        </th>

                        <th class="text-right">
                            Amount
                        </th>

                        <th class="text-right">
                            Discount
                        </th>

                        <th class="text-right">
                            Service Charge
                        </th>

                        <th class="text-right">
                            Total
                        </th>

                        <th class="text-center">
                            Status
                        </th>

                        <th class="text-right">
                            Account Receivable
                        </th>

                    </tr>

                    </thead>


                    <tbody>


                    <?php foreach (
                        $customerRows as $customerIndex => $r
                    ): ?>


                        <?php

                        $amount =
                            (float)(
                                $r['amount']
                                ?? 0
                            );


                        $discount =
                            (float)(
                                $r['Discount']
                                ?? 0
                            );


                        $serviceCharge =
                            (float)(
                                $r['Service_charge']
                                ?? 0
                            );


                        $rowTotal =
                            $amount
                            +
                            $serviceCharge
                            -
                            $discount;


                        $rowTotal =
                            max(
                                0,
                                $rowTotal
                            );


                        $rowStatus =
                            strtoupper(
                                trim(
                                    $r['remarks']
                                    ?? 'PAID'
                                )
                            );


                        $rowAR =
                            (
                                $rowStatus === 'UNPAID'
                            )
                            ? $rowTotal
                            : 0;


                        /* CUSTOMER TOTALS */

                        $customerAmount +=
                            $amount;


                        $customerDiscount +=
                            $discount;


                        $customerServiceCharge +=
                            $serviceCharge;


                        $customerTotal +=
                            $rowTotal;


                        $customerAR +=
                            $rowAR;

                        ?>


                        <tr>


                            <td class="text-center">

                                <?=($customerIndex + 1)?>

                            </td>


                            <td>

                                <?=htmlspecialchars(
                                    $r['sale_date']
                                )?>

                            </td>


                            <td>

                                <?=htmlspecialchars(
                                    $r['reference_no']
                                )?>

                            </td>


                            <td>

                                <?=htmlspecialchars(
                                    $r['customer']
                                )?>

                            </td>


                            <td>

                                <?=htmlspecialchars(
                                    $r['description']
                                )?>

                            </td>


                            <td class="money">

                                ₱<?=number_format(
                                    $amount,
                                    2
                                )?>

                            </td>


                            <td class="money">

                                ₱<?=number_format(
                                    $discount,
                                    2
                                )?>

                            </td>


                            <td class="money">

                                ₱<?=number_format(
                                    $serviceCharge,
                                    2
                                )?>

                            </td>


                            <td class="money">

                                ₱<?=number_format(
                                    $rowTotal,
                                    2
                                )?>

                            </td>


                            <td class="text-center">

                                <span class="unpaid">

                                    UNPAID

                                </span>

                            </td>


                            <td class="money ar">

                                ₱<?=number_format(
                                    $rowAR,
                                    2
                                )?>

                            </td>


                        </tr>


                    <?php endforeach; ?>


                    <!-- =================================================
                         CUSTOMER TOTAL
                    ================================================== -->

                    <tr class="customer-total">


                        <td
                            colspan="5"
                            class="customer-grand-label"
                        >

                            TOTAL FOR
                            <?=htmlspecialchars(
                                $customerName
                            )?>

                        </td>


                        <td class="money">

                            ₱<?=number_format(
                                $customerAmount,
                                2
                            )?>

                        </td>


                        <td class="money">

                            ₱<?=number_format(
                                $customerDiscount,
                                2
                            )?>

                        </td>


                        <td class="money">

                            ₱<?=number_format(
                                $customerServiceCharge,
                                2
                            )?>

                        </td>


                        <td class="money">

                            ₱<?=number_format(
                                $customerTotal,
                                2
                            )?>

                        </td>


                        <td class="text-center">

                            UNPAID

                        </td>


                        <td class="money ar">

                            ₱<?=number_format(
                                $customerAR,
                                2
                            )?>

                        </td>


                    </tr>


                    </tbody>


                </table>


            </div>


        <?php endforeach; ?>


    <?php else: ?>


        <table>

            <tr>

                <td
                    colspan="11"
                    style="
                        text-align:center;
                        padding:30px;
                    "
                >

                    No unpaid sales records found.

                </td>

            </tr>

        </table>


    <?php endif; ?>


    <!-- =====================================================
         UNPAID GRAND TOTAL
    ====================================================== -->

    <table
        class="unpaid-grand-total"
        style="margin-top:20px;"
    >

        <tfoot>

        <tr class="grand-total-row">


            <td
                colspan="5"
                class="text-right"
            >

                GRAND TOTAL UNPAID

            </td>


            <td class="money">

                ₱<?=number_format(
                    $totalSales,
                    2
                )?>

            </td>


            <td class="money">

                ₱<?=number_format(
                    $totalDiscount,
                    2
                )?>

            </td>


            <td class="money">

                ₱<?=number_format(
                    $totalServiceCharge,
                    2
                )?>

            </td>


            <td class="money">

                ₱<?=number_format(
                    $grandTotal,
                    2
                )?>

            </td>


            <td class="text-center">

                UNPAID

            </td>


            <td class="money ar">

                ₱<?=number_format(
                    $totalAccountsReceivable,
                    2
                )?>

            </td>


        </tr>

        </tfoot>

    </table>


<?php else: ?>


<!-- =====================================================
     NORMAL TABLE
     PAID / ALL
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
            Reference
        </th>

        <th class="text-center">
            Pax
        </th>

        <th>
            Customer
        </th>

        <th>
            Payment
        </th>

        <th class="text-right">
            Amount
        </th>

        <th class="text-right">
            Discount
        </th>

        <th class="text-right">
            Service Charge
        </th>

        <th class="text-right">
            Total
        </th>

        <th class="text-center">
            Status
        </th>

        <th class="text-right">
            Account Receivable
        </th>

    </tr>

    </thead>


    <tbody>


    <?php if (
        $rows
    ): ?>


        <?php foreach (
            $rows as $index => $r
        ): ?>


            <?php

            $amount =
                (float)(
                    $r['amount']
                    ?? 0
                );


            $pax =
                (float)(
                    $r['Pax']
                    ?? 0
                );


            $discount =
                (float)(
                    $r['Discount']
                    ?? 0
                );


            $serviceCharge =
                (float)(
                    $r['Service_charge']
                    ?? 0
                );


            $rowTotal =
                $amount
                +
                $serviceCharge
                -
                $discount;


            $rowTotal =
                max(
                    0,
                    $rowTotal
                );


            $rowStatus =
                strtoupper(
                    trim(
                        $r['remarks']
                        ?? 'PAID'
                    )
                );


            $rowAR =
                (
                    $rowStatus === 'UNPAID'
                )
                ? $rowTotal
                : 0;

            ?>


            <tr>


                <td class="text-center">

                    <?=$index + 1?>

                </td>


                <td>

                    <?=htmlspecialchars(
                        $r['sale_date']
                    )?>

                </td>


                <td>

                    <?=htmlspecialchars(
                        $r['branch_name']
                    )?>

                </td>


                <td>

                    <?=htmlspecialchars(
                        $r['reference_no']
                    )?>

                </td>


                <td class="text-center">

                    <?=number_format(
                        $pax,
                        2
                    )?>

                </td>


                <td>

                    <?=htmlspecialchars(
                        $r['customer']
                    )?>

                </td>


                <td>

                    <?=htmlspecialchars(
                        $r['description']
                    )?>

                </td>


                <td class="money">

                    ₱<?=number_format(
                        $amount,
                        2
                    )?>

                </td>


                <td class="money">

                    ₱<?=number_format(
                        $discount,
                        2
                    )?>

                </td>


                <td class="money">

                    ₱<?=number_format(
                        $serviceCharge,
                        2
                    )?>

                </td>


                <td class="money">

                    ₱<?=number_format(
                        $rowTotal,
                        2
                    )?>

                </td>


                <td class="text-center">

                    <?php if (
                        $rowStatus === 'UNPAID'
                    ): ?>

                        <span class="unpaid">
                            UNPAID
                        </span>

                    <?php else: ?>

                        <span class="paid">
                            PAID
                        </span>

                    <?php endif; ?>

                </td>


                <td class="money ar">

                    <?php if (
                        $rowAR > 0
                    ): ?>

                        ₱<?=number_format(
                            $rowAR,
                            2
                        )?>

                    <?php else: ?>

                        ₱0.00

                    <?php endif; ?>

                </td>


            </tr>


        <?php endforeach; ?>


    <?php else: ?>


        <tr>

            <td
                colspan="13"
                style="
                    text-align:center;
                    padding:30px;
                "
            >

                No sales records found.

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
                colspan="7"
                class="text-right"
            >

                TOTAL

            </td>


            <td class="money">

                ₱<?=number_format(
                    $totalSales,
                    2
                )?>

            </td>


            <td class="money">

                ₱<?=number_format(
                    $totalDiscount,
                    2
                )?>

            </td>


            <td class="money">

                ₱<?=number_format(
                    $totalServiceCharge,
                    2
                )?>

            </td>


            <td class="money">

                ₱<?=number_format(
                    $grandTotal,
                    2
                )?>

            </td>


            <td class="text-center">

                <?=htmlspecialchars(
                    $statusLabel
                )?>

            </td>


            <td class="money">

                ₱<?=number_format(
                    $totalAccountsReceivable,
                    2
                )?>

            </td>

        </tr>


        <tr class="grand-total-row">


            <td
                colspan="10"
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


            <td class="text-center">

                <?=htmlspecialchars(
                    $scLabel
                )?>

            </td>


            <td class="money">

                ₱<?=number_format(
                    $totalAccountsReceivable,
                    2
                )?>

            </td>


        </tr>


    </tfoot>


</table>


<?php endif; ?>


</div>


</body>

</html>
```
