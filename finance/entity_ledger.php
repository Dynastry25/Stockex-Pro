<?php
require_once '../config/config.php';
require_once '../auth/auth_middleware.php';
require_once '../tcpdf/tcpdf.php';

// Security headers
header("X-Frame-Options: DENY");
header("X-Content-Type-Options: nosniff");
header("X-XSS-Protection: 1; mode=block");
header("Referrer-Policy: strict-origin-when-cross-origin");
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://fonts.googleapis.com; font-src 'self' https://cdn.jsdelivr.net https://fonts.gstatic.com");

require_finance_officer();

// CSRF Protection
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$db = getDBConnection();
$error_message = '';

// Get entity parameters
$entity_type = strtolower(trim((string)($_GET['type'] ?? '')));
$entity_id = trim((string)($_GET['id'] ?? ''));
$allowed_entity_types = ['client', 'custodian', 'employee', 'agent', 'broker', 'supplier', 'chart_account', 'bank_account'];

if ($entity_id === '' || !in_array($entity_type, $allowed_entity_types, true)) {
    header('Location: ' . BASE_URL . 'finance/debtors?error=' . rawurlencode('Invalid entity parameters'));
    exit;
}

// Helper functions
function validateDate($date, $format = 'Y-m-d') {
    $d = DateTime::createFromFormat($format, $date);
    return $d && $d->format($format) === $date;
}

function sanitizeInput($data) {
    if (is_array($data)) {
        return array_map('sanitizeInput', $data);
    }
    return htmlspecialchars(trim($data), ENT_QUOTES, 'UTF-8');
}

function formatCurrency($amount, $currency = 'Tsh') {
    $symbols = [
        'Tsh' => 'TZS ',
        'USD' => '$',
        'Ksh' => 'KSh ',
        'UGsh' => 'UGX '
    ];
    $symbol = $symbols[$currency] ?? 'TZS ';
    return $symbol . number_format($amount, 2);
}

function getLedgerCode($entity_type) {
    $mapping = [
        'client' => 'C',
        'custodian' => 'D',
        'employee' => 'E',
        'agent' => 'A',
        'broker' => 'B',
        'supplier' => 'S',
        'chart_account' => 'O',
        'bank_account' => 'B'
    ];
    return $mapping[$entity_type] ?? 'O';
}

// Entity metadata is deliberately defined in PHP and the row is loaded with SELECT *.
// This avoids the ledger page failing when an environment has an older/newer schema where
// optional columns (for example status/created_at) differ from the debtors page schema.
function getEntityDefinition($entity_type) {
    $definitions = [
        'client' => [
            'table' => 'clients', 'id_column' => 'id', 'name_column' => 'client_name', 'code_column' => 'cds_account',
            'type_label' => 'Client', 'code_label' => 'CDS Account', 'icon' => 'bi-person-badge', 'color' => '#4F46E5'
        ],
        'custodian' => [
            'table' => 'custodians', 'id_column' => 'id', 'name_column' => 'custodian_name', 'code_column' => 'custodian_code',
            'type_label' => 'Custodian', 'code_label' => 'Custodian Code', 'icon' => 'bi-shield-check', 'color' => '#0891B2'
        ],
        'employee' => [
            'table' => 'users', 'id_column' => 'id', 'name_column' => 'full_name', 'code_column' => 'username',
            'type_label' => 'Employee', 'code_label' => 'Username', 'icon' => 'bi-person-workspace', 'color' => '#059669'
        ],
        'agent' => [
            'table' => 'agents', 'id_column' => 'id', 'name_column' => 'name', 'code_column' => 'agent_code',
            'type_label' => 'Agent', 'code_label' => 'Agent Code', 'icon' => 'bi-person-rolodex', 'color' => '#D97706'
        ],
        'broker' => [
            'table' => 'brokers', 'id_column' => 'id', 'name_column' => 'broker_name', 'code_column' => 'broker_code',
            'type_label' => 'Broker', 'code_label' => 'Broker Code', 'icon' => 'bi-graph-up', 'color' => '#DC2626'
        ],
        'supplier' => [
            'table' => 'suppliers', 'id_column' => 'id', 'name_column' => 'name', 'code_column' => 'supplier_code',
            'type_label' => 'Supplier', 'code_label' => 'Supplier Code', 'icon' => 'bi-truck', 'color' => '#7C3AED'
        ],
        'chart_account' => [
            'table' => 'chart_of_accounts', 'id_column' => 'account_code', 'name_column' => 'account_name', 'code_column' => 'account_code',
            'type_label' => 'Chart Account', 'code_label' => 'Account Code', 'icon' => 'bi-journal-bookmark', 'color' => '#6D28D9'
        ],
        'bank_account' => [
            'table' => 'banks_accounts', 'id_column' => 'id', 'name_column' => 'account_name', 'code_column' => 'account_number',
            'type_label' => 'Bank Account', 'code_label' => 'Account Number', 'icon' => 'bi-bank', 'color' => '#0D9488'
        ],
    ];

    return $definitions[$entity_type] ?? null;
}

function getEntityDetails($db, $entity_type, $entity_id) {
    $definition = getEntityDefinition($entity_type);
    if (!$definition || $entity_id === null || trim((string)$entity_id) === '') {
        return null;
    }

    try {
        // Table/column identifiers come only from the hard-coded whitelist above.
        $sql = "SELECT * FROM {$definition['table']} WHERE {$definition['id_column']} = ? LIMIT 1";
        $stmt = $db->prepare($sql);
        $stmt->execute([$entity_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            error_log("Entity ledger: entity not found type={$entity_type}, id={$entity_id}");
            return null;
        }

        $row['id'] = $row[$definition['id_column']] ?? $entity_id;
        $row['name'] = $row[$definition['name_column']] ?? '';
        $row['code'] = $row[$definition['code_column']] ?? $row['id'];
        $row['type_label'] = $definition['type_label'];
        $row['code_label'] = $definition['code_label'];
        $row['icon'] = $definition['icon'];
        $row['color'] = $definition['color'];

        // Keep the UI stable across schema versions where these columns may be absent.
        if (!array_key_exists('is_active', $row)) {
            $row['is_active'] = 1;
        }
        if (!array_key_exists('status', $row) || trim((string)$row['status']) === '') {
            $row['status'] = ((string)$row['is_active'] === '1') ? 'active' : 'inactive';
        }
        if (!array_key_exists('created_at', $row)) {
            $row['created_at'] = null;
        }

        return $row;
    } catch (Throwable $e) {
        error_log("Entity ledger: failed to load entity type={$entity_type}, id={$entity_id}: " . $e->getMessage());
        return null;
    }
}

// Get all transactions for an entity.
// IMPORTANT: the source-selection rules below intentionally mirror finance/debtors.php.
// The list view and this detail view must be two representations of the same ledger data;
// do not add status/source filters here unless the same rule is also applied in debtors.php.
function getEntityTransactions($db, $entity_type, $entity_id, $start_date = null, $end_date = null, $search = '', $entity = null, $as_of_date = null) {
    $transactions = [];
    $entity = $entity ?: getEntityDetails($db, $entity_type, $entity_id);

    if (!$entity) {
        return ['transactions' => [], 'debit_total' => 0, 'credit_total' => 0, 'balance' => 0];
    }

    $ledger_code = getLedgerCode($entity_type);
    $entity_name = trim((string)($entity['name'] ?? ''));
    $search = trim((string)$search);

    $addDateScope = function (&$sql, &$params, $column, $useAsOf = true) use ($start_date, $end_date, $as_of_date) {
        if ($start_date && $end_date) {
            $sql .= " AND {$column} BETWEEN ? AND ?";
            $params[] = $start_date;
            $params[] = $end_date;
        } elseif ($useAsOf && $as_of_date) {
            $sql .= " AND {$column} <= ?";
            $params[] = $as_of_date;
        }
    };

    $addSearch = function (&$sql, &$params, array $columns) use ($search) {
        if ($search === '') {
            return;
        }
        $needle = '%' . $search . '%';
        $parts = [];
        foreach ($columns as $column) {
            $parts[] = "{$column} LIKE ?";
            $params[] = $needle;
        }
        $sql .= ' AND (' . implode(' OR ', $parts) . ')';
    };

    $pushReceipt = function (array $row) use (&$transactions) {
        $amount = (float)($row['amount'] ?? 0);
        $row['amount'] = $amount;
        // Same accounting direction as debtors.php: receipts reduce what we owe.
        $row['debit'] = $amount;
        $row['credit'] = 0.0;
        $row['account_name'] = $row['account_name'] ?? ($row['account'] ?: 'Receipt');
        $transactions[] = $row;
    };

    $pushPayment = function (array $row) use (&$transactions) {
        $amount = (float)($row['amount'] ?? 0);
        $row['amount'] = $amount;
        // Same accounting direction as debtors.php: payments increase the credit balance.
        $row['debit'] = 0.0;
        $row['credit'] = $amount;
        $row['account_name'] = $row['account_name'] ?? ($row['account'] ?: 'Payment');
        $transactions[] = $row;
    };

    $pushGl = function (array $row) use (&$transactions) {
        $row['debit'] = (float)($row['debit_amount'] ?? 0);
        $row['credit'] = (float)($row['credit_amount'] ?? 0);
        $row['amount'] = max($row['debit'], $row['credit']);
        $transactions[] = $row;
    };

    $pushCustodianTrade = function (array $row) use (&$transactions) {
        $amount = (float)($row['amount'] ?? 0);
        $side = strtolower((string)($row['trade_side'] ?? ''));
        if ($amount <= 0 || ($side !== 'buy' && $side !== 'sell')) {
            return;
        }

        // Keep this identical to finance/debtors.php:
        // BUY = amount payable to custodian (credit); SELL = amount receivable (debit).
        $row['amount'] = $amount;
        $row['debit'] = $side === 'sell' ? $amount : 0.0;
        $row['credit'] = $side === 'buy' ? $amount : 0.0;
        $transactions[] = $row;
    };

    // ------------------------------------------------------------------
    // Receipts / payments
    // ------------------------------------------------------------------
    if ($entity_type === 'bank_account') {
        // Bank accounts are identified by the physical account number. This is safer
        // than the generic entity code because multiple banks can share GL controls.
        $account_number = trim((string)($entity['code'] ?? ''));
        if ($account_number !== '') {
            try {
                $sql = "SELECT r.receipt_date AS transaction_date,
                               'receipt' AS transaction_type,
                               r.receipt_no AS reference,
                               NULL AS related_reference,
                               r.narration AS description,
                               r.amount,
                               r.currency,
                               COALESCE(NULLIF(r.account_no, ''), r.bank_account_number) AS account,
                               COALESCE(NULLIF(r.account_no, ''), r.bank_account_number) AS account_name,
                               'receipts' AS source_table,
                               r.id AS source_id,
                               r.created_at,
                               r.created_by,
                               'Receipt' AS source_label
                        FROM receipts r
                        WHERE r.record_in_financial = 'yes'
                          AND (r.account_no = ? OR r.bank_account_number = ?)";
                $params = [$account_number, $account_number];
                $addDateScope($sql, $params, 'r.receipt_date');
                $addSearch($sql, $params, ['r.receipt_no', 'r.narration', 'r.name']);
                $stmt = $db->prepare($sql);
                $stmt->execute($params);
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $pushReceipt($row);
                }
            } catch (Throwable $e) {
                error_log("Entity ledger bank receipts id={$entity_id}: " . $e->getMessage());
            }

            try {
                $sql = "SELECT p.payment_date AS transaction_date,
                               'payment' AS transaction_type,
                               p.payment_no AS reference,
                               p.trade_reference AS related_reference,
                               p.narration AS description,
                               p.amount,
                               p.currency,
                               COALESCE(NULLIF(p.account_no, ''), p.bank_account_number) AS account,
                               COALESCE(NULLIF(p.account_no, ''), p.bank_account_number) AS account_name,
                               'payments' AS source_table,
                               p.id AS source_id,
                               p.created_at,
                               p.created_by,
                               'Payment' AS source_label
                        FROM payments p
                        WHERE p.record_in_financial = 'yes'
                          AND (p.account_no = ? OR p.bank_account_number = ?)";
                $params = [$account_number, $account_number];
                $addDateScope($sql, $params, 'p.payment_date');
                $addSearch($sql, $params, ['p.payment_no', 'p.narration', 'p.name', 'p.trade_reference']);
                $stmt = $db->prepare($sql);
                $stmt->execute($params);
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $pushPayment($row);
                }
            } catch (Throwable $e) {
                error_log("Entity ledger bank payments id={$entity_id}: " . $e->getMessage());
            }
        }
    } elseif ($entity_type !== 'chart_account') {
        // These two queries are deliberately the same source rules used by debtors.php.
        // In particular, no extra status condition is added here: adding one caused the
        // list to show a balance while the detail page showed zero transactions.
        try {
            $sql = "SELECT r.receipt_date AS transaction_date,
                           'receipt' AS transaction_type,
                           r.receipt_no AS reference,
                           NULL AS related_reference,
                           r.narration AS description,
                           r.amount,
                           r.currency,
                           r.account_no AS account,
                           r.account_no AS account_name,
                           'receipts' AS source_table,
                           r.id AS source_id,
                           r.created_at,
                           r.created_by,
                           'Receipt' AS source_label
                    FROM receipts r
                    WHERE (r.account_of = ? OR (r.account_of = 'O' AND r.source_type = ?))
                      AND r.name_id = ?
                      AND r.record_in_financial = 'yes'";
            $params = [$ledger_code, $entity_type, $entity_id];
            $addDateScope($sql, $params, 'r.receipt_date');
            $addSearch($sql, $params, ['r.receipt_no', 'r.narration', 'r.name']);
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $pushReceipt($row);
            }
        } catch (Throwable $e) {
            error_log("Entity ledger receipts type={$entity_type}, id={$entity_id}: " . $e->getMessage());
        }

        try {
            $sql = "SELECT p.payment_date AS transaction_date,
                           'payment' AS transaction_type,
                           p.payment_no AS reference,
                           p.trade_reference AS related_reference,
                           p.narration AS description,
                           p.amount,
                           p.currency,
                           p.account_no AS account,
                           p.account_no AS account_name,
                           'payments' AS source_table,
                           p.id AS source_id,
                           p.created_at,
                           p.created_by,
                           'Payment' AS source_label
                    FROM payments p
                    WHERE (p.paid_to = ? OR (p.paid_to = 'O' AND p.source_type = ?))
                      AND p.name_id = ?
                      AND p.record_in_financial = 'yes'";
            $params = [$ledger_code, $entity_type, $entity_id];
            $addDateScope($sql, $params, 'p.payment_date');
            $addSearch($sql, $params, ['p.payment_no', 'p.narration', 'p.name', 'p.trade_reference']);
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $pushPayment($row);
            }
        } catch (Throwable $e) {
            error_log("Entity ledger payments type={$entity_type}, id={$entity_id}: " . $e->getMessage());
        }
    }

    // ------------------------------------------------------------------
    // Custodian settlement flows from the canonical trades table.
    // Historical production uploads can have trades.sca_code populated while the
    // derived custodians_trades table is empty, so the detailed ledger must use the
    // same canonical source as finance/debtors.php.
    // ------------------------------------------------------------------
    if ($entity_type === 'custodian') {
        $custodian_code = trim((string)($entity['code'] ?? ''));
        if ($custodian_code !== '') {
            try {
                $sql = "SELECT t.trade_date AS transaction_date,
                               'custodian_trade' AS transaction_type,
                               t.trade_reference AS reference,
                               t.exchange_reference AS related_reference,
                               t.trade_side,
                               t.consideration AS amount,
                               COALESCE(NULLIF(t.currency, ''), 'TZS') AS currency,
                               t.security_id AS account,
                               COALESCE(NULLIF(t.security_name, ''), t.security_id) AS account_name,
                               'trades' AS source_table,
                               t.id AS source_id,
                               t.created_at,
                               t.uploaded_by AS created_by,
                               'Custodian Trade' AS source_label,
                               t.security_name,
                               t.security_id,
                               t.client_name,
                               t.client_cds_account,
                               t.settlement_date
                        FROM trades t
                        WHERE TRIM(t.sca_code) = ?
                          AND COALESCE(t.consideration, 0) <> 0
                          AND (t.status IS NULL OR t.status <> 'cancelled')";
                $params = [$custodian_code];
                $addDateScope($sql, $params, 't.trade_date');
                $addSearch($sql, $params, [
                    't.trade_reference', 't.exchange_reference', 't.security_id',
                    't.security_name', 't.client_name', 't.client_cds_account'
                ]);
                $sql .= ' ORDER BY t.trade_date ASC, t.id ASC';
                $stmt = $db->prepare($sql);
                $stmt->execute($params);

                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $security = trim((string)($row['security_name'] ?? ''));
                    if ($security === '') {
                        $security = trim((string)($row['security_id'] ?? ''));
                    }
                    $clientName = trim((string)($row['client_name'] ?? ''));
                    $settlementDate = trim((string)($row['settlement_date'] ?? ''));
                    $description = 'Custodian ' . strtoupper((string)($row['trade_side'] ?? '')) . ' trade';
                    if ($security !== '') {
                        $description .= ' - ' . $security;
                    }
                    if ($clientName !== '') {
                        $description .= ' - ' . $clientName;
                    }
                    if ($settlementDate !== '') {
                        $description .= ' (settles ' . $settlementDate . ')';
                    }
                    $row['description'] = $description;
                    $pushCustodianTrade($row);
                }
            } catch (Throwable $e) {
                error_log("Entity ledger custodian trades code={$custodian_code}, id={$entity_id}: " . $e->getMessage());
            }
        }
    }

    // ------------------------------------------------------------------
    // General ledger records - mirrors debtors.php source-selection rules.
    // ------------------------------------------------------------------
    try {
        if ($entity_type === 'chart_account') {
            $sql = "SELECT gl.transaction_date,
                           'gl_entry' AS transaction_type,
                           gl.reference_no AS reference,
                           NULL AS related_reference,
                           gl.description,
                           gl.debit_amount,
                           gl.credit_amount,
                           COALESCE(gl.currency, 'TSH') AS currency,
                           gl.account_code AS account,
                           COALESCE(coa.account_name, gl.account_name, gl.account_code) AS account_name,
                           'general_ledger' AS source_table,
                           gl.id AS source_id,
                           gl.created_at,
                           gl.created_by,
                           'GL Entry' AS source_label
                    FROM general_ledger gl
                    LEFT JOIN chart_of_accounts coa ON gl.account_code = coa.account_code
                    WHERE gl.account_code = ?";
            $params = [$entity_id];
            $addDateScope($sql, $params, 'gl.transaction_date');
            $addSearch($sql, $params, ['gl.reference_no', 'gl.description']);
            $sql .= ' ORDER BY gl.transaction_date ASC, gl.id ASC';
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $pushGl($row);
            }
        } elseif ($entity_type === 'client') {
            $cds = trim((string)($entity['cds_account'] ?? $entity['code'] ?? ''));
            if ($cds !== '' || $entity_name !== '') {
                $tradeStmt = $db->prepare("SELECT trade_reference FROM trades WHERE client_cds_account = ? OR client_name = ?");
                $tradeStmt->execute([$cds, $entity_name]);
                $tradeRefs = array_values(array_filter($tradeStmt->fetchAll(PDO::FETCH_COLUMN), static function ($ref) {
                    return trim((string)$ref) !== '';
                }));

                if ($tradeRefs) {
                    $placeholders = implode(',', array_fill(0, count($tradeRefs), '?'));
                    $sql = "SELECT gl.transaction_date,
                                   'gl_entry' AS transaction_type,
                                   gl.reference_no AS reference,
                                   NULL AS related_reference,
                                   gl.description,
                                   gl.debit_amount,
                                   gl.credit_amount,
                                   COALESCE(gl.currency, 'TSH') AS currency,
                                   gl.account_code AS account,
                                   COALESCE(coa.account_name, gl.account_name, gl.account_code) AS account_name,
                                   'general_ledger' AS source_table,
                                   gl.id AS source_id,
                                   gl.created_at,
                                   gl.created_by,
                                   'GL Entry' AS source_label
                            FROM general_ledger gl
                            LEFT JOIN chart_of_accounts coa ON gl.account_code = coa.account_code
                            WHERE gl.reference_no IN ({$placeholders})";
                    $params = $tradeRefs;
                    // debtors.php historically applies no as-of filter to client GL rows.
                    // Preserve that behavior unless the user explicitly selects a date range.
                    if ($start_date && $end_date) {
                        $sql .= ' AND gl.transaction_date BETWEEN ? AND ?';
                        $params[] = $start_date;
                        $params[] = $end_date;
                    }
                    $addSearch($sql, $params, ['gl.reference_no', 'gl.description']);
                    $sql .= ' ORDER BY gl.transaction_date ASC, gl.id ASC';
                    $stmt = $db->prepare($sql);
                    $stmt->execute($params);
                    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                        $pushGl($row);
                    }
                }
            }
        } elseif ($entity_type !== 'bank_account') {
            $sql = "SELECT gl.transaction_date,
                           'gl_entry' AS transaction_type,
                           gl.reference_no AS reference,
                           NULL AS related_reference,
                           gl.description,
                           gl.debit_amount,
                           gl.credit_amount,
                           COALESCE(gl.currency, 'TSH') AS currency,
                           gl.account_code AS account,
                           COALESCE(coa.account_name, gl.account_name, gl.account_code) AS account_name,
                           'general_ledger' AS source_table,
                           gl.id AS source_id,
                           gl.created_at,
                           gl.created_by,
                           CASE WHEN gl.reference_type IN ('payroll', 'salary_payment') THEN 'Payroll' ELSE 'GL Entry' END AS source_label
                    FROM general_ledger gl
                    LEFT JOIN chart_of_accounts coa ON gl.account_code = coa.account_code
                    WHERE ((gl.entity_id = ? AND gl.entity_type = ?)
                       OR (gl.entity_name != '' AND gl.entity_name IS NOT NULL AND gl.entity_name LIKE ? AND gl.entity_type = ?))";
            $params = [$entity_id, $entity_type, '%' . $entity_name . '%', $entity_type];
            $addDateScope($sql, $params, 'gl.transaction_date');
            $addSearch($sql, $params, ['gl.reference_no', 'gl.description', 'gl.entity_name']);
            $sql .= ' ORDER BY gl.transaction_date ASC, gl.id ASC';
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $pushGl($row);
            }
        }
    } catch (Throwable $e) {
        error_log("Entity ledger GL type={$entity_type}, id={$entity_id}: " . $e->getMessage());
    }

    // The debtors list intentionally adds every matching source row. Do not collapse
    // GL/cash rows here: doing so makes the detailed totals disagree with the list.
    usort($transactions, function ($a, $b) {
        $dateA = strtotime((string)($a['transaction_date'] ?? '1970-01-01'));
        $dateB = strtotime((string)($b['transaction_date'] ?? '1970-01-01'));
        if ($dateA === $dateB) {
            $sourceCmp = strcmp((string)($a['source_table'] ?? ''), (string)($b['source_table'] ?? ''));
            if ($sourceCmp !== 0) {
                return $sourceCmp;
            }
            return ((int)($a['source_id'] ?? 0)) <=> ((int)($b['source_id'] ?? 0));
        }
        return $dateA <=> $dateB;
    });

    $runningBalance = 0.0;
    $debitTotal = 0.0;
    $creditTotal = 0.0;

    foreach ($transactions as &$transaction) {
        $debit = (float)($transaction['debit'] ?? 0);
        $credit = (float)($transaction['credit'] ?? 0);
        $debitTotal += $debit;
        $creditTotal += $credit;

        // This is the exact list-view sign convention: payments/GL credits are positive,
        // receipts/GL debits are negative for ordinary entities.
        if ($entity_type === 'bank_account') {
            $runningBalance += $debit - $credit;
            $transaction['balance_impact'] = $debit - $credit;
        } else {
            $runningBalance += $credit - $debit;
            $transaction['balance_impact'] = $credit - $debit;
        }
        $transaction['running_balance'] = $runningBalance;
    }
    unset($transaction);

    $transactions = array_reverse($transactions);

    // The list view treats the configured current bank balance as authoritative.
    $balance = $entity_type === 'bank_account'
        ? (float)($entity['current_balance'] ?? $runningBalance)
        : $runningBalance;

    return [
        'transactions' => $transactions,
        'debit_total' => $debitTotal,
        'credit_total' => $creditTotal,
        'balance' => $balance,
    ];
}

// Get filter parameters
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : null;
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : null;
$search = isset($_GET['search']) ? $_GET['search'] : '';
$as_of_date = isset($_GET['as_of_date']) ? $_GET['as_of_date'] : date('Y-m-d');

// Validate dates
if ($start_date && !validateDate($start_date)) $start_date = null;
if ($end_date && !validateDate($end_date)) $end_date = null;
if (!validateDate($as_of_date)) $as_of_date = date('Y-m-d');

// Get entity details
$entity = getEntityDetails($db, $entity_type, $entity_id);

if (!$entity) {
    header('Location: ' . BASE_URL . 'finance/debtors?error=' . rawurlencode('Entity not found'));
    exit;
}

// Get transactions
$ledger_data = getEntityTransactions($db, $entity_type, $entity_id, $start_date, $end_date, $search, $entity, $as_of_date);
$transactions = $ledger_data['transactions'];
$debit_total = $ledger_data['debit_total'];
$credit_total = $ledger_data['credit_total'];
$balance = $ledger_data['balance'];

// Handle PDF Export
if (isset($_GET['export']) && $_GET['export'] == 'pdf') {
    $pdf = new TCPDF('L', PDF_UNIT, 'A4', true, 'UTF-8', false);
    $pdf->SetCreator(PDF_CREATOR);
    $pdf->SetAuthor('Financial System');
    $pdf->SetTitle('Ledger - ' . $entity['name']);
    $pdf->SetHeaderData('', 0, 'General Ledger', $entity['name']);
    $pdf->setHeaderFont(Array(PDF_FONT_NAME_MAIN, '', PDF_FONT_SIZE_MAIN));
    $pdf->setFooterFont(Array(PDF_FONT_NAME_DATA, '', PDF_FONT_SIZE_DATA));
    $pdf->SetDefaultMonospacedFont(PDF_FONT_MONOSPACED);
    $pdf->SetMargins(15, 25, 15);
    $pdf->SetHeaderMargin(10);
    $pdf->SetFooterMargin(10);
    $pdf->SetAutoPageBreak(TRUE, 15);
    $pdf->AddPage();
    
    // Title
    $pdf->SetFont('helvetica', 'B', 18);
    $pdf->Cell(0, 12, 'GENERAL LEDGER', 0, 1, 'C');
    $pdf->SetFont('helvetica', 'B', 14);
    $pdf->Cell(0, 10, $entity['name'] . ' (' . $entity['type_label'] . ')', 0, 1, 'C');
    $pdf->SetFont('helvetica', '', 10);
    $pdf->Cell(0, 6, 'Code: ' . $entity['code'], 0, 1);
    $pdf->Cell(0, 6, 'As of: ' . date('d/m/Y', strtotime($as_of_date)), 0, 1);
    if ($start_date && $end_date) {
        $pdf->Cell(0, 6, 'Period: ' . date('d/m/Y', strtotime($start_date)) . ' to ' . date('d/m/Y', strtotime($end_date)), 0, 1);
    }
    $pdf->Ln(8);
    
    // Balance Summary
    $pdf->SetFont('helvetica', 'B', 12);
    $pdf->Cell(0, 8, 'BALANCE SUMMARY', 0, 1);
    $pdf->SetFont('helvetica', '', 10);
    
    $balance_class = $balance > 0 ? 'Credit' : ($balance < 0 ? 'Debit' : 'Settled');
    $balance_abs = abs($balance);
    
    $summary_html = '<table border="1" cellpadding="5" cellspacing="0" style="width:100%;">
        <tr>
            <td style="width:50%;"><strong>Total Debit Transactions</strong></td>
            <td style="width:50%;text-align:right;">' . number_format($debit_total, 2) . '</td>
        </tr>
        <tr>
            <td><strong>Total Credit Transactions</strong></td>
            <td style="text-align:right;">' . number_format($credit_total, 2) . '</td>
        </tr>
        <tr style="background-color:#f2f2f2;">
            <td><strong>Balance (' . $balance_class . ')</strong></td>
            <td style="text-align:right;' . ($balance > 0 ? 'color:#00b050;' : ($balance < 0 ? 'color:#c00000;' : '')) . '"><strong>' . number_format($balance_abs, 2) . '</strong></td>
        </tr>
    </table>';
    
    $pdf->writeHTML($summary_html, true, false, true, false, '');
    $pdf->Ln(8);
    
    // Transactions Table
    $pdf->SetFont('helvetica', 'B', 12);
    $pdf->Cell(0, 8, 'TRANSACTION HISTORY', 0, 1);
    $pdf->SetFont('helvetica', '', 9);
    
    $html = '<table border="1" cellpadding="4" cellspacing="0" style="width:100%;">
        <thead>
            <tr style="background-color:#f2f2f2; font-weight:bold;">
                <th style="width:8%;">Date</th>
                <th style="width:10%;">Reference</th>
                <th style="width:20%;">Description</th>
                <th style="width:10%;">Account</th>
                <th style="width:10%;">Source</th>
                <th style="width:10%;text-align:right;">Debit</th>
                <th style="width:10%;text-align:right;">Credit</th>
                <th style="width:12%;text-align:right;">Balance</th>
            </tr>
        </thead>
        <tbody>';
    
    $display_transactions = array_slice($transactions, 0, 100);
    
    foreach ($display_transactions as $t) {
        $date = isset($t['transaction_date']) ? date('d/m/Y', strtotime($t['transaction_date'])) : '';
        $ref = $t['reference'] ?? '';
        $desc = $t['description'] ?? '';
        $account = $t['account'] ?? '';
        $source = $t['source_label'] ?? 'GL Entry';
        
        $debit = isset($t['debit']) && $t['debit'] > 0 ? number_format($t['debit'], 2) : '';
        $credit = isset($t['credit']) && $t['credit'] > 0 ? number_format($t['credit'], 2) : '';
        $balance_val = isset($t['running_balance']) ? number_format($t['running_balance'], 2) : '';
        $balance_color = $t['running_balance'] > 0 ? 'color:#00b050;' : ($t['running_balance'] < 0 ? 'color:#c00000;' : '');
        
        $html .= '<tr>
            <td>' . $date . '</td>
            <td>' . htmlspecialchars($ref) . '</td>
            <td>' . htmlspecialchars($desc) . '</td>
            <td>' . htmlspecialchars($account) . '</td>
            <td>' . $source . '</td>
            <td style="text-align:right;color:#c00000;">' . $debit . '</td>
            <td style="text-align:right;color:#00b050;">' . $credit . '</td>
            <td style="text-align:right;' . $balance_color . '">' . $balance_val . '</td>
        </tr>';
    }
    
    if (count($transactions) > 100) {
        $html .= '<tr><td colspan="8" style="text-align:center;font-style:italic;">... and ' . (count($transactions) - 100) . ' more transactions</td></tr>';
    }
    
    $html .= '</tbody></table>';
    
    $pdf->writeHTML($html, true, false, true, false, '');
    
    $filename = 'ledger_' . $entity['code'] . '_' . date('Ymd_His') . '.pdf';
    $pdf->Output($filename, 'I');
    exit;
}

// Handle Excel Export
if (isset($_GET['export']) && $_GET['export'] == 'excel') {
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="ledger_' . $entity['code'] . '_' . date('Ymd_His') . '.xls"');
    header('Pragma: no-cache');
    header('Expires: 0');
    
    echo "\xEF\xBB\xBF";
    echo '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">';
    echo '<head><meta charset="UTF-8">';
    echo '<style>
        td { mso-number-format:\@; }
        .number { mso-number-format:"#,##0.00"; }
        .header { font-weight: bold; background-color: #f2f2f2; }
        .debit { color: #c00000; font-weight: bold; }
        .credit { color: #00b050; font-weight: bold; }
        .total { font-weight: bold; background-color: #e6f3ff; }
    </style></head><body>';
    
    echo '<table border="1" cellpadding="3" cellspacing="0">';
    echo '<tr><td colspan="8" class="header" style="text-align: center; font-size: 14px;">GENERAL LEDGER</td></tr>';
    echo '<tr><td colspan="8"><strong>Entity:</strong> ' . htmlspecialchars($entity['name']) . ' (' . $entity['type_label'] . ')</td></tr>';
    echo '<tr><td colspan="8"><strong>Code:</strong> ' . htmlspecialchars($entity['code']) . '</td></tr>';
    echo '<tr><td colspan="8"><strong>Generated:</strong> ' . date('d/m/Y H:i:s') . '</td></tr>';
    echo '<tr><td colspan="8"><strong>Balance:</strong> ' . ($balance > 0 ? 'Credit' : ($balance < 0 ? 'Debit' : 'Settled')) . ' ' . number_format(abs($balance), 2) . '</td></tr>';
    echo '<tr><td colspan="8"></td></tr>';
    
    echo '<tr class="header">';
    echo '<td>Date</td>';
    echo '<td>Reference</td>';
    echo '<td>Description</td>';
    echo '<td>Account</td>';
    echo '<td>Source</td>';
    echo '<td>Debit</td>';
    echo '<td>Credit</td>';
    echo '<td>Balance</td>';
    echo '</tr>';
    
    foreach ($transactions as $t) {
        $date = isset($t['transaction_date']) ? date('d/m/Y', strtotime($t['transaction_date'])) : '';
        $ref = $t['reference'] ?? '';
        $desc = $t['description'] ?? '';
        $account = $t['account'] ?? '';
        $source = $t['source_label'] ?? 'GL Entry';
        $debit = isset($t['debit']) && $t['debit'] > 0 ? number_format($t['debit'], 2) : '';
        $credit = isset($t['credit']) && $t['credit'] > 0 ? number_format($t['credit'], 2) : '';
        $balance_val = isset($t['running_balance']) ? number_format($t['running_balance'], 2) : '';
        
        echo '<tr>';
        echo '<td>' . $date . '</td>';
        echo '<td>' . htmlspecialchars($ref) . '</td>';
        echo '<td>' . htmlspecialchars($desc) . '</td>';
        echo '<td>' . htmlspecialchars($account) . '</td>';
        echo '<td>' . $source . '</td>';
        echo '<td class="number debit">' . $debit . '</td>';
        echo '<td class="number credit">' . $credit . '</td>';
        echo '<td class="number">' . $balance_val . '</td>';
        echo '</tr>';
    }
    
    echo '<tr class="total">';
    echo '<td colspan="5">TOTALS:</td>';
    echo '<td class="number debit">' . number_format($debit_total, 2) . '</td>';
    echo '<td class="number credit">' . number_format($credit_total, 2) . '</td>';
    echo '<td class="number">' . number_format($balance, 2) . '</td>';
    echo '</tr>';
    
    echo '</table></body></html>';
    exit;
}

$page_title = 'General Ledger - ' . $entity['name'];
include '../includes/header.php';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #4F46E5;
            --primary-light: #818CF8;
            --primary-dark: #3730A3;
            --success: #10B981;
            --danger: #EF4444;
            --warning: #F59E0B;
            --gray-50: #F9FAFB;
            --gray-100: #F3F4F6;
            --gray-200: #E5E7EB;
            --gray-300: #D1D5DB;
            --gray-400: #9CA3AF;
            --gray-500: #6B7280;
            --gray-600: #4B5563;
            --gray-700: #374151;
            --gray-800: #1F2937;
            --gray-900: #111827;
            --shadow-sm: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
            --shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1), 0 1px 2px 0 rgba(0, 0, 0, 0.06);
            --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            --shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
            --shadow-xl: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
            --radius: 12px;
            --radius-sm: 8px;
            --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            background: var(--gray-50);
            color: var(--gray-800);
            line-height: 1.6;
        }

        .modern-container {
            max-width: 1440px;
            margin: 0 auto;
            padding: 24px 32px;
        }

        /* Back Button */
        .back-btn-modern {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 20px;
            background: white;
            border: 1px solid var(--gray-200);
            border-radius: var(--radius-sm);
            color: var(--gray-600);
            font-weight: 500;
            font-size: 14px;
            transition: var(--transition);
            text-decoration: none;
            box-shadow: var(--shadow-sm);
        }

        .back-btn-modern:hover {
            background: var(--gray-50);
            border-color: var(--gray-300);
            color: var(--gray-800);
            transform: translateX(-2px);
            box-shadow: var(--shadow-md);
            text-decoration: none;
        }

        /* Entity Header Card */
        .entity-header-modern {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            border-radius: var(--radius);
            padding: 32px 40px;
            margin-bottom: 28px;
            box-shadow: 0 8px 32px rgba(79, 70, 229, 0.25);
            position: relative;
            overflow: hidden;
        }

        .entity-header-modern::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -20%;
            width: 400px;
            height: 400px;
            background: rgba(255, 255, 255, 0.05);
            border-radius: 50%;
            pointer-events: none;
        }

        .entity-header-modern::after {
            content: '';
            position: absolute;
            bottom: -60%;
            left: -10%;
            width: 300px;
            height: 300px;
            background: rgba(255, 255, 255, 0.03);
            border-radius: 50%;
            pointer-events: none;
        }

        .entity-header-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: relative;
            z-index: 1;
            flex-wrap: wrap;
            gap: 20px;
        }

        .entity-info-left {
            display: flex;
            align-items: center;
            gap: 24px;
        }

        .entity-icon-wrapper {
            width: 72px;
            height: 72px;
            background: rgba(255, 255, 255, 0.15);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 32px;
            color: white;
            backdrop-filter: blur(10px);
            border: 2px solid rgba(255, 255, 255, 0.2);
        }

        .entity-name-section h1 {
            color: white;
            font-size: 28px;
            font-weight: 700;
            margin: 0 0 4px 0;
            letter-spacing: -0.5px;
        }

        .entity-meta {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
        }

        .meta-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 14px;
            background: rgba(255, 255, 255, 0.15);
            border-radius: 20px;
            color: white;
            font-size: 13px;
            font-weight: 500;
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.1);
        }

        .meta-badge i {
            font-size: 14px;
        }

        .status-badge {
            padding: 4px 16px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .status-badge.active {
            background: rgba(16, 185, 129, 0.3);
            color: #6EE7B7;
            border: 1px solid rgba(16, 185, 129, 0.2);
        }

        .status-badge.inactive {
            background: rgba(239, 68, 68, 0.3);
            color: #FCA5A5;
            border: 1px solid rgba(239, 68, 68, 0.2);
        }

        .balance-cards {
            display: flex;
            gap: 16px;
        }

        .balance-card-modern {
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
            border-radius: var(--radius-sm);
            padding: 16px 24px;
            min-width: 120px;
            text-align: center;
            border: 1px solid rgba(255, 255, 255, 0.1);
            transition: var(--transition);
        }

        .balance-card-modern:hover {
            background: rgba(255, 255, 255, 0.15);
            transform: translateY(-2px);
        }

        .balance-card-modern .label {
            color: rgba(255, 255, 255, 0.7);
            font-size: 12px;
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .balance-card-modern .amount {
            font-size: 22px;
            font-weight: 700;
            color: white;
            margin-top: 2px;
        }

        .balance-card-modern .amount.positive {
            color: #6EE7B7;
        }

        .balance-card-modern .amount.negative {
            color: #FCA5A5;
        }

        .balance-card-modern .sub-label {
            color: rgba(255, 255, 255, 0.5);
            font-size: 11px;
            margin-top: 2px;
        }

        /* Filter Section */
        .filter-section-modern {
            background: white;
            border-radius: var(--radius);
            padding: 20px 24px;
            margin-bottom: 24px;
            box-shadow: var(--shadow);
            border: 1px solid var(--gray-200);
        }

        .filter-grid {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr 1fr;
            gap: 16px;
            align-items: end;
        }

        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .filter-group label {
            font-size: 13px;
            font-weight: 600;
            color: var(--gray-600);
        }

        .filter-group .form-control {
            padding: 8px 12px;
            border: 1px solid var(--gray-200);
            border-radius: var(--radius-sm);
            font-size: 14px;
            transition: var(--transition);
            background: white;
        }

        .filter-group .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1);
        }

        .filter-group .input-group {
            display: flex;
            align-items: stretch;
        }

        .filter-group .input-group .form-control {
            border-radius: var(--radius-sm) 0 0 var(--radius-sm);
            flex: 1;
        }

        .filter-group .input-group .btn {
            border-radius: 0 var(--radius-sm) var(--radius-sm) 0;
            padding: 8px 16px;
            background: var(--gray-100);
            border: 1px solid var(--gray-200);
            border-left: none;
            color: var(--gray-600);
            transition: var(--transition);
        }

        .filter-group .input-group .btn:hover {
            background: var(--gray-200);
        }

        .filter-actions {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .btn-modern {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 20px;
            border-radius: var(--radius-sm);
            font-weight: 600;
            font-size: 14px;
            border: none;
            transition: var(--transition);
            cursor: pointer;
            text-decoration: none;
        }

        .btn-modern-primary {
            background: var(--primary);
            color: white;
        }

        .btn-modern-primary:hover {
            background: var(--primary-dark);
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(79, 70, 229, 0.3);
        }

        .btn-modern-secondary {
            background: var(--gray-100);
            color: var(--gray-700);
        }

        .btn-modern-secondary:hover {
            background: var(--gray-200);
        }

        .btn-modern-success {
            background: var(--success);
            color: white;
        }

        .btn-modern-success:hover {
            background: #059669;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);
        }

        .btn-modern-danger {
            background: var(--danger);
            color: white;
        }

        .btn-modern-danger:hover {
            background: #DC2626;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(239, 68, 68, 0.3);
        }

        /* Transactions Table */
        .table-wrapper {
            background: white;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            border: 1px solid var(--gray-200);
            overflow: hidden;
        }

        .table-header {
            padding: 16px 24px;
            background: var(--gray-50);
            border-bottom: 1px solid var(--gray-200);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .table-header h6 {
            font-size: 16px;
            font-weight: 600;
            color: var(--gray-800);
            margin: 0;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .table-header .badge-count {
            padding: 4px 14px;
            background: var(--primary);
            color: white;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }

        .table-responsive {
            overflow-x: auto;
        }

        .table-modern {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
        }

        .table-modern thead {
            background: var(--gray-50);
            border-bottom: 2px solid var(--gray-200);
        }

        .table-modern thead th {
            padding: 12px 16px;
            text-align: left;
            font-weight: 600;
            color: var(--gray-600);
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .table-modern thead th.text-end {
            text-align: right;
        }

        .table-modern tbody tr {
            border-bottom: 1px solid var(--gray-100);
            transition: var(--transition);
        }

        .table-modern tbody tr:hover {
            background: var(--gray-50);
        }

        .table-modern tbody tr:last-child {
            border-bottom: none;
        }

        .table-modern tbody td {
            padding: 12px 16px;
            vertical-align: middle;
        }

        .table-modern tbody td.text-end {
            text-align: right;
        }

        .source-badge {
            padding: 3px 12px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }

        .source-badge.receipt {
            background: #D1FAE5;
            color: #065F46;
        }

        .source-badge.payment {
            background: #FEE2E2;
            color: #991B1B;
        }

        .source-badge.gl_entry {
            background: #E0E7FF;
            color: #3730A3;
        }

        .source-badge.custodian_trade {
            background: #CFFAFE;
            color: #155E75;
        }

        .text-debit {
            color: var(--danger);
            font-weight: 600;
        }

        .text-credit {
            color: var(--success);
            font-weight: 600;
        }

        .text-balance-positive {
            color: var(--success);
            font-weight: 600;
        }

        .text-balance-negative {
            color: var(--danger);
            font-weight: 600;
        }

        .text-balance-zero {
            color: var(--gray-400);
            font-weight: 600;
        }

        .table-footer {
            padding: 12px 24px;
            background: var(--gray-50);
            border-top: 2px solid var(--gray-200);
            font-weight: 600;
        }

        .table-footer .totals-row {
            display: flex;
            justify-content: flex-end;
            gap: 32px;
        }

        .table-footer .totals-row .total-item {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .table-footer .totals-row .total-item .label {
            color: var(--gray-600);
            font-weight: 500;
        }

        .table-footer .totals-row .total-item .value {
            font-weight: 700;
            font-size: 15px;
        }

        .empty-state {
            text-align: center;
            padding: 60px 20px;
        }

        .empty-state i {
            font-size: 48px;
            color: var(--gray-300);
            margin-bottom: 16px;
        }

        .empty-state h5 {
            color: var(--gray-600);
            margin-bottom: 8px;
        }

        .empty-state p {
            color: var(--gray-400);
        }

        /* Animations */
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .animate-in {
            animation: fadeInUp 0.5s ease forwards;
        }

        .animate-in-delay-1 {
            animation-delay: 0.1s;
            opacity: 0;
        }

        .animate-in-delay-2 {
            animation-delay: 0.2s;
            opacity: 0;
        }

        .animate-in-delay-3 {
            animation-delay: 0.3s;
            opacity: 0;
        }

        /* Responsive */
        @media (max-width: 1200px) {
            .filter-grid {
                grid-template-columns: 1fr 1fr;
            }
        }

        @media (max-width: 992px) {
            .entity-header-content {
                flex-direction: column;
                align-items: stretch;
            }

            .balance-cards {
                justify-content: stretch;
            }

            .balance-card-modern {
                flex: 1;
            }
        }

        @media (max-width: 768px) {
            .modern-container {
                padding: 16px;
            }

            .entity-header-modern {
                padding: 24px;
            }

            .entity-info-left {
                flex-direction: column;
                text-align: center;
            }

            .entity-icon-wrapper {
                width: 56px;
                height: 56px;
                font-size: 24px;
            }

            .entity-name-section h1 {
                font-size: 22px;
            }

            .balance-cards {
                flex-direction: column;
            }

            .filter-grid {
                grid-template-columns: 1fr;
            }

            .filter-actions {
                justify-content: stretch;
            }

            .filter-actions .btn-modern {
                flex: 1;
                justify-content: center;
            }

            .table-header {
                flex-direction: column;
                gap: 8px;
                align-items: stretch;
                text-align: center;
            }

            .table-footer .totals-row {
                flex-direction: column;
                gap: 8px;
                align-items: stretch;
            }

            .table-footer .totals-row .total-item {
                justify-content: space-between;
            }
        }

        @media print {
            .back-btn-modern,
            .filter-section-modern {
                display: none !important;
            }

            .entity-header-modern {
                background: #4F46E5 !important;
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }

            .table-wrapper {
                box-shadow: none !important;
                border: 1px solid #ddd !important;
            }
        }

        /* Scrollbar Styling */
        .table-responsive::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }

        .table-responsive::-webkit-scrollbar-track {
            background: var(--gray-100);
            border-radius: 4px;
        }

        .table-responsive::-webkit-scrollbar-thumb {
            background: var(--gray-300);
            border-radius: 4px;
        }

        .table-responsive::-webkit-scrollbar-thumb:hover {
            background: var(--gray-400);
        }
    </style>
</head>
<body>

<div class="modern-container">
    <?php if (!empty($error_message)): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert" style="border-radius: var(--radius-sm);">
            <i class="bi bi-exclamation-triangle-fill me-2"></i>
            <?php echo $error_message; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Back Button -->
    <div class="animate-in">
        <a href="debtors.php<?php echo isset($_GET['return_to']) ? '?' . htmlspecialchars($_GET['return_to']) : ''; ?>" 
           class="back-btn-modern">
            <i class="bi bi-arrow-left"></i>
            Back to Debtors Report
        </a>
    </div>

    <br>

    <!-- Entity Header -->
    <div class="entity-header-modern animate-in animate-in-delay-1">
        <div class="entity-header-content">
            <div class="entity-info-left">
                <div class="entity-icon-wrapper">
                    <i class="bi <?php echo $entity['icon'] ?? 'bi-building'; ?>"></i>
                </div>
                <div class="entity-name-section">
                    <h1><?php echo htmlspecialchars($entity['name']); ?></h1>
                    <div class="entity-meta">
                        <span class="meta-badge">
                            <i class="bi bi-tag"></i>
                            <?php echo $entity['type_label']; ?>
                        </span>
                        <span class="meta-badge">
                            <i class="bi bi-hash"></i>
                            <?php echo htmlspecialchars($entity['code_label']); ?>: <?php echo htmlspecialchars($entity['code']); ?>
                        </span>
                        <?php if (isset($entity['status'])): ?>
                            <span class="status-badge <?php echo ($entity['status'] == 'active' && $entity['is_active'] == 1) ? 'active' : 'inactive'; ?>">
                                <?php echo ($entity['status'] == 'active' && $entity['is_active'] == 1) ? 'Active' : 'Inactive'; ?>
                            </span>
                        <?php endif; ?>
                        <?php if (isset($entity['created_at'])): ?>
                            <span class="meta-badge">
                                <i class="bi bi-calendar3"></i>
                                Since <?php echo date('M Y', strtotime($entity['created_at'])); ?>
                            </span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="balance-cards">
                <div class="balance-card-modern">
                    <div class="label">Balance</div>
                    <div class="amount <?php echo $balance > 0 ? 'positive' : ($balance < 0 ? 'negative' : ''); ?>">
                        <?php echo formatCurrency(abs($balance)); ?>
                    </div>
                    <div class="sub-label">
                        <?php echo $balance > 0 ? 'Credit' : ($balance < 0 ? 'Debit' : 'Settled'); ?>
                    </div>
                </div>
                <div class="balance-card-modern">
                    <div class="label">Total Debit</div>
                    <div class="amount negative">
                        <?php echo formatCurrency($debit_total); ?>
                    </div>
                </div>
                <div class="balance-card-modern">
                    <div class="label">Total Credit</div>
                    <div class="amount positive">
                        <?php echo formatCurrency($credit_total); ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Filters Section -->
    <div class="filter-section-modern animate-in animate-in-delay-2">
        <form method="GET" id="filterForm">
            <input type="hidden" name="type" value="<?php echo htmlspecialchars($entity_type); ?>">
            <input type="hidden" name="id" value="<?php echo htmlspecialchars($entity_id); ?>">
            <?php if (isset($_GET['return_to'])): ?>
                <input type="hidden" name="return_to" value="<?php echo htmlspecialchars($_GET['return_to']); ?>">
            <?php endif; ?>
            
            <div class="filter-grid">
                <div class="filter-group">
                    <label>From Date</label>
                    <input type="date" class="form-control" name="start_date" 
                           value="<?php echo htmlspecialchars($start_date); ?>"
                           max="<?php echo date('Y-m-d'); ?>">
                </div>
                
                <div class="filter-group">
                    <label>To Date</label>
                    <input type="date" class="form-control" name="end_date" 
                           value="<?php echo htmlspecialchars($end_date); ?>"
                           max="<?php echo date('Y-m-d'); ?>">
                </div>
                
                <div class="filter-group">
                    <label>Search</label>
                    <div class="input-group">
                        <input type="text" class="form-control" name="search" 
                               placeholder="Reference or description..."
                               value="<?php echo htmlspecialchars($search); ?>">
                        <button class="btn" type="submit">
                            <i class="bi bi-search"></i>
                        </button>
                    </div>
                </div>
                
                <div class="filter-group">
                    <label>Actions</label>
                    <div class="filter-actions">
                        <button type="submit" class="btn-modern btn-modern-primary">
                            <i class="bi bi-funnel"></i> Filter
                        </button>
                        <button type="button" class="btn-modern btn-modern-secondary" onclick="resetFilters()">
                            <i class="bi bi-arrow-counterclockwise"></i> Reset
                        </button>
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['export' => 'pdf'])); ?>" 
                           class="btn-modern btn-modern-danger" target="_blank">
                            <i class="bi bi-file-pdf"></i> PDF
                        </a>
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['export' => 'excel'])); ?>" 
                           class="btn-modern btn-modern-success">
                            <i class="bi bi-file-excel"></i> Excel
                        </a>
                    </div>
                </div>
            </div>
        </form>
    </div>

    <!-- Transactions Table -->
    <div class="animate-in animate-in-delay-3">
        <div class="table-wrapper">
            <div class="table-header">
                <h6>
                    <i class="bi bi-list-ul"></i>
                    Transaction History
                    <?php if ($entity_type === 'chart_account'): ?>
                        <small style="font-weight:400;color:var(--gray-400);font-size:12px;">
                            (GL Entries)
                        </small>
                    <?php elseif ($entity_type === 'bank_account'): ?>
                        <small style="font-weight:400;color:var(--gray-400);font-size:12px;">
                            (Receipts & Payments for this bank account)
                        </small>
                    <?php else: ?>
                        <small style="font-weight:400;color:var(--gray-400);font-size:12px;">
                            (Receipts, Payments & GL Entries)
                        </small>
                    <?php endif; ?>
                </h6>
                <div>
                    <span class="badge-count">
                        <i class="bi bi-file-text"></i> <?php echo count($transactions); ?> Transactions
                    </span>
                    <?php if ($start_date && $end_date): ?>
                        <span class="badge-count" style="background: var(--gray-500);">
                            <i class="bi bi-calendar-range"></i>
                            <?php echo date('d M Y', strtotime($start_date)); ?> - <?php echo date('d M Y', strtotime($end_date)); ?>
                        </span>
                    <?php endif; ?>
                </div>
            </div>
            
            <?php if (empty($transactions)): ?>
                <div class="empty-state">
                    <i class="bi bi-inbox"></i>
                    <h5>No transactions found</h5>
                    <p>Try adjusting your filter criteria or date range</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table-modern">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Reference</th>
                                <th>Description</th>
                                <th>Account</th>
                                <th>Source</th>
                                <th class="text-end">Debit</th>
                                <th class="text-end">Credit</th>
                                <th class="text-end">Balance</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($transactions as $transaction): 
                                $date = isset($transaction['transaction_date']) ? date('d/m/Y', strtotime($transaction['transaction_date'])) : '';
                                $ref = $transaction['reference'] ?? '';
                                $desc = $transaction['description'] ?? '';
                                $account = $transaction['account'] ?? '';
                                $source = $transaction['source_label'] ?? 'GL Entry';
                                
                                // Source badge class
                                $source_class = 'gl_entry';
                                if ($source === 'Receipt') $source_class = 'receipt';
                                elseif ($source === 'Payment') $source_class = 'payment';
                                elseif ($source === 'Custodian Trade') $source_class = 'custodian_trade';
                                
                                $debit = isset($transaction['debit']) && $transaction['debit'] > 0 ? $transaction['debit'] : 0;
                                $credit = isset($transaction['credit']) && $transaction['credit'] > 0 ? $transaction['credit'] : 0;
                                $balance_val = isset($transaction['running_balance']) ? $transaction['running_balance'] : 0;
                                
                                $balance_class = $balance_val > 0 ? 'text-balance-positive' : ($balance_val < 0 ? 'text-balance-negative' : 'text-balance-zero');
                            ?>
                                <tr>
                                    <td><strong><?php echo $date; ?></strong></td>
                                    <td><code style="background: var(--gray-100); padding: 2px 8px; border-radius: 4px; font-size: 13px;"><?php echo htmlspecialchars($ref); ?></code></td>
                                    <td><?php echo htmlspecialchars($desc); ?></td>
                                    <td><span style="background: var(--gray-100); padding: 2px 8px; border-radius: 4px; font-size: 12px;"><?php echo htmlspecialchars($account); ?></span></td>
                                    <td>
                                        <span class="source-badge <?php echo $source_class; ?>">
                                            <?php echo $source; ?>
                                        </span>
                                    </td>
                                    <td class="text-end text-debit">
                                        <?php echo $debit > 0 ? number_format($debit, 2) : '-'; ?>
                                    </td>
                                    <td class="text-end text-credit">
                                        <?php echo $credit > 0 ? number_format($credit, 2) : '-'; ?>
                                    </td>
                                    <td class="text-end <?php echo $balance_class; ?>">
                                        <?php echo number_format($balance_val, 2); ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <div class="table-footer">
                    <div class="totals-row">
                        <div class="total-item">
                            <span class="label">Total Debit:</span>
                            <span class="value text-debit"><?php echo number_format($debit_total, 2); ?></span>
                        </div>
                        <div class="total-item">
                            <span class="label">Total Credit:</span>
                            <span class="value text-credit"><?php echo number_format($credit_total, 2); ?></span>
                        </div>
                        <div class="total-item">
                            <span class="label">Balance:</span>
                            <span class="value <?php echo $balance > 0 ? 'text-credit' : ($balance < 0 ? 'text-debit' : ''); ?>">
                                <?php echo number_format($balance, 2); ?>
                                <span style="font-size: 12px; font-weight: 400; color: var(--gray-400);">
                                    (<?php echo $balance > 0 ? 'Credit' : ($balance < 0 ? 'Debit' : 'Settled'); ?>)
                                </span>
                            </span>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Reset filters
    window.resetFilters = function() {
        const form = document.getElementById('filterForm');
        form.querySelectorAll('input[type="date"], input[type="text"]').forEach(input => {
            input.value = '';
        });
        form.submit();
    };
    
    // Auto-submit on Enter key in search
    document.querySelector('input[name="search"]')?.addEventListener('keypress', function(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            document.getElementById('filterForm').submit();
        }
    });
});
</script>

<?php include '../includes/footer.php'; ?>
