<?php
require_once '../config/config.php';
require_once '../auth/auth_middleware.php';

// Security headers
header("X-Frame-Options: DENY");
header("X-Content-Type-Options: nosniff");
header("X-XSS-Protection: 1; mode=block");

require_finance_officer();

$db = getDBConnection();
$db->exec("SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci");

function validateDate($date, $format = 'Y-m-d') {
    $d = DateTime::createFromFormat($format, $date);
    return $d && $d->format($format) === $date;
}

function sanitizeInput($input) {
    return htmlspecialchars(trim($input ?? ''), ENT_QUOTES, 'UTF-8');
}

function validateNumeric($value) {
    return is_numeric($value) ? (float)$value : 0;
}

// Get filter values from GET parameters
$start_date = $_GET['start_date'] ?? '';
$end_date = $_GET['end_date'] ?? '';
$account_filter = $_GET['account'] ?? '';
$category_filter = $_GET['category'] ?? '';
$reference_type_filter = $_GET['reference_type'] ?? '';
$search_term = $_GET['search'] ?? '';
$export_type = $_GET['export'] ?? 'excel';
$clicked_account_id = $_GET['view_account'] ?? '';

// Validate and sanitize inputs
if ($start_date && !validateDate($start_date)) {
    $start_date = '';
}
if ($end_date && !validateDate($end_date)) {
    $end_date = '';
}

// Fix date order if needed
if ($start_date && $end_date && strtotime($end_date) < strtotime($start_date)) {
    $end_date = $start_date;
}

$account_filter = sanitizeInput($account_filter);
$category_filter = sanitizeInput($category_filter);
$reference_type_filter = sanitizeInput($reference_type_filter);
$search_term = sanitizeInput($search_term);

// Get clicked account details for export title
$clicked_account_name = '';
$clicked_account_code = '';
if (!empty($clicked_account_id) && is_numeric($clicked_account_id)) {
    $account_details_stmt = $db->prepare("
        SELECT account_code, account_name 
        FROM chart_of_accounts 
        WHERE id = ? AND status = 'active'
    ");
    $account_details_stmt->execute([$clicked_account_id]);
    $account_details = $account_details_stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($account_details) {
        $clicked_account_name = $account_details['account_name'];
        $clicked_account_code = $account_details['account_code'];
    }
}

// Get company info
$company_stmt = $db->prepare("SELECT * FROM companies WHERE status = 'active' ORDER BY id ASC LIMIT 1");
$company_stmt->execute();
$company = $company_stmt->fetch(PDO::FETCH_ASSOC) ?? [
    'company_name' => 'VICTORY FINANCIAL SERVICES LIMITED',
    'currency' => 'TZS'
];

// Main query for ledger entries
$query = "
    SELECT 
        gl.id,
        gl.transaction_date,
        gl.account_code,
        gl.account_name,
        gl.debit_amount,
        gl.credit_amount,
        gl.running_balance,
        gl.balance_type,
        gl.description,
        gl.reference_no,
        gl.reference_type,
        gl.entity_name,
        gl.entity_type,
        gl.currency,
        gl.created_at,
        gl.created_by_username,
        coa.account_type,
        coa.normal_balance
    FROM general_ledger gl
    LEFT JOIN chart_of_accounts coa ON gl.account_id = coa.id
    WHERE gl.status = 'active'
";

$params = [];

// Apply date filter only if dates are provided
if ($start_date && $end_date) {
    $query .= " AND gl.transaction_date BETWEEN ? AND ?";
    $params[] = $start_date;
    $params[] = $end_date;
}

// If account is clicked OR filtered, show only that account's transactions
if (!empty($account_filter) && is_numeric($account_filter)) {
    $query .= " AND gl.account_id = ?";
    $params[] = $account_filter;
}

// Apply other filters
if (!empty($category_filter)) {
    $valid_categories = ['asset', 'liability', 'equity', 'income', 'expense'];
    if (in_array($category_filter, $valid_categories)) {
        $query .= " AND coa.account_type = ?";
        $params[] = $category_filter;
    }
}

if (!empty($reference_type_filter)) {
    $valid_reference_types = ['trade', 'fee', 'adjustment', 'investment', 'payment', 'receipt', 'invoice', 'journal', 'transfer', 'expense', 'income'];
    if (in_array($reference_type_filter, $valid_reference_types)) {
        $query .= " AND gl.reference_type = ?";
        $params[] = $reference_type_filter;
    }
}

if (!empty($search_term)) {
    $query .= " AND (gl.description LIKE ? OR gl.reference_no LIKE ? OR gl.account_name LIKE ? OR gl.entity_name LIKE ?)";
    $search_like = "%$search_term%";
    $params[] = $search_like;
    $params[] = $search_like;
    $params[] = $search_like;
    $params[] = $search_like;
}

$query .= " ORDER BY gl.transaction_date ASC, gl.id ASC";

// Execute query
$stmt = $db->prepare($query);
$stmt->execute($params);
$ledger_entries = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate totals
$totals_query = "SELECT COALESCE(SUM(debit_amount), 0) as total_debit, COALESCE(SUM(credit_amount), 0) as total_credit FROM general_ledger WHERE status = 'active'";
$totals_params = [];

if ($start_date && $end_date) {
    $totals_query .= " AND transaction_date BETWEEN ? AND ?";
    $totals_params[] = $start_date;
    $totals_params[] = $end_date;
}

if (!empty($account_filter) && is_numeric($account_filter)) {
    $totals_query .= " AND account_id = ?";
    $totals_params[] = $account_filter;
}

$totals_stmt = $db->prepare($totals_query);
$totals_stmt->execute($totals_params);
$totals = $totals_stmt->fetch(PDO::FETCH_ASSOC);

$total_debits = $totals['total_debit'] ?? 0;
$total_credits = $totals['total_credit'] ?? 0;
$net_balance = $total_debits - $total_credits;

// Prepare export data
if ($export_type === 'excel') {
    exportToExcel($ledger_entries, $company, $clicked_account_name, $clicked_account_code, $start_date, $end_date, $total_debits, $total_credits, $net_balance);
} elseif ($export_type === 'pdf') {
    exportToPDF($ledger_entries, $company, $clicked_account_name, $clicked_account_code, $start_date, $end_date, $total_debits, $total_credits, $net_balance);
} else {
    // Default to CSV
    exportToCSV($ledger_entries, $company, $clicked_account_name, $clicked_account_code, $start_date, $end_date, $total_debits, $total_credits, $net_balance);
}

function exportToExcel($data, $company, $account_name, $account_code, $start_date, $end_date, $total_debits, $total_credits, $net_balance) {
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment;filename="General_Ledger_' . date('Y-m-d') . '.xls"');
    header('Cache-Control: max-age=0');
    
    echo '<!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <title>General Ledger Export</title>
        <style>
            body { font-family: Arial, sans-serif; }
            table { border-collapse: collapse; width: 100%; }
            th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
            th { background-color: #f2f2f2; font-weight: bold; }
            .total-row { background-color: #f8f9fa; font-weight: bold; }
            .header { text-align: center; margin-bottom: 20px; }
            .company-name { font-size: 24px; font-weight: bold; }
            .report-title { font-size: 18px; margin: 10px 0; }
            .period { font-size: 14px; color: #666; }
            .summary { margin: 20px 0; padding: 10px; background-color: #f8f9fa; border: 1px solid #ddd; }
        </style>
    </head>
    <body>';
    
    echo '<div class="header">';
    echo '<div class="company-name">' . htmlspecialchars($company['company_name']) . '</div>';
    echo '<div class="report-title">General Ledger Report</div>';
    
    if (!empty($account_name)) {
        echo '<div>Account: ' . htmlspecialchars($account_code . ' - ' . $account_name) . '</div>';
    }
    
    if ($start_date && $end_date) {
        echo '<div class="period">Period: ' . htmlspecialchars(date('F d, Y', strtotime($start_date))) . ' to ' . htmlspecialchars(date('F d, Y', strtotime($end_date))) . '</div>';
    } else {
        echo '<div class="period">All Transactions (No Date Filter)</div>';
    }
    
    echo '<div class="period">Generated on: ' . date('F d, Y h:i A') . '</div>';
    echo '</div>';
    
    // Summary section
    echo '<div class="summary">';
    echo '<div>Total Debits: ' . htmlspecialchars($company['currency']) . ' ' . number_format($total_debits, 2) . '</div>';
    echo '<div>Total Credits: ' . htmlspecialchars($company['currency']) . ' ' . number_format($total_credits, 2) . '</div>';
    echo '<div>Net Balance: ' . htmlspecialchars($company['currency']) . ' ' . number_format(abs($net_balance), 2) . ' ' . ($net_balance >= 0 ? 'DR' : 'CR') . '</div>';
    echo '</div>';
    
    echo '<table>';
    echo '<thead>
        <tr>
            <th>Date</th>
            <th>Account Code</th>
            <th>Account Name</th>
            <th>Description</th>
            <th>Reference No</th>
            <th>Reference Type</th>
            <th>Entity Name</th>
            <th>Debit (' . htmlspecialchars($company['currency']) . ')</th>
            <th>Credit (' . htmlspecialchars($company['currency']) . ')</th>
            <th>Balance Type</th>
            <th>Running Balance</th>
            <th>Created By</th>
            <th>Created At</th>
        </tr>
    </thead>';
    
    echo '<tbody>';
    
    $running_balance = 0;
    foreach ($data as $row) {
        $debit = validateNumeric($row['debit_amount']);
        $credit = validateNumeric($row['credit_amount']);
        $normal_balance = $row['normal_balance'] ?? 'debit';
        
        // Calculate running balance
        if ($normal_balance == 'debit') {
            $running_balance += ($debit - $credit);
        } else {
            $running_balance += ($credit - $debit);
        }
        
        echo '<tr>';
        echo '<td>' . htmlspecialchars(date('Y-m-d', strtotime($row['transaction_date']))) . '</td>';
        echo '<td>' . htmlspecialchars($row['account_code'] ?? '') . '</td>';
        echo '<td>' . htmlspecialchars($row['account_name'] ?? '') . '</td>';
        echo '<td>' . htmlspecialchars($row['description'] ?? '') . '</td>';
        echo '<td>' . htmlspecialchars($row['reference_no'] ?? '') . '</td>';
        echo '<td>' . htmlspecialchars(ucfirst($row['reference_type'] ?? '')) . '</td>';
        echo '<td>' . htmlspecialchars($row['entity_name'] ?? '') . '</td>';
        echo '<td>' . ($debit > 0 ? number_format($debit, 2) : '') . '</td>';
        echo '<td>' . ($credit > 0 ? number_format($credit, 2) : '') . '</td>';
        echo '<td>' . htmlspecialchars($row['balance_type'] ?? '') . '</td>';
        echo '<td>' . number_format($running_balance, 2) . ' ' . ($running_balance >= 0 ? 'DR' : 'CR') . '</td>';
        echo '<td>' . htmlspecialchars($row['created_by_username'] ?? '') . '</td>';
        echo '<td>' . htmlspecialchars(date('Y-m-d H:i', strtotime($row['created_at']))) . '</td>';
        echo '</tr>';
    }
    
    echo '</tbody>';
    echo '</table>';
    
    // Footer with totals
    echo '<div style="margin-top: 20px; padding-top: 10px; border-top: 2px solid #000;">';
    echo '<div><strong>Total Transactions: ' . count($data) . '</strong></div>';
    echo '</div>';
    
    echo '</body></html>';
}

function exportToCSV($data, $company, $account_name, $account_code, $start_date, $end_date, $total_debits, $total_credits, $net_balance) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="General_Ledger_' . date('Y-m-d') . '.csv"');
    
    $output = fopen('php://output', 'w');
    
    // Header information
    fputcsv($output, [$company['company_name']]);
    fputcsv($output, ['General Ledger Report']);
    
    if (!empty($account_name)) {
        fputcsv($output, ['Account:', $account_code . ' - ' . $account_name]);
    }
    
    if ($start_date && $end_date) {
        fputcsv($output, ['Period:', date('F d, Y', strtotime($start_date)) . ' to ' . date('F d, Y', strtotime($end_date))]);
    } else {
        fputcsv($output, ['Period:', 'All Transactions (No Date Filter)']);
    }
    
    fputcsv($output, ['Generated on:', date('F d, Y h:i A')]);
    fputcsv($output, []); // Empty row
    
    // Summary
    fputcsv($output, ['Summary']);
    fputcsv($output, ['Total Debits:', $company['currency'] . ' ' . number_format($total_debits, 2)]);
    fputcsv($output, ['Total Credits:', $company['currency'] . ' ' . number_format($total_credits, 2)]);
    fputcsv($output, ['Net Balance:', $company['currency'] . ' ' . number_format(abs($net_balance), 2) . ' ' . ($net_balance >= 0 ? 'DR' : 'CR')]);
    fputcsv($output, []); // Empty row
    
    // Column headers
    fputcsv($output, [
        'Date',
        'Account Code',
        'Account Name',
        'Description',
        'Reference No',
        'Reference Type',
        'Entity Name',
        'Debit (' . $company['currency'] . ')',
        'Credit (' . $company['currency'] . ')',
        'Balance Type',
        'Running Balance',
        'Created By',
        'Created At'
    ]);
    
    $running_balance = 0;
    foreach ($data as $row) {
        $debit = validateNumeric($row['debit_amount']);
        $credit = validateNumeric($row['credit_amount']);
        $normal_balance = $row['normal_balance'] ?? 'debit';
        
        // Calculate running balance
        if ($normal_balance == 'debit') {
            $running_balance += ($debit - $credit);
        } else {
            $running_balance += ($credit - $debit);
        }
        
        fputcsv($output, [
            date('Y-m-d', strtotime($row['transaction_date'])),
            $row['account_code'] ?? '',
            $row['account_name'] ?? '',
            $row['description'] ?? '',
            $row['reference_no'] ?? '',
            ucfirst($row['reference_type'] ?? ''),
            $row['entity_name'] ?? '',
            $debit > 0 ? number_format($debit, 2) : '',
            $credit > 0 ? number_format($credit, 2) : '',
            $row['balance_type'] ?? '',
            number_format($running_balance, 2) . ' ' . ($running_balance >= 0 ? 'DR' : 'CR'),
            $row['created_by_username'] ?? '',
            date('Y-m-d H:i', strtotime($row['created_at']))
        ]);
    }
    
    fputcsv($output, []); // Empty row
    fputcsv($output, ['Total Transactions:', count($data)]);
    
    fclose($output);
}

function exportToPDF($data, $company, $account_name, $account_code, $start_date, $end_date, $total_debits, $total_credits, $net_balance) {
    // For PDF, we'll output HTML that can be printed as PDF
    header('Content-Type: text/html');
    header('Content-Disposition: attachment; filename="General_Ledger_' . date('Y-m-d') . '.html"');
    
    echo '<!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <title>General Ledger - ' . htmlspecialchars($company['company_name']) . '</title>
        <style>
            @page { margin: 20px; }
            body { font-family: Arial, sans-serif; font-size: 12px; line-height: 1.4; }
            .header { text-align: center; margin-bottom: 30px; border-bottom: 2px solid #000; padding-bottom: 20px; }
            .company-name { font-size: 20px; font-weight: bold; margin-bottom: 5px; }
            .report-title { font-size: 16px; margin: 10px 0; }
            .period { font-size: 12px; color: #666; margin-bottom: 5px; }
            .summary { margin: 20px 0; padding: 15px; background-color: #f8f9fa; border: 1px solid #ddd; border-radius: 5px; }
            .summary div { margin: 5px 0; }
            table { width: 100%; border-collapse: collapse; margin: 20px 0; }
            th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
            th { background-color: #f2f2f2; font-weight: bold; }
            .total-row { background-color: #f8f9fa; font-weight: bold; }
            .footer { margin-top: 30px; padding-top: 10px; border-top: 1px solid #ddd; text-align: center; font-size: 10px; color: #666; }
            .no-print { display: none; }
            @media print {
                .no-print { display: none !important; }
                body { font-size: 10px; }
                th, td { padding: 5px; }
            }
        </style>
    </head>
    <body>';
    
    echo '<div class="header">';
    echo '<div class="company-name">' . htmlspecialchars($company['company_name']) . '</div>';
    echo '<div class="report-title">General Ledger Report</div>';
    
    if (!empty($account_name)) {
        echo '<div><strong>Account:</strong> ' . htmlspecialchars($account_code . ' - ' . $account_name) . '</div>';
    }
    
    if ($start_date && $end_date) {
        echo '<div class="period"><strong>Period:</strong> ' . htmlspecialchars(date('F d, Y', strtotime($start_date))) . ' to ' . htmlspecialchars(date('F d, Y', strtotime($end_date))) . '</div>';
    } else {
        echo '<div class="period"><strong>Period:</strong> All Transactions (No Date Filter)</div>';
    }
    
    echo '<div class="period"><strong>Generated on:</strong> ' . date('F d, Y h:i A') . '</div>';
    echo '</div>';
    
    // Summary section
    echo '<div class="summary">';
    echo '<div><strong>Total Debits:</strong> ' . htmlspecialchars($company['currency']) . ' ' . number_format($total_debits, 2) . '</div>';
    echo '<div><strong>Total Credits:</strong> ' . htmlspecialchars($company['currency']) . ' ' . number_format($total_credits, 2) . '</div>';
    echo '<div><strong>Net Balance:</strong> ' . htmlspecialchars($company['currency']) . ' ' . number_format(abs($net_balance), 2) . ' ' . ($net_balance >= 0 ? 'DR' : 'CR') . '</div>';
    echo '<div><strong>Total Transactions:</strong> ' . count($data) . '</div>';
    echo '</div>';
    
    echo '<table>';
    echo '<thead>
        <tr>
            <th>Date</th>
            <th>Account</th>
            <th>Description</th>
            <th>Reference</th>
            <th>Type</th>
            <th>Entity</th>
            <th>Debit (' . htmlspecialchars($company['currency']) . ')</th>
            <th>Credit (' . htmlspecialchars($company['currency']) . ')</th>
            <th>Balance</th>
        </tr>
    </thead>';
    
    echo '<tbody>';
    
    $running_balance = 0;
    foreach ($data as $row) {
        $debit = validateNumeric($row['debit_amount']);
        $credit = validateNumeric($row['credit_amount']);
        $normal_balance = $row['normal_balance'] ?? 'debit';
        
        // Calculate running balance
        if ($normal_balance == 'debit') {
            $running_balance += ($debit - $credit);
        } else {
            $running_balance += ($credit - $debit);
        }
        
        echo '<tr>';
        echo '<td>' . htmlspecialchars(date('m/d/Y', strtotime($row['transaction_date']))) . '</td>';
        echo '<td>' . htmlspecialchars(($row['account_code'] ?? '') . ' - ' . ($row['account_name'] ?? '')) . '</td>';
        echo '<td>' . htmlspecialchars($row['description'] ?? '') . '</td>';
        echo '<td>' . htmlspecialchars($row['reference_no'] ?? '') . '</td>';
        echo '<td>' . htmlspecialchars(ucfirst($row['reference_type'] ?? '')) . '</td>';
        echo '<td>' . htmlspecialchars($row['entity_name'] ?? '') . '</td>';
        echo '<td style="text-align: right;">' . ($debit > 0 ? number_format($debit, 2) : '') . '</td>';
        echo '<td style="text-align: right;">' . ($credit > 0 ? number_format($credit, 2) : '') . '</td>';
        echo '<td style="text-align: right;">' . number_format($running_balance, 2) . ' ' . ($running_balance >= 0 ? 'DR' : 'CR') . '</td>';
        echo '</tr>';
    }
    
    echo '</tbody>';
    echo '</table>';
    
    echo '<div class="footer">';
    echo 'Generated by Victory Financial Services Limited<br>';
    echo 'Page 1 of 1';
    echo '</div>';
    
    echo '<div class="no-print" style="margin-top: 20px; text-align: center;">
        <button onclick="window.print()">Print PDF</button>
        <button onclick="window.close()">Close</button>
    </div>';
    
    echo '<script>
        // Auto-print when opened
        window.onload = function() {
            setTimeout(function() {
                window.print();
            }, 1000);
        };
    </script>';
    
    echo '</body></html>';
}
?>