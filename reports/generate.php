<?php
require_once '../config/config.php';
require_once '../auth/auth_middleware.php';

require_login();

$db = getDBConnection();
$success_message = '';
$error_message = '';

// Handle document generation
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['generate_documents'])) {
    $trade_ids = $_POST['trade_ids'] ?? [];
    
    if (empty($trade_ids)) {
        $error_message = 'Please select at least one trade to generate documents.';
    } else {
        $generated_receipts = 0;
        $generated_invoices = 0;
        
        try {
            $db->beginTransaction();
            
            foreach ($trade_ids as $trade_id) {
                $stmt = $db->prepare("
                    SELECT t.*
                    FROM trades t
                    WHERE t.id = ? AND t.status = 'active'
                ");
                $stmt->execute([$trade_id]);
                $trade = $stmt->fetch();
                
                if (!$trade) {
                    continue; // Skip cancelled or invalid trades
                }
                
                // Check if documents already exist
                $stmt = $db->prepare("SELECT id FROM trade_receipts WHERE trade_id = ?");
                $stmt->execute([$trade_id]);
                $receipt_exists = $stmt->fetch();
                
                $stmt = $db->prepare("SELECT id FROM trade_invoices WHERE trade_id = ?");
                $stmt->execute([$trade_id]);
                $invoice_exists = $stmt->fetch();
                
                // Generate receipt for buyer
                if (!$receipt_exists) {
                    $receipt_number = generate_reference_number('RCP');
                    $fees = $trade['consideration'] * 0.001; // 0.1% fee
                    $net_amount = $trade['consideration'] + $fees;
                    
                    $stmt = $db->prepare("
                        INSERT INTO trade_receipts (receipt_number, trade_id, client_cds_account, client_name, 
                                                  security_name, quantity, unit_price, gross_amount, fees, taxes, 
                                                  net_amount, currency, receipt_date, generated_by) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?, ?)
                    ");
                    
                    if ($stmt->execute([$receipt_number, $trade_id, $trade['client_cds_account'], $trade['client_name'],
                                      $trade['security_name'], $trade['quantity'], $trade['price'], 
                                      $trade['consideration'], $fees, $net_amount, $trade['currency'], date('Y-m-d'), $_SESSION['user_id']])) {
                        $generated_receipts++;
                    }
                }
                
                // Generate invoice for seller
                if (!$invoice_exists) {
                    $invoice_number = generate_reference_number('INV');
                    $fees = $trade['consideration'] * 0.001; // 0.1% fee
                    $taxes = $trade['consideration'] * 0.005; // 0.5% tax
                    $net_amount = $trade['consideration'] - $fees - $taxes;
                    
                    $stmt = $db->prepare("
                        INSERT INTO trade_invoices (invoice_number, trade_id, client_cds_account, client_name, 
                                                   security_name, quantity, unit_price, gross_amount, 
                                                   fees, taxes, net_amount, currency, invoice_date, generated_by) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    
                    if ($stmt->execute([$invoice_number, $trade_id, $trade['counterparty_cds_account'], $trade['counterparty_name'],
                                      $trade['security_name'], $trade['quantity'], $trade['price'], 
                                      $trade['consideration'], $fees, $taxes, $net_amount, $trade['currency'], date('Y-m-d'), $_SESSION['user_id']])) {
                        $generated_invoices++;
                    }
                }
            }
            
            $db->commit();
            show_alert("Generated $generated_receipts receipts and $generated_invoices invoices successfully.", 'success');
            redirect('reports/');
            
        } catch (Exception $e) {
            $db->rollBack();
            $error_message = 'Error generating documents: ' . $e->getMessage();
        }
    }
}

$stmt = $db->query("
    SELECT t.*, 
           t.security_id as instrument_code,
           CONCAT(UPPER(t.asset_class), ': ', t.security_name) as instrument_name,
           CASE WHEN tr.id IS NOT NULL THEN 1 ELSE 0 END as has_receipt,
           CASE WHEN ti.id IS NOT NULL THEN 1 ELSE 0 END as has_invoice
    FROM trades t
    LEFT JOIN trade_receipts tr ON t.id = tr.trade_id
    LEFT JOIN trade_invoices ti ON t.id = ti.trade_id
    WHERE t.status = 'active'
    ORDER BY t.trade_date DESC
");
$trades = $stmt->fetchAll();

$page_title = 'Generate Documents';
include '../includes/header.php';
?>

<div class="row">
    <div class="col-12">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2><i class="bi bi-plus-lg"></i> Generate Documents</h2>
            <a href="./" class="btn btn-secondary">
                <i class="bi bi-arrow-left"></i> Back to Reports
            </a>
        </div>
    </div>
</div>

<?php if (!empty($error_message)): ?>
    <div class="alert alert-danger">
        <i class="bi bi-exclamation-triangle"></i> <?php echo $error_message; ?>
    </div>
<?php endif; ?>

<div class="alert alert-info">
    <h6><i class="bi bi-info-circle"></i> Document Generation</h6>
    <p class="mb-0">
        Select active trades to generate receipts for buyers and invoices for sellers. 
        <strong>Note:</strong> Cancelled trades are automatically excluded and will not appear in any documents.
    </p>
</div>

<div class="card">
    <div class="card-header">
        <h6 class="mb-0">Active Trades Available for Document Generation</h6>
    </div>
    <div class="card-body">
        <?php if (empty($trades)): ?>
            <p class="text-muted mb-0">No active trades available for document generation.</p>
        <?php else: ?>
            <form method="POST" action="">
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>
                                    <input type="checkbox" id="selectAll" onchange="toggleAll(this)">
                                </th>
                                <th>Trade Reference</th>
                                <th>Instrument</th>
                                <th>Side</th>
                                <th>Quantity</th>
                                <th>Price</th>
                                <th>Total Value</th>
                                <th>Buyer</th>
                                <th>Seller</th>
                                <th>Trade Date</th>
                                <th>Documents</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($trades as $trade): ?>
                                <tr>
                                    <td>
                                        <?php if (!$trade['has_receipt'] || !$trade['has_invoice']): ?>
                                            <input type="checkbox" name="trade_ids[]" value="<?php echo $trade['id']; ?>">
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($trade['trade_reference']); ?></td>
                                    <td>
                                        <div>
                                            <strong><?php echo htmlspecialchars($trade['security_id']); ?></strong><br>
                                            <!-- Updated to use asset_class -->
                                            <small class="text-muted"><?php echo ucfirst($trade['asset_class']); ?></small>
                                        </div>
                                    </td>
                                    <td>
                                        <!-- Updated to use buy_sell -->
                                        <span class="badge bg-<?php echo strtolower($trade['buy_sell']) == 'buy' ? 'success' : 'danger'; ?>">
                                            <?php echo ucfirst($trade['buy_sell']); ?>
                                        </span>
                                    </td>
                                    <td><?php echo number_format($trade['quantity']); ?></td>
                                    <td>$<?php echo format_currency($trade['price']); ?></td>
                                    <!-- Updated to use consideration -->
                                    <td>$<?php echo format_currency($trade['consideration']); ?></td>
                                    <!-- Updated to use client_name -->
                                    <td><?php echo htmlspecialchars($trade['client_name']); ?></td>
                                    <!-- Updated to use counterparty_name -->
                                    <td><?php echo htmlspecialchars($trade['counterparty_name']); ?></td>
                                    <td><?php echo format_date($trade['trade_date']); ?></td>
                                    <td>
                                        <div class="d-flex gap-1">
                                            <?php if ($trade['has_receipt']): ?>
                                                <span class="badge bg-primary">Receipt</span>
                                            <?php endif; ?>
                                            <?php if ($trade['has_invoice']): ?>
                                                <span class="badge bg-success">Invoice</span>
                                            <?php endif; ?>
                                            <?php if (!$trade['has_receipt'] && !$trade['has_invoice']): ?>
                                                <span class="badge bg-warning">Pending</span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <div class="d-flex justify-content-between align-items-center mt-3">
                    <div>
                        <small class="text-muted">
                            Select trades to generate receipts for buyers and invoices for sellers
                        </small>
                    </div>
                    <button type="submit" name="generate_documents" class="btn btn-primary">
                        <i class="bi bi-file-earmark-plus"></i> Generate Selected Documents
                    </button>
                </div>
            </form>
        <?php endif; ?>
    </div>
</div>

<script>
function toggleAll(source) {
    const checkboxes = document.querySelectorAll('input[name="trade_ids[]"]');
    checkboxes.forEach(checkbox => {
        checkbox.checked = source.checked;
    });
}
</script>

<?php include '../includes/footer.php'; ?>
