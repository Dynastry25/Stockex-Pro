<?php
require_once '../config/config.php';
require_once '../auth/auth_middleware.php';

require_trader();
require_mandate();

$db = getDBConnection();
$success_message = '';
$error_message = '';

// Handle share entry
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['enter_share'])) {
    $security_id = strtoupper(sanitize_input($_POST['security_id']));
    $quantity = (int)$_POST['quantity'];
    $price = (float)$_POST['price'];
    $buyer_name = sanitize_input($_POST['buyer_name']);
    $seller_name = sanitize_input($_POST['seller_name']);
    $buyer_account = sanitize_input($_POST['buyer_account']);
    $seller_account = sanitize_input($_POST['seller_account']);
    $trade_date = sanitize_input($_POST['trade_date']);
    $settlement_date = sanitize_input($_POST['settlement_date']);
    
    if (empty($security_id) || !preg_match('/^[A-Z]{2,6}$/', $security_id)) {
        $error_message = 'Invalid security ID format. Stock symbol must be 2-6 uppercase letters (e.g., WVSL, VODA).';
    } else {
        $stmt = $db->prepare("SELECT * FROM equities WHERE security_id = ? AND status = 'active'");
        $stmt->execute([$security_id]);
        $equity = $stmt->fetch();
        
        if (!$equity) {
            $error_message = 'Security ID "' . $security_id . '" not found or inactive. Please ensure the equity is properly listed.';
        } else {
            $total_value = $quantity * $price;
            $trade_reference = generate_reference_number('TRD');
            
            $stmt = $db->prepare("
                INSERT INTO trades (trade_reference, asset_class, security_id, security_name, trade_side, quantity, price, 
                                  consideration, client_name, counterparty_name, client_cds_account, counterparty_cds_account, 
                                  trade_date, settlement_date, uploaded_by) 
                VALUES (?, 'equity', ?, ?, 'buy', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            if ($stmt->execute([$trade_reference, $security_id, $equity['stock_name'], $quantity, $price, $total_value, 
                              $buyer_name, $seller_name, $buyer_account, $seller_account,
                              $trade_date, $settlement_date, $_SESSION['user_id']])) {
                show_alert('Share trade entered successfully with reference: ' . $trade_reference, 'success');
                redirect('trader/enter_shares.php');
            } else {
                $error_message = 'Error entering share trade. Please try again.';
            }
        }
    }
}

// Get available equities for reference
$stmt = $db->query("
    SELECT * 
    FROM equities 
    WHERE status = 'active' 
    ORDER BY security_id
");
$available_equities = $stmt->fetchAll();

$page_title = 'Enter Share Trades';
include '../includes/header.php';
?>

<div class="row">
    <div class="col-12">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2><i class="bi bi-graph-up-arrow"></i> Enter Share Trades</h2>
            <a href="trades" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left"></i> Back to Trades
            </a>
        </div>
    </div>
</div>

<?php if (!empty($error_message)): ?>
    <div class="alert alert-danger">
        <i class="bi bi-exclamation-triangle"></i> <?php echo $error_message; ?>
    </div>
<?php endif; ?>

<!-- Share Entry Form -->
<div class="card">
    <div class="card-header">
        <h6 class="mb-0">Enter New Share Trade</h6>
    </div>
    <div class="card-body">
        <div class="alert alert-info">
            <i class="bi bi-info-circle"></i> 
            <strong>Security ID Format:</strong> Must be a valid stock symbol (2-6 uppercase letters, e.g., WVSL, VODA).
            Only listed equities will be accepted.
        </div>
        
        <form method="POST" action="">
            <div class="row">
                <div class="col-md-6">
                    <div class="mb-3">
                        <label for="security_id" class="form-label">Security ID (Stock Symbol) *</label>
                        <input type="text" class="form-control" id="security_id" name="security_id" 
                               placeholder="WVSL" required pattern="^[A-Z]{2,6}$" maxlength="6"
                               title="Stock symbol must be 2-6 uppercase letters" style="text-transform: uppercase;">
                        <div class="form-text">Example: WVSL, VODA, NMB (2-6 uppercase letters)</div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="mb-3">
                        <label for="quantity" class="form-label">Quantity *</label>
                        <input type="number" class="form-control" id="quantity" name="quantity" required min="1">
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="mb-3">
                        <label for="price" class="form-label">Price *</label>
                        <input type="number" class="form-control" id="price" name="price" required min="0.01" step="0.01">
                    </div>
                </div>
            </div>
            
            <div class="row">
                <div class="col-md-6">
                    <div class="mb-3">
                        <label for="buyer_name" class="form-label">Buyer Name *</label>
                        <input type="text" class="form-control" id="buyer_name" name="buyer_name" required>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="mb-3">
                        <label for="buyer_account" class="form-label">Buyer Account</label>
                        <input type="text" class="form-control" id="buyer_account" name="buyer_account">
                    </div>
                </div>
            </div>
            
            <div class="row">
                <div class="col-md-6">
                    <div class="mb-3">
                        <label for="seller_name" class="form-label">Seller Name *</label>
                        <input type="text" class="form-control" id="seller_name" name="seller_name" required>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="mb-3">
                        <label for="seller_account" class="form-label">Seller Account</label>
                        <input type="text" class="form-control" id="seller_account" name="seller_account">
                    </div>
                </div>
            </div>
            
            <div class="row">
                <div class="col-md-6">
                    <div class="mb-3">
                        <label for="trade_date" class="form-label">Trade Date *</label>
                        <input type="date" class="form-control" id="trade_date" name="trade_date" required value="<?php echo date('Y-m-d'); ?>">
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="mb-3">
                        <label for="settlement_date" class="form-label">Settlement Date *</label>
                        <input type="date" class="form-control" id="settlement_date" name="settlement_date" required value="<?php echo date('Y-m-d', strtotime('+2 days')); ?>">
                    </div>
                </div>
            </div>
            
            <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                <button type="submit" name="enter_share" class="btn btn-primary">
                    <i class="bi bi-check-lg"></i> Enter Share Trade
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Available Equities Reference -->
<div class="card mt-4">
    <div class="card-header">
        <h6 class="mb-0">Available Equities for Trading</h6>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-sm">
                <thead>
                    <tr>
                        <th>Symbol</th>
                        <th>Company Name</th>
                        <th>Sector</th>
                        <th>Current Price</th>
                        <th>Market Cap</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($available_equities as $equity): ?>
                        <tr>
                            <!-- Updated to use security_id instead of stock_symbol -->
                            <td><strong><?php echo htmlspecialchars($equity['security_id']); ?></strong></td>
                            <td><?php echo htmlspecialchars($equity['stock_name']); ?></td>
                            <td><?php echo htmlspecialchars($equity['sector'] ?? 'N/A'); ?></td>
                            <td>$<?php echo format_currency($equity['current_price'] ?? 0); ?></td>
                            <td>$<?php echo format_currency($equity['market_cap'] ?? 0); ?></td>
                            <td><span class="badge bg-success">Active</span></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
// Auto-uppercase stock symbol input
document.getElementById('security_id').addEventListener('input', function() {
    this.value = this.value.toUpperCase();
});

// Real-time stock symbol validation
document.getElementById('security_id').addEventListener('input', function() {
    const symbol = this.value;
    const pattern = /^[A-Z]{2,6}$/;
    
    if (symbol && !pattern.test(symbol)) {
        this.setCustomValidity('Stock symbol must be 2-6 uppercase letters');
    } else {
        this.setCustomValidity('');
    }
});
</script>

<?php include '../includes/footer.php'; ?>
