<?php
// ============================================
// FINANCE OFFICER - NUMERIC REFERENCE RECEIPT APPROVAL
// ============================================

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/numeric_receipt_errors.log');

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

require_finance_officer();

$db = getDBConnection();
$current_user = get_logged_in_user() ?: get_session_user();
$user_name = $current_user['username'] ?? 'System';
$user_id = $current_user['id'] ?? null;

// ============================================
// HANDLE ACTIONS
// ============================================

// Handle Approval / Rejection
if (isset($_POST['approve_receipt']) && isset($_POST['trade_id'])) {
    $trade_id = (int) $_POST['trade_id'];
    $is_approved = isset($_POST['is_approved']) ? (int)$_POST['is_approved'] : 1;
    $comment = trim($_POST['approval_comment'] ?? '');
    
    // Check if receipt exists
    $stmt = $db->prepare("SELECT id, is_approved FROM numeric_trade_receipts WHERE trade_id = ? AND trade_type = 'trade'");
    $stmt->execute([$trade_id]);
    $record = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$record) {
        $_SESSION['alert'] = ['No receipt found for this trade.', 'danger'];
    } else {
        $stmt = $db->prepare("
            UPDATE numeric_trade_receipts 
            SET is_approved = ?, approved_by = ?, approved_at = NOW(), approval_comment = ?, updated_at = NOW()
            WHERE trade_id = ? AND trade_type = 'trade'
        ");
        
        if ($stmt->execute([$is_approved, $user_name, $comment, $trade_id])) {
            $status_text = $is_approved == 1 ? 'approved' : 'rejected';
            $_SESSION['alert'] = ["Receipt $status_text successfully!", 'success'];
        } else {
            $_SESSION['alert'] = ['Failed to update receipt status.', 'danger'];
        }
    }
    
    header('Location: numeric_receipt_finance.php?' . http_build_query(array_filter([
        'filter' => $_GET['filter'] ?? 'pending',
        'asset_class' => $_GET['asset_class'] ?? 'all',
        'search' => $_GET['search'] ?? ''
    ])));
    exit;
}

// Handle Receipt Delete (Finance can delete any receipt)
if (isset($_GET['delete_receipt']) && isset($_GET['trade_id']) && isset($_GET['file'])) {
    $trade_id = (int) $_GET['trade_id'];
    $file_to_delete = $_GET['file'];
    
    $stmt = $db->prepare("SELECT payment_receipt FROM numeric_trade_receipts WHERE trade_id = ? AND trade_type = 'trade'");
    $stmt->execute([$trade_id]);
    $record = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($record && !empty($record['payment_receipt'])) {
        $receipts = explode(',', $record['payment_receipt']);
        
        if (($key = array_search($file_to_delete, $receipts)) !== false) {
            unset($receipts[$key]);
            
            $filepath = __DIR__ . '/../uploads/numeric_receipts/' . $file_to_delete;
            if (file_exists($filepath)) {
                unlink($filepath);
            }
            
            $receipts_str = !empty($receipts) ? implode(',', $receipts) : null;
            $stmt = $db->prepare("UPDATE numeric_trade_receipts SET payment_receipt = ?, updated_at = NOW() WHERE trade_id = ? AND trade_type = 'trade'");
            if ($stmt->execute([$receipts_str, $trade_id])) {
                $_SESSION['alert'] = ['Receipt deleted successfully.', 'success'];
            }
        }
    }
    header('Location: numeric_receipt_finance.php?' . http_build_query(array_filter([
        'filter' => $_GET['filter'] ?? 'pending',
        'asset_class' => $_GET['asset_class'] ?? 'all',
        'search' => $_GET['search'] ?? ''
    ])));
    exit;
}

// Handle Comment Upload (Finance can also add comments)
if (isset($_POST['add_comment']) && isset($_POST['trade_id'])) {
    $trade_id = (int) $_POST['trade_id'];
    $comment = trim($_POST['trader_comment'] ?? '');
    
    if (empty($comment)) {
        $_SESSION['alert'] = ['Please enter a comment.', 'danger'];
        header('Location: numeric_receipt_finance.php?' . http_build_query(array_filter([
            'filter' => $_GET['filter'] ?? 'pending',
            'asset_class' => $_GET['asset_class'] ?? 'all',
            'search' => $_GET['search'] ?? ''
        ])));
        exit;
    }
    
    $stmt = $db->prepare("
        INSERT INTO numeric_trade_comments (trade_id, comment, created_by, created_at) 
        VALUES (?, ?, ?, NOW())
    ");
    
    if ($stmt->execute([$trade_id, $comment, $user_name])) {
        $_SESSION['alert'] = ['Comment added successfully.', 'success'];
    } else {
        $_SESSION['alert'] = ['Failed to add comment.', 'danger'];
    }
    
    header('Location: numeric_receipt_finance.php?' . http_build_query(array_filter([
        'filter' => $_GET['filter'] ?? 'pending',
        'asset_class' => $_GET['asset_class'] ?? 'all',
        'search' => $_GET['search'] ?? ''
    ])));
    exit;
}

// ============================================
// GET FILTER PARAMETERS
// ============================================
$filter = $_GET['filter'] ?? 'pending';
$asset_class_filter = $_GET['asset_class'] ?? 'all';
$search = $_GET['search'] ?? '';

// ============================================
// FETCH TRADES WITH ADDITIONAL_REFERENCE = NUMBER ONLY
// ============================================
function getNumericTrades($db, $filter = 'pending', $asset_class_filter = 'all', $search = '') {
    $sql = "
        SELECT 
            t.id,
            t.trade_reference,
            t.asset_class,
            t.security_id,
            t.security_name,
            t.client_name,
            t.client_cds_account,
            t.trade_side,
            t.quantity,
            t.price,
            t.consideration,
            t.trade_date,
            t.settlement_date,
            t.exchange_reference,
            t.additional_reference,
            t.uploaded_by,
            t.status,
            t.created_at,
            MAX(tr.payment_receipt) as payment_receipt,
            MAX(tr.is_approved) as is_approved,
            MAX(tr.approved_by) as approved_by,
            MAX(tr.approved_at) as approved_at,
            MAX(tr.approval_comment) as approval_comment,
            MAX(tr.uploaded_by) as receipt_uploaded_by,
            MAX(tr.created_at) as receipt_created_at,
            MAX(tr.updated_at) as receipt_updated_at,
            GROUP_CONCAT(DISTINCT tc.comment ORDER BY tc.created_at DESC SEPARATOR '|||') as comments,
            GROUP_CONCAT(DISTINCT tc.created_by ORDER BY tc.created_at DESC SEPARATOR '|||') as comment_authors,
            GROUP_CONCAT(DISTINCT tc.created_at ORDER BY tc.created_at DESC SEPARATOR '|||') as comment_dates
        FROM trades t
        LEFT JOIN numeric_trade_receipts tr ON t.id = tr.trade_id AND tr.trade_type = 'trade'
        LEFT JOIN numeric_trade_comments tc ON t.id = tc.trade_id
        WHERE 1=1
    ";
    
    $params = [];
    
    // KEY CONDITION: additional_reference is a NUMBER ONLY
    $sql .= " AND t.additional_reference REGEXP '^[0-9]+$'";
    $sql .= " AND t.additional_reference IS NOT NULL";
    $sql .= " AND t.additional_reference != ''";
    
    // Asset class conditions
    if ($asset_class_filter === 'all') {
        $sql .= " AND (
            (t.asset_class = 'bond') OR 
            (t.asset_class IN ('equity', 'Exchange Traded Funds') AND LOWER(t.trade_side) = 'buy')
        )";
    } elseif ($asset_class_filter === 'bond') {
        $sql .= " AND t.asset_class = 'bond'";
    } elseif ($asset_class_filter === 'equity') {
        $sql .= " AND t.asset_class = 'equity' AND LOWER(t.trade_side) = 'buy'";
    } elseif ($asset_class_filter === 'etf') {
        $sql .= " AND t.asset_class = 'Exchange Traded Funds' AND LOWER(t.trade_side) = 'buy'";
    }
    
    // Filter by approval status
    if ($filter === 'pending') {
        $sql .= " AND (tr.is_approved IS NULL OR tr.is_approved = 0)";
    } elseif ($filter === 'approved') {
        $sql .= " AND tr.is_approved = 1";
    } elseif ($filter === 'rejected') {
        $sql .= " AND tr.is_approved = 2";
    }
    
    // Search filter
    if (!empty($search)) {
        $sql .= " AND (t.client_name LIKE ? OR t.security_id LIKE ? OR t.trade_reference LIKE ? OR t.exchange_reference LIKE ? OR t.additional_reference LIKE ?)";
        $search_param = "%$search%";
        $params[] = $search_param;
        $params[] = $search_param;
        $params[] = $search_param;
        $params[] = $search_param;
        $params[] = $search_param;
    }
    
    $sql .= " GROUP BY t.id ORDER BY t.trade_date DESC, t.id DESC";
    
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ============================================
// GET STATS
// ============================================
function getNumericStats($db) {
    $stats = [
        'total' => 0,
        'pending' => 0,
        'approved' => 0,
        'rejected' => 0,
        'bond' => 0,
        'equity' => 0,
        'etf' => 0
    ];
    
    try {
        $stmt = $db->query("
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN tr.is_approved IS NULL OR tr.is_approved = 0 THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN tr.is_approved = 1 THEN 1 ELSE 0 END) as approved,
                SUM(CASE WHEN tr.is_approved = 2 THEN 1 ELSE 0 END) as rejected
            FROM trades t
            LEFT JOIN numeric_trade_receipts tr ON t.id = tr.trade_id AND tr.trade_type = 'trade'
            WHERE t.additional_reference REGEXP '^[0-9]+$'
            AND t.additional_reference IS NOT NULL
            AND t.additional_reference != ''
            AND (
                (t.asset_class = 'bond') OR 
                (t.asset_class IN ('equity', 'Exchange Traded Funds') AND LOWER(t.trade_side) = 'buy')
            )
        ");
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($result) {
            $stats['total'] = (int)$result['total'];
            $stats['pending'] = (int)$result['pending'];
            $stats['approved'] = (int)$result['approved'];
            $stats['rejected'] = (int)$result['rejected'];
        }
    } catch (Exception $e) {
        error_log("Error getting stats: " . $e->getMessage());
    }
    
    try {
        $stmt = $db->query("
            SELECT 
                asset_class,
                COUNT(*) as count
            FROM trades t
            LEFT JOIN numeric_trade_receipts tr ON t.id = tr.trade_id AND tr.trade_type = 'trade'
            WHERE t.additional_reference REGEXP '^[0-9]+$'
            AND t.additional_reference IS NOT NULL
            AND t.additional_reference != ''
            AND (
                (t.asset_class = 'bond') OR 
                (t.asset_class IN ('equity', 'Exchange Traded Funds') AND LOWER(t.trade_side) = 'buy')
            )
            GROUP BY asset_class
        ");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if ($row['asset_class'] === 'bond') $stats['bond'] = (int)$row['count'];
            elseif ($row['asset_class'] === 'equity') $stats['equity'] = (int)$row['count'];
            elseif ($row['asset_class'] === 'Exchange Traded Funds') $stats['etf'] = (int)$row['count'];
        }
    } catch (Exception $e) {
        error_log("Error getting asset stats: " . $e->getMessage());
    }
    
    return $stats;
}

// ============================================
// GET DATA
// ============================================
$trades = [];
$stats = ['total' => 0, 'pending' => 0, 'approved' => 0, 'rejected' => 0, 'bond' => 0, 'equity' => 0, 'etf' => 0];
$error_message = '';

try {
    $trades = getNumericTrades($db, $filter, $asset_class_filter, $search);
    $stats = getNumericStats($db);
} catch (Exception $e) {
    $error_message = "Error loading data: " . $e->getMessage();
    error_log("Numeric receipt page error: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
}

$page_title = 'Finance - Numeric Reference Receipt Approval';
include '../includes/header.php';
?>

<style>
    .receipt-thumbnails {
        display: flex;
        gap: 3px;
        flex-wrap: wrap;
        align-items: center;
    }
    .receipt-thumbnails img {
        max-width: 40px;
        max-height: 35px;
        object-fit: cover;
        border: 1px solid #ddd;
        border-radius: 3px;
        cursor: pointer;
    }
    .receipt-thumbnails img:hover {
        border-color: #0d6efd;
    }
    .receipt-thumbnails .pdf-badge {
        background: #dc3545;
        color: white;
        padding: 2px 6px;
        border-radius: 3px;
        font-size: 10px;
        cursor: pointer;
    }
    .receipt-count {
        background: #0d6efd;
        color: white;
        border-radius: 50%;
        padding: 0 5px;
        font-size: 10px;
        margin-left: 2px;
    }
    .comment-bubble {
        background: #f8f9fa;
        border-left: 3px solid #0d6efd;
        padding: 4px 8px;
        border-radius: 3px;
        font-size: 12px;
        max-width: 200px;
    }
    .comment-bubble .author {
        font-weight: bold;
        font-size: 10px;
        color: #6c757d;
    }
    .action-btn {
        padding: 2px 6px;
        font-size: 12px;
    }
    .modal-lg {
        max-width: 600px;
    }
    .receipt-preview-container img {
        max-width: 80px;
        max-height: 60px;
        object-fit: cover;
        border: 1px solid #ddd;
        border-radius: 3px;
        margin: 2px;
    }
    .file-item {
        display: flex;
        justify-content: space-between;
        padding: 4px 8px;
        background: #f8f9fa;
        border-radius: 3px;
        margin-bottom: 2px;
        font-size: 13px;
    }
    .file-item .size {
        color: #6c757d;
        font-size: 11px;
    }
    .approval-badge {
        font-size: 11px;
        padding: 3px 8px;
    }
    .trade-row-pending {
        border-left: 3px solid #ffc107;
    }
    .trade-row-approved {
        border-left: 3px solid #198754;
    }
    .trade-row-rejected {
        border-left: 3px solid #dc3545;
    }
</style>

<div class="container-fluid">
    <!-- Page Header -->
    <div class="row mb-3">
        <div class="col-12">
            <h2>
                <i class="bi bi-check2-square"></i> Finance - Numeric Reference Receipt Approval
                <small class="text-muted">(Additional Reference = Number only)</small>
            </h2>
            <p class="text-muted">
                <span class="badge bg-info">Bonds: All trades</span>
                <span class="badge bg-success">Equities/ETFs: BUY only</span>
                <span class="badge bg-warning">Review and approve/reject receipts</span>
            </p>
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

    <!-- Stats -->
    <div class="row mb-3">
        <div class="col-md-2">
            <div class="card">
                <div class="card-body text-center">
                    <h5><?php echo number_format($stats['total']); ?></h5>
                    <small class="text-muted">Total</small>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card border-warning">
                <div class="card-body text-center">
                    <h5><?php echo number_format($stats['pending']); ?></h5>
                    <small class="text-muted">Pending</small>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card border-success">
                <div class="card-body text-center">
                    <h5><?php echo number_format($stats['approved']); ?></h5>
                    <small class="text-muted">Approved</small>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card border-danger">
                <div class="card-body text-center">
                    <h5><?php echo number_format($stats['rejected']); ?></h5>
                    <small class="text-muted">Rejected</small>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card">
                <div class="card-body text-center">
                    <h5>
                        <span class="badge bg-primary">B: <?php echo $stats['bond']; ?></span>
                        <span class="badge bg-success">E: <?php echo $stats['equity']; ?></span>
                        <span class="badge bg-warning">F: <?php echo $stats['etf']; ?></span>
                    </h5>
                    <small class="text-muted">Breakdown</small>
                </div>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <div class="card mb-3">
        <div class="card-body">
            <form method="GET" class="row g-2">
                <div class="col-md-3">
                    <select class="form-select form-select-sm" name="filter" onchange="this.form.submit()">
                        <option value="pending" <?php echo $filter === 'pending' ? 'selected' : ''; ?>>Pending</option>
                        <option value="approved" <?php echo $filter === 'approved' ? 'selected' : ''; ?>>Approved</option>
                        <option value="rejected" <?php echo $filter === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                        <option value="all" <?php echo $filter === 'all' ? 'selected' : ''; ?>>All</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <select class="form-select form-select-sm" name="asset_class" onchange="this.form.submit()">
                        <option value="all" <?php echo $asset_class_filter === 'all' ? 'selected' : ''; ?>>All Assets</option>
                        <option value="bond" <?php echo $asset_class_filter === 'bond' ? 'selected' : ''; ?>>Bond</option>
                        <option value="equity" <?php echo $asset_class_filter === 'equity' ? 'selected' : ''; ?>>Equity</option>
                        <option value="etf" <?php echo $asset_class_filter === 'etf' ? 'selected' : ''; ?>>ETF</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <input type="text" class="form-control form-control-sm" name="search" placeholder="Search..." value="<?php echo htmlspecialchars($search); ?>">
                </div>
                <div class="col-md-3">
                    <button type="submit" class="btn btn-primary btn-sm w-100">Apply</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Trades Table -->
    <div class="card">
        <div class="card-header">
            <h6 class="mb-0">Numeric Reference Trades (<?php echo count($trades); ?>)</h6>
        </div>
        <div class="card-body p-0">
            <?php if (empty($trades)): ?>
                <div class="text-center py-5">
                    <p class="text-muted">No trades found with numeric Additional Reference.</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Ref</th>
                                <th>Client</th>
                                <th>Security</th>
                                <th>Asset</th>
                                <th>Side</th>
                                <th class="text-end">Qty</th>
                                <th class="text-end">Price</th>
                                <th class="text-end">Value</th>
                                <th>Date</th>
                                <th>Add Ref</th>
                                <th>Receipts</th>
                                <th>Status</th>
                                <th>Comments</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($trades as $trade): 
                                $isBond = ($trade['asset_class'] === 'bond');
                                $receipts = !empty($trade['payment_receipt']) ? explode(',', $trade['payment_receipt']) : [];
                                $hasReceipt = !empty($receipts) && !empty($receipts[0]);
                                $isApproved = isset($trade['is_approved']) ? (int)$trade['is_approved'] : 0;
                                $statusText = $isApproved === 1 ? 'Approved' : ($isApproved === 2 ? 'Rejected' : 'Pending');
                                $statusClass = $isApproved === 1 ? 'success' : ($isApproved === 2 ? 'danger' : 'warning');
                                $rowClass = $isApproved === 1 ? 'trade-row-approved' : ($isApproved === 2 ? 'trade-row-rejected' : 'trade-row-pending');
                                
                                if ($isBond) {
                                    $displayQty = 'TZS ' . number_format(floatval($trade['quantity'] ?? 0), 2);
                                    $displayPrice = number_format(floatval($trade['price'] ?? 0), 4) . '%';
                                    $displayValue = 'TZS ' . number_format(floatval($trade['consideration'] ?? 0), 2);
                                    $assetClass = 'Bond';
                                } else {
                                    $displayQty = number_format(floatval($trade['quantity'] ?? 0), 0);
                                    $displayPrice = 'TZS ' . number_format(floatval($trade['price'] ?? 0), 2);
                                    $displayValue = 'TZS ' . number_format(floatval($trade['consideration'] ?? 0), 2);
                                    $assetClass = ucfirst($trade['asset_class'] ?? 'Equity');
                                    if ($assetClass === 'Exchange Traded Funds') $assetClass = 'ETF';
                                }
                                
                                // Get comments
                                $commentText = '';
                                $commentAuthor = '';
                                if (!empty($trade['comments'])) {
                                    $comment_parts = explode('|||', $trade['comments']);
                                    $author_parts = !empty($trade['comment_authors']) ? explode('|||', $trade['comment_authors']) : [];
                                    $commentText = htmlspecialchars(substr($comment_parts[0] ?? '', 0, 50));
                                    if (strlen($comment_parts[0] ?? '') > 50) $commentText .= '...';
                                    $commentAuthor = $author_parts[0] ?? 'Unknown';
                                }
                            ?>
                                <tr class="<?php echo $rowClass; ?>">
                                    <td><span class="fw-semibold small"><?php echo htmlspecialchars($trade['trade_reference'] ?? ''); ?></span></td>
                                    <td><?php echo htmlspecialchars($trade['client_name'] ?? ''); ?></td>
                                    <td><?php echo htmlspecialchars($trade['security_id'] ?? ''); ?></td>
                                    <td><span class="badge bg-secondary"><?php echo $assetClass; ?></span></td>
                                    <td><span class="badge <?php echo strtolower($trade['trade_side'] ?? '') === 'buy' ? 'bg-success' : 'bg-danger'; ?>"><?php echo strtoupper($trade['trade_side'] ?? ''); ?></span></td>
                                    <td class="text-end"><?php echo $displayQty; ?></td>
                                    <td class="text-end"><?php echo $displayPrice; ?></td>
                                    <td class="text-end fw-bold"><?php echo $displayValue; ?></td>
                                    <td><?php echo date('d/m/Y', strtotime($trade['trade_date'] ?? '')); ?></td>
                                    <td><code class="small"><?php echo htmlspecialchars($trade['additional_reference'] ?? ''); ?></code></td>
                                    <td>
                                        <?php if ($hasReceipt): ?>
                                            <div class="receipt-thumbnails">
                                                <?php 
                                                $count = 0;
                                                foreach ($receipts as $receiptFile):
                                                    $receiptFile = trim($receiptFile);
                                                    if (empty($receiptFile)) continue;
                                                    $count++;
                                                    $filepath = '../uploads/numeric_receipts/' . $receiptFile;
                                                    $ext = strtolower(pathinfo($receiptFile, PATHINFO_EXTENSION));
                                                    if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif']) && file_exists($filepath)):
                                                ?>
                                                    <img src="<?php echo $filepath; ?>" alt="Receipt" onclick="window.open('<?php echo $filepath; ?>', '_blank')" title="Click to view">
                                                <?php else: ?>
                                                    <span class="pdf-badge" onclick="window.open('<?php echo $filepath; ?>', '_blank')">PDF</span>
                                                <?php endif; ?>
                                                <?php endforeach; ?>
                                                <?php if (count($receipts) > 3): ?>
                                                    <span class="receipt-count">+<?php echo count($receipts) - 3; ?></span>
                                                <?php endif; ?>
                                                <a href="numeric_receipt_finance.php?delete_receipt=1&trade_id=<?php echo $trade['id']; ?>&file=<?php echo urlencode($receipts[0]); ?>&filter=<?php echo urlencode($filter); ?>&asset_class=<?php echo urlencode($asset_class_filter); ?>&search=<?php echo urlencode($search); ?>" 
                                                   class="text-danger small" onclick="return confirm('Delete this receipt?')">
                                                    <i class="bi bi-x-circle"></i>
                                                </a>
                                            </div>
                                        <?php else: ?>
                                            <span class="text-muted small">No receipt</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge bg-<?php echo $statusClass; ?> approval-badge"><?php echo $statusText; ?></span>
                                        <?php if ($isApproved === 1 && !empty($trade['approved_by'])): ?>
                                            <br><small class="text-muted">by <?php echo htmlspecialchars($trade['approved_by']); ?></small>
                                            <br><small class="text-muted"><?php echo date('d/m/Y H:i', strtotime($trade['approved_at'])); ?></small>
                                        <?php endif; ?>
                                        <?php if ($isApproved === 2 && !empty($trade['approval_comment'])): ?>
                                            <br><small class="text-danger"><?php echo htmlspecialchars(substr($trade['approval_comment'], 0, 30)); ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!empty($commentText)): ?>
                                            <div class="comment-bubble">
                                                <div class="author"><?php echo $commentAuthor; ?>:</div>
                                                <?php echo $commentText; ?>
                                            </div>
                                        <?php else: ?>
                                            <span class="text-muted small">No comments</span>
                                        <?php endif; ?>
                                        <button class="btn btn-outline-secondary btn-sm mt-1" onclick="openCommentModal(<?php echo $trade['id']; ?>)">
                                            <i class="bi bi-chat"></i>
                                        </button>
                                    </td>
                                    <td class="text-end">
                                        <?php if ($hasReceipt && $isApproved === 0): ?>
                                            <button class="btn btn-outline-success btn-sm" onclick="openApprovalModal(<?php echo $trade['id']; ?>, 1)" title="Approve">
                                                <i class="bi bi-check-lg"></i>
                                            </button>
                                            <button class="btn btn-outline-danger btn-sm" onclick="openApprovalModal(<?php echo $trade['id']; ?>, 0)" title="Reject">
                                                <i class="bi bi-x-lg"></i>
                                            </button>
                                        <?php elseif ($hasReceipt && $isApproved === 2): ?>
                                            <button class="btn btn-outline-success btn-sm" onclick="openApprovalModal(<?php echo $trade['id']; ?>, 1)" title="Re-approve">
                                                <i class="bi bi-arrow-counterclockwise"></i>
                                            </button>
                                        <?php endif; ?>
                                        <button class="btn btn-outline-secondary btn-sm" onclick="openCommentModal(<?php echo $trade['id']; ?>)" title="Add Comment">
                                            <i class="bi bi-chat"></i>
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Approval Modal -->
<div class="modal fade" id="approvalModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header" id="approvalModalHeader">
                <h5 class="modal-title" id="approvalModalTitle"><i class="bi bi-check-circle"></i> Approve Receipt</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="numeric_receipt_finance.php">
                <div class="modal-body">
                    <input type="hidden" name="trade_id" id="approval_trade_id" value="">
                    <input type="hidden" name="is_approved" id="approval_is_approved" value="1">
                    <input type="hidden" name="approve_receipt" value="1">
                    <input type="hidden" name="filter" value="<?php echo htmlspecialchars($filter); ?>">
                    <input type="hidden" name="asset_class" value="<?php echo htmlspecialchars($asset_class_filter); ?>">
                    <input type="hidden" name="search" value="<?php echo htmlspecialchars($search); ?>">
                    
                    <div class="mb-3">
                        <label class="form-label">Comment (Optional)</label>
                        <textarea class="form-control" name="approval_comment" id="approval_comment" rows="3" placeholder="Enter your approval/rejection comment..."></textarea>
                    </div>
                    
                    <div class="alert" id="approvalAlert">
                        <i class="bi" id="approvalAlertIcon"></i>
                        <span id="approvalAlertMessage">Are you sure you want to approve this receipt?</span>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn" id="approvalSubmitBtn">Confirm</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Comment Modal -->
<div class="modal fade" id="commentModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-chat"></i> Add Comment</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="numeric_receipt_finance.php">
                <div class="modal-body">
                    <input type="hidden" name="trade_id" id="comment_trade_id" value="">
                    <input type="hidden" name="add_comment" value="1">
                    <input type="hidden" name="filter" value="<?php echo htmlspecialchars($filter); ?>">
                    <input type="hidden" name="asset_class" value="<?php echo htmlspecialchars($asset_class_filter); ?>">
                    <input type="hidden" name="search" value="<?php echo htmlspecialchars($search); ?>">
                    
                    <div class="mb-3">
                        <label class="form-label">Your Comment</label>
                        <textarea class="form-control" name="trader_comment" rows="4" placeholder="Enter your comment about this trade..." required></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Add Comment</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function openApprovalModal(tradeId, isApproved) {
    document.getElementById('approval_trade_id').value = tradeId;
    document.getElementById('approval_is_approved').value = isApproved;
    document.getElementById('approval_comment').value = '';
    
    const header = document.getElementById('approvalModalHeader');
    const title = document.getElementById('approvalModalTitle');
    const btn = document.getElementById('approvalSubmitBtn');
    const alertBox = document.getElementById('approvalAlert');
    const alertIcon = document.getElementById('approvalAlertIcon');
    const alertMsg = document.getElementById('approvalAlertMessage');
    
    if (isApproved === 1) {
        header.className = 'modal-header bg-success text-white';
        title.innerHTML = '<i class="bi bi-check-circle"></i> Approve Receipt';
        btn.className = 'btn btn-success';
        btn.textContent = 'Approve';
        alertBox.className = 'alert alert-success';
        alertIcon.className = 'bi bi-check-circle';
        alertMsg.textContent = 'Are you sure you want to APPROVE this receipt?';
    } else {
        header.className = 'modal-header bg-danger text-white';
        title.innerHTML = '<i class="bi bi-x-circle"></i> Reject Receipt';
        btn.className = 'btn btn-danger';
        btn.textContent = 'Reject';
        alertBox.className = 'alert alert-danger';
        alertIcon.className = 'bi bi-x-circle';
        alertMsg.textContent = 'Are you sure you want to REJECT this receipt?';
    }
    
    const modal = new bootstrap.Modal(document.getElementById('approvalModal'));
    modal.show();
}

function openCommentModal(tradeId) {
    document.getElementById('comment_trade_id').value = tradeId;
    const modal = new bootstrap.Modal(document.getElementById('commentModal'));
    modal.show();
}
</script>

<?php include '../includes/footer.php'; ?>
