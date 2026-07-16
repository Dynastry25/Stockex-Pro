<?php
// ============================================
// SIMPLE NUMERIC REFERENCE RECEIPT UPLOAD
// ============================================

error_reporting(E_ALL);
ini_set('display_errors', 1);
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
// CHECK AND CREATE TABLE IF NOT EXISTS
// ============================================
try {
    $checkTable = $db->query("SHOW TABLES LIKE 'numeric_trade_receipts'");
    if ($checkTable->rowCount() == 0) {
        $createTable = "
            CREATE TABLE numeric_trade_receipts (
                id INT AUTO_INCREMENT PRIMARY KEY,
                trade_id INT NOT NULL,
                trade_type VARCHAR(50) DEFAULT 'trade',
                payment_receipt TEXT,
                is_approved TINYINT DEFAULT 0,
                approved_by VARCHAR(100),
                approved_at DATETIME,
                approval_comment TEXT,
                uploaded_by VARCHAR(100),
                created_at DATETIME,
                updated_at DATETIME,
                INDEX idx_trade_id (trade_id),
                INDEX idx_trade_type (trade_type)
            )
        ";
        $db->exec($createTable);
        error_log("Created numeric_trade_receipts table");
    }
} catch (Exception $e) {
    error_log("Table setup error: " . $e->getMessage());
}

// ============================================
// HANDLE ACTIONS
// ============================================

// Handle Receipt Upload - FIXED
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
    
    // Debug logging
    error_log("=== Numeric Upload Debug ===");
    error_log("POST: " . print_r($_POST, true));
    error_log("FILES: " . print_r($_FILES, true));
    
    // Get existing receipts
    $stmt = $db->prepare("SELECT payment_receipt FROM numeric_trade_receipts WHERE trade_id = ? AND trade_type = 'trade'");
    $stmt->execute([$trade_id]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);
    $existing_receipts = !empty($existing['payment_receipt']) ? explode(',', $existing['payment_receipt']) : [];
    
    // Check if files were uploaded
    if (isset($_FILES['payment_receipts']) && !empty($_FILES['payment_receipts']['name'][0])) {
        $files = $_FILES['payment_receipts'];
        $total_files = count($files['name']);
        
        for ($i = 0; $i < $total_files; $i++) {
            if ($files['error'][$i] !== UPLOAD_ERR_OK) {
                $errors[] = "File '{$files['name'][$i]}' upload error: " . $files['error'][$i];
                error_log("Upload error for {$files['name'][$i]}: " . $files['error'][$i]);
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
                error_log("Successfully uploaded: $filename");
            } else {
                $errors[] = "Failed to upload file '{$files['name'][$i]}'";
                error_log("Failed to move uploaded file: {$files['tmp_name'][$i]} to $filepath");
            }
        }
        
        if (!empty($uploaded_files)) {
            $all_receipts = array_merge($existing_receipts, $uploaded_files);
            $receipts_str = implode(',', $all_receipts);
            
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
                error_log("Database update failed for trade_id: $trade_id");
            }
        } else {
            $_SESSION['alert'] = ['No files were uploaded successfully. Errors: ' . implode('; ', $errors), 'danger'];
        }
    } else {
        $_SESSION['alert'] = ['No files selected for upload', 'danger'];
        error_log("No files in FILES array or empty name");
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
    
    $sql .= " AND t.additional_reference REGEXP '^[0-9]+$'";
    $sql .= " AND t.additional_reference IS NOT NULL";
    $sql .= " AND t.additional_reference != ''";
    
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
    
    if ($filter === 'pending') {
        $sql .= " AND (tr.is_approved IS NULL OR tr.is_approved = 0)";
    } elseif ($filter === 'approved') {
        $sql .= " AND tr.is_approved = 1";
    } elseif ($filter === 'rejected') {
        $sql .= " AND tr.is_approved = 2";
    }
    
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

$page_title = 'Numeric Reference Receipt Upload';
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
    
    /* Drop Zone */
    .drop-zone {
        border: 2px dashed #dee2e6;
        border-radius: 8px;
        padding: 30px;
        text-align: center;
        transition: border-color 0.3s, background-color 0.3s;
        cursor: pointer;
        margin-bottom: 15px;
    }
    .drop-zone:hover {
        border-color: #0d6efd;
        background-color: #f8f9fa;
    }
    .drop-zone.dragover {
        border-color: #0d6efd;
        background-color: #e7f1ff;
    }
    .drop-zone .icon {
        font-size: 40px;
        color: #6c757d;
        margin-bottom: 10px;
    }
    .drop-zone .text {
        color: #6c757d;
        font-size: 14px;
    }
    .drop-zone .text strong {
        color: #0d6efd;
    }
    
    /* File List */
    .file-list {
        margin-top: 10px;
        max-height: 200px;
        overflow-y: auto;
    }
    .file-item {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 8px 12px;
        background: #f8f9fa;
        border-radius: 4px;
        margin-bottom: 4px;
        font-size: 13px;
    }
    .file-item .file-info {
        display: flex;
        align-items: center;
        gap: 8px;
        flex: 1;
        min-width: 0;
    }
    .file-item .file-info .name {
        font-weight: 500;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        max-width: 200px;
    }
    .file-item .file-info .size {
        color: #6c757d;
        font-size: 12px;
        white-space: nowrap;
    }
    .file-item .remove-btn {
        color: #dc3545;
        cursor: pointer;
        font-size: 18px;
        background: none;
        border: none;
        padding: 0 4px;
        line-height: 1;
    }
    .file-item .remove-btn:hover {
        color: #a71d2a;
    }
    
    /* File Preview Thumbnails */
    .file-preview-thumbnails {
        display: flex;
        gap: 8px;
        flex-wrap: wrap;
        margin-top: 10px;
    }
    .file-preview-thumbnails .thumb {
        width: 70px;
        height: 70px;
        border: 2px solid #dee2e6;
        border-radius: 6px;
        overflow: hidden;
        display: flex;
        align-items: center;
        justify-content: center;
        background: #f8f9fa;
        position: relative;
    }
    .file-preview-thumbnails .thumb img {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }
    .file-preview-thumbnails .thumb .file-icon-big {
        font-size: 30px;
        color: #6c757d;
    }
    .file-preview-thumbnails .thumb .thumb-remove {
        position: absolute;
        top: -6px;
        right: -6px;
        background: #dc3545;
        color: white;
        border-radius: 50%;
        width: 20px;
        height: 20px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 12px;
        cursor: pointer;
        border: none;
        padding: 0;
    }
    .file-preview-thumbnails .thumb .thumb-remove:hover {
        background: #a71d2a;
    }
    
    #uploadBtn:disabled {
        opacity: 0.6;
        cursor: not-allowed;
    }
    
    .modal-lg {
        max-width: 700px;
    }
</style>

<div class="container-fluid">
    <!-- Page Header -->
    <div class="row mb-3">
        <div class="col-12">
            <h4>
                <i class="bi bi-receipt"></i> Numeric Reference Receipt Upload
                <small class="text-muted">(Additional Reference = Number only)</small>
            </h4>
            <p class="text-muted">
                <span class="badge bg-info">Bonds: All trades</span>
                <span class="badge bg-success">Equities/ETFs: BUY only</span>
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
                <div class="col-md-4">
                    <input type="text" class="form-control form-control-sm" name="search" placeholder="Search..." value="<?php echo htmlspecialchars($search); ?>">
                </div>
                <div class="col-md-2">
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
                                <th>Receipt</th>
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
                                <tr>
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
                                                <?php if ($isApproved !== 1): ?>
                                                    <a href="numeric_receipt_upload.php?delete_receipt=1&trade_id=<?php echo $trade['id']; ?>&file=<?php echo urlencode($receipts[0]); ?>&filter=<?php echo urlencode($filter); ?>&asset_class=<?php echo urlencode($asset_class_filter); ?>&search=<?php echo urlencode($search); ?>" 
                                                       class="text-danger small" onclick="return confirm('Delete this receipt?')">
                                                        <i class="bi bi-x-circle"></i>
                                                    </a>
                                                <?php endif; ?>
                                            </div>
                                        <?php else: ?>
                                            <button class="btn btn-outline-success btn-sm" onclick="openUploadModal(<?php echo $trade['id']; ?>)">
                                                <i class="bi bi-upload"></i> Upload
                                            </button>
                                        <?php endif; ?>
                                    </td>
                                    <td><span class="badge bg-<?php echo $statusClass; ?>"><?php echo $statusText; ?></span></td>
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
                                        <?php if ($isApproved !== 1): ?>
                                            <button class="btn btn-outline-success btn-sm" onclick="openUploadModal(<?php echo $trade['id']; ?>)" title="Upload Receipt">
                                                <i class="bi bi-upload"></i>
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

<!-- Upload Modal - COMPLETELY FIXED -->
<div class="modal fade" id="uploadModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-upload"></i> Upload Receipt(s)</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" enctype="multipart/form-data" action="numeric_receipt_upload.php" id="uploadForm">
                <div class="modal-body">
                    <input type="hidden" name="trade_id" id="upload_trade_id" value="">
                    <input type="hidden" name="upload_receipt" value="1">
                    <input type="hidden" name="filter" value="<?php echo htmlspecialchars($filter); ?>">
                    <input type="hidden" name="asset_class" value="<?php echo htmlspecialchars($asset_class_filter); ?>">
                    <input type="hidden" name="search" value="<?php echo htmlspecialchars($search); ?>">
                    
                    <!-- Drop Zone -->
                    <div class="drop-zone" id="dropZone">
                        <div class="icon">
                            <i class="bi bi-cloud-arrow-up"></i>
                        </div>
                        <div class="text">
                            <strong>Click to browse</strong> or drag & drop files here
                            <br><small class="text-muted">Supports: JPG, PNG, GIF, PDF (Max 5MB each)</small>
                        </div>
                        <input type="file" class="form-control" name="payment_receipts[]" id="fileInput" accept="image/*,.pdf" multiple style="display: none;">
                    </div>
                    
                    <!-- File Preview Thumbnails -->
                    <div id="filePreviewThumbnails" class="file-preview-thumbnails"></div>
                    
                    <!-- Selected Files List -->
                    <div class="file-list" id="fileList">
                        <p class="text-muted small">No files selected</p>
                    </div>
                    
                    <div class="mt-2">
                        <button type="button" class="btn btn-outline-secondary btn-sm" onclick="document.getElementById('fileInput').click()">
                            <i class="bi bi-plus-circle"></i> Add More Files
                        </button>
                        <button type="button" class="btn btn-outline-danger btn-sm" onclick="clearSelectedFiles()">
                            <i class="bi bi-x-circle"></i> Clear All
                        </button>
                    </div>
                    
                    <div class="alert alert-info mt-3">
                        <i class="bi bi-info-circle"></i> Upload clear copies of payment receipts or confirmations. Multiple files allowed.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success" id="uploadBtn" disabled>
                        <i class="bi bi-upload"></i> Upload <span id="fileCount">0</span> Files
                    </button>
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
            <form method="POST" action="numeric_receipt_upload.php">
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
// ============================================
// FILE UPLOAD HANDLING - COMPLETELY FIXED
// ============================================

let selectedFiles = [];

// File input change handler
document.getElementById('fileInput').addEventListener('change', function(e) {
    console.log('File input changed:', e.target.files);
    const files = Array.from(e.target.files);
    
    // Clear existing selection and add new files
    selectedFiles = [];
    files.forEach(file => {
        selectedFiles.push(file);
    });
    
    console.log('Selected files:', selectedFiles);
    updateFileList();
});

// Drag and drop
const dropZone = document.getElementById('dropZone');

dropZone.addEventListener('dragover', function(e) {
    e.preventDefault();
    this.classList.add('dragover');
});

dropZone.addEventListener('dragleave', function(e) {
    e.preventDefault();
    this.classList.remove('dragover');
});

dropZone.addEventListener('drop', function(e) {
    e.preventDefault();
    this.classList.remove('dragover');
    
    const files = Array.from(e.dataTransfer.files);
    selectedFiles = [];
    files.forEach(file => {
        selectedFiles.push(file);
    });
    
    console.log('Dropped files:', selectedFiles);
    updateFileList();
    
    // Update the file input with dropped files
    const dataTransfer = new DataTransfer();
    selectedFiles.forEach(file => dataTransfer.items.add(file));
    document.getElementById('fileInput').files = dataTransfer.files;
});

dropZone.addEventListener('click', function() {
    document.getElementById('fileInput').click();
});

// Update file list display with thumbnails
function updateFileList() {
    const container = document.getElementById('fileList');
    const fileCount = document.getElementById('fileCount');
    const uploadBtn = document.getElementById('uploadBtn');
    const thumbnailsContainer = document.getElementById('filePreviewThumbnails');
    
    // Clear thumbnails
    thumbnailsContainer.innerHTML = '';
    
    console.log('Updating file list. Selected files count:', selectedFiles.length);
    
    if (selectedFiles.length === 0) {
        container.innerHTML = '<p class="text-muted small">No files selected</p>';
        fileCount.textContent = '0';
        uploadBtn.disabled = true;
        uploadBtn.innerHTML = '<i class="bi bi-upload"></i> Upload 0 Files';
        return;
    }
    
    // Build file list
    let html = '';
    selectedFiles.forEach((file, index) => {
        const size = (file.size / 1024 / 1024).toFixed(2);
        const icon = file.type.startsWith('image/') ? 'bi-file-image' :
                    file.type === 'application/pdf' ? 'bi-file-pdf' :
                    file.type.includes('word') ? 'bi-file-word' :
                    file.type.includes('excel') ? 'bi-file-excel' : 'bi-file';
        html += `
            <div class="file-item">
                <div class="file-info">
                    <i class="bi ${icon}"></i>
                    <span class="name" title="${file.name}">${file.name}</span>
                    <span class="size">(${size} MB)</span>
                </div>
                <button type="button" class="remove-btn" onclick="removeFile(${index})" title="Remove file">
                    <i class="bi bi-x-circle"></i>
                </button>
            </div>
        `;
    });
    container.innerHTML = html;
    
    // Build thumbnails for images
    selectedFiles.forEach((file, index) => {
        if (file.type.startsWith('image/')) {
            const reader = new FileReader();
            reader.onload = function(e) {
                const thumb = document.createElement('div');
                thumb.className = 'thumb';
                thumb.innerHTML = `
                    <img src="${e.target.result}" alt="${file.name}">
                    <button type="button" class="thumb-remove" onclick="removeFile(${index})" title="Remove">×</button>
                `;
                thumbnailsContainer.appendChild(thumb);
            };
            reader.readAsDataURL(file);
        } else {
            const icon = file.type === 'application/pdf' ? 'bi-file-pdf' : 'bi-file';
            const thumb = document.createElement('div');
            thumb.className = 'thumb';
            thumb.innerHTML = `
                <div class="file-icon-big"><i class="bi ${icon}"></i></div>
                <button type="button" class="thumb-remove" onclick="removeFile(${index})" title="Remove">×</button>
            `;
            thumbnailsContainer.appendChild(thumb);
        }
    });
    
    fileCount.textContent = selectedFiles.length;
    uploadBtn.disabled = false;
    uploadBtn.innerHTML = `<i class="bi bi-upload"></i> Upload ${selectedFiles.length} Files`;
}

// Remove a file from selection
function removeFile(index) {
    selectedFiles.splice(index, 1);
    console.log('Removed file, remaining:', selectedFiles);
    updateFileList();
    
    // Update the file input with remaining files
    const dataTransfer = new DataTransfer();
    selectedFiles.forEach(file => dataTransfer.items.add(file));
    document.getElementById('fileInput').files = dataTransfer.files;
}

// Clear all selected files
function clearSelectedFiles() {
    selectedFiles = [];
    console.log('Cleared all files');
    updateFileList();
    document.getElementById('fileInput').value = '';
    document.getElementById('filePreviewThumbnails').innerHTML = '';
}

// ============================================
// MODAL FUNCTIONS
// ============================================

function openUploadModal(tradeId) {
    console.log('Opening upload modal for trade:', tradeId);
    // Reset file selection
    selectedFiles = [];
    updateFileList();
    document.getElementById('fileInput').value = '';
    document.getElementById('filePreviewThumbnails').innerHTML = '';
    
    document.getElementById('upload_trade_id').value = tradeId;
    const modal = new bootstrap.Modal(document.getElementById('uploadModal'));
    modal.show();
}

function openCommentModal(tradeId) {
    document.getElementById('comment_trade_id').value = tradeId;
    const modal = new bootstrap.Modal(document.getElementById('commentModal'));
    modal.show();
}

// ============================================
// FORM SUBMISSION - COMPLETELY FIXED
// ============================================

document.getElementById('uploadForm').addEventListener('submit', function(e) {
    console.log('Form submitting. Selected files:', selectedFiles);
    
    // If no files selected, prevent submission
    if (selectedFiles.length === 0) {
        e.preventDefault();
        alert('Please select at least one file to upload.');
        return false;
    }
    
    // CRITICAL FIX: Ensure the file input has the files
    const fileInput = document.getElementById('fileInput');
    const dataTransfer = new DataTransfer();
    selectedFiles.forEach(file => dataTransfer.items.add(file));
    fileInput.files = dataTransfer.files;
    
    console.log('File input now has:', fileInput.files.length, 'files');
    
    // Let the form submit normally
    return true;
});

// ============================================
// INITIALIZATION
// ============================================

// Auto-refresh alerts after 5 seconds
document.querySelectorAll('.alert').forEach(alert => {
    setTimeout(() => {
        const bsAlert = bootstrap.Alert.getOrCreateInstance(alert);
        if (bsAlert) bsAlert.close();
    }, 5000);
});

console.log('Numeric Receipt Upload System initialized successfully.');
console.log('Selected files:', selectedFiles.length);
</script>

<?php include '../includes/footer.php'; ?>
