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

// Fetch the specific trade without ownership verification
$stmt = $db->prepare("SELECT * FROM trades WHERE id = ?");
$stmt->execute([$trade_id]);
$trade = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$trade) {
    show_alert('Trade not found.', 'danger');
    redirect('trader/trades.php');
}

// Fetch the client details using CDS account
$stmt_client = $db->prepare("SELECT * FROM clients WHERE cds_account = ?");
$stmt_client->execute([$trade['client_cds_account']]);
$client = $stmt_client->fetch(PDO::FETCH_ASSOC);

// Determine the correct asset class for fee lookup
$asset_class_for_fee = $trade['asset_class'];
if ($asset_class_for_fee === 'bond') {
    $asset_class_for_fee = 'BOND';
} else if ($asset_class_for_fee === 'equity') {
    $asset_class_for_fee = 'EQUITY';
} else if ($asset_class_for_fee === 'Exchange Traded Funds') {
    $asset_class_for_fee = 'ETF';
}

// Fetch the standard brokerage fee
$stmt_fee = $db->prepare("
    SELECT rate_percentage FROM fee_configurations
    WHERE fee_type = 'BROKERAGE' AND applies_to = ? AND is_active = 1
");
$stmt_fee->execute([strtoupper($asset_class_for_fee)]);
$standard_rate_percentage = $stmt_fee->fetchColumn();

if (!$standard_rate_percentage) {
    $stmt_fee_general = $db->prepare("
        SELECT rate_percentage FROM fee_configurations
        WHERE fee_type = 'BROKERAGE' AND applies_to = 'ALL' AND is_active = 1
    ");
    $stmt_fee_general->execute();
    $standard_rate_percentage = $stmt_fee_general->fetchColumn();
}

// ============ BOND CALCULATIONS ============
function calculateBondBrokerageFee($face_value) {
    $first_100m = min($face_value, 100000000) * (0.063132 / 100);
    $excess = max($face_value - 100000000, 0) * (0.035 / 100);
    return $first_100m + $excess;
}

function calculateBondLibertyExcess($face_value, $rate) {
    $first_100m = min($face_value, 100000000) * (0.063132 / 100);
    $excess = max($face_value - 100000000, 0) * ($rate / 100);
    return $first_100m + $excess;
}

function calculateBondLibertyReplaceAll($face_value, $rate) {
    return $face_value * ($rate / 100);
}

// ============ EQUITY CALCULATIONS - CORRECTED ============
function calculateEquityStandard($consideration) {
    $rate1 = 1.7;
    $rate2 = 1.5;
    $rate3 = 0.8;
    $limit1 = 10000000;
    $limit2 = 40000000;
    
    if ($consideration <= $limit1) {
        return $consideration * ($rate1 / 100);
    } elseif ($consideration <= $limit2) {
        $tier1 = $limit1 * ($rate1 / 100);
        $tier2 = ($consideration - $limit1) * ($rate2 / 100);
        return $tier1 + $tier2;
    } else {
        $tier1 = $limit1 * ($rate1 / 100);
        $tier2 = ($limit2 - $limit1) * ($rate2 / 100);
        $tier3 = ($consideration - $limit2) * ($rate3 / 100);
        return $tier1 + $tier2 + $tier3;
    }
}

function calculateEquityLibertyTierOverride($consideration, $rate) {
    $standard_rate = 1.7;
    $limit1 = 10000000;
    
    if ($consideration <= $limit1) {
        return $consideration * ($standard_rate / 100);
    } else {
        $tier1 = $limit1 * ($standard_rate / 100);
        $excess = ($consideration - $limit1) * ($rate / 100);
        return $tier1 + $excess;
    }
}

function calculateEquityLibertyReplaceAll($consideration, $rate) {
    return $consideration * ($rate / 100);
}

// ============ MAIN CALCULATION ============
function calculateBrokerageFee($trade, $rate, $mode = 'replace_all') {
    $is_bond = ($trade['asset_class'] === 'bond' || $trade['asset_class'] === 'treasury_bond');
    
    if ($is_bond) {
        $face_value = floatval($trade['quantity']);
        if ($mode === 'excess_only') {
            return calculateBondLibertyExcess($face_value, $rate);
        } elseif ($mode === 'replace_all') {
            return calculateBondLibertyReplaceAll($face_value, $rate);
        } else {
            return calculateBondBrokerageFee($face_value);
        }
    } else {
        $consideration = floatval($trade['consideration']);
        if ($mode === 'standard') {
            return calculateEquityStandard($consideration);
        } elseif ($mode === 'tier_override') {
            return calculateEquityLibertyTierOverride($consideration, $rate);
        } elseif ($mode === 'replace_all') {
            return calculateEquityLibertyReplaceAll($consideration, $rate);
        } else {
            return calculateEquityStandard($consideration);
        }
    }
}

// ============ HELPER FUNCTIONS ============
function getModeText($asset_class, $mode) {
    if ($asset_class === 'bond') {
        if ($mode === 'excess_only') {
            return 'Excess Only (First 100M Standard, Excess at Liberty)';
        } else {
            return 'Replace All (Full Liberty Rate)';
        }
    } else {
        if ($mode === 'tier_override') {
            return 'Tier Override (First 10M Standard at 1.7%, Excess at Liberty)';
        } else {
            return 'Replace All (Full Liberty Rate)';
        }
    }
}

function updateAllClientTradesToLiberty($db, $cds_account, $rate, $mode, $asset_class) {
    $updated = 0;
    $errors = [];
    
    try {
        $stmt = $db->prepare("
            SELECT id, quantity, consideration, asset_class
            FROM trades 
            WHERE client_cds_account = ? AND asset_class = ? AND status = 'active'
        ");
        $stmt->execute([$cds_account, $asset_class]);
        $trades = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($trades as $t) {
            $temp = ['asset_class' => $asset_class, 'quantity' => $t['quantity'], 'consideration' => $t['consideration']];
            $fee = calculateBrokerageFee($temp, $rate, $mode);
            
            $upd = $db->prepare("
                UPDATE trades 
                SET brokerage_fee_type = 'liberty', custom_brokerage_fee = ?, liberty_mode = ?, final_brokerage_fee = ?
                WHERE id = ? AND client_cds_account = ?
            ");
            if ($upd->execute([$rate, $mode, $fee, $t['id'], $cds_account])) {
                $updated++;
            }
        }
        return ['success' => true, 'updated_count' => $updated, 'errors' => $errors];
    } catch (Exception $e) {
        return ['success' => false, 'updated_count' => 0, 'errors' => [$e->getMessage()]];
    }
}

// ============ BULK UPDATE FUNCTION ============
function bulkUpdateTradesToLiberty($db, $cds_account, $rate, $mode, $filters = []) {
    $updated = 0;
    $errors = [];
    $trade_ids = [];
    
    try {
        // Build WHERE clause based on filters
        $where_parts = ["client_cds_account = ?", "status = 'active'"];
        $params = [$cds_account];
        
        if (!empty($filters['security_id'])) {
            $where_parts[] = "security_id = ?";
            $params[] = $filters['security_id'];
        }
        
        if (!empty($filters['asset_class'])) {
            $where_parts[] = "asset_class = ?";
            $params[] = $filters['asset_class'];
        }
        
        if (!empty($filters['trade_side'])) {
            $where_parts[] = "trade_side = ?";
            $params[] = $filters['trade_side'];
        }
        
        if (!empty($filters['date_from'])) {
            $where_parts[] = "trade_date >= ?";
            $params[] = $filters['date_from'];
        }
        
        if (!empty($filters['date_to'])) {
            $where_parts[] = "trade_date <= ?";
            $params[] = $filters['date_to'];
        }
        
        $where_clause = implode(' AND ', $where_parts);
        
        $stmt = $db->prepare("
            SELECT id, quantity, consideration, asset_class
            FROM trades 
            WHERE $where_clause
        ");
        $stmt->execute($params);
        $trades = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($trades)) {
            return ['success' => false, 'updated_count' => 0, 'errors' => ['No trades found matching the criteria.']];
        }
        
        $trade_ids = array_column($trades, 'id');
        
        foreach ($trades as $t) {
            $temp = ['asset_class' => $t['asset_class'], 'quantity' => $t['quantity'], 'consideration' => $t['consideration']];
            $fee = calculateBrokerageFee($temp, $rate, $mode);
            
            $upd = $db->prepare("
                UPDATE trades 
                SET brokerage_fee_type = 'liberty', 
                    custom_brokerage_fee = ?, 
                    liberty_mode = ?, 
                    final_brokerage_fee = ?
                WHERE id = ? AND client_cds_account = ?
            ");
            if ($upd->execute([$rate, $mode, $fee, $t['id'], $cds_account])) {
                $updated++;
            } else {
                $errors[] = "Failed to update trade ID: " . $t['id'];
            }
        }
        
        return [
            'success' => true, 
            'updated_count' => $updated, 
            'errors' => $errors,
            'trade_ids' => $trade_ids
        ];
        
    } catch (Exception $e) {
        return ['success' => false, 'updated_count' => 0, 'errors' => [$e->getMessage()]];
    }
}

// Determine effective rate
$effective_rate = null;
$effective_mode = $trade['liberty_mode'] ?? 'replace_all';

if ($trade['brokerage_fee_type'] === 'normal') {
    $effective_rate = $standard_rate_percentage;
    $effective_mode = 'standard';
} elseif ($trade['brokerage_fee_type'] === 'liberty' && $client && $client['fee_type'] === 'liberty') {
    $effective_rate = $client['default_brokerage_fee'];
    $effective_mode = $client['liberty_mode'] ?? 'replace_all';
} elseif ($trade['brokerage_fee_type'] === 'this_trade') {
    $effective_rate = $trade['custom_brokerage_fee'];
    $effective_mode = $trade['liberty_mode'] ?? 'replace_all';
}

// Get distinct securities for this client
$stmt_securities = $db->prepare("
    SELECT DISTINCT security_id 
    FROM trades 
    WHERE client_cds_account = ? 
    AND status = 'active'
    ORDER BY security_id
");
$stmt_securities->execute([$trade['client_cds_account']]);
$client_securities = $stmt_securities->fetchAll(PDO::FETCH_COLUMN);

// Get distinct asset classes for this client
$stmt_asset_classes = $db->prepare("
    SELECT DISTINCT asset_class 
    FROM trades 
    WHERE client_cds_account = ? 
    AND status = 'active'
    ORDER BY asset_class
");
$stmt_asset_classes->execute([$trade['client_cds_account']]);
$client_asset_classes = $stmt_asset_classes->fetchAll(PDO::FETCH_COLUMN);

// Get trade date range for this client
$stmt_dates = $db->prepare("
    SELECT MIN(trade_date) as min_date, MAX(trade_date) as max_date
    FROM trades 
    WHERE client_cds_account = ? 
    AND status = 'active'
");
$stmt_dates->execute([$trade['client_cds_account']]);
$date_range = $stmt_dates->fetch(PDO::FETCH_ASSOC);

// Handle POST for single trade update
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_trade'])) {
    $trade_type = sanitize_input($_POST['trade_type']);
    $security_id = sanitize_input($_POST['security_id']);
    $trade_side = sanitize_input($_POST['trade_side']);
    $quantity = (float)$_POST['quantity'];
    $price = (float)$_POST['price'];
    $buyer_name = sanitize_input($_POST['buyer_name']);
    $seller_name = sanitize_input($_POST['seller_name']);
    $buyer_account = sanitize_input($_POST['buyer_account']);
    $seller_account = sanitize_input($_POST['seller_account']);
    $trade_date = sanitize_input($_POST['trade_date']);
    $settlement_date = sanitize_input($_POST['settlement_date']);
    
    $is_bond_trade = ($trade_type === 'bond');
    if ($is_bond_trade) {
        $consideration = $quantity * $price / 100;
    } else {
        $consideration = (float)$_POST['consideration'] ?? ($quantity * $price);
    }
    
    $brokerage_fee_type = sanitize_input($_POST['brokerage_fee_type']);
    $liberty_mode = sanitize_input($_POST['liberty_mode'] ?? 'replace_all');
    $custom_value = null;
    $final_fee = 0;
    $update_existing = isset($_POST['update_existing_trades']) ? (int)$_POST['update_existing_trades'] : 0;
    $existing_updated = 0;
    
    $temp_trade = ['asset_class' => $trade_type, 'quantity' => $quantity, 'consideration' => $consideration];
    
    if (empty($trade_type) || empty($security_id) || empty($trade_side) || $quantity <= 0 || $price <= 0 || empty($buyer_name) || empty($seller_name) || empty($trade_date) || empty($settlement_date)) {
        $error_message = 'All required fields must be filled out.';
    } else {
        switch ($brokerage_fee_type) {
            case 'normal':
                if ($standard_rate_percentage !== null) {
                    $final_fee = calculateBrokerageFee($temp_trade, $standard_rate_percentage, 'standard');
                    $db->prepare("UPDATE clients SET fee_type = 'normal', default_brokerage_fee = NULL, liberty_mode = NULL WHERE cds_account = ?")->execute([$buyer_account]);
                    $success_message = 'Standard fee applied.';
                } else {
                    $error_message = 'Standard fee rate not configured.';
                }
                break;
            case 'liberty':
                if ($client && $client['fee_type'] === 'liberty' && $client['default_brokerage_fee'] !== null) {
                    $final_fee = calculateBrokerageFee($temp_trade, $client['default_brokerage_fee'], $client['liberty_mode'] ?? 'replace_all');
                    $success_message = 'Using permanent liberty fee: ' . number_format($client['default_brokerage_fee'], 5) . '%';
                } else {
                    $error_message = 'No liberty fee set for this client.';
                }
                break;
            case 'liberty_new':
                $custom_value = (float)$_POST['custom_brokerage_fee'];
                if ($custom_value <= 0 || $custom_value > 100) {
                    $error_message = 'Invalid fee rate.';
                } else {
                    $db->prepare("UPDATE clients SET fee_type = 'liberty', default_brokerage_fee = ?, liberty_mode = ? WHERE cds_account = ?")->execute([$custom_value, $liberty_mode, $buyer_account]);
                    $final_fee = calculateBrokerageFee($temp_trade, $custom_value, $liberty_mode);
                    $brokerage_fee_type = 'liberty';
                    
                    if ($update_existing) {
                        $result = updateAllClientTradesToLiberty($db, $buyer_account, $custom_value, $liberty_mode, $trade_type);
                        $existing_updated = $result['updated_count'];
                    }
                    $success_message = 'Liberty fee of ' . number_format($custom_value, 5) . '% set for ' . htmlspecialchars($buyer_name);
                    if ($existing_updated > 0) {
                        $success_message .= ' Updated ' . $existing_updated . ' existing trades.';
                    }
                }
                break;
            case 'this_trade':
                $custom_value = (float)$_POST['custom_brokerage_fee'];
                if ($custom_value <= 0 || $custom_value > 100) {
                    $error_message = 'Invalid fee rate.';
                } else {
                    $final_fee = calculateBrokerageFee($temp_trade, $custom_value, $liberty_mode);
                    $success_message = 'Custom fee of ' . number_format($custom_value, 5) . '% applied to this trade only.';
                }
                break;
            default:
                $error_message = 'Invalid fee type.';
        }
        
        if (empty($error_message)) {
            try {
                $stmt = $db->prepare("
                    UPDATE trades SET
                        asset_class = ?, security_id = ?, trade_side = ?,
                        quantity = ?, price = ?, consideration = ?,
                        client_name = ?, counterparty_name = ?,
                        client_cds_account = ?, counterparty_cds_account = ?,
                        trade_date = ?, settlement_date = ?,
                        brokerage_fee_type = ?, custom_brokerage_fee = ?,
                        liberty_mode = ?,
                        final_brokerage_fee = ?
                    WHERE id = ?
                ");
                $stmt->execute([
                    $trade_type, $security_id, $trade_side,
                    $quantity, $price, $consideration,
                    $buyer_name, $seller_name,
                    $buyer_account, $seller_account,
                    $trade_date, $settlement_date,
                    $brokerage_fee_type, $custom_value,
                    $liberty_mode,
                    $final_fee,
                    $trade_id
                ]);
                $_SESSION['success_message'] = $success_message ?: 'Trade updated successfully!';
                header("Location: view_trade.php?id=" . $trade_id);
                exit();
            } catch (PDOException $e) {
                $error_message = 'Database error: ' . $e->getMessage();
            }
        }
    }
}

// Handle POST for bulk update
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['bulk_update_liberty'])) {
    $bulk_rate = (float)$_POST['bulk_liberty_rate'];
    $bulk_mode = sanitize_input($_POST['bulk_liberty_mode']);
    $bulk_security = !empty($_POST['bulk_security_id']) ? sanitize_input($_POST['bulk_security_id']) : null;
    $bulk_asset_class = !empty($_POST['bulk_asset_class']) ? sanitize_input($_POST['bulk_asset_class']) : null;
    $bulk_trade_side = !empty($_POST['bulk_trade_side']) ? sanitize_input($_POST['bulk_trade_side']) : null;
    $bulk_date_from = !empty($_POST['bulk_date_from']) ? sanitize_input($_POST['bulk_date_from']) : null;
    $bulk_date_to = !empty($_POST['bulk_date_to']) ? sanitize_input($_POST['bulk_date_to']) : null;
    $bulk_client_cds = sanitize_input($_POST['bulk_client_cds']);
    
    if ($bulk_rate <= 0 || $bulk_rate > 100) {
        $error_message = 'Liberty rate must be between 0 and 100.';
    } else {
        $filters = [
            'security_id' => $bulk_security,
            'asset_class' => $bulk_asset_class,
            'trade_side' => $bulk_trade_side,
            'date_from' => $bulk_date_from,
            'date_to' => $bulk_date_to
        ];
        
        $result = bulkUpdateTradesToLiberty($db, $bulk_client_cds, $bulk_rate, $bulk_mode, $filters);
        
        if ($result['success']) {
            $success_message = 'Successfully updated ' . $result['updated_count'] . ' trades with ' . number_format($bulk_rate, 4) . '% liberty rate (Mode: ' . ucfirst(str_replace('_', ' ', $bulk_mode)) . ').';
            if (!empty($result['errors'])) {
                $success_message .= ' Errors: ' . implode(', ', $result['errors']);
            }
        } else {
            $error_message = 'Bulk update failed: ' . implode(', ', $result['errors']);
        }
    }
}

if (isset($_SESSION['success_message'])) {
    $success_message = $_SESSION['success_message'];
    unset($_SESSION['success_message']);
}

$stmt = $db->prepare("SELECT * FROM trades WHERE id = ?");
$stmt->execute([$trade_id]);
$trade = $stmt->fetch(PDO::FETCH_ASSOC);

if ($trade) {
    $stmt_client = $db->prepare("SELECT * FROM clients WHERE cds_account = ?");
    $stmt_client->execute([$trade['client_cds_account']]);
    $client = $stmt_client->fetch(PDO::FETCH_ASSOC);
}

$existing_trades_count = 0;
if ($trade && $trade['client_cds_account']) {
    $stmt = $db->prepare("SELECT COUNT(*) FROM trades WHERE client_cds_account = ? AND id != ? AND status = 'active'");
    $stmt->execute([$trade['client_cds_account'], $trade_id]);
    $existing_trades_count = $stmt->fetchColumn();
}

$is_bond = ($trade['asset_class'] === 'bond' || $trade['asset_class'] === 'treasury_bond');
$fee_calculation_note = $is_bond ? 
    'Bond: Face Value tiered (First 100M at 0.063132%, excess at 0.035%)' : 
    'Equity: Consideration tiered (First 10M at 1.7%, Next 30M at 1.5%, Excess at 0.8%)';

$page_title = 'View Trade: ' . htmlspecialchars($trade['trade_reference']);
include '../includes/header.php';
?>

<style>
    .mode-card {
        cursor: pointer;
        transition: all 0.2s ease;
    }
    .mode-card:hover {
        border-color: var(--primary-color) !important;
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(0,0,0,0.1);
    }
    .mode-card input[type="radio"] {
        cursor: pointer;
    }
    .bulk-filters {
        background: #f8f9fa;
        padding: 15px;
        border-radius: 8px;
        border: 1px solid #e9ecef;
    }
    .bulk-preview {
        max-height: 200px;
        overflow-y: auto;
        font-size: 0.85rem;
    }
</style>

<div class="page-header">
    <div class="container-fluid">
        <div class="row align-items-center">
            <div class="col-md-8">
                <div class="d-flex align-items-center">
                    <div class="me-3">
                        <div class="d-inline-flex align-items-center justify-content-center rounded-circle shadow-sm"
                             style="width: 60px; height: 60px; background: linear-gradient(135deg, var(--primary-color) 0%, #3b82f6 100%);">
                            <i class="bi bi-card-checklist" style="font-size: 1.5rem;"></i>
                        </div>
                    </div>
                    <div>
                        <h1 class="page-title mb-1">Trade Details & Amendment</h1>
                        <p class="page-subtitle">Viewing trade reference: <span class="fw-bold text-primary"><?php echo htmlspecialchars($trade['trade_reference']); ?></span></p>
                        <?php if ($trade['uploaded_by'] && isset($_SESSION['user_id']) && $trade['uploaded_by'] != $_SESSION['user_id']): ?>
                            <span class="badge bg-info mt-1"><i class="bi bi-eye"></i> Viewing trade uploaded by another user</span>
                        <?php endif; ?>
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
            <i class="bi bi-check-circle-fill me-2"></i>
            <?php echo htmlspecialchars($success_message); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <?php if (!empty($error_message)): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="bi bi-exclamation-triangle-fill me-2"></i>
            <?php echo htmlspecialchars($error_message); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <!-- Single Trade Update -->
    <div class="card dashboard-card">
        <div class="card-header bg-transparent border-0 pb-0">
            <h6 class="mb-0 fw-semibold">Trade Information</h6>
        </div>
        <div class="card-body">
            <form method="POST" action="" id="tradeForm" novalidate>
                <input type="hidden" name="update_trade" value="1">
                <div class="row g-4">
                    <div class="col-md-6">
                        <label for="trade_type" class="form-label fw-semibold text-dark">Asset Class *</label>
                        <select class="form-select" id="trade_type" name="trade_type" onchange="previewFee()">
                            <option value="bond" <?php echo ($trade['asset_class'] == 'bond') ? 'selected' : ''; ?>>Fixed Income Bond</option>
                            <option value="equity" <?php echo ($trade['asset_class'] == 'equity') ? 'selected' : ''; ?>>Equity Security</option>
                            <option value="Exchange Traded Funds" <?php echo ($trade['asset_class'] == 'Exchange Traded Funds') ? 'selected' : ''; ?>>Exchange Traded Fund (ETF)</option>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label for="security_id" class="form-label fw-semibold text-dark">Security ID *</label>
                        <input type="text" class="form-control" id="security_id" name="security_id"
                               value="<?php echo htmlspecialchars($trade['security_id']); ?>">
                    </div>
                </div>

                <hr class="my-3">

                <div class="row g-4">
                    <div class="col-md-4">
                        <label for="trade_side" class="form-label fw-semibold text-dark">Trade Side *</label>
                        <select class="form-select" id="trade_side" name="trade_side">
                            <option value="buy" <?php echo ($trade['trade_side'] == 'buy') ? 'selected' : ''; ?>>Buy Order</option>
                            <option value="sell" <?php echo ($trade['trade_side'] == 'sell') ? 'selected' : ''; ?>>Sell Order</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label for="quantity" class="form-label fw-semibold text-dark">Quantity *</label>
                        <input type="number" class="form-control" id="quantity" name="quantity"
                               value="<?php echo htmlspecialchars($trade['quantity']); ?>" step="any" oninput="previewFee()">
                        <?php if ($is_bond): ?>
                            <small class="text-muted">Face value for bonds</small>
                        <?php endif; ?>
                    </div>
                    <div class="col-md-4">
                        <label for="price" class="form-label fw-semibold text-dark">Unit Price *</label>
                        <input type="number" class="form-control" id="price" name="price"
                               value="<?php echo htmlspecialchars($trade['price']); ?>" step="any" oninput="previewFee()">
                        <?php if ($is_bond): ?>
                            <small class="text-muted">Percentage of par for bonds</small>
                        <?php endif; ?>
                    </div>
                </div>

                <hr class="my-3">

                <input type="hidden" id="consideration" name="consideration" value="<?php echo htmlspecialchars($trade['consideration']); ?>">

                <div class="row g-4">
                    <div class="col-md-6">
                        <label for="buyer_name" class="form-label fw-semibold text-dark">Client Name *</label>
                        <input type="text" class="form-control" id="buyer_name" name="buyer_name"
                               value="<?php echo htmlspecialchars($trade['client_name']); ?>">
                    </div>
                    <div class="col-md-6">
                        <label for="buyer_account" class="form-label fw-semibold text-dark">Client CDS Account</label>
                        <input type="text" class="form-control" id="buyer_account" name="buyer_account"
                               value="<?php echo htmlspecialchars($trade['client_cds_account']); ?>">
                    </div>
                </div>

                <div class="row g-4 mt-3">
                    <div class="col-md-6">
                        <label for="seller_name" class="form-label fw-semibold text-dark">Counterparty Name *</label>
                        <input type="text" class="form-control" id="seller_name" name="seller_name"
                               value="<?php echo htmlspecialchars($trade['counterparty_name']); ?>">
                    </div>
                    <div class="col-md-6">
                        <label for="seller_account" class="form-label fw-semibold text-dark">Counterparty CDS Account</label>
                        <input type="text" class="form-control" id="seller_account" name="seller_account"
                               value="<?php echo htmlspecialchars($trade['counterparty_cds_account']); ?>">
                    </div>
                </div>

                <hr class="my-3">

                <div class="row g-4">
                    <div class="col-md-6">
                        <label for="trade_date" class="form-label fw-semibold text-dark">Trade Date *</label>
                        <input type="date" class="form-control" id="trade_date" name="trade_date"
                               value="<?php echo htmlspecialchars($trade['trade_date']); ?>">
                    </div>
                    <div class="col-md-6">
                        <label for="settlement_date" class="form-label fw-semibold text-dark">Settlement Date *</label>
                        <input type="date" class="form-control" id="settlement_date" name="settlement_date"
                               value="<?php echo htmlspecialchars($trade['settlement_date']); ?>">
                    </div>
                </div>

                <hr class="my-3">

                <div class="row g-4">
                    <div class="col-md-6">
                        <label for="brokerage_fee_type" class="form-label fw-semibold text-dark">Brokerage Fee Option</label>
                        <select class="form-select" id="brokerage_fee_type" name="brokerage_fee_type" onchange="toggleCustomFee(); previewFee()">
                            <option value="normal" <?php echo ($trade['brokerage_fee_type'] == 'normal') ? 'selected' : ''; ?>>
                                Normal Fee (Standard <?php echo number_format($standard_rate_percentage, 5); ?>%)
                            </option>
                            <?php if ($client && $client['fee_type'] == 'liberty' && $client['default_brokerage_fee'] !== null): ?>
                                <option value="liberty" <?php echo ($trade['brokerage_fee_type'] == 'liberty') ? 'selected' : ''; ?>>
                                    Liberty (Client Default) - <?php echo number_format($client['default_brokerage_fee'], 5); ?>%
                                </option>
                            <?php endif; ?>
                            <option value="liberty_new">Set New Liberty Fee (Permanent)</option>
                            <option value="this_trade" <?php echo ($trade['brokerage_fee_type'] == 'this_trade') ? 'selected' : ''; ?>>
                                This Trade Only (Custom)
                            </option>
                        </select>
                    </div>
                    <div class="col-md-6" id="custom-fee-section" style="display: none;">
                        <label for="custom_brokerage_fee" class="form-label fw-semibold text-dark">Custom Rate (%)</label>
                        <input type="number" class="form-control" id="custom_brokerage_fee" name="custom_brokerage_fee"
                               value="<?php echo htmlspecialchars($trade['custom_brokerage_fee']); ?>" step="0.001"
                               placeholder="e.g., 1.25 for 1.25%" oninput="previewFee()">
                        <small class="text-muted" id="custom-fee-hint">This rate applies only to this specific trade.</small>
                    </div>
                </div>

                <div class="row g-4 mt-3" id="liberty-mode-section" style="display: none;">
                    <div class="col-md-12">
                        <label class="form-label fw-semibold text-dark">Liberty Application Mode</label>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <div class="card border shadow-sm mode-card" data-mode="replace_all" onclick="selectMode(this)">
                                    <div class="card-body text-center p-3">
                                        <h6 class="fw-semibold mb-1">Replace All</h6>
                                        <p class="text-muted small mb-0">Full liberty rate on entire amount</p>
                                        <div class="mt-2">
                                            <input type="radio" name="liberty_mode" value="replace_all" 
                                                   <?php echo (($trade['liberty_mode'] ?? 'replace_all') == 'replace_all') ? 'checked' : ''; ?>
                                                   onchange="previewFee()">
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="card border shadow-sm mode-card" data-mode="<?php echo $is_bond ? 'excess_only' : 'tier_override'; ?>" onclick="selectMode(this)">
                                    <div class="card-body text-center p-3">
                                        <h6 class="fw-semibold mb-1"><?php echo $is_bond ? 'Excess Only' : 'Tier Override'; ?></h6>
                                        <p class="text-muted small mb-0"><?php echo $is_bond ? 'Standard on first 100M, liberty on excess' : 'Standard on first 10M, liberty on excess'; ?></p>
                                        <div class="mt-2">
                                            <input type="radio" name="liberty_mode" value="<?php echo $is_bond ? 'excess_only' : 'tier_override'; ?>"
                                                   <?php echo (($trade['liberty_mode'] ?? '') == ($is_bond ? 'excess_only' : 'tier_override')) ? 'checked' : ''; ?>
                                                   onchange="previewFee()">
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="mt-3" id="update-existing-section" style="display: none;">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="update_existing_trades" name="update_existing_trades" value="1">
                        <label class="form-check-label" for="update_existing_trades">
                            Apply to ALL existing trades of this client
                        </label>
                        <?php if ($existing_trades_count > 0): ?>
                            <div class="text-muted small">Will update <strong><?php echo $existing_trades_count; ?></strong> existing trade(s).</div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="mt-3">
                    <div id="normal-info" class="alert alert-success" style="display: <?php echo ($trade['brokerage_fee_type'] == 'normal') ? 'block' : 'none'; ?>;">
                        <strong>Standard Fee Applied</strong><br>
                        <?php echo $fee_calculation_note; ?>
                    </div>
                    <div id="liberty-info" class="alert alert-info" style="display: <?php echo ($trade['brokerage_fee_type'] == 'liberty') ? 'block' : 'none'; ?>;">
                        <strong>Liberty Fee Active</strong><br>
                        Rate: <strong><?php echo number_format($client['default_brokerage_fee'] ?? 0, 5); ?>%</strong>
                    </div>
                    <div id="liberty-new-info" class="alert alert-warning" style="display: none;">
                        <strong>Setting New Permanent Liberty Fee</strong><br>
                        This will update the client's permanent rate for ALL future trades.
                    </div>
                    <div id="this-trade-info" class="alert alert-secondary" style="display: <?php echo ($trade['brokerage_fee_type'] == 'this_trade') ? 'block' : 'none'; ?>;">
                        <strong>One-Time Custom Fee</strong><br>
                        Applies ONLY to this specific trade.
                    </div>
                </div>

                <div id="fee-preview" class="mt-3 p-3 bg-light rounded">
                    <div class="d-flex justify-content-between align-items-center">
                        <span class="fw-semibold">Brokerage Fee:</span>
                        <span class="fs-5 fw-bold text-primary" id="preview-amount">
                            TZS <?php echo number_format($trade['final_brokerage_fee'] ?? 0, 2); ?>
                        </span>
                    </div>
                    <small class="text-muted" id="preview-note"><?php echo $fee_calculation_note; ?></small>
                    <div class="mt-2">
                        <small class="text-muted" id="preview-rate">Rate: <?php echo number_format($effective_rate ?? 0, 4); ?>% - <?php echo ucfirst(str_replace('_', ' ', $effective_mode)); ?></small>
                    </div>
                </div>

                <div class="row mt-4">
                    <div class="col-12">
                        <div class="d-flex justify-content-end gap-3 pt-3 border-top">
                            <a href="trades.php" class="btn btn-outline-secondary btn-lg px-4">
                                <i class="bi bi-x-circle me-2"></i> Cancel
                            </a>
                            <button type="submit" class="btn btn-primary btn-lg px-5">
                                <i class="bi bi-save me-2"></i> Save Changes
                            </button>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Bulk Liberty Update Section -->
    <div class="card mt-4">
        <div class="card-header">
            <h6 class="mb-0 fw-semibold"><i class="bi bi-layers me-2"></i>Bulk Liberty Update</h6>
        </div>
        <div class="card-body">
            <p class="text-muted small">Apply liberty rate to multiple trades at once based on filters below. This is useful when a client has many small trades that need the same liberty rate.</p>
            
            <form method="POST" action="" onsubmit="return confirm('This will update ALL matching trades with the liberty rate. Continue?');">
                <input type="hidden" name="bulk_update_liberty" value="1">
                <input type="hidden" name="bulk_client_cds" value="<?php echo htmlspecialchars($trade['client_cds_account']); ?>">
                
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label fw-semibold">Liberty Rate (%)</label>
                        <input type="number" class="form-control" name="bulk_liberty_rate" step="0.001" 
                               placeholder="e.g., 1.2" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-semibold">Mode</label>
                        <select class="form-select" name="bulk_liberty_mode">
                            <option value="replace_all">Replace All</option>
                            <option value="tier_override">Tier Override</option>
                            <option value="excess_only">Excess Only</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-semibold">Security ID</label>
                        <select class="form-select" name="bulk_security_id">
                            <option value="">All Securities</option>
                            <?php foreach ($client_securities as $sec): ?>
                                <option value="<?php echo htmlspecialchars($sec); ?>"><?php echo htmlspecialchars($sec); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-semibold">Asset Class</label>
                        <select class="form-select" name="bulk_asset_class">
                            <option value="">All Asset Classes</option>
                            <?php foreach ($client_asset_classes as $ac): ?>
                                <option value="<?php echo htmlspecialchars($ac); ?>"><?php echo htmlspecialchars($ac); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="row g-3 mt-2">
                    <div class="col-md-3">
                        <label class="form-label fw-semibold">Trade Side</label>
                        <select class="form-select" name="bulk_trade_side">
                            <option value="">All Sides</option>
                            <option value="buy">Buy</option>
                            <option value="sell">Sell</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-semibold">Date From</label>
                        <input type="date" class="form-control" name="bulk_date_from" 
                               min="<?php echo $date_range['min_date'] ?? ''; ?>"
                               max="<?php echo $date_range['max_date'] ?? ''; ?>"
                               placeholder="Start date">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-semibold">Date To</label>
                        <input type="date" class="form-control" name="bulk_date_to"
                               min="<?php echo $date_range['min_date'] ?? ''; ?>"
                               max="<?php echo $date_range['max_date'] ?? ''; ?>"
                               placeholder="End date">
                    </div>
                    <div class="col-md-3 d-flex align-items-end">
                        <button type="submit" class="btn btn-warning w-100">
                            <i class="bi bi-layers me-1"></i> Apply Liberty to Matching Trades
                        </button>
                    </div>
                </div>

                <div class="mt-3">
                    <div class="alert alert-info small">
                        <i class="bi bi-info-circle me-1"></i>
                        <strong>Client:</strong> <?php echo htmlspecialchars($trade['client_name']); ?> (<?php echo htmlspecialchars($trade['client_cds_account']); ?>) 
                        <br><strong>Available Date Range:</strong> <?php echo $date_range['min_date'] ? date('d/m/Y', strtotime($date_range['min_date'])) : 'N/A'; ?> 
                        to <?php echo $date_range['max_date'] ? date('d/m/Y', strtotime($date_range['max_date'])) : 'N/A'; ?>
                        <br><strong>Securities Available:</strong> <?php echo count($client_securities); ?> securities, 
                        <strong>Asset Classes:</strong> <?php echo count($client_asset_classes); ?>
                    </div>
                </div>
            </form>
            
            <!-- Quick Actions -->
            <div class="mt-3">
                <div class="row g-2">
                    <div class="col-md-3">
                        <button class="btn btn-outline-primary btn-sm w-100" onclick="setBulkFilters('', '', '', '')">
                            <i class="bi bi-arrow-counterclockwise"></i> Clear All Filters
                        </button>
                    </div>
                    <div class="col-md-3">
                        <button class="btn btn-outline-secondary btn-sm w-100" onclick="setBulkFilters('<?php echo htmlspecialchars($trade['security_id']); ?>', '', '', '')">
                            <i class="bi bi-filter"></i> Same Security Only
                        </button>
                    </div>
                    <div class="col-md-3">
                        <button class="btn btn-outline-secondary btn-sm w-100" onclick="setBulkFilters('', '<?php echo htmlspecialchars($trade['asset_class']); ?>', '', '')">
                            <i class="bi bi-filter"></i> Same Asset Class Only
                        </button>
                    </div>
                    <div class="col-md-3">
                        <button class="btn btn-outline-secondary btn-sm w-100" onclick="setBulkFilters('', '', '<?php echo htmlspecialchars($trade['trade_side']); ?>', '')">
                            <i class="bi bi-filter"></i> Same Trade Side Only
                        </button>
                    </div>
                </div>
            </div>
            
            <div class="mt-2">
                <button class="btn btn-outline-danger btn-sm" onclick="setBulkDateRange('<?php echo date('Y-m-d', strtotime($trade['trade_date'])); ?>', '<?php echo date('Y-m-d', strtotime($trade['trade_date'])); ?>')">
                    <i class="bi bi-calendar"></i> Same Day Only (<?php echo date('d/m/Y', strtotime($trade['trade_date'])); ?>)
                </button>
            </div>
        </div>
    </div>
</div>

<script>
// ============ CALCULATION FUNCTIONS FOR PREVIEW ============
function calculateEquityStandardJS(consideration) {
    var rate1 = 1.7, rate2 = 1.5, rate3 = 0.8;
    var limit1 = 10000000, limit2 = 40000000;
    
    if (consideration <= limit1) {
        return consideration * (rate1 / 100);
    } else if (consideration <= limit2) {
        return limit1 * (rate1 / 100) + (consideration - limit1) * (rate2 / 100);
    } else {
        return limit1 * (rate1 / 100) + (limit2 - limit1) * (rate2 / 100) + (consideration - limit2) * (rate3 / 100);
    }
}

function calculateEquityTierOverrideJS(consideration, rate) {
    var standard_rate = 1.7;
    var limit1 = 10000000;
    
    if (consideration <= limit1) {
        return consideration * (standard_rate / 100);
    } else {
        return limit1 * (standard_rate / 100) + (consideration - limit1) * (rate / 100);
    }
}

function calculateEquityReplaceAllJS(consideration, rate) {
    return consideration * (rate / 100);
}

function calculateBondStandardJS(faceValue) {
    var first100m = Math.min(faceValue, 100000000);
    var excess = Math.max(faceValue - 100000000, 0);
    return first100m * (0.063132 / 100) + excess * (0.035 / 100);
}

function calculateBondExcessOnlyJS(faceValue, rate) {
    var first100m = Math.min(faceValue, 100000000);
    var excess = Math.max(faceValue - 100000000, 0);
    return first100m * (0.063132 / 100) + excess * (rate / 100);
}

function calculateBondReplaceAllJS(faceValue, rate) {
    return faceValue * (rate / 100);
}

// ============ PREVIEW FUNCTION ============
function previewFee() {
    var tradeType = document.getElementById('trade_type').value;
    var quantity = parseFloat(document.getElementById('quantity').value) || 0;
    var price = parseFloat(document.getElementById('price').value) || 0;
    var feeType = document.getElementById('brokerage_fee_type').value;
    var isBond = (tradeType === 'bond');
    
    var consideration = 0;
    if (isBond) {
        consideration = quantity * price / 100;
    } else {
        var considerationInput = document.getElementById('consideration');
        if (considerationInput) {
            consideration = parseFloat(considerationInput.value) || 0;
        }
        if (consideration === 0) {
            consideration = quantity * price;
        }
    }
    
    var rate = 0;
    var mode = 'replace_all';
    var rateDisplay = '';
    
    var modeRadios = document.querySelectorAll('input[name="liberty_mode"]');
    modeRadios.forEach(function(radio) {
        if (radio.checked) mode = radio.value;
    });
    
    var standardRate = <?php echo $standard_rate_percentage ?? 0; ?>;
    var clientRate = <?php echo ($client && $client['fee_type'] == 'liberty') ? $client['default_brokerage_fee'] : 0; ?>;
    
    switch(feeType) {
        case 'normal':
            rate = standardRate;
            mode = 'standard';
            rateDisplay = 'Standard';
            break;
        case 'liberty':
            rate = clientRate;
            rateDisplay = 'Liberty (Permanent)';
            break;
        case 'liberty_new':
        case 'this_trade':
            var customRate = parseFloat(document.getElementById('custom_brokerage_fee').value);
            if (!isNaN(customRate) && customRate > 0) {
                rate = customRate;
                rateDisplay = feeType === 'liberty_new' ? 'New Liberty' : 'One-Time Custom';
            }
            break;
    }
    
    var calculatedFee = 0;
    var calculationNote = '';
    
    if (rate > 0 && ((isBond && quantity > 0) || (!isBond && consideration > 0))) {
        if (isBond) {
            if (feeType === 'normal' || mode === 'standard') {
                calculatedFee = calculateBondStandardJS(quantity);
                calculationNote = 'Bond STANDARD: Tiered (First 100M at 0.063132%, excess at 0.035%)';
            } else if (mode === 'replace_all') {
                calculatedFee = calculateBondReplaceAllJS(quantity, rate);
                calculationNote = 'Bond REPLACE ALL: Full liberty rate on entire face value';
            } else {
                calculatedFee = calculateBondExcessOnlyJS(quantity, rate);
                calculationNote = 'Bond EXCESS ONLY: First 100M standard, excess at liberty rate';
            }
        } else {
            if (feeType === 'normal' || mode === 'standard') {
                calculatedFee = calculateEquityStandardJS(consideration);
                calculationNote = 'Equity STANDARD: First 10M at 1.7%, Next 30M at 1.5%, Excess at 0.8%';
            } else if (mode === 'replace_all') {
                calculatedFee = calculateEquityReplaceAllJS(consideration, rate);
                calculationNote = 'Equity REPLACE ALL: Full liberty rate on entire consideration';
            } else {
                calculatedFee = calculateEquityTierOverrideJS(consideration, rate);
                calculationNote = 'Equity TIER OVERRIDE: First 10M at 1.7%, Excess at liberty rate';
            }
        }
    }
    
    var previewAmount = document.getElementById('preview-amount');
    var previewNote = document.getElementById('preview-note');
    var previewRate = document.getElementById('preview-rate');
    
    if (previewAmount) {
        previewAmount.textContent = 'TZS ' + calculatedFee.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
    }
    if (previewNote && calculationNote) {
        previewNote.textContent = calculationNote;
    }
    if (previewRate && rate > 0) {
        var modeLabel = mode;
        if (isBond) {
            modeLabel = mode === 'replace_all' ? 'Replace All' : (mode === 'excess_only' ? 'Excess Only' : 'Standard');
        } else {
            modeLabel = mode === 'replace_all' ? 'Replace All' : (mode === 'tier_override' ? 'Tier Override' : 'Standard');
        }
        previewRate.textContent = 'Rate: ' + rate.toFixed(4) + '% - ' + modeLabel;
    }
}

// ============ UI TOGGLES ============
function toggleCustomFee() {
    var feeType = document.getElementById('brokerage_fee_type').value;
    var customSection = document.getElementById('custom-fee-section');
    var modeSection = document.getElementById('liberty-mode-section');
    var updateSection = document.getElementById('update-existing-section');
    var customHint = document.getElementById('custom-fee-hint');
    
    var infoDivs = {
        normal: document.getElementById('normal-info'),
        liberty: document.getElementById('liberty-info'),
        liberty_new: document.getElementById('liberty-new-info'),
        this_trade: document.getElementById('this-trade-info')
    };
    
    for (var key in infoDivs) {
        if (infoDivs[key]) infoDivs[key].style.display = 'none';
    }
    
    if (infoDivs[feeType]) infoDivs[feeType].style.display = 'block';
    
    if (feeType === 'liberty_new' || feeType === 'this_trade') {
        customSection.style.display = 'block';
        modeSection.style.display = 'block';
        if (feeType === 'liberty_new') {
            customHint.textContent = '⚠️ This rate becomes the default for ALL future trades.';
            updateSection.style.display = 'block';
        } else {
            customHint.textContent = 'This rate applies only to this specific trade.';
            updateSection.style.display = 'none';
        }
    } else {
        customSection.style.display = 'none';
        if (feeType === 'liberty') {
            modeSection.style.display = 'block';
        } else {
            modeSection.style.display = 'none';
        }
        updateSection.style.display = 'none';
    }
}

function selectMode(card) {
    var radio = card.querySelector('input[type="radio"]');
    if (radio) {
        radio.checked = true;
        previewFee();
    }
}

// ============ BULK FILTER HELPERS ============
function setBulkFilters(security, assetClass, tradeSide, rate) {
    var securitySelect = document.querySelector('select[name="bulk_security_id"]');
    var assetSelect = document.querySelector('select[name="bulk_asset_class"]');
    var sideSelect = document.querySelector('select[name="bulk_trade_side"]');
    var rateInput = document.querySelector('input[name="bulk_liberty_rate"]');
    
    if (securitySelect) {
        for (var i = 0; i < securitySelect.options.length; i++) {
            if (securitySelect.options[i].value === security) {
                securitySelect.selectedIndex = i;
                break;
            }
        }
    }
    
    if (assetSelect) {
        for (var i = 0; i < assetSelect.options.length; i++) {
            if (assetSelect.options[i].value === assetClass) {
                assetSelect.selectedIndex = i;
                break;
            }
        }
    }
    
    if (sideSelect) {
        for (var i = 0; i < sideSelect.options.length; i++) {
            if (sideSelect.options[i].value === tradeSide) {
                sideSelect.selectedIndex = i;
                break;
            }
        }
    }
    
    if (rateInput && rate) {
        rateInput.value = rate;
    }
}

function setBulkDateRange(from, to) {
    var fromInput = document.querySelector('input[name="bulk_date_from"]');
    var toInput = document.querySelector('input[name="bulk_date_to"]');
    
    if (fromInput) fromInput.value = from;
    if (toInput) toInput.value = to;
}

// ============ INITIALIZE ============
document.addEventListener('DOMContentLoaded', function() {
    toggleCustomFee();
    previewFee();
    
    var inputs = document.querySelectorAll('#trade_type, #quantity, #price, #brokerage_fee_type, #custom_brokerage_fee, input[name="liberty_mode"]');
    inputs.forEach(function(input) {
        input.addEventListener('change', previewFee);
        input.addEventListener('input', previewFee);
    });
    
    document.querySelectorAll('.mode-card').forEach(function(card) {
        card.addEventListener('click', function() {
            selectMode(this);
        });
    });
});
</script>

<?php include '../includes/footer.php'; ?>