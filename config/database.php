<?php
/**
 * Database Configuration
 * Stock Exchange Data Storage System
 *
 * Reads credentials from environment variables.
 * Dev: project-root/.env
 * Prod: /etc/stockex/stockex.env
 */

require_once __DIR__ . '/env_loader.php';

if (!defined('DB_HOST')) {
    define('DB_HOST', env('DB_HOST', 'localhost'));
}

if (!defined('DB_NAME')) {
    define('DB_NAME', env('DB_NAME', 'stockex_db'));
}

if (!defined('DB_USERNAME')) {
    define('DB_USERNAME', env('DB_USERNAME', 'stockex_user'));
}

if (!defined('DB_PASSWORD')) {
    define('DB_PASSWORD', env('DB_PASSWORD', ''));
}

class Database {
    private $host = DB_HOST;
    private $db_name = DB_NAME;
    private $username = DB_USERNAME;
    private $password = DB_PASSWORD;
    private $conn;
    
    public function getConnection() {
        $this->conn = null;
        
        try {
            $this->conn = new PDO(
                "mysql:host=" . $this->host . ";dbname=" . $this->db_name,
                $this->username,
                $this->password,
                array(
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8"
                )
            );
        } catch(PDOException $exception) {
            echo "Connection error: " . $exception->getMessage();
        }
        
        return $this->conn;
    }
}

// Database connection helper function
function getDBConnection() {
    $database = new Database();
    return $database->getConnection();
}
