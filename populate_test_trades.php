<?php
/**
 * Test Data Population Script
 * This script inserts sample trades into the database for testing
 * 
 * Access: /populate_test_trades.php
 * 
 * WARNING: This will INSERT test data. Only run in development!
 */

require_once 'config/config.php';

header('Content-Type: application/json');

$response = [
    'status' => 'error',
    'message' => '',
    'inserted' => 0
];

try {
    $db = getDBConnection();
    
    // First, check if bonds and equities exist
    $query = "SELECT id FROM bonds LIMIT 1";
    $stmt = $db->prepare($query);
    $stmt->execute();
    $bond = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $query = "SELECT id FROM equities LIMIT 1";
    $stmt = $db->prepare($query);
    $stmt->execute();
    $equity = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$bond && !$equity) {
        $response['message'] = 'No bonds or equities found. Please add securities first.';
        echo json_encode($response);
        exit;
    }
    
    // Get a user for the uploaded_by field
    $query = "SELECT id FROM users LIMIT 1";
    $stmt = $db->prepare($query);
    $stmt->execute();
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        $response['message'] = 'No users found. Please create a user first.';
        echo json_encode($response);
        exit;
    }
    
    $user_id = $user['id'];
    
    // Generate sample trades
    $sample_trades = [];
    $today = date('Y-m-d');
    $tomorrow = date('Y-m-d', strtotime('+1 day'));
    
    // Sample bond trades
    if ($bond) {
        $bond_id = $bond['id'];
        $sample_trades[] = [
            'trade_reference' => 'TRD' . date('YmdHis') . '001',
            'trade_type' => 'bond',
            'instrument_id' => $bond_id,
            'trade_side' => 'buy',
            'quantity' => 1000,
            'price' => 100.5000,
            'total_value' => 100500.00,
            'buyer_name' => 'Alpha Trading Inc',
            'seller_name' => 'Beta Securities Ltd',
            'buyer_account' => 'ACC001',
            'seller_account' => 'ACC002',
            'trade_date' => $today,
            'settlement_date' => date('Y-m-d', strtotime($today . ' +3 days')),
            'status' => 'active',
            'uploaded_by' => $user_id
        ];
        
        $sample_trades[] = [
            'trade_reference' => 'TRD' . date('YmdHis') . '002',
            'trade_type' => 'bond',
            'instrument_id' => $bond_id,
            'trade_side' => 'sell',
            'quantity' => 500,
            'price' => 101.2500,
            'total_value' => 50625.00,
            'buyer_name' => 'Gamma Corp',
            'seller_name' => 'Delta Partners',
            'buyer_account' => 'ACC003',
            'seller_account' => 'ACC004',
            'trade_date' => $today,
            'settlement_date' => date('Y-m-d', strtotime($today . ' +3 days')),
            'status' => 'active',
            'uploaded_by' => $user_id
        ];
        
        $sample_trades[] = [
            'trade_reference' => 'TRD' . date('YmdHis') . '003',
            'trade_type' => 'bond',
            'instrument_id' => $bond_id,
            'trade_side' => 'buy',
            'quantity' => 2000,
            'price' => 99.7500,
            'total_value' => 199500.00,
            'buyer_name' => 'Epsilon Trading',
            'seller_name' => 'Zeta Investments',
            'buyer_account' => 'ACC005',
            'seller_account' => 'ACC006',
            'trade_date' => $today,
            'settlement_date' => date('Y-m-d', strtotime($today . ' +3 days')),
            'status' => 'active',
            'uploaded_by' => $user_id
        ];
    }
    
    // Sample equity trades
    if ($equity) {
        $equity_id = $equity['id'];
        $sample_trades[] = [
            'trade_reference' => 'TRD' . date('YmdHis') . '004',
            'trade_type' => 'equity',
            'instrument_id' => $equity_id,
            'trade_side' => 'buy',
            'quantity' => 5000,
            'price' => 45.5000,
            'total_value' => 227500.00,
            'buyer_name' => 'Macro Funds',
            'seller_name' => 'Micro Holdings',
            'buyer_account' => 'ACC007',
            'seller_account' => 'ACC008',
            'trade_date' => $today,
            'settlement_date' => date('Y-m-d', strtotime($today . ' +2 days')),
            'status' => 'active',
            'uploaded_by' => $user_id
        ];
        
        $sample_trades[] = [
            'trade_reference' => 'TRD' . date('YmdHis') . '005',
            'trade_type' => 'equity',
            'instrument_id' => $equity_id,
            'trade_side' => 'sell',
            'quantity' => 3000,
            'price' => 46.2500,
            'total_value' => 138750.00,
            'buyer_name' => 'Growth Venture',
            'seller_name' => 'Value Partners',
            'buyer_account' => 'ACC009',
            'seller_account' => 'ACC010',
            'trade_date' => $today,
            'settlement_date' => date('Y-m-d', strtotime($today . ' +2 days')),
            'status' => 'active',
            'uploaded_by' => $user_id
        ];
        
        $sample_trades[] = [
            'trade_reference' => 'TRD' . date('YmdHis') . '006',
            'trade_type' => 'equity',
            'instrument_id' => $equity_id,
            'trade_side' => 'buy',
            'quantity' => 2000,
            'price' => 45.0000,
            'total_value' => 90000.00,
            'buyer_name' => 'Tech Invest',
            'seller_name' => 'Market Makers',
            'buyer_account' => 'ACC011',
            'seller_account' => 'ACC012',
            'trade_date' => $tomorrow,
            'settlement_date' => date('Y-m-d', strtotime($tomorrow . ' +2 days')),
            'status' => 'active',
            'uploaded_by' => $user_id
        ];
    }
    
    // Insert trades
    $insert_count = 0;
    foreach ($sample_trades as $trade) {
        try {
            $cols = implode(', ', array_keys($trade));
            $placeholders = implode(', ', array_fill(0, count($trade), '?'));
            $query = "INSERT INTO trades ($cols) VALUES ($placeholders)";
            $stmt = $db->prepare($query);
            $stmt->execute(array_values($trade));
            $insert_count++;
        } catch (Exception $e) {
            // Skip duplicate trades
            error_log("Trade insert error: " . $e->getMessage());
        }
    }
    
    $response['status'] = 'success';
    $response['message'] = 'Test data populated successfully!';
    $response['inserted'] = $insert_count;
    
} catch (Exception $e) {
    $response['message'] = 'Error: ' . $e->getMessage();
}

echo json_encode($response);
?>
