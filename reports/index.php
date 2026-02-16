<?php
require_once '../config/config.php';
require_once '../auth/auth_middleware.php';

require_login();

$db = getDBConnection();

// Get clients
$stmt = $db->query("SELECT DISTINCT client_name, client_cds_account FROM trades ORDER BY client_name");
$clients = $stmt->fetchAll();

// Get brokers
$stmt = $db->query("SELECT DISTINCT broker_name FROM trades ORDER BY broker_name");
$brokers = $stmt->fetchAll();

// Get securities
$stmt = $db->query("SELECT DISTINCT security_id, security_name FROM trades ORDER BY security_name");
$securities = $stmt->fetchAll();

// Get users
$stmt = $db->query("SELECT DISTINCT full_name FROM users WHERE is_active = 1 ORDER BY full_name");
$users = $stmt->fetchAll();

$page_title = 'Advanced Reports & Analytics';
include '../includes/header.php';
?>

<!-- Professional reports header with advanced search focus -->
<div class="page-header">
    <div class="container-fluid">
        <div class="row align-items-center">
            <div class="col-md-8">
                <div class="d-flex align-items-center">
                    <div class="me-3">
                        <div class="d-inline-flex align-items-center justify-content-center rounded-circle shadow-sm" 
                             style="width: 60px; height: 60px; background: linear-gradient(135deg, var(--info-color) 0%, #0ea5e9 100%);">
                            <i class="bi bi-graph-up" style="font-size: 1.5rem;"></i>
                        </div>
                    </div>
                    <div>
                        <h1 class="page-title mb-1">Advanced Reports & Analytics</h1>
                        <p class="page-subtitle">Comprehensive financial reporting with advanced filtering and export capabilities</p>
                    </div>
                </div>
            </div>
            <div class="col-md-4 text-end">
                <button type="button" class="btn btn-primary btn-lg" onclick="generateReport()">
                    <i class="bi bi-file-earmark-bar-graph me-2"></i>
                    Generate Report
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Advanced search and filter interface matching the screenshot -->
<div class="container-fluid">
    <div class="row">
        <div class="col-12">
            <div class="card dashboard-card">
                <div class="card-header bg-transparent border-0 pb-0">
                    <div class="d-flex align-items-center">
                        <div class="me-2">
                            <i class="bi bi-funnel text-primary"></i>
                        </div>
                        <h6 class="mb-0 fw-semibold">Report Filters & Parameters</h6>
                    </div>
                </div>
                <div class="card-body">
                    <form id="reportForm" method="POST" action="generate_report">
                        <div class="row g-3">
                            <!-- Client Filter -->
                            <div class="col-md-4">
                                <label for="client" class="form-label fw-semibold">Client :</label>
                                <select class="form-select" id="client" name="client">
                                    <option value="">Select Client</option>
                                    <?php foreach ($clients as $client): ?>
                                        <option value="<?php echo htmlspecialchars($client['client_cds_account']); ?>">
                                            <?php echo htmlspecialchars($client['client_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <!-- Agent Filter -->
                            <div class="col-md-4">
                                <label for="agent" class="form-label fw-semibold">Agent :</label>
                                <select class="form-select" id="agent" name="agent">
                                    <option value="">Select Agent</option>
                                    <?php foreach ($users as $user): ?>
                                        <option value="<?php echo htmlspecialchars($user['full_name']); ?>">
                                            <?php echo htmlspecialchars($user['full_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <!-- Broker Filter -->
                            <div class="col-md-4">
                                <label for="broker" class="form-label fw-semibold">Broker :</label>
                                <select class="form-select" id="broker" name="broker">
                                    <option value="">ARCH FINANCIAL INVEST</option>
                                    <?php foreach ($brokers as $broker): ?>
                                        <option value="<?php echo htmlspecialchars($broker['broker_name']); ?>">
                                            <?php echo htmlspecialchars($broker['broker_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <!-- Security Filter -->
                            <div class="col-md-4">
                                <label for="security" class="form-label fw-semibold">Security :</label>
                                <select class="form-select" id="security" name="security">
                                    <option value="">CRDB BANK PUBLIC LIMT</option>
                                    <?php foreach ($securities as $security): ?>
                                        <option value="<?php echo htmlspecialchars($security['security_id']); ?>">
                                            <?php echo htmlspecialchars($security['security_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <!-- Type Filter -->
                            <div class="col-md-4">
                                <label for="type" class="form-label fw-semibold">Type :</label>
                                <select class="form-select" id="type" name="type">
                                    <option value="">BUY</option>
                                    <option value="Buy">BUY</option>
                                    <option value="Sell">SELL</option>
                                </select>
                            </div>

                            <!-- User Filter -->
                            <div class="col-md-4">
                                <label for="user" class="form-label fw-semibold">User :</label>
                                <select class="form-select" id="user" name="user">
                                    <option value="">ALL</option>
                                    <?php foreach ($users as $user): ?>
                                        <option value="<?php echo htmlspecialchars($user['full_name']); ?>">
                                            <?php echo htmlspecialchars($user['full_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <!-- Status Filter -->
                            <div class="col-md-4">
                                <label for="status" class="form-label fw-semibold">Status :</label>
                                <select class="form-select" id="status" name="status">
                                    <option value="">ALL</option>
                                    <option value="active">ACTIVE</option>
                                    <option value="cancelled">CANCELLED</option>
                                    <option value="settled">SETTLED</option>
                                </select>
                            </div>

                            <!-- Order Type Filter -->
                            <div class="col-md-4">
                                <label for="order_type" class="form-label fw-semibold">Order Type :</label>
                                <select class="form-select" id="order_type" name="order_type">
                                    <option value="">LIMIT</option>
                                    <option value="LIMIT">LIMIT</option>
                                    <option value="MARKET">MARKET</option>
                                    <option value="STOP">STOP</option>
                                </select>
                            </div>

                            <!-- Order From -->
                            <div class="col-md-4">
                                <label for="order_from" class="form-label fw-semibold">Order From :</label>
                                <select class="form-select" id="order_from" name="order_from">
                                    <option value="">000002</option>
                                    <option value="000001">000001</option>
                                    <option value="000002">000002</option>
                                    <option value="000003">000003</option>
                                </select>
                            </div>

                            <!-- Order To -->
                            <div class="col-md-4">
                                <label for="order_to" class="form-label fw-semibold">Order To :</label>
                                <select class="form-select" id="order_to" name="order_to">
                                    <option value="">000002</option>
                                    <option value="000001">000001</option>
                                    <option value="000002">000002</option>
                                    <option value="000003">000003</option>
                                </select>
                            </div>

                            <!-- Contract From -->
                            <div class="col-md-4">
                                <label for="contract_from" class="form-label fw-semibold">Contract From :</label>
                                <select class="form-select" id="contract_from" name="contract_from">
                                    <option value="">000011</option>
                                    <option value="000010">000010</option>
                                    <option value="000011">000011</option>
                                    <option value="000012">000012</option>
                                </select>
                            </div>

                            <!-- Contract To -->
                            <div class="col-md-4">
                                <label for="contract_to" class="form-label fw-semibold">Contract To :</label>
                                <select class="form-select" id="contract_to" name="contract_to">
                                    <option value="">ALL</option>
                                    <option value="000010">000010</option>
                                    <option value="000011">000011</option>
                                    <option value="000012">000012</option>
                                </select>
                            </div>

                            <!-- Period From -->
                            <div class="col-md-6">
                                <label for="period_from" class="form-label fw-semibold">Period From :</label>
                                <input type="date" class="form-control" id="period_from" name="period_from" value="2025-01-01">
                            </div>

                            <!-- Period To -->
                            <div class="col-md-6">
                                <label for="period_to" class="form-label fw-semibold">Period To :</label>
                                <input type="date" class="form-control" id="period_to" name="period_to" value="2025-08-21">
                            </div>

                            <!-- Contract Type Radio Buttons -->
                            <div class="col-md-12">
                                <label class="form-label fw-semibold">Contract :</label>
                                <div class="d-flex gap-4">
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" name="contract_type" id="contract_client" value="client" checked>
                                        <label class="form-check-label" for="contract_client">Client</label>
                                    </div>
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" name="contract_type" id="contract_agent" value="agent">
                                        <label class="form-check-label" for="contract_agent">Agent</label>
                                    </div>
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" name="contract_type" id="contract_broker" value="broker">
                                        <label class="form-check-label" for="contract_broker">Broker</label>
                                    </div>
                                </div>
                            </div>

                            <!-- Report By -->
                            <div class="col-md-4">
                                <label for="report_by" class="form-label fw-semibold">Report By :</label>
                                <select class="form-select" id="report_by" name="report_by">
                                    <option value="Month">Month</option>
                                    <option value="Day">Day</option>
                                    <option value="Week">Week</option>
                                    <option value="Quarter">Quarter</option>
                                    <option value="Year">Year</option>
                                </select>
                            </div>

                            <!-- Report Type Radio Buttons -->
                            <div class="col-md-4">
                                <label class="form-label fw-semibold">Report Type :</label>
                                <div class="d-flex gap-4">
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" name="report_type" id="report_detailed" value="detailed" checked>
                                        <label class="form-check-label" for="report_detailed">Detailed</label>
                                    </div>
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" name="report_type" id="report_summary" value="summary">
                                        <label class="form-check-label" for="report_summary">Summary</label>
                                    </div>
                                </div>
                            </div>

                            <!-- Orientation Radio Buttons -->
                            <div class="col-md-4">
                                <label class="form-label fw-semibold">Orientation :</label>
                                <div class="d-flex gap-4">
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" name="orientation" id="orientation_portrait" value="portrait">
                                        <label class="form-check-label" for="orientation_portrait">Portrait</label>
                                    </div>
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" name="orientation" id="orientation_landscape" value="landscape" checked>
                                        <label class="form-check-label" for="orientation_landscape">Landscape</label>
                                    </div>
                                </div>
                            </div>

                            <!-- WaterMark Radio Buttons -->
                            <div class="col-md-12">
                                <label class="form-label fw-semibold">WaterMark :</label>
                                <div class="d-flex gap-4">
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" name="watermark" id="watermark_no" value="no" checked>
                                        <label class="form-check-label" for="watermark_no">No</label>
                                    </div>
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" name="watermark" id="watermark_yes" value="yes">
                                        <label class="form-check-label" for="watermark_yes">Yes</label>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Action Buttons -->
                        <div class="row mt-4">
                            <div class="col-12">
                                <div class="d-flex gap-2 justify-content-center">
                                    <button type="button" class="btn btn-outline-secondary" onclick="clearForm()">Clear</button>
                                    <button type="reset" class="btn btn-outline-secondary">Reset</button>
                                    <button type="button" class="btn btn-outline-primary" onclick="saveFilters()">Save</button>
                                    <button type="button" class="btn btn-primary" onclick="printReport()">Print</button>
                                    <button type="button" class="btn btn-success" onclick="exportExcel()">Excel</button>
                                    <button type="button" class="btn btn-outline-danger" onclick="closeReports()">Close</button>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Results area for generated reports -->
    <div class="row mt-4" id="reportResults" style="display: none;">
        <div class="col-12">
            <div class="card dashboard-card">
                <div class="card-header bg-transparent border-0 pb-0">
                    <div class="d-flex justify-content-between align-items-center">
                        <div class="d-flex align-items-center">
                            <div class="me-2">
                                <i class="bi bi-file-earmark-bar-graph text-primary"></i>
                            </div>
                            <h6 class="mb-0 fw-semibold">Generated Report</h6>
                        </div>
                        <div class="dropdown">
                            <button class="btn btn-outline-secondary btn-sm dropdown-toggle" type="button" data-bs-toggle="dropdown">
                                <i class="bi bi-three-dots"></i>
                            </button>
                            <ul class="dropdown-menu">
                                <li><a class="dropdown-item" href="#" onclick="downloadPDF()"><i class="bi bi-file-pdf me-2"></i>Download PDF</a></li>
                                <li><a class="dropdown-item" href="#" onclick="exportExcel()"><i class="bi bi-file-excel me-2"></i>Export Excel</a></li>
                                <li><a class="dropdown-item" href="#" onclick="printReport()"><i class="bi bi-printer me-2"></i>Print Report</a></li>
                            </ul>
                        </div>
                    </div>
                </div>
                <div class="card-body" id="reportContent">
                    <!-- Report content will be loaded here -->
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function clearForm() {
    document.getElementById('reportForm').reset();
    document.getElementById('reportResults').style.display = 'none';
}

function saveFilters() {
    // Save current filter settings to localStorage
    const formData = new FormData(document.getElementById('reportForm'));
    const filters = {};
    for (let [key, value] of formData.entries()) {
        filters[key] = value;
    }
    localStorage.setItem('reportFilters', JSON.stringify(filters));
    alert('Filter settings saved successfully!');
}

function generateReport() {
    const form = document.getElementById('reportForm');
    const formData = new FormData(form);
    
    // Show loading state
    document.getElementById('reportResults').style.display = 'block';
    document.getElementById('reportContent').innerHTML = '<div class="text-center py-5"><div class="spinner-border text-primary" role="status"><span class="visually-hidden">Loading...</span></div><p class="mt-3">Generating report...</p></div>';
    
    // Submit form via AJAX
    fetch('generate_report.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.text())
    .then(data => {
        document.getElementById('reportContent').innerHTML = data;
    })
    .catch(error => {
        document.getElementById('reportContent').innerHTML = '<div class="alert alert-danger">Error generating report: ' + error.message + '</div>';
    });
}

function printReport() {
    if (document.getElementById('reportResults').style.display !== 'none') {
        window.print();
    } else {
        generateReport();
        setTimeout(() => window.print(), 2000);
    }
}

function exportExcel() {
    const form = document.getElementById('reportForm');
    const formData = new FormData(form);
    formData.append('export', 'excel');
    
    const link = document.createElement('a');
    link.href = 'generate_report.php?' + new URLSearchParams(formData).toString();
    link.download = 'report.xlsx';
    link.click();
}

function downloadPDF() {
    const form = document.getElementById('reportForm');
    const formData = new FormData(form);
    formData.append('export', 'pdf');
    
    const link = document.createElement('a');
    link.href = 'generate_report.php?' + new URLSearchParams(formData).toString();
    link.download = 'report.pdf';
    link.click();
}

function closeReports() {
    window.location.href = '../trader/dashboard';
}

// Load saved filters on page load
document.addEventListener('DOMContentLoaded', function() {
    const savedFilters = localStorage.getItem('reportFilters');
    if (savedFilters) {
        const filters = JSON.parse(savedFilters);
        for (let [key, value] of Object.entries(filters)) {
            const element = document.querySelector(`[name="${key}"]`);
            if (element) {
                if (element.type === 'radio') {
                    document.querySelector(`[name="${key}"][value="${value}"]`).checked = true;
                } else {
                    element.value = value;
                }
            }
        }
    }
});
</script>

<?php include '../includes/footer.php'; ?>
