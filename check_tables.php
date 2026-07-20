<?php
$pdo = new PDO('mysql:host=localhost;dbname=stockex_exchange_new_db', 'root', '');
$tables = $pdo->query("SHOW TABLES LIKE '%regulatory_fee%'")->fetchAll(PDO::FETCH_COLUMN);
echo "regulatory_fee tables: " . implode(', ', $tables) . "\n";
$tables = $pdo->query("SHOW TABLES LIKE '%csd_reference%'")->fetchAll(PDO::FETCH_COLUMN);
echo "csd_reference tables: " . implode(', ', $tables) . "\n";
$tables = $pdo->query("SHOW TABLES LIKE '%general_ledger%'")->fetchAll(PDO::FETCH_COLUMN);
echo "general_ledger tables: " . implode(', ', $tables) . "\n";
$tables = $pdo->query("SHOW TABLES LIKE '%trial_balance%'")->fetchAll(PDO::FETCH_COLUMN);
echo "trial_balance tables: " . implode(', ', $tables) . "\n";
