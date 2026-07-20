<?php
$pdo = new PDO('mysql:host=localhost;dbname=stockex_exchange_new_db', 'root', '');
$tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
echo "Current tables (" . count($tables) . "):\n";
echo implode("\n", $tables) . "\n";
