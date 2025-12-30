<?php
require_once 'config/config.php';
require_once 'auth/auth_middleware.php';

require_login();

$user = get_logged_in_user();
$success_message = '';
$error_message = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $full_name = sanitize_input($_POST['full_name']);
    $email = sanitize_input($_POST['email']);
    $current_password = $_POST['current_password'];
    $new_password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];
    
    $db = getDBConnection();
    
    // Validate inputs
    if (empty($full_name) || empty($email)) {
        $error_message = 'Full name and email are required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error_message = 'Please enter a valid email address.';
    } else {
        // Check if email is already taken by another user
        $stmt = $db->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
        $stmt->execute([$email, $user['id']]);
        if ($stmt->fetch()) {
            $error_message = 'Email address is already in use.';
        } else {
            // Update profile
            $update_query = "UPDATE users SET full_name = ?, email = ? WHERE id = ?";
            $update_params = [$full_name, $email, $user['id']];
            
            // If password change is requested
            if (!empty($current_password) || !empty($new_password)) {
                if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
                    $error_message = 'All password fields are required to change password.';
                } elseif ($new_password !== $confirm_password) {
                    $error_message = 'New passwords do not match.';
                } elseif (strlen($new_password) < 6) {
                    $error_message = 'New password must be at least 6 characters long.';
                } elseif (!password_verify($current_password, $user['password_hash'])) {
                    $error_message = 'Current password is incorrect.';
                } else {
                    // Include password in update
                    $update_query = "UPDATE users SET full_name = ?, email = ?, password_hash = ? WHERE id = ?";
                    $update_params = [$full_name, $email, password_hash($new_password, PASSWORD_DEFAULT), $user['id']];
                }
            }
            
            if (empty($error_message)) {
                $stmt = $db->prepare($update_query);
                if ($stmt->execute($update_params)) {
                    $success_message = 'Profile updated successfully.';
                    // Update session data
                    $_SESSION['full_name'] = $full_name;
                    // Refresh user data
                    $user = get_logged_in_user();
                } else {
                    $error_message = 'Error updating profile. Please try again.';
                }
            }
        }
    }
}

$page_title = 'Profile';
include 'includes/header.php';
?>

<div class="row justify-content-center">
    <div class="col-md-8 col-lg-6">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">
                    <i class="bi bi-person-circle"></i> My Profile
                </h5>
            </div>
            <div class="card-body">
                <?php if (!empty($success_message)): ?>
                    <div class="alert alert-success">
                        <i class="bi bi-check-circle"></i> <?php echo $success_message; ?>
                    </div>
                <?php endif; ?>
                
                <?php if (!empty($error_message)): ?>
                    <div class="alert alert-danger">
                        <i class="bi bi-exclamation-triangle"></i> <?php echo $error_message; ?>
                    </div>
                <?php endif; ?>
                
                <form method="POST" action="">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="username" class="form-label">Username</label>
                                <input type="text" class="form-control" id="username" 
                                       value="<?php echo htmlspecialchars($user['username']); ?>" readonly>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="role" class="form-label">Role</label>
                                <input type="text" class="form-control" id="role" 
                                       value="<?php echo ucwords(str_replace('_', ' ', $user['role'])); ?>" readonly>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="full_name" class="form-label">Full Name</label>
                        <input type="text" class="form-control" id="full_name" name="full_name" 
                               value="<?php echo htmlspecialchars($user['full_name']); ?>" required>
                    </div>
                    
                    <div class="mb-3">
                        <label for="email" class="form-label">Email Address</label>
                        <input type="email" class="form-control" id="email" name="email" 
                               value="<?php echo htmlspecialchars($user['email']); ?>" required>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Mandate Status</label>
                        <div class="form-control-plaintext">
                            <?php if ($user['mandate_enabled']): ?>
                                <span class="badge bg-success">Enabled</span>
                            <?php else: ?>
                                <span class="badge bg-warning">Disabled</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <hr>
                    <h6>Change Password (Optional)</h6>
                    
                    <div class="mb-3">
                        <label for="current_password" class="form-label">Current Password</label>
                        <input type="password" class="form-control" id="current_password" name="current_password">
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="new_password" class="form-label">New Password</label>
                                <input type="password" class="form-control" id="new_password" name="new_password">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="confirm_password" class="form-label">Confirm New Password</label>
                                <input type="password" class="form-control" id="confirm_password" name="confirm_password">
                            </div>
                        </div>
                    </div>
                    
                    <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                        <a href="<?php echo BASE_URL; ?>" class="btn btn-secondary">Cancel</a>
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-check-lg"></i> Update Profile
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Performance Targets Section -->
        <div class="card shadow-sm">
            <div class="card-header bg-light">
                <h5 class="mb-0">
                    <i class="bi bi-bullseye me-2 text-primary"></i>Your Performance Targets
                </h5>
            </div>
            <div class="card-body">
                <?php 
                try {
                    // Check if user is an employee by looking for employee record
                    $db = getDBConnection();
                    $emp_stmt = $db->prepare("
                        SELECT e.id as employee_id 
                        FROM employees e 
                        WHERE e.user_id = ? 
                        LIMIT 1
                    ");
                    $emp_stmt->execute([$user['id']]);
                    $employee = $emp_stmt->fetch();
                    
                    if ($employee) {
                        // Get all targets for this employee
                        $targets_stmt = $db->prepare("
                            SELECT 
                                pt.id,
                                pt.title,
                                pt.description,
                                pt.target_type,
                                pt.target_value,
                                pt.unit,
                                pt.current_value,
                                pt.progress_percentage,
                                pt.start_date,
                                pt.end_date,
                                pt.priority,
                                pt.status,
                                u.full_name as created_by_name
                            FROM performance_targets pt
                            LEFT JOIN users u ON pt.created_by = u.id
                            WHERE pt.employee_id = ?
                            ORDER BY pt.status = 'active' DESC, pt.priority = 'high' DESC, pt.end_date ASC
                        ");
                        $targets_stmt->execute([$employee['employee_id']]);
                        $targets = $targets_stmt->fetchAll();
                        
                        if (empty($targets)) {
                            echo '<div class="text-center py-5">';
                            echo '<i class="bi bi-bullseye display-4 text-muted mb-3"></i>';
                            echo '<p class="text-muted">No performance targets have been set for you yet.</p>';
                            echo '</div>';
                        } else {
                            // Organize targets by status
                            $active_targets = array_filter($targets, fn($t) => $t['status'] === 'active');
                            $completed_targets = array_filter($targets, fn($t) => $t['status'] === 'completed');
                            
                            if (!empty($active_targets)) {
                                echo '<div class="mb-4">';
                                echo '<h6 class="text-success mb-3"><i class="bi bi-circle-fill me-2"></i>Active Targets</h6>';
                                echo '<div class="row">';
                                
                                foreach ($active_targets as $target) {
                                    $progress = $target['progress_percentage'] ?? 0;
                                    $priority_color = $target['priority'] === 'high' ? 'danger' : ($target['priority'] === 'medium' ? 'warning' : 'info');
                                    $daysRemaining = (strtotime($target['end_date']) - time()) / 86400;
                                    $isOverdue = $daysRemaining < 0;
                                    
                                    echo '<div class="col-md-6 mb-3">';
                                    echo '<div class="card border-left-success h-100">';
                                    echo '<div class="card-body">';
                                    echo '<div class="d-flex justify-content-between align-items-start mb-2">';
                                    echo '<h6 class="mb-0">' . htmlspecialchars($target['title']) . '</h6>';
                                    echo '<span class="badge bg-' . $priority_color . '">' . ucfirst($target['priority']) . '</span>';
                                    echo '</div>';
                                    
                                    if ($target['description']) {
                                        echo '<small class="text-muted d-block mb-2">' . htmlspecialchars($target['description']) . '</small>';
                                    }
                                    
                                    echo '<div class="mb-2">';
                                    echo '<div class="d-flex justify-content-between align-items-center mb-1">';
                                    echo '<small class="text-muted">Progress</small>';
                                    echo '<small class="fw-bold">' . round($progress) . '%</small>';
                                    echo '</div>';
                                    echo '<div class="progress" style="height: 6px;">';
                                    echo '<div class="progress-bar bg-success" role="progressbar" style="width: ' . $progress . '%" aria-valuenow="' . $progress . '" aria-valuemin="0" aria-valuemax="100"></div>';
                                    echo '</div>';
                                    echo '</div>';
                                    
                                    echo '<div class="row text-center text-muted small">';
                                    echo '<div class="col-6">';
                                    echo '<div>Current: <strong>' . number_format($target['current_value'] ?? 0, 2) . ' ' . htmlspecialchars($target['unit']) . '</strong></div>';
                                    echo '</div>';
                                    echo '<div class="col-6">';
                                    echo '<div>Target: <strong>' . number_format($target['target_value'], 2) . ' ' . htmlspecialchars($target['unit']) . '</strong></div>';
                                    echo '</div>';
                                    echo '</div>';
                                    
                                    echo '<div class="mt-2 pt-2 border-top small">';
                                    if ($isOverdue) {
                                        echo '<i class="bi bi-exclamation-circle text-danger me-1"></i>';
                                        echo '<span class="text-danger fw-bold">Overdue by ' . abs(intval($daysRemaining)) . ' day' . (abs(intval($daysRemaining)) !== 1 ? 's' : '') . '</span>';
                                    } else {
                                        echo '<i class="bi bi-calendar me-1 text-info"></i>';
                                        echo '<span>' . intval($daysRemaining) . ' day' . (intval($daysRemaining) !== 1 ? 's' : '') . ' remaining</span>';
                                    }
                                    echo '</div>';
                                    
                                    echo '<div class="text-muted small mt-2">';
                                    echo '<small>Set by: ' . htmlspecialchars($target['created_by_name']) . '</small>';
                                    echo '</div>';
                                    echo '</div>';
                                    echo '</div>';
                                    echo '</div>';
                                }
                                
                                echo '</div>';
                                echo '</div>';
                            }
                            
                            if (!empty($completed_targets)) {
                                echo '<div class="mb-4">';
                                echo '<h6 class="text-muted mb-3"><i class="bi bi-check-circle me-2 text-success"></i>Completed Targets</h6>';
                                echo '<div class="row">';
                                
                                foreach ($completed_targets as $target) {
                                    echo '<div class="col-md-6 mb-3">';
                                    echo '<div class="card border-left-success h-100 opacity-75">';
                                    echo '<div class="card-body">';
                                    echo '<div class="d-flex justify-content-between align-items-start mb-2">';
                                    echo '<h6 class="mb-0"><i class="bi bi-check-circle text-success me-2"></i>' . htmlspecialchars($target['title']) . '</h6>';
                                    echo '</div>';
                                    
                                    echo '<div class="progress mb-2" style="height: 6px;">';
                                    echo '<div class="progress-bar bg-success" role="progressbar" style="width: 100%" aria-valuenow="100" aria-valuemin="0" aria-valuemax="100"></div>';
                                    echo '</div>';
                                    
                                    echo '<div class="text-center text-muted small">';
                                    echo '<div>Achieved: <strong>' . number_format($target['current_value'] ?? 0, 2) . ' ' . htmlspecialchars($target['unit']) . '</strong></div>';
                                    echo '</div>';
                                    echo '</div>';
                                    echo '</div>';
                                    echo '</div>';
                                }
                                
                                echo '</div>';
                                echo '</div>';
                            }
                        }
                    } else {
                        echo '<div class="text-center py-5">';
                        echo '<p class="text-muted">Performance targets are only visible for employees.</p>';
                        echo '</div>';
                    }
                } catch (Exception $e) {
                    echo '<div class="alert alert-warning">';
                    echo '<i class="bi bi-exclamation-triangle me-2"></i>Unable to load performance targets.';
                    echo '</div>';
                    error_log("Error loading targets in profile: " . $e->getMessage());
                }
                ?>
            </div>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
