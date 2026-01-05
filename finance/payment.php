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

function generatePaymentNo($db, $date = null) {
    $prefix = 'PMT';
    $year = date('Y', strtotime($date ?? 'now'));
    $month = date('m', strtotime($date ?? 'now'));
    $day = date('d', strtotime($date ?? 'now'));
    
    // Format: PMTYYYYMMDDXXXX
    $base_no = $prefix . $year . $month . $day;
    
    // Get last payment number for this date
    $stmt = $db->prepare("
        SELECT payment_no FROM payments 
        WHERE payment_no LIKE ? 
        ORDER BY payment_no DESC 
        LIMIT 1
    ");
    $stmt->execute([$base_no . '%']);
    $last = $stmt->fetch();
    
    if ($last) {
        $last_no = $last['payment_no'];
        $last_seq = intval(substr($last_no, -4));
        $new_seq = str_pad($last_seq + 1, 4, '0', STR_PAD_LEFT);
    } else {
        $new_seq = '0001';
    }
    
    return $base_no . $new_seq;
}

function generateJournalNo($db) {
    $prefix = 'JRNL';
    $year = date('Y');
    $month = date('m');
    
    // Get last journal number for this month
    $stmt = $db->prepare("
        SELECT journal_no FROM journal_entries 
        WHERE journal_no LIKE ? 
        ORDER BY id DESC LIMIT 1
    ");
    $stmt->execute(["$prefix$year$month%"]);
    $last = $stmt->fetch();
    
    if ($last) {
        $last_no = intval(substr($last['journal_no'], -4));
        $new_no = str_pad($last_no + 1, 4, '0', STR_PAD_LEFT);
    } else {
        $new_no = '0001';
    }
    
    return $prefix . $year . $month . $new_no;
}

function getChartAccountInfo($db, $account_code) {
    $stmt = $db->prepare("SELECT id, account_name, normal_balance FROM chart_of_accounts WHERE account_code = ?");
    $stmt->execute([$account_code]);
    return $stmt->fetch();
}

function getAppropriateAccountLevel($db, $account_type) {
    try {
        if ($account_type === 'expense') {
            // Look for appropriate expense accounts based on level
            $query = "
                SELECT account_code, account_name, level, is_group_account 
                FROM chart_of_accounts 
                WHERE account_type = 'expense' 
                AND is_active = 1 
                AND (is_group_account = 0 OR level = 4 OR level = 3 OR level = 2)
                ORDER BY level DESC, account_code
            ";
            
            $stmt = $db->prepare($query);
            $stmt->execute();
            $accounts = $stmt->fetchAll();
            
            // Return the first appropriate account (prefer level 5, then 4, then 3, then 2)
            foreach ($accounts as $account) {
                if ($account['level'] == 5 || (!$account['is_group_account'] && $account['level'] >= 3)) {
                    return $account;
                }
            }
            
            // Default to a level 2 expense account if nothing else found
            $default_query = "
                SELECT account_code, account_name 
                FROM chart_of_accounts 
                WHERE account_type = 'expense' 
                AND level = 2 
                AND is_active = 1 
                LIMIT 1
            ";
            $stmt = $db->prepare($default_query);
            $stmt->execute();
            return $stmt->fetch() ?: ['account_code' => '51', 'account_name' => 'Operating Expenses'];
        }
        
        // For other account types, return null
        return null;
        
    } catch (Exception $e) {
        error_log("Error getting appropriate account level: " . $e->getMessage());
        return null;
    }
}

function createJournalEntry($db, $data) {
    try {
        $journal_no = generateJournalNo($db);
        $fiscal_year = getFiscalYear($data['transaction_date']);
        $fiscal_period = getFiscalPeriod($data['transaction_date']);
        
        // Get account info from chart_of_accounts
        $account_info = getChartAccountInfo($db, $data['account_code']);
        
        if (!$account_info) {
            throw new Exception("Account code {$data['account_code']} not found in chart of accounts");
        }
        
        $account_id = $account_info['id'];
        $account_name = $account_info['account_name'];
        
        // Get current user info
        $current_user = $_SESSION['username'] ?? 'system';
        $user_id = $_SESSION['user_id'] ?? null;
        
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
            $data['reference_type'],
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

// Function to export payments to Excel
function exportPaymentsToExcel($payments) {
    // Set headers for Excel file download
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="payments_' . date('Y-m-d_H-i-s') . '.xls"');
    header('Pragma: no-cache');
    header('Expires: 0');
    
    // Excel BOM for UTF-8
    echo "\xEF\xBB\xBF";
    
    // Start Excel content
    echo '<table border="1">';
    
    // Table headers
    echo '<tr>';
    echo '<th>Payment No</th>';
    echo '<th>Date</th>';
    echo '<th>Payee Type</th>';
    echo '<th>Payee Name</th>';
    echo '<th>Payee ID</th>';
    echo '<th>Account No</th>';
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
    
    // Table data
    $total_amount = 0;
    foreach ($payments as $payment) {
        echo '<tr>';
        echo '<td>' . htmlspecialchars($payment['payment_no']) . '</td>';
        echo '<td>' . htmlspecialchars($payment['payment_date']) . '</td>';
        echo '<td>' . htmlspecialchars($payment['paid_to_desc'] ?? $payment['paid_to']) . '</td>';
        echo '<td>' . htmlspecialchars($payment['name']) . '</td>';
        echo '<td>' . htmlspecialchars($payment['name_id'] ?? '') . '</td>';
        echo '<td>' . htmlspecialchars($payment['account_no'] ?? '') . '</td>';
        echo '<td>' . number_format($payment['amount'], 2) . '</td>';
        echo '<td>' . htmlspecialchars($payment['currency']) . '</td>';
        echo '<td>' . htmlspecialchars($payment['bank_name'] ?? '') . '</td>';
        echo '<td>' . htmlspecialchars($payment['bank_account_number'] ?? '') . '</td>';
        echo '<td>' . htmlspecialchars($payment['payment_method_desc'] ?? '') . '</td>';
        echo '<td>' . htmlspecialchars($payment['cheque_no'] ?? '') . '</td>';
        echo '<td>' . htmlspecialchars($payment['narration'] ?? '') . '</td>';
        echo '<td>' . htmlspecialchars($payment['record_in_financial']) . '</td>';
        echo '<td>' . htmlspecialchars($payment['created_by_username'] ?? '') . '</td>';
        echo '<td>' . htmlspecialchars($payment['created_at']) . '</td>';
        echo '<td>' . htmlspecialchars($payment['status']) . '</td>';
        echo '</tr>';
        
        $total_amount += $payment['amount'];
    }
    
    // Total row
    echo '<tr>';
    echo '<td colspan="6" style="text-align: right; font-weight: bold;">TOTAL:</td>';
    echo '<td style="font-weight: bold;">' . number_format($total_amount, 2) . '</td>';
    echo '<td colspan="11"></td>';
    echo '</tr>';
    
    echo '</table>';
    exit;
}

// Handle Excel export
if (isset($_GET['export']) && $_GET['export'] == 'excel') {
    // Build the same query as for display
    $export_conditions = [];
    $export_params = [];
    
    // Get filter parameters
    $start_date = $_GET['start_date'] ?? '';
    $end_date = $_GET['end_date'] ?? '';
    $paid_to_filter = $_GET['paid_to'] ?? '';
    $payment_no_filter = $_GET['payment_no'] ?? '';
    $financial_record_filter = $_GET['financial_record'] ?? '';
    
    // Date range filter
    if (!empty($start_date) && !empty($end_date)) {
        $export_conditions[] = "p.payment_date BETWEEN ? AND ?";
        $export_params[] = $start_date;
        $export_params[] = $end_date;
    }
    
    // Pay To filter
    if (!empty($paid_to_filter)) {
        $export_conditions[] = "p.paid_to = ?";
        $export_params[] = $paid_to_filter;
    }
    
    // Payment No filter
    if (!empty($payment_no_filter)) {
        $export_conditions[] = "p.payment_no LIKE ?";
        $export_params[] = '%' . $payment_no_filter . '%';
    }
    
    // Financial Record filter
    if (!empty($financial_record_filter) && in_array($financial_record_filter, ['yes', 'no'])) {
        $export_conditions[] = "p.record_in_financial = ?";
        $export_params[] = $financial_record_filter;
    }
    
    // Build WHERE clause
    $export_where_clause = '';
    if (!empty($export_conditions)) {
        $export_where_clause = 'WHERE ' . implode(' AND ', $export_conditions);
    }
    
    // Fetch all payments for export
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
        
        // Export to Excel
        exportPaymentsToExcel($all_payments_export);
        
    } catch (PDOException $e) {
        $error_message = "Error exporting payments: " . $e->getMessage();
    }
}

// Fetch payment methods
try {
    $payment_methods_stmt = $db->query("SELECT id, code, description, cashbook, priority, status FROM payment_methods WHERE status = 'active' ORDER BY priority");
    $payment_methods = $payment_methods_stmt->fetchAll();
} catch (PDOException $e) {
    $payment_methods = [];
    $error_message = "Error fetching payment methods: " . $e->getMessage();
}

// Fetch ledger types
try {
    $ledger_types_stmt = $db->query("SELECT code, description FROM ledger_types WHERE status = 'active' ORDER BY description");
    $ledger_types = $ledger_types_stmt->fetchAll();
} catch (PDOException $e) {
    $ledger_types = [];
    $error_message = "Error fetching ledger types: " . $e->getMessage();
}

// Fetch bank accounts with current balance
try {
    $bank_accounts_stmt = $db->query("SELECT id, code, bank_name, account_name, account_number, currency, current_balance FROM banks_accounts WHERE status = 'active' ORDER BY bank_name, account_name");
    $bank_accounts = $bank_accounts_stmt->fetchAll();
} catch (PDOException $e) {
    $bank_accounts = [];
    $error_message = "Error fetching bank accounts: " . $e->getMessage();
}

// Handle AJAX request for fetching entities based on ledger type
if (isset($_GET['ajax']) && $_GET['ajax'] == 'get_entities') {
    $ledger_type = $_GET['ledger_type'] ?? '';
    $entities = [];
    
    try {
        switch ($ledger_type) {
            case 'A': // Agents
                $query = "SELECT id, agent_code as code, name, 'agent' as type, 'Agent' as entity_type, contact_person, phone, email FROM agents WHERE status = 'active' AND is_active = 1 ORDER BY name";
                break;
                
            case 'S': // Suppliers
                $query = "SELECT id, supplier_code as code, name, 'supplier' as type, 'Supplier' as entity_type, contact_person, phone, email FROM suppliers WHERE status = 'active' AND is_active = 1 ORDER BY name";
                break;
                
            case 'C': // Customers/Clients
                $query = "SELECT id, client_code as code, client_name as name, cds_account, 'client' as type, 'Customer' as entity_type, phone, email, client_type FROM clients WHERE status = 'active' AND is_active = 1 ORDER BY client_name";
                break;
                
            case 'D': // Custodians
                $query = "SELECT id, custodian_code as code, custodian_name as name, 'custodian' as type, 'Custodian' as entity_type, contact_person, phone, email FROM custodians WHERE status = 'active' AND is_active = 1 ORDER BY custodian_name";
                break;
                
            case 'B': // Brokers
                $query = "SELECT id, broker_code as code, broker_name as name, 'broker' as type, 'Broker' as entity_type, contact_person, phone, email FROM brokers WHERE status = 'active' AND is_active = 1 ORDER BY broker_name";
                break;
                
            case 'E': // Employees (Users)
                $query = "SELECT id, username as code, full_name as name, 'employee' as type, 'Employee' as entity_type, email, phone FROM users WHERE status = 'active' AND is_active = 1 ORDER BY full_name";
                break;
                
            case 'O': // Chart of Accounts
                $query = "
                    SELECT 
                        account_code as code,
                        account_name as name,
                        account_type,
                        level,
                        is_group_account,
                        'chart_account' as type,
                        'Chart Account' as entity_type,
                        CASE 
                            WHEN level = 5 THEN CONCAT('Level 5: ', account_name, ' (', account_code, ')')
                            WHEN level = 4 THEN CONCAT('Level 4: ', account_name, ' (', account_code, ')')
                            WHEN level = 3 THEN CONCAT('Level 3: ', account_name, ' (', account_code, ')')
                            WHEN level = 2 THEN CONCAT('Level 2: ', account_name, ' (', account_code, ')')
                            WHEN level = 1 THEN CONCAT('Level 1: ', account_name, ' (', account_code, ')')
                            ELSE CONCAT(account_name, ' (', account_code, ')')
                        END as display_name
                    FROM chart_of_accounts 
                    WHERE is_active = 1 
                    AND (is_group_account = 0 OR level IN (2, 3, 4, 5))
                    ORDER BY 
                        account_type,
                        CASE 
                            WHEN account_type = 'expense' THEN 1
                            WHEN account_type = 'asset' THEN 2
                            WHEN account_type = 'liability' THEN 3
                            WHEN account_type = 'equity' THEN 4
                            WHEN account_type = 'income' THEN 5
                            ELSE 6
                        END,
                        account_code
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
        error_log("Error fetching entities for ledger type $ledger_type: " . $e->getMessage());
        header('Content-Type: application/json');
        echo json_encode([]);
        exit;
    }
}

// Handle AJAX request for getting entity details
if (isset($_GET['ajax']) && $_GET['ajax'] == 'get_entity_details') {
    $ledger_type = $_GET['ledger_type'] ?? '';
    $entity_id = $_GET['entity_id'] ?? '';
    $details = [];
    
    try {
        switch ($ledger_type) {
            case 'A': // Agents
                $query = "SELECT id, agent_code as code, name, contact_person, phone, email FROM agents WHERE id = ?";
                break;
                
            case 'S': // Suppliers
                $query = "SELECT id, supplier_code as code, name, contact_person, phone, email FROM suppliers WHERE id = ?";
                break;
                
            case 'C': // Customers/Clients
                $query = "SELECT id, client_code as code, client_name as name, cds_account, phone, email, client_type FROM clients WHERE id = ?";
                break;
                
            case 'D': // Custodians
                $query = "SELECT id, custodian_code as code, custodian_name as name, contact_person, phone, email FROM custodians WHERE id = ?";
                break;
                
            case 'B': // Brokers
                $query = "SELECT id, broker_code as code, broker_name as name, contact_person, phone, email FROM brokers WHERE id = ?";
                break;
                
            case 'E': // Employees (Users)
                $query = "SELECT id, username as code, full_name as name, email, phone, role FROM users WHERE id = ?";
                break;
                
            case 'O': // Chart of Accounts
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

// Handle AJAX request for getting payment details
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
        
        if ($payment) {
            header('Content-Type: application/json');
            echo json_encode($payment);
        } else {
            echo json_encode(['error' => 'Payment not found']);
        }
        exit;
        
    } catch (PDOException $e) {
        error_log("Error fetching payment: " . $e->getMessage());
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Database error']);
        exit;
    }
}

// Handle AJAX request for getting journal entries
if (isset($_GET['ajax']) && $_GET['ajax'] == 'get_journal_entries') {
    $payment_no = $_GET['payment_no'] ?? '';
    
    try {
        $stmt = $db->prepare("
            SELECT * FROM journal_entries 
            WHERE reference_no = ? AND reference_type = 'payment'
            ORDER BY transaction_date, id
        ");
        $stmt->execute([$payment_no]);
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

// Handle payment generation (POST handling)
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Verify CSRF token
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $error_message = "CSRF token validation failed. Please try again.";
    } else {
        // Get current user info
        $current_user = $_SESSION['username'] ?? 'system';
        $user_id = $_SESSION['user_id'] ?? null;
        
        // Sanitize and validate inputs
        $payment_date = sanitizeInput($_POST['payment_date'] ?? '');
        $payment_mode = (int)($_POST['payment_mode'] ?? 0);
        $paid_to = sanitizeInput($_POST['paid_to'] ?? '');
        
        // Get the name from either the select or input field
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
        
        // Get entity information
        $entity_type = sanitizeInput($_POST['entity_type'] ?? '');
        $entity_id = sanitizeInput($_POST['entity_id'] ?? '');
        
        // Validate required fields
        if (empty($payment_date) || !validateDate($payment_date)) {
            $error_message = "Invalid payment date.";
        } elseif ($payment_mode <= 0) {
            $error_message = "Please select a valid payment mode.";
        } elseif (empty($paid_to)) {
            $error_message = "Please select payee type.";
        } elseif (empty($name)) {
            $error_message = "Please enter payee name.";
        } elseif (!validateAmount($amount)) {
            $error_message = "Invalid amount. Amount must be greater than 0 and less than 999,999,999.99";
        } elseif ($ac_credit <= 0) {
            $error_message = "Please select a valid bank account.";
        } elseif (!validateCurrency($currency)) {
            $error_message = "Invalid currency selected.";
        } else {
            try {
                // Get bank account details
                $bank_stmt = $db->prepare("SELECT id, bank_name, account_number, account_name, code, current_balance FROM banks_accounts WHERE id = ?");
                $bank_stmt->execute([$ac_credit]);
                $bank_account = $bank_stmt->fetch();
                
                if (!$bank_account) {
                    $error_message = "Selected bank account not found.";
                } else {
                    $bank_name = $bank_account['bank_name'];
                    $bank_account_number = $bank_account['account_number'];
                    $bank_code = $bank_account['code'];
                    $bank_current_balance = $bank_account['current_balance'];
                    
                    // Get payment method details
                    $payment_stmt = $db->prepare("SELECT description FROM payment_methods WHERE id = ?");
                    $payment_stmt->execute([$payment_mode]);
                    $payment_method = $payment_stmt->fetch();
                    $payment_method_desc = $payment_method['description'] ?? '';
                    
                    // Generate payment number
                    $payment_no = generatePaymentNo($db, $payment_date);
                    
                    // Check if we're updating or creating
                    $is_update = isset($_POST['update_payment']);
                    
                    if ($is_update && $payment_id > 0) {
                        // Update existing payment
                        $update_stmt = $db->prepare("
                            UPDATE payments SET
                                payment_date = ?,
                                payment_mode = ?,
                                paid_to = ?,
                                name = ?,
                                name_id = ?,
                                source_type = ?,
                                record_in_financial = ?,
                                ac_credit = ?,
                                currency = ?,
                                account_no = ?,
                                amount = ?,
                                cheque_no = ?,
                                narration = ?,
                                bank_name = ?,
                                bank_account_number = ?,
                                updated_at = NOW()
                            WHERE id = ?
                        ");
                        
                        $update_stmt->execute([
                            $payment_date,
                            $payment_mode,
                            $paid_to,
                            $name,
                            $entity_id,
                            $entity_type,
                            $record_in_financial,
                            $ac_credit,
                            $currency,
                            $account_no,
                            $amount,
                            $cheque_no,
                            $narration,
                            $bank_name,
                            $bank_account_number,
                            $payment_id
                        ]);
                        
                        $success_message = "Payment updated successfully! Payment No: " . ($_POST['payment_no'] ?? '');
                        
                    } else {
                        // Create new payment
                        $insert_stmt = $db->prepare("
                            INSERT INTO payments (
                                payment_no, payment_date, payment_mode, paid_to,
                                name, name_id, source_type, record_in_financial, ac_credit,
                                currency, account_no, amount, cheque_no, narration,
                                created_by_username, created_at, status,
                                bank_name, bank_account_number
                            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), 'active', ?, ?)
                        ");
                        
                        $insert_stmt->execute([
                            $payment_no,
                            $payment_date,
                            $payment_mode,
                            $paid_to,
                            $name,
                            $entity_id,
                            $entity_type,
                            $record_in_financial,
                            $ac_credit,
                            $currency,
                            $account_no,
                            $amount,
                            $cheque_no,
                            $narration,
                            $current_user,
                            $bank_name,
                            $bank_account_number
                        ]);
                        
                        $new_payment_id = $db->lastInsertId();
                        
                        // If recording in financial statements, create journal entries
                        if ($record_in_financial == 'yes') {
                            try {
                                // Determine appropriate accounts based on ledger type
                                $control_account_map = [
                                    'A' => '73111', // AGENTS CONTROL A/C
                                    'B' => '72714', // BROKERS CONTROL A/C
                                    'C' => '73101', // CLIENTS CONTROL A/C
                                    'S' => '73101', // SUPPLIERS CONTROL A/C
                                    'D' => '72114', // CUSTODIANS CONTROL A/C
                                    'E' => '72715', // EMPLOYEES CONTROL A/C
                                    'O' => '73113', // CLIENTS CONTROL A/C (for nominal)
                                ];
                                
                                $control_account_code = $control_account_map[$paid_to] ?? '73113';
                                
                                // For payments (money out), we debit the appropriate account and credit bank
                                
                                // 1. Debit appropriate account (Expense/Asset account)
                                if ($paid_to === 'O') {
                                    // For nominal clients, get appropriate expense account
                                    $expense_account = getAppropriateAccountLevel($db, 'expense');
                                    if ($expense_account) {
                                        $expense_journal_data = [
                                            'transaction_date' => $payment_date,
                                            'reference_no' => $payment_no,
                                            'reference_type' => 'payment',
                                            'description' => "Payment: $narration",
                                            'account_code' => $expense_account['account_code'],
                                            'debit_amount' => $amount,
                                            'credit_amount' => 0,
                                            'currency' => $currency,
                                            'entity_id' => $entity_id,
                                            'entity_name' => $name,
                                            'entity_type' => $entity_type,
                                            'bank_account_id' => $ac_credit,
                                            'bank_name' => $bank_name,
                                            'bank_account_number' => $bank_account_number
                                        ];
                                        
                                        createJournalEntry($db, $expense_journal_data);
                                    }
                                } else {
                                    // For other entities, debit their control account
                                    $control_journal_data = [
                                        'transaction_date' => $payment_date,
                                        'reference_no' => $payment_no,
                                        'reference_type' => 'payment',
                                        'description' => "Payment to $name: $narration",
                                        'account_code' => $control_account_code,
                                        'debit_amount' => $amount,
                                        'credit_amount' => 0,
                                        'currency' => $currency,
                                        'entity_id' => $entity_id,
                                        'entity_name' => $name,
                                        'entity_type' => $entity_type,
                                        'bank_account_id' => $ac_credit,
                                        'bank_name' => $bank_name,
                                        'bank_account_number' => $bank_account_number
                                    ];
                                    
                                    createJournalEntry($db, $control_journal_data);
                                }
                                
                                // 2. Credit Bank Account (Bank account decreases)
                                $bank_journal_data = [
                                    'transaction_date' => $payment_date,
                                    'reference_no' => $payment_no,
                                    'reference_type' => 'payment',
                                    'description' => "Payment to $name: $narration",
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
                                ];
                                
                                createJournalEntry($db, $bank_journal_data);
                                
                                // Update bank account balance (money out, so decrease balance)
                                updateBankBalance($db, $ac_credit, $amount);
                                
                                $success_message = "✅ Payment created successfully with journal entries! Payment No: $payment_no";
                                
                            } catch (Exception $e) {
                                // Log the error but don't fail the payment creation
                                error_log("Journal entry creation error: " . $e->getMessage());
                                $success_message = "✅ Payment created successfully! Payment No: $payment_no<br>⚠️ Note: Journal entries could not be created: " . $e->getMessage();
                            }
                        } else {
                            $success_message = "✅ Payment created successfully! Payment No: $payment_no (Not recorded in financial statements)";
                        }
                    }
                    
                    // Refresh CSRF token
                    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                }
            } catch (PDOException $e) {
                $error_message = "Database error: " . $e->getMessage();
                error_log("Payment creation error: " . $e->getMessage());
            } catch (Exception $e) {
                $error_message = "Error: " . $e->getMessage();
                error_log("Payment creation error: " . $e->getMessage());
            }
        }
    }
}

// Get filter parameters
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-d');
$paid_to_filter = $_GET['paid_to'] ?? '';
$payment_no_filter = $_GET['payment_no'] ?? '';
$financial_record_filter = $_GET['financial_record'] ?? '';
$search_name = $_GET['search_name'] ?? '';

// Validate filter dates
if (!validateDate($start_date)) $start_date = date('Y-m-01');
if (!validateDate($end_date)) $end_date = date('Y-m-d');

// Ensure end date is not before start date
if (strtotime($end_date) < strtotime($start_date)) {
    $end_date = $start_date;
}

// Build filter query for payments
$filter_conditions = [];
$filter_params = [];

// Date range filter
if (!empty($start_date) && !empty($end_date)) {
    $filter_conditions[] = "p.payment_date BETWEEN ? AND ?";
    $filter_params[] = $start_date;
    $filter_params[] = $end_date;
}

// Paid To filter
if (!empty($paid_to_filter) && in_array($paid_to_filter, array_column($ledger_types, 'code'))) {
    $filter_conditions[] = "p.paid_to = ?";
    $filter_params[] = $paid_to_filter;
}

// Payment No filter
if (!empty($payment_no_filter)) {
    $filter_conditions[] = "p.payment_no LIKE ?";
    $filter_params[] = '%' . $payment_no_filter . '%';
}

// Financial Record filter
if (!empty($financial_record_filter) && in_array($financial_record_filter, ['yes', 'no'])) {
    $filter_conditions[] = "p.record_in_financial = ?";
    $filter_params[] = $financial_record_filter;
}

// Name search filter
if (!empty($search_name)) {
    $filter_conditions[] = "(p.name LIKE ? OR p.account_no LIKE ?)";
    $filter_params[] = '%' . $search_name . '%';
    $filter_params[] = '%' . $search_name . '%';
}

// Build WHERE clause
$where_clause = '';
if (!empty($filter_conditions)) {
    $where_clause = 'WHERE ' . implode(' AND ', $filter_conditions);
}

// Fetch all payments for the table with filters - NO LIMIT for display
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

// Calculate totals
$total_amount = 0;
$total_recorded = 0;
$total_not_recorded = 0;

foreach ($all_payments as $payment) {
    $total_amount += $payment['amount'];
    if ($payment['record_in_financial'] == 'yes') {
        $total_recorded += $payment['amount'];
    } else {
        $total_not_recorded += $payment['amount'];
    }
}

// Fetch journal entries for display
$journal_entries = [];
try {
    $journal_query = "
        SELECT je.* 
        FROM journal_entries je
        WHERE je.reference_type = 'payment'
        AND je.status = 'posted'
        AND je.transaction_date BETWEEN ? AND ?
        ORDER BY je.transaction_date DESC, je.journal_no
    ";
    $journal_stmt = $db->prepare($journal_query);
    $journal_stmt->execute([$start_date, $end_date]);
    $journal_entries = $journal_stmt->fetchAll();
} catch (PDOException $e) {
    // Silently fail - journal entries are optional for display
}

$page_title = 'Payment Processing - Money Out';
include '../includes/header.php';
?>

<style>
    .form-control-sm {
        height: calc(1.5em + 0.5rem + 2px);
        padding: 0.25rem 0.5rem;
        font-size: 0.875rem;
        line-height: 1.5;
    }
    
    .input-group-sm .form-control {
        height: calc(1.5em + 0.5rem + 2px);
        padding: 0.25rem 0.5rem;
        font-size: 0.875rem;
        line-height: 1.5;
    }
    
    .input-group-sm .btn {
        height: calc(1.5em + 0.5rem + 2px);
        padding: 0.25rem 0.5rem;
        font-size: 0.875rem;
        line-height: 1.5;
    }
    
    .floating-upload-container {
        position: fixed;
        bottom: 30px;
        right: 30px;
        z-index: 9999;
        animation: slideInUp 0.5s ease-out;
    }
    
    .floating-upload-card {
        background: white;
        border-radius: 12px;
        box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
        border: 1px solid #dee2e6;
        width: 350px;
        overflow: hidden;
    }
    
    .floating-upload-header {
        background: linear-gradient(135deg, #dc3545 0%, #c82333 100%);
        color: white;
        padding: 15px 20px;
        display: flex;
        align-items: center;
        font-weight: 600;
    }
    
    .floating-upload-header i {
        font-size: 1.5rem;
        margin-right: 10px;
    }
    
    .floating-upload-body {
        padding: 20px;
    }
    
    @keyframes slideInUp {
        from {
            transform: translateY(100px);
            opacity: 0;
        }
        to {
            transform: translateY(0);
            opacity: 1;
        }
    }
    
    .table-danger {
        background-color: rgba(220, 53, 69, 0.1);
    }
    
    .table-warning {
        background-color: rgba(255, 193, 7, 0.1);
    }
    
    .badge {
        padding: 0.35em 0.65em;
        font-size: 0.75em;
    }
    
    .stats-card {
        border-radius: 10px;
        transition: transform 0.3s ease;
    }
    
    .stats-card:hover {
        transform: translateY(-5px);
    }
    
    .export-btn {
        background: linear-gradient(135deg, #dc3545 0%, #c82333 100%);
        border: none;
        color: white;
        font-weight: 600;
    }
    
    .export-btn:hover {
        background: linear-gradient(135deg, #c82333 0%, #bd2130 100%);
        color: white;
    }
    
    .stats-card-recorded {
        border-left: 5px solid #28a745;
    }
    
    .stats-card-not-recorded {
        border-left: 5px solid #6c757d;
    }
    
    .stats-card-total {
        border-left: 5px solid #dc3545;
    }
    
    .clickable-row {
        cursor: pointer;
        transition: background-color 0.2s;
    }
    
    .clickable-row:hover {
        background-color: #f8f9fa;
    }
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

    <!-- Stats Overview -->
    <div class="row mb-4">
        <div class="col-md-2 mb-3">
            <div class="card stats-card border-primary">
                <div class="card-body text-center py-4">
                    <h3 class="text-primary mb-1">
                        <?php echo count($all_payments); ?>
                    </h3>
                    <small class="text-muted">Total Payments</small>
                </div>
            </div>
        </div>
        <div class="col-md-2 mb-3">
            <div class="card stats-card stats-card-total">
                <div class="card-body text-center py-4">
                    <h3 class="text-danger mb-1">
                        Tsh <?php echo number_format($total_amount, 2); ?>
                    </h3>
                    <small class="text-muted">Total Amount</small>
                </div>
            </div>
        </div>
        <div class="col-md-2 mb-3">
            <div class="card stats-card stats-card-recorded">
                <div class="card-body text-center py-4">
                    <h3 class="text-success mb-1">
                        Tsh <?php echo number_format($total_recorded, 2); ?>
                    </h3>
                    <small class="text-muted">In Financial Records</small>
                </div>
            </div>
        </div>
        <div class="col-md-2 mb-3">
            <div class="card stats-card stats-card-not-recorded">
                <div class="card-body text-center py-4">
                    <h3 class="text-warning mb-1">
                        Tsh <?php echo number_format($total_not_recorded, 2); ?>
                    </h3>
                    <small class="text-muted">Not in Financial Records</small>
                </div>
            </div>
        </div>
        <div class="col-md-2 mb-3">
            <div class="card stats-card border-info">
                <div class="card-body text-center py-4">
                    <h3 class="text-info mb-1">
                        <?php echo count($journal_entries); ?>
                    </h3>
                    <small class="text-muted">Journal Entries</small>
                </div>
            </div>
        </div>
        <div class="col-md-2 mb-3">
            <div class="card stats-card border-warning">
                <div class="card-body text-center py-4">
                    <h3 class="text-warning mb-1">
                        <?php echo count($bank_accounts); ?>
                    </h3>
                    <small class="text-muted">Bank Accounts</small>
                </div>
            </div>
        </div>
    </div>

    <!-- Payment Entry Form -->
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-header bg-danger text-white border-bottom">
                    <h5 class="mb-0"><i class="bi bi-cash-coin me-2"></i>Record Money Paid Out</h5>
                </div>
                <div class="card-body" id="paymentFormContainer">
                    <form method="POST" id="paymentForm">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                        <input type="hidden" name="payment_id" id="payment_id" value="">
                        <input type="hidden" name="entity_type" id="entity_type" value="">
                        <input type="hidden" name="entity_id" id="entity_id" value="">
                        
                        <div class="alert alert-danger mb-4">
                            <i class="bi bi-info-circle me-2"></i>
                            <strong>Money Out:</strong> This payment records money being paid out from the company's bank account. Bank account will be <strong>credited</strong> (balance decreased) and appropriate expense/account will be <strong>debited</strong>.
                        </div>
                        
                        <div class="row g-2">
                            <!-- Payment Basic Information -->
                            <div class="col-md-3">
                                <label class="form-label">Payment Date <span class="text-danger">*</span></label>
                                <input type="date" class="form-control form-control-sm" name="payment_date" id="payment_date" 
                                       value="<?php echo htmlspecialchars($_POST['payment_date'] ?? date('Y-m-d')); ?>" 
                                       max="<?php echo date('Y-m-d'); ?>" required>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Payment Mode <span class="text-danger">*</span></label>
                                <div class="input-group input-group-sm">
                                    <select class="form-select form-control-sm" name="payment_mode" id="payment_mode" required>
                                        <option value="">Select Payment Mode</option>
                                        <?php foreach ($payment_methods as $method): ?>
                                            <option value="<?php echo (int)$method['id']; ?>" 
                                                    <?php echo ($_POST['payment_mode'] ?? '') == $method['id'] ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($method['code'] . ' - ' . $method['description']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button type="button" class="btn btn-outline-danger btn-sm" data-bs-toggle="modal" data-bs-target="#paymentMethodsModal">
                                        <i class="bi bi-list-ul"></i>
                                    </button>
                                </div>
                            </div>

                            <!-- Payee Information -->
                            <div class="col-md-3">
                                <label class="form-label">Pay To <span class="text-danger">*</span></label>
                                <div class="input-group input-group-sm">
                                    <select class="form-select form-control-sm" name="paid_to" id="paid_to" required>
                                        <option value="">Select Payee Type</option>
                                        <?php foreach ($ledger_types as $type): ?>
                                            <option value="<?php echo htmlspecialchars($type['code']); ?>" 
                                                    <?php echo ($_POST['paid_to'] ?? '') == $type['code'] ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($type['description']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button type="button" class="btn btn-outline-danger btn-sm" data-bs-toggle="modal" data-bs-target="#accountTypesModal">
                                        <i class="bi bi-list-ul"></i>
                                    </button>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Payee Name <span class="text-danger">*</span></label>
                                <div class="mb-2">
                                    <select class="form-select form-control-sm d-none" name="name_select" id="name_select">
                                        <option value="">Select from list</option>
                                    </select>
                                    <input type="text" class="form-control form-control-sm" name="name" id="name_input" 
                                           value="<?php echo htmlspecialchars($_POST['name'] ?? ''); ?>" 
                                           placeholder="Enter payee name" required>
                                </div>
                                <div class="d-flex gap-1">
                                    <button type="button" class="btn btn-outline-danger btn-sm" id="toggleNameMode">
                                        <i class="bi bi-list-ul"></i> Select from List
                                    </button>
                                    <button type="button" class="btn btn-outline-danger btn-sm" id="openNamesModal" data-bs-toggle="modal" data-bs-target="#entitiesModal" disabled>
                                        <i class="bi bi-search"></i> Browse
                                    </button>
                                </div>
                                <small class="text-muted" id="source_indicator"></small>
                            </div>

                            <!-- Financial Statement Option -->
                            <div class="col-md-3">
                                <label class="form-label">Record in Financial Statement? <span class="text-danger">*</span></label>
                                <div>
                                    <div class="form-check form-check-inline">
                                        <input class="form-check-input" type="radio" name="record_in_financial" id="record_yes" value="yes" 
                                               <?php echo ($_POST['record_in_financial'] ?? 'yes') === 'yes' ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="record_yes">Yes</label>
                                    </div>
                                    <div class="form-check form-check-inline">
                                        <input class="form-check-input" type="radio" name="record_in_financial" id="record_no" value="no"
                                               <?php echo ($_POST['record_in_financial'] ?? 'yes') === 'no' ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="record_no">No</label>
                                    </div>
                                </div>
                            </div>

                            <!-- Bank and Payment Details -->
                            <div class="col-md-3">
                                <label class="form-label">Withdraw From Bank Account <span class="text-danger">*</span></label>
                                <div class="input-group input-group-sm">
                                    <select class="form-select form-control-sm" name="ac_credit" id="ac_credit" required>
                                        <option value="">Select Company Bank Account</option>
                                        <?php foreach ($bank_accounts as $bank): ?>
                                            <option value="<?php echo (int)$bank['id']; ?>" 
                                                    data-currency="<?php echo htmlspecialchars($bank['currency']); ?>"
                                                    data-balance="<?php echo htmlspecialchars($bank['current_balance']); ?>"
                                                    data-code="<?php echo htmlspecialchars($bank['code']); ?>"
                                                    data-bank-name="<?php echo htmlspecialchars($bank['bank_name']); ?>"
                                                    data-bank-number="<?php echo htmlspecialchars($bank['account_number']); ?>"
                                                    <?php echo ($_POST['ac_credit'] ?? '') == $bank['id'] ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($bank['bank_name'] . ' - ' . $bank['account_name'] . ' (' . $bank['account_number'] . ')'); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button type="button" class="btn btn-outline-danger btn-sm" data-bs-toggle="modal" data-bs-target="#bankAccountsModal">
                                        <i class="bi bi-list-ul"></i>
                                    </button>
                                </div>
                                <small class="text-muted" id="bank_balance_indicator">Balance: Tsh 0.00</small>
                            </div>

                            <!-- Currency and Account Details -->
                            <div class="col-md-2">
                                <label class="form-label">Currency</label>
                                <select class="form-select form-control-sm" name="currency" id="currency">
                                    <option value="Tsh" <?php echo ($_POST['currency'] ?? 'Tsh') === 'Tsh' ? 'selected' : ''; ?>>TZS</option>
                                    <option value="Ksh" <?php echo ($_POST['currency'] ?? 'Tsh') === 'Ksh' ? 'selected' : ''; ?>>Ksh</option>
                                    <option value="USD" <?php echo ($_POST['currency'] ?? 'Tsh') === 'USD' ? 'selected' : ''; ?>>USD</option>
                                    <option value="UGsh" <?php echo ($_POST['currency'] ?? 'Tsh') === 'UGsh' ? 'selected' : ''; ?>>UGsh</option>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Payee Account No</label>
                                <input type="text" class="form-control form-control-sm" name="account_no" id="account_no" 
                                       value="<?php echo htmlspecialchars($_POST['account_no'] ?? ''); ?>" 
                                       placeholder="Account number">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Amount Paid <span class="text-danger">*</span></label>
                                <input type="number" class="form-control form-control-sm" name="amount" id="amount" 
                                       value="<?php echo htmlspecialchars($_POST['amount'] ?? ''); ?>" 
                                       step="0.01" min="0.01" max="999999999.99" placeholder="0.00" required>
                            </div>

                            <!-- Additional Details -->
                            <div class="col-md-2">
                                <label class="form-label">Cheque No</label>
                                <input type="text" class="form-control form-control-sm" name="cheque_no" id="cheque_no" 
                                       value="<?php echo htmlspecialchars($_POST['cheque_no'] ?? ''); ?>" 
                                       placeholder="If cheque">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Payment Description</label>
                                <input type="text" class="form-control form-control-sm" name="narration" id="narration" 
                                       value="<?php echo htmlspecialchars($_POST['narration'] ?? ''); ?>" 
                                       placeholder="Purpose of payment">
                            </div>

                            <!-- Submit Buttons -->
                            <div class="col-12 mt-2">
                                <div class="d-flex justify-content-end gap-2">
                                    <button type="reset" class="btn btn-outline-secondary btn-sm" id="resetFormBtn">
                                        <i class="bi bi-arrow-clockwise me-1"></i>Reset
                                    </button>
                                    <button type="submit" name="generate_payment" class="btn btn-danger btn-sm" id="generateBtn">
                                        <i class="bi bi-cash-coin me-1"></i>Record Payment Out
                                    </button>
                                    <button type="submit" name="update_payment" class="btn btn-warning btn-sm d-none" id="updateBtn">
                                        <i class="bi bi-pencil me-1"></i>Update
                                    </button>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Hidden payment number field -->
                        <input type="hidden" name="payment_no" id="payment_no" value="">
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- All Payments Table -->
    <div class="row mt-4">
        <div class="col-12">
            <div class="card">
                <div class="card-header bg-info text-white d-flex justify-content-between align-items-center py-2">
                    <h5 class="mb-0"><i class="bi bi-table me-2"></i>All Payments</h5>
                    <div class="d-flex gap-1">
                        <a href="?export=excel&<?php echo http_build_query($_GET); ?>" 
                           class="btn btn-sm export-btn" 
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
                    <!-- Filters Section -->
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
                                                <option value="<?php echo htmlspecialchars($type['code']); ?>" 
                                                        <?php echo $paid_to_filter === $type['code'] ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($type['description']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Payment No</label>
                                        <input type="text" class="form-control form-control-sm" name="payment_no" value="<?php echo htmlspecialchars($payment_no_filter); ?>" placeholder="Search payment no">
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Name/Account</label>
                                        <input type="text" class="form-control form-control-sm" name="search_name" value="<?php echo htmlspecialchars($search_name); ?>" placeholder="Search name or account">
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Financial Record</label>
                                        <select class="form-select form-control-sm" name="financial_record">
                                            <option value="">All</option>
                                            <option value="yes" <?php echo $financial_record_filter === 'yes' ? 'selected' : ''; ?>>Recorded</option>
                                            <option value="no" <?php echo $financial_record_filter === 'no' ? 'selected' : ''; ?>>Not Recorded</option>
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
                                    <th>Payee Type</th>
                                    <th>Payee Name</th>
                                    <th>Account No</th>
                                    <th>Amount</th>
                                    <th>Currency</th>
                                    <th>Bank Account</th>
                                    <th>Bank Balance</th>
                                    <th>Created By</th>
                                    <th>Financial Record</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($all_payments)): ?>
                                    <tr>
                                        <td colspan="12" class="text-center py-3 text-muted">
                                            <i class="bi bi-inbox" style="font-size: 2rem;"></i>
                                            <p class="mt-2">No payments found</p>
                                        </td>
                                    </tr>
                                <?php else:
                                    foreach ($all_payments as $payment): 
                                        // Format bank info
                                        $bank_info = '';
                                        if (!empty($payment['bank_name'])) {
                                            $bank_info = $payment['bank_name'];
                                            if (!empty($payment['bank_account_number'])) {
                                                $bank_info .= ' (' . $payment['bank_account_number'] . ')';
                                            }
                                        }
                                    ?>
                                        <tr>
                                            <td>
                                                <code class="fw-bold text-danger"><?php echo htmlspecialchars($payment['payment_no']); ?></code>
                                            </td>
                                            <td><?php echo date('M d, Y', strtotime($payment['payment_date'])); ?></td>
                                            <td><?php echo htmlspecialchars($payment['paid_to_desc'] ?? $payment['paid_to']); ?></td>
                                            <td>
                                                <?php echo htmlspecialchars($payment['name'] ?? ''); ?>
                                                <?php if (!empty($payment['name_id'])): ?>
                                                    <br><small class="text-muted">ID: <?php echo htmlspecialchars((string)$payment['name_id']); ?></small>
                                                <?php endif; ?>
                                            </td>
                                            <td><?php echo htmlspecialchars($payment['account_no'] ?? ''); ?></td>
                                            <td class="fw-bold text-danger"><?php echo number_format($payment['amount'], 2); ?></td>
                                            <td><?php echo htmlspecialchars($payment['currency'] ?? ''); ?></td>
                                            <td>
                                                <?php if (!empty($bank_info)): ?>
                                                    <small><?php echo htmlspecialchars($bank_info); ?></small>
                                                <?php else: ?>
                                                    <span class="text-muted">N/A</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if (!empty($payment['bank_current_balance'])): ?>
                                                    <small class="text-muted"><?php echo number_format($payment['bank_current_balance'], 2); ?></small>
                                                <?php else: ?>
                                                    <span class="text-muted">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <small><?php echo htmlspecialchars($payment['created_by_username'] ?? 'System'); ?></small>
                                                <br><small class="text-muted"><?php echo date('M d', strtotime($payment['created_at'])); ?></small>
                                            </td>
                                            <td>
                                                <span class="badge <?php echo $payment['record_in_financial'] == 'yes' ? 'bg-success' : 'bg-secondary'; ?>">
                                                    <?php echo $payment['record_in_financial'] == 'yes' ? 'Recorded' : 'Not Recorded'; ?>
                                                </span>
                                            </td>
                                            <td>
                                                <div class="btn-group btn-group-sm" role="group">
                                                    <button type="button" class="btn btn-outline-primary btn-sm view-payment" 
                                                            data-payment-id="<?php echo (int)$payment['id']; ?>">
                                                        <i class="bi bi-eye"></i>
                                                    </button>
                                                    <button type="button" class="btn btn-outline-warning btn-sm edit-payment" 
                                                            data-payment-id="<?php echo (int)$payment['id']; ?>">
                                                        <i class="bi bi-pencil"></i>
                                                    </button>
                                                    <button type="button" class="btn btn-outline-info btn-sm view-journal" 
                                                            data-payment-no="<?php echo htmlspecialchars($payment['payment_no']); ?>">
                                                        <i class="bi bi-journal-text"></i>
                                                    </button>
                                                    <button type="button" class="btn btn-outline-danger btn-sm print-payment" 
                                                            data-payment-id="<?php echo (int)$payment['id']; ?>"
                                                            data-payment-no="<?php echo htmlspecialchars($payment['payment_no']); ?>">
                                                        <i class="bi bi-file-pdf"></i>
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach;
                                endif; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <!-- Results Count -->
                    <div class="mt-2">
                        <small class="text-muted">
                            Showing <?php echo count($all_payments); ?> payment(s) - Total: 
                            <strong class="text-danger">Tsh <?php echo number_format($total_amount, 2); ?></strong>
                            (Recorded: <?php echo number_format($total_recorded, 2); ?>, Not Recorded: <?php echo number_format($total_not_recorded, 2); ?>)
                            <?php if (!empty($start_date) || !empty($end_date) || !empty($paid_to_filter) || !empty($payment_no_filter) || !empty($financial_record_filter) || !empty($search_name)): ?>
                                (filtered results)
                            <?php endif; ?>
                        </small>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Journal Entries Section -->
    <?php if (!empty($journal_entries)): ?>
    <div class="row mt-3">
        <div class="col-12">
            <div class="card">
                <div class="card-header bg-danger text-white py-2">
                    <h5 class="mb-0"><i class="bi bi-journal-text me-2"></i>Journal Entries for Payments (<?php echo date('M d, Y', strtotime($start_date)); ?> to <?php echo date('M d, Y', strtotime($end_date)); ?>)</h5>
                </div>
                <div class="card-body p-2">
                    <div class="table-responsive">
                        <table class="table table-sm table-striped">
                            <thead>
                                <tr>
                                    <th>Journal No</th>
                                    <th>Date</th>
                                    <th>Reference No</th>
                                    <th>Account</th>
                                    <th>Account Name</th>
                                    <th>Debit</th>
                                    <th>Credit</th>
                                    <th>Description</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($journal_entries as $journal): ?>
                                <tr>
                                    <td><code><?php echo htmlspecialchars($journal['journal_no']); ?></code></td>
                                    <td><?php echo date('M d, Y', strtotime($journal['transaction_date'])); ?></td>
                                    <td class="fw-bold"><?php echo htmlspecialchars($journal['reference_no']); ?></td>
                                    <td><strong><?php echo htmlspecialchars($journal['account_code']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($journal['account_name']); ?></td>
                                    <td class="text-danger fw-bold"><?php echo $journal['debit_amount'] > 0 ? number_format($journal['debit_amount'], 2) : '-'; ?></td>
                                    <td class="text-success fw-bold"><?php echo $journal['credit_amount'] > 0 ? number_format($journal['credit_amount'], 2) : '-'; ?></td>
                                    <td><?php echo htmlspecialchars($journal['description']); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot>
                                <tr class="table-dark">
                                    <td colspan="4" class="text-end fw-bold">Totals:</td>
                                    <td class="text-danger fw-bold">
                                        <?php 
                                        $total_debits = array_sum(array_column($journal_entries, 'debit_amount'));
                                        echo number_format($total_debits, 2);
                                        ?>
                                    </td>
                                    <td class="text-success fw-bold">
                                        <?php 
                                        $total_credits = array_sum(array_column($journal_entries, 'credit_amount'));
                                        echo number_format($total_credits, 2);
                                        ?>
                                    </td>
                                    <td colspan="1">
                                        <span class="badge <?php echo $total_debits == $total_credits ? 'bg-success' : 'bg-danger'; ?>">
                                            <?php echo $total_debits == $total_credits ? 'Balanced' : 'Unbalanced'; ?>
                                        </span>
                                    </td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- View Payment Modal -->
    <div class="modal fade" id="viewPaymentModal" tabindex="-1" aria-labelledby="viewPaymentModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title" id="viewPaymentModalLabel">
                        <i class="bi bi-receipt me-2"></i>Payment Details
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" id="paymentDetailsContent">
                    <!-- Payment details will be loaded here -->
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Close</button>
                    <button type="button" class="btn btn-danger btn-sm" id="printPaymentBtn">
                        <i class="bi bi-printer me-1"></i>Print Payment
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- View Journal Entries Modal -->
    <div class="modal fade" id="viewJournalModal" tabindex="-1" aria-labelledby="viewJournalModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header bg-info text-white">
                    <h5 class="modal-title" id="viewJournalModalLabel">
                        <i class="bi bi-journal-text me-2"></i>Journal Entries
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" id="journalDetailsContent">
                    <!-- Journal entries will be loaded here -->
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Payment Methods Modal -->
    <div class="modal fade" id="paymentMethodsModal" tabindex="-1" aria-labelledby="paymentMethodsModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title" id="paymentMethodsModalLabel">Select Payment Method</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-2">
                    <div class="mb-2">
                        <input type="text" class="form-control form-control-sm" id="paymentMethodsFilter" placeholder="Filter payment methods...">
                    </div>
                    <div class="table-responsive" style="max-height: 400px; overflow-y: auto;">
                        <table class="table table-sm table-hover" id="paymentMethodsTable">
                            <thead class="sticky-top bg-light">
                                <tr>
                                    <th>Code</th>
                                    <th>Description</th>
                                    <th>Cashbook</th>
                                    <th>Priority</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($payment_methods as $method): ?>
                                <tr class="clickable-row" 
                                    data-value="<?php echo (int)$method['id']; ?>"
                                    data-text="<?php echo htmlspecialchars($method['code'] . ' - ' . $method['description']); ?>">
                                    <td><strong><?php echo htmlspecialchars($method['code']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($method['description']); ?></td>
                                    <td>
                                        <span class="badge <?php echo $method['cashbook'] === 'yes' ? 'bg-success' : 'bg-secondary'; ?>">
                                            <?php echo htmlspecialchars($method['cashbook']); ?>
                                        </span>
                                    </td>
                                    <td><?php echo (int)$method['priority']; ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="modal-footer p-2">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Account Types Modal -->
    <div class="modal fade" id="accountTypesModal" tabindex="-1" aria-labelledby="accountTypesModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-info text-white">
                    <h5 class="modal-title" id="accountTypesModalLabel">Select Account Type</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-2">
                    <div class="mb-2">
                        <input type="text" class="form-control form-control-sm" id="accountTypesFilter" placeholder="Filter account types...">
                    </div>
                    <div class="table-responsive" style="max-height: 400px; overflow-y: auto;">
                        <table class="table table-sm table-hover" id="accountTypesTable">
                            <thead class="sticky-top bg-light">
                                <tr>
                                    <th>Code</th>
                                    <th>Description</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($ledger_types as $type): ?>
                                <tr class="clickable-row" 
                                    data-value="<?php echo htmlspecialchars($type['code']); ?>"
                                    data-text="<?php echo htmlspecialchars($type['description']); ?>">
                                    <td><strong><?php echo htmlspecialchars($type['code']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($type['description']); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="modal-footer p-2">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Bank Accounts Modal -->
    <div class="modal fade" id="bankAccountsModal" tabindex="-1" aria-labelledby="bankAccountsModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header bg-warning text-dark">
                    <h5 class="modal-title" id="bankAccountsModalLabel">Select Bank Account</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-2">
                    <div class="mb-2">
                        <input type="text" class="form-control form-control-sm" id="bankAccountsFilter" placeholder="Filter bank accounts...">
                    </div>
                    <div class="table-responsive" style="max-height: 500px; overflow-y: auto;">
                        <table class="table table-sm table-hover" id="bankAccountsTable">
                            <thead class="sticky-top bg-light">
                                <tr>
                                    <th>Bank Name</th>
                                    <th>Account Name</th>
                                    <th>Account Number</th>
                                    <th>Currency</th>
                                    <th>Account Code</th>
                                    <th>Current Balance</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($bank_accounts as $account): ?>
                                <tr class="clickable-row" 
                                    data-value="<?php echo (int)$account['id']; ?>"
                                    data-text="<?php echo htmlspecialchars($account['bank_name'] . ' - ' . $account['account_name'] . ' (' . $account['account_number'] . ')'); ?>"
                                    data-currency="<?php echo htmlspecialchars($account['currency']); ?>"
                                    data-balance="<?php echo htmlspecialchars($account['current_balance']); ?>"
                                    data-code="<?php echo htmlspecialchars($account['code']); ?>"
                                    data-bank-name="<?php echo htmlspecialchars($account['bank_name']); ?>"
                                    data-bank-number="<?php echo htmlspecialchars($account['account_number']); ?>">
                                    <td><strong><?php echo htmlspecialchars($account['bank_name']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($account['account_name']); ?></td>
                                    <td><?php echo htmlspecialchars($account['account_number']); ?></td>
                                    <td>
                                        <span class="badge bg-secondary"><?php echo htmlspecialchars($account['currency']); ?></span>
                                    </td>
                                    <td><code><?php echo htmlspecialchars($account['code']); ?></code></td>
                                    <td class="fw-bold text-success"><?php echo number_format($account['current_balance'], 2); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="modal-footer p-2">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Entities Modal -->
    <div class="modal fade" id="entitiesModal" tabindex="-1" aria-labelledby="entitiesModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title" id="entitiesModalLabel">Select Entity</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-2">
                    <div class="mb-2">
                        <div class="row g-2">
                            <div class="col-md-4">
                                <select class="form-select form-control-sm" id="entityTypeFilter">
                                    <option value="">All Entity Types</option>
                                    <option value="agent">Agents</option>
                                    <option value="supplier">Suppliers</option>
                                    <option value="client">Customers</option>
                                    <option value="custodian">Custodians</option>
                                    <option value="broker">Brokers</option>
                                    <option value="employee">Employees</option>
                                    <option value="chart_account">Chart Accounts</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <input type="text" class="form-control form-control-sm" id="entitiesFilter" placeholder="Search by name or code...">
                            </div>
                            <div class="col-md-4">
                                <button type="button" class="btn btn-outline-secondary btn-sm w-100" id="refreshEntitiesBtn">
                                    <i class="bi bi-arrow-clockwise me-1"></i>Refresh
                                </button>
                            </div>
                        </div>
                    </div>
                    <div class="table-responsive" style="max-height: 500px; overflow-y: auto;">
                        <table class="table table-sm table-hover" id="entitiesTable">
                            <thead class="sticky-top bg-light">
                                <tr>
                                    <th>Code</th>
                                    <th>Name</th>
                                    <th>Type</th>
                                    <th>Details</th>
                                </tr>
                            </thead>
                            <tbody>
                                <!-- Entities will be loaded here -->
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="modal-footer p-2">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                </div>
            </div>
        </div>
    </div>

</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const paidToSelect = document.getElementById('paid_to');
    const nameSelect = document.getElementById('name_select');
    const nameInput = document.getElementById('name_input');
    const toggleNameModeBtn = document.getElementById('toggleNameMode');
    const entityTypeInput = document.getElementById('entity_type');
    const entityIdInput = document.getElementById('entity_id');
    const acCreditSelect = document.getElementById('ac_credit');
    const currencySelect = document.getElementById('currency');
    const openNamesModalBtn = document.getElementById('openNamesModal');
    const resetFormBtn = document.getElementById('resetFormBtn');
    const generateBtn = document.getElementById('generateBtn');
    const updateBtn = document.getElementById('updateBtn');
    const paymentIdInput = document.getElementById('payment_id');
    const clearFiltersBtn = document.getElementById('clearFiltersBtn');
    const paymentNoInput = document.getElementById('payment_no');
    const paymentDateInput = document.getElementById('payment_date');
    const sourceIndicator = document.getElementById('source_indicator');
    const bankBalanceIndicator = document.getElementById('bank_balance_indicator');

    let currentLedgerType = '';
    let isNameSelectMode = false;

    // =============== NAME FIELD MODE TOGGLE ===============
    toggleNameModeBtn.addEventListener('click', function() {
        isNameSelectMode = !isNameSelectMode;
        
        if (isNameSelectMode) {
            // Switch to select mode
            nameSelect.classList.remove('d-none');
            nameInput.classList.add('d-none');
            nameInput.removeAttribute('required');
            nameSelect.setAttribute('required', 'required');
            toggleNameModeBtn.innerHTML = '<i class="bi bi-keyboard"></i> Type Name';
            openNamesModalBtn.disabled = false;
            
            // Load entities if account type is selected
            if (paidToSelect.value) {
                loadEntities(paidToSelect.value);
            } else {
                nameSelect.innerHTML = '<option value="">Select Payee Type First</option>';
                sourceIndicator.textContent = 'Please select payee type first';
            }
        } else {
            // Switch to input mode
            nameSelect.classList.add('d-none');
            nameInput.classList.remove('d-none');
            nameSelect.removeAttribute('required');
            nameInput.setAttribute('required', 'required');
            toggleNameModeBtn.innerHTML = '<i class="bi bi-list-ul"></i> Select from List';
            openNamesModalBtn.disabled = true;
            sourceIndicator.textContent = 'Enter payee name manually';
            
            // Clear hidden entity fields
            entityTypeInput.value = '';
            entityIdInput.value = '';
        }
    });

    // =============== BANK ACCOUNT BALANCE DISPLAY ===============
    function updateInitialBankBalance() {
        const selectedOption = acCreditSelect.options[acCreditSelect.selectedIndex];
        if (selectedOption && selectedOption.value) {
            const currency = selectedOption.dataset.currency || 'Tsh';
            const balance = parseFloat(selectedOption.dataset.balance || 0);
            
            const formattedBalance = balance.toLocaleString('en-US', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            });
            
            bankBalanceIndicator.textContent = `Balance: ${currency} ${formattedBalance}`;
        }
    }

    // Update initial bank balance on page load
    updateInitialBankBalance();

    // =============== CLEAR FILTERS ===============
    clearFiltersBtn.addEventListener('click', function() {
        window.location.href = window.location.pathname;
    });

    // =============== ENTITY LOADING FUNCTIONS ===============
    function loadEntities(ledgerType) {
        if (!ledgerType) {
            nameSelect.innerHTML = '<option value="">Select Payee Type First</option>';
            openNamesModalBtn.disabled = true;
            sourceIndicator.textContent = '';
            currentLedgerType = '';
            return;
        }

        currentLedgerType = ledgerType;
        
        // Show loading
        nameSelect.innerHTML = '<option value="">Loading...</option>';
        openNamesModalBtn.disabled = true;
        sourceIndicator.textContent = 'Loading entities...';
        
        // Fetch entities via AJAX
        fetch(`?ajax=get_entities&ledger_type=${encodeURIComponent(ledgerType)}`)
            .then(response => {
                if (!response.ok) {
                    throw new Error('Network response was not ok');
                }
                return response.json();
            })
            .then(entities => {
                nameSelect.innerHTML = '<option value="">Select Entity</option>';
                if (entities.length === 0) {
                    nameSelect.innerHTML = '<option value="">No entities found</option>';
                    openNamesModalBtn.disabled = true;
                    sourceIndicator.textContent = 'No entities available for this type';
                } else {
                    // Create optgroups based on entity type
                    const entityTypes = {};
                    
                    entities.forEach(entity => {
                        const type = entity.type || 'other';
                        if (!entityTypes[type]) {
                            entityTypes[type] = [];
                        }
                        entityTypes[type].push(entity);
                    });
                    
                    // Add optgroups
                    Object.keys(entityTypes).forEach(type => {
                        const optgroup = document.createElement('optgroup');
                        
                        // Set optgroup label
                        switch(type) {
                            case 'agent': optgroup.label = 'Agents'; break;
                            case 'supplier': optgroup.label = 'Suppliers'; break;
                            case 'client': optgroup.label = 'Customers'; break;
                            case 'custodian': optgroup.label = 'Custodians'; break;
                            case 'broker': optgroup.label = 'Brokers'; break;
                            case 'employee': optgroup.label = 'Employees'; break;
                            case 'chart_account': optgroup.label = 'Chart Accounts'; break;
                            default: optgroup.label = type.charAt(0).toUpperCase() + type.slice(1);
                        }
                        
                        entityTypes[type].forEach(entity => {
                            const option = document.createElement('option');
                            option.value = entity.name || entity.code;
                            option.textContent = entity.display_name || entity.name || entity.code;
                            option.dataset.entityType = type;
                            option.dataset.entityId = ledgerType === 'O' ? entity.code : entity.id;
                            option.dataset.entityName = entity.name;
                            option.dataset.accountType = entity.account_type || '';
                            option.dataset.level = entity.level || '';
                            option.dataset.isGroupAccount = entity.is_group_account || '0';
                            optgroup.appendChild(option);
                        });
                        
                        nameSelect.appendChild(optgroup);
                    });
                    
                    openNamesModalBtn.disabled = false;
                    sourceIndicator.textContent = `${entities.length} entities available`;
                    
                    // If only one entity, select it
                    if (entities.length === 1) {
                        const entity = entities[0];
                        nameSelect.value = entity.name || entity.code;
                        updateEntityDetails();
                    }
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
            document.getElementById('account_no').value = '';
            return;
        }
        
        const ledgerType = paidToSelect.value;
        const entityId = selectedOption.dataset.entityId;
        const entityName = selectedOption.value;
        
        // Update hidden inputs
        entityTypeInput.value = selectedOption.dataset.entityType || '';
        entityIdInput.value = entityId || '';
        
        // Also update the name input field
        nameInput.value = entityName;
        
        // Clear account number field
        document.getElementById('account_no').value = '';
        
        // Fetch additional entity details via AJAX
        if (entityId && ledgerType !== 'O') {
            fetch(`?ajax=get_entity_details&ledger_type=${encodeURIComponent(ledgerType)}&entity_id=${encodeURIComponent(entityId)}`)
                .then(response => {
                    if (!response.ok) {
                        throw new Error('Network response was not ok');
                    }
                    return response.json();
                })
                .then(entityDetails => {
                    if (entityDetails && !entityDetails.error) {
                        // Update account number field based on entity type
                        switch(ledgerType) {
                            case 'C': // Customers
                                document.getElementById('account_no').value = entityDetails.cds_account || '';
                                break;
                            case 'E': // Employees
                                document.getElementById('account_no').value = entityDetails.email || '';
                                break;
                            default:
                                document.getElementById('account_no').value = entityDetails.code || '';
                        }
                        
                        // Update source indicator
                        if (ledgerType === 'O') {
                            const accountType = entityDetails.account_type || selectedOption.dataset.accountType;
                            const level = entityDetails.level || selectedOption.dataset.level;
                            sourceIndicator.textContent = `${accountType.charAt(0).toUpperCase() + accountType.slice(1)} Account - Level ${level}`;
                        } else {
                            sourceIndicator.textContent = `Selected: ${entityDetails.name || selectedOption.dataset.entityName}`;
                        }
                    }
                })
                .catch(error => {
                    console.error('Error fetching entity details:', error);
                });
        }
    }

    // =============== EVENT LISTENERS FOR FORM ELEMENTS ===============
    paidToSelect.addEventListener('change', function() {
        if (isNameSelectMode) {
            loadEntities(this.value);
        }
        entityTypeInput.value = '';
        entityIdInput.value = '';
        document.getElementById('account_no').value = '';
    });

    nameSelect.addEventListener('change', updateEntityDetails);
    
    acCreditSelect.addEventListener('change', function() {
        const selectedOption = this.options[this.selectedIndex];
        if (selectedOption && selectedOption.value) {
            const currency = selectedOption.dataset.currency;
            if (currency) {
                currencySelect.value = currency;
            }
            updateInitialBankBalance();
        }
    });
    
    paymentDateInput.addEventListener('change', function() {
        const today = new Date().toISOString().split('T')[0];
        if (this.value > today) {
            this.value = today;
            alert('Payment date cannot be in the future');
        }
    });

    // =============== MODAL FUNCTIONALITY ===============
    // Payment Methods Modal
    const paymentMethodsModal = document.getElementById('paymentMethodsModal');
    if (paymentMethodsModal) {
        paymentMethodsModal.addEventListener('click', function(e) {
            const row = e.target.closest('.clickable-row');
            if (row) {
                const value = row.getAttribute('data-value');
                const text = row.getAttribute('data-text');
                document.getElementById('payment_mode').value = value;
                bootstrap.Modal.getInstance(paymentMethodsModal).hide();
            }
        });

        const paymentMethodsFilter = document.getElementById('paymentMethodsFilter');
        if (paymentMethodsFilter) {
            paymentMethodsFilter.addEventListener('input', function() {
                const filter = this.value.toLowerCase();
                document.querySelectorAll('#paymentMethodsTable tbody tr').forEach(row => {
                    const text = row.textContent.toLowerCase();
                    row.style.display = text.includes(filter) ? '' : 'none';
                });
            });
        }
    }

    // Account Types Modal
    const accountTypesModal = document.getElementById('accountTypesModal');
    if (accountTypesModal) {
        accountTypesModal.addEventListener('click', function(e) {
            const row = e.target.closest('.clickable-row');
            if (row) {
                const value = row.getAttribute('data-value');
                const text = row.getAttribute('data-text');
                paidToSelect.value = value;
                if (isNameSelectMode) {
                    loadEntities(value);
                }
                bootstrap.Modal.getInstance(accountTypesModal).hide();
            }
        });

        const accountTypesFilter = document.getElementById('accountTypesFilter');
        if (accountTypesFilter) {
            accountTypesFilter.addEventListener('input', function() {
                const filter = this.value.toLowerCase();
                document.querySelectorAll('#accountTypesTable tbody tr').forEach(row => {
                    const text = row.textContent.toLowerCase();
                    row.style.display = text.includes(filter) ? '' : 'none';
                });
            });
        }
    }

    // Bank Accounts Modal
    const bankAccountsModal = document.getElementById('bankAccountsModal');
    if (bankAccountsModal) {
        bankAccountsModal.addEventListener('click', function(e) {
            const row = e.target.closest('.clickable-row');
            if (row) {
                const value = row.getAttribute('data-value');
                const text = row.getAttribute('data-text');
                const currency = row.getAttribute('data-currency');
                const balance = row.getAttribute('data-balance');
                const code = row.getAttribute('data-code');
                const bankName = row.getAttribute('data-bank-name');
                const bankNumber = row.getAttribute('data-bank-number');
                
                acCreditSelect.value = value;
                
                if (currency && currencySelect.value !== currency) {
                    currencySelect.value = currency;
                }
                
                updateInitialBankBalance();
                bootstrap.Modal.getInstance(bankAccountsModal).hide();
            }
        });

        const bankAccountsFilter = document.getElementById('bankAccountsFilter');
        if (bankAccountsFilter) {
            bankAccountsFilter.addEventListener('input', function() {
                const filter = this.value.toLowerCase();
                document.querySelectorAll('#bankAccountsTable tbody tr').forEach(row => {
                    const text = row.textContent.toLowerCase();
                    row.style.display = text.includes(filter) ? '' : 'none';
                });
            });
        }
    }

    // Entities Modal
    const entitiesModal = document.getElementById('entitiesModal');
    
    function loadEntitiesIntoModal() {
        const ledgerType = paidToSelect.value;
        
        if (!ledgerType) {
            const entitiesTableBody = document.querySelector('#entitiesTable tbody');
            entitiesTableBody.innerHTML = '<tr><td colspan="4" class="text-center text-muted">Please select a payee type first</td></tr>';
            return;
        }
        
        fetch(`?ajax=get_entities&ledger_type=${encodeURIComponent(ledgerType)}`)
            .then(response => {
                if (!response.ok) {
                    throw new Error('Network response was not ok');
                }
                return response.json();
            })
            .then(entities => {
                const entitiesTableBody = document.querySelector('#entitiesTable tbody');
                entitiesTableBody.innerHTML = '';
                
                if (entities.length === 0) {
                    entitiesTableBody.innerHTML = '<tr><td colspan="4" class="text-center text-muted">No entities found</td></tr>';
                    return;
                }
                
                entities.forEach(entity => {
                    const row = document.createElement('tr');
                    row.className = 'clickable-row';
                    row.style.cursor = 'pointer';
                    
                    // Prepare display information
                    let details = '';
                    const type = entity.type || 'other';
                    
                    switch(type) {
                        case 'agent':
                        case 'supplier':
                        case 'custodian':
                        case 'broker':
                            details = `${entity.contact_person || ''} ${entity.phone || ''}`.trim();
                            break;
                        case 'client':
                            details = `${entity.cds_account || ''} ${entity.client_type || ''}`.trim();
                            break;
                        case 'employee':
                            details = `${entity.email || ''} ${entity.phone || ''}`.trim();
                            break;
                        case 'chart_account':
                            details = `${entity.account_type || ''} - Level ${entity.level || ''}`;
                            break;
                    }
                    
                    row.innerHTML = `
                        <td><strong>${entity.code || ''}</strong></td>
                        <td>${entity.display_name || entity.name || ''}</td>
                        <td><span class="badge bg-secondary">${getEntityTypeLabel(type)}</span></td>
                        <td><small class="text-muted">${details}</small></td>
                    `;
                    
                    row.addEventListener('click', function() {
                        const entityName = entity.name || entity.code;
                        const entityId = ledgerType === 'O' ? entity.code : entity.id;
                        
                        if (isNameSelectMode) {
                            nameSelect.value = entityName;
                            updateEntityDetails();
                        } else {
                            nameInput.value = entityName;
                            entityTypeInput.value = type;
                            entityIdInput.value = entityId;
                            sourceIndicator.textContent = `Selected: ${entityName}`;
                        }
                        bootstrap.Modal.getInstance(entitiesModal).hide();
                    });
                    
                    entitiesTableBody.appendChild(row);
                });
            })
            .catch(error => {
                console.error('Error loading entities for modal:', error);
                const entitiesTableBody = document.querySelector('#entitiesTable tbody');
                entitiesTableBody.innerHTML = '<tr><td colspan="4" class="text-center text-danger">Error loading entities</td></tr>';
            });
    }

    // Open entities modal
    openNamesModalBtn.addEventListener('click', loadEntitiesIntoModal);

    // Filter for entities modal
    const entitiesFilter = document.getElementById('entitiesFilter');
    if (entitiesFilter) {
        entitiesFilter.addEventListener('input', function() {
            const filter = this.value.toLowerCase();
            const entityTypeFilter = document.getElementById('entityTypeFilter').value;
            
            document.querySelectorAll('#entitiesTable tbody tr.clickable-row').forEach(row => {
                const text = row.textContent.toLowerCase();
                const entityType = getEntityTypeFromRow(row);
                
                const matchesFilter = text.includes(filter);
                const matchesType = !entityTypeFilter || entityType === entityTypeFilter;
                
                row.style.display = (matchesFilter && matchesType) ? '' : 'none';
            });
        });
    }

    // Entity type filter change
    const entityTypeFilter = document.getElementById('entityTypeFilter');
    if (entityTypeFilter) {
        entityTypeFilter.addEventListener('change', function() {
            entitiesFilter.dispatchEvent(new Event('input'));
        });
    }

    // Refresh entities button
    const refreshEntitiesBtn = document.getElementById('refreshEntitiesBtn');
    if (refreshEntitiesBtn) {
        refreshEntitiesBtn.addEventListener('click', loadEntitiesIntoModal);
    }

    // =============== HELPER FUNCTIONS ===============
    function getEntityTypeLabel(type) {
        switch(type) {
            case 'agent': return 'Agent';
            case 'supplier': return 'Supplier';
            case 'client': return 'Customer';
            case 'custodian': return 'Custodian';
            case 'broker': return 'Broker';
            case 'employee': return 'Employee';
            case 'chart_account': return 'Chart Account';
            default: return type;
        }
    }

    function getEntityTypeFromRow(row) {
        const typeBadge = row.querySelector('.badge');
        if (typeBadge) {
            const typeText = typeBadge.textContent.toLowerCase();
            if (typeText.includes('agent')) return 'agent';
            if (typeText.includes('supplier')) return 'supplier';
            if (typeText.includes('customer')) return 'client';
            if (typeText.includes('custodian')) return 'custodian';
            if (typeText.includes('broker')) return 'broker';
            if (typeText.includes('employee')) return 'employee';
            if (typeText.includes('chart account')) return 'chart_account';
        }
        return '';
    }

    // =============== RESET FORM ===============
    resetFormBtn.addEventListener('click', function() {
        if (confirm('Are you sure you want to reset the form? All entered data will be lost.')) {
            document.getElementById('paymentForm').reset();
            paymentIdInput.value = '';
            paymentNoInput.value = '';
            entityTypeInput.value = '';
            entityIdInput.value = '';
            
            // Reset name field to input mode
            isNameSelectMode = false;
            nameSelect.classList.add('d-none');
            nameInput.classList.remove('d-none');
            nameSelect.removeAttribute('required');
            nameInput.setAttribute('required', 'required');
            toggleNameModeBtn.innerHTML = '<i class="bi bi-list-ul"></i> Select from List';
            openNamesModalBtn.disabled = true;
            nameSelect.innerHTML = '<option value="">Select Payee Type First</option>';
            
            sourceIndicator.textContent = '';
            generateBtn.classList.remove('d-none');
            updateBtn.classList.add('d-none');
            paymentDateInput.value = new Date().toISOString().split('T')[0];
            updateInitialBankBalance();
        }
    });

    // =============== TABLE ACTIONS ===============
    // View payment
    document.addEventListener('click', function(e) {
        if (e.target.closest('.view-payment')) {
            const paymentId = e.target.closest('.view-payment').getAttribute('data-payment-id');
            viewPayment(paymentId);
        }
    });

    // Edit payment
    document.addEventListener('click', function(e) {
        if (e.target.closest('.edit-payment')) {
            const paymentId = e.target.closest('.edit-payment').getAttribute('data-payment-id');
            editPayment(paymentId);
        }
    });

    // View journal
    document.addEventListener('click', function(e) {
        if (e.target.closest('.view-journal')) {
            const paymentNo = e.target.closest('.view-journal').getAttribute('data-payment-no');
            viewJournalEntries(paymentNo);
        }
    });

    // Print payment
    document.addEventListener('click', function(e) {
        if (e.target.closest('.print-payment')) {
            const paymentId = e.target.closest('.print-payment').getAttribute('data-payment-id');
            printPayment(paymentId);
        }
    });

    // =============== ACTION FUNCTIONS ===============
    async function viewPayment(paymentId) {
        try {
            const response = await fetch(`?ajax=get_payment&payment_id=${encodeURIComponent(paymentId)}`);
            if (!response.ok) throw new Error('Network response was not ok');
            const payment = await response.json();
            
            if (payment.error) {
                alert(payment.error);
                return;
            }
            
            const paymentDetailsContent = document.getElementById('paymentDetailsContent');
            paymentDetailsContent.innerHTML = `
                <div class="container-fluid">
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <div class="card">
                                <div class="card-header bg-light py-2">
                                    <h6 class="mb-0"><i class="bi bi-info-circle me-2"></i>Basic Information</h6>
                                </div>
                                <div class="card-body">
                                    <table class="table table-sm table-borderless">
                                        <tr>
                                            <th width="40%">Payment No:</th>
                                            <td><strong class="text-danger">${payment.payment_no || ''}</strong></td>
                                        </tr>
                                        <tr>
                                            <th>Payment Date:</th>
                                            <td>${formatDate(payment.payment_date)}</td>
                                        </tr>
                                        <tr>
                                            <th>Payment Mode:</th>
                                            <td>${payment.payment_method_desc || payment.payment_mode || ''}</td>
                                        </tr>
                                        <tr>
                                            <th>Payee Type:</th>
                                            <td>${payment.paid_to_desc || payment.paid_to || ''}</td>
                                        </tr>
                                        <tr>
                                            <th>Payee Name:</th>
                                            <td>${payment.name || ''}</td>
                                        </tr>
                                        <tr>
                                            <th>Payee ID:</th>
                                            <td>${payment.name_id || 'N/A'}</td>
                                        </tr>
                                    </table>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="card">
                                <div class="card-header bg-light py-2">
                                    <h6 class="mb-0"><i class="bi bi-currency-exchange me-2"></i>Financial Information</h6>
                                </div>
                                <div class="card-body">
                                    <table class="table table-sm table-borderless">
                                        <tr>
                                            <th width="40%">Amount:</th>
                                            <td><strong class="text-danger">${formatCurrency(payment.amount, payment.currency)}</strong></td>
                                        </tr>
                                        <tr>
                                            <th>Currency:</th>
                                            <td>${payment.currency || 'Tsh'}</td>
                                        </tr>
                                        <tr>
                                            <th>Bank Account:</th>
                                            <td>${payment.bank_name || ''} ${payment.bank_account_number ? '(' + payment.bank_account_number + ')' : ''}</td>
                                        </tr>
                                        <tr>
                                            <th>Bank Balance:</th>
                                            <td>${payment.bank_current_balance ? formatCurrency(payment.bank_current_balance, payment.currency) : 'N/A'}</td>
                                        </tr>
                                        <tr>
                                            <th>Financial Record:</th>
                                            <td>
                                                <span class="badge ${payment.record_in_financial === 'yes' ? 'bg-success' : 'bg-secondary'}">
                                                    ${payment.record_in_financial === 'yes' ? 'Recorded' : 'Not Recorded'}
                                                </span>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th>Status:</th>
                                            <td>
                                                <span class="badge ${payment.status === 'active' ? 'bg-success' : 'bg-warning'}">
                                                    ${payment.status || 'active'}
                                                </span>
                                            </td>
                                        </tr>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-12">
                            <div class="card">
                                <div class="card-header bg-light py-2">
                                    <h6 class="mb-0"><i class="bi bi-card-text me-2"></i>Additional Details</h6>
                                </div>
                                <div class="card-body">
                                    <table class="table table-sm table-borderless">
                                        <tr>
                                            <th width="20%">Description:</th>
                                            <td>${payment.narration || 'No description provided'}</td>
                                        </tr>
                                        <tr>
                                            <th>Account No:</th>
                                            <td>${payment.account_no || 'N/A'}</td>
                                        </tr>
                                        <tr>
                                            <th>Cheque No:</th>
                                            <td>${payment.cheque_no || 'N/A'}</td>
                                        </tr>
                                        <tr>
                                            <th>Created By:</th>
                                            <td>${payment.created_by_username || ''} (${payment.created_by || 'System'})</td>
                                        </tr>
                                        <tr>
                                            <th>Created At:</th>
                                            <td>${formatDateTime(payment.created_at)}</td>
                                        </tr>
                                        ${payment.updated_at ? `
                                        <tr>
                                            <th>Updated At:</th>
                                            <td>${formatDateTime(payment.updated_at)}</td>
                                        </tr>
                                        ` : ''}
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            `;
            
            const printBtn = document.getElementById('printPaymentBtn');
            printBtn.setAttribute('data-payment-id', paymentId);
            printBtn.setAttribute('data-payment-no', payment.payment_no);
            
            const viewModal = new bootstrap.Modal(document.getElementById('viewPaymentModal'));
            viewModal.show();
        } catch (error) {
            console.error('Error fetching payment:', error);
            alert('Error loading payment details. Please try again.');
        }
    }

    async function editPayment(paymentId) {
        try {
            const response = await fetch(`?ajax=get_payment&payment_id=${encodeURIComponent(paymentId)}`);
            if (!response.ok) throw new Error('Network response was not ok');
            const payment = await response.json();
            
            if (payment.error) {
                alert(payment.error);
                return;
            }
            
            // Populate form fields
            paymentDateInput.value = payment.payment_date || '';
            document.getElementById('payment_mode').value = payment.payment_mode || '';
            paidToSelect.value = payment.paid_to || '';
            
            // Set name based on whether it's in the database or not
            nameInput.value = payment.name || '';
            
            paymentIdInput.value = paymentId;
            paymentNoInput.value = payment.payment_no || '';
            
            // Set other fields
            document.getElementById('record_yes').checked = payment.record_in_financial === 'yes';
            document.getElementById('record_no').checked = payment.record_in_financial === 'no';
            acCreditSelect.value = payment.ac_credit || '';
            currencySelect.value = payment.currency || 'Tsh';
            document.getElementById('account_no').value = payment.account_no || '';
            document.getElementById('amount').value = payment.amount || '';
            document.getElementById('cheque_no').value = payment.cheque_no || '';
            document.getElementById('narration').value = payment.narration || '';
            
            // Set entity info if available
            entityTypeInput.value = payment.source_type || '';
            entityIdInput.value = payment.name_id || '';
            
            updateInitialBankBalance();
            generateBtn.classList.add('d-none');
            updateBtn.classList.remove('d-none');
            
            document.getElementById('paymentFormContainer').scrollIntoView({ behavior: 'smooth' });
            alert('Payment loaded for editing. Please review and update the details.');
        } catch (error) {
            console.error('Error fetching payment for edit:', error);
            alert('Error loading payment for editing. Please try again.');
        }
    }

    async function viewJournalEntries(paymentNo) {
        try {
            const response = await fetch(`?ajax=get_journal_entries&payment_no=${encodeURIComponent(paymentNo)}`);
            if (!response.ok) throw new Error('Network response was not ok');
            const journalEntries = await response.json();
            
            const journalDetailsContent = document.getElementById('journalDetailsContent');
            
            if (journalEntries.length === 0) {
                journalDetailsContent.innerHTML = `
                    <div class="text-center py-5">
                        <i class="bi bi-journal-x" style="font-size: 3rem; color: #6c757d;"></i>
                        <h5 class="mt-3 text-muted">No Journal Entries Found</h5>
                        <p class="text-muted">No journal entries have been created for payment ${paymentNo}</p>
                    </div>
                `;
            } else {
                let totalDebit = 0;
                let totalCredit = 0;
                
                const rows = journalEntries.map(journal => {
                    totalDebit += parseFloat(journal.debit_amount) || 0;
                    totalCredit += parseFloat(journal.credit_amount) || 0;
                    
                    return `
                        <tr>
                            <td><code>${journal.journal_no || ''}</code></td>
                            <td>${formatDate(journal.transaction_date)}</td>
                            <td>${journal.account_code || ''}</td>
                            <td>${journal.account_name || ''}</td>
                            <td class="text-danger fw-bold">${journal.debit_amount > 0 ? formatNumber(journal.debit_amount) : '-'}</td>
                            <td class="text-success fw-bold">${journal.credit_amount > 0 ? formatNumber(journal.credit_amount) : '-'}</td>
                            <td>${journal.description || ''}</td>
                        </tr>
                    `;
                }).join('');
                
                journalDetailsContent.innerHTML = `
                    <div class="container-fluid">
                        <div class="alert alert-info mb-3">
                            <i class="bi bi-info-circle me-2"></i>
                            Journal entries for payment <strong>${paymentNo}</strong>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-sm table-striped">
                                <thead>
                                    <tr>
                                        <th>Journal No</th>
                                        <th>Date</th>
                                        <th>Account Code</th>
                                        <th>Account Name</th>
                                        <th>Debit</th>
                                        <th>Credit</th>
                                        <th>Description</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    ${rows}
                                </tbody>
                                <tfoot class="table-dark">
                                    <tr>
                                        <td colspan="4" class="text-end fw-bold">Totals:</td>
                                        <td class="text-danger fw-bold">${formatNumber(totalDebit)}</td>
                                        <td class="text-success fw-bold">${formatNumber(totalCredit)}</td>
                                        <td>
                                            <span class="badge ${Math.abs(totalDebit - totalCredit) < 0.01 ? 'bg-success' : 'bg-danger'}">
                                                ${Math.abs(totalDebit - totalCredit) < 0.01 ? 'Balanced' : 'Unbalanced'}
                                            </span>
                                        </td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>
                `;
            }
            
            const journalModal = new bootstrap.Modal(document.getElementById('viewJournalModal'));
            journalModal.show();
        } catch (error) {
            console.error('Error fetching journal entries:', error);
            alert('Error loading journal entries. Please try again.');
        }
    }

    function printPayment(paymentId) {
        const printWindow = window.open(`print_payment.php?payment_id=${paymentId}`, '_blank');
        if (!printWindow) {
            alert('Please allow pop-ups to print payments.');
        }
    }

    // Print payment button in modal
    document.getElementById('printPaymentBtn').addEventListener('click', function() {
        const paymentId = this.getAttribute('data-payment-id');
        printPayment(paymentId);
    });

    // =============== FORM VALIDATION ===============
    document.getElementById('paymentForm').addEventListener('submit', function(e) {
        const amount = parseFloat(document.getElementById('amount').value);
        if (amount <= 0) {
            alert('Amount must be greater than 0');
            e.preventDefault();
            return;
        }
        
        if (amount > 999999999.99) {
            alert('Amount is too large. Maximum amount is 999,999,999.99');
            e.preventDefault();
            return;
        }
        
        const paymentDate = document.getElementById('payment_date').value;
        if (paymentDate > new Date().toISOString().split('T')[0]) {
            alert('Payment date cannot be in the future');
            e.preventDefault();
            return;
        }
        
        const isUpdate = updateBtn.classList.contains('d-none') === false;
        const action = isUpdate ? 'update' : 'create';
        
        if (!confirm(`Are you sure you want to ${action} this payment?`)) {
            e.preventDefault();
        }
    });

    // =============== HELPER FUNCTIONS ===============
    function formatDate(dateString) {
        if (!dateString) return '';
        const date = new Date(dateString);
        return date.toLocaleDateString('en-US', { 
            year: 'numeric', 
            month: 'short', 
            day: 'numeric' 
        });
    }

    function formatDateTime(dateTimeString) {
        if (!dateTimeString) return '';
        const date = new Date(dateTimeString);
        return date.toLocaleString('en-US', { 
            year: 'numeric', 
            month: 'short', 
            day: 'numeric',
            hour: '2-digit',
            minute: '2-digit'
        });
    }

    function formatCurrency(amount, currency) {
        const formattedAmount = formatNumber(amount);
        const currencySymbol = getCurrencySymbol(currency);
        return `${currencySymbol} ${formattedAmount}`;
    }

    function formatNumber(number) {
        return parseFloat(number).toLocaleString('en-US', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
    }

    function getCurrencySymbol(currency) {
        switch(currency) {
            case 'USD': return '$';
            case 'Ksh': return 'KSh';
            case 'UGsh': return 'UGX';
            case 'Tsh': return 'TSh';
            default: return 'TSh';
        }
    }

    // =============== INITIALIZATION ===============
    // Start with input mode by default
    isNameSelectMode = false;
    nameSelect.classList.add('d-none');
    nameInput.classList.remove('d-none');
    nameSelect.removeAttribute('required');
    nameInput.setAttribute('required', 'required');
    toggleNameModeBtn.innerHTML = '<i class="bi bi-list-ul"></i> Select from List';
    openNamesModalBtn.disabled = true;
    
    setTimeout(() => {
        paymentDateInput.focus();
    }, 100);
});
</script>
<?php include '../includes/footer.php'; ?>