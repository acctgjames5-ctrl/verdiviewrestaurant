```php
<?php

/* =========================================================
   AUTHENTICATION
========================================================= */

require_once "auth.php";
require_once "config.php";

$pageTitle = "Reports";


/* =========================================================
   BRANCH / DATE FILTER
========================================================= */

$branch = (int)(
    $_GET['branch']
    ?? $_SESSION['branch_id']
    ?? 0
);

$from = trim(
    (string)(
        $_GET['from']
        ?? date('Y-m-01')
    )
);

$to = trim(
    (string)(
        $_GET['to']
        ?? date('Y-m-d')
    )
);


/* =========================================================
   VALIDATE DATES
========================================================= */

$fromDate = DateTime::createFromFormat(
    'Y-m-d',
    $from
);

$toDate = DateTime::createFromFormat(
    'Y-m-d',
    $to
);


if (
    !$fromDate ||
    $fromDate->format('Y-m-d') !== $from
) {

    $from = date('Y-m-01');

    $fromDate = new DateTime($from);
}


if (
    !$toDate ||
    $toDate->format('Y-m-d') !== $to
) {

    $to = date('Y-m-d');

    $toDate = new DateTime($to);
}


/* =========================================================
   PREVENT INVALID DATE RANGE
========================================================= */

if ($from > $to) {

    $temp = $from;
    $from = $to;
    $to = $temp;

    $fromDate = new DateTime($from);
    $toDate = new DateTime($to);
}


/* =========================================================
   INCLUSIVE DATE RANGE
========================================================= */

$toExclusive = (clone $toDate)
    ->modify('+1 day')
    ->format('Y-m-d');


/* =========================================================
   HELPER
========================================================= */

function reportScalarQuery(
    PDO $pdo,
    string $sql,
    array $params = []
): float {

    $stmt = $pdo->prepare($sql);

    $stmt->execute($params);

    $result = $stmt->fetchColumn();

    return is_numeric($result)
        ? (float)$result
        : 0.0;
}


/* =========================================================
   BRANCH NAME
========================================================= */

$branchName = "ALL BRANCHES";


if ($branch > 0) {

    $s = $pdo->prepare("
        SELECT
            branch_name
        FROM branches
        WHERE id = ?
          AND is_active = 1
        LIMIT 1
    ");

    $s->execute([$branch]);

    $foundBranch = $s->fetchColumn();


    if ($foundBranch) {

        $branchName = $foundBranch;

        $_SESSION['branch_id'] = $branch;

    } else {

        $branch = 0;

        unset($_SESSION['branch_id']);
    }
}


/* =========================================================
   BRANCH CONDITIONS
========================================================= */

$branchSalesCondition = "";
$branchSalesParams = [];

$branchPurchaseCondition = "";
$branchPurchaseParams = [];

$branchExpenseCondition = "";
$branchExpenseParams = [];

$branchInventoryCondition = "";
$branchInventoryParams = [];


if ($branch > 0) {

    $branchSalesCondition = "
        AND s.branch_id = ?
    ";

    $branchSalesParams[] = $branch;


    $branchPurchaseCondition = "
        AND p.branch_id = ?
    ";

    $branchPurchaseParams[] = $branch;


    $branchExpenseCondition = "
        AND e.branch_id = ?
    ";

    $branchExpenseParams[] = $branch;


    $branchInventoryCondition = "
        AND branch_id = ?
    ";

    $branchInventoryParams[] = $branch;
}


/* =========================================================
   FOOD SALES
   IMPORTANT:
   PostgreSQL sales columns use quoted mixed-case names
   Service_charge and Discount.
========================================================= */

$foodSales = reportScalarQuery(
    $pdo,
    "
    SELECT
        COALESCE(
            SUM(
                COALESCE(s.amount, 0)
                + COALESCE(s.\"Service_charge\", 0)
                - COALESCE(s.\"Discount\", 0)
            ),
            0
        )

    FROM sales s

    WHERE s.sale_date >= ?
      AND s.sale_date < ?

      $branchSalesCondition
    ",
    array_merge(
        [
            $from,
            $toExclusive
        ],
        $branchSalesParams
    )
);


/* =========================================================
   TOTAL REVENUE
========================================================= */

$totalRevenue = $foodSales;


/* =========================================================
   PURCHASES
========================================================= */

$purchases = reportScalarQuery(
    $pdo,
    "
    SELECT
        COALESCE(
            SUM(
                COALESCE(p.total_amount, 0)
            ),
            0
        )

    FROM purchases p

    WHERE p.purchase_date >= ?
      AND p.purchase_date < ?

      $branchPurchaseCondition
    ",
    array_merge(
        [
            $from,
            $toExclusive
        ],
        $branchPurchaseParams
    )
);


/* =========================================================
   OPERATING EXPENSES
========================================================= */

$totalOperatingExpense = reportScalarQuery(
    $pdo,
    "
    SELECT
        COALESCE(
            SUM(
                COALESCE(e.amount, 0)
            ),
            0
        )

    FROM expenses e

    WHERE e.expense_date >= ?
      AND e.expense_date < ?

      $branchExpenseCondition
    ",
    array_merge(
        [
            $from,
            $toExclusive
        ],
        $branchExpenseParams
    )
);


/* =========================================================
   EXPENSES BY CATEGORY
========================================================= */

$s = $pdo->prepare("
    SELECT

        e.category,

        COALESCE(
            SUM(
                COALESCE(e.amount, 0)
            ),
            0
        ) AS total

    FROM expenses e

    WHERE e.expense_date >= ?
      AND e.expense_date < ?

      $branchExpenseCondition

    GROUP BY
        e.category

    ORDER BY
        e.category
");


$s->execute(
    array_merge(
        [
            $from,
            $toExclusive
        ],
        $branchExpenseParams
    )
);


$expenseCategories = $s->fetchAll(
    PDO::FETCH_ASSOC
);


/* =========================================================
   OPERATING EXPENSE ARRAY
========================================================= */

$operatingExpenses = [];


foreach (
    $expenseCategories as $expense
) {

    $category = trim(
        (string)(
            $expense['category']
            ?? ''
        )
    );


    $amount = (float)(
        $expense['total']
        ?? 0
    );


    if ($category === '') {

        $category = 'OTHER EXPENSE';
    }


    if (
        isset(
            $operatingExpenses[$category]
        )
    ) {

        $operatingExpenses[$category] += $amount;

    } else {

        $operatingExpenses[$category] = $amount;
    }
}


/* =========================================================
   BEGINNING INVENTORY
========================================================= */

$beginningInventory = reportScalarQuery(
    $pdo,
    "
    SELECT
        COALESCE(
            beginning_inventory,
            0
        )

    FROM inventory

    WHERE inventory_date <= ?

      $branchInventoryCondition

    ORDER BY
        inventory_date DESC,
        id DESC

    LIMIT 1
    ",
    array_merge(
        [
            $from
        ],
        $branchInventoryParams
    )
);


/* =========================================================
   ENDING INVENTORY
========================================================= */

$endingInventory = reportScalarQuery(
    $pdo,
    "
    SELECT
        COALESCE(
            ending_inventory,
            0
        )

    FROM inventory

    WHERE inventory_date >= ?
      AND inventory_date < ?

      $branchInventoryCondition

    ORDER BY
        inventory_date DESC,
        id DESC

    LIMIT 1
    ",
    array_merge(
        [
            $from,
            $toExclusive
        ],
        $branchInventoryParams
    )
);


/* =========================================================
   GOODS AVAILABLE
========================================================= */

$goodsAvailable =
    $beginningInventory
    + $purchases;


/* =========================================================
   TOTAL COGS
========================================================= */

$totalCOGS =
    $beginningInventory
    + $purchases
    - $endingInventory;


if ($totalCOGS < 0) {

    $totalCOGS = 0;
}


/* =========================================================
   GROSS PROFIT
========================================================= */

$grossProfit =
    $totalRevenue
    - $totalCOGS;


/* =========================================================
   GROSS MARGIN
========================================================= */

$grossMargin =
    $totalRevenue > 0
        ? (
            $grossProfit
            / $totalRevenue
        ) * 100
        : 0;


/* =========================================================
   NET INCOME
========================================================= */

$netIncome =
    $grossProfit
    - $totalOperatingExpense;


/* =========================================================
   NET MARGIN
========================================================= */

$netMargin =
    $totalRevenue > 0
        ? (
            $netIncome
            / $totalRevenue
        ) * 100
        : 0;


/* =========================================================
   REPORT PERIOD
========================================================= */

$weekNumber =
    (int)ceil(
        ((int)$fromDate->format('d'))
        / 7
    );


$monthName =
    strtoupper(
        $fromDate->format('M')
    );


$year =
    $fromDate->format('Y');


$weekLabel =
    $monthName
    . ' '
    . $fromDate->format('j')
    . '-'
    . $toDate->format('j')
    . ', '
    . $year;


/* =========================================================
   TRANSACTIONS
========================================================= */

$salesRows = [];
$purchaseRows = [];
$expenseRows = [];


/* =========================================================
   SALES TRANSACTIONS
========================================================= */

$s = $pdo->prepare("
    SELECT

        s.sale_date AS dt,

        'Sales' AS type,

        s.reference_no,

        s.customer,

        s.description,

        (
            COALESCE(s.amount, 0)
            + COALESCE(s.\"Service_charge\", 0)
            - COALESCE(s.\"Discount\", 0)
        ) AS amount,

        b.branch_name

    FROM sales s

    INNER JOIN branches b
        ON b.id = s.branch_id

    WHERE s.sale_date >= ?
      AND s.sale_date < ?

      " . (
          $branch > 0
              ? " AND s.branch_id = ? "
              : ""
      ) . "

    ORDER BY
        s.sale_date DESC,
        s.id DESC
");


$s->execute(
    array_merge(
        [
            $from,
            $toExclusive
        ],
        $branch > 0
            ? [$branch]
            : []
    )
);


$salesRows = $s->fetchAll(
    PDO::FETCH_ASSOC
);


/* =========================================================
   PURCHASE TRANSACTIONS
========================================================= */

$s = $pdo->prepare("
    SELECT

        p.purchase_date AS dt,

        'Purchase' AS type,

        p.invoice_no,

        p.supplier,

        p.description,

        COALESCE(
            p.total_amount,
            0
        ) AS amount,

        b.branch_name

    FROM purchases p

    INNER JOIN branches b
        ON b.id = p.branch_id

    WHERE p.purchase_date >= ?
      AND p.purchase_date < ?

      " . (
          $branch > 0
              ? " AND p.branch_id = ? "
              : ""
      ) . "

    ORDER BY
        p.purchase_date DESC,
        p.id DESC
");


$s->execute(
    array_merge(
        [
            $from,
            $toExclusive
        ],
        $branch > 0
            ? [$branch]
            : []
    )
);


$purchaseRows = $s->fetchAll(
    PDO::FETCH_ASSOC
);


/* =========================================================
   EXPENSE TRANSACTIONS
========================================================= */

$s = $pdo->prepare("
    SELECT

        e.expense_date AS dt,

        'Expense' AS type,

        e.reference_no,

        e.supplier,

        e.description,

        COALESCE(
            e.amount,
            0
        ) AS amount,

        e.category,

        b.branch_name

    FROM expenses e

    INNER JOIN branches b
        ON b.id = e.branch_id

    WHERE e.expense_date >= ?
      AND e.expense_date < ?

      " . (
          $branch > 0
              ? " AND e.branch_id = ? "
              : ""
      ) . "

    ORDER BY
        e.expense_date DESC,
        e.id DESC
");


$s->execute(
    array_merge(
        [
            $from,
            $toExclusive
        ],
        $branch > 0
            ? [$branch]
            : []
    )
);


$expenseRows = $s->fetchAll(
    PDO::FETCH_ASSOC
);


/* =========================================================
   MERGE TRANSACTIONS
========================================================= */

$rows = array_merge(
    $salesRows,
    $purchaseRows,
    $expenseRows
);


usort(
    $rows,
    function ($a, $b) {

        return strcmp(
            (string)$b['dt'],
            (string)$a['dt']
        );
    }
);


/* =========================================================
   HEADER
========================================================= */

include "header.php";

?>

<style>

/* =========================================================
   REPORT PAGE
========================================================= */

.font-green {

    color:#57c789;

    text-align:center;

    font-size:30px;

    font-weight:800;

    line-height:1.1;

    padding:5px 5px 2px;

    border-bottom:1px solid #4285e8;
}


.report-page {

    width:100%;

    max-width:900px;

    margin:0 auto;

    padding-bottom:30px;
}


/* =========================================================
   FILTER
========================================================= */

.report-controls {

    background:#fff;

    border:1px solid #e1e5ea;

    border-radius:8px;

    padding:15px;

    margin-bottom:20px;
}


.report-controls label {

    font-size:11px;

    font-weight:700;

    color:#4d596b;

    margin-bottom:5px;
}


.report-controls .form-control {

    height:38px;

    font-size:12px;
}


/* =========================================================
   REPORT PAPER
========================================================= */

.report-paper {

    background:#fff;

    border:1px solid #cfd5dc;

    width:100%;

    max-width:650px;

    margin:0 auto;

    font-family:
        Arial,
        Helvetica,
        sans-serif;

    color:#000;
}


/* =========================================================
   REPORT HEADER
========================================================= */

.report-company {

    text-align:center;

    font-size:18px;

    font-weight:800;

    color:#4285e8;

    line-height:1.1;

    padding:5px 5px 2px;

    border-bottom:
        1px solid
        #4285e8;
}


.report-main-title {

    text-align:center;

    font-size:18px;

    font-weight:800;

    padding:3px 5px;

    line-height:1.1;
}


.report-period {

    text-align:center;

    font-size:14px;

    font-weight:700;

    padding:2px 5px 5px;

    border-bottom:
        1px solid
        #d9d9d9;
}


/* =========================================================
   COPY LABEL
========================================================= */

.copy-label {

    text-align:center;

    font-size:10px;

    font-weight:800;

    letter-spacing:1.5px;

    color:#555;

    padding:3px 0;

    border-bottom:
        1px solid
        #999;
}


/* =========================================================
   REPORT TABLE
========================================================= */

.report-table {

    width:100%;

    border-collapse:collapse;

    table-layout:fixed;
}


.report-table td {

    border-bottom:
        1px solid
        #dedede;

    height:22px;

    padding:2px 7px;

    font-size:12px;

    line-height:1.1;
}


.report-label {

    width:68%;

    text-align:right;

    padding-right:8px !important;
}


.report-amount {

    width:32%;

    text-align:right;

    white-space:nowrap;

    border-left:
        1px solid
        #d9d9d9;
}


/* =========================================================
   SECTION
========================================================= */

.report-section td {

    font-weight:800;

    text-align:left;

    height:34px;

    padding-top:13px;

    border-bottom:0;

    font-size:12px;
}


/* =========================================================
   TOTAL
========================================================= */

.report-total td {

    font-weight:800;

    border-bottom:
        3px double
        #000;
}


/* =========================================================
   SPACER
========================================================= */

.report-spacer td {

    height:22px;

    border-bottom:0;
}


/* =========================================================
   GROSS PROFIT
========================================================= */

.report-gross td {

    font-weight:800;

    height:38px;

    border-bottom:
        3px double
        #000;
}


/* =========================================================
   NET INCOME
========================================================= */

.report-net td {

    background:#cfe2f3;

    font-weight:800;

    height:38px;

    border-bottom:
        1px solid
        #b7c8d8;
}


.report-net .report-label {

    text-align:right;
}


/* =========================================================
   EXPENSE CATEGORY
========================================================= */

.expense-category {

    text-transform:uppercase;
}


/* =========================================================
   TRANSACTION TABLE
========================================================= */

.transaction-card {

    width:100%;
}


.transaction-type-sale {

    color:#198754;

    font-weight:700;
}


.transaction-type-purchase {

    color:#d97706;

    font-weight:700;
}


.transaction-type-expense {

    color:#dc3545;

    font-weight:700;
}


/* =========================================================
   PRINT COPY
========================================================= */

.print-copy {

    display:none;
}


/* =========================================================
   PRINT
========================================================= */

@media print {

    @page {

        size:8.5in 13in;

        margin:0;
    }


    html,
    body {

        width:8.5in !important;

        min-width:8.5in !important;

        height:13in !important;

        margin:0 !important;

        padding:0 !important;

        background:#fff !important;
    }


    body * {

        visibility:hidden !important;
    }


    .print-copy,
    .print-copy * {

        visibility:visible !important;
    }


    .print-copy {

        display:block !important;

        position:absolute !important;

        left:0 !important;

        top:0 !important;

        width:8.5in !important;
    }


    .print-report {

        width:8.5in !important;

        height:auto !important;

        page-break-after:avoid !important;
    }


    .print-report .report-paper {

        width:8.5in !important;

        max-width:8.5in !important;

        min-height:5.9in !important;

        height:auto !important;

        margin:0 !important;

        padding:0.30in !important;

        box-sizing:border-box !important;

        border:1px solid #777 !important;

        box-shadow:none !important;

        background:#fff !important;

        overflow:hidden !important;
    }


    .print-separator {

        display:block !important;

        width:8.5in !important;

        height:0.25in !important;

        border-bottom:
            1px dashed
            #777 !important;

        page-break-after:avoid !important;
    }


    .report-company {

        font-size:27px !important;

        padding:
            8px
            5px
            5px !important;
    }


    .report-main-title {

        font-size:20px !important;

        padding:
            6px
            5px !important;
    }


    .report-period {

        font-size:14px !important;

        padding:
            4px
            5px
            8px !important;
    }


    .report-table {

        width:100% !important;

        table-layout:fixed !important;

        border-collapse:collapse !important;
    }


    .report-table td {

        font-size:12px !important;

        height:25px !important;

        padding:
            3px
            8px !important;

        line-height:1.15 !important;
    }


    .report-label {

        width:68% !important;

        text-align:right !important;

        padding-right:12px !important;
    }


    .report-amount {

        width:32% !important;

        text-align:right !important;

        white-space:nowrap !important;
    }


    .report-section td {

        height:40px !important;

        padding-top:15px !important;

        font-size:12px !important;

        font-weight:800 !important;
    }


    .report-total td {

        height:27px !important;

        font-weight:800 !important;

        border-bottom:
            3px double
            #000 !important;
    }


    .report-spacer td {

        height:28px !important;

        border-bottom:0 !important;
    }


    .report-gross td {

        height:42px !important;

        font-size:13px !important;

        font-weight:800 !important;

        border-bottom:
            3px double
            #000 !important;
    }


    .report-net td {

        height:45px !important;

        font-size:13px !important;

        font-weight:800 !important;

        background:#cfe2f3 !important;
    }


    .report-page > .d-flex,
    .report-controls,
    .report-page > .report-paper,
    .transaction-card,
    header,
    footer,
    nav,
    aside,
    .sidebar {

        display:none !important;
    }
}


/* =========================================================
   MOBILE
========================================================= */

@media(max-width:768px) {

    .report-page {

        padding:
            0
            8px
            20px;
    }


    .report-paper {

        max-width:100%;
    }


    .report-company {

        font-size:21px;
    }


    .report-main-title {

        font-size:16px;
    }


    .report-period {

        font-size:12px;
    }


    .report-table td {

        font-size:11px;
    }
}

</style>


<div class="report-page">


<!-- =========================================================
     PAGE CONTROLS
========================================================= -->

<div class="d-flex justify-content-between align-items-center mb-3">

    <div>

        <h2 class="mb-0">
            Reports
        </h2>

        <div class="text-muted small">

            Branch:

            <strong>

                <?=htmlspecialchars(
                    $branchName
                )?>

            </strong>

        </div>

    </div>


    <button
        type="button"
        onclick="window.print()"
        class="btn btn-outline-dark"
    >

        <i class="fa-solid fa-print me-1"></i>

        Print Original + Duplicate

    </button>

</div>


<!-- =========================================================
     FILTER
========================================================= -->

<form
    method="get"
    class="report-controls"
>

    <input
        type="hidden"
        name="branch"
        value="<?=htmlspecialchars(
            (string)$branch
        )?>"
    >


    <div class="row g-2 align-items-end">


        <div class="col-md-4">

            <label>
                From
            </label>

            <input
                type="date"
                name="from"
                class="form-control"
                value="<?=htmlspecialchars(
                    $from
                )?>"
            >

        </div>


        <div class="col-md-4">

            <label>
                To
            </label>

            <input
                type="date"
                name="to"
                class="form-control"
                value="<?=htmlspecialchars(
                    $to
                )?>"
            >

        </div>


        <div class="col-md-4">

            <button
                type="submit"
                class="btn btn-dark w-100"
            >

                <i class="fa-solid fa-chart-column me-1"></i>

                Generate Report

            </button>

        </div>

    </div>

</form>


<!-- =========================================================
     SCREEN REPORT
========================================================= -->

<div class="report-paper">


    <div class="font-green">

        VERDIVIEW RESTAURANT INC.

    </div>


    <div class="report-company">

        <?=htmlspecialchars(
            $branchName
        )?>

    </div>


    <div class="report-main-title">

        WEEKLY REPORT

    </div>


    <div class="report-period">

        <?=htmlspecialchars(
            $weekLabel
        )?>

    </div>


    <table class="report-table">

        <tbody>


        <!-- REVENUE -->

        <tr>

            <td class="report-label">
                FOOD SALES
            </td>

            <td class="report-amount">

                <?=number_format(
                    $foodSales,
                    2
                )?>

            </td>

        </tr>


        <tr class="report-total">

            <td class="report-label">
                TOTAL REVENUE
            </td>

            <td class="report-amount">

                <?=number_format(
                    $totalRevenue,
                    2
                )?>

            </td>

        </tr>


        <tr class="report-spacer">
            <td></td>
            <td></td>
        </tr>


        <!-- COGS -->

        <tr class="report-section">

            <td colspan="2">

                COST OF GOODS SOLD

            </td>

        </tr>


        <tr>

            <td class="report-label">
                BEGINNING INVENTORY
            </td>

            <td class="report-amount">

                <?=number_format(
                    $beginningInventory,
                    2
                )?>

            </td>

        </tr>


        <tr>

            <td class="report-label">
                PURCHASES
            </td>

            <td class="report-amount">

                <?=number_format(
                    $purchases,
                    2
                )?>

            </td>

        </tr>


        <tr>

            <td class="report-label">
                GOODS AVAILABLE
            </td>

            <td class="report-amount">

                <?=number_format(
                    $goodsAvailable,
                    2
                )?>

            </td>

        </tr>


        <tr>

            <td class="report-label">
                LESS: ENDING INVENTORY
            </td>

            <td class="report-amount">

                <?=number_format(
                    $endingInventory,
                    2
                )?>

            </td>

        </tr>


        <tr class="report-total">

            <td class="report-label">
                TOTAL COGS
            </td>

            <td class="report-amount">

                <?=number_format(
                    $totalCOGS,
                    2
                )?>

            </td>

        </tr>


        <tr class="report-spacer">
            <td></td>
            <td></td>
        </tr>


        <!-- GROSS PROFIT -->

        <tr class="report-gross">

            <td class="report-label">
                GROSS PROFIT
            </td>

            <td class="report-amount">

                <?=number_format(
                    $grossProfit,
                    2
                )?>

            </td>

        </tr>


        <tr class="report-spacer">
            <td></td>
            <td></td>
        </tr>


        <!-- OPERATING EXPENSE -->

        <tr class="report-section">

            <td colspan="2">

                OPERATING EXPENSE

            </td>

        </tr>


        <?php if ($operatingExpenses): ?>

            <?php foreach (
                $operatingExpenses
                as $category => $amount
            ): ?>

            <tr>

                <td class="
                    report-label
                    expense-category
                ">

                    <?=htmlspecialchars(
                        $category
                    )?>

                </td>

                <td class="report-amount">

                    <?=number_format(
                        $amount,
                        2
                    )?>

                </td>

            </tr>

            <?php endforeach; ?>

        <?php else: ?>

            <tr>

                <td class="report-label">

                    NO OPERATING EXPENSE

                </td>

                <td class="report-amount">

                    0.00

                </td>

            </tr>

        <?php endif; ?>


        <tr class="report-total">

            <td class="report-label">

                TOTAL OPERATING EXPENSE

            </td>

            <td class="report-amount">

                <?=number_format(
                    $totalOperatingExpense,
                    2
                )?>

            </td>

        </tr>


        <tr class="report-spacer">
            <td></td>
            <td></td>
        </tr>


        <!-- NET INCOME -->

        <tr class="report-net">

            <td class="report-label">

                NET INCOME

            </td>

            <td class="report-amount">

                <?=number_format(
                    $netIncome,
                    2
                )?>

            </td>

        </tr>


        </tbody>

    </table>

</div>


<!-- =========================================================
     PRINT ONLY
========================================================= -->

<div class="print-copy">


    <!-- =====================================================
         ORIGINAL COPY
    ====================================================== -->

    <div class="print-report">

        <div class="report-paper">

            <div class="copy-label">

                ORIGINAL COPY

            </div>


            <div class="report-company">

                VERDIVIEW RESTAURANT INC.

            </div>


            <div class="report-main-title">

                <?=htmlspecialchars(
                    $branchName
                )?>

            </div>


            <div class="report-period">

                WEEKLY REPORT -
                <?=htmlspecialchars(
                    $weekLabel
                )?>

            </div>


            <table class="report-table">

                <tbody>

                <tr>

                    <td class="report-label">
                        FOOD SALES
                    </td>

                    <td class="report-amount">

                        <?=number_format(
                            $foodSales,
                            2
                        )?>

                    </td>

                </tr>


                <tr class="report-total">

                    <td class="report-label">
                        TOTAL REVENUE
                    </td>

                    <td class="report-amount">

                        <?=number_format(
                            $totalRevenue,
                            2
                        )?>

                    </td>

                </tr>


                <tr class="report-spacer">
                    <td></td>
                    <td></td>
                </tr>


                <tr class="report-section">

                    <td colspan="2">

                        COST OF GOODS SOLD

                    </td>

                </tr>


                <tr>

                    <td class="report-label">
                        BEGINNING INVENTORY
                    </td>

                    <td class="report-amount">

                        <?=number_format(
                            $beginningInventory,
                            2
                        )?>

                    </td>

                </tr>


                <tr>

                    <td class="report-label">
                        PURCHASES
                    </td>

                    <td class="report-amount">

                        <?=number_format(
                            $purchases,
                            2
                        )?>

                    </td>

                </tr>


                <tr>

                    <td class="report-label">
                        GOODS AVAILABLE
                    </td>

                    <td class="report-amount">

                        <?=number_format(
                            $goodsAvailable,
                            2
                        )?>

                    </td>

                </tr>


                <tr>

                    <td class="report-label">
                        LESS: ENDING INVENTORY
                    </td>

                    <td class="report-amount">

                        <?=number_format(
                            $endingInventory,
                            2
                        )?>

                    </td>

                </tr>


                <tr class="report-total">

                    <td class="report-label">
                        TOTAL COGS
                    </td>

                    <td class="report-amount">

                        <?=number_format(
                            $totalCOGS,
                            2
                        )?>

                    </td>

                </tr>


                <tr class="report-spacer">
                    <td></td>
                    <td></td>
                </tr>


                <tr class="report-gross">

                    <td class="report-label">
                        GROSS PROFIT
                    </td>

                    <td class="report-amount">

                        <?=number_format(
                            $grossProfit,
                            2
                        )?>

                    </td>

                </tr>


                <tr class="report-spacer">
                    <td></td>
                    <td></td>
                </tr>


                <tr class="report-section">

                    <td colspan="2">

                        OPERATING EXPENSE

                    </td>

                </tr>


                <?php if ($operatingExpenses): ?>

                    <?php foreach (
                        $operatingExpenses
                        as $category => $amount
                    ): ?>

                    <tr>

                        <td class="
                            report-label
                            expense-category
                        ">

                            <?=htmlspecialchars(
                                $category
                            )?>

                        </td>

                        <td class="report-amount">

                            <?=number_format(
                                $amount,
                                2
                            )?>

                        </td>

                    </tr>

                    <?php endforeach; ?>

                <?php else: ?>

                    <tr>

                        <td class="report-label">

                            NO OPERATING EXPENSE

                        </td>

                        <td class="report-amount">

                            0.00

                        </td>

                    </tr>

                <?php endif; ?>


                <tr class="report-total">

                    <td class="report-label">

                        TOTAL OPERATING EXPENSE

                    </td>

                    <td class="report-amount">

                        <?=number_format(
                            $totalOperatingExpense,
                            2
                        )?>

                    </td>

                </tr>


                <tr class="report-spacer">
                    <td></td>
                    <td></td>
                </tr>


                <tr class="report-net">

                    <td class="report-label">

                        NET INCOME

                    </td>

                    <td class="report-amount">

                        <?=number_format(
                            $netIncome,
                            2
                        )?>

                    </td>

                </tr>


                </tbody>

            </table>

        </div>

    </div>


    <!-- =====================================================
         CUT LINE
    ====================================================== -->

    <div class="print-separator"></div>




</div>


<!-- =========================================================
     TRANSACTION DETAILS
========================================================= -->

<div class="card shadow-sm mt-4 transaction-card">

    <div class="card-header fw-bold">

        <i class="fa-solid fa-list me-1"></i>

        Transaction Details

    </div>


    <div class="table-responsive">

        <table class="table table-striped table-hover mb-0">

            <thead>

                <tr>

                    <th>
                        Date
                    </th>

                    <th>
                        Branch
                    </th>

                    <th>
                        Type
                    </th>

                    <th>
                        Reference
                    </th>

                    <th>
                        Description
                    </th>

                    <th class="text-end">
                        Amount
                    </th>

                </tr>

            </thead>


            <tbody>

            <?php if ($rows): ?>

                <?php foreach (
                    $rows as $r
                ): ?>

                <tr>

                    <td>

                        <?=htmlspecialchars(
                            (string)(
                                $r['dt']
                                ?? ''
                            )
                        )?>

                    </td>


                    <td>

                        <?=htmlspecialchars(
                            (string)(
                                $r['branch_name']
                                ?? ''
                            )
                        )?>

                    </td>


                    <td>

                        <?php

                        $type =
                            $r['type']
                            ?? '';

                        if (
                            $type === 'Sales'
                        ):

                        ?>

                            <span class="
                                transaction-type-sale
                            ">

                                Sales

                            </span>

                        <?php

                        elseif (
                            $type === 'Purchase'
                        ):

                        ?>

                            <span class="
                                transaction-type-purchase
                            ">

                                Purchase

                            </span>

                        <?php else: ?>

                            <span class="
                                transaction-type-expense
                            ">

                                Expense

                            </span>

                        <?php endif; ?>

                    </td>


                    <td>

                        <?php

                        if (
                            $type === 'Sales'
                        ) {

                            echo htmlspecialchars(
                                (string)(
                                    $r['reference_no']
                                    ?? ''
                                )
                            );

                        } elseif (
                            $type === 'Purchase'
                        ) {

                            echo htmlspecialchars(
                                (string)(
                                    $r['invoice_no']
                                    ?? ''
                                )
                            );

                        } else {

                            echo htmlspecialchars(
                                (string)(
                                    $r['reference_no']
                                    ?? ''
                                )
                            );
                        }

                        ?>

                    </td>


                    <td>

                        <?php

                        $description =
                            trim(
                                (string)(
                                    $r['description']
                                    ?? ''
                                )
                            );


                        if (
                            $type === 'Sales'
                            &&
                            !empty(
                                $r['customer']
                            )
                        ) {

                            $customer =
                                trim(
                                    (string)(
                                        $r['customer']
                                    )
                                );

                            $description =
                                $customer
                                . (
                                    $description !== ''
                                        ? ' - ' . $description
                                        : ''
                                );
                        }


                        if (
                            $type === 'Purchase'
                            &&
                            !empty(
                                $r['supplier']
                            )
                        ) {

                            $supplier =
                                trim(
                                    (string)(
                                        $r['supplier']
                                    )
                                );

                            $description =
                                $supplier
                                . (
                                    $description !== ''
                                        ? ' - ' . $description
                                        : ''
                                );
                        }


                        if (
                            $type === 'Expense'
                            &&
                            !empty(
                                $r['category']
                            )
                        ) {

                            $category =
                                trim(
                                    (string)(
                                        $r['category']
                                    )
                                );

                            $description =
                                $category
                                . (
                                    $description !== ''
                                        ? ' - ' . $description
                                        : ''
                                );
                        }


                        echo htmlspecialchars(
                            $description
                        );

                        ?>

                    </td>


                    <td class="text-end">

                        ₱<?=number_format(
                            (float)(
                                $r['amount']
                                ?? 0
                            ),
                            2
                        )?>

                    </td>

                </tr>

                <?php endforeach; ?>

            <?php else: ?>

                <tr>

                    <td
                        colspan="6"
                        class="
                            text-center
                            text-muted
                            py-4
                        "
                    >

                        No transactions found.

                    </td>

                </tr>

            <?php endif; ?>

            </tbody>

        </table>

    </div>

</div>


</div>


<?php

include "footer.php";

?>
```
