<?php
require_once '../config/config.php';
require_once '../auth/auth_middleware.php';
require_once '../tcpdf/tcpdf.php';

require_login();
$db = getDBConnection();

// Get company details - UPDATED to match your table structure
$company_stmt = $db->query("SELECT company_name, company_code, phone, address, email FROM companies WHERE status = 'active' LIMIT 1");
$company = $company_stmt->fetch();
$company_name = $company ? $company['company_name'] : 'Victory Financial Services Limited';
$company_code = $company ? $company['company_code'] : 'B13/C';

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

// Handle bond-specific reports first
if ($report_name === 'bonds_edit_list' || $report_name === 'bonds_summary' || 
    $report_name === 'bonds_statutory_deductions' || $report_name === 'bonds_cmsa_levy' ||
    $report_name === 'bonds_dse_levy' || $report_name === 'bonds_csd_levy' || 
    $report_name === 'bonds_vat_levy') {
    handleBondReports($db, $company_name, $company_code, $_POST);
    exit;
}

// Load master data for other reports
$master_data = loadMasterData($db);

// Build WHERE conditions for other reports
$where_conditions = ["t.status = 'active'"];
$params = [];

if (!empty($client)) {
    $where_conditions[] = "t.client_cds_account = ?";
    $params[] = $client;
}
if (!empty($broker)) {
    $where_conditions[] = "t.broker_name = ?";
    $params[] = $broker;
}
if (!empty($security)) {
    $where_conditions[] = "t.security_id = ?";
    $params[] = $security;
}
if (!empty($type)) {
    $where_conditions[] = "t.trade_side = ?";
    $params[] = strtoupper($type);
}
if (!empty($status)) {
    $where_conditions[] = "t.status = ?";
    $params[] = $status;
}
if (!empty($asset_class)) {
    $where_conditions[] = "t.asset_class = ?";
    $params[] = $asset_class;
}
if (!empty($period_from)) {
    $where_conditions[] = "t.trade_date >= ?";
    $params[] = $period_from;
}
if (!empty($period_to)) {
    $where_conditions[] = "t.trade_date <= ?";
    $params[] = $period_to;
}

$where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

// Get trades data for other reports
$sql = "SELECT t.*, 
               bt.description as bond_type_desc, bi.description as issuer_desc, 
               bes.description as economic_sector_desc, pf.description as payment_freq_desc,
               st.description as share_type_desc, smt.description as market_trend_desc,
               tt.description as title_desc, it.description as identity_type_desc
        FROM trades t
        LEFT JOIN bonds b ON t.security_id = b.security_id AND t.asset_class = 'bond'
        LEFT JOIN bond_types bt ON b.security_type = bt.code
        LEFT JOIN bond_issuers bi ON b.issuer = bi.code
        LEFT JOIN bonds_economic_sectors bes ON b.economic_sector = bes.code
        LEFT JOIN payment_frequencies pf ON b.payment_frequency = pf.code
        LEFT JOIN equities e ON t.security_id = e.security_id AND t.asset_class = 'equity'
        LEFT JOIN share_types st ON e.share_type = st.code
        LEFT JOIN share_market_trends smt ON e.market_trend = smt.code
        LEFT JOIN titles tt ON t.client_title = tt.code
        LEFT JOIN identity_types it ON t.client_identity_type = it.code
        $where_clause 
        ORDER BY trade_date DESC, created_at DESC";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$trades = $stmt->fetchAll();

// Handle export requests for other reports
if ($export_type === 'pdf') {
    generatePDFReport($trades, $report_type, $master_data);
    exit;
} elseif ($export_type === 'excel') {
    generateExcelReport($trades, $report_type, $master_data);
    exit;
} elseif ($export_type === 'csv') {
    generateCSVReport($trades, $report_type, $master_data);
    exit;
}

// Generate reports based on report name
switch ($report_name) {
    case 'contract_notes':
        generateContractNotes($trades, $report_type, $watermark, $master_data, $contract_grouping);
        break;
    case 'commission_summary':
        generateCommissionSummary($trades, $report_type, $report_by, $master_data);
        break;
    case 'transaction_summary':
        generateTransactionSummaryReports($trades, $report_type, $report_by, $master_data);
        break;
    case 'broker_summary':
        generateBrokerSummary($trades, $report_type, $report_by, $master_data);
        break;
    case 'general_ledger':
        generateLedgerEntries($trades, $report_type, $report_by, $master_data);
        break;
    case 'order_forms':
        generateOrderForms($trades, $report_type, $master_data);
        break;
    case 'asset_class_summary':
        generateAssetClassSummary($trades, $report_type, $report_by, $master_data);
        break;
    case 'portfolio_analysis':
        generatePortfolioAnalysis($trades, $report_type, $master_data);
        break;
    default:
        echo '<div class="alert alert-warning text-center py-5">
                <i class="bi bi-exclamation-triangle fs-1 text-muted mb-3"></i>
                <h5>No Report Selected</h5>
                <p class="text-muted">Please select a report type to generate.</p>
              </div>';
        break;
}

// ==================== BOND REPORTS HANDLER ====================
function handleBondReports($db, $company_name, $company_code, $filters) {
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

    switch ($filters['report_name']) {
        case 'bonds_edit_list':
            generateBondsEditListReport($db, $where_clause, $params, $company_name, $company_code, $filters);
            break;
        case 'bonds_summary':
            generateBondsSummaryReport($db, $where_clause, $params, $company_name, $company_code, $filters);
            break;
        case 'bonds_statutory_deductions':
            generateBondsStatutoryDeductionsReport($db, $where_clause, $params, $company_name, $company_code, $filters);
            break;
        case 'bonds_cmsa_levy':
            generateBondsCMSALevyReport($db, $where_clause, $params, $company_name, $company_code, $filters);
            break;
        case 'bonds_dse_levy':
            generateBondsDSELevyReport($db, $where_clause, $params, $company_name, $company_code, $filters);
            break;
        case 'bonds_csd_levy':
            generateBondsCSDLevyReport($db, $where_clause, $params, $company_name, $company_code, $filters);
            break;
        case 'bonds_vat_levy':
            generateBondsVATLevyReport($db, $where_clause, $params, $company_name, $company_code, $filters);
            break;
    }
}

function generateBondsEditListReport($db, $where_clause, $params, $company_name, $company_code, $filters) {
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
            t.created_at,
            b.coupon_rate,
            b.maturity_date
        FROM trades t
        LEFT JOIN bonds b ON t.security_id = b.security_id
        WHERE $where_clause
        ORDER BY t.trade_date, t.created_at
    ");
    $stmt->execute($params);
    $transactions = $stmt->fetchAll();

    if (empty($transactions)) {
        echo '<div class="alert alert-info text-center py-5">
                <i class="bi bi-info-circle fs-1 text-muted mb-3"></i>
                <h5>No Bond Transactions Found</h5>
                <p class="text-muted">No bond trades match your selected criteria. Please adjust your filters and try again.</p>
              </div>';
        return;
    }

    // Check if we're exporting or displaying
    if (isset($_POST['export_type']) && $_POST['export_type'] === 'pdf') {
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

        $html = generateBondsEditListHTML($transactions, $company_name, $company_code, $filters);
        $pdf->writeHTML($html, true, false, true, false, '');
        $pdf->Output('bonds_edit_list_' . date('Y_m_d') . '.pdf', 'I');
    } else {
        // Display HTML
        echo generateBondsEditListHTML($transactions, $company_name, $company_code, $filters);
    }
}

function generateBondsSummaryReport($db, $where_clause, $params, $company_name, $company_code, $filters) {
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
        echo '<div class="alert alert-info text-center py-5">
                <i class="bi bi-info-circle fs-1 text-muted mb-3"></i>
                <h5>No Bond Transactions Found</h5>
                <p class="text-muted">No bond trades match your selected criteria. Please adjust your filters and try again.</p>
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
        
        if ($transaction['trade_side'] === 'SELL') {
            $client_balances[$client_key]['inflow'] += $transaction['total_consideration'];
        } else {
            $client_balances[$client_key]['outflow'] += $transaction['total_consideration'];
        }
        
        $client_balances[$client_key]['net'] = 
            $client_balances[$client_key]['inflow'] - $client_balances[$client_key]['outflow'];
    }

    // Check if we're exporting or displaying
    if (isset($_POST['export_type']) && $_POST['export_type'] === 'pdf') {
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

        $html = generateBondsSummaryHTML($client_balances, $company_name, $company_code, $filters);
        $pdf->writeHTML($html, true, false, true, false, '');
        $pdf->Output('bonds_summary_' . date('Y_m_d') . '.pdf', 'I');
    } else {
        // Display HTML
        echo generateBondsSummaryHTML($client_balances, $company_name, $company_code, $filters);
    }
}

function generateBondsStatutoryDeductionsReport($db, $where_clause, $params, $company_name, $company_code, $filters) {
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
        echo '<div class="alert alert-info text-center py-5">
                <i class="bi bi-info-circle fs-1 text-muted mb-3"></i>
                <h5>No Bond Transactions Found</h5>
                <p class="text-muted">No bond trades match your selected criteria. Please adjust your filters and try again.</p>
              </div>';
        return;
    }

    // Calculate totals - PURCHASES from BUY, SALES from SELL
    $total_purchases = 0;
    $total_sales = 0;
    $total_turnover = 0;
    $total_dse_fees = 0;
    $total_cmsa_fees = 0;
    $total_csd_fees = 0;
    $total_vat_fees = 0;
    $total_fees = 0;

    foreach ($transactions as $transaction) {
        $fees = calculateBondStatutoryFees($transaction['consideration']);
        $total_turnover += $transaction['consideration'];
        
        if ($transaction['trade_side'] === 'BUY') {
            $total_purchases += $transaction['consideration'];
        } else {
            $total_sales += $transaction['consideration'];
        }
        
        $total_dse_fees += $fees['dse_fee'];
        $total_cmsa_fees += $fees['cmsa_fee'];
        $total_csd_fees += $fees['csd_fee'];
        $total_vat_fees += $fees['vat_fee'];
        $total_fees += $fees['total_fees'];
    }

    // Check if we're exporting or displaying
    if (isset($_POST['export_type']) && $_POST['export_type'] === 'pdf') {
        // Create PDF
        $pdf = new TCPDF('L', PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
        $pdf->SetCreator($company_name);
        $pdf->SetAuthor($company_name);
        $pdf->SetTitle('Bonds Statutory Deductions Detailed Report');
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(10, 10, 10);
        $pdf->SetAutoPageBreak(TRUE, 15);
        $pdf->AddPage();

        $html = generateBondsStatutoryDeductionsHTML($transactions, $company_name, $company_code, $filters, [
            'total_purchases' => $total_purchases,
            'total_sales' => $total_sales,
            'total_turnover' => $total_turnover,
            'total_dse_fees' => $total_dse_fees,
            'total_cmsa_fees' => $total_cmsa_fees,
            'total_csd_fees' => $total_csd_fees,
            'total_vat_fees' => $total_vat_fees,
            'total_fees' => $total_fees
        ]);
        $pdf->writeHTML($html, true, false, true, false, '');
        $pdf->Output('bonds_statutory_deductions_' . date('Y_m_d') . '.pdf', 'I');
    } else {
        // Display HTML
        echo generateBondsStatutoryDeductionsHTML($transactions, $company_name, $company_code, $filters, [
            'total_purchases' => $total_purchases,
            'total_sales' => $total_sales,
            'total_turnover' => $total_turnover,
            'total_dse_fees' => $total_dse_fees,
            'total_cmsa_fees' => $total_cmsa_fees,
            'total_csd_fees' => $total_csd_fees,
            'total_vat_fees' => $total_vat_fees,
            'total_fees' => $total_fees
        ]);
    }
}

function generateBondsCMSALevyReport($db, $where_clause, $params, $company_name, $company_code, $filters) {
    // Calculate totals for CMSA levy - PURCHASES from BUY, SALES from SELL
    $stmt = $db->prepare("
        SELECT 
            SUM(CASE WHEN trade_side = 'BUY' THEN consideration ELSE 0 END) as total_purchases,
            SUM(CASE WHEN trade_side = 'SELL' THEN consideration ELSE 0 END) as total_sales,
            SUM(consideration) as total_turnover
        FROM trades 
        WHERE $where_clause
    ");
    $stmt->execute($params);
    $totals = $stmt->fetch();

    if (!$totals || $totals['total_turnover'] == 0) {
        echo '<div class="alert alert-info text-center py-5">
                <i class="bi bi-info-circle fs-1 text-muted mb-3"></i>
                <h5>No Bond Transactions Found</h5>
                <p class="text-muted">No bond trades match your selected criteria. Please adjust your filters and try again.</p>
              </div>';
        return;
    }

    $cmsa_levy = $totals['total_turnover'] * 0.0001; // 0.01%

    // Check if we're exporting or displaying
    if (isset($_POST['export_type']) && $_POST['export_type'] === 'pdf') {
        // Create PDF
        $pdf = new TCPDF('P', PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
        $pdf->SetCreator($company_name);
        $pdf->SetAuthor($company_name);
        $pdf->SetTitle('CMSA Transaction Levy on Bonds');
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(10, 10, 10);
        $pdf->SetAutoPageBreak(TRUE, 15);
        $pdf->AddPage();

        $html = generateBondsCMSALevyHTML($totals, $cmsa_levy, $company_name, $company_code, $filters);
        $pdf->writeHTML($html, true, false, true, false, '');
        $pdf->Output('bonds_cmsa_levy_' . date('Y_m_d') . '.pdf', 'I');
    } else {
        // Display HTML
        echo generateBondsCMSALevyHTML($totals, $cmsa_levy, $company_name, $company_code, $filters);
    }
}

function generateBondsDSELevyReport($db, $where_clause, $params, $company_name, $company_code, $filters) {
    // Calculate totals for DSE levy - PURCHASES from BUY, SALES from SELL
    $stmt = $db->prepare("
        SELECT 
            SUM(CASE WHEN trade_side = 'BUY' THEN consideration ELSE 0 END) as total_purchases,
            SUM(CASE WHEN trade_side = 'SELL' THEN consideration ELSE 0 END) as total_sales,
            SUM(consideration) as total_turnover
        FROM trades 
        WHERE $where_clause
    ");
    $stmt->execute($params);
    $totals = $stmt->fetch();

    if (!$totals || $totals['total_turnover'] == 0) {
        echo '<div class="alert alert-info text-center py-5">
                <i class="bi bi-info-circle fs-1 text-muted mb-3"></i>
                <h5>No Bond Transactions Found</h5>
                <p class="text-muted">No bond trades match your selected criteria. Please adjust your filters and try again.</p>
              </div>';
        return;
    }

    $dse_levy = $totals['total_turnover'] * 0.0002006; // 0.02006%

    // Check if we're exporting or displaying
    if (isset($_POST['export_type']) && $_POST['export_type'] === 'pdf') {
        // Create PDF
        $pdf = new TCPDF('P', PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
        $pdf->SetCreator($company_name);
        $pdf->SetAuthor($company_name);
        $pdf->SetTitle('DSE Transaction Levy on Bonds');
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(10, 10, 10);
        $pdf->SetAutoPageBreak(TRUE, 15);
        $pdf->AddPage();

        $html = generateBondsDSELevyHTML($totals, $dse_levy, $company_name, $company_code, $filters);
        $pdf->writeHTML($html, true, false, true, false, '');
        $pdf->Output('bonds_dse_levy_' . date('Y_m_d') . '.pdf', 'I');
    } else {
        // Display HTML
        echo generateBondsDSELevyHTML($totals, $dse_levy, $company_name, $company_code, $filters);
    }
}

function generateBondsCSDLevyReport($db, $where_clause, $params, $company_name, $company_code, $filters) {
    // Calculate totals for CSD levy - PURCHASES from BUY, SALES from SELL
    $stmt = $db->prepare("
        SELECT 
            SUM(CASE WHEN trade_side = 'BUY' THEN consideration ELSE 0 END) as total_purchases,
            SUM(CASE WHEN trade_side = 'SELL' THEN consideration ELSE 0 END) as total_sales,
            SUM(consideration) as total_turnover
        FROM trades 
        WHERE $where_clause
    ");
    $stmt->execute($params);
    $totals = $stmt->fetch();

    if (!$totals || $totals['total_turnover'] == 0) {
        echo '<div class="alert alert-info text-center py-5">
                <i class="bi bi-info-circle fs-1 text-muted mb-3"></i>
                <h5>No Bond Transactions Found</h5>
                <p class="text-muted">No bond trades match your selected criteria. Please adjust your filters and try again.</p>
              </div>';
        return;
    }

    $csd_levy = $totals['total_turnover'] * 0.000118; // 0.0118%

    // Check if we're exporting or displaying
    if (isset($_POST['export_type']) && $_POST['export_type'] === 'pdf') {
        // Create PDF
        $pdf = new TCPDF('P', PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
        $pdf->SetCreator($company_name);
        $pdf->SetAuthor($company_name);
        $pdf->SetTitle('CSD Transaction Levy on Bonds');
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(10, 10, 10);
        $pdf->SetAutoPageBreak(TRUE, 15);
        $pdf->AddPage();

        $html = generateBondsCSDLevyHTML($totals, $csd_levy, $company_name, $company_code, $filters);
        $pdf->writeHTML($html, true, false, true, false, '');
        $pdf->Output('bonds_csd_levy_' . date('Y_m_d') . '.pdf', 'I');
    } else {
        // Display HTML
        echo generateBondsCSDLevyHTML($totals, $csd_levy, $company_name, $company_code, $filters);
    }
}

function generateBondsVATLevyReport($db, $where_clause, $params, $company_name, $company_code, $filters) {
    // Calculate totals for VAT levy - PURCHASES from BUY, SALES from SELL
    $stmt = $db->prepare("
        SELECT 
            SUM(CASE WHEN trade_side = 'BUY' THEN consideration ELSE 0 END) as total_purchases,
            SUM(CASE WHEN trade_side = 'SELL' THEN consideration ELSE 0 END) as total_sales,
            SUM(consideration) as total_turnover
        FROM trades 
        WHERE $where_clause
    ");
    $stmt->execute($params);
    $totals = $stmt->fetch();

    if (!$totals || $totals['total_turnover'] == 0) {
        echo '<div class="alert alert-info text-center py-5">
                <i class="bi bi-info-circle fs-1 text-muted mb-3"></i>
                <h5>No Bond Transactions Found</h5>
                <p class="text-muted">No bond trades match your selected criteria. Please adjust your filters and try again.</p>
              </div>';
        return;
    }

    $vat_base = $totals['total_turnover'] * 0.00063; // 0.063% brokerage commission
    $vat_levy = $vat_base * 0.18; // 18% VAT on brokerage commission

    // Check if we're exporting or displaying
    if (isset($_POST['export_type']) && $_POST['export_type'] === 'pdf') {
        // Create PDF
        $pdf = new TCPDF('P', PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
        $pdf->SetCreator($company_name);
        $pdf->SetAuthor($company_name);
        $pdf->SetTitle('VAT Transaction Levy on Bonds');
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(10, 10, 10);
        $pdf->SetAutoPageBreak(TRUE, 15);
        $pdf->AddPage();

        $html = generateBondsVATLevyHTML($totals, $vat_levy, $company_name, $company_code, $filters);
        $pdf->writeHTML($html, true, false, true, false, '');
        $pdf->Output('bonds_vat_levy_' . date('Y_m_d') . '.pdf', 'I');
    } else {
        // Display HTML
        echo generateBondsVATLevyHTML($totals, $vat_levy, $company_name, $company_code, $filters);
    }
}

function generateBondsEditListHTML($transactions, $company_name, $company_code, $filters) {
    $period_from = date('d/m/Y', strtotime($filters['period_from'] ?? date('Y-01-01')));
    $period_to = date('d/m/Y', strtotime($filters['period_to'] ?? date('Y-m-d')));
    $current_date = date('d/m/Y');
    $current_time = date('H:i:s');
    
    // Calculate daily totals and process transactions
    $daily_totals = [];
    $processed_transactions = [];
    
    foreach ($transactions as $transaction) {
        $fees = calculateBondFees($transaction['consideration']);
        $net_amount = $transaction['trade_side'] === 'BUY' ? 
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
        .page-break { page-break-after: always; }
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
                <th width="20%">CLIENT</th>
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
    
    foreach ($processed_transactions as $item) {
        $transaction = $item['data'];
        $fees = $item['fees'];
        
        // Show date group header
        if ($current_date_group !== $transaction['trade_date']) {
            $current_date_group = $transaction['trade_date'];
            $html .= '
            <tr class="section-header">
                <td colspan="19" class="text-left">' . date('Y-m-d', strtotime($current_date_group)) . '</td>
            </tr>';
        }
        
        $html .= '
            <tr>
                <td>' . $counter . '</td>
                <td>' . date('d/m/Y', strtotime($transaction['trade_date'])) . '</td>
                <td>' . htmlspecialchars($transaction['trade_reference']) . '</td>
                <td>' . strtoupper($transaction['trade_side']) . ':' . substr($transaction['trade_reference'], -6) . '</td>
                <td class="text-left">' . htmlspecialchars($transaction['client_name']) . '</td>
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
        $next_item = $processed_transactions[$counter] ?? null;
        if (!$next_item || $next_item['data']['trade_date'] !== $current_date_group) {
            $date_totals = $daily_totals[$current_date_group] ?? [
                'purchases_qty' => 0, 'purchases_consideration' => 0, 'purchases_commission' => 0,
                'sales_qty' => 0, 'sales_consideration' => 0, 'sales_commission' => 0
            ];
            
            $html .= '
            <tr class="total-row">
                <td colspan="6" class="text-left">PURCHASES</td>
                <td class="text-right">' . number_format($date_totals['purchases_qty']) . '</td>
                <td colspan="2"></td>
                <td class="text-right">' . number_format($date_totals['purchases_consideration'], 2) . '</td>
                <td class="text-right">' . number_format($date_totals['purchases_commission'], 2) . '</td>
                <td colspan="7"></td>
            </tr>
            <tr class="total-row">
                <td colspan="6" class="text-left">SALES</td>
                <td class="text-right">' . number_format($date_totals['sales_qty']) . '</td>
                <td colspan="2"></td>
                <td class="text-right">' . number_format($date_totals['sales_consideration'], 2) . '</td>
                <td class="text-right">' . number_format($date_totals['sales_commission'], 2) . '</td>
                <td colspan="7"></td>
            </tr>
            <tr class="total-row">
                <td colspan="6" class="text-left">TURNOVER</td>
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

function generateBondsSummaryHTML($client_balances, $company_name, $company_code, $filters) {
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
                <th width="30%">Name</th>
                <th width="15%">Inflow (SALES)</th>
                <th width="15%">Outflow (PURCHASES)</th>
                <th width="15%">Net</th>
                <th width="20%">Balance</th>
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

function generateBondsStatutoryDeductionsHTML($transactions, $company_name, $company_code, $filters, $totals) {
    $period_from = date('d/m/Y', strtotime($filters['period_from'] ?? date('Y-01-01')));
    $period_to = date('d/m/Y', strtotime($filters['period_to'] ?? date('Y-m-d')));
    $current_date = date('d/m/Y');
    $current_time = date('H:i:s');
    
    $html = '
    <style>
        .header { text-align: center; margin-bottom: 10px; border-bottom: 1px solid #000; padding-bottom: 5px; }
        .company-title { font-size: 16px; font-weight: bold; margin: 5px 0; }
        .company-subtitle { font-size: 10px; margin: 2px 0; }
        .division { font-size: 11px; margin: 10px 0; font-weight: bold; }
        .report-title { font-size: 14px; font-weight: bold; margin: 5px 0; }
        .report-subtitle { font-size: 10px; margin: 2px 0; }
        .disclaimer { font-size: 8px; margin-top: 20px; color: #666; }
        table { width: 100%; border-collapse: collapse; font-size: 8px; margin: 5px 0; }
        th, td { border: 1px solid #000; padding: 3px; text-align: center; }
        th { background-color: #f0f0f0; font-weight: bold; }
        .text-left { text-align: left; }
        .text-right { text-align: right; }
        .contact-info { font-size: 9px; margin: 5px 0; }
        .total-row { background-color: #f8f8f8; font-weight: bold; }
    </style>
    
    <div class="header">
        <div class="company-title">' . strtoupper($company_name) . '</div>
        <div class="company-subtitle">Stock Broker / Dealer & Investment Advisor</div>
        <div class="company-subtitle">Member of Dar es Salaam Stock Exchange</div>
        <div class="contact-info">
            ATC HOUSE, OHIO STREET / GARDEN AVENUE PO BOX 8706 DAR ES SALAAM<br>
            Tel: +255 22 2112091 Mob: +255 718 284 540 Email: info@vfsl.co.tz
        </div>
        <div class="division">Division : STOCK BROKING</div>
        <div class="report-title">BONDS STATUTORY DEDUCTIONS DETAILED REPORT</div>
        <div class="report-subtitle">FOR THE PERIOD [' . $period_from . ' - ' . $period_to . ']</div>
        <div class="report-subtitle">Date : ' . $current_date . ' : ' . $current_time . '</div>
    </div>
    
    <table>
        <thead>
            <tr>
                <th width="3%">#</th>
                <th width="8%">TRADE DATE</th>
                <th width="6%">BUY/SELL</th>
                <th width="15%">BUYING BROKER</th>
                <th width="15%">SELLING BROKER</th>
                <th width="10%">TRADE REF NO</th>
                <th width="12%">SECURITY</th>
                <th width="8%">QUANTITY</th>
                <th width="6%">PRICE</th>
                <th width="10%">CONSIDERATION</th>
                <th width="5%">DSE</th>
                <th width="5%">CMSA</th>
                <th width="5%">CSDI</th>
                <th width="5%">VAT</th>
                <th width="8%">TOTAL</th>
            </tr>
        </thead>
        <tbody>';
    
    $counter = 1;
    foreach ($transactions as $transaction) {
        $fees = calculateBondStatutoryFees($transaction['consideration']);
        
        // Determine buying and selling brokers based on trade side
        $buying_broker = $transaction['trade_side'] === 'BUY' ? htmlspecialchars($transaction['client_name']) : $company_code;
        $selling_broker = $transaction['trade_side'] === 'SELL' ? htmlspecialchars($transaction['client_name']) : $company_code;
        
        $html .= '
            <tr>
                <td>' . $counter . '</td>
                <td>' . date('d/m/Y', strtotime($transaction['trade_date'])) . '</td>
                <td>' . strtoupper($transaction['trade_side']) . '</td>
                <td class="text-left">' . $buying_broker . '</td>
                <td class="text-left">' . $selling_broker . '</td>
                <td>' . htmlspecialchars($transaction['trade_reference']) . '</td>
                <td class="text-left">' . htmlspecialchars($transaction['security_id']) . '</td>
                <td class="text-right">' . number_format($transaction['quantity']) . '</td>
                <td class="text-right">' . number_format($transaction['price'], 2) . '</td>
                <td class="text-right">' . number_format($transaction['consideration'], 2) . '</td>
                <td class="text-right">' . number_format($fees['dse_fee'], 2) . '</td>
                <td class="text-right">' . number_format($fees['cmsa_fee'], 2) . '</td>
                <td class="text-right">' . number_format($fees['csd_fee'], 2) . '</td>
                <td class="text-right">' . number_format($fees['vat_fee'], 2) . '</td>
                <td class="text-right">' . number_format($fees['total_fees'], 2) . '</td>
            </tr>';
        $counter++;
    }
    
    $html .= '
            <tr class="total-row">
                <td colspan="9" class="text-left"><strong>TOTAL</strong></td>
                <td class="text-right"><strong>' . number_format($totals['total_turnover'], 2) . '</strong></td>
                <td class="text-right"><strong>' . number_format($totals['total_dse_fees'], 2) . '</strong></td>
                <td class="text-right"><strong>' . number_format($totals['total_cmsa_fees'], 2) . '</strong></td>
                <td class="text-right"><strong>' . number_format($totals['total_csd_fees'], 2) . '</strong></td>
                <td class="text-right"><strong>' . number_format($totals['total_vat_fees'], 2) . '</strong></td>
                <td class="text-right"><strong>' . number_format($totals['total_fees'], 2) . '</strong></td>
            </tr>
            <tr class="total-row">
                <td colspan="9" class="text-left">PURCHASES (BUY)</td>
                <td class="text-right"><strong>' . number_format($totals['total_purchases'], 2) . '</strong></td>
                <td colspan="6"></td>
            </tr>
            <tr class="total-row">
                <td colspan="9" class="text-left">SALES (SELL)</td>
                <td class="text-right"><strong>' . number_format($totals['total_sales'], 2) . '</strong></td>
                <td colspan="6"></td>
            </tr>
            <tr class="total-row">
                <td colspan="9" class="text-left">TURNOVER</td>
                <td class="text-right"><strong>' . number_format($totals['total_turnover'], 2) . '</strong></td>
                <td colspan="6"></td>
            </tr>
            <tr class="total-row">
                <td colspan="9" class="text-left">CSD FEES</td>
                <td class="text-right"><strong>' . number_format($totals['total_csd_fees'], 2) . '</strong></td>
                <td colspan="6"></td>
            </tr>
            <tr class="total-row">
                <td colspan="9" class="text-left">CMSA FEES</td>
                <td class="text-right"><strong>' . number_format($totals['total_cmsa_fees'], 2) . '</strong></td>
                <td colspan="6"></td>
            </tr>
            <tr class="total-row">
                <td colspan="9" class="text-left">DSE FEES</td>
                <td class="text-right"><strong>' . number_format($totals['total_dse_fees'], 2) . '</strong></td>
                <td colspan="6"></td>
            </tr>
            <tr class="total-row">
                <td colspan="9" class="text-left">VAT FEES</td>
                <td class="text-right"><strong>' . number_format($totals['total_vat_fees'], 2) . '</strong></td>
                <td colspan="6"></td>
            </tr>
            <tr class="total-row">
                <td colspan="9" class="text-left">TOTAL FEES</td>
                <td class="text-right"><strong>' . number_format($totals['total_fees'], 2) . '</strong></td>
                <td colspan="6"></td>
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

function generateBondsCMSALevyHTML($totals, $cmsa_levy, $company_name, $company_code, $filters) {
    $current_date = date('d/m/Y');
    $current_time = date('H:i:s');
    $period_from = date('d/m/Y', strtotime($filters['period_from'] ?? date('Y-01-01')));
    $period_to = date('d/m/Y', strtotime($filters['period_to'] ?? date('Y-m-d')));
    
    $html = '
    <style>
        .header { text-align: center; margin-bottom: 10px; border-bottom: 1px solid #000; padding-bottom: 5px; }
        .company-title { font-size: 16px; font-weight: bold; margin: 5px 0; }
        .company-subtitle { font-size: 10px; margin: 2px 0; }
        .division { font-size: 11px; margin: 10px 0; font-weight: bold; }
        .report-title { font-size: 14px; font-weight: bold; margin: 5px 0; }
        .disclaimer { font-size: 8px; margin-top: 20px; color: #666; }
        table { width: 100%; border-collapse: collapse; font-size: 10px; margin: 10px 0; }
        th, td { border: 1px solid #000; padding: 5px; }
        th { background-color: #f0f0f0; font-weight: bold; }
        .text-left { text-align: left; }
        .text-right { text-align: right; }
        .contact-info { font-size: 9px; margin: 5px 0; }
        .letter-header { margin: 15px 0; }
    </style>
    
    <div class="header">
        <div class="company-title">' . strtoupper($company_name) . '</div>
        <div class="company-subtitle">Stock Broker / Dealer & Investment Advisor</div>
        <div class="company-subtitle">Member of Dar es Salaam Stock Exchange</div>
        <div class="contact-info">
            ATC HOUSE, OHIO STREET / GARDEN AVENUE PO BOX 8706 DAR ES SALAAM<br>
            Tel: +255 22 2112091 Mob: +255 788 284 540 Email: info@vfsl.co.tz
        </div>
    </div>
    
    <div class="letter-header">
        <table>
            <tr>
                <td width="20%"><strong>TO</strong></td>
                <td><strong>CAPITAL MARKETS AND SECURITIES AUTHORITY</strong></td>
                <td width="30%" class="text-right"><strong>Time: ' . $current_time . '</strong></td>
            </tr>
            <tr>
                <td><strong>FROM</strong></td>
                <td><strong>' . strtoupper($company_name) . ' - ' . $company_code . '</strong></td>
                <td class="text-right"><strong>Date: ' . $current_date . '</strong></td>
            </tr>
        </table>
    </div>
    
    <div class="report-title" style="text-align: center; margin: 15px 0;">CMSA TRANSACTION LEVY ON BONDS</div>
    
    <p>Dear Sirs,<br>
    We forward herewith our cheque No ...... for Tshs ' . number_format($cmsa_levy, 2) . ' being full and final settlement of the transaction levies on bonds trades for the period ' . $period_from . ' - ' . $period_to . '</p>
    
    <table>
        <thead>
            <tr>
                <th width="70%">PARTICULARS</th>
                <th width="30%">AMOUNT</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td class="text-left">PURCHASES (BUY)</td>
                <td class="text-right">' . number_format($totals['total_purchases'], 2) . '</td>
            </tr>
            <tr>
                <td class="text-left">SALES (SELL)</td>
                <td class="text-right">' . number_format($totals['total_sales'], 2) . '</td>
            </tr>
            <tr>
                <td class="text-left">TOTAL</td>
                <td class="text-right">' . number_format($totals['total_turnover'], 2) . '</td>
            </tr>
            <tr>
                <td class="text-left">CMSA Transaction Levy @0.010000%</td>
                <td class="text-right">' . number_format($cmsa_levy, 2) . '</td>
            </tr>
            <tr>
                <td class="text-left"><strong>FEES PAYABLE</strong></td>
                <td class="text-right"><strong>' . number_format($cmsa_levy, 2) . '</strong></td>
            </tr>
        </tbody>
    </table>
    
    <div style="margin-top: 30px;">
        <p>Received By ______ CMSA Rubber Stamp</p>
    </div>
    
    <div style="margin-top: 20px; font-size: 9px;">
        <strong>1st Copy :</strong> CAPITAL MARKETS AND SECURITIES AUTHORITY<br>
        <strong>2nd Copy :</strong> ' . strtoupper($company_name) . '
    </div>
    
    <div class="disclaimer" style="margin-top: 40px;">
        ' . $company_name . ' has prepared this Report solely for informational purposes. ' . $company_name . ' does not represent warrant or guarantee that the
        Reports are accurate. ' . $company_name . ' disclaims liability for any direct indirect punitive special consequential or incidental damages related to the Reports or the use
        of the Reports. This disclaimer applies to the Reports in their entirety irrespective of whether the Reports are used or viewed in whole or in part.
    </div>';
    
    return $html;
}

function generateBondsDSELevyHTML($totals, $dse_levy, $company_name, $company_code, $filters) {
    $current_date = date('d/m/Y');
    $current_time = date('H:i:s');
    $period_from = date('d/m/Y', strtotime($filters['period_from'] ?? date('Y-01-01')));
    $period_to = date('d/m/Y', strtotime($filters['period_to'] ?? date('Y-m-d')));
    
    $html = '
    <style>
        .header { text-align: center; margin-bottom: 10px; border-bottom: 1px solid #000; padding-bottom: 5px; }
        .company-title { font-size: 16px; font-weight: bold; margin: 5px 0; }
        .company-subtitle { font-size: 10px; margin: 2px 0; }
        .division { font-size: 11px; margin: 10px 0; font-weight: bold; }
        .report-title { font-size: 14px; font-weight: bold; margin: 5px 0; }
        .disclaimer { font-size: 8px; margin-top: 20px; color: #666; }
        table { width: 100%; border-collapse: collapse; font-size: 10px; margin: 10px 0; }
        th, td { border: 1px solid #000; padding: 5px; }
        th { background-color: #f0f0f0; font-weight: bold; }
        .text-left { text-align: left; }
        .text-right { text-align: right; }
        .contact-info { font-size: 9px; margin: 5px 0; }
        .letter-header { margin: 15px 0; }
    </style>
    
    <div class="header">
        <div class="company-title">' . strtoupper($company_name) . '</div>
        <div class="company-subtitle">Stock Broker / Dealer & Investment Advisor</div>
        <div class="company-subtitle">Member of Dar es Salaam Stock Exchange</div>
        <div class="contact-info">
            ATC HOUSE, OHIO STREET / GARDEN AVENUE PO BOX 8706 DAR ES SALAAM<br>
            Tel: +255 22 2112091 Mob: +255 788 284 540 Email: info@vfsl.co.tz
        </div>
    </div>
    
    <div class="letter-header">
        <table>
            <tr>
                <td width="20%"><strong>TO</strong></td>
                <td><strong>DAR ES SALAAM STOCK EXCHANGE</strong></td>
                <td width="30%" class="text-right"><strong>Time: ' . $current_time . '</strong></td>
            </tr>
            <tr>
                <td><strong>FROM</strong></td>
                <td><strong>' . strtoupper($company_name) . ' - ' . $company_code . '</strong></td>
                <td class="text-right"><strong>Date: ' . $current_date . '</strong></td>
            </tr>
        </table>
    </div>
    
    <div class="report-title" style="text-align: center; margin: 15px 0;">DSE TRANSACTION LEVY ON BONDS</div>
    
    <p>Dear Sirs,<br>
    We forward herewith our cheque No ...... for Tshs ' . number_format($dse_levy, 2) . ' being full and final settlement of the transaction levies on bonds trades for the period ' . $period_from . ' - ' . $period_to . '</p>
    
    <table>
        <thead>
            <tr>
                <th width="70%">PARTICULARS</th>
                <th width="30%">AMOUNT</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td class="text-left">PURCHASES (BUY)</td>
                <td class="text-right">' . number_format($totals['total_purchases'], 2) . '</td>
            </tr>
            <tr>
                <td class="text-left">SALES (SELL)</td>
                <td class="text-right">' . number_format($totals['total_sales'], 2) . '</td>
            </tr>
            <tr>
                <td class="text-left">TOTAL</td>
                <td class="text-right">' . number_format($totals['total_turnover'], 2) . '</td>
            </tr>
            <tr>
                <td class="text-left">DSE Transaction Levy @0.020060%</td>
                <td class="text-right">' . number_format($dse_levy, 2) . '</td>
            </tr>
            <tr>
                <td class="text-left"><strong>FEES PAYABLE</strong></td>
                <td class="text-right"><strong>' . number_format($dse_levy, 2) . '</strong></td>
            </tr>
        </tbody>
    </table>
    
    <div style="margin-top: 30px;">
        <p>Received By ______ DSE Rubber Stamp</p>
    </div>
    
    <div style="margin-top: 20px; font-size: 9px;">
        <strong>1st Copy :</strong> DAR ES SALAAM STOCK EXCHANGE<br>
        <strong>2nd Copy :</strong> ' . strtoupper($company_name) . '
    </div>
    
    <div class="disclaimer" style="margin-top: 40px;">
        ' . $company_name . ' has prepared this Report solely for informational purposes. ' . $company_name . ' does not represent warrant or guarantee that the
        Reports are accurate. ' . $company_name . ' disclaims liability for any direct indirect punitive special consequential or incidental damages related to the Reports or the use
        of the Reports. This disclaimer applies to the Reports in their entirety irrespective of whether the Reports are used or viewed in whole or in part.
    </div>';
    
    return $html;
}

function generateBondsCSDLevyHTML($totals, $csd_levy, $company_name, $company_code, $filters) {
    $current_date = date('d/m/Y');
    $current_time = date('H:i:s');
    $period_from = date('d/m/Y', strtotime($filters['period_from'] ?? date('Y-01-01')));
    $period_to = date('d/m/Y', strtotime($filters['period_to'] ?? date('Y-m-d')));
    
    $html = '
    <style>
        .header { text-align: center; margin-bottom: 10px; border-bottom: 1px solid #000; padding-bottom: 5px; }
        .company-title { font-size: 16px; font-weight: bold; margin: 5px 0; }
        .company-subtitle { font-size: 10px; margin: 2px 0; }
        .division { font-size: 11px; margin: 10px 0; font-weight: bold; }
        .report-title { font-size: 14px; font-weight: bold; margin: 5px 0; }
        .disclaimer { font-size: 8px; margin-top: 20px; color: #666; }
        table { width: 100%; border-collapse: collapse; font-size: 10px; margin: 10px 0; }
        th, td { border: 1px solid #000; padding: 5px; }
        th { background-color: #f0f0f0; font-weight: bold; }
        .text-left { text-align: left; }
        .text-right { text-align: right; }
        .contact-info { font-size: 9px; margin: 5px 0; }
        .letter-header { margin: 15px 0; }
    </style>
    
    <div class="header">
        <div class="company-title">' . strtoupper($company_name) . '</div>
        <div class="company-subtitle">Stock Broker / Dealer & Investment Advisor</div>
        <div class="company-subtitle">Member of Dar es Salaam Stock Exchange</div>
        <div class="contact-info">
            ATC HOUSE, OHIO STREET / GARDEN AVENUE PO BOX 8706 DAR ES SALAAM<br>
            Tel: +255 22 2112091 Mob: +255 788 284 540 Email: info@vfsl.co.tz
        </div>
    </div>
    
    <div class="letter-header">
        <table>
            <tr>
                <td width="20%"><strong>TO</strong></td>
                <td><strong>CSD & REGISTRY COMPANY LIMITED</strong></td>
                <td width="30%" class="text-right"><strong>Time: ' . $current_time . '</strong></td>
            </tr>
            <tr>
                <td><strong>FROM</strong></td>
                <td><strong>' . strtoupper($company_name) . ' - ' . $company_code . '</strong></td>
                <td class="text-right"><strong>Date: ' . $current_date . '</strong></td>
            </tr>
        </table>
    </div>
    
    <div class="report-title" style="text-align: center; margin: 15px 0;">CSD TRANSACTION LEVY ON BONDS</div>
    
    <p>Dear Sirs,<br>
    We forward herewith our cheque No ...... for Tshs ' . number_format($csd_levy, 2) . ' being full and final settlement of the transaction levies on bonds trades for the period ' . $period_from . ' - ' . $period_to . '</p>
    
    <table>
        <thead>
            <tr>
                <th width="70%">PARTICULARS</th>
                <th width="30%">AMOUNT</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td class="text-left">PURCHASES (BUY)</td>
                <td class="text-right">' . number_format($totals['total_purchases'], 2) . '</td>
            </tr>
            <tr>
                <td class="text-left">SALES (SELL)</td>
                <td class="text-right">' . number_format($totals['total_sales'], 2) . '</td>
            </tr>
            <tr>
                <td class="text-left">TOTAL</td>
                <td class="text-right">' . number_format($totals['total_turnover'], 2) . '</td>
            </tr>
            <tr>
                <td class="text-left">CSD Transaction Levy @0.011800%</td>
                <td class="text-right">' . number_format($csd_levy, 2) . '</td>
            </tr>
            <tr>
                <td class="text-left"><strong>FEES PAYABLE</strong></td>
                <td class="text-right"><strong>' . number_format($csd_levy, 2) . '</strong></td>
            </tr>
        </tbody>
    </table>
    
    <div style="margin-top: 30px;">
        <p>Received By ______ CSDR Rubber Stamp</p>
    </div>
    
    <div style="margin-top: 20px; font-size: 9px;">
        <strong>1st Copy :</strong> CSD & REGISTRY COMPANY LIMITED<br>
        <strong>2nd Copy :</strong> ' . strtoupper($company_name) . '
    </div>
    
    <div class="disclaimer" style="margin-top: 40px;">
        ' . $company_name . ' has prepared this Report solely for informational purposes. ' . $company_name . ' does not represent warrant or guarantee that the
        Reports are accurate. ' . $company_name . ' disclaims liability for any direct indirect punitive special consequential or incidental damages related to the Reports or the use
        of the Reports. This disclaimer applies to the Reports in their entirety irrespective of whether the Reports are used or viewed in whole or in part.
    </div>';
    
    return $html;
}

function generateBondsVATLevyHTML($totals, $vat_levy, $company_name, $company_code, $filters) {
    $current_date = date('d/m/Y');
    $current_time = date('H:i:s');
    $period_from = date('d/m/Y', strtotime($filters['period_from'] ?? date('Y-01-01')));
    $period_to = date('d/m/Y', strtotime($filters['period_to'] ?? date('Y-m-d')));
    
    $html = '
    <style>
        .header { text-align: center; margin-bottom: 10px; border-bottom: 1px solid #000; padding-bottom: 5px; }
        .company-title { font-size: 16px; font-weight: bold; margin: 5px 0; }
        .company-subtitle { font-size: 10px; margin: 2px 0; }
        .division { font-size: 11px; margin: 10px 0; font-weight: bold; }
        .report-title { font-size: 14px; font-weight: bold; margin: 5px 0; }
        .disclaimer { font-size: 8px; margin-top: 20px; color: #666; }
        table { width: 100%; border-collapse: collapse; font-size: 10px; margin: 10px 0; }
        th, td { border: 1px solid #000; padding: 5px; }
        th { background-color: #f0f0f0; font-weight: bold; }
        .text-left { text-align: left; }
        .text-right { text-align: right; }
        .contact-info { font-size: 9px; margin: 5px 0; }
        .letter-header { margin: 15px 0; }
    </style>
    
    <div class="header">
        <div class="company-title">' . strtoupper($company_name) . '</div>
        <div class="company-subtitle">Stock Broker / Dealer & Investment Advisor</div>
        <div class="company-subtitle">Member of Dar es Salaam Stock Exchange</div>
        <div class="contact-info">
            ATC HOUSE, OHIO STREET / GARDEN AVENUE PO BOX 8706 DAR ES SALAAM<br>
            Tel: +255 22 2112091 Mob: +255 788 284 540 Email: info@vfsl.co.tz
        </div>
    </div>
    
    <div class="letter-header">
        <table>
            <tr>
                <td width="20%"><strong>TO</strong></td>
                <td><strong>COMMISSIONER FOR DOMESTIC REVENUE</strong></td>
                <td width="30%" class="text-right"><strong>Time: ' . $current_time . '</strong></td>
            </tr>
            <tr>
                <td><strong>FROM</strong></td>
                <td><strong>' . strtoupper($company_name) . ' - ' . $company_code . '</strong></td>
                <td class="text-right"><strong>Date: ' . $current_date . '</strong></td>
            </tr>
        </table>
    </div>
    
    <div class="report-title" style="text-align: center; margin: 15px 0;">VAT TRANSACTION LEVY ON BONDS</div>
    
    <p>Dear Sirs,<br>
    We forward herewith our cheque No ...... for Tshs ' . number_format($vat_levy, 2) . ' being full and final settlement of the transaction levies on bonds trades for the period ' . $period_from . ' - ' . $period_to . '</p>
    
    <table>
        <thead>
            <tr>
                <th width="70%">PARTICULARS</th>
                <th width="30%">AMOUNT</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td class="text-left">PURCHASES (BUY)</td>
                <td class="text-right">' . number_format($totals['total_purchases'], 2) . '</td>
            </tr>
            <tr>
                <td class="text-left">SALES (SELL)</td>
                <td class="text-right">' . number_format($totals['total_sales'], 2) . '</td>
            </tr>
            <tr>
                <td class="text-left">TOTAL</td>
                <td class="text-right">' . number_format($totals['total_turnover'], 2) . '</td>
            </tr>
            <tr>
                <td class="text-left">VAT Transaction Levy @18.000000%</td>
                <td class="text-right">' . number_format($vat_levy, 2) . '</td>
            </tr>
            <tr>
                <td class="text-left"><strong>FEES PAYABLE</strong></td>
                <td class="text-right"><strong>' . number_format($vat_levy, 2) . '</strong></td>
            </tr>
        </tbody>
    </table>
    
    <div style="margin-top: 30px;">
        <p>Received By ______ CFDR Rubber Stamp</p>
    </div>
    
    <div style="margin-top: 20px; font-size: 9px;">
        <strong>1st Copy :</strong> COMMISSIONER FOR DOMESTIC REVENUE<br>
        <strong>2nd Copy :</strong> ' . strtoupper($company_name) . '
    </div>
    
    <div class="disclaimer" style="margin-top: 40px;">
        ' . $company_name . ' has prepared this Report solely for informational purposes. ' . $company_name . ' does not represent warrant or guarantee that the
        Reports are accurate. ' . $company_name . ' disclaims liability for any direct indirect punitive special consequential or incidental damages related to the Reports or the use
        of the Reports. This disclaimer applies to the Reports in their entirety irrespective of whether the Reports are used or viewed in whole or in part.
    </div>';
    
    return $html;
}

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

function calculateBondStatutoryFees($consideration) {
    $rates = [
        'dse' => 0.0002006,      // 0.02006%
        'cmsa' => 0.0001,        // 0.01%
        'csd' => 0.000118,       // 0.0118%
        'vat_base' => 0.00063,   // 0.063% brokerage commission
        'vat_rate' => 0.18       // 18% VAT on brokerage
    ];
    
    $dse_fee = $consideration * $rates['dse'];
    $cmsa_fee = $consideration * $rates['cmsa'];
    $csd_fee = $consideration * $rates['csd'];
    $vat_base = $consideration * $rates['vat_base'];
    $vat_fee = $vat_base * $rates['vat_rate'];
    
    return [
        'dse_fee' => $dse_fee,
        'cmsa_fee' => $cmsa_fee,
        'csd_fee' => $csd_fee,
        'vat_fee' => $vat_fee,
        'total_fees' => $dse_fee + $cmsa_fee + $csd_fee + $vat_fee
    ];
}

// ==================== EXISTING CONTRACT NOTE FUNCTIONS ====================
function generateBondContractNote($trade, $watermark, $master_data) {
    // Your existing bond contract note generation code
    echo '<div class="contract-note bond-contract">
            <h4>Bond Contract Note - ' . htmlspecialchars($trade['security_id']) . '</h4>
            <p>Client: ' . htmlspecialchars($trade['client_name']) . '</p>
            <p>Trade Date: ' . $trade['trade_date'] . '</p>
            <p>Consideration: ' . number_format($trade['consideration'], 2) . '</p>
          </div>';
}

function generateEquityContractNote($trade, $watermark, $master_data) {
    // Your existing equity contract note generation code
    echo '<div class="contract-note equity-contract">
            <h4>Equity Contract Note - ' . htmlspecialchars($trade['security_id']) . '</h4>
            <p>Client: ' . htmlspecialchars($trade['client_name']) . '</p>
            <p>Trade Date: ' . $trade['trade_date'] . '</p>
            <p>Consideration: ' . number_format($trade['consideration'], 2) . '</p>
          </div>';
}

function generateSummaryContractNote($group, $watermark, $master_data) {
    // Your existing summary contract note generation code
    $first_trade = $group[0];
    echo '<div class="contract-note summary-contract">
            <h4>Summary Contract Note - ' . htmlspecialchars($first_trade['client_name']) . '</h4>
            <p>Number of trades: ' . count($group) . '</p>
            <p>Total Consideration: ' . number_format(array_sum(array_column($group, 'consideration')), 2) . '</p>
          </div>';
}

// ==================== EXISTING FUNCTIONS ====================
function loadMasterData($db) {
    $master_data = [];
    $tables = [
        'bond_types', 'bond_issuers', 'bonds_economic_sectors', 'payment_frequencies',
        'share_types', 'share_market_trends', 'titles', 'identity_types',
        'transaction_types', 'payment_methods', 'ledger_types', 'companies',
        'custodians', 'brokers', 'gl_account_types', 'gl_account_formats'
    ];
    foreach ($tables as $table) {
        $stmt = $db->query("SELECT * FROM $table WHERE status = 'active' ORDER BY priority");
        $master_data[$table] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // Get distinct clients for filter
    $stmt = $db->query("SELECT DISTINCT client_cds_account, client_name FROM trades WHERE client_name IS NOT NULL AND client_name != '' ORDER BY client_name");
    $master_data['clients'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get distinct securities for filter
    $stmt = $db->query("SELECT DISTINCT security_id, asset_class FROM trades WHERE security_id IS NOT NULL ORDER BY security_id");
    $master_data['securities'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get distinct asset classes for filter
    $stmt = $db->query("SELECT DISTINCT asset_class FROM trades WHERE asset_class IS NOT NULL ORDER BY asset_class");
    $master_data['asset_classes'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    return $master_data;
}

function generateContractNotes($trades, $report_type, $watermark, $master_data, $contract_grouping = 'individual') {
    if (empty($trades)) {
        echo '<div class="alert alert-info text-center py-5">
                <i class="bi bi-info-circle fs-1 text-muted mb-3"></i>
                <h5>No trades found</h5>
                <p class="text-muted">No trades match your selected criteria. Please adjust your filters and try again.</p>
              </div>';
        return;
    }

    if ($contract_grouping === 'summary') {
        $grouped_trades = [];
        foreach ($trades as $trade) {
            $key = $trade['client_cds_account'] . '|' . $trade['trade_date'] . '|' . $trade['security_id'] . '|' . $trade['trade_side'];
            if (!isset($grouped_trades[$key])) {
                $grouped_trades[$key] = [];
            }
            $grouped_trades[$key][] = $trade;
        }
        
        foreach ($grouped_trades as $group) {
            generateSummaryContractNote($group, $watermark, $master_data);
            if (count($grouped_trades) > 1) {
                echo '<div style="page-break-after: always;"></div>';
            }
        }
    } else {
        foreach ($trades as $trade) {
            if ($trade['asset_class'] === 'bond') {
                generateBondContractNote($trade, $watermark, $master_data);
            } else {
                generateEquityContractNote($trade, $watermark, $master_data);
            }
            if (count($trades) > 1) {
                echo '<div style="page-break-after: always;"></div>';
            }
        }
    }
}

function generateCommissionSummary($trades, $report_type, $report_by, $master_data) {
    echo '<div class="alert alert-info text-center py-5">
            <h5>Commission Summary Report</h5>
            <p>This report is under development.</p>
          </div>';
}

function generateTransactionSummaryReports($trades, $report_type, $report_by, $master_data) {
    echo '<div class="alert alert-info text-center py-5">
            <h5>Transaction Summary Report</h5>
            <p>This report is under development.</p>
          </div>';
}

function generateBrokerSummary($trades, $report_type, $report_by, $master_data) {
    echo '<div class="alert alert-info text-center py-5">
            <h5>Broker Summary Report</h5>
            <p>This report is under development.</p>
          </div>';
}

function generateLedgerEntries($trades, $report_type, $report_by, $master_data) {
    echo '<div class="alert alert-info text-center py-5">
            <h5>General Ledger Report</h5>
            <p>This report is under development.</p>
          </div>';
}

function generateOrderForms($trades, $report_type, $master_data) {
    echo '<div class="alert alert-info text-center py-5">
            <h5>Order Forms Report</h5>
            <p>This report is under development.</p>
          </div>';
}

function generateAssetClassSummary($trades, $report_type, $report_by, $master_data) {
    echo '<div class="alert alert-info text-center py-5">
            <h5>Asset Class Summary Report</h5>
            <p>This report is under development.</p>
          </div>';
}

function generatePortfolioAnalysis($trades, $report_type, $master_data) {
    echo '<div class="alert alert-info text-center py-5">
            <h5>Portfolio Analysis Report</h5>
            <p>This report is under development.</p>
          </div>';
}

function generatePDFReport($trades, $report_type, $master_data) {
    echo '<div class="alert alert-info text-center py-5">
            <h5>PDF Export</h5>
            <p>PDF export functionality is under development.</p>
          </div>';
}

function generateExcelReport($trades, $report_type, $master_data) {
    echo '<div class="alert alert-info text-center py-5">
            <h5>Excel Export</h5>
            <p>Excel export functionality is under development.</p>
          </div>';
}

function generateCSVReport($trades, $report_type, $master_data) {
    echo '<div class="alert alert-info text-center py-5">
            <h5>CSV Export</h5>
            <p>CSV export functionality is under development.</p>
          </div>';
}
?>