<?php

session_start();

require_once "auth.php";
require_once "config.php";

$pageTitle = "Inventory";


/* =========================================================
   BRANCH
========================================================= */

$branch = (int)($_GET['branch'] ?? 0);

if ($branch > 0) {

    $_SESSION['branch_id'] = $branch;

} elseif (
    isset($_SESSION['branch_id'])
    && !isset($_GET['branch'])
) {

    $branch = (int)$_SESSION['branch_id'];
}


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

    $value = $stmt->fetchColumn();

    return $value !== false
        ? (float)$value
        : 0.0;
}


/* =========================================================
   MONTH
========================================================= */

$monthParam = $_GET['month'] ?? date('Y-m');

if (!preg_match('/^\d{4}-\d{2}$/', $monthParam)) {
    $monthParam = date('Y-m');
}

$monthStart = $monthParam . '-01';

$monthDate = new DateTime($monthStart);

$nextMonthDate = clone $monthDate;
$nextMonthDate->modify('+1 month');

$nextMonth = $nextMonthDate->format('Y-m-d');

$monthEndDate = clone $nextMonthDate;
$monthEndDate->modify('-1 day');

$monthEnd = $monthEndDate->format('Y-m-d');


/* =========================================================
   PREVIOUS MONTH
========================================================= */

$previousMonthDate = clone $monthDate;
$previousMonthDate->modify('-1 month');

$previousMonthStart =
    $previousMonthDate->format('Y-m-d');

$previousMonthLabel =
    $previousMonthDate->format('F Y');


/* =========================================================
   BRANCH NAME
========================================================= */

$branchName = "All Branches";

if ($branch > 0) {

    $stmt = $pdo->prepare("
        SELECT branch_name
        FROM branches
        WHERE id = ?
        LIMIT 1
    ");

    $stmt->execute([$branch]);

    $branchName =
        $stmt->fetchColumn()
        ?: "All Branches";
}


/* =========================================================
   CURRENT INVENTORY
========================================================= */

$inventory = null;

if ($branch > 0) {

    $stmt = $pdo->prepare("
        SELECT *
        FROM inventory
        WHERE branch_id = ?
          AND inventory_date = ?
        ORDER BY id DESC
        LIMIT 1
    ");

    $stmt->execute([
        $branch,
        $monthStart
    ]);

    $inventory =
        $stmt->fetch(PDO::FETCH_ASSOC);
}


/* =========================================================
   PREVIOUS MONTH INVENTORY
========================================================= */

$previousInventory = null;

if ($branch > 0) {

    $stmt = $pdo->prepare("
        SELECT *
        FROM inventory
        WHERE branch_id = ?
          AND inventory_date = ?
        ORDER BY id DESC
        LIMIT 1
    ");

    $stmt->execute([
        $branch,
        $previousMonthStart
    ]);

    $previousInventory =
        $stmt->fetch(PDO::FETCH_ASSOC);
}


/* =========================================================
   PREVIOUS ENDING
========================================================= */

$previousEnding = 0;

if ($previousInventory) {

    $previousEnding =
        (float)$previousInventory['ending_inventory'];
}


/* =========================================================
   BEGINNING INVENTORY
========================================================= */

if ($previousInventory) {

    $beginningInventory =
        $previousEnding;

} elseif ($inventory) {

    $beginningInventory =
        (float)$inventory['beginning_inventory'];

} else {

    $beginningInventory = 0;
}


/* =========================================================
   PURCHASES
   PURCHASES IS ALREADY WORKING
========================================================= */

$totalPurchases = 0;

if ($branch > 0) {

    $totalPurchases = scalarQuery(
        $pdo,
        "
        SELECT COALESCE(
            SUM(total_amount),
            0
        )
        FROM purchases
        WHERE purchase_date >= ?
          AND purchase_date < ?
          AND branch_id = ?
        ",
        [
            $monthStart,
            $nextMonth,
            $branch
        ]
    );
}


/* =========================================================
   ENDING INVENTORY
========================================================= */

$endingInventory = 0;

if ($inventory) {

    $endingInventory =
        (float)$inventory['ending_inventory'];
}


/* =========================================================
   SALES
   IMPORTANT:
   PostgreSQL/Neon column names are case-sensitive.
   
   Actual columns:
   "Service_charge"
   "Discount"
========================================================= */

$totalSales = 0;

if ($branch > 0) {

    $totalSales = scalarQuery(
        $pdo,
        "
        SELECT COALESCE(
            SUM(
                COALESCE(amount, 0)
                +
                COALESCE(\"Service_charge\", 0)
                -
                COALESCE(\"Discount\", 0)
            ),
            0
        )
        FROM sales
        WHERE sale_date >= ?
          AND sale_date < ?
          AND branch_id = ?
        ",
        [
            $monthStart,
            $nextMonth,
            $branch
        ]
    );
}


/* =========================================================
   EXPENSES
========================================================= */

$totalExpenses = 0;

if ($branch > 0) {

    $totalExpenses = scalarQuery(
        $pdo,
        "
        SELECT COALESCE(
            SUM(amount),
            0
        )
        FROM expenses
        WHERE expense_date >= ?
          AND expense_date < ?
          AND branch_id = ?
        ",
        [
            $monthStart,
            $nextMonth,
            $branch
        ]
    );
}


/* =========================================================
   COGS
========================================================= */

$cogs =
    $beginningInventory
    +
    $totalPurchases
    -
    $endingInventory;


/* =========================================================
   GROSS PROFIT
========================================================= */

$grossProfit =
    $totalSales
    -
    $cogs;


/* =========================================================
   GROSS PROFIT %
========================================================= */

$grossProfitPct =
    $totalSales > 0
        ? (
            $grossProfit
            /
            $totalSales
        ) * 100
        : 0;


/* =========================================================
   NET INCOME
========================================================= */

$netIncome =
    $grossProfit
    -
    $totalExpenses;


/* =========================================================
   MESSAGES
========================================================= */

$message = "";
$error = "";


/* =========================================================
   POST SAVE
========================================================= */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $action =
        $_POST['inventory_action'] ?? '';

    if ($action === 'save') {

        $postBranch =
            (int)($_POST['branch_id'] ?? 0);

        $inventoryDate =
            trim(
                $_POST['inventory_date'] ?? ''
            );

        $postedBeginning =
            (float)(
                $_POST['beginning_inventory']
                ?? 0
            );

        $ending =
            (float)(
                $_POST['ending_inventory']
                ?? 0
            );

        $notes =
            trim(
                $_POST['notes'] ?? ''
            );


        /* =============================================
           VALIDATION
        ============================================= */

        if ($postBranch <= 0) {

            $error =
                "Please select a branch.";

        } elseif (
            !preg_match(
                '/^\d{4}-\d{2}-\d{2}$/',
                $inventoryDate
            )
        ) {

            $error =
                "Invalid inventory date.";

        } elseif (
            substr($inventoryDate, 8, 2) !== '01'
        ) {

            $error =
                "Inventory date must be the first day of the month.";

        } elseif ($ending < 0) {

            $error =
                "Ending inventory cannot be negative.";

        } elseif ($postedBeginning < 0) {

            $error =
                "Beginning inventory cannot be negative.";

        } else {

            /* =========================================
               PREVIOUS MONTH
            ========================================= */

            try {

                $saveMonthDate =
                    new DateTime($inventoryDate);

                $savePreviousMonthDate =
                    clone $saveMonthDate;

                $savePreviousMonthDate
                    ->modify('-1 month');

                $savePreviousMonthStart =
                    $savePreviousMonthDate
                    ->format('Y-m-d');

            } catch (Exception $e) {

                $error =
                    "Invalid inventory date.";

                $savePreviousMonthStart =
                    null;
            }


            /* =========================================
               GET PREVIOUS MONTH INVENTORY
            ========================================= */

            $previousEndingForSave = false;

            if (!$error) {

                $stmt = $pdo->prepare("
                    SELECT ending_inventory
                    FROM inventory
                    WHERE branch_id = ?
                      AND inventory_date = ?
                    ORDER BY id DESC
                    LIMIT 1
                ");

                $stmt->execute([
                    $postBranch,
                    $savePreviousMonthStart
                ]);

                $previousEndingForSave =
                    $stmt->fetchColumn();
            }


            /* =========================================
               DETERMINE BEGINNING
            ========================================= */

            if (!$error) {

                if (
                    $previousEndingForSave !== false
                    &&
                    $previousEndingForSave !== null
                ) {

                    $beginning =
                        (float)$previousEndingForSave;

                } else {

                    /*
                     * Check if there is an older
                     * inventory record.
                     */

                    $checkEarlier = $pdo->prepare("
                        SELECT COUNT(*)
                        FROM inventory
                        WHERE branch_id = ?
                          AND inventory_date < ?
                    ");

                    $checkEarlier->execute([
                        $postBranch,
                        $inventoryDate
                    ]);

                    $earlierCount =
                        (int)$checkEarlier->fetchColumn();


                    if ($earlierCount > 0) {

                        $error =
                            "The previous month's inventory is missing. "
                            . "Please encode the previous month's Ending Inventory "
                            . "first before saving this month's inventory.";

                    } else {

                        /*
                         * First inventory record.
                         */

                        $beginning =
                            $postedBeginning;
                    }
                }
            }


            /* =========================================
               SAVE
            ========================================= */

            if (!$error) {

                $stmt = $pdo->prepare("
                    SELECT id
                    FROM inventory
                    WHERE branch_id = ?
                      AND inventory_date = ?
                    LIMIT 1
                ");

                $stmt->execute([
                    $postBranch,
                    $inventoryDate
                ]);

                $existingId =
                    (int)(
                        $stmt->fetchColumn()
                        ?: 0
                    );


                if ($existingId > 0) {

                    $stmt = $pdo->prepare("
                        UPDATE inventory
                        SET
                            beginning_inventory = ?,
                            ending_inventory = ?,
                            notes = ?
                        WHERE id = ?
                    ");

                    $stmt->execute([
                        $beginning,
                        $ending,
                        $notes,
                        $existingId
                    ]);

                    $message =
                        "Inventory successfully updated.";

                } else {

                    $stmt = $pdo->prepare("
                        INSERT INTO inventory
                        (
                            branch_id,
                            inventory_date,
                            beginning_inventory,
                            ending_inventory,
                            notes
                        )
                        VALUES
                        (
                            ?,
                            ?,
                            ?,
                            ?,
                            ?
                        )
                    ");

                    $stmt->execute([
                        $postBranch,
                        $inventoryDate,
                        $beginning,
                        $ending,
                        $notes
                    ]);

                    $message =
                        "Inventory successfully saved.";
                }


                /* =====================================
                   REDIRECT
                ===================================== */

                header(
                    "Location: inventory.php?"
                    .
                    http_build_query([
                        'branch' => $postBranch,
                        'month' =>
                            date(
                                'Y-m',
                                strtotime(
                                    $inventoryDate
                                )
                            ),
                        'saved' => 1
                    ])
                );

                exit;
            }
        }
    }
}


/* =========================================================
   SAVED MESSAGE
========================================================= */

if (
    isset($_GET['saved'])
    &&
    $_GET['saved'] == '1'
) {

    $message =
        "Inventory successfully saved.";
}


/* =========================================================
   RELOAD CURRENT INVENTORY
========================================================= */

$inventory = null;

if ($branch > 0) {

    $stmt = $pdo->prepare("
        SELECT *
        FROM inventory
        WHERE branch_id = ?
          AND inventory_date = ?
        ORDER BY id DESC
        LIMIT 1
    ");

    $stmt->execute([
        $branch,
        $monthStart
    ]);

    $inventory =
        $stmt->fetch(PDO::FETCH_ASSOC);
}


/* =========================================================
   RELOAD PREVIOUS INVENTORY
========================================================= */

$previousInventory = null;

if ($branch > 0) {

    $stmt = $pdo->prepare("
        SELECT *
        FROM inventory
        WHERE branch_id = ?
          AND inventory_date = ?
        ORDER BY id DESC
        LIMIT 1
    ");

    $stmt->execute([
        $branch,
        $previousMonthStart
    ]);

    $previousInventory =
        $stmt->fetch(PDO::FETCH_ASSOC);
}


/* =========================================================
   RECALCULATE BEGINNING
========================================================= */

if ($previousInventory) {

    $previousEnding =
        (float)$previousInventory['ending_inventory'];

    $beginningInventory =
        $previousEnding;

} elseif ($inventory) {

    $previousEnding = 0;

    $beginningInventory =
        (float)$inventory['beginning_inventory'];

} else {

    $previousEnding = 0;

    $beginningInventory = 0;
}


/* =========================================================
   CURRENT ENDING
========================================================= */

$endingInventory =
    $inventory
        ? (float)$inventory['ending_inventory']
        : 0;


/* =========================================================
   RECALCULATE COGS
========================================================= */

$cogs =
    $beginningInventory
    +
    $totalPurchases
    -
    $endingInventory;


/* =========================================================
   RECALCULATE GROSS PROFIT
========================================================= */

$grossProfit =
    $totalSales
    -
    $cogs;


/* =========================================================
   RECALCULATE GROSS PROFIT %
========================================================= */

$grossProfitPct =
    $totalSales > 0
        ? (
            $grossProfit
            /
            $totalSales
        ) * 100
        : 0;


/* =========================================================
   RECALCULATE NET INCOME
========================================================= */

$netIncome =
    $grossProfit
    -
    $totalExpenses;


/* =========================================================
   INVENTORY HISTORY
========================================================= */

if ($branch > 0) {

    $stmt = $pdo->prepare("
        SELECT
            i.*,
            b.branch_name
        FROM inventory i
        INNER JOIN branches b
            ON b.id = i.branch_id
        WHERE i.branch_id = ?
        ORDER BY
            i.inventory_date DESC,
            i.id DESC
        LIMIT 12
    ");

    $stmt->execute([
        $branch
    ]);

} else {

    $stmt = $pdo->query("
        SELECT
            i.*,
            b.branch_name
        FROM inventory i
        INNER JOIN branches b
            ON b.id = i.branch_id
        ORDER BY
            i.inventory_date DESC,
            i.id DESC
        LIMIT 12
    ");
}

$inventoryHistory =
    $stmt->fetchAll(PDO::FETCH_ASSOC);


/* =========================================================
   MONTH LABEL
========================================================= */

$monthLabel =
    $monthDate->format('F Y');


/* =========================================================
   BRANCH LIST
========================================================= */

$branches =
    $pdo
        ->query("
            SELECT
                id,
                branch_name
            FROM branches
            ORDER BY branch_name
        ")
        ->fetchAll(PDO::FETCH_ASSOC);

?>


<?php include "header.php"; ?>


<div class="container-fluid">


<!-- =====================================================
     HEADER
====================================================== -->

<div class="d-flex justify-content-between align-items-center mb-4">

    <div>

        <h3 class="fw-bold mb-1">

            <i class="fa-solid fa-boxes-stacked"></i>

            Inventory

        </h3>

        <div class="text-muted">

            <?=htmlspecialchars($branchName)?>

            —

            <?=htmlspecialchars($monthLabel)?>

        </div>

    </div>


    <div class="d-flex gap-2">


        <!-- BRANCH -->

        <form method="GET">

            <input
                type="hidden"
                name="month"
                value="<?=htmlspecialchars(
                    $monthParam
                )?>"
            >

            <select
                name="branch"
                class="form-select"
                onchange="this.form.submit()"
            >

                <option value="0">

                    All Branches

                </option>


                <?php foreach ($branches as $b): ?>

                    <option
                        value="<?=$b['id']?>"
                        <?=$branch == $b['id']
                            ? 'selected'
                            : ''?>
                    >

                        <?=htmlspecialchars(
                            $b['branch_name']
                        )?>

                    </option>

                <?php endforeach; ?>

            </select>

        </form>


        <!-- MONTH -->

        <form method="GET">

            <input
                type="hidden"
                name="branch"
                value="<?=$branch?>"
            >

            <input
                type="month"
                name="month"
                value="<?=htmlspecialchars(
                    $monthParam
                )?>"
                class="form-control"
                onchange="this.form.submit()"
            >

        </form>

    </div>

</div>


<!-- =====================================================
     MESSAGES
====================================================== -->

<?php if ($message): ?>

    <div class="alert alert-success">

        <i class="fa-solid fa-circle-check"></i>

        <?=htmlspecialchars($message)?>

    </div>

<?php endif; ?>


<?php if ($error): ?>

    <div class="alert alert-danger">

        <i class="fa-solid fa-circle-exclamation"></i>

        <?=htmlspecialchars($error)?>

    </div>

<?php endif; ?>


<!-- =====================================================
     FLOW INFORMATION
====================================================== -->

<?php if ($branch > 0): ?>

    <div class="alert alert-info border-0 shadow-sm">

        <div class="d-flex">

            <div class="me-3">

                <i class="fa-solid fa-circle-info fa-lg"></i>

            </div>

            <div>

                <strong>
                    Monthly Inventory Flow
                </strong>

                <div class="mt-1">

                    <?=htmlspecialchars($monthLabel)?>

                    Beginning Inventory =

                    <?php if ($previousInventory): ?>

                        <?=htmlspecialchars(
                            $previousMonthLabel
                        )?>

                        Ending Inventory

                    <?php else: ?>

                        Initial Beginning Inventory

                    <?php endif; ?>

                </div>

            </div>

        </div>

    </div>

<?php endif; ?>


<!-- =====================================================
     INVENTORY INPUT
====================================================== -->

<div class="card shadow-sm border-0 mb-4">

    <div class="card-header bg-white">

        <h5 class="mb-0 fw-bold">

            <i class="fa-solid fa-clipboard-check"></i>

            Monthly Inventory

        </h5>

    </div>


    <div class="card-body">


        <?php if (!$branch): ?>

            <div class="alert alert-warning mb-0">

                <i class="fa-solid fa-info-circle"></i>

                Please select a branch before entering
                inventory.

            </div>

        <?php else: ?>


            <form method="POST">

                <input
                    type="hidden"
                    name="inventory_action"
                    value="save"
                >

                <input
                    type="hidden"
                    name="branch_id"
                    value="<?=$branch?>"
                >


                <div class="row g-3">


                    <!-- MONTH -->

                    <div class="col-md-3">

                        <label class="form-label fw-bold">

                            Inventory Month

                        </label>

                        <input
                            type="date"
                            name="inventory_date"
                            class="form-control"
                            value="<?=$inventory
                                ? htmlspecialchars(
                                    $inventory[
                                        'inventory_date'
                                    ]
                                )
                                : htmlspecialchars(
                                    $monthStart
                                )?>"
                            required
                        >

                        <small class="text-muted">

                            Must be the first day
                            of the month.

                        </small>

                    </div>


                    <!-- BEGINNING -->

                    <div class="col-md-3">

                        <label class="form-label fw-bold">

                            Beginning Inventory

                            <?php if ($previousInventory): ?>

                                <span class="badge bg-success ms-1">

                                    Automatic

                                </span>

                            <?php else: ?>

                                <span class="badge bg-secondary ms-1">

                                    Initial

                                </span>

                            <?php endif; ?>

                        </label>


                        <div class="input-group">

                            <span class="input-group-text">

                                ₱

                            </span>

                            <input
                                type="number"
                                step="0.01"
                                min="0"
                                name="beginning_inventory"
                                class="form-control
                                    <?=$previousInventory
                                        ? 'bg-light'
                                        : ''?>"
                                value="<?=number_format(
                                    $beginningInventory,
                                    2,
                                    '.',
                                    ''
                                )?>"
                                <?=$previousInventory
                                    ? 'readonly'
                                    : ''?>
                                required
                            >

                        </div>


                        <?php if ($previousInventory): ?>

                            <small class="text-success">

                                <i class="fa-solid fa-link"></i>

                                Automatic from
                                <?=htmlspecialchars(
                                    $previousMonthLabel
                                )?>
                                Ending Inventory:

                                <strong>

                                    ₱<?=number_format(
                                        $previousEnding,
                                        2
                                    )?>

                                </strong>

                            </small>

                        <?php else: ?>

                            <small class="text-muted">

                                Initial inventory only.
                                Enter this manually if this
                                is the first inventory record.

                            </small>

                        <?php endif; ?>

                    </div>


                    <!-- PURCHASES -->

                    <div class="col-md-3">

                        <label class="form-label fw-bold">

                            Purchases

                            <span class="badge bg-info ms-1">

                                Automatic

                            </span>

                        </label>


                        <div class="input-group">

                            <span class="input-group-text">

                                ₱

                            </span>

                            <input
                                type="text"
                                class="form-control bg-light"
                                value="<?=number_format(
                                    $totalPurchases,
                                    2
                                )?>"
                                readonly
                            >

                        </div>


                        <small class="text-muted">

                            From Purchases for
                            <?=htmlspecialchars(
                                $monthLabel
                            )?>

                        </small>

                    </div>


                    <!-- ENDING -->

                    <div class="col-md-3">

                        <label class="form-label fw-bold">

                            Ending Inventory

                            <span class="badge bg-warning text-dark ms-1">

                                Encode

                            </span>

                        </label>


                        <div class="input-group">

                            <span class="input-group-text">

                                ₱

                            </span>

                            <input
                                type="number"
                                step="0.01"
                                min="0"
                                name="ending_inventory"
                                class="form-control"
                                value="<?=number_format(
                                    $endingInventory,
                                    2,
                                    '.',
                                    ''
                                )?>"
                                required
                            >

                        </div>


                        <small class="text-muted">

                            Enter the actual physical
                            inventory at the end of
                            <?=htmlspecialchars(
                                $monthLabel
                            )?>.

                        </small>

                    </div>


                    <!-- NOTES -->

                    <div class="col-md-9">

                        <label class="form-label fw-bold">

                            Notes

                        </label>

                        <input
                            type="text"
                            name="notes"
                            class="form-control"
                            maxlength="255"
                            placeholder="Optional notes"
                            value="<?=htmlspecialchars(
                                $inventory['notes'] ?? ''
                            )?>"
                        >

                    </div>


                    <!-- SAVE -->

                    <div class="col-md-3 d-flex align-items-end">

                        <button
                            type="submit"
                            class="btn btn-primary w-100"
                        >

                            <i class="fa-solid fa-save"></i>

                            <?=$inventory
                                ? 'Update Inventory'
                                : 'Save Inventory'?>

                        </button>

                    </div>

                </div>

            </form>

        <?php endif; ?>

    </div>

</div>


<!-- =====================================================
     FINANCIAL SUMMARY
====================================================== -->

<div class="row g-3 mb-4">


    <!-- BEGINNING -->

    <div class="col-md-3">

        <div class="card border-0 shadow-sm h-100">

            <div class="card-body">

                <div class="text-muted small">

                    Beginning Inventory

                </div>

                <h4 class="fw-bold mt-2 mb-0">

                    ₱<?=number_format(
                        $beginningInventory,
                        2
                    )?>

                </h4>


                <?php if ($previousInventory): ?>

                    <small class="text-success">

                        <i class="fa-solid fa-link"></i>

                        From
                        <?=htmlspecialchars(
                            $previousMonthLabel
                        )?>
                        Ending

                    </small>

                <?php else: ?>

                    <small class="text-muted">

                        Initial inventory

                    </small>

                <?php endif; ?>

            </div>

        </div>

    </div>


    <!-- PURCHASES -->

    <div class="col-md-3">

        <div class="card border-0 shadow-sm h-100">

            <div class="card-body">

                <div class="text-muted small">

                    Purchases

                </div>

                <h4 class="fw-bold mt-2 mb-0 text-primary">

                    ₱<?=number_format(
                        $totalPurchases,
                        2
                    )?>

                </h4>

            </div>

        </div>

    </div>


    <!-- ENDING -->

    <div class="col-md-3">

        <div class="card border-0 shadow-sm h-100">

            <div class="card-body">

                <div class="text-muted small">

                    Ending Inventory

                </div>

                <h4 class="fw-bold mt-2 mb-0 text-warning">

                    ₱<?=number_format(
                        $endingInventory,
                        2
                    )?>

                </h4>

            </div>

        </div>

    </div>


    <!-- COGS -->

    <div class="col-md-3">

        <div class="card border-0 shadow-sm h-100">

            <div class="card-body">

                <div class="text-muted small">

                    Cost of Goods Sold

                </div>

                <h4 class="fw-bold mt-2 mb-0 text-danger">

                    ₱<?=number_format(
                        $cogs,
                        2
                    )?>

                </h4>

            </div>

        </div>

    </div>

</div>


<!-- =====================================================
     PROFIT SUMMARY
====================================================== -->

<div class="row g-3 mb-4">


    <!-- SALES -->

    <div class="col-md-3">

        <div class="card border-0 shadow-sm">

            <div class="card-body">

                <div class="text-muted small">

                    Sales

                </div>

                <h4 class="fw-bold text-success">

                    ₱<?=number_format(
                        $totalSales,
                        2
                    )?>

                </h4>

            </div>

        </div>

    </div>


    <!-- COGS -->

    <div class="col-md-3">

        <div class="card border-0 shadow-sm">

            <div class="card-body">

                <div class="text-muted small">

                    Cost of Goods Sold

                </div>

                <h4 class="fw-bold text-danger">

                    ₱<?=number_format(
                        $cogs,
                        2
                    )?>

                </h4>

            </div>

        </div>

    </div>


    <!-- GROSS PROFIT -->

    <div class="col-md-3">

        <div class="card border-0 shadow-sm">

            <div class="card-body">

                <div class="text-muted small">

                    Gross Profit

                </div>

                <h4 class="fw-bold text-primary">

                    ₱<?=number_format(
                        $grossProfit,
                        2
                    )?>

                </h4>

                <small class="text-muted">

                    <?=number_format(
                        $grossProfitPct,
                        2
                    )?>% Gross Margin

                </small>

            </div>

        </div>

    </div>


    <!-- NET INCOME -->

    <div class="col-md-3">

        <div class="card border-0 shadow-sm">

            <div class="card-body">

                <div class="text-muted small">

                    Net Income

                </div>

                <h4
                    class="fw-bold
                        <?=$netIncome >= 0
                            ? 'text-success'
                            : 'text-danger'?>"
                >

                    ₱<?=number_format(
                        $netIncome,
                        2
                    )?>

                </h4>

                <small class="text-muted">

                    Gross Profit − Expenses

                </small>

            </div>

        </div>

    </div>

</div>


<!-- =====================================================
     INVENTORY FORMULA
====================================================== -->

<div class="card border-0 shadow-sm mb-4">

    <div class="card-body">

        <h5 class="fw-bold mb-3">

            <i class="fa-solid fa-calculator"></i>

            Inventory Calculation

        </h5>


        <div class="row text-center align-items-center">


            <div class="col-md-2">

                <div class="text-muted small">

                    Beginning

                </div>

                <strong>

                    ₱<?=number_format(
                        $beginningInventory,
                        2
                    )?>

                </strong>

            </div>


            <div class="col-md-1">

                <strong class="fs-4">

                    +

                </strong>

            </div>


            <div class="col-md-2">

                <div class="text-muted small">

                    Purchases

                </div>

                <strong>

                    ₱<?=number_format(
                        $totalPurchases,
                        2
                    )?>

                </strong>

            </div>


            <div class="col-md-1">

                <strong class="fs-4">

                    −

                </strong>

            </div>


            <div class="col-md-2">

                <div class="text-muted small">

                    Ending

                </div>

                <strong>

                    ₱<?=number_format(
                        $endingInventory,
                        2
                    )?>

                </strong>

            </div>


            <div class="col-md-1">

                <strong class="fs-4">

                    =

                </strong>

            </div>


            <div class="col-md-3">

                <div class="text-muted small">

                    COST OF GOODS SOLD

                </div>

                <strong class="text-danger fs-5">

                    ₱<?=number_format(
                        $cogs,
                        2
                    )?>

                </strong>

            </div>

        </div>

    </div>

</div>


<!-- =====================================================
     MONTHLY FLOW
====================================================== -->

<?php if ($branch > 0): ?>

<div class="card border-0 shadow-sm mb-4">

    <div class="card-body">

        <h5 class="fw-bold mb-3">

            <i class="fa-solid fa-arrows-rotate"></i>

            Monthly Inventory Flow

        </h5>


        <div class="row g-3">


            <div class="col-md-4">

                <div class="border rounded p-3 h-100">

                    <div class="text-muted small">

                        Previous Month

                    </div>

                    <div class="fw-bold">

                        <?=htmlspecialchars(
                            $previousMonthLabel
                        )?>

                    </div>

                    <div class="mt-2">

                        Ending Inventory

                    </div>

                    <div class="fs-5 fw-bold text-warning">

                        ₱<?=number_format(
                            $previousEnding,
                            2
                        )?>

                    </div>

                </div>

            </div>


            <div class="col-md-1 d-flex align-items-center justify-content-center">

                <i class="fa-solid fa-arrow-right fa-xl text-success"></i>

            </div>


            <div class="col-md-3">

                <div class="border rounded p-3 h-100">

                    <div class="text-muted small">

                        Current Month

                    </div>

                    <div class="fw-bold">

                        <?=htmlspecialchars(
                            $monthLabel
                        )?>

                    </div>

                    <div class="mt-2">

                        Beginning Inventory

                    </div>

                    <div class="fs-5 fw-bold text-success">

                        ₱<?=number_format(
                            $beginningInventory,
                            2
                        )?>

                    </div>

                </div>

            </div>


            <div class="col-md-1 d-flex align-items-center justify-content-center">

                <i class="fa-solid fa-arrow-right fa-xl text-primary"></i>

            </div>


            <div class="col-md-3">

                <div class="border rounded p-3 h-100">

                    <div class="text-muted small">

                        Encode at Month End

                    </div>

                    <div class="fw-bold">

                        <?=htmlspecialchars(
                            $monthEndDate->format(
                                'F d, Y'
                            )
                        )?>

                    </div>

                    <div class="mt-2">

                        Ending Inventory

                    </div>

                    <div class="fs-5 fw-bold text-warning">

                        ₱<?=number_format(
                            $endingInventory,
                            2
                        )?>

                    </div>

                </div>

            </div>

        </div>

    </div>

</div>

<?php endif; ?>


<!-- =====================================================
     INVENTORY HISTORY
====================================================== -->

<div class="card border-0 shadow-sm">

    <div class="card-header bg-white">

        <h5 class="mb-0 fw-bold">

            <i class="fa-solid fa-clock-rotate-left"></i>

            Inventory History

        </h5>

    </div>


    <div class="table-responsive">

        <table class="table table-hover align-middle mb-0">

            <thead class="table-light">

                <tr>

                    <th>
                        Month
                    </th>


                    <?php if (!$branch): ?>

                        <th>
                            Branch
                        </th>

                    <?php endif; ?>


                    <th class="text-end">
                        Beginning
                    </th>


                    <th class="text-end">
                        Purchases
                    </th>


                    <th class="text-end">
                        Ending
                    </th>


                    <th class="text-end">
                        COGS
                    </th>


                    <th>
                        Notes
                    </th>

                </tr>

            </thead>


            <tbody>


            <?php if ($inventoryHistory): ?>


                <?php foreach (
                    $inventoryHistory
                    as $h
                ): ?>


                    <?php

                    $hStart =
                        $h['inventory_date'];

                    $hEnding =
                        (float)(
                            $h['ending_inventory']
                        );


                    /*
                     * PREVIOUS MONTH
                     */

                    $hDate =
                        new DateTime($hStart);

                    $hPreviousDate =
                        clone $hDate;

                    $hPreviousDate
                        ->modify('-1 month');

                    $hPreviousStart =
                        $hPreviousDate
                        ->format('Y-m-d');


                    /*
                     * PREVIOUS ENDING
                     */

                    $hs = $pdo->prepare("
                        SELECT ending_inventory
                        FROM inventory
                        WHERE branch_id = ?
                          AND inventory_date = ?
                        ORDER BY id DESC
                        LIMIT 1
                    ");

                    $hs->execute([
                        (int)$h['branch_id'],
                        $hPreviousStart
                    ]);

                    $historyPreviousEnding =
                        $hs->fetchColumn();


                    if (
                        $historyPreviousEnding !== false
                        &&
                        $historyPreviousEnding !== null
                    ) {

                        $hBeginning =
                            (float)$historyPreviousEnding;

                    } else {

                        $hBeginning =
                            (float)(
                                $h['beginning_inventory']
                            );
                    }


                    /*
                     * NEXT MONTH
                     */

                    $hNextDate =
                        clone $hDate;

                    $hNextDate
                        ->modify('+1 month');

                    $hNext =
                        $hNextDate
                        ->format('Y-m-d');


                    /*
                     * HISTORY PURCHASES
                     */

                    $ps = $pdo->prepare("
                        SELECT COALESCE(
                            SUM(total_amount),
                            0
                        )
                        FROM purchases
                        WHERE purchase_date >= ?
                          AND purchase_date < ?
                          AND branch_id = ?
                    ");

                    $ps->execute([
                        $hStart,
                        $hNext,
                        (int)$h['branch_id']
                    ]);

                    $hPurchases =
                        (float)(
                            $ps->fetchColumn()
                            ?: 0
                        );


                    /*
                     * HISTORY COGS
                     */

                    $hCogs =
                        $hBeginning
                        +
                        $hPurchases
                        -
                        $hEnding;

                    ?>


                    <tr>


                        <td>

                            <strong>

                                <?=date(
                                    'M Y',
                                    strtotime(
                                        $hStart
                                    )
                                )?>

                            </strong>

                        </td>


                        <?php if (!$branch): ?>

                            <td>

                                <?=htmlspecialchars(
                                    $h['branch_name']
                                )?>

                            </td>

                        <?php endif; ?>


                        <td class="text-end">

                            ₱<?=number_format(
                                $hBeginning,
                                2
                            )?>

                        </td>


                        <td class="text-end text-primary">

                            ₱<?=number_format(
                                $hPurchases,
                                2
                            )?>

                        </td>


                        <td class="text-end text-warning">

                            ₱<?=number_format(
                                $hEnding,
                                2
                            )?>

                        </td>


                        <td class="text-end text-danger fw-bold">

                            ₱<?=number_format(
                                $hCogs,
                                2
                            )?>

                        </td>


                        <td>

                            <?=htmlspecialchars(
                                $h['notes'] ?? ''
                            )?>

                        </td>

                    </tr>


                <?php endforeach; ?>


            <?php else: ?>


                <tr>

                    <td
                        colspan="<?=$branch ? 6 : 7?>"
                        class="text-center text-muted py-4"
                    >

                        <i class="fa-solid fa-box-open fa-2x mb-2"></i>

                        <br>

                        No inventory records yet.

                    </td>

                </tr>


            <?php endif; ?>


            </tbody>

        </table>

    </div>

</div>


</div>


<?php include "footer.php"; ?>
<?php include "footer.php"; ?>