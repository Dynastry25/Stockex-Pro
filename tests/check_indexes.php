<?php
try {
    $db = new PDO('mysql:host=localhost;dbname=stockex_exchange_new_db', 'root', '');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $tables = ['trades', 'users', 'payments', 'receipts', 'pending_pay', 'general_ledger', 'bonds', 'equities', 'leave_requests', 'payroll', 'performance_targets', 'clients', 'chart_of_accounts', 'banks_accounts', 'hr_activities'];
    foreach ($tables as $t) {
        echo "\n=== $t ===\n";
        $rows = $db->query("SHOW INDEX FROM `$t`")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            echo "  {$r['Column_name']} ({$r['Key_name']})\n";
        }
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
