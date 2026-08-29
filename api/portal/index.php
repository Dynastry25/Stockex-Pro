<?php
/**
 * StockEx Client Portal - Secure Client Self-Service API
 *
 * Allows clients (via an admin-minted, expiring token link) to update
 * their own contact and bank/payment details. The page never touches the
 * database directly; everything flows through this API.
 *
 * NO file uploads are accepted. Read-only access to other clients is
 * impossible: every token is bound to exactly one client.
 *
 * Actions:
 *   GET  ?action=csrf_token                (staff session)  -> CSRF token for mint/revoke
 *   POST ?action=mint_link                 (staff session)  -> mint expiring token link
 *   GET  ?action=get_profile               (Bearer token)   -> current profile (owner only)
 *   POST ?action=update_profile            (Bearer token)   -> update phone/email/bank details
 *   POST ?action=revoke_token              (Bearer token)   -> self-revoke current token
 *   POST ?action=revoke_link               (staff session)  -> revoke all links for a CDS account
 */

require_once __DIR__ . '/helpers.php';

header('Access-Control-Allow-Origin: ' . CLIENT_PORTAL_URL);
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json; charset=UTF-8');

// Handle browser preflight (same-origin pages do not trigger this).
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit();
}

portal_deny_uploads();
portal_enforce_https();

$ip = portal_client_ip();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

try {
    $db = getDBConnection();
    if (!$db) {
        portal_error('Database connection failed.', 500);
    }
} catch (Exception $e) {
    error_log('Client portal DB error: ' . $e->getMessage());
    portal_error('Database connection failed.', 500);
}

$security = new SecurityManager();

switch ($action) {

    // ---------------------------------------------------------------
    // Staff: get CSRF token (required for mint_link / revoke_link)
    // ---------------------------------------------------------------
    case 'csrf_token':
        assertMethod($method, 'GET');
        portal_require_staff('trader');
        if (!$security->checkRateLimit($ip, 'portal_csrf', 30, 60)) {
            portal_error('Too many requests. Please try again later.', 429);
        }
        $token = $security->generateCSRFToken();
        portal_json(['csrf_token' => $token], 200, 'CSRF token generated.');

    // ---------------------------------------------------------------
    // Staff: mint an expiring client self-service link
    // ---------------------------------------------------------------
    case 'mint_link':
        assertMethod($method, 'POST');
        portal_require_staff('trader');
        if (!$security->checkRateLimit($ip, 'portal_mint', 10, 60)) {
            portal_error('Too many requests. Please try again later.', 429);
        }

        $input = portal_read_input();

        if (!isset($input['csrf_token']) || !$security->verifyCSRFToken($input['csrf_token'])) {
            portal_error('Invalid security token. Fetch a fresh one via ?action=csrf_token.', 403);
        }

        $cdsAccount = strtoupper(trim((string)($input['cds_account'] ?? '')));
        if (!preg_match('/^[A-Z0-9]{5,20}$/', $cdsAccount)) {
            portal_error('Valid CDS account is required (5-20 alphanumeric characters).', 400, [
                'hint' => '/api/portal/index.php?action=csrf_token first, then send csrf_token + cds_account'
            ]);
        }

        $expiresHours = isset($input['expires_hours']) ? (int)$input['expires_hours'] : PORTAL_DEFAULT_EXPIRY_HOURS;
        $expiresHours = max(1, min($expiresHours, PORTAL_MAX_EXPIRY_HOURS));

        $maxUses = isset($input['max_uses']) ? (int)$input['max_uses'] : PORTAL_DEFAULT_MAX_USES;
        $maxUses = max(1, min($maxUses, PORTAL_MAX_MAX_USES));

        $client = portal_find_client_by_cds($db, $cdsAccount);
        if (!$client) {
            portal_error('Client not found with this CDS account.', 404);
        }
        if (!portal_client_is_active($client)) {
            portal_error('This client account is inactive. Please contact the administrator.', 400);
        }

        // One active token per client: revoke any previous active links.
        portal_revoke_active_tokens($db, $client['id']);

        $rawToken = $security->generateSecureToken(32);
        $tokenHash = hash('sha256', $rawToken);
        $expiresAt = date('Y-m-d H:i:s', time() + ($expiresHours * 3600));

        $stmt = $db->prepare(
            "INSERT INTO client_access_tokens
                (client_id, token_hash, expires_at, max_uses, created_by, ip_address, last_ip)
             VALUES (:cid, :hash, :expires, :uses, :by, :ip, :ip)"
        );
        $stmt->execute([
            ':cid'     => $client['id'],
            ':hash'    => $tokenHash,
            ':expires' => $expiresAt,
            ':uses'    => $maxUses,
            ':by'      => (int)get_logged_in_user()['id'],
            ':ip'      => $ip
        ]);
        $tokenId = (int)$db->lastInsertId();

        portal_log($db, $client['id'], $tokenId, 'link_minted', [
            'cds_account'    => $client['cds_account'],
            'expires_at'     => $expiresAt,
            'max_uses'       => $maxUses
        ], (int)get_logged_in_user()['id']);

        $security->auditLog($ip, 'PORTAL_LINK_MINT', $client['cds_account'], true);

        portal_json([
            'client_id'   => (int)$client['id'],
            'client_name' => $client['client_name'],
            'cds_account' => $client['cds_account'],
            'token'       => $rawToken,
            'expires_at'  => $expiresAt,
            'max_uses'    => $maxUses,
            'link'        => PORTAL_PAGE_URL . urlencode($rawToken)
        ], 200, 'Self-service link generated. Send it to the client now — it is shown only once.');

    // ---------------------------------------------------------------
    // Client (Bearer token): read own profile
    // ---------------------------------------------------------------
    case 'get_profile':
        assertMethod($method, 'GET');
        if (!$security->checkRateLimit($ip, 'portal_read', 60, 60)) {
            portal_error('Too many requests. Please try again later.', 429);
        }

        $resolved = portal_resolve_token($db, portal_get_bearer_token());
        if ($resolved['status'] !== 'ok') {
            portal_error('Invalid or expired token.', 401, ['reason' => $resolved['status']]);
        }
        $token = $resolved['token'];
        $client = $resolved['client'];

        portal_use_token($db, $token['id'], $ip);
        portal_log($db, $client['id'], $token['id'], 'profile_read', null);

        $contact = portal_read_contact($db, $client);

        portal_json([
            'client_id'           => (int)$client['id'],
            'client_name'         => $client['client_name'],
            'cds_account'         => $client['cds_account'],
            'client_type'         => $client['client_type'] ?? null,
            'phone'               => $contact['phone'],
            'email'               => $contact['email'],
            'phone_masked'        => portal_mask($contact['phone']),
            'bank_name'           => $client['bank_name'] ?? null,
            'bank_account_number' => $contact['bank_account_number'],
            'bank_account_masked' => portal_mask($contact['bank_account_number']),
            'bank_branch'         => $client['bank_branch'] ?? null,
            'currency'            => $client['currency'] ?? 'TZS',
            'token'               => [
                'expires_at' => $token['expires_at'],
                'times_used' => (int)$token['times_used'],
                'max_uses'   => (int)$token['max_uses']
            ]
        ], 200, 'Profile retrieved successfully.');

    // ---------------------------------------------------------------
    // Client (Bearer token): update contact and bank/payment details
    // ---------------------------------------------------------------
    case 'update_profile':
        assertMethod($method, 'POST');
        if (!$security->checkRateLimit($ip, 'portal_write', 20, 60)) {
            portal_error('Too many requests. Please try again later.', 429);
        }

        $resolved = portal_resolve_token($db, portal_get_bearer_token());
        if ($resolved['status'] !== 'ok') {
            portal_error('Invalid or expired token.', 401, ['reason' => $resolved['status']]);
        }
        $token = $resolved['token'];
        $client = $resolved['client'];

        $input = portal_read_input();

        // Per-token attempt throttle for writes.
        if (!$security->checkRateLimit($ip . ':' . $token['id'], 'portal_update_confirm', 5, 300)) {
            portal_error('Too many attempts. Please wait a few minutes and try again.', 429);
        }

        $validated = portal_validate_profile($input);

        if (!empty($validated['errors'])) {
            portal_error('Validation failed.', 400, ['errors' => $validated['errors']]);
        }
        if (empty($validated['clean'])) {
            portal_error('No editable fields were provided.', 400, [
                'editable_fields' => ['phone', 'email', 'bank_name', 'bank_account_number', 'bank_branch', 'currency']
            ]);
        }

        // Capture masked before/after for the audit trail, but only for
        // fields that actually exist in the schema and were persisted.
        $fields = $validated['clean'];
        $before = portal_read_contact($db, $client);
        $applied = portal_update_client($db, $client['id'], $fields);

        $changed = [];
        foreach ($applied as $field) {
            $newValue = $fields[$field];
            $oldValue = $before[$field] ?? ($client[$field] ?? null);
            $normalizedNew = $field === 'phone' && is_string($newValue)
                ? preg_replace('/[\s\-\(\)]/', '', $newValue)
                : $newValue;
            if ($normalizedNew !== $oldValue) {
                $changed[$field] = ['from' => portal_mask($oldValue), 'to' => portal_mask($normalizedNew)];
            }
        }

        portal_use_token($db, $token['id'], $ip);

        portal_log($db, $client['id'], $token['id'], 'profile_updated', empty($changed) ? null : $changed);
        $security->auditLog($ip, 'PORTAL_PROFILE_UPDATE', $client['cds_account'], true);

        $changedFields = array_keys($changed);
        portal_json([
            'client_id'   => (int)$client['id'],
            'cds_account' => $client['cds_account'],
            'updated'     => $changedFields,
            'remaining_uses' => (int)$token['max_uses'] - (int)$token['times_used'] - 1
        ], 200, empty($changedFields) ? 'No changes were necessary.' : 'Profile updated successfully.');

    // ---------------------------------------------------------------
    // Client (Bearer token): revoke own token (self-service logout)
    // ---------------------------------------------------------------
    case 'revoke_token':
        assertMethod($method, 'POST');
        if (!$security->checkRateLimit($ip, 'portal_revoke', 10, 60)) {
            portal_error('Too many requests. Please try again later.', 429);
        }

        $resolved = portal_resolve_token($db, portal_get_bearer_token());
        if ($resolved['status'] !== 'ok') {
            portal_error('Invalid or expired token.', 401, ['reason' => $resolved['status']]);
        }
        $token = $resolved['token'];
        $client = $resolved['client'];

        $stmt = $db->prepare("UPDATE client_access_tokens SET revoked = 1 WHERE id = :id");
        $stmt->execute([':id' => $token['id']]);

        portal_log($db, $client['id'], $token['id'], 'link_revoked', null);
        $security->auditLog($ip, 'PORTAL_LINK_REVOKED', $client['cds_account'], true);

        portal_json(null, 200, 'Access link revoked.');

    // ---------------------------------------------------------------
    // Staff: revoke all active links for a CDS account
    // ---------------------------------------------------------------
    case 'revoke_link':
        assertMethod($method, 'POST');
        portal_require_staff('trader');
        if (!$security->checkRateLimit($ip, 'portal_revoke', 10, 60)) {
            portal_error('Too many requests. Please try again later.', 429);
        }

        $input = portal_read_input();
        if (!isset($input['csrf_token']) || !$security->verifyCSRFToken($input['csrf_token'])) {
            portal_error('Invalid security token. Fetch a fresh one via ?action=csrf_token.', 403);
        }

        $cdsAccount = strtoupper(trim((string)($input['cds_account'] ?? '')));
        $client = portal_find_client_by_cds($db, $cdsAccount);
        if (!$client) {
            portal_error('Client not found with this CDS account.', 404);
        }

        $revoked = portal_revoke_active_tokens($db, $client['id']);
        portal_log($db, $client['id'], null, 'link_revoked', ['cds_account' => $client['cds_account']], (int)get_logged_in_user()['id']);
        $security->auditLog($ip, 'PORTAL_LINK_REVOKE_ADMIN', $client['cds_account'], true);

        portal_json(['revoked_tokens' => $revoked], 200, 'Active access links revoked.');

    default:
        portal_error('Invalid action.', 400, [
            'available_actions' => [
                'csrf_token',
                'mint_link',
                'get_profile',
                'update_profile',
                'revoke_token',
                'revoke_link'
            ]
        ]);
}

/**
 * Enforce the HTTP method for the current action.
 */
function assertMethod(string $actual, string $required): void {
    if ($actual !== $required) {
        portal_error("Method not allowed. Use $required.", 405);
    }
}