<?php
require_once '../config/config.php';
require_once '../auth/auth_middleware.php';

require_trader();
require_mandate();

$db = getDBConnection();
$trade = null;
$client = null;
$error_message = '';
$success_message = '';

// Check if a trade ID is provided in the URL
if (!isset($_GET['id']) || empty($_GET['id'])) {
    show_alert('No trade specified.', 'danger');
    redirect('trader/trades.php');
}

$trade_id = (int)$_GET['id'];
$user_id = $_SESSION['user_id'];

// Fetch the specific trade and verify ownership
$stmt = $db->prepare("SELECT * FROM trades WHERE id = ? AND uploaded_by = ?");
$stmt->execute([$trade_id, $user_id]);
$trade = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$trade) {
    show_alert('Trade not found or access denied.', 'danger');
    redirect('trader/trades.php');
}

// Fetch the client details to get the current default fee and type
$stmt_client = $db->prepare("SELECT * FROM clients WHERE client_name = ? AND created_by = ?");
$stmt_client->execute([$trade['client_name'], $user_id]);
$client = $stmt_client->fetch(PDO::FETCH_ASSOC);

// Determine the correct asset class for fee lookup
$asset_class_for_fee = $trade['asset_class'];
if ($asset_class_for_fee === 'bond') {
    // For now, we'll use 'BOND' as a general category for fixed income
    $asset_class_for_fee = 'BOND';
} else if ($asset_class_for_fee === 'equity') {
    $asset_class_for_fee = 'EQUITY';
}

// Fetch the standard brokerage fee from the fee_configurations table based on asset class
$stmt_fee = $db->prepare("
    SELECT rate_percentage FROM fee_configurations
    WHERE fee_type = 'BROKERAGE' AND applies_to = ? AND is_active = 1
");
$stmt_fee->execute([strtoupper($asset_class_for_fee)]);
$standard_rate_percentage = $stmt_fee->fetchColumn();

// If a specific asset class rate is not found, try to fetch the general one
if (!$standard_rate_percentage) {
    $stmt_fee_general = $db->prepare("
        SELECT rate_percentage FROM fee_configurations
        WHERE fee_type = 'BROKERAGE' AND applies_to = 'ALL' AND is_active = 1
    ");
    $stmt_fee_general->execute();
    $standard_rate_percentage = $stmt_fee_general->fetchColumn();
}

$standard_brokerage_fee = null;
if ($standard_rate_percentage !== null) {
    $standard_brokerage_fee = ($trade['consideration'] * $standard_rate_percentage) / 100;
}

// Handle trade amendment
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_trade'])) {
    $trade_type = sanitize_input($_POST['trade_type']);
    $security_id = sanitize_input($_POST['security_id']);
    $trade_side = sanitize_input($_POST['trade_side']);
    $quantity = (int)$_POST['quantity'];
    $price = (float)$_POST['price'];
    $buyer_name = sanitize_input($_POST['buyer_name']);
    $seller_name = sanitize_input($_POST['seller_name']);
    $buyer_account = sanitize_input($_POST['buyer_account']);
    $seller_account = sanitize_input($_POST['seller_account']);
    $trade_date = sanitize_input($_POST['trade_date']);
    $settlement_date = sanitize_input($_POST['settlement_date']);
    $consideration = $quantity * $price;

    $brokerage_fee_type = sanitize_input($_POST['brokerage_fee_type']);
    $custom_brokerage_value = null;

    if ($brokerage_fee_type === 'liberty' || $brokerage_fee_type === 'this_trade') {
        $custom_brokerage_value = (float)$_POST['custom_brokerage_fee'];
        if ($custom_brokerage_value <= 0) {
            $error_message = 'Custom brokerage fee must be greater than zero.';
        }
    }

    if (empty($trade_type) || empty($security_id) || empty($trade_side) ||
        $quantity <= 0 || $price <= 0 || empty($buyer_name) || empty($seller_name) ||
        empty($trade_date) || empty($settlement_date)) {
        $error_message = 'All required fields must be filled out, and quantity/price must be greater than zero.';
    } elseif ($error_message == '') {
        $final_brokerage_fee = 0;
        
        // Determine the final fee and its type based on user selection
        if ($brokerage_fee_type === 'normal') {
            $new_consideration = $quantity * $price;
            $final_brokerage_fee = ($new_consideration * $standard_rate_percentage) / 100;
            $custom_brokerage_value = null; // Clear custom value if normal fee is chosen
        } elseif ($brokerage_fee_type === 'liberty' || $brokerage_fee_type === 'this_trade') {
            $final_brokerage_fee = ($consideration * $custom_brokerage_value) / 100;
        }

        $stmt_update = $db->prepare("
            UPDATE trades SET
                asset_class = ?, security_id = ?, trade_side = ?,
                quantity = ?, price = ?, consideration = ?,
                client_name = ?, counterparty_name = ?,
                client_cds_account = ?, counterparty_cds_account = ?,
                trade_date = ?, settlement_date = ?,
                brokerage_fee_type = ?, custom_brokerage_fee = ?,
                final_brokerage_fee = ?
            WHERE id = ? AND uploaded_by = ?
        ");
        
        if ($stmt_update->execute([
            $trade_type, $security_id, $trade_side,
            $quantity, $price, $consideration,
            $buyer_name, $seller_name,
            $buyer_account, $seller_account,
            $trade_date, $settlement_date,
            $brokerage_fee_type, $custom_brokerage_value,
            $final_brokerage_fee,
            $trade_id, $user_id
        ])) {
            // Logic for updating the clients table based on the selected fee type
            $stmt_update_client_fee = null;
            if ($brokerage_fee_type === 'liberty') {
                // If 'liberty' is chosen, update the client's default fee
                $stmt_update_client_fee = $db->prepare("
                    UPDATE clients SET fee_type = 'liberty', default_brokerage_fee = ? WHERE client_name = ? AND created_by = ?
                ");
                $stmt_update_client_fee->execute([$custom_brokerage_value, $buyer_name, $user_id]);
            } else if ($brokerage_fee_type === 'normal') {
                // If 'normal' is chosen, reset the client's default fee to null and type to normal
                $stmt_update_client_fee = $db->prepare("
                    UPDATE clients SET fee_type = 'normal', default_brokerage_fee = NULL WHERE client_name = ? AND created_by = ?
                ");
                $stmt_update_client_fee->execute([$buyer_name, $user_id]);
            }

            $success_message = 'Trade updated successfully!';
            // Re-fetch the updated trade and client data to display on the page
            $stmt = $db->prepare("SELECT * FROM trades WHERE id = ? AND uploaded_by = ?");
            $stmt->execute([$trade_id, $user_id]);
            $trade = $stmt->fetch(PDO::FETCH_ASSOC);

            $stmt_client = $db->prepare("SELECT * FROM clients WHERE client_name = ? AND created_by = ?");
            $stmt_client->execute([$trade['client_name'], $user_id]);
            $client = $stmt_client->fetch(PDO::FETCH_ASSOC);
        } else {
            $error_message = 'Error updating trade. Please try again.';
        }
    }
}

$page_title = 'View Trade: ' . htmlspecialchars($trade['trade_reference']);
include '../includes/header.php';
?>

<div class="page-header">
    <div class="container-fluid">
        <div class="row align-items-center">
            <div class="col-md-8">
                <div class="d-flex align-items-center">
                    <div class="me-3">
                        <div class="d-inline-flex align-items-center justify-content-center rounded-circle shadow-sm"
                             style="width: 60px; height: 60px; background: linear-gradient(135deg, var(--primary-color) 0%, #3b82f6 100%);">
                            <i class="bi bi-card-checklist text-white" style="font-size: 1.5rem;"></i>
                        </div>
                    </div>
                    <div>
                        <h1 class="page-title mb-1">Trade Details & Amendment</h1>
                        <p class="page-subtitle">Viewing trade reference: <span class="fw-bold text-primary"><?php echo htmlspecialchars($trade['trade_reference']); ?></span></p>
                    </div>
                </div>
            </div>
            <div class="col-md-4 text-end">
                <a href="trades.php" class="btn btn-outline-secondary d-flex align-items-center justify-content-end">
                    <i class="bi bi-arrow-left me-2"></i>
                    <span class="d-none d-sm-inline">Back to Trades</span>
                </a>
            </div>
        </div>
    </div>
</div>

<div class="container-fluid">
    <?php if (!empty($success_message)): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <?php echo $success_message; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <?php if (!empty($error_message)): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <?php echo $error_message; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <div class="card dashboard-card">
        <div class="card-header bg-transparent border-0 pb-0">
            <h6 class="mb-0 fw-semibold">Trade Information</h6>
        </div>
        <div class="card-body">
            <form method="POST" action="" class="needs-validation" novalidate>
                <input type="hidden" name="update_trade" value="1">
                <div class="row g-4">
                    <div class="col-md-6">
                        <label for="trade_type" class="form-label fw-semibold text-dark">Asset Class *</label>
                        <div class="input-group">
                            <span class="input-group-text bg-light" style="border-color: var(--border-color);">
                                <i class="bi bi-collection text-muted"></i>
                            </span>
                            <select class="form-select" id="trade_type" name="trade_type" required onchange="updateInstruments()" style="border-color: var(--border-color);">
                                <option value="bond" <?php echo ($trade['asset_class'] == 'bond') ? 'selected' : ''; ?>>Fixed Income Bond</option>
                                <option value="equity" <?php echo ($trade['asset_class'] == 'equity') ? 'selected' : ''; ?>>Equity Security</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <label for="security_id" class="form-label fw-semibold text-dark">Security ID *</label>
                        <div class="input-group">
                            <span class="input-group-text bg-light" style="border-color: var(--border-color);">
                                <i class="bi bi-hash text-muted"></i>
                            </span>
                            <input type="text" class="form-control" id="security_id" name="security_id"
                                   value="<?php echo htmlspecialchars($trade['security_id']); ?>" required style="border-color: var(--border-color);">
                        </div>
                    </div>
                </div>

                <div class="section-divider"></div>

                <div class="row g-4">
                    <div class="col-md-4">
                        <label for="trade_side" class="form-label fw-semibold text-dark">Trade Side *</label>
                        <div class="input-group">
                            <span class="input-group-text bg-light" style="border-color: var(--border-color);">
                                <i class="bi bi-arrow-left-right text-muted"></i>
                            </span>
                            <select class="form-select" id="trade_side" name="trade_side" required style="border-color: var(--border-color);">
                                <option value="buy" <?php echo ($trade['trade_side'] == 'buy') ? 'selected' : ''; ?>>Buy Order</option>
                                <option value="sell" <?php echo ($trade['trade_side'] == 'sell') ? 'selected' : ''; ?>>Sell Order</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <label for="quantity" class="form-label fw-semibold text-dark">Quantity *</label>
                        <div class="input-group">
                            <span class="input-group-text bg-light" style="border-color: var(--border-color);">
                                <i class="bi bi-123 text-muted"></i>
                            </span>
                            <input type="number" class="form-control" id="quantity" name="quantity"
                                   value="<?php echo htmlspecialchars($trade['quantity']); ?>" required min="1"
                                   placeholder="0" style="border-color: var(--border-color);" oninput="calculateTotal()">
                        </div>
                    </div>
                    <div class="col-md-4">
                        <label for="price" class="form-label fw-semibold text-dark">Unit Price *</label>
                        <div class="input-group">
                            <span class="input-group-text bg-light" style="border-color: var(--border-color);">
                                <i class="bi bi-currency-dollar text-muted"></i>
                            </span>
                            <input type="number" class="form-control" id="price" name="price"
                                   value="<?php echo htmlspecialchars($trade['price']); ?>" required min="0.01" step="0.01"
                                   placeholder="0.00" style="border-color: var(--border-color);" oninput="calculateTotal()">
                        </div>
                    </div>
                </div>

                <div class="section-divider"></div>

                <div class="row g-4">
                    <div class="col-md-6">
                        <label for="buyer_name" class="form-label fw-semibold text-dark">Client Name *</label>
                        <div class="input-group">
                            <span class="input-group-text bg-light" style="border-color: var(--border-color);">
                                <i class="bi bi-person text-muted"></i>
                            </span>
                            <input type="text" class="form-control" id="buyer_name" name="buyer_name"
                                   value="<?php echo htmlspecialchars($trade['client_name']); ?>" required
                                   placeholder="Enter client name" style="border-color: var(--border-color);">
                        </div>
                    </div>
                    <div class="col-md-6">
                        <label for="buyer_account" class="form-label fw-semibold text-dark">Client CDS Account</label>
                        <div class="input-group">
                            <span class="input-group-text bg-light" style="border-color: var(--border-color);">
                                <i class="bi bi-bank text-muted"></i>
                            </span>
                            <input type="text" class="form-control" id="buyer_account" name="buyer_account"
                                   value="<?php echo htmlspecialchars($trade['client_cds_account']); ?>"
                                   placeholder="CDS account number" style="border-color: var(--border-color);">
                        </div>
                    </div>
                </div>

                <div class="row g-4 mt-3">
                    <div class="col-md-6">
                        <label for="seller_name" class="form-label fw-semibold text-dark">Counterparty Name *</label>
                        <div class="input-group">
                            <span class="input-group-text bg-light" style="border-color: var(--border-color);">
                                <i class="bi bi-building text-muted"></i>
                            </span>
                            <input type="text" class="form-control" id="seller_name" name="seller_name"
                                   value="<?php echo htmlspecialchars($trade['counterparty_name']); ?>" required
                                   placeholder="Enter counterparty name" style="border-color: var(--border-color);">
                        </div>
                    </div>
                    <div class="col-md-6">
                        <label for="seller_account" class="form-label fw-semibold text-dark">Counterparty CDS Account</label>
                        <div class="input-group">
                            <span class="input-group-text bg-light" style="border-color: var(--border-color);">
                                <i class="bi bi-bank text-muted"></i>
                            </span>
                            <input type="text" class="form-control" id="seller_account" name="seller_account"
                                   value="<?php echo htmlspecialchars($trade['counterparty_cds_account']); ?>"
                                   placeholder="CDS account number" style="border-color: var(--border-color);">
                        </div>
                    </div>
                </div>

                <div class="section-divider"></div>

                <div class="row g-4">
                    <div class="col-md-6">
                        <label for="trade_date" class="form-label fw-semibold text-dark">Trade Date *</label>
                        <div class="input-group">
                            <span class="input-group-text bg-light" style="border-color: var(--border-color);">
                                <i class="bi bi-calendar-date text-muted"></i>
                            </span>
                            <input type="date" class="form-control" id="trade_date" name="trade_date"
                                   value="<?php echo htmlspecialchars($trade['trade_date']); ?>" required style="border-color: var(--border-color);">
                        </div>
                    </div>
                    <div class="col-md-6">
                        <label for="settlement_date" class="form-label fw-semibold text-dark">Settlement Date *</label>
                        <div class="input-group">
                            <span class="input-group-text bg-light" style="border-color: var(--border-color);">
                                <i class="bi bi-calendar-check text-muted"></i>
                            </span>
                            <input type="date" class="form-control" id="settlement_date" name="settlement_date"
                                   value="<?php echo htmlspecialchars($trade['settlement_date']); ?>" required style="border-color: var(--border-color);">
                        </div>
                    </div>
                </div>

                <div class="section-divider"></div>

                <div class="row g-4">
                    <div class="col-md-6">
                        <label for="brokerage_fee_type" class="form-label fw-semibold text-dark">Brokerage Fee Option</label>
                        <div class="input-group">
                            <span class="input-group-text bg-light" style="border-color: var(--border-color);">
                                <i class="bi bi-gear text-muted"></i>
                            </span>
                            <select class="form-select" id="brokerage_fee_type" name="brokerage_fee_type" onchange="toggleCustomFee()" style="border-color: var(--border-color);">
                                <option value="normal" <?php echo ($trade['brokerage_fee_type'] == 'normal') ? 'selected' : ''; ?>>Normal Fee (<?php echo number_format($standard_rate_percentage, 2); ?>%)</option>
                                <?php if ($client['fee_type'] == 'liberty' && $client['default_brokerage_fee'] !== null): ?>
                                    <option value="liberty" <?php echo ($trade['brokerage_fee_type'] == 'liberty') ? 'selected' : ''; ?>>Liberty (Client Default) (<?php echo number_format($client['default_brokerage_fee'], 2); ?>%)</option>
                                <?php endif; ?>
                                <option value="this_trade" <?php echo ($trade['brokerage_fee_type'] == 'this_trade') ? 'selected' : ''; ?>>This Trade Only (Custom)</option>
                                <option value="liberty_new" <?php echo ($trade['brokerage_fee_type'] == 'liberty_new') ? 'selected' : ''; ?>>Set New Liberty Fee</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-6" id="custom-fee-section" style="display: <?php echo ($trade['brokerage_fee_type'] == 'this_trade' || $trade['brokerage_fee_type'] == 'liberty_new') ? 'block' : 'none'; ?>;">
                        <label for="custom_brokerage_fee" class="form-label fw-semibold text-dark">Custom Brokerage Rate (%)</label>
                        <div class="input-group">
                            <span class="input-group-text bg-light" style="border-color: var(--border-color);">
                                <i class="bi bi-percent text-muted"></i>
                            </span>
                            <input type="number" class="form-control" id="custom_brokerage_fee" name="custom_brokerage_fee"
                                   value="<?php echo htmlspecialchars($trade['custom_brokerage_fee']); ?>" step="0.01" min="0"
                                   placeholder="Enter new rate" style="border-color: var(--border-color);">
                        </div>
                    </div>
                </div>
                
                <div id="liberty-option-section" class="form-check mt-2" style="display: <?php echo ($trade['brokerage_fee_type'] == 'liberty') ? 'block' : 'none'; ?>;">
                    <label class="form-check-label text-muted" for="apply_to_all_trades">
                        This fee is the default for this client.
                    </label>
                </div>
                <div id="this-trade-option-section" class="form-check mt-2" style="display: <?php echo ($trade['brokerage_fee_type'] == 'this_trade') ? 'block' : 'none'; ?>;">
                    <label class="form-check-label text-muted" for="apply_to_all_trades">
                        This fee is only applied to this trade.
                    </label>
                </div>
                 <div id="liberty-new-section" class="form-check mt-2" style="display: <?php echo ($trade['brokerage_fee_type'] == 'liberty_new') ? 'block' : 'none'; ?>;">
                    <label class="form-check-label text-muted" for="apply_to_all_trades">
                        This new rate will be set as the permanent default for this client.
                    </label>
                </div>
                
                <div class="d-flex justify-content-end mt-4">
                    <button type="submit" class="btn btn-primary btn-lg">
                        <i class="bi bi-save me-2"></i>
                        Save Changes
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    function toggleCustomFee() {
        const feeType = document.getElementById('brokerage_fee_type').value;
        const customFeeSection = document.getElementById('custom-fee-section');
        const libertyOptionSection = document.getElementById('liberty-option-section');
        const thisTradeOptionSection = document.getElementById('this-trade-option-section');
        const libertyNewSection = document.getElementById('liberty-new-section');

        // Hide all custom-related sections by default
        customFeeSection.style.display = 'none';
        libertyOptionSection.style.display = 'none';
        thisTradeOptionSection.style.display = 'none';
        libertyNewSection.style.display = 'none';
        
        // Remove 'required' attribute initially
        document.getElementById('custom_brokerage_fee').removeAttribute('required');

        if (feeType === 'this_trade' || feeType === 'liberty_new') {
            customFeeSection.style.display = 'block';
            document.getElementById('custom_brokerage_fee').setAttribute('required', 'required');
            if (feeType === 'this_trade') {
                thisTradeOptionSection.style.display = 'block';
            } else if (feeType === 'liberty_new') {
                libertyNewSection.style.display = 'block';
            }
        } else if (feeType === 'liberty') {
            libertyOptionSection.style.display = 'block';
        }
    }

    // Call on page load to set initial state
    window.addEventListener('load', () => {
        toggleCustomFee();
    });
</script>

<?php include '../includes/footer.php'; ?>