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
$success_message = '';
$error_message = '';

// Pagination configuration
$records_per_page = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 20;
if ($records_per_page <= 0) $records_per_page = 0;
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

function getEntityTypeIcon($type) {
    $icons = [
        'client' => 'bi-person-badge',
        'custodian' => 'bi-shield-check',
        'employee' => 'bi-person-workspace',
        'agent' => 'bi-person-rolodex',
        'broker' => 'bi-graph-up',
        'supplier' => 'bi-truck',
        'chart_account' => 'bi-journal-bookmark',
        'bank_account' => 'bi-bank'
    ];
    return $icons[$type] ?? 'bi-building';
}

function getEntityTypeColor($type) {
    $colors = [
        'client' => '#4F46E5',
        'custodian' => '#0891B2',
        'employee' => '#059669',
        'agent' => '#D97706',
        'broker' => '#DC2626',
        'supplier' => '#7C3AED',
        'chart_account' => '#6D28D9',
        'bank_account' => '#0D9488'
    ];
    return $colors[$type] ?? '#6B7280';
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
    
    if (!$entity_type || $entity_type === 'bank_account' || $entity_type === 'chart_account') {
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
            
            // Get GL entries for this entity
            $gl_entries = [];
            try {
                if ($entity_type === 'chart_account') {
                    $gl_query = "SELECT gl.transaction_date, gl.description, gl.reference_no,
                                 gl.debit_amount, gl.credit_amount, gl.account_code,
                                 coa.account_name
                                 FROM general_ledger gl
                                 LEFT JOIN chart_of_accounts coa ON gl.account_code = coa.account_code
                                 WHERE gl.account_code = ?";
                    $gl_params = [$entity_id];
                    if ($start_date && $end_date) {
                        $gl_query .= " AND gl.transaction_date BETWEEN ? AND ?";
                        $gl_params[] = $start_date;
                        $gl_params[] = $end_date;
                    } elseif ($as_of_date) {
                        $gl_query .= " AND gl.transaction_date <= ?";
                        $gl_params[] = $as_of_date;
                    }
                    $gl_query .= " ORDER BY gl.transaction_date ASC";
                    $gl_stmt = $db->prepare($gl_query);
                    $gl_stmt->execute($gl_params);
                    $gl_entries = $gl_stmt->fetchAll();
                } elseif ($entity_type === 'client') {
                    $cds = $entity['cds_account'] ?? '';
                    $name = $entity['name'] ?? '';
                    if (!empty($cds) || !empty($name)) {
                        $trade_stmt = $db->prepare("SELECT trade_reference FROM trades WHERE client_cds_account = ? OR client_name = ?");
                        $trade_stmt->execute([$cds, $name]);
                        $trade_refs = $trade_stmt->fetchAll(PDO::FETCH_COLUMN);
                        if (!empty($trade_refs)) {
                            $placeholders = implode(',', array_fill(0, count($trade_refs), '?'));
                            $gl_query = "SELECT gl.transaction_date, gl.description, gl.reference_no,
                                         gl.debit_amount, gl.credit_amount, gl.account_code,
                                         coa.account_name
                                         FROM general_ledger gl
                                         LEFT JOIN chart_of_accounts coa ON gl.account_code = coa.account_code
                                         WHERE gl.reference_no IN ($placeholders)
                                         ORDER BY gl.transaction_date ASC";
                            $gl_stmt = $db->prepare($gl_query);
                            $gl_stmt->execute($trade_refs);
                            $gl_entries = $gl_stmt->fetchAll();
                        }
                    }
                }
            } catch (Exception $e) {
                error_log("Error getting GL entries: " . $e->getMessage());
            }
            
            // Add GL debits to receipts total, GL credits to payments total
            $gl_receipts_total = 0;
            $gl_payments_total = 0;
            foreach ($gl_entries as $gl) {
                if ((float)$gl['debit_amount'] > 0) {
                    $gl_receipts_total += (float)$gl['debit_amount'];
                }
                if ((float)$gl['credit_amount'] > 0) {
                    $gl_payments_total += (float)$gl['credit_amount'];
                }
            }
            
            // Calculate totals
            $total_receipts = array_sum(array_column($receipts, 'amount')) + $gl_receipts_total;
            $total_payments = array_sum(array_column($payments, 'amount')) + $gl_payments_total;
            
            // For bank accounts, use current_balance as authoritative
            if ($entity_type === 'bank_account') {
                $current_balance = (float)($entity['current_balance'] ?? 0);
                $net_balance = $current_balance;
                $balance_status = $net_balance >= 0 ? 'Positive Balance' : 'Negative Balance';
                $debit_balance = $net_balance < 0 ? abs($net_balance) : 0;
                $credit_balance = $net_balance > 0 ? $net_balance : 0;
            } else {
                $net_balance = $total_payments - $total_receipts;
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
            
            // Add GL entries as transactions
            foreach ($gl_entries as $gl) {
                $gl_amount = 0;
                if ((float)$gl['debit_amount'] > 0) {
                    $gl_amount = $entity_type === 'bank_account' ? (float)$gl['debit_amount'] : -(float)$gl['debit_amount'];
                } elseif ((float)$gl['credit_amount'] > 0) {
                    $gl_amount = $entity_type === 'bank_account' ? -(float)$gl['credit_amount'] : (float)$gl['credit_amount'];
                }
                $transactions[] = [
                    'date' => $gl['transaction_date'],
                    'type' => 'gl_entry',
                    'description' => $gl['description'],
                    'reference' => $gl['reference_no'],
                    'amount' => $gl_amount,
                    'currency' => 'TZS',
                    'bank_account' => $gl['account_code'] . ($gl['account_name'] ? ' - ' . $gl['account_name'] : ''),
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
            
            // Calculate aging
            $aging_buckets = [
                'Current' => 0,
                '31-60 Days' => 0,
                '61-90 Days' => 0,
                '90+ Days' => 0
            ];
            
            if ($entity_type !== 'bank_account') {
                $today = new DateTime($as_of_date);
                foreach ($transactions as $transaction) {
                    if ($transaction['amount'] < 0) {
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

// Get filter parameters
$entity_type = isset($_GET['entity_type']) ? $_GET['entity_type'] : null;
$as_of_date = isset($_GET['as_of_date']) ? $_GET['as_of_date'] : date('Y-m-d');
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : null;
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : null;
$balance_status = isset($_GET['balance_status']) ? $_GET['balance_status'] : 'all';
$aging_filter = isset($_GET['aging_filter']) ? $_GET['aging_filter'] : 'all';
$hide_zero = isset($_GET['hide_zero']) ? (int)$_GET['hide_zero'] : 1;

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
    $entity_type_key = $data['entity_info']['type'];
    
    if (!isset($summary_stats['by_type'][$entity_type_key])) {
        $summary_stats['by_type'][$entity_type_key] = [
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
    
    $summary_stats['by_type'][$entity_type_key]['count']++;
    $summary_stats['by_type'][$entity_type_key]['debit'] += $data['totals']['debit_balance'];
    $summary_stats['by_type'][$entity_type_key]['credit'] += $data['totals']['credit_balance'];
    $summary_stats['by_type'][$entity_type_key]['overdue'] += $data['aging']['total_overdue'];
    
    if ($data['totals']['net_balance'] > 0) $summary_stats['entities_in_credit']++;
    elseif ($data['totals']['net_balance'] < 0) $summary_stats['entities_in_debit']++;
    else $summary_stats['entities_settled']++;
}

// Calculate net balance
$net_balance = $summary_stats['total_credit_balance'] - $summary_stats['total_debit_balance'];

$page_title = 'Debtors & Credit Tracking';
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

        /* Page Header */
        .page-header-modern {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 28px;
            flex-wrap: wrap;
            gap: 16px;
        }

        .page-header-modern h1 {
            font-size: 28px;
            font-weight: 800;
            color: var(--gray-900);
            letter-spacing: -0.5px;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .page-header-modern h1 i {
            color: var(--primary);
        }

        .page-header-modern .subtitle {
            color: var(--gray-500);
            font-size: 14px;
            font-weight: 400;
            margin-top: 4px;
        }

        .header-actions {
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

        .btn-modern-outline {
            background: white;
            color: var(--gray-700);
            border: 1px solid var(--gray-200);
        }

        .btn-modern-outline:hover {
            background: var(--gray-50);
            border-color: var(--gray-300);
            transform: translateY(-1px);
            box-shadow: var(--shadow-md);
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

        /* Entity Type Pills */
        .entity-pills-wrapper {
            background: white;
            border-radius: var(--radius);
            padding: 16px 20px;
            margin-bottom: 24px;
            box-shadow: var(--shadow);
            border: 1px solid var(--gray-200);
            overflow-x: auto;
        }

        .entity-pills {
            display: flex;
            gap: 8px;
            flex-wrap: nowrap;
        }

        .entity-pill {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 16px;
            border-radius: 50px;
            border: 2px solid var(--gray-200);
            background: white;
            color: var(--gray-600);
            font-weight: 500;
            font-size: 13px;
            transition: var(--transition);
            cursor: pointer;
            text-decoration: none;
            white-space: nowrap;
        }

        .entity-pill:hover {
            border-color: var(--primary);
            color: var(--primary);
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        .entity-pill.active {
            border-color: var(--primary);
            background: var(--primary);
            color: white;
        }

        .entity-pill .pill-icon {
            font-size: 16px;
        }

        .entity-pill .pill-count {
            background: var(--gray-100);
            padding: 0 8px;
            border-radius: 10px;
            font-size: 11px;
            font-weight: 600;
            color: var(--gray-500);
        }

        .entity-pill.active .pill-count {
            background: rgba(255, 255, 255, 0.2);
            color: white;
        }

        /* Stats Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }

        .stat-card-modern {
            background: white;
            border-radius: var(--radius);
            padding: 20px 24px;
            box-shadow: var(--shadow);
            border: 1px solid var(--gray-200);
            transition: var(--transition);
            position: relative;
            overflow: hidden;
        }

        .stat-card-modern:hover {
            transform: translateY(-4px);
            box-shadow: var(--shadow-lg);
        }

        .stat-card-modern .stat-icon {
            position: absolute;
            top: 16px;
            right: 16px;
            font-size: 28px;
            opacity: 0.1;
        }

        .stat-card-modern .stat-label {
            font-size: 13px;
            font-weight: 500;
            color: var(--gray-500);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .stat-card-modern .stat-value {
            font-size: 28px;
            font-weight: 700;
            color: var(--gray-900);
            margin: 4px 0 2px 0;
        }

        .stat-card-modern .stat-sub {
            font-size: 12px;
            color: var(--gray-400);
        }

        .stat-card-modern .stat-progress {
            margin-top: 8px;
            height: 4px;
            background: var(--gray-100);
            border-radius: 2px;
            overflow: hidden;
        }

        .stat-card-modern .stat-progress .progress-bar {
            height: 100%;
            border-radius: 2px;
            transition: width 1s ease;
        }

        .stat-card-primary .stat-icon { color: var(--primary); }
        .stat-card-success .stat-icon { color: var(--success); }
        .stat-card-danger .stat-icon { color: var(--danger); }
        .stat-card-warning .stat-icon { color: var(--warning); }

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
            width: 100%;
        }

        .filter-group .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1);
        }

        .filter-group select.form-control {
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%236B7280' d='M6 8L1 3h10z'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 12px center;
            padding-right: 36px;
        }

        .filter-actions {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        /* Table */
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
            flex-wrap: wrap;
            gap: 8px;
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
            font-size: 11px;
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

        .entity-type-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 3px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }

        .entity-name-link {
            color: var(--primary);
            font-weight: 600;
            text-decoration: none;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }

        .entity-name-link:hover {
            color: var(--primary-dark);
            text-decoration: underline;
        }

        .entity-name-link i {
            font-size: 12px;
            opacity: 0.5;
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

        .aging-badge {
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 10px;
            font-weight: 600;
            display: inline-block;
        }

        .aging-badge.current {
            background: #D1FAE5;
            color: #065F46;
        }

        .aging-badge.overdue-31 {
            background: #FEF3C7;
            color: #92400E;
        }

        .aging-badge.overdue-61 {
            background: #FDE68A;
            color: #78350F;
        }

        .aging-badge.overdue-90 {
            background: #FEE2E2;
            color: #991B1B;
        }

        .aging-progress {
            display: flex;
            height: 4px;
            border-radius: 2px;
            overflow: hidden;
            background: var(--gray-100);
            margin-top: 4px;
        }

        .aging-progress .segment {
            height: 100%;
            transition: width 0.5s ease;
        }

        .segment-current { background: var(--success); }
        .segment-31-60 { background: var(--warning); }
        .segment-61-90 { background: #F97316; }
        .segment-90-plus { background: var(--danger); }

        .action-buttons {
            display: flex;
            gap: 4px;
        }

        .action-btn {
            width: 32px;
            height: 32px;
            border-radius: var(--radius-sm);
            border: none;
            background: transparent;
            color: var(--gray-500);
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
        }

        .action-btn:hover {
            background: var(--gray-100);
            color: var(--gray-700);
        }

        .action-btn.view:hover {
            background: #E0E7FF;
            color: var(--primary);
        }

        .action-btn.details:hover {
            background: #D1FAE5;
            color: var(--success);
        }

        /* Pagination */
        .pagination-modern {
            display: flex;
            align-items: center;
            gap: 4px;
            flex-wrap: wrap;
        }

        .pagination-modern .page-link {
            padding: 6px 12px;
            border-radius: var(--radius-sm);
            border: 1px solid var(--gray-200);
            background: white;
            color: var(--gray-600);
            text-decoration: none;
            transition: var(--transition);
            font-size: 14px;
        }

        .pagination-modern .page-link:hover {
            background: var(--gray-50);
            border-color: var(--gray-300);
        }

        .pagination-modern .page-link.active {
            background: var(--primary);
            border-color: var(--primary);
            color: white;
        }

        .pagination-modern .page-link.disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        .table-footer {
            padding: 12px 24px;
            background: var(--gray-50);
            border-top: 2px solid var(--gray-200);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 8px;
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

        .animate-in-delay-1 { animation-delay: 0.05s; opacity: 0; }
        .animate-in-delay-2 { animation-delay: 0.1s; opacity: 0; }
        .animate-in-delay-3 { animation-delay: 0.15s; opacity: 0; }
        .animate-in-delay-4 { animation-delay: 0.2s; opacity: 0; }

        /* Responsive */
        @media (max-width: 1200px) {
            .filter-grid {
                grid-template-columns: 1fr 1fr;
            }
        }

        @media (max-width: 992px) {
            .page-header-modern {
                flex-direction: column;
                align-items: stretch;
            }

            .header-actions {
                justify-content: stretch;
            }

            .header-actions .btn-modern {
                flex: 1;
                justify-content: center;
            }
        }

        @media (max-width: 768px) {
            .modern-container {
                padding: 16px;
            }

            .stats-grid {
                grid-template-columns: 1fr 1fr;
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
                align-items: stretch;
                text-align: center;
            }

            .table-footer {
                flex-direction: column;
                align-items: stretch;
                text-align: center;
            }

            .pagination-modern {
                justify-content: center;
            }
        }

        @media (max-width: 576px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }

            .entity-pills {
                flex-wrap: wrap;
            }
        }

        /* Scrollbar */
        .table-responsive::-webkit-scrollbar {
            width: 6px;
            height: 6px;
        }

        .table-responsive::-webkit-scrollbar-track {
            background: var(--gray-100);
            border-radius: 3px;
        }

        .table-responsive::-webkit-scrollbar-thumb {
            background: var(--gray-300);
            border-radius: 3px;
        }

        .table-responsive::-webkit-scrollbar-thumb:hover {
            background: var(--gray-400);
        }
    </style>
</head>
<body>

<div class="modern-container">
    <!-- Page Header -->
    <div class="page-header-modern animate-in">
        <div>
            <h1>
                <i class="bi bi-people"></i>
                Debtors & Credit Tracking
            </h1>
            <div class="subtitle">
                <i class="bi bi-clock-history"></i>
                As of <?php echo date('d M Y', strtotime($as_of_date)); ?>
                <?php if ($start_date && $end_date): ?>
                    &bull; Period: <?php echo date('d M Y', strtotime($start_date)); ?> - <?php echo date('d M Y', strtotime($end_date)); ?>
                <?php endif; ?>
                &bull; <span class="text-primary"><?php echo $total_count; ?></span> entities
            </div>
        </div>
        <div class="header-actions">
            <button type="button" class="btn-modern btn-modern-outline" data-bs-toggle="modal" data-bs-target="#helpModal">
                <i class="bi bi-question-circle"></i> Help
            </button>
            <a href="?<?php echo http_build_query(array_merge($_GET, ['export' => 'excel', 'page' => 1])); ?>" 
               class="btn-modern btn-modern-success"
               onclick="return confirm('Export <?php echo count($all_data); ?> entities to Excel?')">
                <i class="bi bi-file-excel"></i> Export Excel
            </a>
            <a href="?<?php echo http_build_query(array_merge($_GET, ['export' => 'pdf', 'page' => 1])); ?>" 
               class="btn-modern btn-modern-danger">
                <i class="bi bi-file-pdf"></i> Export PDF
            </a>
        </div>
    </div>

    <!-- Entity Type Pills -->
    <div class="entity-pills-wrapper animate-in animate-in-delay-1">
        <div class="entity-pills">
            <a href="?<?php echo http_build_query(array_diff_key($_GET, ['entity_type' => '', 'page' => ''])); ?>" 
               class="entity-pill <?php echo !$entity_type ? 'active' : ''; ?>">
                <i class="bi bi-grid-3x3-gap-fill pill-icon"></i>
                All Entities
                <span class="pill-count"><?php echo $total_count; ?></span>
            </a>
            
            <?php
            $entity_types = [
                'client' => ['icon' => 'bi-person-badge', 'label' => 'Clients'],
                'custodian' => ['icon' => 'bi-shield-check', 'label' => 'Custodians'],
                'employee' => ['icon' => 'bi-person-workspace', 'label' => 'Employees'],
                'agent' => ['icon' => 'bi-person-rolodex', 'label' => 'Agents'],
                'broker' => ['icon' => 'bi-graph-up', 'label' => 'Brokers'],
                'supplier' => ['icon' => 'bi-truck', 'label' => 'Suppliers'],
                'chart_account' => ['icon' => 'bi-journal-bookmark', 'label' => 'Chart Accounts'],
                'bank_account' => ['icon' => 'bi-bank', 'label' => 'Bank Accounts']
            ];
            
            foreach ($entity_types as $type => $info):
                $count = $summary_stats['by_type'][$type]['count'] ?? 0;
                if ($count > 0 || $entity_type == $type):
            ?>
                <a href="?entity_type=<?php echo $type; ?>&<?php echo http_build_query(array_diff_key($_GET, ['entity_type' => '', 'page' => ''])); ?>" 
                   class="entity-pill <?php echo $entity_type == $type ? 'active' : ''; ?>">
                    <i class="bi <?php echo $info['icon']; ?> pill-icon"></i>
                    <?php echo $info['label']; ?>
                    <span class="pill-count"><?php echo $count; ?></span>
                </a>
            <?php endif; endforeach; ?>
        </div>
    </div>

    <!-- Stats Cards -->
    <div class="stats-grid animate-in animate-in-delay-2">
        <div class="stat-card-modern stat-card-primary">
            <i class="bi bi-people stat-icon"></i>
            <div class="stat-label">Total Entities</div>
            <div class="stat-value"><?php echo $summary_stats['total_entities']; ?></div>
            <div class="stat-sub">
                <span class="text-success"><?php echo $summary_stats['entities_in_credit']; ?> Credit</span> &bull;
                <span class="text-danger"><?php echo $summary_stats['entities_in_debit']; ?> Debit</span> &bull;
                <span class="text-muted"><?php echo $summary_stats['entities_settled']; ?> Settled</span>
            </div>
        </div>

        <div class="stat-card-modern stat-card-danger">
            <i class="bi bi-arrow-down-circle stat-icon"></i>
            <div class="stat-label">Total Debit</div>
            <div class="stat-value text-danger"><?php echo formatCurrency($summary_stats['total_debit_balance']); ?></div>
            <div class="stat-sub">Money owed to us</div>
        </div>

        <div class="stat-card-modern stat-card-success">
            <i class="bi bi-arrow-up-circle stat-icon"></i>
            <div class="stat-label">Total Credit</div>
            <div class="stat-value text-success"><?php echo formatCurrency($summary_stats['total_credit_balance']); ?></div>
            <div class="stat-sub">Money we owe</div>
        </div>

        <div class="stat-card-modern stat-card-warning">
            <i class="bi bi-clock-history stat-icon"></i>
            <div class="stat-label">Total Overdue</div>
            <div class="stat-value" style="color: var(--warning);"><?php echo formatCurrency($summary_stats['total_overdue']); ?></div>
            <div class="stat-sub">
                <?php echo $summary_stats['total_debit_balance'] > 0 ? 
                    number_format($summary_stats['total_overdue'] / $summary_stats['total_debit_balance'] * 100, 1) : 0; ?>% of total debit
            </div>
            <div class="stat-progress">
                <div class="progress-bar" style="width: <?php echo $summary_stats['total_debit_balance'] > 0 ? ($summary_stats['total_overdue'] / $summary_stats['total_debit_balance'] * 100) : 0; ?>%; background: var(--warning);"></div>
            </div>
        </div>

        <div class="stat-card-modern" style="border-left: 4px solid var(--primary);">
            <i class="bi bi-calculator stat-icon"></i>
            <div class="stat-label">Net Position</div>
            <div class="stat-value <?php echo $net_balance >= 0 ? 'text-success' : 'text-danger'; ?>">
                <?php echo formatCurrency(abs($net_balance)); ?>
            </div>
            <div class="stat-sub">
                <?php echo $net_balance >= 0 ? 'We owe more to entities' : 'Entities owe more to us'; ?>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <div class="filter-section-modern animate-in animate-in-delay-3">
        <form method="GET" id="filterForm">
            <input type="hidden" name="page" value="1">
            <?php if ($entity_type): ?>
                <input type="hidden" name="entity_type" value="<?php echo htmlspecialchars($entity_type); ?>">
            <?php endif; ?>
            
            <div class="filter-grid">
                <div class="filter-group">
                    <label><i class="bi bi-calendar-range"></i> From Date</label>
                    <input type="date" class="form-control" name="start_date" 
                           value="<?php echo htmlspecialchars($start_date); ?>"
                           max="<?php echo date('Y-m-d'); ?>">
                </div>
                
                <div class="filter-group">
                    <label><i class="bi bi-calendar-range"></i> To Date</label>
                    <input type="date" class="form-control" name="end_date" 
                           value="<?php echo htmlspecialchars($end_date); ?>"
                           max="<?php echo date('Y-m-d'); ?>">
                </div>
                
                <div class="filter-group">
                    <label><i class="bi bi-filter"></i> Balance Status</label>
                    <select class="form-control" name="balance_status">
                        <option value="all" <?php echo $balance_status === 'all' ? 'selected' : ''; ?>>All Balances</option>
                        <option value="credit" <?php echo $balance_status === 'credit' ? 'selected' : ''; ?>>Credit (We Owe)</option>
                        <option value="debit" <?php echo $balance_status === 'debit' ? 'selected' : ''; ?>>Debit (Owes Us)</option>
                        <option value="settled" <?php echo $balance_status === 'settled' ? 'selected' : ''; ?>>Settled</option>
                    </select>
                </div>
                
                <div class="filter-group">
                    <label><i class="bi bi-clock"></i> Aging Status</label>
                    <select class="form-control" name="aging_filter">
                        <option value="all" <?php echo $aging_filter === 'all' ? 'selected' : ''; ?>>All Aging</option>
                        <option value="current" <?php echo $aging_filter === 'current' ? 'selected' : ''; ?>>Current (0% Overdue)</option>
                        <option value="overdue_low" <?php echo $aging_filter === 'overdue_low' ? 'selected' : ''; ?>>Low Overdue (1-10%)</option>
                        <option value="overdue_medium" <?php echo $aging_filter === 'overdue_medium' ? 'selected' : ''; ?>>Medium Overdue (11-30%)</option>
                        <option value="overdue_high" <?php echo $aging_filter === 'overdue_high' ? 'selected' : ''; ?>>High Overdue (30%+)</option>
                    </select>
                </div>
                
                <div class="filter-group">
                    <label><i class="bi bi-list-ol"></i> Per Page</label>
                    <select class="form-control" name="per_page" onchange="updatePerPage(this.value)">
                        <option value="10" <?php echo $records_per_page == 10 ? 'selected' : ''; ?>>10</option>
                        <option value="20" <?php echo $records_per_page == 20 ? 'selected' : ''; ?>>20</option>
                        <option value="50" <?php echo $records_per_page == 50 ? 'selected' : ''; ?>>50</option>
                        <option value="100" <?php echo $records_per_page == 100 ? 'selected' : ''; ?>>100</option>
                        <option value="0" <?php echo $records_per_page == 0 ? 'selected' : ''; ?>>All</option>
                    </select>
                </div>
                
                <div class="filter-group">
                    <label>&nbsp;</label>
                    <div class="filter-actions">
                        <button type="submit" class="btn-modern btn-modern-primary">
                            <i class="bi bi-funnel"></i> Apply
                        </button>
                        <button type="button" class="btn-modern btn-modern-outline" onclick="resetFilters()">
                            <i class="bi bi-arrow-counterclockwise"></i> Reset
                        </button>
                    </div>
                </div>
            </div>
        </form>
    </div>

    <!-- Table -->
    <div class="animate-in animate-in-delay-4">
        <div class="table-wrapper">
            <div class="table-header">
                <h6>
                    <i class="bi bi-table"></i>
                    Entity Balances & Aging Analysis
                </h6>
                <div>
                    <span class="badge-count">
                        <i class="bi bi-file-text"></i> <?php echo count($all_data); ?> entities
                    </span>
                    <?php if ($total_pages > 1): ?>
                        <span class="badge-count" style="background: var(--gray-500);">
                            Page <?php echo $current_page; ?> of <?php echo $total_pages; ?>
                        </span>
                    <?php endif; ?>
                </div>
            </div>
            
            <?php if (empty($all_data)): ?>
                <div class="empty-state">
                    <i class="bi bi-inbox"></i>
                    <h5>No entities found</h5>
                    <p>Try adjusting your filter criteria or date range</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table-modern">
                        <thead>
                            <tr>
                                <th>Type</th>
                                <th>Code</th>
                                <th>Entity Name</th>
                                <th class="text-end">Receipts</th>
                                <th class="text-end">Payments</th>
                                <th class="text-end">Debit</th>
                                <th class="text-end">Credit</th>
                                <th class="text-end">Net Balance</th>
                                <th>Aging</th>
                                <th>% Overdue</th>
                                <th class="text-center">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($all_data as $entity_data): 
                                $entity_info = $entity_data['entity_info'];
                                $totals = $entity_data['totals'];
                                $aging = $entity_data['aging'];
                                $type_color = getEntityTypeColor($entity_info['type']);
                                $type_icon = getEntityTypeIcon($entity_info['type']);
                            ?>
                                <tr>
                                    <td>
                                        <span class="entity-type-badge" style="background: <?php echo $type_color; ?>20; color: <?php echo $type_color; ?>;">
                                            <i class="bi <?php echo $type_icon; ?>"></i>
                                            <?php echo ucfirst($entity_info['type']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <code style="background: var(--gray-100); padding: 2px 6px; border-radius: 4px; font-size: 12px;">
                                            <?php echo htmlspecialchars($entity_info['code']); ?>
                                        </code>
                                    </td>
                                    <td>
                                        <a href="entity_ledger.php?type=<?php echo urlencode($entity_info['type']); ?>&id=<?php echo urlencode($entity_info['id']); ?><?php echo isset($_GET['entity_type']) ? '&return_to=' . urlencode(http_build_query(['entity_type' => $_GET['entity_type']])) : ''; ?>" 
                                           class="entity-name-link">
                                            <?php echo htmlspecialchars($entity_info['name']); ?>
                                            <i class="bi bi-box-arrow-up-right"></i>
                                        </a>
                                        <?php if ($entity_info['type'] == 'client' && isset($entity_info['details']['client_type'])): ?>
                                            <br><small style="color: var(--gray-400); font-size: 11px;"><?php echo htmlspecialchars($entity_info['details']['client_type']); ?></small>
                                        <?php endif; ?>
                                        <?php if ($entity_info['type'] == 'bank_account' && isset($entity_info['details']['bank_name'])): ?>
                                            <br><small style="color: var(--gray-400); font-size: 11px;"><?php echo htmlspecialchars($entity_info['details']['bank_name']); ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end text-credit">
                                        <?php echo formatCurrency($totals['receipts']); ?>
                                    </td>
                                    <td class="text-end text-debit">
                                        <?php echo formatCurrency($totals['payments']); ?>
                                    </td>
                                    <td class="text-end text-debit">
                                        <?php echo $totals['debit_balance'] > 0 ? formatCurrency($totals['debit_balance']) : '-'; ?>
                                    </td>
                                    <td class="text-end text-credit">
                                        <?php echo $totals['credit_balance'] > 0 ? formatCurrency($totals['credit_balance']) : '-'; ?>
                                    </td>
                                    <td class="text-end <?php 
                                        echo $totals['net_balance'] > 0 ? 'text-balance-positive' : 
                                            ($totals['net_balance'] < 0 ? 'text-balance-negative' : 'text-balance-zero'); 
                                    ?>">
                                        <?php echo formatCurrency(abs($totals['net_balance'])); ?>
                                    </td>
                                    <td>
                                        <?php if ($entity_info['type'] !== 'bank_account'): ?>
                                            <div style="display: flex; gap: 3px; flex-wrap: wrap;">
                                                <?php if ($aging['buckets']['Current'] > 0): ?>
                                                    <span class="aging-badge current">C</span>
                                                <?php endif; ?>
                                                <?php if ($aging['buckets']['31-60 Days'] > 0): ?>
                                                    <span class="aging-badge overdue-31">31-60</span>
                                                <?php endif; ?>
                                                <?php if ($aging['buckets']['61-90 Days'] > 0): ?>
                                                    <span class="aging-badge overdue-61">61-90</span>
                                                <?php endif; ?>
                                                <?php if ($aging['buckets']['90+ Days'] > 0): ?>
                                                    <span class="aging-badge overdue-90">90+</span>
                                                <?php endif; ?>
                                            </div>
                                            <div class="aging-progress">
                                                <?php $total_aging = array_sum($aging['buckets']); ?>
                                                <?php if ($total_aging > 0): ?>
                                                    <div class="segment segment-current" style="width: <?php echo ($aging['buckets']['Current'] / $total_aging * 100); ?>%;"></div>
                                                    <div class="segment segment-31-60" style="width: <?php echo ($aging['buckets']['31-60 Days'] / $total_aging * 100); ?>%;"></div>
                                                    <div class="segment segment-61-90" style="width: <?php echo ($aging['buckets']['61-90 Days'] / $total_aging * 100); ?>%;"></div>
                                                    <div class="segment segment-90-plus" style="width: <?php echo ($aging['buckets']['90+ Days'] / $total_aging * 100); ?>%;"></div>
                                                <?php endif; ?>
                                            </div>
                                        <?php else: ?>
                                            <span style="color: var(--gray-400); font-size: 12px;">N/A</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($entity_info['type'] !== 'bank_account'): ?>
                                            <span style="font-weight: 600; <?php echo $aging['overdue_ratio'] > 30 ? 'color: var(--danger);' : ($aging['overdue_ratio'] > 10 ? 'color: var(--warning);' : 'color: var(--success);'); ?>">
                                                <?php echo number_format($aging['overdue_ratio'], 1); ?>%
                                            </span>
                                        <?php else: ?>
                                            <span style="color: var(--gray-400); font-size: 12px;">N/A</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <div class="action-buttons" style="justify-content: center;">
                                            <a href="entity_ledger.php?type=<?php echo urlencode($entity_info['type']); ?>&id=<?php echo urlencode($entity_info['id']); ?><?php echo isset($_GET['entity_type']) ? '&return_to=' . urlencode(http_build_query(['entity_type' => $_GET['entity_type']])) : ''; ?>" 
                                               class="action-btn view" title="View Ledger">
                                                <i class="bi bi-eye"></i>
                                            </a>
                                            <button type="button" class="action-btn details" 
                                                    onclick="showEntityDetails('<?php echo $entity_info['type']; ?>', '<?php echo $entity_info['id']; ?>', '<?php echo htmlspecialchars($entity_info['name']); ?>')"
                                                    title="View Details">
                                                <i class="bi bi-info-circle"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <div class="table-footer">
                    <div>
                        <span style="color: var(--gray-500); font-size: 14px;">
                            Showing <?php echo count($all_data); ?> of <?php echo $total_count; ?> entities
                            <?php if ($entity_type): ?> (<?php echo ucfirst($entity_type); ?>s)<?php endif; ?>
                        </span>
                    </div>
                    <?php if ($total_pages > 1): ?>
                        <nav class="pagination-modern">
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => max(1, $current_page - 1)])); ?>" 
                               class="page-link <?php echo $current_page == 1 ? 'disabled' : ''; ?>">
                                <i class="bi bi-chevron-left"></i>
                            </a>
                            
                            <?php 
                            $start_page = max(1, $current_page - 2);
                            $end_page = min($total_pages, $current_page + 2);
                            
                            if ($start_page > 1): ?>
                                <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => 1])); ?>" class="page-link">1</a>
                                <?php if ($start_page > 2): ?>
                                    <span class="page-link disabled">…</span>
                                <?php endif; ?>
                            <?php endif; ?>
                            
                            <?php for ($i = $start_page; $i <= $end_page; $i++): ?>
                                <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>" 
                                   class="page-link <?php echo $i == $current_page ? 'active' : ''; ?>">
                                    <?php echo $i; ?>
                                </a>
                            <?php endfor; ?>
                            
                            <?php if ($end_page < $total_pages): ?>
                                <?php if ($end_page < $total_pages - 1): ?>
                                    <span class="page-link disabled">…</span>
                                <?php endif; ?>
                                <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $total_pages])); ?>" class="page-link"><?php echo $total_pages; ?></a>
                            <?php endif; ?>
                            
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => min($total_pages, $current_page + 1)])); ?>" 
                               class="page-link <?php echo $current_page == $total_pages ? 'disabled' : ''; ?>">
                                <i class="bi bi-chevron-right"></i>
                            </a>
                        </nav>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Entity Details Modal -->
<div class="modal fade" id="entityDetailsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content" style="border-radius: var(--radius);">
            <div class="modal-header" style="border-bottom: none; padding: 24px 24px 0 24px;">
                <h5 class="modal-title" id="entityDetailsTitle" style="font-weight: 700;">
                    <i class="bi bi-info-circle" style="color: var(--primary);"></i>
                    Entity Details
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="entityDetailsContent" style="padding: 20px 24px 24px 24px;">
                <!-- Content loaded dynamically -->
            </div>
        </div>
    </div>
</div>

<!-- Help Modal -->
<div class="modal fade" id="helpModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content" style="border-radius: var(--radius);">
            <div class="modal-header" style="background: var(--gray-50); border-bottom: 1px solid var(--gray-200);">
                <h5 class="modal-title" style="font-weight: 700;">
                    <i class="bi bi-question-circle" style="color: var(--primary);"></i>
                    How to Use This Report
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" style="padding: 24px;">
                <div class="row g-4">
                    <div class="col-md-6">
                        <h6 style="font-weight: 700; color: var(--gray-800);">
                            <i class="bi bi-tag" style="color: var(--primary);"></i> Entity Types
                        </h6>
                        <ul style="padding-left: 20px; color: var(--gray-600);">
                            <li><strong>Clients:</strong> Company customers</li>
                            <li><strong>Custodians:</strong> Asset holders</li>
                            <li><strong>Employees:</strong> Staff members</li>
                            <li><strong>Agents:</strong> Sales/Service agents</li>
                            <li><strong>Brokers:</strong> Trading intermediaries</li>
                            <li><strong>Suppliers:</strong> Goods/Service providers</li>
                            <li><strong>Chart Accounts:</strong> Nominal accounts</li>
                            <li><strong>Bank Accounts:</strong> Company bank accounts</li>
                        </ul>
                    </div>
                    <div class="col-md-6">
                        <h6 style="font-weight: 700; color: var(--gray-800);">
                            <i class="bi bi-graph-up" style="color: var(--success);"></i> Understanding Balances
                        </h6>
                        <ul style="padding-left: 20px; color: var(--gray-600);">
                            <li><span class="text-danger">Debit:</span> Entity owes us</li>
                            <li><span class="text-success">Credit:</span> We owe entity</li>
                            <li><span class="text-muted">Settled:</span> Zero balance</li>
                        </ul>
                        <br>
                        <h6 style="font-weight: 700; color: var(--gray-800);">
                            <i class="bi bi-clock" style="color: var(--warning);"></i> Aging Analysis
                        </h6>
                        <ul style="padding-left: 20px; color: var(--gray-600);">
                            <li><span style="color: var(--success);">Current:</span> 0-30 days</li>
                            <li><span style="color: var(--warning);">31-60:</span> Overdue</li>
                            <li><span style="color: #F97316;">61-90:</span> Highly overdue</li>
                            <li><span style="color: var(--danger);">90+:</span> Critical</li>
                        </ul>
                    </div>
                </div>
                <hr>
                <div class="alert alert-info" style="border-radius: var(--radius-sm);">
                    <i class="bi bi-lightbulb me-2"></i>
                    <strong>Tip:</strong> Click on any entity name to view their complete ledger with transaction history, filtering, and export options.
                </div>
            </div>
            <div class="modal-footer" style="border-top: 1px solid var(--gray-200);">
                <button type="button" class="btn-modern btn-modern-outline" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Update per page
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
    
    // Show entity details
    window.showEntityDetails = function(type, id, name) {
        const entityData = <?php echo json_encode($all_data); ?>.find(e => 
            e.entity_info.type === type && e.entity_info.id.toString() === id
        );
        
        if (entityData) {
            const entityInfo = entityData.entity_info;
            const totals = entityData.totals;
            const aging = entityData.aging;
            
            document.getElementById('entityDetailsTitle').innerHTML = `
                <i class="bi bi-${type === 'client' ? 'person-badge' : type === 'bank_account' ? 'bank' : 'building'}" style="color: var(--primary);"></i>
                ${name}
            `;
            
            let details = '';
            switch(type) {
                case 'client':
                    details = `
                        <tr><td style="font-weight: 500;">Client Type</td><td>${entityInfo.details.client_type || 'N/A'}</td></tr>
                        <tr><td style="font-weight: 500;">CDS Account</td><td><code>${entityInfo.details.cds_account || 'N/A'}</code></td></tr>
                        <tr><td style="font-weight: 500;">Phone</td><td>${entityInfo.details.phone || 'N/A'}</td></tr>
                        <tr><td style="font-weight: 500;">Email</td><td>${entityInfo.details.email || 'N/A'}</td></tr>
                    `;
                    break;
                case 'bank_account':
                    details = `
                        <tr><td style="font-weight: 500;">Bank Name</td><td>${entityInfo.details.bank_name || 'N/A'}</td></tr>
                        <tr><td style="font-weight: 500;">Account Number</td><td><code>${entityInfo.details.code || 'N/A'}</code></td></tr>
                        <tr><td style="font-weight: 500;">Currency</td><td>${entityInfo.details.currency || 'Tsh'}</td></tr>
                        <tr><td style="font-weight: 500;">Current Balance</td><td>${formatCurrency(entityInfo.details.current_balance || 0, entityInfo.details.currency || 'Tsh')}</td></tr>
                    `;
                    break;
                case 'employee':
                    details = `
                        <tr><td style="font-weight: 500;">Role</td><td>${entityInfo.details.role || 'N/A'}</td></tr>
                        <tr><td style="font-weight: 500;">Phone</td><td>${entityInfo.details.phone || 'N/A'}</td></tr>
                        <tr><td style="font-weight: 500;">Email</td><td>${entityInfo.details.email || 'N/A'}</td></tr>
                    `;
                    break;
                default:
                    details = `
                        <tr><td style="font-weight: 500;">Contact Person</td><td>${entityInfo.details.contact_person || 'N/A'}</td></tr>
                        <tr><td style="font-weight: 500;">Phone</td><td>${entityInfo.details.phone || 'N/A'}</td></tr>
                        <tr><td style="font-weight: 500;">Email</td><td>${entityInfo.details.email || 'N/A'}</td></tr>
                    `;
            }
            
            document.getElementById('entityDetailsContent').innerHTML = `
                <div class="row">
                    <div class="col-md-6">
                        <table class="table table-sm table-borderless" style="font-size: 14px;">
                            <tr><td style="font-weight: 500;">Entity Type</td><td><span class="entity-type-badge" style="background: ${getTypeColor(type)}20; color: ${getTypeColor(type)};">${type.charAt(0).toUpperCase() + type.slice(1)}</span></td></tr>
                            <tr><td style="font-weight: 500;">Code</td><td><code>${entityInfo.code}</code></td></tr>
                            ${details}
                        </table>
                    </div>
                    <div class="col-md-6">
                        <div style="background: var(--gray-50); border-radius: var(--radius-sm); padding: 16px; margin-bottom: 12px;">
                            <div style="display: flex; justify-content: space-between; align-items: center;">
                                <span style="font-weight: 500; color: var(--gray-600);">Current Balance</span>
                                <span style="font-size: 24px; font-weight: 700; ${totals.net_balance > 0 ? 'color: var(--success);' : totals.net_balance < 0 ? 'color: var(--danger);' : 'color: var(--gray-400);'}">
                                    ${formatCurrency(Math.abs(totals.net_balance))}
                                </span>
                            </div>
                            <div style="text-align: right;">
                                <span class="badge ${totals.net_balance > 0 ? 'bg-success' : totals.net_balance < 0 ? 'bg-danger' : 'bg-secondary'}" style="font-size: 12px;">
                                    ${totals.balance_status}
                                </span>
                            </div>
                        </div>
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 8px;">
                            <div style="background: var(--gray-50); border-radius: var(--radius-sm); padding: 12px; text-align: center;">
                                <div style="font-size: 12px; color: var(--gray-500);">Receipts</div>
                                <div style="font-weight: 700; color: var(--success);">${formatCurrency(totals.receipts)}</div>
                            </div>
                            <div style="background: var(--gray-50); border-radius: var(--radius-sm); padding: 12px; text-align: center;">
                                <div style="font-size: 12px; color: var(--gray-500);">Payments</div>
                                <div style="font-weight: 700; color: var(--danger);">${formatCurrency(totals.payments)}</div>
                            </div>
                        </div>
                        ${type !== 'bank_account' ? `
                            <div style="margin-top: 12px; background: var(--gray-50); border-radius: var(--radius-sm); padding: 12px;">
                                <div style="font-size: 12px; color: var(--gray-500); margin-bottom: 6px;">Aging Breakdown</div>
                                <div style="display: flex; gap: 6px; flex-wrap: wrap;">
                                    <span class="aging-badge current">Current: ${formatCurrency(aging.buckets.Current)}</span>
                                    <span class="aging-badge overdue-31">31-60: ${formatCurrency(aging.buckets['31-60 Days'])}</span>
                                    <span class="aging-badge overdue-61">61-90: ${formatCurrency(aging.buckets['61-90 Days'])}</span>
                                    <span class="aging-badge overdue-90">90+: ${formatCurrency(aging.buckets['90+ Days'])}</span>
                                </div>
                                <div style="margin-top: 6px; font-size: 13px;">
                                    <span style="font-weight: 500;">Overdue:</span>
                                    <span style="${aging.overdue_ratio > 30 ? 'color: var(--danger);' : aging.overdue_ratio > 10 ? 'color: var(--warning);' : 'color: var(--success);'}">
                                        ${aging.overdue_ratio.toFixed(1)}%
                                    </span>
                                    of total debit
                                </div>
                            </div>
                        ` : ''}
                    </div>
                </div>
                <div style="margin-top: 12px; text-align: right;">
                    <a href="entity_ledger.php?type=${type}&id=${id}" class="btn-modern btn-modern-primary" style="font-size: 13px;">
                        <i class="bi bi-eye"></i> View Full Ledger
                    </a>
                </div>
            `;
            
            const modal = new bootstrap.Modal(document.getElementById('entityDetailsModal'));
            modal.show();
        }
    };
    
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
    
    function getTypeColor(type) {
        const colors = {
            'client': '#4F46E5',
            'custodian': '#0891B2',
            'employee': '#059669',
            'agent': '#D97706',
            'broker': '#DC2626',
            'supplier': '#7C3AED',
            'chart_account': '#6D28D9',
            'bank_account': '#0D9488'
        };
        return colors[type] || '#6B7280';
    }
});

// Auto-submit on Enter key in date fields
document.querySelectorAll('input[type="date"]').forEach(input => {
    input.addEventListener('keypress', function(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            document.getElementById('filterForm').submit();
        }
    });
});
</script>

<?php include '../includes/footer.php'; ?>
