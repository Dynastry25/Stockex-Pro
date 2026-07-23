<?php
/**
 * EMAIL DEBUG PAGE - TEMPORARY (Enhanced)
 * DELETE THIS FILE AFTER TESTING.
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

require_once __DIR__ . '/config/env_loader.php';
require_once __DIR__ . '/config/email.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/phpmailer/src/PHPMailer.php';
require_once __DIR__ . '/phpmailer/src/SMTP.php';
require_once __DIR__ . '/phpmailer/src/Exception.php';

session_start();

$results = [];

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$LOG_DIR = __DIR__ . '/logs';
$EMAIL_DEBUG_LOG = $LOG_DIR . '/email_debug_full.log';

if (!is_dir($LOG_DIR)) {
    mkdir($LOG_DIR, 0777, true);
}

function writeDebugLog($message) {
    global $EMAIL_DEBUG_LOG;
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($EMAIL_DEBUG_LOG, "[$timestamp] $message\n", FILE_APPEND | LOCK_EX);
}

function readDebugLog() {
    global $EMAIL_DEBUG_LOG;
    if (file_exists($EMAIL_DEBUG_LOG)) {
        $content = file_get_contents($EMAIL_DEBUG_LOG);
        if (strlen($content) > 10000) {
            $content = '... (truncated, showing last 10000 chars) ...' . substr($content, -10000);
        }
        return $content;
    }
    return "No log entries yet.";
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $results[] = ['type' => 'error', 'title' => 'CSRF Error', 'message' => 'Token mismatch'];
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'clear_log') {
            file_put_contents($EMAIL_DEBUG_LOG, '');
            $results[] = ['type' => 'info', 'title' => 'Log Cleared', 'message' => 'Debug log has been cleared.'];
        } elseif ($action === 'check_config') {
            $results[] = runFullDiagnostics();
        } elseif ($action === 'test_connection') {
            $results[] = testSMTPConnection();
        } elseif ($action === 'send_test') {
            $to = trim($_POST['test_email'] ?? '');
            $subject = trim($_POST['test_subject'] ?? 'StockEx Email Test');
            $results[] = sendTestEmail($to, $subject);
        } elseif ($action === 'dns_check') {
            $results[] = checkDNSRecords();
        } elseif ($action === 'raw_smtp') {
            $results[] = rawSMTPTest();
        }
    }
}

function runFullDiagnostics() {
    $checks = [];

    writeDebugLog("=== STARTING FULL DIAGNOSTICS ===");

    // 1. .env file
    $envFile = __DIR__ . '/.env';
    $checks[] = [
        'name' => '.env File',
        'status' => file_exists($envFile) ? 'pass' : 'fail',
        'message' => file_exists($envFile) ? 'Found' : 'Not found',
    ];

    // 2. SMTP settings
    $checks[] = ['name' => 'SMTP Host', 'status' => !empty(SMTP_HOST) ? 'pass' : 'fail', 'message' => SMTP_HOST ?: 'Not set'];
    $checks[] = ['name' => 'SMTP Port', 'status' => SMTP_PORT > 0 ? 'pass' : 'fail', 'message' => SMTP_PORT ?: 'Not set'];
    $checks[] = ['name' => 'SMTP Username', 'status' => !empty(SMTP_USERNAME) ? 'pass' : 'warn', 'message' => SMTP_USERNAME ?: 'Not set'];
    $checks[] = ['name' => 'SMTP Password', 'status' => !empty(SMTP_PASSWORD) ? 'pass' : 'fail', 'message' => !empty(SMTP_PASSWORD) ? 'Set (' . strlen(SMTP_PASSWORD) . ' chars)' : 'Not set'];
    $checks[] = ['name' => 'From Email', 'status' => !empty(SMTP_FROM_EMAIL) && filter_var(SMTP_FROM_EMAIL, FILTER_VALIDATE_EMAIL) ? 'pass' : 'fail', 'message' => SMTP_FROM_EMAIL ?: 'Not set'];
    $checks[] = ['name' => 'From Name', 'status' => !empty(SMTP_FROM_NAME) ? 'pass' : 'warn', 'message' => SMTP_FROM_NAME . ' (raw value)'];
    $checks[] = ['name' => 'Encryption', 'status' => in_array(SMTP_ENCRYPTION, ['tls', 'ssl', '']) ? 'pass' : 'warn', 'message' => SMTP_ENCRYPTION ?: 'None'];

    // 3. From Name issue detection
    $fromName = SMTP_FROM_NAME;
    if (strpos($fromName, '"') !== false || strpos($fromName, "'") !== false) {
        $checks[] = ['name' => 'FROM NAME WARNING', 'status' => 'fail', 'message' => "Contains quotes! Raw: [$fromName] - Remove quotes from .env SMTP_FROM_NAME value"];
        writeDebugLog("WARNING: SMTP_FROM_NAME contains quotes: [$fromName]");
    }

    // 4. From Email domain
    $fromDomain = substr(strrchr(SMTP_FROM_EMAIL, "@"), 1);
    $checks[] = ['name' => 'From Domain', 'status' => 'info', 'message' => $fromDomain];

    // 5. DKIM
    $dkimExists = file_exists(DKIM_PRIVATE_KEY_FILE);
    $checks[] = ['name' => 'DKIM Key', 'status' => $dkimExists ? 'pass' : 'warn', 'message' => $dkimExists ? 'Found at ' . DKIM_PRIVATE_KEY_FILE : 'Not found'];
    if ($dkimExists) {
        $keyContent = file_get_contents(DKIM_PRIVATE_KEY_FILE);
        $hasValidKey = strpos($keyContent, 'BEGIN PRIVATE KEY') !== false || strpos($keyContent, 'BEGIN RSA PRIVATE KEY') !== false;
        $checks[] = ['name' => 'DKIM Key Valid', 'status' => $hasValidKey ? 'pass' : 'fail', 'message' => $hasValidKey ? 'Looks valid' : 'Does not contain valid PEM key'];
    }

    // 6. PHPMailer
    $checks[] = ['name' => 'PHPMailer', 'status' => file_exists(__DIR__ . '/phpmailer/src/PHPMailer.php') ? 'pass' : 'fail', 'message' => file_exists(__DIR__ . '/phpmailer/src/PHPMailer.php') ? 'Found' : 'Missing'];

    // 7. Logs dir
    $checks[] = ['name' => 'Logs Dir', 'status' => is_dir(__DIR__ . '/logs') ? 'pass' : 'warn', 'message' => is_dir(__DIR__ . '/logs') ? 'Found' : 'Missing'];

    // 8. Server info
    $checks[] = ['name' => 'PHP Version', 'status' => 'info', 'message' => phpversion()];
    $checks[] = ['name' => 'OpenSSL', 'status' => extension_loaded('openssl') ? 'pass' : 'fail', 'message' => extension_loaded('openssl') ? 'Loaded' : 'Missing - TLS will fail'];
    $checks[] = ['name' => 'Server IP', 'status' => 'info', 'message' => gethostbyname(gethostname())];

    // 9. Check for the common "RSET" issue
    writeDebugLog("SMTP_FROM_EMAIL=" . SMTP_FROM_EMAIL);
    writeDebugLog("SMTP_FROM_NAME=" . SMTP_FROM_NAME);
    writeDebugLog("SMTP_HOST=" . SMTP_HOST);
    writeDebugLog("SMTP_PORT=" . SMTP_PORT);
    writeDebugLog("SMTP_ENCRYPTION=" . SMTP_ENCRYPTION);
    writeDebugLog("SMTP_USERNAME=" . SMTP_USERNAME);
    writeDebugLog("SMTP_PASSWORD length=" . strlen(SMTP_PASSWORD));

    writeDebugLog("=== DIAGNOSTICS COMPLETE ===");

    return [
        'type' => 'info',
        'title' => 'Full Diagnostics',
        'checks' => $checks,
    ];
}

function checkDNSRecords() {
    $domain = substr(strrchr(SMTP_FROM_EMAIL, "@"), 1);
    $checks = [];
    $log = '';

    writeDebugLog("=== DNS CHECK for $domain ===");

    // MX records
    $mx = dns_get_record($domain, DNS_MX);
    if (!empty($mx)) {
        $checks[] = ['name' => "MX Records ($domain)", 'status' => 'pass', 'message' => implode(', ', array_column($mx, 'target'))];
    } else {
        $checks[] = ['name' => "MX Records ($domain)", 'status' => 'fail', 'message' => 'No MX records found'];
    }
    $log .= "MX: " . json_encode($mx) . "\n";

    // SPF (TXT records)
    $txt = dns_get_record($domain, DNS_TXT);
    $spfFound = false;
    $dmarcFound = false;
    $allTxt = [];
    foreach ($txt as $record) {
        $allTxt[] = $record['txt'];
        if (stripos($record['txt'], 'v=spf1') !== false) {
            $spfFound = true;
            $checks[] = ['name' => "SPF Record ($domain)", 'status' => 'pass', 'message' => $record['txt']];
        }
    }
    if (!$spfFound) {
        $checks[] = ['name' => "SPF Record ($domain)", 'status' => 'fail', 'message' => 'No SPF record found - Gmail will likely reject'];
    }

    // DMARC
    $dmarc = dns_get_record('_dmarc.' . $domain, DNS_TXT);
    if (!empty($dmarc)) {
        foreach ($dmarc as $record) {
            if (stripos($record['txt'], 'v=DMARC1') !== false) {
                $dmarcFound = true;
                $checks[] = ['name' => "DMARC Record ($domain)", 'status' => 'pass', 'message' => $record['txt']];
            }
        }
    }
    if (!$dmarcFound) {
        $checks[] = ['name' => "DMARC Record ($domain)", 'status' => 'warn', 'message' => 'No DMARC record found'];
    }

    // DKIM DNS record (selector._domainkey.domain)
    $dkimSelector = env('DKIM_SELECTOR', 'default');
    $dkimDns = dns_get_record($dkimSelector . '._domainkey.' . $domain, DNS_TXT);
    if (!empty($dkimDns)) {
        foreach ($dkimDns as $record) {
            $checks[] = ['name' => "DKIM DNS ($dkimSelector._domainkey.$domain)", 'status' => 'pass', 'message' => substr($record['txt'], 0, 120) . '...'];
        }
    } else {
        $checks[] = ['name' => "DKIM DNS ($dkimSelector._domainkey.$domain)", 'status' => 'warn', 'message' => 'No DKIM DNS record found - DKIM signing will fail silently'];
    }

    // All TXT records
    $checks[] = ['name' => "All TXT Records ($domain)", 'status' => 'info', 'message' => implode(' | ', $allTxt) ?: 'None'];

    // A record
    $a = dns_get_record($domain, DNS_A);
    if (!empty($a)) {
        $checks[] = ['name' => "A Record ($domain)", 'status' => 'pass', 'message' => implode(', ', array_column($a, 'ip') ?: $a)];
    } else {
        $checks[] = ['name' => "A Record ($domain)", 'status' => 'warn', 'message' => 'No A record found'];
    }

    writeDebugLog("DNS Results: TXT=" . json_encode($allTxt));
    writeDebugLog("=== DNS CHECK COMPLETE ===");

    return [
        'type' => 'info',
        'title' => "DNS Records for $domain",
        'checks' => $checks,
    ];
}

function testSMTPConnection() {
    writeDebugLog("=== SMTP CONNECTION TEST ===");

    try {
        $mail = new PHPMailer(true);
        $mail->SMTPDebug = SMTP::DEBUG_CONNECTION;
        $debugOutput = '';
        $mail->Debugoutput = function($str, $level) use (&$debugOutput) {
            $clean = trim($str);
            if (!empty($clean)) {
                $debugOutput .= "[Level $level] $clean\n";
                writeDebugLog("SMTP: $clean");
            }
        };

        $mail->isSMTP();
        $mail->Host = SMTP_HOST;
        $mail->SMTPAuth = !empty(SMTP_USERNAME);
        $mail->Username = SMTP_USERNAME;
        $mail->Password = SMTP_PASSWORD;
        $mail->Port = SMTP_PORT;
        $mail->SMTPKeepAlive = false;
        $mail->Timeout = 15;

        if (SMTP_ENCRYPTION === 'ssl') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        } elseif (SMTP_ENCRYPTION === 'tls') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        } else {
            $mail->SMTPSecure = false;
        }

        $mail->SMTPOptions = [
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true,
            ]
        ];

        $mail->preconnect();

        writeDebugLog("Connection successful");

        return [
            'type' => 'success',
            'title' => 'SMTP Connection Test',
            'message' => 'Connected to ' . SMTP_HOST . ':' . SMTP_PORT,
            'details' => [
                'Host' => SMTP_HOST,
                'Port' => SMTP_PORT,
                'Encryption' => SMTP_ENCRYPTION ?: 'None',
                'Auth' => !empty(SMTP_USERNAME) ? 'Yes' : 'No',
            ],
            'debug' => $debugOutput,
        ];

    } catch (Exception $e) {
        writeDebugLog("CONNECTION FAILED: " . $e->getMessage());
        return [
            'type' => 'error',
            'title' => 'SMTP Connection Failed',
            'message' => $e->getMessage(),
            'debug' => $debugOutput ?? '',
        ];
    }
}

function sendTestEmail($to, $subject) {
    if (empty($to) || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return ['type' => 'error', 'title' => 'Invalid Email', 'message' => 'Please provide a valid email address'];
    }

    writeDebugLog("=== SENDING TEST EMAIL to $to ===");

    try {
        $mail = new PHPMailer(true);

        // Maximum debug level
        $mail->SMTPDebug = SMTP::DEBUG_SERVER;
        $debugOutput = '';
        $mail->Debugoutput = function($str, $level) use (&$debugOutput) {
            $clean = trim($str);
            if (!empty($clean)) {
                $debugOutput .= "[Level $level] $clean\n";
                writeDebugLog("SMTP SEND: $clean");
            }
        };

        $mail->isSMTP();
        $mail->Host = SMTP_HOST;
        $mail->SMTPAuth = !empty(SMTP_USERNAME);
        $mail->Username = SMTP_USERNAME;
        $mail->Password = SMTP_PASSWORD;
        $mail->Port = SMTP_PORT;
        $mail->SMTPKeepAlive = false;
        $mail->Timeout = 30;

        if (SMTP_ENCRYPTION === 'ssl') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        } elseif (SMTP_ENCRYPTION === 'tls') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        } else {
            $mail->SMTPSecure = false;
        }

        $mail->SMTPOptions = [
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true,
            ]
        ];

        // Clear defaults and set manually to avoid duplicate headers
        $mail->clearAddresses();
        $mail->clearReplyTos();
        $mail->clearCustomHeaders();

        // Clean from name - remove any stray quotes
        $cleanFromName = trim(SMTP_FROM_NAME, "\"'");

        $mail->setFrom(SMTP_FROM_EMAIL, $cleanFromName);
        $mail->addReplyTo(SMTP_FROM_EMAIL, $cleanFromName);
        $mail->addAddress($to);

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->CharSet = 'UTF-8';
        $mail->Encoding = 'base64';

        // Remove default X-Mailer, set custom one
        $mail->XMailer = 'StockEx Debug Mailer v1.0';

        $emailId = bin2hex(random_bytes(8));
        $timestamp = date('Y-m-d H:i:s e');

        $htmlBody = '
        <!DOCTYPE html>
        <html>
        <head><meta charset="UTF-8"></head>
        <body style="font-family:Arial,sans-serif;line-height:1.6;color:#333;max-width:600px;margin:0 auto;padding:20px;">
            <div style="background:#007bff;color:white;padding:20px;text-align:center;border-radius:5px 5px 0 0;">
                <h2 style="margin:0;">StockEx Email Debug Test</h2>
            </div>
            <div style="background:#f8f9fa;padding:20px;border:1px solid #ddd;">
                <p style="background:#28a745;color:white;padding:5px 10px;border-radius:3px;display:inline-block;">TEST EMAIL</p>
                <h3>Email Configuration Test</h3>
                <p>This is a debug test email from StockEx.</p>
                <table style="width:100%;border-collapse:collapse;margin:15px 0;">
                    <tr><td style="padding:8px;border:1px solid #ddd;font-weight:bold;">SMTP Host</td><td style="padding:8px;border:1px solid #ddd;">' . htmlspecialchars(SMTP_HOST) . '</td></tr>
                    <tr><td style="padding:8px;border:1px solid #ddd;font-weight:bold;">Port</td><td style="padding:8px;border:1px solid #ddd;">' . SMTP_PORT . '</td></tr>
                    <tr><td style="padding:8px;border:1px solid #ddd;font-weight:bold;">Encryption</td><td style="padding:8px;border:1px solid #ddd;">' . (SMTP_ENCRYPTION ?: 'None') . '</td></tr>
                    <tr><td style="padding:8px;border:1px solid #ddd;font-weight:bold;">From</td><td style="padding:8px;border:1px solid #ddd;">' . htmlspecialchars(SMTP_FROM_EMAIL) . '</td></tr>
                    <tr><td style="padding:8px;border:1px solid #ddd;font-weight:bold;">From Name</td><td style="padding:8px;border:1px solid #ddd;">' . htmlspecialchars($cleanFromName) . '</td></tr>
                    <tr><td style="padding:8px;border:1px solid #ddd;font-weight:bold;">Sent At</td><td style="padding:8px;border:1px solid #ddd;">' . $timestamp . '</td></tr>
                    <tr><td style="padding:8px;border:1px solid #ddd;font-weight:bold;">Email ID</td><td style="padding:8px;border:1px solid #ddd;">' . $emailId . '</td></tr>
                </table>
                <p style="color:#666;font-size:12px;">DKIM: ' . (file_exists(DKIM_PRIVATE_KEY_FILE) ? 'Key exists' : 'No key') . '</p>
            </div>
            <div style="background:#6c757d;color:white;padding:10px;text-align:center;border-radius:0 0 5px 5px;font-size:12px;">
                StockEx Debug &copy; ' . date('Y') . ' | ID: ' . $emailId . '
            </div>
        </body>
        </html>';

        $plainBody = "StockEx Email Debug Test\n\n"
                    . "SMTP Host: " . SMTP_HOST . "\n"
                    . "Port: " . SMTP_PORT . "\n"
                    . "Encryption: " . (SMTP_ENCRYPTION ?: 'None') . "\n"
                    . "From: " . SMTP_FROM_EMAIL . "\n"
                    . "Sent: " . $timestamp . "\n"
                    . "Email ID: " . $emailId . "\n";

        $mail->Body = $htmlBody;
        $mail->AltBody = $plainBody;

        $result = $mail->send();

        $lastError = $mail->ErrorInfo;
        writeDebugLog("SEND RESULT: " . ($result ? 'SUCCESS' : 'FAILED'));
        if (!empty($lastError)) {
            writeDebugLog("ERROR INFO: $lastError");
        }

        $summary = [
            'type' => $result ? 'success' : 'error',
            'title' => $result ? 'Email Sent' : 'Email Failed',
            'message' => $result ? "Queued for delivery to $to" : "Failed: $lastError",
            'details' => [
                'To' => $to,
                'From' => SMTP_FROM_EMAIL,
                'From Name (cleaned)' => $cleanFromName,
                'Subject' => $subject,
                'Host' => SMTP_HOST,
                'Port' => SMTP_PORT,
                'Encryption' => SMTP_ENCRYPTION,
                'Auth' => !empty(SMTP_USERNAME) ? SMTP_USERNAME : 'None',
                'DKIM' => file_exists(DKIM_PRIVATE_KEY_FILE) ? 'Key exists' : 'No key',
                'Email ID' => $emailId,
                'Mailer Info' => $lastError ?: 'None',
            ],
            'debug' => $debugOutput,
        ];

        // Possible causes if "sent" but not received
        if ($result) {
            $summary['possible_issues'] = [
                'SPF record missing for ' . $fromDomain . ' - Gmail will reject',
                'DMARC policy is "reject" or "quarantine"',
                'DKIM DNS record missing or mismatched',
                'SMTP relay is silently dropping outbound mail',
                'From domain has no valid MX/A records',
                'Relay server has its own greylisting or rate limiting',
                'Check SMTP relay server mail queue: postqueue -p',
                'Check SMTP relay server mail log: tail -f /var/log/mail.log',
            ];
        }

        return $summary;

    } catch (Exception $e) {
        writeDebugLog("EXCEPTION: " . $e->getMessage());
        return [
            'type' => 'error',
            'title' => 'Email Send Exception',
            'message' => $e->getMessage(),
            'details' => [
                'Mailer Error' => $mail->ErrorInfo ?? 'Unknown',
                'PHP Error' => error_get_last()['message'] ?? 'None',
            ],
            'debug' => $debugOutput ?? '',
        ];
    }
}

function rawSMTPTest() {
    writeDebugLog("=== RAW SMTP TEST (fsockopen) ===");

    $host = SMTP_HOST;
    $port = SMTP_PORT;
    $timeout = 10;
    $results = [];

    // Test 1: TCP connection
    $start = microtime(true);
    $errno = 0;
    $errstr = '';
    $fp = @fsockopen($host, $port, $errno, $errstr, $timeout);
    $connectTime = round((microtime(true) - $start) * 1000) . 'ms';

    if ($fp) {
        $results[] = ['name' => 'TCP Connection', 'status' => 'pass', 'message' => "Connected in $connectTime"];

        // Read banner
        $banner = fgets($fp, 512);
        $results[] = ['name' => 'Server Banner', 'status' => 'info', 'message' => trim($banner)];
        writeDebugLog("BANNER: $banner");

        // EHLO
        fwrite($fp, "EHLO stockex-debug.vfsl.co.tz\r\n");
        $ehlo = '';
        while ($line = fgets($fp, 512)) {
            $ehlo .= $line;
            if (substr($line, 3, 1) === ' ') break;
        }
        $results[] = ['name' => 'EHLO Response', 'status' => 'info', 'message' => trim($ehlo)];
        writeDebugLog("EHLO: $ehlo");

        // STARTTLS
        fwrite($fp, "STARTTLS\r\n");
        $starttls = fgets($fp, 512);
        $results[] = ['name' => 'STARTTLS', 'status' => 'info', 'message' => trim($starttls)];
        writeDebugLog("STARTTLS: $starttls");

        if (strpos($starttls, '220') !== false) {
            $crypto = stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT);
            $results[] = ['name' => 'TLS Enabled', 'status' => $crypto ? 'pass' : 'fail', 'message' => $crypto ? 'TLS handshake successful' : 'TLS handshake failed'];
            writeDebugLog("TLS: " . ($crypto ? 'SUCCESS' : 'FAILED'));

            // Re-EHLO after TLS
            fwrite($fp, "EHLO stockex-debug.vfsl.co.tz\r\n");
            $ehlo2 = '';
            while ($line = fgets($fp, 512)) {
                $ehlo2 .= $line;
                if (substr($line, 3, 1) === ' ') break;
            }
            $results[] = ['name' => 'EHLO after TLS', 'status' => 'info', 'message' => trim($ehlo2)];
        }

        // AUTH LOGIN
        fwrite($fp, "AUTH LOGIN\r\n");
        $auth1 = fgets($fp, 512);
        $results[] = ['name' => 'AUTH Request', 'status' => 'info', 'message' => trim($auth1)];
        writeDebugLog("AUTH: $auth1");

        if (strpos($auth1, '334') !== false) {
            fwrite($fp, base64_encode(SMTP_USERNAME) . "\r\n");
            $auth2 = fgets($fp, 512);
            writeDebugLog("USERNAME RESP: $auth2");

            fwrite($fp, base64_encode(SMTP_PASSWORD) . "\r\n");
            $auth3 = fgets($fp, 512);
            $results[] = ['name' => 'AUTH Result', 'status' => strpos($auth3, '235') !== false ? 'pass' : 'fail', 'message' => trim($auth3)];
            writeDebugLog("PASSWORD RESP: $auth3");
        }

        // QUIT
        fwrite($fp, "QUIT\r\n");
        fgets($fp, 512);
        fclose($fp);

    } else {
        $results[] = ['name' => 'TCP Connection', 'status' => 'fail', 'message' => "Failed: $errstr ($errno)"];
        writeDebugLog("CONNECTION FAILED: $errstr ($errno)");
    }

    return [
        'type' => 'info',
        'title' => 'Raw SMTP Test (fsockopen)',
        'checks' => $results,
    ];
}

if (isset($_GET['ajax']) && $_GET['ajax'] === 'log') {
    header('Content-Type: text/plain');
    echo readDebugLog();
    exit;
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
        body { background: #1a1a2e; color: #e0e0e0; font-family: 'Segoe UI', monospace; }
        .debug-header { background: linear-gradient(135deg, #e74c3c 0%, #c0392b 100%); color: white; padding: 20px 0; }
        .card { background: #16213e; border: 1px solid #0f3460; margin-bottom: 15px; }
        .card-header { background: #0f3460; color: #e94560; font-weight: 700; border-bottom: 1px solid #e94560; }
        .card-body { color: #e0e0e0; }
        .result-success { border-left: 4px solid #2ecc71; background: rgba(46,204,113,0.1); }
        .result-error { border-left: 4px solid #e74c3c; background: rgba(231,76,60,0.1); }
        .result-info { border-left: 4px solid #3498db; background: rgba(52,152,219,0.1); }
        .debug-output { background: #0d1117; color: #58a6ff; padding: 15px; border-radius: 5px; font-family: 'Cascadia Code', 'Fira Code', Consolas, monospace; font-size: 11px; max-height: 400px; overflow-y: auto; white-space: pre-wrap; word-break: break-all; border: 1px solid #30363d; }
        .config-value { font-family: monospace; background: #0d1117; color: #58a6ff; padding: 2px 6px; border-radius: 3px; border: 1px solid #30363d; }
        .warning-banner { background: rgba(241,196,15,0.15); border: 1px solid #f1c40f; color: #f1c40f; padding: 12px; border-radius: 5px; margin-bottom: 20px; }
        .check-pass { color: #2ecc71; }
        .check-fail { color: #e74c3c; }
        .check-warn { color: #f39c12; }
        .check-info { color: #3498db; }
        .btn-test { margin: 3px; }
        .possible-issues { background: rgba(231,76,60,0.1); border: 1px solid #e74c3c; border-radius: 5px; padding: 15px; margin-top: 15px; }
        .possible-issues h6 { color: #e74c3c; }
        .possible-issues li { color: #e0e0e0; margin-bottom: 5px; font-size: 13px; }
        .table-dark { --bs-table-bg: #16213e; --bs-table-border-color: #0f3460; }
        h5, h6 { color: #e94560; }
        .text-muted { color: #8b949e !important; }
        a { color: #58a6ff; }
    </style>
</head>
<body>
    <div class="debug-header">
        <div class="container">
            <h1><i class="bi bi-bug me-2"></i>Email Debug Console</h1>
            <p class="mb-0">Enhanced diagnostics for email delivery troubleshooting</p>
        </div>
    </div>

    <div class="container py-4">
        <div class="warning-banner">
            <i class="bi bi-exclamation-triangle-fill me-2"></i>
            <strong>TEMPORARY FILE</strong> - Delete after testing: <code>email_debug.php</code>
        </div>

        <div class="row">
            <div class="col-lg-5">
                <div class="card">
                    <div class="card-header"><i class="bi bi-tools me-2"></i>Diagnostics</div>
                    <div class="card-body">
                        <form method="POST" class="mb-2">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                            <input type="hidden" name="action" value="check_config">
                            <button type="submit" class="btn btn-info w-100 btn-test"><i class="bi bi-gear me-1"></i>Full Config Check</button>
                        </form>
                        <form method="POST" class="mb-2">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                            <input type="hidden" name="action" value="dns_check">
                            <button type="submit" class="btn btn-warning w-100 btn-test"><i class="bi bi-globe me-1"></i>DNS Records Check (SPF/DKIM/DMARC)</button>
                        </form>
                        <form method="POST" class="mb-2">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                            <input type="hidden" name="action" value="test_connection">
                            <button type="submit" class="btn btn-primary w-100 btn-test"><i class="bi bi-plug me-1"></i>SMTP Connection Test</button>
                        </form>
                        <form method="POST" class="mb-2">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                            <input type="hidden" name="action" value="raw_smtp">
                            <button type="submit" class="btn btn-secondary w-100 btn-test"><i class="bi bi-hdd-network me-1"></i>Raw SMTP Test (fsockopen)</button>
                        </form>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header"><i class="bi bi-envelope me-2"></i>Send Test Email</div>
                    <div class="card-body">
                        <form method="POST">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                            <input type="hidden" name="action" value="send_test">
                            <div class="mb-2">
                                <input type="email" class="form-control" name="test_email" placeholder="recipient@gmail.com" required
                                       value="<?php echo htmlspecialchars($_POST['test_email'] ?? ''); ?>">
                            </div>
                            <div class="mb-2">
                                <input type="text" class="form-control" name="test_subject"
                                       value="<?php echo htmlspecialchars($_POST['test_subject'] ?? 'Debug Test - ' . date('H:i:s')); ?>">
                            </div>
                            <button type="submit" class="btn btn-success w-100"><i class="bi bi-send me-1"></i>Send Test Email</button>
                        </form>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header"><i class="bi bi-info-circle me-2"></i>Current Config</div>
                    <div class="card-body p-0">
                        <table class="table table-sm table-dark mb-0">
                            <tr><td><strong>Host:</strong></td><td><span class="config-value"><?php echo htmlspecialchars(SMTP_HOST); ?></span></td></tr>
                            <tr><td><strong>Port:</strong></td><td><span class="config-value"><?php echo SMTP_PORT; ?></span></td></tr>
                            <tr><td><strong>TLS:</strong></td><td><span class="config-value"><?php echo htmlspecialchars(SMTP_ENCRYPTION ?: 'None'); ?></span></td></tr>
                            <tr><td><strong>User:</strong></td><td><span class="config-value"><?php echo htmlspecialchars(SMTP_USERNAME ?: '-'); ?></span></td></tr>
                            <tr><td><strong>Pass:</strong></td><td><span class="config-value"><?php echo !empty(SMTP_PASSWORD) ? str_repeat('*', strlen(SMTP_PASSWORD)) . ' (' . strlen(SMTP_PASSWORD) . ' chars)' : '(empty)'; ?></span></td></tr>
                            <tr><td><strong>From:</strong></td><td><span class="config-value"><?php echo htmlspecialchars(SMTP_FROM_EMAIL); ?></span></td></tr>
                            <tr><td><strong>Name:</strong></td><td><span class="config-value"><?php echo htmlspecialchars(SMTP_FROM_NAME); ?></span></td></tr>
                            <tr><td><strong>DKIM:</strong></td><td><span class="config-value"><?php echo file_exists(DKIM_PRIVATE_KEY_FILE) ? 'Key exists' : 'No key'; ?></span></td></tr>
                        </table>
                    </div>
                </div>
            </div>

            <div class="col-lg-7">
                <?php if (!empty($results)): ?>
                    <?php foreach ($results as $result): ?>
                        <div class="card">
                            <div class="card-body result-<?php echo $result['type']; ?>">
                                <h5>
                                    <?php if ($result['type'] === 'success'): ?>
                                        <i class="bi bi-check-circle-fill check-pass me-2"></i>
                                    <?php elseif ($result['type'] === 'error'): ?>
                                        <i class="bi bi-x-circle-fill check-fail me-2"></i>
                                    <?php else: ?>
                                        <i class="bi bi-info-circle-fill check-info me-2"></i>
                                    <?php endif; ?>
                                    <?php echo htmlspecialchars($result['title']); ?>
                                </h5>
                                <p><?php echo htmlspecialchars($result['message'] ?? ''); ?></p>

                                <?php if (!empty($result['details'])): ?>
                                    <table class="table table-sm table-dark mb-3">
                                        <?php foreach ($result['details'] as $key => $value): ?>
                                            <tr><td><strong><?php echo htmlspecialchars($key); ?>:</strong></td><td><span class="config-value"><?php echo htmlspecialchars($value); ?></span></td></tr>
                                        <?php endforeach; ?>
                                    </table>
                                <?php endif; ?>

                                <?php if (!empty($result['checks'])): ?>
                                    <table class="table table-sm table-dark mb-3">
                                        <?php foreach ($result['checks'] as $check): ?>
                                            <tr>
                                                <td width="30">
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

                                <?php if (!empty($result['possible_issues'])): ?>
                                    <div class="possible-issues">
                                        <h6><i class="bi bi-exclamation-triangle me-1"></i> Email sent but not received? Possible causes:</h6>
                                        <ul>
                                            <?php foreach ($result['possible_issues'] as $issue): ?>
                                                <li><?php echo htmlspecialchars($issue); ?></li>
                                            <?php endforeach; ?>
                                        </ul>
                                        <p class="text-muted mb-0" style="font-size:12px;">Most likely: Your SMTP relay server (<code><?php echo htmlspecialchars(SMTP_HOST); ?></code>) is accepting the mail but silently dropping/quarantining it. Check the relay server's mail queue and logs.</p>
                                    </div>
                                <?php endif; ?>

                                <?php if (!empty($result['debug'])): ?>
                                    <div class="mt-3">
                                        <h6><i class="bi bi-terminal me-2"></i>SMTP Transaction Log:</h6>
                                        <div class="debug-output"><?php echo htmlspecialchars($result['debug']); ?></div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>

                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <span><i class="bi bi-journal-text me-2"></i>Persistent Debug Log</span>
                        <div>
                            <form method="POST" class="d-inline">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                <input type="hidden" name="action" value="clear_log">
                                <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash me-1"></i>Clear</button>
                            </form>
                            <button class="btn btn-sm btn-outline-light ms-1" onclick="refreshLog()"><i class="bi bi-arrow-clockwise"></i></button>
                        </div>
                    </div>
                    <div class="card-body">
                        <div id="debugLog" class="debug-output"><?php echo htmlspecialchars(readDebugLog()); ?></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function refreshLog() {
            fetch('email_debug.php?ajax=log').then(r => r.text()).then(t => {
                document.getElementById('debugLog').textContent = t;
            });
        }
        setInterval(refreshLog, 15000);
    </script>
</body>
</html>
