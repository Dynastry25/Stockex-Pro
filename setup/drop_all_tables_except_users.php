<?php

require_once __DIR__ . '/../config/database.php';

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

function env_flag(string $key, bool $default = false): bool
{
    $value = getenv($key);

    if ($value === false || $value === '') {
        return $default;
    }

    return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
}

$confirmed = env_flag('CONFIRM_DROP_ALL_TABLES', false);

if (!$confirmed) {
    fwrite(STDERR, "Set CONFIRM_DROP_ALL_TABLES=1 to run this script.\n");
    exit(1);
}

$databaseName = DB_NAME;
$keepTable = 'users';

try {
    $pdo = getDBConnection();

    if (!$pdo) {
        fwrite(STDERR, "Unable to connect to the database.\n");
        exit(1);
    }

    $query = $pdo->prepare(
        "SELECT table_name
         FROM information_schema.tables
         WHERE table_schema = :database_name
           AND table_type = 'BASE TABLE'
           AND table_name <> :keep_table
         ORDER BY table_name"
    );
    $query->execute([
        ':database_name' => $databaseName,
        ':keep_table' => $keepTable,
    ]);

    $tables = $query->fetchAll(PDO::FETCH_COLUMN);

    if (empty($tables)) {
        echo "No tables to drop in {$databaseName}.\n";
        exit(0);
    }

    echo 'Dropping ' . count($tables) . " table(s) from {$databaseName}, keeping {$keepTable}.\n";

    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');

    foreach ($tables as $tableName) {
        $quotedTableName = str_replace('`', '``', $tableName);
        $pdo->exec("DROP TABLE IF EXISTS `{$quotedTableName}`");
        echo "Dropped {$tableName}\n";
    }

    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

    echo "Done. Kept table: {$keepTable}\n";
} catch (Throwable $throwable) {
    if (isset($pdo) && $pdo instanceof PDO) {
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    fwrite(STDERR, 'Database cleanup failed: ' . $throwable->getMessage() . "\n");
    exit(1);
}
