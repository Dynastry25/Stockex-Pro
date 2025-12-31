<?php
require_once '../config/config.php';
require_once '../auth/auth_middleware.php';
require_once '../includes/approval_helpers.php';
require_once '../config/approval_constants.php';

require_ceo();
$db = getDBConnection();

$page_title = 'CEO Leave Management';
$success_message = '';
$error_message = '';

// Handle POST actions for CEO leave decisions
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        if (isset($_POST['ceo_finalize_leave'])) {
            // CEO makes final decision on escalated leave
            $leave_id = (int)$_POST['leave_id'];
            $decision = sanitize_input($_POST['ceo_finalize_leave']); // 'approve' or 'reject'
            $reason = sanitize_input($_POST['reason']);
            
            if (!in_array($decision, ['approve', 'reject'])) {
                $error_message = 'Invalid decision. Must be approve or reject.';
            } else {
                $result = ceo_approve_leave($leave_id, $decision, $_SESSION['user_id'], $reason);
                
                if ($result['success']) {
                    show_alert($result['message'], 'success');
                } else {
                    $error_message = $result['message'];
                }
            }
            
        }
    } catch (Exception $e) {
        $error_message = 'Error: ' . $e->getMessage();
    }
}

// Get escalated leave requests for CEO approval
try {
    $stmt = $db->query("
        SELECT lr.*, 
               CONCAT(u.first_name, ' ', u.last_name) as employee_name,
               u.full_name,
               u.employee_id as employee_code,
               d.name as department_name, 
               jp.title as position_name,
               lt.name as leave_type_name,
               hr_user.full_name as hr_escalated_by_name,
               aw.hr_action_reason as escalation_reason
        FROM leave_requests lr
        JOIN users u ON lr.employee_id = u.id
        JOIN departments d ON u.department_id = d.id
        JOIN job_positions jp ON u.position_id = jp.id
        JOIN leave_types lt ON lr.leave_type_id = lt.id
        LEFT JOIN approval_workflows aw ON aw.entity_type = 'leave_request' AND aw.entity_id = lr.id
        LEFT JOIN users hr_user ON aw.hr_action_by = hr_user.id
        WHERE lr.requires_ceo_approval = 1 AND lr.ceo_decision_status = 'pending_ceo'
        ORDER BY lr.created_at DESC
    ");
    $escalated_leaves = $stmt->fetchAll();
} catch (Exception $e) {
    $escalated_leaves = [];
    $error_message = 'Error loading escalated leave requests: ' . $e->getMessage();
}

// Get leave statistics for CEO
try {
    $pending_ceo_count = $db->query("SELECT COUNT(*) FROM leave_requests WHERE requires_ceo_approval = 1 AND ceo_decision_status = 'pending_ceo'")->fetchColumn();
    $ceo_approved_count = $db->query("SELECT COUNT(*) FROM leave_requests WHERE requires_ceo_approval = 1 AND ceo_decision_status = 'ceo_approved'")->fetchColumn();
    $ceo_rejected_count = $db->query("SELECT COUNT(*) FROM leave_requests WHERE requires_ceo_approval = 1 AND ceo_decision_status = 'ceo_rejected'")->fetchColumn();
} catch (Exception $e) {
    $pending_ceo_count = $ceo_approved_count = $ceo_rejected_count = 0;
}
?>

<?php include '../includes/header.php'; ?>

<div class="container-fluid pt-4 px-4">
    <div class="d-sm-flex align-items-center justify-content-between mb-4">
        <h1 class="h3 mb-0 text-gray-800">
            <i class="bi bi-calendar-check me-2"></i>CEO Leave Management
        </h1>
        <a href="dashboard.php" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i>Back to Dashboard
        </a>
    </div>

    <!-- Statistics Cards -->
    <div class="row g-4 mb-4">
        <div class="col-lg-4 col-md-6">
            <div class="card bg-warning text-dark h-100 shadow-sm">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h6 class="card-title fw-bold">Pending CEO Approval</h6>
                        <i class="bi bi-clock-history fs-2 opacity-75"></i>
                    </div>
                    <h3 class="card-text"><?php echo $pending_ceo_count; ?></h3>
                    <small class="text-muted">Escalated by HR</small>
                </div>
            </div>
        </div>
        <div class="col-lg-4 col-md-6">
            <div class="card bg-success text-white h-100 shadow-sm">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h6 class="card-title fw-bold">CEO Approved</h6>
                        <i class="bi bi-check-circle-fill fs-2 opacity-75"></i>
                    </div>
                    <h3 class="card-text"><?php echo $ceo_approved_count; ?></h3>
                    <small class="text-white-50">This month</small>
                </div>
            </div>
        </div>
        <div class="col-lg-4 col-md-6">
            <div class="card bg-danger text-white h-100 shadow-sm">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h6 class="card-title fw-bold">CEO Rejected</h6>
                        <i class="bi bi-x-circle-fill fs-2 opacity-75"></i>
                    </div>
                    <h3 class="card-text"><?php echo $ceo_rejected_count; ?></h3>
                    <small class="text-white-50">This month</small>
                </div>
            </div>
        </div>
    </div>

    <!-- Escalated Leave Requests Table -->
    <div class="row">
        <div class="col-12">
            <div class="card shadow mb-4">
                <div class="card-header py-3">
                    <h6 class="m-0 font-weight-bold text-warning">
                        <i class="bi bi-exclamation-triangle me-2"></i>Escalated Leave Requests - CEO Approval Required
                    </h6>
                </div>
                <div class="card-body">
                    <?php if (empty($escalated_leaves)): ?>
                        <div class="text-center py-5">
                            <i class="bi bi-calendar-x text-muted" style="font-size: 4rem;"></i>
                            <h5 class="text-muted mt-3">No Escalated Leave Requests</h5>
                            <p class="text-muted">All leave requests have been handled by HR or are still in HR review.</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-bordered" id="escalatedLeavesTable" width="100%" cellspacing="0">
                                <thead>
                                    <tr>
                                        <th>Employee</th>
                                        <th>Department</th>
                                        <th>Position</th>
                                        <th>Leave Type</th>
                                        <th>Period</th>
                                        <th>Days</th>
                                        <th>Escalated By</th>
                                        <th>Escalation Reason</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($escalated_leaves as $leave): ?>
                                        <tr>
                                            <td>
                                                <div>
                                                    <strong><?php echo htmlspecialchars($leave['employee_name']); ?></strong>
                                                    <br>
                                                    <small class="text-muted">ID: <?php echo htmlspecialchars($leave['employee_code']); ?></small>
                                                </div>
                                            </td>
                                            <td><?php echo htmlspecialchars($leave['department_name']); ?></td>
                                            <td><?php echo htmlspecialchars($leave['position_name']); ?></td>
                                            <td><?php echo htmlspecialchars($leave['leave_type_name']); ?></td>
                                            <td>
                                                <?php echo format_date($leave['start_date']); ?> -
                                                <?php echo format_date($leave['end_date']); ?>
                                            </td>
                                            <td><?php echo htmlspecialchars($leave['total_days']); ?> days</td>
                                            <td><?php echo htmlspecialchars($leave['hr_escalated_by_name'] ?? 'HR'); ?></td>
                                            <td>
                                                <small><?php echo htmlspecialchars($leave['escalation_reason'] ?? 'No reason provided'); ?></small>
                                            </td>
                                            <td>
                                                <div class="btn-group-vertical btn-group-sm">
                                                    <button class="btn btn-success mb-1"
                                                            onclick="ceoApproveLeave(<?php echo $leave['id']; ?>, '<?php echo htmlspecialchars($leave['employee_name']); ?>')"
                                                            title="Approve Leave">
                                                        <i class="bi bi-check-lg me-1"></i>Approve
                                                    </button>
                                                    <button class="btn btn-danger"
                                                            onclick="ceoRejectLeave(<?php echo $leave['id']; ?>, '<?php echo htmlspecialchars($leave['employee_name']); ?>')"
                                                            title="Reject Leave">
                                                        <i class="bi bi-x-lg me-1"></i>Reject
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
        </div>
    </div>
</div>

<!-- CEO Leave Management JavaScript -->
<script>
function ceoApproveLeave(leaveId, employeeName) {
    if (confirm(`Are you sure you want to approve the leave request for ${employeeName}?`)) {
        // Create a form to submit the approval
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = '';

        const leaveIdInput = document.createElement('input');
        leaveIdInput.type = 'hidden';
        leaveIdInput.name = 'leave_id';
        leaveIdInput.value = leaveId;

        const actionInput = document.createElement('input');
        actionInput.type = 'hidden';
        actionInput.name = 'ceo_finalize_leave';
        actionInput.value = 'approve';

        const reasonInput = document.createElement('input');
        reasonInput.type = 'hidden';
        reasonInput.name = 'reason';
        reasonInput.value = 'Approved by CEO';

        form.appendChild(leaveIdInput);
        form.appendChild(actionInput);
        form.appendChild(reasonInput);

        document.body.appendChild(form);
        form.submit();
    }
}

function ceoRejectLeave(leaveId, employeeName) {
    const reason = prompt(`Please provide a reason for rejecting ${employeeName}'s leave request:`);
    if (reason !== null && reason.trim() !== '') {
        // Create a form to submit the rejection
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = '';

        const leaveIdInput = document.createElement('input');
        leaveIdInput.type = 'hidden';
        leaveIdInput.name = 'leave_id';
        leaveIdInput.value = leaveId;

        const actionInput = document.createElement('input');
        actionInput.type = 'hidden';
        actionInput.name = 'ceo_finalize_leave';
        actionInput.value = 'reject';

        const reasonInput = document.createElement('input');
        reasonInput.type = 'hidden';
        reasonInput.name = 'reason';
        reasonInput.value = reason;

        form.appendChild(leaveIdInput);
        form.appendChild(actionInput);
        form.appendChild(reasonInput);

        document.body.appendChild(form);
        form.submit();
    }
}
</script>

<?php include '../includes/footer.php'; ?>