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

// ==================== BONDS EDIT LIST HTML ====================
function generateBondsEditListHTML($transactions, $company_name, $company_address, $company_phone, $company_email, $filters) {
    $period_from = date('d/m/Y', strtotime($filters['period_from'] ?? date('Y-01-01')));
    $period_to = date('d/m/Y', strtotime($filters['period_to'] ?? date('Y-m-d')));
    $chosen_date = date('d/m/Y', strtotime($filters['chosen_date'] ?? date('Y-m-d')));
    $current_date = date('d/m/Y');
    $current_time = date('H:i:s');
    
    // Calculate daily totals and process transactions
    $daily_totals = [];
    $processed_transactions = [];
    
    foreach ($transactions as $transaction) {
        $fees = calculateBondFees($transaction['consideration']);
        $net_amount = strtoupper($transaction['trade_side']) === 'BUY' ? 
            $transaction['consideration'] + $fees['total_charges'] : 
            $transaction['consideration'] - $fees['total_charges'];
        
        $processed_transactions[] = [
            'data' => $transaction,
            'fees' => $fees,
            'net_amount' => $net_amount
        ];
        
        $date = $transaction['trade_date'];
        if (!isset($daily_totals[$date])) {
            $daily_totals[$date] = [
                'purchases_qty' => 0,
                'purchases_consideration' => 0,
                'purchases_commission' => 0,
                'sales_qty' => 0,
                'sales_consideration' => 0,
                'sales_commission' => 0
            ];
        }
        
        if (strtoupper($transaction['trade_side']) === 'BUY') {
            $daily_totals[$date]['purchases_qty'] += $transaction['quantity'];
            $daily_totals[$date]['purchases_consideration'] += $transaction['consideration'];
            $daily_totals[$date]['purchases_commission'] += $fees['brokerage_commission'];
        } else {
            $daily_totals[$date]['sales_qty'] += $transaction['quantity'];
            $daily_totals[$date]['sales_consideration'] += $transaction['consideration'];
            $daily_totals[$date]['sales_commission'] += $fees['brokerage_commission'];
        }
    }
    
    $html = '
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Bonds Edit List Report</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
        <style>
            @media screen {
                .header { text-align: center; margin-bottom: 20px; border-bottom: 2px solid #dee2e6; padding-bottom: 15px; }
                .company-info { font-size: 12px; margin-bottom: 10px; color: #495057; line-height: 1.4; }
                .report-title { font-size: 18px; font-weight: bold; margin: 10px 0; color: #2c3e50; }
                .report-subtitle { font-size: 14px; margin: 5px 0; color: #6c757d; }
                .disclaimer { font-size: 11px; margin-top: 30px; padding: 15px; background-color: #f8f9fa; border-radius: 5px; color: #666; border-left: 4px solid #007bff; }
                table { width: 100%; border-collapse: collapse; font-size: 11px; margin: 10px 0; }
                th, td { border: 1px solid #dee2e6; padding: 6px; text-align: center; }
                th { background-color: #e9ecef; font-weight: bold; color: #495057; }
                .text-left { text-align: left; }
                .text-right { text-align: right; }
                .section-header { background-color: #e8f4fd; font-weight: bold; color: #2c3e50; }
                .total-row { background-color: #f8f9fa; font-weight: bold; color: #007bff; }
                .table-responsive { overflow-x: auto; margin-bottom: 20px; }
                .badge-buy { background-color: #28a745; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; }
                .badge-sell { background-color: #dc3545; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; }
                .action-buttons { position: sticky; top: 0; background: white; padding: 10px 0; z-index: 1000; }
            }
            @media print {
                .header { text-align: center; margin-bottom: 5px; border-bottom: 1px solid #000; padding-bottom: 3px; }
                .company-info { font-size: 9px; margin-bottom: 5px; }
                .report-title { font-size: 14px; font-weight: bold; margin: 5px 0; }
                .report-subtitle { font-size: 10px; margin: 2px 0; }
                .disclaimer { font-size: 8px; margin-top: 15px; color: #666; }
                table { width: 100%; border-collapse: collapse; font-size: 8px; margin: 3px 0; }
                th, td { border: 1px solid #000; padding: 2px; text-align: center; }
                th { background-color: #f0f0f0; font-weight: bold; }
                .text-left { text-align: left; }
                .text-right { text-align: right; }
                .section-header { background-color: #e0e0e0; font-weight: bold; }
                .total-row { background-color: #f8f8f8; font-weight: bold; }
                .page-break { page-break-after: always; }
                .action-buttons { display: none !important; }
            }
        </style>
    </head>
    <body>
        <div class="container-fluid">
            <div class="action-buttons mb-3">
                <div class="d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="fas fa-file-invoice-dollar me-2"></i>Bonds Edit List Report</h5>
                    <div>
                        <button onclick="window.print()" class="btn btn-secondary btn-sm">
                            <i class="fas fa-print me-1"></i>Print
                        </button>
                        <a href="editlist.php?action=filter" class="btn btn-primary btn-sm ms-2">
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
                <div class="report-title">BONDS PURCHASES & SALES TRANSACTIONS EDIT LIST</div>
                <div class="report-subtitle">FOR DATE: ' . $chosen_date . '</div>
                <div class="report-subtitle">Date Printed: ' . $current_date . ' - ' . $current_time . '</div>
            </div>
            
            <div style="background-color: #f8f9fa; border-left: 4px solid #002e92; padding: 10px 15px; margin: 10px 0; font-size: 10px; color: #333;">
                <strong>Report Overview:</strong> This Bonds Edit List provides a detailed record of all bond purchase and sale transactions 
                processed by VICTORY FINANCIAL SERVICES LIMITED during the period <strong>' . $period_from . '</strong> to <strong>' . $period_to . '</strong>. 
                Each row represents a single executed trade, showing the client, security, trade details, and a full breakdown of applicable charges.<br><br>
                <strong>How to Read:</strong> Transactions are grouped by trade date. For each trade, the report shows the gross consideration 
                (trade value), followed by charges: Brokerage Commission (0.063%), DSE Transaction Levy, CMSA Levy, CSD Levy, and VAT on Commission (18%). 
                The final column shows the net amount — the actual cash impact after all charges. For BUY trades, charges are added to the consideration; 
                for SELL trades, charges are deducted.<br><br>
                <strong>Key Columns:</strong> 
                <em>SLIPNO</em> = Trade reference number | <em>CONTRACT</em> = Trade side and unique ID | 
                <em>CONSIDERATION</em> = Quantity × Price | <em>TOTAL CHARGES</em> = Sum of all fees | 
                <em>GROSS/NET AMOUNT</em> = Final settlement amount after charges.
            </div>
            
            <div class="table-responsive">
                <table class="table table-bordered table-striped">
                    <thead>
                        <tr>
                            <th width="3%">#</th>
                            <th width="7%">DATE</th>
                            <th width="9%">SLIP NO</th>
                            <th width="10%">CONTRACT</th>
                            <th width="18%">CLIENT</th>
                            <th width="9%">SECURITY</th>
                            <th width="5%">QUANTITY</th>
                            <th width="4%">PRICE</th>
                            <th width="4%">% RATE</th>
                            <th width="7%">CONSIDERATION</th>
                            <th width="5%">GROSS COMMISSION</th>
                            <th width="4%">DSE LEVY</th>
                            <th width="4%">CMSA LEVY</th>
                            <th width="4%">CSD LEVY</th>
                            <th width="4%">VAT ON COMM</th>
                            <th width="5%">TOTAL CHARGES</th>
                            <th width="7%">GROSS/NET AMOUNT</th>
                            <th width="4%">RETURN COMMISSION</th>
                            <th width="5%">NET COMMISSION</th>
                        </tr>
                    </thead>
                    <tbody>';
    
    $counter = 1;
    $current_date_group = '';
    $total_purchases_qty = 0;
    $total_purchases_consideration = 0;
    $total_purchases_commission = 0;
    $total_sales_qty = 0;
    $total_sales_consideration = 0;
    $total_sales_commission = 0;
    
    foreach ($processed_transactions as $item) {
        $transaction = $item['data'];
        $fees = $item['fees'];
        
        // Show date group header
        if ($current_date_group !== $transaction['trade_date']) {
            $current_date_group = $transaction['trade_date'];
            $html .= '
            <tr class="section-header">
                <td colspan="19" class="text-left"><strong>' . date('l, F j, Y', strtotime($current_date_group)) . '</strong></td>
            </tr>';
        }
        
        $trade_badge = strtoupper($transaction['trade_side']) === 'BUY' ? 
            '<span class="badge-buy">BUY</span>' : 
            '<span class="badge-sell">SELL</span>';
        
        // Update totals
        if (strtoupper($transaction['trade_side']) === 'BUY') {
            $total_purchases_qty += $transaction['quantity'];
            $total_purchases_consideration += $transaction['consideration'];
            $total_purchases_commission += $fees['brokerage_commission'];
        } else {
            $total_sales_qty += $transaction['quantity'];
            $total_sales_consideration += $transaction['consideration'];
            $total_sales_commission += $fees['brokerage_commission'];
        }
        
        $html .= '
            <tr>
                <td>' . $counter . '</td>
                <td>' . date('d/m/Y', strtotime($transaction['trade_date'])) . '</td>
                <td>' . htmlspecialchars($transaction['trade_reference']) . '</td>
                <td>' . $trade_badge . ':' . substr($transaction['trade_reference'], -6) . '</td>
                <td class="text-left">' . htmlspecialchars($transaction['client_name']) . '<br>
                    <small class="text-muted">' . htmlspecialchars($transaction['client_cds_account']) . '</small>
                </td>
                <td>' . htmlspecialchars($transaction['security_id']) . '<br>
                    <small class="text-muted">' . htmlspecialchars($transaction['security_name']) . '</small>
                </td>
                <td class="text-right">' . number_format($transaction['quantity']) . '</td>
                <td class="text-right">' . number_format($transaction['price'], 4) . '</td>
                <td class="text-right">0.0630</td>
                <td class="text-right">' . number_format($transaction['consideration'], 2) . '</td>
                <td class="text-right">' . number_format($fees['brokerage_commission'], 2) . '</td>
                <td class="text-right">' . number_format($fees['dse_fee'], 2) . '</td>
                <td class="text-right">' . number_format($fees['cmsa_fee'], 2) . '</td>
                <td class="text-right">' . number_format($fees['cds_fee'], 2) . '</td>
                <td class="text-right">' . number_format($fees['vat_on_commission'], 2) . '</td>
                <td class="text-right">' . number_format($fees['total_charges'], 2) . '</td>
                <td class="text-right">' . number_format($item['net_amount'], 2) . '</td>
                <td class="text-right">0.00</td>
                <td class="text-right">' . number_format($fees['brokerage_commission'], 2) . '</td>
            </tr>';
        
        $counter++;
    }
    
    // Add final totals
    $html .= '
            <tr class="total-row">
                <td colspan="5" class="text-left"><strong>TOTAL PURCHASES</strong></td>
                <td class="text-right"><strong>' . number_format($total_purchases_qty) . '</strong></td>
                <td colspan="3"></td>
                <td class="text-right"><strong>' . number_format($total_purchases_consideration, 2) . '</strong></td>
                <td class="text-right"><strong>' . number_format($total_purchases_commission, 2) . '</strong></td>
                <td colspan="8"></td>
            </tr>
            <tr class="total-row">
                <td colspan="5" class="text-left"><strong>TOTAL SALES</strong></td>
                <td class="text-right"><strong>' . number_format($total_sales_qty) . '</strong></td>
                <td colspan="3"></td>
                <td class="text-right"><strong>' . number_format($total_sales_consideration, 2) . '</strong></td>
                <td class="text-right"><strong>' . number_format($total_sales_commission, 2) . '</strong></td>
                <td colspan="8"></td>
            </tr>
            <tr class="total-row">
                <td colspan="5" class="text-left"><strong>GRAND TOTAL TURNOVER</strong></td>
                <td class="text-right"><strong>' . number_format($total_purchases_qty + $total_sales_qty) . '</strong></td>
                <td colspan="3"></td>
                <td class="text-right"><strong>' . number_format($total_purchases_consideration + $total_sales_consideration, 2) . '</strong></td>
                <td class="text-right"><strong>' . number_format($total_purchases_commission + $total_sales_commission, 2) . '</strong></td>
                <td colspan="8"></td>
            </tr>
        </tbody>
    </table>
    </div>
    
    <div class="disclaimer">
        <strong>Disclaimer:</strong> ' . $company_name . ' has prepared this Report solely for informational purposes. ' . $company_name . ' does not represent warrant or guarantee that the
        Reports are accurate. ' . $company_name . ' disclaims liability for any direct indirect punitive special consequential or incidental damages related to the Reports or the use
        of the Reports. This disclaimer applies to the Reports in their entirety irrespective of whether the Reports are used or viewed in whole or in part.
    </div>
    
    <div class="row mt-4">
        <div class="col-md-4">
            <div class="card">
                <div class="card-header bg-primary">
                    <strong>Summary</strong>
                </div>
                <div class="card-body">
                    <p class="mb-1">Total Transactions: <strong>' . ($counter - 1) . '</strong></p>
                    <p class="mb-1">Total Purchases: <strong>' . number_format($total_purchases_qty) . ' bonds</strong></p>
                    <p class="mb-1">Total Sales: <strong>' . number_format($total_sales_qty) . ' bonds</strong></p>
                    <p class="mb-0">Total Turnover: <strong>' . number_format($total_purchases_qty + $total_sales_qty) . ' bonds</strong></p>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card">
                <div class="card-header bg-success">
                    <strong>Financial Summary</strong>
                </div>
                <div class="card-body">
                    <p class="mb-1">Purchases Value: <strong>TZS ' . number_format($total_purchases_consideration, 2) . '</strong></p>
                    <p class="mb-1">Sales Value: <strong>TZS ' . number_format($total_sales_consideration, 2) . '</strong></p>
                    <p class="mb-0">Total Turnover Value: <strong>TZS ' . number_format($total_purchases_consideration + $total_sales_consideration, 2) . '</strong></p>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card">
                <div class="card-header bg-info">
                    <strong>Commission Summary</strong>
                </div>
                <div class="card-body">
                    <p class="mb-1">Purchases Commission: <strong>TZS ' . number_format($total_purchases_commission, 2) . '</strong></p>
                    <p class="mb-1">Sales Commission: <strong>TZS ' . number_format($total_sales_commission, 2) . '</strong></p>
                    <p class="mb-0">Total Commission: <strong>TZS ' . number_format($total_purchases_commission + $total_sales_commission, 2) . '</strong></p>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    </body>
    </html>';
    
    return $html;
}

// ==================== EQUITIES EDIT LIST HTML ====================
function generateEquitiesEditListHTML($transactions, $company_name, $company_address, $company_phone, $company_email, $filters) {
    $period_from = date('d/m/Y', strtotime($filters['period_from'] ?? date('Y-01-01')));
    $period_to = date('d/m/Y', strtotime($filters['period_to'] ?? date('Y-m-d')));
    $chosen_date = date('d/m/Y', strtotime($filters['chosen_date'] ?? date('Y-m-d')));
    $current_date = date('d/m/Y');
    $current_time = date('H:i:s');
    
    // Calculate daily totals and process transactions
    $daily_totals = [];
    $processed_transactions = [];
    
    foreach ($transactions as $transaction) {
        $fees = calculateEquityFees($transaction['consideration'], $transaction['price']);
        $net_amount = strtoupper($transaction['trade_side']) === 'BUY' ? 
            $transaction['consideration'] + $fees['total_charges'] : 
            $transaction['consideration'] - $fees['total_charges'];
        
        $processed_transactions[] = [
            'data' => $transaction,
            'fees' => $fees,
            'net_amount' => $net_amount
        ];
        
        $date = $transaction['trade_date'];
        if (!isset($daily_totals[$date])) {
            $daily_totals[$date] = [
                'purchases_qty' => 0,
                'purchases_consideration' => 0,
                'purchases_commission' => 0,
                'sales_qty' => 0,
                'sales_consideration' => 0,
                'sales_commission' => 0
            ];
        }
        
        if (strtoupper($transaction['trade_side']) === 'BUY') {
            $daily_totals[$date]['purchases_qty'] += $transaction['quantity'];
            $daily_totals[$date]['purchases_consideration'] += $transaction['consideration'];
            $daily_totals[$date]['purchases_commission'] += $fees['brokerage_commission'];
        } else {
            $daily_totals[$date]['sales_qty'] += $transaction['quantity'];
            $daily_totals[$date]['sales_consideration'] += $transaction['consideration'];
            $daily_totals[$date]['sales_commission'] += $fees['brokerage_commission'];
        }
    }
    
    $html = '
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Equities Edit List Report</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
        <style>
            @media screen {
                .header { text-align: center; margin-bottom: 20px; border-bottom: 2px solid #dee2e6; padding-bottom: 15px; }
                .company-info { font-size: 12px; margin-bottom: 10px; color: #495057; line-height: 1.4; }
                .report-title { font-size: 18px; font-weight: bold; margin: 10px 0; color: #2c3e50; }
                .report-subtitle { font-size: 14px; margin: 5px 0; color: #6c757d; }
                .disclaimer { font-size: 11px; margin-top: 30px; padding: 15px; background-color: #f8f9fa; border-radius: 5px; color: #666; border-left: 4px solid #28a745; }
                table { width: 100%; border-collapse: collapse; font-size: 11px; margin: 10px 0; }
                th, td { border: 1px solid #dee2e6; padding: 6px; text-align: center; }
                th { background-color: #e9ecef; font-weight: bold; color: #495057; }
                .text-left { text-align: left; }
                .text-right { text-align: right; }
                .section-header { background-color: #e8f5e8; font-weight: bold; color: #2c3e50; }
                .total-row { background-color: #f8f9fa; font-weight: bold; color: #28a745; }
                .table-responsive { overflow-x: auto; margin-bottom: 20px; }
                .badge-buy { background-color: #28a745; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; }
                .badge-sell { background-color: #dc3545; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; }
                .action-buttons { position: sticky; top: 0; background: white; padding: 10px 0; z-index: 1000; }
            }
            @media print {
                .header { text-align: center; margin-bottom: 5px; border-bottom: 1px solid #000; padding-bottom: 3px; }
                .company-info { font-size: 9px; margin-bottom: 5px; }
                .report-title { font-size: 14px; font-weight: bold; margin: 5px 0; }
                .report-subtitle { font-size: 10px; margin: 2px 0; }
                .disclaimer { font-size: 8px; margin-top: 15px; color: #666; }
                table { width: 100%; border-collapse: collapse; font-size: 8px; margin: 3px 0; }
                th, td { border: 1px solid #000; padding: 2px; text-align: center; }
                th { background-color: #f0f0f0; font-weight: bold; }
                .text-left { text-align: left; }
                .text-right { text-align: right; }
                .section-header { background-color: #e0e0e0; font-weight: bold; }
                .total-row { background-color: #f8f8f8; font-weight: bold; }
                .page-break { page-break-after: always; }
                .action-buttons { display: none !important; }
            }
        </style>
    </head>
    <body>
        <div class="container-fluid">
            <div class="action-buttons mb-3">
                <div class="d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="fas fa-chart-line me-2"></i>Equities Edit List Report</h5>
                    <div>
                        <button onclick="window.print()" class="btn btn-secondary btn-sm">
                            <i class="fas fa-print me-1"></i>Print
                        </button>
                        <a href="editlist.php?action=filter" class="btn btn-primary btn-sm ms-2">
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
                <div class="report-title">EQUITIES PURCHASES & SALES TRANSACTIONS EDIT LIST</div>
                <div class="report-subtitle">FOR DATE: ' . $chosen_date . '</div>
                <div class="report-subtitle">Date Printed: ' . $current_date . ' - ' . $current_time . '</div>
            </div>
            
            <div style="background-color: #f8f9fa; border-left: 4px solid #28a745; padding: 10px 15px; margin: 10px 0; font-size: 10px; color: #333;">
                <strong>Report Overview:</strong> This Equities Edit List provides a detailed record of all share purchase and sale transactions 
                processed during the period <strong>' . $period_from . '</strong> to <strong>' . $period_to . '</strong>. Each row represents a single equity trade with 
                full fee breakdown.<br><br>
                <strong>How to Read:</strong> Transactions are grouped by trade date. For each trade, the report shows the gross consideration 
                followed by charges: Brokerage Commission (1.5%), DSE Transaction Levy, CMSA Levy, CSD Levy, and VAT on Commission (18%). 
                The <em>GROSS/NET AMOUNT</em> column shows the final settlement — BUY trades have charges added, SELL trades have charges deducted.<br><br>
                <strong>Key Columns:</strong> 
                <em>SLIPNO</em> = Trade reference | <em>CONTRACT</em> = Trade side (BUY:xxx or SELL:xxx) | 
                <em>CONSIDERATION</em> = Quantity × Price | <em>TOTAL CHARGES</em> = Sum of all fees | 
                <em>NET COMMISSION</em> = Brokerage commission after VAT deduction.
            </div>
            
            <div class="table-responsive">
                <table class="table table-bordered table-striped">
                    <thead>
                        <tr>
                            <th width="3%">#</th>
                            <th width="7%">DATE</th>
                            <th width="9%">SLIP NO</th>
                            <th width="10%">CONTRACT</th>
                            <th width="18%">CLIENT</th>
                            <th width="9%">SECURITY</th>
                            <th width="5%">QUANTITY</th>
                            <th width="4%">PRICE</th>
                            <th width="4%">% RATE</th>
                            <th width="7%">CONSIDERATION</th>
                            <th width="5%">GROSS COMMISSION</th>
                            <th width="4%">DSE LEVY</th>
                            <th width="4%">CMSA LEVY</th>
                            <th width="4%">CSD LEVY</th>
                            <th width="4%">VAT ON COMM</th>
                            <th width="5%">TOTAL CHARGES</th>
                            <th width="7%">GROSS/NET AMOUNT</th>
                            <th width="4%">RETURN COMMISSION</th>
                            <th width="5%">NET COMMISSION</th>
                        </tr>
                    </thead>
                    <tbody>';
    
    $counter = 1;
    $current_date_group = '';
    $total_purchases_qty = 0;
    $total_purchases_consideration = 0;
    $total_purchases_commission = 0;
    $total_sales_qty = 0;
    $total_sales_consideration = 0;
    $total_sales_commission = 0;
    
    foreach ($processed_transactions as $item) {
        $transaction = $item['data'];
        $fees = $item['fees'];
        
        // Show date group header
        if ($current_date_group !== $transaction['trade_date']) {
            $current_date_group = $transaction['trade_date'];
            $html .= '
            <tr class="section-header">
                <td colspan="19" class="text-left"><strong>' . date('l, F j, Y', strtotime($current_date_group)) . '</strong></td>
            </tr>';
        }
        
        $trade_badge = strtoupper($transaction['trade_side']) === 'BUY' ? 
            '<span class="badge-buy">BUY</span>' : 
            '<span class="badge-sell">SELL</span>';
        
        // Update totals
        if (strtoupper($transaction['trade_side']) === 'BUY') {
            $total_purchases_qty += $transaction['quantity'];
            $total_purchases_consideration += $transaction['consideration'];
            $total_purchases_commission += $fees['brokerage_commission'];
        } else {
            $total_sales_qty += $transaction['quantity'];
            $total_sales_consideration += $transaction['consideration'];
            $total_sales_commission += $fees['brokerage_commission'];
        }
        
        $html .= '
            <tr>
                <td>' . $counter . '</td>
                <td>' . date('d/m/Y', strtotime($transaction['trade_date'])) . '</td>
                <td>' . htmlspecialchars($transaction['trade_reference']) . '</td>
                <td>' . $trade_badge . ':' . substr($transaction['trade_reference'], -6) . '</td>
                <td class="text-left">' . htmlspecialchars($transaction['client_name']) . '<br>
                    <small class="text-muted">' . htmlspecialchars($transaction['client_cds_account']) . '</small>
                </td>
                <td>' . htmlspecialchars($transaction['security_id']) . '<br>
                    <small class="text-muted">' . htmlspecialchars($transaction['security_name']) . '</small>
                </td>
                <td class="text-right">' . number_format($transaction['quantity']) . '</td>
                <td class="text-right">' . number_format($transaction['price'], 4) . '</td>
                <td class="text-right">1.5000</td>
                <td class="text-right">' . number_format($transaction['consideration'], 2) . '</td>
                <td class="text-right">' . number_format($fees['brokerage_commission'], 2) . '</td>
                <td class="text-right">' . number_format($fees['dse_fee'], 2) . '</td>
                <td class="text-right">' . number_format($fees['cmsa_fee'], 2) . '</td>
                <td class="text-right">' . number_format($fees['cds_fee'], 2) . '</td>
                <td class="text-right">' . number_format($fees['vat_on_commission'], 2) . '</td>
                <td class="text-right">' . number_format($fees['total_charges'], 2) . '</td>
                <td class="text-right">' . number_format($item['net_amount'], 2) . '</td>
                <td class="text-right">0.00</td>
                <td class="text-right">' . number_format($fees['brokerage_commission'], 2) . '</td>
            </tr>';
        
        $counter++;
    }
    
    // Add final totals
    $html .= '
            <tr class="total-row">
                <td colspan="5" class="text-left"><strong>TOTAL PURCHASES</strong></td>
                <td class="text-right"><strong>' . number_format($total_purchases_qty) . '</strong></td>
                <td colspan="3"></td>
                <td class="text-right"><strong>' . number_format($total_purchases_consideration, 2) . '</strong></td>
                <td class="text-right"><strong>' . number_format($total_purchases_commission, 2) . '</strong></td>
                <td colspan="8"></td>
            </tr>
            <tr class="total-row">
                <td colspan="5" class="text-left"><strong>TOTAL SALES</strong></td>
                <td class="text-right"><strong>' . number_format($total_sales_qty) . '</strong></td>
                <td colspan="3"></td>
                <td class="text-right"><strong>' . number_format($total_sales_consideration, 2) . '</strong></td>
                <td class="text-right"><strong>' . number_format($total_sales_commission, 2) . '</strong></td>
                <td colspan="8"></td>
            </tr>
            <tr class="total-row">
                <td colspan="5" class="text-left"><strong>GRAND TOTAL TURNOVER</strong></td>
                <td class="text-right"><strong>' . number_format($total_purchases_qty + $total_sales_qty) . '</strong></td>
                <td colspan="3"></td>
                <td class="text-right"><strong>' . number_format($total_purchases_consideration + $total_sales_consideration, 2) . '</strong></td>
                <td class="text-right"><strong>' . number_format($total_purchases_commission + $total_sales_commission, 2) . '</strong></td>
                <td colspan="8"></td>
            </tr>
        </tbody>
    </table>
    </div>
    
    <div class="disclaimer">
        <strong>Disclaimer:</strong> ' . $company_name . ' has prepared this Report solely for informational purposes. ' . $company_name . ' does not represent warrant or guarantee that the
        Reports are accurate. ' . $company_name . ' disclaims liability for any direct indirect punitive special consequential or incidental damages related to the Reports or the use
        of the Reports. This disclaimer applies to the Reports in their entirety irrespective of whether the Reports are used or viewed in whole or in part.
    </div>
    
    <div class="row mt-4">
        <div class="col-md-4">
            <div class="card">
                <div class="card-header bg-success">
                    <strong>Summary</strong>
                </div>
                <div class="card-body">
                    <p class="mb-1">Total Transactions: <strong>' . ($counter - 1) . '</strong></p>
                    <p class="mb-1">Total Purchases: <strong>' . number_format($total_purchases_qty) . ' shares</strong></p>
                    <p class="mb-1">Total Sales: <strong>' . number_format($total_sales_qty) . ' shares</strong></p>
                    <p class="mb-0">Total Turnover: <strong>' . number_format($total_purchases_qty + $total_sales_qty) . ' shares</strong></p>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card">
                <div class="card-header bg-primary">
                    <strong>Financial Summary</strong>
                </div>
                <div class="card-body">
                    <p class="mb-1">Purchases Value: <strong>TZS ' . number_format($total_purchases_consideration, 2) . '</strong></p>
                    <p class="mb-1">Sales Value: <strong>TZS ' . number_format($total_sales_consideration, 2) . '</strong></p>
                    <p class="mb-0">Total Turnover Value: <strong>TZS ' . number_format($total_purchases_consideration + $total_sales_consideration, 2) . '</strong></p>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card">
                <div class="card-header bg-info">
                    <strong>Commission Summary</strong>
                </div>
                <div class="card-body">
                    <p class="mb-1">Purchases Commission: <strong>TZS ' . number_format($total_purchases_commission, 2) . '</strong></p>
                    <p class="mb-1">Sales Commission: <strong>TZS ' . number_format($total_sales_commission, 2) . '</strong></p>
                    <p class="mb-0">Total Commission: <strong>TZS ' . number_format($total_purchases_commission + $total_sales_commission, 2) . '</strong></p>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    </body>
    </html>';
    
    return $html;
}

// ==================== COMBINED EDIT LIST HTML ====================
function generateCombinedEditListHTML($bond_transactions, $equity_transactions, $company_name, $company_address, $company_phone, $company_email, $filters) {
    $chosen_date = date('d/m/Y', strtotime($filters['chosen_date'] ?? date('Y-m-d')));
    $current_date = date('d/m/Y');
    $current_time = date('H:i:s');
    
    $html = '
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Combined Edit List Report</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
        <style>
            @media screen {
                .header { text-align: center; margin-bottom: 20px; border-bottom: 2px solid #dee2e6; padding-bottom: 15px; }
                .company-info { font-size: 12px; margin-bottom: 10px; color: #495057; line-height: 1.4; }
                .report-title { font-size: 18px; font-weight: bold; margin: 10px 0; color: #2c3e50; }
                .report-subtitle { font-size: 14px; margin: 5px 0; color: #6c757d; }
                .disclaimer { font-size: 11px; margin-top: 30px; padding: 15px; background-color: #f8f9fa; border-radius: 5px; color: #666; border-left: 4px solid #6c757d; }
                table { width: 100%; border-collapse: collapse; font-size: 11px; margin: 10px 0; }
                th, td { border: 1px solid #dee2e6; padding: 6px; text-align: center; }
                th { background-color: #e9ecef; font-weight: bold; color: #495057; }
                .text-left { text-align: left; }
                .text-right { text-align: right; }
                .section-header { font-weight: bold; color: #2c3e50; }
                .bond-header { background-color: #e8f4fd; }
                .equity-header { background-color: #e8f5e8; }
                .total-row { background-color: #f8f9fa; font-weight: bold; }
                .bond-total { color: #007bff; }
                .equity-total { color: #28a745; }
                .overall-total { color: #6c757d; }
                .table-responsive { overflow-x: auto; margin-bottom: 20px; }
                .badge-buy { background-color: #28a745; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; }
                .badge-sell { background-color: #dc3545; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; }
                .badge-bond { background-color: #007bff; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; }
                .badge-equity { background-color: #28a745; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; }
                .action-buttons { position: sticky; top: 0; background: white; padding: 10px 0; z-index: 1000; }
                .asset-section { margin-bottom: 30px; }
            }
            @media print {
                .header { text-align: center; margin-bottom: 5px; border-bottom: 1px solid #000; padding-bottom: 3px; }
                .company-info { font-size: 9px; margin-bottom: 5px; }
                .report-title { font-size: 14px; font-weight: bold; margin: 5px 0; }
                .report-subtitle { font-size: 10px; margin: 2px 0; }
                .disclaimer { font-size: 8px; margin-top: 15px; color: #666; }
                table { width: 100%; border-collapse: collapse; font-size: 8px; margin: 3px 0; }
                th, td { border: 1px solid #000; padding: 2px; text-align: center; }
                th { background-color: #f0f0f0; font-weight: bold; }
                .text-left { text-align: left; }
                .text-right { text-align: right; }
                .page-break { page-break-after: always; }
                .action-buttons { display: none !important; }
            }
        </style>
    </head>
    <body>
        <div class="container-fluid">
            <div class="action-buttons mb-3">
                <div class="d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="fas fa-list-alt me-2"></i>Combined Edit List Report</h5>
                    <div>
                        <button onclick="window.print()" class="btn btn-secondary btn-sm">
                            <i class="fas fa-print me-1"></i>Print
                        </button>
                        <a href="editlist.php?action=filter" class="btn btn-primary btn-sm ms-2">
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
                <div class="report-title">COMBINED TRANSACTIONS EDIT LIST (BONDS & SHARES)</div>
                <div class="report-subtitle">FOR DATE: ' . $chosen_date . '</div>
                <div class="report-subtitle">Date Printed: ' . $current_date . ' - ' . $current_time . '</div>
            </div>
            
            <div style="background-color: #f8f9fa; border-left: 4px solid #6c757d; padding: 10px 15px; margin: 10px 0; font-size: 10px; color: #333;">
                <strong>Report Overview:</strong> This Combined Edit List consolidates all bond and equity transactions processed on 
                <strong>' . $chosen_date . '</strong> into a single view. It provides a complete picture of the day\'s trading activity across both asset classes.<br><br>
                <strong>How to Read:</strong> The report is divided into two sections — <strong>Bonds</strong> (blue) and <strong>Shares/Equities</strong> (green). 
                Each section shows individual trades grouped by date, with full fee breakdowns. Bonds use a 0.063% brokerage rate while equities use 1.5%. 
                Summary cards at the bottom of each section show totals for quick reference.<br><br>
                <strong>Purpose:</strong> Use this report for daily reconciliation, ensuring all trades are properly captured and fees are correctly calculated 
                across both asset classes.
            </div>';
    
    // Process bond transactions
    if (!empty($bond_transactions)) {
        $processed_bond_transactions = [];
        $bond_totals = [
            'purchases_qty' => 0,
            'purchases_consideration' => 0,
            'purchases_commission' => 0,
            'sales_qty' => 0,
            'sales_consideration' => 0,
            'sales_commission' => 0
        ];
        
        foreach ($bond_transactions as $transaction) {
            $fees = calculateBondFees($transaction['consideration']);
            $net_amount = strtoupper($transaction['trade_side']) === 'BUY' ? 
                $transaction['consideration'] + $fees['total_charges'] : 
                $transaction['consideration'] - $fees['total_charges'];
            
            $processed_bond_transactions[] = [
                'data' => $transaction,
                'fees' => $fees,
                'net_amount' => $net_amount
            ];
            
            if (strtoupper($transaction['trade_side']) === 'BUY') {
                $bond_totals['purchases_qty'] += $transaction['quantity'];
                $bond_totals['purchases_consideration'] += $transaction['consideration'];
                $bond_totals['purchases_commission'] += $fees['brokerage_commission'];
            } else {
                $bond_totals['sales_qty'] += $transaction['quantity'];
                $bond_totals['sales_consideration'] += $transaction['consideration'];
                $bond_totals['sales_commission'] += $fees['brokerage_commission'];
            }
        }
        
        $html .= '
            <div class="asset-section">
                <h5 class="border-bottom pb-2 mb-3" style="color: #007bff;">
                    <i class="fas fa-file-invoice-dollar me-2"></i>BONDS TRANSACTIONS (' . count($bond_transactions) . ' transactions)
                </h5>
                <div class="table-responsive">
                    <table class="table table-bordered table-striped">
                        <thead>
                            <tr>
                                <th width="3%">#</th>
                                <th width="7%">DATE</th>
                                <th width="9%">SLIP NO</th>
                                <th width="10%">CONTRACT</th>
                                <th width="18%">CLIENT</th>
                                <th width="9%">SECURITY</th>
                                <th width="5%">QUANTITY</th>
                                <th width="4%">PRICE</th>
                                <th width="4%">% RATE</th>
                                <th width="7%">CONSIDERATION</th>
                                <th width="5%">GROSS COMMISSION</th>
                                <th width="4%">DSE LEVY</th>
                                <th width="4%">CMSA LEVY</th>
                                <th width="4%">CSD LEVY</th>
                                <th width="4%">VAT ON COMM</th>
                                <th width="5%">TOTAL CHARGES</th>
                                <th width="7%">GROSS/NET AMOUNT</th>
                                <th width="4%">RETURN COMMISSION</th>
                                <th width="5%">NET COMMISSION</th>
                            </tr>
                        </thead>
                        <tbody>';
        
        $counter = 1;
        foreach ($processed_bond_transactions as $item) {
            $transaction = $item['data'];
            $fees = $item['fees'];
            
            $trade_badge = strtoupper($transaction['trade_side']) === 'BUY' ? 
                '<span class="badge-buy">BUY</span>' : 
                '<span class="badge-sell">SELL</span>';
            
            $html .= '
                <tr>
                    <td>' . $counter . '</td>
                    <td>' . date('d/m/Y', strtotime($transaction['trade_date'])) . '</td>
                    <td>' . htmlspecialchars($transaction['trade_reference']) . '</td>
                    <td>' . $trade_badge . ':' . substr($transaction['trade_reference'], -6) . '</td>
                    <td class="text-left">' . htmlspecialchars($transaction['client_name']) . '<br>
                        <small class="text-muted">' . htmlspecialchars($transaction['client_cds_account']) . '</small>
                    </td>
                    <td>' . htmlspecialchars($transaction['security_id']) . '<br>
                        <small class="text-muted">' . htmlspecialchars($transaction['security_name']) . '</small>
                    </td>
                    <td class="text-right">' . number_format($transaction['quantity']) . '</td>
                    <td class="text-right">' . number_format($transaction['price'], 4) . '</td>
                    <td class="text-right">0.0630</td>
                    <td class="text-right">' . number_format($transaction['consideration'], 2) . '</td>
                    <td class="text-right">' . number_format($fees['brokerage_commission'], 2) . '</td>
                    <td class="text-right">' . number_format($fees['dse_fee'], 2) . '</td>
                    <td class="text-right">' . number_format($fees['cmsa_fee'], 2) . '</td>
                    <td class="text-right">' . number_format($fees['cds_fee'], 2) . '</td>
                    <td class="text-right">' . number_format($fees['vat_on_commission'], 2) . '</td>
                    <td class="text-right">' . number_format($fees['total_charges'], 2) . '</td>
                    <td class="text-right">' . number_format($item['net_amount'], 2) . '</td>
                    <td class="text-right">0.00</td>
                    <td class="text-right">' . number_format($fees['brokerage_commission'], 2) . '</td>
                </tr>';
            
            $counter++;
        }
        
        $html .= '
                        </tbody>
                    </table>
                </div>
                <div class="row mt-2">
                    <div class="col-md-12">
                        <div class="card border-primary">
                            <div class="card-header bg-primary py-2">
                                <strong>Bonds Summary</strong>
                            </div>
                            <div class="card-body p-3">
                                <div class="row">
                                    <div class="col-md-3">
                                        <p class="mb-1">Total Transactions: <strong>' . count($bond_transactions) . '</strong></p>
                                        <p class="mb-0">Purchases: <strong>' . number_format($bond_totals['purchases_qty']) . ' bonds</strong></p>
                                        <p class="mb-0">Sales: <strong>' . number_format($bond_totals['sales_qty']) . ' bonds</strong></p>
                                    </div>
                                    <div class="col-md-3">
                                        <p class="mb-1">Purchases Value: <strong>TZS ' . number_format($bond_totals['purchases_consideration'], 2) . '</strong></p>
                                        <p class="mb-0">Sales Value: <strong>TZS ' . number_format($bond_totals['sales_consideration'], 2) . '</strong></p>
                                    </div>
                                    <div class="col-md-3">
                                        <p class="mb-1">Purchases Commission: <strong>TZS ' . number_format($bond_totals['purchases_commission'], 2) . '</strong></p>
                                        <p class="mb-0">Sales Commission: <strong>TZS ' . number_format($bond_totals['sales_commission'], 2) . '</strong></p>
                                    </div>
                                    <div class="col-md-3">
                                        <p class="mb-1">Total Turnover: <strong>' . number_format($bond_totals['purchases_qty'] + $bond_totals['sales_qty']) . ' bonds</strong></p>
                                        <p class="mb-0">Total Value: <strong>TZS ' . number_format($bond_totals['purchases_consideration'] + $bond_totals['sales_consideration'], 2) . '</strong></p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>';
    }
    
    // Process equity transactions
    if (!empty($equity_transactions)) {
        $processed_equity_transactions = [];
        $equity_totals = [
            'purchases_qty' => 0,
            'purchases_consideration' => 0,
            'purchases_commission' => 0,
            'sales_qty' => 0,
            'sales_consideration' => 0,
            'sales_commission' => 0
        ];
        
        foreach ($equity_transactions as $transaction) {
            $fees = calculateEquityFees($transaction['consideration'], $transaction['price']);
            $net_amount = strtoupper($transaction['trade_side']) === 'BUY' ? 
                $transaction['consideration'] + $fees['total_charges'] : 
                $transaction['consideration'] - $fees['total_charges'];
            
            $processed_equity_transactions[] = [
                'data' => $transaction,
                'fees' => $fees,
                'net_amount' => $net_amount
            ];
            
            if (strtoupper($transaction['trade_side']) === 'BUY') {
                $equity_totals['purchases_qty'] += $transaction['quantity'];
                $equity_totals['purchases_consideration'] += $transaction['consideration'];
                $equity_totals['purchases_commission'] += $fees['brokerage_commission'];
            } else {
                $equity_totals['sales_qty'] += $transaction['quantity'];
                $equity_totals['sales_consideration'] += $transaction['consideration'];
                $equity_totals['sales_commission'] += $fees['brokerage_commission'];
            }
        }
        
        $html .= '
            <div class="asset-section">
                <h5 class="border-bottom pb-2 mb-3" style="color: #28a745;">
                    <i class="fas fa-chart-line me-2"></i>SHARES (EQUITIES) TRANSACTIONS (' . count($equity_transactions) . ' transactions)
                </h5>
                <div class="table-responsive">
                    <table class="table table-bordered table-striped">
                        <thead>
                            <tr>
                                <th width="3%">#</th>
                                <th width="7%">DATE</th>
                                <th width="9%">SLIP NO</th>
                                <th width="10%">CONTRACT</th>
                                <th width="18%">CLIENT</th>
                                <th width="9%">SECURITY</th>
                                <th width="5%">QUANTITY</th>
                                <th width="4%">PRICE</th>
                                <th width="4%">% RATE</th>
                                <th width="7%">CONSIDERATION</th>
                                <th width="5%">GROSS COMMISSION</th>
                                <th width="4%">DSE LEVY</th>
                                <th width="4%">CMSA LEVY</th>
                                <th width="4%">CSD LEVY</th>
                                <th width="4%">VAT ON COMM</th>
                                <th width="5%">TOTAL CHARGES</th>
                                <th width="7%">GROSS/NET AMOUNT</th>
                                <th width="4%">RETURN COMMISSION</th>
                                <th width="5%">NET COMMISSION</th>
                            </tr>
                        </thead>
                        <tbody>';
        
        $counter = 1;
        foreach ($processed_equity_transactions as $item) {
            $transaction = $item['data'];
            $fees = $item['fees'];
            
            $trade_badge = strtoupper($transaction['trade_side']) === 'BUY' ? 
                '<span class="badge-buy">BUY</span>' : 
                '<span class="badge-sell">SELL</span>';
            
            $html .= '
                <tr>
                    <td>' . $counter . '</td>
                    <td>' . date('d/m/Y', strtotime($transaction['trade_date'])) . '</td>
                    <td>' . htmlspecialchars($transaction['trade_reference']) . '</td>
                    <td>' . $trade_badge . ':' . substr($transaction['trade_reference'], -6) . '</td>
                    <td class="text-left">' . htmlspecialchars($transaction['client_name']) . '<br>
                        <small class="text-muted">' . htmlspecialchars($transaction['client_cds_account']) . '</small>
                    </td>
                    <td>' . htmlspecialchars($transaction['security_id']) . '<br>
                        <small class="text-muted">' . htmlspecialchars($transaction['security_name']) . '</small>
                    </td>
                    <td class="text-right">' . number_format($transaction['quantity']) . '</td>
                    <td class="text-right">' . number_format($transaction['price'], 4) . '</td>
                    <td class="text-right">1.5000</td>
                    <td class="text-right">' . number_format($transaction['consideration'], 2) . '</td>
                    <td class="text-right">' . number_format($fees['brokerage_commission'], 2) . '</td>
                    <td class="text-right">' . number_format($fees['dse_fee'], 2) . '</td>
                    <td class="text-right">' . number_format($fees['cmsa_fee'], 2) . '</td>
                    <td class="text-right">' . number_format($fees['cds_fee'], 2) . '</td>
                    <td class="text-right">' . number_format($fees['vat_on_commission'], 2) . '</td>
                    <td class="text-right">' . number_format($fees['total_charges'], 2) . '</td>
                    <td class="text-right">' . number_format($item['net_amount'], 2) . '</td>
                    <td class="text-right">0.00</td>
                    <td class="text-right">' . number_format($fees['brokerage_commission'], 2) . '</td>
                </tr>';
            
            $counter++;
        }
        
        $html .= '
                        </tbody>
                    </table>
                </div>
                <div class="row mt-2">
                    <div class="col-md-12">
                        <div class="card border-success">
                            <div class="card-header bg-success py-2">
                                <strong>Shares Summary</strong>
                            </div>
                            <div class="card-body p-3">
                                <div class="row">
                                    <div class="col-md-3">
                                        <p class="mb-1">Total Transactions: <strong>' . count($equity_transactions) . '</strong></p>
                                        <p class="mb-0">Purchases: <strong>' . number_format($equity_totals['purchases_qty']) . ' shares</strong></p>
                                        <p class="mb-0">Sales: <strong>' . number_format($equity_totals['sales_qty']) . ' shares</strong></p>
                                    </div>
                                    <div class="col-md-3">
                                        <p class="mb-1">Purchases Value: <strong>TZS ' . number_format($equity_totals['purchases_consideration'], 2) . '</strong></p>
                                        <p class="mb-0">Sales Value: <strong>TZS ' . number_format($equity_totals['sales_consideration'], 2) . '</strong></p>
                                    </div>
                                    <div class="col-md-3">
                                        <p class="mb-1">Purchases Commission: <strong>TZS ' . number_format($equity_totals['purchases_commission'], 2) . '</strong></p>
                                        <p class="mb-0">Sales Commission: <strong>TZS ' . number_format($equity_totals['sales_commission'], 2) . '</strong></p>
                                    </div>
                                    <div class="col-md-3">
                                        <p class="mb-1">Total Turnover: <strong>' . number_format($equity_totals['purchases_qty'] + $equity_totals['sales_qty']) . ' shares</strong></p>
                                        <p class="mb-0">Total Value: <strong>TZS ' . number_format($equity_totals['purchases_consideration'] + $equity_totals['sales_consideration'], 2) . '</strong></p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>';
    }
    
    // Add overall summary if both sections exist
    if (!empty($bond_transactions) && !empty($equity_transactions)) {
        // Calculate totals from both sections
        $bond_totals = [
            'purchases_qty' => 0,
            'purchases_consideration' => 0,
            'purchases_commission' => 0,
            'sales_qty' => 0,
            'sales_consideration' => 0,
            'sales_commission' => 0
        ];
        
        foreach ($bond_transactions as $transaction) {
            $fees = calculateBondFees($transaction['consideration']);
            if (strtoupper($transaction['trade_side']) === 'BUY') {
                $bond_totals['purchases_qty'] += $transaction['quantity'];
                $bond_totals['purchases_consideration'] += $transaction['consideration'];
                $bond_totals['purchases_commission'] += $fees['brokerage_commission'];
            } else {
                $bond_totals['sales_qty'] += $transaction['quantity'];
                $bond_totals['sales_consideration'] += $transaction['consideration'];
                $bond_totals['sales_commission'] += $fees['brokerage_commission'];
            }
        }
        
        $equity_totals = [
            'purchases_qty' => 0,
            'purchases_consideration' => 0,
            'purchases_commission' => 0,
            'sales_qty' => 0,
            'sales_consideration' => 0,
            'sales_commission' => 0
        ];
        
        foreach ($equity_transactions as $transaction) {
            $fees = calculateEquityFees($transaction['consideration'], $transaction['price']);
            if (strtoupper($transaction['trade_side']) === 'BUY') {
                $equity_totals['purchases_qty'] += $transaction['quantity'];
                $equity_totals['purchases_consideration'] += $transaction['consideration'];
                $equity_totals['purchases_commission'] += $fees['brokerage_commission'];
            } else {
                $equity_totals['sales_qty'] += $transaction['quantity'];
                $equity_totals['sales_consideration'] += $transaction['consideration'];
                $equity_totals['sales_commission'] += $fees['brokerage_commission'];
            }
        }
        
        // Calculate overall totals
        $overall_purchases_qty = $bond_totals['purchases_qty'] + $equity_totals['purchases_qty'];
        $overall_purchases_consideration = $bond_totals['purchases_consideration'] + $equity_totals['purchases_consideration'];
        $overall_purchases_commission = $bond_totals['purchases_commission'] + $equity_totals['purchases_commission'];
        $overall_sales_qty = $bond_totals['sales_qty'] + $equity_totals['sales_qty'];
        $overall_sales_consideration = $bond_totals['sales_consideration'] + $equity_totals['sales_consideration'];
        $overall_sales_commission = $bond_totals['sales_commission'] + $equity_totals['sales_commission'];
        $overall_turnover_qty = $overall_purchases_qty + $overall_sales_qty;
        $overall_turnover_consideration = $overall_purchases_consideration + $overall_sales_consideration;
        $overall_commission = $overall_purchases_commission + $overall_sales_commission;
        
        $html .= '
            <div class="row mt-4">
                <div class="col-md-12">
                    <div class="card border-dark">
                        <div class="card-header bg-dark py-2">
                            <strong><i class="fas fa-calculator me-2"></i>OVERALL SUMMARY</strong>
                        </div>
                        <div class="card-body p-3">
                            <div class="row">
                                <div class="col-md-4">
                                    <h6 class="border-bottom pb-1">Transaction Summary</h6>
                                    <p class="mb-1">Total Transactions: <strong>' . (count($bond_transactions) + count($equity_transactions)) . '</strong></p>
                                    <p class="mb-1">Bond Transactions: <strong>' . count($bond_transactions) . '</strong></p>
                                    <p class="mb-0">Share Transactions: <strong>' . count($equity_transactions) . '</strong></p>
                                </div>
                                <div class="col-md-4">
                                    <h6 class="border-bottom pb-1">Volume Summary</h6>
                                    <p class="mb-1">Total Purchases: <strong>' . number_format($overall_purchases_qty) . ' units</strong></p>
                                    <p class="mb-1">Total Sales: <strong>' . number_format($overall_sales_qty) . ' units</strong></p>
                                    <p class="mb-0">Total Turnover: <strong>' . number_format($overall_turnover_qty) . ' units</strong></p>
                                </div>
                                <div class="col-md-4">
                                    <h6 class="border-bottom pb-1">Financial Summary</h6>
                                    <p class="mb-1">Total Turnover Value: <strong>TZS ' . number_format($overall_turnover_consideration, 2) . '</strong></p>
                                    <p class="mb-1">Total Commission: <strong>TZS ' . number_format($overall_commission, 2) . '</strong></p>
                                    <p class="mb-0">Average Commission Rate: <strong>' . ($overall_turnover_consideration > 0 ? number_format(($overall_commission / $overall_turnover_consideration) * 100, 2) : '0.00') . '%</strong></p>
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
function generateBondsEditListReport($db, $company_name, $company_address, $company_phone, $company_email, $filters) {
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

    // Client filter
    if (!empty($filters['client'])) {
        $where_conditions[] = "t.client_cds_account = ?";
        $params[] = $filters['client'];
    }

    // Security filter
    if (!empty($filters['security'])) {
        $where_conditions[] = "t.security_id = ?";
        $params[] = $filters['security'];
    }

    // Trade type filter (BUY/SELL)
    if (!empty($filters['trade_type'])) {
        $where_conditions[] = "t.trade_side = ?";
        $params[] = strtoupper($filters['trade_type']);
    }

    $where_clause = implode(' AND ', $where_conditions);

    // Get bond transactions data
    $stmt = $db->prepare("
        SELECT 
            t.id,
            t.trade_date,
            t.trade_reference,
            t.trade_side,
            t.client_name,
            t.client_cds_account,
            t.security_id,
            t.security_name,
            t.quantity,
            t.price,
            t.consideration,
            t.created_at
        FROM trades t
        WHERE $where_clause
        ORDER BY t.trade_date, t.created_at
    ");
    $stmt->execute($params);
    $transactions = $stmt->fetchAll();

    if (empty($transactions)) {
        echo '<div class="container py-5">
                <div class="alert alert-info text-center py-5">
                    <i class="fas fa-info-circle fa-3x text-muted mb-3"></i>
                    <h5>No Bond Transactions Found</h5>
                    <p class="text-muted">No bond trades match your selected criteria. Please adjust your filters and try again.</p>
                    <a href="editlist.php?action=filter" class="btn btn-primary mt-3">
                        <i class="fas fa-filter me-2"></i>Apply New Filter
                    </a>
                </div>
              </div>';
        return;
    }

    // Check if we're exporting to PDF
    if (isset($_POST['export_type']) && $_POST['export_type'] === 'pdf') {
        // Create PDF in LANDSCAPE orientation
        $pdf = new TCPDF('L', PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
        $pdf->SetCreator($company_name);
        $pdf->SetAuthor($company_name);
        $pdf->SetTitle('Bonds Purchases & Sales Transactions Edit List');
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(8, 8, 8);
        $pdf->SetAutoPageBreak(TRUE, 10);
        $pdf->AddPage();

        $html = generateBondsEditListHTML($transactions, $company_name, $company_address, $company_phone, $company_email, $filters);
        $pdf->writeHTML($html, true, false, true, false, '');
        $pdf->Output('bonds_edit_list_' . date('Y_m_d') . '.pdf', 'I');
    } else {
        // Display HTML report
        echo generateBondsEditListHTML($transactions, $company_name, $company_address, $company_phone, $company_email, $filters);
    }
}

function generateEquitiesEditListReport($db, $company_name, $company_address, $company_phone, $company_email, $filters) {
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

    // Client filter
    if (!empty($filters['client'])) {
        $where_conditions[] = "t.client_cds_account = ?";
        $params[] = $filters['client'];
    }

    // Security filter
    if (!empty($filters['security'])) {
        $where_conditions[] = "t.security_id = ?";
        $params[] = $filters['security'];
    }

    // Trade type filter (BUY/SELL)
    if (!empty($filters['trade_type'])) {
        $where_conditions[] = "t.trade_side = ?";
        $params[] = strtoupper($filters['trade_type']);
    }

    $where_clause = implode(' AND ', $where_conditions);

    // Get equity transactions data
    $stmt = $db->prepare("
        SELECT 
            t.id,
            t.trade_date,
            t.trade_reference,
            t.trade_side,
            t.client_name,
            t.client_cds_account,
            t.security_id,
            t.security_name,
            t.quantity,
            t.price,
            t.consideration,
            t.created_at
        FROM trades t
        WHERE $where_clause
        ORDER BY t.trade_date, t.created_at
    ");
    $stmt->execute($params);
    $transactions = $stmt->fetchAll();

    if (empty($transactions)) {
        echo '<div class="container py-5">
                <div class="alert alert-info text-center py-5">
                    <i class="fas fa-info-circle fa-3x text-muted mb-3"></i>
                    <h5>No Equity Transactions Found</h5>
                    <p class="text-muted">No equity trades match your selected criteria. Please adjust your filters and try again.</p>
                    <a href="editlist.php?action=filter" class="btn btn-primary mt-3">
                        <i class="fas fa-filter me-2"></i>Apply New Filter
                    </a>
                </div>
              </div>';
        return;
    }

    // Check if we're exporting to PDF
    if (isset($_POST['export_type']) && $_POST['export_type'] === 'pdf') {
        // Create PDF in LANDSCAPE orientation
        $pdf = new TCPDF('L', PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
        $pdf->SetCreator($company_name);
        $pdf->SetAuthor($company_name);
        $pdf->SetTitle('Equities Purchases & Sales Transactions Edit List');
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(8, 8, 8);
        $pdf->SetAutoPageBreak(TRUE, 10);
        $pdf->AddPage();

        $html = generateEquitiesEditListHTML($transactions, $company_name, $company_address, $company_phone, $company_email, $filters);
        $pdf->writeHTML($html, true, false, true, false, '');
        $pdf->Output('equities_edit_list_' . date('Y_m_d') . '.pdf', 'I');
    } else {
        // Display HTML report
        echo generateEquitiesEditListHTML($transactions, $company_name, $company_address, $company_phone, $company_email, $filters);
    }
}

function generateCombinedEditListReport($db, $company_name, $company_address, $company_phone, $company_email, $filters) {
    $chosen_date = $filters['chosen_date'] ?? date('Y-m-d');
    
    // Get bond transactions for chosen date
    $bond_where_conditions = ["t.asset_class = 'bond'", "t.status = 'active'", "DATE(t.trade_date) = ?"];
    $bond_params = [$chosen_date];
    
    $bond_where_clause = implode(' AND ', $bond_where_conditions);
    
    $bond_stmt = $db->prepare("
        SELECT 
            t.id,
            t.trade_date,
            t.trade_reference,
            t.trade_side,
            t.client_name,
            t.client_cds_account,
            t.security_id,
            t.security_name,
            t.quantity,
            t.price,
            t.consideration,
            t.created_at
        FROM trades t
        WHERE $bond_where_clause
        ORDER BY t.trade_date, t.created_at
    ");
    $bond_stmt->execute($bond_params);
    $bond_transactions = $bond_stmt->fetchAll();
    
    // Get equity transactions for chosen date
    $equity_where_conditions = ["t.asset_class = 'equity'", "t.status = 'active'", "DATE(t.trade_date) = ?"];
    $equity_params = [$chosen_date];
    
    $equity_where_clause = implode(' AND ', $equity_where_conditions);
    
    $equity_stmt = $db->prepare("
        SELECT 
            t.id,
            t.trade_date,
            t.trade_reference,
            t.trade_side,
            t.client_name,
            t.client_cds_account,
            t.security_id,
            t.security_name,
            t.quantity,
            t.price,
            t.consideration,
            t.created_at
        FROM trades t
        WHERE $equity_where_clause
        ORDER BY t.trade_date, t.created_at
    ");
    $equity_stmt->execute($equity_params);
    $equity_transactions = $equity_stmt->fetchAll();
    
    if (empty($bond_transactions) && empty($equity_transactions)) {
        echo '<div class="container py-5">
                <div class="alert alert-info text-center py-5">
                    <i class="fas fa-info-circle fa-3x text-muted mb-3"></i>
                    <h5>No Transactions Found</h5>
                    <p class="text-muted">No trades found for the chosen date. Please adjust your filters and try again.</p>
                    <a href="editlist.php?action=filter" class="btn btn-primary mt-3">
                        <i class="fas fa-filter me-2"></i>Apply New Filter
                    </a>
                </div>
              </div>';
        return;
    }
    
    // Check if we're exporting to PDF
    if (isset($_POST['export_type']) && $_POST['export_type'] === 'pdf') {
        // Create PDF in LANDSCAPE orientation
        $pdf = new TCPDF('L', PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
        $pdf->SetCreator($company_name);
        $pdf->SetAuthor($company_name);
        $pdf->SetTitle('Combined Transactions Edit List (Bonds & Shares)');
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(8, 8, 8);
        $pdf->SetAutoPageBreak(TRUE, 10);
        $pdf->AddPage();

        $html = generateCombinedEditListHTML($bond_transactions, $equity_transactions, $company_name, $company_address, $company_phone, $company_email, $filters);
        $pdf->writeHTML($html, true, false, true, false, '');
        $pdf->Output('combined_edit_list_' . date('Y_m_d') . '.pdf', 'I');
    } else {
        // Display HTML report
        echo generateCombinedEditListHTML($bond_transactions, $equity_transactions, $company_name, $company_address, $company_phone, $company_email, $filters);
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
        <title>Edit List Reports - Filter</title>
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
                            <h4 class="mb-0"><i class="fas fa-filter me-2"></i>Edit List Reports Filter</h4>
                        </div>
                        <div class="card-body">
                            <form method="post" action="editlist" id="filterForm">
                                <div class="row mb-4">
                                    <div class="col-md-12">
                                        <h5 class="border-bottom pb-2 mb-3">Select Report Type</h5>
                                        <div class="row">
                                            <div class="col-md-4 mb-3">
                                                <button type="button" class="btn btn-outline-primary w-100 report-type-btn" data-report="bonds_edit_list">
                                                    <i class="fas fa-file-invoice-dollar fa-2x mb-2"></i><br>
                                                    <strong>Bonds Edit List</strong><br>
                                                    <small class="text-muted">Detailed bond transactions</small>
                                                </button>
                                            </div>
                                            <div class="col-md-4 mb-3">
                                                <button type="button" class="btn btn-outline-success w-100 report-type-btn" data-report="equities_edit_list">
                                                    <i class="fas fa-chart-line fa-2x mb-2"></i><br>
                                                    <strong>Equities Edit List</strong><br>
                                                    <small class="text-muted">Detailed equity transactions</small>
                                                </button>
                                            </div>
                                            <div class="col-md-4 mb-3">
                                                <button type="button" class="btn btn-outline-info w-100 report-type-btn" data-report="combined_edit_list">
                                                    <i class="fas fa-list-alt fa-2x mb-2"></i><br>
                                                    <strong>Combined Edit List</strong><br>
                                                    <small class="text-muted">Bonds + Shares transactions</small>
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
                                                <label class="form-label">Date</label>
                                                <input type="date" class="form-control" name="chosen_date" value="' . date('Y-m-d') . '" required>
                                                <small class="text-muted">Select the date for the report</small>
                                            </div>
                                            <div class="col-md-6 mb-3">
                                                <label class="form-label">Trade Type</label>
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
                                                <i class="fas fa-file-alt me-2"></i>Generate Report
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
                                    <h6>Bonds Edit List</h6>
                                    <p class="text-muted small">Detailed listing of all bond transactions with fees calculation and totals.</p>
                                </div>
                                <div class="col-md-4">
                                    <h6>Equities Edit List</h6>
                                    <p class="text-muted small">Detailed listing of all share/equity transactions with fees calculation.</p>
                                </div>
                                <div class="col-md-4">
                                    <h6>Combined Edit List</h6>
                                    <p class="text-muted small">Complete listing showing bonds first, then shares for the selected date.</p>
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
                    
                    if(reportType === "bonds_edit_list") {
                        this.classList.add("active", "btn-primary");
                    } else if(reportType === "equities_edit_list") {
                        this.classList.add("active", "btn-success");
                    } else if(reportType === "combined_edit_list") {
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
            
            // Select bonds edit list by default
            document.querySelector(\'[data-report="bonds_edit_list"]\').click();
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

// Handle report generation based on report type
switch ($report_name) {
    case 'bonds_edit_list':
        generateBondsEditListReport($db, $company_name, $company_address, $company_phone, $company_email, $_POST);
        break;
        
    case 'equities_edit_list':
        generateEquitiesEditListReport($db, $company_name, $company_address, $company_phone, $company_email, $_POST);
        break;
        
    case 'combined_edit_list':
        generateCombinedEditListReport($db, $company_name, $company_address, $company_phone, $company_email, $_POST);
        break;
        
    default:
        echo '<div class="container py-5">
                <div class="alert alert-warning text-center py-5">
                    <i class="fas fa-exclamation-triangle fa-3x text-warning mb-3"></i>
                    <h5>Invalid Report Type</h5>
                    <p class="text-muted">The selected report type is not available.</p>
                    <a href="editlist.php?action=filter" class="btn btn-primary mt-3">
                        <i class="fas fa-filter me-2"></i>Select Report Type
                    </a>
                </div>
              </div>';
        break;
}
?>