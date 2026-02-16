<?php
// Enable error reporting for debugging (remove in production)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once '../config/config.php';
require_once '../auth/auth_middleware.php';

// Check authentication
require_finance_officer();

// Include TCPDF library from your local installation
require_once('../tcpdf/tcpdf.php');

// Get receipt ID
$receipt_id = isset($_GET['receipt_id']) ? (int)$_GET['receipt_id'] : 0;

if ($receipt_id <= 0) {
    die('<div style="padding:20px;color:red;font-family:Arial;">Invalid receipt ID. Please provide a valid receipt ID.</div>');
}

$db = null;
$receipt = null;

try {
    // Get database connection
    $db = getDBConnection();
    
    // Fetch receipt with improved query
    $stmt = $db->prepare("
        SELECT 
            r.*,
            pm.description as payment_method_desc,
            lt.description as account_of_desc,
            ba.bank_name,
            ba.account_number as bank_account_number,
            ba.account_name as bank_account_name,
            ba.code as bank_account_code,
            u.full_name as generated_by_name,
            u.username as generated_by_username,
            (SELECT setting_value FROM system_settings WHERE setting_key = 'company_name' LIMIT 1) as company_name,
            (SELECT setting_value FROM system_settings WHERE setting_key = 'system_name' LIMIT 1) as system_name
        FROM receipts r
        LEFT JOIN payment_methods pm ON r.payment_mode = pm.id
        LEFT JOIN ledger_types lt ON r.account_of = lt.code
        LEFT JOIN banks_accounts ba ON r.ac_debit = ba.id
        LEFT JOIN users u ON r.created_by = u.id
        WHERE r.id = ?
        LIMIT 1
    ");
    
    $stmt->execute([$receipt_id]);
    $receipt = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$receipt) {
        die('<div style="padding:20px;color:red;font-family:Arial;">Receipt with ID ' . $receipt_id . ' not found in the database.</div>');
    }
    
    // Check required fields
    $required_fields = ['receipt_no', 'receipt_date', 'name', 'amount'];
    foreach ($required_fields as $field) {
        if (empty($receipt[$field])) {
            die('<div style="padding:20px;color:red;font-family:Arial;">Required field "' . $field . '" is missing from receipt data.</div>');
        }
    }
    
} catch (PDOException $e) {
    die('<div style="padding:20px;color:red;font-family:Arial;">Database Error: ' . htmlspecialchars($e->getMessage()) . '</div>');
} catch (Exception $e) {
    die('<div style="padding:20px;color:red;font-family:Arial;">Error: ' . htmlspecialchars($e->getMessage()) . '</div>');
}

// Helper functions for number to words conversion
function convert_number_to_words($number) {
    if (!is_numeric($number)) {
        return 'Zero';
    }
    
    $whole = floor($number);
    $fraction = round(($number - $whole) * 100);
    
    $dictionary = [
        0 => 'Zero', 1 => 'One', 2 => 'Two', 3 => 'Three', 4 => 'Four',
        5 => 'Five', 6 => 'Six', 7 => 'Seven', 8 => 'Eight', 9 => 'Nine',
        10 => 'Ten', 11 => 'Eleven', 12 => 'Twelve', 13 => 'Thirteen',
        14 => 'Fourteen', 15 => 'Fifteen', 16 => 'Sixteen', 17 => 'Seventeen',
        18 => 'Eighteen', 19 => 'Nineteen', 20 => 'Twenty', 30 => 'Thirty',
        40 => 'Forty', 50 => 'Fifty', 60 => 'Sixty', 70 => 'Seventy',
        80 => 'Eighty', 90 => 'Ninety'
    ];
    
    // Convert whole number
    if ($whole == 0) {
        $words = 'Zero';
    } else {
        $words = convert_whole_number($whole);
    }
    
    // Add currency name
    $words .= ' TShillings';
    
    // Add fraction if exists
    if ($fraction > 0) {
        $words .= ' and ' . convert_whole_number($fraction) . ' Cents';
    }
    
    return $words . ' Only';
}

function convert_whole_number($num) {
    if ($num == 0) {
        return '';
    }
    
    $dictionary = [
        0 => '', 1 => 'One', 2 => 'Two', 3 => 'Three', 4 => 'Four',
        5 => 'Five', 6 => 'Six', 7 => 'Seven', 8 => 'Eight', 9 => 'Nine',
        10 => 'Ten', 11 => 'Eleven', 12 => 'Twelve', 13 => 'Thirteen',
        14 => 'Fourteen', 15 => 'Fifteen', 16 => 'Sixteen', 17 => 'Seventeen',
        18 => 'Eighteen', 19 => 'Nineteen', 20 => 'Twenty', 30 => 'Thirty',
        40 => 'Forty', 50 => 'Fifty', 60 => 'Sixty', 70 => 'Seventy',
        80 => 'Eighty', 90 => 'Ninety'
    ];
    
    if ($num < 21) {
        return $dictionary[$num];
    }
    
    if ($num < 100) {
        $tens = floor($num / 10) * 10;
        $units = $num % 10;
        return $dictionary[$tens] . ($units > 0 ? '-' . $dictionary[$units] : '');
    }
    
    if ($num < 1000) {
        $hundreds = floor($num / 100);
        $remainder = $num % 100;
        return $dictionary[$hundreds] . ' Hundred' . ($remainder > 0 ? ' ' . convert_whole_number($remainder) : '');
    }
    
    if ($num < 100000) {
        $thousands = floor($num / 1000);
        $remainder = $num % 1000;
        return convert_whole_number($thousands) . ' Thousand' . ($remainder > 0 ? ' ' . convert_whole_number($remainder) : '');
    }
    
    if ($num < 10000000) {
        $millions = floor($num / 100000);
        $remainder = $num % 100000;
        return convert_whole_number($millions) . ' Lakh' . ($remainder > 0 ? ' ' . convert_whole_number($remainder) : '');
    }
    
    return 'Very Large Amount';
}

// Format date function


// Generate PDF function
function generatePDFReceipt($receipt) {
    // Create new PDF document
    $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
    
    // Set document information
    $pdf->SetCreator('Finance System');
    $pdf->SetAuthor($receipt['company_name'] ?? 'Stock Broker');
    $pdf->SetTitle('Receipt ' . $receipt['receipt_no']);
    $pdf->SetSubject('Official Receipt');
    $pdf->SetKeywords('Receipt, Finance, Payment');
    
    // Remove default header/footer
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    
    // Set margins
    $pdf->SetMargins(15, 15, 15);
    $pdf->SetAutoPageBreak(TRUE, 15);
    
    // Add a page
    $pdf->AddPage();
    
    // Set font
    $pdf->SetFont('helvetica', '', 10);
    
    // Get receipt data
    $receipt_date = format_date($receipt['receipt_date']);
    $created_date = format_date($receipt['created_at'] ?? date('Y-m-d H:i:s'), 'F d, Y H:i:s');
    $amount_in_words = convert_number_to_words($receipt['amount']);
    
    // Format currency
    $currency_symbols = [
        'USD' => '$',
        'Ksh' => 'KSh',
        'UGsh' => 'UGX',
        'Tsh' => 'TSh'
    ];
    $currency_symbol = $currency_symbols[$receipt['currency']] ?? $receipt['currency'] . ' ';
    $formatted_amount = $currency_symbol . number_format($receipt['amount'], 2);
    
    // Start HTML content
    $html = '
    <style>
        body { font-family: helvetica, Arial, sans-serif; font-size: 10pt; }
        .text-center { text-align: center; }
        .text-right { text-align: right; }
        .text-bold { font-weight: bold; }
        .text-underline { text-decoration: underline; }
        .company-name { font-size: 14pt; font-weight: bold; text-transform: uppercase; }
        .company-details { font-size: 9pt; line-height: 1.3; }
        .receipt-title { font-size: 16pt; font-weight: bold; margin: 10px 0; text-decoration: underline; }
        .table-details { width: 100%; border-collapse: collapse; margin: 10px 0; }
        .table-details td { padding: 3px 5px; vertical-align: top; }
        .label { font-weight: bold; width: 120px; }
        .amount-box { border: 2px solid #000; padding: 10px; margin: 15px 0; text-align: center; }
        .amount-number { font-size: 14pt; font-weight: bold; margin: 5px 0; }
        .amount-words { font-size: 9pt; font-style: italic; padding: 8px; border: 1px solid #ccc; background: #f9f9f9; margin: 10px 0; }
        .description-box { border: 1px solid #000; padding: 10px; margin: 10px 0; min-height: 50px; }
        .signature-section { margin-top: 40px; }
        .signature-line { border-top: 1px solid #000; width: 200px; margin: 30px auto 5px; }
        .stamp { display: inline-block; border: 2px solid #f00; border-radius: 50%; width: 70px; height: 70px; line-height: 70px; text-align: center; font-weight: bold; color: #f00; transform: rotate(-15deg); }
        .divider { border-top: 1px solid #000; margin: 10px 0; }
        .footer { font-size: 8pt; color: #666; margin-top: 20px; text-align: center; }
    </style>
    
    <div class="text-center">
        <!-- Company Header -->
        <div class="company-name">' . htmlspecialchars($receipt['company_name'] ?? 'STOCK BROKER') . '</div>
        <div class="company-details">
            TZS<br>
            P.O Box 675, Dar es Salaam, Tanzania<br>
            Tel: 0767676767 | Mob: +255769296960 | Email: info@vfsl.co.tz
        </div>
        
        <!-- Receipt Title -->
        <div class="receipt-title">OFFICIAL RECEIPT</div>
        
        <div class="divider"></div>
        
        <!-- Receipt Details Table -->
        <table class="table-details">
            <tr>
                <td class="label">CDS ACCOUNT:</td>
                <td>' . htmlspecialchars($receipt['cds_account'] ?? 'N/A') . '</td>
                <td class="label">RECEIPT NUMBER:</td>
                <td>' . htmlspecialchars($receipt['receipt_no']) . '</td>
            </tr>
            <tr>
                <td class="label">ACCOUNT NO:</td>
                <td>' . htmlspecialchars($receipt['account_no'] ?? 'N/A') . '</td>
                <td class="label">RECEIPT DATE:</td>
                <td>' . $receipt_date . '</td>
            </tr>
            <tr>
                <td class="label">ACCOUNT NAME:</td>
                <td>' . htmlspecialchars($receipt['name']) . '</td>
                <td class="label">PAYMENT MODE:</td>
                <td>' . htmlspecialchars($receipt['payment_method_desc'] ?? 'N/A') . '</td>
            </tr>';
    
    // Add cheque number if exists
    if (!empty($receipt['cheque_no'])) {
        $html .= '
            <tr>
                <td class="label">CHEQUE NUMBER:</td>
                <td colspan="3">' . htmlspecialchars($receipt['cheque_no']) . '</td>
            </tr>';
    }
    
    $html .= '
        </table>
        
        <div class="divider"></div>
        
        <!-- Amount Section -->
        <div class="amount-box">
            <div class="text-bold">RECEIPT AMOUNT:</div>
            <div class="amount-number">' . $formatted_amount . '</div>
            
            <div class="text-bold" style="margin-top: 15px;">AMOUNT IN WORDS</div>
            <div class="amount-words">' . strtoupper($amount_in_words) . '</div>
        </div>
        
        <div class="divider"></div>';
    
    // Add description if exists
    if (!empty($receipt['narration'])) {
        $html .= '
        <!-- Description -->
        <div>
            <div class="text-bold">IN RESPECT OF:</div>
            <div class="description-box">' . nl2br(htmlspecialchars($receipt['narration'])) . '</div>
        </div>
        
        <div class="divider"></div>';
    }
    
    $html .= '
        <!-- Signatures -->
        <div class="signature-section">
            <table width="100%">
                <tr>
                    <td width="50%" class="text-center">
                        <div class="text-bold">RECEIPTED BY:</div>
                        <div class="signature-line"></div>
                        <div style="margin-top: 5px; font-weight: bold;">' . htmlspecialchars($receipt['generated_by_name'] ?? $receipt['generated_by_username'] ?? 'Finance Officer') . '</div>
                        <div>Finance Officer</div>
                    </td>
                    <td width="50%" class="text-center">
                        <div class="text-bold">AUTHORIZED BY</div>
                        <div class="signature-line"></div>
                        <div style="margin-top: 5px; font-weight: bold;">Authorized Signature</div>
                        <div>Affix Stamp</div>
                    </td>
                </tr>
            </table>
        </div>
        
        <!-- Stamp Area -->
        <div class="text-center" style="margin-top: 20px;">
            <div class="stamp">
                PAID<br>
                ✓
            </div>
        </div>
        
        <!-- Footer -->
        <div class="footer">
            <div>Generated on: ' . $created_date . '</div>
            <div>Receipt ID: ' . ($receipt['id'] ?? 'N/A') . ' | This is a computer-generated receipt</div>
        </div>
    </div>';
    
    // Write HTML content to PDF
    $pdf->writeHTML($html, true, false, true, false, '');
    
    // Close and output PDF document
    return $pdf->Output('', 'S'); // Return as string
}

// Check if we should output HTML preview or PDF
$output_format = isset($_GET['format']) ? $_GET['format'] : 'pdf';

if ($output_format === 'html') {
    // Output HTML version for preview
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Receipt <?php echo htmlspecialchars($receipt['receipt_no']); ?> - Preview</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
        <style>
            @media print {
                .no-print { display: none !important; }
                body { background: white !important; }
                .preview-container { 
                    max-width: 210mm; 
                    margin: 0 auto; 
                    padding: 20px;
                    box-shadow: none !important;
                    border: none !important;
                }
            }
            .preview-container { 
                max-width: 210mm; 
                margin: 20px auto; 
                padding: 20px;
                border: 1px solid #dee2e6;
                box-shadow: 0 0 10px rgba(0,0,0,0.1);
                background: white;
            }
            .receipt-content {
                font-family: Arial, sans-serif;
                font-size: 10pt;
            }
            .company-name { 
                font-size: 14pt; 
                font-weight: bold; 
                text-align: center;
                text-transform: uppercase;
                margin-bottom: 5px;
            }
            .company-details { 
                font-size: 9pt; 
                text-align: center;
                line-height: 1.3;
                margin-bottom: 15px;
            }
            .receipt-title { 
                font-size: 16pt; 
                font-weight: bold; 
                text-align: center; 
                margin: 10px 0; 
                text-decoration: underline;
            }
            .table-details { width: 100%; border-collapse: collapse; margin: 10px 0; }
            .table-details td { padding: 3px 5px; vertical-align: top; }
            .label { font-weight: bold; width: 120px; }
            .amount-box { border: 2px solid #000; padding: 10px; margin: 15px 0; text-align: center; }
            .amount-number { font-size: 14pt; font-weight: bold; margin: 5px 0; }
            .amount-words { font-size: 9pt; font-style: italic; padding: 8px; border: 1px solid #ccc; background: #f9f9f9; margin: 10px 0; }
            .description-box { border: 1px solid #000; padding: 10px; margin: 10px 0; min-height: 50px; }
            .signature-section { margin-top: 40px; }
            .signature-line { border-top: 1px solid #000; width: 200px; margin: 30px auto 5px; }
            .stamp { display: inline-block; border: 2px solid #f00; border-radius: 50%; width: 70px; height: 70px; line-height: 70px; text-align: center; font-weight: bold; color: #f00; transform: rotate(-15deg); }
            .divider { border-top: 1px solid #000; margin: 10px 0; }
            .footer { font-size: 8pt; color: #666; margin-top: 20px; text-align: center; }
        </style>
    </head>
    <body>
        <div class="container-fluid no-print">
            <div class="row py-2 bg-light border-bottom">
                <div class="col-12">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <button onclick="window.print()" class="btn btn-primary btn-sm">
                                <i class="bi bi-printer"></i> Print
                            </button>
                            <button onclick="window.close()" class="btn btn-secondary btn-sm">
                                <i class="bi bi-x-lg"></i> Close
                            </button>
                            <a href="?receipt_id=<?php echo $receipt_id; ?>" class="btn btn-success btn-sm">
                                <i class="bi bi-file-pdf"></i> Download PDF
                            </a>
                        </div>
                        <div>
                            <span class="badge bg-info">
                                Receipt: <?php echo htmlspecialchars($receipt['receipt_no']); ?>
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="preview-container">
            <div class="receipt-content">
                <!-- Company Header -->
                <div class="text-center">
                    <div class="company-name"><?php echo htmlspecialchars($receipt['company_name'] ?? 'STOCK BROKER'); ?></div>
                    <div class="company-details">
                        TZS<br>
                        P.O Box 675, Dar es Salaam, Tanzania<br>
                        Tel: 0767676767 | Mob: +255769296960 | Email: info@vfsl.co.tz
                    </div>
                    
                    <!-- Receipt Title -->
                    <div class="receipt-title">OFFICIAL RECEIPT</div>
                    
                    <div class="divider"></div>
                    
                    <!-- Receipt Details Table -->
                    <table class="table-details">
                        <tr>
                            <td class="label">CDS ACCOUNT:</td>
                            <td><?php echo htmlspecialchars($receipt['cds_account'] ?? 'N/A'); ?></td>
                            <td class="label">RECEIPT NUMBER:</td>
                            <td><?php echo htmlspecialchars($receipt['receipt_no']); ?></td>
                        </tr>
                        <tr>
                            <td class="label">ACCOUNT NO:</td>
                            <td><?php echo htmlspecialchars($receipt['account_no'] ?? 'N/A'); ?></td>
                            <td class="label">RECEIPT DATE:</td>
                            <td><?php echo format_date($receipt['receipt_date']); ?></td>
                        </tr>
                        <tr>
                            <td class="label">ACCOUNT NAME:</td>
                            <td><?php echo htmlspecialchars($receipt['name']); ?></td>
                            <td class="label">PAYMENT MODE:</td>
                            <td><?php echo htmlspecialchars($receipt['payment_method_desc'] ?? 'N/A'); ?></td>
                        </tr>
                        <?php if (!empty($receipt['cheque_no'])): ?>
                        <tr>
                            <td class="label">CHEQUE NUMBER:</td>
                            <td colspan="3"><?php echo htmlspecialchars($receipt['cheque_no']); ?></td>
                        </tr>
                        <?php endif; ?>
                    </table>
                    
                    <div class="divider"></div>
                    
                    <!-- Amount Section -->
                    <div class="amount-box">
                        <div class="text-bold">RECEIPT AMOUNT:</div>
                        <div class="amount-number">
                            <?php 
                            $currency_symbols = [
                                'USD' => '$',
                                'Ksh' => 'KSh',
                                'UGsh' => 'UGX',
                                'Tsh' => 'TSh'
                            ];
                            $currency_symbol = $currency_symbols[$receipt['currency']] ?? $receipt['currency'] . ' ';
                            echo $currency_symbol . number_format($receipt['amount'], 2);
                            ?>
                        </div>
                        
                        <div class="text-bold" style="margin-top: 15px;">AMOUNT IN WORDS</div>
                        <div class="amount-words"><?php echo strtoupper(convert_number_to_words($receipt['amount'])); ?></div>
                    </div>
                    
                    <div class="divider"></div>
                    
                    <?php if (!empty($receipt['narration'])): ?>
                    <!-- Description -->
                    <div>
                        <div class="text-bold">IN RESPECT OF:</div>
                        <div class="description-box"><?php echo nl2br(htmlspecialchars($receipt['narration'])); ?></div>
                    </div>
                    
                    <div class="divider"></div>
                    <?php endif; ?>
                    
                    <!-- Signatures -->
                    <div class="signature-section">
                        <table width="100%">
                            <tr>
                                <td width="50%" class="text-center">
                                    <div class="text-bold">RECEIPTED BY:</div>
                                    <div class="signature-line"></div>
                                    <div style="margin-top: 5px; font-weight: bold;">
                                        <?php echo htmlspecialchars($receipt['generated_by_name'] ?? $receipt['generated_by_username'] ?? 'Finance Officer'); ?>
                                    </div>
                                    <div>Finance Officer</div>
                                </td>
                                <td width="50%" class="text-center">
                                    <div class="text-bold">AUTHORIZED BY</div>
                                    <div class="signature-line"></div>
                                    <div style="margin-top: 5px; font-weight: bold;">Authorized Signature</div>
                                    <div>Affix Stamp</div>
                                </td>
                            </tr>
                        </table>
                    </div>
                    
                    <!-- Stamp Area -->
                    <div class="text-center" style="margin-top: 20px;">
                        <div class="stamp">
                            PAID<br>
                            ✓
                        </div>
                    </div>
                    
                    <!-- Footer -->
                    <div class="footer">
                        <div>Generated on: <?php echo format_date($receipt['created_at'] ?? date('Y-m-d H:i:s'), 'F d, Y H:i:s'); ?></div>
                        <div>Receipt ID: <?php echo $receipt['id'] ?? 'N/A'; ?> | This is a computer-generated receipt</div>
                    </div>
                </div>
            </div>
        </div>
        
        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
        <script>
            // Auto-print if specified
            if (window.location.search.includes('autoprint=true')) {
                setTimeout(function() {
                    window.print();
                }, 500);
            }
            
            // Keyboard shortcuts
            document.addEventListener('keydown', function(e) {
                // Ctrl+P or Cmd+P for print
                if ((e.ctrlKey || e.metaKey) && e.key === 'p') {
                    e.preventDefault();
                    window.print();
                }
                // Escape to close
                if (e.key === 'Escape') {
                    window.close();
                }
            });
        </script>
    </body>
    </html>
    <?php
} else {
    // Generate and output PDF (default)
    try {
        $pdf_content = generatePDFReceipt($receipt);
        
        // Set headers for PDF download
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="receipt_' . $receipt['receipt_no'] . '.pdf"');
        header('Content-Length: ' . strlen($pdf_content));
        header('Cache-Control: private, max-age=0, must-revalidate');
        header('Pragma: public');
        header('Expires: 0');
        
        // Output PDF
        echo $pdf_content;
        exit;
        
    } catch (Exception $e) {
        // If PDF generation fails, show error
        header('Content-Type: text/html');
        echo '<div style="padding:20px;color:red;font-family:Arial;">';
        echo '<h3>PDF Generation Error</h3>';
        echo '<p><strong>Error:</strong> ' . htmlspecialchars($e->getMessage()) . '</p>';
        echo '<p><strong>TCPDF Path:</strong> ' . realpath('../TCPDF/tcpdf.php') . '</p>';
        echo '<p><strong>File Exists:</strong> ' . (file_exists('../TCPDF/tcpdf.php') ? 'Yes' : 'No') . '</p>';
        echo '<p><a href="?receipt_id=' . $receipt_id . '&format=html">Click here for HTML preview</a></p>';
        echo '</div>';
    }
}
?>