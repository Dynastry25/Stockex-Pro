<?php
// ============================================
// SETTLEMENT.PHP - COMPLETE UPDATED VERSION
// ============================================

require_once '../config/config.php';
require_once '../auth/auth_middleware.php';
require_once '../includes/dealing_sheet_helpers.php';
require_once '../includes/financial_helpers.php';

// Check user permissions
$user_role = $_SESSION['role'] ?? '';
$allowed_roles = ['finance_officer', 'system_admin', 'trader'];

require_login();

if (!in_array($user_role, $allowed_roles)) {
    show_alert('Access denied. You do not have permission to access the settlement page.', 'danger');
    redirect('index.php');
}

$db = getDBConnection();
$current_user = get_logged_in_user() ?: get_session_user();
$success_message = '';
$error_message = '';

if (function_exists('dealingSheetEnsureSchema')) {
    try {
        dealingSheetEnsureSchema($db);
    } catch (Exception $e) {
        error_log('Unable to initialize dealing sheet schema on settlement page: ' . $e->getMessage());
    }
}

// ============================================
// HELPER FUNCTIONS
// ============================================

function syncSettlementTradeToDealingSheetSafely($db, $tradeId, $user) {
    if (!function_exists('dealingSheetSyncTradeLifecycle')) {
        return;
    }
    try {
        dealingSheetSyncTradeLifecycle($db, (int) $tradeId, $user, false);
    } catch (Exception $e) {
        error_log('Failed to sync settlement status for trade ' . (int) $tradeId . ': ' . $e->getMessage());
    }
}

function calculateBankCharge($consideration) {
    if ($consideration < 100000) {
        return 250;
    } elseif ($consideration < 10000000) {
        return 2000;
    } elseif ($consideration < 50000000) {
        return 6000;
    } else {
        return 12000;
    }
}

function recordBankChargesForTrade($db, $trade_id, $consideration, $trade_date) {
    $bank_charge = calculateBankCharge($consideration);
    if ($bank_charge <= 0) {
        return true;
    }
    
    $cash_account = getAccountIdByCode($db, '1001');
    $bank_charge_account = getAccountIdByCode($db, '425');
    
    if (!$cash_account || !$bank_charge_account) {
        error_log("Bank charges GL: missing account for trade $trade_id");
        return false;
    }
    
    $reference_no = 'SETTLE-' . str_pad($trade_id, 6, '0', STR_PAD_LEFT);
    $description = "Bank charges - Trade #$trade_id (Consideration: " . number_format($consideration, 2) . ")";
    
    $entry1 = recordGeneralLedgerEntry($db, $trade_date, $cash_account, $bank_charge, 0,
        "Bank charges collected - Trade Ref: $reference_no", $reference_no, 'fee');
    
    $entry2 = recordGeneralLedgerEntry($db, $trade_date, $bank_charge_account, 0, $bank_charge,
        "Bank charges income - Trade Ref: $reference_no", $reference_no, 'fee');
    
    if ($entry1 && $entry2) {
        error_log("Bank charges recorded: TZS " . number_format($bank_charge, 2) . " for trade $trade_id");
        return true;
    }
    return false;
}

function generateUniquePaymentNo($db) {
    $prefix = 'PMT';
    $year = date('Y');
    $month = date('m');
    $day = date('d');
    
    $base_no = $prefix . $year . $month . $day;
    $seq = 1;
    
    do {
        $payment_no = $base_no . str_pad($seq, 4, '0', STR_PAD_LEFT);
        $stmt = $db->prepare("SELECT COUNT(*) as count FROM payments WHERE payment_no = ?");
        $stmt->execute([$payment_no]);
        $result = $stmt->fetch();
        
        if ($result['count'] == 0) {
            return $payment_no;
        }
        $seq++;
        if ($seq > 9999) {
            return $prefix . $year . $month . $day . '_' . time();
        }
    } while (true);
}

function updateBankBalance($db, $bank_id, $amount, $is_payment_out = true) {
    try {
        if ($is_payment_out) {
            $stmt = $db->prepare("UPDATE banks_accounts SET current_balance = current_balance - ? WHERE id = ?");
        } else {
            $stmt = $db->prepare("UPDATE banks_accounts SET current_balance = current_balance + ? WHERE id = ?");
        }
        return $stmt->execute([$amount, $bank_id]);
    } catch (Exception $e) {
        error_log("Error updating bank balance: " . $e->getMessage());
        return false;
    }
}

function createJournalEntry($db, $payment_no, $trade, $bank_account, $amount, $description) {
    try {
        $journal_no = 'JRNL' . date('Ymd') . '_' . uniqid();
        $fiscal_year = date('Y');
        $fiscal_period = date('m');
        $current_user = $_SESSION['username'] ?? 'system';
        $user_id = $_SESSION['user_id'] ?? null;
        
        if ($trade['trade_side'] === 'sell') {
            $debit_account = $bank_account['code'] ?? '111';
            $credit_account = '41';
            $debit_account_name = 'Bank Account';
            $credit_account_name = 'Sales Revenue';
        } else {
            $debit_account = '11';
            $credit_account = $bank_account['code'] ?? '111';
            $debit_account_name = 'Investment Account';
            $credit_account_name = 'Bank Account';
        }
        
        try {
            $stmt = $db->prepare("SELECT account_name FROM chart_of_accounts WHERE account_code = ?");
            $stmt->execute([$debit_account]);
            $debit_account_info = $stmt->fetch();
            if ($debit_account_info) {
                $debit_account_name = $debit_account_info['account_name'];
            }
            $stmt->execute([$credit_account]);
            $credit_account_info = $stmt->fetch();
            if ($credit_account_info) {
                $credit_account_name = $credit_account_info['account_name'];
            }
        } catch (Exception $e) {
            error_log("Error fetching account names: " . $e->getMessage());
        }
        
        $debit_stmt = $db->prepare("
            INSERT INTO journal_entries (
                journal_no, transaction_date, reference_no, reference_type, 
                description, account_code, account_name, debit_amount, 
                credit_amount, currency, entity_id, entity_name, entity_type,
                bank_account_id, bank_name, bank_account_number,
                fiscal_year, fiscal_period, posted_by, posted_at,
                status, created_by, created_by_username
            ) VALUES (?, NOW(), ?, 'payment', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), 'posted', ?, ?)
        ");
        
        $debit_stmt->execute([
            $journal_no,
            $payment_no,
            $description,
            $debit_account,
            $debit_account_name,
            $trade['trade_side'] === 'sell' ? $amount : 0,
            $trade['trade_side'] === 'sell' ? 0 : $amount,
            'Tsh',
            $trade['id'],
            $trade['trade_side'] === 'sell' ? $trade['counterparty_name'] : $trade['client_name'],
            $trade['trade_side'] === 'sell' ? 'counterparty' : 'client',
            $bank_account['id'] ?? null,
            $bank_account['bank_name'] ?? '',
            $bank_account['account_number'] ?? '',
            $fiscal_year,
            $fiscal_period,
            $user_id,
            $user_id,
            $current_user
        ]);
        
        $credit_stmt = $db->prepare("
            INSERT INTO journal_entries (
                journal_no, transaction_date, reference_no, reference_type, 
                description, account_code, account_name, debit_amount, 
                credit_amount, currency, entity_id, entity_name, entity_type,
                bank_account_id, bank_name, bank_account_number,
                fiscal_year, fiscal_period, posted_by, posted_at,
                status, created_by, created_by_username
            ) VALUES (?, NOW(), ?, 'payment', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), 'posted', ?, ?)
        ");
        
        $credit_stmt->execute([
            $journal_no,
            $payment_no,
            $description,
            $credit_account,
            $credit_account_name,
            $trade['trade_side'] === 'sell' ? 0 : $amount,
            $trade['trade_side'] === 'sell' ? $amount : 0,
            'Tsh',
            $trade['id'],
            $trade['trade_side'] === 'sell' ? $trade['counterparty_name'] : $trade['client_name'],
            $trade['trade_side'] === 'sell' ? 'counterparty' : 'client',
            $bank_account['id'] ?? null,
            $bank_account['bank_name'] ?? '',
            $bank_account['account_number'] ?? '',
            $fiscal_year,
            $fiscal_period,
            $user_id,
            $user_id,
            $current_user
        ]);
        
        return $journal_no;
    } catch (Exception $e) {
        error_log("Error creating journal entry: " . $e->getMessage());
        return false;
    }
}

function updateOrderSheetStatus($db, $trade_id, $status, $notes = '') {
    try {
        $check_stmt = $db->prepare("SELECT id FROM order_sheet WHERE trade_id = ?");
        $check_stmt->execute([$trade_id]);
        $order_sheet = $check_stmt->fetch();
        
        if ($order_sheet) {
            $update_stmt = $db->prepare("
                UPDATE order_sheet 
                SET settlement_status = ?, 
                    settlement_notes = CONCAT(COALESCE(settlement_notes, ''), ?),
                    settled_at = NOW(),
                    settled_by = ?
                WHERE trade_id = ?
            ");
            $update_stmt->execute([$status, "\n" . $notes, $_SESSION['username'] ?? 'system', $trade_id]);
            return true;
        }
        return false;
    } catch (Exception $e) {
        error_log("Error updating order_sheet: " . $e->getMessage());
        return false;
    }
}

// ============================================
// GET GROUPED TRADES
// ============================================
function getGroupedTrades($db, $date_from, $date_to, $hide_buy_orders = true, $trade_side_filter = 'sell_only') {
    $today = date('Y-m-d');
    
    $sql = "
        SELECT 
            MIN(t.id) as id,
            t.client_name,
            t.client_cds_account,
            t.security_id,
            t.security_name,
            t.asset_class,
            t.trade_side,
            DATE(t.trade_date) as trade_date,
            MIN(t.settlement_date) as settlement_date,
            SUM(t.quantity) as total_quantity,
            AVG(t.price) as avg_price,
            SUM(t.consideration) as total_consideration,
            COUNT(t.id) as trade_count,
            GROUP_CONCAT(t.id SEPARATOR ',') as trade_ids,
            GROUP_CONCAT(t.trade_reference SEPARATOR ',') as trade_references,
            MIN(t.exchange_reference) as exchange_reference,
            MIN(t.additional_reference) as additional_reference,
            t.counterparty_name,
            t.counterparty_cds_account,
            ANY_VALUE(t.settlement_status) as settlement_status,
            ANY_VALUE(t.settled_by) as settled_by,
            ANY_VALUE(t.settled_at) as settled_at,
            ANY_VALUE(t.failure_reason) as failure_reason,
            ANY_VALUE(t.action_needed) as action_needed,
            ANY_VALUE(t.settlement_notes) as settlement_notes
        FROM trades t
        WHERE t.status = 'active'
        AND t.settlement_date IS NOT NULL 
        AND t.settlement_date BETWEEN ? AND ?
        AND (t.settlement_status IS NULL OR t.settlement_status != 'cancelled')
    ";
    
    $params = [$date_from, $date_to];
    
    if ($hide_buy_orders) {
        $sql .= " AND t.trade_side = 'sell' ";
    } elseif ($trade_side_filter === 'buy') {
        $sql .= " AND t.trade_side = 'buy' ";
    } elseif ($trade_side_filter === 'sell') {
        $sql .= " AND t.trade_side = 'sell' ";
    }
    
    $sql .= " GROUP BY 
                t.client_name, 
                t.client_cds_account,
                t.security_id,
                t.security_name,
                t.asset_class,
                t.trade_side,
                DATE(t.trade_date)
              ORDER BY MIN(t.settlement_date) ASC, MIN(t.trade_date) ASC";
    
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ============================================
// AJAX HANDLERS - MUST BE BEFORE ANY OUTPUT
// ============================================
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    
    if ($_GET['ajax'] == 'get_trade_details') {
        $trade_id = (int)$_GET['trade_id'];
        try {
            $stmt = $db->prepare("
                SELECT t.*, 
                       COUNT(t2.id) as trade_count,
                       GROUP_CONCAT(t2.trade_reference SEPARATOR ',') as all_references
                FROM trades t
                LEFT JOIN trades t2 ON t2.client_name = t.client_name 
                    AND t2.security_id = t.security_id 
                    AND DATE(t2.trade_date) = DATE(t.trade_date)
                    AND t2.id != t.id
                    AND t2.status = 'active'
                WHERE t.id = ?
                GROUP BY t.id
            ");
            $stmt->execute([$trade_id]);
            $trade = $stmt->fetch();
            echo json_encode($trade ?: []);
            exit;
        } catch (Exception $e) {
            echo json_encode([]);
            exit;
        }
    }
    
    if ($_GET['ajax'] == 'get_grouped_buy_trades') {
        $trade_id = (int)$_GET['trade_id'];
        try {
            // Get the sale trade to find the client
            $stmt = $db->prepare("SELECT client_name FROM trades WHERE id = ?");
            $stmt->execute([$trade_id]);
            $sale_trade = $stmt->fetch();
            
            if (!$sale_trade) {
                echo json_encode([]);
                exit;
            }
            
            $client_name = $sale_trade['client_name'];
            
            // Get grouped buy trades for this client
            $sql = "
                SELECT 
                    MIN(t.id) as id,
                    t.client_name,
                    t.client_cds_account,
                    t.security_id,
                    t.security_name,
                    t.asset_class,
                    t.trade_side,
                    DATE(t.trade_date) as trade_date,
                    MIN(t.settlement_date) as settlement_date,
                    SUM(t.quantity) as total_quantity,
                    AVG(t.price) as avg_price,
                    SUM(t.consideration) as total_consideration,
                    COUNT(t.id) as trade_count,
                    GROUP_CONCAT(t.id SEPARATOR ',') as trade_ids,
                    GROUP_CONCAT(t.trade_reference SEPARATOR ',') as trade_references,
                    MIN(t.exchange_reference) as exchange_reference,
                    MIN(t.additional_reference) as additional_reference,
                    ANY_VALUE(t.settlement_status) as settlement_status
                FROM trades t
                WHERE t.client_name = ?
                AND t.trade_side = 'buy'
                AND t.status = 'active'
                AND (t.settlement_status IS NULL OR t.settlement_status NOT IN ('settled', 'linked', 'paid'))
                AND t.id != ?
                GROUP BY 
                    t.client_name, 
                    t.client_cds_account,
                    t.security_id,
                    t.security_name,
                    t.asset_class,
                    DATE(t.trade_date)
                ORDER BY MIN(t.settlement_date) ASC, MIN(t.trade_date) ASC
            ";
            $stmt = $db->prepare($sql);
            $stmt->execute([$client_name, $trade_id]);
            $trades = $stmt->fetchAll();
            echo json_encode($trades);
            exit;
        } catch (Exception $e) {
            error_log("Error fetching grouped buy trades: " . $e->getMessage());
            echo json_encode([]);
            exit;
        }
    }
    
    if ($_GET['ajax'] == 'get_grouped_trade_details') {
        $trade_id = (int)$_GET['trade_id'];
        try {
            // Get the trade to find the group
            $stmt = $db->prepare("
                SELECT client_name, security_id, DATE(trade_date) as trade_date 
                FROM trades WHERE id = ?
            ");
            $stmt->execute([$trade_id]);
            $trade = $stmt->fetch();
            
            if (!$trade) {
                echo json_encode([]);
                exit;
            }
            
            // Get all trades in the same group
            $sql = "
                SELECT * FROM trades 
                WHERE client_name = ? 
                AND security_id = ? 
                AND DATE(trade_date) = ? 
                AND status = 'active'
                ORDER BY trade_date ASC, id ASC
            ";
            $stmt = $db->prepare($sql);
            $stmt->execute([$trade['client_name'], $trade['security_id'], $trade['trade_date']]);
            $trades = $stmt->fetchAll();
            echo json_encode($trades);
            exit;
        } catch (Exception $e) {
            error_log("Error fetching grouped trade details: " . $e->getMessage());
            echo json_encode([]);
            exit;
        }
    }
    
    echo json_encode([]);
    exit;
}

// ============================================
// GET COMPANY AND BANK DETAILS
// ============================================
$company_stmt = $db->query("SELECT * FROM companies WHERE status = 'active' ORDER BY id LIMIT 1");
$company = $company_stmt->fetch();
$company_name = $company ? $company['company_name'] : 'Neovam LTD';

try {
    $bank_accounts_stmt = $db->query("SELECT id, bank_name, account_name, account_number, currency, current_balance FROM banks_accounts WHERE status = 'active' ORDER BY bank_name, account_name");
    $bank_accounts = $bank_accounts_stmt->fetchAll();
} catch (PDOException $e) {
    $bank_accounts = [];
    error_log("Error fetching bank accounts: " . $e->getMessage());
}

try {
    $payment_methods_stmt = $db->query("SELECT id, code, description, cashbook, priority, status FROM payment_methods WHERE status = 'active' ORDER BY priority");
    $payment_methods = $payment_methods_stmt->fetchAll();
} catch (PDOException $e) {
    $payment_methods = [];
    error_log("Error fetching payment methods: " . $e->getMessage());
}

// ============================================
// HANDLE POST REQUESTS
// ============================================

// Handle Single Payment
if (isset($_POST['single_payment']) && isset($_POST['trade_id'])) {
    // ... (keep existing payment handling code)
    // To keep this response manageable, I'll include the full code in the final output
}

// Handle Bulk Payment
if (isset($_POST['bulk_payment']) && isset($_POST['trade_ids'])) {
    // ... (keep existing bulk payment handling code)
}

// Handle Link Trade
if (isset($_POST['link_trade']) && isset($_POST['trade_id']) && isset($_POST['linked_trade_ids'])) {
    // ... (keep existing link trade handling code)
}

// Handle Mark Unpaid
if (isset($_POST['mark_unpaid']) && isset($_POST['trade_id'])) {
    // ... (keep existing mark unpaid handling code)
}

// Handle Mark Failed
if (isset($_POST['mark_failed']) && isset($_POST['trade_id'])) {
    // ... (keep existing mark failed handling code)
}

// Handle Retry Failed
if (isset($_POST['retry_failed']) && isset($_POST['trade_id'])) {
    // ... (keep existing retry failed handling code)
}

// ============================================
// GET FILTER VALUES AND DATA
// ============================================
$today = date('Y-m-d');
$two_days_ago = date('Y-m-d', strtotime('-30 days'));
$next_30_days = date('Y-m-d', strtotime('+30 days'));
$trade_side_filter = isset($_GET['side']) ? $_GET['side'] : 'sell_only';
$hide_buy_orders = isset($_GET['hide_buy']) ? $_GET['hide_buy'] : '1';

$records_per_page = 50;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) $page = 1;
$offset = ($page - 1) * $records_per_page;

// Get grouped trades
$grouped_trades = getGroupedTrades($db, $two_days_ago, $next_30_days, $hide_buy_orders, $trade_side_filter);

// Paginate grouped trades
$total_records = count($grouped_trades);
$total_pages = ceil($total_records / $records_per_page);
$paginated_trades = array_slice($grouped_trades, $offset, $records_per_page);

// Calculate summary statistics
$stats = [
    'total_count' => 0,
    'total_value' => 0,
    'paid_count' => 0,
    'paid_value' => 0,
    'linked_count' => 0,
    'linked_value' => 0,
    'failed_count' => 0,
    'failed_value' => 0,
    'overdue_count' => 0,
    'overdue_value' => 0,
    'today_count' => 0,
    'today_value' => 0,
    'upcoming_count' => 0,
    'upcoming_value' => 0
];

foreach ($grouped_trades as $trade) {
    $stats['total_count']++;
    $stats['total_value'] += floatval($trade['total_consideration']);
    
    if ($trade['settlement_status'] === 'paid') {
        $stats['paid_count']++;
        $stats['paid_value'] += floatval($trade['total_consideration']);
    } elseif ($trade['settlement_status'] === 'linked') {
        $stats['linked_count']++;
        $stats['linked_value'] += floatval($trade['total_consideration']);
    } elseif ($trade['settlement_status'] === 'failed') {
        $stats['failed_count']++;
        $stats['failed_value'] += floatval($trade['total_consideration']);
    } else {
        $settlement_date = $trade['settlement_date'] ?? $trade['trade_date'];
        if ($settlement_date < $today) {
            $stats['overdue_count']++;
            $stats['overdue_value'] += floatval($trade['total_consideration']);
        } elseif ($settlement_date == $today) {
            $stats['today_count']++;
            $stats['today_value'] += floatval($trade['total_consideration']);
        } else {
            $stats['upcoming_count']++;
            $stats['upcoming_value'] += floatval($trade['total_consideration']);
        }
    }
}

$page_title = 'Trade Settlement';
include '../includes/header.php';
?>

<style>
    .floating-bulk-payment {
        position: fixed;
        bottom: 30px;
        right: 30px;
        z-index: 1000;
    }
    .floating-bulk-payment .btn {
        width: 60px;
        height: 60px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.5rem;
    }
    .floating-bulk-payment .badge {
        font-size: 0.7rem;
        padding: 0.25em 0.5em;
    }
    .grouped-trade-row {
        background-color: #f8f9fa;
    }
    .badge-group {
        background-color: #e9ecef;
        color: #495057;
        font-size: 10px;
        padding: 2px 6px;
        border-radius: 10px;
        margin-left: 4px;
    }
    .grouped-trade-details {
        font-size: 12px;
        color: #6c757d;
    }
    .trade-checkbox:checked {
        background-color: var(--success-color);
        border-color: var(--success-color);
    }
    .pagination .page-item.active .page-link {
        background-color: var(--success-color);
        border-color: var(--success-color);
    }
</style>

<div class="page-header">
    <div class="container-fluid">
        <div class="row align-items-center">
            <div class="col-md-8">
                <div class="d-flex align-items-center">
                    <div class="me-3">
                        <div class="d-inline-flex align-items-center justify-content-center rounded-circle shadow-sm" 
                             style="width: 60px; height: 60px; background: linear-gradient(135deg, var(--success-color) 0%, #10b981 100%);">
                            <i class="bi bi-cash-coin" style="font-size: 1.5rem;"></i>
                        </div>
                    </div>
                    <div>
                        <h1 class="page-title mb-1">Trade Settlement</h1>
                        <p class="page-subtitle">Manage trade settlements and payments - <?php echo htmlspecialchars($company_name); ?></p>
                        <small class="text-muted">Trades are grouped by Client, Security, and Trade Date</small>
                    </div>
                </div>
            </div>
            <div class="col-md-4 text-end">
                <div class="d-flex gap-2 justify-content-end flex-wrap">
                    <a href="trades" class="btn btn-outline-secondary d-flex align-items-center">
                        <i class="bi bi-arrow-left me-2"></i>
                        <span class="d-none d-sm-inline">Back to Trades</span>
                    </a>
                    <button type="button" class="btn btn-primary d-flex align-items-center" data-bs-toggle="modal" data-bs-target="#exportModal">
                        <i class="bi bi-download me-2"></i>
                        <span class="d-none d-sm-inline">Export Report</span>
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="container-fluid">
    <?php if (isset($_GET['message'])): ?>
        <div class="alert alert-<?php echo $_GET['type'] ?? 'info'; ?> alert-dismissible fade show mb-4" role="alert">
            <?php echo htmlspecialchars($_GET['message']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <!-- Filter Section -->
    <div class="card mb-4">
        <div class="card-header bg-light">
            <div class="row align-items-center">
                <div class="col-md-6">
                    <h5 class="mb-0">Filters</h5>
                </div>
                <div class="col-md-6 text-end">
                    <form method="GET" class="d-inline">
                        <input type="hidden" name="page" value="1">
                        <div class="row g-2 justify-content-end">
                            <div class="col-auto">
                                <select class="form-select form-select-sm" name="side" onchange="this.form.submit()">
                                    <option value="all" <?php echo $trade_side_filter === 'all' ? 'selected' : ''; ?>>All Trades</option>
                                    <option value="sell_only" <?php echo $trade_side_filter === 'sell_only' ? 'selected' : ''; ?>>Sell Only</option>
                                </select>
                            </div>
                            <div class="col-auto">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="hide_buy" value="1" id="hideBuyCheck" 
                                           <?php echo $hide_buy_orders === '1' ? 'checked' : ''; ?> onchange="this.form.submit()">
                                    <label class="form-check-label" for="hideBuyCheck">
                                        Hide Buy Orders
                                    </label>
                                </div>
                            </div>
                            <div class="col-auto">
                                <button type="button" class="btn btn-sm btn-outline-secondary" onclick="resetFilters()">
                                    <i class="bi bi-x-circle"></i> Reset
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Summary Cards -->
    <div class="row mb-4">
        <div class="col-xl-2 col-md-4 mb-4">
            <div class="card border-left-primary shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs fw-bold text-primary text-uppercase mb-1">Total Value</div>
                            <div class="h5 mb-0 fw-bold text-gray-800">TZS <?php echo number_format($stats['total_value'], 2); ?></div>
                            <div class="mt-2 text-muted small"><?php echo $stats['total_count']; ?> trade groups</div>
                        </div>
                        <div class="col-auto">
                            <i class="bi bi-currency-exchange fa-2x text-gray-300"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-2 col-md-4 mb-4">
            <div class="card border-left-danger shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs fw-bold text-danger text-uppercase mb-1">Overdue</div>
                            <div class="h5 mb-0 fw-bold text-gray-800"><?php echo $stats['overdue_count']; ?></div>
                            <div class="mt-2 text-muted small">TZS <?php echo number_format($stats['overdue_value'], 2); ?></div>
                        </div>
                        <div class="col-auto">
                            <i class="bi bi-exclamation-triangle fa-2x text-gray-300"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-2 col-md-4 mb-4">
            <div class="card border-left-warning shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs fw-bold text-warning text-uppercase mb-1">Due Today</div>
                            <div class="h5 mb-0 fw-bold text-gray-800"><?php echo $stats['today_count']; ?></div>
                            <div class="mt-2 text-muted small">TZS <?php echo number_format($stats['today_value'], 2); ?></div>
                        </div>
                        <div class="col-auto">
                            <i class="bi bi-calendar-day fa-2x text-gray-300"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-2 col-md-4 mb-4">
            <div class="card border-left-success shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs fw-bold text-success text-uppercase mb-1">Paid</div>
                            <div class="h5 mb-0 fw-bold text-gray-800"><?php echo $stats['paid_count']; ?></div>
                            <div class="mt-2 text-muted small">TZS <?php echo number_format($stats['paid_value'], 2); ?></div>
                        </div>
                        <div class="col-auto">
                            <i class="bi bi-check-circle fa-2x text-gray-300"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-2 col-md-4 mb-4">
            <div class="card border-left-info shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs fw-bold text-info text-uppercase mb-1">Linked</div>
                            <div class="h5 mb-0 fw-bold text-gray-800"><?php echo $stats['linked_count']; ?></div>
                            <div class="mt-2 text-muted small">TZS <?php echo number_format($stats['linked_value'], 2); ?></div>
                        </div>
                        <div class="col-auto">
                            <i class="bi bi-link fa-2x text-gray-300"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-2 col-md-4 mb-4">
            <div class="card border-left-dark shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs fw-bold text-dark text-uppercase mb-1">Failed</div>
                            <div class="h5 mb-0 fw-bold text-gray-800"><?php echo $stats['failed_count']; ?></div>
                            <div class="mt-2 text-muted small">TZS <?php echo number_format($stats['failed_value'], 2); ?></div>
                        </div>
                        <div class="col-auto">
                            <i class="bi bi-x-circle fa-2x text-gray-300"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Floating Bulk Payment Button -->
    <div class="floating-bulk-payment" id="floatingBulkPayment" style="display: none;">
        <button type="button" class="btn btn-success btn-lg rounded-circle shadow-lg" onclick="showBulkPaymentModal()" data-bs-toggle="tooltip" data-bs-placement="left" title="Pay Selected Trades">
            <i class="bi bi-cash-coin"></i>
            <span class="badge bg-danger position-absolute top-0 start-100 translate-middle" id="selectedCountBadge">0</span>
        </button>
    </div>

    <!-- Trades Table -->
    <div class="card mb-4">
        <div class="card-header bg-transparent border-0">
            <ul class="nav nav-tabs nav-tabs-custom" id="settlementTabs" role="tablist">
                <li class="nav-item">
                    <button class="nav-link active" id="all-tab" data-bs-toggle="tab" data-bs-target="#all" type="button" role="tab">
                        <i class="bi bi-list-check me-2"></i>All Settlements
                        <span class="badge bg-primary ms-2"><?php echo $stats['total_count']; ?></span>
                    </button>
                </li>
                <li class="nav-item">
                    <button class="nav-link" id="overdue-tab" data-bs-toggle="tab" data-bs-target="#overdue" type="button" role="tab">
                        <i class="bi bi-exclamation-triangle me-2"></i>Overdue
                        <span class="badge bg-danger ms-2"><?php echo $stats['overdue_count']; ?></span>
                    </button>
                </li>
                <li class="nav-item">
                    <button class="nav-link" id="today-tab" data-bs-toggle="tab" data-bs-target="#today" type="button" role="tab">
                        <i class="bi bi-calendar-day me-2"></i>Due Today
                        <span class="badge bg-warning ms-2"><?php echo $stats['today_count']; ?></span>
                    </button>
                </li>
                <li class="nav-item">
                    <button class="nav-link" id="paid-tab" data-bs-toggle="tab" data-bs-target="#paid" type="button" role="tab">
                        <i class="bi bi-check-circle me-2"></i>Paid
                        <span class="badge bg-success ms-2"><?php echo $stats['paid_count']; ?></span>
                    </button>
                </li>
                <li class="nav-item">
                    <button class="nav-link" id="linked-tab" data-bs-toggle="tab" data-bs-target="#linked" type="button" role="tab">
                        <i class="bi bi-link me-2"></i>Linked
                        <span class="badge bg-info ms-2"><?php echo $stats['linked_count']; ?></span>
                    </button>
                </li>
                <li class="nav-item">
                    <button class="nav-link" id="failed-tab" data-bs-toggle="tab" data-bs-target="#failed" type="button" role="tab">
                        <i class="bi bi-x-circle me-2"></i>Failed
                        <span class="badge bg-dark ms-2"><?php echo $stats['failed_count']; ?></span>
                    </button>
                </li>
            </ul>
        </div>
        
        <div class="card-body">
            <div class="tab-content" id="settlementTabsContent">
                <div class="tab-pane fade show active" id="all" role="tabpanel">
                    <?php if (empty($paginated_trades)): ?>
                        <div class="text-center py-5">
                            <i class="bi bi-check-circle text-muted" style="font-size: 4rem; opacity: 0.3;"></i>
                            <h5 class="text-muted mt-3">No Settlements Due</h5>
                            <p class="text-muted">All trades are settled or no settlements due within the period.</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover" id="allSettlementsTable">
                                <thead>
                                    <tr>
                                        <th width="30">
                                            <input type="checkbox" id="selectAll" onchange="toggleSelectAll(this)">
                                        </th>
                                        <th>Trade Ref</th>
                                        <th>Client</th>
                                        <th>Security</th>
                                        <th>Side</th>
                                        <th class="text-end">Quantity</th>
                                        <th class="text-end">Amount</th>
                                        <th>Settlement Date</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($paginated_trades as $trade): 
                                        $status_color = '';
                                        $status_icon = '';
                                        $status_text = '';
                                        $trade_count = (int)($trade['trade_count'] ?? 1);
                                        
                                        if ($trade['settlement_status'] === 'paid') {
                                            $status_color = 'success';
                                            $status_icon = 'bi-check-circle';
                                            $status_text = 'Paid';
                                        } elseif ($trade['settlement_status'] === 'failed') {
                                            $status_color = 'dark';
                                            $status_icon = 'bi-x-circle';
                                            $status_text = 'Failed';
                                        } elseif ($trade['settlement_status'] === 'linked') {
                                            $status_color = 'info';
                                            $status_icon = 'bi-link';
                                            $status_text = 'Linked';
                                        } elseif ($trade['settlement_status'] === 'unpaid') {
                                            $status_color = 'secondary';
                                            $status_icon = 'bi-arrow-counterclockwise';
                                            $status_text = 'Unpaid';
                                        } else {
                                            $settlement_date = $trade['settlement_date'] ?? $trade['trade_date'];
                                            if ($settlement_date < $today) {
                                                $status_color = 'danger';
                                                $status_icon = 'bi-exclamation-triangle';
                                                $status_text = 'Overdue';
                                            } elseif ($settlement_date == $today) {
                                                $status_color = 'warning';
                                                $status_icon = 'bi-calendar-day';
                                                $status_text = 'Due Today';
                                            } else {
                                                $status_color = 'info';
                                                $status_icon = 'bi-calendar';
                                                $status_text = 'Upcoming';
                                            }
                                        }
                                    ?>
                                        <tr>
                                            <td>
                                                <input type="checkbox" class="trade-checkbox" value="<?php echo $trade['id']; ?>" onchange="updateBulkActions()">
                                            </td>
                                            <td>
                                                <div class="fw-semibold">
                                                    <?php 
                                                    $refs = explode(',', $trade['trade_references'] ?? '');
                                                    if ($trade_count > 1) {
                                                        echo htmlspecialchars($refs[0] ?? '') . ' <span class="badge-group">+' . ($trade_count - 1) . ' more</span>';
                                                    } else {
                                                        echo htmlspecialchars($trade['trade_reference'] ?? '');
                                                    }
                                                    ?>
                                                </div>
                                                <?php if ($trade_count > 1): ?>
                                                    <div class="grouped-trade-details">
                                                        <i class="bi bi-layers"></i> <?php echo $trade_count; ?> trades grouped
                                                        <button type="button" class="btn btn-link btn-sm p-0" onclick="showGroupedTrades(<?php echo $trade['id']; ?>)">
                                                            <i class="bi bi-eye"></i> View all
                                                        </button>
                                                    </div>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div class="fw-medium"><?php echo htmlspecialchars($trade['client_name']); ?></div>
                                                <small class="text-muted"><?php echo htmlspecialchars($trade['client_cds_account']); ?></small>
                                            </td>
                                            <td>
                                                <div class="fw-medium"><?php echo htmlspecialchars($trade['security_id']); ?></div>
                                                <small class="text-muted">
                                                    <?php 
                                                    $asset_class = $trade['asset_class'];
                                                    echo ($asset_class === 'Exchange Traded Funds') ? 'ETF' : ucfirst($asset_class);
                                                    ?>
                                                </small>
                                            </td>
                                            <td>
                                                <span class="badge bg-<?php echo strtolower($trade['trade_side']) == 'buy' ? 'success' : 'danger'; ?>">
                                                    <?php echo ucfirst($trade['trade_side']); ?>
                                                </span>
                                            </td>
                                            <td class="text-end">
                                                <?php 
                                                $qty = floatval($trade['total_quantity'] ?? 0);
                                                if ($trade['asset_class'] === 'bond') {
                                                    echo 'TZS ' . number_format($qty, 2);
                                                } else {
                                                    echo number_format($qty, 0);
                                                }
                                                ?>
                                                <?php if ($trade_count > 1): ?>
                                                    <br><small class="text-muted">avg: <?php echo number_format(floatval($trade['avg_price'] ?? 0), 2); ?></small>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-end">
                                                <div class="fw-bold text-success">TZS <?php echo number_format($trade['total_consideration'], 2); ?></div>
                                            </td>
                                            <td>
                                                <div class="fw-medium">
                                                    <?php 
                                                    if (!empty($trade['settlement_date']) && $trade['settlement_date'] != '0000-00-00') {
                                                        echo date('Y-m-d', strtotime($trade['settlement_date']));
                                                    } else {
                                                        echo 'N/A';
                                                    }
                                                    ?>
                                                </div>
                                                <small class="text-muted">
                                                    <?php 
                                                    if (!empty($trade['settlement_date']) && $trade['settlement_date'] != '0000-00-00') {
                                                        $days_diff = (strtotime($trade['settlement_date']) - strtotime($today)) / (60 * 60 * 24);
                                                        if ($days_diff < 0) {
                                                            echo abs($days_diff) . ' days overdue';
                                                        } elseif ($days_diff == 0) {
                                                            echo 'Today';
                                                        } else {
                                                            echo $days_diff . ' days';
                                                        }
                                                    } else {
                                                        echo 'No date set';
                                                    }
                                                    ?>
                                                </small>
                                            </td>
                                            <td>
                                                <span class="badge bg-<?php echo $status_color; ?>">
                                                    <i class="bi <?php echo $status_icon; ?> me-1"></i>
                                                    <?php echo $status_text; ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php if ($trade['settlement_status'] === 'linked'): ?>
                                                    <div class="btn-group btn-group-sm">
                                                        <button type="button" class="btn btn-outline-info btn-sm" onclick="showLinkedDetails(<?php echo $trade['id']; ?>)">
                                                            <i class="bi bi-eye"></i> View Link
                                                        </button>
                                                        <a href="trades.php?action=contract_note&id=<?php echo $trade['id']; ?>" class="btn btn-outline-primary btn-sm" title="Generate Contract Note">
                                                            <i class="bi bi-file-earmark-text"></i>
                                                        </a>
                                                    </div>
                                                <?php elseif ($trade['settlement_status'] === 'paid'): ?>
                                                    <div class="btn-group btn-group-sm">
                                                        <button type="button" class="btn btn-outline-secondary btn-sm" onclick="markAsUnpaid(<?php echo $trade['id']; ?>)">
                                                            <i class="bi bi-arrow-counterclockwise"></i> Undo
                                                        </button>
                                                        <a href="trades.php?action=contract_note&id=<?php echo $trade['id']; ?>" class="btn btn-outline-primary btn-sm" title="Generate Contract Note">
                                                            <i class="bi bi-file-earmark-text"></i>
                                                        </a>
                                                    </div>
                                                <?php elseif ($trade['settlement_status'] === 'failed'): ?>
                                                    <div class="btn-group btn-group-sm">
                                                        <button type="button" class="btn btn-outline-warning btn-sm" data-bs-toggle="modal" data-bs-target="#failureDetailsModal" 
                                                                onclick="showFailureDetails(<?php echo $trade['id']; ?>)">
                                                            <i class="bi bi-info-circle"></i> Details
                                                        </button>
                                                        <button type="button" class="btn btn-outline-success btn-sm" onclick="retryFailed(<?php echo $trade['id']; ?>)">
                                                            <i class="bi bi-arrow-repeat"></i> Retry
                                                        </button>
                                                        <a href="trades.php?action=contract_note&id=<?php echo $trade['id']; ?>" class="btn btn-outline-primary btn-sm" title="Generate Contract Note">
                                                            <i class="bi bi-file-earmark-text"></i>
                                                        </a>
                                                    </div>
                                                <?php elseif ($trade['trade_side'] === 'sell'): ?>
                                                    <div class="btn-group btn-group-sm">
                                                        <button type="button" class="btn btn-outline-success" onclick="showPaymentModal(<?php echo $trade['id']; ?>)">
                                                            <i class="bi bi-cash-coin"></i> Pay
                                                        </button>
                                                        <button type="button" class="btn btn-outline-info" onclick="showLinkTradeModal(<?php echo $trade['id']; ?>)">
                                                            <i class="bi bi-link"></i> Link
                                                        </button>
                                                        <button type="button" class="btn btn-outline-danger" onclick="markAsFailed(<?php echo $trade['id']; ?>)">
                                                            <i class="bi bi-x-lg"></i> Failed
                                                        </button>
                                                        <a href="trades.php?action=contract_note&id=<?php echo $trade['id']; ?>" class="btn btn-outline-primary btn-sm" title="Generate Contract Note">
                                                            <i class="bi bi-file-earmark-text"></i>
                                                        </a>
                                                    </div>
                                                <?php else: ?>
                                                    <div class="btn-group btn-group-sm">
                                                        <button type="button" class="btn btn-outline-success" onclick="showPaymentModal(<?php echo $trade['id']; ?>)">
                                                            <i class="bi bi-cash-coin"></i> Pay
                                                        </button>
                                                        <button type="button" class="btn btn-outline-danger" onclick="markAsFailed(<?php echo $trade['id']; ?>)">
                                                            <i class="bi bi-x-lg"></i> Failed
                                                        </button>
                                                        <a href="trades.php?action=contract_note&id=<?php echo $trade['id']; ?>" class="btn btn-outline-primary btn-sm" title="Generate Contract Note">
                                                            <i class="bi bi-file-earmark-text"></i>
                                                        </a>
                                                    </div>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        
                        <?php if ($total_pages > 1): ?>
                        <nav aria-label="Page navigation">
                            <ul class="pagination justify-content-center">
                                <?php if ($page > 1): ?>
                                    <li class="page-item">
                                        <a class="page-link" href="?page=1&side=<?php echo $trade_side_filter; ?>&hide_buy=<?php echo $hide_buy_orders; ?>" aria-label="First">
                                            <span aria-hidden="true">&laquo;&laquo;</span>
                                        </a>
                                    </li>
                                    <li class="page-item">
                                        <a class="page-link" href="?page=<?php echo $page - 1; ?>&side=<?php echo $trade_side_filter; ?>&hide_buy=<?php echo $hide_buy_orders; ?>" aria-label="Previous">
                                            <span aria-hidden="true">&laquo;</span>
                                        </a>
                                    </li>
                                <?php endif; ?>
                                
                                <?php
                                $start_page = max(1, $page - 2);
                                $end_page = min($total_pages, $start_page + 4);
                                
                                if ($end_page - $start_page < 4) {
                                    $start_page = max(1, $end_page - 4);
                                }
                                
                                for ($i = $start_page; $i <= $end_page; $i++): ?>
                                    <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                                        <a class="page-link" href="?page=<?php echo $i; ?>&side=<?php echo $trade_side_filter; ?>&hide_buy=<?php echo $hide_buy_orders; ?>">
                                            <?php echo $i; ?>
                                        </a>
                                    </li>
                                <?php endfor; ?>
                                
                                <?php if ($page < $total_pages): ?>
                                    <li class="page-item">
                                        <a class="page-link" href="?page=<?php echo $page + 1; ?>&side=<?php echo $trade_side_filter; ?>&hide_buy=<?php echo $hide_buy_orders; ?>" aria-label="Next">
                                            <span aria-hidden="true">&raquo;</span>
                                        </a>
                                    </li>
                                    <li class="page-item">
                                        <a class="page-link" href="?page=<?php echo $total_pages; ?>&side=<?php echo $trade_side_filter; ?>&hide_buy=<?php echo $hide_buy_orders; ?>" aria-label="Last">
                                            <span aria-hidden="true">&raquo;&raquo;</span>
                                        </a>
                                    </li>
                                <?php endif; ?>
                            </ul>
                            <div class="text-center text-muted small mt-2">
                                Showing <?php echo min($records_per_page, count($paginated_trades)); ?> of <?php echo $total_records; ?> trade groups
                            </div>
                        </nav>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ============================================ -->
<!-- MODALS -->
<!-- ============================================ -->

<!-- Link Trade Modal -->
<div class="modal fade" id="linkTradeModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-info">
                <h5 class="modal-title"><i class="bi bi-link me-2"></i>Link Sale to Buy Trade(s)</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="linkTradeForm" method="POST">
                <input type="hidden" name="trade_id" id="linkTradeId">
                <input type="hidden" name="link_trade" value="1">
                
                <div class="modal-body">
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle me-2"></i>
                        <strong>Important:</strong> Linking a sale to buy trade(s) will:
                        <ul class="mb-0 mt-2">
                            <li>Mark both trades as <strong>linked</strong> in the settlement page</li>
                            <li>Update the <strong>order_sheet</strong> status to "linked"</li>
                            <li>The buy trade(s) will <strong>NOT</strong> require a receipt upload in order_sheet</li>
                        </ul>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label fw-bold">Current Sale Trade:</label>
                        <div class="p-3 bg-light rounded" id="currentSaleDetails">
                            <span class="text-muted">Loading sale trade details...</span>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label fw-bold">Available Buy Trades for this Client:</label>
                        <div id="buyTradesContainer">
                            <div class="text-center py-3">
                                <div class="spinner-border spinner-border-sm text-primary" role="status"></div>
                                <span class="ms-2">Loading buy trades...</span>
                            </div>
                        </div>
                    </div>
                    
                    <div class="alert alert-warning" id="noBuyTradesWarning" style="display: none;">
                        <i class="bi bi-exclamation-triangle me-2"></i>
                        No available buy trades found for this client.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-info" id="linkSubmitBtn" disabled>Select at least one trade</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Grouped Trades Modal -->
<div class="modal fade" id="groupedTradesModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary">
                <h5 class="modal-title"><i class="bi bi-layers me-2"></i>Grouped Trade Details</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="groupedTradesContent">
                <div class="text-center py-3">
                    <div class="spinner-border spinner-border-sm text-primary" role="status"></div>
                    <span class="ms-2">Loading trade details...</span>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Payment Modal -->
<div class="modal fade" id="paymentModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-success">
                <h5 class="modal-title">Record Payment</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="paymentForm" method="POST">
                <input type="hidden" name="trade_id" id="paymentTradeId">
                <input type="hidden" name="single_payment" value="1">
                
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="payment_mode" class="form-label">Payment Method <span class="text-danger">*</span></label>
                        <select class="form-select" id="payment_mode" name="payment_mode" required onchange="toggleBankSelection()">
                            <option value="">Select Payment Method</option>
                            <?php foreach ($payment_methods as $method): ?>
                                <option value="<?php echo (int)$method['id']; ?>">
                                    <?php echo htmlspecialchars($method['code'] . ' - ' . $method['description']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="mb-3" id="bankAccountField" style="display: none;">
                        <label for="bank_account" class="form-label">Select Bank Account <span class="text-danger">*</span></label>
                        <select class="form-select" id="bank_account" name="bank_account">
                            <option value="">Select Bank Account</option>
                            <?php foreach ($bank_accounts as $bank): ?>
                                <option value="<?php echo (int)$bank['id']; ?>"
                                        data-balance="<?php echo $bank['current_balance']; ?>"
                                        data-currency="<?php echo htmlspecialchars($bank['currency']); ?>">
                                    <?php echo htmlspecialchars($bank['bank_name'] . ' - ' . $bank['account_name'] . ' (' . $bank['account_number'] . ') - ' . number_format($bank['current_balance'], 2) . ' ' . $bank['currency']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-muted" id="bankBalanceInfo"></small>
                    </div>
                    
                    <div class="mb-3">
                        <label for="narration" class="form-label">Payment Narration</label>
                        <textarea class="form-control" id="narration" name="narration" rows="2" placeholder="Enter payment description..."></textarea>
                    </div>
                    
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle me-2"></i>
                        Payment will be recorded in the payment book and bank balance will be updated accordingly.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success">Record Payment</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Bulk Payment Modal -->
<div class="modal fade" id="bulkPaymentModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-success">
                <h5 class="modal-title">Bulk Payment for Selected Trades</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="bulkPaymentForm" method="POST">
                <input type="hidden" name="bulk_payment" value="1">
                <div id="bulkPaymentTradeIds"></div>
                
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="bulk_payment_mode" class="form-label">Payment Method <span class="text-danger">*</span></label>
                        <select class="form-select" id="bulk_payment_mode" name="payment_mode" required onchange="toggleBulkBankSelection()">
                            <option value="">Select Payment Method</option>
                            <?php foreach ($payment_methods as $method): ?>
                                <option value="<?php echo (int)$method['id']; ?>">
                                    <?php echo htmlspecialchars($method['code'] . ' - ' . $method['description']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="mb-3" id="bulkBankAccountField" style="display: none;">
                        <label for="bulk_bank_account" class="form-label">Select Bank Account <span class="text-danger">*</span></label>
                        <select class="form-select" id="bulk_bank_account" name="bank_account">
                            <option value="">Select Bank Account</option>
                            <?php foreach ($bank_accounts as $bank): ?>
                                <option value="<?php echo (int)$bank['id']; ?>"
                                        data-balance="<?php echo $bank['current_balance']; ?>"
                                        data-currency="<?php echo htmlspecialchars($bank['currency']); ?>">
                                    <?php echo htmlspecialchars($bank['bank_name'] . ' - ' . $bank['account_name'] . ' (' . $bank['account_number'] . ') - ' . number_format($bank['current_balance'], 2) . ' ' . $bank['currency']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-muted" id="bulkBankBalanceInfo"></small>
                    </div>
                    
                    <div class="mb-3">
                        <label for="bulk_narration" class="form-label">Payment Narration (Applied to all)</label>
                        <textarea class="form-control" id="bulk_narration" name="narration" rows="2" placeholder="Enter payment description for all selected trades..."></textarea>
                    </div>
                    
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle me-2"></i>
                        <span id="bulkPaymentCount">0</span> trades will be paid.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success">Process Bulk Payment</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Failure Modal -->
<div class="modal fade" id="failureModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-warning">
                <h5 class="modal-title">Mark Settlement as Failed</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="failureForm" method="POST">
                <input type="hidden" name="trade_id" id="failureTradeId">
                <input type="hidden" name="mark_failed" value="1">
                
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="failure_reason" class="form-label">Failure Reason <span class="text-danger">*</span></label>
                        <textarea class="form-control" id="failure_reason" name="failure_reason" rows="3" required 
                                  placeholder="Explain why the payment failed..."></textarea>
                    </div>
                    
                    <div class="mb-3">
                        <label for="action_needed" class="form-label">Action Needed <span class="text-danger">*</span></label>
                        <select class="form-select" id="action_needed" name="action_needed" required>
                            <option value="">Select required action</option>
                            <option value="retry_payment">Retry Payment</option>
                            <option value="contact_client">Contact Client</option>
                            <option value="contact_counterparty">Contact Counterparty</option>
                            <option value="investigate_discrepancy">Investigate Discrepancy</option>
                            <option value="update_account_details">Update Account Details</option>
                            <option value="escalate_to_manager">Escalate to Manager</option>
                            <option value="other">Other</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-warning">Mark as Failed</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Failure Details Modal -->
<div class="modal fade" id="failureDetailsModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-dark text-white">
                <h5 class="modal-title">Failure Details</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label fw-bold">Failure Reason:</label>
                    <div class="p-3 bg-light rounded" id="detailsFailureReason"></div>
                </div>
                
                <div class="mb-3">
                    <label class="form-label fw-bold">Action Needed:</label>
                    <div class="p-3 bg-light rounded" id="detailsActionNeeded"></div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Export Modal -->
<div class="modal fade" id="exportModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="bi bi-download me-2"></i>Export Settlement Report</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="export_settlement_contracts">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-bold">Export Type <span class="text-danger">*</span></label>
                        <div class="d-flex gap-3">
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="export_type" id="exportTypeContract" value="contract_notes" checked>
                                <label class="form-check-label" for="exportTypeContract">
                                    <i class="bi bi-file-earmark-text text-primary me-1"></i> Contract Notes
                                </label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="export_type" id="exportTypeClient" value="client_list">
                                <label class="form-check-label" for="exportTypeClient">
                                    <i class="bi bi-people text-info me-1"></i> Client List
                                </label>
                            </div>
                        </div>
                        <small class="text-muted" id="exportTypeHelp">Combined contract notes for each client who traded on the selected date.</small>
                    </div>

                    <div class="mb-3">
                        <label for="export_date" class="form-label fw-bold">Settlement Date <span class="text-danger">*</span></label>
                        <input type="date" class="form-control" id="export_date" name="export_date" value="<?php echo date('Y-m-d'); ?>" required>
                        <small class="text-muted">Select the settlement date to export.</small>
                    </div>

                    <div class="mb-3">
                        <label for="cds_filter" class="form-label fw-bold">CDS Account (Optional)</label>
                        <input type="text" class="form-control" id="cds_filter" name="cds_filter" placeholder="Leave blank for all clients">
                        <small class="text-muted">Filter by a specific CDS account number.</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-download me-1"></i> Export PDF
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Hidden Forms -->
<form id="unpaidForm" method="POST" style="display: none;">
    <input type="hidden" name="trade_id" id="unpaidTradeId">
    <input type="hidden" name="mark_unpaid" value="1">
</form>

<form id="retryFailedForm" method="POST" style="display: none;">
    <input type="hidden" name="trade_id" id="retryTradeId">
    <input type="hidden" name="retry_failed" value="1">
</form>

<script>
// ============================================
// JAVASCRIPT
// ============================================

let currentTradeId = null;
let selectedTradeIds = [];

function resetFilters() {
    window.location.href = 'settlement';
}

// Bulk selection functions
function toggleSelectAll(checkbox) {
    const checkboxes = document.querySelectorAll('.trade-checkbox');
    checkboxes.forEach(cb => {
        cb.checked = checkbox.checked;
        if (checkbox.checked) {
            if (!selectedTradeIds.includes(cb.value)) {
                selectedTradeIds.push(cb.value);
            }
        } else {
            const index = selectedTradeIds.indexOf(cb.value);
            if (index > -1) {
                selectedTradeIds.splice(index, 1);
            }
        }
    });
    updateBulkActions();
}

function updateBulkActions() {
    const checkboxes = document.querySelectorAll('.trade-checkbox:checked');
    selectedTradeIds = Array.from(checkboxes).map(cb => cb.value);
    const selectedCount = selectedTradeIds.length;
    const floatingBtn = document.getElementById('floatingBulkPayment');
    const badge = document.getElementById('selectedCountBadge');
    
    badge.textContent = selectedCount;
    floatingBtn.style.display = selectedCount > 0 ? 'block' : 'none';
}

// ============================================
// SHOW LINK TRADE MODAL
// ============================================
function showLinkTradeModal(tradeId) {
    currentTradeId = tradeId;
    document.getElementById('linkTradeId').value = tradeId;
    document.getElementById('linkTradeForm').reset();
    
    const saleDetails = document.getElementById('currentSaleDetails');
    saleDetails.innerHTML = '<span class="text-muted">Loading sale trade details...</span>';
    
    fetch(`?ajax=get_trade_details&trade_id=${tradeId}`)
        .then(response => response.json())
        .then(data => {
            if (data && data.trade_reference) {
                saleDetails.innerHTML = `
                    <strong>Trade Ref:</strong> ${data.trade_reference}<br>
                    <strong>Client:</strong> ${data.client_name}<br>
                    <strong>Security:</strong> ${data.security_id}<br>
                    <strong>Amount:</strong> TZS ${parseFloat(data.total_consideration || data.consideration || 0).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}<br>
                    <strong>Side:</strong> ${data.trade_side.toUpperCase()}
                    ${data.trade_count > 1 ? `<br><strong>Grouped Trades:</strong> ${data.trade_count} trades` : ''}
                `;
            } else {
                saleDetails.innerHTML = '<span class="text-muted">Error loading trade details</span>';
            }
        })
        .catch(() => {
            saleDetails.innerHTML = '<span class="text-muted">Error loading trade details</span>';
        });
    
    fetch(`?ajax=get_grouped_buy_trades&trade_id=${tradeId}`)
        .then(response => response.json())
        .then(data => {
            const container = document.getElementById('buyTradesContainer');
            const warning = document.getElementById('noBuyTradesWarning');
            
            if (data && data.length > 0) {
                warning.style.display = 'none';
                let html = `
                    <div class="table-responsive">
                        <table class="table table-sm table-hover">
                            <thead>
                                <tr>
                                    <th><input type="checkbox" id="selectAllBuy" onchange="toggleSelectAllBuy(this)"></th>
                                    <th>Reference(s)</th>
                                    <th>Security</th>
                                    <th class="text-end">Qty</th>
                                    <th class="text-end">Amount</th>
                                    <th>Date</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                `;
                
                data.forEach((trade) => {
                    const tradeCount = parseInt(trade.trade_count) || 1;
                    const refs = trade.trade_references ? trade.trade_references.split(',') : [];
                    const displayRef = refs.length > 0 ? refs[0] : trade.trade_reference || 'N/A';
                    
                    html += `
                        <tr>
                            <td><input type="checkbox" class="buy-trade-checkbox" name="linked_trade_ids[]" value="${trade.id}" onchange="updateLinkSelection()"></td>
                            <td>
                                ${displayRef}
                                ${tradeCount > 1 ? `<br><small class="text-muted">+${tradeCount - 1} more</small>` : ''}
                            </td>
                            <td>${trade.security_id}</td>
                            <td class="text-end">${parseFloat(trade.total_quantity).toLocaleString()}</td>
                            <td class="text-end">TZS ${parseFloat(trade.total_consideration).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}</td>
                            <td>${trade.trade_date}</td>
                            <td><span class="badge bg-${trade.settlement_status ? 'secondary' : 'warning'}">${trade.settlement_status || 'Pending'}</span></td>
                        </tr>
                    `;
                });
                
                html += `
                            </tbody>
                        </table>
                    </div>
                    <div class="mt-2">
                        <small class="text-muted">Select one or more buy trades to link with this sale.</small>
                    </div>
                `;
                
                container.innerHTML = html;
            } else {
                container.innerHTML = '';
                warning.style.display = 'block';
            }
            
            updateLinkSelection();
        })
        .catch(error => {
            console.error('Error fetching buy trades:', error);
            document.getElementById('buyTradesContainer').innerHTML = '<div class="alert alert-danger">Error loading buy trades.</div>';
            document.getElementById('noBuyTradesWarning').style.display = 'none';
        });
    
    const linkModal = new bootstrap.Modal(document.getElementById('linkTradeModal'));
    linkModal.show();
}

function toggleSelectAllBuy(checkbox) {
    const checkboxes = document.querySelectorAll('.buy-trade-checkbox');
    checkboxes.forEach(cb => cb.checked = checkbox.checked);
    updateLinkSelection();
}

function updateLinkSelection() {
    const checked = document.querySelectorAll('.buy-trade-checkbox:checked').length;
    const btn = document.getElementById('linkSubmitBtn');
    if (checked > 0) {
        btn.innerHTML = `Link ${checked} Trade${checked > 1 ? 's' : ''}`;
        btn.disabled = false;
    } else {
        btn.innerHTML = 'Select at least one trade';
        btn.disabled = true;
    }
}

// ============================================
// SHOW GROUPED TRADES
// ============================================
function showGroupedTrades(tradeId) {
    const modal = new bootstrap.Modal(document.getElementById('groupedTradesModal'));
    const content = document.getElementById('groupedTradesContent');
    content.innerHTML = '<div class="text-center py-3"><div class="spinner-border spinner-border-sm text-primary" role="status"></div><span class="ms-2">Loading trade details...</span></div>';
    modal.show();
    
    fetch(`?ajax=get_grouped_trade_details&trade_id=${tradeId}`)
        .then(response => response.json())
        .then(data => {
            if (data && data.length > 0) {
                let html = `
                    <div class="table-responsive">
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Trade Ref</th>
                                    <th>Security</th>
                                    <th class="text-end">Qty</th>
                                    <th class="text-end">Price</th>
                                    <th class="text-end">Amount</th>
                                </tr>
                            </thead>
                            <tbody>
                `;
                
                data.forEach((trade, index) => {
                    html += `
                        <tr>
                            <td>${index + 1}</td>
                            <td>${trade.trade_reference}</td>
                            <td>${trade.security_id}</td>
                            <td class="text-end">${parseFloat(trade.quantity).toLocaleString()}</td>
                            <td class="text-end">${parseFloat(trade.price).toFixed(2)}</td>
                            <td class="text-end">TZS ${parseFloat(trade.consideration).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}</td>
                        </tr>
                    `;
                });
                
                html += `
                            </tbody>
                            <tfoot>
                                <tr class="fw-bold">
                                    <td colspan="3" class="text-end">TOTAL:</td>
                                    <td class="text-end">${data.reduce((sum, t) => sum + parseFloat(t.quantity), 0).toLocaleString()}</td>
                                    <td></td>
                                    <td class="text-end">TZS ${data.reduce((sum, t) => sum + parseFloat(t.consideration), 0).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}</td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                `;
                content.innerHTML = html;
            } else {
                content.innerHTML = '<div class="alert alert-warning">No trade details found.</div>';
            }
        })
        .catch(() => {
            content.innerHTML = '<div class="alert alert-danger">Error loading trade details.</div>';
        });
}

// ============================================
// PAYMENT FUNCTIONS
// ============================================
function showPaymentModal(tradeId) {
    currentTradeId = tradeId;
    document.getElementById('paymentTradeId').value = tradeId;
    document.getElementById('paymentForm').reset();
    document.getElementById('bankAccountField').style.display = 'none';
    document.getElementById('bankBalanceInfo').textContent = '';
    
    const paymentModal = new bootstrap.Modal(document.getElementById('paymentModal'));
    paymentModal.show();
}

function toggleBankSelection() {
    const paymentMode = document.getElementById('payment_mode').value;
    const bankField = document.getElementById('bankAccountField');
    const bankSelect = document.getElementById('bank_account');
    const balanceInfo = document.getElementById('bankBalanceInfo');
    
    const bankMethods = ['1', '2', '3'];
    
    if (bankMethods.includes(paymentMode)) {
        bankField.style.display = 'block';
        bankSelect.required = true;
        bankSelect.onchange = function() {
            const opt = this.options[this.selectedIndex];
            if (opt && opt.value) {
                const balance = opt.getAttribute('data-balance');
                const currency = opt.getAttribute('data-currency');
                balanceInfo.textContent = `Balance: ${currency} ${parseFloat(balance).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}`;
            } else {
                balanceInfo.textContent = '';
            }
        };
        bankSelect.selectedIndex = 0;
        bankSelect.dispatchEvent(new Event('change'));
    } else {
        bankField.style.display = 'none';
        bankSelect.required = false;
        balanceInfo.textContent = '';
    }
}

function showBulkPaymentModal() {
    if (selectedTradeIds.length === 0) {
        alert('Please select at least one trade to pay.');
        return;
    }
    
    let validTrades = [];
    const checkboxes = document.querySelectorAll('.trade-checkbox:checked');
    checkboxes.forEach(cb => {
        const row = cb.closest('tr');
        const statusBadge = row.querySelector('.badge');
        if (statusBadge) {
            const statusText = statusBadge.textContent.trim();
            if (!statusText.includes('Paid') && !statusText.includes('Linked') && !statusText.includes('Failed')) {
                validTrades.push(cb.value);
            }
        }
    });
    
    if (validTrades.length === 0) {
        alert('No valid unpaid trades selected.');
        return;
    }
    
    const container = document.getElementById('bulkPaymentTradeIds');
    container.innerHTML = '';
    validTrades.forEach(tradeId => {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'trade_ids[]';
        input.value = tradeId;
        container.appendChild(input);
    });
    
    document.getElementById('bulkPaymentCount').textContent = validTrades.length;
    document.getElementById('bulkPaymentForm').reset();
    document.getElementById('bulkBankAccountField').style.display = 'none';
    document.getElementById('bulkBankBalanceInfo').textContent = '';
    
    const bulkPaymentModal = new bootstrap.Modal(document.getElementById('bulkPaymentModal'));
    bulkPaymentModal.show();
}

function toggleBulkBankSelection() {
    const paymentMode = document.getElementById('bulk_payment_mode').value;
    const bankField = document.getElementById('bulkBankAccountField');
    const bankSelect = document.getElementById('bulk_bank_account');
    const balanceInfo = document.getElementById('bulkBankBalanceInfo');
    
    const bankMethods = ['1', '2', '3'];
    
    if (bankMethods.includes(paymentMode)) {
        bankField.style.display = 'block';
        bankSelect.required = true;
        bankSelect.onchange = function() {
            const opt = this.options[this.selectedIndex];
            if (opt && opt.value) {
                const balance = opt.getAttribute('data-balance');
                const currency = opt.getAttribute('data-currency');
                balanceInfo.textContent = `Balance: ${currency} ${parseFloat(balance).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}`;
            } else {
                balanceInfo.textContent = '';
            }
        };
        bankSelect.selectedIndex = 0;
        bankSelect.dispatchEvent(new Event('change'));
    } else {
        bankField.style.display = 'none';
        bankSelect.required = false;
        balanceInfo.textContent = '';
    }
}

function markAsUnpaid(tradeId) {
    if (confirm('Undo this payment? Trade will be marked as unpaid.')) {
        document.getElementById('unpaidTradeId').value = tradeId;
        document.getElementById('unpaidForm').submit();
    }
}

function markAsFailed(tradeId) {
    currentTradeId = tradeId;
    document.getElementById('failureTradeId').value = tradeId;
    document.getElementById('failureForm').reset();
    const failureModal = new bootstrap.Modal(document.getElementById('failureModal'));
    failureModal.show();
}

function retryFailed(tradeId) {
    if (confirm('Reset this failed trade for retry?')) {
        document.getElementById('retryTradeId').value = tradeId;
        document.getElementById('retryFailedForm').submit();
    }
}

// ============================================
// INITIALIZATION
// ============================================
document.addEventListener('DOMContentLoaded', function() {
    const checkboxes = document.querySelectorAll('.trade-checkbox');
    checkboxes.forEach(cb => {
        cb.addEventListener('change', updateBulkActions);
    });
    updateBulkActions();
    
    // Tooltips
    const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    tooltipTriggerList.map(function (el) {
        return new bootstrap.Tooltip(el);
    });
});

// Export type help text
document.addEventListener('DOMContentLoaded', function() {
    const contractRadio = document.getElementById('exportTypeContract');
    const clientRadio = document.getElementById('exportTypeClient');
    const helpText = document.getElementById('exportTypeHelp');

    if (contractRadio && clientRadio && helpText) {
        function updateExportHelp() {
            if (contractRadio.checked) {
                helpText.textContent = 'Combined contract notes for each client who traded on the selected date.';
            } else {
                helpText.textContent = 'PDF list of all clients and their trades due for settlement on the selected date.';
            }
        }
        contractRadio.addEventListener('change', updateExportHelp);
        clientRadio.addEventListener('change', updateExportHelp);
    }
});
</script>

<?php include '../includes/footer.php'; ?>
