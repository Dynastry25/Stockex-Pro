<?php
require_once '../config/config.php';
require_once '../auth/auth_middleware.php';

require_hr();
$db = getDBConnection();

$page_title = 'HR Dashboard';
include '../includes/header.php';

// Get statistics with proper error handling
try {
    $total_employees = $db->query("SELECT COUNT(*) FROM employees WHERE status = 'active'")->fetchColumn();
} catch (Exception $e) {
    $total_employees = 0;
}

try {
    $total_departments = $db->query("SELECT COUNT(*) FROM departments WHERE status = 'active'")->fetchColumn();
} catch (Exception $e) {
    $total_departments = 0;
}

try {
    $pending_leaves = $db->query("SELECT COUNT(*) FROM leave_requests WHERE status = 'pending'")->fetchColumn();
} catch (Exception $e) {
    $pending_leaves = 0;
}

try {
    $open_positions = $db->query("SELECT COUNT(*) FROM job_positions WHERE status = 'open'")->fetchColumn();
} catch (Exception $e) {
    $open_positions = 0;
}

try {
    $pending_payroll = $db->query("SELECT COUNT(*) FROM payroll WHERE status IN ('draft', 'calculated')")->fetchColumn();
} catch (Exception $e) {
    $pending_payroll = 0;
}

try {
    $active_targets = $db->query("SELECT COUNT(*) FROM performance_targets WHERE status = 'active'")->fetchColumn();
} catch (Exception $e) {
    $active_targets = 0;
}

try {
    $new_applications = $db->query("SELECT COUNT(*) FROM job_applications WHERE status = 'received'")->fetchColumn();
} catch (Exception $e) {
    $new_applications = 0;
}

try {
    $overdue_targets = $db->query("SELECT COUNT(*) FROM performance_targets WHERE status = 'active' AND end_date < CURDATE()")->fetchColumn();
} catch (Exception $e) {
    $overdue_targets = 0;
}

// Get recent activities with error handling
try {
    $stmt = $db->query("
        SELECT activity_type, description, performed_at as created_at 
        FROM hr_activities 
        ORDER BY performed_at DESC 
        LIMIT 5
    ");
    $recent_activities = $stmt->fetchAll();
} catch (Exception $e) {
    $recent_activities = [];
}

// Check if we need to show database setup warning
$show_setup_warning = false;
try {
    $db->query("SELECT 1 FROM employees LIMIT 1");
} catch (Exception $e) {
    $show_setup_warning = true;
}
?>

<div class="container-fluid pt-4 px-4">
    <?php if ($show_setup_warning): ?>
    <div class="alert alert-warning alert-dismissible fade show" role="alert">
        <i class="bi bi-exclamation-triangle me-2"></i>
        <strong>HR Database Setup Required:</strong> Some HR tables are missing. 
        <a href="setup_database.php" class="alert-link">Click here to set up the database</a>.
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
    <?php endif; ?>

    <div class="d-sm-flex align-items-center justify-content-between mb-4">
        <h1 class="h3 mb-0 text-gray-800">HR Dashboard</h1>
        <div class="d-flex">
            <button class="btn btn-primary me-2" data-bs-toggle="modal" data-bs-target="#addEmployeeModal" <?php echo $show_setup_warning ? 'disabled' : ''; ?>>
                <i class="bi bi-person-plus me-2"></i>Add Employee
            </button>
            <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#addDepartmentModal" <?php echo $show_setup_warning ? 'disabled' : ''; ?>>
                <i class="bi bi-building me-2"></i>Add Department
            </button>
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
                                Total Employees</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo $total_employees; ?></div>
                        </div>
                        <div class="col-auto">
                            <i class="bi bi-people fa-2x text-gray-300"></i>
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
                                Departments</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo $total_departments; ?></div>
                        </div>
                        <div class="col-auto">
                            <i class="bi bi-building fa-2x text-gray-300"></i>
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
                                Pending Leaves</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo $pending_leaves; ?></div>
                        </div>
                        <div class="col-auto">
                            <i class="bi bi-calendar-check fa-2x text-gray-300"></i>
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
                                Open Positions</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo $open_positions; ?></div>
                        </div>
                        <div class="col-auto">
                            <i class="bi bi-briefcase fa-2x text-gray-300"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Additional Statistics Row -->
    <div class="row mb-4">
        <div class="col-xl-3 col-md-6 mb-4">
            <div class="card border-left-danger shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-danger text-uppercase mb-1">
                                Pending Payroll</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo $pending_payroll; ?></div>
                        </div>
                        <div class="col-auto">
                            <i class="bi bi-cash-coin fa-2x text-gray-300"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6 mb-4">
            <div class="card border-left-primary shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">
                                Active Targets</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo $active_targets; ?></div>
                        </div>
                        <div class="col-auto">
                            <i class="bi bi-bullseye fa-2x text-gray-300"></i>
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
                                New Applications</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo $new_applications; ?></div>
                        </div>
                        <div class="col-auto">
                            <i class="bi bi-file-person fa-2x text-gray-300"></i>
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
                                Overdue Targets</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo $overdue_targets; ?></div>
                        </div>
                        <div class="col-auto">
                            <i class="bi bi-exclamation-triangle fa-2x text-gray-300"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <!-- Employee Management -->
        <div class="col-lg-8 mb-4">
            <div class="card shadow mb-4">
                <div class="card-header py-3 d-flex justify-content-between align-items-center">
                    <h6 class="m-0 font-weight-bold text-primary">Employee Management</h6>
                    <div class="btn-group">
                        <a href="employees.php" class="btn btn-sm btn-outline-primary" <?php echo $show_setup_warning ? 'disabled' : ''; ?>>View All</a>
                        <a href="reports.php" class="btn btn-sm btn-outline-info" <?php echo $show_setup_warning ? 'disabled' : ''; ?>>Reports</a>
                    </div>
                </div>
                <div class="card-body">
                    <?php
                    try {
                        $stmt = $db->query("
                            SELECT e.*, d.name as department_name, jp.title as position_title
                            FROM employees e
                            LEFT JOIN departments d ON e.department_id = d.id
                            LEFT JOIN job_positions jp ON e.position_id = jp.id
                            ORDER BY e.created_at DESC LIMIT 10
                        ");
                        $employees = $stmt->fetchAll();
                    } catch (Exception $e) {
                        $employees = [];
                    }
                    ?>
                    
                    <?php if (!empty($employees)): ?>
                    <div class="table-responsive">
                        <table class="table table-bordered" id="employeeTable" width="100%" cellspacing="0">
                            <thead>
                                <tr>
                                    <th>Employee ID</th>
                                    <th>Name</th>
                                    <th>Department</th>
                                    <th>Position</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($employees as $employee): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($employee['employee_id'] ?? 'N/A'); ?></td>
                                    <td><?php echo htmlspecialchars(($employee['first_name'] ?? '') . ' ' . ($employee['last_name'] ?? '')); ?></td>
                                    <td><?php echo htmlspecialchars($employee['department_name'] ?? 'N/A'); ?></td>
                                    <td><?php echo htmlspecialchars($employee['position_title'] ?? 'N/A'); ?></td>
                                    <td>
                                        <span class="badge bg-<?php echo ($employee['status'] ?? '') == 'active' ? 'success' : 'danger'; ?>">
                                            <?php echo ucfirst($employee['status'] ?? 'Unknown'); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="btn-group btn-group-sm">
                                            <a href="employee_details.php?id=<?php echo $employee['id']; ?>" class="btn btn-info">
                                                <i class="bi bi-eye"></i>
                                            </a>
                                            <a href="#" class="btn btn-warning" data-bs-toggle="modal" data-bs-target="#editEmployeeModal<?php echo $employee['id']; ?>">
                                                <i class="bi bi-pencil"></i>
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php else: ?>
                    <div class="text-center py-4">
                        <i class="bi bi-people display-1 text-muted"></i>
                        <h5 class="text-muted">No employees found</h5>
                        <p class="text-muted">Add your first employee to get started.</p>
                        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addEmployeeModal" <?php echo $show_setup_warning ? 'disabled' : ''; ?>>
                            <i class="bi bi-person-plus me-2"></i>Add First Employee
                        </button>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Recent Leave Requests -->
            <div class="card shadow mb-4">
                <div class="card-header py-3">
                    <h6 class="m-0 font-weight-bold text-primary">Recent Leave Requests</h6>
                </div>
                <div class="card-body">
                    <?php
                    try {
                        $stmt = $db->query("
                            SELECT lr.*, e.first_name, e.last_name, e.employee_id,
                                   lt.name as leave_type
                            FROM leave_requests lr
                            JOIN employees e ON lr.employee_id = e.id
                            JOIN leave_types lt ON lr.leave_type_id = lt.id
                            ORDER BY lr.created_at DESC LIMIT 5
                        ");
                        $leave_requests = $stmt->fetchAll();
                    } catch (Exception $e) {
                        $leave_requests = [];
                    }
                    ?>
                    
                    <?php if (!empty($leave_requests)): ?>
                    <div class="table-responsive">
                        <table class="table table-bordered table-sm">
                            <thead>
                                <tr>
                                    <th>Employee</th>
                                    <th>Leave Type</th>
                                    <th>Period</th>
                                    <th>Status</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($leave_requests as $request): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($request['first_name'] . ' ' . $request['last_name']); ?></td>
                                    <td><?php echo htmlspecialchars($request['leave_type']); ?></td>
                                    <td>
                                        <?php echo format_date($request['start_date']); ?> - 
                                        <?php echo format_date($request['end_date']); ?>
                                    </td>
                                    <td>
                                        <span class="badge bg-<?php 
                                            echo $request['status'] == 'approved' ? 'success' : 
                                                 ($request['status'] == 'pending' ? 'warning' : 'danger'); 
                                        ?>">
                                            <?php echo ucfirst($request['status']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($request['status'] == 'pending'): ?>
                                        <div class="btn-group btn-group-sm">
                                            <a href="leave_management.php?action=approve&id=<?php echo $request['id']; ?>" class="btn btn-success btn-sm">
                                                <i class="bi bi-check-lg"></i>
                                            </a>
                                            <a href="leave_management.php?action=reject&id=<?php echo $request['id']; ?>" class="btn btn-danger btn-sm">
                                                <i class="bi bi-x-lg"></i>
                                            </a>
                                        </div>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php else: ?>
                    <div class="text-center py-3">
                        <p class="text-muted mb-0">No pending leave requests</p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Recent Activities & Quick Actions -->
        <div class="col-lg-4 mb-4">
            <!-- Recent Activities -->
            <div class="card shadow mb-4">
                <div class="card-header py-3">
                    <h6 class="m-0 font-weight-bold text-primary">Recent Activities</h6>
                </div>
                <div class="card-body">
                    <div class="activity-feed">
                        <?php if (!empty($recent_activities)): ?>
                            <?php foreach ($recent_activities as $activity): ?>
                            <div class="activity-item mb-3">
                                <div class="activity-content">
                                    <small class="text-muted"><?php echo format_date($activity['created_at']); ?></small>
                                    <p class="mb-1"><?php echo htmlspecialchars($activity['description']); ?></p>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="text-center py-3">
                                <i class="bi bi-activity display-4 text-muted"></i>
                                <p class="text-muted mt-2">No recent activities</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Quick Actions -->
            <div class="card shadow mb-4">
                <div class="card-header py-3">
                    <h6 class="m-0 font-weight-bold text-primary">Quick Actions</h6>
                </div>
                <div class="card-body">
                    <div class="d-grid gap-2">
                        <a href="leave_management.php" class="btn btn-outline-primary btn-sm" <?php echo $show_setup_warning ? 'disabled' : ''; ?>>
                            <i class="bi bi-calendar-check me-2"></i>Leave Management
                        </a>
                        <a href="payroll.php" class="btn btn-outline-success btn-sm" <?php echo $show_setup_warning ? 'disabled' : ''; ?>>
                            <i class="bi bi-cash-coin me-2"></i>Payroll Processing
                        </a>
                        <a href="recruitment.php" class="btn btn-outline-info btn-sm" <?php echo $show_setup_warning ? 'disabled' : ''; ?>>
                            <i class="bi bi-person-badge me-2"></i>Recruitment
                        </a>
                        <a href="reports.php" class="btn btn-outline-warning btn-sm" <?php echo $show_setup_warning ? 'disabled' : ''; ?>>
                            <i class="bi bi-graph-up me-2"></i>HR Reports
                        </a>
                        <?php if ($show_setup_warning): ?>
                        <a href="setup_database.php" class="btn btn-outline-danger btn-sm">
                            <i class="bi bi-database me-2"></i>Setup Database
                        </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Upcoming Birthdays -->
            <div class="card shadow">
                <div class="card-header py-3">
                    <h6 class="m-0 font-weight-bold text-primary">Upcoming Birthdays</h6>
                </div>
                <div class="card-body">
                    <?php
                    try {
                        $stmt = $db->query("
                            SELECT first_name, last_name, date_of_birth
                            FROM employees 
                            WHERE MONTH(date_of_birth) = MONTH(CURDATE())
                            AND DAY(date_of_birth) >= DAY(CURDATE())
                            ORDER BY DAY(date_of_birth) ASC
                            LIMIT 5
                        ");
                        $upcoming_birthdays = $stmt->fetchAll();
                    } catch (Exception $e) {
                        $upcoming_birthdays = [];
                    }
                    ?>
                    
                    <?php if (!empty($upcoming_birthdays)): ?>
                        <?php foreach ($upcoming_birthdays as $birthday): ?>
                        <div class="d-flex align-items-center mb-2">
                            <i class="bi bi-gift text-primary me-2"></i>
                            <div>
                                <strong><?php echo htmlspecialchars($birthday['first_name'] . ' ' . $birthday['last_name']); ?></strong>
                                <br>
                                <small class="text-muted">
                                    <?php echo date('M j', strtotime($birthday['date_of_birth'])); ?>
                                </small>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="text-center py-2">
                            <p class="text-muted mb-0">No upcoming birthdays this month</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Add Employee Modal -->
<div class="modal fade" id="addEmployeeModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" action="hr_actions.php">
                <div class="modal-header">
                    <h5 class="modal-title">Add New Employee</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">First Name</label>
                            <input type="text" class="form-control" name="first_name" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Last Name</label>
                            <input type="text" class="form-control" name="last_name" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Email</label>
                            <input type="email" class="form-control" name="email" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Phone</label>
                            <input type="text" class="form-control" name="phone">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Date of Birth</label>
                            <input type="date" class="form-control" name="date_of_birth">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Employee ID</label>
                            <input type="text" class="form-control" name="employee_id" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Department</label>
                            <select class="form-select" name="department_id" required>
                                <option value="">Select Department</option>
                                <?php
                                try {
                                    $depts = $db->query("SELECT id, name FROM departments WHERE status='active'")->fetchAll();
                                    foreach ($depts as $dept): ?>
                                    <option value="<?php echo $dept['id']; ?>"><?php echo htmlspecialchars($dept['name']); ?></option>
                                    <?php endforeach;
                                } catch (Exception $e) {
                                    echo '<option value="">No departments available</option>';
                                }
                                ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Position</label>
                            <select class="form-select" name="position_id" required>
                                <option value="">Select Position</option>
                                <?php
                                try {
                                    $positions = $db->query("SELECT id, title FROM positions WHERE status='active'")->fetchAll();
                                    foreach ($positions as $pos): ?>
                                    <option value="<?php echo $pos['id']; ?>"><?php echo htmlspecialchars($pos['title']); ?></option>
                                    <?php endforeach;
                                } catch (Exception $e) {
                                    echo '<option value="">No positions available</option>';
                                }
                                ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Hire Date</label>
                            <input type="date" class="form-control" name="hire_date" required value="<?php echo date('Y-m-d'); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Salary (TZS)</label>
                            <input type="number" class="form-control" name="salary" step="0.01" min="0" required>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="add_employee" class="btn btn-primary">Add Employee</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Add Department Modal -->
<div class="modal fade" id="addDepartmentModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="hr_actions.php">
                <div class="modal-header">
                    <h5 class="modal-title">Add New Department</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Department Name</label>
                        <input type="text" class="form-control" name="name" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <textarea class="form-control" name="description" rows="3"></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Manager</label>
                        <select class="form-select" name="manager_id">
                            <option value="">Select Manager</option>
                            <?php
                            try {
                                $managers = $db->query("SELECT id, first_name, last_name FROM employees WHERE status='active'")->fetchAll();
                                foreach ($managers as $mgr): ?>
                                <option value="<?php echo $mgr['id']; ?>">
                                    <?php echo htmlspecialchars($mgr['first_name'] . ' ' . $mgr['last_name']); ?>
                                </option>
                                <?php endforeach;
                            } catch (Exception $e) {
                                echo '<option value="">No employees available</option>';
                            }
                            ?>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="add_department" class="btn btn-primary">Add Department</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Auto-generate employee ID
document.addEventListener('DOMContentLoaded', function() {
    const employeeIdField = document.querySelector('input[name="employee_id"]');
    if (employeeIdField && !employeeIdField.value) {
        // Generate a simple employee ID (you can customize this logic)
        const timestamp = new Date().getTime().toString().slice(-4);
        const random = Math.floor(Math.random() * 1000).toString().padStart(3, '0');
        employeeIdField.value = 'EMP' + timestamp + random;
    }
});
</script>

<?php include '../includes/footer.php'; ?>