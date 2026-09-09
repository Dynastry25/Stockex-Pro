<?php
/**
 * CLI-only runner for database/client_payment_details_schema.sql.
 *
 * Usage on the StockEx server:
 *   php database/run_client_payment_details_migration.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit("Not found.\n");
}

require_once __DIR__ . '/../config/database.php';

$db = getDBConnection();
if (!$db) {
    fwrite(STDERR, "Database connection failed.\n");
    exit(1);
}

$statements = [
    "ALTER TABLE client_submission_queue ADD COLUMN IF NOT EXISTS address TEXT DEFAULT NULL AFTER email",
    "ALTER TABLE client_submission_queue ADD COLUMN IF NOT EXISTS payment_methods JSON DEFAULT NULL COMMENT 'Selected payout methods: bank, phone, selcom' AFTER currency",
    "ALTER TABLE clients ADD COLUMN IF NOT EXISTS payment_methods JSON DEFAULT NULL COMMENT 'Approved payout methods from client portal' AFTER currency",
];

try {
    foreach ($statements as $sql) {
        $db->exec($sql);
    }
    echo "Client payment-details migration applied successfully.\n";
} catch (Throwable $e) {
    fwrite(STDERR, "Migration failed: " . $e->getMessage() . "\n");
    exit(1);
}
