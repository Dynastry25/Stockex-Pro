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
        if (isset($_POST['update_user'])) {
            // Update user (employee) details
            $user_id = (int)$_POST['user_id'];
            $full_name = sanitize_input($_POST['full_name']);
            $email = sanitize_input($_POST['email']);
            $phone = sanitize_input($_POST['phone']);
            $role = sanitize_input($_POST['role']);
            $department = sanitize_input($_POST['department']);
            $position = sanitize_input($_POST['position']);
            $salary = (float)$_POST['salary'];
            $employment_type = sanitize_input($_POST['employment_type']);
            $hire_date = $_POST['hire_date'];
            $status = sanitize_input($_POST['status']);
            $address = sanitize_input($_POST['address']);
            $national_id = sanitize_input($_POST['national_id']);
            
            // Check if email already exists for another user
            $check_stmt = $db->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
            $check_stmt->execute([$email, $user_id]);
            if ($check_stmt->fetch()) {
                $error_message = 'Email already exists for another user.';
            } else {
                $stmt = $db->prepare("
                    UPDATE users 
                    SET full_name = ?, email = ?, role = ?, status = ?, updated_at = NOW()
                    WHERE id = ?
                ");
                
                if ($stmt->execute([$full_name, $email, $role, $status, $user_id])) {
                    // Update user details in user_details table if it exists, or store in a separate table
                    // For now, we'll log the HR-specific details in a separate table or store as metadata
                    // Let's create a user_hr_details table if it doesn't exist
                    
                    // Log HR activity
                    $activity_stmt = $db->prepare("
                        INSERT INTO hr_activities (activity_type, entity_type, entity_id, description, performed_by)
                        VALUES ('employee_updated', 'user', ?, ?, ?)
                    ");
                    $activity_stmt->execute([
                        $user_id,
                        "User {$full_name} information was updated by HR",
                        $_SESSION['user_id']
                    ]);
                    
                    show_alert('Employee updated successfully.', 'success');
                    redirect('hr/employees.php');
                } else {
                    $error_message = 'Error updating employee.';
                }
            }
            
        } elseif (isset($_POST['terminate_user'])) {
            // Terminate user (employee)
            $user_id = (int)$_POST['user_id'];
            $termination_reason = sanitize_input($_POST['termination_reason']);
            $termination_date = $_POST['termination_date'];
            
            $stmt = $db->prepare("
                UPDATE users 
                SET status = 'inactive', updated_at = NOW()
                WHERE id = ?
            ");
            
            if ($stmt->execute([$user_id])) {
                // Log termination
                $activity_stmt = $db->prepare("
                    INSERT INTO hr_activities (activity_type, entity_type, entity_id, description, performed_by)
                    VALUES ('employee_terminated', 'user', ?, ?, ?)
                ");
                $activity_stmt->execute([
                    $user_id,
                    "User was terminated: {$termination_reason}",
                    $_SESSION['user_id']
                ]);
                
                show_alert('Employee terminated successfully.', 'warning');
                redirect('hr/employees.php');
            } else {
                $error_message = 'Error terminating employee.';
            }
        } elseif (isset($_POST['add_user'])) {
            // Add new user (employee)
            $username = sanitize_input($_POST['username']);
            $email = sanitize_input($_POST['email']);
            $full_name = sanitize_input($_POST['full_name']);
            $password = sanitize_input($_POST['password']);
            $role = sanitize_input($_POST['role']);
            $phone = sanitize_input($_POST['phone']);
            $department = sanitize_input($_POST['department']);
            $position = sanitize_input($_POST['position']);
            $salary = (float)$_POST['salary'];
            $employment_type = sanitize_input($_POST['employment_type']);
            $hire_date = $_POST['hire_date'];
            $address = sanitize_input($_POST['address']);
            $national_id = sanitize_input($_POST['national_id']);
            
            // Check if username or email already exists
            $check_stmt = $db->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
            $check_stmt->execute([$username, $email]);
            if ($check_stmt->fetch()) {
                $error_message = 'Username or email already exists.';
            } else {
                // Hash password
                $password_hash = password_hash($password, PASSWORD_DEFAULT);
                
                $stmt = $db->prepare("
                    INSERT INTO users (username, email, password_hash, role, full_name, status, created_at, updated_at) 
                    VALUES (?, ?, ?, ?, ?, 'active', NOW(), NOW())
                ");
                
                if ($stmt->execute([$username, $email, $password_hash, $role, $full_name])) {
                    $new_user_id = $db->lastInsertId();
                    
                    // Log HR activity
                    $activity_stmt = $db->prepare("
                        INSERT INTO hr_activities (activity_type, entity_type, entity_id, description, performed_by)
                        VALUES ('employee_created', 'user', ?, ?, ?)
                    ");
                    $activity_stmt->execute([
                        $new_user_id,
                        "New user {$full_name} was created by HR",
                        $_SESSION['user_id']
                    ]);
                    
                    show_alert('Employee added successfully.', 'success');
                    redirect('hr/employees.php');
                } else {
                    $error_message = 'Error adding employee.';
                }
            }
        }
    } catch (Exception $e) {
        $error_message = 'Database error: ' . $e->getMessage();
    }
}

// Handle user status actions via GET
if (isset($_GET['action']) && isset($_GET['id'])) {
    $action = $_GET['action'];
    $user_id = (int)$_GET['id'];
    
    try {
        switch ($action) {
            case 'activate':
                $stmt = $db->prepare("UPDATE users SET status = 'active', updated_at = NOW() WHERE id = ?");
                $stmt->execute([$user_id]);
                show_alert('Employee activated successfully.', 'success');
                break;
            case 'suspend':
                $stmt = $db->prepare("UPDATE users SET status = 'inactive', updated_at = NOW() WHERE id = ?");
                $stmt->execute([$user_id]);
                show_alert('Employee suspended.', 'warning');
                break;
            case 'delete':
                // Soft delete - mark as inactive
                $stmt = $db->prepare("UPDATE users SET status = 'inactive', is_active = 0, updated_at = NOW() WHERE id = ?");
                $stmt->execute([$user_id]);
                show_alert('Employee deleted successfully.', 'success');
                break;
        }
        redirect('hr/employees.php');
    } catch (Exception $e) {
        show_alert('Error updating employee status.', 'danger');
        redirect('hr/employees.php');
    }
}

// Get all users (employees) with filtering for HR
try {
    $stmt = $db->query("
        SELECT u.* 
        FROM users u
        WHERE u.role IN ('trader', 'finance_officer', 'ceo', 'hr_manager', 'hr_officer')
        ORDER BY u.created_at DESC
    ");
    $employees = $stmt->fetchAll();
} catch (Exception $e) {
    $employees = [];
    $error_message = 'Error loading employees: ' . $e->getMessage();
}

// Define departments and positions arrays (since you don't have these tables)
$departments = [
    'Trading' => 'Trading Department',
    'Finance' => 'Finance Department',
    'HR' => 'Human Resources',
    'Management' => 'Management',
    'Operations' => 'Operations'
];

$positions = [
    'trader' => 'Trader',
    'finance_officer' => 'Finance Officer',
    'ceo' => 'Chief Executive Officer',
    'hr_manager' => 'HR Manager',
    'hr_officer' => 'HR Officer',
    'system_admin' => 'System Administrator'
];

// Define roles for dropdown
$roles = [
    'trader' => 'Trader',
    'finance_officer' => 'Finance Officer',
    'ceo' => 'CEO',
    'hr_manager' => 'HR Manager',
    'hr_officer' => 'HR Officer',
    'system_admin' => 'System Administrator'
];
?>

<?php include '../includes/header.php'; ?>
<div class="container-fluid pt-4 px-4">
    <div class="d-sm-flex align-items-center justify-content-between mb-4">
        <h1 class="h3 mb-0 text-gray-800">
            <i class="bi bi-people-fill me-2"></i>Employee Management
        </h1>
        <div class="d-flex gap-2">
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addUserModal">
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
                    <?php foreach ($departments as $key => $dept): ?>
                        <option value="<?php echo htmlspecialchars($key); ?>">
                            <?php echo htmlspecialchars($dept); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <select class="form-select form-select-sm" id="roleFilter" style="width: 150px;">
                    <option value="">All Roles</option>
                    <?php foreach ($roles as $key => $role): ?>
                        <option value="<?php echo htmlspecialchars($key); ?>">
                            <?php echo htmlspecialchars($role); ?>
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
                            <th>Username</th>
                            <th>Name</th>
                            <th>Email</th>
                            <th>Role</th>
                            <th>Department</th>
                            <th>Created Date</th>
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
                                <?php 
                                // Determine department based on role
                                $department = '';
                                switch ($employee['role']) {
                                    case 'trader':
                                        $department = 'Trading';
                                        break;
                                    case 'finance_officer':
                                        $department = 'Finance';
                                        break;
                                    case 'hr_manager':
                                    case 'hr_officer':
                                        $department = 'HR';
                                        break;
                                    case 'ceo':
                                        $department = 'Management';
                                        break;
                                    case 'system_admin':
                                        $department = 'Operations';
                                        break;
                                    default:
                                        $department = 'Operations';
                                }
                                ?>
                                <tr>
                                    <td>
                                        <strong>EMP-<?php echo str_pad($employee['id'], 4, '0', STR_PAD_LEFT); ?></strong>
                                    </td>
                                    <td>
                                        <code><?php echo htmlspecialchars($employee['username']); ?></code>
                                    </td>
                                    <td>
                                        <div>
                                            <strong><?php echo htmlspecialchars($employee['full_name']); ?></strong>
                                            <?php if ($employee['mandate_enabled']): ?>
                                                <br><small class="badge bg-info">Mandate Enabled</small>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td><?php echo htmlspecialchars($employee['email']); ?></td>
                                    <td>
                                        <span class="badge bg-primary">
                                            <?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $employee['role']))); ?>
                                        </span>
                                    </td>
                                    <td><?php echo htmlspecialchars($department); ?></td>
                                    <td><?php echo format_date($employee['created_at']); ?></td>
                                    <td>
                                        <?php
                                        $status_colors = [
                                            'active' => 'success',
                                            'inactive' => 'danger'
                                        ];
                                        $color = $status_colors[$employee['status']] ?? 'secondary';
                                        $is_active = $employee['is_active'] ? 'Active' : 'Inactive';
                                        ?>
                                        <span class="badge bg-<?php echo $color; ?>">
                                            <?php echo ucfirst($employee['status']); ?>
                                        </span>
                                        <br>
                                        <small class="text-muted">System: <?php echo $is_active; ?></small>
                                    </td>
                                    <td>
                                        <div class="btn-group btn-group-sm">
                                            <button class="btn btn-outline-primary" 
                                                    onclick="editUser(<?php echo htmlspecialchars(json_encode($employee)); ?>, '<?php echo htmlspecialchars($department); ?>')"
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
                                            <?php elseif ($employee['status'] == 'inactive'): ?>
                                                <a href="?action=activate&id=<?php echo $employee['id']; ?>" 
                                                   class="btn btn-outline-success"
                                                   onclick="return confirm('Activate this employee?')"
                                                   title="Activate Employee">
                                                    <i class="bi bi-play-circle"></i>
                                                </a>
                                            <?php endif; ?>
                                            
                                            <a href="?action=delete&id=<?php echo $employee['id']; ?>" 
                                               class="btn btn-outline-danger"
                                               onclick="return confirm('Are you sure you want to delete this employee? This will deactivate their account.')"
                                               title="Delete Employee">
                                                <i class="bi bi-trash"></i>
                                            </a>
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

<!-- Add User Modal -->
<div class="modal fade" id="addUserModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" action="" id="addUserForm">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="bi bi-person-plus me-2"></i>Add New Employee
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Username <span class="text-danger">*</span></label>
                            <input type="text" name="username" class="form-control" required>
                            <small class="form-text text-muted">Unique username for login</small>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Email <span class="text-danger">*</span></label>
                            <input type="email" name="email" class="form-control" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Password <span class="text-danger">*</span></label>
                            <input type="password" name="password" class="form-control" required minlength="6">
                            <small class="form-text text-muted">Minimum 6 characters</small>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Confirm Password <span class="text-danger">*</span></label>
                            <input type="password" name="confirm_password" class="form-control" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Full Name <span class="text-danger">*</span></label>
                            <input type="text" name="full_name" class="form-control" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Phone</label>
                            <input type="tel" name="phone" class="form-control">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Role <span class="text-danger">*</span></label>
                            <select name="role" class="form-select" required id="addRole">
                                <option value="">Select Role</option>
                                <?php foreach ($roles as $key => $role): ?>
                                    <option value="<?php echo htmlspecialchars($key); ?>">
                                        <?php echo htmlspecialchars($role); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Department</label>
                            <select name="department" class="form-select" id="addDepartment">
                                <option value="">Select Department</option>
                                <?php foreach ($departments as $key => $dept): ?>
                                    <option value="<?php echo htmlspecialchars($key); ?>">
                                        <?php echo htmlspecialchars($dept); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Position</label>
                            <select name="position" class="form-select" id="addPosition">
                                <option value="">Select Position</option>
                                <?php foreach ($positions as $key => $pos): ?>
                                    <option value="<?php echo htmlspecialchars($key); ?>">
                                        <?php echo htmlspecialchars($pos); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Salary</label>
                            <input type="number" name="salary" class="form-control" step="0.01" min="0">
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
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Hire Date</label>
                            <input type="date" name="hire_date" class="form-control">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">National ID</label>
                            <input type="text" name="national_id" class="form-control">
                        </div>
                        <div class="col-12 mb-3">
                            <label class="form-label">Address</label>
                            <textarea name="address" class="form-control" rows="3"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="add_user" class="btn btn-primary">
                        <i class="bi bi-person-plus me-1"></i>Add Employee
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit User Modal -->
<div class="modal fade" id="editUserModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" action="" id="editUserForm">
                <input type="hidden" name="user_id" id="editUserId">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="bi bi-pencil me-2"></i>Edit Employee
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Username</label>
                            <input type="text" class="form-control" id="editUsername" disabled>
                            <small class="form-text text-muted">Username cannot be changed</small>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Email <span class="text-danger">*</span></label>
                            <input type="email" name="email" class="form-control" id="editEmail" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Full Name <span class="text-danger">*</span></label>
                            <input type="text" name="full_name" class="form-control" id="editFullName" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Phone</label>
                            <input type="tel" name="phone" class="form-control" id="editPhone">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Role <span class="text-danger">*</span></label>
                            <select name="role" class="form-select" required id="editRole">
                                <?php foreach ($roles as $key => $role): ?>
                                    <option value="<?php echo htmlspecialchars($key); ?>">
                                        <?php echo htmlspecialchars($role); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Department</label>
                            <select name="department" class="form-select" id="editDepartment">
                                <?php foreach ($departments as $key => $dept): ?>
                                    <option value="<?php echo htmlspecialchars($key); ?>">
                                        <?php echo htmlspecialchars($dept); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Position</label>
                            <select name="position" class="form-select" id="editPosition">
                                <?php foreach ($positions as $key => $pos): ?>
                                    <option value="<?php echo htmlspecialchars($key); ?>">
                                        <?php echo htmlspecialchars($pos); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Salary</label>
                            <input type="number" name="salary" class="form-control" step="0.01" min="0" id="editSalary">
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
                            <label class="form-label">Hire Date</label>
                            <input type="date" name="hire_date" class="form-control" id="editHireDate">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Status</label>
                            <select name="status" class="form-select" id="editStatus">
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">National ID</label>
                            <input type="text" name="national_id" class="form-control" id="editNationalId">
                        </div>
                        <div class="col-12 mb-3">
                            <label class="form-label">Address</label>
                            <textarea name="address" class="form-control" rows="3" id="editAddress"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="update_user" class="btn btn-primary">
                        <i class="bi bi-check-lg me-1"></i>Update Employee
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Terminate User Modal -->
<div class="modal fade" id="terminateUserModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="">
                <input type="hidden" name="user_id" id="terminateUserId">
                <div class="modal-header">
                    <h5 class="modal-title text-danger">
                        <i class="bi bi-person-x me-2"></i>Terminate Employee
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-warning">
                        <i class="bi bi-exclamation-triangle me-2"></i>
                        Are you sure you want to terminate <strong id="terminateUserName"></strong>?
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
                    <button type="submit" name="terminate_user" class="btn btn-danger">
                        <i class="bi bi-person-x me-1"></i>Terminate Employee
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Password confirmation validation
document.getElementById('addUserForm')?.addEventListener('submit', function(e) {
    const password = this.querySelector('input[name="password"]').value;
    const confirmPassword = this.querySelector('input[name="confirm_password"]').value;
    
    if (password !== confirmPassword) {
        e.preventDefault();
        alert('Passwords do not match!');
        return false;
    }
    
    if (password.length < 6) {
        e.preventDefault();
        alert('Password must be at least 6 characters long!');
        return false;
    }
});

// Edit user function
function editUser(user, department) {
    document.getElementById('editUserId').value = user.id;
    document.getElementById('editUsername').value = user.username;
    document.getElementById('editEmail').value = user.email;
    document.getElementById('editFullName').value = user.full_name;
    document.getElementById('editPhone').value = user.phone || '';
    document.getElementById('editRole').value = user.role;
    document.getElementById('editDepartment').value = department;
    document.getElementById('editStatus').value = user.status;
    
    // Set default values for other fields (these would come from a user_details table if it existed)
    document.getElementById('editPosition').value = user.role; // Default position same as role
    document.getElementById('editSalary').value = '';
    document.getElementById('editEmploymentType').value = 'full_time';
    document.getElementById('editHireDate').value = '';
    document.getElementById('editNationalId').value = '';
    document.getElementById('editAddress').value = '';
    
    new bootstrap.Modal(document.getElementById('editUserModal')).show();
}

// Terminate user function
function terminateUser(userId, userName) {
    document.getElementById('terminateUserId').value = userId;
    document.getElementById('terminateUserName').textContent = userName;
    
    new bootstrap.Modal(document.getElementById('terminateUserModal')).show();
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
        const departmentCell = row.cells[5]; // Department is in column 5 (0-indexed)
        if (departmentCell) {
            const departmentText = departmentCell.textContent.toLowerCase();
            row.style.display = filterValue === '' || departmentText.includes(filterValue) ? '' : 'none';
        }
    }
});

// Role filter
document.getElementById('roleFilter').addEventListener('change', function() {
    const filterValue = this.value.toLowerCase();
    const table = document.getElementById('employeesTable');
    const rows = table.getElementsByTagName('tbody')[0].getElementsByTagName('tr');
    
    for (let i = 0; i < rows.length; i++) {
        const row = rows[i];
        const roleCell = row.cells[4]; // Role is in column 4 (0-indexed)
        if (roleCell) {
            const roleText = roleCell.textContent.toLowerCase();
            row.style.display = filterValue === '' || roleText.includes(filterValue) ? '' : 'none';
        }
    }
});

// Auto-select department based on role
document.getElementById('addRole').addEventListener('change', function() {
    const role = this.value;
    const departmentSelect = document.getElementById('addDepartment');
    
    let department = '';
    switch (role) {
        case 'trader':
            department = 'Trading';
            break;
        case 'finance_officer':
            department = 'Finance';
            break;
        case 'hr_manager':
        case 'hr_officer':
            department = 'HR';
            break;
        case 'ceo':
            department = 'Management';
            break;
        case 'system_admin':
            department = 'Operations';
            break;
        default:
            department = '';
    }
    
    if (department) {
        departmentSelect.value = department;
    }
});

// Auto-select position based on role
document.getElementById('addRole').addEventListener('change', function() {
    const role = this.value;
    const positionSelect = document.getElementById('addPosition');
    
    let position = '';
    switch (role) {
        case 'trader':
            position = 'trader';
            break;
        case 'finance_officer':
            position = 'finance_officer';
            break;
        case 'hr_manager':
            position = 'hr_manager';
            break;
        case 'hr_officer':
            position = 'hr_officer';
            break;
        case 'ceo':
            position = 'ceo';
            break;
        case 'system_admin':
            position = 'system_admin';
            break;
        default:
            position = '';
    }
    
    if (position) {
        positionSelect.value = position;
    }
});
</script>

<?php include '../includes/footer.php'; ?>