<?php
require_once '../config/config.php';
require_once '../auth/auth_middleware.php';
require_once '../tcpdf/tcpdf.php';
require_once __DIR__ . '/traits/ReportHeaderTrait.php';

require_login();
$db = getDBConnection();

// Get company details - UPDATED to match your table structure
$company_stmt = $db->query("SELECT company_code, name as company_name, phone, address, email FROM companies WHERE is_active = 1 LIMIT 1");
$company = $company_stmt->fetch();
$company_name = $company ? $company['company_name'] : 'NEOVAM';
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
               e.description as equity_desc, e.isin,
               st.description as share_type_desc,
               smt.description as market_trend_desc,
               tt.description as title_desc,
               it.description as identity_type_desc,
               c.client_name as full_client_name,
               c.cds_account as full_cds_account
        FROM trades t
        LEFT JOIN equities_settings e ON t.security_id = e.security_id AND t.asset_class IN ('equity', 'Exchange Traded Funds')
        LEFT JOIN share_types st ON e.share_type = st.code
        LEFT JOIN share_market_trends smt ON e.market_trend = smt.code
        LEFT JOIN titles tt ON t.client_title = tt.code
        LEFT JOIN identity_types it ON t.client_identity_type = it.code
        LEFT JOIN clients c ON t.client_cds_account = c.cds_account
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
        $pdf->SetMargins(10, 30, 10);
        $pdf->SetAutoPageBreak(TRUE, 15);
        $pdf->AddPage();
        renderVfslPdfHeader($pdf);

        $html = generateBondsEditListHTML($transactions, $company_name, $company_code, $filters);
        $pdf->writeHTML($html, true, false, true, false, '');
        renderVfslPdfFooter($pdf);
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
        $pdf->SetMargins(10, 30, 10);
        $pdf->SetAutoPageBreak(TRUE, 15);
        $pdf->AddPage();
        renderVfslPdfHeader($pdf);

        $html = generateBondsSummaryHTML($client_balances, $company_name, $company_code, $filters);
        $pdf->writeHTML($html, true, false, true, false, '');
        renderVfslPdfFooter($pdf);
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
        $pdf->SetMargins(10, 30, 10);
        $pdf->SetAutoPageBreak(TRUE, 15);
        $pdf->AddPage();
        renderVfslPdfHeader($pdf);

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
        renderVfslPdfFooter($pdf);
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
        $pdf->SetMargins(10, 30, 10);
        $pdf->SetAutoPageBreak(TRUE, 15);
        $pdf->AddPage();
        renderVfslPdfHeader($pdf);

        $html = generateBondsCMSALevyHTML($totals, $cmsa_levy, $company_name, $company_code, $filters);
        $pdf->writeHTML($html, true, false, true, false, '');
        renderVfslPdfFooter($pdf);
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
        $pdf->SetMargins(10, 30, 10);
        $pdf->SetAutoPageBreak(TRUE, 15);
        $pdf->AddPage();
        renderVfslPdfHeader($pdf);

        $html = generateBondsDSELevyHTML($totals, $dse_levy, $company_name, $company_code, $filters);
        $pdf->writeHTML($html, true, false, true, false, '');
        renderVfslPdfFooter($pdf);
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
        $pdf->SetMargins(10, 30, 10);
        $pdf->SetAutoPageBreak(TRUE, 15);
        $pdf->AddPage();
        renderVfslPdfHeader($pdf);

        $html = generateBondsCSDLevyHTML($totals, $csd_levy, $company_name, $company_code, $filters);
        $pdf->writeHTML($html, true, false, true, false, '');
        renderVfslPdfFooter($pdf);
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
        $pdf->SetMargins(10, 30, 10);
        $pdf->SetAutoPageBreak(TRUE, 15);
        $pdf->AddPage();
        renderVfslPdfHeader($pdf);

        $html = generateBondsVATLevyHTML($totals, $vat_levy, $company_name, $company_code, $filters);
        $pdf->writeHTML($html, true, false, true, false, '');
        renderVfslPdfFooter($pdf);
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
        <div class="report-title">BONDS PURCHASES & SALES TRANSACTIONS EDIT LIST</div>
        <div class="report-subtitle">FOR THE PERIOD [' . $period_from . ' - ' . $period_to . ']</div>
        <div class="report-subtitle">Date : ' . $current_date . ' : ' . $current_time . '</div>
    </div>
    
    <div style="background-color: #f0f4f8; border-left: 4px solid #002e92; padding: 8px 12px; margin: 5px 0 10px 0; font-size: 8px; color: #333;">
        <strong>Report Overview:</strong> This Bonds Edit List provides a detailed record of all bond purchase and sale transactions 
        processed during the period ' . $period_from . ' to ' . $period_to . '. 
        Each row represents a single executed trade with the client, security, trade details, and a full breakdown of applicable charges.<br/>
        <strong>How to Read:</strong> Transactions are grouped by trade date. Charges include: Brokerage Commission (0.063%), DSE Transaction Levy, CMSA Levy, CSD Levy, and VAT on Commission (18%). 
        BUY trades: charges added to consideration. SELL trades: charges deducted from consideration.<br/>
        <strong>Key Columns:</strong> 
        SLIPNO = Trade reference | CONTRACT = Trade side:ID | 
        CONSIDERATION = Quantity x Price | TOTAL CHARGES = All fees combined | 
        GROSS/NET AMOUNT = Final settlement amount.
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
    $period_from = date('d/m/Y', strtotime($filters['period_from'] ?? date('Y-01-01')));
    $period_to = date('d/m/Y', strtotime($filters['period_to'] ?? date('Y-m-d')));
    
    $html = '
    <style>
        .header { text-align: center; margin-bottom: 10px; border-bottom: 1px solid #000; padding-bottom: 5px; }
        .report-title { font-size: 14px; font-weight: bold; margin: 5px 0; }
        .report-subtitle { font-size: 10px; margin: 2px 0; }
        .disclaimer { font-size: 8px; margin-top: 20px; color: #666; }
        table { width: 100%; border-collapse: collapse; font-size: 9px; margin: 5px 0; }
        th, td { border: 1px solid #000; padding: 4px; }
        th { background-color: #f0f0f0; font-weight: bold; }
        .text-left { text-align: left; }
        .text-right { text-align: right; }
        .total-row { background-color: #f8f8f8; font-weight: bold; }
    </style>
    
    <div class="header">
        <div class="report-title">BONDS TRANSACTIONS SUMMARY</div>
        <div class="report-subtitle">FOR THE PERIOD [' . $period_from . ' - ' . $period_to . ']</div>
    </div>
    
    <div style="background-color: #f0f4f8; border-left: 4px solid #002e92; padding: 8px 12px; margin: 5px 0 10px 0; font-size: 8px; color: #333;">
        <strong>Report Overview:</strong> This Bonds Summary Report provides a consolidated view of client positions in bond trading. 
        It summarizes total inflows (sales) and outflows (purchases) for each client, showing their net market exposure.<br/>
        <strong>How to Read:</strong> Inflow = bonds sold (money in). Outflow = bonds bought (money out). 
        Net = Inflow minus Outflow. Positive = net seller. Negative = net buyer. Balance = running total.
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
        .report-title { font-size: 14px; font-weight: bold; margin: 5px 0; }
        .report-subtitle { font-size: 10px; margin: 2px 0; }
        .disclaimer { font-size: 8px; margin-top: 20px; color: #666; }
        table { width: 100%; border-collapse: collapse; font-size: 8px; margin: 5px 0; }
        th, td { border: 1px solid #000; padding: 3px; text-align: center; }
        th { background-color: #f0f0f0; font-weight: bold; }
        .text-left { text-align: left; }
        .text-right { text-align: right; }
        .total-row { background-color: #f8f8f8; font-weight: bold; }
    </style>
    
    <div class="header">
        <div class="report-title">BONDS STATUTORY DEDUCTIONS DETAILED REPORT</div>
        <div class="report-subtitle">FOR THE PERIOD [' . $period_from . ' - ' . $period_to . ']</div>
        <div class="report-subtitle">Date : ' . $current_date . ' : ' . $current_time . '</div>
    </div>
    
    <div style="background-color: #f0f4f8; border-left: 4px solid #002e92; padding: 8px 12px; margin: 5px 0 10px 0; font-size: 8px; color: #333;">
        <strong>Report Overview:</strong> This Statutory Deductions Report breaks down all regulatory fees and levies charged on bond transactions 
        for the period ' . $period_from . ' to ' . $period_to . '. These fees are mandated by the Dar Es Salaam Stock Exchange (DSE), 
        Capital Markets and Securities Authority (CMSA), and Central Securities Depository (CSD).<br/>
        <strong>Fee Structure:</strong> DSE Transaction Levy (0.02006%) | CMSA Levy (0.01%) | CSD Levy (0.0118%) | 
        VAT on Brokerage Commission (18% of 0.063% = 0.01134%).<br/>
        <strong>Purpose:</strong> Use this report for regulatory compliance, fee reconciliation, and preparation of levy payments to DSE, CMSA, and CSD.
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
        .report-title { font-size: 14px; font-weight: bold; margin: 5px 0; }
        .disclaimer { font-size: 8px; margin-top: 20px; color: #666; }
        table { width: 100%; border-collapse: collapse; font-size: 10px; margin: 10px 0; }
        th, td { border: 1px solid #000; padding: 5px; }
        th { background-color: #f0f0f0; font-weight: bold; }
        .text-left { text-align: left; }
        .text-right { text-align: right; }
        .letter-header { margin: 15px 0; }
    </style>
    
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
    
    <div style="background-color: #f0f4f8; border-left: 4px solid #002e92; padding: 8px 12px; margin: 5px 0 10px 0; font-size: 9px; color: #333;">
        <strong>Re:</strong> CMSA Transaction Levy on Bonds for the period ' . $period_from . ' to ' . $period_to . '.<br/>
        This document details the Capital Markets and Securities Authority (CMSA) levy payable on bond transactions. 
        The CMSA levy is calculated at 0.01% of total transaction turnover (purchases + sales). 
        Please find enclosed our payment for the amount shown below.
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
        .report-title { font-size: 14px; font-weight: bold; margin: 5px 0; }
        .disclaimer { font-size: 8px; margin-top: 20px; color: #666; }
        table { width: 100%; border-collapse: collapse; font-size: 10px; margin: 10px 0; }
        th, td { border: 1px solid #000; padding: 5px; }
        th { background-color: #f0f0f0; font-weight: bold; }
        .text-left { text-align: left; }
        .text-right { text-align: right; }
        .letter-header { margin: 15px 0; }
    </style>
    
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
    
    <div style="background-color: #f0f4f8; border-left: 4px solid #002e92; padding: 8px 12px; margin: 5px 0 10px 0; font-size: 9px; color: #333;">
        <strong>Re:</strong> DSE Transaction Levy on Bonds for the period ' . $period_from . ' to ' . $period_to . '.<br/>
        This document details the Dar Es Salaam Stock Exchange (DSE) Transaction Levy payable on bond transactions. 
        The DSE levy is calculated at 0.02006% of total transaction turnover (purchases + sales). 
        Please find enclosed our payment for the amount shown below.
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
        .report-title { font-size: 14px; font-weight: bold; margin: 5px 0; }
        .disclaimer { font-size: 8px; margin-top: 20px; color: #666; }
        table { width: 100%; border-collapse: collapse; font-size: 10px; margin: 10px 0; }
        th, td { border: 1px solid #000; padding: 5px; }
        th { background-color: #f0f0f0; font-weight: bold; }
        .text-left { text-align: left; }
        .text-right { text-align: right; }
        .letter-header { margin: 15px 0; }
    </style>
    
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
    
    <div style="background-color: #f0f4f8; border-left: 4px solid #002e92; padding: 8px 12px; margin: 5px 0 10px 0; font-size: 9px; color: #333;">
        <strong>Re:</strong> CSD Transaction Levy on Bonds for the period ' . $period_from . ' to ' . $period_to . '.<br/>
        This document details the Central Securities Depository (CSD) Levy payable on bond transactions. 
        The CSD levy is calculated at 0.0118% of total transaction turnover (purchases + sales). 
        Please find enclosed our payment for the amount shown below.
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
        .report-title { font-size: 14px; font-weight: bold; margin: 5px 0; }
        .disclaimer { font-size: 8px; margin-top: 20px; color: #666; }
        table { width: 100%; border-collapse: collapse; font-size: 10px; margin: 10px 0; }
        th, td { border: 1px solid #000; padding: 5px; }
        th { background-color: #f0f0f0; font-weight: bold; }
        .text-left { text-align: left; }
        .text-right { text-align: right; }
        .letter-header { margin: 15px 0; }
    </style>
    
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
    
    <div style="background-color: #f0f4f8; border-left: 4px solid #002e92; padding: 8px 12px; margin: 5px 0 10px 0; font-size: 9px; color: #333;">
        <strong>Re:</strong> VAT on Brokerage Commission for the period ' . $period_from . ' to ' . $period_to . '.<br/>
        This document details the Value Added Tax (VAT) payable on brokerage commission for bond transactions. 
        VAT is calculated at 18% of the gross brokerage commission (0.063% of turnover). 
        Please find enclosed our payment for the amount shown below.
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
    // Get fee calculations for bonds
    $fees = calculateBondFees($trade['consideration']);
    
    echo '<div class="contract-note bond-contract" style="border: 1px solid #ccc; margin: 20px 0; padding: 20px; background: white;">
            <div style="text-align: center; border-bottom: 2px solid #000; padding-bottom: 10px; margin-bottom: 20px;">
                <h3 style="margin: 0;">' . htmlspecialchars($master_data['company_name'] ?? 'NEOVAM') . '</h3>
                <p style="margin: 5px 0;"><strong>BOND CONTRACT NOTE</strong></p>
            </div>
            
            <div style="display: flex; justify-content: space-between; margin-bottom: 20px;">
                <div>
                    <p><strong>Trade Reference:</strong> ' . htmlspecialchars($trade['trade_reference']) . '</p>
                    <p><strong>Trade Date:</strong> ' . date('d/m/Y', strtotime($trade['trade_date'])) . '</p>
                    <p><strong>Settlement Date:</strong> ' . date('d/m/Y', strtotime($trade['settlement_date'])) . '</p>
                </div>
                <div>
                    <p><strong>Client:</strong> ' . htmlspecialchars($trade['client_name']) . '</p>
                    <p><strong>CDS Account:</strong> ' . htmlspecialchars($trade['client_cds_account']) . '</p>
                    <p><strong>Trade Side:</strong> ' . strtoupper($trade['trade_side']) . '</p>
                </div>
            </div>
            
            <table style="width: 100%; border-collapse: collapse; margin-bottom: 20px;">
                <thead>
                    <tr style="background: #f5f5f5;">
                        <th style="border: 1px solid #ddd; padding: 8px; text-align: left;">Security</th>
                        <th style="border: 1px solid #ddd; padding: 8px; text-align: right;">Quantity</th>
                        <th style="border: 1px solid #ddd; padding: 8px; text-align: right;">Price</th>
                        <th style="border: 1px solid #ddd; padding: 8px; text-align: right;">Consideration</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td style="border: 1px solid #ddd; padding: 8px;">' . htmlspecialchars($trade['security_id']) . '</td>
                        <td style="border: 1px solid #ddd; padding: 8px; text-align: right;">' . number_format($trade['quantity']) . '</td>
                        <td style="border: 1px solid #ddd; padding: 8px; text-align: right;">' . number_format($trade['price'], 4) . '</td>
                        <td style="border: 1px solid #ddd; padding: 8px; text-align: right;">TZS ' . number_format($trade['consideration'], 2) . '</td>
                    </tr>
                </tbody>
            </table>
            
            <div style="background: #f9f9f9; padding: 15px; border: 1px solid #ddd;">
                <h4 style="margin-top: 0;">Fee Breakdown</h4>
                <table style="width: 100%;">
                    <tr>
                        <td>Brokerage Commission (0.063%):</td>
                        <td style="text-align: right;">TZS ' . number_format($fees['brokerage_commission'], 2) . '</td>
                    </tr>
                    <tr>
                        <td>DSE Fee (0.02006%):</td>
                        <td style="text-align: right;">TZS ' . number_format($fees['dse_fee'], 2) . '</td>
                    </tr>
                    <tr>
                        <td>CMSA Fee (0.01%):</td>
                        <td style="text-align: right;">TZS ' . number_format($fees['cmsa_fee'], 2) . '</td>
                    </tr>
                    <tr>
                        <td>CSD Fee (0.0708%):</td>
                        <td style="text-align: right;">TZS ' . number_format($fees['cds_fee'], 2) . '</td>
                    </tr>
                    <tr>
                        <td>VAT on Commission (18%):</td>
                        <td style="text-align: right;">TZS ' . number_format($fees['vat_on_commission'], 2) . '</td>
                    </tr>
                    <tr style="border-top: 1px solid #ddd; font-weight: bold;">
                        <td>Total Charges:</td>
                        <td style="text-align: right;">TZS ' . number_format($fees['total_charges'], 2) . '</td>
                    </tr>
                </table>
            </div>
            
            <div style="margin-top: 20px; padding-top: 10px; border-top: 2px solid #000;">
                <p><strong>Net Amount:</strong> ' . ($trade['trade_side'] === 'BUY' ? 'TZS ' . number_format($trade['consideration'] + $fees['total_charges'], 2) : 'TZS ' . number_format($trade['consideration'] - $fees['total_charges'], 2)) . '</p>
                <p style="font-size: 12px; color: #666; margin-top: 20px;">
                    <em>This is a computer generated contract note. No signature required.</em>
                </p>
            </div>
          </div>';
}

function generateEquityContractNote($trade, $watermark, $master_data) {
    // Calculate equity fees
    $fees = calculateEquityFeesForReport($trade['consideration']);
    
    $is_etf = ($trade['asset_class'] === 'Exchange Traded Funds');
    
    echo '<div class="contract-note equity-contract" style="border: 1px solid #ccc; margin: 20px 0; padding: 20px; background: white;">
            <div style="text-align: center; border-bottom: 2px solid #000; padding-bottom: 10px; margin-bottom: 20px;">
                <h3 style="margin: 0;">' . htmlspecialchars($master_data['company_name'] ?? 'NEOVAM') . '</h3>
                <p style="margin: 5px 0;"><strong>' . ($is_etf ? 'ETF' : 'EQUITY') . ' CONTRACT NOTE</strong></p>
            </div>
            
            <div style="display: flex; justify-content: space-between; margin-bottom: 20px;">
                <div>
                    <p><strong>Trade Reference:</strong> ' . htmlspecialchars($trade['trade_reference']) . '</p>
                    <p><strong>Trade Date:</strong> ' . date('d/m/Y', strtotime($trade['trade_date'])) . '</p>
                    <p><strong>Settlement Date:</strong> ' . date('d/m/Y', strtotime($trade['settlement_date'])) . '</p>
                </div>
                <div>
                    <p><strong>Client:</strong> ' . htmlspecialchars($trade['client_name']) . '</p>
                    <p><strong>CDS Account:</strong> ' . htmlspecialchars($trade['client_cds_account']) . '</p>
                    <p><strong>Trade Side:</strong> ' . strtoupper($trade['trade_side']) . '</p>
                </div>
            </div>
            
            <table style="width: 100%; border-collapse: collapse; margin-bottom: 20px;">
                <thead>
                    <tr style="background: #f5f5f5;">
                        <th style="border: 1px solid #ddd; padding: 8px; text-align: left;">Security</th>
                        <th style="border: 1px solid #ddd; padding: 8px; text-align: right;">Quantity</th>
                        <th style="border: 1px solid #ddd; padding: 8px; text-align: right;">Price</th>
                        <th style="border: 1px solid #ddd; padding: 8px; text-align: right;">Consideration</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td style="border: 1px solid #ddd; padding: 8px;">
                            ' . htmlspecialchars($trade['security_id']) . '
                            ' . ($is_etf ? '<br><small>ETF</small>' : '') . '
                        </td>
                        <td style="border: 1px solid #ddd; padding: 8px; text-align: right;">' . number_format($trade['quantity']) . '</td>
                        <td style="border: 1px solid #ddd; padding: 8px; text-align: right;">' . number_format($trade['price'], 2) . '</td>
                        <td style="border: 1px solid #ddd; padding: 8px; text-align: right;">TZS ' . number_format($trade['consideration'], 2) . '</td>
                    </tr>
                </tbody>
            </table>
            
            <div style="background: #f9f9f9; padding: 15px; border: 1px solid #ddd;">
                <h4 style="margin-top: 0;">Brokerage & VAT Calculation</h4>
                <p style="font-size: 12px; color: #666; margin-bottom: 10px;">
                    <em>Regulatory fees (CMSA, DSE, CSDR, VRF) are recorded separately for accountant assignment.</em>
                </p>
                
                <table style="width: 100%;">
                    <tr>
                        <td>Brokerage Commission (Tiered):</td>
                        <td style="text-align: right;">TZS ' . number_format($fees['brokerage'], 2) . '</td>
                    </tr>
                    <tr>
                        <td>VAT on Commission (18%):</td>
                        <td style="text-align: right;">TZS ' . number_format($fees['vat'], 2) . '</td>
                    </tr>
                    <tr style="border-top: 1px solid #ddd; font-weight: bold;">
                        <td>Total Brokerage & VAT:</td>
                        <td style="text-align: right;">TZS ' . number_format($fees['brokerage_vat_total'], 2) . '</td>
                    </tr>
                </table>
                
                <div style="margin-top: 15px; font-size: 12px; background: #fff3cd; padding: 10px; border-radius: 4px;">
                    <strong>Note:</strong> Additional regulatory fees have been recorded for accountant review:<br>
                    • CMSA Fee: TZS ' . number_format($fees['cmsa'], 2) . '<br>
                    • DSE Fee: TZS ' . number_format($fees['dse'], 2) . '<br>
                    • CSDR Fee: TZS ' . number_format($fees['csd'], 2) . '<br>
                    • VRF Fee: TZS ' . number_format($fees['vrf'], 2) . '<br>
                    <em>Total Regulatory Fees: TZS ' . number_format($fees['regulatory_total'], 2) . '</em>
                </div>
            </div>
            
            <div style="margin-top: 20px; padding-top: 10px; border-top: 2px solid #000;">
                <p><strong>Net Amount Payable/Receivable:</strong> ' . 
                    ($trade['trade_side'] === 'BUY' ? 
                        'TZS ' . number_format($trade['consideration'] + $fees['brokerage_vat_total'], 2) : 
                        'TZS ' . number_format($trade['consideration'] - $fees['brokerage_vat_total'], 2)) . '</p>
                <p style="font-size: 12px; color: #666; margin-top: 20px;">
                    <em>This is a computer generated contract note. No signature required.</em>
                </p>
            </div>
          </div>';
}

function generateSummaryContractNote($group, $watermark, $master_data) {
    // Calculate totals for the group
    $first_trade = $group[0];
    $total_quantity = 0;
    $total_consideration = 0;
    $total_brokerage_vat = 0;
    $security_ids = [];
    
    foreach ($group as $trade) {
        $total_quantity += $trade['quantity'];
        $total_consideration += $trade['consideration'];
        
        // Calculate fees for this trade
        if ($trade['asset_class'] === 'bond') {
            $fees = calculateBondFees($trade['consideration']);
            $total_brokerage_vat += $fees['total_charges'];
        } else {
            $fees = calculateEquityFeesForReport($trade['consideration']);
            $total_brokerage_vat += $fees['brokerage_vat_total'];
        }
        
        if (!in_array($trade['security_id'], $security_ids)) {
            $security_ids[] = $trade['security_id'];
        }
    }
    
    $is_etf = ($first_trade['asset_class'] === 'Exchange Traded Funds');
    $asset_type = $first_trade['asset_class'] === 'bond' ? 'BOND' : ($is_etf ? 'ETF' : 'EQUITY');
    
    echo '<div class="contract-note summary-contract" style="border: 1px solid #ccc; margin: 20px 0; padding: 20px; background: white;">
            <div style="text-align: center; border-bottom: 2px solid #000; padding-bottom: 10px; margin-bottom: 20px;">
                <h3 style="margin: 0;">' . htmlspecialchars($master_data['company_name'] ?? 'NEOVAM') . '</h3>
                <p style="margin: 5px 0;"><strong>SUMMARY CONTRACT NOTE - ' . $asset_type . '</strong></p>
                <p style="margin: 5px 0; font-size: 14px;">' . count($group) . ' trades for ' . htmlspecialchars($first_trade['client_name']) . '</p>
            </div>
            
            <div style="display: flex; justify-content: space-between; margin-bottom: 20px;">
                <div>
                    <p><strong>Client:</strong> ' . htmlspecialchars($first_trade['client_name']) . '</p>
                    <p><strong>CDS Account:</strong> ' . htmlspecialchars($first_trade['client_cds_account']) . '</p>
                    <p><strong>Trade Date:</strong> ' . date('d/m/Y', strtotime($first_trade['trade_date'])) . '</p>
                </div>
                <div>
                    <p><strong>Total Trades:</strong> ' . count($group) . '</p>
                    <p><strong>Securities:</strong> ' . implode(', ', array_slice($security_ids, 0, 3)) . 
                       (count($security_ids) > 3 ? ' and ' . (count($security_ids) - 3) . ' more' : '') . '</p>
                    <p><strong>Trade Side:</strong> ' . strtoupper($first_trade['trade_side']) . '</p>
                </div>
            </div>
            
            <table style="width: 100%; border-collapse: collapse; margin-bottom: 20px;">
                <thead>
                    <tr style="background: #f5f5f5;">
                        <th style="border: 1px solid #ddd; padding: 8px; text-align: left;">Summary</th>
                        <th style="border: 1px solid #ddd; padding: 8px; text-align: right;">Total Quantity</th>
                        <th style="border: 1px solid #ddd; padding: 8px; text-align: right;">Total Consideration</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td style="border: 1px solid #ddd; padding: 8px;">
                            <strong>' . count($group) . ' ' . ($first_trade['asset_class'] === 'bond' ? 'Bond' : ($is_etf ? 'ETF' : 'Equity')) . ' Trades</strong><br>
                            <small>' . date('d/m/Y', strtotime($first_trade['trade_date'])) . '</small>
                        </td>
                        <td style="border: 1px solid #ddd; padding: 8px; text-align: right;">' . number_format($total_quantity) . '</td>
                        <td style="border: 1px solid #ddd; padding: 8px; text-align: right;">TZS ' . number_format($total_consideration, 2) . '</td>
                    </tr>
                </tbody>
            </table>
            
            <div style="background: #f9f9f9; padding: 15px; border: 1px solid #ddd;">
                <h4 style="margin-top: 0;">Total Fee Summary</h4>
                <table style="width: 100%;">
                    <tr>
                        <td>Total Brokerage & VAT:</td>
                        <td style="text-align: right;">TZS ' . number_format($total_brokerage_vat, 2) . '</td>
                    </tr>
                    <tr style="border-top: 1px solid #ddd; font-weight: bold;">
                        <td>Net Total Amount:</td>
                        <td style="text-align: right;">
                            ' . ($first_trade['trade_side'] === 'BUY' ? 
                                'TZS ' . number_format($total_consideration + $total_brokerage_vat, 2) : 
                                'TZS ' . number_format($total_consideration - $total_brokerage_vat, 2)) . '
                        </td>
                    </tr>
                </table>
                
                <div style="margin-top: 15px; font-size: 12px; background: #fff3cd; padding: 10px; border-radius: 4px;">
                    <strong>Detailed Breakdown:</strong> Each individual trade has its own contract note with complete fee breakdown.<br>
                    <strong>Regulatory Fees:</strong> All regulatory fees (CMSA, DSE, CSDR, VRF) have been recorded separately for accountant assignment.
                </div>
            </div>
            
            <div style="margin-top: 20px; padding-top: 10px; border-top: 2px solid #000;">
                <p><strong>Individual Trade References:</strong> ' . 
                    implode(', ', array_slice(array_column($group, 'trade_reference'), 0, 5)) . 
                    (count($group) > 5 ? '...' : '') . '</p>
                <p style="font-size: 12px; color: #666; margin-top: 20px;">
                    <em>This is a summary contract note. Refer to individual contract notes for complete details.</em>
                </p>
            </div>
          </div>';
}

// ==================== EXISTING FUNCTIONS ====================
function loadMasterData($db) {
    $master_data = [];
    
    // Load company info
    $company_stmt = $db->query("SELECT name as company_name FROM companies WHERE is_active = 1 LIMIT 1");
    $company = $company_stmt->fetch();
    $master_data['company_name'] = $company ? $company['company_name'] : 'NEOVAM';
    
    // Load other master data
    $tables = [
        'share_types', 'share_market_trends', 'titles', 'identity_types',
        'transaction_types', 'payment_methods', 'ledger_types', 'custodians',
        'brokers', 'gl_account_types', 'gl_account_formats'
    ];
    
    foreach ($tables as $table) {
        try {
            $stmt = $db->query("SELECT * FROM $table WHERE status = 'active' OR is_active = 1 ORDER BY priority");
            $master_data[$table] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            $master_data[$table] = [];
            error_log("Error loading table {$table}: " . $e->getMessage());
        }
    }
    
    // Get distinct clients for filter
    try {
        $stmt = $db->query("SELECT DISTINCT client_cds_account, client_name FROM trades WHERE client_name IS NOT NULL AND client_name != '' ORDER BY client_name LIMIT 100");
        $master_data['clients'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $master_data['clients'] = [];
    }
    
    // Get distinct securities for filter
    try {
        $stmt = $db->query("SELECT DISTINCT security_id, asset_class FROM trades WHERE security_id IS NOT NULL ORDER BY security_id LIMIT 100");
        $master_data['securities'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $master_data['securities'] = [];
    }
    
    // Get distinct asset classes for filter
    try {
        $stmt = $db->query("SELECT DISTINCT asset_class FROM trades WHERE asset_class IS NOT NULL ORDER BY asset_class");
        $master_data['asset_classes'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $master_data['asset_classes'] = [];
    }
    
    return $master_data;
}

function calculateEquityFeesForReport($consideration) {
    // Calculate tiered brokerage
    if ($consideration <= 10000000) {
        $brokerage = $consideration * (1.7 / 100);
    } elseif ($consideration <= 50000000) {
        $brokerage = $consideration * (1.5 / 100);
    } else {
        $brokerage = $consideration * (0.8 / 100);
    }
    
    $vat = $brokerage * 0.18;
    
    // Regulatory fees (for display only - assigned separately)
    $cmsa = $consideration * (0.01 / 100);
    $dse = $consideration * (0.02006 / 100);
    $csd = $consideration * (0.0118 / 100);
    $vrf = $consideration * (0.0025 / 100);
    
    return [
        'brokerage' => $brokerage,
        'vat' => $vat,
        'cmsa' => $cmsa,
        'dse' => $dse,
        'csd' => $csd,
        'vrf' => $vrf,
        'brokerage_vat_total' => $brokerage + $vat,
        'regulatory_total' => $cmsa + $dse + $csd + $vrf
    ];
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
        foreach ($trades as $index => $trade) {
            if ($trade['asset_class'] === 'bond') {
                generateBondContractNote($trade, $watermark, $master_data);
            } else {
                generateEquityContractNote($trade, $watermark, $master_data);
            }
            if ($index < count($trades) - 1) {
                echo '<div style="page-break-after: always;"></div>';
            }
        }
    }
}

function generateCommissionSummary($trades, $report_type, $report_by, $master_data) {
    if (empty($trades)) {
        echo '<div class="alert alert-info text-center py-5">
                <i class="bi bi-info-circle fs-1 text-muted mb-3"></i>
                <h5>No Commission Data Found</h5>
                <p class="text-muted">No trades match your selected criteria. Please adjust your filters and try again.</p>
              </div>';
        return;
    }
    
    // Calculate commissions by grouping
    $commissions = [];
    $total_commission = 0;
    $total_brokerage = 0;
    $total_vat = 0;
    
    foreach ($trades as $trade) {
        if ($trade['asset_class'] === 'bond') {
            $fees = calculateBondFees($trade['consideration']);
            $brokerage = $fees['brokerage_commission'];
            $vat = $fees['vat_on_commission'];
        } else {
            $fees = calculateEquityFeesForReport($trade['consideration']);
            $brokerage = $fees['brokerage'];
            $vat = $fees['vat'];
        }
        
        $commission = $brokerage + $vat;
        
        // Group by selected period
        $group_key = '';
        switch ($report_by) {
            case 'day':
                $group_key = $trade['trade_date'];
                break;
            case 'week':
                $week_start = date('Y-m-d', strtotime('monday this week', strtotime($trade['trade_date'])));
                $group_key = $week_start;
                break;
            case 'month':
                $group_key = date('Y-m', strtotime($trade['trade_date']));
                break;
            case 'quarter':
                $month = date('n', strtotime($trade['trade_date']));
                $quarter = ceil($month / 3);
                $group_key = date('Y', strtotime($trade['trade_date'])) . '-Q' . $quarter;
                break;
            case 'year':
                $group_key = date('Y', strtotime($trade['trade_date']));
                break;
            default:
                $group_key = 'all';
        }
        
        if (!isset($commissions[$group_key])) {
            $commissions[$group_key] = [
                'brokerage' => 0,
                'vat' => 0,
                'commission' => 0,
                'trade_count' => 0,
                'consideration' => 0
            ];
        }
        
        $commissions[$group_key]['brokerage'] += $brokerage;
        $commissions[$group_key]['vat'] += $vat;
        $commissions[$group_key]['commission'] += $commission;
        $commissions[$group_key]['trade_count']++;
        $commissions[$group_key]['consideration'] += $trade['consideration'];
        
        $total_brokerage += $brokerage;
        $total_vat += $vat;
        $total_commission += $commission;
    }
    
    ksort($commissions);
    
    echo '<div class="card">
            <div class="card-header">
                <h5 class="mb-0">Commission Summary Report</h5>
                <p class="text-muted mb-0">Grouped by ' . ucfirst($report_by) . '</p>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Period</th>
                                <th>Trades</th>
                                <th>Consideration</th>
                                <th>Brokerage</th>
                                <th>VAT</th>
                                <th>Total Commission</th>
                                <th>Avg. Commission Rate</th>
                            </tr>
                        </thead>
                        <tbody>';
    
    foreach ($commissions as $period => $data) {
        $period_display = $period;
        if ($report_by === 'month') {
            $period_display = date('F Y', strtotime($period . '-01'));
        } elseif ($report_by === 'quarter') {
            $period_display = str_replace('-Q', ' Q', $period);
        }
        
        $avg_rate = $data['consideration'] > 0 ? ($data['commission'] / $data['consideration'] * 100) : 0;
        
        echo '<tr>
                <td>' . htmlspecialchars($period_display) . '</td>
                <td>' . number_format($data['trade_count']) . '</td>
                <td class="text-end">TZS ' . number_format($data['consideration'], 2) . '</td>
                <td class="text-end">TZS ' . number_format($data['brokerage'], 2) . '</td>
                <td class="text-end">TZS ' . number_format($data['vat'], 2) . '</td>
                <td class="text-end"><strong>TZS ' . number_format($data['commission'], 2) . '</strong></td>
                <td class="text-end">' . number_format($avg_rate, 4) . '%</td>
              </tr>';
    }
    
    $overall_rate = $total_commission > 0 ? ($total_commission / array_sum(array_column($commissions, 'consideration')) * 100) : 0;
    
    echo '<tr class="table-primary">
            <td><strong>Total</strong></td>
            <td><strong>' . number_format(array_sum(array_column($commissions, 'trade_count'))) . '</strong></td>
            <td class="text-end"><strong>TZS ' . number_format(array_sum(array_column($commissions, 'consideration')), 2) . '</strong></td>
            <td class="text-end"><strong>TZS ' . number_format($total_brokerage, 2) . '</strong></td>
            <td class="text-end"><strong>TZS ' . number_format($total_vat, 2) . '</strong></td>
            <td class="text-end"><strong>TZS ' . number_format($total_commission, 2) . '</strong></td>
            <td class="text-end"><strong>' . number_format($overall_rate, 4) . '%</strong></td>
          </tr>';
    
    echo '</tbody>
        </table>
    </div>
</div>
<div class="card-footer">
    <small class="text-muted">
        <strong>Note:</strong> Commission includes brokerage + VAT. Regulatory fees (CMSA, DSE, CSDR, VRF) are recorded separately for accountant assignment.
    </small>
</div>
</div>';
}

function generateTransactionSummaryReports($trades, $report_type, $report_by, $master_data) {
    if (empty($trades)) {
        echo '<div class="alert alert-info text-center py-5">
                <i class="bi bi-info-circle fs-1 text-muted mb-3"></i>
                <h5>No Transaction Data Found</h5>
                <p class="text-muted">No trades match your selected criteria. Please adjust your filters and try again.</p>
              </div>';
        return;
    }
    
    // Prepare summary data
    $summary = [
        'buy' => ['count' => 0, 'quantity' => 0, 'consideration' => 0],
        'sell' => ['count' => 0, 'quantity' => 0, 'consideration' => 0]
    ];
    
    $by_asset_class = [];
    $by_client = [];
    $by_security = [];
    
    foreach ($trades as $trade) {
        $side = strtolower($trade['trade_side']);
        $summary[$side]['count']++;
        $summary[$side]['quantity'] += $trade['quantity'];
        $summary[$side]['consideration'] += $trade['consideration'];
        
        // By asset class
        $asset_class = $trade['asset_class'];
        if (!isset($by_asset_class[$asset_class])) {
            $by_asset_class[$asset_class] = ['buy' => 0, 'sell' => 0, 'quantity' => 0, 'consideration' => 0];
        }
        $by_asset_class[$asset_class][$side]++;
        $by_asset_class[$asset_class]['quantity'] += $trade['quantity'];
        $by_asset_class[$asset_class]['consideration'] += $trade['consideration'];
        
        // By client
        $client_key = $trade['client_name'] . '|' . $trade['client_cds_account'];
        if (!isset($by_client[$client_key])) {
            $by_client[$client_key] = [
                'name' => $trade['client_name'],
                'account' => $trade['client_cds_account'],
                'buy' => 0, 'sell' => 0, 'quantity' => 0, 'consideration' => 0
            ];
        }
        $by_client[$client_key][$side]++;
        $by_client[$client_key]['quantity'] += $trade['quantity'];
        $by_client[$client_key]['consideration'] += $trade['consideration'];
        
        // By security
        $security_key = $trade['security_id'];
        if (!isset($by_security[$security_key])) {
            $by_security[$security_key] = [
                'id' => $trade['security_id'],
                'name' => $trade['security_name'] ?? $trade['security_id'],
                'asset_class' => $trade['asset_class'],
                'buy' => 0, 'sell' => 0, 'quantity' => 0, 'consideration' => 0
            ];
        }
        $by_security[$security_key][$side]++;
        $by_security[$security_key]['quantity'] += $trade['quantity'];
        $by_security[$security_key]['consideration'] += $trade['consideration'];
    }
    
    echo '<div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0">Transaction Summary - Overview</h5>
            </div>
            <div class="card-body">
                <div class="row">';
    
    // Overview cards
    $total_trades = $summary['buy']['count'] + $summary['sell']['count'];
    $total_quantity = $summary['buy']['quantity'] + $summary['sell']['quantity'];
    $total_consideration = $summary['buy']['consideration'] + $summary['sell']['consideration'];
    $net_flow = $summary['sell']['consideration'] - $summary['buy']['consideration'];
    
    $cards = [
        ['title' => 'Total Trades', 'value' => number_format($total_trades), 'color' => 'primary', 'icon' => 'bi-file-text'],
        ['title' => 'Total Quantity', 'value' => number_format($total_quantity), 'color' => 'info', 'icon' => 'bi-123'],
        ['title' => 'Total Consideration', 'value' => 'TZS ' . number_format($total_consideration, 2), 'color' => 'success', 'icon' => 'bi-cash-stack'],
        ['title' => 'Net Cash Flow', 'value' => 'TZS ' . number_format($net_flow, 2), 'color' => $net_flow >= 0 ? 'success' : 'danger', 'icon' => 'bi-arrow-left-right']
    ];
    
    foreach ($cards as $card) {
        echo '<div class="col-md-3 mb-3">
                <div class="card border-' . $card['color'] . '">
                    <div class="card-body">
                        <div class="d-flex align-items-center">
                            <div class="me-3">
                                <i class="bi ' . $card['icon'] . ' fs-2 text-' . $card['color'] . '"></i>
                            </div>
                            <div>
                                <h6 class="card-subtitle mb-1 text-muted">' . $card['title'] . '</h6>
                                <h4 class="card-title mb-0">' . $card['value'] . '</h4>
                            </div>
                        </div>
                    </div>
                </div>
              </div>';
    }
    
    echo '</div>
        </div>
    </div>';
    
    // Detailed breakdowns
    echo '<div class="card">
            <div class="card-header">
                <h5 class="mb-0">Detailed Breakdown</h5>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Category</th>
                                <th>Buy Trades</th>
                                <th>Buy Quantity</th>
                                <th>Buy Consideration</th>
                                <th>Sell Trades</th>
                                <th>Sell Quantity</th>
                                <th>Sell Consideration</th>
                                <th>Total Trades</th>
                                <th>Total Quantity</th>
                                <th>Total Consideration</th>
                            </tr>
                        </thead>
                        <tbody>';
    
    // Overall summary
    echo '<tr>
            <td><strong>Overall</strong></td>
            <td>' . number_format($summary['buy']['count']) . '</td>
            <td>' . number_format($summary['buy']['quantity']) . '</td>
            <td>TZS ' . number_format($summary['buy']['consideration'], 2) . '</td>
            <td>' . number_format($summary['sell']['count']) . '</td>
            <td>' . number_format($summary['sell']['quantity']) . '</td>
            <td>TZS ' . number_format($summary['sell']['consideration'], 2) . '</td>
            <td><strong>' . number_format($total_trades) . '</strong></td>
            <td><strong>' . number_format($total_quantity) . '</strong></td>
            <td><strong>TZS ' . number_format($total_consideration, 2) . '</strong></td>
          </tr>';
    
    // By asset class
    foreach ($by_asset_class as $class => $data) {
        $class_total_trades = $data['buy'] + $data['sell'];
        echo '<tr>
                <td><em>' . htmlspecialchars($class) . '</em></td>
                <td>' . number_format($data['buy']) . '</td>
                <td>' . number_format($data['quantity'] * ($data['buy'] / max(1, $class_total_trades))) . '</td>
                <td>TZS ' . number_format($data['consideration'] * ($data['buy'] / max(1, $class_total_trades)), 2) . '</td>
                <td>' . number_format($data['sell']) . '</td>
                <td>' . number_format($data['quantity'] * ($data['sell'] / max(1, $class_total_trades))) . '</td>
                <td>TZS ' . number_format($data['consideration'] * ($data['sell'] / max(1, $class_total_trades)), 2) . '</td>
                <td>' . number_format($class_total_trades) . '</td>
                <td>' . number_format($data['quantity']) . '</td>
                <td>TZS ' . number_format($data['consideration'], 2) . '</td>
              </tr>';
    }
    
    echo '</tbody>
        </table>
    </div>
</div>
</div>';
}

function generateBrokerSummary($trades, $report_type, $report_by, $master_data) {
    echo '<div class="alert alert-info text-center py-5">
            <h5>Broker Summary Report</h5>
            <p>This report shows brokerage activity by broker.</p>
          </div>';
}

function generateLedgerEntries($trades, $report_type, $report_by, $master_data) {
    echo '<div class="alert alert-info text-center py-5">
            <h5>General Ledger Report</h5>
            <p>This report shows ledger entries for the selected trades.</p>
          </div>';
}

function generateOrderForms($trades, $report_type, $master_data) {
    echo '<div class="alert alert-info text-center py-5">
            <h5>Order Forms Report</h5>
            <p>This report shows order forms for the selected trades.</p>
          </div>';
}

function generateAssetClassSummary($trades, $report_type, $report_by, $master_data) {
    echo '<div class="alert alert-info text-center py-5">
            <h5>Asset Class Summary Report</h5>
            <p>This report shows summary by asset class.</p>
          </div>';
}

function generatePortfolioAnalysis($trades, $report_type, $master_data) {
    echo '<div class="alert alert-info text-center py-5">
            <h5>Portfolio Analysis Report</h5>
            <p>This report shows portfolio analysis.</p>
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