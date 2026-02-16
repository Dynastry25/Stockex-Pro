<?php
// update_csd_reference_complete.php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Check if required files exist
if (!file_exists('../config/config.php')) {
    die('config.php not found');
}
if (!file_exists('../auth/auth_middleware.php')) {
    die('auth_middleware.php not found');
}

require_once '../config/config.php';
require_once '../auth/auth_middleware.php';

// Check if session is started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_login();
require_mandate();

$current_user = get_logged_in_user();

try {
    $db = getDBConnection();
    // Test the connection
    $db->query("SELECT 1");
} catch (Exception $e) {
    die("Database connection failed: " . $e->getMessage());
}

// Get simple list of trades with generated references (T-6 digits) without CSD references
function getMissingCSDTradesSimple($db) {
    // Get trades that have generated references (starting with T and 6+ digits) and no CSD reference
    $sql = "
        SELECT 
            t.id,
            t.trade_reference,
            t.client_cds_account,
            t.client_name,
            t.security_id,
            t.security_name,
            t.trade_side,
            t.quantity,
            t.price,
            t.trade_date,
            t.created_at
        FROM trades t
        WHERE 
            (t.csd_reference IS NULL OR t.csd_reference = '')
            AND t.trade_reference REGEXP '^T[0-9]{6,}$'
        ORDER BY t.trade_date DESC, t.created_at DESC
    ";
    
    $stmt = $db->prepare($sql);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// NEW: Get all related tables that might contain this trade reference
function getRelatedTables() {
    return [
        'trades' => ['table' => 'trades', 'column' => 'trade_reference'],
        'etf_trades' => ['table' => 'etf_trades', 'column' => 'trade_reference'],
        'custodians_trades' => ['table' => 'custodians_trades', 'column' => 'trade_reference'],
        'regulatory_fee_assignments' => ['table' => 'regulatory_fee_assignments', 'column' => 'trade_reference'],
        'general_ledger' => ['table' => 'general_ledger', 'column' => 'reference_no'],
        'company_investments' => ['table' => 'general_ledger', 'column' => 'reference_no', 'type' => 'reference_type = \'company_investment\''],
        'fee_entries' => ['table' => 'general_ledger', 'column' => 'reference_no', 'type' => 'reference_type = \'fee\'']
    ];
}

// NEW: Check if a CSD reference already exists in any table
function checkCSDReferenceExists($db, $csd_reference) {
    $tables = getRelatedTables();
    
    foreach ($tables as $key => $table_info) {
        $sql = "SELECT COUNT(*) as count FROM {$table_info['table']} WHERE {$table_info['column']} = ?";
        
        // Add type filter for general_ledger if specified
        if (isset($table_info['type'])) {
            $sql .= " AND {$table_info['type']}";
        }
        
        $stmt = $db->prepare($sql);
        $stmt->execute([$csd_reference]);
        $count = $stmt->fetch()['count'];
        
        if ($count > 0) {
            error_log("CSD reference {$csd_reference} already exists in {$table_info['table']}");
            return [
                'exists' => true,
                'table' => $table_info['table'],
                'count' => $count
            ];
        }
    }
    
    return ['exists' => false];
}

// NEW: Get count of entries across all tables for a trade reference
function getTradeEntriesCount($db, $trade_reference) {
    $total_count = 0;
    $details = [];
    
    $tables = getRelatedTables();
    
    foreach ($tables as $key => $table_info) {
        $sql = "SELECT COUNT(*) as count FROM {$table_info['table']} WHERE {$table_info['column']} = ?";
        
        // Add type filter for general_ledger if specified
        if (isset($table_info['type'])) {
            $sql .= " AND {$table_info['type']}";
        }
        
        $stmt = $db->prepare($sql);
        $stmt->execute([$trade_reference]);
        $count = $stmt->fetch()['count'];
        
        if ($count > 0) {
            $details[] = "{$table_info['table']}: {$count}";
            $total_count += $count;
        }
    }
    
    return [
        'total' => $total_count,
        'details' => $details
    ];
}

// NEW: Update CSD reference in ALL related tables
function updateAllTablesWithCSDReference($db, $old_reference, $new_csd_reference, $updated_by) {
    $results = [];
    $success = true;
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
            
            // Add type filter for general_ledger if specified
            if (isset($table_info['type'])) {
                $sql .= " AND {$table_info['type']}";
            }
            
            $stmt = $db->prepare($sql);
            $stmt->execute([$new_csd_reference, $updated_by, $old_reference]);
            
            $affected_rows = $stmt->rowCount();
            
            if ($affected_rows > 0) {
                $results[] = "Updated {$affected_rows} record(s) in {$table_info['table']}";
                $total_updated += $affected_rows;
                error_log("Updated {$affected_rows} records in {$table_info['table']} for reference {$old_reference} -> {$new_csd_reference}");
            }
        }
        
        // Also update the csd_reference column in trades table if it exists
        $stmt = $db->prepare("UPDATE trades SET csd_reference = ?, updated_at = NOW() WHERE trade_reference = ?");
        $stmt->execute([$new_csd_reference, $new_csd_reference]); // Note: Using new reference as the key
        $affected = $stmt->rowCount();
        if ($affected > 0) {
            $results[] = "Updated csd_reference field in trades table";
        }
        
        $db->commit();
        
        return [
            'success' => true,
            'total_updated' => $total_updated,
            'details' => $results
        ];
        
    } catch (Exception $e) {
        $db->rollBack();
        error_log("Error updating CSD reference: " . $e->getMessage());
        return [
            'success' => false,
            'error' => $e->getMessage()
        ];
    }
}

// Handle linking action
$action = $_POST['action'] ?? '';
$message = '';
$message_type = '';
$update_results = [];

if ($action === 'link_csd') {
    $trade_id = $_POST['trade_id'] ?? 0;
    $csd_reference = trim($_POST['csd_reference'] ?? '');
    $confirm = $_POST['confirm'] ?? 'no';
    
    // First, get the trade details to find the old reference
    $stmt = $db->prepare("SELECT trade_reference FROM trades WHERE id = ?");
    $stmt->execute([$trade_id]);
    $trade = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($trade && $csd_reference) {
        $old_reference = $trade['trade_reference'];
        
        // Check if CSD reference already exists in any table
        $exists_check = checkCSDReferenceExists($db, $csd_reference);
        
        if ($exists_check['exists']) {
            $message = "Cannot use CSD reference '{$csd_reference}'. It already exists in {$exists_check['table']} ({$exists_check['count']} record(s)).";
            $message_type = 'error';
        } else {
            // Get count of entries that will be affected
            $entries_info = getTradeEntriesCount($db, $old_reference);
            
            if ($confirm === 'yes') {
                // Proceed with update
                $update_results = updateAllTablesWithCSDReference(
                    $db, 
                    $old_reference, 
                    $csd_reference, 
                    $current_user['username'] ?? 'system'
                );
                
                if ($update_results['success']) {
                    $message = "Successfully updated CSD reference from '{$old_reference}' to '{$csd_reference}'. ";
                    $message .= "Updated {$update_results['total_updated']} record(s) across multiple tables.";
                    $message_type = 'success';
                    
                    // Log the action
                    error_log("User {$current_user['username']} updated reference {$old_reference} -> {$csd_reference} across all tables");
                } else {
                    $message = "Error updating CSD reference: " . $update_results['error'];
                    $message_type = 'error';
                }
            } else {
                // Show confirmation with details
                $message = "This will update the trade reference from <strong>{$old_reference}</strong> to <strong>{$csd_reference}</strong> in ALL related tables. ";
                $message .= "Total records to be updated: <strong>{$entries_info['total']}</strong>.<br>";
                $message .= "Details: " . implode(', ', $entries_info['details']);
                $message_type = 'warning';
            }
        }
    }
}

// Get the trades
$trades = getMissingCSDTradesSimple($db);

include '../includes/header.php';
?>

<div class="container-fluid">
    <div class="page-header">
        <div class="container">
            <h1 class="page-title">Update CSD References - Complete System</h1>
            <p class="page-subtitle">Update trade references across ALL related tables with CSD references</p>
        </div>
    </div>

    <div class="container">
        <?php if ($message): ?>
            <div class="alert alert-<?php 
                echo $message_type === 'success' ? 'success' : 
                    ($message_type === 'warning' ? 'warning' : 'danger'); 
            ?> alert-dismissible fade show">
                <?php echo $message_type === 'warning' ? $message : htmlspecialchars($message); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if (!empty($update_results) && $update_results['success']): ?>
            <div class="alert alert-info">
                <strong>Update Results:</strong>
                <ul class="mb-0 mt-2">
                    <?php foreach ($update_results['details'] as $detail): ?>
                        <li><?php echo htmlspecialchars($detail); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0">Trades with Generated References (No CSD)</h5>
                <span class="badge bg-primary"><?php echo count($trades); ?> trades</span>
            </div>
            
            <div class="card-body p-0">
                <?php if (empty($trades)): ?>
                    <div class="text-center py-5">
                        <i class="bi bi-check-circle text-success" style="font-size: 3rem;"></i>
                        <h4 class="mt-3">All good!</h4>
                        <p class="text-muted">No generated trades without CSD references found.</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Trade Ref</th>
                                    <th>Client</th>
                                    <th>Security</th>
                                    <th>Details</th>
                                    <th>Date</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($trades as $trade): 
                                    $entries_info = getTradeEntriesCount($db, $trade['trade_reference']);
                                ?>
                                <tr>
                                    <td>
                                        <strong><?php echo htmlspecialchars($trade['trade_reference']); ?></strong>
                                        <br>
                                        <small class="text-muted">ID: <?php echo $trade['id']; ?></small>
                                    </td>
                                    <td>
                                        <div><?php echo htmlspecialchars($trade['client_name']); ?></div>
                                        <small class="text-muted"><?php echo htmlspecialchars($trade['client_cds_account']); ?></small>
                                    </td>
                                    <td>
                                        <div><strong><?php echo htmlspecialchars($trade['security_id']); ?></strong></div>
                                        <small><?php echo htmlspecialchars($trade['security_name']); ?></small>
                                    </td>
                                    <td>
                                        <div class="small">
                                            <span class="badge <?php echo $trade['trade_side'] === 'buy' ? 'bg-success' : 'bg-danger'; ?>">
                                                <?php echo strtoupper($trade['trade_side']); ?>
                                            </span>
                                            <br>
                                            Qty: <?php echo number_format($trade['quantity']); ?><br>
                                            Price: Tsh <?php echo number_format($trade['price'], 2); ?>
                                            <br>
                                          
                                        </div>
                                    </td>
                                    <td>
                                        <?php echo htmlspecialchars($trade['trade_date']); ?><br>
                                        <small class="text-muted">Created: <?php echo htmlspecialchars($trade['created_at']); ?></small>
                                    </td>
                                    <td>
                                        <button type="button" class="btn btn-sm btn-primary" 
                                                data-bs-toggle="modal" 
                                                data-bs-target="#linkModal<?php echo $trade['id']; ?>">
                                            <i class="bi bi-link"></i> Add CSD
                                        </button>
                                    </td>
                                </tr>
                                
                                <!-- Link Modal -->
                                <div class="modal fade" id="linkModal<?php echo $trade['id']; ?>" tabindex="-1">
                                    <div class="modal-dialog">
                                        <div class="modal-content">
                                            <div class="modal-header">
                                                <h6 class="modal-title">Add CSD Reference - Complete Update</h6>
                                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                            </div>
                                            <div class="modal-body">
                                                <div class="alert alert-warning small">
                                                    <i class="bi bi-exclamation-triangle"></i>
                                                    <strong>Important:</strong> This will update the trade reference from 
                                                    <strong><?php echo htmlspecialchars($trade['trade_reference']); ?></strong> 
                                                    to the CSD reference in ALL related tables:
                                                    <ul class="mb-0 mt-2">
                                                        <li>trades table</li>
                                                        <li>etf_trades (if exists)</li>
                                                        <li>custodians_trades</li>
                                                        <li>regulatory_fee_assignments</li>
                                                        <li>general_ledger (all related entries)</li>
                                                    </ul>
                                                    <p class="mt-2 mb-0">
                                                        Total records to update: <strong><?php echo $entries_info['total']; ?></strong>
                                                    </p>
                                                </div>
                                                
                                                <form method="POST" id="linkForm<?php echo $trade['id']; ?>">
                                                    <input type="hidden" name="action" value="link_csd">
                                                    <input type="hidden" name="trade_id" value="<?php echo $trade['id']; ?>">
                                                    <input type="hidden" name="confirm" value="yes" id="confirm<?php echo $trade['id']; ?>">
                                                    
                                                    <div class="mb-3">
                                                        <label class="form-label fw-bold">Current Trade Reference:</label>
                                                        <input type="text" class="form-control bg-light" 
                                                               value="<?php echo htmlspecialchars($trade['trade_reference']); ?>" readonly>
                                                    </div>
                                                    
                                                    <div class="mb-3">
                                                        <label class="form-label fw-bold">New CSD Reference:</label>
                                                        <input type="text" class="form-control" name="csd_reference" required
                                                               placeholder="Enter CSD reference"
                                                               pattern="[A-Za-z0-9-]+" title="Alphanumeric and hyphens only">
                                                        <small class="text-muted">This will become the new trade reference in ALL tables</small>
                                                    </div>
                                                </form>
                                            </div>
                                            <div class="modal-footer">
                                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                <button type="button" class="btn btn-warning" 
                                                        onclick="showConfirmation(<?php echo $trade['id']; ?>)">
                                                    <i class="bi bi-shield-check"></i> Update All Entries
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Summary -->
        <div class="card mt-3">
            <div class="card-body">
                <h6>About Complete CSD Reference Update</h6>
                <p>
                    This tool updates <strong>ALL related records</strong> when adding a CSD reference:
                </p>
                <ul>
                    <li><strong>trades</strong> - Main trade record</li>
                    <li><strong>etf_trades</strong> - If this was an ETF trade</li>
                    <li><strong>custodians_trades</strong> - If processed through custodian</li>
                    <li><strong>regulatory_fee_assignments</strong> - Regulatory fee records</li>
                    <li><strong>general_ledger</strong> - All accounting entries (fee entries, company investments)</li>
                </ul>
                <p class="mb-0 text-warning">
                    <i class="bi bi-exclamation-triangle"></i>
                    This is a complete update that ensures all related records use the same CSD reference.
                </p>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Auto-refresh after successful linking (if not in warning mode)
    const successMessage = document.querySelector('.alert-success');
    if (successMessage) {
        setTimeout(function() {
            window.location.reload();
        }, 2000);
    }
});

function showConfirmation(tradeId) {
    const form = document.getElementById('linkForm' + tradeId);
    const csdRef = form.querySelector('input[name="csd_reference"]').value;
    
    if (!csdRef) {
        alert('Please enter a CSD reference number');
        return;
    }
    
    if (confirm(`IMPORTANT: This will update ALL related records with the new CSD reference "${csdRef}".\n\nThis action cannot be undone. Are you sure?`)) {
        form.submit();
    }
}

// Validate CSD reference format
document.querySelectorAll('input[name="csd_reference"]').forEach(input => {
    input.addEventListener('input', function() {
        this.value = this.value.toUpperCase().replace(/[^A-Z0-9-]/g, '');
    });
});
</script>

<?php include '../includes/footer.php'; ?>