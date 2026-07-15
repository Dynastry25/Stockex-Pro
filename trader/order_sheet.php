<?php
// ============================================
// NUMERIC REFERENCE RECEIPT UPLOAD
// ============================================

error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/numeric_receipt_errors.log');

require_once '../config/config.php';
require_once '../auth/auth_middleware.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_trader();

$db = getDBConnection();
$current_user = get_logged_in_user() ?: get_session_user();
$user_name = $current_user['username'] ?? 'System';

// ============================================
// HANDLE FILE UPLOAD
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_receipt'])) {
    
    $trade_id = isset($_POST['trade_id']) ? (int) $_POST['trade_id'] : 0;
    
    if ($trade_id <= 0) {
        $_SESSION['alert'] = ['Invalid trade ID.', 'danger'];
        header('Location: order_sheet.php?tab=numeric');
        exit;
    }
    
    $uploaded_files = [];
    $errors = [];
    
    // Create upload directory
    $upload_dir = __DIR__ . '/../uploads/numeric_receipts/';
    if (!file_exists($upload_dir)) {
        mkdir($upload_dir, 0777, true);
    }
    
    // Handle file uploads
    if (isset($_FILES['payment_receipts']) && !empty($_FILES['payment_receipts']['name'][0])) {
        $allowed_exts = ['jpg', 'jpeg', 'png', 'gif', 'pdf'];
        $max_size = 5 * 1024 * 1024; // 5MB
        
        $files = $_FILES['payment_receipts'];
        $total_files = count($files['name']);
        
        for ($i = 0; $i < $total_files; $i++) {
            if ($files['error'][$i] === UPLOAD_ERR_OK) {
                $file_ext = strtolower(pathinfo($files['name'][$i], PATHINFO_EXTENSION));
                if (in_array($file_ext, $allowed_exts) && $files['size'][$i] <= $max_size) {
                    $filename = 'receipt_' . $trade_id . '_' . date('Ymd_His') . '_' . ($i + 1) . '.' . $file_ext;
                    $filepath = $upload_dir . $filename;
                    
                    if (move_uploaded_file($files['tmp_name'][$i], $filepath)) {
                        $uploaded_files[] = $filename;
                    } else {
                        $errors[] = "Failed to upload file '{$files['name'][$i]}'";
                    }
                } else {
                    $errors[] = "Invalid file type or size for '{$files['name'][$i]}'";
                }
            } else {
                $errors[] = "Upload error for file '{$files['name'][$i]}'";
            }
        }
    }
    
    // Save to database
    if (!empty($uploaded_files)) {
        try {
            // Check if record exists
            $stmt = $db->prepare("SELECT id, payment_receipt FROM numeric_trade_receipts WHERE trade_id = ? AND trade_type = 'trade'");
            $stmt->execute([$trade_id]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($existing) {
                $receipts = !empty($existing['payment_receipt']) ? explode(',', $existing['payment_receipt']) : [];
                $all_receipts = array_merge($receipts, $uploaded_files);
                $receipts_str = implode(',', $all_receipts);
                
                $stmt = $db->prepare("UPDATE numeric_trade_receipts SET payment_receipt = ?, uploaded_by = ?, updated_at = NOW() WHERE trade_id = ? AND trade_type = 'trade'");
                $result = $stmt->execute([$receipts_str, $user_name, $trade_id]);
            } else {
                $receipts_str = implode(',', $uploaded_files);
                $stmt = $db->prepare("INSERT INTO numeric_trade_receipts (trade_id, trade_type, payment_receipt, uploaded_by, created_at, updated_at) VALUES (?, 'trade', ?, ?, NOW(), NOW())");
                $result = $stmt->execute([$trade_id, $receipts_str, $user_name]);
            }
            
            if ($result) {
                $_SESSION['alert'] = [count($uploaded_files) . ' receipt(s) uploaded successfully!', 'success'];
            } else {
                $_SESSION['alert'] = ['Failed to save to database.', 'danger'];
            }
        } catch (Exception $e) {
            $_SESSION['alert'] = ['Database error: ' . $e->getMessage(), 'danger'];
        }
    } else {
        $_SESSION['alert'] = ['No valid files uploaded. ' . implode('; ', $errors), 'danger'];
    }
    
    // Redirect back to order_sheet.php
    header('Location: order_sheet.php?tab=numeric');
    exit;
}

// ============================================
// HANDLE DELETE RECEIPT
// ============================================
if (isset($_GET['delete_receipt']) && isset($_GET['trade_id']) && isset($_GET['file'])) {
    
    $trade_id = (int) $_GET['trade_id'];
    $file_to_delete = $_GET['file'];
    
    // Check if approved
    $stmt = $db->prepare("SELECT is_approved FROM numeric_trade_receipts WHERE trade_id = ? AND trade_type = 'trade'");
    $stmt->execute([$trade_id]);
    $record = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($record && $record['is_approved'] == 1) {
        $_SESSION['alert'] = ['Cannot delete approved receipts.', 'warning'];
    } else {
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
    }
    
    header('Location: order_sheet.php?tab=numeric');
    exit;
}

// ============================================
// HANDLE COMMENT
// ============================================
if (isset($_POST['add_comment']) && isset($_POST['trade_id'])) {
    
    $trade_id = (int) $_POST['trade_id'];
    $comment = trim($_POST['trader_comment'] ?? '');
    
    if (empty($comment)) {
        $_SESSION['alert'] = ['Please enter a comment.', 'danger'];
    } else {
        $stmt = $db->prepare("INSERT INTO numeric_trade_comments (trade_id, comment, created_by, created_at) VALUES (?, ?, ?, NOW())");
        if ($stmt->execute([$trade_id, $comment, $user_name])) {
            $_SESSION['alert'] = ['Comment added successfully.', 'success'];
        } else {
            $_SESSION['alert'] = ['Failed to add comment.', 'danger'];
        }
    }
    
    header('Location: order_sheet.php?tab=numeric');
    exit;
}

// ============================================
// GET TRADES FOR DISPLAY
// ============================================
$filter = $_GET['filter'] ?? 'pending';
$asset_class_filter = $_GET['asset_class'] ?? 'all';
$search = $_GET['search'] ?? '';

function getNumericTrades($db, $filter, $asset_class_filter, $search) {
    try {
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
                tr.payment_receipt,
                tr.is_approved,
                tr.approved_by,
                tr.approved_at,
                tr.approval_comment,
                tr.uploaded_by as receipt_uploaded_by,
                tr.created_at as receipt_created_at,
                tr.updated_at as receipt_updated_at,
                (SELECT GROUP_CONCAT(DISTINCT comment ORDER BY created_at DESC SEPARATOR '|||') 
                 FROM numeric_trade_comments WHERE trade_id = t.id) as comments,
                (SELECT GROUP_CONCAT(DISTINCT created_by ORDER BY created_at DESC SEPARATOR '|||') 
                 FROM numeric_trade_comments WHERE trade_id = t.id) as comment_authors,
                (SELECT GROUP_CONCAT(DISTINCT created_at ORDER BY created_at DESC SEPARATOR '|||') 
                 FROM numeric_trade_comments WHERE trade_id = t.id) as comment_dates
            FROM trades t
            LEFT JOIN numeric_trade_receipts tr ON t.id = tr.trade_id AND tr.trade_type = 'trade'
            WHERE 1=1
        ";
        
        $params = [];
        
        // Numeric reference condition
        $sql .= " AND t.additional_reference REGEXP '^[0-9]+$'";
        $sql .= " AND t.additional_reference IS NOT NULL";
        $sql .= " AND t.additional_reference != ''";
        
        // Asset class conditions
        if ($asset_class_filter === 'all') {
            $sql .= " AND ((t.asset_class = 'bond') OR (t.asset_class IN ('equity', 'Exchange Traded Funds') AND LOWER(t.trade_side) = 'buy'))";
        } elseif ($asset_class_filter === 'bond') {
            $sql .= " AND t.asset_class = 'bond'";
        } elseif ($asset_class_filter === 'equity') {
            $sql .= " AND t.asset_class = 'equity' AND LOWER(t.trade_side) = 'buy'";
        } elseif ($asset_class_filter === 'etf') {
            $sql .= " AND t.asset_class = 'Exchange Traded Funds' AND LOWER(t.trade_side) = 'buy'";
        }
        
        // Status filter
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
        
        $sql .= " ORDER BY t.trade_date DESC, t.id DESC";
        
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
        
    } catch (Exception $e) {
        error_log("Error getting trades: " . $e->getMessage());
        return [];
    }
}

function getNumericStats($db) {
    $stats = ['total' => 0, 'pending' => 0, 'approved' => 0, 'rejected' => 0, 'bond' => 0, 'equity' => 0, 'etf' => 0];
    
    try {
        $sql = "
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
            AND ((t.asset_class = 'bond') OR (t.asset_class IN ('equity', 'Exchange Traded Funds') AND LOWER(t.trade_side) = 'buy'))
        ";
        $result = $db->query($sql)->fetch(PDO::FETCH_ASSOC);
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
        $sql = "
            SELECT asset_class, COUNT(*) as count
            FROM trades t
            LEFT JOIN numeric_trade_receipts tr ON t.id = tr.trade_id AND tr.trade_type = 'trade'
            WHERE t.additional_reference REGEXP '^[0-9]+$'
            AND t.additional_reference IS NOT NULL
            AND t.additional_reference != ''
            AND ((t.asset_class = 'bond') OR (t.asset_class IN ('equity', 'Exchange Traded Funds') AND LOWER(t.trade_side) = 'buy'))
            GROUP BY asset_class
        ";
        $stmt = $db->query($sql);
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

$trades = getNumericTrades($db, $filter, $asset_class_filter, $search);
$stats = getNumericStats($db);

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
    .btn-back {
        margin-bottom: 15px;
    }
</style>

<div class="container-fluid">
    <!-- Back Button -->
    <div class="row">
        <div class="col-12">
            <a href="order_sheet.php?tab=numeric" class="btn btn-secondary btn-sm btn-back">
                <i class="bi bi-arrow-left"></i> Back to Order Sheet
            </a>
        </div>
    </div>

    <!-- Page Header -->
    <div class="row mb-3">
        <div class="col-12">
            <h2><i class="bi bi-receipt"></i> Numeric Reference Receipt Upload</h2>
            <p class="text-muted">Upload receipts for trades where Additional Reference is a number only</p>
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

    <!-- Stats -->
    <div class="row mb-3">
        <div class="col-md-2"><div class="card"><div class="card-body text-center"><h5><?php echo number_format($stats['total']); ?></h5><small class="text-muted">Total</small></div></div></div>
        <div class="col-md-2"><div class="card border-warning"><div class="card-body text-center"><h5><?php echo number_format($stats['pending']); ?></h5><small class="text-muted">Pending</small></div></div></div>
        <div class="col-md-2"><div class="card border-success"><div class="card-body text-center"><h5><?php echo number_format($stats['approved']); ?></h5><small class="text-muted">Approved</small></div></div></div>
        <div class="col-md-2"><div class="card border-danger"><div class="card-body text-center"><h5><?php echo number_format($stats['rejected']); ?></h5><small class="text-muted">Rejected</small></div></div></div>
        <div class="col-md-4"><div class="card"><div class="card-body text-center"><h5><span class="badge bg-primary">B: <?php echo $stats['bond']; ?></span> <span class="badge bg-success">E: <?php echo $stats['equity']; ?></span> <span class="badge bg-warning">F: <?php echo $stats['etf']; ?></span></h5><small class="text-muted">Breakdown</small></div></div></div>
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
                <div class="text-center py-5"><p class="text-muted">No trades found with numeric Additional Reference.</p></div>
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
                                    <td class="text-end fw-bold"><?php echo $displayValue; ?></td>
                                    <td><?php echo date('d/m/Y', strtotime($trade['trade_date'] ?? '')); ?></td>
                                    <td><code class="small"><?php echo htmlspecialchars($trade['additional_reference'] ?? ''); ?></code></td>
                                    <td>
                                        <?php if ($hasReceipt): ?>
                                            <div class="receipt-thumbnails">
                                                <?php foreach ($receipts as $receiptFile):
                                                    $receiptFile = trim($receiptFile);
                                                    if (empty($receiptFile)) continue;
                                                    $filepath = '../uploads/numeric_receipts/' . $receiptFile;
                                                    if (file_exists($filepath)):
                                                        $ext = strtolower(pathinfo($receiptFile, PATHINFO_EXTENSION));
                                                        if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif'])):
                                                ?>
                                                    <img src="<?php echo $filepath; ?>" alt="Receipt" onclick="window.open('<?php echo $filepath; ?>', '_blank')" title="Click to view">
                                                <?php else: ?>
                                                    <span class="pdf-badge" onclick="window.open('<?php echo $filepath; ?>', '_blank')">PDF</span>
                                                <?php endif; endif; ?>
                                                <?php endforeach; ?>
                                                <?php if (count($receipts) > 3): ?>
                                                    <span class="badge bg-primary" style="font-size:10px;">+<?php echo count($receipts) - 3; ?></span>
                                                <?php endif; ?>
                                            </div>
                                        <?php else: ?>
                                            <span class="text-muted small">No files</span>
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

<!-- Upload Modal -->
<div class="modal fade" id="uploadModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-upload"></i> Upload Receipt</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" enctype="multipart/form-data" action="numeric_receipt_upload.php">
                <div class="modal-body">
                    <input type="hidden" name="trade_id" id="upload_trade_id" value="">
                    <input type="hidden" name="upload_receipt" value="1">
                    
                    <div class="mb-3">
                        <label class="form-label">Select Receipt Files</label>
                        <input type="file" class="form-control" name="payment_receipts[]" accept="image/*,.pdf" multiple required>
                        <div class="form-text">Allowed: JPG, PNG, GIF, PDF (Max 5MB each)</div>
                    </div>
                    
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle"></i> Upload clear copies of payment receipts or confirmations. Multiple files allowed.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success">Upload</button>
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
                    
                    <div class="mb-3">
                        <label class="form-label">Your Comment</label>
                        <textarea class="form-control" name="trader_comment" rows="4" placeholder="Enter your comment..." required></textarea>
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
function openUploadModal(tradeId) {
    document.getElementById('upload_trade_id').value = tradeId;
    const modal = new bootstrap.Modal(document.getElementById('uploadModal'));
    modal.show();
}

function openCommentModal(tradeId) {
    document.getElementById('comment_trade_id').value = tradeId;
    const modal = new bootstrap.Modal(document.getElementById('commentModal'));
    modal.show();
}
</script>

<?php include '../includes/footer.php'; ?>
