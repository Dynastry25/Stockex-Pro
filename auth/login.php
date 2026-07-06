<?php
require_once '../config/config.php';

// Security headers
header("X-Frame-Options: DENY");
header("X-Content-Type-Options: nosniff");
header("Referrer-Policy: strict-origin-when-cross-origin");
header("X-XSS-Protection: 1; mode=block");

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
        default:
            // Default redirect for unknown roles
            redirect('dashboard.php');
            break;
    }
}

$error_message = '';
$login_attempts = 0;
$max_attempts = 5;
$lockout_time = 15 * 60; // 15 minutes

// Check for brute force protection
if (isset($_SESSION['login_attempts']) && isset($_SESSION['last_attempt'])) {
    $login_attempts = $_SESSION['login_attempts'];
    $last_attempt = $_SESSION['last_attempt'];
    
    // Reset attempts if lockout time has passed
    if (time() - $last_attempt > $lockout_time) {
        $login_attempts = 0;
        unset($_SESSION['login_attempts']);
        unset($_SESSION['last_attempt']);
    }
    
    // Check if user is locked out
    if ($login_attempts >= $max_attempts) {
        $remaining_time = $lockout_time - (time() - $last_attempt);
        $error_message = "Too many login attempts. Please try again in " . ceil($remaining_time / 60) . " minutes.";
    }
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && $login_attempts < $max_attempts) {
    $username = sanitize_input($_POST['username']);
    $password = $_POST['password'];
    $csrf_token = $_POST['csrf_token'] ?? '';
    
    // Validate CSRF token
    if (!isset($_SESSION['csrf_token']) || $csrf_token !== $_SESSION['csrf_token']) {
        $error_message = 'Invalid security token. Please refresh the page and try again.';
    } elseif (empty($username) || empty($password)) {
        $error_message = 'Please enter both username and password.';
    } else {
        $db = getDBConnection();
        
        // Use prepared statement to prevent SQL injection
        $stmt = $db->prepare("SELECT * FROM users WHERE (username = ? OR email = ?) AND is_active = 1");
        $stmt->execute([$username, $username]);
        $user = $stmt->fetch();
        
        if ($user) {
            // Add delay to prevent timing attacks
            usleep(rand(100000, 300000)); // 100-300ms delay
            
            if (password_verify($password, $user['password_hash'])) {
                // Check if account is locked (handle missing column gracefully)
                $is_locked = false;
                $lockout_until = null;
                
                if (isset($user['account_locked'])) {
                    $is_locked = $user['account_locked'];
                    $lockout_until = $user['lockout_until'] ?? null;
                }
                
                if ($is_locked && $lockout_until && strtotime($lockout_until) > time()) {
                    $error_message = 'Account temporarily locked. Please try again later.';
                    $login_attempts = $max_attempts;
                    $_SESSION['login_attempts'] = $login_attempts;
                    $_SESSION['last_attempt'] = time();
                } else {
                    // Check if mandate is enabled for non-admin users
                    if ($user['role'] != 'system_admin' && !$user['mandate_enabled']) {
                        $error_message = 'Your account mandate is not enabled. Please contact the system administrator.';
                        $login_attempts++;
                        $_SESSION['login_attempts'] = $login_attempts;
                        $_SESSION['last_attempt'] = time();
                    } else {
                        // Login successful - reset attempts
                        unset($_SESSION['login_attempts']);
                        unset($_SESSION['last_attempt']);
                        
                        // Set session variables BEFORE regenerating session ID
                        $_SESSION['user_id'] = $user['id'];
                        $_SESSION['username'] = $user['username'];
                        $_SESSION['role'] = $user['role'];
                        $_SESSION['full_name'] = $user['full_name'];
                        $_SESSION['login_time'] = time();
                        $_SESSION['user_agent'] = $_SERVER['HTTP_USER_AGENT'];
                        $_SESSION['ip_address'] = $_SERVER['REMOTE_ADDR'];
                        
                        // Now regenerate session ID
                        session_regenerate_id(true);
                        
                        try {
                            // Update last login - handle missing columns gracefully
                            $update_fields = ["updated_at = CURRENT_TIMESTAMP"];
                            $update_params = [$user['id']];
                            
                            // Check if last_login column exists
                            $check_columns = $db->query("SHOW COLUMNS FROM users LIKE 'last_login'")->fetch();
                            if ($check_columns) {
                                $update_fields[] = "last_login = CURRENT_TIMESTAMP";
                            }
                            
                            // Check if login_attempts column exists
                            $check_attempts = $db->query("SHOW COLUMNS FROM users LIKE 'login_attempts'")->fetch();
                            if ($check_attempts) {
                                $update_fields[] = "login_attempts = 0";
                                $update_fields[] = "account_locked = 0";
                                $update_fields[] = "lockout_until = NULL";
                            }
                            
                            $update_sql = "UPDATE users SET " . implode(", ", $update_fields) . " WHERE id = ?";
                            $update_stmt = $db->prepare($update_sql);
                            $update_stmt->execute($update_params);
                            
                        } catch (PDOException $e) {
                            // Log the error but don't break the login process
                            error_log("Login update error: " . $e->getMessage());
                            
                            // Fallback to basic update
                            try {
                                $fallback_stmt = $db->prepare("UPDATE users SET updated_at = CURRENT_TIMESTAMP WHERE id = ?");
                                $fallback_stmt->execute([$user['id']]);
                            } catch (PDOException $e2) {
                                error_log("Fallback update also failed: " . $e2->getMessage());
                            }
                        }
                        
                        // Log login activity (only if table exists)
                        try {
                            $log_check = $db->query("SHOW TABLES LIKE 'login_logs'")->fetch();
                            if ($log_check) {
                                $log_stmt = $db->prepare("INSERT INTO login_logs (user_id, ip_address, user_agent, success) VALUES (?, ?, ?, 1)");
                                $log_stmt->execute([$user['id'], $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT']]);
                            }
                        } catch (PDOException $e) {
                            error_log("Login log error: " . $e->getMessage());
                        }
                        
                        // Redirect based on role
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
                            default:
                                redirect('dashboard.php');
                                break;
                        }
                    }
                }
            } else {
                // Invalid password
                $login_attempts++;
                $_SESSION['login_attempts'] = $login_attempts;
                $_SESSION['last_attempt'] = time();
                
                try {
                    // Update failed login attempts in database (only if column exists)
                    $check_attempts = $db->query("SHOW COLUMNS FROM users LIKE 'login_attempts'")->fetch();
                    if ($check_attempts) {
                        $fail_stmt = $db->prepare("UPDATE users SET login_attempts = login_attempts + 1, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
                        $fail_stmt->execute([$user['id']]);
                        
                        // Lock account if too many attempts
                        if ($login_attempts >= $max_attempts) {
                            $lockout_until = date('Y-m-d H:i:s', time() + $lockout_time);
                            $lock_stmt = $db->prepare("UPDATE users SET account_locked = 1, lockout_until = ? WHERE id = ?");
                            $lock_stmt->execute([$lockout_until, $user['id']]);
                        }
                    } else {
                        // Fallback: just update the timestamp
                        $fail_stmt = $db->prepare("UPDATE users SET updated_at = CURRENT_TIMESTAMP WHERE id = ?");
                        $fail_stmt->execute([$user['id']]);
                    }
                } catch (PDOException $e) {
                    error_log("Failed login update error: " . $e->getMessage());
                }
                
                // Log failed attempt (only if table exists)
                try {
                    $log_check = $db->query("SHOW TABLES LIKE 'login_logs'")->fetch();
                    if ($log_check) {
                        $log_stmt = $db->prepare("INSERT INTO login_logs (user_id, ip_address, user_agent, success) VALUES (?, ?, ?, 0)");
                        $log_stmt->execute([$user['id'], $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT']]);
                    }
                } catch (PDOException $e) {
                    error_log("Failed login log error: " . $e->getMessage());
                }
                
                $error_message = 'Invalid username or password. Attempts remaining: ' . ($max_attempts - $login_attempts);
            }
        } else {
            // User not found - still increment attempts but don't reveal if user exists
            $login_attempts++;
            $_SESSION['login_attempts'] = $login_attempts;
            $_SESSION['last_attempt'] = time();
            
            // Add random delay to prevent user enumeration
            usleep(rand(200000, 500000));
            
            $error_message = 'Invalid username or password. Attempts remaining: ' . ($max_attempts - $login_attempts);
        }
    }
    
    // Regenerate CSRF token after form submission
    unset($_SESSION['csrf_token']);
}

// Generate CSRF token
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$page_title = 'Login';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?> - Stock Exchange System</title>
    
    <!-- Security Meta Tags -->
    <meta name="referrer" content="strict-origin-when-cross-origin">
    
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    
    <style>
        /* Fallback CSS variables */
        :root {
            --primary-color: #164e63;
            --primary-light: #0e7490;
            --accent-color: #06b6d4;
            --danger-color: #dc2626;
            --border-color: #d1d5db;
            --border-light: #e5e7eb;
            --text-primary: #1f2937;
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
            max-width: 450px;
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
    </style>
</head>
<body>
    <div class="login-container">
        <!-- Professional floating elements -->
        <div class="position-absolute" style="top: 10%; left: 10%; width: 100px; height: 100px; background: rgba(255,255,255,0.1); border-radius: 50%; animation: float 6s ease-in-out infinite;"></div>
        <div class="position-absolute" style="top: 60%; right: 15%; width: 150px; height: 150px; background: rgba(255,255,255,0.05); border-radius: 30px; animation: float 8s ease-in-out infinite reverse;"></div>
        <div class="position-absolute" style="bottom: 20%; left: 20%; width: 80px; height: 80px; background: rgba(255,255,255,0.08); border-radius: 50%; animation: float 7s ease-in-out infinite;"></div>
        
        <div class="card login-card position-relative" style="z-index: 10;">
            <div class="card-header login-header border-0">
                <!-- Enhanced header with professional branding -->
                <div class="text-center mb-4">
                    <div class="d-inline-flex align-items-center justify-content-center bg-white rounded-circle shadow-lg mb-3" style="width: 80px; height: 80px;">
                        <i class="bi bi-graph-up-arrow" style="font-size: 2.5rem; color: #164e63;"></i>
                    </div>
                    <h2 class="fw-bold mb-2" style="color: #164e63; font-size: 1.75rem;">Stock Exchange System</h2>
                    <p class="text-muted mb-0 fw-medium">Professional Trading Platform</p>
                </div>
                
                <div class="text-center">
                    <h4 class="fw-semibold mb-2" style="color: #1f2937;">Secure Login</h4>
                    <p class="text-muted small mb-0">Sign in to access your dashboard</p>
                </div>
            </div>
            
            <div class="card-body login-body">
                <?php if (!empty($error_message)): ?>
                    <!-- Enhanced error message styling -->
                    <div class="alert alert-danger border-0 shadow-sm" role="alert" style="background: linear-gradient(135deg, #fef2f2 0%, #fef2f2 100%); border-left: 4px solid #dc2626 !important;">
                        <div class="d-flex align-items-center">
                            <i class="bi bi-exclamation-triangle-fill me-2" style="color: #dc2626;"></i>
                            <span class="fw-medium"><?php echo $error_message; ?></span>
                        </div>
                    </div>
                <?php endif; ?>
                
                <!-- Security notice -->
                <?php if ($login_attempts >= 3): ?>
                <div class="alert alert-warning border-0 shadow-sm" role="alert">
                    <div class="d-flex align-items-center">
                        <i class="bi bi-shield-exclamation me-2"></i>
                        <span class="fw-medium">Multiple failed login attempts detected. Account may be locked after <?php echo $max_attempts; ?> attempts.</span>
                    </div>
                </div>
                <?php endif; ?>
                
                <form method="POST" action="" class="needs-validation" novalidate autocomplete="on">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    
                    <div class="mb-4">
                        <label for="username" class="form-label fw-semibold text-dark">Username or Email</label>
                        <div class="input-group input-group-lg">
                            <span class="input-group-text bg-light border-end-0" style="border-color: #d1d5db;">
                                <i class="bi bi-person text-muted"></i>
                            </span>
                            <input type="text" class="form-control border-start-0 ps-0" id="username" name="username" 
                                   value="<?php echo isset($_POST['username']) ? htmlspecialchars($_POST['username']) : ''; ?>" 
                                   placeholder="Enter your username or email"
                                   style="border-color: #d1d5db; box-shadow: none;"
                                   required
                                   autocomplete="username"
                                   <?php echo $login_attempts >= $max_attempts ? 'disabled' : ''; ?>>
                        </div>
                    </div>
                    
                    <div class="mb-4">
                        <label for="password" class="form-label fw-semibold text-dark">Password</label>
                        <div class="input-group input-group-lg">
                            <span class="input-group-text bg-light border-end-0" style="border-color: #d1d5db;">
                                <i class="bi bi-lock text-muted"></i>
                            </span>
                            <input type="password" class="form-control border-start-0 border-end-0 ps-0" id="password" name="password" 
                                   placeholder="Enter your password"
                                   style="border-color: #d1d5db; box-shadow: none;"
                                   required
                                   autocomplete="current-password"
                                   <?php echo $login_attempts >= $max_attempts ? 'disabled' : ''; ?>>
                            <button class="btn btn-light border-start-0" type="button" id="togglePassword" style="border-color: #d1d5db;">
                                <i class="bi bi-eye text-muted"></i>
                            </button>
                        </div>
                    </div>
                    
                    <div class="d-grid mb-4">
                        <button type="submit" class="btn btn-lg fw-semibold py-3" 
                                style="background: linear-gradient(135deg, #164e63 0%, #0e7490 100%); border: none; color: white; border-radius: 12px; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06); transition: all 0.3s ease;"
                                <?php echo $login_attempts >= $max_attempts ? 'disabled' : ''; ?>>
                            <i class="bi bi-box-arrow-in-right me-2"></i>
                            <?php echo $login_attempts >= $max_attempts ? 'Account Locked' : 'Sign In to Dashboard'; ?>
                        </button>
                    </div>
                </form>
                

            </div>
        </div>
    </div>
    
    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        // Toggle password visibility
        document.getElementById('togglePassword')?.addEventListener('click', function() {
            const password = document.getElementById('password');
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
        
        // Auto-focus on username field
        document.addEventListener('DOMContentLoaded', function() {
            const usernameField = document.getElementById('username');
            if (usernameField && !usernameField.disabled) {
                usernameField.focus();
            }
        });
    </script>
</body>
</html>" 