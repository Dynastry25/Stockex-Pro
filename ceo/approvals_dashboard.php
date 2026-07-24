<?php
require_once '../config/config.php';
require_once '../auth/auth_middleware.php';
require_once '../includes/approval_helpers.php';
require_once '../config/approval_constants.php';

require_ceo();
$db = getDBConnection();
generate_csrf_token();

$page_title = 'CEO Approval Dashboard';
$success_message = '';
$error_message = '';

// Handle POST actions for CEO approvals
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        if (isset($_POST['ceo_approve_leave'])) {
            // CEO approve/reject leave
            $leave_id = (int)$_POST['leave_id'];
            $decision = sanitize_input($_POST['decision']); // 'approve' or 'reject'
            $reason = sanitize_input($_POST['reason']);
            
            if (!in_array($decision, ['approve', 'reject'])) {
                $error_message = 'Invalid decision. Must be approve or reject.';
            } else {
                $result = ceo_approve_leave($leave_id, $decision, $_SESSION['user_id'], $reason);
                
                if ($result['success']) {
                    show_alert($result['message'], $decision == 'approve' ? 'success' : 'warning');
                } else {
                    $error_message = $result['message'];
                }
            }
            
        } elseif (isset($_POST['ceo_approve_payroll'])) {
            // CEO approve/reject payroll
            $payroll_id = (int)$_POST['payroll_id'];
            $decision = sanitize_input($_POST['decision']); // 'approve' or 'reject'
            $reason = sanitize_input($_POST['reason']);
            
            if (!in_array($decision, ['approve', 'reject'])) {
                $error_message = 'Invalid decision. Must be approve or reject.';
            } else {
                $result = ceo_approve_payroll($payroll_id, $decision, $_SESSION['user_id'], $reason);
                
                if ($result['success']) {
                    show_alert($result['message'], $decision == 'approve' ? 'success' : 'warning');
                } else {
                    $error_message = $result['message'];
                }
            }
        }
        
        // Redirect to prevent resubmission
        if (!$error_message) {
            redirect('ceo/approvals_dashboard.php');
        }
        
    } catch (Exception $e) {
        $error_message = 'Error processing approval: ' . $e->getMessage();
    }
}

// Get CEO pending approvals summary
$approvals_summary = get_ceo_pending_approvals();

// Get detailed pending leave approvals
$pending_leaves = get_ceo_pending_leaves();

// Get pending payroll approvals
try {
    $stmt = $db->query("
        SELECT p.*, CONCAT(u.first_name, ' ', u.last_name) as employee_name, u.full_name, u.employee_id as employee_code,
               d.name as department_name, 
               hr_user.full_name as submitted_by_name
        FROM payroll p
        JOIN users u ON p.user_id = u.id
        JOIN departments d ON u.department_id = d.id
        LEFT JOIN users hr_user ON p.processed_by = hr_user.id
        WHERE p.status = 'pending_ceo_approval'
        ORDER BY p.submitted_by_hr_at ASC
    ");
    $pending_payroll = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $pending_payroll = [];
    error_log("Error fetching pending payroll: " . $e->getMessage());
}

// Get pending recruitment approvals
try {
    $stmt = $db->query("
        SELECT ja.*, jp.position_name, d.name as department_name,
               hr_user.full_name as hr_recommended_by_name
        FROM job_applications ja
        JOIN job_positions jp ON ja.position_id = jp.id
        JOIN departments d ON jp.department_id = d.id
        LEFT JOIN users hr_user ON ja.hr_recommended_by = hr_user.id
        WHERE ja.ceo_approval_status = 'pending_ceo'
        ORDER BY ja.hr_recommended_at ASC
    ");
    $pending_recruitment = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $pending_recruitment = [];
    error_log("Error fetching pending recruitment: " . $e->getMessage());
}

// Get pending target approvals
try {
    $stmt = $db->query("
        SELECT pt.*, CONCAT(u.first_name, ' ', u.last_name) as employee_name, u.full_name, u.employee_id as employee_code,
               d.name as department_name
        FROM performance_targets pt
        JOIN users u ON pt.employee_id = u.id
        JOIN departments d ON u.department_id = d.id
        WHERE pt.requires_ceo_approval = TRUE AND pt.ceo_decision_status = 'pending_ceo'
        ORDER BY pt.created_at ASC
    ");
    $pending_targets = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $pending_targets = [];
    error_log("Error fetching pending targets: " . $e->getMessage());
}

// Get pending payment requests
try {
    $stmt = $db->query("
        SELECT pp.*, u.full_name as requested_by_name,
               lt.description as pay_to_desc
        FROM pending_pay pp
        LEFT JOIN users u ON pp.requested_by = u.id
        LEFT JOIN ledger_types lt ON pp.pay_to_type = lt.code
        WHERE pp.status = 'pending' AND pp.ceo_approved_at IS NULL
        ORDER BY pp.requested_at ASC
    ");
    $pending_payment_requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $pending_payment_requests = [];
    error_log("Error fetching pending payment requests: " . $e->getMessage());
}

// Get approved leave approvals
try {
    $stmt = $db->query("
        SELECT lr.*, lt.name as leave_type_name, 
               CONCAT(u.first_name, ' ', u.last_name) as employee_name, u.full_name, u.employee_id as employee_code,
               d.name as department_name, 
               hr_user.full_name as escalated_by_name,
               ceo_user.full_name as approved_by_name
        FROM leave_requests lr
        JOIN leave_types lt ON lr.leave_type_id = lt.id
        JOIN users u ON lr.employee_id = u.id
        JOIN departments d ON u.department_id = d.id
        LEFT JOIN users hr_user ON lr.finalized_by_hr = hr_user.id
        LEFT JOIN users ceo_user ON lr.ceo_decided_by = ceo_user.id
        WHERE lr.ceo_decision_status = 'approved'
        ORDER BY lr.ceo_decided_at DESC
        LIMIT 50
    ");
    $approved_leaves = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $approved_leaves = [];
    error_log("Error fetching approved leaves: " . $e->getMessage());
}

// Get approved payroll approvals
try {
    $stmt = $db->query("
        SELECT p.*, CONCAT(u.first_name, ' ', u.last_name) as employee_name, u.full_name, u.employee_id as employee_code,
               d.name as department_name, 
               hr_user.full_name as submitted_by_name,
               ceo_user.full_name as approved_by_name
        FROM payroll p
        JOIN users u ON p.user_id = u.id
        JOIN departments d ON u.department_id = d.id
        LEFT JOIN users hr_user ON p.processed_by = hr_user.id
        LEFT JOIN users ceo_user ON p.ceo_approved_by = ceo_user.id
        WHERE p.status = 'approved_by_ceo'
        ORDER BY p.ceo_approved_at DESC
        LIMIT 50
    ");
    $approved_payroll = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $approved_payroll = [];
    error_log("Error fetching approved payroll: " . $e->getMessage());
}

// Get approved recruitment approvals
try {
    $stmt = $db->query("
        SELECT ja.*, jp.position_name, d.name as department_name,
               hr_user.full_name as hr_recommended_by_name,
               ceo_user.full_name as ceo_approved_by_name
        FROM job_applications ja
        JOIN job_positions jp ON ja.position_id = jp.id
        JOIN departments d ON jp.department_id = d.id
        LEFT JOIN users hr_user ON ja.hr_recommended_by = hr_user.id
        LEFT JOIN users ceo_user ON ja.ceo_approved_by = ceo_user.id
        WHERE ja.ceo_approval_status = 'approved'
        ORDER BY ja.ceo_approved_at DESC
        LIMIT 50
    ");
    $approved_recruitment = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $approved_recruitment = [];
    error_log("Error fetching approved recruitment: " . $e->getMessage());
}

// Get approved target approvals
try {
    $stmt = $db->query("
        SELECT pt.*, CONCAT(u.first_name, ' ', u.last_name) as employee_name, u.full_name, u.employee_id as employee_code,
               d.name as department_name,
               ceo_user.full_name as approved_by_name
        FROM performance_targets pt
        JOIN users u ON pt.employee_id = u.id
        JOIN departments d ON u.department_id = d.id
        LEFT JOIN users ceo_user ON pt.ceo_decided_by = ceo_user.id
        WHERE pt.ceo_decision_status = 'approved'
        ORDER BY pt.ceo_decided_at DESC
        LIMIT 50
    ");
    $approved_targets = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $approved_targets = [];
    error_log("Error fetching approved targets: " . $e->getMessage());
}
?>

<?php include '../includes/header.php'; ?>

<input type="hidden" name="csrf_token" id="csrfToken" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">

<div class="container-fluid px-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h3 mb-0 text-gray-800">
                <i class="bi bi-clipboard-check me-2"></i>CEO Approval Dashboard
            </h1>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>ceo/dashboard.php">CEO Dashboard</a></li>
                    <li class="breadcrumb-item active">Approvals</li>
                </ol>
            </nav>
        </div>
        
        <div class="d-flex gap-2">
            <button class="btn btn-outline-primary" onclick="window.location.reload();">
                <i class="bi bi-arrow-clockwise me-1"></i>Refresh
            </button>
        </div>
    </div>

    <?php if ($error_message): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="bi bi-exclamation-triangle me-2"></i><?php echo $error_message; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Approval Summary Cards -->
    <div class="row mb-4">
        <div class="col">
            <div class="card border-left-warning shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-warning text-uppercase mb-1">Leave Approvals</div>
                            <div class="mb-1">
                                <small class="text-muted">Pending: <strong><?php echo $approvals_summary['leaves']; ?></strong></small>
                            </div>
                            <div>
                                <small class="text-muted">Approved: <strong><?php echo count($approved_leaves); ?></strong></small>
                            </div>
                        </div>
                        <div class="col-auto">
                            <i class="bi bi-calendar-check fs-2 text-warning"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col">
            <div class="card border-left-success shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-success text-uppercase mb-1">Payroll Approvals</div>
                            <div class="mb-1">
                                <small class="text-muted">Pending: <strong><?php echo $approvals_summary['payroll']; ?></strong></small>
                            </div>
                            <div>
                                <small class="text-muted">Approved: <strong><?php echo count($approved_payroll); ?></strong></small>
                            </div>
                        </div>
                        <div class="col-auto">
                            <i class="bi bi-cash-coin fs-2 text-success"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col">
            <div class="card border-left-info shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-info text-uppercase mb-1">Recruitment</div>
                            <div class="mb-1">
                                <small class="text-muted">Pending: <strong><?php echo $approvals_summary['recruitment']; ?></strong></small>
                            </div>
                            <div>
                                <small class="text-muted">Approved: <strong><?php echo count($approved_recruitment); ?></strong></small>
                            </div>
                        </div>
                        <div class="col-auto">
                            <i class="bi bi-people fs-2 text-info"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col">
            <div class="card border-left-primary shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">Performance Targets</div>
                            <div class="mb-1">
                                <small class="text-muted">Pending: <strong><?php echo count($pending_targets); ?></strong></small>
                            </div>
                            <div>
                                <small class="text-muted">Approved: <strong><?php echo count($approved_targets); ?></strong></small>
                            </div>
                        </div>
                        <div class="col-auto">
                            <i class="bi bi-bullseye fs-2 text-primary"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col">
            <div class="card border-left-danger shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-danger text-uppercase mb-1">Payment Requests</div>
                            <div class="mb-1">
                                <small class="text-muted">Pending: <strong><?php echo count($pending_payment_requests); ?></strong></small>
                            </div>
                        </div>
                        <div class="col-auto">
                            <i class="bi bi-credit-card fs-2 text-danger"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Tabs for Pending and Approved -->
    <ul class="nav nav-tabs mb-4" id="approvalTabs" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link active" id="pending-tab" data-bs-toggle="tab" data-bs-target="#pending" type="button" role="tab" aria-controls="pending" aria-selected="true">
                <i class="bi bi-exclamation-triangle me-2"></i>Pending Approvals 
                <span class="badge bg-danger"><?php echo $approvals_summary['total'] + count($pending_payment_requests); ?></span>
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="approved-tab" data-bs-toggle="tab" data-bs-target="#approved" type="button" role="tab" aria-controls="approved" aria-selected="false">
                <i class="bi bi-check-circle me-2"></i>Approved Items 
                <span class="badge bg-success"><?php echo count($approved_leaves) + count($approved_payroll) + count($approved_recruitment) + count($approved_targets); ?></span>
            </button>
        </li>
    </ul>

    <div class="tab-content" id="approvalTabsContent">
        <!-- Pending Tab -->
        <div class="tab-pane fade show active" id="pending" role="tabpanel" aria-labelledby="pending-tab">
            <?php if ($approvals_summary['total'] == 0 && empty($pending_payment_requests)): ?>
                <div class="card">
                    <div class="card-body text-center py-5">
                        <i class="bi bi-check-circle display-1 text-success mb-3"></i>
                        <h4 class="text-muted">All Caught Up!</h4>
                        <p class="text-muted">No pending approvals at this time. Great job staying on top of things!</p>
                    </div>
                </div>
            <?php else: ?>

                <!-- Pending Leave Approvals -->
                <div class="card mb-4">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">
                            <i class="bi bi-calendar-check me-2 text-warning"></i>
                            Pending Leave Approvals (<?php echo count($pending_leaves); ?>)
                        </h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($pending_leaves)): ?>
                            <div class="text-center py-4">
                                <i class="bi bi-calendar-check display-1 text-muted mb-3"></i>
                                <p class="text-muted">No pending leave approvals at this time.</p>
                                <small class="text-muted">Escalated leave requests from HR will appear here for your review.</small>
                            </div>
                        <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead class="table-light">
                                    <tr>
                                        <th>Employee</th>
                                        <th>Leave Type</th>
                                        <th>Period</th>
                                        <th>Days</th>
                                        <th>HR Reason</th>
                                        <th>Escalated</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($pending_leaves as $leave): ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo htmlspecialchars($leave['employee_name']); ?></strong>
                                                <br><small class="text-muted"><?php echo htmlspecialchars($leave['employee_code']); ?></small>
                                                <br><small class="text-muted"><?php echo htmlspecialchars($leave['department_name']); ?></small>
                                            </td>
                                            <td>
                                                <span class="badge bg-secondary"><?php echo htmlspecialchars($leave['leave_type_name']); ?></span>
                                            </td>
                                            <td>
                                                <small>
                                                    <?php echo format_date($leave['start_date']); ?><br>
                                                    <strong>to</strong><br>
                                                    <?php echo format_date($leave['end_date']); ?>
                                                </small>
                                            </td>
                                            <td>
                                                <strong class="<?php echo $leave['total_days'] > 7 ? 'text-warning' : ''; ?>">
                                                    <?php echo $leave['total_days']; ?>
                                                </strong>
                                                <small class="text-muted">day<?php echo $leave['total_days'] > 1 ? 's' : ''; ?></small>
                                            </td>
                                            <td>
                                                <small class="text-muted">
                                                    <?php echo htmlspecialchars($leave['finality_reason'] ?? 'Escalated by HR'); ?>
                                                </small>
                                            </td>
                                            <td>
                                                <small class="text-muted">
                                                    <?php echo format_date($leave['finalized_by_hr_at']); ?>
                                                    <br>by <?php echo htmlspecialchars($leave['escalated_by_name'] ?? 'HR System'); ?>
                                                </small>
                                            </td>
                                            <td>
                                                <div class="btn-group btn-group-sm">
                                                    <button class="btn btn-outline-success" 
                                                            onclick="approveLeave(<?php echo $leave['id']; ?>, '<?php echo htmlspecialchars($leave['employee_name']); ?>', <?php echo $leave['total_days']; ?>)"
                                                            title="Approve Leave">
                                                        <i class="bi bi-check-lg"></i> Approve
                                                    </button>
                                                    <button class="btn btn-outline-danger" 
                                                            onclick="rejectLeave(<?php echo $leave['id']; ?>, '<?php echo htmlspecialchars($leave['employee_name']); ?>')"
                                                            title="Reject Leave">
                                                        <i class="bi bi-x-lg"></i> Reject
                                                    </button>
                                                    <button class="btn btn-outline-info" 
                                                            onclick="viewLeaveDetail(<?php echo htmlspecialchars(json_encode($leave)); ?>)"
                                                            title="View Details">
                                                        <i class="bi bi-eye"></i>
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
                </div>

                <!-- Pending Payroll Approvals -->
                <div class="card mb-4">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">
                            <i class="bi bi-cash-coin me-2 text-success"></i>
                            Pending Payroll Approvals (<?php echo count($pending_payroll); ?>)
                        </h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($pending_payroll)): ?>
                            <div class="text-center py-4">
                                <i class="bi bi-cash-coin display-1 text-muted mb-3"></i>
                                <p class="text-muted">No pending payroll approvals at this time.</p>
                                <small class="text-muted">Payroll submissions from HR will appear here for your review and approval.</small>
                            </div>
                        <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead class="table-light">
                                    <tr>
                                        <th>Employee</th>
                                        <th>Pay Period</th>
                                        <th>Gross Pay</th>
                                        <th>Net Pay</th>
                                        <th>Submitted By</th>
                                        <th>Submitted</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($pending_payroll as $payroll): ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo htmlspecialchars($payroll['employee_name']); ?></strong>
                                                <br><small class="text-muted"><?php echo htmlspecialchars($payroll['employee_code']); ?></small>
                                                <br><small class="text-muted"><?php echo htmlspecialchars($payroll['department_name']); ?></small>
                                            </td>
                                            <td>
                                                <small>
                                                    <?php echo format_date($payroll['pay_period_start']); ?><br>
                                                    <strong>to</strong><br>
                                                    <?php echo format_date($payroll['pay_period_end']); ?>
                                                </small>
                                            </td>
                                            <td>
                                                <strong><?php echo number_format($payroll['gross_pay'], 2); ?></strong>
                                                <small class="text-muted"><?php echo htmlspecialchars($payroll['currency'] ?? 'TZS'); ?></small>
                                            </td>
                                            <td>
                                                <strong class="text-success"><?php echo number_format($payroll['net_pay'], 2); ?></strong>
                                                <small class="text-muted"><?php echo htmlspecialchars($payroll['currency'] ?? 'TZS'); ?></small>
                                            </td>
                                            <td>
                                                <small><?php echo htmlspecialchars($payroll['submitted_by_name'] ?? 'HR System'); ?></small>
                                            </td>
                                            <td>
                                                <small class="text-muted">
                                                    <?php echo format_date($payroll['submitted_by_hr_at']); ?>
                                                </small>
                                            </td>
                                            <td>
                                                <div class="btn-group btn-group-sm">
                                                    <button class="btn btn-outline-success" 
                                                            onclick="approvePayroll(<?php echo $payroll['id']; ?>, '<?php echo htmlspecialchars($payroll['employee_name']); ?>', <?php echo $payroll['net_pay']; ?>)"
                                                            title="Approve Payroll">
                                                        <i class="bi bi-check-lg"></i> Approve
                                                    </button>
                                                    <button class="btn btn-outline-danger" 
                                                            onclick="rejectPayroll(<?php echo $payroll['id']; ?>, '<?php echo htmlspecialchars($payroll['employee_name']); ?>')"
                                                            title="Reject Payroll">
                                                        <i class="bi bi-x-lg"></i> Reject
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
                </div>

                <!-- Pending Payment Requests -->
                <div class="card mb-4">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">
                            <i class="bi bi-credit-card me-2 text-danger"></i>
                            Pending Payment Requests (<?php echo count($pending_payment_requests); ?>)
                        </h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($pending_payment_requests)): ?>
                            <div class="text-center py-4">
                                <i class="bi bi-credit-card display-1 text-muted mb-3"></i>
                                <p class="text-muted">No pending payment requests at this time.</p>
                                <small class="text-muted">Payment requests from HR will appear here for your review and approval.</small>
                            </div>
                        <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead class="table-light">
                                    <tr>
                                        <th>Request #</th>
                                        <th>Subject</th>
                                        <th>Payee</th>
                                        <th>Amount</th>
                                        <th>Requested By</th>
                                        <th>Date</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($pending_payment_requests as $pr): ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo htmlspecialchars($pr['request_no']); ?></strong>
                                            </td>
                                            <td>
                                                <div class="text-truncate" style="max-width: 180px;">
                                                    <?php echo htmlspecialchars($pr['subject']); ?>
                                                </div>
                                                <small class="text-muted"><?php echo htmlspecialchars($pr['pay_to_desc'] ?? $pr['pay_to_type']); ?></small>
                                            </td>
                                            <td>
                                                <?php echo htmlspecialchars($pr['payee_name']); ?>
                                                <br><small class="text-muted"><?php echo htmlspecialchars($pr['payee_account_no'] ?? ''); ?></small>
                                            </td>
                                            <td>
                                                <strong class="text-success">
                                                    <?php echo number_format($pr['amount_paid'], 2); ?>
                                                    <?php echo htmlspecialchars($pr['currency']); ?>
                                                </strong>
                                            </td>
                                            <td>
                                                <small><?php echo htmlspecialchars($pr['requested_by_name'] ?? 'Unknown'); ?></small>
                                            </td>
                                            <td>
                                                <small class="text-muted">
                                                    <?php echo date('M d, Y', strtotime($pr['requested_at'])); ?>
                                                </small>
                                            </td>
                                            <td>
                                                <div class="btn-group btn-group-sm">
                                                    <button class="btn btn-outline-success"
                                                            onclick="approvePaymentRequest(<?php echo (int)$pr['id']; ?>, '<?php echo htmlspecialchars(addslashes($pr['request_no'])); ?>', '<?php echo htmlspecialchars(addslashes($pr['payee_name'])); ?>', <?php echo (float)$pr['amount_paid']; ?>, '<?php echo htmlspecialchars(addslashes($pr['currency'])); ?>')"
                                                            title="Approve Payment Request">
                                                        <i class="bi bi-check-lg"></i> Approve
                                                    </button>
                                                    <button class="btn btn-outline-danger"
                                                            onclick="rejectPaymentRequest(<?php echo (int)$pr['id']; ?>, '<?php echo htmlspecialchars(addslashes($pr['request_no'])); ?>', '<?php echo htmlspecialchars(addslashes($pr['payee_name'])); ?>')"
                                                            title="Reject Payment Request">
                                                        <i class="bi bi-x-lg"></i> Reject
                                                    </button>
                                                    <button class="btn btn-outline-info"
                                                            onclick="viewPaymentRequest(<?php echo (int)$pr['id']; ?>)"
                                                            title="View Details">
                                                        <i class="bi bi-eye"></i>
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
                </div>

            <?php endif; ?>
        </div>

        <!-- Approved Tab -->
        <div class="tab-pane fade" id="approved" role="tabpanel" aria-labelledby="approved-tab">
            <?php $total_approved = count($approved_leaves) + count($approved_payroll) + count($approved_recruitment) + count($approved_targets); ?>
            <?php if ($total_approved == 0): ?>
                <div class="card">
                    <div class="card-body text-center py-5">
                        <i class="bi bi-check-circle display-1 text-muted mb-3"></i>
                        <h4 class="text-muted">No Approved Items</h4>
                        <p class="text-muted">Approved items will appear here once you start approving requests.</p>
                    </div>
                </div>
            <?php else: ?>

                <!-- Approved Leave Approvals -->
                <div class="card mb-4">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">
                            <i class="bi bi-calendar-check me-2 text-success"></i>
                            Approved Leave Requests (<?php echo count($approved_leaves); ?>)
                        </h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($approved_leaves)): ?>
                            <div class="text-center py-4">
                                <i class="bi bi-calendar-check display-1 text-muted mb-3"></i>
                                <p class="text-muted">No approved leave requests.</p>
                            </div>
                        <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead class="table-light">
                                    <tr>
                                        <th>Employee</th>
                                        <th>Leave Type</th>
                                        <th>Period</th>
                                        <th>Days</th>
                                        <th>Approved By</th>
                                        <th>Approved</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($approved_leaves as $leave): ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo htmlspecialchars($leave['employee_name']); ?></strong>
                                                <br><small class="text-muted"><?php echo htmlspecialchars($leave['employee_code']); ?></small>
                                                <br><small class="text-muted"><?php echo htmlspecialchars($leave['department_name']); ?></small>
                                            </td>
                                            <td>
                                                <span class="badge bg-secondary"><?php echo htmlspecialchars($leave['leave_type_name']); ?></span>
                                            </td>
                                            <td>
                                                <small>
                                                    <?php echo format_date($leave['start_date']); ?><br>
                                                    <strong>to</strong><br>
                                                    <?php echo format_date($leave['end_date']); ?>
                                                </small>
                                            </td>
                                            <td>
                                                <strong><?php echo $leave['total_days']; ?></strong>
                                                <small class="text-muted">day<?php echo $leave['total_days'] > 1 ? 's' : ''; ?></small>
                                            </td>
                                            <td>
                                                <small><?php echo htmlspecialchars($leave['approved_by_name'] ?? 'CEO'); ?></small>
                                            </td>
                                            <td>
                                                <small class="text-muted">
                                                    <?php echo format_date($leave['ceo_decided_at']); ?>
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

                <!-- Approved Payroll Approvals -->
                <div class="card mb-4">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">
                            <i class="bi bi-cash-coin me-2 text-success"></i>
                            Approved Payroll (<?php echo count($approved_payroll); ?>)
                        </h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($approved_payroll)): ?>
                            <div class="text-center py-4">
                                <i class="bi bi-cash-coin display-1 text-muted mb-3"></i>
                                <p class="text-muted">No approved payroll items.</p>
                            </div>
                        <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead class="table-light">
                                    <tr>
                                        <th>Employee</th>
                                        <th>Pay Period</th>
                                        <th>Gross Pay</th>
                                        <th>Net Pay</th>
                                        <th>Submitted By</th>
                                        <th>Approved By</th>
                                        <th>Approved</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($approved_payroll as $payroll): ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo htmlspecialchars($payroll['employee_name']); ?></strong>
                                                <br><small class="text-muted"><?php echo htmlspecialchars($payroll['employee_code']); ?></small>
                                                <br><small class="text-muted"><?php echo htmlspecialchars($payroll['department_name']); ?></small>
                                            </td>
                                            <td>
                                                <small>
                                                    <?php echo format_date($payroll['pay_period_start']); ?><br>
                                                    <strong>to</strong><br>
                                                    <?php echo format_date($payroll['pay_period_end']); ?>
                                                </small>
                                            </td>
                                            <td>
                                                <strong><?php echo number_format($payroll['gross_pay'], 2); ?></strong>
                                                <small class="text-muted"><?php echo htmlspecialchars($payroll['currency'] ?? 'TZS'); ?></small>
                                            </td>
                                            <td>
                                                <strong class="text-success"><?php echo number_format($payroll['net_pay'], 2); ?></strong>
                                                <small class="text-muted"><?php echo htmlspecialchars($payroll['currency'] ?? 'TZS'); ?></small>
                                            </td>
                                            <td>
                                                <small><?php echo htmlspecialchars($payroll['submitted_by_name'] ?? 'HR System'); ?></small>
                                            </td>
                                            <td>
                                                <small><?php echo htmlspecialchars($payroll['approved_by_name'] ?? 'CEO'); ?></small>
                                            </td>
                                            <td>
                                                <small class="text-muted">
                                                    <?php echo format_date($payroll['ceo_approved_at']); ?>
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

                <!-- Approved Recruitment Approvals -->
                <div class="card mb-4">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">
                            <i class="bi bi-people me-2 text-success"></i>
                            Approved Recruitment (<?php echo count($approved_recruitment); ?>)
                        </h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($approved_recruitment)): ?>
                            <div class="text-center py-4">
                                <i class="bi bi-people display-1 text-muted mb-3"></i>
                                <p class="text-muted">No approved recruitment items.</p>
                            </div>
                        <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead class="table-light">
                                    <tr>
                                        <th>Position</th>
                                        <th>Department</th>
                                        <th>Candidate</th>
                                        <th>Recommended By</th>
                                        <th>Approved By</th>
                                        <th>Approved</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($approved_recruitment as $recruitment): ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo htmlspecialchars($recruitment['position_name']); ?></strong>
                                                <br><small class="text-muted"><?php echo htmlspecialchars($recruitment['department_name']); ?></small>
                                            </td>
                                            <td>
                                                <small><?php echo htmlspecialchars($recruitment['first_name'] . ' ' . $recruitment['last_name']); ?></small>
                                            </td>
                                            <td>
                                                <small><?php echo htmlspecialchars($recruitment['hr_recommended_by_name']); ?></small>
                                            </td>
                                            <td>
                                                <small><?php echo htmlspecialchars($recruitment['ceo_approved_by_name']); ?></small>
                                            </td>
                                            <td>
                                                <small class="text-muted">
                                                    <?php echo format_date($recruitment['ceo_approved_at']); ?>
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

                <!-- Approved Target Approvals -->
                <div class="card mb-4">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">
                            <i class="bi bi-bullseye me-2 text-success"></i>
                            Approved Performance Targets (<?php echo count($approved_targets); ?>)
                        </h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($approved_targets)): ?>
                            <div class="text-center py-4">
                                <i class="bi bi-bullseye display-1 text-muted mb-3"></i>
                                <p class="text-muted">No approved performance targets.</p>
                            </div>
                        <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead class="table-light">
                                    <tr>
                                        <th>Employee</th>
                                        <th>Target Title</th>
                                        <th>Target Value</th>
                                        <th>Approved By</th>
                                        <th>Approved</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($approved_targets as $target): ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo htmlspecialchars($target['employee_name']); ?></strong>
                                                <br><small class="text-muted"><?php echo htmlspecialchars($target['employee_code']); ?></small>
                                                <br><small class="text-muted"><?php echo htmlspecialchars($target['department_name']); ?></small>
                                            </td>
                                            <td>
                                                <strong><?php echo htmlspecialchars($target['title']); ?></strong>
                                            </td>
                                            <td>
                                                <strong><?php echo htmlspecialchars($target['target_value']); ?></strong>
                                                <small class="text-muted"><?php echo htmlspecialchars($target['unit']); ?></small>
                                            </td>
                                            <td>
                                                <small><?php echo htmlspecialchars($target['approved_by_name']); ?></small>
                                            </td>
                                            <td>
                                                <small class="text-muted">
                                                    <?php echo format_date($target['ceo_decided_at']); ?>
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

            <?php endif; ?>
        </div>
    </div>
</div>

<!-- CEO Leave Approval Modal -->
<div class="modal fade" id="ceoLeaveApprovalModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="leaveApprovalTitle">
                    <i class="bi bi-calendar-check me-2"></i>CEO Leave Decision
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="ceoLeaveApprovalForm" method="POST">
                <div class="modal-body">
                    <input type="hidden" name="ceo_approve_leave" value="1">
                    <input type="hidden" name="leave_id" id="ceoLeaveId">
                    <input type="hidden" name="decision" id="ceoLeaveDecision">
                    
                    <div id="leaveApprovalContent">
                        <!-- Content will be populated by JavaScript -->
                    </div>
                    
                    <div class="mb-3">
                        <label for="ceoLeaveReason" class="form-label">Reason <span class="text-muted">(Optional)</span></label>
                        <textarea class="form-control" name="reason" id="ceoLeaveReason" rows="3" 
                                  placeholder="Provide reason for your decision (optional but recommended)..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn" id="ceoLeaveActionBtn">Confirm Decision</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- CEO Payroll Approval Modal -->
<div class="modal fade" id="ceoPayrollApprovalModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="payrollApprovalTitle">
                    <i class="bi bi-cash-coin me-2"></i>CEO Payroll Decision
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="ceoPayrollApprovalForm" method="POST">
                <div class="modal-body">
                    <input type="hidden" name="ceo_approve_payroll" value="1">
                    <input type="hidden" name="payroll_id" id="ceoPayrollId">
                    <input type="hidden" name="decision" id="ceoPayrollDecision">
                    
                    <div id="payrollApprovalContent">
                        <!-- Content will be populated by JavaScript -->
                    </div>
                    
                    <div class="mb-3">
                        <label for="ceoPayrollReason" class="form-label">Reason <span class="text-muted">(Optional for approval, required for rejection)</span></label>
                        <textarea class="form-control" name="reason" id="ceoPayrollReason" rows="3" 
                                  placeholder="Provide reason for your decision..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn" id="ceoPayrollActionBtn">Confirm Decision</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- CEO Payment Request Approval Modal -->
<div class="modal fade" id="ceoPaymentRequestModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="paymentRequestApprovalTitle">
                    <i class="bi bi-credit-card me-2"></i>CEO Payment Decision
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="ceoPaymentRequestForm">
                <div class="modal-body">
                    <input type="hidden" name="action" id="prAction">
                    <input type="hidden" name="request_id" id="prRequestId">
                    
                    <div id="paymentRequestApprovalContent">
                        <!-- Content will be populated by JavaScript -->
                    </div>
                    
                    <div class="mb-3">
                        <label for="prReason" class="form-label">Reason <span class="text-muted">(Optional for approval, required for rejection)</span></label>
                        <textarea class="form-control" name="notes" id="prReason" rows="3"
                                  placeholder="Provide reason for your decision..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn" id="prActionBtn">Confirm Decision</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- CEO Payment Request View Modal -->
<div class="modal fade" id="ceoPaymentRequestViewModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-eye me-2"></i>Payment Request Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="prViewContent">
                <!-- Content loaded via AJAX -->
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script>
// CEO Leave Approval Functions
function approveLeave(leaveId, employeeName, totalDays) {
    document.getElementById('ceoLeaveId').value = leaveId;
    document.getElementById('ceoLeaveDecision').value = 'approve';
    document.getElementById('leaveApprovalTitle').innerHTML = '<i class="bi bi-check-circle me-2 text-success"></i>Approve Leave Request';
    
    document.getElementById('leaveApprovalContent').innerHTML = `
        <div class="alert alert-success">
            <i class="bi bi-check-circle me-2"></i>
            <strong>Approve Leave Request</strong>
            <p class="mb-0 mt-2">You are about to approve the leave request for <strong>${employeeName}</strong> (${totalDays} day${totalDays > 1 ? 's' : ''}).</p>
        </div>
    `;
    
    document.getElementById('ceoLeaveActionBtn').className = 'btn btn-success';
    document.getElementById('ceoLeaveActionBtn').innerHTML = '<i class="bi bi-check-lg me-1"></i>Approve Leave';
    document.getElementById('ceoLeaveReason').required = false;
    
    new bootstrap.Modal(document.getElementById('ceoLeaveApprovalModal')).show();
}

function rejectLeave(leaveId, employeeName) {
    document.getElementById('ceoLeaveId').value = leaveId;
    document.getElementById('ceoLeaveDecision').value = 'reject';
    document.getElementById('leaveApprovalTitle').innerHTML = '<i class="bi bi-x-circle me-2 text-danger"></i>Reject Leave Request';
    
    document.getElementById('leaveApprovalContent').innerHTML = `
        <div class="alert alert-warning">
            <i class="bi bi-exclamation-triangle me-2"></i>
            <strong>Reject Leave Request</strong>
            <p class="mb-0 mt-2">You are about to reject the leave request for <strong>${employeeName}</strong>.</p>
        </div>
    `;
    
    document.getElementById('ceoLeaveActionBtn').className = 'btn btn-danger';
    document.getElementById('ceoLeaveActionBtn').innerHTML = '<i class="bi bi-x-lg me-1"></i>Reject Leave';
    document.getElementById('ceoLeaveReason').required = true;
    document.getElementById('ceoLeaveReason').placeholder = 'Rejection reason is required...';
    
    new bootstrap.Modal(document.getElementById('ceoLeaveApprovalModal')).show();
}

// CEO Payroll Approval Functions
function approvePayroll(payrollId, employeeName, netPay) {
    document.getElementById('ceoPayrollId').value = payrollId;
    document.getElementById('ceoPayrollDecision').value = 'approve';
    document.getElementById('payrollApprovalTitle').innerHTML = '<i class="bi bi-check-circle me-2 text-success"></i>Approve Payroll';
    
    document.getElementById('payrollApprovalContent').innerHTML = `
        <div class="alert alert-success">
            <i class="bi bi-check-circle me-2"></i>
            <strong>Approve Payroll</strong>
            <p class="mb-0 mt-2">You are about to approve the payroll for <strong>${employeeName}</strong> with net pay of <strong>${netPay.toLocaleString()}</strong>.</p>
        </div>
    `;
    
    document.getElementById('ceoPayrollActionBtn').className = 'btn btn-success';
    document.getElementById('ceoPayrollActionBtn').innerHTML = '<i class="bi bi-check-lg me-1"></i>Approve Payroll';
    document.getElementById('ceoPayrollReason').required = false;
    
    new bootstrap.Modal(document.getElementById('ceoPayrollApprovalModal')).show();
}

function rejectPayroll(payrollId, employeeName) {
    document.getElementById('ceoPayrollId').value = payrollId;
    document.getElementById('ceoPayrollDecision').value = 'reject';
    document.getElementById('payrollApprovalTitle').innerHTML = '<i class="bi bi-x-circle me-2 text-danger"></i>Reject Payroll';
    
    document.getElementById('payrollApprovalContent').innerHTML = `
        <div class="alert alert-warning">
            <i class="bi bi-exclamation-triangle me-2"></i>
            <strong>Reject Payroll</strong>
            <p class="mb-0 mt-2">You are about to reject the payroll for <strong>${employeeName}</strong>.</p>
        </div>
    `;
    
    document.getElementById('ceoPayrollActionBtn').className = 'btn btn-danger';
    document.getElementById('ceoPayrollActionBtn').innerHTML = '<i class="bi bi-x-lg me-1"></i>Reject Payroll';
    document.getElementById('ceoPayrollReason').required = true;
    document.getElementById('ceoPayrollReason').placeholder = 'Rejection reason is required...';
    
    new bootstrap.Modal(document.getElementById('ceoPayrollApprovalModal')).show();
}

// View leave details
function viewLeaveDetail(leave) {
    alert(`Leave Details:\n\nEmployee: ${leave.employee_name}\nLeave Type: ${leave.leave_type_name}\nDuration: ${leave.total_days} days\nReason: ${leave.reason}\n\nFull details view would be implemented with a dedicated modal.`);
}

// CEO Payment Request Approval Functions
function approvePaymentRequest(requestId, requestNo, payeeName, amount, currency) {
    document.getElementById('prAction').value = 'approve';
    document.getElementById('prRequestId').value = requestId;
    document.getElementById('paymentRequestApprovalTitle').innerHTML = '<i class="bi bi-check-circle me-2 text-success"></i>Approve Payment Request';
    
    document.getElementById('paymentRequestApprovalContent').innerHTML = `
        <div class="alert alert-success">
            <i class="bi bi-check-circle me-2"></i>
            <strong>Approve Payment Request</strong>
            <p class="mb-0 mt-2">You are about to approve payment request <strong>${requestNo}</strong></p>
        </div>
        <table class="table table-sm table-borderless">
            <tr><td class="fw-bold" style="width:40%">Request No:</td><td>${requestNo}</td></tr>
            <tr><td class="fw-bold">Payee:</td><td>${payeeName}</td></tr>
            <tr><td class="fw-bold">Amount:</td><td class="fw-bold text-success">${parseFloat(amount).toLocaleString()} ${currency}</td></tr>
        </table>
    `;
    
    document.getElementById('prActionBtn').className = 'btn btn-success';
    document.getElementById('prActionBtn').innerHTML = '<i class="bi bi-check-lg me-1"></i>Approve Payment';
    document.getElementById('prReason').required = false;
    document.getElementById('prReason').value = '';
    
    new bootstrap.Modal(document.getElementById('ceoPaymentRequestModal')).show();
}

function rejectPaymentRequest(requestId, requestNo, payeeName) {
    document.getElementById('prAction').value = 'reject';
    document.getElementById('prRequestId').value = requestId;
    document.getElementById('paymentRequestApprovalTitle').innerHTML = '<i class="bi bi-x-circle me-2 text-danger"></i>Reject Payment Request';
    
    document.getElementById('paymentRequestApprovalContent').innerHTML = `
        <div class="alert alert-danger">
            <i class="bi bi-exclamation-triangle me-2"></i>
            <strong>Reject Payment Request</strong>
            <p class="mb-0 mt-2">You are about to reject payment request <strong>${requestNo}</strong> for <strong>${payeeName}</strong>.</p>
        </div>
    `;
    
    document.getElementById('prActionBtn').className = 'btn btn-danger';
    document.getElementById('prActionBtn').innerHTML = '<i class="bi bi-x-lg me-1"></i>Reject Payment';
    document.getElementById('prReason').required = true;
    document.getElementById('prReason').value = '';
    document.getElementById('prReason').placeholder = 'Rejection reason is required...';
    
    new bootstrap.Modal(document.getElementById('ceoPaymentRequestModal')).show();
}

function viewPaymentRequest(requestId) {
    const formData = new FormData();
    formData.append('request_id', requestId);
    formData.append('action', 'get_request_details');
    formData.append('csrf_token', document.getElementById('csrfToken').value);

    fetch('<?php echo BASE_URL; ?>ceo/dashboard.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            const r = data.data;
            document.getElementById('prViewContent').innerHTML = `
                <div class="row">
                    <div class="col-md-6">
                        <h6 class="text-primary">Request Information</h6>
                        <table class="table table-sm table-borderless">
                            <tr><td class="fw-bold" style="width:40%">Request No:</td><td><strong>${r.request_no}</strong></td></tr>
                            <tr><td class="fw-bold">Subject:</td><td>${r.subject || '-'}</td></tr>
                            <tr><td class="fw-bold">Date:</td><td>${r.requested_at ? new Date(r.requested_at).toLocaleDateString() : '-'}</td></tr>
                            <tr><td class="fw-bold">Requested By:</td><td>${r.requested_by_fullname || r.requested_by_username || 'N/A'}</td></tr>
                        </table>
                    </div>
                    <div class="col-md-6">
                        <h6 class="text-primary">Payment Details</h6>
                        <table class="table table-sm table-borderless">
                            <tr><td class="fw-bold" style="width:40%">Pay To:</td><td>${r.pay_to_desc || r.pay_to_type || '-'}</td></tr>
                            <tr><td class="fw-bold">Payee:</td><td>${r.payee_name || '-'}</td></tr>
                            <tr><td class="fw-bold">Amount:</td><td class="fw-bold text-success">${parseFloat(r.amount_paid || 0).toLocaleString()} ${r.currency || ''}</td></tr>
                            <tr><td class="fw-bold">Cheque No:</td><td>${r.cheque_no || '-'}</td></tr>
                            <tr><td class="fw-bold">Description:</td><td>${r.payment_description || '-'}</td></tr>
                        </table>
                    </div>
                </div>
                <hr>
                <h6 class="text-primary">Bank Details</h6>
                <div class="row">
                    <div class="col-md-6">
                        <table class="table table-sm table-borderless">
                            <tr><td class="fw-bold" style="width:40%">Bank:</td><td>${r.payee_bank_name || '-'}</td></tr>
                            <tr><td class="fw-bold">Branch:</td><td>${r.payee_branch || '-'}</td></tr>
                        </table>
                    </div>
                    <div class="col-md-6">
                        <table class="table table-sm table-borderless">
                            <tr><td class="fw-bold" style="width:40%">Account Name:</td><td>${r.payee_account_name || '-'}</td></tr>
                            <tr><td class="fw-bold">Account No:</td><td>${r.payee_account_no || '-'}</td></tr>
                        </table>
                    </div>
                </div>
                ${r.attachment_name ? '<hr><h6 class="text-primary"><i class="bi bi-paperclip me-1"></i>Attachment</h6><p><a href="<?php echo BASE_URL; ?>ceo/dashboard.php?action=download_attachment&id=' + r.id + '&csrf_token=' + encodeURIComponent(document.getElementById('csrfToken').value) + '" target="_blank" class="btn btn-sm btn-outline-primary"><i class="bi bi-download me-1"></i>' + r.attachment_name + '</a></p>' : ''}
            `;
            new bootstrap.Modal(document.getElementById('ceoPaymentRequestViewModal')).show();
        } else {
            alert('Error: ' + (data.message || 'Failed to load request details'));
        }
    })
    .catch(error => {
        alert('Error loading request details: ' + error.message);
    });
}

// Handle payment request form submission via AJAX
document.getElementById('ceoPaymentRequestForm').addEventListener('submit', function(e) {
    e.preventDefault();
    const form = this;
    const action = document.getElementById('prAction').value;
    const notes = document.getElementById('prReason').value;
    const requestId = document.getElementById('prRequestId').value;

    if (action === 'reject' && !notes.trim()) {
        alert('Rejection reason is required');
        return;
    }

    const formData = new FormData();
    formData.append('action', action);
    formData.append('request_id', requestId);
    formData.append('notes', notes);
    formData.append('csrf_token', document.getElementById('csrfToken').value);

    fetch('<?php echo BASE_URL; ?>ceo/dashboard.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            bootstrap.Modal.getInstance(document.getElementById('ceoPaymentRequestModal')).hide();
            alert(data.message);
            window.location.reload();
        } else {
            alert('Error: ' + (data.message || 'Unknown error'));
        }
    })
    .catch(error => {
        alert('Error: ' + error.message);
    });
});

// Auto-refresh page every 5 minutes to show new pending approvals
setInterval(function() {
    if (document.visibilityState === 'visible') {
        window.location.reload();
    }
}, 300000); // 5 minutes
</script>

<?php include '../includes/footer.php'; ?>
