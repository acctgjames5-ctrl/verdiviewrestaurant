<?php
/*
|--------------------------------------------------------------------------
| AUTHENTICATION GUARD
|--------------------------------------------------------------------------
| IMPORTANT:
| - auth.php MUST be included BEFORE ANY HTML/OUTPUT.
| - Do NOT put spaces, HTML, echo, or blank output before <?php.
|
| VIEWER:
| - Allowed: index.php / dashboard.php
| - Blocked: sales.php, expenses.php, purchases.php,
|            inventory.php, users.php, etc.
|--------------------------------------------------------------------------
*/


/* =========================================================
   START SESSION SAFELY
========================================================= */

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}


/* =========================================================
   CHECK LOGIN
========================================================= */

if (
    !isset($_SESSION['logged_in']) ||
    $_SESSION['logged_in'] !== true
) {
    header('Location: login.php');
    exit;
}


/* =========================================================
   PREVENT CACHE
========================================================= */

header(
    'Cache-Control: no-store, no-cache, must-revalidate, max-age=0'
);

header(
    'Cache-Control: post-check=0, pre-check=0',
    false
);

header('Pragma: no-cache');
header('Expires: 0');


/* =========================================================
   SESSION USER ID
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
   DATABASE ROLE VERIFICATION
========================================================= */

if ($userId > 0) {

    try {

        /*
         * Load config only if PDO is not already available.
         */

        if (
            !isset($pdo) ||
            !($pdo instanceof PDO)
        ) {

            $configFile = __DIR__ . '/config.php';

            if (is_file($configFile)) {
                require_once $configFile;
            }

        }


        /* -----------------------------------------------------
           GET USER FROM DATABASE
        ----------------------------------------------------- */

        if (
            isset($pdo) &&
            $pdo instanceof PDO
        ) {

            $stmtAuthUser = $pdo->prepare(
                '
                SELECT *
                FROM "user"
                WHERE UserId = ?
                LIMIT 1
                '
            );

            $stmtAuthUser->execute([
                $userId
            ]);

            $authUser = $stmtAuthUser->fetch(
                PDO::FETCH_ASSOC
            );


            /* -------------------------------------------------
               FIND ROLE FIELD
            ------------------------------------------------- */

            if ($authUser) {

                $possibleRoleFields = [
                    'role',
                    'Role',
                    'user_role',
                    'UserRole',
                    'position',
                    'Position',
                    'job_title',
                    'JobTitle',
                    'type',
                    'Type'
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
                        &&
                        trim(
                            (string)$authUser[$field]
                        ) !== ''
                    ) {

                        $currentUserRole =
                            trim(
                                (string)$authUser[$field]
                            );

                        /*
                         * Keep session synchronized
                         * with database role.
                         */

                        $_SESSION['role'] =
                            $currentUserRole;

                        break;

                    }

                }

            }

        }

    } catch (Throwable $e) {

        /*
         * If database role lookup fails,
         * retain the session role.
         *
         * Do NOT output the error because this file
         * must never break HTTP headers.
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
        $_SERVER['SCRIPT_NAME']
        ?? $_SERVER['PHP_SELF']
        ?? ''
    )
);


/* =========================================================
   VIEWER ACCESS
=========================================================
   Viewer is allowed ONLY on dashboard pages.

   IMPORTANT:
   Do not allow sales.php, expenses.php,
   purchases.php, inventory.php, etc.
========================================================= */

if ($isViewer) {

    $viewerAllowedPages = [
        'index.php',
        'dashboard.php'
    ];


    if (
        !in_array(
            $currentProtectedFile,
            $viewerAllowedPages,
            true
        )
    ) {

        header(
            'Location: index.php'
        );

        exit;

    }

}


/* =========================================================
   GLOBAL ROLE VARIABLES
========================================================= */

$GLOBALS['currentUserRole'] =
    $currentUserRole;

$GLOBALS['normalizedRole'] =
    $normalizedRole;

$GLOBALS['isViewer'] =
    $isViewer;
