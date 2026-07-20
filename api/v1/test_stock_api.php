<?php
/**
 * Test Stock API - Diagnostic Endpoint
 * 
 * Tests DSE API connectivity and data format
 * Helps debug why stock prices aren't being stored
 */

header('Content-Type: application/json; charset=UTF-8');

error_log("=== TEST STOCK API STARTED ===");

$results = [];

// Test 1: Database Connection
error_log("TEST 1: Database Connection");
try {
    require_once __DIR__ . '/../../config/database.php';
    $database = new Database();
    $db = $database->getConnection();
    
    if ($db) {
        $results['database'] = ['status' => 'OK', 'message' => 'Connected successfully'];
        
        // Check if tables exist
        $tables_check = $db->query("
            SELECT TABLE_NAME 
            FROM INFORMATION_SCHEMA.TABLES 
            WHERE TABLE_SCHEMA = DATABASE() 
            AND TABLE_NAME IN ('stock_companies', 'stock_prices', 'stock_fetch_logs')
        ");
        $tables = $tables_check->fetchAll(PDO::FETCH_COLUMN);
        
        $results['database']['tables'] = $tables;
        $results['database']['tables_created'] = count($tables) === 3;
        
        // Check company cache
        $stmt = $db->query("SELECT COUNT(*) as count FROM stock_companies WHERE is_active = TRUE");
        $company_count = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
        $results['database']['active_companies'] = intval($company_count);
        
    } else {
        $results['database'] = ['status' => 'ERROR', 'message' => 'Connection failed'];
    }
} catch (Exception $e) {
    $results['database'] = ['status' => 'ERROR', 'message' => $e->getMessage()];
    error_log("Database test error: " . $e->getMessage());
}

// Test 2: DSE API with sample symbol (CRDB)
error_log("TEST 2: DSE API Connectivity");
try {
    $symbol = 'CRDB';
    $days = 1;
    $url = "https://dse.co.tz/api/get/market/prices/for/range/duration?security_code={$symbol}&days={$days}&class=EQUITY";
    
    error_log("Fetching from: {$url}");
    
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => "User-Agent: StockTestAPI/1.0\r\n" .
                        "Accept: application/json\r\n",
            'timeout' => 10
        ]
    ]);
    
    $response = @file_get_contents($url, false, $context);
    
    if ($response === false) {
        $results['api'] = ['status' => 'ERROR', 'message' => 'Failed to fetch from DSE API'];
        error_log("DSE API fetch failed");
    } else {
        $data = json_decode($response, true);
        
        $results['api'] = [
            'status' => 'OK',
            'message' => 'Connected to DSE API',
            'url' => $url,
            'response_size' => strlen($response),
            'json_valid' => $data !== null,
            'has_data_key' => isset($data['data']),
            'has_current_key' => isset($data['current']),
            'data_count' => isset($data['data']) ? count($data['data']) : 0,
            'current_count' => isset($data['current']) ? count($data['current']) : 0
        ];
        
        // Show first record if available
        if (isset($data['data']) && count($data['data']) > 0) {
            $first = $data['data'][0];
            $results['api']['sample_record'] = [
                'trade_date' => $first['trade_date'] ?? 'MISSING',
                'opening_price' => $first['opening_price'] ?? 'MISSING',
                'high_price' => $first['high_price'] ?? 'MISSING',
                'low_price' => $first['low_price'] ?? 'MISSING',
                'closing_price' => $first['closing_price'] ?? 'MISSING'
            ];
        }
        
        error_log("DSE API Response valid: " . ($data ? 'yes' : 'no'));
        error_log("Data records: " . ($results['api']['data_count'] ?? 0));
    }
} catch (Exception $e) {
    $results['api'] = ['status' => 'ERROR', 'message' => $e->getMessage()];
    error_log("API test error: " . $e->getMessage());
}

// Test 3: Test Insert Statement
error_log("TEST 3: Test Insert Statement");
try {
    require_once __DIR__ . '/../../config/database.php';
    $database = new Database();
    $db = $database->getConnection();
    
    // Get company ID
    $stmt = $db->prepare("SELECT id FROM stock_companies WHERE symbol = 'CRDB' LIMIT 1");
    $stmt->execute();
    $company = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($company) {
        $company_id = $company['id'];
        
        // Test insert (will update if exists)
        $test_insert = $db->prepare("
            INSERT INTO stock_prices (
                company_id, symbol, trading_date,
                open_price, high_price, low_price, close_price, current_price,
                change_value, change_percent,
                volume, trade_value, market_cap,
                source, is_processed
            ) VALUES (
                ?, ?, ?,
                ?, ?, ?, ?, ?,
                ?, ?,
                0, 0, 0,
                'TEST_API', TRUE
            ) ON DUPLICATE KEY UPDATE
                open_price = VALUES(open_price),
                updated_at = CURRENT_TIMESTAMP
        ");
        
        try {
            $test_date = date('Y-m-d');
            $test_insert->execute([
                $company_id,
                'CRDB',
                $test_date,
                450.00,
                465.00,
                448.00,
                460.00,
                460.00,
                10.00,
                2.22
            ]);
            
            $results['insert_test'] = [
                'status' => 'OK',
                'message' => 'INSERT/UPDATE statement executed successfully',
                'company_id' => $company_id,
                'test_date' => $test_date
            ];
            
            // Verify record was inserted
            $verify_stmt = $db->prepare("SELECT id, trading_date, close_price FROM stock_prices WHERE symbol = 'CRDB' AND company_id = ? ORDER BY trading_date DESC LIMIT 1");
            $verify_stmt->execute([$company_id]);
            $verify = $verify_stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($verify) {
                $results['insert_test']['verification'] = 'FOUND in database';
                $results['insert_test']['latest_record'] = $verify;
            } else {
                $results['insert_test']['verification'] = 'NOT FOUND in database after insert';
            }
            
            error_log("Insert test passed");
            
        } catch (Exception $e) {
            $results['insert_test'] = ['status' => 'ERROR', 'message' => $e->getMessage()];
            error_log("Insert test error: " . $e->getMessage());
        }
    } else {
        $results['insert_test'] = ['status' => 'ERROR', 'message' => 'CRDB company not found in database'];
    }
    
} catch (Exception $e) {
    $results['insert_test'] = ['status' => 'ERROR', 'message' => $e->getMessage()];
    error_log("Insert test setup error: " . $e->getMessage());
}

// Test 4: Check existing data
error_log("TEST 4: Check Existing Stock Prices");
try {
    require_once __DIR__ . '/../../config/database.php';
    $database = new Database();
    $db = $database->getConnection();
    
    $stmt = $db->query("
        SELECT symbol, COUNT(*) as count, MAX(trading_date) as latest_date
        FROM stock_prices
        GROUP BY symbol
        ORDER BY count DESC
        LIMIT 5
    ");
    
    $top_stocks = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $results['existing_data'] = [
        'total_records' => 0,
        'total_stmt' => $db->query("SELECT COUNT(*) FROM stock_prices")->fetch(PDO::FETCH_COLUMN),
        'top_5_symbols' => $top_stocks
    ];
    
    error_log("Existing stock price records: " . $results['existing_data']['total_stmt']);
    
} catch (Exception $e) {
    $results['existing_data'] = ['status' => 'ERROR', 'message' => $e->getMessage()];
    error_log("Data check error: " . $e->getMessage());
}

error_log("=== TEST STOCK API COMPLETED ===");

// Return results
http_response_code(200);
echo json_encode([
    'success' => true,
    'timestamp' => date('Y-m-d H:i:s'),
    'tests' => $results,
    'recommendations' => [
        'database' => ($results['database']['status'] ?? 'UNKNOWN') === 'OK' ? '✓ Database OK' : '✗ Fix database connection',
        'tables' => ($results['database']['tables_created'] ?? false) ? '✓ Tables created' : '✗ Run database schema SQL',
        'companies' => ($results['database']['active_companies'] ?? 0) > 0 ? '✓ Companies loaded' : '✗ No companies found',
        'api' => ($results['api']['status'] ?? 'UNKNOWN') === 'OK' ? '✓ DSE API working' : '✗ Cannot reach DSE API',
        'insert' => ($results['insert_test']['status'] ?? 'UNKNOWN') === 'OK' ? '✓ Inserts working' : '✗ Fix insert statement'
    ]
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
?>
