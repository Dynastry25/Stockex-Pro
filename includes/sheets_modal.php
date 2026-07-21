<?php
/**
 * Sheets Modal Template
 * Displays Dealing Sheet and Order Sheet in a tabbed Bootstrap modal
 * Called from footer.php
 * 
 * Required variables from parent:
 * - $trades (array): List of matched trades
 * - $company_name (string): Company name for header
 * - $trade_summary (array): Trade count and total value
 */

// If no variables provided, fetch them
if (!isset($trades)) {
    $trades = getDealingSheetTrades($db) ?? [];
}

if (!isset($company_name)) {
    $company = getCompanyDetails($db);
    $company_name = $company['company_name'] ?? 'Trading Exchange';
}

if (!isset($trade_summary)) {
    $trade_summary = getTradesSummary($db) ?? ['count' => 0, 'total_value' => 0];
}

$orders = getOrdersPlaceholder($db);
?>

<!-- Dealing Sheet & Order Sheet Modal -->
<div class="modal fade" id="sheetsModal" tabindex="-1" aria-labelledby="sheetsModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl">
        <div class="modal-content" style="border-radius: var(--radius-xl); border: none; box-shadow: 0 10px 40px rgba(0,0,0,0.2);">
            <!-- Modal Header with Gradient -->
            <div class="modal-header border-0" style="background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%); padding: 1.5rem;">
                <div class="w-100">
                    <h4 class="modal-title fw-bold text-white mb-2" id="sheetsModalLabel">
                        <i class="bi bi-file-earmark-spreadsheet me-2"></i>Trading Sheets
                    </h4>
                    <small class="text-white-50">
                        <?php echo htmlspecialchars($company_name); ?> | 
                        <?php echo date('l, F j, Y'); ?>
                    </small>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <!-- Modal Body with Tabs -->
            <div class="modal-body p-4">
                <!-- Tab Navigation -->
                <ul class="nav nav-tabs mb-4" id="sheetsTab" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active fw-semibold" id="dealing-sheet-tab" data-bs-toggle="tab" 
                                data-bs-target="#dealing-sheet-content" type="button" role="tab" 
                                aria-controls="dealing-sheet-content" aria-selected="true">
                            <i class="bi bi-list-check me-2"></i>Dealing Sheet
                            <span class="badge bg-success ms-2"><?php echo $trade_summary['count']; ?></span>
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link fw-semibold" id="order-sheet-tab" data-bs-toggle="tab" 
                                data-bs-target="#order-sheet-content" type="button" role="tab" 
                                aria-controls="order-sheet-content" aria-selected="false">
                            <i class="bi bi-inbox me-2"></i>Order Sheet
                            <span class="badge bg-warning ms-2">Demo</span>
                        </button>
                    </li>
                </ul>

                <!-- Tab Content -->
                <div class="tab-content" id="sheetsTabContent">
                    <!-- DEALING SHEET TAB -->
                    <div class="tab-pane fade show active" id="dealing-sheet-content" role="tabpanel" 
                         aria-labelledby="dealing-sheet-tab">
                        <div class="dealing-sheet-container">
                            <!-- Summary Stats -->
                            <div class="row mb-4">
                                <div class="col-md-4">
                                    <div class="p-3 rounded" style="background: var(--light-bg);">
                                        <small class="text-muted d-block mb-1">Total Trades</small>
                                        <h5 class="mb-0 fw-bold" style="color: var(--primary);">
                                            <?php echo $trade_summary['count']; ?>
                                        </h5>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="p-3 rounded" style="background: var(--light-bg);">
                                        <small class="text-muted d-block mb-1">Total Value</small>
                                        <h5 class="mb-0 fw-bold" style="color: var(--success);">
                                            <?php echo number_format($trade_summary['total_value'], 2); ?>
                                        </h5>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="p-3 rounded" style="background: var(--light-bg);">
                                        <small class="text-muted d-block mb-1">Date</small>
                                        <h5 class="mb-0 fw-bold" style="color: var(--info);">
                                            <?php echo date('M d, Y'); ?>
                                        </h5>
                                    </div>
                                </div>
                            </div>

                            <!-- Trades Table -->
                            <?php if (count($trades) > 0): ?>
                                <div class="table-responsive">
                                    <table class="table table-hover table-sm align-middle">
                                        <thead>
                                            <tr style="background-color: var(--light-bg); border-top: 2px solid var(--border-color);">
                                                <th class="text-nowrap fw-semibold">Reference</th>
                                                <th class="text-nowrap fw-semibold">Type</th>
                                                <th class="text-nowrap fw-semibold">Security</th>
                                                <th class="text-end fw-semibold text-nowrap">Quantity</th>
                                                <th class="text-end fw-semibold text-nowrap">Price</th>
                                                <th class="text-end fw-semibold text-nowrap">Value</th>
                                                <th class="text-center fw-semibold text-nowrap">Buy/Sell</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($trades as $trade): ?>
                                                <tr class="border-bottom" style="cursor: pointer; transition: background 0.2s;">
                                                    <td class="text-nowrap">
                                                        <code style="font-size: 0.85rem; background: var(--light-bg); padding: 0.25rem 0.5rem; border-radius: 3px;">
                                                            <?php echo htmlspecialchars($trade['trade_reference']); ?>
                                                        </code>
                                                    </td>
                                                    <td>
                                                        <span class="badge bg-secondary">
                                                            <?php echo ucfirst($trade['trade_type']); ?>
                                                        </span>
                                                    </td>
                                                    <td class="text-nowrap">
                                                        <strong><?php echo htmlspecialchars($trade['security_id']); ?></strong>
                                                        <br>
                                                        <small class="text-muted">
                                                            <?php echo htmlspecialchars(substr($trade['security_name'], 0, 40)); ?>
                                                        </small>
                                                    </td>
                                                    <td class="text-end text-nowrap">
                                                        <?php echo number_format($trade['quantity'], 0); ?>
                                                    </td>
                                                    <td class="text-end text-nowrap">
                                                        <?php echo number_format($trade['price'], 2); ?>
                                                    </td>
                                                    <td class="text-end text-nowrap fw-semibold" style="color: var(--success);">
                                                        <?php echo number_format($trade['total_value'], 2); ?>
                                                    </td>
                                                    <td class="text-center">
                                                        <span class="badge <?php echo ($trade['trade_side'] === 'buy' ? 'bg-success' : 'bg-danger'); ?>">
                                                            <?php echo strtoupper($trade['trade_side']); ?>
                                                        </span>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php else: ?>
                                <div class="alert alert-info text-center mb-0" role="alert">
                                    <i class="bi bi-info-circle me-2"></i>
                                    <strong>No trades today</strong>
                                    <p class="mb-0 small mt-2">There are no matched trades to display at this time.</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- ORDER SHEET TAB -->
                    <div class="tab-pane fade" id="order-sheet-content" role="tabpanel" 
                         aria-labelledby="order-sheet-tab">
                        <div class="order-sheet-container">
                            <!-- Placeholder Status -->
                            <div class="alert alert-warning mb-4" role="alert">
                                <i class="bi bi-exclamation-triangle me-2"></i>
                                <strong>API Integration Pending</strong> — Real order data will be loaded once the API is connected.
                            </div>

                            <!-- Dummy Orders Table -->
                            <div class="table-responsive">
                                <table class="table table-hover table-sm align-middle">
                                    <thead>
                                        <tr style="background-color: var(--light-bg); border-top: 2px solid var(--border-color);">
                                            <th class="text-nowrap fw-semibold">Order ID</th>
                                            <th class="text-nowrap fw-semibold">Client</th>
                                            <th class="text-nowrap fw-semibold">Security</th>
                                            <th class="text-end fw-semibold text-nowrap">Quantity</th>
                                            <th class="text-end fw-semibold text-nowrap">Price</th>
                                            <th class="text-center fw-semibold">Status</th>
                                            <th class="text-nowrap fw-semibold">Order Date</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($orders as $order): ?>
                                            <tr class="border-bottom" style="opacity: 0.7;">
                                                <td class="text-nowrap">
                                                    <code style="font-size: 0.85rem; background: var(--light-bg); padding: 0.25rem 0.5rem; border-radius: 3px;">
                                                        <?php echo htmlspecialchars($order['order_id']); ?>
                                                    </code>
                                                </td>
                                                <td>
                                                    <em class="text-muted"><?php echo htmlspecialchars($order['client_name']); ?></em>
                                                </td>
                                                <td class="fw-semibold"><?php echo htmlspecialchars($order['security']); ?></td>
                                                <td class="text-end text-nowrap"><?php echo number_format($order['quantity']); ?></td>
                                                <td class="text-end text-nowrap"><?php echo number_format($order['price'], 2); ?></td>
                                                <td class="text-center">
                                                    <span class="badge <?php echo ($order['status'] === 'matched' ? 'bg-success' : 'bg-warning'); ?>">
                                                        <?php echo ucfirst($order['status']); ?>
                                                    </span>
                                                </td>
                                                <td class="text-nowrap small text-muted"><?php echo $order['order_date']; ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <p class="text-muted small mt-4 mb-0" style="text-align: center;">
                                <em>Dummy data shown. Real order data will appear here once the order API is integrated.</em>
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Modal Footer -->
            <div class="modal-footer border-top p-3" style="background-color: var(--light-bg);">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="bi bi-x-lg me-2"></i>Close
                </button>
                <button type="button" class="btn btn-primary" onclick="printSheet()">
                    <i class="bi bi-printer me-2"></i>Print
                </button>
                <a href="<?php echo BASE_URL; ?>/trader/order_sheet.php" class="btn btn-info">
                    <i class="bi bi-fullscreen me-2"></i>Full View
                </a>
            </div>
        </div>
    </div>
</div>

<script>
/**
 * Print the dealing sheet
 */
function printSheet() {
    window.print();
}
</script>

<!-- Print Styles -->
<style media="print">
    .modal-header, .modal-footer, .nav-tabs, .alert {
        display: none !important;
    }
    
    .modal-body {
        padding: 0 !important;
    }
    
    .table {
        page-break-inside: avoid;
    }
    
    tr {
        page-break-inside: avoid;
    }
</style>
