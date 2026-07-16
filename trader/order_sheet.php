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
// HANDLE FILE UPLOADS
// ============================================

// Handle Receipt Upload
if (isset($_POST['upload_receipt']) && isset($_POST['trade_id'])) {
    $trade_id = (int) $_POST['trade_id'];
    $uploaded_files = [];
    $errors = [];
    
    $allowed_exts = ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'doc', 'docx', 'xls', 'xlsx'];
    $max_size = 10 * 1024 * 1024; // 10MB
    
    $upload_dir = __DIR__ . '/../uploads/trade_receipts/';
    if (!file_exists($upload_dir)) {
        mkdir($upload_dir, 0777, true);
    }
    
    // Debug logging
    error_log("=== Upload Debug ===");
    error_log("POST: " . print_r($_POST, true));
    error_log("FILES: " . print_r($_FILES, true));
    
    // Get existing receipts
    $stmt = $db->prepare("SELECT receipt_files FROM trade_receipts WHERE trade_id = ? AND trade_type = 'trade'");
    $stmt->execute([$trade_id]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);
    $existing_receipts = !empty($existing['receipt_files']) ? explode(',', $existing['receipt_files']) : [];
    
    if (isset($_FILES['receipt_files']) && !empty($_FILES['receipt_files']['name'][0])) {
        $files = $_FILES['receipt_files'];
        $total_files = count($files['name']);
        
        for ($i = 0; $i < $total_files; $i++) {
            if ($files['error'][$i] !== UPLOAD_ERR_OK) {
                $errors[] = "File '{$files['name'][$i]}' upload error: " . $files['error'][$i];
                error_log("Upload error for {$files['name'][$i]}: " . $files['error'][$i]);
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
                error_log("Successfully uploaded: $filename");
            } else {
                $errors[] = "Failed to upload file '{$files['name'][$i]}'";
                error_log("Failed to move uploaded file: {$files['tmp_name'][$i]} to $filepath");
            }
        }
        
        if (!empty($uploaded_files)) {
            $all_receipts = array_merge($existing_receipts, $uploaded_files);
            $receipts_str = implode(',', $all_receipts);
            
            // Check if record exists
            $stmt = $db->prepare("SELECT id FROM trade_receipts WHERE trade_id = ? AND trade_type = 'trade'");
            $stmt->execute([$trade_id]);
            if ($stmt->fetch()) {
                $stmt = $db->prepare("UPDATE trade_receipts SET receipt_files = ?, updated_at = NOW(), uploaded_by = ? WHERE trade_id = ? AND trade_type = 'trade'");
                $result = $stmt->execute([$receipts_str, $user_name, $trade_id]);
            } else {
                $stmt = $db->prepare("INSERT INTO trade_receipts (trade_id, trade_type, receipt_files, uploaded_by, created_at, updated_at) VALUES (?, 'trade', ?, ?, NOW(), NOW())");
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
    
    $stmt = $db->prepare("SELECT receipt_files FROM trade_receipts WHERE trade_id = ? AND trade_type = 'trade'");
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
// HANDLE COMMENTS
// ============================================

// Handle Trader Comment Upload
if (isset($_POST['add_comment']) && isset($_POST['trade_id'])) {
    $trade_id = (int) $_POST['trade_id'];
    $comment = trim($_POST['trader_comment'] ?? '');
    
    if (empty($comment)) {
        $_SESSION['alert'] = ['Please enter a comment.', 'danger'];
        header('Location: trade_upload.php?' . http_build_query(array_filter([
            'filter' => $_GET['filter'] ?? 'all',
            'type' => $_GET['type'] ?? 'all',
            'search' => $_GET['search'] ?? ''
        ])));
        exit;
    }
    
    $stmt = $db->prepare("
        INSERT INTO trade_comments (trade_id, comment, created_by, created_at) 
        VALUES (?, ?, ?, NOW())
    ");
    
    if ($stmt->execute([$trade_id, $comment, $user_name])) {
        $_SESSION['alert'] = ['Comment added successfully.', 'success'];
    } else {
        $_SESSION['alert'] = ['Failed to add comment.', 'danger'];
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
            ANY_VALUE(tr.uploaded_by) as receipt_uploaded_by,
            ANY_VALUE(tr.created_at) as receipt_created_at,
            ANY_VALUE(tr.updated_at) as receipt_updated_at,
            GROUP_CONCAT(DISTINCT tc.comment ORDER BY tc.created_at DESC SEPARATOR '|||') as comments,
            GROUP_CONCAT(DISTINCT tc.created_by ORDER BY tc.created_at DESC SEPARATOR '|||') as comment_authors,
            GROUP_CONCAT(DISTINCT tc.created_at ORDER BY tc.created_at DESC SEPARATOR '|||') as comment_dates
        FROM trades t
        LEFT JOIN trade_receipts tr ON t.id = tr.trade_id AND tr.trade_type = 'trade'
        LEFT JOIN trade_comments tc ON t.id = tc.trade_id
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
// GET STATS
// ============================================
function getStats($db) {
    $stats = [
        'total' => 0,
        'has_receipt' => 0,
        'no_receipt' => 0,
        'bond' => 0,
        'equity' => 0,
        'etf' => 0
    ];
    
    try {
        $stmt = $db->query("
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN tr.receipt_files IS NOT NULL AND tr.receipt_files != '' THEN 1 ELSE 0 END) as has_receipt,
                SUM(CASE WHEN tr.receipt_files IS NULL OR tr.receipt_files = '' THEN 1 ELSE 0 END) as no_receipt
            FROM trades t
            LEFT JOIN trade_receipts tr ON t.id = tr.trade_id AND tr.trade_type = 'trade'
        ");
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($result) {
            $stats['total'] = (int)$result['total'];
            $stats['has_receipt'] = (int)$result['has_receipt'];
            $stats['no_receipt'] = (int)$result['no_receipt'];
        }
    } catch (Exception $e) {
        error_log("Error getting stats: " . $e->getMessage());
    }
    
    try {
        $stmt = $db->query("
            SELECT 
                asset_class,
                COUNT(*) as count
            FROM trades
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
$stats = ['total' => 0, 'has_receipt' => 0, 'no_receipt' => 0, 'bond' => 0, 'equity' => 0, 'etf' => 0];
$error_message = '';

try {
    $trades = getTrades($db, $filter, $type_filter, $search);
    $stats = getStats($db);
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
    
    /* Comment Styles */
    .comment-bubble {
        background: #f8f9fa;
        border-left: 3px solid #0d6efd;
        padding: 6px 10px;
        border-radius: 4px;
        font-size: 13px;
        max-width: 200px;
        margin-bottom: 4px;
    }
    .comment-bubble .author {
        font-weight: 600;
        font-size: 11px;
        color: #495057;
    }
    .comment-bubble .time {
        font-size: 10px;
        color: #6c757d;
        margin-left: 5px;
    }
    .comment-bubble .comment-text {
        word-wrap: break-word;
        margin-top: 2px;
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
    
    /* Stats Cards */
    .stat-card {
        transition: transform 0.2s, box-shadow 0.2s;
        cursor: default;
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
    
    /* Preview in Modal */
    .preview-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(80px, 1fr));
        gap: 8px;
        margin-top: 10px;
    }
    .preview-grid .preview-item {
        border: 1px solid #dee2e6;
        border-radius: 4px;
        overflow: hidden;
        position: relative;
    }
    .preview-grid .preview-item img {
        width: 100%;
        height: 80px;
        object-fit: cover;
    }
    .preview-grid .preview-item .file-name {
        font-size: 10px;
        text-align: center;
        padding: 2px;
        background: #f8f9fa;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }
    
    /* Selected files preview in modal */
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
</style>

<div class="container-fluid">
    <!-- Page Header -->
    <div class="row mb-3">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h2>
                        <i class="bi bi-file-earmark-arrow-up"></i> Trade Receipt Upload
                        <small class="text-muted fs-6">Upload files, photos & comments</small>
                    </h2>
                </div>
                <div>
                    <button class="btn btn-primary btn-sm" onclick="refreshPage()">
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

    <!-- Stats -->
    <div class="row mb-3 g-2">
        <div class="col-md-2 col-6">
            <div class="card stat-card border-primary">
                <div class="card-body text-center">
                    <div class="stat-number text-primary"><?php echo number_format($stats['total']); ?></div>
                    <div class="stat-label">Total Trades</div>
                </div>
            </div>
        </div>
        <div class="col-md-2 col-6">
            <div class="card stat-card border-success">
                <div class="card-body text-center">
                    <div class="stat-number text-success"><?php echo number_format($stats['has_receipt']); ?></div>
                    <div class="stat-label">With Receipt</div>
                </div>
            </div>
        </div>
        <div class="col-md-2 col-6">
            <div class="card stat-card border-warning">
                <div class="card-body text-center">
                    <div class="stat-number text-warning"><?php echo number_format($stats['no_receipt']); ?></div>
                    <div class="stat-label">No Receipt</div>
                </div>
            </div>
        </div>
        <div class="col-md-2 col-6">
            <div class="card stat-card border-info">
                <div class="card-body text-center">
                    <div class="stat-number text-info"><?php echo number_format($stats['bond']); ?></div>
                    <div class="stat-label">Bonds</div>
                </div>
            </div>
        </div>
        <div class="col-md-2 col-6">
            <div class="card stat-card border-secondary">
                <div class="card-body text-center">
                    <div class="stat-number text-secondary"><?php echo number_format($stats['equity']); ?></div>
                    <div class="stat-label">Equities</div>
                </div>
            </div>
        </div>
        <div class="col-md-2 col-6">
            <div class="card stat-card border-dark">
                <div class="card-body text-center">
                    <div class="stat-number text-dark"><?php echo number_format($stats['etf']); ?></div>
                    <div class="stat-label">ETFs</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <div class="card mb-3">
        <div class="card-body">
            <form method="GET" class="row g-2 align-items-end">
                <div class="col-md-3">
                    <label class="form-label small text-muted">Status</label>
                    <select class="form-select form-select-sm" name="filter" onchange="this.form.submit()">
                        <option value="all" <?php echo $filter === 'all' ? 'selected' : ''; ?>>All Trades</option>
                        <option value="has_receipt" <?php echo $filter === 'has_receipt' ? 'selected' : ''; ?>>With Receipt</option>
                        <option value="no_receipt" <?php echo $filter === 'no_receipt' ? 'selected' : ''; ?>>No Receipt</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small text-muted">Asset Type</label>
                    <select class="form-select form-select-sm" name="type" onchange="this.form.submit()">
                        <option value="all" <?php echo $type_filter === 'all' ? 'selected' : ''; ?>>All Types</option>
                        <option value="bond" <?php echo $type_filter === 'bond' ? 'selected' : ''; ?>>Bond</option>
                        <option value="equity" <?php echo $type_filter === 'equity' ? 'selected' : ''; ?>>Equity</option>
                        <option value="etf" <?php echo $type_filter === 'etf' ? 'selected' : ''; ?>>ETF</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label small text-muted">Search</label>
                    <div class="input-group input-group-sm">
                        <input type="text" class="form-control" name="search" placeholder="Client, Security, Reference..." value="<?php echo htmlspecialchars($search); ?>">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-search"></i>
                        </button>
                    </div>
                </div>
                <div class="col-md-2">
                    <a href="trade_upload.php" class="btn btn-outline-secondary btn-sm w-100">
                        <i class="bi bi-eraser"></i> Clear
                    </a>
                </div>
            </form>
        </div>
    </div>

    <!-- Trades Table -->
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h6 class="mb-0">
                <i class="bi bi-table"></i> Trades 
                <span class="badge bg-secondary ms-2"><?php echo count($trades); ?></span>
            </h6>
            <div>
                <button class="btn btn-outline-primary btn-sm" onclick="openUploadModal(0)" title="Upload for New Trade">
                    <i class="bi bi-plus-circle"></i> New Trade
                </button>
            </div>
        </div>
        <div class="card-body p-0">
            <?php if (empty($trades)): ?>
                <div class="text-center py-5">
                    <i class="bi bi-inbox" style="font-size: 48px; color: #dee2e6;"></i>
                    <p class="text-muted mt-3">No trades found matching your criteria.</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm table-hover mb-0">
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
                                <th>Files</th>
                                <th>Comments</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($trades as $trade): 
                                $receipts = !empty($trade['receipt_files']) ? explode(',', $trade['receipt_files']) : [];
                                $hasReceipt = !empty($receipts) && !empty($receipts[0]);
                                
                                $assetClass = $trade['asset_class'] ?? 'Unknown';
                                if ($assetClass === 'Exchange Traded Funds') $assetClass = 'ETF';
                                
                                // Get latest comment
                                $commentText = '';
                                $commentAuthor = '';
                                $commentTime = '';
                                if (!empty($trade['comments'])) {
                                    $comment_parts = explode('|||', $trade['comments']);
                                    $author_parts = !empty($trade['comment_authors']) ? explode('|||', $trade['comment_authors']) : [];
                                    $date_parts = !empty($trade['comment_dates']) ? explode('|||', $trade['comment_dates']) : [];
                                    $commentText = htmlspecialchars($comment_parts[0] ?? '');
                                    $commentAuthor = $author_parts[0] ?? 'Unknown';
                                    $commentTime = isset($date_parts[0]) ? date('d/m/Y H:i', strtotime($date_parts[0])) : '';
                                }
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
                                        <?php if ($hasReceipt): ?>
                                            <div class="receipt-thumbnails">
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
                                    </td>
                                    <td>
                                        <?php if (!empty($commentText)): ?>
                                            <div class="comment-bubble">
                                                <div>
                                                    <span class="author"><?php echo $commentAuthor; ?></span>
                                                    <span class="time"><?php echo $commentTime; ?></span>
                                                </div>
                                                <div class="comment-text"><?php echo $commentText; ?></div>
                                            </div>
                                        <?php else: ?>
                                            <span class="text-muted small">No comments</span>
                                        <?php endif; ?>
                                        <button class="btn btn-outline-secondary btn-sm mt-1" onclick="openCommentModal(<?php echo $trade['id']; ?>)">
                                            <i class="bi bi-chat"></i> Add
                                        </button>
                                    </td>
                                    <td class="text-end">
                                        <div class="btn-group btn-group-sm" role="group">
                                            <button class="btn btn-outline-primary" onclick="openUploadModal(<?php echo $trade['id']; ?>)" title="Upload Files">
                                                <i class="bi bi-upload"></i>
                                            </button>
                                            <button class="btn btn-outline-secondary" onclick="openCommentModal(<?php echo $trade['id']; ?>)" title="Add Comment">
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
                    <i class="bi bi-upload text-primary"></i> Upload Files
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

<!-- Comment Modal -->
<div class="modal fade" id="commentModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="bi bi-chat-dots text-primary"></i> Add Comment
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="trade_upload.php">
                <div class="modal-body">
                    <input type="hidden" name="trade_id" id="comment_trade_id" value="">
                    <input type="hidden" name="add_comment" value="1">
                    <input type="hidden" name="filter" value="<?php echo htmlspecialchars($filter); ?>">
                    <input type="hidden" name="type" value="<?php echo htmlspecialchars($type_filter); ?>">
                    <input type="hidden" name="search" value="<?php echo htmlspecialchars($search); ?>">
                    
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle"></i> 
                        <strong>Trade:</strong> <span id="commentTradeRef">-</span>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label fw-bold">Your Comment</label>
                        <textarea class="form-control" name="trader_comment" id="traderComment" rows="4" placeholder="Enter your comment about this trade..." required></textarea>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label fw-bold">Recent Comments</label>
                        <div id="recentComments" class="border rounded p-2" style="max-height: 150px; overflow-y: auto;">
                            <p class="text-muted small">No previous comments</p>
                        </div>
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
// FILE UPLOAD HANDLING - FIXED AND IMPROVED
// ============================================

let selectedFiles = [];

// File input change handler - FIXED: Don't clear the input
document.getElementById('fileInput').addEventListener('change', function(e) {
    const files = Array.from(e.target.files);
    files.forEach(file => {
        // Check if file already selected (by name and size)
        if (!selectedFiles.some(f => f.name === file.name && f.size === file.size)) {
            selectedFiles.push(file);
        }
    });
    updateFileList();
    // DO NOT clear e.target.value here - let the form handle it
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
    
    // Update the file input with dropped files
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
    
    if (selectedFiles.length === 0) {
        container.innerHTML = '<p class="text-muted small">No files selected</p>';
        thumbnailsContainer.innerHTML = '';
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
    let thumbHtml = '';
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
            // Non-image file icon
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
    
    // Update the file input with remaining files
    const dataTransfer = new DataTransfer();
    selectedFiles.forEach(file => dataTransfer.items.add(file));
    document.getElementById('fileInput').files = dataTransfer.files;
}

// Clear all selected files
function clearSelectedFiles() {
    selectedFiles = [];
    updateFileList();
    document.getElementById('fileInput').value = '';
}

// ============================================
// MODAL FUNCTIONS
// ============================================

function openUploadModal(tradeId) {
    // Reset file selection
    selectedFiles = [];
    updateFileList();
    document.getElementById('fileInput').value = '';
    document.getElementById('filePreviewThumbnails').innerHTML = '';
    
    document.getElementById('upload_trade_id').value = tradeId;
    document.getElementById('tradeIdDisplay').textContent = tradeId === 0 ? 'New Trade' : tradeId;
    
    const modal = new bootstrap.Modal(document.getElementById('uploadModal'));
    modal.show();
}

function openCommentModal(tradeId) {
    document.getElementById('comment_trade_id').value = tradeId;
    document.getElementById('commentTradeRef').textContent = tradeId;
    document.getElementById('traderComment').value = '';
    
    // Load recent comments (you can implement AJAX here)
    const container = document.getElementById('recentComments');
    container.innerHTML = '<p class="text-muted small">Loading comments...</p>';
    
    // Simulate loading - in production, use AJAX
    setTimeout(() => {
        container.innerHTML = '<p class="text-muted small">No previous comments for this trade.</p>';
    }, 500);
    
    const modal = new bootstrap.Modal(document.getElementById('commentModal'));
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
            <p class="mt-2">Loading trade ${tradeId} details...</p>
        </div>
    `;
    modal.show();
    
    // Simulate loading - in production, use AJAX
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
                        <div class="fw-bold text-success">TZS 1,500,000.00</div>
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
    }, 800);
}

// Refresh page
function refreshPage() {
    window.location.reload();
}

// ============================================
// FORM SUBMISSION - FIXED
// ============================================

document.getElementById('uploadForm').addEventListener('submit', function(e) {
    // If no files selected, prevent submission
    if (selectedFiles.length === 0) {
        e.preventDefault();
        alert('Please select at least one file to upload.');
        return false;
    }
    
    // Ensure the file input has the files
    const fileInput = document.getElementById('fileInput');
    const dataTransfer = new DataTransfer();
    selectedFiles.forEach(file => dataTransfer.items.add(file));
    fileInput.files = dataTransfer.files;
    
    // Let the form submit normally
    return true;
});

// Comment form validation
document.querySelectorAll('form[action="trade_upload.php"]').forEach(form => {
    if (form.querySelector('textarea[name="trader_comment"]')) {
        form.addEventListener('submit', function(e) {
            const comment = this.querySelector('textarea[name="trader_comment"]').value.trim();
            if (!comment) {
                e.preventDefault();
                alert('Please enter a comment.');
                return false;
            }
            return true;
        });
    }
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
console.log('Selected files:', selectedFiles.length);
</script>

<?php include '../includes/footer.php'; ?>
