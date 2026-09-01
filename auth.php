<?php

/*
|--------------------------------------------------------------------------
| AUTHENTICATION GUARD
|--------------------------------------------------------------------------
| IMPORTANT:
| - Walang HTML/output bago ang PHP
| - Huwag maglagay ng closing ?> sa file
| - Session ay sisimulan lamang kung hindi pa active
| - Viewer = Dashboard lamang
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
    empty($_SESSION['logged_in']) ||
    $_SESSION['logged_in'] !== true
) {
    header('Location: login.php');
    exit;
}


/* =========================================================
   NO CACHE
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
   GET USER ID
========================================================= */

$userId = (int) (
    $_SESSION['user_id']
    ?? $_SESSION['id']
    ?? 0
);


/* =========================================================
   GET ROLE FROM SESSION
========================================================= */

$currentUserRole = trim((string) (
    $_SESSION['role']
    ?? $_SESSION['user_role']
    ?? $_SESSION['position']
    ?? ''
));


/* =========================================================
   DATABASE ROLE CHECK
========================================================= */

if ($userId > 0) {

    /*
     * config.php should normally already be loaded by the page.
     *
     * We only load it here if PDO does not yet exist.
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


    if (
        isset($pdo) &&
        $pdo instanceof PDO
    ) {

        try {

            $stmtAuthUser = $pdo->prepare(
                'SELECT *
                 FROM "user"
                 WHERE "UserId" = ?
                 LIMIT 1'
            );

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
                    ) {

                        $roleValue = trim(
                            (string)$authUser[$field]
                        );


                        if ($roleValue !== '') {

                            $currentUserRole =
                                $roleValue;


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

        } catch (Throwable $e) {

            /*
             * If database role lookup fails,
             * keep using the session role.
             */

        }
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
   VIEWER
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
   BLOCK VIEWER FROM OTHER PAGES
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
