<?php
// Add this at the very top for better error reporting
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once '../config/config.php';
require_once '../auth/auth_middleware.php';
require_once '../tcpdf/tcpdf.php';

require_trader();
require_mandate();

$db = getDBConnection();
$success_message = '';
$error_message = '';
$results = [];
$email_stats = [
    'total' => 0,
    'success' => 0,
    'failed' => 0,
    'with_attachment' => 0
];

// Get company details from database
$company_stmt = $db->query("SELECT * FROM companies WHERE status = 'active' ORDER BY id LIMIT 1");
$company = $company_stmt->fetch();
$company_name = $company ? $company['company_name'] : 'Neovam LTD';
$company_phone = $company ? $company['phone'] : '+255 746 177 230';
$company_address = $company ? $company['address'] : 'P.O BOX 36098 Kigamboni, Dar es Salaam';
$company_email = $company ? $company['email'] : 'info@neovam.com';

// Ensure temp directory exists and is writable
$temp_dir = dirname(__FILE__) . '/../temp';
if (!file_exists($temp_dir)) {
    if (!mkdir($temp_dir, 0755, true)) {
        error_log("Failed to create temp directory: $temp_dir");
        $error_message = "Failed to create temporary directory. Please create folder 'temp' manually.";
    } else {
        error_log("Created temp directory: $temp_dir");
    }
}

// Check if temp directory is writable
if (file_exists($temp_dir) && !is_writable($temp_dir)) {
    error_log("Temp directory not writable: $temp_dir");
    $error_message = "Temp directory is not writable. Please set permissions to 755.";
}

// Function to send email with PHPMailer
function sendMarketingEmail($to_email, $to_name, $subject, $html_content, $plain_text, $attachment_path = null, $company_name, $from_email, $footer_image = null) {
    global $db, $company_phone, $company_address;

    require_once '../phpmailer/src/Exception.php';
    require_once '../phpmailer/src/PHPMailer.php';
    require_once '../phpmailer/src/SMTP.php';

    $mail = new PHPMailer\PHPMailer\PHPMailer(true);

    try {
        error_log("=== STARTING MARKETING EMAIL SEND FOR: $to_email ===");
        
        // SMTP Configuration
        $mail->isSMTP();
        $mail->Host = 'mail.neovam.com';
        $mail->SMTPAuth = true;
        $mail->Username = 'info@neovam.com';
        $mail->Password = 'Ernestmswima@12';
        $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;
        $mail->Timeout = 30;
        $mail->SMTPOptions = array(
            'ssl' => array(
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true
            )
        );
        
        // Enable verbose debugging
        $mail->SMTPDebug = 2;
        $mail->Debugoutput = function($str, $level) {
            error_log("PHPMailer debug level $level: $str");
        };
        
        // Sender & recipient
        $mail->setFrom($from_email, $company_name);
        $mail->addAddress($to_email, $to_name);
        $mail->addReplyTo($from_email, $company_name);
        
        // Email content
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->CharSet = 'UTF-8';
        
        // Create full HTML email with header and footer
        $full_html = '
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <style>
                body { 
                    font-family: Arial, sans-serif; 
                    line-height: 1.6; 
                    color: #333; 
                    margin: 0;
                    padding: 0;
                    background-color: #f5f5f5;
                }
                .email-container {
                    max-width: 600px;
                    margin: 0 auto;
                    background: white;
                }
                .email-header {
                    background: linear-gradient(135deg, #1e40af 0%, #3b82f6 100%);
                    color: white;
                    padding: 30px 20px;
                    text-align: center;
                }
                .company-logo {
                    font-size: 28px;
                    font-weight: bold;
                    margin: 0;
                    letter-spacing: 1px;
                }
                .company-tagline {
                    font-size: 14px;
                    opacity: 0.9;
                    margin: 10px 0 0 0;
                }
                .email-content {
                    padding: 30px;
                }
                .greeting {
                    font-size: 18px;
                    font-weight: bold;
                    margin-bottom: 25px;
                    color: #1e40af;
                }
                .email-body {
                    margin: 20px 0;
                    line-height: 1.8;
                }
                .attachment-notice {
                    background: #f0f9ff;
                    border-left: 4px solid #3b82f6;
                    padding: 15px;
                    margin: 25px 0;
                    border-radius: 0 4px 4px 0;
                }
                .attachment-icon {
                    color: #1e40af;
                    font-size: 20px;
                    margin-right: 10px;
                }
                .email-footer {
                    background: #f8fafc;
                    padding: 30px;
                    border-top: 1px solid #e5e7eb;
                    text-align: center;
                }
                .footer-links {
                    margin: 20px 0;
                }
                .footer-link {
                    display: inline-block;
                    margin: 0 15px;
                    color: #4b5563;
                    text-decoration: none;
                    font-size: 14px;
                }
                .footer-link:hover {
                    color: #1e40af;
                    text-decoration: underline;
                }
                .contact-info {
                    font-size: 14px;
                    color: #6b7280;
                    margin: 15px 0;
                }
                .social-icons {
                    margin: 20px 0;
                }
                .social-icon {
                    display: inline-block;
                    width: 36px;
                    height: 36px;
                    line-height: 36px;
                    text-align: center;
                    background: #e5e7eb;
                    color: #4b5563;
                    border-radius: 50%;
                    margin: 0 5px;
                    text-decoration: none;
                    font-size: 14px;
                }
                .social-icon:hover {
                    background: #3b82f6;
                    color: white;
                }
                .footer-image {
                    max-width: 100%;
                    height: auto;
                    margin: 20px 0;
                    border-radius: 8px;
                }
                .unsubscribe {
                    font-size: 12px;
                    color: #9ca3af;
                    margin-top: 20px;
                    padding-top: 15px;
                    border-top: 1px solid #e5e7eb;
                }
                .unsubscribe a {
                    color: #6b7280;
                    text-decoration: none;
                }
                .unsubscribe a:hover {
                    text-decoration: underline;
                }
                .disclaimer {
                    font-size: 11px;
                    color: #9ca3af;
                    margin-top: 15px;
                    line-height: 1.5;
                }
                @media (max-width: 600px) {
                    .email-content {
                        padding: 20px;
                    }
                    .email-footer {
                        padding: 20px;
                    }
                }
            </style>
        </head>
        <body>
            <div class="email-container">
                <div class="email-header">
                    <div class="company-logo">' . htmlspecialchars($company_name) . '</div>
                    <div class="company-tagline">Professional Financial Services</div>
                </div>
                
                <div class="email-content">
                    <div class="greeting">Dear ' . htmlspecialchars($to_name) . ',</div>
                    
                    <div class="email-body">
                        ' . $html_content . '
                    </div>
                    
                    ' . ($attachment_path ? '
                    <div class="attachment-notice">
                        <span class="attachment-icon">📎</span>
                        <strong>Document Attached:</strong> Please find the attached document for your reference.
                    </div>
                    ' : '') . '
                </div>
                
                <div class="email-footer">
                    ' . ($footer_image ? '
                    <div class="footer-image-container">
                        <img src="' . htmlspecialchars($footer_image) . '" alt="Footer Banner" class="footer-image">
                    </div>
                    ' : '') . '
                    
                    <div class="footer-links">
                        <a href="#" class="footer-link">Our Services</a>
                        <a href="#" class="footer-link">Market Updates</a>
                        <a href="#" class="footer-link">Investment Tips</a>
                        <a href="#" class="footer-link">Contact Us</a>
                    </div>
                    
                    <div class="contact-info">
                        <strong>' . htmlspecialchars($company_name) . '</strong><br>
                        ' . htmlspecialchars($company_address) . '<br>
                        📧 ' . htmlspecialchars($from_email) . ' | 📞 ' . htmlspecialchars($company_phone) . '
                    </div>
                    
                    <div class="social-icons">
                        <a href="#" class="social-icon">F</a>
                        <a href="#" class="social-icon">T</a>
                        <a href="#" class="social-icon">L</a>
                        <a href="#" class="social-icon">I</a>
                    </div>
                    
                    <div class="unsubscribe">
                        <p>
                            You are receiving this email because you are a valued client of ' . htmlspecialchars($company_name) . '.<br>
                            <a href="[UNSUBSCRIBE_LINK]">Unsubscribe</a> | 
                            <a href="[PREFERENCES_LINK]">Update Preferences</a> | 
                            <a href="[VIEW_BROWSER_LINK]">View in Browser</a>
                        </p>
                    </div>
                    
                    <div class="disclaimer">
                        <p>
                            This email and any attachments are confidential and intended solely for the use of the individual to whom they are addressed. 
                            If you are not the intended recipient, please delete this email and any attachments immediately.
                        </p>
                        <p>
                            &copy; ' . date('Y') . ' ' . htmlspecialchars($company_name) . '. All rights reserved.
                        </p>
                    </div>
                </div>
            </div>
        </body>
        </html>';
        
        $mail->Body = $full_html;
        $mail->AltBody = $plain_text;

        error_log("Email subject: $subject");
        
        // Add attachment if provided
        $has_attachment = false;
        if ($attachment_path && file_exists($attachment_path)) {
            $file_size = filesize($attachment_path);
            error_log("Attachment exists: $attachment_path ($file_size bytes)");
            
            if ($file_size > 0) {
                error_log("Attaching file...");
                $filename = basename($attachment_path);
                if ($mail->addAttachment($attachment_path, $filename)) {
                    $has_attachment = true;
                    error_log("Attachment added successfully: $filename");
                } else {
                    error_log("Failed to add attachment");
                }
            }
        }

        error_log("Has attachment: " . ($has_attachment ? 'YES' : 'NO'));
        
        // Send email
        error_log("Attempting to send email...");
        
        if ($mail->send()) {
            error_log("Email sent successfully!");
            
            // Log to database
            logMarketingEmailSent($db, $to_email, $to_name, $subject, $has_attachment);
            
            error_log("=== EMAIL SEND COMPLETE FOR: $to_email ===");
            return ['success' => true, 'has_attachment' => $has_attachment];
        } else {
            $error_info = $mail->ErrorInfo;
            error_log("Email failed to send: $error_info");
            error_log("=== EMAIL SEND FAILED FOR: $to_email ===");
            return ['success' => false, 'error' => $error_info];
        }

    } catch (Exception $e) {
        $error = 'PHPMailer exception: ' . $e->getMessage();
        error_log($error);
        error_log("=== EMAIL SEND EXCEPTION FOR: $to_email ===");
        return ['success' => false, 'error' => $error];
    }
}

// Function to log marketing email sending
function logMarketingEmailSent($db, $email, $client_name, $subject, $has_attachment) {
    $stmt = $db->prepare("
        INSERT INTO marketing_emails (email_to, client_name, email_subject, has_attachment, sent_by, sent_at) 
        VALUES (?, ?, ?, ?, ?, NOW())
    ");
    return $stmt->execute([$email, $client_name, $subject, $has_attachment ? 1 : 0, $_SESSION['user_id']]);
}

// Function to handle file upload
function handleFileUpload($file, $temp_dir) {
    if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
        return ['success' => false, 'error' => 'No file uploaded or upload error'];
    }
    
    // Check file size (max 10MB)
    $max_size = 10 * 1024 * 1024; // 10MB
    if ($file['size'] > $max_size) {
        return ['success' => false, 'error' => 'File size exceeds 10MB limit'];
    }
    
    // Check file type
    $allowed_types = [
        'pdf' => 'application/pdf',
        'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'xls' => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'txt' => 'text/plain'
    ];
    
    $file_info = pathinfo($file['name']);
    $extension = strtolower($file_info['extension']);
    
    if (!array_key_exists($extension, $allowed_types)) {
        return ['success' => false, 'error' => 'File type not allowed'];
    }
    
    // Generate unique filename
    $filename = uniqid('attachment_', true) . '.' . $extension;
    $filepath = $temp_dir . '/' . $filename;
    
    // Move uploaded file
    if (move_uploaded_file($file['tmp_name'], $filepath)) {
        return ['success' => true, 'filepath' => $filepath, 'filename' => $filename];
    } else {
        return ['success' => false, 'error' => 'Failed to move uploaded file'];
    }
}

// Function to get image URL for footer
function getFooterImage($type) {
    $images = [
        'market_update' => 'https://images.unsplash.com/photo-1611974789855-9c2a0a7236a3?w=600&h=200&fit=crop',
        'promotion' => 'https://images.unsplash.com/photo-1556742049-0cfed4f6a45d?w=600&h=200&fit=crop',
        'newsletter' => 'https://images.unsplash.com/photo-1542744095-fcf48d80b0fd?w=600&h=200&fit=crop',
        'event' => 'https://images.unsplash.com/photo-1540575467063-178a50c2df87?w=600&h=200&fit=crop',
        'default' => 'https://images.unsplash.com/photo-1553877522-43269d4ea984?w=600&h=200&fit=crop'
    ];
    
    return $images[$type] ?? $images['default'];
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    error_log("=== MARKETING EMAIL FORM SUBMISSION DETECTED ===");
    
    if (isset($_POST['send_marketing_emails'])) {
        $client_ids = $_POST['client_ids'] ?? [];
        $email_subject = trim($_POST['email_subject']);
        $email_html = trim($_POST['email_html']);
        $email_plain = trim($_POST['email_plain']);
        $footer_image_type = $_POST['footer_image_type'] ?? 'none';
        
        error_log("Client IDs count: " . count($client_ids));
        error_log("Email subject: $email_subject");
        
        // Validate required fields
        if (empty($client_ids)) {
            $error_message = 'Please select at least one client.';
            error_log("ERROR: No clients selected");
        } elseif (empty($email_subject) || empty($email_html)) {
            $error_message = 'Please fill in all required fields (Subject and HTML Content).';
            error_log("ERROR: Missing required fields");
        } else {
            // Handle file upload if present
            $attachment_path = null;
            if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] === UPLOAD_ERR_OK) {
                $upload_result = handleFileUpload($_FILES['attachment'], $temp_dir);
                if ($upload_result['success']) {
                    $attachment_path = $upload_result['filepath'];
                    error_log("Attachment uploaded: " . $upload_result['filename']);
                } else {
                    $error_message = 'File upload failed: ' . $upload_result['error'];
                    error_log("ERROR: " . $upload_result['error']);
                }
            }
            
            // Get footer image URL if needed
            $footer_image_url = null;
            if ($footer_image_type !== 'none') {
                $footer_image_url = getFooterImage($footer_image_type);
                error_log("Using footer image: $footer_image_url");
            }
            
            if (empty($error_message)) {
                // Get selected clients from database
                $placeholders = str_repeat('?,', count($client_ids) - 1) . '?';
                $stmt = $db->prepare("
                    SELECT id, client_name, email, cds_account, client_type
                    FROM clients 
                    WHERE id IN ($placeholders) 
                    AND status = 'active'
                    AND is_active = 1
                    ORDER BY client_name
                ");
                
                $stmt->execute($client_ids);
                $selected_clients = $stmt->fetchAll();
                
                $email_stats['total'] = count($selected_clients);
                
                if (empty($selected_clients)) {
                    $error_message = 'No valid clients found.';
                    error_log("ERROR: No valid clients found");
                } else {
                    error_log("Found " . count($selected_clients) . " clients to send emails to");
                    
                    // Send to selected clients
                    error_log("Sending marketing emails to selected clients");
                    
                    $batch_size = 50; // Send 50 emails at a time to avoid timeout
                    $total_clients = count($selected_clients);
                    $processed = 0;
                    
                    foreach ($selected_clients as $client) {
                        if (empty($client['email'])) {
                            $results[] = [
                                'status' => 'error',
                                'client' => $client['client_name'],
                                'message' => 'No email address'
                            ];
                            $email_stats['failed']++;
                            continue;
                        }
                        
                        // Personalize content with client name
                        $personalized_html = str_replace('{client_name}', $client['client_name'], $email_html);
                        $personalized_plain = str_replace('{client_name}', $client['client_name'], $email_plain);
                        
                        $result = sendMarketingEmail(
                            $client['email'],
                            $client['client_name'],
                            $email_subject,
                            $personalized_html,
                            $personalized_plain,
                            $attachment_path,
                            $company_name,
                            $company_email,
                            $footer_image_url
                        );
                        
                        if ($result['success']) {
                            $results[] = [
                                'status' => 'success',
                                'client' => $client['client_name'],
                                'email' => $client['email'],
                                'message' => 'Email sent successfully'
                            ];
                            $email_stats['success']++;
                            if ($result['has_attachment']) {
                                $email_stats['with_attachment']++;
                            }
                        } else {
                            $results[] = [
                                'status' => 'error',
                                'client' => $client['client_name'],
                                'message' => 'Failed to send email'
                            ];
                            $email_stats['failed']++;
                        }
                        
                        $processed++;
                        
                        // Log progress
                        if ($processed % $batch_size === 0) {
                            error_log("Processed $processed of $total_clients clients");
                            // Small delay to avoid overwhelming the server
                            usleep(500000); // 0.5 second delay
                        }
                    }
                    
                    // Create summary message
                    $success_message = "Email campaign completed: ";
                    $success_message .= $email_stats['success'] . " emails sent successfully";
                    if ($email_stats['failed'] > 0) {
                        $success_message .= ", " . $email_stats['failed'] . " failed";
                    }
                    if ($email_stats['with_attachment'] > 0) {
                        $success_message .= ", " . $email_stats['with_attachment'] . " with attachment";
                    }
                    
                    error_log("=== EMAIL CAMPAIGN COMPLETE ===");
                    error_log("Total: " . $email_stats['total']);
                    error_log("Success: " . $email_stats['success']);
                    error_log("Failed: " . $email_stats['failed']);
                    error_log("With attachment: " . $email_stats['with_attachment']);
                    
                    // Clean up attachment file
                    if ($attachment_path && file_exists($attachment_path)) {
                        if (unlink($attachment_path)) {
                            error_log("Cleaned up attachment file: $attachment_path");
                        }
                    }
                }
            }
        }
    }
}

// Apply filters from POST or GET
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['apply_filters'])) {
    $filters = $_POST;
} elseif (isset($_GET['client_name']) || isset($_GET['client_type']) || isset($_GET['cds_account'])) {
    $filters = $_GET;
} else {
    $filters = [];
}

// Get all active clients for selection
$where_conditions = ["c.status = 'active'", "c.is_active = 1"];
$params = [];

if (isset($filters['client_name']) && !empty($filters['client_name'])) {
    $where_conditions[] = "c.client_name LIKE ?";
    $params[] = '%' . $filters['client_name'] . '%';
}

if (isset($filters['client_type']) && !empty($filters['client_type'])) {
    $where_conditions[] = "c.client_type = ?";
    $params[] = $filters['client_type'];
}

if (isset($filters['cds_account']) && !empty($filters['cds_account'])) {
    $where_conditions[] = "c.cds_account LIKE ?";
    $params[] = '%' . $filters['cds_account'] . '%';
}

$where_clause = !empty($where_conditions) ? "WHERE " . implode(' AND ', $where_conditions) : "";

$clients_stmt = $db->prepare("
    SELECT 
        c.id, 
        c.client_name, 
        c.email, 
        c.cds_account, 
        c.client_type, 
        c.phone,
        c.national_id,
        c.address,
        c.fee_type,
        c.default_brokerage_fee,
        (SELECT COUNT(*) FROM trades t WHERE t.client_cds_account = c.cds_account AND t.status = 'active') as trade_count
    FROM clients c
    $where_clause
    ORDER BY c.client_name
    LIMIT 1000
");

$clients_stmt->execute($params);
$all_clients = $clients_stmt->fetchAll();

// Get email statistics for dashboard
$stats_stmt = $db->query("
    SELECT 
        COUNT(*) as total_sent,
        SUM(CASE WHEN has_attachment = 1 THEN 1 ELSE 0 END) as with_attachment,
        DATE(sent_at) as send_date
    FROM marketing_emails 
    WHERE sent_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    GROUP BY DATE(sent_at)
    ORDER BY send_date DESC
    LIMIT 7
");
$recent_stats = $stats_stmt->fetchAll();

// Get total client count
$client_count_stmt = $db->query("
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN email IS NOT NULL AND email != '' THEN 1 ELSE 0 END) as with_email
    FROM clients 
    WHERE status = 'active' AND is_active = 1
");
$client_counts = $client_count_stmt->fetch();

$page_title = 'Marketing Email Campaign';
include '../includes/header.php';
?>

<div class="container-fluid py-4">
    <div class="row mb-4">
        <div class="col-12">
            <div class="page-header">
                <h1 class="mb-2"><i class="bi bi-megaphone me-2"></i>Marketing Email Campaign</h1>
                <p class="text-muted">Send emails to selected clients with customizable content and attachments</p>
            </div>
        </div>
    </div>

    <?php if ($success_message): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="bi bi-check-circle-fill me-2"></i>
            <?php echo $success_message; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if ($error_message): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="bi bi-exclamation-triangle-fill me-2"></i>
            <?php echo $error_message; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="row">
        <!-- Left Column: Client Selection with Filters -->
        <div class="col-lg-8">
            <!-- Filters Card -->
            <div class="card mb-4">
                <div class="card-header bg-transparent border-bottom">
                    <h5 class="mb-0"><i class="bi bi-funnel me-2"></i>Filter Clients</h5>
                </div>
                <div class="card-body">
                    <form method="POST" action="" id="filterForm">
                        <input type="hidden" name="apply_filters" value="1">
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label for="client_name" class="form-label">Client Name</label>
                                <input type="text" class="form-control" id="client_name" name="client_name" 
                                       value="<?php echo $filters['client_name'] ?? ''; ?>" 
                                       placeholder="Search by name...">
                            </div>
                            
                            <div class="col-md-4">
                                <label for="cds_account" class="form-label">CDS Account</label>
                                <input type="text" class="form-control" id="cds_account" name="cds_account" 
                                       value="<?php echo $filters['cds_account'] ?? ''; ?>" 
                                       placeholder="Search by CDS...">
                            </div>
                            
                            <div class="col-md-4">
                                <label for="client_type" class="form-label">Client Type</label>
                                <select class="form-select" id="client_type" name="client_type">
                                    <option value="">All Types</option>
                                    <option value="individual" <?php echo isset($filters['client_type']) && $filters['client_type'] == 'individual' ? 'selected' : ''; ?>>Individual</option>
                                    <option value="corporate" <?php echo isset($filters['client_type']) && $filters['client_type'] == 'corporate' ? 'selected' : ''; ?>>Corporate</option>
                                    <option value="institutional" <?php echo isset($filters['client_type']) && $filters['client_type'] == 'institutional' ? 'selected' : ''; ?>>Institutional</option>
                                </select>
                            </div>
                            
                            <div class="col-md-12">
                                <div class="d-flex justify-content-between">
                                    <button type="submit" name="apply_filters" class="btn btn-primary">
                                        <i class="bi bi-funnel me-1"></i>
                                        Apply Filters
                                    </button>
                                    <button type="button" class="btn btn-outline-secondary" onclick="resetFilters()">
                                        <i class="bi bi-arrow-clockwise me-1"></i>
                                        Reset Filters
                                    </button>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
            
            <!-- Clients List Card -->
            <div class="card mb-4">
                <div class="card-header bg-transparent border-bottom">
                    <div class="d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><i class="bi bi-people me-2"></i>Select Clients</h5>
                        <div class="d-flex align-items-center gap-2">
                            <span class="badge bg-primary" id="selectedClientsCount">0</span>
                            <button type="button" class="btn btn-sm btn-outline-primary" onclick="selectAllWithEmail()">
                                <i class="bi bi-check-all"></i> Select All With Email
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="deselectAll()">
                                <i class="bi bi-x-circle"></i> Clear All
                            </button>
                        </div>
                    </div>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($all_clients)): ?>
                        <div class="text-center py-5">
                            <i class="bi bi-people text-muted" style="font-size: 3rem;"></i>
                            <h5 class="mt-3">No clients found</h5>
                            <p class="text-muted">Try adjusting your filters</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive" style="max-height: 500px; overflow-y: auto;">
                            <table class="table table-hover mb-0">
                                <thead style="position: sticky; top: 0; background: white; z-index: 1;">
                                    <tr>
                                        <th width="50">
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" id="selectAllHeader">
                                            </div>
                                        </th>
                                        <th>Client Details</th>
                                        <th>Contact Information</th>
                                        <th>Account Information</th>
                                        <th>Trades</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($all_clients as $client): 
                                        $has_email = !empty($client['email']);
                                    ?>
                                        <tr class="client-row <?php echo !$has_email ? 'table-warning' : ''; ?>">
                                            <td>
                                                <div class="form-check">
                                                    <input class="form-check-input client-checkbox" type="checkbox" 
                                                           name="client_ids[]" value="<?php echo $client['id']; ?>"
                                                           id="client_<?php echo $client['id']; ?>"
                                                           data-client="<?php echo htmlspecialchars($client['client_name']); ?>"
                                                           data-email="<?php echo htmlspecialchars($client['email'] ?? ''); ?>"
                                                           <?php echo !$has_email ? 'disabled' : ''; ?>>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="fw-medium"><?php echo htmlspecialchars($client['client_name']); ?></div>
                                                <div class="small text-muted">
                                                    <?php if (!empty($client['national_id'])): ?>
                                                        <span>ID: <?php echo htmlspecialchars($client['national_id']); ?></span>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="small">
                                                    CDS: <?php echo htmlspecialchars($client['cds_account']); ?>
                                                </div>
                                            </td>
                                            <td>
                                                <?php if ($has_email): ?>
                                                    <div class="small">
                                                        <i class="bi bi-envelope-check text-success"></i>
                                                        <?php echo htmlspecialchars($client['email']); ?>
                                                    </div>
                                                <?php else: ?>
                                                    <div class="small">
                                                        <i class="bi bi-envelope-slash text-danger"></i>
                                                        <span class="text-danger">No email</span>
                                                    </div>
                                                <?php endif; ?>
                                                <?php if (!empty($client['phone'])): ?>
                                                    <div class="small text-muted">
                                                        <i class="bi bi-telephone"></i>
                                                        <?php echo htmlspecialchars($client['phone']); ?>
                                                    </div>
                                                <?php endif; ?>
                                                <?php if (!empty($client['address'])): ?>
                                                    <div class="small text-muted">
                                                        <i class="bi bi-geo-alt"></i>
                                                        <?php echo htmlspecialchars(substr($client['address'], 0, 50)); ?>...
                                                    </div>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div class="small">
                                                    <span class="badge bg-<?php 
                                                        switch($client['client_type']) {
                                                            case 'individual': echo 'primary'; break;
                                                            case 'corporate': echo 'success'; break;
                                                            case 'institutional': echo 'info'; break;
                                                            default: echo 'secondary';
                                                        }
                                                    ?>">
                                                        <?php echo ucfirst($client['client_type']); ?>
                                                    </span>
                                                </div>
                                                <?php if (!empty($client['fee_type'])): ?>
                                                    <div class="small text-muted mt-1">
                                                        Fee: <?php echo ucfirst(str_replace('_', ' ', $client['fee_type'])); ?>
                                                        <?php if (!empty($client['default_brokerage_fee'])): ?>
                                                            (<?php echo number_format($client['default_brokerage_fee'], 2); ?>%)
                                                        <?php endif; ?>
                                                    </div>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <span class="badge bg-<?php echo $client['trade_count'] > 0 ? 'info' : 'secondary'; ?>">
                                                    <?php echo $client['trade_count']; ?> trades
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <!-- Right Column: Email Composition -->
        <div class="col-lg-4">
            <form method="POST" action="" enctype="multipart/form-data" id="emailForm">
                <input type="hidden" name="send_marketing_emails" value="1">
                <div class="card sticky-top" style="top: 20px;">
                    <div class="card-header bg-transparent border-bottom">
                        <h5 class="mb-0"><i class="bi bi-pencil-square me-2"></i>Email Composition</h5>
                    </div>
                    <div class="card-body">
                        <div class="mb-4">
                            <h6 class="mb-3">Selected Summary</h6>
                            <div class="row g-2 mb-3">
                                <div class="col-6">
                                    <div class="border rounded p-2 text-center">
                                        <div class="fw-bold fs-5 text-primary" id="summaryCount">0</div>
                                        <small class="text-muted">Clients</small>
                                    </div>
                                </div>
                                <div class="col-6">
                                    <div class="border rounded p-2 text-center">
                                        <div class="fw-bold fs-5 text-success" id="summaryEmails">0</div>
                                        <small class="text-muted">With Email</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="email_subject" class="form-label">
                                <i class="bi bi-tag me-1"></i>Email Subject <span class="text-danger">*</span>
                            </label>
                            <input type="text" class="form-control" id="email_subject" name="email_subject" 
                                   value="<?php echo htmlspecialchars($_POST['email_subject'] ?? ''); ?>" 
                                   placeholder="Enter email subject..." required>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">
                                <i class="bi bi-image me-1"></i>Footer Image
                            </label>
                            <select class="form-select" name="footer_image_type" id="footer_image_type">
                                <option value="none" <?php echo ($_POST['footer_image_type'] ?? 'none') === 'none' ? 'selected' : ''; ?>>No Footer Image</option>
                                <option value="market_update" <?php echo ($_POST['footer_image_type'] ?? '') === 'market_update' ? 'selected' : ''; ?>>Market Update Banner</option>
                                <option value="promotion" <?php echo ($_POST['footer_image_type'] ?? '') === 'promotion' ? 'selected' : ''; ?>>Promotional Banner</option>
                                <option value="newsletter" <?php echo ($_POST['footer_image_type'] ?? '') === 'newsletter' ? 'selected' : ''; ?>>Newsletter Banner</option>
                                <option value="event" <?php echo ($_POST['footer_image_type'] ?? '') === 'event' ? 'selected' : ''; ?>>Event Banner</option>
                                <option value="default" <?php echo ($_POST['footer_image_type'] ?? '') === 'default' ? 'selected' : ''; ?>>Default Banner</option>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label for="attachment" class="form-label">
                                <i class="bi bi-paperclip me-1"></i>Attachment (Optional)
                            </label>
                            <input type="file" class="form-control" id="attachment" name="attachment" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.gif,.xls,.xlsx,.txt">
                            <small class="form-text text-muted">Max file size: 10MB. Allowed types: PDF, DOC, DOCX, JPG, PNG, GIF, XLS, XLSX, TXT</small>
                        </div>
                        
                        <div class="mb-3">
                            <label for="email_html" class="form-label">
                                <i class="bi bi-window-sidebar me-1"></i>HTML Content <span class="text-danger">*</span>
                            </label>
                            <textarea class="form-control" id="email_html" name="email_html" rows="6" required 
                                      placeholder="Write your HTML email content here... Use {client_name} as placeholder."><?php echo htmlspecialchars($_POST['email_html'] ?? ''); ?></textarea>
                            <small class="form-text text-muted">
                                Use {client_name} as placeholder for client's name.
                            </small>
                        </div>
                        
                        <div class="mb-3">
                            <label for="email_plain" class="form-label">
                                <i class="bi bi-text-paragraph me-1"></i>Plain Text Content (Optional)
                            </label>
                            <textarea class="form-control" id="email_plain" name="email_plain" rows="4" 
                                      placeholder="Plain text version (auto-generated if empty)..."><?php echo htmlspecialchars($_POST['email_plain'] ?? ''); ?></textarea>
                            <button type="button" class="btn btn-outline-secondary btn-sm mt-1" onclick="generatePlainText()">
                                <i class="bi bi-magic me-1"></i>Generate from HTML
                            </button>
                        </div>
                        
                        <div class="border rounded p-3 mb-3">
                            <h6><i class="bi bi-lightbulb me-1"></i>Quick Templates</h6>
                            <div class="btn-group flex-wrap" role="group">
                                <button type="button" class="btn btn-outline-primary btn-sm mb-1" onclick="loadTemplate('market_update')">
                                    Market Update
                                </button>
                                <button type="button" class="btn btn-outline-primary btn-sm mb-1" onclick="loadTemplate('promotion')">
                                    Promotion
                                </button>
                                <button type="button" class="btn btn-outline-primary btn-sm mb-1" onclick="loadTemplate('newsletter')">
                                    Newsletter
                                </button>
                                <button type="button" class="btn btn-outline-primary btn-sm mb-1" onclick="loadTemplate('event')">
                                    Event
                                </button>
                            </div>
                        </div>
                        
                        <div class="mt-4 pt-3 border-top">
                            <div class="d-grid gap-2">
                                <button type="submit" class="btn btn-primary btn-lg" id="sendButton" disabled>
                                    <i class="bi bi-send-check me-2"></i>
                                    Send to Selected Clients
                                </button>
                                <button type="button" class="btn btn-outline-secondary" onclick="previewSelection()">
                                    <i class="bi bi-eye me-2"></i>
                                    Preview Selection
                                </button>
                                <button type="button" class="btn btn-outline-info" onclick="previewEmail()">
                                    <i class="bi bi-envelope me-2"></i>
                                    Preview Email
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </form>
            
            <!-- Results Card (shown after sending) -->
            <?php if (!empty($results)): ?>
                <div class="card mt-4">
                    <div class="card-header bg-transparent border-bottom">
                        <div class="d-flex justify-content-between align-items-center">
                            <h6 class="mb-0"><i class="bi bi-list-check me-2"></i>Sending Results</h6>
                            <div>
                                <span class="badge bg-success"><?php echo $email_stats['success']; ?> Success</span>
                                <span class="badge bg-danger"><?php echo $email_stats['failed']; ?> Failed</span>
                            </div>
                        </div>
                    </div>
                    <div class="card-body p-0">
                        <div class="list-group list-group-flush">
                            <?php foreach ($results as $result): ?>
                                <div class="list-group-item">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <h6 class="mb-0"><?php echo htmlspecialchars($result['client']); ?></h6>
                                            <?php if (isset($result['email'])): ?>
                                                <small class="text-muted"><?php echo htmlspecialchars($result['email']); ?></small>
                                            <?php endif; ?>
                                        </div>
                                        <span class="badge bg-<?php echo $result['status'] === 'success' ? 'success' : 'danger'; ?>">
                                            <?php echo $result['status'] === 'success' ? 'Sent' : 'Failed'; ?>
                                        </span>
                                    </div>
                                    <small class="text-muted d-block mt-1"><?php echo htmlspecialchars($result['message']); ?></small>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
            
            <!-- Statistics Card -->
            <div class="card mt-4">
                <div class="card-header bg-transparent border-bottom">
                    <h6 class="mb-0"><i class="bi bi-graph-up me-2"></i>Recent Campaigns</h6>
                </div>
                <div class="card-body">
                    <?php if (empty($recent_stats)): ?>
                        <div class="text-center py-3">
                            <i class="bi bi-inbox text-muted" style="font-size: 2rem;"></i>
                            <p class="text-muted mt-2 mb-0">No recent campaigns</p>
                        </div>
                    <?php else: ?>
                        <div class="list-group list-group-flush">
                            <?php foreach ($recent_stats as $stat): ?>
                                <div class="list-group-item border-0 px-0 py-2">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <small class="text-muted"><?php echo date('M d', strtotime($stat['send_date'])); ?></small>
                                            <div class="fw-medium"><?php echo $stat['total_sent']; ?> emails sent</div>
                                        </div>
                                        <?php if ($stat['with_attachment'] > 0): ?>
                                            <span class="badge bg-info"><?php echo $stat['with_attachment']; ?> with attachment</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Preview Modal -->
<div class="modal fade" id="previewModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Client Selection Preview</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="previewContent"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Email Preview Modal -->
<div class="modal fade" id="emailPreviewModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Email Preview</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="emailPreviewContent"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<style>
.client-row:hover {
    background-color: #f8f9fa;
}

.sticky-top {
    z-index: 100;
}

#sendButton:disabled {
    opacity: 0.6;
    cursor: not-allowed;
}

.table-responsive::-webkit-scrollbar {
    width: 8px;
    height: 8px;
}

.table-responsive::-webkit-scrollbar-track {
    background: #f1f1f1;
    border-radius: 4px;
}

.table-responsive::-webkit-scrollbar-thumb {
    background: #c1c1c1;
    border-radius: 4px;
}

.table-responsive::-webkit-scrollbar-thumb:hover {
    background: #a8a8a8;
}

.no-email-row {
    background-color: #fff3cd !important;
}

.no-email-row:hover {
    background-color: #ffeaa7 !important;
}

#email_html {
    font-family: 'Courier New', monospace;
    font-size: 14px;
}

.template-btn {
    margin: 2px;
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Select all header checkbox
    const selectAllHeader = document.getElementById('selectAllHeader');
    const clientCheckboxes = document.querySelectorAll('.client-checkbox:not(:disabled)');
    
    selectAllHeader.addEventListener('change', function() {
        clientCheckboxes.forEach(checkbox => {
            checkbox.checked = this.checked;
        });
        updateSummary();
    });
    
    // Individual checkbox change
    clientCheckboxes.forEach(checkbox => {
        checkbox.addEventListener('change', updateSummary);
    });
    
    // Update summary statistics
    function updateSummary() {
        const selectedCheckboxes = document.querySelectorAll('.client-checkbox:checked');
        const selectedClients = Array.from(selectedCheckboxes);
        
        // Count clients with email
        const clientsWithEmail = new Set();
        
        selectedClients.forEach(checkbox => {
            const hasEmail = checkbox.dataset.email !== '';
            if (hasEmail) {
                clientsWithEmail.add(checkbox.dataset.client);
            }
        });
        
        // Update counters
        document.getElementById('selectedClientsCount').textContent = selectedClients.length;
        document.getElementById('summaryCount').textContent = selectedClients.length;
        document.getElementById('summaryEmails').textContent = clientsWithEmail.size;
        
        // Update button state
        const sendButton = document.getElementById('sendButton');
        const hasEmailClients = selectedClients.some(cb => cb.dataset.email !== '');
        sendButton.disabled = selectedClients.length === 0 || !hasEmailClients;
        
        // Update select all header state
        const allCheckboxes = document.querySelectorAll('.client-checkbox:not(:disabled)');
        const allChecked = allCheckboxes.length > 0 && 
                          selectedClients.length === allCheckboxes.length;
        selectAllHeader.checked = allChecked;
        selectAllHeader.indeterminate = selectedClients.length > 0 && selectedClients.length < allCheckboxes.length;
    }
    
    // Select all clients with email
    window.selectAllWithEmail = function() {
        clientCheckboxes.forEach(checkbox => {
            checkbox.checked = true;
        });
        updateSummary();
    };
    
    // Deselect all clients
    window.deselectAll = function() {
        clientCheckboxes.forEach(checkbox => {
            checkbox.checked = false;
        });
        updateSummary();
    };
    
    // Reset filters
    window.resetFilters = function() {
        document.getElementById('filterForm').reset();
        document.getElementById('filterForm').submit();
    };
    
    // Load template function
    window.loadTemplate = function(templateType) {
        const templates = {
            'market_update': `<h3>📈 Market Update</h3>
<p>Dear {client_name},</p>
<p>We hope this email finds you well. Here's the latest market update for your portfolio:</p>
<ul>
    <li><strong>Market Performance:</strong> The DSE All Share Index closed at...</li>
    <li><strong>Key Developments:</strong> Recent regulatory changes have...</li>
    <li><strong>Investment Opportunities:</strong> We've identified several...</li>
</ul>
<p>Our team is monitoring the markets closely and will keep you updated on any significant developments.</p>
<p>Best regards,<br>
<strong>Investment Team</strong></p>`,
            
            'promotion': `<h3>🎁 Special Promotion</h3>
<p>Dear {client_name},</p>
<p>As a valued client, we're excited to offer you an exclusive promotion!</p>
<p><strong>Special Offer:</strong> For a limited time, enjoy reduced brokerage fees on all trades placed through our platform.</p>
<p><strong>Benefits:</strong></p>
<ul>
    <li>25% off standard brokerage rates</li>
    <li>Priority trade execution</li>
    <li>Personalized portfolio review</li>
</ul>
<p>This offer is valid until the end of the month. Contact your relationship manager to learn more.</p>
<p>Warm regards,<br>
<strong>Client Services Team</strong></p>`,
            
            'newsletter': `<h3>📰 Monthly Newsletter</h3>
<p>Dear {client_name},</p>
<p>Welcome to our monthly newsletter! Here's what's happening this month:</p>
<h4>Market Insights</h4>
<p>Our analysts provide in-depth analysis of current market trends and opportunities.</p>
<h4>Upcoming Events</h4>
<ul>
    <li>Investment Seminar: "Navigating Volatile Markets" - Date TBA</li>
    <li>Quarterly Portfolio Review Sessions - Book your slot now</li>
</ul>
<h4>Client Success Story</h4>
<p>Read about how our client achieved their financial goals with our guidance.</p>
<p>Stay connected with us for more updates!</p>
<p>Best regards,<br>
<strong>Newsletter Team</strong></p>`,
            
            'event': `<h3>🎉 Event Invitation</h3>
<p>Dear {client_name},</p>
<p>You're cordially invited to our exclusive client event!</p>
<p><strong>Event Details:</strong></p>
<ul>
    <li><strong>Date:</strong> [Event Date]</li>
    <li><strong>Time:</strong> [Event Time]</li>
    <li><strong>Venue:</strong> [Event Venue]</li>
    <li><strong>Theme:</strong> "Future of Investing in Tanzania"</li>
</ul>
<p>Join us for an evening of networking, insights from industry experts, and refreshments.</p>
<p><strong>RSVP:</strong> Please confirm your attendance by [RSVP Date] by replying to this email.</p>
<p>We look forward to seeing you there!</p>
<p>Warm regards,<br>
<strong>Events Team</strong></p>`
        };
        
        document.getElementById('email_html').value = templates[templateType] || '';
        
        // Update subject based on template
        const subjectMap = {
            'market_update': 'Market Update - ' + new Date().toLocaleDateString('en-US', { month: 'long', year: 'numeric' }),
            'promotion': 'Special Promotion for Valued Clients',
            'newsletter': 'Monthly Newsletter - ' + new Date().toLocaleDateString('en-US', { month: 'long', year: 'numeric' }),
            'event': 'Invitation: Client Exclusive Event'
        };
        
        document.getElementById('email_subject').value = subjectMap[templateType] || '';
        
        // Update footer image
        document.getElementById('footer_image_type').value = templateType;
        
        alert('Template loaded successfully! Remember to customize it for your needs.');
    };
    
    // Generate plain text from HTML
    window.generatePlainText = function() {
        const htmlContent = document.getElementById('email_html').value;
        
        // Simple HTML to plain text conversion
        let plainText = htmlContent
            .replace(/<[^>]*>/g, '') // Remove HTML tags
            .replace(/\s+/g, ' ') // Replace multiple spaces with single space
            .replace(/&nbsp;/g, ' ') // Replace HTML spaces
            .replace(/&amp;/g, '&') // Replace HTML entities
            .replace(/&lt;/g, '<')
            .replace(/&gt;/g, '>')
            .replace(/&quot;/g, '"')
            .replace(/&#39;/g, "'")
            .trim();
        
        document.getElementById('email_plain').value = plainText;
        alert('Plain text generated from HTML content!');
    };
    
    // Preview client selection
    window.previewSelection = function() {
        const selectedCheckboxes = document.querySelectorAll('.client-checkbox:checked');
        if (selectedCheckboxes.length === 0) {
            alert('Please select at least one client.');
            return;
        }
        
        // Group clients by email status
        const clientsWithEmail = [];
        const clientsWithoutEmail = [];
        
        selectedCheckboxes.forEach(checkbox => {
            const clientName = checkbox.dataset.client;
            const hasEmail = checkbox.dataset.email !== '';
            
            if (hasEmail) {
                clientsWithEmail.push({
                    name: clientName,
                    email: checkbox.dataset.email
                });
            } else {
                clientsWithoutEmail.push({
                    name: clientName
                });
            }
        });
        
        const previewContent = document.getElementById('previewContent');
        let html = `
            <div class="alert alert-info">
                <i class="bi bi-info-circle me-2"></i>
                <strong>Preview:</strong> ${selectedCheckboxes.length} clients selected.
            </div>
        `;
        
        // Show clients with email
        if (clientsWithEmail.length > 0) {
            html += `
                <div class="card mb-3 border-success">
                    <div class="card-header bg-success text-white">
                        <h6 class="mb-0"><i class="bi bi-envelope-check me-2"></i>Clients With Email (${clientsWithEmail.length})</h6>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-sm">
                                <thead>
                                    <tr>
                                        <th>Client Name</th>
                                        <th>Email Address</th>
                                    </tr>
                                </thead>
                                <tbody>
            `;
            
            clientsWithEmail.forEach(client => {
                html += `
                    <tr>
                        <td>${client.name}</td>
                        <td><small>${client.email}</small></td>
                    </tr>
                `;
            });
            
            html += `
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            `;
        }
        
        // Show clients without email
        if (clientsWithoutEmail.length > 0) {
            html += `
                <div class="card border-warning">
                    <div class="card-header bg-warning text-dark">
                        <h6 class="mb-0"><i class="bi bi-exclamation-triangle me-2"></i>Clients Without Email (${clientsWithoutEmail.length})</h6>
                    </div>
                    <div class="card-body">
                        <div class="alert alert-warning">
                            <i class="bi bi-exclamation-triangle me-2"></i>
                            These clients will not receive emails.
                        </div>
                        <div class="table-responsive">
                            <table class="table table-sm">
                                <thead>
                                    <tr>
                                        <th>Client Name</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
            `;
            
            clientsWithoutEmail.forEach(client => {
                html += `
                    <tr>
                        <td>${client.name}</td>
                        <td><span class="badge bg-danger">No Email</span></td>
                    </tr>
                `;
            });
            
            html += `
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            `;
        }
        
        previewContent.innerHTML = html;
        
        const modal = new bootstrap.Modal(document.getElementById('previewModal'));
        modal.show();
    };
    
    // Preview email
    window.previewEmail = function() {
        const htmlContent = document.getElementById('email_html').value;
        const subject = document.getElementById('email_subject').value;
        const footerImage = document.getElementById('footer_image_type').value;
        
        if (!htmlContent.trim()) {
            alert('Please enter some HTML content first.');
            return;
        }
        
        // Create preview content
        const emailPreviewContent = document.getElementById('emailPreviewContent');
        
        // Create email structure
        let previewHTML = `
            <div class="email-container" style="max-width: 600px; margin: 0 auto; border: 1px solid #dee2e6; border-radius: 8px; overflow: hidden;">
                <div style="background: linear-gradient(135deg, #1e40af 0%, #3b82f6 100%); color: white; padding: 30px 20px; text-align: center;">
                    <div style="font-size: 28px; font-weight: bold; margin: 0; letter-spacing: 1px;"><?php echo htmlspecialchars($company_name); ?></div>
                    <div style="font-size: 14px; opacity: 0.9; margin: 10px 0 0 0;">Professional Financial Services</div>
                </div>
                
                <div style="padding: 30px;">
                    <div style="font-size: 18px; font-weight: bold; margin-bottom: 25px; color: #1e40af;">Dear Test Client,</div>
                    
                    <div style="margin: 20px 0; line-height: 1.8;">
                        ${htmlContent.replace(/{client_name}/g, 'Test Client')}
                    </div>
                </div>
        `;
        
        // Add footer image if selected
        if (footerImage !== 'none') {
            const imageUrls = {
                'market_update': 'https://images.unsplash.com/photo-1611974789855-9c2a0a7236a3?w=600&h=200&fit=crop',
                'promotion': 'https://images.unsplash.com/photo-1556742049-0cfed4f6a45d?w=600&h=200&fit=crop',
                'newsletter': 'https://images.unsplash.com/photo-1542744095-fcf48d80b0fd?w=600&h=200&fit=crop',
                'event': 'https://images.unsplash.com/photo-1540575467063-178a50c2df87?w=600&h=200&fit=crop',
                'default': 'https://images.unsplash.com/photo-1553877522-43269d4ea984?w=600&h=200&fit=crop'
            };
            
            previewHTML += `
                <div style="background: #f8fafc; padding: 30px; border-top: 1px solid #e5e7eb; text-align: center;">
                    <div style="margin-bottom: 20px;">
                        <img src="${imageUrls[footerImage] || imageUrls['default']}" alt="Footer Banner" style="max-width: 100%; height: auto; border-radius: 8px;">
                    </div>
            `;
        } else {
            previewHTML += `
                <div style="background: #f8fafc; padding: 30px; border-top: 1px solid #e5e7eb; text-align: center;">
            `;
        }
        
        previewHTML += `
                    <div style="font-size: 14px; color: #6b7280; margin: 15px 0;">
                        <strong><?php echo htmlspecialchars($company_name); ?></strong><br>
                        <?php echo htmlspecialchars($company_address); ?><br>
                        📧 <?php echo htmlspecialchars($company_email); ?> | 📞 <?php echo htmlspecialchars($company_phone); ?>
                    </div>
                    
                    <div style="font-size: 11px; color: #9ca3af; margin-top: 15px; line-height: 1.5;">
                        <p>This is a preview of your email. Actual email will include unsubscribe links and full footer.</p>
                    </div>
                </div>
            </div>
        `;
        
        emailPreviewContent.innerHTML = previewHTML;
        
        const modal = new bootstrap.Modal(document.getElementById('emailPreviewModal'));
        modal.show();
    };
    
    // Form validation
    const emailForm = document.getElementById('emailForm');
    emailForm.addEventListener('submit', function(e) {
        const selectedClients = document.querySelectorAll('.client-checkbox:checked');
        const clientIds = Array.from(selectedClients).map(cb => cb.value);
        const subject = document.getElementById('email_subject').value.trim();
        const htmlContent = document.getElementById('email_html').value.trim();
        
        console.log("Submitting client IDs:", clientIds);
        console.log("Number of clients:", clientIds.length);
        
        if (clientIds.length === 0) {
            e.preventDefault();
            alert('Please select at least one client.');
            return false;
        }
        
        // Check if any selected clients have email addresses
        const hasEmailClients = Array.from(selectedClients).some(cb => cb.dataset.email !== '');
        if (!hasEmailClients) {
            e.preventDefault();
            alert('None of the selected clients have email addresses. Please select clients with valid emails.');
            return false;
        }
        
        if (!subject) {
            e.preventDefault();
            alert('Please enter an email subject.');
            return false;
        }
        
        if (!htmlContent) {
            e.preventDefault();
            alert('Please enter HTML content for the email.');
            return false;
        }
        
        // Add hidden input for client IDs
        clientIds.forEach(clientId => {
            const hiddenInput = document.createElement('input');
            hiddenInput.type = 'hidden';
            hiddenInput.name = 'client_ids[]';
            hiddenInput.value = clientId;
            emailForm.appendChild(hiddenInput);
        });
        
        // Show loading state
        const sendButton = document.getElementById('sendButton');
        const originalText = sendButton.innerHTML;
        sendButton.innerHTML = '<i class="bi bi-hourglass-split me-2"></i>Sending...';
        sendButton.disabled = true;
        
        // Confirm before sending
        const emailCount = Array.from(selectedClients).filter(cb => cb.dataset.email !== '').length;
        if (!confirm(`Are you sure you want to send this email to ${emailCount} clients?`)) {
            e.preventDefault();
            sendButton.innerHTML = originalText;
            sendButton.disabled = false;
            return false;
        }
        
        return true;
    });
    
    // File size validation
    document.getElementById('attachment').addEventListener('change', function(e) {
        const file = e.target.files[0];
        if (file) {
            const maxSize = 10 * 1024 * 1024; // 10MB
            if (file.size > maxSize) {
                alert('File size exceeds 10MB limit. Please choose a smaller file.');
                e.target.value = '';
            }
        }
    });
    
    // Initialize summary
    updateSummary();
});
</script>

<?php include '../includes/footer.php'; ?>