<?php
// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

// Start session early
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once '../config/config.php';
require_once '../auth/auth_middleware.php';

require_finance_officer();

$db = getDBConnection();

// Check database connection
if (!$db) {
    die("Database connection failed. Please contact administrator.");
}

// CSRF Protection
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// --- Fetch Dashboard Data ---

// 1. Get finance statistics
$stats = [];

// Get current date range for calculations
$current_month = date('m');
$current_year = date('Y');
$start_of_month = date('Y-m-01');
$end_of_month = date('Y-m-t');

// Get total revenue this month
$stmt = $db->prepare("
    SELECT COALESCE(SUM(gl.credit_amount), 0) as total 
    FROM general_ledger gl
    JOIN chart_of_accounts coa ON gl.account_id = coa.id
    WHERE gl.transaction_date BETWEEN ? AND ?
    AND gl.status = 'active'
    AND coa.account_type = 'income'
    AND gl.credit_amount > 0
");
$stmt->execute([$start_of_month, $end_of_month]);
$stats['monthly_revenue'] = $stmt->fetch()['total'];

// Get total expenses this month
$stmt = $db->prepare("
    SELECT COALESCE(SUM(gl.debit_amount), 0) as total 
    FROM general_ledger gl
    JOIN chart_of_accounts coa ON gl.account_id = coa.id
    WHERE gl.transaction_date BETWEEN ? AND ?
    AND gl.status = 'active'
    AND coa.account_type = 'expense'
    AND gl.debit_amount > 0
");
$stmt->execute([$start_of_month, $end_of_month]);
$stats['monthly_expenses'] = $stmt->fetch()['total'];

// Net position this month
$stats['net_position'] = $stats['monthly_revenue'] - $stats['monthly_expenses'];

// Count pending receipts
$stmt = $db->query("SELECT COUNT(*) as total FROM receipts WHERE status = 'active' AND record_in_financial = 'no'");
$stats['pending_receipts'] = $stmt->fetch()['total'];

// Count pending payments
$stmt = $db->query("SELECT COUNT(*) as total FROM payments WHERE status = 'active' AND record_in_financial = 'no'");
$stats['pending_payments'] = $stmt->fetch()['total'];

// Number of Pending Payment Requests for Finance Approval
$pending_payments_query = "
    SELECT COUNT(*) as pending_count 
    FROM pending_pay 
    WHERE status = 'approved_ceo' AND finance_approved_at IS NULL
";
$stmt = $db->query($pending_payments_query);
$pending_ceo_approvals = $stmt->fetchColumn();

// Pending Payment Requests for Finance Approval (max 5)
$pending_requests_query = "
    SELECT pp.*, 
           u.full_name as requested_by_name,
           lt.description as pay_to_desc,
           u2.full_name as ceo_approved_by_name,
           v.name as vendor_name,
           s.full_name as staff_name,
           c.name as customer_name
    FROM pending_pay pp
    LEFT JOIN users u ON pp.requested_by = u.id
    LEFT JOIN users u2 ON pp.ceo_approved_by = u2.id
    LEFT JOIN ledger_types lt ON pp.pay_to_type = lt.code
    LEFT JOIN vendors v ON pp.pay_to_type = 'vendor' AND pp.payee_id = v.id
    LEFT JOIN users s ON pp.pay_to_type = 'staff' AND pp.payee_id = s.id
    LEFT JOIN customers c ON pp.pay_to_type = 'customer' AND pp.payee_id = c.id
    WHERE pp.status = 'approved_ceo' 
    AND pp.finance_approved_at IS NULL
    ORDER BY pp.ceo_approved_at ASC
    LIMIT 5
";
$stmt = $db->query($pending_requests_query);
$pending_requests = $stmt->fetchAll();

// Update total pending approvals
$stats['pending_approvals'] = $stats['pending_receipts'] + $stats['pending_payments'] + $pending_ceo_approvals;

// Financial Reports Statistics
$balance_sheet_items = $db->query("SELECT COUNT(*) as total FROM chart_of_accounts WHERE account_type IN ('asset', 'liability', 'equity') AND is_active = 1")->fetch()['total'];
$income_statement_items = $db->query("SELECT COUNT(*) as total FROM chart_of_accounts WHERE account_type IN ('income', 'expense') AND is_active = 1")->fetch()['total'];

// Get cash flow components count
$cashflow_components = $db->query("SELECT COUNT(*) as total FROM payment_methods WHERE status = 'active'")->fetch()['total'];

// Recent transactions from general ledger
$stmt = $db->query("
    SELECT gl.*, coa.account_name, coa.account_type,
           DATE_FORMAT(gl.transaction_date, '%d/%m/%Y') as transaction_date_display,
           CASE 
               WHEN gl.debit_amount > 0 THEN 'Debit'
               WHEN gl.credit_amount > 0 THEN 'Credit'
               ELSE 'N/A'
           END as transaction_side,
           COALESCE(gl.created_by_username, 'System') as created_by_display
    FROM general_ledger gl
    JOIN chart_of_accounts coa ON gl.account_id = coa.id
    WHERE gl.status = 'active'
    ORDER BY gl.transaction_date DESC, gl.created_at DESC
    LIMIT 10
");
$recent_transactions = $stmt->fetchAll();

// Pending approvals detail (for receipts and payments)
$stmt = $db->query("
    SELECT 'receipt' as type, receipt_no as reference_number, receipt_date as transaction_date, 
           name, amount, currency, 'Record receipt in financial system' as description, id,
           DATE_FORMAT(receipt_date, '%d/%m/%Y') as date_display
    FROM receipts 
    WHERE status = 'active' AND record_in_financial = 'no'
    UNION ALL
    SELECT 'payment' as type, payment_no as reference_number, payment_date as transaction_date, 
           name, amount, currency, 'Record payment in financial system' as description, id,
           DATE_FORMAT(payment_date, '%d/%m/%Y') as date_display
    FROM payments 
    WHERE status = 'active' AND record_in_financial = 'no'
    ORDER BY transaction_date ASC
    LIMIT 20
");
$pending_transactions = $stmt->fetchAll();

// Fetch payment methods for dropdown
try {
    $payment_methods_stmt = $db->query("SELECT id, code, description, cashbook, priority, status FROM payment_methods WHERE status = 'active' ORDER BY priority");
    $payment_methods = $payment_methods_stmt->fetchAll();
} catch (PDOException $e) {
    $payment_methods = [];
}

// Fetch bank accounts
try {
    $bank_accounts_stmt = $db->query("SELECT id, code, bank_name, account_name, account_number, currency, current_balance FROM banks_accounts WHERE status = 'active' ORDER BY bank_name, account_name");
    $bank_accounts = $bank_accounts_stmt->fetchAll();
} catch (PDOException $e) {
    $bank_accounts = [];
}

// Handle AJAX requests for payment approval
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    // Verify CSRF token
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
        exit;
    }
    
    $action = $_POST['action'];
    $request_id = (int)($_POST['request_id'] ?? 0);
    
    if (!$request_id) {
        echo json_encode(['success' => false, 'message' => 'Invalid request ID']);
        exit;
    }
    
    try {
        $db->beginTransaction();
        
        // Get user ID (Finance Officer)
        $user_id = $_SESSION['user_id'] ?? 1;
        
        if ($action === 'approve') {
            // Get payment mode, bank account, and financial record option from POST
            $payment_mode = $_POST['payment_mode'] ?? '';
            $ac_credit = $_POST['ac_credit'] ?? '';
            $record_in_financial = $_POST['record_in_financial'] ?? 'yes';
            $notes = $_POST['notes'] ?? '';
            
            if (empty($payment_mode)) {
                echo json_encode(['success' => false, 'message' => 'Payment mode is required']);
                exit;
            }
            
            if (empty($ac_credit)) {
                echo json_encode(['success' => false, 'message' => 'Bank account is required']);
                exit;
            }
            
            // First, get the payment request details
            $stmt = $db->prepare("
                SELECT pp.*, u.full_name as requested_by_name
                FROM pending_pay pp
                LEFT JOIN users u ON pp.requested_by = u.id
                WHERE pp.id = ? AND pp.status = 'approved_ceo'
            ");
            $stmt->execute([$request_id]);
            $request = $stmt->fetch();
            
            if (!$request) {
                echo json_encode(['success' => false, 'message' => 'Payment request not found or not approved by CEO']);
                exit;
            }
            
            // Get payment method details
            $stmt = $db->prepare("SELECT code, description FROM payment_methods WHERE id = ? AND status = 'active'");
            $stmt->execute([$payment_mode]);
            $payment_method = $stmt->fetch();
            
            if (!$payment_method) {
                echo json_encode(['success' => false, 'message' => 'Invalid payment method']);
                exit;
            }
            
            // Get bank account details
            $stmt = $db->prepare("
                SELECT id, bank_name, account_name, account_number, current_balance, currency, code 
                FROM banks_accounts 
                WHERE id = ? AND status = 'active'
            ");
            $stmt->execute([$ac_credit]);
            $bank_account = $stmt->fetch();
            
            if (!$bank_account) {
                echo json_encode(['success' => false, 'message' => 'Invalid bank account']);
                exit;
            }
            
            // Check if bank has sufficient balance
            if ($record_in_financial === 'yes' && $bank_account['current_balance'] < $request['amount_paid']) {
                $available = number_format($bank_account['current_balance'], 2);
                echo json_encode(['success' => false, 'message' => "Insufficient balance in bank account. Available: {$available} {$bank_account['currency']}"]);
                exit;
            }
            
            // Generate payment number
            function generatePaymentNoForRequest($db, $payment_date) {
                $prefix = 'PMT';
                $date = date('Ymd', strtotime($payment_date));
                
                $stmt = $db->prepare("
                    SELECT payment_no FROM payments 
                    WHERE payment_no LIKE ? 
                    ORDER BY id DESC LIMIT 1
                ");
                $stmt->execute(["$prefix$date%"]);
                $last = $stmt->fetch();
                
                if ($last) {
                    $last_no = intval(substr($last['payment_no'], -3));
                    $new_no = str_pad($last_no + 1, 3, '0', STR_PAD_LEFT);
                } else {
                    $new_no = '001';
                }
                
                return $prefix . $date . $new_no;
            }
            
            $payment_no = generatePaymentNoForRequest($db, $request['requested_at']);
            
            // Update payment request status
            $stmt = $db->prepare("
                UPDATE pending_pay 
                SET finance_approved_at = NOW(), 
                    finance_approved_by = ?,
                    finance_approval_notes = ?,
                    status = 'approved_finance',
                    updated_at = NOW()
                WHERE id = ? AND status = 'approved_ceo'
            ");
            $stmt->execute([$user_id, $notes, $request_id]);
            
            // Create payment record
            $stmt = $db->prepare("
                INSERT INTO payments (
                    payment_date, payment_mode, payment_code, paid_to, name_id, name, ac_credit, 
                    payment_no, currency, account_no, amount, cheque_no,
                    narration, record_in_financial, created_by, status, source_type, 
                    bank_name, bank_account_number, pending_pay_id
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', ?, ?, ?, ?)
            ");
            
            $stmt->execute([
                date('Y-m-d'),
                $payment_mode,
                $payment_method['code'],
                $request['pay_to_type'],
                $request['payee_id'],
                $request['payee_name'],
                $ac_credit,
                $payment_no,
                $request['currency'],
                $request['payee_account_no'],
                $request['amount_paid'],
                $request['cheque_no'] ?? '',
                $request['subject'],
                $record_in_financial,
                $user_id,
                'pending_pay',
                $bank_account['bank_name'],
                $bank_account['account_number'],
                $request_id
            ]);
            
            $payment_id = $db->lastInsertId();
            
            // Update bank account balance if recording in financial
            if ($record_in_financial === 'yes') {
                $new_balance = $bank_account['current_balance'] - $request['amount_paid'];
                $stmt = $db->prepare("
                    UPDATE banks_accounts 
                    SET current_balance = ?, 
                        available_balance = ?, 
                        updated_at = CURRENT_TIMESTAMP 
                    WHERE id = ?
                ");
                $stmt->execute([$new_balance, $new_balance, $ac_credit]);
            }
            
            // Log approval in audit trail
            $audit_stmt = $db->prepare("
                INSERT INTO audit_trail (
                    user_id, action, description, ip_address, user_agent
                ) VALUES (?, 'finance_payment_approval', ?, ?, ?)
            ");
            
            $audit_stmt->execute([
                $user_id,
                "Finance approved and created payment #{$payment_no} for request #{$request['request_no']} - Amount: " . number_format($request['amount_paid'], 2) . " {$request['currency']}",
                $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
                $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'
            ]);
            
            $db->commit();
            echo json_encode(['success' => true, 'message' => 'Payment approved and created successfully! Payment Number: ' . $payment_no]);
            
        } elseif ($action === 'reject') {
            $rejection_reason = $_POST['rejection_reason'] ?? '';
            
            if (empty($rejection_reason)) {
                echo json_encode(['success' => false, 'message' => 'Rejection reason is required']);
                exit;
            }
            
            // Update payment request status to rejected
            $stmt = $db->prepare("
                UPDATE pending_pay 
                SET finance_approved_at = NOW(), 
                    finance_approved_by = ?,
                    finance_approval_notes = ?,
                    rejection_reason = CONCAT(COALESCE(rejection_reason, ''), ' | Finance Rejection: ', ?),
                    status = 'rejected',
                    updated_at = NOW()
                WHERE id = ? AND status = 'approved_ceo'
            ");
            $stmt->execute([$user_id, 'Rejected by Finance', $rejection_reason, $request_id]);
            
            // Log rejection in audit trail
            $audit_stmt = $db->prepare("
                INSERT INTO audit_trail (
                    user_id, action, description, ip_address, user_agent
                ) VALUES (?, 'finance_payment_rejection', ?, ?, ?)
            ");
            
            $audit_stmt->execute([
                $user_id,
                "Finance rejected payment request #{$request_id}",
                $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
                $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'
            ]);
            
            $db->commit();
            echo json_encode(['success' => true, 'message' => 'Payment request rejected successfully']);
            
        } elseif ($action === 'get_request_details') {
            // Get detailed request information
            $stmt = $db->prepare("
                SELECT pp.*, 
                       u.username as requested_by_username,
                       u.full_name as requested_by_fullname,
                       u2.full_name as ceo_approved_by_fullname,
                       lt.description as pay_to_desc
                FROM pending_pay pp
                LEFT JOIN users u ON pp.requested_by = u.id
                LEFT JOIN users u2 ON pp.ceo_approved_by = u2.id
                LEFT JOIN ledger_types lt ON pp.pay_to_type = lt.code
                WHERE pp.id = ?
            ");
            $stmt->execute([$request_id]);
            $request_details = $stmt->fetch();
            
            if ($request_details) {
                echo json_encode(['success' => true, 'data' => $request_details]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Request not found']);
            }
        } else {
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
        }
        
    } catch (Exception $e) {
        $db->rollBack();
        error_log("Finance payment approval error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
    exit;
}

// Helper function for currency formatting

$page_title = 'Finance Dashboard';
include '../includes/header.php';
?>

<!-- Enhanced professional finance dashboard header -->
<div class="page-header">
    <div class="container-fluid">
        <div class="row align-items-center">
            <div class="col-md-8">
                <div class="d-flex align-items-center">
                    <div class="me-3">
                        <div class="d-inline-flex align-items-center justify-content-center rounded-circle shadow-sm" 
                             style="width: 60px; height: 60px; background: linear-gradient(135deg, var(--info-color) 0%, #0ea5e9 100%);">
                            <i class="bi bi-calculator text-white" style="font-size: 1.5rem;"></i>
                        </div>
                    </div>
                    <div>
                        <h1 class="page-title mb-1">Financial Management</h1>
                        <p class="page-subtitle">Comprehensive financial oversight and transaction management</p>
                    </div>
                </div>
            </div>
            <div class="col-md-4 text-end">
                <div class="d-flex flex-column align-items-end">
                    <span class="text-muted small">Welcome back</span>
                    <span class="fw-semibold"><?php echo htmlspecialchars($_SESSION['full_name'] ?? 'User'); ?></span>
                    <span class="badge bg-success mt-1">Currency: TSH</span>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Enhanced financial statistics with professional styling -->
<div class="container-fluid">
    <div class="row g-4 mb-5">
        <div class="col-xl-3 col-md-6">
            <div class="card dashboard-card border-0 h-100" style="background: linear-gradient(135deg, var(--danger-color) 0%, #ef4444 100%); color: white;">
                <div class="card-body p-4">
                    <div class="d-flex justify-content-between align-items-start">
                        <div class="flex-grow-1">
                            <div class="stat-number mb-2">TSH <?php echo number_format($stats['monthly_expenses'], 0); ?></div>
                            <div class="stat-label">Monthly Expenses</div>
                            <div class="mt-2">
                                <small class="opacity-75">
                                    <i class="bi bi-arrow-down me-1"></i>
                                    Outgoing transactions
                                </small>
                            </div>
                        </div>
                        <div class="ms-3">
                            <div class="d-flex align-items-center justify-content-center rounded-circle" 
                                 style="width: 50px; height: 50px; background: rgba(255,255,255,0.2);">
                                <i class="bi bi-arrow-down-circle" style="font-size: 1.5rem;"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-xl-3 col-md-6">
            <div class="card dashboard-card border-0 h-100" style="background: linear-gradient(135deg, var(--success-color) 0%, #10b981 100%); color: white;">
                <div class="card-body p-4">
                    <div class="d-flex justify-content-between align-items-start">
                        <div class="flex-grow-1">
                            <div class="stat-number mb-2">TSH <?php echo number_format($stats['monthly_revenue'], 0); ?></div>
                            <div class="stat-label">Monthly Revenue</div>
                            <div class="mt-2">
                                <small class="opacity-75">
                                    <i class="bi bi-arrow-up me-1"></i>
                                    Incoming transactions
                                </small>
                            </div>
                        </div>
                        <div class="ms-3">
                            <div class="d-flex align-items-center justify-content-center rounded-circle" 
                                 style="width: 50px; height: 50px; background: rgba(255,255,255,0.2);">
                                <i class="bi bi-arrow-up-circle" style="font-size: 1.5rem;"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-xl-3 col-md-6">
            <div class="card dashboard-card border-0 h-100" style="background: linear-gradient(135deg, <?php echo $stats['net_position'] >= 0 ? 'var(--info-color)' : 'var(--warning-color)'; ?> 0%, <?php echo $stats['net_position'] >= 0 ? '#0ea5e9' : '#f59e0b'; ?> 100%); color: white;">
                <div class="card-body p-4">
                    <div class="d-flex justify-content-between align-items-start">
                        <div class="flex-grow-1">
                            <div class="stat-number mb-2">TSH <?php echo number_format(abs($stats['net_position']), 0); ?></div>
                            <div class="stat-label">Net Position</div>
                            <div class="mt-2">
                                <small class="opacity-75">
                                    <i class="bi bi-<?php echo $stats['net_position'] >= 0 ? 'plus' : 'dash'; ?> me-1"></i>
                                    <?php echo $stats['net_position'] >= 0 ? 'Positive' : 'Negative'; ?> balance
                                </small>
                            </div>
                        </div>
                        <div class="ms-3">
                            <div class="d-flex align-items-center justify-content-center rounded-circle" 
                                 style="width: 50px; height: 50px; background: rgba(255,255,255,0.2);">
                                <i class="bi bi-<?php echo $stats['net_position'] >= 0 ? 'plus' : 'dash'; ?>-circle" style="font-size: 1.5rem;"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-xl-3 col-md-6">
            <div class="card dashboard-card border-0 h-100" style="background: linear-gradient(135deg, var(--accent-color) 0%, #f472b6 100%); color: white;">
                <div class="card-body p-4">
                    <div class="d-flex justify-content-between align-items-start">
                        <div class="flex-grow-1">
                            <div class="stat-number mb-2"><?php echo number_format($stats['pending_approvals']); ?></div>
                            <div class="stat-label">Pending Approvals</div>
                            <div class="mt-2">
                                <small class="opacity-75">
                                    <i class="bi bi-clock me-1"></i>
                                    Receipts: <?php echo $stats['pending_receipts']; ?> | 
                                    Payments: <?php echo $stats['pending_payments']; ?> |
                                    CEO Approvals: <?php echo $pending_ceo_approvals; ?>
                                </small>
                            </div>
                        </div>
                        <div class="ms-3">
                            <div class="d-flex align-items-center justify-content-center rounded-circle" 
                                 style="width: 50px; height: 50px; background: rgba(255,255,255,0.2);">
                                <i class="bi bi-clock" style="font-size: 1.5rem;"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- --- CEO-Approved Payment Requests Section --- -->
    <div class="row g-4 mb-5">
        <div class="col-12">
            <div class="card dashboard-card">
                <div class="card-header bg-transparent border-0 pb-0">
                    <div class="d-flex align-items-center justify-content-between">
                        <div class="d-flex align-items-center">
                            <div class="me-2">
                                <i class="bi bi-cash-stack text-success"></i>
                            </div>
                            <h6 class="mb-0 fw-semibold">CEO-Approved Payment Requests</h6>
                        </div>
                        <div class="text-muted small">
                            Pending financial processing
                        </div>
                    </div>
                </div>
                <div class="card-body">
                    <?php if (empty($pending_requests)): ?>
                        <div class="text-center py-4">
                            <i class="bi bi-check-circle text-success display-4"></i>
                            <p class="mt-3 mb-0">No pending CEO-approved payments</p>
                            <small class="text-muted">All payments have been processed</small>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Request #</th>
                                        <th>Date</th>
                                        <th>Subject</th>
                                        <th>Pay To</th>
                                        <th>Requested By</th>
                                        <th>CEO Approved By</th>
                                        <th>Amount</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($pending_requests as $request): ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo htmlspecialchars($request['request_no']); ?></strong>
                                            </td>
                                            <td><?php echo date('M d, Y', strtotime($request['requested_at'])); ?></td>
                                            <td>
                                                <div class="text-truncate" style="max-width: 200px;">
                                                    <?php echo htmlspecialchars($request['subject']); ?>
                                                </div>
                                            </td>
                                            <td><?php echo htmlspecialchars($request['pay_to_desc'] ?? 'N/A'); ?></td>
                                            <td><?php echo htmlspecialchars($request['requested_by_name'] ?? 'Unknown'); ?></td>
                                            <td><?php echo htmlspecialchars($request['ceo_approved_by_name'] ?? 'Unknown'); ?></td>
                                            <td class="fw-bold text-success">
                                                <?php echo number_format($request['amount_paid'], 2); ?>
                                                <?php echo htmlspecialchars($request['currency']); ?>
                                            </td>
                                            <td>
                                                <span class="badge bg-info">
                                                    Awaiting Finance Processing
                                                </span>
                                            </td>
                                            <td>
                                                <div class="btn-group btn-group-sm" role="group">
                                                    <button type="button" class="btn btn-outline-primary view-request-btn" 
                                                            data-bs-toggle="modal" data-bs-target="#viewRequestModal"
                                                            data-request-id="<?php echo $request['id']; ?>"
                                                            data-request-no="<?php echo htmlspecialchars($request['request_no']); ?>"
                                                            data-date="<?php echo date('M d, Y', strtotime($request['requested_at'])); ?>"
                                                            data-subject="<?php echo htmlspecialchars($request['subject']); ?>"
                                                            data-pay-to="<?php echo htmlspecialchars($request['pay_to_desc'] ?? 'N/A'); ?>"
                                                            data-payee-name="<?php 
                                                                if ($request['pay_to_type'] == 'vendor') {
                                                                    echo htmlspecialchars($request['vendor_name'] ?? $request['payee_name']);
                                                                } elseif ($request['pay_to_type'] == 'staff') {
                                                                    echo htmlspecialchars($request['staff_name'] ?? $request['payee_name']);
                                                                } elseif ($request['pay_to_type'] == 'customer') {
                                                                    echo htmlspecialchars($request['customer_name'] ?? $request['payee_name']);
                                                                } else {
                                                                    echo htmlspecialchars($request['payee_name']);
                                                                }
                                                            ?>"
                                                            data-requested-by="<?php echo htmlspecialchars($request['requested_by_name'] ?? 'Unknown'); ?>"
                                                            data-ceo-approved-by="<?php echo htmlspecialchars($request['ceo_approved_by_name'] ?? 'Unknown'); ?>"
                                                            data-ceo-approved-date="<?php echo date('M d, Y', strtotime($request['ceo_approved_at'])); ?>"
                                                            data-amount="<?php echo number_format($request['amount_paid'], 2); ?>"
                                                            data-currency="<?php echo htmlspecialchars($request['currency']); ?>"
                                                            data-account-no="<?php echo htmlspecialchars($request['payee_account_no']); ?>"
                                                            data-cheque-no="<?php echo htmlspecialchars($request['cheque_no'] ?? 'N/A'); ?>"
                                                            data-attachments="<?php echo htmlspecialchars($request['attachments'] ?? 'No attachments'); ?>"
                                                            data-notes="<?php echo htmlspecialchars($request['notes'] ?? 'No additional notes'); ?>">
                                                        <i class="bi bi-eye"></i> View
                                                    </button>
                                                    <button type="button" class="btn btn-outline-success process-request-btn" 
                                                            data-bs-toggle="modal" data-bs-target="#processRequestModal"
                                                            data-request-id="<?php echo $request['id']; ?>"
                                                            data-request-no="<?php echo htmlspecialchars($request['request_no']); ?>"
                                                            data-amount="<?php echo $request['amount_paid']; ?>"
                                                            data-currency="<?php echo htmlspecialchars($request['currency']); ?>">
                                                        <i class="bi bi-check-lg"></i> Process
                                                    </button>
                                                    <button type="button" class="btn btn-outline-danger reject-request-btn" 
                                                            data-bs-toggle="modal" data-bs-target="#rejectRequestModal"
                                                            data-request-id="<?php echo $request['id']; ?>"
                                                            data-request-no="<?php echo htmlspecialchars($request['request_no']); ?>">
                                                        <i class="bi bi-x-lg"></i> Reject
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        
                        <?php if ($pending_ceo_approvals > 5): ?>
                            <div class="text-center mt-3">
                                <button type="button" class="btn btn-outline-primary" 
                                        data-bs-toggle="modal" data-bs-target="#allRequestsModal">
                                    View All (<?php echo $pending_ceo_approvals; ?>) Requests
                                </button>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- === ADDED BACK: Enhanced Financial Operations Section === -->
    <div class="row g-4 mb-5">
        <div class="col-12">
            <div class="card dashboard-card">
                <div class="card-header bg-transparent border-0 pb-0">
                    <div class="d-flex align-items-center justify-content-between">
                        <div class="d-flex align-items-center">
                            <div class="me-2">
                                <i class="bi bi-lightning text-primary"></i>
                            </div>
                            <h6 class="mb-0 fw-semibold">Financial Operations</h6>
                        </div>
                        <div class="text-muted small">
                            Quick access to financial management tools
                        </div>
                    </div>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <!-- Transaction Management -->
                        <div class="col-lg-3 col-md-6">
                            <a href="receipt.php?action=add" class="btn btn-primary btn-lg w-100 h-100 d-flex flex-column align-items-center justify-content-center p-4 text-decoration-none">
                                <i class="bi bi-plus-circle mb-2" style="font-size: 2rem;"></i>
                                <div class="fw-semibold">Record Receipt</div>
                                <small class="opacity-75">Cash/Bank incoming</small>
                            </a>
                        </div>
                        <div class="col-lg-3 col-md-6">
                            <a href="payment.php?action=add" class="btn btn-danger btn-lg w-100 h-100 d-flex flex-column align-items-center justify-content-center p-4 text-decoration-none">
                                <i class="bi bi-dash-circle mb-2" style="font-size: 2rem;"></i>
                                <div class="fw-semibold">Record Payment</div>
                                <small class="opacity-75">Cash/Bank outgoing</small>
                            </a>
                        </div>
                        <div class="col-lg-3 col-md-6">
                            <a href="financial_data.php" class="btn btn-outline-secondary btn-lg w-100 h-100 d-flex flex-column align-items-center justify-content-center p-4 text-decoration-none">
                                <i class="bi bi-list mb-2" style="font-size: 2rem;"></i>
                                <div class="fw-semibold">View Ledger</div>
                                <small class="text-muted">Financial history</small>
                            </a>
                        </div>
                        <div class="col-lg-3 col-md-6">
                            <a href="reconciliation.php" class="btn btn-outline-info btn-lg w-100 h-100 d-flex flex-column align-items-center justify-content-center p-4 text-decoration-none">
                                <i class="bi bi-bank mb-2" style="font-size: 2rem;"></i>
                                <div class="fw-semibold">Bank Reconciliation</div>
                                <small class="text-muted">Match records</small>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- === ADDED BACK: Financial Reports Section === -->
    <div class="row g-4 mb-5">
        <div class="col-12">
            <div class="card dashboard-card">
                <div class="card-header bg-transparent border-0 pb-0">
                    <div class="d-flex align-items-center justify-content-between">
                        <div class="d-flex align-items-center">
                            <div class="me-2">
                                <i class="bi bi-file-earmark-text text-success"></i>
                            </div>
                            <h6 class="mb-0 fw-semibold">Financial Reports</h6>
                        </div>
                        <div class="text-muted small">
                            Generate comprehensive financial statements
                        </div>
                    </div>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <!-- Balance Sheet -->
                        <div class="col-lg-3 col-md-6">
                            <div class="card border-0 shadow-sm h-100 report-card">
                                <div class="card-body text-center p-4">
                                    <div class="report-icon mb-3">
                                        <div class="d-inline-flex align-items-center justify-content-center rounded-circle" 
                                             style="width: 70px; height: 70px; background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%);">
                                            <i class="bi bi-balance-scale text-white" style="font-size: 1.8rem;"></i>
                                        </div>
                                    </div>
                                    <h6 class="fw-semibold mb-2">Balance Sheet</h6>
                                    <p class="text-muted small mb-3">
                                        Assets, Liabilities & Equity
                                    </p>
                                    <div class="d-flex justify-content-between small text-muted mb-2">
                                        <span>Accounts:</span>
                                        <span class="fw-bold"><?php echo $balance_sheet_items; ?></span>
                                    </div>
                                    <div class="report-actions">
                                        <a href="balance_sheet.php" class="btn btn-primary btn-sm me-1">
                                            <i class="bi bi-eye me-1"></i>View
                                        </a>
                                        <a href="balance_sheet.php?export=pdf" class="btn btn-outline-primary btn-sm">
                                            <i class="bi bi-download me-1"></i>PDF
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Income Statement -->
                        <div class="col-lg-3 col-md-6">
                            <div class="card border-0 shadow-sm h-100 report-card">
                                <div class="card-body text-center p-4">
                                    <div class="report-icon mb-3">
                                        <div class="d-inline-flex align-items-center justify-content-center rounded-circle" 
                                             style="width: 70px; height: 70px; background: linear-gradient(135deg, #10b981 0%, #047857 100%);">
                                            <i class="bi bi-graph-up-arrow text-white" style="font-size: 1.8rem;"></i>
                                        </div>
                                    </div>
                                    <h6 class="fw-semibold mb-2">Income Statement</h6>
                                    <p class="text-muted small mb-3">
                                        Revenue, Expenses & Profit
                                    </p>
                                    <div class="d-flex justify-content-between small text-muted mb-2">
                                        <span>Accounts:</span>
                                        <span class="fw-bold"><?php echo $income_statement_items; ?></span>
                                    </div>
                                    <div class="report-actions">
                                        <a href="income_statement.php" class="btn btn-success btn-sm me-1">
                                            <i class="bi bi-eye me-1"></i>View
                                        </a>
                                        <a href="income_statement.php?export=pdf" class="btn btn-outline-success btn-sm">
                                            <i class="bi bi-download me-1"></i>PDF
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Cash Flow Statement -->
                        <div class="col-lg-3 col-md-6">
                            <div class="card border-0 shadow-sm h-100 report-card">
                                <div class="card-body text-center p-4">
                                    <div class="report-icon mb-3">
                                        <div class="d-inline-flex align-items-center justify-content-center rounded-circle" 
                                             style="width: 70px; height: 70px; background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);">
                                            <i class="bi bi-cash-coin text-white" style="font-size: 1.8rem;"></i>
                                        </div>
                                    </div>
                                    <h6 class="fw-semibold mb-2">Cash Flow</h6>
                                    <p class="text-muted small mb-3">
                                        Operating, Investing & Financing
                                    </p>
                                    <div class="d-flex justify-content-between small text-muted mb-2">
                                        <span>Components:</span>
                                        <span class="fw-bold"><?php echo $cashflow_components; ?></span>
                                    </div>
                                    <div class="report-actions">
                                        <a href="cashflow_statement.php" class="btn btn-warning btn-sm me-1">
                                            <i class="bi bi-eye me-1"></i>View
                                        </a>
                                        <a href="cashflow_statement.php?export=pdf" class="btn btn-outline-warning btn-sm">
                                            <i class="bi bi-download me-1"></i>PDF
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Trial Balance -->
                        <div class="col-lg-3 col-md-6">
                            <div class="card border-0 shadow-sm h-100 report-card">
                                <div class="card-body text-center p-4">
                                    <div class="report-icon mb-3">
                                        <div class="d-inline-flex align-items-center justify-content-center rounded-circle" 
                                             style="width: 70px; height: 70px; background: linear-gradient(135deg, #8b5cf6 0%, #7c3aed 100%);">
                                            <i class="bi bi-calculator text-white" style="font-size: 1.8rem;"></i>
                                        </div>
                                    </div>
                                    <h6 class="fw-semibold mb-2">Change in Equity</h6>
                                    <p class="text-muted small mb-3">
                                        Account balances at a glance
                                    </p>
                                    <div class="d-flex justify-content-between small text-muted mb-2">
                                        <span>Ready</span>
                                        <span class="fw-bold">✓</span>
                                    </div>
                                    <div class="report-actions">
                                        <a href="equity_statement.php" class="btn btn-info btn-sm me-1">
                                            <i class="bi bi-eye me-1"></i>View
                                        </a>
                                        <a href="trial_balance.php?export=pdf" class="btn btn-outline-info btn-sm">
                                            <i class="bi bi-download me-1"></i>PDF
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Report Management Actions -->
                    <div class="row mt-4">
                        <div class="col-12">
                            <div class="card bg-light border-0">
                                <div class="card-body py-3">
                                    <div class="row g-3">
                                        <div class="col-md-4">
                                            <a href="chart_of_accounts.php" class="btn btn-outline-primary w-100 d-flex align-items-center justify-content-center p-3 text-decoration-none">
                                                <i class="bi bi-list me-2" style="font-size: 1.2rem;"></i>
                                                <div class="text-start">
                                                    <div class="fw-semibold">Chart of Accounts</div>
                                                    <small class="text-muted">Manage accounts</small>
                                                </div>
                                            </a>
                                        </div>
                                        <div class="col-md-4">
                                            <a href="journal_entries.php" class="btn btn-outline-success w-100 d-flex align-items-center justify-content-center p-3 text-decoration-none">
                                                <i class="bi bi-journal-plus me-2" style="font-size: 1.2rem;"></i>
                                                <div class="text-start">
                                                    <div class="fw-semibold">Journal Entries</div>
                                                    <small class="text-muted">Record transactions</small>
                                                </div>
                                            </a>
                                        </div>
                                        <div class="col-md-4">
                                            <a href="reports_dashboard.php" class="btn btn-outline-info w-100 d-flex align-items-center justify-content-center p-3 text-decoration-none">
                                                <i class="bi bi-grid me-2" style="font-size: 1.2rem;"></i>
                                                <div class="text-start">
                                                    <div class="fw-semibold">Reports Dashboard</div>
                                                    <small class="text-muted">All reports overview</small>
                                                </div>
                                            </a>
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

    <!-- View Request Modal -->
    <div class="modal fade" id="viewRequestModal" tabindex="-1" aria-labelledby="viewRequestModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="viewRequestModalLabel">Payment Request Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label text-muted">Request Number</label>
                                <p class="fw-bold" id="view-request-no">-</p>
                            </div>
                            <div class="mb-3">
                                <label class="form-label text-muted">Request Date</label>
                                <p class="fw-bold" id="view-date">-</p>
                            </div>
                            <div class="mb-3">
                                <label class="form-label text-muted">Subject</label>
                                <p class="fw-bold" id="view-subject">-</p>
                            </div>
                            <div class="mb-3">
                                <label class="form-label text-muted">Pay To</label>
                                <p class="fw-bold" id="view-pay-to">-</p>
                            </div>
                            <div class="mb-3">
                                <label class="form-label text-muted">Payee Name</label>
                                <p class="fw-bold" id="view-payee-name">-</p>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label text-muted">Amount</label>
                                <p class="fw-bold text-success" id="view-amount">-</p>
                            </div>
                            <div class="mb-3">
                                <label class="form-label text-muted">Requested By</label>
                                <p class="fw-bold" id="view-requested-by">-</p>
                            </div>
                            <div class="mb-3">
                                <label class="form-label text-muted">CEO Approved By</label>
                                <p class="fw-bold" id="view-ceo-approved-by">-</p>
                            </div>
                            <div class="mb-3">
                                <label class="form-label text-muted">CEO Approval Date</label>
                                <p class="fw-bold" id="view-ceo-approved-date">-</p>
                            </div>
                            <div class="mb-3">
                                <label class="form-label text-muted">Account Number</label>
                                <p class="fw-bold" id="view-account-no">-</p>
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label text-muted">Cheque Number</label>
                                <p class="fw-bold" id="view-cheque-no">-</p>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label text-muted">Attachments</label>
                                <p class="fw-bold" id="view-attachments">-</p>
                            </div>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label text-muted">Additional Notes</label>
                        <div class="border p-3 rounded bg-light">
                            <p class="mb-0" id="view-notes">-</p>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Process Request Modal -->
    <div class="modal fade" id="processRequestModal" tabindex="-1" aria-labelledby="processRequestModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="processRequestModalLabel">Process Payment Request</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form id="processPaymentForm">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        <input type="hidden" name="action" value="approve">
                        <input type="hidden" name="request_id" id="process_request_id">
                        
                        <div class="alert alert-info mb-4">
                            <div class="row">
                                <div class="col-md-6">
                                    <strong>Request #:</strong> <span id="process-request-no">-</span><br>
                                    <strong>Amount:</strong> <span class="text-success fw-bold" id="process-amount">-</span><br>
                                    <strong>Currency:</strong> <span id="process-currency">-</span>
                                </div>
                                <div class="col-md-6 text-end">
                                    <small class="text-muted">Please complete the payment details below</small>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="payment_mode" class="form-label">Payment Mode *</label>
                                <select class="form-select" id="payment_mode" name="payment_mode" required>
                                    <option value="">Select Payment Method</option>
                                    <?php if (!empty($payment_methods)): ?>
                                        <?php foreach ($payment_methods as $method): ?>
                                            <option value="<?php echo $method['id']; ?>">
                                                <?php echo htmlspecialchars($method['description']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <option value="">No payment methods found</option>
                                    <?php endif; ?>
                                </select>
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label for="ac_credit" class="form-label">Bank Account *</label>
                                <select class="form-select" id="ac_credit" name="ac_credit" required>
                                    <option value="">Select Bank Account</option>
                                    <?php if (!empty($bank_accounts)): ?>
                                        <?php foreach ($bank_accounts as $account): ?>
                                            <option value="<?php echo $account['id']; ?>" 
                                                    data-balance="<?php echo $account['current_balance']; ?>"
                                                    data-currency="<?php echo $account['currency']; ?>">
                                                <?php echo htmlspecialchars($account['bank_name'] . ' - ' . $account['account_name'] . ' (' . $account['account_number'] . ')'); ?>
                                                - Balance: <?php echo format_currency($account['current_balance']); ?> <?php echo $account['currency']; ?>
                                            </option>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <option value="">No bank accounts found</option>
                                    <?php endif; ?>
                                </select>
                                <div class="form-text" id="balanceWarning"></div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="record_in_financial" class="form-label">Record in Financial System?</label>
                                <select class="form-select" id="record_in_financial" name="record_in_financial">
                                    <option value="yes">Yes - Record in ledger and update balances</option>
                                    <option value="no">No - Create payment only (manual reconciliation)</option>
                                </select>
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label for="notes" class="form-label">Finance Notes</label>
                                <textarea class="form-control" id="notes" name="notes" rows="2" 
                                          placeholder="Optional notes about this payment..."></textarea>
                            </div>
                        </div>
                        
                        <div class="alert alert-warning">
                            <i class="bi bi-info-circle"></i> 
                            Payment number will be generated automatically upon approval.
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-success" id="confirmProcessBtn">Approve & Create Payment</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Reject Request Modal -->
    <div class="modal fade" id="rejectRequestModal" tabindex="-1" aria-labelledby="rejectRequestModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="rejectRequestModalLabel">Reject Payment Request</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form id="rejectPaymentForm">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        <input type="hidden" name="action" value="reject">
                        <input type="hidden" name="request_id" id="reject_request_id">
                        
                        <div class="mb-3">
                            <p class="text-muted">
                                You are about to reject payment request: <strong id="reject-request-no">-</strong>
                            </p>
                        </div>
                        
                        <div class="mb-3">
                            <label for="rejection_reason" class="form-label">Rejection Reason *</label>
                            <textarea class="form-control" id="rejection_reason" name="rejection_reason" 
                                      rows="4" required placeholder="Please provide a reason for rejecting this payment request..."></textarea>
                        </div>
                        
                        <div class="alert alert-warning">
                            <i class="bi bi-exclamation-triangle"></i> 
                            This action cannot be undone. The requestor will be notified of the rejection.
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-danger" id="confirmRejectBtn">Confirm Rejection</button>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4 mb-5">
        <!-- Pending Approvals -->
        <div class="col-lg-8 mb-4">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h6 class="mb-0"><i class="bi bi-exclamation-triangle text-warning"></i> Pending Receipts & Payments</h6>
                    <span class="badge bg-warning"><?php echo count($pending_transactions); ?></span>
                </div>
                <div class="card-body">
                    <?php if (empty($pending_transactions)): ?>
                        <div class="text-center py-4">
                            <i class="bi bi-check-circle text-success" style="font-size: 3rem;"></i>
                            <p class="text-muted mt-2 mb-0">No pending approvals. All caught up!</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-sm">
                                <thead>
                                    <tr>
                                        <th>Type</th>
                                        <th>Reference</th>
                                        <th>Date</th>
                                        <th>Name</th>
                                        <th>Amount</th>
                                        <th>Action Required</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($pending_transactions as $transaction): ?>
                                        <tr>
                                            <td>
                                                <span class="badge bg-<?php echo $transaction['type'] == 'receipt' ? 'success' : 'danger'; ?>">
                                                    <?php echo ucfirst($transaction['type']); ?>
                                                </span>
                                            </td>
                                            <td><?php echo htmlspecialchars($transaction['reference_number']); ?></td>
                                            <td><?php echo date('d/m/Y', strtotime($transaction['transaction_date'])); ?></td>
                                            <td><?php echo htmlspecialchars($transaction['name']); ?></td>
                                            <td><?php echo format_currency($transaction['amount']); ?> <?php echo htmlspecialchars($transaction['currency']); ?></td>
                                            <td><?php echo htmlspecialchars($transaction['description']); ?></td>
                                            <td>
                                                <?php if ($transaction['type'] == 'receipt'): ?>
                                                <a href="receipt?action=view&id=<?php echo $transaction['id']; ?>" 
                                                   class="btn btn-sm btn-outline-primary">
                                                    <i class="bi bi-eye"></i> Review
                                                </a>
                                                <?php else: ?>
                                                <a href="payment?action=view&id=<?php echo $transaction['id']; ?>" 
                                                   class="btn btn-sm btn-outline-primary">
                                                    <i class="bi bi-eye"></i> Review
                                                </a>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <!-- Monthly Summary & Quick Stats -->
        <div class="col-lg-4 mb-4">
            <!-- Monthly Summary -->
            <div class="card mb-4">
                <div class="card-header">
                    <h6 class="mb-0"><i class="bi bi-calendar-month"></i> This Month Summary</h6>
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <div class="d-flex justify-content-between">
                            <span>Total Revenue:</span>
                            <span class="text-success">+ TSH <?php echo format_currency($stats['monthly_revenue']); ?></span>
                        </div>
                    </div>
                    <div class="mb-3">
                        <div class="d-flex justify-content-between">
                            <span>Total Expenses:</span>
                            <span class="text-danger">- TSH <?php echo format_currency($stats['monthly_expenses']); ?></span>
                        </div>
                    </div>
                    <hr>
                    <div class="d-flex justify-content-between">
                        <strong>Net Position:</strong>
                        <strong class="text-<?php echo $stats['net_position'] >= 0 ? 'success' : 'danger'; ?>">
                            <?php echo $stats['net_position'] >= 0 ? '+' : '-'; ?> TSH <?php echo format_currency(abs($stats['net_position'])); ?>
                        </strong>
                    </div>
                </div>
            </div>

            <!-- System Status -->
            <div class="card">
                <div class="card-header">
                    <h6 class="mb-0"><i class="bi bi-speedometer2"></i> System Status</h6>
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <div class="d-flex justify-content-between align-items-center">
                            <span>Balance Sheet Accounts</span>
                            <span class="badge bg-primary"><?php echo $balance_sheet_items; ?></span>
                        </div>
                    </div>
                    <div class="mb-3">
                        <div class="d-flex justify-content-between align-items-center">
                            <span>Income Statement Accounts</span>
                            <span class="badge bg-success"><?php echo $income_statement_items; ?></span>
                        </div>
                    </div>
                    <div class="mb-3">
                        <div class="d-flex justify-content-between align-items-center">
                            <span>Cash Flow Components</span>
                            <span class="badge bg-warning"><?php echo $cashflow_components; ?></span>
                        </div>
                    </div>
                    <?php 
                    $total_accounts = 0;
                    $total_entries = 0;
                    try {
                        $total_accounts = $db->query("SELECT COUNT(*) as total FROM chart_of_accounts WHERE is_active = 1")->fetch()['total'];
                        $total_entries = $db->query("SELECT COUNT(*) as total FROM general_ledger WHERE status = 'active'")->fetch()['total'];
                    } catch (PDOException $e) {
                        // Silently handle error
                    }
                    ?>
                    <div class="mb-3">
                        <div class="d-flex justify-content-between align-items-center">
                            <span>Chart of Accounts</span>
                            <span class="badge bg-info"><?php echo $total_accounts; ?></span>
                        </div>
                    </div>
                    <div class="mb-3">
                        <div class="d-flex justify-content-between align-items-center">
                            <span>Journal Entries</span>
                            <span class="badge bg-dark"><?php echo $total_entries; ?></span>
                        </div>
                    </div>
                    <hr>
                    <div class="text-center">
                        <a href="chart_of_accounts" class="btn btn-outline-primary btn-sm">
                            <i class="bi bi-pencil me-1"></i>Manage Accounts
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Recent Transactions -->
    <div class="row g-4 mb-5">
        <div class="col-12">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h6 class="mb-0"><i class="bi bi-clock-history"></i> Recent Journal Entries</h6>
                    <a href="general_ledger" class="btn btn-outline-primary btn-sm">View All</a>
                </div>
                <div class="card-body">
                    <?php if (empty($recent_transactions)): ?>
                        <div class="text-center py-4">
                            <i class="bi bi-receipt text-muted" style="font-size: 3rem;"></i>
                            <p class="text-muted mt-2 mb-0">No transactions recorded yet</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Account</th>
                                        <th>Account Name</th>
                                        <th>Type</th>
                                        <th>Debit</th>
                                        <th>Credit</th>
                                        <th>Description</th>
                                        <th>Created By</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recent_transactions as $transaction): ?>
                                        <tr>
                                            <td><?php echo $transaction['transaction_date_display']; ?></td>
                                            <td>
                                                <span class="badge bg-secondary">
                                                    <?php 
                                                    $account_code = 'N/A';
                                                    try {
                                                        $stmt = $db->prepare("SELECT account_code FROM chart_of_accounts WHERE id = ?");
                                                        $stmt->execute([$transaction['account_id']]);
                                                        $result = $stmt->fetch();
                                                        $account_code = $result['account_code'] ?? 'N/A';
                                                    } catch (PDOException $e) {
                                                        // Silently handle error
                                                    }
                                                    echo htmlspecialchars($account_code);
                                                    ?>
                                                </span>
                                            </td>
                                            <td><?php echo htmlspecialchars($transaction['account_name']); ?></td>
                                            <td>
                                                <span class="badge bg-<?php 
                                                    echo $transaction['account_type'] == 'asset' ? 'primary' : 
                                                        ($transaction['account_type'] == 'liability' ? 'warning' : 
                                                        ($transaction['account_type'] == 'equity' ? 'success' : 
                                                        ($transaction['account_type'] == 'income' ? 'info' : 'danger'))); 
                                                ?>">
                                                    <?php echo ucfirst($transaction['account_type']); ?>
                                                </span>
                                            </td>
                                            <td class="text-danger">
                                                <?php if ($transaction['debit_amount'] > 0): ?>
                                                <i class="bi bi-arrow-up-circle me-1"></i>
                                                <?php echo format_currency($transaction['debit_amount']); ?>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-success">
                                                <?php if ($transaction['credit_amount'] > 0): ?>
                                                <i class="bi bi-arrow-down-circle me-1"></i>
                                                <?php echo format_currency($transaction['credit_amount']); ?>
                                                <?php endif; ?>
                                            </td>
                                            <td><?php echo htmlspecialchars(substr($transaction['description'], 0, 50)) . (strlen($transaction['description']) > 50 ? '...' : ''); ?></td>
                                            <td><?php echo htmlspecialchars($transaction['created_by_display']); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
.dashboard-card {
    transition: transform 0.2s ease-in-out, box-shadow 0.2s ease-in-out;
}
.dashboard-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 15px rgba(0,0,0,0.1) !important;
}
.report-card {
    transition: transform 0.2s ease-in-out, box-shadow 0.2s ease-in-out;
}
.report-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 6px 20px rgba(0,0,0,0.15) !important;
}
.stat-number {
    font-size: 1.8rem;
    font-weight: 700;
    line-height: 1.2;
}
.stat-label {
    font-size: 0.9rem;
    opacity: 0.9;
}
.page-header {
    background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%);
    padding: 2rem 0;
    margin: -1rem -1.5rem 2rem -1.5rem;
}
.page-title {
    font-size: 1.8rem;
    font-weight: 700;
    color: #1e293b;
}
.page-subtitle {
    color: #64748b;
    font-size: 0.95rem;
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // View Request Modal Handler
    const viewRequestModal = document.getElementById('viewRequestModal');
    if (viewRequestModal) {
        viewRequestModal.addEventListener('show.bs.modal', function (event) {
            const button = event.relatedTarget;
            
            // Extract data from data-* attributes
            document.getElementById('view-request-no').textContent = button.getAttribute('data-request-no') || '-';
            document.getElementById('view-date').textContent = button.getAttribute('data-date') || '-';
            document.getElementById('view-subject').textContent = button.getAttribute('data-subject') || '-';
            document.getElementById('view-pay-to').textContent = button.getAttribute('data-pay-to') || '-';
            document.getElementById('view-payee-name').textContent = button.getAttribute('data-payee-name') || '-';
            document.getElementById('view-requested-by').textContent = button.getAttribute('data-requested-by') || '-';
            document.getElementById('view-ceo-approved-by').textContent = button.getAttribute('data-ceo-approved-by') || '-';
            document.getElementById('view-ceo-approved-date').textContent = button.getAttribute('data-ceo-approved-date') || '-';
            document.getElementById('view-amount').textContent = (button.getAttribute('data-amount') || '0') + ' ' + (button.getAttribute('data-currency') || '');
            document.getElementById('view-account-no').textContent = button.getAttribute('data-account-no') || '-';
            document.getElementById('view-cheque-no').textContent = button.getAttribute('data-cheque-no') || '-';
            document.getElementById('view-attachments').textContent = button.getAttribute('data-attachments') || '-';
            document.getElementById('view-notes').textContent = button.getAttribute('data-notes') || '-';
        });
    }
    
    // Process Request Modal Handler
    const processRequestModal = document.getElementById('processRequestModal');
    if (processRequestModal) {
        processRequestModal.addEventListener('show.bs.modal', function (event) {
            const button = event.relatedTarget;
            
            // Extract data from data-* attributes
            const requestId = button.getAttribute('data-request-id');
            const requestNo = button.getAttribute('data-request-no');
            const amount = parseFloat(button.getAttribute('data-amount') || 0);
            const currency = button.getAttribute('data-currency') || '';
            
            // Set form values
            document.getElementById('process_request_id').value = requestId;
            document.getElementById('process-request-no').textContent = requestNo;
            document.getElementById('process-amount').textContent = formatCurrency(amount) + ' ' + currency;
            document.getElementById('process-currency').textContent = currency;
            
            // Setup bank account balance validation
            const acCreditSelect = document.getElementById('ac_credit');
            const balanceWarning = document.getElementById('balanceWarning');
            
            // Clear previous validation
            balanceWarning.innerHTML = '';
            
            // Add event listener for balance validation
            acCreditSelect.addEventListener('change', function() {
                const selectedOption = this.options[this.selectedIndex];
                const balance = parseFloat(selectedOption.getAttribute('data-balance') || 0);
                const accountCurrency = selectedOption.getAttribute('data-currency') || '';
                
                // Check if currency matches
                if (accountCurrency !== currency) {
                    balanceWarning.innerHTML = `<span class="text-warning">
                        <i class="bi bi-exclamation-triangle"></i> 
                        Currency mismatch! Request: ${currency}, Account: ${accountCurrency}
                    </span>`;
                } else if (balance < amount && document.getElementById('record_in_financial').value === 'yes') {
                    balanceWarning.innerHTML = `<span class="text-danger">
                        <i class="bi bi-exclamation-triangle"></i> 
                        Insufficient balance! Available: ${formatCurrency(balance)} ${accountCurrency}
                    </span>`;
                } else {
                    balanceWarning.innerHTML = `<span class="text-success">
                        <i class="bi bi-check-circle"></i> 
                        Sufficient balance available: ${formatCurrency(balance)} ${accountCurrency}
                    </span>`;
                }
            });
            
            // Validate on record_in_financial change
            document.getElementById('record_in_financial').addEventListener('change', function() {
                if (acCreditSelect.value) {
                    acCreditSelect.dispatchEvent(new Event('change'));
                }
            });
            
            // Trigger change event if a bank account is already selected
            if (acCreditSelect.value) {
                acCreditSelect.dispatchEvent(new Event('change'));
            }
        });
    }
    
    // Reject Request Modal Handler
    const rejectRequestModal = document.getElementById('rejectRequestModal');
    if (rejectRequestModal) {
        rejectRequestModal.addEventListener('show.bs.modal', function (event) {
            const button = event.relatedTarget;
            
            // Extract data from data-* attributes
            const requestId = button.getAttribute('data-request-id');
            const requestNo = button.getAttribute('data-request-no');
            
            // Set form values
            document.getElementById('reject_request_id').value = requestId;
            document.getElementById('reject-request-no').textContent = requestNo;
        });
    }
    
    // Confirm Process Button
    document.getElementById('confirmProcessBtn').addEventListener('click', function() {
        const form = document.getElementById('processPaymentForm');
        const formData = new FormData(form);
        
        // Validate
        if (!formData.get('payment_mode') || !formData.get('ac_credit')) {
            alert('Please select both payment mode and bank account.');
            return;
        }
        
        if (confirm('Are you sure you want to approve and create this payment?')) {
            this.disabled = true;
            this.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Processing...';
            
            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert(data.message);
                    location.reload();
                } else {
                    alert('Error: ' + data.message);
                    this.disabled = false;
                    this.innerHTML = 'Approve & Create Payment';
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Error processing request. Please try again.');
                this.disabled = false;
                this.innerHTML = 'Approve & Create Payment';
            });
        }
    });
    
    // Confirm Reject Button
    document.getElementById('confirmRejectBtn').addEventListener('click', function() {
        const form = document.getElementById('rejectPaymentForm');
        const formData = new FormData(form);
        
        if (!formData.get('rejection_reason')) {
            alert('Please provide a rejection reason.');
            return;
        }
        
        if (confirm('Are you sure you want to reject this payment request?')) {
            this.disabled = true;
            this.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Processing...';
            
            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert(data.message);
                    location.reload();
                } else {
                    alert('Error: ' + data.message);
                    this.disabled = false;
                    this.innerHTML = 'Confirm Rejection';
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Error processing request. Please try again.');
                this.disabled = false;
                this.innerHTML = 'Confirm Rejection';
            });
        }
    });
    
    // Utility function to format currency
    function formatCurrency(amount) {
        return new Intl.NumberFormat('en-US', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        }).format(amount);
    }
    
    // Auto-refresh dashboard every 5 minutes
    setTimeout(function() {
        location.reload();
    }, 300000); // 5 minutes
});
</script>

<?php include '../includes/footer.php'; ?>