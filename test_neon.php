<?php

require_once "config.php";

try {
    $stmt = $pdo->query("SELECT NOW() AS current_time");
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    echo "<h2 style='color:green;'>✅ NEON DATABASE CONNECTED!</h2>";
    echo "<p>Database Time: " . htmlspecialchars($row['current_time']) . "</p>";

    // Test tables
    $tables = [
        'branches',
        'users',
        'sales',
        'purchases',
        'expenses'
    ];

    echo "<h3>Table Check:</h3>";

    foreach ($tables as $table) {
        try {
            $stmt = $pdo->query("SELECT COUNT(*) AS total FROM \"$table\"");
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            echo "✅ {$table}: {$row['total']} records<br>";
        } catch (Throwable $e) {
            echo "❌ {$table}: " . htmlspecialchars($e->getMessage()) . "<br>";
        }
    }

} catch (Throwable $e) {

    echo "<h2 style='color:red;'>❌ NEON CONNECTION FAILED</h2>";

    echo "<pre>";
    echo htmlspecialchars($e->getMessage());
    echo "</pre>";
}