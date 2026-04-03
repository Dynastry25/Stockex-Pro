<?php
/**
 * Check Actual Database Schema
 * Shows the real column structure of the trades table
 */

require_once 'config/config.php';

header('Content-Type: application/json');

$output = [];

try {
    $db = getDBConnection();
    
    // Get actual trades table structure
    $query = "DESCRIBE trades";
    $stmt = $db->prepare($query);
    $stmt->execute();
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $output['trades_table_structure'] = $columns;
    
    // Get sample data to see what we're working with
    $query = "SELECT * FROM trades LIMIT 1";
    $stmt = $db->prepare($query);
    $stmt->execute();
    $sample = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $output['sample_trade'] = $sample;
    $output['columns'] = $sample ? array_keys($sample) : [];
    
    $output['status'] = 'success';
    
} catch (Exception $e) {
    $output['error'] = $e->getMessage();
    $output['status'] = 'error';
}

echo json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
?>
