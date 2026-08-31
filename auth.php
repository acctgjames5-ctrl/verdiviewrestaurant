<?php
/*
|--------------------------------------------------------------------------
| AUTHENTICATION GUARD
|--------------------------------------------------------------------------
| Lahat ng protected pages dapat dumaan dito.
|
| VIEWER:
| - Dashboard / index.php lang ang puwedeng buksan
| - Lahat ng ibang protected pages ay blocked
|--------------------------------------------------------------------------
*/

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

header(
    "Cache-Control: no-store, no-cache, must-revalidate, max-age=0"
);

header(
    "Cache-Control: post-check=0, pre-check=0",
    false
);

header("Pragma: no-cache");

header("Expires: 0");


/* =========================================================
   GET USER ROLE
========================================================= */

$currentUserRole = trim((string)(
    $_SESSION['role']
    ?? $_SESSION['user_role']
    ?? $_SESSION['position']
    ?? ''
));


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
=========================================================
   Mas reliable ito kaysa session lamang.

   Kung available ang config.php, kukunin natin ang
   actual role mula sa database.
========================================================= */

if ($userId > 0) {

    try {

        /*
         * Kung hindi pa loaded ang PDO,
         * subukan i-load ang config.php.
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
                        isset($authUser[$field]) &&
                        trim(
                            (string)$authUser[$field]
                        ) !== ''
                    ) {

                        $currentUserRole =
                            trim(
                                (string)$authUser[$field]
                            );

                        /*
                         * Update session para consistent
                         * ang role sa buong system.
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
         * Kapag may database issue,
         * gamitin ang session role.
         */

    }

}


/* =========================================================
   NORMALIZE ROLE
========================================================= */

$normalizedRole =
    strtolower(
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
   SERVER-SIDE VIEWER RESTRICTION
=========================================================
   Viewer:
   - index.php = ALLOWED
   - dashboard.php = ALLOWED
   - ibang protected pages = BLOCKED
========================================================= */

if ($isViewer) {

    $currentProtectedFile =
        strtolower(
            basename(
                $_SERVER['PHP_SELF'] ?? ''
            )
        );


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
            "Location: index.php"
        );

        exit;

    }

}


/* =========================================================
   OPTIONAL GLOBAL VARIABLES
========================================================= */

$GLOBALS['currentUserRole'] =
    $currentUserRole;

$GLOBALS['normalizedRole'] =
    $normalizedRole;

$GLOBALS['isViewer'] =
    $isViewer;