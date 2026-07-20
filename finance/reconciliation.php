<?php
require_once '../config/config.php';
require_once '../auth/auth_middleware.php';

require_finance_officer();

$db = getDBConnection();

// Get reconciliation data
$month = isset($_GET['month']) ? $_GET['month'] : date('Y-m');
$start_date = $month . '-01';
$end_date = date('Y-m-t', strtotime($start_date));
$selected_bank_id = isset($_GET['bank_account']) ? $_GET['bank_account'] : null;
$is_cash_selected = ($selected_bank_id === 'cash');

// Pagination configuration
$per_page = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 50;
if ($per_page < 0) $per_page = 50;
$show_all = ($per_page === 0);
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) $page = 1;

// Get company info for currency
$company_stmt = $db->prepare("SELECT * FROM companies WHERE status = 'active' ORDER BY id ASC LIMIT 1");
$company_stmt->execute();
$company = $company_stmt->fetch(PDO::FETCH_ASSOC) ?? ['currency' => 'TZS'];

// Get all bank accounts with GL account information
$bank_accounts_stmt = $db->prepare("
    SELECT 
        ba.*,
        ba.id as bank_account_id,
        ba.account_name as bank_account_name,
        ba.account_number,
        ba.bank_name,
        ba.current_balance,
        ba.available_balance,
        ba.code as gl_account_code,
        coa.id as gl_account_id,
        coa.account_name as gl_account_name
    FROM banks_accounts ba
    LEFT JOIN chart_of_accounts coa ON coa.account_code COLLATE utf8mb4_general_ci = ba.code
    WHERE ba.status = 'active'
    ORDER BY ba.bank_name, ba.account_name
");
$bank_accounts_stmt->execute();
$bank_accounts = $bank_accounts_stmt->fetchAll(PDO::FETCH_ASSOC);

// If no bank selected and not cash, use first active bank account
if (!$selected_bank_id && !$is_cash_selected && count($bank_accounts) > 0) {
    $selected_bank_id = $bank_accounts[0]['id'];
    $is_cash_selected = false;
}

// Get selected bank account details
$selected_bank = null;
$selected_gl_account_id = null;
$selected_gl_account_code = null;
$selected_gl_account_name = null;

if ($is_cash_selected) {
    // Get cash GL account (1111 - Cash on Hand)
    $cash_gl_stmt = $db->prepare("
        SELECT id, account_code, account_name 
        FROM chart_of_accounts 
        WHERE account_code = '1111'
        AND status = 'active'
        LIMIT 1
    ");
    $cash_gl_stmt->execute();
    $cash_gl_account = $cash_gl_stmt->fetch(PDO::FETCH_ASSOC);
    
    // Create cash account object
    $selected_bank = [
        'id' => 'cash',
        'bank_name' => 'CASH',
        'bank_account_name' => 'Petty Cash',
        'account_number' => '',
        'current_balance' => 0,
        'available_balance' => 0,
        'account_type' => 'cash',
        'code' => '1111',
        'gl_account_code' => '1111',
        'gl_account_name' => 'Cash on Hand'
    ];
    $selected_gl_account_id = $cash_gl_account['id'] ?? null;
    $selected_gl_account_code = '1111';
    $selected_gl_account_name = 'Cash on Hand';
} else if ($selected_bank_id) {
    foreach ($bank_accounts as $bank) {
        if ($bank['id'] == $selected_bank_id) {
            $selected_bank = $bank;
            $selected_gl_account_id = $bank['gl_account_id'] ?? null;
            $selected_gl_account_code = $bank['gl_account_code'] ?? null;
            $selected_gl_account_name = $bank['gl_account_name'] ?? null;
            break;
        }
    }
}

// Get opening bank balance (from general ledger for this account)
$opening_balance = 0;
if ($selected_gl_account_id) {
    $opening_balance_stmt = $db->prepare("
        SELECT COALESCE((
            SELECT SUM(debit_amount - credit_amount)
            FROM general_ledger 
            WHERE account_id = ? 
            AND status = 'active'
            AND DATE(transaction_date) < ?
        ), 0) as opening_balance
    ");
    $opening_balance_stmt->execute([$selected_gl_account_id, $start_date]);
    $opening_balance_result = $opening_balance_stmt->fetch(PDO::FETCH_ASSOC);
    $opening_balance = $opening_balance_result['opening_balance'] ?? 0;
}

// Get ALL transactions for the selected bank account
$all_transactions = [];
$total_debits = 0;
$total_credits = 0;
$cleared_transactions = 0;
$uncleared_transactions = 0;

if ($selected_bank_id && !$is_cash_selected) {
    // For BANK ACCOUNTS: Get Payments and Receipts
    
    // Get PAYMENTS from this bank account (money going OUT)
    $payments_stmt = $db->prepare("
        SELECT 
            'payment' as source_type,
            p.id,
            p.payment_date as transaction_date,
            p.payment_no as reference,
            p.narration as description,
            'Payment' as type,
            p.amount as credit_amount,
            0 as debit_amount,
            p.cheque_no,
            p.payment_no as reference_no,
            'payment' as reference_type,
            p.payment_mode,
            pm.description as payment_method,
            p.created_at,
            'Bank Withdrawal' as transaction_desc,
            p.name as payee_name,
            b.bank_name,
            b.account_name,
            b.account_number
        FROM payments p
        LEFT JOIN payment_methods pm ON p.payment_mode = pm.id
        LEFT JOIN banks_accounts b ON p.ac_credit = b.id
        WHERE p.ac_credit = ?
        AND p.status = 'active'
        AND DATE(p.payment_date) BETWEEN ? AND ?
        AND (p.record_in_financial = 'yes' OR p.record_in_financial IS NULL)
        ORDER BY p.payment_date, p.created_at
    ");
    $payments_stmt->execute([$selected_bank_id, $start_date, $end_date]);
    $payments = $payments_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get RECEIPTS to this bank account (money coming IN)
    $receipts_stmt = $db->prepare("
        SELECT 
            'receipt' as source_type,
            r.id,
            r.receipt_date as transaction_date,
            r.receipt_no as reference,
            r.narration as description,
            'Receipt' as type,
            0 as credit_amount,
            r.amount as debit_amount,
            r.cheque_no,
            r.receipt_no as reference_no,
            'receipt' as reference_type,
            r.payment_mode,
            pm.description as payment_method,
            r.created_at,
            'Bank Deposit' as transaction_desc,
            r.name as payer_name,
            b.bank_name,
            b.account_name,
            b.account_number
        FROM receipts r
        LEFT JOIN payment_methods pm ON r.payment_mode = pm.id
        LEFT JOIN banks_accounts b ON r.ac_debit = b.id
        WHERE r.ac_debit = ?
        AND r.status = 'active'
        AND DATE(r.receipt_date) BETWEEN ? AND ?
        AND (r.record_in_financial = 'yes' OR r.record_in_financial IS NULL)
        ORDER BY r.receipt_date, r.created_at
    ");
    $receipts_stmt->execute([$selected_bank_id, $start_date, $end_date]);
    $receipts = $receipts_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get GL transactions for this account (if any direct GL entries)
    $gl_transactions = [];
    if ($selected_gl_account_id) {
        $gl_stmt = $db->prepare("
            SELECT 
                'gl_entry' as source_type,
                gl.id,
                gl.transaction_date,
                gl.reference_no as reference,
                gl.description,
                CASE 
                    WHEN gl.debit_amount > 0 THEN 'Debit'
                    WHEN gl.credit_amount > 0 THEN 'Credit'
                    ELSE 'Adjustment'
                END as type,
                gl.credit_amount,
                gl.debit_amount,
                gl.cheque_no,
                gl.reference_no,
                gl.reference_type,
                '' as payment_method,
                gl.created_at,
                'GL Entry' as transaction_desc,
                '' as payer_payee_name,
                '' as bank_name,
                '' as account_name,
                '' as account_number
            FROM general_ledger gl
            WHERE gl.account_id = ?
            AND gl.status = 'active'
            AND DATE(gl.transaction_date) BETWEEN ? AND ?
            ORDER BY gl.transaction_date, gl.created_at
        ");
        $gl_stmt->execute([$selected_gl_account_id, $start_date, $end_date]);
        $gl_transactions = $gl_stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // Combine all transactions
    $all_transactions = array_merge($receipts, $payments, $gl_transactions);
    
} else if ($is_cash_selected && $selected_gl_account_id) {
    // For CASH: Get GL transactions for cash account
    $cash_stmt = $db->prepare("
        SELECT 
            'gl_entry' as source_type,
            gl.id,
            gl.transaction_date,
            gl.reference_no as reference,
            gl.description,
            CASE 
                WHEN gl.debit_amount > 0 THEN 'Cash In'
                WHEN gl.credit_amount > 0 THEN 'Cash Out'
                ELSE 'Adjustment'
            END as type,
            gl.credit_amount,
            gl.debit_amount,
            gl.cheque_no,
            gl.reference_no,
            gl.reference_type,
            '' as payment_method,
            gl.created_at,
            'Cash Transaction' as transaction_desc,
            '' as payer_payee_name,
            'CASH' as bank_name,
            'Petty Cash' as account_name,
            '' as account_number
        FROM general_ledger gl
        WHERE gl.account_id = ?
        AND gl.status = 'active'
        AND DATE(gl.transaction_date) BETWEEN ? AND ?
        ORDER BY gl.transaction_date, gl.created_at
    ");
    $cash_stmt->execute([$selected_gl_account_id, $start_date, $end_date]);
    $all_transactions = $cash_stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Sort all transactions by date
usort($all_transactions, function($a, $b) {
    $dateCompare = strtotime($a['transaction_date']) - strtotime($b['transaction_date']);
    if ($dateCompare === 0) {
        return strtotime($a['created_at']) - strtotime($b['created_at']);
    }
    return $dateCompare;
});

// Calculate totals and categorize
$receipts_total = 0;
$payments_total = 0;
$gl_debits_total = 0;
$gl_credits_total = 0;

foreach ($all_transactions as $transaction) {
    // For bank accounts: Receipts are debits, Payments are credits
    if ($transaction['source_type'] === 'receipt') {
        $total_debits += $transaction['debit_amount'];
        $receipts_total += $transaction['debit_amount'];
    } elseif ($transaction['source_type'] === 'payment') {
        $total_credits += $transaction['credit_amount'];
        $payments_total += $transaction['credit_amount'];
    } else {
        // GL entries
        if ($transaction['debit_amount'] > 0) {
            $total_debits += $transaction['debit_amount'];
            $gl_debits_total += $transaction['debit_amount'];
        }
        if ($transaction['credit_amount'] > 0) {
            $total_credits += $transaction['credit_amount'];
            $gl_credits_total += $transaction['credit_amount'];
        }
    }
    
    // Check if transaction has cheque/reference number
    if (!empty($transaction['cheque_no']) && trim($transaction['cheque_no']) !== '') {
        $cleared_transactions++;
    } else {
        $uncleared_transactions++;
    }
}

$total_transaction_count = count($all_transactions);
$total_pages = $per_page > 0 ? (int)ceil($total_transaction_count / $per_page) : 1;
if ($total_pages < 1) $total_pages = 1;
if ($page > $total_pages) $page = $total_pages;
$offset = $show_all ? 0 : ($page - 1) * $per_page;

// Calculate starting balance for this page
$page_starting_balance = $opening_balance;
for ($i = 0; $i < $offset && $i < count($all_transactions); $i++) {
    $t = $all_transactions[$i];
    if ($t['source_type'] === 'receipt') {
        $page_starting_balance += $t['debit_amount'];
    } elseif ($t['source_type'] === 'payment') {
        $page_starting_balance -= $t['credit_amount'];
    } else {
        $page_starting_balance += $t['debit_amount'] - $t['credit_amount'];
    }
}

// Get only current page transactions for display
if ($show_all) {
    $display_transactions = $all_transactions;
} else {
    $display_transactions = array_slice($all_transactions, $offset, $per_page);
}

// Calculate GL total movement for the period
$gl_total_movement = ($total_debits - $total_credits);

// Calculate expected GL balance
$expected_gl_balance = $opening_balance + $gl_total_movement;

// Get current balance from bank accounts table
if ($is_cash_selected) {
    // For cash, use the GL balance as physical cash should match
    $bank_current_balance = $expected_gl_balance;
} else if ($selected_bank) {
    $bank_current_balance = $selected_bank['current_balance'] ?? 0;
} else {
    $bank_current_balance = 0;
}

// Calculate difference
$difference = $bank_current_balance - $expected_gl_balance;

// Calculate reconciliation status
$reconciled = (abs($difference) < 0.01) && ($uncleared_transactions == 0);
$reconciliation_status = $reconciled ? 'Reconciled' : 'Unreconciled';

$page_title = 'Bank Reconciliation';
include '../includes/header.php';
?>

<style>
/* Clean, simple styling */
.card {
    border: 1px solid #e0e0e0;
    border-radius: 6px;
    margin-bottom: 20px;
}

.card-header {
    background-color: #f8f9fa;
    border-bottom: 1px solid #e0e0e0;
    padding: 12px 15px;
}

.card-body {
    padding: 15px;
}

.table {
    font-size: 0.9rem;
}

.table th {
    font-weight: 600;
    border-bottom: 2px solid #dee2e6;
}

.table td {
    border-top: 1px solid #f0f0f0;
}

.table-hover tbody tr:hover {
    background-color: #f8f9fa;
}

.badge {
    padding: 4px 8px;
    font-size: 0.75rem;
    font-weight: 500;
}

.bg-success {
    background-color: #28a745 !important;
}

.bg-danger {
    background-color: #dc3545 !important;
}

.bg-warning {
    background-color: #ffc107 !important;
    color: #212529 !important;
}

.bg-info {
    background-color: #17a2b8 !important;
}

.bg-secondary {
    background-color: #6c757d !important;
}

.bg-primary {
    background-color: #007bff !important;
}

.text-success {
    color: #28a745 !important;
}

.text-danger {
    color: #dc3545 !important;
}

.text-primary {
    color: #007bff !important;
}

.text-muted {
    color: #6c757d !important;
}

.form-control {
    border: 1px solid #ced4da;
    border-radius: 4px;
}

.btn {
    border-radius: 4px;
    padding: 6px 12px;
    font-size: 0.875rem;
}

.btn-primary {
    background-color: #007bff;
    border-color: #007bff;
}

.btn-outline-secondary {
    border-color: #6c757d;
    color: #6c757d;
}

.btn-outline-secondary:hover {
    background-color: #6c757d;
    color: white;
}

.alert {
    border-radius: 4px;
    padding: 12px 15px;
    margin-bottom: 15px;
}

.alert-info {
    background-color: #d1ecf1;
    border-color: #bee5eb;
    color: #0c5460;
}

.alert-warning {
    background-color: #fff3cd;
    border-color: #ffeaa7;
    color: #856404;
}

.alert-success {
    background-color: #d4edda;
    border-color: #c3e6cb;
    color: #155724;
}

.balance-card {
    background: #f8f9fa;
    border-left: 4px solid #007bff;
    padding: 15px;
    border-radius: 4px;
}

.balance-label {
    font-size: 0.85rem;
    color: #6c757d;
    margin-bottom: 5px;
}

.balance-value {
    font-size: 1.25rem;
    font-weight: 600;
    color: #212529;
}

.difference-positive {
    color: #28a745;
}

.difference-negative {
    color: #dc3545;
}

.reference-badge {
    display: inline-block;
    padding: 2px 6px;
    font-size: 0.7rem;
    background-color: #e9ecef;
    border-radius: 3px;
    font-family: monospace;
}

.type-badge {
    display: inline-block;
    padding: 3px 8px;
    font-size: 0.75rem;
    border-radius: 4px;
    font-weight: 500;
}

.type-receipt {
    background-color: #d4edda;
    color: #155724;
}

.type-payment {
    background-color: #f8d7da;
    color: #721c24;
}

.type-cash-in {
    background-color: #cce5ff;
    color: #004085;
}

.type-cash-out {
    background-color: #fff3cd;
    color: #856404;
}

.source-badge {
    display: inline-block;
    padding: 2px 6px;
    font-size: 0.7rem;
    border-radius: 3px;
    margin-right: 5px;
}

.source-receipt {
    background-color: #d1ecf1;
    color: #0c5460;
}

.source-payment {
    background-color: #f8d7da;
    color: #721c24;
}

.source-gl {
    background-color: #e2e3e5;
    color: #383d41;
}

.progress {
    height: 6px;
    background-color: #e9ecef;
    border-radius: 3px;
    overflow: hidden;
}

.progress-bar {
    background-color: #28a745;
}

.summary-card {
    text-align: center;
    padding: 15px;
    background: #f8f9fa;
    border-radius: 6px;
    border: 1px solid #e0e0e0;
}

.summary-value {
    font-size: 1.5rem;
    font-weight: 600;
    margin-bottom: 5px;
}

.summary-label {
    font-size: 0.85rem;
    color: #6c757d;
}

.gl-account-info {
    background-color: #e7f3ff;
    border-left: 4px solid #007bff;
    padding: 10px 15px;
    margin: 10px 0;
    border-radius: 4px;
    font-size: 0.875rem;
}

.transaction-breakdown {
    background-color: #f8f9fa;
    border-radius: 6px;
    padding: 15px;
    margin: 15px 0;
}

.breakdown-item {
    display: flex;
    justify-content: space-between;
    padding: 8px 0;
    border-bottom: 1px solid #e9ecef;
}

.breakdown-item:last-child {
    border-bottom: none;
}

.breakdown-label {
    font-weight: 500;
}

.breakdown-value {
    font-weight: 600;
}
</style>

<div class="container-fluid py-3">
    <!-- Header -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h2 class="mb-1">Bank Reconciliation</h2>
                    <p class="text-muted mb-0">Reconcile bank accounts with Payments, Receipts, and General Ledger</p>
                </div>
                <a href="../finance/dashboard.php" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left"></i> Back to Dashboard
                </a>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <div class="card mb-4">
        <div class="card-body">
            <form method="GET" action="" class="row g-3 align-items-end">
                <div class="col-md-4">
                    <label for="bank_account" class="form-label">Select Account</label>
                    <select class="form-control" id="bank_account" name="bank_account" required>
                        <option value="">-- Select Account --</option>
                        <option value="cash" <?php echo $is_cash_selected ? 'selected' : ''; ?>>
                            💵 CASH - Petty Cash (1111)
                        </option>
                        <?php foreach ($bank_accounts as $bank): ?>
                            <option value="<?php echo $bank['id']; ?>" 
                                <?php echo (!$is_cash_selected && $selected_bank_id == $bank['id']) ? 'selected' : ''; ?>>
                                🏦 <?php echo htmlspecialchars($bank['bank_name']); ?> - 
                                <?php echo htmlspecialchars($bank['account_name']); ?> 
                                (<?php echo htmlspecialchars($bank['account_number']); ?>)
                                <?php if ($bank['gl_account_code']): ?> - GL: <?php echo htmlspecialchars($bank['gl_account_code']); ?><?php endif; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label for="month" class="form-label">Month</label>
                    <input type="month" class="form-control" id="month" name="month" 
                           value="<?php echo $month; ?>" required>
                </div>
                <div class="col-md-2">
                    <label for="per_page" class="form-label">Per Page</label>
                    <select class="form-control" id="per_page" name="per_page" onchange="this.form.submit()">
                        <option value="20" <?php echo $per_page == 20 ? 'selected' : ''; ?>>20</option>
                        <option value="50" <?php echo $per_page == 50 ? 'selected' : ''; ?>>50</option>
                        <option value="100" <?php echo $per_page == 100 ? 'selected' : ''; ?>>100</option>
                        <option value="200" <?php echo $per_page == 200 ? 'selected' : ''; ?>>200</option>
                        <option value="0" <?php echo $per_page == 0 ? 'selected' : ''; ?>>All</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-primary" style="margin-top: 24px;">
                        <i class="bi bi-search"></i> Load
                    </button>
                </div>
                <div class="col-md-2 text-end">
                    <button type="button" class="btn btn-outline-secondary" onclick="exportToCSV()" style="margin-top: 24px;">
                        <i class="bi bi-download"></i> Export
                    </button>
                </div>
            </form>
        </div>
    </div>

    <?php if ($selected_bank || $is_cash_selected): ?>
        <?php if (!$selected_gl_account_id && !$is_cash_selected): ?>
        <div class="alert alert-warning">
            <i class="bi bi-exclamation-triangle"></i> 
            <strong>Warning:</strong> This bank account is not linked to a GL account. 
            Some GL transactions may not appear.
        </div>
        <?php endif; ?>
        
        <!-- Account Information -->
        <div class="row mb-4">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-4">
                                <h6 class="mb-2">Account Details</h6>
                                <p class="mb-1">
                                    <strong>Account:</strong> 
                                    <?php if ($is_cash_selected): ?>
                                        CASH - Petty Cash
                                    <?php else: ?>
                                        <?php echo htmlspecialchars($selected_bank['bank_name']); ?> - 
                                        <?php echo htmlspecialchars($selected_bank['account_name']); ?>
                                    <?php endif; ?>
                                </p>
                                <?php if (!$is_cash_selected): ?>
                                <p class="mb-1">
                                    <strong>Account No:</strong> 
                                    <?php echo htmlspecialchars($selected_bank['account_number']); ?>
                                </p>
                                <?php endif; ?>
                                <?php if ($selected_gl_account_code): ?>
                                <p class="mb-0">
                                    <strong>GL Account:</strong> 
                                    <?php echo htmlspecialchars($selected_gl_account_code); ?> - 
                                    <?php echo htmlspecialchars($selected_gl_account_name); ?>
                                </p>
                                <?php endif; ?>
                            </div>
                            <div class="col-md-4">
                                <h6 class="mb-2">Period</h6>
                                <p class="mb-1"><strong>Month:</strong> <?php echo date('F Y', strtotime($start_date)); ?></p>
                                <p class="mb-1"><strong>Period:</strong> <?php echo date('d/m/Y', strtotime($start_date)); ?> to <?php echo date('d/m/Y', strtotime($end_date)); ?></p>
                                <p class="mb-0">
                                    <strong>Status:</strong> 
                                    <span class="badge bg-<?php echo $reconciled ? 'success' : 'warning'; ?>">
                                        <?php echo $reconciliation_status; ?>
                                    </span>
                                </p>
                            </div>
                            <div class="col-md-4">
                                <h6 class="mb-2">Transaction Summary</h6>
                                <p class="mb-1">
                                    <strong>Total Transactions:</strong> 
                                    <?php echo $total_transaction_count; ?>
                                </p>
                                <p class="mb-1">
                                    <strong>With Reference:</strong> 
                                    <?php echo $cleared_transactions; ?> / <?php echo $total_transaction_count; ?>
                                </p>
                                <p class="mb-0">
                                    <strong>Data Sources:</strong> 
                                    <?php 
                                    $sources = [];
                                    if (isset($receipts) && count($receipts) > 0) $sources[] = 'Receipts';
                                    if (isset($payments) && count($payments) > 0) $sources[] = 'Payments';
                                    if (isset($gl_transactions) && count($gl_transactions) > 0) $sources[] = 'GL Entries';
                                    echo implode(', ', $sources);
                                    ?>
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Balance Summary -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="summary-card">
                    <div class="summary-value">
                        <?php echo htmlspecialchars($company['currency']); ?> 
                        <?php echo number_format($opening_balance, 2); ?>
                    </div>
                    <div class="summary-label">Opening Balance</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="summary-card">
                    <div class="summary-value text-success">
                        <?php echo htmlspecialchars($company['currency']); ?> 
                        <?php echo number_format($total_debits, 2); ?>
                    </div>
                    <div class="summary-label">Total Deposits/Inflows</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="summary-card">
                    <div class="summary-value text-danger">
                        <?php echo htmlspecialchars($company['currency']); ?> 
                        <?php echo number_format($total_credits, 2); ?>
                    </div>
                    <div class="summary-label">Total Withdrawals/Outflows</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="summary-card">
                    <div class="summary-value <?php echo $difference > 0 ? 'difference-positive' : ($difference < 0 ? 'difference-negative' : ''); ?>">
                        <?php echo htmlspecialchars($company['currency']); ?> 
                        <?php echo number_format(abs($difference), 2); ?>
                        <?php if ($difference > 0): ?>+
                        <?php elseif ($difference < 0): ?>-
                        <?php endif; ?>
                    </div>
                    <div class="summary-label">Difference</div>
                </div>
            </div>
        </div>

        <!-- Transaction Breakdown -->
        <?php if (isset($receipts) || isset($payments) || isset($gl_transactions)): ?>
        <div class="transaction-breakdown mb-4">
            <h6 class="mb-3">Transaction Breakdown by Source</h6>
            <div class="row">
                <?php if (isset($receipts) && count($receipts) > 0): ?>
                <div class="col-md-4">
                    <div class="breakdown-item">
                        <span class="breakdown-label">Receipts (Deposits):</span>
                        <span class="breakdown-value text-success">
                            <?php echo htmlspecialchars($company['currency']); ?> 
                            <?php echo number_format($receipts_total, 2); ?>
                        </span>
                    </div>
                    <small class="text-muted"><?php echo count($receipts); ?> records</small>
                </div>
                <?php endif; ?>
                
                <?php if (isset($payments) && count($payments) > 0): ?>
                <div class="col-md-4">
                    <div class="breakdown-item">
                        <span class="breakdown-label">Payments (Withdrawals):</span>
                        <span class="breakdown-value text-danger">
                            <?php echo htmlspecialchars($company['currency']); ?> 
                            <?php echo number_format($payments_total, 2); ?>
                        </span>
                    </div>
                    <small class="text-muted"><?php echo count($payments); ?> records</small>
                </div>
                <?php endif; ?>
                
                <?php if (isset($gl_transactions) && count($gl_transactions) > 0): ?>
                <div class="col-md-4">
                    <div class="breakdown-item">
                        <span class="breakdown-label">GL Adjustments:</span>
                        <span class="breakdown-value <?php echo ($gl_debits_total - $gl_credits_total) >= 0 ? 'text-success' : 'text-danger'; ?>">
                            <?php echo htmlspecialchars($company['currency']); ?> 
                            <?php echo number_format($gl_debits_total - $gl_credits_total, 2); ?>
                        </span>
                    </div>
                    <small class="text-muted"><?php echo count($gl_transactions); ?> records</small>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Reconciliation Status -->
        <div class="row mb-4">
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <h6 class="mb-0">Balance Reconciliation</h6>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="balance-card mb-3">
                                    <div class="balance-label">
                                        <?php if ($is_cash_selected): ?>
                                            Physical Cash Count
                                        <?php else: ?>
                                            Bank Statement Balance
                                        <?php endif; ?>
                                    </div>
                                    <div class="balance-value">
                                        <?php echo htmlspecialchars($company['currency']); ?> 
                                        <?php echo number_format($bank_current_balance, 2); ?>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="balance-card mb-3">
                                    <div class="balance-label">System Balance (GL + Payments + Receipts)</div>
                                    <div class="balance-value">
                                        <?php echo htmlspecialchars($company['currency']); ?> 
                                        <?php echo number_format($expected_gl_balance, 2); ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php if ($difference != 0): ?>
                        <div class="alert alert-warning">
                            <i class="bi bi-exclamation-triangle"></i>
                            <strong>Difference Found:</strong> 
                            <?php echo htmlspecialchars($company['currency']); ?> 
                            <?php echo number_format(abs($difference), 2); ?>
                            <?php if ($difference > 0): ?>
                                (<?php echo $is_cash_selected ? 'Cash > System' : 'Bank > System'; ?>)
                            <?php else: ?>
                                (<?php echo $is_cash_selected ? 'Cash < System' : 'Bank < System'; ?>)
                            <?php endif; ?>
                            <br><small>Check for unrecorded transactions, bank charges, or interest.</small>
                        </div>
                        <?php else: ?>
                        <div class="alert alert-success">
                            <i class="bi bi-check-circle"></i>
                            <strong>Reconciliation Successful!</strong> 
                            <?php echo $is_cash_selected ? 'Cash' : 'Bank'; ?> statement and system records match.
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <h6 class="mb-0">Transaction Status</h6>
                    </div>
                    <div class="card-body">
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <div class="text-center">
                                    <div class="summary-value text-success"><?php echo $cleared_transactions; ?></div>
                                    <div class="summary-label">With Reference</div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="text-center">
                                    <div class="summary-value text-warning"><?php echo $uncleared_transactions; ?></div>
                                    <div class="summary-label">Without Reference</div>
                                </div>
                            </div>
                        </div>
                        <div class="progress mb-3">
                            <?php 
                            $cleared_percentage = $total_transaction_count > 0 ? ($cleared_transactions / $total_transaction_count) * 100 : 0;
                            ?>
                            <div class="progress-bar" style="width: <?php echo $cleared_percentage; ?>%"></div>
                        </div>
                        <p class="text-muted mb-0">
                            <small><?php echo number_format($cleared_percentage, 1); ?>% of transactions have reference numbers</small>
                        </p>
                        <div class="mt-3">
                            <span class="source-badge source-receipt">Receipt</span>
                            <span class="source-badge source-payment">Payment</span>
                            <span class="source-badge source-gl">GL Entry</span>
                            <small class="text-muted">Transaction sources</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- All Transactions Table -->
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h6 class="mb-0">
                    <i class="bi bi-list-ul"></i> All Transactions - <?php echo date('F Y', strtotime($start_date)); ?>
                    <span class="badge bg-secondary ms-2"><?php echo $total_transaction_count; ?> records</span>
                    <?php 
                    $start_record = $show_all ? 1 : $offset + 1;
                    $end_record = $show_all ? $total_transaction_count : min($offset + $per_page, $total_transaction_count);
                    if ($total_transaction_count > 0): ?>
                        <small class="text-muted ms-2">
                            (Showing <?php echo $start_record; ?>-<?php echo $end_record; ?>)
                        </small>
                    <?php endif; ?>
                </h6>
                <div class="d-flex align-items-center gap-2">
                    <?php if ($total_pages > 1): ?>
                        <nav aria-label="Page navigation" class="me-2">
                            <ul class="pagination pagination-sm mb-0">
                                <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                                    <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => 1])); ?>">&laquo;</a>
                                </li>
                                <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                                    <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => max(1, $page - 1)])); ?>">&lsaquo;</a>
                                </li>
                                <?php 
                                $start_p = max(1, $page - 2);
                                $end_p = min($total_pages, $page + 2);
                                for ($i = $start_p; $i <= $end_p; $i++): 
                                ?>
                                    <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                                        <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>"><?php echo $i; ?></a>
                                    </li>
                                <?php endfor; ?>
                                <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                                    <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => min($total_pages, $page + 1)])); ?>">&rsaquo;</a>
                                </li>
                                <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                                    <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $total_pages])); ?>">&raquo;</a>
                                </li>
                            </ul>
                        </nav>
                    <?php endif; ?>
                    <span class="badge bg-success">Deposit/In</span>
                    <span class="badge bg-danger ms-2">Withdrawal/Out</span>
                </div>
            </div>
            <div class="card-body">
                <?php if (empty($display_transactions)): ?>
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle"></i> No transactions found for the selected account and month.
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover" id="transactionsTable">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Source</th>
                                    <th>Type</th>
                                    <th>Reference</th>
                                    <th>Description</th>
                                    <th>Payment Method</th>
                                    <th>Cheque/Ref No.</th>
                                    <th class="text-end">Amount</th>
                                    <th class="text-end">Running Balance</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $running_balance = $page_starting_balance;
                                foreach ($display_transactions as $transaction): 
                                    // Determine transaction type and amount
                                    if ($transaction['source_type'] === 'receipt') {
                                        $amount = $transaction['debit_amount'];
                                        $running_balance += $amount;
                                        $type_class = 'type-receipt';
                                        $amount_class = 'text-success';
                                        $type_text = 'Deposit';
                                    } elseif ($transaction['source_type'] === 'payment') {
                                        $amount = $transaction['credit_amount'];
                                        $running_balance -= $amount;
                                        $type_class = 'type-payment';
                                        $amount_class = 'text-danger';
                                        $type_text = 'Withdrawal';
                                    } else {
                                        // GL entry
                                        if ($transaction['debit_amount'] > 0) {
                                            $amount = $transaction['debit_amount'];
                                            $running_balance += $amount;
                                            $type_class = $is_cash_selected ? 'type-cash-in' : 'type-receipt';
                                            $amount_class = 'text-success';
                                            $type_text = $is_cash_selected ? 'Cash In' : 'GL Debit';
                                        } else {
                                            $amount = $transaction['credit_amount'];
                                            $running_balance -= $amount;
                                            $type_class = $is_cash_selected ? 'type-cash-out' : 'type-payment';
                                            $amount_class = 'text-danger';
                                            $type_text = $is_cash_selected ? 'Cash Out' : 'GL Credit';
                                        }
                                    }
                                    
                                    $has_reference = !empty($transaction['cheque_no']) && trim($transaction['cheque_no']) !== '';
                                    $source_badge_class = 'source-' . $transaction['source_type'];
                                ?>
                                    <tr>
                                        <td><?php echo date('d/m/Y', strtotime($transaction['transaction_date'])); ?></td>
                                        <td>
                                            <span class="source-badge <?php echo $source_badge_class; ?>">
                                                <?php 
                                                if ($transaction['source_type'] === 'receipt') echo 'RCPT';
                                                elseif ($transaction['source_type'] === 'payment') echo 'PMT';
                                                else echo 'GL';
                                                ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="type-badge <?php echo $type_class; ?>">
                                                <?php echo $type_text; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="reference-badge"><?php echo htmlspecialchars($transaction['reference']); ?></span>
                                        </td>
                                        <td>
                                            <?php echo htmlspecialchars(substr($transaction['description'], 0, 60)); ?>
                                            <?php if (strlen($transaction['description']) > 60): ?>...<?php endif; ?>
                                        </td>
                                        <td>
                                            <small><?php echo htmlspecialchars($transaction['payment_method'] ?? 'N/A'); ?></small>
                                        </td>
                                        <td>
                                            <?php if ($has_reference): ?>
                                                <span class="reference-badge"><?php echo htmlspecialchars($transaction['cheque_no']); ?></span>
                                            <?php else: ?>
                                                <span class="badge bg-warning">No Ref</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end <?php echo $amount_class; ?>">
                                            <strong>
                                                <?php if (in_array($transaction['source_type'], ['receipt', 'gl_entry']) && $transaction['debit_amount'] > 0): ?>
                                                    + <?php echo htmlspecialchars($company['currency']); ?> 
                                                    <?php echo number_format($amount, 2); ?>
                                                <?php else: ?>
                                                    - <?php echo htmlspecialchars($company['currency']); ?> 
                                                    <?php echo number_format($amount, 2); ?>
                                                <?php endif; ?>
                                            </strong>
                                        </td>
                                        <td class="text-end">
                                            <strong class="<?php echo $running_balance >= 0 ? 'text-success' : 'text-danger'; ?>">
                                                <?php echo htmlspecialchars($company['currency']); ?> 
                                                <?php echo number_format($running_balance, 2); ?>
                                            </strong>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot>
                                <tr class="table-light">
                                    <th colspan="7" class="text-end">Month End Total:</th>
                                    <th class="text-end">
                                        <strong class="<?php echo $gl_total_movement >= 0 ? 'text-success' : 'text-danger'; ?>">
                                            <?php if ($gl_total_movement >= 0): ?>
                                                + <?php echo htmlspecialchars($company['currency']); ?> 
                                                <?php echo number_format($gl_total_movement, 2); ?>
                                            <?php else: ?>
                                                - <?php echo htmlspecialchars($company['currency']); ?> 
                                                <?php echo number_format(abs($gl_total_movement), 2); ?>
                                            <?php endif; ?>
                                        </strong>
                                    </th>
                                    <th class="text-end">
                                        <strong class="<?php echo $running_balance >= 0 ? 'text-success' : 'text-danger'; ?>">
                                            <?php echo htmlspecialchars($company['currency']); ?> 
                                            <?php echo number_format($running_balance, 2); ?>
                                        </strong>
                                    </th>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                    <?php if ($total_pages > 1): ?>
                    <div class="d-flex justify-content-between align-items-center px-3 py-2 border-top">
                        <small class="text-muted">
                            Showing page <?php echo $page; ?> of <?php echo $total_pages; ?> 
                            (<?php echo count($display_transactions); ?> of <?php echo $total_transaction_count; ?> transactions)
                        </small>
                        <nav aria-label="Page navigation">
                            <ul class="pagination pagination-sm mb-0">
                                <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                                    <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => 1])); ?>">&laquo;&laquo;</a>
                                </li>
                                <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                                    <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => max(1, $page - 1)])); ?>">&laquo;</a>
                                </li>
                                <?php 
                                $start_pg = max(1, $page - 2);
                                $end_pg = min($total_pages, $page + 2);
                                for ($i = $start_pg; $i <= $end_pg; $i++): 
                                ?>
                                    <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                                        <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>"><?php echo $i; ?></a>
                                    </li>
                                <?php endfor; ?>
                                <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                                    <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => min($total_pages, $page + 1)])); ?>">&raquo;</a>
                                </li>
                                <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                                    <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $total_pages])); ?>">&raquo;&raquo;</a>
                                </li>
                            </ul>
                        </nav>
                    </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Export Data -->
        <div class="card mt-4">
            <div class="card-header">
                <h6 class="mb-0"><i class="bi bi-download"></i> Export & Actions</h6>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6">
                        <h6>Export Options:</h6>
                        <p class="text-muted">Download transaction data for further analysis or record keeping.</p>
                        <div class="d-grid gap-2">
                            <button type="button" class="btn btn-outline-secondary" onclick="exportToCSV()">
                                <i class="bi bi-file-earmark-excel"></i> Export to CSV
                            </button>
                            <button type="button" class="btn btn-outline-secondary" onclick="window.print()">
                                <i class="bi bi-printer"></i> Print Report
                            </button>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <h6>Actions:</h6>
                        <p class="text-muted">Perform reconciliation actions.</p>
                        <div class="d-grid gap-2">
                            <a href="financial_data.php?account=<?php echo $selected_gl_account_id ?? ''; ?>&month=<?php echo $month; ?>" 
                               class="btn btn-outline-primary">
                                <i class="bi bi-journal-text"></i> View GL Details
                            </a>
                            <?php if ($difference != 0): ?>
                            <a href="journal_entries.php?action=add&account=<?php echo $selected_gl_account_id ?? ''; ?>&amount=<?php echo abs($difference); ?>&type=<?php echo $difference > 0 ? 'credit' : 'debit'; ?>" 
                               class="btn btn-outline-warning">
                                <i class="bi bi-pencil"></i> Create Adjustment
                            </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    <?php else: ?>
        <div class="alert alert-info">
            <i class="bi bi-info-circle"></i> Please select an account to view reconciliation.
        </div>
    <?php endif; ?>
</div>

<script>
function exportToCSV() {
    if (!document.getElementById('transactionsTable')) {
        alert('No data to export.');
        return;
    }
    
    // Create CSV content
    let csv = [];
    let rows = document.querySelectorAll("#transactionsTable tr");
    
    for (let i = 0; i < rows.length; i++) {
        let row = [], cols = rows[i].querySelectorAll("td, th");
        
        for (let j = 0; j < cols.length; j++) {
            // Clean up the content
            let text = cols[j].innerText
                .replace(/\n/g, ' ')
                .replace(/\s+/g, ' ')
                .trim();
            
            // Escape quotes and wrap in quotes if contains comma
            if (text.includes(',') || text.includes('"')) {
                text = '"' + text.replace(/"/g, '""') + '"';
            }
            
            row.push(text);
        }
        
        csv.push(row.join(","));
    }
    
    // Download CSV file
    let csvContent = "data:text/csv;charset=utf-8," + csv.join("\n");
    let encodedUri = encodeURI(csvContent);
    let link = document.createElement("a");
    link.setAttribute("href", encodedUri);
    
    // Generate filename
    let accountName = document.getElementById('bank_account').options[document.getElementById('bank_account').selectedIndex].text;
    let month = document.getElementById('month').value;
    let filename = "Bank_Reconciliation_" + accountName.replace(/[^a-zA-Z0-9]/g, '_') + "_" + month + ".csv";
    
    link.setAttribute("download", filename);
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}

// Auto-submit when month changes
document.getElementById('month').addEventListener('change', function() {
    if (document.getElementById('bank_account').value) {
        document.querySelector('form').submit();
    }
});

// Auto-submit when account changes
document.getElementById('bank_account').addEventListener('change', function() {
    if (this.value && document.getElementById('month').value) {
        document.querySelector('form').submit();
    }
});
</script>

<?php include '../includes/footer.php'; ?>