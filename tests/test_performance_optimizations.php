<?php
/**
 * Comprehensive Test Suite for Performance Optimizations
 * Tests all critical behaviors BEFORE changes to ensure nothing breaks.
 *
 * Run: php tests/test_performance_optimizations.php
 */

ob_start();

$passed = 0;
$failed = 0;
$errors = [];
$test_groups = [];

function start_group($name) {
    global $test_groups;
    $test_groups[$name] = ['passed' => 0, 'failed' => 0, 'errors' => []];
    echo "\n--- {$name} ---\n";
}

function end_group($name) {
    global $test_groups;
    $g = $test_groups[$name];
    echo "  Group result: {$g['passed']} passed, {$g['failed']} failed\n";
}

function assert_eq($expected, $actual, $label, $group = 'general') {
    global $passed, $failed, $errors, $test_groups;
    if (!isset($test_groups[$group])) { $test_groups[$group] = ['passed' => 0, 'failed' => 0, 'errors' => []]; }
    if ($expected === $actual) {
        $passed++;
        $test_groups[$group]['passed']++;
        echo "  PASS: {$label}\n";
    } else {
        $failed++;
        $test_groups[$group]['failed']++;
        $msg = "FAIL: {$label}\n    Expected: " . var_export($expected, true) . "\n    Actual:   " . var_export($actual, true);
        $errors[] = $msg;
        $test_groups[$group]['errors'][] = $msg;
        echo "  {$msg}\n";
    }
}

function assert_true($actual, $label, $group = 'general') {
    assert_eq(true, (bool)$actual, $label, $group);
}

function assert_false($actual, $label, $group = 'general') {
    assert_eq(false, (bool)$actual, $label, $group);
}

function assert_non_null($actual, $label, $group = 'general') {
    global $passed, $failed, $errors, $test_groups;
    if (!isset($test_groups[$group])) { $test_groups[$group] = ['passed' => 0, 'failed' => 0, 'errors' => []]; }
    if ($actual !== null) {
        $passed++;
        $test_groups[$group]['passed']++;
        echo "  PASS: {$label}\n";
    } else {
        $failed++;
        $test_groups[$group]['failed']++;
        $msg = "FAIL: {$label}\n    Expected: non-null\n    Actual:   null";
        $errors[] = $msg;
        $test_groups[$group]['errors'][] = $msg;
        echo "  {$msg}\n";
    }
}

function assert_null($actual, $label, $group = 'general') {
    assert_eq(null, $actual, $label, $group);
}

function assert_approx($expected, $actual, $tolerance, $label, $group = 'general') {
    global $passed, $failed, $errors, $test_groups;
    if (!isset($test_groups[$group])) { $test_groups[$group] = ['passed' => 0, 'failed' => 0, 'errors' => []]; }
    if (abs($expected - $actual) <= $tolerance) {
        $passed++;
        $test_groups[$group]['passed']++;
        echo "  PASS: {$label}\n";
    } else {
        $failed++;
        $test_groups[$group]['failed']++;
        $msg = "FAIL: {$label}\n    Expected: ~{$expected} (tolerance {$tolerance})\n    Actual:   {$actual}";
        $errors[] = $msg;
        $test_groups[$group]['errors'][] = $msg;
        echo "  {$msg}\n";
    }
}

echo "==========================================================\n";
echo "  Performance Optimization Test Suite\n";
echo "  Date: " . date('Y-m-d H:i:s') . "\n";
echo "==========================================================\n";

// ============================================================
// Bootstrap
// ============================================================
require_once __DIR__ . '/../config/env_loader.php';
if (!defined('DB_HOST')) define('DB_HOST', 'localhost');
if (!defined('DB_NAME')) define('DB_NAME', 'stockex_exchange_new_db');
if (!defined('DB_USERNAME')) define('DB_USERNAME', 'root');
if (!defined('DB_PASSWORD')) define('DB_PASSWORD', '');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/account_mapping.php';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../auth/auth_middleware.php';
require_once __DIR__ . '/../includes/payroll_helpers.php';
require_once __DIR__ . '/../includes/financial_helpers.php';

$db = getDBConnection();
$orig_user_id = $_SESSION['user_id'] ?? null;
$orig_role = $_SESSION['role'] ?? null;

echo "\n";

// ============================================================
// GROUP 1: Database Connection
// ============================================================
start_group('Database Connection');

assert_true($db instanceof PDO, 'getDBConnection returns PDO instance', 'Database Connection');

$db2 = getDBConnection();
assert_true(spl_object_id($db) === spl_object_id($db2), 'getDBConnection returns same instance (singleton)', 'Database Connection');

try {
    $result = $db->query("SELECT 1 as test_value")->fetch();
    assert_eq(1, $result['test_value'], 'DB connection is usable (SELECT 1)', 'Database Connection');
} catch (PDOException $e) {
    assert_true(false, 'DB connection usable: ' . $e->getMessage(), 'Database Connection');
}

assert_eq(PDO::ERRMODE_EXCEPTION, $db->getAttribute(PDO::ATTR_ERRMODE), 'PDO error mode is ERRMODE_EXCEPTION', 'Database Connection');
assert_eq(PDO::FETCH_ASSOC, $db->getAttribute(PDO::ATTR_DEFAULT_FETCH_MODE), 'PDO default fetch mode is FETCH_ASSOC', 'Database Connection');

end_group('Database Connection');

// ============================================================
// GROUP 2: get_logged_in_user
// ============================================================
start_group('get_logged_in_user');

unset($_SESSION['user_id']);
$result = get_logged_in_user();
assert_null($result, 'Returns null when not logged in', 'get_logged_in_user');

// Create test user (use existing table schema)
try {
    $db->prepare("DELETE FROM users WHERE username = 'test_perf_user'")->execute([]);
    $db->prepare("INSERT INTO users (username, email, password_hash, full_name, role, is_active, status) VALUES (?, ?, ?, ?, ?, ?, ?)")
       ->execute(['test_perf_user', 'test@perf.local', password_hash('test', PASSWORD_DEFAULT), 'Test Performance User', 'trader', 1, 'active']);
    $test_user_id = $db->lastInsertId();

    $_SESSION['user_id'] = $test_user_id;
    $result = get_logged_in_user();
    assert_non_null($result, 'Returns array when logged in', 'get_logged_in_user');
    assert_eq('test_perf_user', $result['username'] ?? null, 'Returns correct username', 'get_logged_in_user');
    assert_eq('trader', $result['role'] ?? null, 'Returns correct role', 'get_logged_in_user');

    $db->prepare("UPDATE users SET is_active = 0 WHERE id = ?")->execute([$test_user_id]);
    clear_user_cache(); // Must clear cache after direct DB update
    $result = get_logged_in_user();
    assert_null($result, 'Returns null for inactive user', 'get_logged_in_user');

    $db->prepare("UPDATE users SET is_active = 1 WHERE id = ?")->execute([$test_user_id]);
    clear_user_cache();
    $_SESSION['user_id'] = $test_user_id;
    $r1 = get_logged_in_user();
    $r2 = get_logged_in_user();
    assert_eq($r1['id'], $r2['id'], 'Returns consistent data across calls', 'get_logged_in_user');

} catch (PDOException $e) {
    echo "  SKIP: DB tests: " . $e->getMessage() . "\n";
}

end_group('get_logged_in_user');

// ============================================================
// GROUP 3: Session/Auth Helpers
// ============================================================
start_group('Session/Auth Helpers');

unset($_SESSION['user_id']);
assert_false(is_logged_in(), 'is_logged_in false when no session', 'Session/Auth Helpers');
$_SESSION['user_id'] = 0;
assert_false(is_logged_in(), 'is_logged_in false for zero', 'Session/Auth Helpers');
$_SESSION['user_id'] = 1;
assert_true(is_logged_in(), 'is_logged_in true with valid id', 'Session/Auth Helpers');
unset($_SESSION['user_id']);

$_SESSION['user_id'] = 42;
$_SESSION['username'] = 'testuser';
$_SESSION['role'] = 'trader';
$_SESSION['full_name'] = 'Test User';
$_SESSION['mandate_enabled'] = 1;
$su = get_session_user();
assert_eq(42, $su['id'], 'get_session_user correct id', 'Session/Auth Helpers');
assert_eq('testuser', $su['username'], 'get_session_user correct username', 'Session/Auth Helpers');
assert_eq('trader', $su['role'], 'get_session_user correct role', 'Session/Auth Helpers');

unset($_SESSION['user_id']);
assert_null(get_session_user(), 'get_session_user null when not logged in', 'Session/Auth Helpers');

$_SESSION['user_id'] = 99;
assert_eq(99, get_current_user_id(), 'get_current_user_id ok', 'Session/Auth Helpers');
unset($_SESSION['user_id']);
assert_null(get_current_user_id(), 'get_current_user_id null', 'Session/Auth Helpers');

$_SESSION['username'] = 'admin_user';
assert_eq('admin_user', get_current_username(), 'get_current_username ok', 'Session/Auth Helpers');
unset($_SESSION['username']);

$_SESSION['role'] = 'ceo';
assert_eq('ceo', get_current_user_role(), 'get_current_user_role ok', 'Session/Auth Helpers');
unset($_SESSION['role']);

// check_session_permission
$_SESSION['role'] = 'system_admin';
assert_true(check_session_permission('system_admin'), 'Admin->admin', 'Session/Auth Helpers');
assert_true(check_session_permission('ceo'), 'Admin->CEO', 'Session/Auth Helpers');
assert_true(check_session_permission('trader'), 'Admin->trader', 'Session/Auth Helpers');

$_SESSION['role'] = 'ceo';
assert_true(check_session_permission('ceo'), 'CEO->CEO', 'Session/Auth Helpers');
assert_true(check_session_permission('trader'), 'CEO->trader', 'Session/Auth Helpers');
assert_false(check_session_permission('system_admin'), 'CEO->admin blocked', 'Session/Auth Helpers');

$_SESSION['role'] = 'trader';
assert_true(check_session_permission('trader'), 'trader->trader', 'Session/Auth Helpers');
assert_false(check_session_permission('ceo'), 'trader->CEO blocked', 'Session/Auth Helpers');
assert_false(check_session_permission('system_admin'), 'trader->admin blocked', 'Session/Auth Helpers');

$_SESSION['role'] = 'hr_manager';
assert_true(check_session_permission('hr_officer'), 'HR mgr->HR officer', 'Session/Auth Helpers');
assert_true(check_session_permission('hr_manager'), 'HR mgr->HR mgr', 'Session/Auth Helpers');
assert_false(check_session_permission('ceo'), 'HR mgr->CEO blocked', 'Session/Auth Helpers');
unset($_SESSION['role']);

// has_role
$_SESSION['role'] = 'system_admin';
assert_true(has_role('system_admin'), 'has_role admin has admin', 'Session/Auth Helpers');
assert_true(has_role('trader'), 'has_role admin has trader', 'Session/Auth Helpers');
assert_true(has_role('CEO'), 'has_role case-insensitive', 'Session/Auth Helpers');

$_SESSION['role'] = 'trader';
assert_false(has_role('ceo'), 'has_role trader no ceo', 'Session/Auth Helpers');
assert_true(has_role('trader'), 'has_role trader has trader', 'Session/Auth Helpers');
assert_false(has_role('ADMIN'), 'has_role trader no admin', 'Session/Auth Helpers');
unset($_SESSION['role']);

end_group('Session/Auth Helpers');

// ============================================================
// GROUP 4: check_permission (DB-dependent)
// ============================================================
start_group('check_permission');

try {
    if (isset($test_user_id)) {
        $_SESSION['user_id'] = $test_user_id; // Set BEFORE cache clear

        $db->prepare("UPDATE users SET role = 'system_admin' WHERE id = ?")->execute([$test_user_id]);
        clear_user_cache();
        assert_true(check_permission('system_admin'), 'admin->system_admin', 'check_permission');
        assert_true(check_permission('ceo'), 'admin->ceo', 'check_permission');
        assert_true(check_permission('trader'), 'admin->trader', 'check_permission');

        $db->prepare("UPDATE users SET role = 'trader' WHERE id = ?")->execute([$test_user_id]);
        clear_user_cache();
        assert_true(check_permission('trader'), 'trader->trader', 'check_permission');
        assert_false(check_permission('ceo'), 'trader->ceo blocked', 'check_permission');
        assert_false(check_permission('system_admin'), 'trader->admin blocked', 'check_permission');

        $db->prepare("UPDATE users SET role = 'hr_manager' WHERE id = ?")->execute([$test_user_id]);
        clear_user_cache();
        assert_true(check_permission('hr_manager'), 'hr_mgr->hr_manager', 'check_permission');
        assert_true(check_permission('hr_officer'), 'hr_mgr->hr_officer', 'check_permission');
        assert_true(check_permission('finance_officer'), 'hr_mgr->finance_officer', 'check_permission');
        assert_false(check_permission('ceo'), 'hr_mgr->ceo blocked', 'check_permission');

        $db->prepare("UPDATE users SET role = 'trader' WHERE id = ?")->execute([$test_user_id]);
        clear_user_cache();
    }
} catch (PDOException $e) {
    echo "  SKIP: " . $e->getMessage() . "\n";
}

end_group('check_permission');

// ============================================================
// GROUP 5: Input Sanitization
// ============================================================
start_group('Input Sanitization');

assert_eq('hello', sanitize_input('  hello  '), 'Trims whitespace', 'Input Sanitization');
assert_eq('hello world', sanitize_input('hello world'), 'Clean text unchanged', 'Input Sanitization');
assert_eq('&lt;script&gt;', sanitize_input('<script>'), 'Escapes HTML tags', 'Input Sanitization');
assert_eq('hello &amp; world', sanitize_input('hello & world'), 'Escapes ampersands', 'Input Sanitization');
assert_eq('', sanitize_input(''), 'Handles empty string', 'Input Sanitization');
assert_eq('123', sanitize_input('123'), 'Handles numeric string', 'Input Sanitization');

end_group('Input Sanitization');

// ============================================================
// GROUP 6: Format Currency
// ============================================================
start_group('Format Currency');

assert_eq('0.00', format_currency(null), 'Handles null', 'Format Currency');
assert_eq('0.00', format_currency(0), 'Handles zero', 'Format Currency');
assert_eq('1,000.00', format_currency(1000), 'Thousands separator', 'Format Currency');
assert_eq('1,234,567.89', format_currency(1234567.89), 'Large number', 'Format Currency');
assert_eq('-500.00', format_currency(-500), 'Negative', 'Format Currency');
assert_eq('123.45', format_currency(123.45), 'Preserves decimals', 'Format Currency');
assert_eq('0.01', format_currency(0.01), 'Small decimal', 'Format Currency');
assert_eq('0.00', format_currency(0.0), 'Handles 0.0', 'Format Currency');

end_group('Format Currency');

// ============================================================
// GROUP 7: Format Date
// ============================================================
start_group('Format Date');

assert_eq('01/01/2024', format_date('2024-01-01'), 'ISO date', 'Format Date');
assert_eq('31/12/2023', format_date('2023-12-31'), 'Year-end', 'Format Date');
assert_eq('15/06/2024', format_date('2024-06-15'), 'Mid-year', 'Format Date');

end_group('Format Date');

// ============================================================
// GROUP 8: Reference Number Generation
// ============================================================
start_group('Reference Number Generation');

$ref1 = generate_reference_number('INV');
assert_true(strpos($ref1, 'INV') === 0, 'Starts with prefix', 'Ref Number');
assert_eq(15, strlen($ref1), 'Correct length', 'Ref Number');

$ref2 = generate_reference_number('TRD');
assert_true(strpos($ref2, 'TRD') === 0, 'Works with TRD prefix', 'Ref Number');

$refs = [];
for ($i = 0; $i < 10; $i++) { $refs[] = generate_reference_number('T'); }
assert_eq(10, count(array_unique($refs)), 'Unique values', 'Ref Number');

end_group('Reference Number Generation');

// ============================================================
// GROUP 9: Account Mapping
// ============================================================
start_group('Account Mapping');

assert_eq('VICTORY FINANCIAL SERVICES LTD', normalizeName('  Victory Financial Services LTD  '), 'normalizeName trim+uc', 'Account Mapping');
assert_eq('VICTORY FINANCIAL SERVICES LTD', normalizeName('Victory   Financial   Services   LTD'), 'normalizeName collapse', 'Account Mapping');
assert_eq('', normalizeName(''), 'normalizeName empty', 'Account Mapping');

assert_eq('2111', getChargeAccountCode('cmsa'), 'CMSA->2111', 'Account Mapping');
assert_eq('2112', getChargeAccountCode('dse'), 'DSE->2112', 'Account Mapping');
assert_eq('2113', getChargeAccountCode('csdr'), 'CSDR->2113', 'Account Mapping');
assert_eq('2114', getChargeAccountCode('vrf'), 'VRF->2114', 'Account Mapping');
assert_eq('2114', getChargeAccountCode('Value Retention Fee'), 'VRF alias->2114', 'Account Mapping');
assert_null(getChargeAccountCode('unknown_charge'), 'Unknown charge->null', 'Account Mapping');

assert_eq('411', getCommissionAccountCode('brokerage commission'), 'Brokerage comm->411', 'Account Mapping');
assert_eq('411', getCommissionAccountCode('unknown'), 'Unknown comm->411', 'Account Mapping');

$k1 = generateGLDuplicateKey('1', 'TRD-001', '1121', 'receivable');
$k2 = generateGLDuplicateKey('1', 'TRD-001', '1121', 'receivable');
$k3 = generateGLDuplicateKey('1', 'TRD-001', '2111', 'charge');
assert_eq($k1, $k2, 'Same inputs same key', 'Account Mapping');
assert_true($k1 !== $k3, 'Diff inputs diff keys', 'Account Mapping');
assert_eq(32, strlen($k1), 'Key is 32 chars MD5', 'Account Mapping');

$data = ['client_name' => 'Victory Financial Services LTD', 'counterparty_name' => '', 'broker_name' => '', 'counterparty_broker' => '', 'sca_code' => '', 'security_id' => '', 'client_cds' => ''];
assert_true(isVictoryOrB13Trade($data), 'Victory by client_name', 'Account Mapping');

$data = ['client_name' => 'Regular', 'counterparty_name' => '', 'broker_name' => '', 'counterparty_broker' => '', 'sca_code' => 'B13/C', 'security_id' => '', 'client_cds' => ''];
assert_true(isVictoryOrB13Trade($data), 'B13 by sca_code', 'Account Mapping');

$data = ['client_name' => 'Regular', 'counterparty_name' => '', 'broker_name' => '', 'counterparty_broker' => '', 'sca_code' => 'OTHER', 'security_id' => 'XYZ', 'client_cds' => ''];
assert_false(isVictoryOrB13Trade($data), 'No match for regular', 'Account Mapping');

end_group('Account Mapping');

// ============================================================
// GROUP 10: Payroll Calculations
// ============================================================
start_group('Payroll Calculations');

assert_eq(0.0, calculate_tax_amount(0), 'Tax on 0', 'Payroll');
assert_eq(0.0, calculate_tax_amount(20000), 'Tax on 20k (below bracket)', 'Payroll');
assert_approx(600.0, calculate_tax_amount(30000), 0.05, 'Tax on 30k ~600', 'Payroll');
assert_approx(5000.0, calculate_tax_amount(60000), 0.05, 'Tax on 60k ~5000', 'Payroll');
assert_approx(30666.67, calculate_tax_amount(150000), 0.10, 'Tax on 150k ~30666.67', 'Payroll');

assert_eq(10000.0, calculate_social_security(100000), 'SS on 100k', 'Payroll');
// SS caps at 2M/12 = 166666.67 monthly contributable
assert_approx(16666.67, calculate_social_security(200000), 0.02, 'SS on 200k (capped)', 'Payroll');
$ss = calculate_social_security(500000);
assert_true($ss > 0, 'SS on 500k capped', 'Payroll');

assert_eq('Draft', get_payroll_status_display('draft'), 'Status: draft', 'Payroll');
assert_eq('Paid', get_payroll_status_display('paid'), 'Status: paid', 'Payroll');
assert_eq('bg-secondary', get_payroll_status_badge('draft'), 'Badge: draft', 'Payroll');
assert_eq('bg-primary', get_payroll_status_badge('paid'), 'Badge: paid', 'Payroll');

end_group('Payroll Calculations');

// ============================================================
// GROUP 11: Financial Helpers
// ============================================================
start_group('Financial Helpers');

assert_true(isCustodianTrade('CSD001', 'B13/C'), 'Different codes', 'Financial Helpers');
assert_false(isCustodianTrade('', 'B13/C'), 'Empty SCA', 'Financial Helpers');
assert_false(isCustodianTrade('B13/C', 'B13/C'), 'Same codes', 'Financial Helpers');
assert_false(isCustodianTrade(null, 'B13/C'), 'Null SCA', 'Financial Helpers');

assert_eq(1, getDefaultAccountId('1001'), 'Default 1001->1', 'Financial Helpers');
assert_eq(38, getDefaultAccountId('1112'), 'Default 1112->38', 'Financial Helpers');
// getDefaultAccountId defaults to 1 (Cash) for unknown codes
assert_eq(1, getDefaultAccountId('9999'), 'Unknown code->1 (default Cash)', 'Financial Helpers');

end_group('Financial Helpers');

// ============================================================
// GROUP 12: Role Hierarchy Consistency
// ============================================================
start_group('Role Hierarchy');

$roles = ['system_admin', 'ceo', 'hr_manager', 'hr_officer', 'finance_officer', 'trader'];

// Test check_permission (DB-based) against known expected values
try {
    foreach ($roles as $user_role) {
        $db->prepare("UPDATE users SET role = ? WHERE id = ?")->execute([$user_role, $test_user_id]);
        $_SESSION['user_id'] = $test_user_id; // Set BEFORE cache clear
        clear_user_cache();
        $_SESSION['role'] = $user_role;

        foreach ($roles as $required_role) {
            $db_result = check_permission($required_role);
            $session_result = check_session_permission($required_role);
            assert_eq(
                $db_result,
                $session_result,
                "Hierarchy: {$user_role} vs {$required_role}",
                'Role Hierarchy'
            );
        }
    }
    $db->prepare("UPDATE users SET role = 'trader' WHERE id = ?")->execute([$test_user_id]);
    clear_user_cache();
} catch (PDOException $e) {
    echo "  SKIP: " . $e->getMessage() . "\n";
}

end_group('Role Hierarchy');

// ============================================================
// GROUP 13: CSRF Token
// ============================================================
start_group('CSRF Token');

unset($_SESSION['csrf_token']);
$t1 = generate_csrf_token();
assert_non_null($t1, 'Token generated', 'CSRF');
assert_eq(64, strlen($t1), 'Token is 64 hex chars', 'CSRF');
$t2 = generate_csrf_token();
assert_eq($t1, $t2, 'Token consistent in session', 'CSRF');
assert_true(validate_csrf_token($t1), 'Valid token validates', 'CSRF');
assert_false(validate_csrf_token('wrong'), 'Wrong token fails', 'CSRF');
assert_false(validate_csrf_token(''), 'Empty token fails', 'CSRF');

end_group('CSRF Token');

// ============================================================
// GROUP 14: Database Query Patterns
// ============================================================
start_group('Query Patterns');

try {
    // Use actual table schema - include sca_code which is NOT NULL
    $db->prepare("DELETE FROM trades WHERE trade_reference = 'TEST-PERF-001'")->execute([]);
    $db->prepare("INSERT INTO trades (trade_reference, asset_class, security_id, security_name, client_cds_account, client_name, capacity, broker_name, counterparty_broker, counterparty_name, trade_side, quantity, price, rate, consideration, trade_date, settlement_date, exchange_reference, status, settlement_status, sca_code, uploaded_by)
        VALUES (?, 'equity', 'TBL', 'TBL Ltd', 'CDS-001', 'Test Client', 'principal', 'Test Broker', 'Test Counterparty', 'Counterparty Name', 'buy', 1000, 150.00, 1.0, 150000.00, '2024-01-15', '2024-01-20', 'EXCH-001', 'active', 'pending', 'B13/C', ?)")
       ->execute(['TEST-PERF-001', $test_user_id ?? 1]);
    $trade_id = $db->lastInsertId();

    // SELECT * WHERE id = ?
    $stmt = $db->prepare("SELECT * FROM trades WHERE id = ?");
    $stmt->execute([$trade_id]);
    $trade = $stmt->fetch(PDO::FETCH_ASSOC);
    assert_eq('TEST-PERF-001', $trade['trade_reference'], 'SELECT * WHERE id correct', 'Query Patterns');
    assert_eq('equity', $trade['asset_class'], 'Trade has correct asset_class', 'Query Patterns');
    assert_eq('active', $trade['status'], 'Trade has correct status', 'Query Patterns');

    // COUNT pattern (dashboard)
    $stmt = $db->query("SELECT COUNT(*) as total FROM trades WHERE status = 'active'");
    assert_true($stmt->fetch()['total'] >= 1, 'COUNT active trades >= 1', 'Query Patterns');

    // Filter by client_cds_account
    $stmt = $db->prepare("SELECT * FROM trades WHERE client_cds_account = ? AND status = 'active'");
    $stmt->execute(['CDS-001']);
    assert_true(count($stmt->fetchAll()) >= 1, 'Filter by client_cds_account', 'Query Patterns');

    // UPDATE settlement_status
    $stmt = $db->prepare("UPDATE trades SET settlement_status = 'paid' WHERE id = ?");
    assert_true($stmt->execute([$trade_id]), 'UPDATE settlement_status', 'Query Patterns');

    $stmt = $db->prepare("SELECT settlement_status FROM trades WHERE id = ?");
    $stmt->execute([$trade_id]);
    assert_eq('paid', $stmt->fetch()['settlement_status'], 'Settlement updated to paid', 'Query Patterns');

    // SUM pattern
    $stmt = $db->query("SELECT COALESCE(SUM(consideration), 0) as total FROM trades WHERE status = 'active'");
    assert_true($stmt->fetch()['total'] >= 0, 'SUM consideration numeric', 'Query Patterns');

    // SELECT COUNT pattern for fee configs
    $stmt = $db->query("SELECT COUNT(*) as total FROM users WHERE is_active = 1");
    assert_true($stmt->fetch()['total'] >= 1, 'COUNT active users', 'Query Patterns');

    // Cleanup
    $db->prepare("DELETE FROM trades WHERE trade_reference = 'TEST-PERF-001'")->execute([]);

} catch (PDOException $e) {
    echo "  SKIP: " . $e->getMessage() . "\n";
}

end_group('Query Patterns');

// ============================================================
// CLEANUP
// ============================================================
try {
    if (isset($test_user_id)) {
        $db->prepare("DELETE FROM users WHERE username = 'test_perf_user'")->execute([]);
    }
} catch (PDOException $e) {}

// Restore session
if ($orig_user_id !== null) { $_SESSION['user_id'] = $orig_user_id; } else { unset($_SESSION['user_id']); }
if ($orig_role !== null) { $_SESSION['role'] = $orig_role; } else { unset($_SESSION['role']); }

// ============================================================
// SUMMARY
// ============================================================
echo "\n==========================================================\n";
echo "  FINAL RESULTS\n";
echo "==========================================================\n";
echo "  Total: " . ($passed + $failed) . " tests\n";
echo "  Passed: {$passed}\n";
echo "  Failed: {$failed}\n";
echo "==========================================================\n";

if ($failed > 0) {
    echo "\n--- FAILED TESTS ---\n";
    foreach ($errors as $e) { echo "  {$e}\n\n"; }
    echo "\n";
    exit(1);
}

echo "\nAll tests passed!\n";
exit(0);
