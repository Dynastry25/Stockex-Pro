<?php
require_once '../config/config.php';
require_once '../auth/auth_middleware.php';

require_login();

$db = getDBConnection();

// Get un-receipted buy trades from the 'trades' table
// We use a LEFT JOIN to find trades that do not have a matching receipt
$where_conditions = ["t.trade_side = 'buy'"];
$params = [];

if (isset($_GET['search']) && !empty($_GET['search'])) {
    $search = '%' . $_GET['search'] . '%';
    $where_conditions[] = "(t.trade_reference LIKE ? OR t.client_name LIKE ? OR t.security_name LIKE ?)";
    $params = array_merge($params, [$search, $search, $search]);
}

if (isset($_GET['date_from']) && !empty($_GET['date_from'])) {
    $where_conditions[] = "t.trade_date >= ?";
    $params[] = $_GET['date_from'];
}

if (isset($_GET['date_to']) && !empty($_GET['date_to'])) {
    $where_conditions[] = "t.trade_date <= ?";
    $params[] = $_GET['date_to'];
}

$where_clause = implode(' AND ', $where_conditions);

// Add condition to check if a receipt already exists for this trade_id
$sql = "
    SELECT t.*
    FROM trades t
    LEFT JOIN trade_receipts tr ON t.id = tr.trade_id
    WHERE tr.id IS NULL AND $where_clause
    ORDER BY t.trade_date DESC
";

try {
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $trades = $stmt->fetchAll();
} catch (PDOException $e) {
    die("Database query failed: " . $e->getMessage());
}

$page_title = 'Generate Trade Receipts';
include '../includes/header.php';
?>

<div class="row">
    <div class="col-12">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2><i class="bi bi-file-earmark-plus"></i> Trades Pending Receipt</h2>
            <a href="./" class="btn btn-secondary">
                <i class="bi bi-arrow-left"></i> Back to Reports
            </a>
        </div>
    </div>
</div>

<!-- Filters -->
<div class="card mb-4">
    <div class="card-body">
        <form method="GET" action="" class="row g-3">
            <div class="col-md-4">
                <label for="search" class="form-label">Search</label>
                <input type="text" class="form-control" id="search" name="search"
                       value="<?php echo htmlspecialchars($_GET['search'] ?? ''); ?>"
                       placeholder="Trade #, client name, security...">
            </div>
            <div class="col-md-3">
                <label for="date_from" class="form-label">Date From</label>
                <input type="date" class="form-control" id="date_from" name="date_from"
                       value="<?php echo htmlspecialchars($_GET['date_from'] ?? ''); ?>">
            </div>
            <div class="col-md-3">
                <label for="date_to" class="form-label">Date To</label>
                <input type="date" class="form-control" id="date_to" name="date_to"
                       value="<?php echo htmlspecialchars($_GET['date_to'] ?? ''); ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label">&nbsp;</label>
                <div class="d-grid">
                    <button type="submit" class="btn btn-outline-primary">Filter</button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Trades Table -->
<div class="card">
    <div class="card-header">
        <h6 class="mb-0">Pending Receipts (<?php echo count($trades); ?>)</h6>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover">
                <thead>
                    <tr>
                        <th>Trade #</th>
                        <th>Client</th>
                        <th>Security</th>
                        <th>Quantity</th>
                        <th>Price</th>
                        <th>Value</th>
                        <th>Trade Date</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($trades)): ?>
                        <tr>
                            <td colspan="8" class="text-center">No un-receipted 'BUY' trades found.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($trades as $trade): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($trade['trade_reference']); ?></strong></td>
                                <td>
                                    <?php echo htmlspecialchars($trade['client_name']); ?><br>
                                    <small class="text-muted"><?php echo htmlspecialchars($trade['client_cds_account']); ?></small>
                                </td>
                                <td><?php echo htmlspecialchars($trade['security_name']); ?></td>
                                <td><?php echo number_format($trade['quantity']); ?></td>
                                <td>TZS <?php echo format_currency($trade['price']); ?></td>
                                <td>TZS <?php echo format_currency($trade['total_value']); ?></td>
                                <td><?php echo format_date($trade['trade_date']); ?></td>
                                <td>
                                    <a href="create_and_view_receipt?trade_id=<?php echo $trade['id']; ?>"
                                       class="btn btn-sm btn-outline-success">
                                        <i class="bi bi-file-earmark-plus"></i> Generate Receipt
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php include '../includes/footer.php'; ?>
