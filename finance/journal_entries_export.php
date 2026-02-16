<?php
require_once '../config/config.php';
require_once '../auth/auth_middleware.php';

// Security headers
header("X-Frame-Options: DENY");
header("X-Content-Type-Options: nosniff");
header("X-XSS-Protection: 1; mode=block");

require_finance_officer();

$db = getDBConnection();

function validateDate($date, $format = 'Y-m-d') {
    $d = DateTime::createFromFormat($format, $date);
    return $d && $d->format($format) === $date;
}

function sanitizeInput($input) {
    return htmlspecialchars(trim($input ?? ''), ENT_QUOTES, 'UTF-8');
}

// Get filter values from GET parameters
$start_date = $_GET['start_date'] ?? '';
$end_date = $_GET['end_date'] ?? '';
$account_filter = $_GET['account'] ?? '';
$reference_type_filter = $_GET['reference_type'] ?? '';
$search_term = $_GET['search'] ?? '';
$export_type = $_GET['export'] ?? 'excel';

// Validate and sanitize inputs
if ($start_date && !validateDate($start_date)) {
    $start_date = '';
}
if ($end_date && !validateDate($end_date)) {
    $end_date = '';
}

// Build query (same as main page)
$query = "
    SELECT
        gl.id,
        gl.transaction_date,
        gl.account_id,
        gl.account_code,
        gl.account_name,
        gl.debit_amount,
        gl.credit_amount,
        gl.description,
        gl.reference_no,
        gl.reference_type,
        gl.entity_name,
        gl.entity_type,
        gl.currency,
        gl.created_by_username,
        gl.created_at,
        coa.account_type,
        coa.normal_balance,
        u.full_name as created_by_name
    FROM general_ledger gl
    LEFT JOIN chart_of_accounts coa ON gl.account_id = coa.id
    LEFT JOIN users u ON gl.created_by = u.id
    WHERE gl.status = 'active'
";

$params = [];

// Apply date filter
if ($start_date && $end_date) {
    $query .= " AND gl.transaction_date BETWEEN ? AND ?";
    $params[] = $start_date;
    $params[] = $end_date;
}

// Apply account filter
if (!empty($account_filter)) {
    if (is_numeric($account_filter)) {
        $query .= " AND gl.account_id = ?";
        $params[] = $account_filter;
    } else {
        $query .= " AND gl.account_code = ?";
        $params[] = $account_filter;
    }
}

// Apply reference type filter
if (!empty($reference_type_filter)) {
    $valid_types = ['trade', 'fee', 'adjustment', 'investment', 'payment', 'receipt', 'invoice', 'journal', 'transfer', 'expense', 'income'];
    if (in_array($reference_type_filter, $valid_types)) {
        $query .= " AND gl.reference_type = ?";
        $params[] = $reference_type_filter;
    }
}

// Apply search filter
if (!empty($search_term)) {
    $query .= " AND (gl.description LIKE ? OR gl.reference_no LIKE ? OR gl.account_name LIKE ?)";
    $search_like = "%$search_term%";
    $params[] = $search_like;
    $params[] = $search_like;
    $params[] = $search_like;
}

// Add ordering
$query .= " ORDER BY gl.transaction_date DESC, gl.id DESC";

// Execute query
$stmt = $db->prepare($query);
$stmt->execute($params);
$entries = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get company info
$company_stmt = $db->prepare("SELECT * FROM companies WHERE status = 'active' ORDER BY id ASC LIMIT 1");
$company_stmt->execute();
$company_result = $company_stmt->fetch(PDO::FETCH_ASSOC);
$company = $company_result ?: [
    'company_name' => 'NEOVAM LTD',
    'registration_number' => 'Not Registered',
    'address' => 'Address Not Set',
    'city' => 'Dar es Salaam',
    'country' => 'Tanzania',
    'phone' => 'Not Available',
    'email' => 'Not Available',
    'website' => 'Not Available',
    'currency' => 'TZS'
];

if ($export_type === 'excel') {
    // Excel export
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="journal_entries_' . date('Y-m-d') . '.xls"');
    header('Cache-Control: max-age=0');

    echo "<html><head><meta charset='UTF-8'></head><body>";
    echo "<h2>Journal Entries Report</h2>";
    echo "<p>Company: " . htmlspecialchars((string)($company['company_name'] ?? 'NEOVAM LTD')) . "</p>";
    echo "<p>Period: " . ($start_date && $end_date ? htmlspecialchars($start_date . ' to ' . $end_date) : 'All Dates') . "</p>";
    echo "<p>Generated: " . date('Y-m-d H:i:s') . "</p><br>";

    echo "<table border='1'>";
    echo "<tr style='background-color: #f0f0f0; font-weight: bold;'>";
    echo "<th>Date</th>";
    echo "<th>Reference</th>";
    echo "<th>Account Code</th>";
    echo "<th>Account Name</th>";
    echo "<th>Description</th>";
    echo "<th>Type</th>";
    echo "<th>Debit</th>";
    echo "<th>Credit</th>";
    echo "<th>Created By</th>";
    echo "</tr>";

    foreach ($entries as $entry) {
        echo "<tr>";
        echo "<td>" . htmlspecialchars((string)($entry['transaction_date'] ?? '')) . "</td>";
        echo "<td>" . htmlspecialchars((string)($entry['reference_no'] ?? '')) . "</td>";
        echo "<td>" . htmlspecialchars((string)($entry['account_code'] ?? '')) . "</td>";
        echo "<td>" . htmlspecialchars((string)($entry['account_name'] ?? '')) . "</td>";
        echo "<td>" . htmlspecialchars((string)($entry['description'] ?? '')) . "</td>";
        echo "<td>" . htmlspecialchars(ucfirst((string)($entry['reference_type'] ?? ''))) . "</td>";
        echo "<td style='text-align: right;'>" . ($entry['debit_amount'] > 0 ? number_format($entry['debit_amount'], 2) : '-') . "</td>";
        echo "<td style='text-align: right;'>" . ($entry['credit_amount'] > 0 ? number_format($entry['credit_amount'], 2) : '-') . "</td>";
        echo "<td>" . htmlspecialchars((string)($entry['created_by_username'] ?? '')) . "</td>";
        echo "</tr>";
    }

    echo "</table>";
    echo "</body></html>";
    exit;

} elseif ($export_type === 'pdf') {
    // Suppress warnings to prevent header issues
    error_reporting(E_ERROR | E_PARSE);

    // PDF export using TCPDF
    require_once '../tcpdf/tcpdf.php';

    class JournalEntriesPDF extends TCPDF {
        private $company;
        private $start_date;
        private $end_date;

        public function __construct($company, $start_date, $end_date, $orientation='P', $unit='mm', $format='A4', $unicode=true, $encoding='UTF-8', $diskcache=false, $pdfa=false) {
            $this->company = $company;
            $this->start_date = $start_date;
            $this->end_date = $end_date;
            parent::__construct($orientation, $unit, $format, $unicode, $encoding, $diskcache, $pdfa);
        }

        public function Header() {
            $this->SetFont('helvetica', 'B', 16);
            $this->Cell(0, 15, 'Journal Entries Report', 0, false, 'C', 0, '', 0, false, 'M', 'M');
            $this->Ln(10);
            $this->SetFont('helvetica', '', 10);
            $this->Cell(0, 10, 'Company: ' . htmlspecialchars((string)($this->company['company_name'] ?? 'NEOVAM LTD')), 0, false, 'L', 0, '', 0, false, 'M', 'M');
            $this->Ln(5);
            $this->Cell(0, 10, 'Period: ' . ($this->start_date && $this->end_date ? htmlspecialchars($this->start_date . ' to ' . $this->end_date) : 'All Dates'), 0, false, 'L', 0, '', 0, false, 'M', 'M');
            $this->Ln(5);
            $this->Cell(0, 10, 'Generated: ' . date('Y-m-d H:i:s'), 0, false, 'L', 0, '', 0, false, 'M', 'M');
            $this->Ln(10);
        }

        public function Footer() {
            $this->SetY(-15);
            $this->SetFont('helvetica', 'I', 8);
            $this->Cell(0, 10, 'Page ' . $this->getAliasNumPage() . '/' . $this->getAliasNbPages(), 0, false, 'C', 0, '', 0, false, 'T', 'M');
        }
    }

    $pdf = new JournalEntriesPDF($company, $start_date, $end_date, PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
    $pdf->SetCreator(PDF_CREATOR);
    $pdf->SetAuthor('Stock Exchange System');
    $pdf->SetTitle('Journal Entries Report');
    $pdf->SetSubject('Journal Entries Export');

    $pdf->setHeaderFont(Array(PDF_FONT_NAME_MAIN, '', PDF_FONT_SIZE_MAIN));
    $pdf->setFooterFont(Array(PDF_FONT_NAME_DATA, '', PDF_FONT_SIZE_DATA));
    $pdf->SetDefaultMonospacedFont(PDF_FONT_MONOSPACED);
    $pdf->SetMargins(PDF_MARGIN_LEFT, PDF_MARGIN_TOP, PDF_MARGIN_RIGHT);
    $pdf->SetHeaderMargin(PDF_MARGIN_HEADER);
    $pdf->SetFooterMargin(PDF_MARGIN_FOOTER);
    $pdf->SetAutoPageBreak(TRUE, PDF_MARGIN_BOTTOM);
    $pdf->setImageScale(PDF_IMAGE_SCALE_RATIO);

    $pdf->AddPage();

    // Table headers
    $pdf->SetFont('helvetica', 'B', 9);
    $pdf->SetFillColor(240, 240, 240);

    $headers = array('Date', 'Reference', 'Account', 'Description', 'Type', 'Debit', 'Credit');
    $widths = array(25, 30, 35, 50, 20, 25, 25);

    foreach ($headers as $i => $header) {
        $pdf->Cell($widths[$i], 8, $header, 1, 0, 'C', 1);
    }
    $pdf->Ln();

    // Table data
    $pdf->SetFont('helvetica', '', 8);
    $pdf->SetFillColor(255, 255, 255);

    $fill = false;
    foreach ($entries as $entry) {
        $pdf->Cell($widths[0], 6, date('M d, Y', strtotime($entry['transaction_date'])), 1, 0, 'L', $fill);
        $pdf->Cell($widths[1], 6, $entry['reference_no'], 1, 0, 'L', $fill);
        $pdf->Cell($widths[2], 6, substr($entry['account_code'] . ' - ' . $entry['account_name'], 0, 20), 1, 0, 'L', $fill);
        $pdf->Cell($widths[3], 6, substr($entry['description'], 0, 30), 1, 0, 'L', $fill);
        $pdf->Cell($widths[4], 6, ucfirst($entry['reference_type']), 1, 0, 'C', $fill);
        $pdf->Cell($widths[5], 6, $entry['debit_amount'] > 0 ? number_format($entry['debit_amount'], 2) : '-', 1, 0, 'R', $fill);
        $pdf->Cell($widths[6], 6, $entry['credit_amount'] > 0 ? number_format($entry['credit_amount'], 2) : '-', 1, 0, 'R', $fill);
        $pdf->Ln();
        $fill = !$fill;
    }

    $pdf->Output('journal_entries_' . date('Y-m-d') . '.pdf', 'D');
    exit;

} else {
    // Invalid export type
    header('HTTP/1.1 400 Bad Request');
    echo 'Invalid export type';
    exit;
}
?>