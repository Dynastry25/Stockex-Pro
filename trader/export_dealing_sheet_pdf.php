<?php
/**
 * Export Order Sheet as PDF
 * Separate file to avoid conflicts with main page
 * 
 * Usage: export_order_sheet_pdf.php?id=123
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
    die('Invalid request. No order sheet ID provided.');
}

$sheet_id = (int) $_GET['id'];

// Load required files
require_once '../config/config.php';
require_once '../auth/auth_middleware.php';
require_once '../includes/dealing_sheet_helpers.php';
require_once '../tcpdf/tcpdf.php';
require_once __DIR__ . '/../reports/traits/ReportHeaderTrait.php';

// Verify user is logged in
require_login();

// Check user permissions - finance, admin, and traders can access
$user_role = $_SESSION['role'] ?? '';
$allowed_roles = ['finance_officer', 'system_admin', 'trader'];

if (!in_array($user_role, $allowed_roles)) {
    show_alert('Access denied.', 'danger');
    redirect('auth/login.php');
    exit;
}

// Only require mandate for non-system_admin roles
if ($user_role !== 'system_admin') {
    require_mandate();
}

$db = getDBConnection();
$current_user = get_logged_in_user() ?: get_session_user();

// Get the order sheet data
function getOrderSheetById($db, $id) {
    $stmt = $db->prepare("SELECT * FROM dealing_sheets WHERE id = ?");
    $stmt->execute([$id]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

$sheet = getOrderSheetById($db, $sheet_id);

if (!$sheet) {
    die('Order sheet not found.');
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
// FEES CALCULATION
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
            $fees['tier_details'][] = ['fee' => $fees['brokerage'], 'label' => 'Below 10M @ 1.7000%'];
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

// Calculate values
$executed_qty = floatval($sheet['executed_quantity'] ?? 0);
$executed_price = floatval($sheet['executed_price'] ?? 0);
$order_qty = floatval($sheet['quantity'] ?? 0);
$order_price = floatval($sheet['order_price'] ?? 0);

if ($executed_qty > 0 && $executed_price > 0) {
    $consideration = $executed_qty * $executed_price;
    $quantity = $executed_qty;
    $price = $executed_price;
    $executed_value = $consideration;
} else {
    $consideration = $order_qty * $order_price;
    $quantity = $order_qty;
    $price = $order_price;
    $executed_value = 0;
}

$fees = calculateFees($sheet['asset_class'], $consideration, $quantity, $price);
$is_bond = ($sheet['asset_class'] === 'bond');
$trade_side = strtoupper($sheet['order_type'] ?? 'BUY');
$is_sell = ($trade_side === 'SELL');

// ============================================
// BROKER BANK DETAILS FOR BUY ORDERS
// ============================================
function getBrokerBankDetails($db) {
    // Try to get active broker bank accounts
    $stmt = $db->prepare("SELECT bank_name, account_name, account_number, branch_name, swift_code 
                          FROM banks_accounts 
                          WHERE status = 'active' AND is_active = '1'
                          ORDER BY id ASC LIMIT 1");
    $stmt->execute();
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($result) {
        return $result;
    }
    
    // Fallback: hardcoded defaults if no DB records exist
    return [
        'bank_name' => 'National Microfinance Bank',
        'account_name' => 'National Microfinance Bank',
        'account_number' => '0112345678901',
        'branch_name' => 'Dar es Salaam Main Branch',
        'swift_code' => 'NMIBTZTZ'
    ];
}

// ============================================
// TRANSACTION FEES - based on trade value (ONLY FOR SELL)
// ============================================
function calculateTransactionFee($trade_value) {
    if ($trade_value < 100000) {
        return 250;
    } elseif ($trade_value >= 100000 && $trade_value < 10000000) {
        return 2000;
    } elseif ($trade_value >= 10000000 && $trade_value < 50000000) {
        return 6000;
    } elseif ($trade_value >= 50000000) {
        return 12000;
    }
    return 0;
}

// Get broker bank details for BUY orders
$broker_bank_details = null;
if (!$is_sell) { // BUY order
    $broker_bank_details = getBrokerBankDetails($db);
}

// Calculate transaction fee - ONLY FOR SELL
$transaction_fee = 0;
if ($is_sell) {
    $transaction_fee = calculateTransactionFee($consideration);
}

// CORRECTED FORMULA: 
// For BUY: Total Payable = Consideration + Total Charges (NO transaction fee)
// For SELL: Total Receivable = Consideration - Total Charges (WITH transaction fee)
if ($is_sell) {
    $total_charges = $fees['total'] + $transaction_fee;
    $net_amount = $consideration - $total_charges;
    $net_label = 'NET AMOUNT RECEIVABLE';
} else {
    $total_charges = $fees['total']; // No transaction fee for BUY
    $net_amount = $consideration + $total_charges;
    $net_label = 'NET AMOUNT PAYABLE';
}

// ============================================
// PDF CLASS
// ============================================
class OrderSheetPDF extends TCPDF {
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
$pdf = new OrderSheetPDF('P', 'mm', 'A4', true, 'UTF-8', false);
$pdf->setCompanyName($company_name);
$pdf->SetCreator($company_name);
$pdf->SetAuthor($exportedByName);
$pdf->SetTitle('Order Sheet - ' . ($sheet['sheet_reference'] ?? ''));
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
$pdf->Ln(2);

// Document info - REMOVED broker_code and department
$pdf->SetFont('helvetica', '', 9);
$pdf->Cell(50, 5, 'Sheet Reference:', 0, 0);
$pdf->SetFont('helvetica', 'B', 9);
$pdf->Cell(60, 5, $sheet['sheet_reference'] ?? 'N/A', 0, 0);
$pdf->SetFont('helvetica', '', 9);
$pdf->Cell(40, 5, 'Date:', 0, 0);
$pdf->SetFont('helvetica', 'B', 9);
$pdf->Cell(0, 5, date('d/m/Y', strtotime($sheet['order_date'] ?? date('Y-m-d'))), 0, 1);

$pdf->Ln(4);

// ========== SECTION 1: CLIENT DETAILS ==========
$pdf->SetFont('helvetica', 'B', 10);
$pdf->SetFillColor(230, 230, 230);
$pdf->Cell(0, 7, '1. CLIENT DETAILS', 0, 1, 'L', true);

$pdf->SetFont('helvetica', '', 9);
$pdf->Cell(40, 6, 'Client Name:', 0, 0);
$pdf->SetFont('helvetica', 'B', 9);
$pdf->Cell(0, 6, $sheet['client_name'], 0, 1);

$pdf->SetFont('helvetica', '', 9);
$pdf->Cell(40, 6, 'CDS Account:', 0, 0);
$pdf->SetFont('helvetica', 'B', 9);
$pdf->Cell(0, 6, $sheet['client_cds_account'] ?? 'N/A', 0, 1);

$pdf->Ln(2);

// ========== SECTION 2: ORDER DETAILS ==========
$pdf->SetFont('helvetica', 'B', 10);
$pdf->SetFillColor(230, 230, 230);
$pdf->Cell(0, 7, '2. ORDER DETAILS', 0, 1, 'L', true);

// Two-column layout for order details to save space
$pdf->SetFont('helvetica', '', 9);

// Row 1
$pdf->Cell(45, 6, 'Order Type:', 0, 0);
$pdf->SetFont('helvetica', 'B', 9);
$pdf->Cell(40, 6, $trade_side, 0, 0);
$pdf->SetFont('helvetica', '', 9);
$pdf->Cell(30, 6, 'Priority:', 0, 0);
$pdf->SetFont('helvetica', 'B', 9);
$pdf->Cell(0, 6, $sheet['priority'] ?? 'Normal', 0, 1);

// Row 2
$pdf->SetFont('helvetica', '', 9);
$pdf->Cell(45, 6, 'Asset Class:', 0, 0);
$pdf->SetFont('helvetica', 'B', 9);
$pdf->Cell(40, 6, ucfirst($sheet['asset_class']), 0, 0);
$pdf->SetFont('helvetica', '', 9);
$pdf->Cell(30, 6, 'Security Code:', 0, 0);
$pdf->SetFont('helvetica', 'B', 9);
$pdf->Cell(0, 6, $sheet['security_id'] ?? 'N/A', 0, 1);

// Row 3
$pdf->SetFont('helvetica', '', 9);
$pdf->Cell(45, 6, 'Quantity:', 0, 0);
$pdf->SetFont('helvetica', 'B', 9);
$pdf->Cell(40, 6, number_format($order_qty, ($is_bond ? 2 : 0)), 0, 0);
$pdf->SetFont('helvetica', '', 9);
$pdf->Cell(30, 6, 'Price:', 0, 0);
$pdf->SetFont('helvetica', 'B', 9);
if ($is_bond) {
    $pdf->Cell(0, 6, number_format($order_price, 2) . '%', 0, 1);
} else {
    $pdf->Cell(0, 6, 'TZS ' . number_format($order_price, 2), 0, 1);
}

// Row 4
$pdf->SetFont('helvetica', '', 9);
$pdf->Cell(45, 6, 'Consideration Value:', 0, 0);
$pdf->SetFont('helvetica', 'B', 9);
$pdf->Cell(40, 6, 'TZS ' . number_format($consideration, 2), 0, 0);
$pdf->SetFont('helvetica', '', 9);
$pdf->Cell(30, 6, 'Order Date:', 0, 0);
$pdf->SetFont('helvetica', 'B', 9);
$order_date = date('d/m/Y', strtotime($sheet['order_date'] ?? date('Y-m-d')));
$pdf->Cell(0, 6, $order_date, 0, 1);

$pdf->Ln(2);

// ========== SECTION 3: FEES AND CHARGES ==========
$pdf->SetFont('helvetica', 'B', 10);
$pdf->SetFillColor(230, 230, 230);
$pdf->Cell(0, 7, '3. FEES AND CHARGES', 0, 1, 'L', true);

// Table header
$pdf->SetFont('helvetica', 'B', 8);
$pdf->Cell(100, 6, 'Description', 0, 0, 'L');
$pdf->Cell(45, 6, 'Rate', 0, 0, 'R');
$pdf->Cell(40, 6, 'Amount (TZS)', 0, 1, 'R');
$pdf->SetLineWidth(0.2);
$pdf->Line(15, $pdf->GetY(), 195, $pdf->GetY());

$pdf->SetFont('helvetica', '', 8);

// Brokerage
$pdf->Cell(100, 5, 'Brokerage Commission', 0, 0, 'L');
$pdf->Cell(45, 5, $is_bond ? 'Tiered' : 'Tiered', 0, 0, 'R');
$pdf->Cell(40, 5, number_format($fees['brokerage'], 2), 0, 1, 'R');

// Tier details
if (!empty($fees['tier_details'])) {
    $pdf->SetFont('helvetica', 'I', 6.5);
    foreach ($fees['tier_details'] as $tier) {
        $pdf->Cell(20, 4, '', 0, 0);
        $pdf->Cell(80, 4, $tier['label'], 0, 0, 'L');
        $pdf->Cell(45, 4, '', 0, 0, 'R');
        $pdf->Cell(40, 4, number_format($tier['fee'], 2), 0, 1, 'R');
    }
    $pdf->SetFont('helvetica', '', 8);
}

// VAT
$pdf->Cell(100, 5, 'VAT on Brokerage', 0, 0, 'L');
$pdf->Cell(45, 5, '@ 18.00%', 0, 0, 'R');
$pdf->Cell(40, 5, number_format($fees['vat'], 2), 0, 1, 'R');

// CMSA
$cmsa_rate = $is_bond ? '0.0100%' : '0.1400%';
$pdf->Cell(100, 5, 'CMSA Transaction Fee', 0, 0, 'L');
$pdf->Cell(45, 5, '@ ' . $cmsa_rate, 0, 0, 'R');
$pdf->Cell(40, 5, number_format($fees['cmsa'], 2), 0, 1, 'R');

// DSE
$dse_rate = $is_bond ? '0.02006%' : '0.1652%';
$dse_label = $is_bond ? '@ ' . $dse_rate . ' (FV)' : '@ ' . $dse_rate;
$pdf->Cell(100, 5, 'DSE Transaction Fee', 0, 0, 'L');
$pdf->Cell(45, 5, $dse_label, 0, 0, 'R');
$pdf->Cell(40, 5, number_format($fees['dse'], 2), 0, 1, 'R');

// Fidelity (Equity/ETF only)
if (!$is_bond) {
    $pdf->Cell(100, 5, 'Fidelity Fee', 0, 0, 'L');
    $pdf->Cell(45, 5, '@ 0.0200%', 0, 0, 'R');
    $pdf->Cell(40, 5, number_format($fees['fidelity'], 2), 0, 1, 'R');
}

// CDS
$cds_rate = $is_bond ? '0.0118%' : '0.0708%';
$cds_label = $is_bond ? '@ ' . $cds_rate . ' (FV)' : '@ ' . $cds_rate;
$pdf->Cell(100, 5, 'CDS Fee', 0, 0, 'L');
$pdf->Cell(45, 5, $cds_label, 0, 0, 'R');
$pdf->Cell(40, 5, number_format($fees['csd'], 2), 0, 1, 'R');

// Transaction Fee - ONLY FOR SELL
if ($is_sell) {
    $pdf->Cell(100, 5, 'Transaction Processing Fee', 0, 0, 'L');
    $pdf->Cell(45, 5, 'Flat Fee', 0, 0, 'R');
    $pdf->Cell(40, 5, number_format($transaction_fee, 2), 0, 1, 'R');
}

$pdf->Line(15, $pdf->GetY(), 195, $pdf->GetY());

// Total Charges
$pdf->SetFont('helvetica', 'B', 9);
$pdf->Cell(145, 7, 'TOTAL CHARGES', 0, 0, 'R');
$pdf->Cell(40, 7, number_format($total_charges, 2), 0, 1, 'R');

$pdf->Ln(2);

// ========== NET AMOUNT / TOTAL PAYABLE ==========
$pdf->SetLineWidth(0.5);
$pdf->Line(15, $pdf->GetY() + 2, 195, $pdf->GetY() + 2);
$pdf->Ln(5);

$pdf->SetFont('helvetica', 'B', 11);
$pdf->Cell(120, 7, $net_label . ':', 0, 0, 'R');
$pdf->SetFont('helvetica', 'B', 11);
$pdf->SetTextColor(0, 100, 0);
$pdf->Cell(40, 7, 'TZS ' . number_format($net_amount, 2), 0, 1, 'R');
$pdf->SetTextColor(0, 0, 0);

$pdf->Ln(3);

// ========== SECTION 4: BROKER BANK DETAILS (BUY ONLY) - SINGLE COLUMN ==========
if (!$is_sell && $broker_bank_details) { // Only for BUY orders
    $pdf->SetFont('helvetica', 'B', 9);
    $pdf->SetFillColor(230, 230, 230);
    $pdf->Cell(0, 6, '4. PAYMENT INSTRUCTIONS - BROKER BANK DETAILS', 0, 1, 'L', true);
    
    $pdf->SetFont('helvetica', 'B', 7);
    $pdf->SetTextColor(150, 0, 0);
    $pdf->Cell(0, 4, 'Transfer the NET AMOUNT PAYABLE to:', 0, 1, 'L');
    $pdf->SetTextColor(0, 0, 0);
    $pdf->Ln(1);
    
    // Single column bank details
    $pdf->SetFont('helvetica', '', 8);
    $pdf->Cell(35, 5, 'Bank:', 0, 0);
    $pdf->SetFont('helvetica', 'B', 8);
    $pdf->Cell(0, 5, $broker_bank_details['bank_name'] ?? 'N/A', 0, 1);
    
    $pdf->SetFont('helvetica', '', 8);
    $pdf->Cell(35, 5, 'Account Name:', 0, 0);
    $pdf->SetFont('helvetica', 'B', 8);
    $pdf->Cell(0, 5, $broker_bank_details['account_name'] ?? 'N/A', 0, 1);
    
    $pdf->SetFont('helvetica', '', 8);
    $pdf->Cell(35, 5, 'Account Number:', 0, 0);
    $pdf->SetFont('helvetica', 'B', 8);
    $pdf->Cell(0, 5, $broker_bank_details['account_number'] ?? 'N/A', 0, 1);
    
    if (!empty($broker_bank_details['branch_name'])) {
        $pdf->SetFont('helvetica', '', 8);
        $pdf->Cell(35, 5, 'Branch:', 0, 0);
        $pdf->SetFont('helvetica', 'B', 8);
        $pdf->Cell(0, 5, $broker_bank_details['branch_name'], 0, 1);
    }
    
    if (!empty($broker_bank_details['swift_code'])) {
        $pdf->SetFont('helvetica', '', 8);
        $pdf->Cell(35, 5, 'SWIFT Code:', 0, 0);
        $pdf->SetFont('helvetica', 'B', 8);
        $pdf->Cell(0, 5, $broker_bank_details['swift_code'], 0, 1);
    }
    
    $pdf->SetFont('helvetica', 'I', 6);
    $pdf->SetTextColor(100, 100, 100);
    $pdf->Cell(0, 4, 'Reference: Use Sheet Reference as payment reference', 0, 1, 'L');
    $pdf->SetTextColor(0, 0, 0);
    
    $pdf->Ln(2);
}

// ========== SIGNATURES - SQUEEZED ==========
$pdf->SetFont('helvetica', 'B', 9);
$pdf->Cell(70, 5, 'Prepared By:', 0, 0);
$pdf->Cell(70, 5, 'Checked By:', 0, 0);
$pdf->Cell(0, 5, 'Approved By:', 0, 1);

$pdf->SetLineWidth(0.2);
$y_pos = $pdf->GetY();
$pdf->Line(15, $y_pos + 6, 70, $y_pos + 6);
$pdf->Line(85, $y_pos + 6, 140, $y_pos + 6);
$pdf->Line(150, $y_pos + 6, 195, $y_pos + 6);

$pdf->SetFont('helvetica', 'I', 7);
$pdf->Cell(70, 10, $exportedByName, 0, 0, 'L');
$pdf->Cell(70, 10, '', 0, 0, 'L');
$pdf->Cell(0, 10, '', 0, 1, 'L');

$pdf->Ln(2);

// Disclaimer
$pdf->SetFont('helvetica', 'I', 6);
$pdf->SetTextColor(120, 120, 120);
$disclaimer = "This Order Sheet is for internal use only. It does not constitute a contract note or official trade confirmation. " .
              "All trades are subject to the Rules, Regulations and Customs of the Dar es Salaam Stock Exchange.";
$pdf->MultiCell(0, 3, $disclaimer, 0, 'C');
$pdf->SetTextColor(0, 0, 0);

// Output PDF
$filename = 'order_sheet_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $sheet['sheet_reference'] ?? 'export') . '.pdf';
$pdf->Output($filename, 'I');
exit;
?>
