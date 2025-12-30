<?php
require_once '../config/config.php';
require_once '../auth/auth_middleware.php';
require_once '../includes/approval_helpers.php';
require_once '../config/approval_constants.php';

require_hr();
$db = getDBConnection();

$page_title = 'Payroll Management';
$success_message = '';
$error_message = '';

// Handle payroll actions
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        if (isset($_POST['generate_payroll'])) {
            // Generate payroll for selected period
            $pay_period_start = $_POST['pay_period_start'];
            $pay_period_end = $_POST['pay_period_end'];
            $selected_employees = $_POST['employee_ids'] ?? [];
            
            if (empty($selected_employees)) {
                $error_message = 'Please select at least one employee.';
            } else {
                $generated_count = 0;
                $db->beginTransaction();
                
                foreach ($selected_employees as $employee_id) {
                    // Check if payroll already exists for this period
                    $check_stmt = $db->prepare("
                        SELECT id FROM payroll 
                        WHERE employee_id = ? AND pay_period_start = ? AND pay_period_end = ?
                    ");
                    $check_stmt->execute([$employee_id, $pay_period_start, $pay_period_end]);
                    
                    if (!$check_stmt->fetch()) {
                        // Get employee basic salary and benefits
                        $emp_stmt = $db->prepare("
                            SELECT e.basic_salary, e.currency,
                                   COALESCE(SUM(CASE WHEN eb.benefit_type IN ('allowance', 'bonus') 
                                                THEN eb.amount ELSE 0 END), 0) as total_allowances,
                                   COALESCE(SUM(CASE WHEN eb.benefit_type NOT IN ('allowance', 'bonus') 
                                                THEN eb.amount ELSE 0 END), 0) as total_deductions
                            FROM employees e
                            LEFT JOIN employee_benefits eb ON e.id = eb.employee_id 
                                AND eb.is_active = 1 
                                AND (eb.end_date IS NULL OR eb.end_date >= ?)
                            WHERE e.id = ?
                            GROUP BY e.id
                        ");
                        $emp_stmt->execute([$pay_period_start, $employee_id]);
                        $employee = $emp_stmt->fetch();
                        
                        if ($employee) {
                            $basic_salary = $employee['basic_salary'];
                            $total_allowances = $employee['total_allowances'];
                            $total_deductions = $employee['total_deductions'];
                            
                            $gross_pay = $basic_salary + $total_allowances;
                            
                            // No tax deductions - full amount paid
                            $tax_amount = 0;
                            $social_security = 0;
                            
                            $net_pay = $gross_pay - $total_deductions;
                            
                            // Insert payroll record with draft status
                            $payroll_stmt = $db->prepare("
                                INSERT INTO payroll (employee_id, pay_period_start, pay_period_end, 
                                                   basic_salary, total_allowances, total_deductions,
                                                   gross_pay, tax_amount, social_security_amount, 
                                                   net_pay, currency, processed_by, status)
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                            ");
                            
                            if ($payroll_stmt->execute([
                                $employee_id, $pay_period_start, $pay_period_end,
                                $basic_salary, $total_allowances, $total_deductions,
                                $gross_pay, $tax_amount, $social_security, 
                                $net_pay, $employee['currency'], $_SESSION['user_id'], PAYROLL_DRAFT
                            ])) {
                                $generated_count++;
                                $payroll_id = $db->lastInsertId();
                                
                                // Create initial workflow record
                                create_approval_workflow(
                                    WORKFLOW_PAYROLL, ENTITY_PAYROLL, $payroll_id, 
                                    $_SESSION['user_id'], PAYROLL_DRAFT
                                );
                                
                                // Log activity
                                $activity_stmt = $db->prepare("
                                    INSERT INTO hr_activities (activity_type, entity_type, entity_id, description, performed_by)
                                    VALUES ('payroll_processed', 'payroll', ?, ?, ?)
                                ");
                                $activity_stmt->execute([
                                    $payroll_id,
                                    "Payroll generated for period {$pay_period_start} to {$pay_period_end}",
                                    $_SESSION['user_id']
                                ]);
                                
                                // Add payroll items for detailed breakdown
                                
                                // Add basic salary item
                                $item_stmt = $db->prepare("
                                    INSERT INTO payroll_items (payroll_id, item_type, item_name, amount)
                                    VALUES (?, ?, ?, ?)
                                ");
                                $item_stmt->execute([$payroll_id, 'allowance', 'Basic Salary', $basic_salary]);
                                
                                // No tax or social security deductions
                            }
                        }
                    }
                }
                
                $db->commit();
                show_alert("{$generated_count} payroll records generated successfully.", 'success');
                redirect('hr/payroll.php');
            }
            
        } elseif (isset($_POST['submit_for_approval'])) {
            // HR submits payroll for CEO approval
            $selected_payroll_ids = $_POST['payroll_ids'] ?? [];
            $submission_reason = sanitize_input($_POST['submission_reason']);
            
            if (empty($selected_payroll_ids)) {
                $error_message = 'Please select at least one payroll to submit.';
            } else {
                $result = hr_submit_payroll_for_approval($selected_payroll_ids, $_SESSION['user_id'], $submission_reason);
                
                if ($result['success']) {
                    show_alert($result['message'], 'success');
                } else {
                    $error_message = $result['message'];
                }
            }
            
            if (!$error_message) {
                redirect('hr/payroll.php');
            }
            
        } elseif (isset($_POST['mark_paid'])) {
            // Finance marks payroll as paid (only for CEO-approved payroll)
            $payroll_id = (int)$_POST['payroll_id'];
            $payment_date = $_POST['payment_date'];
            $payment_method = sanitize_input($_POST['payment_method']);
            $payment_reference = sanitize_input($_POST['payment_reference']);
            
            // Validate finance officer permission
            if (!check_session_permission('finance_officer')) {
                $error_message = 'Access denied: Finance officer permission required.';
            } else {
                $stmt = $db->prepare("
                    UPDATE payroll 
                    SET status = 'paid', 
                        payment_date = ?, 
                        payment_method = ?, 
                        payment_reference = ?,
                        finance_processed_by = ?
                    WHERE id = ? AND status = 'ceo_approved'
                ");
                
                if ($stmt->execute([$payment_date, $payment_method, $payment_reference, $_SESSION['user_id'], $payroll_id])) {
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
                        VALUES ('payroll_processed', 'payroll', ?, ?, ?)
                    ");
                    $activity_stmt->execute([
                        $payroll_id,
                        "Payroll payment processed: {$payment_method} - {$payment_reference}",
                        $_SESSION['user_id']
                    ]);
                    
                    show_alert('Payroll marked as paid successfully.', 'success');
                } else {
                    $error_message = 'Error updating payroll status.';
                }
            }
            
            if (!$error_message) {
                redirect('hr/payroll.php');
            }
        }
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        $error_message = 'Database error: ' . $e->getMessage();
    }
}

// Get payroll records
try {
    $filter_month = $_GET['month'] ?? date('Y-m');
    $filter_status = $_GET['status'] ?? '';
    
    $where_conditions = ["DATE_FORMAT(p.pay_period_start, '%Y-%m') = ?"];
    $params = [$filter_month];
    
    if (!empty($filter_status)) {
        $where_conditions[] = "p.status = ?";
        $params[] = $filter_status;
    }
    
    $where_clause = implode(' AND ', $where_conditions);
    
    $stmt = $db->prepare("
        SELECT p.*, 
               CONCAT(e.first_name, ' ', e.last_name) as employee_name,
               e.employee_id,
               d.name as department_name,
               processed_by.full_name as processed_by_name,
               ceo_user.full_name as ceo_approved_by_name,
               finance_user.full_name as finance_processed_by_name
        FROM payroll p
        JOIN employees e ON p.employee_id = e.id
        JOIN departments d ON e.department_id = d.id
        LEFT JOIN users processed_by ON p.processed_by = processed_by.id
        LEFT JOIN users ceo_user ON p.ceo_approved_by = ceo_user.id
        LEFT JOIN users finance_user ON p.finance_processed_by = finance_user.id
        WHERE {$where_clause}
        ORDER BY p.created_at DESC
    ");
    $stmt->execute($params);
    $payroll_records = $stmt->fetchAll();
} catch (Exception $e) {
    $payroll_records = [];
    $error_message = 'Error loading payroll records: ' . $e->getMessage();
}

// Get employees for payroll generation
try {
    $emp_stmt = $db->query("
        SELECT e.id, e.employee_id, CONCAT(e.first_name, ' ', e.last_name) as full_name, 
               e.basic_salary, d.name as department_name
        FROM employees e
        JOIN departments d ON e.department_id = d.id
        WHERE e.status = 'active'
        ORDER BY e.first_name, e.last_name
    ");
    $employees = $emp_stmt->fetchAll();
} catch (Exception $e) {
    $employees = [];
}

// Get payroll statistics
try {
    $total_payroll = $db->prepare("
        SELECT COUNT(*) as total, SUM(net_pay) as total_amount
        FROM payroll 
        WHERE DATE_FORMAT(pay_period_start, '%Y-%m') = ?
    ");
    $total_payroll->execute([$filter_month]);
    $payroll_stats = $total_payroll->fetch();
    
    $draft_count = $db->prepare("
        SELECT COUNT(*) FROM payroll 
        WHERE status IN ('draft', 'calculated') AND DATE_FORMAT(pay_period_start, '%Y-%m') = ?
    ");
    $draft_count->execute([$filter_month]);
    $drafts = $draft_count->fetchColumn();
    
    $pending_ceo_count = $db->prepare("
        SELECT COUNT(*) FROM payroll 
        WHERE status = 'pending_ceo_approval' AND DATE_FORMAT(pay_period_start, '%Y-%m') = ?
    ");
    $pending_ceo_count->execute([$filter_month]);
    $pending_ceo = $pending_ceo_count->fetchColumn();
    
    $approved_count = $db->prepare("
        SELECT COUNT(*) FROM payroll 
        WHERE status IN ('ceo_approved', 'ready_for_payment') AND DATE_FORMAT(pay_period_start, '%Y-%m') = ?
    ");
    $approved_count->execute([$filter_month]);
    $approved = $approved_count->fetchColumn();
    
    $paid_count = $db->prepare("
        SELECT COUNT(*) FROM payroll 
        WHERE status = 'paid' AND DATE_FORMAT(pay_period_start, '%Y-%m') = ?
    ");
    $paid_count->execute([$filter_month]);
    $paid = $paid_count->fetchColumn();
} catch (Exception $e) {
    $payroll_stats = ['total' => 0, 'total_amount' => 0];
    $pending = $approved = $paid = 0;
}

include '../includes/header.php';
?>

<div class="container-fluid pt-4 px-4">
    <div class="d-sm-flex align-items-center justify-content-between mb-4">
        <h1 class="h3 mb-0 text-gray-800">
            <i class="bi bi-cash-coin me-2"></i>Payroll Management
        </h1>
        <div class="d-flex gap-2">
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#generatePayrollModal">
                <i class="bi bi-calculator me-2"></i>Generate Payroll
            </button>
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

    <!-- Filter Section -->
    <div class="card shadow mb-4">
        <div class="card-body">
            <form method="GET" class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">Filter by Month</label>
                    <input type="month" name="month" class="form-control" 
                           value="<?php echo htmlspecialchars($filter_month); ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Filter by Status</label>
                    <select name="status" class="form-select">
                        <option value="">All Status</option>
                        <option value="draft" <?php echo $filter_status == 'draft' ? 'selected' : ''; ?>>Draft</option>
                        <option value="calculated" <?php echo $filter_status == 'calculated' ? 'selected' : ''; ?>>Calculated</option>
                        <option value="pending_ceo_approval" <?php echo $filter_status == 'pending_ceo_approval' ? 'selected' : ''; ?>>Pending CEO Approval</option>
                        <option value="ceo_approved" <?php echo $filter_status == 'ceo_approved' ? 'selected' : ''; ?>>CEO Approved</option>
                        <option value="ready_for_payment" <?php echo $filter_status == 'ready_for_payment' ? 'selected' : ''; ?>>Ready for Payment</option>
                        <option value="paid" <?php echo $filter_status == 'paid' ? 'selected' : ''; ?>>Paid</option>
                    </select>
                </div>
                <div class="col-md-4 d-flex align-items-end">
                    <button type="submit" class="btn btn-outline-primary me-2">
                        <i class="bi bi-funnel"></i> Filter
                    </button>
                    <a href="payroll.php" class="btn btn-outline-secondary">
                        <i class="bi bi-x-lg"></i> Clear
                    </a>
                </div>
            </form>
        </div>
    </div>

    <!-- Statistics Cards -->
    <div class="row mb-4">
        <div class="col-md-3">
            <div class="card border-left-primary shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">Total Payroll</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo $payroll_stats['total'] ?? 0; ?></div>
                        </div>
                        <div class="col-auto">
                            <i class="bi bi-list-ol fs-2 text-primary"></i>
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
                            <div class="text-xs font-weight-bold text-warning text-uppercase mb-1">Draft</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo $drafts; ?></div>
                        </div>
                        <div class="col-auto">
                            <i class="bi bi-file-earmark-text fs-2 text-warning"></i>
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
                            <div class="text-xs font-weight-bold text-danger text-uppercase mb-1">Pending CEO</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo $pending_ceo; ?></div>
                        </div>
                        <div class="col-auto">
                            <i class="bi bi-hourglass-split fs-2 text-danger"></i>
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
                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo $approved; ?></div>
                        </div>
                        <div class="col-auto">
                            <i class="bi bi-check-circle fs-2 text-success"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Second row of statistics -->
    <div class="row mb-4">
        <div class="col-md-3">
            <div class="card border-left-primary shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">Paid</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo $paid; ?></div>
                        </div>
                        <div class="col-auto">
                            <i class="bi bi-cash-stack fs-2 text-primary"></i>
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
                            <div class="text-xs font-weight-bold text-info text-uppercase mb-1">Total Amount</div>
                            <div class="h6 mb-0 font-weight-bold text-gray-800">
                                TZS <?php echo number_format($payroll_stats['total_amount'] ?? 0, 2); ?>
                            </div>
                        </div>
                        <div class="col-auto">
                            <i class="bi bi-currency-dollar fs-2 text-info"></i>
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
                Payroll Records for <?php echo date('F Y', strtotime($filter_month . '-01')); ?>
            </h6>
            <input type="text" class="form-control form-control-sm" id="payrollSearch" 
                   placeholder="Search records..." style="width: 200px;">
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-bordered table-hover" id="payrollTable">
                    <thead class="table-light">
                        <tr>
                            <th>Employee</th>
                            <th>Department</th>
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
                                <td colspan="10" class="text-center text-muted py-4">
                                    <i class="bi bi-cash-stack display-4"></i>
                                    <p class="mt-2 mb-0">No payroll records found for this period. Generate payroll to get started.</p>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($payroll_records as $record): ?>
                                <tr>
                                    <td>
                                        <div>
                                            <strong><?php echo htmlspecialchars($record['employee_name']); ?></strong>
                                            <br><small class="text-muted"><?php echo htmlspecialchars($record['employee_id']); ?></small>
                                        </div>
                                    </td>
                                    <td><?php echo htmlspecialchars($record['department_name']); ?></td>
                                    <td>
                                        <small>
                                            <?php echo format_date($record['pay_period_start']); ?><br>
                                            <strong>to</strong><br>
                                            <?php echo format_date($record['pay_period_end']); ?>
                                        </small>
                                    </td>
                                    <td>
                                        <strong><?php echo number_format($record['basic_salary'], 2); ?></strong>
                                        <small class="text-muted"><?php echo $record['currency']; ?></small>
                                    </td>
                                    <td>
                                        <?php echo number_format($record['total_allowances'], 2); ?>
                                        <small class="text-muted"><?php echo $record['currency']; ?></small>
                                    </td>
                                    <td>
                                        <?php echo number_format($record['total_deductions'], 2); ?>
                                        <small class="text-muted"><?php echo $record['currency']; ?></small>
                                    </td>
                                    <td>
                                        <strong class="text-success">
                                            <?php echo number_format($record['net_pay'], 2); ?>
                                        </strong>
                                        <small class="text-muted"><?php echo $record['currency']; ?></small>
                                    </td>
                                    <td>
                                        <?php
                                        // Use status constants for display
                                        require_once '../config/approval_constants.php';
                                        $display_name = get_status_display_name($record['status']);
                                        $badge_class = get_status_badge_class($record['status']);
                                        ?>
                                        <span class="badge <?php echo $badge_class; ?>">
                                            <?php echo $display_name; ?>
                                        </span>
                                        
                                        <?php if (!empty($record['ceo_approved_by']) && !empty($record['ceo_approved_at'])): ?>
                                            <br><small class="text-success">
                                                <i class="bi bi-person-check"></i> CEO: <?php echo format_date($record['ceo_approved_at']); ?>
                                            </small>
                                        <?php endif; ?>
                                        
                                        <?php if (!empty($record['finance_processed_by']) && !empty($record['finance_processed_at'])): ?>
                                            <br><small class="text-primary">
                                                <i class="bi bi-bank"></i> Finance: <?php echo format_date($record['finance_processed_at']); ?>
                                            </small>
                                        <?php endif; ?>
                                        <?php if (!empty($record['payment_date'])): ?>
                                            <br><small class="text-muted">
                                                Paid: <?php echo format_date($record['payment_date']); ?>
                                            </small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="btn-group btn-group-sm">
                                            <?php if (in_array($record['status'], ['draft', 'calculated'])): ?>
                                                <button class="btn btn-outline-success btn-sm" 
                                                        onclick="submitForApproval(<?php echo $record['id']; ?>, '<?php echo htmlspecialchars($record['employee_name']); ?>')"
                                                        title="Submit for CEO Approval">
                                                    <i class="bi bi-arrow-up-circle"></i> Submit
                                                </button>
                                            <?php endif; ?>
                                            
                                            <?php if ($record['status'] == 'ceo_approved' && $_SESSION['role'] == 'finance_officer'): ?>
                                                <button class="btn btn-outline-primary btn-sm" 
                                                        onclick="markAsPaid(<?php echo $record['id']; ?>, '<?php echo htmlspecialchars($record['employee_name']); ?>')"
                                                        title="Mark as Paid">
                                                    <i class="bi bi-cash-stack"></i>
                                                </button>
                                            <?php endif; ?>
                                            
                                            <button class="btn btn-outline-info" 
                                                    onclick="viewPayrollDetails(<?php echo htmlspecialchars(json_encode($record)); ?>)"
                                                    title="View Details">
                                                <i class="bi bi-eye"></i>
                                            </button>
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
</div>

<!-- Generate Payroll Modal -->
<div class="modal fade" id="generatePayrollModal" tabindex="-1" aria-labelledby="generatePayrollModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header">
                    <h5 class="modal-title" id="generatePayrollModalLabel">
                        <i class="bi bi-calculator me-2"></i>Generate Payroll
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Pay Period Start <span class="text-danger">*</span></label>
                            <input type="date" name="pay_period_start" class="form-control" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Pay Period End <span class="text-danger">*</span></label>
                            <input type="date" name="pay_period_end" class="form-control" required>
                        </div>
                    </div>
                    
                    <label class="form-label">Select Employees <span class="text-danger">*</span></label>
                    <div class="card border p-3" style="max-height: 400px; overflow-y: auto;">
                        <?php foreach ($employees as $emp): ?>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="employee_ids[]" 
                                       value="<?php echo $emp['id']; ?>" id="emp_<?php echo $emp['id']; ?>">
                                <label class="form-check-label" for="emp_<?php echo $emp['id']; ?>">
                                    <strong><?php echo htmlspecialchars($emp['full_name']); ?></strong>
                                    <small class="text-muted d-block"><?php echo htmlspecialchars($emp['department_name']); ?> - Salary: <?php echo number_format($emp['basic_salary'], 2); ?> TZS</small>
                                </label>
                            </div>
                        <?php endforeach; ?>
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

<!-- Submit for Approval Modal -->
<div class="modal fade" id="submitApprovalModal" tabindex="-1" aria-labelledby="submitApprovalModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header">
                    <h5 class="modal-title" id="submitApprovalModalLabel">
                        <i class="bi bi-arrow-up-circle me-2"></i>Submit Payroll for CEO Approval
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="submit_for_approval" value="1">
                    <input type="hidden" id="submitPayrollId" name="payroll_ids" value="">
                    
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle me-2"></i>
                        You are about to submit <strong id="submitEmployeeName"></strong>'s payroll for CEO approval. 
                        This action cannot be undone.
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Submission Reason</label>
                        <textarea name="submission_reason" class="form-control" rows="3" 
                                  placeholder="Please provide a reason for submitting this payroll for CEO approval..." required></textarea>
                        <div class="form-text">This will be visible to the CEO when reviewing the approval request.</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="bi bi-x-lg"></i> Cancel
                    </button>
                    <button type="submit" class="btn btn-success">
                        <i class="bi bi-arrow-up-circle me-2"></i>Submit for Approval
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Mark as Paid Modal -->
<div class="modal fade" id="markPaidModal" tabindex="-1" aria-labelledby="markPaidModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header">
                    <h5 class="modal-title" id="markPaidModalLabel">
                        <i class="bi bi-cash-stack me-2"></i>Mark Payroll as Paid
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="mark_paid" value="1">
                    <input type="hidden" name="payroll_id" id="paidPayrollId">
                    
                    <div class="alert alert-warning">
                        <i class="bi bi-exclamation-triangle me-2"></i>
                        You are about to mark <strong id="paidEmployeeName"></strong>'s payroll as paid. 
                        Please ensure payment has been processed.
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <label class="form-label">Payment Date</label>
                            <input type="date" name="payment_date" class="form-control" 
                                   value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Payment Method</label>
                            <select name="payment_method" class="form-select" required>
                                <option value="">Select Method</option>
                                <option value="bank_transfer">Bank Transfer</option>
                                <option value="cash">Cash</option>
                                <option value="cheque">Cheque</option>
                                <option value="mobile_money">Mobile Money</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="mt-3">
                        <label class="form-label">Payment Reference</label>
                        <input type="text" name="payment_reference" class="form-control" 
                               placeholder="Transaction ID, cheque number, etc." required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="bi bi-x-lg"></i> Cancel
                    </button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-cash-stack me-2"></i>Mark as Paid
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- View Payroll Details Modal -->
<div class="modal fade" id="viewPayrollModal" tabindex="-1" aria-labelledby="viewPayrollModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="viewPayrollModalLabel">
                    <i class="bi bi-eye me-2"></i>Payroll Details
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div id="payrollDetailsContent">
                    <!-- Payroll details will be populated here -->
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="bi bi-x-lg"></i> Close
                </button>
            </div>
        </div>
    </div>
</div>

<script>
function submitForApproval(payrollId, employeeName) {
    // Set payroll ID with array naming for backend
    const hiddenField = document.getElementById('submitPayrollId');
    hiddenField.setAttribute('name', 'payroll_ids[]');
    hiddenField.value = payrollId;
    
    // Update employee name in modal
    document.getElementById('submitEmployeeName').textContent = employeeName;
    
    // Clear the submission reason textarea
    const textarea = document.querySelector('#submitApprovalModal textarea[name="submission_reason"]');
    if (textarea) textarea.value = '';
    
    // Show modal
    new bootstrap.Modal(document.getElementById('submitApprovalModal')).show();
}

function markAsPaid(payrollId, employeeName) {
    document.getElementById('paidPayrollId').value = payrollId;
    document.getElementById('paidEmployeeName').textContent = employeeName;
    new bootstrap.Modal(document.getElementById('markPaidModal')).show();
}

function viewPayrollDetails(record) {
    const content = document.getElementById('payrollDetailsContent');
    
    content.innerHTML = `
        <div class="row">
            <div class="col-md-6">
                <h6 class="text-primary mb-3"><i class="bi bi-person me-2"></i>Employee Information</h6>
                <table class="table table-sm">
                    <tr>
                        <td><strong>Name:</strong></td>
                        <td>${record.employee_name}</td>
                    </tr>
                    <tr>
                        <td><strong>Employee ID:</strong></td>
                        <td>${record.employee_id}</td>
                    </tr>
                    <tr>
                        <td><strong>Department:</strong></td>
                        <td>${record.department_name}</td>
                    </tr>
                </table>
            </div>
            <div class="col-md-6">
                <h6 class="text-primary mb-3"><i class="bi bi-calendar me-2"></i>Pay Period</h6>
                <table class="table table-sm">
                    <tr>
                        <td><strong>Start Date:</strong></td>
                        <td>${new Date(record.pay_period_start).toLocaleDateString()}</td>
                    </tr>
                    <tr>
                        <td><strong>End Date:</strong></td>
                        <td>${new Date(record.pay_period_end).toLocaleDateString()}</td>
                    </tr>
                    <tr>
                        <td><strong>Status:</strong></td>
                        <td><span class="badge bg-${getStatusBadgeClass(record.status)}">${getStatusDisplayName(record.status)}</span></td>
                    </tr>
                </table>
            </div>
        </div>
        
        <hr>
        
        <div class="row">
            <div class="col-md-6">
                <h6 class="text-success mb-3"><i class="bi bi-cash-stack me-2"></i>Salary Breakdown</h6>
                <table class="table table-sm">
                    <tr>
                        <td><strong>Basic Salary:</strong></td>
                        <td class="text-end">${Number(record.basic_salary).toLocaleString()} ${record.currency}</td>
                    </tr>
                    <tr>
                        <td><strong>Total Allowances:</strong></td>
                        <td class="text-end">${Number(record.total_allowances).toLocaleString()} ${record.currency}</td>
                    </tr>
                    <tr>
                        <td><strong>Total Deductions:</strong></td>
                        <td class="text-end">${Number(record.total_deductions).toLocaleString()} ${record.currency}</td>
                    </tr>
                    <tr class="table-success">
                        <td><strong>Net Pay:</strong></td>
                        <td class="text-end"><strong>${Number(record.net_pay).toLocaleString()} ${record.currency}</strong></td>
                    </tr>
                </table>
            </div>
            <div class="col-md-6">
                <h6 class="text-info mb-3"><i class="bi bi-clock-history me-2"></i>Processing History</h6>
                <table class="table table-sm">
                    <tr>
                        <td><strong>Created:</strong></td>
                        <td>${record.created_at ? new Date(record.created_at).toLocaleString() : 'N/A'}</td>
                    </tr>
                    <tr>
                        <td><strong>Processed By:</strong></td>
                        <td>${record.processed_by_name || 'N/A'}</td>
                    </tr>
                    ${record.ceo_approved_by ? `
                    <tr>
                        <td><strong>CEO Approved:</strong></td>
                        <td>${record.ceo_approved_at ? new Date(record.ceo_approved_at).toLocaleString() : 'N/A'}</td>
                    </tr>
                    <tr>
                        <td><strong>CEO Approved By:</strong></td>
                        <td>${record.ceo_approved_by_name || 'N/A'}</td>
                    </tr>
                    ` : ''}
                    ${record.finance_processed_by ? `
                    <tr>
                        <td><strong>Finance Processed:</strong></td>
                        <td>${record.finance_processed_at ? new Date(record.finance_processed_at).toLocaleString() : 'N/A'}</td>
                    </tr>
                    <tr>
                        <td><strong>Finance Processed By:</strong></td>
                        <td>${record.finance_processed_by_name || 'N/A'}</td>
                    </tr>
                    ` : ''}
                    ${record.payment_date ? `
                    <tr>
                        <td><strong>Payment Date:</strong></td>
                        <td>${new Date(record.payment_date).toLocaleDateString()}</td>
                    </tr>
                    <tr>
                        <td><strong>Payment Method:</strong></td>
                        <td>${record.payment_method || 'N/A'}</td>
                    </tr>
                    <tr>
                        <td><strong>Payment Reference:</strong></td>
                        <td>${record.payment_reference || 'N/A'}</td>
                    </tr>
                    ` : ''}
                </table>
            </div>
        </div>
    `;
    
    new bootstrap.Modal(document.getElementById('viewPayrollModal')).show();
}

function getStatusBadgeClass(status) {
    const classes = {
        'draft': 'secondary',
        'calculated': 'warning',
        'pending_ceo_approval': 'danger',
        'ceo_approved': 'success',
        'ready_for_payment': 'info',
        'paid': 'primary'
    };
    return classes[status] || 'secondary';
}

function getStatusDisplayName(status) {
    const names = {
        'draft': 'Draft',
        'calculated': 'Calculated',
        'pending_ceo_approval': 'Pending CEO Approval',
        'ceo_approved': 'CEO Approved',
        'ready_for_payment': 'Ready for Payment',
        'paid': 'Paid'
    };
    return names[status] || status;
}

// Search functionality
document.getElementById('payrollSearch').addEventListener('input', function() {
    const searchTerm = this.value.toLowerCase();
    const rows = document.querySelectorAll('#payrollTable tbody tr');
    
    rows.forEach(row => {
        const text = row.textContent.toLowerCase();
        row.style.display = text.includes(searchTerm) ? '' : 'none';
    });
});
</script>

<?php include '../includes/footer.php'; ?>
                    <button type="submit" name="process_payroll" class="btn btn-primary">Process Payroll</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include '../includes/footer.php'; ?>