<?php
require_once '../../config/config.php';

// Get database connection
$db = getDBConnection();

// Delete all records from each table (except users)
$tables = [
    'trade_receipts',
    'trade_invoices',
    'expenses_benefits',
    'trades',
    'equities',
    'bonds',
    'bond_auctions',
    'companies',
    'system_settings'
];

foreach ($tables as $table) {
    $db->exec("DELETE FROM {$table}");
}

// Remove any orphaned system settings
$db->exec("DELETE FROM system_settings WHERE setting_key NOT IN ('system_name', 'company_name', 'receipt_prefix', 'invoice_prefix', 'trade_prefix')");

// Success message
$_SESSION['alert'] = ['message' => 'System data has been reset successfully!', 'type' => 'success'];

// Redirect back to dashboard
header("Location: dashboard.php");
exit();
?>