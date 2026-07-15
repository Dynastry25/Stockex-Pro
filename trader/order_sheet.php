<?php
// ============================================
// NUMERIC REFERENCE RECEIPT UPLOAD - ACTION PAGE
// ============================================
// This page handles uploads and redirects back to order_sheet.php

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
// HANDLE UPLOAD
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_receipt'])) {
    
    $trade_id = isset($_POST['trade_id']) ? (int) $_POST['trade_id'] : 0;
    
    if ($trade_id <= 0) {
        $_SESSION['alert'] = ['Invalid trade ID.', 'danger'];
        header('Location: order_sheet.php');
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
    
    // Redirect back to order_sheet.php with parameters
    $redirect_params = array_filter([
        'filter' => $_POST['filter'] ?? 'pending',
        'asset_class' => $_POST['asset_class'] ?? 'all',
        'search' => $_POST['search'] ?? '',
        'tab' => $_POST['tab'] ?? 'numeric'
    ]);
    
    header('Location: order_sheet.php?' . http_build_query($redirect_params));
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
    
    // Redirect back to order_sheet.php
    $redirect_params = array_filter([
        'filter' => $_GET['filter'] ?? 'pending',
        'asset_class' => $_GET['asset_class'] ?? 'all',
        'search' => $_GET['search'] ?? '',
        'tab' => $_GET['tab'] ?? 'numeric'
    ]);
    
    header('Location: order_sheet.php?' . http_build_query($redirect_params));
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
    
    // Redirect back to order_sheet.php
    $redirect_params = array_filter([
        'filter' => $_POST['filter'] ?? 'pending',
        'asset_class' => $_POST['asset_class'] ?? 'all',
        'search' => $_POST['search'] ?? '',
        'tab' => $_POST['tab'] ?? 'numeric'
    ]);
    
    header('Location: order_sheet.php?' . http_build_query($redirect_params));
    exit;
}

// ============================================
// IF DIRECT ACCESS - REDIRECT TO ORDER SHEET
// ============================================
header('Location: order_sheet.php');
exit;
?>
