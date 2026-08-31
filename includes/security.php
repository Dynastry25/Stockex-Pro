<?php
/**
 * Security Manager - Enterprise Security Class
 * Handles all security operations for the investor portal
 */

class SecurityManager {
    private $encryption_key;
    private $token_lifetime = 3600; // 1 hour
    private $max_login_attempts = 5;
    private $lockout_duration = 900; // 15 minutes
    
    public function __construct() {
        // Get encryption key from environment or config
        $this->encryption_key = defined('ENCRYPTION_KEY') ? ENCRYPTION_KEY : $this->generateEncryptionKey();
        
        // Start session if not started
        if (session_status() === PHP_SESSION_NONE) {
            session_start([
                'cookie_httponly' => true,
                'cookie_secure' => true,
                'cookie_samesite' => 'Strict',
                'use_strict_mode' => true
            ]);
        }
    }
    
    /**
     * Generate encryption key if not set
     */
    private function generateEncryptionKey() {
        return bin2hex(openssl_random_pseudo_bytes(32));
    }
    
    /**
     * Rate limiting using database or cache
     */
    public function checkRateLimit($key, $action, $max_requests, $time_window) {
        $cache_key = "rate_limit_{$key}_{$action}";
        
        // Using file-based cache (replace with Redis/Memcached in production)
        $cache_file = sys_get_temp_dir() . '/' . md5($cache_key) . '.cache';
        $current_time = time();
        
        if (file_exists($cache_file)) {
            $data = unserialize(file_get_contents($cache_file));
            $timestamp = $data['timestamp'] ?? 0;
            $count = $data['count'] ?? 0;
            
            // Reset if time window expired
            if ($current_time - $timestamp > $time_window) {
                $count = 0;
                $timestamp = $current_time;
            }
            
            $count++;
            
            if ($count > $max_requests) {
                return false;
            }
        } else {
            $count = 1;
            $timestamp = $current_time;
        }
        
        // Save cache
        file_put_contents($cache_file, serialize([
            'count' => $count,
            'timestamp' => $timestamp
        ]));
        
        return true;
    }
    
    /**
     * Generate CSRF token
     */
    public function generateCSRFToken() {
        $token = bin2hex(openssl_random_pseudo_bytes(32));
        $_SESSION['csrf_token'] = $token;
        $_SESSION['csrf_token_time'] = time();
        return $token;
    }
    
    /**
     * Verify CSRF token
     */
    public function verifyCSRFToken($token) {
        if (!isset($_SESSION['csrf_token']) || !isset($_SESSION['csrf_token_time'])) {
            return false;
        }
        
        // Check token age
        if (time() - $_SESSION['csrf_token_time'] > $this->token_lifetime) {
            unset($_SESSION['csrf_token']);
            unset($_SESSION['csrf_token_time']);
            return false;
        }
        
        return hash_equals($_SESSION['csrf_token'], $token);
    }
    
    /**
     * Sanitize input (HTML special chars + additional validation)
     */
    public function sanitizeInput($input) {
        if (is_array($input)) {
            return array_map([$this, 'sanitizeInput'], $input);
        }
        
        // Remove null bytes and trim
        $input = trim(str_replace("\0", '', $input));
        
        // HTML encode
        $input = htmlspecialchars($input, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        
        // Remove potential XSS patterns
        $input = preg_replace('/<(script|iframe|object|embed|applet|style|link)[^>]*>.*?<\/\\1>/is', '', $input);
        
        return $input;
    }
    
    /**
     * Encrypt sensitive data
     */
    public function encryptSensitiveData($data) {
        $encrypted = [];
        $cipher = 'aes-256-cbc';
        $ivlen = openssl_cipher_iv_length($cipher);
        
        foreach ($data as $key => $value) {
            if (!empty($value)) {
                $iv = openssl_random_pseudo_bytes($ivlen);
                $encrypted_value = openssl_encrypt(
                    $value,
                    $cipher,
                    $this->encryption_key,
                    OPENSSL_RAW_DATA,
                    $iv
                );
                $encrypted[$key] = base64_encode($iv . $encrypted_value);
            } else {
                $encrypted[$key] = $value;
            }
        }
        
        return $encrypted;
    }
    
    /**
     * Decrypt sensitive data
     */
    public function decryptSensitiveData($encrypted_data) {
        if (empty($encrypted_data)) {
            return null;
        }
        
        $cipher = 'aes-256-cbc';
        $ivlen = openssl_cipher_iv_length($cipher);
        $decoded = base64_decode($encrypted_data);
        $iv = substr($decoded, 0, $ivlen);
        $ciphertext = substr($decoded, $ivlen);
        
        return openssl_decrypt(
            $ciphertext,
            $cipher,
            $this->encryption_key,
            OPENSSL_RAW_DATA,
            $iv
        );
    }
    
    /**
     * Generate OTP and store in session
     */
    public function generateOTP($identifier) {
        $otp = str_pad(rand(100000, 999999), 6, '0', STR_PAD_LEFT);
        $_SESSION['otp_' . $identifier] = [
            'code' => password_hash($otp, PASSWORD_BCRYPT),
            'timestamp' => time(),
            'attempts' => 0
        ];
        return $otp;
    }
    
    /**
     * Verify OTP
     */
    public function verifyOTP($identifier, $otp) {
        if (!isset($_SESSION['otp_' . $identifier])) {
            return false;
        }
        
        $session_data = $_SESSION['otp_' . $identifier];
        
        // Check if OTP expired (5 minutes)
        if (time() - $session_data['timestamp'] > 300) {
            unset($_SESSION['otp_' . $identifier]);
            return false;
        }
        
        // Check max attempts
        if ($session_data['attempts'] >= 3) {
            unset($_SESSION['otp_' . $identifier]);
            return false;
        }
        
        // Verify OTP
        if (password_verify($otp, $session_data['code'])) {
            unset($_SESSION['otp_' . $identifier]);
            return true;
        }
        
        // Increment attempts
        $_SESSION['otp_' . $identifier]['attempts']++;
        return false;
    }
    
    /**
     * Audit logging
     */
    public function auditLog($ip, $action, $data, $success) {
        $log_entry = [
            'timestamp' => date('Y-m-d H:i:s'),
            'ip' => $ip,
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
            'action' => $action,
            'data' => $data,
            'success' => $success ? 'SUCCESS' : 'FAILURE'
        ];
        
        // Write to audit log file
        $log_file = '../logs/audit_' . date('Y-m-d') . '.log';
        $log_dir = dirname($log_file);
        
        if (!is_dir($log_dir)) {
            mkdir($log_dir, 0755, true);
        }
        
        file_put_contents(
            $log_file,
            json_encode($log_entry) . PHP_EOL,
            FILE_APPEND | LOCK_EX
        );
        
        // Also log to database if available
        $this->logToDatabase($log_entry);
    }
    
    /**
     * Log to database (optional)
     */
    private function logToDatabase($log_entry) {
        try {
            $db = getDBConnection();
            $stmt = $db->prepare("INSERT INTO audit_logs 
                (ip_address, user_agent, action, data, success, created_at) 
                VALUES (?, ?, ?, ?, ?, NOW())");
            $stmt->execute([
                $log_entry['ip'],
                $log_entry['user_agent'],
                $log_entry['action'],
                $log_entry['data'],
                $log_entry['success'] === 'SUCCESS' ? 1 : 0
            ]);
        } catch (Exception $e) {
            // Silent fail - file logging is enough
            error_log("Audit DB Error: " . $e->getMessage());
        }
    }
    
    /**
     * Send SMS via gateway
     */
    public function sendSMS($phone, $message) {
        // Delegate to the shared JSON SMS gateway (includes/sms.php).
        // Returns true/false; logs the delivery, never the credentials.
        @require_once __DIR__ . '/sms.php';
        if (!function_exists('sms_send')) {
            error_log("SMS to $phone: $message");
            return true;
        }
        $result = sms_send((string)$phone, (string)$message);
        return ($result['success'] ?? false) === true;
    }
    
    /**
     * Send admin notification email
     */
    public function sendAdminNotification($subject, $message) {
        $admin_email = defined('ADMIN_NOTIFY_EMAIL') ? ADMIN_NOTIFY_EMAIL : '';
        if (empty($admin_email)) {
            return false;
        }
        
        $headers = "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
        $headers .= "From: " . defined('SYSTEM_EMAIL') ? SYSTEM_EMAIL : 'noreply@system.com' . "\r\n";
        
        $body = "<html><body>
            <h2>$subject</h2>
            <p>$message</p>
            <p>Time: " . date('Y-m-d H:i:s') . "</p>
            <p>IP: " . $_SERVER['REMOTE_ADDR'] . "</p>
        </body></html>";
        
        return mail($admin_email, $subject, $body, $headers);
    }
    
    /**
     * Generate secure random token
     */
    public function generateSecureToken($length = 32) {
        return bin2hex(openssl_random_pseudo_bytes($length));
    }
    
    /**
     * Validate and sanitize phone number
     */
    public function validatePhone($phone) {
        // Remove all non-numeric characters
        $clean = preg_replace('/[^0-9]/', '', $phone);
        
        // Check if it's a valid Tanzanian number (adjust as needed)
        if (strlen($clean) === 10 && preg_match('/^[0-9]{10}$/', $clean)) {
            return $clean;
        }
        
        // International format
        if (strlen($clean) === 12 && substr($clean, 0, 3) === '255') {
            return $clean;
        }
        
        return false;
    }
}
