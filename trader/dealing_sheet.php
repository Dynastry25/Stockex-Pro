<?php
// ============================================
// FIXED VERSION - Handles ONLY_FULL_GROUP_BY
// ============================================

// Enable full error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
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

require_trader();

$db = getDBConnection();
$current_user = get_logged_in_user() ?: get_session_user();
$user_name = $current_user['username'] ?? 'System';
$user_id = $current_user['id'] ?? null;

// ============================================
// HANDLE ACTIONS (Traders only - no approval)
// ============================================

// Handle Receipt Upload
if (isset($_POST['upload_receipt']) && isset($_POST['trade_id'])) {
    $trade_id = (int) $_POST['trade_id'];
    $uploaded_files = [];
    $errors = [];
    
    $allowed_exts = ['jpg', 'jpeg', 'png', 'gif', 'pdf'];
    $max_size = 5 * 1024 * 1024;
    
    $upload_dir = __DIR__ . '/../uploads/numeric_receipts/';
    if (!file_exists($upload_dir)) {
        mkdir($upload_dir, 0777, true);
    }
    
    // Get existing receipts
    $stmt = $db->prepare("SELECT payment_receipt FROM numeric_trade_receipts WHERE trade_id = ? AND trade_type = 'trade'");
    $stmt->execute([$trade_id]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);
    $existing_receipts = !empty($existing['payment_receipt']) ? explode(',', $existing['payment_receipt']) : [];
    
    if (isset($_FILES['payment_receipts']) && !empty($_FILES['payment_receipts']['name'][0])) {
        $files = $_FILES['payment_receipts'];
        $total_files = count($files['name']);
        
        for ($i = 0; $i < $total_files; $i++) {
            if ($files['error'][$i] !== UPLOAD_ERR_OK) {
                $errors[] = "File '{$files['name'][$i]}' upload error: " . $files['error'][$i];
                continue;
            }
            
            $file_ext = strtolower(pathinfo($files['name'][$i], PATHINFO_EXTENSION));
            if (!in_array($file_ext, $allowed_exts)) {
                $errors[] = "File '{$files['name'][$i]}' - Invalid type. Allowed: JPG, PNG, GIF, PDF";
                continue;
            }
            
            if ($files['size'][$i] > $max_size) {
                $errors[] = "File '{$files['name'][$i]}' exceeds 5MB limit";
                continue;
            }
            
            $filename = 'receipt_' . $trade_id . '_' . date('Ymd_His') . '_' . ($i + 1) . '.' . $file_ext;
            $filepath = $upload_dir . $filename;
            
            if (move_uploaded_file($files['tmp_name'][$i], $filepath)) {
                $uploaded_files[] = $filename;
            } else {
                $errors[] = "Failed to upload file '{$files['name'][$i]}'";
            }
        }
        
        if (!empty($uploaded_files)) {
            $all_receipts = array_merge($existing_receipts, $uploaded_files);
            $receipts_str = implode(',', $all_receipts);
            
            // Check if record exists
            $stmt = $db->prepare("SELECT id FROM numeric_trade_receipts WHERE trade_id = ? AND trade_type = 'trade'");
            $stmt->execute([$trade_id]);
            if ($stmt->fetch()) {
                $stmt = $db->prepare("UPDATE numeric_trade_receipts SET payment_receipt = ?, updated_at = NOW(), uploaded_by = ? WHERE trade_id = ? AND trade_type = 'trade'");
                $result = $stmt->execute([$receipts_str, $user_name, $trade_id]);
            } else {
                $stmt = $db->prepare("INSERT INTO numeric_trade_receipts (trade_id, trade_type, payment_receipt, uploaded_by, created_at, updated_at) VALUES (?, 'trade', ?, ?, NOW(), NOW())");
                $result = $stmt->execute([$trade_id, $receipts_str, $user_name]);
            }
            
            if ($result) {
                $_SESSION['alert'] = [count($uploaded_files) . ' receipt(s) uploaded successfully!', 'success'];
            } else {
                $_SESSION['alert'] = ['Failed to update database', 'danger'];
            }
        } else {
            $_SESSION['alert'] = ['No files were uploaded successfully. Errors: ' . implode('; ', $errors), 'danger'];
        }
    } else {
        $_SESSION['alert'] = ['No files selected for upload', 'danger'];
    }
    header('Location: numeric_receipt_upload.php?' . http_build_query(array_filter([
        'filter' => $_GET['filter'] ?? 'pending',
        'asset_class' => $_GET['asset_class'] ?? 'all',
        'search' => $_GET['search'] ?? ''
    ])));
    exit;
}

// Handle Receipt Delete
if (isset($_GET['delete_receipt']) && isset($_GET['trade_id']) && isset($_GET['file'])) {
    $trade_id = (int) $_GET['trade_id'];
    $file_to_delete = $_GET['file'];
    
    // Check if receipt is already approved
    $stmt = $db->prepare("SELECT is_approved FROM numeric_trade_receipts WHERE trade_id = ? AND trade_type = 'trade'");
    $stmt->execute([$trade_id]);
    $record = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($record && $record['is_approved'] == 1) {
        $_SESSION['alert'] = ['Cannot delete approved receipts. Please contact finance officer.', 'warning'];
        header('Location: numeric_receipt_upload.php?' . http_build_query(array_filter([
            'filter' => $_GET['filter'] ?? 'pending',
            'asset_class' => $_GET['asset_class'] ?? 'all',
            'search' => $_GET['search'] ?? ''
        ])));
        exit;
    }
    
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
    header('Location: numeric_receipt_upload.php?' . http_build_query(array_filter([
        'filter' => $_GET['filter'] ?? 'pending',
        'asset_class' => $_GET['asset_class'] ?? 'all',
        'search' => $_GET['search'] ?? ''
    ])));
    exit;
}

// Handle Trader Comment Upload
if (isset($_POST['add_comment']) && isset($_POST['trade_id'])) {
    $trade_id = (int) $_POST['trade_id'];
    $comment = trim($_POST['trader_comment'] ?? '');
    
    if (empty($comment)) {
        $_SESSION['alert'] = ['Please enter a comment.', 'danger'];
        header('Location: numeric_receipt_upload.php?' . http_build_query(array_filter([
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
    
    header('Location: numeric_receipt_upload.php?' . http_build_query(array_filter([
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
// FIXED: Added proper GROUP BY with all columns
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
            ANY_VALUE(tr.payment_receipt) as payment_receipt,
            ANY_VALUE(tr.is_approved) as is_approved,
            ANY_VALUE(tr.approved_by) as approved_by,
            ANY_VALUE(tr.approved_at) as approved_at,
            ANY_VALUE(tr.approval_comment) as approval_comment,
            ANY_VALUE(tr.uploaded_by) as receipt_uploaded_by,
            ANY_VALUE(tr.created_at) as receipt_created_at,
            ANY_VALUE(tr.updated_at) as receipt_updated_at,
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
// GET STATS - FIXED
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

$page_title = 'Numeric Reference Receipt Upload';
include '../includes/header.php';
?>

<div class="container-fluid">
    <!-- Page Header -->
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
        <div>
            <h1 class="h3 mb-0 fw-bold">
                <i class="bi bi-receipt-cutoff me-2" style="color: var(--primary);"></i>
                Numeric Reference Receipt Upload
            </h1>
            <p class="text-muted mb-0 small">
                Upload payment receipts for trades where <strong>Additional Reference is a number only</strong>
            </p>
        </div>
        <div>
            <a href="trades.php" class="btn btn-secondary">
                <i class="bi bi-arrow-left"></i> Back to Trades
            </a>
        </div>
    </div>

    <!-- Display any errors -->
    <?php if (!empty($error_message)): ?>
        <div class="alert alert-danger">
            <i class="bi bi-exclamation-triangle me-2"></i>
            <?php echo htmlspecialchars($error_message); ?>
        </div>
    <?php endif; ?>

    <!-- Alert Messages -->
    <?php if (isset($_SESSION['alert'])): ?>
        <div class="alert alert-<?php echo $_SESSION['alert'][1]; ?> alert-dismissible fade show mb-4">
            <i class="bi <?php echo $_SESSION['alert'][1] === 'success' ? 'bi-check-circle' : ($_SESSION['alert'][1] === 'warning' ? 'bi-exclamation-triangle' : 'bi-x-circle'); ?> me-2"></i>
            <?php echo htmlspecialchars($_SESSION['alert'][0]); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php unset($_SESSION['alert']); ?>
    <?php endif; ?>

    <!-- Stats Cards -->
    <div class="row g-3 mb-4">
        <div class="col-md-2">
            <div class="card bg-primary text-white">
                <div class="card-body">
                    <div class="small">Total</div>
                    <div class="fs-3 fw-bold"><?php echo number_format($stats['total']); ?></div>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card bg-warning text-dark">
                <div class="card-body">
                    <div class="small">Pending</div>
                    <div class="fs-3 fw-bold"><?php echo number_format($stats['pending']); ?></div>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card bg-success text-white">
                <div class="card-body">
                    <div class="small">Approved</div>
                    <div class="fs-3 fw-bold"><?php echo number_format($stats['approved']); ?></div>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card bg-danger text-white">
                <div class="card-body">
                    <div class="small">Rejected</div>
                    <div class="fs-3 fw-bold"><?php echo number_format($stats['rejected']); ?></div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card bg-info text-white">
                <div class="card-body">
                    <div class="small">Breakdown</div>
                    <div class="fs-4 fw-bold">
                        <span class="badge bg-primary">B: <?php echo $stats['bond']; ?></span>
                        <span class="badge bg-success ms-1">E: <?php echo $stats['equity']; ?></span>
                        <span class="badge bg-warning ms-1 text-dark">F: <?php echo $stats['etf']; ?></span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Filter Section -->
    <div class="card mb-4">
        <div class="card-body">
            <form method="GET" class="row g-3">
                <div class="col-md-3">
                    <label class="form-label small fw-semibold">Status</label>
                    <select class="form-select" name="filter" onchange="this.form.submit()">
                        <option value="pending" <?php echo $filter === 'pending' ? 'selected' : ''; ?>>Pending</option>
                        <option value="approved" <?php echo $filter === 'approved' ? 'selected' : ''; ?>>Approved</option>
                        <option value="rejected" <?php echo $filter === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                        <option value="all" <?php echo $filter === 'all' ? 'selected' : ''; ?>>All</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-semibold">Asset Class</label>
                    <select class="form-select" name="asset_class" onchange="this.form.submit()">
                        <option value="all" <?php echo $asset_class_filter === 'all' ? 'selected' : ''; ?>>All</option>
                        <option value="bond" <?php echo $asset_class_filter === 'bond' ? 'selected' : ''; ?>>Bond</option>
                        <option value="equity" <?php echo $asset_class_filter === 'equity' ? 'selected' : ''; ?>>Equity</option>
                        <option value="etf" <?php echo $asset_class_filter === 'etf' ? 'selected' : ''; ?>>ETF</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-semibold">Search</label>
                    <input type="text" class="form-control" name="search" placeholder="Client, Security, Ref..." 
                           value="<?php echo htmlspecialchars($search); ?>">
                </div>
                <div class="col-md-3 d-flex align-items-end gap-2">
                    <button type="submit" class="btn btn-primary w-100">Apply</button>
                    <a href="numeric_receipt_upload.php" class="btn btn-secondary w-100">Reset</a>
                </div>
            </form>
        </div>
    </div>

    <!-- Table -->
    <div class="card">
        <div class="card-header">
            <h6 class="mb-0">
                <i class="bi bi-table me-2"></i>
                Numeric Reference Trades
                <span class="badge bg-primary ms-2"><?php echo count($trades); ?> trades</span>
            </h6>
        </div>
        <div class="card-body p-0">
            <?php if (empty($trades)): ?>
                <div class="text-center py-5">
                    <i class="bi bi-inbox" style="font-size: 3rem; color: #ccc;"></i>
                    <h5 class="mt-3 text-muted">No trades with numeric Additional Reference found</h5>
                    <p class="text-muted">Try adjusting your filters.</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Reference</th>
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
                                $isApprovedText = $isApproved === 1 ? 'Approved' : ($isApproved === 2 ? 'Rejected' : 'Pending');
                                $isApprovedClass = $isApproved === 1 ? 'success' : ($isApproved === 2 ? 'danger' : 'warning');
                                
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
                                    $commentText = htmlspecialchars(substr($comment_parts[0] ?? '', 0, 40));
                                    if (strlen($comment_parts[0] ?? '') > 40) $commentText .= '...';
                                    $commentAuthor = $author_parts[0] ?? 'Unknown';
                                }
                            ?>
                                <tr>
                                    <td><span class="fw-semibold"><?php echo htmlspecialchars($trade['trade_reference'] ?? ''); ?></span></td>
                                    <td><?php echo htmlspecialchars($trade['client_name'] ?? ''); ?></td>
                                    <td><?php echo htmlspecialchars($trade['security_id'] ?? ''); ?></td>
                                    <td><span class="badge bg-secondary"><?php echo $assetClass; ?></span></td>
                                    <td><span class="badge <?php echo strtolower($trade['trade_side'] ?? '') === 'buy' ? 'bg-success' : 'bg-danger'; ?>"><?php echo strtoupper($trade['trade_side'] ?? ''); ?></span></td>
                                    <td class="text-end"><?php echo $displayQty; ?></td>
                                    <td class="text-end"><?php echo $displayPrice; ?></td>
                                    <td class="text-end fw-bold"><?php echo $displayValue; ?></td>
                                    <td><?php echo date('d/m/Y', strtotime($trade['trade_date'] ?? '')); ?></td>
                                    <td><code><?php echo htmlspecialchars($trade['additional_reference'] ?? ''); ?></code></td>
                                    <td>
                                        <?php if ($hasReceipt): ?>
                                            <span class="badge bg-success">Has Receipt</span>
                                            <span class="badge bg-secondary"><?php echo count($receipts); ?> files</span>
                                        <?php else: ?>
                                            <button class="btn btn-outline-success btn-sm" onclick="openReceiptUpload(<?php echo $trade['id']; ?>)">
                                                <i class="bi bi-upload"></i> Upload
                                            </button>
                                        <?php endif; ?>
                                    </td>
                                    <td><span class="badge bg-<?php echo $isApprovedClass; ?>"><?php echo $isApprovedText; ?></span></td>
                                    <td>
                                        <?php if (!empty($commentText)): ?>
                                            <div class="small text-muted">
                                                <strong><?php echo $commentAuthor; ?>:</strong> <?php echo $commentText; ?>
                                            </div>
                                        <?php else: ?>
                                            <span class="text-muted">No comments</span>
                                        <?php endif; ?>
                                        <button class="btn btn-outline-secondary btn-sm mt-1" onclick="openCommentModal(<?php echo $trade['id']; ?>)">
                                            <i class="bi bi-chat"></i>
                                        </button>
                                    </td>
                                    <td class="text-end">
                                        <div class="d-flex gap-1 justify-content-end">
                                            <?php if ($isApproved !== 1): ?>
                                                <button class="btn btn-outline-success btn-sm" onclick="openReceiptUpload(<?php echo $trade['id']; ?>)" title="Upload Receipt">
                                                    <i class="bi bi-upload"></i>
                                                </button>
                                            <?php endif; ?>
                                            <button class="btn btn-outline-info btn-sm" onclick="viewTradeDetails(<?php echo htmlspecialchars(json_encode($trade)); ?>)" title="View Details">
                                                <i class="bi bi-eye"></i>
                                            </button>
                                        </div>
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

<!-- Modals -->
<!-- Receipt Upload Modal -->
<div class="modal fade" id="receiptModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-md">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title"><i class="bi bi-upload me-2"></i>Upload Payment Receipts</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" enctype="multipart/form-data" action="numeric_receipt_upload.php">
                <div class="modal-body">
                    <input type="hidden" name="trade_id" id="receipt_trade_id" value="">
                    <input type="hidden" name="upload_receipt" value="1">
                    <input type="hidden" name="filter" value="<?php echo htmlspecialchars($filter); ?>">
                    <input type="hidden" name="asset_class" value="<?php echo htmlspecialchars($asset_class_filter); ?>">
                    <input type="hidden" name="search" value="<?php echo htmlspecialchars($search); ?>">
                    
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Select Receipt Files</label>
                        <input type="file" class="form-control" name="payment_receipts[]" accept="image/*,.pdf" multiple required>
                        <div class="form-text">Allowed: JPG, PNG, GIF, PDF (Max 5MB each)</div>
                    </div>
                    
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle me-2"></i>
                        Upload clear copies of payment receipts or confirmations.
                        <br><small class="text-muted">Multiple files can be selected at once.</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success">Upload Receipts</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Comment Modal -->
<div class="modal fade" id="commentModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-md">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title"><i class="bi bi-chat-dots me-2"></i>Add Comment</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="numeric_receipt_upload.php">
                <div class="modal-body">
                    <input type="hidden" name="trade_id" id="comment_trade_id" value="">
                    <input type="hidden" name="add_comment" value="1">
                    <input type="hidden" name="filter" value="<?php echo htmlspecialchars($filter); ?>">
                    <input type="hidden" name="asset_class" value="<?php echo htmlspecialchars($asset_class_filter); ?>">
                    <input type="hidden" name="search" value="<?php echo htmlspecialchars($search); ?>">
                    
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Your Comment</label>
                        <textarea class="form-control" name="trader_comment" rows="4" placeholder="Enter your comment about this trade..." required></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-info text-white">Add Comment</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// ============================================
// RECEIPT UPLOAD FUNCTIONS
// ============================================
function openReceiptUpload(tradeId) {
    document.getElementById('receipt_trade_id').value = tradeId;
    const modal = new bootstrap.Modal(document.getElementById('receiptModal'));
    modal.show();
}

function openCommentModal(tradeId) {
    document.getElementById('comment_trade_id').value = tradeId;
    document.getElementById('trader_comment').value = '';
    const modal = new bootstrap.Modal(document.getElementById('commentModal'));
    modal.show();
}

function viewTradeDetails(trade) {
    // Simple alert for now
    alert('Trade Details:\n' + 
          'Reference: ' + trade.trade_reference + '\n' +
          'Client: ' + trade.client_name + '\n' +
          'Security: ' + trade.security_id + '\n' +
          'Quantity: ' + trade.quantity + '\n' +
          'Price: ' + trade.price + '\n' +
          'Consideration: ' + trade.consideration + '\n' +
          'Additional Reference: ' + trade.additional_reference);
}
</script>

<?php include '../includes/footer.php'; ?>
