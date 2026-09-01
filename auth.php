```php
<?php
/*
|--------------------------------------------------------------------------
| AUTHENTICATION + ROLE ACCESS CONTROL
|--------------------------------------------------------------------------
| Ilagay ito sa lahat ng PROTECTED pages:
|
| require_once "auth.php";
|
| RULES:
| - Hindi naka-login  → login.php
| - VIEWER            → Dashboard lamang
| - NON-VIEWER        → normal access
|
| IMPORTANT:
| Server-side restriction ito.
| Kahit direktang i-type ng Viewer ang:
| sales.php
| expenses.php
| purchases.php
| inventory.php
| bank_reconciliation.php
| reports.php
|
| automatic siyang ibabalik sa index.php.
|--------------------------------------------------------------------------
*/


/* =========================================================
   SESSION
========================================================= */

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}


/* =========================================================
   PREVENT CACHE
========================================================= */

if (!headers_sent()) {

    header(
        "Cache-Control: no-store, no-cache, must-revalidate, max-age=0"
    );

    header(
        "Cache-Control: post-check=0, pre-check=0",
        false
    );

    header("Pragma: no-cache");
    header("Expires: 0");

}


/* =========================================================
   CHECK LOGIN
========================================================= */

if (
    empty($_SESSION['logged_in']) ||
    $_SESSION['logged_in'] !== true
) {

    if (!headers_sent()) {

        header(
            "Location: login.php"
        );

    }

    exit;

}


/* =========================================================
   USER ID
========================================================= */

$userId = (int)(
    $_SESSION['user_id']
    ?? $_SESSION['id']
    ?? 0
);


/* =========================================================
   SESSION ROLE
========================================================= */

$currentUserRole = trim(
    (string)(
        $_SESSION['role']
        ?? $_SESSION['user_role']
        ?? $_SESSION['position']
        ?? ''
    )
);


/* =========================================================
   LOAD DATABASE CONNECTION
========================================================= */

if (
    !isset($pdo) ||
    !($pdo instanceof PDO)
) {

    $configFile = __DIR__ . "/config.php";

    if (file_exists($configFile)) {

        require_once $configFile;

    }

}


/* =========================================================
   GET ACTUAL USER ROLE FROM DATABASE
=========================================================
   Database role ang priority.
   Mas safe ito kaysa puro session lang.
========================================================= */

if (
    $userId > 0 &&
    isset($pdo) &&
    $pdo instanceof PDO
) {

    try {

        $stmtAuthUser = $pdo->prepare("
            SELECT *
            FROM `user`
            WHERE UserId = ?
            LIMIT 1
        ");

        $stmtAuthUser->execute([
            $userId
        ]);

        $authUser = $stmtAuthUser->fetch(
            PDO::FETCH_ASSOC
        );


        if ($authUser) {

            /*
             * Possible role column names.
             */

            $possibleRoleFields = [

                'role',
                'Role',
                'user_role',
                'position',
                'Position',
                'job_title',
                'type'

            ];


            foreach (
                $possibleRoleFields as $field
            ) {

                if (
                    array_key_exists(
                        $field,
                        $authUser
                    )
                    &&
                    trim(
                        (string)$authUser[$field]
                    ) !== ''
                ) {

                    $currentUserRole = trim(
                        (string)$authUser[$field]
                    );

                    /*
                     * Keep session synchronized.
                     */

                    $_SESSION['role'] =
                        $currentUserRole;

                    break;

                }

            }

        }

    } catch (Throwable $e) {

        /*
         * Kung may DB error,
         * gamitin ang session role.
         *
         * Huwag i-break ang page dito.
         */

    }

}


/* =========================================================
   NORMALIZE ROLE
========================================================= */

$normalizedRole = strtolower(
    trim(
        (string)$currentUserRole
    )
);


/* =========================================================
   VIEWER DETECTION
========================================================= */

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
   CURRENT PAGE
========================================================= */

$currentProtectedFile = strtolower(
    basename(
        $_SERVER['PHP_SELF'] ?? ''
    )
);


/* =========================================================
   VIEWER ALLOWED PAGES
=========================================================
   Dashboard lamang.
========================================================= */

$viewerAllowedPages = [

    'index.php',
    'dashboard.php'

];


/* =========================================================
   VIEWER SERVER-SIDE BLOCK
========================================================= */

if ($isViewer) {

    if (
        !in_array(
            $currentProtectedFile,
            $viewerAllowedPages,
            true
        )
    ) {

        /*
         * Viewer attempted to open
         * another protected page.
         */

        if (!headers_sent()) {

            header(
                "Location: index.php"
            );

        }

        exit;

    }

}


/* =========================================================
   GLOBAL ACCESS VARIABLES
========================================================= */

$GLOBALS['currentUserRole'] =
    $currentUserRole;

$GLOBALS['normalizedRole'] =
    $normalizedRole;

$GLOBALS['isViewer'] =
    $isViewer;


/* =========================================================
   OPTIONAL HELPER FUNCTIONS
======================================================== */

/*
|--------------------------------------------------------------------------
| isViewer()
|--------------------------------------------------------------------------
*/

if (!function_exists('isViewer')) {

    function isViewer(): bool
    {
        return !empty(
            $GLOBALS['isViewer']
        );
    }

}


/*
|--------------------------------------------------------------------------
| requireNonViewer()
|--------------------------------------------------------------------------
| Gamitin kung may page/action na dapat
| ADMIN / ACCOUNTING / NON-VIEWER lamang.
|--------------------------------------------------------------------------
*/

if (!function_exists('requireNonViewer')) {

    function requireNonViewer(): void
    {

        if (
            !empty(
                $GLOBALS['isViewer']
            )
        ) {

            if (!headers_sent()) {

                header(
                    "Location: index.php"
                );

            }

            exit;

        }

    }

}


/* =========================================================
   END AUTH
========================================================= */
```
