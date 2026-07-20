<?php
// forgot_password.php
require_once '../config/config.php';
require_once '../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

// Security headers
header("X-Frame-Options: DENY");
header("X-Content-Type-Options: nosniff");
header("Referrer-Policy: strict-origin-when-cross-origin");
header("X-XSS-Protection: 1; mode=block");

// Redirect if already logged in
if (is_logged_in()) {
    $user = get_logged_in_user();
    $role = $user['role'] ?? '';
    $redirectMap = [
        'system_admin' => 'admin/dashboard.php',
        'trader' => 'trader/dashboard.php',
        'ceo' => 'ceo/dashboard.php',
        'finance_officer' => 'finance/dashboard.php',
        'hr_manager' => 'hr/dashboard.php',
        'hr_officer' => 'hr/dashboard.php',
    ];
    $target = $redirectMap[$role] ?? 'dashboard.php';
    redirect($target);
}

$error_message = '';
$success_message = '';
$token_valid = false;
$show_reset_form = false;
$reset_email = '';

// Initialize session for CSRF and reset process
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Generate CSRF token
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// ------------------------------------------------------------
// 1. Handle "Request Reset Link" (step 1)
// ------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'request_reset') {
    $email = sanitize_input($_POST['email'] ?? '');
    $csrf_token = $_POST['csrf_token'] ?? '';

    if (!isset($_SESSION['csrf_token']) || $csrf_token !== $_SESSION['csrf_token']) {
        $error_message = 'Invalid security token. Please refresh the page.';
    } elseif (empty($email)) {
        $error_message = 'Please enter your email address.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error_message = 'Please enter a valid email address.';
    } else {
        $db = getDBConnection();

        // Check if user exists with this email
        $stmt = $db->prepare("SELECT id, username, full_name FROM users WHERE email = ? AND is_active = 1");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user) {
            // Generate a secure token
            $token = bin2hex(random_bytes(32));
            $expires_at = date('Y-m-d H:i:s', time() + 3600); // 1 hour expiry

            try {
                // Ensure table exists
                $db->exec("CREATE TABLE IF NOT EXISTS password_resets (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    user_id INT NOT NULL,
                    token VARCHAR(255) NOT NULL UNIQUE,
                    expires_at DATETIME NOT NULL,
                    used BOOLEAN DEFAULT 0,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_token (token),
                    INDEX idx_user (user_id)
                )");

                // Delete any existing tokens for this user
                $delete = $db->prepare("DELETE FROM password_resets WHERE user_id = ?");
                $delete->execute([$user['id']]);

                // Insert new token
                $insert = $db->prepare("INSERT INTO password_resets (user_id, token, expires_at) VALUES (?, ?, ?)");
                $insert->execute([$user['id'], $token, $expires_at]);

                // Build reset link
                $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https://' : 'http://';
                $reset_link = $protocol . $_SERVER['HTTP_HOST'] . dirname($_SERVER['SCRIPT_NAME']) . "/forgot_password.php?token=" . $token . "&email=" . urlencode($email);

                // --- EMAIL SENDING USING PHPMailer ---
                $mail = new PHPMailer(true);

                try {
                    // Server settings
                    $mail->isSMTP();
                    $mail->Host       = 'smtp.gmail.com';                     // Your SMTP server
                    $mail->SMTPAuth   = true;
                    $mail->Username   = 'your-email@gmail.com';               // Your email
                    $mail->Password   = 'your-app-password';                  // Your app password
                    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                    $mail->Port       = 587;

                    // Recipients
                    $mail->setFrom('no-reply@stockexchange.com', 'Stock Exchange System');
                    $mail->addAddress($email, $user['full_name']);
                    $mail->addReplyTo('support@stockexchange.com', 'Support');

                    // Content
                    $mail->isHTML(true);
                    $mail->Subject = 'Password Reset Request - Stock Exchange System';
                    
                    // HTML Email Body
                    $mail->Body = "
                        <html>
                        <head>
                            <style>
                                body { font-family: Arial, sans-serif; color: #333; }
                                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                                .header { background: #164e63; color: white; padding: 20px; text-align: center; border-radius: 8px 8px 0 0; }
                                .content { background: #f8f9fa; padding: 30px; border-radius: 0 0 8px 8px; }
                                .button { display: inline-block; padding: 12px 30px; background: #164e63; color: white; text-decoration: none; border-radius: 6px; margin: 20px 0; }
                                .footer { text-align: center; margin-top: 20px; font-size: 12px; color: #6c757d; }
                            </style>
                        </head>
                        <body>
                            <div class='container'>
                                <div class='header'>
                                    <h2>🔐 Password Reset Request</h2>
                                </div>
                                <div class='content'>
                                    <h3>Hello " . htmlspecialchars($user['full_name']) . ",</h3>
                                    <p>We received a request to reset your password for the <strong>Stock Exchange System</strong>.</p>
                                    <p>Click the button below to reset your password:</p>
                                    <p style='text-align: center;'>
                                        <a href='" . $reset_link . "' class='button'>Reset Password</a>
                                    </p>
                                    <p>Or copy and paste this link into your browser:</p>
                                    <p style='background: #e9ecef; padding: 10px; border-radius: 4px; word-break: break-all;'>" . $reset_link . "</p>
                                    <p><strong>⚠️ This link will expire in 1 hour.</strong></p>
                                    <p>If you did not request this, please ignore this email.</p>
                                    <hr>
                                    <p style='font-size: 14px; color: #6c757d;'>This is an automated message. Please do not reply to this email.</p>
                                </div>
                                <div class='footer'>
                                    &copy; " . date('Y') . " Stock Exchange System. All rights reserved.
                                </div>
                            </div>
                        </body>
                        </html>
                    ";

                    // Plain text alternative
                    $mail->AltBody = "Password Reset Request\n\n";
                    $mail->AltBody .= "Hello " . $user['full_name'] . ",\n\n";
                    $mail->AltBody .= "We received a request to reset your password for the Stock Exchange System.\n\n";
                    $mail->AltBody .= "Click the link below to reset your password:\n";
                    $mail->AltBody .= $reset_link . "\n\n";
                    $mail->AltBody .= "This link will expire in 1 hour.\n\n";
                    $mail->AltBody .= "If you did not request this, please ignore this email.\n\n";
                    $mail->AltBody .= "Regards,\nStock Exchange Team";

                    $mail->send();
                    $success_message = "A password reset link has been sent to your email address. Please check your inbox (and spam folder).";
                    
                    // Log successful email send
                    try {
                        $log_check = $db->query("SHOW TABLES LIKE 'login_logs'")->fetch();
                        if ($log_check) {
                            $log = $db->prepare("INSERT INTO login_logs (user_id, ip_address, user_agent, success) VALUES (?, ?, ?, 1)");
                            $log->execute([$user['id'], $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT']]);
                        }
                    } catch (PDOException $e) { /* ignore */ }

                } catch (Exception $e) {
                    error_log("Email sending failed: " . $mail->ErrorInfo);
                    $error_message = "We couldn't send the email. Please try again later or contact support.";
                }

            } catch (PDOException $e) {
                error_log("Password reset error: " . $e->getMessage());
                $error_message = "An error occurred while processing your request. Please try again later.";
            }
        } else {
            // Don't reveal if email exists or not - generic message for security
            usleep(rand(200000, 500000));
            $success_message = "If an account with this email exists, a password reset link has been sent.";
        }

        // Regenerate CSRF token
        unset($_SESSION['csrf_token']);
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
}

// ------------------------------------------------------------
// 2. Handle "Reset Password" (step 2 - token validation)
// ------------------------------------------------------------
if (isset($_GET['token']) && isset($_GET['email'])) {
    $token = $_GET['token'];
    $email = urldecode($_GET['email']);

    if (empty($token) || empty($email)) {
        $error_message = "Invalid reset link.";
    } else {
        $db = getDBConnection();

        // Validate token
        $stmt = $db->prepare("
            SELECT pr.*, u.id as user_id, u.email, u.username, u.full_name 
            FROM password_resets pr
            JOIN users u ON pr.user_id = u.id
            WHERE pr.token = ? AND pr.used = 0 AND pr.expires_at > NOW()
            AND u.email = ? AND u.is_active = 1
        ");
        $stmt->execute([$token, $email]);
        $reset = $stmt->fetch();

        if ($reset) {
            $token_valid = true;
            $show_reset_form = true;
            $reset_email = $email;
            $_SESSION['reset_user_id'] = $reset['user_id'];
            $_SESSION['reset_token'] = $token;
            $success_message = "Please enter your new password.";
        } else {
            $error_message = "This password reset link is invalid or has expired. Please request a new one.";
        }
    }
}

// ------------------------------------------------------------
// 3. Handle "Submit New Password" (step 3)
// ------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reset_password') {
    $new_password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    $csrf_token = $_POST['csrf_token'] ?? '';
    $user_id = $_SESSION['reset_user_id'] ?? 0;
    $token = $_SESSION['reset_token'] ?? '';

    if (!isset($_SESSION['csrf_token']) || $csrf_token !== $_SESSION['csrf_token']) {
        $error_message = 'Invalid security token. Please refresh the page.';
    } elseif (empty($new_password) || empty($confirm_password)) {
        $error_message = 'Please fill in both password fields.';
    } elseif (strlen($new_password) < 8) {
        $error_message = 'Password must be at least 8 characters long.';
    } elseif ($new_password !== $confirm_password) {
        $error_message = 'Passwords do not match.';
    } elseif ($user_id <= 0 || empty($token)) {
        $error_message = 'Session expired. Please request a new reset link.';
    } else {
        $db = getDBConnection();

        // Verify token again
        $stmt = $db->prepare("
            SELECT pr.* FROM password_resets pr
            WHERE pr.token = ? AND pr.used = 0 AND pr.expires_at > NOW() AND pr.user_id = ?
        ");
        $stmt->execute([$token, $user_id]);
        $reset = $stmt->fetch();

        if (!$reset) {
            $error_message = "Invalid reset attempt. Please request a new link.";
        } else {
            // Hash new password
            $hashed = password_hash($new_password, PASSWORD_DEFAULT);

            // Update user password
            $update = $db->prepare("UPDATE users SET password_hash = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
            $update->execute([$hashed, $user_id]);

            // Mark token as used
            $mark = $db->prepare("UPDATE password_resets SET used = 1 WHERE id = ?");
            $mark->execute([$reset['id']]);

            // Clear session
            unset($_SESSION['reset_user_id']);
            unset($_SESSION['reset_token']);
            unset($_SESSION['csrf_token']);
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

            $success_message = "Your password has been successfully reset. You can now <a href='login.php'>login</a> with your new password.";
            $show_reset_form = false;
            $token_valid = false;
        }
    }
}

$page_title = 'Forgot Password';
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
            --border-color: #d1d5db;
            --border-light: #e5e7eb;
            --text-primary: #1f2937;
            --background-secondary: #f3f4f6;
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
            max-width: 480px;
            border: none;
            border-radius: 16px;
            box-shadow: 0 20px 25px -5px rgba(0,0,0,0.1), 0 10px 10px -5px rgba(0,0,0,0.04);
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
        .btn-primary-custom {
            background: linear-gradient(135deg, #164e63 0%, #0e7490 100%);
            border: none;
            color: white;
            border-radius: 12px;
            box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1);
            transition: all 0.3s ease;
        }
        .btn-primary-custom:hover:not(:disabled) {
            transform: translateY(-2px);
            box-shadow: 0 20px 25px -5px rgba(0,0,0,0.1);
        }
        .btn-primary-custom:disabled {
            opacity: 0.6;
        }
        .input-group:focus-within {
            box-shadow: 0 0 0 3px rgba(22,78,99,0.1);
            border-radius: 8px;
        }
        .alert-custom {
            border-left: 4px solid #164e63;
        }
        .alert-danger-custom {
            border-left: 4px solid #dc2626;
        }
        .alert-success-custom {
            border-left: 4px solid #16a34a;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <!-- decorative elements -->
        <div class="position-absolute" style="top:10%;left:10%;width:100px;height:100px;background:rgba(255,255,255,0.1);border-radius:50%;animation:float 6s ease-in-out infinite;"></div>
        <div class="position-absolute" style="top:60%;right:15%;width:150px;height:150px;background:rgba(255,255,255,0.05);border-radius:30px;animation:float 8s ease-in-out infinite reverse;"></div>
        <div class="position-absolute" style="bottom:20%;left:20%;width:80px;height:80px;background:rgba(255,255,255,0.08);border-radius:50%;animation:float 7s ease-in-out infinite;"></div>

        <div class="card login-card position-relative" style="z-index:10;">
            <div class="card-header login-header border-0">
                <div class="text-center mb-4">
                    <div class="d-inline-flex align-items-center justify-content-center bg-white rounded-circle shadow-lg mb-3" style="width:80px;height:80px;">
                        <i class="bi bi-key" style="font-size:2.5rem;color:#164e63;"></i>
                    </div>
                    <h2 class="fw-bold mb-2" style="color:#164e63;font-size:1.75rem;">Stock Exchange System</h2>
                    <p class="text-muted mb-0 fw-medium">Password Recovery</p>
                </div>
                <div class="text-center">
                    <h4 class="fw-semibold mb-2" style="color:#1f2937;">
                        <?php echo ($show_reset_form && $token_valid) ? 'Reset Your Password' : 'Forgot Password'; ?>
                    </h4>
                    <p class="text-muted small mb-0">
                        <?php echo ($show_reset_form && $token_valid) ? 'Enter your new password below.' : 'Enter your email to receive a reset link.'; ?>
                    </p>
                </div>
            </div>

            <div class="card-body login-body">
                <!-- Error / Success Messages -->
                <?php if (!empty($error_message)): ?>
                    <div class="alert alert-danger border-0 shadow-sm alert-danger-custom" role="alert">
                        <div class="d-flex align-items-center">
                            <i class="bi bi-exclamation-triangle-fill me-2" style="color:#dc2626;"></i>
                            <span class="fw-medium"><?php echo $error_message; ?></span>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if (!empty($success_message)): ?>
                    <div class="alert alert-success border-0 shadow-sm alert-success-custom" role="alert">
                        <div class="d-flex align-items-center">
                            <i class="bi bi-check-circle-fill me-2" style="color:#16a34a;"></i>
                            <span class="fw-medium"><?php echo $success_message; ?></span>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- ========================================= -->
                <!-- STEP 1: Request Reset Link (default)      -->
                <!-- ========================================= -->
                <?php if (!$show_reset_form || !$token_valid): ?>
                <form method="POST" action="" class="needs-validation" novalidate autocomplete="off">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    <input type="hidden" name="action" value="request_reset">

                    <div class="mb-4">
                        <label for="email" class="form-label fw-semibold text-dark">Email Address</label>
                        <div class="input-group input-group-lg">
                            <span class="input-group-text bg-light border-end-0" style="border-color:#d1d5db;">
                                <i class="bi bi-envelope text-muted"></i>
                            </span>
                            <input type="email" class="form-control border-start-0 ps-0" id="email" name="email" 
                                   placeholder="Enter your registered email" 
                                   style="border-color:#d1d5db; box-shadow:none;" 
                                   required autofocus
                                   value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>">
                        </div>
                        <div class="form-text mt-1">We'll send a password reset link to this email.</div>
                    </div>

                    <div class="d-grid mb-3">
                        <button type="submit" class="btn btn-primary-custom btn-lg fw-semibold py-3">
                            <i class="bi bi-send me-2"></i> Send Reset Link
                        </button>
                    </div>
                    <div class="text-center">
                        <a href="login.php" class="text-decoration-none small"><i class="bi bi-arrow-left me-1"></i> Back to Login</a>
                    </div>
                </form>
                <?php endif; ?>

                <!-- ========================================= -->
                <!-- STEP 2: Show Reset Password Form          -->
                <!-- ========================================= -->
                <?php if ($show_reset_form && $token_valid): ?>
                <form method="POST" action="" class="needs-validation" novalidate autocomplete="off">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    <input type="hidden" name="action" value="reset_password">

                    <div class="mb-3">
                        <label for="password" class="form-label fw-semibold text-dark">New Password</label>
                        <div class="input-group input-group-lg">
                            <span class="input-group-text bg-light border-end-0" style="border-color:#d1d5db;">
                                <i class="bi bi-lock text-muted"></i>
                            </span>
                            <input type="password" class="form-control border-start-0 border-end-0 ps-0" id="password" name="password" 
                                   placeholder="At least 8 characters" 
                                   style="border-color:#d1d5db; box-shadow:none;" required minlength="8">
                            <button class="btn btn-light border-start-0" type="button" id="togglePassword" style="border-color:#d1d5db;">
                                <i class="bi bi-eye text-muted"></i>
                            </button>
                        </div>
                    </div>

                    <div class="mb-4">
                        <label for="confirm_password" class="form-label fw-semibold text-dark">Confirm Password</label>
                        <div class="input-group input-group-lg">
                            <span class="input-group-text bg-light border-end-0" style="border-color:#d1d5db;">
                                <i class="bi bi-check-circle text-muted"></i>
                            </span>
                            <input type="password" class="form-control border-start-0 ps-0" id="confirm_password" name="confirm_password" 
                                   placeholder="Re-enter new password" 
                                   style="border-color:#d1d5db; box-shadow:none;" required>
                        </div>
                    </div>

                    <div class="d-grid mb-3">
                        <button type="submit" class="btn btn-primary-custom btn-lg fw-semibold py-3">
                            <i class="bi bi-key me-2"></i> Reset Password
                        </button>
                    </div>
                    <div class="text-center">
                        <a href="login.php" class="text-decoration-none small"><i class="bi bi-arrow-left me-1"></i> Back to Login</a>
                    </div>
                </form>
                <?php endif; ?>

                <!-- If token invalid but no error yet (fallback) -->
                <?php if (isset($_GET['token']) && !$token_valid && empty($error_message) && empty($success_message)): ?>
                    <div class="alert alert-warning border-0 shadow-sm" role="alert">
                        <div class="d-flex align-items-center">
                            <i class="bi bi-exclamation-triangle me-2"></i>
                            <span>The reset link is invalid or expired. Please request a new one below.</span>
                        </div>
                    </div>
                    <!-- Re-display request form -->
                    <form method="POST" action="" class="needs-validation" novalidate autocomplete="off">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        <input type="hidden" name="action" value="request_reset">
                        <div class="mb-3">
                            <label for="email" class="form-label fw-semibold text-dark">Email Address</label>
                            <input type="email" class="form-control form-control-lg" id="email" name="email" 
                                   placeholder="Enter your registered email" required>
                        </div>
                        <div class="d-grid">
                            <button type="submit" class="btn btn-primary-custom btn-lg fw-semibold py-3">
                                <i class="bi bi-send me-2"></i> Send Reset Link
                            </button>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>

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
            } else {
                password.type = 'password';
                icon.classList.remove('bi-eye-slash');
                icon.classList.add('bi-eye');
            }
        });
    </script>
</body>
</html>
