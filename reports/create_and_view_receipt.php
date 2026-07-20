<?php
require_once '../config/config.php';
require_once '../auth/auth_middleware.php';

require_login();

$trade_id = isset($_GET['trade_id']) ? (int)$_GET['trade_id'] : 0;

if ($trade_id <= 0) {
    die('Invalid trade ID');
}

$db = getDBConnection();

// Get the trade details
$stmt = $db->prepare("SELECT * FROM trades WHERE id = ?");
$stmt->execute([$trade_id]);
$trade = $stmt->fetch();

if (!$trade) {
    die('Trade not found');
}

// Check if a receipt already exists for this trade
$stmt = $db->prepare("SELECT id FROM trade_receipts WHERE trade_id = ? LIMIT 1");
$stmt->execute([$trade_id]);
$existing_receipt = $stmt->fetch();

if ($existing_receipt) {
    // Redirect to view the existing receipt
    header('Location: print_receipt?id=' . $existing_receipt['id']);
    exit;
}

// Create a new receipt for this trade
try {
    $receipt_number = 'RCP-' . date('YmdHis') . '-' . $trade_id;
    
    // Extract receipt details from trade
    $client_cds_account = $trade['client_cds_account'] ?? '';
    $client_name = $trade['client_name'] ?? '';
    $security_name = $trade['security_name'] ?? '';
    $quantity = $trade['quantity'] ?? 0;
    $unit_price = $trade['price'] ?? 0;
    $gross_amount = $trade['consideration'] ?? 0;
    $fees = 0; // Default to 0, can be updated later
    $taxes = 0; // Default to 0, can be updated later
    $net_amount = $gross_amount - $fees - $taxes;
    $currency = $trade['currency'] ?? 'USD';
    $trade_type = $trade['trade_type'] ?? 'bond'; // Get from trade record
    
    $stmt = $db->prepare("
        INSERT INTO trade_receipts (
            receipt_number, trade_id, client_cds_account, client_name, security_name, 
            quantity, unit_price, gross_amount, fees, taxes, net_amount, currency,
            trade_type, receipt_date, generated_by
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)
    ");
    
    $stmt->execute([
        $receipt_number,
        $trade_id,
        $client_cds_account,
        $client_name,
        $security_name,
        $quantity,
        $unit_price,
        $gross_amount,
        $fees,
        $taxes,
        $net_amount,
        $currency,
        $trade_type,
        $_SESSION['user_id']
    ]);
    
    $receipt_id = $db->lastInsertId();
    
    // Redirect to view the newly created receipt
    header('Location: print_receipt?id=' . $receipt_id);
    exit;
    
} catch (PDOException $e) {
    die('Error creating receipt: ' . $e->getMessage());
}
?>
