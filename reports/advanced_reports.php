<?php
require_once '../config/config.php';
require_once '../auth/auth_middleware.php';

// Require login
require_login();

$db = getDBConnection();
$current_user = get_logged_in_user($db);

// Get company details
$company_stmt = $db->query("SELECT company_name FROM companies WHERE status = 'active' LIMIT 1");
$company = $company_stmt->fetch();
$company_name = $company ? $company['company_name'] : 'Victory Financial Services Limited';

// Get filter options from database with name-based filtering
$clients_query = "SELECT DISTINCT client_name, client_cds_account FROM trades WHERE status = 'active' AND client_name IS NOT NULL AND client_name != '' ORDER BY client_name";
$clients = $db->query($clients_query)->fetchAll(PDO::FETCH_ASSOC);

$brokers_query = "SELECT DISTINCT broker_name FROM trades WHERE status = 'active' AND broker_name IS NOT NULL AND broker_name != '' ORDER BY broker_name";
$brokers = $db->query($brokers_query)->fetchAll(PDO::FETCH_ASSOC);

$securities_query = "SELECT DISTINCT security_id, security_name FROM trades WHERE status = 'active' AND security_name IS NOT NULL AND security_name != '' ORDER BY security_name";
$securities = $db->query($securities_query)->fetchAll(PDO::FETCH_ASSOC);

$users_query = "SELECT DISTINCT full_name FROM users WHERE is_active = 1 AND full_name IS NOT NULL AND full_name != '' ORDER BY full_name";
$users = $db->query($users_query)->fetchAll(PDO::FETCH_ASSOC);

// Get distinct asset classes
$asset_classes_query = "SELECT DISTINCT asset_class FROM trades WHERE asset_class IS NOT NULL AND asset_class != '' ORDER BY asset_class";
$asset_classes = $db->query($asset_classes_query)->fetchAll(PDO::FETCH_ASSOC);

include '../includes/header.php';
?>

<div class="container-fluid py-4">
    <div class="row">
        <div class="col-12">
            <div class="card shadow-sm">
                <div class="card-header bg-gradient-primary">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h4 class="mb-0">
                                <i class="fas fa-chart-bar me-2"></i>
                                Advanced Reports & Analytics
                            </h4>
                            <p class="mb-0 opacity-75">Generate comprehensive financial reports with advanced filtering</p>
                        </div>
                        <div class="text-end">
                            <small class="opacity-75"><?php echo htmlspecialchars($company_name); ?></small>
                        </div>
                    </div>
                </div>
                
                <div class="card-body">
                    <!-- Form for share transaction reports (go to generate_share_report.php) -->
                    <form id="shareReportForm" method="POST" action="generate_share_report.php" style="display: none;">
                        <input type="hidden" name="client" id="share_client">
                        <input type="hidden" name="agent" id="share_agent">
                        <input type="hidden" name="broker" id="share_broker">
                        <input type="hidden" name="security" id="share_security">
                        <input type="hidden" name="asset_class" id="share_asset_class">
                        <input type="hidden" name="trade_type" id="share_trade_type">
                        <input type="hidden" name="user" id="share_user">
                        <input type="hidden" name="status" id="share_status">
                        <input type="hidden" name="period_from" id="share_period_from">
                        <input type="hidden" name="period_to" id="share_period_to">
                        <input type="hidden" name="report_by" id="share_report_by">
                        <input type="hidden" name="report_type" id="share_report_type">
                        <input type="hidden" name="orientation" id="share_orientation">
                        <input type="hidden" name="watermark" id="share_watermark">
                        <input type="hidden" name="report_name" id="share_report_name">
                        <input type="hidden" name="print_mode" id="share_print_mode">
                        <input type="hidden" name="export_type" id="share_export_type">
                    </form>

                    <!-- Form for bonds reports (go to generate_bonds_report.php) -->
                    <form id="bondsReportForm" method="POST" action="generate_bonds_report.php" style="display: none;">
                        <input type="hidden" name="client" id="bonds_client">
                        <input type="hidden" name="agent" id="bonds_agent">
                        <input type="hidden" name="broker" id="bonds_broker">
                        <input type="hidden" name="security" id="bonds_security">
                        <input type="hidden" name="asset_class" id="bonds_asset_class">
                        <input type="hidden" name="trade_type" id="bonds_trade_type">
                        <input type="hidden" name="user" id="bonds_user">
                        <input type="hidden" name="status" id="bonds_status">
                        <input type="hidden" name="period_from" id="bonds_period_from">
                        <input type="hidden" name="period_to" id="bonds_period_to">
                        <input type="hidden" name="report_by" id="bonds_report_by">
                        <input type="hidden" name="report_type" id="bonds_report_type">
                        <input type="hidden" name="orientation" id="bonds_orientation">
                        <input type="hidden" name="watermark" id="bonds_watermark">
                        <input type="hidden" name="report_name" id="bonds_report_name">
                        <input type="hidden" name="print_mode" id="bonds_print_mode">
                        <input type="hidden" name="export_type" id="bonds_export_type">
                    </form>

                    <!-- Form for statutory deductions reports -->
                    <form id="statutoryDeductionsForm" method="POST" action="Statutory_Deductions_Reports.php" style="display: none;">
                        <input type="hidden" name="client" id="statutory_client">
                        <input type="hidden" name="agent" id="statutory_agent">
                        <input type="hidden" name="broker" id="statutory_broker">
                        <input type="hidden" name="security" id="statutory_security">
                        <input type="hidden" name="asset_class" id="statutory_asset_class">
                        <input type="hidden" name="trade_type" id="statutory_trade_type">
                        <input type="hidden" name="user" id="statutory_user">
                        <input type="hidden" name="status" id="statutory_status">
                        <input type="hidden" name="period_from" id="statutory_period_from">
                        <input type="hidden" name="period_to" id="statutory_period_to">
                        <input type="hidden" name="report_by" id="statutory_report_by">
                        <input type="hidden" name="report_type" id="statutory_report_type">
                        <input type="hidden" name="orientation" id="statutory_orientation">
                        <input type="hidden" name="watermark" id="statutory_watermark">
                        <input type="hidden" name="report_name" id="statutory_report_name">
                        <input type="hidden" name="print_mode" id="statutory_print_mode">
                        <input type="hidden" name="export_type" id="statutory_export_type">
                    </form>

                    <!-- Form for other reports that go to generate_report.php -->
                    <form id="generateReportForm" method="POST" action="generate_report.php" style="display: none;">
                        <input type="hidden" name="client" id="generate_client">
                        <input type="hidden" name="agent" id="generate_agent">
                        <input type="hidden" name="broker" id="generate_broker">
                        <input type="hidden" name="security" id="generate_security">
                        <input type="hidden" name="asset_class" id="generate_asset_class">
                        <input type="hidden" name="trade_type" id="generate_trade_type">
                        <input type="hidden" name="user" id="generate_user">
                        <input type="hidden" name="status" id="generate_status">
                        <input type="hidden" name="period_from" id="generate_period_from">
                        <input type="hidden" name="period_to" id="generate_period_to">
                        <input type="hidden" name="report_by" id="generate_report_by">
                        <input type="hidden" name="report_type" id="generate_report_type">
                        <input type="hidden" name="orientation" id="generate_orientation">
                        <input type="hidden" name="watermark" id="generate_watermark">
                        <input type="hidden" name="report_name" id="generate_report_name">
                        <input type="hidden" name="print_mode" id="generate_print_mode">
                        <input type="hidden" name="export_type" id="generate_export_type">
                    </form>

                    <!-- Form for contract notes that go to contract_note.php -->
                    <form id="contractNoteForm" method="POST" action="contract_note.php" style="display: none;">
                        <input type="hidden" name="client" id="contract_client">
                        <input type="hidden" name="agent" id="contract_agent">
                        <input type="hidden" name="broker" id="contract_broker">
                        <input type="hidden" name="security" id="contract_security">
                        <input type="hidden" name="asset_class" id="contract_asset_class">
                        <input type="hidden" name="trade_type" id="contract_trade_type">
                        <input type="hidden" name="user" id="contract_user">
                        <input type="hidden" name="status" id="contract_status">
                        <input type="hidden" name="period_from" id="contract_period_from">
                        <input type="hidden" name="period_to" id="contract_period_to">
                        <input type="hidden" name="report_by" id="contract_report_by">
                        <input type="hidden" name="report_type" id="contract_report_type">
                        <input type="hidden" name="orientation" id="contract_orientation">
                        <input type="hidden" name="watermark" id="contract_watermark">
                        <input type="hidden" name="report_name" id="contract_report_name">
                        <input type="hidden" name="print_mode" id="contract_print_mode">
                        <input type="hidden" name="export_type" id="contract_export_type">
                    </form>

                    <!-- Form for commission reports that go to commissions.php -->
                    <form id="commissionForm" method="POST" action="commissions.php" style="display: none;">
                        <input type="hidden" name="client" id="commission_client">
                        <input type="hidden" name="agent" id="commission_agent">
                        <input type="hidden" name="broker" id="commission_broker">
                        <input type="hidden" name="security" id="commission_security">
                        <input type="hidden" name="asset_class" id="commission_asset_class">
                        <input type="hidden" name="trade_type" id="commission_trade_type">
                        <input type="hidden" name="user" id="commission_user">
                        <input type="hidden" name="status" id="commission_status">
                        <input type="hidden" name="period_from" id="commission_period_from">
                        <input type="hidden" name="period_to" id="commission_period_to">
                        <input type="hidden" name="report_by" id="commission_report_by">
                        <input type="hidden" name="report_type" id="commission_report_type">
                        <input type="hidden" name="orientation" id="commission_orientation">
                        <input type="hidden" name="watermark" id="commission_watermark">
                        <input type="hidden" name="report_name" id="commission_report_name">
                        <input type="hidden" name="print_mode" id="commission_print_mode">
                        <input type="hidden" name="export_type" id="commission_export_type">
                    </form>

                    <!-- Main filter form (no action, used for collecting data) -->
                    <div id="filterForm">
                        <div class="row g-3">
                            <!-- Client Filter with Search -->
                            <div class="col-md-3">
                                <label class="form-label fw-semibold">Client</label>
                                <select class="form-select form-select-sm select2-search" name="client" id="client">
                                    <option value="">ALL CLIENTS</option>
                                    <?php foreach ($clients as $client): ?>
                                        <option value="<?= htmlspecialchars($client['client_cds_account']) ?>">
                                            <?= htmlspecialchars($client['client_name']) ?> (<?= htmlspecialchars($client['client_cds_account']) ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <!-- Agent Filter -->
                            <div class="col-md-3">
                                <label class="form-label fw-semibold">Agent</label>
                                <select class="form-select form-select-sm" name="agent" id="agent">
                                    <option value="">ALL AGENTS</option>
                                    <?php foreach ($users as $user): ?>
                                        <option value="<?= htmlspecialchars($user['full_name']) ?>">
                                            <?= htmlspecialchars($user['full_name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <!-- Broker Filter -->
                            <div class="col-md-3">
                                <label class="form-label fw-semibold">Broker</label>
                                <select class="form-select form-select-sm" name="broker" id="broker">
                                    <option value="">ALL BROKERS</option>
                                    <?php foreach ($brokers as $broker): ?>
                                        <option value="<?= htmlspecialchars($broker['broker_name']) ?>">
                                            <?= htmlspecialchars($broker['broker_name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <!-- Security Filter with Search -->
                            <div class="col-md-3">
                                <label class="form-label fw-semibold">Security</label>
                                <select class="form-select form-select-sm select2-search" name="security" id="security">
                                    <option value="">ALL SECURITIES</option>
                                    <?php foreach ($securities as $security): ?>
                                        <option value="<?= htmlspecialchars($security['security_id']) ?>">
                                            <?= htmlspecialchars($security['security_name']) ?> (<?= htmlspecialchars($security['security_id']) ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <!-- Asset Class Filter -->
                            <div class="col-md-3">
                                <label class="form-label fw-semibold">Asset Class</label>
                                <select class="form-select form-select-sm" name="asset_class" id="asset_class">
                                    <option value="">ALL ASSET CLASSES</option>
                                    <?php foreach ($asset_classes as $asset_class): ?>
                                        <option value="<?= htmlspecialchars($asset_class['asset_class']) ?>">
                                            <?= htmlspecialchars(strtoupper($asset_class['asset_class'])) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <!-- Type Filter -->
                            <div class="col-md-3">
                                <label class="form-label fw-semibold">Type</label>
                                <select class="form-select form-select-sm" name="trade_type" id="trade_type">
                                    <option value="">ALL TYPES</option>
                                    <option value="BUY">BUY</option>
                                    <option value="SELL">SELL</option>
                                </select>
                            </div>

                            <!-- User Filter -->
                            <div class="col-md-3">
                                <label class="form-label fw-semibold">User</label>
                                <select class="form-select form-select-sm" name="user" id="user">
                                    <option value="">ALL USERS</option>
                                    <?php foreach ($users as $user): ?>
                                        <option value="<?= htmlspecialchars($user['full_name']) ?>">
                                            <?= htmlspecialchars($user['full_name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <!-- Status Filter -->
                            <div class="col-md-3">
                                <label class="form-label fw-semibold">Status</label>
                                <select class="form-select form-select-sm" name="status" id="status">
                                    <option value="">ALL STATUS</option>
                                    <option value="active">ACTIVE</option>
                                    <option value="cancelled">CANCELLED</option>
                                    <option value="settled">SETTLED</option>
                                </select>
                            </div>

                            <!-- Period From -->
                            <div class="col-md-3">
                                <label class="form-label fw-semibold">Period From</label>
                                <input type="date" class="form-control form-control-sm" name="period_from" id="period_from" 
                                       value="<?= date('Y-01-01') ?>">
                            </div>

                            <!-- Period To -->
                            <div class="col-md-3">
                                <label class="form-label fw-semibold">Period To</label>
                                <input type="date" class="form-control form-control-sm" name="period_to" id="period_to" 
                                       value="<?= date('Y-m-d') ?>">
                            </div>

                            <!-- Report By -->
                            <div class="col-md-3">
                                <label class="form-label fw-semibold">Report By</label>
                                <select class="form-select form-select-sm" name="report_by" id="report_by">
                                    <option value="month">Month</option>
                                    <option value="day">Day</option>
                                    <option value="week">Week</option>
                                    <option value="quarter">Quarter</option>
                                    <option value="asset_class">Asset Class</option>
                                    <option value="client">Client</option>
                                    <option value="security">Security</option>
                                </select>
                            </div>

                            <!-- Report Type -->
                            <div class="col-md-3">
                                <label class="form-label fw-semibold">Report Type</label>
                                <div class="d-flex gap-3 mt-2">
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" name="report_type" id="detailed" value="detailed" checked>
                                        <label class="form-check-label" for="detailed">Detailed</label>
                                    </div>
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" name="report_type" id="summary" value="summary">
                                        <label class="form-check-label" for="summary">Summary</label>
                                    </div>
                                </div>
                            </div>

                            <!-- Orientation -->
                            <div class="col-md-3">
                                <label class="form-label fw-semibold">Orientation</label>
                                <div class="d-flex gap-3 mt-2">
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" name="orientation" id="portrait" value="portrait">
                                        <label class="form-check-label" for="portrait">Portrait</label>
                                    </div>
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" name="orientation" id="landscape" value="landscape" checked>
                                        <label class="form-check-label" for="landscape">Landscape</label>
                                    </div>
                                </div>
                            </div>

                            <!-- Watermark -->
                            <div class="col-md-3">
                                <label class="form-label fw-semibold">Watermark</label>
                                <div class="d-flex gap-3 mt-2">
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" name="watermark" id="no_watermark" value="no" checked>
                                        <label class="form-check-label" for="no_watermark">No</label>
                                    </div>
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" name="watermark" id="yes_watermark" value="yes">
                                        <label class="form-check-label" for="yes_watermark">Yes</label>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Report Selection -->
                        <div class="row mt-4">
                            <div class="col-12">
                                <h5 class="mb-3">Select Report Type</h5>
                                <div class="row g-3">
                                    <!-- Statutory Deductions Reports (go to generate_statutory_deductions.php) -->
                                    <div class="col-md-4">
                                        <div class="card border-purple">
                                            <div class="card-body text-center">
                                                <i class="fas fa-file-invoice fa-2x text-purple mb-2"></i>
                                                <h6>Bonds Statutory Deductions</h6>
                                                <button type="button" onclick="generateStatutoryReport('bonds_statutory')" class="btn btn-purple btn-sm">Generate</button>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="card border-purple">
                                            <div class="card-body text-center">
                                                <i class="fas fa-chart-pie fa-2x text-purple mb-2"></i>
                                                <h6>Equities Statutory Deductions</h6>
                                                <button type="button" onclick="generateStatutoryReport('equities_statutory')" class="btn btn-purple btn-sm">Generate</button>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="card border-purple">
                                            <div class="card-body text-center">
                                                <i class="fas fa-calculator fa-2x text-purple mb-2"></i>
                                                <h6>All Statutory Deductions</h6>
                                                <button type="button" onclick="generateStatutoryReport('all_statutory')" class="btn btn-purple btn-sm">Generate</button>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Bond Reports (go to generate_bonds_report.php) -->
                                    <div class="col-md-4">
                                        <div class="card border-primary">
                                            <div class="card-body text-center">
                                                <i class="fas fa-file-invoice-dollar fa-2x text-primary mb-2"></i>
                                                <h6>Bonds Transactions Edit List</h6>
                                                <button type="button" onclick="generateBondsReport('bonds_edit_list')" class="btn btn-primary btn-sm">Generate</button>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="card border-success">
                                            <div class="card-body text-center">
                                                <i class="fas fa-chart-bar fa-2x text-success mb-2"></i>
                                                <h6>Bonds Transactions Summary</h6>
                                                <button type="button" onclick="generateBondsReport('bonds_summary')" class="btn btn-success btn-sm">Generate</button>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Share/Equity Transaction Reports (go to generate_share_report.php) -->
                                    <div class="col-md-4">
                                        <div class="card border-secondary">
                                            <div class="card-body text-center">
                                                <i class="fas fa-list-alt fa-2x text-secondary mb-2"></i>
                                                <h6>Transaction Summary</h6>
                                                <button type="button" onclick="generateShareReport('transaction_summary')" class="btn btn-secondary btn-sm">Generate</button>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="card border-dark">
                                            <div class="card-body text-center">
                                                <i class="fas fa-building fa-2x text-dark mb-2"></i>
                                                <h6>Shares Broker Summary</h6>
                                                <button type="button" onclick="generateShareReport('broker_summary')" class="btn btn-dark btn-sm">Generate</button>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Contract Notes (DIRECT PDF GENERATION) -->
                                    <div class="col-md-4">
                                        <div class="card border-info">
                                            <div class="card-body text-center">
                                                <i class="fas fa-file-contract fa-2x text-info mb-2"></i>
                                                <h6>Contract Notes</h6>
                                                <button type="button" onclick="generateContractNote('contract_notes')" class="btn btn-info btn-sm">Generate PDF</button>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Commission Summary (go to commissions.php) -->
                                    <div class="col-md-4">
                                        <div class="card border-warning">
                                            <div class="card-body text-center">
                                                <i class="fas fa-chart-line fa-2x text-warning mb-2"></i>
                                                <h6>Commission Summary</h6>
                                                <button type="button" onclick="generateCommission('commission_summary')" class="btn btn-warning btn-sm">Generate</button>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Other reports (go to generate_report.php) -->
                                    <div class="col-md-4">
                                        <div class="card border-danger">
                                            <div class="card-body text-center">
                                                <i class="fas fa-book fa-2x text-danger mb-2"></i>
                                                <h6>General Ledger</h6>
                                                <button type="button" onclick="generateReport('general_ledger')" class="btn btn-danger btn-sm">Generate</button>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="card border-info">
                                            <div class="card-body text-center">
                                                <i class="fas fa-file-alt fa-2x text-info mb-2"></i>
                                                <h6>Order Forms</h6>
                                                <button type="button" onclick="generateReport('order_forms')" class="btn btn-info btn-sm">Generate</button>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="card border-success">
                                            <div class="card-body text-center">
                                                <i class="fas fa-chart-pie fa-2x text-success mb-2"></i>
                                                <h6>Asset Class Summary</h6>
                                                <button type="button" onclick="generateReport('asset_class_summary')" class="btn btn-success btn-sm">Generate</button>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="card border-primary">
                                            <div class="card-body text-center">
                                                <i class="fas fa-balance-scale fa-2x text-primary mb-2"></i>
                                                <h6>Portfolio Analysis</h6>
                                                <button type="button" onclick="generateReport('portfolio_analysis')" class="btn btn-primary btn-sm">Generate</button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Action Buttons -->
                        <div class="row mt-4">
                            <div class="col-12">
                                <div class="d-flex gap-2 justify-content-center flex-wrap">
                                    <button type="button" class="btn btn-outline-secondary" onclick="clearFilters()">
                                        <i class="fas fa-eraser me-1"></i>Clear
                                    </button>
                                    <button type="button" class="btn btn-outline-primary" onclick="resetFilters()">
                                        <i class="fas fa-undo me-1"></i>Reset
                                    </button>
                                    <button type="button" class="btn btn-outline-success" onclick="saveFilters()">
                                        <i class="fas fa-save me-1"></i>Save
                                    </button>
                                    <button type="button" class="btn btn-outline-info" onclick="printReport()">
                                        <i class="fas fa-print me-1"></i>Print
                                    </button>
                                    <button type="button" class="btn btn-outline-warning" onclick="exportPDF()">
                                        <i class="fas fa-file-pdf me-1"></i>PDF
                                    </button>
                                    <button type="button" class="btn btn-outline-success" onclick="exportExcel()">
                                        <i class="fas fa-file-excel me-1"></i>Excel
                                    </button>
                                    <button type="button" class="btn btn-outline-danger" onclick="window.location.href='dashboard.php'">
                                        <i class="fas fa-times me-1"></i>Close
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Include Select2 CSS -->
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
<style>
.select2-container--default .select2-selection--single {
    height: 31px;
    padding: 2px 8px;
    border: 1px solid #ced4da;
    border-radius: 0.375rem;
}
.select2-container--default .select2-selection--single .select2-selection__arrow {
    height: 29px;
}
.select2-container--default .select2-selection--single .select2-selection__rendered {
    line-height: 27px;
    font-size: 0.875rem;
}
.select2-container .select2-selection--single {
    height: 31px;
}
.card {
    transition: transform 0.2s ease-in-out;
}
.card:hover {
    transform: translateY(-2px);
}
.border-purple {
    border-color: #6f42c1 !important;
}
.btn-purple {
    background-color: #6f42c1;
    border-color: #6f42c1;
    color: white;
}
.btn-purple:hover {
    background-color: #5a32a3;
    border-color: #5a32a3;
    color: white;
}
.text-purple {
    color: #6f42c1 !important;
}
</style>

<!-- Include Select2 JS -->
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<script>
// Initialize Select2 for Client and Security dropdowns
$(document).ready(function() {
    $('.select2-search').select2({
        placeholder: "Search...",
        allowClear: true,
        width: '100%'
    });
});

// Function to get current filter values
function getFilterValues() {
    return {
        client: document.getElementById('client').value,
        agent: document.getElementById('agent').value,
        broker: document.getElementById('broker').value,
        security: document.getElementById('security').value,
        asset_class: document.getElementById('asset_class').value,
        trade_type: document.getElementById('trade_type').value,
        user: document.getElementById('user').value,
        status: document.getElementById('status').value,
        period_from: document.getElementById('period_from').value,
        period_to: document.getElementById('period_to').value,
        report_by: document.getElementById('report_by').value,
        report_type: document.querySelector('input[name="report_type"]:checked').value,
        orientation: document.querySelector('input[name="orientation"]:checked').value,
        watermark: document.querySelector('input[name="watermark"]:checked').value
    };
}

// Function to build query string from filters
function buildQueryString(filters, additionalParams = {}) {
    const params = new URLSearchParams();
    
    // Add all filter parameters
    for (const [key, value] of Object.entries(filters)) {
        if (value && value !== '') {
            params.append(key, value);
        }
    }
    
    // Add additional parameters
    for (const [key, value] of Object.entries(additionalParams)) {
        params.append(key, value);
    }
    
    return params.toString();
}

// DIRECT PDF Generation for Contract Notes
function generateContractNote(reportName) {
    // Show options dialog
    const grouping = prompt('Contract Note Grouping:\n1. Individual (separate page for each trade)\n2. Summary (grouped by client/security/date)\n\nEnter 1 or 2:', '1');
    
    if (grouping === null) return; // User cancelled
    
    const contractGrouping = grouping === '2' ? 'summary' : 'individual';
    
    const watermark = confirm('Include watermark on PDF?\n\nClick OK for Yes, Cancel for No');
    const watermarkValue = watermark ? 'yes' : 'no';
    
    // Get filter values
    const filters = getFilterValues();
    
    // Build query string
    const queryString = buildQueryString(filters, {
        contract_grouping: contractGrouping,
        watermark: watermarkValue,
        report_type: filters.report_type
    });
    
    // Show loading message
    showToast(`Generating ${contractGrouping === 'summary' ? 'Summary' : 'Individual'} Contract Notes...`, 'info');
    
    // Directly open the PDF in a new tab
    setTimeout(() => {
        window.open('contract_note_pdf.php?' + queryString, '_blank');
    }, 500);
}

// Function to populate hidden form fields for statutory deductions reports
function populateStatutoryForm(reportName, additionalParams = {}) {
    const filters = getFilterValues();
    const form = document.getElementById('statutoryDeductionsForm');
    
    // Populate all filter fields
    for (const [key, value] of Object.entries(filters)) {
        const field = form.querySelector(`[name="${key}"]`);
        if (field) field.value = value;
    }
    
    // Set report name
    form.querySelector('[name="report_name"]').value = reportName;
    
    // Add any additional parameters
    for (const [key, value] of Object.entries(additionalParams)) {
        const field = form.querySelector(`[name="${key}"]`);
        if (field) {
            field.value = value;
        } else {
            // Create hidden field if it doesn't exist
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = key;
            input.value = value;
            form.appendChild(input);
        }
    }
    
    return form;
}

// Function to populate hidden form fields for share reports
function populateShareForm(reportName, additionalParams = {}) {
    const filters = getFilterValues();
    const form = document.getElementById('shareReportForm');
    
    // Populate all filter fields
    for (const [key, value] of Object.entries(filters)) {
        const field = form.querySelector(`[name="${key}"]`);
        if (field) field.value = value;
    }
    
    // Set report name
    form.querySelector('[name="report_name"]').value = reportName;
    
    // Add any additional parameters
    for (const [key, value] of Object.entries(additionalParams)) {
        const field = form.querySelector(`[name="${key}"]`);
        if (field) {
            field.value = value;
        } else {
            // Create hidden field if it doesn't exist
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = key;
            input.value = value;
            form.appendChild(input);
        }
    }
    
    return form;
}

// Function to populate hidden form fields for bonds reports
function populateBondsForm(reportName, additionalParams = {}) {
    const filters = getFilterValues();
    const form = document.getElementById('bondsReportForm');
    
    // Populate all filter fields
    for (const [key, value] of Object.entries(filters)) {
        const field = form.querySelector(`[name="${key}"]`);
        if (field) field.value = value;
    }
    
    // Set report name
    form.querySelector('[name="report_name"]').value = reportName;
    
    // Add any additional parameters
    for (const [key, value] of Object.entries(additionalParams)) {
        const field = form.querySelector(`[name="${key}"]`);
        if (field) {
            field.value = value;
        } else {
            // Create hidden field if it doesn't exist
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = key;
            input.value = value;
            form.appendChild(input);
        }
    }
    
    return form;
}

// Function to populate hidden form fields for general reports
function populateForm(formId, reportName, additionalParams = {}) {
    const filters = getFilterValues();
    const form = document.getElementById(formId);
    
    // Populate all filter fields
    for (const [key, value] of Object.entries(filters)) {
        const field = form.querySelector(`[name="${key}"]`);
        if (field) field.value = value;
    }
    
    // Set report name
    form.querySelector('[name="report_name"]').value = reportName;
    
    // Add any additional parameters
    for (const [key, value] of Object.entries(additionalParams)) {
        const field = form.querySelector(`[name="${key}"]`);
        if (field) {
            field.value = value;
        } else {
            // Create hidden field if it doesn't exist
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = key;
            input.value = value;
            form.appendChild(input);
        }
    }
    
    return form;
}

// Report generation functions
function generateStatutoryReport(reportName) {
    const form = populateStatutoryForm(reportName);
    showToast(`Generating ${getReportDisplayName(reportName)}...`, 'info');
    form.submit();
}

function generateReport(reportName) {
    const form = populateForm('generateReportForm', reportName);
    showToast(`Generating ${getReportDisplayName(reportName)}...`, 'info');
    form.submit();
}

function generateShareReport(reportName) {
    const form = populateShareForm(reportName);
    showToast(`Generating ${getReportDisplayName(reportName)}...`, 'info');
    form.submit();
}

function generateBondsReport(reportName) {
    const form = populateBondsForm(reportName);
    showToast(`Generating ${getReportDisplayName(reportName)}...`, 'info');
    form.submit();
}

function generateCommission(reportName) {
    const form = populateForm('commissionForm', reportName);
    showToast(`Generating ${getReportDisplayName(reportName)}...`, 'info');
    form.submit();
}

function clearFilters() {
    document.getElementById('filterForm').reset();
    $('.select2-search').val(null).trigger('change');
    showToast('Filters cleared successfully', 'success');
}

function resetFilters() {
    document.getElementById('period_from').value = '<?= date('Y-01-01') ?>';
    document.getElementById('period_to').value = '<?= date('Y-m-d') ?>';
    document.getElementById('detailed').checked = true;
    document.getElementById('landscape').checked = true;
    document.getElementById('no_watermark').checked = true;
    $('.select2-search').val(null).trigger('change');
    showToast('Filters reset to default values', 'info');
}

function saveFilters() {
    const filters = getFilterValues();
    localStorage.setItem('reportFilters', JSON.stringify(filters));
    showToast('Filter settings saved successfully!', 'success');
}

function printReport() {
    const reportName = prompt('Please enter the report name to print:');
    if (!reportName) return;
    
    showToast('Generating report for printing...', 'info');
    
    // Determine which form to use based on report type
    let form;
    
    if (reportName === 'bonds_statutory' || reportName === 'equities_statutory' || reportName === 'all_statutory') {
        form = populateStatutoryForm(reportName, { print_mode: '1' });
    } else if (reportName === 'bonds_edit_list' || reportName === 'bonds_summary') {
        form = populateBondsForm(reportName, { print_mode: '1' });
    } else if (reportName === 'contract_notes') {
        // For contract notes, use direct PDF generation with print parameters
        const filters = getFilterValues();
        const queryString = buildQueryString(filters, {
            contract_grouping: 'individual',
            watermark: 'no',
            print_mode: '1'
        });
        window.open('contract_note_pdf.php?' + queryString, '_blank');
        showToast('Opening Contract Notes for printing...', 'info');
        return;
    } else if (reportName === 'commission_summary') {
        form = populateForm('commissionForm', reportName, { print_mode: '1' });
    } else if (reportName === 'transaction_summary' || reportName === 'broker_summary') {
        form = populateShareForm(reportName, { print_mode: '1' });
    } else {
        form = populateForm('generateReportForm', reportName, { print_mode: '1' });
    }
    
    const printWindow = window.open('', '_blank');
    printWindow.document.write(`
        <html>
        <head>
            <title>Report Print Preview</title>
            <style>
                body { font-family: Arial, sans-serif; margin: 20px; }
                .header { text-align: center; margin-bottom: 30px; }
                .disclaimer { font-size: 10px; margin: 20px 0; }
                table { width: 100%; border-collapse: collapse; margin: 20px 0; }
                th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
                th { background-color: #f2f2f2; }
                .text-right { text-align: right; }
                @media print {
                    .no-print { display: none; }
                    body { margin: 0; }
                    .header { margin-bottom: 15px; }
                }
            </style>
        </head>
        <body>
            <div class="no-print" style="margin-bottom: 20px;">
                <button onclick="window.print()" style="padding: 10px 20px; background: #007bff; color: white; border: none; border-radius: 5px; cursor: pointer;">
                    Print Report
                </button>
                <button onclick="window.close()" style="padding: 10px 20px; background: #dc3545; color: white; border: none; border-radius: 5px; cursor: pointer; margin-left: 10px;">
                    Close
                </button>
            </div>
            <div id="reportContent">
                <div style="text-align: center; padding: 50px;">
                    <h3>Generating Report...</h3>
                    <p>Please wait while the report is being generated.</p>
                </div>
            </div>
        </body>
        </html>
    `);
    
    // Create a temporary form for the print request
    const tempForm = document.createElement('form');
    tempForm.method = 'POST';
    tempForm.action = form.action;
    tempForm.target = printWindow.name;
    
    for (let element of form.elements) {
        if (element.name) {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = element.name;
            input.value = element.value;
            tempForm.appendChild(input);
        }
    }
    
    document.body.appendChild(tempForm);
    tempForm.submit();
    
    setTimeout(() => {
        document.body.removeChild(tempForm);
        showToast('Report generated successfully', 'success');
    }, 2000);
}

function exportPDF() {
    const reportName = prompt('Please enter the report name to export as PDF:');
    if (!reportName) return;
    
    // Use direct generation for contract notes
    if (reportName === 'contract_notes') {
        generateContractNote(reportName);
        return;
    }
    
    showToast('Generating PDF export...', 'info');
    
    // Determine which form to use based on report type
    let form;
    
    if (reportName === 'bonds_statutory' || reportName === 'equities_statutory' || reportName === 'all_statutory') {
        form = populateStatutoryForm(reportName, { export_type: 'pdf' });
    } else if (reportName === 'bonds_edit_list' || reportName === 'bonds_summary') {
        form = populateBondsForm(reportName, { export_type: 'pdf' });
    } else if (reportName === 'commission_summary') {
        form = populateForm('commissionForm', reportName, { export_type: 'pdf' });
    } else if (reportName === 'transaction_summary' || reportName === 'broker_summary') {
        form = populateShareForm(reportName, { export_type: 'pdf' });
    } else {
        form = populateForm('generateReportForm', reportName, { export_type: 'pdf' });
    }
    
    const iframe = document.createElement('iframe');
    iframe.style.display = 'none';
    document.body.appendChild(iframe);
    
    form.target = iframe.name;
    document.body.appendChild(form);
    form.submit();
    
    setTimeout(() => {
        document.body.removeChild(form);
        document.body.removeChild(iframe);
        showToast('PDF export completed', 'success');
    }, 2000);
}

function exportExcel() {
    const reportName = prompt('Please enter the report name to export as Excel:');
    if (!reportName) return;
    
    showToast('Generating Excel export...', 'info');
    
    // Determine which form to use based on report type
    let form;
    
    if (reportName === 'bonds_statutory' || reportName === 'equities_statutory' || reportName === 'all_statutory') {
        form = populateStatutoryForm(reportName, { export_type: 'excel' });
    } else if (reportName === 'bonds_edit_list' || reportName === 'bonds_summary') {
        form = populateBondsForm(reportName, { export_type: 'excel' });
    } else if (reportName === 'contract_notes') {
        // For contract notes, show message that Excel export is not available
        showToast('Excel export not available for Contract Notes. Use PDF export instead.', 'warning');
        return;
    } else if (reportName === 'commission_summary') {
        form = populateForm('commissionForm', reportName, { export_type: 'excel' });
    } else if (reportName === 'transaction_summary' || reportName === 'broker_summary') {
        form = populateShareForm(reportName, { export_type: 'excel' });
    } else {
        form = populateForm('generateReportForm', reportName, { export_type: 'excel' });
    }
    
    const iframe = document.createElement('iframe');
    iframe.style.display = 'none';
    document.body.appendChild(iframe);
    
    form.target = iframe.name;
    document.body.appendChild(form);
    form.submit();
    
    setTimeout(() => {
        document.body.removeChild(form);
        document.body.removeChild(iframe);
        showToast('Excel export completed', 'success');
    }, 2000);
}

// Toast notification function
function showToast(message, type = 'info') {
    const toast = document.createElement('div');
    toast.style.cssText = `
        position: fixed;
        top: 20px;
        right: 20px;
        padding: 12px 20px;
        border-radius: 5px;
        color: white;
        font-weight: 500;
        z-index: 10000;
        animation: slideIn 0.3s ease;
    `;
    
    const colors = {
        success: '#28a745',
        error: '#dc3545',
        warning: '#ffc107',
        info: '#17a2b8',
        purple: '#6f42c1'
    };
    
    toast.style.backgroundColor = colors[type] || colors.info;
    toast.textContent = message;
    document.body.appendChild(toast);
    
    setTimeout(() => {
        toast.style.animation = 'slideOut 0.3s ease';
        setTimeout(() => document.body.removeChild(toast), 300);
    }, 3000);
}

// Add CSS for animations
const style = document.createElement('style');
style.textContent = `
    @keyframes slideIn {
        from { transform: translateX(100%); opacity: 0; }
        to { transform: translateX(0); opacity: 1; }
    }
    @keyframes slideOut {
        from { transform: translateX(0); opacity: 1; }
        to { transform: translateX(100%); opacity: 0; }
    }
`;
document.head.appendChild(style);

// Load saved filters
document.addEventListener('DOMContentLoaded', function() {
    const savedFilters = localStorage.getItem('reportFilters');
    if (savedFilters) {
        const filters = JSON.parse(savedFilters);
        for (const [key, value] of Object.entries(filters)) {
            const element = document.querySelector(`[name="${key}"]`);
            if (element) {
                if (element.type === 'radio') {
                    if (element.value === value) element.checked = true;
                } else {
                    element.value = value;
                }
            }
        }
        setTimeout(() => $('.select2-search').trigger('change'), 100);
        showToast('Saved filters loaded successfully', 'success');
    }
});

// Form validation
document.getElementById('period_from').addEventListener('change', function() {
    const fromDate = new Date(this.value);
    const toDate = new Date(document.getElementById('period_to').value);
    if (fromDate > toDate) {
        showToast('Period From date cannot be after Period To date', 'warning');
        this.value = document.getElementById('period_to').value;
    }
});

document.getElementById('period_to').addEventListener('change', function() {
    const fromDate = new Date(document.getElementById('period_from').value);
    const toDate = new Date(this.value);
    if (toDate < fromDate) {
        showToast('Period To date cannot be before Period From date', 'warning');
        this.value = document.getElementById('period_from').value;
    }
});

function getReportDisplayName(reportValue) {
    const reportNames = {
        'bonds_statutory': 'Bonds Statutory Deductions',
        'equities_statutory': 'Equities Statutory Deductions',
        'all_statutory': 'All Statutory Deductions',
        'bonds_edit_list': 'Bonds Transactions Edit List',
        'bonds_summary': 'Bonds Transactions Summary',
        'contract_notes': 'Contract Notes',
        'commission_summary': 'Commission Summary',
        'transaction_summary': 'Transaction Summary',
        'broker_summary': 'Broker Summary',
        'general_ledger': 'General Ledger',
        'order_forms': 'Order Forms',
        'asset_class_summary': 'Asset Class Summary',
        'portfolio_analysis': 'Portfolio Analysis'
    };
    return reportNames[reportValue] || 'Report';
}
</script>

<?php include '../includes/footer.php'; ?>