<?php
require_once '../config/config.php';
require_once '../auth/auth_middleware.php';

// Check user permissions - finance, admin, and operations can access
$user_role = $_SESSION['role'] ?? '';
$allowed_roles = ['finance_officer', 'system_admin', 'trader'];

require_login();

// Check if user has any of the allowed roles
if (!in_array($user_role, $allowed_roles)) {
    show_alert('Access denied. You do not have permission to access the settlement page.', 'danger');
    redirect('index.php');
}

$db = getDBConnection();
$success_message = '';
$error_message = '';

// Get company details
$company_stmt = $db->query("SELECT * FROM companies WHERE status = 'active' ORDER BY id LIMIT 1");
$company = $company_stmt->fetch();
$company_name = $company ? $company['company_name'] : 'Victory Financial Services LTD';

// Handle bulk actions
if (isset($_POST['bulk_action']) && isset($_POST['trade_ids'])) {
    $bulk_action = $_POST['bulk_action'];
    $trade_ids = $_POST['trade_ids'];
    $user_id = $_SESSION['user_id'];
    $processed = 0;
    $failed = 0;
    
    // Check if trade_ids is array and not empty
    if (!empty($trade_ids) && is_array($trade_ids)) {
        foreach ($trade_ids as $trade_id) {
            $trade_id = (int)$trade_id;
            
            // Verify trade exists and is active
            $stmt = $db->prepare("SELECT * FROM trades WHERE id = ?");
            $stmt->execute([$trade_id]);
            $trade = $stmt->fetch();
            
            if ($trade) {
                $current_time = date('Y-m-d H:i:s');
                
                switch ($bulk_action) {
                    case 'mark_paid':
                        $notes = "\nMarked as paid by user $user_id on $current_time";
                        $stmt = $db->prepare("
                            UPDATE trades 
                            SET settlement_status = 'paid', 
                                settled_by = ?, 
                                settlement_notes = CONCAT(COALESCE(settlement_notes, ''), ?)
                            WHERE id = ?
                        ");
                        if ($stmt->execute([$user_id, $notes, $trade_id])) {
                            $processed++;
                        } else {
                            $failed++;
                        }
                        break;
                        
                    case 'mark_unpaid':
                        $notes = "\nMarked as unpaid by user $user_id on $current_time";
                        $stmt = $db->prepare("
                            UPDATE trades 
                            SET settlement_status = 'unpaid', 
                                settled_by = NULL, 
                                settlement_notes = CONCAT(COALESCE(settlement_notes, ''), ?)
                            WHERE id = ?
                        ");
                        if ($stmt->execute([$notes, $trade_id])) {
                            $processed++;
                        } else {
                            $failed++;
                        }
                        break;
                        
                    case 'mark_failed':
                        $failure_reason = sanitize_input($_POST['bulk_failure_reason'] ?? '');
                        $action_needed = sanitize_input($_POST['bulk_action_needed'] ?? '');
                        
                        $notes = "\nMarked as failed by user $user_id on $current_time: $failure_reason | Action: $action_needed";
                        
                        $stmt = $db->prepare("
                            UPDATE trades 
                            SET settlement_status = 'failed', 
                                failure_reason = ?, 
                                action_needed = ?,
                                settlement_notes = CONCAT(COALESCE(settlement_notes, ''), ?),
                                settled_by = ?
                            WHERE id = ?
                        ");
                        if ($stmt->execute([$failure_reason, $action_needed, $notes, $user_id, $trade_id])) {
                            $processed++;
                        } else {
                            $failed++;
                        }
                        break;
                }
            } else {
                $failed++;
            }
        }
        
        if ($processed > 0) {
            $success_message = "Successfully processed $processed trades";
            if ($failed > 0) {
                $error_message = "Failed to process $failed trades";
            }
        } else {
            $error_message = "Failed to process any trades";
        }
    }
    
    // Redirect with messages
    $message = $success_message ?: $error_message;
    $type = $success_message ? 'success' : 'danger';
    header('Location: settlement.php?message=' . urlencode($message) . '&type=' . $type);
    exit;
}

// Handle single payment actions
if (isset($_POST['action']) && isset($_POST['trade_id'])) {
    $action = $_POST['action'];
    $trade_id = (int)$_POST['trade_id'];
    $user_id = $_SESSION['user_id'];
    
    // Verify trade exists and is active
    $stmt = $db->prepare("SELECT * FROM trades WHERE id = ?");
    $stmt->execute([$trade_id]);
    $trade = $stmt->fetch();
    
    if ($trade) {
        $current_time = date('Y-m-d H:i:s');
        
        switch ($action) {
            case 'mark_paid':
                $notes = "\nMarked as paid by user $user_id on $current_time";
                $stmt = $db->prepare("
                    UPDATE trades 
                    SET settlement_status = 'paid', 
                        settled_by = ?, 
                        settlement_notes = CONCAT(COALESCE(settlement_notes, ''), ?)
                    WHERE id = ?
                ");
                if ($stmt->execute([$user_id, $notes, $trade_id])) {
                    $success_message = 'Trade marked as paid successfully.';
                } else {
                    $error_message = 'Error marking trade as paid.';
                }
                break;
                
            case 'mark_unpaid':
                $notes = "\nMarked as unpaid by user $user_id on $current_time";
                $stmt = $db->prepare("
                    UPDATE trades 
                    SET settlement_status = 'unpaid', 
                        settled_by = NULL, 
                        settlement_notes = CONCAT(COALESCE(settlement_notes, ''), ?)
                    WHERE id = ?
                ");
                if ($stmt->execute([$notes, $trade_id])) {
                    $success_message = 'Trade marked as unpaid successfully.';
                } else {
                    $error_message = 'Error marking trade as unpaid.';
                }
                break;
                
            case 'mark_failed':
                $failure_reason = sanitize_input($_POST['failure_reason'] ?? '');
                $action_needed = sanitize_input($_POST['action_needed'] ?? '');
                
                $notes = "\nMarked as failed by user $user_id on $current_time: $failure_reason | Action: $action_needed";
                
                $stmt = $db->prepare("
                    UPDATE trades 
                    SET settlement_status = 'failed', 
                        failure_reason = ?, 
                        action_needed = ?,
                        settlement_notes = CONCAT(COALESCE(settlement_notes, ''), ?),
                        settled_by = ?
                    WHERE id = ?
                ");
                if ($stmt->execute([$failure_reason, $action_needed, $notes, $user_id, $trade_id])) {
                    $success_message = 'Trade marked as failed with reason.';
                } else {
                    $error_message = 'Error marking trade as failed.';
                }
                break;
        }
    } else {
        $error_message = 'Trade not found.';
    }
    
    // Redirect to avoid form resubmission
    header('Location: settlement.php?message=' . urlencode($success_message ?: $error_message) . '&type=' . ($success_message ? 'success' : 'danger'));
    exit;
}

// Get filter values
$trade_side_filter = isset($_GET['side']) ? $_GET['side'] : 'all';

// Get date range for settlement
$today = date('Y-m-d');
$two_days_ago = date('Y-m-d', strtotime('-2 days'));
$next_30_days = date('Y-m-d', strtotime('+30 days'));

// Get settlement trades - from 2 days ago to 30 days in the future
$query = "
    SELECT t.*, 
           u.username as settled_by_username,
           c_buyer.company_name as buyer_company_name,
           c_seller.company_name as seller_company_name,
           CASE 
               WHEN t.settlement_date < ? THEN 'overdue'
               WHEN t.settlement_date = ? THEN 'today'
               ELSE 'upcoming'
           END as settlement_status_category
    FROM trades t
    LEFT JOIN users u ON t.settled_by = u.id
    LEFT JOIN companies c_buyer ON t.client_name = c_buyer.company_name
    LEFT JOIN companies c_seller ON t.counterparty_name = c_seller.company_name
    WHERE t.settlement_date BETWEEN ? AND ?
    AND t.status = 'active'
    AND (t.settlement_status IS NULL OR t.settlement_status != 'cancelled')
";

// Add trade side filter if needed
if ($trade_side_filter !== 'all') {
    $query .= " AND t.trade_side = ? ";
}

$query .= " ORDER BY 
    CASE 
        WHEN t.settlement_date < ? THEN 1
        WHEN t.settlement_date = ? THEN 2
        ELSE 3
    END,
    t.settlement_date ASC,
    t.created_at DESC";

// Prepare and execute query
$stmt = $db->prepare($query);

if ($trade_side_filter !== 'all') {
    $stmt->execute([$today, $today, $two_days_ago, $next_30_days, $trade_side_filter, $today, $today]);
} else {
    $stmt->execute([$today, $today, $two_days_ago, $next_30_days, $today, $today]);
}

$settlement_trades = $stmt->fetchAll();

// Calculate summary statistics
$total_trades = count($settlement_trades);
$total_value = 0;
$overdue_count = 0;
$overdue_value = 0;
$today_count = 0;
$today_value = 0;
$upcoming_count = 0;
$upcoming_value = 0;
$paid_count = 0;
$paid_value = 0;
$unpaid_count = 0;
$unpaid_value = 0;
$failed_count = 0;
$failed_value = 0;

foreach ($settlement_trades as $trade) {
    $value = floatval($trade['consideration']);
    $total_value += $value;
    
    if ($trade['settlement_status'] === 'paid') {
        $paid_count++;
        $paid_value += $value;
    } elseif ($trade['settlement_status'] === 'failed') {
        $failed_count++;
        $failed_value += $value;
    } else {
        $unpaid_count++;
        $unpaid_value += $value;
        
        if ($trade['settlement_status_category'] === 'overdue') {
            $overdue_count++;
            $overdue_value += $value;
        } elseif ($trade['settlement_status_category'] === 'today') {
            $today_count++;
            $today_value += $value;
        } else {
            $upcoming_count++;
            $upcoming_value += $value;
        }
    }
}

$page_title = 'Trade Settlement';
include '../includes/header.php';
?>

<div class="page-header">
    <div class="container-fluid">
        <div class="row align-items-center">
            <div class="col-md-8">
                <div class="d-flex align-items-center">
                    <div class="me-3">
                        <div class="d-inline-flex align-items-center justify-content-center rounded-circle shadow-sm" 
                             style="width: 60px; height: 60px; background: linear-gradient(135deg, var(--success-color) 0%, #10b981 100%);">
                            <i class="bi bi-cash-coin text-white" style="font-size: 1.5rem;"></i>
                        </div>
                    </div>
                    <div>
                        <h1 class="page-title mb-1">Trade Settlement</h1>
                        <p class="page-subtitle">Manage trade settlements and payments - <?php echo htmlspecialchars($company_name); ?></p>
                    </div>
                </div>
            </div>
            <div class="col-md-4 text-end">
                <div class="d-flex gap-2 justify-content-end flex-wrap">
                    <a href="trades.php" class="btn btn-outline-secondary d-flex align-items-center">
                        <i class="bi bi-arrow-left me-2"></i>
                        <span class="d-none d-sm-inline">Back to Trades</span>
                    </a>
                    <button type="button" class="btn btn-primary d-flex align-items-center" data-bs-toggle="modal" data-bs-target="#exportModal">
                        <i class="bi bi-download me-2"></i>
                        <span class="d-none d-sm-inline">Export Report</span>
                    </button>
                    <button type="button" class="btn btn-success d-flex align-items-center" onclick="markAllTodayAsPaid()">
                        <i class="bi bi-check-all me-2"></i>
                        <span class="d-none d-sm-inline">Mark Today's as Paid</span>
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="container-fluid">
    <?php if (isset($_GET['message'])): ?>
        <div class="alert alert-<?php echo $_GET['type'] ?? 'info'; ?> alert-dismissible fade show mb-4" role="alert">
            <?php echo htmlspecialchars($_GET['message']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <!-- Filter Section -->
    <div class="card mb-4">
        <div class="card-header bg-light">
            <div class="row align-items-center">
                <div class="col-md-6">
                    <h5 class="mb-0">Filters</h5>
                </div>
                <div class="col-md-6 text-end">
                    <form method="GET" class="d-inline">
                        <div class="row g-2 justify-content-end">
                            <div class="col-auto">
                                <select class="form-select form-select-sm" name="side" onchange="this.form.submit()">
                                    <option value="all" <?php echo $trade_side_filter === 'all' ? 'selected' : ''; ?>>All Trades</option>
                                    <option value="buy" <?php echo $trade_side_filter === 'buy' ? 'selected' : ''; ?>>Buy</option>
                                    <option value="sell" <?php echo $trade_side_filter === 'sell' ? 'selected' : ''; ?>>Sell</option>
                                </select>
                            </div>
                            <div class="col-auto">
                                <button type="button" class="btn btn-sm btn-outline-secondary" onclick="resetFilters()">
                                    <i class="bi bi-x-circle"></i> Reset
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Summary Cards -->
    <div class="row mb-4">
        <div class="col-xl-3 col-md-6 mb-4">
            <div class="card border-left-primary shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs fw-bold text-primary text-uppercase mb-1">Total Settlement Value</div>
                            <div class="h5 mb-0 fw-bold text-gray-800">TZS <?php echo number_format($total_value, 2); ?></div>
                            <div class="mt-2 text-muted small"><?php echo $total_trades; ?> trades</div>
                        </div>
                        <div class="col-auto">
                            <i class="bi bi-currency-exchange fa-2x text-gray-300"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6 mb-4">
            <div class="card border-left-danger shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs fw-bold text-danger text-uppercase mb-1">Overdue Settlements</div>
                            <div class="h5 mb-0 fw-bold text-gray-800"><?php echo $overdue_count; ?> trades</div>
                            <div class="mt-2 text-muted small">TZS <?php echo number_format($overdue_value, 2); ?></div>
                        </div>
                        <div class="col-auto">
                            <i class="bi bi-exclamation-triangle fa-2x text-gray-300"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6 mb-4">
            <div class="card border-left-warning shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs fw-bold text-warning text-uppercase mb-1">Due Today</div>
                            <div class="h5 mb-0 fw-bold text-gray-800"><?php echo $today_count; ?> trades</div>
                            <div class="mt-2 text-muted small">TZS <?php echo number_format($today_value, 2); ?></div>
                        </div>
                        <div class="col-auto">
                            <i class="bi bi-calendar-day fa-2x text-gray-300"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6 mb-4">
            <div class="card border-left-success shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs fw-bold text-success text-uppercase mb-1">Paid Settlements</div>
                            <div class="h5 mb-0 fw-bold text-gray-800"><?php echo $paid_count; ?> trades</div>
                            <div class="mt-2 text-muted small">TZS <?php echo number_format($paid_value, 2); ?></div>
                        </div>
                        <div class="col-auto">
                            <i class="bi bi-check-circle fa-2x text-gray-300"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Bulk Actions -->
    <div class="card mb-4" id="bulkActionsCard" style="display: none;">
        <div class="card-header bg-warning">
            <div class="row align-items-center">
                <div class="col-md-6">
                    <h5 class="mb-0 text-white"><i class="bi bi-check2-all me-2"></i>Bulk Actions</h5>
                </div>
                <div class="col-md-6 text-end">
                    <span class="text-white" id="selectedCount">0</span> <span class="text-white">trades selected</span>
                    <button type="button" class="btn btn-sm btn-light ms-3" onclick="clearSelection()">
                        <i class="bi bi-x-circle"></i> Clear
                    </button>
                </div>
            </div>
        </div>
        <div class="card-body">
            <form id="bulkActionForm" method="POST">
                <div class="row g-3 align-items-center">
                    <div class="col-md-4">
                        <select class="form-select" name="bulk_action" id="bulkActionSelect" onchange="toggleBulkFailureFields()">
                            <option value="">Select Action</option>
                            <option value="mark_paid">Mark as Paid</option>
                            <option value="mark_unpaid">Mark as Unpaid</option>
                            <option value="mark_failed">Mark as Failed</option>
                        </select>
                    </div>
                    <div class="col-md-6" id="bulkFailureFields" style="display: none;">
                        <div class="row g-2">
                            <div class="col-md-6">
                                <input type="text" class="form-control" name="bulk_failure_reason" placeholder="Failure Reason">
                            </div>
                            <div class="col-md-6">
                                <select class="form-select" name="bulk_action_needed">
                                    <option value="">Action Needed</option>
                                    <option value="retry_payment">Retry Payment</option>
                                    <option value="contact_client">Contact Client</option>
                                    <option value="contact_counterparty">Contact Counterparty</option>
                                    <option value="investigate_discrepancy">Investigate Discrepancy</option>
                                    <option value="update_account_details">Update Account Details</option>
                                    <option value="escalate_to_manager">Escalate to Manager</option>
                                    <option value="other">Other</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <button type="submit" class="btn btn-primary w-100">Apply</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Status Filter Tabs -->
    <div class="card mb-4">
        <div class="card-header bg-transparent border-0">
            <ul class="nav nav-tabs nav-tabs-custom" id="settlementTabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" id="all-tab" data-bs-toggle="tab" data-bs-target="#all" type="button" role="tab">
                        <i class="bi bi-list-check me-2"></i>All Settlements
                        <span class="badge bg-primary ms-2"><?php echo $total_trades; ?></span>
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="overdue-tab" data-bs-toggle="tab" data-bs-target="#overdue" type="button" role="tab">
                        <i class="bi bi-exclamation-triangle me-2"></i>Overdue
                        <span class="badge bg-danger ms-2"><?php echo $overdue_count; ?></span>
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="today-tab" data-bs-toggle="tab" data-bs-target="#today" type="button" role="tab">
                        <i class="bi bi-calendar-day me-2"></i>Due Today
                        <span class="badge bg-warning ms-2"><?php echo $today_count; ?></span>
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="paid-tab" data-bs-toggle="tab" data-bs-target="#paid" type="button" role="tab">
                        <i class="bi bi-check-circle me-2"></i>Paid
                        <span class="badge bg-success ms-2"><?php echo $paid_count; ?></span>
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="failed-tab" data-bs-toggle="tab" data-bs-target="#failed" type="button" role="tab">
                        <i class="bi bi-x-circle me-2"></i>Failed
                        <span class="badge bg-dark ms-2"><?php echo $failed_count; ?></span>
                    </button>
                </li>
            </ul>
        </div>
        
        <div class="card-body">
            <div class="tab-content" id="settlementTabsContent">
                <!-- All Settlements Tab -->
                <div class="tab-pane fade show active" id="all" role="tabpanel">
                    <?php if (empty($settlement_trades)): ?>
                        <div class="text-center py-5">
                            <i class="bi bi-check-circle text-muted" style="font-size: 4rem; opacity: 0.3;"></i>
                            <h5 class="text-muted mt-3">No Settlements Due</h5>
                            <p class="text-muted">All trades are settled or no settlements due within the period.</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover" id="allSettlementsTable">
                                <thead>
                                    <tr>
                                        <th width="30">
                                            <input type="checkbox" id="selectAll" onchange="toggleSelectAll(this)">
                                        </th>
                                        <th>Trade Ref</th>
                                        <th>Client</th>
                                        <th>Counterparty</th>
                                        <th>Security</th>
                                        <th>Side</th>
                                        <th>Amount</th>
                                        <th>Settlement Date</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($settlement_trades as $trade): 
                                        $status_color = '';
                                        $status_icon = '';
                                        $status_text = '';
                                        
                                        if ($trade['settlement_status'] === 'paid') {
                                            $status_color = 'success';
                                            $status_icon = 'bi-check-circle';
                                            $status_text = 'Paid';
                                        } elseif ($trade['settlement_status'] === 'failed') {
                                            $status_color = 'dark';
                                            $status_icon = 'bi-x-circle';
                                            $status_text = 'Failed';
                                        } elseif ($trade['settlement_status_category'] === 'overdue') {
                                            $status_color = 'danger';
                                            $status_icon = 'bi-exclamation-triangle';
                                            $status_text = 'Overdue';
                                        } elseif ($trade['settlement_status_category'] === 'today') {
                                            $status_color = 'warning';
                                            $status_icon = 'bi-calendar-day';
                                            $status_text = 'Due Today';
                                        } else {
                                            $status_color = 'info';
                                            $status_icon = 'bi-calendar';
                                            $status_text = 'Upcoming';
                                        }
                                    ?>
                                        <tr>
                                            <td>
                                                <input type="checkbox" class="trade-checkbox" value="<?php echo $trade['id']; ?>" onchange="updateBulkActions()">
                                            </td>
                                            <td>
                                                <div class="fw-semibold"><?php echo htmlspecialchars($trade['trade_reference']); ?></div>
                                                <small class="text-muted">Trade ID: <?php echo $trade['id']; ?></small>
                                            </td>
                                            <td>
                                                <div class="fw-medium"><?php echo htmlspecialchars($trade['client_name']); ?></div>
                                                <small class="text-muted"><?php echo htmlspecialchars($trade['client_cds_account']); ?></small>
                                            </td>
                                            <td>
                                                <div class="fw-medium"><?php echo htmlspecialchars($trade['counterparty_name']); ?></div>
                                                <small class="text-muted"><?php echo htmlspecialchars($trade['counterparty_cds_account']); ?></small>
                                            </td>
                                            <td>
                                                <div class="fw-medium"><?php echo htmlspecialchars($trade['security_id']); ?></div>
                                                <small class="text-muted">
                                                    <?php 
                                                    $asset_class = $trade['asset_class'];
                                                    echo ($asset_class === 'Exchange Traded Funds') ? 'ETF' : ucfirst($asset_class);
                                                    ?>
                                                </small>
                                            </td>
                                            <td>
                                                <span class="badge bg-<?php echo strtolower($trade['trade_side']) == 'buy' ? 'success' : 'danger'; ?>">
                                                    <?php echo ucfirst($trade['trade_side']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <div class="fw-bold text-success">TZS <?php echo number_format($trade['consideration'], 2); ?></div>
                                            </td>
                                            <td>
                                                <div class="fw-medium">
                                                    <?php echo date('Y-m-d', strtotime($trade['settlement_date'])); ?>
                                                </div>
                                                <small class="text-muted">
                                                    <?php 
                                                        $days_diff = (strtotime($trade['settlement_date']) - strtotime($today)) / (60 * 60 * 24);
                                                        if ($days_diff < 0) {
                                                            echo abs($days_diff) . ' days overdue';
                                                        } elseif ($days_diff == 0) {
                                                            echo 'Today';
                                                        } else {
                                                            echo $days_diff . ' days';
                                                        }
                                                    ?>
                                                </small>
                                            </td>
                                            <td>
                                                <span class="badge bg-<?php echo $status_color; ?>">
                                                    <i class="bi <?php echo $status_icon; ?> me-1"></i>
                                                    <?php echo $status_text; ?>
                                                </span>
                                                <?php if ($trade['settled_by_username']): ?>
                                                    <small class="d-block text-muted">By: <?php echo $trade['settled_by_username']; ?></small>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($trade['settlement_status'] !== 'paid' && $trade['settlement_status'] !== 'failed'): ?>
                                                    <div class="btn-group btn-group-sm">
                                                        <button type="button" class="btn btn-outline-success" onclick="markAsPaid(<?php echo $trade['id']; ?>)">
                                                            <i class="bi bi-check-lg"></i> Paid
                                                        </button>
                                                        <button type="button" class="btn btn-outline-danger" onclick="markAsFailed(<?php echo $trade['id']; ?>)">
                                                            <i class="bi bi-x-lg"></i> Failed
                                                        </button>
                                                    </div>
                                                <?php elseif ($trade['settlement_status'] === 'paid'): ?>
                                                    <button type="button" class="btn btn-outline-secondary btn-sm" onclick="markAsUnpaid(<?php echo $trade['id']; ?>)">
                                                        <i class="bi bi-arrow-counterclockwise"></i> Undo
                                                    </button>
                                                <?php elseif ($trade['settlement_status'] === 'failed'): ?>
                                                    <button type="button" class="btn btn-outline-warning btn-sm" data-bs-toggle="modal" data-bs-target="#failureDetailsModal" 
                                                            onclick="showFailureDetails(<?php echo $trade['id']; ?>, '<?php echo addslashes($trade['failure_reason']); ?>', '<?php echo addslashes($trade['action_needed']); ?>')">
                                                        <i class="bi bi-info-circle"></i> Details
                                                    </button>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
                
                <!-- Overdue Tab -->
                <div class="tab-pane fade" id="overdue" role="tabpanel">
                    <?php 
                    $overdue_trades = array_filter($settlement_trades, function($trade) {
                        return $trade['settlement_status_category'] === 'overdue' && 
                               $trade['settlement_status'] !== 'paid' && 
                               $trade['settlement_status'] !== 'failed';
                    });
                    ?>
                    <?php if (empty($overdue_trades)): ?>
                        <div class="text-center py-5">
                            <i class="bi bi-check-circle text-muted" style="font-size: 4rem; opacity: 0.3;"></i>
                            <h5 class="text-muted mt-3">No Overdue Settlements</h5>
                            <p class="text-muted">Great! All settlements are up to date.</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Trade Ref</th>
                                        <th>Client</th>
                                        <th>Counterparty</th>
                                        <th>Security</th>
                                        <th>Side</th>
                                        <th>Amount</th>
                                        <th>Settlement Date</th>
                                        <th>Days Overdue</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($overdue_trades as $trade): 
                                        $days_overdue = (strtotime($today) - strtotime($trade['settlement_date'])) / (60 * 60 * 24);
                                    ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($trade['trade_reference']); ?></td>
                                            <td><?php echo htmlspecialchars($trade['client_name']); ?></td>
                                            <td><?php echo htmlspecialchars($trade['counterparty_name']); ?></td>
                                            <td><?php echo htmlspecialchars($trade['security_id']); ?></td>
                                            <td>
                                                <span class="badge bg-<?php echo strtolower($trade['trade_side']) == 'buy' ? 'success' : 'danger'; ?>">
                                                    <?php echo ucfirst($trade['trade_side']); ?>
                                                </span>
                                            </td>
                                            <td class="fw-bold text-danger">TZS <?php echo number_format($trade['consideration'], 2); ?></td>
                                            <td><?php echo date('Y-m-d', strtotime($trade['settlement_date'])); ?></td>
                                            <td>
                                                <span class="badge bg-danger"><?php echo $days_overdue; ?> days</span>
                                            </td>
                                            <td>
                                                <div class="btn-group btn-group-sm">
                                                    <button type="button" class="btn btn-success" onclick="markAsPaid(<?php echo $trade['id']; ?>)">
                                                        <i class="bi bi-check-lg"></i> Mark Paid
                                                    </button>
                                                    <button type="button" class="btn btn-danger" onclick="markAsFailed(<?php echo $trade['id']; ?>)">
                                                        <i class="bi bi-x-lg"></i> Mark Failed
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
                
                <!-- Today Tab -->
                <div class="tab-pane fade" id="today" role="tabpanel">
                    <?php 
                    $today_trades = array_filter($settlement_trades, function($trade) {
                        return $trade['settlement_status_category'] === 'today' && 
                               $trade['settlement_status'] !== 'paid' && 
                               $trade['settlement_status'] !== 'failed';
                    });
                    ?>
                    <?php if (empty($today_trades)): ?>
                        <div class="text-center py-5">
                            <i class="bi bi-check-circle text-muted" style="font-size: 4rem; opacity: 0.3;"></i>
                            <h5 class="text-muted mt-3">No Settlements Due Today</h5>
                            <p class="text-muted">All today's settlements are processed.</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Trade Ref</th>
                                        <th>Client</th>
                                        <th>Counterparty</th>
                                        <th>Security</th>
                                        <th>Side</th>
                                        <th>Amount</th>
                                        <th>Settlement Date</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($today_trades as $trade): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($trade['trade_reference']); ?></td>
                                            <td><?php echo htmlspecialchars($trade['client_name']); ?></td>
                                            <td><?php echo htmlspecialchars($trade['counterparty_name']); ?></td>
                                            <td><?php echo htmlspecialchars($trade['security_id']); ?></td>
                                            <td>
                                                <span class="badge bg-<?php echo strtolower($trade['trade_side']) == 'buy' ? 'success' : 'danger'; ?>">
                                                    <?php echo ucfirst($trade['trade_side']); ?>
                                                </span>
                                            </td>
                                            <td class="fw-bold text-warning">TZS <?php echo number_format($trade['consideration'], 2); ?></td>
                                            <td><?php echo date('Y-m-d', strtotime($trade['settlement_date'])); ?></td>
                                            <td>
                                                <div class="btn-group btn-group-sm">
                                                    <button type="button" class="btn btn-success" onclick="markAsPaid(<?php echo $trade['id']; ?>)">
                                                        <i class="bi bi-check-lg"></i> Mark Paid
                                                    </button>
                                                    <button type="button" class="btn btn-danger" onclick="markAsFailed(<?php echo $trade['id']; ?>)">
                                                        <i class="bi bi-x-lg"></i> Mark Failed
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
                
                <!-- Paid Tab -->
                <div class="tab-pane fade" id="paid" role="tabpanel">
                    <?php 
                    $paid_trades = array_filter($settlement_trades, function($trade) {
                        return $trade['settlement_status'] === 'paid';
                    });
                    ?>
                    <?php if (empty($paid_trades)): ?>
                        <div class="text-center py-5">
                            <i class="bi bi-check-circle text-muted" style="font-size: 4rem; opacity: 0.3;"></i>
                            <h5 class="text-muted mt-3">No Paid Settlements</h5>
                            <p class="text-muted">No settlements have been marked as paid yet.</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Trade Ref</th>
                                        <th>Client</th>
                                        <th>Counterparty</th>
                                        <th>Security</th>
                                        <th>Side</th>
                                        <th>Amount</th>
                                        <th>Settlement Date</th>
                                        <th>Paid By</th>
                                        <th>Paid Date</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($paid_trades as $trade): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($trade['trade_reference']); ?></td>
                                            <td><?php echo htmlspecialchars($trade['client_name']); ?></td>
                                            <td><?php echo htmlspecialchars($trade['counterparty_name']); ?></td>
                                            <td><?php echo htmlspecialchars($trade['security_id']); ?></td>
                                            <td>
                                                <span class="badge bg-<?php echo strtolower($trade['trade_side']) == 'buy' ? 'success' : 'danger'; ?>">
                                                    <?php echo ucfirst($trade['trade_side']); ?>
                                                </span>
                                            </td>
                                            <td class="fw-bold text-success">TZS <?php echo number_format($trade['consideration'], 2); ?></td>
                                            <td><?php echo date('Y-m-d', strtotime($trade['settlement_date'])); ?></td>
                                            <td><?php echo $trade['settled_by_username'] ?? 'N/A'; ?></td>
                                            <td>
                                                <?php 
                                                if (!empty($trade['settled_at'])) {
                                                    echo date('Y-m-d H:i', strtotime($trade['settled_at']));
                                                } else {
                                                    echo 'N/A';
                                                }
                                                ?>
                                            </td>
                                            <td>
                                                <button type="button" class="btn btn-outline-secondary btn-sm" onclick="markAsUnpaid(<?php echo $trade['id']; ?>)">
                                                    <i class="bi bi-arrow-counterclockwise"></i> Undo
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
                
                <!-- Failed Tab -->
                <div class="tab-pane fade" id="failed" role="tabpanel">
                    <?php 
                    $failed_trades = array_filter($settlement_trades, function($trade) {
                        return $trade['settlement_status'] === 'failed';
                    });
                    ?>
                    <?php if (empty($failed_trades)): ?>
                        <div class="text-center py-5">
                            <i class="bi bi-check-circle text-muted" style="font-size: 4rem; opacity: 0.3;"></i>
                            <h5 class="text-muted mt-3">No Failed Settlements</h5>
                            <p class="text-muted">All settlements are processed successfully.</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Trade Ref</th>
                                        <th>Client</th>
                                        <th>Counterparty</th>
                                        <th>Security</th>
                                        <th>Side</th>
                                        <th>Amount</th>
                                        <th>Settlement Date</th>
                                        <th>Failure Reason</th>
                                        <th>Action Needed</th>
                                        <th>Details</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($failed_trades as $trade): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($trade['trade_reference']); ?></td>
                                            <td><?php echo htmlspecialchars($trade['client_name']); ?></td>
                                            <td><?php echo htmlspecialchars($trade['counterparty_name']); ?></td>
                                            <td><?php echo htmlspecialchars($trade['security_id']); ?></td>
                                            <td>
                                                <span class="badge bg-<?php echo strtolower($trade['trade_side']) == 'buy' ? 'success' : 'danger'; ?>">
                                                    <?php echo ucfirst($trade['trade_side']); ?>
                                                </span>
                                            </td>
                                            <td class="fw-bold text-dark">TZS <?php echo number_format($trade['consideration'], 2); ?></td>
                                            <td><?php echo date('Y-m-d', strtotime($trade['settlement_date'])); ?></td>
                                            <td><?php echo htmlspecialchars($trade['failure_reason']); ?></td>
                                            <td><?php echo htmlspecialchars($trade['action_needed']); ?></td>
                                            <td>
                                                <button type="button" class="btn btn-outline-warning btn-sm" data-bs-toggle="modal" data-bs-target="#failureDetailsModal" 
                                                        onclick="showFailureDetails(<?php echo $trade['id']; ?>, '<?php echo addslashes($trade['failure_reason']); ?>', '<?php echo addslashes($trade['action_needed']); ?>')">
                                                    <i class="bi bi-info-circle"></i> View
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Forms for single actions -->
<form id="paidForm" method="POST" style="display: none;">
    <input type="hidden" name="action" value="mark_paid">
    <input type="hidden" name="trade_id" id="paidTradeId">
</form>

<form id="unpaidForm" method="POST" style="display: none;">
    <input type="hidden" name="action" value="mark_unpaid">
    <input type="hidden" name="trade_id" id="unpaidTradeId">
</form>

<!-- Failure Modal -->
<div class="modal fade" id="failureModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-warning text-white">
                <h5 class="modal-title">Mark Settlement as Failed</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="failureForm" method="POST">
                <input type="hidden" name="action" value="mark_failed">
                <input type="hidden" name="trade_id" id="failureTradeId">
                
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="failure_reason" class="form-label">Failure Reason *</label>
                        <textarea class="form-control" id="failure_reason" name="failure_reason" rows="3" required 
                                  placeholder="Explain why the payment failed..."></textarea>
                    </div>
                    
                    <div class="mb-3">
                        <label for="action_needed" class="form-label">Action Needed *</label>
                        <select class="form-select" id="action_needed" name="action_needed" required>
                            <option value="">Select required action</option>
                            <option value="retry_payment">Retry Payment</option>
                            <option value="contact_client">Contact Client</option>
                            <option value="contact_counterparty">Contact Counterparty</option>
                            <option value="investigate_discrepancy">Investigate Discrepancy</option>
                            <option value="update_account_details">Update Account Details</option>
                            <option value="escalate_to_manager">Escalate to Manager</option>
                            <option value="other">Other</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-warning">Mark as Failed</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Failure Details Modal -->
<div class="modal fade" id="failureDetailsModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-dark text-white">
                <h5 class="modal-title">Failure Details</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label fw-bold">Failure Reason:</label>
                    <div class="p-3 bg-light rounded" id="detailsFailureReason"></div>
                </div>
                
                <div class="mb-3">
                    <label class="form-label fw-bold">Action Needed:</label>
                    <div class="p-3 bg-light rounded" id="detailsActionNeeded"></div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script>
// Trade ID for current actions
let currentTradeId = null;

// Bulk actions functions
function toggleSelectAll(checkbox) {
    const checkboxes = document.querySelectorAll('.trade-checkbox');
    checkboxes.forEach(cb => cb.checked = checkbox.checked);
    updateBulkActions();
}

function updateBulkActions() {
    const checkboxes = document.querySelectorAll('.trade-checkbox:checked');
    const selectedCount = checkboxes.length;
    const bulkActionsCard = document.getElementById('bulkActionsCard');
    const selectedCountSpan = document.getElementById('selectedCount');
    const bulkActionForm = document.getElementById('bulkActionForm');
    
    selectedCountSpan.textContent = selectedCount;
    
    // Update form with selected trade IDs
    const hiddenInputs = bulkActionForm.querySelectorAll('input[name="trade_ids[]"]');
    hiddenInputs.forEach(input => input.remove());
    
    Array.from(checkboxes).forEach(cb => {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'trade_ids[]';
        input.value = cb.value;
        bulkActionForm.appendChild(input);
    });
    
    // Show/hide bulk actions card
    if (selectedCount > 0) {
        bulkActionsCard.style.display = 'block';
    } else {
        bulkActionsCard.style.display = 'none';
    }
}

function clearSelection() {
    const checkboxes = document.querySelectorAll('.trade-checkbox');
    checkboxes.forEach(cb => cb.checked = false);
    document.getElementById('selectAll').checked = false;
    updateBulkActions();
}

function toggleBulkFailureFields() {
    const actionSelect = document.getElementById('bulkActionSelect');
    const failureFields = document.getElementById('bulkFailureFields');
    if (actionSelect.value === 'mark_failed') {
        failureFields.style.display = 'block';
    } else {
        failureFields.style.display = 'none';
    }
}

function resetFilters() {
    window.location.href = 'settlement.php';
}

// Function to mark a trade as paid
function markAsPaid(tradeId) {
    if (confirm('Are you sure you want to mark this trade as paid?')) {
        document.getElementById('paidTradeId').value = tradeId;
        document.getElementById('paidForm').submit();
    }
}

// Function to mark all today's trades as paid
function markAllTodayAsPaid() {
    if (confirm('Are you sure you want to mark all today\'s settlements as paid?')) {
        // Get all today's trade IDs
        let todayTradeIds = [];
        <?php 
        $today_ids = array_map(function($trade) {
            return $trade['id'];
        }, array_filter($settlement_trades, function($trade) {
            return $trade['settlement_status_category'] === 'today' && 
                   $trade['settlement_status'] !== 'paid' && 
                   $trade['settlement_status'] !== 'failed';
        }));
        if (!empty($today_ids)): ?>
            todayTradeIds = <?php echo json_encode($today_ids); ?>;
            
            // Create bulk form
            let form = document.createElement('form');
            form.method = 'POST';
            form.style.display = 'none';
            
            let actionInput = document.createElement('input');
            actionInput.type = 'hidden';
            actionInput.name = 'bulk_action';
            actionInput.value = 'mark_paid';
            form.appendChild(actionInput);
            
            todayTradeIds.forEach(tradeId => {
                let tradeInput = document.createElement('input');
                tradeInput.type = 'hidden';
                tradeInput.name = 'trade_ids[]';
                tradeInput.value = tradeId;
                form.appendChild(tradeInput);
            });
            
            document.body.appendChild(form);
            form.submit();
        <?php else: ?>
            alert('No trades due today to mark as paid.');
        <?php endif; ?>
    }
}

// Function to mark a trade as unpaid
function markAsUnpaid(tradeId) {
    if (confirm('Are you sure you want to mark this trade as unpaid? This will reverse the payment status.')) {
        document.getElementById('unpaidTradeId').value = tradeId;
        document.getElementById('unpaidForm').submit();
    }
}

// Function to show failure modal
function markAsFailed(tradeId) {
    currentTradeId = tradeId;
    document.getElementById('failureTradeId').value = tradeId;
    document.getElementById('failureForm').reset();
    
    let failureModal = new bootstrap.Modal(document.getElementById('failureModal'));
    failureModal.show();
}

// Function to show failure details
function showFailureDetails(tradeId, reason, action) {
    currentTradeId = tradeId;
    document.getElementById('detailsFailureReason').textContent = reason || 'No reason provided';
    document.getElementById('detailsActionNeeded').textContent = action || 'No action specified';
    
    let detailsModal = new bootstrap.Modal(document.getElementById('failureDetailsModal'));
    detailsModal.show();
}

// Initialize when page loads
document.addEventListener('DOMContentLoaded', function() {
    // Tooltips
    const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });
    
    // Update bulk actions on page load
    updateBulkActions();
});
</script>

<?php include '../includes/footer.php'; ?>