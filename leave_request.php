<?php
require_once 'config/config.php';
require_once 'auth/auth_middleware.php';
require_once 'includes/approval_helpers.php';
// REMOVED: require_once 'config/approval_constants.php'; // Constants already defined in approval_helpers.php

require_login();
$db = getDBConnection();
$user = get_logged_in_user();

$page_title = 'Leave Request';
$success_message = '';
$error_message = '';

// Helper function for date formatting
function format_date_leave($date) {
    if (empty($date) || $date == '0000-00-00' || $date == '0000-00-00 00:00:00') return '';
    return date('d M Y', strtotime($date));
}

// Handle leave request submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        if (isset($_POST['request_leave'])) {
            // Get employee record for logged-in user (should always exist)
            $emp_stmt = $db->prepare("SELECT id FROM users WHERE id = ?");
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
                $handover_notes = sanitize_input($_POST['handover_notes'] ?? '');
                
                // Validate dates
                $start = new DateTime($start_date);
                $end = new DateTime($end_date);
                $today = new DateTime();
                $today->setTime(0, 0, 0);
                
                if ($start < $today) {
                    $error_message = 'Start date cannot be in the past.';
                } elseif ($start > $end) {
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
                                                       total_days, reason, handover_notes, status, created_at)
                            VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', NOW())
                        ");
                        
                        if ($stmt->execute([$employee_id, $leave_type_id, $start_date, $end_date, $total_days, $reason, $handover_notes])) {
                            $leave_id = $db->lastInsertId();
                            
                            // Create approval workflow if function exists
                            if (function_exists('create_approval_workflow')) {
                                create_approval_workflow(
                                    WORKFLOW_LEAVE, ENTITY_LEAVE_REQUEST, $leave_id,
                                    $_SESSION['user_id'], LEAVE_PENDING_HR
                                );
                            }
                            
                            // Log activity
                            $activity_stmt = $db->prepare("
                                INSERT INTO hr_activities (activity_type, entity_type, entity_id, description, performed_by, performed_at, created_at)
                                VALUES ('leave_submitted', 'leave', ?, ?, ?, NOW(), NOW())
                            ");
                            $activity_stmt->execute([
                                $leave_id,
                                "Leave request submitted for {$total_days} days",
                                $_SESSION['user_id']
                            ]);
                            
                            $_SESSION['success_message'] = 'Leave request submitted successfully. HR will review your request.';
                            header('Location: leave_request');
                            exit();
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

// AJAX handler for viewing leave details
if (isset($_GET['ajax']) && $_GET['ajax'] == '1' && isset($_GET['action']) && $_GET['action'] == 'view' && isset($_GET['id'])) {
    try {
        $leave_id = (int)$_GET['id'];
        $stmt = $db->prepare("
            SELECT lr.*, 
                   COALESCE(e.full_name, CONCAT(e.first_name, ' ', e.last_name)) as employee_name,
                   COALESCE(e.employee_id, e.username) as employee_code,
                   d.name as department_name,
                   lt.name as leave_type_name,
                   approver.full_name as approved_by_name,
                   ceo_user.full_name as ceo_approved_by_name,
                   hr_user.full_name as hr_action_by_name
            FROM leave_requests lr
            JOIN users e ON lr.employee_id = e.id
            LEFT JOIN departments d ON e.department_id = d.id
            LEFT JOIN leave_types lt ON lr.leave_type_id = lt.id
            LEFT JOIN users approver ON lr.approved_by = approver.id
            LEFT JOIN users ceo_user ON lr.ceo_approved_by = ceo_user.id
            LEFT JOIN users hr_user ON lr.hr_action_by = hr_user.id
            WHERE lr.id = ? AND lr.employee_id = ?
        ");
        $stmt->execute([$leave_id, $_SESSION['user_id']]);
        $leave = $stmt->fetch(PDO::FETCH_ASSOC);

        header('Content-Type: application/json');
        if ($leave) {
            echo json_encode(['success' => true, 'leave' => $leave]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Leave request not found.']);
        }
    } catch (Exception $e) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
    }
    exit();
}

// Check for success message from session
if (isset($_SESSION['success_message'])) {
    $success_message = $_SESSION['success_message'];
    unset($_SESSION['success_message']);
}

// Get user's leave types
try {
    $types_stmt = $db->query("
        SELECT id, name, days_per_year, max_consecutive_days, description
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
    $emp_id_stmt = $db->prepare("SELECT id FROM users WHERE id = ?");
    $emp_id_stmt->execute([$_SESSION['user_id']]);
    $emp = $emp_id_stmt->fetch();
    
    if ($emp) {
        $history_stmt = $db->prepare("
            SELECT lr.*, 
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
            JOIN leave_types lt ON lr.leave_type_id = lt.id
            LEFT JOIN users approver ON lr.approved_by = approver.id
            LEFT JOIN users ceo_user ON lr.ceo_approved_by = ceo_user.id
            LEFT JOIN users hr_user ON lr.hr_action_by = hr_user.id
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

// Get leave statistics for current user
try {
    if ($emp) {
        $stats_stmt = $db->prepare("
            SELECT 
                COUNT(*) as total_requests,
                COUNT(CASE WHEN status = 'approved' OR ceo_decision_status = 'ceo_approved' THEN 1 END) as approved_count,
                SUM(CASE WHEN status = 'approved' OR ceo_decision_status = 'ceo_approved' THEN total_days ELSE 0 END) as approved_days,
                COUNT(CASE WHEN status = 'pending' AND requires_ceo_approval = 0 THEN 1 END) as pending_count,
                SUM(CASE WHEN status = 'pending' AND requires_ceo_approval = 0 THEN total_days ELSE 0 END) as pending_days,
                COUNT(CASE WHEN requires_ceo_approval = 1 AND ceo_decision_status = 'pending_ceo' THEN 1 END) as pending_ceo_count,
                SUM(CASE WHEN requires_ceo_approval = 1 AND ceo_decision_status = 'pending_ceo' THEN total_days ELSE 0 END) as pending_ceo_days
            FROM leave_requests 
            WHERE employee_id = ?
        ");
        $stats_stmt->execute([$emp['id']]);
        $leave_stats = $stats_stmt->fetch();
    } else {
        $leave_stats = ['total_requests' => 0, 'approved_count' => 0, 'approved_days' => 0, 'pending_count' => 0, 'pending_days' => 0, 'pending_ceo_count' => 0, 'pending_ceo_days' => 0];
    }
} catch (Exception $e) {
    $leave_stats = ['approved_days' => 0, 'pending_days' => 0, 'pending_ceo_days' => 0, 'total_requests' => 0];
}

include 'includes/header.php';
?>

<div class="container-fluid pt-4 px-4">
    <div class="row mb-4">
        <div class="col-md-8">
            <h1 class="h3 text-gray-800">
                <i class="bi bi-calendar-check me-2"></i>Request Leave
            </h1>
        </div>
        <div class="col-md-4 text-end">
            <div class="small text-muted">Employee: <strong><?php echo htmlspecialchars($user['full_name']); ?></strong></div>
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

    <!-- Statistics Cards -->
    <div class="row mb-4">
        <div class="col-md-3">
            <div class="card border-left-primary shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">Total Requests</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo $leave_stats['total_requests']; ?></div>
                        </div>
                        <div class="col-auto">
                            <i class="bi bi-list-task fs-2 text-primary"></i>
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
                            <div class="text-xs font-weight-bold text-success text-uppercase mb-1">Approved</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo $leave_stats['approved_count']; ?> <small class="text-muted">request(s)</small></div>
                            <div class="text-xs text-muted"><?php echo $leave_stats['approved_days']; ?> day(s)</div>
                        </div>
                        <div class="col-auto">
                            <i class="bi bi-check-circle fs-2 text-success"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-left-warning shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-warning text-uppercase mb-1">Pending (HR)</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo $leave_stats['pending_count']; ?> <small class="text-muted">request(s)</small></div>
                            <div class="text-xs text-muted"><?php echo $leave_stats['pending_days']; ?> day(s)</div>
                        </div>
                        <div class="col-auto">
                            <i class="bi bi-clock-history fs-2 text-warning"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-left-info shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-info text-uppercase mb-1">Pending (CEO)</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo $leave_stats['pending_ceo_count']; ?> <small class="text-muted">request(s)</small></div>
                            <div class="text-xs text-muted"><?php echo $leave_stats['pending_ceo_days']; ?> day(s)</div>
                        </div>
                        <div class="col-auto">
                            <i class="bi bi-arrow-up-circle fs-2 text-info"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <!-- Leave Request Form -->
        <div class="col-lg-5 mb-4">
            <div class="card shadow h-100">
                <div class="card-header py-3 bg-primary">
                    <h6 class="m-0 font-weight-bold">
                        <i class="bi bi-calendar-plus me-2"></i>New Leave Request
                    </h6>
                </div>
                <div class="card-body">
                    <form method="POST" id="leaveRequestForm">
                        <div class="mb-3">
                            <label class="form-label">Leave Type <span class="text-danger">*</span></label>
                            <select name="leave_type_id" class="form-select" id="leaveTypeSelect" required>
                                <option value="">Select leave type</option>
                                <?php foreach ($leave_types as $type): ?>
                                    <option value="<?php echo $type['id']; ?>" 
                                            data-max-days="<?php echo $type['max_consecutive_days']; ?>"
                                            data-description="<?php echo htmlspecialchars($type['description']); ?>">
                                        <?php echo htmlspecialchars($type['name']); ?>
                                        <?php if ($type['days_per_year'] > 0): ?>
                                            (<?php echo $type['days_per_year']; ?> days/year)
                                        <?php endif; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <small id="leaveTypeDescription" class="form-text text-muted"></small>
                        </div>

                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label class="form-label">Start Date <span class="text-danger">*</span></label>
                                <input type="date" name="start_date" class="form-control" id="startDate" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">End Date <span class="text-danger">*</span></label>
                                <input type="date" name="end_date" class="form-control" id="endDate" required>
                            </div>
                        </div>

                        <div class="mb-3">
                            <div class="alert alert-info d-none" id="daysCalculation">
                                <i class="bi bi-info-circle me-1"></i>
                                <span id="calculatedDays">0</span> day(s) will be taken
                                <small id="maxDaysWarning" class="text-danger d-block"></small>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Reason for Leave <span class="text-danger">*</span></label>
                            <textarea name="reason" class="form-control" rows="4" 
                                      placeholder="Please provide a detailed reason for your leave request..." 
                                      required></textarea>
                            <small class="text-muted">Provide clear details about why you need leave.</small>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Handover Notes (Optional)</label>
                            <textarea name="handover_notes" class="form-control" rows="3" 
                                      placeholder="Describe any work handover arrangements or urgent tasks that need attention during your absence..."></textarea>
                            <small class="text-muted">Optional: Help your team prepare for your absence.</small>
                        </div>

                        <div class="d-grid gap-2">
                            <button type="submit" name="request_leave" class="btn btn-primary">
                                <i class="bi bi-send me-2"></i>Submit Leave Request
                            </button>
                            <button type="reset" class="btn btn-outline-secondary">
                                <i class="bi bi-arrow-clockwise me-2"></i>Reset Form
                            </button>
                        </div>
                    </form>
                </div>
                <div class="card-footer bg-light">
                    <small class="text-muted">
                        <i class="bi bi-info-circle me-1"></i>
                        Your request will be reviewed by HR. Complex cases may require CEO approval.
                    </small>
                </div>
            </div>
        </div>

        <!-- Leave History -->
        <div class="col-lg-7 mb-4">
            <div class="card shadow h-100">
                <div class="card-header py-3 bg-info d-flex justify-content-between align-items-center">
                    <h6 class="m-0 font-weight-bold">
                        <i class="bi bi-clock-history me-2"></i>Your Leave History
                    </h6>
                    <span class="badge bg-light text-dark">
                        <?php echo count($leave_history); ?> request(s)
                    </span>
                </div>
                <div class="card-body">
                    <?php if (empty($leave_history)): ?>
                        <div class="text-center py-5">
                            <i class="bi bi-calendar-x display-4 text-muted"></i>
                            <p class="mt-3 mb-0">No leave requests yet</p>
                            <small class="text-muted">Submit your first leave request using the form</small>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover table-sm" id="leaveHistoryTable">
                                <thead class="table-light">
                                    <tr>
                                        <th>Type</th>
                                        <th>Period</th>
                                        <th>Days</th>
                                        <th>Status</th>
                                        <th>Approval</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($leave_history as $leave): ?>
                                        <tr>
                                            <td>
                                                <span class="badge bg-secondary">
                                                    <?php echo htmlspecialchars($leave['leave_type_name']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <small>
                                                    <?php echo format_date_leave($leave['start_date']); ?><br>
                                                    <strong>to</strong><br>
                                                    <?php echo format_date_leave($leave['end_date']); ?>
                                                </small>
                                            </td>
                                            <td>
                                                <strong><?php echo $leave['total_days']; ?></strong>
                                                <small class="text-muted">day<?php echo $leave['total_days'] > 1 ? 's' : ''; ?></small>
                                            </td>
                                            <td>
                                                <?php
                                                $status_colors = [
                                                    'pending' => 'warning',
                                                    'approved' => 'success',
                                                    'rejected' => 'danger',
                                                    'cancelled' => 'secondary',
                                                    'finalized_by_hr_approved' => 'success',
                                                    'finalized_by_hr_rejected' => 'danger',
                                                    'pending_ceo_approval' => 'info',
                                                    'ceo_approved' => 'success',
                                                    'ceo_rejected' => 'danger'
                                                ];
                                                $display_names = [
                                                    'pending' => 'Pending HR',
                                                    'approved' => 'Approved',
                                                    'rejected' => 'Rejected',
                                                    'cancelled' => 'Cancelled',
                                                    'finalized_by_hr_approved' => 'Approved by HR',
                                                    'finalized_by_hr_rejected' => 'Rejected by HR',
                                                    'pending_ceo_approval' => 'Pending CEO',
                                                    'ceo_approved' => 'CEO Approved',
                                                    'ceo_rejected' => 'CEO Rejected'
                                                ];
                                                
                                                $status = $leave['display_status'];
                                                $color = $status_colors[$status] ?? 'secondary';
                                                $display_name = $display_names[$status] ?? ucfirst(str_replace('_', ' ', $status));
                                                ?>
                                                <span class="badge bg-<?php echo $color; ?>">
                                                    <?php echo $display_name; ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php if ($leave['approved_by_name']): ?>
                                                    <small><?php echo htmlspecialchars($leave['approved_by_name']); ?></small>
                                                    <br><small class="text-muted">HR</small>
                                                <?php elseif ($leave['ceo_approved_by_name']): ?>
                                                    <small><?php echo htmlspecialchars($leave['ceo_approved_by_name']); ?></small>
                                                    <br><small class="text-muted">CEO</small>
                                                <?php else: ?>
                                                    <small class="text-muted">Pending</small>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($leave['status'] == 'pending' && !$leave['requires_ceo_approval']): ?>
                                                    <form method="POST" action="leave_request" class="d-inline">
                                                        <input type="hidden" name="leave_id" value="<?php echo $leave['id']; ?>">
                                                        <button type="submit" name="cancel_leave" class="btn btn-sm btn-outline-danger" 
                                                                onclick="return confirm('Are you sure you want to cancel this leave request?');">
                                                            <i class="bi bi-x-circle"></i> Cancel
                                                        </button>
                                                    </form>
                                                <?php else: ?>
                                                    <button class="btn btn-sm btn-outline-info" 
                                                            onclick="showLeaveDetails(<?php echo $leave['id']; ?>)">
                                                        <i class="bi bi-eye"></i> View
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
            </div>
        </div>
    </div>
</div>

<!-- Leave Details Modal -->
<div class="modal fade" id="leaveDetailsModal" tabindex="-1">
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
    const leaveTypeSelect = document.getElementById('leaveTypeSelect');
    if (!leaveTypeSelect || leaveTypeSelect.selectedIndex < 0) return;
    const selectedOption = leaveTypeSelect.options[leaveTypeSelect.selectedIndex];
    const maxDays = selectedOption?.getAttribute('data-max-days');
    
    if (startDate && endDate) {
        const start = new Date(startDate);
        const end = new Date(endDate);
        const timeDiff = end.getTime() - start.getTime();
        const daysDiff = Math.ceil(timeDiff / (1000 * 3600 * 24)) + 1;
        
        if (daysDiff > 0) {
            const calcDaysEl = document.getElementById('calculatedDays');
            const daysCalcDiv = document.getElementById('daysCalculation');
            const maxWarnEl = document.getElementById('maxDaysWarning');
            if (calcDaysEl) calcDaysEl.textContent = daysDiff;
            if (daysCalcDiv) daysCalcDiv.classList.remove('d-none');
            
            // Check max consecutive days
            if (maxWarnEl) {
                if (maxDays && parseInt(maxDays) > 0 && daysDiff > parseInt(maxDays)) {
                    maxWarnEl.textContent = 
                        `Warning: Maximum consecutive days for this leave type is ${maxDays}`;
                } else {
                    maxWarnEl.textContent = '';
                }
            }
        } else {
            const daysCalcDiv = document.getElementById('daysCalculation');
            if (daysCalcDiv) daysCalcDiv.classList.add('d-none');
        }
    }
}

// Update leave type description
function updateLeaveTypeDescription() {
    const leaveTypeSelect = document.getElementById('leaveTypeSelect');
    if (!leaveTypeSelect || leaveTypeSelect.selectedIndex < 0) return;
    const selectedOption = leaveTypeSelect.options[leaveTypeSelect.selectedIndex];
    const description = selectedOption?.getAttribute('data-description');
    
    const descEl = document.getElementById('leaveTypeDescription');
    if (descEl) {
        if (description) {
            descEl.textContent = description;
        } else {
            descEl.textContent = '';
        }
    }
    
    calculateDays(); // Recalculate days when leave type changes
}

// Add event listeners
document.addEventListener('DOMContentLoaded', function() {
    const startDateInput = document.getElementById('startDate');
    const endDateInput = document.getElementById('endDate');
    const leaveTypeSelect = document.getElementById('leaveTypeSelect');
    
    if (startDateInput) {
        // Set minimum date to today
        const today = new Date().toISOString().split('T')[0];
        startDateInput.min = today;
        if (endDateInput) endDateInput.min = today;
        
        startDateInput.addEventListener('change', function() {
            // Update end date minimum
            endDateInput.min = this.value;
            calculateDays();
        });
    }
    
    if (endDateInput) {
        endDateInput.addEventListener('change', calculateDays);
    }
    
    if (leaveTypeSelect) {
        leaveTypeSelect.addEventListener('change', updateLeaveTypeDescription);
        updateLeaveTypeDescription(); // Initial call
    }
    
    // Form validation
    const form = document.getElementById('leaveRequestForm');
    if (form) {
        form.addEventListener('submit', function(event) {
            const startDate = document.getElementById('startDate').value;
            const endDate = document.getElementById('endDate').value;
            
            if (!startDate || !endDate) {
                alert('Please select both start and end dates.');
                event.preventDefault();
                return;
            }
            
            const start = new Date(startDate);
            const end = new Date(endDate);
            const today = new Date();
            today.setHours(0, 0, 0, 0);
            
            if (start < today) {
                alert('Start date cannot be in the past.');
                event.preventDefault();
                return;
            }
            
            if (start > end) {
                alert('End date must be after start date.');
                event.preventDefault();
                return;
            }
        });
    }
});

// Show leave details via AJAX
function showLeaveDetails(leaveId) {
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
    const modal = new bootstrap.Modal(document.getElementById('leaveDetailsModal'));
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
                            <h6 class="text-primary">Leave Information</h6>
                            <p><strong>Type:</strong> <span class="badge bg-secondary">${leave.leave_type_name || 'Unknown'}</span></p>
                            <p><strong>Duration:</strong> ${leave.total_days || 0} day(s)</p>
                            <p><strong>Period:</strong> ${new Date(leave.start_date).toLocaleDateString()} to ${new Date(leave.end_date).toLocaleDateString()}</p>
                            <p><strong>Status:</strong> <span class="badge bg-${statusColors[status] || 'secondary'}">${status.charAt(0).toUpperCase() + status.slice(1)}</span></p>
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
                            ${leave.escalation_reason ? `<p><strong>Escalation Reason:</strong> ${leave.escalation_reason}</p>` : ''}
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
</script>

<style>
.card {
    margin-bottom: 1rem;
}

.badge {
    font-size: 0.85em;
}

.table th {
    font-weight: 600;
    font-size: 0.85rem;
}

.table td {
    font-size: 0.85rem;
}
</style>

<?php include 'includes/footer.php'; ?>