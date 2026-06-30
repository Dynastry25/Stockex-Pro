<?php
require_once '../config/config.php';
require_once '../auth/auth_middleware.php';

header("X-Frame-Options: DENY");
header("X-Content-Type-Options: nosniff");
header("X-XSS-Protection: 1; mode=block");
header("Referrer-Policy: strict-origin-when-cross-origin");
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; font-src 'self' https://cdn.jsdelivr.net");

require_finance_officer();

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$db = getDBConnection();

$entity_type = isset($_GET['type']) ? $_GET['type'] : null;
$entity_id = isset($_GET['id']) ? $_GET['id'] : null;
$per_page = isset($_GET['per_page']) ? max(1, min(500, (int)$_GET['per_page'])) : 50;
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($page - 1) * $per_page;

if (!$entity_type || !$entity_id) {
    header("Location: debtors.php");
    exit;
}

function getLedgerCode($entity_type) {
    $mapping = [
        'client' => 'C', 'custodian' => 'D', 'employee' => 'E',
        'agent' => 'A', 'broker' => 'B', 'supplier' => 'S',
        'chart_account' => 'O', 'bank_account' => 'B'
    ];
    return $mapping[$entity_type] ?? 'O';
}

function getEntityInfo($db, $entity_type, $entity_id) {
    try {
        switch ($entity_type) {
            case 'client':
                $stmt = $db->prepare("SELECT id, client_name AS name, cds_account AS code, client_type, phone, email FROM clients WHERE id = ?");
                $stmt->execute([$entity_id]);
                return $stmt->fetch();
            case 'custodian':
                $stmt = $db->prepare("SELECT id, custodian_name AS name, custodian_code AS code, contact_person, phone, email FROM custodians WHERE id = ?");
                $stmt->execute([$entity_id]);
                return $stmt->fetch();
            case 'employee':
                $stmt = $db->prepare("SELECT id, full_name AS name, username AS code, email, phone, role FROM users WHERE id = ?");
                $stmt->execute([$entity_id]);
                return $stmt->fetch();
            case 'agent':
                $stmt = $db->prepare("SELECT id, name, agent_code AS code, contact_person, phone, email FROM agents WHERE id = ?");
                $stmt->execute([$entity_id]);
                return $stmt->fetch();
            case 'broker':
                $stmt = $db->prepare("SELECT id, broker_name AS name, broker_code AS code, contact_person, phone, email FROM brokers WHERE id = ?");
                $stmt->execute([$entity_id]);
                return $stmt->fetch();
            case 'supplier':
                $stmt = $db->prepare("SELECT id, name, supplier_code AS code, contact_person, phone, email FROM suppliers WHERE id = ?");
                $stmt->execute([$entity_id]);
                return $stmt->fetch();
            case 'chart_account':
                $stmt = $db->prepare("SELECT account_code AS id, account_name AS name, account_code AS code, account_type, level FROM chart_of_accounts WHERE account_code = ?");
                $stmt->execute([$entity_id]);
                return $stmt->fetch();
            case 'bank_account':
                $stmt = $db->prepare("SELECT id, account_name AS name, account_number AS code, bank_name, currency, current_balance FROM banks_accounts WHERE id = ?");
                $stmt->execute([$entity_id]);
                return $stmt->fetch();
        }
    } catch (Exception $e) {
        error_log("Error getting entity info: " . $e->getMessage());
    }
    return false;
}

function buildTransactionQueries($db, $entity_type, $entity_id) {
    $ledger_code = getLedgerCode($entity_type);
    $is_bank = ($entity_type === 'bank_account');
    $params = [];
    $union_parts = [];

    // For chart_account and bank_account types, skip payments/receipts — GL entries
    // already cover them (every payment/receipt with record_in_financial='yes' now creates
    // GL entries). Including both would produce duplicate rows and incorrect signs,
    // and cause cross-table collation errors in UNION.
    $use_payments_receipts = !in_array($entity_type, ['chart_account', 'bank_account'], true);

    if ($use_payments_receipts) {
        // Receipts: for bank_account amount is +inflow, for others amount is -receipt
        if ($is_bank) {
            $receipt_signed = 'r.amount';
        } else {
            $receipt_signed = '-r.amount';
        }
        $union_parts[] = "(SELECT r.receipt_date AS tx_date, 'receipt' AS source, COALESCE(r.narration, 'Money received') AS description, r.receipt_no AS reference, $receipt_signed AS signed_amount, r.amount AS raw_amount, r.currency, r.account_no AS bank_account FROM receipts r WHERE (r.account_of = ? OR (r.account_of = 'O' AND r.source_type = ?)) AND r.name_id = ? AND r.record_in_financial = 'yes')";
        $params = array_merge($params, [$ledger_code, $entity_type, $entity_id]);

        // Payments: for bank_account amount is -outflow, for others amount is +payment
        if ($is_bank) {
            $payment_signed = '-p.amount';
        } else {
            $payment_signed = 'p.amount';
        }
        $union_parts[] = "(SELECT p.payment_date AS tx_date, 'payment' AS source, COALESCE(p.narration, 'Money paid') AS description, p.payment_no AS reference, $payment_signed AS signed_amount, p.amount AS raw_amount, p.currency, p.account_no AS bank_account FROM payments p WHERE (p.paid_to = ? OR (p.paid_to = 'O' AND p.source_type = ?)) AND p.name_id = ? AND p.record_in_financial = 'yes')";
        $params = array_merge($params, [$ledger_code, $entity_type, $entity_id]);
    }

    // GL entries
    $gl_params = [];
    $gl_where = '';

    if ($entity_type === 'chart_account') {
        $gl_where = "gl.account_code = ?";
        $gl_params = [$entity_id];
    } elseif ($entity_type === 'bank_account') {
        $gl_where = "gl.account_code COLLATE utf8mb4_unicode_ci = (SELECT code FROM banks_accounts WHERE id = ? AND status = 'active')";
        $gl_params = [$entity_id];
    } elseif ($entity_type === 'client') {
        $stmt = $db->prepare("SELECT trade_reference FROM trades WHERE client_cds_account = (SELECT cds_account FROM clients WHERE id = ?) OR client_name = (SELECT client_name FROM clients WHERE id = ?)");
        $stmt->execute([$entity_id, $entity_id]);
        $trade_refs = $stmt->fetchAll(PDO::FETCH_COLUMN);
        if (!empty($trade_refs)) {
            $placeholders = implode(',', array_fill(0, count($trade_refs), '?'));
            $gl_where = "gl.reference_no IN ($placeholders)";
            $gl_params = $trade_refs;
        }
    }

    if (!empty($gl_where)) {
        if ($is_bank) {
            $gl_signed = "CASE WHEN gl.debit_amount > 0 THEN gl.debit_amount ELSE -gl.credit_amount END";
        } else {
            $gl_signed = "CASE WHEN gl.debit_amount > 0 THEN -gl.debit_amount ELSE gl.credit_amount END";
        }
        $union_parts[] = "(SELECT gl.transaction_date AS tx_date, 'gl_entry' AS source, gl.description, gl.reference_no AS reference, $gl_signed AS signed_amount, CASE WHEN gl.debit_amount > 0 THEN gl.debit_amount ELSE gl.credit_amount END AS raw_amount, 'TZS' AS currency, gl.account_code AS bank_account FROM general_ledger gl WHERE $gl_where)";
        $params = array_merge($params, $gl_params);
    }

    return [$union_parts, $params];
}

$entity_info = getEntityInfo($db, $entity_type, $entity_id);

if (!$entity_info) {
    header("Location: debtors.php");
    exit;
}

$entity_name = $entity_info['name'];
$entity_code = !empty($entity_info['code']) ? $entity_info['code'] : $entity_id;

list($union_parts, $query_params) = buildTransactionQueries($db, $entity_type, $entity_id);

if (empty($union_parts)) {
    $page_title = 'Entity Ledger - ' . $entity_name;
    include '../includes/header.php';
    ?>
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h3 mb-0 text-dark"><?php echo htmlspecialchars($entity_name); ?></h1>
            <p class="text-muted mb-0">No transaction data available for this entity</p>
        </div>
        <a href="debtors.php<?php echo $entity_type ? '?entity_type=' . urlencode($entity_type) : ''; ?>" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i>Back to Debtors
        </a>
    </div>
    <?php
    include '../includes/footer.php';
    exit;
}

$combined_sql = implode(" UNION ALL ", $union_parts);

// Total count
$count_sql = "SELECT COUNT(*) AS total FROM ($combined_sql) AS combined";
$count_stmt = $db->prepare($count_sql);
$count_stmt->execute($query_params);
$total_transactions = (int)$count_stmt->fetchColumn();
$total_pages = max(1, (int)ceil($total_transactions / $per_page));
if ($page > $total_pages) $page = $total_pages;

// Opening balance (sum of all transactions before this page)
$opening_balance = 0;
if ($offset > 0) {
    $ob_sql = "SELECT COALESCE(SUM(combined.signed_amount), 0) AS balance FROM ($combined_sql) AS combined ORDER BY combined.tx_date ASC LIMIT $offset";
    $ob_stmt = $db->prepare($ob_sql);
    $ob_stmt->execute($query_params);
    $opening_balance = (float)$ob_stmt->fetchColumn();
} else {
    $opening_balance = 0;
}

// Page transactions
$data_sql = "SELECT * FROM ($combined_sql) AS combined ORDER BY combined.tx_date ASC LIMIT $per_page OFFSET $offset";
$data_stmt = $db->prepare($data_sql);
$data_stmt->execute($query_params);
$transactions = $data_stmt->fetchAll();

// Calculate totals for summary
$summary_sql = "SELECT COUNT(*) AS tx_count, COALESCE(SUM(CASE WHEN combined.signed_amount > 0 THEN combined.signed_amount ELSE 0 END), 0) AS total_inflows, COALESCE(SUM(CASE WHEN combined.signed_amount < 0 THEN ABS(combined.signed_amount) ELSE 0 END), 0) AS total_outflows, COALESCE(SUM(combined.signed_amount), 0) AS net_balance FROM ($combined_sql) AS combined";
$summary_stmt = $db->prepare($summary_sql);
$summary_stmt->execute($query_params);
$summary = $summary_stmt->fetch();

$page_title = 'Ledger: ' . $entity_name;
include '../includes/header.php';
?>

<style>
    .ledger-header {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        border-radius: 10px;
        padding: 20px;
        margin-bottom: 20px;
    }
    .ledger-header h1 { color: white; }
    .ledger-header .text-muted { color: rgba(255,255,255,0.7) !important; }
    .summary-card { border-radius: 10px; border: none; box-shadow: 0 2px 8px rgba(0,0,0,0.08); }
    .tx-inflow { color: #28a745; font-weight: 600; }
    .tx-outflow { color: #dc3545; font-weight: 600; }
    .running-balance { font-weight: 700; }
    .entity-type-badge {
        font-size: 0.7rem;
        padding: 0.2rem 0.4rem;
        border-radius: 4px;
    }
    .badge-client { background-color: #007bff; color: white; }
    .badge-custodian { background-color: #17a2b8; color: white; }
    .badge-employee { background-color: #28a745; color: white; }
    .badge-agent { background-color: #ffc107; color: #212529; }
    .badge-broker { background-color: #fd7e14; color: white; }
    .badge-supplier { background-color: #e83e8c; color: white; }
    .badge-chart_account { background-color: #6f42c1; color: white; }
    .badge-bank_account { background-color: #20c997; color: white; }
    .page-link { color: #667eea; }
    .page-item.active .page-link { background-color: #667eea; border-color: #667eea; }
    .source-receipt { color: #28a745; }
    .source-payment { color: #dc3545; }
    .source-gl_entry { color: #6f42c1; }
</style>

<div class="container-fluid px-0">
    <!-- Header -->
    <div class="ledger-header">
        <div class="d-flex justify-content-between align-items-start">
            <div>
                <div class="mb-2">
                    <span class="entity-type-badge badge-<?php echo $entity_type; ?>">
                        <?php echo ucfirst($entity_type); ?>
                    </span>
                    <code class="ms-2 text-white bg-dark bg-opacity-25 px-2 py-1 rounded"><?php echo htmlspecialchars($entity_code); ?></code>
                </div>
                <h1 class="h3 mb-1"><?php echo htmlspecialchars($entity_name); ?></h1>
                <p class="mb-0 text-white-50">
                    <?php echo number_format($total_transactions); ?> transactions | 
                    Page <?php echo $page; ?> of <?php echo $total_pages; ?>
                </p>
            </div>
            <div>
                <a href="debtors.php<?php echo $entity_type ? '?entity_type=' . urlencode($entity_type) : ''; ?>" class="btn btn-light btn-sm">
                    <i class="bi bi-arrow-left me-1"></i>Back
                </a>
            </div>
        </div>
    </div>

    <!-- Summary Cards -->
    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="card summary-card">
                <div class="card-body">
                    <h6 class="text-muted mb-1">Total Inflows (Credit)</h6>
                    <h4 class="tx-inflow mb-0">TZS <?php echo number_format($summary['total_inflows'], 2); ?></h4>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card summary-card">
                <div class="card-body">
                    <h6 class="text-muted mb-1">Total Outflows (Debit)</h6>
                    <h4 class="tx-outflow mb-0">TZS <?php echo number_format($summary['total_outflows'], 2); ?></h4>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card summary-card">
                <div class="card-body">
                    <h6 class="text-muted mb-1">Net Balance</h6>
                    <?php $net_balance = (float)$summary['net_balance']; ?>
                    <h4 class="mb-0 <?php echo $net_balance >= 0 ? 'tx-inflow' : 'tx-outflow'; ?>">
                        TZS <?php echo number_format(abs($net_balance), 2); ?>
                    </h4>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card summary-card">
                <div class="card-body">
                    <h6 class="text-muted mb-1">Status</h6>
                    <?php if ($net_balance > 0): ?>
                        <h4 class="tx-inflow mb-0">In Credit</h4>
                        <small class="text-muted">Entity is owed</small>
                    <?php elseif ($net_balance < 0): ?>
                        <h4 class="tx-outflow mb-0">In Debit</h4>
                        <small class="text-muted">Entity owes us</small>
                    <?php else: ?>
                        <h4 class="text-muted mb-0">Settled</h4>
                        <small class="text-muted">Zero balance</small>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Per Page Selector -->
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <small class="text-muted">
                Showing 
                <?php echo count($transactions); ?> of 
                <?php echo $total_transactions; ?> transactions
                (opening balance: TZS <?php echo number_format($opening_balance, 2); ?>)
            </small>
        </div>
        <div class="d-flex align-items-center">
            <label class="form-label me-2 mb-0"><small>Per page:</small></label>
            <select class="form-select form-select-sm" style="width: auto;" onchange="window.location.href='?type=<?php echo urlencode($entity_type); ?>&id=<?php echo urlencode($entity_id); ?>&per_page='+this.value">
                <option value="25" <?php echo $per_page == 25 ? 'selected' : ''; ?>>25</option>
                <option value="50" <?php echo $per_page == 50 ? 'selected' : ''; ?>>50</option>
                <option value="100" <?php echo $per_page == 100 ? 'selected' : ''; ?>>100</option>
                <option value="200" <?php echo $per_page == 200 ? 'selected' : ''; ?>>200</option>
                <option value="500" <?php echo $per_page == 500 ? 'selected' : ''; ?>>500</option>
            </select>
        </div>
    </div>

    <!-- Transactions Table -->
    <div class="card">
        <div class="card-body p-0">
            <?php if (empty($transactions)): ?>
                <div class="text-center py-5">
                    <i class="bi bi-inbox" style="font-size: 3rem; color: #6c757d;"></i>
                    <h5 class="mt-3 text-muted">No transactions found</h5>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover table-sm mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>#</th>
                                <th>Date</th>
                                <th>Source</th>
                                <th>Reference</th>
                                <th>Description</th>
                                <th>Account</th>
                                <th class="text-end">Debit (TZS)</th>
                                <th class="text-end">Credit (TZS)</th>
                                <th class="text-end">Balance (TZS)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $running = $opening_balance;
                            $row_num = $offset + 1;
                            foreach ($transactions as $tx):
                                $amount = (float)$tx['signed_amount'];
                                $running += $amount;
                                $is_debit = $amount < 0;
                                $debit = $is_debit ? abs($amount) : 0;
                                $credit = $is_debit ? 0 : $amount;
                            ?>
                                <tr>
                                    <td class="text-muted"><?php echo $row_num++; ?></td>
                                    <td><?php echo date('d M Y', strtotime($tx['tx_date'])); ?></td>
                                    <td>
                                        <?php if ($tx['source'] === 'receipt'): ?>
                                            <span class="badge bg-success bg-opacity-10 text-success">Receipt</span>
                                        <?php elseif ($tx['source'] === 'payment'): ?>
                                            <span class="badge bg-danger bg-opacity-10 text-danger">Payment</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary bg-opacity-10 text-secondary">GL Entry</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><code><?php echo htmlspecialchars($tx['reference']); ?></code></td>
                                    <td><?php echo htmlspecialchars($tx['description']); ?></td>
                                    <td><small class="text-muted"><?php echo htmlspecialchars($tx['bank_account'] ?? '-'); ?></small></td>
                                    <td class="text-end text-danger fw-bold"><?php echo $debit > 0 ? number_format($debit, 2) : '-'; ?></td>
                                    <td class="text-end text-success fw-bold"><?php echo $credit > 0 ? number_format($credit, 2) : '-'; ?></td>
                                    <td class="text-end running-balance <?php echo $running >= 0 ? 'tx-inflow' : 'tx-outflow'; ?>">
                                        <?php echo number_format($running, 2); ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot class="table-light">
                            <tr>
                                <th colspan="7" class="text-end">Page Closing Balance:</th>
                                <th class="text-end running-balance <?php echo $running >= 0 ? 'tx-inflow' : 'tx-outflow'; ?>" colspan="2">
                                    TZS <?php echo number_format($running, 2); ?>
                                </th>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            <?php endif; ?>
        </div>
        <?php if ($total_pages > 1): ?>
            <div class="card-footer">
                <nav>
                    <ul class="pagination pagination-sm justify-content-center mb-0">
                        <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                            <a class="page-link" href="?type=<?php echo urlencode($entity_type); ?>&id=<?php echo urlencode($entity_id); ?>&per_page=<?php echo $per_page; ?>&page=1">&laquo;&laquo;</a>
                        </li>
                        <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                            <a class="page-link" href="?type=<?php echo urlencode($entity_type); ?>&id=<?php echo urlencode($entity_id); ?>&per_page=<?php echo $per_page; ?>&page=<?php echo max(1, $page - 1); ?>">&laquo;</a>
                        </li>
                        <?php
                        $start_p = max(1, $page - 2);
                        $end_p = min($total_pages, $page + 2);
                        if ($start_p > 1): ?>
                            <li class="page-item disabled"><span class="page-link">...</span></li>
                        <?php endif;
                        for ($i = $start_p; $i <= $end_p; $i++): ?>
                            <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                                <a class="page-link" href="?type=<?php echo urlencode($entity_type); ?>&id=<?php echo urlencode($entity_id); ?>&per_page=<?php echo $per_page; ?>&page=<?php echo $i; ?>"><?php echo $i; ?></a>
                            </li>
                        <?php endfor;
                        if ($end_p < $total_pages): ?>
                            <li class="page-item disabled"><span class="page-link">...</span></li>
                        <?php endif; ?>
                        <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                            <a class="page-link" href="?type=<?php echo urlencode($entity_type); ?>&id=<?php echo urlencode($entity_id); ?>&per_page=<?php echo $per_page; ?>&page=<?php echo min($total_pages, $page + 1); ?>">&raquo;</a>
                        </li>
                        <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                            <a class="page-link" href="?type=<?php echo urlencode($entity_type); ?>&id=<?php echo urlencode($entity_id); ?>&per_page=<?php echo $per_page; ?>&page=<?php echo $total_pages; ?>">&raquo;&raquo;</a>
                        </li>
                    </ul>
                </nav>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php include '../includes/footer.php'; ?>
