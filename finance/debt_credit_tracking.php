<?php
require_once '../config/config.php';
require_once '../auth/auth_middleware.php';
require_once '../tcpdf/tcpdf.php';

// Security headers
header("X-Frame-Options: DENY");
header("X-Content-Type-Options: nosniff");
header("X-XSS-Protection: 1; mode=block");
header("Referrer-Policy: strict-origin-when-cross-origin");
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; font-src 'self' https://cdn.jsdelivr.net");

require_finance_officer();

// CSRF Protection
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$db = getDBConnection();
$success_message = '';
$error_message = '';

// Pagination configuration
$records_per_page = 20; // Number of clients per page
$current_page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($current_page < 1) $current_page = 1;
$offset = ($current_page - 1) * $records_per_page;

// Helper functions
function validateDate($date, $format = 'Y-m-d') {
    $d = DateTime::createFromFormat($format, $date);
    return $d && $d->format($format) === $date;
}

function sanitizeInput($data) {
    if (is_array($data)) {
        return array_map('sanitizeInput', $data);
    }
    return htmlspecialchars(trim($data), ENT_QUOTES, 'UTF-8');
}

function formatCurrency($amount, $currency = 'Tsh') {
    $symbols = [
        'Tsh' => 'TZS ',
        'USD' => '$',
        'Ksh' => 'KSh ',
        'UGsh' => 'UGX '
    ];
    $symbol = $symbols[$currency] ?? 'TZS ';
    return $symbol . number_format($amount, 2);
}

function getAgingCategory($days) {
    if ($days <= 30) return 'Current';
    if ($days <= 60) return '31-60 Days';
    if ($days <= 90) return '61-90 Days';
    return '90+ Days';
}

// Function to calculate client balances with aging - UPDATED FOR PAGINATION
function getClientBalances($db, $client_id = null, $as_of_date = null, $start_date = null, $end_date = null, $limit = null, $offset = 0) {
    $as_of_date = $as_of_date ?: date('Y-m-d');
    $clients_data = [];
    
    // Get all active clients or specific client with pagination
    $client_query = "SELECT id, client_name, cds_account, phone, email, client_type FROM clients WHERE is_active = 1 AND status = 'active'";
    $count_query = "SELECT COUNT(*) as total FROM clients WHERE is_active = 1 AND status = 'active'";
    $params = [];
    $count_params = [];
    
    if ($client_id) {
        $client_query .= " AND id = ?";
        $count_query .= " AND id = ?";
        $params[] = $client_id;
        $count_params[] = $client_id;
    }
    
    $client_query .= " ORDER BY client_name";
    
    // Add pagination if limit is specified
    if ($limit !== null) {
        $client_query .= " LIMIT ? OFFSET ?";
        $params[] = $limit;
        $params[] = $offset;
    }
    
    // Get total count for pagination
    $count_stmt = $db->prepare($count_query);
    $count_stmt->execute($count_params);
    $total_count = $count_stmt->fetchColumn();
    
    $stmt = $db->prepare($client_query);
    $stmt->execute($params);
    $clients = $stmt->fetchAll();
    
    foreach ($clients as $client) {
        // Get receipts (money received FROM client)
        $receipts_query = "SELECT receipt_date, amount, currency, narration, receipt_no 
                          FROM receipts 
                          WHERE account_of = 'C' AND name_id = ? AND record_in_financial = 'yes'";
        $receipts_params = [$client['id']];
        
        if ($start_date && $end_date) {
            $receipts_query .= " AND receipt_date BETWEEN ? AND ?";
            $receipts_params[] = $start_date;
            $receipts_params[] = $end_date;
        } elseif ($as_of_date) {
            $receipts_query .= " AND receipt_date <= ?";
            $receipts_params[] = $as_of_date;
        }
        
        $receipts_stmt = $db->prepare($receipts_query);
        $receipts_stmt->execute($receipts_params);
        $receipts = $receipts_stmt->fetchAll();
        
        // Get payments (money paid TO client)
        $payments_query = "SELECT payment_date, amount, currency, narration, payment_no 
                          FROM payments 
                          WHERE paid_to = 'C' AND name_id = ? AND record_in_financial = 'yes'";
        $payments_params = [$client['id']];
        
        if ($start_date && $end_date) {
            $payments_query .= " AND payment_date BETWEEN ? AND ?";
            $payments_params[] = $start_date;
            $payments_params[] = $end_date;
        } elseif ($as_of_date) {
            $payments_query .= " AND payment_date <= ?";
            $payments_params[] = $as_of_date;
        }
        
        $payments_stmt = $db->prepare($payments_query);
        $payments_stmt->execute($payments_params);
        $payments = $payments_stmt->fetchAll();
        
        // Calculate totals
        $total_receipts = array_sum(array_column($receipts, 'amount'));
        $total_payments = array_sum(array_column($payments, 'amount'));
        $net_balance = $total_payments - $total_receipts; // Positive = we owe client, Negative = client owes us
        
        // Combine all transactions
        $transactions = [];
        foreach ($receipts as $receipt) {
            $transactions[] = [
                'date' => $receipt['receipt_date'],
                'type' => 'receipt',
                'description' => $receipt['narration'] ?: 'Money received from client',
                'reference' => $receipt['receipt_no'],
                'amount' => -$receipt['amount'], // Negative because client pays us
                'currency' => $receipt['currency'],
                'running_balance' => 0 // Will be calculated later
            ];
        }
        
        foreach ($payments as $payment) {
            $transactions[] = [
                'date' => $payment['payment_date'],
                'type' => 'payment',
                'description' => $payment['narration'] ?: 'Money paid to client',
                'reference' => $payment['payment_no'],
                'amount' => $payment['amount'], // Positive because we pay client
                'currency' => $payment['currency'],
                'running_balance' => 0
            ];
        }
        
        // Sort transactions by date
        usort($transactions, function($a, $b) {
            return strtotime($a['date']) - strtotime($b['date']);
        });
        
        // Calculate running balance
        $running_balance = 0;
        foreach ($transactions as &$transaction) {
            $running_balance += $transaction['amount'];
            $transaction['running_balance'] = $running_balance;
        }
        
        // Calculate aging buckets
        $aging_buckets = [
            'Current' => 0,
            '31-60 Days' => 0,
            '61-90 Days' => 0,
            '90+ Days' => 0
        ];
        
        $today = new DateTime($as_of_date);
        foreach ($transactions as $transaction) {
            if ($transaction['amount'] < 0) { // Only negative amounts (client owes us) are aged
                $transaction_date = new DateTime($transaction['date']);
                $days_diff = $today->diff($transaction_date)->days;
                $aging_category = getAgingCategory($days_diff);
                $aging_buckets[$aging_category] += abs($transaction['amount']);
            }
        }
        
        // Total overdue (excluding current)
        $total_overdue = $aging_buckets['31-60 Days'] + $aging_buckets['61-90 Days'] + $aging_buckets['90+ Days'];
        
        $clients_data[] = [
            'client_info' => $client,
            'transactions' => $transactions,
            'totals' => [
                'receipts' => $total_receipts,
                'payments' => $total_payments,
                'net_balance' => $net_balance,
                'balance_status' => $net_balance > 0 ? 'Credit Balance (We Owe Client)' : 
                                  ($net_balance < 0 ? 'Debit Balance (Client Owes Us)' : 'Settled')
            ],
            'aging' => [
                'buckets' => $aging_buckets,
                'total_overdue' => $total_overdue,
                'current_ratio' => $total_receipts > 0 ? ($aging_buckets['Current'] / $total_receipts * 100) : 0,
                'overdue_ratio' => $total_receipts > 0 ? ($total_overdue / $total_receipts * 100) : 0
            ]
        ];
    }
    
    return [
        'data' => $clients_data,
        'total_count' => $total_count
    ];
}

// Handle CSV export - keep existing export logic
if (isset($_GET['export']) && $_GET['export'] == 'csv') {
    // ... existing CSV export code ...
}

// Handle PDF export - keep existing export logic
if (isset($_GET['export']) && $_GET['export'] == 'pdf') {
    // ... existing PDF export code ...
}

// Get filter parameters
$client_id = isset($_GET['client_id']) ? (int)$_GET['client_id'] : null;
$as_of_date = isset($_GET['as_of_date']) ? $_GET['as_of_date'] : date('Y-m-d');
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : null;
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : null;
$balance_status = isset($_GET['balance_status']) ? $_GET['balance_status'] : 'all';
$aging_filter = isset($_GET['aging_filter']) ? $_GET['aging_filter'] : 'all';

// Validate dates
if (!validateDate($as_of_date)) $as_of_date = date('Y-m-d');
if ($start_date && !validateDate($start_date)) $start_date = null;
if ($end_date && !validateDate($end_date)) $end_date = null;

// Fetch all clients for dropdown
try {
    $clients_stmt = $db->query("SELECT id, client_name, cds_account FROM clients WHERE is_active = 1 AND status = 'active' ORDER BY client_name");
    $all_clients = $clients_stmt->fetchAll();
} catch (Exception $e) {
    $all_clients = [];
    $error_message = "Error fetching clients: " . $e->getMessage();
}

// Get data based on filters WITH PAGINATION
$clients_result = getClientBalances($db, $client_id, $as_of_date, $start_date, $end_date, $records_per_page, $offset);
$clients_data = $clients_result['data'];
$total_clients_count = $clients_result['total_count'];

// Apply additional filters
if ($balance_status !== 'all') {
    $clients_data = array_filter($clients_data, function($data) use ($balance_status) {
        $net_balance = $data['totals']['net_balance'];
        if ($balance_status === 'credit' && $net_balance > 0) return true;
        if ($balance_status === 'debit' && $net_balance < 0) return true;
        if ($balance_status === 'settled' && $net_balance == 0) return true;
        return false;
    });
}

if ($aging_filter !== 'all') {
    $clients_data = array_filter($clients_data, function($data) use ($aging_filter) {
        $overdue_ratio = $data['aging']['overdue_ratio'];
        if ($aging_filter === 'overdue_high' && $overdue_ratio > 30) return true;
        if ($aging_filter === 'overdue_medium' && $overdue_ratio > 10 && $overdue_ratio <= 30) return true;
        if ($aging_filter === 'overdue_low' && $overdue_ratio > 0 && $overdue_ratio <= 10) return true;
        if ($aging_filter === 'current' && $overdue_ratio == 0) return true;
        return false;
    });
}

// Calculate total pages for pagination
$total_pages = ceil($total_clients_count / $records_per_page);

// Calculate summary statistics
$summary_stats = [
    'total_clients' => count($clients_data),
    'total_receipts' => 0,
    'total_payments' => 0,
    'total_net_balance' => 0,
    'total_current' => 0,
    'total_overdue' => 0,
    'clients_in_credit' => 0,
    'clients_in_debit' => 0,
    'clients_settled' => 0
];

foreach ($clients_data as $data) {
    $summary_stats['total_receipts'] += $data['totals']['receipts'];
    $summary_stats['total_payments'] += $data['totals']['payments'];
    $summary_stats['total_net_balance'] += $data['totals']['net_balance'];
    $summary_stats['total_current'] += $data['aging']['buckets']['Current'];
    $summary_stats['total_overdue'] += $data['aging']['total_overdue'];
    
    if ($data['totals']['net_balance'] > 0) $summary_stats['clients_in_credit']++;
    elseif ($data['totals']['net_balance'] < 0) $summary_stats['clients_in_debit']++;
    else $summary_stats['clients_settled']++;
}

$page_title = 'Debt & Credit Tracking - Aged Balances';
include '../includes/header.php';
?>

<style>
    .stats-card {
        border-radius: 10px;
        transition: transform 0.3s ease;
        border: none;
        box-shadow: 0 2px 10px rgba(0,0,0,0.1);
    }
    
    .stats-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 5px 20px rgba(0,0,0,0.15);
    }
    
    .stats-card-credit {
        border-left: 5px solid #28a745;
    }
    
    .stats-card-debit {
        border-left: 5px solid #dc3545;
    }
    
    .stats-card-total {
        border-left: 5px solid #007bff;
    }
    
    .stats-card-overdue {
        border-left: 5px solid #ffc107;
    }
    
    .aging-badge {
        font-size: 0.75rem;
        padding: 0.25rem 0.5rem;
    }
    
    .aging-current { background-color: #d4edda; color: #155724; }
    .aging-31-60 { background-color: #fff3cd; color: #856404; }
    .aging-61-90 { background-color: #f8d7da; color: #721c24; }
    .aging-90plus { background-color: #dc3545; color: white; }
    
    .balance-positive { color: #28a745; font-weight: bold; }
    .balance-negative { color: #dc3545; font-weight: bold; }
    .balance-zero { color: #6c757d; font-weight: bold; }
    
    .transaction-table {
        font-size: 0.875rem;
    }
    
    .transaction-table th {
        background-color: #f8f9fa;
        font-weight: 600;
        white-space: nowrap;
    }
    
    .export-btn {
        background: linear-gradient(135deg, #6c757d 0%, #495057 100%);
        border: none;
        color: white;
        font-weight: 600;
    }
    
    .export-btn:hover {
        background: linear-gradient(135deg, #495057 0%, #343a40 100%);
        color: white;
    }
    
    .filter-card {
        background-color: #f8f9fa;
        border-radius: 8px;
        border: 1px solid #dee2e6;
    }
    
    .client-select-card {
        max-height: 300px;
        overflow-y: auto;
        border: 1px solid #dee2e6;
        border-radius: 5px;
        padding: 10px;
    }
    
    .client-option {
        padding: 8px 10px;
        border-bottom: 1px solid #eee;
        cursor: pointer;
        transition: background-color 0.2s;
    }
    
    .client-option:hover {
        background-color: #e9ecef;
    }
    
    .client-option.selected {
        background-color: #007bff;
        color: white;
    }
    
    .transaction-receipt { color: #28a745; }
    .transaction-payment { color: #dc3545; }
    
    .progress-bar-overdue {
        background-color: #dc3545;
    }
    
    .progress-bar-current {
        background-color: #28a745;
    }
    
    /* Pagination Styles */
    .pagination {
        margin: 0;
    }
    
    .page-item.active .page-link {
        background-color: #007bff;
        border-color: #007bff;
    }
    
    .page-link {
        color: #007bff;
        border: 1px solid #dee2e6;
    }
    
    .page-link:hover {
        color: #0056b3;
        background-color: #e9ecef;
        border-color: #dee2e6;
    }
    
    .page-item.disabled .page-link {
        color: #6c757d;
        pointer-events: none;
        background-color: #fff;
        border-color: #dee2e6;
    }
    
    .records-per-page-selector {
        max-width: 100px;
    }
</style>

<div class="container-fluid">
    <?php if (!empty($success_message)): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="bi bi-check-circle-fill me-2"></i>
            <?php echo $success_message; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if (!empty($error_message)): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="bi bi-exclamation-triangle-fill me-2"></i>
            <?php echo $error_message; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Page Header -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h1 class="h3 mb-0 text-dark"><i class="bi bi-calculator me-2"></i>Debt & Credit Tracking</h1>
                    <p class="text-muted mb-0">Track client balances with aging analysis and transaction history</p>
                </div>
                <div class="btn-group">
                    <button type="button" class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#helpModal">
                        <i class="bi bi-question-circle me-1"></i>Help
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Summary Statistics -->
    <div class="row mb-4">
        <div class="col-md-3 mb-3">
            <div class="card stats-card stats-card-total">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="text-muted mb-1">Total Clients</h6>
                            <h3 class="mb-0"><?php echo $summary_stats['total_clients']; ?></h3>
                        </div>
                        <div class="bg-primary text-white rounded-circle p-3">
                            <i class="bi bi-people fs-4"></i>
                        </div>
                    </div>
                    <div class="mt-3">
                        <small class="text-muted">
                            <span class="text-success"><?php echo $summary_stats['clients_in_credit']; ?> Credit</span> | 
                            <span class="text-danger"><?php echo $summary_stats['clients_in_debit']; ?> Debit</span> | 
                            <span class="text-secondary"><?php echo $summary_stats['clients_settled']; ?> Settled</span>
                        </small>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-md-3 mb-3">
            <div class="card stats-card stats-card-credit">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="text-muted mb-1">Total Credit Balance</h6>
                            <h3 class="mb-0 text-success"><?php echo formatCurrency(max(0, $summary_stats['total_net_balance'])); ?></h3>
                        </div>
                        <div class="bg-success text-white rounded-circle p-3">
                            <i class="bi bi-arrow-up-circle fs-4"></i>
                        </div>
                    </div>
                    <div class="mt-3">
                        <small class="text-muted">Money we owe to clients</small>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-md-3 mb-3">
            <div class="card stats-card stats-card-debit">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="text-muted mb-1">Total Debit Balance</h6>
                            <h3 class="mb-0 text-danger"><?php echo formatCurrency(abs(min(0, $summary_stats['total_net_balance']))); ?></h3>
                        </div>
                        <div class="bg-danger text-white rounded-circle p-3">
                            <i class="bi bi-arrow-down-circle fs-4"></i>
                        </div>
                    </div>
                    <div class="mt-3">
                        <small class="text-muted">Money clients owe to us</small>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-md-3 mb-3">
            <div class="card stats-card stats-card-overdue">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="text-muted mb-1">Total Overdue</h6>
                            <h3 class="mb-0 text-warning"><?php echo formatCurrency($summary_stats['total_overdue']); ?></h3>
                        </div>
                        <div class="bg-warning text-white rounded-circle p-3">
                            <i class="bi bi-clock-history fs-4"></i>
                        </div>
                    </div>
                    <div class="mt-3">
                        <div class="progress" style="height: 6px;">
                            <div class="progress-bar progress-bar-overdue" 
                                 style="width: <?php echo $summary_stats['total_receipts'] > 0 ? ($summary_stats['total_overdue'] / $summary_stats['total_receipts'] * 100) : 0; ?>%">
                            </div>
                        </div>
                        <small class="text-muted">
                            <?php echo $summary_stats['total_receipts'] > 0 ? number_format($summary_stats['total_overdue'] / $summary_stats['total_receipts'] * 100, 1) : 0; ?>% of total receivables
                        </small>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Filters Section -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="card filter-card">
                <div class="card-header bg-light">
                    <h6 class="mb-0"><i class="bi bi-funnel me-2"></i>Filter Options</h6>
                </div>
                <div class="card-body">
                    <form method="GET" id="filterForm">
                        <input type="hidden" name="page" value="1">
                        <div class="row g-3">
                            <div class="col-md-3">
                                <label class="form-label">Client Selection</label>
                                <div class="client-select-card">
                                    <div class="form-check mb-2">
                                        <input class="form-check-input" type="radio" name="client_id" id="all_clients" value="" 
                                               <?php echo !$client_id ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="all_clients">
                                            All Clients
                                        </label>
                                    </div>
                                    <?php foreach ($all_clients as $client): ?>
                                    <div class="form-check mb-2">
                                        <input class="form-check-input" type="radio" name="client_id" 
                                               id="client_<?php echo $client['id']; ?>" 
                                               value="<?php echo $client['id']; ?>"
                                               <?php echo $client_id == $client['id'] ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="client_<?php echo $client['id']; ?>">
                                            <?php echo htmlspecialchars($client['client_name']); ?>
                                            <small class="text-muted d-block"><?php echo htmlspecialchars($client['cds_account']); ?></small>
                                        </label>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            
                            <div class="col-md-3">
                                <div class="mb-3">
                                    <label class="form-label">As of Date</label>
                                    <input type="date" class="form-control" name="as_of_date" 
                                           value="<?php echo htmlspecialchars($as_of_date); ?>"
                                           max="<?php echo date('Y-m-d'); ?>">
                                </div>
                                
                                <div class="mb-3">
                                    <label class="form-label">Date Range (Optional)</label>
                                    <div class="row g-2">
                                        <div class="col-6">
                                            <input type="date" class="form-control" name="start_date" 
                                                   value="<?php echo htmlspecialchars($start_date); ?>"
                                                   max="<?php echo date('Y-m-d'); ?>">
                                        </div>
                                        <div class="col-6">
                                            <input type="date" class="form-control" name="end_date" 
                                                   value="<?php echo htmlspecialchars($end_date); ?>"
                                                   max="<?php echo date('Y-m-d'); ?>">
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="col-md-3">
                                <div class="mb-3">
                                    <label class="form-label">Balance Status</label>
                                    <select class="form-select" name="balance_status">
                                        <option value="all" <?php echo $balance_status === 'all' ? 'selected' : ''; ?>>All Balances</option>
                                        <option value="credit" <?php echo $balance_status === 'credit' ? 'selected' : ''; ?>>Credit (We Owe Client)</option>
                                        <option value="debit" <?php echo $balance_status === 'debit' ? 'selected' : ''; ?>>Debit (Client Owes Us)</option>
                                        <option value="settled" <?php echo $balance_status === 'settled' ? 'selected' : ''; ?>>Settled (Zero Balance)</option>
                                    </select>
                                </div>
                                
                                <div class="mb-3">
                                    <label class="form-label">Aging Status</label>
                                    <select class="form-select" name="aging_filter">
                                        <option value="all" <?php echo $aging_filter === 'all' ? 'selected' : ''; ?>>All Aging Status</option>
                                        <option value="current" <?php echo $aging_filter === 'current' ? 'selected' : ''; ?>>Current (0% Overdue)</option>
                                        <option value="overdue_low" <?php echo $aging_filter === 'overdue_low' ? 'selected' : ''; ?>>Low Overdue (1-10%)</option>
                                        <option value="overdue_medium" <?php echo $aging_filter === 'overdue_medium' ? 'selected' : ''; ?>>Medium Overdue (11-30%)</option>
                                        <option value="overdue_high" <?php echo $aging_filter === 'overdue_high' ? 'selected' : ''; ?>>High Overdue (30%+)</option>
                                    </select>
                                </div>
                                
                                <!-- Records per page selector -->
                                <div class="mb-3">
                                    <label class="form-label">Records per page</label>
                                    <select class="form-select records-per-page-selector" name="per_page" onchange="updatePerPage(this.value)">
                                        <option value="10" <?php echo $records_per_page == 10 ? 'selected' : ''; ?>>10</option>
                                        <option value="20" <?php echo $records_per_page == 20 ? 'selected' : ''; ?>>20</option>
                                        <option value="50" <?php echo $records_per_page == 50 ? 'selected' : ''; ?>>50</option>
                                        <option value="100" <?php echo $records_per_page == 100 ? 'selected' : ''; ?>>100</option>
                                        <option value="0" <?php echo $records_per_page == 0 ? 'selected' : ''; ?>>All</option>
                                    </select>
                                </div>
                            </div>
                            
                            <div class="col-md-3">
                                <div class="d-flex flex-column h-100 justify-content-between">
                                    <div>
                                        <label class="form-label">Quick Actions</label>
                                        <div class="d-grid gap-2">
                                            <button type="button" class="btn btn-outline-primary" onclick="setDateRange('last30')">
                                                <i class="bi bi-calendar-week me-1"></i>Last 30 Days
                                            </button>
                                            <button type="button" class="btn btn-outline-primary" onclick="setDateRange('last90')">
                                                <i class="bi bi-calendar-month me-1"></i>Last 90 Days
                                            </button>
                                            <button type="button" class="btn btn-outline-secondary" onclick="resetFilters()">
                                                <i class="bi bi-arrow-clockwise me-1"></i>Reset Filters
                                            </button>
                                        </div>
                                    </div>
                                    
                                    <div class="d-grid gap-2 mt-3">
                                        <button type="submit" class="btn btn-primary">
                                            <i class="bi bi-filter me-1"></i>Apply Filters
                                        </button>
                                        <div class="btn-group">
                                            <a href="?<?php echo http_build_query(array_merge($_GET, ['export' => 'csv'])); ?>" 
                                               class="btn btn-success">
                                                <i class="bi bi-file-earmark-excel me-1"></i>Export CSV
                                            </a>
                                            <a href="?<?php echo http_build_query(array_merge($_GET, ['export' => 'pdf'])); ?>" 
                                               class="btn btn-danger">
                                                <i class="bi bi-file-earmark-pdf me-1"></i>Export PDF
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Pagination Info and Navigation -->
    <div class="row mb-3">
        <div class="col-md-6">
            <div class="d-flex align-items-center">
                <div class="me-3">
                    <span class="text-muted">
                        Showing <?php echo min($records_per_page, count($clients_data)); ?> of <?php echo $total_clients_count; ?> clients
                        <?php if ($client_id): ?> (Selected Client Only)<?php endif; ?>
                    </span>
                </div>
                <div>
                    <?php if ($total_pages > 1): ?>
                        <span class="badge bg-info">Page <?php echo $current_page; ?> of <?php echo $total_pages; ?></span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <?php if ($total_pages > 1): ?>
                <nav aria-label="Page navigation">
                    <ul class="pagination justify-content-end mb-0">
                        <!-- First Page -->
                        <li class="page-item <?php echo $current_page == 1 ? 'disabled' : ''; ?>">
                            <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => 1])); ?>" aria-label="First">
                                <span aria-hidden="true">&laquo;&laquo;</span>
                            </a>
                        </li>
                        
                        <!-- Previous Page -->
                        <li class="page-item <?php echo $current_page == 1 ? 'disabled' : ''; ?>">
                            <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => max(1, $current_page - 1)])); ?>" aria-label="Previous">
                                <span aria-hidden="true">&laquo;</span>
                            </a>
                        </li>
                        
                        <!-- Page Numbers -->
                        <?php 
                        $start_page = max(1, $current_page - 2);
                        $end_page = min($total_pages, $current_page + 2);
                        
                        if ($start_page > 1): ?>
                            <li class="page-item disabled"><span class="page-link">...</span></li>
                        <?php endif; ?>
                        
                        <?php for ($i = $start_page; $i <= $end_page; $i++): ?>
                            <li class="page-item <?php echo $i == $current_page ? 'active' : ''; ?>">
                                <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>">
                                    <?php echo $i; ?>
                                </a>
                            </li>
                        <?php endfor; ?>
                        
                        <?php if ($end_page < $total_pages): ?>
                            <li class="page-item disabled"><span class="page-link">...</span></li>
                        <?php endif; ?>
                        
                        <!-- Next Page -->
                        <li class="page-item <?php echo $current_page == $total_pages ? 'disabled' : ''; ?>">
                            <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => min($total_pages, $current_page + 1)])); ?>" aria-label="Next">
                                <span aria-hidden="true">&raquo;</span>
                            </a>
                        </li>
                        
                        <!-- Last Page -->
                        <li class="page-item <?php echo $current_page == $total_pages ? 'disabled' : ''; ?>">
                            <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $total_pages])); ?>" aria-label="Last">
                                <span aria-hidden="true">&raquo;&raquo;</span>
                            </a>
                        </li>
                    </ul>
                </nav>
            <?php endif; ?>
        </div>
    </div>

    <!-- Client Balances Table -->
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-header bg-light d-flex justify-content-between align-items-center">
                    <h6 class="mb-0"><i class="bi bi-table me-2"></i>Client Balances & Aging Analysis</h6>
                    <small class="text-muted">
                        Page <?php echo $current_page; ?> of <?php echo $total_pages; ?> 
                        (<?php echo count($clients_data); ?> clients on this page)
                    </small>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($clients_data)): ?>
                        <div class="text-center py-5">
                            <i class="bi bi-inbox" style="font-size: 3rem; color: #6c757d;"></i>
                            <h5 class="mt-3 text-muted">No client data found</h5>
                            <p class="text-muted">Try adjusting your filter criteria</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Client</th>
                                        <th>CDS Account</th>
                                        <th>Total Receipts</th>
                                        <th>Total Payments</th>
                                        <th>Net Balance</th>
                                        <th>Balance Status</th>
                                        <th>Aging Analysis</th>
                                        <th>% Overdue</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($clients_data as $client_data): 
                                        $client = $client_data['client_info'];
                                        $totals = $client_data['totals'];
                                        $aging = $client_data['aging'];
                                    ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo htmlspecialchars($client['client_name']); ?></strong>
                                                <br>
                                                <small class="text-muted"><?php echo htmlspecialchars($client['client_type']); ?></small>
                                            </td>
                                            <td>
                                                <code><?php echo htmlspecialchars($client['cds_account']); ?></code>
                                            </td>
                                            <td class="<?php echo $totals['receipts'] > 0 ? 'text-danger' : 'text-muted'; ?>">
                                                <?php echo formatCurrency($totals['receipts']); ?>
                                            </td>
                                            <td class="<?php echo $totals['payments'] > 0 ? 'text-success' : 'text-muted'; ?>">
                                                <?php echo formatCurrency($totals['payments']); ?>
                                            </td>
                                            <td class="<?php 
                                                echo $totals['net_balance'] > 0 ? 'balance-positive' : 
                                                    ($totals['net_balance'] < 0 ? 'balance-negative' : 'balance-zero'); 
                                            ?>">
                                                <?php echo formatCurrency(abs($totals['net_balance'])); ?>
                                            </td>
                                            <td>
                                                <span class="badge <?php 
                                                    echo $totals['net_balance'] > 0 ? 'bg-success' : 
                                                        ($totals['net_balance'] < 0 ? 'bg-danger' : 'bg-secondary'); 
                                                ?>">
                                                    <?php echo $totals['balance_status']; ?>
                                                </span>
                                            </td>
                                            <td>
                                                <div class="d-flex gap-1 mb-1">
                                                    <?php if ($aging['buckets']['Current'] > 0): ?>
                                                        <span class="badge aging-badge aging-current" title="Current (0-30 days)">
                                                            C: <?php echo number_format($aging['buckets']['Current'] / 1000, 1); ?>K
                                                        </span>
                                                    <?php endif; ?>
                                                    <?php if ($aging['buckets']['31-60 Days'] > 0): ?>
                                                        <span class="badge aging-badge aging-31-60" title="31-60 Days Overdue">
                                                            31-60: <?php echo number_format($aging['buckets']['31-60 Days'] / 1000, 1); ?>K
                                                        </span>
                                                    <?php endif; ?>
                                                    <?php if ($aging['buckets']['61-90 Days'] > 0): ?>
                                                        <span class="badge aging-badge aging-61-90" title="61-90 Days Overdue">
                                                            61-90: <?php echo number_format($aging['buckets']['61-90 Days'] / 1000, 1); ?>K
                                                        </span>
                                                    <?php endif; ?>
                                                    <?php if ($aging['buckets']['90+ Days'] > 0): ?>
                                                        <span class="badge aging-badge aging-90plus" title="90+ Days Overdue">
                                                            90+: <?php echo number_format($aging['buckets']['90+ Days'] / 1000, 1); ?>K
                                                        </span>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="progress" style="height: 5px;">
                                                    <?php $total_aging = array_sum($aging['buckets']); ?>
                                                    <?php if ($total_aging > 0): ?>
                                                        <div class="progress-bar progress-bar-current" 
                                                             style="width: <?php echo ($aging['buckets']['Current'] / $total_aging * 100); ?>%">
                                                        </div>
                                                        <div class="progress-bar bg-warning" 
                                                             style="width: <?php echo ($aging['buckets']['31-60 Days'] / $total_aging * 100); ?>%">
                                                        </div>
                                                        <div class="progress-bar bg-danger" 
                                                             style="width: <?php echo (($aging['buckets']['61-90 Days'] + $aging['buckets']['90+ Days']) / $total_aging * 100); ?>%">
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                            <td>
                                                <span class="<?php echo $aging['overdue_ratio'] > 30 ? 'text-danger fw-bold' : ($aging['overdue_ratio'] > 10 ? 'text-warning fw-bold' : 'text-success'); ?>">
                                                    <?php echo number_format($aging['overdue_ratio'], 1); ?>%
                                                </span>
                                            </td>
                                            <td>
                                                <div class="btn-group btn-group-sm">
                                                    <button type="button" class="btn btn-outline-primary view-transactions" 
                                                            data-client-id="<?php echo $client['id']; ?>"
                                                            data-client-name="<?php echo htmlspecialchars($client['client_name']); ?>">
                                                        <i class="bi bi-list-ul"></i> Transactions
                                                    </button>
                                                    <button type="button" class="btn btn-outline-info view-details" 
                                                            data-client-id="<?php echo $client['id']; ?>"
                                                            data-client-name="<?php echo htmlspecialchars($client['client_name']); ?>">
                                                        <i class="bi bi-eye"></i> Details
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                                <tfoot class="table-light">
                                    <tr>
                                        <th colspan="2">PAGE TOTALS:</th>
                                        <th class="text-danger"><?php echo formatCurrency($summary_stats['total_receipts']); ?></th>
                                        <th class="text-success"><?php echo formatCurrency($summary_stats['total_payments']); ?></th>
                                        <th class="<?php echo $summary_stats['total_net_balance'] >= 0 ? 'balance-positive' : 'balance-negative'; ?>">
                                            <?php echo formatCurrency(abs($summary_stats['total_net_balance'])); ?>
                                        </th>
                                        <th>
                                            <span class="badge <?php echo $summary_stats['total_net_balance'] >= 0 ? 'bg-success' : 'bg-danger'; ?>">
                                                <?php echo $summary_stats['total_net_balance'] >= 0 ? 'Net Credit' : 'Net Debit'; ?>
                                            </span>
                                        </th>
                                        <th>
                                            <small class="text-muted">
                                                Current: <?php echo formatCurrency($summary_stats['total_current']); ?> | 
                                                Overdue: <?php echo formatCurrency($summary_stats['total_overdue']); ?>
                                            </small>
                                        </th>
                                        <th>
                                            <?php echo $summary_stats['total_receipts'] > 0 ? 
                                                number_format($summary_stats['total_overdue'] / $summary_stats['total_receipts'] * 100, 1) : 0; ?>%
                                        </th>
                                        <th></th>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
                
                <!-- Bottom Pagination -->
                <?php if ($total_pages > 1): ?>
                <div class="card-footer">
                    <div class="row">
                        <div class="col-md-6">
                            <small class="text-muted">
                                Showing page <?php echo $current_page; ?> of <?php echo $total_pages; ?> 
                                (<?php echo count($clients_data); ?> clients on this page)
                            </small>
                        </div>
                        <div class="col-md-6">
                            <nav aria-label="Page navigation" class="float-end">
                                <ul class="pagination pagination-sm mb-0">
                                    <li class="page-item <?php echo $current_page == 1 ? 'disabled' : ''; ?>">
                                        <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => max(1, $current_page - 1)])); ?>">
                                            Previous
                                        </a>
                                    </li>
                                    
                                    <?php for ($i = max(1, $current_page - 1); $i <= min($total_pages, $current_page + 3); $i++): ?>
                                        <li class="page-item <?php echo $i == $current_page ? 'active' : ''; ?>">
                                            <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>">
                                                <?php echo $i; ?>
                                            </a>
                                        </li>
                                    <?php endfor; ?>
                                    
                                    <li class="page-item <?php echo $current_page == $total_pages ? 'disabled' : ''; ?>">
                                        <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => min($total_pages, $current_page + 1)])); ?>">
                                            Next
                                        </a>
                                    </li>
                                </ul>
                            </nav>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Transaction Details Modal -->
<div class="modal fade" id="transactionModal" tabindex="-1" aria-labelledby="transactionModalLabel" aria-hidden="true">
    <!-- Keep existing modal content -->
</div>

<!-- Client Details Modal -->
<div class="modal fade" id="clientDetailsModal" tabindex="-1" aria-labelledby="clientDetailsModalLabel" aria-hidden="true">
    <!-- Keep existing modal content -->
</div>

<!-- Help Modal -->
<div class="modal fade" id="helpModal" tabindex="-1" aria-labelledby="helpModalLabel" aria-hidden="true">
    <!-- Keep existing modal content -->
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Quick date range buttons
    window.setDateRange = function(range) {
        const today = new Date();
        const startDate = document.querySelector('input[name="start_date"]');
        const endDate = document.querySelector('input[name="end_date"]');
        const asOfDate = document.querySelector('input[name="as_of_date"]');
        
        let start = new Date();
        
        switch(range) {
            case 'last30':
                start.setDate(today.getDate() - 30);
                break;
            case 'last90':
                start.setDate(today.getDate() - 90);
                break;
        }
        
        const formatDate = (date) => date.toISOString().split('T')[0];
        
        startDate.value = formatDate(start);
        endDate.value = formatDate(today);
        asOfDate.value = formatDate(today);
        
        // Reset to page 1 when changing date range
        document.querySelector('input[name="page"]').value = 1;
        
        // Submit form
        document.getElementById('filterForm').submit();
    };
    
    // Reset filters
    window.resetFilters = function() {
        window.location.href = window.location.pathname;
    };
    
    // Update records per page
    window.updatePerPage = function(value) {
        // Reset to page 1 when changing records per page
        document.querySelector('input[name="page"]').value = 1;
        
        // Create a hidden input for per_page if it doesn't exist
        let perPageInput = document.querySelector('input[name="per_page"]');
        if (!perPageInput) {
            perPageInput = document.createElement('input');
            perPageInput.type = 'hidden';
            perPageInput.name = 'per_page';
            document.getElementById('filterForm').appendChild(perPageInput);
        }
        perPageInput.value = value;
        
        // Submit form
        document.getElementById('filterForm').submit();
    };
    
    // Keep existing JavaScript for modals and other functionality
    // ... existing JavaScript code ...
    
    // Auto-submit form when clicking client radio buttons - reset to page 1
    document.querySelectorAll('input[name="client_id"]').forEach(radio => {
        radio.addEventListener('change', function() {
            document.querySelector('input[name="page"]').value = 1;
            if (this.value) {
                document.getElementById('filterForm').submit();
            }
        });
    });
});
</script>

<?php include '../includes/footer.php'; ?>