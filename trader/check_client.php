<?php
require_once __DIR__ . '/../config/config.php';
$db = getDBConnection();

// Check clients table
$stmt = $db->prepare("SELECT * FROM clients WHERE client_name LIKE ?");
$stmt->execute(['%INNOCENT%']);
$clients = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "=== CLIENTS ===\n";
if ($clients) {
    foreach ($clients as $c) {
        echo json_encode($c, JSON_PRETTY_PRINT) . "\n";
    }
} else {
    echo "No client found\n";
}

// Check if client exists with full name
$stmt2 = $db->prepare("SELECT * FROM clients WHERE client_name = ?");
$stmt2->execute(['INNOCENT ALEX MAMKWE']);
$exact = $stmt2->fetchAll(PDO::FETCH_ASSOC);
echo "\n=== EXACT MATCH ===\n";
echo $exact ? json_encode($exact[0], JSON_PRETTY_PRINT) : "Not found\n";

// Check existing trades
$stmt3 = $db->prepare("SELECT * FROM trades WHERE client_name LIKE ? LIMIT 5");
$stmt3->execute(['%INNOCENT%']);
$trades = $stmt3->fetchAll(PDO::FETCH_ASSOC);
echo "\n=== TRADES ===\n";
echo $trades ? json_encode($trades, JSON_PRETTY_PRINT) : "No trades\n";

// Show order_sheet (dealing_sheets) table structure
$stmt4 = $db->query("DESCRIBE dealing_sheets");
$cols = $stmt4->fetchAll(PDO::FETCH_ASSOC);
echo "\n=== DEALING_SHEETS COLUMNS ===\n";
foreach ($cols as $col) {
    echo $col['Field'] . " - " . $col['Type'] . "\n";
}

// Show trades table structure (key columns)
$stmt5 = $db->query("DESCRIBE trades");
$cols5 = $stmt5->fetchAll(PDO::FETCH_ASSOC);
echo "\n=== TRADES REQUIRED COLUMNS ===\n";
foreach ($cols5 as $col) {
    if ($col['Null'] === 'NO' && $col['Default'] === null && $col['Field'] !== 'id' && $col['Field'] !== 'created_at' && $col['Field'] !== 'updated_at') {
        echo $col['Field'] . " - " . $col['Type'] . " (REQUIRED)\n";
    }
}
