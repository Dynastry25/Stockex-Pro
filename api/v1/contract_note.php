<?php
/**
 * API v1 - Contract Note PDF Generation Endpoint
 * 
 * Usage:
 * GET /api/v1/contract_note.php?trade_id={trade_id}
 */

// Include API config for CORS headers and utilities
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../../includes/dealing_sheet_helpers.php';
require_once __DIR__ . '/../../tcpdf/tcpdf.php';
require_once __DIR__ . '/../../reports/traits/ReportHeaderTrait.php';

// ============================================================================
// DEFINE ALL CLASSES FIRST - BEFORE ANY EXECUTION
// ============================================================================

// ContractNotePDF class definition
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
    
    // Page footer
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
    
    // Function to add contract note
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
            $net_amount = $consideration - $fees['total'];
        } else {
            $net_amount = $consideration + $fees['total'];
        }
        
        // Add a new page
        $this->AddPage();
        $this->Ln(10);
        
        // Trade information
        $this->SetFont('helvetica', '', 7);
        $this->Cell(90, 4, 'Trade Date: ' . date('d/m/Y', strtotime($trade['trade_date'])), 0, 0, 'L');
        $this->Cell(90, 4, 'Account: ' . $trade['client_cds_account'], 0, 1, 'R');
        $this->Cell(90, 4, 'Order No: ' . $order_number, 0, 0, 'L');
        $this->Cell(90, 4, 'Exch Ref: ' . $exchange_ref, 0, 1, 'R');
        $this->Cell(90, 4, 'Settlement: ' . date('d/m/Y', strtotime($trade['settlement_date'])), 0, 1, 'L');
        
        $this->Ln(4);
        
        // Client information
        $this->SetFont('helvetica', 'B', 10);
        $this->Cell(0, 5, strtoupper($trade['client_name']), 0, 1, 'L');
        $this->SetFont('helvetica', '', 7);
        $this->Cell(0, 3, 'Account Ending: ' . substr($trade['client_cds_account'], -4), 0, 1, 'L');
        
        $this->Ln(10);
        
        // Contract note header
        $this->SetFont('helvetica', 'B', 9);
        $contract_title = $trade_side . ' CONTRACT NOTE No: ' . $contract_number;
        if ($is_summary) {
            $contract_title .= ' (SUMMARY)';
        }
        $this->Cell(0, 5, $contract_title, 0, 1, 'L');
        
        $this->Ln(2);
        
        // Instruction paragraph
        $this->SetFont('helvetica', '', 8);
        $instruction = "We wish to advise that in accordance with your instructions we have " . $trade_side . 
                       " on your account subject to the Rules, Regulations and Customs of the Dar es Salaam Stock Exchange:-";
        $this->MultiCell(0, 4, $instruction, 0, 'L', false, 1, '', '', true, 0, false, true, 0, 'T', false);
        
        $this->Ln(3);
        
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
        
        $this->Ln(4);
        
        // Trade details table
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
        
        $this->Ln(5);
        
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
        
        $this->Ln(2);
        
        // FEES SECTION
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
        
        // Net amount
        $this->SetLineWidth(0.5);
        $this->Line(25, $this->GetY() + 1, 185, $this->GetY() + 1);
        $this->Ln(3);
        
        $amount_label = $is_sell ? 'NET AMOUNT RECEIVABLE' : 'NET AMOUNT PAYABLE';
        $this->SetFont('helvetica', 'B', 10);
        $this->Cell(100, 6, $amount_label, 0, 0, 'L');
        $this->Cell(40, 6, '', 0, 0, 'L');
        $this->Cell(40, 6, number_format($net_amount, 2), 0, 1, 'R');
        
        $this->Ln(50);
        
        // Closing section
        $this->SetFont('helvetica', '', 8);
        $this->Cell(0, 4, 'Yours Faithfully,', 0, 1, 'L');
        $this->Ln(3);
        $this->SetFont('helvetica', 'B', 9);
        $this->Cell(0, 5, 'FOR ' . $this->company_name, 0, 1, 'L');
        
        $this->Ln(6);
        
        // Signature lines
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
}

// Helper function to get fee configuration
function getFeeConfiguration($db, $fee_type, $applies_to = 'ALL') {
    $stmt = $db->prepare("
        SELECT rate_percentage, fixed_amount, calculation_base, fee_name, is_rebated, rebate_percentage, rebate_tiers
        FROM fee_configurations 
        WHERE fee_type = ? AND applies_to = ? AND is_active = TRUE
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

// Helper function to calculate tiered brokerage for equities
function getEquityBrokerageRate($db, $consideration, &$tier_details = []) {
    $tier_details = [];
    
    if ($consideration <= 10000000) {
        $config = getFeeConfiguration($db, 'BROKERAGE_TIER1', 'EQUITY');
        $rate = $config['rate_percentage'] ?? 1.7000;
        $tier_details[] = [
            'amount' => $consideration,
            'rate' => $rate,
            'fee' => $consideration * ($rate / 100),
            'label' => 'Up to 10M @ ' . number_format($rate, 4) . '%'
        ];
        return $rate;
    } elseif ($consideration <= 50000000) {
        $config1 = getFeeConfiguration($db, 'BROKERAGE_TIER1', 'EQUITY');
        $rate1 = $config1['rate_percentage'] ?? 1.7000;
        $config2 = getFeeConfiguration($db, 'BROKERAGE_TIER2', 'EQUITY');
        $rate2 = $config2['rate_percentage'] ?? 1.5000;
        
        $tier1_amount = 10000000;
        $tier2_amount = $consideration - 10000000;
        
        $tier_details[] = [
            'amount' => $tier1_amount,
            'rate' => $rate1,
            'fee' => $tier1_amount * ($rate1 / 100),
            'label' => 'First 10M @ ' . number_format($rate1, 4) . '%'
        ];
        $tier_details[] = [
            'amount' => $tier2_amount,
            'rate' => $rate2,
            'fee' => $tier2_amount * ($rate2 / 100),
            'label' => 'Next ' . number_format($tier2_amount/1000000, 1) . 'M @ ' . number_format($rate2, 4) . '%'
        ];
        return $rate2;
    } else {
        $config1 = getFeeConfiguration($db, 'BROKERAGE_TIER1', 'EQUITY');
        $rate1 = $config1['rate_percentage'] ?? 1.7000;
        $config2 = getFeeConfiguration($db, 'BROKERAGE_TIER2', 'EQUITY');
        $rate2 = $config2['rate_percentage'] ?? 1.5000;
        $config3 = getFeeConfiguration($db, 'BROKERAGE_TIER3', 'EQUITY');
        $rate3 = $config3['rate_percentage'] ?? 0.8000;
        
        $tier1_amount = 10000000;
        $tier2_amount = 40000000;
        $tier3_amount = $consideration - 50000000;
        
        $tier_details[] = [
            'amount' => $tier1_amount,
            'rate' => $rate1,
            'fee' => $tier1_amount * ($rate1 / 100),
            'label' => 'First 10M @ ' . number_format($rate1, 4) . '%'
        ];
        $tier_details[] = [
            'amount' => $tier2_amount,
            'rate' => $rate2,
            'fee' => $tier2_amount * ($rate2 / 100),
            'label' => 'Next 40M @ ' . number_format($rate2, 4) . '%'
        ];
        $tier_details[] = [
            'amount' => $tier3_amount,
            'rate' => $rate3,
            'fee' => $tier3_amount * ($rate3 / 100),
            'label' => 'Excess ' . number_format($tier3_amount/1000000, 1) . 'M @ ' . number_format($rate3, 4) . '%'
        ];
        return $rate3;
    }
}

// Helper function to calculate fees
function calculateFees($db, $asset_class, $consideration, $quantity, $price, $trade_side = 'Sell') {
    $fees = [];
    $fees['tier_details'] = [];
    
    $is_bond = ($asset_class === 'bond' || $asset_class === 'treasury_bond');
    $is_etf = ($asset_class === 'Exchange Traded Funds');
    
    if ($is_bond) {
        // Bond fees
        $face_value = $quantity;
        
        // Brokerage Commission
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
        $vat_config = getFeeConfiguration($db, 'VAT', 'ALL');
        $vat_rate = $vat_config['rate_percentage'] ?? 18.0000;
        $fees['vat'] = $fees['brokerage'] * ($vat_rate / 100);
        
        // Other fees
        $cmsa_config = getFeeConfiguration($db, 'CMSA', 'ALL');
        $cmsa_rate = $cmsa_config['rate_percentage'] ?? 0.0100;
        $fees['cmsa'] = $consideration * ($cmsa_rate / 100);
        
        $csd_config = getFeeConfiguration($db, 'CDS', 'ALL');
        $csd_rate = $csd_config['rate_percentage'] ?? 0.0118;
        $fees['csd'] = $face_value * ($csd_rate / 100);
        
        $dse_config = getFeeConfiguration($db, 'DSE', 'ALL');
        $dse_rate = $dse_config['rate_percentage'] ?? 0.02006;
        $fees['dse'] = $face_value * ($dse_rate / 100);
        
        $fees['fidelity'] = 0.00;
        
    } else {
        // Equity/ETF fees
        $tier_details = [];
        getEquityBrokerageRate($db, $consideration, $tier_details);
        
        // Calculate total brokerage
        $total_brokerage = 0;
        foreach ($tier_details as $tier) {
            $total_brokerage += $tier['fee'];
        }
        $fees['brokerage'] = $total_brokerage;
        $fees['tier_details'] = $tier_details;
        
        // VAT on brokerage
        $vat_config = getFeeConfiguration($db, 'VAT', 'ALL');
        $vat_rate = $vat_config['rate_percentage'] ?? 18.0000;
        $fees['vat'] = $fees['brokerage'] * ($vat_rate / 100);
        
        // CMSA Fee
        $cmsa_config = getFeeConfiguration($db, 'CMSA', 'ALL');
        $cmsa_rate = $cmsa_config['rate_percentage'] ?? 0.1400;
        $fees['cmsa'] = $consideration * ($cmsa_rate / 100);
        
        // DSE Fee
        $dse_config = getFeeConfiguration($db, 'DSE', 'ALL');
        $dse_rate = $dse_config['rate_percentage'] ?? 0.1652;
        $fees['dse'] = $consideration * ($dse_rate / 100);
        
        // Fidelity Fee
        $fidelity_config = getFeeConfiguration($db, 'FIDELITY', 'ALL');
        $fidelity_rate = $fidelity_config['rate_percentage'] ?? 0.0200;
        $fees['fidelity'] = $consideration * ($fidelity_rate / 100);
        
        // CDS Fee
        $cds_config = getFeeConfiguration($db, 'CDS', 'ALL');
        $cds_rate = $cds_config['rate_percentage'] ?? 0.0708;
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

// Main function to generate Contract Note PDF
function generateContractNotePDF($db, $trade_id, $company_name, $contract_type = 'single') {
    // Get the specific trade with all details
    $stmt = $db->prepare("
        SELECT t.*, 
               e.share_type,
               et.isin as etf_isin,
               b.coupon_rate,
               b.maturity_date,
               b.isin as bond_isin,
               c_buyer.company_name as buyer_company_name,
               c_buyer.company_code as buyer_cds_account,
               c_seller.company_name as seller_company_name,
               c_seller.company_code as seller_cds_account
        FROM trades t
        LEFT JOIN equities e ON t.security_id = e.security_id AND t.asset_class = 'equity'
        LEFT JOIN bonds b ON t.security_id = b.security_id AND t.asset_class = 'bond'
        LEFT JOIN etf_trades et ON t.trade_reference = et.trade_reference
        LEFT JOIN companies c_buyer ON t.client_name = c_buyer.company_name
        LEFT JOIN companies c_seller ON t.counterparty_name = c_seller.company_name
        WHERE t.id = ?
    ");
    $stmt->execute([$trade_id]);
    $trade = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$trade) {
        http_response_code(404);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'error' => 'Trade not found'
        ]);
        exit;
    }
    
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

    // Set margins
    $pdf->SetMargins(25.4, 25, 25.4);
    $pdf->SetHeaderMargin(5);
    $pdf->SetFooterMargin(8);

    // Set auto page breaks
    $pdf->SetAutoPageBreak(TRUE, 12);

    // Generate contract note
    $trade_side = strtoupper(trim($trade['trade_side']));
    $contract_number = ($trade_side === 'SELL' ? 'S' : 'P') . str_pad($trade['id'], 6, '0', STR_PAD_LEFT);
    $order_number = str_pad($trade['id'], 6, '0', STR_PAD_LEFT);
    $exchange_ref = date('ymd', strtotime($trade['trade_date'])) . str_pad($trade['id'], 3, '0', STR_PAD_LEFT);
    
    $pdf->setCurrentTrade(1);
    $pdf->addContractNote($trade, $fees, $contract_number, $order_number, $exchange_ref);

    // Set output filename
    $filename = 'contract_note_' . $trade['trade_reference'] . '.pdf';

    // Override JSON content type with PDF headers (must come after config.php)
    // Clear Content-Type header and set to PDF
    header_remove('Content-Type');
    header('Content-Type: application/pdf; charset=UTF-8', true);
    header('Content-Disposition: inline; filename="' . $filename . '"');
    header('Cache-Control: no-cache, must-revalidate');
    header('Expires: 0');

    // Output PDF directly to browser
    $pdf->Output($filename, 'I');
    exit();
}

// ============================================================================
// MAIN EXECUTION LOGIC - HANDLES THE API REQUEST
// ============================================================================

// Only allow GET requests
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'error' => 'Method not allowed. Only GET requests are supported.'
    ]);
    exit;
}

try {
    // Get database connection
    $database = new Database();
    $db = $database->getConnection();
    
    if (!$db) {
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'error' => 'Database connection failed'
        ]);
        exit;
    }
    
    // Check if trade_id parameter is provided
    if (!isset($_GET['trade_id'])) {
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'error' => 'Missing required parameter: trade_id',
            'example' => '/api/v1/contract_note.php?trade_id=123'
        ]);
        exit;
    }
    
    $trade_id = intval($_GET['trade_id']);
    $dealing_sheet_user = [
        'id' => $_SESSION['user_id'] ?? null,
        'full_name' => $_SESSION['full_name'] ?? ($_SESSION['username'] ?? 'System User')
    ];

    try {
        dealingSheetEnsureSchema($db);
        dealingSheetMarkContractGeneratedForTrade($db, $trade_id, $dealing_sheet_user, false);
    } catch (Exception $e) {
        error_log('Contract note dealing sheet sync failed for trade ' . $trade_id . ': ' . $e->getMessage());
    }
    
    // Get company details from database
    $company_stmt = $db->query("SELECT * FROM companies WHERE status = 'active' ORDER BY id LIMIT 1");
    $company = $company_stmt->fetch();
    $company_name = $company ? $company['company_name'] : 'Neovam LTD';
    
    // Generate contract note PDF
    generateContractNotePDF($db, $trade_id, $company_name);
    
} catch (Exception $e) {
    error_log("Contract Note Error - Trade ID: " . ($_GET['trade_id'] ?? 'unknown') . " - " . $e->getMessage());
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'error' => 'Internal server error: ' . $e->getMessage(),
        'timestamp' => date('Y-m-d H:i:s')
    ]);
    exit;
}
