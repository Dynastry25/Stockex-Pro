<?php
/**
 * Public Order Dashboard - Combined Entry + View
 * Includes form, today's orders list, and date filtering
 * 
 * Usage: order_dashboard_public.php
 */

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/order_dashboard_errors.log');

// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once '../config/config.php';

// Get database connection
try {
    $db = getDBConnection();
} catch (Exception $e) {
    die("Database connection failed: " . $e->getMessage());
}

// Get company details
function getCompanyDetails($db) {
    try {
        $stmt = $db->prepare("SELECT company_code, name as company_name FROM companies WHERE is_active = 1 LIMIT 1");
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: ['company_code' => 'B13/C', 'company_name' => 'Victory Financial Services Ltd'];
    } catch (Exception $e) {
        return ['company_code' => 'B13/C', 'company_name' => 'Victory Financial Services Ltd'];
    }
}
$company = getCompanyDetails($db);

// ============================================
// AJAX ENDPOINTS
// ============================================
if (isset($_GET['ajax_action'])) {
    header('Content-Type: application/json');
    
    if ($_GET['ajax_action'] === 'search_clients') {
        $search = $_GET['search'] ?? '';
        try {
            $stmt = $db->prepare("SELECT client_name, cds_account as client_cds_account FROM clients WHERE client_name LIKE ? LIMIT 30");
            $stmt->execute(['%' . $search . '%']);
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (empty($results)) {
                $stmt = $db->prepare("SELECT DISTINCT client_name, client_cds_account FROM trades WHERE client_name LIKE ? LIMIT 30");
                $stmt->execute(['%' . $search . '%']);
                $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
            echo json_encode($results);
        } catch (Exception $e) {
            echo json_encode([]);
        }
        exit;
    }
    
    if ($_GET['ajax_action'] === 'get_securities') {
        $asset_class = $_GET['asset_class'] ?? 'equity';
        $search = $_GET['search'] ?? '';
        try {
            if ($asset_class === 'equity') {
                $sql = "SELECT security_id, stock_name as security_name, company_name, sector FROM equities WHERE status = 'active'";
                if ($search) $sql .= " AND (security_id LIKE ? OR stock_name LIKE ?)";
                $sql .= " LIMIT 50";
            } elseif ($asset_class === 'bond') {
                $sql = "SELECT security_id, bond_name as security_name, issuer, coupon_rate, maturity_date FROM bonds WHERE status = 'active'";
                if ($search) $sql .= " AND (security_id LIKE ? OR bond_name LIKE ?)";
                $sql .= " LIMIT 50";
            } else {
                $sql = "SELECT etf_code as security_id, name as security_name FROM etf WHERE status = 'active'";
                if ($search) $sql .= " AND (etf_code LIKE ? OR name LIKE ?)";
                $sql .= " LIMIT 50";
            }
            $stmt = $db->prepare($sql);
            if ($search) {
                $searchParam = '%' . $search . '%';
                $stmt->execute([$searchParam, $searchParam]);
            } else {
                $stmt->execute();
            }
            echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
        } catch (Exception $e) {
            echo json_encode([]);
        }
        exit;
    }
}

// ============================================
// FORM SUBMISSION HANDLER
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'save_order') {
    header('Content-Type: application/json');
    
    try {
        $data = $_POST;
        
        // Validate required fields
        $required_fields = ['client_name', 'security_id', 'quantity', 'order_price'];
        foreach ($required_fields as $field) {
            if (empty($data[$field])) {
                throw new Exception('Missing required field: ' . $field);
            }
        }
        
        // Generate sheet reference
        $prefix = 'DS' . date('Ymd');
        $stmt = $db->prepare("SELECT COUNT(*) FROM dealing_sheets WHERE sheet_reference LIKE ?");
        $stmt->execute([$prefix . '%']);
        $count = $stmt->fetchColumn() + 1;
        $sheet_reference = $prefix . str_pad($count, 4, '0', STR_PAD_LEFT);
        
        // Map priority
        $priority_map = ['normal' => 'Normal', 'urgent' => 'Urgent', 'most_important' => 'Most Important'];
        $priority = $priority_map[$data['priority'] ?? 'normal'] ?? 'Normal';
        
        // Calculate order value based on asset class
        $qty = floatval($data['quantity']);
        $price = floatval($data['order_price']);
        $is_bond = ($data['asset_class'] ?? 'equity') === 'bond';
        
        if ($is_bond) {
            $order_value = ($price / 100) * $qty;
        } else {
            $order_value = $qty * $price;
        }
        
        // Handle file uploads
        $receipt_files = [];
        if (isset($_FILES['payment_receipts']) && !empty($_FILES['payment_receipts']['name'][0])) {
            $upload_dir = __DIR__ . '/../uploads/payment_receipts/';
            if (!file_exists($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }
            
            $allowed_exts = ['jpg', 'jpeg', 'png', 'gif', 'pdf'];
            $max_size = 5 * 1024 * 1024;
            
            $files = $_FILES['payment_receipts'];
            $total_files = count($files['name']);
            
            for ($i = 0; $i < $total_files; $i++) {
                if ($files['error'][$i] === UPLOAD_ERR_OK) {
                    $file_ext = strtolower(pathinfo($files['name'][$i], PATHINFO_EXTENSION));
                    if (in_array($file_ext, $allowed_exts) && $files['size'][$i] <= $max_size) {
                        $filename = 'receipt_public_' . date('Ymd_His') . '_' . ($i + 1) . '.' . $file_ext;
                        $filepath = $upload_dir . $filename;
                        if (move_uploaded_file($files['tmp_name'][$i], $filepath)) {
                            $receipt_files[] = $filename;
                        }
                    }
                }
            }
        }
        
        $receipts_str = !empty($receipt_files) ? implode(',', $receipt_files) : null;
        
        // Insert into database
        $sql = "INSERT INTO dealing_sheets (
            sheet_reference, client_name, client_cds_account, security_id, security_name,
            order_type, asset_class, quantity, order_price, order_value, order_date, order_time,
            priority, remarks, broker_code, executed_quantity, executed_price,
            trade_date, settlement_date, execution_time, lifecycle_stage, execution_status,
            recorded_at, created_at, dealer_name, payment_receipt, payment_status, viewed_count
        ) VALUES (
            :sheet_reference, :client_name, :client_cds_account, :security_id, :security_name,
            :order_type, :asset_class, :quantity, :order_price, :order_value, :order_date, :order_time,
            :priority, :remarks, :broker_code, :executed_quantity, :executed_price,
            :trade_date, :settlement_date, :execution_time, 'order', 'pending',
            NOW(), NOW(), :dealer_name, :payment_receipt, 'pending', 0
        )";
        
        $stmt = $db->prepare($sql);
        $stmt->execute([
            ':sheet_reference' => $sheet_reference,
            ':client_name' => $data['client_name'],
            ':client_cds_account' => $data['client_cds_account'] ?? '',
            ':security_id' => $data['security_id'],
            ':security_name' => $data['security_name'] ?? '',
            ':order_type' => $data['order_type'] ?? 'buy',
            ':asset_class' => $data['asset_class'] ?? 'equity',
            ':quantity' => $data['quantity'],
            ':order_price' => $data['order_price'],
            ':order_value' => $order_value,
            ':order_date' => $data['order_date'] ?? date('Y-m-d'),
            ':order_time' => $data['order_time'] ?? date('H:i:s'),
            ':priority' => $priority,
            ':remarks' => $data['remarks'] ?? '',
            ':broker_code' => $data['broker_code'] ?? $company['company_code'],
            ':executed_quantity' => !empty($data['executed_quantity']) ? $data['executed_quantity'] : null,
            ':executed_price' => !empty($data['executed_price']) ? $data['executed_price'] : null,
            ':trade_date' => !empty($data['trade_date']) ? $data['trade_date'] : null,
            ':settlement_date' => !empty($data['settlement_date']) ? $data['settlement_date'] : null,
            ':execution_time' => !empty($data['execution_time']) ? $data['execution_time'] : null,
            ':dealer_name' => 'Public User',
            ':payment_receipt' => $receipts_str
        ]);
        
        echo json_encode([
            'success' => true, 
            'message' => 'Order submitted successfully! Reference: ' . $sheet_reference,
            'reference' => $sheet_reference
        ]);
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// ============================================
// ACCOUNTANT CONFIRMATION HANDLER
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    $action = $_POST['action'];
    $order_id = (int) $_POST['order_id'];
    
    if ($action === 'confirm_payment') {
        $notes = $_POST['notes'] ?? '';
        try {
            $stmt = $db->prepare("
                UPDATE dealing_sheets 
                SET payment_status = 'confirmed',
                    payment_confirmed_by = 'Accountant',
                    payment_confirmed_at = NOW(),
                    payment_notes = ?,
                    viewed_count = viewed_count + 1
                WHERE id = ? AND is_cancelled = 0
            ");
            $stmt->execute([$notes, $order_id]);
            echo json_encode(['success' => true, 'message' => 'Payment confirmed successfully!']);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
        }
        exit;
    }
    
    if ($action === 'reject_payment') {
        $reason = $_POST['reason'] ?? '';
        try {
            $stmt = $db->prepare("
                UPDATE dealing_sheets 
                SET payment_status = 'rejected',
                    payment_confirmed_by = 'Accountant',
                    payment_confirmed_at = NOW(),
                    payment_notes = ?,
                    viewed_count = viewed_count + 1
                WHERE id = ? AND is_cancelled = 0
            ");
            $stmt->execute([$reason, $order_id]);
            echo json_encode(['success' => true, 'message' => 'Payment rejected successfully.']);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
        }
        exit;
    }
    
    if ($action === 'cancel_order') {
        $reason = $_POST['reason'] ?? 'Cancelled by accountant';
        try {
            $stmt = $db->prepare("
                UPDATE dealing_sheets 
                SET is_cancelled = 1,
                    cancelled_by = 'Accountant',
                    cancelled_at = NOW(),
                    cancellation_reason = ?,
                    payment_status = 'rejected',
                    viewed_count = viewed_count + 1
                WHERE id = ?
            ");
            $stmt->execute([$reason, $order_id]);
            echo json_encode(['success' => true, 'message' => 'Order cancelled successfully.']);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
        }
        exit;
    }
}

// ============================================
// GET ORDERS WITH FILTERS
// ============================================
$filter = $_GET['filter'] ?? 'today';
$search = $_GET['search'] ?? '';
$date_from = $_GET['date_from'] ?? date('Y-m-d');
$date_to = $_GET['date_to'] ?? date('Y-m-d');

// If filter is 'today', use today's date
if ($filter === 'today') {
    $date_from = date('Y-m-d');
    $date_to = date('Y-m-d');
} elseif ($filter === 'week') {
    $date_from = date('Y-m-d', strtotime('-7 days'));
    $date_to = date('Y-m-d');
} elseif ($filter === 'month') {
    $date_from = date('Y-m-d', strtotime('-30 days'));
    $date_to = date('Y-m-d');
}

function getOrders($db, $filter, $search, $date_from, $date_to) {
    $sql = "SELECT * FROM dealing_sheets WHERE 1=1";
    $params = [];
    
    // Date filter
    if (!empty($date_from) && !empty($date_to)) {
        $sql .= " AND DATE(order_date) BETWEEN ? AND ?";
        $params[] = $date_from;
        $params[] = $date_to;
    } elseif (!empty($date_from)) {
        $sql .= " AND DATE(order_date) >= ?";
        $params[] = $date_from;
    } elseif (!empty($date_to)) {
        $sql .= " AND DATE(order_date) <= ?";
        $params[] = $date_to;
    }
    
    // Search
    if (!empty($search)) {
        $sql .= " AND (client_name LIKE ? OR security_id LIKE ? OR sheet_reference LIKE ?)";
        $searchParam = '%' . $search . '%';
        $params[] = $searchParam;
        $params[] = $searchParam;
        $params[] = $searchParam;
    }
    
    $sql .= " ORDER BY created_at DESC";
    
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getStats($db, $date_from, $date_to) {
    $stats = [];
    
    $sql = "SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN payment_status = 'pending' AND is_cancelled = 0 THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN payment_status = 'confirmed' AND is_cancelled = 0 THEN 1 ELSE 0 END) as confirmed,
                SUM(CASE WHEN payment_status = 'rejected' AND is_cancelled = 0 THEN 1 ELSE 0 END) as rejected,
                SUM(CASE WHEN is_cancelled = 1 THEN 1 ELSE 0 END) as cancelled
            FROM dealing_sheets 
            WHERE DATE(order_date) BETWEEN ? AND ?";
    
    $stmt = $db->prepare($sql);
    $stmt->execute([$date_from, $date_to]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $stats['total'] = $result['total'] ?? 0;
    $stats['pending'] = $result['pending'] ?? 0;
    $stats['confirmed'] = $result['confirmed'] ?? 0;
    $stats['rejected'] = $result['rejected'] ?? 0;
    $stats['cancelled'] = $result['cancelled'] ?? 0;
    
    return $stats;
}

$orders = getOrders($db, $filter, $search, $date_from, $date_to);
$stats = getStats($db, $date_from, $date_to);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>Order Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        :root {
            --primary-color: #1a237e;
            --success-color: #2e7d32;
            --danger-color: #c62828;
            --warning-color: #e65100;
        }
        
        body {
            background: #f0f2f5;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
            padding: 10px;
            min-height: 100vh;
        }
        
        .container-custom {
            max-width: 1400px;
            margin: 0 auto;
        }
        
        .header {
            background: linear-gradient(135deg, var(--primary-color), #0d47a1);
            color: white;
            padding: 15px 20px;
            border-radius: 12px;
            margin-bottom: 15px;
        }
        
        .header h1 {
            font-size: 1.3rem;
            font-weight: 700;
            margin: 0;
        }
        
        .header small {
            opacity: 0.8;
            font-weight: 300;
        }
        
        .stat-card {
            border-radius: 10px;
            padding: 12px 15px;
            color: white;
            text-align: center;
            transition: transform 0.2s;
            height: 100%;
        }
        
        .stat-card:hover {
            transform: translateY(-2px);
        }
        
        .stat-card .number {
            font-size: 1.5rem;
            font-weight: 700;
        }
        
        .stat-card .label {
            font-size: 0.7rem;
            opacity: 0.9;
        }
        
        .stat-card.total { background: linear-gradient(135deg, #1a237e, #0d47a1); }
        .stat-card.pending { background: linear-gradient(135deg, #f57c00, #e65100); }
        .stat-card.confirmed { background: linear-gradient(135deg, #2e7d32, #1b5e20); }
        .stat-card.rejected { background: linear-gradient(135deg, #c62828, #b71c1c); }
        .stat-card.cancelled { background: linear-gradient(135deg, #455a64, #263238); }
        
        .card {
            border-radius: 12px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.06);
            border: none;
            margin-bottom: 15px;
        }
        
        .card-header {
            background: transparent;
            border-bottom: 2px solid #f0f0f0;
            padding: 12px 16px;
            font-weight: 600;
        }
        
        .card-body {
            padding: 16px;
        }
        
        .form-label {
            font-weight: 600;
            font-size: 0.8rem;
            color: #333;
            margin-bottom: 3px;
        }
        
        .form-control, .form-select {
            border-radius: 8px;
            border: 1.5px solid #e0e0e0;
            padding: 8px 12px;
            font-size: 0.9rem;
            transition: all 0.3s;
        }
        
        .form-control:focus, .form-select:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(26, 35, 126, 0.1);
        }
        
        .required-star {
            color: #dc3545;
            margin-left: 2px;
        }
        
        .btn-primary-custom {
            background: linear-gradient(135deg, var(--primary-color), #0d47a1);
            border: none;
            padding: 10px 25px;
            font-weight: 600;
            border-radius: 8px;
            color: white;
            transition: all 0.3s;
        }
        
        .btn-primary-custom:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(13, 71, 161, 0.3);
            color: white;
        }
        
        .btn-primary-custom:disabled {
            opacity: 0.7;
            transform: none;
        }
        
        .btn-outline-custom {
            border: 2px solid var(--primary-color);
            color: var(--primary-color);
            background: transparent;
            padding: 8px 18px;
            font-weight: 600;
            border-radius: 8px;
            transition: all 0.3s;
        }
        
        .btn-outline-custom:hover {
            background: var(--primary-color);
            color: white;
        }
        
        .search-dropdown {
            position: absolute;
            background: white;
            border: 1px solid #ddd;
            border-radius: 8px;
            max-height: 200px;
            overflow-y: auto;
            z-index: 9999;
            width: 100%;
            display: none;
            box-shadow: 0 4px 12px rgba(0,0,0,0.12);
        }
        
        .search-dropdown .item {
            padding: 8px 12px;
            cursor: pointer;
            border-bottom: 1px solid #f5f5f5;
            transition: background 0.2s;
            font-size: 0.85rem;
        }
        
        .search-dropdown .item:hover {
            background: #f0f4ff;
        }
        
        .search-dropdown .item .sub {
            font-size: 0.7rem;
            color: #6c757d;
            display: block;
        }
        
        .position-relative {
            position: relative;
        }
        
        .security-info {
            font-size: 0.75rem;
            color: #6c757d;
            margin-top: 3px;
            padding: 3px 8px;
            background: #f8f9fa;
            border-radius: 4px;
            min-height: 20px;
        }
        
        .badge-status {
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 0.65rem;
            font-weight: 600;
        }
        
        .badge-status.pending { background: #fff3e0; color: #e65100; }
        .badge-status.confirmed { background: #e8f5e9; color: #1b5e20; }
        .badge-status.rejected { background: #ffebee; color: #c62828; }
        .badge-status.cancelled { background: #eceff1; color: #455a64; }
        
        .table th {
            font-size: 0.7rem;
            text-transform: uppercase;
            color: #6c757d;
            font-weight: 700;
            border-bottom: 2px solid #e0e0e0;
            white-space: nowrap;
        }
        
        .table td {
            font-size: 0.8rem;
            vertical-align: middle;
        }
        
        .btn-sm-custom {
            padding: 3px 8px;
            font-size: 0.7rem;
            border-radius: 6px;
        }
        
        .receipt-thumb {
            max-width: 40px;
            max-height: 35px;
            object-fit: cover;
            border-radius: 4px;
            cursor: pointer;
            border: 1px solid #ddd;
        }
        
        .receipt-thumb:hover {
            border-color: var(--primary-color);
        }
        
        .receipt-thumbnails {
            display: flex;
            gap: 3px;
            flex-wrap: wrap;
            align-items: center;
        }
        
        .order-row-cancelled {
            background-color: #f5f5f5 !important;
            opacity: 0.6;
        }
        
        .order-row-cancelled td {
            text-decoration: line-through;
        }
        
        .section-divider {
            border-top: 2px dashed #e8e8e8;
            margin: 15px 0;
        }
        
        .form-hint {
            font-size: 0.65rem;
            color: #6c757d;
            margin-top: 2px;
        }
        
        .file-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 4px 10px;
            background: #f8f9fa;
            border-radius: 4px;
            margin-bottom: 2px;
            font-size: 0.8rem;
        }
        
        .file-item .remove-file {
            cursor: pointer;
            color: #dc3545;
            font-weight: bold;
            padding: 0 5px;
        }
        
        .file-item .remove-file:hover {
            color: #a71d2a;
        }
        
        .filter-section {
            background: white;
            border-radius: 10px;
            padding: 12px 15px;
            margin-bottom: 15px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.06);
        }
        
        .filter-btn {
            padding: 5px 15px;
            border-radius: 20px;
            border: 1px solid #ddd;
            background: white;
            font-size: 0.8rem;
            transition: all 0.3s;
        }
        
        .filter-btn:hover {
            background: #f0f4ff;
        }
        
        .filter-btn.active {
            background: var(--primary-color);
            color: white;
            border-color: var(--primary-color);
        }
        
        .view-count {
            font-size: 0.6rem;
            color: #6c757d;
        }
        
        @media (max-width: 768px) {
            body { padding: 5px; }
            .header h1 { font-size: 1rem; }
            .stat-card .number { font-size: 1.2rem; }
            .stat-card { padding: 8px 10px; }
            .card-body { padding: 10px; }
            .table-responsive { font-size: 0.7rem; }
            .btn-sm-custom { font-size: 0.6rem; padding: 2px 5px; }
            .form-control, .form-select { font-size: 16px; }
        }
        
        @media (max-width: 480px) {
            .stat-card .number { font-size: 1rem; }
            .filter-btn { font-size: 0.7rem; padding: 3px 10px; }
            .col-6 { padding-left: 4px; padding-right: 4px; }
        }
        
        .tab-content {
            padding-top: 15px;
        }
        
        .nav-tabs .nav-link {
            font-size: 0.85rem;
            font-weight: 600;
            color: #6c757d;
            border: none;
            padding: 8px 20px;
            border-radius: 8px 8px 0 0;
        }
        
        .nav-tabs .nav-link.active {
            color: var(--primary-color);
            background: white;
            border-bottom: 3px solid var(--primary-color);
        }
        
        .nav-tabs .nav-link:hover {
            color: var(--primary-color);
        }
    </style>
</head>
<body>

<div class="container-custom">

    <!-- Header -->
    <div class="header">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <h1><i class="bi bi-speedometer2 me-2"></i>Order Dashboard</h1>
                <small><i class="bi bi-calendar3 me-1"></i> <?php echo date('l, F j, Y'); ?></small>
            </div>
            <div>
                <span class="badge bg-light text-dark" id="liveClock"></span>
            </div>
        </div>
    </div>

    <!-- Stats -->
    <div class="row g-2 g-md-3 mb-3">
        <div class="col-6 col-md-2">
            <div class="stat-card total">
                <div class="number"><?php echo $stats['total']; ?></div>
                <div class="label">Total Orders</div>
            </div>
        </div>
        <div class="col-6 col-md-2">
            <div class="stat-card pending">
                <div class="number"><?php echo $stats['pending']; ?></div>
                <div class="label">Pending</div>
            </div>
        </div>
        <div class="col-6 col-md-2">
            <div class="stat-card confirmed">
                <div class="number"><?php echo $stats['confirmed']; ?></div>
                <div class="label">Confirmed</div>
            </div>
        </div>
        <div class="col-6 col-md-2">
            <div class="stat-card rejected">
                <div class="number"><?php echo $stats['rejected']; ?></div>
                <div class="label">Rejected</div>
            </div>
        </div>
        <div class="col-6 col-md-2">
            <div class="stat-card cancelled">
                <div class="number"><?php echo $stats['cancelled']; ?></div>
                <div class="label">Cancelled</div>
            </div>
        </div>
    </div>

    <!-- Tabs: Form | Orders -->
    <ul class="nav nav-tabs" id="mainTabs" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link active" id="form-tab" data-bs-toggle="tab" data-bs-target="#form-panel" type="button" role="tab">
                <i class="bi bi-plus-circle me-1"></i> New Order
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="orders-tab" data-bs-toggle="tab" data-bs-target="#orders-panel" type="button" role="tab">
                <i class="bi bi-list-ul me-1"></i> Orders List
                <span class="badge bg-primary ms-1"><?php echo count($orders); ?></span>
            </button>
        </li>
    </ul>

    <div class="tab-content">

        <!-- ========================================= -->
        <!-- TAB 1: FORM PANEL -->
        <!-- ========================================= -->
        <div class="tab-pane fade show active" id="form-panel" role="tabpanel">
            <div class="card">
                <div class="card-header">
                    <h6 class="mb-0"><i class="bi bi-pencil-square me-2"></i>Enter Order Details</h6>
                </div>
                <div class="card-body">
                    
                    <div id="alertContainer"></div>

                    <form id="orderForm" enctype="multipart/form-data">

                        <div class="row g-2 g-md-3">
                            <!-- Order Type -->
                            <div class="col-6 col-md-3">
                                <label class="form-label">Order Type <span class="required-star">*</span></label>
                                <select class="form-select" name="order_type" id="order_type" required>
                                    <option value="buy">BUY</option>
                                    <option value="sell">SELL</option>
                                </select>
                            </div>
                            <!-- Priority -->
                            <div class="col-6 col-md-3">
                                <label class="form-label">Priority</label>
                                <select class="form-select" name="priority" id="priority">
                                    <option value="normal">Normal</option>
                                    <option value="urgent">Urgent</option>
                                    <option value="most_important">Most Important</option>
                                </select>
                            </div>
                            <!-- Asset Class -->
                            <div class="col-6 col-md-3">
                                <label class="form-label">Asset Class <span class="required-star">*</span></label>
                                <select class="form-select" name="asset_class" id="asset_class" required>
                                    <option value="equity">Equity / Shares</option>
                                    <option value="bond">Bond</option>
                                    <option value="etf">ETF</option>
                                </select>
                            </div>
                            <!-- Order Date -->
                            <div class="col-6 col-md-3">
                                <label class="form-label">Order Date</label>
                                <input type="date" class="form-control" name="order_date" id="order_date">
                            </div>
                        </div>

                        <div class="section-divider"></div>

                        <!-- Client & Security -->
                        <div class="row g-2 g-md-3">
                            <div class="col-12 col-md-6 position-relative">
                                <label class="form-label">Client Name <span class="required-star">*</span></label>
                                <input type="text" class="form-control" id="client_search" placeholder="Type to search client..." autocomplete="off">
                                <input type="hidden" name="client_name" id="client_name">
                                <input type="hidden" name="client_cds_account" id="client_cds_account">
                                <div id="client_search_dropdown" class="search-dropdown"></div>
                                <div class="form-hint">Type at least 2 characters to search</div>
                            </div>
                            <div class="col-12 col-md-6 position-relative">
                                <label class="form-label">Security <span class="required-star">*</span></label>
                                <input type="text" class="form-control" id="security_search" placeholder="Type to search security..." autocomplete="off">
                                <input type="hidden" name="security_id" id="security_id">
                                <input type="hidden" name="security_name" id="security_name">
                                <div id="security_search_dropdown" class="search-dropdown"></div>
                                <div id="security_info" class="security-info"></div>
                            </div>
                        </div>

                        <div class="section-divider"></div>

                        <!-- Quantity & Price -->
                        <div class="row g-2 g-md-3">
                            <div class="col-6 col-md-3">
                                <label class="form-label" id="quantity_label">Quantity <span class="required-star">*</span></label>
                                <input type="number" class="form-control" name="quantity" id="quantity" step="1" min="1" required>
                                <div class="form-hint" id="quantity_hint">Number of shares</div>
                            </div>
                            <div class="col-6 col-md-3">
                                <label class="form-label" id="price_label">Price (TZS) <span class="required-star">*</span></label>
                                <input type="number" class="form-control" name="order_price" id="order_price" step="0.01" min="0" required>
                                <div class="form-hint" id="price_hint">Price per share in TZS</div>
                            </div>
                            <div class="col-12 col-md-6">
                                <label class="form-label">Broker Code</label>
                                <input class="form-control" name="broker_code" id="broker_code" placeholder="e.g., B13/C" value="<?php echo htmlspecialchars($company['company_code'] ?? ''); ?>">
                            </div>
                        </div>

                        <div class="section-divider"></div>

                        <!-- Execution Details -->
                        <div class="row g-2 g-md-3">
                            <div class="col-6 col-md-3">
                                <label class="form-label" id="exec_qty_label">Executed Qty</label>
                                <input type="number" class="form-control" name="executed_quantity" id="executed_quantity" step="1" min="0">
                            </div>
                            <div class="col-6 col-md-3">
                                <label class="form-label" id="exec_price_label">Executed Price</label>
                                <input type="number" class="form-control" name="executed_price" id="executed_price" step="0.01" min="0">
                            </div>
                            <div class="col-6 col-md-3">
                                <label class="form-label">Trade Date</label>
                                <input type="date" class="form-control" name="trade_date" id="trade_date">
                            </div>
                            <div class="col-6 col-md-3">
                                <label class="form-label">Settlement Date</label>
                                <input type="date" class="form-control" name="settlement_date" id="settlement_date">
                            </div>
                            <div class="col-6 col-md-3">
                                <label class="form-label">Execution Time</label>
                                <input type="time" class="form-control" name="execution_time" id="execution_time">
                            </div>
                        </div>

                        <div class="section-divider"></div>

                        <!-- Receipt Upload -->
                        <div class="row g-2">
                            <div class="col-12">
                                <label class="form-label">Payment Receipt <span class="text-muted">(Optional)</span></label>
                                <input type="file" class="form-control" name="payment_receipts[]" id="receipt_files" accept="image/*,.pdf" multiple style="padding:6px 10px;">
                                <div class="form-hint">Allowed: JPG, PNG, GIF, PDF (Max 5MB each) &mdash; Select multiple files</div>
                            </div>
                            <div class="col-12">
                                <div id="fileList"></div>
                                <div id="receiptPreviews" class="receipt-thumbnails"></div>
                            </div>
                        </div>

                        <div class="section-divider"></div>

                        <!-- Remarks -->
                        <div class="row g-2">
                            <div class="col-12">
                                <label class="form-label">Remarks</label>
                                <textarea class="form-control" name="remarks" id="remarks" rows="2" placeholder="Any additional notes..."></textarea>
                            </div>
                        </div>

                        <div class="section-divider"></div>

                        <!-- Form Actions -->
                        <div class="row g-2">
                            <div class="col-6">
                                <button type="button" class="btn btn-outline-custom w-100" onclick="resetForm()">
                                    <i class="bi bi-arrow-counterclockwise me-1"></i> Reset
                                </button>
                            </div>
                            <div class="col-6">
                                <button type="submit" class="btn btn-primary-custom w-100" id="submitBtn">
                                    <i class="bi bi-save me-2"></i> Submit Order
                                </button>
                            </div>
                        </div>

                    </form>
                </div>
            </div>
        </div>

        <!-- ========================================= -->
        <!-- TAB 2: ORDERS LIST PANEL -->
        <!-- ========================================= -->
        <div class="tab-pane fade" id="orders-panel" role="tabpanel">
            
            <!-- Filter Section -->
            <div class="filter-section">
                <div class="row g-2 align-items-end">
                    <div class="col-12 col-md-auto">
                        <label class="form-label small fw-semibold">Quick Filters</label>
                        <div class="d-flex flex-wrap gap-1">
                            <a href="?filter=today" class="filter-btn <?php echo $filter === 'today' ? 'active' : ''; ?>">Today</a>
                            <a href="?filter=week" class="filter-btn <?php echo $filter === 'week' ? 'active' : ''; ?>">This Week</a>
                            <a href="?filter=month" class="filter-btn <?php echo $filter === 'month' ? 'active' : ''; ?>">This Month</a>
                            <a href="?filter=all" class="filter-btn <?php echo $filter === 'all' ? 'active' : ''; ?>">All</a>
                        </div>
                    </div>
                    <div class="col-12 col-md">
                        <form method="GET" action="order_dashboard_public.php" class="row g-2 align-items-end">
                            <input type="hidden" name="filter" value="<?php echo htmlspecialchars($filter); ?>">
                            <div class="col-6 col-md-3">
                                <label class="form-label small fw-semibold">Date From</label>
                                <input type="date" class="form-control form-control-sm" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>">
                            </div>
                            <div class="col-6 col-md-3">
                                <label class="form-label small fw-semibold">Date To</label>
                                <input type="date" class="form-control form-control-sm" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>">
                            </div>
                            <div class="col-8 col-md-4">
                                <label class="form-label small fw-semibold">Search</label>
                                <input type="text" class="form-control form-control-sm" name="search" placeholder="Client, Security, Ref..." value="<?php echo htmlspecialchars($search); ?>">
                            </div>
                            <div class="col-4 col-md-2">
                                <button type="submit" class="btn btn-primary btn-sm w-100">
                                    <i class="bi bi-search"></i>
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Orders Table -->
            <div class="card">
                <div class="card-header">
                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <span><i class="bi bi-list-ul me-2"></i>Orders List</span>
                        <span class="text-muted small"><?php echo count($orders); ?> orders found</span>
                    </div>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead>
                                <tr>
                                    <th>Ref</th>
                                    <th>Client</th>
                                    <th>Security</th>
                                    <th>Qty</th>
                                    <th>Price</th>
                                    <th>Value</th>
                                    <th>Date</th>
                                    <th>Status</th>
                                    <th>Receipt</th>
                                    <th class="text-end">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($orders)): ?>
                                    <tr>
                                        <td colspan="10" class="text-center py-4 text-muted">
                                            <i class="bi bi-inbox me-2"></i>No orders found for the selected period
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($orders as $order): 
                                        $isCancelled = (bool) $order['is_cancelled'];
                                        $rowClass = $isCancelled ? 'order-row-cancelled' : '';
                                        
                                        $isBond = ($order['asset_class'] ?? '') === 'bond';
                                        if ($isBond) {
                                            $displayQty = 'TZS ' . number_format(floatval($order['quantity'] ?? 0), 2);
                                            $displayPrice = number_format(floatval($order['order_price'] ?? 0), 4) . '%';
                                            $displayValue = 'TZS ' . number_format((floatval($order['order_price'] ?? 0) / 100) * floatval($order['quantity'] ?? 0), 2);
                                        } else {
                                            $displayQty = number_format(floatval($order['quantity'] ?? 0), 0);
                                            $displayPrice = 'TZS ' . number_format(floatval($order['order_price'] ?? 0), 2);
                                            $displayValue = 'TZS ' . number_format(floatval($order['order_value'] ?? 0), 2);
                                        }
                                        
                                        $status = $isCancelled ? 'cancelled' : ($order['payment_status'] ?? 'pending');
                                        $statusColors = ['pending' => 'pending', 'confirmed' => 'confirmed', 'rejected' => 'rejected', 'cancelled' => 'cancelled'];
                                        $statusLabels = ['pending' => 'Pending', 'confirmed' => 'Confirmed', 'rejected' => 'Rejected', 'cancelled' => 'Cancelled'];
                                        
                                        $hasReceipt = !empty($order['payment_receipt']);
                                        $receiptFiles = $hasReceipt ? explode(',', $order['payment_receipt']) : [];
                                    ?>
                                        <tr class="<?php echo $rowClass; ?>">
                                            <td><span class="fw-semibold"><?php echo htmlspecialchars($order['sheet_reference'] ?? 'N/A'); ?></span></td>
                                            <td><?php echo htmlspecialchars($order['client_name'] ?? ''); ?></td>
                                            <td><?php echo htmlspecialchars($order['security_id'] ?? ''); ?></td>
                                            <td><?php echo $displayQty; ?></td>
                                            <td><?php echo $displayPrice; ?></td>
                                            <td><?php echo $displayValue; ?></td>
                                            <td><?php echo htmlspecialchars($order['order_date'] ?? ''); ?></td>
                                            <td>
                                                <span class="badge-status <?php echo $statusColors[$status] ?? 'pending'; ?>">
                                                    <?php echo $statusLabels[$status] ?? 'Pending'; ?>
                                                </span>
                                                <?php if ($order['payment_confirmed_at']): ?>
                                                    <br><small class="text-success" style="font-size:0.55rem;">
                                                        <?php echo date('d/m/Y H:i', strtotime($order['payment_confirmed_at'])); ?>
                                                    </small>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($hasReceipt && !$isCancelled): ?>
                                                    <div class="receipt-thumbnails">
                                                        <?php 
                                                        $count = 0;
                                                        foreach ($receiptFiles as $file):
                                                            $file = trim($file);
                                                            if (empty($file)) continue;
                                                            $count++;
                                                            $filepath = '../uploads/payment_receipts/' . $file;
                                                            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
                                                            $isImage = in_array($ext, ['jpg', 'jpeg', 'png', 'gif']);
                                                            if ($isImage && file_exists($filepath)):
                                                        ?>
                                                            <img src="<?php echo $filepath; ?>" class="receipt-thumb" onclick="viewReceipt('<?php echo $filepath; ?>')" title="Click to view">
                                                        <?php else: ?>
                                                            <span class="badge bg-info" onclick="viewReceiptPDF('<?php echo $filepath; ?>')" style="cursor:pointer; font-size:0.6rem;">
                                                                <i class="bi bi-file-pdf"></i>
                                                            </span>
                                                        <?php endif; ?>
                                                        <?php endforeach; ?>
                                                        <?php if ($count > 1): ?>
                                                            <span class="badge bg-secondary" style="font-size:0.6rem;">+<?php echo $count - 1; ?></span>
                                                        <?php endif; ?>
                                                    </div>
                                                <?php else: ?>
                                                    <span class="text-muted" style="font-size:0.7rem;">No receipt</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-end">
                                                <?php if (!$isCancelled): ?>
                                                    <?php if ($order['payment_status'] === 'pending'): ?>
                                                        <button class="btn btn-success btn-sm-custom" onclick="confirmPayment(<?php echo $order['id']; ?>, '<?php echo htmlspecialchars($order['sheet_reference']); ?>')" title="Confirm Payment">
                                                            <i class="bi bi-check-circle"></i>
                                                        </button>
                                                        <button class="btn btn-danger btn-sm-custom" onclick="rejectPayment(<?php echo $order['id']; ?>, '<?php echo htmlspecialchars($order['sheet_reference']); ?>')" title="Reject Payment">
                                                            <i class="bi bi-x-circle"></i>
                                                        </button>
                                                    <?php endif; ?>
                                                    <button class="btn btn-secondary btn-sm-custom" onclick="cancelOrder(<?php echo $order['id']; ?>, '<?php echo htmlspecialchars($order['sheet_reference']); ?>')" title="Cancel Order">
                                                        <i class="bi bi-ban"></i>
                                                    </button>
                                                <?php else: ?>
                                                    <span class="text-muted" style="font-size:0.7rem;">Cancelled</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

    </div>

    <!-- Footer -->
    <div class="text-center text-muted small py-3">
        <i class="bi bi-shield-check me-1"></i> Secure Order Management System
    </div>
</div>

<!-- ========================================= -->
<!-- MODALS -->
<!-- ========================================= -->

<!-- Receipt Image Modal -->
<div class="modal fade" id="receiptModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title"><i class="bi bi-image me-2"></i>Payment Receipt</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body text-center">
                <img id="receiptViewImg" src="" alt="Receipt" style="max-width:100%; max-height:80vh;">
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <a id="receiptDownloadLink" href="#" target="_blank" class="btn btn-primary">Download</a>
            </div>
        </div>
    </div>
</div>

<!-- Receipt PDF Modal -->
<div class="modal fade" id="receiptPdfModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered" style="max-width:95%; height:90vh;">
        <div class="modal-content" style="height:100%;">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title"><i class="bi bi-file-pdf me-2"></i>PDF Receipt</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" style="height:calc(100% - 120px); padding:0;">
                <embed id="receiptPdfViewer" src="" type="application/pdf" width="100%" height="100%">
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <a id="receiptPdfDownloadLink" href="#" target="_blank" class="btn btn-primary">Download PDF</a>
            </div>
        </div>
    </div>
</div>

<!-- Confirm Payment Modal -->
<div class="modal fade" id="confirmModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title"><i class="bi bi-check-circle me-2"></i>Confirm Payment</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="confirmForm">
                <div class="modal-body">
                    <input type="hidden" name="order_id" id="confirm_order_id">
                    <input type="hidden" name="action" value="confirm_payment">
                    <p>Confirm payment for order: <strong id="confirm_ref"></strong></p>
                    <div class="mb-3">
                        <label class="form-label">Notes (Optional)</label>
                        <textarea class="form-control" name="notes" id="confirm_notes" rows="2" placeholder="Add any notes..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success">Confirm Payment</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Reject Payment Modal -->
<div class="modal fade" id="rejectModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title"><i class="bi bi-x-circle me-2"></i>Reject Payment</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="rejectForm">
                <div class="modal-body">
                    <input type="hidden" name="order_id" id="reject_order_id">
                    <input type="hidden" name="action" value="reject_payment">
                    <p>Reject payment for order: <strong id="reject_ref"></strong></p>
                    <div class="mb-3">
                        <label class="form-label">Reason <span class="text-danger">*</span></label>
                        <textarea class="form-control" name="reason" id="reject_reason" rows="2" placeholder="Reason for rejection..." required></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger">Reject Payment</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Cancel Order Modal -->
<div class="modal fade" id="cancelModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-secondary text-white">
                <h5 class="modal-title"><i class="bi bi-ban me-2"></i>Cancel Order</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="cancelForm">
                <div class="modal-body">
                    <input type="hidden" name="order_id" id="cancel_order_id">
                    <input type="hidden" name="action" value="cancel_order">
                    <div class="alert alert-warning">
                        <i class="bi bi-exclamation-triangle me-2"></i>
                        <strong>Warning:</strong> This action cannot be undone.
                    </div>
                    <p>Cancel order: <strong id="cancel_ref"></strong></p>
                    <div class="mb-3">
                        <label class="form-label">Cancellation Reason <span class="text-danger">*</span></label>
                        <textarea class="form-control" name="reason" id="cancel_reason" rows="2" placeholder="Reason for cancellation..." required></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-secondary">Cancel Order</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ========================================= -->
<!-- SCRIPTS -->
<!-- ========================================= -->
<script>
// ============================================
// CLOCK
// ============================================
function updateClock() {
    const now = new Date();
    document.getElementById('liveClock').textContent = 
        now.toLocaleDateString() + ' ' + now.toLocaleTimeString();
}
setInterval(updateClock, 1000);
updateClock();

// ============================================
// INITIALIZATION
// ============================================
document.addEventListener('DOMContentLoaded', function() {
    const today = new Date();
    const dateStr = today.toISOString().split('T')[0];
    document.getElementById('order_date').value = dateStr;
    document.getElementById('trade_date').value = dateStr;
    
    const settlement = new Date(today);
    settlement.setDate(settlement.getDate() + 2);
    document.getElementById('settlement_date').value = settlement.toISOString().split('T')[0];
    
    const timeStr = today.toTimeString().slice(0, 5);
    document.getElementById('order_time').value = timeStr;
    document.getElementById('execution_time').value = timeStr;
    
    updateFieldsForAssetClass();
    
    document.getElementById('asset_class').addEventListener('change', updateFieldsForAssetClass);
    document.getElementById('client_search').addEventListener('input', searchClients);
    document.getElementById('security_search').addEventListener('input', searchSecurities);
    document.getElementById('receipt_files').addEventListener('change', handleFilePreview);
    document.getElementById('orderForm').addEventListener('submit', submitForm);
    
    document.addEventListener('click', function(e) {
        if (!e.target.closest('#client_search')) {
            document.getElementById('client_search_dropdown').style.display = 'none';
        }
        if (!e.target.closest('#security_search')) {
            document.getElementById('security_search_dropdown').style.display = 'none';
        }
    });
});

// ============================================
// ASSET CLASS FIELD UPDATES
// ============================================
function updateFieldsForAssetClass() {
    const assetClass = document.getElementById('asset_class').value;
    const isBond = assetClass === 'bond';
    
    const quantityLabel = document.getElementById('quantity_label');
    const priceLabel = document.getElementById('price_label');
    const quantityInput = document.getElementById('quantity');
    const priceInput = document.getElementById('order_price');
    const quantityHint = document.getElementById('quantity_hint');
    const priceHint = document.getElementById('price_hint');
    const execQtyLabel = document.getElementById('exec_qty_label');
    const execPriceLabel = document.getElementById('exec_price_label');
    const execQtyInput = document.getElementById('executed_quantity');
    const execPriceInput = document.getElementById('executed_price');
    
    if (isBond) {
        quantityLabel.innerHTML = 'Face Value (TZS) <span class="required-star">*</span>';
        priceLabel.innerHTML = 'Price (% of Par) <span class="required-star">*</span>';
        quantityInput.placeholder = 'e.g., 100,000,000';
        quantityInput.step = '0.01';
        priceInput.placeholder = 'e.g., 98.5000';
        priceInput.step = '0.0001';
        quantityHint.textContent = 'Face value in TZS';
        priceHint.textContent = 'Percentage of par value (e.g., 98.5 = 98.5%)';
        execQtyLabel.textContent = 'Executed Face Value (TZS)';
        execPriceLabel.textContent = 'Executed Price (% of Par)';
        execQtyInput.step = '0.01';
        execPriceInput.step = '0.0001';
        execQtyInput.placeholder = 'e.g., 100,000,000';
        execPriceInput.placeholder = 'e.g., 98.5000';
    } else {
        quantityLabel.innerHTML = 'Quantity <span class="required-star">*</span>';
        priceLabel.innerHTML = 'Price (TZS) <span class="required-star">*</span>';
        quantityInput.placeholder = 'e.g., 1,000';
        quantityInput.step = '1';
        priceInput.placeholder = 'e.g., 2,500';
        priceInput.step = '0.01';
        quantityHint.textContent = 'Number of shares';
        priceHint.textContent = 'Price per share in TZS';
        execQtyLabel.textContent = 'Executed Quantity';
        execPriceLabel.textContent = 'Executed Price (TZS)';
        execQtyInput.step = '1';
        execPriceInput.step = '0.01';
        execQtyInput.placeholder = 'e.g., 1,000';
        execPriceInput.placeholder = 'e.g., 2,500';
    }
}

// ============================================
// CLIENT SEARCH
// ============================================
function searchClients() {
    const search = this.value.trim();
    const dropdown = document.getElementById('client_search_dropdown');
    
    if (search.length < 2) {
        dropdown.style.display = 'none';
        return;
    }
    
    fetch(window.location.href + '?ajax_action=search_clients&search=' + encodeURIComponent(search))
        .then(r => r.json())
        .then(data => {
            dropdown.innerHTML = '';
            if (data && data.length > 0) {
                data.forEach(c => {
                    const div = document.createElement('div');
                    div.className = 'item';
                    div.innerHTML = `
                        <strong>${escapeHtml(c.client_name)}</strong>
                        <span class="sub">CDS: ${escapeHtml(c.client_cds_account || 'N/A')}</span>
                    `;
                    div.onclick = () => {
                        document.getElementById('client_name').value = c.client_name;
                        document.getElementById('client_cds_account').value = c.client_cds_account || '';
                        document.getElementById('client_search').value = c.client_name;
                        dropdown.style.display = 'none';
                    };
                    dropdown.appendChild(div);
                });
                dropdown.style.display = 'block';
            } else {
                const div = document.createElement('div');
                div.className = 'item';
                div.innerHTML = `<em>No matching clients found. Type manually.</em>`;
                div.onclick = () => {
                    document.getElementById('client_name').value = search;
                    document.getElementById('client_search').value = search;
                    dropdown.style.display = 'none';
                };
                dropdown.appendChild(div);
                dropdown.style.display = 'block';
            }
        })
        .catch(() => { dropdown.style.display = 'none'; });
}

// ============================================
// SECURITY SEARCH
// ============================================
function searchSecurities() {
    const search = this.value.trim();
    const assetClass = document.getElementById('asset_class').value;
    const dropdown = document.getElementById('security_search_dropdown');
    
    if (search.length < 2) {
        dropdown.style.display = 'none';
        return;
    }
    
    fetch(window.location.href + '?ajax_action=get_securities&asset_class=' + encodeURIComponent(assetClass) + '&search=' + encodeURIComponent(search))
        .then(r => r.json())
        .then(data => {
            dropdown.innerHTML = '';
            if (data && data.length > 0) {
                data.forEach(s => {
                    const div = document.createElement('div');
                    div.className = 'item';
                    let info = '';
                    if (s.company_name) info += ' | ' + s.company_name;
                    if (s.coupon_rate) info += ' | Coupon: ' + s.coupon_rate + '%';
                    if (s.issuer) info += ' | ' + s.issuer;
                    div.innerHTML = `
                        <strong>${escapeHtml(s.security_id)}</strong> - ${escapeHtml(s.security_name)}
                        <span class="sub">${info || 'Click to select'}</span>
                    `;
                    div.onclick = () => {
                        document.getElementById('security_id').value = s.security_id;
                        document.getElementById('security_name').value = s.security_name;
                        document.getElementById('security_search').value = s.security_name;
                        dropdown.style.display = 'none';
                        
                        let infoHtml = '';
                        if (s.company_name) infoHtml += `<strong>Company:</strong> ${escapeHtml(s.company_name)}<br>`;
                        if (s.coupon_rate) infoHtml += `<strong>Coupon:</strong> ${s.coupon_rate}%<br>`;
                        if (s.issuer) infoHtml += `<strong>Issuer:</strong> ${escapeHtml(s.issuer)}<br>`;
                        if (s.maturity_date) infoHtml += `<strong>Maturity:</strong> ${s.maturity_date}<br>`;
                        document.getElementById('security_info').innerHTML = infoHtml;
                    };
                    dropdown.appendChild(div);
                });
                dropdown.style.display = 'block';
            } else {
                const div = document.createElement('div');
                div.className = 'item';
                div.innerHTML = `<em>No matching securities found. Type manually.</em>`;
                div.onclick = () => {
                    document.getElementById('security_id').value = search;
                    document.getElementById('security_name').value = search;
                    document.getElementById('security_search').value = search;
                    dropdown.style.display = 'none';
                };
                dropdown.appendChild(div);
                dropdown.style.display = 'block';
            }
        })
        .catch(() => { dropdown.style.display = 'none'; });
}

// ============================================
// FILE PREVIEW
// ============================================
function handleFilePreview() {
    const files = this.files;
    const fileList = document.getElementById('fileList');
    const previewContainer = document.getElementById('receiptPreviews');
    
    fileList.innerHTML = '';
    previewContainer.innerHTML = '';
    
    for (let i = 0; i < files.length; i++) {
        const file = files[i];
        
        const fileItem = document.createElement('div');
        fileItem.className = 'file-item';
        fileItem.innerHTML = `
            <span class="file-name" title="${escapeHtml(file.name)}">${escapeHtml(file.name)}</span>
            <span class="file-size">${(file.size / 1024).toFixed(1)} KB</span>
            <span class="remove-file" onclick="removeFile(${i})">&times;</span>
        `;
        fileList.appendChild(fileItem);
        
        if (file.type.startsWith('image/')) {
            const reader = new FileReader();
            reader.onload = function(e) {
                const img = document.createElement('img');
                img.src = e.target.result;
                img.className = 'receipt-thumb';
                img.title = file.name;
                previewContainer.appendChild(img);
            };
            reader.readAsDataURL(file);
        }
    }
}

function removeFile(index) {
    const input = document.getElementById('receipt_files');
    const dt = new DataTransfer();
    const files = input.files;
    
    for (let i = 0; i < files.length; i++) {
        if (i !== index) {
            dt.items.add(files[i]);
        }
    }
    input.files = dt.files;
    const event = new Event('change');
    input.dispatchEvent(event);
}

// ============================================
// FORM SUBMIT
// ============================================
function submitForm(e) {
    e.preventDefault();
    
    const clientName = document.getElementById('client_name').value.trim();
    const securityId = document.getElementById('security_id').value.trim();
    const quantity = document.getElementById('quantity').value;
    const price = document.getElementById('order_price').value;
    
    if (!clientName) {
        showAlert('danger', 'Please select a client from the search results.');
        document.getElementById('client_search').style.borderColor = '#dc3545';
        setTimeout(() => { document.getElementById('client_search').style.borderColor = ''; }, 2000);
        return;
    }
    
    if (!securityId) {
        showAlert('danger', 'Please select a security from the search results.');
        document.getElementById('security_search').style.borderColor = '#dc3545';
        setTimeout(() => { document.getElementById('security_search').style.borderColor = ''; }, 2000);
        return;
    }
    
    if (!quantity || parseFloat(quantity) <= 0) {
        showAlert('danger', 'Please enter a valid quantity.');
        document.getElementById('quantity').style.borderColor = '#dc3545';
        setTimeout(() => { document.getElementById('quantity').style.borderColor = ''; }, 2000);
        return;
    }
    
    if (!price || parseFloat(price) <= 0) {
        showAlert('danger', 'Please enter a valid price.');
        document.getElementById('order_price').style.borderColor = '#dc3545';
        setTimeout(() => { document.getElementById('order_price').style.borderColor = ''; }, 2000);
        return;
    }
    
    const btn = document.getElementById('submitBtn');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span> Submitting...';
    
    const formData = new FormData(document.getElementById('orderForm'));
    formData.append('ajax_action', 'save_order');
    
    fetch(window.location.href, {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showAlert('success', '✅ ' + data.message);
            setTimeout(() => {
                resetForm();
                btn.disabled = false;
                btn.innerHTML = '<i class="bi bi-save me-2"></i> Submit Order';
                // Switch to orders tab
                document.getElementById('orders-tab').click();
            }, 2000);
        } else {
            showAlert('danger', '❌ ' + (data.message || 'An error occurred.'));
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-save me-2"></i> Submit Order';
        }
    })
    .catch(error => {
        showAlert('danger', '❌ Connection error. Please try again.');
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-save me-2"></i> Submit Order';
    });
}

// ============================================
// ALERT
// ============================================
function showAlert(type, message) {
    const container = document.getElementById('alertContainer');
    const alert = document.createElement('div');
    alert.className = `alert alert-${type} alert-dismissible fade show`;
    alert.style.borderRadius = '8px';
    alert.style.padding = '10px 15px';
    alert.style.fontSize = '0.9rem';
    alert.innerHTML = `
        ${message}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    `;
    container.appendChild(alert);
    
    setTimeout(() => {
        if (alert.parentNode) {
            alert.classList.remove('show');
            setTimeout(() => { alert.remove(); }, 300);
        }
    }, 6000);
}

// ============================================
// RESET FORM
// ============================================
function resetForm() {
    document.getElementById('orderForm').reset();
    document.getElementById('client_name').value = '';
    document.getElementById('client_cds_account').value = '';
    document.getElementById('client_search').value = '';
    document.getElementById('security_id').value = '';
    document.getElementById('security_name').value = '';
    document.getElementById('security_search').value = '';
    document.getElementById('security_info').innerHTML = '';
    document.getElementById('receiptPreviews').innerHTML = '';
    document.getElementById('fileList').innerHTML = '';
    document.getElementById('receipt_files').value = '';
    
    const today = new Date();
    const dateStr = today.toISOString().split('T')[0];
    document.getElementById('order_date').value = dateStr;
    document.getElementById('trade_date').value = dateStr;
    
    const settlement = new Date(today);
    settlement.setDate(settlement.getDate() + 2);
    document.getElementById('settlement_date').value = settlement.toISOString().split('T')[0];
    
    const timeStr = today.toTimeString().slice(0, 5);
    document.getElementById('order_time').value = timeStr;
    document.getElementById('execution_time').value = timeStr;
    
    document.getElementById('client_search_dropdown').style.display = 'none';
    document.getElementById('security_search_dropdown').style.display = 'none';
    document.getElementById('asset_class').dispatchEvent(new Event('change'));
}

// ============================================
// RECEIPT VIEW
// ============================================
function viewReceipt(path) {
    document.getElementById('receiptViewImg').src = path;
    document.getElementById('receiptDownloadLink').href = path;
    new bootstrap.Modal(document.getElementById('receiptModal')).show();
}

function viewReceiptPDF(path) {
    document.getElementById('receiptPdfViewer').src = path;
    document.getElementById('receiptPdfDownloadLink').href = path;
    new bootstrap.Modal(document.getElementById('receiptPdfModal')).show();
}

// ============================================
// CONFIRM PAYMENT
// ============================================
function confirmPayment(id, ref) {
    document.getElementById('confirm_order_id').value = id;
    document.getElementById('confirm_ref').textContent = ref;
    document.getElementById('confirm_notes').value = '';
    new bootstrap.Modal(document.getElementById('confirmModal')).show();
}

document.getElementById('confirmForm').addEventListener('submit', function(e) {
    e.preventDefault();
    const btn = this.querySelector('button[type="submit"]');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span> Processing...';
    
    fetch(window.location.href, {
        method: 'POST',
        body: new FormData(this)
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            alert('✅ ' + data.message);
            location.reload();
        } else {
            alert('❌ ' + data.message);
            btn.disabled = false;
            btn.innerHTML = 'Confirm Payment';
        }
    })
    .catch(() => {
        alert('❌ An error occurred. Please try again.');
        btn.disabled = false;
        btn.innerHTML = 'Confirm Payment';
    });
});

// ============================================
// REJECT PAYMENT
// ============================================
function rejectPayment(id, ref) {
    document.getElementById('reject_order_id').value = id;
    document.getElementById('reject_ref').textContent = ref;
    document.getElementById('reject_reason').value = '';
    new bootstrap.Modal(document.getElementById('rejectModal')).show();
}

document.getElementById('rejectForm').addEventListener('submit', function(e) {
    e.preventDefault();
    const btn = this.querySelector('button[type="submit"]');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span> Processing...';
    
    fetch(window.location.href, {
        method: 'POST',
        body: new FormData(this)
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            alert('✅ ' + data.message);
            location.reload();
        } else {
            alert('❌ ' + data.message);
            btn.disabled = false;
            btn.innerHTML = 'Reject Payment';
        }
    })
    .catch(() => {
        alert('❌ An error occurred. Please try again.');
        btn.disabled = false;
        btn.innerHTML = 'Reject Payment';
    });
});

// ============================================
// CANCEL ORDER
// ============================================
function cancelOrder(id, ref) {
    document.getElementById('cancel_order_id').value = id;
    document.getElementById('cancel_ref').textContent = ref;
    document.getElementById('cancel_reason').value = '';
    new bootstrap.Modal(document.getElementById('cancelModal')).show();
}

document.getElementById('cancelForm').addEventListener('submit', function(e) {
    e.preventDefault();
    const btn = this.querySelector('button[type="submit"]');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span> Processing...';
    
    if (!confirm('⚠️ Are you sure you want to cancel this order? This cannot be undone.')) {
        btn.disabled = false;
        btn.innerHTML = 'Cancel Order';
        return;
    }
    
    fetch(window.location.href, {
        method: 'POST',
        body: new FormData(this)
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            alert('✅ ' + data.message);
            location.reload();
        } else {
            alert('❌ ' + data.message);
            btn.disabled = false;
            btn.innerHTML = 'Cancel Order';
        }
    })
    .catch(() => {
        alert('❌ An error occurred. Please try again.');
        btn.disabled = false;
        btn.innerHTML = 'Cancel Order';
    });
});

// ============================================
// UTILITY
// ============================================
function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}
</script>

</body>
</html>
