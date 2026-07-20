<?php
// ============================================
// NUMERIC REFERENCE RECEIPT UPLOAD - ACTION PAGE
// ============================================
// This page handles all uploads, deletions, and comments
// then redirects back to order_sheet.php

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
// FUNCTION: Redirect back to order_sheet.php
// ============================================
function redirectBack($params = []) {
    $default_params = [
        'filter' => $_POST['filter'] ?? $_GET['filter'] ?? 'pending',
        'asset_class' => $_POST['asset_class'] ?? $_GET['asset_class'] ?? 'all',
        'search' => $_POST['search'] ?? $_GET['search'] ?? '',
        'tab' => $_POST['tab'] ?? $_GET['tab'] ?? 'numeric'
    ];
    
    // Merge with provided params (override defaults)
    $merged_params = array_merge($default_params, $params);
    
    // Remove empty values
    $merged_params = array_filter($merged_params);
    
    header('Location: order_sheet.php?' . http_build_query($merged_params));
    exit;
}

// ============================================
// HANDLE FILE UPLOAD
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_receipt'])) {
    
    $trade_id = isset($_POST['trade_id']) ? (int) $_POST['trade_id'] : 0;
    
    if ($trade_id <= 0) {
        $_SESSION['alert'] = ['Invalid trade ID.', 'danger'];
        redirectBack();
    }
    
    $uploaded_files = [];
    $errors = [];
    
    // Create upload directory
    $upload_dir = __DIR__ . '/../uploads/numeric_receipts/';
    if (!file_exists($upload_dir)) {
        if (!mkdir($upload_dir, 0777, true)) {
            $_SESSION['alert'] = ['Failed to create upload directory.', 'danger'];
            redirectBack();
        }
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
                $file_size = $files['size'][$i];
                
                // Validate file extension
                if (!in_array($file_ext, $allowed_exts)) {
                    $errors[] = "File '{$files['name'][$i]}' - Invalid type. Allowed: JPG, PNG, GIF, PDF";
                    continue;
                }
                
                // Validate file size
                if ($file_size > $max_size) {
                    $errors[] = "File '{$files['name'][$i]}' - Exceeds 5MB limit";
                    continue;
                }
                
                // Generate unique filename
                $timestamp = date('Ymd_His');
                $random = rand(1000, 9999);
                $filename = 'receipt_' . $trade_id . '_' . $timestamp . '_' . $random . '.' . $file_ext;
                $filepath = $upload_dir . $filename;
                
                // Move uploaded file
                if (move_uploaded_file($files['tmp_name'][$i], $filepath)) {
                    $uploaded_files[] = $filename;
                } else {
                    $errors[] = "Failed to upload file '{$files['name'][$i]}'";
                }
            } else {
                $errors[] = "Upload error for file '{$files['name'][$i]}' (Error code: {$files['error'][$i]})";
            }
        }
    } else {
        $_SESSION['alert'] = ['No files selected for upload.', 'danger'];
        redirectBack();
    }
    
    // Save to database
    if (!empty($uploaded_files)) {
        try {
            // Check if record exists
            $stmt = $db->prepare("SELECT id, payment_receipt FROM numeric_trade_receipts WHERE trade_id = ? AND trade_type = 'trade'");
            $stmt->execute([$trade_id]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($existing) {
                // Update existing record
                $receipts = !empty($existing['payment_receipt']) ? explode(',', $existing['payment_receipt']) : [];
                $all_receipts = array_merge($receipts, $uploaded_files);
                $receipts_str = implode(',', $all_receipts);
                
                $stmt = $db->prepare("UPDATE numeric_trade_receipts SET payment_receipt = ?, uploaded_by = ?, updated_at = NOW() WHERE trade_id = ? AND trade_type = 'trade'");
                $result = $stmt->execute([$receipts_str, $user_name, $trade_id]);
            } else {
                // Insert new record
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
            error_log("Database error: " . $e->getMessage());
            $_SESSION['alert'] = ['Database error occurred. Please try again.', 'danger'];
        }
    } else {
        $error_msg = 'No valid files uploaded.';
        if (!empty($errors)) {
            $error_msg .= ' Errors: ' . implode('; ', $errors);
        }
        $_SESSION['alert'] = [$error_msg, 'danger'];
    }
    
    // Redirect back
    redirectBack();
}

// ============================================
// HANDLE DELETE RECEIPT
// ============================================
if (isset($_GET['delete_receipt']) && isset($_GET['trade_id']) && isset($_GET['file'])) {
    
    $trade_id = (int) $_GET['trade_id'];
    $file_to_delete = trim($_GET['file']);
    
    if (empty($file_to_delete)) {
        $_SESSION['alert'] = ['Invalid file specified.', 'danger'];
        redirectBack();
    }
    
    try {
        // Check if receipt is approved
        $stmt = $db->prepare("SELECT is_approved FROM numeric_trade_receipts WHERE trade_id = ? AND trade_type = 'trade'");
        $stmt->execute([$trade_id]);
        $record = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($record && $record['is_approved'] == 1) {
            $_SESSION['alert'] = ['Cannot delete approved receipts. Please contact finance officer.', 'warning'];
            redirectBack();
        }
        
        // Get current receipts
        $stmt = $db->prepare("SELECT payment_receipt FROM numeric_trade_receipts WHERE trade_id = ? AND trade_type = 'trade'");
        $stmt->execute([$trade_id]);
        $record = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($record && !empty($record['payment_receipt'])) {
            $receipts = explode(',', $record['payment_receipt']);
            
            // Find and remove the file
            if (($key = array_search($file_to_delete, $receipts)) !== false) {
                unset($receipts[$key]);
                
                // Delete physical file
                $filepath = __DIR__ . '/../uploads/numeric_receipts/' . $file_to_delete;
                if (file_exists($filepath)) {
                    if (!unlink($filepath)) {
                        error_log("Failed to delete file: " . $filepath);
                    }
                }
                
                // Update database
                $receipts_str = !empty($receipts) ? implode(',', $receipts) : null;
                $stmt = $db->prepare("UPDATE numeric_trade_receipts SET payment_receipt = ?, updated_at = NOW() WHERE trade_id = ? AND trade_type = 'trade'");
                if ($stmt->execute([$receipts_str, $trade_id])) {
                    $_SESSION['alert'] = ['Receipt deleted successfully.', 'success'];
                } else {
                    $_SESSION['alert'] = ['Failed to update database.', 'danger'];
                }
            } else {
                $_SESSION['alert'] = ['File not found in database.', 'warning'];
            }
        } else {
            $_SESSION['alert'] = ['No receipts found for this trade.', 'warning'];
        }
    } catch (Exception $e) {
        error_log("Delete error: " . $e->getMessage());
        $_SESSION['alert'] = ['Error deleting receipt. Please try again.', 'danger'];
    }
    
    // Redirect back
    redirectBack();
}

// ============================================
// HANDLE ADD COMMENT
// ============================================
if (isset($_POST['add_comment']) && isset($_POST['trade_id'])) {
    
    $trade_id = (int) $_POST['trade_id'];
    $comment = trim($_POST['trader_comment'] ?? '');
    
    if ($trade_id <= 0) {
        $_SESSION['alert'] = ['Invalid trade ID.', 'danger'];
        redirectBack();
    }
    
    if (empty($comment)) {
        $_SESSION['alert'] = ['Please enter a comment.', 'danger'];
        redirectBack();
    }
    
    try {
        $stmt = $db->prepare("INSERT INTO numeric_trade_comments (trade_id, comment, created_by, created_at) VALUES (?, ?, ?, NOW())");
        if ($stmt->execute([$trade_id, $comment, $user_name])) {
            $_SESSION['alert'] = ['Comment added successfully.', 'success'];
        } else {
            $_SESSION['alert'] = ['Failed to add comment.', 'danger'];
        }
    } catch (Exception $e) {
        error_log("Comment error: " . $e->getMessage());
        $_SESSION['alert'] = ['Error adding comment. Please try again.', 'danger'];
    }
    
    // Redirect back
    redirectBack();
}

// ============================================
// HANDLE GET COMMENTS (AJAX)
// ============================================
if (isset($_GET['get_comments']) && isset($_GET['trade_id'])) {
    header('Content-Type: application/json');
    
    $trade_id = (int) $_GET['trade_id'];
    $response = ['success' => false, 'comments' => []];
    
    try {
        $stmt = $db->prepare("
            SELECT comment, created_by, created_at 
            FROM numeric_trade_comments 
            WHERE trade_id = ? 
            ORDER BY created_at DESC 
            LIMIT 10
        ");
        $stmt->execute([$trade_id]);
        $comments = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $response['success'] = true;
        $response['comments'] = $comments;
    } catch (Exception $e) {
        error_log("Get comments error: " . $e->getMessage());
        $response['message'] = 'Error loading comments.';
    }
    
    echo json_encode($response);
    exit;
}

// ============================================
// HANDLE GET RECEIPTS (AJAX)
// ============================================
if (isset($_GET['get_receipts']) && isset($_GET['trade_id'])) {
    header('Content-Type: application/json');
    
    $trade_id = (int) $_GET['trade_id'];
    $response = ['success' => false, 'receipts' => []];
    
    try {
        $stmt = $db->prepare("
            SELECT payment_receipt, is_approved 
            FROM numeric_trade_receipts 
            WHERE trade_id = ? AND trade_type = 'trade'
        ");
        $stmt->execute([$trade_id]);
        $record = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($record && !empty($record['payment_receipt'])) {
            $receipts = explode(',', $record['payment_receipt']);
            $response['success'] = true;
            $response['receipts'] = array_filter($receipts);
            $response['is_approved'] = (int)$record['is_approved'];
        } else {
            $response['success'] = true;
            $response['receipts'] = [];
            $response['is_approved'] = 0;
        }
    } catch (Exception $e) {
        error_log("Get receipts error: " . $e->getMessage());
        $response['message'] = 'Error loading receipts.';
    }
    
    echo json_encode($response);
    exit;
}

// ============================================
// IF DIRECT ACCESS - REDIRECT TO ORDER SHEET
// ============================================
// If someone accesses this file directly without any action
$_SESSION['alert'] = ['Invalid request.', 'danger'];
redirectBack();
?>
