<?php

/* =========================================================
   AUTHENTICATION
========================================================= */

require_once "auth.php";
require_once "config.php";

$pageTitle = "Dashboard";


/* =========================================================
   USER ROLE
========================================================= */

$currentUserRole = trim((string)(
    $_SESSION['role']
    ?? $_SESSION['user_role']
    ?? $_SESSION['position']
    ?? ''
));

$normalizedRole = strtolower(trim($currentUserRole));

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
   BRANCH
========================================================= */

$branch = (int)(
    $_GET['branch']
    ?? $_SESSION['branch_id']
    ?? 0
);

if ($branch > 0) {
    $_SESSION['branch_id'] = $branch;
}


/* =========================================================
   VIEWER SEARCH
========================================================= */

$viewerSalesSearch = trim(
    $_GET['viewer_search'] ?? ''
);


/* =========================================================
   BRANCH NAME
========================================================= */

$branchName = "All Branches";

if ($branch > 0) {

    $stmt = $pdo->prepare("
        SELECT branch_name
        FROM branches
        WHERE id = ?
          AND is_active = 1
        LIMIT 1
    ");

    $stmt->execute([$branch]);

    $foundBranch = $stmt->fetchColumn();

    if ($foundBranch) {

        $branchName = $foundBranch;

    } else {

        $branch = 0;

        unset($_SESSION['branch_id']);
    }
}


/* =========================================================
   DATE FILTER
========================================================= */

$defaultFrom = date('Y-m-01');
$defaultTo   = date('Y-m-d');

$dateFrom = $_GET['date_from'] ?? $defaultFrom;
$dateTo   = $_GET['date_to']   ?? $defaultTo;


/* =========================================================
   VALIDATE DATE FROM
========================================================= */

$dateFromObj = DateTime::createFromFormat(
    'Y-m-d',
    $dateFrom
);

if (
    !$dateFromObj ||
    $dateFromObj->format('Y-m-d') !== $dateFrom
) {

    $dateFrom = $defaultFrom;
}


/* =========================================================
   VALIDATE DATE TO
========================================================= */

$dateToObj = DateTime::createFromFormat(
    'Y-m-d',
    $dateTo
);

if (
    !$dateToObj ||
    $dateToObj->format('Y-m-d') !== $dateTo
) {

    $dateTo = $defaultTo;
}


/* =========================================================
   IF FROM > TO
========================================================= */

if ($dateFrom > $dateTo) {

    $temp = $dateFrom;

    $dateFrom = $dateTo;

    $dateTo = $temp;
}


/* =========================================================
   SQL DATE RANGE
========================================================= */

$filterStart = $dateFrom;

$filterEnd = (
    new DateTime($dateTo)
)
    ->modify('+1 day')
    ->format('Y-m-d');


/* =========================================================
   DISPLAY LABEL
========================================================= */

$filterLabel =
    date('F d, Y', strtotime($dateFrom))
    . " - "
    .
    date('F d, Y', strtotime($dateTo));


/* =========================================================
   HELPER
========================================================= */

function scalarQuery(
    PDO $pdo,
    string $sql,
    array $params = []
) {

    $stmt = $pdo->prepare($sql);

    $stmt->execute($params);

    $result = $stmt->fetchColumn();

    return is_numeric($result)
        ? (float)$result
        : 0.0;
}


/* =========================================================
   BRANCH FILTER
========================================================= */

$branchFilter = '';
$branchParams = [];

if ($branch > 0) {

    $branchFilter = "
        AND branch_id = ?
    ";

    $branchParams = [
        $branch
    ];
}


/* =========================================================
   TOTAL SALES
========================================================= */

$totalSales = scalarQuery(
    $pdo,
    "
    SELECT COALESCE(
        SUM(
            COALESCE(amount, 0)
            + COALESCE(\"service_charge\", 0)
            - COALESCE(\"discount\", 0)
        ),
        0
    )
    FROM sales
    WHERE sale_date >= ?
      AND sale_date < ?
      $branchFilter
    ",
    array_merge(
        [
            $filterStart,
            $filterEnd
        ],
        $branchParams
    )
);


/* =========================================================
   TOTAL PURCHASES
========================================================= */

$totalPurchases = scalarQuery(
    $pdo,
    "
    SELECT COALESCE(
        SUM(
            COALESCE(total_amount, 0)
        ),
        0
    )
    FROM purchases
    WHERE purchase_date >= ?
      AND purchase_date < ?
      $branchFilter
    ",
    array_merge(
        [
            $filterStart,
            $filterEnd
        ],
        $branchParams
    )
);


/* =========================================================
   TOTAL OPERATING EXPENSES
========================================================= */

$totalExpenses = scalarQuery(
    $pdo,
    "
    SELECT COALESCE(
        SUM(
            COALESCE(amount, 0)
        ),
        0
    )
    FROM expenses
    WHERE expense_date >= ?
      AND expense_date < ?
      $branchFilter
    ",
    array_merge(
        [
            $filterStart,
            $filterEnd
        ],
        $branchParams
    )
);


/* =========================================================
   COUNTS
========================================================= */

$countSales = scalarQuery(
    $pdo,
    "
    SELECT COUNT(*)
    FROM sales
    WHERE sale_date >= ?
      AND sale_date < ?
      $branchFilter
    ",
    array_merge(
        [
            $filterStart,
            $filterEnd
        ],
        $branchParams
    )
);


$countPurchases = scalarQuery(
    $pdo,
    "
    SELECT COUNT(*)
    FROM purchases
    WHERE purchase_date >= ?
      AND purchase_date < ?
      $branchFilter
    ",
    array_merge(
        [
            $filterStart,
            $filterEnd
        ],
        $branchParams
    )
);


$countExpenses = scalarQuery(
    $pdo,
    "
    SELECT COUNT(*)
    FROM expenses
    WHERE expense_date >= ?
      AND expense_date < ?
      $branchFilter
    ",
    array_merge(
        [
            $filterStart,
            $filterEnd
        ],
        $branchParams
    )
);


/* =========================================================
   VIEWER UNPAID SALES
========================================================= */

$viewerUnpaidSales = [];

$totalViewerUnpaid = 0;

$countViewerUnpaid = 0;


/* =========================================================
   GET UNPAID SALES
========================================================= */

$sqlViewerUnpaid = "
    SELECT

        s.id,

        s.sale_date,

        s.reference_no,

        s.customer,

        s.\"pax\" AS pax,

        s.\"discount\" AS discount,

        s.description,

        s.amount,

        s.\"service_charge\" AS service_charge,

        s.remarks,

        s.notes,

        s.accounts_receivable,

        b.branch_name

    FROM sales s

    LEFT JOIN branches b
        ON b.id = s.branch_id

    WHERE s.sale_date >= ?
      AND s.sale_date < ?

      AND UPPER(
            TRIM(
                COALESCE(s.remarks, '')
            )
          ) = 'UNPAID'

      AND COALESCE(
            s.accounts_receivable,
            0
          ) > 0
";


$viewerUnpaidParams = [
    $filterStart,
    $filterEnd
];


/* =========================================================
   VIEWER SEARCH
========================================================= */

if ($viewerSalesSearch !== '') {

    $sqlViewerUnpaid .= "
        AND (
            s.customer ILIKE ?
            OR s.reference_no ILIKE ?
            OR s.description ILIKE ?
            OR s.remarks ILIKE ?
            OR s.notes ILIKE ?
            OR b.branch_name ILIKE ?
        )
    ";

    $searchLike =
        '%' . $viewerSalesSearch . '%';

    $viewerUnpaidParams[] = $searchLike;
    $viewerUnpaidParams[] = $searchLike;
    $viewerUnpaidParams[] = $searchLike;
    $viewerUnpaidParams[] = $searchLike;
    $viewerUnpaidParams[] = $searchLike;
    $viewerUnpaidParams[] = $searchLike;
}


/* =========================================================
   BRANCH FILTER
========================================================= */

if ($branch > 0) {

    $sqlViewerUnpaid .= "
        AND s.branch_id = ?
    ";

    $viewerUnpaidParams[] = $branch;
}


/* =========================================================
   ORDER
========================================================= */

$sqlViewerUnpaid .= "

    ORDER BY
        s.sale_date DESC,
        s.id DESC

    LIMIT 500
";


/* =========================================================
   EXECUTE VIEWER UNPAID
========================================================= */

try {

    $stmtViewerUnpaid =
        $pdo->prepare(
            $sqlViewerUnpaid
        );

    $stmtViewerUnpaid->execute(
        $viewerUnpaidParams
    );

    $viewerUnpaidSales =
        $stmtViewerUnpaid->fetchAll(
            PDO::FETCH_ASSOC
        );


    foreach (
        $viewerUnpaidSales
        as &$row
    ) {

        $row['_computed_unpaid'] =
            max(
                0,
                (float)(
                    $row['accounts_receivable']
                    ?? 0
                )
            );


        $row['_computed_total'] =
            max(
                0,

                (float)(
                    $row['amount']
                    ?? 0
                )

                +

                (float)(
                    $row['service_charge']
                    ?? 0
                )

                -

                (float)(
                    $row['discount']
                    ?? 0
                )
            );
    }

    unset($row);


    $totalViewerUnpaid = 0;


    foreach (
        $viewerUnpaidSales
        as $row
    ) {

        $totalViewerUnpaid +=
            (float)(
                $row['_computed_unpaid']
                ?? 0
            );
    }


    $countViewerUnpaid =
        count($viewerUnpaidSales);


} catch (Throwable $e) {

    $viewerUnpaidSales = [];

    $totalViewerUnpaid = 0;

    $countViewerUnpaid = 0;
}


/* =========================================================
   HEADER
========================================================= */

include "header.php";

?>


<!-- =======================================================
     DASHBOARD HEADER
======================================================= -->

<div class="dashboard-header mb-3">

    <div>

        <div class="dashboard-title fw-bold fs-4">
            Dashboard
        </div>

        <div class="dashboard-subtitle text-muted">

            <i class="fa-solid fa-location-dot me-1"></i>

            <?=htmlspecialchars($branchName)?>

        </div>

    </div>


    <button
        type="button"
        class="dashboard-header-filter"
        data-bs-toggle="modal"
        data-bs-target="#dateFilterModal"
        title="Filter Dashboard"
    >

        <i class="fa-solid fa-filter"></i>

        <span>Filter</span>

    </button>

</div>


<!-- =======================================================
     DATE FILTER MODAL
======================================================= -->

<div
    class="modal fade"
    id="dateFilterModal"
    tabindex="-1"
    aria-labelledby="dateFilterModalLabel"
    aria-hidden="true"
>

    <div class="modal-dialog modal-dialog-centered">

        <div class="modal-content dashboard-date-modal">


            <div class="modal-header">

                <div>

                    <h5
                        class="modal-title fw-bold"
                        id="dateFilterModalLabel"
                    >

                        <i class="fa-solid fa-calendar-days me-2"></i>

                        Filter Dashboard

                    </h5>

                    <div class="small text-muted mt-1">

                        Select the date range you want to view.

                    </div>

                </div>


                <button
                    type="button"
                    class="btn-close"
                    data-bs-dismiss="modal"
                    aria-label="Close"
                ></button>

            </div>


            <form
                method="get"
                action="index.php"
            >

                <div class="modal-body">


                    <?php if ($branch > 0): ?>

                        <input
                            type="hidden"
                            name="branch"
                            value="<?=$branch?>"
                        >

                    <?php endif; ?>


                    <?php if (
                        $isViewer &&
                        $viewerSalesSearch !== ''
                    ): ?>

                        <input
                            type="hidden"
                            name="viewer_search"
                            value="<?=htmlspecialchars(
                                $viewerSalesSearch
                            )?>"
                        >

                    <?php endif; ?>


                    <div class="date-filter-box">


                        <div class="mb-3">

                            <label
                                for="modalDateFrom"
                                class="form-label fw-semibold"
                            >

                                <i class="fa-solid fa-calendar-day me-1"></i>

                                Date From

                            </label>


                            <input
                                type="date"
                                id="modalDateFrom"
                                name="date_from"
                                class="form-control form-control-lg"
                                value="<?=htmlspecialchars(
                                    $dateFrom
                                )?>"
                                required
                            >

                        </div>


                        <div class="mb-3">

                            <label
                                for="modalDateTo"
                                class="form-label fw-semibold"
                            >

                                <i class="fa-solid fa-calendar-check me-1"></i>

                                Date To

                            </label>


                            <input
                                type="date"
                                id="modalDateTo"
                                name="date_to"
                                class="form-control form-control-lg"
                                value="<?=htmlspecialchars(
                                    $dateTo
                                )?>"
                                required
                            >

                        </div>


                        <div class="current-date-filter">

                            <div class="current-date-filter-icon">

                                <i class="fa-solid fa-circle-info"></i>

                            </div>


                            <div>

                                <div class="small text-muted">

                                    Current selected range

                                </div>


                                <strong>

                                    <?=htmlspecialchars(
                                        $filterLabel
                                    )?>

                                </strong>

                            </div>

                        </div>

                    </div>

                </div>


                <div class="modal-footer">


                    <button
                        type="button"
                        class="btn btn-outline-secondary"
                        data-bs-dismiss="modal"
                    >

                        Cancel

                    </button>


                    <a
                        href="index.php<?= $branch
                            ? '?branch=' . urlencode(
                                (string)$branch
                            )
                            : ''
                        ?>"
                        class="btn btn-outline-danger"
                    >

                        <i class="fa-solid fa-rotate-left me-1"></i>

                        Reset

                    </a>


                    <button
                        type="submit"
                        class="btn btn-primary"
                    >

                        <i class="fa-solid fa-filter me-1"></i>

                        Apply Filter

                    </button>

                </div>

            </form>

        </div>

    </div>

</div>


<!-- =======================================================
     VIEWER DASHBOARD
======================================================= -->

<?php if ($isViewer): ?>


<style>

.viewer-dashboard-grid {

    display:grid;

    grid-template-columns:
        repeat(3, minmax(0, 1fr));

    gap:20px;

    margin-bottom:20px;
}


.viewer-stat-card {

    background:#fff;

    border-radius:16px;

    padding:24px;

    box-shadow:
        0 4px 18px rgba(0,0,0,.06);

    border:1px solid #eef1f5;

    min-height:180px;
}


.viewer-stat-icon {

    width:48px;

    height:48px;

    border-radius:12px;

    display:flex;

    align-items:center;

    justify-content:center;

    font-size:20px;

    margin-bottom:16px;
}


.viewer-label {

    color:#697586;

    font-size:14px;

    font-weight:600;

    margin-bottom:8px;
}


.viewer-value {

    font-size:30px;

    font-weight:800;

    color:#1f2937;
}


.viewer-foot {

    color:#8993a1;

    font-size:12px;

    margin-top:10px;
}


.viewer-green {

    color:#20b878;

    background:rgba(32,184,120,.10);
}


.viewer-orange {

    color:#f29420;

    background:rgba(242,148,32,.10);
}


.viewer-purple {

    color:#7b45e6;

    background:rgba(123,69,230,.10);
}


.viewer-unpaid-panel {

    background:#fff;

    border-radius:16px;

    border:1px solid #eef1f5;

    box-shadow:
        0 4px 18px rgba(0,0,0,.06);

    overflow:hidden;
}


.viewer-unpaid-header {

    padding:18px 20px;

    border-bottom:1px solid #eef1f5;

    display:flex;

    align-items:center;

    justify-content:space-between;

    gap:15px;

    flex-wrap:wrap;
}


.viewer-unpaid-title {

    font-size:17px;

    font-weight:700;

    color:#273142;
}


.viewer-unpaid-title i {

    color:#dc3545;

    margin-right:7px;
}


.viewer-unpaid-total {

    color:#dc3545;

    font-size:18px;

    font-weight:800;
}


.viewer-unpaid-meta {

    font-size:12px;

    color:#7d8795;

    margin-top:3px;
}


.viewer-unpaid-table-wrap {

    width:100%;

    overflow-x:auto;
}


.viewer-sales-search {

    width:100%;

    padding:15px 20px 0;

    max-width:100%;
}


.viewer-sales-search .input-group {

    width:100%;

    box-shadow:
        0 3px 12px rgba(0,0,0,.06);

    border-radius:10px;

    overflow:hidden;
}


.viewer-sales-search .form-control {

    min-height:42px;
}


.viewer-sales-search .input-group-text {

    background:#fff;

    border-right:0;
}


.viewer-sales-search .form-control {

    border-left:0;
}


.viewer-unpaid-table {

    width:100%;

    border-collapse:collapse;

    min-width:1050px;
}


.viewer-unpaid-table th {

    background:#f8f9fb;

    color:#697586;

    font-size:12px;

    font-weight:700;

    text-transform:uppercase;

    letter-spacing:.3px;

    padding:13px 15px;

    border-bottom:1px solid #eef1f5;

    white-space:nowrap;
}


.viewer-unpaid-table td {

    padding:14px 15px;

    border-bottom:1px solid #f0f2f5;

    font-size:13px;

    color:#374151;

    vertical-align:middle;
}


.viewer-unpaid-table tbody tr:hover {

    background:#fafbfc;
}


.viewer-unpaid-amount {

    color:#dc3545 !important;

    font-weight:800;

    white-space:nowrap;
}


.viewer-unpaid-customer {

    font-weight:600;

    color:#273142 !important;
}


.viewer-no-unpaid {

    padding:45px 20px;

    text-align:center;

    color:#8993a1;
}


.viewer-no-unpaid i {

    font-size:34px;

    margin-bottom:12px;

    color:#20b878;
}


.viewer-unpaid-footer {

    padding:16px 20px;

    border-top:1px solid #eef1f5;

    display:flex;

    justify-content:space-between;

    align-items:center;

    gap:15px;

    flex-wrap:wrap;

    background:#fafbfc;
}


.viewer-unpaid-footer-label {

    color:#697586;

    font-size:13px;
}


.viewer-unpaid-footer-total {

    color:#dc3545;

    font-size:20px;

    font-weight:800;
}


.viewer-unpaid-badge {

    display:inline-block;

    padding:5px 9px;

    border-radius:8px;

    background:rgba(220,53,69,.10);

    color:#dc3545;

    font-weight:700;

    font-size:12px;
}


.dashboard-header-filter {

    border:1px solid #dfe4ea;

    background:#fff;

    color:#0d6efd;

    border-radius:9px;

    min-height:40px;

    padding:8px 14px;

    display:flex;

    align-items:center;

    justify-content:center;

    gap:7px;

    font-size:13px;

    font-weight:700;

    cursor:pointer;

    transition:all .2s ease;
}


.dashboard-header-filter:hover {

    background:#0d6efd;

    color:#fff;

    border-color:#0d6efd;

    box-shadow:
        0 4px 12px rgba(13,110,253,.18);
}


.dashboard-date-modal {

    border:0;

    border-radius:16px;

    overflow:hidden;

    box-shadow:
        0 15px 45px rgba(0,0,0,.15);
}


.dashboard-date-modal .modal-header {

    padding:20px 22px;

    border-bottom:1px solid #eef1f5;

    background:#fff;
}


.dashboard-date-modal .modal-body {

    padding:22px;
}


.dashboard-date-modal .modal-footer {

    padding:15px 22px;

    border-top:1px solid #eef1f5;

    background:#fafbfc;
}


.date-filter-box {

    background:#f8f9fb;

    border:1px solid #eef1f5;

    border-radius:12px;

    padding:18px;
}


.date-filter-box .form-label {

    color:#374151;

    font-size:13px;
}


.date-filter-box .form-control {

    border-radius:9px;

    border-color:#dfe4ea;
}


.current-date-filter {

    display:flex;

    align-items:center;

    gap:10px;

    margin-top:15px;

    padding:11px 13px;

    border-radius:9px;

    background:#fff;

    border:1px solid #e8ebef;
}


.current-date-filter-icon {

    color:#0d6efd;

    font-size:16px;
}


.current-date-filter strong {

    color:#273142;

    font-size:13px;
}


@media (max-width:900px) {

    .viewer-dashboard-grid {

        grid-template-columns:1fr;
    }
}


@media (max-width:600px) {

    .viewer-unpaid-header {

        align-items:flex-start;
    }


    .dashboard-header-filter span {

        display:none;
    }


    .dashboard-header-filter {

        width:40px;

        padding:8px;
    }
}

</style>


<!-- =======================================================
     VIEWER TOTAL CARDS
======================================================= -->

<div class="viewer-dashboard-grid">


    <div class="viewer-stat-card">

        <div class="viewer-stat-icon viewer-green">

            <i class="fa-solid fa-cart-shopping"></i>

        </div>


        <div class="viewer-label">
            Total Sales
        </div>


        <div class="viewer-value">

            ₱<?=number_format(
                $totalSales,
                2
            )?>

        </div>


        <div class="viewer-foot">

            <?=htmlspecialchars(
                $filterLabel
            )?>

            ·

            <?=number_format(
                $countSales
            )?>

            transaction(s)

        </div>

    </div>


    <div class="viewer-stat-card">

        <div class="viewer-stat-icon viewer-orange">

            <i class="fa-solid fa-basket-shopping"></i>

        </div>


        <div class="viewer-label">
            Total Purchases
        </div>


        <div class="viewer-value">

            ₱<?=number_format(
                $totalPurchases,
                2
            )?>

        </div>


        <div class="viewer-foot">

            <?=htmlspecialchars(
                $filterLabel
            )?>

            ·

            <?=number_format(
                $countPurchases
            )?>

            purchase(s)

        </div>

    </div>


    <div class="viewer-stat-card">

        <div class="viewer-stat-icon viewer-purple">

            <i class="fa-solid fa-wallet"></i>

        </div>


        <div class="viewer-label">
            Operating Expenses
        </div>


        <div class="viewer-value">

            ₱<?=number_format(
                $totalExpenses,
                2
            )?>

        </div>


        <div class="viewer-foot">

            <?=htmlspecialchars(
                $filterLabel
            )?>

            ·

            <?=number_format(
                $countExpenses
            )?>

            expense(s)

        </div>

    </div>

</div>


<!-- =======================================================
     UNPAID SALES TABLE
======================================================= -->

<div class="viewer-unpaid-panel">


    <div class="viewer-unpaid-header">

        <div>

            <div class="viewer-unpaid-title">

                <i class="fa-solid fa-file-invoice-dollar"></i>

                Unpaid Sales Only

            </div>


            <div class="viewer-unpaid-meta">

                Sales transactions with unpaid balance

                ·

                <?=htmlspecialchars(
                    $filterLabel
                )?>

            </div>

        </div>


        <div style="text-align:right;">

            <div class="viewer-unpaid-total">

                ₱<?=number_format(
                    $totalViewerUnpaid,
                    2
                )?>

            </div>


            <div class="viewer-unpaid-meta">

                <?=$countViewerUnpaid?>

                unpaid transaction(s)

            </div>

        </div>

    </div>


    <form
        method="GET"
        action="index.php"
        class="viewer-sales-search mb-0"
    >


        <?php if ($branch > 0): ?>

            <input
                type="hidden"
                name="branch"
                value="<?=htmlspecialchars(
                    (string)$branch
                )?>"
            >

        <?php endif; ?>


        <input
            type="hidden"
            name="date_from"
            value="<?=htmlspecialchars(
                $dateFrom
            )?>"
        >


        <input
            type="hidden"
            name="date_to"
            value="<?=htmlspecialchars(
                $dateTo
            )?>"
        >


        <div class="input-group">

            <span class="input-group-text">

                <i class="fa-solid fa-magnifying-glass"></i>

            </span>


            <input
                type="search"
                name="viewer_search"
                value="<?=htmlspecialchars(
                    $viewerSalesSearch
                )?>"
                class="form-control"
                placeholder="Search customer, reference, description, notes, or branch..."
                autocomplete="off"
            >


            <button
                type="submit"
                class="btn btn-primary"
            >

                Search

            </button>


            <?php if ($viewerSalesSearch !== ''): ?>

                <a
                    href="index.php<?= $branch
                        ? '?branch=' .
                            urlencode(
                                (string)$branch
                            )
                        . '&date_from=' .
                            urlencode(
                                $dateFrom
                            )
                        . '&date_to=' .
                            urlencode(
                                $dateTo
                            )
                        : '?date_from=' .
                            urlencode(
                                $dateFrom
                            )
                        . '&date_to=' .
                            urlencode(
                                $dateTo
                            )
                    ?>"
                    class="btn btn-outline-secondary"
                >

                    Clear

                </a>

            <?php endif; ?>


        </div>

    </form>


    <?php if (!$viewerUnpaidSales): ?>


        <div class="viewer-no-unpaid">

            <i class="fa-solid fa-circle-check"></i>


            <div>

                <strong>
                    No unpaid sales found.
                </strong>

            </div>


            <div class="small mt-1">

                Walang unpaid transaction
                sa napiling filter.

            </div>

        </div>


    <?php else: ?>


        <div class="viewer-unpaid-table-wrap">

            <table class="viewer-unpaid-table">

                <thead>

                    <tr>

                        <th>Date</th>

                        <th>OR / Invoice No.</th>

                        <th>Customer</th>

                        <?php if ($branch == 0): ?>

                            <th>Branch</th>

                        <?php endif; ?>

                        <th>Description</th>

                        <th>Notes</th>

                        <th>Status</th>

                        <th class="text-end">
                            Total Amount
                        </th>

                        <th class="text-end">
                            Unpaid
                        </th>

                    </tr>

                </thead>


                <tbody>

                <?php foreach (
                    $viewerUnpaidSales
                    as $r
                ): ?>

                    <tr>


                        <td>

                            <?=htmlspecialchars(
                                date(
                                    'M d, Y',
                                    strtotime(
                                        $r['sale_date']
                                    )
                                )
                            )?>

                        </td>


                        <td>

                            <strong>

                                <?=htmlspecialchars(
                                    $r['reference_no']
                                    ?? ''
                                )?>

                            </strong>

                        </td>


                        <td class="viewer-unpaid-customer">

                            <?=htmlspecialchars(
                                $r['customer']
                                ?? 'Walk-in Customer'
                            )?>

                        </td>


                        <?php if ($branch == 0): ?>

                            <td>

                                <?=htmlspecialchars(
                                    $r['branch_name']
                                    ?? ''
                                )?>

                            </td>

                        <?php endif; ?>


                        <td>

                            <?=htmlspecialchars(
                                $r['description']
                                ?? '—'
                            )?>

                        </td>


                        <td>

                            <?=htmlspecialchars(
                                $r['notes']
                                ?? '—'
                            )?>

                        </td>


                        <td>

                            <span class="viewer-unpaid-badge">

                                UNPAID

                            </span>

                        </td>


                        <td class="text-end">

                            ₱<?=number_format(
                                (float)(
                                    $r['_computed_total']
                                    ?? 0
                                ),
                                2
                            )?>

                        </td>


                        <td class="text-end">

                            <span class="viewer-unpaid-badge">

                                ₱<?=number_format(
                                    (float)(
                                        $r['_computed_unpaid']
                                        ?? 0
                                    ),
                                    2
                                )?>

                            </span>

                        </td>


                    </tr>

                <?php endforeach; ?>

                </tbody>

            </table>

        </div>


        <div class="viewer-unpaid-footer">

            <div class="viewer-unpaid-footer-label">

                Showing

                <strong>
                    <?=$countViewerUnpaid?>
                </strong>

                unpaid transaction(s)

                <?php if ($viewerSalesSearch !== ''): ?>

                    for search:

                    <strong>

                        <?=htmlspecialchars(
                            $viewerSalesSearch
                        )?>

                    </strong>

                <?php endif; ?>

            </div>


            <div class="viewer-unpaid-footer-total">

                Total Unpaid:

                ₱<?=number_format(
                    $totalViewerUnpaid,
                    2
                )?>

            </div>

        </div>


    <?php endif; ?>


</div>


<?php else: ?>


<!-- =======================================================
     FULL DASHBOARD
======================================================= -->

<style>

.net-margin-filter-card {

    position:relative;
}


.net-margin-top {

    display:flex;

    align-items:flex-start;

    justify-content:space-between;

    gap:10px;

    margin-bottom:16px;
}


.net-margin-top .stat-icon {

    margin-bottom:0;
}


.dashboard-header-filter {

    border:1px solid #dfe4ea;

    background:#fff;

    color:#0d6efd;

    border-radius:9px;

    min-height:40px;

    padding:8px 14px;

    display:flex;

    align-items:center;

    justify-content:center;

    gap:7px;

    font-size:13px;

    font-weight:700;

    cursor:pointer;

    transition:all .2s ease;
}


.dashboard-header-filter:hover {

    background:#0d6efd;

    color:#fff;

    border-color:#0d6efd;

    box-shadow:
        0 4px 12px rgba(13,110,253,.18);
}


.net-margin-date {

    display:flex;

    align-items:center;

    gap:5px;

    margin-top:10px;

    color:#7d8795;

    font-size:11px;

    line-height:1.4;
}


.net-margin-date i {

    color:#0d6efd;
}


.dashboard-date-modal {

    border:0;

    border-radius:16px;

    overflow:hidden;

    box-shadow:
        0 15px 45px rgba(0,0,0,.15);
}


.dashboard-date-modal .modal-header {

    padding:20px 22px;

    border-bottom:1px solid #eef1f5;

    background:#fff;
}


.dashboard-date-modal .modal-body {

    padding:22px;
}


.dashboard-date-modal .modal-footer {

    padding:15px 22px;

    border-top:1px solid #eef1f5;

    background:#fafbfc;
}


.date-filter-box {

    background:#f8f9fb;

    border:1px solid #eef1f5;

    border-radius:12px;

    padding:18px;
}


.date-filter-box .form-label {

    color:#374151;

    font-size:13px;
}


.date-filter-box .form-control {

    border-radius:9px;

    border-color:#dfe4ea;
}


.date-filter-box .form-control:focus {

    border-color:#86b7fe;

    box-shadow:
        0 0 0 .2rem rgba(13,110,253,.10);
}


.current-date-filter {

    display:flex;

    align-items:center;

    gap:10px;

    margin-top:15px;

    padding:11px 13px;

    border-radius:9px;

    background:#fff;

    border:1px solid #e8ebef;
}


.current-date-filter-icon {

    color:#0d6efd;

    font-size:16px;
}


.current-date-filter strong {

    color:#273142;

    font-size:13px;
}


@media (max-width:900px) {

    .dashboard-grid {

        grid-template-columns:
            repeat(2, minmax(0, 1fr));
    }
}


@media (max-width:600px) {

    .dashboard-grid {

        grid-template-columns:1fr;
    }


    .dashboard-header-filter span {

        display:none;
    }


    .dashboard-header-filter {

        width:40px;

        padding:8px;
    }
}

</style>


<!-- =======================================================
     DASHBOARD CARDS
======================================================= -->

<div class="dashboard-grid">


    <!-- TOTAL SALES -->

    <div class="stat-card">

        <div class="stat-icon icon-green">

            <i class="fa-solid fa-cart-shopping"></i>

        </div>


        <div class="stat-label">
            Total Sales
        </div>


        <div class="stat-value value-green">

            ₱<?=number_format(
                $totalSales,
                2
            )?>

        </div>


        <div class="stat-foot">

            <?=htmlspecialchars(
                $filterLabel
            )?>

            ·

            <?=number_format(
                $countSales
            )?>

            transaction(s)

        </div>

    </div>


    <!-- TOTAL PURCHASES -->

    <div class="stat-card">

        <div class="stat-icon icon-orange">

            <i class="fa-solid fa-basket-shopping"></i>

        </div>


        <div class="stat-label">
            Total Purchases
        </div>


        <div class="stat-value value-orange">

            ₱<?=number_format(
                $totalPurchases,
                2
            )?>

        </div>


        <div class="stat-foot">

            <?=htmlspecialchars(
                $filterLabel
            )?>

            ·

            <?=number_format(
                $countPurchases
            )?>

            purchase(s)

        </div>

    </div>


    <!-- COGS -->

    <div class="stat-card">

        <div class="stat-icon icon-red">

            <i class="fa-solid fa-box-open"></i>

        </div>


        <div class="stat-label">
            Cost of Goods Sold
        </div>


        <div class="stat-value value-red">

            <?php

            $beginningInventory = scalarQuery(
                $pdo,
                "
                SELECT COALESCE(
                    beginning_inventory,
                    0
                )
                FROM inventory
                WHERE inventory_date <= ?
                $branchFilter
                ORDER BY
                    inventory_date DESC,
                    id DESC
                LIMIT 1
                ",
                array_merge(
                    [$dateFrom],
                    $branchParams
                )
            );


            $endingInventory = scalarQuery(
                $pdo,
                "
                SELECT COALESCE(
                    ending_inventory,
                    0
                )
                FROM inventory
                WHERE inventory_date <= ?
                $branchFilter
                ORDER BY
                    inventory_date DESC,
                    id DESC
                LIMIT 1
                ",
                array_merge(
                    [$dateTo],
                    $branchParams
                )
            );


            $cogs =
                $beginningInventory
                + $totalPurchases
                - $endingInventory;


            if ($cogs < 0) {

                $cogs = 0;
            }

            ?>

            ₱<?=number_format(
                $cogs,
                2
            )?>

        </div>


        <div class="stat-foot">

            Beginning + Purchases − Ending

        </div>

    </div>


    <?php

    $grossProfit =
        $totalSales - $cogs;


    $grossMargin =
        $totalSales > 0
            ? (
                $grossProfit
                / $totalSales
            ) * 100
            : 0;


    $netIncome =
        $grossProfit
        - $totalExpenses;


    $netMargin =
        $totalSales > 0
            ? (
                $netIncome
                / $totalSales
            ) * 100
            : 0;

    ?>


    <!-- GROSS PROFIT -->

    <div class="stat-card">

        <div class="stat-icon icon-blue">

            <i class="fa-solid fa-chart-line"></i>

        </div>


        <div class="stat-label">
            Gross Profit
        </div>


        <div class="stat-value <?=$grossProfit >= 0
            ? 'value-blue'
            : 'value-red'
        ?>">

            ₱<?=number_format(
                $grossProfit,
                2
            )?>

        </div>


        <div class="stat-foot">
            Sales − COGS
        </div>


        <div class="trend">

            <?=number_format(
                $grossMargin,
                2
            )?>% Margin

        </div>

    </div>


    <!-- OPERATING EXPENSES -->

    <div class="stat-card">

        <div class="stat-icon icon-purple">

            <i class="fa-solid fa-wallet"></i>

        </div>


        <div class="stat-label">
            Operating Expenses
        </div>


        <div class="stat-value value-purple">

            ₱<?=number_format(
                $totalExpenses,
                2
            )?>

        </div>


        <div class="stat-foot">

            <?=htmlspecialchars(
                $filterLabel
            )?>

            ·

            <?=number_format(
                $countExpenses
            )?>

            expense(s)

        </div>

    </div>


    <!-- NET INCOME -->

    <div class="stat-card">

        <div class="stat-icon icon-green">

            <i class="fa-solid fa-sack-dollar"></i>

        </div>


        <div class="stat-label">
            Net Income
        </div>


        <div class="stat-value <?=$netIncome >= 0
            ? 'value-green'
            : 'value-red'
        ?>">

            ₱<?=number_format(
                $netIncome,
                2
            )?>

        </div>


        <div class="stat-foot">
            Gross Profit − Expenses
        </div>


        <div class="trend">

            <?=number_format(
                $netMargin,
                2
            )?>% Margin

        </div>

    </div>


    <!-- GROSS MARGIN -->

    <div class="stat-card">

        <div class="stat-icon icon-orange">

            <i class="fa-solid fa-percent"></i>

        </div>


        <div class="stat-label">
            Gross Profit Margin
        </div>


        <div class="stat-value value-orange">

            <?=number_format(
                $grossMargin,
                2
            )?>%

        </div>


        <div class="stat-foot">
            Gross Profit ÷ Sales
        </div>

    </div>


    <!-- NET PROFIT MARGIN -->

    <div class="stat-card net-margin-filter-card">


        <div class="net-margin-top">

            <div class="stat-icon icon-blue">

                <i class="fa-solid fa-chart-pie"></i>

            </div>

        </div>


        <div class="stat-label">
            Net Profit Margin
        </div>


        <div class="stat-value value-blue">

            <?=number_format(
                $netMargin,
                2
            )?>%

        </div>


        <div class="stat-foot">
            Net Income ÷ Sales
        </div>


        <div class="net-margin-date">

            <i class="fa-regular fa-calendar"></i>

            <?=htmlspecialchars(
                $filterLabel
            )?>

        </div>

    </div>


</div>


<!-- =======================================================
     INVENTORY / PROFITABILITY
======================================================= -->

<div class="middle-grid">


    <!-- INVENTORY -->

    <div class="panel">

        <div class="panel-head">

            <div class="panel-title">

                <i class="fa-solid fa-boxes-stacked"></i>

                Inventory &amp; COGS

            </div>


            <a
                class="view-btn"
                href="Inventory.php<?= $branch
                    ? '?branch='.$branch
                    : ''
                ?>"
            >

                Manage Inventory

            </a>

        </div>


        <div class="summary">


            <div class="summary-row">

                <span>
                    Beginning Inventory
                </span>


                <span class="summary-value summary-blue">

                    ₱<?=number_format(
                        $beginningInventory,
                        2
                    )?>

                </span>

            </div>


            <div class="summary-row">

                <span>
                    Add: Purchases
                </span>


                <span class="summary-value summary-orange">

                    ₱<?=number_format(
                        $totalPurchases,
                        2
                    )?>

                </span>

            </div>


            <div class="summary-row">

                <span>
                    Goods Available for Sale
                </span>


                <span class="summary-value">

                    ₱<?=number_format(
                        $beginningInventory
                        + $totalPurchases,
                        2
                    )?>

                </span>

            </div>


            <div class="summary-row">

                <span>
                    Less: Ending Inventory
                </span>


                <span class="summary-value summary-purple">

                    ₱<?=number_format(
                        $endingInventory,
                        2
                    )?>

                </span>

            </div>


            <div class="summary-row">

                <span>

                    <strong>
                        Cost of Goods Sold
                    </strong>

                </span>


                <span class="summary-value summary-red">

                    <strong>

                        ₱<?=number_format(
                            $cogs,
                            2
                        )?>

                    </strong>

                </span>

            </div>


        </div>

    </div>


    <!-- PROFITABILITY -->

    <div class="panel summary-card">

        <div class="panel-head">

            <div class="panel-title">

                <i class="fa-solid fa-chart-line"></i>

                Profitability

            </div>

        </div>


        <div class="summary">


            <div class="summary-row">

                <span>
                    Filtered Sales
                </span>


                <span class="summary-value summary-green">

                    ₱<?=number_format(
                        $totalSales,
                        2
                    )?>

                </span>

            </div>


            <div class="summary-row">

                <span>
                    Cost of Goods Sold
                </span>


                <span class="summary-value summary-red">

                    ₱<?=number_format(
                        $cogs,
                        2
                    )?>

                </span>

            </div>


            <div class="summary-row">

                <span>
                    Gross Profit
                </span>


                <span class="summary-value summary-blue">

                    ₱<?=number_format(
                        $grossProfit,
                        2
                    )?>

                </span>

            </div>


            <div class="summary-row">

                <span>
                    Gross Margin
                </span>


                <span class="summary-value summary-orange">

                    <?=number_format(
                        $grossMargin,
                        2
                    )?>%

                </span>

            </div>


            <div class="summary-row">

                <span>
                    Operating Expenses
                </span>


                <span class="summary-value summary-purple">

                    ₱<?=number_format(
                        $totalExpenses,
                        2
                    )?>

                </span>

            </div>


            <div class="summary-row">

                <span>

                    <strong>
                        Net Income
                    </strong>

                </span>


                <span class="summary-value <?=$netIncome >= 0
                    ? 'summary-green'
                    : 'summary-red'
                ?>">

                    <strong>

                        ₱<?=number_format(
                            $netIncome,
                            2
                        )?>

                    </strong>

                </span>

            </div>


            <div class="summary-row">

                <span>
                    Net Margin
                </span>


                <span class="summary-value summary-blue">

                    <?=number_format(
                        $netMargin,
                        2
                    )?>%

                </span>

            </div>


        </div>

    </div>


</div>


<!-- =======================================================
     FINANCIAL PERFORMANCE
======================================================= -->

<?php

$chartSales = [];

$chartPurchases = [];

$chartExpenses = [];


/* =========================================================
   SALES CHART
========================================================= */

$sql = "

    SELECT

        sale_date AS d,

        SUM(

            COALESCE(amount, 0)

            +

            COALESCE(\"service_charge\", 0)

            -

            COALESCE(\"discount\", 0)

        ) AS total

    FROM sales

    WHERE sale_date >= ?

      AND sale_date < ?

";


if ($branch > 0) {

    $sql .= "

        AND branch_id = ?

    ";
}


$sql .= "

    GROUP BY sale_date

    ORDER BY sale_date

";


$stmt = $pdo->prepare($sql);


$stmt->execute(
    array_merge(
        [
            $filterStart,
            $filterEnd
        ],
        $branchParams
    )
);


foreach (
    $stmt->fetchAll(
        PDO::FETCH_ASSOC
    )
    as $row
) {

    $chartSales[
        $row['d']
    ] =
        (float)$row['total'];
}


/* =========================================================
   PURCHASE CHART
========================================================= */

$sql = "

    SELECT

        purchase_date AS d,

        SUM(
            COALESCE(total_amount, 0)
        ) AS total

    FROM purchases

    WHERE purchase_date >= ?

      AND purchase_date < ?

";


if ($branch > 0) {

    $sql .= "

        AND branch_id = ?

    ";
}


$sql .= "

    GROUP BY purchase_date

    ORDER BY purchase_date

";


$stmt = $pdo->prepare($sql);


$stmt->execute(
    array_merge(
        [
            $filterStart,
            $filterEnd
        ],
        $branchParams
    )
);


foreach (
    $stmt->fetchAll(
        PDO::FETCH_ASSOC
    )
    as $row
) {

    $chartPurchases[
        $row['d']
    ] =
        (float)$row['total'];
}


/* =========================================================
   EXPENSE CHART
========================================================= */

$sql = "

    SELECT

        expense_date AS d,

        SUM(
            COALESCE(amount, 0)
        ) AS total

    FROM expenses

    WHERE expense_date >= ?

      AND expense_date < ?

";


if ($branch > 0) {

    $sql .= "

        AND branch_id = ?

    ";
}


$sql .= "

    GROUP BY expense_date

    ORDER BY expense_date

";


$stmt = $pdo->prepare($sql);


$stmt->execute(
    array_merge(
        [
            $filterStart,
            $filterEnd
        ],
        $branchParams
    )
);


foreach (
    $stmt->fetchAll(
        PDO::FETCH_ASSOC
    )
    as $row
) {

    $chartExpenses[
        $row['d']
    ] =
        (float)$row['total'];
}


/* =========================================================
   CHART LABELS
========================================================= */

$labels = [];

$salesData = [];

$purchaseData = [];

$expenseData = [];


$chartDate =
    new DateTime(
        $dateFrom
    );


$chartEndDate =
    new DateTime(
        $dateTo
    );


while (
    $chartDate <=
    $chartEndDate
) {

    $currentDate =
        $chartDate->format(
            'Y-m-d'
        );


    $labels[] =
        $chartDate->format(
            'M d'
        );


    $salesData[] =
        round(
            $chartSales[
                $currentDate
            ] ?? 0,
            2
        );


    $purchaseData[] =
        round(
            $chartPurchases[
                $currentDate
            ] ?? 0,
            2
        );


    $expenseData[] =
        round(
            $chartExpenses[
                $currentDate
            ] ?? 0,
            2
        );


    $chartDate->modify(
        '+1 day'
    );
}

?>


<div class="panel mt-3">

    <div class="panel-head">

        <div class="panel-title">

            <i class="fa-solid fa-chart-column"></i>

            Financial Performance

            <span
                style="
                    font-weight:400;
                    color:#7d8795;
                "
            >

                (<?=htmlspecialchars(
                    $filterLabel
                )?>)

            </span>

        </div>

    </div>


    <div class="chart-wrap">

        <canvas id="financialChart"></canvas>

    </div>

</div>


<!-- =======================================================
     RECENT SALES
======================================================= -->

<?php

if ($branch > 0) {

    $stmt = $pdo->prepare("

        SELECT

            s.*,

            b.branch_name

        FROM sales s

        LEFT JOIN branches b
            ON b.id = s.branch_id

        WHERE s.sale_date >= ?

          AND s.sale_date < ?

          AND s.branch_id = ?

        ORDER BY

            s.sale_date DESC,

            s.id DESC

        LIMIT 5

    ");


    $stmt->execute([
        $filterStart,
        $filterEnd,
        $branch
    ]);

} else {

    $stmt = $pdo->prepare("

        SELECT

            s.*,

            b.branch_name

        FROM sales s

        LEFT JOIN branches b
            ON b.id = s.branch_id

        WHERE s.sale_date >= ?

          AND s.sale_date < ?

        ORDER BY

            s.sale_date DESC,

            s.id DESC

        LIMIT 5

    ");


    $stmt->execute([
        $filterStart,
        $filterEnd
    ]);
}


$recentSales =
    $stmt->fetchAll(
        PDO::FETCH_ASSOC
    );

?>


<div class="lower-grid">


    <div class="panel table-card">


        <div class="panel-head">

            <div class="panel-title">

                <i class="fa-solid fa-cart-shopping"></i>

                Recent Sales

            </div>


            <a
                class="view-btn"
                href="sales.php<?= $branch
                    ? '?branch='.$branch
                    : ''
                ?>"
            >

                View All

            </a>

        </div>


        <div class="table-wrap">

            <table class="table">

                <thead>

                    <tr>

                        <th>
                            Date
                        </th>

                        <th>
                            OR / Invoice No.
                        </th>

                        <th>
                            Customer
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

                <?php foreach (
                    $recentSales
                    as $r
                ): ?>

                    <tr>

                        <td>

                            <?=date(
                                'M d, Y',
                                strtotime(
                                    $r['sale_date']
                                )
                            )?>

                        </td>


                        <td>

                            <?=htmlspecialchars(
                                $r['reference_no']
                                ?? ''
                            )?>

                        </td>


                        <td>

                            <?=htmlspecialchars(
                                $r['customer']
                                ?? 'Walk-in Customer'
                            )?>

                        </td>


                        <td>

                            <?=htmlspecialchars(
                                $r['description']
                                ?? ''
                            )?>

                        </td>


                        <td class="text-end amount-green">

                            ₱<?=number_format(

                                (

                                    (float)(
                                        $r['amount']
                                        ?? 0
                                    )

                                    +

                                    (float)(
                                        $r['service_charge']
                                        ?? 0
                                    )

                                    -

                                    (float)(
                                        $r['discount']
                                        ?? 0
                                    )

                                ),

                                2

                            )?>

                        </td>

                    </tr>

                <?php endforeach; ?>


                <?php if (!$recentSales): ?>

                    <tr>

                        <td
                            colspan="5"
                            class="text-center text-muted py-4"
                        >

                            No sales recorded
                            for the selected date.

                        </td>

                    </tr>

                <?php endif; ?>

                </tbody>

            </table>

        </div>


        <div class="p-3">

            <a
                class="view-btn"
                href="sales.php?action=add<?= $branch
                    ? '&branch='.$branch
                    : ''
                ?>"
            >

                + Add New Sale

            </a>

        </div>

    </div>


</div>


<!-- =======================================================
     CHART.JS
======================================================= -->

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>


<script>

document.addEventListener(
    'DOMContentLoaded',
    function () {


        const canvas =
            document.getElementById(
                'financialChart'
            );


        if (!canvas) {

            return;

        }


        new Chart(
            canvas,
            {

                type:'line',


                data:{

                    labels:
                        <?=json_encode(
                            $labels
                        )?>,


                    datasets:[


                        {

                            label:'Sales',

                            data:
                                <?=json_encode(
                                    $salesData
                                )?>,

                            borderColor:
                                '#28c884',

                            backgroundColor:
                                'rgba(40,200,132,.10)',

                            fill:true,

                            tension:.35,

                            pointRadius:2,

                            pointHoverRadius:4

                        },


                        {

                            label:'Purchases',

                            data:
                                <?=json_encode(
                                    $purchaseData
                                )?>,

                            borderColor:
                                '#ff9d21',

                            backgroundColor:
                                'rgba(255,157,33,.08)',

                            fill:true,

                            tension:.35,

                            pointRadius:2,

                            pointHoverRadius:4

                        },


                        {

                            label:'Expenses',

                            data:
                                <?=json_encode(
                                    $expenseData
                                )?>,

                            borderColor:
                                '#ff4b4b',

                            backgroundColor:
                                'rgba(255,75,75,.08)',

                            fill:true,

                            tension:.35,

                            pointRadius:2,

                            pointHoverRadius:4

                        }

                    ]

                },


                options:{

                    responsive:true,

                    maintainAspectRatio:false,


                    interaction:{

                        mode:'index',

                        intersect:false

                    },


                    plugins:{

                        legend:{

                            position:'top',

                            labels:{

                                usePointStyle:true,

                                boxWidth:8,

                                padding:20,

                                font:{

                                    size:11

                                }

                            }

                        },


                        tooltip:{

                            callbacks:{

                                label:
                                    function(context){

                                        return ' '

                                            +

                                            context.dataset.label

                                            +

                                            ': ₱'

                                            +

                                            Number(
                                                context.raw || 0
                                            ).toLocaleString(
                                                undefined,
                                                {
                                                    minimumFractionDigits:2,

                                                    maximumFractionDigits:2
                                                }
                                            );

                                    }

                            }

                        }

                    },


                    scales:{

                        x:{

                            grid:{
                                color:'#eef1f5'
                            },

                            ticks:{
                                font:{
                                    size:10
                                }
                            }

                        },


                        y:{

                            beginAtZero:true,

                            grid:{
                                color:'#eef1f5'
                            },

                            ticks:{

                                font:{
                                    size:10
                                },

                                callback:
                                    function(value){

                                        return '₱'

                                            +

                                            Number(
                                                value
                                            ).toLocaleString();

                                    }

                            }

                        }

                    }

                }

            }

        );

    }

);

</script>


<?php endif; ?>


<?php

include "footer.php";

?>
