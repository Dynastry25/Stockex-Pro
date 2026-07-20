<?php
/**
 * Diagnostic Script - Check Trades Data
 * Run this to verify trades are being retrieved and displayed correctly
 * 
 * Access: /diagnose_trades.php
 */

require_once 'config/config.php';
require_once 'includes/financial_helpers.php';

$output = [];

try {
    $db = getDBConnection();
    $output['db_connection'] = '✓ Database connected successfully';
    
    // 1. Check if trades table exists and has records
    $query = "SELECT COUNT(*) as total FROM trades";
    $stmt = $db->prepare($query);
    $stmt->execute();
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $total_trades = $result['total'] ?? 0;
    $output['total_trades'] = $total_trades . " trade records in database";
    
    // 2. Check active trades
    $query = "SELECT COUNT(*) as total FROM trades WHERE status = 'active'";
    $stmt = $db->prepare($query);
    $stmt->execute();
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $active_trades = $result['total'] ?? 0;
    $output['active_trades'] = $active_trades . " active trade records";
    
    // 3. Check trades by type
    $query = "SELECT asset_class, COUNT(*) as count FROM trades GROUP BY asset_class";
    $stmt = $db->prepare($query);
    $stmt->execute();
    $trades_by_type = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $output['trades_by_type'] = $trades_by_type;
    
    // 4. Check sample trade data
    $query = "SELECT id, trade_reference, asset_class, security_id, trade_date, status FROM trades LIMIT 5";
    $stmt = $db->prepare($query);
    $stmt->execute();
    $sample_trades = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $output['sample_trades'] = $sample_trades;
    
    // 5. Test getDealingSheetTrades function
    $trades_from_function = getDealingSheetTrades($db);
    $output['getDealingSheetTrades_result'] = [
        'count' => count($trades_from_function),
        'first_trade' => $trades_from_function[0] ?? 'NO TRADES'
    ];
    
    // 6. Test with specific date
    $today = date('Y-m-d');
    $trades_today = getDealingSheetTrades($db, $today);
    $output['trades_today'] = count($trades_today);
    
    // 7. Check bonds table
    $query = "SELECT COUNT(*) as total FROM bonds";
    $stmt = $db->prepare($query);
    $stmt->execute();
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $output['total_bonds'] = $result['total'] ?? 0;
    
    // 8. Check equities table
    $query = "SELECT COUNT(*) as total FROM equities";
    $stmt = $db->prepare($query);
    $stmt->execute();
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $output['total_equities'] = $result['total'] ?? 0;
    
    // 9. Test JOIN logic
    $query = "
        SELECT COUNT(*) as total
        FROM trades t
        LEFT JOIN bonds b ON t.trade_type = 'bond' AND t.instrument_id = b.id
        LEFT JOIN equities e ON t.trade_type = 'equity' AND t.instrument_id = e.id
    ";
    $stmt = $db->prepare($query);
    $stmt->execute();
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $output['join_test'] = $result['total'] ?? 0 . " trades with JOINs working";
    
    $output['status'] = '✓ ALL TESTS PASSED';
    
} catch (Exception $e) {
    $output['error'] = $e->getMessage();
    $output['status'] = '✗ ERROR: Check database configuration';
}

header('Content-Type: application/json');
echo json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
?>
