<?php
// Error reporting - log but don't display
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/dealing_sheet_errors.log');

// Start output buffering
if (ob_get_level() == 0) {
    ob_start();
}

require_once '../config/config.php';
require_once '../auth/auth_middleware.php';
require_once '../includes/dealing_sheet_helpers.php';
require_once '../tcpdf/tcpdf.php';
require_once __DIR__ . '/../reports/traits/ReportHeaderTrait.php';

require_trader();
require_mandate();

$db = getDBConnection();
$current_user = get_logged_in_user() ?: get_session_user();
$company = dealingSheetGetCompany($db);
$view = $_GET['view'] ?? 'all';

// ============================================
// DEALING SHEET PDF CLASS
// ============================================
class DealingSheetPDF extends TCPDF {
    use ReportHeaderTrait;
    
    private $company_name = '';
    private $watermark_enabled = true;
    
    public function setCompanyName($name) {
        $this->company_name = $name;
    }
    
    public function setWatermarkEnabled($enabled) {
        $this->watermark_enabled = $enabled;
    }
    
    public function Header() {
        $this->renderReportHeader();
        
        $y = $this->GetY();
        
        if ($this->watermark_enabled) {
            $this->SetAlpha(0.05);
            $this->SetFont('times', 'B', 45);
            $this->SetTextColor(200, 200, 200);
            $this->StartTransform();
            $this->Rotate(45, 105, 150);
            $this->Text(105, 150, $this->company_name);
            $this->StopTransform();
            $this->SetAlpha(1);
            $this->SetTextColor(0, 0, 0);
        }
        
        $this->SetY($y + 2);
    }
    
    public function Footer() {
        $this->SetY(-12);
        $this->SetFont('helvetica', 'I', 7);
        $this->SetTextColor(100, 100, 100);
        $this->Cell(0, 5, 'Generated on: ' . date('d/m/Y H:i:s'), 0, 0, 'L');
        $this->Cell(0, 5, 'Page ' . $this->getAliasNumPage() . ' of ' . $this->getAliasNbPages(), 0, 0, 'R');
    }
    
    public function addDealingSheet($sheet, $fees, $exportedByName) {
        $this->AddPage();
        
        $is_bond = ($sheet['asset_class'] === 'bond');
        $trade_side = strtoupper($sheet['order_type'] ?? 'BUY');
        
        // Title
        $this->SetFont('helvetica', 'B', 16);
        $this->Cell(0, 10, 'ORDER SHEET', 0, 1, 'C');
        
        $this->Ln(4);
        
        // Company info line
        $this->SetFont('helvetica', '', 9);
        $this->Cell(0, 5, 'Broker Code: ' . ($sheet['broker_code'] ?? 'N/A'), 0, 1, 'L');
        $this->Cell(0, 5, 'Department: Operations', 0, 1, 'L');
        $this->Cell(0, 5, 'Date: ' . date('d/m/Y', strtotime($sheet['order_date'] ?? date('Y-m-d'))), 0, 1, 'L');
        
        $this->Ln(4);
        
        // ============================================
        // 1. CLIENT DETAILS
        // ============================================
        $this->SetFont('helvetica', 'B', 11);
        $this->SetFillColor(240, 240, 240);
        $this->Cell(0, 8, '1. CLIENT DETAILS', 0, 1, 'L', true);
        
        $this->SetFont('helvetica', '', 10);
        $this->Cell(45, 7, 'Client Name:', 0, 0);
        $this->SetFont('helvetica', 'B', 10);
        $this->Cell(0, 7, $sheet['client_name'], 0, 1);
        
        $this->SetFont('helvetica', '', 10);
        $this->Cell(45, 7, 'CDS Account:', 0, 0);
        $this->SetFont('helvetica', 'B', 10);
        $this->Cell(0, 7, $sheet['client_cds_account'], 0, 1);
        
        $this->Ln(4);
        
        // ============================================
        // 2. ORDER DETAILS - Updated for bonds
        // ============================================
        $this->SetFont('helvetica', 'B', 11);
        $this->SetFillColor(240, 240, 240);
        $this->Cell(0, 8, '2. ORDER DETAILS', 0, 1, 'L', true);
        
        $this->SetFont('helvetica', '', 10);
        $this->Cell(50, 7, 'Order Type:', 0, 0);
        $this->SetFont('helvetica', 'B', 10);
        $this->Cell(60, 7, $trade_side, 0, 0);
        
        $this->SetFont('helvetica', '', 10);
        $this->Cell(45, 7, 'Priority:', 0, 0);
        $this->SetFont('helvetica', 'B', 10);
        $this->Cell(0, 7, $sheet['priority'] ?? 'Normal', 0, 1);
        
        $this->SetFont('helvetica', '', 10);
        $this->Cell(50, 7, 'Asset Class:', 0, 0);
        $this->SetFont('helvetica', 'B', 10);
        $this->Cell(60, 7, ucfirst($sheet['asset_class']), 0, 0);
        
        $this->SetFont('helvetica', '', 10);
        $this->Cell(45, 7, 'Security:', 0, 0);
        $this->SetFont('helvetica', 'B', 10);
        $this->Cell(0, 7, $sheet['security_id'], 0, 1);
        
        $qty = floatval($sheet['quantity'] ?? 0);
        $price = floatval($sheet['order_price'] ?? 0);
        
        // Calculate order value based on asset class
        if ($is_bond) {
            $order_value = ($price / 100) * $qty;
            $quantity_label = 'Quantity (Face Value):';
            $price_label = 'Price (% of Par):';
            $price_display = number_format($price, 4) . '%';
            $qty_display = 'TZS ' . number_format($qty, 2);
        } else {
            $order_value = $qty * $price;
            $quantity_label = 'Quantity:';
            $price_label = 'Price (TZS):';
            $price_display = 'TZS ' . number_format($price, 2);
            $qty_display = number_format($qty, 0);
        }
        
        $this->SetFont('helvetica', '', 10);
        $this->Cell(50, 7, $quantity_label, 0, 0);
        $this->SetFont('helvetica', 'B', 10);
        $this->Cell(60, 7, $qty_display, 0, 0);
        
        $this->SetFont('helvetica', '', 10);
        $this->Cell(45, 7, $price_label, 0, 0);
        $this->SetFont('helvetica', 'B', 10);
        $this->Cell(0, 7, $price_display, 0, 1);
        
        $this->SetFont('helvetica', '', 10);
        $this->Cell(50, 7, 'Order Value:', 0, 0);
        $this->SetFont('helvetica', 'B', 10);
        $this->Cell(60, 7, 'TZS ' . number_format($order_value, 2), 0, 0);
        
        $this->SetFont('helvetica', '', 10);
        $this->Cell(45, 7, 'Order Date/Time:', 0, 0);
        $this->SetFont('helvetica', 'B', 10);
        $order_datetime = ($sheet['order_date'] ?? date('Y-m-d')) . ' ' . ($sheet['order_time'] ?? '');
        $this->Cell(0, 7, date('d/m/Y H:i', strtotime($order_datetime)), 0, 1);
        
        // NEW: Display Additional Reference if present
        if (!empty($sheet['additional_reference'])) {
            $this->SetFont('helvetica', '', 10);
            $this->Cell(50, 7, 'Additional Reference:', 0, 0);
            $this->SetFont('helvetica', 'B', 10);
            $this->Cell(0, 7, $sheet['additional_reference'], 0, 1);
        }
        
        $this->Ln(4);
        
        // ============================================
        // 3. EXECUTION DETAILS - Updated for bonds
        // ============================================
        $this->SetFont('helvetica', 'B', 11);
        $this->SetFillColor(240, 240, 240);
        $this->Cell(0, 8, '3. EXECUTION DETAILS', 0, 1, 'L', true);
        
        $executed_qty = floatval($sheet['executed_quantity'] ?? 0);
        $executed_price = floatval($sheet['executed_price'] ?? 0);
        
        if ($is_bond) {
            $executed_value = ($executed_price / 100) * $executed_qty;
            $exec_qty_display = $executed_qty > 0 ? 'TZS ' . number_format($executed_qty, 2) : 'Pending';
            $exec_price_display = $executed_price > 0 ? number_format($executed_price, 4) . '%' : 'Pending';
        } else {
            $executed_value = $executed_qty * $executed_price;
            $exec_qty_display = $executed_qty > 0 ? number_format($executed_qty, 0) : 'Pending';
            $exec_price_display = $executed_price > 0 ? 'TZS ' . number_format($executed_price, 2) : 'Pending';
        }
        
        $this->SetFont('helvetica', '', 10);
        $this->Cell(60, 7, 'Executed Quantity:', 0, 0);
        $this->SetFont('helvetica', 'B', 10);
        $this->Cell(50, 7, $exec_qty_display, 0, 0);
        
        $this->SetFont('helvetica', '', 10);
        $this->Cell(50, 7, 'Executed Price:', 0, 0);
        $this->SetFont('helvetica', 'B', 10);
        $this->Cell(0, 7, $exec_price_display, 0, 1);
        
        $this->SetFont('helvetica', '', 10);
        $this->Cell(60, 7, 'Executed Value:', 0, 0);
        $this->SetFont('helvetica', 'B', 10);
        $this->Cell(50, 7, $executed_value > 0 ? 'TZS ' . number_format($executed_value, 2) : 'Pending', 0, 0);
        
        $this->SetFont('helvetica', '', 10);
        $this->Cell(50, 7, 'Execution Status:', 0, 0);
        $this->SetFont('helvetica', 'B', 10);
        $this->Cell(0, 7, ucfirst($sheet['execution_status'] ?? 'Pending'), 0, 1);
        
        if ($executed_qty > 0) {
            $this->SetFont('helvetica', '', 10);
            $this->Cell(60, 7, 'Trade Date:', 0, 0);
            $this->SetFont('helvetica', 'B', 10);
            $this->Cell(50, 7, $sheet['trade_date'] ? date('d/m/Y', strtotime($sheet['trade_date'])) : 'Pending', 0, 0);
            
            $this->SetFont('helvetica', '', 10);
            $this->Cell(50, 7, 'Settlement Date:', 0, 0);
            $this->SetFont('helvetica', 'B', 10);
            $this->Cell(0, 7, $sheet['settlement_date'] ? date('d/m/Y', strtotime($sheet['settlement_date'])) : 'Pending', 0, 1);
            
            $this->SetFont('helvetica', '', 10);
            $this->Cell(60, 7, 'Trade Reference:', 0, 0);
            $this->SetFont('helvetica', 'B', 10);
            $this->Cell(0, 7, $sheet['trade_reference'] ?? 'Pending', 0, 1);
        }
        
        $this->Ln(4);
        
        // ============================================
        // 4. FEES AND CHARGES
        // ============================================
        $this->SetFont('helvetica', 'B', 11);
        $this->SetFillColor(240, 240, 240);
        $this->Cell(0, 8, '4. FEES AND CHARGES', 0, 1, 'L', true);
        
        // Table header
        $this->SetFont('helvetica', 'B', 9);
        $this->Cell(100, 7, 'Description', 0, 0, 'L');
        $this->Cell(40, 7, 'Rate', 0, 0, 'R');
        $this->Cell(40, 7, 'Amount (TZS)', 0, 1, 'R');
        $this->SetLineWidth(0.2);
        $this->Line(25, $this->GetY(), 185, $this->GetY());
        
        $this->SetFont('helvetica', '', 9);
        
        // Brokerage Commission
        $this->Cell(100, 6, 'Brokerage Commission', 0, 0, 'L');
        $this->Cell(40, 6, $is_bond ? 'Tiered' : 'Tiered', 0, 0, 'R');
        $this->Cell(40, 6, number_format($fees['brokerage'], 2), 0, 1, 'R');
        
        // Show tier details
        if (!empty($fees['tier_details'])) {
            $this->SetFont('helvetica', 'I', 7);
            foreach ($fees['tier_details'] as $tier) {
                $this->Cell(15, 4, '', 0, 0);
                $this->Cell(85, 4, $tier['label'], 0, 0, 'L');
                $this->Cell(40, 4, number_format($tier['fee'], 2), 0, 1, 'R');
            }
            $this->SetFont('helvetica', '', 9);
        }
        
        // VAT
        $this->Cell(100, 6, 'VAT on Brokerage', 0, 0, 'L');
        $this->Cell(40, 6, '@ 18.000%', 0, 0, 'R');
        $this->Cell(40, 6, number_format($fees['vat'], 2), 0, 1, 'R');
        
        // CMSA
        $cmsa_rate = $is_bond ? '0.0100%' : '0.1400%';
        $this->Cell(100, 6, 'CMSA Transaction Fee', 0, 0, 'L');
        $this->Cell(40, 6, '@ ' . $cmsa_rate, 0, 0, 'R');
        $this->Cell(40, 6, number_format($fees['cmsa'], 2), 0, 1, 'R');
        
        // DSE
        $dse_rate = $is_bond ? '0.02006% (on Face Value)' : '0.1652%';
        $this->Cell(100, 6, 'DSE Transaction Fee', 0, 0, 'L');
        $this->Cell(40, 6, '@ ' . $dse_rate, 0, 0, 'R');
        $this->Cell(40, 6, number_format($fees['dse'], 2), 0, 1, 'R');
        
        // Fidelity (Equity/ETF only)
        if (!$is_bond) {
            $this->Cell(100, 6, 'Fidelity Fee', 0, 0, 'L');
            $this->Cell(40, 6, '@ 0.0200%', 0, 0, 'R');
            $this->Cell(40, 6, number_format($fees['fidelity'] ?? 0, 2), 0, 1, 'R');
        }
        
        // CDS/CSDR
        $cds_rate = $is_bond ? '0.0118% (on Face Value)' : '0.0708%';
        $this->Cell(100, 6, 'CDS Fee', 0, 0, 'L');
        $this->Cell(40, 6, '@ ' . $cds_rate, 0, 0, 'R');
        $this->Cell(40, 6, number_format($fees['csd'], 2), 0, 1, 'R');
        
        // Other Charges (placeholder)
        $this->Cell(100, 6, 'Other Charges', 0, 0, 'L');
        $this->Cell(40, 6, '', 0, 0, 'R');
        $this->Cell(40, 6, '0.00', 0, 1, 'R');
        
        $this->Line(25, $this->GetY(), 185, $this->GetY());
        
        // Total Charges
        $this->SetFont('helvetica', 'B', 10);
        $this->Cell(140, 8, 'TOTAL CHARGES', 0, 0, 'R');
        $this->Cell(40, 8, number_format($fees['total'], 2), 0, 1, 'R');
        
        $this->Ln(4);
        
        // ============================================
        // NET AMOUNT
        // ============================================
        // Use executed value if available, otherwise order value
        if ($executed_qty > 0 && $executed_price > 0) {
            $consideration = ($is_bond) ? ($executed_price / 100) * $executed_qty : $executed_qty * $executed_price;
        } else {
            $consideration = ($is_bond) ? ($price / 100) * $qty : $qty * $price;
        }
        
        $is_sell = ($trade_side === 'SELL');
        
        if ($is_sell) {
            $net_amount = $consideration - $fees['total'];
            $net_label = 'NET AMOUNT RECEIVABLE';
        } else {
            $net_amount = $consideration + $fees['total'];
            $net_label = 'NET AMOUNT PAYABLE';
        }
        
        $this->SetLineWidth(0.5);
        $this->Line(25, $this->GetY() + 2, 185, $this->GetY() + 2);
        $this->Ln(4);
        
        $this->SetFont('helvetica', 'B', 12);
        $this->Cell(120, 8, $net_label, 0, 0, 'R');
        $this->SetFont('helvetica', 'B', 12);
        $this->SetTextColor(0, 100, 0);
        $this->Cell(40, 8, 'TZS ' . number_format($net_amount, 2), 0, 1, 'R');
        $this->SetTextColor(0, 0, 0);
        
        $this->Ln(8);
        
        // ============================================
        // REMARKS
        // ============================================
        if (!empty($sheet['remarks'])) {
            $this->SetFont('helvetica', 'B', 10);
            $this->Cell(0, 6, 'Remarks:', 0, 1);
            $this->SetFont('helvetica', '', 9);
            $this->MultiCell(0, 5, $sheet['remarks'], 0, 'L');
            $this->Ln(4);
        }
        
        // ============================================
        // SIGNATURES
        // ============================================
        $this->SetFont('helvetica', 'B', 9);
        $this->Cell(0, 6, 'Dealer Name:', 0, 0);
        $this->SetFont('helvetica', '', 9);
        $this->Cell(0, 6, $sheet['dealer_name'] ?? $exportedByName, 0, 1);
        $this->Ln(4);
        
        $this->SetFont('helvetica', 'B', 9);
        $this->Cell(80, 6, 'Prepared By:', 0, 0);
        $this->Cell(80, 6, 'Checked By:', 0, 0);
        $this->Cell(0, 6, 'Approved By:', 0, 1);
        
        $this->SetLineWidth(0.2);
        $this->Line(25, $this->GetY() + 8, 65, $this->GetY() + 8);
        $this->Line(90, $this->GetY() + 8, 130, $this->GetY() + 8);
        $this->Line(150, $this->GetY() + 8, 185, $this->GetY() + 8);
        
        $this->SetFont('helvetica', 'I', 7);
        $this->Cell(80, 12, $exportedByName, 0, 0, 'L');
        $this->Cell(80, 12, '__________________', 0, 0, 'L');
        $this->Cell(0, 12, '__________________', 0, 1, 'L');
        
        $this->Ln(8);
        
        // ============================================
        // DISCLAIMER
        // ============================================
        $this->SetFont('helvetica', 'I', 6);
        $this->SetTextColor(120, 120, 120);
        $disclaimer = "This Dealing Sheet is for internal use only. It does not constitute a contract note or official trade confirmation. " .
                      "All trades are subject to the Rules, Regulations and Customs of the Dar es Salaam Stock Exchange.";
        $this->MultiCell(0, 3, $disclaimer, 0, 'C');
        $this->SetTextColor(0, 0, 0);
    }
}

// ============================================
// FEES CALCULATION FUNCTION - BOND SPECIFIC
// ============================================
function calculateDealingSheetFees($asset_class, $consideration, $quantity, $price) {
    $fees = [];
    $fees['tier_details'] = [];
    
    $is_bond = ($asset_class === 'bond');
    
    if ($is_bond) {
        // BOND FEES - Using the same calculation as your upload script
        $face_value = $quantity; // Face value in TZS
        
        // Brokerage: 0.063132% on first 100M, 0.035% on excess
        $brokerage_first_100m = min($face_value, 100000000) * (0.063132 / 100);
        $brokerage_excess = max($face_value - 100000000, 0) * (0.035 / 100);
        $fees['brokerage'] = $brokerage_first_100m + $brokerage_excess;
        
        if ($face_value <= 100000000) {
            $fees['tier_details'][] = [
                'fee' => $brokerage_first_100m,
                'label' => number_format($face_value/1000000, 2) . 'M @ 0.063132%'
            ];
        } else {
            $fees['tier_details'][] = [
                'fee' => $brokerage_first_100m,
                'label' => 'First 100M @ 0.063132%'
            ];
            $fees['tier_details'][] = [
                'fee' => $brokerage_excess,
                'label' => 'Excess ' . number_format(($face_value - 100000000)/1000000, 2) . 'M @ 0.035%'
            ];
        }
        
        // VAT: 18% on brokerage
        $fees['vat'] = $fees['brokerage'] * 0.18;
        
        // CMSA: 0.0100% on consideration (actual monetary value)
        $fees['cmsa'] = $consideration * (0.0100 / 100);
        
        // CSD: 0.0118% on face value
        $fees['csd'] = $face_value * (0.0118 / 100);
        
        // DSE: 0.02006% on face value
        $fees['dse'] = $face_value * (0.02006 / 100);
        
        // No fidelity fee for bonds
        $fees['fidelity'] = 0;
        
        // Calculate total
        $fees['total'] = $fees['brokerage'] + $fees['vat'] + $fees['cmsa'] + $fees['dse'] + $fees['csd'];
        
    } else {
        // EQUITY/ETF FEES (unchanged)
        $total_brokerage = 0;
        
        if ($consideration <= 10000000) {
            $brokerage_fee = $consideration * (1.7000 / 100);
            $fees['tier_details'][] = [
                'fee' => $brokerage_fee,
                'label' => 'Up to 10M @ 1.7000%'
            ];
            $total_brokerage = $brokerage_fee;
        } elseif ($consideration <= 50000000) {
            $tier1_fee = 10000000 * (1.7000 / 100);
            $tier2_fee = ($consideration - 10000000) * (1.5000 / 100);
            $fees['tier_details'][] = [
                'fee' => $tier1_fee,
                'label' => 'First 10M @ 1.7000%'
            ];
            $fees['tier_details'][] = [
                'fee' => $tier2_fee,
                'label' => 'Next ' . number_format(($consideration - 10000000)/1000000, 1) . 'M @ 1.5000%'
            ];
            $total_brokerage = $tier1_fee + $tier2_fee;
        } else {
            $tier1_fee = 10000000 * (1.7000 / 100);
            $tier2_fee = 40000000 * (1.5000 / 100);
            $tier3_fee = ($consideration - 50000000) * (0.8000 / 100);
            $fees['tier_details'][] = [
                'fee' => $tier1_fee,
                'label' => 'First 10M @ 1.7000%'
            ];
            $fees['tier_details'][] = [
                'fee' => $tier2_fee,
                'label' => 'Next 40M @ 1.5000%'
            ];
            $fees['tier_details'][] = [
                'fee' => $tier3_fee,
                'label' => 'Excess ' . number_format(($consideration - 50000000)/1000000, 1) . 'M @ 0.8000%'
            ];
            $total_brokerage = $tier1_fee + $tier2_fee + $tier3_fee;
        }
        
        $fees['brokerage'] = $total_brokerage;
        $fees['vat'] = $fees['brokerage'] * 0.18;
        $fees['cmsa'] = $consideration * (0.1400 / 100);
        $fees['dse'] = $consideration * (0.1652 / 100);
        $fees['fidelity'] = $consideration * (0.0200 / 100);
        $fees['csd'] = $consideration * (0.0708 / 100);
        
        $fees['total'] = $fees['brokerage'] + $fees['vat'] + $fees['cmsa'] + $fees['dse'] + $fees['fidelity'] + $fees['csd'];
    }
    
    return $fees;
}

// ============================================
// PDF EXPORT HANDLER - Updated for bonds
// ============================================
if (isset($_GET['export_pdf']) && isset($_GET['id'])) {
    // Clean output buffers
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    
    $sheet_id = (int) $_GET['id'];
    $sheet = dealingSheetGetById($db, $sheet_id);
    
    if (!$sheet) {
        die('Dealing sheet not found.');
    }
    
    $company_details = dealingSheetGetCompany($db);
    $company_name = $company_details['company_name'] ?? 'Victory Financial Services Ltd';
    
    // Calculate consideration
    $executed_qty = floatval($sheet['executed_quantity'] ?? 0);
    $executed_price = floatval($sheet['executed_price'] ?? 0);
    $order_qty = floatval($sheet['quantity'] ?? 0);
    $order_price = floatval($sheet['order_price'] ?? 0);
    $is_bond = ($sheet['asset_class'] === 'bond');
    
    if ($executed_qty > 0 && $executed_price > 0) {
        $quantity = $executed_qty;
        $price = $executed_price;
    } else {
        $quantity = $order_qty;
        $price = $order_price;
    }
    
    // Calculate consideration based on asset class
    if ($is_bond) {
        // For bonds: Consideration = (Price% / 100) × Face Value
        $consideration = ($price / 100) * $quantity;
    } else {
        // For equities/ETFs: Consideration = Quantity × Price
        $consideration = $quantity * $price;
    }
    
    $fees = calculateDealingSheetFees($sheet['asset_class'], $consideration, $quantity, $price);
    
    $pdf = new DealingSheetPDF('P', 'mm', 'A4', true, 'UTF-8', false);
    $pdf->setCompanyName($company_name);
    $pdf->setWatermarkEnabled(true);
    $pdf->SetCreator($company_name);
    $pdf->SetAuthor($sheet['dealer_name'] ?? 'System');
    $pdf->SetTitle('Dealing Sheet - ' . ($sheet['sheet_reference'] ?? ''));
    $pdf->SetMargins(25, 25, 25);
    $pdf->SetHeaderMargin(5);
    $pdf->SetFooterMargin(10);
    $pdf->SetAutoPageBreak(true, 20);
    
    $exportedByName = dealingSheetGetCurrentUserDisplayName($current_user);
    $pdf->addDealingSheet($sheet, $fees, $exportedByName);
    
    $filename = 'dealing_sheet_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $sheet['sheet_reference'] ?? 'export') . '.pdf';
    $pdf->Output($filename, 'I');
    exit;
}

// ============================================
// POST HANDLER FOR AJAX SAVE
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'save_order') {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    
    header('Content-Type: application/json');
    
    try {
        $data = $_POST;
        
        $required_fields = ['client_name', 'security_id', 'quantity', 'order_price'];
        foreach ($required_fields as $field) {
            if (empty($data[$field])) {
                throw new Exception('Missing required field: ' . $field);
            }
        }
        
        if (empty($data['id'])) {
            $prefix = 'DS' . date('Ymd');
            $stmt = $db->prepare("SELECT COUNT(*) FROM dealing_sheets WHERE sheet_reference LIKE ?");
            $stmt->execute([$prefix . '%']);
            $count = $stmt->fetchColumn() + 1;
            $data['sheet_reference'] = $prefix . str_pad($count, 4, '0', STR_PAD_LEFT);
        }
        
        // Map priority
        $priority_map = ['normal' => 'Normal', 'urgent' => 'Urgent', 'most_important' => 'Most Important'];
        $data['priority'] = $priority_map[$data['priority'] ?? 'normal'] ?? 'Normal';
        
        // Calculate order value based on asset class
        $qty = floatval($data['quantity']);
        $price = floatval($data['order_price']);
        $is_bond = ($data['asset_class'] ?? 'equity') === 'bond';
        
        if ($is_bond) {
            // For bonds: Value = (Price% / 100) × Face Value
            $data['order_value'] = ($price / 100) * $qty;
        } else {
            $data['order_value'] = $qty * $price;
        }
        
        // ============================================
        // NEW: Get additional_reference from form data
        // ============================================
        $additional_reference = isset($data['additional_reference']) ? trim($data['additional_reference']) : '';
        
        if (!empty($data['id'])) {
            // Update existing - ADDED additional_reference
            $sql = "UPDATE dealing_sheets SET 
                client_name = :client_name,
                client_cds_account = :client_cds_account,
                security_id = :security_id,
                security_name = :security_name,
                order_type = :order_type,
                asset_class = :asset_class,
                quantity = :quantity,
                order_price = :order_price,
                order_value = :order_value,
                order_date = :order_date,
                order_time = :order_time,
                priority = :priority,
                remarks = :remarks,
                broker_code = :broker_code,
                executed_quantity = :executed_quantity,
                executed_price = :executed_price,
                trade_date = :trade_date,
                settlement_date = :settlement_date,
                execution_time = :execution_time,
                additional_reference = :additional_reference,
                updated_at = NOW()
                WHERE id = :id";
            
            $stmt = $db->prepare($sql);
            $stmt->execute([
                ':id' => $data['id'],
                ':client_name' => $data['client_name'],
                ':client_cds_account' => $data['client_cds_account'] ?? '',
                ':security_id' => $data['security_id'],
                ':security_name' => $data['security_name'] ?? '',
                ':order_type' => $data['order_type'] ?? 'buy',
                ':asset_class' => $data['asset_class'] ?? 'equity',
                ':quantity' => $data['quantity'],
                ':order_price' => $data['order_price'],
                ':order_value' => $data['order_value'],
                ':order_date' => $data['order_date'] ?? date('Y-m-d'),
                ':order_time' => $data['order_time'] ?? date('H:i:s'),
                ':priority' => $data['priority'],
                ':remarks' => $data['remarks'] ?? '',
                ':broker_code' => $data['broker_code'] ?? '',
                ':executed_quantity' => !empty($data['executed_quantity']) ? $data['executed_quantity'] : null,
                ':executed_price' => !empty($data['executed_price']) ? $data['executed_price'] : null,
                ':trade_date' => !empty($data['trade_date']) ? $data['trade_date'] : null,
                ':settlement_date' => !empty($data['settlement_date']) ? $data['settlement_date'] : null,
                ':execution_time' => !empty($data['execution_time']) ? $data['execution_time'] : null,
                ':additional_reference' => $additional_reference
            ]);
        } else {
            // Insert new - ADDED additional_reference
            $sql = "INSERT INTO dealing_sheets (
                sheet_reference, client_name, client_cds_account, security_id, security_name,
                order_type, asset_class, quantity, order_price, order_value, order_date, order_time,
                priority, remarks, broker_code, executed_quantity, executed_price,
                trade_date, settlement_date, execution_time, lifecycle_stage, execution_status,
                additional_reference, recorded_at, created_at, dealer_name
            ) VALUES (
                :sheet_reference, :client_name, :client_cds_account, :security_id, :security_name,
                :order_type, :asset_class, :quantity, :order_price, :order_value, :order_date, :order_time,
                :priority, :remarks, :broker_code, :executed_quantity, :executed_price,
                :trade_date, :settlement_date, :execution_time, 'order', 'pending',
                :additional_reference, NOW(), NOW(), :dealer_name
            )";
            
            $stmt = $db->prepare($sql);
            $stmt->execute([
                ':sheet_reference' => $data['sheet_reference'],
                ':client_name' => $data['client_name'],
                ':client_cds_account' => $data['client_cds_account'] ?? '',
                ':security_id' => $data['security_id'],
                ':security_name' => $data['security_name'] ?? '',
                ':order_type' => $data['order_type'] ?? 'buy',
                ':asset_class' => $data['asset_class'] ?? 'equity',
                ':quantity' => $data['quantity'],
                ':order_price' => $data['order_price'],
                ':order_value' => $data['order_value'],
                ':order_date' => $data['order_date'] ?? date('Y-m-d'),
                ':order_time' => $data['order_time'] ?? date('H:i:s'),
                ':priority' => $data['priority'],
                ':remarks' => $data['remarks'] ?? '',
                ':broker_code' => $data['broker_code'] ?? '',
                ':executed_quantity' => !empty($data['executed_quantity']) ? $data['executed_quantity'] : null,
                ':executed_price' => !empty($data['executed_price']) ? $data['executed_price'] : null,
                ':trade_date' => !empty($data['trade_date']) ? $data['trade_date'] : null,
                ':settlement_date' => !empty($data['settlement_date']) ? $data['settlement_date'] : null,
                ':execution_time' => !empty($data['execution_time']) ? $data['execution_time'] : null,
                ':additional_reference' => $additional_reference,
                ':dealer_name' => dealingSheetGetCurrentUserDisplayName($current_user)
            ]);
        }
        
        echo json_encode(['success' => true, 'message' => 'Order saved successfully']);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// ============================================
// ORDER ACTION HANDLERS
// ============================================
if (isset($_GET['action']) && isset($_GET['id'])) {
    $sheet_id = (int) $_GET['id'];
    
    if ($_GET['action'] === 'delete_order') {
        $db->prepare("DELETE FROM dealing_sheets WHERE id = ?")->execute([$sheet_id]);
        $_SESSION['alert'] = ['Order deleted successfully.', 'success'];
        header('Location: dealing_sheet.php?view=' . urlencode($view));
        exit;
    }
    
    if ($_GET['action'] === 'cancel_order') {
        $db->prepare("UPDATE dealing_sheets SET lifecycle_stage = 'cancelled', execution_status = 'cancelled', updated_at = NOW() WHERE id = ?")->execute([$sheet_id]);
        $_SESSION['alert'] = ['Order cancelled successfully.', 'warning'];
        header('Location: dealing_sheet.php?view=' . urlencode($view));
        exit;
    }
    
    if ($_GET['action'] === 'mark_executed') {
        $db->prepare("UPDATE dealing_sheets SET lifecycle_stage = 'executed', execution_status = 'executed', updated_at = NOW() WHERE id = ?")->execute([$sheet_id]);
        $_SESSION['alert'] = ['Order marked as executed successfully.', 'success'];
        header('Location: dealing_sheet.php?view=' . urlencode($view));
        exit;
    }
}

// ============================================
// AJAX HANDLERS FOR SEARCH
// ============================================
if (isset($_GET['ajax_action'])) {
    header('Content-Type: application/json');
    
    if ($_GET['ajax_action'] === 'get_securities') {
        $asset_class = $_GET['asset_class'] ?? 'equity';
        $search = $_GET['search'] ?? '';
        
        try {
            if ($asset_class === 'equity') {
                $sql = "SELECT security_id, stock_name as security_name, company_name, sector FROM equities WHERE status = 'active'";
                if ($search) $sql .= " AND (security_id LIKE :search OR stock_name LIKE :search)";
                $sql .= " LIMIT 50";
            } elseif ($asset_class === 'bond') {
                $sql = "SELECT security_id, bond_name as security_name, issuer, coupon_rate, maturity_date FROM bonds WHERE status = 'active'";
                if ($search) $sql .= " AND (security_id LIKE :search OR bond_name LIKE :search)";
                $sql .= " LIMIT 50";
            } else {
                $sql = "SELECT etf_code as security_id, name as security_name FROM etf WHERE status = 'active'";
                if ($search) $sql .= " AND (etf_code LIKE :search OR name LIKE :search)";
                $sql .= " LIMIT 50";
            }
            
            $stmt = $db->prepare($sql);
            if ($search) $stmt->bindValue(':search', "%$search%");
            $stmt->execute();
            echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
        } catch (Exception $e) {
            echo json_encode([]);
        }
        exit;
    }
    
    if ($_GET['ajax_action'] === 'search_clients') {
        $search = $_GET['search'] ?? '';
        try {
            $stmt = $db->prepare("SELECT DISTINCT client_name, client_cds_account FROM trades WHERE client_name LIKE :search LIMIT 30");
            $stmt->bindValue(':search', "%$search%");
            $stmt->execute();
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (empty($results)) {
                $stmt = $db->prepare("SELECT client_name, cds_account as client_cds_account FROM clients WHERE client_name LIKE :search LIMIT 30");
                $stmt->bindValue(':search', "%$search%");
                $stmt->execute();
                $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
            echo json_encode($results);
        } catch (Exception $e) {
            echo json_encode([]);
        }
        exit;
    }
}

// ============================================
// GET DATA FOR DISPLAY
// ============================================

// Simple list function if not in helper
if (!function_exists('dealingSheetList')) {
    function dealingSheetList($db, $filters) {
        $sql = "SELECT * FROM dealing_sheets ORDER BY 
            CASE priority 
                WHEN 'Most Important' THEN 1 
                WHEN 'Urgent' THEN 2 
                ELSE 3 
            END,
            created_at DESC";
        
        // Apply basic filters
        if ($filters['view'] === 'orders') {
            $sql = "SELECT * FROM dealing_sheets WHERE lifecycle_stage = 'order' OR execution_status = 'pending' ORDER BY created_at DESC";
        } elseif ($filters['view'] === 'execution') {
            $sql = "SELECT * FROM dealing_sheets WHERE lifecycle_stage = 'execution' OR execution_status = 'executed' ORDER BY created_at DESC";
        } elseif ($filters['view'] === 'approved') {
            $sql = "SELECT * FROM dealing_sheets WHERE lifecycle_stage = 'approved' ORDER BY created_at DESC";
        } elseif ($filters['view'] === 'settled') {
            $sql = "SELECT * FROM dealing_sheets WHERE lifecycle_stage = 'settled' ORDER BY created_at DESC";
        }
        
        return $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('dealingSheetOverview')) {
    function dealingSheetOverview($db) {
        $total = $db->query("SELECT COUNT(*) FROM dealing_sheets")->fetchColumn();
        $orders = $db->query("SELECT COUNT(*) FROM dealing_sheets WHERE lifecycle_stage = 'order' OR execution_status = 'pending'")->fetchColumn();
        $execution = $db->query("SELECT COUNT(*) FROM dealing_sheets WHERE lifecycle_stage = 'execution' OR execution_status = 'executed'")->fetchColumn();
        $settled = $db->query("SELECT COUNT(*) FROM dealing_sheets WHERE lifecycle_stage = 'settled'")->fetchColumn();
        $total_executed_value = $db->query("SELECT COALESCE(SUM(executed_value), 0) FROM dealing_sheets WHERE execution_status = 'executed'")->fetchColumn();
        
        return [
            'total' => $total,
            'orders' => $orders,
            'execution' => $execution,
            'settled' => $settled,
            'total_executed_value' => $total_executed_value
        ];
    }
}

if (!function_exists('dealingSheetGetById')) {
    function dealingSheetGetById($db, $id) {
        $stmt = $db->prepare("SELECT * FROM dealing_sheets WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('dealingSheetGetCurrentUserDisplayName')) {
    function dealingSheetGetCurrentUserDisplayName($user) {
        if (isset($user['first_name']) && isset($user['last_name'])) {
            return $user['first_name'] . ' ' . $user['last_name'];
        }
        return $user['username'] ?? 'System User';
    }
}

$filters = [
    'view' => $view,
    'search' => $_GET['search'] ?? '',
    'stage' => $_GET['stage'] ?? 'all',
    'asset_class' => $_GET['asset_class'] ?? 'all',
    'payment_status' => $_GET['payment_status'] ?? 'all',
    'date_from' => $_GET['date_from'] ?? '',
    'date_to' => $_GET['date_to'] ?? '',
];
$sheets = dealingSheetList($db, $filters);
$overview = dealingSheetOverview($db);

$page_title = $view === 'orders' ? 'Order Intake Sheet' : 'Dealing Sheet Lifecycle';
include '../includes/header.php';
?>

<style>
    .modal-xl { max-width: 900px; }
    .priority-Normal { background-color: #e8f5e9; }
    .priority-Urgent { background-color: #fff3e0; border-left: 4px solid #ff9800 !important; }
    .priority-Most\ Important { background-color: #ffebee; border-left: 4px solid #f44336 !important; }
    .old-order { background-color: #ffcdd2 !important; color: #c62828 !important; }
    .old-order td { color: #c62828 !important; }
    .client-search-dropdown, .security-search-dropdown {
        position: absolute;
        background: white;
        border: 1px solid #ddd;
        max-height: 250px;
        overflow-y: auto;
        z-index: 10000;
        width: 100%;
        display: none;
        border-radius: 4px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.15);
    }
    .client-search-dropdown div, .security-search-dropdown div {
        padding: 10px 12px;
        cursor: pointer;
        border-bottom: 1px solid #eee;
    }
    .client-search-dropdown div:hover, .security-search-dropdown div:hover {
        background-color: #f0f0f0;
    }
    .security-info { font-size: 11px; color: #6c757d; margin-top: 4px; }
    .position-relative { position: relative; }
    .btn-group-sm .btn { padding: 0.25rem 0.5rem; font-size: 0.75rem; }
    .table-responsive { overflow-x: auto; }
    .bond-indicator { 
        background-color: #e3f2fd; 
        border-left: 3px solid #1976d2 !important;
    }
    .additional-ref-badge {
        background-color: #e8f5e9;
        color: #2e7d32;
        padding: 2px 8px;
        border-radius: 4px;
        font-size: 11px;
        border: 1px solid #a5d6a7;
    }
</style>

<!-- Modal for Order Entry -->
<div class="modal fade" id="orderModal" tabindex="-1" aria-labelledby="orderModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="bi bi-plus-circle me-2"></i>New Order</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="orderForm">
                <div class="modal-body">
                    <input type="hidden" name="id" id="order_id" value="">
                    <input type="hidden" name="ajax_action" value="save_order">
                    
                    <div class="row g-3">
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">Order Type <span class="text-danger">*</span></label>
                            <select class="form-select" name="order_type" id="order_type" required>
                                <option value="buy">BUY</option>
                                <option value="sell">SELL</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">Priority</label>
                            <select class="form-select" name="priority" id="priority">
                                <option value="normal">Normal</option>
                                <option value="urgent">Urgent</option>
                                <option value="most_important">Most Important</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">Asset Class <span class="text-danger">*</span></label>
                            <select class="form-select" name="asset_class" id="asset_class" required>
                                <option value="equity">Equity / Shares</option>
                                <option value="bond">Bond</option>
                                <option value="etf">ETF</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">Order Date</label>
                            <input type="date" class="form-control" name="order_date" id="order_date" value="<?php echo date('Y-m-d'); ?>">
                        </div>
                        
                        <div class="col-md-6 position-relative">
                            <label class="form-label fw-semibold">Client <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="client_search" placeholder="Type to search..." autocomplete="off">
                            <input type="hidden" name="client_name" id="client_name">
                            <input type="hidden" name="client_cds_account" id="client_cds_account">
                            <div id="client_search_dropdown" class="client-search-dropdown"></div>
                        </div>
                        
                        <div class="col-md-6 position-relative">
                            <label class="form-label fw-semibold">Security <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="security_search" placeholder="Type to search..." autocomplete="off">
                            <input type="hidden" name="security_id" id="security_id">
                            <input type="hidden" name="security_name" id="security_name">
                            <div id="security_search_dropdown" class="security-search-dropdown"></div>
                            <div id="security_info" class="security-info"></div>
                        </div>
                        
                        <div class="col-md-3" id="quantity_field">
                            <label class="form-label fw-semibold" id="quantity_label">Quantity <span class="text-danger">*</span></label>
                            <input type="number" class="form-control" name="quantity" id="quantity" step="1" min="1" required>
                            <small class="text-muted" id="quantity_hint">Number of shares</small>
                        </div>
                        <div class="col-md-3" id="price_field">
                            <label class="form-label fw-semibold" id="price_label">Price (TZS) <span class="text-danger">*</span></label>
                            <input type="number" class="form-control" name="order_price" id="order_price" step="0.01" min="0" required>
                            <small class="text-muted" id="price_hint">Price per share in TZS</small>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">Order Time</label>
                            <input type="time" class="form-control" name="order_time" id="order_time" value="<?php echo date('H:i:s'); ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">Broker Code</label>
                            <input class="form-control" name="broker_code" id="broker_code" value="<?php echo htmlspecialchars($company['company_code'] ?? ''); ?>">
                        </div>
                        
                        <!-- NEW: Additional Reference Field -->
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Additional Reference</label>
                            <input type="text" class="form-control" name="additional_reference" id="additional_reference" 
                                   placeholder="Enter reference number or MTP..." maxlength="100">
                            <small class="text-muted">Optional: Can contain a number, be empty, or contain "MTP"</small>
                        </div>
                        
                        <div class="col-12">
                            <hr>
                            <h6 class="fw-semibold">Execution Details (Optional)</h6>
                            <div class="row g-3">
                                <div class="col-md-3">
                                    <label class="form-label" id="exec_qty_label">Executed Quantity</label>
                                    <input type="number" class="form-control" name="executed_quantity" id="executed_quantity" step="1" min="0">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label" id="exec_price_label">Executed Price</label>
                                    <input type="number" class="form-control" name="executed_price" id="executed_price" step="0.01" min="0">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Trade Date</label>
                                    <input type="date" class="form-control" name="trade_date" id="trade_date" value="<?php echo date('Y-m-d'); ?>">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Settlement Date</label>
                                    <input type="date" class="form-control" name="settlement_date" id="settlement_date" value="<?php echo date('Y-m-d', strtotime('+2 days')); ?>">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Execution Time</label>
                                    <input type="time" class="form-control" name="execution_time" id="execution_time" value="<?php echo date('H:i:s'); ?>">
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-12">
                            <label class="form-label">Remarks</label>
                            <textarea class="form-control" name="remarks" id="remarks" rows="2"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Order</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="page-header">
    <div class="container-fluid">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
            <div>
                <h1 class="page-title mb-1"><?php echo htmlspecialchars($page_title); ?></h1>
                <p class="page-subtitle mb-0">Capture orders, execute trades, and track settlement in one workflow.</p>
            </div>
            <div class="d-flex gap-2 flex-wrap">
                <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#orderModal" onclick="resetOrderForm()">
                    <i class="bi bi-plus-circle me-2"></i>New Order
                </button>
                <a href="trades.php" class="btn btn-outline-dark"><i class="bi bi-list-ul me-2"></i>All Trades</a>
            </div>
        </div>
    </div>
</div>

<div class="container-fluid">
    <?php if (isset($_SESSION['alert'])): ?>
        <div class="alert alert-<?php echo $_SESSION['alert'][1]; ?> alert-dismissible fade show"><?php echo htmlspecialchars($_SESSION['alert'][0]); ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
        <?php unset($_SESSION['alert']); ?>
    <?php endif; ?>

    <div class="d-flex flex-wrap gap-2 mb-4">
        <?php foreach (['all' => 'All Sheets', 'orders' => 'Order Intake', 'execution' => 'Execution Queue', 'approved' => 'Approved', 'settled' => 'Settled'] as $viewKey => $viewLabel): ?>
            <a href="dealing_sheet.php?view=<?php echo urlencode($viewKey); ?>" class="btn <?php echo $view === $viewKey ? 'btn-primary' : 'btn-outline-secondary'; ?>"><?php echo htmlspecialchars($viewLabel); ?></a>
        <?php endforeach; ?>
    </div>

    <div class="row g-4 mb-4">
        <div class="col-lg-3 col-md-6"><div class="card bg-primary text-white"><div class="card-body"><div class="small">Total Sheets</div><div class="fs-3 fw-bold"><?php echo number_format($overview['total']); ?></div></div></div></div>
        <div class="col-lg-3 col-md-6"><div class="card bg-warning text-dark"><div class="card-body"><div class="small">Open Orders</div><div class="fs-3 fw-bold"><?php echo number_format($overview['orders']); ?></div></div></div></div>
        <div class="col-lg-3 col-md-6"><div class="card bg-info text-white"><div class="card-body"><div class="small">Awaiting Review</div><div class="fs-3 fw-bold"><?php echo number_format($overview['execution']); ?></div></div></div></div>
        <div class="col-lg-3 col-md-6"><div class="card bg-success text-white"><div class="card-body"><div class="small">Settled</div><div class="fs-3 fw-bold"><?php echo number_format($overview['settled']); ?></div></div></div></div>
    </div>

    <div class="card dashboard-card">
        <div class="card-header bg-transparent border-0 pb-0">
            <h6 class="mb-1 fw-semibold">Order Register</h6>
            <div class="small text-muted"><?php echo number_format(count($sheets)); ?> orders matching current view</div>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead>
                        <tr>
                            <th>Reference</th>
                            <th>Priority</th>
                            <th>Client</th>
                            <th>Security</th>
                            <th>Asset Class</th>
                            <th class="text-end">Qty</th>
                            <th class="text-end">Price</th>
                            <th class="text-end">Value</th>
                            <th>Date</th>
                            <th>Additional Ref</th>
                            <th>Status</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($sheets)): ?>
                            <tr><td colspan="12" class="text-center py-5 text-muted">No orders found</td></tr>
                        <?php else: ?>
                            <?php foreach ($sheets as $sheet): 
                                $isOldOrder = strtotime($sheet['order_date'] ?? '') < strtotime(date('Y-m-d'));
                                $isBond = ($sheet['asset_class'] ?? '') === 'bond';
                                $rowClass = '';
                                if ($isOldOrder) $rowClass = 'old-order';
                                elseif (($sheet['priority'] ?? '') === 'Urgent') $rowClass = 'priority-Urgent';
                                elseif (($sheet['priority'] ?? '') === 'Most Important') $rowClass = 'priority-Most Important';
                                if ($isBond) $rowClass .= ' bond-indicator';
                                
                                // Format based on asset class
                                if ($isBond) {
                                    $displayQty = 'TZS ' . number_format(floatval($sheet['quantity'] ?? 0), 2);
                                    $displayPrice = number_format(floatval($sheet['order_price'] ?? 0), 4) . '%';
                                    $displayValue = 'TZS ' . number_format((floatval($sheet['order_price'] ?? 0) / 100) * floatval($sheet['quantity'] ?? 0), 2);
                                    $assetClassDisplay = '<span class="badge bg-info">Bond</span>';
                                } else {
                                    $displayQty = number_format(floatval($sheet['quantity'] ?? 0), 0);
                                    $displayPrice = 'TZS ' . number_format(floatval($sheet['order_price'] ?? 0), 2);
                                    $displayValue = 'TZS ' . number_format(floatval($sheet['order_value'] ?? 0), 2);
                                    $assetClassDisplay = '<span class="badge bg-secondary">' . ucfirst($sheet['asset_class'] ?? 'Equity') . '</span>';
                                }
                                
                                // Display Additional Reference
                                $additionalRef = !empty($sheet['additional_reference']) ? 
                                    '<span class="additional-ref-badge">' . htmlspecialchars($sheet['additional_reference']) . '</span>' : 
                                    '<span class="text-muted">-</span>';
                            ?>
                                <tr class="<?php echo $rowClass; ?>">
                                    <td><span class="fw-semibold"><?php echo htmlspecialchars($sheet['sheet_reference'] ?? 'N/A'); ?></span></td>
                                    <td><?php echo htmlspecialchars($sheet['priority'] ?? 'Normal'); ?></td>
                                    <td><?php echo htmlspecialchars($sheet['client_name'] ?? ''); ?></td>
                                    <td><?php echo htmlspecialchars($sheet['security_id'] ?? ''); ?></td>
                                    <td><?php echo $assetClassDisplay; ?></td>
                                    <td class="text-end"><?php echo $displayQty; ?></td>
                                    <td class="text-end"><?php echo $displayPrice; ?></td>
                                    <td class="text-end"><?php echo $displayValue; ?></td>
                                    <td><?php echo htmlspecialchars($sheet['order_date'] ?? ''); ?></td>
                                    <td><?php echo $additionalRef; ?></td>
                                    <td>
                                        <?php if (($sheet['execution_status'] ?? '') === 'executed'): ?>
                                            <span class="badge bg-success">Executed</span>
                                        <?php elseif (($sheet['lifecycle_stage'] ?? '') === 'cancelled'): ?>
                                            <span class="badge bg-secondary">Cancelled</span>
                                        <?php else: ?>
                                            <span class="badge bg-warning">Pending</span>
                                        <?php endif; ?>
                                        <?php if ($isBond): ?>
                                            <span class="badge bg-info">Bond</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end">
                                        <div class="btn-group btn-group-sm">
                                            <button class="btn btn-outline-primary" onclick='editOrder(<?php echo json_encode($sheet); ?>)'><i class="bi bi-pencil"></i></button>
                                            <a href="dealing_sheet.php?export_pdf=1&id=<?php echo $sheet['id']; ?>" target="_blank" class="btn btn-outline-danger btn-sm">
                                                <i class="bi bi-file-pdf"></i>
                                            </a>
                                            <button class="btn btn-outline-warning" onclick="confirmAction(<?php echo $sheet['id']; ?>, 'cancel_order')"><i class="bi bi-x-circle"></i></button>
                                            <button class="btn btn-outline-success" onclick="confirmAction(<?php echo $sheet['id']; ?>, 'mark_executed')"><i class="bi bi-check-circle"></i></button>
                                            <button class="btn btn-outline-danger" onclick="confirmAction(<?php echo $sheet['id']; ?>, 'delete_order')"><i class="bi bi-trash"></i></button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
let securityData = [];

function resetOrderForm() {
    document.getElementById('orderForm').reset();
    document.getElementById('order_id').value = '';
    document.getElementById('client_name').value = '';
    document.getElementById('client_cds_account').value = '';
    document.getElementById('client_search').value = '';
    document.getElementById('security_id').value = '';
    document.getElementById('security_name').value = '';
    document.getElementById('security_search').value = '';
    document.getElementById('security_info').innerHTML = '';
    document.getElementById('additional_reference').value = ''; // NEW: Reset additional reference
    document.getElementById('order_date').value = '<?php echo date('Y-m-d'); ?>';
    document.getElementById('trade_date').value = '<?php echo date('Y-m-d'); ?>';
    document.getElementById('settlement_date').value = '<?php echo date('Y-m-d', strtotime('+2 days')); ?>';
    hideDropdowns();
    updateFieldsForAssetClass();
}

function hideDropdowns() {
    const clientDropdown = document.getElementById('client_search_dropdown');
    const securityDropdown = document.getElementById('security_search_dropdown');
    if (clientDropdown) clientDropdown.style.display = 'none';
    if (securityDropdown) securityDropdown.style.display = 'none';
}

function editOrder(sheet) {
    resetOrderForm();
    document.getElementById('order_id').value = sheet.id || '';
    document.getElementById('client_name').value = sheet.client_name || '';
    document.getElementById('client_cds_account').value = sheet.client_cds_account || '';
    document.getElementById('client_search').value = sheet.client_name || '';
    document.getElementById('security_id').value = sheet.security_id || '';
    document.getElementById('security_name').value = sheet.security_name || '';
    document.getElementById('security_search').value = sheet.security_name || '';
    document.getElementById('order_type').value = sheet.order_type || 'buy';
    document.getElementById('asset_class').value = sheet.asset_class || 'equity';
    // NEW: Set additional reference
    document.getElementById('additional_reference').value = sheet.additional_reference || '';
    let priorityVal = 'normal';
    if (sheet.priority === 'Urgent') priorityVal = 'urgent';
    else if (sheet.priority === 'Most Important') priorityVal = 'most_important';
    document.getElementById('priority').value = priorityVal;
    document.getElementById('quantity').value = sheet.quantity || '';
    document.getElementById('order_price').value = sheet.order_price || '';
    document.getElementById('order_date').value = sheet.order_date || '<?php echo date('Y-m-d'); ?>';
    document.getElementById('order_time').value = sheet.order_time || '<?php echo date('H:i:s'); ?>';
    document.getElementById('executed_quantity').value = sheet.executed_quantity || '';
    document.getElementById('executed_price').value = sheet.executed_price || '';
    document.getElementById('trade_date').value = sheet.trade_date || '<?php echo date('Y-m-d'); ?>';
    document.getElementById('settlement_date').value = sheet.settlement_date || '<?php echo date('Y-m-d', strtotime('+2 days')); ?>';
    document.getElementById('execution_time').value = sheet.execution_time || '<?php echo date('H:i:s'); ?>';
    document.getElementById('remarks').value = sheet.remarks || '';
    document.getElementById('broker_code').value = sheet.broker_code || '<?php echo htmlspecialchars($company['company_code'] ?? ''); ?>';
    
    updateFieldsForAssetClass();
    const modal = new bootstrap.Modal(document.getElementById('orderModal'));
    modal.show();
}

function confirmAction(id, action) {
    let msg = '';
    if (action === 'delete_order') msg = 'Delete this order? This cannot be undone.';
    else if (action === 'cancel_order') msg = 'Cancel this order?';
    else if (action === 'mark_executed') msg = 'Mark this order as executed?';
    if (confirm(msg)) {
        window.location.href = 'dealing_sheet.php?action=' + action + '&id=' + id + '&view=' + encodeURIComponent('<?php echo $view; ?>');
    }
}

// ============================================
// ASSET CLASS FIELD UPDATES
// ============================================
function updateFieldsForAssetClass() {
    const assetClass = document.getElementById('asset_class').value;
    const isBond = assetClass === 'bond';
    
    const quantityLabel = document.getElementById('quantity_label');
    const priceLabel = document.getElementById('price_label');
    const quantityInput = document.getElementById('quantity');
    const priceInput = document.getElementById('order_price');
    const quantityHint = document.getElementById('quantity_hint');
    const priceHint = document.getElementById('price_hint');
    const execQtyLabel = document.getElementById('exec_qty_label');
    const execPriceLabel = document.getElementById('exec_price_label');
    
    if (isBond) {
        quantityLabel.innerHTML = 'Face Value (TZS) <span class="text-danger">*</span>';
        priceLabel.innerHTML = 'Price (% of Par) <span class="text-danger">*</span>';
        quantityInput.placeholder = 'e.g., 100000000';
        quantityInput.step = '0.01';
        priceInput.placeholder = 'e.g., 98.5000';
        priceInput.step = '0.0001';
        quantityHint.textContent = 'Face value in TZS';
        priceHint.textContent = 'Percentage of par value (e.g., 98.5 = 98.5% of face value)';
        execQtyLabel.textContent = 'Executed Face Value (TZS)';
        execPriceLabel.textContent = 'Executed Price (% of Par)';
        document.getElementById('executed_quantity').step = '0.01';
        document.getElementById('executed_price').step = '0.0001';
    } else {
        quantityLabel.innerHTML = 'Quantity <span class="text-danger">*</span>';
        priceLabel.innerHTML = 'Price (TZS) <span class="text-danger">*</span>';
        quantityInput.placeholder = 'e.g., 1000';
        quantityInput.step = '1';
        priceInput.placeholder = 'e.g., 2500';
        priceInput.step = '0.01';
        quantityHint.textContent = 'Number of shares';
        priceHint.textContent = 'Price per share in TZS';
        execQtyLabel.textContent = 'Executed Quantity';
        execPriceLabel.textContent = 'Executed Price (TZS)';
        document.getElementById('executed_quantity').step = '1';
        document.getElementById('executed_price').step = '0.01';
    }
}

// Update fields when asset class changes
document.getElementById('asset_class')?.addEventListener('change', updateFieldsForAssetClass);

// Client search
document.getElementById('client_search')?.addEventListener('input', function() {
    const search = this.value;
    if (search.length < 2) { document.getElementById('client_search_dropdown').style.display = 'none'; return; }
    fetch('dealing_sheet.php?ajax_action=search_clients&search=' + encodeURIComponent(search))
        .then(r => r.json()).then(data => {
            const dropdown = document.getElementById('client_search_dropdown');
            dropdown.innerHTML = '';
            if (data.length) {
                data.forEach(c => {
                    const div = document.createElement('div');
                    div.innerHTML = `<strong>${escapeHtml(c.client_name)}</strong><br><small>CDS: ${escapeHtml(c.client_cds_account)}</small>`;
                    div.onclick = () => {
                        document.getElementById('client_name').value = c.client_name;
                        document.getElementById('client_cds_account').value = c.client_cds_account;
                        document.getElementById('client_search').value = c.client_name;
                        dropdown.style.display = 'none';
                    };
                    dropdown.appendChild(div);
                });
                dropdown.style.display = 'block';
            } else dropdown.style.display = 'none';
        });
});

// Security search
document.getElementById('security_search')?.addEventListener('input', function() {
    const search = this.value;
    const assetClass = document.getElementById('asset_class').value;
    if (search.length < 2) { document.getElementById('security_search_dropdown').style.display = 'none'; return; }
    fetch('dealing_sheet.php?ajax_action=get_securities&asset_class=' + encodeURIComponent(assetClass) + '&search=' + encodeURIComponent(search))
        .then(r => r.json()).then(data => {
            const dropdown = document.getElementById('security_search_dropdown');
            dropdown.innerHTML = '';
            if (data.length) {
                data.forEach(s => {
                    const div = document.createElement('div');
                    div.innerHTML = `<strong>${escapeHtml(s.security_id)}</strong> - ${escapeHtml(s.security_name)}`;
                    div.onclick = () => {
                        document.getElementById('security_id').value = s.security_id;
                        document.getElementById('security_name').value = s.security_name;
                        document.getElementById('security_search').value = s.security_name;
                        dropdown.style.display = 'none';
                        let info = '';
                        if (s.company_name) info += `<strong>Company:</strong> ${escapeHtml(s.company_name)}<br>`;
                        if (s.coupon_rate) info += `<strong>Coupon:</strong> ${s.coupon_rate}%<br>`;
                        if (s.maturity_date) info += `<strong>Maturity:</strong> ${s.maturity_date}<br>`;
                        if (s.issuer) info += `<strong>Issuer:</strong> ${escapeHtml(s.issuer)}<br>`;
                        document.getElementById('security_info').innerHTML = info;
                    };
                    dropdown.appendChild(div);
                });
                dropdown.style.display = 'block';
            } else dropdown.style.display = 'none';
        });
});

// Form submit
document.getElementById('orderForm')?.addEventListener('submit', function(e) {
    e.preventDefault();
    const btn = this.querySelector('button[type="submit"]');
    const original = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="bi bi-hourglass-split"></i> Saving...';
    fetch(window.location.href, { method: 'POST', body: new FormData(this) })
        .then(r => r.json()).then(data => {
            if (data.success) {
                bootstrap.Modal.getInstance(document.getElementById('orderModal'))?.hide();
                location.reload();
            } else alert('Error: ' + data.message);
        }).catch(err => alert('Error saving order')).finally(() => {
            btn.disabled = false;
            btn.innerHTML = original;
        });
});

function escapeHtml(text) { if (!text) return ''; return text.replace(/[&<>]/g, function(m) { if (m === '&') return '&amp;'; if (m === '<') return '&lt;'; if (m === '>') return '&gt;'; return m; }); }

document.addEventListener('click', function(e) {
    if (!document.getElementById('client_search')?.contains(e.target)) document.getElementById('client_search_dropdown').style.display = 'none';
    if (!document.getElementById('security_search')?.contains(e.target)) document.getElementById('security_search_dropdown').style.display = 'none';
});

// Initialize fields on page load
document.addEventListener('DOMContentLoaded', function() {
    updateFieldsForAssetClass();
});
</script>

<?php include '../includes/footer.php'; ?>
