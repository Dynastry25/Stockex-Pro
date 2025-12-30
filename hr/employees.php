<?php
require_once '../config/config.php';
require_once '../auth/auth_middleware.php';

require_hr();
$db = getDBConnection();

$page_title = 'Employee Management';
$success_message = '';
$error_message = '';

// Handle employee actions
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        if (isset($_POST['link_user_to_employee'])) {
            // Link an existing user to employee record
            $user_id = (int)$_POST['user_id'];
            $department_id = (int)$_POST['department_id'];
            $position_id = (int)$_POST['position_id'];
            $hire_date = $_POST['hire_date'];
            $basic_salary = (float)$_POST['basic_salary'];
            $employment_type = sanitize_input($_POST['employment_type']);
            
            // Get user details
            $user_stmt = $db->prepare("SELECT id, username, full_name, email FROM users WHERE id = ?");
            $user_stmt->execute([$user_id]);
            $user = $user_stmt->fetch();
            
            if (!$user) {
                $error_message = 'User not found.';
            } else {
                // Check if user already has employee record
                $check_stmt = $db->prepare("SELECT id FROM employees WHERE user_id = ?");
                $check_stmt->execute([$user_id]);
                if ($check_stmt->fetch()) {
                    $error_message = 'This user already has an employee record.';
                } else {
                    $names = explode(' ', $user['full_name'], 2);
                    $first_name = $names[0] ?? '';
                    $last_name = $names[1] ?? '';
                    $employee_id = strtoupper(substr($user['username'], 0, 3)) . '-' . $user_id;
                    
                    $stmt = $db->prepare("
                        INSERT INTO employees (employee_id, user_id, first_name, last_name, email, 
                                             department_id, position_id, hire_date, basic_salary, 
                                             employment_type, status, created_by) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', ?)
                    ");
                    
                    if ($stmt->execute([$employee_id, $user_id, $first_name, $last_name, $user['email'],
                                      $department_id, $position_id, $hire_date, $basic_salary, 
                                      $employment_type, $_SESSION['user_id']])) {
                        
                        show_alert("User '{$user['full_name']}' successfully linked as employee.", 'success');
                        redirect('hr/employees.php');
                    } else {
                        $error_message = 'Error creating employee record.';
                    }
                }
            }
        } elseif (isset($_POST['add_employee'])) {
            // Add new employee
            $employee_id = sanitize_input($_POST['employee_id']);
            $first_name = sanitize_input($_POST['first_name']);
            $last_name = sanitize_input($_POST['last_name']);
            $middle_name = sanitize_input($_POST['middle_name']);
            $email = sanitize_input($_POST['email']);
            $phone = sanitize_input($_POST['phone']);
            $department_id = (int)$_POST['department_id'];
            $position_id = (int)$_POST['position_id'];
            $hire_date = $_POST['hire_date'];
            $basic_salary = (float)$_POST['basic_salary'];
            $employment_type = sanitize_input($_POST['employment_type']);
            $national_id = sanitize_input($_POST['national_id']);
            $address = sanitize_input($_POST['address']);
            
            // Check if employee_id or email already exists
            $check_stmt = $db->prepare("SELECT id FROM employees WHERE employee_id = ? OR email = ?");
            $check_stmt->execute([$employee_id, $email]);
            if ($check_stmt->fetch()) {
                $error_message = 'Employee ID or email already exists.';
            } else {
                $stmt = $db->prepare("
                    INSERT INTO employees (employee_id, first_name, last_name, middle_name, email, phone, 
                                         department_id, position_id, hire_date, basic_salary, employment_type, 
                                         national_id, address, created_by) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                
                if ($stmt->execute([$employee_id, $first_name, $last_name, $middle_name, $email, $phone,
                                  $department_id, $position_id, $hire_date, $basic_salary, $employment_type,
                                  $national_id, $address, $_SESSION['user_id']])) {
                    
                    // Log activity
                    $activity_stmt = $db->prepare("
                        INSERT INTO hr_activities (activity_type, entity_type, entity_id, description, performed_by)
                        VALUES ('employee_created', 'employee', ?, ?, ?)
                    ");
                    $activity_stmt->execute([
                        $db->lastInsertId(),
                        "Employee {$first_name} {$last_name} was created",
                        $_SESSION['user_id']
                    ]);
                    
                    show_alert('Employee added successfully.', 'success');
                    redirect('hr/employees.php');
                } else {
                    $error_message = 'Error adding employee.';
                }
            }
            
        } elseif (isset($_POST['update_employee'])) {
            // Update employee
            $employee_id = (int)$_POST['employee_id'];
            $first_name = sanitize_input($_POST['first_name']);
            $last_name = sanitize_input($_POST['last_name']);
            $middle_name = sanitize_input($_POST['middle_name']);
            $email = sanitize_input($_POST['email']);
            $phone = sanitize_input($_POST['phone']);
            $department_id = (int)$_POST['department_id'];
            $position_id = (int)$_POST['position_id'];
            $basic_salary = (float)$_POST['basic_salary'];
            $employment_type = sanitize_input($_POST['employment_type']);
            $status = sanitize_input($_POST['status']);
            $address = sanitize_input($_POST['address']);
            
            $stmt = $db->prepare("
                UPDATE employees 
                SET first_name = ?, last_name = ?, middle_name = ?, email = ?, phone = ?, 
                    department_id = ?, position_id = ?, basic_salary = ?, employment_type = ?, 
                    status = ?, address = ?
                WHERE id = ?
            ");
            
            if ($stmt->execute([$first_name, $last_name, $middle_name, $email, $phone,
                              $department_id, $position_id, $basic_salary, $employment_type,
                              $status, $address, $employee_id])) {
                
                // Log activity
                $activity_stmt = $db->prepare("
                    INSERT INTO hr_activities (activity_type, entity_type, entity_id, description, performed_by)
                    VALUES ('employee_updated', 'employee', ?, ?, ?)
                ");
                $activity_stmt->execute([
                    $employee_id,
                    "Employee {$first_name} {$last_name} information was updated",
                    $_SESSION['user_id']
                ]);
                
                show_alert('Employee updated successfully.', 'success');
                redirect('hr/employees.php');
            } else {
                $error_message = 'Error updating employee.';
            }
            
        } elseif (isset($_POST['terminate_employee'])) {
            // Terminate employee
            $employee_id = (int)$_POST['employee_id'];
            $termination_reason = sanitize_input($_POST['termination_reason']);
            $termination_date = $_POST['termination_date'];
            
            $stmt = $db->prepare("
                UPDATE employees 
                SET status = 'terminated', termination_date = ?, termination_reason = ?
                WHERE id = ?
            ");
            
            if ($stmt->execute([$termination_date, $termination_reason, $employee_id])) {
                // Log activity
                $activity_stmt = $db->prepare("
                    INSERT INTO hr_activities (activity_type, entity_type, entity_id, description, performed_by)
                    VALUES ('employee_terminated', 'employee', ?, ?, ?)
                ");
                $activity_stmt->execute([
                    $employee_id,
                    "Employee was terminated: {$termination_reason}",
                    $_SESSION['user_id']
                ]);
                
                show_alert('Employee terminated successfully.', 'warning');
                redirect('hr/employees.php');
            } else {
                $error_message = 'Error terminating employee.';
            }
        }
    } catch (Exception $e) {
        $error_message = 'Database error: ' . $e->getMessage();
    }
}

// Handle employee status actions via GET
if (isset($_GET['action']) && isset($_GET['id'])) {
    $action = $_GET['action'];
    $employee_id = (int)$_GET['id'];
    
    try {
        switch ($action) {
            case 'activate':
                $stmt = $db->prepare("UPDATE employees SET status = 'active' WHERE id = ?");
                $stmt->execute([$employee_id]);
                show_alert('Employee activated successfully.', 'success');
                break;
            case 'suspend':
                $stmt = $db->prepare("UPDATE employees SET status = 'suspended' WHERE id = ?");
                $stmt->execute([$employee_id]);
                show_alert('Employee suspended.', 'warning');
                break;
        }
        redirect('hr/employees.php');
    } catch (Exception $e) {
        show_alert('Error updating employee status.', 'danger');
        redirect('hr/employees.php');
    }
}

// Get all employees with department and position info
try {
    $stmt = $db->query("
        SELECT e.*, d.name as department_name, jp.title as position_title,
               u.username, u.is_active as user_status
        FROM employees e
        LEFT JOIN departments d ON e.department_id = d.id
        LEFT JOIN job_positions jp ON e.position_id = jp.id
        LEFT JOIN users u ON e.user_id = u.id
        ORDER BY e.created_at DESC
    ");
    $employees = $stmt->fetchAll();
} catch (Exception $e) {
    $employees = [];
    $error_message = 'Error loading employees: ' . $e->getMessage();
}

// Get departments and positions for forms
try {
    $dept_stmt = $db->query("SELECT id, name FROM departments WHERE status = 'active' ORDER BY name");
    $departments = $dept_stmt->fetchAll();
    
    $pos_stmt = $db->query("SELECT id, title, department_id FROM job_positions WHERE status = 'open' ORDER BY title");
    $positions = $pos_stmt->fetchAll();
} catch (Exception $e) {
    $departments = [];
    $positions = [];
}
?>


<?php include '../includes/header.php'; ?>
<div class="container-fluid pt-4 px-4">
    <div class="d-sm-flex align-items-center justify-content-between mb-4">
        <h1 class="h3 mb-0 text-gray-800">
            <i class="bi bi-people-fill me-2"></i>Employee Management
        </h1>
        <div class="d-flex gap-2">
            <button class="btn btn-info" data-bs-toggle="modal" data-bs-target="#linkUserModal">
                <i class="bi bi-link-45deg me-2"></i>Link Existing User
            </button>
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addEmployeeModal">
                <i class="bi bi-person-plus me-2"></i>Add Employee
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

    <div class="card shadow mb-4">
        <div class="card-header py-3 d-flex justify-content-between align-items-center">
            <h6 class="m-0 font-weight-bold text-primary">All Employees (<?php echo count($employees); ?>)</h6>
            <div class="d-flex gap-2">
                <input type="text" class="form-control form-control-sm" id="employeeSearch" 
                       placeholder="Search employees..." style="width: 200px;">
                <select class="form-select form-select-sm" id="departmentFilter" style="width: 150px;">
                    <option value="">All Departments</option>
                    <?php foreach ($departments as $dept): ?>
                        <option value="<?php echo htmlspecialchars($dept['name']); ?>">
                            <?php echo htmlspecialchars($dept['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-bordered table-hover" id="employeesTable">
                    <thead class="table-light">
                        <tr>
                            <th>Employee ID</th>
                            <th>Name</th>
                            <th>Email</th>
                            <th>Department</th>
                            <th>Position</th>
                            <th>Hire Date</th>
                            <th>Salary</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($employees)): ?>
                            <tr>
                                <td colspan="9" class="text-center text-muted py-4">
                                    <i class="bi bi-people display-4"></i>
                                    <p class="mt-2 mb-0">No employees found. Add your first employee to get started.</p>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($employees as $employee): ?>
                                <tr>
                                    <td>
                                        <strong><?php echo htmlspecialchars($employee['employee_id']); ?></strong>
                                    </td>
                                    <td>
                                        <div>
                                            <strong><?php echo htmlspecialchars($employee['first_name'] . ' ' . $employee['last_name']); ?></strong>
                                            <?php if (!empty($employee['middle_name'])): ?>
                                                <br><small class="text-muted"><?php echo htmlspecialchars($employee['middle_name']); ?></small>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td><?php echo htmlspecialchars($employee['email']); ?></td>
                                    <td><?php echo htmlspecialchars($employee['department_name'] ?? 'N/A'); ?></td>
                                    <td><?php echo htmlspecialchars($employee['position_title'] ?? 'N/A'); ?></td>
                                    <td><?php echo format_date($employee['hire_date']); ?></td>
                                    <td>
                                        <strong><?php echo number_format($employee['basic_salary'], 2); ?></strong>
                                        <small class="text-muted"><?php echo $employee['currency']; ?></small>
                                    </td>
                                    <td>
                                        <?php
                                        $status_colors = [
                                            'active' => 'success',
                                            'terminated' => 'danger',
                                            'suspended' => 'warning',
                                            'on_leave' => 'info'
                                        ];
                                        $color = $status_colors[$employee['status']] ?? 'secondary';
                                        ?>
                                        <span class="badge bg-<?php echo $color; ?>">
                                            <?php echo ucfirst(str_replace('_', ' ', $employee['status'])); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="btn-group btn-group-sm">
                                            <button class="btn btn-outline-primary" 
                                                    onclick="editEmployee(<?php echo htmlspecialchars(json_encode($employee)); ?>)"
                                                    title="Edit Employee">
                                                <i class="bi bi-pencil"></i>
                                            </button>
                                            
                                            <?php if ($employee['status'] == 'active'): ?>
                                                <a href="?action=suspend&id=<?php echo $employee['id']; ?>" 
                                                   class="btn btn-outline-warning"
                                                   onclick="return confirm('Suspend this employee?')"
                                                   title="Suspend Employee">
                                                    <i class="bi bi-pause-circle"></i>
                                                </a>
                                            <?php elseif ($employee['status'] == 'suspended'): ?>
                                                <a href="?action=activate&id=<?php echo $employee['id']; ?>" 
                                                   class="btn btn-outline-success"
                                                   onclick="return confirm('Activate this employee?')"
                                                   title="Activate Employee">
                                                    <i class="bi bi-play-circle"></i>
                                                </a>
                                            <?php endif; ?>
                                            
                                            <?php if ($employee['status'] != 'terminated'): ?>
                                                <button class="btn btn-outline-danger" 
                                                        onclick="terminateEmployee(<?php echo $employee['id']; ?>, '<?php echo htmlspecialchars($employee['first_name'] . ' ' . $employee['last_name']); ?>')"
                                                        title="Terminate Employee">
                                                    <i class="bi bi-person-x"></i>
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
</div>

<!-- Add Employee Modal -->
<div class="modal fade" id="addEmployeeModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" action="">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="bi bi-person-plus me-2"></i>Add New Employee
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Employee ID <span class="text-danger">*</span></label>
                            <input type="text" name="employee_id" class="form-control" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Email <span class="text-danger">*</span></label>
                            <input type="email" name="email" class="form-control" required>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">First Name <span class="text-danger">*</span></label>
                            <input type="text" name="first_name" class="form-control" required>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Middle Name</label>
                            <input type="text" name="middle_name" class="form-control">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Last Name <span class="text-danger">*</span></label>
                            <input type="text" name="last_name" class="form-control" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Phone</label>
                            <input type="tel" name="phone" class="form-control">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">National ID</label>
                            <input type="text" name="national_id" class="form-control">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Department <span class="text-danger">*</span></label>
                            <select name="department_id" class="form-select" required id="addDepartment">
                                <option value="">Select Department</option>
                                <?php foreach ($departments as $dept): ?>
                                    <option value="<?php echo $dept['id']; ?>">
                                        <?php echo htmlspecialchars($dept['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Position <span class="text-danger">*</span></label>
                            <select name="position_id" class="form-select" required id="addPosition">
                                <option value="">Select Position</option>
                                <?php foreach ($positions as $pos): ?>
                                    <option value="<?php echo $pos['id']; ?>" data-department="<?php echo $pos['department_id']; ?>">
                                        <?php echo htmlspecialchars($pos['title']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Hire Date <span class="text-danger">*</span></label>
                            <input type="date" name="hire_date" class="form-control" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Basic Salary <span class="text-danger">*</span></label>
                            <input type="number" name="basic_salary" class="form-control" step="0.01" min="0" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Employment Type</label>
                            <select name="employment_type" class="form-select">
                                <option value="full_time">Full Time</option>
                                <option value="part_time">Part Time</option>
                                <option value="contract">Contract</option>
                                <option value="internship">Internship</option>
                            </select>
                        </div>
                        <div class="col-12 mb-3">
                            <label class="form-label">Address</label>
                            <textarea name="address" class="form-control" rows="3"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="add_employee" class="btn btn-primary">
                        <i class="bi bi-person-plus me-1"></i>Add Employee
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Employee Modal -->
<div class="modal fade" id="editEmployeeModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" action="" id="editEmployeeForm">
                <input type="hidden" name="employee_id" id="editEmployeeId">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="bi bi-pencil me-2"></i>Edit Employee
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Email <span class="text-danger">*</span></label>
                            <input type="email" name="email" class="form-control" id="editEmail" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Phone</label>
                            <input type="tel" name="phone" class="form-control" id="editPhone">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">First Name <span class="text-danger">*</span></label>
                            <input type="text" name="first_name" class="form-control" id="editFirstName" required>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Middle Name</label>
                            <input type="text" name="middle_name" class="form-control" id="editMiddleName">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Last Name <span class="text-danger">*</span></label>
                            <input type="text" name="last_name" class="form-control" id="editLastName" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Department <span class="text-danger">*</span></label>
                            <select name="department_id" class="form-select" required id="editDepartment">
                                <?php foreach ($departments as $dept): ?>
                                    <option value="<?php echo $dept['id']; ?>">
                                        <?php echo htmlspecialchars($dept['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Position <span class="text-danger">*</span></label>
                            <select name="position_id" class="form-select" required id="editPosition">
                                <?php foreach ($positions as $pos): ?>
                                    <option value="<?php echo $pos['id']; ?>" data-department="<?php echo $pos['department_id']; ?>">
                                        <?php echo htmlspecialchars($pos['title']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Basic Salary <span class="text-danger">*</span></label>
                            <input type="number" name="basic_salary" class="form-control" step="0.01" min="0" id="editSalary" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Employment Type</label>
                            <select name="employment_type" class="form-select" id="editEmploymentType">
                                <option value="full_time">Full Time</option>
                                <option value="part_time">Part Time</option>
                                <option value="contract">Contract</option>
                                <option value="internship">Internship</option>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Status</label>
                            <select name="status" class="form-select" id="editStatus">
                                <option value="active">Active</option>
                                <option value="suspended">Suspended</option>
                                <option value="on_leave">On Leave</option>
                            </select>
                        </div>
                        <div class="col-12 mb-3">
                            <label class="form-label">Address</label>
                            <textarea name="address" class="form-control" rows="3" id="editAddress"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="update_employee" class="btn btn-primary">
                        <i class="bi bi-check-lg me-1"></i>Update Employee
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Terminate Employee Modal -->
<div class="modal fade" id="terminateEmployeeModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="">
                <input type="hidden" name="employee_id" id="terminateEmployeeId">
                <div class="modal-header">
                    <h5 class="modal-title text-danger">
                        <i class="bi bi-person-x me-2"></i>Terminate Employee
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-warning">
                        <i class="bi bi-exclamation-triangle me-2"></i>
                        Are you sure you want to terminate <strong id="terminateEmployeeName"></strong>?
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Termination Date <span class="text-danger">*</span></label>
                        <input type="date" name="termination_date" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Reason for Termination <span class="text-danger">*</span></label>
                        <textarea name="termination_reason" class="form-control" rows="3" required 
                                  placeholder="Please provide a detailed reason for termination..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="terminate_employee" class="btn btn-danger">
                        <i class="bi bi-person-x me-1"></i>Terminate Employee
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Link User to Employee Modal -->
<div class="modal fade" id="linkUserModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" action="">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="bi bi-link-45deg me-2"></i>Link Existing User to Employee Record
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="text-muted mb-3">Convert a registered system user into an employee record with HR information.</p>
                    
                    <div class="mb-3">
                        <label class="form-label">Select User <span class="text-danger">*</span></label>
                        <select name="user_id" class="form-select" required id="linkUserSelect">
                            <option value="">Choose a user to link...</option>
                            <?php
                            try {
                                $stmt = $db->query("
                                    SELECT u.id, u.username, u.full_name, u.email, u.role
                                    FROM users u
                                    LEFT JOIN employees e ON u.id = e.user_id
                                    WHERE e.id IS NULL
                                    ORDER BY u.full_name ASC
                                ");
                                $unlinked_users = $stmt->fetchAll();
                                foreach ($unlinked_users as $user):
                                    ?>
                                    <option value="<?php echo $user['id']; ?>" 
                                            data-email="<?php echo htmlspecialchars($user['email']); ?>"
                                            data-role="<?php echo htmlspecialchars($user['role']); ?>">
                                        <?php echo htmlspecialchars($user['full_name']); ?> (<?php echo htmlspecialchars($user['username']); ?>)
                                    </option>
                                    <?php
                                endforeach;
                                if (empty($unlinked_users)) {
                                    echo '<option value="">All users are already linked as employees</option>';
                                }
                            } catch (Exception $e) {
                                echo '<option value="">Error loading users</option>';
                            }
                            ?>
                        </select>
                        <small class="form-text text-muted">Only users not yet linked to employee records are shown.</small>
                    </div>

                    <div id="userDetailInfo" style="display: none;" class="alert alert-info mb-3">
                        <p class="mb-1"><strong>Email:</strong> <span id="userDetailEmail"></span></p>
                        <p class="mb-0"><strong>Role:</strong> <span id="userDetailRole"></span></p>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Department <span class="text-danger">*</span></label>
                            <select name="department_id" class="form-select" required id="linkDepartment">
                                <option value="">Select Department</option>
                                <?php foreach ($departments as $dept): ?>
                                    <option value="<?php echo $dept['id']; ?>">
                                        <?php echo htmlspecialchars($dept['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Position <span class="text-danger">*</span></label>
                            <select name="position_id" class="form-select" required id="linkPosition">
                                <option value="">Select Position</option>
                                <?php foreach ($positions as $pos): ?>
                                    <option value="<?php echo $pos['id']; ?>" data-department="<?php echo $pos['department_id']; ?>">
                                        <?php echo htmlspecialchars($pos['title']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Hire Date <span class="text-danger">*</span></label>
                            <input type="date" name="hire_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Basic Salary <span class="text-danger">*</span></label>
                            <input type="number" name="basic_salary" class="form-control" step="0.01" min="0" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Employment Type <span class="text-danger">*</span></label>
                            <select name="employment_type" class="form-select" required>
                                <option value="full-time">Full-Time</option>
                                <option value="part-time">Part-Time</option>
                                <option value="contract">Contract</option>
                                <option value="temporary">Temporary</option>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Currency <span class="text-danger">*</span></label>
                            <select name="currency" class="form-select" required>
                                <option value="USD">USD</option>
                                <option value="KES">KES</option>
                                <option value="GBP">GBP</option>
                                <option value="EUR">EUR</option>
                            </select>
                        </div>
                    </div>

                    <div class="alert alert-primary" role="alert">
                        <i class="bi bi-info-circle me-2"></i>
                        The employee ID will be auto-generated from the user's username. The user will immediately have access to the HR portal and can request leave.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="link_user_to_employee" class="btn btn-info">
                        <i class="bi bi-link-45deg me-1"></i>Link User as Employee
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Show/hide user details when user is selected
document.getElementById('linkUserSelect').addEventListener('change', function() {
    if (this.value) {
        const selected = this.options[this.selectedIndex];
        document.getElementById('userDetailEmail').textContent = selected.dataset.email;
        document.getElementById('userDetailRole').textContent = selected.dataset.role;
        document.getElementById('userDetailInfo').style.display = 'block';
    } else {
        document.getElementById('userDetailInfo').style.display = 'none';
    }
});

// Filter positions by department for link user modal
document.getElementById('linkDepartment').addEventListener('change', function() {
    const departmentId = this.value;
    const positionSelect = document.getElementById('linkPosition');
    const options = positionSelect.querySelectorAll('option');
    
    options.forEach(option => {
        if (option.value === '') {
            option.style.display = 'block';
            return;
        }
        
        if (departmentId === '' || option.dataset.department === departmentId) {
            option.style.display = 'block';
        } else {
            option.style.display = 'none';
        }
    });
    
    positionSelect.value = '';
});

// Filter positions by department
document.getElementById('addDepartment').addEventListener('change', function() {
    const departmentId = this.value;
    const positionSelect = document.getElementById('addPosition');
    const options = positionSelect.querySelectorAll('option');
    
    options.forEach(option => {
        if (option.value === '') {
            option.style.display = 'block';
            return;
        }
        
        if (departmentId === '' || option.dataset.department === departmentId) {
            option.style.display = 'block';
        } else {
            option.style.display = 'none';
        }
    });
    
    positionSelect.value = '';
});

document.getElementById('addDepartment').addEventListener('change', function() {
    const departmentId = this.value;
    const positionSelect = document.getElementById('addPosition');
    const options = positionSelect.querySelectorAll('option');
    
    options.forEach(option => {
        if (option.value === '') {
            option.style.display = 'block';
            return;
        }
        
        if (departmentId === '' || option.dataset.department === departmentId) {
            option.style.display = 'block';
        } else {
            option.style.display = 'none';
        }
    });
    
    positionSelect.value = '';
});

// Edit employee function
function editEmployee(employee) {
    document.getElementById('editEmployeeId').value = employee.id;
    document.getElementById('editEmail').value = employee.email;
    document.getElementById('editPhone').value = employee.phone || '';
    document.getElementById('editFirstName').value = employee.first_name;
    document.getElementById('editMiddleName').value = employee.middle_name || '';
    document.getElementById('editLastName').value = employee.last_name;
    document.getElementById('editDepartment').value = employee.department_id;
    document.getElementById('editPosition').value = employee.position_id;
    document.getElementById('editSalary').value = employee.basic_salary;
    document.getElementById('editEmploymentType').value = employee.employment_type;
    document.getElementById('editStatus').value = employee.status;
    document.getElementById('editAddress').value = employee.address || '';
    
    new bootstrap.Modal(document.getElementById('editEmployeeModal')).show();
}

// Terminate employee function
function terminateEmployee(employeeId, employeeName) {
    document.getElementById('terminateEmployeeId').value = employeeId;
    document.getElementById('terminateEmployeeName').textContent = employeeName;
    
    new bootstrap.Modal(document.getElementById('terminateEmployeeModal')).show();
}

// Search functionality
document.getElementById('employeeSearch').addEventListener('keyup', function() {
    const searchTerm = this.value.toLowerCase();
    const table = document.getElementById('employeesTable');
    const rows = table.getElementsByTagName('tbody')[0].getElementsByTagName('tr');
    
    for (let i = 0; i < rows.length; i++) {
        const row = rows[i];
        const text = row.textContent.toLowerCase();
        row.style.display = text.includes(searchTerm) ? '' : 'none';
    }
});

// Department filter
document.getElementById('departmentFilter').addEventListener('change', function() {
    const filterValue = this.value.toLowerCase();
    const table = document.getElementById('employeesTable');
    const rows = table.getElementsByTagName('tbody')[0].getElementsByTagName('tr');
    
    for (let i = 0; i < rows.length; i++) {
        const row = rows[i];
        const departmentCell = row.cells[3];
        if (departmentCell) {
            const departmentText = departmentCell.textContent.toLowerCase();
            row.style.display = filterValue === '' || departmentText.includes(filterValue) ? '' : 'none';
        }
    }
});
</script>

<?php include '../includes/footer.php'; ?>