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
                comment TEXT,
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
    
    // Add comment column if it doesn't exist
    $checkColumn = $db->query("SHOW COLUMNS FROM numeric_trade_receipts LIKE 'comment'");
    if ($checkColumn->rowCount() == 0) {
        $db->exec("ALTER TABLE numeric_trade_receipts ADD COLUMN comment TEXT AFTER payment_receipt");
        error_log("Added comment column to numeric_trade_receipts");
    }
} catch (Exception $e) {
    error_log("Table setup error: " . $e->getMessage());
}

// ============================================
// HANDLE ACTIONS
// ============================================

// Handle Receipt Upload with Comment
if (isset($_POST['upload_receipt']) && isset($_POST['trade_id'])) {
    $trade_id = (int) $_POST['trade_id'];
    $uploaded_files = [];
    $errors = [];
    $comment = trim($_POST['receipt_comment'] ?? '');
    
    $allowed_exts = ['jpg', 'jpeg', 'png', 'gif', 'pdf'];
    $max_size = 5 * 1024 * 1024;
    
    $upload_dir = __DIR__ . '/../uploads/numeric_receipts/';
    if (!file_exists($upload_dir)) {
        mkdir($upload_dir, 0777, true);
    }
    
    // Get existing receipts and comment
    $stmt = $db->prepare("SELECT payment_receipt, comment FROM numeric_trade_receipts WHERE trade_id = ? AND trade_type = 'trade'");
    $stmt->execute([$trade_id]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);
    $existing_receipts = !empty($existing['payment_receipt']) ? explode(',', $existing['payment_receipt']) : [];
    $existing_comment = $existing['comment'] ?? '';
    
    // Handle file uploads
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
    }
    
    // Combine comment
    $full_comment = $existing_comment;
    if (!empty($comment)) {
        $timestamp = date('Y-m-d H:i:s');
        $full_comment = $existing_comment 
            ? $existing_comment . "\n---\n[" . $timestamp . "] " . $user_name . ": " . $comment 
            : "[" . $timestamp . "] " . $user_name . ": " . $comment;
    }
    
    // Update database
    $receipts_str = !empty($uploaded_files) ? implode(',', array_merge($existing_receipts, $uploaded_files)) : $existing['payment_receipt'] ?? null;
    
    $stmt = $db->prepare("SELECT id FROM numeric_trade_receipts WHERE trade_id = ? AND trade_type = 'trade'");
    $stmt->execute([$trade_id]);
    if ($stmt->fetch()) {
        $stmt = $db->prepare("UPDATE numeric_trade_receipts SET payment_receipt = ?, comment = ?, updated_at = NOW(), uploaded_by = ? WHERE trade_id = ? AND trade_type = 'trade'");
        $result = $stmt->execute([$receipts_str, $full_comment, $user_name, $trade_id]);
    } else {
        $stmt = $db->prepare("INSERT INTO numeric_trade_receipts (trade_id, trade_type, payment_receipt, comment, uploaded_by, created_at, updated_at) VALUES (?, 'trade', ?, ?, ?, NOW(), NOW())");
        $result = $stmt->execute([$trade_id, $receipts_str, $full_comment, $user_name]);
    }
    
    if ($result) {
        $message = [];
        if (!empty($uploaded_files)) $message[] = count($uploaded_files) . ' file(s) uploaded';
        if (!empty($comment)) $message[] = 'comment added';
        $_SESSION['alert'] = [implode(' and ', $message) . ' successfully!', 'success'];
    } else {
        $_SESSION['alert'] = ['Failed to update database', 'danger'];
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

// Handle Comment Delete
if (isset($_GET['delete_comment']) && isset($_GET['trade_id'])) {
    $trade_id = (int) $_GET['trade_id'];
    
    $stmt = $db->prepare("UPDATE numeric_trade_receipts SET comment = NULL, updated_at = NOW() WHERE trade_id = ? AND trade_type = 'trade'");
    if ($stmt->execute([$trade_id])) {
        $_SESSION['alert'] = ['Comment deleted successfully.', 'success'];
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
// FETCH TRADES
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
            ANY_VALUE(tr.comment) as receipt_comment,
            ANY_VALUE(tr.is_approved) as is_approved,
            ANY_VALUE(tr.approved_by) as approved_by,
            ANY_VALUE(tr.approved_at) as approved_at,
            ANY_VALUE(tr.approval_comment) as approval_comment,
            ANY_VALUE(tr.uploaded_by) as receipt_uploaded_by,
            ANY_VALUE(tr.created_at) as receipt_created_at,
            ANY_VALUE(tr.updated_at) as receipt_updated_at
        FROM trades t
        LEFT JOIN numeric_trade_receipts tr ON t.id = tr.trade_id AND tr.trade_type = 'trade'
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
    /* Simple clean styles - like dealing_sheet.php */
    .receipt-thumbnails {
        display: flex;
        gap: 5px;
        flex-wrap: wrap;
        align-items: center;
    }
    .receipt-thumbnails .thumbnail {
        position: relative;
        width: 50px;
        height: 50px;
        border: 2px solid #e9ecef;
        border-radius: 6px;
        overflow: hidden;
        cursor: pointer;
        transition: border-color 0.2s;
    }
    .receipt-thumbnails .thumbnail:hover {
        border-color: #0d6efd;
    }
    .receipt-thumbnails .thumbnail img {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }
    .receipt-thumbnails .thumbnail .file-icon {
        width: 100%;
        height: 100%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 24px;
        background: #f8f9fa;
        color: #6c757d;
    }
    .receipt-thumbnails .delete-btn {
        position: absolute;
        top: -6px;
        right: -6px;
        background: #dc3545;
        color: white;
        border-radius: 50%;
        width: 18px;
        height: 18px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 12px;
        text-decoration: none;
        box-shadow: 0 2px 4px rgba(0,0,0,0.2);
        transition: transform 0.2s;
    }
    .receipt-thumbnails .delete-btn:hover {
        transform: scale(1.1);
        color: white;
    }
    .receipt-thumbnails .more-badge {
        background: #0d6efd;
        color: white;
        border-radius: 50%;
        width: 30px;
        height: 30px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 12px;
        font-weight: bold;
    }
    
    .comment-display {
        background: #f8f9fa;
        border-left: 3px solid #0d6efd;
        padding: 6px 10px;
        border-radius: 4px;
        font-size: 12px;
        max-width: 200px;
        margin-top: 4px;
    }
    .comment-display .comment-text {
        white-space: pre-wrap;
        word-wrap: break-word;
        max-height: 60px;
        overflow-y: auto;
    }
    .comment-actions .btn-sm {
        font-size: 10px;
        padding: 1px 6px;
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
    
    .stat-card {
        transition: transform 0.2s, box-shadow 0.2s;
    }
    .stat-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(0,0,0,0.1);
    }
    .stat-card .stat-number {
        font-size: 24px;
        font-weight: bold;
    }
    .stat-card .stat-label {
        font-size: 13px;
        color: #6c757d;
    }
    
    .filter-section {
        background: #f8f9fa;
        padding: 15px;
        border-radius: 6px;
        margin-bottom: 20px;
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
</style>

<div class="container-fluid">
    <!-- Page Header -->
    <div class="row mb-3">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h4>
                        <i class="bi bi-receipt"></i> Numeric Reference Receipt Upload
                        <small class="text-muted">(Additional Reference = Number only)</small>
                    </h4>
                </div>
            </div>
        </div>
    </div>

    <!-- Alert Messages -->
    <?php if (isset($_SESSION['alert'])): ?>
        <div class="alert alert-<?php echo $_SESSION['alert'][1]; ?> alert-dismissible fade show">
            <i class="bi <?php echo $_SESSION['alert'][1] === 'success' ? 'bi-check-circle' : 'bi-exclamation-triangle'; ?>"></i>
            <?php echo htmlspecialchars($_SESSION['alert'][0]); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php unset($_SESSION['alert']); ?>
    <?php endif; ?>

    <?php if (!empty($error_message)): ?>
        <div class="alert alert-danger"><?php echo htmlspecialchars($error_message); ?></div>
    <?php endif; ?>

    <!-- Stats -->
    <div class="row mb-3 g-2">
        <div class="col-md-2 col-6">
            <div class="card stat-card border-primary">
                <div class="card-body text-center">
                    <div class="stat-number text-primary"><?php echo number_format($stats['total']); ?></div>
                    <div class="stat-label">Total</div>
                </div>
            </div>
        </div>
        <div class="col-md-2 col-6">
            <div class="card stat-card border-warning">
                <div class="card-body text-center">
                    <div class="stat-number text-warning"><?php echo number_format($stats['pending']); ?></div>
                    <div class="stat-label">Pending</div>
                </div>
            </div>
        </div>
        <div class="col-md-2 col-6">
            <div class="card stat-card border-success">
                <div class="card-body text-center">
                    <div class="stat-number text-success"><?php echo number_format($stats['approved']); ?></div>
                    <div class="stat-label">Approved</div>
                </div>
            </div>
        </div>
        <div class="col-md-2 col-6">
            <div class="card stat-card border-danger">
                <div class="card-body text-center">
                    <div class="stat-number text-danger"><?php echo number_format($stats['rejected']); ?></div>
                    <div class="stat-label">Rejected</div>
                </div>
            </div>
        </div>
        <div class="col-md-4 col-12">
            <div class="card stat-card">
                <div class="card-body text-center">
                    <div>
                        <span class="badge bg-secondary">B: <?php echo $stats['bond']; ?></span>
                        <span class="badge bg-secondary">E: <?php echo $stats['equity']; ?></span>
                        <span class="badge bg-secondary">F: <?php echo $stats['etf']; ?></span>
                    </div>
                    <div class="stat-label">Breakdown</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <div class="filter-section">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-3">
                <label class="form-label">Status</label>
                <select class="form-select form-select-sm" name="filter" onchange="this.form.submit()">
                    <option value="pending" <?php echo $filter === 'pending' ? 'selected' : ''; ?>>Pending</option>
                    <option value="approved" <?php echo $filter === 'approved' ? 'selected' : ''; ?>>Approved</option>
                    <option value="rejected" <?php echo $filter === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                    <option value="all" <?php echo $filter === 'all' ? 'selected' : ''; ?>>All</option>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">Asset Class</label>
                <select class="form-select form-select-sm" name="asset_class" onchange="this.form.submit()">
                    <option value="all" <?php echo $asset_class_filter === 'all' ? 'selected' : ''; ?>>All Assets</option>
                    <option value="bond" <?php echo $asset_class_filter === 'bond' ? 'selected' : ''; ?>>Bond</option>
                    <option value="equity" <?php echo $asset_class_filter === 'equity' ? 'selected' : ''; ?>>Equity</option>
                    <option value="etf" <?php echo $asset_class_filter === 'etf' ? 'selected' : ''; ?>>ETF</option>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label">Search</label>
                <input type="text" class="form-control form-control-sm" name="search" placeholder="Client, Security, Reference..." value="<?php echo htmlspecialchars($search); ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label">&nbsp;</label>
                <button type="submit" class="btn btn-secondary btn-sm w-100">Apply</button>
            </div>
        </form>
    </div>

    <!-- Trades Table -->
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h6 class="mb-0">
                <i class="bi bi-table"></i> Numeric Reference Trades 
                <span class="badge bg-secondary ms-2"><?php echo count($trades); ?></span>
            </h6>
        </div>
        <div class="card-body p-0">
            <?php if (empty($trades)): ?>
                <div class="text-center py-5">
                    <i class="bi bi-inbox" style="font-size: 48px; color: #dee2e6;"></i>
                    <p class="text-muted mt-3">No trades found with numeric Additional Reference.</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm table-hover mb-0 table-condensed">
                        <thead class="table-light">
                            <tr>
                                <th>Ref</th>
                                <th>Client</th>
                                <th>Security</th>
                                <th>Asset</th>
                                <th>Side</th>
                                <th class="text-end">Qty</th>
                                <th class="text-end">Value</th>
                                <th>Date</th>
                                <th>Add Ref</th>
                                <th>Files / Comments</th>
                                <th>Status</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($trades as $trade): 
                                $isBond = ($trade['asset_class'] === 'bond');
                                $receipts = !empty($trade['payment_receipt']) ? explode(',', $trade['payment_receipt']) : [];
                                $hasReceipt = !empty($receipts) && !empty($receipts[0]);
                                $hasComment = !empty($trade['receipt_comment']);
                                $commentText = $trade['receipt_comment'] ?? '';
                                
                                $isApproved = isset($trade['is_approved']) ? (int)$trade['is_approved'] : 0;
                                $statusText = $isApproved === 1 ? 'Approved' : ($isApproved === 2 ? 'Rejected' : 'Pending');
                                $statusClass = $isApproved === 1 ? 'success' : ($isApproved === 2 ? 'danger' : 'warning');
                                
                                if ($isBond) {
                                    $displayQty = 'TZS ' . number_format(floatval($trade['quantity'] ?? 0), 2);
                                    $displayValue = 'TZS ' . number_format(floatval($trade['consideration'] ?? 0), 2);
                                    $assetClass = 'Bond';
                                } else {
                                    $displayQty = number_format(floatval($trade['quantity'] ?? 0), 0);
                                    $displayValue = 'TZS ' . number_format(floatval($trade['consideration'] ?? 0), 2);
                                    $assetClass = ucfirst($trade['asset_class'] ?? 'Equity');
                                    if ($assetClass === 'Exchange Traded Funds') $assetClass = 'ETF';
                                }
                            ?>
                                <tr>
                                    <td><span class="fw-semibold small"><?php echo htmlspecialchars($trade['trade_reference'] ?? ''); ?></span></td>
                                    <td><?php echo htmlspecialchars($trade['client_name'] ?? ''); ?></td>
                                    <td><?php echo htmlspecialchars($trade['security_id'] ?? ''); ?></td>
                                    <td><span class="badge bg-secondary"><?php echo $assetClass; ?></span></td>
                                    <td>
                                        <span class="badge <?php echo strtolower($trade['trade_side'] ?? '') === 'buy' ? 'bg-success' : 'bg-danger'; ?>">
                                            <?php echo strtoupper($trade['trade_side'] ?? ''); ?>
                                        </span>
                                    </td>
                                    <td class="text-end"><?php echo $displayQty; ?></td>
                                    <td class="text-end fw-bold"><?php echo $displayValue; ?></td>
                                    <td><?php echo date('d/m/Y', strtotime($trade['trade_date'] ?? '')); ?></td>
                                    <td><code class="small"><?php echo htmlspecialchars($trade['additional_reference'] ?? ''); ?></code></td>
                                    <td>
                                        <!-- Receipt Thumbnails -->
                                        <?php if ($hasReceipt): ?>
                                            <div class="receipt-thumbnails">
                                                <?php 
                                                $displayCount = 0;
                                                foreach ($receipts as $receiptFile):
                                                    $receiptFile = trim($receiptFile);
                                                    if (empty($receiptFile)) continue;
                                                    if ($displayCount >= 3) break;
                                                    $displayCount++;
                                                    $filepath = '../uploads/numeric_receipts/' . $receiptFile;
                                                    $ext = strtolower(pathinfo($receiptFile, PATHINFO_EXTENSION));
                                                    $isImage = in_array($ext, ['jpg', 'jpeg', 'png', 'gif']);
                                                ?>
                                                    <div class="thumbnail" onclick="window.open('<?php echo $filepath; ?>', '_blank')" title="Click to view">
                                                        <?php if ($isImage && file_exists($filepath)): ?>
                                                            <img src="<?php echo $filepath; ?>" alt="Receipt">
                                                        <?php else: ?>
                                                            <div class="file-icon">
                                                                <i class="bi bi-file-pdf"></i>
                                                            </div>
                                                        <?php endif; ?>
                                                        <?php if ($isApproved !== 1): ?>
                                                            <a href="numeric_receipt_upload.php?delete_receipt=1&trade_id=<?php echo $trade['id']; ?>&file=<?php echo urlencode($receiptFile); ?>&filter=<?php echo urlencode($filter); ?>&asset_class=<?php echo urlencode($asset_class_filter); ?>&search=<?php echo urlencode($search); ?>" 
                                                               class="delete-btn" onclick="event.stopPropagation(); return confirm('Delete this file?')">
                                                                <i class="bi bi-x"></i>
                                                            </a>
                                                        <?php endif; ?>
                                                    </div>
                                                <?php endforeach; ?>
                                                <?php if (count($receipts) > 3): ?>
                                                    <div class="more-badge">+<?php echo count($receipts) - 3; ?></div>
                                                <?php endif; ?>
                                            </div>
                                        <?php else: ?>
                                            <span class="text-muted small">No files</span>
                                        <?php endif; ?>
                                        
                                        <!-- Comment Display -->
                                        <?php if ($hasComment): ?>
                                            <div class="comment-display">
                                                <div class="comment-text"><?php echo nl2br(htmlspecialchars($commentText)); ?></div>
                                            </div>
                                            <div class="comment-actions">
                                                <a href="numeric_receipt_upload.php?delete_comment=1&trade_id=<?php echo $trade['id']; ?>&filter=<?php echo urlencode($filter); ?>&asset_class=<?php echo urlencode($asset_class_filter); ?>&search=<?php echo urlencode($search); ?>" 
                                                   class="text-danger small" onclick="return confirm('Delete this comment?')">
                                                    <i class="bi bi-trash"></i> Delete
                                                </a>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td><span class="badge bg-<?php echo $statusClass; ?>"><?php echo $statusText; ?></span></td>
                                    <td class="text-end">
                                        <div class="btn-group btn-group-sm" role="group">
                                            <?php if ($isApproved !== 1): ?>
                                                <button class="btn btn-outline-primary" onclick="openUploadModal(<?php echo $trade['id']; ?>)" title="Upload Files / Add Comment">
                                                    <i class="bi bi-upload"></i>
                                                </button>
                                            <?php endif; ?>
                                            <button class="btn btn-outline-info" onclick="viewTrade(<?php echo $trade['id']; ?>)" title="View Details">
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

<!-- ============================================ -->
<!-- UPLOAD MODAL - Combined Upload + Comment -->
<!-- ============================================ -->
<div class="modal fade" id="uploadModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="bi bi-upload"></i> Upload Receipt & Add Comment
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" enctype="multipart/form-data" action="numeric_receipt_upload.php" id="uploadForm">
                <div class="modal-body">
                    <input type="hidden" name="trade_id" id="upload_trade_id" value="">
                    <input type="hidden" name="upload_receipt" value="1">
                    <input type="hidden" name="filter" value="<?php echo htmlspecialchars($filter); ?>">
                    <input type="hidden" name="asset_class" value="<?php echo htmlspecialchars($asset_class_filter); ?>">
                    <input type="hidden" name="search" value="<?php echo htmlspecialchars($search); ?>">
                    
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle"></i> 
                        <strong>Trade ID:</strong> <span id="tradeIdDisplay">-</span>
                    </div>
                    
                    <!-- File Upload Area -->
                    <div class="mb-3">
                        <label class="form-label fw-bold">Select Files</label>
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
                    
                    <!-- Comment Section -->
                    <div class="mt-3">
                        <label class="form-label fw-bold">Add Comment (Optional)</label>
                        <textarea class="form-control" name="receipt_comment" id="receiptComment" rows="3" placeholder="Add a comment about this trade..."></textarea>
                    </div>
                    
                    <div class="alert alert-info mt-3">
                        <i class="bi bi-info-circle"></i> Upload clear copies of payment receipts or confirmations. Multiple files allowed.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="uploadBtn" disabled>
                        <i class="bi bi-upload"></i> Upload <span id="fileCount">0</span> Files
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- View Trade Modal -->
<div class="modal fade" id="viewTradeModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="bi bi-info-circle"></i> Trade Details
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="tradeDetails">
                <div class="text-center py-3">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                    <p class="mt-2">Loading trade details...</p>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// ============================================
// FILE UPLOAD HANDLING
// ============================================

let selectedFiles = [];

// File input change handler
document.getElementById('fileInput').addEventListener('change', function(e) {
    const files = Array.from(e.target.files);
    files.forEach(file => {
        if (!selectedFiles.some(f => f.name === file.name && f.size === file.size)) {
            selectedFiles.push(file);
        }
    });
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
    files.forEach(file => {
        if (!selectedFiles.some(f => f.name === file.name && f.size === file.size)) {
            selectedFiles.push(file);
        }
    });
    updateFileList();
    
    const dataTransfer = new DataTransfer();
    selectedFiles.forEach(file => dataTransfer.items.add(file));
    document.getElementById('fileInput').files = dataTransfer.files;
});

dropZone.addEventListener('click', function() {
    document.getElementById('fileInput').click();
});

// Update file list display
function updateFileList() {
    const container = document.getElementById('fileList');
    const fileCount = document.getElementById('fileCount');
    const uploadBtn = document.getElementById('uploadBtn');
    const thumbnailsContainer = document.getElementById('filePreviewThumbnails');
    
    thumbnailsContainer.innerHTML = '';
    
    if (selectedFiles.length === 0) {
        container.innerHTML = '<p class="text-muted small">No files selected</p>';
        fileCount.textContent = '0';
        uploadBtn.disabled = true;
        uploadBtn.innerHTML = '<i class="bi bi-upload"></i> Upload 0 Files';
        return;
    }
    
    let html = '';
    selectedFiles.forEach((file, index) => {
        const size = (file.size / 1024 / 1024).toFixed(2);
        const icon = file.type.startsWith('image/') ? 'bi-file-image' :
                    file.type === 'application/pdf' ? 'bi-file-pdf' : 'bi-file';
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
    updateFileList();
    
    const dataTransfer = new DataTransfer();
    selectedFiles.forEach(file => dataTransfer.items.add(file));
    document.getElementById('fileInput').files = dataTransfer.files;
}

// Clear all selected files
function clearSelectedFiles() {
    selectedFiles = [];
    updateFileList();
    document.getElementById('fileInput').value = '';
    document.getElementById('filePreviewThumbnails').innerHTML = '';
}

// ============================================
// MODAL FUNCTIONS
// ============================================

function openUploadModal(tradeId) {
    selectedFiles = [];
    updateFileList();
    document.getElementById('fileInput').value = '';
    document.getElementById('filePreviewThumbnails').innerHTML = '';
    document.getElementById('receiptComment').value = '';
    
    document.getElementById('upload_trade_id').value = tradeId;
    document.getElementById('tradeIdDisplay').textContent = tradeId === 0 ? 'New Trade' : tradeId;
    
    const modal = new bootstrap.Modal(document.getElementById('uploadModal'));
    modal.show();
}

function viewTrade(tradeId) {
    const modal = new bootstrap.Modal(document.getElementById('viewTradeModal'));
    const container = document.getElementById('tradeDetails');
    container.innerHTML = `
        <div class="text-center py-3">
            <div class="spinner-border text-primary" role="status">
                <span class="visually-hidden">Loading...</span>
            </div>
            <p class="mt-2">Loading trade details...</p>
        </div>
    `;
    modal.show();
    
    setTimeout(() => {
        container.innerHTML = `
            <div class="row g-3">
                <div class="col-md-6">
                    <div class="border-bottom pb-2 mb-2">
                        <small class="text-muted">Trade Reference</small>
                        <div class="fw-bold">TRD-${String(tradeId).padStart(6, '0')}</div>
                    </div>
                    <div class="border-bottom pb-2 mb-2">
                        <small class="text-muted">Client</small>
                        <div>Sample Client Ltd</div>
                    </div>
                    <div class="border-bottom pb-2 mb-2">
                        <small class="text-muted">Security</small>
                        <div>SEC-001 (Sample Security)</div>
                    </div>
                    <div class="border-bottom pb-2 mb-2">
                        <small class="text-muted">Trade Date</small>
                        <div>${new Date().toLocaleDateString()}</div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="border-bottom pb-2 mb-2">
                        <small class="text-muted">Trade Side</small>
                        <div><span class="badge bg-success">BUY</span></div>
                    </div>
                    <div class="border-bottom pb-2 mb-2">
                        <small class="text-muted">Quantity</small>
                        <div>1,000</div>
                    </div>
                    <div class="border-bottom pb-2 mb-2">
                        <small class="text-muted">Consideration</small>
                        <div class="fw-bold">TZS 1,500,000.00</div>
                    </div>
                    <div class="border-bottom pb-2 mb-2">
                        <small class="text-muted">Status</small>
                        <div><span class="badge bg-warning">Pending</span></div>
                    </div>
                </div>
                <div class="col-12">
                    <hr>
                    <small class="text-muted">Additional Reference</small>
                    <div><code>REF-${String(tradeId).padStart(4, '0')}</code></div>
                </div>
            </div>
        `;
    }, 500);
}

// ============================================
// FORM SUBMISSION - FIXED
// ============================================

document.getElementById('uploadForm').addEventListener('submit', function(e) {
    // Allow submission if files are selected OR comment is entered
    const comment = document.getElementById('receiptComment').value.trim();
    
    if (selectedFiles.length === 0 && !comment) {
        e.preventDefault();
        alert('Please select at least one file or add a comment.');
        return false;
    }
    
    // If files are selected, ensure the file input has them
    if (selectedFiles.length > 0) {
        const fileInput = document.getElementById('fileInput');
        const dataTransfer = new DataTransfer();
        selectedFiles.forEach(file => dataTransfer.items.add(file));
        fileInput.files = dataTransfer.files;
    }
    
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
</script>

<?php include '../includes/footer.php'; ?>
