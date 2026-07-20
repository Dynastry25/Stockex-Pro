<?php
/**
 * API v1 - Batch Query Endpoint
 * 
 * Execute multiple queries in a single API call to reduce round trips
 * 
 * Usage:
 * POST /api/v1/batch.php
 * Content-Type: application/json
 * 
 * Body:
 * {
 *   "queries": [
 *     {
 *       "name": "trades",
 *       "table": "trades",
 *       "params": {
 *         "cds_account": "696126",
 *         "limit": 10
 *       }
 *     },
 *     {
 *       "name": "payments",
 *       "table": "payments",
 *       "params": {
 *         "cds_account": "696126"
 *       }
 *     }
 *   ]
 * }
 * 
 * Or via GET with URL-encoded JSON:
 * GET /api/v1/batch.php?queries=[{...}]
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../QueryBuilder.php';

// Allow both GET and POST
if (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'POST'])) {
    sendError('Method not allowed. Only GET and POST requests are supported.', 405);
}

try {
    // Get database connection
    $database = new Database();
    $db = $database->getConnection();
    
    if (!$db) {
        sendError('Database connection failed', 500);
    }
    
    // Parse queries from request
    $queries = null;
    
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = file_get_contents('php://input');
        $data = json_decode($input, true);
        $queries = $data['queries'] ?? null;
    } else {
        // GET request
        if (isset($_GET['queries'])) {
            $queries = json_decode($_GET['queries'], true);
        }
    }
    
    if (!$queries || !is_array($queries)) {
        sendError('Missing or invalid queries parameter', 400, [
            'format' => 'Array of query objects',
            'example' => [
                'queries' => [
                    [
                        'name' => 'trades',
                        'table' => 'trades',
                        'params' => ['cds_account' => '696126', 'limit' => 10]
                    ],
                    [
                        'name' => 'count',
                        'table' => 'trades',
                        'aggregate' => 'count',
                        'params' => ['cds_account' => '696126']
                    ]
                ]
            ]
        ]);
    }
    
    // Limit number of queries to prevent abuse
    $maxQueries = 10;
    if (count($queries) > $maxQueries) {
        sendError("Too many queries. Maximum {$maxQueries} queries allowed per batch.", 400);
    }
    
    $results = [];
    $errors = [];
    
    foreach ($queries as $index => $query) {
        $queryName = $query['name'] ?? "query_{$index}";
        $tableName = $query['table'] ?? null;
        $params = $query['params'] ?? [];
        $aggregate = $query['aggregate'] ?? null;
        
        if (!$tableName) {
            $errors[$queryName] = 'Missing table parameter';
            continue;
        }
        
        if (!isValidTable($tableName)) {
            $errors[$queryName] = 'Invalid table name';
            continue;
        }
        
        try {
            // Temporarily set $_GET params for this query
            $originalGet = $_GET;
            $_GET = array_merge(['table' => $tableName], $params);
            
            // Handle CDS account resolution
            if (isset($params['cds_account'])) {
                $cdsAccount = sanitizeInput($params['cds_account']);
                $cdsMapping = getCDSColumnMapping();
                
                if (isset($cdsMapping[$tableName])) {
                    $targetColumn = $cdsMapping[$tableName];
                    
                    if ($targetColumn === 'client_id') {
                        $clientData = resolveCDSAccount($cdsAccount, $db);
                        if ($clientData) {
                            $_GET[$targetColumn] = $clientData['id'];
                            $params[$targetColumn] = $clientData['id'];
                        }
                    } else {
                        $_GET[$targetColumn] = $cdsAccount;
                        $params[$targetColumn] = $cdsAccount;
                    }
                }
            }
            
            if ($aggregate) {
                // Execute aggregation query
                $whereConditions = [];
                $bindParams = [];
                
                foreach ($params as $key => $value) {
                    if ($key !== 'cds_account') {
                        $whereConditions[] = "`{$key}` = :{$key}";
                        $bindParams[$key] = $value;
                    }
                }
                
                $whereClause = !empty($whereConditions) ? ' WHERE ' . implode(' AND ', $whereConditions) : '';
                
                $sql = "SELECT COUNT(*) as count FROM `{$tableName}`{$whereClause}";
                $stmt = $db->prepare($sql);
                
                foreach ($bindParams as $key => $value) {
                    $stmt->bindValue(":{$key}", $value);
                }
                
                $stmt->execute();
                $result = $stmt->fetch();
                
                $results[$queryName] = [
                    'success' => true,
                    'data' => $result
                ];
            } else {
                // Execute regular query
                $queryBuilder = new QueryBuilder($db, $tableName);
                $queryParams = getQueryParams();
                
                // Check if requesting specific record by ID
                if (isset($params['id'])) {
                    $record = $queryBuilder->getById($params['id']);
                    $results[$queryName] = [
                        'success' => true,
                        'data' => $record
                    ];
                } else {
                    $result = $queryBuilder->getAll($queryParams);
                    $results[$queryName] = [
                        'success' => true,
                        'data' => $result
                    ];
                }
            }
            
            // Restore original $_GET
            $_GET = $originalGet;
            
        } catch (Exception $e) {
            $errors[$queryName] = $e->getMessage();
            $_GET = $originalGet;
        }
    }
    
    $response = [
        'total_queries' => count($queries),
        'successful' => count($results),
        'failed' => count($errors),
        'results' => $results
    ];
    
    if (!empty($errors)) {
        $response['errors'] = $errors;
    }
    
    $statusCode = empty($errors) ? 200 : 207; // 207 Multi-Status if some failed
    
    logAPIRequest('/api/v1/batch.php', $_SERVER['REQUEST_METHOD'], $statusCode);
    sendResponse($response, $statusCode, 'Batch query completed');
    
} catch (Exception $e) {
    logAPIRequest('/api/v1/batch.php', $_SERVER['REQUEST_METHOD'], 500);
    sendError('Internal server error', 500, ['error' => $e->getMessage()]);
}
?>
