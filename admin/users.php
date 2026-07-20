<?php
require_once '../config/config.php';
require_once '../auth/auth_middleware.php';

require_admin();
require_ceo();

$db = getDBConnection();
$success_message = '';
$error_message = '';

// Handle actions
if (isset($_GET['action'])) {
    $action = $_GET['action'];
    $user_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    
    switch ($action) {
        case 'enable_mandate':
            if ($user_id > 0) {
                $stmt = $db->prepare("UPDATE users SET mandate_enabled = 1 WHERE id = ? AND role != 'system_admin'");
                if ($stmt->execute([$user_id])) {
                    show_alert('User mandate enabled successfully.', 'success');
                } else {
                    show_alert('Error enabling user mandate.', 'danger');
                }
            }
            break;
            
        case 'disable_mandate':
            if ($user_id > 0) {
                $stmt = $db->prepare("UPDATE users SET mandate_enabled = 0 WHERE id = ? AND role != 'system_admin'");
                if ($stmt->execute([$user_id])) {
                    show_alert('User mandate disabled successfully.', 'warning');
                } else {
                    show_alert('Error disabling user mandate.', 'danger');
                }
            }
            break;
            
        case 'deactivate':
            if ($user_id > 0) {
                $stmt = $db->prepare("UPDATE users SET is_active = 0 WHERE id = ? AND role != 'system_admin'");
                if ($stmt->execute([$user_id])) {
                    show_alert('User deactivated successfully.', 'warning');
                } else {
                    show_alert('Error deactivating user.', 'danger');
                }
            }
            break;
            
        case 'activate':
            if ($user_id > 0) {
                $stmt = $db->prepare("UPDATE users SET is_active = 1 WHERE id = ?");
                if ($stmt->execute([$user_id])) {
                    show_alert('User activated successfully.', 'success');
                } else {
                    show_alert('Error activating user.', 'danger');
                }
            }
            break;
    }
    
    redirect('admin/users.php');
}

// Handle new user creation
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['create_user'])) {
    $username = sanitize_input($_POST['username']);
    $email = sanitize_input($_POST['email']);
    $full_name = sanitize_input($_POST['full_name']);
    $role = sanitize_input($_POST['role']);
    $password = $_POST['password'];
    
    if (empty($username) || empty($email) || empty($full_name) || empty($role) || empty($password)) {
        $error_message = 'All fields are required.';
    } elseif (strlen($password) < 6) {
        $error_message = 'Password must be at least 6 characters long.';
    } else {
        // Check if username or email already exists
        $stmt = $db->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
        $stmt->execute([$username, $email]);
        if ($stmt->fetch()) {
            $error_message = 'Username or email already exists.';
        } else {
            // Create new user
            $password_hash = password_hash($password, PASSWORD_DEFAULT);
            $mandate_enabled = ($role == 'system_admin') ? 1 : 0;
            
            // Prepare employee data
            $name_parts = explode(' ', $full_name, 2);
            $first_name = $name_parts[0];
            $last_name = isset($name_parts[1]) ? $name_parts[1] : '';
            $employee_id = strtoupper(substr($username, 0, 3)) . '-' . rand(1000, 9999); // Generate employee ID
            
            $stmt = $db->prepare("
                INSERT INTO users (
                    username, email, password_hash, role, full_name, mandate_enabled,
                    employee_id, first_name, last_name, department_id, position_id, 
                    hire_date, employment_type
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, 1, CURDATE(), 'full_time')
            ");
            
            if ($stmt->execute([$username, $email, $password_hash, $role, $full_name, $mandate_enabled, $employee_id, $first_name, $last_name])) {
                show_alert('User created successfully.', 'success');
                redirect('admin/users.php');
            } else {
                $error_message = 'Error creating user. Please try again.';
            }
        }
    }
}

// Get all users
$stmt = $db->query("SELECT * FROM users ORDER BY created_at DESC");
$users = $stmt->fetchAll();

$page_title = 'User Management';
include '../includes/header.php';
?>

<div class="row">
    <div class="col-12">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2><i class="bi bi-people"></i> User Management</h2>
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createUserModal">
                <i class="bi bi-plus-lg"></i> Create New User
            </button>
        </div>
    </div>
</div>

<?php if (!empty($error_message)): ?>
    <div class="alert alert-danger">
        <i class="bi bi-exclamation-triangle"></i> <?php echo $error_message; ?>
    </div>
<?php endif; ?>

<div class="card">
    <div class="card-header">
        <div class="row align-items-center">
            <div class="col">
                <h6 class="mb-0">All Users</h6>
            </div>
            <div class="col-auto">
                <input type="text" class="form-control" id="userSearch" placeholder="Search users...">
            </div>
        </div>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover" id="usersTable">
                <thead>
                    <tr>
                        <th>User</th>
                        <th>Role</th>
                        <th>Status</th>
                        <th>Mandate</th>
                        <th>Created</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $user): ?>
                        <tr>
                            <td>
                                <div>
                                    <strong><?php echo htmlspecialchars($user['full_name']); ?></strong>
                                    <br>
                                    <small class="text-muted">
                                        <?php echo htmlspecialchars($user['username']); ?> | 
                                        <?php echo htmlspecialchars($user['email']); ?>
                                    </small>
                                </div>
                            </td>
                            <td>
                                <span class="badge bg-secondary">
                                    <?php echo ucwords(str_replace('_', ' ', $user['role'])); ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($user['is_active']): ?>
                                    <span class="badge bg-success">Active</span>
                                <?php else: ?>
                                    <span class="badge bg-danger">Inactive</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($user['role'] == 'system_admin'): ?>
                                    <span class="badge bg-primary">Always Enabled</span>
                                <?php elseif ($user['mandate_enabled']): ?>
                                    <span class="badge bg-success">Enabled</span>
                                <?php else: ?>
                                    <span class="badge bg-warning">Disabled</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo format_date($user['created_at']); ?></td>
                            <td>
                                <?php if ($user['role'] != 'system_admin'): ?>
                                    <div class="btn-group btn-group-sm">
                                        <?php if ($user['mandate_enabled']): ?>
                                            <a href="?action=disable_mandate&id=<?php echo $user['id']; ?>" 
                                               class="btn btn-outline-warning" 
                                               onclick="return confirm('Disable mandate for this user?')"
                                               title="Disable Mandate">
                                                <i class="bi bi-x-lg"></i>
                                            </a>
                                        <?php else: ?>
                                            <a href="?action=enable_mandate&id=<?php echo $user['id']; ?>" 
                                               class="btn btn-outline-success" 
                                               onclick="return confirm('Enable mandate for this user?')"
                                               title="Enable Mandate">
                                                <i class="bi bi-check-lg"></i>
                                            </a>
                                        <?php endif; ?>
                                        
                                        <?php if ($user['is_active']): ?>
                                            <a href="?action=deactivate&id=<?php echo $user['id']; ?>" 
                                               class="btn btn-outline-danger" 
                                               onclick="return confirm('Deactivate this user?')"
                                               title="Deactivate User">
                                                <i class="bi bi-person-x"></i>
                                            </a>
                                        <?php else: ?>
                                            <a href="?action=activate&id=<?php echo $user['id']; ?>" 
                                               class="btn btn-outline-primary" 
                                               onclick="return confirm('Activate this user?')"
                                               title="Activate User">
                                                <i class="bi bi-person-check"></i>
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                <?php else: ?>
                                    <span class="text-muted">System Admin</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Create User Modal -->
<div class="modal fade" id="createUserModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="">
                <div class="modal-header">
                    <h5 class="modal-title">Create New User</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="username" class="form-label">Username</label>
                        <input type="text" class="form-control" id="username" name="username" required>
                    </div>
                    
                    <div class="mb-3">
                        <label for="email" class="form-label">Email</label>
                        <input type="email" class="form-control" id="email" name="email" required>
                    </div>
                    
                    <div class="mb-3">
                        <label for="full_name" class="form-label">Full Name</label>
                        <input type="text" class="form-control" id="full_name" name="full_name" required>
                    </div>
                    
                    <div class="mb-3">
                        <label for="role" class="form-label">Role</label>
                        <select class="form-select" id="role" name="role" required>
                            <option value="">Select Role</option>
                            <option value="trader">Trader</option>
                            <option value="ceo">CEO</option>
                            <option value="finance_officer">Finance Officer</option>
                            <option value="hr_manager">HR Manager</option>
                            <option value="hr_officer">HR Officer</option>
                            <option value="system_admin">System Admin</option>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label for="password" class="form-label">Password</label>
                        <input type="password" class="form-control" id="password" name="password" required>
                        <div class="form-text">Minimum 6 characters</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="create_user" class="btn btn-primary">Create User</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Initialize table search
document.addEventListener('DOMContentLoaded', function() {
    initTableSearch('usersTable', 'userSearch');
});
</script>

<?php include '../includes/footer.php'; ?>
