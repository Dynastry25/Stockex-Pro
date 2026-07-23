<?php
/**
 * Main Configuration File for the Stock Exchange Data Storage System
 *
 * This file handles global configurations, constants, session management,
 * and includes a set of essential helper functions.
 */

// --- Session Management ---
// Start the session if it hasn't been started yet.
// This is crucial for user authentication and managing flash messages.
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// --- Global Constants (override via .env) ---
require_once __DIR__ . '/env_loader.php';
define('APP_ENV', env('APP_ENV', 'production'));
define('APP_DEBUG', env('APP_DEBUG', 'false') === 'true');
define('BASE_URL', rtrim(env('BASE_URL', env('APP_URL', 'http://localhost')), '/') . '/');
define('UPLOAD_PATH', 'uploads/');
define('MAX_FILE_SIZE', 50 * 1024 * 1024); // 50MB in bytes
define('STORAGE_PATH', sys_get_temp_dir());

// --- Available Departments ---
// Define all available departments in the system
define('AVAILABLE_DEPARTMENTS', [
    'HR Department',
    'Finance Department', 
    'IT Department',
    'Operations',
    'Sales & Marketing',
    'Legal Department'
]);

// --- Database Inclusion ---
// Require the database configuration file to establish a connection.
require_once 'database.php';

// --- Timezone and Error Reporting ---
// Set the default timezone to prevent date/time inconsistencies.
date_default_timezone_set('UTC');

// Enable detailed error reporting for development.
// IMPORTANT: These settings MUST be turned OFF in a production environment
// to prevent sensitive information from being exposed.
ini_set('display_errors', '1');
error_reporting(E_ALL);

// --- Helper Functions ---
/**
 * Sanitizes input data to prevent Cross-Site Scripting (XSS) attacks.
 * This function is for output escaping. For database queries, always use
 * prepared statements to prevent SQL injection.
 *
 * @param string $data The raw input data.
 * @return string The sanitized data.
 */
function sanitize_input(string $data): string
{
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
    return $data;
}

/**
 * Safe HTML escaping function that handles null values
 * Prevents deprecation warnings when passing null to htmlspecialchars
 *
 * @param string|null $string The string to escape
 * @return string The escaped string or empty string if null
 */
function safe_html($string): string
{
    return htmlspecialchars($string ?? '', ENT_QUOTES, 'UTF-8');
}

/**
 * Generates a unique, pseudo-random reference number with a given prefix.
 * Uses cryptographically secure `random_int()` for better unpredictability.
 *
 * @param string $prefix The prefix for the reference number (e.g., 'INV').
 * @return string The generated reference number.
 */
function generate_reference_number(string $prefix): string
{
    try {
        // Use a cryptographically secure random number generator.
        return $prefix . date('Ymd') . sprintf('%04d', random_int(1, 9999));
    } catch (Exception $e) {
        // Fallback to a less secure method in case of an error.
        error_log("Error generating secure random number: " . $e->getMessage());
        return $prefix . date('Ymd') . sprintf('%04d', mt_rand(1, 9999));
    }
}

/**
 * Formats a numeric amount as a currency string.
 * This function now also handles null values gracefully, returning '0.00' for any null input.
 *
 * @param float|int|null $amount The amount to format.
 * @return string The formatted currency string.
 */
function format_currency(float|int|null $amount): string
{
    // Handle null values to prevent a fatal TypeError.
    if ($amount === null) {
        return '0.00';
    }
    return number_format($amount, 2, '.', ',');
}

/**
 * Formats a date string into 'd/m/Y' format.
 *
 * @param string $date The date string to format.
 * @return string The formatted date string.
 */
function format_date(string $date): string
{
    return date('d/m/Y', strtotime($date));
}

/**
 * Checks if a user is currently logged in based on the session ID.
 *
 * @return bool True if a user is logged in, false otherwise.
 */
function is_logged_in(): bool
{
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

/**
 * Retrieves the currently logged-in user's data from the database.
 * Returns null if the user is not found or is inactive.
 *
 * @return array|null The user's data array, or null if not found.
 */
function get_logged_in_user(): ?array
{
    if (!is_logged_in()) {
        return null;
    }
    
    // Ensure the database connection is available.
    if (!function_exists('getDBConnection')) {
        error_log("getDBConnection() function not found.");
        return null;
    }

    try {
        $db = getDBConnection();
        $stmt = $db->prepare("SELECT * FROM users WHERE id = ? AND is_active = 1");
        $stmt->execute([$_SESSION['user_id']]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return $user ?: null;
    } catch (PDOException $e) {
        error_log("Database error in get_logged_in_user: " . $e->getMessage());
        return null;
    }
}

/**
 * Checks if the logged-in user has the required permission level based on their role.
 *
 * @param string $required_role The minimum role required (e.g., 'finance_officer').
 * @return bool True if the user has permission, false otherwise.
 */
function check_permission(string $required_role): bool
{
    $user = get_logged_in_user();
    if (!$user) {
        return false;
    }
    
    $role_hierarchy = [
        'system_admin' => 5,
        'ceo' => 4,
        'hr_manager' => 3,
        'hr_officer' => 3,  // Same level as hr_manager
        'finance_officer' => 2,
        'trader' => 1
    ];
    
    // Check if both the user's role and the required role exist in the hierarchy.
    if (!isset($role_hierarchy[$user['role']]) || !isset($role_hierarchy[$required_role])) {
        return false;
    }
    
    return $role_hierarchy[$user['role']] >= $role_hierarchy[$required_role];
}

/**
 * Requires HR department access - all HR roles have equal access
 * Redirects to unauthorized page if user doesn't have HR access
 */
function require_hr(): void
{
    $user = get_logged_in_user();
    
    if (!$user) {
        redirect('auth/login.php');
    }
    
    $hr_roles = ['hr_manager', 'hr_officer', 'system_admin'];
    
    if (!in_array($user['role'], $hr_roles)) {
        show_alert('You do not have permission to access the HR dashboard.', 'danger');
        redirect('dashboard.php');
    }
}

/**
 * Redirects the user to a specified URL and exits the script.
 *
 * @param string $url The URL to redirect to (relative to BASE_URL).
 */
function redirect(string $url): void
{
    header("Location: " . BASE_URL . $url);
    exit();
}

/**
 * Stores a message in the session to be displayed on the next page load.
 *
 * @param string $message The alert message.
 * @param string $type The alert type (e.g., 'success', 'danger', 'info').
 */
function show_alert(string $message, string $type = 'info'): void
{
    $_SESSION['alert'] = [
        'message' => $message,
        'type' => $type
    ];
}

/**
 * Displays any pending alerts from the session and clears the session variable.
 * Assumes a Bootstrap framework is in use.
 * FIXED: Added null checks to prevent warnings
 */
function display_alerts(): void
{
    // Check if alert exists in session
    if (isset($_SESSION['alert']) && is_array($_SESSION['alert'])) {
        // Get alert data with null coalescing to prevent warnings
        $alert_type = $_SESSION['alert']['type'] ?? 'info';
        $alert_message = $_SESSION['alert']['message'] ?? '';
        
        // Only display if there's a message
        if (!empty($alert_message)) {
            echo '<div class="alert alert-' . safe_html($alert_type) . ' alert-dismissible fade show" role="alert">';
            echo safe_html($alert_message);
            echo '<button type="button" class="btn-close" data-bs-dismiss="alert"></button>';
            echo '</div>';
        }
        
        // Clear the alert from session
        unset($_SESSION['alert']);
    }
}

/**
 * Gets the list of available departments for forms and dropdowns
 * Can be used when database departments table is not available
 *
 * @return array List of department names
 */
function get_available_departments(): array
{
    return AVAILABLE_DEPARTMENTS;
}

/**
 * Validates if a department exists in the available departments list
 *
 * @param string $department The department to validate
 * @return bool True if valid, false otherwise
 */
function is_valid_department(string $department): bool
{
    return in_array($department, AVAILABLE_DEPARTMENTS);
}

// Remove or modify existing CSP headers
//header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com; script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net;");
/**
 * Get company details
 */// Add to config.php - after other helper functions

/**
 * Get account hierarchy totals from general ledger
 */
function getAccountBalancesFromLedger($db, $account_codes, $start_date = null, $end_date = null) {
    $placeholders = str_repeat('?,', count($account_codes) - 1) . '?';
    
    $date_condition = "";
    $params = $account_codes;
    
    if ($start_date && $end_date) {
        $date_condition = "AND gl.transaction_date BETWEEN ? AND ?";
        array_push($params, $start_date, $end_date);
    }
    
    $query = "
        SELECT 
            coa.id,
            coa.account_code,
            coa.account_name,
            coa.account_type,
            coa.level,
            coa.parent_id,
            coa.is_group_account,
            coa.normal_balance,
            COALESCE(SUM(
                CASE 
                    WHEN gl.debit_amount > 0 THEN gl.debit_amount
                    WHEN gl.credit_amount > 0 THEN -gl.credit_amount
                    ELSE 0
                END
            ), 0) as balance
        FROM chart_of_accounts coa
        LEFT JOIN general_ledger gl ON coa.id = gl.account_id 
            AND gl.status = 'active'
            {$date_condition}
        WHERE coa.account_code IN ($placeholders)
        AND coa.is_active = 1
        GROUP BY coa.id
        ORDER BY coa.account_code
    ";
    
    $stmt = $db->prepare($query);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Calculate hierarchical totals by level
 */
function calculateHierarchicalTotals($accounts, $target_level = 2) {
    $totals = [];
    
    // First, find all accounts at the target level
    $level_accounts = array_filter($accounts, fn($a) => $a['level'] == $target_level && $a['is_group_account']);
    
    foreach ($level_accounts as $level_account) {
        // Find child accounts
        $child_total = 0;
        foreach ($accounts as $account) {
            if (!$account['is_group_account']) {
                $parent_code = substr($account['account_code'], 0, strlen($level_account['account_code']));
                if ($parent_code == $level_account['account_code']) {
                    $child_total += $account['balance'];
                }
            }
        }
        
        $totals[$level_account['account_code']] = [
            'code' => $level_account['account_code'],
            'name' => $level_account['account_name'],
            'balance' => $child_total,
            'normal_balance' => $level_account['normal_balance']
        ];
    }
    
    return $totals;
}
?>
