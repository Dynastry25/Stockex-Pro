<?php
/**
 * API v1 - Aggregations Endpoint
 * 
 * Provides server-side aggregation operations for efficient data analysis
 * 
 * Usage:
 * GET /api/v1/aggregations.php?table={table}&type={aggregation_type}
 * 
 * Supported aggregation types:
 * - count: Count records
 * - sum: Sum of numeric column
 * - avg: Average of numeric column
 * - min: Minimum value
 * - max: Maximum value
 * - group_count: Count grouped by a column
 * 
 * Optional parameters:
 * - cds_account: Filter by client CDS account (auto-resolved)
 * - column: Column to aggregate (for sum, avg, min, max)
 * - group_by: Column to group by (for group_count)
 * - {column_name}: Any additional filters
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
    
    // Check required parameters
    if (!isset($_GET['table'])) {
        sendError('Missing required parameter: table', 400, [
            'example' => '/api/v1/aggregations.php?table=trades&type=count&cds_account=696126'
        ]);
    }
    
    $tableName = sanitizeInput($_GET['table']);
    
    // Validate table name
    if (!isValidTable($tableName)) {
        sendError('Invalid table name', 400);
    }
    
    $aggregationType = isset($_GET['type']) ? sanitizeInput($_GET['type']) : 'count';
    $column = isset($_GET['column']) ? sanitizeInput($_GET['column']) : null;
    $groupBy = isset($_GET['group_by']) ? sanitizeInput($_GET['group_by']) : null;
    
    // Build WHERE clause
    $whereConditions = [];
    $params = [];
    
    // Handle unified CDS account parameter
    if (isset($_GET['cds_account'])) {
        $cdsAccount = sanitizeInput($_GET['cds_account']);
        $cdsMapping = getCDSColumnMapping();
        
        if (isset($cdsMapping[$tableName])) {
            $targetColumn = $cdsMapping[$tableName];
            
            if ($targetColumn === 'client_id') {
                $clientData = resolveCDSAccount($cdsAccount, $db);
                if ($clientData) {
                    $whereConditions[] = "`{$targetColumn}` = :client_id";
                    $params['client_id'] = $clientData['id'];
                } else {
                    sendError('Client not found with CDS account: ' . $cdsAccount, 404);
                }
            } else {
                $whereConditions[] = "`{$targetColumn}` = :cds_account";
                $params['cds_account'] = $cdsAccount;
            }
        }
    }
    
    // Handle additional filters
    foreach ($_GET as $key => $value) {
        if (!in_array($key, ['table', 'type', 'column', 'group_by', 'cds_account', 'page', 'limit', 'sort_by', 'sort_order'])) {
            $whereConditions[] = "`{$key}` = :{$key}";
            $params[$key] = sanitizeInput($value);
        }
    }
    
    $whereClause = !empty($whereConditions) ? ' WHERE ' . implode(' AND ', $whereConditions) : '';
    
    // Build aggregation query based on type
    $sql = '';
    $result = null;
    
    switch ($aggregationType) {
        case 'count':
            $sql = "SELECT COUNT(*) as count FROM `{$tableName}`{$whereClause}";
            break;
            
        case 'sum':
            if (!$column) {
                sendError('Column parameter required for sum aggregation', 400);
            }
            $sql = "SELECT SUM(`{$column}`) as sum, COUNT(*) as count FROM `{$tableName}`{$whereClause}";
            break;
            
        case 'avg':
            if (!$column) {
                sendError('Column parameter required for avg aggregation', 400);
            }
            $sql = "SELECT AVG(`{$column}`) as average, COUNT(*) as count FROM `{$tableName}`{$whereClause}";
            break;
            
        case 'min':
            if (!$column) {
                sendError('Column parameter required for min aggregation', 400);
            }
            $sql = "SELECT MIN(`{$column}`) as minimum FROM `{$tableName}`{$whereClause}";
            break;
            
        case 'max':
            if (!$column) {
                sendError('Column parameter required for max aggregation', 400);
            }
            $sql = "SELECT MAX(`{$column}`) as maximum FROM `{$tableName}`{$whereClause}";
            break;
            
        case 'group_count':
            if (!$groupBy) {
                sendError('group_by parameter required for group_count aggregation', 400);
            }
            $sql = "SELECT `{$groupBy}`, COUNT(*) as count FROM `{$tableName}`{$whereClause} GROUP BY `{$groupBy}` ORDER BY count DESC LIMIT 50";
            break;
            
        case 'stats':
            // Comprehensive stats
            if (!$column) {
                $column = 'id'; // fallback
            }
            $sql = "SELECT 
                        COUNT(*) as count,
                        MIN(`{$column}`) as minimum,
                        MAX(`{$column}`) as maximum,
                        AVG(`{$column}`) as average,
                        SUM(`{$column}`) as sum
                    FROM `{$tableName}`{$whereClause}";
            break;
            
        default:
            sendError('Invalid aggregation type', 400, [
                'valid_types' => ['count', 'sum', 'avg', 'min', 'max', 'group_count', 'stats']
            ]);
    }
    
    $stmt = $db->prepare($sql);
    
    // Bind parameters
    foreach ($params as $key => $value) {
        $stmt->bindValue(":{$key}", $value);
    }
    
    $stmt->execute();
    
    if ($aggregationType === 'group_count') {
        $result = $stmt->fetchAll();
    } else {
        $result = $stmt->fetch();
    }
    
    $response = [
        'table' => $tableName,
        'aggregation_type' => $aggregationType,
        'filters_applied' => $params,
        'result' => $result
    ];
    
    logAPIRequest('/api/v1/aggregations.php', 'GET', 200);
    sendResponse($response, 200, 'Aggregation completed successfully');
    
} catch (Exception $e) {
    logAPIRequest('/api/v1/aggregations.php', 'GET', 500);
    sendError('Internal server error', 500, ['error' => $e->getMessage()]);
}
?>
