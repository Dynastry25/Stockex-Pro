<?php
/**
 * API v1 - Update Stock Prices (Daily Fetch)
 * 
 * Fetches TODAY's stock price data from DSE API and updates database
 * Designed to be called by cron jobs (UptimeRobot)
 * 
 * Usage:
 * GET  /api/v1/update_stock_prices.php - Fetch and update today's prices
 * GET  /api/v1/update_stock_prices.php?days=7 - Fetch last N days (max 30)
 * 
 * Returns:
 * {
 *   "success": true,
 *   "data": {
 *     "symbols_fetched": 28,
 *     "records_inserted": 15,
 *     "records_updated": 13,
 *     "errors": 0,
 *     "duration": 12.5
 *   }
 * }
 */

// Enable error handling
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../../logs/stock_fetch_errors.log');

// Set execution time limit (5 minutes for cron jobs)
set_time_limit(300);

header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Only allow GET
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed. Use GET.']);
    exit();
}

// Configuration
$DSE_API_BASE = 'https://dse.co.tz/api/get/market/prices/for/range/duration';
$SECURITIES = [
    'EABL', 'KCB', 'NMG', 'MCB', 'USL', 'CRDB', 'KA', 'NMB', 'MKCB', 'TBL',
    'JHL', 'TOL', 'TCC', 'TTP', 'TCCL', 'DSE', 'TPCC', 'MBP', 'SWIS', 'DCB',
    'VODA', 'JATU', 'PAL', 'NICO', 'SWALA', 'YETU', 'AFRIPRISE', 'MUCOBA'
];
$DELAY_MS = 500; // Delay between requests to avoid rate limiting

// Get days parameter (default to 1 for today only, max 30)
$days = isset($_GET['days']) ? min(max(1, intval($_GET['days'])), 30) : 1;

$start_time = microtime(true);
error_log("=== Stock Price Fetch Started: Days={$days} ===");

try {
    // Include database config
    require_once __DIR__ . '/../../config/database.php';
    
    $database = new Database();
    $db = $database->getConnection();
    
    if (!$db) {
        throw new Exception('Database connection failed');
    }
    
    // Initialize counters
    $stats = [
        'symbols_fetched' => 0,
        'records_inserted' => 0,
        'records_updated' => 0,
        'errors' => 0,
        'error_details' => []
    ];
    
    // Prepare company lookup cache
    $company_cache = [];
    $stmt = $db->query("SELECT id, symbol FROM stock_companies WHERE is_active = TRUE");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $company_cache[$row['symbol']] = $row['id'];
    }
    
    error_log("Loaded " . count($company_cache) . " active companies from cache");
    
    // Prepare statements
    $insert_stmt = $db->prepare("
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
            'DSE_API', TRUE
        ) ON DUPLICATE KEY UPDATE
            open_price = VALUES(open_price),
            high_price = VALUES(high_price),
            low_price = VALUES(low_price),
            close_price = VALUES(close_price),
            current_price = VALUES(current_price),
            change_value = VALUES(change_value),
            change_percent = VALUES(change_percent),
            updated_at = CURRENT_TIMESTAMP
    ");
    
    $update_company_stmt = $db->prepare("
        UPDATE stock_companies 
        SET name = ?, market_cap = ?, updated_at = CURRENT_TIMESTAMP 
        WHERE id = ?
    ");
    
    // Fetch data for each security
    foreach ($SECURITIES as $symbol) {
        try {
            error_log("Fetching data for: {$symbol}");
            
            // Build API URL
            $url = "{$DSE_API_BASE}?security_code={$symbol}&days={$days}&class=EQUITY";
            
            // Make HTTP request with error handling
            $context = stream_context_create([
                'http' => [
                    'method' => 'GET',
                    'header' => "User-Agent: StockExAPI/1.0\r\n" .
                                "Accept: application/json\r\n" .
                                "Cache-Control: no-cache\r\n",
                    'timeout' => 10
                ]
            ]);
            
            $response = @file_get_contents($url, false, $context);
            
            if ($response === false) {
                error_log("❌ Failed to fetch: {$symbol}");
                $stats['errors']++;
                $stats['error_details'][] = "{$symbol}: No response from DSE API";
                continue;
            }
            
            $data = json_decode($response, true);
            
            if (!$data) {
                error_log("❌ Invalid JSON for: {$symbol}");
                $stats['errors']++;
                $stats['error_details'][] = "{$symbol}: Invalid JSON response";
                continue;
            }
            
            if (!isset($data['data'])) {
                error_log("⚠️  No 'data' key in response for: {$symbol}");
                error_log("API Response keys: " . implode(', ', array_keys($data)));
                $stats['symbols_fetched']++;
                continue;
            }
            
            // Get company ID
            if (!isset($company_cache[$symbol])) {
                error_log("❌ Symbol {$symbol} not found in cache");
                $stats['errors']++;
                $stats['error_details'][] = "{$symbol}: Company not in database";
                continue;
            }
            
            $company_id = $company_cache[$symbol];
            
            // Update company info if available
            if (isset($data['current'][0])) {
                $current = $data['current'][0];
                $company_name = $current['description'] ?? $symbol;
                $market_cap = floatval($current['market_cap'] ?? 0);
                
                try {
                    $update_company_stmt->execute([$company_name, $market_cap, $company_id]);
                } catch (Exception $uce) {
                    error_log("Warning: Could not update company info for {$symbol}: " . $uce->getMessage());
                }
            }
            
            // Process price history
            $price_data = $data['data'] ?? [];
            error_log("{$symbol}: Found " . count($price_data) . " price records in API response");
            
            if (empty($price_data)) {
                error_log("⚠️  No price data for {$symbol}");
                $stats['symbols_fetched']++;
                continue;
            }
            
            $symbols_inserted = 0;
            
            foreach ($price_data as $record) {
                if (!isset($record['trade_date'])) continue;
                
                $trading_date = substr($record['trade_date'], 0, 10); // Extract date part
                $open = floatval($record['opening_price'] ?? 0);
                $high = floatval($record['high_price'] ?? 0);
                $low = floatval($record['low_price'] ?? 0);
                $close = floatval($record['closing_price'] ?? $record['opening_price'] ?? 0);
                
                // Calculate change
                $change_value = $close - $open;
                $change_percent = ($open > 0) ? (($change_value / $open) * 100) : 0;
                
                try {
                    // Insert/update price record
                    $insert_stmt->execute([
                        $company_id,
                        $symbol,
                        $trading_date,
                        $open,
                        $high > 0 ? $high : $close,
                        $low > 0 ? $low : $open,
                        $close,
                        $close,
                        $change_value,
                        $change_percent
                    ]);
                    
                    $stats['records_inserted']++;
                    $symbols_inserted++;
                    
                } catch (Exception $e) {
                    error_log("❌ Database error for {$symbol} on {$trading_date}: " . $e->getMessage());
                    $stats['errors']++;
                    $stats['error_details'][] = "{$symbol} ({$trading_date}): " . $e->getMessage();
                }
            }
            
            $stats['symbols_fetched']++;
            error_log("✓ {$symbol}: Inserted {$symbols_inserted} records");
            
            // Delay to avoid rate limiting
            usleep($DELAY_MS * 1000);
            usleep($DELAY_MS * 1000);
            
        } catch (Exception $e) {
            $stats['errors']++;
            $error_msg = "{$symbol}: {$e->getMessage()}";
            $stats['error_details'][] = $error_msg;
            error_log("✗ Error: {$error_msg}");
            continue;
        }
    }
    
    // Calculate duration
    $end_time = microtime(true);
    $duration = round($end_time - $start_time, 2);
    
    // Log fetch operation
    $status = ($stats['errors'] === 0) ? 'success' : (($stats['symbols_fetched'] > 0) ? 'partial' : 'failed');
    
    $log_stmt = $db->prepare("
        INSERT INTO stock_fetch_logs (
            fetch_date, symbols_fetched, records_inserted, records_updated,
            errors_count, status, duration_seconds, error_message,
            started_at, completed_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, FROM_UNIXTIME(?), FROM_UNIXTIME(?))
    ");
    
    $log_stmt->execute([
        date('Y-m-d'),
        $stats['symbols_fetched'],
        $stats['records_inserted'],
        $stats['records_updated'],
        $stats['errors'],
        $status,
        $duration,
        !empty($stats['error_details']) ? implode('; ', $stats['error_details']) : null,
        $start_time,
        $end_time
    ]);
    
    error_log("=== Stock Price Fetch Completed: {$duration}s, Status: {$status} ===");
    
    // Return response
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'status_code' => 200,
        'timestamp' => date('Y-m-d H:i:s'),
        'data' => [
            'symbols_fetched' => $stats['symbols_fetched'],
            'records_inserted' => $stats['records_inserted'],
            'records_updated' => $stats['records_updated'],
            'errors' => $stats['errors'],
            'error_details' => $stats['error_details'],
            'duration_seconds' => $duration,
            'status' => $status,
            'fetch_date' => date('Y-m-d'),
            'days_fetched' => $days
        ],
        'message' => "Stock prices updated successfully"
    ], JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    $duration = round(microtime(true) - $start_time, 2);
    
    error_log("FATAL ERROR in update_stock_prices.php: " . $e->getMessage());
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'status_code' => 500,
        'timestamp' => date('Y-m-d H:i:s'),
        'error' => 'Stock price fetch failed',
        'message' => $e->getMessage(),
        'duration_seconds' => $duration
    ], JSON_PRETTY_PRINT);
}
?>
