<?php
/**
 * EMAIL DEBUG PAGE - TEMPORARY
 * 
 * This is a temporary debug page to test email configurations.
 * DELETE THIS FILE AFTER TESTING.
 */

// Suppress errors for clean output
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Load configuration
require_once __DIR__ . '/config/env_loader.php';
require_once __DIR__ . '/config/email.php';

// Load PHPMailer
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/phpmailer/src/PHPMailer.php';
require_once __DIR__ . '/phpmailer/src/SMTP.php';
require_once __DIR__ . '/phpmailer/src/Exception.php';

// Start session for CSRF
session_start();

// Handle form submissions
$results = [];
$action = $_POST['action'] ?? $_GET['action'] ?? '';

// CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verify CSRF
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $results[] = ['type' => 'error', 'message' => 'CSRF token mismatch'];
    } else {
        $action = $_POST['action'] ?? '';
        
        if ($action === 'test_connection') {
            $results[] = testSMTPConnection();
        } elseif ($action === 'send_test') {
            $to = trim($_POST['test_email'] ?? '');
            $subject = trim($_POST['test_subject'] ?? 'StockEx Email Test');
            $results[] = sendTestEmail($to, $subject);
        } elseif ($action === 'check_config') {
            $results[] = checkConfiguration();
        }
    }
}

/**
 * Test SMTP Connection
 */
function testSMTPConnection() {
    try {
        $mail = new PHPMailer(true);
        
        // Enable debug output
        $mail->SMTPDebug = SMTP::DEBUG_CONNECTION;
        $debugOutput = '';
        $mail->Debugoutput = function($str, $level) use (&$debugOutput) {
            $debugOutput .= "[Level $level] $str\n";
        };
        
        // Configure SMTP
        $mail->isSMTP();
        $mail->Host = SMTP_HOST;
        $mail->SMTPAuth = !empty(SMTP_USERNAME);
        $mail->Username = SMTP_USERNAME;
        $mail->Password = SMTP_PASSWORD;
        $mail->Port = SMTP_PORT;
        $mail->SMTPKeepAlive = true;
        $mail->Timeout = 10;
        
        // Encryption
        if (SMTP_ENCRYPTION === 'ssl') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        } elseif (SMTP_ENCRYPTION === 'tls') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        } else {
            $mail->SMTPSecure = false;
        }
        
        // SSL options
        if (SMTP_VERIFY_PEER === false) {
            $mail->SMTPOptions = [
                'ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'allow_self_signed' => true,
                ]
            ];
        }
        
        // Attempt connection
        $mail->preconnect();
        
        return [
            'type' => 'success',
            'title' => 'SMTP Connection Test',
            'message' => 'Successfully connected to SMTP server!',
            'details' => [
                'Host' => SMTP_HOST,
                'Port' => SMTP_PORT,
                'Encryption' => SMTP_ENCRYPTION ?: 'None',
                'Auth Required' => !empty(SMTP_USERNAME) ? 'Yes' : 'No',
                'Username' => SMTP_USERNAME ?: '(not set)',
            ],
            'debug' => $debugOutput
        ];
        
    } catch (Exception $e) {
        return [
            'type' => 'error',
            'title' => 'SMTP Connection Test Failed',
            'message' => $e->getMessage(),
            'details' => [
                'Host' => SMTP_HOST,
                'Port' => SMTP_PORT,
                'Encryption' => SMTP_ENCRYPTION ?: 'None',
            ],
            'debug' => $debugOutput ?? ''
        ];
    }
}

/**
 * Send Test Email
 */
function sendTestEmail($to, $subject) {
    if (empty($to)) {
        return ['type' => 'error', 'title' => 'Test Email', 'message' => 'Email address is required'];
    }
    
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return ['type' => 'error', 'title' => 'Test Email', 'message' => 'Invalid email address format'];
    }
    
    try {
        $mail = new PHPMailer(true);
        
        // Enable debug
        $mail->SMTPDebug = SMTP::DEBUG_SERVER;
        $debugOutput = '';
        $mail->Debugoutput = function($str, $level) use (&$debugOutput) {
            $debugOutput .= "[Level $level] $str\n";
        };
        
        // Configure using centralized function
        configureMailer($mail);
        
        // Set recipients
        $mail->clearAddresses();
        $mail->addAddress($to);
        $mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
        $mail->addReplyTo(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
        
        // Email content
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->CharSet = 'UTF-8';
        
        $htmlBody = '
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background: #007bff; color: white; padding: 20px; text-align: center; border-radius: 5px 5px 0 0; }
                .content { background: #f8f9fa; padding: 20px; border: 1px solid #ddd; }
                .footer { background: #6c757d; color: white; padding: 10px; text-align: center; border-radius: 0 0 5px 5px; font-size: 12px; }
                .test-badge { background: #28a745; color: white; padding: 5px 10px; border-radius: 3px; display: inline-block; margin: 10px 0; }
            </style>
        </head>
        <body>
            <div class="container">
                <div class="header">
                    <h2>StockEx Email Test</h2>
                </div>
                <div class="content">
                    <p><span class="test-badge">TEST EMAIL</span></p>
                    <h3>Email Configuration Test</h3>
                    <p>This is a test email sent from the StockEx application to verify email configurations are working correctly.</p>
                    
                    <h4>Configuration Details:</h4>
                    <table style="width: 100%; border-collapse: collapse;">
                        <tr><td style="padding: 8px; border: 1px solid #ddd;"><strong>SMTP Host:</strong></td><td style="padding: 8px; border: 1px solid #ddd;">' . htmlspecialchars(SMTP_HOST) . '</td></tr>
                        <tr><td style="padding: 8px; border: 1px solid #ddd;"><strong>Port:</strong></td><td style="padding: 8px; border: 1px solid #ddd;">' . SMTP_PORT . '</td></tr>
                        <tr><td style="padding: 8px; border: 1px solid #ddd;"><strong>Encryption:</strong></td><td style="padding: 8px; border: 1px solid #ddd;">' . (SMTP_ENCRYPTION ?: 'None') . '</td></tr>
                        <tr><td style="padding: 8px; border: 1px solid #ddd;"><strong>From:</strong></td><td style="padding: 8px; border: 1px solid #ddd;">' . htmlspecialchars(SMTP_FROM_EMAIL) . '</td></tr>
                        <tr><td style="padding: 8px; border: 1px solid #ddd;"><strong>Sender Name:</strong></td><td style="padding: 8px; border: 1px solid #ddd;">' . htmlspecialchars(SMTP_FROM_NAME) . '</td></tr>
                        <tr><td style="padding: 8px; border: 1px solid #ddd;"><strong>Sent At:</strong></td><td style="padding: 8px; border: 1px solid #ddd;">' . date('Y-m-d H:i:s') . '</td></tr>
                    </table>
                    
                    <p style="margin-top: 20px;"><strong>DKIM Signing:</strong> ' . (file_exists(DKIM_PRIVATE_KEY_FILE) ? 'Enabled' : 'Disabled') . '</p>
                    
                    <p style="color: #666; font-size: 12px; margin-top: 20px;">
                        This is an automated test message. Please do not reply to this email.
                    </p>
                </div>
                <div class="footer">
                    <p>StockEx Mailer &copy; ' . date('Y') . ' | Test Email ID: ' . bin2hex(random_bytes(4)) . '</p>
                </div>
            </div>
        </body>
        </html>';
        
        $plainBody = "StockEx Email Test\n\n" .
                     "This is a test email sent from the StockEx application.\n\n" .
                     "Configuration Details:\n" .
                     "SMTP Host: " . SMTP_HOST . "\n" .
                     "Port: " . SMTP_PORT . "\n" .
                     "Encryption: " . (SMTP_ENCRYPTION ?: 'None') . "\n" .
                     "From: " . SMTP_FROM_EMAIL . "\n" .
                     "Sent At: " . date('Y-m-d H:i:s') . "\n\n" .
                     "This is an automated test message.";
        
        $mail->Body = $htmlBody;
        $mail->AltBody = $plainBody;
        
        // Send
        $mail->send();
        
        return [
            'type' => 'success',
            'title' => 'Test Email Sent',
            'message' => "Test email sent successfully to $to",
            'details' => [
                'To' => $to,
                'Subject' => $subject,
                'From' => SMTP_FROM_EMAIL,
                'Host' => SMTP_HOST,
                'Port' => SMTP_PORT,
            ],
            'debug' => $debugOutput
        ];
        
    } catch (Exception $e) {
        return [
            'type' => 'error',
            'title' => 'Test Email Failed',
            'message' => $e->getMessage(),
            'details' => [
                'To' => $to,
                'Subject' => $subject,
                'Mailer Error' => $mail->ErrorInfo ?? 'Unknown',
            ],
            'debug' => $debugOutput ?? ''
        ];
    }
}

/**
 * Check Configuration
 */
function checkConfiguration() {
    $checks = [];
    
    // Check .env file
    $envFile = __DIR__ . '/.env';
    $checks[] = [
        'name' => '.env File',
        'status' => file_exists($envFile) ? 'pass' : 'fail',
        'message' => file_exists($envFile) ? 'Found' : 'Not found',
    ];
    
    // Check SMTP settings
    $checks[] = [
        'name' => 'SMTP Host',
        'status' => !empty(SMTP_HOST) ? 'pass' : 'fail',
        'message' => SMTP_HOST ?: 'Not configured',
    ];
    
    $checks[] = [
        'name' => 'SMTP Port',
        'status' => SMTP_PORT > 0 ? 'pass' : 'fail',
        'message' => SMTP_PORT ?: 'Not configured',
    ];
    
    $checks[] = [
        'name' => 'SMTP Username',
        'status' => !empty(SMTP_USERNAME) ? 'pass' : 'warn',
        'message' => SMTP_USERNAME ?: 'Not configured (some servers require this)',
    ];
    
    $checks[] = [
        'name' => 'SMTP Password',
        'status' => !empty(SMTP_PASSWORD) ? 'pass' : 'fail',
        'message' => !empty(SMTP_PASSWORD) ? 'Set (hidden)' : 'Not configured',
    ];
    
    $checks[] = [
        'name' => 'From Email',
        'status' => !empty(SMTP_FROM_EMAIL) && filter_var(SMTP_FROM_EMAIL, FILTER_VALIDATE_EMAIL) ? 'pass' : 'fail',
        'message' => SMTP_FROM_EMAIL ?: 'Not configured',
    ];
    
    $checks[] = [
        'name' => 'From Name',
        'status' => !empty(SMTP_FROM_NAME) ? 'pass' : 'warn',
        'message' => SMTP_FROM_NAME ?: 'Not configured',
    ];
    
    $checks[] = [
        'name' => 'Encryption',
        'status' => in_array(SMTP_ENCRYPTION, ['tls', 'ssl', '']) ? 'pass' : 'warn',
        'message' => SMTP_ENCRYPTION ?: 'None (not recommended for production)',
    ];
    
    // Check DKIM
    $dkimKeyExists = file_exists(DKIM_PRIVATE_KEY_FILE);
    $checks[] = [
        'name' => 'DKIM Private Key',
        'status' => $dkimKeyExists ? 'pass' : 'warn',
        'message' => $dkimKeyExists ? 'Found' : 'Not found (optional)',
    ];
    
    // Check PHPMailer
    $phpmailerExists = file_exists(__DIR__ . '/phpmailer/src/PHPMailer.php');
    $checks[] = [
        'name' => 'PHPMailer Library',
        'status' => $phpmailerExists ? 'pass' : 'fail',
        'message' => $phpmailerExists ? 'Found' : 'Not found',
    ];
    
    // Check email.php config
    $emailConfigExists = file_exists(__DIR__ . '/config/email.php');
    $checks[] = [
        'name' => 'Email Config File',
        'status' => $emailConfigExists ? 'pass' : 'fail',
        'message' => $emailConfigExists ? 'Found' : 'Not found',
    ];
    
    // Check logs directory
    $logsDir = __DIR__ . '/logs';
    $checks[] = [
        'name' => 'Logs Directory',
        'status' => is_dir($logsDir) ? 'pass' : 'warn',
        'message' => is_dir($logsDir) ? 'Found' : 'Not found (will be created on first debug log)',
    ];
    
    // Check SMTP debug log
    $smtpDebugLog = $logsDir . '/smtp_debug.log';
    $checks[] = [
        'name' => 'SMTP Debug Log',
        'status' => file_exists($smtpDebugLog) ? 'pass' : 'info',
        'message' => file_exists($smtpDebugLog) ? 'Exists (' . date('Y-m-d H:i:s', filemtime($smtpDebugLog)) . ')' : 'Not yet created',
    ];
    
    return [
        'type' => 'info',
        'title' => 'Configuration Check',
        'checks' => $checks,
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Email Debug - StockEx</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body { background: #f5f5f5; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .debug-header { background: linear-gradient(135deg, #dc3545 0%, #c82333 100%); color: white; padding: 20px 0; }
        .card { border: none; box-shadow: 0 2px 10px rgba(0,0,0,0.1); margin-bottom: 20px; }
        .card-header { font-weight: 600; }
        .result-success { border-left: 4px solid #28a745; background: #d4edda; }
        .result-error { border-left: 4px solid #dc3545; background: #f8d7da; }
        .result-info { border-left: 4px solid #17a2b8; background: #d1ecf1; }
        .debug-output { background: #1e1e1e; color: #d4d4d4; padding: 15px; border-radius: 5px; font-family: 'Consolas', monospace; font-size: 12px; max-height: 300px; overflow-y: auto; white-space: pre-wrap; word-break: break-all; }
        .config-value { font-family: 'Consolas', monospace; background: #e9ecef; padding: 2px 6px; border-radius: 3px; }
        .warning-banner { background: #fff3cd; border: 1px solid #ffc107; padding: 10px; border-radius: 5px; margin-bottom: 20px; }
        .check-pass { color: #28a745; }
        .check-fail { color: #dc3545; }
        .check-warn { color: #ffc107; }
        .check-info { color: #17a2b8; }
    </style>
</head>
<body>
    <div class="debug-header">
        <div class="container">
            <h1><i class="bi bi-bug me-2"></i>Email Debug Console</h1>
            <p class="mb-0">Temporary debugging tool for email configuration testing</p>
        </div>
    </div>
    
    <div class="container py-4">
        <!-- Warning Banner -->
        <div class="warning-banner">
            <i class="bi bi-exclamation-triangle-fill text-warning me-2"></i>
            <strong>Warning:</strong> This is a temporary debug page. Delete this file after testing: <code>email_debug.php</code>
        </div>
        
        <div class="row">
            <!-- Left Column - Actions -->
            <div class="col-lg-6">
                <!-- Configuration Check -->
                <div class="card">
                    <div class="card-header bg-info text-white">
                        <i class="bi bi-gear me-2"></i>Configuration Check
                    </div>
                    <div class="card-body">
                        <form method="POST">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                            <input type="hidden" name="action" value="check_config">
                            <button type="submit" class="btn btn-info w-100">
                                <i class="bi bi-check-circle me-2"></i>Check Configuration
                            </button>
                        </form>
                    </div>
                </div>
                
                <!-- SMTP Connection Test -->
                <div class="card">
                    <div class="card-header bg-primary text-white">
                        <i class="bi bi-plug me-2"></i>SMTP Connection Test
                    </div>
                    <div class="card-body">
                        <p class="text-muted">Tests if the application can connect to the SMTP server.</p>
                        <form method="POST">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                            <input type="hidden" name="action" value="test_connection">
                            <button type="submit" class="btn btn-primary w-100">
                                <i class="bi bi-lightning me-2"></i>Test Connection
                            </button>
                        </form>
                    </div>
                </div>
                
                <!-- Send Test Email -->
                <div class="card">
                    <div class="card-header bg-success text-white">
                        <i class="bi bi-envelope me-2"></i>Send Test Email
                    </div>
                    <div class="card-body">
                        <form method="POST">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                            <input type="hidden" name="action" value="send_test">
                            
                            <div class="mb-3">
                                <label for="test_email" class="form-label">Recipient Email Address</label>
                                <input type="email" class="form-control" id="test_email" name="test_email" 
                                       placeholder="test@example.com" required
                                       value="<?php echo htmlspecialchars($_POST['test_email'] ?? ''); ?>">
                            </div>
                            
                            <div class="mb-3">
                                <label for="test_subject" class="form-label">Subject</label>
                                <input type="text" class="form-control" id="test_subject" name="test_subject" 
                                       value="<?php echo htmlspecialchars($_POST['test_subject'] ?? 'StockEx Email Test - ' . date('Y-m-d H:i:s')); ?>">
                            </div>
                            
                            <button type="submit" class="btn btn-success w-100">
                                <i class="bi bi-send me-2"></i>Send Test Email
                            </button>
                        </form>
                    </div>
                </div>
            </div>
            
            <!-- Right Column - Results & Current Config -->
            <div class="col-lg-6">
                <!-- Current Configuration Display -->
                <div class="card">
                    <div class="card-header bg-secondary text-white">
                        <i class="bi bi-info-circle me-2"></i>Current SMTP Configuration
                    </div>
                    <div class="card-body">
                        <table class="table table-sm">
                            <tr>
                                <td><strong>SMTP Host:</strong></td>
                                <td><span class="config-value"><?php echo htmlspecialchars(SMTP_HOST); ?></span></td>
                            </tr>
                            <tr>
                                <td><strong>SMTP Port:</strong></td>
                                <td><span class="config-value"><?php echo SMTP_PORT; ?></span></td>
                            </tr>
                            <tr>
                                <td><strong>Encryption:</strong></td>
                                <td><span class="config-value"><?php echo htmlspecialchars(SMTP_ENCRYPTION ?: 'None'); ?></span></td>
                            </tr>
                            <tr>
                                <td><strong>Username:</strong></td>
                                <td><span class="config-value"><?php echo htmlspecialchars(SMTP_USERNAME ?: '(not set)'); ?></span></td>
                            </tr>
                            <tr>
                                <td><strong>Password:</strong></td>
                                <td><span class="config-value"><?php echo !empty(SMTP_PASSWORD) ? '********' : '(not set)'; ?></span></td>
                            </tr>
                            <tr>
                                <td><strong>From Email:</strong></td>
                                <td><span class="config-value"><?php echo htmlspecialchars(SMTP_FROM_EMAIL); ?></span></td>
                            </tr>
                            <tr>
                                <td><strong>From Name:</strong></td>
                                <td><span class="config-value"><?php echo htmlspecialchars(SMTP_FROM_NAME); ?></span></td>
                            </tr>
                            <tr>
                                <td><strong>Verify Peer:</strong></td>
                                <td><span class="config-value"><?php echo SMTP_VERIFY_PEER ? 'Yes' : 'No'; ?></span></td>
                            </tr>
                            <tr>
                                <td><strong>DKIM Signing:</strong></td>
                                <td><span class="config-value"><?php echo file_exists(DKIM_PRIVATE_KEY_FILE) ? 'Enabled' : 'Disabled'; ?></span></td>
                            </tr>
                            <tr>
                                <td><strong>Debug Mode:</strong></td>
                                <td><span class="config-value"><?php echo SMTP_DEBUG ? 'Enabled' : 'Disabled'; ?></span></td>
                            </tr>
                        </table>
                    </div>
                </div>
                
                <!-- Results Display -->
                <?php if (!empty($results)): ?>
                    <?php foreach ($results as $result): ?>
                        <div class="card">
                            <div class="card-body result-<?php echo $result['type']; ?>">
                                <h5>
                                    <?php if ($result['type'] === 'success'): ?>
                                        <i class="bi bi-check-circle-fill text-success me-2"></i>
                                    <?php elseif ($result['type'] === 'error'): ?>
                                        <i class="bi bi-x-circle-fill text-danger me-2"></i>
                                    <?php else: ?>
                                        <i class="bi bi-info-circle-fill text-info me-2"></i>
                                    <?php endif; ?>
                                    <?php echo htmlspecialchars($result['title']); ?>
                                </h5>
                                <p><?php echo htmlspecialchars($result['message'] ?? ''); ?></p>
                                
                                <?php if (!empty($result['details'])): ?>
                                    <table class="table table-sm table-bordered mb-3">
                                        <?php foreach ($result['details'] as $key => $value): ?>
                                            <tr>
                                                <td><strong><?php echo htmlspecialchars($key); ?>:</strong></td>
                                                <td><span class="config-value"><?php echo htmlspecialchars($value); ?></span></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </table>
                                <?php endif; ?>
                                
                                <?php if (!empty($result['checks'])): ?>
                                    <table class="table table-sm">
                                        <?php foreach ($result['checks'] as $check): ?>
                                            <tr>
                                                <td>
                                                    <?php if ($check['status'] === 'pass'): ?>
                                                        <i class="bi bi-check-circle-fill check-pass"></i>
                                                    <?php elseif ($check['status'] === 'fail'): ?>
                                                        <i class="bi bi-x-circle-fill check-fail"></i>
                                                    <?php elseif ($check['status'] === 'warn'): ?>
                                                        <i class="bi bi-exclamation-triangle-fill check-warn"></i>
                                                    <?php else: ?>
                                                        <i class="bi bi-info-circle-fill check-info"></i>
                                                    <?php endif; ?>
                                                </td>
                                                <td><strong><?php echo htmlspecialchars($check['name']); ?></strong></td>
                                                <td><?php echo htmlspecialchars($check['message']); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </table>
                                <?php endif; ?>
                                
                                <?php if (!empty($result['debug'])): ?>
                                    <div class="mt-3">
                                        <h6><i class="bi bi-terminal me-2"></i>SMTP Debug Output:</h6>
                                        <div class="debug-output"><?php echo htmlspecialchars($result['debug']); ?></div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
                
                <!-- Quick Actions -->
                <div class="card">
                    <div class="card-header bg-dark text-white">
                        <i class="bi bi-lightning me-2"></i>Quick Actions
                    </div>
                    <div class="card-body">
                        <div class="d-grid gap-2">
                            <a href="email_debug.php?action=check_config" class="btn btn-outline-info">
                                <i class="bi bi-arrow-repeat me-2"></i>Refresh Configuration
                            </a>
                            <a href="trader/send_contract_notes.php?test_smtp" class="btn btn-outline-warning" target="_blank">
                                <i class="bi bi-box-arrow-up-right me-2"></i>Test via Contract Notes
                            </a>
                            <a href="trader/send_email.php" class="btn btn-outline-secondary" target="_blank">
                                <i class="bi bi-box-arrow-up-right me-2"></i>Open Email Campaign
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Debug Log Viewer -->
        <div class="card mt-4">
            <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center">
                <span><i class="bi bi-journal-text me-2"></i>SMTP Debug Log</span>
                <button class="btn btn-sm btn-light" onclick="refreshLog()">
                    <i class="bi bi-arrow-clockwise"></i> Refresh
                </button>
            </div>
            <div class="card-body">
                <div id="debugLog" class="debug-output" style="max-height: 400px;">
                    <?php
                    $logFile = __DIR__ . '/logs/smtp_debug.log';
                    if (file_exists($logFile)) {
                        $logContent = file_get_contents($logFile);
                        // Show last 5000 characters
                        if (strlen($logContent) > 5000) {
                            $logContent = '... (showing last 5000 characters) ...' . substr($logContent, -5000);
                        }
                        echo htmlspecialchars($logContent);
                    } else {
                        echo "No debug log file found yet.\n";
                        echo "Debug logs will appear here after SMTP connections are attempted.";
                    }
                    ?>
                </div>
            </div>
        </div>
        
        <!-- Footer -->
        <div class="text-center text-muted py-4">
            <small>
                <i class="bi bi-exclamation-triangle text-warning me-1"></i>
                Remember to delete this file after testing: <code>rm email_debug.php</code>
            </small>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function refreshLog() {
            fetch('email_debug.php?ajax=log')
                .then(response => response.text())
                .then(data => {
                    document.getElementById('debugLog').textContent = data;
                })
                .catch(error => {
                    console.error('Error refreshing log:', error);
                });
        }
        
        // Auto-refresh log every 30 seconds
        setInterval(refreshLog, 30000);
    </script>
</body>
</html>
