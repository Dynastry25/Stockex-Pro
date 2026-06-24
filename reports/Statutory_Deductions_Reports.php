<?php
/**
 * Statutory_Deductions_Reports.php
 * Immediate PDF export for statutory deductions
 */

// Start session at the very top with output buffering
ob_start();
session_start();

// Include configuration
require_once __DIR__ . '/../config/config.php';
        require_once('../tcpdf/tcpdf.php');
        require_once __DIR__ . '/traits/ReportHeaderTrait.php';

// Custom PDF class with header and footer
class StatutoryDeductionsPDF extends TCPDF {
    use ReportHeaderTrait;
    
    private $company_name = '';
    
    public function setCompanyInfo($company_name) {
        $this->company_name = $company_name;
    }
    
    public function Header() {
        $this->renderReportHeader();
        $this->SetY($this->GetY() + 16);
    }
    
    // Page footer
    public function Footer() {
        // Position at 15 mm from bottom
        $this->SetY(-25);
        
        // Separator line
        $this->Line(15, $this->GetY(), 195, $this->GetY());
        $this->Ln(2);
        
        // Set font
        $this->SetFont('times', 'I', 8);
        
        // Disclaimer
        $disclaimer = $this->company_name . " has prepared this Report solely for informational purposes. " .
                     $this->company_name . " does not represent, warrant or guarantee that the Reports are accurate. " .
                     $this->company_name . " disclaims liability for any direct, indirect, punitive, special, consequential " .
                     "or incidental damages related to the Reports or the use of the Reports.";
        
        $this->MultiCell(0, 4, $disclaimer, 0, 'C');
        
        // Page number
        $this->SetY(-8);
        $this->SetFont('times', '', 8);
        $this->Cell(0, 6, 'Page ' . $this->getAliasNumPage() . ' of ' . $this->getAliasNbPages(), 0, 0, 'C');
    }
}

// Check if this is a request from the filter form
if ($_SERVER['REQUEST_METHOD'] === 'POST' || isset($_GET['action']) || isset($_GET['export'])) {
    try {
        // Get database connection
        $db = getDBConnection();
        if (!$db) {
            throw new Exception("Database connection failed.");
        }
        
        // Check user authorization
        if (!isset($_SESSION['user_id'])) {
            throw new Exception("Unauthorized access. Please login.");
        }
        
        // Get report parameters
        $report_type = $_POST['report_type'] ?? ($_GET['report_type'] ?? 'bonds_statutory');
        $start_date = $_POST['period_from'] ?? ($_GET['start_date'] ?? date('Y-m-01'));
        $end_date = $_POST['period_to'] ?? ($_GET['end_date'] ?? date('Y-m-d'));
        $sca_code = $_POST['sca_code'] ?? ($_GET['sca_code'] ?? 'B13/C');
        $export_type = $_POST['export_type'] ?? ($_GET['export'] ?? 'pdf');
        
        // Fetch company info
        $company = [];
        $company_stmt = $db->query("SELECT company_name, address, phone, email FROM companies WHERE status = 'active' LIMIT 1");
        if ($company_stmt) {
            $company = $company_stmt->fetch(PDO::FETCH_ASSOC);
        }
        
        $company_name = $company['company_name'] ?? 'NEOVAM Limited';
        
        // Determine report type and fetch data
        if ($report_type === 'bonds_statutory') {
            $assetType = 'BONDS';
            $rates = [
                ['agency' => 'CMSA', 'name' => 'CAPITAL MARKETS AND SECURITIES AUTHORITY', 'rate' => 0.0001, 'rateLabel' => '0.010000%'],
                ['agency' => 'DSE', 'name' => 'DAR ES SALAAM STOCK EXCHANGE', 'rate' => 0.0002006, 'rateLabel' => '0.020060%'],
                ['agency' => 'CSD', 'name' => 'CSD & REGISTRY COMPANY LIMITED', 'rate' => 0.000118, 'rateLabel' => '0.011800%'],
                ['agency' => 'VAT', 'name' => 'COMMISSIONER FOR DOMESTIC REVENUE', 'rate' => 0.18, 'rateLabel' => '18.000000%']
            ];
            
            $query = "SELECT 
                        COALESCE(SUM(CASE WHEN trade_side = 'buy' THEN consideration ELSE 0 END), 0) as purchases,
                        COALESCE(SUM(CASE WHEN trade_side = 'sell' THEN consideration ELSE 0 END), 0) as sales
                      FROM trades 
                      WHERE trade_date BETWEEN :start_date AND :end_date 
                        AND asset_class = 'bond' 
                        AND sca_code = :sca_code 
                        AND status = 'active' 
                        AND currency = 'TZS'";
            
        } else { // equities_statutory
            $assetType = 'EQUITIES';
            $rates = [
                ['agency' => 'CMSA', 'name' => 'CAPITAL MARKETS AND SECURITIES AUTHORITY', 'rate' => 0.0014, 'rateLabel' => '0.1400%'],
                ['agency' => 'DSE', 'name' => 'DAR ES SALAAM STOCK EXCHANGE', 'rate' => 0.0002006, 'rateLabel' => '0.02006%'],
                ['agency' => 'CSD', 'name' => 'CSD & REGISTRY COMPANY LIMITED', 'rate' => 0.000708, 'rateLabel' => '0.0708%'],
                ['agency' => 'FIDELITY', 'name' => 'DSE FIDELITY FUND', 'rate' => 0.0002, 'rateLabel' => '0.0200%'],
                ['agency' => 'VAT', 'name' => 'COMMISSIONER FOR DOMESTIC REVENUE', 'rate' => 0.18, 'rateLabel' => '18.0000%']
            ];
            
            $query = "SELECT 
                        COALESCE(SUM(CASE WHEN trade_side = 'buy' THEN consideration ELSE 0 END), 0) as purchases,
                        COALESCE(SUM(CASE WHEN trade_side = 'sell' THEN consideration ELSE 0 END), 0) as sales
                      FROM trades 
                      WHERE trade_date BETWEEN :start_date AND :end_date 
                        AND asset_class = 'equity' 
                        AND sca_code = :sca_code 
                        AND status = 'active' 
                        AND currency = 'TZS'";
        }
        
        $stmt = $db->prepare($query);
        $stmt->execute([
            ':start_date' => $start_date,
            ':end_date' => $end_date,
            ':sca_code' => $sca_code
        ]);
        
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$result || (floatval($result['purchases']) == 0 && floatval($result['sales']) == 0)) {
            // Return minimal error page
            ob_end_clean();
            echo '<html><body style="padding:20px;font-family:Times New Roman;"><h3>No Data Found</h3><p>No transactions found for the selected period.</p><button onclick="history.back()">Go Back</button></body></html>';
            exit;
        }
        
        $purchases = floatval($result['purchases']);
        $sales = floatval($result['sales']);
        $total = $purchases + $sales;
        
        // Generate PDF
        require_once('../tcpdf/tcpdf.php');
        
        $pdf = new StatutoryDeductionsPDF('P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->setCompanyInfo($company_name);
        
        // Set document information
     
        // Set default monospaced font
        $pdf->SetDefaultMonospacedFont('courier');
        
        // Set margins
        $pdf->SetMargins(15, 40, 15);
        $pdf->SetHeaderMargin(10);
        $pdf->SetFooterMargin(30);
        
        // Set auto page breaks
        $pdf->SetAutoPageBreak(TRUE, 35);
        
        // Set font
        $pdf->SetFont('times', '', 12);
        
        $formattedStartDate = date('d/m/Y', strtotime($start_date));
        $formattedEndDate = date('d/m/Y', strtotime($end_date));
        $formattedPurchases = number_format($purchases, 2);
        $formattedSales = number_format($sales, 2);
        $formattedTotal = number_format($total, 2);
        
        $page_count = 1;
        $total_pages = count($rates);
        
        foreach ($rates as $rate) {
            $pdf->AddPage();
            
            $feesPayable = $total * $rate['rate'];
            $formattedAmount = number_format($feesPayable, 2);
            
            // Report Title - Centered
          
            
            // Separator line
     
            
            // To and Date
            $pdf->Cell(20, 6, 'TO:', 0, 0);
            $pdf->SetFont('times', 'B', 11);
            $pdf->Cell(0, 6, $rate['name'], 0, 1);
            
            $pdf->SetFont('times', '', 11);
            $pdf->Cell(20, 6, 'DATE:', 0, 0);
            $pdf->Cell(0, 6, date('d/m/Y'), 0, 1);
            $pdf->Ln(8);
            
            // Letter content
            $pdf->MultiCell(0, 6, 'Dear Sir/Madam,', 0, 'L');
            $pdf->Ln(2);
            
            $letterBody = "Please find enclosed our cheque for the amount of TZS " . $formattedAmount . " being the full and final settlement of the transaction levies on " . strtolower($assetType) . " trades for the period from " . $formattedStartDate . " to " . $formattedEndDate . ".";
            $pdf->MultiCell(0, 6, $letterBody, 0, 'L');
            $pdf->Ln(2);
            
            $pdf->MultiCell(0, 6, 'Should you require any additional information or clarification regarding this remittance, please do not hesitate to contact our office.', 0, 'L');
            $pdf->Ln(10);
            
            // Table - Centered
            $table_start_x = 30;
            $pdf->SetX($table_start_x);
            
            // Table Header
            $pdf->SetFont('times', 'B', 11);
            $pdf->Cell(90, 8, 'PARTICULARS', 1, 0, 'C');
            $pdf->Cell(50, 8, 'AMOUNT (TZS)', 1, 1, 'C');
            
            // Table Data
            $pdf->SetFont('times', '', 11);
            $pdf->SetX($table_start_x);
            $pdf->Cell(90, 7, 'Total Purchases', 1, 0, 'L');
            $pdf->Cell(50, 7, $formattedPurchases, 1, 1, 'R');
            
            $pdf->SetX($table_start_x);
            $pdf->Cell(90, 7, 'Total Sales', 1, 0, 'L');
            $pdf->Cell(50, 7, $formattedSales, 1, 1, 'R');
            
            $pdf->SetFont('times', 'B', 11);
            $pdf->SetX($table_start_x);
            $pdf->Cell(90, 7, 'Total Transaction Value', 1, 0, 'L');
            $pdf->Cell(50, 7, $formattedTotal, 1, 1, 'R');
            
            $pdf->SetFont('times', '', 11);
            $pdf->SetX($table_start_x);
            $pdf->Cell(90, 7, 'Transaction Levy @ ' . $rate['rateLabel'], 1, 0, 'L');
            $pdf->Cell(50, 7, $formattedAmount, 1, 1, 'R');
            
            $pdf->SetFont('times', 'B', 11);
            $pdf->SetFillColor(232, 245, 232);
            $pdf->SetX($table_start_x);
            $pdf->Cell(90, 8, 'TOTAL FEES PAYABLE', 1, 0, 'C', true);
            $pdf->Cell(50, 8, $formattedAmount, 1, 1, 'R', true);
            $pdf->Ln(12);
            

            // Stamp section - Centered
            $pdf->SetFont('times', 'B', 11);
            $pdf->Cell(0, 7, 'RECEIVED AND ACKNOWLEDGED:', 0, 1, 'C');
            $pdf->Ln(4);
            
            // Stamp boxes - Centered
            $currentY = $pdf->GetY();
            $boxWidth = 75;
            $boxHeight = 22;
            $centerX = (210 - (2 * $boxWidth + 15)) / 2;
            
            // Left stamp box
            $pdf->SetDrawColor(150, 150, 150);
            $pdf->Rect($centerX, $currentY, $boxWidth, $boxHeight);
            $pdf->SetXY($centerX, $currentY + ($boxHeight/2) - 2);
            $pdf->SetFont('times', '', 9);
            $pdf->Cell($boxWidth, 4, $rate['agency'] . ' STAMP & SIGNATURE', 0, 1, 'C');
            
            // Right stamp box
            $rightBoxX = $centerX + $boxWidth + 15;
            $pdf->Rect($rightBoxX, $currentY, $boxWidth, $boxHeight);
            $pdf->SetXY($rightBoxX, $currentY + ($boxHeight/2) - 2);
            $pdf->Cell($boxWidth, 4, 'COMPANY STAMP', 0, 1, 'C');
            
            $pdf->SetDrawColor(0, 0, 0);
            
            $pdf->SetY($currentY + $boxHeight + 8);
            $pdf->SetFont('times', '', 10);
            
            // Left signature details
            $pdf->SetX($centerX);
            $pdf->Cell($boxWidth, 5, 'Name and Sign: ______________________________', 0, 1, 'L');
            $pdf->SetX($centerX);
            $pdf->Cell($boxWidth, 5, 'Designation:  _________________________________', 0, 1, 'L');
            $pdf->SetX($centerX);
            $pdf->Cell($boxWidth, 5, 'Date:  _________________________________', 0, 1, 'L');
            
            // Right signature details
            $pdf->SetXY($rightBoxX, $currentY + $boxHeight + 8);
            $pdf->Cell($boxWidth, 5, 'Name and Sign:  _________________________________', 0, 1, 'L');
            $pdf->SetX($rightBoxX);
            $pdf->Cell($boxWidth, 5, 'Designation: Finance Manager', 0, 1, 'L');
            $pdf->SetX($rightBoxX);
            $pdf->Cell($boxWidth, 5, 'Date: ' . date('d/m/Y'), 0, 1, 'L');
            
            // Generation info at bottom (above footer)
            if ($pdf->GetY() > 230) {
                $pdf->SetY(230);
            }
            
          
            $page_count++;
        }
        
        // Output PDF
        ob_end_clean();
        $filename = 'statutory_' . strtolower($assetType) . '_' . date('Ymd_His') . '.pdf';
        $pdf->Output($filename, 'D');
        
    } catch (Exception $e) {
        ob_end_clean();
        echo '<html><body style="padding:20px;font-family:Times New Roman;font-size:12pt;"><h3>Error</h3><p>' . htmlspecialchars($e->getMessage()) . '</p><button onclick="history.back()">Go Back</button></body></html>';
    }
    exit;
}

// If accessed directly without parameters, return nothing
ob_end_clean();
die();