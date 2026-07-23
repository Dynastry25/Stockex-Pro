<?php
/**
 * API v1 - Stock Prices Endpoint
 * 
 * Retrieve stock price data from database
 * 
 * Usage:
 * GET  /api/v1/stock_prices.php - Get latest snapshot for all symbols
 * GET  /api/v1/stock_prices.php?symbol=CRDB - Get latest snapshot for CRDB
 * GET  /api/v1/stock_prices.php?symbol=CRDB&days=30 - Get historical data (last 30 days)
 * GET  /api/v1/stock_prices.php?date=2026-02-10 - Get snapshots for specific date
 * 
 * Query Parameters:
 * - symbol: Stock symbol (e.g., CRDB, NMB, TBL)
 * - days: Number of days of historical data (1-365, default: 1)
 * - date: Specific date (YYYY-MM-DD format)
 * - from_date: Start date for range query
 * - to_date: End date for range query
 * - limit: Max records to return (default: 100, max: 1000)
 */

// Enable error handling
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../../logs/stock_api_errors.log');

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

try {
    // Include database config
    require_once __DIR__ . '/../../config/database.php';
    
    $db = getDBConnection();
    
    if (!$db) {
        throw new Exception('Database connection failed');
    }
    
    // Get query parameters
    $symbol = isset($_GET['symbol']) ? strtoupper(trim($_GET['symbol'])) : null;
    $days = isset($_GET['days']) ? min(max(1, intval($_GET['days'])), 365) : 1;
    $date = isset($_GET['date']) ? trim($_GET['date']) : null;
    $from_date = isset($_GET['from_date']) ? trim($_GET['from_date']) : null;
    $to_date = isset($_GET['to_date']) ? trim($_GET['to_date']) : null;
    $limit = isset($_GET['limit']) ? min(max(1, intval($_GET['limit'])), 1000) : 100;
    
    error_log("Stock prices request: symbol={$symbol}, days={$days}, date={$date}");
    
    // Determine if any date filter is applied
    $dateFiltersApplied = $date || ($from_date && $to_date) || $days > 1;
    
    // Prepare parameters array
    $params = [];
    
    if (!$dateFiltersApplied) {
        // ---- LATEST SNAPSHOT MODE ----
        // Build subquery to get max snapshot_time per symbol (optionally filtered by symbol)
        $subQuery = "SELECT symbol, MAX(snapshot_time) as max_time
                     FROM stock_prices
                     WHERE 1=1";
        if ($symbol) {
            $subQuery .= " AND symbol = ?";
            $params[] = $symbol;
        }
        $subQuery .= " GROUP BY symbol";
        
        // Main query joining with the subquery
        $query = "
            SELECT 
                sp.id,
                sp.symbol,
                sc.name as company_name,
                sc.full_name,
                sp.trading_date,
                sp.open_price,
                sp.high_price,
                sp.low_price,
                sp.close_price,
                sp.current_price,
                sp.change_value,
                sp.change_percent,
                sp.volume,
                sp.trade_value,
                sp.market_cap,
                sc.currency,
                sc.exchange,
                sp.created_at,
                sp.updated_at
            FROM stock_prices sp
            INNER JOIN (
                $subQuery
            ) latest ON sp.symbol = latest.symbol AND sp.snapshot_time = latest.max_time
            INNER JOIN stock_companies sc ON sp.company_id = sc.id
            ORDER BY sp.symbol ASC
            LIMIT {$limit}
        ";
        
    } else {
        // ---- HISTORICAL MODE ----
        // Build where conditions based on date filters
        $where_conditions = [];
        
        if ($symbol) {
            $where_conditions[] = "sp.symbol = ?";
            $params[] = $symbol;
        }
        
        if ($date) {
            $where_conditions[] = "sp.trading_date = ?";
            $params[] = $date;
        } elseif ($from_date && $to_date) {
            $where_conditions[] = "sp.trading_date BETWEEN ? AND ?";
            $params[] = $from_date;
            $params[] = $to_date;
        } elseif ($days > 1) {
            $where_conditions[] = "sp.trading_date >= DATE_SUB(CURDATE(), INTERVAL ? DAY)";
            $params[] = $days;
        } else {
            // Should not happen because $dateFiltersApplied is true, but fallback
            $where_conditions[] = "sp.trading_date = CURDATE()";
        }
        
        $where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';
        
        $query = "
            SELECT 
                sp.id,
                sp.symbol,
                sc.name as company_name,
                sc.full_name,
                sp.trading_date,
                sp.open_price,
                sp.high_price,
                sp.low_price,
                sp.close_price,
                sp.current_price,
                sp.change_value,
                sp.change_percent,
                sp.volume,
                sp.trade_value,
                sp.market_cap,
                sc.currency,
                sc.exchange,
                sp.created_at,
                sp.updated_at
            FROM stock_prices sp
            INNER JOIN stock_companies sc ON sp.company_id = sc.id
            {$where_clause}
            ORDER BY sp.trading_date DESC, sp.snapshot_time DESC, sp.symbol ASC
            LIMIT {$limit}
        ";
    }
    
    // Execute query
    $stmt = $db->prepare($query);
    $stmt->execute($params);
    $prices = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Format response
    $formatted_prices = array_map(function($price) {
        return [
            'id' => intval($price['id']),
            'symbol' => $price['symbol'],
            'company_name' => $price['company_name'],
            'full_name' => $price['full_name'],
            'trading_date' => $price['trading_date'],
            'prices' => [
                'open' => floatval($price['open_price']),
                'high' => floatval($price['high_price']),
                'low' => floatval($price['low_price']),
                'close' => floatval($price['close_price']),
                'current' => floatval($price['current_price'])
            ],
            'change' => [
                'value' => floatval($price['change_value']),
                'percent' => floatval($price['change_percent'])
            ],
            'volume' => intval($price['volume']),
            'trade_value' => floatval($price['trade_value']),
            'market_cap' => floatval($price['market_cap']),
            'currency' => $price['currency'],
            'exchange' => $price['exchange'],
            'updated_at' => $price['updated_at']
        ];
    }, $prices);
    
    // Get latest fetch log
    $log_stmt = $db->query("
        SELECT * FROM stock_fetch_logs 
        ORDER BY completed_at DESC 
        LIMIT 1
    ");
    $latest_fetch = $log_stmt->fetch(PDO::FETCH_ASSOC);
    
    error_log("Retrieved " . count($formatted_prices) . " stock price records");
    
    // Return response
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'status_code' => 200,
        'timestamp' => date('Y-m-d H:i:s'),
        'data' => [
            'prices' => $formatted_prices,
            'count' => count($formatted_prices),
            'filters' => [
                'symbol' => $symbol,
                'days' => $days,
                'date' => $date,
                'from_date' => $from_date,
                'to_date' => $to_date,
                'limit' => $limit
            ],
            'last_fetch' => $latest_fetch ? [
                'date' => $latest_fetch['fetch_date'],
                'status' => $latest_fetch['status'],
                'symbols_fetched' => intval($latest_fetch['symbols_fetched']),
                'completed_at' => $latest_fetch['completed_at']
            ] : null
        ],
        'message' => 'Stock prices retrieved successfully'
    ], JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    error_log("ERROR in stock_prices.php: " . $e->getMessage());
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'status_code' => 500,
        'timestamp' => date('Y-m-d H:i:s'),
        'error' => 'Failed to retrieve stock prices',
        'message' => $e->getMessage()
    ], JSON_PRETTY_PRINT);
}
?>