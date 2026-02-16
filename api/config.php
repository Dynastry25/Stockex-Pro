<?php
/**
 * API Configuration
 * RESTful API for Stock Exchange Database
 */

// Fix CORS headers - remove any existing and set once
header_remove('Access-Control-Allow-Origin');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json; charset=UTF-8');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Include database configuration
require_once __DIR__ . '/../config/database.php';

// API Configuration Constants
define('API_VERSION', 'v1');
define('API_BASE_URL', '/api/v1');
define('DEFAULT_PAGE_SIZE', 50);
define('MAX_PAGE_SIZE', 500);

// Enable error reporting for development (disable in production)
error_reporting(E_ALL);
ini_set('display_errors', 0); // Set to 0 in production
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../logs/api_errors.log');

/**
 * Send JSON response
 */
function sendResponse($data, $statusCode = 200, $message = null) {
    http_response_code($statusCode);
    
    $response = [
        'success' => ($statusCode >= 200 && $statusCode < 300),
        'status_code' => $statusCode,
        'timestamp' => date('Y-m-d H:i:s')
    ];
    
    if ($message) {
        $response['message'] = $message;
    }
    
    if ($data !== null) {
        $response['data'] = $data;
    }
    
    echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit();
}

/**
 * Send error response
 */
function sendError($message, $statusCode = 400, $details = null) {
    http_response_code($statusCode);
    
    $response = [
        'success' => false,
        'status_code' => $statusCode,
        'error' => $message,
        'timestamp' => date('Y-m-d H:i:s')
    ];
    
    if ($details) {
        $response['details'] = $details;
    }
    
    echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit();
}

/**
 * Sanitize input
 */
function sanitizeInput($input) {
    return htmlspecialchars(strip_tags(trim($input)));
}

/**
 * Get query parameters
 */
function getQueryParams() {
    $params = [];
    
    // Page and limit for pagination
    $params['page'] = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
    $params['limit'] = isset($_GET['limit']) ? min(intval($_GET['limit']), MAX_PAGE_SIZE) : DEFAULT_PAGE_SIZE;
    $params['offset'] = ($params['page'] - 1) * $params['limit'];
    
    // Search parameter
    $params['search'] = isset($_GET['search']) ? sanitizeInput($_GET['search']) : '';
    
    // Sort parameters
    $params['sort_by'] = isset($_GET['sort_by']) ? sanitizeInput($_GET['sort_by']) : 'id';
    $params['sort_order'] = isset($_GET['sort_order']) && strtoupper($_GET['sort_order']) === 'DESC' ? 'DESC' : 'ASC';
    
    // Filter parameters
    $params['filters'] = [];
    foreach ($_GET as $key => $value) {
        if (!in_array($key, ['page', 'limit', 'search', 'sort_by', 'sort_order'])) {
            $params['filters'][$key] = sanitizeInput($value);
        }
    }
    
    return $params;
}

/**
 * Validate table name (security check)
 */
function isValidTable($tableName) {
    $validTables = [
        'account_categories',
        'account_opening_balances',
        'agents',
        'approval_notifications',
        'approval_settings',
        'approval_workflows',
        'audit_trail',
        'balance_sheet_items',
        'balance_sheet_reporting_formats',
        'banks_accounts',
        'bonds',
        'bonds_economic_sectors',
        'bond_auctions',
        'bond_issuers',
        'bond_types',
        'brokers',
        'cashflow_components',
        'cash_flow_formats',
        'chart_of_accounts',
        'clients',
        'client_merge_log',
        'companies',
        'coupon_determiners',
        'custodians',
        'custodians_trades',
        'customers',
        'daily_trade_sequence',
        'departments',
        'documents',
        'document_folders',
        'employees',
        'employee_benefits',
        'employee_targets',
        'equities',
        'equities_settings',
        'equity_transactions',
        'etf_trades',
        'fee_configurations',
        'fee_configuration_audit',
        'general_ledger',
        'gl_account_formats',
        'gl_transactions',
        'hr_activities',
        'identity_types',
        'income_statement_items',
        'investments_costing_basis',
        'investment_asset_classes',
        'job_applications',
        'job_positions',
        'journal_entries',
        'leave_requests',
        'leave_types',
        'ledger_types',
        'linked_trades',
        'merged_cds_accounts',
        'payments',
        'payment_frequencies',
        'payment_methods',
        'payroll',
        'payroll_incentives',
        'payroll_items',
        'pending_pay',
        'performance_reviews',
        'performance_targets',
        'positions',
        'receipts',
        'regulatory_fee_assignments',
        'report_periods',
        'salary_history',
        'security_logs',
        'share_market_segments',
        'share_market_trends',
        'share_types',
        'sub_ledger_categories',
        'sub_ledger_groups',
        'sub_ledger_related_parties',
        'sub_ledger_status',
        'suppliers',
        'system_settings',
        'target_reviews',
        'titles',
        'trades',
        'trade_invoices',
        'trade_receipts',
        'transaction',
        'transaction_comments',
        'transaction_types',
        'trial_balance',
        'users',
        'vendors'
    ];
    
    return in_array($tableName, $validTables);
}

/**
 * Log API request
 */
function logAPIRequest($endpoint, $method, $statusCode) {
    $logData = [
        'timestamp' => date('Y-m-d H:i:s'),
        'endpoint' => $endpoint,
        'method' => $method,
        'status_code' => $statusCode,
        'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
    ];
    
    $logFile = __DIR__ . '/../logs/api_access.log';
    $logDir = dirname($logFile);
    
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }
    
    file_put_contents($logFile, json_encode($logData) . PHP_EOL, FILE_APPEND);
}

/**
 * Get CDS account column mapping for tables
 */
function getCDSColumnMapping() {
    return [
        'trades' => 'client_cds_account',
        'payments' => 'client_id',
        'receipts' => 'client_id',
        'transaction' => 'client_id',
        'custodians_trades' => 'client_cds_account',
        'linked_trades' => 'client_cds_account',
        'trade_invoices' => 'client_cds_account',
        'trade_receipts' => 'client_cds_account',
        'bonds' => 'client_id',
        'equities' => 'client_id',
        'equity_transactions' => 'client_id',
        'etf_trades' => 'client_cds_account',
        'fee_configurations' => 'client_id',
        'regulatory_fee_assignments' => 'client_id'
    ];
}

/**
 * Resolve CDS account to client ID if needed
 */
function resolveCDSAccount($cdsAccount, $db) {
    try {
        $stmt = $db->prepare("SELECT id, cds_account, client_name FROM clients WHERE cds_account = :cds LIMIT 1");
        $stmt->bindValue(':cds', $cdsAccount);
        $stmt->execute();
        return $stmt->fetch();
    } catch (Exception $e) {
        return null;
    }
}
?>
