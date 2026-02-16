<?php
require_once '../config/config.php';
require_once '../auth/auth_middleware.php';
require_once '../tcpdf/tcpdf.php';

require_trader();
require_ceo();

require_mandate();

$db = getDBConnection();
$success_message = '';
$error_message = '';

// Get company details from database
$company_stmt = $db->query("SELECT * FROM companies WHERE status = 'active' ORDER BY id LIMIT 1");
$company = $company_stmt->fetch();
$company_name = $company ? $company['company_name'] : 'Neovam LTD';
$company_phone = $company ? $company['phone'] : '0767676767';
$company_address = $company ? $company['address'] : 'P.O Box 675, Dar es Salaam, Tanzania';
$company_email = $company ? $company['email'] : 'info@neovam.com';

// Handle CSV export
if (isset($_GET['export']) && $_GET['export'] == 'csv') {
    // Set headers for CSV download
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="trades_export_' . date('Ymd_His') . '.csv"');
    
    // Create output stream
    $output = fopen('php://output', 'w');
    
    // Add BOM for UTF-8 compatibility with Excel
    fwrite($output, "\xEF\xBB\xBF");
    
    // CSV headers
    $headers = [
        'Reference', 'Instrument', 'Asset Type', 'Side', 'Quantity', 
        'Price', 'Total Value', 'Client', 'Client Account', 'Counterparty', 
        'Counterparty Account', 'Trade Date', 'Settlement Date', 'Status', 'Created At'
    ];
    fputcsv($output, $headers, ',', '"', '\\');
    
    // Get filtered trades for export - REMOVED USER RESTRICTION
    $where_conditions = []; // Changed from ["t.uploaded_by = ?"]
    $params = []; // Changed from [$_SESSION['user_id']]
    
    // Apply same filters as page
    if (isset($_GET['status']) && !empty($_GET['status'])) {
        $where_conditions[] = "t.status = ?";
        $params[] = $_GET['status'];
    }
    if (isset($_GET['type']) && !empty($_GET['type'])) {
        $where_conditions[] = "t.asset_class = ?";
        $params[] = $_GET['type'];
    }
    if (isset($_GET['trade_date_from']) && !empty($_GET['trade_date_from'])) {
        $where_conditions[] = "t.trade_date >= ?";
        $params[] = $_GET['trade_date_from'];
    }
    if (isset($_GET['trade_date_to']) && !empty($_GET['trade_date_to'])) {
        $where_conditions[] = "t.trade_date <= ?";
        $params[] = $_GET['trade_date_to'];
    }
    if (isset($_GET['settlement_date_from']) && !empty($_GET['settlement_date_from'])) {
        $where_conditions[] = "t.settlement_date >= ?";
        $params[] = $_GET['settlement_date_from'];
    }
    if (isset($_GET['settlement_date_to']) && !empty($_GET['settlement_date_to'])) {
        $where_conditions[] = "t.settlement_date <= ?";
        $params[] = $_GET['settlement_date_to'];
    }
    if (isset($_GET['search']) && !empty($_GET['search'])) {
        $search_term = '%' . $_GET['search'] . '%';
        $where_conditions[] = "(t.trade_reference LIKE ? OR t.security_id LIKE ? OR t.client_name LIKE ? OR t.counterparty_name LIKE ?)";
        $params[] = $search_term;
        $params[] = $search_term;
        $params[] = $search_term;
        $params[] = $search_term;
    }
    
    // Build WHERE clause for export
    if (empty($where_conditions)) {
        $where_clause = "1=1"; // Show all trades
    } else {
        $where_clause = implode(' AND ', $where_conditions);
    }
    
    $export_stmt = $db->prepare("
        SELECT t.*
        FROM trades t
        WHERE $where_clause
        ORDER BY t.created_at DESC
    ");
    $export_stmt->execute($params);
    $export_trades = $export_stmt->fetchAll();
    
    // Add data rows
    foreach ($export_trades as $trade) {
        $row = [
            $trade['trade_reference'],
            $trade['security_id'],
            $trade['asset_class'] === 'Exchange Traded Funds' ? 'ETF' : ucfirst($trade['asset_class']),
            ucfirst($trade['trade_side']),
            $trade['quantity'],
            number_format($trade['price'], 2),
            number_format($trade['consideration'], 2),
            $trade['client_name'],
            $trade['client_cds_account'],
            $trade['counterparty_name'],
            $trade['counterparty_cds_account'],
            $trade['trade_date'],
            $trade['settlement_date'],
            ucfirst($trade['status']),
            $trade['created_at']
        ];
        fputcsv($output, $row, ',', '"', '\\');
    }
    
    fclose($output);
    exit;
}

// Define ContractNotePDF class at the top level so all functions can access it
class ContractNotePDF extends TCPDF {
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
    
    // Page header - COMPACT
    public function Header() {
        // Logo - smaller
        $image_file = '../assets/HeaderLogoVfsl.jpg';
        if (file_exists($image_file)) {
            $this->Image($image_file, 25, 8, 160, 0, 'JPG', '', 'T', false, 300, '', false, false, 0, false, false, false);
        } else {
            $this->SetFont('helvetica', 'B', 11);
            $this->SetXY(25, 8);
            $this->Cell(160, 5, $this->company_name, 0, 1, 'C');
            $this->SetFont('helvetica', '', 7);
            $this->Cell(160, 3, 'Registered Stockbroker', 0, 1, 'C');
        }
        
        // Line - thin
        $this->SetLineWidth(0.2);
        $this->Line(25, 18, 185, 18);
        
        // Subtitle - smaller
        $this->SetFont('helvetica', '', 6);
        $this->SetXY(25, 19);
        $this->Cell(160, 3, '(Subject to the Rules and Practice of the Dar es Salaam Stock Exchange)', 0, 1, 'C');
        
        // Progress indicator - very small
        if ($this->total_trades > 1) {
            $this->SetFont('helvetica', '', 5);
            $this->SetTextColor(120, 120, 120);
            $this->SetXY(25, 22);
            $this->Cell(10, 3, 'Trade ' . $this->current_trade . ' of ' . $this->total_trades, 0, 0, 'L');
            $this->SetTextColor(0, 0, 0);
        }
        
        // Watermark - lighter
        if ($this->watermark_enabled) {
            $this->SetAlpha(0.05);
            $this->SetFont('helvetica', 'B', 50);
            $this->SetTextColor(200, 200, 200);
            $this->RotatedText(105, 150, $this->company_name, 45);
            $this->SetAlpha(1);
            $this->SetTextColor(0, 0, 0);
        }
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
        
        $this->Cell(100, 3.5, 'Other Charges', 0, 0, 'L');
        $this->Cell(40, 3.5, '', 0, 0, 'L');
        $this->Cell(40, 3.5, '0.00', 0, 1, 'R');
        
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

// Handle trade actions
if (isset($_GET['action']) && isset($_GET['id'])) {
    $action = $_GET['action'];
    $trade_id = (int)$_GET['id'];
    
    // REMOVED USER RESTRICTION - any trader can now modify any trade
    $stmt = $db->prepare("SELECT * FROM trades"); // Removed: "AND uploaded_by = ?"
    $stmt->execute([$trade_id]); // Removed: , $_SESSION['user_id']
    $trade = $stmt->fetch();
    
    if ($trade) {
        switch ($action) {
            case 'cancel':
                $stmt = $db->prepare("UPDATE trades SET status = 'cancelled' WHERE id = ?");
                if ($stmt->execute([$trade_id])) {
                    show_alert('Trade cancelled successfully. It will not appear in receipts or ledgers.', 'warning');
                } else {
                    show_alert('Error cancelling trade.', 'danger');
                }
                break;
                
            case 'enable':
                $stmt = $db->prepare("UPDATE trades SET status = 'active' WHERE id = ?");
                if ($stmt->execute([$trade_id])) {
                    show_alert('Trade enabled successfully.', 'success');
                } else {
                    show_alert('Error enabling trade.', 'danger');
                }
                break;
                
            case 'settle':
                $stmt = $db->prepare("UPDATE trades SET status = 'settled' WHERE id = ?");
                if ($stmt->execute([$trade_id])) {
                    show_alert('Trade marked as settled successfully.', 'success');
                } else {
                    show_alert('Error settling trade.', 'danger');
                }
                break;
                
            case 'contract_note':
                // Check if client has multiple trades on the same day
                $client_id = $trade['client_cds_account'];
                $trade_date = $trade['trade_date'];
                $trade_side = $trade['trade_side'];
                $security_id = $trade['security_id'];
                
                // Count trades for this client on the same day with same security and trade side
                // REMOVED USER RESTRICTION FROM CONTRACT NOTE QUERY
                $stmt = $db->prepare("
                    SELECT COUNT(*) as trade_count 
                    FROM trades 
                    WHERE client_cds_account = ? 
                    AND trade_date = ? 
                    AND trade_side = ? 
                    AND security_id = ?
                    AND status = 'active'
                "); // Removed: "AND uploaded_by = ?"
                $stmt->execute([$client_id, $trade_date, $trade_side, $security_id]); // Removed: , $_SESSION['user_id']
                $result = $stmt->fetch();
                
                if ($result['trade_count'] > 1) {
                    // Store data for modal in session
                    $_SESSION['contract_modal_data'] = [
                        'client_id' => $client_id,
                        'trade_date' => $trade_date,
                        'trade_side' => $trade_side,
                        'security_id' => $security_id,
                        'trigger_trade_id' => $trade_id
                    ];
                    
                    // Redirect back to show modal
                    header('Location: trades?show_contract_modal=1');
                    exit;
                } else {
                    // Single trade - generate contract note directly
                    generateContractNotePDF($trade_id, 'single');
                }
                exit;
        }
    } else {
        show_alert('Trade not found.', 'danger'); // Changed from 'Trade not found or access denied.'
    }
    
    redirect('trader/trades.php');
}

// Function to get fee configuration from database
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

// Function to calculate tiered brokerage for equities (and ETFs)
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
    } elseif ($consideration <= 40000000) {
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
        $tier2_amount = 30000000; // 40M - 10M
        $tier3_amount = $consideration - 40000000;
        
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
            'label' => 'Next 30M @ ' . number_format($rate2, 4) . '%'
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

// Function to calculate fees based on asset type
function calculateFees($db, $asset_class, $consideration, $quantity, $price) {
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
        // Equity/ETF fees - Calculate brokerage correctly by summing tier fees
        $tier_details = [];
        getEquityBrokerageRate($db, $consideration, $tier_details);
        
        // Calculate total brokerage by summing ALL tier fees
        $total_brokerage = 0;
        foreach ($tier_details as $tier) {
            $total_brokerage += $tier['fee'];
        }
        $fees['brokerage'] = $total_brokerage; // Correct total brokerage
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
    
    $fees['total'] = array_sum([
        $fees['brokerage'] ?? 0,
        $fees['vat'] ?? 0,
        $fees['cmsa'] ?? 0,
        $fees['dse'] ?? 0,
        $fees['fidelity'] ?? 0,
        $fees['csd'] ?? 0
    ]);
    
    return $fees;
}

// Function to generate Contract Note PDF
function generateContractNotePDF($trade_id, $contract_type = 'single') {
    global $db, $company_name;
    
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
    $trade = $stmt->fetch();
    
    if (!$trade) {
        die('Trade not found');
    }
    
    // Calculate fees using the same function as first code
    $fees = calculateFees($db, $trade['asset_class'], 
                         floatval($trade['consideration']), 
                         floatval($trade['quantity']), 
                         floatval($trade['price']));
    
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

    // Set output filename
    $filename = 'contract_note_' . $trade['trade_reference'] . '.pdf';

    // Output PDF
    $pdf->Output($filename, 'I');
}

// Function to generate summary contract note for multiple trades (Equities/ETFs only)
function generateSummaryContractNote($client_id, $trade_date, $trade_side, $security_id) {
    global $db, $company_name;
    
    // Get all trades for this client on the same day with same security and trade side
    // REMOVED USER RESTRICTION
    $stmt = $db->prepare("
        SELECT t.*, 
               e.share_type,
               b.coupon_rate,
               b.maturity_date,
               c_buyer.company_name as buyer_company_name,
               c_seller.company_name as seller_company_name
        FROM trades t
        LEFT JOIN equities e ON t.security_id = e.security_id AND t.asset_class = 'equity'
        LEFT JOIN bonds b ON t.security_id = b.security_id AND t.asset_class = 'bond'
        LEFT JOIN companies c_buyer ON t.client_name = c_buyer.company_name
        LEFT JOIN companies c_seller ON t.counterparty_name = c_seller.company_name
        WHERE t.client_cds_account = ? 
        AND t.trade_date = ? 
        AND t.trade_side = ? 
        AND t.security_id = ?
        AND t.status = 'active'
        ORDER BY t.created_at
    "); // Removed: "AND t.uploaded_by = ?"
    $stmt->execute([$client_id, $trade_date, $trade_side, $security_id]); // Removed: , $_SESSION['user_id']
    $trades = $stmt->fetchAll();
    
    if (empty($trades)) {
        die('No trades found for summary');
    }
    
    // Calculate summary data
    $total_quantity = 0;
    $total_consideration = 0;
    $total_fees = 0;
    $trade_count = count($trades);
    $client_name = $trades[0]['client_name'];
    $settlement_date = $trades[0]['settlement_date'];
    $asset_class = $trades[0]['asset_class'];
    
    foreach ($trades as $trade) {
        $total_quantity += floatval($trade['quantity']);
        $total_consideration += floatval($trade['consideration']);
        
        // Calculate fees for each trade
        $fees = calculateFees($db, $trade['asset_class'], 
                             floatval($trade['consideration']), 
                             floatval($trade['quantity']), 
                             floatval($trade['price']));
        $total_fees += $fees['total'];
    }
    
    $average_price = $total_consideration / $total_quantity;
    
    // Create a synthetic trade for the summary
    $summary_trade = $trades[0];
    $summary_trade['quantity'] = $total_quantity;
    $summary_trade['price'] = $average_price;
    $summary_trade['consideration'] = $total_consideration;
    
    // Calculate fees for the total consideration
    $summary_fees = calculateFees($db, $asset_class, $total_consideration, $total_quantity, $average_price);
    
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
        'total_consideration' => $total_consideration,
        'total_fees' => $total_fees
    ];
    
    $pdf->setCurrentTrade(1);
    
    // Call addContractNote with summary flag
    $pdf->addContractNote($summary_trade, $summary_fees, $contract_number, $order_number, $exchange_ref, true, $summary_data);
    
    // Add breakdown page
    $pdf->addSummaryBreakdown($trades, $summary_data, $client_name, $trade_date, $security_id, $trade_side);

    // Set output filename
    $filename = 'contract_note_summary_' . $client_id . '_' . date('Ymd', strtotime($trade_date)) . '.pdf';

    // Output PDF
    $pdf->Output($filename, 'I');
}

// Function to generate detailed contract notes for multiple trades
function generateDetailedContractNotes($client_id, $trade_date, $trade_side, $security_id) {
    global $db, $company_name;
    
    // Get all trades for this client on the same day with same security and trade side
    // REMOVED USER RESTRICTION
    $stmt = $db->prepare("
        SELECT t.*, 
               e.share_type,
               b.coupon_rate,
               b.maturity_date,
               c_buyer.company_name as buyer_company_name,
               c_seller.company_name as seller_company_name
        FROM trades t
        LEFT JOIN equities e ON t.security_id = e.security_id AND t.asset_class = 'equity'
        LEFT JOIN bonds b ON t.security_id = b.security_id AND t.asset_class = 'bond'
        LEFT JOIN companies c_buyer ON t.client_name = c_buyer.company_name
        LEFT JOIN companies c_seller ON t.counterparty_name = c_seller.company_name
        WHERE t.client_cds_account = ? 
        AND t.trade_date = ? 
        AND t.trade_side = ? 
        AND t.security_id = ?
        AND t.status = 'active'
        ORDER BY t.created_at
    "); // Removed: "AND t.uploaded_by = ?"
    $stmt->execute([$client_id, $trade_date, $trade_side, $security_id]); // Removed: , $_SESSION['user_id']
    $trades = $stmt->fetchAll();
    
    if (empty($trades)) {
        die('No trades found for detailed notes');
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
                             floatval($trade['price']));
        
        $trade_side_upper = strtoupper(trim($trade['trade_side']));
        $contract_number = ($trade_side_upper === 'SELL' ? 'S' : 'P') . str_pad($trade['id'], 6, '0', STR_PAD_LEFT);
        $order_number = str_pad($trade['id'], 6, '0', STR_PAD_LEFT);
        $exchange_ref = date('ymd', strtotime($trade['trade_date'])) . str_pad($trade['id'], 3, '0', STR_PAD_LEFT);
        
        $pdf->setCurrentTrade($trade_index);
        $pdf->addContractNote($trade, $fees, $contract_number, $order_number, $exchange_ref);
        
        $trade_index++;
    }

    // Set output filename
    $filename = 'contract_notes_detailed_' . $client_id . '_' . date('Ymd', strtotime($trade_date)) . '.pdf';

    // Output PDF
    $pdf->Output($filename, 'I');
}

// Handle contract note generation based on modal selection
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['generate_contract_note'])) {
    $contract_type = $_POST['contract_type'];
    $client_id = $_POST['client_id'];
    $trade_date = $_POST['trade_date'];
    $trade_side = $_POST['trade_side'];
    $security_id = $_POST['security_id'];
    $trigger_trade_id = $_POST['trigger_trade_id'];
    
    if ($contract_type == 'single') {
        generateContractNotePDF($trigger_trade_id, 'single');
    } elseif ($contract_type == 'summary') {
        generateSummaryContractNote($client_id, $trade_date, $trade_side, $security_id);
    } elseif ($contract_type == 'detailed') {
        generateDetailedContractNotes($client_id, $trade_date, $trade_side, $security_id);
    }
    exit;
}

// Handle trade upload
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['upload_trade'])) {
    $asset_class = sanitize_input($_POST['asset_class']);
    $security_id = sanitize_input($_POST['security_id']);
    $trade_side = sanitize_input($_POST['trade_side']);
    $trade_reference = sanitize_input($_POST['trade_reference']);
    $quantity = (int)$_POST['quantity'];
    $price = (float)$_POST['price'];
    $client_name = sanitize_input($_POST['client_name']);
    $counterparty_name = sanitize_input($_POST['counterparty_name']);
    $client_cds_account = sanitize_input($_POST['client_cds_account']);
    $counterparty_cds_account = sanitize_input($_POST['counterparty_cds_account']);
    $trade_date = sanitize_input($_POST['trade_date']);
    $settlement_date = sanitize_input($_POST['settlement_date']);

    $consideration = $quantity * $price;
    
    if (empty($asset_class) || empty($security_id) || empty($trade_side) || 
        empty($quantity) || empty($price) || empty($client_name) || empty($counterparty_name) ||
        empty($trade_date) || empty($settlement_date)) {
        $error_message = 'All fields are required.';
    } elseif ($quantity <= 0 || $price <= 0) {
        $error_message = 'Quantity and price must be greater than zero.';
    } else {
        $stmt = $db->prepare("
            INSERT INTO trades (trade_reference, asset_class, security_id, security_name, trade_side, quantity, price, 
                                consideration, client_name, counterparty_name, client_cds_account, counterparty_cds_account, 
                                trade_date, settlement_date, uploaded_by) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        if ($stmt->execute([$trade_reference, $asset_class, $security_id, $security_id, $trade_side, $quantity, $price,
                            $consideration, $client_name, $counterparty_name, $client_cds_account, $counterparty_cds_account,
                            $trade_date, $settlement_date, $_SESSION['user_id']])) {
            show_alert('Trade uploaded successfully with reference: ' . $trade_reference, 'success');
            redirect('trader/trades.php');
        } else {
            $error_message = 'Error uploading trade. Please try again.';
        }
    }
}

// Get available instruments for upload form
$bonds = [];
$equities = [];
$etfs = [];

$stmt = $db->query("SELECT id, security_id, issued_amount FROM bonds WHERE status = 'active' ORDER BY security_id");
$bonds = $stmt->fetchAll();

$stmt = $db->query("SELECT id, security_id, stock_name FROM equities WHERE status = 'active' ORDER BY security_id");
$equities = $stmt->fetchAll();

$stmt = $db->query("SELECT id, security_id, stock_name FROM equities WHERE status = 'active' AND share_type = 'ETF' ORDER BY security_id");
$etfs = $stmt->fetchAll();

// Get companies from database for dropdowns
$companies = [];
$stmt = $db->query("SELECT id, company_name, company_code FROM companies WHERE status = 'active' ORDER BY company_name");
$companies = $stmt->fetchAll();

// Get trades with extensive filtering including dates - WITHOUT PAGINATION (client-side)
// REMOVED USER RESTRICTION - any trader can now view all trades
$where_conditions = []; // Changed from ["t.uploaded_by = ?"]
$params = []; // Changed from [$_SESSION['user_id']]

// Status filter
if (isset($_GET['status']) && !empty($_GET['status'])) {
    $where_conditions[] = "t.status = ?";
    $params[] = $_GET['status'];
}

// Asset type filter
if (isset($_GET['type']) && !empty($_GET['type'])) {
    $where_conditions[] = "t.asset_class = ?";
    $params[] = $_GET['type'];
}

// Date range filter - Trade Date
if (isset($_GET['trade_date_from']) && !empty($_GET['trade_date_from'])) {
    $where_conditions[] = "t.trade_date >= ?";
    $params[] = $_GET['trade_date_from'];
}

if (isset($_GET['trade_date_to']) && !empty($_GET['trade_date_to'])) {
    $where_conditions[] = "t.trade_date <= ?";
    $params[] = $_GET['trade_date_to'];
}

// Date range filter - Settlement Date
if (isset($_GET['settlement_date_from']) && !empty($_GET['settlement_date_from'])) {
    $where_conditions[] = "t.settlement_date >= ?";
    $params[] = $_GET['settlement_date_from'];
}

if (isset($_GET['settlement_date_to']) && !empty($_GET['settlement_date_to'])) {
    $where_conditions[] = "t.settlement_date <= ?";
    $params[] = $_GET['settlement_date_to'];
}

// Search filter
if (isset($_GET['search']) && !empty($_GET['search'])) {
    $search_term = '%' . $_GET['search'] . '%';
    $where_conditions[] = "(t.trade_reference LIKE ? OR t.security_id LIKE ? OR t.client_name LIKE ? OR t.counterparty_name LIKE ?)";
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
}

// Build WHERE clause - if no conditions, show all trades
if (empty($where_conditions)) {
    $where_clause = "1=1"; // Show all trades
} else {
    $where_clause = implode(' AND ', $where_conditions);
}

// Get all trades without pagination - REMOVED USER RESTRICTION
$stmt = $db->prepare("
    SELECT t.*, 
           COALESCE(e.stock_name, b.security_id, etf.stock_name) AS asset_name,
           e.share_type,
           et.isin as etf_isin,
           c_buyer.company_name as buyer_company_name,
           c_seller.company_name as seller_company_name,
           cl.id as client_id, -- Add client ID
           cl.client_name as proper_client_name -- Use client name from clients table
    FROM trades t
    LEFT JOIN equities e ON t.security_id = e.security_id AND t.asset_class = 'equity'
    LEFT JOIN bonds b ON t.security_id = b.security_id AND t.asset_class = 'bond'
    LEFT JOIN equities etf ON t.security_id = etf.security_id AND t.asset_class = 'Exchange Traded Funds'
    LEFT JOIN etf_trades et ON t.trade_reference = et.trade_reference
    LEFT JOIN companies c_buyer ON t.client_name = c_buyer.company_name
    LEFT JOIN companies c_seller ON t.counterparty_name = c_seller.company_name
    LEFT JOIN clients cl ON t.client_cds_account = cl.cds_account AND cl.is_active = 1
    WHERE $where_clause
    ORDER BY t.created_at DESC
");

$stmt->execute($params);
$trades = $stmt->fetchAll();

// Get total count for display
$total_trades = count($trades);

// Check if we need to show contract modal
$show_contract_modal = isset($_GET['show_contract_modal']) && isset($_SESSION['contract_modal_data']);

$page_title = 'Trade Management';
include '../includes/header.php';
?>

<!-- The rest of your HTML/PHP code remains exactly the same as in your second file -->
<!-- Only the PHP functions above were fixed -->

<!-- Enhanced professional trade management header with modern styling -->
<div class="page-header">
    <div class="container-fluid">
        <div class="row align-items-center">
            <div class="col-md-8">
                <div class="d-flex align-items-center">
                    <div class="me-3">
                        <div class="d-inline-flex align-items-center justify-content-center rounded-circle shadow-sm" 
                             style="width: 60px; height: 60px; background: linear-gradient(135deg, var(--success-color) 0%, #10b981 100%);">
                            <i class="bi bi-graph-up" style="font-size: 1.5rem;"></i>
                        </div>
                    </div>
                    <div>
                        <h1 class="page-title mb-1">Trade Management</h1>
                        <p class="page-subtitle">Professional trading operations and portfolio oversight - <?php echo htmlspecialchars($company_name); ?></p>
                    </div>
                </div>
            </div>
            <div class="col-md-4 text-end">
                <!-- Enhanced action buttons with professional styling -->
                <div class="d-flex gap-2 justify-content-end flex-wrap">
                    <a href="enter_bonds.php" class="btn btn-outline-success d-flex align-items-center">
                        <i class="bi bi-bank me-2"></i>
                        <span class="d-none d-sm-inline">Enter Bonds</span>
                    </a>
                    <a href="enter_shares.php" class="btn btn-outline-info d-flex align-items-center">
                        <i class="bi bi-graph-up-arrow me-2"></i>
                        <span class="d-none d-sm-inline">Enter Shares</span>
                    </a>
                    <a href="upload_shares.php" class="btn btn-outline-warning d-flex align-items-center">
                        <i class="bi bi-upload me-2"></i>
                        <span class="d-none d-sm-inline">Upload Shares/ETFs</span>
                    </a>
                  
                    <!-- Settle Trades Button -->
                    <a href="settlement.php" class="btn btn-outline-danger d-flex align-items-center">
                        <i class="bi bi-check-circle me-2"></i>
                        <span class="d-none d-sm-inline">Settle Trades</span>
                    </a>
                    <!-- Export CSV Button -->
                    <a href="?<?php echo http_build_query(array_merge($_GET, ['export' => 'csv'])); ?>" 
                       class="btn btn-success d-flex align-items-center" 
                       onclick="return confirm('Export all filtered trades to CSV?')">
                        <i class="bi bi-file-earmark-excel me-2"></i>
                        <span class="d-none d-sm-inline">Export CSV</span>
                    </a>
                    <button type="button" class="btn btn-primary d-flex align-items-center" data-bs-toggle="modal" data-bs-target="#uploadTradeModal">
                        <i class="bi bi-upload me-2"></i>
                        <span class="d-none d-sm-inline">New Trade</span>
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="container-fluid">
    <?php if (!empty($error_message)): ?>
        <!-- Enhanced error message styling -->
        <div class="alert alert-danger border-0 shadow-sm mb-4" role="alert" style="background: linear-gradient(135deg, #fef2f2 0%, #fef2f2 100%); border-left: 4px solid var(--danger-color) !important;">
            <div class="d-flex align-items-center">
                <i class="bi bi-exclamation-triangle-fill me-2" style="color: var(--danger-color);"></i>
                <span class="fw-medium"><?php echo $error_message; ?></span>
            </div>
        </div>
    <?php endif; ?>

    <!-- Contract Note Modal -->
    <?php if ($show_contract_modal && isset($_SESSION['contract_modal_data'])): 
        $modal_data = $_SESSION['contract_modal_data'];
        
        // Get client details with asset class - REMOVED USER RESTRICTION
        $stmt = $db->prepare("
            SELECT t.asset_class, t.client_name, COUNT(*) as trade_count, 
                   SUM(quantity) as total_quantity, 
                   SUM(consideration) as total_consideration
            FROM trades t
            WHERE t.client_cds_account = ? 
            AND t.trade_date = ? 
            AND t.trade_side = ? 
            AND t.security_id = ?
            AND t.status = 'active'
            GROUP BY t.asset_class, t.client_name
        "); // Removed: "AND t.uploaded_by = ?"
        $stmt->execute([
            $modal_data['client_id'], 
            $modal_data['trade_date'], 
            $modal_data['trade_side'], 
            $modal_data['security_id']
        ]); // Removed: , $_SESSION['user_id']
        $client_data = $stmt->fetch();
        
        // Check asset type
        $is_equity_or_etf = ($client_data['asset_class'] === 'equity' || $client_data['asset_class'] === 'Exchange Traded Funds');
        $asset_type_display = ($client_data['asset_class'] === 'Exchange Traded Funds') ? 'ETF' : ucfirst($client_data['asset_class']);
        
        // Get individual trades for display - REMOVED USER RESTRICTION
        $stmt = $db->prepare("
            SELECT trade_reference, quantity, price, consideration, created_at
            FROM trades 
            WHERE client_cds_account = ? 
            AND trade_date = ? 
            AND trade_side = ? 
            AND security_id = ?
            AND status = 'active'
            ORDER BY created_at
        "); // Removed: "AND uploaded_by = ?"
        $stmt->execute([
            $modal_data['client_id'], 
            $modal_data['trade_date'], 
            $modal_data['trade_side'], 
            $modal_data['security_id']
        ]); // Removed: , $_SESSION['user_id']
        $trades_list = $stmt->fetchAll();
    ?>
    <div class="modal fade show" id="contractNoteModal" tabindex="-1" aria-labelledby="contractNoteModalLabel" style="display: block; background-color: rgba(0,0,0,0.5);" aria-modal="true" role="dialog">
        <div class="modal-dialog modal-lg">
            <div class="modal-content border-0 shadow-lg" style="border-radius: var(--radius-xl);">
                <div class="modal-header border-0 pb-0" style="background: linear-gradient(135deg, var(--primary-color) 0%, var(--primary-color-dark) 100%);">
                    <div class="d-flex align-items-center w-100">
                        <div class="me-3">
                            <i class="bi bi-file-earmark-text-fill" style="font-size: 1.5rem;"></i>
                        </div>
                        <div class="flex-grow-1">
                            <h5 class="modal-title mb-0" id="contractNoteModalLabel">Generate Contract Note</h5>
                            <small class="opacity-75">Multiple trades detected for <?php echo htmlspecialchars($client_data['client_name']); ?></small>
                        </div>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close" onclick="window.location.href='trades';"></button>
                    </div>
                </div>
                <div class="modal-body p-4">
                    <div class="alert alert-info border-0 mb-4" style="background-color: #f0f9ff;">
                        <div class="d-flex align-items-center">
                            <i class="bi bi-info-circle-fill text-info me-2"></i>
                            <div>
                                <h6 class="alert-heading mb-1">Multiple Trades Detected</h6>
                                <p class="mb-0">Found <strong><?php echo $client_data['trade_count']; ?> trades</strong> for <?php echo htmlspecialchars($client_data['client_name']); ?> on <?php echo format_date($modal_data['trade_date']); ?> (<?php echo ucfirst($modal_data['trade_side']); ?> <?php echo htmlspecialchars($modal_data['security_id']); ?>)</p>
                            </div>
                        </div>
                    </div>
                    
                    <div class="card border-0 shadow-sm mb-4">
                        <div class="card-header bg-transparent border-bottom py-3">
                            <h6 class="mb-0 fw-semibold">Trade Summary</h6>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label text-muted small mb-1">Total Quantity</label>
                                    <div class="fw-semibold fs-5 text-primary"><?php echo number_format($client_data['total_quantity']); ?> units</div>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label text-muted small mb-1">Total Consideration</label>
                                    <div class="fw-semibold fs-5 text-success">TZS <?php echo number_format($client_data['total_consideration'], 2); ?></div>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label text-muted small mb-1">Asset Type</label>
                                    <div class="fw-semibold"><?php echo $asset_type_display; ?></div>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label text-muted small mb-1">Trade Side</label>
                                    <div class="fw-semibold badge bg-<?php echo strtolower($modal_data['trade_side']) == 'buy' ? 'success' : 'danger'; ?> px-3 py-2">
                                        <?php echo ucfirst($modal_data['trade_side']); ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mb-4">
                        <h6 class="mb-3 fw-semibold">Individual Trades</h6>
                        <div class="table-responsive">
                            <table class="table table-sm table-hover">
                                <thead>
                                    <tr>
                                        <th>Reference</th>
                                        <th>Quantity</th>
                                        <th>Price</th>
                                        <th>Value</th>
                                        <th>Time</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($trades_list as $trade): ?>
                                    <tr>
                                        <td><code><?php echo htmlspecialchars($trade['trade_reference']); ?></code></td>
                                        <td><?php echo number_format($trade['quantity']); ?></td>
                                        <td>TZS <?php echo number_format($trade['price'], 2); ?></td>
                                        <td>TZS <?php echo number_format($trade['consideration'], 2); ?></td>
                                        <td><?php echo date('H:i:s', strtotime($trade['created_at'])); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    
                    <form method="POST" action="">
                        <input type="hidden" name="client_id" value="<?php echo htmlspecialchars($modal_data['client_id']); ?>">
                        <input type="hidden" name="trade_date" value="<?php echo htmlspecialchars($modal_data['trade_date']); ?>">
                        <input type="hidden" name="trade_side" value="<?php echo htmlspecialchars($modal_data['trade_side']); ?>">
                        <input type="hidden" name="security_id" value="<?php echo htmlspecialchars($modal_data['security_id']); ?>">
                        <input type="hidden" name="trigger_trade_id" value="<?php echo htmlspecialchars($modal_data['trigger_trade_id']); ?>">
                        
                        <h6 class="mb-3 fw-semibold">Select Contract Note Type</h6>
                        <div class="row g-3">
                            <div class="col-md-4">
                                <div class="card h-100 border-0 shadow-sm option-card" data-option="single">
                                    <div class="card-body text-center p-4">
                                        <div class="mb-3">
                                            <i class="bi bi-file-text text-primary" style="font-size: 2.5rem;"></i>
                                        </div>
                                        <h6 class="fw-semibold mb-2">Single Trade</h6>
                                        <p class="text-muted small mb-0">Generate contract note for only the selected trade</p>
                                        <div class="mt-3">
                                            <div class="form-check">
                                                <input class="form-check-input" type="radio" name="contract_type" id="contract_type_single" value="single" checked>
                                                <label class="form-check-label fw-medium" for="contract_type_single">
                                                    Select This
                                                </label>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <?php if ($is_equity_or_etf): ?>
                            <div class="col-md-4">
                                <div class="card h-100 border-0 shadow-sm option-card" data-option="summary">
                                    <div class="card-body text-center p-4">
                                        <div class="mb-3">
                                            <i class="bi bi-file-earmark-bar-graph text-success" style="font-size: 2.5rem;"></i>
                                        </div>
                                        <h6 class="fw-semibold mb-2">Summary (Equities/ETFs)</h6>
                                        <p class="text-muted small mb-0">Consolidated contract note with one summary page</p>
                                        <div class="mt-3">
                                            <div class="form-check">
                                                <input class="form-check-input" type="radio" name="contract_type" id="contract_type_summary" value="summary">
                                                <label class="form-check-label fw-medium" for="contract_type_summary">
                                                    Select This
                                                </label>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>
                            
                            <div class="col-md-4">
                                <div class="card h-100 border-0 shadow-sm option-card" data-option="detailed">
                                    <div class="card-body text-center p-4">
                                        <div class="mb-3">
                                            <i class="bi bi-files text-warning" style="font-size: 2.5rem;"></i>
                                        </div>
                                        <h6 class="fw-semibold mb-2">Detailed (All)</h6>
                                        <p class="text-muted small mb-0">Separate contract notes for each trade in one PDF</p>
                                        <div class="mt-3">
                                            <div class="form-check">
                                                <input class="form-check-input" type="radio" name="contract_type" id="contract_type_detailed" value="detailed">
                                                <label class="form-check-label fw-medium" for="contract_type_detailed">
                                                    Select This
                                                </label>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="mt-4 pt-3 border-top">
                            <div class="d-flex justify-content-between">
                                <a href="trades.php" class="btn btn-outline-secondary">
                                    <i class="bi bi-x-lg me-1"></i>
                                    Cancel
                                </a>
                                <button type="submit" name="generate_contract_note" class="btn btn-primary">
                                    <i class="bi bi-file-earmark-pdf me-1"></i>
                                    Generate Contract Note
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
    
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        // Add hover effect to option cards
        const optionCards = document.querySelectorAll('.option-card');
        optionCards.forEach(card => {
            card.addEventListener('mouseenter', function() {
                this.style.transform = 'translateY(-5px)';
                this.style.transition = 'transform 0.2s ease';
            });
            
            card.addEventListener('mouseleave', function() {
                this.style.transform = 'translateY(0)';
            });
            
            // Click on card selects radio button
            card.addEventListener('click', function() {
                const option = this.getAttribute('data-option');
                const radio = document.querySelector(`#contract_type_${option}`);
                if (radio) {
                    radio.checked = true;
                    
                    // Update card styles
                    optionCards.forEach(c => {
                        c.style.border = '1px solid var(--border-color)';
                        c.style.boxShadow = 'var(--shadow-sm)';
                    });
                    
                    this.style.border = '2px solid var(--primary-color)';
                    this.style.boxShadow = 'var(--shadow-md)';
                }
            });
        });
        
        // Set initial border for selected option
        const selectedRadio = document.querySelector('input[name="contract_type"]:checked');
        if (selectedRadio) {
            const selectedCard = document.querySelector(`.option-card[data-option="${selectedRadio.value}"]`);
            if (selectedCard) {
                selectedCard.style.border = '2px solid var(--primary-color)';
                selectedCard.style.boxShadow = 'var(--shadow-md)';
            }
        }
    });
    </script>
    
    <?php 
    // Clear modal data from session
    unset($_SESSION['contract_modal_data']);
    endif; 
    ?>

    <!-- Enhanced filters section with professional card design -->
    <div class="card dashboard-card mb-4">
        <div class="card-header bg-transparent border-0 pb-0">
            <div class="d-flex align-items-center">
                <div class="me-2">
                    <i class="bi bi-funnel text-primary"></i>
                </div>
                <h6 class="mb-0 fw-semibold">Filter & Search Trades - <?php echo htmlspecialchars($company_name); ?></h6>
            </div>
        </div>
        <div class="card-body">
            <form method="GET" action="" class="row g-4">
                <div class="col-lg-3 col-md-6">
                    <label for="status" class="form-label fw-semibold text-dark">Status</label>
                    <div class="input-group">
                        <span class="input-group-text bg-light border-end-0" style="border-color: var(--border-color);">
                            <i class="bi bi-check-circle-fill text-muted"></i>
                        </span>
                        <select class="form-select border-start-0" id="status" name="status" style="border-color: var(--border-color);">
                            <option value="">All Statuses</option>
                            <option value="active" <?php echo (isset($_GET['status']) && $_GET['status'] == 'active') ? 'selected' : ''; ?>>Active</option>
                            <option value="cancelled" <?php echo (isset($_GET['status']) && $_GET['status'] == 'cancelled') ? 'selected' : ''; ?>>Cancelled</option>
                            <option value="settled" <?php echo (isset($_GET['status']) && $_GET['status'] == 'settled') ? 'selected' : ''; ?>>Settled</option>
                        </select>
                    </div>
                </div>
                <div class="col-lg-3 col-md-6">
                    <label for="type" class="form-label fw-semibold text-dark">Asset Type</label>
                    <div class="input-group">
                        <span class="input-group-text bg-light border-end-0" style="border-color: var(--border-color);">
                            <i class="bi bi-collection text-muted"></i>
                        </span>
                        <select class="form-select border-start-0" id="type" name="type" style="border-color: var(--border-color);">
                            <option value="">All Types</option>
                            <option value="bond" <?php echo (isset($_GET['type']) && $_GET['type'] == 'bond') ? 'selected' : ''; ?>>Bonds</option>
                            <option value="equity" <?php echo (isset($_GET['type']) && $_GET['type'] == 'equity') ? 'selected' : ''; ?>>Equities</option>
                            <option value="Exchange Traded Funds" <?php echo (isset($_GET['type']) && $_GET['type'] == 'Exchange Traded Funds') ? 'selected' : ''; ?>>ETFs</option>
                        </select>
                    </div>
                </div>
                
                <!-- Trade Date Range -->
                <div class="col-lg-3 col-md-6">
                    <label for="trade_date_from" class="form-label fw-semibold text-dark">Trade Date From</label>
                    <div class="input-group">
                        <span class="input-group-text bg-light border-end-0">
                            <i class="bi bi-calendar text-muted"></i>
                        </span>
                        <input type="date" class="form-control border-start-0" id="trade_date_from" name="trade_date_from" 
                               value="<?php echo isset($_GET['trade_date_from']) ? htmlspecialchars($_GET['trade_date_from']) : ''; ?>">
                    </div>
                </div>
                
                <div class="col-lg-3 col-md-6">
                    <label for="trade_date_to" class="form-label fw-semibold text-dark">Trade Date To</label>
                    <div class="input-group">
                        <span class="input-group-text bg-light border-end-0">
                            <i class="bi bi-calendar text-muted"></i>
                        </span>
                        <input type="date" class="form-control border-start-0" id="trade_date_to" name="trade_date_to" 
                               value="<?php echo isset($_GET['trade_date_to']) ? htmlspecialchars($_GET['trade_date_to']) : ''; ?>">
                    </div>
                </div>
                
                <!-- Settlement Date Range -->
                <div class="col-lg-3 col-md-6">
                    <label for="settlement_date_from" class="form-label fw-semibold text-dark">Settlement Date From</label>
                    <div class="input-group">
                        <span class="input-group-text bg-light border-end-0">
                            <i class="bi bi-calendar-check text-muted"></i>
                        </span>
                        <input type="date" class="form-control border-start-0" id="settlement_date_from" name="settlement_date_from" 
                               value="<?php echo isset($_GET['settlement_date_from']) ? htmlspecialchars($_GET['settlement_date_from']) : ''; ?>">
                    </div>
                </div>
                
                <div class="col-lg-3 col-md-6">
                    <label for="settlement_date_to" class="form-label fw-semibold text-dark">Settlement Date To</label>
                    <div class="input-group">
                        <span class="input-group-text bg-light border-end-0">
                            <i class="bi bi-calendar-check text-muted"></i>
                        </span>
                        <input type="date" class="form-control border-start-0" id="settlement_date_to" name="settlement_date_to" 
                               value="<?php echo isset($_GET['settlement_date_to']) ? htmlspecialchars($_GET['settlement_date_to']) : ''; ?>">
                    </div>
                </div>
                
                <div class="col-lg-4 col-md-6">
                    <label for="search" class="form-label fw-semibold text-dark">Search Trades</label>
                    <div class="input-group">
                        <span class="input-group-text bg-light border-end-0" style="border-color: var(--border-color);">
                            <i class="bi bi-search text-muted"></i>
                        </span>
                        <input type="text" class="form-control border-start-0 border-end-0" id="search" name="search" 
                               placeholder="Search by reference, instrument, or client..."
                               value="<?php echo isset($_GET['search']) ? htmlspecialchars($_GET['search']) : ''; ?>">
                    </div>
                </div>
                <div class="col-lg-2 col-md-6">
                    <label class="form-label fw-semibold text-dark">&nbsp;</label>
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary flex-fill">
                            <i class="bi bi-funnel me-1"></i>
                            Apply Filters
                        </button>
                        <a href="trades.php" class="btn btn-outline-secondary">
                            <i class="bi bi-arrow-clockwise"></i>
                        </a>
                    </div>
                </div>
            </form>
            
            <!-- Active Filters Display -->
            <?php
            $active_filters = [];
            if (isset($_GET['status']) && !empty($_GET['status'])) $active_filters[] = "Status: " . ucfirst($_GET['status']);
            if (isset($_GET['type']) && !empty($_GET['type'])) {
                $type_display = ($_GET['type'] === 'Exchange Traded Funds') ? 'ETF' : ucfirst($_GET['type']);
                $active_filters[] = "Asset Type: " . $type_display;
            }
            if (isset($_GET['trade_date_from']) && !empty($_GET['trade_date_from'])) $active_filters[] = "Trade From: " . $_GET['trade_date_from'];
            if (isset($_GET['trade_date_to']) && !empty($_GET['trade_date_to'])) $active_filters[] = "Trade To: " . $_GET['trade_date_to'];
            if (isset($_GET['settlement_date_from']) && !empty($_GET['settlement_date_from'])) $active_filters[] = "Settlement From: " . $_GET['settlement_date_from'];
            if (isset($_GET['settlement_date_to']) && !empty($_GET['settlement_date_to'])) $active_filters[] = "Settlement To: " . $_GET['settlement_date_to'];
            if (isset($_GET['search']) && !empty($_GET['search'])) $active_filters[] = "Search: " . $_GET['search'];
            
            if (!empty($active_filters)): ?>
            <div class="mt-3 pt-3 border-top">
                <div class="d-flex align-items-center mb-2">
                    <i class="bi bi-filter text-primary me-2"></i>
                    <small class="fw-semibold text-dark">Active Filters:</small>
                </div>
                <div class="d-flex flex-wrap gap-2">
                    <?php foreach ($active_filters as $filter): ?>
                        <span class="badge bg-primary px-3 py-2">
                            <i class="bi bi-funnel me-1"></i>
                            <?php echo htmlspecialchars($filter); ?>
                        </span>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Enhanced trades table with modern professional styling -->
    <div class="card dashboard-card">
        <div class="card-header bg-transparent border-0 pb-0">
            <div class="d-flex justify-content-between align-items-center">
                <div class="d-flex align-items-center">
                    <div class="me-2">
                        <i class="bi bi-table text-primary"></i>
                    </div>
                    <h6 class="mb-0 fw-semibold">Trading Portfolio - <?php echo htmlspecialchars($company_name); ?></h6>
                </div>
                <div class="d-flex align-items-center">
                    <span class="badge bg-primary px-3 py-2 me-2"><?php echo number_format($total_trades); ?> Total Trades</span>
                    <div class="dropdown">
                        <button class="btn btn-outline-secondary btn-sm dropdown-toggle" type="button" data-bs-toggle="dropdown">
                            <i class="bi bi-three-dots"></i>
                        </button>
                        <ul class="dropdown-menu">
                            <li>
                                <a class="dropdown-item" href="?<?php echo http_build_query(array_merge($_GET, ['export' => 'csv'])); ?>" 
                                   onclick="return confirm('Export all filtered trades to CSV?')">
                                    <i class="bi bi-file-earmark-excel me-2"></i>Export to CSV
                                </a>
                            </li>
                            <li><a class="dropdown-item" href="#"><i class="bi bi-printer me-2"></i>Print Report</a></li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
        <div class="card-body p-0">
            <?php if (empty($trades)): ?>
                <!-- Enhanced empty state with professional styling -->
                <div class="text-center py-5">
                    <div class="mb-4">
                        <i class="bi bi-graph-up text-muted" style="font-size: 4rem; opacity: 0.3;"></i>
                    </div>
                    <h5 class="text-muted mb-3">No Trades Found</h5>
                    <p class="text-muted mb-4">Start building your trading portfolio by uploading your first trade.</p>
                    <button type="button" class="btn btn-primary btn-lg" data-bs-toggle="modal" data-bs-target="#uploadTradeModal">
                        <i class="bi bi-upload me-2"></i>
                        Upload Your First Trade
                    </button>
                </div>
            <?php else: ?>
                <!-- Bootstrap table with client-side pagination -->
                <div class="p-3 border-bottom">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <small class="text-muted">
                                Showing <span id="currentCount"><?php echo number_format(min(10, $total_trades)); ?></span> of <?php echo number_format($total_trades); ?> trades
                            </small>
                        </div>
                        <div class="d-flex align-items-center">
                            <label for="pageSize" class="form-label mb-0 me-2 small">Show:</label>
                            <select id="pageSize" class="form-select form-select-sm" style="width: auto;">
                                <option value="10">10</option>
                                <option value="25">25</option>
                                <option value="50">50</option>
                                <option value="100">100</option>
                                <option value="500">500</option>
                                <option value="all">All</option>
                            </select>
                        </div>
                    </div>
                </div>
                
                <div class="table-responsive">
                    <table class="table table-hover mb-0" id="tradesTable">
                        <thead style="background: linear-gradient(135deg, var(--background-secondary) 0%, var(--background-muted) 100%);">
                            <tr>
                                <th class="border-0 fw-semibold text-dark py-3">Reference</th>
                                <th class="border-0 fw-semibold text-dark py-3">Instrument</th>
                                <th class="border-0 fw-semibold text-dark py-3">Asset Type</th>
                                <th class="border-0 fw-semibold text-dark py-3">Side</th>
                                <th class="border-0 fw-semibold text-dark py-3">Quantity</th>
                                <th class="border-0 fw-semibold text-dark py-3">Price</th>
                                <th class="border-0 fw-semibold text-dark py-3">Total Value</th>
                                <th class="border-0 fw-semibold text-dark py-3">Client</th>
                                <th class="border-0 fw-semibold text-dark py-3">Counterparty</th>
                                <th class="border-0 fw-semibold text-dark py-3">Trade Date</th>
                                <th class="border-0 fw-semibold text-dark py-3">Status</th>
                                <th class="border-0 fw-semibold text-dark py-3">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="tradesTableBody">
                            <?php foreach ($trades as $trade): 
                                $asset_class_icon = '';
                                $asset_class_badge = '';
                                
                                if ($trade['asset_class'] === 'bond') {
                                    $asset_class_icon = 'bi-bank';
                                    $asset_class_badge = 'bg-warning';
                                } elseif ($trade['asset_class'] === 'Exchange Traded Funds') {
                                    $asset_class_icon = 'bi-pie-chart';
                                    $asset_class_badge = 'bg-purple';
                                } else {
                                    $asset_class_icon = 'bi-graph-up';
                                    $asset_class_badge = 'bg-info';
                                }
                            ?>
                                <tr class="trade-row border-bottom" style="border-color: var(--border-light) !important;">
                                    <td class="border-0 py-3">
                                        <div class="fw-semibold text-primary"><?php echo htmlspecialchars($trade['trade_reference']); ?></div>
                                        <small class="text-muted">Trade ID: <?php echo $trade['id']; ?></small>
                                    </td>
                                    <td class="border-0 py-3">
                                        <div>
                                            <div class="fw-semibold"><?php echo htmlspecialchars($trade['security_id']); ?></div>
                                            <small class="text-muted">
                                                <i class="bi <?php echo $asset_class_icon; ?> me-1"></i>
                                                <?php 
                                                // Display ETF instead of "Exchange Traded Funds"
                                                if ($trade['asset_class'] === 'Exchange Traded Funds') {
                                                    echo 'ETF';
                                                } else {
                                                    echo ucfirst($trade['asset_class']);
                                                }
                                                ?>
                                            </small>
                                        </div>
                                    </td>
                                    <td class="border-0 py-3">
                                        <span class="badge <?php echo $asset_class_badge; ?> px-3 py-2">
                                            <i class="bi <?php echo $asset_class_icon; ?> me-1"></i>
                                            <?php 
                                            // Display ETF instead of "Exchange Traded Funds"
                                            if ($trade['asset_class'] === 'Exchange Traded Funds') {
                                                echo 'ETF';
                                            } else {
                                                echo ucfirst($trade['asset_class']);
                                            }
                                            ?>
                                        </span>
                                    </td>
                                    <td class="border-0 py-3">
                                        <span class="badge bg-<?php echo strtolower($trade['trade_side']) == 'buy' ? 'success' : 'danger'; ?> px-3 py-2">
                                            <i class="bi bi-arrow-<?php echo strtolower($trade['trade_side']) == 'buy' ? 'down' : 'up'; ?> me-1"></i>
                                            <?php echo ucfirst($trade['trade_side']); ?>
                                        </span>
                                    </td>
                                    <td class="border-0 py-3">
                                        <div class="fw-medium"><?php echo number_format($trade['quantity']); ?></div>
                                        <small class="text-muted">Units</small>
                                    </td>
                                    <td class="border-0 py-3">
                                        <div class="fw-medium">TZS <?php echo number_format($trade['price'], 2); ?></div>
                                        <small class="text-muted">Per unit</small>
                                    </td>
                                    <td class="border-0 py-3">
                                        <div class="fw-semibold text-success">TZS <?php echo number_format($trade['consideration'], 2); ?></div>
                                        <small class="text-muted">Total value</small>
                                    </td>
                                 <td class="border-0 py-3">
    <div>
        <!-- Clickable client name -->
        <?php if ($trade['client_id']): ?>
            <a href="client_profile?id=<?php echo $trade['client_id']; ?>" 
               class="fw-medium text-decoration-none text-primary hover-underline" 
               title="View Client Profile">
                <?php echo htmlspecialchars($trade['proper_client_name'] ?? $trade['client_name']); ?>
                <i class="bi bi-box-arrow-up-right ms-1 small"></i>
            </a>
        <?php else: ?>
            <span class="fw-medium"><?php echo htmlspecialchars($trade['client_name']); ?></span>
        <?php endif; ?>
        <small class="text-muted d-block"><?php echo htmlspecialchars($trade['client_cds_account']); ?></small>
    </div>
</td>
                                    <td class="border-0 py-3">
                                        <div>
                                            <div class="fw-medium"><?php echo htmlspecialchars($trade['counterparty_name']); ?></div>
                                            <small class="text-muted"><?php echo htmlspecialchars($trade['counterparty_cds_account']); ?></small>
                                        </div>
                                    </td>
                                    <td class="border-0 py-3">
                                        <div class="fw-medium"><?php echo format_date($trade['trade_date']); ?></div>
                                        <small class="text-muted">Settlement: <?php echo format_date($trade['settlement_date']); ?></small>
                                    </td>
                                    <td class="border-0 py-3">
                                        <span class="badge bg-<?php 
                                            echo $trade['status'] == 'active' ? 'success' : 
                                                ($trade['status'] == 'cancelled' ? 'danger' : 
                                                ($trade['status'] == 'settled' ? 'info' : 'warning')); 
                                        ?> px-3 py-2">
                                            <i class="bi bi-<?php 
                                                echo $trade['status'] == 'active' ? 'check-circle' : 
                                                    ($trade['status'] == 'cancelled' ? 'x-circle' : 
                                                    ($trade['status'] == 'settled' ? 'check-all' : 'clock')); 
                                            ?> me-1"></i>
                                            <?php echo ucfirst($trade['status']); ?>
                                        </span>
                                    </td>
                                    <td class="border-0 py-3">
                                        <div class="btn-group">
                                            <?php if ($trade['status'] == 'active'): ?>
                                                <a href="?action=cancel&id=<?php echo $trade['id']; ?>" 
                                                   class="btn btn-outline-danger btn-sm" 
                                                   onclick="return confirm('Cancel this trade? It will not appear in receipts or ledgers.')"
                                                   title="Cancel Trade">
                                                    <i class="bi bi-x-lg"></i>
                                                </a>
                                                <a href="?action=settle&id=<?php echo $trade['id']; ?>" 
                                                   class="btn btn-outline-info btn-sm" 
                                                   onclick="return confirm('Mark this trade as settled?')"
                                                   title="Settle Trade">
                                                    <i class="bi bi-check-circle"></i>
                                                </a>
                                            <?php elseif ($trade['status'] == 'cancelled'): ?>
                                                <a href="?action=enable&id=<?php echo $trade['id']; ?>" 
                                                   class="btn btn-outline-success btn-sm" 
                                                   onclick="return confirm('Enable this trade?')"
                                                   title="Enable Trade">
                                                    <i class="bi bi-check-lg"></i>
                                                </a>
                                            <?php elseif ($trade['status'] == 'settled'): ?>
                                                <span class="btn btn-outline-secondary btn-sm disabled" title="Trade is settled">
                                                    <i class="bi bi-check-all"></i>
                                                </span>
                                            <?php endif; ?>
                                            <a href="?action=contract_note&id=<?php echo $trade['id']; ?>" 
                                               class="btn btn-outline-primary btn-sm" 
                                               title="Generate Contract Note">
                                                <i class="bi bi-file-earmark-text"></i>
                                            </a>
                                            <a href="view_trade?id=<?php echo $trade['id']; ?>" 
                                               class="btn btn-outline-secondary btn-sm" 
                                               title="View Details">
                                                <i class="bi bi-eye"></i>
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- Bootstrap Pagination -->
                <div class="p-3 border-top">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <small class="text-muted">
                                Showing <span id="showingFrom">1</span> to <span id="showingTo"><?php echo number_format(min(10, $total_trades)); ?></span> of <?php echo number_format($total_trades); ?> trades
                            </small>
                        </div>
                        <nav>
                            <ul class="pagination pagination-sm mb-0" id="pagination">
                                <!-- Pagination will be generated by JavaScript -->
                            </ul>
                        </nav>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Enhanced upload trade modal with professional styling -->
<div class="modal fade" id="uploadTradeModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content" style="border-radius: var(--radius-xl); border: none; box-shadow: var(--shadow-xl);">
            <form method="POST" action="" class="needs-validation" novalidate>
                <div class="modal-header border-0 pb-0" style="background: linear-gradient(135deg, var(--background-secondary) 0%, var(--background-primary) 100%);">
                    <div>
                        <h4 class="modal-title fw-bold text-primary mb-1">Upload New Trade</h4>
                        <p class="text-muted small mb-0">Enter trade details for processing - <?php echo htmlspecialchars($company_name); ?></p>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="row g-4">
                        <div class="col-md-6">
                            <label for="trade_type" class="form-label fw-semibold">Asset Type</label>
                            <select class="form-select" id="trade_type" name="asset_class" required onchange="updateInstruments()">
                                <option value="">Select Asset Type</option>
                                <option value="bond">Bond</option>
                                <option value="equity">Equity</option>
                                <option value="Exchange Traded Funds">ETF</option>
                            </select>
                            <div class="invalid-feedback">Please select an asset type.</div>
                        </div>
                        
                        <div class="col-md-6">
                            <label for="security_id" class="form-label fw-semibold">Security/Instrument</label>
                            <input type="text" class="form-control" id="security_id" name="security_id" 
                                   placeholder="Enter security identifier" required>
                            <div class="invalid-feedback">Please enter a security identifier.</div>
                            <small class="form-text text-muted" id="security-help">Enter the unique security identifier</small>
                        </div>
                        
                        <div class="col-md-6">
                            <label for="trade_side" class="form-label fw-semibold">Trade Side</label>
                            <select class="form-select" id="trade_side" name="trade_side" required>
                                <option value="">Select Side</option>
                                <option value="buy">Buy</option>
                                <option value="sell">Sell</option>
                            </select>
                            <div class="invalid-feedback">Please select trade side.</div>
                        </div>
                        
                        <div class="col-md-6">
                            <label for="trade_reference" class="form-label fw-semibold">Trade Reference</label>
                            <input type="text" class="form-control" id="trade_reference" name="trade_reference" 
                                   placeholder="e.g., TRD-20240101-001" required>
                            <div class="invalid-feedback">Please enter a trade reference.</div>
                        </div>
                        
                        <div class="col-md-4">
                            <label for="quantity" class="form-label fw-semibold">Quantity</label>
                            <input type="number" class="form-control" id="quantity" name="quantity" 
                                   min="1" step="1" placeholder="e.g., 1000" required oninput="calculateTotal()">
                            <div class="invalid-feedback">Please enter a valid quantity.</div>
                        </div>
                        
                        <div class="col-md-4">
                            <label for="price" class="form-label fw-semibold">Price per Unit (TZS)</label>
                            <input type="number" class="form-control" id="price" name="price" 
                                   min="0" step="0.01" placeholder="e.g., 2500.50" required oninput="calculateTotal()">
                            <div class="invalid-feedback">Please enter a valid price.</div>
                        </div>
                        
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Total Consideration</label>
                            <div class="form-control bg-light border-0">
                                <span class="fw-semibold text-success" id="total-consideration">TZS 0.00</span>
                            </div>
                        </div>
                        
                        <div class="col-md-6">
                            <label for="client_name" class="form-label fw-semibold">Client</label>
                            <select class="form-select" id="client_name" name="client_name" required>
                                <option value="">Select Client</option>
                                <?php foreach ($companies as $company): ?>
                                    <option value="<?php echo htmlspecialchars($company['company_name']); ?>">
                                        <?php echo htmlspecialchars($company['company_name']); ?> (<?php echo htmlspecialchars($company['company_code']); ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="invalid-feedback">Please select a client.</div>
                        </div>
                        
                        <div class="col-md-6">
                            <label for="client_cds_account" class="form-label fw-semibold">Client CDS Account</label>
                            <input type="text" class="form-control" id="client_cds_account" name="client_cds_account" 
                                   placeholder="e.g., CDS001234" required>
                            <div class="invalid-feedback">Please enter client CDS account.</div>
                        </div>
                        
                        <div class="col-md-6">
                            <label for="counterparty_name" class="form-label fw-semibold">Counterparty</label>
                            <select class="form-select" id="counterparty_name" name="counterparty_name" required>
                                <option value="">Select Counterparty</option>
                                <?php foreach ($companies as $company): ?>
                                    <option value="<?php echo htmlspecialchars($company['company_name']); ?>">
                                        <?php echo htmlspecialchars($company['company_name']); ?> (<?php echo htmlspecialchars($company['company_code']); ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="invalid-feedback">Please select a counterparty.</div>
                        </div>
                        
                        <div class="col-md-6">
                            <label for="counterparty_cds_account" class="form-label fw-semibold">Counterparty CDS Account</label>
                            <input type="text" class="form-control" id="counterparty_cds_account" name="counterparty_cds_account" 
                                   placeholder="e.g., CDS005678" required>
                            <div class="invalid-feedback">Please enter counterparty CDS account.</div>
                        </div>
                        
                        <div class="col-md-6">
                            <label for="trade_date" class="form-label fw-semibold">Trade Date</label>
                            <input type="date" class="form-control" id="trade_date" name="trade_date" 
                                   value="<?php echo date('Y-m-d'); ?>" required>
                            <div class="invalid-feedback">Please enter trade date.</div>
                        </div>
                        
                        <div class="col-md-6">
                            <label for="settlement_date" class="form-label fw-semibold">Settlement Date</label>
                            <input type="date" class="form-control" id="settlement_date" name="settlement_date" 
                                   value="<?php echo date('Y-m-d', strtotime('+2 days')); ?>" required>
                            <div class="invalid-feedback">Please enter settlement date.</div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-0 pt-0">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">
                        <i class="bi bi-x-lg me-1"></i>
                        Cancel
                    </button>
                    <button type="submit" name="upload_trade" class="btn btn-primary">
                        <i class="bi bi-upload me-1"></i>
                        Upload Trade
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<style>
.badge.bg-purple {
    background-color: #6f42c1 !important;
    color: white;
}

.hover-underline:hover {
    text-decoration: underline !important;
}

/* Pagination styles */
.page-item.active .page-link {
    background-color: var(--primary-color);
    border-color: var(--primary-color);
}

.page-link {
    color: var(--primary-color);
}

.page-link:hover {
    color: var(--primary-color-dark);
    background-color: #f8f9fa;
}

.option-card {
    cursor: pointer;
    transition: all 0.2s ease;
}

.option-card:hover {
    border-color: var(--primary-color) !important;
}
</style>

<script>
// Initialize table search
document.addEventListener('DOMContentLoaded', function() {
    // If modal is open, handle backdrop click
    const contractModal = document.getElementById('contractNoteModal');
    if (contractModal) {
        contractModal.addEventListener('click', function(e) {
            if (e.target === this) {
                window.location.href = 'trades';
            }
        });
    }
    
    // Initialize client-side pagination
    initPagination();
});

function updateInstruments() {
    const tradeType = document.getElementById('trade_type').value;
    const securityInput = document.getElementById('security_id');
    const helpText = document.getElementById('security-help');
    
    securityInput.disabled = false;
    
    if (tradeType === 'bond') {
        securityInput.placeholder = 'e.g., 675-15-T16-A1';
        helpText.textContent = 'Enter bond ATS code (Bond-Coupon-Term-Auction format)';
    } else if (tradeType === 'equity') {
        securityInput.placeholder = 'e.g., WVSL, CRDB';
        helpText.textContent = 'Enter stock symbol or equity identifier';
    } else if (tradeType === 'Exchange Traded Funds') {
        securityInput.placeholder = 'e.g., ETF001, ETF002';
        helpText.textContent = 'Enter ETF identifier';
    } else {
        securityInput.placeholder = 'Enter security identifier';
        helpText.textContent = 'Enter the unique security identifier';
    }
}

function calculateTotal() {
    const quantity = parseFloat(document.getElementById('quantity').value) || 0;
    const price = parseFloat(document.getElementById('price').value) || 0;
    const total = quantity * price;
    
    document.getElementById('total-consideration').textContent = 'TZS ' + total.toLocaleString('en-US', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    });
}

// Client-side pagination function
function initPagination() {
    const rows = document.querySelectorAll('.trade-row');
    const totalRows = rows.length;
    const pageSizeSelect = document.getElementById('pageSize');
    const currentCount = document.getElementById('currentCount');
    const showingFrom = document.getElementById('showingFrom');
    const showingTo = document.getElementById('showingTo');
    const pagination = document.getElementById('pagination');
    
    let currentPage = 1;
    let pageSize = parseInt(pageSizeSelect.value) || 10;
    
    function updateDisplay() {
        // Hide all rows
        rows.forEach(row => row.style.display = 'none');
        
        // Calculate start and end indices
        const startIndex = (currentPage - 1) * pageSize;
        const endIndex = pageSize === 'all' ? totalRows : Math.min(startIndex + pageSize, totalRows);
        
        // Show rows for current page
        for (let i = startIndex; i < endIndex; i++) {
            if (rows[i]) {
                rows[i].style.display = '';
            }
        }
        
        // Update counters
        const displayCount = pageSize === 'all' ? totalRows : Math.min(pageSize, totalRows);
        currentCount.textContent = displayCount.toLocaleString();
        showingFrom.textContent = (startIndex + 1).toLocaleString();
        showingTo.textContent = endIndex.toLocaleString();
        
        // Generate pagination buttons
        generatePaginationButtons();
    }
    
    function generatePaginationButtons() {
        pagination.innerHTML = '';
        
        if (pageSize === 'all' || totalRows <= pageSize) {
            return; // No pagination needed
        }
        
        const totalPages = Math.ceil(totalRows / pageSize);
        
        // Previous button
        const prevLi = document.createElement('li');
        prevLi.className = 'page-item' + (currentPage === 1 ? ' disabled' : '');
        prevLi.innerHTML = `
            <a class="page-link" href="#" aria-label="Previous" ${currentPage === 1 ? 'tabindex="-1"' : ''}>
                <span aria-hidden="true">&laquo;</span>
            </a>
        `;
        prevLi.querySelector('a').addEventListener('click', (e) => {
            e.preventDefault();
            if (currentPage > 1) {
                currentPage--;
                updateDisplay();
            }
        });
        pagination.appendChild(prevLi);
        
        // Page buttons
        for (let i = 1; i <= totalPages; i++) {
            const li = document.createElement('li');
            li.className = 'page-item' + (i === currentPage ? ' active' : '');
            li.innerHTML = `<a class="page-link" href="#">${i}</a>`;
            li.querySelector('a').addEventListener('click', (e) => {
                e.preventDefault();
                currentPage = i;
                updateDisplay();
            });
            pagination.appendChild(li);
        }
        
        // Next button
        const nextLi = document.createElement('li');
        nextLi.className = 'page-item' + (currentPage === totalPages ? ' disabled' : '');
        nextLi.innerHTML = `
            <a class="page-link" href="#" aria-label="Next" ${currentPage === totalPages ? 'tabindex="-1"' : ''}>
                <span aria-hidden="true">&raquo;</span>
            </a>
        `;
        nextLi.querySelector('a').addEventListener('click', (e) => {
            e.preventDefault();
            if (currentPage < totalPages) {
                currentPage++;
                updateDisplay();
            }
        });
        pagination.appendChild(nextLi);
    }
    
    // Handle page size change
    pageSizeSelect.addEventListener('change', function() {
        pageSize = this.value === 'all' ? 'all' : parseInt(this.value);
        currentPage = 1;
        updateDisplay();
    });
    
    // Initial display
    updateDisplay();
}

(function() {
    'use strict';
    window.addEventListener('load', function() {
        var forms = document.getElementsByClassName('needs-validation');
        var validation = Array.prototype.filter.call(forms, function(form) {
            form.addEventListener('submit', function(event) {
                if (form.checkValidity() === false) {
                    event.preventDefault();
                    event.stopPropagation();
                }
                form.classList.add('was-validated');
            }, false);
        });
    }, false);
})();
</script>

<?php include '../includes/footer.php'; ?>