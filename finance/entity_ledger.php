<?php
// ============================================
// ENTITY LEDGER - CLIENT RECEIPTS & PAYMENTS ONLY
// ============================================

error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/entity_ledger_errors.log');

// Start output buffering
if (ob_get_level() == 0) {
    ob_start();
}

require_once '../config/config.php';
require_once '../auth/auth_middleware.php';

// Check if session is started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_trader();

$db = getDBConnection();
$current_user = get_logged_in_user() ?: get_session_user();
$user_name = $current_user['username'] ?? 'System';
$user_id = $current_user['id'] ?? null;

// ============================================
// GET FILTER PARAMETERS
// ============================================
$entity_type = $_GET['type'] ?? 'client';
$entity_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$entity_name = isset($_GET['name']) ? $_GET['name'] : '';
$date_from = $_GET['date_from'] ?? date('Y-m-01');
$date_to = $_GET['date_to'] ?? date('Y-m-d');
$search = $_GET['search'] ?? '';

// ============================================
// GET ENTITY DETAILS
// ============================================
function getEntityDetails($db, $type, $id) {
    $entity = null;
    
    if ($type === 'client') {
        $stmt = $db->prepare("SELECT id, client_name as name, cds_account as code, client_type, phone, email FROM clients WHERE id = ? AND is_active = 1");
        $stmt->execute([$id]);
        $entity = $stmt->fetch(PDO::FETCH_ASSOC);
    } elseif ($type === 'custodian') {
        $stmt = $db->prepare("SELECT id, custodian_name as name, custodian_code as code, contact_person, phone, email FROM custodians WHERE id = ? AND status = 'active'");
        $stmt->execute([$id]);
        $entity = $stmt->fetch(PDO::FETCH_ASSOC);
    } elseif ($type === 'employee') {
        $stmt = $db->prepare("SELECT id, full_name as name, username as code, role, phone, email FROM users WHERE id = ? AND status = 'active'");
        $stmt->execute([$id]);
        $entity = $stmt->fetch(PDO::FETCH_ASSOC);
    } elseif ($type === 'agent') {
        $stmt = $db->prepare("SELECT id, name, agent_code as code, contact_person, phone, email FROM agents WHERE id = ? AND status = 'active'");
        $stmt->execute([$id]);
        $entity = $stmt->fetch(PDO::FETCH_ASSOC);
    } elseif ($type === 'broker') {
        $stmt = $db->prepare("SELECT id, broker_name as name, broker_code as code, contact_person, phone, email FROM brokers WHERE id = ? AND status = 'active'");
        $stmt->execute([$id]);
        $entity = $stmt->fetch(PDO::FETCH_ASSOC);
    } elseif ($type === 'supplier') {
        $stmt = $db->prepare("SELECT id, name, supplier_code as code, contact_person, phone, email FROM suppliers WHERE id = ? AND status = 'active'");
        $stmt->execute([$id]);
        $entity = $stmt->fetch(PDO::FETCH_ASSOC);
    } elseif ($type === 'chart_account') {
        $stmt = $db->prepare("SELECT account_code as id, account_name as name, account_code as code, account_type, level FROM chart_of_accounts WHERE account_code = ? AND is_active = 1");
        $stmt->execute([$id]);
        $entity = $stmt->fetch(PDO::FETCH_ASSOC);
    } elseif ($type === 'bank_account') {
        $stmt = $db->prepare("SELECT id, account_name as name, account_number as code, bank_name, currency, current_balance FROM banks_accounts WHERE id = ? AND status = 'active'");
        $stmt->execute([$id]);
        $entity = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    return $entity;
}

// ============================================
// GET CLIENT TRANSACTIONS - RECEIPTS & PAYMENTS ONLY
// ============================================
function getClientTransactions($db, $id, $date_from, $date_to) {
    $transactions = [];
    
    // 1. GET RECEIPTS for this client (Credit - money received)
    $sql = "
        SELECT 
            'Receipt' as source,
            r.id as reference_id,
            r.receipt_no as reference,
            r.receipt_date as transaction_date,
            r.narration as description,
            r.amount,
            r.currency,
            r.account_no as bank_account,
            'Credit' as debit_credit,
            0 as debit_amount,
            r.amount as credit_amount,
            r.created_by,
            r.created_at,
            r.status,
            'Payment Received' as category
        FROM receipts r
        WHERE r.name_id = ?
        AND r.account_of = 'C'
        AND r.record_in_financial = 'yes'
        AND r.receipt_date BETWEEN ? AND ?
        AND r.status != 'cancelled'
        ORDER BY r.receipt_date ASC
    ";
    $stmt = $db->prepare($sql);
    $stmt->execute([$id, $date_from, $date_to]);
    $receipts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $transactions = array_merge($transactions, $receipts);
    
    // 2. GET PAYMENTS for this client (Debit - money paid)
    $sql = "
        SELECT 
            'Payment' as source,
            p.id as reference_id,
            p.payment_no as reference,
            p.payment_date as transaction_date,
            p.narration as description,
            p.amount,
            p.currency,
            p.account_no as bank_account,
            'Debit' as debit_credit,
            p.amount as debit_amount,
            0 as credit_amount,
            p.created_by,
            p.created_at,
            p.status,
            'Payment Made' as category
        FROM payments p
        WHERE p.name_id = ?
        AND p.paid_to = 'C'
        AND p.record_in_financial = 'yes'
        AND p.payment_date BETWEEN ? AND ?
        AND p.status != 'cancelled'
        ORDER BY p.payment_date ASC
    ";
    $stmt = $db->prepare($sql);
    $stmt->execute([$id, $date_from, $date_to]);
    $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $transactions = array_merge($transactions, $payments);
    
    // Sort by transaction date
    usort($transactions, function($a, $b) {
        return strtotime($a['transaction_date']) - strtotime($b['transaction_date']);
    });
    
    // Calculate running balance
    // For client: Debit (payment) increases balance, Credit (receipt) decreases balance
    $balance = 0;
    foreach ($transactions as &$t) {
        $debit = floatval($t['debit_amount'] ?? 0);
        $credit = floatval($t['credit_amount'] ?? 0);
        
        // Debit increases what client owes, Credit decreases it
        $balance = $balance + $debit - $credit;
        $t['running_balance'] = $balance;
    }
    
    return $transactions;
}

// ============================================
// GET CLIENT SUMMARY - RECEIPTS & PAYMENTS ONLY
// ============================================
function getClientSummary($db, $id, $date_from, $date_to) {
    $summary = [
        'total_debit' => 0,
        'total_credit' => 0,
        'net_balance' => 0,
        'receipt_count' => 0,
        'payment_count' => 0,
        'total_count' => 0
    ];
    
    // Receipts summary (Credits)
    $sql = "SELECT COUNT(*) as count, SUM(amount) as total FROM receipts WHERE name_id = ? AND account_of = 'C' AND record_in_financial = 'yes' AND receipt_date BETWEEN ? AND ? AND status != 'cancelled'";
    $stmt = $db->prepare($sql);
    $stmt->execute([$id, $date_from, $date_to]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($result) {
        $summary['receipt_count'] = (int)$result['count'];
        $summary['total_credit'] = floatval($result['total']);
    }
    
    // Payments summary (Debits)
    $sql = "SELECT COUNT(*) as count, SUM(amount) as total FROM payments WHERE name_id = ? AND paid_to = 'C' AND record_in_financial = 'yes' AND payment_date BETWEEN ? AND ? AND status != 'cancelled'";
    $stmt = $db->prepare($sql);
    $stmt->execute([$id, $date_from, $date_to]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($result) {
        $summary['payment_count'] = (int)$result['count'];
        $summary['total_debit'] = floatval($result['total']);
    }
    
    $summary['total_count'] = $summary['receipt_count'] + $summary['payment_count'];
    $summary['net_balance'] = $summary['total_debit'] - $summary['total_credit'];
    
    return $summary;
}

// ============================================
// GET DATA
// ============================================
$entity = null;
$transactions = [];
$summary = [];
$error_message = '';

try {
    if ($entity_id > 0) {
        $entity = getEntityDetails($db, $entity_type, $entity_id);
        if ($entity) {
            $entity_name = $entity['name'];
            $transactions = getClientTransactions($db, $entity_id, $date_from, $date_to);
            $summary = getClientSummary($db, $entity_id, $date_from, $date_to);
        } else {
            $error_message = "Entity not found.";
        }
    } else {
        $error_message = "No entity selected.";
    }
} catch (Exception $e) {
    $error_message = "Error loading data: " . $e->getMessage();
    error_log("Entity ledger error: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
}

$page_title = 'Client Ledger - ' . ($entity ? htmlspecialchars($entity['name']) : '');
include '../includes/header.php';
?>

<style>
    .stat-box {
        background: #f8f9fa;
        border: 1px solid #ddd;
        border-radius: 4px;
        padding: 10px 15px;
        text-align: center;
    }
    .stat-box .stat-number {
        font-size: 22px;
        font-weight: 600;
        color: #333;
    }
    .stat-box .stat-label {
        font-size: 12px;
        color: #666;
        margin-top: 2px;
    }
    .stat-box .stat-number.positive {
        color: #28a745;
    }
    .stat-box .stat-number.negative {
        color: #dc3545;
    }
    
    .filter-section {
        background: #f8f9fa;
        padding: 15px;
        border-radius: 4px;
        margin-bottom: 20px;
        border: 1px solid #e9ecef;
    }
    .filter-section .form-label {
        font-size: 12px;
        font-weight: 600;
        color: #495057;
        margin-bottom: 4px;
    }
    
    .table-condensed td, .table-condensed th {
        padding: 6px 8px;
        font-size: 13px;
    }
    
    .badge-debit {
        background: #f8f9fa;
        color: #dc3545;
        border: 1px solid #dc3545;
        padding: 2px 8px;
        border-radius: 3px;
        font-size: 11px;
    }
    .badge-credit {
        background: #f8f9fa;
        color: #28a745;
        border: 1px solid #28a745;
        padding: 2px 8px;
        border-radius: 3px;
        font-size: 11px;
    }
    .badge-source {
        background: #f8f9fa;
        border: 1px solid #6c757d;
        color: #6c757d;
        padding: 2px 8px;
        border-radius: 3px;
        font-size: 10px;
    }
    
    .entity-info {
        background: #f8f9fa;
        border: 1px solid #e9ecef;
        border-radius: 4px;
        padding: 15px 20px;
        margin-bottom: 20px;
    }
    .entity-info .label {
        font-size: 12px;
        color: #999;
        font-weight: 500;
    }
    .entity-info .value {
        font-weight: 600;
        color: #333;
    }
</style>

<div class="container-fluid">
    <!-- Page Header -->
    <div class="row mb-3">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h4 class="mb-0">
                        <i class="bi bi-journal"></i> Client Ledger
                    </h4>
                    <?php if ($entity): ?>
                        <small class="text-muted">
                            <?php echo htmlspecialchars($entity['name']); ?> 
                            (Client)
                            <?php if (!empty($entity['code'])): ?>
                                - CDS: <?php echo htmlspecialchars($entity['code']); ?>
                            <?php endif; ?>
                        </small>
                    <?php endif; ?>
                </div>
                <div>
                    <a href="debtors_credit.php" class="btn btn-secondary btn-sm">
                        <i class="bi bi-arrow-left"></i> Back to List
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- Alert Messages -->
    <?php if (isset($_SESSION['alert'])): ?>
        <div class="alert alert-<?php echo $_SESSION['alert'][1]; ?> alert-dismissible fade show">
            <?php echo htmlspecialchars($_SESSION['alert'][0]); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php unset($_SESSION['alert']); ?>
    <?php endif; ?>

    <?php if (!empty($error_message)): ?>
        <div class="alert alert-danger"><?php echo htmlspecialchars($error_message); ?></div>
    <?php endif; ?>

    <?php if ($entity): ?>
        <!-- Entity Info -->
        <div class="entity-info">
            <div class="row">
                <div class="col-md-3">
                    <div class="label">Client Name</div>
                    <div class="value"><?php echo htmlspecialchars($entity['name']); ?></div>
                </div>
                <div class="col-md-2">
                    <div class="label">CDS Account</div>
                    <div class="value"><?php echo htmlspecialchars($entity['code'] ?? 'N/A'); ?></div>
                </div>
                <div class="col-md-2">
                    <div class="label">Client Type</div>
                    <div class="value"><?php echo htmlspecialchars($entity['client_type'] ?? 'N/A'); ?></div>
                </div>
                <div class="col-md-2">
                    <div class="label">Phone</div>
                    <div class="value"><?php echo htmlspecialchars($entity['phone'] ?? 'N/A'); ?></div>
                </div>
                <div class="col-md-3">
                    <div class="label">Email</div>
                    <div class="value"><?php echo htmlspecialchars($entity['email'] ?? 'N/A'); ?></div>
                </div>
            </div>
        </div>

        <!-- Summary Stats -->
        <div class="row mb-3 g-2">
            <div class="col-md-3 col-6">
                <div class="stat-box">
                    <div class="stat-number text-danger"><?php echo number_format($summary['total_debit'], 2); ?></div>
                    <div class="stat-label">Total Payments (DR)</div>
                    <small class="text-muted"><?php echo $summary['payment_count']; ?> transactions</small>
                </div>
            </div>
            <div class="col-md-3 col-6">
                <div class="stat-box">
                    <div class="stat-number text-success"><?php echo number_format($summary['total_credit'], 2); ?></div>
                    <div class="stat-label">Total Receipts (CR)</div>
                    <small class="text-muted"><?php echo $summary['receipt_count']; ?> transactions</small>
                </div>
            </div>
            <div class="col-md-3 col-6">
                <div class="stat-box">
                    <div class="stat-number <?php echo $summary['net_balance'] >= 0 ? 'positive' : 'negative'; ?>">
                        <?php echo number_format(abs($summary['net_balance']), 2); ?>
                    </div>
                    <div class="stat-label">Net Balance</div>
                    <small class="text-muted"><?php echo $summary['net_balance'] >= 0 ? 'Client Owes Us' : 'We Owe Client'; ?></small>
                </div>
            </div>
            <div class="col-md-3 col-6">
                <div class="stat-box">
                    <div class="stat-number"><?php echo $summary['total_count']; ?></div>
                    <div class="stat-label">Total Transactions</div>
                    <small class="text-muted">Receipts + Payments</small>
                </div>
            </div>
        </div>

        <!-- Filters -->
        <div class="filter-section">
            <form method="GET" class="row g-2 align-items-end">
                <input type="hidden" name="type" value="<?php echo htmlspecialchars($entity_type); ?>">
                <input type="hidden" name="id" value="<?php echo htmlspecialchars($entity_id); ?>">
                <input type="hidden" name="name" value="<?php echo htmlspecialchars($entity_name); ?>">
                
                <div class="col-md-3">
                    <label class="form-label">Date From</label>
                    <input type="date" class="form-control form-control-sm" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Date To</label>
                    <input type="date" class="form-control form-control-sm" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">&nbsp;</label>
                    <button type="submit" class="btn btn-secondary btn-sm w-100">Apply Filters</button>
                </div>
                <div class="col-md-3">
                    <label class="form-label">&nbsp;</label>
                    <a href="entity_ledger.php?type=<?php echo urlencode($entity_type); ?>&id=<?php echo urlencode($entity_id); ?>&name=<?php echo urlencode($entity_name); ?>" class="btn btn-outline-secondary btn-sm w-100">Reset</a>
                </div>
            </form>
        </div>

        <!-- Transactions Table -->
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h6 class="mb-0">
                    <i class="bi bi-list-ul"></i> Receipts & Payments History
                    <span class="badge bg-secondary ms-2"><?php echo count($transactions); ?></span>
                </h6>
            </div>
            <div class="card-body p-0">
                <?php if (empty($transactions)): ?>
                    <div class="text-center py-5">
                        <i class="bi bi-inbox" style="font-size: 48px; color: #dee2e6;"></i>
                        <p class="text-muted mt-3">No receipts or payments found for this client in the selected date range.</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm table-hover mb-0 table-condensed">
                            <thead class="table-light">
                                <tr>
                                    <th>Date</th>
                                    <th>Source</th>
                                    <th>Reference</th>
                                    <th>Description</th>
                                    <th>Type</th>
                                    <th class="text-end">Payment (DR)</th>
                                    <th class="text-end">Receipt (CR)</th>
                                    <th class="text-end">Balance</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($transactions as $t): 
                                    $isDebit = ($t['debit_credit'] === 'Debit' || $t['debit_credit'] === 'DR');
                                    $amount = floatval($t['amount'] ?? 0);
                                    $debit = floatval($t['debit_amount'] ?? 0);
                                    $credit = floatval($t['credit_amount'] ?? 0);
                                    
                                    if ($isDebit) {
                                        $display_debit = $debit > 0 ? $debit : $amount;
                                        $display_credit = 0;
                                        $type_label = 'Payment';
                                        $type_class = 'badge-debit';
                                    } else {
                                        $display_debit = 0;
                                        $display_credit = $credit > 0 ? $credit : $amount;
                                        $type_label = 'Receipt';
                                        $type_class = 'badge-credit';
                                    }
                                ?>
                                    <tr>
                                        <td><?php echo date('d/m/Y', strtotime($t['transaction_date'])); ?></td>
                                        <td>
                                            <span class="badge-source"><?php echo htmlspecialchars($t['source'] ?? 'N/A'); ?></span>
                                        </td>
                                        <td>
                                            <span class="fw-semibold small"><?php echo htmlspecialchars($t['reference'] ?? 'N/A'); ?></span>
                                            <?php if (!empty($t['reference_id'])): ?>
                                                <br><small class="text-muted">#<?php echo $t['reference_id']; ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php echo htmlspecialchars($t['description'] ?? ''); ?>
                                            <?php if (!empty($t['category'])): ?>
                                                <br><small class="text-muted"><?php echo htmlspecialchars($t['category']); ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="<?php echo $type_class; ?>">
                                                <?php echo $type_label; ?>
                                            </span>
                                        </td>
                                        <td class="text-end">
                                            <?php if ($display_debit > 0): ?>
                                                <span class="badge-debit">TZS <?php echo number_format($display_debit, 2); ?></span>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end">
                                            <?php if ($display_credit > 0): ?>
                                                <span class="badge-credit">TZS <?php echo number_format($display_credit, 2); ?></span>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end fw-bold">
                                            TZS <?php echo number_format($t['running_balance'] ?? 0, 2); ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot class="table-light">
                                <tr>
                                    <td colspan="5" class="text-end fw-bold">Totals:</td>
                                    <td class="text-end fw-bold">
                                        <span class="badge-debit">TZS <?php echo number_format($summary['total_debit'], 2); ?></span>
                                    </td>
                                    <td class="text-end fw-bold">
                                        <span class="badge-credit">TZS <?php echo number_format($summary['total_credit'], 2); ?></span>
                                    </td>
                                    <td class="text-end fw-bold">
                                        <?php if ($summary['net_balance'] > 0): ?>
                                            <span class="text-danger">DR TZS <?php echo number_format($summary['net_balance'], 2); ?></span>
                                        <?php elseif ($summary['net_balance'] < 0): ?>
                                            <span class="text-success">CR TZS <?php echo number_format(abs($summary['net_balance']), 2); ?></span>
                                        <?php else: ?>
                                            <span class="text-muted">Settled</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</div>

<script>
// ============================================
// CLIENT LEDGER - INITIALIZATION
// ============================================

// Auto-refresh alerts after 5 seconds
document.querySelectorAll('.alert').forEach(alert => {
    setTimeout(() => {
        const bsAlert = bootstrap.Alert.getOrCreateInstance(alert);
        if (bsAlert) bsAlert.close();
    }, 5000);
});

console.log('Client Ledger System initialized.');
</script>

<?php include '../includes/footer.php'; ?>
