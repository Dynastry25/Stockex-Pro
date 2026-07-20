<?php
// ============================================
// ENTITY LEDGER - CLIENT RECEIPTS, PAYMENTS & JOURNALS
// ============================================

error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/entity_ledger_errors.log');

// Start output buffering
if (ob_get_level() == 0) {
    ob_start();
}

require_once '../config/config.php';
require_once '../auth/auth_middleware.php';

// Check if session is started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_trader();

$db = getDBConnection();
$current_user = get_logged_in_user() ?: get_session_user();
$user_name = $current_user['username'] ?? 'System';
$user_id = $current_user['id'] ?? null;

// ============================================
// GET FILTER PARAMETERS
// ============================================
$entity_type = isset($_GET['type']) ? $_GET['type'] : 'client';
$entity_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$entity_name = isset($_GET['name']) ? $_GET['name'] : '';
$date_from = $_GET['date_from'] ?? date('Y-m-01');
$date_to = $_GET['date_to'] ?? date('Y-m-d');
$search = $_GET['search'] ?? '';

if (!$entity_type || !$entity_id) {
    header('Location: debtors.php?error=Invalid entity parameters');
    exit;
}

// ============================================
// HELPER FUNCTIONS
// ============================================
function formatCurrency($amount) {
    return 'TZS ' . number_format($amount, 2);
}

function getEntityDetails($db, $type, $id) {
    $entity = null;
    
    if ($type === 'client') {
        $stmt = $db->prepare("SELECT id, client_name as name, cds_account as code, client_type, phone, email, status, is_active, created_at FROM clients WHERE id = ?");
        $stmt->execute([$id]);
        $entity = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($entity) {
            $entity['type_label'] = 'Client';
            $entity['code_label'] = 'CDS Account';
            $entity['icon'] = 'bi-person-badge';
            $entity['color'] = '#4F46E5';
        }
    }
    return $entity;
}

// ============================================
// GET CLIENT TRANSACTIONS - RECEIPTS, PAYMENTS & JOURNALS
// ============================================
function getClientTransactions($db, $id, $entity_name, $date_from, $date_to, $search = '') {
    $transactions = [];
    $total_debit = 0;
    $total_credit = 0;
    
    // 1. GET RECEIPTS for this client (Credit - money received)
    $sql = "
        SELECT 
            'Receipt' as source,
            r.id as reference_id,
            r.receipt_no as reference,
            r.receipt_date as transaction_date,
            r.narration as description,
            r.amount,
            r.currency,
            r.account_no as bank_account,
            'Credit' as debit_credit,
            0 as debit_amount,
            r.amount as credit_amount,
            r.created_by,
            r.created_at,
            r.status,
            'Payment Received' as category
        FROM receipts r
        WHERE r.name_id = ?
        AND r.account_of = 'C'
        AND r.record_in_financial = 'yes'
        AND r.receipt_date BETWEEN ? AND ?
        AND r.status != 'cancelled'
    ";
    $params = [$id, $date_from, $date_to];
    
    if (!empty($search)) {
        $sql .= " AND (r.receipt_no LIKE ? OR r.narration LIKE ?)";
        $search_param = "%$search%";
        $params[] = $search_param;
        $params[] = $search_param;
    }
    
    $sql .= " ORDER BY r.receipt_date ASC";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $receipts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($receipts as $r) {
        $transactions[] = $r;
        $total_credit += floatval($r['amount']);
    }
    
    // 2. GET PAYMENTS for this client (Debit - money paid)
    $sql = "
        SELECT 
            'Payment' as source,
            p.id as reference_id,
            p.payment_no as reference,
            p.payment_date as transaction_date,
            p.narration as description,
            p.amount,
            p.currency,
            p.account_no as bank_account,
            'Debit' as debit_credit,
            p.amount as debit_amount,
            0 as credit_amount,
            p.created_by,
            p.created_at,
            p.status,
            'Payment Made' as category
        FROM payments p
        WHERE p.name_id = ?
        AND p.paid_to = 'C'
        AND p.record_in_financial = 'yes'
        AND p.payment_date BETWEEN ? AND ?
        AND p.status != 'cancelled'
    ";
    $params = [$id, $date_from, $date_to];
    
    if (!empty($search)) {
        $sql .= " AND (p.payment_no LIKE ? OR p.narration LIKE ?)";
        $search_param = "%$search%";
        $params[] = $search_param;
        $params[] = $search_param;
    }
    
    $sql .= " ORDER BY p.payment_date ASC";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($payments as $p) {
        $transactions[] = $p;
        $total_debit += floatval($p['amount']);
    }
    
    // 3. GET JOURNAL ENTRIES for this client
    $sql = "
        SELECT 
            'Journal' as source,
            j.id as reference_id,
            j.journal_reference as reference,
            j.entry_date as transaction_date,
            j.description,
            j.amount,
            'TZS' as currency,
            NULL as bank_account,
            j.debit_credit,
            j.debit_amount,
            j.credit_amount,
            j.created_by,
            j.created_at,
            j.status,
            j.category,
            j.account_name,
            j.account_code
        FROM journal_entries j
        WHERE j.client_name = ?
        AND j.entry_date BETWEEN ? AND ?
        AND j.status != 'cancelled'
    ";
    $params = [$entity_name, $date_from, $date_to];
    
    if (!empty($search)) {
        $sql .= " AND (j.journal_reference LIKE ? OR j.description LIKE ?)";
        $search_param = "%$search%";
        $params[] = $search_param;
        $params[] = $search_param;
    }
    
    $sql .= " ORDER BY j.entry_date ASC";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $journal_entries = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($journal_entries as $j) {
        $transactions[] = $j;
        $total_debit += floatval($j['debit_amount'] ?? 0);
        $total_credit += floatval($j['credit_amount'] ?? 0);
    }
    
    // Sort by transaction date
    usort($transactions, function($a, $b) {
        return strtotime($a['transaction_date']) - strtotime($b['transaction_date']);
    });
    
    // Calculate running balance
    // For client: Debit increases balance, Credit decreases balance
    $balance = 0;
    foreach ($transactions as &$t) {
        $debit = floatval($t['debit_amount'] ?? 0);
        $credit = floatval($t['credit_amount'] ?? 0);
        
        // If amount is set but debit/credit not, determine based on source
        if ($debit == 0 && $credit == 0 && isset($t['amount'])) {
            if ($t['debit_credit'] === 'Debit' || $t['debit_credit'] === 'DR') {
                $debit = floatval($t['amount']);
            } else {
                $credit = floatval($t['amount']);
            }
        }
        
        $balance = $balance + $debit - $credit;
        $t['running_balance'] = $balance;
    }
    
    return [
        'transactions' => $transactions,
        'total_debit' => $total_debit,
        'total_credit' => $total_credit,
        'balance' => $balance
    ];
}

// ============================================
// GET DATA
// ============================================
$entity = null;
$transactions = [];
$total_debit = 0;
$total_credit = 0;
$balance = 0;
$error_message = '';

try {
    $entity = getEntityDetails($db, $entity_type, $entity_id);
    
    if (!$entity) {
        $error_message = "Entity not found.";
    } else {
        $entity_name = $entity['name'];
        $result = getClientTransactions($db, $entity_id, $entity_name, $date_from, $date_to, $search);
        $transactions = $result['transactions'];
        $total_debit = $result['total_debit'];
        $total_credit = $result['total_credit'];
        $balance = $result['balance'];
    }
} catch (Exception $e) {
    $error_message = "Error loading data: " . $e->getMessage();
    error_log("Entity ledger error: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
}

$page_title = 'Client Ledger - ' . ($entity ? htmlspecialchars($entity['name']) : '');
include '../includes/header.php';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?></title>
    <style>
        .entity-header {
            background: #f8f9fa;
            border: 1px solid #e9ecef;
            border-radius: 8px;
            padding: 20px 24px;
            margin-bottom: 20px;
        }
        .entity-header .name {
            font-size: 24px;
            font-weight: 700;
            color: #333;
        }
        .entity-header .code {
            color: #666;
            font-size: 14px;
        }
        .entity-header .meta {
            display: flex;
            gap: 16px;
            flex-wrap: wrap;
            margin-top: 8px;
        }
        .entity-header .meta .badge {
            background: #f8f9fa;
            border: 1px solid #ddd;
            border-radius: 4px;
            padding: 4px 12px;
            font-size: 12px;
            color: #666;
        }
        
        .stat-box {
            background: #f8f9fa;
            border: 1px solid #ddd;
            border-radius: 4px;
            padding: 10px 15px;
            text-align: center;
        }
        .stat-box .stat-number {
            font-size: 22px;
            font-weight: 600;
            color: #333;
        }
        .stat-box .stat-label {
            font-size: 12px;
            color: #666;
            margin-top: 2px;
        }
        .stat-box .stat-number.positive {
            color: #28a745;
        }
        .stat-box .stat-number.negative {
            color: #dc3545;
        }
        
        .filter-section {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 4px;
            margin-bottom: 20px;
            border: 1px solid #e9ecef;
        }
        .filter-section .form-label {
            font-size: 12px;
            font-weight: 600;
            color: #495057;
            margin-bottom: 4px;
        }
        
        .table-condensed td, .table-condensed th {
            padding: 6px 8px;
            font-size: 13px;
        }
        
        .badge-debit {
            background: #f8f9fa;
            color: #dc3545;
            border: 1px solid #dc3545;
            padding: 2px 8px;
            border-radius: 3px;
            font-size: 11px;
        }
        .badge-credit {
            background: #f8f9fa;
            color: #28a745;
            border: 1px solid #28a745;
            padding: 2px 8px;
            border-radius: 3px;
            font-size: 11px;
        }
        .badge-source {
            background: #f8f9fa;
            border: 1px solid #6c757d;
            color: #6c757d;
            padding: 2px 8px;
            border-radius: 3px;
            font-size: 10px;
        }
        .badge-journal {
            background: #f8f9fa;
            border: 1px solid #6f42c1;
            color: #6f42c1;
            padding: 2px 8px;
            border-radius: 3px;
            font-size: 10px;
        }
        
        .btn-secondary {
            background: #6c757d;
            color: white;
            border: none;
            padding: 6px 16px;
            border-radius: 4px;
            font-size: 13px;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .btn-secondary:hover {
            background: #5a6268;
            color: white;
        }
        .btn-outline-secondary {
            background: transparent;
            color: #6c757d;
            border: 1px solid #6c757d;
            padding: 5px 15px;
            border-radius: 4px;
            font-size: 13px;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .btn-outline-secondary:hover {
            background: #6c757d;
            color: white;
        }
        .btn-danger {
            background: #dc3545;
            color: white;
            border: none;
            padding: 6px 16px;
            border-radius: 4px;
            font-size: 13px;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .btn-danger:hover {
            background: #c82333;
            color: white;
        }
        .btn-success {
            background: #28a745;
            color: white;
            border: none;
            padding: 6px 16px;
            border-radius: 4px;
            font-size: 13px;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .btn-success:hover {
            background: #218838;
            color: white;
        }
        
        .back-btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 16px;
            background: #f8f9fa;
            border: 1px solid #ddd;
            border-radius: 4px;
            color: #333;
            text-decoration: none;
            font-size: 13px;
        }
        .back-btn:hover {
            background: #e9ecef;
            color: #333;
        }
        
        .export-buttons {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
        }
        
        .source-icon {
            font-size: 14px;
        }
        .source-icon.receipt { color: #28a745; }
        .source-icon.payment { color: #dc3545; }
        .source-icon.journal { color: #6f42c1; }
    </style>
</head>
<body>

<div class="container-fluid">
    <!-- Page Header -->
    <div class="row mb-3">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div>
                    <a href="debtors.php<?php echo isset($_GET['return_to']) ? '?' . htmlspecialchars($_GET['return_to']) : ''; ?>" class="back-btn">
                        <i class="bi bi-arrow-left"></i> Back
                    </a>
                </div>
                <div class="export-buttons">
                    <a href="?<?php echo http_build_query(array_merge($_GET, ['export' => 'pdf'])); ?>" class="btn-danger" target="_blank">
                        <i class="bi bi-file-pdf"></i> PDF
                    </a>
                    <a href="?<?php echo http_build_query(array_merge($_GET, ['export' => 'excel'])); ?>" class="btn-success">
                        <i class="bi bi-file-excel"></i> Excel
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- Alert Messages -->
    <?php if (isset($_SESSION['alert'])): ?>
        <div class="alert alert-<?php echo $_SESSION['alert'][1]; ?> alert-dismissible fade show">
            <?php echo htmlspecialchars($_SESSION['alert'][0]); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php unset($_SESSION['alert']); ?>
    <?php endif; ?>

    <?php if (!empty($error_message)): ?>
        <div class="alert alert-danger"><?php echo htmlspecialchars($error_message); ?></div>
    <?php endif; ?>

    <?php if ($entity): ?>
        <!-- Entity Header -->
        <div class="entity-header">
            <div class="d-flex justify-content-between align-items-center flex-wrap">
                <div>
                    <div class="name"><?php echo htmlspecialchars($entity['name']); ?></div>
                    <div class="code">
                        <i class="bi bi-hash"></i> 
                        <?php echo htmlspecialchars($entity['code_label']); ?>: <?php echo htmlspecialchars($entity['code']); ?>
                        <span class="badge"><?php echo $entity['type_label']; ?></span>
                        <?php if (isset($entity['client_type'])): ?>
                            <span class="badge"><?php echo htmlspecialchars($entity['client_type']); ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="meta">
                        <?php if (isset($entity['phone'])): ?>
                            <span class="badge"><i class="bi bi-phone"></i> <?php echo htmlspecialchars($entity['phone']); ?></span>
                        <?php endif; ?>
                        <?php if (isset($entity['email'])): ?>
                            <span class="badge"><i class="bi bi-envelope"></i> <?php echo htmlspecialchars($entity['email']); ?></span>
                        <?php endif; ?>
                        <?php if (isset($entity['created_at'])): ?>
                            <span class="badge"><i class="bi bi-calendar3"></i> Since <?php echo date('M Y', strtotime($entity['created_at'])); ?></span>
                        <?php endif; ?>
                        <span class="badge <?php echo ($entity['status'] == 'active' && $entity['is_active'] == 1) ? 'bg-success' : 'bg-danger'; ?>">
                            <?php echo ($entity['status'] == 'active' && $entity['is_active'] == 1) ? 'Active' : 'Inactive'; ?>
                        </span>
                    </div>
                </div>
                <div class="text-end">
                    <div style="font-size: 14px; color: #666;">Net Balance</div>
                    <div style="font-size: 28px; font-weight: 700; <?php echo $balance > 0 ? 'color: #dc3545;' : ($balance < 0 ? 'color: #28a745;' : 'color: #666;'); ?>">
                        <?php echo formatCurrency(abs($balance)); ?>
                    </div>
                    <div style="font-size: 12px; color: #666;">
                        <?php echo $balance > 0 ? 'Client Owes Us (DR)' : ($balance < 0 ? 'We Owe Client (CR)' : 'Settled'); ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Summary Stats -->
        <div class="row mb-3 g-2">
            <div class="col-md-3 col-6">
                <div class="stat-box">
                    <div class="stat-number text-danger"><?php echo formatCurrency($total_debit); ?></div>
                    <div class="stat-label">Total Debit (DR)</div>
                    <small class="text-muted">Payments + Journal Debits</small>
                </div>
            </div>
            <div class="col-md-3 col-6">
                <div class="stat-box">
                    <div class="stat-number text-success"><?php echo formatCurrency($total_credit); ?></div>
                    <div class="stat-label">Total Credit (CR)</div>
                    <small class="text-muted">Receipts + Journal Credits</small>
                </div>
            </div>
            <div class="col-md-3 col-6">
                <div class="stat-box">
                    <div class="stat-number <?php echo $balance >= 0 ? 'positive' : 'negative'; ?>">
                        <?php echo formatCurrency(abs($balance)); ?>
                    </div>
                    <div class="stat-label">Net Balance</div>
                    <small class="text-muted"><?php echo $balance > 0 ? 'Client Owes Us' : ($balance < 0 ? 'We Owe Client' : 'Settled'); ?></small>
                </div>
            </div>
            <div class="col-md-3 col-6">
                <div class="stat-box">
                    <div class="stat-number"><?php echo count($transactions); ?></div>
                    <div class="stat-label">Total Transactions</div>
                    <small class="text-muted">Receipts + Payments + Journals</small>
                </div>
            </div>
        </div>

        <!-- Filters -->
        <div class="filter-section">
            <form method="GET" class="row g-2 align-items-end">
                <input type="hidden" name="type" value="<?php echo htmlspecialchars($entity_type); ?>">
                <input type="hidden" name="id" value="<?php echo htmlspecialchars($entity_id); ?>">
                <input type="hidden" name="name" value="<?php echo htmlspecialchars($entity['name']); ?>">
                <?php if (isset($_GET['return_to'])): ?>
                    <input type="hidden" name="return_to" value="<?php echo htmlspecialchars($_GET['return_to']); ?>">
                <?php endif; ?>
                
                <div class="col-md-3">
                    <label class="form-label">Date From</label>
                    <input type="date" class="form-control form-control-sm" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Date To</label>
                    <input type="date" class="form-control form-control-sm" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Search</label>
                    <input type="text" class="form-control form-control-sm" name="search" placeholder="Reference or description..." value="<?php echo htmlspecialchars($search); ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">&nbsp;</label>
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-secondary btn-sm w-100">Apply</button>
                        <a href="entity_ledger.php?type=<?php echo urlencode($entity_type); ?>&id=<?php echo urlencode($entity_id); ?>&name=<?php echo urlencode($entity['name']); ?><?php echo isset($_GET['return_to']) ? '&return_to=' . urlencode($_GET['return_to']) : ''; ?>" class="btn btn-outline-secondary btn-sm w-100">Reset</a>
                    </div>
                </div>
            </form>
        </div>

        <!-- Transactions Table -->
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h6 class="mb-0">
                    <i class="bi bi-list-ul"></i> Transaction History
                    <span class="badge bg-secondary ms-2"><?php echo count($transactions); ?></span>
                </h6>
                <div>
                    <span class="badge bg-success">Receipt</span>
                    <span class="badge bg-danger">Payment</span>
                    <span class="badge" style="background: #6f42c1; color: white;">Journal</span>
                </div>
            </div>
            <div class="card-body p-0">
                <?php if (empty($transactions)): ?>
                    <div class="text-center py-5">
                        <i class="bi bi-inbox" style="font-size: 48px; color: #dee2e6;"></i>
                        <p class="text-muted mt-3">No transactions found for this client in the selected date range.</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm table-hover mb-0 table-condensed">
                            <thead class="table-light">
                                <tr>
                                    <th>Date</th>
                                    <th>Source</th>
                                    <th>Reference</th>
                                    <th>Description</th>
                                    <th>Account</th>
                                    <th>Type</th>
                                    <th class="text-end">Debit (DR)</th>
                                    <th class="text-end">Credit (CR)</th>
                                    <th class="text-end">Balance</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $running_balance = 0;
                                foreach ($transactions as $t): 
                                    $isDebit = ($t['debit_credit'] === 'Debit' || $t['debit_credit'] === 'DR');
                                    $amount = floatval($t['amount'] ?? 0);
                                    $debit = floatval($t['debit_amount'] ?? 0);
                                    $credit = floatval($t['credit_amount'] ?? 0);
                                    
                                    // Determine source and style
                                    $source = $t['source'] ?? 'Unknown';
                                    $source_class = '';
                                    $source_icon = '';
                                    $badge_class = 'badge-source';
                                    
                                    if ($source === 'Receipt') {
                                        $source_class = 'receipt';
                                        $source_icon = 'bi-arrow-down-circle';
                                        $badge_class = 'badge-credit';
                                    } elseif ($source === 'Payment') {
                                        $source_class = 'payment';
                                        $source_icon = 'bi-arrow-up-circle';
                                        $badge_class = 'badge-debit';
                                    } elseif ($source === 'Journal') {
                                        $source_class = 'journal';
                                        $source_icon = 'bi-journal';
                                        $badge_class = 'badge-journal';
                                    }
                                    
                                    if ($isDebit) {
                                        $display_debit = $debit > 0 ? $debit : $amount;
                                        $display_credit = 0;
                                        $type_label = 'DR';
                                        $type_class = 'badge-debit';
                                    } else {
                                        $display_debit = 0;
                                        $display_credit = $credit > 0 ? $credit : $amount;
                                        $type_label = 'CR';
                                        $type_class = 'badge-credit';
                                    }
                                    
                                    // For journal entries, use the debit/credit amounts directly
                                    if ($source === 'Journal') {
                                        $display_debit = $debit;
                                        $display_credit = $credit;
                                    }
                                ?>
                                    <tr>
                                        <td><?php echo date('d/m/Y', strtotime($t['transaction_date'])); ?></td>
                                        <td>
                                            <span class="badge <?php echo $badge_class; ?>">
                                                <i class="bi <?php echo $source_icon; ?> source-icon <?php echo $source_class; ?>"></i>
                                                <?php echo htmlspecialchars($source); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="fw-semibold small"><?php echo htmlspecialchars($t['reference'] ?? 'N/A'); ?></span>
                                            <?php if (!empty($t['reference_id'])): ?>
                                                <br><small class="text-muted">#<?php echo $t['reference_id']; ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php echo htmlspecialchars($t['description'] ?? ''); ?>
                                            <?php if (!empty($t['category'])): ?>
                                                <br><small class="text-muted"><?php echo htmlspecialchars($t['category']); ?></small>
                                            <?php endif; ?>
                                            <?php if (!empty($t['bank_account'])): ?>
                                                <br><small class="text-muted">Bank: <?php echo htmlspecialchars($t['bank_account']); ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if (!empty($t['account_name'])): ?>
                                                <?php echo htmlspecialchars($t['account_name']); ?>
                                                <br><small class="text-muted"><?php echo htmlspecialchars($t['account_code'] ?? ''); ?></small>
                                            <?php elseif (!empty($t['account_code'])): ?>
                                                <?php echo htmlspecialchars($t['account_code']); ?>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="<?php echo $type_class; ?>">
                                                <?php echo $type_label; ?>
                                            </span>
                                        </td>
                                        <td class="text-end">
                                            <?php if ($display_debit > 0): ?>
                                                <span class="badge-debit"><?php echo formatCurrency($display_debit); ?></span>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end">
                                            <?php if ($display_credit > 0): ?>
                                                <span class="badge-credit"><?php echo formatCurrency($display_credit); ?></span>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end fw-bold">
                                            <?php echo formatCurrency($t['running_balance'] ?? 0); ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot class="table-light">
                                <tr>
                                    <td colspan="6" class="text-end fw-bold">Totals:</td>
                                    <td class="text-end fw-bold">
                                        <span class="badge-debit"><?php echo formatCurrency($total_debit); ?></span>
                                    </td>
                                    <td class="text-end fw-bold">
                                        <span class="badge-credit"><?php echo formatCurrency($total_credit); ?></span>
                                    </td>
                                    <td class="text-end fw-bold">
                                        <?php if ($balance > 0): ?>
                                            <span class="text-danger">DR <?php echo formatCurrency($balance); ?></span>
                                        <?php elseif ($balance < 0): ?>
                                            <span class="text-success">CR <?php echo formatCurrency(abs($balance)); ?></span>
                                        <?php else: ?>
                                            <span class="text-muted">Settled</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</div>

<script>
// Auto-refresh alerts after 5 seconds
document.querySelectorAll('.alert').forEach(alert => {
    setTimeout(() => {
        const bsAlert = bootstrap.Alert.getOrCreateInstance(alert);
        if (bsAlert) bsAlert.close();
    }, 5000);
});
</script>

<?php include '../includes/footer.php'; ?>
