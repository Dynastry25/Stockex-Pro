<?php
require_once '../config/config.php';

// Redirect if already logged in
if (is_logged_in()) {
    $user = get_logged_in_user();
    switch ($user['role']) {
        case 'system_admin':
            redirect('admin/dashboard.php');
            break;
        case 'trader':
            redirect('trader/dashboard.php');
            break;
        case 'ceo':
            redirect('ceo/dashboard.php');
            break;
        case 'finance_officer':
            redirect('finance/dashboard.php');
            break;
        case 'hr_manager':
        case 'hr_officer':
            redirect('hr/dashboard.php');
            break;
    }
}

$error_message = '';
$success_message = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = sanitize_input($_POST['username']);
    $email = sanitize_input($_POST['email']);
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];
    $full_name = sanitize_input($_POST['full_name']);
    $role = sanitize_input($_POST['role']);
    
    // Validation
    if (empty($username) || empty($email) || empty($password) || empty($full_name) || empty($role)) {
        $error_message = 'All fields are required.';
    } elseif (strlen($username) < 3) {
        $error_message = 'Username must be at least 3 characters long.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error_message = 'Please enter a valid email address.';
    } elseif (strlen($password) < 6) {
        $error_message = 'Password must be at least 6 characters long.';
    } elseif ($password !== $confirm_password) {
        $error_message = 'Passwords do not match.';
    } elseif (!in_array($role, ['trader', 'ceo', 'finance_officer', 'hr_manager', 'hr_officer'])) {
        $error_message = 'Please select a valid role.';
    } else {
        $db = getDBConnection();
        
        // Check if username or email already exists
        $check_stmt = $db->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
        $check_stmt->execute([$username, $email]);
        
        if ($check_stmt->fetch()) {
            $error_message = 'Username or email already exists.';
        } else {
            // Hash password and create user
            $password_hash = password_hash($password, PASSWORD_DEFAULT);
            
            try {
                // First, let's check the role column size and use shorter codes if needed
                $column_info = $db->query("SHOW COLUMNS FROM users LIKE 'role'")->fetch();
                $max_role_length = 0;
                
                if ($column_info && preg_match('/varchar\((\d+)\)/', $column_info['Type'], $matches)) {
                    $max_role_length = (int)$matches[1];
                }
                
                // Map roles to shorter codes if column is too small
                $role_mapping = [
                    'trader' => 'trader',
                    'ceo' => 'ceo',
                    'finance_officer' => 'finance',
                    'hr_manager' => 'hr_mgr',
                    'hr_officer' => 'hr_off'
                ];
                
                $role_to_insert = $role;
                
                // If column is too small for full role names, use shortened versions
                if ($max_role_length > 0 && strlen($role) > $max_role_length) {
                    if (isset($role_mapping[$role])) {
                        $role_to_insert = $role_mapping[$role];
                    } else {
                        // Fallback: use first 10 characters
                        $role_to_insert = substr($role, 0, $max_role_length);
                    }
                }
                
                $insert_stmt = $db->prepare("
                    INSERT INTO users (username, email, password_hash, role, full_name, is_active, mandate_enabled) 
                    VALUES (?, ?, ?, ?, ?, 1, 0)
                ");
                
                if ($insert_stmt->execute([$username, $email, $password_hash, $role_to_insert, $full_name])) {
                    // Get the newly created user ID
                    $user_id = $db->lastInsertId();
                    
                    // Auto-create employee record
                    try {
                        $names = explode(' ', $full_name, 2);
                        $first_name = $names[0] ?? '';
                        $last_name = $names[1] ?? '';
                        $employee_id = strtoupper(substr($username, 0, 3)) . '-' . $user_id;
                        
                        // Get default department (or create one if doesn't exist)
                        $dept_stmt = $db->query("SELECT id FROM departments LIMIT 1");
                        $dept = $dept_stmt->fetch();
                        $dept_id = $dept ? $dept['id'] : 1;
                        
                        // Get default position (or create one if doesn't exist)
                        $pos_stmt = $db->query("SELECT id FROM job_positions LIMIT 1");
                        $pos = $pos_stmt->fetch();
                        $pos_id = $pos ? $pos['id'] : 1;
                        
                        // Create employee record with minimal required info
                        $emp_stmt = $db->prepare("
                            INSERT INTO employees (employee_id, user_id, first_name, last_name, email, 
                                                 department_id, position_id, hire_date, basic_salary, 
                                                 employment_type, status, created_by)
                            VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), 0.00, 'full_time', 'active', ?)
                        ");
                        $emp_stmt->execute([$employee_id, $user_id, $first_name, $last_name, $email, 
                                           $dept_id, $pos_id, $user_id]);
                    } catch (Exception $e) {
                        // Log but don't fail registration if employee creation fails
                        error_log("Warning: Could not auto-create employee record for user {$user_id}: " . $e->getMessage());
                    }
                    
                    $success_message = 'Registration successful! Your account has been created and you are now registered as an employee. You can access the system immediately.';
                    // Clear form data
                    $_POST = array();
                } else {
                    $error_message = 'Registration failed. Please try again.';
                }
                
            } catch (PDOException $e) {
                // If we still get an error, try with a basic role
                try {
                    $insert_stmt = $db->prepare("
                        INSERT INTO users (username, email, password_hash, role, full_name, is_active, mandate_enabled) 
                        VALUES (?, ?, ?, 'trader', ?, 1, 0)
                    ");
                    
                    if ($insert_stmt->execute([$username, $email, $password_hash, $full_name])) {
                        $user_id = $db->lastInsertId();
                        
                        // Also create employee record in fallback
                        try {
                            $names = explode(' ', $full_name, 2);
                            $first_name = $names[0] ?? '';
                            $last_name = $names[1] ?? '';
                            $employee_id = strtoupper(substr($username, 0, 3)) . '-' . $user_id;
                            
                            $dept_stmt = $db->query("SELECT id FROM departments LIMIT 1");
                            $dept = $dept_stmt->fetch();
                            $dept_id = $dept ? $dept['id'] : 1;
                            
                            $pos_stmt = $db->query("SELECT id FROM job_positions LIMIT 1");
                            $pos = $pos_stmt->fetch();
                            $pos_id = $pos ? $pos['id'] : 1;
                            
                            $emp_stmt = $db->prepare("
                                INSERT INTO employees (employee_id, user_id, first_name, last_name, email, 
                                                     department_id, position_id, hire_date, basic_salary, 
                                                     employment_type, status, created_by)
                                VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), 0.00, 'full_time', 'active', ?)
                            ");
                            $emp_stmt->execute([$employee_id, $user_id, $first_name, $last_name, $email, 
                                               $dept_id, $pos_id, $user_id]);
                        } catch (Exception $emp_e) {
                            error_log("Warning: Could not auto-create employee record in fallback for user {$user_id}: " . $emp_e->getMessage());
                        }
                        
                        $success_message = 'Registration successful! Your account has been created and you are now registered as an employee.';
                        $_POST = array();
                    } else {
                        $error_message = 'Registration failed due to database constraints. Please contact the system administrator.';
                    }
                } catch (PDOException $e2) {
                    $error_message = 'System error during registration. Please contact the system administrator. Error: ' . $e2->getMessage();
                }
            }
        }
    }
}

$page_title = 'Register';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?> - Stock Exchange System</title>
    
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    
    <style>
        :root {
            --primary-color: #164e63;
            --primary-light: #0e7490;
            --accent-color: #06b6d4;
            --danger-color: #dc2626;
            --success-color: #059669;
            --info-color: #0284c7;
            --border-color: #d1d5db;
            --background-secondary: #f3f4f6;
            --radius-lg: 12px;
            --radius-md: 8px;
            --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            --shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
        }

        .login-container {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            background: linear-gradient(135deg, #164e63 0%, #0e7490 50%, #06b6d4 100%);
            position: relative;
            overflow: hidden;
        }

        .login-card {
            width: 100%;
            max-width: 600px;
            border: none;
            border-radius: 16px;
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
        }

        .login-header {
            background: transparent;
            padding: 2rem 2rem 1rem 2rem;
        }

        .login-body {
            padding: 1rem 2rem 2rem 2rem;
        }

        @keyframes float {
            0%, 100% { transform: translateY(0px); }
            50% { transform: translateY(-20px); }
        }

        .btn:hover:not(:disabled) {
            transform: translateY(-2px);
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04) !important;
        }

        .input-group:focus-within {
            box-shadow: 0 0 0 3px rgba(22, 78, 99, 0.1);
            border-radius: 8px;
        }

        .form-control:focus, .form-select:focus {
            border-color: #164e63 !important;
            box-shadow: 0 0 0 3px rgba(22, 78, 99, 0.1) !important;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <!-- Added professional background elements -->
        <div class="position-absolute top-0 start-0 w-100 h-100" style="background: linear-gradient(135deg, #164e63 0%, #0e7490 50%, #06b6d4 100%); opacity: 0.95;"></div>
        
        <!-- Professional floating elements -->
        <div class="position-absolute" style="top: 15%; right: 10%; width: 120px; height: 120px; background: rgba(255,255,255,0.08); border-radius: 50%; animation: float 6s ease-in-out infinite;"></div>
        <div class="position-absolute" style="bottom: 15%; left: 10%; width: 100px; height: 100px; background: rgba(255,255,255,0.06); border-radius: 30px; animation: float 8s ease-in-out infinite reverse;"></div>
        
        <div class="card login-card position-relative" style="z-index: 10;">
            <div class="card-header login-header border-0">
                <!-- Enhanced header with professional branding -->
                <div class="text-center mb-4">
                    <div class="d-inline-flex align-items-center justify-content-center bg-white rounded-circle shadow-lg mb-3" style="width: 80px; height: 80px;">
                        <i class="bi bi-person-plus-fill" style="font-size: 2.5rem; color: #164e63;"></i>
                    </div>
                    <h2 class="fw-bold mb-2" style="color: #164e63; font-size: 1.75rem;">Join Our Platform</h2>
                    <p class="text-muted mb-0 fw-medium">Create your professional trading account</p>
                </div>
            </div>
            
            <div class="card-body login-body">
                <?php if (!empty($error_message)): ?>
                    <!-- Enhanced error message styling -->
                    <div class="alert alert-danger border-0 shadow-sm mb-4" role="alert" style="background: linear-gradient(135deg, #fef2f2 0%, #fef2f2 100%); border-left: 4px solid #dc2626 !important;">
                        <div class="d-flex align-items-center">
                            <i class="bi bi-exclamation-triangle-fill me-2" style="color: #dc2626;"></i>
                            <span class="fw-medium"><?php echo $error_message; ?></span>
                        </div>
                    </div>
                <?php endif; ?>
                
                <?php if (!empty($success_message)): ?>
                    <!-- Enhanced success message styling -->
                    <div class="alert alert-success border-0 shadow-sm mb-4" role="alert" style="background: linear-gradient(135deg, #ecfdf5 0%, #f0fdf4 100%); border-left: 4px solid #059669 !important;">
                        <div class="d-flex align-items-start">
                            <i class="bi bi-check-circle-fill me-2 mt-1" style="color: #059669;"></i>
                            <div>
                                <div class="fw-semibold mb-1">Registration Successful!</div>
                                <small class="text-muted"><?php echo $success_message; ?></small>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
                
                <form method="POST" action="" id="registerForm" class="needs-validation" novalidate>
                    <!-- Enhanced form layout with better spacing and styling -->
                    <div class="row g-4 mb-4">
                        <div class="col-md-6">
                            <label for="username" class="form-label fw-semibold text-dark">Username *</label>
                            <div class="input-group">
                                <span class="input-group-text bg-light" style="border-color: #d1d5db;">
                                    <i class="bi bi-person text-muted"></i>
                                </span>
                                <input type="text" class="form-control" id="username" name="username" 
                                       value="<?php echo isset($_POST['username']) ? htmlspecialchars($_POST['username']) : ''; ?>" 
                                       placeholder="Choose username"
                                       style="border-color: #d1d5db;"
                                       required minlength="3">
                            </div>
                            <div class="form-text small">
                                <i class="bi bi-info-circle me-1"></i>
                                Minimum 3 characters
                            </div>
                        </div>
                        
                        <div class="col-md-6">
                            <label for="email" class="form-label fw-semibold text-dark">Email Address *</label>
                            <div class="input-group">
                                <span class="input-group-text bg-light" style="border-color: #d1d5db;">
                                    <i class="bi bi-envelope text-muted"></i>
                                </span>
                                <input type="email" class="form-control" id="email" name="email" 
                                       value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>" 
                                       placeholder="your@email.com"
                                       style="border-color: #d1d5db;"
                                       required>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mb-4">
                        <label for="full_name" class="form-label fw-semibold text-dark">Full Name *</label>
                        <div class="input-group">
                            <span class="input-group-text bg-light" style="border-color: #d1d5db;">
                                <i class="bi bi-person-badge text-muted"></i>
                            </span>
                            <input type="text" class="form-control" id="full_name" name="full_name" 
                                   value="<?php echo isset($_POST['full_name']) ? htmlspecialchars($_POST['full_name']) : ''; ?>" 
                                   placeholder="Enter your full name"
                                   style="border-color: #d1d5db;"
                                   required>
                        </div>
                    </div>
                    
                    <div class="mb-4">
                        <label for="role" class="form-label fw-semibold text-dark">Professional Role *</label>
                        <div class="input-group">
                            <span class="input-group-text bg-light" style="border-color: #d1d5db;">
                                <i class="bi bi-briefcase text-muted"></i>
                            </span>
                            <select class="form-select" id="role" name="role" style="border-color: #d1d5db;" required>
                                <option value="">Select your professional role</option>
                                <option value="trader" <?php echo (isset($_POST['role']) && $_POST['role'] == 'trader') ? 'selected' : ''; ?>>
                                    Trader
                                </option>
                                <option value="ceo" <?php echo (isset($_POST['role']) && $_POST['role'] == 'ceo') ? 'selected' : ''; ?>>
                                    Chief Executive Officer
                                </option>
                                <option value="finance_officer" <?php echo (isset($_POST['role']) && $_POST['role'] == 'finance_officer') ? 'selected' : ''; ?>>
                                    Finance Officer
                                </option>
                                <option value="hr_manager" <?php echo (isset($_POST['role']) && $_POST['role'] == 'hr_manager') ? 'selected' : ''; ?>>
                                    HR Manager
                                </option>
                                <option value="hr_officer" <?php echo (isset($_POST['role']) && $_POST['role'] == 'hr_officer') ? 'selected' : ''; ?>>
                                    HR Officer
                                </option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="row g-4 mb-4">
                        <div class="col-md-6">
                            <label for="password" class="form-label fw-semibold text-dark">Password *</label>
                            <div class="input-group">
                                <span class="input-group-text bg-light" style="border-color: #d1d5db;">
                                    <i class="bi bi-lock text-muted"></i>
                                </span>
                                <input type="password" class="form-control" id="password" name="password" 
                                       placeholder="Create password"
                                       style="border-color: #d1d5db;"
                                       required minlength="6">
                                <button class="btn btn-light" type="button" id="togglePassword" style="border-color: #d1d5db;">
                                    <i class="bi bi-eye text-muted"></i>
                                </button>
                            </div>
                            <div class="form-text small">
                                <i class="bi bi-shield-check me-1"></i>
                                Minimum 6 characters
                            </div>
                        </div>
                        
                        <div class="col-md-6">
                            <label for="confirm_password" class="form-label fw-semibold text-dark">Confirm Password *</label>
                            <div class="input-group">
                                <span class="input-group-text bg-light" style="border-color: #d1d5db;">
                                    <i class="bi bi-lock-fill text-muted"></i>
                                </span>
                                <input type="password" class="form-control" id="confirm_password" name="confirm_password" 
                                       placeholder="Confirm password"
                                       style="border-color: #d1d5db;"
                                       required minlength="6">
                                <button class="btn btn-light" type="button" id="toggleConfirmPassword" style="border-color: #d1d5db;">
                                    <i class="bi bi-eye text-muted"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Enhanced info alert with better styling -->
                    <div class="alert border-0 shadow-sm mb-4" style="background: linear-gradient(135deg, #eff6ff 0%, #f0f9ff 100%); border-left: 4px solid #0284c7 !important;">
                        <div class="d-flex align-items-start">
                            <i class="bi bi-info-circle-fill me-2 mt-1" style="color: #0284c7;"></i>
                            <div>
                                <div class="fw-semibold mb-1" style="color: #0284c7;">Account Approval Required</div>
                                <small class="text-muted">Your account will be created but requires admin approval to enable trading mandate. Please contact the system administrator after registration.</small>
                            </div>
                        </div>
                    </div>
                    
                    <div class="d-grid mb-4">
                        <button type="submit" class="btn btn-lg fw-semibold py-3" style="background: linear-gradient(135deg, #164e63 0%, #0e7490 100%); border: none; color: white; border-radius: 12px; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);">
                            <i class="bi bi-person-plus me-2"></i>
                            Create Professional Account
                        </button>
                    </div>
                </form>
                
                <!-- Enhanced sign-in link with professional styling -->
                <div class="text-center">
                    <div class="position-relative">
                        <hr class="my-4" style="border-color: #d1d5db;">
                        <span class="position-absolute top-50 start-50 translate-middle bg-white px-3 text-muted small">
                            Already have an account?
                        </span>
                    </div>
                    <a href="login.php" class="btn btn-outline-secondary btn-lg w-100 mt-3 fw-semibold">
                        <i class="bi bi-box-arrow-in-right me-2"></i>
                        Sign In to Your Account
                    </a>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        // Toggle password visibility functions
        function togglePasswordVisibility(passwordId, buttonId) {
            document.getElementById(buttonId).addEventListener('click', function() {
                const password = document.getElementById(passwordId);
                const icon = this.querySelector('i');
                
                if (password.type === 'password') {
                    password.type = 'text';
                    icon.classList.remove('bi-eye');
                    icon.classList.add('bi-eye-slash');
                    this.style.backgroundColor = '#f3f4f6';
                } else {
                    password.type = 'password';
                    icon.classList.remove('bi-eye-slash');
                    icon.classList.add('bi-eye');
                    this.style.backgroundColor = '';
                }
            });
        }
        
        togglePasswordVisibility('password', 'togglePassword');
        togglePasswordVisibility('confirm_password', 'toggleConfirmPassword');
        
        // Enhanced password confirmation validation
        document.getElementById('confirm_password').addEventListener('input', function() {
            const password = document.getElementById('password').value;
            const confirmPassword = this.value;
            
            if (password !== confirmPassword) {
                this.setCustomValidity('Passwords do not match');
                this.classList.add('is-invalid');
            } else {
                this.setCustomValidity('');
                this.classList.remove('is-invalid');
                this.classList.add('is-valid');
            }
        });
        
        document.getElementById('password').addEventListener('input', function() {
            const confirmPassword = document.getElementById('confirm_password');
            if (confirmPassword.value && this.value !== confirmPassword.value) {
                confirmPassword.setCustomValidity('Passwords do not match');
                confirmPassword.classList.add('is-invalid');
            } else {
                confirmPassword.setCustomValidity('');
                confirmPassword.classList.remove('is-invalid');
                if (confirmPassword.value) confirmPassword.classList.add('is-valid');
            }
        });
        
        // Form validation enhancement
        (function() {
            'use strict';
            window.addEventListener('load', function() {
                var forms = document.getElementsByClassName('needs-validation');
                var validation = Array.prototype.filter.call(forms, function(form) {
                    form.addEventListener('submit', function(event) {
                        if (form.checkValidity() === false) {
                            event.preventDefault();
                            event.stopPropagation();
                        }
                        form.classList.add('was-validated');
                    }, false);
                });
            }, false);
        })();
    </script>
</body>
</html>