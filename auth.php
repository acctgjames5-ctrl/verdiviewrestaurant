<?php
/*
|--------------------------------------------------------------------------
| AUTHENTICATION + ACCESS CONTROL
|--------------------------------------------------------------------------
| IMPORTANT:
| auth.php must be loaded BEFORE ANY HTML OUTPUT.
|
| VIEWER:
| - index.php / dashboard.php ONLY
| - Sales       = BLOCKED
| - Expenses    = BLOCKED
| - Purchases   = BLOCKED
| - Inventory   = BLOCKED
| - Bank Recon  = BLOCKED
| - Reports     = BLOCKED
|--------------------------------------------------------------------------
*/


/* =========================================================
   OUTPUT BUFFER
========================================================= */

if (!ob_get_level()) {
    ob_start();
}


/* =========================================================
   SESSION
========================================================= */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}


/* =========================================================
   CHECK LOGIN
========================================================= */

if (
    empty($_SESSION['logged_in']) ||
    $_SESSION['logged_in'] !== true
) {

    header("Location: login.php");
    exit;
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
   USER ID
========================================================= */

$userId = (int)(
    $_SESSION['user_id']
    ?? $_SESSION['id']
    ?? 0
);


/* =========================================================
   GET ROLE FROM SESSION FIRST
========================================================= */

$currentUserRole = trim((string)(
    $_SESSION['role']
    ?? $_SESSION['user_role']
    ?? $_SESSION['position']
    ?? ''
));


/* =========================================================
   DATABASE ROLE CHECK
========================================================= */

if ($userId > 0) {

    try {

        /*
         * Load config.php only if PDO is not already available.
         */

        if (
            !isset($pdo) ||
            !($pdo instanceof PDO)
        ) {

            $configFile = __DIR__ . "/config.php";

            if (file_exists($configFile)) {

                require_once $configFile;

            }

        }


        /*
         * If PDO is available, get the real role
         * directly from the database.
         */

        if (
            isset($pdo) &&
            $pdo instanceof PDO
        ) {

            $stmtAuthUser = $pdo->prepare("
                SELECT *
                FROM `user`
                WHERE UserId = ?
                LIMIT 1
            ");

            $stmtAuthUser->execute([
                $userId
            ]);

            $authUser =
                $stmtAuthUser->fetch(
                    PDO::FETCH_ASSOC
                );


            if ($authUser) {

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
                    $possibleRoleFields
                    as $field
                ) {

                    if (
                        array_key_exists(
                            $field,
                            $authUser
                        )
                    ) {

                        $dbRole = trim(
                            (string)$authUser[$field]
                        );


                        if ($dbRole !== '') {

                            $currentUserRole =
                                $dbRole;

                            /*
                             * Keep session synchronized.
                             */

                            $_SESSION['role'] =
                                $currentUserRole;

                            break;

                        }

                    }

                }

            }

        }

    } catch (Throwable $e) {

        /*
         * If database role lookup fails,
         * continue using the session role.
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
========================================================= */

$viewerAllowedPages = [
    'index.php',
    'dashboard.php'
];


/* =========================================================
   VIEWER SERVER-SIDE ACCESS CONTROL
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
         * Do not allow Viewer to access
         * Sales / Expenses / Purchases /
         * Inventory / Banking / Reports
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
   GLOBAL VARIABLES
========================================================= */

$GLOBALS['currentUserRole'] =
    $currentUserRole;

$GLOBALS['normalizedRole'] =
    $normalizedRole;

$GLOBALS['isViewer'] =
    $isViewer;


/* =========================================================
   OPTIONAL SIMPLE CONSTANTS
========================================================= */

if (!defined('CURRENT_USER_ID')) {
    define(
        'CURRENT_USER_ID',
        $userId
    );
}

if (!defined('CURRENT_USER_ROLE')) {
    define(
        'CURRENT_USER_ROLE',
        $currentUserRole
    );
}

if (!defined('IS_VIEWER')) {
    define(
        'IS_VIEWER',
        $isViewer
    );
}
