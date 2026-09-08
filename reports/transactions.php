<?php
require_once '../config/config.php';
require_once '../auth/auth_middleware.php';
require_once '../tcpdf/tcpdf.php';

require_login();
$db = getDBConnection();

// Get ALL company details from database with correct column names
$company_stmt = $db->query("SELECT 
    company_code, 
    name, 
    registration_number, 
    address, 
    contact_person, 
    mobile, 
    email,
    phone,
    company_name,
    logo_path,
    header_image_path,
    footer_image_path
FROM companies WHERE status = 'active' LIMIT 1");
$company = $company_stmt->fetch();

// Use correct column names from your table
$company_name = $company ? $company['company_name'] : ($company ? $company['name'] : 'NEOVAM Limited');
$company_code = $company ? $company['company_code'] : 'B13/C';
$company_phone = $company ? $company['phone'] : ($company ? $company['mobile'] : '');
$company_address = $company ? $company['address'] : '';
$company_email = $company ? $company['email'] : '';
$company_contact_person = $company ? $company['contact_person'] : '';
$company_registration = $company ? $company['registration_number'] : '';

// Get filter parameters
$client = $_POST['client'] ?? '';
$agent = $_POST['agent'] ?? '';
$broker = $_POST['broker'] ?? '';
$security = $_POST['security'] ?? '';
$type = $_POST['trade_type'] ?? '';
$user = $_POST['user'] ?? '';
$status = $_POST['status'] ?? '';
$period_from = $_POST['period_from'] ?? '';
$period_to = $_POST['period_to'] ?? '';
$asset_class = $_POST['asset_class'] ?? '';
$contract_type = $_POST['contract_type'] ?? 'client';
$report_type = $_POST['report_type'] ?? 'detailed';
$contract_grouping = $_POST['contract_grouping'] ?? 'individual';
$report_by = $_POST['report_by'] ?? 'month';
$orientation = $_POST['orientation'] ?? 'landscape';
$watermark = $_POST['watermark'] ?? 'no';
$report_name = $_POST['report_name'] ?? '';
$export_type = $_POST['export_type'] ?? '';
$print_mode = $_POST['print_mode'] ?? '';
$chosen_date = $_POST['chosen_date'] ?? date('Y-m-d');

// ==================== HELPER FUNCTIONS ====================
function calculateBondFees($consideration) {
    $rates = [
        'brokerage' => 0.063,
        'dse' => 0.02006,
        'cmsa' => 0.01,
        'cds' => 0.0708,
        'vat' => 0.18
    ];
    
    $brokerage_commission = $consideration * ($rates['brokerage'] / 100);
    $dse_fee = $consideration * ($rates['dse'] / 100);
    $cmsa_fee = $consideration * ($rates['cmsa'] / 100);
    $cds_fee = $consideration * ($rates['cds'] / 100);
    $vat_on_commission = $brokerage_commission * $rates['vat'];
    
    return [
        'brokerage_commission' => $brokerage_commission,
        'dse_fee' => $dse_fee,
        'cmsa_fee' => $cmsa_fee,
        'cds_fee' => $cds_fee,
        'vat_on_commission' => $vat_on_commission,
        'total_charges' => $brokerage_commission + $dse_fee + $cmsa_fee + $cds_fee + $vat_on_commission
    ];
}

function calculateEquityFees($consideration, $price) {
    // Equity fee calculation (different from bonds)
    $rates = [
        'brokerage' => 1.5,  // 1.5% for equities
        'dse' => 0.02006,
        'cmsa' => 0.01,
        'cds' => 0.0708,
        'vat' => 0.18
    ];
    
    $brokerage_commission = $consideration * ($rates['brokerage'] / 100);
    $dse_fee = $consideration * ($rates['dse'] / 100);
    $cmsa_fee = $consideration * ($rates['cmsa'] / 100);
    $cds_fee = $consideration * ($rates['cds'] / 100);
    $vat_on_commission = $brokerage_commission * $rates['vat'];
    
    return [
        'brokerage_commission' => $brokerage_commission,
        'dse_fee' => $dse_fee,
        'cmsa_fee' => $cmsa_fee,
        'cds_fee' => $cds_fee,
        'vat_on_commission' => $vat_on_commission,
        'total_charges' => $brokerage_commission + $dse_fee + $cmsa_fee + $cds_fee + $vat_on_commission
    ];
}

// ==================== BONDS SUMMARY HTML ====================
function generateBondsSummaryHTML($client_balances, $company_name, $company_address, $company_phone, $company_email, $filters) {
    $period_from = date('d/m/Y', strtotime($filters['period_from'] ?? date('Y-01-01')));
    $period_to = date('d/m/Y', strtotime($filters['period_to'] ?? date('Y-m-d')));
    $chosen_date = date('d/m/Y', strtotime($filters['chosen_date'] ?? date('Y-m-d')));
    $current_date = date('d/m/Y');
    
    $html = '
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Bonds Transactions Summary Report</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
        <style>
            @media screen {
                .header { text-align: center; margin-bottom: 20px; border-bottom: 2px solid #dee2e6; padding-bottom: 15px; }
                .company-info { font-size: 12px; margin-bottom: 10px; color: #495057; line-height: 1.4; }
                .report-title { font-size: 18px; font-weight: bold; margin: 10px 0; color: #2c3e50; }
                .report-subtitle { font-size: 14px; margin: 5px 0; color: #6c757d; }
                .disclaimer { font-size: 11px; margin-top: 30px; padding: 15px; background-color: #f8f9fa; border-radius: 5px; color: #666; border-left: 4px solid #007bff; }
                table { width: 100%; border-collapse: collapse; font-size: 13px; margin: 10px 0; }
                th, td { border: 1px solid #dee2e6; padding: 8px; text-align: center; }
                th { background-color: #e9ecef; font-weight: bold; color: #495057; position: sticky; top: 0; z-index: 10; }
                .text-left { text-align: left; }
                .text-right { text-align: right; }
                .total-row { background-color: #f8f9fa; font-weight: bold; color: #007bff; }
                .positive { color: #28a745; font-weight: bold; }
                .negative { color: #dc3545; font-weight: bold; }
                .action-buttons { position: sticky; top: 0; background: white; padding: 10px 0; z-index: 1000; }
            }
            @media print {
                .header { text-align: center; margin-bottom: 5px; border-bottom: 1px solid #000; padding-bottom: 3px; }
                .company-info { font-size: 9px; margin-bottom: 5px; }
                .report-title { font-size: 14px; font-weight: bold; margin: 5px 0; }
                .report-subtitle { font-size: 10px; margin: 2px 0; }
                .disclaimer { font-size: 8px; margin-top: 15px; color: #666; }
                table { width: 100%; border-collapse: collapse; font-size: 10px; margin: 5px 0; }
                th, td { border: 1px solid #000; padding: 4px; text-align: center; }
                th { background-color: #f0f0f0; font-weight: bold; }
                .text-left { text-align: left; }
                .text-right { text-align: right; }
                .total-row { background-color: #f8f8f8; font-weight: bold; }
                .action-buttons { display: none !important; }
            }
            @media screen and (max-width: 768px) {
                .client-card {
                    border: 1px solid #dee2e6;
                    border-radius: 8px;
                    padding: 15px;
                    margin-bottom: 15px;
                    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
                }
                .card-row { margin-bottom: 8px; display: flex; justify-content: space-between; }
                .card-label { font-weight: bold; color: #6c757d; font-size: 12px; }
                .card-value { font-size: 14px; text-align: right; }
                table { display: none; }
                .mobile-view { display: block !important; }
                .summary-cards { display: block !important; }
            }
            @media screen and (min-width: 769px) {
                .mobile-view { display: none !important; }
                .summary-cards { display: none !important; }
            }
        </style>
    </head>
    <body>
        <div class="container-fluid">
            <div class="action-buttons mb-3">
                <div class="d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="fas fa-chart-bar me-2"></i>Bonds Transactions Summary</h5>
                    <div>
                        <button onclick="window.print()" class="btn btn-secondary btn-sm">
                            <i class="fas fa-print me-1"></i>Print
                        </button>
                        <a href="transactions.php?action=filter" class="btn btn-primary btn-sm ms-2">
                            <i class="fas fa-filter me-1"></i>New Filter
                        </a>
                    </div>
                </div>
            </div>
            
            <div class="header">
                <div style="margin-bottom: 10px;">
                    <img src="<?php echo BASE_URL; ?>assets/HeaderLogoVfsl.jpg" alt="VFSL Logo" style="height: 50px;">
                </div>
                <div class="company-info">
                    <strong style="color: #002e92; font-size: 14px;">' . strtoupper($company_name) . '</strong><br>
                    <span style="color: #cc0000; font-style: italic; font-size: 11px;">Stockbroker/Dealer, Fund Manager & Investment Advisor</span><br>
                    <span style="color: #002e92; font-size: 10px;">Members of the Dar Es Salaam Stock Exchange</span><br>
                    <span style="color: #666; font-size: 10px;">' . htmlspecialchars($company_address) . '</span><br>
                    <span style="color: #666; font-size: 10px;">Mob: +255 752 824 977 | Tel: +255 22 211 2691 | Email: info@vfsl.co.tz</span>
                </div>
                <div style="border-top: 2px solid #002e92; border-bottom: 1px solid #cc0000; padding: 5px 0; margin-top: 5px;"></div>
                <div class="report-title">BONDS TRANSACTIONS SUMMARY REPORT</div>
                <div class="report-subtitle">FOR DATE: ' . $chosen_date . '</div>
                <div class="report-subtitle">Date Printed: ' . $current_date . '</div>
            </div>
            
            <div style="background-color: #f8f9fa; border-left: 4px solid #002e92; padding: 10px 15px; margin: 10px 0; font-size: 11px; color: #333;">
                <strong>Report Overview:</strong> This Bonds Summary Report provides a consolidated view of all client positions in bond trading 
                for the period <strong>' . $period_from . '</strong> to <strong>' . $period_to . '</strong>. It summarizes total inflows (from sales) and outflows 
                (from purchases) for each client, showing their net exposure to the bond market.<br><br>
                <strong>How to Read:</strong> Each row represents one client. <strong>Inflow (SALES)</strong> is the total value of bonds sold by the client 
                (money coming in). <strong>Outflow (PURCHASES)</strong> is the total value of bonds bought by the client (money going out). 
                The <strong>Net Position</strong> shows the difference: positive means the client is a net seller (received more than spent), 
                negative means a net buyer (spent more than received). The <strong>Balance</strong> column shows the running cumulative total.<br><br>
                <strong>Key Insights:</strong> A large positive net position indicates profit-taking or portfolio reduction. 
                A large negative net position indicates accumulation or increased exposure. 
                Use this report for client portfolio reviews and position monitoring.
            </div>
            
            <div class="table-responsive">
                <table class="table table-bordered table-striped">
                    <thead>
                        <tr>
                            <th width="5%">#</th>
                            <th width="25%">Client Name</th>
                            <th width="15%">Client Account</th>
                            <th width="15%">Inflow (SALES)</th>
                            <th width="15%">Outflow (PURCHASES)</th>
                            <th width="15%">Net Position</th>
                            <th width="10%">Balance</th>
                        </tr>
                    </thead>
                    <tbody>';
    
    $counter = 1;
    $total_inflow = 0;
    $total_outflow = 0;
    $running_balance = 0;
    
    foreach ($client_balances as $client) {
        $running_balance += $client['net'];
        $total_inflow += $client['inflow'];
        $total_outflow += $client['outflow'];
        
        $net_class = $client['net'] >= 0 ? 'positive' : 'negative';
        $balance_class = $running_balance >= 0 ? 'positive' : 'negative';
        
        $html .= '
            <tr>
                <td>' . $counter . '</td>
                <td class="text-left">' . htmlspecialchars($client['client_name']) . '</td>
                <td class="text-left">' . htmlspecialchars($client['client_account']) . '</td>
                <td class="text-right">' . number_format($client['inflow'], 2) . '</td>
                <td class="text-right">' . number_format($client['outflow'], 2) . '</td>
                <td class="text-right ' . $net_class . '">' . number_format($client['net'], 2) . '</td>
                <td class="text-right ' . $balance_class . '">' . number_format($running_balance, 2) . '</td>
            </tr>';
        $counter++;
    }
    
    $total_net = $total_inflow - $total_outflow;
    $total_net_class = $total_net >= 0 ? 'positive' : 'negative';
    $running_balance_class = $running_balance >= 0 ? 'positive' : 'negative';
    
    $html .= '
            <tr class="total-row">
                <td colspan="3" class="text-left"><strong>TOTAL</strong></td>
                <td class="text-right"><strong>' . number_format($total_inflow, 2) . '</strong></td>
                <td class="text-right"><strong>' . number_format($total_outflow, 2) . '</strong></td>
                <td class="text-right ' . $total_net_class . '"><strong>' . number_format($total_net, 2) . '</strong></td>
                <td class="text-right ' . $running_balance_class . '"><strong>' . number_format($running_balance, 2) . '</strong></td>
            </tr>
        </tbody>
    </table>
    </div>
    
    <div class="mobile-view">';
    
    $running_balance_mobile = 0;
    foreach ($client_balances as $client) {
        $running_balance_mobile += $client['net'];
        $net_class = $client['net'] >= 0 ? 'positive' : 'negative';
        $balance_class = $running_balance_mobile >= 0 ? 'positive' : 'negative';
        
        $html .= '
        <div class="client-card">
            <div class="card-row">
                <span class="card-label">Client:</span>
                <span class="card-value">' . htmlspecialchars($client['client_name']) . '</span>
            </div>
            <div class="card-row">
                <span class="card-label">Account:</span>
                <span class="card-value">' . htmlspecialchars($client['client_account']) . '</span>
            </div>
            <div class="card-row">
                <span class="card-label">Inflow:</span>
                <span class="card-value positive">TZS ' . number_format($client['inflow'], 2) . '</span>
            </div>
            <div class="card-row">
                <span class="card-label">Outflow:</span>
                <span class="card-value negative">TZS ' . number_format($client['outflow'], 2) . '</span>
            </div>
            <div class="card-row">
                <span class="card-label">Net Position:</span>
                <span class="card-value ' . $net_class . '">TZS ' . number_format($client['net'], 2) . '</span>
            </div>
            <div class="card-row">
                <span class="card-label">Balance:</span>
                <span class="card-value ' . $balance_class . '">TZS ' . number_format($running_balance_mobile, 2) . '</span>
            </div>
        </div>';
    }
    
    $html .= '
    </div>
    
    <div class="row mt-4">
        <div class="col-md-4">
            <div class="card border-primary">
                <div class="card-header bg-primary">
                    <strong><i class="fas fa-users me-2"></i>Client Summary</strong>
                </div>
                <div class="card-body">
                    <p class="mb-1">Total Clients: <strong>' . ($counter - 1) . '</strong></p>
                    <p class="mb-1">Clients with Inflow: <strong>' . count(array_filter($client_balances, function($c) { return $c['inflow'] > 0; })) . '</strong></p>
                    <p class="mb-1">Clients with Outflow: <strong>' . count(array_filter($client_balances, function($c) { return $c['outflow'] > 0; })) . '</strong></p>
                    <p class="mb-0">Clients with Net Position: <strong>' . count(array_filter($client_balances, function($c) { return $c['net'] != 0; })) . '</strong></p>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card border-success">
                <div class="card-header bg-success">
                    <strong><i class="fas fa-money-bill-wave me-2"></i>Financial Summary</strong>
                </div>
                <div class="card-body">
                    <p class="mb-1">Total Inflow (Sales): <strong class="positive">TZS ' . number_format($total_inflow, 2) . '</strong></p>
                    <p class="mb-1">Total Outflow (Purchases): <strong class="negative">TZS ' . number_format($total_outflow, 2) . '</strong></p>
                    <p class="mb-1">Net Position: <strong class="' . $total_net_class . '">TZS ' . number_format($total_net, 2) . '</strong></p>
                    <p class="mb-0">Cumulative Balance: <strong class="' . $running_balance_class . '">TZS ' . number_format($running_balance, 2) . '</strong></p>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card border-info">
                <div class="card-header bg-info">
                    <strong><i class="fas fa-chart-pie me-2"></i>Statistics</strong>
                </div>
                <div class="card-body">
                    <p class="mb-1">Average Inflow per Client: <strong>TZS ' . number_format(($counter - 1) > 0 ? $total_inflow / ($counter - 1) : 0, 2) . '</strong></p>
                    <p class="mb-1">Average Outflow per Client: <strong>TZS ' . number_format(($counter - 1) > 0 ? $total_outflow / ($counter - 1) : 0, 2) . '</strong></p>
                    <p class="mb-1">Largest Inflow: <strong>TZS ' . number_format(max(array_column($client_balances, 'inflow')), 2) . '</strong></p>
                    <p class="mb-0">Largest Outflow: <strong>TZS ' . number_format(max(array_column($client_balances, 'outflow')), 2) . '</strong></p>
                </div>
            </div>
        </div>
    </div>
    
    <div class="disclaimer mt-4">
        <strong>Disclaimer:</strong> ' . $company_name . ' has prepared this Report solely for informational purposes. ' . $company_name . ' does not represent warrant or guarantee that the
        Reports are accurate. ' . $company_name . ' disclaims liability for any direct indirect punitive special consequential or incidental damages related to the Reports or the use
        of the Reports. This disclaimer applies to the Reports in their entirety irrespective of whether the Reports are used or viewed in whole or in part.
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    </body>
    </html>';
    
    return $html;
}

// ==================== EQUITIES SUMMARY HTML ====================
function generateEquitiesSummaryHTML($client_balances, $company_name, $company_address, $company_phone, $company_email, $filters) {
    $period_from = date('d/m/Y', strtotime($filters['period_from'] ?? date('Y-01-01')));
    $period_to = date('d/m/Y', strtotime($filters['period_to'] ?? date('Y-m-d')));
    $chosen_date = date('d/m/Y', strtotime($filters['chosen_date'] ?? date('Y-m-d')));
    $current_date = date('d/m/Y');
    
    $html = '
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Equities Transactions Summary Report</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
        <style>
            @media screen {
                .header { text-align: center; margin-bottom: 20px; border-bottom: 2px solid #dee2e6; padding-bottom: 15px; }
                .company-info { font-size: 12px; margin-bottom: 10px; color: #495057; line-height: 1.4; }
                .report-title { font-size: 18px; font-weight: bold; margin: 10px 0; color: #2c3e50; }
                .report-subtitle { font-size: 14px; margin: 5px 0; color: #6c757d; }
                .disclaimer { font-size: 11px; margin-top: 30px; padding: 15px; background-color: #f8f9fa; border-radius: 5px; color: #666; border-left: 4px solid #28a745; }
                table { width: 100%; border-collapse: collapse; font-size: 12px; margin: 10px 0; }
                th, td { border: 1px solid #dee2e6; padding: 8px; text-align: center; }
                th { background-color: #e9ecef; font-weight: bold; color: #495057; }
                .text-left { text-align: left; }
                .text-right { text-align: right; }
                .total-row { background-color: #f8f9fa; font-weight: bold; color: #28a745; }
                .positive { color: #28a745; font-weight: bold; }
                .negative { color: #dc3545; font-weight: bold; }
                .action-buttons { position: sticky; top: 0; background: white; padding: 10px 0; z-index: 1000; }
            }
            @media print {
                .header { text-align: center; margin-bottom: 5px; border-bottom: 1px solid #000; padding-bottom: 3px; }
                .company-info { font-size: 9px; margin-bottom: 5px; }
                .report-title { font-size: 14px; font-weight: bold; margin: 5px 0; }
                .report-subtitle { font-size: 10px; margin: 2px 0; }
                .disclaimer { font-size: 8px; margin-top: 15px; color: #666; }
                table { width: 100%; border-collapse: collapse; font-size: 10px; margin: 5px 0; }
                th, td { border: 1px solid #000; padding: 4px; text-align: center; }
                th { background-color: #f0f0f0; font-weight: bold; }
                .text-left { text-align: left; }
                .text-right { text-align: right; }
                .total-row { background-color: #f8f8f8; font-weight: bold; }
                .action-buttons { display: none !important; }
            }
        </style>
    </head>
    <body>
        <div class="container-fluid">
            <div class="action-buttons mb-3">
                <div class="d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="fas fa-chart-bar me-2"></i>Equities Transactions Summary</h5>
                    <div>
                        <button onclick="window.print()" class="btn btn-secondary btn-sm">
                            <i class="fas fa-print me-1"></i>Print
                        </button>
                        <a href="transactions.php?action=filter" class="btn btn-primary btn-sm ms-2">
                            <i class="fas fa-filter me-1"></i>New Filter
                        </a>
                    </div>
                </div>
            </div>
            
            <div class="header">
                <div style="margin-bottom: 10px;">
                    <img src="<?php echo BASE_URL; ?>assets/HeaderLogoVfsl.jpg" alt="VFSL Logo" style="height: 50px;">
                </div>
                <div class="company-info">
                    <strong style="color: #002e92; font-size: 14px;">' . strtoupper($company_name) . '</strong><br>
                    <span style="color: #cc0000; font-style: italic; font-size: 11px;">Stockbroker/Dealer, Fund Manager & Investment Advisor</span><br>
                    <span style="color: #002e92; font-size: 10px;">Members of the Dar Es Salaam Stock Exchange</span><br>
                    <span style="color: #666; font-size: 10px;">' . htmlspecialchars($company_address) . '</span><br>
                    <span style="color: #666; font-size: 10px;">Mob: +255 752 824 977 | Tel: +255 22 211 2691 | Email: info@vfsl.co.tz</span>
                </div>
                <div style="border-top: 2px solid #002e92; border-bottom: 1px solid #cc0000; padding: 5px 0; margin-top: 5px;"></div>
                <div class="report-title">EQUITIES TRANSACTIONS SUMMARY REPORT</div>
                <div class="report-subtitle">FOR DATE: ' . $chosen_date . '</div>
                <div class="report-subtitle">Date Printed: ' . $current_date . '</div>
            </div>
            
            <div style="background-color: #f8f9fa; border-left: 4px solid #28a745; padding: 10px 15px; margin: 10px 0; font-size: 11px; color: #333;">
                <strong>Report Overview:</strong> This Equities Summary Report provides a consolidated view of all client positions in share/equity 
                trading for the period <strong>' . $period_from . '</strong> to <strong>' . $period_to . '</strong>. It summarizes total inflows (from sales) and outflows 
                (from purchases) for each client, showing their net exposure to the equity market.<br><br>
                <strong>How to Read:</strong> Each row represents one client. <strong>Inflow (SALES)</strong> is the total value of shares sold 
                (money coming in). <strong>Outflow (PURCHASES)</strong> is the total value of shares bought (money going out). 
                The <strong>Net Position</strong> shows the difference: positive = net seller, negative = net buyer. 
                The <strong>Balance</strong> column shows the running cumulative total across all clients.<br><br>
                <strong>Key Insights:</strong> Monitor clients with large negative positions — they may need additional funds for settlement. 
                Clients with large positive positions may have cash available for new investments.
            </div>
            
            <div class="table-responsive">
                <table class="table table-bordered table-striped">
                    <thead>
                        <tr>
                            <th width="5%">#</th>
                            <th width="25%">Client Name</th>
                            <th width="15%">Client Account</th>
                            <th width="15%">Inflow (SALES)</th>
                            <th width="15%">Outflow (PURCHASES)</th>
                            <th width="15%">Net Position</th>
                            <th width="10%">Balance</th>
                        </tr>
                    </thead>
                    <tbody>';
    
    $counter = 1;
    $total_inflow = 0;
    $total_outflow = 0;
    $running_balance = 0;
    
    foreach ($client_balances as $client) {
        $running_balance += $client['net'];
        $total_inflow += $client['inflow'];
        $total_outflow += $client['outflow'];
        
        $net_class = $client['net'] >= 0 ? 'positive' : 'negative';
        $balance_class = $running_balance >= 0 ? 'positive' : 'negative';
        
        $html .= '
            <tr>
                <td>' . $counter . '</td>
                <td class="text-left">' . htmlspecialchars($client['client_name']) . '</td>
                <td class="text-left">' . htmlspecialchars($client['client_account']) . '</td>
                <td class="text-right">' . number_format($client['inflow'], 2) . '</td>
                <td class="text-right">' . number_format($client['outflow'], 2) . '</td>
                <td class="text-right ' . $net_class . '">' . number_format($client['net'], 2) . '</td>
                <td class="text-right ' . $balance_class . '">' . number_format($running_balance, 2) . '</td>
            </tr>';
        $counter++;
    }
    
    $total_net = $total_inflow - $total_outflow;
    $total_net_class = $total_net >= 0 ? 'positive' : 'negative';
    $running_balance_class = $running_balance >= 0 ? 'positive' : 'negative';
    
    $html .= '
            <tr class="total-row">
                <td colspan="3" class="text-left"><strong>TOTAL</strong></td>
                <td class="text-right"><strong>' . number_format($total_inflow, 2) . '</strong></td>
                <td class="text-right"><strong>' . number_format($total_outflow, 2) . '</strong></td>
                <td class="text-right ' . $total_net_class . '"><strong>' . number_format($total_net, 2) . '</strong></td>
                <td class="text-right ' . $running_balance_class . '"><strong>' . number_format($running_balance, 2) . '</strong></td>
            </tr>
        </tbody>
    </table>
    </div>
    
    <div class="mobile-view">';
    
    $running_balance_mobile = 0;
    foreach ($client_balances as $client) {
        $running_balance_mobile += $client['net'];
        $net_class = $client['net'] >= 0 ? 'positive' : 'negative';
        $balance_class = $running_balance_mobile >= 0 ? 'positive' : 'negative';
        
        $html .= '
        <div class="client-card">
            <div class="card-row">
                <span class="card-label">Client:</span>
                <span class="card-value">' . htmlspecialchars($client['client_name']) . '</span>
            </div>
            <div class="card-row">
                <span class="card-label">Account:</span>
                <span class="card-value">' . htmlspecialchars($client['client_account']) . '</span>
            </div>
            <div class="card-row">
                <span class="card-label">Inflow:</span>
                <span class="card-value positive">TZS ' . number_format($client['inflow'], 2) . '</span>
            </div>
            <div class="card-row">
                <span class="card-label">Outflow:</span>
                <span class="card-value negative">TZS ' . number_format($client['outflow'], 2) . '</span>
            </div>
            <div class="card-row">
                <span class="card-label">Net Position:</span>
                <span class="card-value ' . $net_class . '">TZS ' . number_format($client['net'], 2) . '</span>
            </div>
            <div class="card-row">
                <span class="card-label">Balance:</span>
                <span class="card-value ' . $balance_class . '">TZS ' . number_format($running_balance_mobile, 2) . '</span>
            </div>
        </div>';
    }
    
    $html .= '
    </div>
    
    <div class="row mt-4">
        <div class="col-md-4">
            <div class="card border-success">
                <div class="card-header bg-success">
                    <strong><i class="fas fa-users me-2"></i>Client Summary</strong>
                </div>
                <div class="card-body">
                    <p class="mb-1">Total Clients: <strong>' . ($counter - 1) . '</strong></p>
                    <p class="mb-1">Clients with Inflow: <strong>' . count(array_filter($client_balances, function($c) { return $c['inflow'] > 0; })) . '</strong></p>
                    <p class="mb-1">Clients with Outflow: <strong>' . count(array_filter($client_balances, function($c) { return $c['outflow'] > 0; })) . '</strong></p>
                    <p class="mb-0">Clients with Net Position: <strong>' . count(array_filter($client_balances, function($c) { return $c['net'] != 0; })) . '</strong></p>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card border-primary">
                <div class="card-header bg-primary">
                    <strong><i class="fas fa-money-bill-wave me-2"></i>Financial Summary</strong>
                </div>
                <div class="card-body">
                    <p class="mb-1">Total Inflow (Sales): <strong class="positive">TZS ' . number_format($total_inflow, 2) . '</strong></p>
                    <p class="mb-1">Total Outflow (Purchases): <strong class="negative">TZS ' . number_format($total_outflow, 2) . '</strong></p>
                    <p class="mb-1">Net Position: <strong class="' . $total_net_class . '">TZS ' . number_format($total_net, 2) . '</strong></p>
                    <p class="mb-0">Cumulative Balance: <strong class="' . $running_balance_class . '">TZS ' . number_format($running_balance, 2) . '</strong></p>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card border-info">
                <div class="card-header bg-info">
                    <strong><i class="fas fa-chart-pie me-2"></i>Statistics</strong>
                </div>
                <div class="card-body">
                    <p class="mb-1">Average Inflow per Client: <strong>TZS ' . number_format(($counter - 1) > 0 ? $total_inflow / ($counter - 1) : 0, 2) . '</strong></p>
                    <p class="mb-1">Average Outflow per Client: <strong>TZS ' . number_format(($counter - 1) > 0 ? $total_outflow / ($counter - 1) : 0, 2) . '</strong></p>
                    <p class="mb-1">Largest Inflow: <strong>TZS ' . number_format(max(array_column($client_balances, 'inflow')), 2) . '</strong></p>
                    <p class="mb-0">Largest Outflow: <strong>TZS ' . number_format(max(array_column($client_balances, 'outflow')), 2) . '</strong></p>
                </div>
            </div>
        </div>
    </div>
    
    <div class="disclaimer mt-4">
        <strong>Disclaimer:</strong> ' . $company_name . ' has prepared this Report solely for informational purposes. ' . $company_name . ' does not represent warrant or guarantee that the
        Reports are accurate. ' . $company_name . ' disclaims liability for any direct indirect punitive special consequential or incidental damages related to the Reports or the use
        of the Reports. This disclaimer applies to the Reports in their entirety irrespective of whether the Reports are used or viewed in whole or in part.
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    </body>
    </html>';
    
    return $html;
}

// ==================== COMBINED SUMMARY HTML ====================
function generateCombinedSummaryHTML($bond_balances, $equity_balances, $company_name, $company_address, $company_phone, $company_email, $filters) {
    $chosen_date = date('d/m/Y', strtotime($filters['chosen_date'] ?? date('Y-m-d')));
    $current_date = date('d/m/Y');
    
    $html = '
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Combined Transactions Summary Report</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
        <style>
            @media screen {
                .header { text-align: center; margin-bottom: 20px; border-bottom: 2px solid #dee2e6; padding-bottom: 15px; }
                .company-info { font-size: 12px; margin-bottom: 10px; color: #495057; line-height: 1.4; }
                .report-title { font-size: 18px; font-weight: bold; margin: 10px 0; color: #2c3e50; }
                .report-subtitle { font-size: 14px; margin: 5px 0; color: #6c757d; }
                .disclaimer { font-size: 11px; margin-top: 30px; padding: 15px; background-color: #f8f9fa; border-radius: 5px; color: #666; border-left: 4px solid #6c757d; }
                table { width: 100%; border-collapse: collapse; font-size: 12px; margin: 10px 0; }
                th, td { border: 1px solid #dee2e6; padding: 8px; text-align: center; }
                th { background-color: #e9ecef; font-weight: bold; color: #495057; }
                .text-left { text-align: left; }
                .text-right { text-align: right; }
                .total-row { background-color: #f8f9fa; font-weight: bold; }
                .bond-total { color: #007bff; }
                .equity-total { color: #28a745; }
                .overall-total { color: #6c757d; }
                .positive { color: #28a745; font-weight: bold; }
                .negative { color: #dc3545; font-weight: bold; }
                .action-buttons { position: sticky; top: 0; background: white; padding: 10px 0; z-index: 1000; }
                .asset-section { margin-bottom: 30px; }
            }
            @media print {
                .header { text-align: center; margin-bottom: 5px; border-bottom: 1px solid #000; padding-bottom: 3px; }
                .company-info { font-size: 9px; margin-bottom: 5px; }
                .report-title { font-size: 14px; font-weight: bold; margin: 5px 0; }
                .report-subtitle { font-size: 10px; margin: 2px 0; }
                .disclaimer { font-size: 8px; margin-top: 15px; color: #666; }
                table { width: 100%; border-collapse: collapse; font-size: 10px; margin: 5px 0; }
                th, td { border: 1px solid #000; padding: 4px; text-align: center; }
                th { background-color: #f0f0f0; font-weight: bold; }
                .text-left { text-align: left; }
                .text-right { text-align: right; }
                .action-buttons { display: none !important; }
            }
        </style>
    </head>
    <body>
        <div class="container-fluid">
            <div class="action-buttons mb-3">
                <div class="d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="fas fa-chart-bar me-2"></i>Combined Transactions Summary</h5>
                    <div>
                        <button onclick="window.print()" class="btn btn-secondary btn-sm">
                            <i class="fas fa-print me-1"></i>Print
                        </button>
                        <a href="transactions.php?action=filter" class="btn btn-primary btn-sm ms-2">
                            <i class="fas fa-filter me-1"></i>New Filter
                        </a>
                    </div>
                </div>
            </div>
            
            <div class="header">
                <div style="margin-bottom: 10px;">
                    <img src="<?php echo BASE_URL; ?>assets/HeaderLogoVfsl.jpg" alt="VFSL Logo" style="height: 50px;">
                </div>
                <div class="company-info">
                    <strong style="color: #002e92; font-size: 14px;">' . strtoupper($company_name) . '</strong><br>
                    <span style="color: #cc0000; font-style: italic; font-size: 11px;">Stockbroker/Dealer, Fund Manager & Investment Advisor</span><br>
                    <span style="color: #002e92; font-size: 10px;">Members of the Dar Es Salaam Stock Exchange</span><br>
                    <span style="color: #666; font-size: 10px;">' . htmlspecialchars($company_address) . '</span><br>
                    <span style="color: #666; font-size: 10px;">Mob: +255 752 824 977 | Tel: +255 22 211 2691 | Email: info@vfsl.co.tz</span>
                </div>
                <div style="border-top: 2px solid #002e92; border-bottom: 1px solid #cc0000; padding: 5px 0; margin-top: 5px;"></div>
                <div class="report-title">COMBINED TRANSACTIONS SUMMARY REPORT</div>
                <div class="report-subtitle">FOR DATE: ' . $chosen_date . '</div>
                <div class="report-subtitle">Date Printed: ' . $current_date . '</div>
            </div>
            
            <div style="background-color: #f8f9fa; border-left: 4px solid #6c757d; padding: 10px 15px; margin: 10px 0; font-size: 11px; color: #333;">
                <strong>Report Overview:</strong> This Combined Summary Report consolidates client positions across both Bonds and Shares (Equities) 
                for the period <strong>' . $chosen_date . '</strong>. It provides a complete overview of client exposure across all asset classes.<br><br>
                <strong>How to Read:</strong> The report is divided into two sections — <strong>Bonds</strong> (blue) and <strong>Shares</strong> (green). 
                Each section shows client-level inflow/outflow/net position summaries. The <strong>Overall Summary</strong> table at the bottom 
                combines both asset classes to show the total market exposure per client.<br><br>
                <strong>Purpose:</strong> Use this report for comprehensive portfolio reviews, risk assessment, and regulatory reporting. 
                It helps identify clients with significant exposure across multiple asset classes.
            </div>';
    
    // Bonds Summary Section
    if (!empty($bond_balances)) {
        $html .= '
            <div class="asset-section">
                <h5 class="border-bottom pb-2 mb-3" style="color: #007bff;">
                    <i class="fas fa-file-invoice-dollar me-2"></i>BONDS SUMMARY (' . count($bond_balances) . ' clients)
                </h5>
                <div class="table-responsive">
                    <table class="table table-bordered table-striped">
                        <thead>
                            <tr>
                                <th width="5%">#</th>
                                <th width="25%">Client Name</th>
                                <th width="15%">Client Account</th>
                                <th width="15%">Inflow (SALES)</th>
                                <th width="15%">Outflow (PURCHASES)</th>
                                <th width="15%">Net Position</th>
                                <th width="10%">Balance</th>
                            </tr>
                        </thead>
                        <tbody>';
        
        $counter = 1;
        $bond_total_inflow = 0;
        $bond_total_outflow = 0;
        $bond_running_balance = 0;
        
        foreach ($bond_balances as $client) {
            $bond_running_balance += $client['net'];
            $bond_total_inflow += $client['inflow'];
            $bond_total_outflow += $client['outflow'];
            
            $net_class = $client['net'] >= 0 ? 'positive' : 'negative';
            $balance_class = $bond_running_balance >= 0 ? 'positive' : 'negative';
            
            $html .= '
                <tr>
                    <td>' . $counter . '</td>
                    <td class="text-left">' . htmlspecialchars($client['client_name']) . '</td>
                    <td class="text-left">' . htmlspecialchars($client['client_account']) . '</td>
                    <td class="text-right">' . number_format($client['inflow'], 2) . '</td>
                    <td class="text-right">' . number_format($client['outflow'], 2) . '</td>
                    <td class="text-right ' . $net_class . '">' . number_format($client['net'], 2) . '</td>
                    <td class="text-right ' . $balance_class . '">' . number_format($bond_running_balance, 2) . '</td>
                </tr>';
            $counter++;
        }
        
        $bond_total_net = $bond_total_inflow - $bond_total_outflow;
        $bond_total_net_class = $bond_total_net >= 0 ? 'positive' : 'negative';
        $bond_running_balance_class = $bond_running_balance >= 0 ? 'positive' : 'negative';
        
        $html .= '
                        <tr class="total-row">
                            <td colspan="3" class="text-left"><strong>BONDS TOTAL</strong></td>
                            <td class="text-right"><strong>' . number_format($bond_total_inflow, 2) . '</strong></td>
                            <td class="text-right"><strong>' . number_format($bond_total_outflow, 2) . '</strong></td>
                            <td class="text-right ' . $bond_total_net_class . '"><strong>' . number_format($bond_total_net, 2) . '</strong></td>
                            <td class="text-right ' . $bond_running_balance_class . '"><strong>' . number_format($bond_running_balance, 2) . '</strong></td>
                        </tr>
                    </tbody>
                </table>
            </div>';
    }
    
    // Equities Summary Section
    if (!empty($equity_balances)) {
        $html .= '
            <div class="asset-section">
                <h5 class="border-bottom pb-2 mb-3" style="color: #28a745;">
                    <i class="fas fa-chart-line me-2"></i>SHARES (EQUITIES) SUMMARY (' . count($equity_balances) . ' clients)
                </h5>
                <div class="table-responsive">
                    <table class="table table-bordered table-striped">
                        <thead>
                            <tr>
                                <th width="5%">#</th>
                                <th width="25%">Client Name</th>
                                <th width="15%">Client Account</th>
                                <th width="15%">Inflow (SALES)</th>
                                <th width="15%">Outflow (PURCHASES)</th>
                                <th width="15%">Net Position</th>
                                <th width="10%">Balance</th>
                            </tr>
                        </thead>
                        <tbody>';
        
        $counter = 1;
        $equity_total_inflow = 0;
        $equity_total_outflow = 0;
        $equity_running_balance = 0;
        
        foreach ($equity_balances as $client) {
            $equity_running_balance += $client['net'];
            $equity_total_inflow += $client['inflow'];
            $equity_total_outflow += $client['outflow'];
            
            $net_class = $client['net'] >= 0 ? 'positive' : 'negative';
            $balance_class = $equity_running_balance >= 0 ? 'positive' : 'negative';
            
            $html .= '
                <tr>
                    <td>' . $counter . '</td>
                    <td class="text-left">' . htmlspecialchars($client['client_name']) . '</td>
                    <td class="text-left">' . htmlspecialchars($client['client_account']) . '</td>
                    <td class="text-right">' . number_format($client['inflow'], 2) . '</td>
                    <td class="text-right">' . number_format($client['outflow'], 2) . '</td>
                    <td class="text-right ' . $net_class . '">' . number_format($client['net'], 2) . '</td>
                    <td class="text-right ' . $balance_class . '">' . number_format($equity_running_balance, 2) . '</td>
                </tr>';
            $counter++;
        }
        
        $equity_total_net = $equity_total_inflow - $equity_total_outflow;
        $equity_total_net_class = $equity_total_net >= 0 ? 'positive' : 'negative';
        $equity_running_balance_class = $equity_running_balance >= 0 ? 'positive' : 'negative';
        
        $html .= '
                        <tr class="total-row">
                            <td colspan="3" class="text-left"><strong>SHARES TOTAL</strong></td>
                            <td class="text-right"><strong>' . number_format($equity_total_inflow, 2) . '</strong></td>
                            <td class="text-right"><strong>' . number_format($equity_total_outflow, 2) . '</strong></td>
                            <td class="text-right ' . $equity_total_net_class . '"><strong>' . number_format($equity_total_net, 2) . '</strong></td>
                            <td class="text-right ' . $equity_running_balance_class . '"><strong>' . number_format($equity_running_balance, 2) . '</strong></td>
                        </tr>
                    </tbody>
                </table>
            </div>';
    }
    
    // Mobile card view for bonds
    if (!empty($bond_balances)) {
        $html .= '
            <div class="mobile-view">
                <h5 class="border-bottom pb-2 mb-3" style="color: #007bff;">
                    <i class="fas fa-file-invoice-dollar me-2"></i>BONDS SUMMARY (' . count($bond_balances) . ' clients)
                </h5>';
        foreach ($bond_balances as $client) {
            $net_class = $client['net'] >= 0 ? 'positive' : 'negative';
            $html .= '
            <div class="client-card">
                <div class="card-row">
                    <span class="card-label">Client:</span>
                    <span class="card-value">' . htmlspecialchars($client['client_name']) . '</span>
                </div>
                <div class="card-row">
                    <span class="card-label">Account:</span>
                    <span class="card-value">' . htmlspecialchars($client['client_account']) . '</span>
                </div>
                <div class="card-row">
                    <span class="card-label">Inflow:</span>
                    <span class="card-value positive">TZS ' . number_format($client['inflow'], 2) . '</span>
                </div>
                <div class="card-row">
                    <span class="card-label">Outflow:</span>
                    <span class="card-value negative">TZS ' . number_format($client['outflow'], 2) . '</span>
                </div>
                <div class="card-row">
                    <span class="card-label">Net Position:</span>
                    <span class="card-value ' . $net_class . '">TZS ' . number_format($client['net'], 2) . '</span>
                </div>
            </div>';
        }
        $html .= '</div>';
    }

    // Mobile card view for equities
    if (!empty($equity_balances)) {
        $html .= '
            <div class="mobile-view">
                <h5 class="border-bottom pb-2 mb-3" style="color: #28a745;">
                    <i class="fas fa-chart-line me-2"></i>SHARES (EQUITIES) SUMMARY (' . count($equity_balances) . ' clients)
                </h5>';
        foreach ($equity_balances as $client) {
            $net_class = $client['net'] >= 0 ? 'positive' : 'negative';
            $html .= '
            <div class="client-card">
                <div class="card-row">
                    <span class="card-label">Client:</span>
                    <span class="card-value">' . htmlspecialchars($client['client_name']) . '</span>
                </div>
                <div class="card-row">
                    <span class="card-label">Account:</span>
                    <span class="card-value">' . htmlspecialchars($client['client_account']) . '</span>
                </div>
                <div class="card-row">
                    <span class="card-label">Inflow:</span>
                    <span class="card-value positive">TZS ' . number_format($client['inflow'], 2) . '</span>
                </div>
                <div class="card-row">
                    <span class="card-label">Outflow:</span>
                    <span class="card-value negative">TZS ' . number_format($client['outflow'], 2) . '</span>
                </div>
                <div class="card-row">
                    <span class="card-label">Net Position:</span>
                    <span class="card-value ' . $net_class . '">TZS ' . number_format($client['net'], 2) . '</span>
                </div>
            </div>';
        }
        $html .= '</div>';
    }
    
    // Overall Summary Section
    if (!empty($bond_balances) && !empty($equity_balances)) {
        $total_inflow = $bond_total_inflow + $equity_total_inflow;
        $total_outflow = $bond_total_outflow + $equity_total_outflow;
        $total_net = $bond_total_net + $equity_total_net;
        $total_balance = $bond_running_balance + $equity_running_balance;
        
        $total_net_class = $total_net >= 0 ? 'positive' : 'negative';
        $total_balance_class = $total_balance >= 0 ? 'positive' : 'negative';
        
        $html .= '
            <div class="row mt-4">
                <div class="col-md-12">
                    <div class="card border-dark">
                        <div class="card-header bg-dark py-2">
                            <strong><i class="fas fa-calculator me-2"></i>OVERALL SUMMARY</strong>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-4">
                                    <h6 class="border-bottom pb-1">Transaction Summary</h6>
                                    <p class="mb-1">Total Clients: <strong>' . (count($bond_balances) + count($equity_balances)) . '</strong></p>
                                    <p class="mb-1">Bond Clients: <strong>' . count($bond_balances) . '</strong></p>
                                    <p class="mb-0">Share Clients: <strong>' . count($equity_balances) . '</strong></p>
                                </div>
                                <div class="col-md-4">
                                    <h6 class="border-bottom pb-1">Financial Summary</h6>
                                    <p class="mb-1">Total Inflow: <strong class="positive">TZS ' . number_format($total_inflow, 2) . '</strong></p>
                                    <p class="mb-1">Total Outflow: <strong class="negative">TZS ' . number_format($total_outflow, 2) . '</strong></p>
                                    <p class="mb-0">Net Position: <strong class="' . $total_net_class . '">TZS ' . number_format($total_net, 2) . '</strong></p>
                                </div>
                                <div class="col-md-4">
                                    <h6 class="border-bottom pb-1">Balance Summary</h6>
                                    <p class="mb-1">Bonds Balance: <strong class="' . $bond_running_balance_class . '">TZS ' . number_format($bond_running_balance, 2) . '</strong></p>
                                    <p class="mb-1">Shares Balance: <strong class="' . $equity_running_balance_class . '">TZS ' . number_format($equity_running_balance, 2) . '</strong></p>
                                    <p class="mb-0">Total Balance: <strong class="' . $total_balance_class . '">TZS ' . number_format($total_balance, 2) . '</strong></p>
                                </div>
                            </div>
                            <div class="row mt-3">
                                <div class="col-md-12">
                                    <div class="table-responsive">
                                        <table class="table table-bordered">
                                            <thead>
                                                <tr>
                                                    <th>Asset Type</th>
                                                    <th>Number of Clients</th>
                                                    <th>Total Inflow (Sales)</th>
                                                    <th>Total Outflow (Purchases)</th>
                                                    <th>Net Position</th>
                                                    <th>Cumulative Balance</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <tr>
                                                    <td><strong>Bonds</strong></td>
                                                    <td class="text-center">' . count($bond_balances) . '</td>
                                                    <td class="text-right positive">TZS ' . number_format($bond_total_inflow, 2) . '</td>
                                                    <td class="text-right negative">TZS ' . number_format($bond_total_outflow, 2) . '</td>
                                                    <td class="text-right ' . $bond_total_net_class . '">TZS ' . number_format($bond_total_net, 2) . '</td>
                                                    <td class="text-right ' . $bond_running_balance_class . '">TZS ' . number_format($bond_running_balance, 2) . '</td>
                                                </tr>
                                                <tr>
                                                    <td><strong>Shares</strong></td>
                                                    <td class="text-center">' . count($equity_balances) . '</td>
                                                    <td class="text-right positive">TZS ' . number_format($equity_total_inflow, 2) . '</td>
                                                    <td class="text-right negative">TZS ' . number_format($equity_total_outflow, 2) . '</td>
                                                    <td class="text-right ' . $equity_total_net_class . '">TZS ' . number_format($equity_total_net, 2) . '</td>
                                                    <td class="text-right ' . $equity_running_balance_class . '">TZS ' . number_format($equity_running_balance, 2) . '</td>
                                                </tr>
                                                <tr class="total-row">
                                                    <td><strong>TOTAL</strong></td>
                                                    <td class="text-center"><strong>' . (count($bond_balances) + count($equity_balances)) . '</strong></td>
                                                    <td class="text-right positive"><strong>TZS ' . number_format($total_inflow, 2) . '</strong></td>
                                                    <td class="text-right negative"><strong>TZS ' . number_format($total_outflow, 2) . '</strong></td>
                                                    <td class="text-right ' . $total_net_class . '"><strong>TZS ' . number_format($total_net, 2) . '</strong></td>
                                                    <td class="text-right ' . $total_balance_class . '"><strong>TZS ' . number_format($total_balance, 2) . '</strong></td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>';
    }
    
    $html .= '
            <div class="disclaimer mt-4">
                <strong>Disclaimer:</strong> ' . $company_name . ' has prepared this Report solely for informational purposes. ' . $company_name . ' does not represent warrant or guarantee that the
                Reports are accurate. ' . $company_name . ' disclaims liability for any direct indirect punitive special consequential or incidental damages related to the Reports or the use
                of the Reports. This disclaimer applies to the Reports in their entirety irrespective of whether the Reports are used or viewed in whole or in part.
            </div>
        </div>
        
        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    </body>
    </html>';
    
    return $html;
}

// ==================== REPORT GENERATORS ====================
function generateBondsSummaryReport($db, $company_name, $company_address, $company_phone, $company_email, $filters) {
    $where_conditions = ["t.asset_class = 'bond'", "t.status = 'active'"];
    $params = [];

    // Use chosen date if specified
    if (!empty($filters['chosen_date'])) {
        $where_conditions[] = "DATE(t.trade_date) = ?";
        $params[] = $filters['chosen_date'];
    } else {
        // Date range filter
        if (!empty($filters['period_from'])) {
            $where_conditions[] = "t.trade_date >= ?";
            $params[] = $filters['period_from'];
        }
        if (!empty($filters['period_to'])) {
            $where_conditions[] = "t.trade_date <= ?";
            $params[] = $filters['period_to'];
        }
    }

    $where_clause = implode(' AND ', $where_conditions);

    // Get bond transactions grouped by client
    $stmt = $db->prepare("
        SELECT 
            t.client_name,
            t.client_cds_account,
            t.trade_side,
            SUM(t.quantity) as total_quantity,
            SUM(t.consideration) as total_consideration,
            COUNT(*) as transaction_count
        FROM trades t
        WHERE $where_clause
        GROUP BY t.client_name, t.client_cds_account, t.trade_side
        ORDER BY t.client_name, t.trade_side
    ");
    $stmt->execute($params);
    $client_transactions = $stmt->fetchAll();

    if (empty($client_transactions)) {
        echo '<div class="container py-5">
                <div class="alert alert-info text-center py-5">
                    <i class="fas fa-info-circle fa-3x text-muted mb-3"></i>
                    <h5>No Bond Transactions Found</h5>
                    <p class="text-muted">No bond trades match your selected criteria. Please adjust your filters and try again.</p>
                    <a href="transactions.php?action=filter" class="btn btn-primary mt-3">
                        <i class="fas fa-filter me-2"></i>Apply New Filter
                    </a>
                </div>
              </div>';
        return;
    }

    // Calculate client balances - Inflow from SALES, Outflow from BUY
    $client_balances = [];
    foreach ($client_transactions as $transaction) {
        $client_key = $transaction['client_name'] . '|' . $transaction['client_cds_account'];
        
        if (!isset($client_balances[$client_key])) {
            $client_balances[$client_key] = [
                'client_name' => $transaction['client_name'],
                'client_account' => $transaction['client_cds_account'],
                'inflow' => 0,
                'outflow' => 0,
                'net' => 0
            ];
        }
        
        if (strtoupper($transaction['trade_side']) === 'SELL') {
            $client_balances[$client_key]['inflow'] += $transaction['total_consideration'];
        } else {
            $client_balances[$client_key]['outflow'] += $transaction['total_consideration'];
        }
        
        $client_balances[$client_key]['net'] = 
            $client_balances[$client_key]['inflow'] - $client_balances[$client_key]['outflow'];
    }

    // Sort by client name
    usort($client_balances, function($a, $b) {
        return strcmp($a['client_name'], $b['client_name']);
    });

    // Check if we're exporting to PDF
    if (isset($_POST['export_type']) && $_POST['export_type'] === 'pdf') {
        // Create PDF in PORTRAIT orientation (better for summary reports)
        $pdf = new TCPDF('P', PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
        $pdf->SetCreator($company_name);
        $pdf->SetAuthor($company_name);
        $pdf->SetTitle('Bonds Transactions Summary Report');
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(10, 10, 10);
        $pdf->SetAutoPageBreak(TRUE, 15);
        $pdf->AddPage();

        $html = generateBondsSummaryHTML($client_balances, $company_name, $company_address, $company_phone, $company_email, $filters);
        $pdf->writeHTML($html, true, false, true, false, '');
        $pdf->Output('bonds_summary_' . date('Y_m_d') . '.pdf', 'I');
    } else {
        // Display HTML report
        echo generateBondsSummaryHTML($client_balances, $company_name, $company_address, $company_phone, $company_email, $filters);
    }
}

function generateEquitiesSummaryReport($db, $company_name, $company_address, $company_phone, $company_email, $filters) {
    $where_conditions = ["t.asset_class = 'equity'", "t.status = 'active'"];
    $params = [];

    // Use chosen date if specified
    if (!empty($filters['chosen_date'])) {
        $where_conditions[] = "DATE(t.trade_date) = ?";
        $params[] = $filters['chosen_date'];
    } else {
        // Date range filter
        if (!empty($filters['period_from'])) {
            $where_conditions[] = "t.trade_date >= ?";
            $params[] = $filters['period_from'];
        }
        if (!empty($filters['period_to'])) {
            $where_conditions[] = "t.trade_date <= ?";
            $params[] = $filters['period_to'];
        }
    }

    $where_clause = implode(' AND ', $where_conditions);

    // Get equity transactions grouped by client
    $stmt = $db->prepare("
        SELECT 
            t.client_name,
            t.client_cds_account,
            t.trade_side,
            SUM(t.quantity) as total_quantity,
            SUM(t.consideration) as total_consideration,
            COUNT(*) as transaction_count
        FROM trades t
        WHERE $where_clause
        GROUP BY t.client_name, t.client_cds_account, t.trade_side
        ORDER BY t.client_name, t.trade_side
    ");
    $stmt->execute($params);
    $client_transactions = $stmt->fetchAll();

    if (empty($client_transactions)) {
        echo '<div class="container py-5">
                <div class="alert alert-info text-center py-5">
                    <i class="fas fa-info-circle fa-3x text-muted mb-3"></i>
                    <h5>No Equity Transactions Found</h5>
                    <p class="text-muted">No equity trades match your selected criteria. Please adjust your filters and try again.</p>
                    <a href="transactions.php?action=filter" class="btn btn-primary mt-3">
                        <i class="fas fa-filter me-2"></i>Apply New Filter
                    </a>
                </div>
              </div>';
        return;
    }

    // Calculate client balances - Inflow from SALES, Outflow from BUY
    $client_balances = [];
    foreach ($client_transactions as $transaction) {
        $client_key = $transaction['client_name'] . '|' . $transaction['client_cds_account'];
        
        if (!isset($client_balances[$client_key])) {
            $client_balances[$client_key] = [
                'client_name' => $transaction['client_name'],
                'client_account' => $transaction['client_cds_account'],
                'inflow' => 0,
                'outflow' => 0,
                'net' => 0
            ];
        }
        
        if (strtoupper($transaction['trade_side']) === 'SELL') {
            $client_balances[$client_key]['inflow'] += $transaction['total_consideration'];
        } else {
            $client_balances[$client_key]['outflow'] += $transaction['total_consideration'];
        }
        
        $client_balances[$client_key]['net'] = 
            $client_balances[$client_key]['inflow'] - $client_balances[$client_key]['outflow'];
    }

    // Sort by client name
    usort($client_balances, function($a, $b) {
        return strcmp($a['client_name'], $b['client_name']);
    });

    // Check if we're exporting to PDF
    if (isset($_POST['export_type']) && $_POST['export_type'] === 'pdf') {
        // Create PDF in PORTRAIT orientation (better for summary reports)
        $pdf = new TCPDF('P', PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
        $pdf->SetCreator($company_name);
        $pdf->SetAuthor($company_name);
        $pdf->SetTitle('Equities Transactions Summary Report');
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(10, 10, 10);
        $pdf->SetAutoPageBreak(TRUE, 15);
        $pdf->AddPage();

        $html = generateEquitiesSummaryHTML($client_balances, $company_name, $company_address, $company_phone, $company_email, $filters);
        $pdf->writeHTML($html, true, false, true, false, '');
        $pdf->Output('equities_summary_' . date('Y_m_d') . '.pdf', 'I');
    } else {
        // Display HTML report
        echo generateEquitiesSummaryHTML($client_balances, $company_name, $company_address, $company_phone, $company_email, $filters);
    }
}

// ==================== DETAILED REPORTS ====================
function generateBondsDetailedReport($db, $company_name, $company_address, $company_phone, $company_email, $filters) {
    $where_conditions = ["t.asset_class = 'bond'", "t.status = 'active'"];
    $params = [];

    if (!empty($filters['period_from']) && !empty($filters['period_to'])) {
        $where_conditions[] = "DATE(t.trade_date) BETWEEN ? AND ?";
        $params[] = $filters['period_from'];
        $params[] = $filters['period_to'];
    } elseif (!empty($filters['chosen_date'])) {
        $where_conditions[] = "DATE(t.trade_date) = ?";
        $params[] = $filters['chosen_date'];
    }

    $where_clause = implode(' AND ', $where_conditions);

    $stmt = $db->prepare("
        SELECT 
            t.trade_date,
            t.client_name,
            t.client_cds_account,
            t.security_name,
            t.trade_side,
            t.quantity,
            t.price,
            t.consideration,
            t.trade_id
        FROM trades t
        WHERE $where_clause
        ORDER BY t.trade_date DESC, t.client_name, t.trade_side
    ");
    $stmt->execute($params);
    $transactions = $stmt->fetchAll();

    if (empty($transactions)) {
        echo '<div class="container py-5"><div class="alert alert-info text-center py-5">
            <i class="fas fa-info-circle fa-3x text-muted mb-3"></i>
            <h5>No Bond Transactions Found</h5>
            <p class="text-muted">No bond trades match your selected criteria.</p>
            <a href="transactions.php?action=filter" class="btn btn-primary mt-3">
                <i class="fas fa-filter me-2"></i>Apply New Filter
            </a>
        </div></div>';
        return;
    }

    echo generateDetailedHTML($transactions, 'Bonds', 'bond', $company_name, $company_address, $company_phone, $company_email, $filters);
}

function generateEquitiesDetailedReport($db, $company_name, $company_address, $company_phone, $company_email, $filters) {
    $where_conditions = ["t.asset_class = 'equity'", "t.status = 'active'"];
    $params = [];

    if (!empty($filters['period_from']) && !empty($filters['period_to'])) {
        $where_conditions[] = "DATE(t.trade_date) BETWEEN ? AND ?";
        $params[] = $filters['period_from'];
        $params[] = $filters['period_to'];
    } elseif (!empty($filters['chosen_date'])) {
        $where_conditions[] = "DATE(t.trade_date) = ?";
        $params[] = $filters['chosen_date'];
    }

    $where_clause = implode(' AND ', $where_conditions);

    $stmt = $db->prepare("
        SELECT 
            t.trade_date,
            t.client_name,
            t.client_cds_account,
            t.security_name,
            t.trade_side,
            t.quantity,
            t.price,
            t.consideration,
            t.trade_id
        FROM trades t
        WHERE $where_clause
        ORDER BY t.trade_date DESC, t.client_name, t.trade_side
    ");
    $stmt->execute($params);
    $transactions = $stmt->fetchAll();

    if (empty($transactions)) {
        echo '<div class="container py-5"><div class="alert alert-info text-center py-5">
            <i class="fas fa-info-circle fa-3x text-muted mb-3"></i>
            <h5>No Equity Transactions Found</h5>
            <p class="text-muted">No equity trades match your selected criteria.</p>
            <a href="transactions.php?action=filter" class="btn btn-primary mt-3">
                <i class="fas fa-filter me-2"></i>Apply New Filter
            </a>
        </div></div>';
        return;
    }

    echo generateDetailedHTML($transactions, 'Equities', 'equity', $company_name, $company_address, $company_phone, $company_email, $filters);
}

function generateCombinedDetailedReport($db, $company_name, $company_address, $company_phone, $company_email, $filters) {
    $where_conditions = ["t.status = 'active'"];
    $params = [];

    if (!empty($filters['period_from']) && !empty($filters['period_to'])) {
        $where_conditions[] = "DATE(t.trade_date) BETWEEN ? AND ?";
        $params[] = $filters['period_from'];
        $params[] = $filters['period_to'];
    } elseif (!empty($filters['chosen_date'])) {
        $where_conditions[] = "DATE(t.trade_date) = ?";
        $params[] = $filters['chosen_date'];
    }

    $where_clause = implode(' AND ', $where_conditions);

    $stmt = $db->prepare("
        SELECT 
            t.trade_date,
            t.client_name,
            t.client_cds_account,
            t.security_name,
            t.asset_class,
            t.trade_side,
            t.quantity,
            t.price,
            t.consideration,
            t.trade_id
        FROM trades t
        WHERE $where_clause
        ORDER BY t.trade_date DESC, t.asset_class, t.client_name, t.trade_side
    ");
    $stmt->execute($params);
    $transactions = $stmt->fetchAll();

    if (empty($transactions)) {
        echo '<div class="container py-5"><div class="alert alert-info text-center py-5">
            <i class="fas fa-info-circle fa-3x text-muted mb-3"></i>
            <h5>No Transactions Found</h5>
            <p class="text-muted">No trades match your selected criteria.</p>
            <a href="transactions.php?action=filter" class="btn btn-primary mt-3">
                <i class="fas fa-filter me-2"></i>Apply New Filter
            </a>
        </div></div>';
        return;
    }

    echo generateDetailedHTML($transactions, 'Combined', 'combined', $company_name, $company_address, $company_phone, $company_email, $filters);
}

function generateDetailedHTML($transactions, $report_title, $asset_type, $company_name, $company_address, $company_phone, $company_email, $filters) {
    $period_from = !empty($filters['period_from']) ? date('d/m/Y', strtotime($filters['period_from'])) : date('d/m/Y', strtotime($filters['chosen_date'] ?? date('Y-m-d')));
    $period_to = !empty($filters['period_to']) ? date('d/m/Y', strtotime($filters['period_to'])) : $period_from;
    $current_date = date('d/m/Y');
    
    $color_code = $asset_type === 'bond' ? '#007bff' : ($asset_type === 'equity' ? '#28a745' : '#6c757d');
    
    $html = '
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>' . $report_title . ' Detailed Transaction Report</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
        <style>
            @media screen {
                .header { text-align: center; margin-bottom: 20px; border-bottom: 2px solid #dee2e6; padding-bottom: 15px; }
                .company-info { font-size: 12px; margin-bottom: 10px; color: #495057; line-height: 1.4; }
                .report-title { font-size: 18px; font-weight: bold; margin: 10px 0; color: #2c3e50; }
                .report-subtitle { font-size: 14px; margin: 5px 0; color: #6c757d; }
                .disclaimer { font-size: 11px; margin-top: 30px; padding: 15px; background-color: #f8f9fa; border-radius: 5px; color: #666; border-left: 4px solid ' . $color_code . '; }
                .table-responsive { overflow-x: auto; }
                table { width: 100%; border-collapse: collapse; font-size: 13px; margin: 10px 0; }
                th, td { border: 1px solid #dee2e6; padding: 8px; text-align: center; }
                th { background-color: #e9ecef; font-weight: bold; color: #495057; position: sticky; top: 0; z-index: 10; }
                .text-left { text-align: left; }
                .text-right { text-align: right; }
                .buy-row { background-color: #fff5f5; }
                .sell-row { background-color: #f0fff4; }
                .action-buttons { position: sticky; top: 0; background: white; padding: 10px 0; z-index: 1000; }
                .badge-sell { background-color: #28a745; color: white; padding: 4px 8px; border-radius: 4px; font-size: 11px; }
                .badge-buy { background-color: #dc3545; color: white; padding: 4px 8px; border-radius: 4px; font-size: 11px; }
            }
            @media print {
                .header { text-align: center; margin-bottom: 5px; border-bottom: 1px solid #000; padding-bottom: 3px; }
                .company-info { font-size: 9px; margin-bottom: 5px; }
                .report-title { font-size: 14px; font-weight: bold; margin: 5px 0; }
                .report-subtitle { font-size: 10px; margin: 2px 0; }
                .disclaimer { font-size: 8px; margin-top: 15px; color: #666; }
                table { width: 100%; border-collapse: collapse; font-size: 10px; margin: 5px 0; }
                th, td { border: 1px solid #000; padding: 4px; text-align: center; }
                th { background-color: #f0f0f0; font-weight: bold; }
                .text-left { text-align: left; }
                .text-right { text-align: right; }
                .action-buttons { display: none !important; }
            }
            @media screen and (max-width: 768px) {
                .transaction-card {
                    border: 1px solid #dee2e6;
                    border-radius: 8px;
                    padding: 15px;
                    margin-bottom: 15px;
                    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
                }
                .card-row { margin-bottom: 8px; }
                .card-label { font-weight: bold; color: #6c757d; font-size: 12px; }
                .card-value { font-size: 14px; }
                table { display: none; }
                .mobile-view { display: block !important; }
            }
            @media screen and (min-width: 769px) {
                .mobile-view { display: none !important; }
            }
        </style>
    </head>
    <body>
        <div class="container-fluid">
            <div class="action-buttons mb-3">
                <div class="d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="fas fa-list me-2"></i>' . $report_title . ' Detailed Transactions</h5>
                    <div>
                        <button onclick="window.print()" class="btn btn-secondary btn-sm">
                            <i class="fas fa-print me-1"></i>Print
                        </button>
                        <a href="transactions.php?action=filter" class="btn btn-primary btn-sm ms-2">
                            <i class="fas fa-filter me-1"></i>New Filter
                        </a>
                    </div>
                </div>
            </div>
            
            <div class="header">
                <div style="margin-bottom: 10px;">
                    <img src="<?php echo BASE_URL; ?>assets/HeaderLogoVfsl.jpg" alt="VFSL Logo" style="height: 50px;">
                </div>
                <div class="company-info">
                    <strong style="color: #002e92; font-size: 14px;">' . strtoupper($company_name) . '</strong><br>
                    <span style="color: #cc0000; font-style: italic; font-size: 11px;">Stockbroker/Dealer, Fund Manager & Investment Advisor</span><br>
                    <span style="color: #002e92; font-size: 10px;">Members of the Dar Es Salaam Stock Exchange</span><br>
                    <span style="color: #666; font-size: 10px;">' . htmlspecialchars($company_address) . '</span><br>
                    <span style="color: #666; font-size: 10px;">Mob: +255 752 824 977 | Tel: +255 22 211 2691 | Email: info@vfsl.co.tz</span>
                </div>
                <div style="border-top: 2px solid #002e92; border-bottom: 1px solid #cc0000; padding: 5px 0; margin-top: 5px;"></div>
                <div class="report-title">' . strtoupper($report_title) . ' DETAILED TRANSACTION REPORT</div>
                <div class="report-subtitle">Period: ' . $period_from . ' to ' . $period_to . '</div>
                <div class="report-subtitle">Date Printed: ' . $current_date . '</div>
            </div>
            
            <div style="background-color: #f8f9fa; border-left: 4px solid ' . $color_code . '; padding: 10px 15px; margin: 10px 0; font-size: 11px; color: #333;">
                <strong>Report Overview:</strong> This detailed transaction report shows every individual trade transaction for the selected period.
                <strong>Total Transactions:</strong> ' . count($transactions) . ' trades.
                <strong>How to Read:</strong> Each row represents one transaction. <span class="badge-sell">SELL</span> transactions are highlighted in green (money coming in). 
                <span class="badge-buy">BUY</span> transactions are highlighted in red (money going out).
            </div>
            
            <div class="table-responsive">
                <table class="table table-bordered table-striped">
                    <thead>
                        <tr>
                            <th width="3%">#</th>
                            <th width="10%">Date</th>
                            <th width="20%">Client Name</th>
                            <th width="10%">Account</th>
                            <th width="15%">Security</th>' . ($asset_type === 'combined' ? '<th width="8%">Asset</th>' : '') . 
                            '<th width="8%">Side</th>
                            <th width="10%">Quantity</th>
                            <th width="10%">Price</th>
                            <th width="12%">Consideration</th>
                        </tr>
                    </thead>
                    <tbody>';
    
    $counter = 1;
    $total_inflow = 0;
    $total_outflow = 0;
    
    foreach ($transactions as $trans) {
        $row_class = strtoupper($trans['trade_side']) === 'SELL' ? 'sell-row' : 'buy-row';
        $badge_class = strtoupper($trans['trade_side']) === 'SELL' ? 'badge-sell' : 'badge-buy';
        
        if (strtoupper($trans['trade_side']) === 'SELL') {
            $total_inflow += $trans['consideration'];
        } else {
            $total_outflow += $trans['consideration'];
        }
        
        $html .= '
            <tr class="' . $row_class . '">
                <td>' . $counter . '</td>
                <td>' . date('d/m/Y', strtotime($trans['trade_date'])) . '</td>
                <td class="text-left">' . htmlspecialchars($trans['client_name']) . '</td>
                <td class="text-left">' . htmlspecialchars($trans['client_cds_account']) . '</td>
                <td class="text-left">' . htmlspecialchars($trans['security_name']) . '</td>' .
                ($asset_type === 'combined' ? '<td>' . ucfirst($trans['asset_class']) . '</td>' : '') .
                '<td><span class="' . $badge_class . '">' . strtoupper($trans['trade_side']) . '</span></td>
                <td class="text-right">' . number_format($trans['quantity'], 0) . '</td>
                <td class="text-right">' . number_format($trans['price'], 2) . '</td>
                <td class="text-right">' . number_format($trans['consideration'], 2) . '</td>
            </tr>';
        $counter++;
    }
    
    $total_net = $total_inflow - $total_outflow;
    $total_net_class = $total_net >= 0 ? 'positive' : 'negative';
    
    $html .= '
            <tr style="background-color: #f8f9fa; font-weight: bold;">
                <td colspan="' . ($asset_type === 'combined' ? '9' : '8') . '" class="text-right"><strong>TOTALS:</strong></td>
                <td class="text-right">
                    <div class="text-success">In: ' . number_format($total_inflow, 2) . '</div>
                    <div class="text-danger">Out: ' . number_format($total_outflow, 2) . '</div>
                    <div class="' . $total_net_class . '">Net: ' . number_format($total_net, 2) . '</div>
                </td>
            </tr>
        </tbody>
    </table>
    </div>
    
    <div class="mobile-view">';
    
    $counter = 1;
    foreach ($transactions as $trans) {
        $badge_class = strtoupper($trans['trade_side']) === 'SELL' ? 'badge-sell' : 'badge-buy';
        $html .= '
        <div class="transaction-card">
            <div class="card-row">
                <span class="card-label">Date:</span>
                <span class="card-value">' . date('d/m/Y', strtotime($trans['trade_date'])) . '</span>
            </div>
            <div class="card-row">
                <span class="card-label">Client:</span>
                <span class="card-value">' . htmlspecialchars($trans['client_name']) . '</span>
            </div>
            <div class="card-row">
                <span class="card-label">Security:</span>
                <span class="card-value">' . htmlspecialchars($trans['security_name']) . '</span>
            </div>
            <div class="card-row">
                <span class="card-label">Side:</span>
                <span class="' . $badge_class . '">' . strtoupper($trans['trade_side']) . '</span>
            </div>
            <div class="card-row">
                <span class="card-label">Quantity:</span>
                <span class="card-value">' . number_format($trans['quantity'], 0) . '</span>
            </div>
            <div class="card-row">
                <span class="card-label">Price:</span>
                <span class="card-value">' . number_format($trans['price'], 2) . '</span>
            </div>
            <div class="card-row">
                <span class="card-label">Consideration:</span>
                <span class="card-value"><strong>' . number_format($trans['consideration'], 2) . '</strong></span>
            </div>
        </div>';
        $counter++;
    }
    
    $html .= '
    </div>
    
    <div class="disclaimer mt-4">
        <strong>Disclaimer:</strong> ' . $company_name . ' has prepared this Report solely for informational purposes. ' . $company_name . ' does not represent warrant or guarantee that the
        Reports are accurate. ' . $company_name . ' disclaims liability for any direct indirect punitive special consequential or incidental damages related to the Reports or the use
        of the Reports. This disclaimer applies to the Reports in their entirety irrespective of whether the Reports are used or viewed in whole or in part.
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    </body>
    </html>';
    
    return $html;
}

function generateCombinedSummaryReport($db, $company_name, $company_address, $company_phone, $company_email, $filters) {
    $chosen_date = $filters['chosen_date'] ?? date('Y-m-d');
    $period_from = $filters['period_from'] ?? $chosen_date;
    $period_to = $filters['period_to'] ?? $chosen_date;

    // Build conditions
    $bond_where_conditions = ["t.asset_class = 'bond'", "t.status = 'active'"];
    $equity_where_conditions = ["t.asset_class = 'equity'", "t.status = 'active'"];
    $params = [];

    // Use date range logic
    if (!empty($period_from) && !empty($period_to)) {
        $bond_where_conditions[] = "DATE(t.trade_date) BETWEEN ? AND ?";
        $equity_where_conditions[] = "DATE(t.trade_date) BETWEEN ? AND ?";
        $params[] = $period_from;
        $params[] = $period_to;
    } else {
        $bond_where_conditions[] = "DATE(t.trade_date) = ?";
        $equity_where_conditions[] = "DATE(t.trade_date) = ?";
        $params[] = $chosen_date;
    }

    $bond_where_clause = implode(' AND ', $bond_where_conditions);
    $equity_where_clause = implode(' AND ', $equity_where_conditions);
    
    $bond_stmt = $db->prepare("
        SELECT 
            t.client_name,
            t.client_cds_account,
            t.trade_side,
            SUM(t.quantity) as total_quantity,
            SUM(t.consideration) as total_consideration,
            COUNT(*) as transaction_count
        FROM trades t
        WHERE $bond_where_clause
        GROUP BY t.client_name, t.client_cds_account, t.trade_side
        ORDER BY t.client_name, t.trade_side
    ");
    $bond_stmt->execute($params);
    $bond_client_transactions = $bond_stmt->fetchAll();
    
    $equity_stmt = $db->prepare("
        SELECT 
            t.client_name,
            t.client_cds_account,
            t.trade_side,
            SUM(t.quantity) as total_quantity,
            SUM(t.consideration) as total_consideration,
            COUNT(*) as transaction_count
        FROM trades t
        WHERE $equity_where_clause
        GROUP BY t.client_name, t.client_cds_account, t.trade_side
        ORDER BY t.client_name, t.trade_side
    ");
    $equity_stmt->execute($params);
    $equity_client_transactions = $equity_stmt->fetchAll();
    
    if (empty($bond_client_transactions) && empty($equity_client_transactions)) {
        echo '<div class="container py-5">
                <div class="alert alert-info text-center py-5">
                    <i class="fas fa-info-circle fa-3x text-muted mb-3"></i>
                    <h5>No Transactions Found</h5>
                    <p class="text-muted">No trades found for the chosen date. Please adjust your filters and try again.</p>
                    <a href="transactions.php?action=filter" class="btn btn-primary mt-3">
                        <i class="fas fa-filter me-2"></i>Apply New Filter
                    </a>
                </div>
              </div>';
        return;
    }
    
    // Calculate bond client balances
    $bond_balances = [];
    foreach ($bond_client_transactions as $transaction) {
        $client_key = $transaction['client_name'] . '|' . $transaction['client_cds_account'];
        
        if (!isset($bond_balances[$client_key])) {
            $bond_balances[$client_key] = [
                'client_name' => $transaction['client_name'],
                'client_account' => $transaction['client_cds_account'],
                'inflow' => 0,
                'outflow' => 0,
                'net' => 0
            ];
        }
        
        if (strtoupper($transaction['trade_side']) === 'SELL') {
            $bond_balances[$client_key]['inflow'] += $transaction['total_consideration'];
        } else {
            $bond_balances[$client_key]['outflow'] += $transaction['total_consideration'];
        }
        
        $bond_balances[$client_key]['net'] = 
            $bond_balances[$client_key]['inflow'] - $bond_balances[$client_key]['outflow'];
    }
    
    // Calculate equity client balances
    $equity_balances = [];
    foreach ($equity_client_transactions as $transaction) {
        $client_key = $transaction['client_name'] . '|' . $transaction['client_cds_account'];
        
        if (!isset($equity_balances[$client_key])) {
            $equity_balances[$client_key] = [
                'client_name' => $transaction['client_name'],
                'client_account' => $transaction['client_cds_account'],
                'inflow' => 0,
                'outflow' => 0,
                'net' => 0
            ];
        }
        
        if (strtoupper($transaction['trade_side']) === 'SELL') {
            $equity_balances[$client_key]['inflow'] += $transaction['total_consideration'];
        } else {
            $equity_balances[$client_key]['outflow'] += $transaction['total_consideration'];
        }
        
        $equity_balances[$client_key]['net'] = 
            $equity_balances[$client_key]['inflow'] - $equity_balances[$client_key]['outflow'];
    }
    
    // Sort by client name
    usort($bond_balances, function($a, $b) {
        return strcmp($a['client_name'], $b['client_name']);
    });
    
    usort($equity_balances, function($a, $b) {
        return strcmp($a['client_name'], $b['client_name']);
    });
    
    // Check if we're exporting to PDF
    if (isset($_POST['export_type']) && $_POST['export_type'] === 'pdf') {
        // Create PDF in PORTRAIT orientation (better for summary reports)
        $pdf = new TCPDF('P', PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
        $pdf->SetCreator($company_name);
        $pdf->SetAuthor($company_name);
        $pdf->SetTitle('Combined Transactions Summary Report');
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(10, 10, 10);
        $pdf->SetAutoPageBreak(TRUE, 15);
        $pdf->AddPage();

        $html = generateCombinedSummaryHTML($bond_balances, $equity_balances, $company_name, $company_address, $company_phone, $company_email, $filters);
        $pdf->writeHTML($html, true, false, true, false, '');
        $pdf->Output('combined_summary_' . date('Y_m_d') . '.pdf', 'I');
    } else {
        // Display HTML report
        echo generateCombinedSummaryHTML($bond_balances, $equity_balances, $company_name, $company_address, $company_phone, $company_email, $filters);
    }
}

// ==================== FILTER FORM ====================
function displayFilterForm() {
    echo '
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Transaction Summary Reports - Filter</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
        <style>
            .card { border-radius: 10px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); }
            .card-header { border-radius: 10px 10px 0 0 !important; }
            .btn-primary { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); border: none; }
            .btn-primary:hover { background: linear-gradient(135deg, #5a6fd8 0%, #6a4090 100%); }
            .report-type-btn { transition: all 0.3s ease; }
            .report-type-btn:hover { transform: translateY(-2px); box-shadow: 0 4px 8px rgba(0,0,0,0.2); }
            .report-type-btn.active { transform: translateY(-2px); box-shadow: 0 4px 8px rgba(0,0,0,0.2); }
        </style>
    </head>
    <body class="bg-light">
        <div class="container py-5">
            <div class="row justify-content-center">
                <div class="col-lg-10">
                    <div class="card">
                        <div class="card-header bg-primary">
                            <h4 class="mb-0"><i class="fas fa-filter me-2"></i>Transaction Summary Reports Filter</h4>
                        </div>
                        <div class="card-body">
                            <form method="post" action="transactions" id="filterForm">
                                <div class="row mb-4">
                                    <div class="col-md-12">
                                        <h5 class="border-bottom pb-2 mb-3">Select Report Type</h5>
                                        <div class="row">
                                            <div class="col-md-4 mb-3">
                                                <button type="button" class="btn btn-outline-primary w-100 report-type-btn" data-report="bonds_summary">
                                                    <i class="fas fa-file-invoice-dollar fa-2x mb-2"></i><br>
                                                    <strong>Bonds Summary</strong><br>
                                                    <small class="text-muted">Summary of bond transactions by client</small>
                                                </button>
                                            </div>
                                            <div class="col-md-4 mb-3">
                                                <button type="button" class="btn btn-outline-success w-100 report-type-btn" data-report="equities_summary">
                                                    <i class="fas fa-chart-line fa-2x mb-2"></i><br>
                                                    <strong>Equities Summary</strong><br>
                                                    <small class="text-muted">Summary of equity transactions by client</small>
                                                </button>
                                            </div>
                                            <div class="col-md-4 mb-3">
                                                <button type="button" class="btn btn-outline-info w-100 report-type-btn" data-report="combined_summary">
                                                    <i class="fas fa-list-alt fa-2x mb-2"></i><br>
                                                    <strong>Combined Summary</strong><br>
                                                    <small class="text-muted">Summary of bonds + shares by client</small>
                                                </button>
                                            </div>
                                        </div>
                                        <input type="hidden" name="report_name" id="report_name" value="">
                                    </div>
                                </div>
                                
                                <div class="row mb-4">
                                    <div class="col-md-12">
                                        <h5 class="border-bottom pb-2 mb-3">Filter Criteria</h5>
                                        <div class="row">
                                            <div class="col-md-6 mb-3">
                                                <label class="form-label">Report Mode</label>
                                                <select class="form-control" name="report_mode">
                                                    <option value="summary">Summary (Aggregated)</option>
                                                    <option value="detailed">Detailed (All Transactions)</option>
                                                </select>
                                            </div>
                                            <div class="col-md-6 mb-3">
                                                <label class="form-label">Date Range From</label>
                                                <input type="date" class="form-control" name="period_from" value="' . date('Y-m-d') . '">
                                            </div>
                                            <div class="col-md-6 mb-3">
                                                <label class="form-label">Date Range To</label>
                                                <input type="date" class="form-control" name="period_to" value="' . date('Y-m-d') . '">
                                            </div>
                                            <div class="col-md-6 mb-3">
                                                <label class="form-label">Trade Type (Optional)</label>
                                                <select class="form-control" name="trade_type">
                                                    <option value="">All Types</option>
                                                    <option value="BUY">Buy Only</option>
                                                    <option value="SELL">Sell Only</option>
                                                </select>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="row">
                                    <div class="col-md-12">
                                        <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                                            <button type="button" class="btn btn-secondary" onclick="window.history.back()">
                                                <i class="fas fa-arrow-left me-2"></i>Back
                                            </button>
                                            <button type="submit" class="btn btn-primary">
                                                <i class="fas fa-chart-bar me-2"></i>Generate Report
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
                    
                    <div class="card mt-4">
                        <div class="card-header bg-light">
                            <h6 class="mb-0"><i class="fas fa-info-circle me-2"></i>Report Information</h6>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-4">
                                    <h6>Summary Mode</h6>
                                    <p class="text-muted small">Aggregated totals per client. Ideal for quick reviews and net position assessment.</p>
                                </div>
                                <div class="col-md-4">
                                    <h6>Detailed Mode</h6>
                                    <p class="text-muted small">Lists every transaction record for the selected period.</p>
                                </div>
                                <div class="col-md-4">
                                    <h6>Date Range Filtering</h6>
                                    <p class="text-muted small">Select a start and end date to analyze trends over a custom period.</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
        <script>
            // Handle report type selection
            document.querySelectorAll(".report-type-btn").forEach(btn => {
                btn.addEventListener("click", function() {
                    // Remove active class from all buttons
                    document.querySelectorAll(".report-type-btn").forEach(b => {
                        b.classList.remove("active", "btn-primary", "btn-success", "btn-info");
                        b.classList.add("btn-outline-primary", "btn-outline-success", "btn-outline-info");
                    });
                    
                    // Add active class to clicked button
                    this.classList.remove("btn-outline-primary", "btn-outline-success", "btn-outline-info");
                    const reportType = this.getAttribute("data-report");
                    
                    if(reportType === "bonds_summary") {
                        this.classList.add("active", "btn-primary");
                    } else if(reportType === "equities_summary") {
                        this.classList.add("active", "btn-success");
                    } else if(reportType === "combined_summary") {
                        this.classList.add("active", "btn-info");
                    }
                    
                    // Set the hidden input value
                    document.getElementById("report_name").value = reportType;
                });
            });
            
            // Form validation
            document.getElementById("filterForm").addEventListener("submit", function(e) {
                const reportName = document.getElementById("report_name").value;
                if(!reportName) {
                    e.preventDefault();
                    alert("Please select a report type!");
                    return false;
                }
                return true;
            });
            
            // Select bonds summary by default
            document.querySelector(\'[data-report="bonds_summary"]\').click();
        </script>
    </body>
    </html>';
}

// ==================== MAIN LOGIC ====================
// Check if we're showing the filter form or generating a report
if (empty($report_name) || isset($_GET['action']) && $_GET['action'] === 'filter') {
    displayFilterForm();
    exit;
}

$report_mode = $_POST['report_mode'] ?? 'summary';

// Handle report generation based on report type and mode
switch ($report_name) {
    case 'bonds_summary':
        if ($report_mode === 'detailed') {
            generateBondsDetailedReport($db, $company_name, $company_address, $company_phone, $company_email, $_POST);
        } else {
            generateBondsSummaryReport($db, $company_name, $company_address, $company_phone, $company_email, $_POST);
        }
        break;
        
    case 'equities_summary':
        if ($report_mode === 'detailed') {
            generateEquitiesDetailedReport($db, $company_name, $company_address, $company_phone, $company_email, $_POST);
        } else {
            generateEquitiesSummaryReport($db, $company_name, $company_address, $company_phone, $company_email, $_POST);
        }
        break;
        
    case 'combined_summary':
        if ($report_mode === 'detailed') {
            generateCombinedDetailedReport($db, $company_name, $company_address, $company_phone, $company_email, $_POST);
        } else {
            generateCombinedSummaryReport($db, $company_name, $company_address, $company_phone, $company_email, $_POST);
        }
        break;
        
    default:
        echo '<div class="container py-5">
                <div class="alert alert-warning text-center py-5">
                    <i class="fas fa-exclamation-triangle fa-3x text-warning mb-3"></i>
                    <h5>Invalid Report Type</h5>
                    <p class="text-muted">The selected report type is not available.</p>
                    <a href="transactions.php?action=filter" class="btn btn-primary mt-3">
                        <i class="fas fa-filter me-2"></i>Select Report Type
                    </a>
                </div>
              </div>';
        break;
}
?>