<?php
/**
 * Public Order Entry Form - Standalone page without login
 * Accessible from mobile phones and PCs
 * 
 * Usage: order_entry_public.php
 */

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/order_entry_public_errors.log');

// Start session for alerts
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Include database configuration
require_once '../config/config.php';

// Get database connection
try {
    $db = getDBConnection();
} catch (Exception $e) {
    die("Database connection failed: " . $e->getMessage());
}

// Get company details for broker code
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

// Handle AJAX requests
if (isset($_GET['ajax_action'])) {
    header('Content-Type: application/json');
    
    if ($_GET['ajax_action'] === 'search_clients') {
        $search = $_GET['search'] ?? '';
        
        try {
            // Search in clients table first
            $stmt = $db->prepare("SELECT client_name, cds_account as client_cds_account FROM clients WHERE client_name LIKE ? LIMIT 30");
            $stmt->execute(['%' . $search . '%']);
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // If no results, search in trades table
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
            // For bonds: Value = (Price% / 100) × Face Value
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
            recorded_at, created_at, dealer_name, payment_receipt
        ) VALUES (
            :sheet_reference, :client_name, :client_cds_account, :security_id, :security_name,
            :order_type, :asset_class, :quantity, :order_price, :order_value, :order_date, :order_time,
            :priority, :remarks, :broker_code, :executed_quantity, :executed_price,
            :trade_date, :settlement_date, :execution_time, 'order', 'pending',
            NOW(), NOW(), :dealer_name, :payment_receipt
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
// PAGE RENDERING
// ============================================
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>Order Entry Form</title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        :root {
            --primary-color: #1a237e;
            --primary-light: #0d47a1;
            --success-color: #2e7d32;
            --border-radius: 12px;
        }
        
        * {
            box-sizing: border-box;
        }
        
        body {
            background: #f0f2f5;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
            padding: 15px;
            min-height: 100vh;
        }
        
        .container-custom {
            max-width: 920px;
            margin: 0 auto;
        }
        
        .header-logo {
            background: linear-gradient(135deg, var(--primary-color), var(--primary-light));
            color: white;
            padding: 20px 25px;
            border-radius: var(--border-radius);
            margin-bottom: 20px;
            text-align: center;
        }
        
        .header-logo h1 {
            font-size: 1.5rem;
            font-weight: 700;
            margin: 0;
        }
        
        .header-logo small {
            opacity: 0.8;
            font-weight: 300;
        }
        
        .header-logo .badge-public {
            background: rgba(255,255,255,0.2);
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.7rem;
            margin-top: 5px;
            display: inline-block;
        }
        
        .card {
            border-radius: var(--border-radius);
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
            border: none;
            margin-bottom: 20px;
            background: white;
        }
        
        .card-header {
            background: transparent;
            border-bottom: 2px solid #f0f0f0;
            padding: 16px 22px;
            font-weight: 600;
        }
        
        .card-header .bi {
            color: var(--primary-color);
        }
        
        .card-body {
            padding: 22px;
        }
        
        .form-label {
            font-weight: 600;
            font-size: 0.85rem;
            color: #333;
            margin-bottom: 4px;
        }
        
        .form-control, .form-select {
            border-radius: 8px;
            border: 1.5px solid #e0e0e0;
            padding: 10px 14px;
            font-size: 0.95rem;
            transition: all 0.3s;
            -webkit-appearance: none;
            appearance: none;
        }
        
        .form-control:focus, .form-select:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(26, 35, 126, 0.1);
        }
        
        .form-control::placeholder {
            color: #aaa;
            font-size: 0.85rem;
        }
        
        .required-star {
            color: #dc3545;
            margin-left: 2px;
        }
        
        .btn-primary-custom {
            background: linear-gradient(135deg, var(--primary-color), var(--primary-light));
            border: none;
            padding: 12px 35px;
            font-weight: 600;
            border-radius: 8px;
            color: white;
            transition: all 0.3s;
            width: 100%;
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
            padding: 10px 20px;
            font-weight: 600;
            border-radius: 8px;
            transition: all 0.3s;
            width: 100%;
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
            max-height: 220px;
            overflow-y: auto;
            z-index: 9999;
            width: 100%;
            display: none;
            box-shadow: 0 4px 12px rgba(0,0,0,0.12);
        }
        
        .search-dropdown .item {
            padding: 10px 14px;
            cursor: pointer;
            border-bottom: 1px solid #f5f5f5;
            transition: background 0.2s;
        }
        
        .search-dropdown .item:hover {
            background: #f0f4ff;
        }
        
        .search-dropdown .item .sub {
            font-size: 0.75rem;
            color: #6c757d;
            display: block;
        }
        
        .position-relative {
            position: relative;
        }
        
        .security-info {
            font-size: 0.8rem;
            color: #6c757d;
            margin-top: 4px;
            padding: 4px 10px;
            background: #f8f9fa;
            border-radius: 4px;
            min-height: 24px;
        }
        
        .receipt-preview {
            max-width: 70px;
            max-height: 50px;
            object-fit: cover;
            border-radius: 4px;
            margin: 2px;
            border: 1px solid #ddd;
        }
        
        .file-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 5px 12px;
            background: #f8f9fa;
            border-radius: 4px;
            margin-bottom: 3px;
            font-size: 0.85rem;
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
        
        .receipt-thumbnails {
            display: flex;
            gap: 4px;
            flex-wrap: wrap;
            align-items: center;
            margin-top: 5px;
        }
        
        .section-divider {
            border-top: 2px dashed #e8e8e8;
            margin: 18px 0;
        }
        
        .form-hint {
            font-size: 0.7rem;
            color: #6c757d;
            margin-top: 2px;
        }
        
        .alert-custom {
            border-radius: 8px;
            padding: 12px 18px;
            font-size: 0.9rem;
        }
        
        .clock-display {
            font-size: 0.8rem;
            opacity: 0.8;
            font-weight: 300;
        }
        
        @media (max-width: 768px) {
            body { padding: 10px; }
            .container-custom { max-width: 100%; }
            .card-body { padding: 16px; }
            .header-logo h1 { font-size: 1.2rem; }
            .btn-primary-custom, .btn-outline-custom { 
                padding: 10px 20px;
                font-size: 0.9rem;
            }
            .form-control, .form-select { font-size: 16px; } /* Prevents zoom on iOS */
        }
        
        @media (max-width: 480px) {
            .col-md-3, .col-md-4, .col-md-6 {
                padding-left: 6px;
                padding-right: 6px;
            }
            .row.g-3 { --bs-gutter-y: 0.5rem; }
            .card-header { padding: 12px 16px; }
            .card-header h6 { font-size: 0.9rem; }
        }
        
        /* Spinner */
        .spinner-border-sm {
            width: 1rem;
            height: 1rem;
        }
        
        /* Custom scrollbar */
        .search-dropdown::-webkit-scrollbar {
            width: 4px;
        }
        .search-dropdown::-webkit-scrollbar-track {
            background: #f1f1f1;
        }
        .search-dropdown::-webkit-scrollbar-thumb {
            background: #ccc;
            border-radius: 4px;
        }
    </style>
</head>
<body>

<div class="container-custom">

    <!-- Header -->
    <div class="header-logo">
        <h1>
            <i class="bi bi-building me-2"></i><?php echo htmlspecialchars($company['company_name'] ?? 'Victory Financial Services'); ?>
        </h1>
        <small>Order Entry Form</small>
        <div class="badge-public">
            <i class="bi bi-globe me-1"></i> Public Access
            <span class="clock-display ms-2" id="currentDateTime"></span>
        </div>
    </div>

    <!-- Main Form Card -->
    <div class="card">
        <div class="card-header">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                <h6 class="mb-0"><i class="bi bi-plus-circle me-2"></i>New Order</h6>
                <span class="text-muted small">
                    <i class="bi bi-asterisk text-danger me-1"></i> Required fields
                </span>
            </div>
        </div>
        <div class="card-body">

            <!-- Alert Container -->
            <div id="alertContainer"></div>

            <form id="orderForm" enctype="multipart/form-data">

                <!-- ========================================= -->
                <!-- SECTION 1: ORDER BASIC INFO -->
                <!-- ========================================= -->
                <h6 class="fw-bold text-primary mb-2" style="font-size:0.85rem;">
                    <i class="bi bi-info-circle me-2"></i>Order Information
                </h6>
                <div class="row g-2 g-md-3">
                    <div class="col-6 col-md-4">
                        <label class="form-label">Order Type <span class="required-star">*</span></label>
                        <select class="form-select" name="order_type" id="order_type" required>
                            <option value="buy">BUY</option>
                            <option value="sell">SELL</option>
                        </select>
                    </div>
                    <div class="col-6 col-md-4">
                        <label class="form-label">Priority</label>
                        <select class="form-select" name="priority" id="priority">
                            <option value="normal">Normal</option>
                            <option value="urgent">Urgent</option>
                            <option value="most_important">Most Important</option>
                        </select>
                    </div>
                    <div class="col-6 col-md-4">
                        <label class="form-label">Asset Class <span class="required-star">*</span></label>
                        <select class="form-select" name="asset_class" id="asset_class" required>
                            <option value="equity">Equity / Shares</option>
                            <option value="bond">Bond</option>
                            <option value="etf">ETF</option>
                        </select>
                    </div>
                    <div class="col-6 col-md-3">
                        <label class="form-label">Order Date</label>
                        <input type="date" class="form-control" name="order_date" id="order_date">
                    </div>
                    <div class="col-6 col-md-3">
                        <label class="form-label">Order Time</label>
                        <input type="time" class="form-control" name="order_time" id="order_time">
                    </div>
                    <div class="col-12 col-md-6">
                        <label class="form-label">Broker Code</label>
                        <input class="form-control" name="broker_code" id="broker_code" placeholder="e.g., B13/C" value="<?php echo htmlspecialchars($company['company_code'] ?? ''); ?>">
                    </div>
                </div>

                <div class="section-divider"></div>

                <!-- ========================================= -->
                <!-- SECTION 2: CLIENT & SECURITY -->
                <!-- ========================================= -->
                <h6 class="fw-bold text-primary mb-2" style="font-size:0.85rem;">
                    <i class="bi bi-person me-2"></i>Client & Security Details
                </h6>
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

                <!-- ========================================= -->
                <!-- SECTION 3: QUANTITY & PRICE -->
                <!-- ========================================= -->
                <h6 class="fw-bold text-primary mb-2" style="font-size:0.85rem;">
                    <i class="bi bi-calculator me-2"></i>Quantity & Price
                </h6>
                <div class="row g-2 g-md-3">
                    <div class="col-6 col-md-4">
                        <label class="form-label" id="quantity_label">Quantity <span class="required-star">*</span></label>
                        <input type="number" class="form-control" name="quantity" id="quantity" step="1" min="1" required>
                        <div class="form-hint" id="quantity_hint">Number of shares</div>
                    </div>
                    <div class="col-6 col-md-4">
                        <label class="form-label" id="price_label">Price (TZS) <span class="required-star">*</span></label>
                        <input type="number" class="form-control" name="order_price" id="order_price" step="0.01" min="0" required>
                        <div class="form-hint" id="price_hint">Price per share in TZS</div>
                    </div>
                </div>

                <div class="section-divider"></div>

                <!-- ========================================= -->
                <!-- SECTION 4: EXECUTION DETAILS -->
                <!-- ========================================= -->
                <h6 class="fw-bold text-primary mb-2" style="font-size:0.85rem;">
                    <i class="bi bi-check2-circle me-2"></i>Execution Details
                    <span class="text-muted fw-normal" style="font-size:0.75rem;">(Optional)</span>
                </h6>
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

                <!-- ========================================= -->
                <!-- SECTION 5: RECEIPT UPLOAD -->
                <!-- ========================================= -->
                <h6 class="fw-bold text-primary mb-2" style="font-size:0.85rem;">
                    <i class="bi bi-file-earmark-image me-2"></i>Payment Receipt
                    <span class="text-muted fw-normal" style="font-size:0.75rem;">(Optional)</span>
                </h6>
                <div class="row g-2">
                    <div class="col-12">
                        <input type="file" class="form-control" name="payment_receipts[]" id="receipt_files" accept="image/*,.pdf" multiple style="padding:8px 12px;">
                        <div class="form-hint">Allowed: JPG, PNG, GIF, PDF (Max 5MB each) &mdash; Select multiple files</div>
                    </div>
                    <div class="col-12">
                        <div id="fileList"></div>
                        <div id="receiptPreviews" class="receipt-thumbnails"></div>
                    </div>
                </div>

                <div class="section-divider"></div>

                <!-- ========================================= -->
                <!-- SECTION 6: REMARKS -->
                <!-- ========================================= -->
                <div class="row g-2">
                    <div class="col-12">
                        <label class="form-label">Remarks</label>
                        <textarea class="form-control" name="remarks" id="remarks" rows="2" placeholder="Any additional notes..."></textarea>
                    </div>
                </div>

                <div class="section-divider"></div>

                <!-- ========================================= -->
                <!-- FORM ACTIONS -->
                <!-- ========================================= -->
                <div class="row g-2">
                    <div class="col-6">
                        <button type="button" class="btn btn-outline-custom" onclick="resetForm()">
                            <i class="bi bi-arrow-counterclockwise me-1"></i> Reset
                        </button>
                    </div>
                    <div class="col-6">
                        <button type="submit" class="btn btn-primary-custom" id="submitBtn">
                            <i class="bi bi-save me-2"></i> Submit
                        </button>
                    </div>
                </div>

            </form>
        </div>
    </div>

    <!-- Footer -->
    <div class="text-center text-muted small py-3">
        <i class="bi bi-shield-check me-1"></i> This form is for internal use only.<br>
        Data is submitted to the system securely.
    </div>

</div>

<!-- ========================================= -->
<!-- SCRIPTS -->
<!-- ========================================= -->
<script>
// ============================================
// CONFIGURATION
// ============================================
const API_URL = window.location.href;

// ============================================
// INITIALIZATION
// ============================================
document.addEventListener('DOMContentLoaded', function() {
    // Set default dates
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
    
    // Update clock
    updateClock();
    setInterval(updateClock, 1000);
    
    // Initialize fields
    updateFieldsForAssetClass();
    
    // Event listeners
    document.getElementById('asset_class').addEventListener('change', updateFieldsForAssetClass);
    document.getElementById('client_search').addEventListener('input', searchClients);
    document.getElementById('security_search').addEventListener('input', searchSecurities);
    document.getElementById('receipt_files').addEventListener('change', handleFilePreview);
    document.getElementById('orderForm').addEventListener('submit', submitForm);
    
    // Close dropdowns on outside click
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
// CLOCK
// ============================================
function updateClock() {
    const now = new Date();
    document.getElementById('currentDateTime').textContent = 
        now.toLocaleDateString('en-US', { day: '2-digit', month: 'short', year: 'numeric' }) +
        ' ' + now.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' });
}

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
    
    fetch(API_URL + '?ajax_action=search_clients&search=' + encodeURIComponent(search))
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
                // Allow manual entry
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
        .catch(() => {
            dropdown.style.display = 'none';
        });
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
    
    fetch(API_URL + '?ajax_action=get_securities&asset_class=' + encodeURIComponent(assetClass) + '&search=' + encodeURIComponent(search))
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
        .catch(() => {
            dropdown.style.display = 'none';
        });
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
                img.className = 'receipt-preview';
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
    
    // Validate required fields
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
    
    // Disable button
    const btn = document.getElementById('submitBtn');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span> Submitting...';
    
    // Build FormData
    const formData = new FormData(document.getElementById('orderForm'));
    formData.append('ajax_action', 'save_order');
    
    fetch(API_URL, {
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
                btn.innerHTML = '<i class="bi bi-save me-2"></i> Submit';
                window.scrollTo({ top: 0, behavior: 'smooth' });
            }, 2000);
        } else {
            showAlert('danger', '❌ ' + (data.message || 'An error occurred.'));
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-save me-2"></i> Submit';
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showAlert('danger', '❌ Connection error. Please try again.');
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-save me-2"></i> Submit';
    });
}

// ============================================
// ALERT
// ============================================
function showAlert(type, message) {
    const container = document.getElementById('alertContainer');
    const alert = document.createElement('div');
    alert.className = `alert alert-${type} alert-dismissible fade show alert-custom`;
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
