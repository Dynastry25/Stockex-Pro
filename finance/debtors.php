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
$success_message = '';
$error_message = '';

// Pagination configuration
$records_per_page = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 20;
if ($records_per_page <= 0) $records_per_page = 0; // 0 means show all

$current_page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($current_page < 1) $current_page = 1;
$offset = ($records_per_page > 0) ? ($current_page - 1) * $records_per_page : 0;

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

function getAgingCategory($days) {
    if ($days <= 30) return 'Current';
    if ($days <= 60) return '31-60 Days';
    if ($days <= 90) return '61-90 Days';
    return '90+ Days';
}

// Get entity name from ID
function getEntityName($db, $entity_type, $entity_id) {
    try {
        switch ($entity_type) {
            case 'client':
                $stmt = $db->prepare("SELECT client_name FROM clients WHERE id = ?");
                $stmt->execute([$entity_id]);
                $result = $stmt->fetch();
                return $result ? $result['client_name'] : "Client #$entity_id";
                
            case 'custodian':
                $stmt = $db->prepare("SELECT custodian_name FROM custodians WHERE id = ?");
                $stmt->execute([$entity_id]);
                $result = $stmt->fetch();
                return $result ? $result['custodian_name'] : "Custodian #$entity_id";
                
            case 'employee':
                $stmt = $db->prepare("SELECT full_name FROM users WHERE id = ?");
                $stmt->execute([$entity_id]);
                $result = $stmt->fetch();
                return $result ? $result['full_name'] : "Employee #$entity_id";
                
            case 'agent':
                $stmt = $db->prepare("SELECT name FROM agents WHERE id = ?");
                $stmt->execute([$entity_id]);
                $result = $stmt->fetch();
                return $result ? $result['name'] : "Agent #$entity_id";
                
            case 'broker':
                $stmt = $db->prepare("SELECT broker_name FROM brokers WHERE id = ?");
                $stmt->execute([$entity_id]);
                $result = $stmt->fetch();
                return $result ? $result['broker_name'] : "Broker #$entity_id";
                
            case 'supplier':
                $stmt = $db->prepare("SELECT name FROM suppliers WHERE id = ?");
                $stmt->execute([$entity_id]);
                $result = $stmt->fetch();
                return $result ? $result['name'] : "Supplier #$entity_id";
                
            case 'chart_account':
                $stmt = $db->prepare("SELECT account_name FROM chart_of_accounts WHERE account_code = ?");
                $stmt->execute([$entity_id]);
                $result = $stmt->fetch();
                return $result ? $result['account_name'] : "Account #$entity_id";
                
            case 'bank_account':
                $stmt = $db->prepare("SELECT account_name, account_number FROM banks_accounts WHERE id = ?");
                $stmt->execute([$entity_id]);
                $result = $stmt->fetch();
                return $result ? $result['account_name'] . ' (' . $result['account_number'] . ')' : "Bank Account #$entity_id";
                
            default:
                return "Entity #$entity_id";
        }
    } catch (Exception $e) {
        error_log("Error getting entity name: " . $e->getMessage());
        return "Entity #$entity_id";
    }
}

// Helper function to get ledger code from entity type
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

// Function to get all balances with aging
function getAllBalances($db, $entity_type = null, $as_of_date = null, $start_date = null, $end_date = null, $limit = null, $offset = 0) {
    $as_of_date = $as_of_date ?: date('Y-m-d');
    $all_data = [];
    
    // Build entity queries based on type filter
    $entity_queries = [];
    
    if (!$entity_type || $entity_type === 'client') {
        $entity_queries[] = [
            'type' => 'client',
            'query' => "SELECT id, client_name as name, cds_account, phone, email, client_type FROM clients WHERE is_active = 1 AND status = 'active'",
            'count_query' => "SELECT COUNT(*) as total FROM clients WHERE is_active = 1 AND status = 'active'",
            'params' => []
        ];
    }
    
    if (!$entity_type || $entity_type === 'custodian') {
        $entity_queries[] = [
            'type' => 'custodian',
            'query' => "SELECT id, custodian_name as name, custodian_code as code, contact_person, phone, email FROM custodians WHERE status = 'active' AND is_active = 1",
            'count_query' => "SELECT COUNT(*) as total FROM custodians WHERE status = 'active' AND is_active = 1",
            'params' => []
        ];
    }
    
    if (!$entity_type || $entity_type === 'employee') {
        $entity_queries[] = [
            'type' => 'employee',
            'query' => "SELECT id, full_name as name, username as code, email, phone, role FROM users WHERE status = 'active' AND is_active = 1 AND role IN ('employee', 'manager', 'admin', 'finance_officer')",
            'count_query' => "SELECT COUNT(*) as total FROM users WHERE status = 'active' AND is_active = 1 AND role IN ('employee', 'manager', 'admin', 'finance_officer')",
            'params' => []
        ];
    }
    
    if (!$entity_type || $entity_type === 'agent') {
        $entity_queries[] = [
            'type' => 'agent',
            'query' => "SELECT id, name, agent_code as code, contact_person, phone, email FROM agents WHERE status = 'active' AND is_active = 1",
            'count_query' => "SELECT COUNT(*) as total FROM agents WHERE status = 'active' AND is_active = 1",
            'params' => []
        ];
    }
    
    if (!$entity_type || $entity_type === 'broker') {
        $entity_queries[] = [
            'type' => 'broker',
            'query' => "SELECT id, broker_name as name, broker_code as code, contact_person, phone, email FROM brokers WHERE status = 'active' AND is_active = 1",
            'count_query' => "SELECT COUNT(*) as total FROM brokers WHERE status = 'active' AND is_active = 1",
            'params' => []
        ];
    }
    
    if (!$entity_type || $entity_type === 'supplier') {
        $entity_queries[] = [
            'type' => 'supplier',
            'query' => "SELECT id, name, supplier_code as code, contact_person, phone, email FROM suppliers WHERE status = 'active' AND is_active = 1",
            'count_query' => "SELECT COUNT(*) as total FROM suppliers WHERE status = 'active' AND is_active = 1",
            'params' => []
        ];
    }
    
    if (!$entity_type || $entity_type === 'chart_account') {
        $entity_queries[] = [
            'type' => 'chart_account',
            'query' => "SELECT account_code as id, account_name as name, account_code as code, account_type, level FROM chart_of_accounts WHERE is_active = 1 AND (is_group_account = 0 OR level >= 3)",
            'count_query' => "SELECT COUNT(*) as total FROM chart_of_accounts WHERE is_active = 1 AND (is_group_account = 0 OR level >= 3)",
            'params' => []
        ];
    }
    
    if (!$entity_type || $entity_type === 'bank_account') {
        $entity_queries[] = [
            'type' => 'bank_account',
            'query' => "SELECT id, account_name as name, account_number as code, bank_name, currency, current_balance FROM banks_accounts WHERE status = 'active' AND is_active = 1",
            'count_query' => "SELECT COUNT(*) as total FROM banks_accounts WHERE status = 'active' AND is_active = 1",
            'params' => []
        ];
    }
    
    $total_count = 0;
    
    foreach ($entity_queries as $entity_query) {
        $entity_type = $entity_query['type'];
        $query = $entity_query['query'];
        $count_query = $entity_query['count_query'];
        $params = $entity_query['params'];
        
        // Get count
        $count_stmt = $db->prepare($count_query);
        $count_stmt->execute($params);
        $total_count += $count_stmt->fetchColumn();
        
        // Add pagination
        if ($limit !== null && $limit > 0) {
            $query .= " LIMIT " . (int)$limit . " OFFSET " . (int)$offset;
        }
        
        $stmt = $db->prepare($query);
        $stmt->execute($params);
        $entities = $stmt->fetchAll();
        
        foreach ($entities as $entity) {
            $entity_id = $entity['id'];
            
            // Get receipts for this entity
            $receipts_query = "SELECT receipt_date, amount, currency, narration, receipt_no, 
                              account_no as bank_account 
                              FROM receipts 
                              WHERE (account_of = ? OR (account_of = 'O' AND source_type = ?))
                              AND name_id = ? 
                              AND record_in_financial = 'yes'";
            $receipts_params = [getLedgerCode($entity_type), $entity_type, $entity_id];
            
            if ($start_date && $end_date) {
                $receipts_query .= " AND receipt_date BETWEEN ? AND ?";
                $receipts_params[] = $start_date;
                $receipts_params[] = $end_date;
            } elseif ($as_of_date) {
                $receipts_query .= " AND receipt_date <= ?";
                $receipts_params[] = $as_of_date;
            }
            
            $receipts_stmt = $db->prepare($receipts_query);
            $receipts_stmt->execute($receipts_params);
            $receipts = $receipts_stmt->fetchAll();
            
            // Get payments for this entity
            $payments_query = "SELECT payment_date, amount, currency, narration, payment_no,
                              account_no as bank_account 
                              FROM payments 
                              WHERE (paid_to = ? OR (paid_to = 'O' AND source_type = ?))
                              AND name_id = ? 
                              AND record_in_financial = 'yes'";
            $payments_params = [getLedgerCode($entity_type), $entity_type, $entity_id];
            
            if ($start_date && $end_date) {
                $payments_query .= " AND payment_date BETWEEN ? AND ?";
                $payments_params[] = $start_date;
                $payments_params[] = $end_date;
            } elseif ($as_of_date) {
                $payments_query .= " AND payment_date <= ?";
                $payments_params[] = $as_of_date;
            }
            
            $payments_stmt = $db->prepare($payments_query);
            $payments_stmt->execute($payments_params);
            $payments = $payments_stmt->fetchAll();
            
            // Calculate totals
            $total_receipts = array_sum(array_column($receipts, 'amount'));
            $total_payments = array_sum(array_column($payments, 'amount'));
            
            // For bank accounts, receipts are inflows and payments are outflows
            // For other entities, receipts are money we received (entity owes us), payments are money we paid (we owe entity)
            if ($entity_type === 'bank_account') {
                $net_balance = $total_receipts - $total_payments; // Inflows minus outflows
                $balance_status = $net_balance >= 0 ? 'Positive Balance' : 'Negative Balance';
                $debit_balance = $net_balance < 0 ? abs($net_balance) : 0; // Negative balance
                $credit_balance = $net_balance > 0 ? $net_balance : 0; // Positive balance
            } else {
                $net_balance = $total_payments - $total_receipts; // Payments minus receipts
                $balance_status = $net_balance > 0 ? 'Credit Balance (We Owe)' : 
                                ($net_balance < 0 ? 'Debit Balance (Entity Owes Us)' : 'Settled');
                $debit_balance = ($net_balance < 0) ? abs($net_balance) : 0;
                $credit_balance = ($net_balance > 0) ? $net_balance : 0;
            }
            
            // Combine transactions
            $transactions = [];
            foreach ($receipts as $receipt) {
                $transactions[] = [
                    'date' => $receipt['receipt_date'],
                    'type' => 'receipt',
                    'description' => $receipt['narration'] ?: 'Money received',
                    'reference' => $receipt['receipt_no'],
                    'amount' => $entity_type === 'bank_account' ? $receipt['amount'] : -$receipt['amount'],
                    'currency' => $receipt['currency'],
                    'bank_account' => $receipt['bank_account'],
                    'running_balance' => 0
                ];
            }
            
            foreach ($payments as $payment) {
                $transactions[] = [
                    'date' => $payment['payment_date'],
                    'type' => 'payment',
                    'description' => $payment['narration'] ?: 'Money paid',
                    'reference' => $payment['payment_no'],
                    'amount' => $entity_type === 'bank_account' ? -$payment['amount'] : $payment['amount'],
                    'currency' => $payment['currency'],
                    'bank_account' => $payment['bank_account'],
                    'running_balance' => 0
                ];
            }
            
            // Sort transactions
            usort($transactions, function($a, $b) {
                return strtotime($a['date']) - strtotime($b['date']);
            });
            
            // Calculate running balance
            $running_balance = 0;
            foreach ($transactions as &$transaction) {
                $running_balance += $transaction['amount'];
                $transaction['running_balance'] = $running_balance;
            }
            
            // Calculate aging (only for debit balances of non-bank entities)
            $aging_buckets = [
                'Current' => 0,
                '31-60 Days' => 0,
                '61-90 Days' => 0,
                '90+ Days' => 0
            ];
            
            if ($entity_type !== 'bank_account') {
                $today = new DateTime($as_of_date);
                foreach ($transactions as $transaction) {
                    if ($transaction['amount'] < 0) { // Negative amount means entity owes us
                        $transaction_date = new DateTime($transaction['date']);
                        $days_diff = $today->diff($transaction_date)->days;
                        $aging_category = getAgingCategory($days_diff);
                        $aging_buckets[$aging_category] += abs($transaction['amount']);
                    }
                }
            }
            
            $total_overdue = $aging_buckets['31-60 Days'] + $aging_buckets['61-90 Days'] + $aging_buckets['90+ Days'];
            
            $all_data[] = [
                'entity_info' => [
                    'type' => $entity_type,
                    'id' => $entity_id,
                    'name' => $entity['name'],
                    'code' => $entity['code'] ?? $entity['id'],
                    'details' => $entity
                ],
                'transactions' => $transactions,
                'totals' => [
                    'receipts' => $total_receipts,
                    'payments' => $total_payments,
                    'net_balance' => $net_balance,
                    'debit_balance' => $debit_balance,
                    'credit_balance' => $credit_balance,
                    'balance_status' => $balance_status
                ],
                'aging' => [
                    'buckets' => $aging_buckets,
                    'total_overdue' => $total_overdue,
                    'current_ratio' => $total_receipts > 0 ? ($aging_buckets['Current'] / $total_receipts * 100) : 0,
                    'overdue_ratio' => $total_receipts > 0 ? ($total_overdue / $total_receipts * 100) : 0
                ]
            ];
        }
    }
    
    // Sort by entity name
    usort($all_data, function($a, $b) {
        return strcmp($a['entity_info']['name'], $b['entity_info']['name']);
    });
    
    return [
        'data' => $all_data,
        'total_count' => $total_count
    ];
}

// Function to export to Excel
function exportToExcel($data, $entity_type = null, $as_of_date = null, $start_date = null, $end_date = null) {
    // Set headers for Excel download
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="debt_credit_report_' . date('Ymd_His') . '.xls"');
    header('Pragma: no-cache');
    header('Expires: 0');
    
    // Excel BOM for UTF-8
    echo "\xEF\xBB\xBF";
    
    // Start Excel content
    echo '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">';
    echo '<head>';
    echo '<meta charset="UTF-8">';
    echo '<style>';
    echo 'td { mso-number-format:\@; }';
    echo '.text { mso-number-format:"\@"; }';
    echo '.number { mso-number-format:"#,##0.00"; }';
    echo '.date { mso-number-format:"Short Date"; }';
    echo '.header { font-weight: bold; background-color: #f2f2f2; }';
    echo '.debit { color: #c00000; font-weight: bold; }';
    echo '.credit { color: #00b050; font-weight: bold; }';
    echo '.total { font-weight: bold; background-color: #e6f3ff; }';
    echo '</style>';
    echo '</head>';
    echo '<body>';
    
    echo '<table border="1" cellpadding="3" cellspacing="0">';
    
    // Report header
    echo '<tr><td colspan="10" class="header" style="text-align: center; font-size: 16px;">DEBT & CREDIT TRACKING REPORT - COMPREHENSIVE</td></tr>';
    echo '<tr><td colspan="10">Generated: ' . date('d/m/Y H:i:s') . '</td></tr>';
    echo '<tr><td colspan="10">As of Date: ' . $as_of_date . '</td></tr>';
    if ($start_date && $end_date) {
        echo '<tr><td colspan="10">Date Range: ' . $start_date . ' to ' . $end_date . '</td></tr>';
    }
    if ($entity_type) {
        echo '<tr><td colspan="10">Entity Type: ' . ucfirst($entity_type) . 's</td></tr>';
    } else {
        echo '<tr><td colspan="10">Entity Type: All Entities</td></tr>';
    }
    echo '<tr><td colspan="10"></td></tr>';
    
    // Column headers
    echo '<tr class="header">';
    echo '<td>Entity Type</td>';
    echo '<td>Entity Code</td>';
    echo '<td>Entity Name</td>';
    echo '<td>Total Receipts (Money Received)</td>';
    echo '<td>Total Payments (Money Paid)</td>';
    echo '<td>Debit Balance</td>';
    echo '<td>Credit Balance</td>';
    echo '<td>Net Balance</td>';
    echo '<td>Balance Status</td>';
    echo '<td>Total Overdue</td>';
    echo '</tr>';
    
    // Data rows
    $summary_totals = [
        'total_receipts' => 0,
        'total_payments' => 0,
        'total_debit' => 0,
        'total_credit' => 0,
        'total_overdue' => 0
    ];
    
    foreach ($data as $entity_data) {
        $entity_info = $entity_data['entity_info'];
        $totals = $entity_data['totals'];
        $aging = $entity_data['aging'];
        
        $summary_totals['total_receipts'] += $totals['receipts'];
        $summary_totals['total_payments'] += $totals['payments'];
        $summary_totals['total_debit'] += $totals['debit_balance'];
        $summary_totals['total_credit'] += $totals['credit_balance'];
        $summary_totals['total_overdue'] += $aging['total_overdue'];
        
        $net_balance_class = $totals['net_balance'] > 0 ? 'credit' : ($totals['net_balance'] < 0 ? 'debit' : '');
        
        echo '<tr>';
        echo '<td>' . ucfirst($entity_info['type']) . '</td>';
        echo '<td>' . htmlspecialchars($entity_info['code']) . '</td>';
        echo '<td>' . htmlspecialchars($entity_info['name']) . '</td>';
        echo '<td class="number">' . number_format($totals['receipts'], 2) . '</td>';
        echo '<td class="number">' . number_format($totals['payments'], 2) . '</td>';
        echo '<td class="number debit">' . number_format($totals['debit_balance'], 2) . '</td>';
        echo '<td class="number credit">' . number_format($totals['credit_balance'], 2) . '</td>';
        echo '<td class="number ' . $net_balance_class . '">' . number_format(abs($totals['net_balance']), 2) . '</td>';
        echo '<td>' . $totals['balance_status'] . '</td>';
        echo '<td class="number">' . number_format($aging['total_overdue'], 2) . '</td>';
        echo '</tr>';
    }
    
    // Summary row
    $net_balance_total = $summary_totals['total_credit'] - $summary_totals['total_debit'];
    $net_balance_class = $net_balance_total > 0 ? 'credit' : ($net_balance_total < 0 ? 'debit' : '');
    
    echo '<tr class="total">';
    echo '<td colspan="3">TOTALS:</td>';
    echo '<td class="number">' . number_format($summary_totals['total_receipts'], 2) . '</td>';
    echo '<td class="number">' . number_format($summary_totals['total_payments'], 2) . '</td>';
    echo '<td class="number debit">' . number_format($summary_totals['total_debit'], 2) . '</td>';
    echo '<td class="number credit">' . number_format($summary_totals['total_credit'], 2) . '</td>';
    echo '<td class="number ' . $net_balance_class . '">' . number_format(abs($net_balance_total), 2) . '</td>';
    echo '<td>' . ($net_balance_total > 0 ? 'Net Credit' : ($net_balance_total < 0 ? 'Net Debit' : 'Balanced')) . '</td>';
    echo '<td class="number">' . number_format($summary_totals['total_overdue'], 2) . '</td>';
    echo '</tr>';
    
    echo '</table>';
    
    // Add aging analysis section
    echo '<br><br>';
    echo '<table border="1" cellpadding="3" cellspacing="0">';
    echo '<tr><td colspan="5" class="header" style="text-align: center;">AGING ANALYSIS (Amounts Owed to Us)</td></tr>';
    echo '<tr class="header">';
    echo '<td>Aging Category</td>';
    echo '<td>Amount</td>';
    echo '<td>% of Total Debit</td>';
    echo '<td>Risk Level</td>';
    echo '<td>Recommended Action</td>';
    echo '</tr>';
    
    $aging_summary = [
        'Current' => 0,
        '31-60 Days' => 0,
        '61-90 Days' => 0,
        '90+ Days' => 0
    ];
    
    foreach ($data as $entity_data) {
        $aging_buckets = $entity_data['aging']['buckets'];
        foreach ($aging_buckets as $category => $amount) {
            $aging_summary[$category] += $amount;
        }
    }
    
    $total_debit_aging = array_sum($aging_summary);
    
    $aging_rows = [
        ['Current (0-30 days)', $aging_summary['Current'], 'Low Risk', 'Monitor'],
        ['31-60 Days Overdue', $aging_summary['31-60 Days'], 'Medium Risk', 'Follow-up Required'],
        ['61-90 Days Overdue', $aging_summary['61-90 Days'], 'High Risk', 'Urgent Action Needed'],
        ['90+ Days Overdue', $aging_summary['90+ Days'], 'Critical Risk', 'Legal/Collection Action']
    ];
    
    foreach ($aging_rows as $row) {
        $percentage = $total_debit_aging > 0 ? ($row[1] / $total_debit_aging * 100) : 0;
        echo '<tr>';
        echo '<td>' . $row[0] . '</td>';
        echo '<td class="number">' . number_format($row[1], 2) . '</td>';
        echo '<td class="number">' . number_format($percentage, 1) . '%</td>';
        echo '<td>' . $row[2] . '</td>';
        echo '<td>' . $row[3] . '</td>';
        echo '</tr>';
    }
    
    echo '</table>';
    
    // Add transaction details for each entity
    echo '<br><br>';
    echo '<table border="1" cellpadding="3" cellspacing="0">';
    echo '<tr><td colspan="9" class="header" style="text-align: center;">DETAILED TRANSACTION HISTORY</td></tr>';
    echo '<tr class="header">';
    echo '<td>Entity Type</td>';
    echo '<td>Entity Name</td>';
    echo '<td>Date</td>';
    echo '<td>Transaction Type</td>';
    echo '<td>Reference No</td>';
    echo '<td>Description</td>';
    echo '<td>Bank Account</td>';
    echo '<td>Amount</td>';
    echo '<td>Running Balance</td>';
    echo '</tr>';
    
    foreach ($data as $entity_data) {
        $entity_info = $entity_data['entity_info'];
        $transactions = $entity_data['transactions'];
        
        foreach ($transactions as $transaction) {
            $amount_class = $transaction['amount'] > 0 ? 'credit' : 'debit';
            $balance_class = $transaction['running_balance'] >= 0 ? 'credit' : 'debit';
            
            echo '<tr>';
            echo '<td>' . ucfirst($entity_info['type']) . '</td>';
            echo '<td>' . htmlspecialchars($entity_info['name']) . '</td>';
            echo '<td class="date">' . $transaction['date'] . '</td>';
            echo '<td>' . ucfirst($transaction['type']) . '</td>';
            echo '<td>' . htmlspecialchars($transaction['reference']) . '</td>';
            echo '<td>' . htmlspecialchars($transaction['description']) . '</td>';
            echo '<td>' . ($transaction['bank_account'] ?: 'N/A') . '</td>';
            echo '<td class="number ' . $amount_class . '">' . number_format($transaction['amount'], 2) . '</td>';
            echo '<td class="number ' . $balance_class . '">' . number_format($transaction['running_balance'], 2) . '</td>';
            echo '</tr>';
        }
    }
    
    echo '</table>';
    
    echo '</body></html>';
    exit;
}

// Handle Excel export
if (isset($_GET['export']) && $_GET['export'] == 'excel') {
    $entity_type = isset($_GET['entity_type']) ? $_GET['entity_type'] : null;
    $as_of_date = isset($_GET['as_of_date']) ? $_GET['as_of_date'] : date('Y-m-d');
    $start_date = isset($_GET['start_date']) ? $_GET['start_date'] : null;
    $end_date = isset($_GET['end_date']) ? $_GET['end_date'] : null;
    
    // Get all data for export (no pagination)
    $result = getAllBalances($db, $entity_type, $as_of_date, $start_date, $end_date, null, 0);
    $data = $result['data'];
    
    exportToExcel($data, $entity_type, $as_of_date, $start_date, $end_date);
    exit;
}

// Handle PDF export
if (isset($_GET['export']) && $_GET['export'] == 'pdf') {
    $entity_type = isset($_GET['entity_type']) ? $_GET['entity_type'] : null;
    $as_of_date = isset($_GET['as_of_date']) ? $_GET['as_of_date'] : date('Y-m-d');
    $start_date = isset($_GET['start_date']) ? $_GET['start_date'] : null;
    $end_date = isset($_GET['end_date']) ? $_GET['end_date'] : null;
    
    $result = getAllBalances($db, $entity_type, $as_of_date, $start_date, $end_date, null, 0);
    $data = $result['data'];
    
    // Create PDF
    $pdf = new TCPDF('L', PDF_UNIT, 'A4', true, 'UTF-8', false);
    $pdf->SetCreator(PDF_CREATOR);
    $pdf->SetAuthor('Financial System');
    $pdf->SetTitle('Debt & Credit Aged Balances Report');
    $pdf->SetSubject('Aged Debtors Report');
    $pdf->SetHeaderData('', 0, 'Debt & Credit Tracking Report', 'Aged Balances Analysis');
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
    $pdf->Cell(0, 10, 'DEBT & CREDIT TRACKING REPORT - COMPREHENSIVE', 0, 1, 'C');
    $pdf->Ln(5);
    
    // Report info
    $pdf->SetFont('helvetica', '', 10);
    $pdf->Cell(0, 6, 'Generated: ' . date('d/m/Y H:i:s'), 0, 1);
    $pdf->Cell(0, 6, 'As of Date: ' . $as_of_date, 0, 1);
    if ($start_date && $end_date) {
        $pdf->Cell(0, 6, 'Date Range: ' . $start_date . ' to ' . $end_date, 0, 1);
    }
    $pdf->Cell(0, 6, 'Entity Type: ' . ($entity_type ? ucfirst($entity_type) . 's' : 'All Entities'), 0, 1);
    $pdf->Ln(10);
    
    // Calculate summary
    $summary_totals = [
        'total_receipts' => 0,
        'total_payments' => 0,
        'total_debit' => 0,
        'total_credit' => 0,
        'total_overdue' => 0
    ];
    
    foreach ($data as $entity_data) {
        $totals = $entity_data['totals'];
        $aging = $entity_data['aging'];
        
        $summary_totals['total_receipts'] += $totals['receipts'];
        $summary_totals['total_payments'] += $totals['payments'];
        $summary_totals['total_debit'] += $totals['debit_balance'];
        $summary_totals['total_credit'] += $totals['credit_balance'];
        $summary_totals['total_overdue'] += $aging['total_overdue'];
    }
    
    $net_balance_total = $summary_totals['total_credit'] - $summary_totals['total_debit'];
    
    // Summary table
    $summary_html = '<table border="1" cellpadding="4" cellspacing="0">
        <thead>
            <tr style="background-color:#f2f2f2; font-weight:bold;">
                <th width="20%">Description</th>
                <th width="20%">Amount</th>
                <th width="20%">Type</th>
                <th width="40%">Status</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>Total Receipts (Money Received)</td>
                <td align="right">' . number_format($summary_totals['total_receipts'], 2) . '</td>
                <td>Income</td>
                <td>Money received from all entities</td>
            </tr>
            <tr>
                <td>Total Payments (Money Paid)</td>
                <td align="right">' . number_format($summary_totals['total_payments'], 2) . '</td>
                <td>Expense</td>
                <td>Money paid to all entities</td>
            </tr>
            <tr>
                <td>Total Debit Balance</td>
                <td align="right" style="color:#c00000;">' . number_format($summary_totals['total_debit'], 2) . '</td>
                <td>Assets</td>
                <td>Money entities owe to us</td>
            </tr>
            <tr>
                <td>Total Credit Balance</td>
                <td align="right" style="color:#00b050;">' . number_format($summary_totals['total_credit'], 2) . '</td>
                <td>Liabilities</td>
                <td>Money we owe to entities</td>
            </tr>
            <tr style="background-color:#e6f3ff; font-weight:bold;">
                <td>NET BALANCE</td>
                <td align="right" style="color:' . ($net_balance_total > 0 ? '#00b050' : '#c00000') . ';">' . number_format(abs($net_balance_total), 2) . '</td>
                <td>' . ($net_balance_total > 0 ? 'Liability' : 'Asset') . '</td>
                <td>' . ($net_balance_total > 0 ? 'We owe more to entities' : 'Entities owe more to us') . '</td>
            </tr>
        </tbody>
    </table>';
    
    $pdf->writeHTML($summary_html, true, false, true, false, '');
    $pdf->Ln(10);
    
    // Entity details table
    $pdf->SetFont('helvetica', 'B', 12);
    $pdf->Cell(0, 8, 'ENTITY BALANCE DETAILS', 0, 1);
    $pdf->SetFont('helvetica', '', 9);
    
    $entity_html = '<table border="1" cellpadding="3" cellspacing="0">
        <thead>
            <tr style="background-color:#f2f2f2; font-weight:bold;">
                <th width="8%">Type</th>
                <th width="12%">Code</th>
                <th width="20%">Name</th>
                <th width="10%">Receipts</th>
                <th width="10%">Payments</th>
                <th width="10%">Debit</th>
                <th width="10%">Credit</th>
                <th width="10%">Net</th>
                <th width="10%">Overdue</th>
            </tr>
        </thead>
        <tbody>';
    
    foreach ($data as $entity_data) {
        $entity_info = $entity_data['entity_info'];
        $totals = $entity_data['totals'];
        $aging = $entity_data['aging'];
        
        $net_class = $totals['net_balance'] > 0 ? 'color:#00b050;' : 'color:#c00000;';
        
        $entity_html .= '
            <tr>
                <td>' . ucfirst($entity_info['type']) . '</td>
                <td>' . htmlspecialchars($entity_info['code']) . '</td>
                <td>' . htmlspecialchars($entity_info['name']) . '</td>
                <td align="right">' . number_format($totals['receipts'], 2) . '</td>
                <td align="right">' . number_format($totals['payments'], 2) . '</td>
                <td align="right" style="color:#c00000;">' . number_format($totals['debit_balance'], 2) . '</td>
                <td align="right" style="color:#00b050;">' . number_format($totals['credit_balance'], 2) . '</td>
                <td align="right" style="' . $net_class . '">' . number_format(abs($totals['net_balance']), 2) . '</td>
                <td align="right">' . number_format($aging['total_overdue'], 2) . '</td>
            </tr>';
    }
    
    $entity_html .= '</tbody></table>';
    $pdf->writeHTML($entity_html, true, false, true, false, '');
    
    // Output PDF
    $filename = 'debt_credit_report_' . date('Ymd_His') . '.pdf';
    $pdf->Output($filename, 'I');
    exit;
}

// Get filter parameters
$entity_type = isset($_GET['entity_type']) ? $_GET['entity_type'] : null;
$as_of_date = isset($_GET['as_of_date']) ? $_GET['as_of_date'] : date('Y-m-d');
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : null;
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : null;
$balance_status = isset($_GET['balance_status']) ? $_GET['balance_status'] : 'all';
$aging_filter = isset($_GET['aging_filter']) ? $_GET['aging_filter'] : 'all';

// Validate dates
if (!validateDate($as_of_date)) $as_of_date = date('Y-m-d');
if ($start_date && !validateDate($start_date)) $start_date = null;
if ($end_date && !validateDate($end_date)) $end_date = null;

// Get data with pagination
$limit_for_query = $records_per_page > 0 ? $records_per_page : null;
$result = getAllBalances($db, $entity_type, $as_of_date, $start_date, $end_date, $limit_for_query, $offset);
$all_data = $result['data'];
$total_count = $result['total_count'];
$total_pages = $records_per_page > 0 ? (int)ceil($total_count / $records_per_page) : 1;

// Apply additional filters
if ($balance_status !== 'all') {
    $all_data = array_filter($all_data, function($data) use ($balance_status) {
        $net_balance = $data['totals']['net_balance'];
        if ($balance_status === 'credit' && $net_balance > 0) return true;
        if ($balance_status === 'debit' && $net_balance < 0) return true;
        if ($balance_status === 'settled' && $net_balance == 0) return true;
        return false;
    });
}

if ($aging_filter !== 'all') {
    $all_data = array_filter($all_data, function($data) use ($aging_filter) {
        $overdue_ratio = $data['aging']['overdue_ratio'];
        if ($aging_filter === 'overdue_high' && $overdue_ratio > 30) return true;
        if ($aging_filter === 'overdue_medium' && $overdue_ratio > 10 && $overdue_ratio <= 30) return true;
        if ($aging_filter === 'overdue_low' && $overdue_ratio > 0 && $overdue_ratio <= 10) return true;
        if ($aging_filter === 'current' && $overdue_ratio == 0) return true;
        return false;
    });
}

// Calculate summary statistics
$summary_stats = [
    'total_entities' => count($all_data),
    'total_receipts' => 0,
    'total_payments' => 0,
    'total_debit_balance' => 0,
    'total_credit_balance' => 0,
    'total_current' => 0,
    'total_overdue' => 0,
    'entities_in_credit' => 0,
    'entities_in_debit' => 0,
    'entities_settled' => 0,
    'by_type' => []
];

foreach ($all_data as $data) {
    $entity_type = $data['entity_info']['type'];
    
    if (!isset($summary_stats['by_type'][$entity_type])) {
        $summary_stats['by_type'][$entity_type] = [
            'count' => 0,
            'debit' => 0,
            'credit' => 0,
            'overdue' => 0
        ];
    }
    
    $summary_stats['total_receipts'] += $data['totals']['receipts'];
    $summary_stats['total_payments'] += $data['totals']['payments'];
    $summary_stats['total_debit_balance'] += $data['totals']['debit_balance'];
    $summary_stats['total_credit_balance'] += $data['totals']['credit_balance'];
    $summary_stats['total_current'] += $data['aging']['buckets']['Current'];
    $summary_stats['total_overdue'] += $data['aging']['total_overdue'];
    
    $summary_stats['by_type'][$entity_type]['count']++;
    $summary_stats['by_type'][$entity_type]['debit'] += $data['totals']['debit_balance'];
    $summary_stats['by_type'][$entity_type]['credit'] += $data['totals']['credit_balance'];
    $summary_stats['by_type'][$entity_type]['overdue'] += $data['aging']['total_overdue'];
    
    if ($data['totals']['net_balance'] > 0) $summary_stats['entities_in_credit']++;
    elseif ($data['totals']['net_balance'] < 0) $summary_stats['entities_in_debit']++;
    else $summary_stats['entities_settled']++;
}

// Calculate net balance
$net_balance = $summary_stats['total_credit_balance'] - $summary_stats['total_debit_balance'];

$page_title = 'Comprehensive Debt & Credit Tracking';
include '../includes/header.php';
?>

<style>
    .stats-card {
        border-radius: 10px;
        transition: transform 0.3s ease;
        border: none;
        box-shadow: 0 2px 10px rgba(0,0,0,0.1);
    }
    
    .stats-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 5px 20px rgba(0,0,0,0.15);
    }
    
    .stats-card-total {
        border-left: 5px solid #007bff;
    }
    
    .stats-card-debit {
        border-left: 5px solid #dc3545;
    }
    
    .stats-card-credit {
        border-left: 5px solid #28a745;
    }
    
    .stats-card-net {
        border-left: 5px solid #6f42c1;
    }
    
    .stats-card-overdue {
        border-left: 5px solid #ffc107;
    }
    
    .entity-type-badge {
        font-size: 0.7rem;
        padding: 0.2rem 0.4rem;
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
    
    .aging-badge {
        font-size: 0.75rem;
        padding: 0.25rem 0.5rem;
    }
    
    .aging-current { background-color: #d4edda; color: #155724; }
    .aging-31-60 { background-color: #fff3cd; color: #856404; }
    .aging-61-90 { background-color: #f8d7da; color: #721c24; }
    .aging-90plus { background-color: #dc3545; color: white; }
    
    .balance-positive { color: #28a745; font-weight: bold; }
    .balance-negative { color: #dc3545; font-weight: bold; }
    .balance-zero { color: #6c757d; font-weight: bold; }
    
    .progress-bar-overdue {
        background-color: #dc3545;
    }
    
    .progress-bar-current {
        background-color: #28a745;
    }
    
    .pagination {
        margin: 0;
    }
    
    .page-item.active .page-link {
        background-color: #007bff;
        border-color: #007bff;
    }
    
    .page-link {
        color: #007bff;
        border: 1px solid #dee2e6;
    }
    
    .page-link:hover {
        color: #0056b3;
        background-color: #e9ecef;
        border-color: #dee2e6;
    }
    
    .page-item.disabled .page-link {
        color: #6c757d;
        pointer-events: none;
        background-color: #fff;
        border-color: #dee2e6;
    }
    
    .records-per-page-selector {
        max-width: 100px;
    }
    
    .balance-breakdown {
        background-color: #f8f9fa;
        border-radius: 8px;
        padding: 15px;
        margin-bottom: 20px;
    }
    
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
    
    .entity-type-pill {
        cursor: pointer;
        transition: all 0.2s;
        border: 1px solid #dee2e6;
        border-radius: 8px;
        padding: 10px 5px;
        text-align: center;
        background: white;
    }
    
    .entity-type-pill:hover {
        transform: scale(1.05);
        box-shadow: 0 4px 8px rgba(0,0,0,0.1);
    }
    
    .entity-type-pill.active {
        border: 2px solid #007bff;
        box-shadow: 0 0 0 3px rgba(0,123,255,0.25);
        background-color: #f8f9fa;
    }
    
    .entity-type-icon {
        font-size: 1.5rem;
        margin-bottom: 5px;
    }
    
    .entity-type-name {
        font-weight: 600;
        font-size: 0.85rem;
        margin-bottom: 3px;
    }
    
    .entity-type-count {
        font-size: 0.75rem;
        color: #6c757d;
    }
    
    .filter-section {
        background-color: #f8f9fa;
        border-radius: 8px;
        padding: 15px;
        margin-bottom: 20px;
    }
    
    .transaction-inflow {
        color: #28a745;
        font-weight: bold;
    }
    
    .transaction-outflow {
        color: #dc3545;
        font-weight: bold;
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

    <!-- Page Header -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h1 class="h3 mb-0 text-dark"><i class="bi bi-calculator me-2"></i>Comprehensive Debt & Credit Tracking</h1>
                    <p class="text-muted mb-0">Track balances across all entities in the system</p>
                </div>
                <div class="btn-group">
                    <button type="button" class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#helpModal">
                        <i class="bi bi-question-circle me-1"></i>Help
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Entity Type Selection -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="card">
                <div class="card-header bg-light d-flex justify-content-between align-items-center">
                    <h6 class="mb-0"><i class="bi bi-filter me-2"></i>Filter by Entity Type</h6>
                    <small class="text-muted">Click on any entity type to filter</small>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <!-- All Entities -->
                        <div class="col-6 col-md-4 col-lg-2">
                            <a href="?entity_type=&<?php echo http_build_query(array_diff_key($_GET, ['entity_type' => '', 'page' => ''])); ?>" 
                               class="text-decoration-none">
                                <div class="entity-type-pill <?php echo !$entity_type ? 'active' : ''; ?>">
                                    <div class="entity-type-icon text-primary">
                                        <i class="bi bi-people-fill"></i>
                                    </div>
                                    <div class="entity-type-name">All Entities</div>
                                    <div class="entity-type-count"><?php echo $total_count; ?> total</div>
                                </div>
                            </a>
                        </div>
                        
                        <!-- Clients -->
                        <div class="col-6 col-md-4 col-lg-2">
                            <a href="?entity_type=client&<?php echo http_build_query(array_diff_key($_GET, ['entity_type' => '', 'page' => ''])); ?>" 
                               class="text-decoration-none">
                                <div class="entity-type-pill <?php echo $entity_type == 'client' ? 'active' : ''; ?>" 
                                     style="border-left: 4px solid #007bff;">
                                    <div class="entity-type-icon text-primary">
                                        <i class="bi bi-person-badge"></i>
                                    </div>
                                    <div class="entity-type-name">Clients</div>
                                    <div class="entity-type-count"><?php echo $summary_stats['by_type']['client']['count'] ?? 0; ?> clients</div>
                                </div>
                            </a>
                        </div>
                        
                        <!-- Custodians -->
                        <div class="col-6 col-md-4 col-lg-2">
                            <a href="?entity_type=custodian&<?php echo http_build_query(array_diff_key($_GET, ['entity_type' => '', 'page' => ''])); ?>" 
                               class="text-decoration-none">
                                <div class="entity-type-pill <?php echo $entity_type == 'custodian' ? 'active' : ''; ?>" 
                                     style="border-left: 4px solid #17a2b8;">
                                    <div class="entity-type-icon text-info">
                                        <i class="bi bi-shield-check"></i>
                                    </div>
                                    <div class="entity-type-name">Custodians</div>
                                    <div class="entity-type-count"><?php echo $summary_stats['by_type']['custodian']['count'] ?? 0; ?> custodians</div>
                                </div>
                            </a>
                        </div>
                        
                        <!-- Employees -->
                        <div class="col-6 col-md-4 col-lg-2">
                            <a href="?entity_type=employee&<?php echo http_build_query(array_diff_key($_GET, ['entity_type' => '', 'page' => ''])); ?>" 
                               class="text-decoration-none">
                                <div class="entity-type-pill <?php echo $entity_type == 'employee' ? 'active' : ''; ?>" 
                                     style="border-left: 4px solid #28a745;">
                                    <div class="entity-type-icon text-success">
                                        <i class="bi bi-person-workspace"></i>
                                    </div>
                                    <div class="entity-type-name">Employees</div>
                                    <div class="entity-type-count"><?php echo $summary_stats['by_type']['employee']['count'] ?? 0; ?> employees</div>
                                </div>
                            </a>
                        </div>
                        
                        <!-- Agents -->
                        <div class="col-6 col-md-4 col-lg-2">
                            <a href="?entity_type=agent&<?php echo http_build_query(array_diff_key($_GET, ['entity_type' => '', 'page' => ''])); ?>" 
                               class="text-decoration-none">
                                <div class="entity-type-pill <?php echo $entity_type == 'agent' ? 'active' : ''; ?>" 
                                     style="border-left: 4px solid #ffc107;">
                                    <div class="entity-type-icon text-warning">
                                        <i class="bi bi-person-rolodex"></i>
                                    </div>
                                    <div class="entity-type-name">Agents</div>
                                    <div class="entity-type-count"><?php echo $summary_stats['by_type']['agent']['count'] ?? 0; ?> agents</div>
                                </div>
                            </a>
                        </div>
                        
                        <!-- Brokers -->
                        <div class="col-6 col-md-4 col-lg-2">
                            <a href="?entity_type=broker&<?php echo http_build_query(array_diff_key($_GET, ['entity_type' => '', 'page' => ''])); ?>" 
                               class="text-decoration-none">
                                <div class="entity-type-pill <?php echo $entity_type == 'broker' ? 'active' : ''; ?>" 
                                     style="border-left: 4px solid #fd7e14;">
                                    <div class="entity-type-icon" style="color: #fd7e14;">
                                        <i class="bi bi-graph-up"></i>
                                    </div>
                                    <div class="entity-type-name">Brokers</div>
                                    <div class="entity-type-count"><?php echo $summary_stats['by_type']['broker']['count'] ?? 0; ?> brokers</div>
                                </div>
                            </a>
                        </div>
                        
                        <!-- Suppliers -->
                        <div class="col-6 col-md-4 col-lg-2">
                            <a href="?entity_type=supplier&<?php echo http_build_query(array_diff_key($_GET, ['entity_type' => '', 'page' => ''])); ?>" 
                               class="text-decoration-none">
                                <div class="entity-type-pill <?php echo $entity_type == 'supplier' ? 'active' : ''; ?>" 
                                     style="border-left: 4px solid #e83e8c;">
                                    <div class="entity-type-icon text-pink">
                                        <i class="bi bi-truck"></i>
                                    </div>
                                    <div class="entity-type-name">Suppliers</div>
                                    <div class="entity-type-count"><?php echo $summary_stats['by_type']['supplier']['count'] ?? 0; ?> suppliers</div>
                                </div>
                            </a>
                        </div>
                        
                        <!-- Chart Accounts -->
                        <div class="col-6 col-md-4 col-lg-2">
                            <a href="?entity_type=chart_account&<?php echo http_build_query(array_diff_key($_GET, ['entity_type' => '', 'page' => ''])); ?>" 
                               class="text-decoration-none">
                                <div class="entity-type-pill <?php echo $entity_type == 'chart_account' ? 'active' : ''; ?>" 
                                     style="border-left: 4px solid #6f42c1;">
                                    <div class="entity-type-icon text-purple">
                                        <i class="bi bi-journal-bookmark"></i>
                                    </div>
                                    <div class="entity-type-name">Chart Accounts</div>
                                    <div class="entity-type-count"><?php echo $summary_stats['by_type']['chart_account']['count'] ?? 0; ?> accounts</div>
                                </div>
                            </a>
                        </div>
                        
                        <!-- Bank Accounts -->
                        <div class="col-6 col-md-4 col-lg-2">
                            <a href="?entity_type=bank_account&<?php echo http_build_query(array_diff_key($_GET, ['entity_type' => '', 'page' => ''])); ?>" 
                               class="text-decoration-none">
                                <div class="entity-type-pill <?php echo $entity_type == 'bank_account' ? 'active' : ''; ?>" 
                                     style="border-left: 4px solid #20c997;">
                                    <div class="entity-type-icon text-teal">
                                        <i class="bi bi-bank"></i>
                                    </div>
                                    <div class="entity-type-name">Bank Accounts</div>
                                    <div class="entity-type-count"><?php echo $summary_stats['by_type']['bank_account']['count'] ?? 0; ?> accounts</div>
                                </div>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Summary Statistics -->
    <div class="row mb-4">
        <div class="col-md-2 mb-3">
            <div class="card stats-card stats-card-total">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="text-muted mb-1">Total Entities</h6>
                            <h3 class="mb-0"><?php echo $summary_stats['total_entities']; ?></h3>
                        </div>
                        <div class="bg-primary text-white rounded-circle p-3">
                            <i class="bi bi-people fs-4"></i>
                        </div>
                    </div>
                    <div class="mt-3">
                        <small class="text-muted">
                            <span class="text-success"><?php echo $summary_stats['entities_in_credit']; ?> Credit</span> | 
                            <span class="text-danger"><?php echo $summary_stats['entities_in_debit']; ?> Debit</span> | 
                            <span class="text-secondary"><?php echo $summary_stats['entities_settled']; ?> Settled</span>
                        </small>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-md-2 mb-3">
            <div class="card stats-card stats-card-debit">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="text-muted mb-1">Total Debit</h6>
                            <h3 class="mb-0 text-danger"><?php echo formatCurrency($summary_stats['total_debit_balance']); ?></h3>
                        </div>
                        <div class="bg-danger text-white rounded-circle p-3">
                            <i class="bi bi-arrow-down-circle fs-4"></i>
                        </div>
                    </div>
                    <div class="mt-3">
                        <small class="text-muted">
                            <?php echo $entity_type == 'bank_account' ? 'Bank Deficits' : 'Money owed to us'; ?>
                        </small>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-md-2 mb-3">
            <div class="card stats-card stats-card-credit">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="text-muted mb-1">Total Credit</h6>
                            <h3 class="mb-0 text-success"><?php echo formatCurrency($summary_stats['total_credit_balance']); ?></h3>
                        </div>
                        <div class="bg-success text-white rounded-circle p-3">
                            <i class="bi bi-arrow-up-circle fs-4"></i>
                        </div>
                    </div>
                    <div class="mt-3">
                        <small class="text-muted">
                            <?php echo $entity_type == 'bank_account' ? 'Bank Surplus' : 'Money we owe'; ?>
                        </small>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-md-3 mb-3">
            <div class="card stats-card stats-card-net">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="text-muted mb-1">Net Position</h6>
                            <h3 class="mb-0 <?php echo $net_balance >= 0 ? 'text-success' : 'text-danger'; ?>">
                                <?php echo formatCurrency(abs($net_balance)); ?>
                            </h3>
                        </div>
                        <div class="<?php echo $net_balance >= 0 ? 'bg-success' : 'bg-danger'; ?> text-white rounded-circle p-3">
                            <i class="bi bi-calculator fs-4"></i>
                        </div>
                    </div>
                    <div class="mt-3">
                        <small class="text-muted">
                            <?php 
                            if ($entity_type == 'bank_account') {
                                echo $net_balance >= 0 ? 'Overall Bank Surplus' : 'Overall Bank Deficit';
                            } else {
                                echo $net_balance >= 0 ? 'We owe more to entities' : 'Entities owe more to us';
                            }
                            ?>
                        </small>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-md-3 mb-3">
            <div class="card stats-card stats-card-overdue">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="text-muted mb-1">Total Overdue</h6>
                            <h3 class="mb-0 text-warning"><?php echo formatCurrency($summary_stats['total_overdue']); ?></h3>
                        </div>
                        <div class="bg-warning text-white rounded-circle p-3">
                            <i class="bi bi-clock-history fs-4"></i>
                        </div>
                    </div>
                    <div class="mt-3">
                        <div class="progress" style="height: 6px;">
                            <div class="progress-bar progress-bar-overdue" 
                                 style="width: <?php echo $summary_stats['total_debit_balance'] > 0 ? ($summary_stats['total_overdue'] / $summary_stats['total_debit_balance'] * 100) : 0; ?>%">
                            </div>
                        </div>
                        <small class="text-muted">
                            <?php echo $summary_stats['total_debit_balance'] > 0 ? 
                                number_format($summary_stats['total_overdue'] / $summary_stats['total_debit_balance'] * 100, 1) : 0; ?>% of total debit
                        </small>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Filters Section -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="card">
                <div class="card-header bg-light">
                    <h6 class="mb-0"><i class="bi bi-funnel me-2"></i>Advanced Filters & Export</h6>
                </div>
                <div class="card-body">
                    <form method="GET" id="filterForm">
                        <input type="hidden" name="page" value="1">
                        <?php if ($entity_type): ?>
                            <input type="hidden" name="entity_type" value="<?php echo htmlspecialchars($entity_type); ?>">
                        <?php endif; ?>
                        
                        <div class="row g-3">
                            <div class="col-md-3">
                                <label class="form-label">Date Range</label>
                                <div class="row g-2 mb-2">
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
                                
                                <label class="form-label">As of Date</label>
                                <input type="date" class="form-control form-control-sm" name="as_of_date" 
                                       value="<?php echo htmlspecialchars($as_of_date); ?>"
                                       max="<?php echo date('Y-m-d'); ?>">
                            </div>
                            
                            <div class="col-md-3">
                                <div class="mb-3">
                                    <label class="form-label">Balance Status</label>
                                    <select class="form-select" name="balance_status">
                                        <option value="all" <?php echo $balance_status === 'all' ? 'selected' : ''; ?>>All Balances</option>
                                        <option value="credit" <?php echo $balance_status === 'credit' ? 'selected' : ''; ?>>Credit <?php echo $entity_type == 'bank_account' ? '(Positive)' : '(We Owe)'; ?></option>
                                        <option value="debit" <?php echo $balance_status === 'debit' ? 'selected' : ''; ?>>Debit <?php echo $entity_type == 'bank_account' ? '(Negative)' : '(Entity Owes Us)'; ?></option>
                                        <option value="settled" <?php echo $balance_status === 'settled' ? 'selected' : ''; ?>>Settled (Zero Balance)</option>
                                    </select>
                                </div>
                                
                                <div class="mb-3">
                                    <label class="form-label">Aging Status</label>
                                    <select class="form-select" name="aging_filter">
                                        <option value="all" <?php echo $aging_filter === 'all' ? 'selected' : ''; ?>>All Aging Status</option>
                                        <option value="current" <?php echo $aging_filter === 'current' ? 'selected' : ''; ?>>Current (0% Overdue)</option>
                                        <option value="overdue_low" <?php echo $aging_filter === 'overdue_low' ? 'selected' : ''; ?>>Low Overdue (1-10%)</option>
                                        <option value="overdue_medium" <?php echo $aging_filter === 'overdue_medium' ? 'selected' : ''; ?>>Medium Overdue (11-30%)</option>
                                        <option value="overdue_high" <?php echo $aging_filter === 'overdue_high' ? 'selected' : ''; ?>>High Overdue (30%+)</option>
                                    </select>
                                </div>
                            </div>
                            
                            <div class="col-md-3">
                                <div class="mb-3">
                                    <label class="form-label">Records per page</label>
                                    <select class="form-select records-per-page-selector" name="per_page" onchange="updatePerPage(this.value)">
                                        <option value="10" <?php echo $records_per_page == 10 ? 'selected' : ''; ?>>10</option>
                                        <option value="20" <?php echo $records_per_page == 20 ? 'selected' : ''; ?>>20</option>
                                        <option value="50" <?php echo $records_per_page == 50 ? 'selected' : ''; ?>>50</option>
                                        <option value="100" <?php echo $records_per_page == 100 ? 'selected' : ''; ?>>100</option>
                                        <option value="0" <?php echo $records_per_page == 0 ? 'selected' : ''; ?>>All</option>
                                    </select>
                                </div>
                                
                                <div class="d-grid gap-2">
                                    <button type="submit" class="btn btn-primary btn-sm">
                                        <i class="bi bi-filter me-1"></i>Apply Filters
                                    </button>
                                    <button type="button" class="btn btn-outline-secondary btn-sm" onclick="resetFilters()">
                                        <i class="bi bi-arrow-clockwise me-1"></i>Reset Filters
                                    </button>
                                </div>
                            </div>
                            
                            <div class="col-md-3">
                                <div class="d-flex flex-column h-100 justify-content-between">
                                    <div>
                                        <label class="form-label">Export Options</label>
                                        <div class="d-grid gap-2">
                                            <a href="?<?php echo http_build_query(array_merge($_GET, ['export' => 'excel', 'page' => 1])); ?>" 
                                               class="btn btn-success btn-sm export-btn"
                                               onclick="return confirm('Export <?php echo count($all_data); ?> entities to Excel?')">
                                                <i class="bi bi-file-excel me-1"></i>Export to Excel
                                            </a>
                                            <a href="?<?php echo http_build_query(array_merge($_GET, ['export' => 'pdf', 'page' => 1])); ?>" 
                                               class="btn btn-danger btn-sm">
                                                <i class="bi bi-file-pdf me-1"></i>Export to PDF
                                            </a>
                                        </div>
                                    </div>
                                    
                                    <div class="mt-3">
                                        <small class="text-muted">
                                            <i class="bi bi-info-circle me-1"></i>
                                            Excel export includes detailed transaction history
                                        </small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Pagination Info -->
    <div class="row mb-3">
        <div class="col-md-6">
            <div class="d-flex align-items-center">
                <div class="me-3">
                    <span class="text-muted">
                        Showing <?php echo count($all_data); ?> of <?php echo $total_count; ?> entities
                        <?php if ($entity_type): ?> (<?php echo ucfirst($entity_type); ?>s only)<?php endif; ?>
                    </span>
                </div>
                <div>
                    <?php if ($total_pages > 1): ?>
                        <span class="badge bg-info">Page <?php echo $current_page; ?> of <?php echo $total_pages; ?></span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <?php if ($total_pages > 1): ?>
                <nav aria-label="Page navigation">
                    <ul class="pagination justify-content-end mb-0">
                        <li class="page-item <?php echo $current_page == 1 ? 'disabled' : ''; ?>">
                            <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => 1])); ?>" aria-label="First">
                                <span aria-hidden="true">&laquo;&laquo;</span>
                            </a>
                        </li>
                        
                        <li class="page-item <?php echo $current_page == 1 ? 'disabled' : ''; ?>">
                            <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => max(1, $current_page - 1)])); ?>" aria-label="Previous">
                                <span aria-hidden="true">&laquo;</span>
                            </a>
                        </li>
                        
                        <?php 
                        $start_page = max(1, $current_page - 2);
                        $end_page = min($total_pages, $current_page + 2);
                        
                        if ($start_page > 1): ?>
                            <li class="page-item disabled"><span class="page-link">...</span></li>
                        <?php endif; ?>
                        
                        <?php for ($i = $start_page; $i <= $end_page; $i++): ?>
                            <li class="page-item <?php echo $i == $current_page ? 'active' : ''; ?>">
                                <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>">
                                    <?php echo $i; ?>
                                </a>
                            </li>
                        <?php endfor; ?>
                        
                        <?php if ($end_page < $total_pages): ?>
                            <li class="page-item disabled"><span class="page-link">...</span></li>
                        <?php endif; ?>
                        
                        <li class="page-item <?php echo $current_page == $total_pages ? 'disabled' : ''; ?>">
                            <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => min($total_pages, $current_page + 1)])); ?>" aria-label="Next">
                                <span aria-hidden="true">&raquo;</span>
                            </a>
                        </li>
                        
                        <li class="page-item <?php echo $current_page == $total_pages ? 'disabled' : ''; ?>">
                            <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $total_pages])); ?>" aria-label="Last">
                                <span aria-hidden="true">&raquo;&raquo;</span>
                            </a>
                        </li>
                    </ul>
                </nav>
            <?php endif; ?>
        </div>
    </div>

    <!-- Entity Balances Table -->
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-header bg-light d-flex justify-content-between align-items-center">
                    <h6 class="mb-0"><i class="bi bi-table me-2"></i>Entity Balances & Aging Analysis</h6>
                    <small class="text-muted">
                        Page <?php echo $current_page; ?> of <?php echo $total_pages; ?> 
                        (<?php echo count($all_data); ?> entities on this page)
                    </small>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($all_data)): ?>
                        <div class="text-center py-5">
                            <i class="bi bi-inbox" style="font-size: 3rem; color: #6c757d;"></i>
                            <h5 class="mt-3 text-muted">No entity data found</h5>
                            <p class="text-muted">Try adjusting your filter criteria</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Entity Type</th>
                                        <th>Entity Code</th>
                                        <th>Entity Name</th>
                                        <th>Total Receipts (<?php echo $entity_type == 'bank_account' ? 'Inflows' : 'Received'; ?>)</th>
                                        <th>Total Payments (<?php echo $entity_type == 'bank_account' ? 'Outflows' : 'Paid'; ?>)</th>
                                        <th>Debit Balance</th>
                                        <th>Credit Balance</th>
                                        <th>Net Balance</th>
                                        <th>Aging Analysis</th>
                                        <th>% Overdue</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($all_data as $entity_data): 
                                        $entity_info = $entity_data['entity_info'];
                                        $totals = $entity_data['totals'];
                                        $aging = $entity_data['aging'];
                                    ?>
                                        <tr>
                                            <td>
                                                <span class="entity-type-badge badge-<?php echo $entity_info['type']; ?>">
                                                    <?php echo ucfirst($entity_info['type']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <code><?php echo htmlspecialchars($entity_info['code']); ?></code>
                                            </td>
                                            <td>
                                                <strong><?php echo htmlspecialchars($entity_info['name']); ?></strong>
                                                <?php if ($entity_info['type'] == 'client' && isset($entity_info['details']['client_type'])): ?>
                                                    <br><small class="text-muted"><?php echo htmlspecialchars($entity_info['details']['client_type']); ?></small>
                                                <?php endif; ?>
                                                <?php if ($entity_info['type'] == 'bank_account' && isset($entity_info['details']['bank_name'])): ?>
                                                    <br><small class="text-muted"><?php echo htmlspecialchars($entity_info['details']['bank_name']); ?></small>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-success fw-bold">
                                                <?php echo formatCurrency($totals['receipts']); ?>
                                            </td>
                                            <td class="text-danger fw-bold">
                                                <?php echo formatCurrency($totals['payments']); ?>
                                            </td>
                                            <td class="text-danger fw-bold">
                                                <?php echo $totals['debit_balance'] > 0 ? formatCurrency($totals['debit_balance']) : '-'; ?>
                                            </td>
                                            <td class="text-success fw-bold">
                                                <?php echo $totals['credit_balance'] > 0 ? formatCurrency($totals['credit_balance']) : '-'; ?>
                                            </td>
                                            <td class="<?php 
                                                echo $totals['net_balance'] > 0 ? 'balance-positive' : 
                                                    ($totals['net_balance'] < 0 ? 'balance-negative' : 'balance-zero'); 
                                            ?> fw-bold">
                                                <?php echo formatCurrency(abs($totals['net_balance'])); ?>
                                            </td>
                                            <td>
                                                <?php if ($entity_info['type'] !== 'bank_account'): ?>
                                                <div class="d-flex gap-1 mb-1">
                                                    <?php if ($aging['buckets']['Current'] > 0): ?>
                                                        <span class="badge aging-badge aging-current" title="Current (0-30 days)">
                                                            C: <?php echo number_format($aging['buckets']['Current'] / 1000, 1); ?>K
                                                        </span>
                                                    <?php endif; ?>
                                                    <?php if ($aging['buckets']['31-60 Days'] > 0): ?>
                                                        <span class="badge aging-badge aging-31-60" title="31-60 Days Overdue">
                                                            31-60: <?php echo number_format($aging['buckets']['31-60 Days'] / 1000, 1); ?>K
                                                        </span>
                                                    <?php endif; ?>
                                                    <?php if ($aging['buckets']['61-90 Days'] > 0): ?>
                                                        <span class="badge aging-badge aging-61-90" title="61-90 Days Overdue">
                                                            61-90: <?php echo number_format($aging['buckets']['61-90 Days'] / 1000, 1); ?>K
                                                        </span>
                                                    <?php endif; ?>
                                                    <?php if ($aging['buckets']['90+ Days'] > 0): ?>
                                                        <span class="badge aging-badge aging-90plus" title="90+ Days Overdue">
                                                            90+: <?php echo number_format($aging['buckets']['90+ Days'] / 1000, 1); ?>K
                                                        </span>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="progress" style="height: 5px;">
                                                    <?php $total_aging = array_sum($aging['buckets']); ?>
                                                    <?php if ($total_aging > 0): ?>
                                                        <div class="progress-bar progress-bar-current" 
                                                             style="width: <?php echo ($aging['buckets']['Current'] / $total_aging * 100); ?>%">
                                                        </div>
                                                        <div class="progress-bar bg-warning" 
                                                             style="width: <?php echo ($aging['buckets']['31-60 Days'] / $total_aging * 100); ?>%">
                                                        </div>
                                                        <div class="progress-bar bg-danger" 
                                                             style="width: <?php echo (($aging['buckets']['61-90 Days'] + $aging['buckets']['90+ Days']) / $total_aging * 100); ?>%">
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                                <?php else: ?>
                                                    <span class="text-muted">N/A for Bank Accounts</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($entity_info['type'] !== 'bank_account'): ?>
                                                <span class="<?php echo $aging['overdue_ratio'] > 30 ? 'text-danger fw-bold' : ($aging['overdue_ratio'] > 10 ? 'text-warning fw-bold' : 'text-success'); ?>">
                                                    <?php echo number_format($aging['overdue_ratio'], 1); ?>%
                                                </span>
                                                <?php else: ?>
                                                    <span class="text-muted">N/A</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div class="btn-group btn-group-sm">
                                                    <button type="button" class="btn btn-outline-primary view-transactions" 
                                                            data-entity-type="<?php echo $entity_info['type']; ?>"
                                                            data-entity-id="<?php echo $entity_info['id']; ?>"
                                                            data-entity-name="<?php echo htmlspecialchars($entity_info['name']); ?>">
                                                        <i class="bi bi-list-ul"></i>
                                                    </button>
                                                    <button type="button" class="btn btn-outline-info view-details" 
                                                            data-entity-type="<?php echo $entity_info['type']; ?>"
                                                            data-entity-id="<?php echo $entity_info['id']; ?>"
                                                            data-entity-name="<?php echo htmlspecialchars($entity_info['name']); ?>">
                                                        <i class="bi bi-eye"></i>
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                                <tfoot class="table-light">
                                    <tr>
                                        <th colspan="3">PAGE TOTALS:</th>
                                        <th class="text-success fw-bold"><?php echo formatCurrency($summary_stats['total_receipts']); ?></th>
                                        <th class="text-danger fw-bold"><?php echo formatCurrency($summary_stats['total_payments']); ?></th>
                                        <th class="text-danger fw-bold"><?php echo formatCurrency($summary_stats['total_debit_balance']); ?></th>
                                        <th class="text-success fw-bold"><?php echo formatCurrency($summary_stats['total_credit_balance']); ?></th>
                                        <th class="<?php echo $net_balance >= 0 ? 'balance-positive' : 'balance-negative'; ?> fw-bold">
                                            <?php echo formatCurrency(abs($net_balance)); ?>
                                        </th>
                                        <th>
                                            <?php if ($entity_type !== 'bank_account'): ?>
                                            <small class="text-muted">
                                                Current: <?php echo formatCurrency($summary_stats['total_current']); ?> | 
                                                Overdue: <?php echo formatCurrency($summary_stats['total_overdue']); ?>
                                            </small>
                                            <?php else: ?>
                                            <small class="text-muted">Bank Account Analysis</small>
                                            <?php endif; ?>
                                        </th>
                                        <th>
                                            <?php if ($entity_type !== 'bank_account'): ?>
                                            <?php echo $summary_stats['total_debit_balance'] > 0 ? 
                                                number_format($summary_stats['total_overdue'] / $summary_stats['total_debit_balance'] * 100, 1) : 0; ?>%
                                            <?php else: ?>
                                            N/A
                                            <?php endif; ?>
                                        </th>
                                        <th></th>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
                
                <?php if ($total_pages > 1): ?>
                <div class="card-footer">
                    <div class="row">
                        <div class="col-md-6">
                            <small class="text-muted">
                                Page <?php echo $current_page; ?> of <?php echo $total_pages; ?>
                            </small>
                        </div>
                        <div class="col-md-6">
                            <nav aria-label="Page navigation" class="float-end">
                                <ul class="pagination pagination-sm mb-0">
                                    <li class="page-item <?php echo $current_page == 1 ? 'disabled' : ''; ?>">
                                        <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => max(1, $current_page - 1)])); ?>">
                                            Previous
                                        </a>
                                    </li>
                                    
                                    <?php for ($i = max(1, $current_page - 1); $i <= min($total_pages, $current_page + 3); $i++): ?>
                                        <li class="page-item <?php echo $i == $current_page ? 'active' : ''; ?>">
                                            <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>">
                                                <?php echo $i; ?>
                                            </a>
                                        </li>
                                    <?php endfor; ?>
                                    
                                    <li class="page-item <?php echo $current_page == $total_pages ? 'disabled' : ''; ?>">
                                        <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => min($total_pages, $current_page + 1)])); ?>">
                                            Next
                                        </a>
                                    </li>
                                </ul>
                            </nav>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Transaction Details Modal -->
<div class="modal fade" id="transactionModal" tabindex="-1" aria-labelledby="transactionModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title" id="transactionModalLabel">Transaction History</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row mb-3">
                    <div class="col-md-6">
                        <h6 id="entityNameHeader"></h6>
                        <small class="text-muted" id="entityCodeHeader"></small>
                    </div>
                    <div class="col-md-6 text-end">
                        <div id="runningBalance" class="fw-bold fs-5"></div>
                        <small class="text-muted">Current Balance</small>
                    </div>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm table-hover" id="transactionTable">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Type</th>
                                <th>Reference</th>
                                <th>Description</th>
                                <th>Bank Account</th>
                                <th>Amount</th>
                                <th>Running Balance</th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-primary" onclick="printTransactionHistory()">
                    <i class="bi bi-printer me-1"></i>Print
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Entity Details Modal -->
<div class="modal fade" id="entityDetailsModal" tabindex="-1" aria-labelledby="entityDetailsModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title" id="entityDetailsModalLabel">Entity Balance Details</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="entityDetailsContent"></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Help Modal -->
<div class="modal fade" id="helpModal" tabindex="-1" aria-labelledby="helpModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-warning text-dark">
                <h5 class="modal-title" id="helpModalLabel"><i class="bi bi-question-circle me-2"></i>How to Use This Report</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <h6>Understanding Entity Types:</h6>
                <ul>
                    <li><strong>Clients:</strong> Company customers with trading accounts</li>
                    <li><strong>Custodians:</strong> Entities holding company assets</li>
                    <li><strong>Employees:</strong> Company staff members</li>
                    <li><strong>Agents:</strong> Sales or service agents</li>
                    <li><strong>Brokers:</strong> Trading intermediaries</li>
                    <li><strong>Suppliers:</strong> Goods/service providers</li>
                    <li><strong>Chart Accounts:</strong> Nominal accounts for expenses/income</li>
                    <li><strong>Bank Accounts:</strong> Company bank accounts with inflows and outflows</li>
                </ul>
                
                <h6 class="mt-4">Understanding Transactions:</h6>
                <ul>
                    <li><strong>For Non-Bank Entities:</strong>
                        <ul>
                            <li>Receipts: Money we received (entity owes us)</li>
                            <li>Payments: Money we paid (we owe entity)</li>
                            <li>Debit Balance: Entity owes us money</li>
                            <li>Credit Balance: We owe entity money</li>
                        </ul>
                    </li>
                    <li><strong>For Bank Accounts:</strong>
                        <ul>
                            <li>Receipts: Money coming into bank (inflows)</li>
                            <li>Payments: Money going out of bank (outflows)</li>
                            <li>Debit Balance: Bank deficit (negative balance)</li>
                            <li>Credit Balance: Bank surplus (positive balance)</li>
                            <li>Net Balance: Current bank balance</li>
                        </ul>
                    </li>
                </ul>
                
                <h6 class="mt-4">Export Features:</h6>
                <ul>
                    <li><strong>Excel Export:</strong> Complete data with transaction history including bank account details</li>
                    <li><strong>PDF Export:</strong> Formatted report for printing</li>
                    <li><strong>Filtered Export:</strong> Exports only filtered results</li>
                </ul>
                
                <div class="alert alert-info mt-4">
                    <i class="bi bi-lightbulb me-2"></i>
                    <strong>Tip:</strong> Click on entity type pills at the top to filter by specific entity types.
                    Click the "Bank Accounts" pill to see all bank account inflows and outflows.
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Update records per page
    window.updatePerPage = function(value) {
        document.querySelector('input[name="page"]').value = 1;
        
        let perPageInput = document.querySelector('input[name="per_page"]');
        if (!perPageInput) {
            perPageInput = document.createElement('input');
            perPageInput.type = 'hidden';
            perPageInput.name = 'per_page';
            document.getElementById('filterForm').appendChild(perPageInput);
        }
        perPageInput.value = value;
        
        document.getElementById('filterForm').submit();
    };
    
    // Reset filters
    window.resetFilters = function() {
        window.location.href = window.location.pathname;
    };
    
    // View transactions
    document.querySelectorAll('.view-transactions').forEach(button => {
        button.addEventListener('click', function() {
            const entityType = this.getAttribute('data-entity-type');
            const entityId = this.getAttribute('data-entity-id');
            const entityName = this.getAttribute('data-entity-name');
            
            // Find entity data
            const entityData = <?php echo json_encode($all_data); ?>.find(e => 
                e.entity_info.type === entityType && e.entity_info.id.toString() === entityId
            );
            
            if (entityData) {
                const entityInfo = entityData.entity_info;
                const transactions = entityData.transactions;
                const totals = entityData.totals;
                
                document.getElementById('entityNameHeader').textContent = entityInfo.name;
                document.getElementById('entityCodeHeader').textContent = entityInfo.type.charAt(0).toUpperCase() + entityInfo.type.slice(1) + ' - ' + entityInfo.code;
                
                if (entityInfo.type === 'bank_account') {
                    document.getElementById('runningBalance').textContent = 
                        'Current Balance: ' + formatCurrency(totals.net_balance);
                    document.getElementById('runningBalance').className = 
                        'fw-bold fs-5 ' + (totals.net_balance >= 0 ? 'text-success' : 'text-danger');
                } else {
                    document.getElementById('runningBalance').textContent = 
                        totals.net_balance > 0 ? 
                            'Credit: ' + formatCurrency(totals.net_balance) : 
                        totals.net_balance < 0 ? 
                            'Debit: ' + formatCurrency(Math.abs(totals.net_balance)) : 
                            'Settled';
                    document.getElementById('runningBalance').className = 
                        'fw-bold fs-5 ' + (totals.net_balance > 0 ? 'text-success' : 
                                          totals.net_balance < 0 ? 'text-danger' : 'text-muted');
                }
                
                const tbody = document.querySelector('#transactionTable tbody');
                tbody.innerHTML = '';
                
                transactions.forEach(transaction => {
                    const row = document.createElement('tr');
                    const amountClass = transaction.amount > 0 ? 'transaction-inflow' : 'transaction-outflow';
                    const balanceClass = transaction.running_balance >= 0 ? 'text-success' : 'text-danger';
                    const typeBadge = transaction.type === 'payment' ? 'bg-danger' : 'bg-success';
                    const typeText = transaction.type === 'payment' ? 
                        (entityInfo.type === 'bank_account' ? 'OUTFLOW' : 'PAYMENT') : 
                        (entityInfo.type === 'bank_account' ? 'INFLOW' : 'RECEIPT');
                    
                    row.innerHTML = `
                        <td>${formatDate(transaction.date)}</td>
                        <td><span class="badge ${typeBadge}">${typeText}</span></td>
                        <td><code>${transaction.reference}</code></td>
                        <td>${transaction.description}</td>
                        <td>${transaction.bank_account || 'N/A'}</td>
                        <td class="${amountClass} fw-bold">${formatCurrency(transaction.amount, transaction.currency)}</td>
                        <td class="${balanceClass} fw-bold">${formatCurrency(transaction.running_balance, transaction.currency)}</td>
                    `;
                    tbody.appendChild(row);
                });
                
                const modal = new bootstrap.Modal(document.getElementById('transactionModal'));
                modal.show();
            }
        });
    });
    
    // View entity details
    document.querySelectorAll('.view-details').forEach(button => {
        button.addEventListener('click', function() {
            const entityType = this.getAttribute('data-entity-type');
            const entityId = this.getAttribute('data-entity-id');
            const entityName = this.getAttribute('data-entity-name');
            
            const entityData = <?php echo json_encode($all_data); ?>.find(e => 
                e.entity_info.type === entityType && e.entity_info.id.toString() === entityId
            );
            
            if (entityData) {
                const entityInfo = entityData.entity_info;
                const totals = entityData.totals;
                const aging = entityData.aging;
                
                let details = '';
                let additionalInfo = '';
                
                switch(entityInfo.type) {
                    case 'client':
                        details = entityInfo.details.client_type || 'N/A';
                        additionalInfo = `
                            <tr><th>CDS Account:</th><td>${entityInfo.details.cds_account || 'N/A'}</td></tr>
                            <tr><th>Phone:</th><td>${entityInfo.details.phone || 'N/A'}</td></tr>
                            <tr><th>Email:</th><td>${entityInfo.details.email || 'N/A'}</td></tr>
                        `;
                        break;
                    case 'employee':
                        details = entityInfo.details.role || 'N/A';
                        additionalInfo = `
                            <tr><th>Username:</th><td>${entityInfo.details.code || 'N/A'}</td></tr>
                            <tr><th>Phone:</th><td>${entityInfo.details.phone || 'N/A'}</td></tr>
                            <tr><th>Email:</th><td>${entityInfo.details.email || 'N/A'}</td></tr>
                        `;
                        break;
                    case 'agent':
                    case 'supplier':
                    case 'custodian':
                    case 'broker':
                        details = entityInfo.details.contact_person || 'N/A';
                        additionalInfo = `
                            <tr><th>Contact Person:</th><td>${entityInfo.details.contact_person || 'N/A'}</td></tr>
                            <tr><th>Phone:</th><td>${entityInfo.details.phone || 'N/A'}</td></tr>
                            <tr><th>Email:</th><td>${entityInfo.details.email || 'N/A'}</td></tr>
                        `;
                        break;
                    case 'chart_account':
                        details = entityInfo.details.account_type || 'N/A';
                        additionalInfo = `
                            <tr><th>Account Type:</th><td>${entityInfo.details.account_type || 'N/A'}</td></tr>
                            <tr><th>Level:</th><td>${entityInfo.details.level || 'N/A'}</td></tr>
                        `;
                        break;
                    case 'bank_account':
                        details = entityInfo.details.bank_name || 'N/A';
                        additionalInfo = `
                            <tr><th>Bank Name:</th><td>${entityInfo.details.bank_name || 'N/A'}</td></tr>
                            <tr><th>Account Number:</th><td>${entityInfo.details.code || 'N/A'}</td></tr>
                            <tr><th>Currency:</th><td>${entityInfo.details.currency || 'N/A'}</td></tr>
                            <tr><th>Current Balance:</th><td>${formatCurrency(entityInfo.details.current_balance || 0, entityInfo.details.currency || 'Tsh')}</td></tr>
                        `;
                        break;
                    default:
                        details = 'N/A';
                }
                
                let html = `
                    <div class="container-fluid">
                        <div class="row mb-4">
                            <div class="col-md-6">
                                <h5>${entityInfo.name}</h5>
                                <table class="table table-sm table-borderless">
                                    <tr><th>Entity Type:</th><td><span class="entity-type-badge badge-${entityInfo.type}">${entityInfo.type.charAt(0).toUpperCase() + entityInfo.type.slice(1)}</span></td></tr>
                                    <tr><th>Code:</th><td><code>${entityInfo.code}</code></td></tr>
                                    <tr><th>Details:</th><td>${details}</td></tr>
                                    ${additionalInfo}
                                </table>
                            </div>
                            <div class="col-md-6">
                                <div class="card ${totals.net_balance > 0 ? 'border-success' : totals.net_balance < 0 ? 'border-danger' : 'border-secondary'}">
                                    <div class="card-body text-center">
                                        <h6 class="card-title">Current Balance</h6>
                                        <h2 class="${totals.net_balance > 0 ? 'text-success' : totals.net_balance < 0 ? 'text-danger' : 'text-muted'}">
                                            ${formatCurrency(Math.abs(totals.net_balance))}
                                        </h2>
                                        <span class="badge ${totals.net_balance > 0 ? 'bg-success' : totals.net_balance < 0 ? 'bg-danger' : 'bg-secondary'}">
                                            ${totals.balance_status}
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="card">
                                    <div class="card-header">
                                        <h6 class="mb-0">Financial Summary</h6>
                                    </div>
                                    <div class="card-body">
                                        <table class="table table-sm">
                                            <tr>
                                                <td>Total Receipts:</td>
                                                <td class="text-end text-success fw-bold">${formatCurrency(totals.receipts)}</td>
                                            </tr>
                                            <tr>
                                                <td>Total Payments:</td>
                                                <td class="text-end text-danger fw-bold">${formatCurrency(totals.payments)}</td>
                                            </tr>
                                            <tr>
                                                <td>Debit Balance:</td>
                                                <td class="text-end text-danger fw-bold">${formatCurrency(totals.debit_balance)}</td>
                                            </tr>
                                            <tr>
                                                <td>Credit Balance:</td>
                                                <td class="text-end text-success fw-bold">${formatCurrency(totals.credit_balance)}</td>
                                            </tr>
                                            <tr class="table-light">
                                                <td><strong>Net Balance:</strong></td>
                                                <td class="text-end ${totals.net_balance > 0 ? 'text-success' : totals.net_balance < 0 ? 'text-danger' : 'text-muted'} fw-bold">
                                                    ${formatCurrency(Math.abs(totals.net_balance))}
                                                </td>
                                            </tr>
                                        </table>
                                    </div>
                                </div>
                            </div>
                `;
                
                if (entityInfo.type !== 'bank_account') {
                    html += `
                            <div class="col-md-6">
                                <div class="card">
                                    <div class="card-header">
                                        <h6 class="mb-0">Aging Analysis</h6>
                                    </div>
                                    <div class="card-body">
                                        <table class="table table-sm">
                                            <tr>
                                                <td>Current (0-30 days):</td>
                                                <td class="text-end">${formatCurrency(aging.buckets.Current)}</td>
                                                <td class="text-end">${totals.debit_balance > 0 ? (aging.buckets.Current / totals.debit_balance * 100).toFixed(1) : '0.0'}%</td>
                                            </tr>
                                            <tr>
                                                <td>31-60 Days Overdue:</td>
                                                <td class="text-end">${formatCurrency(aging.buckets['31-60 Days'])}</td>
                                                <td class="text-end">${totals.debit_balance > 0 ? (aging.buckets['31-60 Days'] / totals.debit_balance * 100).toFixed(1) : '0.0'}%</td>
                                            </tr>
                                            <tr>
                                                <td>61-90 Days Overdue:</td>
                                                <td class="text-end">${formatCurrency(aging.buckets['61-90 Days'])}</td>
                                                <td class="text-end">${totals.debit_balance > 0 ? (aging.buckets['61-90 Days'] / totals.debit_balance * 100).toFixed(1) : '0.0'}%</td>
                                            </tr>
                                            <tr>
                                                <td>90+ Days Overdue:</td>
                                                <td class="text-end">${formatCurrency(aging.buckets['90+ Days'])}</td>
                                                <td class="text-end">${totals.debit_balance > 0 ? (aging.buckets['90+ Days'] / totals.debit_balance * 100).toFixed(1) : '0.0'}%</td>
                                            </tr>
                                            <tr class="table-warning">
                                                <td><strong>Total Overdue:</strong></td>
                                                <td class="text-end fw-bold">${formatCurrency(aging.total_overdue)}</td>
                                                <td class="text-end fw-bold">${aging.overdue_ratio.toFixed(1)}%</td>
                                            </tr>
                                        </table>
                                    </div>
                                </div>
                            </div>
                    `;
                }
                
                html += `</div></div>`;
                
                document.getElementById('entityDetailsContent').innerHTML = html;
                
                const modal = new bootstrap.Modal(document.getElementById('entityDetailsModal'));
                modal.show();
            }
        });
    });
    
    // Helper functions
    function formatCurrency(amount, currency = 'Tsh') {
        const symbols = {
            'Tsh': 'TZS ',
            'USD': '$',
            'Ksh': 'KSh ',
            'UGsh': 'UGX '
        };
        const symbol = symbols[currency] || 'TZS ';
        return symbol + Math.abs(amount).toLocaleString('en-US', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
    }
    
    function formatDate(dateString) {
        const date = new Date(dateString);
        return date.toLocaleDateString('en-US', {
            year: 'numeric',
            month: 'short',
            day: 'numeric'
        });
    }
});
</script>

<?php include '../includes/footer.php'; ?>