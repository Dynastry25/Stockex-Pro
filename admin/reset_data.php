<?php
require_once '../../config/config.php';

// Get database connection
$db = getDBConnection();

// Set autocommit to false for transactions
$db->beginTransaction();

try {
    // Delete in correct dependency order (child tables first)
    
    // 1. Tables that reference trades (child tables)
    $db->exec("DELETE FROM trade_receipts");
    $db->exec("DELETE FROM trade_invoices");
    
    // 2. Tables that reference bond_auctions
    $db->exec("DELETE FROM bonds");
    
    // 3. Tables that reference companies
    $db->exec("DELETE FROM equities");
    
    // 4. Tables that reference users (non-user tables)
    $db->exec("DELETE FROM trades");
    $db->exec("DELETE FROM expenses_benefits");
    $db->exec("DELETE FROM bond_auctions");
    $db->exec("DELETE FROM companies");
    $db->exec("DELETE FROM system_settings");

    // Commit transaction
    $db->commit();

    // Success message
    $_SESSION['alert'] = ['message' => 'System data has been reset successfully!', 'type' => 'success'];

} catch (Exception $e) {
    // Rollback on error
    $db->rollBack();
    $_SESSION['alert'] = ['message' => 'Error resetting system data: ' . $e->getMessage(), 'type' => 'danger'];
}

// Redirect back to dashboard
header("Location: dashboard.php");
exit();
?>