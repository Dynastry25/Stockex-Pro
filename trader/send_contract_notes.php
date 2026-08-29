<?php
// Add this at the very top for better error reporting
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once '../config/config.php';
require_once '../config/email.php';
require_once '../auth/auth_middleware.php';
require_once '../tcpdf/tcpdf.php';
require_once __DIR__ . '/../reports/traits/ReportHeaderTrait.php';

require_trader();
require_mandate();

$db = getDBConnection();
$success_message = '';
$error_message = '';
$results = [];

// Get company details from database
$company_stmt = $db->query("SELECT * FROM companies WHERE status = 'active' ORDER BY id LIMIT 1");
$company = $company_stmt->fetch();
$company_name = $company ? $company['company_name'] : 'Neovam LTD';
$company_phone = $company ? $company['phone'] : '+255 746 177 230';
$company_address = $company ? $company['address'] : 'P.O BOX 36098 Kigamboni, Dar es Salaam';
$company_email = $company ? $company['email'] : 'info@neovam.com';

// Use system temp directory - always writable, no permission headaches
$temp_dir = STORAGE_PATH;

// Define ContractNotePDF class ONCE at the top level
class ContractNotePDF extends TCPDF {
    use ReportHeaderTrait;
    
    private $watermark_enabled = false;
    private $company_name = '';
    private $total_trades = 0;
    private $current_trade = 1;
    
    public function setWatermarkEnabled($enabled) {
        $this->watermark_enabled = $enabled;
    }
    
    public function setCompanyName($company_name) {
        $this->company_name = $company_name;
    }
    
    public function setTotalTrades($total) {
        $this->total_trades = $total;
    }
    
    public function setCurrentTrade($current) {
        $this->current_trade = $current;
    }
    
    public function Header() {
        $this->renderReportHeader();
        
        $y = $this->GetY();
        
        $this->SetFont('helvetica', 'I', 7);
        $this->SetTextColor(4, 45, 146);
        $this->SetXY(15, $y);
        $this->Cell(180, 3, '(Subject to the Rules and Practice of the Dar es Salaam Stock Exchange)', 0, 1, 'C');
        $y += 4;
        
        if ($this->total_trades > 1) {
            $this->SetFont('helvetica', '', 6);
            $this->SetTextColor(120, 120, 120);
            $this->SetXY(15, $y);
            $this->Cell(10, 3, 'Trade ' . $this->current_trade . ' of ' . $this->total_trades, 0, 0, 'L');
            $this->SetTextColor(0, 0, 0);
            $y += 3;
        }
        
        if ($this->watermark_enabled) {
            $this->SetAlpha(0.05);
            $this->SetFont('helvetica', 'B', 50);
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
    
    // Page footer - COMPACT
    public function Footer() {
        $this->SetY(-12);
        $this->SetFont('helvetica', '', 4);
        $this->SetTextColor(120, 120, 120);
        
        $disclaimer = "{$this->company_name} has prepared this Report solely for informational purposes. " .
                     "{$this->company_name} does not represent warrant or guarantee that the Reports are accurate.";
        
        $this->MultiCell(160, 1.5, $disclaimer, 0, 'C', false, 1, 25, $this->GetY(), true, 0, false, true, 0, 'T', false);
        
        $this->SetY(-6);
        $this->SetFont('helvetica', 'B', 5);
        $this->SetTextColor(0, 0, 0);
        $this->Cell(0, 4, 'Page ' . $this->getAliasNumPage(), 0, false, 'R', 0, '', 0, false, 'T', 'M');
    }
    
    private function RotatedText($x, $y, $txt, $angle) {
        $this->StartTransform();
        $this->Rotate($angle, $x, $y);
        $this->Text($x, $y, $txt);
        $this->StopTransform();
    }
    
    // Function to add contract note (OPTIMIZED for one page) - COMPLETE VERSION
    public function addContractNote($trade, $fees, $contract_number, $order_number, $exchange_ref, $is_summary = false, $summary_data = null) {
        $is_bond = ($trade['asset_class'] === 'bond' || $trade['asset_class'] === 'treasury_bond');
        $is_etf = ($trade['asset_class'] === 'Exchange Traded Funds');
        $trade_side = strtoupper(trim($trade['trade_side']));
        $is_sell = ($trade_side === 'SELL');
        
        $consideration = floatval($trade['consideration']);
        $quantity = floatval($trade['quantity']);
        $price = floatval($trade['price']);
        
        // Calculate net amount based on trade side
        if ($is_sell) {
            // For SELL: Consideration - Total Charges
            $net_amount = $consideration - $fees['total'];
        } else {
            // For BUY: Consideration + Total Charges
            $net_amount = $consideration + $fees['total'];
        }
        
        // Add a new page
        $this->AddPage();
        $this->Ln(10);
        
        // Trade information - COMPACT
        $this->SetFont('helvetica', '', 7);
        $this->Cell(90, 4, 'Trade Date: ' . date('d/m/Y', strtotime($trade['trade_date'])), 0, 0, 'L');
        $this->Cell(90, 4, 'Account: ' . $trade['client_cds_account'], 0, 1, 'R');
        $this->Cell(90, 4, 'Order No: ' . $order_number, 0, 0, 'L');
        $this->Cell(90, 4, 'Exch Ref: ' . $exchange_ref, 0, 1, 'R');
        $this->Cell(90, 4, 'Settlement: ' . date('d/m/Y', strtotime($trade['settlement_date'])), 0, 1, 'L');
        
        $this->Ln(4); // SPACING
        
        // Client information
        $this->SetFont('helvetica', 'B', 10);
        $this->Cell(0, 5, strtoupper($trade['client_name']), 0, 1, 'L');
        $this->SetFont('helvetica', '', 7);
        $this->Cell(0, 3, 'Account Ending: ' . substr($trade['client_cds_account'], -4), 0, 1, 'L');
        
        $this->Ln(10); // SPACING
        
        // Contract note header
        $this->SetFont('helvetica', 'B', 9);
        $contract_title = $trade_side . ' CONTRACT NOTE No: ' . $contract_number;
        if ($is_summary) {
            $contract_title .= ' (SUMMARY)';
        }
        $this->Cell(0, 5, $contract_title, 0, 1, 'L');
        
        $this->Ln(2); // SPACING
        
        // Instruction paragraph - COMPACT
        $this->SetFont('helvetica', '', 8);
        $instruction = "We wish to advise that in accordance with your instructions we have " . $trade_side . 
                       " on your account subject to the Rules, Regulations and Customs of the Dar es Salaam Stock Exchange:-";
        $this->MultiCell(0, 4, $instruction, 0, 'L', false, 1, '', '', true, 0, false, true, 0, 'T', false);
        
        $this->Ln(3); // SPACING
        
        // Security details
        $this->SetFont('helvetica', 'B', 8);
        if ($is_bond) {
            $this->Cell(0, 4, 'TREASURY BOND', 0, 1, 'L');
        } elseif ($is_etf) {
            $this->Cell(0, 4, 'EXCHANGE TRADED FUND', 0, 1, 'L');
        } else {
            $this->Cell(0, 4, '[DSE] EQUITY', 0, 1, 'L');
        }
        
        $this->SetFont('helvetica', '', 8);
        $this->Cell(0, 4, $trade['security_id'], 0, 1, 'L');
        
        $this->Ln(4); // SPACING
        
        // Trade details table - COMPACT
        $this->SetFont('helvetica', 'B', 8);
        $this->SetFillColor(245, 245, 245);
        
        $this->Cell(45, 6, 'SECURITY', 1, 0, 'C', 1);
        $this->Cell(45, 6, 'QUANTITY', 1, 0, 'C', 1);
        $this->Cell(45, 6, 'PRICE', 1, 0, 'C', 1);
        $this->Cell(45, 6, 'CONSIDERATION', 1, 1, 'C', 1);
        
        $this->SetFont('helvetica', '', 8);
        $this->Cell(45, 6, $trade['security_id'], 1, 0, 'C');
        
        if ($is_summary && $summary_data) {
            $this->Cell(45, 6, number_format($summary_data['total_quantity'], ($is_bond ? 2 : 0)), 1, 0, 'C');
            $this->Cell(45, 6, number_format($summary_data['average_price'], ($is_bond ? 6 : 2)), 1, 0, 'C');
        } else {
            $this->Cell(45, 6, number_format($quantity, ($is_bond ? 2 : 0)), 1, 0, 'C');
            $this->Cell(45, 6, number_format($price, ($is_bond ? 6 : 2)), 1, 0, 'C');
        }
        
        $this->Cell(45, 6, number_format($consideration, 2), 1, 1, 'C');
        
        $this->Ln(5); // SPACING
        
        // Bond specific details
        if ($is_bond) {
            $this->SetFont('helvetica', 'B', 7);
            $this->Cell(25, 3, 'Coupon:', 0, 0, 'L');
            $this->SetFont('helvetica', '', 7);
            $this->Cell(25, 3, number_format($trade['coupon_rate'] ?? 15.49, 4) . '%', 0, 0, 'L');
            
            $this->SetFont('helvetica', 'B', 7);
            $this->Cell(35, 3, 'Maturity Date:', 0, 0, 'L');
            $this->SetFont('helvetica', '', 7);
            $maturity_date = !empty($trade['maturity_date']) && $trade['maturity_date'] != '0000-00-00' ? 
                date('d/m/Y', strtotime($trade['maturity_date'])) : date('d/m/Y', strtotime('+5 years', strtotime($trade['trade_date'])));
            $this->Cell(0, 3, $maturity_date, 0, 1, 'L');
            $this->Ln(2);
        }
        
        $this->Ln(2); // SPACING
        
        // FEES SECTION - OPTIMIZED
        $this->SetFont('helvetica', 'B', 8);
        $this->Cell(0, 5, 'FEES AND CHARGES', 0, 1, 'L');
        $this->SetFont('helvetica', '', 7);
        
        // Brokerage Commission with tiers
        $this->Cell(0, 3, 'Brokerage Commission:', 0, 1, 'L');
        
        if (!empty($fees['tier_details'])) {
            foreach ($fees['tier_details'] as $tier) {
                $this->Cell(15, 2.5, '', 0, 0, 'L');
                $this->Cell(70, 2.5, $tier['label'], 0, 0, 'L');
                $this->Cell(40, 2.5, number_format($tier['fee'], 2), 0, 1, 'R');
            }
        }
        
        $this->SetFont('helvetica', 'B', 7);
        $this->Cell(85, 3, '', 0, 0, 'L');
        $this->Cell(50, 3, 'Total Brokerage:', 0, 0, 'L');
        $this->Cell(40, 3, number_format($fees['brokerage'], 2), 0, 1, 'R');
        
        $this->SetFont('helvetica', '', 7);
        $this->Cell(100, 3.5, 'VAT on Brokerage Commission', 0, 0, 'L');
        $this->Cell(40, 3.5, '@ 18.000%', 0, 0, 'L');
        $this->Cell(40, 3.5, number_format($fees['vat'], 2), 0, 1, 'R');
        
        $this->Cell(100, 3.5, 'CMSA Transaction Fee', 0, 0, 'L');
        $cmsa_rate = $is_bond ? 0.0100 : 0.1400;
        $this->Cell(40, 3.5, '@ ' . number_format($cmsa_rate, 4) . '%', 0, 0, 'L');
        $this->Cell(40, 3.5, number_format($fees['cmsa'], 2), 0, 1, 'R');
        
        $this->Cell(100, 3.5, 'DSE Transaction Fee (VAT INCL)', 0, 0, 'L');
        $dse_rate = $is_bond ? 0.02006 : 0.1652;
        $this->Cell(40, 3.5, '@ ' . number_format($dse_rate, 4) . '%', 0, 0, 'L');
        $this->Cell(40, 3.5, number_format($fees['dse'], 2), 0, 1, 'R');
        
        if (!$is_bond) {
            $this->Cell(100, 3.5, 'Fidelity Fee', 0, 0, 'L');
            $this->Cell(40, 3.5, '@ 0.0200%', 0, 0, 'L');
            $this->Cell(40, 3.5, number_format($fees['fidelity'], 2), 0, 1, 'R');
        }
        
        $this->Cell(100, 3.5, 'CDS Fee (VAT INCL)', 0, 0, 'L');
        $cds_rate = $is_bond ? 0.0118 : 0.0708;
        $this->Cell(40, 3.5, '@ ' . number_format($cds_rate, 4) . '%', 0, 0, 'L');
        $this->Cell(40, 3.5, number_format($fees['csd'], 2), 0, 1, 'R');
        
        $this->Cell(100, 3.5, 'Bank Charges', 0, 0, 'L');
        $this->Cell(40, 3.5, '', 0, 0, 'L');
        $this->Cell(40, 3.5, number_format($fees['bank_charges'], 2), 0, 1, 'R');
        
        // Total line
        $this->SetLineWidth(0.2);
        $this->Line(25, $this->GetY() + 1, 185, $this->GetY() + 1);
        $this->Ln(2);
        
        $this->SetFont('helvetica', 'B', 8);
        $this->Cell(100, 4, 'Total Charges', 0, 0, 'L');
        $this->Cell(40, 4, '', 0, 0, 'L');
        $this->Cell(40, 4, number_format($fees['total'], 2), 0, 1, 'R');
        
        $this->Ln(2);
        
        // Net amount - PROMINENT
        $this->SetLineWidth(0.5);
        $this->Line(25, $this->GetY() + 1, 185, $this->GetY() + 1);
        $this->Ln(3);
        
        $amount_label = $is_sell ? 'NET AMOUNT RECEIVABLE' : 'NET AMOUNT PAYABLE';
        $this->SetFont('helvetica', 'B', 10);
        $this->Cell(100, 6, $amount_label, 0, 0, 'L');
        $this->Cell(40, 6, '', 0, 0, 'L');
        $this->Cell(40, 6, number_format($net_amount, 2), 0, 1, 'R');
        
        $this->Ln(50);
        
        // Closing section - COMPACT
        $this->SetFont('helvetica', '', 8);
        $this->Cell(0, 4, 'Yours Faithfully,', 0, 1, 'L');
        $this->Ln(3);
        $this->SetFont('helvetica', 'B', 9);
        $this->Cell(0, 5, 'FOR ' . $this->company_name, 0, 1, 'L');
        
        $this->Ln(6);
        
        // Signature lines - COMPACT
        $this->SetFont('helvetica', '', 7);
        $this->Cell(80, 8, '', 0, 0, 'L');
        $this->Cell(80, 8, '', 0, 1, 'L');
        
        $this->SetLineWidth(0.3);
        $this->Line(25, $this->GetY() - 6, 105, $this->GetY() - 6);
        $this->Line(110, $this->GetY() - 6, 185, $this->GetY() - 6);
        
        $this->SetFont('helvetica', 'B', 7);
        $this->Cell(80, 3, 'SIGNATURE OF CLIENT', 0, 0, 'C');
        $this->Cell(80, 3, 'STAMP & SIGNATURE OF LDM', 0, 1, 'C');
    }
    
    // Function to add summary breakdown page
    public function addSummaryBreakdown($trades, $summary_data, $client_name, $trade_date, $security_id, $trade_side) {
        $this->AddPage();
        $this->SetFont('helvetica', 'B', 12);
        $this->Cell(0, 10, 'TRADE BREAKDOWN - ' . strtoupper($trade_side) . ' SUMMARY', 0, 1, 'C');
        $this->Ln(5);
        
        $this->SetFont('helvetica', 'B', 9);
        $this->Cell(0, 8, 'Client: ' . strtoupper($client_name), 0, 1, 'L');
        $this->Cell(0, 8, 'Trade Date: ' . date('d/m/Y', strtotime($trade_date)), 0, 1, 'L');
        $this->Cell(0, 8, 'Security: ' . $security_id, 0, 1, 'L');
        $this->Ln(5);
        
        // Create breakdown table
        $this->SetFont('helvetica', 'B', 8);
        $this->SetFillColor(245, 245, 245);
        
        $this->Cell(20, 8, '#', 1, 0, 'C', 1);
        $this->Cell(25, 8, 'Ref No', 1, 0, 'C', 1);
        $this->Cell(25, 8, 'Quantity', 1, 0, 'C', 1);
        $this->Cell(25, 8, 'Price', 1, 0, 'C', 1);
        $this->Cell(35, 8, 'Consideration', 1, 0, 'C', 1);
        $this->Cell(35, 8, 'Time', 1, 1, 'C', 1);
        
        $this->SetFont('helvetica', '', 8);
        $counter = 1;
        foreach ($trades as $trade) {
            $this->Cell(20, 8, $counter++, 1, 0, 'C');
            $this->Cell(25, 8, substr($trade['trade_reference'], -6), 1, 0, 'C');
            $this->Cell(25, 8, number_format($trade['quantity'], 0), 1, 0, 'R');
            $this->Cell(25, 8, number_format($trade['price'], 4), 1, 0, 'R');
            $this->Cell(35, 8, number_format($trade['consideration'], 2), 1, 0, 'R');
            $this->Cell(35, 8, date('H:i', strtotime($trade['created_at'])), 1, 1, 'C');
        }
        
        // Summary totals
        $this->Ln(5);
        $this->SetFont('helvetica', 'B', 9);
        $this->Cell(95, 8, 'TOTAL QUANTITY:', 0, 0, 'R');
        $this->Cell(40, 8, number_format($summary_data['total_quantity'], 0), 0, 1, 'R');
        
        $this->Cell(95, 8, 'AVERAGE PRICE:', 0, 0, 'R');
        $this->Cell(40, 8, number_format($summary_data['average_price'], 4), 0, 1, 'R');
        
        $this->Cell(95, 8, 'TOTAL CONSIDERATION:', 0, 0, 'R');
        $this->Cell(40, 8, number_format($summary_data['total_consideration'], 2), 0, 1, 'R');
        
        $this->Cell(95, 8, 'NUMBER OF TRADES:', 0, 0, 'R');
        $this->Cell(40, 8, $summary_data['trade_count'], 0, 1, 'R');
    }
}

// Debug function to test SMTP connection
function testSMTPConnection($debug = false) {
    require_once '../phpmailer/src/Exception.php';
    require_once '../phpmailer/src/PHPMailer.php';
    require_once '../phpmailer/src/SMTP.php';

    $mail = new PHPMailer\PHPMailer\PHPMailer(true);
    
    try {
        // Enable debugging
        if ($debug) {
            $mail->SMTPDebug = 3;
            $mail->Debugoutput = function($str, $level) {
                echo "Debug level $level; message: $str\n";
            };
        }
        
        // Apply centralized SMTP configuration
        configureMailer($mail);
        $mail->addAddress(SMTP_USERNAME, 'SMTP Test');
        $mail->Subject = 'SMTP Connection Test';
        $mail->Body = 'SMTP connection test successful!';
        
        if ($mail->send()) {
            return "SMTP Test: SUCCESS - Email sent!";
        } else {
            return "SMTP Test: FAILED - " . $mail->ErrorInfo;
        }
        
    } catch (Exception $e) {
        return "SMTP Test: ERROR - " . $e->getMessage();
    }
}

// Function to calculate fees
function calculateFees($db, $asset_class, $consideration, $quantity, $price, $trade_side = 'Sell') {
    $fees = [];
    $fees['tier_details'] = [];
    
    $is_bond = ($asset_class === 'bond' || $asset_class === 'treasury_bond');
    $is_etf = ($asset_class === 'Exchange Traded Funds');
    
    if ($is_bond) {
        // Bond fees
        $face_value = $quantity;
        
        // Brokerage Commission: 0.063132% on first 100M, 0.035% on excess
        $brokerage_first_100m = min($face_value, 100000000) * (0.063132 / 100);
        $brokerage_excess = max($face_value - 100000000, 0) * (0.035 / 100);
        $fees['brokerage'] = $brokerage_first_100m + $brokerage_excess;
        
        // Store tier details
        if ($face_value <= 100000000) {
            $fees['tier_details'][] = [
                'amount' => $face_value,
                'rate' => 0.063132,
                'fee' => $brokerage_first_100m,
                'label' => number_format($face_value/1000000, 2) . 'M @ 0.063132%'
            ];
        } else {
            $fees['tier_details'][] = [
                'amount' => 100000000,
                'rate' => 0.063132,
                'fee' => $brokerage_first_100m,
                'label' => 'First 100M @ 0.063132%'
            ];
            $fees['tier_details'][] = [
                'amount' => $face_value - 100000000,
                'rate' => 0.035,
                'fee' => $brokerage_excess,
                'label' => 'Excess ' . number_format(($face_value - 100000000)/1000000, 2) . 'M @ 0.035%'
            ];
        }
        
        // VAT on Brokerage (18%)
        $vat_rate = 18.0000;
        $fees['vat'] = $fees['brokerage'] * ($vat_rate / 100);
        
        // Other fees
        $cmsa_rate = 0.0100;
        $fees['cmsa'] = $consideration * ($cmsa_rate / 100);
        
        $csd_rate = 0.0118;
        $fees['csd'] = $face_value * ($csd_rate / 100);
        
        $dse_rate = 0.02006;
        $fees['dse'] = $face_value * ($dse_rate / 100);
        
        $fees['fidelity'] = 0.00;
        
    } else {
        // Equity/ETF fees
        $total_brokerage = 0;
        
        // Tiered brokerage calculation
        if ($consideration <= 10000000) {
            $rate = 1.7000;
            $brokerage_fee = $consideration * ($rate / 100);
            $fees['tier_details'][] = [
                'amount' => $consideration,
                'rate' => $rate,
                'fee' => $brokerage_fee,
                'label' => 'Up to 10M @ ' . number_format($rate, 4) . '%'
            ];
            $total_brokerage = $brokerage_fee;
        } elseif ($consideration <= 50000000) {
            $rate1 = 1.7000;
            $rate2 = 1.5000;
            
            $tier1_amount = 10000000;
            $tier2_amount = $consideration - 10000000;
            
            $tier1_fee = $tier1_amount * ($rate1 / 100);
            $tier2_fee = $tier2_amount * ($rate2 / 100);
            
            $fees['tier_details'][] = [
                'amount' => $tier1_amount,
                'rate' => $rate1,
                'fee' => $tier1_fee,
                'label' => 'First 10M @ ' . number_format($rate1, 4) . '%'
            ];
            $fees['tier_details'][] = [
                'amount' => $tier2_amount,
                'rate' => $rate2,
                'fee' => $tier2_fee,
                'label' => 'Next ' . number_format($tier2_amount/1000000, 1) . 'M @ ' . number_format($rate2, 4) . '%'
            ];
            $total_brokerage = $tier1_fee + $tier2_fee;
        } else {
            $rate1 = 1.7000;
            $rate2 = 1.5000;
            $rate3 = 0.8000;
            
            $tier1_amount = 10000000;
            $tier2_amount = 40000000;
            $tier3_amount = $consideration - 50000000;
            
            $tier1_fee = $tier1_amount * ($rate1 / 100);
            $tier2_fee = $tier2_amount * ($rate2 / 100);
            $tier3_fee = $tier3_amount * ($rate3 / 100);
            
            $fees['tier_details'][] = [
                'amount' => $tier1_amount,
                'rate' => $rate1,
                'fee' => $tier1_fee,
                'label' => 'First 10M @ ' . number_format($rate1, 4) . '%'
            ];
            $fees['tier_details'][] = [
                'amount' => $tier2_amount,
                'rate' => $rate2,
                'fee' => $tier2_fee,
                'label' => 'Next 40M @ ' . number_format($rate2, 4) . '%'
            ];
            $fees['tier_details'][] = [
                'amount' => $tier3_amount,
                'rate' => $rate3,
                'fee' => $tier3_fee,
                'label' => 'Excess ' . number_format($tier3_amount/1000000, 1) . 'M @ ' . number_format($rate3, 4) . '%'
            ];
            $total_brokerage = $tier1_fee + $tier2_fee + $tier3_fee;
        }
        
        $fees['brokerage'] = $total_brokerage;
        
        // VAT on brokerage
        $vat_rate = 18.0000;
        $fees['vat'] = $fees['brokerage'] * ($vat_rate / 100);
        
        // CMSA Fee
        $cmsa_rate = 0.1400;
        $fees['cmsa'] = $consideration * ($cmsa_rate / 100);
        
        // DSE Fee
        $dse_rate = 0.1652;
        $fees['dse'] = $consideration * ($dse_rate / 100);
        
        // Fidelity Fee
        $fidelity_rate = 0.0200;
        $fees['fidelity'] = $consideration * ($fidelity_rate / 100);
        
        // CDS Fee
        $cds_rate = 0.0708;
        $fees['csd'] = $consideration * ($cds_rate / 100);
    }
    
    // Bank Charges (flat fee based on consideration, SELL only)
    if (strtoupper($trade_side) !== 'BUY') {
        if ($consideration < 100000) $fees['bank_charges'] = 250;
        elseif ($consideration < 10000000) $fees['bank_charges'] = 2000;
        elseif ($consideration < 50000000) $fees['bank_charges'] = 6000;
        else $fees['bank_charges'] = 12000;
    } else {
        $fees['bank_charges'] = 0;
    }
    
    $fees['total'] = array_sum([
        $fees['brokerage'] ?? 0,
        $fees['vat'] ?? 0,
        $fees['cmsa'] ?? 0,
        $fees['dse'] ?? 0,
        $fees['fidelity'] ?? 0,
        $fees['csd'] ?? 0,
        $fees['bank_charges'] ?? 0
    ]);
    
    return $fees;
}

// Function to send email with contract note using PHPMailer (Exodus Securities Style)
function sendContractNoteEmail($to_email, $to_name, $subject, $body, $pdf_path, $company_name, $from_email) {
    global $db, $company_phone;

    require_once '../phpmailer/src/Exception.php';
    require_once '../phpmailer/src/PHPMailer.php';
    require_once '../phpmailer/src/SMTP.php';

    $mail = new PHPMailer\PHPMailer\PHPMailer(true);

    try {
        error_log("=== STARTING EMAIL SEND FOR: $to_email ===");
        
        // Apply centralized SMTP + DKIM configuration
        configureMailer($mail);
        
        // Use relay-authorized From address for deliverability
        // Company email goes in Reply-To so clients reply to the right address
        error_log("Setting From: " . SMTP_FROM_EMAIL . ", " . $company_name);
        $mail->setFrom(SMTP_FROM_EMAIL, $company_name);
        
        error_log("Adding recipient: $to_email, $to_name");
        $mail->addAddress($to_email, $to_name);
        $mail->addReplyTo($from_email, $company_name);
        
        // Email content
        $mail->isHTML(true);
        $mail->Subject = $subject;
        
        // Create Exodus Securities style HTML email template
        $html_body = '
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <style>
                body { 
                    font-family: Arial, sans-serif; 
                    line-height: 1.6; 
                    color: #333; 
                    margin: 0;
                    padding: 0;
                    background-color: #f5f5f5;
                }
                .email-container {
                    max-width: 600px;
                    margin: 0 auto;
                    background: white;
                    padding: 20px;
                    border-radius: 8px;
                    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
                }
                .company-header {
                    text-align: center;
                    margin-bottom: 25px;
                    padding-bottom: 15px;
                    border-bottom: 2px solid #1e40af;
                }
                .company-name {
                    font-size: 24px;
                    font-weight: bold;
                    color: #1e40af;
                    margin: 0;
                    text-transform: uppercase;
                }
                .document-type {
                    font-size: 18px;
                    color: #4b5563;
                    margin: 10px 0 0 0;
                    font-weight: 600;
                }
                .content {
                    padding: 15px 0;
                }
                .greeting {
                    font-size: 18px;
                    font-weight: bold;
                    margin-bottom: 20px;
                    color: #111827;
                }
                .message {
                    margin: 15px 0;
                    line-height: 1.8;
                    font-size: 14px;
                }
                .attachment-notice {
                    background: #f8fafc;
                    border: 1px solid #d1d5db;
                    border-radius: 6px;
                    padding: 15px;
                    margin: 20px 0;
                    text-align: center;
                }
                .attachment-icon {
                    color: #1e40af;
                    font-size: 32px;
                    margin-bottom: 10px;
                }
                .attachment-text {
                    font-weight: 600;
                    color: #1e40af;
                    margin: 5px 0;
                }
                .support-section {
                    background: #f0f9ff;
                    border-left: 4px solid #3b82f6;
                    padding: 15px;
                    margin: 25px 0;
                    border-radius: 0 4px 4px 0;
                }
                .support-title {
                    font-weight: 600;
                    color: #1e40af;
                    margin-bottom: 8px;
                }
                .signature {
                    margin-top: 30px;
                    padding-top: 20px;
                    border-top: 1px solid #e5e7eb;
                }
                .company-signature {
                    font-weight: bold;
                    font-size: 16px;
                    color: #111827;
                    margin: 5px 0;
                }
                .disclaimer {
                    font-size: 12px;
                    color: #6b7280;
                    margin-top: 25px;
                    padding-top: 15px;
                    border-top: 1px dashed #d1d5db;
                    text-align: center;
                }
                .contact-info {
                    font-size: 13px;
                    color: #4b5563;
                    margin: 10px 0;
                }
                .contact-info strong {
                    color: #111827;
                }
            </style>
        </head>
        <body>
            <div class="email-container">
                <div class="company-header">
                    <div class="company-name">' . htmlspecialchars($company_name) . '</div>
                    <div class="document-type">Contract Note Delivery</div>
                </div>
                
                <div class="content">
                    <div class="greeting">Dear ' . htmlspecialchars($to_name) . ',</div>
                    
                    <div class="message">
                        <p>' . nl2br(htmlspecialchars($body)) . '</p>
                    </div>
                    
                    <div class="attachment-notice">
                        <div class="attachment-icon">📎</div>
                        <div class="attachment-text">Contract Note(s) Attached</div>
                        <p>Your contract note(s) are attached in PDF format for your records.</p>
                    </div>
                    
                    <div class="support-section">
                        <div class="support-title">Need Assistance?</div>
                        <p>If you have any questions regarding these transactions, please don\'t hesitate to contact our support team.</p>
                        <div class="contact-info">
                            <strong>Email:</strong> ' . htmlspecialchars($from_email) . '<br>
                            <strong>Phone:</strong> ' . htmlspecialchars($company_phone) . '
                        </div>
                    </div>
                    
                    <div class="signature">
                        <p>Best regards,</p>
                        <div class="company-signature">' . htmlspecialchars($company_name) . '</div>
                    </div>
                    
                    <div class="disclaimer">
                        <p>This is an automated email. Please do not reply directly to this message.</p>
                        <p>&copy; ' . date('Y') . ' ' . htmlspecialchars($company_name) . '. All rights reserved.</p>
                    </div>
                </div>
            </div>
        </body>
        </html>';
        
        $mail->Body = $html_body;
        
        // Plain text version (Exodus Securities style)
        $plain_text = strtoupper($company_name) . "\n";
        $plain_text .= str_repeat("=", strlen($company_name)) . "\n\n";
        $plain_text .= "Contract Note Delivery\n\n";
        $plain_text .= "Dear $to_name,\n\n";
        $plain_text .= "$body\n\n";
        $plain_text .= "Please find attached your contract note(s) in PDF format.\n\n";
        $plain_text .= "If you have any questions regarding these transactions, please don't hesitate to contact our support team.\n\n";
        $plain_text .= "Best regards,\n";
        $plain_text .= $company_name . "\n\n";
        $plain_text .= str_repeat("-", 50) . "\n";
        $plain_text .= "This is an automated email. Please do not reply directly to this message.\n";
        $plain_text .= "© " . date('Y') . " " . $company_name . ". All rights reserved.\n";
        
        $mail->AltBody = $plain_text;

        error_log("Email subject: $subject");
        error_log("Email body prepared");
        
        // Check if PDF exists
        $has_attachment = false;
        if ($pdf_path && file_exists($pdf_path)) {
            $file_size = filesize($pdf_path);
            error_log("PDF exists: $pdf_path ($file_size bytes)");
            
            if ($file_size > 0) {
                error_log("Attaching PDF...");
                if ($mail->addAttachment($pdf_path, 'Contract_Note_' . date('Y-m-d') . '.pdf')) {
                    $has_attachment = true;
                    error_log("PDF attachment added successfully");
                } else {
                    error_log("Failed to add PDF attachment");
                }
            } else {
                error_log("PDF file is empty: $pdf_path");
            }
        } else {
            error_log("PDF file does not exist: " . ($pdf_path ?: 'NULL'));
        }

        error_log("Has attachment: " . ($has_attachment ? 'YES' : 'NO'));
        
        // Send email
        error_log("Attempting to send email...");
        
        if ($mail->send()) {
            error_log("Email sent successfully!");
            
            // Log to database
            logEmailSent($db, $to_email, $to_name, $subject, $has_attachment ? 'Yes' : 'No');
            
            error_log("=== EMAIL SEND COMPLETE FOR: $to_email ===");
            return true;
        } else {
            $error_info = $mail->ErrorInfo;
            error_log("Email failed to send: $error_info");
            error_log("=== EMAIL SEND FAILED FOR: $to_email ===");
            return false;
        }

    } catch (Exception $e) {
        $error = 'PHPMailer exception: ' . $e->getMessage();
        error_log($error);
        error_log("=== EMAIL SEND EXCEPTION FOR: $to_email ===");
        return false;
    }
}

// Helper function to create a simple PDF if the main one fails
function createSimplePDF($client_name, $company_name) {
    global $temp_dir;
    
    require_once '../tcpdf/tcpdf.php';
    
    try {
        $pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
        
        // Set document information
        $pdf->SetCreator(PDF_CREATOR);
        $pdf->SetAuthor($company_name);
        $pdf->SetTitle('Contract Note');
        $pdf->SetSubject('Contract Note');
        
        // Set default header data
        $pdf->SetHeaderData('', 0, $company_name, 'Contract Note');
        
        // Set margins
        $pdf->SetMargins(15, 25, 15);
        $pdf->SetHeaderMargin(5);
        $pdf->SetFooterMargin(10);
        
        // Add a page
        $pdf->AddPage();
        
        // Add content
        $pdf->SetFont('helvetica', 'B', 16);
        $pdf->Cell(0, 10, 'CONTRACT NOTE', 0, 1, 'C');
        $pdf->Ln(10);
        
        $pdf->SetFont('helvetica', '', 12);
        $pdf->Cell(0, 10, 'Client: ' . $client_name, 0, 1);
        $pdf->Cell(0, 10, 'Date: ' . date('d/m/Y'), 0, 1);
        $pdf->Cell(0, 10, 'This is a contract note for your trades.', 0, 1);
        
        // Save the PDF
        $filename = 'simple_contract_' . time() . '.pdf';
        $filepath = $temp_dir . '/' . $filename;
        
        $pdf->Output($filepath, 'F');
        
        if (file_exists($filepath)) {
            error_log("Simple PDF created: $filepath");
            return $filepath;
        }
        
    } catch (Exception $e) {
        error_log("Failed to create simple PDF: " . $e->getMessage());
    }
    
    return false;
}

// Function to log email sending
function logEmailSent($db, $email, $client_name, $subject, $attachment) {
    $stmt = $db->prepare("
        INSERT INTO email_logs (email_to, client_name, email_subject, attachment, sent_by, sent_at) 
        VALUES (?, ?, ?, ?, ?, NOW())
    ");
    return $stmt->execute([$email, $client_name, $subject, $attachment, $_SESSION['user_id']]);
}

// Function to generate summary contract note for multiple trades (Equities/ETFs only)
function generateSummaryContractNote($client_id, $trade_date, $trade_side, $security_id, $company_name, $temp_dir) {
    global $db;
    
    // Get all trades for this client on the same day with same security and trade side
    $stmt = $db->prepare("
        SELECT t.*
        FROM trades t
        WHERE t.client_cds_account = ? 
        AND t.trade_date = ? 
        AND t.trade_side = ? 
        AND t.security_id = ?
        AND t.status = 'active'
        ORDER BY t.created_at
    ");
    $stmt->execute([$client_id, $trade_date, $trade_side, $security_id]);
    $trades = $stmt->fetchAll();
    
    if (empty($trades)) {
        error_log("No trades found for summary");
        return false;
    }
    
    // Calculate summary data
    $total_quantity = 0;
    $total_consideration = 0;
    $trade_count = count($trades);
    $client_name = $trades[0]['client_name'];
    $settlement_date = $trades[0]['settlement_date'];
    $asset_class = $trades[0]['asset_class'];
    
    foreach ($trades as $trade) {
        $total_quantity += floatval($trade['quantity']);
        $total_consideration += floatval($trade['consideration']);
    }
    
    $average_price = $total_consideration / $total_quantity;
    
    // Create a synthetic trade for the summary
    $summary_trade = $trades[0];
    $summary_trade['quantity'] = $total_quantity;
    $summary_trade['price'] = $average_price;
    $summary_trade['consideration'] = $total_consideration;
    
    // Calculate fees for the total consideration
    $summary_fees = calculateFees($db, $asset_class, $total_consideration, $total_quantity, $average_price, $trade_side);
    
    // Create PDF document
    $pdf = new ContractNotePDF(PDF_PAGE_ORIENTATION, PDF_UNIT, 'LETTER', true, 'UTF-8', false);

    // Set document information
    $pdf->SetCreator(PDF_CREATOR);
    $pdf->SetAuthor($company_name);
    $pdf->SetTitle('Contract Note Summary - ' . $client_id);
    $pdf->SetSubject('Trade Contract Note Summary');

    // Set watermark setting
    $pdf->setWatermarkEnabled(true);
    $pdf->setCompanyName($company_name);
    $pdf->setTotalTrades(1);

    // Set margins
    $pdf->SetMargins(25.4, 25, 25.4);
    $pdf->SetHeaderMargin(5);
    $pdf->SetFooterMargin(8);
    $pdf->SetAutoPageBreak(TRUE, 12);

    // Generate summary contract note
    $trade_side_upper = strtoupper(trim($trade_side));
    $contract_number = ($trade_side_upper === 'SELL' ? 'S' : 'P') . str_pad($trades[0]['id'], 6, '0', STR_PAD_LEFT) . '-SUM';
    $order_number = 'SUMMARY-' . date('ymd', strtotime($trade_date));
    $exchange_ref = date('ymd', strtotime($trade_date)) . 'SUM';
    
    $summary_data = [
        'total_quantity' => $total_quantity,
        'average_price' => $average_price,
        'trade_count' => $trade_count,
        'total_consideration' => $total_consideration
    ];
    
    $pdf->setCurrentTrade(1);
    
    // Call addContractNote with summary flag
    $pdf->addContractNote($summary_trade, $summary_fees, $contract_number, $order_number, $exchange_ref, true, $summary_data);
    
    // Add breakdown page
    $pdf->addSummaryBreakdown($trades, $summary_data, $client_name, $trade_date, $security_id, $trade_side);

    // Generate filename and save
    $filename = 'contract_note_summary_' . $client_id . '_' . date('Ymd_His') . '.pdf';
    $filepath = $temp_dir . '/' . $filename;
    
    // Save PDF to file
    error_log("Saving PDF to: $filepath");
    $pdf->Output($filepath, 'F');
    
    // Check if file was created
    if (file_exists($filepath)) {
        $file_size = filesize($filepath);
        error_log("Summary PDF generated successfully: $filepath ($file_size bytes)");
        return $filepath;
    } else {
        error_log("Summary PDF file was not created: $filepath");
        return false;
    }
}

// Function to generate detailed contract notes for multiple trades
function generateDetailedContractNotes($client_id, $trade_date, $trade_side, $security_id, $company_name, $temp_dir) {
    global $db;
    
    // Get all trades for this client on the same day with same security and trade side
    $stmt = $db->prepare("
        SELECT t.*
        FROM trades t
        WHERE t.client_cds_account = ? 
        AND t.trade_date = ? 
        AND t.trade_side = ? 
        AND t.security_id = ?
        AND t.status = 'active'
        ORDER BY t.created_at
    ");
    $stmt->execute([$client_id, $trade_date, $trade_side, $security_id]);
    $trades = $stmt->fetchAll();
    
    if (empty($trades)) {
        error_log("No trades found for detailed notes");
        return false;
    }
    
    // Create PDF document
    $pdf = new ContractNotePDF(PDF_PAGE_ORIENTATION, PDF_UNIT, 'LETTER', true, 'UTF-8', false);

    // Set document information
    $pdf->SetCreator(PDF_CREATOR);
    $pdf->SetAuthor($company_name);
    $pdf->SetTitle('Contract Notes - ' . $client_id);
    $pdf->SetSubject('Detailed Trade Contract Notes');

    // Set watermark setting
    $pdf->setWatermarkEnabled(true);
    $pdf->setCompanyName($company_name);
    $pdf->setTotalTrades(count($trades));

    // Set margins
    $pdf->SetMargins(25.4, 25, 25.4);
    $pdf->SetHeaderMargin(5);
    $pdf->SetFooterMargin(8);
    $pdf->SetAutoPageBreak(TRUE, 12);

    // Generate contract notes for each trade
    $trade_index = 1;
    foreach ($trades as $trade) {
        $fees = calculateFees($db, $trade['asset_class'], 
                             floatval($trade['consideration']), 
                             floatval($trade['quantity']), 
                             floatval($trade['price']),
                             $trade['trade_side'] ?? 'Sell');
        
        $trade_side_upper = strtoupper(trim($trade['trade_side']));
        $contract_number = ($trade_side_upper === 'SELL' ? 'S' : 'P') . str_pad($trade['id'], 6, '0', STR_PAD_LEFT);
        $order_number = str_pad($trade['id'], 6, '0', STR_PAD_LEFT);
        $exchange_ref = date('ymd', strtotime($trade['trade_date'])) . str_pad($trade['id'], 3, '0', STR_PAD_LEFT);
        
        $pdf->setCurrentTrade($trade_index);
        $pdf->addContractNote($trade, $fees, $contract_number, $order_number, $exchange_ref);
        
        $trade_index++;
    }

    // Generate filename and save
    $filename = 'contract_notes_detailed_' . $client_id . '_' . date('Ymd_His') . '.pdf';
    $filepath = $temp_dir . '/' . $filename;
    
    // Save PDF to file
    error_log("Saving detailed PDF to: $filepath");
    $pdf->Output($filepath, 'F');
    
    // Check if file was created
    if (file_exists($filepath)) {
        $file_size = filesize($filepath);
        error_log("Detailed PDF generated successfully: $filepath ($file_size bytes)");
        return $filepath;
    } else {
        error_log("Detailed PDF file was not created: $filepath");
        return false;
    }
}

// Function to generate single contract note
function generateSingleContractNote($trade, $company_name, $temp_dir) {
    global $db;
    
    // Calculate fees
    $fees = calculateFees($db, $trade['asset_class'], 
                         floatval($trade['consideration']), 
                         floatval($trade['quantity']), 
                         floatval($trade['price']),
                         $trade['trade_side'] ?? 'Sell');
    
    // Create PDF document
    $pdf = new ContractNotePDF(PDF_PAGE_ORIENTATION, PDF_UNIT, 'LETTER', true, 'UTF-8', false);

    // Set document information
    $pdf->SetCreator(PDF_CREATOR);
    $pdf->SetAuthor($company_name);
    $pdf->SetTitle('Contract Note - ' . $trade['trade_reference']);
    $pdf->SetSubject('Trade Contract Note');

    // Set watermark setting
    $pdf->setWatermarkEnabled(true);
    $pdf->setCompanyName($company_name);
    $pdf->setTotalTrades(1);

    // Set default header data
    $pdf->SetHeaderData('', 0, '', '');

    // Set 1" margins (25.4mm = 1 inch)
    $pdf->SetMargins(25.4, 25, 25.4);
    $pdf->SetHeaderMargin(5);
    $pdf->SetFooterMargin(8);

    // Set auto page breaks with minimal bottom margin
    $pdf->SetAutoPageBreak(TRUE, 12);

    // Generate contract note
    $trade_side = strtoupper(trim($trade['trade_side']));
    $contract_number = ($trade_side === 'SELL' ? 'S' : 'P') . str_pad($trade['id'], 6, '0', STR_PAD_LEFT);
    $order_number = str_pad($trade['id'], 6, '0', STR_PAD_LEFT);
    $exchange_ref = date('ymd', strtotime($trade['trade_date'])) . str_pad($trade['id'], 3, '0', STR_PAD_LEFT);
    
    $pdf->setCurrentTrade(1);
    $pdf->addContractNote($trade, $fees, $contract_number, $order_number, $exchange_ref);

    // Generate filename and save
    $filename = 'contract_note_' . $trade['trade_reference'] . '_' . date('Ymd_His') . '.pdf';
    $filepath = $temp_dir . '/' . $filename;
    
    // Save PDF to file
    error_log("Saving single PDF to: $filepath");
    $pdf->Output($filepath, 'F');
    
    // Check if file was created
    if (file_exists($filepath)) {
        $file_size = filesize($filepath);
        error_log("Single PDF generated successfully: $filepath ($file_size bytes)");
        return $filepath;
    } else {
        error_log("Single PDF file was not created: $filepath");
        return false;
    }
}

// Main function to generate contract note PDF for selected trades
function generateContractNotePDF($trades, $client_info, $send_type = 'individual') {
    global $db, $company_name, $temp_dir;
    
    error_log("=== STARTING PDF GENERATION ===");
    error_log("Client: " . $client_info['client_name']);
    error_log("Client Email: " . ($client_info['client_email'] ?? 'NO EMAIL'));
    error_log("Number of trades: " . count($trades));
    error_log("Temp directory: $temp_dir");
    error_log("Is temp dir writable? " . (is_writable($temp_dir) ? 'YES' : 'NO'));
    
    if (empty($trades)) {
        error_log("No trades provided for PDF generation");
        return false;
    }
    
    try {
        // Check if client has multiple trades on the same day with same security
        // Group trades by date and security
        $trades_by_day_security = [];
        foreach ($trades as $trade) {
            $key = $trade['trade_date'] . '_' . $trade['security_id'] . '_' . $trade['trade_side'];
            if (!isset($trades_by_day_security[$key])) {
                $trades_by_day_security[$key] = [];
            }
            $trades_by_day_security[$key][] = $trade;
        }
        
        $pdf_path = false;
        
        // If only one trade or send_type is 'individual', generate individual notes
        if ($send_type === 'individual' || count($trades) === 1) {
            error_log("Generating individual contract notes");
            
            // Create PDF for each trade
            $pdf = new ContractNotePDF(PDF_PAGE_ORIENTATION, PDF_UNIT, 'LETTER', true, 'UTF-8', false);
            $pdf->SetCreator(PDF_CREATOR);
            $pdf->SetAuthor($company_name);
            $pdf->SetTitle('Contract Notes - ' . $client_info['client_name']);
            $pdf->SetSubject('Trade Contract Notes');
            $pdf->setWatermarkEnabled(true);
            $pdf->setCompanyName($company_name);
            $pdf->setTotalTrades(count($trades));
            $pdf->SetMargins(25.4, 25, 25.4);
            $pdf->SetHeaderMargin(5);
            $pdf->SetFooterMargin(8);
            $pdf->SetAutoPageBreak(TRUE, 12);
            
            $trade_index = 1;
            foreach ($trades as $trade) {
                $fees = calculateFees($db, $trade['asset_class'], 
                                     floatval($trade['consideration']), 
                                     floatval($trade['quantity']), 
                                     floatval($trade['price']),
                                     $trade['trade_side'] ?? 'Sell');
                
                $trade_side_upper = strtoupper(trim($trade['trade_side']));
                $contract_number = ($trade_side_upper === 'SELL' ? 'S' : 'P') . str_pad($trade['id'], 6, '0', STR_PAD_LEFT);
                $order_number = str_pad($trade['id'], 6, '0', STR_PAD_LEFT);
                $exchange_ref = date('ymd', strtotime($trade['trade_date'])) . str_pad($trade['id'], 3, '0', STR_PAD_LEFT);
                
                $pdf->setCurrentTrade($trade_index);
                $pdf->addContractNote($trade, $fees, $contract_number, $order_number, $exchange_ref);
                
                $trade_index++;
            }
            
            // Generate filename and save
            $filename = 'contract_notes_' . $client_info['client_id'] . '_' . date('Ymd_His') . '.pdf';
            $pdf_path = $temp_dir . '/' . $filename;
            $pdf->Output($pdf_path, 'F');
            
        } else {
            // For consolidated notes, check each day/security group
            error_log("Generating consolidated/summary notes");
            
            $pdf_paths = [];
            
            foreach ($trades_by_day_security as $key => $day_trades) {
                if (count($day_trades) > 1) {
                    // Multiple trades on same day/security - generate summary note
                    error_log("Multiple trades found for key: $key - generating summary note");
                    
                    $first_trade = $day_trades[0];
                    $client_id = $first_trade['client_cds_account'];
                    $trade_date = $first_trade['trade_date'];
                    $trade_side = $first_trade['trade_side'];
                    $security_id = $first_trade['security_id'];
                    
                    // Check if it's equity/ETF (only these support summary notes)
                    $is_equity_or_etf = ($first_trade['asset_class'] === 'equity' || $first_trade['asset_class'] === 'Exchange Traded Funds');
                    
                    if ($is_equity_or_etf) {
                        $pdf_path = generateSummaryContractNote($client_id, $trade_date, $trade_side, $security_id, $company_name, $temp_dir);
                    } else {
                        // For bonds, generate detailed notes
                        $pdf_path = generateDetailedContractNotes($client_id, $trade_date, $trade_side, $security_id, $company_name, $temp_dir);
                    }
                    
                    if ($pdf_path) {
                        $pdf_paths[] = $pdf_path;
                    }
                } else {
                    // Single trade for this day/security - generate single note
                    error_log("Single trade found for key: $key - generating single note");
                    $trade = $day_trades[0];
                    $pdf_path = generateSingleContractNote($trade, $company_name, $temp_dir);
                    
                    if ($pdf_path) {
                        $pdf_paths[] = $pdf_path;
                    }
                }
            }
            
            // If we have multiple PDFs, combine them
            if (count($pdf_paths) > 1) {
                // For now, just use the first PDF (we could combine them later)
                $pdf_path = $pdf_paths[0];
                error_log("Multiple PDFs generated, using first one: " . basename($pdf_path));
            } elseif (count($pdf_paths) === 1) {
                $pdf_path = $pdf_paths[0];
            } else {
                error_log("No PDFs were generated");
                return false;
            }
        }
        
        // Check if file was created
        if ($pdf_path && file_exists($pdf_path)) {
            $file_size = filesize($pdf_path);
            error_log("PDF generated successfully: $pdf_path ($file_size bytes)");
            return $pdf_path;
        } else {
            error_log("PDF file was not created");
            return false;
        }
        
    } catch (Exception $e) {
        error_log("PDF generation failed: " . $e->getMessage());
        error_log("Stack trace: " . $e->getTraceAsString());
        return false;
    }
}

// Handle test requests
if (isset($_GET['test_smtp'])) {
    echo "<h2>Testing SMTP Connection...</h2>";
    echo "<pre>";
    echo testSMTPConnection(true);
    echo "</pre>";
    exit;
}

if (isset($_GET['test_email'])) {
    $test_email = $_GET['test_email'] ?? 'ernestmswima@gmail.com';
    $test_name = 'Test User';
    
    echo "<h2>Sending Test Email to: $test_email</h2>";
    
    $result = sendContractNoteEmail(
        $test_email,
        $test_name,
        'Test Email from Neovam',
        'This is a test email from the contract notes system.',
        null,
        $company_name,
        $company_email
    );
    
    echo "<p>Result: " . ($result ? "SUCCESS" : "FAILED") . "</p>";
    echo "<p>Check your email inbox and spam folder for the test email.</p>";
    exit;
}

// Handle form submission - FIXED VERSION
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    error_log("=== FORM SUBMISSION DETECTED ===");
    error_log("POST data keys: " . implode(', ', array_keys($_POST)));
    
    // Check which form was submitted
    if (isset($_POST['apply_filters'])) {
        error_log("Filter form submitted");
        $filters = $_POST;
    } elseif (isset($_POST['send_emails'])) {
        error_log("Send emails form submitted");
        
        $trade_ids = $_POST['trade_ids'] ?? [];
        $send_type = $_POST['send_type'] ?? 'individual';
        $email_subject = $_POST['email_subject'] ?? 'Contract Note - ' . $company_name;
        $email_body = $_POST['email_body'] ?? '';
        
        error_log("Trade IDs count: " . count($trade_ids));
        error_log("Trade IDs: " . implode(', ', $trade_ids));
        error_log("Send type: $send_type");
        
        if (empty($trade_ids)) {
            $error_message = 'Please select at least one trade.';
            error_log("ERROR: No trade IDs selected");
        } else {
            error_log("=== STARTING EMAIL SENDING PROCESS ===");
            error_log("Total trades selected: " . count($trade_ids));
            
            // Group trades by client
            $trades_by_client = [];
            
            // Get all selected trades with client info
            $placeholders = str_repeat('?,', count($trade_ids) - 1) . '?';
            $trades_stmt = $db->prepare("
                SELECT t.*, 
                       c.id as client_id, 
                       c.client_name, 
                       c.email as client_email,
                       c.cds_account
                FROM trades t
                LEFT JOIN clients c ON t.client_cds_account = c.cds_account
                WHERE t.id IN ($placeholders)
                AND c.status = 'active'
                ORDER BY t.client_cds_account, t.trade_date
            ");
            
            error_log("SQL: SELECT t.*, c.id as client_id, c.client_name, c.email as client_email, c.cds_account FROM trades t LEFT JOIN clients c ON t.client_cds_account = c.cds_account WHERE t.id IN (" . implode(',', $trade_ids) . ") AND c.status = 'active'");
            
            $trades_stmt->execute($trade_ids);
            $selected_trades = $trades_stmt->fetchAll();
            
            error_log("Found " . count($selected_trades) . " trades in database");
            
            if (empty($selected_trades)) {
                $error_message = 'No valid trades found with active clients.';
                error_log("ERROR: No valid trades found");
            } else {
                // Group trades by client
                foreach ($selected_trades as $trade) {
                    $client_id = $trade['client_id'];
                    if (!isset($trades_by_client[$client_id])) {
                        $trades_by_client[$client_id] = [
                            'client_id' => $client_id,
                            'client_name' => $trade['client_name'],
                            'client_email' => $trade['client_email'],
                            'trades' => []
                        ];
                    }
                    $trades_by_client[$client_id]['trades'][] = $trade;
                }
                
                error_log("Grouped into " . count($trades_by_client) . " clients");
                
                // Process each client
                foreach ($trades_by_client as $client_info) {
                    error_log("Processing client: " . $client_info['client_name']);
                    
                    if (empty($client_info['client_email'])) {
                        $results[] = [
                            'status' => 'error',
                            'client' => $client_info['client_name'],
                            'message' => 'No email address found for client'
                        ];
                        error_log("No email for client: " . $client_info['client_name']);
                        continue;
                    }
                    
                    // Generate PDF for this client's trades
                    error_log("Generating PDF for client: " . $client_info['client_name']);
                    $pdf_path = generateContractNotePDF($client_info['trades'], $client_info, $send_type);
                    
                    if (!$pdf_path) {
                        error_log("PDF generation failed for: " . $client_info['client_name']);
                        $results[] = [
                            'status' => 'error',
                            'client' => $client_info['client_name'],
                            'message' => 'Failed to generate PDF'
                        ];
                        continue;
                    }
                    
                    // Prepare email body with placeholders
                    $trade_count = count($client_info['trades']);
                    $first_trade = $client_info['trades'][0];
                    $last_trade = $client_info['trades'][$trade_count - 1];
                    
                    $personalized_body = str_replace(
                        ['{client_name}', '{trade_count}', '{date_range}'],
                        [
                            $client_info['client_name'],
                            $trade_count,
                            date('d/m/Y', strtotime($first_trade['trade_date'])) . ' - ' . date('d/m/Y', strtotime($last_trade['trade_date']))
                        ],
                        $email_body
                    );
                    
                    error_log("Sending email to: " . $client_info['client_email'] . " (" . $client_info['client_name'] . ")");
                    
                    // Send email using PHPMailer with Exodus style
                    $email_sent = sendContractNoteEmail(
                        $client_info['client_email'],
                        $client_info['client_name'],
                        $email_subject,
                        $personalized_body,
                        $pdf_path,
                        $company_name,
                        $company_email
                    );
                    
                    if ($email_sent) {
                        $results[] = [
                            'status' => 'success',
                            'client' => $client_info['client_name'],
                            'email' => $client_info['client_email'],
                            'trades' => $trade_count,
                            'message' => 'Contract notes sent successfully'
                        ];
                        error_log("Email sent successfully to: " . $client_info['client_email']);
                    } else {
                        $results[] = [
                            'status' => 'error',
                            'client' => $client_info['client_name'],
                            'message' => 'Failed to send email'
                        ];
                        error_log("Email failed for: " . $client_info['client_email']);
                    }
                    
                    // Clean up temp file
                    if ($pdf_path && file_exists($pdf_path)) {
                        if (unlink($pdf_path)) {
                            error_log("Cleaned up PDF: $pdf_path");
                        }
                    }
                }
                
                if (!empty($results)) {
                    $success_count = count(array_filter($results, fn($r) => $r['status'] === 'success'));
                    $error_count = count(array_filter($results, fn($r) => $r['status'] === 'error'));
                    
                    $success_message = "Email sending completed: {$success_count} successful, {$error_count} failed.";
                    error_log("=== EMAIL SENDING COMPLETE: $success_count success, $error_count failed ===");
                }
            }
        }
    }
}

// Apply filters from POST or GET
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['apply_filters'])) {
    $filters = $_POST;
} elseif (isset($_GET['client_id']) || isset($_GET['trade_date_from'])) {
    $filters = $_GET;
} else {
    $filters = [
        'trade_date_from' => date('Y-m-d', strtotime('-30 days')),
        'trade_date_to' => date('Y-m-d')
    ];
}

// Get trades data for display
$where_conditions = ["t.status = 'active'"];
$params = [];

if (isset($filters['client_id']) && !empty($filters['client_id'])) {
    $where_conditions[] = "c.id = ?";
    $params[] = $filters['client_id'];
}

if (isset($filters['trade_date_from']) && !empty($filters['trade_date_from'])) {
    $where_conditions[] = "t.trade_date >= ?";
    $params[] = $filters['trade_date_from'];
}

if (isset($filters['trade_date_to']) && !empty($filters['trade_date_to'])) {
    $where_conditions[] = "t.trade_date <= ?";
    $params[] = $filters['trade_date_to'];
}

if (isset($filters['asset_class']) && !empty($filters['asset_class'])) {
    $where_conditions[] = "t.asset_class = ?";
    $params[] = $filters['asset_class'];
}

if (isset($filters['trade_side']) && !empty($filters['trade_side'])) {
    $where_conditions[] = "t.trade_side = ?";
    $params[] = $filters['trade_side'];
}

if (isset($filters['security_id']) && !empty($filters['security_id'])) {
    $where_conditions[] = "t.security_id LIKE ?";
    $params[] = '%' . $filters['security_id'] . '%';
}

$where_clause = !empty($where_conditions) ? "WHERE " . implode(' AND ', $where_conditions) : "";

// Get all active trades with client info for selection
$trades_stmt = $db->prepare("
    SELECT t.*, 
           c.id as client_id, 
           c.client_name, 
           c.email as client_email,
           c.cds_account
    FROM trades t
    LEFT JOIN clients c ON t.client_cds_account = c.cds_account
    $where_clause
    AND c.status = 'active'
    ORDER BY t.trade_date DESC, t.created_at DESC
    LIMIT 1000
");

$trades_stmt->execute($params);
$all_trades = $trades_stmt->fetchAll();

// Get all active clients for filter dropdown
$clients_stmt = $db->query("
    SELECT id, client_name, cds_account, email
    FROM clients 
    WHERE status = 'active' 
    ORDER BY client_name
");
$all_clients = $clients_stmt->fetchAll();

$page_title = 'Send Contract Notes - Select Trades';
include '../includes/header.php';
?>

<!-- Debug Information Section -->
<div class="container-fluid py-4">
    <?php if ($success_message): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="bi bi-check-circle-fill me-2"></i>
            <?php echo $success_message; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if ($error_message): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="bi bi-exclamation-triangle-fill me-2"></i>
            <?php echo $error_message; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="row">
        <!-- Left Column: Trades List with Filters -->
        <div class="col-lg-8">
            <!-- Filters Card -->
            <div class="card mb-4">
                <div class="card-header bg-transparent border-bottom">
                    <h5 class="mb-0"><i class="bi bi-funnel me-2"></i>Filter Trades</h5>
                </div>
                <div class="card-body">
                    <form method="POST" action="" id="filterForm">
                        <input type="hidden" name="apply_filters" value="1">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="client_id" class="form-label">Client</label>
                                <select class="form-select" id="client_id" name="client_id">
                                    <option value="">All Clients</option>
                                    <?php foreach ($all_clients as $client): ?>
                                        <option value="<?php echo $client['id']; ?>" 
                                            <?php echo isset($filters['client_id']) && $filters['client_id'] == $client['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($client['client_name']); ?>
                                            <?php if (empty($client['email'])): ?>
                                                <span class="text-danger">(No email)</span>
                                            <?php endif; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="col-md-6">
                                <label for="asset_class" class="form-label">Asset Type</label>
                                <select class="form-select" id="asset_class" name="asset_class">
                                    <option value="">All Types</option>
                                    <option value="bond" <?php echo isset($filters['asset_class']) && $filters['asset_class'] == 'bond' ? 'selected' : ''; ?>>Bond</option>
                                    <option value="equity" <?php echo isset($filters['asset_class']) && $filters['asset_class'] == 'equity' ? 'selected' : ''; ?>>Equity</option>
                                    <option value="Exchange Traded Funds" <?php echo isset($filters['asset_class']) && $filters['asset_class'] == 'Exchange Traded Funds' ? 'selected' : ''; ?>>ETF</option>
                                </select>
                            </div>
                            
                            <div class="col-md-6">
                                <label for="trade_date_from" class="form-label">Trade Date From</label>
                                <input type="date" class="form-control" id="trade_date_from" name="trade_date_from" 
                                       value="<?php echo $filters['trade_date_from'] ?? date('Y-m-d', strtotime('-30 days')); ?>">
                            </div>
                            
                            <div class="col-md-6">
                                <label for="trade_date_to" class="form-label">Trade Date To</label>
                                <input type="date" class="form-control" id="trade_date_to" name="trade_date_to" 
                                       value="<?php echo $filters['trade_date_to'] ?? date('Y-m-d'); ?>">
                            </div>
                            
                            <div class="col-md-6">
                                <label for="trade_side" class="form-label">Trade Side</label>
                                <select class="form-select" id="trade_side" name="trade_side">
                                    <option value="">All Sides</option>
                                    <option value="buy" <?php echo isset($filters['trade_side']) && $filters['trade_side'] == 'buy' ? 'selected' : ''; ?>>Buy</option>
                                    <option value="sell" <?php echo isset($filters['trade_side']) && $filters['trade_side'] == 'sell' ? 'selected' : ''; ?>>Sell</option>
                                </select>
                            </div>
                            
                            <div class="col-md-6">
                                <label for="security_id" class="form-label">Security ID</label>
                                <input type="text" class="form-control" id="security_id" name="security_id" 
                                       value="<?php echo $filters['security_id'] ?? ''; ?>" 
                                       placeholder="Enter security ID...">
                            </div>
                            
                            <div class="col-md-12">
                                <div class="d-flex justify-content-between">
                                    <button type="submit" name="apply_filters" class="btn btn-primary">
                                        <i class="bi bi-funnel me-1"></i>
                                        Apply Filters
                                    </button>
                                    <button type="button" class="btn btn-outline-secondary" onclick="resetFilters()">
                                        <i class="bi bi-arrow-clockwise me-1"></i>
                                        Reset Filters
                                    </button>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
            
            <!-- Trades List Card -->
            <div class="card">
                <div class="card-header bg-transparent border-bottom">
                    <div class="d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><i class="bi bi-list-ul me-2"></i>Select Trades</h5>
                        <div class="d-flex align-items-center gap-2">
                            <span class="badge bg-primary" id="selectedTradesCount">0</span>
                            <button type="button" class="btn btn-sm btn-outline-primary" onclick="selectAllVisible()">
                                <i class="bi bi-check-all"></i> Select All
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="deselectAll()">
                                <i class="bi bi-x-circle"></i> Clear All
                            </button>
                        </div>
                    </div>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($all_trades)): ?>
                        <div class="text-center py-5">
                            <i class="bi bi-inbox text-muted" style="font-size: 3rem;"></i>
                            <h5 class="mt-3">No trades found</h5>
                            <p class="text-muted">Try adjusting your filters</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive" style="max-height: 500px; overflow-y: auto;">
                            <table class="table table-hover mb-0">
                                <thead style="position: sticky; top: 0; background: white; z-index: 1;">
                                    <tr>
                                        <th width="50">
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" id="selectAllHeader">
                                            </div>
                                        </th>
                                        <th>Trade Details</th>
                                        <th>Client</th>
                                        <th>Value</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($all_trades as $trade): 
                                        $has_email = !empty($trade['client_email']);
                                    ?>
                                        <tr class="trade-row <?php echo !$has_email ? 'table-warning' : ''; ?>">
                                            <td>
                                                <div class="form-check">
                                                    <input class="form-check-input trade-checkbox" type="checkbox" 
                                                           name="trade_ids[]" value="<?php echo $trade['id']; ?>"
                                                           id="trade_<?php echo $trade['id']; ?>"
                                                           data-client="<?php echo htmlspecialchars($trade['client_name']); ?>"
                                                           data-email="<?php echo htmlspecialchars($trade['client_email'] ?? ''); ?>"
                                                           data-value="<?php echo $trade['consideration']; ?>"
                                                           <?php echo !$has_email ? 'disabled' : ''; ?>>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="fw-medium"><?php echo htmlspecialchars($trade['trade_reference']); ?></div>
                                                <div class="small text-muted">
                                                    <?php echo htmlspecialchars($trade['security_id']); ?> • 
                                                    <?php echo ucfirst($trade['asset_class']); ?> • 
                                                    <?php echo strtoupper($trade['trade_side']); ?>
                                                </div>
                                                <div class="small">
                                                    <?php echo date('d/m/Y', strtotime($trade['trade_date'])); ?> • 
                                                    Qty: <?php echo number_format($trade['quantity']); ?>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="fw-medium"><?php echo htmlspecialchars($trade['client_name']); ?></div>
                                                <div class="small text-muted"><?php echo htmlspecialchars($trade['cds_account']); ?></div>
                                                <div class="small">
                                                    <?php if ($has_email): ?>
                                                        <i class="bi bi-envelope-check text-success"></i>
                                                        <?php echo htmlspecialchars($trade['client_email']); ?>
                                                    <?php else: ?>
                                                        <i class="bi bi-envelope-slash text-danger"></i>
                                                        <span class="text-danger">No email</span>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="fw-bold text-success">
                                                    TZS <?php echo number_format($trade['consideration'], 2); ?>
                                                </div>
                                                <div class="small text-muted">
                                                    @ TZS <?php echo number_format($trade['price'], 2); ?>
                                                </div>
                                            </td>
                                            <td>
                                                <span class="badge bg-<?php 
                                                    echo $trade['status'] == 'active' ? 'success' : 
                                                        ($trade['status'] == 'cancelled' ? 'danger' : 'info'); 
                                                ?>">
                                                    <?php echo ucfirst($trade['status']); ?>
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <!-- Right Column: Email Configuration -->
        <div class="col-lg-4">
            <form method="POST" action="" id="emailForm">
                <input type="hidden" name="send_emails" value="1">
                <div class="card sticky-top" style="top: 20px;">
                    <div class="card-header bg-transparent border-bottom">
                        <h5 class="mb-0"><i class="bi bi-gear me-2"></i>Email Configuration</h5>
                    </div>
                    <div class="card-body">
                        <div class="mb-4">
                            <h6 class="mb-3">Selected Summary</h6>
                            <div class="row g-2 mb-3">
                                <div class="col-6">
                                    <div class="border rounded p-2 text-center">
                                        <div class="fw-bold fs-5 text-primary" id="summaryCount">0</div>
                                        <small class="text-muted">Trades</small>
                                    </div>
                                </div>
                                <div class="col-6">
                                    <div class="border rounded p-2 text-center">
                                        <div class="fw-bold fs-5 text-success" id="summaryValue">0</div>
                                        <small class="text-muted">Total Value</small>
                                    </div>
                                </div>
                                <div class="col-6">
                                    <div class="border rounded p-2 text-center">
                                        <div class="fw-bold fs-5 text-info" id="summaryClients">0</div>
                                        <small class="text-muted">Clients</small>
                                    </div>
                                </div>
                                <div class="col-6">
                                    <div class="border rounded p-2 text-center">
                                        <div class="fw-bold fs-5 <?php echo '#4f46e5'; ?>" id="summaryEmails">0</div>
                                        <small class="text-muted">With Email</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="email_subject" class="form-label">Email Subject</label>
                            <input type="text" class="form-control" id="email_subject" name="email_subject" 
                                   value="Contract Note - <?php echo $company_name; ?>" required>
                        </div>
                        
                        <div class="mb-3">
                            <label for="send_type" class="form-label">Sending Method</label>
                            <select class="form-select" id="send_type" name="send_type">
                                <option value="individual">Individual contract notes</option>
                                <option value="consolidated">Consolidated note per client</option>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label for="email_body" class="form-label">Email Body</label>
                            <textarea class="form-control" id="email_body" name="email_body" rows="6" required>Dear {client_name},

Please find attached your contract note(s) for {trade_count} trade(s) executed between {date_range}.

If you have any questions regarding these transactions, please don't hesitate to contact our support team.

Best regards,
<?php echo $company_name; ?></textarea>
                            <small class="form-text text-muted">
                                Placeholders: {client_name}, {trade_count}, {date_range}
                            </small>
                        </div>
                        
                        <div class="mt-4 pt-3 border-top">
                            <div class="d-grid gap-2">
                                <button type="submit" class="btn btn-primary btn-lg" id="sendButton" disabled>
                                    <i class="bi bi-send-check me-2"></i>
                                    Send Selected Trades
                                </button>
                                <button type="button" class="btn btn-outline-secondary" onclick="previewSelection()">
                                    <i class="bi bi-eye me-2"></i>
                                    Preview Selection
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </form>
            
            <!-- Results Card (shown after sending) -->
            <?php if (!empty($results)): ?>
                <div class="card mt-4">
                    <div class="card-header bg-transparent border-bottom">
                        <h6 class="mb-0"><i class="bi bi-list-check me-2"></i>Sending Results</h6>
                    </div>
                    <div class="card-body p-0">
                        <div class="list-group list-group-flush">
                            <?php foreach ($results as $result): ?>
                                <div class="list-group-item">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <h6 class="mb-0"><?php echo htmlspecialchars($result['client']); ?></h6>
                                            <small class="text-muted"><?php echo $result['trades'] ?? 0; ?> trades</small>
                                        </div>
                                        <span class="badge bg-<?php echo $result['status'] === 'success' ? 'success' : 'danger'; ?>">
                                            <?php echo $result['status'] === 'success' ? 'Sent' : 'Failed'; ?>
                                        </span>
                                    </div>
                                    <small class="text-muted d-block mt-1"><?php echo htmlspecialchars($result['message']); ?></small>
                                    <?php if (isset($result['email'])): ?>
                                        <small class="text-muted">Sent to: <?php echo htmlspecialchars($result['email']); ?></small>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Preview Modal -->
<div class="modal fade" id="previewModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Preview Selected Trades</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="previewContent"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<style>
.trade-row:hover {
    background-color: #f8f9fa;
}

.sticky-top {
    z-index: 100;
}

#sendButton:disabled {
    opacity: 0.6;
    cursor: not-allowed;
}

.table-responsive::-webkit-scrollbar {
    width: 8px;
    height: 8px;
}

.table-responsive::-webkit-scrollbar-track {
    background: #f1f1f1;
    border-radius: 4px;
}

.table-responsive::-webkit-scrollbar-thumb {
    background: #c1c1c1;
    border-radius: 4px;
}

.table-responsive::-webkit-scrollbar-thumb:hover {
    background: #a8a8a8;
}

.no-email-row {
    background-color: #fff3cd !important;
}

.no-email-row:hover {
    background-color: #ffeaa7 !important;
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Select all header checkbox
    const selectAllHeader = document.getElementById('selectAllHeader');
    const tradeCheckboxes = document.querySelectorAll('.trade-checkbox:not(:disabled)');
    
    selectAllHeader.addEventListener('change', function() {
        tradeCheckboxes.forEach(checkbox => {
            checkbox.checked = this.checked;
        });
        updateSummary();
    });
    
    // Individual checkbox change
    tradeCheckboxes.forEach(checkbox => {
        checkbox.addEventListener('change', updateSummary);
    });
    
    // Update summary statistics
    function updateSummary() {
        const selectedCheckboxes = document.querySelectorAll('.trade-checkbox:checked');
        const selectedTrades = Array.from(selectedCheckboxes);
        
        // Count unique clients and those with email
        const uniqueClients = new Set();
        const clientsWithEmail = new Set();
        let totalValue = 0;
        
        selectedTrades.forEach(checkbox => {
            const clientName = checkbox.dataset.client;
            const hasEmail = checkbox.dataset.email !== '';
            uniqueClients.add(clientName);
            
            if (hasEmail) {
                clientsWithEmail.add(clientName);
            }
            
            totalValue += parseFloat(checkbox.dataset.value) || 0;
        });
        
        // Update counters
        document.getElementById('selectedTradesCount').textContent = selectedTrades.length;
        document.getElementById('summaryCount').textContent = selectedTrades.length;
        document.getElementById('summaryClients').textContent = uniqueClients.size;
        document.getElementById('summaryEmails').textContent = clientsWithEmail.size;
        document.getElementById('summaryValue').textContent = formatCurrency(totalValue);
        
        // Update button state
        const sendButton = document.getElementById('sendButton');
        const hasEmailTrades = selectedTrades.some(cb => cb.dataset.email !== '');
        sendButton.disabled = selectedTrades.length === 0 || !hasEmailTrades;
        
        // Update select all header state
        const allCheckboxes = document.querySelectorAll('.trade-checkbox:not(:disabled)');
        const allChecked = allCheckboxes.length > 0 && 
                          selectedTrades.length === allCheckboxes.length;
        selectAllHeader.checked = allChecked;
        selectAllHeader.indeterminate = selectedTrades.length > 0 && selectedTrades.length < allCheckboxes.length;
    }
    
    // Format currency
    function formatCurrency(amount) {
        if (amount >= 1000000) {
            return 'TZS ' + (amount / 1000000).toFixed(2) + 'M';
        } else if (amount >= 1000) {
            return 'TZS ' + (amount / 1000).toFixed(2) + 'K';
        }
        return 'TZS ' + amount.toFixed(2);
    }
    
    // Select all visible trades
    window.selectAllVisible = function() {
        tradeCheckboxes.forEach(checkbox => {
            checkbox.checked = true;
        });
        updateSummary();
    };
    
    // Deselect all trades
    window.deselectAll = function() {
        tradeCheckboxes.forEach(checkbox => {
            checkbox.checked = false;
        });
        updateSummary();
    };
    
    // Reset filters
    window.resetFilters = function() {
        document.getElementById('filterForm').reset();
        document.getElementById('filterForm').submit();
    };
    
    // Form validation for email form
    const emailForm = document.getElementById('emailForm');
    emailForm.addEventListener('submit', function(e) {
        const selectedTrades = document.querySelectorAll('.trade-checkbox:checked');
        const tradeIds = Array.from(selectedTrades).map(cb => cb.value);
        
        console.log("Submitting trade IDs:", tradeIds);
        console.log("Number of trades:", tradeIds.length);
        
        if (tradeIds.length === 0) {
            e.preventDefault();
            alert('Please select at least one trade.');
            return false;
        }
        
        // Check if any selected trades have email addresses
        const hasEmailTrades = Array.from(selectedTrades).some(cb => cb.dataset.email !== '');
        if (!hasEmailTrades) {
            e.preventDefault();
            alert('None of the selected trades have client email addresses. Please select trades with valid client emails.');
            return false;
        }
        
        // Add hidden input for trade IDs
        tradeIds.forEach(tradeId => {
            const hiddenInput = document.createElement('input');
            hiddenInput.type = 'hidden';
            hiddenInput.name = 'trade_ids[]';
            hiddenInput.value = tradeId;
            emailForm.appendChild(hiddenInput);
        });
        
        // Show loading state
        const sendButton = document.getElementById('sendButton');
        const originalText = sendButton.innerHTML;
        sendButton.innerHTML = '<i class="bi bi-hourglass-split me-2"></i>Sending...';
        sendButton.disabled = true;
        
        return true;
    });
    
    // Initialize summary
    updateSummary();
});

function previewSelection() {
    const selectedTrades = document.querySelectorAll('.trade-checkbox:checked');
    if (selectedTrades.length === 0) {
        alert('Please select at least one trade.');
        return;
    }
    
    // Group trades by client
    const tradesByClient = {};
    
    selectedTrades.forEach(checkbox => {
        const clientName = checkbox.dataset.client;
        const hasEmail = checkbox.dataset.email !== '';
        const tradeValue = parseFloat(checkbox.dataset.value) || 0;
        
        if (!tradesByClient[clientName]) {
            tradesByClient[clientName] = {
                name: clientName,
                hasEmail: hasEmail,
                trades: [],
                totalValue: 0
            };
        }
        
        tradesByClient[clientName].trades.push({
            id: checkbox.value,
            value: tradeValue
        });
        tradesByClient[clientName].totalValue += tradeValue;
    });
    
    const previewContent = document.getElementById('previewContent');
    let html = `
        <div class="alert alert-info">
            <i class="bi bi-info-circle me-2"></i>
            <strong>Preview:</strong> ${selectedTrades.length} trades selected for ${Object.keys(tradesByClient).length} clients.
        </div>
    `;
    
    // Create client groups
    Object.values(tradesByClient).forEach(client => {
        const emailStatus = client.hasEmail ? 
            '<span class="badge bg-success">Has Email</span>' : 
            '<span class="badge bg-danger">No Email</span>';
        
        html += `
            <div class="card mb-3 ${!client.hasEmail ? 'border-warning' : ''}">
                <div class="card-header bg-light">
                    <div class="d-flex justify-content-between align-items-center">
                        <h6 class="mb-0">${client.name}</h6>
                        ${emailStatus}
                    </div>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-4">
                            <div class="mb-2">
                                <strong>Trades:</strong> ${client.trades.length}
                            </div>
                        </div>
                        <div class="col-md-8">
                            <div class="mb-2">
                                <strong>Total Value:</strong> TZS ${client.totalValue.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}
                            </div>
                        </div>
                    </div>
                    ${!client.hasEmail ? `
                        <div class="alert alert-warning mt-2 mb-0 py-2">
                            <i class="bi bi-exclamation-triangle me-1"></i>
                            This client has no email address. Emails cannot be sent.
                        </div>
                    ` : ''}
                </div>
            </div>
        `;
    });
    
    previewContent.innerHTML = html;
    
    const modal = new bootstrap.Modal(document.getElementById('previewModal'));
    modal.show();
}
</script>

<?php include '../includes/footer.php'; ?>