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
    $bankName = trim((string)($input['bank_name'] ?? ''));
    if (strlen($bankName) > 100) $errors[] = 'bank_name must be 100 characters or less.';
    elseif ($bankName !== '') $clean['bank_name'] = $bankName;
    $bankAccount = trim((string)($input['bank_account_number'] ?? ''));
    if ($bankAccount !== '' && !preg_match('/^[A-Za-z0-9\-]{5,50}$/', $bankAccount)) $errors[] = 'bank_account_number: 5-50 alphanumeric characters.';
    elseif ($bankAccount !== '') $clean['bank_account_number'] = $bankAccount;
    $bankBranch = trim((string)($input['bank_branch'] ?? ''));
    if (strlen($bankBranch) > 100) $errors[] = 'bank_branch must be 100 characters or less.';
    elseif ($bankBranch !== '') $clean['bank_branch'] = $bankBranch;
    $currency = strtoupper(trim((string)($input['currency'] ?? '')));
    if ($currency !== '' && !in_array($currency, PORTAL_VALID_CURRENCIES, true)) $errors[] = 'currency must be: ' . implode(', ', PORTAL_VALID_CURRENCIES);
    elseif ($currency !== '') $clean['currency'] = $currency;
    return ['errors' => $errors, 'clean' => $clean];
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
    return ['phone' => $phone, 'email' => $email, 'bank_account_number' => $bankAccount];
}

// ===========================================================================
// Name matching and form submission helpers
// ===========================================================================

/**
 * Fuzzy name match percentage using Levenshtein distance.
 * Normalizes both names, then computes similarity based on
 * word-level matching combined with Levenshtein string distance.
 *
 * Weighted: 60% word overlap + 40% Levenshtein ratio.
 * This handles typos, missing middle names, and reversed order.
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

    // Word overlap (order-insensitive)
    $wa = array_unique(explode(' ', $a));
    $wb = array_unique(explode(' ', $b));
    $wordScore = count($wa) > 0 && count($wb) > 0
        ? count(array_intersect($wa, $wb)) / max(count($wa), count($wb))
        : 0;

    // Levenshtein string similarity
    $lev = levenshtein($a, $b);
    $maxLen = max(mb_strlen($a), mb_strlen($b));
    $levScore = $maxLen > 0 ? 1 - ($lev / $maxLen) : 0;

    // Weighted combination
    $pct = (int)round(($wordScore * 0.6 + $levScore * 0.4) * 100);

    // Also check if any single input word is a substring of a stored word
    // (handles Swahili name variations: e.g., "MBARAKA" vs "MBARAKA SAIDI")
    foreach ($wb as $iw) {
        foreach ($wa as $sw) {
            if (mb_strlen($iw) >= 3 && mb_strpos($sw, $iw) !== false) {
                $pct = max($pct, (int)round(($wordScore * 0.6 + $levScore * 0.4 + 0.15) * 100));
            }
        }
    }

    return min(100, $pct);
}

/**
 * Mask a client name, e.g. "CH*****GO".
 */
function portal_mask_name($name) {
    $name = trim((string)$name);
    if ($name === '') return '';
    $len = mb_strlen($name);
    if ($len <= 4) return $name;
    $stars = max(3, $len - 4);
    return mb_substr($name, 0, 2) . str_repeat('*', $stars) . mb_substr($name, -2);
}

/**
 * Normalize a Tanzanian phone number to E.164.
 */
function portal_normalize_phone($phone) {
    return sms_normalize_number((string)$phone);
}
