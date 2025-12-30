<?php
require_once '../config/config.php';
require_once '../auth/auth_middleware.php';
require_once '../tcpdf/tcpdf.php';

require_login();
$db = getDBConnection();

// Get company details
$company_stmt = $db->query("SELECT company_name, phone, address, email FROM companies WHERE status = 'active' LIMIT 1");
$company = $company_stmt->fetch();
$company_name = $company ? $company['company_name'] : 'Victory Financial Services Limited';

// Get filter parameters
$filters = [
    'client' => $_POST['client'] ?? '',
    'agent' => $_POST['agent'] ?? '',
    'broker' => $_POST['broker'] ?? '',
    'security' => $_POST['security'] ?? '',
    'asset_class' => $_POST['asset_class'] ?? '',
    'trade_type' => $_POST['trade_type'] ?? '',
    'user' => $_POST['user'] ?? '',
    'status' => $_POST['status'] ?? 'active',
    'period_from' => $_POST['period_from'] ?? date('Y-01-01'),
    'period_to' => $_POST['period_to'] ?? date('Y-m-d'),
    'report_name' => $_POST['report_name'] ?? ''
];

// Build WHERE conditions for bonds
$where_conditions = ["t.asset_class = 'bond'", "t.status = 'active'"];
$params = [];

// Date range filter
if (!empty($filters['period_from'])) {
    $where_conditions[] = "t.trade_date >= ?";
    $params[] = $filters['period_from'];
}
if (!empty($filters['period_to'])) {
    $where_conditions[] = "t.trade_date <= ?";
    $params[] = $filters['period_to'];
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

// Handle different report types
switch ($filters['report_name']) {
    case 'bonds_edit_list':
        generateBondsEditListReport($db, $where_clause, $params, $company_name, $filters);
        break;
        
    case 'bonds_summary':
        generateBondsSummaryReport($db, $where_clause, $params, $company_name, $filters);
        break;
        
    default:
        echo "Please select a valid report type.";
        exit;
}

function generateBondsEditListReport($db, $where_clause, $params, $company_name, $filters) {
    // Get bond transactions data
    $stmt = $db->prepare("
        SELECT 
            t.id,
            t.trade_date,
            t.trade_reference,
            t.trade_side,
            t.client_name,
            t.client_cds_account,
            t.broker_name,
            t.security_id,
            t.security_name,
            t.quantity,
            t.price,
            t.consideration,
            t.created_at,
            u.full_name as agent_name,
            b.coupon_rate,
            b.maturity_date
        FROM trades t
        LEFT JOIN users u ON t.uploaded_by = u.id
        LEFT JOIN bonds b ON t.security_id = b.security_id
        WHERE $where_clause
        ORDER BY t.trade_date, t.created_at
    ");
    $stmt->execute($params);
    $transactions = $stmt->fetchAll();

    // Create PDF
    $pdf = new TCPDF('L', PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
    $pdf->SetCreator($company_name);
    $pdf->SetAuthor($company_name);
    $pdf->SetTitle('Bonds Purchases & Sales Transactions Edit List');
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    $pdf->SetMargins(10, 10, 10);
    $pdf->SetAutoPageBreak(TRUE, 15);
    $pdf->AddPage();

    // Calculate fees for each transaction
    $processed_transactions = [];
    $daily_totals = [];
    
    foreach ($transactions as $transaction) {
        $fees = calculateBondFees($transaction['consideration']);
        
        $processed_transactions[] = [
            'data' => $transaction,
            'fees' => $fees,
            'net_amount' => $transaction['trade_side'] === 'BUY' ? 
                $transaction['consideration'] + $fees['total_charges'] : 
                $transaction['consideration'] - $fees['total_charges']
        ];
        
        // Group by date for daily totals
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
        
        if ($transaction['trade_side'] === 'BUY') {
            $daily_totals[$date]['purchases_qty'] += $transaction['quantity'];
            $daily_totals[$date]['purchases_consideration'] += $transaction['consideration'];
            $daily_totals[$date]['purchases_commission'] += $fees['brokerage_commission'];
        } else {
            $daily_totals[$date]['sales_qty'] += $transaction['quantity'];
            $daily_totals[$date]['sales_consideration'] += $transaction['consideration'];
            $daily_totals[$date]['sales_commission'] += $fees['brokerage_commission'];
        }
    }

    // Generate HTML content
    $html = generateBondsEditListHTML($processed_transactions, $daily_totals, $company_name, $filters);
    $pdf->writeHTML($html, true, false, true, false, '');
    $pdf->Output('bonds_edit_list_' . date('Y_m_d') . '.pdf', 'I');
}

function generateBondsSummaryReport($db, $where_clause, $params, $company_name, $filters) {
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

    // Calculate client balances
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
        
        if ($transaction['trade_side'] === 'SELL') {
            $client_balances[$client_key]['inflow'] += $transaction['total_consideration'];
        } else {
            $client_balances[$client_key]['outflow'] += $transaction['total_consideration'];
        }
        
        $client_balances[$client_key]['net'] = 
            $client_balances[$client_key]['inflow'] - $client_balances[$client_key]['outflow'];
    }

    // Create PDF
    $pdf = new TCPDF('P', PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
    $pdf->SetCreator($company_name);
    $pdf->SetAuthor($company_name);
    $pdf->SetTitle('Bonds Transactions Summary Report');
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    $pdf->SetMargins(10, 10, 10);
    $pdf->SetAutoPageBreak(TRUE, 15);
    $pdf->AddPage();

    $html = generateBondsSummaryHTML($client_balances, $company_name, $filters);
    $pdf->writeHTML($html, true, false, true, false, '');
    $pdf->Output('bonds_summary_' . date('Y_m_d') . '.pdf', 'I');
}

function calculateBondFees($consideration) {
    $rates = [
        'brokerage' => 0.063, // 0.063% as shown in PDF
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

function generateBondsEditListHTML($transactions, $daily_totals, $company_name, $filters) {
    $period_from = date('d/m/Y', strtotime($filters['period_from']));
    $period_to = date('d/m/Y', strtotime($filters['period_to']));
    $current_date = date('d/m/Y');
    $current_time = date('H:i:s');
    
    $html = '
    <style>
        .header { text-align: center; margin-bottom: 10px; border-bottom: 1px solid #000; padding-bottom: 5px; }
        .report-title { font-size: 14px; font-weight: bold; margin: 5px 0; }
        .report-subtitle { font-size: 10px; margin: 2px 0; }
        .disclaimer { font-size: 8px; margin-top: 20px; color: #666; }
        table { width: 100%; border-collapse: collapse; font-size: 8px; margin: 5px 0; }
        th, td { border: 1px solid #000; padding: 3px; text-align: center; }
        th { background-color: #f0f0f0; font-weight: bold; }
        .text-left { text-align: left; }
        .text-right { text-align: right; }
        .section-header { background-color: #e0e0e0; font-weight: bold; }
        .total-row { background-color: #f8f8f8; font-weight: bold; }
    </style>
    
    <div class="header">
        <div class="report-title">' . strtoupper($company_name) . '</div>
        <div class="report-subtitle">Division : STOCK BROKING</div>
        <div class="report-title">BONDS PURCHASES & SALES TRANSACTIONS EDIT LIST</div>
        <div class="report-subtitle">FOR THE PERIOD [' . $period_from . ' - ' . $period_to . ']</div>
        <div class="report-subtitle">Date : ' . $current_date . ' : ' . $current_time . '</div>
    </div>
    
    <table>
        <thead>
            <tr>
                <th width="3%">#</th>
                <th width="8%">DATE</th>
                <th width="10%">SLIPNO</th>
                <th width="12%">CONTRACT</th>
                <th width="15%">CLIENT</th>
                <th width="8%">BROKER</th>
                <th width="8%">AGENT</th>
                <th width="10%">SECURITY</th>
                <th width="6%">QUANTITY</th>
                <th width="5%">PRICE</th>
                <th width="4%">% RATE</th>
                <th width="8%">CONSIDERATION</th>
                <th width="6%">GROSS COMMISSION</th>
                <th width="5%">DSE T/LEVY</th>
                <th width="5%">CMSA T/LEVY</th>
                <th width="5%">CSD T/LEVY</th>
                <th width="5%">VAT ON COMM</th>
                <th width="6%">TOTAL CHARGES</th>
                <th width="8%">GROSS/NET AMOUNT</th>
                <th width="5%">RETURN COMMISSION</th>
                <th width="6%">NET COMMISSION</th>
            </tr>
        </thead>
        <tbody>';
    
    $counter = 1;
    $current_date_group = '';
    
    foreach ($transactions as $item) {
        $transaction = $item['data'];
        $fees = $item['fees'];
        
        // Show date group header
        if ($current_date_group !== $transaction['trade_date']) {
            $current_date_group = $transaction['trade_date'];
            $html .= '
            <tr class="section-header">
                <td colspan="21" class="text-left">' . date('Y-m-d', strtotime($current_date_group)) . '</td>
            </tr>';
        }
        
        $html .= '
            <tr>
                <td>' . $counter . '</td>
                <td>' . date('d/m/Y', strtotime($transaction['trade_date'])) . '</td>
                <td>' . htmlspecialchars($transaction['trade_reference']) . '</td>
                <td>' . strtoupper($transaction['trade_side']) . ':' . substr($transaction['trade_reference'], -6) . '</td>
                <td class="text-left">' . htmlspecialchars($transaction['client_name']) . '</td>
                <td>' . htmlspecialchars($transaction['broker_name'] ?? 'B01') . '</td>
                <td>' . htmlspecialchars($transaction['agent_name'] ?? 'D00001') . '</td>
                <td>' . htmlspecialchars($transaction['security_id']) . '</td>
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
        
        // Show daily totals after each date group
        if (!isset($transactions[$counter]) || $transactions[$counter]['data']['trade_date'] !== $current_date_group) {
            $date_totals = $daily_totals[$current_date_group] ?? [
                'purchases_qty' => 0, 'purchases_consideration' => 0, 'purchases_commission' => 0,
                'sales_qty' => 0, 'sales_consideration' => 0, 'sales_commission' => 0
            ];
            
            $html .= '
            <tr class="total-row">
                <td colspan="8" class="text-left">PURCHASES</td>
                <td class="text-right">' . number_format($date_totals['purchases_qty']) . '</td>
                <td colspan="2"></td>
                <td class="text-right">' . number_format($date_totals['purchases_consideration'], 2) . '</td>
                <td class="text-right">' . number_format($date_totals['purchases_commission'], 2) . '</td>
                <td colspan="7"></td>
            </tr>
            <tr class="total-row">
                <td colspan="8" class="text-left">SALES</td>
                <td class="text-right">' . number_format($date_totals['sales_qty']) . '</td>
                <td colspan="2"></td>
                <td class="text-right">' . number_format($date_totals['sales_consideration'], 2) . '</td>
                <td class="text-right">' . number_format($date_totals['sales_commission'], 2) . '</td>
                <td colspan="7"></td>
            </tr>
            <tr class="total-row">
                <td colspan="8" class="text-left">TURNOVER</td>
                <td class="text-right">' . number_format($date_totals['purchases_qty'] + $date_totals['sales_qty']) . '</td>
                <td colspan="2"></td>
                <td class="text-right">' . number_format($date_totals['purchases_consideration'] + $date_totals['sales_consideration'], 2) . '</td>
                <td class="text-right">' . number_format($date_totals['purchases_commission'] + $date_totals['sales_commission'], 2) . '</td>
                <td colspan="7"></td>
            </tr>';
        }
    }
    
    $html .= '
        </tbody>
    </table>
    
    <div class="disclaimer">
        ' . $company_name . ' has prepared this Report solely for informational purposes. ' . $company_name . ' does not represent warrant or guarantee that the
        Reports are accurate. ' . $company_name . ' disclaims liability for any direct indirect punitive special consequential or incidental damages related to the Reports or the use
        of the Reports. This disclaimer applies to the Reports in their entirety irrespective of whether the Reports are used or viewed in whole or in part.
    </div>';
    
    return $html;
}

function generateBondsSummaryHTML($client_balances, $company_name, $filters) {
    $current_date = date('d/m/Y');
    
    $html = '
    <style>
        .header { text-align: center; margin-bottom: 10px; border-bottom: 1px solid #000; padding-bottom: 5px; }
        .company-title { font-size: 16px; font-weight: bold; margin: 5px 0; }
        .company-subtitle { font-size: 10px; margin: 2px 0; }
        .division { font-size: 11px; margin: 10px 0; font-weight: bold; }
        .disclaimer { font-size: 8px; margin-top: 20px; color: #666; }
        table { width: 100%; border-collapse: collapse; font-size: 9px; margin: 5px 0; }
        th, td { border: 1px solid #000; padding: 4px; }
        th { background-color: #f0f0f0; font-weight: bold; }
        .text-left { text-align: left; }
        .text-right { text-align: right; }
        .total-row { background-color: #f8f8f8; font-weight: bold; }
        .contact-info { font-size: 9px; margin: 5px 0; }
    </style>
    
    <div class="header">
        <div class="company-title">' . strtoupper($company_name) . '</div>
        <div class="company-subtitle">Stock Broker / Dealer & Investment Advisor</div>
        <div class="company-subtitle">Member of Dar es Salaam Stock Exchange</div>
        <div class="contact-info">
            ATC HOUSE, OHIO STREET/GARDEN AVENUE PO BOX 8706 DAR ES SALAAM<br>
            Tel: +255 22 2112091 Mob: +255 788 284 540 Email: info@vfsl.co.tz
        </div>
        <div class="division">Division : STOCK BROKING</div>
    </div>
    
    <table>
        <thead>
            <tr>
                <th width="5%">#</th>
                <th width="25%">Name</th>
                <th width="15%">Inflow</th>
                <th width="15%">Outflow</th>
                <th width="15%">Net</th>
                <th width="25%">Balance</th>
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
        
        $html .= '
            <tr>
                <td>' . $counter . '</td>
                <td class="text-left">' . htmlspecialchars($client['client_name']) . '</td>
                <td class="text-right">' . number_format($client['inflow'], 2) . '</td>
                <td class="text-right">' . number_format($client['outflow'], 2) . '</td>
                <td class="text-right">' . number_format($client['net'], 2) . '</td>
                <td class="text-right">' . number_format($running_balance, 2) . '</td>
            </tr>';
        $counter++;
    }
    
    $html .= '
            <tr class="total-row">
                <td colspan="2" class="text-left"><strong>TOTAL</strong></td>
                <td class="text-right"><strong>' . number_format($total_inflow, 2) . '</strong></td>
                <td class="text-right"><strong>' . number_format($total_outflow, 2) . '</strong></td>
                <td class="text-right"><strong>' . number_format($total_inflow - $total_outflow, 2) . '</strong></td>
                <td class="text-right"><strong>' . number_format($running_balance, 2) . '</strong></td>
            </tr>
        </tbody>
    </table>
    
    <div class="disclaimer">
        ' . $company_name . ' has prepared this Report solely for informational purposes. ' . $company_name . ' does not represent warrant or guarantee that the
        Reports are accurate. ' . $company_name . ' disclaims liability for any direct indirect punitive special consequential or incidental damages related to the Reports or the use
        of the Reports. This disclaimer applies to the Reports in their entirety irrespective of whether the Reports are used or viewed in whole or in part.
    </div>';
    
    return $html;
}
?>