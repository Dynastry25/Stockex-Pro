<?php
/**
 * API v1 - Update Stock Prices (Real‑Time Snapshots)
 * 
 * Fetches current market data from DSE API and inserts a snapshot
 * for every active company. Designed to be called every 15 minutes.
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../../logs/stock_fetch_errors.log');
set_time_limit(60);

header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed. Use GET.']);
    exit();
}

define('DSE_API_URL', 'https://api.dse.co.tz/api/market-data?isBond=false');
define('REQUEST_TIMEOUT', 30);

$start_time = microtime(true);
error_log("=== Stock Snapshot Fetch Started ===");

try {
    require_once __DIR__ . '/../../config/database.php';
    $db = getDBConnection();

    if (!$db) {
        throw new Exception('Database connection failed');
    }

    // Load active companies
    $company_cache = [];
    $stmt = $db->query("SELECT id, symbol FROM stock_companies WHERE is_active = TRUE");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $company_cache[$row['symbol']] = $row['id'];
    }
    error_log("Loaded " . count($company_cache) . " active companies");

    // Prepare INSERT IGNORE statement – if a duplicate (symbol+snapshot_time) exists, it will be ignored
    $insert_sql = "
        INSERT IGNORE INTO stock_prices (
            company_id, symbol, trading_date, snapshot_time,
            open_price, high_price, low_price, close_price, current_price,
            change_value, change_percent,
            volume, market_cap,
            source, created_at
        ) VALUES (
            :company_id, :symbol, :trading_date, :snapshot_time,
            :open_price, :high_price, :low_price, :close_price, :current_price,
            :change_value, :change_percent,
            :volume, :market_cap,
            'DSE_API', NOW()
        )
    ";
    $insert_stmt = $db->prepare($insert_sql);

    // Fetch data from API using cURL
    error_log("Fetching data from DSE API using cURL...");
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => DSE_API_URL,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => REQUEST_TIMEOUT,
        CURLOPT_USERAGENT => 'StockExAPI/1.0',
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        throw new Exception("cURL failed: $curlError");
    }

    if ($httpCode !== 200) {
        throw new Exception("API returned HTTP $httpCode. Response: $response");
    }

    $data = json_decode($response, true);
    if (!is_array($data)) {
        throw new Exception('Invalid JSON response from DSE API');
    }

    error_log("Received " . count($data) . " records from API");

    $stats = [
        'symbols_fetched' => 0,
        'records_inserted' => 0,
        'records_skipped' => 0,
        'errors' => 0,
        'error_details' => []
    ];

    foreach ($data as $item) {
        $company = $item['company'] ?? null;
        if (!$company) {
            $stats['errors']++;
            $stats['error_details'][] = 'Missing company object';
            continue;
        }

        $symbol = $company['symbol'] ?? '';
        if (!$symbol) {
            $stats['errors']++;
            $stats['error_details'][] = 'Missing symbol';
            continue;
        }

        if (!isset($company_cache[$symbol])) {
            error_log("Symbol $symbol not found in database – skipping");
            $stats['symbols_fetched']++;
            continue;
        }

        $company_id = $company_cache[$symbol];

        // Parse fields
        $open_price       = $item['openingPrice'] ?? 0;
        $high_price       = $item['high'] ?? $open_price;
        $low_price        = $item['low'] ?? $open_price;
        $current_price    = $item['marketPrice'] ?? 0;
        $change_value     = $item['change'] ?? 0;
        $change_percent   = $item['percentageChange'] ?? 0;
        $volume           = $item['volume'] ?? 0;
        $market_cap       = $item['marketCap'] ?? 0;
        $time_str         = $item['time'] ?? '';

        $snapshot_time = $time_str ? date('Y-m-d H:i:s', strtotime($time_str)) : date('Y-m-d H:i:s');
        $trading_date = substr($snapshot_time, 0, 10);

        try {
            $insert_stmt->execute([
                ':company_id'     => $company_id,
                ':symbol'         => $symbol,
                ':trading_date'   => $trading_date,
                ':snapshot_time'  => $snapshot_time,
                ':open_price'     => $open_price,
                ':high_price'     => $high_price,
                ':low_price'      => $low_price,
                ':close_price'    => $current_price,
                ':current_price'  => $current_price,
                ':change_value'   => $change_value,
                ':change_percent' => $change_percent,
                ':volume'         => $volume,
                ':market_cap'     => $market_cap
            ]);

            $rowCount = $insert_stmt->rowCount(); // 1 if inserted, 0 if ignored due to duplicate
            if ($rowCount > 0) {
                $stats['records_inserted']++;
            } else {
                $stats['records_skipped']++;
                error_log("Duplicate snapshot skipped for $symbol at $snapshot_time");
            }
        } catch (Exception $e) {
            $stats['errors']++;
            $stats['error_details'][] = "$symbol: " . $e->getMessage();
            error_log("Insert failed for $symbol: " . $e->getMessage());
        }

        $stats['symbols_fetched']++;
    }

    $end_time = microtime(true);
    $duration = round($end_time - $start_time, 2);
    $status = ($stats['errors'] === 0) ? 'success' : (($stats['records_inserted'] > 0) ? 'partial' : 'failed');

    // Log fetch operation (optional)
    try {
        $log_sql = "
            INSERT INTO stock_fetch_logs (
                fetch_date, symbols_fetched, records_inserted, records_updated,
                errors_count, status, duration_seconds, error_message,
                started_at, completed_at
            ) VALUES (
                CURDATE(), :fetched, :inserted, 0, :errors, :status, :duration,
                :error_msg, FROM_UNIXTIME(:start), FROM_UNIXTIME(:end)
            )
        ";
        $log_stmt = $db->prepare($log_sql);
        $log_stmt->execute([
            ':fetched'   => $stats['symbols_fetched'],
            ':inserted'  => $stats['records_inserted'],
            ':errors'    => $stats['errors'],
            ':status'    => $status,
            ':duration'  => $duration,
            ':error_msg' => !empty($stats['error_details']) ? implode('; ', $stats['error_details']) : null,
            ':start'     => $start_time,
            ':end'       => $end_time
        ]);
    } catch (Exception $e) {
        error_log("Could not write to fetch log: " . $e->getMessage());
    }

    error_log("=== Stock Snapshot Fetch Completed: {$duration}s, Inserted: {$stats['records_inserted']}, Skipped: {$stats['records_skipped']}, Errors: {$stats['errors']} ===");

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'timestamp' => date('Y-m-d H:i:s'),
        'data' => [
            'symbols_fetched' => $stats['symbols_fetched'],
            'records_inserted' => $stats['records_inserted'],
            'records_skipped' => $stats['records_skipped'],
            'errors' => $stats['errors'],
            'error_details' => $stats['error_details'],
            'duration_seconds' => $duration,
            'status' => $status
        ],
        'message' => 'Snapshots inserted successfully'
    ], JSON_PRETTY_PRINT);

} catch (Exception $e) {
    $duration = round(microtime(true) - $start_time, 2);
    error_log("FATAL ERROR: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());

    http_response_code(500);
    echo json_encode([
        'success' => false,
        'timestamp' => date('Y-m-d H:i:s'),
        'error' => 'Snapshot fetch failed',
        'message' => $e->getMessage(),
        'duration_seconds' => $duration
    ], JSON_PRETTY_PRINT);
}
?>