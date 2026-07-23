<?php
/**
 * Diagnostic Script - Find Why Stock Prices Aren't Being Inserted
 */

header('Content-Type: application/json; charset=UTF-8');

$diagnostics = [];
error_log("=== DIAGNOSTIC: Insert Failure Debug ===");

try {
    require_once __DIR__ . '/../../config/database.php';
    $db = getDBConnection();
    
    if (!$db) {
        throw new Exception('Database connection failed');
    }
    
    $diagnostics['db_connected'] = true;
    error_log("✓ Database connected");
    
    // TEST 1: Check if stock_companies table exists
    error_log("\n--- TEST 1: Check stock_companies table ---");
    try {
        $result = $db->query("SELECT COUNT(*) as cnt FROM stock_companies LIMIT 1");
        $count = $result->fetch(PDO::FETCH_ASSOC)['cnt'];
        $diagnostics['stock_companies_exists'] = true;
        $diagnostics['stock_companies_count'] = intval($count);
        error_log("✓ stock_companies table exists with {$count} records");
    } catch (Exception $e) {
        $diagnostics['stock_companies_exists'] = false;
        $diagnostics['stock_companies_error'] = $e->getMessage();
        error_log("✗ stock_companies table missing or error: " . $e->getMessage());
    }
    
    // TEST 2: Check if stock_prices table exists and describe it
    error_log("\n--- TEST 2: Check stock_prices table ---");
    try {
        $result = $db->query("DESCRIBE stock_prices");
        $columns = $result->fetchAll(PDO::FETCH_ASSOC);
        $diagnostics['stock_prices_exists'] = true;
        $diagnostics['stock_prices_columns'] = count($columns);
        
        $col_names = array_column($columns, 'Field');
        $diagnostics['stock_prices_column_names'] = $col_names;
        
        error_log("✓ stock_prices table exists with " . count($columns) . " columns:");
        error_log("  Columns: " . implode(', ', $col_names));
    } catch (Exception $e) {
        $diagnostics['stock_prices_exists'] = false;
        $diagnostics['stock_prices_error'] = $e->getMessage();
        error_log("✗ stock_prices table missing: " . $e->getMessage());
    }
    
    // TEST 3: Check if stock_fetch_logs table exists
    error_log("\n--- TEST 3: Check stock_fetch_logs table ---");
    try {
        $result = $db->query("SELECT COUNT(*) as cnt FROM stock_fetch_logs LIMIT 1");
        $count = $result->fetch(PDO::FETCH_ASSOC)['cnt'];
        $diagnostics['stock_fetch_logs_exists'] = true;
        $diagnostics['stock_fetch_logs_count'] = intval($count);
        error_log("✓ stock_fetch_logs table exists with {$count} records");
    } catch (Exception $e) {
        $diagnostics['stock_fetch_logs_exists'] = false;
        $diagnostics['stock_fetch_logs_error'] = $e->getMessage();
        error_log("✗ stock_fetch_logs table missing: " . $e->getMessage());
    }
    
    // TEST 4: Get a sample company from stock_companies
    error_log("\n--- TEST 4: Sample company record ---");
    try {
        $stmt = $db->query("SELECT id, symbol, name FROM stock_companies WHERE is_active = TRUE LIMIT 1");
        $company = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($company) {
            $diagnostics['sample_company'] = $company;
            error_log("✓ Found company: ID={$company['id']}, Symbol={$company['symbol']}, Name={$company['name']}");
        } else {
            error_log("⚠️ No active companies found");
            $diagnostics['sample_company'] = null;
        }
    } catch (Exception $e) {
        error_log("✗ Error fetching company: " . $e->getMessage());
    }
    
    // TEST 5: Test INSERT statement exactly as used in update_stock_prices.php
    error_log("\n--- TEST 5: Test INSERT statement ---");
    try {
        // Get a test company
        $company_stmt = $db->query("SELECT id FROM stock_companies WHERE is_active = TRUE LIMIT 1");
        $company = $company_stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($company) {
            $company_id = $company['id'];
            
            $insert_sql = "
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
                    'DIAGNOSTIC_TEST', TRUE
                ) ON DUPLICATE KEY UPDATE
                    open_price = VALUES(open_price),
                    updated_at = CURRENT_TIMESTAMP
            ";
            
            error_log("Preparing INSERT statement...");
            $insert_stmt = $db->prepare($insert_sql);
            error_log("✓ Statement prepared successfully");
            
            // Execute with test data
            $test_data = [
                $company_id,
                'TEST_' . date('His'),
                date('Y-m-d'),
                450.00,
                465.00,
                448.00,
                460.00,
                460.00,
                10.00,
                2.22
            ];
            
            error_log("Executing INSERT with test data: company_id={$company_id}");
            $result = $insert_stmt->execute($test_data);
            
            if ($result) {
                error_log("✓ INSERT executed successfully");
                $diagnostics['insert_test'] = 'SUCCESS';
                
                // Verify inserted
                $verify_stmt = $db->prepare("SELECT id, symbol, trading_date FROM stock_prices WHERE symbol = ? ORDER BY id DESC LIMIT 1");
                $verify_stmt->execute([$test_data[1]]);
                $verify = $verify_stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($verify) {
                    error_log("✓ Record verified in database: ID={$verify['id']}, Symbol={$verify['symbol']}, Date={$verify['trading_date']}");
                    $diagnostics['insert_verified'] = $verify;
                } else {
                    error_log("⚠️ Record not found after insert");
                    $diagnostics['insert_verified'] = false;
                }
            } else {
                error_log("✗ INSERT execution failed");
                error_log("Error: " . implode(' ', $insert_stmt->errorInfo()));
                $diagnostics['insert_test'] = 'FAILED';
                $diagnostics['insert_error'] = implode(' ', $insert_stmt->errorInfo());
            }
        } else {
            error_log("⚠️ Cannot test INSERT - no companies available");
            $diagnostics['insert_test'] = 'SKIPPED - no companies';
        }
    } catch (Exception $e) {
        error_log("✗ INSERT test error: " . $e->getMessage());
        $diagnostics['insert_test'] = 'ERROR';
        $diagnostics['insert_test_error'] = $e->getMessage();
    }
    
    // TEST 6: Run a simple SELECT fetch like update_stock_prices does
    error_log("\n--- TEST 6: Simulate fetch_log insert ---");
    try {
        $log_sql = "
            INSERT INTO stock_fetch_logs (
                fetch_date, symbols_fetched, records_inserted, records_updated,
                errors_count, status, duration_seconds, error_message,
                started_at, completed_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, FROM_UNIXTIME(?), FROM_UNIXTIME(?))
        ";
        
        error_log("Preparing fetch_log INSERT...");
        $log_stmt = $db->prepare($log_sql);
        error_log("✓ Fetch log INSERT prepared");
        
        $now = time();
        $test_log_data = [
            date('Y-m-d'),
            28,
            5,
            0,
            0,
            'test',
            1.23,
            'Diagnostic test',
            $now,
            $now
        ];
        
        error_log("Executing fetch_log INSERT...");
        $result = $log_stmt->execute($test_log_data);
        
        if ($result) {
            error_log("✓ Fetch log INSERT successful");
            $diagnostics['fetch_log_test'] = 'SUCCESS';
        } else {
            error_log("✗ Fetch log INSERT failed: " . implode(' ', $log_stmt->errorInfo()));
            $diagnostics['fetch_log_test'] = 'FAILED';
            $diagnostics['fetch_log_error'] = implode(' ', $log_stmt->errorInfo());
        }
    } catch (Exception $e) {
        error_log("✗ Fetch log test error: " . $e->getMessage());
        $diagnostics['fetch_log_test'] = 'ERROR';
        $diagnostics['fetch_log_test_error'] = $e->getMessage();
    }
    
    error_log("\n=== DIAGNOSTIC COMPLETE ===");
    
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'timestamp' => date('Y-m-d H:i:s'),
        'diagnostics' => $diagnostics
    ], JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    error_log("FATAL: " . $e->getMessage());
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'diagnostics' => $diagnostics ?? []
    ]);
}
?>
