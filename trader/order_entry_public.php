<?php
/**
 * Public Order View - View submitted orders without login
 * Includes payment confirmation by accountant
 * 
 * Usage: order_view_public.php
 */

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/order_view_errors.log');

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
            
            if ($stmt->rowCount() > 0) {
                echo json_encode(['success' => true, 'message' => 'Payment confirmed successfully!']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Order not found or already cancelled.']);
            }
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
            
            if ($stmt->rowCount() > 0) {
                echo json_encode(['success' => true, 'message' => 'Payment rejected successfully.']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Order not found or already cancelled.']);
            }
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
            
            if ($stmt->rowCount() > 0) {
                echo json_encode(['success' => true, 'message' => 'Order cancelled successfully.']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Order not found.']);
            }
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
        }
        exit;
    }
}

// ============================================
// FILTERS
// ============================================
$filter = $_GET['filter'] ?? 'all';
$search = $_GET['search'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';

// ============================================
// GET ORDERS
// ============================================
function getOrders($db, $filter, $search, $date_from, $date_to) {
    $sql = "SELECT * FROM dealing_sheets WHERE 1=1";
    $params = [];
    
    // Filter by status
    if ($filter === 'pending') {
        $sql .= " AND payment_status = 'pending' AND is_cancelled = 0";
    } elseif ($filter === 'confirmed') {
        $sql .= " AND payment_status = 'confirmed' AND is_cancelled = 0";
    } elseif ($filter === 'rejected') {
        $sql .= " AND payment_status = 'rejected' AND is_cancelled = 0";
    } elseif ($filter === 'cancelled') {
        $sql .= " AND is_cancelled = 1";
    }
    
    // Search
    if (!empty($search)) {
        $sql .= " AND (client_name LIKE ? OR security_id LIKE ? OR sheet_reference LIKE ?)";
        $searchParam = '%' . $search . '%';
        $params[] = $searchParam;
        $params[] = $searchParam;
        $params[] = $searchParam;
    }
    
    // Date range
    if (!empty($date_from)) {
        $sql .= " AND order_date >= ?";
        $params[] = $date_from;
    }
    if (!empty($date_to)) {
        $sql .= " AND order_date <= ?";
        $params[] = $date_to;
    }
    
    $sql .= " ORDER BY 
        CASE payment_status 
            WHEN 'pending' THEN 1 
            WHEN 'confirmed' THEN 2 
            WHEN 'rejected' THEN 3 
            ELSE 4 
        END,
        created_at DESC";
    
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Get stats
function getStats($db) {
    $stats = [];
    
    $stmt = $db->query("SELECT COUNT(*) FROM dealing_sheets WHERE payment_status = 'pending' AND is_cancelled = 0");
    $stats['pending'] = $stmt->fetchColumn();
    
    $stmt = $db->query("SELECT COUNT(*) FROM dealing_sheets WHERE payment_status = 'confirmed' AND is_cancelled = 0");
    $stats['confirmed'] = $stmt->fetchColumn();
    
    $stmt = $db->query("SELECT COUNT(*) FROM dealing_sheets WHERE payment_status = 'rejected' AND is_cancelled = 0");
    $stats['rejected'] = $stmt->fetchColumn();
    
    $stmt = $db->query("SELECT COUNT(*) FROM dealing_sheets WHERE is_cancelled = 1");
    $stats['cancelled'] = $stmt->fetchColumn();
    
    $stmt = $db->query("SELECT COUNT(*) FROM dealing_sheets");
    $stats['total'] = $stmt->fetchColumn();
    
    return $stats;
}

$orders = getOrders($db, $filter, $search, $date_from, $date_to);
$stats = getStats($db);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>Order Management - Public View</title>
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
            padding: 15px;
            min-height: 100vh;
        }
        
        .container-custom {
            max-width: 1200px;
            margin: 0 auto;
        }
        
        .header {
            background: linear-gradient(135deg, var(--primary-color), #0d47a1);
            color: white;
            padding: 20px 25px;
            border-radius: 12px;
            margin-bottom: 20px;
        }
        
        .header h1 {
            font-size: 1.5rem;
            font-weight: 700;
            margin: 0;
        }
        
        .header small {
            opacity: 0.8;
            font-weight: 300;
        }
        
        .stat-card {
            border-radius: 12px;
            padding: 15px 20px;
            color: white;
            text-align: center;
            transition: transform 0.2s;
        }
        
        .stat-card:hover {
            transform: translateY(-3px);
        }
        
        .stat-card .number {
            font-size: 2rem;
            font-weight: 700;
        }
        
        .stat-card .label {
            font-size: 0.75rem;
            opacity: 0.9;
        }
        
        .stat-card.pending { background: linear-gradient(135deg, #f57c00, #e65100); }
        .stat-card.confirmed { background: linear-gradient(135deg, #2e7d32, #1b5e20); }
        .stat-card.rejected { background: linear-gradient(135deg, #c62828, #b71c1c); }
        .stat-card.cancelled { background: linear-gradient(135deg, #455a64, #263238); }
        .stat-card.total { background: linear-gradient(135deg, #1a237e, #0d47a1); }
        
        .card {
            border-radius: 12px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.06);
            border: none;
            margin-bottom: 20px;
        }
        
        .card-header {
            background: transparent;
            border-bottom: 2px solid #f0f0f0;
            padding: 14px 20px;
            font-weight: 600;
        }
        
        .card-body {
            padding: 20px;
            overflow-x: auto;
        }
        
        .filter-section {
            background: white;
            border-radius: 12px;
            padding: 15px 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.06);
        }
        
        .badge-status {
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
        }
        
        .badge-status.pending { background: #fff3e0; color: #e65100; }
        .badge-status.confirmed { background: #e8f5e9; color: #1b5e20; }
        .badge-status.rejected { background: #ffebee; color: #c62828; }
        .badge-status.cancelled { background: #eceff1; color: #455a64; }
        
        .table th {
            font-size: 0.75rem;
            text-transform: uppercase;
            color: #6c757d;
            font-weight: 700;
            border-bottom: 2px solid #e0e0e0;
        }
        
        .table td {
            font-size: 0.85rem;
            vertical-align: middle;
        }
        
        .btn-sm-custom {
            padding: 4px 10px;
            font-size: 0.75rem;
            border-radius: 6px;
        }
        
        .receipt-thumb {
            max-width: 50px;
            max-height: 40px;
            object-fit: cover;
            border-radius: 4px;
            cursor: pointer;
            border: 1px solid #ddd;
        }
        
        .receipt-thumb:hover {
            border-color: var(--primary-color);
        }
        
        .modal-body img, .modal-body embed {
            max-width: 100%;
            max-height: 80vh;
        }
        
        @media (max-width: 768px) {
            body { padding: 10px; }
            .header h1 { font-size: 1.2rem; }
            .stat-card .number { font-size: 1.5rem; }
            .stat-card { padding: 10px 15px; }
            .table-responsive { font-size: 0.8rem; }
            .btn-sm-custom { font-size: 0.65rem; padding: 2px 6px; }
        }
        
        @media (max-width: 480px) {
            .stat-card .number { font-size: 1.2rem; }
            .filter-section .row > div { margin-bottom: 8px; }
        }
        
        .order-row-cancelled {
            background-color: #f5f5f5 !important;
            opacity: 0.7;
        }
        
        .order-row-cancelled td {
            text-decoration: line-through;
        }
        
        .receipt-thumbnails {
            display: flex;
            gap: 3px;
            flex-wrap: wrap;
        }
        
        .view-count {
            font-size: 0.7rem;
            color: #6c757d;
        }
    </style>
</head>
<body>

<div class="container-custom">

    <!-- Header -->
    <div class="header">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <h1><i class="bi bi-clipboard-data me-2"></i>Order Management</h1>
                <small>View and confirm payment receipts</small>
            </div>
            <div>
                <a href="order_entry_public.php" class="btn btn-light btn-sm">
                    <i class="bi bi-plus-circle me-1"></i> New Order
                </a>
                <span class="badge bg-light text-dark ms-2" id="liveClock"></span>
            </div>
        </div>
    </div>

    <!-- Stats -->
    <div class="row g-2 g-md-3 mb-4">
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

    <!-- Filters -->
    <div class="filter-section">
        <form method="GET" action="order_view_public.php" class="row g-2 align-items-end">
            <div class="col-12 col-md-2">
                <label class="form-label small fw-semibold">Filter</label>
                <select class="form-select form-select-sm" name="filter">
                    <option value="all" <?php echo $filter === 'all' ? 'selected' : ''; ?>>All Orders</option>
                    <option value="pending" <?php echo $filter === 'pending' ? 'selected' : ''; ?>>Pending</option>
                    <option value="confirmed" <?php echo $filter === 'confirmed' ? 'selected' : ''; ?>>Confirmed</option>
                    <option value="rejected" <?php echo $filter === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                    <option value="cancelled" <?php echo $filter === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                </select>
            </div>
            <div class="col-12 col-md-3">
                <label class="form-label small fw-semibold">Search</label>
                <input type="text" class="form-control form-control-sm" name="search" placeholder="Client, Security, Ref..." value="<?php echo htmlspecialchars($search); ?>">
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small fw-semibold">Date From</label>
                <input type="date" class="form-control form-control-sm" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>">
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small fw-semibold">Date To</label>
                <input type="date" class="form-control form-control-sm" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>">
            </div>
            <div class="col-12 col-md-3">
                <button type="submit" class="btn btn-primary btn-sm w-100">
                    <i class="bi bi-funnel me-1"></i> Apply Filters
                </button>
            </div>
        </form>
    </div>

    <!-- Orders Table -->
    <div class="card">
        <div class="card-header">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                <span><i class="bi bi-list-ul me-2"></i>Orders</span>
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
                            <th>Views</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($orders)): ?>
                            <tr>
                                <td colspan="11" class="text-center py-4 text-muted">
                                    <i class="bi bi-inbox me-2"></i>No orders found
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
                                $statusColors = [
                                    'pending' => 'pending',
                                    'confirmed' => 'confirmed',
                                    'rejected' => 'rejected',
                                    'cancelled' => 'cancelled'
                                ];
                                $statusLabels = [
                                    'pending' => 'Pending',
                                    'confirmed' => 'Confirmed',
                                    'rejected' => 'Rejected',
                                    'cancelled' => 'Cancelled'
                                ];
                                
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
                                                    <span class="badge bg-info" onclick="viewReceiptPDF('<?php echo $filepath; ?>')" style="cursor:pointer;">
                                                        <i class="bi bi-file-pdf"></i>
                                                    </span>
                                                <?php endif; ?>
                                                <?php endforeach; ?>
                                                <?php if ($count > 1): ?>
                                                    <span class="badge bg-secondary">+<?php echo $count - 1; ?></span>
                                                <?php endif; ?>
                                            </div>
                                        <?php else: ?>
                                            <span class="text-muted small">No receipt</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="view-count" title="Views">
                                            <i class="bi bi-eye"></i> <?php echo $order['viewed_count'] ?? 0; ?>
                                        </span>
                                        <?php if ($order['payment_confirmed_at']): ?>
                                            <br><small class="text-success" style="font-size:0.6rem;">
                                                <?php echo date('d/m/Y H:i', strtotime($order['payment_confirmed_at'])); ?>
                                            </small>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end">
                                        <?php if (!$isCancelled): ?>
                                            <?php if ($order['payment_status'] === 'pending'): ?>
                                                <button class="btn btn-success btn-sm-custom" onclick="confirmPayment(<?php echo $order['id']; ?>)">
                                                    <i class="bi bi-check-circle"></i>
                                                </button>
                                                <button class="btn btn-danger btn-sm-custom" onclick="rejectPayment(<?php echo $order['id']; ?>)">
                                                    <i class="bi bi-x-circle"></i>
                                                </button>
                                            <?php endif; ?>
                                            <button class="btn btn-secondary btn-sm-custom" onclick="cancelOrder(<?php echo $order['id']; ?>)">
                                                <i class="bi bi-ban"></i>
                                            </button>
                                        <?php else: ?>
                                            <span class="text-muted small">Cancelled</span>
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

    <!-- Footer -->
    <div class="text-center text-muted small py-3">
        <i class="bi bi-shield-check me-1"></i> Secure order management system
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
                    <p>Are you sure you want to confirm payment for this order?</p>
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
                    <p>Are you sure you want to reject this payment?</p>
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
                        <strong>Warning:</strong> This action cannot be undone. The order will be permanently cancelled.
                    </div>
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
function confirmPayment(id) {
    document.getElementById('confirm_order_id').value = id;
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
function rejectPayment(id) {
    document.getElementById('reject_order_id').value = id;
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
function cancelOrder(id) {
    document.getElementById('cancel_order_id').value = id;
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
// AUTO-REFRESH FOR PENDING ORDERS
// ============================================
// Refresh page every 60 seconds to check for new orders
setTimeout(function() {
    location.reload();
}, 60000);
</script>

</body>
</html>
