<?php
/**
 * API v1 - List All Available Tables
 * 
 * Returns a list of all queryable tables in the database
 * 
 * Usage:
 * GET /api/v1/tables.php
 */

require_once __DIR__ . '/../config.php';

// Only allow GET requests
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendError('Method not allowed. Only GET requests are supported.', 405);
}

try {
    // Get database connection
    $db = getDBConnection();
    
    if (!$db) {
        sendError('Database connection failed', 500);
    }
    
    // Get all tables from database
    $query = "SHOW TABLES";
    $stmt = $db->query($query);
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    // Categorize tables by type for better organization
    $categorizedTables = [
        'accounting' => [],
        'trading' => [],
        'clients' => [],
        'employees' => [],
        'system' => [],
        'other' => []
    ];
    
    foreach ($tables as $table) {
        // Skip if not in valid tables list
        if (!isValidTable($table)) {
            continue;
        }
        
        // Categorize based on table name patterns
        if (preg_match('/(account|ledger|balance|chart|journal|cashflow|trial)/i', $table)) {
            $categorizedTables['accounting'][] = $table;
        } elseif (preg_match('/(trade|bond|equity|stock|share|broker|custodian)/i', $table)) {
            $categorizedTables['trading'][] = $table;
        } elseif (preg_match('/(client|customer|agent)/i', $table)) {
            $categorizedTables['clients'][] = $table;
        } elseif (preg_match('/(employee|payroll|leave|department|position|hr)/i', $table)) {
            $categorizedTables['employees'][] = $table;
        } elseif (preg_match('/(approval|audit|security|system|document|setting)/i', $table)) {
            $categorizedTables['system'][] = $table;
        } else {
            $categorizedTables['other'][] = $table;
        }
    }
    
    // Remove empty categories
    $categorizedTables = array_filter($categorizedTables);
    
    $response = [
        'total_tables' => count($tables),
        'categories' => $categorizedTables,
        'all_tables' => $tables
    ];
    
    logAPIRequest('/api/v1/tables.php', 'GET', 200);
    sendResponse($response, 200, 'Available tables retrieved successfully');
    
} catch (Exception $e) {
    logAPIRequest('/api/v1/tables.php', 'GET', 500);
    sendError('Internal server error', 500, ['error' => $e->getMessage()]);
}
?>
