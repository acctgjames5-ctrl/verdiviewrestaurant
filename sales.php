<?php

session_start();
require_once "auth.php";
require "config.php";

$pageTitle = "Sales";

$action = $_GET['action'] ?? '';
$id = (int)($_GET['id'] ?? 0);

$selectedBranch = (int)(
    $_GET['branch']
    ?? $_SESSION['branch_id']
    ?? 0
);

$uploadMessage = "";
$uploadError = "";


/* =========================================================
   FUNCTIONS
========================================================= */

function branchExists(PDO $pdo, int $branchId): bool
{
    if ($branchId <= 0) {
        return false;
    }

    $stmt = $pdo->prepare("
        SELECT id
        FROM branches
        WHERE id = ?
        AND is_active = 1
        LIMIT 1
    ");

    $stmt->execute([$branchId]);

    return (bool)$stmt->fetchColumn();
}


function calculateSaleTotal(
    float $amount,
    float $serviceCharge,
    float $discount
): float {
    return max(
        0,
        $amount + $serviceCharge - $discount
    );
}


function calculatePaymentStatus(
    float $total,
    float $amountReceived
): string {
    $total = max(0, $total);

    $amountReceived = max(
        0,
        min($amountReceived, $total)
    );

    if ($total <= 0) {
        return 'PAID';
    }

    if ($amountReceived <= 0) {
        return 'UNPAID';
    }

    if ($amountReceived >= $total) {
        return 'PAID';
    }

    return 'PARTIAL';
}


function cleanMoney($value): float
{
    $value = trim((string)$value);

    $value = str_replace(
        ['₱', 'PHP', ',', ' '],
        '',
        $value
    );

    if ($value === '' || !is_numeric($value)) {
        return 0;
    }

    return max(0, (float)$value);
}


/* =========================================================
   SPLIT PAYMENT
========================================================= */

function getPaymentBreakdown(
    array $row,
    float $received
): array {
    $cash = max(
        0,
        (float)($row['payment_cash'] ?? 0)
    );

    $gcash = max(
        0,
        (float)($row['payment_gcash'] ?? 0)
    );

    $bank = max(
        0,
        (float)($row['payment_bank_transfer'] ?? 0)
    );

    $debit = max(
        0,
        (float)($row['payment_debit'] ?? 0)
    );

    $splitTotal =
        $cash +
        $gcash +
        $bank +
        $debit;


    /*
     * Backward compatibility for old records.
     */
    if (
        $splitTotal <= 0 &&
        $received > 0
    ) {

        $method = strtolower(
            trim(
                (string)($row['description'] ?? '')
            )
        );

        switch ($method) {

            case 'cash':

                $cash = $received;

                break;


            case 'gcash':

                $gcash = $received;

                break;


            case 'bank transfer':

                $bank = $received;

                break;


            case 'debit':

                $debit = $received;

                break;
        }
    }


    return [

        'cash' =>
            $cash,

        'gcash' =>
            $gcash,

        'bank_transfer' =>
            $bank,

        'debit' =>
            $debit,

        'total' =>
            $cash +
            $gcash +
            $bank +
            $debit
    ];
}


function paymentDescription(array $payments): string
{
    $parts = [];


    if (($payments['cash'] ?? 0) > 0) {

        $parts[] = 'Cash';

    }


    if (($payments['gcash'] ?? 0) > 0) {

        $parts[] = 'GCash';

    }


    if (($payments['bank_transfer'] ?? 0) > 0) {

        $parts[] = 'Bank Transfer';

    }


    if (($payments['debit'] ?? 0) > 0) {

        $parts[] = 'Debit';

    }


    return $parts
        ? implode(' + ', $parts)
        : '';
}


/* =========================================================
   FILTERS
========================================================= */

$from = trim(
    $_GET['from'] ?? ''
);

$to = trim(
    $_GET['to'] ?? ''
);

$q = trim(
    $_GET['q'] ?? ''
);


$status = strtoupper(
    trim(
        $_GET['status'] ?? ''
    )
);


if (!in_array(
    $status,
    ['PAID', 'PARTIAL', 'UNPAID'],
    true
)) {

    $status = '';

}


$sc = strtoupper(
    trim(
        $_GET['sc'] ?? ''
    )
);


if (!in_array(
    $sc,
    ['WITH_SC', 'WITHOUT_SC'],
    true
)) {

    $sc = '';

}


/* =========================================================
   QUICK PAY
   CLICK PAY -> SELECT PAYMENT METHOD
========================================================= */

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['quick_pay'])
) {

    $quickPayId =
        (int)(
            $_POST['quick_pay_id']
            ?? 0
        );


    $quickPayMethod =
        strtolower(
            trim(
                $_POST['quick_pay_method']
                ?? ''
            )
        );

    /* =====================================================
       QUICK PAY NOTES
    ===================================================== */

    $quickPayNotes =
        trim(
            $_POST['quick_pay_notes']
            ?? ''
        );


    /*
     * Only these payment methods are allowed.
     */

    $allowedQuickMethods = [
        'cash',
        'gcash',
        'bank_transfer',
        'debit'
    ];


    if (
        $quickPayId <= 0 ||
        !in_array(
            $quickPayMethod,
            $allowedQuickMethods,
            true
        )
    ) {

        die("
            <div style='
                font-family:Arial;
                padding:30px;
                color:#c62828;
            '>

                <h3>
                    Invalid Payment
                </h3>

                <p>
                    Please select a valid payment method.
                </p>

                <a href='sales.php'>
                    Return to Sales
                </a>

            </div>
        ");

    }


    try {

        /*
         * Start transaction.
         */

        $pdo->beginTransaction();


        /*
         * Lock the sale row.
         */

        $quickStmt = $pdo->prepare("
            SELECT *
            FROM sales
            WHERE id = ?
            
            FOR UPDATE
        ");


        $quickStmt->execute([
            $quickPayId
        ]);


        $quickSale =
            $quickStmt->fetch(
                PDO::FETCH_ASSOC
            );


        if (!$quickSale) {

            throw new Exception(
                "Sales record not found."
            );

        }


        /*
         * Calculate sale total.
         */

        $quickAmount =
            (float)(
                $quickSale['amount']
                ?? 0
            );


        $quickdiscount =
            (float)(
                $quickSale['discount']
                ?? 0
            );


        $quickSC =
            (float)(
                $quickSale['service_charge']
                ?? 0
            );


        $quickTotal =
            calculateSaleTotal(
                $quickAmount,
                $quickSC,
                $quickdiscount
            );


        /*
         * Existing AR.
         */

        $quickAR =
            max(
                0,
                (float)(
                    $quickSale[
                        'accounts_receivable'
                    ]
                    ?? 0
                )
            );


        /*
         * If already paid,
         * don't add another payment.
         */

        if ($quickAR <= 0) {

            $pdo->rollBack();

            header(
                "Location: sales.php?" .
                http_build_query([
                    'branch' =>
                        (int)(
                            $quickSale[
                                'branch_id'
                            ]
                        ),
                    'from' =>
                        $from,
                    'to' =>
                        $to,
                    'q' =>
                        $q,
                    'status' =>
                        $status,
                    'sc' =>
                        $sc
                ])
            );

            exit;

        }


        /*
         * Quick Pay amount is automatically
         * the remaining balance.
         */

        $quickPayment =
            $quickAR;


        /*
         * Existing received amount.
         */

        $quickReceived =
            max(
                0,
                $quickTotal -
                $quickAR
            );


        /*
         * Get existing payment breakdown.
         */

        $quickPayments =
            getPaymentBreakdown(
                $quickSale,
                $quickReceived
            );


        /*
         * Add the new payment to
         * the selected method.
         */

        switch ($quickPayMethod) {

            case 'cash':

                $quickPayments['cash'] +=
                    $quickPayment;

                break;


            case 'gcash':

                $quickPayments['gcash'] +=
                    $quickPayment;

                break;


            case 'bank_transfer':

                $quickPayments['bank_transfer'] +=
                    $quickPayment;

                break;


            case 'debit':

                $quickPayments['debit'] +=
                    $quickPayment;

                break;

        }


        /*
         * New received amount.
         */

        $newReceived =
            min(
                $quickTotal,
                $quickReceived +
                $quickPayment
            );


        /*
         * New AR.
         */

        $newAR =
            max(
                0,
                $quickTotal -
                $newReceived
            );


        /*
         * New status.
         */

        $newRemarks =
            calculatePaymentStatus(
                $quickTotal,
                $newReceived
            );


        /*
         * New payment description.
         */

        $newDescription =
            paymentDescription(
                $quickPayments
            );


        /*
         * Update sale.
         */

        $quickUpdate = $pdo->prepare("
            UPDATE sales
            SET
                description = ?,
                payment_cash = ?,
                payment_gcash = ?,
                payment_bank_transfer = ?,
                payment_debit = ?,
                remarks = ?,
                accounts_receivable = ?,
                notes = ?
            WHERE id = ?
        
        ");


        $quickUpdate->execute([

            $newDescription,

            $quickPayments['cash'],

            $quickPayments['gcash'],

            $quickPayments['bank_transfer'],

            $quickPayments['debit'],

            $newRemarks,

            $newAR,

            $quickPayNotes,

            $quickPayId

        ]);


        /*
         * Commit.
         */

        $pdo->commit();


        /*
         * Return to the same filtered sales page.
         */

        header(
            "Location: sales.php?" .
            http_build_query([

                'branch' =>
                    (int)(
                        $quickSale[
                            'branch_id'
                        ]
                    ),

                'from' =>
                    $from,

                'to' =>
                    $to,

                'q' =>
                    $q,

                'status' =>
                    $status,

                'sc' =>
                    $sc

            ])
        );

        exit;


    } catch (Throwable $e) {

        if (
            $pdo->inTransaction()
        ) {

            $pdo->rollBack();

        }


        die("
            <div style='
                font-family:Arial;
                padding:30px;
                color:#c62828;
            '>

                <h3>
                    Payment Failed
                </h3>

                <p>
                    " .
                    htmlspecialchars(
                        $e->getMessage()
                    )
                    . "
                </p>

                <a href='javascript:history.back()'>
                    Go Back
                </a>

            </div>
        ");

    }
}


/* =========================================================
   DELETE
========================================================= */

if (
    $action === 'delete' &&
    $id > 0
) {

    $findDelete = $pdo->prepare("
        SELECT branch_id
        FROM sales
        WHERE id = ?
        LIMIT 1
    ");


    $findDelete->execute([
        $id
    ]);


    $deleteBranch =
        (int)(
            $findDelete->fetchColumn()
            ?: $selectedBranch
        );


   $deleteStmt = $pdo->prepare("
    DELETE FROM sales
    WHERE id = ?
");


    $deleteStmt->execute([
        $id
    ]);


    header(
        "Location: sales.php?branch=" .
        urlencode(
            $deleteBranch
        )
    );


    exit;
}


/* =========================================================
   ADD CUSTOMER
========================================================= */

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['add_customer'])
) {

    $customerName =
        trim(
            $_POST['customer_name']
            ?? ''
        );


    $returnBranch =
        (int)(
            $_POST['customer_branch_id']
            ?? $selectedBranch
        );


    if ($customerName === '') {

        die("
            <div style='
                font-family:Arial;
                padding:30px;
                color:#c62828
            '>

                <h3>
                    Invalid Customer
                </h3>

                <p>
                    Customer name is required.
                </p>

                <a href='javascript:history.back()'>
                    Go Back
                </a>

            </div>
        ");

    }


    $customerCheck = $pdo->prepare("
        SELECT id, customer_name
        FROM customers
        WHERE customer_name = ?
        LIMIT 1
    ");


    $customerCheck->execute([
        $customerName
    ]);


    $existingCustomer =
        $customerCheck->fetch(
            PDO::FETCH_ASSOC
        );


    if ($existingCustomer) {

        $savedCustomerName =
            $existingCustomer[
                'customer_name'
            ];

    } else {

        $customerInsert = $pdo->prepare("
            INSERT INTO customers
            (customer_name)
            VALUES (?)
        ");


        $customerInsert->execute([
            $customerName
        ]);


        $savedCustomerName =
            $customerName;

    }


    header(
        "Location: sales.php?branch=" .
        urlencode($returnBranch) .
        "&action=add&customer=" .
        urlencode($savedCustomerName)
    );


    exit;
}


/* =========================================================
   CSV UPLOAD
========================================================= */

elseif (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['upload_csv'])
) {

    $branch =
        (int)(
            $_POST['csv_branch_id']
            ?? 0
        );


    if (!branchExists($pdo, $branch)) {

        $uploadError =
            "Invalid branch selected. Please select an active branch.";

    } elseif (
        !isset($_FILES['csv_file']) ||
        $_FILES['csv_file']['error']
        !== UPLOAD_ERR_OK
    ) {

        $uploadError =
            "Please select a valid CSV file.";

    } else {

        $fileName =
            $_FILES['csv_file']['name'];

        $fileTmp =
            $_FILES['csv_file']['tmp_name'];


        $extension =
            strtolower(
                pathinfo(
                    $fileName,
                    PATHINFO_EXTENSION
                )
            );


        if ($extension !== 'csv') {

            $uploadError =
                "Only CSV files are allowed.";

        } else {

            $handle =
                fopen(
                    $fileTmp,
                    "r"
                );


            if (!$handle) {

                $uploadError =
                    "Unable to read the CSV file.";

            } else {

                $inserted = 0;
                $skipped = 0;
                $rowNumber = 0;
                $errors = [];


                $header =
                    fgetcsv(
                        $handle,
                        0,
                        ','
                    );


                if (!$header) {

                    $uploadError =
                        "CSV file is empty.";

                } else {

                    /*
                     * REMOVE BOM
                     */

                    if (
                        isset($header[0]) &&
                        str_starts_with(
                            $header[0],
                            "\xEF\xBB\xBF"
                        )
                    ) {

                        $header[0] =
                            substr(
                                $header[0],
                                3
                            );

                    }


                    /*
                     * NORMALIZE HEADER
                     */

                    $normalizedHeader = [];


                    foreach (
                        $header
                        as $h
                    ) {

                        $h =
                            trim($h);


                        $h =
                            preg_replace(
                                '/^\xEF\xBB\xBF/',
                                '',
                                $h
                            );


                        $h =
                            str_replace(
                                ' ',
                                '_',
                                $h
                            );


                        $h =
                            strtolower($h);


                        $normalizedHeader[] =
                            $h;

                    }


                    /*
                     * REQUIRED CSV COLUMNS
                     */

                    $requiredColumns = [

                        'sale_date',

                        'reference_no',

                        'customer',

                        'pax',

                        'discount',

                        'description',

                        'amount',

                        'service_charge',

                        'remarks'

                    ];


                    $missingColumns = [];


                    foreach (
                        $requiredColumns
                        as $required
                    ) {

                        if (
                            !in_array(
                                $required,
                                $normalizedHeader,
                                true
                            )
                        ) {

                            $missingColumns[] =
                                $required;

                        }

                    }


                    if ($missingColumns) {

                        $uploadError =
                            "Invalid CSV header. Missing column(s): " .
                            implode(
                                ', ',
                                $missingColumns
                            );

                    } else {

                        $col = [];


                        foreach (
                            $normalizedHeader
                            as $index => $name
                        ) {

                            $col[$name] =
                                $index;

                        }


                        /*
                         * NOTES OPTIONAL
                         */

                        $hasNotesColumn =
                            isset(
                                $col['notes']
                            );


                        /*
                         * AMOUNT RECEIVED OPTIONAL
                         */

                        $hasAmountReceivedColumn =
                            isset(
                                $col[
                                    'amount_received'
                                ]
                            );


                        $stmt =
                            $pdo->prepare("
                                INSERT INTO sales
                                (
                                    branch_id,
                                    sale_date,
                                    reference_no,
                                    customer,
                                    pax,
                                    discount,
                                    description,
                                    amount,
                                    service_charge,
                                    remarks,
                                    notes,
                                    accounts_receivable
                                )
                                VALUES
                                (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                            ");


                        $pdo->beginTransaction();


                        try {

                            while (
                                (
                                    $data =
                                    fgetcsv(
                                        $handle,
                                        0,
                                        ','
                                    )
                                ) !== false
                            ) {

                                $rowNumber++;


                                /*
                                 * EMPTY ROW
                                 */

                                $isEmpty = true;


                                foreach (
                                    $data
                                    as $value
                                ) {

                                    if (
                                        trim($value)
                                        !== ''
                                    ) {

                                        $isEmpty =
                                            false;

                                        break;

                                    }

                                }


                                if ($isEmpty) {

                                    continue;

                                }


                                /*
                                 * BASIC DATA
                                 */

                                $saleDate =
                                    trim(
                                        $data[
                                            $col[
                                                'sale_date'
                                            ]
                                        ] ?? ''
                                    );


                                $reference =
                                    trim(
                                        $data[
                                            $col[
                                                'reference_no'
                                            ]
                                        ] ?? ''
                                    );


                                $customer =
                                    trim(
                                        $data[
                                            $col[
                                                'customer'
                                            ]
                                        ] ?? ''
                                    );


                                $paxRaw =
                                    trim(
                                        $data[
                                            $col['pax']
                                        ] ?? ''
                                    );


                                $discountRaw =
                                    trim(
                                        $data[
                                            $col['discount']
                                        ] ?? ''
                                    );


                                $description =
                                    trim(
                                        $data[
                                            $col[
                                                'description'
                                            ]
                                        ] ?? ''
                                    );


                                $amountRaw =
                                    trim(
                                        $data[
                                            $col['amount']
                                        ] ?? ''
                                    );


                                $serviceChargeRaw =
                                    trim(
                                        $data[
                                            $col[
                                                'service_charge'
                                            ]
                                        ] ?? ''
                                    );


                                $remarksInput =
                                    strtoupper(
                                        trim(
                                            $data[
                                                $col[
                                                    'remarks'
                                                ]
                                            ] ?? ''
                                        )
                                    );


                                /*
                                 * NOTES
                                 */

                                $notes = '';


                                if (
                                    $hasNotesColumn
                                ) {

                                    $notes =
                                        trim(
                                            $data[
                                                $col[
                                                    'notes'
                                                ]
                                            ] ?? ''
                                        );

                                }


                                /*
                                 * AMOUNT RECEIVED
                                 */

                                $amountReceivedRaw =
                                    '';


                                if (
                                    $hasAmountReceivedColumn
                                ) {

                                    $amountReceivedRaw =
                                        trim(
                                            $data[
                                                $col[
                                                    'amount_received'
                                                ]
                                            ] ?? ''
                                        );

                                }


                                /*
                                 * DATE
                                 */

                                $dateValid =
                                    DateTime::createFromFormat(
                                        'Y-m-d',
                                        $saleDate
                                    );


                                if (
                                    !$dateValid ||
                                    $dateValid->format(
                                        'Y-m-d'
                                    )
                                    !==
                                    $saleDate
                                ) {

                                    $skipped++;


                                    $errors[] =
                                        "Row " .
                                        ($rowNumber + 1) .
                                        ": Invalid date.";


                                    continue;

                                }


                                /*
                                 * pax
                                 */

                                $cleanpax =
                                    str_replace(
                                        [',', ' '],
                                        '',
                                        $paxRaw
                                    );


                                if (
                                    $cleanpax === ''
                                ) {

                                    $pax = 0;

                                } elseif (
                                    !is_numeric(
                                        $cleanpax
                                    )
                                ) {

                                    $skipped++;


                                    $errors[] =
                                        "Row " .
                                        ($rowNumber + 1) .
                                        ": Invalid pax.";


                                    continue;

                                } else {

                                    $pax =
                                        max(
                                            0,
                                            (float)$cleanpax
                                        );

                                }


                                /*
                                 * discount
                                 */

                                $cleandiscount =
                                    str_replace(
                                        [
                                            '₱',
                                            'PHP',
                                            ',',
                                            ' '
                                        ],
                                        '',
                                        $discountRaw
                                    );


                                if (
                                    $cleandiscount === ''
                                ) {

                                    $discount = 0;

                                } elseif (
                                    !is_numeric(
                                        $cleandiscount
                                    )
                                ) {

                                    $skipped++;


                                    $errors[] =
                                        "Row " .
                                        ($rowNumber + 1) .
                                        ": Invalid discount.";


                                    continue;

                                } else {

                                    $discount =
                                        max(
                                            0,
                                            (float)$cleandiscount
                                        );

                                }


                                /*
                                 * AMOUNT
                                 */

                                $cleanAmount =
                                    str_replace(
                                        [
                                            '₱',
                                            'PHP',
                                            ',',
                                            ' '
                                        ],
                                        '',
                                        $amountRaw
                                    );


                                if (
                                    $cleanAmount === '' ||
                                    !is_numeric(
                                        $cleanAmount
                                    )
                                ) {

                                    $skipped++;


                                    $errors[] =
                                        "Row " .
                                        ($rowNumber + 1) .
                                        ": Invalid amount.";


                                    continue;

                                }


                                $amount =
                                    max(
                                        0,
                                        (float)$cleanAmount
                                    );


                                /*
                                 * SERVICE CHARGE
                                 */

                                $cleanServiceCharge =
                                    str_replace(
                                        [
                                            '₱',
                                            'PHP',
                                            ',',
                                            ' '
                                        ],
                                        '',
                                        $serviceChargeRaw
                                    );


                                if (
                                    $cleanServiceCharge === ''
                                ) {

                                    $serviceCharge = 0;

                                } elseif (
                                    !is_numeric(
                                        $cleanServiceCharge
                                    )
                                ) {

                                    $skipped++;


                                    $errors[] =
                                        "Row " .
                                        ($rowNumber + 1) .
                                        ": Invalid Service Charge.";


                                    continue;

                                } else {

                                    $serviceCharge =
                                        max(
                                            0,
                                            (float)$cleanServiceCharge
                                        );

                                }


                                /*
                                 * TOTAL
                                 */

                                $rowTotal =
                                    calculateSaleTotal(
                                        $amount,
                                        $serviceCharge,
                                        $discount
                                    );


                                /*
                                 * AMOUNT RECEIVED
                                 */

                                if (
                                    $amountReceivedRaw
                                    !== ''
                                ) {

                                    $cleanReceived =
                                        str_replace(
                                            [
                                                '₱',
                                                'PHP',
                                                ',',
                                                ' '
                                            ],
                                            '',
                                            $amountReceivedRaw
                                        );


                                    if (
                                        !is_numeric(
                                            $cleanReceived
                                        )
                                    ) {

                                        $skipped++;


                                        $errors[] =
                                            "Row " .
                                            ($rowNumber + 1) .
                                            ": Invalid Amount Received.";


                                        continue;

                                    }


                                    $amountReceived =
                                        max(
                                            0,
                                            (float)$cleanReceived
                                        );

                                } else {

                                    if (
                                        $remarksInput ===
                                        'PAID'
                                    ) {

                                        $amountReceived =
                                            $rowTotal;

                                    } else {

                                        $amountReceived =
                                            0;

                                    }

                                }


                                $amountReceived =
                                    min(
                                        $amountReceived,
                                        $rowTotal
                                    );


                                /*
                                 * AR
                                 */

                                $accountsReceivable =
                                    max(
                                        0,
                                        $rowTotal -
                                        $amountReceived
                                    );


                                /*
                                 * STATUS
                                 */

                                $remarks =
                                    calculatePaymentStatus(
                                        $rowTotal,
                                        $amountReceived
                                    );


                                /*
                                 * INSERT
                                 */

                                $stmt->execute([

                                    $branch,

                                    $saleDate,

                                    $reference,

                                    $customer,

                                    $pax,

                                    $discount,

                                    $description,

                                    $amount,

                                    $serviceCharge,

                                    $remarks,

                                    $notes,

                                    $accountsReceivable

                                ]);


                                $inserted++;

                            }


                            $pdo->commit();


                            $uploadMessage =
                                "CSV import completed successfully. " .
                                $inserted .
                                " record(s) imported.";


                            if (
                                $skipped > 0
                            ) {

                                $uploadMessage .=
                                    " " .
                                    $skipped .
                                    " invalid row(s) skipped.";

                            }


                        } catch (
                            Throwable $e
                        ) {

                            if (
                                $pdo->inTransaction()
                            ) {

                                $pdo->rollBack();

                            }


                            $uploadError =
                                "CSV import failed: " .
                                $e->getMessage();

                        }

                    }

                }


                fclose($handle);

            }

        }

    }
}


/* =========================================================
   ADD / UPDATE SALE
========================================================= */

elseif (
    $_SERVER['REQUEST_METHOD'] === 'POST'
) {

    $id =
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
        $_POST['sale_date']
        ?? date('Y-m-d');


    $ref =
        trim(
            $_POST['reference_no']
            ?? ''
        );


    $customer =
        trim(
            $_POST['customer']
            ?? 'FOOD SALES'
        );


    $pax =
        max(
            0,
            (float)(
                $_POST['pax']
                ?? 0
            )
        );


    $discount =
        max(
            0,
            (float)(
                $_POST['discount']
                ?? 0
            )
        );


    $amount =
        max(
            0,
            (float)(
                $_POST['amount']
                ?? 0
            )
        );


    $service_charge =
        max(
            0,
            (float)(
                $_POST['service_charge']
                ?? 0
            )
        );


    /*
     * NOTES
     */

    $notes =
        trim(
            $_POST['notes']
            ?? ''
        );


    /*
     * SPLIT PAYMENT
     */

    $paymentCash =
        cleanMoney(
            $_POST['payment_cash']
            ?? 0
        );


    $paymentGcash =
        cleanMoney(
            $_POST['payment_gcash']
            ?? 0
        );


    $paymentBankTransfer =
        cleanMoney(
            $_POST[
                'payment_bank_transfer'
            ]
            ?? 0
        );


    $paymentDebit =
        cleanMoney(
            $_POST['payment_debit']
            ?? 0
        );


    $amountReceived =
        $paymentCash +
        $paymentGcash +
        $paymentBankTransfer +
        $paymentDebit;


    /*
     * CHECK BRANCH
     */

    if (!branchExists(
        $pdo,
        $branch
    )) {

        die("
            <div style='
                font-family:Arial;
                padding:30px;
                color:#c62828
            '>

                <h3>
                    Invalid Branch
                </h3>

                <p>
                    The selected branch does not exist
                    or is inactive.
                </p>

                <a href='sales.php'>
                    Return to Sales
                </a>

            </div>
        ");

    }


    /*
     * TOTAL
     */

    $saleTotal =
        calculateSaleTotal(
            $amount,
            $service_charge,
            $discount
        );


    /*
     * PAYMENT LIMIT
     */

    if (
        $amountReceived >
        $saleTotal
    ) {

        die("
            <div style='
                font-family:Arial;
                padding:30px;
                color:#c62828
            '>

                <h3>
                    Invalid Payment Amount
                </h3>

                <p>
                    Total split payment cannot be greater
                    than the sale total.
                </p>

                <a href='javascript:history.back()'>
                    Go Back
                </a>

            </div>
        ");

    }


    /*
     * PAYMENT DESCRIPTION
     */

    $splitPayments = [

        'cash' =>
            $paymentCash,

        'gcash' =>
            $paymentGcash,

        'bank_transfer' =>
            $paymentBankTransfer,

        'debit' =>
            $paymentDebit

    ];


    $desc =
        paymentDescription(
            $splitPayments
        );


    /*
     * ACCOUNT RECEIVABLE
     */

    $accountsReceivable =
        max(
            0,
            $saleTotal -
            $amountReceived
        );


    /*
     * STATUS
     */

    $remarks =
        calculatePaymentStatus(
            $saleTotal,
            $amountReceived
        );


    /*
     * UPDATE
     */

    if ($id > 0) {

        $s =
            $pdo->prepare("
                UPDATE sales
                SET
                    branch_id = ?,
                    sale_date = ?,
                    reference_no = ?,
                    customer = ?,
                    pax = ?,
                    discount = ?,
                    description = ?,
                    amount = ?,
                    service_charge = ?,
                    notes = ?,
                    payment_cash = ?,
                    payment_gcash = ?,
                    payment_bank_transfer = ?,
                    payment_debit = ?,
                    remarks = ?,
                    accounts_receivable = ?
                WHERE id = ?
              
            ");


        $s->execute([

            $branch,

            $date,

            $ref,

            $customer,

            $pax,

            $discount,

            $desc,

            $amount,

            $service_charge,

            $notes,

            $paymentCash,

            $paymentGcash,

            $paymentBankTransfer,

            $paymentDebit,

            $remarks,

            $accountsReceivable,

            $id

        ]);

    } else {

        /*
         * INSERT
         */

        $s =
            $pdo->prepare("
                INSERT INTO sales
                (
                    branch_id,
                    sale_date,
                    reference_no,
                    customer,
                    pax,
                    discount,
                    description,
                    amount,
                    service_charge,
                    notes,
                    payment_cash,
                    payment_gcash,
                    payment_bank_transfer,
                    payment_debit,
                    remarks,
                    accounts_receivable
                )
                VALUES
                (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");


        $s->execute([

            $branch,

            $date,

            $ref,

            $customer,

            $pax,

            $discount,

            $desc,

            $amount,

            $service_charge,

            $notes,

            $paymentCash,

            $paymentGcash,

            $paymentBankTransfer,

            $paymentDebit,

            $remarks,

            $accountsReceivable

        ]);

    }


    header(
        "Location: sales.php?branch=" .
        urlencode($branch)
    );


    exit;
}


/* =========================================================
   EDIT
========================================================= */

$edit = null;


if (
    $action === 'edit' &&
    $id > 0
) {

    $s =
        $pdo->prepare("
            SELECT *
            FROM sales
            WHERE id = ?
            LIMIT 1
        ");


    $s->execute([
        $id
    ]);


    $edit =
        $s->fetch(
            PDO::FETCH_ASSOC
        );


    if (!$edit) {

        header(
            "Location: sales.php?branch=" .
            urlencode(
                $selectedBranch
            )
        );


        exit;

    }


    $selectedBranch =
        (int)$edit['branch_id'];

}


/* =========================================================
   BRANCHES
========================================================= */

$branches =
    $pdo->query("
        SELECT *
        FROM branches
        WHERE is_active = 1
        ORDER BY branch_name
    ")
    ->fetchAll(
        PDO::FETCH_ASSOC
    );


/* =========================================================
   CUSTOMERS
========================================================= */

$customers =
    $pdo->query("
        SELECT id, customer_name
        FROM customers
        ORDER BY customer_name ASC
    ")
    ->fetchAll(
        PDO::FETCH_ASSOC
    );


$selectedCustomer =
    trim(
        $_GET['customer']
        ?? (
            $edit['customer']
            ?? 'FOOD SALES'
        )
    );


/* =========================================================
   GET SALES
========================================================= */

$where = [];
$params = [];


/*
 * BRANCH
 */

if ($selectedBranch > 0) {

    $where[] =
        "s.branch_id = ?";

    $params[] =
        $selectedBranch;

}


/*
 * FROM
 */

if ($from !== '') {

    $where[] =
        "s.sale_date >= ?";

    $params[] =
        $from;

}


/*
 * TO
 */

if ($to !== '') {

    $where[] =
        "s.sale_date <= ?";

    $params[] =
        $to;

}


/*
 * SEARCH
 */

if ($q !== '') {

    $where[] = "
        (
            s.reference_no LIKE ?
            OR s.customer LIKE ?
            OR s.description LIKE ?
            OR s.remarks LIKE ?
            OR s.notes LIKE ?
        )
    ";


    $search =
        "%{$q}%";


    $params[] =
        $search;

    $params[] =
        $search;

    $params[] =
        $search;

    $params[] =
        $search;

    $params[] =
        $search;

}


/*
 * STATUS
 */

if ($status !== '') {

    $where[] =
        "UPPER(s.remarks) = ?";

    $params[] =
        $status;

}


/*
 * SERVICE CHARGE
 */

if ($sc === 'WITH_SC') {

    $where[] = "
        COALESCE(
            s.service_charge,
            0
        ) > 0
    ";

} elseif (
    $sc === 'WITHOUT_SC'
) {

    $where[] = "
        COALESCE(
            s.service_charge,
            0
        ) <= 0
    ";

}


/* =========================================================
   QUERY
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


$sql .= "
    ORDER BY
        s.sale_date DESC,
        s.id DESC
";


$s =
    $pdo->prepare($sql);


$s->execute($params);


$rows =
    $s->fetchAll(
        PDO::FETCH_ASSOC
    );


/* =========================================================
   TOTALS
========================================================= */

$totalSales = 0;
$totaldiscount = 0;
$totalServiceCharge = 0;
$totalAccountsReceivable = 0;

$totalCash = 0;
$totalGcash = 0;
$totalBankTransfer = 0;
$totaldebit = 0;

$totalPaid = 0;
$totalPartial = 0;
$totalUnpaid = 0;

$grandTotal = 0;


/* =========================================================
   COMPUTE TOTALS
========================================================= */

foreach (
    $rows
    as $r
) {

    $rowAmount =
        (float)(
            $r['amount']
            ?? 0
        );


    $rowdiscount =
        (float)(
            $r['discount']
            ?? 0
        );


    $rowServiceCharge =
        (float)(
            $r['service_charge']
            ?? 0
        );


    $rowTotal =
        calculateSaleTotal(
            $rowAmount,
            $rowServiceCharge,
            $rowdiscount
        );


    $rowAR =
        max(
            0,
            (float)(
                $r[
                    'accounts_receivable'
                ]
                ?? 0
            )
        );


    $rowReceived =
        max(
            0,
            $rowTotal -
            $rowAR
        );


    $totalSales +=
        $rowAmount;


    $totaldiscount +=
        $rowdiscount;


    $totalServiceCharge +=
        $rowServiceCharge;


    $totalAccountsReceivable +=
        $rowAR;


    $rowPayments =
        getPaymentBreakdown(
            $r,
            $rowReceived
        );


    $totalCash +=
        $rowPayments['cash'];


    $totalGcash +=
        $rowPayments['gcash'];


    $totalBankTransfer +=
        $rowPayments['bank_transfer'];


    $totaldebit +=
        $rowPayments['debit'];


    $rowRemarks =
        strtoupper(
            trim(
                $r['remarks']
                ?? ''
            )
        );


    if (
        $rowRemarks ===
        'PAID'
    ) {

        $totalPaid +=
            $rowReceived;

    } elseif (
        $rowRemarks ===
        'PARTIAL'
    ) {

        $totalPartial +=
            $rowReceived;

    } else {

        $totalUnpaid +=
            $rowAR;

    }

}


/* =========================================================
   GRAND TOTAL
========================================================= */

$grandTotal =
    $totalPaid
    - $totalServiceCharge
    + $totaldiscount
    + $totalUnpaid;


/* =========================================================
   EDIT PAYMENT
========================================================= */

$editAmountReceived = 0;
$editTotal = 0;
$editAR = 0;


$editPayments = [

    'cash' => 0,

    'gcash' => 0,

    'bank_transfer' => 0,

    'debit' => 0

];


if ($edit) {

    $editAmount =
        (float)(
            $edit['amount']
            ?? 0
        );


    $editdiscount =
        (float)(
            $edit['discount']
            ?? 0
        );


    $editSC =
        (float)(
            $edit['service_charge']
            ?? 0
        );


    $editTotal =
        calculateSaleTotal(
            $editAmount,
            $editSC,
            $editdiscount
        );


    $editAR =
        max(
            0,
            (float)(
                $edit[
                    'accounts_receivable'
                ]
                ?? 0
            )
        );


    $editAmountReceived =
        max(
            0,
            $editTotal -
            $editAR
        );


    $editPayments =
        getPaymentBreakdown(
            $edit,
            $editAmountReceived
        );

}


/* =========================================================
   HEADER
========================================================= */

include "header.php";

?>


<!-- =========================================================
     MESSAGES
========================================================= -->

<?php if ($uploadMessage): ?>

<div class="alert alert-success shadow-sm">

    <i class="fa-solid fa-circle-check me-1"></i>

    <?=htmlspecialchars(
        $uploadMessage
    )?>

</div>

<?php endif; ?>


<?php if ($uploadError): ?>

<div class="alert alert-danger shadow-sm">

    <i class="fa-solid fa-circle-exclamation me-1"></i>

    <?=htmlspecialchars(
        $uploadError
    )?>

</div>

<?php endif; ?>


<style>

/* =========================================================
   GENERAL
========================================================= */

.sales-page{
    width:100%;
}


.sales-header{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:15px;
    margin-bottom:20px;
}


.sales-title{
    margin:0;
    font-size:24px;
    font-weight:800;
    color:#162747;
}


.sales-subtitle{
    color:#8995a8;
    font-size:12px;
    margin-top:3px;
}


/* =========================================================
   BUTTONS
========================================================= */

.btn-add-sales{
    background:linear-gradient(
        135deg,
        #22c77d,
        #2ed895
    );

    border:0;

    color:#fff;

    font-weight:700;

    padding:11px 18px;

    border-radius:8px;
}


.btn-add-sales:hover{
    color:#fff;
}


.btn-csv-upload{
    background:#172642;

    border:0;

    color:#fff;

    font-weight:700;

    padding:11px 18px;

    border-radius:8px;
}


.btn-csv-upload:hover{
    background:#0f1d35;

    color:#fff;
}


/* =========================================================
   CARD
========================================================= */

.sales-card{
    background:#fff;

    border:1px solid #edf0f5;

    border-radius:11px;

    box-shadow:
        0 4px 16px
        rgba(30,50,80,.04);

    overflow:hidden;

    margin-bottom:18px;
}


.sales-card-header{
    padding:15px 18px;

    border-bottom:1px solid #edf0f5;

    display:flex;

    align-items:center;

    justify-content:space-between;

    gap:20px;
}


.sales-card-title{
    font-size:14px;

    font-weight:800;

    color:#162747;
}


.sales-card-title i{
    color:#2169e8;

    margin-right:7px;
}


.sales-card-body{
    padding:20px;
}


/* =========================================================
   INPUTS
========================================================= */

.sales-label{
    font-size:12px;

    font-weight:700;

    color:#4b5a73;

    margin-bottom:6px;
}


.sales-input{
    min-height:42px;

    border:1px solid #dfe5ee;

    border-radius:7px;

    font-size:13px;
}


.sales-input:focus{
    border-color:#2169e8;

    box-shadow:
        0 0 0 3px
        rgba(33,105,232,.09);
}


.btn-save{
    background:#2169e8;

    border:0;

    color:#fff;

    font-weight:700;

    padding:10px 20px;

    border-radius:7px;
}


/* =========================================================
   PAYMENT SUMMARY
========================================================= */

.payment-summary{
    background:#f8fbff;

    border:1px solid #dfeafb;

    border-radius:9px;

    padding:14px;
}


.payment-summary-item{
    display:flex;

    justify-content:space-between;

    align-items:center;

    padding:5px 0;

    font-size:12px;
}


.payment-summary-label{
    color:#69768b;

    font-weight:700;
}


.payment-summary-value{
    font-weight:900;

    color:#162747;
}


.payment-summary-total{
    color:#2169e8;

    font-size:16px;
}


.payment-summary-received{
    color:#16ad6c;

    font-size:16px;
}


.payment-summary-ar{
    color:#e67e22;

    font-size:16px;
}


/* =========================================================
   FILTER
========================================================= */

.filter-card{
    background:#fff;

    border:1px solid #edf0f5;

    border-radius:11px;

    box-shadow:
        0 4px 16px
        rgba(30,50,80,.04);

    padding:17px;

    margin-bottom:18px;
}


.filter-title{
    font-size:13px;

    font-weight:800;

    color:#162747;

    margin-bottom:12px;
}


.filter-label{
    display:block;

    font-size:11px;

    font-weight:700;

    color:#64718a;

    margin-bottom:5px;
}


.filter-input{
    height:40px;

    border:1px solid #dfe5ee;

    border-radius:7px;

    font-size:12px;
}


.btn-filter{
    height:40px;

    background:#172642;

    color:#fff;

    border:0;

    border-radius:7px;

    font-weight:700;

    width:100%;
}


.btn-reset{
    height:40px;

    border:1px solid #dfe5ee;

    background:#fff;

    color:#59677f;

    border-radius:7px;

    font-weight:700;

    width:100%;
}


/* =========================================================
   TOTALS
========================================================= */

.total-container{
    display:flex;

    align-items:center;

    gap:22px;

    flex-wrap:wrap;

    justify-content:flex-end;
}


.total-box{
    text-align:right;

    min-width:100px;
}


.total-label{
    display:block;

    font-size:10px;

    color:#8995a8;

    text-transform:uppercase;

    font-weight:700;
}


.total-value{
    color:#16ad6c;

    font-size:16px;

    font-weight:900;
}


.total-discount{
    color:#e04b4b;

    font-size:16px;

    font-weight:900;
}


.total-ar{
    color:#e67e22;

    font-size:17px;

    font-weight:900;
}


.total-grand{
    color:#2169e8;

    font-size:19px;
}


/* =========================================================
   TABLE
========================================================= */

.sales-table{
    margin:0;

    font-size:12px;

    min-width:1700px;
}


.sales-table thead th{
    background:#f8fafd;

    color:#53617a;

    font-size:10px;

    text-transform:uppercase;

    letter-spacing:.3px;

    font-weight:800;

    padding:13px 12px;

    border-bottom:1px solid #e7ebf1;

    white-space:nowrap;
}


.sales-table tbody td{
    padding:13px 12px;

    border-color:#edf0f4;

    vertical-align:middle;

    color:#34425d;
}


.sales-table tbody tr:hover{
    background:#f8fbff;
}


.sale-amount{
    color:#16ad6c!important;

    font-weight:900;

    white-space:nowrap;
}


.sale-discount{
    color:#e04b4b!important;

    font-weight:800;

    white-space:nowrap;
}


.sale-total{
    color:#2169e8!important;

    font-weight:900;

    white-space:nowrap;
}


.sale-received{
    color:#16ad6c!important;

    font-weight:900;

    white-space:nowrap;
}


.sale-ar{
    color:#e67e22!important;

    font-weight:900;

    white-space:nowrap;
}


/* =========================================================
   STATUS
========================================================= */

.badge-paid{
    background:#e8f8ef;

    color:#15945b;

    border:1px solid #bdebd0;

    font-weight:800;

    padding:6px 9px;
}


.badge-partial{
    background:#fff7dc;

    color:#b77900;

    border:1px solid #f4dc94;

    font-weight:800;

    padding:6px 9px;
}


.badge-unpaid{
    background:#fff1e7;

    color:#d96b19;

    border:1px solid #ffd0ae;

    font-weight:800;

    padding:6px 9px;
}


/* =========================================================
   SERVICE CHARGE
========================================================= */

.badge-sc{
    background:#eaf3ff;

    color:#2169e8;

    border:1px solid #bfd7ff;

    font-weight:800;

    padding:5px 8px;
}


.badge-no-sc{
    background:#f3f4f6;

    color:#7b8494;

    border:1px solid #dfe2e7;

    font-weight:800;

    padding:5px 8px;
}


/* =========================================================
   ACTION
========================================================= */

.action-btn{
    border-radius:6px;

    font-size:10px;

    font-weight:700;

    padding:6px 10px;

    margin:2px;
}


/* =========================================================
   QUICK PAY BUTTON
========================================================= */

.quick-pay-btn{
    background:#16ad6c;

    color:#fff;

    border:0;

    border-radius:6px;

    font-size:10px;

    font-weight:800;

    padding:6px 11px;

    margin:2px;

    transition:.15s;
}


.quick-pay-btn:hover{
    background:#10945b;

    color:#fff;

    transform:translateY(-1px);
}


/* =========================================================
   EMPTY
========================================================= */

.empty-sales{
    padding:45px 20px;

    text-align:center;

    color:#98a3b4;
}


/* =========================================================
   SPLIT PAYMENT
========================================================= */

.split-payment-box{
    background:#f8fbff;

    border:1px solid #dfeafb;

    border-radius:9px;

    padding:14px;
}


.split-payment-grid{
    display:grid;

    grid-template-columns:
        repeat(4,minmax(0,1fr));

    gap:12px;
}


.split-label{
    display:block;

    font-size:11px;

    font-weight:800;

    color:#64718a;

    margin-bottom:5px;
}


.split-payment-note{
    margin-top:10px;

    font-size:11px;

    color:#69768b;
}


.split-payment-total-row{
    display:flex;

    justify-content:space-between;

    align-items:center;

    margin-top:12px;

    padding-top:10px;

    border-top:1px solid #dfeafb;

    font-size:12px;

    font-weight:800;

    color:#53617a;
}


.split-payment-total-row strong{
    color:#2169e8;

    font-size:16px;
}


/* =========================================================
   PAYMENT BREAKDOWN
========================================================= */

.payment-breakdown{
    display:flex;

    flex-direction:column;

    gap:2px;

    margin-top:5px;

    font-size:10px;

    color:#53617a;
}


.payment-breakdown span{
    white-space:nowrap;
}


/* =========================================================
   CUSTOMER
========================================================= */

.customer-select-wrap{
    display:flex;

    gap:7px;

    align-items:center;
}


.customer-select-wrap .sales-input{
    flex:1;

    min-width:0;
}


.btn-add-customer{
    height:42px;

    min-width:42px;

    border:0;

    border-radius:7px;

    background:#2169e8;

    color:#fff;

    font-weight:800;

    display:flex;

    align-items:center;

    justify-content:center;
}


/* =========================================================
   NOTES
========================================================= */

.notes-box{
    min-height:80px;

    resize:vertical;
}


.sale-notes{
    max-width:250px;

    min-width:150px;

    white-space:normal;

    word-break:break-word;

    line-height:1.4;
}


/* =========================================================
   CSV
========================================================= */

.csv-info{
    background:#f7f9fc;

    border:1px solid #e8edf4;

    border-radius:8px;

    padding:12px;

    font-size:11px;

    color:#64718a;

    margin-top:12px;
}


.csv-example{
    margin-top:8px;

    background:#172642;

    color:#dce7f5;

    padding:10px;

    border-radius:6px;

    font-family:monospace;

    font-size:10px;

    overflow-x:auto;

    white-space:nowrap;
}


.csv-upload-submit{
    background:#16ad6c;

    border:0;

    color:#fff;

    font-weight:700;

    padding:10px 18px;

    border-radius:7px;
}


.csv-modal-icon{
    width:50px;

    height:50px;

    border-radius:12px;

    background:#eaf7ef;

    color:#16a765;

    display:flex;

    align-items:center;

    justify-content:center;

    font-size:23px;

    margin-bottom:12px;
}


/* =========================================================
   QUICK PAY MODAL
========================================================= */

.quick-pay-modal-icon{
    width:64px;

    height:64px;

    border-radius:18px;

    background:#e8f8ef;

    color:#16ad6c;

    display:flex;

    align-items:center;

    justify-content:center;

    font-size:28px;

    margin:0 auto 12px;
}


.quick-pay-balance{
    background:#fff7dc;

    border:1px solid #f4dc94;

    border-radius:10px;

    padding:14px;

    text-align:center;

    margin-bottom:18px;
}


.quick-pay-balance-label{
    display:block;

    font-size:10px;

    text-transform:uppercase;

    font-weight:800;

    color:#927000;

    margin-bottom:3px;
}


.quick-pay-balance-value{
    display:block;

    color:#e67e22;

    font-size:25px;

    font-weight:900;
}


.quick-pay-methods{
    display:grid;

    grid-template-columns:
        repeat(2,minmax(0,1fr));

    gap:10px;
}


.quick-pay-method{
    border:1px solid #dfe5ee;

    background:#fff;

    border-radius:10px;

    padding:14px 10px;

    cursor:pointer;

    transition:.15s;

    text-align:center;

    font-weight:800;

    color:#34425d;
}


.quick-pay-method:hover{
    border-color:#2169e8;

    background:#f8fbff;

    transform:translateY(-1px);
}


.quick-pay-method i{
    display:block;

    font-size:23px;

    margin-bottom:7px;

    color:#2169e8;
}


.quick-pay-method.cash i{
    color:#16ad6c;
}


.quick-pay-method.gcash i{
    color:#2169e8;
}


.quick-pay-method.bank i{
    color:#7952b3;
}


.quick-pay-method.debit i{
    color:#e67e22;
}


.quick-pay-method small{
    display:block;

    font-size:10px;

    font-weight:600;

    color:#8995a8;

    margin-top:3px;
}


/* =========================================================
   RESPONSIVE
========================================================= */

@media(max-width:700px){

    .sales-header{
        align-items:flex-start;

        flex-direction:column;
    }


    .sales-title{
        font-size:20px;
    }


    .sales-card-body{
        padding:15px;
    }


    .sales-card-header{
        align-items:flex-start;

        flex-direction:column;
    }


    .total-container{
        width:100%;

        justify-content:flex-start;

        gap:15px;
    }


    .total-box{
        text-align:left;
    }


    .split-payment-grid{
        grid-template-columns:1fr;
    }


    .quick-pay-methods{
        grid-template-columns:1fr;
    }

}

</style>


<div class="sales-page">


<!-- =========================================================
     HEADER
========================================================= -->

<div class="sales-header">

<div>

<h2 class="sales-title">
    Sales
</h2>


<div class="sales-subtitle">
    Manage and monitor your sales transactions
</div>

</div>


<div class="d-flex gap-2 flex-wrap">


<a
    href="sales.php?action=add&branch=<?=urlencode(
        $selectedBranch
    )?>"
    class="btn btn-add-sales"
>

<i class="fa-solid fa-plus me-1"></i>

Add Sales

</a>


<a
    href="print_sales.php?branch=<?=urlencode(
        $selectedBranch
    )?>&from=<?=urlencode(
        $from
    )?>&to=<?=urlencode(
        $to
    )?>&q=<?=urlencode(
        $q
    )?>&status=<?=urlencode(
        $status
    )?>&sc=<?=urlencode(
        $sc
    )?>"
    target="_blank"
    class="btn btn-success"
>

<i class="fa-solid fa-print me-1"></i>

Print Sales

</a>


<button
    type="button"
    class="btn btn-csv-upload"
    data-bs-toggle="modal"
    data-bs-target="#csvUploadModal"
>

<i class="fa-solid fa-file-csv me-1"></i>

Upload CSV

</button>


</div>

</div>


<!-- =========================================================
     ADD / EDIT FORM
========================================================= -->

<?php if (
    $action === 'add' ||
    $edit
): ?>

<div class="sales-card">


<div class="sales-card-header">


<div class="sales-card-title">

<i class="fa-solid fa-pen-to-square"></i>

<?=(
    $edit
    ? 'Edit Sale'
    : 'Add New Sale'
)?>

</div>


<a
    href="sales.php?branch=<?=urlencode(
        $selectedBranch
    )?>"
    class="btn btn-sm btn-light"
>

<i class="fa-solid fa-xmark"></i>

</a>


</div>


<div class="sales-card-body">


<form
    method="post"
    class="row g-3"
    id="salesForm"
>


<input
    type="hidden"
    name="id"
    value="<?=htmlspecialchars(
        $edit['id'] ?? 0
    )?>"
>


<!-- BRANCH -->

<div class="col-md-3">

<label class="sales-label">
    Branch
</label>


<select
    name="branch_id"
    class="form-select sales-input"
    required
>

<option value="">
    Select Branch
</option>


<?php foreach (
    $branches
    as $b
): ?>

<option
    value="<?=htmlspecialchars(
        $b['id']
    )?>"
    <?=(
        (int)(
            $edit['branch_id']
            ?? $selectedBranch
        )
        ===
        (int)$b['id']
    )
    ? 'selected'
    : ''?>
>

<?=htmlspecialchars(
    $b['branch_name']
)?>

</option>

<?php endforeach; ?>

</select>

</div>


<!-- DATE -->

<div class="col-md-3">

<label class="sales-label">
    Date
</label>


<input
    type="date"
    name="sale_date"
    class="form-control sales-input"
    required
    value="<?=htmlspecialchars(
        $edit['sale_date']
        ?? date('Y-m-d')
    )?>"
>

</div>


<!-- REFERENCE -->

<div class="col-md-3">

<label class="sales-label">
    Reference No.
</label>


<input
    name="reference_no"
    class="form-control sales-input"
    placeholder="Reference number"
    required
    value="<?=htmlspecialchars(
        $edit['reference_no'] ?? ''
    )?>"
>

</div>


<!-- CUSTOMER -->

<div class="col-md-3">

<label class="sales-label">
    Customer
</label>


<div class="customer-select-wrap">


<select
    name="customer"
    id="customerSelect"
    class="form-select sales-input"
    required
>

<option value="">
    Select Customer
</option>


<?php foreach (
    $customers
    as $customer
): ?>

<option
    value="<?=htmlspecialchars(
        $customer['customer_name']
    )?>"
    <?=(
        $selectedCustomer ===
        $customer['customer_name']
    )
    ? 'selected'
    : ''?>
>

<?=htmlspecialchars(
    $customer['customer_name']
)?>

</option>

<?php endforeach; ?>

</select>


<button
    type="button"
    class="btn btn-add-customer"
    data-bs-toggle="modal"
    data-bs-target="#addCustomerModal"
    title="Add Customer"
>

<i class="fa-solid fa-plus"></i>

</button>


</div>

</div>


<!-- pax -->

<div class="col-md-3">

<label class="sales-label">
    pax
</label>


<input
    type="number"
    step="0.01"
    min="0"
    name="pax"
    class="form-control sales-input"
    value="<?=htmlspecialchars(
        $edit['pax'] ?? '0'
    )?>"
>

</div>


<!-- discount -->

<div class="col-md-3">

<label class="sales-label">
    discount
</label>


<div class="input-group">


<span class="input-group-text">
    ₱
</span>


<input
    type="number"
    step="0.01"
    min="0"
    name="discount"
    id="discount"
    class="form-control sales-input"
    value="<?=htmlspecialchars(
        $edit['discount'] ?? '0'
    )?>"
>


</div>

</div>


<!-- AMOUNT -->

<div class="col-md-3">

<label class="sales-label">
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
    id="amount"
    class="form-control sales-input"
    required
    value="<?=htmlspecialchars(
        $edit['amount'] ?? ''
    )?>"
>


</div>

</div>


<!-- SERVICE CHARGE -->

<div class="col-md-3">

<label class="sales-label">
    Service Charge
</label>


<div class="input-group">


<span class="input-group-text">
    ₱
</span>


<input
    type="number"
    step="0.01"
    min="0"
    name="service_charge"
    id="service_charge"
    class="form-control sales-input"
    value="<?=htmlspecialchars(
        $edit['service_charge'] ?? '0'
    )?>"
>


</div>

</div>


<!-- NOTES -->

<div class="col-md-12">

<label class="sales-label">
    Notes
</label>


<textarea
    name="notes"
    class="form-control sales-input notes-box"
    rows="3"
    maxlength="2000"
    placeholder="Enter notes for this sales transaction..."
><?=htmlspecialchars(
    $edit['notes'] ?? ''
)?></textarea>


<small class="text-muted">

<i class="fa-solid fa-circle-info me-1"></i>

Notes are recorded for this sale only.

</small>


</div>


<!-- =====================================================
     SPLIT PAYMENT
===================================================== -->

<div class="col-md-12">

<label class="sales-label">
    PAYMENT
</label>


<div class="split-payment-box">


<div class="split-payment-grid">


<?php

$splitFields = [

    [
        'name' =>
            'payment_cash',

        'id' =>
            'payment_cash',

        'label' =>
            'Cash',

        'key' =>
            'cash'
    ],

    [
        'name' =>
            'payment_gcash',

        'id' =>
            'payment_gcash',

        'label' =>
            'GCash',

        'key' =>
            'gcash'
    ],

    [
        'name' =>
            'payment_bank_transfer',

        'id' =>
            'payment_bank_transfer',

        'label' =>
            'Bank Transfer',

        'key' =>
            'bank_transfer'
    ],

    [
        'name' =>
            'payment_debit',

        'id' =>
            'payment_debit',

        'label' =>
            'Debit',

        'key' =>
            'debit'
    ]

];


foreach (
    $splitFields
    as $pf
):

?>


<div>


<label class="split-label">

<?=htmlspecialchars(
    $pf['label']
)?>

</label>


<div class="input-group">


<span class="input-group-text">
    ₱
</span>


<input
    type="number"
    step="0.01"
    min="0"
    name="<?=htmlspecialchars(
        $pf['name']
    )?>"
    id="<?=htmlspecialchars(
        $pf['id']
    )?>"
    class="form-control sales-input"
    value="<?=htmlspecialchars(
        number_format(
            $editPayments[
                $pf['key']
            ] ?? 0,
            2,
            '.',
            ''
        )
    )?>"
>


</div>

</div>


<?php endforeach; ?>


</div>


<div class="split-payment-note">

<i class="fa-solid fa-circle-info me-1"></i>

Split payment is allowed.

Example:
<b>₱1,000 GCash + ₱1,000 Cash</b>.

</div>


<div class="split-payment-total-row">


<span>
    Total Payment
</span>


<strong id="split_payment_total">

₱<?=number_format(
    $editAmountReceived,
    2
)?>

</strong>


</div>


<input
    type="hidden"
    name="description"
    id="description"
    value="<?=htmlspecialchars(
        $edit['description'] ?? ''
    )?>"
>


</div>

</div>


<!-- AMOUNT RECEIVED -->

<div class="col-md-4">

<label class="sales-label">
    AMOUNT RECEIVED
</label>


<div class="input-group">


<span class="input-group-text">
    ₱
</span>


<input
    type="number"
    step="0.01"
    min="0"
    id="amount_received"
    class="form-control sales-input"
    readonly
    value="<?=htmlspecialchars(
        number_format(
            $editAmountReceived,
            2,
            '.',
            ''
        )
    )?>"
>


</div>


<small class="text-muted">

Automatically calculated from the payment breakdown.

</small>


</div>


<!-- PAYMENT STATUS -->

<div class="col-md-4">

<label class="sales-label">
    PAYMENT STATUS
</label>


<div
    id="payment_status_display"
    class="form-control sales-input"
    style="
        background:#f8fbff;
        font-weight:900;
    "
>


<?php

$displayStatus =
    calculatePaymentStatus(
        $editTotal,
        $editAmountReceived
    );

?>


<?php if (
    $displayStatus === 'PAID'
): ?>

<span class="badge badge-paid">
    PAID
</span>


<?php elseif (
    $displayStatus === 'PARTIAL'
): ?>

<span class="badge badge-partial">
    PARTIAL
</span>


<?php else: ?>

<span class="badge badge-unpaid">
    UNPAID
</span>


<?php endif; ?>


</div>

</div>


<!-- PAYMENT SUMMARY -->

<div class="col-12">


<div class="payment-summary">


<div class="payment-summary-item">


<span class="payment-summary-label">
    SALE TOTAL
</span>


<span
    class="payment-summary-value payment-summary-total"
    id="summary_total"
>

₱<?=number_format(
    $editTotal,
    2
)?>

</span>


</div>


<div class="payment-summary-item">


<span class="payment-summary-label">
    AMOUNT RECEIVED
</span>


<span
    class="payment-summary-value payment-summary-received"
    id="summary_received"
>

₱<?=number_format(
    $editAmountReceived,
    2
)?>

</span>


</div>


<div
    style="
        border-top:1px solid #dfeafb;
        margin:5px 0;
    "
></div>


<div class="payment-summary-item">


<span class="payment-summary-label">
    ACCOUNT RECEIVABLE
</span>


<span
    class="payment-summary-value payment-summary-ar"
    id="summary_ar"
>

₱<?=number_format(
    $editAR,
    2
)?>

</span>


</div>


</div>

</div>


<!-- BUTTONS -->

<div class="col-12 pt-2">


<button
    type="submit"
    class="btn btn-save"
>


<i class="fa-solid fa-floppy-disk me-1"></i>


<?=(
    $edit
    ? 'Update Sale'
    : 'Save Sale'
)?>


</button>


<a
    href="sales.php?branch=<?=urlencode(
        $selectedBranch
    )?>"
    class="btn btn-light ms-1"
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
    class="filter-card"
    method="get"
>


<input
    type="hidden"
    name="branch"
    value="<?=htmlspecialchars(
        $selectedBranch
    )?>"
>


<div class="filter-title">

<i class="fa-solid fa-filter me-1"></i>

Search & Filter Sales

</div>


<div class="row g-2 align-items-end">


<div class="col-xl-2 col-lg-3 col-md-4">

<label class="filter-label">
    From
</label>


<input
    type="date"
    name="from"
    class="form-control filter-input"
    value="<?=htmlspecialchars(
        $from
    )?>"
>

</div>


<div class="col-xl-2 col-lg-3 col-md-4">

<label class="filter-label">
    To
</label>


<input
    type="date"
    name="to"
    class="form-control filter-input"
    value="<?=htmlspecialchars(
        $to
    )?>"
>

</div>


<div class="col-xl-3 col-lg-6 col-md-8">

<label class="filter-label">
    Search
</label>


<input
    name="q"
    class="form-control filter-input"
    value="<?=htmlspecialchars(
        $q
    )?>"
    placeholder="Reference, customer, payment, notes..."
>

</div>


<div class="col-xl-2 col-lg-3 col-md-4">

<label class="filter-label">
    Payment Status
</label>


<select
    name="status"
    class="form-select filter-input"
>


<option value="">
    All Status
</option>


<option
    value="PAID"
    <?=(
        $status === 'PAID'
    )
    ? 'selected'
    : ''?>
>

PAID

</option>


<option
    value="PARTIAL"
    <?=(
        $status === 'PARTIAL'
    )
    ? 'selected'
    : ''?>
>

PARTIAL

</option>


<option
    value="UNPAID"
    <?=(
        $status === 'UNPAID'
    )
    ? 'selected'
    : ''?>
>

UNPAID

</option>


</select>

</div>


<div class="col-xl-2 col-lg-3 col-md-4">

<label class="filter-label">
    Service Charge
</label>


<select
    name="sc"
    class="form-select filter-input"
>


<option value="">
    All Service Charge
</option>


<option
    value="WITH_SC"
    <?=(
        $sc === 'WITH_SC'
    )
    ? 'selected'
    : ''?>
>

WITH SC

</option>


<option
    value="WITHOUT_SC"
    <?=(
        $sc === 'WITHOUT_SC'
    )
    ? 'selected'
    : ''?>
>

WITHOUT SC

</option>


</select>

</div>


<div class="col-xl-1 col-lg-3 col-md-4">

<button
    type="submit"
    class="btn btn-filter"
>

<i class="fa-solid fa-magnifying-glass me-1"></i>

Filter

</button>

</div>


<div class="col-xl-1 col-lg-3 col-md-4">

<a
    href="sales.php?branch=<?=urlencode(
        $selectedBranch
    )?>"
    class="btn btn-reset"
>

<i class="fa-solid fa-rotate-left me-1"></i>

Reset

</a>

</div>


</div>

</form>


<!-- =========================================================
     SALES RECORDS
========================================================= -->

<div class="sales-card">


<div class="sales-card-header">


<div>


<div class="sales-card-title">

<i class="fa-solid fa-cart-shopping"></i>

Sales Records

</div>


<div
    style="
        font-size:11px;
        color:#8995a8;
        margin-top:3px;
    "
>

<?=count($rows)?> record(s) found

</div>


</div>


<div class="total-container">


<div class="total-box">

<span class="total-label">
    CASH RECEIVED
</span>


<span class="total-value">

₱<?=number_format(
    $totalCash,
    2
)?>

</span>

</div>


<div class="total-box">

<span class="total-label">
    GCASH RECEIVED
</span>


<span class="total-value">

₱<?=number_format(
    $totalGcash,
    2
)?>

</span>

</div>


<div class="total-box">

<span class="total-label">
    BANK TRANSFER
</span>


<span class="total-value">

₱<?=number_format(
    $totalBankTransfer,
    2
)?>

</span>

</div>


<div class="total-box">

<span class="total-label">
    DEBIT
</span>


<span class="total-value">

₱<?=number_format(
    $totaldebit,
    2
)?>

</span>

</div>


<div class="total-box">

<span class="total-label">
    PAID
</span>


<span class="total-value">

₱<?=number_format(
    $totalPaid,
    2
)?>

</span>

</div>


<div class="total-box">

<span class="total-label">
    PARTIAL RECEIVED
</span>


<span class="total-value">

₱<?=number_format(
    $totalPartial,
    2
)?>

</span>

</div>


<div class="total-box">

<span class="total-label">
    UNPAID BALANCE
</span>


<span class="total-ar">

₱<?=number_format(
    $totalUnpaid,
    2
)?>

</span>

</div>


<div class="total-box">

<span class="total-label">
    discount
</span>


<span class="total-discount">

₱<?=number_format(
    $totaldiscount,
    2
)?>

</span>

</div>


<div class="total-box">

<span class="total-label">
    SERVICE CHARGE
</span>


<span class="total-value">

₱<?=number_format(
    $totalServiceCharge,
    2
)?>

</span>

</div>


<div class="total-box">

<span class="total-label">
    ACCOUNT RECEIVABLE
</span>


<span class="total-ar">

₱<?=number_format(
    $totalAccountsReceivable,
    2
)?>

</span>

</div>


<div class="total-box">

<span class="total-label">
    GRAND TOTAL
</span>


<span class="total-value total-grand">

₱<?=number_format(
    $grandTotal,
    2
)?>

</span>

</div>


</div>

</div>


<!-- =========================================================
     TABLE
========================================================= -->

<div class="table-responsive">


<table class="table sales-table table-hover">


<thead>


<tr>

<th>
    Date
</th>


<th>
    Branch
</th>


<th>
    Reference
</th>


<th class="text-end">
    pax
</th>


<th>
    Customer
</th>


<th>
    Mode of Payment
</th>


<th class="text-end">
    Amount
</th>


<th class="text-end">
    discount
</th>


<th class="text-end">
    Service Charge
</th>


<th class="text-end">
    Total
</th>


<th class="text-end">
    Amount Received
</th>


<th class="text-center">
    Remarks
</th>


<th>
    Notes
</th>


<th class="text-end">
    Account Receivable
</th>


<th class="text-center">
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

$rowAmount =
    (float)(
        $r['amount']
        ?? 0
    );


$rowpax =
    (float)(
        $r['pax']
        ?? 0
    );


$rowdiscount =
    (float)(
        $r['discount']
        ?? 0
    );


$rowServiceCharge =
    (float)(
        $r['service_charge']
        ?? 0
    );


$rowTotal =
    calculateSaleTotal(
        $rowAmount,
        $rowServiceCharge,
        $rowdiscount
    );


$rowAR =
    max(
        0,
        (float)(
            $r[
                'accounts_receivable'
            ]
            ?? 0
        )
    );


$rowReceived =
    max(
        0,
        $rowTotal -
        $rowAR
    );


$rowRemarks =
    strtoupper(
        trim(
            $r['remarks']
            ?? 'UNPAID'
        )
    );


$rowPayment =
    $r['description']
    ?? '';


$rowPayments =
    getPaymentBreakdown(
        $r,
        $rowReceived
    );


$rowNotes =
    trim(
        $r['notes']
        ?? ''
    );

?>


<tr>


<!-- DATE -->

<td>

<?=htmlspecialchars(
    $r['sale_date']
)?>

</td>


<!-- BRANCH -->

<td>


<span class="badge bg-light text-dark border">

<?=htmlspecialchars(
    $r['branch_name']
)?>

</span>


</td>


<!-- REFERENCE -->

<td>

<?=htmlspecialchars(
    $r['reference_no']
)?>

</td>


<!-- pax -->

<td class="text-end">

<?=number_format(
    $rowpax,
    2
)?>

</td>


<!-- CUSTOMER -->

<td>

<?=htmlspecialchars(
    $r['customer']
)?>

</td>


<!-- PAYMENT -->

<td>


<div>

<?=htmlspecialchars(
    $rowPayment
)?>

</div>


<div class="payment-breakdown">


<?php if (
    $rowPayments['cash'] > 0
): ?>

<span>

Cash ₱<?=number_format(
    $rowPayments['cash'],
    2
)?>

</span>

<?php endif; ?>


<?php if (
    $rowPayments['gcash'] > 0
): ?>

<span>

GCash ₱<?=number_format(
    $rowPayments['gcash'],
    2
)?>

</span>

<?php endif; ?>


<?php if (
    $rowPayments[
        'bank_transfer'
    ] > 0
): ?>

<span>

Bank ₱<?=number_format(
    $rowPayments[
        'bank_transfer'
    ],
    2
)?>

</span>

<?php endif; ?>


<?php if (
    $rowPayments['debit'] > 0
): ?>

<span>

Debit ₱<?=number_format(
    $rowPayments['debit'],
    2
)?>

</span>

<?php endif; ?>


</div>


<?php if (
    $rowServiceCharge > 0
): ?>

<span class="badge badge-sc mt-1">

WITH SC

</span>


<?php else: ?>

<span class="badge badge-no-sc mt-1">

NO SC

</span>


<?php endif; ?>


</td>


<!-- AMOUNT -->

<td class="text-end sale-amount">

₱<?=number_format(
    $rowAmount,
    2
)?>

</td>


<!-- discount -->

<td class="text-end sale-discount">

₱<?=number_format(
    $rowdiscount,
    2
)?>

</td>


<!-- SERVICE CHARGE -->

<td class="text-end">

₱<?=number_format(
    $rowServiceCharge,
    2
)?>

</td>


<!-- TOTAL -->

<td class="text-end sale-total">

₱<?=number_format(
    $rowTotal,
    2
)?>

</td>


<!-- AMOUNT RECEIVED -->

<td class="text-end sale-received">

₱<?=number_format(
    $rowReceived,
    2
)?>

</td>


<!-- REMARKS -->

<td class="text-center">


<?php if (
    $rowRemarks === 'PAID'
): ?>

<span class="badge badge-paid">
    PAID
</span>


<?php elseif (
    $rowRemarks === 'PARTIAL'
): ?>

<span class="badge badge-partial">
    PARTIAL
</span>


<?php else: ?>

<span class="badge badge-unpaid">
    UNPAID
</span>


<?php endif; ?>


</td>


<!-- NOTES -->

<td class="sale-notes">


<?php if (
    $rowNotes !== ''
): ?>

<div>

<i
    class="fa-solid fa-note-sticky me-1"
    style="color:#2169e8;"
></i>


<?=nl2br(
    htmlspecialchars(
        $rowNotes
    )
)?>

</div>


<?php else: ?>

<span style="color:#adb5bd;">
    —
</span>


<?php endif; ?>


</td>


<!-- AR -->

<td class="text-end sale-ar">


<?php if (
    $rowAR > 0
): ?>

₱<?=number_format(
    $rowAR,
    2
)?>

<?php else: ?>

<span style="color:#adb5bd;">
    ₱0.00
</span>

<?php endif; ?>


</td>


<!-- ACTION -->

<td class="text-center">


<!-- =====================================================
     QUICK PAY
===================================================== -->

<?php if (
    $rowAR > 0
): ?>


<button
    type="button"
    class="quick-pay-btn"
    data-bs-toggle="modal"
    data-bs-target="#quickPayModal"
    data-sale-id="<?=htmlspecialchars(
        $r['id']
    )?>"
    data-customer="<?=htmlspecialchars(
        $r['customer']
    )?>"
    data-reference="<?=htmlspecialchars(
        $r['reference_no']
    )?>"
    data-balance="<?=htmlspecialchars(
        number_format(
            $rowAR,
            2,
            '.',
            ''
        )
    )?>"
    data-notes="<?=htmlspecialchars(
        $rowNotes,
        ENT_QUOTES,
        'UTF-8'
    )?>"
>


<i class="fa-solid fa-circle-check me-1"></i>

PAY

</button>


<?php endif; ?>


<!-- EDIT -->

<a
    href="sales.php?action=edit&id=<?=urlencode(
        $r['id']
    )?>&branch=<?=urlencode(
        $selectedBranch
    )?>"
    class="btn btn-sm btn-outline-primary action-btn"
>


<i class="fa-solid fa-pen"></i>

Edit


</a>


<!-- DELETE -->

<a
    href="sales.php?action=delete&id=<?=urlencode(
        $r['id']
    )?>&branch=<?=urlencode(
        $selectedBranch
    )?>"
    class="btn btn-sm btn-outline-danger action-btn"
    onclick="
        return confirm(
            'Are you sure you want to delete this sales record?'
        );
    "
>


<i class="fa-solid fa-trash"></i>

Delete


</a>


</td>


</tr>


<?php endforeach; ?>


<?php else: ?>


<tr>


<td
    colspan="15"
    class="empty-sales"
>


<i
    class="fa-solid fa-cart-shopping d-block mb-2"
    style="
        font-size:35px;
        color:#cbd3df;
    "
></i>


<strong>
    No sales records found.
</strong>


<div>
    Try changing your filter
    or add a new sale.
</div>


</td>


</tr>


<?php endif; ?>


</tbody>


</table>


</div>


</div>


</div>


<!-- =========================================================
     QUICK PAY MODAL
========================================================= -->

<div
    class="modal fade"
    id="quickPayModal"
    tabindex="-1"
    aria-hidden="true"
>


<div class="modal-dialog modal-dialog-centered">


<div class="modal-content border-0 shadow">


<div class="modal-header">


<h5 class="modal-title fw-bold">


<i
    class="fa-solid fa-circle-check text-success me-2"
></i>


Quick Payment


</h5>


<button
    type="button"
    class="btn-close"
    data-bs-dismiss="modal"
></button>


</div>


<form
    method="post"
    id="quickPayForm"
>


<div class="modal-body">


<input
    type="hidden"
    name="quick_pay"
    value="1"
>


<input
    type="hidden"
    name="quick_pay_id"
    id="quick_pay_id"
    value=""
>


<input
    type="hidden"
    name="quick_pay_method"
    id="quick_pay_method"
    value=""
>


<div class="quick-pay-modal-icon">


<i class="fa-solid fa-wallet"></i>


</div>


<div class="text-center mb-3">


<h6
    class="fw-bold mb-1"
    id="quick_pay_customer"
>
    Customer
</h6>


<div
    class="small text-muted"
    id="quick_pay_reference"
>
    Reference
</div>


</div>


<div class="quick-pay-balance">


<span class="quick-pay-balance-label">

Remaining Balance

</span>


<span
    class="quick-pay-balance-value"
    id="quick_pay_balance"
>

₱0.00

</span>


</div>


<!-- =====================================================
     QUICK PAY NOTES
===================================================== -->

<div class="mb-3">

<label
    for="quick_pay_notes"
    class="form-label fw-bold"
    style="font-size:12px;color:#4b5a73;"
>
    Notes
</label>

<textarea
    name="quick_pay_notes"
    id="quick_pay_notes"
    class="form-control"
    rows="4"
    maxlength="2000"
    placeholder="Enter notes for this payment..."
    style="border:1px solid #dfe5ee;border-radius:8px;font-size:13px;resize:vertical;"
></textarea>

<div class="form-text" style="font-size:10px;">
    <i class="fa-solid fa-circle-info me-1"></i>
    Existing notes are displayed here. You can update them before recording the payment.
</div>

</div>



<div class="small text-muted text-center mb-3">

<i class="fa-solid fa-circle-info me-1"></i>

Click the payment method used by the customer.
The remaining balance will automatically be paid in full.

</div>


<div class="quick-pay-methods">


<button
    type="button"
    class="quick-pay-method cash"
    data-method="cash"
>


<i class="fa-solid fa-money-bill-wave"></i>


Cash


<small>
    Pay using cash
</small>


</button>


<button
    type="button"
    class="quick-pay-method gcash"
    data-method="gcash"
>


<i class="fa-solid fa-mobile-screen-button"></i>


GCash


<small>
    Pay using GCash
</small>


</button>


<button
    type="button"
    class="quick-pay-method bank"
    data-method="bank_transfer"
>


<i class="fa-solid fa-building-columns"></i>


Bank Transfer


<small>
    Pay through bank
</small>


</button>


<button
    type="button"
    class="quick-pay-method debit"
    data-method="debit"
>


<i class="fa-solid fa-credit-card"></i>


Debit


<small>
    Pay using debit
</small>


</button>


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


</div>


</form>


</div>

</div>

</div>


<!-- =========================================================
     ADD CUSTOMER MODAL
========================================================= -->

<div
    class="modal fade"
    id="addCustomerModal"
    tabindex="-1"
    aria-hidden="true"
>


<div class="modal-dialog modal-dialog-centered">


<div class="modal-content border-0 shadow">


<div class="modal-header">


<h5 class="modal-title fw-bold">


<i class="fa-solid fa-user-plus me-2"></i>


Add Customer


</h5>


<button
    type="button"
    class="btn-close"
    data-bs-dismiss="modal"
></button>


</div>


<form
    method="post"
    id="addCustomerForm"
>


<div class="modal-body">


<input
    type="hidden"
    name="customer_branch_id"
    value="<?=htmlspecialchars(
        $selectedBranch
    )?>"
>


<div class="mb-3">


<label class="sales-label">
    Customer Name
</label>


<input
    type="text"
    name="customer_name"
    id="newCustomerName"
    class="form-control sales-input"
    placeholder="Enter customer name"
    maxlength="255"
    required
>


</div>


<div class="small text-muted">


<i class="fa-solid fa-circle-info me-1"></i>


The customer will be added to your existing customer list.


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
    name="add_customer"
    value="1"
    class="btn btn-save"
>


<i class="fa-solid fa-save me-1"></i>


Save Customer


</button>


</div>


</form>


</div>

</div>

</div>


<!-- =========================================================
     CSV UPLOAD MODAL
========================================================= -->

<div
    class="modal fade"
    id="csvUploadModal"
    tabindex="-1"
    aria-hidden="true"
>


<div class="modal-dialog modal-dialog-centered">


<div class="modal-content border-0 shadow">


<div class="modal-header">


<h5 class="modal-title fw-bold">


<i class="fa-solid fa-file-csv text-success me-2"></i>


Upload Sales CSV


</h5>


<button
    type="button"
    class="btn-close"
    data-bs-dismiss="modal"
></button>


</div>


<form
    method="post"
    enctype="multipart/form-data"
>


<div class="modal-body">


<div class="csv-modal-icon">


<i class="fa-solid fa-file-arrow-up"></i>


</div>


<h6 class="fw-bold">

Import Sales Records

</h6>


<p class="text-muted small">

Upload a CSV file to add multiple sales records at once.

</p>


<div class="mb-3">


<label class="sales-label">
    Import to Branch
</label>


<select
    name="csv_branch_id"
    class="form-select sales-input"
    required
>


<option value="">
    Select Branch
</option>


<?php foreach (
    $branches
    as $b
): ?>


<option
    value="<?=htmlspecialchars(
        $b['id']
    )?>"
    <?=(
        $selectedBranch ==
        $b['id']
    )
    ? 'selected'
    : ''?>
>


<?=htmlspecialchars(
    $b['branch_name']
)?>


</option>


<?php endforeach; ?>


</select>


</div>


<div class="mb-3">


<label class="sales-label">
    CSV File
</label>


<input
    type="file"
    name="csv_file"
    class="form-control sales-input"
    accept=".csv,text/csv"
    required
>


</div>


<div class="csv-info">


<strong>
    CSV Format
</strong>


<div class="mt-1">

Required columns:

</div>


<div class="csv-example">

sale_date,reference_no,customer,pax,discount,description,amount,service_charge,remarks,notes,amount_received

2026-08-26,INV-001,FOOD SALES,4,0,Cash,10000,0,PAID,Birthday,10000

2026-08-26,INV-002,FOOD SALES,2,0,GCash,5000,0,PAID,VIP customer,5000

2026-08-26,INV-003,FOOD SALES,3,0,Cash,8000,0,UNPAID,For collection,0

</div>


<div class="mt-2">


<strong>
    Notes:
</strong>


<br>


Ang
<b>notes</b>
ay para lamang sa particular na sales transaction.


<br>


Halimbawa:

<b>
Birthday reservation
</b>


<br>


<b>
VIP customer
</b>


<br>


<b>
Deposit only
</b>


<br>


<b>
For collection
</b>


<br><br>


Ang
<b>notes</b>
ay optional sa CSV.


<br>


Ang
<b>amount_received</b>
ay optional din.


<br>


Ang payment ay puwedeng hatiin sa:

<b>
Cash, GCash, Bank Transfer, at Debit.
</b>


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
    name="upload_csv"
    value="1"
    class="btn csv-upload-submit"
>


<i class="fa-solid fa-upload me-1"></i>


Import CSV


</button>


</div>


</form>


</div>

</div>

</div>


<!-- =========================================================
     JAVASCRIPT
========================================================= -->

<script>

document.addEventListener(
    'DOMContentLoaded',
    function(){

    /* =====================================================
       ADD CUSTOMER
    ===================================================== */

    const addCustomerModal =
        document.getElementById(
            'addCustomerModal'
        );


    const newCustomerName =
        document.getElementById(
            'newCustomerName'
        );


    if (
        addCustomerModal &&
        newCustomerName
    ) {

        addCustomerModal.addEventListener(
            'shown.bs.modal',
            function(){

                newCustomerName.focus();

            }
        );

    }


    /* =====================================================
       NORMAL SALES PAYMENT CALCULATION
    ===================================================== */

    const amount =
        document.getElementById(
            'amount'
        );


    const discount =
        document.getElementById(
            'discount'
        );


    const serviceCharge =
        document.getElementById(
            'service_charge'
        );


    const paymentCash =
        document.getElementById(
            'payment_cash'
        );


    const paymentGcash =
        document.getElementById(
            'payment_gcash'
        );


    const paymentBank =
        document.getElementById(
            'payment_bank_transfer'
        );


    const paymentDebit =
        document.getElementById(
            'payment_debit'
        );


    const amountReceived =
        document.getElementById(
            'amount_received'
        );


    const description =
        document.getElementById(
            'description'
        );


    const splitPaymentTotal =
        document.getElementById(
            'split_payment_total'
        );


    const summaryTotal =
        document.getElementById(
            'summary_total'
        );


    const summaryReceived =
        document.getElementById(
            'summary_received'
        );


    const summaryAR =
        document.getElementById(
            'summary_ar'
        );


    const statusDisplay =
        document.getElementById(
            'payment_status_display'
        );


    if (
        amount &&
        discount &&
        serviceCharge &&
        paymentCash &&
        paymentGcash &&
        paymentBank &&
        paymentDebit &&
        amountReceived
    ) {


        function money(value){

            return new Intl.NumberFormat(
                'en-PH',
                {
                    minimumFractionDigits:2,
                    maximumFractionDigits:2
                }
            ).format(value);

        }


        function valueOf(input){

            return Math.max(
                0,
                parseFloat(input.value)
                || 0
            );

        }


        function updatePayment(){

            const saleAmount =
                valueOf(amount);


            const salediscount =
                valueOf(discount);


            const saleSC =
                valueOf(serviceCharge);


            let cash =
                valueOf(paymentCash);


            let gcash =
                valueOf(paymentGcash);


            let bank =
                valueOf(paymentBank);


            let debit =
                valueOf(paymentDebit);


            const total =
                Math.max(
                    0,
                    saleAmount +
                    saleSC -
                    salediscount
                );


            let received =
                cash +
                gcash +
                bank +
                debit;


            /*
             * Prevent payment from exceeding
             * the sale total.
             */

            if (
                received > total
            ) {

                const fields = [

                    paymentCash,

                    paymentGcash,

                    paymentBank,

                    paymentDebit

                ];


                const active =
                    document.activeElement;


                if (
                    fields.includes(active)
                ) {

                    const currentValue =
                        valueOf(active);


                    const otherTotal =
                        received -
                        currentValue;


                    active.value =
                        Math.max(
                            0,
                            total -
                            otherTotal
                        ).toFixed(2);

                } else {

                    paymentDebit.value =
                        Math.max(
                            0,
                            total -
                            cash -
                            gcash -
                            bank
                        ).toFixed(2);

                }


                cash =
                    valueOf(
                        paymentCash
                    );


                gcash =
                    valueOf(
                        paymentGcash
                    );


                bank =
                    valueOf(
                        paymentBank
                    );


                debit =
                    valueOf(
                        paymentDebit
                    );


                received =
                    cash +
                    gcash +
                    bank +
                    debit;

            }


            amountReceived.value =
                received.toFixed(2);


            if (
                splitPaymentTotal
            ) {

                splitPaymentTotal.textContent =
                    '₱' +
                    money(received);

            }


            const methods = [];


            if (cash > 0) {

                methods.push(
                    'Cash'
                );

            }


            if (gcash > 0) {

                methods.push(
                    'GCash'
                );

            }


            if (bank > 0) {

                methods.push(
                    'Bank Transfer'
                );

            }


            if (debit > 0) {

                methods.push(
                    'Debit'
                );

            }


            if (description) {

                description.value =
                    methods.join(
                        ' + '
                    );

            }


            const ar =
                Math.max(
                    0,
                    total -
                    received
                );


            let status =
                'UNPAID';


            if (
                total <= 0
            ) {

                status =
                    'PAID';

            } else if (
                received <= 0
            ) {

                status =
                    'UNPAID';

            } else if (
                received >= total
            ) {

                status =
                    'PAID';

            } else {

                status =
                    'PARTIAL';

            }


            if (
                summaryTotal
            ) {

                summaryTotal.textContent =
                    '₱' +
                    money(total);

            }


            if (
                summaryReceived
            ) {

                summaryReceived.textContent =
                    '₱' +
                    money(received);

            }


            if (
                summaryAR
            ) {

                summaryAR.textContent =
                    '₱' +
                    money(ar);

            }


            if (
                statusDisplay
            ) {

                if (
                    status === 'PAID'
                ) {

                    statusDisplay.innerHTML =
                        '<span class="badge badge-paid">PAID</span>';

                } else if (
                    status === 'PARTIAL'
                ) {

                    statusDisplay.innerHTML =
                        '<span class="badge badge-partial">PARTIAL</span>';

                } else {

                    statusDisplay.innerHTML =
                        '<span class="badge badge-unpaid">UNPAID</span>';

                }

            }

        }


        [

            amount,

            discount,

            serviceCharge,

            paymentCash,

            paymentGcash,

            paymentBank,

            paymentDebit

        ].forEach(
            function(input){

                input.addEventListener(
                    'input',
                    updatePayment
                );

            }
        );


        updatePayment();

    }


    /* =====================================================
       QUICK PAY MODAL
    ===================================================== */

    const quickPayModal =
        document.getElementById(
            'quickPayModal'
        );


    const quickPayId =
        document.getElementById(
            'quick_pay_id'
        );


    const quickPayMethod =
        document.getElementById(
            'quick_pay_method'
        );


    const quickPayCustomer =
        document.getElementById(
            'quick_pay_customer'
        );


    const quickPayReference =
        document.getElementById(
            'quick_pay_reference'
        );


    const quickPayBalance =
        document.getElementById(
            'quick_pay_balance'
        );


    const quickPayNotes =
        document.getElementById(
            'quick_pay_notes'
        );


    const quickPayForm =
        document.getElementById(
            'quickPayForm'
        );


    if (
        quickPayModal
    ) {


        quickPayModal.addEventListener(
            'show.bs.modal',
            function(event){

                const button =
                    event.relatedTarget;


                if (!button) {
                    return;
                }


                const saleId =
                    button.getAttribute(
                        'data-sale-id'
                    );


                const customer =
                    button.getAttribute(
                        'data-customer'
                    );


                const reference =
                    button.getAttribute(
                        'data-reference'
                    );


                const balance =
                    parseFloat(
                        button.getAttribute(
                            'data-balance'
                        )
                    ) || 0;


                const notes =
                    button.getAttribute(
                        'data-notes'
                    ) || '';


                if (
                    quickPayId
                ) {

                    quickPayId.value =
                        saleId;

                }


                if (
                    quickPayMethod
                ) {

                    quickPayMethod.value =
                        '';

                }


                if (
                    quickPayCustomer
                ) {

                    quickPayCustomer.textContent =
                        customer ||
                        'Customer';

                }


                if (
                    quickPayReference
                ) {

                    quickPayReference.textContent =
                        reference
                        ? 'Reference: ' +
                          reference
                        : '';

                }


                if (
                    quickPayBalance
                ) {

                    quickPayBalance.textContent =
                        '₱' +
                        new Intl.NumberFormat(
                            'en-PH',
                            {
                                minimumFractionDigits:2,
                                maximumFractionDigits:2
                            }
                        ).format(
                            balance
                        );

                }


                if (
                    quickPayNotes
                ) {

                    quickPayNotes.value =
                        notes;

                }

            }
        );

    }


    /* =====================================================
       RESET QUICK PAY MODAL
    ===================================================== */

    if (quickPayModal) {

        quickPayModal.addEventListener(
            'hidden.bs.modal',
            function(){

                if (quickPayNotes) {
                    quickPayNotes.value = '';
                }

                if (quickPayMethod) {
                    quickPayMethod.value = '';
                }

            }
        );

    }


    /* =====================================================
       QUICK PAY METHOD BUTTONS
    ===================================================== */

    const quickPayButtons =
        document.querySelectorAll(
            '.quick-pay-method'
        );


    quickPayButtons.forEach(
        function(button){

            button.addEventListener(
                'click',
                function(){

                    const method =
                        this.getAttribute(
                            'data-method'
                        );


                    if (
                        !quickPayId ||
                        !quickPayForm ||
                        !quickPayMethod
                    ) {

                        return;

                    }


                    const customer =
                        quickPayCustomer
                        ? quickPayCustomer.textContent
                        : 'this customer';


                    const balance =
                        quickPayBalance
                        ? quickPayBalance.textContent
                        : 'the remaining balance';


                    let methodName =
                        method;


                    if (
                        method === 'cash'
                    ) {

                        methodName =
                            'Cash';

                    } else if (
                        method === 'gcash'
                    ) {

                        methodName =
                            'GCash';

                    } else if (
                        method === 'bank_transfer'
                    ) {

                        methodName =
                            'Bank Transfer';

                    } else if (
                        method === 'debit'
                    ) {

                        methodName =
                            'Debit';

                    }


                    const confirmed =
                        confirm(
                            'Record payment of ' +
                            balance +
                            ' for ' +
                            customer +
                            ' using ' +
                            methodName +
                            '?'
                        );


                    if (!confirmed) {

                        return;

                    }


                    quickPayMethod.value =
                        method;


                    /*
                     * Submit immediately.
                     *
                     * No need to edit the sale.
                     */

                    quickPayForm.submit();

                }
            );

        }
    );


});

</script>


<?php

include "footer.php";

?>
