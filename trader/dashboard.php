<?php
require_once '../config/config.php';
require_once '../auth/auth_middleware.php';

require_trader();
require_mandate();

$db = getDBConnection();

// Get trader statistics
$stats = [];

// Total trades uploaded by this trader
$stmt = $db->prepare("SELECT COUNT(*) as total FROM trades WHERE uploaded_by = ?");
$stmt->execute([$_SESSION['user_id']]);
$stats['my_trades'] = $stmt->fetch()['total'];

// Active trades
$stmt = $db->prepare("SELECT COUNT(*) as total FROM trades WHERE uploaded_by = ? AND status = 'active'");
$stmt->execute([$_SESSION['user_id']]);
$stats['active_trades'] = $stmt->fetch()['total'];

// Today's trade value
$stmt = $db->prepare("SELECT COALESCE(SUM(consideration), 0) as total FROM trades WHERE uploaded_by = ? AND DATE(trade_date) = CURDATE() AND status = 'active'");
$stmt->execute([$_SESSION['user_id']]);
$stats['today_value'] = $stmt->fetch()['total'];

// Cancelled trades
$stmt = $db->prepare("SELECT COUNT(*) as total FROM trades WHERE uploaded_by = ? AND status = 'cancelled'");
$stmt->execute([$_SESSION['user_id']]);
$stats['cancelled_trades'] = $stmt->fetch()['total'];

// Recent trades
$stmt = $db->prepare("
    SELECT t.*, 
           t.security_id as instrument_code,
           t.security_name as instrument_name
    FROM trades t
    WHERE t.uploaded_by = ?
    ORDER BY t.created_at DESC
    LIMIT 10
");
$stmt->execute([$_SESSION['user_id']]);
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
                            <i class="bi bi-graph-up text-white" style="font-size: 1.5rem;"></i>
                        </div>
                    </div>
                    <div>
                        <h1 class="page-title mb-1">Trading Dashboard</h1>
                        <p class="page-subtitle">Professional trading operations and portfolio management</p>
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
                            <div class="stat-number mb-2"><?php echo number_format($stats['my_trades']); ?></div>
                            <div class="stat-label">Total Trades</div>
                            <div class="mt-2">
                                <small class="opacity-75">
                                    <i class="bi bi-arrow-up me-1"></i>
                                    Lifetime portfolio
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
                            <div class="stat-number mb-2">$<?php echo number_format($stats['today_value'], 0); ?></div>
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

    <!-- Enhanced quick actions with professional card design -->
    <div class="row g-4 mb-5">
        <div class="col-12">
            <div class="card dashboard-card">
                <div class="card-header bg-transparent border-0 pb-0">
                    <div class="d-flex align-items-center">
                        <div class="me-2">
                            <i class="bi bi-lightning text-primary"></i>
                        </div>
                        <h6 class="mb-0 fw-semibold">Trading Operations</h6>
                    </div>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-lg-3 col-md-6">
                            <a href="trades.php" class="btn btn-primary btn-lg w-100 h-100 d-flex flex-column align-items-center justify-content-center p-4 text-decoration-none">
                                <i class="bi bi-upload mb-2" style="font-size: 2rem;"></i>
                                <div class="fw-semibold">Upload Trade</div>
                                <small class="opacity-75">Add new positions</small>
                            </a>
                        </div>
                        <div class="col-lg-3 col-md-6">
                            <a href="upload_bonds.php" class="btn btn-outline-warning btn-lg w-100 h-100 d-flex flex-column align-items-center justify-content-center p-4 text-decoration-none">
                                <i class="bi bi-file-earmark-arrow-up mb-2" style="font-size: 2rem;"></i>
                                <div class="fw-semibold">Upload Bonds</div>
                                <small class="text-muted">Fixed income securities</small>
                            </a>
                        </div>
                        <div class="col-lg-3 col-md-6">
                            <a href="upload_shares.php" class="btn btn-outline-info btn-lg w-100 h-100 d-flex flex-column align-items-center justify-content-center p-4 text-decoration-none">
                                <i class="bi bi-file-earmark-arrow-up mb-2" style="font-size: 2rem;"></i>
                                <div class="fw-semibold">Upload Shares</div>
                                <small class="text-muted">Equity securities</small>
                            </a>
                        </div>
                        <div class="col-lg-3 col-md-6">
                            <a href="trades.php" class="btn btn-outline-secondary btn-lg w-100 h-100 d-flex flex-column align-items-center justify-content-center p-4 text-decoration-none">
                                <i class="bi bi-list mb-2" style="font-size: 2rem;"></i>
                                <div class="fw-semibold">View Trades</div>
                                <small class="text-muted">Portfolio overview</small>
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
                            <h6 class="mb-0 fw-semibold">Recent Trading Activity</h6>
                        </div>
                        <a href="trades.php" class="btn btn-outline-primary btn-sm">View All</a>
                    </div>
                </div>
                <div class="card-body">
                    <?php if (empty($recent_trades)): ?>
                        <div class="text-center py-5">
                            <i class="bi bi-graph-up text-muted" style="font-size: 3rem;"></i>
                            <p class="text-muted mt-3 mb-3">No trades uploaded yet</p>
                            <a href="trades.php" class="btn btn-primary">Upload Your First Trade</a>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th class="border-0">Reference</th>
                                        <th class="border-0">Instrument</th>
                                        <th class="border-0">Type</th>
                                        <th class="border-0">Quantity</th>
                                        <th class="border-0">Price</th>
                                        <th class="border-0">Total Value</th>
                                        <th class="border-0">Status</th>
                                        <th class="border-0">Date</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recent_trades as $trade): ?>
                                        <tr>
                                            <td class="border-0 fw-medium"><?php echo htmlspecialchars($trade['trade_reference']); ?></td>
                                            <td class="border-0">
                                                <div>
                                                    <div class="fw-medium"><?php echo htmlspecialchars($trade['instrument_code']); ?></div>
                                                    <small class="text-muted"><?php echo htmlspecialchars($trade['instrument_name']); ?></small>
                                                </div>
                                            </td>
                                            <td class="border-0">
                                                <span class="badge bg-<?php echo $trade['trade_side'] == 'buy' ? 'success' : 'danger'; ?> px-3 py-2">
                                                    <i class="bi bi-arrow-<?php echo $trade['trade_side'] == 'buy' ? 'down' : 'up'; ?> me-1"></i>
                                                    <?php echo ucfirst($trade['trade_side']); ?>
                                                </span>
                                            </td>
                                            <td class="border-0"><?php echo number_format($trade['quantity']); ?></td>
                                            <td class="border-0">$<?php echo format_currency($trade['price']); ?></td>
                                            <td class="border-0 fw-semibold">$<?php echo format_currency($trade['consideration']); ?></td>
                                            <td class="border-0">
                                                <span class="badge bg-<?php 
                                                    echo $trade['status'] == 'active' ? 'success' : 
                                                        ($trade['status'] == 'cancelled' ? 'danger' : 'warning'); 
                                                ?> px-3 py-2">
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

<?php include '../includes/footer.php'; ?>
