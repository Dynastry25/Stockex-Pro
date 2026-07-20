<?php
require_once '../config/config.php';
require_once '../auth/auth_middleware.php';

// Security headers
header("X-Frame-Options: DENY");
header("X-Content-Type-Options: nosniff");
header("X-XSS-Protection: 1; mode=block");
header("Referrer-Policy: strict-origin-when-cross-origin");
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; font-src 'self' https://cdn.jsdelivr.net;");

require_finance_officer();

$db = getDBConnection();
$db->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");

function validateDate($date, $format = 'Y-m-d') {
    $d = DateTime::createFromFormat($format, $date);
    return $d && $d->format($format) === $date;
}

function sanitizeInput($input) {
    return htmlspecialchars(trim($input ?? ''), ENT_QUOTES, 'UTF-8');
}

function validateNumeric($value) {
    return is_numeric($value) ? (float)$value : 0;
}

// SIMPLIFIED: Get filter values - no default dates
$start_date = $_GET['start_date'] ?? '';
$end_date = $_GET['end_date'] ?? '';
$account_filter = $_GET['account'] ?? '';
$category_filter = $_GET['category'] ?? '';
$reference_type_filter = $_GET['reference_type'] ?? '';
$search_term = $_GET['search'] ?? '';
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$clicked_account_id = $_GET['view_account'] ?? '';

// Validate dates if provided
if ($start_date && !validateDate($start_date)) {
    $start_date = '';
}
if ($end_date && !validateDate($end_date)) {
    $end_date = '';
}

// Fix date order if both provided
if ($start_date && $end_date && strtotime($end_date) < strtotime($start_date)) {
    $end_date = $start_date;
}

// Sanitize
$account_filter = sanitizeInput($account_filter);
$category_filter = sanitizeInput($category_filter);
$reference_type_filter = sanitizeInput($reference_type_filter);
$search_term = sanitizeInput($search_term);

// Debug: Log what we're looking for
error_log("DEBUG - Filters: start_date=$start_date, end_date=$end_date, account_filter=$account_filter, clicked_account_id=$clicked_account_id");

// Get clicked account details
$clicked_account_name = '';
$clicked_account_code = '';
$clicked_account_type = '';
if (!empty($clicked_account_id) && is_numeric($clicked_account_id)) {
    // Use the clicked account ID as filter
    $account_filter = $clicked_account_id;
    
    $account_details_stmt = $db->prepare("
        SELECT id, account_code, account_name, account_type, normal_balance 
        FROM chart_of_accounts 
        WHERE (id = ? OR account_code = ?) AND status = 'active'
    ");
    $account_details_stmt->execute([$clicked_account_id, $clicked_account_id]);
    $account_details = $account_details_stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($account_details) {
        $clicked_account_id = $account_details['id']; // Ensure we have the correct ID
        $clicked_account_name = $account_details['account_name'];
        $clicked_account_code = $account_details['account_code'];
        $clicked_account_type = $account_details['account_type'];
        $clicked_normal_balance = $account_details['normal_balance'] ?? 'debit';
        error_log("DEBUG - Found account: ID=$clicked_account_id, Code=$clicked_account_code, Name=$clicked_account_name");
    } else {
        error_log("DEBUG - Account not found in chart_of_accounts: $clicked_account_id");
    }
}

// Pagination
$per_page = 25;
$offset = ($page - 1) * $per_page;
if ($offset < 0) $offset = 0;

// Get company info
$company_stmt = $db->prepare("SELECT * FROM companies WHERE status = 'active' ORDER BY id ASC LIMIT 1");
$company_stmt->execute();
$company = $company_stmt->fetch(PDO::FETCH_ASSOC) ?? [
    'company_name' => 'NEOVAM LIMITED',
    'registration_number' => 'Not Registered',
    'address' => 'Address Not Set',
    'city' => 'Dar es Salaam',
    'country' => 'Tanzania',
    'phone' => 'Not Available',
    'email' => 'Not Available',
    'website' => 'Not Available',
    'currency' => 'TZS'
];

foreach ($company as $key => $value) {
    $company[$key] = sanitizeInput($value);
}

// BUILD QUERY - SIMPLIFIED
$query = "
    SELECT 
        gl.id,
        gl.transaction_date,
        gl.account_id,
        gl.account_code,
        gl.account_name,
        gl.debit_amount,
        gl.credit_amount,
        gl.running_balance,
        gl.balance_type,
        gl.description,
        gl.reference_no,
        gl.reference_type,
        gl.entity_name,
        gl.entity_type,
        gl.currency,
        gl.created_by,
        gl.created_at,
        gl.created_by_username,
        coa.account_type,
        coa.normal_balance,
        u.full_name as created_by_name
    FROM general_ledger gl
    LEFT JOIN chart_of_accounts coa ON gl.account_id = coa.id
    LEFT JOIN users u ON gl.created_by = u.id
    WHERE gl.status = 'active'
";

$params = [];
$count_params = [];

// Debug the query building
error_log("DEBUG - Building query...");

// 1. Date filter - only if BOTH dates are provided
if ($start_date && $end_date) {
    $query .= " AND gl.transaction_date BETWEEN ? AND ?";
    $params[] = $start_date;
    $params[] = $end_date;
    error_log("DEBUG - Adding date filter: $start_date to $end_date");
} else {
    error_log("DEBUG - No date filter applied");
}

// 2. Account filter - check if we have an account to filter by
if (!empty($account_filter)) {
    if (is_numeric($account_filter)) {
        $query .= " AND gl.account_id = ?";
        $params[] = $account_filter;
        error_log("DEBUG - Adding account filter by ID: $account_filter");
    } else {
        // Maybe it's an account code
        $query .= " AND gl.account_code = ?";
        $params[] = $account_filter;
        error_log("DEBUG - Adding account filter by Code: $account_filter");
    }
}

// 3. Category filter
if (!empty($category_filter)) {
    $valid_categories = ['asset', 'liability', 'equity', 'income', 'expense'];
    if (in_array($category_filter, $valid_categories)) {
        $query .= " AND coa.account_type = ?";
        $params[] = $category_filter;
    }
}

// 4. Reference type filter
if (!empty($reference_type_filter)) {
    $valid_reference_types = ['trade', 'fee', 'adjustment', 'investment', 'payment', 'receipt', 'invoice', 'journal', 'transfer', 'expense', 'income'];
    if (in_array($reference_type_filter, $valid_reference_types)) {
        $query .= " AND gl.reference_type = ?";
        $params[] = $reference_type_filter;
    }
}

// 5. Search filter
if (!empty($search_term)) {
    $query .= " AND (gl.description LIKE ? OR gl.reference_no LIKE ? OR gl.account_name LIKE ? OR gl.entity_name LIKE ?)";
    $search_like = "%$search_term%";
    $params[] = $search_like;
    $params[] = $search_like;
    $params[] = $search_like;
    $params[] = $search_like;
}

// Debug final query
error_log("DEBUG - Final query: " . str_replace(array("\n", "\r", "\t"), ' ', $query));
error_log("DEBUG - Query params: " . json_encode($params));

// Count query - same conditions
$count_query = "SELECT COUNT(*) as total FROM (" . str_replace("SELECT *", "SELECT gl.id", $query) . ") as count_table";
try {
    $count_stmt = $db->prepare($count_query);
    $count_stmt->execute($params);
    $count_result = $count_stmt->fetch(PDO::FETCH_ASSOC);
    $total_records = $count_result['total'] ?? 0;
    $total_pages = ceil($total_records / $per_page);
    
    if ($page > $total_pages && $total_pages > 0) {
        $page = $total_pages;
    }
    
    error_log("DEBUG - Total records found: $total_records");
} catch (Exception $e) {
    error_log("Count query error: " . $e->getMessage());
    $total_records = 0;
    $total_pages = 1;
    $page = 1;
}

// Add sorting and pagination (use direct integer values for LIMIT/OFFSET to avoid binding issues)
$query .= " ORDER BY gl.transaction_date ASC, gl.id ASC LIMIT " . (int)$per_page . " OFFSET " . (int)$offset;

// Execute main query
try {
    $stmt = $db->prepare($query);
    $stmt->execute($params);
    $ledger_entries = $stmt->fetchAll(PDO::FETCH_ASSOC);
    error_log("DEBUG - Found " . count($ledger_entries) . " ledger entries");
    
    // Debug first few entries if found
    if (count($ledger_entries) > 0) {
        error_log("DEBUG - First entry: " . json_encode($ledger_entries[0]));
    }
} catch (Exception $e) {
    error_log("Main query error: " . $e->getMessage());
    $ledger_entries = [];
}

// Get account balances - SIMPLIFIED
$account_balances_query = "
    SELECT 
        gl.account_id,
        MAX(gl.account_code) as account_code,
        MAX(gl.account_name) as account_name,
        MAX(coa.account_type) as account_type,
        MAX(coa.normal_balance) as normal_balance,
        COALESCE(SUM(gl.debit_amount), 0) as total_debit,
        COALESCE(SUM(gl.credit_amount), 0) as total_credit
    FROM general_ledger gl
    LEFT JOIN chart_of_accounts coa ON gl.account_id = coa.id
    WHERE gl.status = 'active'
";

$account_balances_params = [];

// Add date filter to balances if provided
if ($start_date && $end_date) {
    $account_balances_query .= " AND gl.transaction_date BETWEEN ? AND ?";
    $account_balances_params[] = $start_date;
    $account_balances_params[] = $end_date;
}

$account_balances_query .= " GROUP BY gl.account_id ORDER BY MAX(coa.account_type), MAX(gl.account_code)";

try {
    $account_balances_stmt = $db->prepare($account_balances_query);
    $account_balances_stmt->execute($account_balances_params);
    $account_balances_temp = $account_balances_stmt->fetchAll(PDO::FETCH_ASSOC);
    error_log("DEBUG - Found " . count($account_balances_temp) . " account balances");
} catch (Exception $e) {
    error_log("Account balances error: " . $e->getMessage());
    $account_balances_temp = [];
}

$account_balances = [];
$account_types = [
    'asset' => ['icon' => 'bi-cash-stack', 'color' => 'success', 'title' => 'Assets'],
    'liability' => ['icon' => 'bi-credit-card', 'color' => 'danger', 'title' => 'Liabilities'],
    'equity' => ['icon' => 'bi-building', 'color' => 'info', 'title' => 'Equity'],
    'income' => ['icon' => 'bi-arrow-up-circle', 'color' => 'warning', 'title' => 'Income'],
    'expense' => ['icon' => 'bi-arrow-down-circle', 'color' => 'secondary', 'title' => 'Expenses']
];

foreach ($account_balances_temp as $account) {
    $debit = validateNumeric($account['total_debit']);
    $credit = validateNumeric($account['total_credit']);
    $normal_balance = $account['normal_balance'] ?? 'debit';
    
    if ($normal_balance == 'debit') {
        $balance = $debit - $credit;
    } else {
        $balance = $credit - $debit;
    }
    
    if ($debit > 0 || $credit > 0) {
        $account['balance'] = $balance;
        $account['balance_formatted'] = number_format(abs($balance), 2);
        $account['balance_class'] = $balance >= 0 ? 'text-success' : 'text-danger';
        $account['balance_indicator'] = $balance >= 0 ? 'DR' : 'CR';
        $account_balances[] = $account;
    }
}

// Get distinct accounts for filter dropdown
$accounts_query = "
    SELECT DISTINCT gl.account_id, MAX(gl.account_code) as account_code, MAX(gl.account_name) as account_name, MAX(coa.account_type) as account_type
    FROM general_ledger gl
    LEFT JOIN chart_of_accounts coa ON gl.account_id = coa.id
    WHERE gl.status = 'active'
";

$accounts_params = [];
if ($start_date && $end_date) {
    $accounts_query .= " AND gl.transaction_date BETWEEN ? AND ?";
    $accounts_params[] = $start_date;
    $accounts_params[] = $end_date;
}

$accounts_query .= " GROUP BY gl.account_id ORDER BY MAX(coa.account_type), MAX(gl.account_code)";

try {
    $accounts_stmt = $db->prepare($accounts_query);
    $accounts_stmt->execute($accounts_params);
    $accounts = $accounts_stmt->fetchAll(PDO::FETCH_ASSOC);
    error_log("DEBUG - Found " . count($accounts) . " distinct accounts");
} catch (Exception $e) {
    error_log("Accounts query error: " . $e->getMessage());
    $accounts = [];
}

// Get distinct categories
$categories_query = "
    SELECT DISTINCT COALESCE(coa.account_type, 'unknown') as account_type 
    FROM general_ledger gl
    LEFT JOIN chart_of_accounts coa ON gl.account_id = coa.id
    WHERE gl.status = 'active'
";

$categories_params = [];
if ($start_date && $end_date) {
    $categories_query .= " AND gl.transaction_date BETWEEN ? AND ?";
    $categories_params[] = $start_date;
    $categories_params[] = $end_date;
}

$categories_query .= " ORDER BY COALESCE(coa.account_type, 'unknown')";

try {
    $categories_stmt = $db->prepare($categories_query);
    $categories_stmt->execute($categories_params);
    $categories = $categories_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Categories query error: " . $e->getMessage());
    $categories = [];
}

// Get distinct reference types
$reference_types_query = "
    SELECT DISTINCT reference_type 
    FROM general_ledger 
    WHERE reference_type IS NOT NULL 
    AND status = 'active'
";

$reference_types_params = [];
if ($start_date && $end_date) {
    $reference_types_query .= " AND transaction_date BETWEEN ? AND ?";
    $reference_types_params[] = $start_date;
    $reference_types_params[] = $end_date;
}

$reference_types_query .= " ORDER BY reference_type";

try {
    $reference_types_stmt = $db->prepare($reference_types_query);
    $reference_types_stmt->execute($reference_types_params);
    $reference_types = $reference_types_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Reference types query error: " . $e->getMessage());
    $reference_types = [];
}

// CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// Calculate totals
$totals_query = "SELECT COALESCE(SUM(debit_amount), 0) as total_debit, COALESCE(SUM(credit_amount), 0) as total_credit FROM general_ledger WHERE status = 'active'";
$totals_params = [];

if ($start_date && $end_date) {
    $totals_query .= " AND transaction_date BETWEEN ? AND ?";
    $totals_params[] = $start_date;
    $totals_params[] = $end_date;
}

if (!empty($account_filter) && is_numeric($account_filter)) {
    $totals_query .= " AND account_id = ?";
    $totals_params[] = $account_filter;
}

try {
    $totals_stmt = $db->prepare($totals_query);
    $totals_stmt->execute($totals_params);
    $totals = $totals_stmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Totals query error: " . $e->getMessage());
    $totals = ['total_debit' => 0, 'total_credit' => 0];
}

$total_debits = $totals['total_debit'] ?? 0;
$total_credits = $totals['total_credit'] ?? 0;
$net_balance = $total_debits - $total_credits;

$page_title = 'General Ledger';
include '../includes/header.php';
?>

<!-- DEBUG SECTION - Remove in production -->
<?php if (isset($_GET['debug'])): ?>
<div class="container-fluid mt-3">
    <div class="alert alert-info">
        <h5>Debug Information</h5>
        <p>Start Date: <?php echo $start_date ?: 'Not set'; ?></p>
        <p>End Date: <?php echo $end_date ?: 'Not set'; ?></p>
        <p>Account Filter: <?php echo $account_filter ?: 'Not set'; ?></p>
        <p>Clicked Account ID: <?php echo $clicked_account_id ?: 'Not set'; ?></p>
        <p>Clicked Account Name: <?php echo $clicked_account_name ?: 'Not set'; ?></p>
        <p>Total Records: <?php echo $total_records; ?></p>
        <p>Ledger Entries Found: <?php echo count($ledger_entries); ?></p>
        <p>Account Balances Found: <?php echo count($account_balances); ?></p>
        <p>Distinct Accounts: <?php echo count($accounts); ?></p>
        
        <?php if (count($ledger_entries) > 0): ?>
            <p>First Entry Account ID: <?php echo $ledger_entries[0]['account_id']; ?></p>
            <p>First Entry Account Code: <?php echo $ledger_entries[0]['account_code']; ?></p>
        <?php endif; ?>
        
        <hr>
        <h6>All Accounts in Database:</h6>
        <?php
        $all_accounts = $db->query("SELECT id, account_code, account_name FROM chart_of_accounts WHERE status = 'active' ORDER BY account_code")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($all_accounts as $acc): 
            if (strpos($acc['account_code'], '1112') !== false || $acc['id'] == $clicked_account_id):
        ?>
            <div style="background: <?php echo $acc['id'] == $clicked_account_id ? '#d4edda' : '#f8f9fa'; ?>; padding: 5px; margin: 2px;">
                ID: <?php echo $acc['id']; ?> | 
                Code: <?php echo $acc['account_code']; ?> | 
                Name: <?php echo $acc['account_name']; ?>
                <?php if ($acc['id'] == $clicked_account_id): ?><strong>(Current Filter)</strong><?php endif; ?>
            </div>
        <?php 
            endif;
        endforeach; 
        ?>
    </div>
</div>
<?php endif; ?>

<!-- Add a debug link -->
<div style="position: fixed; bottom: 10px; right: 10px; z-index: 1000;">
    <a href="?<?php echo http_build_query(array_merge($_GET, ['debug' => '1'])); ?>" class="btn btn-sm btn-warning">
        Debug
    </a>
</div>

<style>
/* Keep your existing CSS styles */
:root {
    --primary-gradient: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    --sidebar-bg: #f8f9fa;
    --card-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
    --hover-shadow: 0 10px 20px rgba(0, 0, 0, 0.15);
}

body {
    background-color: #f5f7fb;
    font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
}

.main-header {
    background: var(--primary-gradient);
    color: white;
    border-radius: 0 0 20px 20px;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
    margin-bottom: 30px;
}

.account-card {
    border: none;
    border-radius: 15px;
    box-shadow: var(--card-shadow);
    transition: all 0.3s ease;
    margin-bottom: 20px;
}

.account-card:hover {
    transform: translateY(-5px);
    box-shadow: var(--hover-shadow);
}

.account-card .card-header {
    border-radius: 15px 15px 0 0 !important;
    border: none;
    padding: 20px;
}

.account-list-item {
    border: none;
    border-bottom: 1px solid #eee;
    padding: 15px;
    transition: all 0.2s ease;
}

.account-list-item:hover {
    background-color: #f8f9fa;
    transform: translateX(5px);
}

.account-list-item.active {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    border: none;
}

.balance-badge {
    font-size: 0.8rem;
    padding: 4px 10px;
    border-radius: 20px;
}

.table-custom {
    border-collapse: separate;
    border-spacing: 0;
}

.table-custom thead th {
    background: #f8f9fa;
    border: none;
    padding: 15px;
    font-weight: 600;
    color: #495057;
    border-bottom: 2px solid #e9ecef;
}

.table-custom tbody td {
    padding: 15px;
    border-bottom: 1px solid #eee;
    vertical-align: middle;
}

.table-custom tbody tr:hover {
    background-color: #f8f9fa;
}

.reference-badge {
    font-size: 0.75rem;
    padding: 4px 10px;
    border-radius: 12px;
}

.stat-card {
    border: none;
    border-radius: 15px;
    padding: 20px;
    text-align: center;
    color: white;
    margin-bottom: 20px;
    min-height: 120px;
    display: flex;
    flex-direction: column;
    justify-content: center;
}

.stat-card i {
    font-size: 2rem;
    margin-bottom: 10px;
}

.filter-card {
    border: none;
    border-radius: 15px;
    box-shadow: var(--card-shadow);
}

.btn-gradient {
    background: var(--primary-gradient);
    border: none;
    color: white;
    transition: all 0.3s ease;
}

.btn-gradient:hover {
    transform: translateY(-2px);
    box-shadow: 0 5px 15px rgba(102, 126, 234, 0.4);
    color: white;
}

.date-range-display {
    background: white;
    border-radius: 10px;
    padding: 15px;
    border-left: 4px solid #667eea;
    margin-bottom: 20px;
}

.sidebar-scroll {
    max-height: calc(100vh - 300px);
    overflow-y: auto;
}

.sidebar-scroll::-webkit-scrollbar {
    width: 6px;
}

.sidebar-scroll::-webkit-scrollbar-track {
    background: #f1f1f1;
    border-radius: 10px;
}

.sidebar-scroll::-webkit-scrollbar-thumb {
    background: #c1c1c1;
    border-radius: 10px;
}

.sidebar-scroll::-webkit-scrollbar-thumb:hover {
    background: #a1a1a1;
}

.category-header {
    background: #f8f9fa;
    padding: 10px 15px;
    border-radius: 8px;
    margin: 10px 0;
    font-weight: 600;
    color: #495057;
    border-left: 4px solid;
}

.quick-stats {
    background: white;
    border-radius: 15px;
    padding: 20px;
    box-shadow: var(--card-shadow);
}

.ledger-entry-card {
    border: 1px solid #e9ecef;
    border-radius: 10px;
    padding: 15px;
    margin-bottom: 10px;
    background: white;
    transition: all 0.2s ease;
}

.ledger-entry-card:hover {
    border-color: #667eea;
    box-shadow: 0 4px 8px rgba(102, 126, 234, 0.1);
}

.empty-state {
    padding: 60px 20px;
    text-align: center;
    color: #6c757d;
}

.empty-state i {
    font-size: 4rem;
    margin-bottom: 20px;
    opacity: 0.5;
}

.print-hide {
    display: block;
}

@media print {
    .print-hide {
        display: none !important;
    }
    
    .account-card {
        box-shadow: none !important;
        border: 1px solid #ddd !important;
    }
}

.transaction-detail-card {
    border: 1px solid #e9ecef;
    border-radius: 10px;
    padding: 20px;
    margin-bottom: 15px;
    background: white;
}

.transaction-detail-card:hover {
    box-shadow: 0 4px 12px rgba(0,0,0,0.05);
}

.amount-large {
    font-size: 1.25rem;
    font-weight: bold;
}

.no-transactions-state {
    padding: 80px 20px;
    text-align: center;
}

.no-transactions-state i {
    font-size: 4rem;
    color: #dee2e6;
    margin-bottom: 20px;
}
</style>

<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />

<div class="container-fluid py-3">
    <!-- Main Header -->
    <div class="main-header py-4 px-4">
        <div class="row align-items-center">
            <div class="col-md-8">
                <div class="d-flex align-items-center">
                    <div class="me-3">
                        <i class="bi bi-journal-text display-4"></i>
                    </div>
                    <div>
                        <h1 class="h2 mb-1 fw-bold">General Ledger</h1>
                        <p class="mb-0 opacity-90">
                            <i class="bi bi-calendar3 me-1"></i>
                            <?php 
                            if ($start_date && $end_date) {
                                echo htmlspecialchars(date('F d, Y', strtotime($start_date)) . ' to ' . date('F d, Y', strtotime($end_date)));
                            } else {
                                echo 'All Transactions';
                            }
                            ?>
                        </p>
                        <?php if (!empty($clicked_account_name)): ?>
                            <div class="mt-2 d-flex align-items-center">
                                <span class="badge bg-white text-primary fs-6">
                                    <i class="bi bi-journal-bookmark me-1"></i>
                                    Viewing: <?php echo htmlspecialchars($clicked_account_code . ' - ' . $clicked_account_name); ?>
                                </span>
                                <a href="financial_data.php?start_date=<?php echo urlencode($start_date); ?>&amp;end_date=<?php echo urlencode($end_date); ?>" 
                                   class="btn btn-sm btn-light ms-3">
                                    <i class="bi bi-x-lg"></i> Show All Accounts
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="col-md-4 text-end">
                <div class="btn-group print-hide">
                    <button class="btn btn-light btn-gradient shadow-sm" onclick="showDateModal()">
                        <i class="bi bi-calendar-range me-2"></i>Change Period
                    </button>
                    <button class="btn btn-light btn-gradient shadow-sm" onclick="window.print()">
                        <i class="bi bi-printer me-2"></i>Print
                    </button>
                    <button class="btn btn-light btn-gradient shadow-sm" onclick="exportReport('excel')">
                        <i class="bi bi-file-excel me-2"></i>Excel
                    </button>
                    <button class="btn btn-light btn-gradient shadow-sm" onclick="exportReport('pdf')">
                        <i class="bi bi-file-pdf me-2"></i>PDF
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Quick Stats -->
    <div class="row mb-4">
        <div class="col-xl-3 col-md-6">
            <div class="stat-card" style="background: linear-gradient(135deg, #00b09b 0%, #96c93d 100%);">
                <i class="bi bi-arrow-up-circle"></i>
                <h3 class="mb-1"><?php echo htmlspecialchars($company['currency']); ?> <?php echo number_format($total_debits, 2); ?></h3>
                <p class="mb-0 opacity-90">Total Debits</p>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="stat-card" style="background: linear-gradient(135deg, #ff416c 0%, #ff4b2b 100%);">
                <i class="bi bi-arrow-down-circle"></i>
                <h3 class="mb-1"><?php echo htmlspecialchars($company['currency']); ?> <?php echo number_format($total_credits, 2); ?></h3>
                <p class="mb-0 opacity-90">Total Credits</p>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="stat-card" style="background: linear-gradient(135deg, #2193b0 0%, #6dd5ed 100%);">
                <i class="bi bi-graph-up-arrow"></i>
                <h3 class="mb-1"><?php echo number_format($total_records); ?></h3>
                <p class="mb-0 opacity-90">Total Transactions</p>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="stat-card" style="background: linear-gradient(135deg, #654ea3 0%, #da98b4 100%);">
                <i class="bi bi-calculator"></i>
                <h3 class="mb-1 text-white">
                    <?php echo htmlspecialchars($company['currency']); ?> <?php echo number_format(abs($net_balance), 2); ?>
                </h3>
                <p class="mb-0 opacity-90">Net Balance <?php echo $net_balance >= 0 ? 'DR' : 'CR'; ?></p>
            </div>
        </div>
    </div>

    <!-- Main Content -->
    <div class="row">
        <!-- Left Sidebar - Account Balances -->
        <div class="col-lg-4">
            <div class="account-card">
                <div class="card-header bg-light d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="bi bi-wallet2 me-2"></i>Account Balances</h5>
                    <span class="badge bg-primary"><?php echo count($account_balances); ?></span>
                </div>
                <div class="card-body p-0">
                    <div class="sidebar-scroll">
                        <?php if (empty($account_balances)): ?>
                            <div class="empty-state">
                                <i class="bi bi-inbox"></i>
                                <h5>No Account Activity</h5>
                                <p class="text-muted">No transactions found in the selected period</p>
                            </div>
                        <?php else: ?>
                            <?php 
                            $current_category = '';
                            foreach ($account_balances as $account): 
                                if ($account['account_type'] !== $current_category):
                                    $current_category = $account['account_type'];
                                    $type_info = $account_types[$current_category] ?? ['icon' => 'bi-circle', 'color' => 'secondary', 'title' => ucfirst($current_category)];
                            ?>
                                <div class="category-header" style="border-left-color: var(--bs-<?php echo $type_info['color']; ?>);">
                                    <i class="bi <?php echo $type_info['icon']; ?> me-2"></i>
                                    <?php echo $type_info['title']; ?>
                                </div>
                            <?php endif; ?>
                            
                            <a href="?start_date=<?php echo urlencode($start_date); ?>&amp;end_date=<?php echo urlencode($end_date); ?>&amp;view_account=<?php echo $account['account_id']; ?>" 
                               class="account-list-item list-group-item list-group-item-action d-flex justify-content-between align-items-center <?php echo ($account_filter == $account['account_id'] || $clicked_account_id == $account['account_id']) ? 'active' : ''; ?>">
                                <div class="d-flex align-items-center">
                                    <div class="me-3">
                                        <i class="bi bi-journal-text text-<?php echo $account_types[$account['account_type']]['color'] ?? 'secondary'; ?>"></i>
                                    </div>
                                    <div>
                                        <h6 class="mb-1"><?php echo htmlspecialchars($account['account_name']); ?></h6>
                                        <small class="<?php echo ($account_filter == $account['account_id'] || $clicked_account_id == $account['account_id']) ? 'text-white-50' : 'text-muted'; ?>">
                                            <?php echo htmlspecialchars($account['account_code']); ?>
                                        </small>
                                    </div>
                                </div>
                                <div class="text-end">
                                    <div class="fw-bold <?php echo ($account_filter == $account['account_id'] || $clicked_account_id == $account['account_id']) ? 'text-white' : $account['balance_class']; ?>">
                                        <?php echo htmlspecialchars($company['currency']); ?> <?php echo $account['balance_formatted']; ?>
                                    </div>
                                    <small class="<?php echo ($account_filter == $account['account_id'] || $clicked_account_id == $account['account_id']) ? 'text-white-50' : $account['balance_class']; ?>">
                                        <?php echo $account['balance_indicator']; ?>
                                    </small>
                                </div>
                            </a>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Right Content - Ledger Entries -->
        <div class="col-lg-8">
            <!-- Filters Card -->
            <div class="account-card mb-4 print-hide">
                <div class="card-header bg-light">
                    <h5 class="mb-0"><i class="bi bi-funnel me-2"></i>Filters</h5>
                </div>
                
                <div class="card-body">
                    <form method="GET" id="filterForm" action="financial_data">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                        
                        <div class="row g-3 mb-3">
                            <div class="col-md-6 col-lg-3">
                                <label class="form-label small text-muted">Start Date</label>
                                <input type="date" name="start_date" class="form-control" value="<?php echo htmlspecialchars($start_date); ?>">
                            </div>
                            <div class="col-md-6 col-lg-3">
                                <label class="form-label small text-muted">End Date</label>
                                <input type="date" name="end_date" class="form-control" value="<?php echo htmlspecialchars($end_date); ?>">
                            </div>
                            <div class="col-md-6 col-lg-3">
                                <label class="form-label small text-muted">Account</label>
                                <select class="form-select form-select-sm" name="account" id="account">
                                    <option value="">All Accounts</option>
                                    <?php foreach ($accounts as $acc): ?>
                                        <option value="<?php echo htmlspecialchars($acc['account_id']); ?>" 
                                            <?php echo $account_filter == $acc['account_id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($acc['account_code'] . ' - ' . $acc['account_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6 col-lg-3">
                                <label class="form-label small text-muted">Category</label>
                                <select class="form-select form-select-sm" name="category" id="category">
                                    <option value="">All Categories</option>
                                    <?php foreach ($categories as $cat): ?>
                                        <option value="<?php echo htmlspecialchars($cat['account_type']); ?>" 
                                            <?php echo $category_filter == $cat['account_type'] ? 'selected' : ''; ?>>
                                            <?php echo ucfirst(htmlspecialchars($cat['account_type'])); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        
                        <div class="row g-3 mb-3">
                            <div class="col-md-6 col-lg-4">
                                <label class="form-label small text-muted">Reference Type</label>
                                <select class="form-select form-select-sm" name="reference_type" id="reference_type">
                                    <option value="">All Types</option>
                                    <?php foreach ($reference_types as $type): ?>
                                        <option value="<?php echo htmlspecialchars($type['reference_type']); ?>" 
                                            <?php echo $reference_type_filter == $type['reference_type'] ? 'selected' : ''; ?>>
                                            <?php echo ucfirst(htmlspecialchars($type['reference_type'])); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6 col-lg-5">
                                <label class="form-label small text-muted">Search</label>
                                <div class="input-group">
                                    <input type="text" name="search" class="form-control" 
                                           placeholder="Search transactions, references, or descriptions..." 
                                           value="<?php echo htmlspecialchars($search_term); ?>">
                                    <button class="btn btn-outline-primary" type="submit">
                                        <i class="bi bi-search"></i>
                                    </button>
                                </div>
                            </div>
                            <div class="col-md-12 col-lg-3">
                                <label class="form-label small text-muted">&nbsp;</label>
                                <div class="d-flex gap-2">
                                    <button type="submit" class="btn btn-primary flex-fill">
                                        <i class="bi bi-funnel me-1"></i> Apply Filters
                                    </button>
                                    <a href="financial_data.php" class="btn btn-outline-secondary">
                                        <i class="bi bi-arrow-clockwise"></i>
                                    </a>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Ledger Entries -->
            <div class="account-card">
                <div class="card-header bg-light d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">
                        <i class="bi bi-list-check me-2"></i>
                        <?php if (!empty($clicked_account_name)): ?>
                            Transactions for <?php echo htmlspecialchars($clicked_account_code . ' - ' . $clicked_account_name); ?>
                        <?php else: ?>
                            Ledger Entries
                        <?php endif; ?>
                    </h5>
                    <div>
                        <span class="badge bg-primary rounded-pill"><?php echo number_format($total_records); ?> transactions</span>
                        <?php if ($total_pages > 1): ?>
                            <span class="badge bg-secondary rounded-pill ms-2">Page <?php echo $page; ?> of <?php echo $total_pages; ?></span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($ledger_entries)): ?>
                        <div class="no-transactions-state">
                            <i class="bi bi-journal-x"></i>
                            <h4>No Transactions Found</h4>
                            <p class="text-muted mb-4">There are no transactions matching your criteria.</p>
                            <div class="d-flex justify-content-center gap-2">
                                <a href="financial_data.php" class="btn btn-primary">
                                    <i class="bi bi-arrow-clockwise me-1"></i> Reset Filters
                                </a>
                                <a href="?debug=1" class="btn btn-warning">
                                    <i class="bi bi-bug me-1"></i> Debug
                                </a>
                            </div>
                            <?php if (!empty($account_filter)): ?>
                                <div class="mt-3 alert alert-info">
                                    <p>Account filter is active. Try:</p>
                                    <ul>
                                        <li><a href="financial_data.php">Remove account filter</a></li>
                                        <li>Check if account exists in database</li>
                                        <li>Verify the account has transactions</li>
                                    </ul>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <!-- Transaction Details View -->
                        <div class="p-4">
                            <?php 
                            $running_balance = 0;
                            foreach ($ledger_entries as $entry): 
                                $debit = validateNumeric($entry['debit_amount']);
                                $credit = validateNumeric($entry['credit_amount']);
                                $normal_balance = $entry['normal_balance'] ?? 'debit';
                                
                                // Calculate running balance
                                if ($normal_balance == 'debit') {
                                    $running_balance += ($debit - $credit);
                                } else {
                                    $running_balance += ($credit - $debit);
                                }
                                
                                $ref_type_colors = [
                                    'trade' => 'primary',
                                    'fee' => 'warning',
                                    'payment' => 'danger',
                                    'receipt' => 'success',
                                    'invoice' => 'info',
                                    'journal' => 'secondary',
                                    'transfer' => 'dark'
                                ];
                            ?>
                                <div class="transaction-detail-card">
                                    <div class="transaction-header d-flex justify-content-between align-items-center mb-3">
                                        <div>
                                            <h6 class="mb-0 fw-bold">
                                                <?php echo htmlspecialchars(date('F d, Y', strtotime($entry['transaction_date']))); ?>
                                            </h6>
                                            <div class="transaction-meta mt-1">
                                                <span class="me-3">
                                                    <i class="bi bi-hash"></i>
                                                    <?php echo htmlspecialchars($entry['reference_no']); ?>
                                                </span>
                                                <span class="badge bg-<?php echo $ref_type_colors[$entry['reference_type']] ?? 'secondary'; ?>">
                                                    <?php echo ucfirst(htmlspecialchars($entry['reference_type'])); ?>
                                                </span>
                                            </div>
                                        </div>
                                        <div class="text-end">
                                            <div class="balance-running <?php echo $running_balance >= 0 ? 'text-success' : 'text-danger'; ?>">
                                                <?php echo htmlspecialchars($company['currency']); ?> <?php echo number_format(abs($running_balance), 2); ?>
                                                <small><?php echo $running_balance >= 0 ? 'DR' : 'CR'; ?></small>
                                            </div>
                                            <div class="transaction-meta">Running Balance</div>
                                        </div>
                                    </div>
                                    
                                    <div class="transaction-body">
                                        <div class="row">
                                            <div class="col-md-8">
                                                <h6 class="fw-bold mb-2"><?php echo htmlspecialchars($entry['description']); ?></h6>
                                                
                                                <div class="transaction-meta mb-3">
                                                    <?php if (!empty($entry['entity_name'])): ?>
                                                        <div class="mb-1">
                                                            <i class="bi bi-person"></i>
                                                            <?php echo htmlspecialchars($entry['entity_name']); ?>
                                                            <?php if (!empty($entry['entity_type'])): ?>
                                                                <span class="badge bg-secondary ms-1"><?php echo htmlspecialchars($entry['entity_type']); ?></span>
                                                            <?php endif; ?>
                                                        </div>
                                                    <?php endif; ?>
                                                    
                                                    <?php if (!empty($entry['created_by_name'])): ?>
                                                        <div class="mb-1">
                                                            <i class="bi bi-person-circle"></i>
                                                            Created by: <?php echo htmlspecialchars($entry['created_by_name']); ?>
                                                        </div>
                                                    <?php endif; ?>
                                                    
                                                    <div>
                                                        <i class="bi bi-clock"></i>
                                                        <?php echo htmlspecialchars(date('M d, Y h:i A', strtotime($entry['created_at']))); ?>
                                                    </div>
                                                </div>
                                            </div>
                                            
                                            <div class="col-md-4">
                                                <div class="border rounded p-3 bg-light">
                                                    <div class="row text-center">
                                                        <div class="col-6">
                                                            <div class="text-success mb-1">
                                                                <i class="bi bi-arrow-up-circle fs-4"></i>
                                                            </div>
                                                            <div class="amount-large text-success">
                                                                <?php echo $debit > 0 ? number_format($debit, 2) : '-'; ?>
                                                            </div>
                                                            <div class="transaction-meta">Debit</div>
                                                        </div>
                                                        <div class="col-6">
                                                            <div class="text-danger mb-1">
                                                                <i class="bi bi-arrow-down-circle fs-4"></i>
                                                            </div>
                                                            <div class="amount-large text-danger">
                                                                <?php echo $credit > 0 ? number_format($credit, 2) : '-'; ?>
                                                            </div>
                                                            <div class="transaction-meta">Credit</div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <?php if (!empty($entry['account_code'])): ?>
                                            <div class="mt-3 pt-3 border-top">
                                                <div class="d-flex justify-content-between">
                                                    <div>
                                                        <small class="text-muted">Account:</small>
                                                        <a href="?start_date=<?php echo urlencode($start_date); ?>&amp;end_date=<?php echo urlencode($end_date); ?>&amp;view_account=<?php echo $entry['account_id']; ?>" 
                                                           class="ms-2 fw-bold text-decoration-none">
                                                            <?php echo htmlspecialchars($entry['account_code'] . ' - ' . $entry['account_name']); ?>
                                                        </a>
                                                    </div>
                                                    <div>
                                                        <small class="text-muted">Entry ID:</small>
                                                        <span class="ms-2 fw-bold">#<?php echo htmlspecialchars($entry['id']); ?></span>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <!-- Pagination -->
                        <?php if ($total_pages > 1): ?>
                        <div class="card-footer">
                            <nav aria-label="Page navigation">
                                <ul class="pagination justify-content-center mb-0">
                                    <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                                        <a class="page-link" href="?<?php 
                                            echo http_build_query(array_merge($_GET, ['page' => 1]));
                                        ?>" aria-label="First">
                                            <i class="bi bi-chevron-double-left"></i>
                                        </a>
                                    </li>
                                    <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                                        <a class="page-link" href="?<?php 
                                            echo http_build_query(array_merge($_GET, ['page' => $page - 1]));
                                        ?>">
                                            <i class="bi bi-chevron-left"></i> Previous
                                        </a>
                                    </li>
                                    
                                    <?php 
                                    $start_page = max(1, $page - 2);
                                    $end_page = min($total_pages, $page + 2);
                                    
                                    for ($i = $start_page; $i <= $end_page; $i++): ?>
                                        <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                                            <a class="page-link" href="?<?php 
                                                echo http_build_query(array_merge($_GET, ['page' => $i]));
                                            ?>"><?php echo $i; ?></a>
                                        </li>
                                    <?php endfor; ?>
                                    
                                    <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                                        <a class="page-link" href="?<?php 
                                            echo http_build_query(array_merge($_GET, ['page' => $page + 1]));
                                        ?>">
                                            Next <i class="bi bi-chevron-right"></i>
                                        </a>
                                    </li>
                                    <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                                        <a class="page-link" href="?<?php 
                                            echo http_build_query(array_merge($_GET, ['page' => $total_pages]));
                                        ?>" aria-label="Last">
                                            <i class="bi bi-chevron-double-right"></i>
                                        </a>
                                    </li>
                                </ul>
                            </nav>
                            <div class="text-center text-muted mt-2">
                                Showing <?php echo number_format(($offset + 1)); ?> to <?php echo number_format(min($offset + $per_page, $total_records)); ?> of <?php echo number_format($total_records); ?> transactions
                            </div>
                        </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Date Modal -->
<div class="modal fade" id="dateModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Change Date Range</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form id="dateForm" method="GET">
                    <div class="mb-3">
                        <label class="form-label">Start Date</label>
                        <input type="date" name="start_date" class="form-control" value="<?php echo htmlspecialchars($start_date); ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">End Date</label>
                        <input type="date" name="end_date" class="form-control" value="<?php echo htmlspecialchars($end_date); ?>">
                    </div>
                    <div class="mb-3">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="clearDates">
                            <label class="form-check-label" for="clearDates">
                                Show all transactions (clear date filter)
                            </label>
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="applyDateFilter()">Apply</button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<script>
function showDateModal() {
    const modal = new bootstrap.Modal(document.getElementById('dateModal'));
    modal.show();
}

function applyDateFilter() {
    const form = document.getElementById('dateForm');
    const clearDates = document.getElementById('clearDates').checked;
    
    if (clearDates) {
        window.location.href = 'financial_data';
    } else {
        const startDate = form.start_date.value;
        const endDate = form.end_date.value;
        
        if (!startDate || !endDate) {
            alert('Please select both start and end dates');
            return;
        }
        
        const url = new URL(window.location.href);
        url.searchParams.set('start_date', startDate);
        url.searchParams.set('end_date', endDate);
        url.searchParams.delete('page'); // Reset to page 1
        url.searchParams.delete('view_account'); // Clear account view
        
        window.location.href = url.toString();
    }
}

function exportReport(type) {
    const filters = {
        start_date: document.querySelector('[name="start_date"]').value,
        end_date: document.querySelector('[name="end_date"]').value,
        account: document.querySelector('[name="account"]').value,
        category: document.querySelector('[name="category"]').value,
        reference_type: document.querySelector('[name="reference_type"]').value,
        search: document.querySelector('[name="search"]').value,
        view_account: '<?php echo $clicked_account_id; ?>'
    };
    
    // Build query string
    const params = new URLSearchParams();
    for (const [key, value] of Object.entries(filters)) {
        if (value) params.append(key, value);
    }
    params.append('export', type);
    
    // Open export in new tab
    window.open('financial_data_export.php?' + params.toString(), '_blank');
}

// Simple form validation
document.querySelector('[name="start_date"]').addEventListener('change', function() {
    const toDateInput = document.querySelector('[name="end_date"]');
    if (this.value && toDateInput.value) {
        const fromDate = new Date(this.value);
        const toDate = new Date(toDateInput.value);
        if (fromDate > toDate) {
            alert('Start date cannot be after end date');
            this.value = toDateInput.value;
        }
    }
});

document.querySelector('[name="end_date"]').addEventListener('change', function() {
    const fromDateInput = document.querySelector('[name="start_date"]');
    if (this.value && fromDateInput.value) {
        const fromDate = new Date(fromDateInput.value);
        const toDate = new Date(this.value);
        if (toDate < fromDate) {
            alert('End date cannot be before start date');
            this.value = fromDateInput.value;
        }
    }
});

// Initialize page
document.addEventListener('DOMContentLoaded', function() {
    console.log('General Ledger loaded');
    console.log('Total records: <?php echo $total_records; ?>');
    console.log('Ledger entries: <?php echo count($ledger_entries); ?>');
});
</script>

<?php include '../includes/footer.php'; ?>