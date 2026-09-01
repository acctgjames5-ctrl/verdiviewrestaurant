```php
<?php

/*
|--------------------------------------------------------------------------
| AUTHENTICATION GUARD
|--------------------------------------------------------------------------
| IMPORTANT:
| This file must be included BEFORE ANY HTML OUTPUT.
|
| VIEWER:
| - index.php / dashboard.php = ALLOWED
| - all other protected pages = BLOCKED
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
   ROLE FROM SESSION
========================================================= */

$currentUserRole = trim((string) (
    $_SESSION['role']
    ?? $_SESSION['user_role']
    ?? $_SESSION['position']
    ?? ''
));


/* =========================================================
   LOAD DATABASE CONFIG IF NEEDED
========================================================= */

if (
    !isset($pdo) ||
    !($pdo instanceof PDO)
) {

    $configFile = __DIR__ . '/config.php';

    if (file_exists($configFile)) {

        require_once $configFile;

    }

}


/* =========================================================
   GET ACTUAL USER ROLE FROM DATABASE
========================================================= */

if (
    $userId > 0 &&
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
                    isset($authUser[$field]) &&
                    trim(
                        (string)$authUser[$field]
                    ) !== ''
                ) {

                    $currentUserRole = trim(
                        (string)$authUser[$field]
                    );

                    break;

                }

            }

        }

    } catch (Throwable $e) {

        /*
         * If database role lookup fails,
         * use the session role.
         */

    }

}


/* =========================================================
   SAVE ROLE TO SESSION
========================================================= */

if (
    trim(
        (string)$currentUserRole
    ) !== ''
) {

    $_SESSION['role'] = $currentUserRole;

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
   VIEWER ALLOWED PAGES
========================================================= */

$viewerAllowedPages = [
    'index.php',
    'dashboard.php'
];


/* =========================================================
   CURRENT PAGE
========================================================= */

$currentProtectedFile = strtolower(
    basename(
        $_SERVER['PHP_SELF'] ?? ''
    )
);


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

$GLOBALS['currentUserRole'] = $currentUserRole;

$GLOBALS['normalizedRole'] = $normalizedRole;

$GLOBALS['isViewer'] = $isViewer;
```
