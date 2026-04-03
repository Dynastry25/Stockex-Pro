<?php
/**
 * Dealing Sheet - Full Page View
 * Displays all matched trades with filtering and export options
 */

require_once '../config/config.php';
require_once '../includes/financial_helpers.php';

// Check authentication
check_permission('trader');

$db = getDBConnection();
$filter_date = $_GET['date'] ?? date('Y-m-d');
$filter_type = $_GET['type'] ?? 'all'; // all, bond, equity

// Validate date format
if ($filter_date && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $filter_date)) {
    $filter_date = date('Y-m-d');
}

// Get company info
$company = getCompanyDetails($db);
$company_name = $company['company_name'] ?? 'Trading Exchange';
$company_code = $company['company_code'] ?? 'TRX';

// Get all trades (no date filter by default - show all active trades)
$trades = getDealingSheetTrades($db, null);

// Filter by date if specified
if ($filter_date) {
    $trades = array_filter($trades, function($trade) use ($filter_date) {
        return date('Y-m-d', strtotime($trade['trade_date'])) === $filter_date;
    });
}

// Filter by type if needed - use 'bond', 'equity', or 'shares'
if ($filter_type !== 'all') {
    if ($filter_type === 'equity') {
        // For equity filter, include both 'equity' and 'shares'
        $trades = array_filter($trades, function($trade) {
            return in_array($trade['trade_type'], ['equity', 'shares']);
        });
    } else {
        // For bond or other types, match exactly
        $trades = array_filter($trades, function($trade) use ($filter_type) {
            return $trade['trade_type'] === $filter_type;
        });
    }
}

// Re-index array after filtering
$trades = array_values($trades);

// Calculate totals
$total_quantity = 0;
$total_value = 0;
foreach ($trades as $trade) {
    $total_quantity += $trade['quantity'];
    $total_value += $trade['total_value'];
}

// Include header
require_once '../includes/header.php';
?>

<div class="container-fluid pt-4 pb-4">
    <!-- Page Header -->
    <div class="row mb-4">
        <div class="col-12">
            <div style="background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%); padding: 2rem; border-radius: var(--radius-lg); color: white;">
                <h1 class="mb-2" style="font-size: 2rem; font-weight: 700;">
                    <i class="bi bi-file-earmark-spreadsheet me-3"></i>Dealing Sheet
                </h1>
                <p class="mb-0 small">
                    <?php echo htmlspecialchars($company_name); ?> | <?php echo htmlspecialchars($company_code); ?>
                </p>
            </div>
        </div>
    </div>

    <!-- Filter Section -->
    <div class="row mb-4">
        <div class="col-md-8">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <form method="GET" class="row g-3 align-items-end">
                        <div class="col-md-4">
                            <label for="filterDate" class="form-label fw-semibold small">Trade Date</label>
                            <input type="date" class="form-control" id="filterDate" name="date" 
                                   value="<?php echo htmlspecialchars($filter_date); ?>">
                        </div>
                        <div class="col-md-4">
                            <label for="filterType" class="form-label fw-semibold small">Asset Type</label>
                            <select class="form-select" id="filterType" name="type">
                                <option value="all" <?php echo $filter_type === 'all' ? 'selected' : ''; ?>>All Types</option>
                                <option value="bond" <?php echo $filter_type === 'bond' ? 'selected' : ''; ?>>Bonds Only</option>
                                <option value="equity" <?php echo $filter_type === 'equity' ? 'selected' : ''; ?>>Equities Only</option>
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
        <div class="col-md-4">
            <div class="d-grid gap-2">
                <button class="btn btn-outline-secondary" onclick="window.print()">
                    <i class="bi bi-printer me-2"></i>Print Sheet
                </button>
            </div>
        </div>
    </div>

    <!-- Summary Stats -->
    <div class="row mb-4">
        <div class="col-md-3">
            <div class="card border-0 shadow-sm" style="border-left: 4px solid var(--primary);">
                <div class="card-body">
                    <small class="text-muted d-block mb-1">Total Trades</small>
                    <h4 class="mb-0 fw-bold" style="color: var(--primary);">
                        <?php echo count($trades); ?>
                    </h4>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm" style="border-left: 4px solid var(--success);">
                <div class="card-body">
                    <small class="text-muted d-block mb-1">Total Quantity</small>
                    <h4 class="mb-0 fw-bold" style="color: var(--success);">
                        <?php echo number_format($total_quantity, 0); ?>
                    </h4>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm" style="border-left: 4px solid var(--info);">
                <div class="card-body">
                    <small class="text-muted d-block mb-1">Total Value</small>
                    <h4 class="mb-0 fw-bold" style="color: var(--info);">
                        <?php echo number_format($total_value, 2); ?>
                    </h4>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm" style="border-left: 4px solid var(--warning);">
                <div class="card-body">
                    <small class="text-muted d-block mb-1">Report Date</small>
                    <h4 class="mb-0 fw-bold" style="color: var(--warning);">
                        <?php echo date('M d, Y', strtotime($filter_date)); ?>
                    </h4>
                </div>
            </div>
        </div>
    </div>

    <!-- Trades Table -->
    <div class="row">
        <div class="col-12">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white px-4 py-3 border-bottom">
                    <h6 class="mb-0 fw-bold">
                        <i class="bi bi-list-check me-2" style="color: var(--primary);"></i>Matched Trades
                    </h6>
                </div>
                <div class="card-body p-0">
                    <?php if (count($trades) > 0): ?>
                        <div class="table-responsive">
                            <table class="table table-hover table-sm mb-0 align-middle">
                                <thead style="background-color: var(--light-bg); border-top: 2px solid var(--border-color);">
                                    <tr>
                                        <th class="ps-4 fw-semibold text-nowrap">Trade Ref</th>
                                        <th class="fw-semibold text-nowrap">Type</th>
                                        <th class="fw-semibold text-nowrap">Security ID</th>
                                        <th class="fw-semibold text-nowrap">Security Name</th>
                                        <th class="text-end fw-semibold text-nowrap">Qty</th>
                                        <th class="text-end fw-semibold text-nowrap">Price</th>
                                        <th class="text-end fw-semibold text-nowrap">Value</th>
                                        <th class="text-center fw-semibold text-nowrap">Side</th>
                                        <th class="fw-semibold text-nowrap">Trade Date</th>
                                        <th class="fw-semibold text-nowrap">Buyer</th>
                                        <th class="fw-semibold text-nowrap">Seller</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($trades as $trade): ?>
                                        <tr class="border-bottom" style="transition: background 0.2s;">
                                            <td class="ps-4 text-nowrap">
                                                <code style="font-size: 0.85rem; background: var(--light-bg); padding: 0.25rem 0.5rem; border-radius: 3px;">
                                                    <?php echo htmlspecialchars($trade['trade_reference']); ?>
                                                </code>
                                            </td>
                                            <td class="text-nowrap">
                                                <span class="badge bg-secondary">
                                                    <?php echo ucfirst($trade['trade_type']); ?>
                                                </span>
                                            </td>
                                            <td class="text-nowrap fw-semibold">
                                                <?php echo htmlspecialchars($trade['security_id']); ?>
                                            </td>
                                            <td class="text-nowrap">
                                                <small>
                                                    <?php echo htmlspecialchars(substr($trade['security_name'], 0, 35)); ?>
                                                </small>
                                            </td>
                                            <td class="text-end text-nowrap">
                                                <?php echo number_format($trade['quantity'], 0); ?>
                                            </td>
                                            <td class="text-end text-nowrap">
                                                <?php echo number_format($trade['price'], 4); ?>
                                            </td>
                                            <td class="text-end text-nowrap fw-semibold" style="color: var(--success);">
                                                <?php echo number_format($trade['total_value'], 2); ?>
                                            </td>
                                            <td class="text-center text-nowrap">
                                                <span class="badge <?php echo ($trade['trade_side'] === 'buy' ? 'bg-success' : 'bg-danger'); ?>">
                                                    <?php echo strtoupper($trade['trade_side']); ?>
                                                </span>
                                            </td>
                                            <td class="text-nowrap small">
                                                <?php echo date('M d, Y', strtotime($trade['trade_date'])); ?>
                                            </td>
                                            <td class="text-nowrap small">
                                                <em class="text-muted">
                                                    <?php echo htmlspecialchars(substr($trade['buyer_name'], 0, 30)); ?>
                                                </em>
                                            </td>
                                            <td class="text-nowrap small">
                                                <em class="text-muted">
                                                    <?php echo htmlspecialchars(substr($trade['seller_name'], 0, 30)); ?>
                                                </em>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <!-- Totals Row -->
                        <div class="p-4" style="background-color: var(--light-bg); border-top: 2px solid var(--border-color);">
                            <div class="row">
                                <div class="col-md-6"></div>
                                <div class="col-md-2 text-end">
                                    <small class="fw-semibold">Total Qty:</small><br>
                                    <span class="fw-bold" style="font-size: 1.1rem; color: var(--primary);">
                                        <?php echo number_format($total_quantity, 0); ?>
                                    </span>
                                </div>
                                <div class="col-md-2 text-end">
                                    <small class="fw-semibold">Total Value:</small><br>
                                    <span class="fw-bold" style="font-size: 1.1rem; color: var(--success);">
                                        <?php echo number_format($total_value, 2); ?>
                                    </span>
                                </div>
                                <div class="col-md-2 text-end">
                                    <small class="fw-semibold">Avg Price:</small><br>
                                    <span class="fw-bold" style="font-size: 1.1rem; color: var(--info);">
                                        <?php echo $total_quantity > 0 ? number_format($total_value / $total_quantity, 4) : '0.0000'; ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="alert alert-info m-4" role="alert">
                            <i class="bi bi-info-circle me-2"></i>
                            <strong>No trades found</strong> for the selected date and filters.
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Footer Info -->
    <div class="row mt-4">
        <div class="col-12">
            <p class="text-muted small text-center">
                <i class="bi bi-info-circle me-1"></i>
                Generated on <?php echo date('l, F j, Y \a\t h:i A'); ?> | Dealing Sheet for <?php echo date('F j, Y', strtotime($filter_date)); ?>
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
        .page-footer {
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
        
        /* Show header for printing */
        .gradient-header {
            page-break-inside: avoid;
            border: 1px solid #000;
        }
        
        /* Summary stats visible in print */
        .col-md-3 {
            page-break-inside: avoid;
        }
        
        h1, h4, h6 {
            page-break-after: avoid;
        }
        
        /* Ensure footer info is visible */
        .text-center {
            page-break-before: avoid;
        }
        
        /* Color preservation for print */
        .text-success {
            color: #000 !important;
        }
        
        .fw-bold {
            font-weight: bold;
        }
    }
</style>

<?php require_once '../includes/footer.php'; ?>
