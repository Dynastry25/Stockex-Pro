<?php
/**
 * KYC API - Secure Backend Proxy
 * All database operations go through this API
 * Rate limiting, input validation, and audit logging implemented
 */

require_once '../config/config.php';
require_once '../includes/security.php';

// Set JSON response header
header('Content-Type: application/json');

// Initialize security
$security = new SecurityManager();

// Rate limiting - max 5 requests per minute per IP
$ip = $_SERVER['REMOTE_ADDR'];
if (!$security->checkRateLimit($ip, 'kyc_api', 5, 60)) {
    http_response_code(429);
    echo json_encode(['success' => false, 'error' => 'Too many requests. Please try again later.']);
    exit;
}

// CSRF Protection - verify token for POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !$security->verifyCSRFToken($_POST['csrf_token'])) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Invalid security token.']);
        exit;
    }
}

// Get action from request
$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
    $db = getDBConnection();
    $response = ['success' => false, 'error' => 'Invalid action'];
    
    switch ($action) {
        case 'lookup_cds':
            $response = lookupCDS($db, $security, $_POST);
            break;
            
        case 'submit_kyc':
            $response = submitKYC($db, $security, $_POST);
            break;
            
        case 'verify_otp':
            $response = verifyOTP($db, $security, $_POST);
            break;
            
        case 'send_otp':
            $response = sendOTP($db, $security, $_POST);
            break;
            
        default:
            http_response_code(400);
            $response = ['success' => false, 'error' => 'Invalid action'];
    }
    
    // Audit log
    $security->auditLog($ip, $action, json_encode($_POST), $response['success']);
    
    echo json_encode($response);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error occurred.']);
    // Log error internally
    error_log("KYC API Error: " . $e->getMessage());
}

/**
 * Look up CDS account with partial data masking
 */
function lookupCDS($db, $security, $data) {
    $cds_account = trim($data['cds_account'] ?? '');
    
    // Validate CDS format (alphanumeric, 5-20 chars)
    if (!preg_match('/^[A-Z0-9]{5,20}$/i', $cds_account)) {
        return ['success' => false, 'error' => 'Invalid CDS account format.'];
    }
    
    // Check for SQL injection (already sanitized via prepared statement)
    $stmt = $db->prepare("SELECT 
        id, 
        client_name, 
        cds_account, 
        client_type, 
        status,
        CASE 
            WHEN national_id IS NOT NULL THEN CONCAT(LEFT(national_id, 2), '****', RIGHT(national_id, 4))
            ELSE NULL 
        END as national_id_masked,
        date_of_birth,
        phone,
        email,
        address,
        bank_account_number,
        bank_name,
        bank_branch,
        currency,
        client_code,
        is_active,
        created_at,
        updated_at
    FROM clients 
    WHERE cds_account = ? AND is_active = 1");
    
    $stmt->execute([$cds_account]);
    $client = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($client) {
        // Mask sensitive data for response
        $client['phone_masked'] = $client['phone'] ? substr($client['phone'], 0, 4) . '****' . substr($client['phone'], -3) : null;
        $client['email_masked'] = $client['email'] ? substr($client['email'], 0, 3) . '****@' . substr($client['email'], strpos($client['email'], '@') + 1) : null;
        
        // Remove original sensitive fields from response (they will be sent via secure channels)
        unset($client['national_id']);
        unset($client['phone']);
        unset($client['email']);
        
        return [
            'success' => true,
            'found' => true,
            'client' => $client
        ];
    } else {
        // Check if account exists but inactive
        $stmt = $db->prepare("SELECT is_active, status FROM clients WHERE cds_account = ?");
        $stmt->execute([$cds_account]);
        $inactive = $stmt->fetch();
        
        if ($inactive) {
            return [
                'success' => false,
                'error' => 'This CDS account is inactive. Please contact support.',
                'code' => 'INACTIVE_ACCOUNT'
            ];
        }
        
        return [
            'success' => true,
            'found' => false,
            'message' => 'CDS account not found. You can register as a new client.'
        ];
    }
}

/**
 * Submit KYC data with comprehensive validation and encryption
 */
function submitKYC($db, $security, $data) {
    // Extract and sanitize all inputs
    $cds_account = $security->sanitizeInput($data['cds_account'] ?? '');
    $client_name = $security->sanitizeInput($data['client_name'] ?? '');
    $client_type = $security->sanitizeInput($data['client_type'] ?? 'individual');
    $national_id = $security->sanitizeInput($data['national_id'] ?? '');
    $date_of_birth = $security->sanitizeInput($data['date_of_birth'] ?? '');
    $phone = $security->sanitizeInput($data['phone'] ?? '');
    $email = $security->sanitizeInput($data['email'] ?? '');
    $address = $security->sanitizeInput($data['address'] ?? '');
    $bank_account = $security->sanitizeInput($data['bank_account_number'] ?? '');
    $bank_name = $security->sanitizeInput($data['bank_name'] ?? '');
    $bank_branch = $security->sanitizeInput($data['bank_branch'] ?? '');
    $currency = $security->sanitizeInput($data['currency'] ?? 'TZS');
    $client_code = $security->sanitizeInput($data['client_code'] ?? '');
    $is_existing = isset($data['is_existing']) ? (int)$data['is_existing'] : 0;
    $otp = $security->sanitizeInput($data['otp'] ?? '');
    
    // Comprehensive validation
    $errors = [];
    
    // CDS Account validation
    if (empty($cds_account) || !preg_match('/^[A-Z0-9]{5,20}$/i', $cds_account)) {
        $errors[] = 'Valid CDS Account is required (5-20 alphanumeric characters).';
    }
    
    // Name validation (only letters, spaces, hyphens, apostrophes)
    if (empty($client_name) || !preg_match('/^[a-zA-Z\s\-\']{2,100}$/', $client_name)) {
        $errors[] = 'Full Name must be 2-100 characters and contain only letters, spaces, hyphens, or apostrophes.';
    }
    
    // National ID validation
    if (empty($national_id) || !preg_match('/^[0-9\-]{5,20}$/', $national_id)) {
        $errors[] = 'Valid National ID/Passport is required.';
    }
    
    // Date of Birth validation (must be at least 18 years old)
    if (!empty($date_of_birth)) {
        $dob = DateTime::createFromFormat('Y-m-d', $date_of_birth);
        $now = new DateTime();
        $age = $now->diff($dob)->y;
        if ($age < 18) {
            $errors[] = 'You must be at least 18 years old.';
        }
    } else {
        $errors[] = 'Date of Birth is required.';
    }
    
    // Phone validation (Tanzanian format)
    if (empty($phone) || !preg_match('/^[0-9\-]{10,15}$/', $phone)) {
        $errors[] = 'Valid phone number is required.';
    }
    
    // Email validation (optional but must be valid if provided)
    if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
    }
    
    // Client type validation
    $valid_types = ['individual', 'institution', 'corporate', 'joint'];
    if (!in_array($client_type, $valid_types)) {
        $errors[] = 'Invalid client type selected.';
    }
    
    // Currency validation
    $valid_currencies = ['TZS', 'USD', 'EUR', 'GBP'];
    if (!in_array($currency, $valid_currencies)) {
        $errors[] = 'Invalid currency selected.';
    }
    
    // OTP verification (if enabled)
    if (defined('ENABLE_OTP_VERIFICATION') && ENABLE_OTP_VERIFICATION) {
        if (empty($otp)) {
            $errors[] = 'OTP verification is required.';
        } elseif (!$security->verifyOTP($phone, $otp)) {
            $errors[] = 'Invalid OTP. Please try again.';
        }
    }
    
    if (!empty($errors)) {
        return ['success' => false, 'errors' => $errors];
    }
    
    try {
        // Check for duplicate CDS
        $stmt = $db->prepare("SELECT id, is_active, status FROM clients WHERE cds_account = ?");
        $stmt->execute([$cds_account]);
        $existing = $stmt->fetch();
        
        // Encrypt sensitive data
        $encrypted = $security->encryptSensitiveData([
            'national_id' => $national_id,
            'phone' => $phone,
            'email' => $email,
            'bank_account' => $bank_account
        ]);
        
        if ($is_existing == 1 && $existing) {
            // Update existing client - only allow if active
            if ($existing['is_active'] != 1) {
                return ['success' => false, 'error' => 'Cannot update inactive account. Please contact support.'];
            }
            
            $stmt = $db->prepare("UPDATE clients SET 
                client_name = ?,
                national_id = ?,
                date_of_birth = ?,
                phone = ?,
                email = ?,
                client_type = ?,
                address = ?,
                bank_account_number = ?,
                bank_name = ?,
                bank_branch = ?,
                currency = ?,
                client_code = ?,
                updated_at = NOW(),
                kyc_updated_at = NOW()
                WHERE cds_account = ? AND is_active = 1");
            
            $stmt->execute([
                $client_name,
                $encrypted['national_id'],
                $date_of_birth,
                $encrypted['phone'],
                $encrypted['email'],
                $client_type,
                $address,
                $encrypted['bank_account'],
                $bank_name,
                $bank_branch,
                $currency,
                $client_code,
                $cds_account
            ]);
            
            // Audit log
            $security->auditLog($_SERVER['REMOTE_ADDR'], 'KYC_UPDATE', "CDS: $cds_account", true);
            
            return [
                'success' => true,
                'message' => 'KYC information updated successfully!',
                'action' => 'updated'
            ];
            
        } elseif (!$existing) {
            // Insert new client with pending status
            $stmt = $db->prepare("INSERT INTO clients 
                (client_name, cds_account, client_type, national_id, date_of_birth, 
                phone, email, address, bank_account_number, bank_name, bank_branch, 
                currency, client_code, status, is_active, created_at, kyc_submitted_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', 1, NOW(), NOW())");
            
            $stmt->execute([
                $client_name,
                $cds_account,
                $client_type,
                $encrypted['national_id'],
                $date_of_birth,
                $encrypted['phone'],
                $encrypted['email'],
                $address,
                $encrypted['bank_account'],
                $bank_name,
                $bank_branch,
                $currency,
                $client_code
            ]);
            
            // Audit log
            $security->auditLog($_SERVER['REMOTE_ADDR'], 'KYC_REGISTER', "CDS: $cds_account", true);
            
            // Send notification to admin
            if (defined('ADMIN_NOTIFY_EMAIL')) {
                $security->sendAdminNotification("New KYC Registration", "CDS: $cds_account, Name: $client_name");
            }
            
            return [
                'success' => true,
                'message' => 'Registration submitted successfully! Please wait for verification.',
                'action' => 'registered'
            ];
        } else {
            return ['success' => false, 'error' => 'This CDS account already exists but is inactive. Please contact support.'];
        }
    } catch (Exception $e) {
        error_log("KYC Submission Error: " . $e->getMessage());
        return ['success' => false, 'error' => 'An error occurred while processing your request.'];
    }
}

/**
 * Send OTP for verification
 */
function sendOTP($db, $security, $data) {
    $phone = $security->sanitizeInput($data['phone'] ?? '');
    
    if (empty($phone) || !preg_match('/^[0-9\-]{10,15}$/', $phone)) {
        return ['success' => false, 'error' => 'Valid phone number is required.'];
    }
    
    // Generate and store OTP
    $otp = $security->generateOTP($phone);
    
    // In production, send via SMS service
    // For demo, we'll return it (only in development)
    if (defined('ENVIRONMENT') && ENVIRONMENT === 'development') {
        return [
            'success' => true,
            'message' => 'OTP sent successfully!',
            'debug_otp' => $otp // Remove in production
        ];
    }
    
    // Production: Send via SMS gateway
    $sms_sent = $security->sendSMS($phone, "Your verification code is: $otp");
    
    if ($sms_sent) {
        return ['success' => true, 'message' => 'OTP sent successfully!'];
    } else {
        return ['success' => false, 'error' => 'Failed to send OTP. Please try again.'];
    }
}

/**
 * Verify OTP
 */
function verifyOTP($db, $security, $data) {
    $phone = $security->sanitizeInput($data['phone'] ?? '');
    $otp = $security->sanitizeInput($data['otp'] ?? '');
    
    if (empty($phone) || empty($otp)) {
        return ['success' => false, 'error' => 'Phone number and OTP are required.'];
    }
    
    $verified = $security->verifyOTP($phone, $otp);
    
    return ['success' => $verified, 'message' => $verified ? 'OTP verified!' : 'Invalid OTP.'];
}
