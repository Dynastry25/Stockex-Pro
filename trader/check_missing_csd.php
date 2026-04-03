<?php
// update_csd_reference_complete.php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Set page title for the header
$page_title = "Update CSD References - Non-Compliant Trades";

// Check if required files exist
$required_files = ['../config/config.php', '../auth/auth_middleware.php'];
foreach ($required_files as $file) {
    if (!file_exists($file)) {
        die("Required file not found: {$file}");
    }
}

require_once '../config/config.php';
require_once '../auth/auth_middleware.php';

// Initialize session securely
if (session_status() === PHP_SESSION_NONE) {
    if (!session_start()) {
        die('Failed to start session');
    }
}

require_login();
require_mandate();

$current_user = get_logged_in_user();

// Initialize database connection
try {
    $db = getDBConnection();
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->query("SELECT 1");
} catch (Exception $e) {
    error_log("Database connection failed: " . $e->getMessage());
    die("Database connection failed. Please contact administrator.");
}

// Generate CSRF token
function generateCSRFToken() {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

// Verify CSRF token
function verifyCSRFToken($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

$csrf_token = generateCSRFToken();

// Function to check if trade reference is compliant (only numbers)
function isCompliantTradeReference($reference) {
    // Compliant: only digits (0-9)
    return preg_match('/^[0-9]+$/', $reference);
}

// Get trades with non-compliant trade references (contains letters or starts with T)
function getNonCompliantTrades($db) {
    $sql = "
        SELECT 
            t.id,
            t.trade_reference,
            t.client_cds_account,
            t.client_name,
            t.security_id,
            t.security_name,
            t.asset_class,
            t.trade_side,
            t.quantity,
            t.price,
            t.consideration,
            t.trade_date,
            t.created_at,
            t.settlement_date,
            t.csd_reference
        FROM trades t
        WHERE 
            (t.csd_reference IS NULL OR t.csd_reference = '')
            AND (
                -- Non-compliant: contains letters OR starts with T
                t.trade_reference REGEXP '[A-Za-z]' 
                OR t.trade_reference LIKE 'T%'
            )
        ORDER BY t.trade_date DESC, t.created_at DESC
    ";
    
    $stmt = $db->prepare($sql);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Get all related tables that might contain this trade reference
function getRelatedTables() {
    return [
        'trades' => ['table' => 'trades', 'column' => 'trade_reference', 'has_type' => false, 'description' => 'Main Trade Records'],
        'etf_trades' => ['table' => 'etf_trades', 'column' => 'trade_reference', 'has_type' => false, 'description' => 'ETF Trade Records'],
        'custodians_trades' => ['table' => 'custodians_trades', 'column' => 'trade_reference', 'has_type' => false, 'description' => 'Custodian Trade Records'],
        'regulatory_fee_assignments' => ['table' => 'regulatory_fee_assignments', 'column' => 'trade_reference', 'has_type' => false, 'description' => 'Regulatory Fee Records'],
        'general_ledger_fee' => ['table' => 'general_ledger', 'column' => 'reference_no', 'has_type' => true, 'type' => "reference_type = 'fee'", 'description' => 'Fee Entries'],
        'general_ledger_investment' => ['table' => 'general_ledger', 'column' => 'reference_no', 'has_type' => true, 'type' => "reference_type = 'company_investment'", 'description' => 'Company Investment Records']
    ];
}

// Check if a CSD reference already exists in any table
function checkCSDReferenceExists($db, $csd_reference) {
    $tables = getRelatedTables();
    
    foreach ($tables as $key => $table_info) {
        $sql = "SELECT COUNT(*) as count FROM {$table_info['table']} WHERE {$table_info['column']} = ?";
        
        if ($table_info['has_type']) {
            $sql .= " AND {$table_info['type']}";
        }
        
        $stmt = $db->prepare($sql);
        $stmt->execute([$csd_reference]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $count = $result ? $result['count'] : 0;
        
        if ($count > 0) {
            error_log("CSD reference {$csd_reference} already exists in {$table_info['table']}");
            return [
                'exists' => true,
                'table' => $table_info['description'],
                'table_name' => $table_info['table'],
                'count' => $count
            ];
        }
    }
    
    return ['exists' => false];
}

// Get count of entries across all tables for a trade reference
function getTradeEntriesCount($db, $trade_reference) {
    $total_count = 0;
    $details = [];
    $table_details = [];
    
    $tables = getRelatedTables();
    
    foreach ($tables as $key => $table_info) {
        $sql = "SELECT COUNT(*) as count FROM {$table_info['table']} WHERE {$table_info['column']} = ?";
        
        if ($table_info['has_type']) {
            $sql .= " AND {$table_info['type']}";
        }
        
        $stmt = $db->prepare($sql);
        $stmt->execute([$trade_reference]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $count = $result ? $result['count'] : 0;
        
        if ($count > 0) {
            $details[] = "{$table_info['description']}: {$count} record(s)";
            $table_details[] = [
                'table' => $table_info['description'],
                'count' => $count
            ];
            $total_count += $count;
        }
    }
    
    return [
        'total' => $total_count,
        'details' => $details,
        'table_details' => $table_details
    ];
}

// Update CSD reference in ALL related tables
function updateAllTablesWithCSDReference($db, $old_reference, $new_csd_reference, $updated_by) {
    $results = [];
    $total_updated = 0;
    
    $tables = getRelatedTables();
    
    // Start transaction
    $db->beginTransaction();
    
    try {
        foreach ($tables as $key => $table_info) {
            $sql = "UPDATE {$table_info['table']} 
                    SET {$table_info['column']} = ?, 
                        updated_at = NOW(),
                        updated_by = ?
                    WHERE {$table_info['column']} = ?";
            
            if ($table_info['has_type']) {
                $sql .= " AND {$table_info['type']}";
            }
            
            $stmt = $db->prepare($sql);
            $stmt->execute([$new_csd_reference, $updated_by, $old_reference]);
            
            $affected_rows = $stmt->rowCount();
            
            if ($affected_rows > 0) {
                $results[] = "✓ {$table_info['description']}: {$affected_rows} record(s) updated";
                $total_updated += $affected_rows;
                error_log("Updated {$affected_rows} records in {$table_info['table']} for reference {$old_reference} -> {$new_csd_reference}");
            }
        }
        
        // Update the csd_reference column in trades table
        $stmt = $db->prepare("UPDATE trades SET csd_reference = ?, updated_at = NOW(), updated_by = ? WHERE trade_reference = ?");
        $stmt->execute([$new_csd_reference, $updated_by, $old_reference]);
        $affected = $stmt->rowCount();
        
        if ($affected > 0) {
            $results[] = "✓ CSD Reference field updated in trades table";
        }
        
        // Log the update in an audit table
        try {
            // Check if audit table exists, create if not
            $check_table = $db->query("SHOW TABLES LIKE 'csd_reference_audit'");
            if ($check_table->rowCount() == 0) {
                $create_sql = "CREATE TABLE IF NOT EXISTS csd_reference_audit (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    old_reference VARCHAR(50) NOT NULL,
                    new_reference VARCHAR(50) NOT NULL,
                    updated_by VARCHAR(100) NOT NULL,
                    updated_at DATETIME NOT NULL,
                    affected_records INT DEFAULT 0
                )";
                $db->exec($create_sql);
            }
            
            $audit_sql = "INSERT INTO csd_reference_audit (old_reference, new_reference, updated_by, updated_at, affected_records) 
                          VALUES (?, ?, ?, NOW(), ?)";
            $audit_stmt = $db->prepare($audit_sql);
            $audit_stmt->execute([$old_reference, $new_csd_reference, $updated_by, $total_updated]);
        } catch (Exception $e) {
            error_log("Failed to log to audit table: " . $e->getMessage());
        }
        
        $db->commit();
        
        return [
            'success' => true,
            'total_updated' => $total_updated,
            'details' => $results
        ];
        
    } catch (Exception $e) {
        $db->rollBack();
        error_log("Error updating CSD reference: " . $e->getMessage() . "\n" . $e->getTraceAsString());
        return [
            'success' => false,
            'error' => $e->getMessage()
        ];
    }
}

// Validate CSD reference format (should be numbers only for compliant)
function validateCSDReference($reference) {
    // CSD reference should only contain numbers (compliant format)
    $pattern = '/^[0-9]+$/';
    return preg_match($pattern, $reference);
}

// Generate a compliant CSD reference suggestion based on trade details
function generateCompliantReference($trade) {
    // Generate a unique numeric reference
    // Format: YYYYMMDD + random 6 digits
    $date_part = date('Ymd', strtotime($trade['trade_date']));
    $random_part = str_pad(mt_rand(1, 999999), 6, '0', STR_PAD_LEFT);
    return $date_part . $random_part;
}

// Get non-compliant trades
$trades = getNonCompliantTrades($db);

// Generate suggestions for each trade
$suggestions = [];
foreach ($trades as $trade) {
    $suggestions[$trade['id']] = generateCompliantReference($trade);
}

// Handle linking action
$action = $_POST['action'] ?? '';
$message = '';
$message_type = '';
$update_results = [];
$show_confirm = false;
$confirm_data = [];

if ($action === 'link_csd') {
    // Verify CSRF token
    $csrf_token_post = $_POST['csrf_token'] ?? '';
    if (!verifyCSRFToken($csrf_token_post)) {
        $message = "Security token validation failed. Please refresh the page and try again.";
        $message_type = 'error';
    } else {
        $trade_id = filter_input(INPUT_POST, 'trade_id', FILTER_VALIDATE_INT);
        $csd_reference = trim($_POST['csd_reference'] ?? '');
        
        if (!$trade_id) {
            $message = "Invalid trade ID";
            $message_type = 'error';
        } elseif (empty($csd_reference)) {
            $message = "CSD reference cannot be empty";
            $message_type = 'error';
        } elseif (!validateCSDReference($csd_reference)) {
            $message = "Invalid CSD reference format. CSD reference must contain only numbers (0-9). No letters or special characters allowed.";
            $message_type = 'error';
        } else {
            // First, get the trade details to find the old reference
            $stmt = $db->prepare("SELECT trade_reference, client_name, security_id, asset_class FROM trades WHERE id = ?");
            $stmt->execute([$trade_id]);
            $trade = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($trade) {
                $old_reference = $trade['trade_reference'];
                
                // Check if CSD reference already exists in any table
                $exists_check = checkCSDReferenceExists($db, $csd_reference);
                
                if ($exists_check['exists']) {
                    $message = "Cannot use CSD reference '{$csd_reference}'. It already exists in {$exists_check['description']} ({$exists_check['count']} record(s)).";
                    $message_type = 'error';
                } else {
                    // Get count of entries that will be affected
                    $entries_info = getTradeEntriesCount($db, $old_reference);
                    
                    $confirm = $_POST['confirm'] ?? 'no';
                    if ($confirm === 'yes') {
                        // Proceed with update
                        $update_results = updateAllTablesWithCSDReference(
                            $db, 
                            $old_reference, 
                            $csd_reference, 
                            $current_user['username'] ?? 'system'
                        );
                        
                        if ($update_results['success']) {
                            $message = "Successfully updated non-compliant trade reference from '{$old_reference}' to compliant CSD reference '{$csd_reference}'. ";
                            $message .= "Updated {$update_results['total_updated']} record(s) across multiple tables.";
                            $message_type = 'success';
                            
                            error_log("User {$current_user['username']} updated non-compliant reference {$old_reference} -> {$csd_reference}");
                            
                            // Regenerate CSRF token
                            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                            $csrf_token = $_SESSION['csrf_token'];
                        } else {
                            $message = "Error updating CSD reference: " . $update_results['error'];
                            $message_type = 'error';
                        }
                    } else {
                        // Store confirmation data for display
                        $show_confirm = true;
                        $confirm_data = [
                            'trade_id' => $trade_id,
                            'old_reference' => $old_reference,
                            'new_reference' => $csd_reference,
                            'entries_info' => $entries_info,
                            'client_name' => $trade['client_name'],
                            'security_id' => $trade['security_id'],
                            'asset_class' => $trade['asset_class']
                        ];
                    }
                }
            } else {
                $message = "Trade not found";
                $message_type = 'error';
            }
        }
    }
}

include '../includes/header.php';
?>

<!-- Custom CSS for this page -->
<style>
.csd-update-container {
    padding: 20px 0;
}

.stats-card {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    padding: 1.5rem;
    border-radius: 12px;
    text-align: center;
    margin-bottom: 1rem;
    box-shadow: 0 4px 6px rgba(0,0,0,0.1);
}

.stats-number {
    font-size: 2rem;
    font-weight: bold;
    margin-bottom: 0.5rem;
}

.stats-label {
    font-size: 0.85rem;
    opacity: 0.9;
    margin-bottom: 0;
}

.card-csd {
    border: none;
    box-shadow: 0 0 20px rgba(0,0,0,0.08);
    border-radius: 12px;
    margin-bottom: 1.5rem;
    background: white;
}

.card-csd .card-header {
    background: white;
    border-bottom: 2px solid #f0f0f0;
    padding: 1rem 1.5rem;
    font-weight: 600;
    border-radius: 12px 12px 0 0;
}

.table-csd thead th {
    background: #f8f9fa;
    border-bottom: 2px solid #dee2e6;
    font-weight: 600;
    font-size: 0.85rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.badge-buy {
    background-color: #28a745;
    color: white;
    padding: 4px 8px;
    border-radius: 6px;
    font-size: 0.75rem;
    font-weight: 600;
}

.badge-sell {
    background-color: #dc3545;
    color: white;
    padding: 4px 8px;
    border-radius: 6px;
    font-size: 0.75rem;
    font-weight: 600;
}

.badge-non-compliant {
    background-color: #ffc107;
    color: #856404;
    padding: 4px 8px;
    border-radius: 6px;
    font-size: 0.7rem;
    font-weight: 600;
}

.input-group-sm .form-control {
    height: 35px;
    font-size: 0.875rem;
}

.input-group-sm .btn {
    padding: 0.25rem 0.75rem;
    font-size: 0.875rem;
}

.suggestion-badge {
    cursor: pointer;
    font-size: 0.7rem;
    padding: 2px 6px;
    background: #e9ecef;
    border-radius: 4px;
    margin-left: 5px;
    display: inline-block;
}

.suggestion-badge:hover {
    background: #667eea;
    color: white;
}

@keyframes fadeIn {
    from { opacity: 0; transform: translateY(20px); }
    to { opacity: 1; transform: translateY(0); }
}

.fade-in {
    animation: fadeIn 0.5s ease-out;
}

.confirm-card {
    border-left: 4px solid #ffc107;
    background: #fff9e6;
}

.non-compliant-row {
    background-color: #fff8e7;
}

.table-responsive {
    overflow-x: auto;
}

@media (max-width: 768px) {
    .stats-number {
        font-size: 1.5rem;
    }
    
    .table-csd {
        font-size: 0.85rem;
    }
    
    .input-group-sm .form-control {
        width: 100px !important;
    }
}

.info-box {
    background: #e7f3ff;
    border-left: 4px solid #2196F3;
    padding: 15px;
    border-radius: 8px;
    margin-bottom: 20px;
}

.info-box i {
    color: #2196F3;
    font-size: 1.2rem;
}
</style>

<!-- Main Content -->
<div class="csd-update-container fade-in">
    <!-- Page Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2 class="mb-1">
                <i class="bi bi-exclamation-triangle-fill text-warning"></i> Update Non-Compliant Trade References
            </h2>
            <p class="text-muted mb-0">Fix trade references that contain letters or start with 'T' - Convert to numeric CSD references</p>
        </div>
        <div>
            <button class="btn btn-outline-primary" onclick="window.location.reload()">
                <i class="bi bi-arrow-repeat"></i> Refresh
            </button>
        </div>
    </div>

    <!-- Info Box -->
    <div class="info-box">
        <i class="bi bi-info-circle-fill me-2"></i>
        <strong>What are non-compliant trade references?</strong>
        <p class="mb-0 mt-2">
            Trade references should only contain <strong>numbers (0-9)</strong>. References that contain letters or start with 'T' are considered 
            <strong class="text-warning">non-compliant</strong> and need to be updated with proper CSD references (numeric only).
        </p>
        <hr class="my-2">
        <div class="small">
            <strong>Examples:</strong><br>
            ✓ Compliant: <code class="text-success">008039547</code>, <code class="text-success">008048965</code><br>
            ✗ Non-Compliant: <code class="text-danger">TYRNWQ</code>, <code class="text-danger">TROAKM</code>, <code class="text-danger">T0JROC</code>
        </div>
    </div>

    <!-- Statistics Cards -->
    <div class="row mb-4">
        <div class="col-md-4">
            <div class="stats-card">
                <div class="stats-number"><?php echo count($trades); ?></div>
                <div class="stats-label">Non-Compliant Trades</div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="stats-card" style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);">
                <div class="stats-number"><?php 
                    $total_entries = 0;
                    foreach ($trades as $trade) {
                        $entries_info = getTradeEntriesCount($db, $trade['trade_reference']);
                        $total_entries += $entries_info['total'];
                    }
                    echo $total_entries;
                ?></div>
                <div class="stats-label">Total Records to Update</div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="stats-card" style="background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);">
                <div class="stats-number"><?php echo count(getRelatedTables()); ?></div>
                <div class="stats-label">Tables Affected</div>
            </div>
        </div>
    </div>

    <!-- Alert Messages -->
    <?php if ($message): ?>
        <div class="alert alert-<?php 
            echo $message_type === 'success' ? 'success' : 
                ($message_type === 'warning' ? 'warning' : 'danger'); 
        ?> alert-dismissible fade show shadow-sm" role="alert">
            <i class="bi bi-<?php echo $message_type === 'success' ? 'check-circle' : ($message_type === 'warning' ? 'exclamation-triangle' : 'x-circle'); ?> me-2"></i>
            <?php echo htmlspecialchars($message); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <!-- Confirmation Card -->
    <?php if ($show_confirm): ?>
        <div class="card confirm-card mb-4">
            <div class="card-body">
                <h5 class="card-title text-warning mb-3">
                    <i class="bi bi-exclamation-triangle-fill"></i> Confirm Update - Multiple Tables
                </h5>
                <div class="row mb-3">
                    <div class="col-md-6">
                        <div class="p-3 bg-light rounded">
                            <strong>Non-Compliant Reference:</strong><br>
                            <code class="h5 text-danger"><?php echo htmlspecialchars($confirm_data['old_reference']); ?></code>
                            <br><br>
                            <strong>New Compliant CSD Reference:</strong><br>
                            <code class="h5 text-success"><?php echo htmlspecialchars($confirm_data['new_reference']); ?></code>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="p-3 bg-light rounded">
                            <strong>Trade Details:</strong><br>
                            Client: <?php echo htmlspecialchars($confirm_data['client_name']); ?><br>
                            Security: <?php echo htmlspecialchars($confirm_data['security_id']); ?><br>
                            Asset Class: <?php echo htmlspecialchars($confirm_data['asset_class']); ?>
                        </div>
                    </div>
                </div>
                
                <div class="alert alert-info">
                    <i class="bi bi-info-circle-fill me-2"></i>
                    <strong>This will update records in the following tables:</strong>
                    <ul class="mb-0 mt-2">
                        <?php foreach ($confirm_data['entries_info']['details'] as $detail): ?>
                            <li><?php echo htmlspecialchars($detail); ?></li>
                        <?php endforeach; ?>
                    </ul>
                    <hr>
                    <p class="mb-0 fw-bold">
                        <i class="bi bi-database"></i> Total records to update: <?php echo $confirm_data['entries_info']['total']; ?>
                    </p>
                </div>
                
                <form method="POST" class="d-inline">
                    <input type="hidden" name="action" value="link_csd">
                    <input type="hidden" name="trade_id" value="<?php echo $confirm_data['trade_id']; ?>">
                    <input type="hidden" name="csd_reference" value="<?php echo htmlspecialchars($confirm_data['new_reference']); ?>">
                    <input type="hidden" name="confirm" value="yes">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    <button type="submit" class="btn btn-warning">
                        <i class="bi bi-check-circle-fill"></i> Yes, Update to Compliant Reference
                    </button>
                    <a href="?cancel=1" class="btn btn-secondary ms-2">
                        <i class="bi bi-x-circle"></i> Cancel
                    </a>
                </form>
            </div>
        </div>
    <?php endif; ?>

    <!-- Update Results -->
    <?php if (!empty($update_results) && $update_results['success']): ?>
        <div class="alert alert-success shadow-sm mb-4">
            <i class="bi bi-check-circle-fill me-2"></i>
            <strong>Update Results:</strong>
            <ul class="mb-0 mt-2">
                <?php foreach ($update_results['details'] as $detail): ?>
                    <li><?php echo htmlspecialchars($detail); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <!-- Non-Compliant Trades Table -->
    <div class="card card-csd">
        <div class="card-header d-flex justify-content-between align-items-center">
            <div>
                <i class="bi bi-exclamation-triangle-fill text-warning me-2"></i>
                <strong>Non-Compliant Trades (Need CSD Reference)</strong>
            </div>
            <span class="badge bg-warning rounded-pill"><?php echo count($trades); ?> non-compliant trades</span>
        </div>
        
        <div class="card-body p-0">
            <?php if (empty($trades)): ?>
                <div class="text-center py-5">
                    <i class="bi bi-check-circle-fill text-success" style="font-size: 4rem;"></i>
                    <h4 class="mt-3">All Trade References are Compliant!</h4>
                    <p class="text-muted">No non-compliant trade references found. All trade references contain only numbers.</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover table-csd mb-0">
                        <thead>
                            <tr>
                                <th width="12%">Current Reference</th>
                                <th width="15%">Client</th>
                                <th width="15%">Security</th>
                                <th width="15%">Asset Class</th>
                                <th width="10%">Trade Details</th>
                                <th width="13%">Trade Date</th>
                                <th width="20%">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($trades as $trade): 
                                $entries_info = getTradeEntriesCount($db, $trade['trade_reference']);
                                $suggestion = $suggestions[$trade['id']] ?? '';
                                $is_compliant = isCompliantTradeReference($trade['trade_reference']);
                            ?>
                                <tr class="<?php echo !$is_compliant ? 'non-compliant-row' : ''; ?>">
                                    <td>
                                        <strong class="text-danger"><?php echo htmlspecialchars($trade['trade_reference']); ?></strong>
                                        <br>
                                        <span class="badge badge-non-compliant mt-1">
                                            <i class="bi bi-exclamation-circle"></i> Non-Compliant
                                        </span>
                                        <br>
                                        <small class="text-muted">ID: <?php echo $trade['id']; ?></small>
                                    </td>
                                    <td>
                                        <div><?php echo htmlspecialchars($trade['client_name']); ?></div>
                                        <small class="text-muted"><?php echo htmlspecialchars($trade['client_cds_account']); ?></small>
                                    </td>
                                    <td>
                                        <div><strong><?php echo htmlspecialchars($trade['security_id']); ?></strong></div>
                                        <small><?php echo htmlspecialchars(substr($trade['security_name'], 0, 30)); ?></small>
                                    </td>
                                    <td>
                                        <span class="badge bg-info"><?php echo htmlspecialchars($trade['asset_class']); ?></span>
                                    </td>
                                    <td>
                                        <div>
                                            <span class="badge <?php echo $trade['trade_side'] === 'buy' ? 'badge-buy' : 'badge-sell'; ?>">
                                                <?php echo strtoupper($trade['trade_side']); ?>
                                            </span>
                                        </div>
                                        <div class="small mt-1">
                                            Qty: <?php echo number_format($trade['quantity']); ?><br>
                                            Value: Tsh <?php echo number_format($trade['consideration'], 2); ?>
                                        </div>
                                        <?php if ($entries_info['total'] > 0): ?>
                                            <span class="badge bg-info text-dark mt-1" title="Records across all tables">
                                                <i class="bi bi-files"></i> <?php echo $entries_info['total']; ?> entries
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php echo htmlspecialchars($trade['trade_date']); ?><br>
                                        <small class="text-muted">Settlement: <?php echo htmlspecialchars($trade['settlement_date']); ?></small>
                                    </td>
                                    <td>
                                        <form method="POST" style="display: inline;" onsubmit="return validateForm(this, <?php echo $entries_info['total']; ?>, '<?php echo htmlspecialchars($trade['trade_reference']); ?>')">
                                            <input type="hidden" name="action" value="link_csd">
                                            <input type="hidden" name="trade_id" value="<?php echo $trade['id']; ?>">
                                            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                            <div class="input-group input-group-sm">
                                                <input type="text" 
                                                       name="csd_reference" 
                                                       class="form-control form-control-sm" 
                                                       placeholder="Enter numeric CSD ref"
                                                       style="width: 130px;"
                                                       pattern="[0-9]+"
                                                       title="Only numbers (0-9) allowed"
                                                       value="<?php echo htmlspecialchars($suggestion); ?>"
                                                       required>
                                                <button type="submit" class="btn btn-primary btn-sm">
                                                    <i class="bi bi-check-lg"></i> Fix Reference
                                                </button>
                                            </div>
                                            <?php if ($suggestion): ?>
                                                <small class="text-muted d-block mt-1">
                                                    <span class="suggestion-badge" onclick="this.parentElement.parentElement.querySelector('input[name=\'csd_reference\']').value = '<?php echo $suggestion; ?>'">
                                                        💡 Suggest: <?php echo $suggestion; ?>
                                                    </span>
                                                </small>
                                            <?php endif; ?>
                                        </form>
                                        <small class="text-muted d-block mt-2">
                                            <i class="bi bi-info-circle"></i> Must be numbers only
                                        </small>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Information Card -->
    <div class="card card-csd mt-3">
        <div class="card-body">
            <h6 class="card-title">
                <i class="bi bi-question-circle-fill text-info"></i> About Non-Compliant Trade References
            </h6>
            <p class="card-text">
                <strong>What makes a trade reference non-compliant?</strong>
            </p>
            <ul>
                <li><strong class="text-danger">Contains letters</strong> (A-Z, a-z) - Example: <code>TYRNWQ</code></li>
                <li><strong class="text-danger">Starts with 'T'</strong> - Example: <code>TROAKM</code>, <code>T48EG6</code></li>
                <li><strong class="text-danger">Contains a mix of letters and numbers</strong> - Example: <code>T0JROC</code></li>
            </ul>
            <p class="card-text">
                <strong>What is a compliant CSD reference?</strong>
            </p>
            <ul>
                <li><strong class="text-success">Only numbers (0-9)</strong> - Example: <code>008039547</code></li>
                <li>Should be unique across all trades</li>
                <li>Typically follows a numeric format (YYYYMMDD + random digits)</li>
            </ul>
            <hr>
            <p class="mb-0 text-warning small">
                <i class="bi bi-exclamation-triangle"></i>
                <strong>Important:</strong> This tool updates ALL related records (ETF trades, custodian trades, regulatory fees, general ledger entries) with the new compliant CSD reference. The action cannot be undone once confirmed.
            </p>
        </div>
    </div>
</div>

<script>
function validateForm(form, entriesCount, oldReference) {
    const csdRef = form.querySelector('input[name="csd_reference"]').value;
    
    if (!csdRef) {
        alert('Please enter a CSD reference number');
        return false;
    }
    
    // Validate only numbers
    const numbersOnlyPattern = /^[0-9]+$/;
    if (!numbersOnlyPattern.test(csdRef)) {
        alert('Invalid CSD reference format.\n\nCSD reference must contain ONLY numbers (0-9).\nNo letters, hyphens, or special characters allowed.\n\nExample: 20260319123456');
        return false;
    }
    
    return confirm(`IMPORTANT: Fixing Non-Compliant Trade Reference\n\n` +
                   `Current (Non-Compliant): ${oldReference}\n` +
                   `New (Compliant): ${csdRef}\n\n` +
                   `This will update ${entriesCount} record(s) across all related tables.\n\n` +
                   `This action cannot be undone. Are you sure?`);
}

// Auto-refresh after successful linking
document.addEventListener('DOMContentLoaded', function() {
    const successMessage = document.querySelector('.alert-success');
    if (successMessage && successMessage.innerText.includes('Successfully updated')) {
        setTimeout(function() {
            window.location.href = window.location.pathname;
        }, 3000);
    }
    
    // Format CSD reference input - only numbers allowed
    document.querySelectorAll('input[name="csd_reference"]').forEach(input => {
        input.addEventListener('input', function() {
            // Remove any non-numeric characters
            this.value = this.value.replace(/[^0-9]/g, '');
        });
        
        // Add Enter key support
        input.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                this.closest('form').querySelector('button[type="submit"]').click();
            }
        });
    });
    
    // Add suggestion badge click handlers
    document.querySelectorAll('.suggestion-badge').forEach(badge => {
        badge.style.cursor = 'pointer';
        badge.addEventListener('click', function() {
            const input = this.parentElement.parentElement.querySelector('input[name="csd_reference"]');
            const suggestion = this.textContent.replace('💡 Suggest: ', '');
            input.value = suggestion;
            input.focus();
        });
    });
});

// Add keyboard navigation
document.addEventListener('keydown', function(e) {
    // Ctrl + R to refresh
    if (e.ctrlKey && e.key === 'r') {
        e.preventDefault();
        window.location.reload();
    }
});
</script>

<?php include '../includes/footer.php'; ?>