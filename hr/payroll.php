<?php
require_once '../config/config.php';
require_once '../auth/auth_middleware.php';
require_once '../includes/payroll_helpers.php';

// Check permissions - HR can create, CEO can approve
$current_user_id = $_SESSION['user_id'];
$current_user_role = $_SESSION['role'];

if ($current_user_role !== 'hr_manager' && $current_user_role !== 'ceo') {
    header('Location: ./dashboard.php');
    exit();
}

$db = getDBConnection();
$page_title = 'Salary & Incentives Setup';
$success_message = '';
$error_message = '';

// Function to determine if incentive needs CEO approval
function needs_ceo_approval($incentive_type) {
    // Only bonuses require CEO approval
    return $incentive_type === 'bonus';
}

// Function to determine initial status
function get_initial_incentive_status($incentive_type) {
    if (needs_ceo_approval($incentive_type)) {
        return 'pending_ceo';
    }
    return 'approved'; // Other types auto-approved
}

// Handle all POST actions
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        // UPDATE SALARY (HR only)
        if (isset($_POST['update_salary']) && $current_user_role === 'hr_manager') {
            $user_id = (int)$_POST['user_id'];
            $new_salary = (float)$_POST['new_salary'];
            $job_title = sanitize_input($_POST['job_title'] ?? '');
            $salary_level = sanitize_input($_POST['salary_level'] ?? '');
            $effective_date = $_POST['effective_date'] ?? '';
            $change_reason = sanitize_input($_POST['change_reason'] ?? '');
            
            if ($new_salary <= 0) {
                $error_message = 'Salary must be greater than 0.';
            } elseif (empty($change_reason)) {
                $error_message = 'Please provide a reason for the salary change.';
            } else {
                // Get current salary
                $current_stmt = $db->prepare("SELECT salary, job_title FROM users WHERE id = ?");
                $current_stmt->execute([$user_id]);
                $current = $current_stmt->fetch();
                
                if ($current) {
                    $old_salary = (float)$current['salary'];
                    $old_job_title = $current['job_title'] ?? '';
                    
                    $db->beginTransaction();
                    
                    // Update user record
                    $update_stmt = $db->prepare("
                        UPDATE users 
                        SET salary = ?, job_title = ?, salary_level = ?, last_salary_review = ?, updated_at = NOW()
                        WHERE id = ?
                    ");
                    $update_stmt->execute([$new_salary, $job_title, $salary_level, date('Y-m-d'), $user_id]);
                    
                    // Record in salary history
                    $history_stmt = $db->prepare("
                        INSERT INTO salary_history (
                            user_id, old_salary, new_salary, old_job_title, job_title, 
                            salary_level, change_reason, effective_date, approved_by
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $history_stmt->execute([
                        $user_id, $old_salary, $new_salary, $old_job_title, $job_title,
                        $salary_level, $change_reason, $effective_date, $_SESSION['user_id']
                    ]);
                    
                    // Log activity
                    $activity_stmt = $db->prepare("
                        INSERT INTO hr_activities (activity_type, entity_type, entity_id, description, performed_by)
                        VALUES ('salary_updated', 'user', ?, ?, ?)
                    ");
                    $change_percent = calculate_salary_change_percent($old_salary, $new_salary);
                    $activity_stmt->execute([
                        $user_id,
                        "Salary updated from " . format_payroll_currency($old_salary) . " to " . format_payroll_currency($new_salary) . 
                        " ({$change_percent}%). Reason: {$change_reason}",
                        $_SESSION['user_id']
                    ]);
                    
                    $db->commit();
                    $success_message = 'Salary updated successfully and recorded in history.';
                    redirect('hr/salary_setup.php');
                } else {
                    $error_message = 'User not found.';
                }
            }
        }
        
        // ADD INCENTIVE/BONUS (HR only)
        elseif (isset($_POST['add_incentive']) && $current_user_role === 'hr_manager') {
            $target_type = $_POST['target_type'] ?? ''; // 'all' or 'specific'
            $incentive_type = sanitize_input($_POST['incentive_type'] ?? '');
            $description = sanitize_input($_POST['description'] ?? '');
            $pay_period_month = $_POST['pay_period_month'] ?? '';
            $notes = sanitize_input($_POST['notes'] ?? '');
            
            // Determine initial status
            $initial_status = get_initial_incentive_status($incentive_type);
            $needs_ceo = needs_ceo_approval($incentive_type);
            
            $db->beginTransaction();
            
            if ($target_type === 'all') {
                // Apply to all active employees
                $amount_type = $_POST['amount_type'] ?? ''; // 'percentage' or 'fixed'
                $amount_value = (float)($_POST['amount_value'] ?? 0);
                
                // Get all active employees (excluding CEO)
                $user_stmt = $db->query("
                    SELECT id, salary FROM users 
                    WHERE status = 'active' 
                    AND role NOT IN ('system_admin', 'ceo')
                ");
                $all_users = $user_stmt->fetchAll();
                
                $added_count = 0;
                $requires_ceo = false;
                
                foreach ($all_users as $user) {
                    $user_id = $user['id'];
                    $salary = (float)($user['salary'] ?? 0);
                    
                    // Calculate amount based on type
                    if ($amount_type === 'percentage') {
                        $amount = ($salary * $amount_value) / 100;
                        $final_description = $description . " ({$amount_value}% of salary)";
                    } else {
                        $amount = $amount_value;
                        $final_description = $description . " (Fixed amount)";
                    }
                    
                    if ($amount > 0) {
                        $stmt = $db->prepare("
                            INSERT INTO payroll_incentives (
                                user_id, incentive_type, description, amount, currency,
                                pay_period_month, notes, created_by, status, is_bulk,
                                needs_ceo_approval
                            ) VALUES (?, ?, ?, ?, 'TZS', ?, ?, ?, ?, 1, ?)
                        ");
                        
                        if ($stmt->execute([$user_id, $incentive_type, $final_description, $amount, 
                                           $pay_period_month, $notes, $_SESSION['user_id'], 
                                           $initial_status, $needs_ceo ? 1 : 0])) {
                            $added_count++;
                        }
                    }
                }
                
                $db->commit();
                
                if ($needs_ceo) {
                    $success_message = "Bonus added to {$added_count} employees. Waiting for CEO approval.";
                } else {
                    $success_message = "{$added_count} incentives added and auto-approved.";
                }
                
                redirect('hr/salary_setup.php');
                
            } else {
                // Apply to specific employee
                $user_id = (int)($_POST['user_id'] ?? 0);
                $amount_type = $_POST['specific_amount_type'] ?? '';
                $amount_value = (float)($_POST['specific_amount_value'] ?? 0);
                
                // Get user salary for percentage calculation
                if ($amount_type === 'percentage') {
                    $salary_stmt = $db->prepare("SELECT salary FROM users WHERE id = ?");
                    $salary_stmt->execute([$user_id]);
                    $user_data = $salary_stmt->fetch();
                    
                    if ($user_data) {
                        $salary = (float)($user_data['salary'] ?? 0);
                        $amount = ($salary * $amount_value) / 100;
                        $final_description = $description . " ({$amount_value}% of salary)";
                    } else {
                        throw new Exception("User not found");
                    }
                } else {
                    $amount = $amount_value;
                    $final_description = $description;
                }
                
                if ($amount <= 0) {
                    $error_message = 'Amount must be greater than 0.';
                } elseif (empty($description)) {
                    $error_message = 'Please provide a description.';
                } else {
                    $stmt = $db->prepare("
                        INSERT INTO payroll_incentives (
                            user_id, incentive_type, description, amount, currency,
                            pay_period_month, notes, created_by, status, is_bulk,
                            needs_ceo_approval
                        ) VALUES (?, ?, ?, ?, 'TZS', ?, ?, ?, ?, 0, ?)
                    ");
                    
                    if ($stmt->execute([$user_id, $incentive_type, $final_description, $amount, 
                                       $pay_period_month, $notes, $_SESSION['user_id'], 
                                       $initial_status, $needs_ceo ? 1 : 0])) {
                        $db->commit();
                        
                        if ($needs_ceo) {
                            $success_message = 'Bonus added successfully. Waiting for CEO approval.';
                        } else {
                            $success_message = 'Incentive added and auto-approved.';
                        }
                        
                        redirect('hr/salary_setup.php');
                    } else {
                        $error_message = 'Failed to add incentive.';
                    }
                }
            }
        }
        
        // ADD OVERTIME (HR only)
        elseif (isset($_POST['add_overtime']) && $current_user_role === 'hr_manager') {
            $user_id = (int)($_POST['user_id'] ?? 0);
            $hours = (float)($_POST['hours'] ?? 0);
            $date = $_POST['overtime_date'] ?? '';
            $description = sanitize_input($_POST['overtime_description'] ?? '');
            
            // Get overtime rate
            $rate_stmt = $db->query("SELECT setting_value FROM system_settings WHERE setting_key = 'overtime_rate_per_hour'");
            $rate_data = $rate_stmt->fetch();
            $overtime_rate = $rate_data ? (float)($rate_data['setting_value'] ?? 5000) : 5000; // Default 5000 TZS per hour
            
            // Calculate amount
            $amount = $hours * $overtime_rate;
            
            if ($hours <= 0) {
                $error_message = 'Hours must be greater than 0.';
            } elseif (empty($description)) {
                $error_message = 'Please provide a description.';
            } else {
                // Overtime is auto-approved (no CEO approval needed)
                $stmt = $db->prepare("
                    INSERT INTO payroll_incentives (
                        user_id, incentive_type, description, amount, currency,
                        pay_period_month, notes, created_by, status, is_bulk,
                        hours, overtime_date, overtime_rate, needs_ceo_approval
                    ) VALUES (?, 'overtime', ?, ?, 'TZS', ?, ?, ?, 'approved', 0, ?, ?, ?, 0)
                ");
                
                $pay_period_month = date('Y-m', strtotime($date));
                $notes = "Overtime: {$hours} hours @ " . format_payroll_currency($overtime_rate) . "/hr";
                
                if ($stmt->execute([$user_id, $description, $amount, $pay_period_month, $notes, 
                                   $_SESSION['user_id'], $hours, $date, $overtime_rate])) {
                    $success_message = 'Overtime added and auto-approved.';
                    redirect('hr/salary_setup.php');
                } else {
                    $error_message = 'Failed to add overtime.';
                }
            }
        }
        
        // SET OVERTIME RATE (HR only)
        elseif (isset($_POST['set_overtime_rate']) && $current_user_role === 'hr_manager') {
            $overtime_rate = (float)($_POST['overtime_rate'] ?? 0);
            
            if ($overtime_rate <= 0) {
                $error_message = 'Overtime rate must be greater than 0.';
            } else {
                $stmt = $db->prepare("
                    INSERT INTO system_settings (setting_key, setting_value, description, updated_by, updated_at)
                    VALUES ('overtime_rate_per_hour', ?, 'Overtime payment per hour', ?, NOW())
                    ON DUPLICATE KEY UPDATE setting_value = ?, updated_by = ?, updated_at = NOW()
                ");
                
                if ($stmt->execute([$overtime_rate, $_SESSION['user_id'], $overtime_rate, $_SESSION['user_id']])) {
                    $success_message = 'Overtime rate updated successfully.';
                    redirect('hr/salary_setup.php');
                } else {
                    $error_message = 'Failed to update overtime rate.';
                }
            }
        }
        
        // APPROVE/REJECT BONUS (CEO only)
        elseif (isset($_POST['approve_incentive']) && $current_user_role === 'ceo') {
            $incentive_id = (int)($_POST['incentive_id'] ?? 0);
            $action = $_POST['action'] ?? '';
            
            if (empty($action)) {
                $error_message = 'Action not specified.';
            } else {
                $status = ($action == 'approve') ? 'approved' : 'rejected';
                
                $stmt = $db->prepare("
                    UPDATE payroll_incentives 
                    SET status = ?, approved_by = ?, approved_at = NOW()
                    WHERE id = ? AND status = 'pending_ceo' AND needs_ceo_approval = 1
                ");
                
                if ($stmt->execute([$status, $_SESSION['user_id'], $incentive_id])) {
                    if ($stmt->rowCount() > 0) {
                        $message = ($action == 'approve') ? 'Bonus approved.' : 'Bonus rejected.';
                        $success_message = $message;
                        
                        // Log activity
                        $activity_type = ($action == 'approve') ? 'bonus_approved' : 'bonus_rejected';
                        $activity_stmt = $db->prepare("
                            INSERT INTO hr_activities (activity_type, entity_type, entity_id, description, performed_by)
                            VALUES (?, 'incentive', ?, ?, ?)
                        ");
                        $activity_stmt->execute([
                            $activity_type,
                            $incentive_id,
                            "Bonus {$action}d by CEO",
                            $_SESSION['user_id']
                        ]);
                    } else {
                        $error_message = 'Bonus not found or already processed.';
                    }
                    redirect('hr/salary_setup.php');
                } else {
                    $error_message = 'Failed to update bonus status.';
                }
            }
        }
        
        // BULK APPROVE/REJECT BONUSES (CEO only)
        elseif (isset($_POST['bulk_action_incentives']) && $current_user_role === 'ceo') {
            $action = $_POST['bulk_action'] ?? '';
            $selected_incentives = $_POST['selected_incentives'] ?? [];
            
            if (empty($selected_incentives)) {
                $error_message = 'Please select at least one bonus.';
            } elseif (empty($action)) {
                $error_message = 'Please select a bulk action.';
            } else {
                $status = ($action == 'approve') ? 'approved' : 'rejected';
                $success_count = 0;
                
                $db->beginTransaction();
                
                foreach ($selected_incentives as $incentive_id) {
                    $incentive_id = (int)$incentive_id;
                    
                    $stmt = $db->prepare("
                        UPDATE payroll_incentives 
                        SET status = ?, approved_by = ?, approved_at = NOW()
                        WHERE id = ? AND status = 'pending_ceo' AND needs_ceo_approval = 1
                    ");
                    
                    if ($stmt->execute([$status, $_SESSION['user_id'], $incentive_id])) {
                        if ($stmt->rowCount() > 0) {
                            $success_count++;
                        }
                    }
                }
                
                $db->commit();
                $success_message = "{$success_count} bonuses {$action}d successfully.";
                redirect('hr/salary_setup.php');
            }
        }
        
        // DELETE DRAFT INCENTIVE (HR only - can only delete own pending incentives)
        elseif (isset($_POST['delete_incentive']) && $current_user_role === 'hr_manager') {
            $incentive_id = (int)($_POST['incentive_id'] ?? 0);
            
            // HR can only delete pending CEO approvals (bonuses they created)
            $stmt = $db->prepare("
                DELETE FROM payroll_incentives 
                WHERE id = ? AND status = 'pending_ceo' AND created_by = ?
            ");
            
            if ($stmt->execute([$incentive_id, $_SESSION['user_id']])) {
                if ($stmt->rowCount() > 0) {
                    $success_message = 'Pending bonus deleted successfully.';
                } else {
                    $error_message = 'Bonus not found, already processed, or you don\'t have permission to delete it.';
                }
                redirect('hr/salary_setup.php');
            } else {
                $error_message = 'Failed to delete bonus.';
            }
        }
        
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        $error_message = 'Database error: ' . $e->getMessage();
    }
}

// GET DATA FOR DISPLAY
$filter_month = $_GET['month'] ?? date('Y-m'); // Default to current month
$view_salary_history = $_GET['view_history'] ?? 0;

// Get users for dropdowns (excluding CEO)
try {
    $user_stmt = $db->query("
        SELECT u.id, u.username, u.full_name, 
               u.salary, u.currency, u.job_title,
               u.salary_level, u.hire_date, u.last_salary_review,
               u.status, u.role
        FROM users u
        WHERE u.status = 'active'
        AND u.role NOT IN ('system_admin')
        " . ($current_user_role === 'hr_manager' ? "AND u.role != 'ceo'" : "") . "
        ORDER BY u.full_name
    ");
    $users = $user_stmt->fetchAll();
} catch (Exception $e) {
    $users = [];
}

// Get pending CEO approvals (bonuses only)
try {
    $pending_stmt = $db->prepare("
        SELECT pi.*, u.full_name, u.username, u.job_title,
               creator.full_name as created_by_name
        FROM payroll_incentives pi
        JOIN users u ON pi.user_id = u.id
        JOIN users creator ON pi.created_by = creator.id
        WHERE pi.status = 'pending_ceo'
        AND pi.needs_ceo_approval = 1
        AND pi.pay_period_month = ?
        ORDER BY pi.created_at DESC
    ");
    $pending_stmt->execute([$filter_month]);
    $pending_bonuses = $pending_stmt->fetchAll();
} catch (Exception $e) {
    $pending_bonuses = [];
}

// Get all approved incentives for current month
try {
    $approved_stmt = $db->prepare("
        SELECT pi.*, u.full_name, u.username, u.job_title,
               approver.full_name as approved_by_name
        FROM payroll_incentives pi
        JOIN users u ON pi.user_id = u.id
        LEFT JOIN users approver ON pi.approved_by = approver.id
        WHERE pi.status = 'approved'
        AND pi.pay_period_month = ?
        ORDER BY pi.incentive_type, u.full_name
    ");
    $approved_stmt->execute([$filter_month]);
    $approved_incentives = $approved_stmt->fetchAll();
} catch (Exception $e) {
    $approved_incentives = [];
}

// Get rejected bonuses
try {
    $rejected_stmt = $db->prepare("
        SELECT pi.*, u.full_name, u.username, u.job_title,
               approver.full_name as approved_by_name
        FROM payroll_incentives pi
        JOIN users u ON pi.user_id = u.id
        LEFT JOIN users approver ON pi.approved_by = approver.id
        WHERE pi.status = 'rejected'
        AND pi.needs_ceo_approval = 1
        AND pi.pay_period_month = ?
        ORDER BY pi.created_at DESC
    ");
    $rejected_stmt->execute([$filter_month]);
    $rejected_bonuses = $rejected_stmt->fetchAll();
} catch (Exception $e) {
    $rejected_bonuses = [];
}

// Get overtime rate
try {
    $rate_stmt = $db->query("SELECT setting_value FROM system_settings WHERE setting_key = 'overtime_rate_per_hour'");
    $rate_data = $rate_stmt->fetch();
    $overtime_rate = $rate_data ? (float)($rate_data['setting_value'] ?? 5000) : 5000;
} catch (Exception $e) {
    $overtime_rate = 5000;
}

// Get salary history if requested
$salary_history = [];
if ($view_salary_history) {
    try {
        $history_stmt = $db->prepare("
            SELECT sh.*, approver.full_name as approved_by_name
            FROM salary_history sh
            LEFT JOIN users approver ON sh.approved_by = approver.id
            WHERE sh.user_id = ?
            ORDER BY sh.effective_date DESC, sh.created_at DESC
        ");
        $history_stmt->execute([$view_salary_history]);
        $salary_history = $history_stmt->fetchAll();
        
        // Get user info
        $user_stmt = $db->prepare("SELECT full_name FROM users WHERE id = ?");
        $user_stmt->execute([$view_salary_history]);
        $history_user = $user_stmt->fetch();
    } catch (Exception $e) {
        $error_message = 'Error loading salary history: ' . $e->getMessage();
    }
}

// Get statistics
try {
    // Pending bonuses stats (CEO approval needed)
    $pending_stats_stmt = $db->prepare("
        SELECT 
            COUNT(*) as total_pending_bonuses,
            SUM(amount) as total_pending_amount
        FROM payroll_incentives 
        WHERE status = 'pending_ceo'
        AND needs_ceo_approval = 1
        AND pay_period_month = ?
    ");
    $pending_stats_stmt->execute([$filter_month]);
    $pending_stats = $pending_stats_stmt->fetch();
    
    // Approved incentives stats
    $approved_stats_stmt = $db->prepare("
        SELECT 
            COUNT(*) as total_approved,
            SUM(CASE WHEN incentive_type = 'bonus' THEN amount ELSE 0 END) as total_bonuses,
            SUM(CASE WHEN incentive_type IN ('allowance', 'commission') THEN amount ELSE 0 END) as total_other_incentives,
            SUM(CASE WHEN incentive_type = 'deduction' THEN amount ELSE 0 END) as total_deductions,
            SUM(CASE WHEN incentive_type = 'overtime' THEN hours ELSE 0 END) as total_overtime_hours,
            SUM(CASE WHEN incentive_type = 'overtime' THEN amount ELSE 0 END) as total_overtime_amount
        FROM payroll_incentives 
        WHERE status = 'approved'
        AND pay_period_month = ?
    ");
    $approved_stats_stmt->execute([$filter_month]);
    $approved_stats = $approved_stats_stmt->fetch();
    
    // Rejected bonuses stats
    $rejected_stats_stmt = $db->prepare("
        SELECT COUNT(*) as total_rejected_bonuses
        FROM payroll_incentives 
        WHERE status = 'rejected'
        AND needs_ceo_approval = 1
        AND pay_period_month = ?
    ");
    $rejected_stats_stmt->execute([$filter_month]);
    $rejected_stats = $rejected_stats_stmt->fetch();
    
} catch (Exception $e) {
    $pending_stats = ['total_pending_bonuses' => 0];
    $approved_stats = ['total_approved' => 0];
    $rejected_stats = ['total_rejected_bonuses' => 0];
}



// Calculate total salary safely
$total_salary = 0;
if (!empty($users)) {
    foreach ($users as $user) {
        $total_salary += (float)($user['salary'] ?? 0);
    }
}

ob_start(); // Start output buffering to prevent header errors
include '../includes/header.php';
?>

<div class="container-fluid pt-4 px-4">
    <div class="d-sm-flex align-items-center justify-content-between mb-4">
        <h1 class="h3 mb-0 text-gray-800">
            <i class="bi bi-cash-coin me-2"></i>Salary & Incentives Setup
            <?php if ($current_user_role === 'hr_manager'): ?>
                <span class="badge bg-primary">HR Manager</span>
            <?php elseif ($current_user_role === 'ceo'): ?>
                <span class="badge bg-success">CEO Approver</span>
            <?php endif; ?>
        </h1>
        <div class="d-flex gap-2">
         
            
            <?php if ($current_user_role === 'hr_manager'): ?>
                <!-- HR Manager Actions -->
                <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#updateSalaryModal">
                    <i class="bi bi-currency-dollar me-2"></i>Update Salary
                </button>
                <button class="btn btn-info" data-bs-toggle="modal" data-bs-target="#addIncentiveModal">
                    <i class="bi bi-plus-circle me-2"></i>Add Incentive
                </button>
                <button class="btn btn-warning" data-bs-toggle="modal" data-bs-target="#addOvertimeModal">
                    <i class="bi bi-clock me-2"></i>Add Overtime
                </button>
            <?php endif; ?>
            
            <?php if ($current_user_role === 'ceo' && !empty($pending_bonuses)): ?>
                <!-- CEO Actions (only when there are pending bonuses) -->
                <button class="btn btn-danger" onclick="bulkActionPrompt()">
                    <i class="bi bi-check-all me-2"></i>Bulk Actions
                </button>
            <?php endif; ?>
        </div>
    </div>

    <!-- Alert Messages -->
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

    <!-- Role-specific Information -->
    <div class="row mb-4">
        <?php if ($current_user_role === 'hr_manager'): ?>
            <div class="col-12">
                <div class="alert alert-info">
                    <i class="bi bi-info-circle me-2"></i>
                    <strong>HR Manager Role:</strong> You can create incentives and update salaries. 
                    <ul class="mb-0 mt-2">
                        <li><strong>Bonuses:</strong> Require CEO approval before being included in payroll</li>
                        <li><strong>Other incentives:</strong> Auto-approved (allowances, commissions, deductions, overtime)</li>
                        <li><strong>Overtime:</strong> Auto-approved based on system rate</li>
                    </ul>
                </div>
            </div>
        <?php elseif ($current_user_role === 'ceo'): ?>
            <div class="col-12">
                <div class="alert alert-warning">
                    <i class="bi bi-shield-check me-2"></i>
                    <strong>CEO Approver Role:</strong> You can approve or reject bonuses created by HR. 
                    Only bonuses require CEO approval. Other incentives are auto-approved by HR.
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- Filter Section -->
    <div class="card shadow mb-4">
        <div class="card-body">
            <form method="GET" class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">Filter by Month</label>
                    <input type="month" name="month" class="form-control" 
                           value="<?php echo htmlspecialchars($filter_month); ?>" 
                           max="<?php echo date('Y-m'); ?>">
                </div>
                <div class="col-md-6 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary me-2">
                        <i class="bi bi-funnel me-1"></i>Apply Filter
                    </button>
                    <a href="../hr/salary_setup" class="btn btn-secondary">
                        <i class="bi bi-x-lg me-1"></i>Clear
                    </a>
                </div>
            </form>
        </div>
    </div>

    <!-- Statistics Cards -->
    <div class="row mb-4">
        <div class="col-xl-3 col-md-6 mb-4">
            <div class="card border-left-primary shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">
                                Active Employees</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">
                                <?php echo count($users); ?>
                            </div>
                        </div>
                        <div class="col-auto">
                            <i class="bi bi-people fa-2x text-primary"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <?php if ($current_user_role === 'ceo' || !empty($pending_bonuses)): ?>
        <div class="col-xl-3 col-md-6 mb-4">
            <div class="card border-left-warning shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-warning text-uppercase mb-1">
                                Pending CEO Approval</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">
                                <?php echo $pending_stats['total_pending_bonuses'] ?? 0; ?>
                            </div>
                            <div class="text-xs text-muted">
                                Value: <?php echo format_payroll_currency($pending_stats['total_pending_amount'] ?? 0); ?>
                            </div>
                        </div>
                        <div class="col-auto">
                            <i class="bi bi-clock fa-2x text-warning"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <div class="col-xl-3 col-md-6 mb-4">
            <div class="card border-left-success shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-success text-uppercase mb-1">
                                Approved Incentives</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">
                                <?php echo $approved_stats['total_approved'] ?? 0; ?>
                            </div>
                            <div class="text-xs text-muted">
                                Value: <?php echo format_payroll_currency(($approved_stats['total_bonuses'] ?? 0) + ($approved_stats['total_other_incentives'] ?? 0) - ($approved_stats['total_deductions'] ?? 0)); ?>
                            </div>
                        </div>
                        <div class="col-auto">
                            <i class="bi bi-check-circle fa-2x text-success"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6 mb-4">
            <div class="card border-left-info shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-info text-uppercase mb-1">
                                Overtime Rate (per hour)</div>
                            <div class="h6 mb-0 font-weight-bold text-gray-800">
                                <?php echo format_payroll_currency($overtime_rate); ?>
                            </div>
                            <div class="text-xs text-muted">
                                Approved: <?php echo $approved_stats['total_overtime_hours'] ?? 0; ?> hrs
                            </div>
                        </div>
                        <div class="col-auto">
                            <i class="bi bi-clock-history fa-2x text-info"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Pending CEO Approval Section (Bonuses only) -->
    <?php if (!empty($pending_bonuses) && $current_user_role === 'ceo'): ?>
    <div class="card shadow mb-4 border-warning">
        <div class="card-header bg-warning text-dark py-3 d-flex justify-content-between align-items-center">
            <h6 class="m-0 font-weight-bold">
                <i class="bi bi-clock-history me-2"></i>
                Pending CEO Approval (Bonuses) - <?php echo date('F Y', strtotime($filter_month . '-01')); ?>
                <span class="badge bg-danger ms-2"><?php echo count($pending_bonuses); ?></span>
            </h6>
            <?php if ($current_user_role === 'ceo' && !empty($pending_bonuses)): ?>
            <form method="POST" class="d-flex align-items-center gap-2" id="bulkIncentiveForm">
                <select name="bulk_action" class="form-select form-select-sm" style="width: 120px;" required>
                    <option value="">Bulk Action</option>
                    <option value="approve">Approve All</option>
                    <option value="reject">Reject All</option>
                </select>
                <button type="submit" name="bulk_action_incentives" class="btn btn-sm btn-primary">
                    Apply
                </button>
            </form>
            <?php endif; ?>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-bordered table-hover" id="pendingBonusesTable">
                    <thead class="table-warning">
                        <tr>
                            <?php if ($current_user_role === 'ceo'): ?>
                            <th width="50">
                                <input type="checkbox" id="selectAllBonuses">
                            </th>
                            <?php endif; ?>
                            <th>Employee</th>
                            <th>Description</th>
                            <th>Amount</th>
                            <th>Created By</th>
                            <th>Date</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pending_bonuses as $bonus): ?>
                            <tr>
                                <?php if ($current_user_role === 'ceo'): ?>
                                <td>
                                    <input type="checkbox" name="selected_incentives[]" 
                                           value="<?php echo $bonus['id']; ?>" 
                                           class="bonus-checkbox">
                                </td>
                                <?php endif; ?>
                                <td>
                                    <strong><?php echo htmlspecialchars($bonus['full_name'] ?? ''); ?></strong>
                                    <br><small class="text-muted"><?php echo htmlspecialchars($bonus['job_title'] ?? ''); ?></small>
                                </td>
                                <td><?php echo htmlspecialchars($bonus['description'] ?? ''); ?>
                                    <?php if (!empty($bonus['is_bulk'])): ?>
                                        <br><small class="text-muted">Bulk bonus</small>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end">
                                    <strong class="text-success">
                                        <?php echo format_payroll_currency($bonus['amount'] ?? 0); ?>
                                    </strong>
                                </td>
                                <td>
                                    <?php echo htmlspecialchars($bonus['created_by_name'] ?? ''); ?>
                                    <br><small class="text-muted"><?php echo !empty($bonus['created_at']) ? date('d/m', strtotime($bonus['created_at'])) : ''; ?></small>
                                </td>
                                <td>
                                    <?php echo !empty($bonus['pay_period_month']) ? date('d M', strtotime($bonus['pay_period_month'] . '-01')) : ''; ?>
                                </td>
                                <td>
                                    <div class="btn-group btn-group-sm">
                                        <?php if ($current_user_role === 'ceo'): ?>
                                            <!-- CEO Actions: Approve/Reject -->
                                            <form method="POST" class="d-inline">
                                                <input type="hidden" name="incentive_id" value="<?php echo $bonus['id']; ?>">
                                                <input type="hidden" name="action" value="approve">
                                                <button type="submit" name="approve_incentive" 
                                                        class="btn btn-sm btn-success" title="Approve Bonus"
                                                        onclick="return confirm('Approve this bonus?')">
                                                    <i class="bi bi-check"></i>
                                                </button>
                                            </form>
                                            <form method="POST" class="d-inline">
                                                <input type="hidden" name="incentive_id" value="<?php echo $bonus['id']; ?>">
                                                <input type="hidden" name="action" value="reject">
                                                <button type="submit" name="approve_incentive" 
                                                        class="btn btn-sm btn-danger" title="Reject Bonus"
                                                        onclick="return confirm('Reject this bonus?')">
                                                    <i class="bi bi-x"></i>
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- HR View: Pending Bonuses Waiting for CEO -->
    <?php if ($current_user_role === 'hr_manager' && !empty($pending_bonuses)): ?>
    <div class="card shadow mb-4 border-info">
        <div class="card-header bg-info py-3">
            <h6 class="m-0 font-weight-bold">
                <i class="bi bi-clock me-2"></i>
                Bonuses Pending CEO Approval - <?php echo date('F Y', strtotime($filter_month . '-01')); ?>
                <span class="badge bg-warning ms-2"><?php echo count($pending_bonuses); ?></span>
            </h6>
        </div>
        <div class="card-body">
            <div class="alert alert-warning">
                <i class="bi bi-info-circle me-2"></i>
                These bonuses are waiting for CEO approval. They will be included in payroll once approved.
            </div>
            <div class="table-responsive">
                <table class="table table-bordered table-hover">
                    <thead class="table-info">
                        <tr>
                            <th>Employee</th>
                            <th>Description</th>
                            <th>Amount</th>
                            <th>Created Date</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pending_bonuses as $bonus): ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars($bonus['full_name'] ?? ''); ?></strong>
                                    <br><small class="text-muted"><?php echo htmlspecialchars($bonus['job_title'] ?? ''); ?></small>
                                </td>
                                <td><?php echo htmlspecialchars($bonus['description'] ?? ''); ?>
                                    <?php if (!empty($bonus['is_bulk'])): ?>
                                        <br><small class="text-muted">Bulk bonus</small>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end">
                                    <strong class="text-success">
                                        <?php echo format_payroll_currency($bonus['amount'] ?? 0); ?>
                                    </strong>
                                </td>
                                <td>
                                    <?php echo !empty($bonus['created_at']) ? date('d M Y', strtotime($bonus['created_at'])) : ''; ?>
                                </td>
                                <td>
                                    <form method="POST" class="d-inline">
                                        <input type="hidden" name="incentive_id" value="<?php echo $bonus['id']; ?>">
                                        <button type="submit" name="delete_incentive" 
                                                class="btn btn-sm btn-outline-danger" title="Delete"
                                                onclick="return confirm('Are you sure you want to delete this pending bonus?');">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Approved Incentives Section -->
    <div class="card shadow mb-4 border-success">
        <div class="card-header bg-success py-3">
            <h6 class="m-0 font-weight-bold">
                <i class="bi bi-check-circle me-2"></i>
                Approved Incentives for <?php echo date('F Y', strtotime($filter_month . '-01')); ?>
                <span class="badge bg-light text-dark ms-2"><?php echo $approved_stats['total_approved'] ?? 0; ?></span>
            </h6>
        </div>
        <div class="card-body">
            <?php if (empty($approved_incentives)): ?>
                <div class="text-center py-4">
                    <i class="bi bi-inbox display-4 text-muted mb-3"></i>
                    <h5>No approved incentives</h5>
                    <p class="text-muted">All incentives for <?php echo date('F Y', strtotime($filter_month . '-01')); ?> have been processed.</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-bordered table-hover" id="approvedIncentivesTable">
                        <thead class="table-success">
                            <tr>
                                <th>Employee</th>
                                <th>Type</th>
                                <th>Description</th>
                                <th>Amount</th>
                                <th>Hours/Rate</th>
                                <th>Approved By</th>
                                <th>Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($approved_incentives as $incentive): ?>
                                <tr>
                                    <td>
                                        <strong><?php echo htmlspecialchars($incentive['full_name'] ?? ''); ?></strong>
                                        <br><small class="text-muted"><?php echo htmlspecialchars($incentive['job_title'] ?? ''); ?></small>
                                    </td>
                                    <td>
                                        <span class="badge bg-<?php echo ($incentive['incentive_type'] ?? '') == 'deduction' ? 'danger' : 'success'; ?>">
                                            <?php echo get_incentive_type_display($incentive['incentive_type'] ?? ''); ?>
                                        </span>
                                        <?php if (!empty($incentive['is_bulk'])): ?>
                                            <br><small class="text-muted">Bulk</small>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($incentive['description'] ?? ''); ?></td>
                                    <td class="text-end">
                                        <strong class="<?php echo ($incentive['incentive_type'] ?? '') == 'deduction' ? 'text-danger' : 'text-success'; ?>">
                                            <?php echo format_payroll_currency($incentive['amount'] ?? 0); ?>
                                        </strong>
                                    </td>
                                    <td class="text-center">
                                        <?php if (($incentive['incentive_type'] ?? '') == 'overtime'): ?>
                                            <?php if (!empty($incentive['hours'])): ?>
                                                <small><?php echo $incentive['hours']; ?> hrs</small><br>
                                            <?php endif; ?>
                                            <?php if (!empty($incentive['overtime_rate'])): ?>
                                                <small>@ <?php echo format_payroll_currency($incentive['overtime_rate']); ?>/hr</small>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            -
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php 
                                        if ($incentive['incentive_type'] == 'bonus' && !empty($incentive['approved_by_name'])) {
                                            echo htmlspecialchars($incentive['approved_by_name']) . ' (CEO)';
                                        } elseif ($incentive['incentive_type'] == 'bonus') {
                                            echo 'CEO';
                                        } elseif (!empty($incentive['approved_by_name'])) {
                                            echo htmlspecialchars($incentive['approved_by_name']);
                                        } else {
                                            echo 'Auto-approved';
                                        }
                                        ?>
                                        <br><small class="text-muted"><?php echo !empty($incentive['approved_at']) ? date('d/m H:i', strtotime($incentive['approved_at'])) : ''; ?></small>
                                    </td>
                                    <td>
                                        <?php echo !empty($incentive['pay_period_month']) ? date('d M', strtotime($incentive['pay_period_month'] . '-01')) : ''; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <?php if (($approved_stats['total_bonuses'] ?? 0) > 0 || ($approved_stats['total_other_incentives'] ?? 0) > 0 || ($approved_stats['total_deductions'] ?? 0) > 0): ?>
                        <tfoot class="table-light">
                            <tr>
                                <td colspan="3" class="text-end"><strong>Totals:</strong></td>
                                <td class="text-end text-success">
                                    <strong>Bonuses: <?php echo format_payroll_currency($approved_stats['total_bonuses'] ?? 0); ?></strong><br>
                                    <small>Other: <?php echo format_payroll_currency($approved_stats['total_other_incentives'] ?? 0); ?></small>
                                </td>
                                <td colspan="2" class="text-end text-danger">
                                    <strong>Deductions: <?php echo format_payroll_currency($approved_stats['total_deductions'] ?? 0); ?></strong><br>
                                    <small>Overtime: <?php echo format_payroll_currency($approved_stats['total_overtime_amount'] ?? 0); ?></small>
                                </td>
                                <td colspan="2" class="text-end">
                                    <strong>Net: <?php echo format_payroll_currency(
                                        ($approved_stats['total_bonuses'] ?? 0) + 
                                        ($approved_stats['total_other_incentives'] ?? 0) - 
                                        ($approved_stats['total_deductions'] ?? 0)
                                    ); ?></strong>
                                </td>
                            </tr>
                        </tfoot>
                        <?php endif; ?>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Rejected Bonuses Section (CEO only) -->
    <?php if (!empty($rejected_bonuses) && $current_user_role === 'ceo'): ?>
    <div class="card shadow mb-4 border-danger">
        <div class="card-header bg-danger py-3">
            <h6 class="m-0 font-weight-bold">
                <i class="bi bi-x-circle me-2"></i>
                Rejected Bonuses - <?php echo date('F Y', strtotime($filter_month . '-01')); ?>
                <span class="badge bg-light text-dark ms-2"><?php echo count($rejected_bonuses); ?></span>
                <button class="btn btn-sm btn-light float-end" type="button" data-bs-toggle="collapse" data-bs-target="#rejectedBonuses">
                    <i class="bi bi-chevron-down"></i>
                </button>
            </h6>
        </div>
        <div class="collapse" id="rejectedBonuses">
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-bordered table-hover">
                        <thead class="table-danger">
                            <tr>
                                <th>Employee</th>
                                <th>Description</th>
                                <th>Amount</th>
                                <th>Rejected By</th>
                                <th>Date</th>
                                <th>Reason</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rejected_bonuses as $bonus): ?>
                                <tr>
                                    <td>
                                        <strong><?php echo htmlspecialchars($bonus['full_name'] ?? ''); ?></strong>
                                        <br><small class="text-muted"><?php echo htmlspecialchars($bonus['job_title'] ?? ''); ?></small>
                                    </td>
                                    <td><?php echo htmlspecialchars($bonus['description'] ?? ''); ?></td>
                                    <td class="text-end">
                                        <span class="text-danger">
                                            <?php echo format_payroll_currency($bonus['amount'] ?? 0); ?>
                                        </span>
                                    </td>
                                    <td><?php echo htmlspecialchars($bonus['approved_by_name'] ?? 'N/A'); ?> (CEO)</td>
                                    <td>
                                        <?php echo !empty($bonus['approved_at']) ? date('d M H:i', strtotime($bonus['approved_at'])) : ''; ?>
                                    </td>
                                    <td class="text-muted"><?php echo htmlspecialchars($bonus['notes'] ?? 'Rejected by CEO'); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Employee Salary List (HR only) -->
    <?php if ($current_user_role === 'hr_manager'): ?>
    <div class="card shadow">
        <div class="card-header py-3 d-flex justify-content-between align-items-center">
            <h6 class="m-0 font-weight-bold text-primary">
                <i class="bi bi-people me-2"></i>Employee Salary Overview
            </h6>
            <div class="d-flex gap-2">
                <input type="text" class="form-control form-control-sm" id="salarySearch" 
                       placeholder="Search employees..." style="width: 200px;">
                <span class="badge bg-primary align-self-center">
                    Total: <?php echo format_payroll_currency($total_salary); ?>
                </span>
            </div>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-bordered table-hover" id="salaryTable">
                    <thead class="table-light">
                        <tr>
                            <th>Employee</th>
                            <th>Username</th>
                            <th>Job Title</th>
                            <th>Salary Level</th>
                            <th>Current Salary</th>
                            <th>Last Review</th>
                            <th>Hire Date</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $user): ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars($user['full_name'] ?? ''); ?></strong>
                                </td>
                                <td><?php echo htmlspecialchars($user['username'] ?? ''); ?></td>
                                <td><?php echo htmlspecialchars($user['job_title'] ?? ''); ?></td>
                                <td>
                                    <?php if (!empty($user['salary_level'])): ?>
                                        <span class="badge bg-info"><?php echo htmlspecialchars($user['salary_level']); ?></span>
                                    <?php else: ?>
                                        <span class="text-muted">Not set</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end">
                                    <strong class="text-success">
                                        <?php echo format_payroll_currency($user['salary'] ?? 0, $user['currency'] ?? 'TZS'); ?>
                                    </strong>
                                </td>
                                <td>
                                    <?php 
                                    if (!empty($user['last_salary_review'])) {
                                        echo date('d M Y', strtotime($user['last_salary_review']));
                                    } else {
                                        echo 'Never';
                                    }
                                    ?>
                                </td>
                                <td>
                                    <?php 
                                    if (!empty($user['hire_date'])) {
                                        echo date('d M Y', strtotime($user['hire_date']));
                                    } else {
                                        echo 'Not set';
                                    }
                                    ?>
                                </td>
                                <td>
                                    <div class="btn-group btn-group-sm">
                                        <button class="btn btn-outline-info" 
                                                onclick="viewSalaryHistory(<?php echo $user['id']; ?>, '<?php echo htmlspecialchars(addslashes($user['full_name'] ?? '')); ?>')"
                                                title="View History">
                                            <i class="bi bi-clock-history"></i>
                                        </button>
                                        <button class="btn btn-outline-warning" 
                                                onclick="editSalary(<?php echo htmlspecialchars(json_encode([
                                                    'id' => $user['id'] ?? 0,
                                                    'full_name' => $user['full_name'] ?? '',
                                                    'username' => $user['username'] ?? '',
                                                    'salary' => $user['salary'] ?? 0,
                                                    'job_title' => $user['job_title'] ?? '',
                                                    'salary_level' => $user['salary_level'] ?? '',
                                                    'currency' => $user['currency'] ?? 'TZS'
                                                ])); ?>)"
                                                title="Edit Salary">
                                            <i class="bi bi-pencil"></i>
                                        </button>
                                        <button class="btn btn-outline-primary" 
                                                onclick="addIncentiveForUser(<?php echo $user['id']; ?>, '<?php echo htmlspecialchars(addslashes($user['full_name'] ?? '')); ?>')"
                                                title="Add Incentive">
                                            <i class="bi bi-plus-circle"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- Modals -->

<!-- Update Salary Modal (HR only) -->
<?php if ($current_user_role === 'hr_manager'): ?>
<div class="modal fade" id="updateSalaryModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" id="updateSalaryForm">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="bi bi-currency-dollar me-2"></i>Update Employee Salary
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Select Employee <span class="text-danger">*</span></label>
                            <select name="user_id" class="form-select" id="salaryUserSelect" required>
                                <option value="">Select Employee</option>
                                <?php foreach ($users as $user): ?>
                                    <?php if ($user['role'] !== 'ceo'): ?>
                                    <option value="<?php echo $user['id']; ?>"
                                            data-salary="<?php echo htmlspecialchars($user['salary'] ?? 0); ?>"
                                            data-jobtitle="<?php echo htmlspecialchars($user['job_title'] ?? ''); ?>"
                                            data-level="<?php echo htmlspecialchars($user['salary_level'] ?? ''); ?>">
                                        <?php echo htmlspecialchars(($user['full_name'] ?? '') . ' (' . ($user['username'] ?? '') . ')'); ?>
                                    </option>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Current Salary</label>
                            <input type="text" class="form-control" id="currentSalaryDisplay" readonly>
                        </div>
                    </div>
                    
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label">New Salary <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text">TZS</span>
                                <input type="number" name="new_salary" class="form-control" 
                                       step="0.01" min="0" required id="newSalaryInput">
                            </div>
                            <div class="mt-2">
                                <small class="text-muted" id="salaryChangeInfo"></small>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Effective Date <span class="text-danger">*</span></label>
                            <input type="date" name="effective_date" class="form-control" 
                                   value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                    </div>
                    
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Job Title</label>
                            <input type="text" name="job_title" class="form-control" 
                                   id="jobTitleInput" placeholder="Enter new job title">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Salary Level</label>
                            <select name="salary_level" class="form-select" id="salaryLevelSelect">
                                <option value="">Select Level</option>
                                <?php 
                                $salary_levels = [
                                    'Entry' => 'Entry Level',
                                    'Junior' => 'Junior',
                                    'Mid' => 'Mid Level',
                                    'Senior' => 'Senior',
                                    'Lead' => 'Lead',
                                    'Manager' => 'Manager',
                                    'Director' => 'Director',
                                    'Executive' => 'Executive'
                                ];
                                foreach ($salary_levels as $value => $label): ?>
                                    <option value="<?php echo htmlspecialchars($value); ?>"><?php echo htmlspecialchars($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Reason for Change <span class="text-danger">*</span></label>
                        <textarea name="change_reason" class="form-control" rows="3" required
                                  placeholder="Enter reason for salary change (promotion, annual increment, adjustment, etc.)"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="update_salary" class="btn btn-primary">
                        <i class="bi bi-save me-2"></i>Update Salary
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Add Incentive Modal (HR only) -->
<?php if ($current_user_role === 'hr_manager'): ?>
<div class="modal fade" id="addIncentiveModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" id="addIncentiveForm">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="bi bi-plus-circle me-2"></i>Add Incentive
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle me-2"></i>
                        <strong>Note:</strong> Bonuses require CEO approval. Other incentives are auto-approved.
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Apply To <span class="text-danger">*</span></label>
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="radio" name="target_type" id="targetAll" value="all" checked onclick="toggleTargetType()">
                            <label class="form-check-label" for="targetAll">All Employees</label>
                        </div>
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="radio" name="target_type" id="targetSpecific" value="specific" onclick="toggleTargetType()">
                            <label class="form-check-label" for="targetSpecific">Specific Employee</label>
                        </div>
                    </div>
                    
                    <div id="specificEmployeeSection" style="display: none;">
                        <div class="mb-3">
                            <label class="form-label">Select Employee <span class="text-danger">*</span></label>
                            <select name="user_id" class="form-select" id="incentiveUserSelect">
                                <option value="">Select Employee</option>
                                <?php foreach ($users as $user): ?>
                                    <?php if ($user['role'] !== 'ceo'): ?>
                                    <option value="<?php echo $user['id']; ?>">
                                        <?php echo htmlspecialchars(($user['full_name'] ?? '') . ' (' . ($user['username'] ?? '') . ')'); ?>
                                    </option>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Incentive Type <span class="text-danger">*</span></label>
                            <select name="incentive_type" class="form-select" required id="incentiveTypeSelect" onchange="updateIncentiveInfo()">
                                <option value="">Select Type</option>
                                <option value="bonus">Bonus (Requires CEO Approval)</option>
                                <option value="allowance">Allowance (Auto-approved)</option>
                                <option value="commission">Commission (Auto-approved)</option>
                                <option value="deduction">Deduction (Auto-approved)</option>
                                <option value="other">Other (Auto-approved)</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Pay Period Month <span class="text-danger">*</span></label>
                            <input type="month" name="pay_period_month" class="form-control" 
                                   value="<?php echo date('Y-m'); ?>" required>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Description <span class="text-danger">*</span></label>
                        <input type="text" name="description" class="form-control" required 
                               placeholder="e.g., Year-end bonus, Transportation allowance, Late deduction, etc.">
                    </div>
                    
                    <!-- Amount for All Employees -->
                    <div id="allEmployeesAmountSection">
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label class="form-label">Amount Type <span class="text-danger">*</span></label>
                                <select name="amount_type" class="form-select" onchange="toggleAmountType()">
                                    <option value="percentage">Percentage of Salary</option>
                                    <option value="fixed">Fixed Amount</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Amount Value <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <span class="input-group-text" id="amountPrefix">%</span>
                                    <input type="number" name="amount_value" class="form-control" 
                                           step="0.01" min="0.01" required value="10">
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Amount for Specific Employee -->
                    <div id="specificEmployeeAmountSection" style="display: none;">
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label class="form-label">Amount Type <span class="text-danger">*</span></label>
                                <select name="specific_amount_type" class="form-select" onchange="toggleSpecificAmountType()">
                                    <option value="percentage">Percentage of Salary</option>
                                    <option value="fixed">Fixed Amount</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Amount Value <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <span class="input-group-text" id="specificAmountPrefix">%</span>
                                    <input type="number" name="specific_amount_value" class="form-control" 
                                           step="0.01" min="0.01" required value="10">
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Notes (Optional)</label>
                        <textarea name="notes" class="form-control" rows="2" 
                                  placeholder="Additional notes or details"></textarea>
                    </div>
                    
                    <div class="alert" id="incentiveStatusAlert">
                        <!-- Dynamic status message based on incentive type -->
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="add_incentive" class="btn btn-primary">
                        <i class="bi bi-plus-circle me-2"></i>Add Incentive
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Add Overtime Modal (HR only) -->
<?php if ($current_user_role === 'hr_manager'): ?>
<div class="modal fade" id="addOvertimeModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="bi bi-clock me-2"></i>Add Overtime
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-success">
                        <i class="bi bi-info-circle me-2"></i>
                        Overtime is auto-approved and will be included in payroll immediately.
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Select Employee <span class="text-danger">*</span></label>
                        <select name="user_id" class="form-select" required>
                            <option value="">Select Employee</option>
                            <?php foreach ($users as $user): ?>
                                <?php if ($user['role'] !== 'ceo'): ?>
                                <option value="<?php echo $user['id']; ?>">
                                    <?php echo htmlspecialchars(($user['full_name'] ?? '') . ' (' . ($user['username'] ?? '') . ')'); ?>
                                </option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Overtime Date <span class="text-danger">*</span></label>
                            <input type="date" name="overtime_date" class="form-control" 
                                   value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Hours Worked <span class="text-danger">*</span></label>
                            <input type="number" name="hours" class="form-control" 
                                   step="0.5" min="0.5" required placeholder="e.g., 2.5">
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Description <span class="text-danger">*</span></label>
                        <input type="text" name="overtime_description" class="form-control" required 
                               placeholder="e.g., Weekend work, Evening shift, etc.">
                    </div>
                    
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle me-2"></i>
                        Overtime rate: <?php echo format_payroll_currency($overtime_rate); ?> per hour
                        <button type="button" class="btn btn-sm btn-outline-primary ms-2" 
                                onclick="showOvertimeRateModal()">
                            Change Rate
                        </button>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="add_overtime" class="btn btn-primary">
                        <i class="bi bi-plus-circle me-2"></i>Add Overtime
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Set Overtime Rate Modal (HR only) -->
<?php if ($current_user_role === 'hr_manager'): ?>
<div class="modal fade" id="setOvertimeRateModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="bi bi-clock-history me-2"></i>Set Overtime Rate
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Overtime Rate per Hour (TZS) <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <span class="input-group-text">TZS</span>
                            <input type="number" name="overtime_rate" class="form-control" 
                                   step="100" min="100" required value="<?php echo $overtime_rate; ?>">
                        </div>
                        <small class="text-muted">This rate will be used for all overtime calculations.</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="set_overtime_rate" class="btn btn-primary">
                        <i class="bi bi-save me-2"></i>Save Rate
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- View Salary History Modal -->
<div class="modal fade" id="viewSalaryHistoryModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="bi bi-clock-history me-2"></i>Salary History - 
                    <span id="historyEmployeeName"></span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <?php if (!empty($salary_history)): ?>
                    <div class="table-responsive">
                        <table class="table table-bordered table-hover">
                            <thead class="table-light">
                                <tr>
                                    <th>Effective Date</th>
                                    <th>Old Salary</th>
                                    <th>New Salary</th>
                                    <th>Change %</th>
                                    <th>Old Job Title</th>
                                    <th>New Job Title</th>
                                    <th>Salary Level</th>
                                    <th>Reason</th>
                                    <th>Approved By</th>
                                    <th>Date</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($salary_history as $history): ?>
                                    <?php
                                    $old_salary = (float)($history['old_salary'] ?? 0);
                                    $new_salary = (float)($history['new_salary'] ?? 0);
                                    $change_percent = calculate_salary_change_percent($old_salary, $new_salary);
                                    $change_class = $change_percent >= 0 ? 'text-success' : 'text-danger';
                                    $change_sign = $change_percent >= 0 ? '+' : '';
                                    ?>
                                    <tr>
                                        <td><?php echo !empty($history['effective_date']) ? date('d M Y', strtotime($history['effective_date'])) : '-'; ?></td>
                                        <td class="text-end"><?php echo format_payroll_currency($old_salary); ?></td>
                                        <td class="text-end"><strong><?php echo format_payroll_currency($new_salary); ?></strong></td>
                                        <td class="text-end">
                                            <span class="badge <?php echo $change_percent >= 0 ? 'bg-success' : 'bg-danger'; ?>">
                                                <?php echo $change_sign . $change_percent; ?>%
                                            </span>
                                        </td>
                                        <td><?php echo htmlspecialchars($history['old_job_title'] ?? ''); ?></td>
                                        <td><?php echo htmlspecialchars($history['job_title'] ?? ''); ?></td>
                                        <td>
                                            <?php if (!empty($history['salary_level'])): ?>
                                                <span class="badge bg-info"><?php echo htmlspecialchars($history['salary_level']); ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo htmlspecialchars($history['change_reason'] ?? ''); ?></td>
                                        <td><?php echo htmlspecialchars($history['approved_by_name'] ?? ''); ?></td>
                                        <td><?php echo !empty($history['created_at']) ? date('d M Y', strtotime($history['created_at'])) : '-'; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="text-center py-5">
                        <i class="bi bi-clock-history display-4 text-muted mb-3"></i>
                        <h5>No salary history found</h5>
                        <p class="text-muted">No salary changes have been recorded for this employee.</p>
                    </div>
                <?php endif; ?>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- JavaScript -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Salary update form logic
    const salaryUserSelect = document.getElementById('salaryUserSelect');
    const newSalaryInput = document.getElementById('newSalaryInput');
    const jobTitleInput = document.getElementById('jobTitleInput');
    const salaryLevelSelect = document.getElementById('salaryLevelSelect');
    const currentSalaryDisplay = document.getElementById('currentSalaryDisplay');
    const salaryChangeInfo = document.getElementById('salaryChangeInfo');
    
    if (salaryUserSelect) {
        salaryUserSelect.addEventListener('change', function() {
            const selectedOption = this.options[this.selectedIndex];
            if (selectedOption.value) {
                const currentSalary = selectedOption.getAttribute('data-salary') || 0;
                const jobTitle = selectedOption.getAttribute('data-jobtitle') || '';
                const salaryLevel = selectedOption.getAttribute('data-level') || '';
                
                currentSalaryDisplay.value = formatCurrency(currentSalary);
                newSalaryInput.value = currentSalary;
                jobTitleInput.value = jobTitle;
                salaryLevelSelect.value = salaryLevel;
                
                updateSalaryChangeInfo(currentSalary, currentSalary);
            } else {
                currentSalaryDisplay.value = '';
                newSalaryInput.value = '';
                jobTitleInput.value = '';
                salaryLevelSelect.value = '';
                salaryChangeInfo.textContent = '';
            }
        });
    }
    
    if (newSalaryInput) {
        newSalaryInput.addEventListener('input', function() {
            const currentSalary = parseFloat(salaryUserSelect.options[salaryUserSelect.selectedIndex]?.getAttribute('data-salary') || 0);
            const newSalary = parseFloat(this.value) || 0;
            updateSalaryChangeInfo(currentSalary, newSalary);
        });
    }
    
    function updateSalaryChangeInfo(oldSalary, newSalary) {
        oldSalary = parseFloat(oldSalary) || 0;
        newSalary = parseFloat(newSalary) || 0;
        
        if (oldSalary > 0 && newSalary > 0) {
            const change = newSalary - oldSalary;
            const percent = ((change / oldSalary) * 100).toFixed(2);
            const changeType = change >= 0 ? 'increase' : 'decrease';
            const changeClass = change >= 0 ? 'text-success' : 'text-danger';
            const sign = change >= 0 ? '+' : '';
            
            salaryChangeInfo.innerHTML = `
                <span class="${changeClass}">
                    ${sign}${formatCurrency(Math.abs(change))} (${sign}${Math.abs(percent)}%) ${changeType}
                </span>
            `;
        } else {
            salaryChangeInfo.textContent = '';
        }
    }
    
    // Search functionality
    const salarySearch = document.getElementById('salarySearch');
    if (salarySearch) {
        salarySearch.addEventListener('input', function() {
            const term = this.value.toLowerCase();
            const rows = document.querySelectorAll('#salaryTable tbody tr');
            
            rows.forEach(row => {
                const text = row.textContent.toLowerCase();
                row.style.display = text.includes(term) ? '' : 'none';
            });
        });
    }
    
    // Select all bonuses checkbox
    const selectAllBonuses = document.getElementById('selectAllBonuses');
    if (selectAllBonuses) {
        selectAllBonuses.addEventListener('change', function() {
            const checkboxes = document.querySelectorAll('.bonus-checkbox');
            checkboxes.forEach(cb => cb.checked = this.checked);
        });
    }
    
    // Bulk incentive form submission
    const bulkIncentiveForm = document.getElementById('bulkIncentiveForm');
    if (bulkIncentiveForm) {
        bulkIncentiveForm.addEventListener('submit', function(e) {
            const selected = document.querySelectorAll('.bonus-checkbox:checked');
            const action = this.querySelector('select[name="bulk_action"]').value;
            
            if (selected.length === 0) {
                e.preventDefault();
                alert('Please select at least one bonus.');
                return;
            }
            
            if (!action) {
                e.preventDefault();
                alert('Please select a bulk action.');
                return;
            }
            
            if (!confirm(`Are you sure you want to ${action} ${selected.length} bonus(es)?`)) {
                e.preventDefault();
            }
        });
    }
    
    <?php if (!empty($salary_history) && $view_salary_history): ?>
    // Show salary history modal if loaded
    const historyModal = new bootstrap.Modal(document.getElementById('viewSalaryHistoryModal'));
    historyModal.show();
    <?php endif; ?>
    
    // Update incentive info on type change
    updateIncentiveInfo();
});

// Helper functions
function formatCurrency(amount) {
    amount = parseFloat(amount) || 0;
    return 'TZS ' + amount.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
}

// Incentive modal functions
function toggleTargetType() {
    const targetAll = document.getElementById('targetAll');
    const specificSection = document.getElementById('specificEmployeeSection');
    const allAmountSection = document.getElementById('allEmployeesAmountSection');
    const specificAmountSection = document.getElementById('specificEmployeeAmountSection');
    
    if (targetAll.checked) {
        specificSection.style.display = 'none';
        allAmountSection.style.display = 'block';
        specificAmountSection.style.display = 'none';
        
        // Clear specific employee selection
        document.getElementById('incentiveUserSelect').value = '';
        // Reset specific amount type to percentage
        document.querySelector('select[name="specific_amount_type"]').value = 'percentage';
        toggleSpecificAmountType();
    } else {
        specificSection.style.display = 'block';
        allAmountSection.style.display = 'none';
        specificAmountSection.style.display = 'block';
    }
}

function toggleAmountType() {
    const select = document.querySelector('select[name="amount_type"]');
    const prefix = document.getElementById('amountPrefix');
    
    if (select.value === 'percentage') {
        prefix.textContent = '%';
        document.querySelector('input[name="amount_value"]').value = '10';
    } else {
        prefix.textContent = 'TZS';
        document.querySelector('input[name="amount_value"]').value = '50000';
    }
}

function toggleSpecificAmountType() {
    const select = document.querySelector('select[name="specific_amount_type"]');
    const prefix = document.getElementById('specificAmountPrefix');
    
    if (select.value === 'percentage') {
        prefix.textContent = '%';
        document.querySelector('input[name="specific_amount_value"]').value = '10';
    } else {
        prefix.textContent = 'TZS';
        document.querySelector('input[name="specific_amount_value"]').value = '50000';
    }
}

function updateIncentiveInfo() {
    const select = document.getElementById('incentiveTypeSelect');
    const alertDiv = document.getElementById('incentiveStatusAlert');
    
    if (!select || !alertDiv) return;
    
    const value = select.value;
    
    switch(value) {
        case 'bonus':
            alertDiv.className = 'alert alert-warning';
            alertDiv.innerHTML = '<i class="bi bi-exclamation-triangle me-2"></i><strong>Bonus:</strong> Requires CEO approval before being included in payroll.';
            break;
        case 'allowance':
        case 'commission':
        case 'other':
            alertDiv.className = 'alert alert-success';
            alertDiv.innerHTML = '<i class="bi bi-check-circle me-2"></i><strong>Auto-approved:</strong> Will be included in payroll immediately.';
            break;
        case 'deduction':
            alertDiv.className = 'alert alert-info';
            alertDiv.innerHTML = '<i class="bi bi-info-circle me-2"></i><strong>Deduction:</strong> Auto-approved and will be deducted from payroll.';
            break;
        default:
            alertDiv.className = 'alert alert-secondary';
            alertDiv.innerHTML = '<i class="bi bi-info-circle me-2"></i>Select an incentive type to see details.';
    }
}

function showOvertimeRateModal() {
    const modal = new bootstrap.Modal(document.getElementById('setOvertimeRateModal'));
    modal.show();
}

// Action functions
function viewSalaryHistory(userId, employeeName) {
    window.location.href = `../hr/salary_setup?view_history=${userId}`;
}

function editSalary(user) {
    const select = document.getElementById('salaryUserSelect');
    for (let option of select.options) {
        if (option.value == user.id) {
            select.value = user.id;
            select.dispatchEvent(new Event('change'));
            break;
        }
    }
    
    const modal = new bootstrap.Modal(document.getElementById('updateSalaryModal'));
    modal.show();
}

function addIncentiveForUser(userId, employeeName) {
    // Select specific employee radio
    document.getElementById('targetSpecific').checked = true;
    toggleTargetType();
    
    // Set the employee
    const select = document.getElementById('incentiveUserSelect');
    select.value = userId;
    
    // Show modal
    const modal = new bootstrap.Modal(document.getElementById('addIncentiveModal'));
    modal.show();
}

// Keyboard shortcuts
document.addEventListener('keydown', function(e) {
    // Ctrl+S to update salary
    if (e.ctrlKey && e.key === 's' && <?php echo $current_user_role === 'hr_manager' ? 'true' : 'false'; ?>) {
        e.preventDefault();
        const modal = new bootstrap.Modal(document.getElementById('updateSalaryModal'));
        modal.show();
    }
    
    // Ctrl+I to add incentive
    if (e.ctrlKey && e.key === 'i' && <?php echo $current_user_role === 'hr_manager' ? 'true' : 'false'; ?>) {
        e.preventDefault();
        const modal = new bootstrap.Modal(document.getElementById('addIncentiveModal'));
        modal.show();
    }
    
    // Ctrl+O to add overtime
    if (e.ctrlKey && e.key === 'o' && <?php echo $current_user_role === 'hr_manager' ? 'true' : 'false'; ?>) {
        e.preventDefault();
        const modal = new bootstrap.Modal(document.getElementById('addOvertimeModal'));
        modal.show();
    }
    
    // Ctrl+R to set overtime rate
    if (e.ctrlKey && e.key === 'r' && <?php echo $current_user_role === 'hr_manager' ? 'true' : 'false'; ?>) {
        e.preventDefault();
        showOvertimeRateModal();
    }
});
</script>

<?php
include '../includes/footer.php';
ob_end_flush(); // End output buffering
?>