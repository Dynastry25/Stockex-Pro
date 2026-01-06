<?php
require_once '../config/config.php';
require_once '../auth/auth_middleware.php';

// Allow both finance officers and CEOs to access this page
$user = get_logged_in_user();
$allowed_roles = ['finance_officer', 'ceo', 'system_admin'];
if (!in_array($user['role'], $allowed_roles)) {
    show_alert('You do not have permission to access financial reports.', 'danger');
    redirect('auth/login.php');
}

$db = getDBConnection();

$page_title = 'Financial Reports';
include '../includes/header.php';
?>

<div class="container-fluid py-4">
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-header bg-primary">
                    <h4 class="mb-0"><i class="bi bi-file-earmark-text me-2"></i>Financial Reports</h4>
                    <p class="mb-0">Generate and export comprehensive financial statements</p>
                </div>
                <div class="card-body">
                    <div class="row g-4">
                        <!-- Balance Sheet Report -->
                        <div class="col-xl-3 col-md-6">
                            <div class="card report-card border-0 shadow-sm h-100">
                                <div class="card-body text-center p-4">
                                    <div class="report-icon mb-3">
                                        <div class="d-inline-flex align-items-center justify-content-center rounded-circle" 
                                             style="width: 80px; height: 80px; background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%);">
                                            <i class="bi bi-balance-scale" style="font-size: 2rem;"></i>
                                        </div>
                                    </div>
                                    <h5 class="report-title mb-2">Balance Sheet</h5>
                                    <p class="report-description text-muted mb-3">
                                        Statement of financial position showing assets, liabilities, and equity
                                    </p>
                                    <div class="report-actions">
                                        <a href="balance_sheet.php" class="btn btn-primary btn-sm me-2">
                                            <i class="bi bi-eye me-1"></i>View
                                        </a>
                                        <a href="balance_sheet.php?export=pdf" class="btn btn-outline-primary btn-sm">
                                            <i class="bi bi-download me-1"></i>PDF
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Income Statement Report -->
                        <div class="col-xl-3 col-md-6">
                            <div class="card report-card border-0 shadow-sm h-100">
                                <div class="card-body text-center p-4">
                                    <div class="report-icon mb-3">
                                        <div class="d-inline-flex align-items-center justify-content-center rounded-circle" 
                                             style="width: 80px; height: 80px; background: linear-gradient(135deg, #10b981 0%, #047857 100%);">
                                            <i class="bi bi-graph-up-arrow text-white" style="font-size: 2rem;"></i>
                                        </div>
                                    </div>
                                    <h5 class="report-title mb-2">Income Statement</h5>
                                    <p class="report-description text-muted mb-3">
                                        Profit & Loss statement showing revenues, expenses, and net income
                                    </p>
                                    <div class="report-actions">
                                        <a href="income_statement.php" class="btn btn-success btn-sm me-2">
                                            <i class="bi bi-eye me-1"></i>View
                                        </a>
                                        <a href="income_statement.php?export=pdf" class="btn btn-outline-success btn-sm">
                                            <i class="bi bi-download me-1"></i>PDF
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Cash Flow Statement -->
                        <div class="col-xl-3 col-md-6">
                            <div class="card report-card border-0 shadow-sm h-100">
                                <div class="card-body text-center p-4">
                                    <div class="report-icon mb-3">
                                        <div class="d-inline-flex align-items-center justify-content-center rounded-circle" 
                                             style="width: 80px; height: 80px; background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);">
                                            <i class="bi bi-cash-coin text-white" style="font-size: 2rem;"></i>
                                        </div>
                                    </div>
                                    <h5 class="report-title mb-2">Cash Flow Statement</h5>
                                    <p class="report-description text-muted mb-3">
                                        Statement showing cash inflows and outflows from operating, investing, and financing activities
                                    </p>
                                    <div class="report-actions">
                                        <a href="cashflow_statement.php" class="btn btn-warning btn-sm me-2">
                                            <i class="bi bi-eye me-1"></i>View
                                        </a>
                                        <a href="cashflow_statement.php?export=pdf" class="btn btn-outline-warning btn-sm">
                                            <i class="bi bi-download me-1"></i>PDF
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Statement of Changes in Equity -->
                        <div class="col-xl-3 col-md-6">
                            <div class="card report-card border-0 shadow-sm h-100">
                                <div class="card-body text-center p-4">
                                    <div class="report-icon mb-3">
                                        <div class="d-inline-flex align-items-center justify-content-center rounded-circle" 
                                             style="width: 80px; height: 80px; background: linear-gradient(135deg, #8b5cf6 0%, #7c3aed 100%);">
                                            <i class="bi bi-pie-chart text-white" style="font-size: 2rem;"></i>
                                        </div>
                                    </div>
                                    <h5 class="report-title mb-2">Changes in Equity</h5>
                                    <p class="report-description text-muted mb-3">
                                        Statement showing changes in owner's equity including investments and dividends
                                    </p>
                                    <div class="report-actions">
                                        <a href="equity_statement.php" class="btn btn-info btn-sm me-2">
                                            <i class="bi bi-eye me-1"></i>View
                                        </a>
                                        <a href="equity_statement.php?export=pdf" class="btn btn-outline-info btn-sm">
                                            <i class="bi bi-download me-1"></i>PDF
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Quick Actions -->
                    <div class="row mt-5">
                        <div class="col-12">
                            <div class="card">
                                <div class="card-header bg-light">
                                    <h6 class="mb-0"><i class="bi bi-lightning me-2"></i>Quick Actions</h6>
                                </div>
                                <div class="card-body">
                                    <div class="row g-3">
                                        <div class="col-md-4">
                                            <a href="settings.php" class="btn btn-outline-primary w-100 h-100 d-flex flex-column align-items-center justify-content-center p-4 text-decoration-none">
                                                <i class="bi bi-gear mb-2" style="font-size: 2rem;"></i>
                                                <div class="fw-semibold">Configure Reports</div>
                                                <small class="text-muted">Set up financial statement components</small>
                                            </a>
                                        </div>
                                        <div class="col-md-4">
                                            <a href="financial_data.php" class="btn btn-outline-success w-100 h-100 d-flex flex-column align-items-center justify-content-center p-4 text-decoration-none">
                                                <i class="bi bi-database mb-2" style="font-size: 2rem;"></i>
                                                <div class="fw-semibold">Enter Financial Data</div>
                                                <small class="text-muted">Input financial figures</small>
                                            </a>
                                        </div>
                                        <div class="col-md-4">
                                            <a href="report_scheduler.php" class="btn btn-outline-info w-100 h-100 d-flex flex-column align-items-center justify-content-center p-4 text-decoration-none">
                                                <i class="bi bi-clock mb-2" style="font-size: 2rem;"></i>
                                                <div class="fw-semibold">Schedule Reports</div>
                                                <small class="text-muted">Automate report generation</small>
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
.report-card {
    transition: transform 0.2s ease-in-out, box-shadow 0.2s ease-in-out;
}
.report-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 8px 25px rgba(0,0,0,0.15) !important;
}
.report-icon {
    transition: transform 0.2s ease-in-out;
}
.report-card:hover .report-icon {
    transform: scale(1.1);
}
</style>

<?php include '../includes/footer.php'; ?>