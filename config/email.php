<?php
/**
 * Email Configuration for PHPMailer
 *
 * Centralizes SMTP and DKIM settings for all email sending in the application.
 * All secrets are read from environment variables (Oracle Cloud / .env).
 *
 * --- DKIM Setup Instructions (Critical for Inbox Delivery) ---
 * 1. Generate a DKIM key pair:
 *    openssl genrsa -out dkim_private.pem 1024
 *    openssl rsa -in dkim_private.pem -pubout -out dkim_public.pem
 * 2. Place dkim_private.pem outside webroot on production
 * 3. Add a TXT record to your DNS for:
 *    {selector}._domainkey.{domain}  IN  TXT  "v=DKIM1; h=sha256; k=rsa; p={public_key}"
 * 4. Also add SPF record:
 *    {domain}  IN  TXT  "v=spf1 mx a include:{mail_server} ~all"
 * 5. And DMARC record:
 *    _dmarc.{domain}  IN  TXT  "v=DMARC1; p=quarantine; rua=mailto:dmarc@{domain}"
 */

require_once __DIR__ . '/env_loader.php';

// --- SMTP Configuration (from environment) ---
define('SMTP_HOST', env('SMTP_HOST', 'localhost'));
define('SMTP_PORT', intval(env('SMTP_PORT', '587')));
define('SMTP_ENCRYPTION', env('SMTP_ENCRYPTION', 'tls'));
define('SMTP_USERNAME', env('SMTP_USERNAME', ''));
define('SMTP_PASSWORD', env('SMTP_PASSWORD', ''));
define('SMTP_FROM_EMAIL', env('SMTP_FROM_EMAIL', SMTP_USERNAME));
define('SMTP_FROM_NAME', env('SMTP_FROM_NAME', 'StockEx Mailer'));
define('SMTP_TIMEOUT', intval(env('SMTP_TIMEOUT', '30')));
define('SMTP_VERIFY_PEER', env('SMTP_VERIFY_PEER', 'false') === 'true');
define('SMTP_DEBUG', intval(env('SMTP_DEBUG', '0')));

// --- DKIM Configuration (from environment) ---
define('DKIM_DOMAIN', env('DKIM_DOMAIN', ''));
define('DKIM_SELECTOR', env('DKIM_SELECTOR', 'default'));
define('DKIM_IDENTITY', env('DKIM_IDENTITY', SMTP_FROM_EMAIL));
define('DKIM_KEYS_PATH', __DIR__ . '/../' . env('DKIM_PRIVATE_KEY_PATH', 'config/dkim/'));
define('DKIM_PRIVATE_KEY_FILE', rtrim(DKIM_KEYS_PATH, '/') . '/dkim_private.pem');
define('DKIM_PASSPHRASE', env('DKIM_PASSPHRASE', ''));

// --- General Email Settings ---
define('EMAIL_CHARSET', 'UTF-8');
define('EMAIL_MAX_ATTACHMENT_SIZE', 25 * 1024 * 1024); // 25MB

/**
 * Configure a PHPMailer instance with SMTP settings and DKIM signing.
 */
function configureMailer($mail) {
    $mail->isSMTP();
    $mail->Host = SMTP_HOST;
    $mail->SMTPAuth = true;
    $mail->Username = SMTP_USERNAME;
    $mail->Password = SMTP_PASSWORD;
    $mail->SMTPSecure = SMTP_ENCRYPTION === 'ssl'
        ? PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS
        : PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port = SMTP_PORT;
    $mail->Timeout = SMTP_TIMEOUT;
    $mail->SMTPKeepAlive = true;

    $mail->SMTPOptions = [
        'ssl' => [
            'verify_peer' => SMTP_VERIFY_PEER,
            'verify_peer_name' => SMTP_VERIFY_PEER,
            'allow_self_signed' => !SMTP_VERIFY_PEER,
        ],
    ];

    if (SMTP_DEBUG > 0) {
        $mail->SMTPDebug = SMTP_DEBUG;
        $mail->Debugoutput = function ($str, $level) {
            $log_file = dirname(__DIR__) . '/logs/smtp_debug.log';
            if (!is_dir(dirname($log_file))) {
                @mkdir(dirname($log_file), 0755, true);
            }
            @file_put_contents($log_file, date('[Y-m-d H:i:s]') . " [lvl $level] " . trim($str) . PHP_EOL, FILE_APPEND);
        };
    }

    if (file_exists(DKIM_PRIVATE_KEY_FILE)) {
        $mail->DKIM_domain = DKIM_DOMAIN;
        $mail->DKIM_selector = DKIM_SELECTOR;
        $mail->DKIM_private_string = file_get_contents(DKIM_PRIVATE_KEY_FILE);
        if (!empty(DKIM_PASSPHRASE)) {
            $mail->DKIM_passphrase = DKIM_PASSPHRASE;
        }
        $mail->DKIM_identity = DKIM_IDENTITY;
        $mail->DKIM_copyHeaderFields = true;
        $mail->DKIM_extraHeaders = ['List-Unsubscribe', 'X-Mailer'];
        error_log('DKIM signing enabled for domain: ' . DKIM_DOMAIN);
    } else {
        error_log('DKIM private key not found at: ' . DKIM_PRIVATE_KEY_FILE . ' - emails will NOT be DKIM-signed');
    }

    $mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
    $mail->addCustomHeader('X-Mailer', 'Stockex Mailer');
    $mail->addCustomHeader('Date', date('r'));
    $mail->CharSet = EMAIL_CHARSET;
    $mail->Encoding = 'base64';
}

function ensureDkimKeysDirectory() {
    if (!is_dir(DKIM_KEYS_PATH)) {
        @mkdir(DKIM_KEYS_PATH, 0700, true);
    }
}
