<?php
/**
 * Email Configuration for PHPMailer
 *
 * Centralizes SMTP and DKIM settings for all email sending in the application.
 * Update these values to match your email server and domain settings.
 *
 * --- DKIM Setup Instructions (Critical for Inbox Delivery) ---
 * 1. Generate a DKIM key pair:
 *    openssl genrsa -out dkim_private.pem 1024
 *    openssl rsa -in dkim_private.pem -pubout -out dkim_public.pem
 * 2. Place dkim_private.pem in the configured DKIM_KEYS_PATH directory
 * 3. Add a TXT record to your DNS for:
 *    {selector}._domainkey.{domain}  IN  TXT  "v=DKIM1; h=sha256; k=rsa; p={public_key}"
 * 4. Also add SPF record:
 *    {domain}  IN  TXT  "v=spf1 mx a include:{mail_server} ~all"
 * 5. And DMARC record:
 *    _dmarc.{domain}  IN  TXT  "v=DMARC1; p=quarantine; rua=mailto:dmarc@{domain}"
 *
 * The code below will automatically sign all outgoing emails with DKIM
 * when a valid private key file is found.
 */

// --- SMTP Configuration ---
define('SMTP_HOST', 'mail.neovam.com');
define('SMTP_PORT', 587);
define('SMTP_ENCRYPTION', 'tls'); // 'tls' (port 587) or 'ssl' (port 465)
define('SMTP_USERNAME', 'info@neovam.com');
define('SMTP_PASSWORD', 'Ernestmswima@12');
define('SMTP_TIMEOUT', 30);
define('SMTP_VERIFY_PEER', false); // Set to true in production with valid SSL cert
define('SMTP_DEBUG', 2); // 0=off, 1=client, 2=client+server

// --- DKIM Configuration ---
define('DKIM_DOMAIN', 'neovam.com');
define('DKIM_SELECTOR', 'default');
define('DKIM_IDENTITY', 'info@neovam.com');
define('DKIM_KEYS_PATH', __DIR__ . '/../config/dkim/');
define('DKIM_PRIVATE_KEY_FILE', DKIM_KEYS_PATH . 'dkim_private.pem');
define('DKIM_PASSPHRASE', ''); // If your private key is encrypted

// --- General Email Settings ---
define('EMAIL_CHARSET', 'UTF-8');
define('EMAIL_MAX_ATTACHMENT_SIZE', 25 * 1024 * 1024); // 25MB

/**
 * Configure a PHPMailer instance with SMTP settings and DKIM signing.
 * Call this after creating $mail = new PHPMailer(true) and before sending.
 *
 * @param PHPMailer\PHPMailer\PHPMailer $mail  The PHPMailer instance
 */
function configureMailer($mail) {
    // SMTP
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

    // SSL options
    $mail->SMTPOptions = [
        'ssl' => [
            'verify_peer' => SMTP_VERIFY_PEER,
            'verify_peer_name' => SMTP_VERIFY_PEER,
            'allow_self_signed' => !SMTP_VERIFY_PEER,
        ],
    ];

    // Debug logging
    if (SMTP_DEBUG > 0) {
        $mail->SMTPDebug = SMTP_DEBUG;
        $mail->Debugoutput = function ($str, $level) {
            error_log("PHPMailer [lvl $level]: $str");
        };
    }

    // DKIM Signing - critical for inbox delivery
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

    // Proper headers for deliverability
    $mail->addCustomHeader('X-Mailer', 'Stockex Mailer');
    $mail->addCustomHeader('Date', date('r'));

    // Encoding
    $mail->CharSet = EMAIL_CHARSET;
    $mail->Encoding = 'base64';
}

/**
 * Create the DKIM keys directory if it doesn't exist.
 * Call this during setup to ensure the keys path is available.
 */
function ensureDkimKeysDirectory() {
    if (!is_dir(DKIM_KEYS_PATH)) {
        @mkdir(DKIM_KEYS_PATH, 0700, true);
    }
}
