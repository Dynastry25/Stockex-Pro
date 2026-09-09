<?php
/**
 * StockEx Client Portal - Open Form Submission API
 *
 * Clients submit their details via a public form. No OTP or authentication.
 * Name matching (≥60%) gates the submission. Staff reviews submissions
 * before applying changes to the clients table.
 *
 * Actions:
 *   POST ?action=lookup_cds         (public) -> check name match, return client
 *   POST ?action=submit_details     (public) -> store submission in queue
 *   POST ?action=approve_submission (staff)  -> approve submission, copy to client
 *   POST ?action=reject_submission  (staff)  -> reject with notes
 *   POST ?action=list_submissions   (staff)  -> list pending/approved/rejected
 *   GET  ?action=csrf_token         (staff)  -> CSRF token for forms
 */

// Guarantee clean JSON: never let PHP notices/warnings pollute the response.
error_reporting(E_ERROR | E_PARSE);
ini_set('display_errors', '0');
ob_start();

require_once __DIR__ . '/helpers.php';

$allowedOrigin = (string)CLIENT_PORTAL_URL;
if (!empty($_SERVER['HTTP_ORIGIN'])) {
    $origin = rtrim(trim((string)$_SERVER['HTTP_ORIGIN']), '/');
    $known = array_filter(array_map('trim', explode(',', env('PORTAL_ALLOWED_ORIGINS', ''))));
    foreach ($known as $o) {
        if (rtrim($o, '/') === $origin) {
            $allowedOrigin = $origin;
            break;
        }
    }
}

header('Access-Control-Allow-Origin: ' . $allowedOrigin);
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Credentials: false');

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

try {
    switch ($action) {

        // ---------------------------------------------------------------
        // Staff: get CSRF token
        // ---------------------------------------------------------------
        case 'csrf_token':
            assertMethod($method, 'GET');
            portal_require_staff('trader');
            $token = $security->generateCSRFToken();
            portal_json(['csrf_token' => $token], 200, 'CSRF token generated.');

        // ---------------------------------------------------------------
        // Public: lookup CDS, check name match percentage
        // ---------------------------------------------------------------
        case 'lookup_cds':
            assertMethod($method, 'POST');
            if (!$security->checkRateLimit($ip, 'portal_lookup', 20, 60)) {
                portal_error('Too many requests. Please try again later.', 429);
            }
            // Daily IP cap
            if (!$security->checkRateLimit($ip, 'portal_lookup_daily', 200, 86400)) {
                portal_error('Daily request limit reached. Try again tomorrow.', 429);
            }

            $input = portal_read_input();
            $cdsAccount = strtoupper(trim((string)($input['cds_account'] ?? '')));
            if (!preg_match('/^[A-Z0-9]{5,20}$/', $cdsAccount)) {
                portal_error('Valid CDS account is required (5-20 alphanumeric characters).', 400);
            }

            // Per-CDS rate limiting (10 per hour)
            if (!$security->checkRateLimit('cds_' . $cdsAccount, 'portal_lookup_cds', 10, 3600)) {
                portal_error('This account has been looked up too many times. Please try again later.', 429);
            }

            $client = portal_find_client_by_cds($db, $cdsAccount);
            if (!$client) {
                portal_error('We could not find this CDS account. Check the number and try again.', 404);
            }
            if (!portal_client_is_active($client)) {
                portal_error('This account is inactive. Please contact the administrator.', 400);
            }

            // Check name match if name provided
            $submittedName = trim((string)($input['name'] ?? ''));
            $matchPct = 0;
            if ($submittedName !== '') {
                $matchPct = portal_name_match_pct($client['client_name'], $submittedName);
            }

            $minMatch = (int)env('PORTAL_NAME_MATCH_MIN', 60);
            $requiresName = $submittedName === '' || $matchPct < $minMatch;

            $response = [
                'cds_account'   => $client['cds_account'],
                'name_hint'     => portal_mask_name($client['client_name']),
                'match_pct'     => $matchPct,
                'requires_name' => $requiresName
            ];

            // Only expose full name + bank data when name matches
            if (!$requiresName) {
                $response['client_name'] = $client['client_name'];
                $response['bank_current'] = portal_read_contact($db, $client);
            }

            portal_json($response, 200, 'Account found.');

        // ---------------------------------------------------------------
        // Public: submit details (open form)
        // ---------------------------------------------------------------
        case 'submit_details':
            assertMethod($method, 'POST');
            if (!$security->checkRateLimit($ip, 'portal_submit', 10, 60)) {
                portal_error('Too many requests. Please try again later.', 429);
            }

            $input = portal_read_input();
            $cdsAccount = strtoupper(trim((string)($input['cds_account'] ?? '')));
            $submittedName = trim((string)($input['name'] ?? ''));

            if (!preg_match('/^[A-Z0-9]{5,20}$/', $cdsAccount)) {
                portal_error('Valid CDS account is required.', 400);
            }

            $client = portal_find_client_by_cds($db, $cdsAccount);
            if (!$client) {
                portal_error('Client not found.', 404);
            }
            if (!portal_client_is_active($client)) {
                portal_error('This account is inactive.', 400);
            }

            // Name match check
            if ($submittedName === '') {
                portal_error('Please enter at least two of your names for verification.', 400);
            }
            $matchPct = portal_name_match_pct($client['client_name'], $submittedName);
            $minMatch = (int)env('PORTAL_NAME_MATCH_MIN', 60);
            if ($matchPct < $minMatch) {
                portal_error(
                    'The name you entered does not match our records sufficiently. ' .
                    "Match: {$matchPct}%. Minimum required: {$minMatch}%.",
                    403
                );
            }

            // Validate contact details.
            $phoneRaw = trim((string)($input['phone'] ?? ''));
            $phone = $phoneRaw !== '' ? portal_normalize_phone($phoneRaw) : null;
            if ($phoneRaw !== '' && !$phone) {
                portal_error('Please enter a valid Tanzanian phone number.', 400);
            }

            $email = trim((string)($input['email'] ?? ''));
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                portal_error('Please enter a valid email address.', 400);
            }
            $email = $email !== '' ? $email : null;

            $address = trim((string)($input['address'] ?? ''));
            if (portal_text_length($address) > 500) {
                portal_error('Address must be 500 characters or less.', 400);
            }
            $address = $address !== '' ? $address : null;

            // New clients explicitly send payment_methods. Legacy portal builds can
            // still submit flat bank fields during a rolling deployment.
            $paymentMethodsExplicit = array_key_exists('payment_methods', $input);
            if ($paymentMethodsExplicit) {
                $paymentValidation = portal_validate_payment_methods($input['payment_methods'], true);
                if (!empty($paymentValidation['errors'])) {
                    portal_error('Please complete the selected payment details.', 400, $paymentValidation['errors']);
                }
                $paymentMethods = $paymentValidation['clean'];
            } else {
                $paymentMethods = portal_payment_methods_from_legacy($input);
            }

            $bank = $paymentMethods['bank'] ?? [];
            $bankName = $bank['bank_name'] ?? (isset($input['bank_name']) ? trim((string)$input['bank_name']) : null);
            $bankAccount = $bank['account_number'] ?? (isset($input['bank_account_number']) ? trim((string)$input['bank_account_number']) : null);
            $bankBranch = $bank['branch'] ?? (isset($input['bank_branch']) ? trim((string)$input['bank_branch']) : null);
            $currency = $bank['currency'] ?? (isset($input['currency']) ? strtoupper(trim((string)$input['currency'])) : 'TZS');
            if (!in_array($currency, PORTAL_VALID_CURRENCIES, true)) $currency = 'TZS';

            $queueColumns = portal_table_columns($db, 'client_submission_queue');
            if ($paymentMethodsExplicit && (!in_array('address', $queueColumns, true) || !in_array('payment_methods', $queueColumns, true))) {
                portal_error('The client portal is being upgraded. Please try again shortly.', 503);
            }

            // Check for existing pending submission for this CDS.
            $stmt = $db->prepare(
                "SELECT id FROM client_submission_queue WHERE cds_account = :cds AND status = 'pending' LIMIT 1"
            );
            $stmt->execute([':cds' => $cdsAccount]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($existing) {
                portal_error(
                    'You already have a pending submission (ID #' . $existing['id'] . '). ' .
                    'Wait for it to be reviewed, or contact support.',
                    409
                );
            }

            // Insert into submission queue. New fields are added dynamically so
            // old portal builds remain usable during deployment ordering.
            try {
                $columns = [
                    'client_id', 'cds_account', 'submitted_name', 'match_pct', 'phone', 'email',
                    'bank_name', 'bank_account_number', 'bank_branch', 'currency', 'client_ip'
                ];
                $placeholders = [
                    ':cid', ':cds', ':name', ':pct', ':phone', ':email',
                    ':bn', ':ba', ':bb', ':curr', ':ip'
                ];
                $params = [
                    ':cid' => $client['id'],
                    ':cds' => $cdsAccount,
                    ':name' => $submittedName,
                    ':pct' => $matchPct,
                    ':phone' => $phone,
                    ':email' => $email,
                    ':bn' => $bankName,
                    ':ba' => $bankAccount,
                    ':bb' => $bankBranch,
                    ':curr' => $currency,
                    ':ip' => $ip
                ];

                if (in_array('address', $queueColumns, true)) {
                    $columns[] = 'address';
                    $placeholders[] = ':address';
                    $params[':address'] = $address;
                }
                if (in_array('payment_methods', $queueColumns, true)) {
                    $columns[] = 'payment_methods';
                    $placeholders[] = ':payment_methods';
                    $params[':payment_methods'] = !empty($paymentMethods)
                        ? json_encode($paymentMethods, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                        : null;
                }

                $stmt = $db->prepare(
                    'INSERT INTO client_submission_queue (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $placeholders) . ')'
                );
                $stmt->execute($params);

                portal_log($db, $client['id'], null, 'form_submission', [
                    'match_pct' => $matchPct,
                    'phone' => $phone,
                    'email' => $email,
                    'address' => $address,
                    'payment_method_types' => array_keys($paymentMethods),
                    'bank_name' => $bankName,
                    'bank_account' => $bankAccount,
                    'bank_branch' => $bankBranch
                ], null);

                $security->auditLog($ip, 'PORTAL_SUBMISSION', $cdsAccount, true, [
                    'match_pct' => $matchPct,
                    'submitted' => true,
                    'payment_method_types' => array_keys($paymentMethods)
                ]);

                portal_json([
                    'client_id' => (int)$client['id'],
                    'cds_account' => $cdsAccount,
                    'match_pct' => $matchPct,
                    'submission_id' => (int)$db->lastInsertId()
                ], 201, 'Submission received. We will review your details shortly.');
            } catch (Exception $e) {
                error_log('Submission queue error: ' . $e->getMessage());
                portal_error('Could not save your submission. Please try again.', 500);
            }

        // ---------------------------------------------------------------
        // Staff: approve submission, copy to client record
        // ---------------------------------------------------------------
        case 'approve_submission':
            assertMethod($method, 'POST');
            portal_require_staff('trader');
            if (!$security->checkRateLimit($ip, 'portal_approve', 20, 60)) {
                portal_error('Too many requests. Please try again later.', 429);
            }

            $input = portal_read_input();
            $submissionId = (int)($input['submission_id'] ?? 0);

            if ($submissionId <= 0) {
                portal_error('Invalid submission ID.', 400);
            }

            $stmt = $db->prepare(
                "SELECT s.*, c.client_name FROM client_submission_queue s
                 JOIN clients c ON s.client_id = c.id WHERE s.id = :id"
            );
            $stmt->execute([':id' => $submissionId]);
            $submission = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$submission) {
                portal_error('Submission not found.', 404);
            }
            if ($submission['status'] !== 'pending') {
                portal_error('This submission has already been reviewed.', 400);
            }

            $staff = get_logged_in_user();

            try {
                $clientColumns = portal_table_columns($db, 'clients');
                $paymentMethodsRaw = $submission['payment_methods'] ?? null;
                $hasAuthoritativePayments = is_string($paymentMethodsRaw) && trim($paymentMethodsRaw) !== '';
                $paymentMethods = $hasAuthoritativePayments ? portal_decode_payment_methods($paymentMethodsRaw) : [];
                $bank = $paymentMethods['bank'] ?? null;

                if ($hasAuthoritativePayments && !in_array('payment_methods', $clientColumns, true)) {
                    portal_error('Database migration for payment details is not complete.', 503);
                }

                $set = [];
                $cParams = [];

                // Contact details submitted through the portal are applied after
                // staff approval. Keep encrypted mirror columns in sync when the
                // production schema contains them.
                $encryptedContact = $security->encryptSensitiveData([
                    'phone' => $submission['phone'] ?? null,
                    'email' => $submission['email'] ?? null,
                    'bank_account' => $bank['account_number'] ?? ($submission['bank_account_number'] ?? null)
                ]);

                if (!empty($submission['phone']) && in_array('phone', $clientColumns, true)) {
                    $set[] = 'phone = :phone';
                    $cParams[':phone'] = $submission['phone'];
                    if (in_array('phone_encrypted', $clientColumns, true)) {
                        $set[] = 'phone_encrypted = :phone_encrypted';
                        $cParams[':phone_encrypted'] = $encryptedContact['phone'];
                    }
                }

                if (!empty($submission['email']) && in_array('email', $clientColumns, true)) {
                    $set[] = 'email = :email';
                    $cParams[':email'] = $submission['email'];
                    if (in_array('email_encrypted', $clientColumns, true)) {
                        $set[] = 'email_encrypted = :email_encrypted';
                        $cParams[':email_encrypted'] = $encryptedContact['email'];
                    }
                }

                if (!empty($submission['address']) && in_array('address', $clientColumns, true)) {
                    $set[] = 'address = :address';
                    $cParams[':address'] = $submission['address'];
                }

                if ($hasAuthoritativePayments && in_array('payment_methods', $clientColumns, true)) {
                    $set[] = 'payment_methods = :payment_methods';
                    $cParams[':payment_methods'] = json_encode($paymentMethods, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                }

                if ($hasAuthoritativePayments) {
                    // The new payment selection is authoritative. Mirror the bank
                    // method into legacy columns used by existing reports/notes;
                    // clear those columns when bank is not selected.
                    if ($bank) {
                        if (in_array('bank_name', $clientColumns, true)) {
                            $set[] = 'bank_name = :bank_name';
                            $cParams[':bank_name'] = $bank['bank_name'] ?? null;
                        }
                        if (in_array('bank_account_number', $clientColumns, true)) {
                            $set[] = 'bank_account_number = :bank_account_number';
                            $cParams[':bank_account_number'] = $bank['account_number'] ?? null;
                        }
                        if (in_array('bank_account_encrypted', $clientColumns, true)) {
                            $set[] = 'bank_account_encrypted = :bank_account_encrypted';
                            $cParams[':bank_account_encrypted'] = $encryptedContact['bank_account'];
                        }
                        if (in_array('bank_branch', $clientColumns, true)) {
                            $set[] = 'bank_branch = :bank_branch';
                            $cParams[':bank_branch'] = $bank['branch'] ?? null;
                        }
                        if (in_array('currency', $clientColumns, true)) {
                            $set[] = 'currency = :currency';
                            $cParams[':currency'] = $bank['currency'] ?? 'TZS';
                        }
                    } else {
                        foreach (['bank_name', 'bank_account_number', 'bank_branch'] as $column) {
                            if (in_array($column, $clientColumns, true)) $set[] = $column . ' = NULL';
                        }
                        if (in_array('bank_account_encrypted', $clientColumns, true)) $set[] = 'bank_account_encrypted = NULL';
                    }
                } else {
                    // Legacy submission: preserve the old behavior and copy only
                    // bank values that were actually supplied.
                    if (!empty($submission['bank_name']) && in_array('bank_name', $clientColumns, true)) {
                        $set[] = 'bank_name = :legacy_bank_name';
                        $cParams[':legacy_bank_name'] = $submission['bank_name'];
                    }
                    if (!empty($submission['bank_account_number']) && in_array('bank_account_number', $clientColumns, true)) {
                        $set[] = 'bank_account_number = :legacy_bank_account';
                        $cParams[':legacy_bank_account'] = $submission['bank_account_number'];
                        if (in_array('bank_account_encrypted', $clientColumns, true)) {
                            $set[] = 'bank_account_encrypted = :legacy_bank_account_encrypted';
                            $cParams[':legacy_bank_account_encrypted'] = $encryptedContact['bank_account'];
                        }
                    }
                    if (!empty($submission['bank_branch']) && in_array('bank_branch', $clientColumns, true)) {
                        $set[] = 'bank_branch = :legacy_bank_branch';
                        $cParams[':legacy_bank_branch'] = $submission['bank_branch'];
                    }
                    if (!empty($submission['currency']) && in_array('currency', $clientColumns, true)) {
                        $set[] = 'currency = :legacy_currency';
                        $cParams[':legacy_currency'] = $submission['currency'];
                    }
                }

                $db->beginTransaction();

                if (!empty($set)) {
                    $cParams[':cid'] = $submission['client_id'];
                    $db->prepare('UPDATE clients SET ' . implode(', ', $set) . ' WHERE id = :cid')
                        ->execute($cParams);
                }

                $db->prepare(
                    "UPDATE client_submission_queue SET status = 'approved', reviewed_by = :by,
                     reviewed_at = NOW(), client_ip = :ip WHERE id = :sid"
                )->execute([
                    ':sid' => $submissionId,
                    ':by' => (int)$staff['id'],
                    ':ip' => $ip
                ]);

                $db->commit();

                portal_log($db, $submission['client_id'], null, 'form_submission_approved', [
                    'submission_id' => $submissionId,
                    'staff_id' => $staff['id'],
                    'staff_name' => $staff['full_name'] ?? $staff['username'],
                    'payment_method_types' => array_keys($paymentMethods)
                ], $staff['id']);

                portal_json([
                    'submission_id' => $submissionId,
                    'status' => 'approved',
                    'client_id' => (int)$submission['client_id']
                ], 200, 'Submission approved and client record updated.');

            } catch (Exception $e) {
                if ($db->inTransaction()) $db->rollBack();
                error_log('Approve submission error: ' . $e->getMessage());
                portal_error('Could not approve submission.', 500);
            }

        // ---------------------------------------------------------------
        // Staff: reject submission
        // ---------------------------------------------------------------
        case 'reject_submission':
            assertMethod($method, 'POST');
            portal_require_staff('trader');
            if (!$security->checkRateLimit($ip, 'portal_reject', 20, 60)) {
                portal_error('Too many requests. Please try again later.', 429);
            }

            $input = portal_read_input();
            $submissionId = (int)($input['submission_id'] ?? 0);
            $reason = trim((string)($input['reason'] ?? ''));

            if ($submissionId <= 0) {
                portal_error('Invalid submission ID.', 400);
            }
            if ($reason === '') {
                portal_error('Please provide a rejection reason.', 400);
            }

            $stmt = $db->prepare("SELECT * FROM client_submission_queue WHERE id = :id");
            $stmt->execute([':id' => $submissionId]);
            $submission = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$submission) {
                portal_error('Submission not found.', 404);
            }
            if ($submission['status'] !== 'pending') {
                portal_error('This submission has already been reviewed.', 400);
            }

            $staff = get_logged_in_user();

            try {
                $db->prepare(
                    "UPDATE client_submission_queue SET status = 'rejected', review_notes = :notes,
                     reviewed_by = :by, reviewed_at = NOW(), client_ip = :ip WHERE id = :sid"
                )->execute([
                    ':sid' => $submissionId,
                    ':notes' => $reason,
                    ':by' => (int)$staff['id'],
                    ':ip' => $ip
                ]);

                portal_log($db, $submission['client_id'], null, 'form_submission_rejected', [
                    'submission_id' => $submissionId, 'reason' => $reason
                ], $staff['id']);

                portal_json(['submission_id' => $submissionId, 'status' => 'rejected'], 200, 'Submission rejected.');

            } catch (Exception $e) {
                error_log('Reject submission error: ' . $e->getMessage());
                portal_error('Could not reject submission.', 500);
            }

        // ---------------------------------------------------------------
        // Staff: list submissions
        // ---------------------------------------------------------------
        case 'list_submissions':
            assertMethod($method, 'POST');
            portal_require_staff('trader');
            if (!$security->checkRateLimit($ip, 'portal_list', 30, 60)) {
                portal_error('Too many requests. Please try again later.', 429);
            }

            $input = portal_read_input();
            $status = trim((string)($input['status'] ?? 'pending'));
            $limit = min(100, max(10, (int)($input['limit'] ?? 50)));
            $offset = max(0, (int)($input['offset'] ?? 0));

            if (!in_array($status, ['pending', 'approved', 'rejected', 'all'], true)) {
                $status = 'pending';
            }

            $where = $status === 'all' ? '1=1' : 's.status = :status';
            $params = $status === 'all' ? [] : [':status' => $status];

            // LIMIT/OFFSET are integers (already cast + clamped); interpolate them
            // directly because PDO binds placeholders as strings, which MariaDB
            // rejects in LIMIT clauses.
            $stmt = $db->prepare(
                "SELECT s.*, c.client_name, c.cds_account, u.full_name as reviewer_name
                 FROM client_submission_queue s
                 JOIN clients c ON s.client_id = c.id
                 LEFT JOIN users u ON s.reviewed_by = u.id
                 WHERE $where
                 ORDER BY s.created_at DESC
                 LIMIT $limit OFFSET $offset"
            );
            $stmt->execute($params);
            $submissions = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $totalStmt = $db->prepare(
                "SELECT COUNT(*) FROM client_submission_queue s WHERE $where"
            );
            if ($status !== 'all') $totalStmt->execute([':status' => $status]);
            else $totalStmt->execute();
            $total = (int)$totalStmt->fetchColumn();
            portal_json([
                'submissions' => $submissions,
                'total' => $total,
                'limit' => $limit,
                'offset' => $offset
            ], 200, 'Submissions retrieved.');

        // ---------------------------------------------------------------
        // Staff: get submission details
        // ---------------------------------------------------------------
        case 'get_submission':
            assertMethod($method, 'POST');
            portal_require_staff('trader');

            $input = portal_read_input();
            $submissionId = (int)($input['submission_id'] ?? 0);

            if ($submissionId <= 0) {
                portal_error('Invalid submission ID.', 400);
            }

            $stmt = $db->prepare(
                "SELECT s.*, c.client_name, c.cds_account, u.full_name as reviewer_name
                 FROM client_submission_queue s
                 JOIN clients c ON s.client_id = c.id
                 LEFT JOIN users u ON s.reviewed_by = u.id
                 WHERE s.id = :id"
            );
            $stmt->execute([':id' => $submissionId]);
            $submission = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$submission) {
                portal_error('Submission not found.', 404);
            }

            portal_json(['submission' => $submission], 200, 'Submission details.');

        // ---------------------------------------------------------------
        // Staff: mint a secure link (legacy, for staff-initiated)
        // ---------------------------------------------------------------
        case 'mint_link':
            assertMethod($method, 'POST');
            portal_require_staff('trader');

            $input = portal_read_input();
            $cdsAccount = strtoupper(trim((string)($input['cds_account'] ?? '')));
            $ttlHours = min(72, max(1, (int)($input['ttl_hours'] ?? 24)));
            $maxUses = min(20, max(1, (int)($input['max_uses'] ?? 10)));

            if (!preg_match('/^[A-Z0-9]{5,20}$/', $cdsAccount)) {
                portal_error('Valid CDS account is required.', 400);
            }

            $client = portal_find_client_by_cds($db, $cdsAccount);
            if (!$client) {
                portal_error('Client not found.', 404);
            }

            $staff = get_logged_in_user();
            $security = new SecurityManager();
            $raw = $security->generateSecureToken(32);

            try {
                $stmt = $db->prepare(
                    "INSERT INTO client_access_tokens
                        (client_id, token_hash, expires_at, max_uses, created_by, ip_address, last_ip, source)
                     VALUES (:cid, :hash, DATE_ADD(NOW(), INTERVAL :hrs HOUR), :uses, :by, :ip, :ip, 'staff')"
                );
                $stmt->execute([
                    ':cid' => $client['id'],
                    ':hash' => hash('sha256', $raw),
                    ':hrs' => $ttlHours,
                    ':uses' => $maxUses,
                    ':by' => (int)$staff['id'],
                    ':ip' => $ip
                ]);

                portal_log($db, $client['id'], null, 'link_minted', [
                    'staff_id' => $staff['id'], 'staff_name' => $staff['full_name'] ?? $staff['username']
                ], $staff['id']);

                $link = CLIENT_PORTAL_URL . '?t=' . $raw;

                portal_json([
                    'token' => $raw,
                    'link' => $link,
                    'expires_at' => date('Y-m-d H:i:s', time() + $ttlHours * 3600),
                    'max_uses' => $maxUses
                ], 201, 'Secure link generated. Send this to the client:');

            } catch (Exception $e) {
                error_log('Mint link error: ' . $e->getMessage());
                portal_error('Could not generate link.', 500);
            }

        // ---------------------------------------------------------------
        // Staff: revoke links for a CDS
        // ---------------------------------------------------------------
        case 'revoke_link':
            assertMethod($method, 'POST');
            portal_require_staff('trader');

            $input = portal_read_input();
            $cdsAccount = strtoupper(trim((string)($input['cds_account'] ?? '')));

            if (!preg_match('/^[A-Z0-9]{5,20}$/', $cdsAccount)) {
                portal_error('Valid CDS account is required.', 400);
            }

            $client = portal_find_client_by_cds($db, $cdsAccount);
            if (!$client) {
                portal_error('Client not found.', 404);
            }

            $staff = get_logged_in_user();
            $stmt = $db->prepare("UPDATE client_access_tokens SET revoked = 1 WHERE client_id = :cid");
            $stmt->execute([':cid' => $client['id']]);

            portal_log($db, $client['id'], null, 'link_revoked', [
                'staff_id' => $staff['id'], 'staff_name' => $staff['full_name'] ?? $staff['username']
            ], $staff['id']);

            portal_json(['revoked' => $stmt->rowCount()], 200, 'Active access links revoked.');

        default:
            portal_error('Invalid action.', 400, [
                'available_actions' => [
                    'csrf_token',
                    'mint_link',
                    'revoke_link',
                    'lookup_cds',
                    'submit_details',
                    'approve_submission',
                    'reject_submission',
                    'list_submissions',
                    'get_submission'
                ]
            ]);
    }
} catch (Throwable $e) {
    error_log('Client portal fatal [' . date('Y-m-d H:i:s') . '] ' . get_class($e) . ': ' . $e->getMessage()
        . ' @ ' . $e->getFile() . ':' . $e->getLine());
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'status_code' => 500,
        'error' => 'An unexpected error occurred. Please try again.',
        'timestamp' => date('Y-m-d H:i:s')
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit();
}

/**
 * Enforce the HTTP method for the current action.
 */
function assertMethod(string $actual, string $required): void {
    if ($actual !== $required) {
        portal_error("Method not allowed. Use $required.", 405);
    }
}
