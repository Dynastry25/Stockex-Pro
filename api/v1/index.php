<?php
/**
 * API v1 - Main Endpoint Router
 * Universal endpoint for all database tables
 * 
 * Usage:
 * GET /api/v1/index.php?table={table_name}
 * GET /api/v1/index.php?table={table_name}&id={id}
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../QueryBuilder.php';

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
    
    // Check if table parameter is provided
    if (!isset($_GET['table'])) {
        sendError('Missing required parameter: table', 400, [
            'example' => '/api/v1/index.php?table=clients',
            'available_endpoints' => [
                'list_all' => '/api/v1/index.php?table={table_name}',
                'get_by_id' => '/api/v1/index.php?table={table_name}&id={id}',
                'search' => '/api/v1/index.php?table={table_name}&search={keyword}',
                'filter' => '/api/v1/index.php?table={table_name}&{column}={value}',
                'paginate' => '/api/v1/index.php?table={table_name}&page={page}&limit={limit}',
                'sort' => '/api/v1/index.php?table={table_name}&sort_by={column}&sort_order=ASC|DESC'
            ]
        ]);
    }
    
    $tableName = sanitizeInput($_GET['table']);
    
    // Validate table name
    if (!isValidTable($tableName)) {
        sendError('Invalid table name', 400, [
            'message' => 'The requested table does not exist or is not accessible via API',
            'hint' => 'Use /api/v1/tables.php to get a list of available tables'
        ]);
    }
    
    // Handle unified CDS account parameter
    if (isset($_GET['cds_account'])) {
        $cdsAccount = sanitizeInput($_GET['cds_account']);
        $cdsMapping = getCDSColumnMapping();
        
        if (isset($cdsMapping[$tableName])) {
            $targetColumn = $cdsMapping[$tableName];
            
            // If the target column is client_id, we need to resolve CDS to ID first
            if ($targetColumn === 'client_id') {
                $clientData = resolveCDSAccount($cdsAccount, $db);
                if ($clientData) {
                    $_GET[$targetColumn] = $clientData['id'];
                } else {
                    sendError('Client not found with CDS account: ' . $cdsAccount, 404);
                }
            } else {
                // Direct CDS account column, use it directly
                $_GET[$targetColumn] = $cdsAccount;
            }
        }
    }
    
    // Initialize query builder
    $queryBuilder = new QueryBuilder($db, $tableName);
    
    // Check if requesting a specific record by ID
    if (isset($_GET['id'])) {
        $id = intval($_GET['id']);
        $record = $queryBuilder->getById($id);
        
        if ($record) {
            logAPIRequest("/api/v1/{$tableName}/{$id}", 'GET', 200);
            sendResponse($record, 200, "Record retrieved successfully");
        } else {
            logAPIRequest("/api/v1/{$tableName}/{$id}", 'GET', 404);
            sendError("Record not found", 404);
        }
    } else {
        // Get all records with filters, search, pagination
        $queryParams = getQueryParams();
        $result = $queryBuilder->getAll($queryParams);
        
        logAPIRequest("/api/v1/{$tableName}", 'GET', 200);
        sendResponse($result, 200, "Records retrieved successfully");
    }
    
} catch (Exception $e) {
    logAPIRequest($_SERVER['REQUEST_URI'], 'GET', 500);
    sendError('Internal server error', 500, ['error' => $e->getMessage()]);
}
?>
