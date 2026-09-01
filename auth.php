```php
<?php
/*
|--------------------------------------------------------------------------
| AUTH.PHP
|--------------------------------------------------------------------------
| CENTRAL AUTHENTICATION + ACCESS CONTROL
|--------------------------------------------------------------------------
| IMPORTANT:
| This file MUST be loaded before ANY HTML/output.
|
| Viewer:
|   - index.php       = ALLOWED
|   - dashboard.php   = ALLOWED
|   - all other pages = BLOCKED
|
|--------------------------------------------------------------------------
*/


/* =========================================================
   SESSION
========================================================= */

if (session_status() === PHP_SESSION_NONE) {

    /*
     * Only start the session if headers have not
     * already been sent.
     */

    if (!headers_sent()) {

        session_start();

    } else {

        /*
         * If output already happened, do NOT call
         * session_start() because PHP will generate:
         *
         * "Session cannot be started after headers
         *  have already been sent"
         */

        return;

    }

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

        exit;

    }

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

    header(
        "Pragma: no-cache"
    );

    header(
        "Expires: 0"
    );

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
   LOAD DATABASE CONFIG
========================================================= */

if (
    !isset($pdo) ||
    !($pdo instanceof PDO)
) {

    $configFile =
        __DIR__ . "/config.php";


    if (
        file_exists($configFile)
    ) {

        require_once $configFile;

    }

}


/* =========================================================
   GET REAL USER ROLE FROM DATABASE
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

        $authUser =
            $stmtAuthUser->fetch(
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
         * If database role lookup fails,
         * use the session role.
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
   CURRENT PAGE
========================================================= */

$currentProtectedFile =
    strtolower(
        basename(
            $_SERVER['PHP_SELF']
            ?? ''
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
   OPTIONAL CONSTANTS
========================================================= */

if (
    !defined('USER_IS_VIEWER')
) {

    define(
        'USER_IS_VIEWER',
        $isViewer
    );

}


/* =========================================================
   END AUTH.PHP
========================================================= */
```
