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

// Detect if POST data was truncated due to exceeding post_max_size
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST) && empty($_FILES)) {
    $content_length = $_SERVER['CONTENT_LENGTH'] ?? 0;
    $post_max_size = ini_get('post_max_size');
    $_SESSION['alert'] = ["Upload failed: The uploaded file ($content_length bytes) exceeds the server's maximum upload size ($post_max_size).", 'danger'];
    session_write_close();
    header('Location: order_sheet.php');
    exit;
}

// Check user permissions - finance, admin, and traders can access
$user_role = $_SESSION['role'] ?? '';
$allowed_roles = ['finance_officer', 'system_admin', 'trader'];
require_login();
if (!in_array($user_role, $allowed_roles)) {
    show_alert('Access denied.', 'danger');
    redirect('auth/login.php');
    exit;
}
if ($user_role !== 'system_admin') {
    require_mandate();
}

$db = getDBConnection();
$current_user = get_logged_in_user() ?: get_session_user();
$user_name = $current_user['username'] ?? 'System';
$user_id = $current_user['id'] ?? null;

function receiptFileUrl($ref) {
    if (strpos($ref, 'db_') === 0) {
        $id = (int)substr($ref, 3);
        $parts = explode('.', $ref);
        $ext = count($parts) > 1 ? end($parts) : '';
        return 'serve_receipt.php?id=' . $id . ($ext ? '&ext=' . $ext : '');
    }
    return '../uploads/numeric_receipts/' . $ref;
}

function receiptFileExists($ref) {
    if (strpos($ref, 'db_') === 0) return true;
    return file_exists(__DIR__ . '/../uploads/numeric_receipts/' . $ref);
}

function receiptIsImage($ref) {
    if (strpos($ref, 'db_') === 0) {
        $parts = explode('.', $ref);
        $ext = count($parts) > 1 ? strtolower(end($parts)) : '';
        return in_array($ext, ['jpg', 'jpeg', 'png', 'gif']);
    }
    $ext = strtolower(pathinfo($ref, PATHINFO_EXTENSION));
    return in_array($ext, ['jpg', 'jpeg', 'png', 'gif']);
}

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
                commission_receipt TEXT,
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
    
    $checkColumn = $db->query("SHOW COLUMNS FROM numeric_trade_receipts LIKE 'commission_receipt'");
    if ($checkColumn->rowCount() == 0) {
        $db->exec("ALTER TABLE numeric_trade_receipts ADD COLUMN commission_receipt TEXT AFTER payment_receipt");
        error_log("Added commission_receipt column to numeric_trade_receipts");
    }
    
    $checkColumn = $db->query("SHOW COLUMNS FROM numeric_trade_receipts LIKE 'comment'");
    if ($checkColumn->rowCount() == 0) {
        $db->exec("ALTER TABLE numeric_trade_receipts ADD COLUMN comment TEXT AFTER commission_receipt");
        error_log("Added comment column to numeric_trade_receipts");
    }
    
    $checkColumn = $db->query("SHOW COLUMNS FROM trades LIKE 'approval_status'");
    if ($checkColumn->rowCount() == 0) {
        $db->exec("ALTER TABLE trades ADD COLUMN approval_status ENUM('pending','approved','rejected') DEFAULT 'pending' AFTER status");
        error_log("Added approval_status column to trades table");
    }
    
    // Check if linked_trade_id exists in trades table
    $checkColumn = $db->query("SHOW COLUMNS FROM trades LIKE 'linked_trade_id'");
    if ($checkColumn->rowCount() == 0) {
        $db->exec("ALTER TABLE trades ADD COLUMN linked_trade_id INT DEFAULT NULL AFTER settlement_status");
        error_log("Added linked_trade_id column to trades table");
    }
    
    $checkColumn = $db->query("SHOW COLUMNS FROM trades LIKE 'linked_trade_ref'");
    if ($checkColumn->rowCount() == 0) {
        $db->exec("ALTER TABLE trades ADD COLUMN linked_trade_ref VARCHAR(50) DEFAULT NULL AFTER linked_trade_id");
        error_log("Added linked_trade_ref column to trades table");
    }
    
    // Auto-create receipt_files table for DB-based receipt storage
    $checkTable = $db->query("SHOW TABLES LIKE 'receipt_files'");
    if ($checkTable->rowCount() == 0) {
        $createTable = "
            CREATE TABLE receipt_files (
                id INT AUTO_INCREMENT PRIMARY KEY,
                trade_id INT NOT NULL,
                receipt_type VARCHAR(20) NOT NULL DEFAULT 'payment',
                file_data LONGBLOB NOT NULL,
                file_name VARCHAR(255) NOT NULL,
                file_size INT NOT NULL DEFAULT 0,
                mime_type VARCHAR(100) NOT NULL DEFAULT '',
                uploaded_by VARCHAR(100) DEFAULT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                KEY trade_id (trade_id)
            )
        ";
        $db->exec($createTable);
        error_log("Created receipt_files table");
    }
    
} catch (Exception $e) {
    error_log("Table setup error: " . $e->getMessage());
}

// ============================================
// AJAX: VIEW TRADE DETAILS
// ============================================
if (isset($_GET['ajax']) && $_GET['ajax'] === 'view_trade' && isset($_GET['id'])) {
    header('Content-Type: application/json');
    try {
        $tid = (int)$_GET['id'];
        $stmt = $db->prepare("
            SELECT t.*, 
                   tr.is_approved, tr.approved_by, tr.approved_at,
                   tr.payment_receipt, tr.commission_receipt, tr.comment as receipt_comment,
                   lt.linked_trade_id, lt2.trade_reference as linked_trade_ref
            FROM trades t
            LEFT JOIN numeric_trade_receipts tr ON t.id = tr.trade_id AND tr.trade_type = 'trade'
            LEFT JOIN linked_trades lt ON t.id = lt.trade_id
            LEFT JOIN trades lt2 ON lt.linked_trade_id = lt2.id
            WHERE t.id = ?
        ");
        $stmt->execute([$tid]);
        $trade = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($trade) {
            echo json_encode(['success' => true, 'trade' => $trade]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Trade not found']);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ============================================
// HANDLE APPROVAL
// ============================================

if (isset($_POST['approve_trade']) && isset($_POST['trade_id'])) {
    $trade_id = (int)$_POST['trade_id'];
    $approve_action = $_POST['approve_action'] ?? 'approve';
    $is_approved = ($approve_action === 'approve') ? 1 : 2;
    $admin_username = $_SESSION['username'] ?? 'System';
    
    try {
        $stmt = $db->prepare("SELECT id FROM numeric_trade_receipts WHERE trade_id = ? AND trade_type = 'trade'");
        $stmt->execute([$trade_id]);
        $existing = $stmt->fetch();
        
        if ($existing) {
            $stmt = $db->prepare("UPDATE numeric_trade_receipts SET is_approved = ?, approved_by = ?, approved_at = NOW() WHERE trade_id = ? AND trade_type = 'trade'");
            $stmt->execute([$is_approved, $admin_username, $trade_id]);
        } else {
            $stmt = $db->prepare("INSERT INTO numeric_trade_receipts (trade_id, trade_type, is_approved, approved_by, approved_at) VALUES (?, 'trade', ?, ?, NOW())");
            $stmt->execute([$trade_id, $is_approved, $admin_username]);
        }
        
        $approval_status = ($approve_action === 'approve') ? 'approved' : 'rejected';
        $db->prepare("UPDATE trades SET approval_status = ? WHERE id = ?")->execute([$approval_status, $trade_id]);
        
        $_SESSION['alert'] = [ucfirst($approve_action) . 'd successfully', 'success'];
    } catch (Exception $e) {
        error_log("Approval error: " . $e->getMessage());
        $_SESSION['alert'] = ['Error processing approval', 'danger'];
    }
    
    session_write_close();
    header('Location: order_sheet.php' . (!empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : ''));
    exit;
}

// ============================================
// HANDLE UPLOAD
// ============================================

// Handle Receipt Upload
if (isset($_POST['upload_receipt']) && isset($_POST['trade_id'])) {
    error_log("Upload handler entered for trade_id=" . $_POST['trade_id'] . ", files=" . print_r($_FILES['payment_receipts']['name'] ?? [], true));
    try {
        $trade_id = (int) $_POST['trade_id'];
        $uploaded_files = [];
        $errors = [];
        $comment = trim($_POST['receipt_comment'] ?? '');
        $receipt_type = $_POST['receipt_type'] ?? 'payment';
        
        $allowed_exts = ['jpg', 'jpeg', 'png', 'gif', 'pdf'];
        $max_size = 5 * 1024 * 1024;
        
        // Get existing receipts and comment
        $stmt = $db->prepare("SELECT payment_receipt, commission_receipt, comment FROM numeric_trade_receipts WHERE trade_id = ? AND trade_type = 'trade'");
        $stmt->execute([$trade_id]);
        $existing_record = $stmt->fetch(PDO::FETCH_ASSOC);
        $existing_payment_receipts = !empty($existing_record['payment_receipt']) ? explode(',', $existing_record['payment_receipt']) : [];
        $existing_commission_receipts = !empty($existing_record['commission_receipt']) ? explode(',', $existing_record['commission_receipt']) : [];
        $existing_comment = $existing_record['comment'] ?? '';
        
        // Determine which field to update
        if ($receipt_type === 'commission') {
            $existing_receipts = $existing_commission_receipts;
            $field_name = 'commission_receipt';
        } else {
            $existing_receipts = $existing_payment_receipts;
            $field_name = 'payment_receipt';
        }
        
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
                
                $file_data = file_get_contents($files['tmp_name'][$i]);
                $mime_type = $files['type'][$i] ?: 'application/octet-stream';
                $orig_name = $files['name'][$i];
                
                $stmt = $db->prepare("INSERT INTO receipt_files (trade_id, receipt_type, file_data, file_name, file_size, mime_type, uploaded_by) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$trade_id, $receipt_type, $file_data, $orig_name, $files['size'][$i], $mime_type, $user_name]);
                $file_id = $db->lastInsertId();
                $uploaded_files[] = 'db_' . $file_id . '.' . $file_ext;
            }
            
            $full_comment = $existing_comment;
            if (!empty($comment)) {
                $timestamp = date('Y-m-d H:i:s');
                $full_comment = $existing_comment 
                    ? $existing_comment . "\n---\n[" . $timestamp . "] " . $user_name . ": " . $comment 
                    : "[" . $timestamp . "] " . $user_name . ": " . $comment;
            }
            
            if (!empty($uploaded_files)) {
                $all_receipts = array_merge($existing_receipts, $uploaded_files);
                $receipts_str = implode(',', $all_receipts);
                
                $stmt = $db->prepare("SELECT id FROM numeric_trade_receipts WHERE trade_id = ? AND trade_type = 'trade'");
                $stmt->execute([$trade_id]);
                if ($stmt->fetch()) {
                    $stmt = $db->prepare("UPDATE numeric_trade_receipts SET $field_name = ?, comment = ?, updated_at = NOW(), uploaded_by = ? WHERE trade_id = ? AND trade_type = 'trade'");
                    $result = $stmt->execute([$receipts_str, $full_comment, $user_name, $trade_id]);
                } else {
                    $stmt = $db->prepare("INSERT INTO numeric_trade_receipts (trade_id, trade_type, $field_name, comment, uploaded_by, created_at, updated_at) VALUES (?, 'trade', ?, ?, ?, NOW(), NOW())");
                    $result = $stmt->execute([$trade_id, $receipts_str, $full_comment, $user_name]);
                }
                
                if ($result) {
                    $message = [];
                    if (!empty($uploaded_files)) $message[] = count($uploaded_files) . ' receipt(s) uploaded';
                    if (!empty($comment)) $message[] = 'comment added';
                    $_SESSION['alert'] = [implode(' and ', $message) . ' successfully!', 'success'];
                } else {
                    $_SESSION['alert'] = ['Failed to update numeric_trade_receipts', 'danger'];
                }
            } else {
                $_SESSION['alert'] = ['No files were uploaded successfully. Errors: ' . implode('; ', $errors), 'danger'];
            }
        } else {
            if (!empty($comment)) {
                $stmt = $db->prepare("SELECT comment FROM numeric_trade_receipts WHERE trade_id = ? AND trade_type = 'trade'");
                $stmt->execute([$trade_id]);
                $existing = $stmt->fetch(PDO::FETCH_ASSOC);
                $existing_comment = $existing['comment'] ?? '';
                
                $timestamp = date('Y-m-d H:i:s');
                $full_comment = $existing_comment 
                    ? $existing_comment . "\n---\n[" . $timestamp . "] " . $user_name . ": " . $comment 
                    : "[" . $timestamp . "] " . $user_name . ": " . $comment;
                
                $stmt = $db->prepare("SELECT id FROM numeric_trade_receipts WHERE trade_id = ? AND trade_type = 'trade'");
                $stmt->execute([$trade_id]);
                if ($stmt->fetch()) {
                    $stmt = $db->prepare("UPDATE numeric_trade_receipts SET comment = ?, updated_at = NOW(), uploaded_by = ? WHERE trade_id = ? AND trade_type = 'trade'");
                    $result = $stmt->execute([$full_comment, $user_name, $trade_id]);
                } else {
                    $stmt = $db->prepare("INSERT INTO numeric_trade_receipts (trade_id, trade_type, comment, uploaded_by, created_at, updated_at) VALUES (?, 'trade', ?, ?, NOW(), NOW())");
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
    } catch (Exception $e) {
        $_SESSION['alert'] = ['Upload error: ' . $e->getMessage(), 'danger'];
        error_log("Receipt upload error: " . $e->getMessage());
    }
    session_write_close();
    header('Location: order_sheet.php?' . http_build_query(array_filter([
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
        session_write_close();
        header('Location: order_sheet.php?' . http_build_query(array_filter([
            'filter' => $_GET['filter'] ?? 'pending',
            'asset_class' => $_GET['asset_class'] ?? 'all',
            'search' => $_GET['search'] ?? ''
        ])));
        exit;
    }
    
    $stmt = $db->prepare("SELECT payment_receipt, commission_receipt FROM numeric_trade_receipts WHERE trade_id = ? AND trade_type = 'trade'");
    $stmt->execute([$trade_id]);
    $record = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($record) {
        $field_to_update = null;
        $receipts_array = [];
        
        if (!empty($record['payment_receipt'])) {
            $receipts = explode(',', $record['payment_receipt']);
            if (($key = array_search($file_to_delete, $receipts)) !== false) {
                $field_to_update = 'payment_receipt';
                unset($receipts[$key]);
                $receipts_array = $receipts;
            }
        }
        
        if (!$field_to_update && !empty($record['commission_receipt'])) {
            $receipts = explode(',', $record['commission_receipt']);
            if (($key = array_search($file_to_delete, $receipts)) !== false) {
                $field_to_update = 'commission_receipt';
                unset($receipts[$key]);
                $receipts_array = $receipts;
            }
        }
        
        if ($field_to_update) {
            if (strpos($file_to_delete, 'db_') === 0) {
                $parts = explode('.', $file_to_delete);
                $file_id = (int) substr($parts[0], 3);
                $stmt = $db->prepare("DELETE FROM receipt_files WHERE id = ? AND trade_id = ?");
                $stmt->execute([$file_id, $trade_id]);
            } else {
                $filepath = __DIR__ . '/../uploads/numeric_receipts/' . $file_to_delete;
                if (file_exists($filepath)) {
                    unlink($filepath);
                }
            }
            
            $receipts_str = !empty($receipts_array) ? implode(',', $receipts_array) : null;
            $stmt = $db->prepare("UPDATE numeric_trade_receipts SET $field_to_update = ?, updated_at = NOW() WHERE trade_id = ? AND trade_type = 'trade'");
            if ($stmt->execute([$receipts_str, $trade_id])) {
                $_SESSION['alert'] = ['Receipt deleted successfully.', 'success'];
            }
        }
    }
    session_write_close();
    header('Location: order_sheet.php?' . http_build_query(array_filter([
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
    
    session_write_close();
    header('Location: order_sheet.php?' . http_build_query(array_filter([
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
// FETCH TRADES - GROUPED BY CLIENT, SECURITY, DATE
// ============================================
function getNumericTrades($db, $filter = 'pending', $asset_class_filter = 'all', $search = '') {
    $sql = "
        SELECT 
            MIN(t.id) as id,
            MIN(t.trade_reference) as trade_reference,
            t.asset_class,
            t.security_id,
            t.security_name,
            t.client_name,
            t.client_cds_account,
            t.trade_side,
            SUM(t.quantity) as quantity,
            AVG(t.price) as price,
            SUM(t.consideration) as consideration,
            DATE(t.trade_date) as trade_date,
            MIN(t.settlement_date) as settlement_date,
            MIN(t.exchange_reference) as exchange_reference,
            MIN(t.additional_reference) as additional_reference,
            MIN(t.uploaded_by) as uploaded_by,
            MIN(t.status) as status,
            MIN(t.created_at) as created_at,
            MAX(t.settlement_status) as settlement_status,
            MAX(t.linked_trade_id) as linked_trade_id,
            MAX(t.linked_trade_ref) as linked_trade_ref,
            MAX(tr.payment_receipt) as payment_receipt,
            MAX(tr.commission_receipt) as commission_receipt,
            MAX(tr.comment) as receipt_comment,
            MAX(tr.is_approved) as is_approved,
            MAX(tr.approved_by) as approved_by,
            MAX(tr.approved_at) as approved_at,
            MAX(tr.approval_comment) as approval_comment,
            MAX(tr.uploaded_by) as receipt_uploaded_by,
            MAX(tr.created_at) as receipt_created_at,
            MAX(tr.updated_at) as receipt_updated_at,
            COUNT(t.id) as trade_count
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
    
    $sql .= " GROUP BY 
                t.client_name, 
                t.client_cds_account,
                t.security_id,
                t.security_name,
                t.asset_class,
                t.trade_side,
                DATE(t.trade_date)
              ORDER BY trade_date DESC";
    
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
                COUNT(DISTINCT CONCAT(client_name, '|', security_id, '|', DATE(trade_date))) as total,
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
                COUNT(DISTINCT CONCAT(client_name, '|', security_id, '|', DATE(trade_date))) as count
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
    /* Receipt Thumbnails */
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
        border: 1px solid #ddd;
        border-radius: 4px;
        overflow: hidden;
        cursor: pointer;
    }
    .receipt-thumbnails .thumbnail:hover {
        border-color: #666;
        box-shadow: 0 2px 8px rgba(0,0,0,0.15);
    }
    .receipt-thumbnails .thumbnail .view-overlay {
        position: absolute;
        top: 0; left: 0; right: 0; bottom: 0;
        background: rgba(0,0,0,0.4);
        display: flex;
        align-items: center;
        justify-content: center;
        opacity: 0;
        transition: opacity 0.2s;
        color: white;
        font-size: 20px;
    }
    .receipt-thumbnails .thumbnail:hover .view-overlay {
        opacity: 1;
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
        color: #666;
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
        box-shadow: 0 1px 3px rgba(0,0,0,0.3);
        z-index: 2;
    }
    .receipt-thumbnails .delete-btn:hover {
        transform: scale(1.1);
        color: white;
    }
    
    .receipt-thumbnails .more-badge {
        background: #6c757d;
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
    
    .receipt-type-label {
        font-size: 10px;
        padding: 1px 6px;
        border-radius: 3px;
        background: #e9ecef;
        color: #495057;
        margin-left: 2px;
    }
    .receipt-type-label.payment {
        background: #d4edda;
        color: #155724;
    }
    .receipt-type-label.commission {
        background: #fff3cd;
        color: #856404;
    }
    
    /* Linked Badge */
    .badge-linked {
        background: #6f42c1;
        color: white;
        padding: 3px 10px;
        border-radius: 12px;
        font-size: 11px;
        font-weight: 600;
    }
    .badge-linked i {
        margin-right: 4px;
    }
    
    /* Contract Note Buttons */
    .btn-contract {
        font-size: 11px;
        padding: 3px 8px;
    }
    .btn-contract i {
        margin-right: 3px;
    }
    
    .comment-display {
        background: #f8f9fa;
        border-left: 2px solid #6c757d;
        padding: 6px 10px;
        border-radius: 3px;
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
    
    .stat-box {
        background: #f8f9fa;
        border: 1px solid #ddd;
        border-radius: 4px;
        padding: 8px 12px;
        text-align: center;
    }
    .stat-box .stat-number {
        font-size: 20px;
        font-weight: 600;
        color: #333;
    }
    .stat-box .stat-label {
        font-size: 11px;
        color: #666;
        margin-top: 2px;
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
    
    .badge-status {
        font-size: 11px;
        padding: 3px 8px;
        border-radius: 3px;
    }
    .badge-status.pending {
        background: #f8f9fa;
        color: #856404;
        border: 1px solid #ffc107;
    }
    .badge-status.approved {
        background: #f8f9fa;
        color: #155724;
        border: 1px solid #28a745;
    }
    .badge-status.rejected {
        background: #f8f9fa;
        color: #721c24;
        border: 1px solid #dc3545;
    }
    
    .badge-asset {
        font-size: 11px;
        padding: 3px 8px;
        border-radius: 3px;
        background: #f8f9fa;
        border: 1px solid #ddd;
        color: #333;
    }
    
    .trade-count-badge {
        background: #e9ecef;
        color: #495057;
        font-size: 10px;
        padding: 1px 6px;
        border-radius: 10px;
        margin-left: 4px;
    }
    
    /* File Upload */
    .file-item {
        display: flex;
        justify-content: space-between;
        padding: 4px 8px;
        background: #f8f9fa;
        border-radius: 3px;
        margin-bottom: 2px;
        font-size: 13px;
    }
    .file-item .file-size {
        color: #999;
        font-size: 11px;
    }
    .receipt-preview-container img {
        max-width: 80px;
        max-height: 60px;
        object-fit: cover;
        border: 1px solid #ddd;
        border-radius: 3px;
        margin: 2px;
    }
</style>

<div class="container-fluid">
    <!-- Page Header -->
    <div class="row mb-3">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h4 class="mb-0">
                        <i class="bi bi-receipt"></i> Numeric Reference Receipt Upload
                    </h4>
                    <small class="text-muted">Additional Reference = Number only (Grouped by Client, Security, Date)</small>
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

    <!-- Small Stat Boxes -->
    <div class="row mb-3 g-2">
        <div class="col-md-2 col-4">
            <div class="stat-box">
                <div class="stat-number"><?php echo number_format($stats['total']); ?></div>
                <div class="stat-label">Total</div>
            </div>
        </div>
        <div class="col-md-2 col-4">
            <div class="stat-box">
                <div class="stat-number"><?php echo number_format($stats['pending']); ?></div>
                <div class="stat-label">Pending</div>
            </div>
        </div>
        <div class="col-md-2 col-4">
            <div class="stat-box">
                <div class="stat-number"><?php echo number_format($stats['approved']); ?></div>
                <div class="stat-label">Approved</div>
            </div>
        </div>
        <div class="col-md-2 col-4">
            <div class="stat-box">
                <div class="stat-number"><?php echo number_format($stats['rejected']); ?></div>
                <div class="stat-label">Rejected</div>
            </div>
        </div>
        <div class="col-md-4 col-8">
            <div class="stat-box">
                <div class="stat-number">
                    <span class="badge-asset">B: <?php echo $stats['bond']; ?></span>
                    <span class="badge-asset">E: <?php echo $stats['equity']; ?></span>
                    <span class="badge-asset">F: <?php echo $stats['etf']; ?></span>
                </div>
                <div class="stat-label">Breakdown</div>
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
                <small class="text-muted ms-2">(Grouped by Client, Security, Date)</small>
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
                                <th>Status / Receipts</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($trades as $trade): 
                                $isBond = ($trade['asset_class'] === 'bond');
                                $payment_receipts = !empty($trade['payment_receipt']) ? explode(',', $trade['payment_receipt']) : [];
                                $commission_receipts = !empty($trade['commission_receipt']) ? explode(',', $trade['commission_receipt']) : [];
                                $hasPaymentReceipt = !empty($payment_receipts) && !empty($payment_receipts[0]);
                                $hasCommissionReceipt = !empty($commission_receipts) && !empty($commission_receipts[0]);
                                $hasComment = !empty($trade['receipt_comment']);
                                $commentText = $trade['receipt_comment'] ?? '';
                                $tradeCount = (int)($trade['trade_count'] ?? 1);
                                $isLinked = ($trade['settlement_status'] === 'linked' && !empty($trade['linked_trade_id']));
                                
                                $isApproved = isset($trade['is_approved']) ? (int)$trade['is_approved'] : 0;
                                $statusText = $isApproved === 1 ? 'Approved' : ($isApproved === 2 ? 'Rejected' : 'Pending');
                                $statusClass = $isApproved === 1 ? 'approved' : ($isApproved === 2 ? 'rejected' : 'pending');
                                
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
                                    <td>
                                        <span class="fw-semibold small"><?php echo htmlspecialchars($trade['trade_reference'] ?? ''); ?></span>
                                        <?php if ($tradeCount > 1): ?>
                                            <span class="trade-count-badge" title="<?php echo $tradeCount; ?> trades grouped together">
                                                <i class="bi bi-layers"></i> <?php echo $tradeCount; ?>x
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($trade['client_name'] ?? ''); ?></td>
                                    <td><?php echo htmlspecialchars($trade['security_id'] ?? ''); ?></td>
                                    <td><span class="badge-asset"><?php echo $assetClass; ?></span></td>
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
                                        <?php if ($isLinked): ?>
                                            <!-- LINKED STATUS -->
                                            <div class="mb-1">
                                                <span class="badge-linked">
                                                    <i class="bi bi-link-45deg"></i> LINKED
                                                </span>
                                            </div>
                                            <div class="small text-muted mb-1">
                                                <i class="bi bi-arrow-right"></i> 
                                                Linked to: <strong><?php echo htmlspecialchars($trade['linked_trade_ref'] ?? 'Trade #' . $trade['linked_trade_id']); ?></strong>
                                            </div>
                                            <!-- Contract Note Buttons -->
                                            <div class="btn-group btn-group-sm mt-1" role="group">
                                                <a href="trades.php?action=contract_note&id=<?php echo $trade['id']; ?>" 
                                                   class="btn btn-outline-primary btn-contract" target="_blank" 
                                                   title="Contract Note for Sold Shares">
                                                    <i class="bi bi-file-earmark-text"></i> Sold
                                                </a>
                                                <a href="trades.php?action=contract_note&id=<?php echo $trade['linked_trade_id']; ?>" 
                                                   class="btn btn-outline-success btn-contract" target="_blank" 
                                                   title="Contract Note for Bought Shares">
                                                    <i class="bi bi-file-earmark-text"></i> Bought
                                                </a>
                                            </div>
                                        <?php else: ?>
                                            <!-- Payment Receipts -->
                                            <?php if ($hasPaymentReceipt): ?>
                                                <div class="receipt-thumbnails">
                                                    <?php 
                                                    $displayCount = 0;
                                                    foreach ($payment_receipts as $receiptFile):
                                                        $receiptFile = trim($receiptFile);
                                                        if (empty($receiptFile)) continue;
                                                        if ($displayCount >= 3) break;
                                                        $displayCount++;
                                                        $filepath = receiptFileUrl($receiptFile);
                                                        $isImage = receiptIsImage($receiptFile);
                                                        $fileExists = receiptFileExists($receiptFile);
                                                    ?>
                                                        <div class="thumbnail" onclick="viewReceipt('<?php echo $filepath; ?>')" title="Click to view">
                                                            <?php if ($isImage && $fileExists): ?>
                                                                <img src="<?php echo $filepath; ?>" alt="Receipt">
                                                            <?php else: ?>
                                                                <div class="file-icon">
                                                                    <i class="bi bi-file-pdf"></i>
                                                                </div>
                                                            <?php endif; ?>
                                                            <div class="view-overlay"><i class="bi bi-eye"></i></div>
                                                            <?php if ($isApproved !== 1): ?>
                                                                <a href="order_sheet.php?delete_receipt=1&trade_id=<?php echo $trade['id']; ?>&file=<?php echo urlencode($receiptFile); ?>&filter=<?php echo urlencode($filter); ?>&asset_class=<?php echo urlencode($asset_class_filter); ?>&search=<?php echo urlencode($search); ?>" 
                                                                   class="delete-btn" onclick="event.stopPropagation(); return confirm('Delete this file?')">
                                                                    <i class="bi bi-x"></i>
                                                                </a>
                                                            <?php endif; ?>
                                                        </div>
                                                    <?php endforeach; ?>
                                                    <?php if (count($payment_receipts) > 3): ?>
                                                        <div class="more-badge">+<?php echo count($payment_receipts) - 3; ?></div>
                                                    <?php endif; ?>
                                                </div>
                                                <small class="receipt-type-label payment">Payment</small>
                                            <?php endif; ?>
                                            
                                            <!-- Commission Receipts (for Bonds) -->
                                            <?php if ($isBond && $hasCommissionReceipt): ?>
                                                <div class="receipt-thumbnails mt-1">
                                                    <?php 
                                                    $displayCount = 0;
                                                    foreach ($commission_receipts as $receiptFile):
                                                        $receiptFile = trim($receiptFile);
                                                        if (empty($receiptFile)) continue;
                                                        if ($displayCount >= 3) break;
                                                        $displayCount++;
                                                        $filepath = receiptFileUrl($receiptFile);
                                                        $isImage = receiptIsImage($receiptFile);
                                                        $fileExists = receiptFileExists($receiptFile);
                                                    ?>
                                                        <div class="thumbnail" onclick="viewReceipt('<?php echo $filepath; ?>')" title="Click to view">
                                                            <?php if ($isImage && $fileExists): ?>
                                                                <img src="<?php echo $filepath; ?>" alt="Commission Receipt">
                                                            <?php else: ?>
                                                                <div class="file-icon">
                                                                    <i class="bi bi-file-pdf"></i>
                                                                </div>
                                                            <?php endif; ?>
                                                            <div class="view-overlay"><i class="bi bi-eye"></i></div>
                                                            <?php if ($isApproved !== 1): ?>
                                                                <a href="order_sheet.php?delete_receipt=1&trade_id=<?php echo $trade['id']; ?>&file=<?php echo urlencode($receiptFile); ?>&filter=<?php echo urlencode($filter); ?>&asset_class=<?php echo urlencode($asset_class_filter); ?>&search=<?php echo urlencode($search); ?>" 
                                                                   class="delete-btn" onclick="event.stopPropagation(); return confirm('Delete this file?')">
                                                                    <i class="bi bi-x"></i>
                                                                </a>
                                                            <?php endif; ?>
                                                        </div>
                                                    <?php endforeach; ?>
                                                    <?php if (count($commission_receipts) > 3): ?>
                                                        <div class="more-badge">+<?php echo count($commission_receipts) - 3; ?></div>
                                                    <?php endif; ?>
                                                </div>
                                                <small class="receipt-type-label commission">Commission</small>
                                            <?php endif; ?>
                                            
                                            <!-- Comment Display -->
                                            <?php if ($hasComment): ?>
                                                <div class="comment-display mt-1">
                                                    <div class="comment-text"><?php echo nl2br(htmlspecialchars($commentText)); ?></div>
                                                </div>
                                                <div class="mt-1">
                                                    <a href="order_sheet.php?delete_comment=1&trade_id=<?php echo $trade['id']; ?>&filter=<?php echo urlencode($filter); ?>&asset_class=<?php echo urlencode($asset_class_filter); ?>&search=<?php echo urlencode($search); ?>" 
                                                       class="text-danger small" onclick="return confirm('Delete this comment?')">
                                                        <i class="bi bi-trash"></i> Delete Comment
                                                    </a>
                                                </div>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($isLinked): ?>
                                            <span class="badge bg-info">
                                                <i class="bi bi-link-45deg"></i> Linked
                                            </span>
                                        <?php else: ?>
                                            <span class="badge-status <?php echo $statusClass; ?>"><?php echo $statusText; ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end">
                                        <div class="btn-group btn-group-sm" role="group">
                                            <?php if (!$isLinked && $isApproved !== 1): ?>
                                                <button class="btn btn-outline-secondary" onclick="openUploadModal(<?php echo $trade['id']; ?>, 'payment', <?php echo htmlspecialchars(json_encode($trade['payment_receipt'] ?? '')); ?>)" title="Upload Payment Receipt">
                                                    <i class="bi bi-cash"></i>
                                                </button>
                                                <?php if ($isBond): ?>
                                                    <button class="btn btn-outline-secondary" onclick="openUploadModal(<?php echo $trade['id']; ?>, 'commission', <?php echo htmlspecialchars(json_encode($trade['commission_receipt'] ?? '')); ?>)" title="Upload Commission Receipt">
                                                        <i class="bi bi-percent"></i>
                                                    </button>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                            <?php if ($user_role === 'finance_officer' && $isApproved !== 1 && !$isLinked): ?>
                                                <form method="POST" style="display:inline" onsubmit="return confirm('Approve this trade?')">
                                                    <input type="hidden" name="trade_id" value="<?php echo $trade['id']; ?>">
                                                    <input type="hidden" name="approve_trade" value="1">
                                                    <input type="hidden" name="approve_action" value="approve">
                                                    <button type="submit" class="btn btn-outline-success" title="Approve">
                                                        <i class="bi bi-check-lg"></i>
                                                    </button>
                                                </form>
                                                <form method="POST" style="display:inline" onsubmit="return confirm('Reject this trade?')">
                                                    <input type="hidden" name="trade_id" value="<?php echo $trade['id']; ?>">
                                                    <input type="hidden" name="approve_trade" value="1">
                                                    <input type="hidden" name="approve_action" value="reject">
                                                    <button type="submit" class="btn btn-outline-danger" title="Reject">
                                                        <i class="bi bi-x-lg"></i>
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                            <?php if ($isLinked): ?>
                                                <a href="trades.php?action=contract_note&id=<?php echo $trade['id']; ?>" 
                                                   class="btn btn-outline-primary btn-contract" target="_blank" 
                                                   title="Contract Note for Sold Shares">
                                                    <i class="bi bi-file-earmark-text"></i>
                                                </a>
                                                <a href="trades.php?action=contract_note&id=<?php echo $trade['linked_trade_id']; ?>" 
                                                   class="btn btn-outline-success btn-contract" target="_blank" 
                                                   title="Contract Note for Bought Shares">
                                                    <i class="bi bi-file-earmark-text"></i>
                                                </a>
                                            <?php endif; ?>
                                            <button class="btn btn-outline-secondary" onclick="viewTrade(<?php echo $trade['id']; ?>)" title="View Details">
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
<!-- UPLOAD MODAL -->
<!-- ============================================ -->
<div class="modal fade" id="uploadModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-md">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-upload me-2"></i>Upload Receipt & Add Comment</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" enctype="multipart/form-data" action="order_sheet.php" id="receiptUploadForm">
                <div class="modal-body">
                    <input type="hidden" name="trade_id" id="receipt_trade_id" value="">
                    <input type="hidden" name="upload_receipt" value="1">
                    <input type="hidden" name="receipt_type" id="receipt_type" value="payment">
                    <input type="hidden" name="filter" value="<?php echo htmlspecialchars($filter); ?>">
                    <input type="hidden" name="asset_class" value="<?php echo htmlspecialchars($asset_class_filter); ?>">
                    <input type="hidden" name="search" value="<?php echo htmlspecialchars($search); ?>">
                    
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle me-2"></i>
                        <strong id="receiptTypeLabel">Payment Receipt</strong>
                        <span id="receiptTypeDesc" class="text-muted">- Upload payment confirmation</span>
                    </div>
                    
                    <div class="text-center mb-3">
                        <div class="receipt-preview-container" id="receiptPreviews"></div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Select Receipt Files</label>
                        <div class="file-input-wrapper">
                            <input type="file" class="form-control" name="payment_receipts[]" id="receipt_files" accept="image/*,.pdf" multiple>
                            <div class="form-text">Allowed formats: JPG, PNG, GIF, PDF (Max 5MB each)</div>
                            <div class="file-list" id="fileList"></div>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Add Comment (Optional)</label>
                        <textarea class="form-control" name="receipt_comment" id="receipt_comment" rows="3" placeholder="Add a comment about this trade..."></textarea>
                    </div>
                    
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle me-2"></i>
                        You can select multiple files at once. Upload clear photos or scanned copies of payment receipts/confirmations.
                        <br><small class="text-muted">Supported: Images (JPG, PNG, GIF) and PDF documents.</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-secondary" id="uploadReceiptBtn">Upload</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Receipt Viewer Modal -->
<div class="modal fade" id="receiptViewerModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-file-earmark me-2"></i>Receipt Viewer</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body text-center" id="receiptViewerBody">
                <div id="receiptViewerContent" class="p-2"></div>
            </div>
            <div class="modal-footer justify-content-between">
                <span id="receiptViewerInfo" class="text-muted small"></span>
                <a id="receiptViewerDownload" class="btn btn-sm btn-secondary" target="_blank">
                    <i class="bi bi-box-arrow-up-right me-1"></i> Open in New Tab
                </a>
            </div>
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
                    <div class="spinner-border text-secondary" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                    <p class="text-muted mt-2">Loading trade details...</p>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// ============================================
// FUNCTIONS
// ============================================

function viewReceipt(filepath) {
    const content = document.getElementById('receiptViewerContent');
    const info = document.getElementById('receiptViewerInfo');
    const download = document.getElementById('receiptViewerDownload');
    const params = new URLSearchParams(filepath.split('?')[1] || '');
    const ext = params.get('ext') || filepath.split('.').pop().toLowerCase();
    const isImage = ['jpg','jpeg','png','gif'].includes(ext);
    
    download.href = filepath;
    info.textContent = filepath.split('/').pop();
    
    if (isImage) {
        content.innerHTML = `<img src="${filepath}" class="img-fluid" style="max-height:70vh;object-fit:contain;" alt="Receipt">`;
    } else {
        content.innerHTML = `
            <div class="py-5">
                <i class="bi bi-file-pdf" style="font-size:64px;color:#dc3545;"></i>
                <p class="mt-3 fw-bold">PDF Document</p>
                <p class="text-muted small">${filepath.split('/').pop()}</p>
                <a href="${filepath}" target="_blank" class="btn btn-danger mt-2">
                    <i class="bi bi-eye me-1"></i> View PDF
                </a>
            </div>
        `;
    }
    
    new bootstrap.Modal(document.getElementById('receiptViewerModal')).show();
}

function openUploadModal(tradeId, receiptType, existingReceipts) {
    document.getElementById('receipt_trade_id').value = tradeId;
    document.getElementById('receipt_type').value = receiptType || 'payment';
    document.getElementById('receipt_files').value = '';
    document.getElementById('receiptPreviews').innerHTML = '';
    document.getElementById('fileList').innerHTML = '';
    document.getElementById('receipt_comment').value = '';
    
    const typeLabel = document.getElementById('receiptTypeLabel');
    const typeDesc = document.getElementById('receiptTypeDesc');
    
    if (receiptType === 'commission') {
        if (typeLabel) typeLabel.textContent = 'Commission Receipt';
        if (typeDesc) typeDesc.textContent = '- Upload commission payment confirmation';
    } else {
        if (typeLabel) typeLabel.textContent = 'Payment Receipt';
        if (typeDesc) typeDesc.textContent = '- Upload payment confirmation';
    }
    
    const previewContainer = document.getElementById('receiptPreviews');
    previewContainer.innerHTML = '';
    if (existingReceipts) {
        const files = existingReceipts.split(',').filter(f => f.trim());
        if (files.length > 0) {
            let html = '<div class="mb-2"><small class="text-muted fw-semibold">Existing Receipts:</small></div><div class="d-flex flex-wrap gap-1 justify-content-center">';
            files.forEach(function(f) {
                f = f.trim();
                if (!f) return;
                const isDb = f.startsWith('db_');
                const fparts = f.split('.');
                const fid = parseInt(fparts[0].substring(3));
                const fext = fparts.length > 1 ? fparts.pop() : '';
                const fp = isDb ? 'serve_receipt.php?id=' + fid + (fext ? '&ext=' + fext : '') : '../uploads/numeric_receipts/' + f;
                const isImg = ['jpg','jpeg','png','gif'].includes(fext || '');
                html += '<div class="thumbnail" onclick="viewReceipt(\'' + fp + '\')" title="' + f + '">';
                if (isImg) {
                    html += '<img src="' + fp + '" alt="Receipt" style="width:60px;height:60px;object-fit:cover;">';
                } else {
                    html += '<div class="file-icon"><i class="bi bi-file-pdf"></i></div>';
                }
                html += '</div>';
            });
            html += '</div>';
            previewContainer.innerHTML = html;
        }
    }
    
    const modal = new bootstrap.Modal(document.getElementById('uploadModal'));
    modal.show();
}

// File preview
document.getElementById('receipt_files')?.addEventListener('change', function(e) {
    const files = this.files;
    const fileList = document.getElementById('fileList');
    const previewContainer = document.getElementById('receiptPreviews');
    
    fileList.innerHTML = '';
    previewContainer.innerHTML = '';
    
    for (let i = 0; i < files.length; i++) {
        const file = files[i];
        const size = (file.size / 1024).toFixed(1);
        
        const fileItem = document.createElement('div');
        fileItem.className = 'file-item';
        fileItem.innerHTML = `
            <span class="file-name" title="${file.name}">${file.name}</span>
            <span class="file-size">${size} KB</span>
        `;
        fileList.appendChild(fileItem);
        
        if (file.type.startsWith('image/')) {
            const reader = new FileReader();
            reader.onload = function(e) {
                const img = document.createElement('img');
                img.src = e.target.result;
                img.style.maxWidth = '80px';
                img.style.maxHeight = '60px';
                img.style.objectFit = 'cover';
                img.style.borderRadius = '4px';
                img.style.margin = '3px';
                img.style.border = '1px solid #ddd';
                previewContainer.appendChild(img);
            };
            reader.readAsDataURL(file);
        }
    }
});

function viewTrade(tradeId) {
    const modal = new bootstrap.Modal(document.getElementById('viewTradeModal'));
    const container = document.getElementById('tradeDetails');
    container.innerHTML = `
        <div class="text-center py-3">
            <div class="spinner-border text-secondary" role="status">
                <span class="visually-hidden">Loading...</span>
            </div>
            <p class="text-muted mt-2">Loading trade details...</p>
        </div>
    `;
    modal.show();
    
    fetch('order_sheet.php?ajax=view_trade&id=' + tradeId)
        .then(r => r.json())
        .then(data => {
            if (!data.success) {
                container.innerHTML = `<div class="alert alert-danger">${data.error || 'Trade not found'}</div>`;
                return;
            }
            const t = data.trade;
            const statusText = t.approval_status === 'approved' ? 'Approved' : (t.approval_status === 'rejected' ? 'Rejected' : 'Pending');
            const statusClass = t.approval_status === 'approved' ? 'approved' : (t.approval_status === 'rejected' ? 'rejected' : 'pending');
            const sideClass = t.trade_side && t.trade_side.toLowerCase() === 'buy' ? 'bg-success' : 'bg-danger';
            const qty = Number(t.quantity || 0).toLocaleString();
            const val = 'TZS ' + Number(t.consideration || 0).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
            const date = t.trade_date ? new Date(t.trade_date).toLocaleDateString() : '-';
            const ref = t.trade_reference || ('TRD-' + String(tradeId).padStart(6, '0'));
            const sec = (t.security_id || '-') + (t.security_name ? ' (' + t.security_name + ')' : '');
            const addRef = t.additional_reference || '-';
            const isLinked = t.settlement_status === 'linked' && t.linked_trade_id;
            
            function receiptThumbs(filesStr, label) {
                if (!filesStr) return '';
                const files = filesStr.split(',').filter(function(f){ return f.trim(); });
                if (files.length === 0) return '';
                let html = '<div class="mt-2"><small class="text-muted">' + label + '</small><div class="receipt-thumbnails">';
                files.forEach(function(f) {
                    f = f.trim();
                    if (!f) return;
                    const isDb = f.startsWith('db_');
                    const fparts = f.split('.');
                    const fid = parseInt(fparts[0].substring(3));
                    const fext = fparts.length > 1 ? fparts.pop() : '';
                    const fp = isDb ? 'serve_receipt.php?id=' + fid + (fext ? '&ext=' + fext : '') : '../uploads/numeric_receipts/' + f;
                    const isImg = ['jpg','jpeg','png','gif'].includes(fext || '');
                    html += '<div class="thumbnail" onclick="viewReceipt(\'' + fp + '\')" title="' + f + '">';
                    if (isImg) {
                        html += '<img src="' + fp + '" alt="Receipt" style="width:50px;height:50px;object-fit:cover;">';
                    } else {
                        html += '<div class="file-icon"><i class="bi bi-file-pdf"></i></div>';
                    }
                    html += '<div class="view-overlay"><i class="bi bi-eye"></i></div></div>';
                });
                html += '</div></div>';
                return html;
            }
            
            let linkedHtml = '';
            if (isLinked) {
                linkedHtml = `
                    <div class="mt-2">
                        <span class="badge-linked">
                            <i class="bi bi-link-45deg"></i> LINKED
                        </span>
                        <div class="mt-1">
                            <small class="text-muted">Linked to Trade:</small>
                            <strong>${t.linked_trade_ref || '#' + t.linked_trade_id}</strong>
                        </div>
                        <div class="btn-group btn-group-sm mt-2" role="group">
                            <a href="trades.php?action=contract_note&id=${tradeId}" 
                               class="btn btn-outline-primary" target="_blank">
                                <i class="bi bi-file-earmark-text"></i> Sold Contract Note
                            </a>
                            <a href="trades.php?action=contract_note&id=${t.linked_trade_id}" 
                               class="btn btn-outline-success" target="_blank">
                                <i class="bi bi-file-earmark-text"></i> Bought Contract Note
                            </a>
                        </div>
                    </div>
                `;
            }
            
            const paymentReceiptsHtml = receiptThumbs(t.payment_receipt, 'Payment Receipts:');
            const commissionReceiptsHtml = receiptThumbs(t.commission_receipt, 'Commission Receipts:');
            const commentHtml = t.receipt_comment ? '<div class="mt-2"><small class="text-muted">Comment:</small><p class="mb-0 small">' + t.receipt_comment + '</p></div>' : '';
            
            container.innerHTML = `
                <div class="row g-3">
                    <div class="col-md-6">
                        <div class="border-bottom pb-2 mb-2">
                            <small class="text-muted">Trade Reference</small>
                            <div class="fw-bold">${ref}</div>
                        </div>
                        <div class="border-bottom pb-2 mb-2">
                            <small class="text-muted">Client</small>
                            <div>${t.client_name || '-'}</div>
                        </div>
                        <div class="border-bottom pb-2 mb-2">
                            <small class="text-muted">Security</small>
                            <div>${sec}</div>
                        </div>
                        <div class="border-bottom pb-2 mb-2">
                            <small class="text-muted">Trade Date</small>
                            <div>${date}</div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="border-bottom pb-2 mb-2">
                            <small class="text-muted">Trade Side</small>
                            <div><span class="badge ${sideClass}">${(t.trade_side || '').toUpperCase()}</span></div>
                        </div>
                        <div class="border-bottom pb-2 mb-2">
                            <small class="text-muted">Quantity</small>
                            <div>${qty}</div>
                        </div>
                        <div class="border-bottom pb-2 mb-2">
                            <small class="text-muted">Consideration</small>
                            <div class="fw-bold">${val}</div>
                        </div>
                        <div class="border-bottom pb-2 mb-2">
                            <small class="text-muted">Status</small>
                            <div><span class="badge-status ${statusClass}">${statusText}</span></div>
                        </div>
                        ${linkedHtml}
                    </div>
                    <div class="col-12">
                        <hr>
                        <small class="text-muted">Additional Reference</small>
                        <div><code>${addRef}</code></div>
                        ${paymentReceiptsHtml}
                        ${commissionReceiptsHtml}
                        ${commentHtml}
                    </div>
                </div>
            `;
        })
        .catch(err => {
            container.innerHTML = `<div class="alert alert-danger">Failed to load trade details</div>`;
        });
}

// Auto-refresh alerts after 5 seconds
document.querySelectorAll('.alert').forEach(alert => {
    setTimeout(() => {
        const bsAlert = bootstrap.Alert.getOrCreateInstance(alert);
        if (bsAlert) bsAlert.close();
    }, 5000);
});

console.log('Numeric Receipt Upload System initialized.');
</script>

<?php include '../includes/footer.php'; ?>
