<?php
// balance_sheet_final_working.php
session_start();
require_once '../config/config.php';

// Check authentication
$user = get_logged_in_user();
if (!$user) {
    redirect('auth/login.php');
}

$allowed_roles = ['finance_officer', 'finance_manager', 'accountant', 'system_admin', 'ceo', 'admin'];
if (!in_array($user['role'], $allowed_roles)) {
    show_alert('You do not have permission to access the balance sheet.', 'danger');
    redirect('dashboard.php');
}

$db = getDBConnection();

// Get company details
$company_stmt = $db->prepare("
    SELECT 
        company_code,
        COALESCE(company_name, name) as company_name,
        address,
        phone,
        mobile,
        email,
        registration_number,
        currency,
        country
    FROM companies 
    WHERE status = 'active' OR is_active = 1
    ORDER BY id ASC 
    LIMIT 1
");
$company_stmt->execute();
$company = $company_stmt->fetch(PDO::FETCH_ASSOC) ?: [
    'company_name' => 'Neovam Technologies LTD',
    'address' => 'P.O Box, Dar es Salaam, Tanzania',
    'registration_number' => '',
    'currency' => 'TZS'
];

// ========== DRILL-DOWN FUNCTIONALITY ==========
if (isset($_GET['drilldown'])) {
    $account_code = $_GET['account_code'] ?? '';
    $account_name = $_GET['account_name'] ?? '';
    $period = $_GET['period'] ?? 'current';
    $filter_type = $_GET['filter_type'] ?? 'annual';
    $year = isset($_GET['year']) ? intval($_GET['year']) : date('Y');
    $month = isset($_GET['month']) ? intval($_GET['month']) : null;
    $quarter = isset($_GET['quarter']) ? intval($_GET['quarter']) : null;
    $start_date = $_GET['start_date'] ?? '';
    $end_date = $_GET['end_date'] ?? '';
    
    // If date range not provided, calculate it
    if (empty($start_date) || empty($end_date)) {
        $date_range = getDateRange($filter_type, $year, 
            $filter_type == 'monthly' ? $month : 
            ($filter_type == 'quarterly' ? $quarter : null));
        $start_date = $date_range['start'];
        $end_date = $date_range['end'];
    }
    
    // Get entries for this account
    $entries = [];
    $sub_accounts = [];
    
    if ($account_code === '111') {
        // Cash & Cash Equivalents - get all third level accounts
        $query = "
            SELECT 
                gl.id,
                gl.transaction_date,
                gl.description,
                gl.debit_amount,
                gl.credit_amount,
                gl.reference_no,
                gl.balance_type,
                coa.account_name,
                coa.account_code,
                coa.account_type,
                CONCAT(u.username) as created_by
            FROM general_ledger gl
            JOIN chart_of_accounts coa ON gl.account_id = coa.id
            LEFT JOIN users u ON gl.created_by = u.id
            WHERE gl.transaction_date BETWEEN ? AND ?
            AND gl.status = 'active'
            AND coa.account_code LIKE '111%'
            ORDER BY coa.account_code, gl.transaction_date DESC
        ";
        $stmt = $db->prepare($query);
        $stmt->execute([$start_date, $end_date]);
        $entries = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Group by sub-account for third level breakdown
        foreach ($entries as $entry) {
            $sub_code = substr($entry['account_code'], 0, 4);
            if (!isset($sub_accounts[$sub_code])) {
                $sub_accounts[$sub_code] = [
                    'name' => $entry['account_name'],
                    'debit' => 0,
                    'credit' => 0,
                    'entries' => []
                ];
            }
            $sub_accounts[$sub_code]['debit'] += $entry['debit_amount'];
            $sub_accounts[$sub_code]['credit'] += $entry['credit_amount'];
            $sub_accounts[$sub_code]['entries'][] = $entry;
        }
    } elseif ($account_code === '211') {
        // Trade Payables
        $query = "
            SELECT 
                gl.id,
                gl.transaction_date,
                gl.description,
                gl.debit_amount,
                gl.credit_amount,
                gl.reference_no,
                gl.balance_type,
                coa.account_name,
                coa.account_code,
                coa.account_type,
                CONCAT(u.username) as created_by,
                v.name as vendor_name
            FROM general_ledger gl
            JOIN chart_of_accounts coa ON gl.account_id = coa.id
            LEFT JOIN users u ON gl.created_by = u.id
            LEFT JOIN vendors v ON gl.vendor_id = v.id
            WHERE gl.transaction_date BETWEEN ? AND ?
            AND gl.status = 'active'
            AND coa.account_code LIKE '211%'
            ORDER BY gl.transaction_date DESC
        ";
        $stmt = $db->prepare($query);
        $stmt->execute([$start_date, $end_date]);
        $entries = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } elseif ($account_code === '213-215') {
        // Other Current Liabilities
        $query = "
            SELECT 
                gl.id,
                gl.transaction_date,
                gl.description,
                gl.debit_amount,
                gl.credit_amount,
                gl.reference_no,
                gl.balance_type,
                coa.account_name,
                coa.account_code,
                coa.account_type,
                CONCAT(u.username) as created_by
            FROM general_ledger gl
            JOIN chart_of_accounts coa ON gl.account_id = coa.id
            LEFT JOIN users u ON gl.created_by = u.id
            WHERE gl.transaction_date BETWEEN ? AND ?
            AND gl.status = 'active'
            AND (coa.account_code LIKE '213%' OR coa.account_code LIKE '214%' OR coa.account_code LIKE '215%')
            ORDER BY coa.account_code, gl.transaction_date DESC
        ";
        $stmt = $db->prepare($query);
        $stmt->execute([$start_date, $end_date]);
        $entries = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Group by account code
        foreach ($entries as $entry) {
            $main_code = substr($entry['account_code'], 0, 3);
            if (!isset($sub_accounts[$main_code])) {
                $sub_accounts[$main_code] = [
                    'name' => $entry['account_name'],
                    'debit' => 0,
                    'credit' => 0,
                    'entries' => []
                ];
            }
            $sub_accounts[$main_code]['debit'] += $entry['debit_amount'];
            $sub_accounts[$main_code]['credit'] += $entry['credit_amount'];
            $sub_accounts[$main_code]['entries'][] = $entry;
        }
    } else {
        // Other accounts
        $query = "
            SELECT 
                gl.id,
                gl.transaction_date,
                gl.description,
                gl.debit_amount,
                gl.credit_amount,
                gl.reference_no,
                gl.balance_type,
                coa.account_name,
                coa.account_code,
                coa.account_type,
                CONCAT(u.username) as created_by
            FROM general_ledger gl
            JOIN chart_of_accounts coa ON gl.account_id = coa.id
            LEFT JOIN users u ON gl.created_by = u.id
            WHERE gl.transaction_date BETWEEN ? AND ?
            AND gl.status = 'active'
            AND coa.account_code = ?
            ORDER BY gl.transaction_date DESC
        ";
        $stmt = $db->prepare($query);
        $stmt->execute([$start_date, $end_date, $account_code]);
        $entries = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // Calculate totals
    $total_debit = array_sum(array_column($entries, 'debit_amount'));
    $total_credit = array_sum(array_column($entries, 'credit_amount'));
    $net_balance = $total_debit - $total_credit;
    
    // Display drill-down page
    include '../includes/header.php';
    ?>
    
    <div class="container-fluid py-4">
        <div class="card">
            <div class="card-header bg-primary text-white">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h4 class="mb-0">
                            <i class="bi bi-arrow-down-circle me-2"></i>
                            Account Details: <?php echo htmlspecialchars($account_name); ?>
                        </h4>
                        <p class="mb-0">
                            Account Code: <?php echo htmlspecialchars($account_code); ?> | 
                            Period: <?php echo date('d/m/Y', strtotime($start_date)); ?> to <?php echo date('d/m/Y', strtotime($end_date)); ?>
                        </p>
                    </div>
                    <div>
                        <a href="balance_sheet.php?<?php echo http_build_query(array_diff_key($_GET, ['drilldown' => '', 'account_code' => '', 'account_name' => '', 'start_date' => '', 'end_date' => ''])); ?>" 
                           class="btn btn-light btn-sm">
                            <i class="bi bi-arrow-left me-1"></i>Back to Balance Sheet
                        </a>
                        
                    </div>
                </div>
            </div>
            
            <div class="card-body">
                <!-- Account Summary -->
                <div class="row mb-4">
                    <div class="col-md-4">
                        <div class="card border-primary">
                            <div class="card-body">
                                <h6 class="card-title">Account Summary</h6>
                                <div class="d-flex justify-content-between mb-2">
                                    <span>Total Debits:</span>
                                    <strong class="text-danger"><?php echo number_format($total_debit, 2); ?></strong>
                                </div>
                                <div class="d-flex justify-content-between mb-2">
                                    <span>Total Credits:</span>
                                    <strong class="text-success"><?php echo number_format($total_credit, 2); ?></strong>
                                </div>
                                <hr>
                                <div class="d-flex justify-content-between">
                                    <span>Net Balance:</span>
                                    <strong class="<?php echo $net_balance >= 0 ? 'text-primary' : 'text-warning'; ?>">
                                        <?php echo number_format(abs($net_balance), 2); ?>
                                        (<?php echo $net_balance >= 0 ? 'Debit' : 'Credit'; ?>)
                                    </strong>
                                </div>
                                <div class="d-flex justify-content-between mt-2">
                                    <span>Total Entries:</span>
                                    <strong><?php echo count($entries); ?></strong>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-8">
                        <div class="card">
                            <div class="card-body">
                                <h6 class="card-title">Account Information</h6>
                                <div class="row">
                                    <div class="col-md-6">
                                        <small class="text-muted">Account Name:</small>
                                        <p class="mb-2"><strong><?php echo htmlspecialchars($account_name); ?></strong></p>
                                        
                                        <small class="text-muted">Account Code:</small>
                                        <p class="mb-2"><strong><?php echo htmlspecialchars($account_code); ?></strong></p>
                                    </div>
                                    <div class="col-md-6">
                                        <small class="text-muted">Period Start:</small>
                                        <p class="mb-2"><?php echo date('d/m/Y', strtotime($start_date)); ?></p>
                                        
                                        <small class="text-muted">Period End:</small>
                                        <p class="mb-2"><?php echo date('d/m/Y', strtotime($end_date)); ?></p>
                                        
                                        <small class="text-muted">Report Date:</small>
                                        <p class="mb-0"><?php echo date('d/m/Y H:i:s'); ?></p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Third Level Breakdown (for cash and other liabilities) -->
                <?php if (!empty($sub_accounts)): ?>
                <div class="card mb-4">
                    <div class="card-header bg-info text-white">
                        <h5 class="mb-0">
                            <i class="bi bi-diagram-3 me-2"></i>
                            Third Level Breakdown
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-sm table-bordered table-hover">
                                <thead class="table-light">
                                    <tr>
                                        <th>Sub-Account Code</th>
                                        <th>Sub-Account Name</th>
                                        <th class="text-end">Debit Total</th>
                                        <th class="text-end">Credit Total</th>
                                        <th class="text-end">Net Balance</th>
                                        <th class="text-center">Entries</th>
                                        <th class="text-center">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($sub_accounts as $code => $data): 
                                        $sub_net = $data['debit'] - $data['credit'];
                                    ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($code); ?></strong></td>
                                        <td><?php echo htmlspecialchars($data['name']); ?></td>
                                        <td class="text-end text-danger"><?php echo number_format($data['debit'], 2); ?></td>
                                        <td class="text-end text-success"><?php echo number_format($data['credit'], 2); ?></td>
                                        <td class="text-end <?php echo $sub_net >= 0 ? 'text-primary' : 'text-warning'; ?>">
                                            <?php echo number_format(abs($sub_net), 2); ?>
                                            <small>(<?php echo $sub_net >= 0 ? 'Debit' : 'Credit'; ?>)</small>
                                        </td>
                                        <td class="text-center"><?php echo count($data['entries']); ?></td>
                                        <td class="text-center">
                                            <button class="btn btn-sm btn-outline-primary toggle-sub-entries" 
                                                    data-target="#sub-entries-<?php echo $code; ?>">
                                                <i class="bi bi-chevron-down"></i> View Entries
                                            </button>
                                        </td>
                                    </tr>
                                    <!-- Sub-entries (hidden by default) -->
                                    <tr id="sub-entries-<?php echo $code; ?>" class="sub-entries-row" style="display: none;">
                                        <td colspan="7">
                                            <div class="p-3 bg-light">
                                                <h6 class="mb-3">Detailed Entries for <?php echo htmlspecialchars($code); ?></h6>
                                                <div class="table-responsive">
                                                    <table class="table table-sm">
                                                        <thead>
                                                            <tr>
                                                                <th>Date</th>
                                                                <th>Description</th>
                                                                <th>Ref No.</th>
                                                                <th class="text-end">Debit</th>
                                                                <th class="text-end">Credit</th>
                                                                <th>Type</th>
                                                            </tr>
                                                        </thead>
                                                        <tbody>
                                                            <?php foreach ($data['entries'] as $sub_entry): ?>
                                                            <tr>
                                                                <td><?php echo date('d/m/Y', strtotime($sub_entry['transaction_date'])); ?></td>
                                                                <td><?php echo htmlspecialchars($sub_entry['description']); ?></td>
                                                                <td><?php echo htmlspecialchars($sub_entry['reference_no']); ?></td>
                                                                <td class="text-end <?php echo $sub_entry['debit_amount'] > 0 ? 'text-danger' : ''; ?>">
                                                                    <?php echo $sub_entry['debit_amount'] > 0 ? number_format($sub_entry['debit_amount'], 2) : '-'; ?>
                                                                </td>
                                                                <td class="text-end <?php echo $sub_entry['credit_amount'] > 0 ? 'text-success' : ''; ?>">
                                                                    <?php echo $sub_entry['credit_amount'] > 0 ? number_format($sub_entry['credit_amount'], 2) : '-'; ?>
                                                                </td>
                                                                <td><?php echo htmlspecialchars($sub_entry['balance_type']); ?></td>
                                                            </tr>
                                                            <?php endforeach; ?>
                                                        </tbody>
                                                    </table>
                                                </div> 
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                
                <!-- Detailed Transaction List -->
                <div class="card">
                    <div class="card-header">
                        <div class="d-flex justify-content-between align-items-center">
                            <h5 class="mb-0">Transaction Details</h5>
                            <span class="badge bg-primary"><?php echo count($entries); ?> entries</span>
                        </div>
                    </div>
                    <div class="card-body">
                        <?php if (empty($entries)): ?>
                            <div class="alert alert-info">
                                <i class="bi bi-info-circle me-2"></i>
                                No transactions found for this account in the selected period.
                            </div>
                        <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover table-bordered">
                                <thead class="table-light">
                                    <tr>
                                        <th>Date</th>
                                        <th>Description</th>
                                        <th>Reference</th>
                                        <th>Account Code</th>
                                        <th class="text-end">Debit</th>
                                        <th class="text-end">Credit</th>
                                        <th>Type</th>
                                        <th>Created By</th>
                                        <?php if ($account_code === '211'): ?>
                                        <th>Vendor</th>
                                        <?php endif; ?>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($entries as $entry): ?>
                                    <tr>
                                        <td><?php echo date('d/m/Y', strtotime($entry['transaction_date'])); ?></td>
                                        <td><?php echo htmlspecialchars($entry['description']); ?></td>
                                        <td><?php echo htmlspecialchars($entry['reference_no']); ?></td>
                                        <td>
                                            <span class="badge bg-secondary"><?php echo htmlspecialchars($entry['account_code']); ?></span>
                                        </td>
                                        <td class="text-end <?php echo $entry['debit_amount'] > 0 ? 'text-danger' : ''; ?>">
                                            <?php if ($entry['debit_amount'] > 0): ?>
                                            <i class="bi bi-arrow-up-circle text-danger me-1"></i>
                                            <?php echo number_format($entry['debit_amount'], 2); ?>
                                            <?php else: ?>
                                            -
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end <?php echo $entry['credit_amount'] > 0 ? 'text-success' : ''; ?>">
                                            <?php if ($entry['credit_amount'] > 0): ?>
                                            <i class="bi bi-arrow-down-circle text-success me-1"></i>
                                            <?php echo number_format($entry['credit_amount'], 2); ?>
                                            <?php else: ?>
                                            -
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="badge bg-info"><?php echo htmlspecialchars($entry['balance_type']); ?></span>
                                        </td>
                                        <td><?php echo htmlspecialchars($entry['created_by'] ?? 'System'); ?></td>
                                        <?php if ($account_code === '211'): ?>
                                        <td><?php echo htmlspecialchars($entry['vendor_name'] ?? '-'); ?></td>
                                        <?php endif; ?>
                                        <td>
                                            <a href="journal_entry_view.php?id=<?php echo $entry['id']; ?>" 
                                               class="btn btn-sm btn-outline-primary" 
                                               title="View Journal Entry"
                                               target="_blank">
                                                <i class="bi bi-journal-text"></i>
                                            </a>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                                <tfoot class="table-light">
                                    <tr>
                                        <td colspan="4" class="text-end"><strong>Totals:</strong></td>
                                        <td class="text-end">
                                            <strong class="text-danger"><?php echo number_format($total_debit, 2); ?></strong>
                                        </td>
                                        <td class="text-end">
                                            <strong class="text-success"><?php echo number_format($total_credit, 2); ?></strong>
                                        </td>
                                        <td colspan="<?php echo $account_code === '211' ? 4 : 3; ?>"></td>
                                    </tr>
                                    <tr class="table-primary">
                                        <td colspan="4" class="text-end"><strong>Net Balance:</strong></td>
                                        <td colspan="2" class="text-center">
                                            <strong class="<?php echo $net_balance >= 0 ? 'text-primary' : 'text-warning'; ?>">
                                                <?php echo number_format(abs($net_balance), 2); ?>
                                                (<?php echo $net_balance >= 0 ? 'Debit Balance' : 'Credit Balance'; ?>)
                                            </strong>
                                        </td>
                                        <td colspan="<?php echo $account_code === '211' ? 4 : 3; ?>"></td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                        
                        <!-- Export Options -->
                        <div class="mt-3 d-flex justify-content-between">
                           
                            <div>
                                <a href="export_account_details.php?<?php echo http_build_query($_GET); ?>&format=pdf" 
                                   class="btn btn-sm btn-outline-danger">
                                    <i class="bi bi-file-pdf me-1"></i>Export as PDF
                                </a>
                                <a href="export_account_details.php?<?php echo http_build_query($_GET); ?>&format=excel" 
                                   class="btn btn-sm btn-outline-success">
                                    <i class="bi bi-file-excel me-1"></i>Export as Excel
                                </a>
                                <a href="export_account_details.php?<?php echo http_build_query($_GET); ?>&format=csv" 
                                   class="btn btn-sm btn-outline-info">
                                    <i class="bi bi-file-text me-1"></i>Export as CSV
                                </a>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <style>
        .table th {
            background-color: #f8f9fa;
            font-weight: 600;
        }
        .clickable-account {
            color: #0d6efd;
            cursor: pointer;
            text-decoration: underline dotted;
        }
        .clickable-account:hover {
            color: #0a58ca;
            text-decoration: underline;
        }
        .sub-entries-row {
            background-color: #f8f9fa;
        }
        .badge {
            font-size: 0.75em;
        }
    </style>
    
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        // Toggle sub-entries
        const toggleButtons = document.querySelectorAll('.toggle-sub-entries');
        toggleButtons.forEach(button => {
            button.addEventListener('click', function() {
                const targetId = this.getAttribute('data-target');
                const target = document.querySelector(targetId);
                const icon = this.querySelector('i');
                
                if (target.style.display === 'none') {
                    target.style.display = 'table-row';
                    icon.className = 'bi bi-chevron-up';
                } else {
                    target.style.display = 'none';
                    icon.className = 'bi bi-chevron-down';
                }
            });
        });
        
        // Print button functionality
        const printButton = document.querySelector('[onclick="window.print()"]');
        if (printButton) {
            printButton.addEventListener('click', function() {
                window.print();
            });
        }
    });
    </script>
    
    <?php
    include '../includes/footer.php';
    exit;
}

// ========== EXPORT FUNCTIONALITY ==========
if (isset($_GET['export'])) {
    $export_type = $_GET['export'];
    
    // Get filter parameters for export
    $filter_type = $_GET['filter_type'] ?? 'annual';
    $year = isset($_GET['year']) ? intval($_GET['year']) : date('Y');
    $month = isset($_GET['month']) ? intval($_GET['month']) : date('n');
    $quarter = isset($_GET['quarter']) ? intval($_GET['quarter']) : ceil(date('n')/3);
    $compare_year = isset($_GET['compare_year']) ? intval($_GET['compare_year']) : ($year - 1);
    
    // Get data for current period
    $current_range = getDateRange($filter_type, $year, 
        $filter_type == 'monthly' ? $month : 
        ($filter_type == 'quarterly' ? $quarter : null));
    
    $current_data = getBalanceSheetData($db, $current_range['start'], $current_range['end']);
    
    // Get data for comparison period if requested
    $compare_range = getDateRange($filter_type, $compare_year, 
        $filter_type == 'monthly' ? ($month ?? null) : 
        ($filter_type == 'quarterly' ? ($quarter ?? null) : null));
    
    $compare_data = getBalanceSheetData($db, $compare_range['start'], $compare_range['end']);
    
    // Calculate totals
    $current_totals = calculateTotals($current_data);
    $compare_totals = calculateTotals($compare_data);
    
    switch ($export_type) {
        case 'pdf':
            exportToPDF($company, $current_range, $compare_range, $current_data, $compare_data, $current_totals, $compare_totals);
            break;
        case 'excel':
            exportToExcel($company, $current_range, $compare_range, $current_data, $compare_data, $current_totals, $compare_totals);
            break;
        case 'csv':
            exportToCSV($company, $current_range, $compare_range, $current_data, $compare_data, $current_totals, $compare_totals);
            break;
    }
}

// ========== EXPORT FUNCTIONS ==========
function exportToPDF($company, $current_range, $compare_range, $current_data, $compare_data, $current_totals, $compare_totals) {
    require_once('../tcpdf/tcpdf.php');
    
    // Create new PDF document
    $pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, 'A4', true, 'UTF-8', false);
    
    // Set document information
    $pdf->SetCreator('Financial System');
    $pdf->SetAuthor($company['company_name']);
    $pdf->SetTitle('Statement of Financial Position');
    $pdf->SetSubject('Balance Sheet');
    
    // Remove default header/footer
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    
    // Set margins
    $pdf->SetMargins(15, 15, 15);
    $pdf->SetAutoPageBreak(TRUE, 15);
    
    // Add a page
    $pdf->AddPage();
    
    // Company Header
    $pdf->SetFont('helvetica', 'B', 16);
    $pdf->Cell(0, 10, 'STATEMENT OF FINANCIAL POSITION', 0, 1, 'C');
    $pdf->SetFont('helvetica', '', 11);
    $pdf->Cell(0, 6, '(' . $company['company_name'] . ')', 0, 1, 'C');
    $pdf->Cell(0, 6, '(As at ' . date('F d, Y', strtotime($current_range['end'])) . ')', 0, 1, 'C');
    $pdf->Cell(0, 6, '(In ' . $company['currency'] . ')', 0, 1, 'C');
    $pdf->Ln(8);
    
    // Period Information
    $pdf->SetFont('helvetica', 'I', 9);
    $pdf->Cell(0, 5, 'Current Period: ' . $current_range['label'], 0, 1);
    $pdf->Cell(0, 5, 'Comparison Period: ' . $compare_range['label'], 0, 1);
    $pdf->Ln(5);
    
    // Table Header
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->Cell(100, 7, 'ASSETS', 1, 0, 'L');
    $pdf->Cell(40, 7, $current_range['label'], 1, 0, 'R');
    $pdf->Cell(40, 7, $compare_range['label'], 1, 1, 'R');
    
    // Non-Current Assets
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->Cell(100, 6, 'Non-Current Assets', 1, 0, 'L');
    $pdf->Cell(40, 6, number_format($current_totals['non_current_assets'], 2), 1, 0, 'R');
    $pdf->Cell(40, 6, number_format($compare_totals['non_current_assets'], 2), 1, 1, 'R');
    
    $pdf->SetFont('helvetica', '', 9);
    foreach ($current_data['non_current_assets'] as $item) {
        $compare_value = findComparisonValue($compare_data['non_current_assets'], $item['account_code']);
        $pdf->Cell(5, 5, '', 0, 0);
        $pdf->Cell(95, 5, $item['account_name'], 0, 0);
        $pdf->Cell(40, 5, number_format($item['balance'], 2), 0, 0, 'R');
        $pdf->Cell(40, 5, number_format($compare_value, 2), 0, 1, 'R');
    }
    
    // Current Assets
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->Cell(100, 6, 'Current Assets', 1, 0, 'L');
    $pdf->Cell(40, 6, number_format($current_totals['current_assets'], 2), 1, 0, 'R');
    $pdf->Cell(40, 6, number_format($compare_totals['current_assets'], 2), 1, 1, 'R');
    
    $pdf->SetFont('helvetica', '', 9);
    foreach ($current_data['current_assets'] as $item) {
        $compare_value = findComparisonValue($compare_data['current_assets'], $item['account_code']);
        $pdf->Cell(5, 5, '', 0, 0);
        $pdf->Cell(95, 5, $item['account_name'], 0, 0);
        $pdf->Cell(40, 5, number_format($item['balance'], 2), 0, 0, 'R');
        $pdf->Cell(40, 5, number_format($compare_value, 2), 0, 1, 'R');
    }
    
    // Total Assets
    $pdf->SetFont('helvetica', 'B', 11);
    $pdf->Cell(100, 8, 'TOTAL ASSETS', 1, 0, 'L');
    $pdf->Cell(40, 8, number_format($current_totals['total_assets'], 2), 1, 0, 'R');
    $pdf->Cell(40, 8, number_format($compare_totals['total_assets'], 2), 1, 1, 'R');
    
    $pdf->Ln(10);
    
    // EQUITY AND LIABILITIES
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->Cell(100, 7, 'EQUITY AND LIABILITIES', 1, 0, 'L');
    $pdf->Cell(40, 7, $current_range['label'], 1, 0, 'R');
    $pdf->Cell(40, 7, $compare_range['label'], 1, 1, 'R');
    
    // Equity
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->Cell(100, 6, 'Equity', 1, 0, 'L');
    $pdf->Cell(40, 6, number_format($current_totals['equity'], 2), 1, 0, 'R');
    $pdf->Cell(40, 6, number_format($compare_totals['equity'], 2), 1, 1, 'R');
    
    $pdf->SetFont('helvetica', '', 9);
    foreach ($current_data['equity'] as $item) {
        $compare_value = findComparisonValue($compare_data['equity'], $item['account_code']);
        $pdf->Cell(5, 5, '', 0, 0);
        $pdf->Cell(95, 5, $item['account_name'], 0, 0);
        $pdf->Cell(40, 5, number_format($item['balance'], 2), 0, 0, 'R');
        $pdf->Cell(40, 5, number_format($compare_value, 2), 0, 1, 'R');
    }
    
    // Non-Current Liabilities
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->Cell(100, 6, 'Non-Current Liabilities', 1, 0, 'L');
    $pdf->Cell(40, 6, number_format($current_totals['non_current_liabilities'], 2), 1, 0, 'R');
    $pdf->Cell(40, 6, number_format($compare_totals['non_current_liabilities'], 2), 1, 1, 'R');
    
    $pdf->SetFont('helvetica', '', 9);
    foreach ($current_data['non_current_liabilities'] as $item) {
        $compare_value = findComparisonValue($compare_data['non_current_liabilities'], $item['account_code']);
        $pdf->Cell(5, 5, '', 0, 0);
        $pdf->Cell(95, 5, $item['account_name'], 0, 0);
        $pdf->Cell(40, 5, number_format($item['balance'], 2), 0, 0, 'R');
        $pdf->Cell(40, 5, number_format($compare_value, 2), 0, 1, 'R');
    }
    
    // Current Liabilities
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->Cell(100, 6, 'Current Liabilities', 1, 0, 'L');
    $pdf->Cell(40, 6, number_format($current_totals['current_liabilities'], 2), 1, 0, 'R');
    $pdf->Cell(40, 6, number_format($compare_totals['current_liabilities'], 2), 1, 1, 'R');
    
    $pdf->SetFont('helvetica', '', 9);
    foreach ($current_data['current_liabilities'] as $item) {
        $compare_value = findComparisonValue($compare_data['current_liabilities'], $item['account_code']);
        $pdf->Cell(5, 5, '', 0, 0);
        $pdf->Cell(95, 5, $item['account_name'], 0, 0);
        $pdf->Cell(40, 5, number_format($item['balance'], 2), 0, 0, 'R');
        $pdf->Cell(40, 5, number_format($compare_value, 2), 0, 1, 'R');
    }
    
    // Total Equity and Liabilities
    $pdf->SetFont('helvetica', 'B', 11);
    $pdf->Cell(100, 8, 'TOTAL EQUITY AND LIABILITIES', 1, 0, 'L');
    $pdf->Cell(40, 8, number_format($current_totals['total_liabilities_equity'], 2), 1, 0, 'R');
    $pdf->Cell(40, 8, number_format($compare_totals['total_liabilities_equity'], 2), 1, 1, 'R');
    
    // Footer
    $pdf->Ln(10);
    $pdf->SetFont('helvetica', 'I', 8);
    $pdf->Cell(0, 5, 'Generated on: ' . date('Y-m-d H:i:s'), 0, 1);
    $pdf->Cell(0, 5, 'Generated by: ' . $_SESSION['username'], 0, 1);
    
    // Output PDF
    $pdf->Output('Statement_of_Financial_Position_' . date('Y-m-d') . '.pdf', 'I');
    exit;
}

function exportToExcel($company, $current_range, $compare_range, $current_data, $compare_data, $current_totals, $compare_totals) {
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment;filename="Statement_of_Financial_Position_' . date('Y-m-d') . '.xls"');
    header('Cache-Control: max-age=0');
    
    echo "<!DOCTYPE html>
    <html>
    <head>
        <meta charset='UTF-8'>
        <style>
            body { font-family: Arial, sans-serif; }
            table { border-collapse: collapse; width: 100%; }
            th, td { border: 1px solid #000; padding: 6px; }
            .header { text-align: center; font-weight: bold; font-size: 16px; }
            .total { font-weight: bold; background-color: #f0f0f0; }
            .subtotal { font-weight: bold; }
            .indent { padding-left: 20px !important; }
        </style>
    </head>
    <body>";
    
    echo "<h3 class='header'>STATEMENT OF FINANCIAL POSITION</h3>";
    echo "<h4 class='header'>" . htmlspecialchars($company['company_name']) . "</h4>";
    echo "<p class='header'>(As at " . date('F d, Y', strtotime($current_range['end'])) . ")</p>";
    echo "<p class='header'>(In " . htmlspecialchars($company['currency']) . ")</p>";
    echo "<p><strong>Current Period:</strong> " . $current_range['label'] . "</p>";
    echo "<p><strong>Comparison Period:</strong> " . $compare_range['label'] . "</p>";
    echo "<br>";
    
    echo "<table>
        <tr>
            <th width='50%'>ASSETS</th>
            <th width='25%'>" . $current_range['label'] . "</th>
            <th width='25%'>" . $compare_range['label'] . "</th>
        </tr>
        <tr class='subtotal'>
            <td><strong>Non-Current Assets</strong></td>
            <td>" . number_format($current_totals['non_current_assets'], 2) . "</td>
            <td>" . number_format($compare_totals['non_current_assets'], 2) . "</td>
        </tr>";
    
    foreach ($current_data['non_current_assets'] as $item) {
        $compare_value = findComparisonValue($compare_data['non_current_assets'], $item['account_code']);
        echo "<tr>
            <td class='indent'>" . htmlspecialchars($item['account_name']) . "</td>
            <td>" . number_format($item['balance'], 2) . "</td>
            <td>" . number_format($compare_value, 2) . "</td>
        </tr>";
    }
    
    echo "<tr class='subtotal'>
            <td><strong>Current Assets</strong></td>
            <td>" . number_format($current_totals['current_assets'], 2) . "</td>
            <td>" . number_format($compare_totals['current_assets'], 2) . "</td>
        </tr>";
    
    foreach ($current_data['current_assets'] as $item) {
        $compare_value = findComparisonValue($compare_data['current_assets'], $item['account_code']);
        echo "<tr>
            <td class='indent'>" . htmlspecialchars($item['account_name']) . "</td>
            <td>" . number_format($item['balance'], 2) . "</td>
            <td>" . number_format($compare_value, 2) . "</td>
        </tr>";
    }
    
    echo "<tr class='total'>
            <td><strong>TOTAL ASSETS</strong></td>
            <td><strong>" . number_format($current_totals['total_assets'], 2) . "</strong></td>
            <td><strong>" . number_format($compare_totals['total_assets'], 2) . "</strong></td>
        </tr>
        <tr><td colspan='3'>&nbsp;</td></tr>
        <tr>
            <th>EQUITY AND LIABILITIES</th>
            <th>" . $current_range['label'] . "</th>
            <th>" . $compare_range['label'] . "</th>
        </tr>
        <tr class='subtotal'>
            <td><strong>Equity</strong></td>
            <td>" . number_format($current_totals['equity'], 2) . "</td>
            <td>" . number_format($compare_totals['equity'], 2) . "</td>
        </tr>";
    
    foreach ($current_data['equity'] as $item) {
        $compare_value = findComparisonValue($compare_data['equity'], $item['account_code']);
        echo "<tr>
            <td class='indent'>" . htmlspecialchars($item['account_name']) . "</td>
            <td>" . number_format($item['balance'], 2) . "</td>
            <td>" . number_format($compare_value, 2) . "</td>
        </tr>";
    }
    
    echo "<tr class='subtotal'>
            <td><strong>Non-Current Liabilities</strong></td>
            <td>" . number_format($current_totals['non_current_liabilities'], 2) . "</td>
            <td>" . number_format($compare_totals['non_current_liabilities'], 2) . "</td>
        </tr>";
    
    foreach ($current_data['non_current_liabilities'] as $item) {
        $compare_value = findComparisonValue($compare_data['non_current_liabilities'], $item['account_code']);
        echo "<tr>
            <td class='indent'>" . htmlspecialchars($item['account_name']) . "</td>
            <td>" . number_format($item['balance'], 2) . "</td>
            <td>" . number_format($compare_value, 2) . "</td>
        </tr>";
    }
    
    echo "<tr class='subtotal'>
            <td><strong>Current Liabilities</strong></td>
            <td>" . number_format($current_totals['current_liabilities'], 2) . "</td>
            <td>" . number_format($compare_totals['current_liabilities'], 2) . "</td>
        </tr>";
    
    foreach ($current_data['current_liabilities'] as $item) {
        $compare_value = findComparisonValue($compare_data['current_liabilities'], $item['account_code']);
        echo "<tr>
            <td class='indent'>" . htmlspecialchars($item['account_name']) . "</td>
            <td>" . number_format($item['balance'], 2) . "</td>
            <td>" . number_format($compare_value, 2) . "</td>
        </tr>";
    }
    
    echo "<tr class='total'>
            <td><strong>TOTAL EQUITY AND LIABILITIES</strong></td>
            <td><strong>" . number_format($current_totals['total_liabilities_equity'], 2) . "</strong></td>
            <td><strong>" . number_format($compare_totals['total_liabilities_equity'], 2) . "</strong></td>
        </tr>
        <tr><td colspan='3'>&nbsp;</td></tr>
        <tr>
            <td colspan='3'><em>Generated on: " . date('Y-m-d H:i:s') . "</em></td>
        </tr>
        <tr>
            <td colspan='3'><em>Generated by: " . htmlspecialchars($_SESSION['username']) . "</em></td>
        </tr>
    </table>";
    
    echo "</body></html>";
    exit;
}

function exportToCSV($company, $current_range, $compare_range, $current_data, $compare_data, $current_totals, $compare_totals) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment;filename="Statement_of_Financial_Position_' . date('Y-m-d') . '.csv"');
    
    $output = fopen('php://output', 'w');
    fputcsv($output, ['STATEMENT OF FINANCIAL POSITION']);
    fputcsv($output, [$company['company_name']]);
    fputcsv($output, ['(As at ' . date('F d, Y', strtotime($current_range['end'])) . ')']);
    fputcsv($output, ['(In ' . $company['currency'] . ')']);
    fputcsv($output, []);
    fputcsv($output, ['Current Period:', $current_range['label']]);
    fputcsv($output, ['Comparison Period:', $compare_range['label']]);
    fputcsv($output, []);
    
    // Table Header
    fputcsv($output, ['ASSETS', $current_range['label'], $compare_range['label']]);
    
    // Non-Current Assets
    fputcsv($output, ['Non-Current Assets', $current_totals['non_current_assets'], $compare_totals['non_current_assets']]);
    foreach ($current_data['non_current_assets'] as $item) {
        $compare_value = findComparisonValue($compare_data['non_current_assets'], $item['account_code']);
        fputcsv($output, ['  ' . $item['account_name'], $item['balance'], $compare_value]);
    }
    
    // Current Assets
    fputcsv($output, ['Current Assets', $current_totals['current_assets'], $compare_totals['current_assets']]);
    foreach ($current_data['current_assets'] as $item) {
        $compare_value = findComparisonValue($compare_data['current_assets'], $item['account_code']);
        fputcsv($output, ['  ' . $item['account_name'], $item['balance'], $compare_value]);
    }
    
    // Total Assets
    fputcsv($output, ['TOTAL ASSETS', $current_totals['total_assets'], $compare_totals['total_assets']]);
    
    fputcsv($output, []);
    
    // EQUITY AND LIABILITIES
    fputcsv($output, ['EQUITY AND LIABILITIES', $current_range['label'], $compare_range['label']]);
    
    // Equity
    fputcsv($output, ['Equity', $current_totals['equity'], $compare_totals['equity']]);
    foreach ($current_data['equity'] as $item) {
        $compare_value = findComparisonValue($compare_data['equity'], $item['account_code']);
        fputcsv($output, ['  ' . $item['account_name'], $item['balance'], $compare_value]);
    }
    
    // Non-Current Liabilities
    fputcsv($output, ['Non-Current Liabilities', $current_totals['non_current_liabilities'], $compare_totals['non_current_liabilities']]);
    foreach ($current_data['non_current_liabilities'] as $item) {
        $compare_value = findComparisonValue($compare_data['non_current_liabilities'], $item['account_code']);
        fputcsv($output, ['  ' . $item['account_name'], $item['balance'], $compare_value]);
    }
    
    // Current Liabilities
    fputcsv($output, ['Current Liabilities', $current_totals['current_liabilities'], $compare_totals['current_liabilities']]);
    foreach ($current_data['current_liabilities'] as $item) {
        $compare_value = findComparisonValue($compare_data['current_liabilities'], $item['account_code']);
        fputcsv($output, ['  ' . $item['account_name'], $item['balance'], $compare_value]);
    }
    
    // Total Equity and Liabilities
    fputcsv($output, ['TOTAL EQUITY AND LIABILITIES', $current_totals['total_liabilities_equity'], $compare_totals['total_liabilities_equity']]);
    
    fputcsv($output, []);
    fputcsv($output, ['Generated on:', date('Y-m-d H:i:s')]);
    fputcsv($output, ['Generated by:', $_SESSION['username']]);
    
    fclose($output);
    exit;
}

// ========== HELPER FUNCTIONS ==========
function findComparisonValue($compare_data, $account_code) {
    foreach ($compare_data as $item) {
        if ($item['account_code'] == $account_code) {
            return $item['balance'];
        }
    }
    return 0;
}

function getDateRange($filter_type, $year, $param = null) {
    if ($filter_type == 'monthly') {
        $month = $param ?: date('n');
        $start_date = date("$year-$month-01");
        $end_date = date("$year-$month-t", strtotime($start_date));
        $label = date('F Y', strtotime($start_date));
    } elseif ($filter_type == 'quarterly') {
        $quarter = $param ?: ceil(date('n')/3);
        $quarter_months = [1 => [1,3], 2 => [4,6], 3 => [7,9], 4 => [10,12]];
        $start_month = $quarter_months[$quarter][0];
        $end_month = $quarter_months[$quarter][1];
        $start_date = date("$year-$start_month-01");
        $end_date = date("$year-$end_month-t", strtotime("$year-$end_month-01"));
        $label = "Q$quarter $year";
    } else { // annual
        $start_date = "$year-01-01";
        $end_date = "$year-12-31";
        $label = "$year";
    }
    
    return ['start' => $start_date, 'end' => $end_date, 'label' => $label];
}

function getBalanceSheetData($db, $start_date, $end_date) {
    // Define account mappings based on your chart of accounts structure
    $data = [
        'non_current_assets' => [],
        'current_assets' => [],
        'equity' => [],
        'non_current_liabilities' => [],
        'current_liabilities' => []
    ];
    
    // Property, Plant & Equipment (121 series)
    $ppe_query = "
        SELECT 'Property, Plant & Equipment' as account_name, '121' as account_code,
        COALESCE(SUM(
            CASE WHEN gl.debit_amount > 0 THEN gl.debit_amount
                 WHEN gl.credit_amount > 0 THEN -gl.credit_amount
                 ELSE 0 END
        ), 0) as balance
        FROM general_ledger gl
        JOIN chart_of_accounts coa ON gl.account_id = coa.id
        WHERE gl.transaction_date BETWEEN ? AND ?
        AND gl.status = 'active'
        AND coa.account_code LIKE '121%'
        AND coa.account_type = 'asset'
    ";
    
    $stmt = $db->prepare($ppe_query);
    $stmt->execute([$start_date, $end_date]);
    $ppe_result = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($ppe_result['balance'] != 0) {
        $data['non_current_assets'][] = $ppe_result;
    }
    
    // Intangible Assets (122 series)
    $intangible_query = "
        SELECT 'Intangible Assets' as account_name, '122' as account_code,
        COALESCE(SUM(
            CASE WHEN gl.debit_amount > 0 THEN gl.debit_amount
                 WHEN gl.credit_amount > 0 THEN -gl.credit_amount
                 ELSE 0 END
        ), 0) as balance
        FROM general_ledger gl
        JOIN chart_of_accounts coa ON gl.account_id = coa.id
        WHERE gl.transaction_date BETWEEN ? AND ?
        AND gl.status = 'active'
        AND coa.account_code LIKE '122%'
        AND coa.account_type = 'asset'
    ";
    
    $stmt = $db->prepare($intangible_query);
    $stmt->execute([$start_date, $end_date]);
    $intangible_result = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($intangible_result['balance'] != 0) {
        $data['non_current_assets'][] = $intangible_result;
    }
    
    // Other Non-Current Assets (123-125 series)
    $other_nca_query = "
        SELECT 'Other Non-Current Assets' as account_name, '123-125' as account_code,
        COALESCE(SUM(
            CASE WHEN gl.debit_amount > 0 THEN gl.debit_amount
                 WHEN gl.credit_amount > 0 THEN -gl.credit_amount
                 ELSE 0 END
        ), 0) as balance
        FROM general_ledger gl
        JOIN chart_of_accounts coa ON gl.account_id = coa.id
        WHERE gl.transaction_date BETWEEN ? AND ?
        AND gl.status = 'active'
        AND coa.account_code IN ('123', '124', '125', '1251', '1252', '1253')
        AND coa.account_type = 'asset'
    ";
    
    $stmt = $db->prepare($other_nca_query);
    $stmt->execute([$start_date, $end_date]);
    $other_nca_result = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($other_nca_result['balance'] != 0) {
        $data['non_current_assets'][] = $other_nca_result;
    }
    
    // Inventory (113)
    $inventory_query = "
        SELECT 'Inventory' as account_name, '113' as account_code,
        COALESCE(SUM(
            CASE WHEN gl.debit_amount > 0 THEN gl.debit_amount
                 WHEN gl.credit_amount > 0 THEN -gl.credit_amount
                 ELSE 0 END
        ), 0) as balance
        FROM general_ledger gl
        JOIN chart_of_accounts coa ON gl.account_id = coa.id
        WHERE gl.transaction_date BETWEEN ? AND ?
        AND gl.status = 'active'
        AND coa.account_code = '113'
    ";
    
    $stmt = $db->prepare($inventory_query);
    $stmt->execute([$start_date, $end_date]);
    $inventory_result = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($inventory_result['balance'] != 0) {
        $data['current_assets'][] = $inventory_result;
    }
    
    // Trade Receivables (1121)
    $receivables_query = "
        SELECT 'Trade Receivables' as account_name, '1121' as account_code,
        COALESCE(SUM(
            CASE WHEN gl.debit_amount > 0 THEN gl.debit_amount
                 WHEN gl.credit_amount > 0 THEN -gl.credit_amount
                 ELSE 0 END
        ), 0) as balance
        FROM general_ledger gl
        JOIN chart_of_accounts coa ON gl.account_id = coa.id
        WHERE gl.transaction_date BETWEEN ? AND ?
        AND gl.status = 'active'
        AND coa.account_code = '1121'
    ";
    
    $stmt = $db->prepare($receivables_query);
    $stmt->execute([$start_date, $end_date]);
    $receivables_result = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($receivables_result['balance'] != 0) {
        $data['current_assets'][] = $receivables_result;
    }
    
    // Cash & Cash Equivalents (111 series) - Modified for clickable
    $cash_query = "
        SELECT 
            'Cash & Cash Equivalents' as account_name, 
            '111' as account_code,
            COALESCE(SUM(
                CASE WHEN gl.debit_amount > 0 THEN gl.debit_amount
                     WHEN gl.credit_amount > 0 THEN -gl.credit_amount
                     ELSE 0 END
            ), 0) as balance,
            COUNT(DISTINCT gl.id) as transaction_count
        FROM general_ledger gl
        JOIN chart_of_accounts coa ON gl.account_id = coa.id
        WHERE gl.transaction_date BETWEEN ? AND ?
        AND gl.status = 'active'
        AND coa.account_code LIKE '111%'
        AND coa.account_type = 'asset'
    ";
    
    $stmt = $db->prepare($cash_query);
    $stmt->execute([$start_date, $end_date]);
    $cash_result = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($cash_result['balance'] != 0) {
        $data['current_assets'][] = $cash_result;
    }
    
    // Other Current Assets (114 series)
    $other_ca_query = "
        SELECT 'Other Current Assets' as account_name, '114' as account_code,
        COALESCE(SUM(
            CASE WHEN gl.debit_amount > 0 THEN gl.debit_amount
                 WHEN gl.credit_amount > 0 THEN -gl.credit_amount
                 ELSE 0 END
        ), 0) as balance
        FROM general_ledger gl
        JOIN chart_of_accounts coa ON gl.account_id = coa.id
        WHERE gl.transaction_date BETWEEN ? AND ?
        AND gl.status = 'active'
        AND coa.account_code LIKE '114%'
        AND coa.account_type = 'asset'
    ";
    
    $stmt = $db->prepare($other_ca_query);
    $stmt->execute([$start_date, $end_date]);
    $other_ca_result = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($other_ca_result['balance'] != 0) {
        $data['current_assets'][] = $other_ca_result;
    }
    
    // Share Capital (31)
    $share_capital_query = "
        SELECT 'Share Capital' as account_name, '31' as account_code,
        COALESCE(SUM(
            CASE WHEN gl.credit_amount > 0 THEN gl.credit_amount
                 WHEN gl.debit_amount > 0 THEN -gl.debit_amount
                 ELSE 0 END
        ), 0) as balance
        FROM general_ledger gl
        JOIN chart_of_accounts coa ON gl.account_id = coa.id
        WHERE gl.transaction_date BETWEEN ? AND ?
        AND gl.status = 'active'
        AND coa.account_code = '31'
    ";
    
    $stmt = $db->prepare($share_capital_query);
    $stmt->execute([$start_date, $end_date]);
    $share_capital_result = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($share_capital_result['balance'] != 0) {
        $data['equity'][] = $share_capital_result;
    }
    
    // Retained Earnings (33)
    $retained_earnings_query = "
        SELECT 'Retained Earnings' as account_name, '33' as account_code,
        COALESCE(SUM(
            CASE WHEN gl.credit_amount > 0 THEN gl.credit_amount
                 WHEN gl.debit_amount > 0 THEN -gl.debit_amount
                 ELSE 0 END
        ), 0) as balance
        FROM general_ledger gl
        JOIN chart_of_accounts coa ON gl.account_id = coa.id
        WHERE gl.transaction_date BETWEEN ? AND ?
        AND gl.status = 'active'
        AND coa.account_code = '33'
    ";
    
    $stmt = $db->prepare($retained_earnings_query);
    $stmt->execute([$start_date, $end_date]);
    $retained_earnings_result = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($retained_earnings_result['balance'] != 0) {
        $data['equity'][] = $retained_earnings_result;
    }
    
    // Other Equity Items (32, 34, 35)
    $other_equity_query = "
        SELECT 'Other Equity Items' as account_name, '32-35' as account_code,
        COALESCE(SUM(
            CASE WHEN gl.credit_amount > 0 THEN gl.credit_amount
                 WHEN gl.debit_amount > 0 THEN -gl.debit_amount
                 ELSE 0 END
        ), 0) as balance
        FROM general_ledger gl
        JOIN chart_of_accounts coa ON gl.account_id = coa.id
        WHERE gl.transaction_date BETWEEN ? AND ?
        AND gl.status = 'active'
        AND coa.account_code IN ('32', '34', '35')
    ";
    
    $stmt = $db->prepare($other_equity_query);
    $stmt->execute([$start_date, $end_date]);
    $other_equity_result = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($other_equity_result['balance'] != 0) {
        $data['equity'][] = $other_equity_result;
    }
    
    // Long-term Loans (221)
    $long_term_loans_query = "
        SELECT 'Long-term Loans' as account_name, '221' as account_code,
        COALESCE(SUM(
            CASE WHEN gl.credit_amount > 0 THEN gl.credit_amount
                 WHEN gl.debit_amount > 0 THEN -gl.debit_amount
                 ELSE 0 END
        ), 0) as balance
        FROM general_ledger gl
        JOIN chart_of_accounts coa ON gl.account_id = coa.id
        WHERE gl.transaction_date BETWEEN ? AND ?
        AND gl.status = 'active'
        AND coa.account_code = '221'
    ";
    
    $stmt = $db->prepare($long_term_loans_query);
    $stmt->execute([$start_date, $end_date]);
    $long_term_loans_result = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($long_term_loans_result['balance'] != 0) {
        $data['non_current_liabilities'][] = $long_term_loans_result;
    }
    
    // Deferred Tax (223)
    $deferred_tax_query = "
        SELECT 'Deferred Tax' as account_name, '223' as account_code,
        COALESCE(SUM(
            CASE WHEN gl.credit_amount > 0 THEN gl.credit_amount
                 WHEN gl.debit_amount > 0 THEN -gl.debit_amount
                 ELSE 0 END
        ), 0) as balance
        FROM general_ledger gl
        JOIN chart_of_accounts coa ON gl.account_id = coa.id
        WHERE gl.transaction_date BETWEEN ? AND ?
        AND gl.status = 'active'
        AND coa.account_code = '223'
    ";
    
    $stmt = $db->prepare($deferred_tax_query);
    $stmt->execute([$start_date, $end_date]);
    $deferred_tax_result = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($deferred_tax_result['balance'] != 0) {
        $data['non_current_liabilities'][] = $deferred_tax_result;
    }
    
    // Other Non-Current Liabilities (222, 224)
    $other_ncl_query = "
        SELECT 'Other Non-Current Liabilities' as account_name, '222-224' as account_code,
        COALESCE(SUM(
            CASE WHEN gl.credit_amount > 0 THEN gl.credit_amount
                 WHEN gl.debit_amount > 0 THEN -gl.debit_amount
                 ELSE 0 END
        ), 0) as balance
        FROM general_ledger gl
        JOIN chart_of_accounts coa ON gl.account_id = coa.id
        WHERE gl.transaction_date BETWEEN ? AND ?
        AND gl.status = 'active'
        AND coa.account_code IN ('222', '224')
    ";
    
    $stmt = $db->prepare($other_ncl_query);
    $stmt->execute([$start_date, $end_date]);
    $other_ncl_result = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($other_ncl_result['balance'] != 0) {
        $data['non_current_liabilities'][] = $other_ncl_result;
    }
    
    // Trade Payables (211 series) - Modified for clickable
    $trade_payables_query = "
        SELECT 
            'Trade Payables' as account_name, 
            '211' as account_code,
            COALESCE(SUM(
                CASE WHEN gl.credit_amount > 0 THEN gl.credit_amount
                     WHEN gl.debit_amount > 0 THEN -gl.debit_amount
                     ELSE 0 END
            ), 0) as balance,
            COUNT(DISTINCT gl.id) as transaction_count
        FROM general_ledger gl
        JOIN chart_of_accounts coa ON gl.account_id = coa.id
        WHERE gl.transaction_date BETWEEN ? AND ?
        AND gl.status = 'active'
        AND coa.account_code LIKE '211%'
    ";
    
    $stmt = $db->prepare($trade_payables_query);
    $stmt->execute([$start_date, $end_date]);
    $trade_payables_result = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($trade_payables_result['balance'] != 0) {
        $data['current_liabilities'][] = $trade_payables_result;
    }
    
    // Short-term Loans (214)
    $short_term_loans_query = "
        SELECT 'Short-term Loans' as account_name, '214' as account_code,
        COALESCE(SUM(
            CASE WHEN gl.credit_amount > 0 THEN gl.credit_amount
                 WHEN gl.debit_amount > 0 THEN -gl.debit_amount
                 ELSE 0 END
        ), 0) as balance
        FROM general_ledger gl
        JOIN chart_of_accounts coa ON gl.account_id = coa.id
        WHERE gl.transaction_date BETWEEN ? AND ?
        AND gl.status = 'active'
        AND coa.account_code = '214'
    ";
    
    $stmt = $db->prepare($short_term_loans_query);
    $stmt->execute([$start_date, $end_date]);
    $short_term_loans_result = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($short_term_loans_result['balance'] != 0) {
        $data['current_liabilities'][] = $short_term_loans_result;
    }
    
    // Accrued Expenses (212)
    $accrued_expenses_query = "
        SELECT 'Accrued Expenses' as account_name, '212' as account_code,
        COALESCE(SUM(
            CASE WHEN gl.credit_amount > 0 THEN gl.credit_amount
                 WHEN gl.debit_amount > 0 THEN -gl.debit_amount
                 ELSE 0 END
        ), 0) as balance
        FROM general_ledger gl
        JOIN chart_of_accounts coa ON gl.account_id = coa.id
        WHERE gl.transaction_date BETWEEN ? AND ?
        AND gl.status = 'active'
        AND coa.account_code = '212'
    ";
    
    $stmt = $db->prepare($accrued_expenses_query);
    $stmt->execute([$start_date, $end_date]);
    $accrued_expenses_result = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($accrued_expenses_result['balance'] != 0) {
        $data['current_liabilities'][] = $accrued_expenses_result;
    }
    
    // Other Current Liabilities (213, 215) - Modified for clickable
    $other_cl_query = "
        SELECT 
            'Other Current Liabilities' as account_name, 
            '213-215' as account_code,
            COALESCE(SUM(
                CASE WHEN gl.credit_amount > 0 THEN gl.credit_amount
                     WHEN gl.debit_amount > 0 THEN -gl.debit_amount
                     ELSE 0 END
            ), 0) as balance,
            COUNT(DISTINCT gl.id) as transaction_count
        FROM general_ledger gl
        JOIN chart_of_accounts coa ON gl.account_id = coa.id
        WHERE gl.transaction_date BETWEEN ? AND ?
        AND gl.status = 'active'
        AND (coa.account_code LIKE '213%' OR coa.account_code LIKE '214%' OR coa.account_code LIKE '215%')
    ";
    
    $stmt = $db->prepare($other_cl_query);
    $stmt->execute([$start_date, $end_date]);
    $other_cl_result = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($other_cl_result['balance'] != 0) {
        $data['current_liabilities'][] = $other_cl_result;
    }
    
    return $data;
}

function calculateTotals($data) {
    $totals = [
        'non_current_assets' => 0,
        'current_assets' => 0,
        'equity' => 0,
        'non_current_liabilities' => 0,
        'current_liabilities' => 0
    ];
    
    foreach ($data['non_current_assets'] as $item) {
        $totals['non_current_assets'] += $item['balance'];
    }
    
    foreach ($data['current_assets'] as $item) {
        $totals['current_assets'] += $item['balance'];
    }
    
    foreach ($data['equity'] as $item) {
        $totals['equity'] += $item['balance'];
    }
    
    foreach ($data['non_current_liabilities'] as $item) {
        $totals['non_current_liabilities'] += $item['balance'];
    }
    
    foreach ($data['current_liabilities'] as $item) {
        $totals['current_liabilities'] += $item['balance'];
    }
    
    $totals['total_assets'] = $totals['non_current_assets'] + $totals['current_assets'];
    $totals['total_liabilities_equity'] = $totals['equity'] + $totals['non_current_liabilities'] + $totals['current_liabilities'];
    
    return $totals;
}

// ========== FILTER PARAMETERS ==========
$filter_type = $_GET['filter_type'] ?? 'annual';
$year = isset($_GET['year']) ? intval($_GET['year']) : date('Y');
$month = isset($_GET['month']) ? intval($_GET['month']) : date('n');
$quarter = isset($_GET['quarter']) ? intval($_GET['quarter']) : ceil(date('n')/3);
$compare_year = isset($_GET['compare_year']) ? intval($_GET['compare_year']) : ($year - 1);
$compare_month = isset($_GET['compare_month']) ? intval($_GET['compare_month']) : $month;
$compare_quarter = isset($_GET['compare_quarter']) ? intval($_GET['compare_quarter']) : ($quarter - 1);

// ========== GET DATA ==========
$current_range = getDateRange($filter_type, $year, 
    $filter_type == 'monthly' ? $month : 
    ($filter_type == 'quarterly' ? $quarter : null));

$compare_range = getDateRange($filter_type, $compare_year, 
    $filter_type == 'monthly' ? $compare_month : 
    ($filter_type == 'quarterly' ? $compare_quarter : null));

$current_data = getBalanceSheetData($db, $current_range['start'], $current_range['end']);
$compare_data = getBalanceSheetData($db, $compare_range['start'], $compare_range['end']);

$current_totals = calculateTotals($current_data);
$compare_totals = calculateTotals($compare_data);

// ========== DISPLAY FUNCTIONS ==========
function displayAmount($amount, $currency, $account_code = '', $account_name = '', $start_date = '', $end_date = '', $has_entries = true) {
    if (abs($amount) < 0.01) return '-';
    
    $amount_str = htmlspecialchars($currency) . ' ' . number_format(abs($amount), 2);
    
    // Make clickable for specific accounts with entries
    $clickable_accounts = ['111', '211', '213-215', '1121', '113', '114'];
    if ($has_entries && in_array($account_code, $clickable_accounts) && abs($amount) >= 0.01) {
        $params = [
            'drilldown' => 'true',
            'account_code' => $account_code,
            'account_name' => urlencode($account_name),
            'start_date' => $start_date,
            'end_date' => $end_date,
            'filter_type' => $_GET['filter_type'] ?? 'annual',
            'year' => $_GET['year'] ?? date('Y'),
            'month' => $_GET['month'] ?? null,
            'quarter' => $_GET['quarter'] ?? null
        ];
        
        $url = 'balance_sheet.php?' . http_build_query($params);
        
        return '<a href="' . $url . '" class="clickable-account" title="Click to view details">' . $amount_str . '</a>';
    }
    
    return $amount_str;
}

function displayChange($current, $previous) {
    if ($previous == 0) return '';
    
    $change = $current - $previous;
    $percent = ($change / abs($previous)) * 100;
    
    $class = $change >= 0 ? 'text-success' : 'text-danger';
    $icon = $change >= 0 ? '↑' : '↓';
    
    return '<span class="' . $class . ' small">' . $icon . ' ' . 
           number_format(abs($change), 2) . ' (' . number_format(abs($percent), 1) . '%)</span>';
}

include '../includes/header.php';
?>

<div class="container-fluid py-4">
    <div class="card">
        <div class="card-header bg-primary text-white">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h4 class="mb-0">
                        <i class="bi bi-file-earmark-text me-2"></i>
                        STATEMENT OF FINANCIAL POSITION
                    </h4>
                    <p class="mb-0">Using actual general ledger data with drill-down functionality</p>
                </div>
                <div class="btn-group">
                   
                    <button type="button" class="btn btn-light btn-sm dropdown-toggle" data-bs-toggle="dropdown">
                        <i class="bi bi-download me-1"></i>Export
                    </button>
                    <ul class="dropdown-menu">
                        <li><a class="dropdown-item" href="?<?php echo http_build_query(array_merge($_GET, ['export' => 'pdf'])); ?>">
                            <i class="bi bi-file-pdf me-2"></i>Export as PDF
                        </a></li>
                        <li><a class="dropdown-item" href="?<?php echo http_build_query(array_merge($_GET, ['export' => 'excel'])); ?>">
                            <i class="bi bi-file-excel me-2"></i>Export as Excel
                        </a></li>
                        <li><a class="dropdown-item" href="?<?php echo http_build_query(array_merge($_GET, ['export' => 'csv'])); ?>">
                            <i class="bi bi-file-text me-2"></i>Export as CSV
                        </a></li>
                    </ul>
                </div>
            </div>
        </div>
        
        <!-- Filter Controls -->
        <div class="card-body border-bottom">
            <form method="GET" class="row g-3">
                <div class="col-md-2">
                    <label class="form-label">Period Type</label>
                    <select name="filter_type" class="form-select" id="filter-type">
                        <option value="annual" <?php echo $filter_type == 'annual' ? 'selected' : ''; ?>>Annual</option>
                        <option value="quarterly" <?php echo $filter_type == 'quarterly' ? 'selected' : ''; ?>>Quarterly</option>
                        <option value="monthly" <?php echo $filter_type == 'monthly' ? 'selected' : ''; ?>>Monthly</option>
                    </select>
                </div>
                
                <div class="col-md-2">
                    <label class="form-label">Current Year</label>
                    <select name="year" class="form-select">
                        <?php for ($y = date('Y') + 1; $y >= 2020; $y--): ?>
                            <option value="<?php echo $y; ?>" <?php echo $year == $y ? 'selected' : ''; ?>>
                                <?php echo $y; ?>
                            </option>
                        <?php endfor; ?>
                    </select>
                </div>
                
                <div class="col-md-2" id="current-quarter-container" style="display: <?php echo $filter_type == 'quarterly' ? 'block' : 'none'; ?>">
                    <label class="form-label">Current Quarter</label>
                    <select name="quarter" class="form-select">
                        <?php for ($q = 1; $q <= 4; $q++): ?>
                            <option value="<?php echo $q; ?>" <?php echo $quarter == $q ? 'selected' : ''; ?>>
                                Q<?php echo $q; ?>
                            </option>
                        <?php endfor; ?>
                    </select>
                </div>
                
                <div class="col-md-2" id="current-month-container" style="display: <?php echo $filter_type == 'monthly' ? 'block' : 'none'; ?>">
                    <label class="form-label">Current Month</label>
                    <select name="month" class="form-select">
                        <?php for ($m = 1; $m <= 12; $m++): ?>
                            <option value="<?php echo $m; ?>" <?php echo $month == $m ? 'selected' : ''; ?>>
                                <?php echo date('F', mktime(0, 0, 0, $m, 1)); ?>
                            </option>
                        <?php endfor; ?>
                    </select>
                </div>
                
                <div class="col-md-2">
                    <label class="form-label">Compare With Year</label>
                    <select name="compare_year" class="form-select">
                        <?php for ($y = date('Y') + 1; $y >= 2020; $y--): ?>
                            <option value="<?php echo $y; ?>" <?php echo $compare_year == $y ? 'selected' : ''; ?>>
                                <?php echo $y; ?>
                            </option>
                        <?php endfor; ?>
                    </select>
                </div>
                
                <div class="col-md-2" id="compare-quarter-container" style="display: <?php echo $filter_type == 'quarterly' ? 'block' : 'none'; ?>">
                    <label class="form-label">Compare Quarter</label>
                    <select name="compare_quarter" class="form-select">
                        <?php for ($q = 1; $q <= 4; $q++): ?>
                            <option value="<?php echo $q; ?>" <?php echo $compare_quarter == $q ? 'selected' : ''; ?>>
                                Q<?php echo $q; ?>
                            </option>
                        <?php endfor; ?>
                    </select>
                </div>
                
                <div class="col-md-12 mt-3">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-filter me-1"></i>Generate Report
                    </button>
                    <a href="balance_sheet.php" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-clockwise"></i> Reset
                    </a>
                </div>
            </form>
        </div>
        
        <!-- Report Body -->
        <div class="card-body">
            <!-- Company Header -->
            <div class="text-center mb-4">
                <h4>STATEMENT OF FINANCIAL POSITION</h4>
                <h5><?php echo htmlspecialchars($company['company_name']); ?></h5>
                <p class="mb-1">(As at <?php echo date('F d, Y', strtotime($current_range['end'])); ?>)</p>
                <p class="mb-1">(In <?php echo htmlspecialchars($company['currency']); ?>)</p>
            </div>
            
            <!-- Info Alert about Clickable Items -->
            <div class="alert alert-info alert-dismissible fade show mb-4">
                <i class="bi bi-info-circle me-2"></i>
                <strong>Note:</strong> Click on any amount with entries (Cash & Cash Equivalents, Trade Payables, Other Current Liabilities) to view detailed transaction breakdown with third-level account information.
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            
            <!-- Comparative Table -->
            <div class="table-responsive">
                <table class="table table-bordered">
                    <thead class="table-light">
                        <tr>
                            <th style="width: 50%;">ASSETS</th>
                            <th style="width: 25%;" class="text-center">
                                <?php echo $current_range['label']; ?>
                                <br><small>Current Period</small>
                            </th>
                            <th style="width: 25%;" class="text-center">
                                <?php echo $compare_range['label']; ?>
                                <br><small>Comparison Period</small>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <!-- Non-Current Assets -->
                        <tr class="table-secondary">
                            <td><strong>Non-Current Assets</strong></td>
                            <td class="text-end"><strong><?php echo displayAmount($current_totals['non_current_assets'], $company['currency']); ?></strong></td>
                            <td class="text-end"><strong><?php echo displayAmount($compare_totals['non_current_assets'], $company['currency']); ?></strong>
                                <?php if ($compare_totals['non_current_assets'] != 0): ?>
                                <div class="change-indicator small">
                                    <?php echo displayChange($current_totals['non_current_assets'], $compare_totals['non_current_assets']); ?>
                                </div>
                                <?php endif; ?>
                            </td>
                        </tr>
                        
                        <?php foreach ($current_data['non_current_assets'] as $item): ?>
                        <?php $compare_value = findComparisonValue($compare_data['non_current_assets'], $item['account_code']); ?>
                        <tr>
                            <td style="padding-left: 30px;"><?php echo htmlspecialchars($item['account_name']); ?></td>
                            <td class="text-end"><?php echo displayAmount($item['balance'], '', $item['account_code'], $item['account_name'], $current_range['start'], $current_range['end'], $item['balance'] != 0); ?></td>
                            <td class="text-end"><?php echo displayAmount($compare_value, '', $item['account_code'], $item['account_name'], $compare_range['start'], $compare_range['end'], $compare_value != 0); ?>
                                <?php if ($compare_value != 0): ?>
                                <div class="change-indicator small">
                                    <?php echo displayChange($item['balance'], $compare_value); ?>
                                </div>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        
                        <!-- Current Assets -->
                        <tr class="table-secondary">
                            <td><strong>Current Assets</strong></td>
                            <td class="text-end"><strong><?php echo displayAmount($current_totals['current_assets'], $company['currency']); ?></strong></td>
                            <td class="text-end"><strong><?php echo displayAmount($compare_totals['current_assets'], $company['currency']); ?></strong>
                                <?php if ($compare_totals['current_assets'] != 0): ?>
                                <div class="change-indicator small">
                                    <?php echo displayChange($current_totals['current_assets'], $compare_totals['current_assets']); ?>
                                </div>
                                <?php endif; ?>
                            </td>
                        </tr>
                        
                        <?php foreach ($current_data['current_assets'] as $item): ?>
                        <?php $compare_value = findComparisonValue($compare_data['current_assets'], $item['account_code']); 
                              $has_entries = isset($item['transaction_count']) ? $item['transaction_count'] > 0 : $item['balance'] != 0;
                        ?>
                        <tr>
                            <td style="padding-left: 30px;">
                                <?php echo htmlspecialchars($item['account_name']); ?>
                                <?php if ($has_entries && in_array($item['account_code'], ['111', '1121', '113', '114'])): ?>
                                <span class="badge bg-info ms-2" title="Clickable for details">
                                    <i class="bi bi-arrow-down-circle"></i>
                                </span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end"><?php echo displayAmount($item['balance'], '', $item['account_code'], $item['account_name'], $current_range['start'], $current_range['end'], $has_entries); ?></td>
                            <td class="text-end"><?php echo displayAmount($compare_value, '', $item['account_code'], $item['account_name'], $compare_range['start'], $compare_range['end'], $compare_value != 0); ?>
                                <?php if ($compare_value != 0): ?>
                                <div class="change-indicator small">
                                    <?php echo displayChange($item['balance'], $compare_value); ?>
                                </div>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        
                        <!-- TOTAL ASSETS -->
                        <tr class="table-primary">
                            <td><strong>TOTAL ASSETS</strong></td>
                            <td class="text-end"><strong><?php echo displayAmount($current_totals['total_assets'], $company['currency']); ?></strong></td>
                            <td class="text-end"><strong><?php echo displayAmount($compare_totals['total_assets'], $company['currency']); ?></strong>
                                <?php if ($compare_totals['total_assets'] != 0): ?>
                                <div class="change-indicator small">
                                    <?php echo displayChange($current_totals['total_assets'], $compare_totals['total_assets']); ?>
                                </div>
                                <?php endif; ?>
                            </td>
                        </tr>
                        
                        <!-- EQUITY AND LIABILITIES SECTION -->
                        <tr>
                            <th colspan="3" class="table-light text-center">EQUITY AND LIABILITIES</th>
                        </tr>
                        
                        <!-- Equity -->
                        <tr class="table-secondary">
                            <td><strong>Equity</strong></td>
                            <td class="text-end"><strong><?php echo displayAmount($current_totals['equity'], $company['currency']); ?></strong></td>
                            <td class="text-end"><strong><?php echo displayAmount($compare_totals['equity'], $company['currency']); ?></strong>
                                <?php if ($compare_totals['equity'] != 0): ?>
                                <div class="change-indicator small">
                                    <?php echo displayChange($current_totals['equity'], $compare_totals['equity']); ?>
                                </div>
                                <?php endif; ?>
                            </td>
                        </tr>
                        
                        <?php foreach ($current_data['equity'] as $item): ?>
                        <?php $compare_value = findComparisonValue($compare_data['equity'], $item['account_code']); ?>
                        <tr>
                            <td style="padding-left: 30px;"><?php echo htmlspecialchars($item['account_name']); ?></td>
                            <td class="text-end"><?php echo displayAmount($item['balance'], '', $item['account_code'], $item['account_name'], $current_range['start'], $current_range['end'], $item['balance'] != 0); ?></td>
                            <td class="text-end"><?php echo displayAmount($compare_value, '', $item['account_code'], $item['account_name'], $compare_range['start'], $compare_range['end'], $compare_value != 0); ?>
                                <?php if ($compare_value != 0): ?>
                                <div class="change-indicator small">
                                    <?php echo displayChange($item['balance'], $compare_value); ?>
                                </div>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        
                        <!-- Non-Current Liabilities -->
                        <tr class="table-secondary">
                            <td><strong>Non-Current Liabilities</strong></td>
                            <td class="text-end"><strong><?php echo displayAmount($current_totals['non_current_liabilities'], $company['currency']); ?></strong></td>
                            <td class="text-end"><strong><?php echo displayAmount($compare_totals['non_current_liabilities'], $company['currency']); ?></strong>
                                <?php if ($compare_totals['non_current_liabilities'] != 0): ?>
                                <div class="change-indicator small">
                                    <?php echo displayChange($current_totals['non_current_liabilities'], $compare_totals['non_current_liabilities']); ?>
                                </div>
                                <?php endif; ?>
                            </td>
                        </tr>
                        
                        <?php foreach ($current_data['non_current_liabilities'] as $item): ?>
                        <?php $compare_value = findComparisonValue($compare_data['non_current_liabilities'], $item['account_code']); ?>
                        <tr>
                            <td style="padding-left: 30px;"><?php echo htmlspecialchars($item['account_name']); ?></td>
                            <td class="text-end"><?php echo displayAmount($item['balance'], '', $item['account_code'], $item['account_name'], $current_range['start'], $current_range['end'], $item['balance'] != 0); ?></td>
                            <td class="text-end"><?php echo displayAmount($compare_value, '', $item['account_code'], $item['account_name'], $compare_range['start'], $compare_range['end'], $compare_value != 0); ?>
                                <?php if ($compare_value != 0): ?>
                                <div class="change-indicator small">
                                    <?php echo displayChange($item['balance'], $compare_value); ?>
                                </div>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        
                        <!-- Current Liabilities -->
                        <tr class="table-secondary">
                            <td><strong>Current Liabilities</strong></td>
                            <td class="text-end"><strong><?php echo displayAmount($current_totals['current_liabilities'], $company['currency']); ?></strong></td>
                            <td class="text-end"><strong><?php echo displayAmount($compare_totals['current_liabilities'], $company['currency']); ?></strong>
                                <?php if ($compare_totals['current_liabilities'] != 0): ?>
                                <div class="change-indicator small">
                                    <?php echo displayChange($current_totals['current_liabilities'], $compare_totals['current_liabilities']); ?>
                                </div>
                                <?php endif; ?>
                            </td>
                        </tr>
                        
                        <?php foreach ($current_data['current_liabilities'] as $item): ?>
                        <?php $compare_value = findComparisonValue($compare_data['current_liabilities'], $item['account_code']); 
                              $has_entries = isset($item['transaction_count']) ? $item['transaction_count'] > 0 : $item['balance'] != 0;
                        ?>
                        <tr>
                            <td style="padding-left: 30px;">
                                <?php echo htmlspecialchars($item['account_name']); ?>
                                <?php if ($has_entries && in_array($item['account_code'], ['211', '213-215'])): ?>
                                <span class="badge bg-info ms-2" title="Clickable for details">
                                    <i class="bi bi-arrow-down-circle"></i>
                                </span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end"><?php echo displayAmount($item['balance'], '', $item['account_code'], $item['account_name'], $current_range['start'], $current_range['end'], $has_entries); ?></td>
                            <td class="text-end"><?php echo displayAmount($compare_value, '', $item['account_code'], $item['account_name'], $compare_range['start'], $compare_range['end'], $compare_value != 0); ?>
                                <?php if ($compare_value != 0): ?>
                                <div class="change-indicator small">
                                    <?php echo displayChange($item['balance'], $compare_value); ?>
                                </div>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        
                        <!-- TOTAL EQUITY AND LIABILITIES -->
                        <tr class="table-primary">
                            <td><strong>TOTAL EQUITY AND LIABILITIES</strong></td>
                            <td class="text-end"><strong><?php echo displayAmount($current_totals['total_liabilities_equity'], $company['currency']); ?></strong></td>
                            <td class="text-end"><strong><?php echo displayAmount($compare_totals['total_liabilities_equity'], $company['currency']); ?></strong>
                                <?php if ($compare_totals['total_liabilities_equity'] != 0): ?>
                                <div class="change-indicator small">
                                    <?php echo displayChange($current_totals['total_liabilities_equity'], $compare_totals['total_liabilities_equity']); ?>
                                </div>
                                <?php endif; ?>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            
            <!-- Balance Check -->
            <div class="row mt-4">
                <div class="col-12">
                    <?php 
                    $current_balance_diff = abs($current_totals['total_assets'] - $current_totals['total_liabilities_equity']);
                    $current_is_balanced = $current_balance_diff < 0.01;
                    
                    $compare_balance_diff = abs($compare_totals['total_assets'] - $compare_totals['total_liabilities_equity']);
                    $compare_is_balanced = $compare_balance_diff < 0.01;
                    ?>
                    <div class="alert <?php echo $current_is_balanced ? 'alert-success' : 'alert-danger'; ?>">
                        <div class="row">
                            <div class="col-md-6">
                                <i class="bi bi-<?php echo $current_is_balanced ? 'check' : 'x'; ?>-circle me-2"></i>
                                <strong>Current Period:</strong>
                                <?php echo $current_is_balanced ? 'Balance Sheet is balanced!' : 'Balance Sheet is NOT balanced!'; ?>
                                <?php if (!$current_is_balanced): ?>
                                    <br><small>Difference: <?php echo displayAmount($current_balance_diff, $company['currency']); ?></small>
                                <?php endif; ?>
                            </div>
                            <div class="col-md-6">
                                <i class="bi bi-<?php echo $compare_is_balanced ? 'check' : 'x'; ?>-circle me-2"></i>
                                <strong>Comparison Period:</strong>
                                <?php echo $compare_is_balanced ? 'Balance Sheet is balanced!' : 'Balance Sheet is NOT balanced!'; ?>
                                <?php if (!$compare_is_balanced): ?>
                                    <br><small>Difference: <?php echo displayAmount($compare_balance_diff, $company['currency']); ?></small>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Data Summary -->
                    <div class="alert alert-info">
                        <strong>Data Summary:</strong><br>
                        <div class="row">
                            <div class="col-md-6">
                                • Period: <?php echo $current_range['label']; ?><br>
                                • From: <?php echo date('d/m/Y', strtotime($current_range['start'])); ?><br>
                                • To: <?php echo date('d/m/Y', strtotime($current_range['end'])); ?>
                            </div>
                            <div class="col-md-6">
                                • Total Assets: <?php echo displayAmount($current_totals['total_assets'], $company['currency']); ?><br>
                                • Total Liabilities & Equity: <?php echo displayAmount($current_totals['total_liabilities_equity'], $company['currency']); ?><br>
                                • Generated: <?php echo date('d/m/Y H:i:s'); ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
    .table-secondary td {
        background-color: #f8f9fa !important;
        font-weight: bold;
    }
    .table-primary td {
        background-color: #e3f2fd !important;
        font-weight: bold;
    }
    .change-indicator {
        font-size: 0.85em;
        margin-top: 2px;
    }
    .clickable-account {
        color: #0d6efd;
        cursor: pointer;
        text-decoration: underline dotted;
        font-weight: 500;
    }
    .clickable-account:hover {
        color: #0a58ca;
        text-decoration: underline;
    }
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const filterType = document.getElementById('filter-type');
    const currentQuarter = document.getElementById('current-quarter-container');
    const currentMonth = document.getElementById('current-month-container');
    const compareQuarter = document.getElementById('compare-quarter-container');
    const compareMonth = document.getElementById('compare-month-container');
    
    function toggleFilters() {
        const value = filterType.value;
        
        currentQuarter.style.display = value === 'quarterly' ? 'block' : 'none';
        currentMonth.style.display = value === 'monthly' ? 'block' : 'none';
        compareQuarter.style.display = value === 'quarterly' ? 'block' : 'none';
        compareMonth.style.display = value === 'monthly' ? 'block' : 'none';
    }
    
    filterType.addEventListener('change', toggleFilters);
    toggleFilters();
    
    // Add click handler for clickable accounts
    const clickableAccounts = document.querySelectorAll('.clickable-account');
    clickableAccounts.forEach(link => {
        link.addEventListener('click', function(e) {
            // The link already navigates, we just add a loading indicator
            const originalText = this.innerHTML;
            this.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Loading...';
            
            setTimeout(() => {
                this.innerHTML = originalText;
            }, 3000);
        });
    });
});
</script>

<?php include '../includes/footer.php'; ?>