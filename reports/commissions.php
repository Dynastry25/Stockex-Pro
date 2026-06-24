<?php
require_once '../config/config.php';
require_once '../auth/auth_middleware.php';
require_once '../tcpdf/tcpdf.php';
require_once __DIR__ . '/traits/ReportHeaderTrait.php';

require_login();

class CommissionPDF extends TCPDF {
    use ReportHeaderTrait;
    
    private $current_asset_class = '';
    private $company_name = '';
    private $company_address = '';
    private $company_phone = '';
    private $company_email = '';
    private $db = null;
    public $watermark = 'no';
    
    public function setCompanyInfo($name, $address, $phone, $email) {
        $this->company_name = $name;
        $this->company_address = $address;
        $this->company_phone = $phone;
        $this->company_email = $email;
    }
    
    public function setDatabase($db) {
        $this->db = $db;
    }
    
    public function setWatermark($watermark) {
        $this->watermark = $watermark;
    }
    
    public function Header() {
        $this->renderReportHeader();
        $this->SetY($this->GetY() + 16);
    }

    public function Footer() {
        $this->SetY(-15);
        $this->SetFont('helvetica', 'I', 6);
        
        $disclaimer = $this->company_name . " - Confidential. This report is for internal use only.";
        $this->MultiCell(0, 3, $disclaimer, 0, 'C');
        $this->Cell(0, 5, 'Page ' . $this->getAliasNumPage() . ' of ' . $this->getAliasNbPages(), 0, false, 'C');
    }
    
    public function AssetClassHeader($asset_class) {
        $this->current_asset_class = $asset_class;
        $this->SetFont('helvetica', 'B', 12);
        
        if ($asset_class === 'bond') {
            $title = "BONDS BROKERAGE COMMISSION REPORT";
        } elseif ($asset_class === 'etf' || $asset_class === 'Exchange Traded Funds') {
            $title = "ETF BROKERAGE COMMISSION REPORT";
        } else {
            $title = "EQUITIES BROKERAGE COMMISSION REPORT";
        }
        
        $this->Cell(0, 8, $title, 0, 1, 'C');
        $this->Ln(2);
    }
    
    public function RotatedText($x, $y, $txt, $angle) {
        $this->StartTransform();
        $this->Rotate($angle, $x, $y);
        $this->Text($x, $y, $txt);
        $this->StopTransform();
    }
}

// Function to get fee configuration from database
function getFeeConfiguration($db, $fee_type, $applies_to = 'ALL') {
    $stmt = $db->prepare("
        SELECT rate_percentage, fixed_amount, calculation_base, fee_name 
        FROM fee_configurations 
        WHERE fee_type = ? AND applies_to = ? AND is_active = 1
        ORDER BY applies_to DESC
        LIMIT 1
    ");
    $stmt->execute([$fee_type, $applies_to]);
    $config = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$config && $applies_to !== 'ALL') {
        $stmt->execute([$fee_type, 'ALL']);
        $config = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    return $config;
}

// Function to get standard brokerage rate for asset class
function getStandardBrokerageRate($db, $asset_class) {
    $asset_class_upper = strtoupper($asset_class);
    if ($asset_class_upper === 'EXCHANGE TRADED FUNDS') {
        $asset_class_upper = 'ETF';
    }
    
    $stmt = $db->prepare("
        SELECT rate_percentage FROM fee_configurations
        WHERE fee_type = 'BROKERAGE' AND applies_to = ? AND is_active = 1
    ");
    $stmt->execute([$asset_class_upper]);
    $rate = $stmt->fetchColumn();
    
    if (!$rate) {
        $stmt = $db->prepare("
            SELECT rate_percentage FROM fee_configurations
            WHERE fee_type = 'BROKERAGE' AND applies_to = 'ALL' AND is_active = 1
        ");
        $stmt->execute();
        $rate = $stmt->fetchColumn();
    }
    
    return $rate ?: 1.7;
}

// IMPROVED: Function to get client's effective rate with liberty mode
function getClientEffectiveRate($db, $client_cds_account, $asset_class) {
    $stmt = $db->prepare("
        SELECT default_brokerage_fee, fee_type, liberty_mode 
        FROM clients 
        WHERE cds_account = ? AND is_active = 1
        LIMIT 1
    ");
    $stmt->execute([$client_cds_account]);
    $client = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($client && $client['fee_type'] === 'liberty' && $client['default_brokerage_fee'] !== null && $client['default_brokerage_fee'] > 0) {
        // Cap liberty rate at 5% max to prevent errors
        $rate = floatval($client['default_brokerage_fee']);
        if ($rate > 5) {
            error_log("Liberty rate {$rate}% exceeds 5% cap. Using standard rate.");
            $rate = getStandardBrokerageRate($db, $asset_class);
            return [
                'rate' => $rate,
                'type' => 'standard',
                'is_liberty' => false,
                'liberty_mode' => 'replace_all'
            ];
        }
        return [
            'rate' => $rate,
            'type' => 'liberty',
            'is_liberty' => true,
            'liberty_mode' => $client['liberty_mode'] ?? 'replace_all'
        ];
    }
    
    return [
        'rate' => getStandardBrokerageRate($db, $asset_class),
        'type' => 'standard',
        'is_liberty' => false,
        'liberty_mode' => 'replace_all'
    ];
}

// ============ BOND COMMISSION (with Liberty Support) ============
function calculateBondCommission($face_value, $rate, $is_liberty = false, $liberty_mode = 'replace_all') {
    if ($is_liberty && $rate > 0) {
        if ($liberty_mode === 'excess_only') {
            $standard_first_100m = min($face_value, 100000000) * (0.063132 / 100);
            $liberty_excess = max($face_value - 100000000, 0) * ($rate / 100);
            return $standard_first_100m + $liberty_excess;
        } else {
            return $face_value * ($rate / 100);
        }
    }
    
    $brokerage_first_100m = min($face_value, 100000000) * (0.063132 / 100);
    $brokerage_excess = max($face_value - 100000000, 0) * (0.035 / 100);
    return $brokerage_first_100m + $brokerage_excess;
}

// ============ EQUITY COMMISSION (with Liberty Support) ============
function calculateEquityCommission($consideration, $rate, $is_liberty = false, $liberty_mode = 'replace_all') {
    if ($is_liberty && $rate > 0) {
        if ($liberty_mode === 'tier_override') {
            $liberty_rate_decimal = $rate / 100;
            
            if ($consideration <= 10000000) {
                return $consideration * (1.7 / 100);
            } elseif ($consideration <= 40000000) {
                $first_tier = 10000000 * (1.7 / 100);
                $excess = ($consideration - 10000000) * $liberty_rate_decimal;
                return $first_tier + $excess;
            } else {
                $first_tier = 10000000 * (1.7 / 100);
                $second_tier = 30000000 * (1.5 / 100);
                $excess = ($consideration - 40000000) * $liberty_rate_decimal;
                return $first_tier + $second_tier + $excess;
            }
        } else {
            return $consideration * ($rate / 100);
        }
    }
    
    $tier_details = [];
    return calculateTieredBrokerage($consideration, $tier_details);
}

// ============ EQUITY TIERED BROKERAGE (Standard) ============
function calculateTieredBrokerage($consideration, &$tier_details = []) {
    $tier_details = [];
    
    $tier1_rate = 1.7 / 100;
    $tier1_limit = 10000000;
    $tier2_rate = 1.5 / 100;
    $tier2_limit = 40000000;
    $tier3_rate = 0.8 / 100;
    
    if ($consideration <= $tier1_limit) {
        $brokerage = $consideration * $tier1_rate;
        $tier_details[] = [
            'amount' => $consideration,
            'rate' => 1.7,
            'fee' => $brokerage,
            'label' => 'Up to 10M @ 1.7%'
        ];
        return $brokerage;
        
    } elseif ($consideration <= $tier2_limit) {
        $tier1_fee = $tier1_limit * $tier1_rate;
        $tier2_amount = $consideration - $tier1_limit;
        $tier2_fee = $tier2_amount * $tier2_rate;
        
        $tier_details[] = [
            'amount' => $tier1_limit,
            'rate' => 1.7,
            'fee' => $tier1_fee,
            'label' => 'First 10M @ 1.7%'
        ];
        $tier_details[] = [
            'amount' => $tier2_amount,
            'rate' => 1.5,
            'fee' => $tier2_fee,
            'label' => 'Next ' . number_format($tier2_amount/1000000, 1) . 'M @ 1.5%'
        ];
        return $tier1_fee + $tier2_fee;
        
    } else {
        $tier1_fee = $tier1_limit * $tier1_rate;
        $tier2_amount = $tier2_limit - $tier1_limit;
        $tier2_fee = $tier2_amount * $tier2_rate;
        $tier3_amount = $consideration - $tier2_limit;
        $tier3_fee = $tier3_amount * $tier3_rate;
        
        $tier_details[] = [
            'amount' => $tier1_limit,
            'rate' => 1.7,
            'fee' => $tier1_fee,
            'label' => 'First 10M @ 1.7%'
        ];
        $tier_details[] = [
            'amount' => $tier2_amount,
            'rate' => 1.5,
            'fee' => $tier2_fee,
            'label' => 'Next 30M @ 1.5%'
        ];
        $tier_details[] = [
            'amount' => $tier3_amount,
            'rate' => 0.8,
            'fee' => $tier3_fee,
            'label' => 'Excess ' . number_format($tier3_amount/1000000, 1) . 'M @ 0.8%'
        ];
        return $tier1_fee + $tier2_fee + $tier3_fee;
    }
}

// Function to get correct bond consideration
function getCorrectBondConsideration($trade) {
    // Always use the stored consideration for bonds too
    $stored_consideration = floatval($trade['consideration']);
    return $stored_consideration > 0 ? $stored_consideration : (floatval($trade['quantity']) * floatval($trade['price']) / 100);
}

// ============ MAIN FEE CALCULATION ============
function calculateFeesFromTrade($db, $trade) {
    $fees = [];
    $is_bond = ($trade['asset_class'] === 'bond');
    
    // ALWAYS use the stored consideration from the trade
    $consideration = floatval($trade['consideration']);
    $quantity = floatval($trade['quantity']);
    $price = floatval($trade['price']);
    
    // For bonds, if consideration is 0, calculate it
    if ($is_bond && $consideration == 0) {
        $consideration = $quantity * $price / 100;
    }
    
    $client_rate_info = getClientEffectiveRate($db, $trade['client_cds_account'], $trade['asset_class']);
    $effective_rate = $client_rate_info['rate'];
    $is_liberty = $client_rate_info['is_liberty'];
    $liberty_mode = $client_rate_info['liberty_mode'];
    
    if ($is_bond) {
        $face_value = $quantity;
        $brokerage_commission = calculateBondCommission($face_value, $effective_rate, $is_liberty, $liberty_mode);
        $fees['tier_details'] = [];
        
        if ($is_liberty) {
            if ($liberty_mode === 'excess_only') {
                $fees['tier_details'][] = [
                    'amount' => min($face_value, 100000000),
                    'rate' => 0.063132,
                    'fee' => min($face_value, 100000000) * (0.063132 / 100),
                    'label' => 'First 100M @ 0.063132%'
                ];
                if ($face_value > 100000000) {
                    $fees['tier_details'][] = [
                        'amount' => $face_value - 100000000,
                        'rate' => $effective_rate,
                        'fee' => ($face_value - 100000000) * ($effective_rate / 100),
                        'label' => 'Excess @ ' . number_format($effective_rate, 4) . '% (Liberty)'
                    ];
                }
            } else {
                $fees['tier_details'][] = [
                    'amount' => $face_value,
                    'rate' => $effective_rate,
                    'fee' => $brokerage_commission,
                    'label' => 'Full Face Value @ ' . number_format($effective_rate, 4) . '% (Liberty)'
                ];
            }
        } else {
            if ($face_value <= 100000000) {
                $fees['tier_details'][] = [
                    'amount' => $face_value,
                    'rate' => 0.063132,
                    'fee' => $brokerage_commission,
                    'label' => 'First ' . number_format($face_value/1000000, 2) . 'M @ 0.063132%'
                ];
            } else {
                $fees['tier_details'][] = [
                    'amount' => 100000000,
                    'rate' => 0.063132,
                    'fee' => 100000000 * (0.063132 / 100),
                    'label' => 'First 100M @ 0.063132%'
                ];
                $fees['tier_details'][] = [
                    'amount' => $face_value - 100000000,
                    'rate' => 0.035,
                    'fee' => ($face_value - 100000000) * (0.035 / 100),
                    'label' => 'Excess @ 0.035%'
                ];
            }
        }
        
    } else {
        $brokerage_commission = calculateEquityCommission($consideration, $effective_rate, $is_liberty, $liberty_mode);
        
        if (!$is_liberty || ($is_liberty && $liberty_mode === 'tier_override')) {
            $temp_details = [];
            calculateTieredBrokerage($consideration, $temp_details);
            $fees['tier_details'] = $temp_details;
        } else {
            $fees['tier_details'] = [[
                'amount' => $consideration,
                'rate' => $effective_rate,
                'fee' => $brokerage_commission,
                'label' => 'Full Consideration @ ' . number_format($effective_rate, 4) . '% (Liberty)'
            ]];
        }
    }
    
    $vat_config = getFeeConfiguration($db, 'VAT', 'ALL');
    $vat_rate = $vat_config['rate_percentage'] ?? 18.0000;
    $vat_on_brokerage = $brokerage_commission * ($vat_rate / 100);
    
    if ($is_bond) {
        $cmsa_rate = 0.0100;
        $cmsa_fee = $consideration * ($cmsa_rate / 100);
        
        $cds_rate = 0.0118;
        $cds_fee = $quantity * ($cds_rate / 100);
        
        $dse_rate = 0.02006;
        $dse_fee = $quantity * ($dse_rate / 100);
        
        $fidelity_fee = 0;
        
        $fees['effective_rate'] = $effective_rate;
        $fees['is_liberty'] = $is_liberty;
        $fees['liberty_mode'] = $liberty_mode;
        $fees['gross_commission'] = $brokerage_commission + $vat_on_brokerage;
        $fees['broker_commission'] = $brokerage_commission;
        $fees['total_levies'] = $cmsa_fee + $cds_fee + $dse_fee;
        $fees['dse_levy'] = $dse_fee;
        $fees['cmsa_fee'] = $cmsa_fee;
        $fees['csd_levy'] = $cds_fee;
        $fees['vat_levy'] = $vat_on_brokerage;
        $fees['return_commission'] = $fidelity_fee;
        $fees['net_commission'] = $brokerage_commission;
        
    } else {
        // Equity fees - all calculated on consideration
        $cmsa_rate = 0.1400;
        $cmsa_fee = $consideration * ($cmsa_rate / 100);
        
        $dse_rate = 0.1652;
        $dse_fee = $consideration * ($dse_rate / 100);
        
        $fidelity_rate = 0.0200;
        $fidelity_fee = $consideration * ($fidelity_rate / 100);
        
        $cds_rate = 0.0708;
        $cds_fee = $consideration * ($cds_rate / 100);
        
        $fees['effective_rate'] = $effective_rate;
        $fees['is_liberty'] = $is_liberty;
        $fees['liberty_mode'] = $liberty_mode;
        $fees['gross_commission'] = $brokerage_commission + $vat_on_brokerage;
        $fees['broker_commission'] = $brokerage_commission;
        $fees['total_levies'] = $cmsa_fee + $dse_fee + $fidelity_fee + $cds_fee;
        $fees['dse_levy'] = $dse_fee;
        $fees['cmsa_fee'] = $cmsa_fee;
        $fees['csd_levy'] = $cds_fee;
        $fees['vat_levy'] = $vat_on_brokerage;
        $fees['return_commission'] = $fidelity_fee;
        $fees['net_commission'] = $brokerage_commission;
    }
    
    $fees['total_charges'] = $fees['broker_commission'] + $fees['vat_levy'] + $fees['cmsa_fee'] + $fees['dse_levy'] + $fees['return_commission'] + $fees['csd_levy'];
    
    return $fees;
}

// Start output buffering
ob_start();

$db = getDBConnection();

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

// Get individual trades
$individual_sql = "SELECT 
            t.id,
            t.asset_class,
            t.trade_date,
            t.quantity,
            t.consideration,
            t.price,
            t.trade_reference,
            t.client_name,
            t.client_cds_account,
            t.security_id,
            t.trade_side,
            t.brokerage_fee_type,
            t.custom_brokerage_fee,
            t.final_brokerage_fee,
            t.liberty_mode as trade_liberty_mode
        FROM trades t
        $where_clause 
        ORDER BY t.asset_class, t.trade_date DESC";

$individual_stmt = $db->prepare($individual_sql);
$individual_stmt->execute($params);
$individual_trades = $individual_stmt->fetchAll();

// Group data by period
$grouped_data = [];

foreach ($individual_trades as $trade) {
    $asset_class_val = $trade['asset_class'];
    $fees = calculateFeesFromTrade($db, $trade);
    
    $trade_date = $trade['trade_date'];
    switch ($report_by) {
        case 'day':
            $period_group = date('Y-m-d', strtotime($trade_date));
            break;
        case 'week':
            $period_group = date('Y-\WW', strtotime($trade_date));
            break;
        case 'month':
            $period_group = date('Y-m', strtotime($trade_date));
            break;
        case 'quarter':
            $quarter = ceil(date('n', strtotime($trade_date)) / 3);
            $period_group = date('Y', strtotime($trade_date)) . '-Q' . $quarter;
            break;
        default:
            $period_group = date('Y-m', strtotime($trade_date));
    }
    
    if (!isset($grouped_data[$asset_class_val][$period_group])) {
        $grouped_data[$asset_class_val][$period_group] = [
            'asset_class' => $asset_class_val,
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
            'is_liberty' => $fees['is_liberty'],
            'effective_rate' => $fees['effective_rate'],
            'liberty_mode' => $fees['liberty_mode'],
            'trades' => []
        ];
    }
    
    $grouped_data[$asset_class_val][$period_group]['trade_count']++;
    $grouped_data[$asset_class_val][$period_group]['total_quantity'] += $trade['quantity'];
    // Use the stored consideration directly
    $grouped_data[$asset_class_val][$period_group]['total_consideration'] += floatval($trade['consideration']);
    $grouped_data[$asset_class_val][$period_group]['total_gross_commission'] += $fees['gross_commission'];
    $grouped_data[$asset_class_val][$period_group]['total_broker_commission'] += $fees['broker_commission'];
    $grouped_data[$asset_class_val][$period_group]['total_levies'] += $fees['total_levies'];
    $grouped_data[$asset_class_val][$period_group]['total_dse_levy'] += $fees['dse_levy'];
    $grouped_data[$asset_class_val][$period_group]['total_cmsa_fee'] += $fees['cmsa_fee'];
    $grouped_data[$asset_class_val][$period_group]['total_csd_levy'] += $fees['csd_levy'];
    $grouped_data[$asset_class_val][$period_group]['total_vat_levy'] += $fees['vat_levy'];
    $grouped_data[$asset_class_val][$period_group]['total_return_commission'] += $fees['return_commission'];
    $grouped_data[$asset_class_val][$period_group]['total_net_commission'] += $fees['net_commission'];
    $grouped_data[$asset_class_val][$period_group]['trades'][] = $trade;
}

// Organize by asset class
$equities_data = [];
$etf_data = [];
$bonds_data = [];

foreach ($grouped_data as $asset_class_val => $periods) {
    foreach ($periods as $period_group => $period_data) {
        $asset_lower = strtolower($asset_class_val);
        if ($asset_lower === 'bond' || $asset_lower === 'treasury_bond') {
            $bonds_data[] = $period_data;
        } elseif ($asset_lower === 'etf' || $asset_lower === 'exchange traded funds') {
            $etf_data[] = $period_data;
        } else {
            $equities_data[] = $period_data;
        }
    }
}

// Sort by period
usort($equities_data, function($a, $b) {
    return strcmp($b['period_group'], $a['period_group']);
});
usort($etf_data, function($a, $b) {
    return strcmp($b['period_group'], $a['period_group']);
});
usort($bonds_data, function($a, $b) {
    return strcmp($b['period_group'], $a['period_group']);
});

// Format period label
function formatPeriodLabel($report_by, $period_group) {
    switch ($report_by) {
        case 'day':
            return date('d/m/Y', strtotime($period_group));
        case 'week':
            $year = substr($period_group, 0, 4);
            $week = substr($period_group, 6);
            return "Week $week, $year";
        case 'month':
            return date('M Y', strtotime($period_group . '-01'));
        case 'quarter':
            $year = substr($period_group, 0, 4);
            $quarter = substr($period_group, -1);
            return "Q$quarter $year";
        default:
            return date('M Y', strtotime($period_group . '-01'));
    }
}

// Clear output buffer
if (ob_get_length()) {
    ob_clean();
}

// Create PDF
$pdf = new CommissionPDF('L', 'mm', 'A4', true, 'UTF-8', false);
$pdf->setCompanyInfo($company_name, $company_address, $company_phone, $company_email);
$pdf->setDatabase($db);
$pdf->setWatermark($watermark);
$pdf->SetCreator(PDF_CREATOR);
$pdf->SetAuthor($company_name);
$pdf->SetTitle('Commission Summary Report');
$pdf->SetSubject('Commission Report');
$pdf->SetFont('helvetica', '', 7);
$pdf->setHeaderFont(array('helvetica', '', 8));
$pdf->setFooterFont(array('helvetica', '', 6));
$pdf->SetDefaultMonospacedFont('courier');
$pdf->SetMargins(15, 50, 15);
$pdf->SetHeaderMargin(10);
$pdf->SetFooterMargin(15);
$pdf->SetAutoPageBreak(true, 20);
$pdf->setImageScale(PDF_IMAGE_SCALE_RATIO);

// ============ DYNAMIC COLUMN WIDTH CALCULATION ============
function calculateDynamicColumnWidths($data, $headers, $report_by, $asset_class_type) {
    // Base widths for each column (in mm)
    $base_widths = [
        '#' => 7,
        'PERIOD' => 28,
        'QTY' => 18,
        'CONSIDERATION' => 28,
        'GROSS COMM' => 22,
        'BROKER COMM' => 22,
        'TOTAL LEVIES' => 20,
        'DSE' => 18,
        'CMSA' => 18,
        'CSD' => 18,
        'VAT' => 18,
        'FIDELITY/RETURN' => 22,
        'NET COMM' => 22
    ];
    
    // Column keys mapping
    $column_keys = [
        '#' => 'number',
        'PERIOD' => 'period',
        'QTY' => 'quantity',
        'CONSIDERATION' => 'consideration',
        'GROSS COMM' => 'gross',
        'BROKER COMM' => 'broker',
        'TOTAL LEVIES' => 'levies',
        'DSE' => 'dse',
        'CMSA' => 'cmsa',
        'CSD' => 'csd',
        'VAT' => 'vat',
        'FIDELITY/RETURN' => 'return',
        'NET COMM' => 'net'
    ];
    
    // Get column key based on asset type
    if ($asset_class_type === 'bond') {
        $col_keys = [
            '#' => 'number',
            'PERIOD' => 'period',
            'QTY (FV)' => 'quantity',
            'CONSIDERATION' => 'consideration',
            'GROSS COMM' => 'gross',
            'BROKER COMM' => 'broker',
            'TOTAL LEVIES' => 'levies',
            'DSE' => 'dse',
            'CMSA' => 'cmsa',
            'CSD' => 'csd',
            'VAT' => 'vat',
            'RETURN' => 'return',
            'NET COMM' => 'net'
        ];
    } else {
        $col_keys = [
            '#' => 'number',
            'PERIOD' => 'period',
            'QTY' => 'quantity',
            'CONSIDERATION' => 'consideration',
            'GROSS COMM' => 'gross',
            'BROKER COMM' => 'broker',
            'TOTAL LEVIES' => 'levies',
            'DSE' => 'dse',
            'CMSA' => 'cmsa',
            'CSD' => 'csd',
            'VAT' => 'vat',
            'FIDELITY' => 'return',
            'NET COMM' => 'net'
        ];
    }
    
    // Find max value length for each column
    $max_lengths = [];
    foreach ($base_widths as $key => $width) {
        $max_lengths[$key] = strlen($key); // Start with header length
    }
    
    // Group periods and find max values
    $grouped_periods = [];
    foreach ($data as $period_data) {
        $period_label = formatPeriodLabel($report_by, $period_data['period_group']);
        
        if (!isset($grouped_periods[$period_label])) {
            $grouped_periods[$period_label] = [
                'quantity' => 0,
                'consideration' => 0,
                'gross' => 0,
                'broker' => 0,
                'levies' => 0,
                'dse' => 0,
                'cmsa' => 0,
                'csd' => 0,
                'vat' => 0,
                'return' => 0,
                'net' => 0
            ];
        }
        
        $grouped_periods[$period_label]['quantity'] += $period_data['total_quantity'];
        $grouped_periods[$period_label]['consideration'] += $period_data['total_consideration'];
        $grouped_periods[$period_label]['gross'] += $period_data['total_gross_commission'];
        $grouped_periods[$period_label]['broker'] += $period_data['total_broker_commission'];
        $grouped_periods[$period_label]['levies'] += $period_data['total_levies'];
        $grouped_periods[$period_label]['dse'] += $period_data['total_dse_levy'];
        $grouped_periods[$period_label]['cmsa'] += $period_data['total_cmsa_fee'];
        $grouped_periods[$period_label]['csd'] += $period_data['total_csd_levy'];
        $grouped_periods[$period_label]['vat'] += $period_data['total_vat_levy'];
        $grouped_periods[$period_label]['return'] += $period_data['total_return_commission'];
        $grouped_periods[$period_label]['net'] += $period_data['total_net_commission'];
    }
    
    // Also add totals row
    $totals = [
        'quantity' => 0,
        'consideration' => 0,
        'gross' => 0,
        'broker' => 0,
        'levies' => 0,
        'dse' => 0,
        'cmsa' => 0,
        'csd' => 0,
        'vat' => 0,
        'return' => 0,
        'net' => 0
    ];
    
    foreach ($grouped_periods as $period) {
        $totals['quantity'] += $period['quantity'];
        $totals['consideration'] += $period['consideration'];
        $totals['gross'] += $period['gross'];
        $totals['broker'] += $period['broker'];
        $totals['levies'] += $period['levies'];
        $totals['dse'] += $period['dse'];
        $totals['cmsa'] += $period['cmsa'];
        $totals['csd'] += $period['csd'];
        $totals['vat'] += $period['vat'];
        $totals['return'] += $period['return'];
        $totals['net'] += $period['net'];
    }
    
    // Check max length for each column
    $value_mappings = [
        'quantity' => ['QTY', 'QTY (FV)'],
        'consideration' => ['CONSIDERATION'],
        'gross' => ['GROSS COMM'],
        'broker' => ['BROKER COMM'],
        'levies' => ['TOTAL LEVIES'],
        'dse' => ['DSE'],
        'cmsa' => ['CMSA'],
        'csd' => ['CSD'],
        'vat' => ['VAT'],
        'return' => ['RETURN', 'FIDELITY'],
        'net' => ['NET COMM']
    ];
    
    // Check period labels
    foreach ($grouped_periods as $period_label => $period) {
        $period_length = strlen($period_label);
        if ($period_length > $max_lengths['PERIOD']) {
            $max_lengths['PERIOD'] = $period_length;
        }
    }
    
    // Check number values
    foreach ($value_mappings as $key => $header_names) {
        $max_val = max(
            strlen(number_format($totals[$key], 2)),
            strlen('TOTAL')
        );
        
        foreach ($grouped_periods as $period) {
            $val_length = strlen(number_format($period[$key], 2));
            if ($val_length > $max_val) {
                $max_val = $val_length;
            }
        }
        
        // Find the header name to set the width
        foreach ($header_names as $header_name) {
            if (isset($max_lengths[$header_name])) {
                $max_lengths[$header_name] = max($max_lengths[$header_name], $max_val);
            }
        }
    }
    
    // Also check the number column (#)
    $max_lengths['#'] = max($max_lengths['#'], strlen('999'));
    
    // Calculate final widths (in mm) - each character is approximately 1.5mm
    $char_width = 1.5;
    $min_width = 6;
    $max_width = 35;
    
    $final_widths = [];
    $total_width = 0;
    
    foreach ($base_widths as $key => $base_width) {
        $calculated_width = max($min_width, min($max_width, $max_lengths[$key] * $char_width + 4));
        $final_widths[$key] = $calculated_width;
        $total_width += $calculated_width;
    }
    
    // Normalize widths if total exceeds page width (280mm)
    $page_width = 280;
    if ($total_width > $page_width) {
        $scale = $page_width / $total_width;
        foreach ($final_widths as $key => $width) {
            $final_widths[$key] = $width * $scale;
        }
    }
    
    return $final_widths;
}

// Function to generate commission table with dynamic widths
function generateCommissionTable($pdf, $data, $report_by, $asset_class_type, $watermark, $period_from, $period_to) {
    if (empty($data)) return null;
    
    $pdf->AddPage('L');
    
    if ($watermark === 'yes') {
        $pdf->SetFont('helvetica', 'B', 40);
        $pdf->SetTextColor(230, 230, 230);
        $pdf->RotatedText(150, 100, $pdf->company_name, 45);
        $pdf->SetTextColor(0, 0, 0);
    }
    
    $pdf->AssetClassHeader($asset_class_type);
    
    $pdf->SetFont('helvetica', '', 7);
    if (!empty($period_from) && !empty($period_to)) {
        $pdf->Cell(0, 5, 'Report Period: ' . date('d/m/Y', strtotime($period_from)) . ' - ' . date('d/m/Y', strtotime($period_to)), 0, 1, 'C');
    }
    $pdf->Cell(0, 4, 'Generated: ' . date('d/m/Y H:i:s'), 0, 1, 'C');
    $pdf->Ln(3);
    
    // Build headers
    if ($asset_class_type === 'bond') {
        $headers = ['#', 'PERIOD', 'QTY (FV)', 'CONSIDERATION', 'GROSS COMM', 'BROKER COMM', 'TOTAL LEVIES', 'DSE', 'CMSA', 'CSD', 'VAT', 'RETURN', 'NET COMM'];
    } else {
        $headers = ['#', 'PERIOD', 'QTY', 'CONSIDERATION', 'GROSS COMM', 'BROKER COMM', 'TOTAL LEVIES', 'DSE', 'CMSA', 'CSD', 'VAT', 'FIDELITY', 'NET COMM'];
    }
    
    // Calculate dynamic column widths
    $col_widths = calculateDynamicColumnWidths($data, $headers, $report_by, $asset_class_type);
    
    // Get widths in order of headers
    $ordered_widths = [];
    foreach ($headers as $header) {
        $ordered_widths[] = $col_widths[$header] ?? 18;
    }
    
    // Center table
    $page_width = 297;
    $margin_left = $pdf->GetX();
    $total_width = array_sum($ordered_widths);
    $start_x = max($margin_left, ($page_width - $total_width) / 2);
    
    // Header
    $pdf->SetFont('helvetica', 'B', 6);
    $pdf->SetFillColor(200, 200, 200);
    $pdf->SetX($start_x);
    
    foreach ($headers as $i => $col) {
        $pdf->Cell($ordered_widths[$i], 6, $col, 1, 0, 'C', 1);
    }
    $pdf->Ln();
    
    // Data rows
    $pdf->SetFont('helvetica', '', 6);
    $counter = 1;
    $totals = array_fill(0, count($headers) - 1, 0);
    
    $grouped_periods = [];
    foreach ($data as $period_data) {
        $period_label = formatPeriodLabel($report_by, $period_data['period_group']);
        
        if (!isset($grouped_periods[$period_label])) {
            $grouped_periods[$period_label] = [
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
                'is_liberty' => $period_data['is_liberty'] ?? false,
                'effective_rate' => $period_data['effective_rate'] ?? 0,
                'liberty_mode' => $period_data['liberty_mode'] ?? 'replace_all'
            ];
        }
        
        $grouped_periods[$period_label]['total_quantity'] += $period_data['total_quantity'];
        $grouped_periods[$period_label]['total_consideration'] += $period_data['total_consideration'];
        $grouped_periods[$period_label]['total_gross_commission'] += $period_data['total_gross_commission'];
        $grouped_periods[$period_label]['total_broker_commission'] += $period_data['total_broker_commission'];
        $grouped_periods[$period_label]['total_levies'] += $period_data['total_levies'];
        $grouped_periods[$period_label]['total_dse_levy'] += $period_data['total_dse_levy'];
        $grouped_periods[$period_label]['total_cmsa_fee'] += $period_data['total_cmsa_fee'];
        $grouped_periods[$period_label]['total_csd_levy'] += $period_data['total_csd_levy'];
        $grouped_periods[$period_label]['total_vat_levy'] += $period_data['total_vat_levy'];
        $grouped_periods[$period_label]['total_return_commission'] += $period_data['total_return_commission'];
        $grouped_periods[$period_label]['total_net_commission'] += $period_data['total_net_commission'];
    }
    
    uksort($grouped_periods, function($a, $b) {
        return strtotime($b) - strtotime($a);
    });
    
    foreach ($grouped_periods as $period_label => $period) {
        if ($pdf->GetY() > 250) {
            $pdf->AddPage('L');
            if ($watermark === 'yes') {
                $pdf->SetFont('helvetica', 'B', 40);
                $pdf->SetTextColor(230, 230, 230);
                $pdf->RotatedText(150, 100, $pdf->company_name, 45);
                $pdf->SetTextColor(0, 0, 0);
            }
            $pdf->AssetClassHeader($asset_class_type);
            $pdf->SetFont('helvetica', 'B', 6);
            $pdf->SetFillColor(200, 200, 200);
            $pdf->SetX($start_x);
            foreach ($headers as $i => $col) {
                $pdf->Cell($ordered_widths[$i], 6, $col, 1, 0, 'C', 1);
            }
            $pdf->Ln();
            $pdf->SetFont('helvetica', '', 6);
        }
        
        $fill_color = ($counter % 2 == 0) ? [248, 248, 248] : [255, 255, 255];
        $pdf->SetFillColor($fill_color[0], $fill_color[1], $fill_color[2]);
        $pdf->SetX($start_x);
        
        // Row number
        $pdf->Cell($ordered_widths[0], 5, $counter, 1, 0, 'C', 1);
        
        // Period
        $pdf->Cell($ordered_widths[1], 5, $period_label, 1, 0, 'L', 1);
        
        // Data values
        $data_values = [
            number_format($period['total_quantity'], 2),
            number_format($period['total_consideration'], 2),
            number_format($period['total_gross_commission'], 2),
            number_format($period['total_broker_commission'], 2),
            number_format($period['total_levies'], 2),
            number_format($period['total_dse_levy'], 2),
            number_format($period['total_cmsa_fee'], 2),
            number_format($period['total_csd_levy'], 2),
            number_format($period['total_vat_levy'], 2),
            number_format($period['total_return_commission'], 2),
            number_format($period['total_net_commission'], 2)
        ];
        
        foreach ($data_values as $i => $value) {
            $pdf->Cell($ordered_widths[$i + 2], 5, $value, 1, 0, 'R', 1);
        }
        $pdf->Ln();
        
        // Accumulate totals
        $totals[0] += $period['total_quantity'];
        $totals[1] += $period['total_consideration'];
        $totals[2] += $period['total_gross_commission'];
        $totals[3] += $period['total_broker_commission'];
        $totals[4] += $period['total_levies'];
        $totals[5] += $period['total_dse_levy'];
        $totals[6] += $period['total_cmsa_fee'];
        $totals[7] += $period['total_csd_levy'];
        $totals[8] += $period['total_vat_levy'];
        $totals[9] += $period['total_return_commission'];
        $totals[10] += $period['total_net_commission'];
        
        $counter++;
    }
    
    // Total row
    $pdf->SetFont('helvetica', 'B', 6);
    $pdf->SetFillColor(180, 180, 180);
    $pdf->SetX($start_x);
    
    $pdf->Cell($ordered_widths[0] + $ordered_widths[1], 6, 'TOTAL', 1, 0, 'C', 1);
    $total_values = [
        number_format($totals[0], 2),
        number_format($totals[1], 2),
        number_format($totals[2], 2),
        number_format($totals[3], 2),
        number_format($totals[4], 2),
        number_format($totals[5], 2),
        number_format($totals[6], 2),
        number_format($totals[7], 2),
        number_format($totals[8], 2),
        number_format($totals[9], 2),
        number_format($totals[10], 2)
    ];
    
    foreach ($total_values as $i => $value) {
        $pdf->Cell($ordered_widths[$i + 2], 6, $value, 1, 0, 'R', 1);
    }
    $pdf->Ln();
    
    $pdf->Ln(8);
    
    // Add note about liberty rates if applicable
    $has_liberty = false;
    $liberty_modes_used = [];
    foreach ($grouped_periods as $period) {
        if ($period['is_liberty']) {
            $has_liberty = true;
            if (!in_array($period['liberty_mode'], $liberty_modes_used)) {
                $liberty_modes_used[] = $period['liberty_mode'];
            }
        }
    }
    
    if ($has_liberty) {
        $pdf->SetFont('helvetica', 'I', 5);
        $pdf->SetTextColor(100, 100, 100);
        $mode_text = '';
        foreach ($liberty_modes_used as $mode) {
            if ($mode == 'replace_all') {
                $mode_text .= 'Full Rate, ';
            } elseif ($mode == 'excess_only') {
                $mode_text .= 'Excess Only, ';
            } elseif ($mode == 'tier_override') {
                $mode_text .= 'Tier Override, ';
            }
        }
        $mode_text = rtrim($mode_text, ', ');
        $pdf->Cell(0, 3, 'Note: Liberty rates applied - Modes: ' . $mode_text, 0, 1, 'C');
        $pdf->SetTextColor(0, 0, 0);
        $pdf->Ln(3);
    }
    
    return $totals;
}

// Generate reports
$equities_totals = !empty($equities_data) ? generateCommissionTable($pdf, $equities_data, $report_by, 'equity', $watermark, $period_from, $period_to) : null;
$etf_totals = !empty($etf_data) ? generateCommissionTable($pdf, $etf_data, $report_by, 'etf', $watermark, $period_from, $period_to) : null;
$bonds_totals = !empty($bonds_data) ? generateCommissionTable($pdf, $bonds_data, $report_by, 'bond', $watermark, $period_from, $period_to) : null;

// Grand Summary with dynamic widths
$asset_class_count = (!empty($equities_data) ? 1 : 0) + (!empty($etf_data) ? 1 : 0) + (!empty($bonds_data) ? 1 : 0);
if ($asset_class_count > 1 && ($equities_totals || $etf_totals || $bonds_totals)) {
    $pdf->AddPage('L');
    if ($watermark === 'yes') {
        $pdf->SetFont('helvetica', 'B', 40);
        $pdf->SetTextColor(230, 230, 230);
        $pdf->RotatedText(150, 100, $pdf->company_name, 45);
        $pdf->SetTextColor(0, 0, 0);
    }
    $pdf->SetFont('helvetica', 'B', 12);
    $pdf->Cell(0, 10, 'GRAND SUMMARY - ALL ASSET CLASSES', 0, 1, 'C');
    $pdf->Ln(5);
    
    $pdf->SetFont('helvetica', 'B', 9);
    $pdf->SetFillColor(200, 200, 200);
    
    $summary_headers = ['Asset Class', 'Total Qty', 'Total Consideration', 'Gross Comm', 'Broker Comm', 'Total Levies', 'Net Comm'];
    $summary_widths = [40, 30, 45, 40, 40, 35, 40];
    
    // Dynamic summary widths
    $max_lengths = [];
    foreach ($summary_headers as $header) {
        $max_lengths[$header] = strlen($header);
    }
    
    $summary_data = [];
    if ($equities_totals) $summary_data[] = ['name' => 'Equities', 'totals' => $equities_totals];
    if ($etf_totals) $summary_data[] = ['name' => 'ETFs', 'totals' => $etf_totals];
    if ($bonds_totals) $summary_data[] = ['name' => 'Bonds', 'totals' => $bonds_totals];
    
    foreach ($summary_data as $item) {
        $name_length = strlen($item['name']);
        if ($name_length > $max_lengths['Asset Class']) {
            $max_lengths['Asset Class'] = $name_length;
        }
        
        $max_lengths['Total Qty'] = max($max_lengths['Total Qty'], strlen(number_format($item['totals'][0], 2)));
        $max_lengths['Total Consideration'] = max($max_lengths['Total Consideration'], strlen(number_format($item['totals'][1], 2)));
        $max_lengths['Gross Comm'] = max($max_lengths['Gross Comm'], strlen(number_format($item['totals'][2], 2)));
        $max_lengths['Broker Comm'] = max($max_lengths['Broker Comm'], strlen(number_format($item['totals'][3], 2)));
        $max_lengths['Total Levies'] = max($max_lengths['Total Levies'], strlen(number_format($item['totals'][4], 2)));
        $max_lengths['Net Comm'] = max($max_lengths['Net Comm'], strlen(number_format($item['totals'][10], 2)));
    }
    
    $char_width = 1.5;
    $min_width = 20;
    $max_width = 50;
    
    $summary_widths = [];
    $total_summary_width = 0;
    foreach ($summary_headers as $header) {
        $width = max($min_width, min($max_width, $max_lengths[$header] * $char_width + 6));
        $summary_widths[] = $width;
        $total_summary_width += $width;
    }
    
    $page_width = 297;
    $start_x = ($page_width - $total_summary_width) / 2;
    $pdf->SetX($start_x);
    
    foreach ($summary_headers as $i => $header) {
        $pdf->Cell($summary_widths[$i], 8, $header, 1, 0, 'C', 1);
    }
    $pdf->Ln();
    
    $pdf->SetFont('helvetica', '', 8);
    $grand_totals = ['quantity' => 0, 'consideration' => 0, 'gross' => 0, 'broker' => 0, 'levies' => 0, 'net' => 0];
    
    foreach ($summary_data as $item) {
        $pdf->SetX($start_x);
        $pdf->Cell($summary_widths[0], 7, $item['name'], 1, 0, 'L', 0);
        $pdf->Cell($summary_widths[1], 7, number_format($item['totals'][0], 2), 1, 0, 'R', 0);
        $pdf->Cell($summary_widths[2], 7, number_format($item['totals'][1], 2), 1, 0, 'R', 0);
        $pdf->Cell($summary_widths[3], 7, number_format($item['totals'][2], 2), 1, 0, 'R', 0);
        $pdf->Cell($summary_widths[4], 7, number_format($item['totals'][3], 2), 1, 0, 'R', 0);
        $pdf->Cell($summary_widths[5], 7, number_format($item['totals'][4], 2), 1, 0, 'R', 0);
        $pdf->Cell($summary_widths[6], 7, number_format($item['totals'][10], 2), 1, 1, 'R', 0);
        
        $grand_totals['quantity'] += $item['totals'][0];
        $grand_totals['consideration'] += $item['totals'][1];
        $grand_totals['gross'] += $item['totals'][2];
        $grand_totals['broker'] += $item['totals'][3];
        $grand_totals['levies'] += $item['totals'][4];
        $grand_totals['net'] += $item['totals'][10];
    }
    
    $pdf->SetFont('helvetica', 'B', 8);
    $pdf->SetFillColor(180, 180, 180);
    $pdf->SetX($start_x);
    $pdf->Cell($summary_widths[0], 8, 'GRAND TOTAL', 1, 0, 'C', 1);
    $pdf->Cell($summary_widths[1], 8, number_format($grand_totals['quantity'], 2), 1, 0, 'R', 1);
    $pdf->Cell($summary_widths[2], 8, number_format($grand_totals['consideration'], 2), 1, 0, 'R', 1);
    $pdf->Cell($summary_widths[3], 8, number_format($grand_totals['gross'], 2), 1, 0, 'R', 1);
    $pdf->Cell($summary_widths[4], 8, number_format($grand_totals['broker'], 2), 1, 0, 'R', 1);
    $pdf->Cell($summary_widths[5], 8, number_format($grand_totals['levies'], 2), 1, 0, 'R', 1);
    $pdf->Cell($summary_widths[6], 8, number_format($grand_totals['net'], 2), 1, 1, 'R', 1);
}

// Output PDF
$filename = 'commission_report_' . date('Ymd_His') . '.pdf';
if ($export_type === 'download') {
    $pdf->Output($filename, 'D');
} else {
    $pdf->Output($filename, 'I');
}
?>