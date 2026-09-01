<?php

/*
|--------------------------------------------------------------------------
| AUTHENTICATION + ACCESS CONTROL
|--------------------------------------------------------------------------
| IMPORTANT:
| This file MUST be loaded before ANY HTML/output.
|
| Viewer:
|   - index.php       = ALLOWED
|   - dashboard.php   = ALLOWED
|   - all other pages = BLOCKED
|--------------------------------------------------------------------------
*/


/* =========================================================
   START SESSION
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
   USER ID
========================================================= */

$userId = (int) (
    $_SESSION['user_id']
    ?? $_SESSION['id']
    ?? 0
);


/* =========================================================
   SESSION ROLE
========================================================= */

$currentUserRole = trim(
    (string) (
        $_SESSION['role']
        ?? $_SESSION['user_role']
        ?? $_SESSION['position']
        ?? ''
    )
);


/* =========================================================
   LOAD DATABASE ROLE
========================================================= */

if ($userId > 0) {

    try {

        /*
         * Load config.php only if PDO does not already exist.
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


        /*
         * Get actual user record from database.
         */

        if (
            isset($pdo) &&
            $pdo instanceof PDO
        ) {

            $stmtAuthUser = $pdo->prepare(
                '
                SELECT *
                FROM "user"
                WHERE "UserId" = ?
                LIMIT 1
                '
            );

            $stmtAuthUser->execute([
                $userId
            ]);

            $authUser = $stmtAuthUser->fetch(
                PDO::FETCH_ASSOC
            );


            /*
             * Find role column.
             */

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
                        ) &&
                        trim(
                            (string)$authUser[$field]
                        ) !== ''
                    ) {

                        $currentUserRole =
                            trim(
                                (string)$authUser[$field]
                            );

                        break;
                    }
                }
            }
        }

    } catch (Throwable $e) {

        /*
         * If database lookup fails,
         * continue using session role.
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
   SAVE ROLE BACK TO SESSION
========================================================= */

if ($currentUserRole !== '') {

    $_SESSION['role'] =
        $currentUserRole;
}


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
   BLOCK VIEWER
========================================================= */

if (
    $isViewer &&
    !in_array(
        $currentProtectedFile,
        $viewerAllowedPages,
        true
    )
) {

    header('Location: index.php');
    exit;
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
?>
