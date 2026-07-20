<?php
require_once '../config/config.php';
require_once '../auth/auth_middleware.php';
require_finance_officer();

$db = getDBConnection();
$page_title = 'HR Payables';

// Get GL balances for HR payable accounts
$hr_accounts = ['2121','2122','2123','2124','2125','2126','216'];
$placeholders = implode(',', array_fill(0, count($hr_accounts), '?'));

$stmt = $db->prepare("
    SELECT 
        coa.account_code,
        coa.account_name,
        COALESCE((
            SELECT SUM(
                CASE WHEN gl.account_code = coa.account_code 
                THEN gl.credit_amount - gl.debit_amount 
                ELSE 0 END
            ) FROM general_ledger gl 
            WHERE gl.account_code = coa.account_code
        ), 0) as balance
    FROM chart_of_accounts coa
    WHERE coa.account_code IN ($placeholders)
      AND coa.is_active = 1
    ORDER BY coa.account_code
");
$stmt->execute($hr_accounts);
$payables = $stmt->fetchAll();

$total_balance = array_sum(array_column($payables, 'balance'));

// Get recent payroll GL entries
$recent_stmt = $db->prepare("
    SELECT transaction_date, reference_no, account_code, 
           debit_amount, credit_amount, description, created_at as posted_date
    FROM general_ledger 
    WHERE reference_type = 'payroll'
    ORDER BY created_at DESC
    LIMIT 50
");
$recent_stmt->execute();
$recent_entries = $recent_stmt->fetchAll();

ob_start();
include '../includes/header.php';
?>

<div class="container-fluid pt-4 px-4">
    <div class="d-sm-flex align-items-center justify-content-between mb-4">
        <h1 class="h3 mb-0 text-gray-800">
            <i class="bi bi-people me-2"></i>HR Payables
        </h1>
        <div>
            <a href="payment.php" class="btn btn-primary">
                <i class="bi bi-cash me-2"></i>Process Payments
            </a>
        </div>
    </div>

    <!-- Summary Cards -->
    <div class="row mb-4">
        <div class="col-md-3">
            <div class="card border-start-warning shadow h-100">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="me-3"><i class="bi bi-cash-stack fs-1 text-warning"></i></div>
                        <div>
                            <div class="text-muted">Total Payroll Payable</div>
                            <div class="fs-4 fw-bold"><?php echo number_format($total_balance, 2); ?></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-start-info shadow h-100">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="me-3"><i class="bi bi-person fs-1 text-info"></i></div>
                        <div>
                            <div class="text-muted">Net Salary Payable</div>
                            <div class="fs-4 fw-bold">
                                <?php
                                $net_sal = 0;
                                foreach ($payables as $p) {
                                    if ($p['account_code'] === '216') $net_sal = $p['balance'];
                                }
                                echo number_format($net_sal, 2);
                                ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-start-danger shadow h-100">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="me-3"><i class="bi bi-building fs-1 text-danger"></i></div>
                        <div>
                            <div class="text-muted">Statutory Payables</div>
                            <div class="fs-4 fw-bold">
                                <?php
                                $stat_total = 0;
                                foreach ($payables as $p) {
                                    if ($p['account_code'] !== '216') $stat_total += $p['balance'];
                                }
                                echo number_format($stat_total, 2);
                                ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-start-success shadow h-100">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="me-3"><i class="bi bi-clock-history fs-1 text-success"></i></div>
                        <div>
                            <div class="text-muted">Payments Pending</div>
                            <div class="fs-4 fw-bold">
                                <?php
                                $stmt = $db->query("SELECT COUNT(*) as cnt FROM pending_pay WHERE payee_id = 'SALARY' AND status NOT IN ('paid','rejected')");
                                echo $stmt->fetch()['cnt'] ?? 0;
                                ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Payable Accounts Table -->
    <div class="card shadow mb-4">
        <div class="card-header bg-primary">
            <h5 class="mb-0"><i class="bi bi-list-columns me-2"></i>HR Payable Account Balances</h5>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-bordered table-hover">
                    <thead class="table-light">
                        <tr>
                            <th>Account Code</th>
                            <th>Account Name</th>
                            <th>Type</th>
                            <th class="text-end">Balance (TZS)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($payables as $p): ?>
                            <tr>
                                <td><strong><?php echo $p['account_code']; ?></strong></td>
                                <td><?php echo htmlspecialchars($p['account_name']); ?></td>
                                <td>
                                    <?php if ($p['account_code'] === '216'): ?>
                                        <span class="badge bg-info">Net Salary</span>
                                    <?php else: ?>
                                        <span class="badge bg-warning">Statutory</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end fw-bold <?php echo $p['balance'] > 0 ? 'text-danger' : 'text-muted'; ?>">
                                    <?php echo number_format($p['balance'], 2); ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot class="table-secondary fw-bold">
                        <tr>
                            <td colspan="3" class="text-end">Total Payables</td>
                            <td class="text-end text-danger"><?php echo number_format($total_balance, 2); ?></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>

    <!-- Recent GL Entries -->
    <div class="card shadow">
        <div class="card-header bg-info">
            <h5 class="mb-0"><i class="bi bi-journal-text me-2"></i>Recent Payroll GL Entries</h5>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-sm table-bordered">
                    <thead class="table-light">
                        <tr>
                            <th>Date</th>
                            <th>Reference</th>
                            <th>Account</th>
                            <th>Description</th>
                            <th class="text-end">Debit</th>
                            <th class="text-end">Credit</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($recent_entries)): ?>
                            <?php foreach ($recent_entries as $e): ?>
                                <tr>
                                    <td><?php echo date('d/m/Y', strtotime($e['transaction_date'])); ?></td>
                                    <td><small><?php echo htmlspecialchars($e['reference_no']); ?></small></td>
                                    <td><strong><?php echo $e['account_code']; ?></strong></td>
                                    <td><small><?php echo htmlspecialchars(substr($e['description'], 0, 60)); ?></small></td>
                                    <td class="text-end text-danger"><?php echo $e['debit_amount'] > 0 ? number_format($e['debit_amount'], 2) : '-'; ?></td>
                                    <td class="text-end text-success"><?php echo $e['credit_amount'] > 0 ? number_format($e['credit_amount'], 2) : '-'; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="6" class="text-center text-muted">No payroll entries found</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php
include '../includes/footer.php';
$db = null;
?>
