<?php
/**
 * StockEx Client Portal - Shared Helpers
 * Helpers for the open-form submission API (api/portal/index.php).
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/security_config.php';
require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../includes/sms.php';

// --- Configuration ---
if (!defined('PORTAL_PAGE_URL')) {
    define('PORTAL_PAGE_URL', CLIENT_PORTAL_URL . '?t=');
}
if (!defined('PORTAL_DEFAULT_EXPIRY_HOURS')) {
    define('PORTAL_DEFAULT_EXPIRY_HOURS', 24);
}
if (!defined('PORTAL_MAX_EXPIRY_HOURS')) {
    define('PORTAL_MAX_EXPIRY_HOURS', 720);
}
if (!defined('PORTAL_DEFAULT_MAX_USES')) {
    define('PORTAL_DEFAULT_MAX_USES', 10);
}
if (!defined('PORTAL_MAX_MAX_USES')) {
    define('PORTAL_MAX_MAX_USES', 100);
}
if (!defined('PORTAL_VALID_CURRENCIES')) {
    define('PORTAL_VALID_CURRENCIES', ['TZS', 'USD', 'EUR', 'GBP']);
}
if (!defined('PORTAL_ENFORCE_HTTPS')) {
    define('PORTAL_ENFORCE_HTTPS', true);
}

// --- Core helpers (unchanged) ---

function portal_json($data, $statusCode = 200, $message = null) {
    // Discard any stray output (PHP notices/warnings) so the response is pure JSON.
    while (ob_get_level() > 0) { ob_end_clean(); }
    http_response_code($statusCode);
    $response = [
        'success' => true,
        'status_code' => $statusCode,
        'timestamp' => date('Y-m-d H:i:s')
    ];
    if ($message) $response['message'] = $message;
    if ($data !== null) $response['data'] = $data;
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit();
}

function portal_error($message, $statusCode = 400, $details = null) {
    // Discard any stray output (PHP notices/warnings) so the response is pure JSON.
    while (ob_get_level() > 0) { ob_end_clean(); }
    http_response_code($statusCode);
    $response = [
        'success' => false,
        'status_code' => $statusCode,
        'error' => $message,
        'timestamp' => date('Y-m-d H:i:s')
    ];
    if ($details !== null) $response['details'] = $details;
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit();
}

function portal_client_ip() {
    return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
}

function portal_deny_uploads() {
    if (!empty($_FILES)) {
        portal_error('File uploads are not supported by this API.', 415, ['uploads' => array_keys($_FILES)]);
    }
}

function portal_get_bearer_token() {
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if ($header === '' && function_exists('getallheaders')) {
        foreach (getallheaders() as $name => $value) {
            if (strtolower($name) === 'authorization') { $header = $value; break; }
        }
    }
    if (preg_match('/^Bearer\s+(.+)$/i', trim($header), $m)) return trim($m[1]);
    return null;
}

function portal_read_input() {
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    if (stripos($contentType, 'application/json') !== false) {
        $raw = file_get_contents('php://input');
        $json = json_decode($raw, true);
        return is_array($json) ? $json : [];
    }
    return $_POST;
}


function portal_text_length($value) {
    $value = (string)$value;
    return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
}

function portal_text_substr($value, $start, $length = null) {
    $value = (string)$value;
    if (function_exists('mb_substr')) {
        return $length === null ? mb_substr($value, $start, null, 'UTF-8') : mb_substr($value, $start, $length, 'UTF-8');
    }
    return $length === null ? substr($value, $start) : substr($value, $start, $length);
}

function portal_table_columns($db, $table) {
    static $cache = [];
    if (!isset($cache[$table])) {
        $stmt = $db->prepare("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t");
        $stmt->execute([':t' => $table]);
        $cache[$table] = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'COLUMN_NAME');
    }
    return $cache[$table];
}

function portal_find_client_by_cds($db, $cdsAccount) {
    $stmt = $db->prepare("SELECT * FROM clients WHERE UPPER(cds_account) = UPPER(:cds) LIMIT 1");
    $stmt->execute([':cds' => $cdsAccount]);
    $client = $stmt->fetch(PDO::FETCH_ASSOC);
    return $client ?: null;
}

function portal_client_is_active($client) {
    if ((int)($client['is_active'] ?? 0) !== 1) return false;
    if (array_key_exists('status', $client) && !empty($client['status']) && $client['status'] !== 'active') return false;
    return true;
}

function portal_mask($value) {
    if ($value === null || $value === '' || strlen($value) < 6) return $value;
    return substr($value, 0, 3) . '****' . substr($value, -2);
}

function portal_enforce_https() {
    if (!PORTAL_ENFORCE_HTTPS) return;
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (int)($_SERVER['SERVER_PORT'] ?? 443) === 443;
    $isLocal = in_array(portal_client_ip(), ['127.0.0.1', '::1'], true);
    if (!$isHttps && !$isLocal) portal_error('HTTPS is required to use this API.', 426);
}

function portal_require_staff($minRole = 'trader') {
    if (!is_logged_in()) portal_error('Authentication required.', 401);
    $user = get_logged_in_user();
    if (!$user || !check_permission($minRole)) portal_error('Forbidden.', 403);
    return $user;
}

function portal_log($db, $clientId, $tokenId, $action, $changedFields, $staffId = null) {
    try {
        $stmt = $db->prepare("INSERT INTO client_profile_update_log (client_id, token_id, action, changed_fields, ip_address, user_agent, created_by) VALUES (:cid, :tid, :action, :fields, :ip, :ua, :by)");
        $stmt->execute([':cid' => $clientId, ':tid' => $tokenId, ':action' => $action, ':fields' => $changedFields !== null ? json_encode($changedFields) : null, ':ip' => portal_client_ip(), ':ua' => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255), ':by' => $staffId]);
    } catch (Exception $e) { error_log('portal_log error: ' . $e->getMessage()); }
}

function portal_resolve_token($db, $rawToken) {
    if (empty($rawToken)) return ['status' => 'TOKEN_NOT_FOUND'];
    $hash = hash('sha256', $rawToken);
    $stmt = $db->prepare("SELECT * FROM client_access_tokens WHERE token_hash = :hash LIMIT 1");
    $stmt->execute([':hash' => $hash]);
    $token = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$token) return ['status' => 'TOKEN_NOT_FOUND'];
    if ((int)$token['revoked'] === 1) return ['status' => 'TOKEN_REVOKED'];
    if (strtotime($token['expires_at']) < time()) return ['status' => 'TOKEN_EXPIRED'];
    if ((int)$token['times_used'] >= (int)$token['max_uses']) return ['status' => 'TOKEN_LIMIT'];
    $stmt = $db->prepare("SELECT * FROM clients WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $token['client_id']]);
    $client = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$client || !portal_client_is_active($client)) return ['status' => 'TOKEN_REVOKED'];
    return ['status' => 'ok', 'token' => $token, 'client' => $client];
}

function portal_validate_profile($input) {
    $errors = [];
    $clean = [];
    $phone = trim((string)($input['phone'] ?? ''));
    if ($phone !== '') {
        $phoneClean = preg_replace('/[\s\-\(\)]/', '', $phone);
        if (!preg_match('/^(\+?255|0)[0-9]{9}$/', $phoneClean)) $errors[] = 'Phone must be a valid Tanzanian number.';
        else $clean['phone'] = $phoneClean;
    }
    $email = trim((string)($input['email'] ?? ''));
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Email address is invalid.';
    elseif ($email !== '') $clean['email'] = $email;
    $address = trim((string)($input['address'] ?? ''));
    if (portal_text_length($address) > 500) $errors[] = 'address must be 500 characters or less.';
    elseif ($address !== '') $clean['address'] = $address;
    $bankName = trim((string)($input['bank_name'] ?? ''));
    if (strlen($bankName) > 100) $errors[] = 'bank_name must be 100 characters or less.';
    elseif ($bankName !== '') $clean['bank_name'] = $bankName;
    $bankAccount = trim((string)($input['bank_account_number'] ?? ''));
    if ($bankAccount !== '' && !preg_match('/^[A-Za-z0-9 .\-\/]{5,50}$/', $bankAccount)) $errors[] = 'bank_account_number: 5-50 letters, numbers, spaces, dots, slashes or hyphens.';
    elseif ($bankAccount !== '') $clean['bank_account_number'] = $bankAccount;
    $bankBranch = trim((string)($input['bank_branch'] ?? ''));
    if (strlen($bankBranch) > 100) $errors[] = 'bank_branch must be 100 characters or less.';
    elseif ($bankBranch !== '') $clean['bank_branch'] = $bankBranch;
    $currency = strtoupper(trim((string)($input['currency'] ?? '')));
    if ($currency !== '' && !in_array($currency, PORTAL_VALID_CURRENCIES, true)) $errors[] = 'currency must be: ' . implode(', ', PORTAL_VALID_CURRENCIES);
    elseif ($currency !== '') $clean['currency'] = $currency;
    return ['errors' => $errors, 'clean' => $clean];
}

/**
 * Validate the flexible payout methods used by the public portal.
 * Multiple methods may be selected. Each selected method must contain
 * the minimum details required to make it operationally useful.
 */
function portal_validate_payment_methods($raw, $requireAtLeastOne = true) {
    $errors = [];
    $clean = [];

    if (!is_array($raw)) {
        return [
            'errors' => $requireAtLeastOne ? ['Select at least one payment method.'] : [],
            'clean' => []
        ];
    }

    $allowed = ['bank', 'phone', 'selcom'];
    foreach ($raw as $method => $details) {
        if (!in_array($method, $allowed, true) || !is_array($details)) continue;

        if ($method === 'bank') {
            $bankName = trim((string)($details['bank_name'] ?? ''));
            $account = trim((string)($details['account_number'] ?? $details['bank_account_number'] ?? ''));
            $branch = trim((string)($details['branch'] ?? $details['bank_branch'] ?? ''));
            $currency = strtoupper(trim((string)($details['currency'] ?? 'TZS')));

            if ($bankName === '') $errors[] = 'Bank name is required for bank payments.';
            if (portal_text_length($bankName) > 100) $errors[] = 'Bank name must be 100 characters or less.';
            if ($account === '') $errors[] = 'Bank account number is required for bank payments.';
            elseif (!preg_match('/^[A-Za-z0-9 .\-\/]{5,50}$/', $account)) $errors[] = 'Bank account number format is invalid.';
            if (portal_text_length($branch) > 100) $errors[] = 'Bank branch must be 100 characters or less.';
            if (!in_array($currency, PORTAL_VALID_CURRENCIES, true)) $errors[] = 'Invalid bank currency.';

            if ($bankName !== '' && $account !== '' && in_array($currency, PORTAL_VALID_CURRENCIES, true)) {
                $clean['bank'] = [
                    'bank_name' => $bankName,
                    'account_number' => $account,
                    'branch' => $branch,
                    'currency' => $currency
                ];
            }
        }

        if ($method === 'phone') {
            $phone = portal_normalize_phone($details['phone_number'] ?? $details['phone'] ?? '');
            $provider = trim((string)($details['provider'] ?? ''));

            if (!$phone) $errors[] = 'A valid payment phone number is required for mobile payments.';
            if ($provider === '') $errors[] = 'Mobile payment provider is required.';
            elseif (portal_text_length($provider) > 60) $errors[] = 'Mobile payment provider must be 60 characters or less.';

            if ($phone && $provider !== '') {
                $clean['phone'] = [
                    'phone_number' => $phone,
                    'provider' => $provider
                ];
            }
        }

        if ($method === 'selcom') {
            $account = trim((string)($details['account_number'] ?? $details['account'] ?? ''));
            $accountName = trim((string)($details['account_name'] ?? $details['name'] ?? ''));

            if ($account === '') $errors[] = 'Selcom account number is required.';
            elseif (portal_text_length($account) > 80) $errors[] = 'Selcom account number must be 80 characters or less.';
            if ($accountName === '') $errors[] = 'Selcom account name is required.';
            elseif (portal_text_length($accountName) > 120) $errors[] = 'Selcom account name must be 120 characters or less.';

            if ($account !== '' && $accountName !== '') {
                $clean['selcom'] = [
                    'account_number' => $account,
                    'account_name' => $accountName
                ];
            }
        }
    }

    if ($requireAtLeastOne && empty($clean) && empty($errors)) {
        $errors[] = 'Select at least one payment method.';
    }

    return ['errors' => array_values(array_unique($errors)), 'clean' => $clean];
}

/** Build a bank payment method from the legacy flat bank fields. */
function portal_payment_methods_from_legacy($input) {
    $bankName = trim((string)($input['bank_name'] ?? ''));
    $account = trim((string)($input['bank_account_number'] ?? ''));
    $branch = trim((string)($input['bank_branch'] ?? ''));
    $currency = strtoupper(trim((string)($input['currency'] ?? 'TZS')));

    if ($bankName === '' && $account === '' && $branch === '') return [];
    if (!in_array($currency, PORTAL_VALID_CURRENCIES, true)) $currency = 'TZS';

    return [
        'bank' => [
            'bank_name' => $bankName,
            'account_number' => $account,
            'branch' => $branch,
            'currency' => $currency
        ]
    ];
}

function portal_decode_payment_methods($value) {
    if (is_array($value)) return $value;
    if (!is_string($value) || trim($value) === '') return [];
    $decoded = json_decode($value, true);
    return is_array($decoded) ? $decoded : [];
}

function portal_read_contact($db, $client) {
    $cols = portal_table_columns($db, 'clients');
    $security = new SecurityManager();
    $phone = $client['phone'] ?? null;
    $email = $client['email'] ?? null;
    $bankAccount = $client['bank_account_number'] ?? null;

    if (in_array('phone_encrypted', $cols, true) && !empty($client['phone_encrypted'])) {
        $d = $security->decryptSensitiveData($client['phone_encrypted']);
        if ($d) $phone = $d;
    }
    if (in_array('email_encrypted', $cols, true) && !empty($client['email_encrypted'])) {
        $d = $security->decryptSensitiveData($client['email_encrypted']);
        if ($d) $email = $d;
    }
    if (in_array('bank_account_encrypted', $cols, true) && !empty($client['bank_account_encrypted'])) {
        $d = $security->decryptSensitiveData($client['bank_account_encrypted']);
        if ($d) $bankAccount = $d;
    }

    $current = [
        'phone' => $phone,
        'email' => $email,
        'address' => $client['address'] ?? null,
        'bank_name' => $client['bank_name'] ?? null,
        'bank_account_number' => $bankAccount,
        'bank_branch' => $client['bank_branch'] ?? null,
        'currency' => $client['currency'] ?? 'TZS',
        'payment_methods' => []
    ];

    if (in_array('payment_methods', $cols, true) && !empty($client['payment_methods'])) {
        $current['payment_methods'] = portal_decode_payment_methods($client['payment_methods']);
    }

    // Existing customers can be pre-filled before the new JSON field has data.
    if (empty($current['payment_methods']) && ($current['bank_name'] || $current['bank_account_number'] || $current['bank_branch'])) {
        $current['payment_methods'] = portal_payment_methods_from_legacy($current);
    }

    return $current;
}

// ===========================================================================
// Name matching and form submission helpers
// ===========================================================================

/**
 * Flexible name comparison for the client portal.
 *
 * The comparison is token-based rather than tied to first/middle/last-name
 * positions: any two exact stored name parts should pass the default gate.
 * String similarity still contributes a smaller score for ordinary typos and
 * reordered names, while a single name alone is capped below the pass mark.
 */
function portal_name_match_pct($stored, $input) {
    $norm = function ($s) {
        $s = preg_replace('/[^a-zA-Z0-9 ]/', '', strtoupper(trim((string)$s)));
        return preg_replace('/\s+/', ' ', $s);
    };
    $a = $norm($stored);
    $b = $norm($input);
    if ($a === '' || $b === '') return 0;
    if ($a === $b) return 100;

    $wa = array_values(array_unique(array_filter(explode(' ', $a))));
    $wb = array_values(array_unique(array_filter(explode(' ', $b))));
    $intersection = array_intersect($wa, $wb);
    $matched = count($intersection);

    // The portal accepts any two (or more) correct name parts, not a specific
    // first/middle/last-name slot. Weight coverage of what the client typed
    // more strongly than coverage of every stored name part.
    $inputCoverage = count($wb) > 0 ? $matched / count($wb) : 0;
    $storedCoverage = count($wa) > 0 ? $matched / count($wa) : 0;

    $lev = levenshtein($a, $b);
    $maxLen = max(portal_text_length($a), portal_text_length($b));
    $levScore = $maxLen > 0 ? max(0, 1 - ($lev / $maxLen)) : 0;

    $pct = (int)round(($inputCoverage * 0.65 + $storedCoverage * 0.20 + $levScore * 0.15) * 100);

    // Any two exact stored name parts should comfortably pass the default
    // 60% gate, even when the database contains three or four names.
    if (count($wb) >= 2 && $matched === count($wb)) {
        $pct = max($pct, min(96, 76 + min(20, (count($wb) - 2) * 8)));
    }

    // A single name on its own must not satisfy the comparison gate for a
    // multi-part stored name. This keeps the gate useful while allowing any
    // two names regardless of position/order.
    if (count($wb) === 1 && count($wa) > 1) {
        $pct = min($pct, 55);
    }

    // Give a small typo allowance when an input token is substantially the
    // same as a stored token (e.g. one-character spelling variation).
    foreach ($wb as $iw) {
        foreach ($wa as $sw) {
            $longest = max(portal_text_length($iw), portal_text_length($sw));
            if ($longest < 4) continue;
            $distance = levenshtein($iw, $sw);
            if ($distance === 1) {
                $pct = min(100, $pct + 6);
                break;
            }
        }
    }

    return min(100, max(0, $pct));
}

/**
 * Mask a client name, e.g. "CH*****GO".
 */
function portal_mask_name($name) {
    $name = trim((string)$name);
    if ($name === '') return '';
    $len = portal_text_length($name);
    if ($len <= 4) return $name;
    $stars = max(3, $len - 4);
    return portal_text_substr($name, 0, 2) . str_repeat('*', $stars) . portal_text_substr($name, -2);
}

/**
 * Normalize a Tanzanian phone number to E.164.
 */
function portal_normalize_phone($phone) {
    return sms_normalize_number((string)$phone);
}
