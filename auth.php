<?php
/*
|--------------------------------------------------------------------------
| AUTHENTICATION GUARD
|--------------------------------------------------------------------------
| VIEWER:
| - index.php / dashboard.php lamang
| - lahat ng ibang protected pages = BLOCKED
|--------------------------------------------------------------------------
*/

/*
 * IMPORTANT:
 * Huwag maglagay ng kahit anong output bago itong file.
 */

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
   ROLE FROM SESSION
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
   LOAD DATABASE CONFIG IF NEEDED
========================================================= */

if (
    !isset($pdo) ||
    !($pdo instanceof PDO)
) {

    $configFile = __DIR__ . '/config.php';

    if (is_file($configFile)) {

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
                $possibleRoleFields as $field
            ) {

                if (
                    array_key_exists(
                        $field,
                        $authUser
                    ) &&
                    trim(
                        (string) $authUser[$field]
                    ) !== ''
                ) {

                    $currentUserRole = trim(
                        (string) $authUser[$field]
                    );

                    $_SESSION['role'] =
                        $currentUserRole;

                    break;

                }

            }

        }

    } catch (Throwable $e) {

        /*
         * Kapag may database problem,
         * gamitin ang role na nasa session.
         */

    }

}


/* =========================================================
   NORMALIZE ROLE
========================================================= */

$normalizedRole = strtolower(
    trim(
        (string) $currentUserRole
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


/* =========================================================
   HELPER FUNCTIONS
========================================================= */

if (!function_exists('userIsViewer')) {

    function userIsViewer(): bool
    {
        return !empty(
            $GLOBALS['isViewer']
        );
    }

}


if (!function_exists('userCanEdit')) {

    function userCanEdit(): bool
    {
        return !userIsViewer();
    }

}
