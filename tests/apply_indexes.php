<?php
try {
    $db = new PDO('mysql:host=localhost;dbname=stockex_exchange_new_db', 'root', '');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $indexes = [
        "CREATE INDEX idx_settlement_status ON trades(settlement_status)",
        "CREATE INDEX idx_status_settlement ON trades(status, settlement_status)",
        "CREATE INDEX idx_trade_date_id ON trades(trade_date, id)",
        "CREATE INDEX idx_cds_asset_status ON trades(client_cds_account, asset_class, status)",
        "CREATE INDEX idx_cds_status ON trades(client_cds_account, status)",
        "CREATE INDEX idx_status_consideration ON trades(status, consideration)",
        "CREATE INDEX idx_source_type_id ON payments(source_type, source_id)",
        "CREATE INDEX idx_status_record_pay ON payments(status, record_in_financial)",
        "CREATE INDEX idx_status_record_rec ON receipts(status, record_in_financial)",
        "CREATE INDEX idx_status_ceo_approved ON pending_pay(status, ceo_approved_at)",
        "CREATE INDEX idx_status_finance_approved ON pending_pay(status, finance_approved_at)",
        "CREATE INDEX idx_subject ON pending_pay(subject)",
        "CREATE INDEX idx_status_role ON users(status, role)",
        "CREATE INDEX idx_hire_date ON users(hire_date)",
        "CREATE INDEX idx_type_active ON chart_of_accounts(account_type, is_active)",
        "CREATE INDEX idx_status_account ON general_ledger(status, account_id)",
        "CREATE INDEX idx_status_end_date ON performance_targets(status, end_date)",
    ];
    
    $created = 0;
    $skipped = 0;
    $errors = 0;
    
    foreach ($indexes as $sql) {
        try {
            $db->exec($sql);
            $created++;
            echo "  CREATED: " . substr($sql, 13, 60) . "\n";
        } catch (PDOException $e) {
            if (strpos($e->getMessage(), 'Duplicate key name') !== false || strpos($e->getMessage(), 'already exists') !== false) {
                $skipped++;
                echo "  EXISTS:  " . substr($sql, 13, 60) . "\n";
            } else {
                $errors++;
                echo "  ERROR:   " . substr($sql, 13, 60) . " -> " . $e->getMessage() . "\n";
            }
        }
    }
    
    echo "\nResults: $created created, $skipped already existed, $errors errors\n";
} catch (Exception $e) {
    echo "Connection error: " . $e->getMessage() . "\n";
}
