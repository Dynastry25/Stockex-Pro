<?php
require_once '../config/config.php';
require_once '../auth/auth_middleware.php';
require_once '../tcpdf/tcpdf.php';

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
                $stmt = $db->prepare("SELECT id, client_name as name, cds_account as code, phone, email, client_type, status, is_active FROM clients WHERE id = ?");
                $stmt->execute([$entity_id]);
                $entity = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($entity) {
                    $entity['type_label'] = 'Client';
                    $entity['code_label'] = 'CDS Account';
                }
                return $entity;
                
            case 'custodian':
                $stmt = $db->prepare("SELECT id, custodian_name as name, custodian_code as code, contact_person, phone, email, status, is_active FROM custodians WHERE id = ?");
                $stmt->execute([$entity_id]);
                $entity = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($entity) {
                    $entity['type_label'] = 'Custodian';
                    $entity['code_label'] = 'Custodian Code';
                }
                return $entity;
                
            case 'employee':
                $stmt = $db->prepare("SELECT id, full_name as name, username as code, email, phone, role, status, is_active FROM users WHERE id = ?");
                $stmt->execute([$entity_id]);
                $entity = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($entity) {
                    $entity['type_label'] = 'Employee';
                    $entity['code_label'] = 'Username';
                }
                return $entity;
                
            case 'agent':
                $stmt = $db->prepare("SELECT id, name, agent_code as code, contact_person, phone, email, status, is_active FROM agents WHERE id = ?");
                $stmt->execute([$entity_id]);
                $entity = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($entity) {
                    $entity['type_label'] = 'Agent';
                    $entity['code_label'] = 'Agent Code';
                }
                return $entity;
                
            case 'broker':
                $stmt = $db->prepare("SELECT id, broker_name as name, broker_code as code, contact_person, phone, email, status, is_active FROM brokers WHERE id = ?");
                $stmt->execute([$entity_id]);
                $entity = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($entity) {
                    $entity['type_label'] = 'Broker';
                    $entity['code_label'] = 'Broker Code';
                }
                return $entity;
                
            case 'supplier':
                $stmt = $db->prepare("SELECT id, name, supplier_code as code, contact_person, phone, email, status, is_active FROM suppliers WHERE id = ?");
                $stmt->execute([$entity_id]);
                $entity = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($entity) {
                    $entity['type_label'] = 'Supplier';
                    $entity['code_label'] = 'Supplier Code';
                }
                return $entity;
                
            case 'chart_account':
                $stmt = $db->prepare("SELECT account_code as id, account_name as name, account_code as code, account_type, level, is_active FROM chart_of_accounts WHERE account_code = ?");
                $stmt->execute([$entity_id]);
                $entity = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($entity) {
                    $entity['type_label'] = 'Chart Account';
                    $entity['code_label'] = 'Account Code';
                }
                return $entity;
                
            case 'bank_account':
                $stmt = $db->prepare("SELECT id, account_name as name, account_number as code, bank_name, currency, current_balance, status, is_active FROM banks_accounts WHERE id = ?");
                $stmt->execute([$entity_id]);
                $entity = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($entity) {
                    $entity['type_label'] = 'Bank Account';
                    $entity['code_label'] = 'Account Number';
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
    $pdf = new TCPDF('P', PDF_UNIT, 'A4', true, 'UTF-8', false);
    $pdf->SetCreator(PDF_CREATOR);
    $pdf->SetAuthor('Financial System');
    $pdf->SetTitle('Ledger - ' . $entity['name']);
    $pdf->SetHeaderData('', 0, 'General Ledger', $entity['name']);
    $pdf->setHeaderFont(Array(PDF_FONT_NAME_MAIN, '', PDF_FONT_SIZE_MAIN));
    $pdf->setFooterFont(Array(PDF_FONT_NAME_DATA, '', PDF_FONT_SIZE_DATA));
    $pdf->SetDefaultMonospacedFont(PDF_FONT_MONOSPACED);
    $pdf->SetMargins(10, 25, 10);
    $pdf->SetHeaderMargin(10);
    $pdf->SetFooterMargin(10);
    $pdf->SetAutoPageBreak(TRUE, 15);
    $pdf->AddPage();
    
    // Title
    $pdf->SetFont('helvetica', 'B', 16);
    $pdf->Cell(0, 10, 'GENERAL LEDGER', 0, 1, 'C');
    $pdf->SetFont('helvetica', 'B', 12);
    $pdf->Cell(0, 8, $entity['name'] . ' (' . $entity['type_label'] . ')', 0, 1, 'C');
    $pdf->SetFont('helvetica', '', 10);
    $pdf->Cell(0, 6, 'Code: ' . $entity['code'], 0, 1);
    $pdf->Cell(0, 6, 'As of: ' . date('d/m/Y', strtotime($as_of_date)), 0, 1);
    if ($start_date && $end_date) {
        $pdf->Cell(0, 6, 'Period: ' . date('d/m/Y', strtotime($start_date)) . ' to ' . date('d/m/Y', strtotime($end_date)), 0, 1);
    }
    $pdf->Ln(5);
    
    // Balance Summary
    $pdf->SetFont('helvetica', 'B', 11);
    $pdf->Cell(0, 8, 'BALANCE SUMMARY', 0, 1);
    $pdf->SetFont('helvetica', '', 10);
    
    $balance_class = $balance > 0 ? 'Credit' : ($balance < 0 ? 'Debit' : 'Settled');
    $balance_abs = abs($balance);
    
    $summary_html = '<table border="1" cellpadding="4" cellspacing="0">
        <tr>
            <td><strong>Total Debit Transactions</strong></td>
            <td align="right">' . number_format($debit_total, 2) . '</td>
        </tr>
        <tr>
            <td><strong>Total Credit Transactions</strong></td>
            <td align="right">' . number_format($credit_total, 2) . '</td>
        </tr>
        <tr style="background-color:#f2f2f2;">
            <td><strong>Balance (' . $balance_class . ')</strong></td>
            <td align="right" style="' . ($balance > 0 ? 'color:#00b050;' : ($balance < 0 ? 'color:#c00000;' : '')) . '"><strong>' . number_format($balance_abs, 2) . '</strong></td>
        </tr>
    </table>';
    
    $pdf->writeHTML($summary_html, true, false, true, false, '');
    $pdf->Ln(5);
    
    // Transactions Table
    $pdf->SetFont('helvetica', 'B', 11);
    $pdf->Cell(0, 8, 'TRANSACTION HISTORY', 0, 1);
    $pdf->SetFont('helvetica', '', 9);
    
    $html = '<table border="1" cellpadding="3" cellspacing="0">
        <thead>
            <tr style="background-color:#f2f2f2; font-weight:bold;">
                <th width="12%">Date</th>
                <th width="15%">Reference</th>
                <th width="25%">Description</th>
                <th width="10%">Account</th>
                <th width="10%">Type</th>
                <th width="13%">Debit</th>
                <th width="13%">Credit</th>
                <th width="13%">Balance</th>
            </tr>
        </thead>
        <tbody>';
    
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
        $balance_color = $t['running_balance'] > 0 ? 'color:#00b050;' : ($t['running_balance'] < 0 ? 'color:#c00000;' : '');
        
        $html .= '<tr>
            <td>' . $date . '</td>
            <td>' . htmlspecialchars($ref) . '</td>
            <td>' . htmlspecialchars($desc) . '</td>
            <td>' . htmlspecialchars($account) . '</td>
            <td>' . $type_label . '</td>
            <td align="right" style="color:#c00000;">' . $debit . '</td>
            <td align="right" style="color:#00b050;">' . $credit . '</td>
            <td align="right" style="' . $balance_color . '">' . $balance_val . '</td>
        </tr>';
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

<style>
    .entity-header-card {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        border-radius: 10px;
        padding: 20px;
        margin-bottom: 20px;
    }
    
    .entity-header-card h2 {
        color: white;
        margin-bottom: 5px;
    }
    
    .entity-header-card .badge {
        font-size: 0.9rem;
        padding: 5px 10px;
    }
    
    .balance-card {
        border-radius: 10px;
        padding: 15px;
        text-align: center;
        height: 100%;
        background: rgba(255,255,255,0.9);
    }
    
    .balance-card .amount {
        font-size: 2rem;
        font-weight: bold;
    }
    
    .balance-card .label {
        font-size: 0.9rem;
        color: #6c757d;
    }
    
    .filter-section {
        background-color: #f8f9fa;
        border-radius: 8px;
        padding: 15px;
        margin-bottom: 20px;
    }
    
    .transaction-debit {
        color: #dc3545;
        font-weight: bold;
    }
    
    .transaction-credit {
        color: #28a745;
        font-weight: bold;
    }
    
    .transaction-balance-positive {
        color: #28a745;
        font-weight: bold;
    }
    
    .transaction-balance-negative {
        color: #dc3545;
        font-weight: bold;
    }
    
    .table-transactions tbody tr:hover {
        background-color: #f8f9fa;
    }
    
    .entity-type-badge {
        font-size: 0.8rem;
        padding: 0.3rem 0.6rem;
        border-radius: 4px;
    }
    
    .badge-client { background-color: #007bff; color: white; }
    .badge-custodian { background-color: #17a2b8; color: white; }
    .badge-employee { background-color: #28a745; color: white; }
    .badge-agent { background-color: #ffc107; color: #212529; }
    .badge-broker { background-color: #fd7e14; color: white; }
    .badge-supplier { background-color: #e83e8c; color: white; }
    .badge-chart_account { background-color: #6f42c1; color: white; }
    .badge-bank_account { background-color: #20c997; color: white; }
    
    .export-btn {
        background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
        border: none;
        color: white;
        font-weight: 600;
    }
    
    .export-btn:hover {
        background: linear-gradient(135deg, #20c997 0%, #17a2b8 100%);
        color: white;
    }
    
    .back-btn {
        background: linear-gradient(135deg, #6c757d 0%, #495057 100%);
        border: none;
        color: white;
        font-weight: 600;
    }
    
    .back-btn:hover {
        background: linear-gradient(135deg, #495057 0%, #343a40 100%);
        color: white;
    }
</style>

<div class="container-fluid">
    <?php if (!empty($error_message)): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="bi bi-exclamation-triangle-fill me-2"></i>
            <?php echo $error_message; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Back Button -->
    <div class="row mb-3">
        <div class="col-12">
            <a href="debtors.php<?php echo isset($_GET['return_to']) ? '?' . htmlspecialchars($_GET['return_to']) : ''; ?>" 
               class="btn btn-secondary btn-sm back-btn">
                <i class="bi bi-arrow-left me-1"></i>Back to Debtors Report
            </a>
        </div>
    </div>

    <!-- Entity Header -->
    <div class="row">
        <div class="col-12">
            <div class="entity-header-card">
                <div class="row align-items-center">
                    <div class="col-md-6">
                        <h2><?php echo htmlspecialchars($entity['name']); ?></h2>
                        <div class="d-flex align-items-center gap-2 flex-wrap">
                            <span class="entity-type-badge badge-<?php echo $entity_type; ?>">
                                <?php echo $entity['type_label']; ?>
                            </span>
                            <span class="badge bg-light text-dark">
                                <?php echo htmlspecialchars($entity['code_label']); ?>: <?php echo htmlspecialchars($entity['code']); ?>
                            </span>
                            <?php if (isset($entity['status'])): ?>
                                <span class="badge <?php echo ($entity['status'] == 'active' && $entity['is_active'] == 1) ? 'bg-success' : 'bg-danger'; ?>">
                                    <?php echo ($entity['status'] == 'active' && $entity['is_active'] == 1) ? 'Active' : 'Inactive'; ?>
                                </span>
                            <?php endif; ?>
                            <span class="badge <?php echo $balance > 0 ? 'bg-success' : ($balance < 0 ? 'bg-danger' : 'bg-secondary'); ?>">
                                <?php echo $balance > 0 ? 'Credit Balance' : ($balance < 0 ? 'Debit Balance' : 'Settled'); ?>
                            </span>
                        </div>
                        <?php if (isset($entity['email']) && $entity['email']): ?>
                            <div class="mt-2 text-white-50">
                                <small><i class="bi bi-envelope me-1"></i><?php echo htmlspecialchars($entity['email']); ?></small>
                                <?php if (isset($entity['phone']) && $entity['phone']): ?>
                                    <small class="ms-3"><i class="bi bi-phone me-1"></i><?php echo htmlspecialchars($entity['phone']); ?></small>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="col-md-6">
                        <div class="row">
                            <div class="col-4">
                                <div class="balance-card">
                                    <div class="label">Balance</div>
                                    <div class="amount <?php echo $balance > 0 ? 'text-success' : ($balance < 0 ? 'text-danger' : 'text-muted'); ?>">
                                        <?php echo formatCurrency(abs($balance)); ?>
                                    </div>
                                    <div>
                                        <span class="badge <?php echo $balance > 0 ? 'bg-success' : ($balance < 0 ? 'bg-danger' : 'bg-secondary'); ?>">
                                            <?php echo $balance > 0 ? 'Credit' : ($balance < 0 ? 'Debit' : 'Settled'); ?>
                                        </span>
                                    </div>
                                </div>
                            </div>
                            <div class="col-4">
                                <div class="balance-card">
                                    <div class="label">Total Debit</div>
                                    <div class="amount text-danger">
                                        <?php echo formatCurrency($debit_total); ?>
                                    </div>
                                </div>
                            </div>
                            <div class="col-4">
                                <div class="balance-card">
                                    <div class="label">Total Credit</div>
                                    <div class="amount text-success">
                                        <?php echo formatCurrency($credit_total); ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Filters Section -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="filter-section">
                <form method="GET" id="filterForm">
                    <input type="hidden" name="type" value="<?php echo htmlspecialchars($entity_type); ?>">
                    <input type="hidden" name="id" value="<?php echo htmlspecialchars($entity_id); ?>">
                    <?php if (isset($_GET['return_to'])): ?>
                        <input type="hidden" name="return_to" value="<?php echo htmlspecialchars($_GET['return_to']); ?>">
                    <?php endif; ?>
                    
                    <div class="row g-3 align-items-end">
                        <div class="col-md-3">
                            <label class="form-label">Date Range</label>
                            <div class="row g-2">
                                <div class="col-6">
                                    <input type="date" class="form-control form-control-sm" name="start_date" 
                                           value="<?php echo htmlspecialchars($start_date); ?>"
                                           max="<?php echo date('Y-m-d'); ?>">
                                </div>
                                <div class="col-6">
                                    <input type="date" class="form-control form-control-sm" name="end_date" 
                                           value="<?php echo htmlspecialchars($end_date); ?>"
                                           max="<?php echo date('Y-m-d'); ?>">
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-md-2">
                            <label class="form-label">Search</label>
                            <div class="input-group input-group-sm">
                                <input type="text" class="form-control" name="search" 
                                       placeholder="Reference or description..."
                                       value="<?php echo htmlspecialchars($search); ?>">
                                <button class="btn btn-outline-secondary" type="submit">
                                    <i class="bi bi-search"></i>
                                </button>
                            </div>
                        </div>
                        
                        <div class="col-md-7">
                            <div class="d-flex gap-2 flex-wrap justify-content-end">
                                <button type="submit" class="btn btn-primary btn-sm">
                                    <i class="bi bi-filter me-1"></i>Apply Filters
                                </button>
                                <button type="button" class="btn btn-outline-secondary btn-sm" onclick="resetFilters()">
                                    <i class="bi bi-arrow-clockwise me-1"></i>Reset
                                </button>
                                <a href="?<?php echo http_build_query(array_merge($_GET, ['export' => 'pdf'])); ?>" 
                                   class="btn btn-danger btn-sm" target="_blank">
                                    <i class="bi bi-file-pdf me-1"></i>PDF
                                </a>
                                <a href="?<?php echo http_build_query(array_merge($_GET, ['export' => 'excel'])); ?>" 
                                   class="btn btn-success btn-sm export-btn">
                                    <i class="bi bi-file-excel me-1"></i>Excel
                                </a>
                                <?php if (count($transactions) > 0): ?>
                                    <span class="badge bg-secondary align-self-center">
                                        <?php echo count($transactions); ?> transactions
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Transactions Table -->
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-header bg-light d-flex justify-content-between align-items-center">
                    <h6 class="mb-0"><i class="bi bi-list-ul me-2"></i>Transaction History</h6>
                    <div>
                        <?php if ($start_date && $end_date): ?>
                            <span class="badge bg-info me-2"><?php echo date('d/m/Y', strtotime($start_date)); ?> - <?php echo date('d/m/Y', strtotime($end_date)); ?></span>
                        <?php endif; ?>
                        <span class="badge bg-secondary"><?php echo count($transactions); ?> transactions</span>
                    </div>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($transactions)): ?>
                        <div class="text-center py-5">
                            <i class="bi bi-inbox" style="font-size: 3rem; color: #6c757d;"></i>
                            <h5 class="mt-3 text-muted">No transactions found</h5>
                            <p class="text-muted">Try adjusting your filter criteria</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover table-transactions mb-0">
                                <thead class="table-light">
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
                                        $type_badge = $type === 'receipt' ? 'bg-success' : ($type === 'payment' ? 'bg-danger' : 'bg-info');
                                        
                                        $debit = isset($transaction['debit']) && $transaction['debit'] > 0 ? $transaction['debit'] : 0;
                                        $credit = isset($transaction['credit']) && $transaction['credit'] > 0 ? $transaction['credit'] : 0;
                                        $balance_val = isset($transaction['running_balance']) ? $transaction['running_balance'] : 0;
                                        
                                        $balance_class = $balance_val > 0 ? 'transaction-balance-positive' : ($balance_val < 0 ? 'transaction-balance-negative' : '');
                                    ?>
                                        <tr>
                                            <td><?php echo $date; ?></td>
                                            <td><code><?php echo htmlspecialchars($ref); ?></code></td>
                                            <td><?php echo htmlspecialchars($desc); ?></td>
                                            <td><code class="small"><?php echo htmlspecialchars($account); ?></code></td>
                                            <td>
                                                <span class="badge <?php echo $type_badge; ?>">
                                                    <?php echo $type_label; ?>
                                                </span>
                                            </td>
                                            <td class="text-end transaction-debit">
                                                <?php echo $debit > 0 ? number_format($debit, 2) : '-'; ?>
                                            </td>
                                            <td class="text-end transaction-credit">
                                                <?php echo $credit > 0 ? number_format($credit, 2) : '-'; ?>
                                            </td>
                                            <td class="text-end <?php echo $balance_class; ?>">
                                                <?php echo number_format($balance_val, 2); ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                                <tfoot class="table-light">
                                    <tr>
                                        <th colspan="5" class="text-end">TOTALS:</th>
                                        <th class="text-end transaction-debit"><?php echo number_format($debit_total, 2); ?></th>
                                        <th class="text-end transaction-credit"><?php echo number_format($credit_total, 2); ?></th>
                                        <th class="text-end <?php echo $balance > 0 ? 'transaction-balance-positive' : ($balance < 0 ? 'transaction-balance-negative' : ''); ?>">
                                            <?php echo number_format($balance, 2); ?>
                                        </th>
                                    </tr>
                                    <tr>
                                        <th colspan="7" class="text-end">BALANCE (<?php echo $balance > 0 ? 'Credit' : ($balance < 0 ? 'Debit' : 'Settled'); ?>):</th>
                                        <th class="text-end <?php echo $balance > 0 ? 'transaction-balance-positive' : ($balance < 0 ? 'transaction-balance-negative' : ''); ?>">
                                            <?php echo number_format(abs($balance), 2); ?>
                                        </th>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
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
});
</script>

<?php include '../includes/footer.php'; ?>
