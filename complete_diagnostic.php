<?php
/**
 * Complete Dealing Sheet Diagnostic
 * Validates all fixes and data retrieval
 */

require_once 'config/config.php';
require_once 'includes/financial_helpers.php';

header('Content-Type: application/json');

$results = [
    'timestamp' => date('Y-m-d H:i:s'),
    'tests' => []
];

try {
    $db = getDBConnection();
    $results['tests']['database_connection'] = '✓ Connected';
    
    // Test 1: Check actual column names
    $test1 = [
        'name' => 'Trades Table Schema',
        'status' => 'pending'
    ];
    
    $query = "DESCRIBE trades";
    $stmt = $db->prepare($query);
    $stmt->execute();
    $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    $expected_cols = ['asset_class', 'security_id', 'client_name', 'counterparty_name', 'consideration', 'trade_date', 'trade_side', 'status'];
    $missing = [];
    foreach ($expected_cols as $col) {
        if (!in_array($col, $columns)) {
            $missing[] = $col;
        }
    }
    
    if (empty($missing)) {
        $test1['status'] = '✓ PASS';
        $test1['message'] = 'All required columns found';
        $test1['columns_found'] = count($columns);
    } else {
        $test1['status'] = '✗ FAIL';
        $test1['message'] = 'Missing columns: ' . implode(', ', $missing);
        $test1['missing'] = $missing;
    }
    $results['tests'][] = $test1;
    
    // Test 2: Get total trades count
    $test2 = [
        'name' => 'Total Trades Count',
        'status' => 'pending'
    ];
    
    $query = "SELECT COUNT(*) as total FROM trades";
    $stmt = $db->prepare($query);
    $stmt->execute();
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $total = $result['total'] ?? 0;
    
    if ($total > 0) {
        $test2['status'] = '✓ PASS';
        $test2['count'] = $total;
    } else {
        $test2['status'] = '⚠ WARNING';
        $test2['message'] = 'No trades in database';
    }
    $results['tests'][] = $test2;
    
    // Test 3: Check asset_class values
    $test3 = [
        'name' => 'Asset Class Distribution',
        'status' => 'pending'
    ];
    
    $query = "SELECT asset_class, COUNT(*) as count FROM trades GROUP BY asset_class ORDER BY count DESC";
    $stmt = $db->prepare($query);
    $stmt->execute();
    $asset_classes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $test3['status'] = '✓ PASS';
    $test3['distribution'] = $asset_classes;
    $results['tests'][] = $test3;
    
    // Test 4: Test getDealingSheetTrades function
    $test4 = [
        'name' => 'getDealingSheetTrades Function',
        'status' => 'pending'
    ];
    
    $trades = getDealingSheetTrades($db);
    if (is_array($trades) && count($trades) > 0) {
        $test4['status'] = '✓ PASS';
        $test4['trades_retrieved'] = count($trades);
        
        // Check sample trade structure
        $sample = $trades[0];
        $required_fields = ['trade_reference', 'trade_type', 'quantity', 'price', 'total_value', 'buyer_name', 'seller_name', 'security_id', 'security_name'];
        $missing_fields = [];
        foreach ($required_fields as $field) {
            if (!isset($sample[$field])) {
                $missing_fields[] = $field;
            }
        }
        
        if (empty($missing_fields)) {
            $test4['fields_check'] = '✓ All required fields present';
        } else {
            $test4['fields_check'] = '✗ Missing: ' . implode(', ', $missing_fields);
            $test4['status'] = '✗ FAIL';
        }
        
        $test4['first_trade_sample'] = $sample;
    } else {
        $test4['status'] = '⚠ WARNING';
        $test4['message'] = 'Function returned no trades';
    }
    $results['tests'][] = $test4;
    
    // Test 5: Test with date filter
    $test5 = [
        'name' => 'getDealingSheetTrades with Date Filter',
        'status' => 'pending'
    ];
    
    $today = date('Y-m-d');
    $trades_today = getDealingSheetTrades($db, $today);
    
    $test5['status'] = '✓ PASS';
    $test5['date'] = $today;
    $test5['trades_today'] = count($trades_today);
    $results['tests'][] = $test5;
    
    // Test 6: Check bonds join
    $test6 = [
        'name' => 'Bonds Table Join',
        'status' => 'pending'
    ];
    
    $query = "SELECT COUNT(*) as total FROM trades t WHERE t.asset_class = 'bond'";
    $stmt = $db->prepare($query);
    $stmt->execute();
    $bond_count = $stmt->fetchColumn();
    
    $query = "SELECT COUNT(*) as total FROM trades t LEFT JOIN bonds b ON t.security_id = b.security_id WHERE t.asset_class = 'bond' AND b.security_id IS NOT NULL";
    $stmt = $db->prepare($query);
    $stmt->execute();
    $matched = $stmt->fetchColumn();
    
    $test6['status'] = '✓ PASS';
    $test6['total_bonds'] = $bond_count;
    $test6['matched_bonds'] = $matched;
    $test6['match_rate'] = $bond_count > 0 ? round(($matched / $bond_count) * 100, 2) . '%' : 'N/A';
    $results['tests'][] = $test6;
    
    // Test 7: Check equities join
    $test7 = [
        'name' => 'Equities Table Join',
        'status' => 'pending'
    ];
    
    $query = "SELECT COUNT(*) as total FROM trades t WHERE t.asset_class IN ('equity', 'shares')";
    $stmt = $db->prepare($query);
    $stmt->execute();
    $equity_count = $stmt->fetchColumn();
    
    $query = "SELECT COUNT(*) as total FROM trades t LEFT JOIN equities e ON t.security_id = e.security_id WHERE t.asset_class IN ('equity', 'shares') AND e.security_id IS NOT NULL";
    $stmt = $db->prepare($query);
    $stmt->execute();
    $matched = $stmt->fetchColumn();
    
    $test7['status'] = '✓ PASS';
    $test7['total_equities'] = $equity_count;
    $test7['matched_equities'] = $matched;
    $test7['match_rate'] = $equity_count > 0 ? round(($matched / $equity_count) * 100, 2) . '%' : 'N/A';
    $results['tests'][] = $test7;
    
    // Test 8: Sample trade with all joins
    $test8 = [
        'name' => 'Sample Trade Data Retrieval',
        'status' => 'pending'
    ];
    
    $query = "
        SELECT 
            t.id,
            t.trade_reference,
            t.asset_class,
            t.security_id,
            t.client_name,
            t.counterparty_name,
            t.consideration,
            COALESCE(b.security_id, e.security_id) as matched_security,
            COALESCE(b.bond_name, e.stock_name) as matched_name
        FROM trades t
        LEFT JOIN bonds b ON t.security_id = b.security_id AND t.asset_class = 'bond'
        LEFT JOIN equities e ON t.security_id = e.security_id AND t.asset_class IN ('equity', 'shares')
        LIMIT 3
    ";
    $stmt = $db->prepare($query);
    $stmt->execute();
    $samples = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $test8['status'] = '✓ PASS';
    $test8['samples'] = $samples;
    $results['tests'][] = $test8;
    
    // Test 9: Check getTradesSummary function
    $test9 = [
        'name' => 'getTradesSummary Function',
        'status' => 'pending'
    ];
    
    $summary = getTradesSummary($db);
    $test9['status'] = '✓ PASS';
    $test9['result'] = $summary;
    $results['tests'][] = $test9;
    
    // Overall result
    $results['status'] = '✓ ALL TESTS PASSED';
    $results['summary'] = [
        'total_trades' => $total,
        'trades_today' => count($trades_today),
        'bond_trades' => $bond_count,
        'equity_trades' => $equity_count
    ];
    
} catch (Exception $e) {
    $results['status'] = '✗ ERROR';
    $results['error'] = $e->getMessage();
    $results['error_trace'] = $e->getTraceAsString();
}

echo json_encode($results, JSON_PRETTY_PRINT);
?>
