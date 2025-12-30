<?php
require_once '../config/config.php';
require_once '../auth/auth_middleware.php';
require_once '../includes/bond_validation.php';

require_trader();
require_mandate();

$db = getDBConnection();
$success_message = '';
$error_message = '';

// Handle bond entry
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['enter_bond'])) {
    $security_id = sanitize_input($_POST['security_id']);
    $quantity = (int)$_POST['quantity'];
    $price = (float)$_POST['price'];
    $buyer_name = sanitize_input($_POST['buyer_name']);
    $seller_name = sanitize_input($_POST['seller_name']);
    $buyer_account = sanitize_input($_POST['buyer_account']);
    $seller_account = sanitize_input($_POST['seller_account']);
    $trade_date = sanitize_input($_POST['trade_date']);
    $settlement_date = sanitize_input($_POST['settlement_date']);
    
    $ats_validation = validate_ats_code($security_id);
    if (!$ats_validation['valid']) {
        $error_message = $ats_validation['error'];
    } else {
        $bond_verification = verify_bond_exists($security_id, $db);
        if (!$bond_verification['exists']) {
            $error_message = $bond_verification['error'] . ' Corporate bonds must be created through auction first.';
        } else {
            $bond = $bond_verification['bond'];
            $total_value = $quantity * $price;
            $trade_reference = generate_reference_number('TRD');
            
            $stmt = $db->prepare("
                INSERT INTO trades (trade_reference, asset_class, security_id, security_name, trade_side, quantity, price, 
                                  consideration, client_name, counterparty_name, client_cds_account, counterparty_cds_account, 
                                  trade_date, settlement_date, uploaded_by) 
                VALUES (?, 'bond', ?, ?, 'buy', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            if ($stmt->execute([$trade_reference, $security_id, $bond['bond_name'], $quantity, $price, $total_value, 
                              $buyer_name, $seller_name, $buyer_account, $seller_account,
                              $trade_date, $settlement_date, $_SESSION['user_id']])) {
                show_alert('Bond trade entered successfully with reference: ' . $trade_reference, 'success');
                redirect('trader/enter_bonds.php');
            } else {
                $error_message = 'Error entering bond trade. Please try again.';
            }
        }
    }
}

// Get available bonds for reference
$available_bonds = get_available_bonds_for_trading($db);

$page_title = 'Enter Bond Trades';
include '../includes/header.php';
?>

<div class="row">
    <div class="col-12">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2><i class="bi bi-bank"></i> Enter Bond Trades</h2>
            <a href="trades.php" class="btn btn-outline-secondary">
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

<!-- Bond Entry Form -->
<div class="card">
    <div class="card-header">
        <h6 class="mb-0">Enter New Bond Trade</h6>
    </div>
    <div class="card-body">
        <div class="alert alert-info">
            <i class="bi bi-info-circle"></i> 
            <strong>Security ID Format:</strong> Must be in format "675-15-T16-A1" (Bond Number-Coupon Rate-Term Years-Auction Number).
            Only bonds created through auction system will be accepted.
        </div>
        
        <form method="POST" action="">
            <div class="row">
                <div class="col-md-6">
                    <div class="mb-3">
                        <label for="security_id" class="form-label">Security ID (ATS Code) *</label>
                        <input type="text" class="form-control" id="security_id" name="security_id" 
                               placeholder="675-15-T16-A1" required pattern="^\d+-\d+(\.\d+)?-T\d+-[A-Z0-9]+$"
                               title="Format: BondNumber-CouponRate-TYears-AuctionNumber">
                        <div class="form-text">Example: 675-15-T16-A1 (Bond 675, 15% coupon, 16 years term, Auction A1)</div>
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
                <button type="submit" name="enter_bond" class="btn btn-primary">
                    <i class="bi bi-check-lg"></i> Enter Bond Trade
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Available Bonds Reference -->
<div class="card mt-4">
    <div class="card-header">
        <h6 class="mb-0">Available Bonds for Trading</h6>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-sm">
                <thead>
                    <tr>
                        <th>ATS Code</th>
                        <th>Bond Name</th>
                        <th>Coupon Rate</th>
                        <th>Face Value</th>
                        <th>Maturity Date</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($available_bonds as $bond): ?>
                        <tr>
                            <!-- Updated to use security_id instead of ats_code -->
                            <td><strong><?php echo htmlspecialchars($bond['security_id']); ?></strong></td>
                            <td><?php echo htmlspecialchars($bond['bond_name']); ?></td>
                            <td><?php echo $bond['coupon_rate']; ?>%</td>
                            <td>$<?php echo format_currency($bond['face_value']); ?></td>
                            <td><?php echo format_date($bond['maturity_date']); ?></td>
                            <td><span class="badge bg-success">Active</span></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
// Real-time ATS code validation
document.getElementById('security_id').addEventListener('input', function() {
    const atsCode = this.value;
    const pattern = /^\d+-\d+(\.\d+)?-T\d+-[A-Z0-9]+$/;
    
    if (atsCode && !pattern.test(atsCode)) {
        this.setCustomValidity('Invalid ATS code format. Use: BondNumber-CouponRate-TYears-AuctionNumber');
    } else {
        this.setCustomValidity('');
    }
});
</script>

<?php include '../includes/footer.php'; ?>
