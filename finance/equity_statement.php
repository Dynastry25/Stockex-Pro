<?php
require_once '../config/config.php';
require_once '../auth/auth_middleware.php';

// Security headers
header("X-Frame-Options: DENY");
header("X-Content-Type-Options: nosniff");
header("X-XSS-Protection: 1; mode=block");
header("Referrer-Policy: strict-origin-when-cross-origin");
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; font-src 'self' https://cdn.jsdelivr.net");

require_finance_officer();

$db = getDBConnection();

// Set connection collation to match your database
$db->exec("SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci");

// Input validation and sanitization functions
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

// Validate and sanitize filter parameters
$start_date = $_GET['start_date'] ?? date('Y-01-01');
$end_date = $_GET['end_date'] ?? date('Y-m-t');
$account_filter = $_GET['account'] ?? '';
$search_term = $_GET['search'] ?? '';
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;

// Validate date format and range
if (!validateDate($start_date) || !validateDate($end_date)) {
    $start_date = date('Y-01-01');
    $end_date = date('Y-m-t');
}

// Ensure end date is not before start date
if (strtotime($end_date) < strtotime($start_date)) {
    $end_date = $start_date;
}

// Sanitize filter inputs
$account_filter = sanitizeInput($account_filter);
$search_term = sanitizeInput($search_term);

// Pagination security
$per_page = 10;
$offset = ($page - 1) * $per_page;
if ($offset < 0) $offset = 0;

// Get company information from database with prepared statement
$company_stmt = $db->prepare("
    SELECT * FROM companies 
    WHERE status = 'active' 
    ORDER BY id ASC 
    LIMIT 1
");
$company_stmt->execute();
$company = $company_stmt->fetch(PDO::FETCH_ASSOC);

// If no company exists, create default values
if (!$company) {
    $company = [
        'company_name' => 'Neovam Technologies LTD',
        'registration_number' => 'Not Registered',
        'address' => 'Address Not Set',
        'city' => 'Dar es Salaam',
        'country' => 'Tanzania',
        'phone' => 'Not Available',
        'email' => 'Not Available',
        'website' => 'Not Available',
        'currency' => 'TZS'
    ];
}

// Sanitize company data
foreach ($company as $key => $value) {
    $company[$key] = sanitizeInput($value);
}

// Build base query for equity transactions using chart_of_accounts and general_ledger
$query = "
    SELECT 
        gl.account_code,
        MAX(gl.account_name) as account_name,
        gl.transaction_date,
        gl.description,
        gl.reference_no,
        SUM(gl.debit_amount) as total_debit,
        SUM(gl.credit_amount) as total_credit,
        SUM(gl.credit_amount - gl.debit_amount) as net_amount,
        gl.created_by_username
    FROM general_ledger gl
    WHERE gl.status = 'active'
    AND gl.transaction_date BETWEEN ? AND ?
    AND gl.account_code LIKE '3%'  -- Equity accounts start with 3
    GROUP BY gl.account_code, DATE(gl.transaction_date), gl.description, gl.reference_no, gl.created_by_username
    HAVING net_amount != 0
";

$params = [$start_date, $end_date];

// Apply filters with parameter binding
if (!empty($account_filter)) {
    $query .= " AND gl.account_code = ?";
    $params[] = $account_filter;
}

if (!empty($search_term)) {
    $query .= " AND (gl.description LIKE ? OR gl.reference_no LIKE ?)";
    $search_like = "%$search_term%";
    $params[] = $search_like;
    $params[] = $search_like;
}

// Count total records for pagination
try {
    $count_query = "SELECT COUNT(DISTINCT CONCAT(gl.account_code, '_', DATE(gl.transaction_date), '_', gl.reference_no)) as total 
                   FROM general_ledger gl
                   WHERE gl.status = 'active'
                   AND gl.transaction_date BETWEEN ? AND ?
                   AND gl.account_code LIKE '3%'";
    
    $count_params = [$start_date, $end_date];
    
    if (!empty($account_filter)) {
        $count_query .= " AND gl.account_code = ?";
        $count_params[] = $account_filter;
    }
    
    if (!empty($search_term)) {
        $count_query .= " AND (gl.description LIKE ? OR gl.reference_no LIKE ?)";
        $search_like = "%$search_term%";
        $count_params[] = $search_like;
        $count_params[] = $search_like;
    }
    
    $count_stmt = $db->prepare($count_query);
    $count_stmt->execute($count_params);
    $total_records = $count_stmt->fetch()['total'];
    $total_pages = ceil($total_records / $per_page);
    
    // Ensure page is within valid range
    if ($page > $total_pages && $total_pages > 0) {
        $page = $total_pages;
    }
} catch (Exception $e) {
    error_log("Count query error: " . $e->getMessage());
    $total_records = 0;
    $total_pages = 1;
    $page = 1;
}

// Add sorting and pagination to main query
$query .= " ORDER BY gl.transaction_date DESC, gl.account_code DESC LIMIT ? OFFSET ?";
$params[] = $per_page;
$params[] = $offset;

// Execute main query
try {
    $stmt = $db->prepare($query);
    $stmt->execute($params);
    $equity_transactions = $stmt->fetchAll();
} catch (Exception $e) {
    error_log("Main query error: " . $e->getMessage());
    $equity_transactions = [];
}

// Get beginning equity balance (before period start)
$beginning_equity_stmt = $db->prepare("
    SELECT COALESCE(SUM(credit_amount - debit_amount), 0) as balance
    FROM general_ledger 
    WHERE status = 'active'
    AND transaction_date < ?
    AND account_code LIKE '3%'  -- Equity accounts
");
$beginning_equity_stmt->execute([$start_date]);
$beginning_equity_result = $beginning_equity_stmt->fetch(PDO::FETCH_ASSOC);
$beginning_equity = validateNumeric($beginning_equity_result['balance'] ?? 0);

// Get net income from income accounts (4xxxx)
$net_income_stmt = $db->prepare("
    SELECT COALESCE(SUM(credit_amount - debit_amount), 0) as net_income
    FROM general_ledger 
    WHERE status = 'active'
    AND transaction_date BETWEEN ? AND ?
    AND account_code LIKE '4%'  -- Income accounts
");
$net_income_stmt->execute([$start_date, $end_date]);
$net_income_result = $net_income_stmt->fetch(PDO::FETCH_ASSOC);
$net_income = validateNumeric($net_income_result['net_income'] ?? 0);

// Get distinct equity accounts for filter dropdown
$accounts_stmt = $db->prepare("
    SELECT DISTINCT gl.account_code, MAX(gl.account_name) as account_name
    FROM general_ledger gl
    WHERE gl.status = 'active'
    AND gl.account_code LIKE '3%'
    GROUP BY gl.account_code
    ORDER BY account_name
");
$accounts_stmt->execute();
$accounts = $accounts_stmt->fetchAll();

// Calculate totals by equity component with validation
$total_owners_capital = 0;
$total_retained_earnings = 0;
$total_common_stock = 0;
$total_preferred_stock = 0;
$total_additional_paid_in_capital = 0;
$total_treasury_stock = 0;

foreach ($equity_transactions as $transaction) {
    $amount = validateNumeric($transaction['net_amount']);
    $account_name = strtolower($transaction['account_name'] ?? '');
    
    // Map account names to equity components
    if (strpos($account_name, 'owner') !== false || strpos($account_name, 'capital') !== false) {
        $total_owners_capital += $amount;
    } elseif (strpos($account_name, 'retained') !== false) {
        $total_retained_earnings += $amount;
    } elseif (strpos($account_name, 'common') !== false) {
        $total_common_stock += $amount;
    } elseif (strpos($account_name, 'preferred') !== false) {
        $total_preferred_stock += $amount;
    } elseif (strpos($account_name, 'additional') !== false || strpos($account_name, 'paid-in') !== false) {
        $total_additional_paid_in_capital += $amount;
    } elseif (strpos($account_name, 'treasury') !== false) {
        $total_treasury_stock += $amount;
    }
}

// Get brokerage commission income (4111 - Equity Trading Commission)
$brokerage_income_stmt = $db->prepare("
    SELECT COALESCE(SUM(credit_amount - debit_amount), 0) as income
    FROM general_ledger 
    WHERE status = 'active'
    AND transaction_date BETWEEN ? AND ?
    AND account_code IN ('4111', '4112', '4113')  -- Brokerage income accounts
");
$brokerage_income_stmt->execute([$start_date, $end_date]);
$brokerage_income_result = $brokerage_income_stmt->fetch(PDO::FETCH_ASSOC);
$total_brokerage_income = validateNumeric($brokerage_income_result['income'] ?? 0);

// Calculate total equity changes for the period
$total_equity_changes = $total_owners_capital + $total_retained_earnings + $total_common_stock + 
                       $total_preferred_stock + $total_additional_paid_in_capital + $total_treasury_stock + $net_income;

$ending_equity = $beginning_equity + $total_equity_changes;

// CSRF token generation and validation for forms
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

$page_title = 'Statement of Changes in Equity';
include '../includes/header.php';
?>

<div class="container-fluid py-4">
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-header bg-info text-white d-flex justify-content-between align-items-center">
                    <div>
                        <h4 class="mb-0"><i class="bi bi-pie-chart me-2"></i>Statement of Changes in Equity</h4>
                        <p class="mb-0">For Period: <?php echo htmlspecialchars(date('F d, Y', strtotime($start_date)) . ' - ' . date('F d, Y', strtotime($end_date))); ?></p>
                    </div>
                    <div class="btn-group">
                        <button class="btn btn-light btn-sm" data-bs-toggle="modal" data-bs-target="#periodModal">
                            <i class="bi bi-calendar me-1"></i>Period
                        </button>
                        <a href="?export=print&amp;start_date=<?php echo urlencode($start_date); ?>&amp;end_date=<?php echo urlencode($end_date); ?>" class="btn btn-light btn-sm" onclick="window.print()">
                            <i class="bi bi-printer me-1"></i>Print
                        </a>
                    </div>
                </div>
                <div class="card-body">
                    <!-- Company Header from Database -->
                    <div class="text-center mb-4">
                        <h3><?php echo htmlspecialchars($company['company_name']); ?></h3>
                        <h5>STATEMENT OF CHANGES IN EQUITY</h5>
                        <p class="mb-0">For the Period Ended <?php echo htmlspecialchars(date('F d, Y', strtotime($end_date))); ?></p>
                        <p class="text-muted mb-1">
                            Currency: <?php echo htmlspecialchars($company['currency']); ?>
                        </p>

                    </div>

                    <div class="row justify-content-center">
                        <div class="col-lg-12">
                            <!-- Equity Summary -->
                            <div class="card border-0 mb-4">
                                <div class="card-header bg-light">
                                    <h6 class="mb-0 fw-bold">EQUITY MOVEMENT SUMMARY</h6>
                                </div>
                                <div class="card-body p-0">
                                    <div class="d-flex justify-content-between py-3 px-3 border-bottom">
                                        <strong>Beginning Equity Balance</strong>
                                        <strong><?php echo htmlspecialchars($company['currency']); ?> <?php echo number_format($beginning_equity, 2); ?></strong>
                                    </div>
                                    
                                    <!-- Equity Components -->
                                    <div class="p-3 border-bottom">
                                        <h6 class="fw-bold text-primary">Equity Components</h6>
                                        <?php if ($total_owners_capital != 0): ?>
                                            <div class="d-flex justify-content-between py-1">
                                                <span>Owner's Capital</span>
                                                <span class="<?php echo $total_owners_capital >= 0 ? 'text-success' : 'text-danger'; ?>">
                                                    <?php echo $total_owners_capital >= 0 ? '+' : '-'; ?> <?php echo htmlspecialchars($company['currency']); ?> <?php echo number_format(abs($total_owners_capital), 2); ?>
                                                </span>
                                            </div>
                                        <?php endif; ?>
                                        <?php if ($total_common_stock != 0): ?>
                                            <div class="d-flex justify-content-between py-1">
                                                <span>Common Stock</span>
                                                <span class="<?php echo $total_common_stock >= 0 ? 'text-success' : 'text-danger'; ?>">
                                                    <?php echo $total_common_stock >= 0 ? '+' : '-'; ?> <?php echo htmlspecialchars($company['currency']); ?> <?php echo number_format(abs($total_common_stock), 2); ?>
                                                </span>
                                            </div>
                                        <?php endif; ?>
                                        <?php if ($total_preferred_stock != 0): ?>
                                            <div class="d-flex justify-content-between py-1">
                                                <span>Preferred Stock</span>
                                                <span class="<?php echo $total_preferred_stock >= 0 ? 'text-success' : 'text-danger'; ?>">
                                                    <?php echo $total_preferred_stock >= 0 ? '+' : '-'; ?> <?php echo htmlspecialchars($company['currency']); ?> <?php echo number_format(abs($total_preferred_stock), 2); ?>
                                                </span>
                                            </div>
                                        <?php endif; ?>
                                        <?php if ($total_additional_paid_in_capital != 0): ?>
                                            <div class="d-flex justify-content-between py-1">
                                                <span>Additional Paid-in Capital</span>
                                                <span class="<?php echo $total_additional_paid_in_capital >= 0 ? 'text-success' : 'text-danger'; ?>">
                                                    <?php echo $total_additional_paid_in_capital >= 0 ? '+' : '-'; ?> <?php echo htmlspecialchars($company['currency']); ?> <?php echo number_format(abs($total_additional_paid_in_capital), 2); ?>
                                                </span>
                                            </div>
                                        <?php endif; ?>
                                        <?php if ($total_treasury_stock != 0): ?>
                                            <div class="d-flex justify-content-between py-1">
                                                <span>Treasury Stock</span>
                                                <span class="<?php echo $total_treasury_stock >= 0 ? 'text-success' : 'text-danger'; ?>">
                                                    <?php echo $total_treasury_stock >= 0 ? '+' : '-'; ?> <?php echo htmlspecialchars($company['currency']); ?> <?php echo number_format(abs($total_treasury_stock), 2); ?>
                                                </span>
                                            </div>
                                        <?php endif; ?>
                                    </div>

                                    <!-- Income and Retained Earnings -->
                                    <div class="p-3 border-bottom">
                                        <h6 class="fw-bold text-success">Income & Retained Earnings</h6>
                                        <?php if ($net_income > 0): ?>
                                            <div class="d-flex justify-content-between py-1">
                                                <span>Net Income from Operations</span>
                                                <span class="text-success">+ <?php echo htmlspecialchars($company['currency']); ?> <?php echo number_format($net_income, 2); ?></span>
                                            </div>
                                        <?php endif; ?>
                                        <?php if ($total_retained_earnings != 0): ?>
                                            <div class="d-flex justify-content-between py-1">
                                                <span>Retained Earnings</span>
                                                <span class="<?php echo $total_retained_earnings >= 0 ? 'text-success' : 'text-danger'; ?>">
                                                    <?php echo $total_retained_earnings >= 0 ? '+' : '-'; ?> <?php echo htmlspecialchars($company['currency']); ?> <?php echo number_format(abs($total_retained_earnings), 2); ?>
                                                </span>
                                            </div>
                                        <?php endif; ?>
                                        <?php if ($total_brokerage_income > 0): ?>
                                            <div class="d-flex justify-content-between py-1">
                                                <span>Brokerage Commission Income</span>
                                                <span class="text-success">+ <?php echo htmlspecialchars($company['currency']); ?> <?php echo number_format($total_brokerage_income, 2); ?></span>
                                            </div>
                                        <?php endif; ?>
                                    </div>

                                    <!-- Net Change and Ending Balance -->
                                    <div class="p-3">
                                        <div class="d-flex justify-content-between py-2 border-bottom">
                                            <strong>Total Equity Changes</strong>
                                            <strong class="<?php echo $total_equity_changes >= 0 ? 'text-success' : 'text-danger'; ?>">
                                                <?php echo $total_equity_changes >= 0 ? '+' : '-'; ?> <?php echo htmlspecialchars($company['currency']); ?> <?php echo number_format(abs($total_equity_changes), 2); ?>
                                            </strong>
                                        </div>
                                        <div class="d-flex justify-content-between py-3 bg-info text-white">
                                            <h6 class="mb-0 fw-bold">ENDING EQUITY BALANCE</h6>
                                            <h6 class="mb-0 fw-bold"><?php echo htmlspecialchars($company['currency']); ?> <?php echo number_format($ending_equity, 2); ?></h6>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Filters Section -->
                            <div class="card border-0 mb-4">
                                <div class="card-header bg-light">
                                    <h6 class="mb-0 fw-bold">FILTERS</h6>
                                </div>
                                <div class="card-body">
                                    <form method="GET" id="filterForm">
                                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                        <input type="hidden" name="start_date" value="<?php echo htmlspecialchars($start_date); ?>">
                                        <input type="hidden" name="end_date" value="<?php echo htmlspecialchars($end_date); ?>">
                                        <div class="row g-3">
                                            <div class="col-md-4">
                                                <label class="form-label">Account</label>
                                                <select name="account" class="form-select">
                                                    <option value="">All Equity Accounts</option>
                                                    <?php foreach ($accounts as $account): ?>
                                                        <option value="<?php echo htmlspecialchars($account['account_code']); ?>" 
                                                            <?php echo $account_filter == $account['account_code'] ? 'selected' : ''; ?>>
                                                            <?php echo htmlspecialchars($account['account_name']); ?> (<?php echo htmlspecialchars($account['account_code']); ?>)
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label">Search</label>
                                                <div class="input-group">
                                                    <input type="text" name="search" class="form-control" placeholder="Search description or reference..." 
                                                           value="<?php echo htmlspecialchars($search_term); ?>" maxlength="100">
                                                    <button class="btn btn-outline-primary" type="submit">
                                                        <i class="bi bi-search"></i>
                                                    </button>
                                                </div>
                                            </div>
                                            <div class="col-md-2">
                                                <label class="form-label">&nbsp;</label>
                                                <div>
                                                    <button type="submit" class="btn btn-primary me-1">Apply</button>
                                                    <a href="?start_date=<?php echo urlencode($start_date); ?>&amp;end_date=<?php echo urlencode($end_date); ?>" class="btn btn-outline-secondary">
                                                        <i class="bi bi-arrow-clockwise"></i> Reset
                                                    </a>
                                                </div>
                                            </div>
                                        </div>
                                    </form>
                                </div>
                            </div>

                            <!-- Data Source Notice -->
                            <div class="alert alert-info mb-4">
                                <i class="bi bi-database me-2"></i>
                                <strong>Data Source:</strong> This statement is generated directly from General Ledger entries.
                                <small class="d-block mt-1">Generated on: <?php echo date('F d, Y \a\t H:i:s'); ?></small>
                            </div>

                            <!-- Detailed Transactions -->
                            <div class="card border-0">
                                <div class="card-header bg-light d-flex justify-content-between align-items-center">
                                    <h6 class="mb-0 fw-bold">EQUITY TRANSACTIONS</h6>
                                    <span class="badge bg-primary"><?php echo number_format($total_records); ?> records</span>
                                </div>
                                <div class="card-body p-0">
                                    <?php if (empty($equity_transactions)): ?>
                                        <div class="text-muted py-4 text-center">
                                            <i class="bi bi-inbox display-4"></i>
                                            <p class="mt-2">No equity transactions found for the selected filters</p>
                                        </div>
                                    <?php else: ?>
                                        <div class="table-responsive">
                                            <table class="table table-sm table-hover mb-0">
                                                <thead class="table-light">
                                                    <tr>
                                                        <th>Date</th>
                                                        <th>Account</th>
                                                        <th>Description</th>
                                                        <th>Reference</th>
                                                        <th>Debit</th>
                                                        <th>Credit</th>
                                                        <th>Net Amount</th>
                                                        <th>Recorded By</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($equity_transactions as $transaction): ?>
                                                        <tr>
                                                            <td><?php echo htmlspecialchars(date('M d, Y', strtotime($transaction['transaction_date']))); ?></td>
                                                            <td>
                                                                <span class="badge bg-secondary">
                                                                    <?php echo htmlspecialchars($transaction['account_name']); ?>
                                                                    <small>(<?php echo htmlspecialchars($transaction['account_code']); ?>)</small>
                                                                </span>
                                                            </td>
                                                            <td><?php echo htmlspecialchars($transaction['description'] ?? 'No description'); ?></td>
                                                            <td><code><?php echo htmlspecialchars($transaction['reference_no'] ?? 'N/A'); ?></code></td>
                                                            <td class="text-danger"><?php echo htmlspecialchars($company['currency']); ?> <?php echo number_format($transaction['total_debit'] ?? 0, 2); ?></td>
                                                            <td class="text-success"><?php echo htmlspecialchars($company['currency']); ?> <?php echo number_format($transaction['total_credit'] ?? 0, 2); ?></td>
                                                            <td class="fw-bold <?php echo $transaction['net_amount'] >= 0 ? 'text-success' : 'text-danger'; ?>">
                                                                <?php echo htmlspecialchars($company['currency']); ?> <?php echo number_format($transaction['net_amount'], 2); ?>
                                                            </td>
                                                            <td><?php echo htmlspecialchars($transaction['created_by_username'] ?? 'System'); ?></td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>

                                        <!-- Pagination -->
                                        <?php if ($total_pages > 1): ?>
                                        <div class="card-footer">
                                            <nav aria-label="Transaction pagination">
                                                <ul class="pagination justify-content-center mb-0">
                                                    <!-- Previous Page -->
                                                    <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                                                        <a class="page-link" href="?<?php 
                                                            echo http_build_query(array_merge($_GET, ['page' => $page - 1]));
                                                        ?>">Previous</a>
                                                    </li>
                                                    
                                                    <!-- Page Numbers -->
                                                    <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                                                        <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                                                            <a class="page-link" href="?<?php 
                                                                echo http_build_query(array_merge($_GET, ['page' => $i]));
                                                            ?>"><?php echo $i; ?></a>
                                                        </li>
                                                    <?php endfor; ?>
                                                    
                                                    <!-- Next Page -->
                                                    <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                                                        <a class="page-link" href="?<?php 
                                                            echo http_build_query(array_merge($_GET, ['page' => $page + 1]));
                                                        ?>">Next</a>
                                                    </li>
                                                </ul>
                                            </nav>
                                            <div class="text-center text-muted mt-2">
                                                Showing <?php echo number_format(($offset + 1)); ?> to <?php echo number_format(min($offset + $per_page, $total_records)); ?> of <?php echo number_format($total_records); ?> entries
                                            </div>
                                        </div>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <!-- Equity Analysis -->
                            <div class="row mt-4">
                                <div class="col-md-4">
                                    <div class="card text-center">
                                        <div class="card-body">
                                            <h6 class="text-muted">Equity Growth</h6>
                                            <h4 class="text-<?php echo $total_equity_changes >= 0 ? 'success' : 'danger'; ?>">
                                                <?php echo $beginning_equity > 0 ? number_format(($total_equity_changes/$beginning_equity)*100, 1) : '0.0'; ?>%
                                            </h4>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="card text-center">
                                        <div class="card-body">
                                            <h6 class="text-muted">Return on Equity</h6>
                                            <h4 class="text-<?php echo ($net_income/$ending_equity*100) > 0 ? 'success' : 'danger'; ?>">
                                                <?php echo $ending_equity > 0 ? number_format(($net_income/$ending_equity)*100, 1) : '0.0'; ?>%
                                            </h4>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="card text-center">
                                        <div class="card-body">
                                            <h6 class="text-muted">Income Contribution</h6>
                                            <h4 class="text-success">
                                                <?php echo $total_equity_changes > 0 ? number_format(($net_income/$total_equity_changes)*100, 1) : '0.0'; ?>%
                                            </h4>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Period Selection Modal -->
<div class="modal fade" id="periodModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Select Reporting Period</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form method="GET" id="periodForm">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    <div class="mb-3">
                        <label class="form-label">Start Date</label>
                        <input type="date" class="form-control" name="start_date" value="<?php echo htmlspecialchars($start_date); ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">End Date</label>
                        <input type="date" class="form-control" name="end_date" value="<?php echo htmlspecialchars($end_date); ?>" required>
                    </div>
                    <!-- Preserve existing filters -->
                    <input type="hidden" name="account" value="<?php echo htmlspecialchars($account_filter); ?>">
                    <input type="hidden" name="search" value="<?php echo htmlspecialchars($search_term); ?>">
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" form="periodForm" class="btn btn-primary">Apply Period</button>
            </div>
        </div>
    </div>
</div>

<script>
// Client-side validation
document.addEventListener('DOMContentLoaded', function() {
    const periodForm = document.getElementById('periodForm');
    const filterForm = document.getElementById('filterForm');
    
    if (periodForm) {
        periodForm.addEventListener('submit', function(e) {
            const startDate = new Date(this.start_date.value);
            const endDate = new Date(this.end_date.value);
            
            // Validate date range
            if (startDate > endDate) {
                e.preventDefault();
                alert('Error: Start date cannot be after end date.');
                return false;
            }
        });
    }
    
    // Input length validation for search
    if (filterForm) {
        const searchInput = filterForm.querySelector('input[name="search"]');
        if (searchInput) {
            searchInput.addEventListener('input', function() {
                if (this.value.length > 100) {
                    this.value = this.value.substring(0, 100);
                }
            });
        }
    }
    
    // Prevent form resubmission on page refresh
    if (window.history.replaceState) {
        window.history.replaceState(null, null, window.location.href);
    }
});
</script>

<?php include '../includes/footer.php'; ?>