<?php
require_once '../config/config.php';
require_once '../auth/auth_middleware.php';

require_admin();

$db = getDBConnection();

// Get system statistics - consolidated queries
$stats = [];

// Users stats: total + by role (single query)
$role_rows = $db->query("SELECT role, COUNT(*) as count FROM users WHERE is_active = 1 GROUP BY role")->fetchAll();
$stats['total_users'] = 0;
$role_stats = [];
foreach ($role_rows as $row) {
    $role_stats[$row['role']] = $row['count'];
    $stats['total_users'] += $row['count'];
}

// Trades stats: active count + today's value (single query)
$trades_row = $db->query("
    SELECT 
        COUNT(*) as active_trades,
        COALESCE(SUM(CASE WHEN DATE(trade_date) = CURDATE() THEN total_value ELSE 0 END), 0) as today_value
    FROM trades WHERE status = 'active'
")->fetch();
$stats['active_trades'] = $trades_row['active_trades'];
$stats['today_trade_value'] = $trades_row['today_value'];

// Assets stats: bonds + equities (single query)
$assets_row = $db->query("
    SELECT 
        (SELECT COUNT(*) FROM bonds WHERE status = 'active') as active_bonds,
        (SELECT COUNT(*) FROM equities WHERE status = 'active') as active_equities
")->fetch();
$stats['active_bonds'] = $assets_row['active_bonds'] ?? 0;
$stats['active_equities'] = $assets_row['active_equities'] ?? 0;

// Fee configurations
$fee_configs = $db->query("SELECT * FROM fee_configurations ORDER BY fee_type, applies_to")->fetchAll();

// Users needing mandate approval
$pending_mandates = $db->query("SELECT * FROM users WHERE mandate_enabled = 0 AND role != 'system_admin' AND is_active = 1 ORDER BY created_at DESC")->fetchAll();

$page_title = 'Admin Dashboard';
include '../includes/header.php';
?>

<div class="page-header">
    <div class="container-fluid">
        <div class="row align-items-center">
            <div class="col-md-8">
                <div class="d-flex align-items-center">
                    <div class="me-3">
                        <div class="d-inline-flex align-items-center justify-content-center rounded-circle shadow-sm"
                             style="width: 60px; height: 60px; background: linear-gradient(135deg, var(--primary-color) 0%, var(--primary-light) 100%);">
                            <i class="bi bi-speedometer2 text-white" style="font-size: 1.5rem;"></i>
                        </div>
                    </div>
                    <div>
                        <h1 class="page-title mb-1">System Administration</h1>
                        <p class="page-subtitle">Comprehensive platform oversight and management</p>
                    </div>
                </div>
            </div>
            <div class="col-md-4 text-end">
                <div class="d-flex flex-column align-items-end">
                    <span class="text-muted small">Last updated</span>
                    <span class="fw-semibold"><?php echo date('M d, Y H:i'); ?></span>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="container-fluid">
    <div class="row g-4 mb-5">
        <div class="col-xl-3 col-md-6">
            <div class="card dashboard-card border-0 h-100" style="background: linear-gradient(135deg, var(--primary-color) 0%, var(--primary-light) 100%); color: white;">
                <div class="card-body p-4">
                    <div class="d-flex justify-content-between align-items-start">
                        <div class="flex-grow-1">
                            <div class="stat-number mb-2"><?php echo number_format($stats['total_users']); ?></div>
                            <div class="stat-label">Total Active Users</div>
                            <div class="mt-2">
                                <small class="opacity-75">
                                    <i class="bi bi-arrow-up me-1"></i>
                                    System-wide user base
                                </small>
                            </div>
                        </div>
                        <div class="ms-3">
                            <div class="d-flex align-items-center justify-content-center rounded-circle"
                                 style="width: 50px; height: 50px; background: rgba(255,255,255,0.2);">
                                <i class="bi bi-people" style="font-size: 1.5rem;"></i>
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
                                    <i class="bi bi-graph-up me-1"></i>
                                    Currently processing
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
            <div class="card dashboard-card border-0 h-100" style="background: linear-gradient(135deg, var(--info-color) 0%, #0ea5e9 100%); color: white;">
                <div class="card-body p-4">
                    <div class="d-flex justify-content-between align-items-start">
                        <div class="flex-grow-1">
                            <div class="stat-number mb-2">Tsh <?php echo number_format($stats['today_trade_value'], 2); ?></div>
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
            <div class="card dashboard-card border-0 h-100" style="background: linear-gradient(135deg, var(--accent-color) 0%, #f472b6 100%); color: white;">
                <div class="card-body p-4">
                    <div class="d-flex justify-content-between align-items-start">
                        <div class="flex-grow-1">
                            <div class="stat-number mb-2"><?php echo number_format($stats['active_bonds'] + $stats['active_equities']); ?></div>
                            <div class="stat-label">Active Instruments</div>
                            <div class="mt-2">
                                <small class="opacity-75">
                                    <i class="bi bi-collection me-1"></i>
                                    Bonds & Equities
                                </small>
                            </div>
                        </div>
                        <div class="ms-3">
                            <div class="d-flex align-items-center justify-content-center rounded-circle"
                                 style="width: 50px; height: 50px; background: rgba(255,255,255,0.2);">
                                <i class="bi bi-collection" style="font-size: 1.5rem;"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4 mb-5">
        <div class="col-lg-4">
            <div class="card dashboard-card h-100">
                <div class="card-header bg-transparent border-0 pb-0">
                    <div class="d-flex align-items-center">
                        <div class="me-2">
                            <i class="bi bi-pie-chart text-primary"></i>
                        </div>
                        <h6 class="mb-0 fw-semibold">User Distribution</h6>
                    </div>
                </div>
                <div class="card-body">
                    <div class="space-y-3">
                        <?php foreach ($role_stats as $role => $count): ?>
                            <div class="d-flex justify-content-between align-items-center p-3 rounded-3"
                                 style="background: var(--background-secondary);">
                                <div class="d-flex align-items-center">
                                    <div class="me-3">
                                        <i class="bi bi-<?php
                                            echo $role == 'system_admin' ? 'shield-check' :
                                                ($role == 'trader' ? 'graph-up' :
                                                ($role == 'ceo' ? 'person-workspace' : 'calculator'));
                                            ?> text-primary"></i>
                                    </div>
                                    <span class="fw-medium"><?php echo ucwords(str_replace('_', ' ', $role)); ?></span>
                                </div>
                                <span class="badge bg-primary px-3 py-2"><?php echo $count; ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-lg-8">
            <div class="card dashboard-card h-100">
                <div class="card-header bg-transparent border-0 pb-0">
                    <div class="d-flex justify-content-between align-items-center">
                        <div class="d-flex align-items-center">
                            <div class="me-2">
                                <i class="bi bi-exclamation-triangle text-warning"></i>
                            </div>
                            <h6 class="mb-0 fw-semibold">Pending Mandate Approvals</h6>
                        </div>
                        <span class="badge bg-warning text-dark px-3 py-2"><?php echo count($pending_mandates); ?></span>
                    </div>
                </div>
                <div class="card-body">
                    <?php if (empty($pending_mandates)): ?>
                        <div class="text-center py-5">
                            <i class="bi bi-check-circle text-success" style="font-size: 3rem;"></i>
                            <p class="text-muted mt-3 mb-0">All mandates are up to date</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th class="border-0">User</th>
                                        <th class="border-0">Role</th>
                                        <th class="border-0">Created</th>
                                        <th class="border-0">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($pending_mandates as $user): ?>
                                        <tr>
                                            <td class="border-0">
                                                <div>
                                                    <div class="fw-semibold"><?php echo htmlspecialchars($user['full_name']); ?></div>
                                                    <small class="text-muted"><?php echo htmlspecialchars($user['email']); ?></small>
                                                </div>
                                            </td>
                                            <td class="border-0">
                                                <span class="badge bg-secondary px-3 py-2">
                                                    <?php echo ucwords(str_replace('_', ' ', $user['role'])); ?>
                                                </span>
                                            </td>
                                            <td class="border-0"><?php echo format_date($user['created_at']); ?></td>
                                            <td class="border-0">
                                                <a href="users.php?action=enable_mandate&id=<?php echo $user['id']; ?>"
                                                   class="btn btn-success btn-sm px-3"
                                                   onclick="return confirm('Enable mandate for this user?')">
                                                       <i class="bi bi-check-lg me-1"></i> Enable
                                                </a>
                                            </td>
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

    <div class="row g-4">
        <div class="col-lg-8">
            <div class="card dashboard-card">
                <div class="card-header bg-transparent border-0 pb-0">
                    <div class="d-flex justify-content-between align-items-center">
                        <div class="d-flex align-items-center">
                            <div class="me-2">
                                <i class="bi bi-percent text-primary"></i>
                            </div>
                            <h6 class="mb-0 fw-semibold">Trade Fee Management</h6>
                        </div>
                        <a href="fee_management.php" class="btn btn-primary btn-sm">
                            <i class="bi bi-plus-lg me-1"></i> Add New Fee
                        </a>
                    </div>
                </div>
                <div class="card-body">
                    <?php if (empty($fee_configs)): ?>
                        <div class="text-center py-5">
                            <i class="bi bi-cash-stack text-muted" style="font-size: 3rem;"></i>
                            <p class="text-muted mt-3 mb-0">No fee configurations to display</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th class="border-0">Fee Name</th>
                                        <th class="border-0">Type</th>
                                        <th class="border-0">Applies To</th>
                                        <th class="border-0">Rate (%)</th>
                                        <th class="border-0">Fixed Amount</th>
                                        <th class="border-0">Calculation Base</th>
                                        <th class="border-0">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($fee_configs as $fee): ?>
                                        <tr>
                                            <td class="border-0 fw-medium"><?php echo htmlspecialchars($fee['fee_name']); ?></td>
                                            <td class="border-0">
                                                <span class="badge bg-secondary px-3 py-2">
                                                    <?php echo ucwords(str_replace('_', ' ', $fee['fee_type'])); ?>
                                                </span>
                                            </td>
                                            <td class="border-0"><?php echo ucwords(str_replace('_', ' ', $fee['applies_to'])); ?></td>
                                            <td class="border-0 text-muted"><?php echo number_format($fee['rate_percentage'], 2); ?>%</td>
                                            <td class="border-0 text-muted">Tsh <?php echo number_format($fee['fixed_amount'], 2); ?></td>
                                            <td class="border-0"><?php echo ucwords(str_replace('_', ' ', $fee['calculation_base'])); ?></td>
                                            <td class="border-0">
                                                <a href="fee_management.php?id=<?php echo $fee['id']; ?>" class="btn btn-sm btn-outline-primary me-2">
                                                    <i class="bi bi-pencil-square"></i> Edit
                                                </a>
                                                <a href="fee_management.php?action=delete&id=<?php echo $fee['id']; ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('Are you sure you want to delete this fee?');">
                                                    <i class="bi bi-trash"></i> Delete
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <div class="col-lg-4">
            <div class="card dashboard-card">
                <div class="card-header bg-transparent border-0 pb-0">
                    <div class="d-flex align-items-center">
                        <div class="me-2">
                            <i class="bi bi-lightning text-primary"></i>
                        </div>
                        <h6 class="mb-0 fw-semibold">Quick Actions</h6>
                    </div>
                </div>
                <div class="card-body">
                    <div class="d-grid gap-3">
                        <a href="users.php" class="btn btn-outline-primary btn-lg d-flex align-items-center">
                            <i class="bi bi-people me-3"></i>
                            <div class="text-start">
                                <div class="fw-semibold">Manage Users</div>
                                <small class="text-muted">User accounts & permissions</small>
                            </div>
                        </a>
                        <a href="settings.php" class="btn btn-outline-secondary btn-lg d-flex align-items-center">
                            <i class="bi bi-gear me-3"></i>
                            <div class="text-start">
                                <div class="fw-semibold">System Settings</div>
                                <small class="text-muted">Platform configuration</small>
                            </div>
                        </a>
                        <a href="../reports/" class="btn btn-outline-info btn-lg d-flex align-items-center">
                            <i class="bi bi-file-earmark-text me-3"></i>
                            <div class="text-start">
                                <div class="fw-semibold">View Reports</div>
                                <small class="text-muted">Analytics & insights</small>
                            </div>
                        </a>
                        <a href="master_data.php" class="btn btn-outline-warning btn-lg d-flex align-items-center">
                            <i class="bi bi-database me-3"></i>
                            <div class="text-start">
                                <div class="fw-semibold">Master Data</div>
                                <small class="text-muted">System configuration</small>
                            </div>
                        </a>
                        <a href="fee_management.php" class="btn btn-outline-danger btn-lg d-flex align-items-center">
                            <i class="bi bi-percent me-3"></i>
                            <div class="text-start">
                                <div class="fw-semibold">Fee Management</div>
                                <small class="text-muted">Configure transaction fees</small>
                            </div>
                        </a>
                        <a href="../trader/trades.php" class="btn btn-outline-success btn-lg d-flex align-items-center">
                            <i class="bi bi-graph-up me-3"></i>
                            <div class="text-start">
                                <div class="fw-semibold">View All Trades</div>
                                <small class="text-muted">Trading activity</small>
                            </div>
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>

<?php include '../includes/footer.php'; ?>