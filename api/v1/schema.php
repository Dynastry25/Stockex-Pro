<?php
/**
 * API v1 - Get Table Schema/Structure
 * 
 * Returns the structure/schema of a specific table including columns, types, and constraints
 * 
 * Usage:
 * GET /api/v1/schema.php?table={table_name}
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
            'example' => '/api/v1/schema.php?table=clients'
        ]);
    }
    
    $tableName = sanitizeInput($_GET['table']);
    
    // Validate table name
    if (!isValidTable($tableName)) {
        sendError('Invalid table name', 400);
    }
    
    // Get table columns
    $queryBuilder = new QueryBuilder($db, $tableName);
    $columns = $queryBuilder->getColumns();
    
    // Format column information
    $formattedColumns = [];
    foreach ($columns as $column) {
        $formattedColumns[] = [
            'name' => $column['Field'],
            'type' => $column['Type'],
            'null' => $column['Null'] === 'YES',
            'key' => $column['Key'],
            'default' => $column['Default'],
            'extra' => $column['Extra']
        ];
    }
    
    // Get sample record count
    $countQuery = "SELECT COUNT(*) as total FROM `{$tableName}`";
    $stmt = $db->query($countQuery);
    $totalRecords = $stmt->fetch()['total'];
    
    // Get CDS mapping info
    $cdsMapping = getCDSColumnMapping();
    $isCDSFilterable = isset($cdsMapping[$tableName]);
    $cdsColumn = $cdsMapping[$tableName] ?? null;
    
    // Identify searchable columns (text/varchar types)
    $searchableColumns = [];
    $numericColumns = [];
    $dateColumns = [];
    
    foreach ($formattedColumns as $column) {
        $type = strtolower($column['type']);
        
        if (preg_match('/varchar|text|char/i', $type)) {
            $searchableColumns[] = $column['name'];
        }
        
        if (preg_match('/int|decimal|float|double|numeric/i', $type)) {
            $numericColumns[] = $column['name'];
        }
        
        if (preg_match('/date|time|timestamp/i', $type)) {
            $dateColumns[] = $column['name'];
        }
    }
    
    // Identify common filter columns that exist in this table
    $commonFilters = ['status', 'trade_side', 'asset_class', 'trade_date', 'type', 'client_type'];
    $availableFilters = array_intersect($commonFilters, array_column($formattedColumns, 'name'));
    
    $response = [
        'table_name' => $tableName,
        'total_records' => (int)$totalRecords,
        'columns' => $formattedColumns,
        'column_count' => count($formattedColumns),
        'filter_hints' => [
            'cds_filterable' => $isCDSFilterable,
            'cds_parameter' => $isCDSFilterable ? 'cds_account' : null,
            'cds_column' => $cdsColumn,
            'searchable_columns' => $searchableColumns,
            'numeric_columns' => $numericColumns,
            'date_columns' => $dateColumns,
            'available_filters' => array_values($availableFilters),
            'supports_aggregations' => !empty($numericColumns)
        ],
        'api_hints' => [
            'get_all' => "/api/v1/index.php?table={$tableName}",
            'get_by_id' => "/api/v1/index.php?table={$tableName}&id={id}",
            'search' => "/api/v1/index.php?table={$tableName}&search={keyword}",
            'paginate' => "/api/v1/index.php?table={$tableName}&page=1&limit=50",
            'sort' => "/api/v1/index.php?table={$tableName}&sort_by={column}&sort_order=DESC"
        ]
    ];
    
    if ($isCDSFilterable) {
        $response['api_hints']['filter_by_cds'] = "/api/v1/index.php?table={$tableName}&cds_account={cds_number}";
        $response['api_hints']['count_by_cds'] = "/api/v1/aggregations.php?table={$tableName}&type=count&cds_account={cds_number}";
    }
    
    if (!empty($numericColumns)) {
        $exampleNumericCol = $numericColumns[0];
        $response['api_hints']['aggregate'] = "/api/v1/aggregations.php?table={$tableName}&type=sum&column={$exampleNumericCol}";
    }
    
    logAPIRequest("/api/v1/schema/{$tableName}", 'GET', 200);
    sendResponse($response, 200, 'Table schema retrieved successfully');
    
} catch (Exception $e) {
    logAPIRequest($_SERVER['REQUEST_URI'], 'GET', 500);
    sendError('Internal server error', 500, ['error' => $e->getMessage()]);
}
?>
