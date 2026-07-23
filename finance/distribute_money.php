<?php
require_once '../config/config.php';
require_once '../auth/auth_middleware.php';

// Security headers
header("X-Frame-Options: DENY");
header("X-Content-Type-Options: nosniff");
header("X-XSS-Protection: 1; mode=block");
header("Referrer-Policy: strict-origin-when-cross-origin");
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; font-src 'self' https://cdn.jsdelivr.net");

require_finance_officer();

// CSRF Protection
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$db = getDBConnection();
$success_message = '';
$error_message = '';

// Helper functions
function sanitizeInput($data) {
    if (is_array($data)) {
        return array_map('sanitizeInput', $data);
    }
    return htmlspecialchars(trim($data), ENT_QUOTES, 'UTF-8');
}

function validateAmount($amount) {
    return is_numeric($amount) && $amount > 0 && $amount <= 999999999.99;
}

function formatCurrency($amount, $currency = 'Tsh') {
    // Ensure amount is not null and is numeric
    if ($amount === null || !is_numeric($amount)) {
        $amount = 0;
    }
    return number_format((float)$amount, 2) . ' ' . $currency;
}

function getCurrencySymbol($currency) {
    switch($currency) {
        case 'USD': return '$';
        case 'Ksh': return 'KSh';
        case 'UGsh': return 'UGX';
        case 'Tsh': return 'TSh';
        default: return 'TSh';
    }
}

// Handle form submission for new distribution
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Verify CSRF token
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $error_message = "CSRF token validation failed. Please try again.";
    } else {
        $action = $_POST['action'] ?? '';
        $current_user = $_SESSION['username'] ?? 'system';
        $user_id = $_SESSION['user_id'] ?? null;
        
        if ($action === 'add_distribution') {
            // Add new distribution
            $receipt_id = (int)($_POST['receipt_id'] ?? 0);
            $amount = (float)($_POST['amount'] ?? 0);
            $description = sanitizeInput($_POST['description'] ?? '');
            $distribution_type = sanitizeInput($_POST['distribution_type'] ?? 'trade');
            
            if ($receipt_id <= 0) {
                $error_message = "Invalid receipt selected.";
            } elseif (!validateAmount($amount)) {
                $error_message = "Invalid amount. Amount must be greater than 0 and less than 999,999,999.99";
            } elseif (empty($description)) {
                $error_message = "Please enter a description for the distribution.";
            } else {
                try {
                    // Start transaction
                    $db->beginTransaction();
                    
                    // Get receipt details
                    $stmt = $db->prepare("
                        SELECT r.* 
                        FROM receipts r 
                        WHERE r.id = ? AND r.money_distribution = 'yes'
                    ");
                    $stmt->execute([$receipt_id]);
                    $receipt = $stmt->fetch();
                    
                    if (!$receipt) {
                        $error_message = "Receipt not found or not marked for distribution.";
                        $db->rollBack();
                    } else {
                        // Check if distribution record exists
                        $dist_stmt = $db->prepare("
                            SELECT * FROM receipt_distributions 
                            WHERE receipt_id = ?
                        ");
                        $dist_stmt->execute([$receipt_id]);
                        $distribution = $dist_stmt->fetch();
                        
                        if (!$distribution) {
                            // Create new distribution record
                            $new_remaining_balance = $receipt['amount'] - $amount;
                            
                            $insert_stmt = $db->prepare("
                                INSERT INTO receipt_distributions (
                                    receipt_id, receipt_no, total_amount, 
                                    distributed_amount, remaining_balance, 
                                    currency, status, created_at
                                ) VALUES (?, ?, ?, ?, ?, ?, 'pending', NOW())
                            ");
                            
                            $insert_stmt->execute([
                                $receipt_id,
                                $receipt['receipt_no'],
                                $receipt['amount'],
                                $amount,
                                $new_remaining_balance,
                                $receipt['currency']
                            ]);
                            
                            $distribution_id = $db->lastInsertId();
                        } else {
                            // Update existing distribution record
                            $distribution_id = $distribution['id'];
                            $new_distributed = ($distribution['distributed_amount'] ?? 0) + $amount;
                            $new_remaining = ($distribution['remaining_balance'] ?? $receipt['amount']) - $amount;
                            
                            $update_stmt = $db->prepare("
                                UPDATE receipt_distributions 
                                SET distributed_amount = ?, 
                                    remaining_balance = ?,
                                    last_distribution_date = NOW(),
                                    status = CASE WHEN ? >= total_amount THEN 'completed' ELSE 'pending' END,
                                    updated_at = NOW()
                                WHERE id = ?
                            ");
                            
                            $update_stmt->execute([
                                $new_distributed,
                                $new_remaining,
                                $new_distributed,
                                $distribution_id
                            ]);
                        }
                        
                        // Add distribution activity
                        $activity_stmt = $db->prepare("
                            INSERT INTO distribution_activities (
                                distribution_id, amount, description, distribution_type,
                                distributed_at, created_by, created_by_username
                            ) VALUES (?, ?, ?, ?, NOW(), ?, ?)
                        ");
                        
                        $activity_stmt->execute([
                            $distribution_id,
                            $amount,
                            $description,
                            $distribution_type,
                            $user_id,
                            $current_user
                        ]);
                        
                        // Commit transaction
                        $db->commit();
                        
                        $success_message = "✅ Distribution added successfully! Amount: " . formatCurrency($amount, $receipt['currency']);
                        
                        // Refresh CSRF token
                        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                        
                    }
                } catch (PDOException $e) {
                    $db->rollBack();
                    $error_message = "Database error: " . $e->getMessage();
                    error_log("Distribution error: " . $e->getMessage());
                } catch (Exception $e) {
                    $db->rollBack();
                    $error_message = "Error: " . $e->getMessage();
                    error_log("Distribution error: " . $e->getMessage());
                }
            }
        } elseif ($action === 'delete_distribution') {
            // Delete distribution activity
            $activity_id = (int)($_POST['activity_id'] ?? 0);
            
            if ($activity_id <= 0) {
                $error_message = "Invalid distribution activity.";
            } else {
                try {
                    // Start transaction
                    $db->beginTransaction();
                    
                    // Get activity details
                    $stmt = $db->prepare("
                        SELECT da.*, rd.distributed_amount, rd.remaining_balance, rd.total_amount
                        FROM distribution_activities da
                        JOIN receipt_distributions rd ON da.distribution_id = rd.id
                        WHERE da.id = ?
                    ");
                    $stmt->execute([$activity_id]);
                    $activity = $stmt->fetch();
                    
                    if (!$activity) {
                        $error_message = "Distribution activity not found.";
                        $db->rollBack();
                    } else {
                        // Delete the activity
                        $delete_stmt = $db->prepare("DELETE FROM distribution_activities WHERE id = ?");
                        $delete_stmt->execute([$activity_id]);
                        
                        // Update distribution record
                        $new_distributed = ($activity['distributed_amount'] ?? 0) - ($activity['amount'] ?? 0);
                        $new_remaining = ($activity['remaining_balance'] ?? 0) + ($activity['amount'] ?? 0);
                        
                        $update_stmt = $db->prepare("
                            UPDATE receipt_distributions 
                            SET distributed_amount = ?, 
                                remaining_balance = ?,
                                status = CASE WHEN ? >= total_amount THEN 'completed' ELSE 'pending' END,
                                updated_at = NOW()
                            WHERE id = ?
                        ");
                        
                        $update_stmt->execute([
                            $new_distributed,
                            $new_remaining,
                            $new_distributed,
                            $activity['distribution_id']
                        ]);
                        
                        // Commit transaction
                        $db->commit();
                        
                        $success_message = "✅ Distribution activity deleted successfully.";
                        
                        // Refresh CSRF token
                        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                    }
                } catch (PDOException $e) {
                    $db->rollBack();
                    $error_message = "Database error: " . $e->getMessage();
                    error_log("Distribution delete error: " . $e->getMessage());
                }
            }
        } elseif ($action === 'complete_distribution') {
            // Mark distribution as completed
            $distribution_id = (int)($_POST['distribution_id'] ?? 0);
            
            if ($distribution_id <= 0) {
                $error_message = "Invalid distribution.";
            } else {
                try {
                    $update_stmt = $db->prepare("
                        UPDATE receipt_distributions 
                        SET status = 'completed', updated_at = NOW()
                        WHERE id = ?
                    ");
                    
                    $update_stmt->execute([$distribution_id]);
                    
                    $success_message = "✅ Distribution marked as completed.";
                    
                    // Refresh CSRF token
                    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                    
                } catch (PDOException $e) {
                    $error_message = "Database error: " . $e->getMessage();
                    error_log("Distribution complete error: " . $e->getMessage());
                }
            }
        }
    }
}

// Get filter parameters
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-d');
$receipt_no_filter = $_GET['receipt_no'] ?? '';
$status_filter = $_GET['status'] ?? '';
$search_description = $_GET['search_description'] ?? '';

// Validate filter dates
$start_date_obj = DateTime::createFromFormat('Y-m-d', $start_date);
$end_date_obj = DateTime::createFromFormat('Y-m-d', $end_date);
if (!$start_date_obj) $start_date = date('Y-m-01');
if (!$end_date_obj) $end_date = date('Y-m-d');

// DEBUG: Check if distribution_activities table exists and has data
try {
    $debug_stmt = $db->query("SHOW TABLES LIKE 'distribution_activities'");
    $table_exists = $debug_stmt->fetch();
    
    if ($table_exists) {
        $count_stmt = $db->query("SELECT COUNT(*) as count FROM distribution_activities");
        $activity_count = $count_stmt->fetch()['count'];
        error_log("DEBUG: distribution_activities table exists with {$activity_count} records");
        
        // Check recent activities
        $recent_stmt = $db->query("
            SELECT da.*, rd.receipt_id, r.receipt_no 
            FROM distribution_activities da
            LEFT JOIN receipt_distributions rd ON da.distribution_id = rd.id
            LEFT JOIN receipts r ON rd.receipt_id = r.id
            ORDER BY da.distributed_at DESC 
            LIMIT 5
        ");
        $recent_activities = $recent_stmt->fetchAll();
        error_log("DEBUG: Recent activities: " . json_encode($recent_activities));
    } else {
        error_log("DEBUG: distribution_activities table does not exist!");
    }
} catch (Exception $e) {
    error_log("DEBUG Error: " . $e->getMessage());
}

// Fetch receipts available for distribution
try {
    $receipts_stmt = $db->prepare("
        SELECT r.id, r.receipt_no, r.receipt_date, r.amount, r.currency, 
               r.name as payer_name, r.narration,
               rd.id as distribution_id, 
               COALESCE(rd.distributed_amount, 0) as distributed_amount, 
               COALESCE(rd.remaining_balance, r.amount) as remaining_balance, 
               COALESCE(rd.status, 'pending') as distribution_status
        FROM receipts r
        LEFT JOIN receipt_distributions rd ON r.id = rd.receipt_id
        WHERE r.money_distribution = 'yes'
        AND r.receipt_date >= ? AND r.receipt_date < DATE_ADD(?, INTERVAL 1 DAY)
        ORDER BY r.receipt_date DESC
    ");
    $receipts_stmt->execute([$start_date, $end_date]);
    $available_receipts = $receipts_stmt->fetchAll();
} catch (PDOException $e) {
    $available_receipts = [];
    $error_message = "Error fetching receipts: " . $e->getMessage();
    error_log("Receipts fetch error: " . $e->getMessage());
}

// Fetch distribution activities with filters - FIXED QUERY
$filter_conditions = ["da.distributed_at >= ?", "da.distributed_at < DATE_ADD(?, INTERVAL 1 DAY)"];
$filter_params = [$start_date, $end_date];

if (!empty($receipt_no_filter)) {
    $filter_conditions[] = "r.receipt_no LIKE ?";
    $filter_params[] = '%' . $receipt_no_filter . '%';
}

if (!empty($status_filter) && in_array($status_filter, ['pending', 'completed', 'cancelled'])) {
    $filter_conditions[] = "rd.status = ?";
    $filter_params[] = $status_filter;
}

if (!empty($search_description)) {
    $filter_conditions[] = "(da.description LIKE ? OR r.narration LIKE ?)";
    $filter_params[] = '%' . $search_description . '%';
    $filter_params[] = '%' . $search_description . '%';
}

$where_clause = '';
if (!empty($filter_conditions)) {
    $where_clause = 'WHERE ' . implode(' AND ', $filter_conditions);
}

try {
    // First, let's try a simpler query to see if we get any data
    $test_query = "
        SELECT COUNT(*) as count FROM distribution_activities da
        WHERE da.distributed_at >= ? AND da.distributed_at < DATE_ADD(?, INTERVAL 1 DAY)
    ";
    $test_stmt = $db->prepare($test_query);
    $test_stmt->execute([$start_date, $end_date]);
    $test_result = $test_stmt->fetch();
    error_log("DEBUG: Activities in date range: " . $test_result['count']);
    
    // Main query - FIXED with proper table relationships
    $activities_query = "
        SELECT 
            da.id,
            da.distribution_id,
            da.amount,
            da.description,
            da.distribution_type,
            da.distributed_at,
            da.created_by_username,
            rd.receipt_id,
            rd.receipt_no,
            rd.total_amount,
            rd.distributed_amount as rd_distributed,
            rd.remaining_balance,
            rd.status as distribution_status,
            rd.currency,
            r.receipt_date,
            r.name as payer_name,
            r.narration as receipt_description
        FROM distribution_activities da
        LEFT JOIN receipt_distributions rd ON da.distribution_id = rd.id
        LEFT JOIN receipts r ON rd.receipt_id = r.id
        $where_clause
        ORDER BY da.distributed_at DESC
    ";
    
    error_log("DEBUG Query: " . $activities_query);
    error_log("DEBUG Params: " . json_encode($filter_params));
    
    $activities_stmt = $db->prepare($activities_query);
    $activities_stmt->execute($filter_params);
    $distribution_activities = $activities_stmt->fetchAll();
    
    error_log("DEBUG: Found " . count($distribution_activities) . " distribution activities");
    
} catch (PDOException $e) {
    $distribution_activities = [];
    $error_message = "Error fetching distribution activities: " . $e->getMessage();
    error_log("Distribution activities fetch error: " . $e->getMessage());
    error_log("Error details: " . print_r($db->errorInfo(), true));
}

// Calculate totals
$total_distributed = 0;
$total_available = 0;
$pending_distributions = 0;
$completed_distributions = 0;

foreach ($available_receipts as $receipt) {
    $available_balance = $receipt['remaining_balance'] ?? 0;
    $total_available += $available_balance;
    if (($receipt['distribution_status'] ?? 'pending') == 'pending') {
        $pending_distributions++;
    } elseif (($receipt['distribution_status'] ?? '') == 'completed') {
        $completed_distributions++;
    }
}

foreach ($distribution_activities as $activity) {
    $total_distributed += ($activity['amount'] ?? 0);
}

$page_title = 'Distribute Money - Fund Allocation';
include '../includes/header.php';
?>

<style>
    .stats-card {
        border-radius: 10px;
        transition: transform 0.3s ease;
    }
    
    .stats-card:hover {
        transform: translateY(-5px);
    }
    
    .distribution-progress {
        height: 10px;
        border-radius: 5px;
        background-color: #e9ecef;
        margin-top: 5px;
    }
    
    .distribution-progress-bar {
        height: 100%;
        border-radius: 5px;
        background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
    }
    
    .table-hover tbody tr:hover {
        background-color: rgba(0, 123, 255, 0.05);
    }
    
    .badge-distribution-pending {
        background: linear-gradient(135deg, #ffc107 0%, #ff9800 100%);
        color: white;
    }
    
    .badge-distribution-completed {
        background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
        color: white;
    }
    
    .badge-distribution-cancelled {
        background: linear-gradient(135deg, #dc3545 0%, #c82333 100%);
        color: white;
    }
    
    .badge-type-trade {
        background-color: #007bff;
        color: white;
    }
    
    .badge-type-expense {
        background-color: #6c757d;
        color: white;
    }
    
    .badge-type-investment {
        background-color: #17a2b8;
        color: white;
    }
    
    .badge-type-other {
        background-color: #6f42c1;
        color: white;
    }
    
    .amount-cell {
        font-weight: 600;
        text-align: right;
    }
    
    .receipt-link {
        color: #007bff;
        text-decoration: none;
        font-weight: 600;
    }
    
    .receipt-link:hover {
        text-decoration: underline;
        color: #0056b3;
    }
    
    .action-buttons {
        display: flex;
        gap: 5px;
    }
    
    .action-buttons .btn-sm {
        padding: 0.25rem 0.5rem;
        font-size: 0.75rem;
    }
    
    .distribution-summary {
        background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
        border-radius: 8px;
        padding: 15px;
        margin-bottom: 20px;
    }
    
    .form-control-sm {
        height: calc(1.5em + 0.5rem + 2px);
        padding: 0.25rem 0.5rem;
        font-size: 0.875rem;
        line-height: 1.5;
    }
    
    .btn-sm {
        padding: 0.25rem 0.5rem;
        font-size: 0.875rem;
        line-height: 1.5;
    }
    
    .modal-header {
        background: linear-gradient(135deg, #ffc107 0%, #ff9800 100%);
        color: white;
    }
    
    .alert {
        border-radius: 8px;
        border: none;
    }
    
    .debug-info {
        background-color: #f8f9fa;
        border-left: 4px solid #dc3545;
        padding: 10px;
        margin: 10px 0;
        font-size: 0.875rem;
    }
</style>

<div class="container-fluid">
    <?php if (!empty($success_message)): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="bi bi-check-circle-fill me-2"></i>
            <?php echo $success_message; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if (!empty($error_message)): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="bi bi-exclamation-triangle-fill me-2"></i>
            <?php echo $error_message; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>


    <!-- Stats Overview -->
    <div class="row mb-4">
        <div class="col-md-3 mb-3">
            <div class="card stats-card border-primary">
                <div class="card-body text-center py-4">
                    <h3 class="text-primary mb-1">
                        <?php echo count($available_receipts); ?>
                    </h3>
                    <small class="text-muted">Receipts for Distribution</small>
                </div>
            </div>
        </div>
        <div class="col-md-3 mb-3">
            <div class="card stats-card border-success">
                <div class="card-body text-center py-4">
                    <h3 class="text-success mb-1">
                        <?php echo getCurrencySymbol('Tsh') . ' ' . number_format($total_available, 2); ?>
                    </h3>
                    <small class="text-muted">Total Available for Distribution</small>
                </div>
            </div>
        </div>
        <div class="col-md-3 mb-3">
            <div class="card stats-card border-warning">
                <div class="card-body text-center py-4">
                    <h3 class="text-warning mb-1">
                        <?php echo getCurrencySymbol('Tsh') . ' ' . number_format($total_distributed, 2); ?>
                    </h3>
                    <small class="text-muted">Total Distributed</small>
                </div>
            </div>
        </div>
        <div class="col-md-3 mb-3">
            <div class="card stats-card border-info">
                <div class="card-body text-center py-4">
                    <h3 class="text-info mb-1">
                        <?php echo $pending_distributions; ?> / <?php echo $completed_distributions; ?>
                    </h3>
                    <small class="text-muted">Pending / Completed</small>
                </div>
            </div>
        </div>
    </div>

    <!-- Two Column Layout -->
    <div class="row">
        <!-- Left Column: Add Distribution Form -->
        <div class="col-md-6 mb-4">
            <div class="card">
                <div class="card-header bg-warning text-dark">
                    <h5 class="mb-0"><i class="bi bi-cash-stack me-2"></i>Add New Distribution</h5>
                </div>
                <div class="card-body">
                    <form method="POST" id="distributionForm">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                        <input type="hidden" name="action" value="add_distribution">
                        
                        <div class="row g-2">
                            <div class="col-md-12">
                                <label class="form-label">Select Receipt <span class="text-danger">*</span></label>
                                <select class="form-select form-control-sm" name="receipt_id" id="receipt_id" required>
                                    <option value="">Choose a receipt...</option>
                                    <?php foreach ($available_receipts as $receipt): 
                                        $available_balance = $receipt['remaining_balance'] ?? $receipt['amount'];
                                        $distributed_amount = $receipt['distributed_amount'] ?? 0;
                                        $total_amount = $receipt['amount'];
                                        $status = $receipt['distribution_status'] ?? 'pending';
                                        $status_badge = $status == 'completed' ? 'Completed' : 'Pending';
                                        $status_class = $status == 'completed' ? 'badge-distribution-completed' : 'badge-distribution-pending';
                                    ?>
                                        <option value="<?php echo (int)$receipt['id']; ?>" 
                                                data-available="<?php echo $available_balance; ?>"
                                                data-currency="<?php echo htmlspecialchars($receipt['currency']); ?>"
                                                data-receipt-no="<?php echo htmlspecialchars($receipt['receipt_no']); ?>">
                                            <?php echo htmlspecialchars($receipt['receipt_no']); ?> - 
                                            <?php echo htmlspecialchars($receipt['payer_name']); ?> - 
                                            Total: <?php echo formatCurrency($total_amount, $receipt['currency']); ?> - 
                                            Available: <?php echo formatCurrency($available_balance, $receipt['currency']); ?> - 
                                            <span class="badge <?php echo $status_class; ?>"><?php echo $status_badge; ?></span>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <small class="text-muted" id="receiptInfo"></small>
                            </div>
                            
                            <div class="col-md-12">
                                <label class="form-label">Description <span class="text-danger">*</span></label>
                                <input type="text" class="form-control form-control-sm" name="description" 
                                       placeholder="e.g., Buy CRDB shares, Buy VFS ETF, Buy Bonds, Pay supplier invoice" required>
                                <small class="text-muted">Describe what the money is being used for</small>
                            </div>
                            
                            <div class="col-md-6">
                                <label class="form-label">Amount <span class="text-danger">*</span></label>
                                <div class="input-group input-group-sm">
                                    <input type="number" class="form-control form-control-sm" name="amount" 
                                           id="distribution_amount" step="0.01" min="0.01" 
                                           placeholder="0.00" required>
                                    <span class="input-group-text" id="currency_symbol">Tsh</span>
                                </div>
                                <small class="text-muted" id="amountInfo">Available: Tsh 0.00</small>
                            </div>
                            
                            <div class="col-md-6">
                                <label class="form-label">Distribution Type</label>
                                <select class="form-select form-control-sm" name="distribution_type">
                                    <option value="trade">Trade (Buy Share)</option>
                                    <option value="expense">Expense</option>
                                    <option value="investment">Investment</option>
                                    <option value="other">Other</option>
                                </select>
                            </div>
                            
                            <div class="col-12 mt-3">
                                <div class="d-grid">
                                    <button type="submit" class="btn btn-warning btn-sm">
                                        <i class="bi bi-cash-stack me-1"></i>Add Distribution
                                    </button>
                                </div>
                            </div>
                        </div>
                    </form>
                    
                  
                </div>
            </div>
            
            <!-- Available Receipts for Distribution -->
         <!-- Available Receipts for Distribution with Filter -->
<div class="card mt-4">
    <div class="card-header bg-info text-white d-flex justify-content-between align-items-center py-2">
        <h5 class="mb-0"><i class="bi bi-list-check me-2"></i>Available for Distribution</h5>
        <button type="button" class="btn btn-sm btn-light" data-bs-toggle="collapse" data-bs-target="#receiptFiltersCollapse">
            <i class="bi bi-funnel me-1"></i>Filter
        </button>
    </div>
    <div class="card-body p-2">
        <!-- Receipt Filters -->
        <div class="collapse mb-3" id="receiptFiltersCollapse">
            <div class="card card-body p-2">
                <div class="row g-2">
                    <div class="col-md-6">
                        <label class="form-label">Search Receipt/Payer</label>
                        <input type="text" class="form-control form-control-sm" id="receiptSearch" 
                               placeholder="Search receipt no or payer name...">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Currency</label>
                        <select class="form-select form-control-sm" id="currencyFilter">
                            <option value="">All Currencies</option>
                            <option value="Tsh">TSh (Tanzanian Shilling)</option>
                            <option value="USD">USD (US Dollar)</option>
                            <option value="Ksh">KSh (Kenyan Shilling)</option>
                            <option value="UGsh">UGX (Ugandan Shilling)</option>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Status</label>
                        <select class="form-select form-control-sm" id="statusFilter">
                            <option value="">All Status</option>
                            <option value="pending">Pending</option>
                            <option value="completed">Completed</option>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Sort By</label>
                        <select class="form-select form-control-sm" id="sortFilter">
                            <option value="date_desc">Date (Newest First)</option>
                            <option value="date_asc">Date (Oldest First)</option>
                            <option value="amount_desc">Amount (High to Low)</option>
                            <option value="amount_asc">Amount (Low to High)</option>
                            <option value="available_desc">Available (High to Low)</option>
                            <option value="available_asc">Available (Low to High)</option>
                        </select>
                    </div>
                    <div class="col-12">
                        <div class="d-flex justify-content-between">
                            <button type="button" class="btn btn-sm btn-outline-secondary" id="clearReceiptFilters">
                                <i class="bi bi-x-circle me-1"></i>Clear Filters
                            </button>
                            <small class="text-muted align-self-center" id="filteredCount"></small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="table-responsive" style="max-height: 300px; overflow-y: auto;">
            <table class="table table-sm table-hover mb-0" id="receiptsTable">
                <thead>
                    <tr>
                        <th>Receipt No</th>
                        <th>Date</th>
                        <th>Payer</th>
                        <th>Total</th>
                        <th>Available</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody id="receiptsTableBody">
                    <?php if (empty($available_receipts)): ?>
                        <tr>
                            <td colspan="6" class="text-center py-3 text-muted">
                                <i class="bi bi-inbox" style="font-size: 1.5rem;"></i>
                                <p class="mt-2 mb-0">No receipts available for distribution</p>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($available_receipts as $receipt): 
                            $available_balance = $receipt['remaining_balance'] ?? $receipt['amount'];
                            $distributed_amount = $receipt['distributed_amount'] ?? 0;
                            $total_amount = $receipt['amount'];
                            $progress_percent = $total_amount > 0 ? ($distributed_amount / $total_amount) * 100 : 0;
                            $status = $receipt['distribution_status'] ?? 'pending';
                            $status_badge = $status == 'completed' ? 'Completed' : 'Pending';
                            $status_class = $status == 'completed' ? 'badge-distribution-completed' : 'badge-distribution-pending';
                        ?>
                            <tr class="receipt-row" 
                                data-receipt-no="<?php echo htmlspecialchars($receipt['receipt_no']); ?>"
                                data-payer-name="<?php echo htmlspecialchars($receipt['payer_name']); ?>"
                                data-currency="<?php echo htmlspecialchars($receipt['currency']); ?>"
                                data-status="<?php echo htmlspecialchars($status); ?>"
                                data-total="<?php echo $total_amount; ?>"
                                data-available="<?php echo $available_balance; ?>"
                                data-date="<?php echo strtotime($receipt['receipt_date']); ?>">
                                <td>
                                    <a href="receipt_generation.php?receipt_no=<?php echo urlencode($receipt['receipt_no']); ?>" 
                                       class="receipt-link" target="_blank">
                                        <?php echo htmlspecialchars($receipt['receipt_no']); ?>
                                    </a>
                                </td>
                                <td><?php echo date('M d, Y', strtotime($receipt['receipt_date'])); ?></td>
                                <td><small><?php echo htmlspecialchars($receipt['payer_name']); ?></small></td>
                                <td class="amount-cell"><?php echo formatCurrency($total_amount, $receipt['currency']); ?></td>
                                <td class="amount-cell text-warning"><?php echo formatCurrency($available_balance, $receipt['currency']); ?></td>
                                <td>
                                    <span class="badge <?php echo $status_class; ?>"><?php echo $status_badge; ?></span>
                                    <div class="distribution-progress">
                                        <div class="distribution-progress-bar" style="width: <?php echo min(100, $progress_percent); ?>%"></div>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <!-- Receipt Summary -->
        <div class="mt-2">
            <small class="text-muted" id="receiptsSummary">
                Showing <?php echo count($available_receipts); ?> receipt(s) - 
                Total Available: <strong class="text-warning"><?php echo getCurrencySymbol('Tsh') . ' ' . number_format($total_available, 2); ?></strong>
            </small>
        </div>
    </div>
</div>
        </div>
        
        <!-- Right Column: Distribution History -->
        <div class="col-md-6">
            <div class="card">
                <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center py-2">
                    <h5 class="mb-0"><i class="bi bi-clock-history me-2"></i>Distribution History</h5>
                    <div class="d-flex gap-1">
                        <button class="btn btn-sm btn-light" type="button" data-bs-toggle="collapse" data-bs-target="#filtersCollapse">
                            <i class="bi bi-funnel me-1"></i>Filters
                        </button>
                        <button class="btn btn-sm btn-light" id="clearFiltersBtn">
                            <i class="bi bi-x-circle me-1"></i>Clear
                        </button>
                    </div>
                </div>
                <div class="card-body p-2">
                    <!-- Filters Section -->
                    <div class="collapse mb-3" id="filtersCollapse">
                        <div class="card card-body p-2">
                            <form method="GET" id="filtersForm">
                                <div class="row g-2">
                                    <div class="col-md-4">
                                        <label class="form-label">Start Date</label>
                                        <input type="date" class="form-control form-control-sm" name="start_date" value="<?php echo htmlspecialchars($start_date); ?>">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">End Date</label>
                                        <input type="date" class="form-control form-control-sm" name="end_date" value="<?php echo htmlspecialchars($end_date); ?>">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">Status</label>
                                        <select class="form-select form-control-sm" name="status">
                                            <option value="">All Status</option>
                                            <option value="pending" <?php echo $status_filter === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                            <option value="completed" <?php echo $status_filter === 'completed' ? 'selected' : ''; ?>>Completed</option>
                                            <option value="cancelled" <?php echo $status_filter === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                                        </select>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Receipt No</label>
                                        <input type="text" class="form-control form-control-sm" name="receipt_no" value="<?php echo htmlspecialchars($receipt_no_filter); ?>" placeholder="Search receipt no">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Description</label>
                                        <input type="text" class="form-control form-control-sm" name="search_description" value="<?php echo htmlspecialchars($search_description); ?>" placeholder="Search description">
                                    </div>
                                    <div class="col-12">
                                        <div class="d-flex justify-content-end">
                                            <button type="submit" class="btn btn-primary btn-sm">
                                                <i class="bi bi-filter me-1"></i>Apply Filters
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
                    
                    <div class="table-responsive" style="max-height: 500px; overflow-y: auto;">
                        <table class="table table-sm table-striped table-hover mb-1">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Receipt No</th>
                                    <th>Description</th>
                                    <th>Amount</th>
                                    <th>Type</th>
                                    <th>By</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($distribution_activities)): ?>
                                    <tr>
                                        <td colspan="7" class="text-center py-4 text-muted">
                                            <i class="bi bi-inbox" style="font-size: 2rem;"></i>
                                            <p class="mt-2 mb-0">No distribution activities found</p>
                                            <small>Start by adding a distribution from available receipts</small>
                                            <?php if (isset($activity_count) && $activity_count > 0): ?>
                                                <br><small class="text-danger">(Debug: Table has <?php echo $activity_count; ?> records but query returned empty)</small>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($distribution_activities as $activity): 
                                        $type_class = 'badge-type-' . ($activity['distribution_type'] ?? 'other');
                                        $type_label = ucfirst($activity['distribution_type'] ?? 'Other');
                                        $receipt_no = $activity['receipt_no'] ?? 'N/A';
                                        $currency = $activity['currency'] ?? 'Tsh';
                                    ?>
                                        <tr>
                                            <td><?php echo date('M d, Y', strtotime($activity['distributed_at'] ?? 'now')); ?></td>
                                            <td>
                                                <?php if ($receipt_no !== 'N/A'): ?>
                                                    <a href="receipt_generation.php?receipt_no=<?php echo urlencode($receipt_no); ?>" 
                                                       class="receipt-link" target="_blank">
                                                        <?php echo htmlspecialchars($receipt_no); ?>
                                                    </a>
                                                <?php else: ?>
                                                    <span class="text-muted">N/A</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?php echo htmlspecialchars($activity['description'] ?? 'No description'); ?></td>
                                            <td class="amount-cell text-success">
                                                <?php echo formatCurrency($activity['amount'] ?? 0, $currency); ?>
                                            </td>
                                            <td>
                                                <span class="badge <?php echo $type_class; ?>"><?php echo $type_label; ?></span>
                                            </td>
                                            <td>
                                                <small><?php echo htmlspecialchars($activity['created_by_username'] ?? 'System'); ?></small>
                                            </td>
                                            <td>
                                                <div class="action-buttons">
                                                    <?php if (($activity['distribution_status'] ?? '') !== 'completed'): ?>
                                                        <button type="button" class="btn btn-sm btn-outline-danger delete-activity" 
                                                                data-activity-id="<?php echo (int)$activity['id']; ?>"
                                                                data-description="<?php echo htmlspecialchars($activity['description'] ?? ''); ?>"
                                                                data-amount="<?php echo $activity['amount'] ?? 0; ?>">
                                                            <i class="bi bi-trash"></i>
                                                        </button>
                                                    <?php endif; ?>
                                                    <?php if (($activity['distribution_status'] ?? '') === 'pending'): ?>
                                                        <button type="button" class="btn btn-sm btn-outline-success complete-distribution" 
                                                                data-distribution-id="<?php echo (int)($activity['distribution_id'] ?? 0); ?>"
                                                                data-receipt-no="<?php echo htmlspecialchars($receipt_no); ?>">
                                                            <i class="bi bi-check-circle"></i>
                                                        </button>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                            <?php if (!empty($distribution_activities)): ?>
                                <tfoot>
                                    <tr class="table-dark">
                                        <td colspan="3" class="text-end fw-bold">Total Distributed:</td>
                                        <td class="text-success fw-bold">
                                            <?php echo getCurrencySymbol('Tsh') . ' ' . number_format($total_distributed, 2); ?>
                                        </td>
                                        <td colspan="3"></td>
                                    </tr>
                                </tfoot>
                            <?php endif; ?>
                        </table>
                    </div>
                    
                    <!-- Results Count -->
                    <div class="mt-2">
                        <small class="text-muted">
                            Showing <?php echo count($distribution_activities); ?> distribution(s) - 
                            Total: <strong class="text-success"><?php echo getCurrencySymbol('Tsh') . ' ' . number_format($total_distributed, 2); ?></strong>
                            <?php if (!empty($start_date) || !empty($end_date) || !empty($receipt_no_filter) || !empty($status_filter) || !empty($search_description)): ?>
                                (filtered results)
                            <?php endif; ?>
                        </small>
                    </div>
                </div>
            </div>
            
            <!-- Distribution Summary -->
            <div class="card mt-4">
                <div class="card-header bg-success text-white">
                    <h5 class="mb-0"><i class="bi bi-pie-chart me-2"></i>Distribution Summary</h5>
                </div>
                <div class="card-body">
                    <div class="distribution-summary">
                        <div class="row">
                            <div class="col-md-6">
                                <h6>By Distribution Type</h6>
                                <?php
                                $type_totals = [];
                                foreach ($distribution_activities as $activity) {
                                    $type = $activity['distribution_type'] ?? 'other';
                                    $amount = $activity['amount'] ?? 0;
                                    $type_totals[$type] = ($type_totals[$type] ?? 0) + $amount;
                                }
                                ?>
                                <table class="table table-sm table-borderless">
                                    <?php foreach ($type_totals as $type => $total): ?>
                                        <tr>
                                            <td><span class="badge badge-type-<?php echo $type; ?>"><?php echo ucfirst($type); ?></span></td>
                                            <td class="text-end"><?php echo formatCurrency($total, 'Tsh'); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </table>
                            </div>
                            <div class="col-md-6">
                                <h6>By Receipt</h6>
                                <?php
                                $receipt_totals = [];
                                foreach ($distribution_activities as $activity) {
                                    $receipt = $activity['receipt_no'] ?? '';
                                    $amount = $activity['amount'] ?? 0;
                                    if ($receipt) {
                                        $receipt_totals[$receipt] = ($receipt_totals[$receipt] ?? 0) + $amount;
                                    }
                                }
                                arsort($receipt_totals);
                                $receipt_totals = array_slice($receipt_totals, 0, 5); // Top 5
                                ?>
                                <table class="table table-sm table-borderless">
                                    <?php foreach ($receipt_totals as $receipt => $total): ?>
                                        <tr>
                                            <td><small><?php echo htmlspecialchars($receipt); ?></small></td>
                                            <td class="text-end"><small><?php echo formatCurrency($total, 'Tsh'); ?></small></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </table>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mt-3">
                        <a href="receipt_generation.php" class="btn btn-sm btn-outline-primary">
                            <i class="bi bi-arrow-left me-1"></i>Back to Receipts
                        </a>
                        <button type="button" class="btn btn-sm btn-outline-success" id="exportDistributionsBtn">
                            <i class="bi bi-file-excel me-1"></i>Export to Excel
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div class="modal fade" id="deleteConfirmationModal" tabindex="-1" aria-labelledby="deleteConfirmationModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title" id="deleteConfirmationModalLabel">
                    <i class="bi bi-exclamation-triangle me-2"></i>Confirm Delete
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to delete this distribution activity?</p>
                <div class="alert alert-warning">
                    <strong id="deleteItemDescription"></strong><br>
                    Amount: <span id="deleteItemAmount" class="text-danger fw-bold"></span>
                </div>
                <p class="text-muted">This action cannot be undone.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                <form method="POST" id="deleteForm">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                    <input type="hidden" name="action" value="delete_distribution">
                    <input type="hidden" name="activity_id" id="deleteActivityId" value="">
                    <button type="submit" class="btn btn-danger btn-sm">Delete</button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Complete Distribution Modal -->
<div class="modal fade" id="completeDistributionModal" tabindex="-1" aria-labelledby="completeDistributionModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title" id="completeDistributionModalLabel">
                    <i class="bi bi-check-circle me-2"></i>Complete Distribution
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p>Mark this distribution as completed?</p>
                <div class="alert alert-info">
                    Receipt: <strong id="completeReceiptNo"></strong><br>
                    <small class="text-muted">This will change the status to "Completed"</small>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                <form method="POST" id="completeForm">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                    <input type="hidden" name="action" value="complete_distribution">
                    <input type="hidden" name="distribution_id" id="completeDistributionId" value="">
                    <button type="submit" class="btn btn-success btn-sm">Mark as Completed</button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const receiptSelect = document.getElementById('receipt_id');
    const distributionAmountInput = document.getElementById('distribution_amount');
    const receiptInfo = document.getElementById('receiptInfo');
    const amountInfo = document.getElementById('amountInfo');
    const currencySymbol = document.getElementById('currency_symbol');
    const clearFiltersBtn = document.getElementById('clearFiltersBtn');
    const deleteActivityButtons = document.querySelectorAll('.delete-activity');
    const completeDistributionButtons = document.querySelectorAll('.complete-distribution');
    const exportDistributionsBtn = document.getElementById('exportDistributionsBtn');

    // =============== RECEIPT SELECTION HANDLING ===============
    receiptSelect.addEventListener('change', function() {
        const selectedOption = this.options[this.selectedIndex];
        if (selectedOption && selectedOption.value) {
            const availableBalance = parseFloat(selectedOption.dataset.available) || 0;
            const currency = selectedOption.dataset.currency || 'Tsh';
            const receiptNo = selectedOption.dataset.receiptNo || '';
            
            // Update currency symbol
            currencySymbol.textContent = currency;
            
            // Update receipt info
            receiptInfo.textContent = `Receipt: ${receiptNo} - Available: ${formatCurrency(availableBalance, currency)}`;
            receiptInfo.className = 'text-success';
            
            // Update amount info
            amountInfo.textContent = `Available: ${formatCurrency(availableBalance, currency)}`;
            amountInfo.className = 'text-success';
            
            // Set max amount for input
            distributionAmountInput.max = availableBalance;
            distributionAmountInput.value = '';
            
        } else {
            receiptInfo.textContent = 'Please select a receipt';
            receiptInfo.className = 'text-muted';
            amountInfo.textContent = 'Available: Tsh 0.00';
            amountInfo.className = 'text-muted';
            currencySymbol.textContent = 'Tsh';
            distributionAmountInput.max = '';
            distributionAmountInput.value = '';
        }
    });

    // =============== AMOUNT INPUT VALIDATION ===============
    distributionAmountInput.addEventListener('input', function() {
        const selectedOption = receiptSelect.options[receiptSelect.selectedIndex];
        if (selectedOption && selectedOption.value) {
            const availableBalance = parseFloat(selectedOption.dataset.available) || 0;
            const currency = selectedOption.dataset.currency || 'Tsh';
            const enteredAmount = parseFloat(this.value) || 0;
            
            if (enteredAmount > availableBalance) {
                amountInfo.textContent = `Amount exceeds available balance! Available: ${formatCurrency(availableBalance, currency)}`;
                amountInfo.className = 'text-danger';
            } else if (enteredAmount <= 0) {
                amountInfo.textContent = `Please enter a valid amount. Available: ${formatCurrency(availableBalance, currency)}`;
                amountInfo.className = 'text-warning';
            } else {
                const remaining = availableBalance - enteredAmount;
                amountInfo.textContent = `Remaining after distribution: ${formatCurrency(remaining, currency)}`;
                amountInfo.className = 'text-success';
            }
        }
    });

    // =============== CLEAR FILTERS ===============
    clearFiltersBtn.addEventListener('click', function() {
        window.location.href = window.location.pathname;
    });

    // =============== DELETE ACTIVITY CONFIRMATION ===============
    deleteActivityButtons.forEach(button => {
        button.addEventListener('click', function() {
            const activityId = this.getAttribute('data-activity-id');
            const description = this.getAttribute('data-description');
            const amount = this.getAttribute('data-amount');
            
            document.getElementById('deleteActivityId').value = activityId;
            document.getElementById('deleteItemDescription').textContent = description;
            document.getElementById('deleteItemAmount').textContent = formatCurrency(parseFloat(amount), 'Tsh');
            
            const deleteModal = new bootstrap.Modal(document.getElementById('deleteConfirmationModal'));
            deleteModal.show();
        });
    });

    // =============== COMPLETE DISTRIBUTION CONFIRMATION ===============
    completeDistributionButtons.forEach(button => {
        button.addEventListener('click', function() {
            const distributionId = this.getAttribute('data-distribution-id');
            const receiptNo = this.getAttribute('data-receipt-no');
            
            document.getElementById('completeDistributionId').value = distributionId;
            document.getElementById('completeReceiptNo').textContent = receiptNo;
            
            const completeModal = new bootstrap.Modal(document.getElementById('completeDistributionModal'));
            completeModal.show();
        });
    });

    // =============== EXPORT DISTRIBUTIONS ===============
    exportDistributionsBtn.addEventListener('click', function() {
        // Create CSV content
        let csvContent = "Date,Receipt No,Description,Amount,Currency,Type,Distributed By\n";
        
        <?php foreach ($distribution_activities as $activity): ?>
            csvContent += `"<?php echo date('Y-m-d', strtotime($activity['distributed_at'])); ?>",`;
            csvContent += `"<?php echo htmlspecialchars($activity['receipt_no'] ?? 'N/A'); ?>",`;
            csvContent += `"<?php echo htmlspecialchars($activity['description']); ?>",`;
            csvContent += `"<?php echo $activity['amount']; ?>",`;
            csvContent += `"<?php echo htmlspecialchars($activity['currency'] ?? 'Tsh'); ?>",`;
            csvContent += `"<?php echo ucfirst($activity['distribution_type'] ?? 'other'); ?>"\n`;
        <?php endforeach; ?>
        
        // Create and download file
        const blob = new Blob([csvContent], { type: 'text/csv' });
        const url = window.URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = `distribution_history_${new Date().toISOString().slice(0,10)}.csv`;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        window.URL.revokeObjectURL(url);
    });

    // =============== FORM VALIDATION ===============
    document.getElementById('distributionForm').addEventListener('submit', function(e) {
        const amount = parseFloat(distributionAmountInput.value);
        const selectedOption = receiptSelect.options[receiptSelect.selectedIndex];
        
        if (!selectedOption || !selectedOption.value) {
            alert('Please select a receipt');
            e.preventDefault();
            return;
        }
        
        if (amount <= 0) {
            alert('Please enter a valid amount');
            e.preventDefault();
            return;
        }
        
        const availableBalance = parseFloat(selectedOption.dataset.available) || 0;
        if (amount > availableBalance) {
            alert('Amount exceeds available balance. Please enter a smaller amount.');
            e.preventDefault();
            return;
        }
        
        if (!confirm(`Are you sure you want to distribute ${formatCurrency(amount, selectedOption.dataset.currency)}?\n\nReceipt: ${selectedOption.dataset.receiptNo}`)) {
            e.preventDefault();
        }
    });

    // =============== HELPER FUNCTIONS ===============
    function formatCurrency(amount, currency = 'Tsh') {
        const formatter = new Intl.NumberFormat('en-US', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
        
        const formattedAmount = formatter.format(amount);
        const symbol = getCurrencySymbol(currency);
        
        return `${symbol} ${formattedAmount}`;
    }

    function getCurrencySymbol(currency) {
        switch(currency) {
            case 'USD': return '$';
            case 'Ksh': return 'KSh';
            case 'UGsh': return 'UGX';
            case 'Tsh': return 'TSh';
            default: return 'TSh';
        }
    }

    // =============== INITIALIZATION ===============
    // Trigger receipt select change on page load if there's a selected value
    if (receiptSelect.value) {
        receiptSelect.dispatchEvent(new Event('change'));
    }
    
    // Auto-focus on amount field when receipt is selected
    receiptSelect.addEventListener('change', function() {
        if (this.value) {
            setTimeout(() => {
                distributionAmountInput.focus();
            }, 100);
        }
    });
});

// =============== RECEIPTS TABLE FILTERING ===============
const receiptSearch = document.getElementById('receiptSearch');
const currencyFilter = document.getElementById('currencyFilter');
const statusFilter = document.getElementById('statusFilter');
const sortFilter = document.getElementById('sortFilter');
const clearReceiptFilters = document.getElementById('clearReceiptFilters');
const receiptsTableBody = document.getElementById('receiptsTableBody');
const receiptsSummary = document.getElementById('receiptsSummary');
const filteredCount = document.getElementById('filteredCount');

let allReceiptRows = Array.from(document.querySelectorAll('.receipt-row'));

// Function to filter and sort receipts
function filterAndSortReceipts() {
    const searchTerm = receiptSearch.value.toLowerCase();
    const currencyValue = currencyFilter.value;
    const statusValue = statusFilter.value;
    const sortValue = sortFilter.value;
    
    let filteredRows = allReceiptRows.filter(row => {
        const receiptNo = row.getAttribute('data-receipt-no').toLowerCase();
        const payerName = row.getAttribute('data-payer-name').toLowerCase();
        const currency = row.getAttribute('data-currency');
        const status = row.getAttribute('data-status');
        
        // Search filter
        const searchMatch = !searchTerm || 
                          receiptNo.includes(searchTerm) || 
                          payerName.includes(searchTerm);
        
        // Currency filter
        const currencyMatch = !currencyValue || currency === currencyValue;
        
        // Status filter
        const statusMatch = !statusValue || status === statusValue;
        
        return searchMatch && currencyMatch && statusMatch;
    });
    
    // Sort the filtered rows
    filteredRows.sort((a, b) => {
        const aTotal = parseFloat(a.getAttribute('data-total'));
        const bTotal = parseFloat(b.getAttribute('data-total'));
        const aAvailable = parseFloat(a.getAttribute('data-available'));
        const bAvailable = parseFloat(b.getAttribute('data-available'));
        const aDate = parseInt(a.getAttribute('data-date'));
        const bDate = parseInt(b.getAttribute('data-date'));
        
        switch(sortValue) {
            case 'date_desc':
                return bDate - aDate;
            case 'date_asc':
                return aDate - bDate;
            case 'amount_desc':
                return bTotal - aTotal;
            case 'amount_asc':
                return aTotal - bTotal;
            case 'available_desc':
                return bAvailable - aAvailable;
            case 'available_asc':
                return aAvailable - bAvailable;
            default:
                return 0;
        }
    });
    
    // Update the table
    receiptsTableBody.innerHTML = '';
    
    if (filteredRows.length === 0) {
        receiptsTableBody.innerHTML = `
            <tr>
                <td colspan="6" class="text-center py-3 text-muted">
                    <i class="bi bi-search" style="font-size: 1.5rem;"></i>
                    <p class="mt-2 mb-0">No receipts match your filters</p>
                </td>
            </tr>
        `;
    } else {
        filteredRows.forEach(row => {
            receiptsTableBody.appendChild(row.cloneNode(true));
        });
    }
    
    // Update summary
    const totalAvailable = filteredRows.reduce((sum, row) => {
        return sum + parseFloat(row.getAttribute('data-available'));
    }, 0);
    
    filteredCount.textContent = `${filteredRows.length} receipt(s) found`;
    receiptsSummary.innerHTML = `
        Showing ${filteredRows.length} receipt(s) - 
        Total Available: <strong class="text-warning">${formatCurrency(totalAvailable, 'Tsh')}</strong>
        ${searchTerm || currencyValue || statusValue ? '(filtered)' : ''}
    `;
    
    // Update the dropdown options to match filtered receipts
    updateDropdownOptions(filteredRows);
}

// Function to update dropdown options based on filtered receipts
function updateDropdownOptions(filteredRows) {
    const receiptSelect = document.getElementById('receipt_id');
    const currentValue = receiptSelect.value;
    
    // Clear existing options (except the first empty one)
    while (receiptSelect.options.length > 1) {
        receiptSelect.remove(1);
    }
    
    // Add filtered options
    filteredRows.forEach(row => {
        const receiptNo = row.getAttribute('data-receipt-no');
        const payerName = row.getAttribute('data-payer-name');
        const total = parseFloat(row.getAttribute('data-total'));
        const available = parseFloat(row.getAttribute('data-available'));
        const currency = row.getAttribute('data-currency');
        const status = row.getAttribute('data-status');
        const receiptId = row.querySelector('a.receipt-link')?.href?.match(/receipt_no=([^&]+)/)?.[1] || '';
        
        // Find the original option from available_receipts PHP array
        const originalOption = Array.from(receiptSelect.originalOptions || []).find(opt => 
            opt.text.includes(receiptNo)
        );
        
        if (originalOption) {
            const option = document.createElement('option');
            option.value = originalOption.value;
            option.textContent = originalOption.textContent;
            option.dataset.available = available;
            option.dataset.currency = currency;
            option.dataset.receiptNo = receiptNo;
            
            if (originalOption.value === currentValue) {
                option.selected = true;
            }
            
            receiptSelect.appendChild(option);
        }
    });
    
    // Trigger change event if the selected option still exists
    if (receiptSelect.value !== currentValue) {
        receiptSelect.value = currentValue;
        receiptSelect.dispatchEvent(new Event('change'));
    }
}

// Store original dropdown options
document.addEventListener('DOMContentLoaded', function() {
    const receiptSelect = document.getElementById('receipt_id');
    receiptSelect.originalOptions = Array.from(receiptSelect.options);
    
    // Store all receipt rows
    allReceiptRows = Array.from(document.querySelectorAll('.receipt-row'));
    
    // Initialize filtered count
    filteredCount.textContent = `${allReceiptRows.length} receipt(s) found`;
});

// Event listeners for filters
receiptSearch.addEventListener('input', filterAndSortReceipts);
currencyFilter.addEventListener('change', filterAndSortReceipts);
statusFilter.addEventListener('change', filterAndSortReceipts);
sortFilter.addEventListener('change', filterAndSortReceipts);

clearReceiptFilters.addEventListener('click', function() {
    receiptSearch.value = '';
    currencyFilter.value = '';
    statusFilter.value = '';
    sortFilter.value = 'date_desc';
    filterAndSortReceipts();
});

// Click on receipt row to select it in dropdown
document.addEventListener('click', function(e) {
    const receiptRow = e.target.closest('.receipt-row');
    if (receiptRow) {
        const receiptNo = receiptRow.getAttribute('data-receipt-no');
        const receiptSelect = document.getElementById('receipt_id');
        
        // Find and select the corresponding option
        for (let option of receiptSelect.options) {
            if (option.text.includes(receiptNo)) {
                receiptSelect.value = option.value;
                receiptSelect.dispatchEvent(new Event('change'));
                
                // Scroll to the form
                document.querySelector('#distributionForm').scrollIntoView({
                    behavior: 'smooth',
                    block: 'center'
                });
                break;
            }
        }
    }
});

// Initialize filtering on page load
filterAndSortReceipts();
</script>

<?php include '../includes/footer.php'; ?>