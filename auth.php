<?php

/* =========================================================
   AUTHENTICATION + ACCESS CONTROL
   Vianchris Sales & Expenses System
========================================================= */


/* =========================================================
   START SESSION SAFELY
========================================================= */

if (session_status() !== PHP_SESSION_ACTIVE) {

    if (!headers_sent()) {
        session_start();
    }

}


/* =========================================================
   CONFIG
========================================================= */

require_once __DIR__ . '/config.php';


/* =========================================================
   CURRENT FILE
========================================================= */

$currentFile = strtolower(
    basename($_SERVER['PHP_SELF'] ?? '')
);


/* =========================================================
   LOGIN CHECK
========================================================= */

$userId = (int)(
    $_SESSION['user_id']
    ?? $_SESSION['id']
    ?? 0
);


/*
 * If there is no logged-in user,
 * send them to login page.
 */

if ($userId <= 0) {

    if (!headers_sent()) {

        header(
            'Location: login.php'
        );

        exit;

    }

    exit;

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
   GET USER DATA FROM DATABASE
========================================================= */

$loggedUser = null;

try {

    /*
     * PostgreSQL / MySQL compatible quoted table name
     *
     * The system currently uses UserId as the user ID.
     */

    $stmtUser = $pdo->prepare("
        SELECT *
        FROM `user`
        WHERE UserId = ?
        LIMIT 1
    ");

    $stmtUser->execute([
        $userId
    ]);

    $loggedUser = $stmtUser->fetch(
        PDO::FETCH_ASSOC
    );

} catch (Throwable $e) {

    /*
     * If database lookup fails,
     * continue using the session role.
     */

    $loggedUser = null;

}


/* =========================================================
   SYNCHRONIZE ROLE FROM DATABASE
========================================================= */

if ($loggedUser) {

    $possibleRoleFields = [
        'role',
        'Role',
        'user_role',
        'position',
        'Position',
        'job_title',
        'type'
    ];


    foreach ($possibleRoleFields as $field) {

        if (
            isset($loggedUser[$field]) &&
            trim(
                (string)$loggedUser[$field]
            ) !== ''
        ) {

            $currentUserRole = trim(
                (string)$loggedUser[$field]
            );

            $_SESSION['role'] =
                $currentUserRole;

            break;

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
   STORE ACCESS VARIABLES
========================================================= */

$_SESSION['is_viewer'] =
    $isViewer;


/* =========================================================
   VIEWER ALLOWED PAGES
========================================================= */

/*
 * Viewer is allowed ONLY to access Dashboard.
 */

$viewerAllowedPages = [
    'index.php',
    'dashboard.php'
];


/* =========================================================
   VIEWER BLOCK
========================================================= */

if (
    $isViewer &&
    !in_array(
        $currentFile,
        $viewerAllowedPages,
        true
    )
) {

    /*
     * Viewer attempted to access:
     *
     * sales.php
     * expenses.php
     * purchases.php
     * inventory.php
     * bank_reconciliation.php
     * reports.php
     *
     * Send them back to Dashboard.
     */

    $branchId = (int)(
        $_SESSION['branch_id'] ?? 0
    );


    $dashboardUrl = 'index.php';


    if ($branchId > 0) {

        $dashboardUrl .=
            '?branch=' . $branchId;

    }


    if (!headers_sent()) {

        header(
            'Location: ' . $dashboardUrl
        );

        exit;

    }

    exit;

}


/* =========================================================
   ROLE VARIABLES AVAILABLE TO OTHER PAGES
========================================================= */

$currentUserId = $userId;

$currentUserRole = trim(
    (string)$currentUserRole
);


/* =========================================================
   OPTIONAL HELPER FUNCTIONS
========================================================= */

/*
 * Check whether current user is Viewer.
 */

function isViewerUser(): bool
{
    return !empty(
        $_SESSION['is_viewer']
    );
}


/*
 * Require logged-in user.
 */

function requireLogin(): void
{
    $userId = (int)(
        $_SESSION['user_id']
        ?? $_SESSION['id']
        ?? 0
    );

    if ($userId <= 0) {

        if (!headers_sent()) {

            header(
                'Location: login.php'
            );

            exit;

        }

        exit;

    }
}


/*
 * Require non-viewer user.
 */

function requireNonViewer(): void
{
    if (isViewerUser()) {

        $branchId = (int)(
            $_SESSION['branch_id'] ?? 0
        );


        $url = 'index.php';


        if ($branchId > 0) {

            $url .=
                '?branch=' . $branchId;

        }


        if (!headers_sent()) {

            header(
                'Location: ' . $url
            );

            exit;

        }

        exit;

    }
}
