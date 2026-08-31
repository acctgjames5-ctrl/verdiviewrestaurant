<?php

$databaseUrl = getenv('DATABASE_URL');

if (!$databaseUrl) {
    die("DATABASE_URL is not configured.");
}

$db = parse_url($databaseUrl);

if ($db === false || empty($db['host'])) {
    die("Invalid DATABASE_URL.");
}

$host = $db['host'];
$port = $db['port'] ?? 5432;
$user = $db['user'] ?? '';
$pass = $db['pass'] ?? '';
$name = isset($db['path'])
    ? ltrim($db['path'], '/')
    : 'neondb';

try {

    $dsn = "pgsql:"
         . "host={$host};"
         . "port={$port};"
         . "dbname={$name};"
         . "sslmode=require";

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
        "Database connection failed: " .
        htmlspecialchars(
            $e->getMessage(),
            ENT_QUOTES,
            'UTF-8'
        )
    );
}
