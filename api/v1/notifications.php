<?php
/**
 * API v1 - Notifications Endpoint
 * 
 * Usage:
 * POST /api/v1/notifications.php - Send email notification
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
 *   }
 * }
 */

// Enable error handling
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../../logs/notifications_errors.log');

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
    $metadata = $data['metadata'] ?? [];
    $from_email = $data['from_email'] ?? 'noreply@myshopii.co.tz';
    $from_name = $data['from_name'] ?? 'MyShopii';
    $reply_to = $data['reply_to'] ?? 'support@myshopii.co.tz';
    
    error_log("Notification type: $type, Subject: $subject, Recipients: " . json_encode($recipients));
    
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
    
    // Prepare email
    $to = implode(',', $valid_recipients);
    
    // Email headers
    $headers = "MIME-Version: 1.0\r\n";
    $headers .= "Content-type: text/html; charset=UTF-8\r\n";
    $headers .= "From: {$from_name} <{$from_email}>\r\n";
    $headers .= "Reply-To: {$reply_to}\r\n";
    $headers .= "X-Mailer: PHP/" . phpversion() . "\r\n";
    
    // Add custom headers for tracking
    if (!empty($metadata['ticket_id'])) {
        $headers .= "X-Ticket-ID: " . $metadata['ticket_id'] . "\r\n";
    }
    if (!empty($metadata['ticket_number'])) {
        $headers .= "X-Ticket-Number: " . $metadata['ticket_number'] . "\r\n";
    }
    
    // Use HTML content if provided, otherwise format message
    $emailBody = !empty($html_content) ? $html_content : "<p>" . nl2br(htmlspecialchars($message)) . "</p>";
    
    // Add footer to HTML email
    $emailBody .= "\n\n<hr style='border:none;border-top:1px solid #ccc;margin:20px 0;'>";
    $emailBody .= "\n<p style='font-size:12px;color:#666;'>";
    $emailBody .= "This is an automated notification from MyShopii Stockex Platform.<br>";
    $emailBody .= "Please do not reply to this email. Send inquiries to: {$reply_to}";
    $emailBody .= "</p>";
    
    error_log("Sending email to: $to, Subject: $subject");
    
    // Send email using PHP mail()
    $mailSent = mail($to, $subject, $emailBody, $headers);
    
    if ($mailSent) {
        $messageId = 'msg_' . uniqid() . '_' . time();
        
        error_log("[NOTIFICATION_SUCCESS] Type: {$type}, To: {$to}, Subject: {$subject}, MessageID: {$messageId}");
        
        http_response_code(200);
        echo json_encode([
            'success' => true,
            'data' => [
                'message_id' => $messageId,
                'sent_to' => $valid_recipients,
                'timestamp' => date('Y-m-d\TH:i:s\Z'),
                'type' => $type
            ],
            'message' => 'Email notification sent successfully'
        ]);
    } else {
        error_log("[NOTIFICATION_ERROR] Failed to send email. To: {$to}, Subject: {$subject}");
        
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => 'Failed to send email notification',
            'details' => 'The mail server did not accept the message'
        ]);
    }
    
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
