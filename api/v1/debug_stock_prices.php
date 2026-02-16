<?php
/**
 * Stock Prices Debug - Show Fetch Logs and Diagnostics
 */

header('Content-Type: application/json; charset=UTF-8');

try {
    require_once __DIR__ . '/../../config/database.php';
    $database = new Database();
    $db = $database->getConnection();
    
    if (!$db) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Database connection failed']);
        exit();
    }
    
    // Get fetch logs
    $logs_stmt = $db->query("
        SELECT * FROM stock_fetch_logs 
        ORDER BY completed_at DESC 
        LIMIT 10
    ");
    $logs = $logs_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get stock counts
    $price_count = $db->query("SELECT COUNT(*) FROM stock_prices")->fetch(PDO::FETCH_COLUMN);
    $company_count = $db->query("SELECT COUNT(*) FROM stock_companies")->fetch(PDO::FETCH_COLUMN);
    
    // Sample the latest prices by symbol
    $sample_stmt = $db->query("
        SELECT 
            symbol, 
            COUNT(*) as record_count,
            MAX(trading_date) as latest_date,
            MAX(close_price) as latest_close
        FROM stock_prices
        GROUP BY symbol
        ORDER BY MAX(trading_date) DESC
        LIMIT 10
    ");
    $samples = $sample_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Check for data in different date ranges
    $today = date('Y-m-d');
    $yesterday = date('Y-m-d', strtotime('-1 day'));
    $last7 = date('Y-m-d', strtotime('-7 days'));
    
    $today_count = $db->query("SELECT COUNT(*) FROM stock_prices WHERE trading_date = '{$today}'")->fetch(PDO::FETCH_COLUMN);
    $yesterday_count = $db->query("SELECT COUNT(*) FROM stock_prices WHERE trading_date = '{$yesterday}'")->fetch(PDO::FETCH_COLUMN);
    $last7_count = $db->query("SELECT COUNT(*) FROM stock_prices WHERE trading_date >= '{$last7}'")->fetch(PDO::FETCH_COLUMN);
    
    // Get latest prices for specific stocks
    $crdb_stmt = $db->prepare("
        SELECT * FROM stock_prices 
        WHERE symbol = 'CRDB' 
        ORDER BY trading_date DESC 
        LIMIT 3
    ");
    $crdb_stmt->execute();
    $crdb_prices = $crdb_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'timestamp' => date('Y-m-d H:i:s'),
        'database_status' => [
            'total_stock_prices' => intval($price_count),
            'total_companies' => intval($company_count)
        ],
        'date_breakdown' => [
            'today' => intval($today_count),
            'yesterday' => intval($yesterday_count),
            'last_7_days' => intval($last7_count),
            'today_date' => $today
        ],
        'latest_fetch_log' => $logs[0] ?? null,
        'recent_fetch_logs' => array_slice($logs, 0, 5),
        'sample_prices_by_symbol' => $samples,
        'sample_crdb_prices' => $crdb_prices,
        'diagnosis' => [
            'tables_created' => intval($company_count) > 0,
            'companies_loaded' => intval($company_count) === 28 ? 'All 28 loaded' : (intval($company_count) . ' loaded'),
            'any_prices_stored' => intval($price_count) > 0 ? 'YES' : 'NO - PROBLEM!',
            'todays_prices' => intval($today_count) > 0 ? "YES ({$today_count} records)" : 'NO - Fetch not working',
            'issue' => intval($price_count) === 0 ? 'No records inserted at all' : (intval($today_count) === 0 ? 'Old data exists but not updating' : 'System working')
        ]
    ], JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>
