<?php
// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once '../config/config.php';
require_once '../auth/auth_middleware.php';
require_once '../includes/approval_helpers.php';

require_ceo();
$db = getDBConnection();

$page_title = 'CEO Dashboard';
$success_message = '';
$error_message = '';

// Helper functions
function format_date_ceo($date) {
    if (empty($date) || $date == '0000-00-00' || $date == '0000-00-00 00:00:00') return '';
    return date('d M Y', strtotime($date));
}

function get_status_badge_class($status) {
    $classes = [
        'pending' => 'bg-warning',
        'approved' => 'bg-success',
        'rejected' => 'bg-danger',
        'cancelled' => 'bg-secondary',
        'pending_ceo' => 'bg-info',
        'ceo_approved' => 'bg-success',
        'ceo_rejected' => 'bg-danger',
        'finalized_by_hr_approved' => 'bg-success',
        'finalized_by_hr_rejected' => 'bg-danger',
        'pending_ceo_approval' => 'bg-info'
    ];
    
    return $classes[$status] ?? 'bg-secondary';
}

function get_status_display_name($status) {
    $names = [
        'pending' => 'Pending HR',
        'approved' => 'Approved',
        'rejected' => 'Rejected',
        'cancelled' => 'Cancelled',
        'pending_ceo' => 'Pending CEO',
        'ceo_approved' => 'CEO Approved',
        'ceo_rejected' => 'CEO Rejected',
        'finalized_by_hr_approved' => 'Approved by HR',
        'finalized_by_hr_rejected' => 'Rejected by HR',
        'pending_ceo_approval' => 'Pending CEO Approval'
    ];
    
    return $names[$status] ?? ucfirst(str_replace('_', ' ', $status));
}

// CEO approval of leave requests
function ceo_approve_leave($leave_id, $decision, $ceo_user_id, $reason = '') {
    global $db;
    
    try {
        $db->beginTransaction();
        
        // Get current leave details
        $stmt = $db->prepare("
            SELECT lr.*, e.employee_id, CONCAT(e.first_name, ' ', e.last_name) as employee_name,
                   e.department_id, e.position_id
            FROM leave_requests lr
            JOIN users e ON lr.employee_id = e.id
            WHERE lr.id = ? AND lr.requires_ceo_approval = 1 
            AND lr.ceo_decision_status = 'pending_ceo'
        ");
        $stmt->execute([$leave_id]);
        $leave = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$leave) {
            return [
                'success' => false,
                'message' => 'Leave request not found or not pending CEO approval.'
            ];
        }
        
        // Update leave request
        $new_ceo_status = ($decision === 'approve') ? 'ceo_approved' : 'ceo_rejected';
        $new_status = ($decision === 'approve') ? 'approved' : 'rejected';
        
        $update_stmt = $db->prepare("
            UPDATE leave_requests 
            SET ceo_decision_status = ?,
                ceo_approved_by = ?,
                ceo_approved_at = NOW(),
                status = ?,
                ceo_rejection_reason = ?
            WHERE id = ?
        ");
        
        $rejection_reason = ($decision === 'reject') ? $reason : NULL;
        $update_stmt->execute([
            $new_ceo_status,
            $ceo_user_id,
            $new_status,
            $rejection_reason,
            $leave_id
        ]);
        
        // If approved, also update approved_by
        if ($decision === 'approve') {
            $approved_stmt = $db->prepare("
                UPDATE leave_requests 
                SET approved_by = ?,
                    approved_at = NOW()
                WHERE id = ?
            ");
            $approved_stmt->execute([$ceo_user_id, $leave_id]);
        }
        
        // Log CEO activity
        $activity_type = ($decision === 'approve') ? 'ceo_leave_approved' : 'ceo_leave_rejected';
        $description = ($decision === 'approve') 
            ? "Leave request approved by CEO" 
            : "Leave request rejected by CEO: " . substr($reason, 0, 200);
        
        $activity_stmt = $db->prepare("
            INSERT INTO hr_activities (activity_type, entity_type, entity_id, description, performed_by, performed_at, created_at)
            VALUES (?, 'leave', ?, ?, ?, NOW(), NOW())
        ");
        $activity_stmt->execute([$activity_type, $leave_id, $description, $ceo_user_id]);
        
        // Update approval workflow if function exists
        if (function_exists('update_approval_workflow_status')) {
            $workflow_status = ($decision === 'approve') ? 'approved_by_ceo' : 'rejected_by_ceo';
            update_approval_workflow_status('leave_request', $leave_id, $workflow_status, $ceo_user_id);
        }
        
        $db->commit();
        
        return [
            'success' => true,
            'message' => "Leave request has been {$decision}d by CEO successfully."
        ];
        
    } catch (Exception $e) {
        $db->rollBack();
        return [
            'success' => false,
            'message' => 'Error processing CEO decision: ' . $e->getMessage()
        ];
    }
}

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        if (isset($_POST['ceo_leave_decision'])) {
            // CEO makes decision on escalated leave
            $leave_id = (int)$_POST['leave_id'];
            $decision = sanitize_input($_POST['decision']); // 'approve' or 'reject'
            $reason = isset($_POST['reason']) ? sanitize_input($_POST['reason']) : '';
            
            if (!in_array($decision, ['approve', 'reject'])) {
                $error_message = 'Invalid decision. Must be approve or reject.';
            } else {
                $result = ceo_approve_leave($leave_id, $decision, $_SESSION['user_id'], $reason);
                
                if ($result['success']) {
                    $_SESSION['success_message'] = $result['message'];
                    header('Location: dashboard.php');
                    exit();
                } else {
                    $error_message = $result['message'];
                }
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

// Get all leave requests (for CEO to see everything)
try {
    $all_leaves_stmt = $db->prepare("
        SELECT lr.*, 
               CONCAT(e.first_name, ' ', e.last_name) as employee_name,
               e.employee_id as employee_code,
               d.name as department_name, 
               jp.title as position_name,
               lt.name as leave_type_name,
               lt.days_per_year as leave_type_days,
               hr_user.full_name as hr_action_by_name,
               approver.full_name as approved_by_name,
               ceo_user.full_name as ceo_approved_by_name,
               lr.escalation_reason as escalation_reason,
               lr.finality_reason as escalation_reason_alt,
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
        LEFT JOIN users hr_user ON lr.hr_action_by = hr_user.id
        LEFT JOIN users approver ON lr.approved_by = approver.id
        LEFT JOIN users ceo_user ON lr.ceo_approved_by = ceo_user.id
        ORDER BY 
            CASE 
                WHEN lr.requires_ceo_approval = 1 AND lr.ceo_decision_status = 'pending_ceo' THEN 1
                WHEN lr.status = 'pending' THEN 2
                WHEN lr.status = 'approved' THEN 3
                ELSE 4
            END,
            lr.created_at DESC
    ");
    $all_leaves_stmt->execute();
    $all_leaves = $all_leaves_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $all_leaves = [];
    $error_message .= ' Error loading all leaves: ' . $e->getMessage();
}

// Get leave requests pending CEO approval
try {
    $pending_stmt = $db->prepare("
        SELECT lr.*, 
               CONCAT(e.first_name, ' ', e.last_name) as employee_name,
               e.employee_id as employee_code,
               d.name as department_name, 
               jp.title as position_name,
               lt.name as leave_type_name,
               hr_user.full_name as hr_action_by_name,
               lr.escalation_reason as escalation_reason,
               lr.finality_reason as escalation_reason_alt
        FROM leave_requests lr
        JOIN users e ON lr.employee_id = e.id
        LEFT JOIN departments d ON e.department_id = d.id
        LEFT JOIN job_positions jp ON e.position_id = jp.id
        LEFT JOIN leave_types lt ON lr.leave_type_id = lt.id
        LEFT JOIN users hr_user ON lr.hr_action_by = hr_user.id
        WHERE lr.requires_ceo_approval = 1 
        AND lr.ceo_decision_status = 'pending_ceo'
        ORDER BY lr.finalized_by_hr_at ASC
    ");
    $pending_stmt->execute();
    $pending_leaves = $pending_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $pending_leaves = [];
    $error_message .= ' Error loading pending leaves: ' . $e->getMessage();
}

// Get current workers on leave (approved leaves that are active)
try {
    $today = date('Y-m-d');
    $current_leaves_stmt = $db->prepare("
        SELECT lr.*, 
               CONCAT(e.first_name, ' ', e.last_name) as employee_name,
               e.employee_id as employee_code,
               d.name as department_name, 
               jp.title as position_name,
               lt.name as leave_type_name,
               DATEDIFF(lr.end_date, ?) + 1 as days_remaining,
               CASE 
                   WHEN ? < lr.start_date THEN 'Upcoming'
                   WHEN ? BETWEEN lr.start_date AND lr.end_date THEN 'On Leave'
                   ELSE 'Returned'
               END as leave_status
        FROM leave_requests lr
        JOIN users e ON lr.employee_id = e.id
        LEFT JOIN departments d ON e.department_id = d.id
        LEFT JOIN job_positions jp ON e.position_id = jp.id
        LEFT JOIN leave_types lt ON lr.leave_type_id = lt.id
        WHERE lr.status = 'approved' 
        AND lr.start_date <= ? 
        AND lr.end_date >= ?
        ORDER BY 
            CASE 
                WHEN ? BETWEEN lr.start_date AND lr.end_date THEN 1
                ELSE 2
            END,
            lr.end_date ASC
    ");
    $current_leaves_stmt->execute([$today, $today, $today, $today, $today, $today]);
    $current_leaves = $current_leaves_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $current_leaves = [];
    $error_message .= ' Error loading current leaves: ' . $e->getMessage();
}

// Get employees with their leave balances
try {
    $leave_balance_stmt = $db->query("
        SELECT 
            u.id,
            u.employee_id,
            CONCAT(u.first_name, ' ', u.last_name) as employee_name,
            d.name as department_name,
            jp.title as position_name,
            lt.name as leave_type_name,
            lt.days_per_year,
            COALESCE(SUM(CASE 
                WHEN lr.status = 'approved' OR lr.ceo_decision_status = 'ceo_approved' 
                THEN lr.total_days ELSE 0 
            END), 0) as used_days,
            lt.days_per_year - COALESCE(SUM(CASE 
                WHEN lr.status = 'approved' OR lr.ceo_decision_status = 'ceo_approved' 
                THEN lr.total_days ELSE 0 
            END), 0) as remaining_days
        FROM users u
        CROSS JOIN leave_types lt
        LEFT JOIN leave_requests lr ON u.id = lr.employee_id 
            AND lt.id = lr.leave_type_id
            AND YEAR(lr.start_date) = YEAR(CURDATE())
        LEFT JOIN departments d ON u.department_id = d.id
        LEFT JOIN job_positions jp ON u.position_id = jp.id
        WHERE u.status = 'active' 
        AND u.role IN ('trader', 'finance_officer', 'hr_manager', 'hr_officer')
        AND lt.is_active = 1
        GROUP BY u.id, lt.id
        HAVING remaining_days > 0
        ORDER BY u.first_name, u.last_name, lt.name
    ");
    $leave_balances = $leave_balance_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $leave_balances = [];
    $error_message .= ' Error loading leave balances: ' . $e->getMessage();
}

// Get recent HR activities
try {
    $activities_stmt = $db->query("
        SELECT ha.*, 
               u.full_name as performed_by_name,
               u.employee_id as performed_by_employee_id
        FROM hr_activities ha
        LEFT JOIN users u ON ha.performed_by = u.id
        ORDER BY ha.performed_at DESC
        LIMIT 50
    ");
    $hr_activities = $activities_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $hr_activities = [];
    $error_message .= ' Error loading HR activities: ' . $e->getMessage();
}

// Get leave statistics
try {
    $total_pending = $db->query("SELECT COUNT(*) FROM leave_requests WHERE requires_ceo_approval = 1 AND ceo_decision_status = 'pending_ceo'")->fetchColumn();
    $total_approved = $db->query("SELECT COUNT(*) FROM leave_requests WHERE ceo_decision_status = 'ceo_approved'")->fetchColumn();
    $total_rejected = $db->query("SELECT COUNT(*) FROM leave_requests WHERE ceo_decision_status = 'ceo_rejected'")->fetchColumn();
    $total_activities = $db->query("SELECT COUNT(*) FROM hr_activities")->fetchColumn();
    $current_on_leave = $db->query("SELECT COUNT(*) FROM leave_requests WHERE status = 'approved' AND CURDATE() BETWEEN start_date AND end_date")->fetchColumn();
} catch (Exception $e) {
    $total_pending = $total_approved = $total_rejected = $total_activities = $current_on_leave = 0;
}

// Get payroll pending approval
try {
    $payroll_stmt = $db->query("
        SELECT COUNT(*) as count 
        FROM payroll_processing 
        WHERE status = 'pending_ceo_approval'
    ");
    $pending_payrolls = $payroll_stmt->fetchColumn();
} catch (Exception $e) {
    $pending_payrolls = 0;
}

// Check if AJAX request for leave details
if (isset($_GET['ajax']) && $_GET['ajax'] == '1') {
    if (isset($_GET['action']) && $_GET['action'] == 'view_leave' && isset($_GET['id'])) {
        try {
            $leave_id = (int)$_GET['id'];
            $stmt = $db->prepare("
                SELECT lr.*, 
                       CONCAT(e.first_name, ' ', e.last_name) as employee_name,
                       e.employee_id as employee_code,
                       d.name as department_name, 
                       jp.title as position_name,
                       lt.name as leave_type_name,
                       lt.days_per_year as leave_type_days,
                       hr_user.full_name as hr_action_by_name,
                       approver.full_name as approved_by_name,
                       ceo_user.full_name as ceo_approved_by_name,
                       lr.escalation_reason as escalation_reason,
                       lr.finality_reason as escalation_reason_alt
                FROM leave_requests lr
                JOIN users e ON lr.employee_id = e.id
                LEFT JOIN departments d ON e.department_id = d.id
                LEFT JOIN job_positions jp ON e.position_id = jp.id
                LEFT JOIN leave_types lt ON lr.leave_type_id = lt.id
                LEFT JOIN users hr_user ON lr.hr_action_by = hr_user.id
                LEFT JOIN users approver ON lr.approved_by = approver.id
                LEFT JOIN users ceo_user ON lr.ceo_approved_by = ceo_user.id
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
}
?>

<?php include '../includes/header.php'; ?>

<div class="container-fluid pt-4 px-4">
    <div class="d-sm-flex align-items-center justify-content-between mb-4">
        <h1 class="h3 mb-0 text-gray-800">
            <i class="bi bi-speedometer2 me-2"></i>CEO Dashboard
        </h1>
        <div class="d-flex gap-2">
            <span class="badge bg-warning">
                <i class="bi bi-bell me-1"></i>
                <?php echo $pending_leaves ? count($pending_leaves) : 0; ?> Pending Approvals
            </span>
            <span class="badge bg-danger">
                <i class="bi bi-person-x me-1"></i>
                <?php echo $current_on_leave; ?> On Leave
            </span>
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
            <div class="card border-left-warning shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-warning text-uppercase mb-1">Pending CEO Approval</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo $total_pending; ?></div>
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
                            <div class="text-xs font-weight-bold text-success text-uppercase mb-1">CEO Approved</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo $total_approved; ?></div>
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
                            <div class="text-xs font-weight-bold text-danger text-uppercase mb-1">Current on Leave</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo $current_on_leave; ?></div>
                        </div>
                        <div class="col-auto">
                            <i class="bi bi-person-x fs-2 text-danger"></i>
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
                            <div class="text-xs font-weight-bold text-info text-uppercase mb-1">HR Activities</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo $total_activities; ?></div>
                        </div>
                        <div class="col-auto">
                            <i class="bi bi-activity fs-2 text-info"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <!-- Pending Approvals Column -->
        <div class="col-lg-4 mb-4">
            <div class="card shadow h-100">
                <div class="card-header py-3 d-flex justify-content-between align-items-center">
                    <h6 class="m-0 font-weight-bold text-primary">
                        <i class="bi bi-calendar-check me-2"></i>Pending CEO Approvals
                    </h6>
                    <span class="badge bg-warning"><?php echo count($pending_leaves); ?></span>
                </div>
                <div class="card-body">
                    <?php if (empty($pending_leaves)): ?>
                        <div class="text-center py-4">
                            <i class="bi bi-check-circle display-4 text-success"></i>
                            <p class="mt-3 mb-0">No pending approvals</p>
                            <small class="text-muted">All escalated requests processed</small>
                        </div>
                    <?php else: ?>
                        <div class="list-group">
                            <?php foreach ($pending_leaves as $leave): ?>
                                <div class="list-group-item list-group-item-action">
                                    <div class="d-flex w-100 justify-content-between align-items-start">
                                        <div class="me-3">
                                            <h6 class="mb-1"><?php echo htmlspecialchars($leave['employee_name']); ?></h6>
                                            <small class="text-muted"><?php echo htmlspecialchars($leave['leave_type_name']); ?></small>
                                            <br>
                                            <small>
                                                <?php echo format_date_ceo($leave['start_date']); ?> 
                                                to 
                                                <?php echo format_date_ceo($leave['end_date']); ?>
                                                (<?php echo $leave['total_days']; ?> days)
                                            </small>
                                        </div>
                                        <div class="text-end">
                                            <button class="btn btn-sm btn-primary mb-1" 
                                                    onclick="showCeoDecisionModal(<?php echo $leave['id']; ?>, '<?php echo htmlspecialchars(addslashes($leave['employee_name'])); ?>', <?php echo $leave['total_days']; ?>)">
                                                <i class="bi bi-clipboard-check"></i> Decide
                                            </button>
                                            <br>
                                            <button class="btn btn-sm btn-outline-info mt-1" 
                                                    onclick="viewLeaveDetailsCeo(<?php echo $leave['id']; ?>)">
                                                <i class="bi bi-eye"></i> View
                                            </button>
                                        </div>
                                    </div>
                                    <?php if ($leave['hr_action_by_name']): ?>
                                        <small class="text-muted">
                                            <i class="bi bi-person me-1"></i>
                                            Escalated by: <?php echo htmlspecialchars($leave['hr_action_by_name']); ?>
                                            on <?php echo format_date_ceo($leave['finalized_by_hr_at']); ?>
                                        </small>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Current Workers on Leave -->
        <div class="col-lg-4 mb-4">
            <div class="card shadow h-100">
                <div class="card-header py-3 d-flex justify-content-between align-items-center">
                    <h6 class="m-0 font-weight-bold text-primary">
                        <i class="bi bi-person-x me-2"></i>Current Workers on Leave
                    </h6>
                    <span class="badge bg-danger"><?php echo count($current_leaves); ?></span>
                </div>
                <div class="card-body">
                    <?php if (empty($current_leaves)): ?>
                        <div class="text-center py-4">
                            <i class="bi bi-check-circle display-4 text-success"></i>
                            <p class="mt-3 mb-0">No employees on leave</p>
                            <small class="text-muted">All employees are at work today</small>
                        </div>
                    <?php else: ?>
                        <div class="list-group">
                            <?php foreach ($current_leaves as $leave): ?>
                                <div class="list-group-item list-group-item-action">
                                    <div class="d-flex w-100 justify-content-between align-items-start">
                                        <div>
                                            <h6 class="mb-1"><?php echo htmlspecialchars($leave['employee_name']); ?></h6>
                                            <small class="text-muted"><?php echo htmlspecialchars($leave['department_name']); ?></small>
                                            <br>
                                            <span class="badge bg-<?php echo $leave['leave_status'] == 'On Leave' ? 'danger' : ($leave['leave_status'] == 'Upcoming' ? 'warning' : 'success'); ?>">
                                                <?php echo $leave['leave_status']; ?>
                                            </span>
                                            <br>
                                            <small>
                                                <?php echo format_date_ceo($leave['start_date']); ?> 
                                                to 
                                                <?php echo format_date_ceo($leave['end_date']); ?>
                                                (<?php echo $leave['total_days']; ?> days)
                                            </small>
                                        </div>
                                        <div class="text-end">
                                            <?php if ($leave['days_remaining'] > 0): ?>
                                                <span class="badge bg-info">
                                                    <?php echo $leave['days_remaining']; ?> days left
                                                </span>
                                            <?php endif; ?>
                                            <br>
                                            <button class="btn btn-sm btn-outline-info mt-1" 
                                                    onclick="viewLeaveDetailsCeo(<?php echo $leave['id']; ?>)">
                                                <i class="bi bi-eye"></i> Details
                                            </button>
                                        </div>
                                    </div>
                                    <small class="text-muted">
                                        <i class="bi bi-calendar me-1"></i>
                                        <?php echo htmlspecialchars($leave['leave_type_name']); ?>
                                    </small>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Leave Balances -->
        <div class="col-lg-4 mb-4">
            <div class="card shadow h-100">
                <div class="card-header py-3 d-flex justify-content-between align-items-center">
                    <h6 class="m-0 font-weight-bold text-primary">
                        <i class="bi bi-graph-up me-2"></i>Leave Balances
                    </h6>
                    <span class="badge bg-info"><?php echo count($leave_balances); ?> records</span>
                </div>
                <div class="card-body">
                    <?php if (empty($leave_balances)): ?>
                        <div class="text-center py-4">
                            <i class="bi bi-calendar-x display-4 text-muted"></i>
                            <p class="mt-3 mb-0">No leave balance data</p>
                            <small class="text-muted">Leave records will appear here</small>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-sm table-hover">
                                <thead>
                                    <tr>
                                        <th>Employee</th>
                                        <th>Leave Type</th>
                                        <th>Used</th>
                                        <th>Remaining</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    $current_employee = null;
                                    foreach ($leave_balances as $balance): 
                                        if ($current_employee !== $balance['employee_name']):
                                            $current_employee = $balance['employee_name'];
                                    ?>
                                    <tr class="table-light">
                                        <td colspan="4" class="fw-bold">
                                            <?php echo htmlspecialchars($balance['employee_name']); ?>
                                            <small class="text-muted">(<?php echo htmlspecialchars($balance['department_name']); ?>)</small>
                                        </td>
                                    </tr>
                                    <?php endif; ?>
                                    <tr>
                                        <td></td>
                                        <td>
                                            <small><?php echo htmlspecialchars($balance['leave_type_name']); ?></small>
                                        </td>
                                        <td>
                                            <span class="badge bg-secondary">
                                                <?php echo $balance['used_days']; ?> days
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ($balance['remaining_days'] > 0): ?>
                                                <span class="badge bg-success">
                                                    <?php echo $balance['remaining_days']; ?> days
                                                </span>
                                            <?php else: ?>
                                                <span class="badge bg-danger">
                                                    None
                                                </span>
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

    <!-- All Leave Requests -->
    <div class="row">
        <div class="col-12 mb-4">
            <div class="card shadow">
                <div class="card-header py-3 d-flex justify-content-between align-items-center">
                    <h6 class="m-0 font-weight-bold text-primary">
                        <i class="bi bi-list-ul me-2"></i>All Leave Requests
                    </h6>
                    <div class="d-flex gap-2">
                        <input type="text" class="form-control form-control-sm" id="searchAllLeaves" 
                               placeholder="Search..." style="width: 150px;">
                        <select class="form-select form-select-sm" id="filterAllLeaves" style="width: 120px;">
                            <option value="">All Status</option>
                            <option value="pending_ceo_approval">Pending CEO</option>
                            <option value="ceo_approved">CEO Approved</option>
                            <option value="ceo_rejected">CEO Rejected</option>
                            <option value="approved">Approved</option>
                            <option value="pending">Pending HR</option>
                        </select>
                    </div>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-bordered table-hover" id="allLeavesTable">
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
                                <?php if (empty($all_leaves)): ?>
                                    <tr>
                                        <td colspan="8" class="text-center py-4">
                                            <i class="bi bi-calendar-x display-4 text-muted"></i>
                                            <p class="mt-2 mb-0">No leave requests found</p>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($all_leaves as $leave): ?>
                                        <tr>
                                            <td>
                                                <div>
                                                    <strong><?php echo htmlspecialchars($leave['employee_name']); ?></strong>
                                                    <br><small class="text-muted"><?php echo htmlspecialchars($leave['employee_code']); ?></small>
                                                </div>
                                            </td>
                                            <td><?php echo htmlspecialchars($leave['department_name'] ?? 'N/A'); ?></td>
                                            <td>
                                                <span class="badge bg-secondary"><?php echo htmlspecialchars($leave['leave_type_name'] ?? 'Unknown'); ?></span>
                                            </td>
                                            <td>
                                                <small>
                                                    <?php echo format_date_ceo($leave['start_date']); ?><br>
                                                    <strong>to</strong><br>
                                                    <?php echo format_date_ceo($leave['end_date']); ?>
                                                </small>
                                            </td>
                                            <td>
                                                <strong><?php echo $leave['total_days']; ?></strong>
                                                <small class="text-muted">day<?php echo $leave['total_days'] > 1 ? 's' : ''; ?></small>
                                            </td>
                                            <td>
                                                <?php
                                                $badge_class = get_status_badge_class($leave['display_status']);
                                                $display_name = get_status_display_name($leave['display_status']);
                                                ?>
                                                <span class="badge <?php echo $badge_class; ?>">
                                                    <?php echo $display_name; ?>
                                                </span>
                                            </td>
                                            <td>
                                                <div class="small">
                                                    <?php if ($leave['hr_action_by_name']): ?>
                                                        <div class="mb-1">
                                                            <i class="bi bi-person-check text-success"></i>
                                                            HR: <?php echo htmlspecialchars($leave['hr_action_by_name']); ?>
                                                            <br><small class="text-muted"><?php echo $leave['finalized_by_hr_at'] ? format_date_ceo($leave['finalized_by_hr_at']) : ''; ?></small>
                                                        </div>
                                                    <?php endif; ?>
                                                    
                                                    <?php if ($leave['ceo_approved_by_name']): ?>
                                                        <div>
                                                            <i class="bi bi-person-check text-primary"></i>
                                                            CEO: <?php echo htmlspecialchars($leave['ceo_approved_by_name']); ?>
                                                            <br><small class="text-muted"><?php echo $leave['ceo_approved_at'] ? format_date_ceo($leave['ceo_approved_at']) : ''; ?></small>
                                                        </div>
                                                    <?php elseif ($leave['requires_ceo_approval'] && $leave['ceo_decision_status'] == 'pending_ceo'): ?>
                                                        <div>
                                                            <i class="bi bi-clock text-warning"></i>
                                                            <span class="text-warning">Awaiting CEO</span>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                            <td>
                                                <?php if ($leave['requires_ceo_approval'] && $leave['ceo_decision_status'] == 'pending_ceo'): ?>
                                                    <div class="btn-group-vertical btn-group-sm">
                                                        <button class="btn btn-outline-primary btn-sm" 
                                                                onclick="showCeoDecisionModal(<?php echo $leave['id']; ?>, '<?php echo htmlspecialchars(addslashes($leave['employee_name'])); ?>', <?php echo $leave['total_days']; ?>)"
                                                                title="Make Decision">
                                                            <i class="bi bi-clipboard-check"></i> Decide
                                                        </button>
                                                        <button class="btn btn-outline-info btn-sm" 
                                                                onclick="viewLeaveDetailsCeo(<?php echo $leave['id']; ?>)"
                                                                title="View Details">
                                                            <i class="bi bi-eye"></i> View
                                                        </button>
                                                    </div>
                                                <?php else: ?>
                                                    <button class="btn btn-outline-info btn-sm"
                                                            onclick="viewLeaveDetailsCeo(<?php echo $leave['id']; ?>)"
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
    </div>
</div>

<!-- CEO Decision Modal -->
<div class="modal fade" id="ceoDecisionModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-clipboard-check me-2"></i>CEO Decision Required</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-info">
                    <i class="bi bi-info-circle me-2"></i>
                    This leave request has been escalated to you by HR for final decision.
                </div>
                
                <div id="employeeInfoDivCeo" class="mb-3">
                    <!-- Employee info will be populated by JavaScript -->
                </div>
                
                <div id="escalationReasonDiv" class="mb-3">
                    <!-- Escalation reason will be populated by JavaScript -->
                </div>
                
                <form id="ceoDecisionForm" method="POST">
                    <input type="hidden" name="ceo_leave_decision" value="1">
                    <input type="hidden" name="leave_id" id="ceoLeaveId">
                    
                    <div class="mb-3">
                        <label class="form-label">Your Decision</label>
                        <div class="btn-group d-flex" role="group">
                            <input type="radio" class="btn-check" name="decision" id="ceoApprove" value="approve" autocomplete="off" required>
                            <label class="btn btn-outline-success" for="ceoApprove">
                                <i class="bi bi-check-lg"></i> Approve
                            </label>
                            
                            <input type="radio" class="btn-check" name="decision" id="ceoReject" value="reject" autocomplete="off" required>
                            <label class="btn btn-outline-danger" for="ceoReject">
                                <i class="bi bi-x-lg"></i> Reject
                            </label>
                        </div>
                    </div>
                    
                    <div class="mb-3" id="rejectionReasonDiv" style="display: none;">
                        <label for="ceoReason" class="form-label">Reason for Rejection</label>
                        <textarea class="form-control" name="reason" id="ceoReason" rows="3" placeholder="Provide reason for rejection..."></textarea>
                        <small class="text-muted">Required when rejecting a leave request.</small>
                    </div>
                    
                    <div class="d-grid gap-2">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-check-lg me-1"></i>Submit Decision
                        </button>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
            </div>
        </div>
    </div>
</div>

<!-- View Leave Details Modal for CEO -->
<div class="modal fade" id="viewLeaveModalCeo" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="bi bi-eye me-2"></i>Leave Request Details
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="leaveDetailsContentCeo">
                <!-- Content will be loaded via AJAX -->
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script>
// Show CEO decision modal
function showCeoDecisionModal(leaveId, employeeName, totalDays) {
    // Set form value
    document.getElementById('ceoLeaveId').value = leaveId;
    
    // Show basic employee info
    document.getElementById('employeeInfoDivCeo').innerHTML = `
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
    
    // Load escalation reason via AJAX
    fetch(`?action=view_leave&id=${leaveId}&ajax=1`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const leave = data.leave;
                const escalationReason = leave.escalation_reason || leave.escalation_reason_alt || 'No escalation reason provided.';
                
                document.getElementById('escalationReasonDiv').innerHTML = `
                    <div class="card border-warning">
                        <div class="card-header bg-light">
                            <h6 class="mb-0"><i class="bi bi-arrow-up-circle me-2"></i>Escalation Reason from HR</h6>
                        </div>
                        <div class="card-body">
                            <p class="mb-0">${escalationReason.replace(/\n/g, '<br>')}</p>
                        </div>
                    </div>
                `;
            }
        })
        .catch(error => {
            console.error('Error loading escalation reason:', error);
            document.getElementById('escalationReasonDiv').innerHTML = `
                <div class="alert alert-warning">
                    <i class="bi bi-exclamation-triangle me-2"></i>
                    Unable to load escalation reason.
                </div>
            `;
        });
    
    // Reset form
    document.getElementById('ceoDecisionForm').reset();
    document.getElementById('rejectionReasonDiv').style.display = 'none';
    
    // Show/hide rejection reason based on decision
    document.querySelectorAll('input[name="decision"]').forEach(radio => {
        radio.addEventListener('change', function() {
            document.getElementById('rejectionReasonDiv').style.display = 
                this.value === 'reject' ? 'block' : 'none';
            if (this.value === 'reject') {
                document.getElementById('ceoReason').required = true;
            } else {
                document.getElementById('ceoReason').required = false;
            }
        });
    });
    
    // Show modal
    new bootstrap.Modal(document.getElementById('ceoDecisionModal')).show();
}

// View leave details via AJAX for CEO
function viewLeaveDetailsCeo(leaveId) {
    // Create loading content
    document.getElementById('leaveDetailsContentCeo').innerHTML = `
        <div class="text-center py-4">
            <div class="spinner-border text-primary mb-3" role="status">
                <span class="visually-hidden">Loading...</span>
            </div>
            <p>Loading leave details...</p>
        </div>
    `;
    
    // Show modal first
    const modal = new bootstrap.Modal(document.getElementById('viewLeaveModalCeo'));
    modal.show();
    
    // Fetch leave details via AJAX
    fetch(`?action=view_leave&id=${leaveId}&ajax=1`)
        .then(response => {
            if (!response.ok) throw new Error('Network response was not ok');
            return response.json();
        })
        .then(data => {
            if (data.success) {
                const leave = data.leave;
                const escalationReason = leave.escalation_reason || leave.escalation_reason_alt || 'No escalation reason provided.';
                const today = new Date();
                const startDate = new Date(leave.start_date);
                const endDate = new Date(leave.end_date);
                const isActive = today >= startDate && today <= endDate;
                
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
                            <p><strong>Period:</strong> ${new Date(leave.start_date).toLocaleDateString()} to ${new Date(leave.end_date).toLocaleDateString()}</p>
                            <p><strong>Status:</strong> <span class="badge bg-${isActive ? 'danger' : 'info'}">${isActive ? 'Currently on Leave' : 'Leave Period'}</span></p>
                        </div>
                    </div>
                    ${leave.requires_ceo_approval ? `
                    <hr>
                    <div class="row">
                        <div class="col-md-12">
                            <h6 class="text-primary">CEO Approval Information</h6>
                            <p><strong>Status:</strong> <span class="badge bg-${leave.ceo_decision_status === 'approved' ? 'success' : (leave.ceo_decision_status === 'rejected' ? 'danger' : 'warning')}">${leave.ceo_decision_status ? leave.ceo_decision_status.replace('_', ' ').charAt(0).toUpperCase() + leave.ceo_decision_status.slice(1) : 'Pending'}</span></p>
                            ${escalationReason ? `<p><strong>Escalation Reason:</strong> ${escalationReason}</p>` : ''}
                            ${leave.ceo_approved_by_name ? `<p><strong>CEO Approved By:</strong> ${leave.ceo_approved_by_name}</p>` : ''}
                            ${leave.ceo_approved_at ? `<p><strong>CEO Decision Date:</strong> ${new Date(leave.ceo_approved_at).toLocaleDateString()}</p>` : ''}
                        </div>
                    </div>
                    ` : ''}
                    <hr>
                    <div class="row">
                        <div class="col-md-6">
                            <h6 class="text-primary">HR Information</h6>
                            ${leave.hr_action_by_name ? `<p><strong>HR Action By:</strong> ${leave.hr_action_by_name}</p>` : ''}
                            ${leave.finalized_by_hr_at ? `<p><strong>HR Decision Date:</strong> ${new Date(leave.finalized_by_hr_at).toLocaleDateString()}</p>` : ''}
                            ${leave.approved_by_name ? `<p><strong>Approved By:</strong> ${leave.approved_by_name}</p>` : ''}
                            ${leave.approved_at ? `<p><strong>Approved On:</strong> ${new Date(leave.approved_at).toLocaleDateString()}</p>` : ''}
                        </div>
                    </div>
                    <hr>
                    <h6 class="text-primary">Reason for Leave</h6>
                    <div class="bg-light p-3 rounded mb-3">
                        ${leave.reason ? leave.reason.replace(/\n/g, '<br>') : 'No reason provided'}
                    </div>
                    
                    ${leave.handover_notes ? `<h6 class="text-primary">Handover Notes</h6><div class="bg-light p-3 rounded mb-3">${leave.handover_notes.replace(/\n/g, '<br>')}</div>` : ''}
                    
                    ${leave.rejection_reason ? `<h6 class="text-danger">Rejection Reason (HR)</h6><div class="alert alert-danger">${leave.rejection_reason.replace(/\n/g, '<br>')}</div>` : ''}
                    
                    ${leave.ceo_rejection_reason ? `<h6 class="text-danger">Rejection Reason (CEO)</h6><div class="alert alert-danger">${leave.ceo_rejection_reason.replace(/\n/g, '<br>')}</div>` : ''}
                `;
                
                document.getElementById('leaveDetailsContentCeo').innerHTML = content;
            } else {
                document.getElementById('leaveDetailsContentCeo').innerHTML = `
                    <div class="alert alert-danger">
                        <i class="bi bi-exclamation-triangle me-2"></i>
                        Error loading leave details: ${data.message || 'Unknown error'}
                    </div>
                `;
            }
        })
        .catch(error => {
            document.getElementById('leaveDetailsContentCeo').innerHTML = `
                <div class="alert alert-danger">
                    <i class="bi bi-exclamation-triangle me-2"></i>
                    Error loading leave details: ${error.message}
                </div>
            `;
        });
}

// Search and filter functionality
document.addEventListener('DOMContentLoaded', function() {
    const searchInput = document.getElementById('searchAllLeaves');
    const filterSelect = document.getElementById('filterAllLeaves');
    const table = document.getElementById('allLeavesTable');
    
    if (searchInput && table) {
        searchInput.addEventListener('keyup', function() {
            const searchTerm = this.value.toLowerCase();
            const rows = table.getElementsByTagName('tbody')[0].getElementsByTagName('tr');
            
            for (let i = 0; i < rows.length; i++) {
                const row = rows[i];
                const text = row.textContent.toLowerCase();
                row.style.display = text.includes(searchTerm) ? '' : 'none';
            }
        });
    }
    
    if (filterSelect && table) {
        filterSelect.addEventListener('change', function() {
            const filterValue = this.value.toLowerCase();
            const rows = table.getElementsByTagName('tbody')[0].getElementsByTagName('tr');
            
            for (let i = 0; i < rows.length; i++) {
                const row = rows[i];
                const statusCell = row.cells[5]; // Status column
                if (statusCell) {
                    const statusText = statusCell.textContent.toLowerCase();
                    if (filterValue === '' || statusText.includes(filterValue)) {
                        row.style.display = '';
                    } else {
                        row.style.display = 'none';
                    }
                }
            }
        });
    }
    
    // Initialize tooltips
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });
});
</script>

<style>
.list-group-item {
    border-left: 3px solid #4e73df;
    margin-bottom: 5px;
}

.list-group-item h6 {
    color: #2e59d9;
}

.badge {
    font-size: 0.85em;
}

.card {
    margin-bottom: 1rem;
}

.table th {
    font-weight: 600;
    font-size: 0.85rem;
}

.table td {
    font-size: 0.85rem;
}

.btn-group-vertical {
    width: 100%;
}

.timeline {
    position: relative;
    padding-left: 30px;
}

.timeline:before {
    content: '';
    position: absolute;
    left: 15px;
    top: 0;
    bottom: 0;
    width: 2px;
    background: #e9ecef;
}

.timeline-item {
    position: relative;
    margin-bottom: 20px;
}

.timeline-marker {
    position: absolute;
    left: -30px;
    top: 5px;
    width: 12px;
    height: 12px;
    border-radius: 50%;
    border: 2px solid #fff;
    box-shadow: 0 0 0 2px #e9ecef;
}

.timeline-content {
    padding: 10px 15px;
    background: #f8f9fa;
    border-radius: 6px;
    border-left: 3px solid #4e73df;
}

.timeline-content h6 {
    color: #2e59d9;
}
</style>

<?php include '../includes/footer.php'; ?>