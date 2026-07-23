<?php
require_once __DIR__ . '/../config/env_loader.php';
require_once __DIR__ . '/../config/database.php';
$db = getDBConnection();

echo "=== USERS TABLE ===\n";
$stmt = $db->query('DESCRIBE users');
while ($r = $stmt->fetch()) {
    echo $r['Field'] . ' | ' . $r['Type'] . ' | ' . $r['Null'] . ' | ' . $r['Default'] . "\n";
}

echo "\n=== TRADES TABLE (sample columns) ===\n";
$stmt = $db->query('SHOW COLUMNS FROM trades');
while ($r = $stmt->fetch()) {
    echo $r['Field'] . ' | ' . $r['Type'] . ' | ' . $r['Null'] . ' | ' . ($r['Default'] ?? 'NULL') . "\n";
}
