<?php

/* =========================================================
   NEON POSTGRESQL DATABASE CONFIGURATION
   Render Environment Variables
========================================================= */

$host = getenv('DB_HOST');
$port = getenv('DB_PORT') ?: '5432';
$db   = getenv('DB_NAME') ?: 'neondb';
$user = getenv('DB_USER');
$pass = getenv('DB_PASSWORD');


/* =========================================================
   VALIDATE DATABASE SETTINGS
========================================================= */

if (!$host || !$user || !$pass) {
    die("Database configuration is missing. Please check Render Environment Variables.");
}


/* =========================================================
   DATABASE CONNECTION
========================================================= */

try {

    $dsn = "pgsql:"
         . "host={$host};"
         . "port={$port};"
         . "dbname={$db};"
         . "sslmode=require;"
         . "options='endpoint=ep-frosty-surf-azo6it4t'";

    $pdo = new PDO(
        $dsn,
        $user,
        $pass,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false
        ]
    );

} catch (PDOException $e) {

    die(
        "Database connection failed: "
        . htmlspecialchars(
            $e->getMessage(),
            ENT_QUOTES,
            'UTF-8'
        )
    );

}