<?php
require_once '../config/config.php';
require_once '../auth/auth_middleware.php';

// Security headers
header("X-Frame-Options: DENY");
header("X-Content-Type-Options: nosniff");
header("X-XSS-Protection: 1; mode=block");
header("Referrer-Policy: strict-origin-when-cross-origin");
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; font-src 'self' https://cdn.jsdelivr.net");

require_finance_officer();

// CSRF Protection
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$db = getDBConnection();
$success_message = '';
$error_message = '';

// =====================================================
// HELPER FUNCTIONS
// =====================================================

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

function validateAmount($amount) {
    return is_numeric($amount) && $amount > 0 && $amount <= 999999999.99;
}

function validateCurrency($currency) {
    $allowed_currencies = ['Tsh', 'Ksh', 'USD', 'UGsh'];
    return in_array($currency, $allowed_currencies);
}

function getFiscalYear($date) {
    $dateObj = new DateTime($date);
    return $dateObj->format('Y');
}

function getFiscalPeriod($date) {
    $dateObj = new DateTime($date);
    return (int)$dateObj->format('m');
}

function generatePaymentNo($db, $date = null, $prefix = 'PMT') {
    $year = date('Y', strtotime($date ?? 'now'));
    $month = date('m', strtotime($date ?? 'now'));
    $day = date('d', strtotime($date ?? 'now'));
    
    $base_no = $prefix . $year . $month . $day;
    
    $stmt = $db->prepare("
        SELECT payment_no FROM payments 
        WHERE payment_no LIKE ? 
        ORDER BY payment_no DESC 
        LIMIT 1
    ");
    $stmt->execute([$base_no . '%']);
    $last = $stmt->fetch();
    
    if ($last) {
        $last_seq = intval(substr($last['payment_no'], -4));
        $new_seq = str_pad($last_seq + 1, 4, '0', STR_PAD_LEFT);
    } else {
        $new_seq = '0001';
    }
    
    return $base_no . $new_seq;
}

function generateBulkPaymentNo($db, $date = null) {
    return generatePaymentNo($db, $date, 'BPMT');
}

function generateJournalNo($db) {
    $prefix = 'JRNL';
    $year = date('Y');
    $month = date('m');
    $day = date('d');
    
    $pattern = $prefix . $year . $month . $day . '%';
    
    $stmt = $db->prepare("
        SELECT journal_no FROM journal_entries 
        WHERE journal_no LIKE ? 
        ORDER BY journal_no DESC 
        LIMIT 1
    ");
    $stmt->execute([$pattern]);
    $last = $stmt->fetch();
    
    if ($last) {
        $last_no = $last['journal_no'];
        $seq = intval(substr($last_no, -4));
        $new_seq = str_pad($seq + 1, 4, '0', STR_PAD_LEFT);
    } else {
        $new_seq = '0001';
    }
    
    return $prefix . $year . $month . $day . $new_seq;
}

function getChartAccountInfo($db, $account_code) {
    $stmt = $db->prepare("SELECT id, account_name, normal_balance FROM chart_of_accounts WHERE account_code = ?");
    $stmt->execute([$account_code]);
    return $stmt->fetch();
}

function getAppropriateAccountLevel($db, $account_type) {
    try {
        $query = "
            SELECT account_code, account_name, level, is_group_account 
            FROM chart_of_accounts 
            WHERE account_type = ? 
            AND is_active = 1 
            AND (is_group_account = 0 OR level IN (2, 3, 4, 5))
            ORDER BY level DESC, account_code
            LIMIT 1
        ";
        
        $stmt = $db->prepare($query);
        $stmt->execute([$account_type]);
        $account = $stmt->fetch();
        
        if ($account) {
            return $account;
        }
        
        $fallback_query = "
            SELECT account_code, account_name 
            FROM chart_of_accounts 
            WHERE account_type = 'expense' 
            AND is_active = 1 
            LIMIT 1
        ";
        $stmt = $db->prepare($fallback_query);
        $stmt->execute();
        return $stmt->fetch() ?: ['account_code' => '51', 'account_name' => 'Operating Expenses'];
        
    } catch (Exception $e) {
        error_log("Error getting appropriate account level: " . $e->getMessage());
        return ['account_code' => '51', 'account_name' => 'Operating Expenses'];
    }
}

function getTradeDetails($db, $trade_reference) {
    $stmt = $db->prepare("
        SELECT t.*, 
               c.fee_type as client_fee_type, 
               c.default_brokerage_fee,
               c.client_name
        FROM trades t
        LEFT JOIN clients c ON t.client_cds_account = c.cds_account
        WHERE t.trade_reference = ?
    ");
    $stmt->execute([$trade_reference]);
    return $stmt->fetch();
}

function getClientBalance($db, $client_cds_account) {
    $stmt = $db->prepare("
        SELECT 
            COALESCE(SUM(CASE WHEN trade_side = 'buy' THEN consideration ELSE -consideration END), 0) as net_position,
            COUNT(*) as trade_count
        FROM trades 
        WHERE client_cds_account = ? 
        AND status = 'active'
    ");
    $stmt->execute([$client_cds_account]);
    return $stmt->fetch();
}

function getUnsettledTrades($db, $client_cds_account) {
    $stmt = $db->prepare("
        SELECT trade_reference, security_id, trade_side, quantity, price, consideration, trade_date
        FROM trades 
        WHERE client_cds_account = ? 
        AND status = 'active'
        AND (settlement_status IS NULL OR settlement_status != 'settled')
        ORDER BY trade_date DESC
    ");
    $stmt->execute([$client_cds_account]);
    return $stmt->fetchAll();
}

function getValidAccountCode($db, $account_code, $default = null) {
    $stmt = $db->prepare("SELECT account_code FROM chart_of_accounts WHERE account_code = ? AND is_active = 1");
    $stmt->execute([$account_code]);
    $account = $stmt->fetch();
    
    if ($account) {
        return $account['account_code'];
    }
    
    if ($default) {
        $stmt = $db->prepare("SELECT account_code FROM chart_of_accounts WHERE account_code = ? AND is_active = 1");
        $stmt->execute([$default]);
        $account = $stmt->fetch();
        if ($account) {
            return $account['account_code'];
        }
    }
    
    // Try to get any active account
    $stmt = $db->query("SELECT account_code FROM chart_of_accounts WHERE is_active = 1 LIMIT 1");
    $account = $stmt->fetch();
    
    if ($account) {
        return $account['account_code'];
    }
    
    return null;
}

function getControlAccount($db, $paid_to) {
    // Define the control account mapping with fallback options
    $control_account_map = [
        'A' => ['code' => '73111', 'fallback' => '73100'], // Agents Control
        'B' => ['code' => '72714', 'fallback' => '72700'], // Brokers Control
        'C' => ['code' => '73101', 'fallback' => '73100'], // Clients Control
        'S' => ['code' => '73102', 'fallback' => '73100'], // Suppliers Control
        'D' => ['code' => '72114', 'fallback' => '72100'], // Custodians Control
        'E' => ['code' => '72715', 'fallback' => '72700'], // Employees Control
        'O' => ['code' => '73113', 'fallback' => '73100'], // Chart Accounts
    ];
    
    // Get the account mapping for the paid_to type
    $mapping = $control_account_map[$paid_to] ?? ['code' => '73100', 'fallback' => '73100'];
    $account_code = $mapping['code'];
    $fallback_code = $mapping['fallback'];
    
    // First, check if the primary account exists
    $stmt = $db->prepare("SELECT account_code, account_name FROM chart_of_accounts WHERE account_code = ? AND is_active = 1");
    $stmt->execute([$account_code]);
    $account = $stmt->fetch();
    
    if ($account) {
        return $account['account_code'];
    }
    
    // If primary doesn't exist, try the fallback
    $stmt = $db->prepare("SELECT account_code, account_name FROM chart_of_accounts WHERE account_code = ? AND is_active = 1");
    $stmt->execute([$fallback_code]);
    $account = $stmt->fetch();
    
    if ($account) {
        return $account['account_code'];
    }
    
    // If neither exists, try to get any active expense account
    $stmt = $db->query("SELECT account_code FROM chart_of_accounts WHERE account_type = 'expense' AND is_active = 1 LIMIT 1");
    $account = $stmt->fetch();
    
    if ($account) {
        return $account['account_code'];
    }
    
    // Ultimate fallback - get any active account
    $stmt = $db->query("SELECT account_code FROM chart_of_accounts WHERE is_active = 1 LIMIT 1");
    $account = $stmt->fetch();
    
    if ($account) {
        return $account['account_code'];
    }
    
    // If all fails, throw an exception with helpful message
    throw new Exception("No valid account found for paid_to: $paid_to. Please check chart_of_accounts table.");
}

function createJournalEntry($db, $data) {
    try {
        $journal_no = generateJournalNo($db);
        $fiscal_year = getFiscalYear($data['transaction_date']);
        $fiscal_period = getFiscalPeriod($data['transaction_date']);

        // Validate account exists. If the exact account is missing, use a safe fallback
        // so payments are not left half-posted without ledger visibility.
        $account_info = getChartAccountInfo($db, $data['account_code']);

        if (!$account_info) {
            $fallback = getValidAccountCode($db, $data['account_code'], '73100');
            if ($fallback) {
                $account_info = getChartAccountInfo($db, $fallback);
                error_log("Account code {$data['account_code']} not found, using fallback: $fallback");
                $data['account_code'] = $fallback;
            }

            if (!$account_info) {
                throw new Exception("No valid account found for code: {$data['account_code']}");
            }
        }

        $account_id = $account_info['id'];
        $account_name = $account_info['account_name'];
        $normal_balance = $account_info['normal_balance'] ?? 'debit';

        $current_user = $_SESSION['username'] ?? 'system';
        $user_id = $_SESSION['user_id'] ?? null;

        // Keep reference_type within DB limits while preserving payment and bulk-payment meaning.
        $reference_type = $data['reference_type'] ?? 'journal';
        $reference_type_map = [
            'bulk_payment' => 'bulk_pmt',
            'bulk_pmt' => 'bulk_pmt',
            'bulk_payment_reversal' => 'bulk_rev',
            'bulk_rev' => 'bulk_rev',
            'payment' => 'payment',
            'receipt' => 'receipt',
            'reversal' => 'reversal',
            'journal' => 'journal',
        ];
        $reference_type = $reference_type_map[$reference_type] ?? 'journal';

        if (strlen($reference_type) > 20) {
            $reference_type = substr($reference_type, 0, 20);
        }

        $stmt = $db->prepare("
            INSERT INTO journal_entries (
                journal_no, transaction_date, reference_no, reference_type, 
                description, account_code, account_name, debit_amount, 
                credit_amount, currency, entity_id, entity_name, entity_type,
                bank_account_id, bank_name, bank_account_number,
                fiscal_year, fiscal_period, posted_by, posted_at,
                status, created_by, created_by_username
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), 'posted', ?, ?)
        ");

        $stmt->execute([
            $journal_no,
            $data['transaction_date'],
            $data['reference_no'],
            $reference_type,
            $data['description'],
            $data['account_code'],
            $account_name,
            $data['debit_amount'],
            $data['credit_amount'],
            $data['currency'],
            $data['entity_id'] ?? null,
            $data['entity_name'] ?? null,
            $data['entity_type'] ?? null,
            $data['bank_account_id'] ?? null,
            $data['bank_name'] ?? null,
            $data['bank_account_number'] ?? null,
            $fiscal_year,
            $fiscal_period,
            $user_id,
            $user_id,
            $current_user
        ]);

        $journal_id = $db->lastInsertId();

        // Local ledger fix: mirror every posted journal entry into general_ledger
        // so both normal and bulk payments appear in ledger/reporting views.
        $gl_stmt = $db->prepare("
            INSERT INTO general_ledger (
                journal_id, transaction_date, account_id, account_code, account_name,
                debit_amount, credit_amount, running_balance, balance_type,
                description, reference_no, reference_type,
                entity_id, entity_name, entity_type,
                currency, fiscal_year, fiscal_period,
                is_reconciled, reconciliation_id, status, notes,
                created_by, created_by_username
            ) VALUES (?, ?, ?, ?, ?, ?, ?, 0.00, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, NULL, 'active', NULL, ?, ?)
        ");

        $gl_stmt->execute([
            $journal_id,
            $data['transaction_date'],
            $account_id,
            $data['account_code'],
            $account_name,
            $data['debit_amount'],
            $data['credit_amount'],
            $normal_balance,
            $data['description'],
            $data['reference_no'],
            $reference_type,
            $data['entity_id'] ?? null,
            $data['entity_name'] ?? null,
            $data['entity_type'] ?? null,
            $data['currency'],
            $fiscal_year,
            $fiscal_period,
            $user_id,
            $current_user
        ]);

        return $journal_id;

    } catch (Exception $e) {
        error_log("Journal entry creation error: " . $e->getMessage());
        throw $e;
    }
}

function updateBankBalance($db, $bank_id, $amount) {
    try {
        $stmt = $db->prepare("
            UPDATE banks_accounts 
            SET current_balance = current_balance - ?, 
                available_balance = available_balance - ?
            WHERE id = ?
        ");
        
        $result = $stmt->execute([$amount, $amount, $bank_id]);
        
        if (!$result) {
            $error_info = $stmt->errorInfo();
            error_log("Failed to update bank balance: " . json_encode($error_info));
            return false;
        }
        
        return true;
    } catch (Exception $e) {
        error_log("Exception updating bank balance: " . $e->getMessage());
        return false;
    }
}

function updateTradeSettlementStatus($db, $trade_reference, $status, $notes = null) {
    $stmt = $db->prepare("
        UPDATE trades 
        SET settlement_status = ?,
            settlement_notes = ?,
            settled_at = NOW(),
            settled_by = ?
        WHERE trade_reference = ?
    ");
    return $stmt->execute([$status, $notes, $_SESSION['username'] ?? 'system', $trade_reference]);
}

function getPaymentMethods($db) {
    $stmt = $db->query("
        SELECT id, code, description, cashbook, priority, status 
        FROM payment_methods 
        WHERE status = 'active' 
        ORDER BY priority
    ");
    return $stmt->fetchAll();
}

function getLedgerTypes($db) {
    $stmt = $db->query("
        SELECT code, description, status 
        FROM ledger_types 
        WHERE status = 'active' 
        ORDER BY description
    ");
    return $stmt->fetchAll();
}

function getBankAccounts($db) {
    $stmt = $db->query("
        SELECT id, code, bank_name, account_name, account_number, currency, current_balance 
        FROM banks_accounts 
        WHERE status = 'active' 
        ORDER BY bank_name, account_name
    ");
    return $stmt->fetchAll();
}

function getBankAccountDetails($db, $account_id) {
    $stmt = $db->prepare("SELECT id, code, bank_name, account_name, account_number, current_balance FROM banks_accounts WHERE id = ?");
    $stmt->execute([$account_id]);
    return $stmt->fetch();
}

function exportPaymentsToExcel($payments) {
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="payments_' . date('Y-m-d_H-i-s') . '.xls"');
    header('Pragma: no-cache');
    header('Expires: 0');

    echo "\xEF\xBB\xBF";
    echo '<table border="1">';

    echo '<tr>';
    echo '<th>Payment No</th>';
    echo '<th>Date</th>';
    echo '<th>Payment Type</th>';
    echo '<th>Payee Type</th>';
    echo '<th>Payee Name</th>';
    echo '<th>Payee ID</th>';
    echo '<th>Account No</th>';
    echo '<th>Trade Reference</th>';
    echo '<th>Bulk Payment No</th>';
    echo '<th>Amount</th>';
    echo '<th>Currency</th>';
    echo '<th>Bank Account</th>';
    echo '<th>Bank Account Number</th>';
    echo '<th>Payment Mode</th>';
    echo '<th>Cheque No</th>';
    echo '<th>Description</th>';
    echo '<th>Financial Record</th>';
    echo '<th>Created By</th>';
    echo '<th>Created At</th>';
    echo '<th>Status</th>';
    echo '</tr>';

    $total_amount = 0;
    foreach ($payments as $payment) {
        echo '<tr>';
        echo '<td>' . htmlspecialchars($payment['payment_no'] ?? '') . '</td>';
        echo '<td>' . htmlspecialchars($payment['payment_date'] ?? '') . '</td>';
        echo '<td>' . htmlspecialchars($payment['payment_type'] ?? 'general') . '</td>';
        echo '<td>' . htmlspecialchars($payment['paid_to_desc'] ?? ($payment['paid_to'] ?? '')) . '</td>';
        echo '<td>' . htmlspecialchars($payment['name'] ?? '') . '</td>';
        echo '<td>' . htmlspecialchars($payment['name_id'] ?? '') . '</td>';
        echo '<td>' . htmlspecialchars($payment['account_no'] ?? '') . '</td>';
        echo '<td>' . htmlspecialchars($payment['trade_reference'] ?? '') . '</td>';
        echo '<td>' . htmlspecialchars($payment['bulk_payment_no'] ?? '') . '</td>';
        echo '<td>' . number_format($payment['amount'] ?? 0, 2) . '</td>';
        echo '<td>' . htmlspecialchars($payment['currency'] ?? 'Tsh') . '</td>';
        echo '<td>' . htmlspecialchars($payment['bank_name'] ?? '') . '</td>';
        echo '<td>' . htmlspecialchars($payment['bank_account_number'] ?? '') . '</td>';
        echo '<td>' . htmlspecialchars($payment['payment_method_desc'] ?? '') . '</td>';
        echo '<td>' . htmlspecialchars($payment['cheque_no'] ?? '') . '</td>';
        echo '<td>' . htmlspecialchars($payment['narration'] ?? '') . '</td>';
        echo '<td>' . htmlspecialchars($payment['record_in_financial'] ?? 'no') . '</td>';
        echo '<td>' . htmlspecialchars($payment['created_by_username'] ?? '') . '</td>';
        echo '<td>' . htmlspecialchars($payment['created_at'] ?? '') . '</td>';
        echo '<td>' . htmlspecialchars($payment['status'] ?? 'active') . '</td>';
        echo '</tr>';

        $total_amount += $payment['amount'] ?? 0;
    }

    echo '<tr>';
    echo '<td colspan="9" style="text-align: right; font-weight: bold;">TOTAL:</td>';
    echo '<td style="font-weight: bold;">' . number_format($total_amount, 2) . '</td>';
    echo '<td colspan="10"></td>';
    echo '</tr>';

    echo '</table>';
    exit;
}

// =====================================================
// BULK PAYMENT PROCESSING
// =====================================================

function processBulkPayment($db, $data) {
    try {
        $db->beginTransaction();
        
        $bulk_payment_no = generateBulkPaymentNo($db, $data['payment_date']);
        $total_amount = floatval($data['total_amount']);
        $payment_ids = [];
        $payment_references = [];
        
        $bank_account = getBankAccountDetails($db, $data['bank_account_id']);
        if (!$bank_account) {
            throw new Exception("Bank account not found");
        }
        
        // Insert into bulk_payments table
        $stmt = $db->prepare("
            INSERT INTO bulk_payments (
                bulk_payment_no, payment_date, payment_mode, 
                total_amount, currency, narration, 
                bank_account_id, bank_name, bank_account_number,
                created_by_username, status, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', NOW())
        ");
        
        $stmt->execute([
            $bulk_payment_no,
            $data['payment_date'],
            $data['payment_mode'],
            $total_amount,
            $data['currency'],
            $data['narration'],
            $data['bank_account_id'],
            $bank_account['bank_name'],
            $bank_account['account_number'],
            $_SESSION['username'] ?? 'system'
        ]);
        
        $bulk_payment_id = $db->lastInsertId();
        $payment_numbers = [];
        
        // Process each payment in the bulk
        foreach ($data['payments'] as $payment_item) {
            $payment_no = generatePaymentNo($db, $data['payment_date'], 'PMT');
            $payment_numbers[] = $payment_no;
            
            $stmt = $db->prepare("
                INSERT INTO payments (
                    payment_no, payment_date, payment_mode, paid_to,
                    name, name_id, source_type, record_in_financial, ac_credit,
                    currency, account_no, amount, cheque_no, narration,
                    trade_reference, payment_type, bulk_payment_id, bulk_payment_no,
                    created_by_username, created_at, status,
                    bank_name, bank_account_number
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), 'active', ?, ?)
            ");
            
            $stmt->execute([
                $payment_no,
                $data['payment_date'],
                $data['payment_mode'],
                $payment_item['paid_to'],
                $payment_item['name'],
                $payment_item['entity_id'] ?? null,
                $payment_item['entity_type'] ?? null,
                $data['record_in_financial'] ?? 'yes',
                $data['bank_account_id'],
                $data['currency'],
                $payment_item['account_no'] ?? '',
                $payment_item['amount'],
                $payment_item['cheque_no'] ?? '',
                $payment_item['narration'] ?? $data['narration'],
                $payment_item['trade_reference'] ?? null,
                $payment_item['payment_type'] ?? 'general',
                $bulk_payment_id,
                $bulk_payment_no,
                $_SESSION['username'] ?? 'system',
                $bank_account['bank_name'] ?? '',
                $bank_account['account_number'] ?? ''
            ]);
            
            $payment_ids[] = $db->lastInsertId();
            
            if (!empty($payment_item['trade_reference'])) {
                updateTradeSettlementStatus($db, $payment_item['trade_reference'], 'settled', 
                    "Bulk Payment: $bulk_payment_no - {$payment_item['narration']}");
            }
        }
        
        // Create journal entries if financial recording is enabled
        if (($data['record_in_financial'] ?? 'yes') == 'yes') {
            // Pre-generate all journal entries data first
            $journal_entries_data = [];
            
            // Credit journal entry for bank (total amount)
            $journal_entries_data[] = [
                'transaction_date' => $data['payment_date'],
                'reference_no' => $bulk_payment_no,
                'reference_type' => 'bulk_pmt',
                'description' => "Bulk Payment: $bulk_payment_no - " . count($data['payments']) . " payments",
                'account_code' => $bank_account['code'],
                'debit_amount' => 0,
                'credit_amount' => $total_amount,
                'currency' => $data['currency'],
                'bank_account_id' => $data['bank_account_id'],
                'bank_name' => $bank_account['bank_name'],
                'bank_account_number' => $bank_account['account_number']
            ];
            
            // Debit journal entries for each payee
            foreach ($data['payments'] as $payment_item) {
                // Get the control account using the updated function.
                // For Chart Account payments, use the selected chart account directly.
                $control_account_code = getControlAccount($db, $payment_item['paid_to']);
                if (($payment_item['paid_to'] ?? '') === 'O' && !empty($payment_item['entity_id'])) {
                    $selected_account_code = getValidAccountCode($db, $payment_item['entity_id'], null);
                    if ($selected_account_code) {
                        $control_account_code = $selected_account_code;
                    } else {
                        error_log("Selected chart account '{$payment_item['entity_id']}' not found for bulk item; using control account '$control_account_code'");
                    }
                }

                $journal_entries_data[] = [
                    'transaction_date' => $data['payment_date'],
                    'reference_no' => $bulk_payment_no,
                    'reference_type' => 'bulk_pmt',
                    'description' => "Payment to {$payment_item['name']}: {$payment_item['narration']}",
                    'account_code' => $control_account_code,
                    'debit_amount' => $payment_item['amount'],
                    'credit_amount' => 0,
                    'currency' => $data['currency'],
                    'entity_id' => $payment_item['entity_id'] ?? null,
                    'entity_name' => $payment_item['name'],
                    'entity_type' => $payment_item['entity_type'] ?? null,
                    'bank_account_id' => $data['bank_account_id'],
                    'bank_name' => $bank_account['bank_name'],
                    'bank_account_number' => $bank_account['account_number']
                ];
            }
            
            // Now create all journal entries with unique numbers
            foreach ($journal_entries_data as $entry) {
                createJournalEntry($db, $entry);
            }
            
            // Update bank balance once (total amount)
            updateBankBalance($db, $data['bank_account_id'], $total_amount);
        }
        
        // Update bulk payment status to processed
        $stmt = $db->prepare("
            UPDATE bulk_payments 
            SET status = 'processed', processed_at = NOW(), processed_by = ?
            WHERE id = ?
        ");
        $stmt->execute([$_SESSION['user_id'] ?? null, $bulk_payment_id]);
        
        $db->commit();
        
        return [
            'success' => true,
            'bulk_payment_no' => $bulk_payment_no,
            'bulk_payment_id' => $bulk_payment_id,
            'payment_ids' => $payment_ids,
            'payment_references' => $payment_numbers,
            'total_amount' => $total_amount,
            'payment_count' => count($data['payments'])
        ];
        
    } catch (Exception $e) {
        $db->rollBack();
        error_log("Bulk payment error: " . $e->getMessage());
        throw $e;
    }
}

// =====================================================
// AJAX HANDLERS
// =====================================================

if (isset($_GET['ajax']) && $_GET['ajax'] == 'get_entities') {
    $ledger_type = $_GET['ledger_type'] ?? '';
    $entities = [];
    
    try {
        switch ($ledger_type) {
            case 'A':
                $query = "SELECT id, agent_code as code, name, 'agent' as type, contact_person, phone, email FROM agents WHERE status = 'active' ORDER BY name";
                break;
            case 'S':
                $query = "SELECT id, supplier_code as code, name, 'supplier' as type, contact_person, phone, email FROM suppliers WHERE status = 'active' ORDER BY name";
                break;
            case 'C':
                $query = "SELECT id, client_code as code, client_name as name, cds_account, 'client' as type, phone, email FROM clients WHERE status = 'active' ORDER BY client_name";
                break;
            case 'D':
                $query = "SELECT id, custodian_code as code, custodian_name as name, 'custodian' as type, contact_person, phone, email FROM custodians WHERE status = 'active' ORDER BY custodian_name";
                break;
            case 'B':
                $query = "SELECT id, broker_code as code, broker_name as name, 'broker' as type, contact_person, phone, email FROM brokers WHERE status = 'active' ORDER BY broker_name";
                break;
            case 'E':
                $query = "SELECT id, username as code, full_name as name, 'employee' as type, email, phone FROM users WHERE status = 'active' ORDER BY full_name";
                break;
            case 'O':
                $query = "
                    SELECT 
                        account_code as code, 
                        account_name as name, 
                        account_type, 
                        level, 
                        is_group_account, 
                        'chart_account' as type,
                        CONCAT(account_name, ' (', account_code, ')') as display_name
                    FROM chart_of_accounts 
                    WHERE is_active = 1 
                    AND (is_group_account = 0 OR level IN (2, 3, 4, 5))
                    ORDER BY account_type, account_code
                ";
                break;
            default:
                $entities = [];
                break;
        }
        
        if (!empty($query)) {
            $stmt = $db->prepare($query);
            $stmt->execute();
            $entities = $stmt->fetchAll();
        }
        
        header('Content-Type: application/json');
        echo json_encode($entities);
        exit;
        
    } catch (PDOException $e) {
        error_log("Error fetching entities: " . $e->getMessage());
        header('Content-Type: application/json');
        echo json_encode([]);
        exit;
    }
}

if (isset($_GET['ajax']) && $_GET['ajax'] == 'get_entity_details') {
    $ledger_type = $_GET['ledger_type'] ?? '';
    $entity_id = $_GET['entity_id'] ?? '';
    $details = [];
    
    try {
        switch ($ledger_type) {
            case 'A':
                $query = "SELECT id, agent_code as code, name, contact_person, phone, email FROM agents WHERE id = ?";
                break;
            case 'S':
                $query = "SELECT id, supplier_code as code, name, contact_person, phone, email FROM suppliers WHERE id = ?";
                break;
            case 'C':
                $query = "SELECT id, client_code as code, client_name as name, cds_account, phone, email FROM clients WHERE id = ?";
                break;
            case 'D':
                $query = "SELECT id, custodian_code as code, custodian_name as name, contact_person, phone, email FROM custodians WHERE id = ?";
                break;
            case 'B':
                $query = "SELECT id, broker_code as code, broker_name as name, contact_person, phone, email FROM brokers WHERE id = ?";
                break;
            case 'E':
                $query = "SELECT id, username as code, full_name as name, email, phone FROM users WHERE id = ?";
                break;
            case 'O':
                $query = "SELECT account_code as code, account_name as name, account_type, level FROM chart_of_accounts WHERE account_code = ?";
                break;
            default:
                $details = [];
                break;
        }
        
        if (!empty($query)) {
            $stmt = $db->prepare($query);
            $stmt->execute([$entity_id]);
            $details = $stmt->fetch();
        }
        
        header('Content-Type: application/json');
        echo json_encode($details);
        exit;
        
    } catch (PDOException $e) {
        error_log("Error fetching entity details: " . $e->getMessage());
        header('Content-Type: application/json');
        echo json_encode([]);
        exit;
    }
}

if (isset($_GET['ajax']) && $_GET['ajax'] == 'get_payment') {
    $payment_id = $_GET['payment_id'] ?? '';
    
    try {
        $stmt = $db->prepare("
            SELECT p.*, 
                   pm.description as payment_method_desc,
                   lt.description as paid_to_desc,
                   ba.bank_name,
                   ba.account_number as bank_account_number,
                   ba.account_name as bank_account_name,
                   ba.current_balance as bank_current_balance,
                   ba.code as bank_account_code
            FROM payments p
            LEFT JOIN payment_methods pm ON p.payment_mode = pm.id
            LEFT JOIN ledger_types lt ON p.paid_to = lt.code
            LEFT JOIN banks_accounts ba ON p.ac_credit = ba.id
            WHERE p.id = ?
        ");
        $stmt->execute([$payment_id]);
        $payment = $stmt->fetch();
        
        header('Content-Type: application/json');
        echo json_encode($payment ?: ['error' => 'Payment not found']);
        exit;
        
    } catch (PDOException $e) {
        error_log("Error fetching payment: " . $e->getMessage());
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Database error']);
        exit;
    }
}

if (isset($_GET['ajax']) && $_GET['ajax'] == 'get_journal_entries') {
    $payment_no = $_GET['payment_no'] ?? '';
    
    try {
        $reference_numbers = [$payment_no];

        $bulk_stmt = $db->prepare("SELECT bulk_payment_no FROM payments WHERE payment_no = ? AND bulk_payment_no IS NOT NULL AND bulk_payment_no <> '' LIMIT 1");
        $bulk_stmt->execute([$payment_no]);
        $payment_bulk = $bulk_stmt->fetch();
        if ($payment_bulk && !empty($payment_bulk['bulk_payment_no'])) {
            $reference_numbers[] = $payment_bulk['bulk_payment_no'];
        }

        $placeholders = implode(',', array_fill(0, count($reference_numbers), '?'));
        $stmt = $db->prepare("
            SELECT * FROM journal_entries 
            WHERE reference_no IN ($placeholders)
              AND reference_type IN ('payment', 'bulk_pmt', 'bulk_payment', 'journal')
            ORDER BY transaction_date, id
        ");
        $stmt->execute($reference_numbers);
        $journals = $stmt->fetchAll();
        
        header('Content-Type: application/json');
        echo json_encode($journals);
        exit;
        
    } catch (PDOException $e) {
        error_log("Error fetching journal entries: " . $e->getMessage());
        header('Content-Type: application/json');
        echo json_encode([]);
        exit;
    }
}

if (isset($_GET['ajax']) && $_GET['ajax'] == 'get_client_trades') {
    $client_cds = $_GET['client_cds'] ?? '';
    
    try {
        $trades = getUnsettledTrades($db, $client_cds);
        $balance = getClientBalance($db, $client_cds);
        
        header('Content-Type: application/json');
        echo json_encode([
            'trades' => $trades,
            'balance' => $balance
        ]);
        exit;
        
    } catch (PDOException $e) {
        error_log("Error fetching client trades: " . $e->getMessage());
        header('Content-Type: application/json');
        echo json_encode([]);
        exit;
    }
}

if (isset($_GET['ajax']) && $_GET['ajax'] == 'get_client_outstanding') {
    $client_cds = $_GET['client_cds'] ?? '';
    
    try {
        $stmt = $db->prepare("
            SELECT 
                SUM(CASE WHEN trade_side = 'buy' THEN consideration ELSE 0 END) as total_buy,
                SUM(CASE WHEN trade_side = 'sell' THEN consideration ELSE 0 END) as total_sell,
                SUM(final_brokerage_fee) as total_commission,
                COUNT(*) as trade_count
            FROM trades 
            WHERE client_cds_account = ? 
            AND status = 'active'
            AND (settlement_status IS NULL OR settlement_status != 'settled')
        ");
        $stmt->execute([$client_cds]);
        $outstanding = $stmt->fetch();
        
        header('Content-Type: application/json');
        echo json_encode($outstanding);
        exit;
        
    } catch (PDOException $e) {
        error_log("Error fetching outstanding: " . $e->getMessage());
        header('Content-Type: application/json');
        echo json_encode([]);
        exit;
    }
}


// Handle Excel export
if (isset($_GET['export']) && $_GET['export'] == 'excel') {
    $export_conditions = [];
    $export_params = [];

    $export_start_date = $_GET['start_date'] ?? '';
    $export_end_date = $_GET['end_date'] ?? '';
    $export_paid_to_filter = $_GET['paid_to'] ?? '';
    $export_payment_no_filter = $_GET['payment_no'] ?? '';
    $export_financial_record_filter = $_GET['financial_record'] ?? '';
    $export_search_name = $_GET['search_name'] ?? '';
    $export_payment_type_filter = $_GET['payment_type'] ?? '';

    if (!empty($export_start_date) && !empty($export_end_date)) {
        $export_conditions[] = "p.payment_date BETWEEN ? AND ?";
        $export_params[] = $export_start_date;
        $export_params[] = $export_end_date;
    }

    if (!empty($export_paid_to_filter)) {
        $export_conditions[] = "p.paid_to = ?";
        $export_params[] = $export_paid_to_filter;
    }

    if (!empty($export_payment_no_filter)) {
        $export_conditions[] = "p.payment_no LIKE ?";
        $export_params[] = '%' . $export_payment_no_filter . '%';
    }

    if (!empty($export_financial_record_filter) && in_array($export_financial_record_filter, ['yes', 'no'])) {
        $export_conditions[] = "p.record_in_financial = ?";
        $export_params[] = $export_financial_record_filter;
    }

    if (!empty($export_payment_type_filter)) {
        $export_conditions[] = "p.payment_type = ?";
        $export_params[] = $export_payment_type_filter;
    }

    if (!empty($export_search_name)) {
        $export_conditions[] = "(p.name LIKE ? OR p.account_no LIKE ? OR p.trade_reference LIKE ?)";
        $export_params[] = '%' . $export_search_name . '%';
        $export_params[] = '%' . $export_search_name . '%';
        $export_params[] = '%' . $export_search_name . '%';
    }

    $export_where_clause = '';
    if (!empty($export_conditions)) {
        $export_where_clause = 'WHERE ' . implode(' AND ', $export_conditions);
    }

    try {
        $export_query = "
            SELECT p.*, 
                   pm.description as payment_method_desc,
                   lt.description as paid_to_desc,
                   ba.bank_name,
                   ba.account_number as bank_account_number,
                   ba.account_name as bank_account_name,
                   ba.current_balance as bank_current_balance,
                   ba.code as bank_account_code
            FROM payments p
            LEFT JOIN payment_methods pm ON p.payment_mode = pm.id
            LEFT JOIN ledger_types lt ON p.paid_to = lt.code
            LEFT JOIN banks_accounts ba ON p.ac_credit = ba.id
            $export_where_clause
            ORDER BY p.payment_date DESC, p.created_at DESC
        ";

        $export_stmt = $db->prepare($export_query);
        $export_stmt->execute($export_params);
        $all_payments_export = $export_stmt->fetchAll();
        exportPaymentsToExcel($all_payments_export);

    } catch (PDOException $e) {
        $error_message = "Error exporting payments: " . $e->getMessage();
    }
}

// =====================================================
// POST HANDLING
// =====================================================

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $error_message = "CSRF token validation failed. Please try again.";
    } else {
        // ============ BULK PAYMENT ============
        if (isset($_POST['bulk_payment']) && isset($_POST['process_bulk_payment'])) {
            $payment_date = sanitizeInput($_POST['bulk_payment_date'] ?? '');
            $payment_mode = (int)($_POST['bulk_payment_mode'] ?? 0);
            $bank_account_id = (int)($_POST['bulk_ac_credit'] ?? 0);
            $currency = sanitizeInput($_POST['bulk_currency'] ?? 'Tsh');
            $record_in_financial = sanitizeInput($_POST['bulk_record_in_financial'] ?? 'yes');
            $narration = sanitizeInput($_POST['bulk_narration'] ?? '');
            $total_amount = (float)($_POST['bulk_total_amount'] ?? 0);
            
            $payments = [];
            $paid_to_values = $_POST['bulk_paid_to'] ?? [];
            $name_values = $_POST['bulk_name'] ?? [];
            $account_no_values = $_POST['bulk_account_no'] ?? [];
            $trade_ref_values = $_POST['bulk_trade_ref'] ?? [];
            $amount_values = $_POST['bulk_amount'] ?? [];
            $narration_values = $_POST['bulk_item_narration'] ?? [];
            $entity_type_values = $_POST['bulk_entity_type'] ?? [];
            $entity_id_values = $_POST['bulk_entity_id'] ?? [];
            
            for ($i = 0; $i < count($paid_to_values); $i++) {
                $amount = floatval($amount_values[$i] ?? 0);
                if ($amount > 0 && !empty($paid_to_values[$i]) && !empty($name_values[$i])) {
                    $payments[] = [
                        'paid_to' => $paid_to_values[$i],
                        'name' => $name_values[$i],
                        'account_no' => $account_no_values[$i] ?? '',
                        'trade_reference' => $trade_ref_values[$i] ?? null,
                        'amount' => $amount,
                        'narration' => $narration_values[$i] ?? $narration,
                        'entity_type' => $entity_type_values[$i] ?? '',
                        'entity_id' => $entity_id_values[$i] ?? '',
                        'payment_type' => !empty($trade_ref_values[$i]) ? 'trade' : 'general'
                    ];
                }
            }
            
            $items_total = array_sum(array_column($payments, 'amount'));
            
            if (empty($payments)) {
                $error_message = "No valid payment items found. Please add at least one payee with amount.";
            } elseif (abs($items_total - $total_amount) > 0.01) {
                $error_message = "Total amount (" . number_format($total_amount, 2) . ") does not match sum of items (" . number_format($items_total, 2) . "). Please adjust.";
            } else {
                try {
                    $result = processBulkPayment($db, [
                        'payment_date' => $payment_date,
                        'payment_mode' => $payment_mode,
                        'bank_account_id' => $bank_account_id,
                        'currency' => $currency,
                        'record_in_financial' => $record_in_financial,
                        'narration' => $narration,
                        'total_amount' => $total_amount,
                        'payments' => $payments
                    ]);
                    
                    if ($result['success']) {
                        $success_message = "✅ Bulk payment processed successfully!<br>";
                        $success_message .= "Bulk Payment No: <strong>{$result['bulk_payment_no']}</strong><br>";
                        $success_message .= "Total Amount: <strong>" . number_format($result['total_amount'], 2) . " Tsh</strong><br>";
                        $success_message .= "Payments: <strong>{$result['payment_count']}</strong> distributions<br>";
                        $success_message .= "Payment References: " . implode(', ', $result['payment_references']);
                        
                        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                    }
                } catch (Exception $e) {
                    $error_message = "Bulk payment failed: " . $e->getMessage();
                }
            }
        }
        
        // ============ SINGLE PAYMENT ============
        else if (isset($_POST['generate_payment']) || isset($_POST['update_payment'])) {
            $current_user = $_SESSION['username'] ?? 'system';
            
            $payment_date = sanitizeInput($_POST['payment_date'] ?? '');
            $payment_mode = (int)($_POST['payment_mode'] ?? 0);
            $paid_to = sanitizeInput($_POST['paid_to'] ?? '');
            $payment_type = sanitizeInput($_POST['payment_type'] ?? 'general');
            
            if (isset($_POST['name_select']) && !empty($_POST['name_select'])) {
                $name = sanitizeInput($_POST['name_select']);
            } else {
                $name = sanitizeInput($_POST['name'] ?? '');
            }
            
            $record_in_financial = sanitizeInput($_POST['record_in_financial'] ?? 'yes');
            $ac_credit = (int)($_POST['ac_credit'] ?? 0);
            $currency = sanitizeInput($_POST['currency'] ?? 'Tsh');
            $account_no = sanitizeInput($_POST['account_no'] ?? '');
            $amount = (float)($_POST['amount'] ?? 0);
            $cheque_no = sanitizeInput($_POST['cheque_no'] ?? '');
            $narration = sanitizeInput($_POST['narration'] ?? '');
            $payment_id = (int)($_POST['payment_id'] ?? 0);
            $trade_reference = sanitizeInput($_POST['trade_reference'] ?? '');
            $entity_type = sanitizeInput($_POST['entity_type'] ?? '');
            $entity_id = sanitizeInput($_POST['entity_id'] ?? '');
            
            if (empty($payment_date) || !validateDate($payment_date)) {
                $error_message = "Invalid payment date.";
            } elseif ($payment_mode <= 0) {
                $error_message = "Please select a valid payment mode.";
            } elseif (empty($paid_to)) {
                $error_message = "Please select payee type.";
            } elseif (empty($name)) {
                $error_message = "Please enter payee name.";
            } elseif (!validateAmount($amount)) {
                $error_message = "Invalid amount. Amount must be greater than 0.";
            } elseif ($ac_credit <= 0) {
                $error_message = "Please select a valid bank account.";
            } elseif (!validateCurrency($currency)) {
                $error_message = "Invalid currency selected.";
            } else {
                try {
                    $bank_stmt = $db->prepare("SELECT id, bank_name, account_number, code, current_balance FROM banks_accounts WHERE id = ?");
                    $bank_stmt->execute([$ac_credit]);
                    $bank_account = $bank_stmt->fetch();
                    
                    if (!$bank_account) {
                        $error_message = "Selected bank account not found.";
                    } else {
                        $bank_name = $bank_account['bank_name'];
                        $bank_account_number = $bank_account['account_number'];
                        $bank_code = $bank_account['code'];
                        
                        $payment_no = generatePaymentNo($db, $payment_date);
                        $is_update = isset($_POST['update_payment']) && $payment_id > 0;
                        
                        if ($is_update) {
                            $update_stmt = $db->prepare("
                                UPDATE payments SET
                                    payment_date = ?, payment_mode = ?, paid_to = ?,
                                    name = ?, name_id = ?, source_type = ?,
                                    record_in_financial = ?, ac_credit = ?,
                                    currency = ?, account_no = ?, amount = ?,
                                    cheque_no = ?, narration = ?,
                                    trade_reference = ?, payment_type = ?,
                                    bank_name = ?, bank_account_number = ?,
                                    updated_at = NOW()
                                WHERE id = ?
                            ");
                            
                            $update_stmt->execute([
                                $payment_date, $payment_mode, $paid_to,
                                $name, $entity_id, $entity_type,
                                $record_in_financial, $ac_credit,
                                $currency, $account_no, $amount,
                                $cheque_no, $narration,
                                $trade_reference, $payment_type,
                                $bank_name, $bank_account_number,
                                $payment_id
                            ]);
                            
                            $success_message = "Payment updated successfully! Payment No: " . ($_POST['payment_no'] ?? '');
                            
                        } else {
                            $insert_stmt = $db->prepare("
                                INSERT INTO payments (
                                    payment_no, payment_date, payment_mode, paid_to,
                                    name, name_id, source_type, record_in_financial, ac_credit,
                                    currency, account_no, amount, cheque_no, narration,
                                    trade_reference, payment_type,
                                    created_by_username, created_at, status,
                                    bank_name, bank_account_number
                                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), 'active', ?, ?)
                            ");
                            
                            $insert_stmt->execute([
                                $payment_no, $payment_date, $payment_mode, $paid_to,
                                $name, $entity_id, $entity_type,
                                $record_in_financial, $ac_credit,
                                $currency, $account_no, $amount,
                                $cheque_no, $narration,
                                $trade_reference, $payment_type,
                                $current_user,
                                $bank_name, $bank_account_number
                            ]);
                            
                            if (!empty($trade_reference)) {
                                updateTradeSettlementStatus($db, $trade_reference, 'settled', "Payment No: $payment_no - $narration");
                            }
                            
                            if ($record_in_financial == 'yes') {
                                try {
                                    $debit_account_code = getControlAccount($db, $paid_to);

                                    // For Chart Account payments, use the selected account directly.
                                    if ($paid_to === 'O' && !empty($entity_id)) {
                                        $selected_account_code = getValidAccountCode($db, $entity_id, null);
                                        if ($selected_account_code) {
                                            $debit_account_code = $selected_account_code;
                                        } else {
                                            error_log("Selected chart account '$entity_id' not found; using control account '$debit_account_code'");
                                        }
                                    }

                                    createJournalEntry($db, [
                                        'transaction_date' => $payment_date,
                                        'reference_no' => $payment_no,
                                        'reference_type' => 'payment',
                                        'description' => "Payment to $name: $narration" . (!empty($trade_reference) ? " (Trade: $trade_reference)" : ""),
                                        'account_code' => $debit_account_code,
                                        'debit_amount' => $amount,
                                        'credit_amount' => 0,
                                        'currency' => $currency,
                                        'entity_id' => $entity_id,
                                        'entity_name' => $name,
                                        'entity_type' => $entity_type,
                                        'bank_account_id' => $ac_credit,
                                        'bank_name' => $bank_name,
                                        'bank_account_number' => $bank_account_number
                                    ]);
                                    
                                    createJournalEntry($db, [
                                        'transaction_date' => $payment_date,
                                        'reference_no' => $payment_no,
                                        'reference_type' => 'payment',
                                        'description' => "Payment to $name: $narration" . (!empty($trade_reference) ? " (Trade: $trade_reference)" : ""),
                                        'account_code' => $bank_code,
                                        'debit_amount' => 0,
                                        'credit_amount' => $amount,
                                        'currency' => $currency,
                                        'entity_id' => $entity_id,
                                        'entity_name' => $name,
                                        'entity_type' => $entity_type,
                                        'bank_account_id' => $ac_credit,
                                        'bank_name' => $bank_name,
                                        'bank_account_number' => $bank_account_number
                                    ]);
                                    
                                    updateBankBalance($db, $ac_credit, $amount);
                                    $success_message = "Payment created successfully with journal entries! Payment No: $payment_no";
                                    
                                } catch (Exception $e) {
                                    error_log("Journal entry error: " . $e->getMessage());
                                    $success_message = "Payment created! Payment No: $payment_no (Journal entries failed)";
                                }
                            } else {
                                $success_message = "Payment created successfully! Payment No: $payment_no";
                            }
                        }
                        
                        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                    }
                } catch (PDOException $e) {
                    $error_message = "Database error: " . $e->getMessage();
                    error_log("Payment error: " . $e->getMessage());
                }
            }
        }
    }
}

// =====================================================
// FILTERS AND PAYMENTS FETCH
// =====================================================

$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-d');
$paid_to_filter = $_GET['paid_to'] ?? '';
$payment_no_filter = $_GET['payment_no'] ?? '';
$financial_record_filter = $_GET['financial_record'] ?? '';
$search_name = $_GET['search_name'] ?? '';
$payment_type_filter = $_GET['payment_type'] ?? '';

if (!validateDate($start_date)) $start_date = date('Y-m-01');
if (!validateDate($end_date)) $end_date = date('Y-m-d');

if (strtotime($end_date) < strtotime($start_date)) {
    $end_date = $start_date;
}

$filter_conditions = [];
$filter_params = [];

if (!empty($start_date) && !empty($end_date)) {
    $filter_conditions[] = "p.payment_date BETWEEN ? AND ?";
    $filter_params[] = $start_date;
    $filter_params[] = $end_date;
}

if (!empty($paid_to_filter)) {
    $filter_conditions[] = "p.paid_to = ?";
    $filter_params[] = $paid_to_filter;
}

if (!empty($payment_no_filter)) {
    $filter_conditions[] = "p.payment_no LIKE ?";
    $filter_params[] = '%' . $payment_no_filter . '%';
}

if (!empty($financial_record_filter) && in_array($financial_record_filter, ['yes', 'no'])) {
    $filter_conditions[] = "p.record_in_financial = ?";
    $filter_params[] = $financial_record_filter;
}

if (!empty($payment_type_filter)) {
    $filter_conditions[] = "p.payment_type = ?";
    $filter_params[] = $payment_type_filter;
}

if (!empty($search_name)) {
    $filter_conditions[] = "(p.name LIKE ? OR p.account_no LIKE ? OR p.trade_reference LIKE ?)";
    $filter_params[] = '%' . $search_name . '%';
    $filter_params[] = '%' . $search_name . '%';
    $filter_params[] = '%' . $search_name . '%';
}

$where_clause = '';
if (!empty($filter_conditions)) {
    $where_clause = 'WHERE ' . implode(' AND ', $filter_conditions);
}

try {
    $payments_query = "
        SELECT p.*, 
               pm.description as payment_method_desc,
               lt.description as paid_to_desc,
               ba.bank_name,
               ba.account_number as bank_account_number,
               ba.account_name as bank_account_name,
               ba.current_balance as bank_current_balance,
               ba.code as bank_account_code
        FROM payments p
        LEFT JOIN payment_methods pm ON p.payment_mode = pm.id
        LEFT JOIN ledger_types lt ON p.paid_to = lt.code
        LEFT JOIN banks_accounts ba ON p.ac_credit = ba.id
        $where_clause
        ORDER BY p.payment_date DESC, p.created_at DESC
    ";
    
    $payments_stmt = $db->prepare($payments_query);
    $payments_stmt->execute($filter_params);
    $all_payments = $payments_stmt->fetchAll();
} catch (PDOException $e) {
    $all_payments = [];
    $error_message = "Error fetching payments: " . $e->getMessage();
}

$total_amount = 0;
$total_recorded = 0;
$total_trade_payments = 0;

foreach ($all_payments as $payment) {
    $total_amount += $payment['amount'] ?? 0;
    if (($payment['record_in_financial'] ?? 'no') == 'yes') {
        $total_recorded += $payment['amount'] ?? 0;
    }
    if (!empty($payment['trade_reference'])) {
        $total_trade_payments += $payment['amount'] ?? 0;
    }
}

$payment_methods = getPaymentMethods($db);
$ledger_types = getLedgerTypes($db);
$bank_accounts = getBankAccounts($db);

$page_title = 'Payment Processing - Money Out';
include '../includes/header.php';
?>

<style>
    .form-control-sm { height: calc(1.5em + 0.5rem + 2px); padding: 0.25rem 0.5rem; font-size: 0.875rem; }
    .clickable-row { cursor: pointer; transition: background-color 0.2s; }
    .clickable-row:hover { background-color: #f8f9fa; }
    .badge { padding: 0.35em 0.65em; font-size: 0.75em; }
    
    .payment-mode-toggle .btn { border-radius: 0; padding: 0.5rem 1.5rem; font-weight: 600; }
    .payment-mode-toggle .btn:first-child { border-radius: 0.375rem 0 0 0.375rem; }
    .payment-mode-toggle .btn:last-child { border-radius: 0 0.375rem 0.375rem 0; }
    .payment-mode-toggle .btn.active { background-color: #0d6efd; color: white; border-color: #0d6efd; }
    
    #bulkPaymentTable .form-control-sm,
    #bulkPaymentTable .form-select-sm {
        font-size: 0.8rem;
        padding: 0.2rem 0.3rem;
        height: auto;
        min-height: 28px;
    }
    #bulkPaymentTable td { vertical-align: middle; padding: 0.3rem; }
    .bulk-amount { font-weight: bold; color: #dc3545; }
    #bulkTotalDisplay { font-size: 1.1rem; font-weight: bold; }
    .remaining-balance { font-size: 1.2rem; font-weight: bold; padding: 0.5rem 1rem; border-radius: 0.375rem; background: #f8f9fa; border: 2px solid #dee2e6; }
    .remaining-balance.zero { background: #d4edda; border-color: #28a745; }
    .bulk-name-wrapper { display: flex; gap: 2px; align-items: center; }
    .bulk-name-wrapper .form-control-sm { flex: 1; min-width: 80px; }
    .bulk-name-wrapper .btn-sm { padding: 0.1rem 0.3rem; font-size: 0.7rem; }
    .selected-entity { background-color: #d4edda !important; }
    .entity-selected { color: green; font-weight: bold; }
</style>

<div class="container-fluid">
    <?php if (!empty($success_message)): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="bi bi-check-circle-fill me-2"></i>
            <?php echo $success_message; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if (!empty($error_message)): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="bi bi-exclamation-triangle-fill me-2"></i>
            <?php echo $error_message; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Payment Form -->
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-header bg-danger text-white border-bottom">
                    <h5 class="mb-0"><i class="bi bi-cash-coin me-2"></i>Payment Processing - Money Out</h5>
                </div>
                <div class="card-body">
                    <!-- Payment Mode Toggle -->
                    <div class="payment-mode-toggle mb-3">
                        <div class="btn-group" role="group">
                            <button type="button" class="btn btn-outline-primary active" id="singleModeBtn" onclick="togglePaymentMode('single')">
                                <i class="bi bi-person me-1"></i>Single Payment
                            </button>
                            <button type="button" class="btn btn-outline-primary" id="bulkModeBtn" onclick="togglePaymentMode('bulk')">
                                <i class="bi bi-people me-1"></i>Bulk Payment
                            </button>
                        </div>
                    </div>

                    <!-- ============ SINGLE PAYMENT ============ -->
                    <div id="singlePaymentForm">
                        <form method="POST" id="paymentForm">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                            <input type="hidden" name="payment_id" id="payment_id" value="">
                            <input type="hidden" name="entity_type" id="entity_type" value="">
                            <input type="hidden" name="entity_id" id="entity_id" value="">
                            <input type="hidden" name="payment_no" id="payment_no" value="">
                            
                            <div class="row g-2">
                                <div class="col-md-2">
                                    <label class="form-label">Payment Date <span class="text-danger">*</span></label>
                                    <input type="date" class="form-control form-control-sm" name="payment_date" id="payment_date" 
                                           value="<?php echo date('Y-m-d'); ?>" max="<?php echo date('Y-m-d'); ?>" required>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">Payment Type</label>
                                    <select class="form-select form-control-sm" name="payment_type" id="payment_type">
                                        <option value="general">General Payment</option>
                                        <option value="trade">Trade Settlement</option>
                                        <option value="commission">Commission Payment</option>
                                        <option value="regulatory">Regulatory Fee</option>
                                        <option value="salary">Salary/Staff</option>
                                        <option value="supplier">Supplier Payment</option>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">Payment Mode <span class="text-danger">*</span></label>
                                    <select class="form-select form-control-sm" name="payment_mode" id="payment_mode" required>
                                        <option value="">Select Mode</option>
                                        <?php foreach ($payment_methods as $method): ?>
                                            <option value="<?php echo (int)$method['id']; ?>">
                                                <?php echo htmlspecialchars($method['code'] . ' - ' . $method['description']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Pay To <span class="text-danger">*</span></label>
                                    <select class="form-select form-control-sm" name="paid_to" id="paid_to" required>
                                        <option value="">Select Payee Type</option>
                                        <?php foreach ($ledger_types as $type): ?>
                                            <option value="<?php echo htmlspecialchars($type['code']); ?>">
                                                <?php echo htmlspecialchars($type['description']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Payee Name <span class="text-danger">*</span></label>
                                    <div class="d-flex gap-1">
                                        <input type="text" class="form-control form-control-sm" name="name" id="name_input" 
                                               value="" placeholder="Enter payee name" required>
                                        <button type="button" class="btn btn-outline-secondary btn-sm" id="toggleNameMode">
                                            <i class="bi bi-list-ul"></i>
                                        </button>
                                        <button type="button" class="btn btn-outline-secondary btn-sm" id="openNamesModal" disabled>
                                            <i class="bi bi-search"></i>
                                        </button>
                                    </div>
                                    <select class="form-select form-control-sm d-none" name="name_select" id="name_select">
                                        <option value="">Select from list</option>
                                    </select>
                                    <small class="text-muted" id="source_indicator"></small>
                                </div>

                                <div class="col-md-2">
                                    <label class="form-label">Record in Financial?</label>
                                    <div>
                                        <div class="form-check form-check-inline">
                                            <input class="form-check-input" type="radio" name="record_in_financial" id="record_yes" value="yes" checked>
                                            <label class="form-check-label" for="record_yes">Yes</label>
                                        </div>
                                        <div class="form-check form-check-inline">
                                            <input class="form-check-input" type="radio" name="record_in_financial" id="record_no" value="no">
                                            <label class="form-check-label" for="record_no">No</label>
                                        </div>
                                    </div>
                                </div>

                                <div class="col-md-3">
                                    <label class="form-label">Withdraw From <span class="text-danger">*</span></label>
                                    <select class="form-select form-control-sm" name="ac_credit" id="ac_credit" required>
                                        <option value="">Select Bank Account</option>
                                        <?php foreach ($bank_accounts as $bank): ?>
                                            <option value="<?php echo (int)$bank['id']; ?>" 
                                                    data-currency="<?php echo htmlspecialchars($bank['currency']); ?>"
                                                    data-balance="<?php echo htmlspecialchars($bank['current_balance']); ?>">
                                                <?php echo htmlspecialchars($bank['bank_name'] . ' - ' . $bank['account_name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <small class="text-muted" id="bank_balance_indicator">Balance: Tsh 0.00</small>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">Currency</label>
                                    <select class="form-select form-control-sm" name="currency" id="currency">
                                        <option value="Tsh">TZS</option>
                                        <option value="Ksh">Ksh</option>
                                        <option value="USD">USD</option>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">Amount <span class="text-danger">*</span></label>
                                    <input type="number" class="form-control form-control-sm" name="amount" id="amount" 
                                           step="0.01" min="0.01" placeholder="0.00" required>
                                </div>

                                <div class="col-md-3" id="tradeRefSection" style="display: none;">
                                    <label class="form-label">Trade Reference</label>
                                    <input type="text" class="form-control form-control-sm" name="trade_reference" id="trade_reference" 
                                           placeholder="Enter trade reference">
                                </div>

                                <div class="col-md-2">
                                    <label class="form-label">Payee Account</label>
                                    <input type="text" class="form-control form-control-sm" name="account_no" id="account_no" 
                                           placeholder="Account number">
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">Cheque No</label>
                                    <input type="text" class="form-control form-control-sm" name="cheque_no" id="cheque_no" 
                                           placeholder="If cheque">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Description</label>
                                    <input type="text" class="form-control form-control-sm" name="narration" id="narration" 
                                           placeholder="Purpose of payment">
                                </div>

                                <div class="col-12 mt-2">
                                    <div class="d-flex justify-content-end gap-2">
                                        <button type="reset" class="btn btn-outline-secondary btn-sm" id="resetFormBtn">
                                            <i class="bi bi-arrow-clockwise me-1"></i>Reset
                                        </button>
                                        <button type="submit" name="generate_payment" class="btn btn-danger btn-sm" id="generateBtn">
                                            <i class="bi bi-cash-coin me-1"></i>Record Payment
                                        </button>
                                        <button type="submit" name="update_payment" class="btn btn-warning btn-sm d-none" id="updateBtn">
                                            <i class="bi bi-pencil me-1"></i>Update
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </form>
                    </div>

                    <!-- ============ BULK PAYMENT ============ -->
                    <div id="bulkPaymentForm" style="display: none;">
                        <form method="POST" id="bulkPaymentFormSubmit">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                            <input type="hidden" name="bulk_payment" value="1">
                            
                            <div class="row g-2">
                                <div class="col-md-2">
                                    <label class="form-label">Payment Date <span class="text-danger">*</span></label>
                                    <input type="date" class="form-control form-control-sm" name="bulk_payment_date" id="bulk_payment_date" 
                                           value="<?php echo date('Y-m-d'); ?>" max="<?php echo date('Y-m-d'); ?>" required>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">Payment Mode <span class="text-danger">*</span></label>
                                    <select class="form-select form-control-sm" name="bulk_payment_mode" id="bulk_payment_mode" required>
                                        <option value="">Select Mode</option>
                                        <?php foreach ($payment_methods as $method): ?>
                                            <option value="<?php echo (int)$method['id']; ?>">
                                                <?php echo htmlspecialchars($method['code'] . ' - ' . $method['description']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Bank Account <span class="text-danger">*</span></label>
                                    <select class="form-select form-control-sm" name="bulk_ac_credit" id="bulk_ac_credit" required>
                                        <option value="">Select Bank</option>
                                        <?php foreach ($bank_accounts as $bank): ?>
                                            <option value="<?php echo (int)$bank['id']; ?>" 
                                                    data-currency="<?php echo htmlspecialchars($bank['currency']); ?>"
                                                    data-balance="<?php echo htmlspecialchars($bank['current_balance']); ?>">
                                                <?php echo htmlspecialchars($bank['bank_name'] . ' - ' . $bank['account_name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <small class="text-muted" id="bulk_bank_balance">Balance: Tsh 0.00</small>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">Currency</label>
                                    <select class="form-select form-control-sm" name="bulk_currency" id="bulk_currency">
                                        <option value="Tsh">TZS</option>
                                        <option value="Ksh">Ksh</option>
                                        <option value="USD">USD</option>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">Record in Financial?</label>
                                    <select class="form-select form-control-sm" name="bulk_record_in_financial">
                                        <option value="yes">Yes</option>
                                        <option value="no">No</option>
                                    </select>
                                </div>
                                <div class="col-md-1">
                                    <label class="form-label">Total Amount <span class="text-danger">*</span></label>
                                    <input type="number" class="form-control form-control-sm" name="bulk_total_amount" id="bulk_total_amount" 
                                           step="0.01" min="0.01" placeholder="0.00" required oninput="updateRemainingBalance()">
                                </div>
                            </div>
                            
                            <!-- Remaining Balance -->
                            <div class="mt-3">
                                <div class="d-flex justify-content-between align-items-center">
                                    <span class="fw-semibold">Remaining Balance to Distribute:</span>
                                    <span class="remaining-balance" id="remainingBalanceDisplay">Tsh 0.00</span>
                                </div>
                                <small class="text-muted" id="balanceStatus">Enter total amount and add distributions below</small>
                            </div>
                            
                            <!-- Bulk Payment Items -->
                            <div class="mt-3">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <h6 class="mb-0"><i class="bi bi-list-ul me-1"></i>Payment Distributions</h6>
                                    <div>
                                        <span class="badge bg-info me-2" id="bulkItemCount">0 items</span>
                                        <span class="badge bg-success" id="bulkDistributedTotal">Tsh 0.00</span>
                                    </div>
                                </div>
                                
                                <div class="table-responsive">
                                    <table class="table table-sm table-bordered" id="bulkPaymentTable">
                                        <thead class="table-light">
                                            <tr>
                                                <th style="width:12%">Payee Type</th>
                                                <th style="width:22%">Payee Name</th>
                                                <th style="width:8%">Account No</th>
                                                <th style="width:12%">Trade Ref</th>
                                                <th style="width:12%">Amount</th>
                                                <th style="width:22%">Description</th>
                                                <th style="width:8%">Action</th>
                                            </tr>
                                        </thead>
                                        <tbody id="bulkPaymentItems">
                                            <tr id="emptyBulkRow">
                                                <td colspan="7" class="text-center text-muted py-3">
                                                    <i class="bi bi-plus-circle me-1"></i>
                                                    Click "Add Payee" or "Load Trades" to add distributions
                                                </td>
                                            </tr>
                                        </tbody>
                                        <tfoot>
                                            <tr>
                                                <td colspan="4" class="text-end fw-bold">TOTAL DISTRIBUTED:</td>
                                                <td class="fw-bold text-primary" id="bulkTotalDisplay">Tsh 0.00</td>
                                                <td colspan="2"></td>
                                            </tr>
                                        </tfoot>
                                    </table>
                                </div>
                                
                                <div class="d-flex gap-2">
                                    <button type="button" class="btn btn-outline-success btn-sm" id="addBulkItem">
                                        <i class="bi bi-plus-circle me-1"></i>Add Payee
                                    </button>
                                    <button type="button" class="btn btn-outline-danger btn-sm" id="clearBulkItems">
                                        <i class="bi bi-trash me-1"></i>Clear All
                                    </button>
                                    <button type="button" class="btn btn-outline-info btn-sm" id="loadTradeItems">
                                        <i class="bi bi-arrow-repeat me-1"></i>Load Trades
                                    </button>
                                </div>
                            </div>
                            
                            <div class="mt-3">
                                <label class="form-label">Bulk Description</label>
                                <input type="text" class="form-control form-control-sm" name="bulk_narration" 
                                       placeholder="Bulk payment description">
                            </div>
                            
                            <div class="mt-3">
                                <button type="submit" name="process_bulk_payment" class="btn btn-success" id="processBulkBtn" disabled>
                                    <i class="bi bi-cash-stack me-1"></i>Process Bulk Payment
                                </button>
                                <span class="text-muted ms-2" id="processBulkStatus">Distribute all amounts to enable processing</span>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Payments Table -->
    <div class="row mt-4">
        <div class="col-12">
            <div class="card">
                <div class="card-header bg-info text-white d-flex justify-content-between align-items-center py-2">
                    <h5 class="mb-0"><i class="bi bi-table me-2"></i>All Payments (<?php echo count($all_payments); ?>)</h5>
                    <div class="d-flex gap-1">
                        <a href="?export=excel&<?php echo http_build_query($_GET); ?>" 
                           class="btn btn-sm btn-light" 
                           onclick="return confirm('Export <?php echo count($all_payments); ?> payments to Excel?')">
                            <i class="bi bi-file-excel me-1"></i>Export Excel
                        </a>
                        <button class="btn btn-sm btn-light" type="button" data-bs-toggle="collapse" data-bs-target="#filtersCollapse">
                            <i class="bi bi-funnel me-1"></i>Filters
                        </button>
                        <button class="btn btn-sm btn-light" id="clearFiltersBtn">
                            <i class="bi bi-x-circle me-1"></i>Clear
                        </button>
                    </div>
                </div>
                <div class="card-body p-2">
                    <!-- Filters -->
                    <div class="collapse mb-3" id="filtersCollapse">
                        <div class="card card-body p-2">
                            <form method="GET" id="filtersForm">
                                <div class="row g-2">
                                    <div class="col-md-2">
                                        <label class="form-label">Start Date</label>
                                        <input type="date" class="form-control form-control-sm" name="start_date" value="<?php echo htmlspecialchars($start_date); ?>">
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">End Date</label>
                                        <input type="date" class="form-control form-control-sm" name="end_date" value="<?php echo htmlspecialchars($end_date); ?>">
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Payee Type</label>
                                        <select class="form-select form-control-sm" name="paid_to">
                                            <option value="">All Types</option>
                                            <?php foreach ($ledger_types as $type): ?>
                                                <option value="<?php echo htmlspecialchars($type['code']); ?>">
                                                    <?php echo htmlspecialchars($type['description']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Payment Type</label>
                                        <select class="form-select form-control-sm" name="payment_type">
                                            <option value="">All</option>
                                            <option value="general">General</option>
                                            <option value="trade">Trade</option>
                                            <option value="commission">Commission</option>
                                            <option value="regulatory">Regulatory</option>
                                            <option value="salary">Salary</option>
                                            <option value="supplier">Supplier</option>
                                        </select>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Search</label>
                                        <input type="text" class="form-control form-control-sm" name="search_name" value="<?php echo htmlspecialchars($search_name); ?>" placeholder="Name/Account/Trade">
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Financial Record</label>
                                        <select class="form-select form-control-sm" name="financial_record">
                                            <option value="">All</option>
                                            <option value="yes">Recorded</option>
                                            <option value="no">Not Recorded</option>
                                        </select>
                                    </div>
                                    <div class="col-12">
                                        <div class="d-flex justify-content-end">
                                            <button type="submit" class="btn btn-primary btn-sm">
                                                <i class="bi bi-filter me-1"></i>Apply Filters
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>

                    <div class="table-responsive" style="max-height: 500px; overflow-y: auto;">
                        <table class="table table-sm table-striped table-hover mb-1" id="paymentsTable">
                            <thead>
                                <tr>
                                    <th>Payment No</th>
                                    <th>Date</th>
                                    <th>Type</th>
                                    <th>Payee</th>
                                    <th>Trade Ref</th>
                                    <th>Amount</th>
                                    <th>Bank</th>
                                    <th>Record</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($all_payments)): ?>
                                    <tr><td colspan="9" class="text-center py-3 text-muted">No payments found</td></tr>
                                <?php else: foreach ($all_payments as $payment): ?>
                                    <tr>
                                        <td><code class="fw-bold text-danger"><?php echo htmlspecialchars($payment['payment_no'] ?? ''); ?></code></td>
                                        <td><?php echo isset($payment['payment_date']) ? date('M d, Y', strtotime($payment['payment_date'])) : ''; ?></td>
                                        <td>
                                            <span class="badge bg-<?php echo ($payment['payment_type'] ?? 'general') === 'trade' ? 'primary' : (($payment['payment_type'] ?? 'general') === 'commission' ? 'warning' : (($payment['payment_type'] ?? 'general') === 'regulatory' ? 'info' : 'secondary')); ?>">
                                                <?php echo htmlspecialchars($payment['payment_type'] ?? 'general'); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php echo htmlspecialchars($payment['name'] ?? ''); ?>
                                            <br><small class="text-muted"><?php echo htmlspecialchars($payment['paid_to_desc'] ?? $payment['paid_to'] ?? ''); ?></small>
                                        </td>
                                        <td><?php echo htmlspecialchars($payment['trade_reference'] ?? '-'); ?></td>
                                        <td class="fw-bold text-danger"><?php echo number_format($payment['amount'] ?? 0, 2); ?></td>
                                        <td>
                                            <small><?php echo htmlspecialchars($payment['bank_name'] ?? ''); ?></small>
                                            <br><small class="text-muted"><?php echo number_format($payment['bank_current_balance'] ?? 0, 2); ?></small>
                                        </td>
                                        <td>
                                            <span class="badge <?php echo ($payment['record_in_financial'] ?? 'no') == 'yes' ? 'bg-success' : 'bg-secondary'; ?>">
                                                <?php echo ($payment['record_in_financial'] ?? 'no') == 'yes' ? 'Recorded' : 'Not Recorded'; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="btn-group btn-group-sm">
                                                <button class="btn btn-outline-primary view-payment" data-payment-id="<?php echo (int)($payment['id'] ?? 0); ?>">
                                                    <i class="bi bi-eye"></i>
                                                </button>
                                                <button class="btn btn-outline-warning edit-payment" data-payment-id="<?php echo (int)($payment['id'] ?? 0); ?>">
                                                    <i class="bi bi-pencil"></i>
                                                </button>
                                                <button class="btn btn-outline-info view-journal" data-payment-no="<?php echo htmlspecialchars($payment['payment_no'] ?? ''); ?>">
                                                    <i class="bi bi-journal-text"></i>
                                                </button>
                                                <button class="btn btn-outline-danger print-payment" data-payment-id="<?php echo (int)($payment['id'] ?? 0); ?>">
                                                    <i class="bi bi-file-pdf"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <div class="mt-2">
                        <small class="text-muted">
                            Total: <strong class="text-danger">Tsh <?php echo number_format($total_amount, 2); ?></strong>
                            | Recorded: <?php echo number_format($total_recorded, 2); ?>
                            | Trade Related: <?php echo number_format($total_trade_payments, 2); ?>
                        </small>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- View Payment Modal -->
<div class="modal fade" id="viewPaymentModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title"><i class="bi bi-receipt me-2"></i>Payment Details</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="paymentDetailsContent"></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- View Journal Modal -->
<div class="modal fade" id="viewJournalModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title"><i class="bi bi-journal-text me-2"></i>Journal Entries</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="journalDetailsContent"></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Entities Modal -->
<div class="modal fade" id="entitiesModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title"><i class="bi bi-search me-2"></i>Select Entity</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-2">
                <div class="mb-2">
                    <input type="text" class="form-control form-control-sm" id="entitiesFilter" placeholder="Search by name or code...">
                </div>
                <div class="table-responsive" style="max-height: 400px; overflow-y: auto;">
                    <table class="table table-sm table-hover" id="entitiesTable">
                        <thead class="sticky-top bg-light">
                            <tr><th>Code</th><th>Name</th><th>Type</th></tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer p-2">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
            </div>
        </div>
    </div>
</div>

<script>
// =====================================================
// JAVASCRIPT
// =====================================================

document.addEventListener('DOMContentLoaded', function() {
    // =============== PAYMENT MODE TOGGLE ===============
    window.togglePaymentMode = function(mode) {
        const singleForm = document.getElementById('singlePaymentForm');
        const bulkForm = document.getElementById('bulkPaymentForm');
        const singleBtn = document.getElementById('singleModeBtn');
        const bulkBtn = document.getElementById('bulkModeBtn');
        
        if (mode === 'single') {
            singleForm.style.display = 'block';
            bulkForm.style.display = 'none';
            singleBtn.classList.add('active');
            bulkBtn.classList.remove('active');
        } else {
            singleForm.style.display = 'none';
            bulkForm.style.display = 'block';
            bulkBtn.classList.add('active');
            singleBtn.classList.remove('active');
        }
    };

    // =============== SINGLE PAYMENT NAME MODE ===============
    const paidToSelect = document.getElementById('paid_to');
    const nameInput = document.getElementById('name_input');
    const nameSelect = document.getElementById('name_select');
    const toggleNameModeBtn = document.getElementById('toggleNameMode');
    const openNamesModalBtn = document.getElementById('openNamesModal');
    const sourceIndicator = document.getElementById('source_indicator');
    const entityTypeInput = document.getElementById('entity_type');
    const entityIdInput = document.getElementById('entity_id');
    
    let isNameSelectMode = false;

    toggleNameModeBtn.addEventListener('click', function() {
        isNameSelectMode = !isNameSelectMode;
        
        if (isNameSelectMode) {
            nameInput.classList.add('d-none');
            nameSelect.classList.remove('d-none');
            nameInput.removeAttribute('required');
            nameSelect.setAttribute('required', 'required');
            toggleNameModeBtn.innerHTML = '<i class="bi bi-keyboard"></i>';
            openNamesModalBtn.disabled = false;
            
            if (paidToSelect.value) {
                loadEntities(paidToSelect.value);
            } else {
                nameSelect.innerHTML = '<option value="">Select Payee Type First</option>';
                sourceIndicator.textContent = 'Please select payee type first';
            }
        } else {
            nameInput.classList.remove('d-none');
            nameSelect.classList.add('d-none');
            nameSelect.removeAttribute('required');
            nameInput.setAttribute('required', 'required');
            toggleNameModeBtn.innerHTML = '<i class="bi bi-list-ul"></i>';
            openNamesModalBtn.disabled = true;
            sourceIndicator.textContent = 'Enter payee name manually';
            entityTypeInput.value = '';
            entityIdInput.value = '';
        }
    });

    function loadEntities(ledgerType) {
        if (!ledgerType) {
            nameSelect.innerHTML = '<option value="">Select Payee Type First</option>';
            openNamesModalBtn.disabled = true;
            sourceIndicator.textContent = '';
            return;
        }

        nameSelect.innerHTML = '<option value="">Loading...</option>';
        openNamesModalBtn.disabled = true;
        sourceIndicator.textContent = 'Loading entities...';
        
        fetch(`?ajax=get_entities&ledger_type=${encodeURIComponent(ledgerType)}`)
            .then(response => response.json())
            .then(entities => {
                nameSelect.innerHTML = '<option value="">Select Entity</option>';
                if (entities.length === 0) {
                    nameSelect.innerHTML = '<option value="">No entities found</option>';
                    openNamesModalBtn.disabled = true;
                    sourceIndicator.textContent = 'No entities available';
                } else {
                    entities.forEach(entity => {
                        const option = document.createElement('option');
                        option.value = entity.name || entity.code;
                        option.textContent = entity.display_name || entity.name || entity.code;
                        option.dataset.entityType = entity.type || '';
                        option.dataset.entityId = entity.id || entity.code;
                        option.dataset.entityName = entity.name;
                        nameSelect.appendChild(option);
                    });
                    
                    openNamesModalBtn.disabled = false;
                    sourceIndicator.textContent = `${entities.length} entities available`;
                }
            })
            .catch(error => {
                console.error('Error loading entities:', error);
                nameSelect.innerHTML = '<option value="">Error loading entities</option>';
                openNamesModalBtn.disabled = true;
                sourceIndicator.textContent = 'Error loading entities';
            });
    }

    function updateEntityDetails() {
        const selectedOption = nameSelect.options[nameSelect.selectedIndex];
        if (!selectedOption || !selectedOption.value) {
            entityTypeInput.value = '';
            entityIdInput.value = '';
            return;
        }
        
        entityTypeInput.value = selectedOption.dataset.entityType || '';
        entityIdInput.value = selectedOption.dataset.entityId || '';
        nameInput.value = selectedOption.value;
        sourceIndicator.innerHTML = `✓ Selected: <span class="entity-selected">${selectedOption.dataset.entityName || selectedOption.value}</span>`;
    }

    paidToSelect.addEventListener('change', function() {
        if (isNameSelectMode) {
            loadEntities(this.value);
        }
        entityTypeInput.value = '';
        entityIdInput.value = '';
    });

    nameSelect.addEventListener('change', updateEntityDetails);

    // =============== FETCH ENTITIES FOR MODAL (shared) ===============
    function fetchEntitiesForModal(ledgerType, callback) {
        // Show loading state
        const tbody = document.querySelector('#entitiesTable tbody');
        tbody.innerHTML = '<tr><td colspan="3" class="text-center text-muted">Loading entities...</td></tr>';
        
        fetch(`?ajax=get_entities&ledger_type=${encodeURIComponent(ledgerType)}`)
            .then(response => response.json())
            .then(entities => {
                tbody.innerHTML = '';
                
                if (entities.length === 0) {
                    tbody.innerHTML = '<tr><td colspan="3" class="text-center text-muted">No entities found</td></tr>';
                    return;
                }
                
                entities.forEach(entity => {
                    const row = document.createElement('tr');
                    row.className = 'clickable-row';
                    row.style.cursor = 'pointer';
                    row.innerHTML = `
                        <td><strong>${entity.code || ''}</strong></td>
                        <td>${entity.display_name || entity.name || ''}</td>
                        <td><span class="badge bg-secondary">${entity.type || ''}</span></td>
                    `;
                    row.addEventListener('click', function() {
                        // Store the entity data
                        const selectedEntity = entity;
                        
                        // Close modal using Bootstrap's hide method
                        const modalElement = document.getElementById('entitiesModal');
                        const modal = bootstrap.Modal.getInstance(modalElement);
                        if (modal) {
                            modal.hide();
                        } else {
                            // Fallback: try to close using jQuery or manual
                            const closeBtn = modalElement.querySelector('.btn-close');
                            if (closeBtn) {
                                closeBtn.click();
                            }
                        }
                        
                        // Call the callback with the selected entity after modal closes
                        setTimeout(function() {
                            if (callback) {
                                callback(selectedEntity);
                            }
                        }, 150);
                    });
                    tbody.appendChild(row);
                });
                
                // Clear filter input
                document.getElementById('entitiesFilter').value = '';
                
                // Show the modal
                const modal = new bootstrap.Modal(document.getElementById('entitiesModal'), {
                    backdrop: 'static',
                    keyboard: true
                });
                modal.show();
            })
            .catch(error => {
                console.error('Error loading entities:', error);
                tbody.innerHTML = '<tr><td colspan="3" class="text-center text-danger">Error loading entities</td></tr>';
                alert('Error loading entities. Please try again.');
            });
    }

    // =============== OPEN SINGLE ENTITY MODAL ===============
    openNamesModalBtn.addEventListener('click', function() {
        const ledgerType = paidToSelect.value;
        if (!ledgerType) {
            alert('Please select a payee type first');
            return;
        }
        
        fetchEntitiesForModal(ledgerType, function(entity) {
            // Update single payment fields
            if (isNameSelectMode) {
                // If in select mode, update the select
                nameSelect.value = entity.name || entity.code;
                updateEntityDetails();
            } else {
                // If in manual mode, update the input
                nameInput.value = entity.name || entity.code;
                entityTypeInput.value = entity.type || '';
                entityIdInput.value = entity.id || entity.code;
                sourceIndicator.innerHTML = `✓ Selected: <span class="entity-selected">${entity.name || entity.code}</span>`;
            }
            
            // Highlight the name input briefly
            nameInput.style.backgroundColor = '#d4edda';
            setTimeout(() => {
                nameInput.style.backgroundColor = '';
            }, 1500);
        });
    });

    // =============== BULK PAYMENT NAME BROWSE ===============
    // Store the current row ID being browsed
    let currentBulkRowId = null;

    // Function to open entity modal for bulk rows
    window.openBulkEntityModal = function(rowId) {
        const row = document.getElementById(`bulkRow_${rowId}`);
        if (!row) {
            console.error('Row not found:', rowId);
            return;
        }
        
        const paidToSelect = row.querySelector('.bulk-paid-to');
        const paidToValue = paidToSelect ? paidToSelect.value : '';
        
        if (!paidToValue) {
            alert('Please select a payee type first');
            return;
        }
        
        // Store current row ID for callback
        currentBulkRowId = rowId;
        
        fetchEntitiesForModal(paidToValue, function(entity) {
            // Update the specific row with selected entity
            const targetRow = document.getElementById(`bulkRow_${currentBulkRowId}`);
            if (targetRow) {
                const nameInput = targetRow.querySelector('.bulk-name-input');
                const entityTypeInput = targetRow.querySelector('.bulk-entity-type');
                const entityIdInput = targetRow.querySelector('.bulk-entity-id');
                const statusSpan = targetRow.querySelector('.bulk-name-status');
                const amountInput = targetRow.querySelector('.bulk-amount');
                const accountNoInput = targetRow.querySelector('.bulk-account-no');
                
                if (nameInput) {
                    nameInput.value = entity.name || entity.code;
                    // Trigger input event to update any bindings
                    nameInput.dispatchEvent(new Event('input', { bubbles: true }));
                }
                if (entityTypeInput) {
                    entityTypeInput.value = entity.type || '';
                }
                if (entityIdInput) {
                    entityIdInput.value = entity.id || entity.code;
                }
                if (statusSpan) {
                    statusSpan.innerHTML = `✓ Selected: <span class="entity-selected">${entity.name || entity.code}</span>`;
                }
                
                // If this is a client with CDS account, auto-fill account number
                if (entity.type === 'client' && entity.cds_account) {
                    if (accountNoInput && !accountNoInput.value) {
                        accountNoInput.value = entity.cds_account;
                    }
                }
                
                // Visual feedback - highlight the row briefly
                targetRow.classList.add('selected-entity');
                setTimeout(() => {
                    targetRow.classList.remove('selected-entity');
                }, 1500);
                
                // Auto-focus amount field for quick entry
                if (amountInput && !amountInput.value) {
                    setTimeout(() => {
                        amountInput.focus();
                    }, 300);
                }
            } else {
                console.error('Target row not found:', currentBulkRowId);
            }
            currentBulkRowId = null;
        });
    }

    // =============== BULK PAYMENT ITEM FUNCTIONS ===============
    let bulkItemCounter = 0;
    const ledgerTypes = <?php echo json_encode($ledger_types); ?>;
    let totalAmount = 0;
    let distributedAmount = 0;

    window.updateRemainingBalance = function() {
        const totalInput = document.getElementById('bulk_total_amount');
        totalAmount = parseFloat(totalInput.value) || 0;
        
        distributedAmount = 0;
        document.querySelectorAll('.bulk-amount').forEach(input => {
            const val = parseFloat(input.value);
            if (!isNaN(val) && val > 0) {
                distributedAmount += val;
            }
        });
        
        const remaining = totalAmount - distributedAmount;
        const display = document.getElementById('remainingBalanceDisplay');
        const status = document.getElementById('balanceStatus');
        const processBtn = document.getElementById('processBulkBtn');
        
        display.textContent = `Tsh ${remaining.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}`;
        display.className = 'remaining-balance';
        
        if (totalAmount > 0) {
            if (remaining === 0) {
                display.classList.add('zero');
                status.innerHTML = '✅ Balance is zero! Ready to process bulk payment.';
                processBtn.disabled = false;
                document.getElementById('processBulkStatus').textContent = 'Ready to process!';
            } else if (remaining > 0) {
                status.innerHTML = `⚠️ Remaining: Tsh ${remaining.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})} to distribute`;
                processBtn.disabled = true;
                document.getElementById('processBulkStatus').textContent = 'Distribute all amounts to enable processing';
            } else {
                status.innerHTML = '❌ Over-distributed! Reduce some amounts.';
                processBtn.disabled = true;
                document.getElementById('processBulkStatus').textContent = 'Amount exceeds total!';
            }
        } else {
            status.innerHTML = 'Enter total amount and add distributions below';
            processBtn.disabled = true;
            document.getElementById('processBulkStatus').textContent = 'Enter total amount first';
        }
        
        document.getElementById('bulkTotalDisplay').textContent = 
            `Tsh ${distributedAmount.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}`;
        document.getElementById('bulkDistributedTotal').textContent = 
            `Tsh ${distributedAmount.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}`;
    }

    window.removeBulkItem = function(rowId) {
        const row = document.getElementById(`bulkRow_${rowId}`);
        if (row) {
            row.remove();
            const tbody = document.getElementById('bulkPaymentItems');
            if (tbody.children.length === 0) {
                const emptyRow = document.getElementById('emptyBulkRow');
                if (emptyRow) emptyRow.style.display = '';
            }
            updateBulkItemCount();
            updateRemainingBalance();
        }
    }

    function updateBulkItemCount() {
        const count = document.querySelectorAll('#bulkPaymentItems tr:not(#emptyBulkRow)').length;
        document.getElementById('bulkItemCount').textContent = `${count} items`;
    }

    // =============== ADD BULK ITEM ===============
    document.getElementById('addBulkItem').addEventListener('click', function() {
        const rowId = ++bulkItemCounter;
        const tbody = document.getElementById('bulkPaymentItems');
        
        const emptyRow = document.getElementById('emptyBulkRow');
        if (emptyRow) emptyRow.style.display = 'none';
        
        let payeeOptions = '<option value="">Select Payee</option>';
        ledgerTypes.forEach(type => {
            payeeOptions += `<option value="${type.code}">${type.description}</option>`;
        });
        
        const row = document.createElement('tr');
        row.id = `bulkRow_${rowId}`;
        row.innerHTML = `
            <td>
                <select class="form-select form-select-sm bulk-paid-to" data-row-id="${rowId}" onchange="onBulkPayeeTypeChange(${rowId})">
                    ${payeeOptions}
                </select>
            </td>
            <td>
                <div class="bulk-name-wrapper">
                    <input type="text" class="form-control form-control-sm bulk-name-input" 
                           placeholder="Enter payee name" required>
                    <input type="hidden" class="bulk-entity-type" value="">
                    <input type="hidden" class="bulk-entity-id" value="">
                    <button type="button" class="btn btn-outline-secondary btn-sm bulk-browse-btn" 
                            onclick="openBulkEntityModal(${rowId})" disabled>
                        <i class="bi bi-search"></i>
                    </button>
                </div>
                <small class="text-muted bulk-name-status"></small>
            </td>
            <td>
                <input type="text" class="form-control form-control-sm bulk-account-no" 
                       placeholder="Account no">
            </td>
            <td>
                <input type="text" class="form-control form-control-sm bulk-trade-ref" 
                       placeholder="Trade ref">
            </td>
            <td>
                <input type="number" class="form-control form-control-sm bulk-amount" 
                       step="0.01" min="0.01" placeholder="0.00" oninput="updateRemainingBalance()" required>
            </td>
            <td>
                <input type="text" class="form-control form-control-sm bulk-narration" 
                       placeholder="Description">
            </td>
            <td>
                <button type="button" class="btn btn-outline-danger btn-sm" onclick="removeBulkItem(${rowId})">
                    <i class="bi bi-x-lg"></i>
                </button>
            </td>
        `;
        
        tbody.appendChild(row);
        updateBulkItemCount();
        updateRemainingBalance();
    });

    // Handle payee type change for bulk rows
    window.onBulkPayeeTypeChange = function(rowId) {
        const row = document.getElementById(`bulkRow_${rowId}`);
        if (!row) return;
        
        const paidToSelect = row.querySelector('.bulk-paid-to');
        const browseBtn = row.querySelector('.bulk-browse-btn');
        const statusSpan = row.querySelector('.bulk-name-status');
        const entityTypeInput = row.querySelector('.bulk-entity-type');
        const entityIdInput = row.querySelector('.bulk-entity-id');
        
        if (paidToSelect.value) {
            browseBtn.disabled = false;
            statusSpan.textContent = 'Click search to browse entities';
            statusSpan.style.color = '#6c757d';
        } else {
            browseBtn.disabled = true;
            statusSpan.textContent = '';
        }
        
        // Clear entity data when payee type changes
        entityTypeInput.value = '';
        entityIdInput.value = '';
    }

    // =============== CLEAR BULK ITEMS ===============
    document.getElementById('clearBulkItems').addEventListener('click', function() {
        if (!confirm('Clear all bulk payment items?')) return;
        
        document.getElementById('bulkPaymentItems').innerHTML = `
            <tr id="emptyBulkRow">
                <td colspan="7" class="text-center text-muted py-3">
                    <i class="bi bi-plus-circle me-1"></i>
                    Click "Add Payee" or "Load Trades" to add distributions
                </td>
            </tr>
        `;
        bulkItemCounter = 0;
        updateBulkItemCount();
        updateRemainingBalance();
    });

    // =============== LOAD TRADE ITEMS ===============
    document.getElementById('loadTradeItems').addEventListener('click', function() {
        const clientCds = prompt('Enter Client CDS Account to load unsettled trades:');
        if (!clientCds) return;
        
        fetch(`?ajax=get_client_trades&client_cds=${encodeURIComponent(clientCds)}`)
            .then(response => response.json())
            .then(data => {
                if (!data.trades || data.trades.length === 0) {
                    alert('No unsettled trades found for this client');
                    return;
                }
                
                const tbody = document.getElementById('bulkPaymentItems');
                const emptyRow = document.getElementById('emptyBulkRow');
                if (emptyRow) emptyRow.style.display = 'none';
                
                data.trades.forEach(trade => {
                    const rowId = ++bulkItemCounter;
                    const row = document.createElement('tr');
                    row.id = `bulkRow_${rowId}`;
                    row.innerHTML = `
                        <td>
                            <select class="form-select form-select-sm bulk-paid-to" data-row-id="${rowId}" disabled>
                                <option value="C" selected>Customers</option>
                            </select>
                        </td>
                        <td>
                            <div class="bulk-name-wrapper">
                                <input type="text" class="form-control form-control-sm bulk-name-input" 
                                       value="${trade.client_name || 'Client'}" readonly>
                                <input type="hidden" class="bulk-entity-type" value="client">
                                <button type="button" class="btn btn-outline-secondary btn-sm bulk-browse-btn" disabled>
                                    <i class="bi bi-search"></i>
                                </button>
                            </div>
                            <small class="text-muted bulk-name-status">✓ Loaded from trade</small>
                        </td>
                        <td>
                            <input type="text" class="form-control form-control-sm bulk-account-no" 
                                   value="${clientCds}" readonly>
                        </td>
                        <td>
                            <input type="text" class="form-control form-control-sm bulk-trade-ref" 
                                   value="${trade.trade_reference}" readonly>
                        </td>
                        <td>
                            <input type="number" class="form-control form-control-sm bulk-amount" 
                                   value="${trade.consideration}" step="0.01" min="0.01" 
                                   oninput="updateRemainingBalance()" readonly>
                        </td>
                        <td>
                            <input type="text" class="form-control form-control-sm bulk-narration" 
                                   value="Trade settlement - ${trade.security_id}">
                        </td>
                        <td>
                            <button type="button" class="btn btn-outline-danger btn-sm" onclick="removeBulkItem(${rowId})">
                                <i class="bi bi-x-lg"></i>
                            </button>
                        </td>
                    `;
                    tbody.appendChild(row);
                });
                
                updateBulkItemCount();
                updateRemainingBalance();
                alert(`Loaded ${data.trades.length} trades for client ${clientCds}`);
            })
            .catch(error => {
                console.error('Error loading trades:', error);
                alert('Error loading trades');
            });
    });

    // =============== SINGLE PAYMENT ===============
    document.getElementById('payment_type').addEventListener('change', function() {
        document.getElementById('tradeRefSection').style.display = this.value === 'trade' ? 'block' : 'none';
    });

    document.getElementById('ac_credit').addEventListener('change', function() {
        const opt = this.options[this.selectedIndex];
        if (opt && opt.value) {
            const balance = parseFloat(opt.dataset.balance || 0);
            document.getElementById('bank_balance_indicator').textContent = 
                `Balance: ${opt.dataset.currency || 'Tsh'} ${balance.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}`;
            document.getElementById('currency').value = opt.dataset.currency || 'Tsh';
        }
    });

    document.getElementById('resetFormBtn').addEventListener('click', function() {
        if (confirm('Reset form? All data will be lost.')) {
            document.getElementById('paymentForm').reset();
            document.getElementById('payment_id').value = '';
            document.getElementById('payment_no').value = '';
            document.getElementById('generateBtn').classList.remove('d-none');
            document.getElementById('updateBtn').classList.add('d-none');
            document.getElementById('tradeRefSection').style.display = 'none';
            document.getElementById('payment_date').value = new Date().toISOString().split('T')[0];
            document.getElementById('bank_balance_indicator').textContent = 'Balance: Tsh 0.00';
            
            // Reset name mode
            isNameSelectMode = false;
            nameInput.classList.remove('d-none');
            nameSelect.classList.add('d-none');
            nameSelect.removeAttribute('required');
            nameInput.setAttribute('required', 'required');
            toggleNameModeBtn.innerHTML = '<i class="bi bi-list-ul"></i>';
            openNamesModalBtn.disabled = true;
            sourceIndicator.textContent = '';
            entityTypeInput.value = '';
            entityIdInput.value = '';
        }
    });

    document.getElementById('bulk_ac_credit').addEventListener('change', function() {
        const opt = this.options[this.selectedIndex];
        if (opt && opt.value) {
            const balance = parseFloat(opt.dataset.balance || 0);
            document.getElementById('bulk_bank_balance').textContent = 
                `Balance: ${opt.dataset.currency || 'Tsh'} ${balance.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}`;
            document.getElementById('bulk_currency').value = opt.dataset.currency || 'Tsh';
        }
    });

    // =============== VIEW PAYMENT ===============
    document.querySelectorAll('.view-payment').forEach(btn => {
        btn.addEventListener('click', function() {
            const paymentId = this.dataset.paymentId;
            fetch(`?ajax=get_payment&payment_id=${paymentId}`)
                .then(response => response.json())
                .then(payment => {
                    if (payment.error) {
                        alert(payment.error);
                        return;
                    }
                    const content = document.getElementById('paymentDetailsContent');
                    content.innerHTML = `
                        <div class="row">
                            <div class="col-md-6">
                                <strong>Payment No:</strong> ${payment.payment_no || ''}<br>
                                <strong>Date:</strong> ${payment.payment_date || ''}<br>
                                <strong>Type:</strong> ${payment.payment_type || 'general'}<br>
                                <strong>Payee:</strong> ${payment.name || ''}<br>
                                <strong>Trade Ref:</strong> ${payment.trade_reference || 'N/A'}
                            </div>
                            <div class="col-md-6">
                                <strong>Amount:</strong> ${payment.currency || 'Tsh'} ${parseFloat(payment.amount || 0).toLocaleString()}<br>
                                <strong>Bank:</strong> ${payment.bank_name || 'N/A'}<br>
                                <strong>Record:</strong> ${payment.record_in_financial || 'no'}<br>
                                <strong>Description:</strong> ${payment.narration || 'N/A'}
                            </div>
                        </div>
                    `;
                    new bootstrap.Modal(document.getElementById('viewPaymentModal')).show();
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Error loading payment details');
                });
        });
    });

    // =============== EDIT PAYMENT ===============
    document.querySelectorAll('.edit-payment').forEach(btn => {
        btn.addEventListener('click', function() {
            const paymentId = this.dataset.paymentId;
            fetch(`?ajax=get_payment&payment_id=${paymentId}`)
                .then(response => response.json())
                .then(payment => {
                    if (payment.error) {
                        alert(payment.error);
                        return;
                    }
                    document.getElementById('payment_id').value = paymentId;
                    document.getElementById('payment_no').value = payment.payment_no || '';
                    document.getElementById('payment_date').value = payment.payment_date || '';
                    document.getElementById('payment_type').value = payment.payment_type || 'general';
                    document.getElementById('payment_mode').value = payment.payment_mode || '';
                    document.getElementById('paid_to').value = payment.paid_to || '';
                    document.getElementById('name_input').value = payment.name || '';
                    document.getElementById('ac_credit').value = payment.ac_credit || '';
                    document.getElementById('currency').value = payment.currency || 'Tsh';
                    document.getElementById('amount').value = payment.amount || '';
                    document.getElementById('account_no').value = payment.account_no || '';
                    document.getElementById('cheque_no').value = payment.cheque_no || '';
                    document.getElementById('narration').value = payment.narration || '';
                    document.getElementById('trade_reference').value = payment.trade_reference || '';
                    
                    if (payment.record_in_financial === 'yes') {
                        document.getElementById('record_yes').checked = true;
                    } else {
                        document.getElementById('record_no').checked = true;
                    }
                    
                    if (payment.payment_type === 'trade') {
                        document.getElementById('tradeRefSection').style.display = 'block';
                    }
                    
                    document.getElementById('generateBtn').classList.add('d-none');
                    document.getElementById('updateBtn').classList.remove('d-none');
                    
                    const bankSelect = document.getElementById('ac_credit');
                    if (payment.ac_credit) {
                        const option = bankSelect.querySelector(`option[value="${payment.ac_credit}"]`);
                        if (option) {
                            const balance = parseFloat(option.dataset.balance || 0);
                            document.getElementById('bank_balance_indicator').textContent = 
                                `Balance: ${option.dataset.currency || 'Tsh'} ${balance.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}`;
                        }
                    }
                    
                    document.getElementById('paymentFormContainer').scrollIntoView({ behavior: 'smooth' });
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Error loading payment for editing');
                });
        });
    });

    // =============== VIEW JOURNAL ===============
    document.querySelectorAll('.view-journal').forEach(btn => {
        btn.addEventListener('click', function() {
            const paymentNo = this.dataset.paymentNo;
            fetch(`?ajax=get_journal_entries&payment_no=${paymentNo}`)
                .then(response => response.json())
                .then(journals => {
                    const content = document.getElementById('journalDetailsContent');
                    if (journals.length === 0) {
                        content.innerHTML = '<div class="text-center py-5"><i class="bi bi-journal-x" style="font-size:3rem;"></i><h5>No Journal Entries</h5></div>';
                    } else {
                        let html = '<table class="table table-sm"><thead><tr><th>Journal No</th><th>Date</th><th>Account</th><th>Debit</th><th>Credit</th><th>Description</th></tr></thead><tbody>';
                        journals.forEach(j => {
                            html += `<tr>
                                <td>${j.journal_no || ''}</td>
                                <td>${j.transaction_date || ''}</td>
                                <td>${j.account_code || ''} - ${j.account_name || ''}</td>
                                <td class="text-danger">${parseFloat(j.debit_amount || 0).toLocaleString()}</td>
                                <td class="text-success">${parseFloat(j.credit_amount || 0).toLocaleString()}</td>
                                <td>${j.description || ''}</td>
                            </tr>`;
                        });
                        html += '</tbody></table>';
                        content.innerHTML = html;
                    }
                    new bootstrap.Modal(document.getElementById('viewJournalModal')).show();
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Error loading journal entries');
                });
        });
    });


    // =============== PRINT PAYMENT ===============
    document.querySelectorAll('.print-payment').forEach(btn => {
        btn.addEventListener('click', function() {
            const paymentId = this.dataset.paymentId;
            const printWindow = window.open(`print_payment.php?payment_id=${encodeURIComponent(paymentId)}`, '_blank');
            if (!printWindow) {
                alert('Please allow pop-ups to print payments.');
            }
        });
    });

    // =============== CLEAR FILTERS ===============
    document.getElementById('clearFiltersBtn').addEventListener('click', function() {
        window.location.href = window.location.pathname;
    });

    // =============== BULK PAYMENT SUBMIT ===============
    document.getElementById('bulkPaymentFormSubmit').addEventListener('submit', function(e) {
        const rows = document.querySelectorAll('#bulkPaymentItems tr:not(#emptyBulkRow)');
        if (rows.length === 0) {
            e.preventDefault();
            alert('Please add at least one payment distribution');
            return;
        }
        
        const totalInput = document.getElementById('bulk_total_amount');
        const totalAmount = parseFloat(totalInput.value) || 0;
        
        let distributedTotal = 0;
        let hasError = false;
        
        rows.forEach(row => {
            const amount = parseFloat(row.querySelector('.bulk-amount').value);
            const name = row.querySelector('.bulk-name-input').value.trim();
            const paidTo = row.querySelector('.bulk-paid-to').value;
            
            if (isNaN(amount) || amount <= 0) {
                hasError = true;
                row.style.backgroundColor = '#ffebee';
            } else {
                distributedTotal += amount;
                row.style.backgroundColor = '';
            }
            
            if (!name) {
                hasError = true;
                row.querySelector('.bulk-name-input').style.borderColor = 'red';
            } else {
                row.querySelector('.bulk-name-input').style.borderColor = '';
            }
            
            if (!paidTo) {
                hasError = true;
                row.querySelector('.bulk-paid-to').style.borderColor = 'red';
            } else {
                row.querySelector('.bulk-paid-to').style.borderColor = '';
            }
        });
        
        if (hasError) {
            e.preventDefault();
            alert('Please fix errors: ensure all rows have payee type, name, and valid amount');
            return;
        }
        
        if (Math.abs(distributedTotal - totalAmount) > 0.01) {
            e.preventDefault();
            alert(`Total amount (${totalAmount.toFixed(2)}) does not match sum of items (${distributedTotal.toFixed(2)}). Please adjust.`);
            return;
        }
        
        // Collect data for submission
        const paidToValues = [], nameValues = [], accountNoValues = [];
        const tradeRefValues = [], amountValues = [], narrationValues = [];
        const entityTypeValues = [], entityIdValues = [];
        
        rows.forEach(row => {
            paidToValues.push(row.querySelector('.bulk-paid-to').value);
            nameValues.push(row.querySelector('.bulk-name-input').value);
            accountNoValues.push(row.querySelector('.bulk-account-no').value || '');
            tradeRefValues.push(row.querySelector('.bulk-trade-ref').value || '');
            amountValues.push(row.querySelector('.bulk-amount').value);
            narrationValues.push(row.querySelector('.bulk-narration').value || '');
            entityTypeValues.push(row.querySelector('.bulk-entity-type').value || '');
            entityIdValues.push(row.querySelector('.bulk-entity-id').value || '');
        });
        
        const form = document.getElementById('bulkPaymentFormSubmit');
        form.querySelectorAll('.bulk-item-data').forEach(el => el.remove());
        
        paidToValues.forEach((val, i) => {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = `bulk_paid_to[]`;
            input.value = val;
            input.className = 'bulk-item-data';
            form.appendChild(input);
        });
        
        nameValues.forEach((val, i) => {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = `bulk_name[]`;
            input.value = val;
            input.className = 'bulk-item-data';
            form.appendChild(input);
        });
        
        accountNoValues.forEach((val, i) => {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = `bulk_account_no[]`;
            input.value = val;
            input.className = 'bulk-item-data';
            form.appendChild(input);
        });
        
        tradeRefValues.forEach((val, i) => {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = `bulk_trade_ref[]`;
            input.value = val;
            input.className = 'bulk-item-data';
            form.appendChild(input);
        });
        
        amountValues.forEach((val, i) => {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = `bulk_amount[]`;
            input.value = val;
            input.className = 'bulk-item-data';
            form.appendChild(input);
        });
        
        narrationValues.forEach((val, i) => {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = `bulk_item_narration[]`;
            input.value = val;
            input.className = 'bulk-item-data';
            form.appendChild(input);
        });
        
        entityTypeValues.forEach((val, i) => {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = `bulk_entity_type[]`;
            input.value = val;
            input.className = 'bulk-item-data';
            form.appendChild(input);
        });
        
        entityIdValues.forEach((val, i) => {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = `bulk_entity_id[]`;
            input.value = val;
            input.className = 'bulk-item-data';
            form.appendChild(input);
        });
        
        if (!confirm(`Process bulk payment of Tsh ${totalAmount.toLocaleString()} for ${rows.length} payees?`)) {
            e.preventDefault();
        }
    });

    // =============== ENTITIES FILTER ===============
    document.getElementById('entitiesFilter').addEventListener('keyup', function() {
        const filter = this.value.toLowerCase();
        const rows = document.querySelectorAll('#entitiesTable tbody tr');
        rows.forEach(row => {
            const text = row.textContent.toLowerCase();
            row.style.display = text.includes(filter) ? '' : 'none';
        });
    });

    // =============== INITIALIZATION ===============
    togglePaymentMode('single');
    
    const initialBank = document.getElementById('ac_credit');
    if (initialBank && initialBank.options[initialBank.selectedIndex]) {
        const opt = initialBank.options[initialBank.selectedIndex];
        const balance = parseFloat(opt.dataset.balance || 0);
        document.getElementById('bank_balance_indicator').textContent = 
            `Balance: ${opt.dataset.currency || 'Tsh'} ${balance.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}`;
    }
    
    const bulkBank = document.getElementById('bulk_ac_credit');
    if (bulkBank && bulkBank.options[bulkBank.selectedIndex]) {
        const opt = bulkBank.options[bulkBank.selectedIndex];
        const balance = parseFloat(opt.dataset.balance || 0);
        document.getElementById('bulk_bank_balance').textContent = 
            `Balance: ${opt.dataset.currency || 'Tsh'} ${balance.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}`;
    }
    
    updateBulkItemCount();
    updateRemainingBalance();
});
</script>

<?php include '../includes/footer.php'; ?>