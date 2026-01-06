<?php
require_once '../config/config.php';
require_once '../auth/auth_middleware.php';

// Allow both CEOs and admins to access this page
require_login();
$user = get_logged_in_user();
$allowed_roles = ['system_admin', 'ceo'];
if (!in_array($user['role'], $allowed_roles)) {
    show_alert('You do not have permission to access master data.', 'danger');
    redirect('auth/login.php');
}

$db = getDBConnection();

// Get utilization statistics for each master data table
$utilization_stats = [];

$tables = [
    'sub_ledger_groups' => 'Sub Ledger Groups',
    'sub_ledger_categories' => 'Sub Ledger Categories', 
    'sub_ledger_related_parties' => 'Related Parties',
    'sub_ledger_status' => 'Account Status',
    'titles' => 'Titles',
    'identity_types' => 'Identity Types',
    'investment_asset_classes' => 'Asset Classes',
    'transaction_types' => 'Transaction Types',
    'payment_methods' => 'Payment Methods',
    'ledger_types' => 'Ledger Types',
    'companies' => 'Company Information',
    'custodians' => 'Custodians',
    'brokers' => 'Brokers',
    'payment_frequencies' => 'Payment Frequencies',
    'bonds_economic_sectors' => 'Bonds Economic Sectors',
    'share_types' => 'Share Types',
    'share_market_trends' => 'Share Market Trends',
    'gl_account_types' => 'GL Account Types',
    'gl_account_formats' => 'GL Account Formats',
    'balance_sheet_reporting_formats' => 'Balance Sheet Formats',
    'bond_types' => 'Bond Types',
    'bond_issuers' => 'Bond Issuers',
    'coupon_determiners' => 'Coupon Determiners',
    'equities_settings' => 'Equities Settings',
    'cash_flow_formats' => 'Cash Flow Formats'  // Added for cash flow configuration
];

foreach ($tables as $table => $name) {
    try {
        $stmt = $db->query("SELECT COUNT(*) as total FROM $table WHERE is_active = 1");
        $result = $stmt->fetch();
        $utilization_stats[$table] = [
            'name' => $name,
            'count' => $result['total'] ?? 0
        ];
    } catch (Exception $e) {
        $utilization_stats[$table] = [
            'name' => $name,
            'count' => 0
        ];
    }
}

$page_title = 'Master Data Management';
include '../includes/header.php';
?>

<!-- Professional master data management header -->
<div class="page-header">
    <div class="container-fluid">
        <div class="row align-items-center">
            <div class="col-md-8">
                <div class="d-flex align-items-center">
                    <div class="me-3">
                        <div class="d-inline-flex align-items-center justify-content-center rounded-circle shadow-sm" 
                             style="width: 60px; height: 60px; background: linear-gradient(135deg, var(--primary-color) 0%, var(--primary-light) 100%);">
                            <i class="bi bi-database text-white" style="font-size: 1.5rem;"></i>
                        </div>
                    </div>
                    <div>
                        <h1 class="page-title mb-1">Master Data Management</h1>
                        <p class="page-subtitle">View system reference data and business rules</p>
                    </div>
                </div>
            </div>
            <div class="col-md-4 text-end">
                <div class="d-flex flex-column align-items-end">
                    <span class="text-muted small">System Configuration</span>
                    <span class="fw-semibold">Victory Financial Services</span>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="container-fluid">
    <!-- Master data categories with professional styling -->
    <div class="row g-4 mb-5">
        <!-- Sub Ledger Management Section -->
        <div class="col-12">
            <div class="card dashboard-card">
                <div class="card-header bg-transparent border-0 pb-0">
                    <div class="d-flex align-items-center">
                        <div class="me-2">
                            <i class="bi bi-diagram-3 text-primary"></i>
                        </div>
                        <h5 class="mb-0 fw-semibold">Sub Ledger Management</h5>
                    </div>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-lg-3 col-md-6">
                            <div class="card border-0 h-100" style="background: linear-gradient(135deg, #164e63 0%, #0891b2 100%); color: white;">
                                <div class="card-body p-4">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <div class="h4 mb-1"><?php echo $utilization_stats['sub_ledger_groups']['count']; ?></div>
                                            <div class="small">Sub Ledger Groups</div>
                                        </div>
                                        <i class="bi bi-collection" style="font-size: 2rem; opacity: 0.7;"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-3 col-md-6">
                            <div class="card border-0 h-100" style="background: linear-gradient(135deg, #059669 0%, #10b981 100%); color: white;">
                                <div class="card-body p-4">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <div class="h4 mb-1"><?php echo $utilization_stats['sub_ledger_categories']['count']; ?></div>
                                            <div class="small">Sub Ledger Categories</div>
                                        </div>
                                        <i class="bi bi-tags" style="font-size: 2rem; opacity: 0.7;"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-3 col-md-6">
                            <div class="card border-0 h-100" style="background: linear-gradient(135deg, #0284c7 0%, #0ea5e9 100%); color: white;">
                                <div class="card-body p-4">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <div class="h4 mb-1"><?php echo $utilization_stats['sub_ledger_related_parties']['count']; ?></div>
                                            <div class="small">Related Parties</div>
                                        </div>
                                        <i class="bi bi-people" style="font-size: 2rem; opacity: 0.7;"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-3 col-md-6">
                            <div class="card border-0 h-100" style="background: linear-gradient(135deg, #dc2626 0%, #ef4444 100%); color: white;">
                                <div class="card-body p-4">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <div class="h4 mb-1"><?php echo $utilization_stats['sub_ledger_status']['count']; ?></div>
                                            <div class="small">Account Status</div>
                                        </div>
                                        <i class="bi bi-check-circle" style="font-size: 2rem; opacity: 0.7;"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Investment Configuration Section -->
        <div class="col-12">
            <div class="card dashboard-card">
                <div class="card-header bg-transparent border-0 pb-0">
                    <div class="d-flex align-items-center">
                        <div class="me-2">
                            <i class="bi bi-graph-up text-primary"></i>
                        </div>
                        <h5 class="mb-0 fw-semibold">Investment Configuration</h5>
                    </div>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-lg-3 col-md-6">
                            <div class="card border-0 h-100" style="background: linear-gradient(135deg, #0f766e 0%, #14b8a6 100%); color: white;">
                                <div class="card-body p-4">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <div class="h4 mb-1"><?php echo $utilization_stats['investment_asset_classes']['count']; ?></div>
                                            <div class="small">Asset Classes</div>
                                        </div>
                                        <i class="bi bi-pie-chart" style="font-size: 2rem; opacity: 0.7;"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-3 col-md-6">
                            <div class="card border-0 h-100" style="background: linear-gradient(135deg, #1e40af 0%, #3b82f6 100%); color: white;">
                                <div class="card-body p-4">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <div class="h4 mb-1"><?php echo $utilization_stats['equities_settings']['count']; ?></div>
                                            <div class="small">Equities Settings</div>
                                        </div>
                                        <i class="bi bi-graph-up-arrow" style="font-size: 2rem; opacity: 0.7;"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-3 col-md-6">
                            <div class="card border-0 h-100" style="background: linear-gradient(135deg, #7c2d12 0%, #ea580c 100%); color: white;">
                                <div class="card-body p-4">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <div class="h4 mb-1"><?php echo $utilization_stats['custodians']['count']; ?></div>
                                            <div class="small">Custodians</div>
                                        </div>
                                        <i class="bi bi-shield-check" style="font-size: 2rem; opacity: 0.7;"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-3 col-md-6">
                            <div class="card border-0 h-100" style="background: linear-gradient(135deg, #581c87 0%, #8b5cf6 100%); color: white;">
                                <div class="card-body p-4">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <div class="h4 mb-1"><?php echo $utilization_stats['brokers']['count']; ?></div>
                                            <div class="small">Brokers</div>
                                        </div>
                                        <i class="bi bi-briefcase" style="font-size: 2rem; opacity: 0.7;"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Financial Configuration Section -->
        <div class="col-12">
            <div class="card dashboard-card">
                <div class="card-header bg-transparent border-0 pb-0">
                    <div class="d-flex align-items-center">
                        <div class="me-2">
                            <i class="bi bi-bank text-primary"></i>
                        </div>
                        <h5 class="mb-0 fw-semibold">Financial Configuration</h5>
                    </div>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-lg-3 col-md-6">
                            <div class="card border-0 h-100" style="background: linear-gradient(135deg, #0c4a6e 0%, #0284c7 100%); color: white;">
                                <div class="card-body p-4">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <div class="h4 mb-1"><?php echo $utilization_stats['payment_frequencies']['count']; ?></div>
                                            <div class="small">Payment Frequencies</div>
                                        </div>
                                        <i class="bi bi-calendar-event" style="font-size: 2rem; opacity: 0.7;"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-3 col-md-6">
                            <div class="card border-0 h-100" style="background: linear-gradient(135deg, #166534 0%, #22c55e 100%); color: white;">
                                <div class="card-body p-4">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <div class="h4 mb-1"><?php echo $utilization_stats['bonds_economic_sectors']['count']; ?></div>
                                            <div class="small">Bond Economic Sectors</div>
                                        </div>
                                        <i class="bi bi-building" style="font-size: 2rem; opacity: 0.7;"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-3 col-md-6">
                            <div class="card border-0 h-100" style="background: linear-gradient(135deg, #7c2d12 0%, #f97316 100%); color: white;">
                                <div class="card-body p-4">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <div class="h4 mb-1"><?php echo $utilization_stats['bond_types']['count']; ?></div>
                                            <div class="small">Bond Types</div>
                                        </div>
                                        <i class="bi bi-receipt" style="font-size: 2rem; opacity: 0.7;"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-3 col-md-6">
                            <div class="card border-0 h-100" style="background: linear-gradient(135deg, #92400e 0%, #f59e0b 100%); color: white;">
                                <div class="card-body p-4">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <div class="h4 mb-1"><?php echo $utilization_stats['share_types']['count']; ?></div>
                                            <div class="small">Share Types</div>
                                        </div>
                                        <i class="bi bi-share" style="font-size: 2rem; opacity: 0.7;"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- System overview -->
    <div class="row g-4">
        <div class="col-lg-8">
            <div class="card dashboard-card">
                <div class="card-header bg-transparent border-0 pb-0">
                    <div class="d-flex align-items-center">
                        <div class="me-2">
                            <i class="bi bi-info-circle text-primary"></i>
                        </div>
                        <h6 class="mb-0 fw-semibold">Master Data Overview</h6>
                    </div>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <div class="p-3 rounded-3" style="background: var(--background-secondary);">
                                <div class="d-flex align-items-center">
                                    <i class="bi bi-database text-primary me-3" style="font-size: 1.5rem;"></i>
                                    <div>
                                        <div class="fw-semibold">Configuration Tables</div>
                                        <div class="text-muted small"><?php echo count($tables); ?> master data categories</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="p-3 rounded-3" style="background: var(--background-secondary);">
                                <div class="d-flex align-items-center">
                                    <i class="bi bi-check-circle text-success me-3" style="font-size: 1.5rem;"></i>
                                    <div>
                                        <div class="fw-semibold">System Status</div>
                                        <div class="text-muted small">All configurations active</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
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
                    <div class="d-grid gap-2">
                        <a href="dashboard.php" class="btn btn-outline-primary">
                            <i class="bi bi-arrow-left me-2"></i>Back to Dashboard
                        </a>
                        <a href="../finance/reports_dashboard.php" class="btn btn-outline-info">
                            <i class="bi bi-file-earmark-text me-2"></i>View Reports
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include '../includes/footer.php'; ?>
