<?php
require_once '../config/config.php';
require_once '../auth/auth_middleware.php';

require_trader();
require_mandate();

$db = getDBConnection();

// Get company details for display
$company_stmt = $db->query("SELECT * FROM companies WHERE status = 'active' ORDER BY id LIMIT 1");
$company = $company_stmt->fetch();
$company_name = $company ? $company['company_name'] : 'Neovam LTD';

// Get trader statistics - NOW SHOWING ALL TRADES, not just user's trades
$stats = [];

// Total trades (ALL trades in the system)
$stmt = $db->query("SELECT COUNT(*) as total FROM trades");
$stats['total_trades'] = $stmt->fetch()['total'];

// Active trades (ALL active trades)
$stmt = $db->query("SELECT COUNT(*) as total FROM trades WHERE status = 'active'");
$stats['active_trades'] = $stmt->fetch()['total'];

// Today's trade value (ALL trades from today)
$stmt = $db->query("SELECT COALESCE(SUM(consideration), 0) as total FROM trades WHERE DATE(trade_date) = CURDATE() AND status = 'active'");
$stats['today_value'] = $stmt->fetch()['total'];

// Cancelled trades (ALL cancelled trades)
$stmt = $db->query("SELECT COUNT(*) as total FROM trades WHERE status = 'cancelled'");
$stats['cancelled_trades'] = $stmt->fetch()['total'];

// Settled trades (ALL settled trades)
$stmt = $db->query("SELECT COUNT(*) as total FROM trades WHERE status = 'settled'");
$stats['settled_trades'] = $stmt->fetch()['total'];

// Get recent trades (ALL recent trades, not just user's)
$stmt = $db->prepare("
    SELECT t.*, 
           COALESCE(e.stock_name, b.security_id, etf.stock_name) AS instrument_name,
           e.share_type,
           et.isin as etf_isin,
           c_buyer.company_name as buyer_company_name,
           c_seller.company_name as seller_company_name,
           cl.id as client_id,
           cl.client_name as proper_client_name
    FROM trades t
    LEFT JOIN equities e ON t.security_id = e.security_id AND t.asset_class = 'equity'
    LEFT JOIN bonds b ON t.security_id = b.security_id AND t.asset_class = 'bond'
    LEFT JOIN equities etf ON t.security_id = etf.security_id AND t.asset_class = 'Exchange Traded Funds'
    LEFT JOIN etf_trades et ON t.trade_reference = et.trade_reference
    LEFT JOIN companies c_buyer ON t.client_name = c_buyer.company_name
    LEFT JOIN companies c_seller ON t.counterparty_name = c_seller.company_name
    LEFT JOIN clients cl ON t.client_cds_account = cl.cds_account AND cl.is_active = 1
    ORDER BY t.created_at DESC
    LIMIT 10
");
$stmt->execute();
$recent_trades = $stmt->fetchAll();

$page_title = 'Trader Dashboard';
include '../includes/header.php';
?>

<!-- Enhanced professional trader dashboard header -->
<div class="page-header">
    <div class="container-fluid">
        <div class="row align-items-center">
            <div class="col-md-8">
                <div class="d-flex align-items-center">
                    <div class="me-3">
                        <div class="d-inline-flex align-items-center justify-content-center rounded-circle shadow-sm" 
                             style="width: 60px; height: 60px; background: linear-gradient(135deg, var(--success-color) 0%, #10b981 100%);">
                            <i class="bi bi-graph-up" style="font-size: 1.5rem;"></i>
                        </div>
                    </div>
                    <div>
                        <h1 class="page-title mb-1">Trading Dashboard</h1>
                        <p class="page-subtitle">Professional trading operations and portfolio management - <?php echo htmlspecialchars($company_name); ?></p>
                    </div>
                </div>
            </div>
            <div class="col-md-4 text-end">
                <div class="d-flex flex-column align-items-end">
                    <span class="text-muted small">Welcome back</span>
                    <span class="fw-semibold"><?php echo htmlspecialchars($_SESSION['full_name']); ?></span>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Enhanced trading statistics with professional styling -->
<div class="container-fluid">
    <div class="row g-4 mb-5">
        <div class="col-xl-3 col-md-6">
            <div class="card dashboard-card border-0 h-100" style="background: linear-gradient(135deg, var(--primary-color) 0%, var(--primary-light) 100%); color: white;">
                <div class="card-body p-4">
                    <div class="d-flex justify-content-between align-items-start">
                        <div class="flex-grow-1">
                            <div class="stat-number mb-2"><?php echo number_format($stats['total_trades']); ?></div>
                            <div class="stat-label">Total Trades</div>
                            <div class="mt-2">
                                <small class="opacity-75">
                                    <i class="bi bi-arrow-up me-1"></i>
                                    System-wide portfolio
                                </small>
                            </div>
                        </div>
                        <div class="ms-3">
                            <div class="d-flex align-items-center justify-content-center rounded-circle" 
                                 style="width: 50px; height: 50px; background: rgba(255,255,255,0.2);">
                                <i class="bi bi-graph-up" style="font-size: 1.5rem;"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-xl-3 col-md-6">
            <div class="card dashboard-card border-0 h-100" style="background: linear-gradient(135deg, var(--success-color) 0%, #10b981 100%); color: white;">
                <div class="card-body p-4">
                    <div class="d-flex justify-content-between align-items-start">
                        <div class="flex-grow-1">
                            <div class="stat-number mb-2"><?php echo number_format($stats['active_trades']); ?></div>
                            <div class="stat-label">Active Trades</div>
                            <div class="mt-2">
                                <small class="opacity-75">
                                    <i class="bi bi-check-circle me-1"></i>
                                    Currently processing
                                </small>
                            </div>
                        </div>
                        <div class="ms-3">
                            <div class="d-flex align-items-center justify-content-center rounded-circle" 
                                 style="width: 50px; height: 50px; background: rgba(255,255,255,0.2);">
                                <i class="bi bi-check-circle" style="font-size: 1.5rem;"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-xl-3 col-md-6">
            <div class="card dashboard-card border-0 h-100" style="background: linear-gradient(135deg, var(--info-color) 0%, #0ea5e9 100%); color: white;">
                <div class="card-body p-4">
                    <div class="d-flex justify-content-between align-items-start">
                        <div class="flex-grow-1">
                            <div class="stat-number mb-2">TZS <?php echo number_format($stats['today_value'], 0); ?></div>
                            <div class="stat-label">Today's Volume</div>
                            <div class="mt-2">
                                <small class="opacity-75">
                                    <i class="bi bi-calendar-day me-1"></i>
                                    Daily trading value
                                </small>
                            </div>
                        </div>
                        <div class="ms-3">
                            <div class="d-flex align-items-center justify-content-center rounded-circle" 
                                 style="width: 50px; height: 50px; background: rgba(255,255,255,0.2);">
                                <i class="bi bi-currency-dollar" style="font-size: 1.5rem;"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-xl-3 col-md-6">
            <div class="card dashboard-card border-0 h-100" style="background: linear-gradient(135deg, var(--warning-color) 0%, #f59e0b 100%); color: white;">
                <div class="card-body p-4">
                    <div class="d-flex justify-content-between align-items-start">
                        <div class="flex-grow-1">
                            <div class="stat-number mb-2"><?php echo number_format($stats['cancelled_trades']); ?></div>
                            <div class="stat-label">Cancelled Trades</div>
                            <div class="mt-2">
                                <small class="opacity-75">
                                    <i class="bi bi-x-circle me-1"></i>
                                    Inactive positions
                                </small>
                            </div>
                        </div>
                        <div class="ms-3">
                            <div class="d-flex align-items-center justify-content-center rounded-circle" 
                                 style="width: 50px; height: 50px; background: rgba(255,255,255,0.2);">
                                <i class="bi bi-x-circle" style="font-size: 1.5rem;"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Second row of statistics -->
    <div class="row g-4 mb-5">
        <div class="col-xl-6 col-md-6">
            <div class="card dashboard-card border-0 h-100" style="background: linear-gradient(135deg, #6f42c1 0%, #8b5cf6 100%); color: white;">
                <div class="card-body p-4">
                    <div class="d-flex justify-content-between align-items-start">
                        <div class="flex-grow-1">
                            <div class="stat-number mb-2"><?php echo number_format($stats['settled_trades']); ?></div>
                            <div class="stat-label">Settled Trades</div>
                            <div class="mt-2">
                                <small class="opacity-75">
                                    <i class="bi bi-check-all me-1"></i>
                                    Completed transactions
                                </small>
                            </div>
                        </div>
                        <div class="ms-3">
                            <div class="d-flex align-items-center justify-content-center rounded-circle" 
                                 style="width: 50px; height: 50px; background: rgba(255,255,255,0.2);">
                                <i class="bi bi-check-all" style="font-size: 1.5rem;"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-xl-6 col-md-6">
            <div class="card dashboard-card border-0 h-100" style="background: linear-gradient(135deg, #059669 0%, #10b981 100%); color: white;">
                <div class="card-body p-4">
                    <div class="d-flex justify-content-between align-items-start">
                        <div class="flex-grow-1">
                            <?php 
                            // Calculate total consideration for all trades
                            $stmt = $db->query("SELECT COALESCE(SUM(consideration), 0) as total FROM trades WHERE status = 'active'");
                            $total_consideration = $stmt->fetch()['total'];
                            ?>
                            <div class="stat-number mb-2">TZS <?php echo number_format($total_consideration, 0); ?></div>
                            <div class="stat-label">Total Portfolio Value</div>
                            <div class="mt-2">
                                <small class="opacity-75">
                                    <i class="bi bi-pie-chart me-1"></i>
                                    Active positions value
                                </small>
                            </div>
                        </div>
                        <div class="ms-3">
                            <div class="d-flex align-items-center justify-content-center rounded-circle" 
                                 style="width: 50px; height: 50px; background: rgba(255,255,255,0.2);">
                                <i class="bi bi-pie-chart" style="font-size: 1.5rem;"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Enhanced quick actions with professional card design -->
    <div class="row g-4 mb-5">
        <div class="col-12">
            <div class="card dashboard-card">
                <div class="card-header bg-transparent border-0 pb-0">
                    <div class="d-flex align-items-center">
                        <div class="me-2">
                            <i class="bi bi-lightning text-primary"></i>
                        </div>
                        <h6 class="mb-0 fw-semibold">Trading Operations - <?php echo htmlspecialchars($company_name); ?></h6>
                    </div>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-lg-3 col-md-6">
                            <a href="order_sheet.php" class="btn btn-primary btn-lg w-100 h-100 d-flex flex-column align-items-center justify-content-center p-4 text-decoration-none">
                                <i class="bi bi-journal-check mb-2" style="font-size: 2rem;"></i>
                                <div class="fw-semibold">Dealing Sheets</div>
                                <small class="opacity-75">Capture and execute orders</small>
                            </a>
                        </div>
                        <div class="col-lg-3 col-md-6">
                            <a href="enter_bonds.php" class="btn btn-outline-warning btn-lg w-100 h-100 d-flex flex-column align-items-center justify-content-center p-4 text-decoration-none">
                                <i class="bi bi-bank mb-2" style="font-size: 2rem;"></i>
                                <div class="fw-semibold">Enter Bonds</div>
                                <small class="text-muted">Fixed income securities</small>
                            </a>
                        </div>
                        <div class="col-lg-3 col-md-6">
                            <a href="enter_shares.php" class="btn btn-outline-info btn-lg w-100 h-100 d-flex flex-column align-items-center justify-content-center p-4 text-decoration-none">
                                <i class="bi bi-graph-up-arrow mb-2" style="font-size: 2rem;"></i>
                                <div class="fw-semibold">Enter Shares</div>
                                <small class="text-muted">Equity securities</small>
                            </a>
                        </div>
                        <div class="col-lg-3 col-md-6">
                            <a href="trades.php" class="btn btn-outline-secondary btn-lg w-100 h-100 d-flex flex-column align-items-center justify-content-center p-4 text-decoration-none">
                                <i class="bi bi-list mb-2" style="font-size: 2rem;"></i>
                                <div class="fw-semibold">View All Trades</div>
                                <small class="text-muted">Portfolio overview</small>
                            </a>
                        </div>
                    </div>
                    
                    <!-- Additional actions row -->
                    <div class="row g-3 mt-3">
                        <div class="col-lg-3 col-md-6">
                            <a href="upload_shares.php" class="btn btn-outline-success btn-lg w-100 h-100 d-flex flex-column align-items-center justify-content-center p-4 text-decoration-none">
                                <i class="bi bi-upload mb-2" style="font-size: 2rem;"></i>
                                <div class="fw-semibold">Upload Shares</div>
                                <small class="text-muted">Equity/ETF upload</small>
                            </a>
                        </div>
                        <div class="col-lg-3 col-md-6">
                            <a href="settlement.php" class="btn btn-outline-danger btn-lg w-100 h-100 d-flex flex-column align-items-center justify-content-center p-4 text-decoration-none">
                                <i class="bi bi-check-circle mb-2" style="font-size: 2rem;"></i>
                                <div class="fw-semibold">Settle Trades</div>
                                <small class="text-muted">Process settlements</small>
                            </a>
                        </div>
                        <div class="col-lg-3 col-md-6">
                            <a href="../reports/advanced_reports.php" class="btn btn-outline-purple btn-lg w-100 h-100 d-flex flex-column align-items-center justify-content-center p-4 text-decoration-none">
                                <i class="bi bi-file-earmark-text mb-2" style="font-size: 2rem;"></i>
                                <div class="fw-semibold">Reports</div>
                                <small class="text-muted">Generate reports</small>
                            </a>
                        </div>
                        <div class="col-lg-3 col-md-6">
                            <a href="../admin/client_management.php" class="btn btn-outline-dark btn-lg w-100 h-100 d-flex flex-column align-items-center justify-content-center p-4 text-decoration-none">
                                <i class="bi bi-people mb-2" style="font-size: 2rem;"></i>
                                <div class="fw-semibold">Clients</div>
                                <small class="text-muted">Client management</small>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Enhanced recent trades table with modern styling -->
    <div class="row">
        <div class="col-12">
            <div class="card dashboard-card">
                <div class="card-header bg-transparent border-0 pb-0">
                    <div class="d-flex justify-content-between align-items-center">
                        <div class="d-flex align-items-center">
                            <div class="me-2">
                                <i class="bi bi-clock-history text-primary"></i>
                            </div>
                            <h6 class="mb-0 fw-semibold">Recent Trading Activity - All Traders</h6>
                        </div>
                        <a href="trades.php" class="btn btn-outline-primary btn-sm">View All Trades</a>
                    </div>
                </div>
                <div class="card-body">
                    <?php if (empty($recent_trades)): ?>
                        <div class="text-center py-5">
                            <i class="bi bi-graph-up text-muted" style="font-size: 3rem;"></i>
                            <p class="text-muted mt-3 mb-3">No trades in the system yet</p>
                            <a href="trades.php" class="btn btn-primary">Upload First Trade</a>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th class="border-0">Reference</th>
                                        <th class="border-0">Instrument</th>
                                        <th class="border-0">Type</th>
                                        <th class="border-0">Side</th>
                                        <th class="border-0">Quantity</th>
                                        <th class="border-0">Price</th>
                                        <th class="border-0">Total Value</th>
                                        <th class="border-0">Client</th>
                                        <th class="border-0">Status</th>
                                        <th class="border-0">Date</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recent_trades as $trade): 
                                        $asset_class_icon = '';
                                        $asset_class_badge = '';
                                        
                                        if ($trade['asset_class'] === 'bond') {
                                            $asset_class_icon = 'bi-bank';
                                            $asset_class_badge = 'bg-warning';
                                        } elseif ($trade['asset_class'] === 'Exchange Traded Funds') {
                                            $asset_class_icon = 'bi-pie-chart';
                                            $asset_class_badge = 'bg-purple';
                                        } else {
                                            $asset_class_icon = 'bi-graph-up';
                                            $asset_class_badge = 'bg-info';
                                        }
                                    ?>
                                        <tr>
                                            <td class="border-0 fw-medium">
                                                <code><?php echo htmlspecialchars($trade['trade_reference']); ?></code>
                                            </td>
                                            <td class="border-0">
                                                <div>
                                                    <div class="fw-medium"><?php echo htmlspecialchars($trade['security_id']); ?></div>
                                                    <small class="text-muted">
                                                        <i class="bi <?php echo $asset_class_icon; ?> me-1"></i>
                                                        <?php 
                                                        // Display ETF instead of "Exchange Traded Funds"
                                                        if ($trade['asset_class'] === 'Exchange Traded Funds') {
                                                            echo 'ETF';
                                                        } else {
                                                            echo ucfirst($trade['asset_class']);
                                                        }
                                                        ?>
                                                    </small>
                                                </div>
                                            </td>
                                            <td class="border-0">
                                                <span class="badge <?php echo $asset_class_badge; ?> px-3 py-2">
                                                    <i class="bi <?php echo $asset_class_icon; ?> me-1"></i>
                                                    <?php 
                                                    // Display ETF instead of "Exchange Traded Funds"
                                                    if ($trade['asset_class'] === 'Exchange Traded Funds') {
                                                        echo 'ETF';
                                                    } else {
                                                        echo ucfirst($trade['asset_class']);
                                                    }
                                                    ?>
                                                </span>
                                            </td>
                                            <td class="border-0">
                                                <span class="badge bg-<?php echo strtolower($trade['trade_side']) == 'buy' ? 'success' : 'danger'; ?> px-3 py-2">
                                                    <i class="bi bi-arrow-<?php echo strtolower($trade['trade_side']) == 'buy' ? 'down' : 'up'; ?> me-1"></i>
                                                    <?php echo ucfirst($trade['trade_side']); ?>
                                                </span>
                                            </td>
                                            <td class="border-0"><?php echo number_format($trade['quantity']); ?></td>
                                            <td class="border-0">TZS <?php echo number_format($trade['price'], 2); ?></td>
                                            <td class="border-0 fw-semibold text-success">TZS <?php echo number_format($trade['consideration'], 2); ?></td>
                                            <td class="border-0">
                                                <?php if ($trade['client_id']): ?>
                                                    <a href="client_profile?id=<?php echo $trade['client_id']; ?>" 
                                                       class="text-decoration-none text-primary hover-underline" 
                                                       title="View Client Profile">
                                                        <?php echo htmlspecialchars($trade['proper_client_name'] ?? $trade['client_name']); ?>
                                                        <i class="bi bi-box-arrow-up-right ms-1 small"></i>
                                                    </a>
                                                <?php else: ?>
                                                    <span><?php echo htmlspecialchars($trade['client_name']); ?></span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="border-0">
                                                <span class="badge bg-<?php 
                                                    echo $trade['status'] == 'active' ? 'success' : 
                                                        ($trade['status'] == 'cancelled' ? 'danger' : 
                                                        ($trade['status'] == 'settled' ? 'info' : 'warning')); 
                                                ?> px-3 py-2">
                                                    <i class="bi bi-<?php 
                                                        echo $trade['status'] == 'active' ? 'check-circle' : 
                                                            ($trade['status'] == 'cancelled' ? 'x-circle' : 
                                                            ($trade['status'] == 'settled' ? 'check-all' : 'clock')); 
                                                    ?> me-1"></i>
                                                    <?php echo ucfirst($trade['status']); ?>
                                                </span>
                                            </td>
                                            <td class="border-0 text-muted"><?php echo format_date($trade['trade_date']); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
.badge.bg-purple {
    background-color: #6f42c1 !important;
    color: white;
}

.btn-outline-purple {
    color: #6f42c1;
    border-color: #6f42c1;
}

.btn-outline-purple:hover {
    background-color: #6f42c1;
    color: white;
    border-color: #6f42c1;
}

.stat-number {
    font-size: 2.25rem;
    font-weight: 700;
    line-height: 1;
}

.stat-label {
    font-size: 0.875rem;
    font-weight: 600;
    opacity: 0.9;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.hover-underline:hover {
    text-decoration: underline !important;
}

.card.dashboard-card {
    border-radius: var(--radius-lg);
    box-shadow: var(--shadow-sm);
    transition: transform 0.2s ease, box-shadow 0.2s ease;
}

.card.dashboard-card:hover {
    transform: translateY(-2px);
    box-shadow: var(--shadow-md);
}

</style>

<?php include '../includes/footer.php'; ?>
