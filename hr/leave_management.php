<?php
// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once '../config/config.php';
require_once '../auth/auth_middleware.php';
require_once '../includes/approval_helpers.php';

require_hr();
$db = getDBConnection();

$page_title = 'Leave Management';
$success_message = '';
$error_message = '';

// Helper functions
function get_status_badge_class($status) {
    $classes = [
        'pending' => 'bg-warning',
        'approved' => 'bg-success',
        'rejected' => 'bg-danger',
        'cancelled' => 'bg-secondary',
        'finalized_by_hr_approved' => 'bg-success',
        'finalized_by_hr_rejected' => 'bg-danger',
        'pending_ceo_approval' => 'bg-info',
        'ceo_approved' => 'bg-success',
        'ceo_rejected' => 'bg-danger'
    ];
    
    return $classes[$status] ?? 'bg-secondary';
}

function get_status_display_name($status) {
    $names = [
        'pending' => 'Pending',
        'approved' => 'Approved',
        'rejected' => 'Rejected',
        'cancelled' => 'Cancelled',
        'finalized_by_hr_approved' => 'Approved by HR',
        'finalized_by_hr_rejected' => 'Rejected by HR',
        'pending_ceo_approval' => 'Pending CEO',
        'ceo_approved' => 'CEO Approved',
        'ceo_rejected' => 'CEO Rejected'
    ];
    
    return $names[$status] ?? ucfirst(str_replace('_', ' ', $status));
}



function hr_finalize_leave($leave_id, $decision, $hr_user_id, $reason = '') {
    global $db;
    
    try {
        $db->beginTransaction();
        
        // Get current leave details
        $stmt = $db->prepare("
            SELECT lr.*, e.employee_id, COALESCE(e.full_name, CONCAT(e.first_name, ' ', e.last_name)) as employee_name
            FROM leave_requests lr
            JOIN users e ON lr.employee_id = e.id
            WHERE lr.id = ? AND lr.status = 'pending'
        ");
        $stmt->execute([$leave_id]);
        $leave = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$leave) {
            return [
                'success' => false,
                'message' => 'Leave request not found or already processed.'
            ];
        }
        
        // Update leave request - SIMPLIFIED VERSION
        $new_status = ($decision === 'approve') ? 'approved' : 'rejected';
        
        // First, let's see what columns we actually have
        $columns_stmt = $db->query("SHOW COLUMNS FROM leave_requests");
        $columns = $columns_stmt->fetchAll(PDO::FETCH_COLUMN);
        
        // Build SQL dynamically based on actual columns
        $sql_parts = [];
        $params = [];
        
        $sql_parts[] = "status = ?";
        $params[] = $new_status;
        
        $sql_parts[] = "finalized_by_hr_at = NOW()";
        
        // Check if hr_action_by exists and handle it
        if (in_array('hr_action_by', $columns)) {
            $sql_parts[] = "hr_action_by = ?";
            $params[] = $hr_user_id;
        }
        // hr_decision_by column is removed - no longer used
        
        if ($decision === 'reject') {
            $sql_parts[] = "rejection_reason = ?";
            $params[] = $reason;
        } else {
            $sql_parts[] = "rejection_reason = NULL";
        }
        
        $params[] = $leave_id;
        
        $sql = "UPDATE leave_requests SET " . implode(', ', $sql_parts) . " WHERE id = ?";
        
        $update_stmt = $db->prepare($sql);
        $update_stmt->execute($params);
        
        // Also update approved_by if needed
        if ($decision === 'approve') {
            $approved_stmt = $db->prepare("
                UPDATE leave_requests 
                SET approved_by = ?,
                    approved_at = NOW()
                WHERE id = ?
            ");
            $approved_stmt->execute([$hr_user_id, $leave_id]);
        }
        
        // Log HR activity
        $activity_type = ($decision === 'approve') ? 'leave_approved_by_hr' : 'leave_rejected_by_hr';
        $description = ($decision === 'approve') 
            ? "Leave request approved by HR" 
            : "Leave request rejected by HR: " . substr($reason, 0, 200);
        
        $activity_stmt = $db->prepare("
            INSERT INTO hr_activities (activity_type, entity_type, entity_id, description, performed_by, created_at)
            VALUES (?, 'leave', ?, ?, ?, NOW())
        ");
        $activity_stmt->execute([$activity_type, $leave_id, $description, $hr_user_id]);
        
        // Update approval workflow if function exists
        if (function_exists('update_approval_workflow_status')) {
            $workflow_status = ($decision === 'approve') ? 'approved_by_hr' : 'rejected_by_hr';
            update_approval_workflow_status('leave_request', $leave_id, $workflow_status, $hr_user_id);
        }
        
        $db->commit();
        
        return [
            'success' => true,
            'message' => "Leave request has been {$decision}d successfully."
        ];
        
    } catch (Exception $e) {
        $db->rollBack();
        return [
            'success' => false,
            'message' => 'Error finalizing leave: ' . $e->getMessage()
        ];
    }
}

function hr_escalate_leave_to_ceo($leave_id, $hr_user_id, $escalation_reason) {
    global $db;
    
    try {
        $db->beginTransaction();
        
        // Get current leave details
        $stmt = $db->prepare("
            SELECT lr.*, e.employee_id, COALESCE(e.full_name, CONCAT(e.first_name, ' ', e.last_name)) as employee_name
            FROM leave_requests lr
            JOIN users e ON lr.employee_id = e.id
            WHERE lr.id = ? AND lr.status = 'pending'
        ");
        $stmt->execute([$leave_id]);
        $leave = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$leave) {
            return [
                'success' => false,
                'message' => 'Leave request not found or already processed.'
            ];
        }
        
        // First, let's see what columns we actually have
        $columns_stmt = $db->query("SHOW COLUMNS FROM leave_requests");
        $columns = $columns_stmt->fetchAll(PDO::FETCH_COLUMN);
        
        // Build SQL dynamically
        $sql_parts = [];
        $params = [];
        
        $sql_parts[] = "requires_ceo_approval = 1";
        $sql_parts[] = "ceo_decision_status = 'pending_ceo'";
        $sql_parts[] = "finalized_by_hr_at = NOW()";
        
        // Check if escalation_reason exists
        if (in_array('escalation_reason', $columns)) {
            $sql_parts[] = "escalation_reason = ?";
            $params[] = $escalation_reason;
        } elseif (in_array('finality_reason', $columns)) {
            $sql_parts[] = "finality_reason = ?";
            $params[] = $escalation_reason;
        }
        
        // Check if hr_action_by exists
        if (in_array('hr_action_by', $columns)) {
            $sql_parts[] = "hr_action_by = ?";
            $params[] = $hr_user_id;
        }
        // hr_decision_by column is removed - no longer used
        
        $params[] = $leave_id;
        
        $sql = "UPDATE leave_requests SET " . implode(', ', $sql_parts) . " WHERE id = ?";
        
        $update_stmt = $db->prepare($sql);
        $update_stmt->execute($params);
        
        // Log HR activity
        $activity_stmt = $db->prepare("
            INSERT INTO hr_activities (activity_type, entity_type, entity_id, description, performed_by, created_at)
            VALUES ('leave_escalated_to_ceo', 'leave', ?, ?, ?, NOW())
        ");
        $activity_stmt->execute([
            $leave_id, 
            "Leave request escalated to CEO: " . substr($escalation_reason, 0, 200),
            $hr_user_id
        ]);
        
        // Update approval workflow if function exists
        if (function_exists('update_approval_workflow_status')) {
            update_approval_workflow_status('leave_request', $leave_id, 'pending_ceo', $hr_user_id);
        }
        
        $db->commit();
        
        return [
            'success' => true,
            'message' => 'Leave request has been escalated to CEO for approval.'
        ];
        
    } catch (Exception $e) {
        $db->rollBack();
        return [
            'success' => false,
            'message' => 'Error escalating leave to CEO: ' . $e->getMessage()
        ];
    }
}

// Handle POST actions for leave requests
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        if (isset($_POST['add_leave_request'])) {
            // Add new leave request
            $employee_id = (int)$_POST['employee_id'];
            $leave_type_id = (int)$_POST['leave_type_id'];
            $start_date = $_POST['start_date'];
            $end_date = $_POST['end_date'];
            $reason = sanitize_input($_POST['reason']);
            $handover_notes = sanitize_input($_POST['handover_notes']);
            
            // Calculate total days
            $start = new DateTime($start_date);
            $end = new DateTime($end_date);
            $total_days = $end->diff($start)->days + 1;
            
            $stmt = $db->prepare("
                INSERT INTO leave_requests (employee_id, leave_type_id, start_date, end_date, 
                                          total_days, reason, handover_notes, status, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', NOW())
            ");
            
            if ($stmt->execute([$employee_id, $leave_type_id, $start_date, $end_date, 
                              $total_days, $reason, $handover_notes])) {
                
                $leave_id = $db->lastInsertId();
                
                // Create approval workflow if function exists
                if (function_exists('create_approval_workflow')) {
                    create_approval_workflow(
                        'leave', 'leave_request', $leave_id, 
                        $_SESSION['user_id'], 'pending_hr'
                    );
                }
                
                // Log activity
                $activity_stmt = $db->prepare("
                    INSERT INTO hr_activities (activity_type, entity_type, entity_id, description, performed_by, created_at)
                    VALUES (?, 'leave', ?, ?, ?, NOW())
                ");
                $activity_stmt->execute([
                    'leave_submitted',
                    $leave_id,
                    "Leave request submitted for {$total_days} days",
                    $_SESSION['user_id']
                ]);
                
                $_SESSION['success_message'] = 'Leave request submitted successfully.';
                header('Location: leave_management');
                exit();
            } else {
                $error_message = 'Error submitting leave request.';
            }
            
        } elseif (isset($_POST['hr_finalize_leave'])) {
            // HR makes final decision on leave (approve/reject without CEO)
            $leave_id = (int)$_POST['leave_id'];
            $decision = sanitize_input($_POST['decision']); // 'approve' or 'reject'
            $reason = isset($_POST['reason']) ? sanitize_input($_POST['reason']) : '';
            
            if (!in_array($decision, ['approve', 'reject'])) {
                $error_message = 'Invalid decision. Must be approve or reject.';
            } else {
                $result = hr_finalize_leave($leave_id, $decision, $_SESSION['user_id'], $reason);
                
                if ($result['success']) {
                    $_SESSION['success_message'] = $result['message'];
                    header('Location: leave_management.php');
                    exit();
                } else {
                    $error_message = $result['message'];
                }
            }
            
        } elseif (isset($_POST['hr_escalate_leave'])) {
            // HR escalates leave to CEO for decision
            $leave_id = (int)$_POST['leave_id'];
            $escalation_reason = sanitize_input($_POST['escalation_reason']);
            
            if (empty($escalation_reason)) {
                $error_message = 'Escalation reason is required when escalating to CEO.';
            } else {
                $result = hr_escalate_leave_to_ceo($leave_id, $_SESSION['user_id'], $escalation_reason);
                
                if ($result['success']) {
                    $_SESSION['success_message'] = $result['message'];
                    header('Location: leave_management');
                    exit();
                } else {
                    $error_message = $result['message'];
                }
            }
            
        } elseif (isset($_POST['cancel_leave'])) {
            // Cancel leave request
            $leave_id = (int)$_POST['leave_id'];
            
            $stmt = $db->prepare("
                UPDATE leave_requests 
                SET status = 'cancelled' 
                WHERE id = ? AND status = 'pending'
            ");
            
            $stmt->execute([$leave_id]);
            
            if ($stmt->rowCount() > 0) {
                // Log activity
                $activity_stmt = $db->prepare("
                    INSERT INTO hr_activities (activity_type, entity_type, entity_id, description, performed_by, created_at)
                    VALUES ('leave_cancelled', 'leave', ?, 'Leave request cancelled', ?, NOW())
                ");
                $activity_stmt->execute([$leave_id, $_SESSION['user_id']]);
                
                $_SESSION['success_message'] = 'Leave request cancelled successfully.';
                header('Location: leave_management');
                exit();
            } else {
                $error_message = 'Unable to cancel leave request.';
            }
        }
        
    } catch (Exception $e) {
        $error_message = 'Database error: ' . $e->getMessage();
    }
}

// Check for success message from session
if (isset($_SESSION['success_message'])) {
    $success_message = $_SESSION['success_message'];
    unset($_SESSION['success_message']);
}

// Get leave requests with employee and leave type details
try {
    $stmt = $db->query("
        SELECT lr.*, 
               COALESCE(e.full_name, CONCAT(e.first_name, ' ', e.last_name)) as employee_name,
               COALESCE(e.employee_id, e.username) as employee_code,
               d.name as department_name, 
               jp.title as position_name,
               lt.name as leave_type_name,
               approver.full_name as approved_by_name,
               ceo_user.full_name as ceo_approved_by_name,
               hr_user.full_name as hr_action_by_name,
               CASE 
                   WHEN lr.requires_ceo_approval = 1 AND lr.ceo_decision_status = 'pending_ceo' THEN 'pending_ceo_approval'
                   WHEN lr.requires_ceo_approval = 1 AND lr.ceo_decision_status = 'ceo_approved' THEN 'ceo_approved'
                   WHEN lr.requires_ceo_approval = 1 AND lr.ceo_decision_status = 'ceo_rejected' THEN 'ceo_rejected'
                   WHEN lr.finalized_by_hr_at IS NOT NULL THEN CONCAT('finalized_by_hr_', lr.status)
                   ELSE lr.status
               END as display_status
        FROM leave_requests lr
        JOIN users e ON lr.employee_id = e.id
        LEFT JOIN departments d ON e.department_id = d.id
        LEFT JOIN job_positions jp ON e.position_id = jp.id
        LEFT JOIN leave_types lt ON lr.leave_type_id = lt.id
        LEFT JOIN users approver ON lr.approved_by = approver.id
        LEFT JOIN users ceo_user ON lr.ceo_approved_by = ceo_user.id
        LEFT JOIN users hr_user ON lr.hr_action_by = hr_user.id
        ORDER BY lr.created_at DESC
    ");
    $leave_requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $leave_requests = [];
    $error_message = 'Error loading leave requests: ' . $e->getMessage();
}

// Get employees for dropdown
try {
    $emp_stmt = $db->query("
        SELECT e.id, COALESCE(e.employee_id, e.username) as employee_id, COALESCE(e.full_name, CONCAT(e.first_name, ' ', e.last_name)) as full_name, d.name as department_name
        FROM users e
        LEFT JOIN departments d ON e.department_id = d.id
        WHERE e.status = 'active' AND e.role IN ('trader', 'finance_officer', 'ceo', 'hr_manager', 'hr_officer')
        ORDER BY COALESCE(e.full_name, e.username)
    ");
    $employees = $emp_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $employees = [];
}

// Get leave types
try {
    $lt_stmt = $db->query("SELECT * FROM leave_types WHERE is_active = 1 ORDER BY name");
    $leave_types = $lt_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $leave_types = [];
}

// Get leave statistics
try {
    $pending_count = $db->query("SELECT COUNT(*) FROM leave_requests WHERE status = 'pending'")->fetchColumn();
    $approved_count = $db->query("SELECT COUNT(*) FROM leave_requests WHERE status = 'approved'")->fetchColumn();
    $rejected_count = $db->query("SELECT COUNT(*) FROM leave_requests WHERE status = 'rejected'")->fetchColumn();
    $escalated_count = $db->query("SELECT COUNT(*) FROM leave_requests WHERE requires_ceo_approval = 1 AND ceo_decision_status = 'pending_ceo'")->fetchColumn();
} catch (Exception $e) {
    $pending_count = $approved_count = $rejected_count = $escalated_count = 0;
}

// Check if AJAX request
if (isset($_GET['ajax']) && $_GET['ajax'] == '1' && isset($_GET['action']) && $_GET['action'] == 'view' && isset($_GET['id'])) {
    try {
        $leave_id = (int)$_GET['id'];
        $stmt = $db->prepare("
            SELECT lr.*, 
                   COALESCE(e.full_name, CONCAT(e.first_name, ' ', e.last_name)) as employee_name,
                   COALESCE(e.employee_id, e.username) as employee_code,
                   d.name as department_name, 
                   jp.title as position_name,
                   lt.name as leave_type_name,
                   approver.full_name as approved_by_name,
                   ceo_user.full_name as ceo_approved_by_name,
                   hr_user.full_name as hr_action_by_name
            FROM leave_requests lr
            JOIN users e ON lr.employee_id = e.id
            LEFT JOIN departments d ON e.department_id = d.id
            LEFT JOIN job_positions jp ON e.position_id = jp.id
            LEFT JOIN leave_types lt ON lr.leave_type_id = lt.id
            LEFT JOIN users approver ON lr.approved_by = approver.id
            LEFT JOIN users ceo_user ON lr.ceo_approved_by = ceo_user.id
            LEFT JOIN users hr_user ON lr.hr_action_by = hr_user.id
            WHERE lr.id = ?
        ");
        $stmt->execute([$leave_id]);
        $leave = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($leave) {
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'leave' => $leave]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Leave request not found.']);
        }
        exit();
    } catch (Exception $e) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Error loading leave details: ' . $e->getMessage()]);
        exit();
    }
}
?>

<?php include '../includes/header.php'; ?>

<div class="container-fluid pt-4 px-4">
    <div class="d-sm-flex align-items-center justify-content-between mb-4">
        <h1 class="h3 mb-0 text-gray-800">
            <i class="bi bi-calendar-check me-2"></i>Leave Management (Ruhusa)
        </h1>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addLeaveModal">
            <i class="bi bi-calendar-plus me-2"></i>Add Leave Request
        </button>
    </div>

    <?php if (!empty($error_message)): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <i class="bi bi-exclamation-triangle me-2"></i>
            <?php echo htmlspecialchars($error_message ?? ''); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if (!empty($success_message)): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <i class="bi bi-check-circle me-2"></i>
            <?php echo htmlspecialchars($success_message ?? ''); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Statistics Cards -->
    <div class="row mb-4">
        <div class="col-md-3">
            <div class="card border-left-warning shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-warning text-uppercase mb-1">Pending Requests</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo $pending_count; ?></div>
                        </div>
                        <div class="col-auto">
                            <i class="bi bi-clock-history fs-2 text-warning"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-left-success shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-success text-uppercase mb-1">Approved Requests</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo $approved_count; ?></div>
                        </div>
                        <div class="col-auto">
                            <i class="bi bi-check-circle fs-2 text-success"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-left-danger shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-danger text-uppercase mb-1">Rejected Requests</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo $rejected_count; ?></div>
                        </div>
                        <div class="col-auto">
                            <i class="bi bi-x-circle fs-2 text-danger"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-left-primary shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">Escalated to CEO</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo $escalated_count; ?></div>
                        </div>
                        <div class="col-auto">
                            <i class="bi bi-arrow-up-circle fs-2 text-primary"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card shadow mb-4">
        <div class="card-header py-3 d-flex justify-content-between align-items-center">
            <h6 class="m-0 font-weight-bold text-primary">All Leave Requests</h6>
            <div class="d-flex gap-2">
                <input type="text" class="form-control form-control-sm" id="leaveSearch" 
                       placeholder="Search requests..." style="width: 200px;">
                <select class="form-select form-select-sm" id="statusFilter" style="width: 120px;">
                    <option value="">All Status</option>
                    <option value="pending">Pending</option>
                    <option value="approved">Approved</option>
                    <option value="rejected">Rejected</option>
                    <option value="cancelled">Cancelled</option>
                </select>
            </div>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-bordered table-hover" id="leaveRequestsTable">
                    <thead class="table-light">
                        <tr>
                            <th>Employee</th>
                            <th>Department</th>
                            <th>Leave Type</th>
                            <th>Period</th>
                            <th>Days</th>
                            <th>Status</th>
                            <th>Workflow</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($leave_requests)): ?>
                            <tr>
                                <td colspan="8" class="text-center text-muted py-4">
                                    <i class="bi bi-calendar-x display-4"></i>
                                    <p class="mt-2 mb-0">No leave requests found. Submit your first leave request to get started.</p>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($leave_requests as $request): ?>
                                <tr>
                                    <td>
                                        <div>
                                            <strong><?php echo htmlspecialchars($request['employee_name'] ?? ''); ?></strong>
                                            <br><small class="text-muted"><?php echo htmlspecialchars($request['employee_code'] ?? ''); ?></small>
                                        </div>
                                    </td>
                                    <td><?php echo htmlspecialchars($request['department_name'] ?? 'N/A'); ?></td>
                                    <td>
                                        <span class="badge bg-secondary"><?php echo htmlspecialchars($request['leave_type_name'] ?? 'Unknown'); ?></span>
                                    </td>
                                    <td>
                                        <small>
                                            <?php echo format_date($request['start_date']); ?><br>
                                            <strong>to</strong><br>
                                            <?php echo format_date($request['end_date']); ?>
                                        </small>
                                    </td>
                                    <td>
                                        <strong><?php echo $request['total_days']; ?></strong>
                                        <small class="text-muted">day<?php echo $request['total_days'] > 1 ? 's' : ''; ?></small>
                                    </td>
                                    <td>
                                        <?php
                                        $badge_class = get_status_badge_class($request['display_status'] ?? $request['status']);
                                        $display_name = get_status_display_name($request['display_status'] ?? $request['status']);
                                        ?>
                                        <span class="badge <?php echo $badge_class; ?>">
                                            <?php echo $display_name; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="workflow-timeline">
                                            <?php if ($request['finalized_by_hr_at']): ?>
                                                <div class="timeline-item">
                                                    <i class="bi bi-person-check text-success"></i>
                                                    <small class="text-muted">HR: <?php echo format_date($request['finalized_by_hr_at']); ?></small>
                                                </div>
                                            <?php endif; ?>
                                            
                                            <?php if ($request['requires_ceo_approval']): ?>
                                                <div class="timeline-item">
                                                    <i class="bi bi-arrow-up-circle <?php echo ($request['ceo_decision_status'] == 'pending_ceo') ? 'text-warning' : (($request['ceo_decision_status'] == 'ceo_approved') ? 'text-success' : 'text-danger'); ?>"></i>
                                                    <small class="text-muted">CEO: 
                                                        <?php if ($request['ceo_approved_at']): ?>
                                                            <?php echo format_date($request['ceo_approved_at']); ?>
                                                        <?php else: ?>
                                                            Pending
                                                        <?php endif; ?>
                                                    </small>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <?php if ($request['status'] == 'pending'): ?>
                                            <div class="btn-group-vertical btn-group-sm">
                                                <button class="btn btn-outline-primary btn-sm" 
                                                        onclick="showDecisionModal(<?php echo $request['id']; ?>, '<?php echo htmlspecialchars(addslashes($request['employee_name'])); ?>', <?php echo $request['total_days']; ?>)"
                                                        title="Make HR Decision">
                                                    <i class="bi bi-clipboard-check"></i> Decide
                                                </button>
                                                <button class="btn btn-outline-info btn-sm" 
                                                        onclick="viewLeaveDetails(<?php echo $request['id']; ?>)"
                                                        title="View Details">
                                                    <i class="bi bi-eye"></i> View
                                                </button>
                                            </div>
                                        <?php elseif ($request['requires_ceo_approval'] && $request['ceo_decision_status'] == 'pending_ceo'): ?>
                                            <div class="btn-group-vertical btn-group-sm">
                                                <span class="badge bg-warning text-dark">Waiting CEO</span>
                                                <button class="btn btn-outline-info btn-sm mt-1" 
                                                        onclick="viewLeaveDetails(<?php echo $request['id']; ?>)"
                                                        title="View Details">
                                                    <i class="bi bi-eye"></i> View
                                                </button>
                                            </div>
                                        <?php else: ?>
                                            <button class="btn btn-outline-info btn-sm"
                                                    onclick="viewLeaveDetails(<?php echo $request['id']; ?>)"
                                                    title="View Details">
                                                <i class="bi bi-eye"></i> View
                                            </button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Add Leave Request Modal -->
<div class="modal fade" id="addLeaveModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" action="">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="bi bi-calendar-plus me-2"></i>Add Leave Request
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Employee <span class="text-danger">*</span></label>
                            <select name="employee_id" class="form-select" required>
                                <option value="">Select Employee</option>
                                <?php foreach ($employees as $emp): ?>
                                    <option value="<?php echo $emp['id']; ?>">
                                        <?php echo htmlspecialchars($emp['full_name'] ?? ''); ?> 
                                        (<?php echo htmlspecialchars($emp['employee_id'] ?? ''); ?>) - 
                                        <?php echo htmlspecialchars($emp['department_name'] ?? 'N/A'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Leave Type <span class="text-danger">*</span></label>
                            <select name="leave_type_id" class="form-select" required>
                                <option value="">Select Leave Type</option>
                                <?php foreach ($leave_types as $lt): ?>
                                    <option value="<?php echo $lt['id']; ?>" 
                                            data-description="<?php echo htmlspecialchars($lt['description']); ?>"
                                            data-days="<?php echo $lt['days_per_year']; ?>">
                                        <?php echo htmlspecialchars($lt['name']); ?>
                                        <?php if ($lt['days_per_year'] > 0): ?>
                                            (<?php echo $lt['days_per_year']; ?> days/year)
                                        <?php endif; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Start Date <span class="text-danger">*</span></label>
                            <input type="date" name="start_date" class="form-control" required id="startDate">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">End Date <span class="text-danger">*</span></label>
                            <input type="date" name="end_date" class="form-control" required id="endDate">
                        </div>
                        <div class="col-md-12 mb-3">
                            <div class="alert alert-info d-none" id="daysCalculation">
                                <i class="bi bi-info-circle me-1"></i>
                                <span id="calculatedDays">0</span> day(s) will be taken
                            </div>
                        </div>
                        <div class="col-12 mb-3">
                            <label class="form-label">Reason for Leave <span class="text-danger">*</span></label>
                            <textarea name="reason" class="form-control" rows="3" required 
                                      placeholder="Please provide a detailed reason for your leave request..."></textarea>
                        </div>
                        <div class="col-12 mb-3">
                            <label class="form-label">Handover Notes</label>
                            <textarea name="handover_notes" class="form-control" rows="2" 
                                      placeholder="Describe any work handover arrangements or urgent tasks..."></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="add_leave_request" class="btn btn-primary">
                        <i class="bi bi-calendar-plus me-1"></i>Submit Request
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- HR Decision Modal -->
<div class="modal fade" id="hrDecisionModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-clipboard-check me-2"></i>HR Decision Required</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-info">
                    <i class="bi bi-info-circle me-2"></i>
                    As HR, you can choose to:
                    <ul class="mb-0 mt-2">
                        <li><strong>Finalize</strong> - Approve or reject the leave yourself</li>
                        <li><strong>Escalate to CEO</strong> - Forward to CEO for final decision</li>
                    </ul>
                </div>
                
                <div id="employeeInfoDiv" class="mb-3">
                    <!-- Employee info will be populated by JavaScript -->
                </div>
                
                <div class="row">
                    <div class="col-md-6">
                        <!-- Finalize Options -->
                        <div class="card border-success">
                            <div class="card-header bg-light">
                                <h6 class="mb-0"><i class="bi bi-check-square me-2"></i>Finalize Decision</h6>
                            </div>
                            <div class="card-body">
                                <p class="small text-muted">Make the final decision as HR</p>
                                
                                <form id="hrFinalizeForm" method="POST">
                                    <input type="hidden" name="hr_finalize_leave" value="1">
                                    <input type="hidden" name="leave_id" id="finalizeLeaveId">
                                    
                                    <div class="mb-3">
                                        <label class="form-label">Decision</label>
                                        <div class="btn-group d-flex" role="group">
                                            <input type="radio" class="btn-check" name="decision" id="finalizeApprove" value="approve" autocomplete="off" required>
                                            <label class="btn btn-outline-success" for="finalizeApprove">
                                                <i class="bi bi-check-lg"></i> Approve
                                            </label>
                                            
                                            <input type="radio" class="btn-check" name="decision" id="finalizeReject" value="reject" autocomplete="off" required>
                                            <label class="btn btn-outline-danger" for="finalizeReject">
                                                <i class="bi bi-x-lg"></i> Reject
                                            </label>
                                        </div>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for="finalizeReason" class="form-label">Reason</label>
                                        <textarea class="form-control" name="reason" id="finalizeReason" rows="3" placeholder="Provide reason for your decision..."></textarea>
                                    </div>
                                    
                                    <button type="submit" class="btn btn-primary w-100">
                                        <i class="bi bi-check-lg me-1"></i>Finalize Decision
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-6">
                        <!-- Escalate to CEO -->
                        <div class="card border-warning">
                            <div class="card-header bg-light">
                                <h6 class="mb-0"><i class="bi bi-arrow-up-circle me-2"></i>Escalate to CEO</h6>
                            </div>
                            <div class="card-body">
                                <p class="small text-muted">Forward to CEO for final decision</p>
                                
                                <form id="hrEscalateForm" method="POST">
                                    <input type="hidden" name="hr_escalate_leave" value="1">
                                    <input type="hidden" name="leave_id" id="escalateLeaveId">
                                    
                                    <div class="mb-3">
                                        <label for="escalationReason" class="form-label">Escalation Reason <span class="text-danger">*</span></label>
                                        <textarea class="form-control" name="escalation_reason" id="escalationReason" rows="4" 
                                                  placeholder="Why does this leave require CEO approval? (e.g., extended duration, business impact, strategic importance...)" 
                                                  required></textarea>
                                    </div>
                                    
                                    <div class="alert alert-warning small">
                                        <i class="bi bi-exclamation-triangle me-2"></i>
                                        Once escalated, the CEO will make the final decision.
                                    </div>
                                    
                                    <button type="submit" class="btn btn-warning w-100">
                                        <i class="bi bi-arrow-up-circle me-1"></i>Escalate to CEO
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
            </div>
        </div>
    </div>
</div>

<!-- View Leave Details Modal -->
<div class="modal fade" id="viewLeaveModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="bi bi-eye me-2"></i>Leave Request Details
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="leaveDetailsContent">
                <!-- Content will be loaded via AJAX -->
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script>
// Calculate days between dates
function calculateDays() {
    const startDate = document.getElementById('startDate')?.value;
    const endDate = document.getElementById('endDate')?.value;
    
    if (startDate && endDate) {
        const start = new Date(startDate);
        const end = new Date(endDate);
        const timeDiff = end.getTime() - start.getTime();
        const daysDiff = Math.ceil(timeDiff / (1000 * 3600 * 24)) + 1;
        
        if (daysDiff > 0) {
            document.getElementById('calculatedDays').textContent = daysDiff;
            document.getElementById('daysCalculation').classList.remove('d-none');
        } else {
            document.getElementById('daysCalculation').classList.add('d-none');
        }
    }
}

// Add event listeners if elements exist
const startDateInput = document.getElementById('startDate');
const endDateInput = document.getElementById('endDate');
if (startDateInput) startDateInput.addEventListener('change', calculateDays);
if (endDateInput) endDateInput.addEventListener('change', calculateDays);

// Show HR decision modal for pending leaves
function showDecisionModal(leaveId, employeeName, totalDays) {
    // Set form values
    document.getElementById('finalizeLeaveId').value = leaveId;
    document.getElementById('escalateLeaveId').value = leaveId;
    
    // Show employee info
    document.getElementById('employeeInfoDiv').innerHTML = `
        <div class="card border-primary">
            <div class="card-body">
                <h6 class="card-title text-primary mb-2">
                    <i class="bi bi-person me-2"></i>${employeeName}
                </h6>
                <p class="card-text mb-1">
                    <strong>Duration:</strong> ${totalDays} day${totalDays > 1 ? 's' : ''}
                </p>
                <p class="card-text mb-0">
                    <strong>Leave ID:</strong> #${leaveId}
                </p>
            </div>
        </div>
    `;
    
    // Reset forms
    document.getElementById('hrFinalizeForm').reset();
    document.getElementById('hrEscalateForm').reset();
    
    // Show modal
    new bootstrap.Modal(document.getElementById('hrDecisionModal')).show();
}

// View leave details via AJAX
function viewLeaveDetails(leaveId) {
    // Create loading content
    document.getElementById('leaveDetailsContent').innerHTML = `
        <div class="text-center py-4">
            <div class="spinner-border text-primary mb-3" role="status">
                <span class="visually-hidden">Loading...</span>
            </div>
            <p>Loading leave details...</p>
        </div>
    `;
    
    // Show modal first
    const modal = new bootstrap.Modal(document.getElementById('viewLeaveModal'));
    modal.show();
    
    // Fetch leave details via AJAX
    fetch(`?action=view&id=${leaveId}&ajax=1`)
        .then(response => {
            if (!response.ok) throw new Error('Network response was not ok');
            return response.json();
        })
        .then(data => {
            if (data.success) {
                const leave = data.leave;
                const statusColors = {
                    'pending': 'warning',
                    'approved': 'success',
                    'rejected': 'danger',
                    'cancelled': 'secondary'
                };
                
                const status = leave.status || 'pending';
                const content = `
                    <div class="row">
                        <div class="col-md-6">
                            <h6 class="text-primary">Employee Information</h6>
                            <p><strong>Name:</strong> ${leave.employee_name || 'N/A'}</p>
                            <p><strong>Employee ID:</strong> ${leave.employee_code || 'N/A'}</p>
                            <p><strong>Department:</strong> ${leave.department_name || 'N/A'}</p>
                            <p><strong>Position:</strong> ${leave.position_name || 'N/A'}</p>
                        </div>
                        <div class="col-md-6">
                            <h6 class="text-primary">Leave Details</h6>
                            <p><strong>Leave Type:</strong> <span class="badge bg-secondary">${leave.leave_type_name || 'Unknown'}</span></p>
                            <p><strong>Duration:</strong> ${leave.total_days || 0} day(s)</p>
                            <p><strong>Status:</strong> <span class="badge bg-${statusColors[status] || 'secondary'}">${status.charAt(0).toUpperCase() + status.slice(1)}</span></p>
                            <p><strong>Created:</strong> ${leave.created_at ? new Date(leave.created_at).toLocaleDateString() : 'N/A'}</p>
                        </div>
                    </div>
                    <hr>
                    <div class="row">
                        <div class="col-md-6">
                            <h6 class="text-primary">Period</h6>
                            <p><strong>Start Date:</strong> ${leave.start_date ? new Date(leave.start_date).toLocaleDateString() : 'N/A'}</p>
                            <p><strong>End Date:</strong> ${leave.end_date ? new Date(leave.end_date).toLocaleDateString() : 'N/A'}</p>
                        </div>
                        <div class="col-md-6">
                            <h6 class="text-primary">Approval Information</h6>
                            ${leave.approved_by_name ? `<p><strong>Approved By:</strong> ${leave.approved_by_name}</p>` : '<p class="text-muted">Not yet approved</p>'}
                            ${leave.approved_at ? `<p><strong>Approved On:</strong> ${new Date(leave.approved_at).toLocaleDateString()}</p>` : ''}
                            ${leave.hr_action_by_name ? `<p><strong>HR Action By:</strong> ${leave.hr_action_by_name}</p>` : ''}
                            ${leave.finalized_by_hr_at ? `<p><strong>HR Decision Date:</strong> ${new Date(leave.finalized_by_hr_at).toLocaleDateString()}</p>` : ''}
                        </div>
                    </div>
                    ${leave.requires_ceo_approval ? `
                    <hr>
                    <div class="row">
                        <div class="col-md-12">
                            <h6 class="text-primary">CEO Approval</h6>
                            <p><strong>Status:</strong> <span class="badge bg-${leave.ceo_decision_status === 'approved' ? 'success' : (leave.ceo_decision_status === 'rejected' ? 'danger' : 'warning')}">${leave.ceo_decision_status ? leave.ceo_decision_status.replace('_', ' ').charAt(0).toUpperCase() + leave.ceo_decision_status.slice(1) : 'Pending'}</span></p>
                            ${leave.finality_reason ? `<p><strong>Escalation Reason:</strong> ${leave.finality_reason}</p>` : ''}
                            ${leave.ceo_approved_by_name ? `<p><strong>CEO Approved By:</strong> ${leave.ceo_approved_by_name}</p>` : ''}
                            ${leave.ceo_approved_at ? `<p><strong>CEO Decision Date:</strong> ${new Date(leave.ceo_approved_at).toLocaleDateString()}</p>` : ''}
                        </div>
                    </div>
                    ` : ''}
                    <hr>
                    <h6 class="text-primary">Reason for Leave</h6>
                    <div class="bg-light p-3 rounded mb-3">
                        ${leave.reason ? leave.reason.replace(/\n/g, '<br>') : 'No reason provided'}
                    </div>
                    
                    ${leave.handover_notes ? `<h6 class="text-primary">Handover Notes</h6><div class="bg-light p-3 rounded mb-3">${leave.handover_notes.replace(/\n/g, '<br>')}</div>` : ''}
                    
                    ${leave.rejection_reason ? `<h6 class="text-danger">Rejection Reason (HR)</h6><div class="alert alert-danger">${leave.rejection_reason.replace(/\n/g, '<br>')}</div>` : ''}
                    
                    ${leave.ceo_rejection_reason ? `<h6 class="text-danger">Rejection Reason (CEO)</h6><div class="alert alert-danger">${leave.ceo_rejection_reason.replace(/\n/g, '<br>')}</div>` : ''}
                `;
                
                document.getElementById('leaveDetailsContent').innerHTML = content;
            } else {
                document.getElementById('leaveDetailsContent').innerHTML = `
                    <div class="alert alert-danger">
                        <i class="bi bi-exclamation-triangle me-2"></i>
                        Error loading leave details: ${data.message || 'Unknown error'}
                    </div>
                `;
            }
        })
        .catch(error => {
            document.getElementById('leaveDetailsContent').innerHTML = `
                <div class="alert alert-danger">
                    <i class="bi bi-exclamation-triangle me-2"></i>
                    Error loading leave details: ${error.message}
                </div>
            `;
        });
}

// Search functionality
const leaveSearchInput = document.getElementById('leaveSearch');
if (leaveSearchInput) {
    leaveSearchInput.addEventListener('keyup', function() {
        const searchTerm = this.value.toLowerCase();
        const table = document.getElementById('leaveRequestsTable');
        const rows = table.getElementsByTagName('tbody')[0].getElementsByTagName('tr');
        
        for (let i = 0; i < rows.length; i++) {
            const row = rows[i];
            const text = row.textContent.toLowerCase();
            row.style.display = text.includes(searchTerm) ? '' : 'none';
        }
    });
}

// Status filter
const statusFilterInput = document.getElementById('statusFilter');
if (statusFilterInput) {
    statusFilterInput.addEventListener('change', function() {
        const filterValue = this.value.toLowerCase();
        const table = document.getElementById('leaveRequestsTable');
        const rows = table.getElementsByTagName('tbody')[0].getElementsByTagName('tr');
        
        for (let i = 0; i <rows.length; i++) {
            const row = rows[i];
            const statusCell = row.cells[5]; // Status column (0-based index)
            if (statusCell) {
                const statusText = statusCell.textContent.toLowerCase();
                row.style.display = filterValue === '' || statusText.includes(filterValue) ? '' : 'none';
            }
        }
    });
}

// Set minimum date to today for new leave requests
const today = new Date().toISOString().split('T')[0];
if (startDateInput) startDateInput.min = today;
if (endDateInput) endDateInput.min = today;

// Initialize any tooltips
var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
    return new bootstrap.Tooltip(tooltipTriggerEl);
});
</script>

<style>
.workflow-timeline {
    display: flex;
    flex-direction: column;
    gap: 5px;
}
.timeline-item {
    display: flex;
    align-items: center;
    gap: 8px;
}
.timeline-item i {
    font-size: 1.2em;
}
.card {
    margin-bottom: 1rem;
}
.badge {
    font-size: 0.85em;
}
</style>

<?php include '../includes/footer.php'; ?>