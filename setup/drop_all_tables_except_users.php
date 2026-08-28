<?php

require_once __DIR__ . '/../config/env_loader.php';

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

function connect_database(): array
{
    $host = env('DB_HOST', 'localhost');
    $databaseName = env('DB_NAME', 'stockex_db');
    $username = env('DB_USERNAME', 'stockex_user');
    $password = env('DB_PASSWORD', '');

    if (class_exists('mysqli')) {
        $mysqli = @new mysqli($host, $username, $password, $databaseName);

        if (!$mysqli->connect_error) {
            $mysqli->set_charset('utf8');

            return ['type' => 'mysqli', 'connection' => $mysqli, 'database' => $databaseName];
        }

        throw new RuntimeException('mysqli connection failed: ' . $mysqli->connect_error);
    }

    if (class_exists('PDO')) {
        $dsn = 'mysql:host=' . $host . ';dbname=' . $databaseName;
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ];

        if (defined('PDO::MYSQL_ATTR_INIT_COMMAND')) {
            $options[PDO::MYSQL_ATTR_INIT_COMMAND] = 'SET NAMES utf8';
        }

        $pdo = new PDO($dsn, $username, $password, $options);

        return ['type' => 'pdo', 'connection' => $pdo, 'database' => $databaseName];
    }

    throw new RuntimeException('No MySQL driver available. Install the mysqli or pdo_mysql PHP extension.');
}

$confirmed = env_flag('CONFIRM_DROP_ALL_TABLES', false);

if (!$confirmed) {
    fwrite(STDERR, "Set CONFIRM_DROP_ALL_TABLES=1 to run this script.\n");
    exit(1);
}

$databaseName = env('DB_NAME', 'stockex_db');
$keepTable = 'users';

try {
    $database = connect_database();
    $connection = $database['connection'];

    if ($database['type'] === 'mysqli') {
        $safeDatabaseName = $connection->real_escape_string($databaseName);
        $safeKeepTable = $connection->real_escape_string($keepTable);

        $result = $connection->query(
            "SELECT table_name
             FROM information_schema.tables
             WHERE table_schema = '{$safeDatabaseName}'
               AND table_type = 'BASE TABLE'
               AND table_name <> '{$safeKeepTable}'
             ORDER BY table_name"
        );

        if (!$result) {
            throw new RuntimeException('Failed to list tables: ' . $connection->error);
        }

        $tables = [];

        while ($row = $result->fetch_assoc()) {
            $tables[] = $row['table_name'];
        }
    } else {
        $query = $connection->prepare(
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
    }

    if (empty($tables)) {
        echo "No tables to drop in {$databaseName}.\n";
        exit(0);
    }

    echo 'Dropping ' . count($tables) . " table(s) from {$databaseName}, keeping {$keepTable}.\n";

    if ($database['type'] === 'mysqli') {
        $connection->query('SET FOREIGN_KEY_CHECKS = 0');
    } else {
        $connection->exec('SET FOREIGN_KEY_CHECKS = 0');
    }

    foreach ($tables as $tableName) {
        $quotedTableName = str_replace('`', '``', $tableName);
        if ($database['type'] === 'mysqli') {
            if (!$connection->query("DROP TABLE IF EXISTS `{$quotedTableName}`")) {
                throw new RuntimeException('Failed to drop ' . $tableName . ': ' . $connection->error);
            }
        } else {
            $connection->exec("DROP TABLE IF EXISTS `{$quotedTableName}`");
        }
        echo "Dropped {$tableName}\n";
    }

    if ($database['type'] === 'mysqli') {
        $connection->query('SET FOREIGN_KEY_CHECKS = 1');
    } else {
        $connection->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    echo "Done. Kept table: {$keepTable}\n";
} catch (Throwable $throwable) {
    if (isset($connection)) {
        if ($connection instanceof mysqli) {
            $connection->query('SET FOREIGN_KEY_CHECKS = 1');
        } elseif ($connection instanceof PDO) {
            $connection->exec('SET FOREIGN_KEY_CHECKS = 1');
        }
    }

    fwrite(STDERR, 'Database cleanup failed: ' . $throwable->getMessage() . "\n");
    exit(1);
}
