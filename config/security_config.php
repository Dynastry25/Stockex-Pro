<?php
/**
 * Security Configuration
 * Add to your config.php or create separate config
 */

// Encryption
define('ENCRYPTION_KEY', getenv('ENCRYPTION_KEY') ?: '4f2a8d9e7b3c1f6a5e8d2c4b9a7f3e1d6c8a5f2e9d7b3c1a4f8e6d2c9b7a5f3e'); // Use environment variable in production

// Security settings
define('ENABLE_OTP_VERIFICATION', true);
define('ENABLE_CAPTCHA', true);
define('MAX_LOGIN_ATTEMPTS', 5);
define('LOCKOUT_DURATION', 900); // 15 minutes
define('SESSION_LIFETIME', 3600); // 1 hour

// Rate limiting
define('RATE_LIMIT_REQUESTS', 30);
define('RATE_LIMIT_WINDOW', 60); // 1 minute

// Environment
define('ENVIRONMENT', 'production'); // 'development' or 'production'

// Notifications
define('ADMIN_NOTIFY_EMAIL', 'emswima@vfsl.co.tz');
define('SYSTEM_EMAIL', 'noreply@yourdomain.com');

// SMS Gateway (if using)
define('SMS_USERNAME', getenv('SMS_USERNAME') ?: '');
define('SMS_API_KEY', getenv('SMS_API_KEY') ?: '');
