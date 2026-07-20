<?php
require_once '../config/config.php';
require_once '../auth/auth_middleware.php';

// Security headers
header("X-Frame-Options: DENY");
header("X-Content-Type-Options: nosniff");
header("X-XSS-Protection: 1; mode=block");
header("Referrer-Policy: strict-origin-when-cross-origin");
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; font-src 'self' https://cdn.jsdelivr.net");

require_finance_officer();

$db = getDBConnection();

// Input validation and sanitization
function validateDate($date, $format = 'Y-m-d') {
    $d = DateTime::createFromFormat($format, $date);
    return $d && $d->format($format) === $date;
}

function sanitizeInput($input) {
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}

// ========== EXPORT FUNCTIONALITY ==========
if (isset($_GET['export'])) {
    $export_type = $_GET['export'];
    $start_date = $_GET['start_date'] ?? date('Y-01-01');
    $end_date = $_GET['end_date'] ?? date('Y-m-d');
    
    // Validate dates
    if (!validateDate($start_date) || !validateDate($end_date)) {
        die('Invalid date format');
    }
    
    // CSRF validation for export
    if (!isset($_GET['csrf_token']) || $_GET['csrf_token'] !== $_SESSION['csrf_token']) {
        die('Invalid CSRF token');
    }
    
    // Get company information
    $company_stmt = $db->prepare("SELECT * FROM companies WHERE status = 'active' ORDER BY id ASC LIMIT 1");
    $company_stmt->execute();
    $company = $company_stmt->fetch(PDO::FETCH_ASSOC) ?: [
        'company_name' => 'NEOVAM',
        'registration_number' => '',
        'address' => 'P.O Box, Dar es Salaam, Tanzania',
        'currency' => 'TZS'
    ];
    
    // Get data for export
    $categories = getIncomeStatementCategories($db, $start_date, $end_date);
    $totals = calculateTotals($categories);
    
    switch ($export_type) {
        case 'pdf':
            exportToPDF($company, $start_date, $end_date, $categories, $totals);
            break;
        case 'excel':
            exportToExcel($company, $start_date, $end_date, $categories, $totals);
            break;
        case 'csv':
            exportToCSV($company, $start_date, $end_date, $categories, $totals);
            break;
    }
}

// ========== EXPORT FUNCTIONS ==========
function exportToPDF($company, $start_date, $end_date, $categories, $totals) {
    require_once('../tcpdf/tcpdf.php');
    
    // Create new PDF document
    $pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, 'A4', true, 'UTF-8', false);
    
    // Set document information
    $pdf->SetCreator('Financial System');
    $pdf->SetAuthor($company['company_name']);
    $pdf->SetTitle('Income Statement');
    $pdf->SetSubject('Profit and Loss Statement');
    
    // Remove default header/footer
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    
    // Set margins
    $pdf->SetMargins(15, 15, 15);
    $pdf->SetAutoPageBreak(TRUE, 15);
    
    // Add a page
    $pdf->AddPage();
    
    // Company Header
    $pdf->SetFont('helvetica', 'B', 16);
    $pdf->Cell(0, 10, 'INCOME STATEMENT', 0, 1, 'C');
    $pdf->SetFont('helvetica', '', 11);
    $pdf->Cell(0, 6, '(' . $company['company_name'] . ')', 0, 1, 'C');
    $pdf->Cell(0, 6, 'For the Period Ended ' . date('F d, Y', strtotime($end_date)), 0, 1, 'C');
    $pdf->Cell(0, 6, '(In ' . $company['currency'] . ')', 0, 1, 'C');
    $pdf->Ln(8);
    
    // Table Header
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->Cell(140, 7, 'DESCRIPTION', 1, 0, 'L');
    $pdf->Cell(40, 7, 'AMOUNT', 1, 1, 'R');
    
    // Revenue
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->Cell(140, 6, 'REVENUE', 1, 0, 'L');
    $pdf->Cell(40, 6, '', 1, 1, 'R');
    
    $pdf->SetFont('helvetica', '', 9);
    foreach ($categories['revenue'] as $item) {
        $pdf->Cell(5, 5, '', 0, 0);
        $pdf->Cell(135, 5, $item['account_name'], 0, 0);
        $pdf->Cell(40, 5, number_format($item['net_amount'], 2), 0, 1, 'R');
    }
    
    $pdf->SetFont('helvetica', 'B', 9);
    $pdf->Cell(140, 6, 'Total Revenue', 0, 0, 'R');
    $pdf->Cell(40, 6, number_format($totals['revenue'], 2), 0, 1, 'R');
    
    $pdf->Ln(3);
    
    // Operating Expenses
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->Cell(140, 6, 'OPERATING EXPENSES', 1, 0, 'L');
    $pdf->Cell(40, 6, '', 1, 1, 'R');
    
    $pdf->SetFont('helvetica', '', 9);
    foreach ($categories['operating_expenses'] as $item) {
        $pdf->Cell(5, 5, '', 0, 0);
        $pdf->Cell(135, 5, $item['account_name'], 0, 0);
        $pdf->Cell(40, 5, '(' . number_format($item['net_amount'], 2) . ')', 0, 1, 'R');
    }
    
    $pdf->SetFont('helvetica', 'B', 9);
    $pdf->Cell(140, 6, 'Total Operating Expenses', 0, 0, 'R');
    $pdf->Cell(40, 6, '(' . number_format($totals['operating_expenses'], 2) . ')', 0, 1, 'R');
    
    $pdf->Ln(3);
    
    // Operating Profit
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->Cell(140, 6, 'OPERATING PROFIT', 0, 0, 'R');
    $pdf->Cell(40, 6, number_format($totals['operating_profit'], 2), 0, 1, 'R');
    
    $pdf->Ln(3);
    
    // Other Income
    if (!empty($categories['other_income'])) {
        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->Cell(140, 6, 'OTHER INCOME', 1, 0, 'L');
        $pdf->Cell(40, 6, '', 1, 1, 'R');
        
        $pdf->SetFont('helvetica', '', 9);
        foreach ($categories['other_income'] as $item) {
            $pdf->Cell(5, 5, '', 0, 0);
            $pdf->Cell(135, 5, $item['account_name'], 0, 0);
            $pdf->Cell(40, 5, number_format($item['net_amount'], 2), 0, 1, 'R');
        }
        
        $pdf->SetFont('helvetica', 'B', 9);
        $pdf->Cell(140, 6, 'Total Other Income', 0, 0, 'R');
        $pdf->Cell(40, 6, number_format($totals['other_income'], 2), 0, 1, 'R');
        
        $pdf->Ln(3);
    }
    
    // Finance Costs
    if (!empty($categories['finance_costs'])) {
        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->Cell(140, 6, 'FINANCE COSTS', 1, 0, 'L');
        $pdf->Cell(40, 6, '', 1, 1, 'R');
        
        $pdf->SetFont('helvetica', '', 9);
        foreach ($categories['finance_costs'] as $item) {
            $pdf->Cell(5, 5, '', 0, 0);
            $pdf->Cell(135, 5, $item['account_name'], 0, 0);
            $pdf->Cell(40, 5, '(' . number_format($item['net_amount'], 2) . ')', 0, 1, 'R');
        }
        
        $pdf->SetFont('helvetica', 'B', 9);
        $pdf->Cell(140, 6, 'Total Finance Costs', 0, 0, 'R');
        $pdf->Cell(40, 6, '(' . number_format($totals['finance_costs'], 2) . ')', 0, 1, 'R');
        
        $pdf->Ln(3);
    }
    
    // Profit Before Tax
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->Cell(140, 6, 'PROFIT BEFORE TAX', 0, 0, 'R');
    $pdf->Cell(40, 6, number_format($totals['profit_before_tax'], 2), 0, 1, 'R');
    
    $pdf->Ln(3);
    
    // Tax Expense
    if (!empty($categories['tax_expenses'])) {
        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->Cell(140, 6, 'TAX EXPENSE', 1, 0, 'L');
        $pdf->Cell(40, 6, '', 1, 1, 'R');
        
        $pdf->SetFont('helvetica', '', 9);
        foreach ($categories['tax_expenses'] as $item) {
            $pdf->Cell(5, 5, '', 0, 0);
            $pdf->Cell(135, 5, $item['account_name'], 0, 0);
            $pdf->Cell(40, 5, '(' . number_format($item['net_amount'], 2) . ')', 0, 1, 'R');
        }
        
        $pdf->SetFont('helvetica', 'B', 9);
        $pdf->Cell(140, 6, 'Total Tax Expense', 0, 0, 'R');
        $pdf->Cell(40, 6, '(' . number_format($totals['tax_expenses'], 2) . ')', 0, 1, 'R');
        
        $pdf->Ln(3);
    }
    
    // Net Income
    $pdf->SetFont('helvetica', 'B', 11);
    $pdf->SetFillColor(0, 100, 0);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->Cell(140, 8, 'NET INCOME AFTER TAX', 1, 0, 'C', true);
    $pdf->Cell(40, 8, number_format($totals['net_income'], 2), 1, 1, 'R', true);
    
    // Footer
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetFont('helvetica', 'I', 8);
    $pdf->Ln(8);
    $pdf->Cell(0, 5, 'Generated on: ' . date('Y-m-d H:i:s'), 0, 1);
    $pdf->Cell(0, 5, 'Generated by: ' . $_SESSION['username'], 0, 1);
    
    // Output PDF
    $pdf->Output('Income_Statement_' . date('Y-m-d') . '.pdf', 'I');
    exit;
}

function exportToExcel($company, $start_date, $end_date, $categories, $totals) {
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment;filename="Income_Statement_' . date('Y-m-d') . '.xls"');
    header('Cache-Control: max-age=0');
    
    echo "<!DOCTYPE html>
    <html>
    <head>
        <meta charset='UTF-8'>
        <style>
            body { font-family: Arial, sans-serif; }
            table { border-collapse: collapse; width: 100%; margin-top: 20px; }
            th, td { border: 1px solid #000; padding: 8px; }
            .header { text-align: center; font-weight: bold; font-size: 16px; }
            .total { font-weight: bold; background-color: #f0f0f0; }
            .subtotal { font-weight: bold; }
            .indent { padding-left: 20px !important; }
            .income { color: green; }
            .expense { color: red; }
        </style>
    </head>
    <body>";
    
    echo "<h3 class='header'>INCOME STATEMENT</h3>";
    echo "<h4 class='header'>" . htmlspecialchars($company['company_name']) . "</h4>";
    echo "<p class='header'>For the Period Ended " . date('F d, Y', strtotime($end_date)) . "</p>";
    echo "<p class='header'>(In " . htmlspecialchars($company['currency']) . ")</p>";
    echo "<br>";
    
    echo "<table>
        <tr>
            <th width='70%'>DESCRIPTION</th>
            <th width='30%'>AMOUNT</th>
        </tr>
        <tr class='subtotal'>
            <td colspan='2'><strong>REVENUE</strong></td>
        </tr>";
    
    foreach ($categories['revenue'] as $item) {
        echo "<tr>
            <td class='indent'>" . htmlspecialchars($item['account_name']) . "</td>
            <td class='income'>" . number_format($item['net_amount'], 2) . "</td>
        </tr>";
    }
    
    echo "<tr class='total'>
            <td align='right'><strong>Total Revenue</strong></td>
            <td><strong>" . number_format($totals['revenue'], 2) . "</strong></td>
        </tr>
        <tr class='subtotal'>
            <td colspan='2'><strong>OPERATING EXPENSES</strong></td>
        </tr>";
    
    foreach ($categories['operating_expenses'] as $item) {
        echo "<tr>
            <td class='indent'>" . htmlspecialchars($item['account_name']) . "</td>
            <td class='expense'>(" . number_format($item['net_amount'], 2) . ")</td>
        </tr>";
    }
    
    echo "<tr class='total'>
            <td align='right'><strong>Total Operating Expenses</strong></td>
            <td><strong>(" . number_format($totals['operating_expenses'], 2) . ")</strong></td>
        </tr>
        <tr class='subtotal'>
            <td align='right'><strong>OPERATING PROFIT</strong></td>
            <td><strong>" . number_format($totals['operating_profit'], 2) . "</strong></td>
        </tr>";
    
    if (!empty($categories['other_income'])) {
        echo "<tr class='subtotal'>
                <td colspan='2'><strong>OTHER INCOME</strong></td>
            </tr>";
        
        foreach ($categories['other_income'] as $item) {
            echo "<tr>
                <td class='indent'>" . htmlspecialchars($item['account_name']) . "</td>
                <td class='income'>" . number_format($item['net_amount'], 2) . "</td>
            </tr>";
        }
        
        echo "<tr class='total'>
                <td align='right'><strong>Total Other Income</strong></td>
                <td><strong>" . number_format($totals['other_income'], 2) . "</strong></td>
            </tr>";
    }
    
    if (!empty($categories['finance_costs'])) {
        echo "<tr class='subtotal'>
                <td colspan='2'><strong>FINANCE COSTS</strong></td>
            </tr>";
        
        foreach ($categories['finance_costs'] as $item) {
            echo "<tr>
                <td class='indent'>" . htmlspecialchars($item['account_name']) . "</td>
                <td class='expense'>(" . number_format($item['net_amount'], 2) . ")</td>
            </tr>";
        }
        
        echo "<tr class='total'>
                <td align='right'><strong>Total Finance Costs</strong></td>
                <td><strong>(" . number_format($totals['finance_costs'], 2) . ")</strong></td>
            </tr>";
    }
    
    echo "<tr class='subtotal'>
            <td align='right'><strong>PROFIT BEFORE TAX</strong></td>
            <td><strong>" . number_format($totals['profit_before_tax'], 2) . "</strong></td>
        </tr>";
    
    if (!empty($categories['tax_expenses'])) {
        echo "<tr class='subtotal'>
                <td colspan='2'><strong>TAX EXPENSE</strong></td>
            </tr>";
        
        foreach ($categories['tax_expenses'] as $item) {
            echo "<tr>
                <td class='indent'>" . htmlspecialchars($item['account_name']) . "</td>
                <td class='expense'>(" . number_format($item['net_amount'], 2) . ")</td>
            </tr>";
        }
        
        echo "<tr class='total'>
                <td align='right'><strong>Total Tax Expense</strong></td>
                <td><strong>(" . number_format($totals['tax_expenses'], 2) . ")</strong></td>
            </tr>";
    }
    
    $net_income_class = $totals['net_income'] >= 0 ? 'income' : 'expense';
    echo "<tr class='total' style='background-color: #e8f5e8;'>
            <td align='right'><strong>NET INCOME AFTER TAX</strong></td>
            <td class='" . $net_income_class . "'><strong>" . number_format($totals['net_income'], 2) . "</strong></td>
        </tr>
        <tr><td colspan='2'>&nbsp;</td></tr>
        <tr>
            <td colspan='2'><em>Generated on: " . date('Y-m-d H:i:s') . "</em></td>
        </tr>
        <tr>
            <td colspan='2'><em>Generated by: " . htmlspecialchars($_SESSION['username']) . "</em></td>
        </tr>
    </table>";
    
    echo "</body></html>";
    exit;
}

function exportToCSV($company, $start_date, $end_date, $categories, $totals) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment;filename="Income_Statement_' . date('Y-m-d') . '.csv"');
    
    $output = fopen('php://output', 'w');
    
    // Header
    fputcsv($output, ['INCOME STATEMENT']);
    fputcsv($output, [$company['company_name']]);
    fputcsv($output, ['For the Period Ended ' . date('F d, Y', strtotime($end_date))]);
    fputcsv($output, ['(In ' . $company['currency'] . ')']);
    fputcsv($output, []);
    fputcsv($output, ['DESCRIPTION', 'AMOUNT']);
    
    // Revenue
    fputcsv($output, ['REVENUE', '']);
    foreach ($categories['revenue'] as $item) {
        fputcsv($output, ['  ' . $item['account_name'], $item['net_amount']]);
    }
    fputcsv($output, ['Total Revenue', $totals['revenue']]);
    fputcsv($output, []);
    
    // Operating Expenses
    fputcsv($output, ['OPERATING EXPENSES', '']);
    foreach ($categories['operating_expenses'] as $item) {
        fputcsv($output, ['  ' . $item['account_name'], '-' . $item['net_amount']]);
    }
    fputcsv($output, ['Total Operating Expenses', '-' . $totals['operating_expenses']]);
    fputcsv($output, []);
    fputcsv($output, ['OPERATING PROFIT', $totals['operating_profit']]);
    fputcsv($output, []);
    
    // Other Income
    if (!empty($categories['other_income'])) {
        fputcsv($output, ['OTHER INCOME', '']);
        foreach ($categories['other_income'] as $item) {
            fputcsv($output, ['  ' . $item['account_name'], $item['net_amount']]);
        }
        fputcsv($output, ['Total Other Income', $totals['other_income']]);
        fputcsv($output, []);
    }
    
    // Finance Costs
    if (!empty($categories['finance_costs'])) {
        fputcsv($output, ['FINANCE COSTS', '']);
        foreach ($categories['finance_costs'] as $item) {
            fputcsv($output, ['  ' . $item['account_name'], '-' . $item['net_amount']]);
        }
        fputcsv($output, ['Total Finance Costs', '-' . $totals['finance_costs']]);
        fputcsv($output, []);
    }
    
    // Profit Before Tax
    fputcsv($output, ['PROFIT BEFORE TAX', $totals['profit_before_tax']]);
    fputcsv($output, []);
    
    // Tax Expense
    if (!empty($categories['tax_expenses'])) {
        fputcsv($output, ['TAX EXPENSE', '']);
        foreach ($categories['tax_expenses'] as $item) {
            fputcsv($output, ['  ' . $item['account_name'], '-' . $item['net_amount']]);
        }
        fputcsv($output, ['Total Tax Expense', '-' . $totals['tax_expenses']]);
        fputcsv($output, []);
    }
    
    // Net Income
    fputcsv($output, ['NET INCOME AFTER TAX', $totals['net_income']]);
    
    fputcsv($output, []);
    fputcsv($output, ['Generated on:', date('Y-m-d H:i:s')]);
    fputcsv($output, ['Generated by:', $_SESSION['username']]);
    
    fclose($output);
    exit;
}

// ========== DATA FETCHING FUNCTIONS ==========
function getIncomeStatementCategories($db, $start_date, $end_date) {
    $categories = [
        'revenue' => [],          // Account codes 411-414
        'other_income' => [],     // Account codes 421-424
        'operating_expenses' => [], // Account codes 51, 52, 53, 56
        'finance_costs' => [],    // Account codes 54
        'tax_expenses' => []      // Account codes 55
    ];
    
    // Get all income and expense accounts from general_ledger joined with chart_of_accounts
    $stmt = $db->prepare("
        SELECT 
            gl.account_code,
            coa.account_name,
            coa.account_type,
            SUM(
                CASE 
                    WHEN coa.account_type = 'income' THEN gl.credit - gl.debit
                    WHEN coa.account_type = 'expense' THEN gl.debit - gl.credit
                    ELSE 0
                END
            ) as net_amount
        FROM general_ledger gl
        JOIN chart_of_accounts coa ON gl.account_code = coa.account_code
        WHERE gl.transaction_date BETWEEN ? AND ?
        AND coa.is_active = 1
        AND coa.account_type IN ('income', 'expense')
        GROUP BY gl.account_code, coa.account_name, coa.account_type
        HAVING net_amount != 0
        ORDER BY gl.account_code
    ");
    $stmt->execute([$start_date, $end_date]);
    $accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Categorize accounts
    foreach ($accounts as $account) {
        $code = $account['account_code'];
        $net_amount = (float)$account['net_amount'];
        
        // REVENUE (41xx series)
        if (strpos($code, '411') === 0) {
            $categories['revenue'][] = [
                'account_code' => $code,
                'account_name' => 'Brokerage Commission Income',
                'net_amount' => $net_amount
            ];
        } elseif (strpos($code, '412') === 0) {
            $categories['revenue'][] = [
                'account_code' => $code,
                'account_name' => 'Advisory Fees',
                'net_amount' => $net_amount
            ];
        } elseif (strpos($code, '413') === 0) {
            $categories['revenue'][] = [
                'account_code' => $code,
                'account_name' => 'Portfolio Management Fees',
                'net_amount' => $net_amount
            ];
        } elseif (strpos($code, '414') === 0) {
            $categories['revenue'][] = [
                'account_code' => $code,
                'account_name' => 'Custodial Fees',
                'net_amount' => $net_amount
            ];
        }
        
        // OTHER INCOME (42xx series)
        elseif (strpos($code, '421') === 0) {
            $categories['other_income'][] = [
                'account_code' => $code,
                'account_name' => 'Interest Income',
                'net_amount' => $net_amount
            ];
        } elseif (strpos($code, '422') === 0) {
            $categories['other_income'][] = [
                'account_code' => $code,
                'account_name' => 'Dividend Income',
                'net_amount' => $net_amount
            ];
        } elseif (strpos($code, '423') === 0) {
            $categories['other_income'][] = [
                'account_code' => $code,
                'account_name' => 'FX Gain',
                'net_amount' => $net_amount
            ];
        } elseif (strpos($code, '424') === 0) {
            $categories['other_income'][] = [
                'account_code' => $code,
                'account_name' => 'Gain on Disposal of Assets',
                'net_amount' => $net_amount
            ];
        }
        
        // OPERATING EXPENSES (51, 52, 53, 56 series)
        elseif (strpos($code, '511') === 0) {
            $categories['operating_expenses'][] = [
                'account_code' => $code,
                'account_name' => 'Salaries & Wages',
                'net_amount' => $net_amount
            ];
        } elseif (strpos($code, '512') === 0) {
            $categories['operating_expenses'][] = [
                'account_code' => $code,
                'account_name' => 'Staff Benefits',
                'net_amount' => $net_amount
            ];
        } elseif (strpos($code, '521') === 0) {
            $categories['operating_expenses'][] = [
                'account_code' => $code,
                'account_name' => 'Rent & Utilities',
                'net_amount' => $net_amount
            ];
        } elseif (strpos($code, '522') === 0) {
            $categories['operating_expenses'][] = [
                'account_code' => $code,
                'account_name' => 'Professional Fees',
                'net_amount' => $net_amount
            ];
        } elseif (strpos($code, '523') === 0) {
            $categories['operating_expenses'][] = [
                'account_code' => $code,
                'account_name' => 'Compliance & Legal Costs',
                'net_amount' => $net_amount
            ];
        } elseif (strpos($code, '524') === 0) {
            $categories['operating_expenses'][] = [
                'account_code' => $code,
                'account_name' => 'Market Data & Trading Systems',
                'net_amount' => $net_amount
            ];
        } elseif (strpos($code, '53') === 0) {
            $categories['operating_expenses'][] = [
                'account_code' => $code,
                'account_name' => 'Depreciation & Amortization',
                'net_amount' => $net_amount
            ];
        } elseif (strpos($code, '561') === 0) {
            $categories['operating_expenses'][] = [
                'account_code' => $code,
                'account_name' => 'CMSA Fees',
                'net_amount' => $net_amount
            ];
        } elseif (strpos($code, '562') === 0) {
            $categories['operating_expenses'][] = [
                'account_code' => $code,
                'account_name' => 'DSE Fees',
                'net_amount' => $net_amount
            ];
        } elseif (strpos($code, '563') === 0) {
            $categories['operating_expenses'][] = [
                'account_code' => $code,
                'account_name' => 'CSDR Fees',
                'net_amount' => $net_amount
            ];
        } elseif (strpos($code, '564') === 0) {
            $categories['operating_expenses'][] = [
                'account_code' => $code,
                'account_name' => 'Value Retention Fees (VRF)',
                'net_amount' => $net_amount
            ];
        }
        
        // FINANCE COSTS (54 series)
        elseif (strpos($code, '541') === 0) {
            $categories['finance_costs'][] = [
                'account_code' => $code,
                'account_name' => 'Interest Expense',
                'net_amount' => $net_amount
            ];
        } elseif (strpos($code, '542') === 0) {
            $categories['finance_costs'][] = [
                'account_code' => $code,
                'account_name' => 'Lease Interest (IFRS 16)',
                'net_amount' => $net_amount
            ];
        }
        
        // TAX EXPENSES (55 series) - CORRECTLY MAPPED
        elseif (strpos($code, '551') === 0) {
            $categories['tax_expenses'][] = [
                'account_code' => $code,
                'account_name' => 'Current Tax',
                'net_amount' => $net_amount
            ];
        } elseif (strpos($code, '552') === 0) {
            $categories['tax_expenses'][] = [
                'account_code' => $code,
                'account_name' => 'Deferred Tax',
                'net_amount' => $net_amount
            ];
        }
        
        // Handle misc accounts (2242-2248)
        elseif (in_array($code, ['2242', '2243', '2244'])) {
            $account_name = '';
            switch($code) {
                case '2242': $account_name = 'DSE Fees Expense'; break;
                case '2243': $account_name = 'CMSA Fees Expense'; break;
                case '2244': $account_name = 'CSDR Fees Expense'; break;
            }
            $categories['operating_expenses'][] = [
                'account_code' => $code,
                'account_name' => $account_name,
                'net_amount' => $net_amount
            ];
        }
    }
    
    return $categories;
}

function calculateTotals($categories) {
    $totals = [
        'revenue' => 0,
        'other_income' => 0,
        'operating_expenses' => 0,
        'finance_costs' => 0,
        'tax_expenses' => 0
    ];
    
    foreach ($categories['revenue'] as $item) {
        $totals['revenue'] += $item['net_amount'];
    }
    
    foreach ($categories['other_income'] as $item) {
        $totals['other_income'] += $item['net_amount'];
    }
    
    foreach ($categories['operating_expenses'] as $item) {
        $totals['operating_expenses'] += $item['net_amount'];
    }
    
    foreach ($categories['finance_costs'] as $item) {
        $totals['finance_costs'] += $item['net_amount'];
    }
    
    // Tax expenses are correctly deducted
    foreach ($categories['tax_expenses'] as $item) {
        $totals['tax_expenses'] += $item['net_amount'];
    }
    
    $totals['operating_profit'] = $totals['revenue'] - $totals['operating_expenses'];
    $totals['profit_before_tax'] = $totals['operating_profit'] + $totals['other_income'] - $totals['finance_costs'];
    $totals['net_income'] = $totals['profit_before_tax'] - $totals['tax_expenses'];
    
    return $totals;
}

// ========== MAIN PAGE ==========
// Validate and sanitize period parameters
$selected_period = $_GET['period'] ?? 'all_time';
$start_date = $_GET['start_date'] ?? '2020-01-01';
$end_date = $_GET['end_date'] ?? date('Y-m-d');

// Validate date format and range
if (!validateDate($start_date) || !validateDate($end_date)) {
    $start_date = '2020-01-01';
    $end_date = date('Y-m-d');
}

// Ensure end date is not before start date
if (strtotime($end_date) < strtotime($start_date)) {
    $end_date = $start_date;
}

// Get company information from database with prepared statement
$company_stmt = $db->prepare("
    SELECT * FROM companies 
    WHERE status = 'active' 
    ORDER BY id ASC 
    LIMIT 1
");
$company_stmt->execute();
$company = $company_stmt->fetch(PDO::FETCH_ASSOC);

// If no company exists, create default values
if (!$company) {
    $company = [
        'company_name' => 'NEOVAM',
        'registration_number' => '',
        'address' => 'P.O Box, Dar es Salaam, Tanzania',
        'currency' => 'TZS'
    ];
}

// Sanitize company data
foreach ($company as $key => $value) {
    $company[$key] = sanitizeInput($value);
}

// Get data for display
$categories = getIncomeStatementCategories($db, $start_date, $end_date);
$totals = calculateTotals($categories);

// Get all accounts for debug
function getAllTransactionAccounts($db, $start_date, $end_date) {
    $stmt = $db->prepare("
        SELECT 
            gl.account_code,
            gl.account_name,
            coa.account_type,
            COUNT(*) as transaction_count,
            SUM(gl.credit_amount) as total_credits,
            SUM(gl.debit_amount) as total_debits,
            SUM(
                CASE 
                    WHEN coa.account_type = 'income' THEN gl.credit_amount - gl.debit_amount
                    WHEN coa.account_type = 'expense' THEN gl.debit_amount - gl.credit_amount
                    ELSE 0
                END
            ) as net_amount
        FROM general_ledger gl
        JOIN chart_of_accounts coa ON gl.account_code = coa.account_code
        WHERE gl.transaction_date BETWEEN ? AND ?
        AND gl.status = 'active'
        AND coa.account_type IN ('income', 'expense')
        GROUP BY gl.account_code, gl.account_name, coa.account_type
        HAVING total_credits > 0 OR total_debits > 0
        ORDER BY gl.account_code
    ");
    $stmt->execute([$start_date, $end_date]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// CSRF token generation and validation for forms
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

$page_title = 'Income Statement';
include '../includes/header.php';
?>

<div class="container-fluid py-4">
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-header bg-success text-white d-flex justify-content-between align-items-center">
                    <div>
                        <h4 class="mb-0"><i class="bi bi-graph-up-arrow me-2"></i>Income Statement</h4>
                        <p class="mb-0">For Period: <?php echo htmlspecialchars(date('F d, Y', strtotime($start_date)) . ' - ' . date('F d, Y', strtotime($end_date))); ?></p>
                    </div>
                    <div class="btn-group">
                        <button class="btn btn-light btn-sm" data-bs-toggle="modal" data-bs-target="#periodModal">
                            <i class="bi bi-calendar me-1"></i>Period
                        </button>
                        <button type="button" class="btn btn-light btn-sm dropdown-toggle" data-bs-toggle="dropdown">
                            <i class="bi bi-download me-1"></i>Export
                        </button>
                        <ul class="dropdown-menu">
                            <li><a class="dropdown-item" href="?export=pdf&start_date=<?php echo urlencode($start_date); ?>&end_date=<?php echo urlencode($end_date); ?>&csrf_token=<?php echo $csrf_token; ?>">
                                <i class="bi bi-file-pdf me-2"></i>PDF
                            </a></li>
                            <li><a class="dropdown-item" href="?export=excel&start_date=<?php echo urlencode($start_date); ?>&end_date=<?php echo urlencode($end_date); ?>&csrf_token=<?php echo $csrf_token; ?>">
                                <i class="bi bi-file-excel me-2"></i>Excel
                            </a></li>
                            <li><a class="dropdown-item" href="?export=csv&start_date=<?php echo urlencode($start_date); ?>&end_date=<?php echo urlencode($end_date); ?>&csrf_token=<?php echo $csrf_token; ?>">
                                <i class="bi bi-file-text me-2"></i>CSV
                            </a></li>
                        </ul>
                        <a href="javascript:window.print()" class="btn btn-light btn-sm">
                            <i class="bi bi-printer me-1"></i>Print
                        </a>
                        <?php if (!isset($_GET['debug'])): ?>
                        <a href="?debug=1&start_date=<?php echo urlencode($start_date); ?>&end_date=<?php echo urlencode($end_date); ?>" class="btn btn-light btn-sm">
                            <i class="bi bi-bug me-1"></i>Debug
                        </a>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="card-body">
                    <!-- Debug Information -->
                    <?php if (isset($_GET['debug']) && $_GET['debug'] == 1): ?>
                    <div class="alert alert-warning mb-4">
                        <h5>DEBUG INFORMATION</h5>
                        <p>Date Range: <?php echo $start_date; ?> to <?php echo $end_date; ?></p>
                        
                        <?php 
                        $all_transactions = getAllTransactionAccounts($db, $start_date, $end_date);
                        
                        echo '<h6>All transactions found:</h6>';
                        if (empty($all_transactions)) {
                            echo '<p>No transactions found in this date range</p>';
                            
                            $check_stmt = $db->query("SELECT COUNT(*) as total FROM general_ledger WHERE status = 'active'");
                            $total_count = $check_stmt->fetch(PDO::FETCH_ASSOC);
                            echo '<p>Total transactions in database: ' . $total_count['total'] . '</p>';
                            
                            $sample_stmt = $db->query("SELECT * FROM general_ledger WHERE status = 'active' ORDER BY transaction_date DESC LIMIT 5");
                            $samples = $sample_stmt->fetchAll(PDO::FETCH_ASSOC);
                            if (!empty($samples)) {
                                echo '<h6>Sample transactions:</h6>';
                                foreach ($samples as $sample) {
                                    echo $sample['transaction_date'] . ' - ' . 
                                         $sample['account_code'] . ' - ' . $sample['account_name'] . ': ' .
                                         'Credit=' . number_format($sample['credit_amount'], 2) . 
                                         ', Debit=' . number_format($sample['debit_amount'], 2) . '<br>';
                                }
                            }
                        } else {
                            foreach ($all_transactions as $row) {
                                echo $row['account_code'] . ' - ' . $row['account_name'] . ' (' . $row['account_type'] . '): ' .
                                     'Net Amount=' . number_format($row['net_amount'], 2) . '<br>';
                            }
                        }
                        ?>
                        
                        <h6>Categories Found:</h6>
                        <p>Revenue: <?php echo count($categories['revenue']); ?> accounts</p>
                        <p>Other Income: <?php echo count($categories['other_income']); ?> accounts</p>
                        <p>Operating Expenses: <?php echo count($categories['operating_expenses']); ?> accounts</p>
                        <p>Finance Costs: <?php echo count($categories['finance_costs']); ?> accounts</p>
                        <p>Tax Expenses: <?php echo count($categories['tax_expenses']); ?> accounts</p>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Company Header -->
                    <div class="text-center mb-4">
                        <h3><?php echo htmlspecialchars($company['company_name']); ?></h3>
                        <h5>INCOME STATEMENT</h5>
                        <p class="mb-0">For the Period Ended <?php echo htmlspecialchars(date('F d, Y', strtotime($end_date))); ?></p>
                        <p class="text-muted mb-1">
                            Currency: <?php echo htmlspecialchars($company['currency']); ?>
                        </p>
                    </div>

                    <div class="row justify-content-center">
                        <div class="col-lg-8">
                            <!-- REVENUE SECTION -->
                            <div class="card border-0 mb-3">
                                <div class="card-header bg-light">
                                    <h6 class="mb-0 fw-bold text-success">REVENUE</h6>
                                </div>
                                <div class="card-body p-0">
                                    <?php 
                                    if (!empty($categories['revenue'])) {
                                        foreach ($categories['revenue'] as $item) {
                                            echo '<div class="d-flex justify-content-between py-2 px-3 border-bottom">';
                                            echo '<span>' . htmlspecialchars($item['account_name']) . '</span>';
                                            echo '<span class="fw-bold text-success">' . 
                                                 htmlspecialchars($company['currency']) . ' ' . 
                                                 number_format($item['net_amount'], 2) . '</span>';
                                            echo '</div>';
                                        }
                                    } else {
                                        echo '<div class="text-muted py-3 px-3 text-center">No revenue recorded for this period</div>';
                                    }
                                    ?>
                                    
                                    <div class="d-flex justify-content-between py-2 px-3 bg-light fw-bold">
                                        <span>Total Revenue</span>
                                        <span class="text-success"><?php echo htmlspecialchars($company['currency']); ?> <?php echo number_format($totals['revenue'], 2); ?></span>
                                    </div>
                                </div>
                            </div>

                            <!-- OPERATING EXPENSES SECTION -->
                            <div class="card border-0 mb-3">
                                <div class="card-header bg-light">
                                    <h6 class="mb-0 fw-bold text-danger">OPERATING EXPENSES</h6>
                                </div>
                                <div class="card-body p-0">
                                    <?php 
                                    if (!empty($categories['operating_expenses'])) {
                                        foreach ($categories['operating_expenses'] as $item) {
                                            echo '<div class="d-flex justify-content-between py-2 px-3 border-bottom">';
                                            echo '<span>' . htmlspecialchars($item['account_name']) . '</span>';
                                            echo '<span class="fw-bold text-danger">' . 
                                                 htmlspecialchars($company['currency']) . ' ' . 
                                                 number_format($item['net_amount'], 2) . '</span>';
                                            echo '</div>';
                                        }
                                    } else {
                                        echo '<div class="text-muted py-3 px-3 text-center">No operating expenses recorded for this period</div>';
                                    }
                                    ?>
                                    
                                    <div class="d-flex justify-content-between py-2 px-3 bg-light fw-bold">
                                        <span>Total Operating Expenses</span>
                                        <span class="text-danger">(<?php echo htmlspecialchars($company['currency']); ?> <?php echo number_format($totals['operating_expenses'], 2); ?>)</span>
                                    </div>
                                </div>
                            </div>

                            <!-- OPERATING PROFIT -->
                            <div class="card border-0 mb-3">
                                <div class="card-body p-0">
                                    <div class="d-flex justify-content-between py-3 px-3 border-bottom bg-light fw-bold">
                                        <span>Operating Profit</span>
                                        <span><?php echo htmlspecialchars($company['currency']); ?> <?php echo number_format($totals['operating_profit'], 2); ?></span>
                                    </div>
                                </div>
                            </div>

                            <!-- OTHER INCOME -->
                            <?php if (!empty($categories['other_income'])): ?>
                            <div class="card border-0 mb-3">
                                <div class="card-header bg-light">
                                    <h6 class="mb-0 fw-bold">OTHER INCOME</h6>
                                </div>
                                <div class="card-body p-0">
                                    <?php 
                                    foreach ($categories['other_income'] as $item) {
                                        echo '<div class="d-flex justify-content-between py-2 px-3 border-bottom">';
                                        echo '<span>' . htmlspecialchars($item['account_name']) . '</span>';
                                        echo '<span class="fw-bold text-success">' . 
                                             htmlspecialchars($company['currency']) . ' ' . 
                                             number_format($item['net_amount'], 2) . '</span>';
                                        echo '</div>';
                                    }
                                    ?>
                                    
                                    <div class="d-flex justify-content-between py-2 px-3 bg-light fw-bold">
                                        <span>Total Other Income</span>
                                        <span class="text-success"><?php echo htmlspecialchars($company['currency']); ?> <?php echo number_format($totals['other_income'], 2); ?></span>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>

                            <!-- FINANCE COSTS -->
                            <?php if (!empty($categories['finance_costs'])): ?>
                            <div class="card border-0 mb-3">
                                <div class="card-header bg-light">
                                    <h6 class="mb-0 fw-bold">FINANCE COSTS</h6>
                                </div>
                                <div class="card-body p-0">
                                    <?php 
                                    foreach ($categories['finance_costs'] as $item) {
                                        echo '<div class="d-flex justify-content-between py-2 px-3 border-bottom">';
                                        echo '<span>' . htmlspecialchars($item['account_name']) . '</span>';
                                        echo '<span class="fw-bold text-danger">' . 
                                             htmlspecialchars($company['currency']) . ' ' . 
                                             number_format($item['net_amount'], 2) . '</span>';
                                        echo '</div>';
                                    }
                                    ?>
                                    
                                    <div class="d-flex justify-content-between py-2 px-3 bg-light fw-bold">
                                        <span>Total Finance Costs</span>
                                        <span class="text-danger">(<?php echo htmlspecialchars($company['currency']); ?> <?php echo number_format($totals['finance_costs'], 2); ?>)</span>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>

                            <!-- PROFIT BEFORE TAX -->
                            <div class="card border-0 mb-3">
                                <div class="card-body p-0">
                                    <div class="d-flex justify-content-between py-3 px-3 bg-light fw-bold">
                                        <span>Profit Before Tax</span>
                                        <span><?php echo htmlspecialchars($company['currency']); ?> <?php echo number_format($totals['profit_before_tax'], 2); ?></span>
                                    </div>
                                </div>
                            </div>

                            <!-- TAX EXPENSES -->
                            <?php if (!empty($categories['tax_expenses'])): ?>
                            <div class="card border-0 mb-3">
                                <div class="card-header bg-light">
                                    <h6 class="mb-0 fw-bold">TAX EXPENSE</h6>
                                </div>
                                <div class="card-body p-0">
                                    <?php 
                                    foreach ($categories['tax_expenses'] as $item) {
                                        echo '<div class="d-flex justify-content-between py-2 px-3 border-bottom">';
                                        echo '<span>' . htmlspecialchars($item['account_name']) . '</span>';
                                        echo '<span class="fw-bold text-danger">' . 
                                             htmlspecialchars($company['currency']) . ' ' . 
                                             number_format($item['net_amount'], 2) . '</span>';
                                        echo '</div>';
                                    }
                                    ?>
                                    
                                    <div class="d-flex justify-content-between py-2 px-3 bg-light fw-bold">
                                        <span>Total Tax Expense</span>
                                        <span class="text-danger">(<?php echo htmlspecialchars($company['currency']); ?> <?php echo number_format($totals['tax_expenses'], 2); ?>)</span>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>

                            <!-- NET INCOME -->
                            <div class="card border-0">
                                <div class="card-body p-0">
                                    <div class="d-flex justify-content-between py-3 px-3 bg-<?php echo $totals['net_income'] >= 0 ? 'success' : 'danger'; ?> text-white fw-bold">
                                        <h5 class="mb-0">NET INCOME AFTER TAX</h5>
                                        <h5 class="mb-0"><?php echo htmlspecialchars($company['currency']); ?> <?php echo number_format($totals['net_income'], 2); ?></h5>
                                    </div>
                                </div>
                            </div>

                            <!-- FINANCIAL RATIOS -->
                            <?php if ($totals['revenue'] > 0): ?>
                            <div class="row mt-4">
                                <div class="col-md-4">
                                    <div class="card text-center">
                                        <div class="card-body">
                                            <h6 class="text-muted">Operating Margin</h6>
                                            <h4 class="text-<?php echo ($totals['operating_profit']/$totals['revenue']*100) >= 0 ? 'success' : 'danger'; ?>">
                                                <?php echo number_format($totals['operating_profit']/$totals['revenue']*100, 1); ?>%
                                            </h4>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="card text-center">
                                        <div class="card-body">
                                            <h6 class="text-muted">Net Profit Margin</h6>
                                            <h4 class="text-<?php echo ($totals['net_income']/$totals['revenue']*100) >= 0 ? 'success' : 'danger'; ?>">
                                                <?php echo number_format($totals['net_income']/$totals['revenue']*100, 1); ?>%
                                            </h4>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="card text-center">
                                        <div class="card-body">
                                            <h6 class="text-muted">Expense Ratio</h6>
                                            <h4 class="text-<?php echo ($totals['operating_expenses']/$totals['revenue']*100) < 80 ? 'success' : 'danger'; ?>">
                                                <?php echo number_format($totals['operating_expenses']/$totals['revenue']*100, 1); ?>%
                                            </h4>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- PERIOD SELECTION MODAL -->
<div class="modal fade" id="periodModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Select Reporting Period</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form method="GET" id="periodForm">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    <div class="mb-3">
                        <label class="form-label">Start Date</label>
                        <input type="date" class="form-control" name="start_date" value="<?php echo htmlspecialchars($start_date); ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">End Date</label>
                        <input type="date" class="form-control" name="end_date" value="<?php echo htmlspecialchars($end_date); ?>" required>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" form="periodForm" class="btn btn-primary">Apply Period</button>
            </div>
        </div>
    </div>
</div>

<script>
// CLIENT-SIDE VALIDATION
document.addEventListener('DOMContentLoaded', function() {
    const periodForm = document.getElementById('periodForm');
    
    if (periodForm) {
        periodForm.addEventListener('submit', function(e) {
            const startDate = new Date(this.start_date.value);
            const endDate = new Date(this.end_date.value);
            
            // Validate date range
            if (startDate > endDate) {
                e.preventDefault();
                alert('Error: Start date cannot be after end date.');
                return false;
            }
        });
    }
    
    // Prevent form resubmission on page refresh
    if (window.history.replaceState) {
        window.history.replaceState(null, null, window.location.href);
    }
});
</script>

<?php include '../includes/footer.php'; ?>