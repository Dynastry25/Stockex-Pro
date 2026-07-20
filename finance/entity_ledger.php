<?php
// ============================================
// ENTITY LEDGER - COMPLETE CLIENT TRANSACTIONS
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
$entity_type = $_GET['entity_type'] ?? 'clients';
$entity_id = isset($_GET['entity_id']) ? (int)$_GET['entity_id'] : 0;
$date_from = $_GET['date_from'] ?? date('Y-m-01');
$date_to = $_GET['date_to'] ?? date('Y-m-d');
$search = $_GET['search'] ?? '';

// ============================================
// GET ENTITY LIST
// ============================================
function getEntities($db, $type = 'clients', $search = '') {
    $entities = [];
    
    if ($type === 'clients') {
        $sql = "SELECT DISTINCT client_name as name, client_cds_account as code FROM trades WHERE client_name IS NOT NULL AND client_name != ''";
        if (!empty($search)) {
            $sql .= " AND client_name LIKE ?";
            $params = ["%$search%"];
        } else {
            $params = [];
        }
        $sql .= " ORDER BY client_name LIMIT 100";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    return $entities;
}

// ============================================
// GET COMPLETE CLIENT LEDGER
// ============================================
function getClientLedger($db, $client_name, $date_from, $date_to) {
    $transactions = [];
    
    // 1. GET TRADE TRANSACTIONS (Both Buy and Sell)
    $sql = "
        SELECT 
            'Trade' as source,
            t.id as reference_id,
            t.trade_reference as reference,
            t.trade_date as transaction_date,
            t.trade_side as transaction_type,
            t.asset_class,
            t.security_id,
            t.security_name,
            t.quantity,
            t.price,
            t.consideration as amount,
            t.status,
            t.uploaded_by as created_by,
            t.created_at,
            'Trade' as category,
            CASE 
                WHEN LOWER(t.trade_side) = 'buy' THEN 'Debit'
                WHEN LOWER(t.trade_side) = 'sell' THEN 'Credit'
                ELSE 'Debit'
            END as debit_credit,
            t.consideration as debit_amount,
            CASE 
                WHEN LOWER(t.trade_side) = 'sell' THEN t.consideration
                ELSE 0
            END as credit_amount,
            t.client_name,
            t.client_cds_account
        FROM trades t
        WHERE t.client_name = ?
        AND t.trade_date BETWEEN ? AND ?
        AND t.status != 'cancelled'
    ";
    
    $stmt = $db->prepare($sql);
    $stmt->execute([$client_name, $date_from, $date_to]);
    $trade_transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $transactions = array_merge($transactions, $trade_transactions);
    
    // 2. GET JOURNAL ENTRIES (Both Debit and Credit)
    $sql = "
        SELECT 
            'Journal' as source,
            j.id as reference_id,
            j.journal_reference as reference,
            j.entry_date as transaction_date,
            j.description as transaction_type,
            j.asset_class,
            j.security_id,
            j.security_name,
            j.quantity,
            j.price,
            j.amount,
            j.status,
            j.created_by,
            j.created_at,
            j.category,
            j.debit_credit,
            j.debit_amount,
            j.credit_amount,
            j.client_name,
            j.client_cds_account
        FROM journal_entries j
        WHERE j.client_name = ?
        AND j.entry_date BETWEEN ? AND ?
        AND j.status != 'cancelled'
    ";
    
    $stmt = $db->prepare($sql);
    $stmt->execute([$client_name, $date_from, $date_to]);
    $journal_transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $transactions = array_merge($transactions, $journal_transactions);
    
    // 3. GET PAYMENT RECEIPTS (If linked to client)
    $sql = "
        SELECT 
            'Payment' as source,
            pr.id as reference_id,
            pr.receipt_reference as reference,
            pr.receipt_date as transaction_date,
            'Payment Receipt' as transaction_type,
            NULL as asset_class,
            NULL as security_id,
            NULL as security_name,
            NULL as quantity,
            NULL as price,
            pr.amount,
            pr.status,
            pr.created_by,
            pr.created_at,
            'Payment' as category,
            'Credit' as debit_credit,
            0 as debit_amount,
            pr.amount as credit_amount,
            pr.client_name,
            pr.client_cds_account
        FROM payment_receipts pr
        WHERE pr.client_name = ?
        AND pr.receipt_date BETWEEN ? AND ?
        AND pr.status != 'cancelled'
    ";
    
    $stmt = $db->prepare($sql);
    $stmt->execute([$client_name, $date_from, $date_to]);
    $payment_transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $transactions = array_merge($transactions, $payment_transactions);
    
    // Sort by transaction date
    usort($transactions, function($a, $b) {
        return strtotime($a['transaction_date']) - strtotime($b['transaction_date']);
    });
    
    // Calculate running balance
    $balance = 0;
    foreach ($transactions as &$t) {
        if ($t['debit_credit'] === 'Debit' || $t['debit_credit'] === 'DR') {
            $balance += floatval($t['debit_amount'] ?? $t['amount'] ?? 0);
        } else {
            $balance -= floatval($t['credit_amount'] ?? $t['amount'] ?? 0);
        }
        $t['running_balance'] = $balance;
    }
    
    return $transactions;
}

// ============================================
// GET LEDGER SUMMARY
// ============================================
function getLedgerSummary($db, $client_name, $date_from, $date_to) {
    $summary = [
        'total_debit' => 0,
        'total_credit' => 0,
        'net_balance' => 0,
        'trade_count' => 0,
        'journal_count' => 0,
        'payment_count' => 0
    ];
    
    // Get trades summary
    $sql = "
        SELECT 
            COUNT(*) as count,
            SUM(CASE WHEN LOWER(trade_side) = 'buy' THEN consideration ELSE 0 END) as debit,
            SUM(CASE WHEN LOWER(trade_side) = 'sell' THEN consideration ELSE 0 END) as credit
        FROM trades
        WHERE client_name = ?
        AND trade_date BETWEEN ? AND ?
        AND status != 'cancelled'
    ";
    $stmt = $db->prepare($sql);
    $stmt->execute([$client_name, $date_from, $date_to]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($result) {
        $summary['trade_count'] = (int)$result['count'];
        $summary['total_debit'] += floatval($result['debit']);
        $summary['total_credit'] += floatval($result['credit']);
    }
    
    // Get journal entries summary
    $sql = "
        SELECT 
            COUNT(*) as count,
            SUM(debit_amount) as debit,
            SUM(credit_amount) as credit
        FROM journal_entries
        WHERE client_name = ?
        AND entry_date BETWEEN ? AND ?
        AND status != 'cancelled'
    ";
    $stmt = $db->prepare($sql);
    $stmt->execute([$client_name, $date_from, $date_to]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($result) {
        $summary['journal_count'] = (int)$result['count'];
        $summary['total_debit'] += floatval($result['debit']);
        $summary['total_credit'] += floatval($result['credit']);
    }
    
    // Get payments summary
    $sql = "
        SELECT 
            COUNT(*) as count,
            SUM(amount) as credit
        FROM payment_receipts
        WHERE client_name = ?
        AND receipt_date BETWEEN ? AND ?
        AND status != 'cancelled'
    ";
    $stmt = $db->prepare($sql);
    $stmt->execute([$client_name, $date_from, $date_to]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($result) {
        $summary['payment_count'] = (int)$result['count'];
        $summary['total_credit'] += floatval($result['credit']);
    }
    
    $summary['net_balance'] = $summary['total_debit'] - $summary['total_credit'];
    
    return $summary;
}

// ============================================
// GET DATA
// ============================================
$entities = [];
$transactions = [];
$summary = [];
$selected_entity = '';
$error_message = '';

try {
    $entities = getEntities($db, $entity_type, $search);
    
    if ($entity_id > 0 && !empty($entities)) {
        // Find the selected entity
        foreach ($entities as $e) {
            if ($e['code'] == $entity_id || $e['name'] == $entity_id) {
                $selected_entity = $e['name'];
                break;
            }
        }
        if (empty($selected_entity) && isset($entities[$entity_id - 1])) {
            $selected_entity = $entities[$entity_id - 1]['name'];
        }
    }
    
    if (!empty($selected_entity)) {
        $transactions = getClientLedger($db, $selected_entity, $date_from, $date_to);
        $summary = getLedgerSummary($db, $selected_entity, $date_from, $date_to);
    }
} catch (Exception $e) {
    $error_message = "Error loading data: " . $e->getMessage();
    error_log("Entity ledger error: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
}

$page_title = 'Entity Ledger - Client Transactions';
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
    
    .entity-item {
        cursor: pointer;
        padding: 8px 12px;
        border: 1px solid #ddd;
        border-radius: 4px;
        margin-bottom: 4px;
        transition: all 0.2s;
    }
    .entity-item:hover {
        background: #f8f9fa;
        border-color: #999;
    }
    .entity-item.active {
        background: #e9ecef;
        border-color: #6c757d;
        font-weight: 600;
    }
    .entity-item .code {
        font-size: 11px;
        color: #999;
    }
    
    .entity-list {
        max-height: 500px;
        overflow-y: auto;
        border: 1px solid #ddd;
        border-radius: 4px;
        padding: 5px;
    }
    
    @media (max-width: 768px) {
        .entity-list {
            max-height: 300px;
        }
    }
</style>

<div class="container-fluid">
    <!-- Page Header -->
    <div class="row mb-3">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h4 class="mb-0">
                        <i class="bi bi-journal"></i> Entity Ledger
                    </h4>
                    <small class="text-muted">Complete client transaction history</small>
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

    <!-- Filters -->
    <div class="filter-section">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-2">
                <label class="form-label">Entity Type</label>
                <select class="form-select form-select-sm" name="entity_type" onchange="this.form.submit()">
                    <option value="clients" <?php echo $entity_type === 'clients' ? 'selected' : ''; ?>>Clients</option>
                    <option value="suppliers" <?php echo $entity_type === 'suppliers' ? 'selected' : ''; ?>>Suppliers</option>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">Search Entity</label>
                <input type="text" class="form-control form-control-sm" name="search" placeholder="Search client name..." value="<?php echo htmlspecialchars($search); ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label">Date From</label>
                <input type="date" class="form-control form-control-sm" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label">Date To</label>
                <input type="date" class="form-control form-control-sm" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label">&nbsp;</label>
                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-secondary btn-sm w-100">Apply</button>
                    <a href="entity_ledger.php" class="btn btn-outline-secondary btn-sm w-100">Reset</a>
                </div>
            </div>
        </form>
    </div>

    <div class="row">
        <!-- Entity List -->
        <div class="col-md-3">
            <div class="card">
                <div class="card-header">
                    <h6 class="mb-0">Entities</h6>
                    <small class="text-muted"><?php echo count($entities); ?> found</small>
                </div>
                <div class="card-body p-0">
                    <div class="entity-list">
                        <?php if (empty($entities)): ?>
                            <div class="p-3 text-center text-muted">
                                <i class="bi bi-inbox"></i>
                                <p>No entities found</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($entities as $index => $entity): 
                                $isActive = ($entity['name'] === $selected_entity);
                            ?>
                                <a href="entity_ledger.php?entity_type=<?php echo urlencode($entity_type); ?>&entity_id=<?php echo urlencode($entity['name']); ?>&date_from=<?php echo urlencode($date_from); ?>&date_to=<?php echo urlencode($date_to); ?>&search=<?php echo urlencode($search); ?>" 
                                   class="entity-item <?php echo $isActive ? 'active' : ''; ?> d-block text-decoration-none text-dark">
                                    <div><?php echo htmlspecialchars($entity['name']); ?></div>
                                    <div class="code"><?php echo htmlspecialchars($entity['code'] ?? 'N/A'); ?></div>
                                </a>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Ledger Details -->
        <div class="col-md-9">
            <?php if (!empty($selected_entity)): ?>
                <!-- Summary Stats -->
                <div class="row mb-3 g-2">
                    <div class="col-md-3 col-6">
                        <div class="stat-box">
                            <div class="stat-number"><?php echo number_format($summary['total_debit'], 2); ?></div>
                            <div class="stat-label">Total Debit (DR)</div>
                        </div>
                    </div>
                    <div class="col-md-3 col-6">
                        <div class="stat-box">
                            <div class="stat-number"><?php echo number_format($summary['total_credit'], 2); ?></div>
                            <div class="stat-label">Total Credit (CR)</div>
                        </div>
                    </div>
                    <div class="col-md-3 col-6">
                        <div class="stat-box">
                            <div class="stat-number <?php echo $summary['net_balance'] >= 0 ? 'positive' : 'negative'; ?>">
                                <?php echo number_format($summary['net_balance'], 2); ?>
                            </div>
                            <div class="stat-label">Net Balance</div>
                        </div>
                    </div>
                    <div class="col-md-3 col-6">
                        <div class="stat-box">
                            <div class="stat-number">
                                <?php 
                                    $total_count = $summary['trade_count'] + $summary['journal_count'] + $summary['payment_count'];
                                    echo number_format($total_count);
                                ?>
                            </div>
                            <div class="stat-label">
                                Transactions (T:<?php echo $summary['trade_count']; ?> J:<?php echo $summary['journal_count']; ?> P:<?php echo $summary['payment_count']; ?>)
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Transactions Table -->
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h6 class="mb-0">
                            <i class="bi bi-list-ul"></i> Transactions for <?php echo htmlspecialchars($selected_entity); ?>
                            <span class="badge bg-secondary ms-2"><?php echo count($transactions); ?></span>
                        </h6>
                    </div>
                    <div class="card-body p-0">
                        <?php if (empty($transactions)): ?>
                            <div class="text-center py-5">
                                <i class="bi bi-inbox" style="font-size: 48px; color: #dee2e6;"></i>
                                <p class="text-muted mt-3">No transactions found for this entity in the selected date range.</p>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-sm table-hover mb-0 table-condensed">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Date</th>
                                            <th>Reference</th>
                                            <th>Source</th>
                                            <th>Description</th>
                                            <th>Security</th>
                                            <th class="text-end">Debit (DR)</th>
                                            <th class="text-end">Credit (CR)</th>
                                            <th class="text-end">Balance</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($transactions as $t): 
                                            $debit = floatval($t['debit_amount'] ?? $t['amount'] ?? 0);
                                            $credit = floatval($t['credit_amount'] ?? 0);
                                            $isDebit = ($t['debit_credit'] === 'Debit' || $t['debit_credit'] === 'DR');
                                            
                                            if ($isDebit) {
                                                $display_debit = $debit > 0 ? $debit : (floatval($t['amount'] ?? 0));
                                                $display_credit = 0;
                                            } else {
                                                $display_debit = 0;
                                                $display_credit = $credit > 0 ? $credit : (floatval($t['amount'] ?? 0));
                                            }
                                        ?>
                                            <tr>
                                                <td><?php echo date('d/m/Y', strtotime($t['transaction_date'])); ?></td>
                                                <td>
                                                    <span class="fw-semibold small"><?php echo htmlspecialchars($t['reference'] ?? 'N/A'); ?></span>
                                                    <?php if (!empty($t['reference_id'])): ?>
                                                        <br><small class="text-muted">ID: <?php echo $t['reference_id']; ?></small>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <span class="badge-source"><?php echo htmlspecialchars($t['source'] ?? 'N/A'); ?></span>
                                                </td>
                                                <td>
                                                    <?php echo htmlspecialchars($t['transaction_type'] ?? ''); ?>
                                                    <?php if (!empty($t['category'])): ?>
                                                        <br><small class="text-muted"><?php echo htmlspecialchars($t['category']); ?></small>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if (!empty($t['security_id'])): ?>
                                                        <strong><?php echo htmlspecialchars($t['security_id']); ?></strong>
                                                        <br><small class="text-muted"><?php echo htmlspecialchars($t['security_name'] ?? ''); ?></small>
                                                    <?php else: ?>
                                                        <span class="text-muted">-</span>
                                                    <?php endif; ?>
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
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php else: ?>
                <div class="card">
                    <div class="card-body text-center py-5">
                        <i class="bi bi-person" style="font-size: 48px; color: #dee2e6;"></i>
                        <h5 class="mt-3">Select an Entity</h5>
                        <p class="text-muted">Choose a client from the list to view their complete ledger.</p>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
// ============================================
// ENTITY LEDGER - INITIALIZATION
// ============================================

// Auto-refresh alerts after 5 seconds
document.querySelectorAll('.alert').forEach(alert => {
    setTimeout(() => {
        const bsAlert = bootstrap.Alert.getOrCreateInstance(alert);
        if (bsAlert) bsAlert.close();
    }, 5000);
});

console.log('Entity Ledger System initialized.');
</script>

<?php include '../includes/footer.php'; ?>
