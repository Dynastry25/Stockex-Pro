<?php
/**
 * API v1 - Notifications Endpoint
 * 
 * Usage:
 * POST /api/v1/notifications.php - Send email notification with optional attachments
 * 
 * Request Body:
 * {
 *   "type": "ticket_created",
 *   "subject": "Email Subject",
 *   "message": "Plain text message",
 *   "html_content": "<html>...</html>",
 *   "recipients": ["email@example.com"],
 *   "metadata": {
 *     "ticket_id": "123",
 *     "ticket_number": "TK-001",
 *     "user_name": "John Doe"
 *   },
 *   "attachments": [
 *     {
 *       "file_name": "Purchase-Order.pdf",
 *       "mime_type": "application/pdf",
 *       "base64_content": "JVBERi0xLjc..."
 *     }
 *   ]
 * }
 */

// Enable error handling
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../../logs/notifications_errors.log');

// Load PHPMailer
require_once __DIR__ . '/../../phpmailer/src/Exception.php';
require_once __DIR__ . '/../../phpmailer/src/PHPMailer.php';
require_once __DIR__ . '/../../phpmailer/src/SMTP.php';
require_once __DIR__ . '/../../config/email.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Only allow POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'error' => 'Method not allowed. Use POST.'
    ]);
    exit();
}

try {
    // Get JSON request
    $raw_input = file_get_contents('php://input');
    error_log("Notification request received, size: " . strlen($raw_input));
    
    $data = json_decode($raw_input, true);
    
    if (!$data) {
        error_log("JSON decode failed: " . json_last_error_msg());
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'Invalid JSON input'
        ]);
        exit();
    }
    
    // Extract notification data
    $type = $data['type'] ?? 'notification';
    $subject = $data['subject'] ?? 'Notification';
    $message = $data['message'] ?? '';
    $html_content = $data['html_content'] ?? '';
    $recipients = $data['recipients'] ?? [];
    $attachments = $data['attachments'] ?? [];
    $metadata = $data['metadata'] ?? [];
    $from_email = $data['from_email'] ?? SMTP_FROM_EMAIL;
    $from_name = $data['from_name'] ?? SMTP_FROM_NAME;
    $reply_to = $data['reply_to'] ?? SMTP_FROM_EMAIL;
    
    error_log("Notification type: $type, Subject: $subject, Recipients: " . json_encode($recipients) . ", Attachments: " . count($attachments));
    
    // Validate recipients
    if (empty($recipients) || !is_array($recipients)) {
        error_log("Invalid recipients: " . json_encode($recipients));
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'No recipients specified or invalid format'
        ]);
        exit();
    }
    
    // Validate email addresses
    $valid_recipients = [];
    foreach ($recipients as $email) {
        if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $valid_recipients[] = $email;
        } else {
            error_log("Invalid email address: $email");
        }
    }
    
    if (empty($valid_recipients)) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'No valid email addresses provided'
        ]);
        exit();
    }
    
    // Initialize PHPMailer
    $mail = new PHPMailer(true);

    // Apply centralized SMTP configuration from .env
    configureMailer($mail);
    
    // Override from/reply-to with request-specific values if provided
    $mail->clearAddresses();
    $mail->clearReplyTos();
    $mail->setFrom($from_email, $from_name);
    $mail->addReplyTo($reply_to, 'Support');
    
    // Add recipients
    foreach ($valid_recipients as $email) {
        $mail->addAddress($email);
    }
    
    // Set email subject
    $mail->Subject = $subject;
    
    // Prepare email body with footer
    $emailBody = !empty($html_content) ? $html_content : "<p>" . nl2br(htmlspecialchars($message)) . "</p>";
    $emailBody .= "\n\n<hr style='border:none;border-top:1px solid #ccc;margin:20px 0;'>";
    $emailBody .= "\n<p style='font-size:12px;color:#666;'>";
    $emailBody .= "This is an automated notification from StockEx Platform.<br>";
    $emailBody .= "Please do not reply to this email. Send inquiries to: {$reply_to}";
    $emailBody .= "</p>";
    
    $mail->isHTML(true);
    $mail->Body = $emailBody;
    
    // Add custom headers for tracking
    if (!empty($metadata['ticket_id'])) {
        $mail->addCustomHeader('X-Ticket-ID', $metadata['ticket_id']);
    }
    if (!empty($metadata['ticket_number'])) {
        $mail->addCustomHeader('X-Ticket-Number', $metadata['ticket_number']);
    }
    
    // Handle attachments
    if (!empty($attachments) && is_array($attachments)) {
        error_log("Processing " . count($attachments) . " attachment(s)");
        
        foreach ($attachments as $index => $attachment) {
            try {
                $file_name = $attachment['file_name'] ?? "attachment_$index";
                $mime_type = $attachment['mime_type'] ?? 'application/octet-stream';
                $base64_content = $attachment['base64_content'] ?? '';
                
                if (empty($base64_content)) {
                    error_log("Attachment $index: empty base64_content");
                    continue;
                }
                
                // Decode base64 content
                $file_content = base64_decode($base64_content, true);
                if ($file_content === false) {
                    error_log("Attachment $index: invalid base64 encoding for {$file_name}");
                    continue;
                }
                
                // Create a temporary file for the attachment
                $temp_file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid() . '_' . basename($file_name);
                
                if (file_put_contents($temp_file, $file_content) === false) {
                    error_log("Attachment $index: failed to write temp file for {$file_name}");
                    continue;
                }
                
                // Add attachment to email
                $mail->addAttachment($temp_file, $file_name, 'base64', $mime_type);
                error_log("Attachment $index added: {$file_name} ({$mime_type}, " . strlen($file_content) . " bytes)");
                
                // Register cleanup callback
                register_shutdown_function(function () use ($temp_file) {
                    if (file_exists($temp_file)) {
                        @unlink($temp_file);
                    }
                });
                
            } catch (Exception $e) {
                error_log("Error processing attachment $index: " . $e->getMessage());
                continue;
            }
        }
    }
    
    error_log("Sending email to: " . implode(', ', $valid_recipients) . ", Subject: $subject");
    
    // Send email
    $mailSent = $mail->send();
    
    if ($mailSent) {
        $messageId = 'msg_' . uniqid() . '_' . time();
        
        error_log("[NOTIFICATION_SUCCESS] Type: {$type}, To: " . implode(', ', $valid_recipients) . ", Subject: {$subject}, Attachments: " . count($attachments) . ", MessageID: {$messageId}");
        
        http_response_code(200);
        echo json_encode([
            'success' => true,
            'data' => [
                'message_id' => $messageId,
                'sent_to' => $valid_recipients,
                'timestamp' => date('Y-m-d\TH:i:s\Z'),
                'type' => $type,
                'attachments_count' => count($attachments)
            ],
            'message' => 'Email notification sent successfully'
        ]);
    } else {
        error_log("[NOTIFICATION_ERROR] Failed to send email. To: " . implode(', ', $valid_recipients) . ", Subject: {$subject}, Error: " . $mail->ErrorInfo);
        
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => 'Failed to send email notification',
            'details' => $mail->ErrorInfo
        ]);
    }
    
} catch (PHPMailerException $e) {
    error_log("PHPMailer Exception: " . $e->getMessage());
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Email service error',
        'message' => $e->getMessage()
    ]);
} catch (Exception $e) {
    error_log("Exception in notifications.php: " . $e->getMessage());
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Internal server error',
        'message' => $e->getMessage()
    ]);
}
?>
