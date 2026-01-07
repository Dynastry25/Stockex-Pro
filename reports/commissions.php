<?php
require_once '../config/config.php';
require_once '../auth/auth_middleware.php';
require_once '../tcpdf/tcpdf.php';

require_login();

class CommissionPDF extends TCPDF {
    private $current_asset_class = '';
    private $company_name = '';
    private $company_address = '';
    private $company_phone = '';
    private $company_email = '';
    
    public function setCompanyInfo($name, $address, $phone, $email) {
        $this->company_name = $name;
        $this->company_address = $address;
        $this->company_phone = $phone;
        $this->company_email = $email;
    }
    
    // Page header
    public function Header() {
        // Logo
        $image_file = '../assets/HeaderLogoVfsl.jpg';
        if (file_exists($image_file)) {
            $this->Image($image_file, 15, 10, 80, 0, 'JPG', '', 'T', false, 300, '', false, false, 0, false, false, false);
        }
        
        // Set Times New Roman font
        $this->SetFont('times', 'B', 12);
        
        // Company name from database
        $this->SetY(15);
        $this->Cell(0, 10, $this->company_name, 0, 1, 'C');
        $this->SetFont('times', '', 9);
        $this->Cell(0, 5, 'Stock Broker / Dealer & Investment Advisor', 0, 1, 'C');
        $this->Cell(0, 5, 'Member of Dar es Salaam Stock Exchange', 0, 1, 'C');
        
        // Company address and contact from database
        if (!empty($this->company_address)) {
            $this->SetFont('times', '', 8);
            $this->Cell(0, 5, $this->company_address, 0, 1, 'C');
        }
        
        if (!empty($this->company_phone) || !empty($this->company_email)) {
            $contact_info = [];
            if (!empty($this->company_phone)) $contact_info[] = 'Tel: ' . $this->company_phone;
            if (!empty($this->company_email)) $contact_info[] = 'Email: ' . $this->company_email;
            
            $this->Cell(0, 5, implode(' | ', $contact_info), 0, 1, 'C');
        }
        
        // Line break
        $this->Ln(3);
    }

    // Page footer
    public function Footer() {
        // Position at 15 mm from bottom
        $this->SetY(-15);
        $this->SetFont('times', 'I', 8);
        
        // Disclaimer
        $disclaimer = $this->company_name . " has prepared this Report solely for informational purposes. " .
                    $this->company_name . " does not represent warrant or guarantee that the Reports are accurate. " .
                    $this->company_name . " disclaims liability for any direct indirect punitive special consequential " .
                    "or incidental damages related to the Reports or the use of the Reports.";
        
        $this->MultiCell(0, 10, $disclaimer, 0, 'C');
        
        // Page number
        $this->Cell(0, 10, 'Page '.$this->getAliasNumPage().' of '.$this->getAliasNbPages(), 0, false, 'C', 0, '', 0, false, 'T', 'M');
    }
    
    // Asset class header
    public function AssetClassHeader($asset_class) {
        $this->current_asset_class = $asset_class;
        $this->SetFont('times', 'B', 14);
        
        if ($asset_class === 'bond' || $asset_class === 'treasury_bond' || $asset_class === 'corporate_bond') {
            $title = "BONDS BROKERAGE COMMISSION SUMMARY REPORT";
        } elseif ($asset_class === 'etf') {
            $title = "ETF BROKERAGE COMMISSION SUMMARY REPORT";
        } else {
            $title = "EQUITIES BROKERAGE COMMISSION SUMMARY REPORT";
        }
        
        $this->Cell(0, 10, $title, 0, 1, 'C');
    }
    
    // Custom method for rotated text (watermark)
    public function RotatedText($x, $y, $txt, $angle) {
        $this->StartTransform();
        $this->Rotate($angle, $x, $y);
        $this->Text($x, $y, $txt);
        $this->StopTransform();
    }
}

// Commission calculation functions
function calculateBondFees($quantity, $consideration) {
    $brokerage_rate = 0.025;
    $vat_rate = 0.18;
    $cmsa_rate = 0.0001;
    $csdr_rate = 0.000118;
    $dse_rate = 0.0002006;
    
    $brokerage_commission = $quantity * $brokerage_rate / 100;
    $vat_on_brokerage = $brokerage_commission * $vat_rate;
    $cmsa_fee = $consideration * $cmsa_rate;
    $csdr_fee = $quantity * $csdr_rate;
    $dse_fee = $quantity * $dse_rate;
    
    $total_charges = $brokerage_commission + $vat_on_brokerage + $cmsa_fee + $csdr_fee + $dse_fee;
    $net_commission = $brokerage_commission;
    
    return [
        'gross_commission' => $brokerage_commission + $vat_on_brokerage,
        'broker_commission' => $brokerage_commission,
        'total_levies' => $cmsa_fee + $csdr_fee + $dse_fee,
        'dse_levy' => $dse_fee,
        'cmsa_fee' => $cmsa_fee,
        'csd_levy' => $csdr_fee,
        'vat_levy' => $vat_on_brokerage,
        'return_commission' => 0,
        'net_commission' => $net_commission
    ];
}

function calculateEquityFees($quantity, $consideration, $price) {
    $brokerage_rate = 1.7;
    $vat_rate = 0.18;
    $cmsa_rate = 0.0014;
    $dse_rate = 0.001652;
    $fidelity_rate = 0.0002;
    $cds_rate = 0.000708;
    
    $brokerage_commission = $consideration * $brokerage_rate / 100;
    $vat_on_brokerage = $brokerage_commission * $vat_rate;
    $cmsa_fee = $consideration * $cmsa_rate;
    $dse_fee = $consideration * $dse_rate;
    $fidelity_fee = $consideration * $fidelity_rate;
    $cds_fee = $consideration * $cds_rate;
    
    $total_charges = $brokerage_commission + $vat_on_brokerage + $cmsa_fee + $dse_fee + $fidelity_fee + $cds_fee;
    $net_commission = $brokerage_commission;
    
    return [
        'gross_commission' => $brokerage_commission + $vat_on_brokerage,
        'broker_commission' => $brokerage_commission,
        'total_levies' => $cmsa_fee + $dse_fee + $fidelity_fee + $cds_fee,
        'dse_levy' => $dse_fee,
        'cmsa_fee' => $cmsa_fee,
        'csd_levy' => $cds_fee,
        'vat_levy' => $vat_on_brokerage,
        'return_commission' => $fidelity_fee,
        'net_commission' => $net_commission
    ];
}

function calculateETFFees($quantity, $consideration, $price) {
    return calculateEquityFees($quantity, $consideration, $price);
}

$db = getDBConnection();

// Get company details
$company_stmt = $db->query("SELECT 
    company_name, 
    address, 
    phone, 
    email,
    mobile,
    contact_person
FROM companies 
WHERE status = 'active' 
LIMIT 1");

$company = $company_stmt->fetch();
$company_name = $company ? $company['company_name'] : 'NEOVAM Limited';
$company_address = $company ? $company['address'] : '';
$company_phone = $company ? $company['phone'] : '';
$company_email = $company ? $company['email'] : '';

if (empty($company_phone) && !empty($company['mobile'])) {
    $company_phone = $company['mobile'];
}

// Get form data
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
$report_type = $_POST['report_type'] ?? 'detailed';
$report_by = $_POST['report_by'] ?? 'month';
$orientation = $_POST['orientation'] ?? 'landscape';
$watermark = $_POST['watermark'] ?? 'no';
$export_type = $_POST['export_type'] ?? 'pdf';

// Build WHERE conditions
$where_conditions = [];
$params = [];

if (!empty($client)) {
    $where_conditions[] = "client_cds_account = ?";
    $params[] = $client;
}
if (!empty($broker)) {
    $where_conditions[] = "broker_name = ?";
    $params[] = $broker;
}
if (!empty($security)) {
    $where_conditions[] = "security_id = ?";
    $params[] = $security;
}
if (!empty($type)) {
    $where_conditions[] = "trade_side = ?";
    $params[] = $type;
}
if (!empty($status)) {
    $where_conditions[] = "status = ?";
    $params[] = $status;
}
if (!empty($asset_class)) {
    $where_conditions[] = "asset_class = ?";
    $params[] = $asset_class;
}
if (!empty($period_from)) {
    $where_conditions[] = "trade_date >= ?";
    $params[] = $period_from;
}
if (!empty($period_to)) {
    $where_conditions[] = "trade_date <= ?";
    $params[] = $period_to;
}

$where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

// Get grouping
switch ($report_by) {
    case 'day':
        $group_by = "DATE(trade_date)";
        break;
    case 'week':
        $group_by = "YEAR(trade_date), WEEK(trade_date)";
        break;
    case 'month':
        $group_by = "YEAR(trade_date), MONTH(trade_date)";
        break;
    case 'quarter':
        $group_by = "YEAR(trade_date), QUARTER(trade_date)";
        break;
    default:
        $group_by = "YEAR(trade_date), MONTH(trade_date)";
}

// Get individual trades
$individual_sql = "SELECT 
            id,
            asset_class,
            trade_date,
            quantity,
            consideration,
            price,
            $group_by as period_group
        FROM trades 
        $where_clause 
        ORDER BY asset_class, trade_date DESC";

$individual_stmt = $db->prepare($individual_sql);
$individual_stmt->execute($params);
$individual_trades = $individual_stmt->fetchAll();

// Calculate commissions and group data
$grouped_data = [];

foreach ($individual_trades as $trade) {
    $asset_class = $trade['asset_class'];
    $period_group = $trade['period_group'];
    $quantity = floatval($trade['quantity']);
    $consideration = floatval($trade['consideration']);
    $price = floatval($trade['price']);
    
    if ($asset_class === 'bond' || $asset_class === 'treasury_bond' || $asset_class === 'corporate_bond') {
        $fees = calculateBondFees($quantity, $consideration);
    } elseif ($asset_class === 'etf') {
        $fees = calculateETFFees($quantity, $consideration, $price);
    } else {
        $fees = calculateEquityFees($quantity, $consideration, $price);
    }
    
    if (!isset($grouped_data[$asset_class][$period_group])) {
        $grouped_data[$asset_class][$period_group] = [
            'asset_class' => $asset_class,
            'period_group' => $period_group,
            'trade_count' => 0,
            'total_quantity' => 0,
            'total_consideration' => 0,
            'total_gross_commission' => 0,
            'total_broker_commission' => 0,
            'total_levies' => 0,
            'total_dse_levy' => 0,
            'total_cmsa_fee' => 0,
            'total_csd_levy' => 0,
            'total_vat_levy' => 0,
            'total_return_commission' => 0,
            'total_net_commission' => 0,
            'period_start' => $trade['trade_date'],
            'period_end' => $trade['trade_date']
        ];
    }
    
    $grouped_data[$asset_class][$period_group]['trade_count']++;
    $grouped_data[$asset_class][$period_group]['total_quantity'] += $quantity;
    $grouped_data[$asset_class][$period_group]['total_consideration'] += $consideration;
    $grouped_data[$asset_class][$period_group]['total_gross_commission'] += $fees['gross_commission'];
    $grouped_data[$asset_class][$period_group]['total_broker_commission'] += $fees['broker_commission'];
    $grouped_data[$asset_class][$period_group]['total_levies'] += $fees['total_levies'];
    $grouped_data[$asset_class][$period_group]['total_dse_levy'] += $fees['dse_levy'];
    $grouped_data[$asset_class][$period_group]['total_cmsa_fee'] += $fees['cmsa_fee'];
    $grouped_data[$asset_class][$period_group]['total_csd_levy'] += $fees['csd_levy'];
    $grouped_data[$asset_class][$period_group]['total_vat_levy'] += $fees['vat_levy'];
    $grouped_data[$asset_class][$period_group]['total_return_commission'] += $fees['return_commission'];
    $grouped_data[$asset_class][$period_group]['total_net_commission'] += $fees['net_commission'];
    
    if ($trade['trade_date'] < $grouped_data[$asset_class][$period_group]['period_start']) {
        $grouped_data[$asset_class][$period_group]['period_start'] = $trade['trade_date'];
    }
    if ($trade['trade_date'] > $grouped_data[$asset_class][$period_group]['period_end']) {
        $grouped_data[$asset_class][$period_group]['period_end'] = $trade['trade_date'];
    }
}

// Organize by asset class
$equities_data = [];
$etf_data = [];
$bonds_data = [];

foreach ($grouped_data as $asset_class => $periods) {
    foreach ($periods as $period_data) {
        if ($asset_class === 'bond' || $asset_class === 'treasury_bond' || $asset_class === 'corporate_bond') {
            $bonds_data[] = $period_data;
        } elseif ($asset_class === 'etf') {
            $etf_data[] = $period_data;
        } else {
            $equities_data[] = $period_data;
        }
    }
}

// Sort by period start date
usort($equities_data, function($a, $b) {
    return strtotime($b['period_start']) - strtotime($a['period_start']);
});

usort($etf_data, function($a, $b) {
    return strtotime($b['period_start']) - strtotime($a['period_start']);
});

usort($bonds_data, function($a, $b) {
    return strtotime($b['period_start']) - strtotime($a['period_start']);
});

// Format period label
function formatPeriodLabel($report_by, $trade) {
    $start_date = $trade['period_start'];
    $end_date = $trade['period_end'];
    
    switch ($report_by) {
        case 'day':
            return date('d/m/Y', strtotime($start_date));
        case 'week':
            $week_start = date('d/m/Y', strtotime($start_date));
            $week_end = date('d/m/Y', strtotime($end_date));
            return "W" . date('W', strtotime($start_date)) . " ($week_start - $week_end)";
        case 'month':
            return date('M Y', strtotime($start_date));
        case 'quarter':
            $quarter = ceil(date('n', strtotime($start_date)) / 3);
            return "Q" . $quarter . " " . date('Y', strtotime($start_date));
        default:
            return date('M Y', strtotime($start_date));
    }
}

// Create PDF with landscape orientation
$pdf = new CommissionPDF('L', PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
$pdf->setCompanyInfo($company_name, $company_address, $company_phone, $company_email);

// Set document information
$pdf->SetCreator(PDF_CREATOR);
$pdf->SetAuthor($company_name);
$pdf->SetTitle('Commission Summary Report');
$pdf->SetSubject('Commission Report');
$pdf->SetKeywords('Commission, Report, Financial');

// Set Times New Roman as default font
$pdf->SetFont('times', '', 10);

// Set header and footer fonts
$pdf->setHeaderFont(array('times', '', 10));
$pdf->setFooterFont(array('times', '', 8));

// Set default monospaced font
$pdf->SetDefaultMonospacedFont(PDF_FONT_MONOSPACED);

// Set margins for landscape
$pdf->SetMargins(10, 45, 10);
$pdf->SetHeaderMargin(10);
$pdf->SetFooterMargin(10);

// Set auto page breaks
$pdf->SetAutoPageBreak(TRUE, 15);

// Set image scale factor
$pdf->setImageScale(PDF_IMAGE_SCALE_RATIO);

// Function to generate commission table with centered alignment and combined months
function generateCommissionTable($pdf, $data, $report_by, $asset_class_type) {
    $pdf->AssetClassHeader($asset_class_type);
    
    // Report period
    $pdf->SetFont('times', 'B', 10);
    $pdf->Cell(0, 7, 'Grouped by: ' . ucfirst($report_by), 0, 1, 'C');
    
    // Report date
    $pdf->SetFont('times', '', 9);
    $pdf->Cell(0, 5, 'Report Date: ' . date('d/m/Y H:i:s'), 0, 1, 'C');
    $pdf->Ln(2);

    // Define headers based on asset class
    if ($asset_class_type === 'bond' || $asset_class_type === 'treasury_bond' || $asset_class_type === 'corporate_bond') {
        $headers = array(
            '#', 
            'PERIOD', 
            'QUANTITY', 
            'CONSIDERATION', 
            'GROSS COMMISSION', 
            'BROKER COMMISSION', 
            'TOTAL LEVIES', 
            'DSE TXN.LEVY', 
            'CMSA CMP.FUND', 
            'CSD TXN.LEVY', 
            'VAT TXN.LEVY', 
            'RETURN COMMISSION', 
            'NET COMMISSION'
        );
    } else {
        $headers = array(
            '#', 
            'PERIOD', 
            'QUANTITY', 
            'CONSIDERATION', 
            'GROSS COMMISSION', 
            'BROKER COMMISSION', 
            'TOTAL LEVIES', 
            'DSE TXN.LEVY', 
            'CMSA CMP.FUND', 
            'CSD TXN.LEVY', 
            'VAT TXN.LEVY', 
            'FIDELITY TXN.LEVY', 
            'NET COMMISSION'
        );
    }

    // Column widths
    $col_widths = array(
        8,    // #
        25,   // PERIOD
        20,   // QUANTITY
        25,   // CONSIDERATION
        22,   // GROSS COMMISSION
        22,   // BROKER COMMISSION
        20,   // TOTAL LEVIES
        18,   // DSE TXN.LEVY
        20,   // CMSA CMP.FUND
        18,   // CSD TXN.LEVY
        18,   // VAT TXN.LEVY
        22,   // FIDELITY/RETURN COMMISSION
        22    // NET COMMISSION
    );
    
    // Calculate total table width
    $total_table_width = array_sum($col_widths);
    
    // Calculate starting X position to center the table
    $page_width = 297; // A4 landscape width in mm
    $margin_left = $pdf->GetX();
    $margin_right = $page_width - $margin_left;
    $available_width = $page_width - ($margin_left * 2);
    
    // If table is wider than available space, we can't center it perfectly
    if ($total_table_width < $available_width) {
        $start_x = ($available_width - $total_table_width) / 2 + $margin_left;
        $pdf->SetX($start_x);
    }

    // Header with styling
    $pdf->SetFillColor(220, 220, 220);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetFont('times', 'B', 8);
    $pdf->SetLineWidth(0.2);
    
    foreach ($headers as $i => $col) {
        $pdf->Cell($col_widths[$i], 8, $col, 1, 0, 'C', 1);
    }
    $pdf->Ln();

    // Table data
    $pdf->SetFont('times', '', 7);
    $pdf->SetTextColor(0, 0, 0);
    $counter = 1;
    $totals = array_fill(0, count($headers) - 2, 0);
    
    // Group data by period to combine duplicate months
    $grouped_periods = array();
    foreach ($data as $trade) {
        $period_label = formatPeriodLabel($report_by, $trade);
        
        if (!isset($grouped_periods[$period_label])) {
            $grouped_periods[$period_label] = array(
                'period_label' => $period_label,
                'total_quantity' => 0,
                'total_consideration' => 0,
                'total_gross_commission' => 0,
                'total_broker_commission' => 0,
                'total_levies' => 0,
                'total_dse_levy' => 0,
                'total_cmsa_fee' => 0,
                'total_csd_levy' => 0,
                'total_vat_levy' => 0,
                'total_return_commission' => 0,
                'total_net_commission' => 0
            );
        }
        
        // Sum all values for this period
        $grouped_periods[$period_label]['total_quantity'] += $trade['total_quantity'];
        $grouped_periods[$period_label]['total_consideration'] += $trade['total_consideration'];
        $grouped_periods[$period_label]['total_gross_commission'] += $trade['total_gross_commission'];
        $grouped_periods[$period_label]['total_broker_commission'] += $trade['total_broker_commission'];
        $grouped_periods[$period_label]['total_levies'] += $trade['total_levies'];
        $grouped_periods[$period_label]['total_dse_levy'] += $trade['total_dse_levy'];
        $grouped_periods[$period_label]['total_cmsa_fee'] += $trade['total_cmsa_fee'];
        $grouped_periods[$period_label]['total_csd_levy'] += $trade['total_csd_levy'];
        $grouped_periods[$period_label]['total_vat_levy'] += $trade['total_vat_levy'];
        $grouped_periods[$period_label]['total_return_commission'] += $trade['total_return_commission'];
        $grouped_periods[$period_label]['total_net_commission'] += $trade['total_net_commission'];
    }

    // Sort grouped periods by date (most recent first)
    uksort($grouped_periods, function($a, $b) {
        // Extract month and year from period labels
        $a_parts = explode(' ', $a);
        $b_parts = explode(' ', $b);
        
        $a_month = $a_parts[0];
        $a_year = $a_parts[1] ?? date('Y');
        $b_month = $b_parts[0];
        $b_year = $b_parts[1] ?? date('Y');
        
        // Convert month names to numbers
        $a_time = strtotime("1 $a_month $a_year");
        $b_time = strtotime("1 $b_month $b_year");
        
        return $b_time - $a_time; // Descending order
    });

    // Display grouped data
    foreach ($grouped_periods as $period_label => $period_data) {
        // Check if we need a new page
        if ($pdf->GetY() > 185) {
            $pdf->AddPage('L');
            $pdf->AssetClassHeader($asset_class_type);
            
            // Reset X position for centering on new page
            if ($total_table_width < $available_width) {
                $start_x = ($available_width - $total_table_width) / 2 + $margin_left;
                $pdf->SetX($start_x);
            }
            
            // Re-add header
            $pdf->SetFont('times', 'B', 8);
            $pdf->SetFillColor(220, 220, 220);
            foreach ($headers as $i => $col) {
                $pdf->Cell($col_widths[$i], 8, $col, 1, 0, 'C', 1);
            }
            $pdf->Ln();
            $pdf->SetFont('times', '', 7);
        }
        
        // Reset X position for each row
        if ($total_table_width < $available_width) {
            $pdf->SetX($start_x);
        }
        
        // Alternate row colors
        $fill_color = ($counter % 2 == 0) ? array(245, 245, 245) : array(255, 255, 255);
        $pdf->SetFillColor($fill_color[0], $fill_color[1], $fill_color[2]);
        
        // Row data
        $pdf->Cell($col_widths[0], 7, $counter, 1, 0, 'C', 1);
        $pdf->Cell($col_widths[1], 7, $period_label, 1, 0, 'L', 1);
        $pdf->Cell($col_widths[2], 7, number_format($period_data['total_quantity'], 0), 1, 0, 'R', 1);
        $pdf->Cell($col_widths[3], 7, number_format($period_data['total_consideration'], 2), 1, 0, 'R', 1);
        $pdf->Cell($col_widths[4], 7, number_format($period_data['total_gross_commission'], 2), 1, 0, 'R', 1);
        $pdf->Cell($col_widths[5], 7, number_format($period_data['total_broker_commission'], 2), 1, 0, 'R', 1);
        $pdf->Cell($col_widths[6], 7, number_format($period_data['total_levies'], 2), 1, 0, 'R', 1);
        $pdf->Cell($col_widths[7], 7, number_format($period_data['total_dse_levy'], 2), 1, 0, 'R', 1);
        $pdf->Cell($col_widths[8], 7, number_format($period_data['total_cmsa_fee'], 2), 1, 0, 'R', 1);
        $pdf->Cell($col_widths[9], 7, number_format($period_data['total_csd_levy'], 2), 1, 0, 'R', 1);
        $pdf->Cell($col_widths[10], 7, number_format($period_data['total_vat_levy'], 2), 1, 0, 'R', 1);
        $pdf->Cell($col_widths[11], 7, number_format($period_data['total_return_commission'], 2), 1, 0, 'R', 1);
        $pdf->Cell($col_widths[12], 7, number_format($period_data['total_net_commission'], 2), 1, 0, 'R', 1);
        $pdf->Ln();
        
        // Accumulate totals
        $totals[0] += $period_data['total_quantity'];
        $totals[1] += $period_data['total_consideration'];
        $totals[2] += $period_data['total_gross_commission'];
        $totals[3] += $period_data['total_broker_commission'];
        $totals[4] += $period_data['total_levies'];
        $totals[5] += $period_data['total_dse_levy'];
        $totals[6] += $period_data['total_cmsa_fee'];
        $totals[7] += $period_data['total_csd_levy'];
        $totals[8] += $period_data['total_vat_levy'];
        $totals[9] += $period_data['total_return_commission'];
        $totals[10] += $period_data['total_net_commission'];
        
        $counter++;
    }

    // Total row
    if ($total_table_width < $available_width) {
        $pdf->SetX($start_x);
    }
    
    $pdf->SetFont('times', 'B', 8);
    $pdf->SetFillColor(200, 200, 200);
    $pdf->SetTextColor(0, 0, 0);
    
    $pdf->Cell($col_widths[0] + $col_widths[1], 8, 'TOTAL', 1, 0, 'C', 1);
    $pdf->Cell($col_widths[2], 8, number_format($totals[0], 0), 1, 0, 'R', 1);
    $pdf->Cell($col_widths[3], 8, number_format($totals[1], 2), 1, 0, 'R', 1);
    $pdf->Cell($col_widths[4], 8, number_format($totals[2], 2), 1, 0, 'R', 1);
    $pdf->Cell($col_widths[5], 8, number_format($totals[3], 2), 1, 0, 'R', 1);
    $pdf->Cell($col_widths[6], 8, number_format($totals[4], 2), 1, 0, 'R', 1);
    $pdf->Cell($col_widths[7], 8, number_format($totals[5], 2), 1, 0, 'R', 1);
    $pdf->Cell($col_widths[8], 8, number_format($totals[6], 2), 1, 0, 'R', 1);
    $pdf->Cell($col_widths[9], 8, number_format($totals[7], 2), 1, 0, 'R', 1);
    $pdf->Cell($col_widths[10], 8, number_format($totals[8], 2), 1, 0, 'R', 1);
    $pdf->Cell($col_widths[11], 8, number_format($totals[9], 2), 1, 0, 'R', 1);
    $pdf->Cell($col_widths[12], 8, number_format($totals[10], 2), 1, 0, 'R', 1);
    
    $pdf->Ln(10);
    
    return $totals;
}

// Generate reports for each asset class
if (!empty($equities_data)) {
    $pdf->AddPage('L');
    if ($watermark === 'yes') {
        $pdf->SetFont('times', 'B', 50);
        $pdf->SetTextColor(240, 240, 240);
        $pdf->RotatedText(150, 100, $company_name, 45);
        $pdf->SetTextColor(0, 0, 0);
    }
    $equities_totals = generateCommissionTable($pdf, $equities_data, $report_by, 'equity');
}

if (!empty($etf_data)) {
    $pdf->AddPage('L');
    if ($watermark === 'yes') {
        $pdf->SetFont('times', 'B', 50);
        $pdf->SetTextColor(240, 240, 240);
        $pdf->RotatedText(150, 100, $company_name, 45);
        $pdf->SetTextColor(0, 0, 0);
    }
    $etf_totals = generateCommissionTable($pdf, $etf_data, $report_by, 'etf');
}

if (!empty($bonds_data)) {
    $pdf->AddPage('L');
    if ($watermark === 'yes') {
        $pdf->SetFont('times', 'B', 50);
        $pdf->SetTextColor(240, 240, 240);
        $pdf->RotatedText(150, 100, $company_name, 45);
        $pdf->SetTextColor(0, 0, 0);
    }
    $bonds_totals = generateCommissionTable($pdf, $bonds_data, $report_by, 'bond');
}

// Add summary page if multiple asset classes exist
$asset_class_count = (!empty($equities_data) ? 1 : 0) + (!empty($etf_data) ? 1 : 0) + (!empty($bonds_data) ? 1 : 0);
if ($asset_class_count > 1) {
    $pdf->AddPage('L');
    $pdf->SetFont('times', 'B', 16);
    $pdf->Cell(0, 10, 'COMMISSION SUMMARY REPORT', 0, 1, 'C');
    $pdf->Ln(10);
    
    $pdf->SetFont('times', 'B', 12);
    $pdf->Cell(0, 8, 'GRAND TOTALS', 0, 1, 'C');
    $pdf->Ln(5);
    
    $pdf->SetFont('times', '', 10);
    
    // Initialize grand totals
    $grand_quantity = 0;
    $grand_consideration = 0;
    $grand_gross_commission = 0;
    $grand_broker_commission = 0;
    $grand_net_commission = 0;
    $grand_levies = 0;
    
    if (!empty($equities_data)) {
        $grand_quantity += $equities_totals[0];
        $grand_consideration += $equities_totals[1];
        $grand_gross_commission += $equities_totals[2];
        $grand_broker_commission += $equities_totals[3];
        $grand_net_commission += $equities_totals[10];
        $grand_levies += $equities_totals[4];
    }
    
    if (!empty($etf_data)) {
        $grand_quantity += $etf_totals[0];
        $grand_consideration += $etf_totals[1];
        $grand_gross_commission += $etf_totals[2];
        $grand_broker_commission += $etf_totals[3];
        $grand_net_commission += $etf_totals[10];
        $grand_levies += $etf_totals[4];
    }
    
    if (!empty($bonds_data)) {
        $grand_quantity += $bonds_totals[0];
        $grand_consideration += $bonds_totals[1];
        $grand_gross_commission += $bonds_totals[2];
        $grand_broker_commission += $bonds_totals[3];
        $grand_net_commission += $bonds_totals[10];
        $grand_levies += $bonds_totals[4];
    }
    
    // Create summary table
    $pdf->SetFont('times', 'B', 10);
    $pdf->SetFillColor(240, 240, 240);
    $pdf->Cell(80, 8, 'Description', 1, 0, 'C', 1);
    $pdf->Cell(50, 8, 'Value', 1, 1, 'C', 1);
    
    $pdf->SetFont('times', '', 10);
    $summary_data = [
        'Total Quantity' => number_format($grand_quantity, 0),
        'Total Consideration' => 'TZS ' . number_format($grand_consideration, 2),
        'Total Gross Commission' => 'TZS ' . number_format($grand_gross_commission, 2),
        'Total Broker Commission' => 'TZS ' . number_format($grand_broker_commission, 2),
        'Total Net Commission' => 'TZS ' . number_format($grand_net_commission, 2),
        'Total Levies & Fees' => 'TZS ' . number_format($grand_levies, 2)
    ];
    
    $fill = false;
    foreach ($summary_data as $label => $value) {
        $pdf->SetFillColor($fill ? 245 : 255, $fill ? 245 : 255, $fill ? 245 : 255);
        $pdf->Cell(80, 8, $label, 1, 0, 'L', 1);
        $pdf->SetFont('times', 'B', 10);
        $pdf->Cell(50, 8, $value, 1, 1, 'R', 1);
        $pdf->SetFont('times', '', 10);
        $fill = !$fill;
    }
    
    $pdf->Ln(10);
    $pdf->SetFont('times', 'I', 9);
    $pdf->MultiCell(0, 5, 'Note: This summary combines all asset class data from the previous pages.', 0, 'C');
}

// Output PDF
$filename = 'commission_report_' . date('Ymd_His') . '.pdf';
if ($export_type === 'download') {
    $pdf->Output($filename, 'D');
} else {
    $pdf->Output($filename, 'I');
}
?>