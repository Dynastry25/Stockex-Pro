<?php
/**
 * FORGOT PASSWORD - OTP BASED FLOW
 * Step 1: Enter email → OTP sent
 * Step 2: Enter OTP → verified
 * Step 3: New password + confirm → updated
 */
require_once '../config/config.php';
require_once '../config/email.php';
require_once '../phpmailer/src/PHPMailer.php';
require_once '../phpmailer/src/SMTP.php';
require_once '../phpmailer/src/Exception.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

header("X-Frame-Options: DENY");
header("X-Content-Type-Options: nosniff");

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (is_logged_in()) {
    redirect('login.php');
}

if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$error_message = '';
$success_message = '';
$step = $_SESSION['otp_step'] ?? 1;
$reset_email = $_SESSION['otp_email'] ?? '';

// =====================================================
// STEP 1: Request OTP
// =====================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'request_otp') {
    $email = strtolower(trim($_POST['email'] ?? ''));
    $csrf = $_POST['csrf_token'] ?? '';

    if ($csrf !== ($_SESSION['csrf_token'] ?? '')) {
        $error_message = 'Invalid security token.';
    } elseif (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error_message = 'Please enter a valid email address.';
    } else {
        $db = getDBConnection();

        $db->exec("CREATE TABLE IF NOT EXISTS password_otps (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            otp_code VARCHAR(6) NOT NULL,
            expires_at DATETIME NOT NULL,
            attempts INT DEFAULT 0,
            verified TINYINT(1) DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user (user_id)
        )");

        $stmt = $db->prepare("SELECT id, username, full_name, email FROM users WHERE email = ? AND is_active = 1");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user) {
            $otp = str_pad(random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
            $expires_at = date('Y-m-d H:i:s', time() + 600);

            $db->prepare("DELETE FROM password_otps WHERE user_id = ?")->execute([$user['id']]);
            $db->prepare("INSERT INTO password_otps (user_id, otp_code, expires_at) VALUES (?, ?, ?)")
               ->execute([$user['id'], $otp, $expires_at]);

            $_SESSION['otp_user_id'] = $user['id'];
            $_SESSION['otp_email'] = $email;
            $_SESSION['otp_step'] = 2;
            unset($_SESSION['csrf_token']);
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

            $mail = new PHPMailer(true);
            $email_sent = false;
            try {
                configureMailer($mail);
                $mail->addAddress($email, $user['full_name']);
                $mail->isHTML(true);
                $mail->Subject = 'Your OTP Code - Stock Exchange System';
                $mail->Body = "
                    <html><head><style>
                    body{font-family:Arial,sans-serif;color:#333}
                    .c{max-width:500px;margin:0 auto;padding:20px}
                    .h{background:#164e63;color:white;padding:25px;text-align:center;border-radius:8px 8px 0 0}
                    .b{background:#f8f9fa;padding:30px;border-radius:0 0 8px 8px}
                    .o{background:white;border:2px dashed #164e63;border-radius:10px;padding:20px;text-align:center;margin:20px 0}
                    .n{font-size:36px;font-weight:bold;color:#164e63;letter-spacing:8px}
                    .f{text-align:center;margin-top:20px;font-size:12px;color:#6c757d}
                    </style></head><body>
                    <div class='c'>
                        <div class='h'><h2>Password Reset OTP</h2></div>
                        <div class='b'>
                            <h3>Hello " . htmlspecialchars($user['full_name']) . ",</h3>
                            <p>Use this OTP code to reset your password:</p>
                            <div class='o'><div class='n'>{$otp}</div></div>
                            <p><strong>This OTP expires in 10 minutes.</strong></p>
                            <p>If you did not request this, ignore this email.</p>
                        </div>
                        <div class='f'>&copy; " . date('Y') . " Stock Exchange System</div>
                    </div></body></html>";
                $mail->AltBody = "Your OTP code is: {$otp}\nExpires in 10 minutes.";
                $mail->send();
                $email_sent = true;
            } catch (PHPMailerException $e) {
                error_log("OTP email failed: " . $e->getMessage());
            }
            
            $step = 2;
            if ($email_sent) {
                $success_message = "OTP sent to <strong>" . htmlspecialchars($email) . "</strong>. Check your inbox.";
            } else {
                // SMTP not configured or failed - show OTP on screen for development
                $success_message = "OTP for <strong>" . htmlspecialchars($email) . "</strong>:" .
                    "<div class='alert alert-warning mt-3 mb-0 text-center'>" .
                    "<small class='text-muted d-block mb-1'>Email not configured. Your OTP Code:</small>" .
                    "<span style='font-size:32px;font-weight:bold;letter-spacing:8px;color:#164e63;'>{$otp}</span>" .
                    "<small class='d-block mt-1 text-muted'>Expires in 10 minutes.</small></div>";
                error_log("OTP for {$email}: {$otp} (Email not sent - SMTP not configured)");
            }
        } else {
            usleep(rand(200000, 500000));
            $success_message = "If an account with this email exists, an OTP has been sent.";
        }
    }
}

// =====================================================
// STEP 2: Verify OTP
// =====================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'verify_otp') {
    $otp_input = trim($_POST['otp_code'] ?? '');
    $csrf = $_POST['csrf_token'] ?? '';

    if ($csrf !== ($_SESSION['csrf_token'] ?? '')) {
        $error_message = 'Invalid security token.';
    } elseif (empty($otp_input) || strlen($otp_input) !== 6) {
        $error_message = 'Please enter the 6-digit OTP.';
    } elseif (!isset($_SESSION['otp_user_id'])) {
        $error_message = 'Session expired. Start over.';
        $step = 1;
    } else {
        $db = getDBConnection();
        $uid = $_SESSION['otp_user_id'];

        $stmt = $db->prepare("SELECT * FROM password_otps WHERE user_id = ? AND verified = 0 ORDER BY id DESC LIMIT 1");
        $stmt->execute([$uid]);
        $rec = $stmt->fetch();

        if (!$rec) {
            $error_message = 'No OTP found. Request a new one.';
            $step = 1;
        } elseif ($rec['attempts'] >= 5) {
            $error_message = 'Too many attempts. Request a new OTP.';
            $step = 1;
        } elseif (strtotime($rec['expires_at']) < time()) {
            $error_message = 'OTP expired. Request a new one.';
            $step = 1;
        } elseif ($rec['otp_code'] !== $otp_input) {
            $db->prepare("UPDATE password_otps SET attempts = attempts + 1 WHERE id = ?")->execute([$rec['id']]);
            $remaining = 5 - ($rec['attempts'] + 1);
            $error_message = "Invalid OTP. {$remaining} attempts left.";
        } else {
            $db->prepare("UPDATE password_otps SET verified = 1 WHERE id = ?")->execute([$rec['id']]);
            $_SESSION['otp_step'] = 3;
            $_SESSION['otp_verified'] = true;
            unset($_SESSION['csrf_token']);
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            $step = 3;
            $success_message = "OTP verified! Set your new password.";
        }
    }
}

// =====================================================
// STEP 3: Set New Password
// =====================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reset_password') {
    $pw = $_POST['password'] ?? '';
    $cpw = $_POST['confirm_password'] ?? '';
    $csrf = $_POST['csrf_token'] ?? '';

    if ($csrf !== ($_SESSION['csrf_token'] ?? '')) {
        $error_message = 'Invalid security token.';
    } elseif (!($_SESSION['otp_verified'] ?? false)) {
        $error_message = 'OTP not verified. Start over.';
        $step = 1;
    } elseif (strlen($pw) < 8) {
        $error_message = 'Password must be at least 8 characters.';
    } elseif ($pw !== $cpw) {
        $error_message = 'Passwords do not match.';
    } else {
        $db = getDBConnection();
        $uid = $_SESSION['otp_user_id'];

        $hashed = password_hash($pw, PASSWORD_DEFAULT);
        $db->prepare("UPDATE users SET password_hash = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?")->execute([$hashed, $uid]);
        $db->prepare("DELETE FROM password_otps WHERE user_id = ?")->execute([$uid]);

        unset($_SESSION['otp_user_id'], $_SESSION['otp_email'], $_SESSION['otp_step'], $_SESSION['otp_verified']);
        unset($_SESSION['csrf_token']);
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

        $step = 1;
        $success_message = "Password updated! <a href='login.php' class='fw-bold'>Login now</a>";
    }
}

// Cancel
if (isset($_GET['cancel'])) {
    unset($_SESSION['otp_user_id'], $_SESSION['otp_email'], $_SESSION['otp_step'], $_SESSION['otp_verified']);
    unset($_SESSION['csrf_token']);
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    $step = 1;
}

$page_title = 'Forgot Password';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?> - Stock Exchange System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root { --primary-color: #164e63; --primary-light: #0e7490; --accent-color: #06b6d4; }
        .login-container {
            min-height: 100vh; display: flex; align-items: center; justify-content: center;
            padding: 20px;
            background: linear-gradient(135deg, #164e63 0%, #0e7490 50%, #06b6d4 100%);
            position: relative; overflow: hidden;
        }
        .login-card { width: 100%; max-width: 480px; border: none; border-radius: 16px; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.1); }
        .login-header { background: transparent; padding: 2rem 2rem 1rem 2rem; }
        .login-body { padding: 1rem 2rem 2rem 2rem; }
        @keyframes float { 0%,100%{transform:translateY(0)} 50%{transform:translateY(-20px)} }
        .btn-primary-custom {
            background: linear-gradient(135deg, #164e63 0%, #0e7490 100%); border: none; color: white;
            border-radius: 12px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); transition: all 0.3s;
        }
        .btn-primary-custom:hover:not(:disabled) { transform: translateY(-2px); box-shadow: 0 20px 25px -5px rgba(0,0,0,0.1); color: white; }
        .btn-primary-custom:disabled { opacity: 0.6; }
        .input-group:focus-within { box-shadow: 0 0 0 3px rgba(22,78,99,0.1); border-radius: 8px; }
        .alert-success-custom { border-left: 4px solid #16a34a; }
        .alert-danger-custom { border-left: 4px solid #dc2626; }
        .otp-input { font-size: 28px; font-weight: bold; letter-spacing: 12px; text-align: center; padding: 12px; }
        .step-indicator { display: flex; justify-content: center; gap: 8px; margin-bottom: 20px; }
        .step-dot { width: 10px; height: 10px; border-radius: 50%; background: #d1d5db; transition: all 0.3s; }
        .step-dot.active { background: #164e63; width: 30px; border-radius: 5px; }
        .step-dot.completed { background: #16a34a; }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="position-absolute" style="top:10%;left:10%;width:100px;height:100px;background:rgba(255,255,255,0.1);border-radius:50%;animation:float 6s ease-in-out infinite;"></div>
        <div class="position-absolute" style="top:60%;right:15%;width:150px;height:150px;background:rgba(255,255,255,0.05);border-radius:30px;animation:float 8s ease-in-out infinite reverse;"></div>
        <div class="position-absolute" style="bottom:20%;left:20%;width:80px;height:80px;background:rgba(255,255,255,0.08);border-radius:50%;animation:float 7s ease-in-out infinite;"></div>

        <div class="card login-card position-relative" style="z-index:10;">
            <div class="card-header login-header border-0">
                <div class="text-center mb-3">
                    <div class="d-inline-flex align-items-center justify-content-center bg-white rounded-circle shadow-lg mb-3" style="width:80px;height:80px;">
                        <i class="bi bi-shield-lock" style="font-size:2.5rem;color:#164e63;"></i>
                    </div>
                    <h2 class="fw-bold mb-2" style="color:#164e63;font-size:1.75rem;">Stock Exchange System</h2>
                    <p class="text-muted mb-0 fw-medium">Password Recovery</p>
                </div>

                <div class="step-indicator">
                    <div class="step-dot <?php echo $step >= 1 ? ($step > 1 ? 'completed' : 'active') : ''; ?>"></div>
                    <div class="step-dot <?php echo $step >= 2 ? ($step > 2 ? 'completed' : 'active') : ''; ?>"></div>
                    <div class="step-dot <?php echo $step >= 3 ? 'active' : ''; ?>"></div>
                </div>

                <div class="text-center">
                    <h4 class="fw-semibold mb-2" style="color:#1f2937;">
                        <?php echo [' Forgot Password','Enter OTP Code','Set New Password'][$step - 1] ?? 'Forgot Password'; ?>
                    </h4>
                    <p class="text-muted small mb-0">
                        <?php
                        echo match($step) {
                            1 => 'Enter your email to receive a verification code.',
                            2 => 'Enter the 6-digit code sent to your email.',
                            3 => 'Create a new strong password.',
                            default => ''
                        };
                        ?>
                    </p>
                </div>
            </div>

            <div class="card-body login-body">
                <?php if (!empty($error_message)): ?>
                    <div class="alert alert-danger border-0 shadow-sm alert-danger-custom">
                        <div class="d-flex align-items-center">
                            <i class="bi bi-exclamation-triangle-fill me-2" style="color:#dc2626;"></i>
                            <span class="fw-medium"><?php echo $error_message; ?></span>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if (!empty($success_message)): ?>
                    <div class="alert alert-success border-0 shadow-sm alert-success-custom">
                        <div class="d-flex align-items-center">
                            <i class="bi bi-check-circle-fill me-2" style="color:#16a34a;"></i>
                            <span class="fw-medium"><?php echo $success_message; ?></span>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- ===== STEP 1: Email ===== -->
                <?php if ($step === 1): ?>
                <form method="POST" autocomplete="off">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    <input type="hidden" name="action" value="request_otp">

                    <div class="mb-4">
                        <label for="email" class="form-label fw-semibold text-dark">Email Address</label>
                        <div class="input-group input-group-lg">
                            <span class="input-group-text bg-light border-end-0" style="border-color:#d1d5db;">
                                <i class="bi bi-envelope text-muted"></i>
                            </span>
                            <input type="email" class="form-control border-start-0 ps-0" id="email" name="email"
                                   placeholder="Enter your registered email"
                                   style="border-color:#d1d5db;box-shadow:none;" required autofocus
                                   value="<?php echo htmlspecialchars($_POST['email'] ?? ($reset_email ?? '')); ?>">
                        </div>
                        <div class="form-text mt-1">We'll send a 6-digit OTP to this email.</div>
                    </div>

                    <div class="d-grid mb-3">
                        <button type="submit" class="btn btn-primary-custom btn-lg fw-semibold py-3">
                            <i class="bi bi-send me-2"></i> Send OTP Code
                        </button>
                    </div>
                    <div class="text-center">
                        <a href="login.php" class="text-decoration-none small"><i class="bi bi-arrow-left me-1"></i> Back to Login</a>
                    </div>
                </form>

                <!-- ===== STEP 2: OTP ===== -->
                <?php elseif ($step === 2): ?>
                <form method="POST" autocomplete="off">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    <input type="hidden" name="action" value="verify_otp">

                    <div class="text-center mb-3">
                        <div class="d-inline-flex align-items-center justify-content-center rounded-circle" style="width:50px;height:50px;background:#e0f2fe;">
                            <i class="bi bi-envelope-check" style="font-size:1.5rem;color:#0e7490;"></i>
                        </div>
                        <p class="mt-2 text-muted small">Code sent to<br><strong><?php echo htmlspecialchars($reset_email); ?></strong></p>
                    </div>

                    <div class="mb-4">
                        <label for="otp_code" class="form-label fw-semibold text-dark">OTP Code</label>
                        <input type="text" class="form-control form-control-lg otp-input" id="otp_code" name="otp_code"
                               placeholder="000000" maxlength="6" pattern="[0-9]{6}" required autofocus
                               inputmode="numeric" autocomplete="one-time-code">
                        <div class="form-text mt-1 text-center">Enter the 6-digit code. Expires in 10 minutes.</div>
                    </div>

                    <div class="d-grid mb-3">
                        <button type="submit" class="btn btn-primary-custom btn-lg fw-semibold py-3">
                            <i class="bi bi-check-circle me-2"></i> Verify OTP
                        </button>
                    </div>
                    <div class="text-center">
                        <a href="?cancel=1" class="text-decoration-none small"><i class="bi bi-arrow-left me-1"></i> Back to Login</a>
                    </div>
                </form>

                <!-- ===== STEP 3: New Password ===== -->
                <?php elseif ($step === 3): ?>
                <form method="POST" autocomplete="off">
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
                                   style="border-color:#d1d5db;box-shadow:none;" required minlength="8" autofocus>
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
                                   style="border-color:#d1d5db;box-shadow:none;" required minlength="8">
                        </div>
                    </div>

                    <div class="d-grid mb-3">
                        <button type="submit" class="btn btn-primary-custom btn-lg fw-semibold py-3">
                            <i class="bi bi-key me-2"></i> Update Password
                        </button>
                    </div>
                    <div class="text-center">
                        <a href="login.php" class="text-decoration-none small"><i class="bi bi-arrow-left me-1"></i> Back to Login</a>
                    </div>
                </form>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.getElementById('togglePassword')?.addEventListener('click', function() {
            const pw = document.getElementById('password');
            const icon = this.querySelector('i');
            if (pw.type === 'password') { pw.type = 'text'; icon.classList.replace('bi-eye','bi-eye-slash'); }
            else { pw.type = 'password'; icon.classList.replace('bi-eye-slash','bi-eye'); }
        });
        document.getElementById('otp_code')?.addEventListener('input', function() {
            this.value = this.value.replace(/[^0-9]/g, '');
        });
    </script>
</body>
</html>