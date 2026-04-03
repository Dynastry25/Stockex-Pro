<?php
/**
 * Order Sheet - Full Page View
 * Placeholder for order book integration with future API
 * Currently displays dummy data with message about pending API integration
 */

require_once '../config/config.php';
require_once '../includes/financial_helpers.php';

// Check authentication
check_permission('trader');

$db = getDBConnection();
$filter_status = $_GET['status'] ?? 'all'; // all, matched, unmatched

// Get company info
$company = getCompanyDetails($db);
$company_name = $company['company_name'] ?? 'Trading Exchange';
$company_code = $company['company_code'] ?? 'TRX';

// Get placeholder orders
$orders = getOrdersPlaceholder($db);

// Filter by status if needed
if ($filter_status !== 'all') {
    $orders = array_filter($orders, function($order) use ($filter_status) {
        return $order['status'] === $filter_status;
    });
}

// Count by status
$matched_count = count(array_filter($orders, function($o) { return $o['status'] === 'matched'; }));
$unmatched_count = count(array_filter($orders, function($o) { return $o['status'] === 'unmatched'; }));

// Include header
require_once '../includes/header.php';
?>

<div class="container-fluid pt-4 pb-4">
    <!-- Page Header -->
    <div class="row mb-4">
        <div class="col-12">
            <div style="background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%); padding: 2rem; border-radius: var(--radius-lg); color: white;">
                <h1 class="mb-2" style="font-size: 2rem; font-weight: 700;">
                    <i class="bi bi-inbox me-3"></i>Order Sheet (Order Book)
                </h1>
                <p class="mb-0 small">
                    <?php echo htmlspecialchars($company_name); ?> | <?php echo htmlspecialchars($company_code); ?>
                </p>
            </div>
        </div>
    </div>

    <!-- API Status Alert -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="alert alert-warning alert-dismissible fade show border-0" role="alert" style="background: linear-gradient(135deg, #fbbf24 0%, #f59e0b 100%); color: white; border-radius: var(--radius-lg);">
                <div class="d-flex align-items-center">
                    <i class="bi bi-exclamation-triangle me-3" style="font-size: 1.5rem;"></i>
                    <div>
                        <h5 class="mb-1 fw-bold">API Integration Pending</h5>
                        <p class="mb-0 small">This page displays sample order data. Real order information will be loaded once the order API is connected and configured.</p>
                    </div>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        </div>
    </div>

    <!-- Filter Section -->
    <div class="row mb-4">
        <div class="col-md-6">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <form method="GET" class="row g-3 align-items-end">
                        <div class="col-md-8">
                            <label for="filterStatus" class="form-label fw-semibold small">Order Status</label>
                            <select class="form-select" id="filterStatus" name="status">
                                <option value="all" <?php echo $filter_status === 'all' ? 'selected' : ''; ?>>All Orders</option>
                                <option value="matched" <?php echo $filter_status === 'matched' ? 'selected' : ''; ?>>Matched Only</option>
                                <option value="unmatched" <?php echo $filter_status === 'unmatched' ? 'selected' : ''; ?>>Unmatched Only</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <button type="submit" class="btn btn-primary w-100">
                                <i class="bi bi-search me-2"></i>Filter
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="d-grid gap-2">
                <button class="btn btn-outline-secondary" onclick="window.print()">
                    <i class="bi bi-printer me-2"></i>Print Order Book
                </button>
            </div>
        </div>
    </div>

    <!-- Summary Stats -->
    <div class="row mb-4">
        <div class="col-md-4">
            <div class="card border-0 shadow-sm" style="border-left: 4px solid var(--primary);">
                <div class="card-body">
                    <small class="text-muted d-block mb-1">Total Orders</small>
                    <h4 class="mb-0 fw-bold" style="color: var(--primary);">
                        <?php echo count($orders); ?>
                    </h4>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card border-0 shadow-sm" style="border-left: 4px solid var(--success);">
                <div class="card-body">
                    <small class="text-muted d-block mb-1">Matched Orders</small>
                    <h4 class="mb-0 fw-bold" style="color: var(--success);">
                        <?php echo $matched_count; ?>
                    </h4>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card border-0 shadow-sm" style="border-left: 4px solid var(--danger);">
                <div class="card-body">
                    <small class="text-muted d-block mb-1">Unmatched Orders</small>
                    <h4 class="mb-0 fw-bold" style="color: var(--danger);">
                        <?php echo $unmatched_count; ?>
                    </h4>
                </div>
            </div>
        </div>
    </div>

    <!-- Orders Table -->
    <div class="row">
        <div class="col-12">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white px-4 py-3 border-bottom">
                    <h6 class="mb-0 fw-bold">
                        <i class="bi bi-inbox me-2" style="color: var(--primary);"></i>Matched & Unmatched Orders
                    </h6>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover table-sm mb-0 align-middle">
                            <thead style="background-color: var(--light-bg); border-top: 2px solid var(--border-color);">
                                <tr>
                                    <th class="ps-4 fw-semibold text-nowrap">Order ID</th>
                                    <th class="fw-semibold text-nowrap">Client Name</th>
                                    <th class="fw-semibold text-nowrap">Security</th>
                                    <th class="text-end fw-semibold text-nowrap">Quantity</th>
                                    <th class="text-end fw-semibold text-nowrap">Price</th>
                                    <th class="text-center fw-semibold text-nowrap">Status</th>
                                    <th class="fw-semibold text-nowrap">Order Date/Time</th>
                                    <th class="text-center text-nowrap">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($orders as $order): ?>
                                    <tr class="border-bottom" style="opacity: 0.85; transition: background 0.2s;">
                                        <td class="ps-4 text-nowrap">
                                            <code style="font-size: 0.85rem; background: var(--light-bg); padding: 0.25rem 0.5rem; border-radius: 3px;">
                                                <?php echo htmlspecialchars($order['order_id']); ?>
                                            </code>
                                        </td>
                                        <td class="fw-semibold">
                                            <?php echo htmlspecialchars($order['client_name']); ?>
                                        </td>
                                        <td class="text-nowrap">
                                            <strong><?php echo htmlspecialchars($order['security']); ?></strong>
                                        </td>
                                        <td class="text-end text-nowrap">
                                            <?php echo number_format($order['quantity'], 0); ?>
                                        </td>
                                        <td class="text-end text-nowrap fw-semibold">
                                            <?php echo number_format($order['price'], 2); ?>
                                        </td>
                                        <td class="text-center text-nowrap">
                                            <span class="badge <?php echo ($order['status'] === 'matched' ? 'bg-success' : 'bg-warning'); ?>">
                                                <?php echo ucfirst($order['status']); ?>
                                            </span>
                                        </td>
                                        <td class="text-nowrap small">
                                            <?php echo $order['order_date']; ?>
                                        </td>
                                        <td class="text-center text-nowrap">
                                            <button class="btn btn-sm btn-outline-primary" disabled title="API integration required">
                                                <i class="bi bi-eye"></i>
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Integration Info Card -->
    <div class="row mt-4">
        <div class="col-12">
            <div class="card border-0 shadow-sm" style="background: linear-gradient(135deg, #f3f4f6 0%, #e5e7eb 100%);">
                <div class="card-body">
                    <h6 class="fw-bold mb-3">
                        <i class="bi bi-info-circle me-2" style="color: var(--primary);"></i>API Integration Instructions
                    </h6>
                    <div class="row">
                        <div class="col-md-6">
                            <h6 class="small fw-semibold text-muted mb-2">Phase 1: Current Status</h6>
                            <ul class="small mb-0">
                                <li>✓ Order sheet UI framework ready</li>
                                <li>✓ Filter and search capabilities built-in</li>
                                <li>✓ Display table structure established</li>
                                <li>⏳ Real order data - <strong>Pending API</strong></li>
                            </ul>
                        </div>
                        <div class="col-md-6">
                            <h6 class="small fw-semibold text-muted mb-2">Phase 2: Next Steps</h6>
                            <ul class="small mb-0">
                                <li>1. Connect external order API endpoint</li>
                                <li>2. Map API response to order structure</li>
                                <li>3. Implement real-time order updates (WebSocket)</li>
                                <li>4. Add order action handlers (cancel, modify, etc.)</li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Footer Info -->
    <div class="row mt-4">
        <div class="col-12">
            <p class="text-muted small text-center">
                <i class="bi bi-info-circle me-1"></i>
                Generated on <?php echo date('l, F j, Y \a\t h:i A'); ?> | Sample data displayed for UI demonstration
            </p>
        </div>
    </div>
</div>

<style media="print">
    @media print {
        /* Hide navigation and controls */
        body > nav,
        body > aside,
        .navbar,
        .sidebar,
        .container-fluid > .row:first-child,
        .row:has(> .col-md-8:has(form)),
        .row:has(> .col-md-4:has(.btn)),
        form,
        .btn,
        footer,
        .page-footer,
        .alert {
            display: none !important;
        }
        
        /* Optimize for print */
        body {
            background: white !important;
            margin: 0;
            padding: 1cm;
        }
        
        .container-fluid {
            width: 100%;
            max-width: 100%;
            margin: 0;
            padding: 0;
        }
        
        .card {
            border: 1px solid #ddd !important;
            page-break-inside: avoid;
            box-shadow: none !important;
        }
        
        .card-body {
            padding: 0.5rem;
        }
        
        .card-header {
            display: none !important;
        }
        
        table {
            page-break-inside: avoid;
            width: 100%;
        }
        
        tr {
            page-break-inside: avoid;
        }
        
        thead {
            display: table-header-group;
            background: #f5f5f5 !important;
            border-top: 2px solid #000;
        }
        
        th {
            font-weight: bold;
            border: 1px solid #ccc;
            padding: 0.5rem;
            background: #f0f0f0 !important;
        }
        
        td {
            border: 1px solid #e0e0e0;
            padding: 0.5rem;
        }
        
        .opacity-85 {
            opacity: 1 !important;
        }
        
        h1, h4, h6 {
            page-break-after: avoid;
        }
        
        .fw-bold {
            font-weight: bold;
        }
    }
</style>

<?php require_once '../includes/footer.php'; ?>
