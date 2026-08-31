<?php
/**
 * StockEx Client Portal - Shared Helpers
 * Secure helpers for the client self-service API (api/portal/index.php).
 *
 * All functions are prefixed `portal_` to avoid collisions with the
 * open read-only API helpers in api/config.php.
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/security_config.php';
require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../includes/sms.php';

// --- Configuration (overridable via config) ---
// By default links point to the dedicated client subdomain (clients.vfsl.co.tz).
if (!defined('PORTAL_PAGE_URL')) {
    define('PORTAL_PAGE_URL', CLIENT_PORTAL_URL . 'client-update.html?t=');
}
if (!defined('PORTAL_DEFAULT_EXPIRY_HOURS')) {
    define('PORTAL_DEFAULT_EXPIRY_HOURS', 72); // 3 days
}
if (!defined('PORTAL_DEFAULT_MAX_USES')) {
    define('PORTAL_DEFAULT_MAX_USES', 20);
}
if (!defined('PORTAL_MAX_EXPIRY_HOURS')) {
    define('PORTAL_MAX_EXPIRY_HOURS', 720); // 30 days ceiling
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

// SMS OTP limits (env-overridable; cost control for a public self-serve flow).
if (!defined('PORTAL_SMS_COOLDOWN_SEC')) {
    define('PORTAL_SMS_COOLDOWN_SEC', max(10, (int)env('SMS_COOLDOWN_SEC', 60)));
}
if (!defined('PORTAL_SMS_MAX_PER_NUMBER_HOUR')) {
    define('PORTAL_SMS_MAX_PER_NUMBER_HOUR', max(1, (int)env('SMS_MAX_PER_NUMBER_HOUR', 3)));
}
if (!defined('PORTAL_SMS_MAX_PER_NUMBER_DAY')) {
    define('PORTAL_SMS_MAX_PER_NUMBER_DAY', max(1, (int)env('SMS_MAX_PER_NUMBER_DAY', 5)));
}
if (!defined('PORTAL_OTP_TTL_SEC')) {
    define('PORTAL_OTP_TTL_SEC', max(60, (int)env('OTP_TTL_SEC', 300)));
}
if (!defined('PORTAL_OTP_MAX_ATTEMPTS')) {
    define('PORTAL_OTP_MAX_ATTEMPTS', max(1, (int)env('OTP_MAX_ATTEMPTS', 3)));
}
if (!defined('PORTAL_SESSION_SELF_HOURS')) {
    define('PORTAL_SESSION_SELF_HOURS', 2); // verified self-claim sessions last 2h
}
if (!defined('PORTAL_SESSION_SELF_USES')) {
    define('PORTAL_SESSION_SELF_USES', 10);
}

/**
 * Send a JSON success response and terminate.
 */
function portal_json($data, $statusCode = 200, $message = null) {
    http_response_code($statusCode);
    $response = [
        'success' => true,
        'status_code' => $statusCode,
        'timestamp' => date('Y-m-d H:i:s')
    ];
    if ($message) {
        $response['message'] = $message;
    }
    if ($data !== null) {
        $response['data'] = $data;
    }
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit();
}

/**
 * Send a JSON error response and terminate.
 */
function portal_error($message, $statusCode = 400, $details = null) {
    http_response_code($statusCode);
    $response = [
        'success' => false,
        'status_code' => $statusCode,
        'error' => $message,
        'timestamp' => date('Y-m-d H:i:s')
    ];
    if ($details !== null) {
        $response['details'] = $details;
    }
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit();
}

/**
 * Client IP address (proxy aware where available).
 */
function portal_client_ip() {
    return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
}

/**
 * Reject any request that attempts a file upload.
 */
function portal_deny_uploads() {
    if (!empty($_FILES)) {
        portal_error('File uploads are not supported by this API.', 415, [
            'uploads' => array_keys($_FILES)
        ]);
    }
}

/**
 * Extract the raw bearer token from the Authorization header.
 */
function portal_get_bearer_token() {
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';

    if ($header === '' && function_exists('getallheaders')) {
        foreach (getallheaders() as $name => $value) {
            if (strtolower($name) === 'authorization') {
                $header = $value;
                break;
            }
        }
    }

    if (preg_match('/^Bearer\s+(.+)$/i', trim($header), $m)) {
        return trim($m[1]);
    }

    return null;
}

/**
 * Read request body. Supports application/json and form-encoded.
 */
function portal_read_input() {
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    if (stripos($contentType, 'application/json') !== false) {
        $raw = file_get_contents('php://input');
        $json = json_decode($raw, true);
        return is_array($json) ? $json : [];
    }
    return $_POST;
}

/**
 * List the columns of a table (cached per request).
 */
function portal_table_columns($db, $table) {
    static $cache = [];
    if (!isset($cache[$table])) {
        $stmt = $db->prepare(
            "SELECT COLUMN_NAME FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t"
        );
        $stmt->execute([':t' => $table]);
        $cache[$table] = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'COLUMN_NAME');
    }
    return $cache[$table];
}

/**
 * Look up a client by CDS account (case-insensitive).
 */
function portal_find_client_by_cds($db, $cdsAccount) {
    $stmt = $db->prepare("SELECT * FROM clients WHERE UPPER(cds_account) = UPPER(:cds) LIMIT 1");
    $stmt->execute([':cds' => $cdsAccount]);
    $client = $stmt->fetch(PDO::FETCH_ASSOC);
    return $client ?: null;
}

/**
 * A client is usable only if is_active and status (if present) are active.
 */
function portal_client_is_active($client) {
    if ((int)($client['is_active'] ?? 0) !== 1) {
        return false;
    }
    if (array_key_exists('status', $client) && !empty($client['status']) && $client['status'] !== 'active') {
        return false;
    }
    return true;
}

/**
 * Validate a raw bearer token. Returns:
 *   ['status' => 'ok',     'token' => row, 'client' => row]
 *   ['status' => 'TOKEN_REVOKED'|'TOKEN_EXPIRED'|'TOKEN_LIMIT'|'TOKEN_NOT_FOUND']
 */
function portal_resolve_token($db, $rawToken) {
    if (empty($rawToken)) {
        return ['status' => 'TOKEN_NOT_FOUND'];
    }

    $hash = hash('sha256', $rawToken);
    $stmt = $db->prepare("SELECT * FROM client_access_tokens WHERE token_hash = :hash LIMIT 1");
    $stmt->execute([':hash' => $hash]);
    $token = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$token) {
        return ['status' => 'TOKEN_NOT_FOUND'];
    }
    if ((int)$token['revoked'] === 1) {
        return ['status' => 'TOKEN_REVOKED'];
    }
    if (strtotime($token['expires_at']) < time()) {
        return ['status' => 'TOKEN_EXPIRED'];
    }
    if ((int)$token['times_used'] >= (int)$token['max_uses']) {
        return ['status' => 'TOKEN_LIMIT'];
    }

    $stmt = $db->prepare("SELECT * FROM clients WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $token['client_id']]);
    $client = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$client || !portal_client_is_active($client)) {
        return ['status' => 'TOKEN_REVOKED'];
    }

    return ['status' => 'ok', 'token' => $token, 'client' => $client];
}

/**
 * Record use of a token (counter + metadata).
 */
function portal_use_token($db, $tokenId, $ip) {
    $stmt = $db->prepare(
        "UPDATE client_access_tokens
         SET times_used = times_used + 1, last_used_at = NOW(), last_ip = :ip
         WHERE id = :id"
    );
    $stmt->execute([':ip' => $ip, ':id' => $tokenId]);
}

/**
 * Revoke all currently-active tokens for a client.
 */
function portal_revoke_active_tokens($db, $clientId) {
    $stmt = $db->prepare(
        "UPDATE client_access_tokens SET revoked = 1
         WHERE client_id = :cid AND revoked = 0"
    );
    $stmt->execute([':cid' => $clientId]);
    return $stmt->rowCount();
}

/**
 * Requires an authenticated staff session.
 * Role levels: system_admin > ceo > finance_officer > trader.
 */
function portal_require_staff($minRole = 'trader') {
    if (!is_logged_in()) {
        portal_error('Authentication required. Staff login needed.', 401);
    }
    $user = get_logged_in_user();
    if (!$user || !check_permission($minRole)) {
        portal_error('Forbidden. You do not have permission to perform this action.', 403);
    }
    return $user;
}

/**
 * Mask sensitive values for logs: first 3 + **** + last 2.
 */
function portal_mask($value) {
    if ($value === null || $value === '' || strlen($value) < 6) {
        return $value;
    }
    return substr($value, 0, 3) . '****' . substr($value, -2);
}

/**
 * Enforce HTTPS in production (allows localhost/dev/test).
 */
function portal_enforce_https() {
    if (!PORTAL_ENFORCE_HTTPS) {
        return;
    }
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (int)($_SERVER['SERVER_PORT'] ?? 443) === 443;
    $isLocal = in_array(portal_client_ip(), ['127.0.0.1', '::1'], true);

    if (!$isHttps && !$isLocal) {
        portal_error('HTTPS is required to use this API.', 426);
    }
}

/**
 * Write to the client_profile_update_log audit trail.
 */
function portal_log($db, $clientId, $tokenId, $action, $changedFields, $staffId = null) {
    try {
        $stmt = $db->prepare(
            "INSERT INTO client_profile_update_log
                (client_id, token_id, action, changed_fields, ip_address, user_agent, created_by)
             VALUES (:cid, :tid, :action, :fields, :ip, :ua, :by)"
        );
        $stmt->execute([
            ':cid' => $clientId,
            ':tid' => $tokenId,
            ':action' => $action,
            ':fields' => $changedFields !== null ? json_encode($changedFields) : null,
            ':ip' => portal_client_ip(),
            ':ua' => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
            ':by' => $staffId
        ]);
    } catch (Exception $e) {
        error_log('client_profile_update_log error: ' . $e->getMessage());
    }
}

/**
 * Validate an update_profile payload.
 * Returns ['errors' => [...], 'clean' => [...]]. Empty clean => nothing to update.
 */
function portal_validate_profile($input) {
    $errors = [];
    $clean = [];

    // Phone (Tanzanian format; optional)
    $phone = trim((string)($input['phone'] ?? ''));
    if ($phone !== '') {
        $phoneClean = preg_replace('/[\s\-\(\)]/', '', $phone);
        if (!preg_match('/^(\+?255|0)[0-9]{9}$/', $phoneClean)) {
            $errors[] = 'Phone must be a valid Tanzanian number, e.g. 0755123456 or +255755123456.';
        } else {
            $clean['phone'] = $phoneClean;
        }
    }

    // Email (optional)
    $email = trim((string)($input['email'] ?? ''));
    if ($email !== '') {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Email address is invalid.';
        } else {
            $clean['email'] = $email;
        }
    }

    // Bank name (optional)
    $bankName = trim((string)($input['bank_name'] ?? ''));
    if (strlen($bankName) > 100) {
        $errors[] = 'bank_name must be 100 characters or less.';
    } elseif ($bankName !== '') {
        $clean['bank_name'] = $bankName;
    }

    // Bank account number (optional, alphanumeric)
    $bankAccount = trim((string)($input['bank_account_number'] ?? ''));
    if ($bankAccount !== '') {
        if (!preg_match('/^[A-Za-z0-9\-]{5,50}$/', $bankAccount)) {
            $errors[] = 'bank_account_number must be 5-50 alphanumeric characters (hyphens allowed).';
        } else {
            $clean['bank_account_number'] = $bankAccount;
        }
    }

    // Bank branch (optional)
    $bankBranch = trim((string)($input['bank_branch'] ?? ''));
    if (strlen($bankBranch) > 100) {
        $errors[] = 'bank_branch must be 100 characters or less.';
    } elseif ($bankBranch !== '') {
        $clean['bank_branch'] = $bankBranch;
    }

    // Currency (optional)
    $currency = strtoupper(trim((string)($input['currency'] ?? '')));
    if ($currency !== '') {
        if (!in_array($currency, PORTAL_VALID_CURRENCIES, true)) {
            $errors[] = 'currency must be one of: ' . implode(', ', PORTAL_VALID_CURRENCIES) . '.';
        } else {
            $clean['currency'] = $currency;
        }
    }

    return ['errors' => $errors, 'clean' => $clean];
}

/**
 * Build and return the client contact/bank values, preferring the
 * *_encrypted columns (decrypted) when present and non-empty.
 */
function portal_read_contact($db, $client) {
    $cols = portal_table_columns($db, 'clients');
    $security = new SecurityManager();

    $phone = $client['phone'] ?? null;
    $email = $client['email'] ?? null;
    $bankAccount = $client['bank_account_number'] ?? null;

    if (in_array('phone_encrypted', $cols, true) && !empty($client['phone_encrypted'])) {
        $decrypted = $security->decryptSensitiveData($client['phone_encrypted']);
        if ($decrypted) {
            $phone = $decrypted;
        }
    }
    if (in_array('email_encrypted', $cols, true) && !empty($client['email_encrypted'])) {
        $decrypted = $security->decryptSensitiveData($client['email_encrypted']);
        if ($decrypted) {
            $email = $decrypted;
        }
    }
    if (in_array('bank_account_encrypted', $cols, true) && !empty($client['bank_account_encrypted'])) {
        $decrypted = $security->decryptSensitiveData($client['bank_account_encrypted']);
        if ($decrypted) {
            $bankAccount = $decrypted;
        }
    }

    return ['phone' => $phone, 'email' => $email, 'bank_account_number' => $bankAccount];
}

/**
 * Apply an update_profile payload to the clients table, only touching
 * columns that exist in the current schema.
 * Returns the list of field names actually persisted.
 */
function portal_update_client($db, $clientId, $cleanFields) {
    $cols = portal_table_columns($db, 'clients');
    $security = new SecurityManager();

    $mapping = [
        'phone'               => 'phone',
        'email'               => 'email',
        'bank_name'           => 'bank_name',
        'bank_account_number' => 'bank_account_number',
        'bank_branch'         => 'bank_branch',
        'currency'            => 'currency'
    ];

    $set = [];
    $params = [];
    $applied = [];

    foreach ($mapping as $field => $column) {
        if (array_key_exists($field, $cleanFields) && in_array($column, $cols, true)) {
            $set[] = "`$column` = :$column";
            $params[$column] = $cleanFields[$field];
            $applied[] = $field;
        }
    }

    // Encrypted copies (only if columns exist and a sensitive value changed)
    $encrypted = $security->encryptSensitiveData([
        'phone'               => $cleanFields['phone'] ?? '',
        'email'               => $cleanFields['email'] ?? '',
        'bank_account_number' => $cleanFields['bank_account_number'] ?? ''
    ]);

    $encryptedColumns = [
        'phone_encrypted'               => 'phone',
        'email_encrypted'               => 'email',
        'bank_account_encrypted'        => 'bank_account_number'
    ];
    foreach ($encryptedColumns as $col => $field) {
        if (in_array($col, $cols, true) && array_key_exists($field, $cleanFields)) {
            $set[] = "`$col` = :$col";
            $params[$col] = $encrypted[$field] !== '' ? $encrypted[$field] : null;
        }
    }

    if (empty($set)) {
        return [];
    }

    if (in_array('updated_at', $cols, true)) {
        $set[] = 'updated_at = NOW()';
    }
    if (in_array('kyc_updated_at', $cols, true)) {
        $set[] = 'kyc_updated_at = NOW()';
    }

    $params[':client_id'] = $clientId;
    $sql = "UPDATE clients SET " . implode(', ', $set) . " WHERE id = :client_id";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $applied;
}

// ===========================================================================
// Public self-service flow helpers (SMS OTP verification + first-claim).
// ===========================================================================

/**
 * Normalize a Tanzanian phone into E.164 (2557XXXXXXXX).
 */
function portal_normalize_phone($phone) {
    return sms_normalize_number((string)$phone);
}

/**
 * Send an SMS through the shared gateway.
 */
function portal_send_sms($phone, $message) {
    return sms_send((string)$phone, (string)$message);
}

/**
 * The standard OTP SMS body (ASCII / English so no hex encoding is needed).
 */
function portal_otp_message($code) {
    return 'Your StockEx verification code is ' . $code
        . '. It expires within ' . (int)(PORTAL_OTP_TTL_SEC / 60)
        . ' minutes. Do not share it with anyone.';
}

/**
 * Mask a client name for the name-confirmation step, e.g. "CH*****GO".
 */
function portal_mask_name($name) {
    $name = trim((string)$name);
    if ($name === '') {
        return '';
    }
    $len = mb_strlen($name);
    if ($len <= 4) {
        return $name;
    }
    $stars = max(3, $len - 4);
    return mb_substr($name, 0, 2) . str_repeat('*', $stars) . mb_substr($name, -2);
}

/**
 * Name confirmation matcher: compares the typed name to the stored one,
 * tolerating ordering ("LAST FIRST" vs "FIRST LAST") and extra words.
 */
function portal_name_matches($stored, $input) {
    $norm = function ($s) {
        return preg_replace('/\s+/', ' ', strtoupper(trim((string)$s)));
    };
    $a = $norm($stored);
    $b = $norm($input);
    if ($a === '' || $b === '') {
        return false;
    }
    if ($a === $b) {
        return true;
    }
    $wa = explode(' ', $a);
    $wb = explode(' ', $b);
    return count(array_diff($wa, $wb)) === 0 || count(array_diff($wb, $wa)) === 0;
}

/**
 * The client's verified phone (E.164), or null when none is bound yet.
 */
function portal_verified_phone($db, $client) {
    $cols = portal_table_columns($db, 'clients');
    if (!in_array('phone_verified', $cols, true) || (int)($client['phone_verified'] ?? 0) !== 1) {
        return null;
    }
    $contact = portal_read_contact($db, $client);
    $phone = $contact['phone'] ?? null;
    if ($phone === null || $phone === '') {
        return null;
    }
    return sms_normalize_number($phone);
}

/**
 * Channel state for the lookup step. 'established' means an OTP-bound
 * number already exists; 'first_claim' means nothing verified yet.
 */
function portal_channel_state($db, $client) {
    $verified = portal_verified_phone($db, $client);
    if ($verified !== null) {
        return ['state' => 'established', 'phone_e164' => $verified];
    }
    return ['state' => 'first_claim', 'phone_e164' => null];
}

/**
 * Record the fact that a phone number was verified for a client.
 */
function portal_mark_phone_verified($db, $clientId, $phoneE164, $ip) {
    $cols = portal_table_columns($db, 'clients');
    $security = new SecurityManager();
    $set = ["phone_verified = 1", "verified_phone_at = NOW()"];

    if (in_array('phone', $cols, true)) {
        $set[] = 'phone = :phone';
    }
    if (in_array('phone_encrypted', $cols, true)) {
        $enc = $security->encryptSensitiveData(['phone' => $phoneE164]);
        $set[] = 'phone_encrypted = :phone_enc';
    }
    if (in_array('updated_at', $cols, true)) {
        $set[] = 'updated_at = NOW()';
    }
    if (in_array('kyc_updated_at', $cols, true)) {
        $set[] = 'kyc_updated_at = NOW()';
    }

    $stmt = $db->prepare("UPDATE clients SET " . implode(', ', $set) . " WHERE id = :id");
    $params = [':id' => $clientId];
    if (in_array('phone', $cols, true)) {
        $params[':phone'] = $phoneE164;
    }
    if (in_array('phone_encrypted', $cols, true)) {
        $params[':phone_enc'] = $enc['phone'] ?? null;
    }
    $stmt->execute($params);
}

/**
 * Bind an actual stored phone number on an established account (change_phone).
 */
function portal_set_phone($db, $clientId, $phoneE164) {
    $cols = portal_table_columns($db, 'clients');
    $security = new SecurityManager();
    $set = ["phone_verified = 1", "verified_phone_at = NOW()"];
    if (in_array('phone', $cols, true)) {
        $set[] = 'phone = :phone';
    }
    if (in_array('phone_encrypted', $cols, true)) {
        $enc = $security->encryptSensitiveData(['phone' => $phoneE164]);
        $set[] = 'phone_encrypted = :phone_enc';
    }
    if (in_array('updated_at', $cols, true)) {
        $set[] = 'updated_at = NOW()';
    }
    $stmt = $db->prepare("UPDATE clients SET " . implode(', ', $set) . " WHERE id = :id");
    $params = [':id' => $clientId, ':phone' => $phoneE164, ':phone_enc' => $enc['phone'] ?? null];
    $stmt->execute($params);
}

/**
 * Issue an OTP (DB-backed, hashed, single-use). Returns ['code'=>..] on
 * success, or ['error'=>.., 'cooldown_sec'=>..] when a limit is hit.
 */
function portal_otp_issue($db, $cdsAccount, $phoneE164, $purpose, $ip) {
    $cdsAccount = strtoupper(trim($cdsAccount));
    $validPurposes = ['bind_phone', 'session', 'change_phone_old', 'change_phone_new'];
    if (!in_array($purpose, $validPurposes, true)) {
        $purpose = 'bind_phone';
    }

    $stmt = $db->prepare(
        "SELECT TIMESTAMPDIFF(SECOND, MAX(created_at), NOW()) AS elapsed
         FROM otp_verifications
         WHERE phone_e164 = :p AND created_at > (NOW() - INTERVAL 1 DAY)"
    );
    $stmt->execute([':p' => $phoneE164]);
    $elapsed = (int)($stmt->fetch(PDO::FETCH_ASSOC)['elapsed'] ?? 999999);
    if ($elapsed < PORTAL_SMS_COOLDOWN_SEC) {
        return ['error' => 'A code was recently sent to this number.', 'cooldown_sec' => PORTAL_SMS_COOLDOWN_SEC - max(0, $elapsed)];
    }

    $stmt = $db->prepare("SELECT COUNT(*) c FROM otp_verifications WHERE phone_e164 = :p AND created_at > (NOW() - INTERVAL 1 HOUR)");
    $stmt->execute([':p' => $phoneE164]);
    $hourly = (int)$stmt->fetch(PDO::FETCH_ASSOC)['c'];

    $stmt = $db->prepare("SELECT COUNT(*) c FROM otp_verifications WHERE phone_e164 = :p AND created_at > (NOW() - INTERVAL 1 DAY)");
    $stmt->execute([':p' => $phoneE164]);
    $daily = (int)$stmt->fetch(PDO::FETCH_ASSOC)['c'];

    if ($hourly >= PORTAL_SMS_MAX_PER_NUMBER_HOUR) {
        return ['error' => 'Too many codes were sent to this number recently. Please wait.'];
    }
    if ($daily >= PORTAL_SMS_MAX_PER_NUMBER_DAY) {
        return ['error' => 'Daily SMS limit reached for this number. Try again tomorrow.'];
    }

    $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $stmt = $db->prepare(
        "INSERT INTO otp_verifications (cds_account, phone_e164, purpose, code_hash, expires_at, ip_address)
         VALUES (:c, :p, :pur, :h, DATE_ADD(NOW(), INTERVAL " . (int)PORTAL_OTP_TTL_SEC . " SECOND), :ip)"
    );
    $stmt->execute([
        ':c'   => $cdsAccount,
        ':p'   => $phoneE164,
        ':pur' => $purpose,
        ':h'   => hash('sha256', $code),
        ':ip'  => $ip
    ]);
    return ['code' => $code, 'otp_id' => (int)$db->lastInsertId()];
}

/**
 * Verify a submitted code against the latest unconsumed OTP row.
 * Consumes it on success; returns ['ok'=>true,...] or ['ok'=>false,'reason'=>..].
 */
function portal_otp_verify($db, $cdsAccount, $phoneE164, $code) {
    $cdsAccount = strtoupper(trim($cdsAccount));
    $stmt = $db->prepare(
        "SELECT o.*, (o.expires_at > NOW()) AS otp_fresh
         FROM otp_verifications o
         WHERE o.cds_account = :c AND o.phone_e164 = :p AND o.consumed = 0
         ORDER BY o.id DESC LIMIT 1"
    );
    $stmt->execute([':c' => $cdsAccount, ':p' => $phoneE164]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return ['ok' => false, 'reason' => 'No verification found. Request a new code.'];
    }
    if (!(int)($row['otp_fresh'] ?? 0)) {
        return ['ok' => false, 'reason' => 'Code expired. Request a new one.'];
    }
    if ((int)$row['attempts'] >= PORTAL_OTP_MAX_ATTEMPTS) {
        return ['ok' => false, 'reason' => 'Too many attempts. Request a new code.'];
    }

    if (hash_equals($row['code_hash'], hash('sha256', trim((string)$code)))) {
        $db->prepare("UPDATE otp_verifications SET consumed = 1, attempts = attempts + 1 WHERE id = :id")
            ->execute([':id' => $row['id']]);
        return ['ok' => true, 'otp_id' => (int)$row['id'], 'purpose' => $row['purpose']];
    }

    $db->prepare("UPDATE otp_verifications SET attempts = attempts + 1 WHERE id = :id")
        ->execute([':id' => $row['id']]);
    return ['ok' => false, 'reason' => 'Incorrect code.'];
}

/**
 * Check whether a new phone number has already been verified for login
 * purposes inside the current session (server-side, not client-declarable).
 */
function portal_new_phone_verified_for($db, $clientId, $newPhoneE164, $since) {
    $stmt = $db->prepare(
        "SELECT COUNT(*) c FROM otp_verifications v
         JOIN clients cl ON UPPER(cl.cds_account) = v.cds_account
         WHERE cl.id = :cid
           AND v.purpose = 'change_phone_new'
           AND v.consumed = 1
           AND v.phone_e164 = :p
           AND v.created_at > :since
         LIMIT 1"
    );
    $stmt->execute([':cid' => $clientId, ':p' => $newPhoneE164, ':since' => $since]);
    return (int)$stmt->fetch(PDO::FETCH_ASSOC)['c'] > 0;
}

/**
 * Create a self-service session token (source='self') and return the raw token.
 */
function portal_create_self_token($db, $clientId, $ip) {
    $security = new SecurityManager();
    $raw = $security->generateSecureToken(32);
    $stmt = $db->prepare(
        "INSERT INTO client_access_tokens
            (client_id, token_hash, expires_at, max_uses, created_by, ip_address, last_ip, `source`)
         VALUES (:cid, :h, DATE_ADD(NOW(), INTERVAL " . (int)PORTAL_SESSION_SELF_HOURS . " HOUR), :uses, NULL, :ip, :ip, 'self')"
    );
    $stmt->execute([
        ':cid'  => $clientId,
        ':h'    => hash('sha256', $raw),
        ':uses' => PORTAL_SESSION_SELF_USES,
        ':ip'   => $ip
    ]);
    return [
        'token'      => $raw,
        'token_id'   => (int)$db->lastInsertId(),
        'expires_at' => date('Y-m-d H:i:s', time() + PORTAL_SESSION_SELF_HOURS * 3600)
    ];
}

/**
 * Add a row to the staff verification queue (no duplicate open rows).
 */
function portal_enqueue_verification($db, $clientId, $kind, $reason, $ip) {
    try {
        $stmt = $db->prepare(
            "SELECT id FROM client_verification_queue
             WHERE client_id = :c AND kind = :k AND status = 'open' LIMIT 1"
        );
        $stmt->execute([':c' => $clientId, ':k' => $kind]);
        if ($stmt->fetch()) {
            return false;
        }
        $stmt = $db->prepare(
            "INSERT INTO client_verification_queue (client_id, kind, reason, client_ip)
             VALUES (:c, :k, :r, :ip)"
        );
        $stmt->execute([':c' => $clientId, ':k' => $kind, ':r' => $reason, ':ip' => $ip]);
        return true;
    } catch (Exception $e) {
        error_log('verification queue error: ' . $e->getMessage());
        return false;
    }
}