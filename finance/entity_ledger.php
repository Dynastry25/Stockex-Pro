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
$entity_type = isset($_GET['type']) ? $_GET['type'] : null;
$entity_id = isset($_GET['id']) ? $_GET['id'] : null;

if (!$entity_type || !$entity_id) {
    header('Location: debtors.php?error=Invalid entity parameters');
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

// Get entity details
function getEntityDetails($db, $entity_type, $entity_id) {
    try {
        switch ($entity_type) {
            case 'client':
                $stmt = $db->prepare("SELECT id, client_name as name, cds_account as code, phone, email, client_type, status, is_active, created_at FROM clients WHERE id = ?");
                $stmt->execute([$entity_id]);
                $entity = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($entity) {
                    $entity['type_label'] = 'Client';
                    $entity['code_label'] = 'CDS Account';
                    $entity['icon'] = 'bi-person-badge';
                    $entity['color'] = '#4F46E5';
                }
                return $entity;
                
            case 'custodian':
                $stmt = $db->prepare("SELECT id, custodian_name as name, custodian_code as code, contact_person, phone, email, status, is_active, created_at FROM custodians WHERE id = ?");
                $stmt->execute([$entity_id]);
                $entity = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($entity) {
                    $entity['type_label'] = 'Custodian';
                    $entity['code_label'] = 'Custodian Code';
                    $entity['icon'] = 'bi-shield-check';
                    $entity['color'] = '#0891B2';
                }
                return $entity;
                
            case 'employee':
                $stmt = $db->prepare("SELECT id, full_name as name, username as code, email, phone, role, status, is_active, created_at FROM users WHERE id = ?");
                $stmt->execute([$entity_id]);
                $entity = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($entity) {
                    $entity['type_label'] = 'Employee';
                    $entity['code_label'] = 'Username';
                    $entity['icon'] = 'bi-person-workspace';
                    $entity['color'] = '#059669';
                }
                return $entity;
                
            case 'agent':
                $stmt = $db->prepare("SELECT id, name, agent_code as code, contact_person, phone, email, status, is_active, created_at FROM agents WHERE id = ?");
                $stmt->execute([$entity_id]);
                $entity = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($entity) {
                    $entity['type_label'] = 'Agent';
                    $entity['code_label'] = 'Agent Code';
                    $entity['icon'] = 'bi-person-rolodex';
                    $entity['color'] = '#D97706';
                }
                return $entity;
                
            case 'broker':
                $stmt = $db->prepare("SELECT id, broker_name as name, broker_code as code, contact_person, phone, email, status, is_active, created_at FROM brokers WHERE id = ?");
                $stmt->execute([$entity_id]);
                $entity = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($entity) {
                    $entity['type_label'] = 'Broker';
                    $entity['code_label'] = 'Broker Code';
                    $entity['icon'] = 'bi-graph-up';
                    $entity['color'] = '#DC2626';
                }
                return $entity;
                
            case 'supplier':
                $stmt = $db->prepare("SELECT id, name, supplier_code as code, contact_person, phone, email, status, is_active, created_at FROM suppliers WHERE id = ?");
                $stmt->execute([$entity_id]);
                $entity = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($entity) {
                    $entity['type_label'] = 'Supplier';
                    $entity['code_label'] = 'Supplier Code';
                    $entity['icon'] = 'bi-truck';
                    $entity['color'] = '#7C3AED';
                }
                return $entity;
                
            case 'chart_account':
                $stmt = $db->prepare("SELECT account_code as id, account_name as name, account_code as code, account_type, level, is_active, created_at FROM chart_of_accounts WHERE account_code = ?");
                $stmt->execute([$entity_id]);
                $entity = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($entity) {
                    $entity['type_label'] = 'Chart Account';
                    $entity['code_label'] = 'Account Code';
                    $entity['icon'] = 'bi-journal-bookmark';
                    $entity['color'] = '#6D28D9';
                }
                return $entity;
                
            case 'bank_account':
                $stmt = $db->prepare("SELECT id, account_name as name, account_number as code, bank_name, currency, current_balance, status, is_active, created_at FROM banks_accounts WHERE id = ?");
                $stmt->execute([$entity_id]);
                $entity = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($entity) {
                    $entity['type_label'] = 'Bank Account';
                    $entity['code_label'] = 'Account Number';
                    $entity['icon'] = 'bi-bank';
                    $entity['color'] = '#0D9488';
                }
                return $entity;
                
            default:
                return null;
        }
    } catch (Exception $e) {
        error_log("Error getting entity details: " . $e->getMessage());
        return null;
    }
}

// Get all transactions for an entity
function getEntityTransactions($db, $entity_type, $entity_id, $start_date = null, $end_date = null, $search = '') {
    $transactions = [];
    $ledger_code = getLedgerCode($entity_type);
    
    // Get receipts
    $receipts_query = "SELECT receipt_date as transaction_date, 'receipt' as transaction_type, 
                       receipt_no as reference, narration as description, 
                       amount, currency, account_no as account, 
                       'receipts' as source_table, id as source_id,
                       created_at, created_by
                       FROM receipts 
                       WHERE (account_of = ? OR (account_of = 'O' AND source_type = ?))
                       AND name_id = ? 
                       AND record_in_financial = 'yes'";
    $receipts_params = [$ledger_code, $entity_type, $entity_id];
    
    if ($start_date && $end_date) {
        $receipts_query .= " AND receipt_date BETWEEN ? AND ?";
        $receipts_params[] = $start_date;
        $receipts_params[] = $end_date;
    }
    
    if (!empty($search)) {
        $receipts_query .= " AND (receipt_no LIKE ? OR narration LIKE ?)";
        $search_param = "%$search%";
        $receipts_params[] = $search_param;
        $receipts_params[] = $search_param;
    }
    
    $receipts_query .= " ORDER BY receipt_date DESC";
    
    $receipts_stmt = $db->prepare($receipts_query);
    $receipts_stmt->execute($receipts_params);
    $receipts = $receipts_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($receipts as $receipt) {
        $transactions[] = $receipt;
    }
    
    // Get payments
    $payments_query = "SELECT payment_date as transaction_date, 'payment' as transaction_type,
                       payment_no as reference, narration as description,
                       amount, currency, account_no as account,
                       'payments' as source_table, id as source_id,
                       created_at, created_by
                       FROM payments 
                       WHERE (paid_to = ? OR (paid_to = 'O' AND source_type = ?))
                       AND name_id = ? 
                       AND record_in_financial = 'yes'";
    $payments_params = [$ledger_code, $entity_type, $entity_id];
    
    if ($start_date && $end_date) {
        $payments_query .= " AND payment_date BETWEEN ? AND ?";
        $payments_params[] = $start_date;
        $payments_params[] = $end_date;
    }
    
    if (!empty($search)) {
        $payments_query .= " AND (payment_no LIKE ? OR narration LIKE ?)";
        $search_param = "%$search%";
        $payments_params[] = $search_param;
        $payments_params[] = $search_param;
    }
    
    $payments_query .= " ORDER BY payment_date DESC";
    
    $payments_stmt = $db->prepare($payments_query);
    $payments_stmt->execute($payments_params);
    $payments = $payments_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($payments as $payment) {
        $transactions[] = $payment;
    }
    
    // Get GL entries
    try {
        if ($entity_type === 'chart_account') {
            $gl_query = "SELECT gl.transaction_date, 'gl_entry' as transaction_type,
                         gl.reference_no as reference, gl.description,
                         gl.debit_amount, gl.credit_amount, 'TZS' as currency,
                         gl.account_code as account,
                         'general_ledger' as source_table, gl.id as source_id,
                         gl.created_at, gl.created_by
                         FROM general_ledger gl
                         WHERE gl.account_code = ?";
            $gl_params = [$entity_id];
        } elseif ($entity_type === 'client') {
            $gl_query = "SELECT gl.transaction_date, 'gl_entry' as transaction_type,
                         gl.reference_no as reference, gl.description,
                         gl.debit_amount, gl.credit_amount, 'TZS' as currency,
                         gl.account_code as account,
                         'general_ledger' as source_table, gl.id as source_id,
                         gl.created_at, gl.created_by
                         FROM general_ledger gl
                         LEFT JOIN trades t ON gl.reference_no = t.trade_reference
                         WHERE t.client_cds_account = ? OR t.client_name = ?";
            $gl_params = [$entity_id, $entity_id];
        } else {
            $gl_query = null;
            $gl_params = [];
        }
        
        if ($gl_query) {
            if ($start_date && $end_date) {
                $gl_query .= " AND gl.transaction_date BETWEEN ? AND ?";
                $gl_params[] = $start_date;
                $gl_params[] = $end_date;
            }
            
            if (!empty($search)) {
                $gl_query .= " AND (gl.reference_no LIKE ? OR gl.description LIKE ?)";
                $search_param = "%$search%";
                $gl_params[] = $search_param;
                $gl_params[] = $search_param;
            }
            
            $gl_query .= " ORDER BY gl.transaction_date DESC";
            
            $gl_stmt = $db->prepare($gl_query);
            $gl_stmt->execute($gl_params);
            $gl_entries = $gl_stmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($gl_entries as $gl) {
                if ((float)$gl['debit_amount'] > 0) {
                    $gl['amount'] = (float)$gl['debit_amount'];
                    $gl['amount_type'] = 'debit';
                } elseif ((float)$gl['credit_amount'] > 0) {
                    $gl['amount'] = (float)$gl['credit_amount'];
                    $gl['amount_type'] = 'credit';
                } else {
                    $gl['amount'] = 0;
                    $gl['amount_type'] = 'none';
                }
                $transactions[] = $gl;
            }
        }
    } catch (Exception $e) {
        error_log("Error getting GL entries: " . $e->getMessage());
    }
    
    // Sort transactions by date (newest first)
    usort($transactions, function($a, $b) {
        return strtotime($b['transaction_date']) - strtotime($a['transaction_date']);
    });
    
    // Calculate running balance (oldest to newest)
    $running_balance = 0;
    $debit_total = 0;
    $credit_total = 0;
    
    // Reverse for balance calculation
    $reversed = array_reverse($transactions);
    
    foreach ($reversed as &$transaction) {
        if ($entity_type === 'bank_account') {
            // Bank accounts: receipts = inflow (credit), payments = outflow (debit)
            if ($transaction['transaction_type'] === 'receipt') {
                $amount = (float)$transaction['amount'];
                $running_balance += $amount;
                $credit_total += $amount;
                $transaction['debit'] = 0;
                $transaction['credit'] = $amount;
                $transaction['balance_impact'] = $amount;
            } elseif ($transaction['transaction_type'] === 'payment') {
                $amount = (float)$transaction['amount'];
                $running_balance -= $amount;
                $debit_total += $amount;
                $transaction['debit'] = $amount;
                $transaction['credit'] = 0;
                $transaction['balance_impact'] = -$amount;
            } else {
                // GL entries
                if (isset($transaction['amount_type']) && $transaction['amount_type'] === 'debit') {
                    $amount = (float)$transaction['amount'];
                    $running_balance -= $amount;
                    $debit_total += $amount;
                    $transaction['debit'] = $amount;
                    $transaction['credit'] = 0;
                    $transaction['balance_impact'] = -$amount;
                } elseif (isset($transaction['amount_type']) && $transaction['amount_type'] === 'credit') {
                    $amount = (float)$transaction['amount'];
                    $running_balance += $amount;
                    $credit_total += $amount;
                    $transaction['debit'] = 0;
                    $transaction['credit'] = $amount;
                    $transaction['balance_impact'] = $amount;
                } else {
                    $transaction['debit'] = 0;
                    $transaction['credit'] = 0;
                    $transaction['balance_impact'] = 0;
                }
            }
        } else {
            // Non-bank entities: receipts = credit (entity owes us), payments = debit (we owe entity)
            if ($transaction['transaction_type'] === 'receipt') {
                $amount = (float)$transaction['amount'];
                $running_balance -= $amount;
                $credit_total += $amount;
                $transaction['debit'] = 0;
                $transaction['credit'] = $amount;
                $transaction['balance_impact'] = -$amount;
            } elseif ($transaction['transaction_type'] === 'payment') {
                $amount = (float)$transaction['amount'];
                $running_balance += $amount;
                $debit_total += $amount;
                $transaction['debit'] = $amount;
                $transaction['credit'] = 0;
                $transaction['balance_impact'] = $amount;
            } else {
                // GL entries
                if (isset($transaction['amount_type']) && $transaction['amount_type'] === 'debit') {
                    $amount = (float)$transaction['amount'];
                    $running_balance += $amount;
                    $debit_total += $amount;
                    $transaction['debit'] = $amount;
                    $transaction['credit'] = 0;
                    $transaction['balance_impact'] = $amount;
                } elseif (isset($transaction['amount_type']) && $transaction['amount_type'] === 'credit') {
                    $amount = (float)$transaction['amount'];
                    $running_balance -= $amount;
                    $credit_total += $amount;
                    $transaction['debit'] = 0;
                    $transaction['credit'] = $amount;
                    $transaction['balance_impact'] = -$amount;
                } else {
                    $transaction['debit'] = 0;
                    $transaction['credit'] = 0;
                    $transaction['balance_impact'] = 0;
                }
            }
        }
        $transaction['running_balance'] = $running_balance;
    }
    
    // Reverse back to newest first for display
    $transactions = array_reverse($reversed);
    
    return [
        'transactions' => $transactions,
        'debit_total' => $debit_total,
        'credit_total' => $credit_total,
        'balance' => $running_balance
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
    header('Location: debtors.php?error=Entity not found');
    exit;
}

// Get transactions
$ledger_data = getEntityTransactions($db, $entity_type, $entity_id, $start_date, $end_date, $search);
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
                <th style="width:10%;">Date</th>
                <th style="width:12%;">Reference</th>
                <th style="width:22%;">Description</th>
                <th style="width:12%;">Account</th>
                <th style="width:10%;">Type</th>
                <th style="width:11%;text-align:right;">Debit</th>
                <th style="width:11%;text-align:right;">Credit</th>
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
        $type = $t['transaction_type'] ?? '';
        $type_label = $type === 'receipt' ? 'Receipt' : ($type === 'payment' ? 'Payment' : 'GL Entry');
        $debit = isset($t['debit']) && $t['debit'] > 0 ? number_format($t['debit'], 2) : '';
        $credit = isset($t['credit']) && $t['credit'] > 0 ? number_format($t['credit'], 2) : '';
        $balance_val = isset($t['running_balance']) ? number_format($t['running_balance'], 2) : '';
        $balance_color = $t['running_balance'] > 0 ? 'color:#00b050;' : ($t['running_balance'] < 0 ? 'color:#c00000;' : '');
        
        $html .= '<tr>
            <td>' . $date . '</td>
            <td>' . htmlspecialchars($ref) . '</td>
            <td>' . htmlspecialchars($desc) . '</td>
            <td>' . htmlspecialchars($account) . '</td>
            <td>' . $type_label . '</td>
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
    echo '<td>Type</td>';
    echo '<td>Debit</td>';
    echo '<td>Credit</td>';
    echo '<td>Balance</td>';
    echo '</tr>';
    
    foreach ($transactions as $t) {
        $date = isset($t['transaction_date']) ? date('d/m/Y', strtotime($t['transaction_date'])) : '';
        $ref = $t['reference'] ?? '';
        $desc = $t['description'] ?? '';
        $account = $t['account'] ?? '';
        $type = $t['transaction_type'] ?? '';
        $type_label = $type === 'receipt' ? 'Receipt' : ($type === 'payment' ? 'Payment' : 'GL Entry');
        $debit = isset($t['debit']) && $t['debit'] > 0 ? number_format($t['debit'], 2) : '';
        $credit = isset($t['credit']) && $t['credit'] > 0 ? number_format($t['credit'], 2) : '';
        $balance_val = isset($t['running_balance']) ? number_format($t['running_balance'], 2) : '';
        
        echo '<tr>';
        echo '<td>' . $date . '</td>';
        echo '<td>' . htmlspecialchars($ref) . '</td>';
        echo '<td>' . htmlspecialchars($desc) . '</td>';
        echo '<td>' . htmlspecialchars($account) . '</td>';
        echo '<td>' . $type_label . '</td>';
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

        .type-badge {
            padding: 3px 12px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }

        .type-badge.receipt {
            background: #D1FAE5;
            color: #065F46;
        }

        .type-badge.payment {
            background: #FEE2E2;
            color: #991B1B;
        }

        .type-badge.gl_entry {
            background: #E0E7FF;
            color: #3730A3;
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
                                <th>Type</th>
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
                                $type = $transaction['transaction_type'] ?? '';
                                $type_label = $type === 'receipt' ? 'Receipt' : ($type === 'payment' ? 'Payment' : 'GL Entry');
                                $type_class = $type === 'receipt' ? 'receipt' : ($type === 'payment' ? 'payment' : 'gl_entry');
                                
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
                                        <span class="type-badge <?php echo $type_class; ?>">
                                            <?php echo $type_label; ?>
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
