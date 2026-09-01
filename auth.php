<?php
/*
|--------------------------------------------------------------------------
| AUTHENTICATION GUARD
|--------------------------------------------------------------------------
| Protected pages must require this file.
|
| VIEWER:
| - index.php / dashboard.php ONLY
| - sales.php BLOCKED
| - expenses.php BLOCKED
| - purchases.php BLOCKED
| - inventory.php BLOCKED
| - lahat ng ibang protected pages BLOCKED
|--------------------------------------------------------------------------
*/


/* =========================================================
   START SESSION
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

    header('Location: login.php');
    exit;

}


/* =========================================================
   PREVENT CACHE
========================================================= */

if (!headers_sent()) {

    header(
        'Cache-Control: no-store, no-cache, must-revalidate, max-age=0'
    );

    header(
        'Cache-Control: post-check=0, pre-check=0',
        false
    );

    header('Pragma: no-cache');

    header('Expires: 0');

}


/* =========================================================
   GET ROLE FROM SESSION
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
   USER ID
========================================================= */

$userId = (int)(
    $_SESSION['user_id']
    ?? $_SESSION['id']
    ?? 0
);


/* =========================================================
   DATABASE ROLE CHECK
========================================================= */

if ($userId > 0) {

    try {

        /*
        |--------------------------------------------------------------------------
        | LOAD CONFIG ONLY IF PDO DOES NOT EXIST
        |--------------------------------------------------------------------------
        */

        if (
            !isset($pdo) ||
            !($pdo instanceof PDO)
        ) {

            $configFile = __DIR__ . '/config.php';

            if (file_exists($configFile)) {

                require_once $configFile;

            }

        }


        /*
        |--------------------------------------------------------------------------
        | GET ACTUAL USER ROLE
        |--------------------------------------------------------------------------
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

            $authUser = $stmtAuthUser->fetch(
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
                        &&
                        trim(
                            (string)$authUser[$field]
                        ) !== ''
                    ) {

                        $currentUserRole = trim(
                            (string)$authUser[$field]
                        );

                        /*
                        |--------------------------------------------------------------------------
                        | KEEP SESSION ROLE UPDATED
                        |--------------------------------------------------------------------------
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
        |--------------------------------------------------------------------------
        | IF DATABASE ROLE CHECK FAILS
        |--------------------------------------------------------------------------
        | Use the existing session role.
        |--------------------------------------------------------------------------
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
   VIEWER PAGE RESTRICTION
=========================================================
   Viewer can ONLY access dashboard.

   Allowed:
   - index.php
   - dashboard.php

   Blocked:
   - sales.php
   - expenses.php
   - purchases.php
   - inventory.php
   - users.php
   - reports.php
   - etc.
========================================================= */

if ($isViewer) {

    $currentFile = strtolower(
        basename(
            $_SERVER['SCRIPT_FILENAME']
            ?? $_SERVER['PHP_SELF']
            ?? ''
        )
    );


    $viewerAllowedPages = [
        'index.php',
        'dashboard.php'
    ];


    if (
        !in_array(
            $currentFile,
            $viewerAllowedPages,
            true
        )
    ) {

        /*
        |--------------------------------------------------------------------------
        | REDIRECT VIEWER TO DASHBOARD
        |--------------------------------------------------------------------------
        */

        if (!headers_sent()) {

            header(
                'Location: index.php'
            );

            exit;

        }

        /*
        |--------------------------------------------------------------------------
        | FALLBACK IF HEADERS ALREADY SENT
        |--------------------------------------------------------------------------
        */

        echo '<script>
            window.location.replace("index.php");
        </script>';

        echo '<noscript>
            <meta http-equiv="refresh" content="0;url=index.php">
        </noscript>';

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


/* =========================================================
   OPTIONAL HELPER FUNCTIONS
========================================================= */

/*
|--------------------------------------------------------------------------
| Check if current user is viewer
|--------------------------------------------------------------------------
*/

if (!function_exists('isViewerUser')) {

    function isViewerUser(): bool
    {
        return !empty(
            $GLOBALS['isViewer']
        );
    }

}


/*
|--------------------------------------------------------------------------
| Check if current user is allowed to modify data
|--------------------------------------------------------------------------
*/

if (!function_exists('canModify')) {

    function canModify(): bool
    {

        return !isViewerUser();

    }

}


/*
|--------------------------------------------------------------------------
| Check if current user has a specific role
|--------------------------------------------------------------------------
*/

if (!function_exists('hasRole')) {

    function hasRole(string $role): bool
    {

        return (
            strtolower(
                trim(
                    (string)(
                        $GLOBALS['normalizedRole']
                        ?? ''
                    )
                )
            )
            ===
            strtolower(
                trim($role)
            )
        );

    }

}
?>
