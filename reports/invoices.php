<?php
require_once '../config/config.php';
require_once '../auth/auth_middleware.php';

require_login();

$db = getDBConnection();

// Get invoices with filtering
$where_conditions = ["1=1"];
$params = [];

if (isset($_GET['search']) && !empty($_GET['search'])) {
    $search = '%' . $_GET['search'] . '%';
    $where_conditions[] = "(ti.invoice_number LIKE ? OR ti.client_name LIKE ? OR ti.security_name LIKE ?)";
    $params = array_merge($params, [$search, $search, $search]);
}

if (isset($_GET['date_from']) && !empty($_GET['date_from'])) {
    $where_conditions[] = "ti.invoice_date >= ?";
    $params[] = $_GET['date_from'];
}

if (isset($_GET['date_to']) && !empty($_GET['date_to'])) {
    $where_conditions[] = "ti.invoice_date <= ?";
    $params[] = $_GET['date_to'];
}

$where_clause = implode(' AND ', $where_conditions);

$stmt = $db->prepare("
    SELECT ti.*, t.trade_reference, t.trade_date, u.full_name as generated_by_name
    FROM trade_invoices ti
    JOIN trades t ON ti.trade_id = t.id
    JOIN users u ON ti.generated_by = u.id
    WHERE $where_clause
    ORDER BY ti.created_at DESC
");
$stmt->execute($params);
$invoices = $stmt->fetchAll();

$page_title = 'Trade Invoices';
include '../includes/header.php';
?>

<div class="row">
    <div class="col-12">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2><i class="bi bi-file-earmark-text"></i> Trade Invoices</h2>
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
                       placeholder="Invoice #, seller name, instrument...">
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

<!-- Invoices Table -->
<div class="card">
    <div class="card-header">
        <h6 class="mb-0">All Invoices (<?php echo count($invoices); ?>)</h6>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover">
                <thead>
                    <tr>
                        <th>Invoice #</th>
                        <th>Trade Reference</th>
                        <th>Seller</th>
                        <th>Instrument</th>
                        <th>Quantity</th>
                        <th>Unit Price</th>
                        <th>Gross Amount</th>
                        <th>Fees</th>
                        <th>Taxes</th>
                        <th>Net Amount</th>
                        <th>Invoice Date</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($invoices as $invoice): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($invoice['invoice_number']); ?></strong></td>
                            <td><?php echo htmlspecialchars($invoice['trade_reference']); ?></td>
                            <td>
                                <div>
                                    <!-- Updated to use client_name and client_cds_account -->
                                    <?php echo htmlspecialchars($invoice['client_name']); ?><br>
                                    <small class="text-muted"><?php echo htmlspecialchars($invoice['client_cds_account']); ?></small>
                                </div>
                            </td>
                            <!-- Updated to use security_name -->
                            <td><?php echo htmlspecialchars($invoice['security_name']); ?></td>
                            <td><?php echo number_format($invoice['quantity']); ?></td>
                            <td>$<?php echo format_currency($invoice['unit_price']); ?></td>
                            <td>$<?php echo format_currency($invoice['gross_amount']); ?></td>
                            <td>$<?php echo format_currency($invoice['fees']); ?></td>
                            <td>$<?php echo format_currency($invoice['taxes']); ?></td>
                            <td><strong>$<?php echo format_currency($invoice['net_amount']); ?></strong></td>
                            <td><?php echo format_date($invoice['invoice_date']); ?></td>
                            <td>
                                <a href="print_invoice?id=<?php echo $invoice['id']; ?>" 
                                   class="btn btn-sm btn-outline-primary" target="_blank">
                                    <i class="bi bi-printer"></i> Print
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php include '../includes/footer.php'; ?>
