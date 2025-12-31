<?php
require_once '../config/config.php';
require_once '../auth/auth_middleware.php';
require_once '../includes/payroll_helpers.php';
require_once '../config/approval_constants.php';

require_hr(); // Only HR can access

$db = getDBConnection();
$page_title = 'Payroll & Salary Management';
$success_message = '';
$error_message = '';

// Handle all POST actions
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        // GENERATE PAYROLL
        if (isset($_POST['generate_payroll'])) {
            $pay_period_start = $_POST['pay_period_start'];
            $pay_period_end = $_POST['pay_period_end'];
            $selected_users = $_POST['user_ids'] ?? [];
            
            if (empty($selected_users)) {
                $error_message = 'Please select at least one employee.';
            } elseif (empty($pay_period_start) || empty($pay_period_end)) {
                $error_message = 'Please select pay period dates.';
            } elseif (strtotime($pay_period_start) > strtotime($pay_period_end)) {
                $error_message = 'Pay period start date must be before end date.';
            } else {
                $generated_count = 0;
                $db->beginTransaction();
                $pay_period_month = date('Y-m', strtotime($pay_period_start));
                
                foreach ($selected_users as $user_id) {
                    $user_id = (int)$user_id;
                    
                    // Check if payroll already exists
                    $check_stmt = $db->prepare("
                        SELECT id FROM payroll 
                        WHERE user_id = ? AND pay_period_start = ? AND pay_period_end = ?
                    ");
                    $check_stmt->execute([$user_id, $pay_period_start, $pay_period_end]);
                    
                    if (!$check_stmt->fetch()) {
                        // Get user details and incentives
                        $user_stmt = $db->prepare("
                            SELECT u.*,
                                   COALESCE(SUM(CASE WHEN pi.incentive_type IN ('allowance', 'bonus', 'commission', 'overtime') 
                                                AND pi.status = 'approved' 
                                                AND pi.pay_period_month = ? 
                                                THEN pi.amount ELSE 0 END), 0) as total_incentives,
                                   COALESCE(SUM(CASE WHEN pi.incentive_type = 'deduction' 
                                                AND pi.status = 'approved' 
                                                AND pi.pay_period_month = ? 
                                                THEN pi.amount ELSE 0 END), 0) as total_deductions
                            FROM users u
                            LEFT JOIN payroll_incentives pi ON u.id = pi.user_id 
                                AND pi.status = 'approved'
                            WHERE u.id = ?
                            GROUP BY u.id
                        ");
                        $user_stmt->execute([$pay_period_month, $pay_period_month, $user_id]);
                        $user = $user_stmt->fetch();
                        
                        if ($user) {
                            $basic_salary = (float)$user['salary'];
                            $total_incentives = (float)$user['total_incentives'];
                            $total_deductions = (float)$user['total_deductions'];
                            
                            $gross_pay = $basic_salary + $total_incentives;
                            
                            // Calculate deductions
                            $tax_amount = calculate_tax_amount($gross_pay);
                            $social_security = calculate_social_security($basic_salary);
                            
                            $net_pay = $gross_pay - $total_deductions - $tax_amount - $social_security;
                            
                            // Insert payroll record
                            $payroll_stmt = $db->prepare("
                                INSERT INTO payroll (
                                    user_id, pay_period_start, pay_period_end, 
                                    basic_salary, total_allowances, total_deductions,
                                    tax_amount, social_security_amount, 
                                    gross_pay, net_pay, currency, 
                                    processed_by, status, created_at
                                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                            ");
                            
                            if ($payroll_stmt->execute([
                                $user_id, $pay_period_start, $pay_period_end,
                                $basic_salary, $total_incentives, $total_deductions,
                                $tax_amount, $social_security,
                                $gross_pay, $net_pay, $user['currency'],
                                $_SESSION['user_id'], PAYROLL_DRAFT
                            ])) {
                                $generated_count++;
                                $payroll_id = $db->lastInsertId();
                                
                                // Create workflow record
                                create_approval_workflow(
                                    WORKFLOW_PAYROLL, ENTITY_PAYROLL, $payroll_id, 
                                    $_SESSION['user_id'], PAYROLL_DRAFT
                                );
                                
                                // Insert payroll items
                                $item_stmt = $db->prepare("
                                    INSERT INTO payroll_items (payroll_id, item_type, item_name, amount)
                                    VALUES (?, ?, ?, ?)
                                ");
                                
                                // Basic salary
                                $item_stmt->execute([$payroll_id, 'salary', 'Basic Salary', $basic_salary]);
                                
                                // Incentives
                                $incentive_items = $db->prepare("
                                    SELECT incentive_type, description, amount 
                                    FROM payroll_incentives 
                                    WHERE user_id = ? 
                                    AND status = 'approved'
                                    AND pay_period_month = ?
                                    AND incentive_type IN ('allowance', 'bonus', 'commission', 'overtime')
                                ");
                                $incentive_items->execute([$user_id, $pay_period_month]);
                                while ($incentive = $incentive_items->fetch()) {
                                    $item_name = get_incentive_type_display($incentive['incentive_type']) . ': ' . $incentive['description'];
                                    $item_stmt->execute([$payroll_id, $incentive['incentive_type'], $item_name, $incentive['amount']]);
                                }
                                
                                // Deductions
                                $deduction_items = $db->prepare("
                                    SELECT description, amount 
                                    FROM payroll_incentives 
                                    WHERE user_id = ? 
                                    AND status = 'approved'
                                    AND pay_period_month = ?
                                    AND incentive_type = 'deduction'
                                ");
                                $deduction_items->execute([$user_id, $pay_period_month]);
                                while ($deduction = $deduction_items->fetch()) {
                                    $item_stmt->execute([$payroll_id, 'deduction', $deduction['description'], $deduction['amount']]);
                                }
                                
                                // Tax and social security
                                if ($tax_amount > 0) {
                                    $item_stmt->execute([$payroll_id, 'tax', 'Income Tax', $tax_amount]);
                                }
                                if ($social_security > 0) {
                                    $item_stmt->execute([$payroll_id, 'social_security', 'Social Security (NSSF)', $social_security]);
                                }
                                
                                // Mark incentives as paid
                                $update_incentives = $db->prepare("
                                    UPDATE payroll_incentives 
                                    SET status = 'paid' 
                                    WHERE user_id = ? 
                                    AND status = 'approved'
                                    AND pay_period_month = ?
                                ");
                                $update_incentives->execute([$user_id, $pay_period_month]);
                                
                                // Log activity
                                $activity_stmt = $db->prepare("
                                    INSERT INTO hr_activities (activity_type, entity_type, entity_id, description, performed_by)
                                    VALUES ('payroll_generated', 'payroll', ?, ?, ?)
                                ");
                                $activity_stmt->execute([
                                    $payroll_id,
                                    "Payroll generated for period {$pay_period_start} to {$pay_period_end}",
                                    $_SESSION['user_id']
                                ]);
                            }
                        }
                    }
                }
                
                $db->commit();
                $success_message = "{$generated_count} payroll records generated successfully.";
                redirect('payroll.php');
            }
        }
        
        // UPDATE SALARY
        elseif (isset($_POST['update_salary'])) {
            $user_id = (int)$_POST['user_id'];
            $new_salary = (float)$_POST['new_salary'];
            $job_title = sanitize_input($_POST['job_title']);
            $salary_level = sanitize_input($_POST['salary_level']);
            $effective_date = $_POST['effective_date'];
            $change_reason = sanitize_input($_POST['change_reason']);
            
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
                    $old_job_title = $current['job_title'];
                    
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
                    redirect('payroll.php');
                } else {
                    $error_message = 'User not found.';
                }
            }
        }
        
        // ADD INCENTIVE
        elseif (isset($_POST['add_incentive'])) {
            $user_id = (int)$_POST['user_id'];
            $incentive_type = sanitize_input($_POST['incentive_type']);
            $description = sanitize_input($_POST['description']);
            $amount = (float)$_POST['amount'];
            $pay_period_month = $_POST['pay_period_month'];
            $notes = sanitize_input($_POST['notes'] ?? '');
            
            if ($amount <= 0) {
                $error_message = 'Amount must be greater than 0.';
            } elseif (empty($description)) {
                $error_message = 'Please provide a description.';
            } else {
                $stmt = $db->prepare("
                    INSERT INTO payroll_incentives (
                        user_id, incentive_type, description, amount, currency,
                        pay_period_month, notes, created_by, status
                    ) VALUES (?, ?, ?, ?, 'TZS', ?, ?, ?, 'pending')
                ");
                
                if ($stmt->execute([$user_id, $incentive_type, $description, $amount, $pay_period_month, $notes, $_SESSION['user_id']])) {
                    $success_message = 'Incentive added successfully. Waiting for approval.';
                    redirect('payroll.php');
                } else {
                    $error_message = 'Failed to add incentive.';
                }
            }
        }
        
        // APPROVE/REJECT INCENTIVE
        elseif (isset($_POST['approve_incentive'])) {
            $incentive_id = (int)$_POST['incentive_id'];
            $action = $_POST['action'];
            
            $status = ($action == 'approve') ? 'approved' : 'rejected';
            
            $stmt = $db->prepare("
                UPDATE payroll_incentives 
                SET status = ?, approved_by = ?, approved_at = NOW()
                WHERE id = ? AND status = 'pending'
            ");
            
            if ($stmt->execute([$status, $_SESSION['user_id'], $incentive_id])) {
                if ($stmt->rowCount() > 0) {
                    $message = ($action == 'approve') ? 'Incentive approved.' : 'Incentive rejected.';
                    $success_message = $message;
                    
                    // Log activity
                    $activity_type = ($action == 'approve') ? 'incentive_approved' : 'incentive_rejected';
                    $activity_stmt = $db->prepare("
                        INSERT INTO hr_activities (activity_type, entity_type, entity_id, description, performed_by)
                        VALUES (?, 'incentive', ?, ?, ?)
                    ");
                    $activity_stmt->execute([
                        $activity_type,
                        $incentive_id,
                        "Incentive {$action}d by HR",
                        $_SESSION['user_id']
                    ]);
                } else {
                    $error_message = 'Incentive not found or already processed.';
                }
                redirect('payroll.php');
            } else {
                $error_message = 'Failed to update incentive status.';
            }
        }
        
        // SUBMIT FOR APPROVAL
        elseif (isset($_POST['submit_for_approval'])) {
            $selected_payroll_ids = $_POST['payroll_ids'] ?? [];
            $submission_reason = sanitize_input($_POST['submission_reason']);
            
            if (empty($selected_payroll_ids)) {
                $error_message = 'Please select at least one payroll to submit.';
            } elseif (empty($submission_reason)) {
                $error_message = 'Please provide a submission reason.';
            } else {
                $result = hr_submit_payroll_for_approval($selected_payroll_ids, $_SESSION['user_id'], $submission_reason);
                
                if ($result['success']) {
                    $success_message = $result['message'];
                } else {
                    $error_message = $result['message'];
                }
                redirect('payroll.php');
            }
        }
        
        // MARK AS PAID
        elseif (isset($_POST['mark_paid'])) {
            if (!check_session_permission('finance_officer')) {
                $error_message = 'Access denied: Finance officer permission required.';
            } else {
                $payroll_id = (int)$_POST['payroll_id'];
                $payment_date = $_POST['payment_date'];
                $payment_method = sanitize_input($_POST['payment_method']);
                $payment_reference = sanitize_input($_POST['payment_reference']);
                
                $stmt = $db->prepare("
                    UPDATE payroll 
                    SET status = 'paid', 
                        payment_date = ?, 
                        payment_method = ?, 
                        payment_reference = ?,
                        finance_processed_by = ?,
                        finance_processed_at = NOW()
                    WHERE id = ? AND status = 'ceo_approved'
                ");
                
                if ($stmt->execute([$payment_date, $payment_method, $payment_reference, $_SESSION['user_id'], $payroll_id])) {
                    if ($stmt->rowCount() > 0) {
                        // Update workflow
                        $workflow = get_approval_workflow(ENTITY_PAYROLL, $payroll_id);
                        if ($workflow) {
                            update_approval_workflow_status(
                                $workflow['id'], 
                                PAYROLL_PAID, 
                                STAGE_FINANCE, 
                                $_SESSION['user_id'], 
                                "Payment processed: {$payment_method} - {$payment_reference}"
                            );
                        }
                        
                        // Log activity
                        $activity_stmt = $db->prepare("
                            INSERT INTO hr_activities (activity_type, entity_type, entity_id, description, performed_by)
                            VALUES ('payroll_paid', 'payroll', ?, ?, ?)
                        ");
                        $activity_stmt->execute([
                            $payroll_id,
                            "Payroll marked as paid. Method: {$payment_method}, Ref: {$payment_reference}",
                            $_SESSION['user_id']
                        ]);
                        
                        $success_message = 'Payroll marked as paid successfully.';
                    } else {
                        $error_message = 'Payroll not found or not approved by CEO.';
                    }
                    redirect('payroll.php');
                } else {
                    $error_message = 'Error updating payroll status.';
                }
            }
        }
        
        // BULK ACTIONS
        elseif (isset($_POST['bulk_action'])) {
            $bulk_action = $_POST['bulk_action'];
            $selected_payrolls = $_POST['selected_payrolls'] ?? [];
            
            if (empty($selected_payrolls)) {
                $error_message = 'Please select at least one payroll.';
            } else {
                $db->beginTransaction();
                $success_count = 0;
                
                foreach ($selected_payrolls as $payroll_id) {
                    $payroll_id = (int)$payroll_id;
                    
                    switch ($bulk_action) {
                        case 'submit':
                            $stmt = $db->prepare("
                                UPDATE payroll 
                                SET status = ? 
                                WHERE id = ? AND status IN (?, ?)
                            ");
                            $stmt->execute([PAYROLL_PENDING_CEO_APPROVAL, $payroll_id, PAYROLL_DRAFT, PAYROLL_CALCULATED]);
                            break;
                            
                        case 'delete':
                            $stmt = $db->prepare("DELETE FROM payroll WHERE id = ? AND status = ?");
                            $stmt->execute([$payroll_id, PAYROLL_DRAFT]);
                            break;
                    }
                    
                    if ($stmt->rowCount() > 0) {
                        $success_count++;
                    }
                }
                
                $db->commit();
                $success_message = "{$success_count} payroll records processed successfully.";
                redirect('payroll.php');
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
$filter_status = $_GET['status'] ?? '';
$view_salary_history = $_GET['view_history'] ?? 0;

// Get payroll records
try {
    $where_conditions = [];
    $params = [];

    if (!empty($filter_month)) {
        $where_conditions[] = "DATE_FORMAT(p.pay_period_start, '%Y-%m') = ?";
        $params[] = $filter_month;
    }

    if (!empty($filter_status)) {
        $where_conditions[] = "p.status = ?";
        $params[] = $filter_status;
    }

    $where_clause = !empty($where_conditions) ? "WHERE " . implode(" AND ", $where_conditions) : "";

    $query = "
        SELECT p.*,
               u.full_name, u.username, u.job_title,
               u.salary_level, u.salary as current_salary,
               hr.full_name as processed_by_name,
               ceo.full_name as ceo_approved_by_name,
               finance.full_name as finance_processed_by_name
        FROM payroll p
        JOIN users u ON p.user_id = u.id
        LEFT JOIN users hr ON p.processed_by = hr.id
        LEFT JOIN users ceo ON p.ceo_approved_by = ceo.id
        LEFT JOIN users finance ON p.finance_processed_by = finance.id
        $where_clause
        ORDER BY p.created_at DESC
    ";

    $stmt = $db->prepare($query);
    $stmt->execute($params);
    $payroll_records = $stmt->fetchAll();
} catch (Exception $e) {
    $payroll_records = [];
    if (empty($error_message)) $error_message = 'Error loading payroll: ' . $e->getMessage();
    error_log("Payroll query error: " . $e->getMessage());
}

// Get users for dropdowns
try {
    $user_stmt = $db->query("
        SELECT u.id, u.username, u.full_name, 
               u.salary, u.currency, u.job_title,
               u.salary_level, u.hire_date, u.last_salary_review,
               u.status
        FROM users u
        WHERE u.status = 'active'
        AND u.role NOT IN ('system_admin')
        ORDER BY u.full_name
    ");
    $users = $user_stmt->fetchAll();
} catch (Exception $e) {
    $users = [];
}

// Get pending incentives
try {
    $incentive_stmt = $db->prepare("
        SELECT pi.*, u.full_name, u.username, u.job_title,
               creator.full_name as created_by_name
        FROM payroll_incentives pi
        JOIN users u ON pi.user_id = u.id
        JOIN users creator ON pi.created_by = creator.id
        WHERE pi.status = 'pending'
        ORDER BY pi.created_at DESC
    ");
    $incentive_stmt->execute();
    $pending_incentives = $incentive_stmt->fetchAll();
} catch (Exception $e) {
    $pending_incentives = [];
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
    // Payroll stats
    $stats_stmt = $db->prepare("
        SELECT 
            COUNT(*) as total,
            SUM(net_pay) as total_amount,
            SUM(CASE WHEN status = 'draft' THEN 1 ELSE 0 END) as draft_count,
            SUM(CASE WHEN status = 'calculated' THEN 1 ELSE 0 END) as calculated_count,
            SUM(CASE WHEN status = 'pending_ceo_approval' THEN 1 ELSE 0 END) as pending_ceo_count,
            SUM(CASE WHEN status = 'ceo_approved' THEN 1 ELSE 0 END) as ceo_approved_count,
            SUM(CASE WHEN status = 'ready_for_payment' THEN 1 ELSE 0 END) as ready_count,
            SUM(CASE WHEN status = 'paid' THEN 1 ELSE 0 END) as paid_count
        FROM payroll 
        WHERE DATE_FORMAT(pay_period_start, '%Y-%m') = ?
    ");
    $stats_stmt->execute([$filter_month]);
    $stats = $stats_stmt->fetch();
    
    // Incentive stats
    $inc_stats = $db->query("
        SELECT 
            COUNT(*) as total_pending,
            SUM(CASE WHEN incentive_type IN ('bonus', 'allowance', 'overtime', 'commission') THEN amount ELSE 0 END) as total_incentives_amount,
            SUM(CASE WHEN incentive_type = 'deduction' THEN amount ELSE 0 END) as total_deductions_amount
        FROM payroll_incentives 
        WHERE status = 'pending'
    ")->fetch();
    
} catch (Exception $e) {
    $stats = ['total' => 0, 'total_amount' => 0];
    $inc_stats = ['total_pending' => 0];
}

include '../includes/header.php';
?>

<div class="container-fluid pt-4 px-4">
    <div class="d-sm-flex align-items-center justify-content-between mb-4">
        <h1 class="h3 mb-0 text-gray-800">
            <i class="bi bi-cash-coin me-2"></i>Payroll & Salary Management
        </h1>
        <div class="d-flex gap-2">
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#generatePayrollModal">
                <i class="bi bi-calculator me-2"></i>Generate Payroll
            </button>
            <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#updateSalaryModal">
                <i class="bi bi-currency-dollar me-2"></i>Update Salary
            </button>
            <button class="btn btn-info" data-bs-toggle="modal" data-bs-target="#addIncentiveModal">
                <i class="bi bi-plus-circle me-2"></i>Add Incentive
            </button>
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

    <!-- Pending Incentives Section -->
    <?php if (!empty($pending_incentives)): ?>
    <div class="card shadow mb-4 border-warning">
        <div class="card-header bg-warning text-dark py-3 d-flex justify-content-between align-items-center">
            <h6 class="m-0 font-weight-bold">
                <i class="bi bi-clock-history me-2"></i>Pending Incentives Approval
                <span class="badge bg-danger ms-2"><?php echo count($pending_incentives); ?></span>
            </h6>
         <span class="text-dark">
    Total Amount: <strong><?php echo format_payroll_currency($inc_stats['total_incentives_amount'] ?? 0); ?></strong>
</span>
        </div>
       
    </div>
    <?php endif; ?>

    <!-- Filter Section -->
    <div class="card shadow mb-4">
        <div class="card-body">
            <form method="GET" class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">Filter by Month</label>
                    <input type="month" name="month" class="form-control" 
                           value="<?php echo htmlspecialchars($filter_month); ?>" 
                           max="<?php echo date('Y-m'); ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Filter by Status</label>
                    <select name="status" class="form-select">
                        <option value="">All Status</option>
                        <option value="draft" <?php echo $filter_status == 'draft' ? 'selected' : ''; ?>>Draft</option>
                        <option value="calculated" <?php echo $filter_status == 'calculated' ? 'selected' : ''; ?>>Calculated</option>
                        <option value="pending_ceo_approval" <?php echo $filter_status == 'pending_ceo_approval' ? 'selected' : ''; ?>>Pending CEO</option>
                        <option value="ceo_approved" <?php echo $filter_status == 'ceo_approved' ? 'selected' : ''; ?>>CEO Approved</option>
                        <option value="ready_for_payment" <?php echo $filter_status == 'ready_for_payment' ? 'selected' : ''; ?>>Ready for Payment</option>
                        <option value="paid" <?php echo $filter_status == 'paid' ? 'selected' : ''; ?>>Paid</option>
                    </select>
                </div>
                <div class="col-md-4 d-flex align-items-end">
                    <button type="submit" class="btn btn-outline-primary me-2">
                        <i class="bi bi-funnel me-1"></i>Filter
                    </button>
                    <a href="payroll.php" class="btn btn-outline-secondary">
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
                                Total Payroll</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">
                                <?php echo $stats['total'] ?? 0; ?>
                            </div>
                        </div>
                        <div class="col-auto">
                            <i class="bi bi-list-ol fa-2x text-primary"></i>
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
                            <div class="text-xs font-weight-bold text-warning text-uppercase mb-1">
                                Draft/Calculated</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">
                                <?php echo ($stats['draft_count'] ?? 0) + ($stats['calculated_count'] ?? 0); ?>
                            </div>
                        </div>
                        <div class="col-auto">
                            <i class="bi bi-file-earmark-text fa-2x text-warning"></i>
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
                            <div class="text-xs font-weight-bold text-danger text-uppercase mb-1">
                                Pending CEO</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">
                                <?php echo $stats['pending_ceo_count'] ?? 0; ?>
                            </div>
                        </div>
                        <div class="col-auto">
                            <i class="bi bi-hourglass-split fa-2x text-danger"></i>
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
                            <div class="text-xs font-weight-bold text-success text-uppercase mb-1">
                                Approved</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">
                                <?php echo ($stats['ceo_approved_count'] ?? 0) + ($stats['ready_count'] ?? 0); ?>
                            </div>
                        </div>
                        <div class="col-auto">
                            <i class="bi bi-check-circle fa-2x text-success"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Second Row Statistics -->
    <div class="row mb-4">
        <div class="col-xl-3 col-md-6 mb-4">
            <div class="card border-left-primary shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">
                                Paid</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">
                                <?php echo $stats['paid_count'] ?? 0; ?>
                            </div>
                        </div>
                        <div class="col-auto">
                            <i class="bi bi-cash-stack fa-2x text-primary"></i>
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
                                Total Amount</div>
                            <div class="h6 mb-0 font-weight-bold text-gray-800">
                                <?php echo format_currency($stats['total_amount'] ?? 0); ?>
                            </div>
                        </div>
                        <div class="col-auto">
                            <i class="bi bi-currency-dollar fa-2x text-info"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6 mb-4">
            <div class="card border-left-secondary shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-secondary text-uppercase mb-1">
                                Pending Incentives</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">
                                <?php echo $inc_stats['total_pending'] ?? 0; ?>
                            </div>
                        </div>
                        <div class="col-auto">
                            <i class="bi bi-clock fa-2x text-secondary"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6 mb-4">
            <div class="card border-left-dark shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-dark text-uppercase mb-1">
                                Active Employees</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">
                                <?php echo count($users); ?>
                            </div>
                        </div>
                        <div class="col-auto">
                            <i class="bi bi-people fa-2x text-dark"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Payroll Records Table -->
    <div class="card shadow mb-4">
        <div class="card-header py-3 d-flex justify-content-between align-items-center">
            <h6 class="m-0 font-weight-bold text-primary">
                <i class="bi bi-cash-stack me-2"></i>
                Payroll Records for <?php echo date('F Y', strtotime($filter_month . '-01')); ?>
            </h6>
            <div class="d-flex gap-2">
                <input type="text" class="form-control form-control-sm" id="payrollSearch" 
                       placeholder="Search payroll..." style="width: 200px;">
                <form method="POST" id="bulkForm" class="d-flex gap-2">
                    <select name="bulk_action" class="form-select form-select-sm" style="width: 150px;">
                        <option value="">Bulk Actions</option>
                        <option value="submit">Submit for Approval</option>
                        <option value="delete">Delete Drafts</option>
                    </select>
                    <button type="submit" class="btn btn-sm btn-outline-primary">
                        <i class="bi bi-play"></i>
                    </button>
                </form>
            </div>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-bordered table-hover" id="payrollTable">
                    <thead class="table-light">
                        <tr>
                            <th width="30">
                                <input type="checkbox" id="selectAllPayrolls">
                            </th>
                            <th>Employee</th>
                            <th>Job Title</th>
                            <th>Pay Period</th>
                            <th>Basic Salary</th>
                            <th>Allowances</th>
                            <th>Deductions</th>
                            <th>Net Pay</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($payroll_records)): ?>
                            <tr>
                                <td colspan="10" class="text-center text-muted py-5">
                                    <i class="bi bi-cash-stack display-4 d-block mb-3"></i>
                                    <h5>No payroll records found</h5>
                                    <p class="mb-0">Generate payroll for the selected period to get started.</p>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($payroll_records as $record): ?>
                                <?php
                                $total_deductions = $record['total_deductions'] + $record['tax_amount'] + $record['social_security_amount'];
                                $status_badge = get_payroll_status_badge($record['status']);
                                $status_display = get_payroll_status_display($record['status']);
                                ?>
                                <tr>
                                    <td>
                                        <input type="checkbox" name="selected_payrolls[]" 
                                               value="<?php echo $record['id']; ?>" 
                                               class="payroll-checkbox"
                                               <?php echo in_array($record['status'], ['paid', 'ceo_approved']) ? 'disabled' : ''; ?>>
                                    </td>
                                    <td>
                                        <div>
                                            <strong><?php echo htmlspecialchars($record['full_name']); ?></strong>
                                            <br><small class="text-muted"><?php echo htmlspecialchars($record['username']); ?></small>
                                        </div>
                                    </td>
                                    <td><?php echo htmlspecialchars($record['job_title'] ?? ''); ?></td>
                                    <td>
                                        <small>
                                            <?php echo date('d M', strtotime($record['pay_period_start'])); ?><br>
                                            <strong>to</strong><br>
                                            <?php echo date('d M', strtotime($record['pay_period_end'])); ?>
                                        </small>
                                    </td>
                                    <td class="text-end">
                                        <strong><?php echo format_currency($record['basic_salary'], $record['currency']); ?></strong>
                                    </td>
                                    <td class="text-end">
                                        <span class="text-success">
                                            <?php echo format_currency($record['total_allowances'], $record['currency']); ?>
                                        </span>
                                    </td>
                                    <td class="text-end">
                                        <span class="text-danger">
                                            <?php echo format_currency($total_deductions, $record['currency']); ?>
                                        </span>
                                    </td>
                                    <td class="text-end">
                                        <strong class="text-success">
                                            <?php echo format_currency($record['net_pay'], $record['currency']); ?>
                                        </strong>
                                    </td>
                                    <td>
                                        <span class="badge <?php echo $status_badge; ?>">
                                            <?php echo $status_display; ?>
                                        </span>
                                        <?php if ($record['ceo_approved_by_name']): ?>
                                            <br><small class="text-success">
                                                <i class="bi bi-person-check"></i> 
                                                <?php echo $record['ceo_approved_by_name']; ?>
                                            </small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="btn-group btn-group-sm">
                                            <?php if (in_array($record['status'], ['draft', 'calculated'])): ?>
                                                <button class="btn btn-outline-success" 
                                                        onclick="submitSinglePayroll(<?php echo $record['id']; ?>, '<?php echo htmlspecialchars($record['full_name']); ?>')"
                                                        title="Submit for Approval">
                                                    <i class="bi bi-arrow-up-circle"></i>
                                                </button>
                                            <?php endif; ?>
                                            
                                            <?php if ($record['status'] == 'ceo_approved' && $_SESSION['role'] == 'finance_officer'): ?>
                                                <button class="btn btn-outline-primary" 
                                                        onclick="markAsPaid(<?php echo $record['id']; ?>, '<?php echo htmlspecialchars($record['full_name']); ?>')"
                                                        title="Mark as Paid">
                                                    <i class="bi bi-cash-stack"></i>
                                                </button>
                                            <?php endif; ?>
                                            
                                            <button class="btn btn-outline-info" 
                                                    onclick="viewPayrollDetails(<?php echo $record['id']; ?>)"
                                                    title="View Details">
                                                <i class="bi bi-eye"></i>
                                            </button>
                                            
                                            <?php if ($record['status'] == 'draft'): ?>
                                                <button class="btn btn-outline-danger" 
                                                        onclick="deletePayroll(<?php echo $record['id']; ?>)"
                                                        title="Delete">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Employee Salary List -->
    <div class="card shadow">
        <div class="card-header py-3 d-flex justify-content-between align-items-center">
            <h6 class="m-0 font-weight-bold text-primary">
                <i class="bi bi-people me-2"></i>Employee Salary Overview
            </h6>
            <div>
                <input type="text" class="form-control form-control-sm" id="salarySearch" 
                       placeholder="Search employees..." style="width: 200px;">
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
                <strong><?php echo htmlspecialchars($user['full_name']); ?></strong>
            </td>
            <td><?php echo htmlspecialchars($user['username']); ?></td>
            <td><?php echo htmlspecialchars($user['job_title'] ?? ''); ?></td>
            <td>
                <?php if ($user['salary_level']): ?>
                    <span class="badge bg-info"><?php echo htmlspecialchars($user['salary_level'] ?? ''); ?></span>
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
                <?php echo $user['last_salary_review'] ? date('d M Y', strtotime($user['last_salary_review'])) : 'Never'; ?>
            </td>
            <td>
                <?php echo $user['hire_date'] ? date('d M Y', strtotime($user['hire_date'])) : 'Not set'; ?>
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
                                'id' => $user['id'],
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
</div>

<!-- Modals -->

<!-- Generate Payroll Modal -->
<div class="modal fade" id="generatePayrollModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="bi bi-calculator me-2"></i>Generate Payroll
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Pay Period Start <span class="text-danger">*</span></label>
                            <input type="date" name="pay_period_start" class="form-control" required
                                   value="<?php echo date('Y-m-01'); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Pay Period End <span class="text-danger">*</span></label>
                            <input type="date" name="pay_period_end" class="form-control" required
                                   value="<?php echo date('Y-m-t'); ?>">
                        </div>
                    </div>
                    
                    <label class="form-label">Select Employees <span class="text-danger">*</span></label>
                    <div class="card border p-3" style="max-height: 400px; overflow-y: auto;">
                        <div class="form-check mb-2">
                            <input type="checkbox" id="selectAllEmployees" class="form-check-input">
                            <label for="selectAllEmployees" class="form-check-label fw-bold">Select All Employees</label>
                        </div>
                        <hr class="my-2">
                        <?php foreach ($users as $user): ?>
                            <div class="form-check">
                                <input class="form-check-input employee-checkbox" type="checkbox" 
                                       name="user_ids[]" value="<?php echo $user['id']; ?>" 
                                       id="emp_<?php echo $user['id']; ?>">
                                <label class="form-check-label" for="emp_<?php echo $user['id']; ?>">
                                    <strong><?php echo htmlspecialchars($user['full_name']); ?></strong>
                                    <small class="text-muted d-block">
                                        <?php echo htmlspecialchars($user['job_title'] ?? ''); ?> -
                                        Salary: <?php echo format_currency($user['salary'], $user['currency']); ?>
                                    </small>
                                </label>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    
                    <div class="alert alert-info mt-3">
                        <i class="bi bi-info-circle me-2"></i>
                        This will generate payroll for selected employees including their approved incentives and deductions.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="generate_payroll" class="btn btn-primary">
                        <i class="bi bi-calculator me-2"></i>Generate Payroll
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Update Salary Modal -->
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
                                    <option value="<?php echo $user['id']; ?>"
                                            data-salary="<?php echo $user['salary']; ?>"
                                            data-jobtitle="<?php echo htmlspecialchars($user['job_title'] ?? ''); ?>"
                                            data-level="<?php echo htmlspecialchars($user['salary_level'] ?? ''); ?>">
                                        <?php echo htmlspecialchars($user['full_name'] . ' (' . $user['username'] . ')'); ?>
                                    </option>
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
                                // Define salary levels inline since we don't have payroll_constants.php
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
                                    <option value="<?php echo $value; ?>"><?php echo $label; ?></option>
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

<!-- Add Incentive Modal -->
<div class="modal fade" id="addIncentiveModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="bi bi-plus-circle me-2"></i>Add Incentive
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Select Employee <span class="text-danger">*</span></label>
                        <select name="user_id" class="form-select" id="incentiveUserSelect" required>
                            <option value="">Select Employee</option>
                            <?php foreach ($users as $user): ?>
                                <option value="<?php echo $user['id']; ?>">
                                    <?php echo htmlspecialchars($user['full_name'] . ' (' . $user['username'] . ')'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Incentive Type <span class="text-danger">*</span></label>
                            <select name="incentive_type" class="form-select" required>
                                <option value="">Select Type</option>
                                <option value="bonus">Bonus</option>
                                <option value="overtime">Overtime</option>
                                <option value="allowance">Allowance</option>
                                <option value="commission">Commission</option>
                                <option value="deduction">Deduction</option>
                                <option value="other">Other</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Amount <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text">TZS</span>
                                <input type="number" name="amount" class="form-control" 
                                       step="0.01" min="0.01" required>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Description <span class="text-danger">*</span></label>
                        <input type="text" name="description" class="form-control" required 
                               placeholder="e.g., Year-end bonus, Overtime for weekend work, etc.">
                    </div>
                    
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Pay Period Month <span class="text-danger">*</span></label>
                            <input type="month" name="pay_period_month" class="form-control" 
                                   value="<?php echo date('Y-m'); ?>" required>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Notes</label>
                        <textarea name="notes" class="form-control" rows="2" 
                                  placeholder="Additional notes or details"></textarea>
                    </div>
                    
                    <div class="alert alert-warning">
                        <i class="bi bi-exclamation-triangle me-2"></i>
                        Incentives require HR approval before being included in payroll.
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

<!-- Submit for Approval Modal -->
<div class="modal fade" id="submitApprovalModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="bi bi-arrow-up-circle me-2"></i>Submit for CEO Approval
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="submit_for_approval" value="1">
                    <input type="hidden" id="submitPayrollId" name="payroll_ids" value="">
                    
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle me-2"></i>
                        You are about to submit payroll for <strong id="submitEmployeeName"></strong> for CEO approval.
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Submission Reason <span class="text-danger">*</span></label>
                        <textarea name="submission_reason" class="form-control" rows="3" 
                                  placeholder="Please provide a reason for submitting this payroll for CEO approval..." 
                                  required></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success">
                        <i class="bi bi-arrow-up-circle me-2"></i>Submit for Approval
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Mark as Paid Modal -->
<div class="modal fade" id="markPaidModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="bi bi-cash-stack me-2"></i>Mark as Paid
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="mark_paid" value="1">
                    <input type="hidden" name="payroll_id" id="paidPayrollId">
                    
                    <div class="alert alert-warning">
                        <i class="bi bi-exclamation-triangle me-2"></i>
                        You are about to mark payroll for <strong id="paidEmployeeName"></strong> as paid.
                    </div>
                    
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Payment Date <span class="text-danger">*</span></label>
                            <input type="date" name="payment_date" class="form-control" 
                                   value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Payment Method <span class="text-danger">*</span></label>
                            <select name="payment_method" class="form-select" required>
                                <option value="">Select Method</option>
                                <option value="bank_transfer">Bank Transfer</option>
                                <option value="cash">Cash</option>
                                <option value="cheque">Cheque</option>
                                <option value="mobile_money">Mobile Money</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Payment Reference <span class="text-danger">*</span></label>
                        <input type="text" name="payment_reference" class="form-control" 
                               placeholder="Transaction ID, cheque number, etc." required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-cash-stack me-2"></i>Mark as Paid
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- View Payroll Details Modal -->
<div class="modal fade" id="viewPayrollModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="bi bi-eye me-2"></i>Payroll Details
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="payrollDetailsContent">
                <!-- Content loaded via AJAX -->
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

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
                                    $change_percent = calculate_salary_change_percent($history['old_salary'], $history['new_salary']);
                                    $change_class = $change_percent >= 0 ? 'text-success' : 'text-danger';
                                    $change_sign = $change_percent >= 0 ? '+' : '';
                                    ?>
                                    <tr>
                                        <td><?php echo date('d M Y', strtotime($history['effective_date'])); ?></td>
                                        <td class="text-end"><?php echo format_currency($history['old_salary']); ?></td>
                                        <td class="text-end"><strong><?php echo format_currency($history['new_salary']); ?></strong></td>
                                        <td class="text-end">
                                            <span class="badge <?php echo $change_percent >= 0 ? 'bg-success' : 'bg-danger'; ?>">
                                                <?php echo $change_sign . $change_percent; ?>%
                                            </span>
                                        </td>
                                        <td><?php echo htmlspecialchars($history['old_job_title'] ?? ''); ?></td>
                                        <td><?php echo htmlspecialchars($history['job_title'] ?? ''); ?></td>
                                        <td>
                                            <?php if ($history['salary_level']): ?>
                                                <span class="badge bg-info"><?php echo htmlspecialchars($history['salary_level'] ?? ''); ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo htmlspecialchars($history['change_reason']); ?></td>
                                        <td><?php echo htmlspecialchars($history['approved_by_name']); ?></td>
                                        <td><?php echo date('d M Y', strtotime($history['created_at'])); ?></td>
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

<!-- Delete Confirmation Modal -->
<div class="modal fade" id="deleteConfirmModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title text-danger">
                    <i class="bi bi-exclamation-triangle me-2"></i>Confirm Delete
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to delete this payroll record? This action cannot be undone.</p>
                <div class="alert alert-danger">
                    <i class="bi bi-exclamation-triangle me-2"></i>
                    Only draft payrolls can be deleted.
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-danger" id="confirmDeleteBtn">
                    <i class="bi bi-trash me-2"></i>Delete
                </button>
            </div>
        </div>
    </div>
</div>

<!-- JavaScript -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Select all employees checkbox
    const selectAllEmployees = document.getElementById('selectAllEmployees');
    if (selectAllEmployees) {
        selectAllEmployees.addEventListener('change', function() {
            const checkboxes = document.querySelectorAll('.employee-checkbox');
            checkboxes.forEach(cb => cb.checked = this.checked);
        });
    }
    
    // Select all payrolls checkbox
    const selectAllPayrolls = document.getElementById('selectAllPayrolls');
    if (selectAllPayrolls) {
        selectAllPayrolls.addEventListener('change', function() {
            const checkboxes = document.querySelectorAll('.payroll-checkbox:not(:disabled)');
            checkboxes.forEach(cb => cb.checked = this.checked);
        });
    }
    
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
                const currentSalary = selectedOption.getAttribute('data-salary');
                const jobTitle = selectedOption.getAttribute('data-jobtitle');
                const salaryLevel = selectedOption.getAttribute('data-level');
                
                currentSalaryDisplay.value = formatCurrency(currentSalary);
                newSalaryInput.value = currentSalary;
                jobTitleInput.value = jobTitle || '';
                salaryLevelSelect.value = salaryLevel || '';
                
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
    const payrollSearch = document.getElementById('payrollSearch');
    if (payrollSearch) {
        payrollSearch.addEventListener('input', function() {
            const term = this.value.toLowerCase();
            const rows = document.querySelectorAll('#payrollTable tbody tr');
            
            rows.forEach(row => {
                const text = row.textContent.toLowerCase();
                row.style.display = text.includes(term) ? '' : 'none';
            });
        });
    }
    
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
    
    // Bulk form submission
    const bulkForm = document.getElementById('bulkForm');
    if (bulkForm) {
        bulkForm.addEventListener('submit', function(e) {
            const selected = document.querySelectorAll('.payroll-checkbox:checked');
            const action = this.querySelector('select[name="bulk_action"]').value;
            
            if (selected.length === 0) {
                e.preventDefault();
                alert('Please select at least one payroll.');
                return;
            }
            
            if (!action) {
                e.preventDefault();
                alert('Please select a bulk action.');
                return;
            }
            
            if (action === 'delete') {
                if (!confirm('Are you sure you want to delete selected draft payrolls?')) {
                    e.preventDefault();
                }
            }
        });
    }
    
    <?php if (!empty($salary_history) && $view_salary_history): ?>
    // Show salary history modal if loaded
    const historyModal = new bootstrap.Modal(document.getElementById('viewSalaryHistoryModal'));
    historyModal.show();
    <?php endif; ?>
});

// Helper functions
function formatCurrency(amount) {
    return 'TZS ' + parseFloat(amount).toLocaleString('en-TZ', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    });
}

function formatDate(dateStr) {
    const date = new Date(dateStr);
    return date.toLocaleDateString('en-GB', {
        day: '2-digit',
        month: 'short',
        year: 'numeric'
    });
}

// Action functions
function submitSinglePayroll(payrollId, employeeName) {
    document.getElementById('submitPayrollId').value = payrollId;
    document.getElementById('submitEmployeeName').textContent = employeeName;
    document.querySelector('#submitApprovalModal textarea').value = '';
    
    const modal = new bootstrap.Modal(document.getElementById('submitApprovalModal'));
    modal.show();
}

function markAsPaid(payrollId, employeeName) {
    document.getElementById('paidPayrollId').value = payrollId;
    document.getElementById('paidEmployeeName').textContent = employeeName;
    
    const modal = new bootstrap.Modal(document.getElementById('markPaidModal'));
    modal.show();
}

function viewPayrollDetails(payrollId) {
    fetch(`ajax/payroll_details.php?id=${payrollId}`)
        .then(response => response.text())
        .then(html => {
            document.getElementById('payrollDetailsContent').innerHTML = html;
            const modal = new bootstrap.Modal(document.getElementById('viewPayrollModal'));
            modal.show();
        })
        .catch(error => {
            document.getElementById('payrollDetailsContent').innerHTML = `
                <div class="alert alert-danger">
                    <i class="bi bi-exclamation-triangle me-2"></i>
                    Error loading payroll details: ${error.message}
                </div>
            `;
            const modal = new bootstrap.Modal(document.getElementById('viewPayrollModal'));
            modal.show();
        });
}

function viewSalaryHistory(userId, employeeName) {
    window.location.href = `payroll.php?view_history=${userId}`;
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
    const select = document.getElementById('incentiveUserSelect');
    select.value = userId;
    
    const modal = new bootstrap.Modal(document.getElementById('addIncentiveModal'));
    modal.show();
}

function deletePayroll(payrollId) {
    if (confirm('Are you sure you want to delete this payroll? This action cannot be undone.')) {
        window.location.href = `payroll.php?delete_payroll=${payrollId}`;
    }
}

// Keyboard shortcuts
document.addEventListener('keydown', function(e) {
    // Ctrl+G to generate payroll
    if (e.ctrlKey && e.key === 'g') {
        e.preventDefault();
        const modal = new bootstrap.Modal(document.getElementById('generatePayrollModal'));
        modal.show();
    }
    
    // Ctrl+S to update salary
    if (e.ctrlKey && e.key === 's') {
        e.preventDefault();
        const modal = new bootstrap.Modal(document.getElementById('updateSalaryModal'));
        modal.show();
    }
    
    // Ctrl+I to add incentive
    if (e.ctrlKey && e.key === 'i') {
        e.preventDefault();
        const modal = new bootstrap.Modal(document.getElementById('addIncentiveModal'));
        modal.show();
    }
});
</script>

<?php
include '../includes/footer.php';
?>