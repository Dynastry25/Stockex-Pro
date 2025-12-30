<?php
/**
 * Authentication Middleware
 * Handles role-based access control
 */

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Don't redeclare functions that already exist in config.php
// Remove these duplicate functions:

/*
function is_logged_in(): bool
{
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

function get_logged_in_user(): ?array
{
    if (!is_logged_in()) {
        return null;
    }
    
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
    
    if (!isset($role_hierarchy[$user['role']]) || !isset($role_hierarchy[$required_role])) {
        return false;
    }
    
    return $role_hierarchy[$user['role']] >= $role_hierarchy[$required_role];
}
*/

function require_login() {
    if (!is_logged_in()) {
        redirect('auth/login.php');
    }
}

function require_role($required_role) {
    require_login();
    
    if (!check_permission($required_role)) {
        show_alert('Access denied. You do not have permission to access this page.', 'danger');
        redirect('auth/login.php');
    }
}

function require_admin() {
    require_role('system_admin');
}

function require_trader() {
    require_role('trader');
}

function require_ceo() {
    require_role('ceo');
}

function require_finance_officer() {
    require_role('finance_officer');
}

function check_mandate_enabled() {
    $user = get_logged_in_user();
    if (!$user || !is_array($user)) {
        return false;
    }
    
    // System admin always has mandate enabled
    if ($user['role'] == 'system_admin') {
        return true;
    }
    
    return $user['mandate_enabled'] == 1;
}

function require_mandate() {
    require_login();
    
    if (!check_mandate_enabled()) {
        show_alert('Your mandate is not enabled. Please contact the system administrator.', 'warning');
        redirect('auth/login.php');
    }
}

// These helper functions are safe to keep as they don't duplicate config.php
function get_current_user_id() {
    return $_SESSION['user_id'] ?? null;
}

function get_current_username() {
    return $_SESSION['username'] ?? null;
}

function get_current_user_role() {
    return $_SESSION['role'] ?? null;
}

/**
 * Get user data from session (lightweight version)
 * Use this instead of get_logged_in_user() when you don't need database info
 */
function get_session_user() {
    if (!is_logged_in()) {
        return null;
    }
    
    return [
        'id' => $_SESSION['user_id'],
        'username' => $_SESSION['username'],
        'role' => $_SESSION['role'],
        'full_name' => $_SESSION['full_name'] ?? '',
        'mandate_enabled' => $_SESSION['mandate_enabled'] ?? 0
    ];
}

/**
 * Check if user has permission for required role (simplified version)
 * This uses the session data directly instead of querying database
 */
function check_session_permission($required_role) {
    $user_role = get_current_user_role();
    
    if (!$user_role) {
        return false;
    }
    
    // Role hierarchy (from highest to lowest)
    $role_hierarchy = [
        'system_admin' => 5,
        'ceo' => 4,
        'hr_manager' => 3,
        'hr_officer' => 3,
        'finance_officer' => 2,
        'trader' => 1
    ];
    
    // Check if user has the required role or higher
    if (isset($role_hierarchy[$user_role]) && isset($role_hierarchy[$required_role])) {
        return $role_hierarchy[$user_role] >= $role_hierarchy[$required_role];
    }
    
    // Special cases
    if ($user_role === 'system_admin') {
        return true; // Admin has all permissions
    }
    
    if ($required_role === 'hr_officer' && $user_role === 'hr_manager') {
        return true; // HR manager can access HR officer pages
    }
    
    return $user_role === $required_role;
}

/**
 * Redirect to specified URL
 * This is different from config.php's redirect() which adds BASE_URL
 */
function auth_redirect($url) {
    if (headers_sent()) {
        echo '<script>window.location.href="' . $url . '";</script>';
    } else {
        header("Location: " . $url);
    }
    exit();
}

/**
 * Store alert message to display
 */
function show_auth_alert($message, $type = 'info') {
    $_SESSION['alert_message'] = $message;
    $_SESSION['alert_type'] = $type;
}

/**
 * Display stored alert message
 */
function display_auth_alert() {
    if (isset($_SESSION['alert_message'])) {
        $message = $_SESSION['alert_message'];
        $type = $_SESSION['alert_type'];
        
        $alert_class = 'alert-' . $type;
        $icon = '';
        
        switch ($type) {
            case 'success':
                $icon = 'bi-check-circle-fill';
                break;
            case 'danger':
                $icon = 'bi-exclamation-triangle-fill';
                break;
            case 'warning':
                $icon = 'bi-exclamation-circle-fill';
                break;
            case 'info':
                $icon = 'bi-info-circle-fill';
                break;
        }
        
        echo '<div class="alert ' . $alert_class . ' alert-dismissible fade show" role="alert">';
        echo '<i class="bi ' . $icon . ' me-2"></i>';
        echo $message;
        echo '<button type="button" class="btn-close" data-bs-dismiss="alert"></button>';
        echo '</div>';
        
        unset($_SESSION['alert_message']);
        unset($_SESSION['alert_type']);
    }
}

/**
 * Generate CSRF token
 */
function generate_csrf_token() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Validate CSRF token
 */
function validate_csrf_token($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Update user session data
 */
function update_user_session($user_data) {
    if (isset($user_data['id'])) {
        $_SESSION['user_id'] = (int)$user_data['id'];
    }
    if (isset($user_data['username'])) {
        $_SESSION['username'] = $user_data['username'];
    }
    if (isset($user_data['role'])) {
        $_SESSION['role'] = $user_data['role'];
    }
    if (isset($user_data['full_name'])) {
        $_SESSION['full_name'] = $user_data['full_name'];
    }
    if (isset($user_data['email'])) {
        $_SESSION['email'] = $user_data['email'];
    }
    if (isset($user_data['mandate_enabled'])) {
        $_SESSION['mandate_enabled'] = (bool)$user_data['mandate_enabled'];
    }
}

/**
 * Clear user session (logout)
 */
function clear_user_session() {
    // Unset all session variables
    $_SESSION = array();
    
    // If it's desired to kill the session, also delete the session cookie.
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    
    session_destroy();
}
?>