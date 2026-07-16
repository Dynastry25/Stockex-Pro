<?php
// ============================================
// TRADE RECEIPT UPLOAD & COMMENT SYSTEM
// ============================================

error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/trade_upload_errors.log');

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
    // Check if trade_receipts table exists, if not create it
    $checkTable = $db->query("SHOW TABLES LIKE 'trade_receipts'");
    if ($checkTable->rowCount() == 0) {
        $createTable = "
            CREATE TABLE trade_receipts (
                id INT AUTO_INCREMENT PRIMARY KEY,
                trade_id INT NOT NULL,
                trade_type VARCHAR(50) DEFAULT 'trade',
                receipt_files TEXT,
                comment TEXT,
                uploaded_by VARCHAR(100),
                created_at DATETIME,
                updated_at DATETIME,
                INDEX idx_trade_id (trade_id),
                INDEX idx_trade_type (trade_type)
            )
        ";
        $db->exec($createTable);
        error_log("Created trade_receipts table");
    }
    
    // Check if comment column exists in trade_receipts, if not add it
    $checkColumn = $db->query("SHOW COLUMNS FROM trade_receipts LIKE 'comment'");
    if ($checkColumn->rowCount() == 0) {
        $db->exec("ALTER TABLE trade_receipts ADD COLUMN comment TEXT AFTER receipt_files");
        error_log("Added comment column to trade_receipts");
    }
    
    // Check if uploaded_by column exists, if not add it
    $checkUploadedBy = $db->query("SHOW COLUMNS FROM trade_receipts LIKE 'uploaded_by'");
    if ($checkUploadedBy->rowCount() == 0) {
        $db->exec("ALTER TABLE trade_receipts ADD COLUMN uploaded_by VARCHAR(100) AFTER comment");
        error_log("Added uploaded_by column to trade_receipts");
    }
} catch (Exception $e) {
    error_log("Table setup error: " . $e->getMessage());
}

// ============================================
// HANDLE FILE UPLOADS
// ============================================

// Handle Receipt Upload
if (isset($_POST['upload_receipt']) && isset($_POST['trade_id'])) {
    $trade_id = (int) $_POST['trade_id'];
    $uploaded_files = [];
    $errors = [];
    $comment = trim($_POST['receipt_comment'] ?? '');
    
    $allowed_exts = ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'doc', 'docx', 'xls', 'xlsx'];
    $max_size = 10 * 1024 * 1024; // 10MB
    
    $upload_dir = __DIR__ . '/../uploads/trade_receipts/';
    if (!file_exists($upload_dir)) {
        mkdir($upload_dir, 0777, true);
    }
    
    // Get existing receipts
    $stmt = $db->prepare("SELECT receipt_files, comment FROM trade_receipts WHERE trade_id = ? AND trade_type = 'trade'");
    $stmt->execute([$trade_id]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);
    $existing_receipts = !empty($existing['receipt_files']) ? explode(',', $existing['receipt_files']) : [];
    $existing_comment = $existing['comment'] ?? '';
    
    if (isset($_FILES['receipt_files']) && !empty($_FILES['receipt_files']['name'][0])) {
        $files = $_FILES['receipt_files'];
        $total_files = count($files['name']);
        
        for ($i = 0; $i < $total_files; $i++) {
            if ($files['error'][$i] !== UPLOAD_ERR_OK) {
                $errors[] = "File '{$files['name'][$i]}' upload error: " . $files['error'][$i];
                continue;
            }
            
            $file_ext = strtolower(pathinfo($files['name'][$i], PATHINFO_EXTENSION));
            if (!in_array($file_ext, $allowed_exts)) {
                $errors[] = "File '{$files['name'][$i]}' - Invalid type. Allowed: JPG, PNG, GIF, PDF, DOC, XLS";
                continue;
            }
            
            if ($files['size'][$i] > $max_size) {
                $errors[] = "File '{$files['name'][$i]}' exceeds 10MB limit";
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
            
            // Combine comments
            $full_comment = $existing_comment;
            if (!empty($comment)) {
                $timestamp = date('Y-m-d H:i:s');
                $full_comment = $existing_comment 
                    ? $existing_comment . "\n---\n[" . $timestamp . "] " . $user_name . ": " . $comment 
                    : "[" . $timestamp . "] " . $user_name . ": " . $comment;
            }
            
            // Check if record exists
            $stmt = $db->prepare("SELECT id FROM trade_receipts WHERE trade_id = ? AND trade_type = 'trade'");
            $stmt->execute([$trade_id]);
            if ($stmt->fetch()) {
                $stmt = $db->prepare("UPDATE trade_receipts SET receipt_files = ?, comment = ?, updated_at = NOW(), uploaded_by = ? WHERE trade_id = ? AND trade_type = 'trade'");
                $result = $stmt->execute([$receipts_str, $full_comment, $user_name, $trade_id]);
            } else {
                $stmt = $db->prepare("INSERT INTO trade_receipts (trade_id, trade_type, receipt_files, comment, uploaded_by, created_at, updated_at) VALUES (?, 'trade', ?, ?, ?, NOW(), NOW())");
                $result = $stmt->execute([$trade_id, $receipts_str, $full_comment, $user_name]);
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
        // If no files but comment only
        if (!empty($comment)) {
            $full_comment = $existing_comment;
            $timestamp = date('Y-m-d H:i:s');
            $full_comment = $existing_comment 
                ? $existing_comment . "\n---\n[" . $timestamp . "] " . $user_name . ": " . $comment 
                : "[" . $timestamp . "] " . $user_name . ": " . $comment;
            
            $stmt = $db->prepare("SELECT id FROM trade_receipts WHERE trade_id = ? AND trade_type = 'trade'");
            $stmt->execute([$trade_id]);
            if ($stmt->fetch()) {
                $stmt = $db->prepare("UPDATE trade_receipts SET comment = ?, updated_at = NOW(), uploaded_by = ? WHERE trade_id = ? AND trade_type = 'trade'");
                $result = $stmt->execute([$full_comment, $user_name, $trade_id]);
            } else {
                $stmt = $db->prepare("INSERT INTO trade_receipts (trade_id, trade_type, comment, uploaded_by, created_at, updated_at) VALUES (?, 'trade', ?, ?, NOW(), NOW())");
                $result = $stmt->execute([$trade_id, $full_comment, $user_name]);
            }
            
            if ($result) {
                $_SESSION['alert'] = ['Comment added successfully!', 'success'];
            } else {
                $_SESSION['alert'] = ['Failed to add comment', 'danger'];
            }
        } else {
            $_SESSION['alert'] = ['No files selected for upload', 'danger'];
        }
    }
    header('Location: trade_upload.php?' . http_build_query(array_filter([
        'filter' => $_GET['filter'] ?? 'all',
        'type' => $_GET['type'] ?? 'all',
        'search' => $_GET['search'] ?? ''
    ])));
    exit;
}

// ============================================
// HANDLE FILE DELETE
// ============================================

// Handle Receipt Delete
if (isset($_GET['delete_receipt']) && isset($_GET['trade_id']) && isset($_GET['file'])) {
    $trade_id = (int) $_GET['trade_id'];
    $file_to_delete = $_GET['file'];
    
    $stmt = $db->prepare("SELECT receipt_files, comment FROM trade_receipts WHERE trade_id = ? AND trade_type = 'trade'");
    $stmt->execute([$trade_id]);
    $record = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($record && !empty($record['receipt_files'])) {
        $receipts = explode(',', $record['receipt_files']);
        
        if (($key = array_search($file_to_delete, $receipts)) !== false) {
            unset($receipts[$key]);
            
            $filepath = __DIR__ . '/../uploads/trade_receipts/' . $file_to_delete;
            if (file_exists($filepath)) {
                unlink($filepath);
            }
            
            $receipts_str = !empty($receipts) ? implode(',', $receipts) : null;
            $stmt = $db->prepare("UPDATE trade_receipts SET receipt_files = ?, updated_at = NOW() WHERE trade_id = ? AND trade_type = 'trade'");
            if ($stmt->execute([$receipts_str, $trade_id])) {
                $_SESSION['alert'] = ['Receipt deleted successfully.', 'success'];
            }
        }
    }
    header('Location: trade_upload.php?' . http_build_query(array_filter([
        'filter' => $_GET['filter'] ?? 'all',
        'type' => $_GET['type'] ?? 'all',
        'search' => $_GET['search'] ?? ''
    ])));
    exit;
}

// ============================================
// HANDLE COMMENT DELETE
// ============================================

// Handle Comment Delete
if (isset($_GET['delete_comment']) && isset($_GET['trade_id'])) {
    $trade_id = (int) $_GET['trade_id'];
    
    $stmt = $db->prepare("UPDATE trade_receipts SET comment = NULL, updated_at = NOW() WHERE trade_id = ? AND trade_type = 'trade'");
    if ($stmt->execute([$trade_id])) {
        $_SESSION['alert'] = ['Comment deleted successfully.', 'success'];
    }
    
    header('Location: trade_upload.php?' . http_build_query(array_filter([
        'filter' => $_GET['filter'] ?? 'all',
        'type' => $_GET['type'] ?? 'all',
        'search' => $_GET['search'] ?? ''
    ])));
    exit;
}

// ============================================
// GET FILTER PARAMETERS
// ============================================
$filter = $_GET['filter'] ?? 'all';
$type_filter = $_GET['type'] ?? 'all';
$search = $_GET['search'] ?? '';

// ============================================
// FETCH TRADES
// ============================================
function getTrades($db, $filter = 'all', $type_filter = 'all', $search = '') {
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
            ANY_VALUE(tr.receipt_files) as receipt_files,
            ANY_VALUE(tr.comment) as receipt_comment,
            ANY_VALUE(tr.uploaded_by) as receipt_uploaded_by,
            ANY_VALUE(tr.created_at) as receipt_created_at,
            ANY_VALUE(tr.updated_at) as receipt_updated_at
        FROM trades t
        LEFT JOIN trade_receipts tr ON t.id = tr.trade_id AND tr.trade_type = 'trade'
        WHERE 1=1
    ";
    
    $params = [];
    
    // Type filter
    if ($type_filter === 'bond') {
        $sql .= " AND t.asset_class = 'bond'";
    } elseif ($type_filter === 'equity') {
        $sql .= " AND t.asset_class = 'equity'";
    } elseif ($type_filter === 'etf') {
        $sql .= " AND t.asset_class = 'Exchange Traded Funds'";
    }
    
    // Status filter
    if ($filter === 'has_receipt') {
        $sql .= " AND tr.receipt_files IS NOT NULL AND tr.receipt_files != ''";
    } elseif ($filter === 'no_receipt') {
        $sql .= " AND (tr.receipt_files IS NULL OR tr.receipt_files = '')";
    } elseif ($filter === 'has_comment') {
        $sql .= " AND tr.comment IS NOT NULL AND tr.comment != ''";
    } elseif ($filter === 'no_comment') {
        $sql .= " AND (tr.comment IS NULL OR tr.comment = '')";
    }
    
    // Search filter
    if (!empty($search)) {
        $sql .= " AND (t.client_name LIKE ? OR t.security_id LIKE ? OR t.trade_reference LIKE ? OR t.exchange_reference LIKE ?)";
        $search_param = "%$search%";
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
// GET DATA
// ============================================
$trades = [];
$error_message = '';

try {
    $trades = getTrades($db, $filter, $type_filter, $search);
} catch (Exception $e) {
    $error_message = "Error loading data: " . $e->getMessage();
    error_log("Trade upload page error: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
}

$page_title = 'Trade Receipt Upload & Comments';
include '../includes/header.php';
?>

<style>
    /* File Upload Styles */
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
    
    /* Comment Display */
    .comment-display {
        background: #f8f9fa;
        border-left: 3px solid #0d6efd;
        padding: 8px 10px;
        border-radius: 4px;
        font-size: 12px;
        max-width: 250px;
        max-height: 100px;
        overflow-y: auto;
        white-space: pre-wrap;
        word-wrap: break-word;
    }
    .comment-display .comment-meta {
        font-weight: 600;
        font-size: 10px;
        color: #495057;
        margin-bottom: 2px;
    }
    .comment-display .comment-text {
        margin-top: 2px;
    }
    .comment-display .comment-empty {
        color: #6c757d;
        font-style: italic;
    }
    .comment-actions {
        margin-top: 4px;
    }
    .comment-actions .btn-sm {
        font-size: 10px;
        padding: 1px 6px;
    }
    
    /* Status Badges */
    .status-badge {
        font-size: 11px;
        padding: 3px 8px;
    }
    
    /* Upload Drop Zone */
    .drop-zone {
        border: 2px dashed #dee2e6;
        border-radius: 8px;
        padding: 20px;
        text-align: center;
        transition: border-color 0.3s, background-color 0.3s;
        cursor: pointer;
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
    
    /* Table Row Hover */
    .trade-row {
        transition: background-color 0.2s;
    }
    .trade-row:hover {
        background-color: #f8f9fa;
    }
    
    /* Action Buttons */
    .action-btn {
        padding: 3px 8px;
        font-size: 12px;
        margin: 0 2px;
    }
    .action-btn i {
        margin-right: 2px;
    }
    
    /* Modal Enhancements */
    .modal-header .modal-title i {
        margin-right: 8px;
    }
    
    /* File preview thumbnails */
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
    
    /* Upload button disabled state */
    #uploadBtn:disabled {
        opacity: 0.6;
        cursor: not-allowed;
    }
    
    /* Simple clean table */
    .table-condensed td, .table-condensed th {
        padding: 6px 8px;
        font-size: 13px;
    }
    .table-condensed thead th {
        border-bottom: 2px solid #dee2e6;
        font-weight: 600;
        color: #495057;
    }
    
    /* Filter section */
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
    
    /* Alert styling */
    .alert {
        border-radius: 4px;
        font-size: 14px;
    }
</style>

<div class="container-fluid">
    <!-- Page Header -->
    <div class="row mb-3">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h4>
                        <i class="bi bi-file-earmark-arrow-up"></i> Trade Receipt Upload
                        <small class="text-muted fs-6">Upload files & add comments</small>
                    </h4>
                </div>
                <div>
                    <button class="btn btn-outline-secondary btn-sm" onclick="refreshPage()">
                        <i class="bi bi-arrow-clockwise"></i> Refresh
                    </button>
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
        <div class="alert alert-danger">
            <i class="bi bi-exclamation-triangle"></i> <?php echo htmlspecialchars($error_message); ?>
        </div>
    <?php endif; ?>

    <!-- Filters -->
    <div class="filter-section">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-2">
                <label class="form-label">Status</label>
                <select class="form-select form-select-sm" name="filter" onchange="this.form.submit()">
                    <option value="all" <?php echo $filter === 'all' ? 'selected' : ''; ?>>All Trades</option>
                    <option value="has_receipt" <?php echo $filter === 'has_receipt' ? 'selected' : ''; ?>>With Receipt</option>
                    <option value="no_receipt" <?php echo $filter === 'no_receipt' ? 'selected' : ''; ?>>No Receipt</option>
                    <option value="has_comment" <?php echo $filter === 'has_comment' ? 'selected' : ''; ?>>With Comment</option>
                    <option value="no_comment" <?php echo $filter === 'no_comment' ? 'selected' : ''; ?>>No Comment</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Asset Type</label>
                <select class="form-select form-select-sm" name="type" onchange="this.form.submit()">
                    <option value="all" <?php echo $type_filter === 'all' ? 'selected' : ''; ?>>All Types</option>
                    <option value="bond" <?php echo $type_filter === 'bond' ? 'selected' : ''; ?>>Bond</option>
                    <option value="equity" <?php echo $type_filter === 'equity' ? 'selected' : ''; ?>>Equity</option>
                    <option value="etf" <?php echo $type_filter === 'etf' ? 'selected' : ''; ?>>ETF</option>
                </select>
            </div>
            <div class="col-md-5">
                <label class="form-label">Search</label>
                <div class="input-group input-group-sm">
                    <input type="text" class="form-control" name="search" placeholder="Client, Security, Reference..." value="<?php echo htmlspecialchars($search); ?>">
                    <button type="submit" class="btn btn-secondary">
                        <i class="bi bi-search"></i>
                    </button>
                </div>
            </div>
            <div class="col-md-2">
                <label class="form-label">&nbsp;</label>
                <a href="trade_upload.php" class="btn btn-outline-secondary btn-sm w-100">
                    <i class="bi bi-eraser"></i> Clear
                </a>
            </div>
            <div class="col-md-1">
                <label class="form-label">&nbsp;</label>
                <button class="btn btn-primary btn-sm w-100" onclick="openUploadModal(0)" type="button">
                    <i class="bi bi-plus-circle"></i> New
                </button>
            </div>
        </form>
    </div>

    <!-- Trades Table -->
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h6 class="mb-0">
                <i class="bi bi-table"></i> Trades 
                <span class="badge bg-secondary ms-2"><?php echo count($trades); ?></span>
            </h6>
        </div>
        <div class="card-body p-0">
            <?php if (empty($trades)): ?>
                <div class="text-center py-5">
                    <i class="bi bi-inbox" style="font-size: 48px; color: #dee2e6;"></i>
                    <p class="text-muted mt-3">No trades found matching your criteria.</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm table-hover mb-0 table-condensed">
                        <thead class="table-light">
                            <tr>
                                <th>Ref</th>
                                <th>Client</th>
                                <th>Security</th>
                                <th>Type</th>
                                <th>Side</th>
                                <th class="text-end">Qty</th>
                                <th class="text-end">Value</th>
                                <th>Date</th>
                                <th>Files / Comments</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($trades as $trade): 
                                $receipts = !empty($trade['receipt_files']) ? explode(',', $trade['receipt_files']) : [];
                                $hasReceipt = !empty($receipts) && !empty($receipts[0]);
                                $hasComment = !empty($trade['receipt_comment']);
                                $commentText = $trade['receipt_comment'] ?? '';
                                
                                $assetClass = $trade['asset_class'] ?? 'Unknown';
                                if ($assetClass === 'Exchange Traded Funds') $assetClass = 'ETF';
                            ?>
                                <tr class="trade-row">
                                    <td>
                                        <span class="fw-semibold small"><?php echo htmlspecialchars($trade['trade_reference'] ?? ''); ?></span>
                                        <?php if (!empty($trade['additional_reference'])): ?>
                                            <br><small class="text-muted"><?php echo htmlspecialchars($trade['additional_reference']); ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php echo htmlspecialchars($trade['client_name'] ?? ''); ?>
                                        <br><small class="text-muted"><?php echo htmlspecialchars($trade['client_cds_account'] ?? ''); ?></small>
                                    </td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($trade['security_id'] ?? ''); ?></strong>
                                        <br><small class="text-muted"><?php echo htmlspecialchars($trade['security_name'] ?? ''); ?></small>
                                    </td>
                                    <td><span class="badge bg-secondary status-badge"><?php echo $assetClass; ?></span></td>
                                    <td>
                                        <span class="badge <?php echo strtolower($trade['trade_side'] ?? '') === 'buy' ? 'bg-success' : 'bg-danger'; ?> status-badge">
                                            <?php echo strtoupper($trade['trade_side'] ?? ''); ?>
                                        </span>
                                    </td>
                                    <td class="text-end">
                                        <?php 
                                        if ($assetClass === 'Bond' || $assetClass === 'bond') {
                                            echo 'TZS ' . number_format(floatval($trade['quantity'] ?? 0), 2);
                                        } else {
                                            echo number_format(floatval($trade['quantity'] ?? 0), 0);
                                        }
                                        ?>
                                    </td>
                                    <td class="text-end fw-bold">
                                        TZS <?php echo number_format(floatval($trade['consideration'] ?? 0), 2); ?>
                                    </td>
                                    <td><?php echo date('d/m/Y', strtotime($trade['trade_date'] ?? '')); ?></td>
                                    <td>
                                        <!-- Files -->
                                        <?php if ($hasReceipt): ?>
                                            <div class="receipt-thumbnails mb-1">
                                                <?php 
                                                $displayCount = 0;
                                                foreach ($receipts as $receiptFile):
                                                    $receiptFile = trim($receiptFile);
                                                    if (empty($receiptFile)) continue;
                                                    if ($displayCount >= 3) break;
                                                    $displayCount++;
                                                    $filepath = '../uploads/trade_receipts/' . $receiptFile;
                                                    $ext = strtolower(pathinfo($receiptFile, PATHINFO_EXTENSION));
                                                    $isImage = in_array($ext, ['jpg', 'jpeg', 'png', 'gif']);
                                                ?>
                                                    <div class="thumbnail" onclick="window.open('<?php echo $filepath; ?>', '_blank')" title="Click to view">
                                                        <?php if ($isImage && file_exists($filepath)): ?>
                                                            <img src="<?php echo $filepath; ?>" alt="Receipt">
                                                        <?php else: ?>
                                                            <div class="file-icon">
                                                                <i class="bi <?php echo in_array($ext, ['pdf']) ? 'bi-file-pdf' : (in_array($ext, ['doc', 'docx']) ? 'bi-file-word' : (in_array($ext, ['xls', 'xlsx']) ? 'bi-file-excel' : 'bi-file')); ?>"></i>
                                                            </div>
                                                        <?php endif; ?>
                                                        <a href="trade_upload.php?delete_receipt=1&trade_id=<?php echo $trade['id']; ?>&file=<?php echo urlencode($receiptFile); ?>&filter=<?php echo urlencode($filter); ?>&type=<?php echo urlencode($type_filter); ?>&search=<?php echo urlencode($search); ?>" 
                                                           class="delete-btn" onclick="event.stopPropagation(); return confirm('Delete this file?')">
                                                            <i class="bi bi-x"></i>
                                                        </a>
                                                    </div>
                                                <?php endforeach; ?>
                                                <?php if (count($receipts) > 3): ?>
                                                    <div class="more-badge">+<?php echo count($receipts) - 3; ?></div>
                                                <?php endif; ?>
                                            </div>
                                        <?php else: ?>
                                            <span class="text-muted small">No files</span>
                                        <?php endif; ?>
                                        
                                        <!-- Comment -->
                                        <?php if ($hasComment): ?>
                                            <div class="comment-display">
                                                <div class="comment-text"><?php echo nl2br(htmlspecialchars($commentText)); ?></div>
                                            </div>
                                            <div class="comment-actions">
                                                <a href="trade_upload.php?delete_comment=1&trade_id=<?php echo $trade['id']; ?>&filter=<?php echo urlencode($filter); ?>&type=<?php echo urlencode($type_filter); ?>&search=<?php echo urlencode($search); ?>" 
                                                   class="text-danger small" onclick="return confirm('Delete this comment?')">
                                                    <i class="bi bi-trash"></i> Delete
                                                </a>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end">
                                        <div class="btn-group btn-group-sm" role="group">
                                            <button class="btn btn-outline-primary" onclick="openUploadModal(<?php echo $trade['id']; ?>)" title="Upload Files / Add Comment">
                                                <i class="bi bi-upload"></i>
                                            </button>
                                            <button class="btn btn-outline-secondary" onclick="openCommentOnlyModal(<?php echo $trade['id']; ?>)" title="Add Comment Only">
                                                <i class="bi bi-chat-dots"></i>
                                            </button>
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
<!-- MODALS -->
<!-- ============================================ -->

<!-- Upload Modal -->
<div class="modal fade" id="uploadModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="bi bi-upload text-primary"></i> Upload Files & Add Comment
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" enctype="multipart/form-data" action="trade_upload.php" id="uploadForm">
                <div class="modal-body">
                    <input type="hidden" name="trade_id" id="upload_trade_id" value="">
                    <input type="hidden" name="upload_receipt" value="1">
                    <input type="hidden" name="filter" value="<?php echo htmlspecialchars($filter); ?>">
                    <input type="hidden" name="type" value="<?php echo htmlspecialchars($type_filter); ?>">
                    <input type="hidden" name="search" value="<?php echo htmlspecialchars($search); ?>">
                    
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle"></i> 
                        <strong>Trade ID:</strong> <span id="tradeIdDisplay">-</span>
                    </div>
                    
                    <!-- File Upload Area -->
                    <div class="mb-3">
                        <label class="form-label fw-bold">Select Files to Upload</label>
                        <div class="drop-zone" id="dropZone">
                            <div class="icon">
                                <i class="bi bi-cloud-arrow-up"></i>
                            </div>
                            <div class="text">
                                <strong>Click to browse</strong> or drag & drop files here
                                <br><small class="text-muted">Supports: JPG, PNG, GIF, PDF, DOC, XLS (Max 10MB each)</small>
                            </div>
                            <input type="file" class="form-control" name="receipt_files[]" id="fileInput" accept="image/*,.pdf,.doc,.docx,.xls,.xlsx" multiple style="display: none;">
                        </div>
                    </div>
                    
                    <!-- File Preview Thumbnails -->
                    <div id="filePreviewThumbnails" class="file-preview-thumbnails"></div>
                    
                    <!-- Selected Files List -->
                    <div id="selectedFilesContainer">
                        <div class="file-list" id="fileList">
                            <p class="text-muted small">No files selected</p>
                        </div>
                    </div>
                    
                    <!-- Comment Section -->
                    <div class="mt-3">
                        <label class="form-label fw-bold">Add Comment (Optional)</label>
                        <textarea class="form-control" name="receipt_comment" id="receiptComment" rows="3" placeholder="Add a comment about this trade..."></textarea>
                    </div>
                    
                    <div class="mt-3">
                        <button type="button" class="btn btn-outline-secondary btn-sm" onclick="document.getElementById('fileInput').click()">
                            <i class="bi bi-plus-circle"></i> Add More Files
                        </button>
                        <button type="button" class="btn btn-outline-danger btn-sm" onclick="clearSelectedFiles()">
                            <i class="bi bi-x-circle"></i> Clear All
                        </button>
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

<!-- Comment Only Modal -->
<div class="modal fade" id="commentOnlyModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="bi bi-chat-dots text-primary"></i> Add Comment
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" enctype="multipart/form-data" action="trade_upload.php">
                <div class="modal-body">
                    <input type="hidden" name="trade_id" id="comment_trade_id" value="">
                    <input type="hidden" name="upload_receipt" value="1">
                    <input type="hidden" name="filter" value="<?php echo htmlspecialchars($filter); ?>">
                    <input type="hidden" name="type" value="<?php echo htmlspecialchars($type_filter); ?>">
                    <input type="hidden" name="search" value="<?php echo htmlspecialchars($search); ?>">
                    
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle"></i> 
                        <strong>Trade:</strong> <span id="commentTradeRef">-</span>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label fw-bold">Your Comment</label>
                        <textarea class="form-control" name="receipt_comment" id="traderComment" rows="4" placeholder="Enter your comment about this trade..." required></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-send"></i> Add Comment
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
                    <i class="bi bi-info-circle text-info"></i> Trade Details
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
            const icon = file.type === 'application/pdf' ? 'bi-file-pdf' :
                        file.type.includes('word') ? 'bi-file-word' :
                        file.type.includes('excel') ? 'bi-file-excel' : 'bi-file';
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

function openCommentOnlyModal(tradeId) {
    document.getElementById('comment_trade_id').value = tradeId;
    document.getElementById('commentTradeRef').textContent = tradeId;
    document.getElementById('traderComment').value = '';
    
    const modal = new bootstrap.Modal(document.getElementById('commentOnlyModal'));
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
            </div>
        `;
    }, 500);
}

// Refresh page
function refreshPage() {
    window.location.reload();
}

// ============================================
// FORM SUBMISSION
// ============================================

document.getElementById('uploadForm').addEventListener('submit', function(e) {
    if (selectedFiles.length === 0) {
        const comment = document.getElementById('receiptComment').value.trim();
        if (!comment) {
            e.preventDefault();
            alert('Please select at least one file or add a comment.');
            return false;
        }
        // If only comment, still submit
        return true;
    }
    
    const fileInput = document.getElementById('fileInput');
    const dataTransfer = new DataTransfer();
    selectedFiles.forEach(file => dataTransfer.items.add(file));
    fileInput.files = dataTransfer.files;
    
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

console.log('Trade Upload System initialized successfully.');
</script>

<?php include '../includes/footer.php'; ?>
