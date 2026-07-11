<?php
/**
 * Export Dealing Sheet as PDF
 * Separate file to avoid conflicts with main page
 * 
 * Usage: export_dealing_sheet_pdf.php?id=123
 */

// Error reporting for debugging (disable in production)
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/pdf_export_errors.log');

// Clean all output buffers immediately
while (ob_get_level() > 0) {
    ob_end_clean();
}

// Check if ID is provided
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    die('Invalid request. No dealing sheet ID provided.');
}

$sheet_id = (int) $_GET['id'];

// Load required files
require_once '../config/config.php';
require_once '../auth/auth_middleware.php';
require_once '../includes/dealing_sheet_helpers.php';
require_once '../tcpdf/tcpdf.php';
require_once __DIR__ . '/../reports/traits/ReportHeaderTrait.php';

// Verify user is logged in
require_trader();
require_mandate();

$db = getDBConnection();
$current_user = get_logged_in_user() ?: get_session_user();

// Get the dealing sheet data
function getDealingSheetById($db, $id) {
    $stmt = $db->prepare("SELECT * FROM dealing_sheets WHERE id = ?");
    $stmt->execute([$id]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

$sheet = getDealingSheetById($db, $sheet_id);

if (!$sheet) {
    die('Dealing sheet not found.');
}

// Get company details
function getCompanyDetails($db) {
    $stmt = $db->query("SELECT company_name, company_code, address, phone, email FROM companies LIMIT 1");
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: ['company_name' => 'NEOVAM LTD', 'company_code' => 'NVM'];
}

$company = getCompanyDetails($db);
$company_name = $company['company_name'] ?? 'Neovam Ltd';
$company_code = $company['company_code'] ?? 'NVM';

// Get user display name
function getUserDisplayName($user) {
    if (isset($user['first_name']) && isset($user['last_name'])) {
        return $user['first_name'] . ' ' . $user['last_name'];
    }
    return $user['username'] ?? 'System User';
}
$exportedByName = getUserDisplayName($current_user);

// ============================================
// FEES CALCULATION - UPDATED FOR BONDS
// ============================================
function calculateFees($asset_class, $consideration, $quantity, $price) {
    $fees = [
        'brokerage' => 0,
        'vat' => 0,
        'cmsa' => 0,
        'dse' => 0,
        'fidelity' => 0,
        'csd' => 0,
        'total' => 0,
        'tier_details' => []
    ];
    
    $is_bond = ($asset_class === 'bond');
    
    if ($is_bond) {
        // BOND FEES - based on FACE VALUE
        $face_value = $quantity;
        
        // Brokerage: 0.063132% on first 100M, 0.035% on excess
        $brokerage_first = min($face_value, 100000000) * (0.063132 / 100);
        $brokerage_excess = max($face_value - 100000000, 0) * (0.035 / 100);
        $fees['brokerage'] = $brokerage_first + $brokerage_excess;
        
        // Add tier details
        if ($face_value <= 100000000) {
            $fees['tier_details'][] = [
                'fee' => $brokerage_first,
                'label' => number_format($face_value/1000000, 2) . 'M @ 0.063132%'
            ];
        } else {
            $fees['tier_details'][] = ['fee' => $brokerage_first, 'label' => 'First 100M @ 0.063132%'];
            $fees['tier_details'][] = ['fee' => $brokerage_excess, 'label' => 'Excess ' . number_format(($face_value - 100000000)/1000000, 2) . 'M @ 0.035%'];
        }
        
        $fees['vat'] = $fees['brokerage'] * 0.18;
        $fees['cmsa'] = $consideration * (0.0100 / 100);
        $fees['csd'] = $face_value * (0.0118 / 100);
        $fees['dse'] = $face_value * (0.02006 / 100);
        $fees['fidelity'] = 0;
        
    } else {
        // EQUITY/ETF FEES - based on CONSIDERATION
        if ($consideration <= 10000000) {
            $fees['brokerage'] = $consideration * (1.70 / 100);
            $fees['tier_details'][] = ['fee' => $fees['brokerage'], 'label' => 'Up to 10M @ 1.7000%'];
        } elseif ($consideration <= 50000000) {
            $tier1 = 10000000 * (1.70 / 100);
            $tier2 = ($consideration - 10000000) * (1.50 / 100);
            $fees['brokerage'] = $tier1 + $tier2;
            $fees['tier_details'][] = ['fee' => $tier1, 'label' => 'First 10M @ 1.7000%'];
            $fees['tier_details'][] = ['fee' => $tier2, 'label' => 'Next ' . number_format(($consideration - 10000000)/1000000, 1) . 'M @ 1.5000%'];
        } else {
            $tier1 = 10000000 * (1.70 / 100);
            $tier2 = 40000000 * (1.50 / 100);
            $tier3 = ($consideration - 50000000) * (0.80 / 100);
            $fees['brokerage'] = $tier1 + $tier2 + $tier3;
            $fees['tier_details'][] = ['fee' => $tier1, 'label' => 'First 10M @ 1.7000%'];
            $fees['tier_details'][] = ['fee' => $tier2, 'label' => 'Next 40M @ 1.5000%'];
            $fees['tier_details'][] = ['fee' => $tier3, 'label' => 'Excess ' . number_format(($consideration - 50000000)/1000000, 1) . 'M @ 0.8000%'];
        }
        
        $fees['vat'] = $fees['brokerage'] * 0.18;
        $fees['cmsa'] = $consideration * (0.14 / 100);
        $fees['dse'] = $consideration * (0.1652 / 100);
        $fees['fidelity'] = $consideration * (0.02 / 100);
        $fees['csd'] = $consideration * (0.0708 / 100);
    }
    
    $fees['total'] = $fees['brokerage'] + $fees['vat'] + $fees['cmsa'] + $fees['dse'] + $fees['fidelity'] + $fees['csd'];
    
    return $fees;
}

// ============================================
// CALCULATE VALUES - UPDATED FOR BONDS
// ============================================
$executed_qty = floatval($sheet['executed_quantity'] ?? 0);
$executed_price = floatval($sheet['executed_price'] ?? 0);
$order_qty = floatval($sheet['quantity'] ?? 0);
$order_price = floatval($sheet['order_price'] ?? 0);
$is_bond = ($sheet['asset_class'] === 'bond');

// Determine which values to use (executed or order)
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
    $executed_value = ($executed_qty > 0 && $executed_price > 0) ? (($executed_price / 100) * $executed_qty) : 0;
} else {
    // For equities/ETFs: Consideration = Quantity × Price
    $consideration = $quantity * $price;
    $executed_value = ($executed_qty > 0 && $executed_price > 0) ? ($executed_qty * $executed_price) : 0;
}

// Calculate fees
$fees = calculateFees($sheet['asset_class'], $consideration, $quantity, $price);
$trade_side = strtoupper($sheet['order_type'] ?? 'BUY');
$is_sell = ($trade_side === 'SELL');

// Calculate net amount
if ($is_sell) {
    $net_amount = $consideration - $fees['total'];
    $net_label = 'NET AMOUNT RECEIVABLE';
} else {
    $net_amount = $consideration + $fees['total'];
    $net_label = 'NET AMOUNT PAYABLE';
}

// ============================================
// PDF CLASS
// ============================================
class DealingSheetPDF extends TCPDF {
    use ReportHeaderTrait;
    
    private $company_name = '';
    
    public function setCompanyName($name) {
        $this->company_name = $name;
    }
    
    public function Header() {
        $this->renderReportHeader();
    }
    
    public function Footer() {
        $this->SetY(-15);
        $this->SetFont('helvetica', 'I', 7);
        $this->SetTextColor(100, 100, 100);
        $this->Cell(0, 5, 'Generated: ' . date('d/m/Y H:i:s'), 0, 0, 'L');
        $this->Cell(0, 5, 'Page ' . $this->getAliasNumPage() . ' of ' . $this->getAliasNbPages(), 0, 0, 'R');
    }
}

// Create PDF
$pdf = new DealingSheetPDF('P', 'mm', 'A4', true, 'UTF-8', false);
$pdf->setCompanyName($company_name);
$pdf->SetCreator($company_name);
$pdf->SetAuthor($exportedByName);
$pdf->SetTitle('Dealing Sheet - ' . ($sheet['sheet_reference'] ?? ''));
$pdf->SetMargins(15, 30, 15);
$pdf->SetHeaderMargin(5);
$pdf->SetFooterMargin(10);
$pdf->SetAutoPageBreak(true, 25);
$pdf->AddPage();

// ============================================
// PDF CONTENT
// ============================================

// Title
$pdf->SetFont('helvetica', 'B', 16);
$pdf->Cell(0, 8, 'ORDER SHEET', 0, 1, 'C');
$pdf->Ln(3);

// Document info
$pdf->SetFont('helvetica', '', 9);
$pdf->Cell(50, 5, 'Sheet Reference:', 0, 0);
$pdf->SetFont('helvetica', 'B', 9);
$pdf->Cell(60, 5, $sheet['sheet_reference'] ?? 'N/A', 0, 0);
$pdf->SetFont('helvetica', '', 9);
$pdf->Cell(40, 5, 'Broker Code:', 0, 0);
$pdf->SetFont('helvetica', 'B', 9);
$pdf->Cell(0, 5, $sheet['broker_code'] ?? $company_code, 0, 1);

$pdf->SetFont('helvetica', '', 9);
$pdf->Cell(50, 5, 'Department:', 0, 0);
$pdf->SetFont('helvetica', 'B', 9);
$pdf->Cell(60, 5, 'Operations', 0, 0);
$pdf->SetFont('helvetica', '', 9);
$pdf->Cell(40, 5, 'Date:', 0, 0);
$pdf->SetFont('helvetica', 'B', 9);
$pdf->Cell(0, 5, date('d/m/Y', strtotime($sheet['order_date'] ?? date('Y-m-d'))), 0, 1);

$pdf->Ln(6);

// ========== SECTION 1: CLIENT DETAILS ==========
$pdf->SetFont('helvetica', 'B', 11);
$pdf->SetFillColor(230, 230, 230);
$pdf->Cell(0, 8, '1. CLIENT DETAILS', 0, 1, 'L', true);

$pdf->SetFont('helvetica', '', 10);
$pdf->Cell(40, 7, 'Client Name:', 0, 0);
$pdf->SetFont('helvetica', 'B', 10);
$pdf->Cell(0, 7, $sheet['client_name'], 0, 1);

$pdf->SetFont('helvetica', '', 10);
$pdf->Cell(40, 7, 'CDS Account:', 0, 0);
$pdf->SetFont('helvetica', 'B', 10);
$pdf->Cell(0, 7, $sheet['client_cds_account'] ?? 'N/A', 0, 1);

$pdf->Ln(4);

// ========== SECTION 2: ORDER DETAILS - UPDATED FOR BONDS ==========
$pdf->SetFont('helvetica', 'B', 11);
$pdf->SetFillColor(230, 230, 230);
$pdf->Cell(0, 8, '2. ORDER DETAILS', 0, 1, 'L', true);

$pdf->SetFont('helvetica', '', 10);
$pdf->Cell(45, 7, 'Order Type:', 0, 0);
$pdf->SetFont('helvetica', 'B', 10);
$pdf->Cell(50, 7, $trade_side, 0, 0);
$pdf->SetFont('helvetica', '', 10);
$pdf->Cell(40, 7, 'Priority:', 0, 0);
$pdf->SetFont('helvetica', 'B', 10);
$pdf->Cell(0, 7, $sheet['priority'] ?? 'Normal', 0, 1);

$pdf->SetFont('helvetica', '', 10);
$pdf->Cell(45, 7, 'Asset Class:', 0, 0);
$pdf->SetFont('helvetica', 'B', 10);
$pdf->Cell(50, 7, ucfirst($sheet['asset_class']), 0, 0);
$pdf->SetFont('helvetica', '', 10);
$pdf->Cell(40, 7, 'Security:', 0, 0);
$pdf->SetFont('helvetica', 'B', 10);
$pdf->Cell(0, 7, $sheet['security_id'] . ' - ' . ($sheet['security_name'] ?? ''), 0, 1);

// Quantity - Bond specific display
$pdf->SetFont('helvetica', '', 10);
if ($is_bond) {
    $pdf->Cell(45, 7, 'Face Value:', 0, 0);
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->Cell(50, 7, 'TZS ' . number_format($order_qty, 2), 0, 0);
    $pdf->SetFont('helvetica', '', 10);
    $pdf->Cell(40, 7, 'Price (% of Par):', 0, 0);
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->Cell(0, 7, number_format($order_price, 4) . '%', 0, 1);
} else {
    $pdf->Cell(45, 7, 'Quantity:', 0, 0);
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->Cell(50, 7, number_format($order_qty, 0), 0, 0);
    $pdf->SetFont('helvetica', '', 10);
    $pdf->Cell(40, 7, 'Price (TZS):', 0, 0);
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->Cell(0, 7, 'TZS ' . number_format($order_price, 2), 0, 1);
}

// Consideration Value - Bond specific
$pdf->SetFont('helvetica', '', 10);
if ($is_bond) {
    $display_consideration = ($order_price / 100) * $order_qty;
    $pdf->Cell(45, 7, 'Order Value (Monetary):', 0, 0);
} else {
    $display_consideration = $order_qty * $order_price;
    $pdf->Cell(45, 7, 'Consideration Value:', 0, 0);
}
$pdf->SetFont('helvetica', 'B', 10);
$pdf->Cell(50, 7, 'TZS ' . number_format($display_consideration, 2), 0, 0);
$pdf->SetFont('helvetica', '', 10);
$pdf->Cell(40, 7, 'Order Date/Time:', 0, 0);
$pdf->SetFont('helvetica', 'B', 10);
$order_datetime = ($sheet['order_date'] ?? date('Y-m-d')) . ' ' . ($sheet['order_time'] ?? '');
$pdf->Cell(0, 7, date('d/m/Y H:i', strtotime($order_datetime)), 0, 1);

$pdf->Ln(4);

// ========== SECTION 3: EXECUTION DETAILS - UPDATED FOR BONDS ==========
$pdf->SetFont('helvetica', 'B', 11);
$pdf->SetFillColor(230, 230, 230);
$pdf->Cell(0, 8, '3. EXECUTION DETAILS', 0, 1, 'L', true);

$pdf->SetFont('helvetica', '', 10);

// Executed Quantity
if ($is_bond) {
    $pdf->Cell(60, 7, 'Executed Face Value:', 0, 0);
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->Cell(50, 7, $executed_qty > 0 ? 'TZS ' . number_format($executed_qty, 2) : 'Pending', 0, 0);
    
    $pdf->SetFont('helvetica', '', 10);
    $pdf->Cell(40, 7, 'Executed Price (%):', 0, 0);
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->Cell(0, 7, $executed_price > 0 ? number_format($executed_price, 4) . '%' : 'Pending', 0, 1);
} else {
    $pdf->Cell(60, 7, 'Executed Quantity:', 0, 0);
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->Cell(50, 7, $executed_qty > 0 ? number_format($executed_qty, 0) : 'Pending', 0, 0);
    
    $pdf->SetFont('helvetica', '', 10);
    $pdf->Cell(40, 7, 'Executed Price (TZS):', 0, 0);
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->Cell(0, 7, $executed_price > 0 ? 'TZS ' . number_format($executed_price, 2) : 'Pending', 0, 1);
}

// Executed Value
$pdf->SetFont('helvetica', '', 10);
$pdf->Cell(60, 7, 'Executed Value:', 0, 0);
$pdf->SetFont('helvetica', 'B', 10);
$pdf->Cell(50, 7, $executed_value > 0 ? 'TZS ' . number_format($executed_value, 2) : 'Pending', 0, 0);

$pdf->SetFont('helvetica', '', 10);
$pdf->Cell(40, 7, 'Execution Status:', 0, 0);
$pdf->SetFont('helvetica', 'B', 10);
$pdf->Cell(0, 7, ucfirst($sheet['execution_status'] ?? 'Pending'), 0, 1);

if ($executed_qty > 0) {
    $pdf->SetFont('helvetica', '', 10);
    $pdf->Cell(60, 7, 'Trade Date:', 0, 0);
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->Cell(50, 7, $sheet['trade_date'] ? date('d/m/Y', strtotime($sheet['trade_date'])) : 'Pending', 0, 0);
    
    $pdf->SetFont('helvetica', '', 10);
    $pdf->Cell(40, 7, 'Settlement Date:', 0, 0);
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->Cell(0, 7, $sheet['settlement_date'] ? date('d/m/Y', strtotime($sheet['settlement_date'])) : 'Pending', 0, 1);
    
    $pdf->SetFont('helvetica', '', 10);
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->Cell(0, 7, $sheet['trade_reference'] ?? 'Pending', 0, 1);
}

$pdf->Ln(4);

// ========== SECTION 4: FEES AND CHARGES ==========
$pdf->SetFont('helvetica', 'B', 11);
$pdf->SetFillColor(230, 230, 230);
$pdf->Cell(0, 8, '4. FEES AND CHARGES', 0, 1, 'L', true);

// Table header
$pdf->SetFont('helvetica', 'B', 9);
$pdf->Cell(100, 7, 'Description', 0, 0, 'L');
$pdf->Cell(45, 7, 'Rate / Basis', 0, 0, 'R');
$pdf->Cell(40, 7, 'Amount (TZS)', 0, 1, 'R');
$pdf->SetLineWidth(0.2);
$pdf->Line(15, $pdf->GetY(), 195, $pdf->GetY());

$pdf->SetFont('helvetica', '', 9);

// Brokerage
$pdf->Cell(100, 6, 'Brokerage Commission', 0, 0, 'L');
if ($is_bond) {
    $pdf->Cell(45, 6, 'Tiered (0.063132% / 0.035%)', 0, 0, 'R');
} else {
    $pdf->Cell(45, 6, 'Tiered (1.7% / 1.5% / 0.8%)', 0, 0, 'R');
}
$pdf->Cell(40, 6, number_format($fees['brokerage'], 2), 0, 1, 'R');

// Tier details
if (!empty($fees['tier_details'])) {
    $pdf->SetFont('helvetica', 'I', 7);
    foreach ($fees['tier_details'] as $tier) {
        $pdf->Cell(20, 4, '', 0, 0);
        $pdf->Cell(80, 4, $tier['label'], 0, 0, 'L');
        $pdf->Cell(45, 4, '', 0, 0, 'R');
        $pdf->Cell(40, 4, number_format($tier['fee'], 2), 0, 1, 'R');
    }
    $pdf->SetFont('helvetica', '', 9);
}

// VAT
$pdf->Cell(100, 6, 'VAT on Brokerage', 0, 0, 'L');
$pdf->Cell(45, 6, '@ 18.00%', 0, 0, 'R');
$pdf->Cell(40, 6, number_format($fees['vat'], 2), 0, 1, 'R');

// CMSA
$cmsa_rate = $is_bond ? '0.0100%' : '0.1400%';
$cmsa_basis = $is_bond ? 'of Consideration' : 'of Consideration';
$pdf->Cell(100, 6, 'CMSA Transaction Fee', 0, 0, 'L');
$pdf->Cell(45, 6, '@ ' . $cmsa_rate . ' ' . $cmsa_basis, 0, 0, 'R');
$pdf->Cell(40, 6, number_format($fees['cmsa'], 2), 0, 1, 'R');

// DSE
$dse_rate = $is_bond ? '0.02006%' : '0.1652%';
$dse_basis = $is_bond ? 'of Face Value' : 'of Consideration';
$pdf->Cell(100, 6, 'DSE Transaction Fee', 0, 0, 'L');
$pdf->Cell(45, 6, '@ ' . $dse_rate . ' ' . $dse_basis, 0, 0, 'R');
$pdf->Cell(40, 6, number_format($fees['dse'], 2), 0, 1, 'R');

// Fidelity (Equity/ETF only)
if (!$is_bond) {
    $pdf->Cell(100, 6, 'Fidelity Fee', 0, 0, 'L');
    $pdf->Cell(45, 6, '@ 0.0200% of Consideration', 0, 0, 'R');
    $pdf->Cell(40, 6, number_format($fees['fidelity'], 2), 0, 1, 'R');
}

// CDS/CSDR
$cds_rate = $is_bond ? '0.0118%' : '0.0708%';
$cds_basis = $is_bond ? 'of Face Value' : 'of Consideration';
$cds_label = $is_bond ? 'CSDR Fee' : 'CDS Fee';
$pdf->Cell(100, 6, $cds_label, 0, 0, 'L');
$pdf->Cell(45, 6, '@ ' . $cds_rate . ' ' . $cds_basis, 0, 0, 'R');
$pdf->Cell(40, 6, number_format($fees['csd'], 2), 0, 1, 'R');

// Separator line
$pdf->Line(15, $pdf->GetY(), 195, $pdf->GetY());

// Total Charges
$pdf->SetFont('helvetica', 'B', 10);
$pdf->Cell(145, 8, 'TOTAL CHARGES', 0, 0, 'R');
$pdf->Cell(40, 8, number_format($fees['total'], 2), 0, 1, 'R');

$pdf->Ln(4);

// ========== NET AMOUNT / TOTAL PAYABLE ==========
$pdf->SetLineWidth(0.5);
$pdf->Line(15, $pdf->GetY() + 4, 195, $pdf->GetY() + 4);
$pdf->Ln(8);

$pdf->SetFont('helvetica', 'B', 12);
$pdf->Cell(120, 8, $net_label . ':', 0, 0, 'R');
$pdf->SetFont('helvetica', 'B', 12);
$pdf->SetTextColor(0, 100, 0);
$pdf->Cell(40, 8, 'TZS ' . number_format($net_amount, 2), 0, 1, 'R');
$pdf->SetTextColor(0, 0, 0);

// Add breakdown explanation for bonds
if ($is_bond) {
    $pdf->SetFont('helvetica', 'I', 7);
    $pdf->SetTextColor(100, 100, 100);
    $pdf->SetTextColor(0, 0, 0);
}

$pdf->Ln(12);

// ========== REMARKS ==========
if (!empty($sheet['remarks'])) {
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->Cell(0, 6, 'Remarks:', 0, 1);
    $pdf->SetFont('helvetica', '', 9);
    $pdf->MultiCell(0, 5, $sheet['remarks'], 0, 'L');
    $pdf->Ln(4);
}

// ========== SIGNATURES ==========
$pdf->SetFont('helvetica', 'B', 9);
$pdf->SetFont('helvetica', '', 9);
$pdf->Ln(4);

$pdf->SetFont('helvetica', 'B', 9);
$pdf->Cell(70, 6, 'Prepared By:', 0, 0);
$pdf->Cell(70, 6, 'Checked By:', 0, 0);
$pdf->Cell(0, 6, 'Approved By:', 0, 1);

$pdf->SetLineWidth(0.2);
$pdf->Line(15, $pdf->GetY() + 8, 70, $pdf->GetY() + 8);
$pdf->Line(85, $pdf->GetY() + 8, 140, $pdf->GetY() + 8);
$pdf->Line(150, $pdf->GetY() + 8, 195, $pdf->GetY() + 8);

$pdf->SetFont('helvetica', 'I', 7);
$pdf->Cell(70, 12, $exportedByName, 0, 0, 'L');
$pdf->Cell(70, 12, '__________________', 0, 0, 'L');
$pdf->Cell(0, 12, '__________________', 0, 1, 'L');
$pdf->Ln(8);

// Disclaimer
$pdf->SetFont('helvetica', 'I', 6);
$pdf->SetTextColor(120, 120, 120);
$disclaimer = "This Order Sheet is for internal use only. It does not constitute a contract note or official trade confirmation. " .
              "All trades are subject to the Rules, Regulations and Customs of the Dar es Salaam Stock Exchange.";
$pdf->MultiCell(0, 3, $disclaimer, 0, 'C');
$pdf->SetTextColor(0, 0, 0);

// Output PDF
$filename = 'dealing_sheet_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $sheet['sheet_reference'] ?? 'export') . '.pdf';
$pdf->Output($filename, 'I');
exit;
?>
