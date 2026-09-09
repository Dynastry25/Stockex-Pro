<?php

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

function columnExists(PDO $db, string $table, string $column): bool
{
    $sql = "
        SELECT COUNT(*)
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
          AND COLUMN_NAME = ?
    ";

    $stmt = $db->prepare($sql);
    $stmt->execute([$table, $column]);

    return (int)$stmt->fetchColumn() > 0;
}

try {
    echo "Running client payment-details migration...\n";

    if (!columnExists($db, 'client_submission_queue', 'address')) {
        echo "Adding client_submission_queue.address...\n";

        $db->exec("
            ALTER TABLE client_submission_queue
            ADD COLUMN address TEXT DEFAULT NULL AFTER email
        ");
    } else {
        echo "client_submission_queue.address already exists - skipping.\n";
    }

    if (!columnExists($db, 'client_submission_queue', 'payment_methods')) {
        echo "Adding client_submission_queue.payment_methods...\n";

        $db->exec("
            ALTER TABLE client_submission_queue
            ADD COLUMN payment_methods JSON DEFAULT NULL
            COMMENT 'Selected payout methods: bank, phone, selcom'
            AFTER currency
        ");
    } else {
        echo "client_submission_queue.payment_methods already exists - skipping.\n";
    }

    if (!columnExists($db, 'clients', 'payment_methods')) {
        echo "Adding clients.payment_methods...\n";

        $db->exec("
            ALTER TABLE clients
            ADD COLUMN payment_methods JSON DEFAULT NULL
            COMMENT 'Approved payout methods from client portal'
            AFTER currency
        ");
    } else {
        echo "clients.payment_methods already exists - skipping.\n";
    }

    echo "Client payment-details migration applied successfully.\n";

} catch (Throwable $e) {
    fwrite(STDERR, "Migration failed: " . $e->getMessage() . "\n");
    exit(1);
}
