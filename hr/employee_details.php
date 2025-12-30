<?php
require_once '../config/config.php';
require_once '../auth/auth_middleware.php';

require_hr();
$db = getDBConnection();

// Get employee ID from URL
$employee_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$employee_id) {
    show_alert('Invalid employee ID.', 'danger');
    redirect('dashboard.php');
}

// Fetch employee details
try {
    $stmt = $db->prepare("
        SELECT e.*,
               d.name as department_name,
               jp.title as position_title,
               u.full_name as created_by_name,
               au.full_name as approved_by_name
        FROM employees e
        LEFT JOIN departments d ON e.department_id = d.id
        LEFT JOIN job_positions jp ON e.position_id = jp.id
        LEFT JOIN users u ON e.created_by = u.id
        LEFT JOIN users au ON e.approved_by = au.id
        WHERE e.id = ?
    ");
    $stmt->execute([$employee_id]);
    $employee = $stmt->fetch();

    if (!$employee) {
        show_alert('Employee not found.', 'danger');
        redirect('dashboard.php');
    }
} catch (Exception $e) {
    show_alert('Error loading employee details: ' . $e->getMessage(), 'danger');
    redirect('dashboard.php');
}

$page_title = 'Employee Details - ' . htmlspecialchars($employee['first_name'] . ' ' . $employee['last_name']);
include '../includes/header.php';
?>

<div class="container-fluid pt-4 px-4">
    <div class="d-sm-flex align-items-center justify-content-between mb-4">
        <h1 class="h3 mb-0 text-gray-800">
            <i class="bi bi-person-circle me-2"></i>Employee Details
        </h1>
        <div>
            <a href="dashboard.php" class="btn btn-secondary">
                <i class="bi bi-arrow-left me-2"></i>Back to Dashboard
            </a>
            <a href="employees.php" class="btn btn-primary">
                <i class="bi bi-people me-2"></i>All Employees
            </a>
        </div>
    </div>

    <?php if (!empty($error_message)): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <i class="bi bi-exclamation-triangle me-2"></i>
            <?php echo htmlspecialchars($error_message); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="row">
        <!-- Employee Basic Information -->
        <div class="col-lg-8">
            <div class="card shadow mb-4">
                <div class="card-header py-3">
                    <h6 class="m-0 font-weight-bold text-primary">
                        <i class="bi bi-person me-2"></i>Basic Information
                    </h6>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label fw-bold">Employee ID</label>
                                <p class="form-control-plaintext"><?php echo htmlspecialchars($employee['employee_id']); ?></p>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label fw-bold">Status</label>
                                <p class="form-control-plaintext">
                                    <span class="badge bg-<?php echo $employee['status'] == 'active' ? 'success' : ($employee['status'] == 'terminated' ? 'danger' : 'warning'); ?>">
                                        <?php echo ucfirst($employee['status']); ?>
                                    </span>
                                </p>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label class="form-label fw-bold">First Name</label>
                                <p class="form-control-plaintext"><?php echo htmlspecialchars($employee['first_name']); ?></p>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label class="form-label fw-bold">Middle Name</label>
                                <p class="form-control-plaintext"><?php echo htmlspecialchars($employee['middle_name'] ?? 'N/A'); ?></p>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label class="form-label fw-bold">Last Name</label>
                                <p class="form-control-plaintext"><?php echo htmlspecialchars($employee['last_name']); ?></p>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label fw-bold">Email</label>
                                <p class="form-control-plaintext"><?php echo htmlspecialchars($employee['email']); ?></p>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label fw-bold">Phone</label>
                                <p class="form-control-plaintext"><?php echo htmlspecialchars($employee['phone'] ?? 'N/A'); ?></p>
                            </div>
                        </div>
                        <?php if (!empty($employee['alternate_phone'])): ?>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label fw-bold">Alternate Phone</label>
                                <p class="form-control-plaintext"><?php echo htmlspecialchars($employee['alternate_phone']); ?></p>
                            </div>
                        </div>
                        <?php endif; ?>
                        <div class="col-12">
                            <div class="mb-3">
                                <label class="form-label fw-bold">Address</label>
                                <p class="form-control-plaintext"><?php echo htmlspecialchars($employee['address'] ?? 'N/A'); ?></p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Employment Information -->
            <div class="card shadow mb-4">
                <div class="card-header py-3">
                    <h6 class="m-0 font-weight-bold text-primary">
                        <i class="bi bi-briefcase me-2"></i>Employment Information
                    </h6>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label fw-bold">Department</label>
                                <p class="form-control-plaintext"><?php echo htmlspecialchars($employee['department_name'] ?? 'N/A'); ?></p>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label fw-bold">Position</label>
                                <p class="form-control-plaintext"><?php echo htmlspecialchars($employee['position_title'] ?? 'N/A'); ?></p>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label fw-bold">Employment Type</label>
                                <p class="form-control-plaintext"><?php echo ucwords(str_replace('_', ' ', $employee['employment_type'])); ?></p>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label fw-bold">Hire Date</label>
                                <p class="form-control-plaintext"><?php echo $employee['hire_date'] ? date('M d, Y', strtotime($employee['hire_date'])) : 'N/A'; ?></p>
                            </div>
                        </div>
                        <?php if (!empty($employee['probation_end_date'])): ?>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label fw-bold">Probation End Date</label>
                                <p class="form-control-plaintext"><?php echo date('M d, Y', strtotime($employee['probation_end_date'])); ?></p>
                            </div>
                        </div>
                        <?php endif; ?>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label fw-bold">Basic Salary</label>
                                <p class="form-control-plaintext">
                                    <?php echo $employee['basic_salary'] ? number_format($employee['basic_salary'], 2) . ' ' . ($employee['currency'] ?? 'TZS') : 'N/A'; ?>
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Identification & Banking -->
            <div class="card shadow mb-4">
                <div class="card-header py-3">
                    <h6 class="m-0 font-weight-bold text-primary">
                        <i class="bi bi-shield-check me-2"></i>Identification & Banking
                    </h6>
                </div>
                <div class="card-body">
                    <div class="row">
                        <?php if (!empty($employee['national_id'])): ?>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label fw-bold">National ID</label>
                                <p class="form-control-plaintext"><?php echo htmlspecialchars($employee['national_id']); ?></p>
                            </div>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($employee['tax_identification'])): ?>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label fw-bold">Tax Identification</label>
                                <p class="form-control-plaintext"><?php echo htmlspecialchars($employee['tax_identification']); ?></p>
                            </div>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($employee['social_security'])): ?>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label fw-bold">Social Security</label>
                                <p class="form-control-plaintext"><?php echo htmlspecialchars($employee['social_security']); ?></p>
                            </div>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($employee['bank_name'])): ?>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label fw-bold">Bank Name</label>
                                <p class="form-control-plaintext"><?php echo htmlspecialchars($employee['bank_name']); ?></p>
                            </div>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($employee['bank_account_number'])): ?>
                        <div class="col-12">
                            <div class="mb-3">
                                <label class="form-label fw-bold">Bank Account Number</label>
                                <p class="form-control-plaintext"><?php echo htmlspecialchars($employee['bank_account_number']); ?></p>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Sidebar Information -->
        <div class="col-lg-4">
            <!-- Emergency Contact -->
            <?php if (!empty($employee['emergency_contact_name'])): ?>
            <div class="card shadow mb-4">
                <div class="card-header py-3">
                    <h6 class="m-0 font-weight-bold text-primary">
                        <i class="bi bi-telephone me-2"></i>Emergency Contact
                    </h6>
                </div>
                <div class="card-body">
                    <div class="mb-2">
                        <strong><?php echo htmlspecialchars($employee['emergency_contact_name']); ?></strong>
                    </div>
                    <?php if (!empty($employee['emergency_contact_relationship'])): ?>
                    <div class="mb-2">
                        <small class="text-muted">Relationship:</small><br>
                        <?php echo htmlspecialchars($employee['emergency_contact_relationship']); ?>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($employee['emergency_contact_phone'])): ?>
                    <div class="mb-2">
                        <small class="text-muted">Phone:</small><br>
                        <?php echo htmlspecialchars($employee['emergency_contact_phone']); ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Employment Status -->
            <div class="card shadow mb-4">
                <div class="card-header py-3">
                    <h6 class="m-0 font-weight-bold text-primary">
                        <i class="bi bi-info-circle me-2"></i>Employment Status
                    </h6>
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label fw-bold">Current Status</label>
                        <p class="form-control-plaintext">
                            <span class="badge bg-<?php echo $employee['status'] == 'active' ? 'success' : ($employee['status'] == 'terminated' ? 'danger' : 'warning'); ?> fs-6">
                                <?php echo ucfirst($employee['status']); ?>
                            </span>
                        </p>
                    </div>

                    <?php if ($employee['status'] == 'terminated' && !empty($employee['termination_date'])): ?>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Termination Date</label>
                        <p class="form-control-plaintext"><?php echo date('M d, Y', strtotime($employee['termination_date'])); ?></p>
                    </div>
                    <?php endif; ?>

                    <?php if ($employee['status'] == 'terminated' && !empty($employee['termination_reason'])): ?>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Termination Reason</label>
                        <p class="form-control-plaintext"><?php echo htmlspecialchars($employee['termination_reason']); ?></p>
                    </div>
                    <?php endif; ?>

                    <?php if (!empty($employee['approved_by_name'])): ?>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Approved By</label>
                        <p class="form-control-plaintext"><?php echo htmlspecialchars($employee['approved_by_name']); ?></p>
                    </div>
                    <?php endif; ?>

                    <div class="mb-3">
                        <label class="form-label fw-bold">Created By</label>
                        <p class="form-control-plaintext"><?php echo htmlspecialchars($employee['created_by_name'] ?? 'System'); ?></p>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold">Created Date</label>
                        <p class="form-control-plaintext"><?php echo date('M d, Y H:i', strtotime($employee['created_at'])); ?></p>
                    </div>
                </div>
            </div>

            <!-- Quick Actions -->
            <div class="card shadow mb-4">
                <div class="card-header py-3">
                    <h6 class="m-0 font-weight-bold text-primary">
                        <i class="bi bi-gear me-2"></i>Quick Actions
                    </h6>
                </div>
                <div class="card-body">
                    <div class="d-grid gap-2">
                        <button class="btn btn-outline-primary" onclick="printEmployeeDetails()">
                            <i class="bi bi-printer me-2"></i>Print Details
                        </button>
                        <a href="leave_request.php?employee_id=<?php echo $employee['id']; ?>" class="btn btn-outline-info">
                            <i class="bi bi-calendar-x me-2"></i>Request Leave
                        </a>
                        <a href="payroll.php?employee_id=<?php echo $employee['id']; ?>" class="btn btn-outline-success">
                            <i class="bi bi-cash me-2"></i>View Payroll
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Print employee details function
function printEmployeeDetails() {
    window.print();
}
</script>

<?php include '../includes/footer.php'; ?>