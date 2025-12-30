<?php
require_once 'config/config.php';
require_once 'auth/auth_middleware.php';
require_once 'includes/approval_helpers.php';
require_once 'config/approval_constants.php';

require_login();
$db = getDBConnection();
$user = get_logged_in_user();

$page_title = 'Leave Request';
$success_message = '';
$error_message = '';

// Handle leave request submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        if (isset($_POST['request_leave'])) {
            // Get employee record for logged-in user (should always exist)
            $emp_stmt = $db->prepare("SELECT id FROM employees WHERE user_id = ?");
            $emp_stmt->execute([$_SESSION['user_id']]);
            $employee = $emp_stmt->fetch();
            
            if (!$employee) {
                $error_message = 'System error: Employee record not found. Please contact support.';
            } else {
                $employee_id = $employee['id'];
                $leave_type_id = (int)$_POST['leave_type_id'];
                $start_date = $_POST['start_date'];
                $end_date = $_POST['end_date'];
                $reason = sanitize_input($_POST['reason']);
                
                // Validate dates
                $start = new DateTime($start_date);
                $end = new DateTime($end_date);
                
                if ($start > $end) {
                    $error_message = 'End date must be after start date.';
                } else {
                    $total_days = $end->diff($start)->days + 1;
                    
                    // Check if leave already exists for overlapping dates
                    $check_stmt = $db->prepare("
                        SELECT COUNT(*) as count FROM leave_requests
                        WHERE employee_id = ? 
                        AND status NOT IN ('rejected', 'cancelled')
                        AND ((start_date <= ? AND end_date >= ?) OR (start_date <= ? AND end_date >= ?))
                    ");
                    $check_stmt->execute([$employee_id, $end_date, $start_date, $end_date, $start_date]);
                    $overlap = $check_stmt->fetch();
                    
                    if ($overlap['count'] > 0) {
                        $error_message = 'You already have an overlapping leave request for these dates.';
                    } else {
                        $stmt = $db->prepare("
                            INSERT INTO leave_requests (employee_id, leave_type_id, start_date, end_date, 
                                                       total_days, reason, status)
                            VALUES (?, ?, ?, ?, ?, ?, 'pending')
                        ");
                        
                        if ($stmt->execute([$employee_id, $leave_type_id, $start_date, $end_date, $total_days, $reason])) {
                            $leave_id = $db->lastInsertId();
                            
                            // Create approval workflow
                            create_approval_workflow(
                                WORKFLOW_LEAVE, ENTITY_LEAVE_REQUEST, $leave_id,
                                $_SESSION['user_id'], LEAVE_PENDING_HR
                            );
                            
                            // Log activity
                            $activity_stmt = $db->prepare("
                                INSERT INTO hr_activities (activity_type, entity_type, entity_id, description, performed_by)
                                VALUES ('leave_requested', 'leave', ?, ?, ?)
                            ");
                            $activity_stmt->execute([
                                $leave_id,
                                "Leave request from {$total_days} days",
                                $_SESSION['user_id']
                            ]);
                            
                            show_alert('Leave request submitted successfully. HR will review your request.', 'success');
                            redirect('leave_request.php');
                        } else {
                            $error_message = 'Error submitting leave request. Please try again.';
                        }
                    }
                }
            }
        }
    } catch (Exception $e) {
        $error_message = 'Error: ' . $e->getMessage();
    }
}

// Get user's leave types
try {
    $types_stmt = $db->query("
        SELECT id, name, days_per_year, max_consecutive_days
        FROM leave_types
        WHERE is_active = 1
        ORDER BY name
    ");
    $leave_types = $types_stmt->fetchAll();
} catch (Exception $e) {
    $leave_types = [];
}

// Get user's leave history
try {
    $emp_id_stmt = $db->prepare("SELECT id FROM employees WHERE user_id = ?");
    $emp_id_stmt->execute([$_SESSION['user_id']]);
    $emp = $emp_id_stmt->fetch();
    
    if ($emp) {
        $history_stmt = $db->prepare("
            SELECT lr.*, 
                   lt.name as leave_type_name,
                   u.full_name as approved_by_name,
                   CASE 
                       WHEN lr.status = 'pending' THEN 'Pending HR Review'
                       WHEN lr.requires_ceo_approval = 1 AND lr.ceo_decision_status = 'pending_ceo' THEN 'Pending CEO Approval'
                       WHEN lr.status = 'approved' OR lr.ceo_decision_status = 'ceo_approved' THEN 'Approved'
                       WHEN lr.status = 'rejected' OR lr.ceo_decision_status = 'ceo_rejected' THEN 'Rejected'
                       ELSE lr.status
                   END as display_status
            FROM leave_requests lr
            JOIN leave_types lt ON lr.leave_type_id = lt.id
            LEFT JOIN users u ON lr.approved_by = u.id
            WHERE lr.employee_id = ?
            ORDER BY lr.created_at DESC
        ");
        $history_stmt->execute([$emp['id']]);
        $leave_history = $history_stmt->fetchAll();
    } else {
        $leave_history = [];
    }
} catch (Exception $e) {
    $leave_history = [];
    error_log("Error loading leave history: " . $e->getMessage());
}

include 'includes/header.php';
?>

<div class="container-fluid pt-4 px-4">
    <div class="row mb-4">
        <div class="col-md-8">
            <h1 class="h3 text-gray-800">
                <i class="bi bi-calendar-check me-2"></i>Request Leave / Permission
            </h1>
        </div>
    </div>

    <?php if (!empty($error_message)): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <i class="bi bi-exclamation-triangle me-2"></i>
            <?php echo htmlspecialchars($error_message); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if (!empty($success_message)): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <i class="bi bi-check-circle me-2"></i>
            <?php echo htmlspecialchars($success_message); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="row">
        <!-- Leave Request Form -->
        <div class="col-lg-5 mb-4">
            <div class="card shadow h-100">
                <div class="card-header py-3 bg-primary">
                    <h6 class="m-0 font-weight-bold text-white">
                        <i class="bi bi-form-check me-2"></i>New Leave Request
                    </h6>
                </div>
                <div class="card-body">
                    <form method="POST">
                        <div class="mb-3">
                            <label class="form-label">Leave Type</label>
                            <select name="leave_type_id" class="form-select" required>
                                <option value="">Select leave type</option>
                                <?php foreach ($leave_types as $type): ?>
                                    <option value="<?php echo $type['id']; ?>">
                                        <?php echo htmlspecialchars($type['name']); ?>
                                        (<?php echo $type['days_per_year']; ?> days/year)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="row">
                            <div class="col-md-6">
                                <label class="form-label">Start Date</label>
                                <input type="date" name="start_date" class="form-control" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">End Date</label>
                                <input type="date" name="end_date" class="form-control" required>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Reason for Leave</label>
                            <textarea name="reason" class="form-control" rows="4" 
                                      placeholder="Please provide a reason for your leave request..." required></textarea>
                        </div>

                        <button type="submit" name="request_leave" class="btn btn-primary w-100">
                            <i class="bi bi-send me-2"></i>Submit Leave Request
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <!-- Leave History -->
        <div class="col-lg-7 mb-4">
            <div class="card shadow h-100">
                <div class="card-header py-3 bg-info">
                    <h6 class="m-0 font-weight-bold text-white">
                        <i class="bi bi-clock-history me-2"></i>Your Leave Requests
                    </h6>
                </div>
                <div class="card-body">
                    <?php if (empty($leave_history)): ?>
                        <div class="text-center text-muted py-4">
                            <i class="bi bi-inbox display-4"></i>
                            <p class="mt-2 mb-0">No leave requests yet</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead class="table-light">
                                    <tr>
                                        <th>Type</th>
                                        <th>Dates</th>
                                        <th>Days</th>
                                        <th>Status</th>
                                        <th>Approved By</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($leave_history as $leave): ?>
                                        <tr>
                                            <td>
                                                <span class="badge bg-primary">
                                                    <?php echo htmlspecialchars($leave['leave_type_name']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <small>
                                                    <?php echo format_date($leave['start_date']); ?> to 
                                                    <?php echo format_date($leave['end_date']); ?>
                                                </small>
                                            </td>
                                            <td>
                                                <strong><?php echo $leave['total_days']; ?></strong>
                                            </td>
                                            <td>
                                                <?php
                                                $status_colors = [
                                                    'pending' => 'warning',
                                                    'approved' => 'success',
                                                    'rejected' => 'danger',
                                                    'Pending HR Review' => 'warning',
                                                    'Pending CEO Approval' => 'info',
                                                    'Approved' => 'success',
                                                    'Rejected' => 'danger'
                                                ];
                                                $color = $status_colors[$leave['display_status']] ?? 'secondary';
                                                ?>
                                                <span class="badge bg-<?php echo $color; ?>">
                                                    <?php echo htmlspecialchars($leave['display_status']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php if ($leave['approved_by_name']): ?>
                                                    <small><?php echo htmlspecialchars($leave['approved_by_name']); ?></small>
                                                <?php else: ?>
                                                    <small class="text-muted">-</small>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                        <?php if (!empty($leave['rejection_reason'])): ?>
                                            <tr>
                                                <td colspan="5" class="bg-light">
                                                    <small class="text-danger">
                                                        <strong>Rejection Reason:</strong> <?php echo htmlspecialchars($leave['rejection_reason']); ?>
                                                    </small>
                                                </td>
                                            </tr>
                                        <?php endif; ?>
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

<?php include 'includes/footer.php'; ?>
