<?php
require_once '../config/config.php';
require_once '../auth/auth_middleware.php';

// Require login
require_login();

// Include TCPDF library
require_once('../tcpdf/tcpdf.php');

$db = getDBConnection();

// Get filter parameters
$filters = [
    'account_id' => $_POST['account_id'] ?? '',
    'reference_no' => $_POST['reference_no'] ?? '',
    'reference_type' => $_POST['reference_type'] ?? '',
    'period_from' => $_POST['period_from'] ?? '',
    'period_to' => $_POST['period_to'] ?? '',
    'orientation' => $_POST['orientation'] ?? 'landscape',
    'watermark' => $_POST['watermark'] ?? 'no',
    'print_mode' => $_POST['print_mode'] ?? '0',
    'export_type' => $_POST['export_type'] ?? 'pdf'
];

// Generate the General Ledger Report
generateGeneralLedgerReport($db, $filters);

function generateGeneralLedgerReport($db, $filters) {
    // Get company details from database - using correct column names from your database
    $company_stmt = $db->query("SELECT 
        company_name, 
        address, 
        phone, 
        mobile, 
        email,
        division,
        exchange,
        registration_number
        FROM companies WHERE status = 'active' LIMIT 1");
    $company = $company_stmt->fetch();
    
    // Set default values if company not found
    $company_name = $company ? $company['company_name'] : 'Victory Financial Services Limited';
    $company_address = $company ? $company['address'] : 'ATC HOUSE, OHIO STREET / GARDEN AVENUE PO BOX 8706 DAR ES SALAAM';
    $company_phone = $company ? $company['phone'] : '+255 22 2112091';
    $company_mobile = $company ? $company['mobile'] : '+255 788 284 540';
    $company_email = $company ? $company['email'] : 'info@vfsl.co.tz';
    $company_division = $company ? $company['division'] : 'STOCK BROKING';
    $company_exchange = $company ? $company['exchange'] : 'Dar es Salaam Stock Exchange';
    $company_registration = $company ? $company['registration_number'] : 'REG: 123456';
    
    // Set default TIN and VAT values since they don't exist in the table
    $company_tin = 'TIN: 123-456-789';
    $company_vat = 'VAT: 123456789';
    
    // Build WHERE clause based on filters
    $where_conditions = ["1=1"];
    $params = [];
    
    if ($filters['account_id']) {
        $where_conditions[] = "gl.account_id = :account_id";
        $params[':account_id'] = $filters['account_id'];
    }
    
    if ($filters['reference_no']) {
        $where_conditions[] = "gl.reference_no = :reference_no";
        $params[':reference_no'] = $filters['reference_no'];
    }
    
    if ($filters['reference_type']) {
        $where_conditions[] = "gl.reference_type = :reference_type";
        $params[':reference_type'] = $filters['reference_type'];
    }
    
    if ($filters['period_from']) {
        $where_conditions[] = "gl.transaction_date >= :period_from";
        $params[':period_from'] = $filters['period_from'];
    }
    
    if ($filters['period_to']) {
        $where_conditions[] = "gl.transaction_date <= :period_to";
        $params[':period_to'] = $filters['period_to'];
    }
    
    $where_clause = '';
    if (!empty($where_conditions)) {
        $where_clause = 'WHERE ' . implode(' AND ', $where_conditions);
    }
    
    // Get General Ledger entries with account details
    $ledger_query = "
        SELECT 
            gl.*,
            coa.account_code,
            coa.account_name,
            coa.account_type,
            coa.normal_balance,
            u.full_name as created_by_name
        FROM general_ledger gl
        LEFT JOIN chart_of_accounts coa ON gl.account_id = coa.id
        LEFT JOIN users u ON gl.created_by = u.username
        $where_clause
        ORDER BY gl.transaction_date, gl.created_at
    ";
    
    $ledger_stmt = $db->prepare($ledger_query);
    foreach ($params as $key => $value) {
        $ledger_stmt->bindValue($key, $value);
    }
    $ledger_stmt->execute();
    $ledger_entries = $ledger_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get account summary (group by account)
    $account_summary_query = "
        SELECT 
            gl.account_id,
            coa.account_code,
            coa.account_name,
            coa.account_type,
            coa.normal_balance,
            SUM(gl.debit_amount) as total_debit,
            SUM(gl.credit_amount) as total_credit,
            CASE 
                WHEN coa.normal_balance = 'debit' THEN 
                    (SUM(gl.debit_amount) - SUM(gl.credit_amount))
                ELSE 
                    (SUM(gl.credit_amount) - SUM(gl.debit_amount))
            END as net_balance
        FROM general_ledger gl
        LEFT JOIN chart_of_accounts coa ON gl.account_id = coa.id
        $where_clause
        GROUP BY gl.account_id, coa.account_code, coa.account_name, coa.account_type, coa.normal_balance
        ORDER BY coa.account_code
    ";
    
    $account_summary_stmt = $db->prepare($account_summary_query);
    foreach ($params as $key => $value) {
        $account_summary_stmt->bindValue($key, $value);
    }
    $account_summary_stmt->execute();
    $account_summary = $account_summary_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get bank accounts summary
    $bank_accounts_query = "
        SELECT 
            ba.code,
            ba.account_name,
            ba.account_number,
            ba.bank_name,
            ba.current_balance,
            ba.currency
        FROM banks_accounts ba
        WHERE ba.status = 'active'
        ORDER BY ba.code
    ";
    
    $bank_accounts_stmt = $db->query($bank_accounts_query);
    $bank_accounts = $bank_accounts_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calculate totals
    $total_debit = 0;
    $total_credit = 0;
    foreach ($ledger_entries as $entry) {
        $total_debit += $entry['debit_amount'];
        $total_credit += $entry['credit_amount'];
    }
    
    $balance = $total_debit - $total_credit;
    
    // Create PDF
    $orientation = ($filters['orientation'] === 'portrait') ? 'P' : 'L';
    $pdf = new TCPDF($orientation, PDF_UNIT, 'A4', true, 'UTF-8', false);
    
    // Set document information
    $pdf->SetCreator(PDF_CREATOR);
    $pdf->SetAuthor($company_name);
    $pdf->SetTitle('General Ledger Report');
    $pdf->SetSubject('Financial Accounting Report');
    
    // Set default monospaced font
    $pdf->SetDefaultMonospacedFont(PDF_FONT_MONOSPACED);
    
    // Set margins
    $pdf->SetMargins(10, 15, 10);
    $pdf->SetHeaderMargin(5);
    $pdf->SetFooterMargin(10);
    
    // Remove default header/footer
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    
    // Set auto page breaks
    $pdf->SetAutoPageBreak(TRUE, 15);
    
    // Set font
    $pdf->SetFont('helvetica', '', 8);
    
    // Add a page
    $pdf->AddPage();
    
    // Add watermark if requested
    if ($filters['watermark'] === 'yes') {
        $pdf->SetFont('helvetica', 'B', 50);
        $pdf->SetTextColor(200, 200, 200);
        $pdf->SetAlpha(0.1);
        $text = 'CONFIDENTIAL';
        $x = ($orientation === 'L') ? 80 : 40;
        $pdf->RotatedText($x, 150, $text, 45);
        $pdf->SetAlpha(1);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetFont('helvetica', '', 8);
    }
    
    // Add company header
    $pdf->SetFont('helvetica', 'B', 14);
    $pdf->Cell(0, 6, strtoupper($company_name), 0, 1, 'C');
    $pdf->SetFont('helvetica', '', 10);
    $pdf->Cell(0, 5, 'Stock Broker / Dealer & Investment Advisor', 0, 1, 'C');
    $pdf->Cell(0, 5, 'Member of ' . $company_exchange, 0, 1, 'C');
    
    // Add address and contact info from database
    $pdf->SetFont('helvetica', '', 7);
    if (!empty($company_address)) {
        $pdf->Cell(0, 4, $company_address, 0, 1, 'C');
    }
    
    // Build contact info string
    $contact_info = '';
    if (!empty($company_phone)) {
        $contact_info .= 'Tel: ' . $company_phone;
    }
    if (!empty($company_mobile)) {
        if (!empty($contact_info)) $contact_info .= ' ';
        $contact_info .= 'Mob: ' . $company_mobile;
    }
    if (!empty($company_email)) {
        if (!empty($contact_info)) $contact_info .= ' ';
        $contact_info .= 'Email: ' . $company_email;
    }
    
    if (!empty($contact_info)) {
        $pdf->Cell(0, 4, $contact_info, 0, 1, 'C');
    }
    
    // Add company registration details
    $reg_info = '';
    if (!empty($company_tin)) {
        $reg_info .= $company_tin;
    }
    if (!empty($company_vat)) {
        if (!empty($reg_info)) $reg_info .= ' | ';
        $reg_info .= $company_vat;
    }
    if (!empty($company_registration)) {
        if (!empty($reg_info)) $reg_info .= ' | ';
        $reg_info .= 'REG: ' . $company_registration;
    }
    
    if (!empty($reg_info)) {
        $pdf->Cell(0, 4, $reg_info, 0, 1, 'C');
    }
    
    $pdf->Ln(3);
    
    // Report title and date
    $pdf->SetFont('helvetica', 'B', 11);
    $pdf->Cell(0, 5, 'Division : ' . strtoupper($company_division), 0, 1, 'L');
    
    $period_from_display = !empty($filters['period_from']) ? 
        date('d/m/Y', strtotime($filters['period_from'])) : 
        date('Y-01-01');
    $period_to_display = !empty($filters['period_to']) ? 
        date('d/m/Y', strtotime($filters['period_to'])) : 
        date('d/m/Y');
    
    $pdf->Cell(0, 5, 'GENERAL LEDGER REPORT FOR PERIOD ' . $period_from_display . ' TO ' . $period_to_display, 0, 1, 'C');
    
    $pdf->SetFont('helvetica', '', 9);
    $pdf->Cell(0, 4, 'Date : ' . date('d/m/Y') . ' : ' . date('H:i:s'), 0, 1, 'C');
    $pdf->Ln(3);
    
    // Add filter information
    $filters_applied = [];
    
    if (!empty($filters['account_id'])) {
        $account_name_stmt = $db->prepare("SELECT account_name FROM chart_of_accounts WHERE id = ?");
        $account_name_stmt->execute([$filters['account_id']]);
        $account_name = $account_name_stmt->fetch();
        $filters_applied[] = 'Account: ' . ($account_name ? $account_name['account_name'] : $filters['account_id']);
    }
    
    if (!empty($filters['reference_no'])) {
        $filters_applied[] = 'Reference No: ' . $filters['reference_no'];
    }
    
    if (!empty($filters['reference_type'])) {
        $filters_applied[] = 'Reference Type: ' . ucfirst($filters['reference_type']);
    }
    
    if (!empty($filters_applied)) {
        $pdf->SetFont('helvetica', 'I', 7);
        $pdf->Cell(0, 3, 'Filtered: ' . implode(' | ', $filters_applied), 0, 1, 'L');
        $pdf->Ln(1);
    }
    
    // Add bank accounts summary section
    if (!empty($bank_accounts)) {
        $pdf->SetFont('helvetica', 'B', 9);
        $pdf->Cell(0, 5, 'BANK ACCOUNTS SUMMARY', 0, 1, 'L');
        $pdf->Ln(2);
        
        // Bank accounts table header
        $pdf->SetFont('helvetica', 'B', 8);
        $bank_col_widths = [
            '#' => 5,
            'code' => 15,
            'bank' => 35,
            'account_name' => 40,
            'account_no' => 25,
            'currency' => 15,
            'balance' => 25
        ];
        
        $pdf->Cell($bank_col_widths['#'], 6, '#', 1, 0, 'C');
        $pdf->Cell($bank_col_widths['code'], 6, 'Code', 1, 0, 'C');
        $pdf->Cell($bank_col_widths['bank'], 6, 'Bank', 1, 0, 'C');
        $pdf->Cell($bank_col_widths['account_name'], 6, 'Account Name', 1, 0, 'C');
        $pdf->Cell($bank_col_widths['account_no'], 6, 'Account No', 1, 0, 'C');
        $pdf->Cell($bank_col_widths['currency'], 6, 'Currency', 1, 0, 'C');
        $pdf->Cell($bank_col_widths['balance'], 6, 'Current Balance', 1, 0, 'C');
        $pdf->Ln();
        
        // Bank accounts data
        $pdf->SetFont('helvetica', '', 7);
        $row_height = 5;
        $bank_counter = 1;
        $total_bank_balance = 0;
        
        foreach ($bank_accounts as $bank) {
            $total_bank_balance += $bank['current_balance'];
            
            $pdf->Cell($bank_col_widths['#'], $row_height, $bank_counter, 1, 0, 'C');
            $pdf->Cell($bank_col_widths['code'], $row_height, $bank['code'], 1, 0, 'C');
            $pdf->Cell($bank_col_widths['bank'], $row_height, substr($bank['bank_name'], 0, 20), 1, 0, 'L');
            $pdf->Cell($bank_col_widths['account_name'], $row_height, substr($bank['account_name'], 0, 25), 1, 0, 'L');
            $pdf->Cell($bank_col_widths['account_no'], $row_height, $bank['account_number'], 1, 0, 'C');
            $pdf->Cell($bank_col_widths['currency'], $row_height, $bank['currency'], 1, 0, 'C');
            $pdf->Cell($bank_col_widths['balance'], $row_height, number_format($bank['current_balance'], 2), 1, 0, 'R');
            $pdf->Ln();
            $bank_counter++;
        }
        
        // Bank accounts total
        $pdf->SetFont('helvetica', 'B', 7);
        $total_bank_label_width = $bank_col_widths['#'] + $bank_col_widths['code'] + $bank_col_widths['bank'] + $bank_col_widths['account_name'] + $bank_col_widths['account_no'] + $bank_col_widths['currency'];
        $pdf->Cell($total_bank_label_width, $row_height, 'TOTAL BANK BALANCE', 1, 0, 'R');
        $pdf->Cell($bank_col_widths['balance'], $row_height, number_format($total_bank_balance, 2), 1, 0, 'R');
        $pdf->Ln();
        
        $pdf->Ln(5);
    }
    
    // Add Account Summary section
    if (!empty($account_summary)) {
        $pdf->SetFont('helvetica', 'B', 9);
        $pdf->Cell(0, 5, 'ACCOUNT SUMMARY', 0, 1, 'L');
        $pdf->Ln(2);
        
        // Account summary table header
        $pdf->SetFont('helvetica', 'B', 8);
        $summary_col_widths = [
            '#' => 5,
            'code' => 15,
            'name' => ($orientation === 'L') ? 60 : 45,
            'type' => 20,
            'debit' => 25,
            'credit' => 25,
            'balance' => 25
        ];
        
        $pdf->Cell($summary_col_widths['#'], 6, '#', 1, 0, 'C');
        $pdf->Cell($summary_col_widths['code'], 6, 'Account Code', 1, 0, 'C');
        $pdf->Cell($summary_col_widths['name'], 6, 'Account Name', 1, 0, 'C');
        $pdf->Cell($summary_col_widths['type'], 6, 'Type', 1, 0, 'C');
        $pdf->Cell($summary_col_widths['debit'], 6, 'Total Debit', 1, 0, 'C');
        $pdf->Cell($summary_col_widths['credit'], 6, 'Total Credit', 1, 0, 'C');
        $pdf->Cell($summary_col_widths['balance'], 6, 'Net Balance', 1, 0, 'C');
        $pdf->Ln();
        
        // Account summary data
        $pdf->SetFont('helvetica', '', 7);
        $summary_counter = 1;
        $summary_total_debit = 0;
        $summary_total_credit = 0;
        
        foreach ($account_summary as $account) {
            $summary_total_debit += $account['total_debit'];
            $summary_total_credit += $account['total_credit'];
            
            $account_name_chars = floor($summary_col_widths['name'] / 1.5);
            $account_name_display = strlen($account['account_name']) > $account_name_chars ? 
                substr($account['account_name'], 0, $account_name_chars-3) . '...' : $account['account_name'];
            
            $pdf->Cell($summary_col_widths['#'], $row_height, $summary_counter, 1, 0, 'C');
            $pdf->Cell($summary_col_widths['code'], $row_height, $account['account_code'], 1, 0, 'C');
            $pdf->Cell($summary_col_widths['name'], $row_height, $account_name_display, 1, 0, 'L');
            $pdf->Cell($summary_col_widths['type'], $row_height, ucfirst($account['account_type']), 1, 0, 'C');
            $pdf->Cell($summary_col_widths['debit'], $row_height, number_format($account['total_debit'], 2), 1, 0, 'R');
            $pdf->Cell($summary_col_widths['credit'], $row_height, number_format($account['total_credit'], 2), 1, 0, 'R');
            
            // Highlight negative balances
            if ($account['net_balance'] < 0) {
                $pdf->SetTextColor(255, 0, 0);
            }
            $pdf->Cell($summary_col_widths['balance'], $row_height, number_format($account['net_balance'], 2), 1, 0, 'R');
            $pdf->SetTextColor(0, 0, 0);
            $pdf->Ln();
            $summary_counter++;
        }
        
        // Account summary totals
        $pdf->SetFont('helvetica', 'B', 7);
        $summary_total_label_width = $summary_col_widths['#'] + $summary_col_widths['code'] + $summary_col_widths['name'] + $summary_col_widths['type'];
        $pdf->Cell($summary_total_label_width, $row_height, 'TOTAL', 1, 0, 'C');
        $pdf->Cell($summary_col_widths['debit'], $row_height, number_format($summary_total_debit, 2), 1, 0, 'R');
        $pdf->Cell($summary_col_widths['credit'], $row_height, number_format($summary_total_credit, 2), 1, 0, 'R');
        
        // Check if debits equal credits (accounting equation)
        if (abs($summary_total_debit - $summary_total_credit) < 0.01) {
            $pdf->SetTextColor(0, 128, 0);
            $pdf->Cell($summary_col_widths['balance'], $row_height, 'BALANCED', 1, 0, 'C');
        } else {
            $pdf->SetTextColor(255, 0, 0);
            $pdf->Cell($summary_col_widths['balance'], $row_height, 'UNBALANCED', 1, 0, 'C');
        }
        $pdf->SetTextColor(0, 0, 0);
        $pdf->Ln();
        
        $pdf->Ln(5);
    }
    
    // Add General Ledger Transactions section
    if (!empty($ledger_entries)) {
        $pdf->SetFont('helvetica', 'B', 9);
        $pdf->Cell(0, 5, 'GENERAL LEDGER TRANSACTIONS', 0, 1, 'L');
        $pdf->Ln(2);
        
        // Check if we need a new page
        if ($pdf->GetY() > 200) {
            $pdf->AddPage();
        }
        
        // General ledger table header
        $pdf->SetFont('helvetica', 'B', 8);
        $ledger_col_widths = [
            '#' => 5,
            'date' => 15,
            'account_code' => 12,
            'account_name' => ($orientation === 'L') ? 40 : 30,
            'description' => ($orientation === 'L') ? 50 : 35,
            'reference' => 20,
            'debit' => 20,
            'credit' => 20,
            'balance' => 25,
            'created_by' => 20
        ];
        
        $pdf->Cell($ledger_col_widths['#'], 6, '#', 1, 0, 'C');
        $pdf->Cell($ledger_col_widths['date'], 6, 'Date', 1, 0, 'C');
        $pdf->Cell($ledger_col_widths['account_code'], 6, 'Account', 1, 0, 'C');
        $pdf->Cell($ledger_col_widths['account_name'], 6, 'Account Name', 1, 0, 'C');
        $pdf->Cell($ledger_col_widths['description'], 6, 'Description', 1, 0, 'C');
        $pdf->Cell($ledger_col_widths['reference'], 6, 'Reference', 1, 0, 'C');
        $pdf->Cell($ledger_col_widths['debit'], 6, 'Debit', 1, 0, 'C');
        $pdf->Cell($ledger_col_widths['credit'], 6, 'Credit', 1, 0, 'C');
        $pdf->Cell($ledger_col_widths['balance'], 6, 'Balance', 1, 0, 'C');
        $pdf->Cell($ledger_col_widths['created_by'], 6, 'Created By', 1, 0, 'C');
        $pdf->Ln();
        
        // General ledger data
        $pdf->SetFont('helvetica', '', 7);
        $ledger_counter = 1;
        $running_balance = 0;
        
        foreach ($ledger_entries as $entry) {
            $running_balance += ($entry['debit_amount'] - $entry['credit_amount']);
            
            $account_name_chars = floor($ledger_col_widths['account_name'] / 1.5);
            $account_name_display = strlen($entry['account_name']) > $account_name_chars ? 
                substr($entry['account_name'], 0, $account_name_chars-3) . '...' : $entry['account_name'];
            
            $description_chars = floor($ledger_col_widths['description'] / 1.5);
            $description_display = strlen($entry['description']) > $description_chars ? 
                substr($entry['description'], 0, $description_chars-3) . '...' : $entry['description'];
            
            $created_by_chars = floor($ledger_col_widths['created_by'] / 1.5);
            $created_by_display = strlen($entry['created_by_name']) > $created_by_chars ? 
                substr($entry['created_by_name'], 0, $created_by_chars-3) . '...' : ($entry['created_by_name'] ?? 'System');
            
            $pdf->Cell($ledger_col_widths['#'], $row_height, $ledger_counter, 1, 0, 'C');
            $pdf->Cell($ledger_col_widths['date'], $row_height, date('d/m/Y', strtotime($entry['transaction_date'])), 1, 0, 'C');
            $pdf->Cell($ledger_col_widths['account_code'], $row_height, $entry['account_code'], 1, 0, 'C');
            $pdf->Cell($ledger_col_widths['account_name'], $row_height, $account_name_display, 1, 0, 'L');
            $pdf->Cell($ledger_col_widths['description'], $row_height, $description_display, 1, 0, 'L');
            $pdf->Cell($ledger_col_widths['reference'], $row_height, substr($entry['reference_no'], 0, 10), 1, 0, 'C');
            
            // Highlight debit amounts
            if ($entry['debit_amount'] > 0) {
                $pdf->SetTextColor(0, 128, 0);
            }
            $pdf->Cell($ledger_col_widths['debit'], $row_height, number_format($entry['debit_amount'], 2), 1, 0, 'R');
            $pdf->SetTextColor(0, 0, 0);
            
            // Highlight credit amounts
            if ($entry['credit_amount'] > 0) {
                $pdf->SetTextColor(255, 0, 0);
            }
            $pdf->Cell($ledger_col_widths['credit'], $row_height, number_format($entry['credit_amount'], 2), 1, 0, 'R');
            $pdf->SetTextColor(0, 0, 0);
            
            // Highlight running balance
            if ($running_balance < 0) {
                $pdf->SetTextColor(255, 0, 0);
            }
            $pdf->Cell($ledger_col_widths['balance'], $row_height, number_format($running_balance, 2), 1, 0, 'R');
            $pdf->SetTextColor(0, 0, 0);
            
            $pdf->Cell($ledger_col_widths['created_by'], $row_height, $created_by_display, 1, 0, 'C');
            $pdf->Ln();
            $ledger_counter++;
            
            // Check if we need a new page
            if ($pdf->GetY() > 250) {
                $pdf->AddPage();
                
                // Redraw headers on new page
                $pdf->SetFont('helvetica', 'B', 8);
                $pdf->Cell($ledger_col_widths['#'], 6, '#', 1, 0, 'C');
                $pdf->Cell($ledger_col_widths['date'], 6, 'Date', 1, 0, 'C');
                $pdf->Cell($ledger_col_widths['account_code'], 6, 'Account', 1, 0, 'C');
                $pdf->Cell($ledger_col_widths['account_name'], 6, 'Account Name', 1, 0, 'C');
                $pdf->Cell($ledger_col_widths['description'], 6, 'Description', 1, 0, 'C');
                $pdf->Cell($ledger_col_widths['reference'], 6, 'Reference', 1, 0, 'C');
                $pdf->Cell($ledger_col_widths['debit'], 6, 'Debit', 1, 0, 'C');
                $pdf->Cell($ledger_col_widths['credit'], 6, 'Credit', 1, 0, 'C');
                $pdf->Cell($ledger_col_widths['balance'], 6, 'Balance', 1, 0, 'C');
                $pdf->Cell($ledger_col_widths['created_by'], 6, 'Created By', 1, 0, 'C');
                $pdf->Ln();
                
                $pdf->SetFont('helvetica', '', 7);
            }
        }
        
        // Ledger totals
        $pdf->SetFont('helvetica', 'B', 7);
        $ledger_total_label_width = $ledger_col_widths['#'] + $ledger_col_widths['date'] + $ledger_col_widths['account_code'] + $ledger_col_widths['account_name'] + $ledger_col_widths['description'] + $ledger_col_widths['reference'];
        $pdf->Cell($ledger_total_label_width, $row_height, 'TOTAL', 1, 0, 'R');
        $pdf->Cell($ledger_col_widths['debit'], $row_height, number_format($total_debit, 2), 1, 0, 'R');
        $pdf->Cell($ledger_col_widths['credit'], $row_height, number_format($total_credit, 2), 1, 0, 'R');
        $pdf->Cell($ledger_col_widths['balance'], $row_height, number_format($balance, 2), 1, 0, 'R');
        $pdf->Cell($ledger_col_widths['created_by'], $row_height, '', 1, 0, 'C');
        $pdf->Ln();
    } else {
        $pdf->SetFont('helvetica', '', 8);
        $pdf->Cell(0, 8, 'No general ledger entries found for the selected filters.', 0, 1, 'C');
    }
    
    // Add summary notes
    $pdf->Ln(5);
    $pdf->SetFont('helvetica', 'I', 7);
    $pdf->MultiCell(0, 3, 'Accounting Summary Notes:', 0, 'L');
    $pdf->MultiCell(0, 3, '1. Debit amounts are positive amounts recorded on the left side of an account', 0, 'L');
    $pdf->MultiCell(0, 3, '2. Credit amounts are positive amounts recorded on the right side of an account', 0, 'L');
    $pdf->MultiCell(0, 3, '3. Asset and Expense accounts normally have debit balances', 0, 'L');
    $pdf->MultiCell(0, 3, '4. Liability, Equity, and Revenue accounts normally have credit balances', 0, 'L');
    $pdf->MultiCell(0, 3, '5. The accounting equation must always balance: Assets = Liabilities + Equity', 0, 'L');
    
    // Add disclaimer at the bottom
    $pdf->SetY(-40);
    $pdf->SetFont('helvetica', 'I', 6);
    $disclaimer = $company_name . ' has prepared this Report solely for informational purposes. ' . 
        $company_name . ' does not represent warrant or guarantee that the Reports are accurate. ' . 
        $company_name . ' disclaims liability for any direct indirect punitive special consequential or incidental damages related to the Reports or the use of the Reports. ' .
        'This disclaimer applies to the Reports in their entirety irrespective of whether the Reports are used or viewed in whole or in part.';
    $pdf->MultiCell(0, 3, $disclaimer, 0, 'L');
    
    // Add footer with page number
    $pdf->SetY(-15);
    $pdf->SetFont('helvetica', 'I', 7);
    $pdf->Cell(0, 10, 'Page ' . $pdf->getAliasNumPage() . ' of ' . $pdf->getAliasNbPages(), 0, 0, 'C');
    
    // Output the PDF
    $filename = 'General_Ledger_Report_' . date('d_m_Y_H_i_s') . '.pdf';
    
    if ($filters['print_mode'] === '1') {
        $pdf->Output($filename, 'I');
    } elseif ($filters['export_type'] === 'excel') {
        $pdf->Output($filename, 'D');
    } else {
        $pdf->Output($filename, 'I');
    }
}
?>