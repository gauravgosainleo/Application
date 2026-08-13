<?php
/**
 * One-time installer. Hit this URL once to create all tables.
 * Delete this file after successful install.
 */
require_once __DIR__ . '/../includes/db.php';

header('Content-Type: text/plain');

try {
    $sql = file_get_contents(__DIR__ . '/schema.sql');
    if (!$sql) { echo "Could not read schema.sql"; exit; }
    $statements = preg_split('/;\s*\n/', $sql);
    $pdo = db();
    $ok = 0; $fail = 0;
    foreach ($statements as $stmt) {
        $stmt = trim($stmt);
        if ($stmt === '' || strpos($stmt, '--') === 0) continue;
        try { $pdo->exec($stmt); $ok++; }
        catch (PDOException $e) {
            $fail++;
            echo "FAIL: " . $e->getMessage() . "\n";
            echo "  SQL: " . substr($stmt, 0, 120) . "...\n\n";
        }
    }
    echo "\nDone. Executed OK: $ok  Failed: $fail\n";
    echo "Delete this file now: install/install.php\n";
} catch (Throwable $e) {
    echo "Install failed: " . $e->getMessage();
}
