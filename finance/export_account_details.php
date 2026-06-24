<?php
// export_account_details.php
session_start();
require_once '../config/config.php';

// Check authentication
$user = get_logged_in_user();
if (!$user) {
    redirect('auth/login.php');
}

$allowed_roles = ['finance_officer', 'finance_manager', 'accountant', 'system_admin', 'ceo', 'admin'];
if (!in_array($user['role'], $allowed_roles)) {
    show_alert('You do not have permission to export account details.', 'danger');
    redirect('balance_sheet.php');
}

$db = getDBConnection();

// Get parameters
$account_code = $_GET['account_code'] ?? '';
$account_name = $_GET['account_name'] ?? '';
$start_date = $_GET['start_date'] ?? '';
$end_date = $_GET['end_date'] ?? '';
$format = $_GET['format'] ?? 'pdf';

if (empty($account_code) || empty($start_date) || empty($end_date)) {
    show_alert('Invalid parameters for export.', 'danger');
    redirect('balance_sheet.php');
}

// Get company details
$company_stmt = $db->prepare("
    SELECT 
        COALESCE(company_name, name) as company_name,
        address,
        phone,
        mobile,
        email,
        registration_number,
        currency
    FROM companies 
    WHERE status = 'active' OR is_active = 1
    ORDER BY id ASC 
    LIMIT 1
");
$company_stmt->execute();
$company = $company_stmt->fetch(PDO::FETCH_ASSOC) ?: [
    'company_name' => 'Neovam Technologies LTD',
    'address' => 'P.O Box, Dar es Salaam, Tanzania',
    'currency' => 'TSH'
];

// Get account information
$account_query = "
    SELECT 
        account_code,
        account_name,
        account_type,
        account_subtype,
        normal_balance,
        level,
        description as account_description
    FROM chart_of_accounts 
    WHERE account_code = ?
    LIMIT 1
";

$stmt = $db->prepare($account_query);
$stmt->execute([$account_code]);
$account_info = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$account_info) {
    $account_info = [
        'account_code' => $account_code,
        'account_name' => $account_name,
        'account_type' => 'Unknown',
        'normal_balance' => 'debit',
        'level' => 1,
        'account_description' => ''
    ];
}

// Get journal entries for this account with special handling for group accounts
$entries = [];
$sub_accounts = [];
$third_level_breakdown = [];

// Special handling for cash accounts (111 series)
if ($account_code === '111') {
    // Get all third level accounts
    $entries_query = "
        SELECT 
            gl.id,
            gl.transaction_date,
            gl.reference_no,
            gl.description,
            gl.debit_amount,
            gl.credit_amount,
            gl.entity_name,
            gl.reference_type,
            gl.currency,
            gl.status,
            gl.created_by_username,
            coa.account_code,
            coa.account_name,
            DATE_FORMAT(gl.transaction_date, '%d/%m/%Y') as transaction_date_display,
            CASE 
                WHEN gl.debit_amount > 0 THEN 'Debit'
                WHEN gl.credit_amount > 0 THEN 'Credit'
                ELSE 'N/A'
            END as transaction_side
        FROM general_ledger gl
        JOIN chart_of_accounts coa ON gl.account_id = coa.id
        WHERE gl.transaction_date BETWEEN ? AND ?
        AND gl.status != 'cancelled'
        AND coa.account_code LIKE '111%'
        ORDER BY coa.account_code, gl.transaction_date DESC, gl.id DESC
    ";
    
    $stmt = $db->prepare($entries_query);
    $stmt->execute([$start_date, $end_date]);
    $all_entries = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Group by sub-account for third level breakdown
    foreach ($all_entries as $entry) {
        $sub_code = substr($entry['account_code'], 0, 4);
        if (!isset($third_level_breakdown[$sub_code])) {
            $third_level_breakdown[$sub_code] = [
                'name' => $entry['account_name'],
                'debit' => 0,
                'credit' => 0,
                'entries' => []
            ];
        }
        $third_level_breakdown[$sub_code]['debit'] += $entry['debit_amount'];
        $third_level_breakdown[$sub_code]['credit'] += $entry['credit_amount'];
        $third_level_breakdown[$sub_code]['entries'][] = $entry;
    }
    
    // Calculate totals for main 111 account
    $entries = $all_entries;
} elseif ($account_code === '211') {
    // Trade Payables
    $entries_query = "
        SELECT 
            gl.id,
            gl.transaction_date,
            gl.reference_no,
            gl.description,
            gl.debit_amount,
            gl.credit_amount,
            gl.entity_name,
            gl.reference_type,
            gl.currency,
            gl.status,
            gl.created_by_username,
            v.name as vendor_name,
            DATE_FORMAT(gl.transaction_date, '%d/%m/%Y') as transaction_date_display,
            CASE 
                WHEN gl.debit_amount > 0 THEN 'Debit'
                WHEN gl.credit_amount > 0 THEN 'Credit'
                ELSE 'N/A'
            END as transaction_side
        FROM general_ledger gl
        JOIN chart_of_accounts coa ON gl.account_id = coa.id
        LEFT JOIN vendors v ON gl.vendor_id = v.id
        WHERE gl.transaction_date BETWEEN ? AND ?
        AND gl.status != 'cancelled'
        AND coa.account_code LIKE '211%'
        ORDER BY gl.transaction_date DESC, gl.id DESC
    ";
    
    $stmt = $db->prepare($entries_query);
    $stmt->execute([$start_date, $end_date]);
    $entries = $stmt->fetchAll(PDO::FETCH_ASSOC);
} elseif ($account_code === '213-215') {
    // Other Current Liabilities
    $entries_query = "
        SELECT 
            gl.id,
            gl.transaction_date,
            gl.reference_no,
            gl.description,
            gl.debit_amount,
            gl.credit_amount,
            gl.entity_name,
            gl.reference_type,
            gl.currency,
            gl.status,
            gl.created_by_username,
            coa.account_code,
            coa.account_name,
            DATE_FORMAT(gl.transaction_date, '%d/%m/%Y') as transaction_date_display,
            CASE 
                WHEN gl.debit_amount > 0 THEN 'Debit'
                WHEN gl.credit_amount > 0 THEN 'Credit'
                ELSE 'N/A'
            END as transaction_side
        FROM general_ledger gl
        JOIN chart_of_accounts coa ON gl.account_id = coa.id
        WHERE gl.transaction_date BETWEEN ? AND ?
        AND gl.status != 'cancelled'
        AND (coa.account_code LIKE '213%' OR coa.account_code LIKE '214%' OR coa.account_code LIKE '215%')
        ORDER BY coa.account_code, gl.transaction_date DESC, gl.id DESC
    ";
    
    $stmt = $db->prepare($entries_query);
    $stmt->execute([$start_date, $end_date]);
    $all_entries = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Group by main account code (213, 214, 215)
    foreach ($all_entries as $entry) {
        $main_code = substr($entry['account_code'], 0, 3);
        if (!isset($third_level_breakdown[$main_code])) {
            $third_level_breakdown[$main_code] = [
                'name' => $entry['account_name'],
                'debit' => 0,
                'credit' => 0,
                'entries' => []
            ];
        }
        $third_level_breakdown[$main_code]['debit'] += $entry['debit_amount'];
        $third_level_breakdown[$main_code]['credit'] += $entry['credit_amount'];
        $third_level_breakdown[$main_code]['entries'][] = $entry;
    }
    
    $entries = $all_entries;
} else {
    // Other accounts
    $entries_query = "
        SELECT 
            gl.id,
            gl.transaction_date,
            gl.reference_no,
            gl.description,
            gl.debit_amount,
            gl.credit_amount,
            gl.entity_name,
            gl.reference_type,
            gl.currency,
            gl.status,
            gl.created_by_username,
            DATE_FORMAT(gl.transaction_date, '%d/%m/%Y') as transaction_date_display,
            CASE 
                WHEN gl.debit_amount > 0 THEN 'Debit'
                WHEN gl.credit_amount > 0 THEN 'Credit'
                ELSE 'N/A'
            END as transaction_side
        FROM general_ledger gl
        WHERE gl.account_code = ?
        AND gl.transaction_date BETWEEN ? AND ?
        AND gl.status != 'cancelled'
        ORDER BY gl.transaction_date DESC, gl.id DESC
    ";
    
    $stmt = $db->prepare($entries_query);
    $stmt->execute([$account_code, $start_date, $end_date]);
    $entries = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Calculate totals
$total_debit = 0;
$total_credit = 0;

foreach ($entries as $entry) {
    $total_debit += $entry['debit_amount'];
    $total_credit += $entry['credit_amount'];
}

// Determine net balance based on account type
$net_balance = 0;
if ($account_info['account_type'] == 'asset' || $account_info['account_type'] == 'expense') {
    $net_balance = $total_debit - $total_credit;
} else {
    $net_balance = $total_credit - $total_debit;
}

// Get sub-accounts for group accounts
$sub_accounts = [];
if (in_array($account_info['account_type'], ['asset', 'liability', 'income', 'expense'])) {
    $sub_accounts_query = "
        SELECT 
            account_code,
            account_name,
            account_type,
            normal_balance,
            level
        FROM chart_of_accounts 
        WHERE account_code LIKE ? 
        AND account_code != ?
        AND is_active = 1
        ORDER BY account_code
    ";
    $stmt = $db->prepare($sub_accounts_query);
    $stmt->execute([$account_code . '%', $account_code]);
    $sub_accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Export based on format
switch ($format) {
    case 'pdf':
        exportToPDF($company, $account_info, $entries, $sub_accounts, $third_level_breakdown, $total_debit, $total_credit, $net_balance, $start_date, $end_date);
        break;
    case 'excel':
        exportToExcel($company, $account_info, $entries, $sub_accounts, $third_level_breakdown, $total_debit, $total_credit, $net_balance, $start_date, $end_date);
        break;
    case 'csv':
        exportToCSV($company, $account_info, $entries, $sub_accounts, $third_level_breakdown, $total_debit, $total_credit, $net_balance, $start_date, $end_date);
        break;
    default:
        exportToPDF($company, $account_info, $entries, $sub_accounts, $third_level_breakdown, $total_debit, $total_credit, $net_balance, $start_date, $end_date);
        break;
}

function exportToPDF($company, $account_info, $entries, $sub_accounts, $third_level_breakdown, $total_debit, $total_credit, $net_balance, $start_date, $end_date) {
    require_once('../tcpdf/tcpdf.php');
    
    // Create new PDF document
    $pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, 'A4', true, 'UTF-8', false);
    
    // Set document information
    $pdf->SetCreator('Financial System');
    $pdf->SetAuthor($company['company_name']);
    $pdf->SetTitle('Account Details Report');
    $pdf->SetSubject('Account Transactions');
    
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
    $pdf->Cell(0, 10, 'ACCOUNT DETAILS REPORT', 0, 1, 'C');
    $pdf->SetFont('helvetica', '', 11);
    $pdf->Cell(0, 6, '(' . $company['company_name'] . ')', 0, 1, 'C');
    $pdf->SetFont('helvetica', 'I', 10);
    $pdf->Cell(0, 6, 'Period: ' . date('d/m/Y', strtotime($start_date)) . ' to ' . date('d/m/Y', strtotime($end_date)), 0, 1, 'C');
    $pdf->Cell(0, 6, 'Generated on: ' . date('d/m/Y H:i:s'), 0, 1, 'C');
    $pdf->Ln(5);
    
    // Account Information
    $pdf->SetFont('helvetica', 'B', 12);
    $pdf->Cell(0, 8, 'Account Information', 0, 1);
    $pdf->SetFont('helvetica', '', 10);
    
    $pdf->Cell(40, 6, 'Account Code:', 0, 0);
    $pdf->Cell(0, 6, $account_info['account_code'], 0, 1);
    
    $pdf->Cell(40, 6, 'Account Name:', 0, 0);
    $pdf->Cell(0, 6, $account_info['account_name'], 0, 1);
    
    $pdf->Cell(40, 6, 'Account Type:', 0, 0);
    $pdf->Cell(0, 6, ucfirst($account_info['account_type']), 0, 1);
    
    $pdf->Cell(40, 6, 'Normal Balance:', 0, 0);
    $pdf->Cell(0, 6, ucfirst($account_info['normal_balance']), 0, 1);
    
    if (!empty($account_info['account_description'])) {
        $pdf->Cell(40, 6, 'Description:', 0, 0);
        $pdf->MultiCell(0, 6, $account_info['account_description'], 0, 1);
    }
    $pdf->Ln(5);
    
    // Summary Statistics
    $pdf->SetFont('helvetica', 'B', 12);
    $pdf->Cell(0, 8, 'Summary Statistics', 0, 1);
    $pdf->SetFont('helvetica', '', 10);
    
    $pdf->Cell(60, 6, 'Total Debit Transactions:', 0, 0);
    $pdf->Cell(0, 6, number_format(count(array_filter($entries, function($e) { return $e['debit_amount'] > 0; })), 0), 0, 1);
    
    $pdf->Cell(60, 6, 'Total Credit Transactions:', 0, 0);
    $pdf->Cell(0, 6, number_format(count(array_filter($entries, function($e) { return $e['credit_amount'] > 0; })), 0), 0, 1);
    
    $pdf->Cell(60, 6, 'Total Transactions:', 0, 0);
    $pdf->Cell(0, 6, number_format(count($entries), 0), 0, 1);
    
    $pdf->Cell(60, 6, 'Total Debit Amount:', 0, 0);
    $pdf->Cell(0, 6, number_format($total_debit, 2) . ' ' . $company['currency'], 0, 1);
    
    $pdf->Cell(60, 6, 'Total Credit Amount:', 0, 0);
    $pdf->Cell(0, 6, number_format($total_credit, 2) . ' ' . $company['currency'], 0, 1);
    
    $pdf->SetFont('helvetica', 'B', 11);
    $pdf->Cell(60, 7, 'Net Balance:', 0, 0);
    $pdf->Cell(0, 7, number_format(abs($net_balance), 2) . ' ' . $company['currency'] . ' (' . ($net_balance >= 0 ? 'Debit' : 'Credit') . ')', 0, 1);
    $pdf->Ln(10);
    
    // Third Level Breakdown (for cash and other grouped accounts)
    if (!empty($third_level_breakdown)) {
        $pdf->SetFont('helvetica', 'B', 12);
        $pdf->Cell(0, 8, 'Third Level Account Breakdown', 0, 1);
        
        $pdf->SetFont('helvetica', 'B', 9);
        $pdf->Cell(30, 6, 'Account Code', 1, 0, 'C');
        $pdf->Cell(60, 6, 'Account Name', 1, 0, 'C');
        $pdf->Cell(30, 6, 'Debit Total', 1, 0, 'C');
        $pdf->Cell(30, 6, 'Credit Total', 1, 0, 'C');
        $pdf->Cell(40, 6, 'Net Balance', 1, 1, 'C');
        
        $pdf->SetFont('helvetica', '', 9);
        foreach ($third_level_breakdown as $code => $data) {
            $sub_net = $data['debit'] - $data['credit'];
            $pdf->Cell(30, 6, $code, 1, 0);
            $pdf->Cell(60, 6, substr($data['name'], 0, 30), 1, 0);
            $pdf->Cell(30, 6, number_format($data['debit'], 2), 1, 0, 'R');
            $pdf->Cell(30, 6, number_format($data['credit'], 2), 1, 0, 'R');
            $pdf->Cell(40, 6, number_format(abs($sub_net), 2) . ' (' . ($sub_net >= 0 ? 'Debit' : 'Credit') . ')', 1, 1);
        }
        $pdf->Ln(10);
    }
    
    // Sub-Accounts (if any)
    if (!empty($sub_accounts)) {
        $pdf->SetFont('helvetica', 'B', 12);
        $pdf->Cell(0, 8, 'Sub-Accounts in this Group', 0, 1);
        
        $pdf->SetFont('helvetica', 'B', 9);
        $pdf->Cell(40, 6, 'Account Code', 1, 0, 'C');
        $pdf->Cell(80, 6, 'Account Name', 1, 0, 'C');
        $pdf->Cell(30, 6, 'Type', 1, 0, 'C');
        $pdf->Cell(40, 6, 'Normal Balance', 1, 1, 'C');
        
        $pdf->SetFont('helvetica', '', 9);
        foreach ($sub_accounts as $sub) {
            $pdf->Cell(40, 6, $sub['account_code'], 1, 0);
            $pdf->Cell(80, 6, $sub['account_name'], 1, 0);
            $pdf->Cell(30, 6, ucfirst($sub['account_type']), 1, 0);
            $pdf->Cell(40, 6, ucfirst($sub['normal_balance']), 1, 1);
        }
        $pdf->Ln(10);
    }
    
    // Transaction Details
    $pdf->SetFont('helvetica', 'B', 12);
    $pdf->Cell(0, 8, 'Transaction Details (' . count($entries) . ' entries)', 0, 1);
    
    if (empty($entries)) {
        $pdf->SetFont('helvetica', 'I', 10);
        $pdf->Cell(0, 6, 'No transactions found for this period.', 0, 1);
    } else {
        // Table Header
        $pdf->SetFont('helvetica', 'B', 9);
        $pdf->Cell(20, 6, 'Date', 1, 0, 'C');
        $pdf->Cell(30, 6, 'Reference', 1, 0, 'C');
        $pdf->Cell(50, 6, 'Description', 1, 0, 'C');
        $pdf->Cell(25, 6, 'Entity', 1, 0, 'C');
        $pdf->Cell(20, 6, 'Type', 1, 0, 'C');
        $pdf->Cell(25, 6, 'Debit', 1, 0, 'C');
        $pdf->Cell(25, 6, 'Credit', 1, 1, 'C');
        
        $pdf->SetFont('helvetica', '', 8);
        foreach ($entries as $entry) {
            $pdf->Cell(20, 6, $entry['transaction_date_display'], 1, 0);
            $pdf->Cell(30, 6, $entry['reference_no'], 1, 0);
            
            // Truncate long descriptions
            $description = strlen($entry['description']) > 35 ? substr($entry['description'], 0, 32) . '...' : $entry['description'];
            $pdf->Cell(50, 6, $description, 1, 0);
            
            $entity = $entry['entity_name'] ?? '-';
            $entity = strlen($entity) > 15 ? substr($entity, 0, 12) . '...' : $entity;
            $pdf->Cell(25, 6, $entity, 1, 0);
            
            $pdf->Cell(20, 6, $entry['reference_type'], 1, 0);
            $pdf->Cell(25, 6, $entry['debit_amount'] > 0 ? number_format($entry['debit_amount'], 2) : '-', 1, 0, 'R');
            $pdf->Cell(25, 6, $entry['credit_amount'] > 0 ? number_format($entry['credit_amount'], 2) : '-', 1, 1, 'R');
        }
        
        // Totals Row
        $pdf->SetFont('helvetica', 'B', 9);
        $pdf->Cell(145, 6, 'TOTALS:', 1, 0, 'R');
        $pdf->Cell(25, 6, number_format($total_debit, 2), 1, 0, 'R');
        $pdf->Cell(25, 6, number_format($total_credit, 2), 1, 1, 'R');
        
        // Net Balance Row
        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->Cell(145, 7, 'NET BALANCE:', 1, 0, 'R');
        $pdf->Cell(50, 7, number_format(abs($net_balance), 2) . ' (' . ($net_balance >= 0 ? 'Debit' : 'Credit') . ')', 1, 1, 'C');
    }
    
    // Footer
    $pdf->Ln(10);
    $pdf->SetFont('helvetica', 'I', 8);
    $pdf->Cell(0, 5, 'Generated by: ' . $_SESSION['username'] ?? 'System', 0, 1);
    $pdf->Cell(0, 5, 'Page ' . $pdf->getAliasNumPage() . ' of ' . $pdf->getAliasNbPages(), 0, 1);
    
    // Output PDF
    $filename = 'Account_Details_' . $account_info['account_code'] . '_' . date('Ymd_His') . '.pdf';
    $pdf->Output($filename, 'I');
    exit;
}

function exportToExcel($company, $account_info, $entries, $sub_accounts, $third_level_breakdown, $total_debit, $total_credit, $net_balance, $start_date, $end_date) {
    header('Content-Type: application/vnd.ms-excel');
    $filename = 'Account_Details_' . $account_info['account_code'] . '_' . date('Ymd_His') . '.xls';
    header('Content-Disposition: attachment;filename="' . $filename . '"');
    header('Cache-Control: max-age=0');
    
    echo "<!DOCTYPE html>
    <html>
    <head>
        <meta charset='UTF-8'>
        <style>
            body { font-family: Arial, sans-serif; }
            table { border-collapse: collapse; width: 100%; }
            th, td { border: 1px solid #000; padding: 6px; }
            .header { text-align: center; font-weight: bold; font-size: 16px; }
            .subheader { text-align: center; font-style: italic; }
            .total { font-weight: bold; background-color: #f0f0f0; }
            .summary { background-color: #e8f4f8; padding: 10px; margin: 10px 0; }
            .section-title { font-weight: bold; background-color: #d0d0d0; padding: 8px; }
            .breakdown-title { font-weight: bold; background-color: #e0e0ff; padding: 8px; }
            .debit { color: #d9534f; }
            .credit { color: #5cb85c; }
            .sub-account { background-color: #f9f9f9; }
        </style>
    </head>
    <body>";
    
    echo "<h3 class='header'>ACCOUNT DETAILS REPORT</h3>";
    echo "<h4 class='header'>" . htmlspecialchars($company['company_name']) . "</h4>";
    echo "<p class='subheader'>Period: " . date('d/m/Y', strtotime($start_date)) . " to " . date('d/m/Y', strtotime($end_date)) . "</p>";
    echo "<p class='subheader'>Generated on: " . date('d/m/Y H:i:s') . "</p>";
    echo "<br>";
    
    // Account Information
    echo "<div class='section-title'>Account Information</div>";
    echo "<table>
        <tr>
            <td width='150'><strong>Account Code:</strong></td>
            <td>" . htmlspecialchars($account_info['account_code']) . "</td>
        </tr>
        <tr>
            <td><strong>Account Name:</strong></td>
            <td>" . htmlspecialchars($account_info['account_name']) . "</td>
        </tr>
        <tr>
            <td><strong>Account Type:</strong></td>
            <td>" . htmlspecialchars(ucfirst($account_info['account_type'])) . "</td>
        </tr>
        <tr>
            <td><strong>Normal Balance:</strong></td>
            <td>" . htmlspecialchars(ucfirst($account_info['normal_balance'])) . "</td>
        </tr>";
    
    if (!empty($account_info['account_description'])) {
        echo "<tr>
            <td><strong>Description:</strong></td>
            <td>" . nl2br(htmlspecialchars($account_info['account_description'])) . "</td>
        </tr>";
    }
    
    echo "</table>";
    echo "<br>";
    
    // Summary Statistics
    echo "<div class='summary'>";
    echo "<div class='section-title'>Summary Statistics</div>";
    echo "<table>
        <tr>
            <td width='200'><strong>Total Debit Transactions:</strong></td>
            <td>" . number_format(count(array_filter($entries, function($e) { return $e['debit_amount'] > 0; })), 0) . "</td>
        </tr>
        <tr>
            <td><strong>Total Credit Transactions:</strong></td>
            <td>" . number_format(count(array_filter($entries, function($e) { return $e['credit_amount'] > 0; })), 0) . "</td>
        </tr>
        <tr>
            <td><strong>Total Transactions:</strong></td>
            <td>" . number_format(count($entries), 0) . "</td>
        </tr>
        <tr>
            <td><strong>Total Debit Amount:</strong></td>
            <td class='debit'>" . number_format($total_debit, 2) . "</td>
        </tr>
        <tr>
            <td><strong>Total Credit Amount:</strong></td>
            <td class='credit'>" . number_format($total_credit, 2) . "</td>
        </tr>
        <tr class='total'>
            <td><strong>Net Balance:</strong></td>
            <td><strong>" . number_format(abs($net_balance), 2) . " (" . ($net_balance >= 0 ? 'Debit' : 'Credit') . ")</strong></td>
        </tr>
    </table>";
    echo "</div>";
    echo "<br>";
    
    // Third Level Breakdown
    if (!empty($third_level_breakdown)) {
        echo "<div class='breakdown-title'>Third Level Account Breakdown</div>";
        echo "<table>
            <thead>
                <tr>
                    <th>Account Code</th>
                    <th>Account Name</th>
                    <th>Debit Total</th>
                    <th>Credit Total</th>
                    <th>Net Balance</th>
                    <th>Entries Count</th>
                </tr>
            </thead>
            <tbody>";
        
        foreach ($third_level_breakdown as $code => $data) {
            $sub_net = $data['debit'] - $data['credit'];
            $entries_count = count($data['entries']);
            echo "<tr class='sub-account'>
                <td>" . htmlspecialchars($code) . "</td>
                <td>" . htmlspecialchars($data['name']) . "</td>
                <td class='debit'>" . number_format($data['debit'], 2) . "</td>
                <td class='credit'>" . number_format($data['credit'], 2) . "</td>
                <td><strong>" . number_format(abs($sub_net), 2) . " (" . ($sub_net >= 0 ? 'Debit' : 'Credit') . ")</strong></td>
                <td>" . $entries_count . "</td>
            </tr>";
        }
        
        echo "</tbody></table>";
        echo "<br>";
    }
    
    // Sub-Accounts
    if (!empty($sub_accounts)) {
        echo "<div class='section-title'>Sub-Accounts in this Group</div>";
        echo "<table>
            <thead>
                <tr>
                    <th>Account Code</th>
                    <th>Account Name</th>
                    <th>Type</th>
                    <th>Normal Balance</th>
                    <th>Level</th>
                </tr>
            </thead>
            <tbody>";
        
        foreach ($sub_accounts as $sub) {
            echo "<tr>
                <td>" . htmlspecialchars($sub['account_code']) . "</td>
                <td>" . htmlspecialchars($sub['account_name']) . "</td>
                <td>" . htmlspecialchars(ucfirst($sub['account_type'])) . "</td>
                <td>" . htmlspecialchars(ucfirst($sub['normal_balance'])) . "</td>
                <td>" . $sub['level'] . "</td>
            </tr>";
        }
        
        echo "</tbody></table>";
        echo "<br>";
    }
    
    // Transaction Details
    echo "<div class='section-title'>Transaction Details (" . count($entries) . " entries)</div>";
    
    if (empty($entries)) {
        echo "<p><em>No transactions found for this period.</em></p>";
    } else {
        echo "<table>
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Reference</th>
                    <th>Description</th>
                    <th>Entity</th>
                    <th>Type</th>
                    <th>Debit</th>
                    <th>Credit</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>";
        
        foreach ($entries as $entry) {
            echo "<tr>
                <td>" . $entry['transaction_date_display'] . "</td>
                <td>" . htmlspecialchars($entry['reference_no']) . "</td>
                <td>" . htmlspecialchars($entry['description']) . "</td>
                <td>" . htmlspecialchars($entry['entity_name'] ?? '-') . "</td>
                <td>" . htmlspecialchars($entry['reference_type']) . "</td>
                <td class='debit'>" . ($entry['debit_amount'] > 0 ? number_format($entry['debit_amount'], 2) : '-') . "</td>
                <td class='credit'>" . ($entry['credit_amount'] > 0 ? number_format($entry['credit_amount'], 2) : '-') . "</td>
                <td>" . htmlspecialchars($entry['status']) . "</td>
            </tr>";
        }
        
        echo "</tbody>
            <tfoot>
                <tr class='total'>
                    <td colspan='5'><strong>TOTALS:</strong></td>
                    <td class='debit'><strong>" . number_format($total_debit, 2) . "</strong></td>
                    <td class='credit'><strong>" . number_format($total_credit, 2) . "</strong></td>
                    <td></td>
                </tr>
                <tr class='total'>
                    <td colspan='5'><strong>NET BALANCE:</strong></td>
                    <td colspan='3'><strong>" . number_format(abs($net_balance), 2) . " (" . ($net_balance >= 0 ? 'Debit' : 'Credit') . ")</strong></td>
                </tr>
            </tfoot>
        </table>";
    }
    
    echo "<br><br>";
    echo "<p><em>Generated by: " . htmlspecialchars($_SESSION['username'] ?? 'System') . "</em></p>";
    
    echo "</body></html>";
    exit;
}

function exportToCSV($company, $account_info, $entries, $sub_accounts, $third_level_breakdown, $total_debit, $total_credit, $net_balance, $start_date, $end_date) {
    $filename = 'Account_Details_' . $account_info['account_code'] . '_' . date('Ymd_His') . '.csv';
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment;filename="' . $filename . '"');
    
    $output = fopen('php://output', 'w');
    
    // Header
    fputcsv($output, ['ACCOUNT DETAILS REPORT']);
    fputcsv($output, [$company['company_name']]);
    fputcsv($output, ['Period: ' . date('d/m/Y', strtotime($start_date)) . ' to ' . date('d/m/Y', strtotime($end_date))]);
    fputcsv($output, ['Generated on: ' . date('d/m/Y H:i:s')]);
    fputcsv($output, []);
    
    // Account Information
    fputcsv($output, ['ACCOUNT INFORMATION']);
    fputcsv($output, ['Account Code:', $account_info['account_code']]);
    fputcsv($output, ['Account Name:', $account_info['account_name']]);
    fputcsv($output, ['Account Type:', ucfirst($account_info['account_type'])]);
    fputcsv($output, ['Normal Balance:', ucfirst($account_info['normal_balance'])]);
    if (!empty($account_info['account_description'])) {
        fputcsv($output, ['Description:', $account_info['account_description']]);
    }
    fputcsv($output, []);
    
    // Summary Statistics
    fputcsv($output, ['SUMMARY STATISTICS']);
    fputcsv($output, ['Total Debit Transactions:', count(array_filter($entries, function($e) { return $e['debit_amount'] > 0; }))]);
    fputcsv($output, ['Total Credit Transactions:', count(array_filter($entries, function($e) { return $e['credit_amount'] > 0; }))]);
    fputcsv($output, ['Total Transactions:', count($entries)]);
    fputcsv($output, ['Total Debit Amount:', number_format($total_debit, 2)]);
    fputcsv($output, ['Total Credit Amount:', number_format($total_credit, 2)]);
    fputcsv($output, ['Net Balance:', number_format(abs($net_balance), 2) . ' (' . ($net_balance >= 0 ? 'Debit' : 'Credit') . ')']);
    fputcsv($output, []);
    
    // Third Level Breakdown
    if (!empty($third_level_breakdown)) {
        fputcsv($output, ['THIRD LEVEL ACCOUNT BREAKDOWN']);
        fputcsv($output, ['Account Code', 'Account Name', 'Debit Total', 'Credit Total', 'Net Balance', 'Entries Count']);
        foreach ($third_level_breakdown as $code => $data) {
            $sub_net = $data['debit'] - $data['credit'];
            $entries_count = count($data['entries']);
            fputcsv($output, [
                $code,
                $data['name'],
                number_format($data['debit'], 2),
                number_format($data['credit'], 2),
                number_format(abs($sub_net), 2) . ' (' . ($sub_net >= 0 ? 'Debit' : 'Credit') . ')',
                $entries_count
            ]);
        }
        fputcsv($output, []);
    }
    
    // Sub-Accounts
    if (!empty($sub_accounts)) {
        fputcsv($output, ['SUB-ACCOUNTS IN THIS GROUP']);
        fputcsv($output, ['Account Code', 'Account Name', 'Type', 'Normal Balance', 'Level']);
        foreach ($sub_accounts as $sub) {
            fputcsv($output, [
                $sub['account_code'],
                $sub['account_name'],
                ucfirst($sub['account_type']),
                ucfirst($sub['normal_balance']),
                $sub['level']
            ]);
        }
        fputcsv($output, []);
    }
    
    // Transaction Details
    fputcsv($output, ['TRANSACTION DETAILS (' . count($entries) . ' entries)']);
    if (empty($entries)) {
        fputcsv($output, ['No transactions found for this period.']);
    } else {
        fputcsv($output, ['Date', 'Reference', 'Description', 'Entity', 'Type', 'Debit Amount', 'Credit Amount', 'Status', 'Created By']);
        foreach ($entries as $entry) {
            fputcsv($output, [
                $entry['transaction_date_display'],
                $entry['reference_no'],
                $entry['description'],
                $entry['entity_name'] ?? '-',
                $entry['reference_type'],
                $entry['debit_amount'] > 0 ? number_format($entry['debit_amount'], 2) : '',
                $entry['credit_amount'] > 0 ? number_format($entry['credit_amount'], 2) : '',
                $entry['status'],
                $entry['created_by_username']
            ]);
        }
        
        // Totals
        fputcsv($output, []);
        fputcsv($output, ['TOTALS', '', '', '', '', number_format($total_debit, 2), number_format($total_credit, 2), '', '']);
        fputcsv($output, ['NET BALANCE', '', '', '', '', number_format(abs($net_balance), 2) . ' (' . ($net_balance >= 0 ? 'Debit' : 'Credit') . ')', '', '', '']);
    }
    
    fputcsv($output, []);
    fputcsv($output, ['Generated by:', $_SESSION['username'] ?? 'System']);
    
    fclose($output);
    exit;
}
?>