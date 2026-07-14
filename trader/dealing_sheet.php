<?php
// Error reporting - log but don't display
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/mtp_receipt_errors.log');

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
// FUNCTION TO CHECK IF USER IS ACCOUNTANT
// ============================================
function isAccountant($user) {
    return isset($user['role']) && in_array($user['role'], ['finance_officer', 'accountant', 'admin']);
}

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
    
    $upload_dir = __DIR__ . '/../uploads/mtp_receipts/';
    if (!file_exists($upload_dir)) {
        mkdir($upload_dir, 0777, true);
    }
    
    // Get existing receipts
    $stmt = $db->prepare("SELECT payment_receipt FROM mtp_trade_receipts WHERE trade_id = ? AND trade_type = 'trade'");
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
            $stmt = $db->prepare("SELECT id FROM mtp_trade_receipts WHERE trade_id = ? AND trade_type = 'trade'");
            $stmt->execute([$trade_id]);
            if ($stmt->fetch()) {
                $stmt = $db->prepare("UPDATE mtp_trade_receipts SET payment_receipt = ?, updated_at = NOW(), uploaded_by = ? WHERE trade_id = ? AND trade_type = 'trade'");
                $result = $stmt->execute([$receipts_str, $user_name, $trade_id]);
            } else {
                $stmt = $db->prepare("INSERT INTO mtp_trade_receipts (trade_id, trade_type, payment_receipt, uploaded_by, created_at, updated_at) VALUES (?, 'trade', ?, ?, NOW(), NOW())");
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
    header('Location: mtp_receipt_upload.php?' . http_build_query(array_filter([
        'filter' => $_GET['filter'] ?? 'pending',
        'asset_class' => $_GET['asset_class'] ?? 'all',
        'search' => $_GET['search'] ?? ''
    ])));
    exit;
}

// Handle Receipt Delete (Traders can delete their own receipts)
if (isset($_GET['delete_receipt']) && isset($_GET['trade_id']) && isset($_GET['file'])) {
    $trade_id = (int) $_GET['trade_id'];
    $file_to_delete = $_GET['file'];
    
    // Check if receipt is already approved - if approved, traders cannot delete
    $stmt = $db->prepare("SELECT is_approved FROM mtp_trade_receipts WHERE trade_id = ? AND trade_type = 'trade'");
    $stmt->execute([$trade_id]);
    $record = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($record && $record['is_approved'] == 1) {
        $_SESSION['alert'] = ['Cannot delete approved receipts. Please contact finance officer.', 'warning'];
        header('Location: mtp_receipt_upload.php?' . http_build_query(array_filter([
            'filter' => $_GET['filter'] ?? 'pending',
            'asset_class' => $_GET['asset_class'] ?? 'all',
            'search' => $_GET['search'] ?? ''
        ])));
        exit;
    }
    
    $stmt = $db->prepare("SELECT payment_receipt FROM mtp_trade_receipts WHERE trade_id = ? AND trade_type = 'trade'");
    $stmt->execute([$trade_id]);
    $record = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($record && !empty($record['payment_receipt'])) {
        $receipts = explode(',', $record['payment_receipt']);
        
        if (($key = array_search($file_to_delete, $receipts)) !== false) {
            unset($receipts[$key]);
            
            $filepath = __DIR__ . '/../uploads/mtp_receipts/' . $file_to_delete;
            if (file_exists($filepath)) {
                unlink($filepath);
            }
            
            $receipts_str = !empty($receipts) ? implode(',', $receipts) : null;
            $stmt = $db->prepare("UPDATE mtp_trade_receipts SET payment_receipt = ?, updated_at = NOW() WHERE trade_id = ? AND trade_type = 'trade'");
            if ($stmt->execute([$receipts_str, $trade_id])) {
                $_SESSION['alert'] = ['Receipt deleted successfully.', 'success'];
            }
        }
    }
    header('Location: mtp_receipt_upload.php?' . http_build_query(array_filter([
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
        header('Location: mtp_receipt_upload.php?' . http_build_query(array_filter([
            'filter' => $_GET['filter'] ?? 'pending',
            'asset_class' => $_GET['asset_class'] ?? 'all',
            'search' => $_GET['search'] ?? ''
        ])));
        exit;
    }
    
    $stmt = $db->prepare("
        INSERT INTO mtp_trade_comments (trade_id, comment, created_by, created_at) 
        VALUES (?, ?, ?, NOW())
    ");
    
    if ($stmt->execute([$trade_id, $comment, $user_name])) {
        $_SESSION['alert'] = ['Comment added successfully.', 'success'];
    } else {
        $_SESSION['alert'] = ['Failed to add comment.', 'danger'];
    }
    
    header('Location: mtp_receipt_upload.php?' . http_build_query(array_filter([
        'filter' => $_GET['filter'] ?? 'pending',
        'asset_class' => $_GET['asset_class'] ?? 'all',
        'search' => $_GET['search'] ?? ''
    ])));
    exit;
}

// ============================================
// GET FILTER PARAMETERS
// ============================================
$filter = $_GET['filter'] ?? 'pending'; // pending, approved, rejected, all
$asset_class_filter = $_GET['asset_class'] ?? 'all';
$search = $_GET['search'] ?? '';

// ============================================
// FETCH TRADES WITH ADDITIONAL_REFERENCE = NUMBER ONLY
// ============================================
function getMTPTrades($db, $filter = 'pending', $asset_class_filter = 'all', $search = '') {
    $sql = "
        SELECT 
            t.*,
            tr.payment_receipt,
            tr.is_approved,
            tr.approved_by,
            tr.approved_at,
            tr.approval_comment,
            tr.uploaded_by,
            tr.created_at as receipt_created_at,
            tr.updated_at as receipt_updated_at,
            GROUP_CONCAT(DISTINCT tc.comment ORDER BY tc.created_at DESC SEPARATOR '|||') as comments,
            GROUP_CONCAT(DISTINCT tc.created_by ORDER BY tc.created_at DESC SEPARATOR '|||') as comment_authors,
            GROUP_CONCAT(DISTINCT tc.created_at ORDER BY tc.created_at DESC SEPARATOR '|||') as comment_dates
        FROM trades t
        LEFT JOIN mtp_trade_receipts tr ON t.id = tr.trade_id AND tr.trade_type = 'trade'
        LEFT JOIN mtp_trade_comments tc ON t.id = tc.trade_id
        WHERE 1=1
    ";
    
    $params = [];
    
    // Condition: additional_reference is a number only (numeric)
    $sql .= " AND t.additional_reference REGEXP '^[0-9]+$'";
    
    // Asset class conditions
    if ($asset_class_filter === 'all') {
        // For bonds: all trades (BUY and SELL)
        // For equities/ETFs: only BUY side
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
function getMTPStats($db) {
    $stats = [
        'total' => 0,
        'pending' => 0,
        'approved' => 0,
        'rejected' => 0,
        'bond' => 0,
        'equity' => 0,
        'etf' => 0
    ];
    
    // Get counts by status
    $stmt = $db->query("
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN tr.is_approved IS NULL OR tr.is_approved = 0 THEN 1 ELSE 0 END) as pending,
            SUM(CASE WHEN tr.is_approved = 1 THEN 1 ELSE 0 END) as approved,
            SUM(CASE WHEN tr.is_approved = 2 THEN 1 ELSE 0 END) as rejected
        FROM trades t
        LEFT JOIN mtp_trade_receipts tr ON t.id = tr.trade_id AND tr.trade_type = 'trade'
        WHERE t.additional_reference REGEXP '^[0-9]+$'
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
    
    // Get counts by asset class
    $stmt = $db->query("
        SELECT 
            asset_class,
            COUNT(*) as count
        FROM trades t
        LEFT JOIN mtp_trade_receipts tr ON t.id = tr.trade_id AND tr.trade_type = 'trade'
        WHERE t.additional_reference REGEXP '^[0-9]+$'
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
    
    return $stats;
}

$trades = getMTPTrades($db, $filter, $asset_class_filter, $search);
$stats = getMTPStats($db);

$page_title = 'MTP Payment Receipt Upload';
include '../includes/header.php';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #4F46E5;
            --primary-light: #818CF8;
            --primary-dark: #3730A3;
            --success: #10B981;
            --danger: #EF4444;
            --warning: #F59E0B;
            --gray-50: #F9FAFB;
            --gray-100: #F3F4F6;
            --gray-200: #E5E7EB;
            --gray-300: #D1D5DB;
            --gray-400: #9CA3AF;
            --gray-500: #6B7280;
            --gray-600: #4B5563;
            --gray-700: #374151;
            --gray-800: #1F2937;
            --gray-900: #111827;
            --shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1), 0 1px 2px 0 rgba(0, 0, 0, 0.06);
            --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            --shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
            --radius: 12px;
            --radius-sm: 8px;
            --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            background: var(--gray-50);
            color: var(--gray-800);
            line-height: 1.6;
        }

        .modern-container {
            max-width: 1440px;
            margin: 0 auto;
            padding: 24px 32px;
        }

        /* Stats Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }

        .stat-card {
            background: white;
            border-radius: var(--radius);
            padding: 16px 20px;
            box-shadow: var(--shadow);
            border: 1px solid var(--gray-200);
            transition: var(--transition);
            position: relative;
            overflow: hidden;
        }

        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        .stat-card .stat-icon {
            font-size: 24px;
            margin-bottom: 8px;
            opacity: 0.2;
        }

        .stat-card .stat-label {
            font-size: 12px;
            font-weight: 500;
            color: var(--gray-500);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .stat-card .stat-value {
            font-size: 24px;
            font-weight: 700;
            color: var(--gray-900);
            margin-top: 2px;
        }

        .stat-card .stat-sub {
            font-size: 11px;
            color: var(--gray-400);
            margin-top: 2px;
        }

        .stat-card.primary .stat-icon { color: var(--primary); }
        .stat-card.success .stat-icon { color: var(--success); }
        .stat-card.warning .stat-icon { color: var(--warning); }
        .stat-card.danger .stat-icon { color: var(--danger); }
        .stat-card.info .stat-icon { color: #0891B2; }

        /* Filter Section */
        .filter-section {
            background: white;
            border-radius: var(--radius);
            padding: 16px 20px;
            margin-bottom: 24px;
            box-shadow: var(--shadow);
            border: 1px solid var(--gray-200);
        }

        .filter-grid {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr 1fr;
            gap: 12px;
            align-items: end;
        }

        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .filter-group label {
            font-size: 12px;
            font-weight: 600;
            color: var(--gray-600);
        }

        .filter-group .form-control,
        .filter-group .form-select {
            padding: 8px 12px;
            border: 1px solid var(--gray-200);
            border-radius: var(--radius-sm);
            font-size: 14px;
            transition: var(--transition);
            background: white;
            width: 100%;
        }

        .filter-group .form-control:focus,
        .filter-group .form-select:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1);
        }

        .filter-actions {
            display: flex;
            gap: 8px;
        }

        .btn-modern {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 18px;
            border-radius: var(--radius-sm);
            font-weight: 600;
            font-size: 13px;
            border: none;
            transition: var(--transition);
            cursor: pointer;
            text-decoration: none;
        }

        .btn-modern-primary {
            background: var(--primary);
            color: white;
        }

        .btn-modern-primary:hover {
            background: var(--primary-dark);
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(79, 70, 229, 0.3);
        }

        .btn-modern-secondary {
            background: var(--gray-100);
            color: var(--gray-700);
        }

        .btn-modern-secondary:hover {
            background: var(--gray-200);
        }

        .btn-modern-success {
            background: var(--success);
            color: white;
        }

        .btn-modern-success:hover {
            background: #059669;
        }

        .btn-modern-outline {
            background: transparent;
            color: var(--gray-700);
            border: 1px solid var(--gray-200);
        }

        .btn-modern-outline:hover {
            background: var(--gray-50);
            border-color: var(--gray-300);
        }

        /* Table */
        .table-wrapper {
            background: white;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            border: 1px solid var(--gray-200);
            overflow: hidden;
        }

        .table-header {
            padding: 14px 20px;
            background: var(--gray-50);
            border-bottom: 1px solid var(--gray-200);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 8px;
        }

        .table-header h6 {
            font-size: 15px;
            font-weight: 600;
            color: var(--gray-800);
            margin: 0;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .table-header .badge-count {
            padding: 4px 12px;
            background: var(--primary);
            color: white;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
        }

        .table-responsive {
            overflow-x: auto;
        }

        .table-modern {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }

        .table-modern thead {
            background: var(--gray-50);
            border-bottom: 2px solid var(--gray-200);
        }

        .table-modern thead th {
            padding: 10px 14px;
            text-align: left;
            font-weight: 600;
            color: var(--gray-600);
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            white-space: nowrap;
        }

        .table-modern thead th.text-end {
            text-align: right;
        }

        .table-modern tbody tr {
            border-bottom: 1px solid var(--gray-100);
            transition: var(--transition);
        }

        .table-modern tbody tr:hover {
            background: var(--gray-50);
        }

        .table-modern tbody tr:last-child {
            border-bottom: none;
        }

        .table-modern tbody td {
            padding: 10px 14px;
            vertical-align: middle;
        }

        .table-modern tbody td.text-end {
            text-align: right;
        }

        .badge-asset {
            padding: 2px 10px;
            border-radius: 12px;
            font-size: 10px;
            font-weight: 600;
        }

        .badge-asset.bond { background: #E0E7FF; color: #3730A3; }
        .badge-asset.equity { background: #D1FAE5; color: #065F46; }
        .badge-asset.etf { background: #FEF3C7; color: #92400E; }

        .badge-status {
            padding: 2px 10px;
            border-radius: 12px;
            font-size: 10px;
            font-weight: 600;
        }

        .badge-status.pending { background: #FEF3C7; color: #92400E; }
        .badge-status.approved { background: #D1FAE5; color: #065F46; }
        .badge-status.rejected { background: #FEE2E2; color: #991B1B; }

        .receipt-thumbnails {
            display: flex;
            gap: 4px;
            flex-wrap: wrap;
            align-items: center;
        }

        .receipt-preview {
            max-width: 40px;
            max-height: 35px;
            object-fit: cover;
            border-radius: 4px;
            cursor: pointer;
            border: 1px solid var(--gray-200);
            transition: var(--transition);
        }

        .receipt-preview:hover {
            border-color: var(--primary);
            box-shadow: 0 0 5px rgba(79, 70, 229, 0.3);
        }

        .receipt-count {
            font-size: 10px;
            background: var(--primary);
            color: white;
            border-radius: 50%;
            padding: 1px 6px;
            margin-left: 2px;
        }

        .comment-bubble {
            background: var(--gray-100);
            border-radius: var(--radius-sm);
            padding: 6px 10px;
            margin-top: 4px;
            font-size: 12px;
            max-width: 250px;
            border-left: 3px solid var(--primary);
        }

        .comment-bubble .comment-author {
            font-weight: 600;
            font-size: 10px;
            color: var(--gray-500);
        }

        .action-btn {
            width: 28px;
            height: 28px;
            border-radius: var(--radius-sm);
            border: none;
            background: transparent;
            color: var(--gray-500);
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
        }

        .action-btn:hover {
            background: var(--gray-100);
        }

        .action-btn.upload:hover { background: #D1FAE5; color: var(--success); }
        .action-btn.view:hover { background: #E0E7FF; color: var(--primary); }
        .action-btn.comment:hover { background: #FEF3C7; color: var(--warning); }

        /* Modals */
        .modal-content {
            border-radius: var(--radius);
            border: none;
        }

        .modal-header {
            border-bottom: 1px solid var(--gray-200);
            padding: 16px 24px;
        }

        .modal-body {
            padding: 24px;
        }

        .modal-footer {
            border-top: 1px solid var(--gray-200);
            padding: 16px 24px;
        }

        /* Responsive */
        @media (max-width: 1024px) {
            .filter-grid {
                grid-template-columns: 1fr 1fr;
            }
        }

        @media (max-width: 768px) {
            .modern-container {
                padding: 16px;
            }

            .stats-grid {
                grid-template-columns: 1fr 1fr;
            }

            .filter-grid {
                grid-template-columns: 1fr;
            }

            .filter-actions {
                flex-wrap: wrap;
            }

            .filter-actions .btn-modern {
                flex: 1;
                justify-content: center;
            }

            .table-header {
                flex-direction: column;
                align-items: stretch;
                text-align: center;
            }
        }

        @media (max-width: 576px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
        }

        /* Scrollbar */
        .table-responsive::-webkit-scrollbar {
            width: 6px;
            height: 6px;
        }

        .table-responsive::-webkit-scrollbar-track {
            background: var(--gray-100);
            border-radius: 3px;
        }

        .table-responsive::-webkit-scrollbar-thumb {
            background: var(--gray-300);
            border-radius: 3px;
        }

        .table-responsive::-webkit-scrollbar-thumb:hover {
            background: var(--gray-400);
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 40px 20px;
        }

        .empty-state i {
            font-size: 40px;
            color: var(--gray-300);
            margin-bottom: 12px;
        }

        .empty-state h5 {
            color: var(--gray-600);
            margin-bottom: 4px;
        }

        .empty-state p {
            color: var(--gray-400);
            font-size: 14px;
        }
    </style>
</head>
<body>

<div class="modern-container">
    <!-- Page Header -->
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
        <div>
            <h1 class="h3 mb-0 fw-bold">
                <i class="bi bi-receipt-cutoff me-2" style="color: var(--primary);"></i>
                MTP Payment Receipt Upload
            </h1>
            <p class="text-muted mb-0 small">
                Upload payment receipts for MTP trades (Additional Reference = Number only)
            </p>
        </div>
        <div>
            <a href="trades.php" class="btn-modern btn-modern-secondary">
                <i class="bi bi-arrow-left"></i> Back to Trades
            </a>
        </div>
    </div>

    <!-- Alert Messages -->
    <?php if (isset($_SESSION['alert'])): ?>
        <div class="alert alert-<?php echo $_SESSION['alert'][1]; ?> alert-dismissible fade show mb-4" style="border-radius: var(--radius-sm);">
            <i class="bi <?php echo $_SESSION['alert'][1] === 'success' ? 'bi-check-circle' : ($_SESSION['alert'][1] === 'warning' ? 'bi-exclamation-triangle' : 'bi-x-circle'); ?> me-2"></i>
            <?php echo htmlspecialchars($_SESSION['alert'][0]); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php unset($_SESSION['alert']); ?>
    <?php endif; ?>

    <!-- Stats Cards -->
    <div class="stats-grid">
        <div class="stat-card primary">
            <div class="stat-icon"><i class="bi bi-file-text"></i></div>
            <div class="stat-label">Total MTP Trades</div>
            <div class="stat-value"><?php echo number_format($stats['total']); ?></div>
            <div class="stat-sub">With numeric Additional Reference</div>
        </div>
        <div class="stat-card warning">
            <div class="stat-icon"><i class="bi bi-clock-history"></i></div>
            <div class="stat-label">Pending</div>
            <div class="stat-value" style="color: var(--warning);"><?php echo number_format($stats['pending']); ?></div>
            <div class="stat-sub">Awaiting receipt upload</div>
        </div>
        <div class="stat-card success">
            <div class="stat-icon"><i class="bi bi-check-circle"></i></div>
            <div class="stat-label">Approved</div>
            <div class="stat-value" style="color: var(--success);"><?php echo number_format($stats['approved']); ?></div>
            <div class="stat-sub">Receipts verified</div>
        </div>
        <div class="stat-card danger">
            <div class="stat-icon"><i class="bi bi-x-circle"></i></div>
            <div class="stat-label">Rejected</div>
            <div class="stat-value" style="color: var(--danger);"><?php echo number_format($stats['rejected']); ?></div>
            <div class="stat-sub">Receipts rejected</div>
        </div>
        <div class="stat-card info">
            <div class="stat-icon"><i class="bi bi-pie-chart"></i></div>
            <div class="stat-label">Breakdown</div>
            <div class="stat-value" style="font-size: 18px;">
                <span class="text-primary"><?php echo $stats['bond']; ?>B</span>
                <span class="text-success ms-2"><?php echo $stats['equity']; ?>E</span>
                <span class="text-warning ms-2"><?php echo $stats['etf']; ?>F</span>
            </div>
            <div class="stat-sub">Bonds · Equities · ETFs</div>
        </div>
    </div>

    <!-- Filter Section -->
    <div class="filter-section">
        <form method="GET" id="filterForm">
            <div class="filter-grid">
                <div class="filter-group">
                    <label><i class="bi bi-funnel me-1"></i> Status</label>
                    <select class="form-select" name="filter" onchange="this.form.submit()">
                        <option value="pending" <?php echo $filter === 'pending' ? 'selected' : ''; ?>>Pending</option>
                        <option value="approved" <?php echo $filter === 'approved' ? 'selected' : ''; ?>>Approved</option>
                        <option value="rejected" <?php echo $filter === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                        <option value="all" <?php echo $filter === 'all' ? 'selected' : ''; ?>>All</option>
                    </select>
                </div>
                <div class="filter-group">
                    <label><i class="bi bi-tag me-1"></i> Asset Class</label>
                    <select class="form-select" name="asset_class" onchange="this.form.submit()">
                        <option value="all" <?php echo $asset_class_filter === 'all' ? 'selected' : ''; ?>>All</option>
                        <option value="bond" <?php echo $asset_class_filter === 'bond' ? 'selected' : ''; ?>>Bond</option>
                        <option value="equity" <?php echo $asset_class_filter === 'equity' ? 'selected' : ''; ?>>Equity</option>
                        <option value="etf" <?php echo $asset_class_filter === 'etf' ? 'selected' : ''; ?>>ETF</option>
                    </select>
                </div>
                <div class="filter-group">
                    <label><i class="bi bi-search me-1"></i> Search</label>
                    <input type="text" class="form-control" name="search" placeholder="Client, Security, Ref..." 
                           value="<?php echo htmlspecialchars($search); ?>" onkeypress="if(event.key==='Enter') this.form.submit()">
                </div>
                <div class="filter-group">
                    <label>&nbsp;</label>
                    <div class="filter-actions">
                        <button type="submit" class="btn-modern btn-modern-primary">
                            <i class="bi bi-filter"></i> Apply
                        </button>
                        <a href="mtp_receipt_upload.php" class="btn-modern btn-modern-secondary">
                            <i class="bi bi-arrow-counterclockwise"></i> Reset
                        </a>
                    </div>
                </div>
            </div>
        </form>
    </div>

    <!-- Table -->
    <div class="table-wrapper">
        <div class="table-header">
            <h6>
                <i class="bi bi-table"></i>
                MTP Trades
                <span class="badge-count"><?php echo count($trades); ?> trades</span>
            </h6>
            <div>
                <span class="badge bg-secondary me-2">
                    <i class="bi bi-info-circle"></i> 
                    <?php if ($asset_class_filter === 'bond'): ?>
                        All Bond trades
                    <?php elseif ($asset_class_filter === 'equity' || $asset_class_filter === 'etf'): ?>
                        BUY only for <?php echo ucfirst($asset_class_filter); ?>
                    <?php else: ?>
                        Bonds: All | Equities/ETFs: BUY only
                    <?php endif; ?>
                </span>
            </div>
        </div>

        <?php if (empty($trades)): ?>
            <div class="empty-state">
                <i class="bi bi-inbox"></i>
                <h5>No MTP trades found</h5>
                <p>Try adjusting your filters or check if there are trades with numeric Additional Reference.</p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table-modern">
                    <thead>
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
                            $isApprovedBadge = $isApproved === 1 ? 'approved' : ($isApproved === 2 ? 'rejected' : 'pending');
                            $isApprovedText = $isApproved === 1 ? 'Approved' : ($isApproved === 2 ? 'Rejected' : 'Pending');
                            
                            if ($isBond) {
                                $displayQty = 'TZS ' . number_format(floatval($trade['quantity'] ?? 0), 2);
                                $displayPrice = number_format(floatval($trade['price'] ?? 0), 4) . '%';
                                $displayValue = 'TZS ' . number_format(floatval($trade['consideration'] ?? 0), 2);
                                $assetClass = 'bond';
                            } else {
                                $displayQty = number_format(floatval($trade['quantity'] ?? 0), 0);
                                $displayPrice = 'TZS ' . number_format(floatval($trade['price'] ?? 0), 2);
                                $displayValue = 'TZS ' . number_format(floatval($trade['consideration'] ?? 0), 2);
                                $assetClass = strtolower($trade['asset_class'] ?? 'equity');
                                if ($assetClass === 'exchange traded funds') $assetClass = 'etf';
                            }
                            
                            // Get comments
                            $comments = [];
                            if (!empty($trade['comments'])) {
                                $comment_parts = explode('|||', $trade['comments']);
                                $author_parts = !empty($trade['comment_authors']) ? explode('|||', $trade['comment_authors']) : [];
                                $date_parts = !empty($trade['comment_dates']) ? explode('|||', $trade['comment_dates']) : [];
                                
                                for ($i = 0; $i < count($comment_parts) && $i < 3; $i++) {
                                    $comments[] = [
                                        'text' => $comment_parts[$i] ?? '',
                                        'author' => $author_parts[$i] ?? 'Unknown',
                                        'date' => $date_parts[$i] ?? ''
                                    ];
                                }
                            }
                        ?>
                            <tr>
                                <td>
                                    <span class="fw-semibold" style="font-size: 12px;"><?php echo htmlspecialchars($trade['trade_reference'] ?? ''); ?></span>
                                </td>
                                <td><?php echo htmlspecialchars($trade['client_name'] ?? ''); ?></td>
                                <td><?php echo htmlspecialchars($trade['security_id'] ?? ''); ?></td>
                                <td>
                                    <span class="badge-asset <?php echo $assetClass; ?>">
                                        <?php echo strtoupper($assetClass); ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="badge <?php echo strtolower($trade['trade_side'] ?? '') === 'buy' ? 'bg-success' : 'bg-danger'; ?>">
                                        <?php echo strtoupper($trade['trade_side'] ?? ''); ?>
                                    </span>
                                </td>
                                <td class="text-end"><?php echo $displayQty; ?></td>
                                <td class="text-end"><?php echo $displayPrice; ?></td>
                                <td class="text-end fw-bold"><?php echo $displayValue; ?></td>
                                <td><?php echo date('d/m/Y', strtotime($trade['trade_date'] ?? '')); ?></td>
                                <td>
                                    <code style="background: var(--gray-100); padding: 1px 6px; border-radius: 4px; font-size: 11px;">
                                        <?php echo htmlspecialchars($trade['additional_reference'] ?? ''); ?>
                                    </code>
                                </td>
                                <td>
                                    <?php if ($hasReceipt): ?>
                                        <div class="receipt-thumbnails">
                                            <?php 
                                            $display_count = 0;
                                            foreach ($receipts as $receiptFile):
                                                $receiptFile = trim($receiptFile);
                                                if (empty($receiptFile)) continue;
                                                $display_count++;
                                                $filepath = '../uploads/mtp_receipts/' . $receiptFile;
                                                $ext = strtolower(pathinfo($receiptFile, PATHINFO_EXTENSION));
                                                $isImage = in_array($ext, ['jpg', 'jpeg', 'png', 'gif']);
                                                
                                                if ($isImage && file_exists($filepath)):
                                            ?>
                                                <img src="<?php echo $filepath; ?>" alt="Receipt" class="receipt-preview" onclick="viewReceiptImage('<?php echo $filepath; ?>')" title="Click to view">
                                            <?php else: ?>
                                                <span class="badge bg-danger" style="cursor:pointer; font-size:10px;" onclick="viewReceiptPDF('<?php echo $filepath; ?>')">
                                                    <i class="bi bi-file-pdf"></i>
                                                </span>
                                            <?php endif; ?>
                                            <?php endforeach; ?>
                                            <?php if (count($receipts) > 3): ?>
                                                <span class="receipt-count">+<?php echo count($receipts) - 3; ?></span>
                                            <?php endif; ?>
                                            <?php if ($isApproved !== 1): // Only allow delete if not approved ?>
                                                <a href="mtp_receipt_upload.php?delete_receipt=1&trade_id=<?php echo $trade['id']; ?>&file=<?php echo urlencode($receipts[0]); ?>&filter=<?php echo urlencode($filter); ?>&asset_class=<?php echo urlencode($asset_class_filter); ?>&search=<?php echo urlencode($search); ?>" 
                                                   class="text-danger" onclick="return confirm('Delete this receipt?')" title="Delete receipt">
                                                    <i class="bi bi-x-circle" style="font-size: 12px;"></i>
                                                </a>
                                            <?php endif; ?>
                                        </div>
                                    <?php else: ?>
                                        <button class="btn btn-outline-success btn-sm" onclick="openReceiptUpload(<?php echo $trade['id']; ?>)" style="font-size: 11px; padding: 2px 8px;">
                                            <i class="bi bi-upload"></i> Upload
                                        </button>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge-status <?php echo $isApprovedBadge; ?>">
                                        <?php echo $isApprovedText; ?>
                                    </span>
                                    <?php if ($isApproved === 1 && !empty($trade['approved_by'])): ?>
                                        <br><small style="font-size: 9px; color: var(--gray-400);">
                                            by <?php echo htmlspecialchars($trade['approved_by']); ?>
                                            <br><?php echo date('d/m/Y H:i', strtotime($trade['approved_at'])); ?>
                                        </small>
                                    <?php endif; ?>
                                    <?php if ($isApproved === 2 && !empty($trade['approval_comment'])): ?>
                                        <br><small style="font-size: 9px; color: var(--danger);">
                                            <?php echo htmlspecialchars(substr($trade['approval_comment'], 0, 30)); ?>
                                        </small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!empty($comments)): ?>
                                        <div class="comment-bubble">
                                            <?php foreach ($comments as $c): ?>
                                                <div>
                                                    <span class="comment-author"><?php echo htmlspecialchars($c['author']); ?>:</span>
                                                    <?php echo htmlspecialchars(substr($c['text'], 0, 40)); ?>
                                                    <?php if (strlen($c['text']) > 40): ?>...<?php endif; ?>
                                                </div>
                                            <?php endforeach; ?>
                                            <?php if (count($comments) > 3): ?>
                                                <div class="text-muted" style="font-size: 10px;">+<?php echo count($comments) - 3; ?> more</div>
                                            <?php endif; ?>
                                        </div>
                                    <?php else: ?>
                                        <span class="text-muted" style="font-size: 11px;">No comments</span>
                                    <?php endif; ?>
                                    <button class="btn btn-outline-secondary btn-sm mt-1" onclick="openCommentModal(<?php echo $trade['id']; ?>)" style="font-size: 10px; padding: 1px 6px;">
                                        <i class="bi bi-chat"></i>
                                    </button>
                                </td>
                                <td class="text-end">
                                    <div class="d-flex gap-1 justify-content-end">
                                        <?php if ($isApproved !== 1): // Only show upload if not approved ?>
                                            <button class="action-btn upload" onclick="openReceiptUpload(<?php echo $trade['id']; ?>)" title="Upload Receipt">
                                                <i class="bi bi-upload"></i>
                                            </button>
                                        <?php endif; ?>
                                        <button class="action-btn comment" onclick="openCommentModal(<?php echo $trade['id']; ?>)" title="Add Comment">
                                            <i class="bi bi-chat-dots"></i>
                                        </button>
                                        <button class="action-btn view" onclick="viewTradeDetails(<?php echo htmlspecialchars(json_encode($trade)); ?>)" title="View Details">
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

<!-- Receipt Upload Modal -->
<div class="modal fade" id="receiptModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-md">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title"><i class="bi bi-upload me-2"></i>Upload Payment Receipts</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" enctype="multipart/form-data" action="mtp_receipt_upload.php">
                <div class="modal-body">
                    <input type="hidden" name="trade_id" id="receipt_trade_id" value="">
                    <input type="hidden" name="upload_receipt" value="1">
                    <input type="hidden" name="filter" value="<?php echo htmlspecialchars($filter); ?>">
                    <input type="hidden" name="asset_class" value="<?php echo htmlspecialchars($asset_class_filter); ?>">
                    <input type="hidden" name="search" value="<?php echo htmlspecialchars($search); ?>">
                    
                    <div class="text-center mb-3">
                        <div class="receipt-preview-container" id="receiptPreviews"></div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Select Receipt Files</label>
                        <input type="file" class="form-control" name="payment_receipts[]" id="receipt_files" accept="image/*,.pdf" multiple required>
                        <div class="form-text">Allowed: JPG, PNG, GIF, PDF (Max 5MB each)</div>
                        <div class="file-list mt-2" id="fileList"></div>
                    </div>
                    
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle me-2"></i>
                        Upload clear copies of payment receipts or confirmations.
                        <br><small class="text-muted">Multiple files can be selected at once.</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success" id="uploadReceiptBtn">Upload Receipts</button>
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
            <form method="POST" action="mtp_receipt_upload.php">
                <div class="modal-body">
                    <input type="hidden" name="trade_id" id="comment_trade_id" value="">
                    <input type="hidden" name="add_comment" value="1">
                    <input type="hidden" name="filter" value="<?php echo htmlspecialchars($filter); ?>">
                    <input type="hidden" name="asset_class" value="<?php echo htmlspecialchars($asset_class_filter); ?>">
                    <input type="hidden" name="search" value="<?php echo htmlspecialchars($search); ?>">
                    
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Your Comment</label>
                        <textarea class="form-control" name="trader_comment" id="trader_comment" rows="4" placeholder="Enter your comment about this trade..." required></textarea>
                        <div class="form-text">Add any relevant information about the trade or receipt.</div>
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

<!-- Receipt View Modal -->
<div class="modal fade" id="receiptViewModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title"><i class="bi bi-image me-2"></i>Payment Receipt</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body text-center">
                <img id="receiptViewImg" src="" alt="Payment Receipt" style="max-width: 100%; max-height: 80vh;">
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <a id="receiptDownloadLink" href="#" target="_blank" class="btn btn-primary"><i class="bi bi-download"></i> Download</a>
            </div>
        </div>
    </div>
</div>

<!-- PDF Viewer Modal -->
<div class="modal fade" id="receiptPdfViewerModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl" style="max-width: 95%; height: 90vh;">
        <div class="modal-content" style="height: 100%;">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title"><i class="bi bi-file-pdf me-2"></i>Payment Receipt (PDF)</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" style="height: calc(100% - 120px); padding: 0;">
                <embed id="receiptPdfViewer" src="" type="application/pdf" width="100%" height="100%">
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <a id="receiptPdfDownloadLink" href="#" target="_blank" class="btn btn-primary"><i class="bi bi-download"></i> Download PDF</a>
            </div>
        </div>
    </div>
</div>

<!-- Trade Details Modal -->
<div class="modal fade" id="tradeDetailsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="bi bi-info-circle me-2"></i>Trade Details</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="tradeDetailsBody"></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script>
// ============================================
// RECEIPT UPLOAD FUNCTIONS
// ============================================
function openReceiptUpload(tradeId) {
    document.getElementById('receipt_trade_id').value = tradeId;
    document.getElementById('receipt_files').value = '';
    document.getElementById('receiptPreviews').innerHTML = '';
    document.getElementById('fileList').innerHTML = '';
    const modal = new bootstrap.Modal(document.getElementById('receiptModal'));
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
        
        const fileItem = document.createElement('div');
        fileItem.className = 'd-flex justify-content-between align-items-center p-2 bg-light rounded mb-1';
        fileItem.innerHTML = `
            <span style="font-size: 13px; max-width: 200px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">${file.name}</span>
            <span style="font-size: 11px; color: var(--gray-500);">${(file.size / 1024).toFixed(1)} KB</span>
        `;
        fileList.appendChild(fileItem);
        
        if (file.type.startsWith('image/')) {
            const reader = new FileReader();
            reader.onload = function(e) {
                const img = document.createElement('img');
                img.src = e.target.result;
                img.style.maxWidth = '60px';
                img.style.maxHeight = '50px';
                img.style.objectFit = 'cover';
                img.style.borderRadius = '4px';
                img.style.margin = '2px';
                img.style.border = '1px solid var(--gray-200)';
                previewContainer.appendChild(img);
            };
            reader.readAsDataURL(file);
        }
    }
});

// ============================================
// COMMENT FUNCTIONS
// ============================================
function openCommentModal(tradeId) {
    document.getElementById('comment_trade_id').value = tradeId;
    document.getElementById('trader_comment').value = '';
    const modal = new bootstrap.Modal(document.getElementById('commentModal'));
    modal.show();
}

// ============================================
// RECEIPT VIEW FUNCTIONS
// ============================================
function viewReceiptImage(path) {
    document.getElementById('receiptViewImg').src = path;
    document.getElementById('receiptDownloadLink').href = path;
    const modal = new bootstrap.Modal(document.getElementById('receiptViewModal'));
    modal.show();
}

function viewReceiptPDF(path) {
    document.getElementById('receiptPdfViewer').src = path;
    document.getElementById('receiptPdfDownloadLink').href = path;
    const modal = new bootstrap.Modal(document.getElementById('receiptPdfViewerModal'));
    modal.show();
}

// ============================================
// TRADE DETAILS VIEW
// ============================================
function viewTradeDetails(trade) {
    const body = document.getElementById('tradeDetailsBody');
    
    const isBond = trade.asset_class === 'bond';
    const receipts = trade.payment_receipt ? trade.payment_receipt.split(',') : [];
    const hasReceipt = receipts.length > 0 && receipts[0] !== '';
    
    let html = `
        <div class="row">
            <div class="col-md-6">
                <table class="table table-sm table-borderless">
                    <tr><td><strong>Trade Reference</strong></td><td>${trade.trade_reference || 'N/A'}</td></tr>
                    <tr><td><strong>Exchange Reference</strong></td><td>${trade.exchange_reference || 'N/A'}</td></tr>
                    <tr><td><strong>Asset Class</strong></td><td>${trade.asset_class || 'N/A'}</td></tr>
                    <tr><td><strong>Security</strong></td><td>${trade.security_id || 'N/A'}</td></tr>
                    <tr><td><strong>Client</strong></td><td>${trade.client_name || 'N/A'}</td></tr>
                    <tr><td><strong>CDS Account</strong></td><td>${trade.client_cds_account || 'N/A'}</td></tr>
                    <tr><td><strong>Trade Side</strong></td><td><span class="badge ${trade.trade_side === 'buy' ? 'bg-success' : 'bg-danger'}">${(trade.trade_side || '').toUpperCase()}</span></td></tr>
                </table>
            </div>
            <div class="col-md-6">
                <table class="table table-sm table-borderless">
                    <tr><td><strong>Quantity</strong></td><td>${trade.quantity || 0}</td></tr>
                    <tr><td><strong>Price</strong></td><td>${isBond ? (trade.price || 0) + '%' : 'TZS ' + (trade.price || 0)}</td></tr>
                    <tr><td><strong>Consideration</strong></td><td>TZS ${Number(trade.consideration || 0).toLocaleString(undefined, {minimumFractionDigits: 2})}</td></tr>
                    <tr><td><strong>Trade Date</strong></td><td>${trade.trade_date || 'N/A'}</td></tr>
                    <tr><td><strong>Settlement Date</strong></td><td>${trade.settlement_date || 'N/A'}</td></tr>
                    <tr><td><strong>Additional Reference</strong></td><td><code>${trade.additional_reference || 'N/A'}</code></td></tr>
                    <tr><td><strong>Status</strong></td><td>${trade.is_approved === 1 ? '✅ Approved' : trade.is_approved === 2 ? '❌ Rejected' : '⏳ Pending'}</td></tr>
                </table>
            </div>
        </div>
        <hr>
        <div class="row">
            <div class="col-12">
                <strong>Receipts:</strong>
                ${hasReceipt ? receipts.map(r => `<span class="badge bg-success me-1">${r.trim()}</span>`).join('') : '<span class="text-muted">No receipts uploaded</span>'}
                ${trade.is_approved === 1 ? `<br><small class="text-success">Approved by ${trade.approved_by || 'Unknown'} on ${trade.approved_at || ''}</small>` : ''}
                ${trade.approval_comment ? `<br><small class="text-muted">Comment: ${trade.approval_comment}</small>` : ''}
            </div>
        </div>
    `;
    
    body.innerHTML = html;
    const modal = new bootstrap.Modal(document.getElementById('tradeDetailsModal'));
    modal.show();
}
</script>

<?php include '../includes/footer.php'; ?>
