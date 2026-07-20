<?php
require_once('../config/config.php');
require_once('../auth/auth_middleware.php');
require_finance_officer();

// Include TCPDF library
require_once('../tcpdf/tcpdf.php');
require_once __DIR__ . '/../reports/traits/ReportHeaderTrait.php';

class ReceiptPDF extends TCPDF {
    use ReportHeaderTrait;
    
    private $company_data;
    
    public function __construct($company_data = null) {
        parent::__construct(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
        $this->company_data = $company_data;
    }
    
    public function Header() {
        $this->renderReportHeader();
    }

    // Footer
    public function Footer() {
        $this->SetY(-25);
        $this->SetFont('helvetica', 'I', 7);
        $this->SetTextColor(100, 100, 100);
        
        // Get company name from database or use default
        $company_name = $this->company_data['company_name'] ?? 'Neovam Technologies LTD';
        
        // Add footer image if exists
        $footer_path = $this->company_data['footer_image_path'] ?? '';
        if (file_exists($footer_path) && !empty($this->company_data['footer_image_path'])) {
            $this->Image($footer_path, 15, $this->GetY() - 20, 180, 15, '', '', 'T', false, 300, '', false, false, 0, false, false, false);
            $this->SetY(-10);
        }
        
        $this->Cell(0, 4, $company_name . ' has prepared this Report solely for informational purposes.', 0, 1, 'C');
        $this->Cell(0, 4, $company_name . ' does not represent warrant or guarantees that the Reports are accurate.', 0, 1, 'C');
        $this->Cell(0, 4, $company_name . ' disclaims liability for any direct indirect printing special consequential or incidental damages.', 0, 1, 'C');
        
        $this->SetY(-8);
        $this->SetFont('helvetica', 'I', 8);
        $this->Cell(0, 4, 'Page '.$this->getAliasNumPage().'/'.$this->getAliasNbPages(), 0, 0, 'C');
    }
    
    // Add decorative border
    public function AddDecorativeBorder() {
        $this->SetLineWidth(0.5);
        $this->SetDrawColor(0, 0, 128);
        $this->Rect(10, 45, 190, 240);
        
        $this->SetLineWidth(0.2);
        $this->SetDrawColor(200, 200, 200);
        $this->Rect(11, 46, 188, 238);
    }
    
    // Function to adjust font size for long text - made public
    public function getAdjustedFontSize($text, $max_width, $max_font_size = 10, $min_font_size = 7) {
        $current_font_size = $max_font_size;
        
        while ($current_font_size >= $min_font_size) {
            $this->SetFont('helvetica', '', $current_font_size);
            $text_width = $this->GetStringWidth($text);
            if ($text_width <= $max_width) {
                return $current_font_size;
            }
            $current_font_size--;
        }
        
        return $min_font_size;
    }
    
    // Public method to write text with adjusted font size
    public function writeAdjustedText($text, $max_width, $x, $y, $max_font_size = 10, $min_font_size = 7) {
        $font_size = $this->getAdjustedFontSize($text, $max_width, $max_font_size, $min_font_size);
        $this->SetFont('helvetica', '', $font_size);
        $this->SetXY($x, $y);
        $this->Cell(0, 6, $text, 0, 1, 'L');
        return $font_size;
    }
}

// Function to convert amount to words
// Function to convert amount to words
function convertAmountToWords($number) {
    $whole = floor($number);
    $fraction = round(($number - $whole) * 100);
    
    $words = convertNumberToWords($whole) . ' SHILLINGS';
    
    if ($fraction > 0) {
        $words .= ' AND ' . convertNumberToWords($fraction) . ' CENTS';
    } else {
        $words .= ' ZERO CENTS';
    }
    
    return strtoupper($words) . ' ONLY';
}

// Helper function to convert numbers to words
function convertNumberToWords($number) {
    if ($number == 0) {
        return 'ZERO';
    }
    
    $units = ['', 'ONE', 'TWO', 'THREE', 'FOUR', 'FIVE', 'SIX', 'SEVEN', 'EIGHT', 'NINE'];
    $teens = ['TEN', 'ELEVEN', 'TWELVE', 'THIRTEEN', 'FOURTEEN', 'FIFTEEN', 'SIXTEEN', 'SEVENTEEN', 'EIGHTEEN', 'NINETEEN'];
    $tens = ['', '', 'TWENTY', 'THIRTY', 'FORTY', 'FIFTY', 'SIXTY', 'SEVENTY', 'EIGHTY', 'NINETY'];
    
    $words = '';
    
    // Handle trillions
    if ($number >= 1000000000000) {
        $trillions = floor($number / 1000000000000);
        $words .= convertNumberToWords($trillions) . ' TRILLION ';
        $number %= 1000000000000;
    }
    
    // Handle billions
    if ($number >= 1000000000) {
        $billions = floor($number / 1000000000);
        $words .= convertNumberToWords($billions) . ' BILLION ';
        $number %= 1000000000;
    }
    
    // Handle millions
    if ($number >= 1000000) {
        $millions = floor($number / 1000000);
        $words .= convertNumberToWords($millions) . ' MILLION ';
        $number %= 1000000;
    }
    
    // Handle thousands
    if ($number >= 1000) {
        $thousands = floor($number / 1000);
        $words .= convertNumberToWords($thousands) . ' THOUSAND ';
        $number %= 1000;
    }
    
    // Handle hundreds
    if ($number >= 100) {
        $hundreds = floor($number / 100);
        $words .= $units[$hundreds] . ' HUNDRED ';
        $number %= 100;
    }
    
    // Handle tens and units
    if ($number > 0) {
        if (!empty(trim($words))) {
            $words .= 'AND ';
        }
        
        if ($number < 10) {
            $words .= $units[$number];
        } elseif ($number < 20) {
            $words .= $teens[$number - 10];
        } else {
            $tensDigit = floor($number / 10);
            $unitsDigit = $number % 10;
            $words .= $tens[$tensDigit];
            if ($unitsDigit > 0) {
                $words .= ' ' . $units[$unitsDigit];
            }
        }
    }
    
    return trim($words);
}

// Get receipt ID from query parameter
$receipt_id = $_GET['receipt_id'] ?? 0;

if (!$receipt_id) {
    die('Invalid receipt ID');
}

try {
    $db = getDBConnection();
    
    // Fetch company data
    $company_stmt = $db->query("SELECT * FROM companies WHERE status = 'active' LIMIT 1");
    $company_data = $company_stmt->fetch();
    
    // Fetch receipt data
    $stmt = $db->prepare("
        SELECT r.*, 
               pm.description as payment_method_desc,
               lt.description as account_of_desc,
               ba.bank_name,
               ba.account_number as bank_account_number,
               ba.account_name as bank_account_name
        FROM receipts r
        LEFT JOIN payment_methods pm ON r.payment_mode = pm.id
        LEFT JOIN ledger_types lt ON r.account_of = lt.code
        LEFT JOIN banks_accounts ba ON r.ac_debit = ba.id
        WHERE r.id = ?
    ");
    $stmt->execute([$receipt_id]);
    $receipt = $stmt->fetch();

    if (!$receipt) {
        die('Receipt not found');
    }

    // Fetch logged in user's name
    $user_id = $_SESSION['user_id'] ?? 0;
    $user_name = 'ERNEST KENARD MSWIMA'; // Default fallback
    
    if ($user_id) {
        $user_stmt = $db->prepare("SELECT username, full_name FROM users WHERE id = ?");
        $user_stmt->execute([$user_id]);
        $user_data = $user_stmt->fetch();
        
        if ($user_data) {
            $user_name = $user_data['full_name'] ?? $user_data['username'] ?? 'ERNEST KENARD MSWIMA';
        }
    }

    // Create new PDF document with company data
    $pdf = new ReceiptPDF($company_data);

    // Set document information
    $company_name = $company_data['company_name'] ?? 'Neovam Technologies LTD';
    $pdf->SetCreator($company_name);
    $pdf->SetAuthor($company_name);
    $pdf->SetTitle('Receipt - ' . $receipt['receipt_no']);
    $pdf->SetSubject('Official Receipt');

    // Set margins
    $pdf->SetMargins(15, 50, 15);
    $pdf->SetHeaderMargin(10);
    $pdf->SetFooterMargin(15);

    // Add a page
    $pdf->AddPage();
    
    // Add decorative border
    $pdf->AddDecorativeBorder();

    // Receipt header with background
    $pdf->SetFillColor(240, 240, 240);
    $pdf->SetTextColor(0, 0, 128);
    $pdf->SetFont('helvetica', 'B', 20);
    $pdf->Cell(0, 12, 'OFFICIAL RECEIPT', 0, 1, 'C', true);
    $pdf->Ln(5);

    // Receipt details table with zig-zag arrangement
    $pdf->SetTextColor(0, 0, 0);

    // Get current Y position
    $current_y = $pdf->GetY();

    // Row 1: CDS ACCOUNT (Left) and RECEIPT NUMBER (Right)
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->Cell(40, 7, 'CDS ACCOUNT:', 0, 0, 'L');
    
    // CDS ACCOUNT with adjusted font size
    $cds_account = $receipt['cds_account'] ?? 'N/A';
    $cds_font_size = $pdf->getAdjustedFontSize($cds_account, 50);
    $pdf->SetFont('helvetica', '', $cds_font_size);
    $pdf->Cell(50, 7, $cds_account, 0, 0, 'L');
    
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->Cell(40, 7, 'RECEIPT NUMBER:', 0, 0, 'L');
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->SetTextColor(0, 0, 128);
    $pdf->Cell(0, 7, $receipt['receipt_no'], 0, 1, 'L');
    $pdf->SetTextColor(0, 0, 0);

    // Row 2: ACCOUNT NO (Left) and RECEIPT DATE (Right)
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->Cell(40, 7, 'ACCOUNT NO:', 0, 0, 'L');
    
    // ACCOUNT NO with adjusted font size
    $account_no = $receipt['account_no'] ?? 'N/A';
    $account_no_font_size = $pdf->getAdjustedFontSize($account_no, 50);
    $pdf->SetFont('helvetica', '', $account_no_font_size);
    $pdf->Cell(50, 7, $account_no, 0, 0, 'L');
    
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->Cell(40, 7, 'RECEIPT DATE:', 0, 0, 'L');
    $pdf->SetFont('helvetica', '', 10);
    $pdf->Cell(0, 7, date('F d, Y', strtotime($receipt['receipt_date'])), 0, 1, 'L');

    // Row 3: ACCOUNT NAME (Full width - this will be on its own row)
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->Cell(40, 7, 'ACCOUNT NAME:', 0, 0, 'L');
    
    // ACCOUNT NAME with adjusted font size
    $account_name = $receipt['name'];
    $name_font_size = $pdf->getAdjustedFontSize($account_name, 140);
    $pdf->SetFont('helvetica', '', $name_font_size);
    $pdf->Cell(0, 7, $account_name, 0, 1, 'L');

    // Row 4: PAYMENT MODE (Left) and CHEQUE NUMBER (Right)
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->Cell(40, 7, 'PAYMENT MODE:', 0, 0, 'L');
    
    // PAYMENT MODE with adjusted font size
    $payment_mode = $receipt['payment_method_desc'];
    $payment_font_size = $pdf->getAdjustedFontSize($payment_mode, 50);
    $pdf->SetFont('helvetica', '', $payment_font_size);
    $pdf->Cell(50, 7, $payment_mode, 0, 0, 'L');
    
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->Cell(40, 7, 'CHEQUE NUMBER:', 0, 0, 'L');
    $pdf->SetFont('helvetica', '', 10);
    $pdf->Cell(0, 7, $receipt['cheque_no'] ?: 'CASH', 0, 1, 'L');

    $pdf->Ln(8);

    // Amount row with highlight
    $pdf->SetFillColor(245, 245, 255);
    $pdf->SetFont('helvetica', 'B', 12);
    $pdf->Cell(40, 10, 'RECEIPT AMOUNT:', 0, 0, 'L', true);
    $pdf->SetFont('helvetica', 'B', 14);
    $pdf->SetTextColor(0, 100, 0);
    $pdf->Cell(0, 10, number_format($receipt['amount'], 2) . ' ' . $receipt['currency'], 0, 1, 'L', true);
    $pdf->SetTextColor(0, 0, 0);

    $pdf->Ln(10);

    // Amount in words section with background
    $pdf->SetFillColor(250, 250, 250);
    $pdf->SetFont('helvetica', 'B', 11);
    $pdf->Cell(0, 8, 'AMOUNT IN WORDS', 1, 1, 'C', true);
    
    $amount_words = convertAmountToWords($receipt['amount']);
    
    $pdf->SetFont('helvetica', '', 10);
    $pdf->MultiCell(0, 6, $amount_words, 1, 'L', false);
    $pdf->Ln(8);

    // In respect of section
    $pdf->SetFillColor(250, 250, 250);
    $pdf->SetFont('helvetica', 'B', 11);
    $pdf->Cell(0, 8, 'IN RESPECT OF:', 1, 1, 'C', true);
    
    $pdf->SetFont('helvetica', '', 10);
    $pdf->MultiCell(0, 6, $receipt['narration'] ?: 'Payment received as per agreement', 1, 'L', false);
    $pdf->Ln(12);

    // Signature section
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->Cell(90, 6, 'RECEIPTED BY:', 0, 0, 'L');
    $pdf->Cell(0, 6, 'AUTHORIZED BY', 0, 1, 'L');

    $pdf->Ln(12);

    // Signature lines
    $pdf->SetLineWidth(0.5);
    $pdf->Line(25, $pdf->GetY(), 85, $pdf->GetY());
    $pdf->Line(110, $pdf->GetY(), 170, $pdf->GetY());
    
    $pdf->Ln(8);

    // Names - using logged in user's name for Receipted By
    $pdf->SetFont('helvetica', '', 10);
    
    // User name with adjusted font size
    $user_name_font_size = $pdf->getAdjustedFontSize($user_name, 80);
    $pdf->SetFont('helvetica', '', $user_name_font_size);
    $pdf->Cell(90, 6, $user_name, 0, 0, 'C');
    
    $pdf->SetFont('helvetica', '', 10);
    $pdf->Cell(0, 6, 'Finance Officer', 0, 1, 'C');

    $pdf->Ln(6);

    // Stamp area - removed "Finance Department"
    $pdf->SetFont('helvetica', 'I', 8);
    $pdf->Cell(90, 6, 'Affix Stamp', 0, 0, 'C');
    $pdf->Cell(0, 6, 'Authorized Signature', 0, 1, 'C');

    // Output PDF
    $pdf->Output('receipt_' . $receipt['receipt_no'] . '.pdf', 'D');

} catch (PDOException $e) {
    die('Database error: ' . $e->getMessage());
}