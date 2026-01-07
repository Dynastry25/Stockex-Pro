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
    'client' => $_POST['client'] ?? '',
    'agent' => $_POST['agent'] ?? '',
    'broker' => $_POST['broker'] ?? '',
    'security' => $_POST['security'] ?? '',
    'trade_type' => $_POST['trade_type'] ?? '',
    'user' => $_POST['user'] ?? '',
    'status' => $_POST['status'] ?? 'active',
    'period_from' => $_POST['period_from'] ?? '',
    'period_to' => $_POST['period_to'] ?? '',
    'report_by' => $_POST['report_by'] ?? 'month',
    'report_type' => $_POST['report_type'] ?? 'detailed',
    'orientation' => $_POST['orientation'] ?? 'landscape',
    'watermark' => $_POST['watermark'] ?? 'no',
    'report_name' => $_POST['report_name'] ?? 'transaction_summary',
    'print_mode' => $_POST['print_mode'] ?? '0',
    'export_type' => $_POST['export_type'] ?? 'pdf'
];

// Generate the Bonds Transaction Summary Report
generateBondsTransactionSummaryReport($db, $filters);

function generateBondsTransactionSummaryReport($db, $filters) {
    // Get company details from database
    $company_stmt = $db->query("SELECT 
        company_name, 
        address, 
        phone, 
        mobile, 
        email,
        division,
        exchange
        FROM companies WHERE status = 'active' LIMIT 1");
    $company = $company_stmt->fetch();
    
    // Set default values if company not found
    $company_name = $company ? $company['company_name'] : 'NEOVAM Limited';
    $company_address = $company ? $company['address'] : 'ATC HOUSE, OHIO STREET / GARDEN AVENUE PO BOX 8706 DAR ES SALAAM';
    $company_phone = $company ? $company['phone'] : '+255 22 2112091';
    $company_mobile = $company ? $company['mobile'] : '+255 788 284 540';
    $company_email = $company ? $company['email'] : 'info@vfsl.co.tz';
    $company_division = $company ? $company['division'] : 'STOCK BROKING';
    $company_exchange = $company ? $company['exchange'] : 'Dar es Salaam Stock Exchange';
    
    // Get all brokers for checking client names against broker names
    $brokers_stmt = $db->query("SELECT broker_code, broker_name FROM brokers WHERE status = 'active'");
    $all_brokers = $brokers_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Create a lookup array for broker codes by broker name
    $broker_codes = [];
    foreach ($all_brokers as $broker) {
        $broker_codes[$broker['broker_name']] = $broker['broker_code'];
    }
    
    // Build WHERE clause based on filters - BONDS ONLY
    $where_conditions = ["t.status = 'active'", "(t.asset_class = 'bond' OR t.asset_class = 'treasury_bill' OR t.asset_class = 'corporate_bond')"];
    $params = [];
    
    if ($filters['client']) {
        $where_conditions[] = "t.client_cds_account = :client";
        $params[':client'] = $filters['client'];
    }
    
    if ($filters['broker']) {
        $where_conditions[] = "t.broker_name = :broker";
        $params[':broker'] = $filters['broker'];
    }
    
    if ($filters['security']) {
        $where_conditions[] = "t.security_id = :security";
        $params[':security'] = $filters['security'];
    }
    
    if ($filters['trade_type']) {
        $where_conditions[] = "t.trade_side = :trade_type";
        $params[':trade_type'] = strtolower($filters['trade_type']);
    }
    
    if ($filters['period_from']) {
        $where_conditions[] = "t.trade_date >= :period_from";
        $params[':period_from'] = $filters['period_from'];
    }
    
    if ($filters['period_to']) {
        $where_conditions[] = "t.trade_date <= :period_to";
        $params[':period_to'] = $filters['period_to'];
    }
    
    $where_clause = '';
    if (!empty($where_conditions)) {
        $where_clause = 'WHERE ' . implode(' AND ', $where_conditions);
    }
    
    // Get all trades for the detailed transaction list - BONDS ONLY
    $trades_query = "
        SELECT 
            t.*,
            b.broker_code,
            ROW_NUMBER() OVER (ORDER BY t.trade_date, t.created_at) as row_num
        FROM trades t
        LEFT JOIN brokers b ON t.broker_name = b.broker_name
        $where_clause
        ORDER BY t.trade_date, t.created_at
    ";
    
    $trades_stmt = $db->prepare($trades_query);
    foreach ($params as $key => $value) {
        $trades_stmt->bindValue($key, $value);
    }
    $trades_stmt->execute();
    $trades = $trades_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Initialize totals for the transaction table
    $seller_totals = [
        'clients' => 0,    // (a) Non-broker clients on seller side
        'brokers' => 0,    // (b) Brokers on seller side
        'total' => 0       // (c) Total seller consideration (a+b)
    ];
    
    $buyer_totals = [
        'clients' => 0,    // (d) Non-broker clients on buyer side
        'brokers' => 0,    // (e) Brokers on buyer side
        'total' => 0       // (f) Total buyer consideration (d+e)
    ];
    
    // Process trades to calculate totals and prepare for display
    $display_trades = [];
    foreach ($trades as $trade) {
        $display_trade = $trade;
        
        // Check if client_name is a broker
        $is_client_broker = isset($broker_codes[$trade['client_name']]);
        $is_counterparty_broker = isset($broker_codes[$trade['counterparty_name']]);
        
        if ($trade['trade_side'] === 'sell') {
            // SELLER side: This client is selling
            $seller_ac = $is_client_broker ? $broker_codes[$trade['client_name']] : $trade['client_cds_account'];
            $seller_name = $trade['client_name'];
            $seller_consideration = $trade['consideration'];
            
            // BUYER side: Counterparty is buying
            $buyer_ac = $is_counterparty_broker ? ($broker_codes[$trade['counterparty_name']] ?? '') : ($trade['counterparty_cds_account'] ?? '');
            $buyer_name = $trade['counterparty_name'];
            $buyer_consideration = $trade['consideration'];
            
            // Update seller totals
            if ($is_client_broker) {
                $seller_totals['brokers'] += $seller_consideration;
            } else {
                $seller_totals['clients'] += $seller_consideration;
            }
            $seller_totals['total'] += $seller_consideration;
            
            // Update buyer totals
            if ($is_counterparty_broker) {
                $buyer_totals['brokers'] += $buyer_consideration;
            } else {
                $buyer_totals['clients'] += $buyer_consideration;
            }
            $buyer_totals['total'] += $buyer_consideration;
            
        } else {
            // BUYER side: This client is buying
            $buyer_ac = $is_client_broker ? $broker_codes[$trade['client_name']] : $trade['client_cds_account'];
            $buyer_name = $trade['client_name'];
            $buyer_consideration = $trade['consideration'];
            
            // SELLER side: Counterparty is selling
            $seller_ac = $is_counterparty_broker ? ($broker_codes[$trade['counterparty_name']] ?? '') : ($trade['counterparty_cds_account'] ?? '');
            $seller_name = $trade['counterparty_name'];
            $seller_consideration = $trade['consideration'];
            
            // Update buyer totals
            if ($is_client_broker) {
                $buyer_totals['brokers'] += $buyer_consideration;
            } else {
                $buyer_totals['clients'] += $buyer_consideration;
            }
            $buyer_totals['total'] += $buyer_consideration;
            
            // Update seller totals
            if ($is_counterparty_broker) {
                $seller_totals['brokers'] += $seller_consideration;
            } else {
                $seller_totals['clients'] += $seller_consideration;
            }
            $seller_totals['total'] += $seller_consideration;
        }
        
        // Add formatted data for display
        $display_trade['seller_ac'] = $seller_ac;
        $display_trade['seller_name'] = $seller_name;
        $display_trade['seller_consideration'] = $seller_consideration;
        $display_trade['buyer_ac'] = $buyer_ac;
        $display_trade['buyer_name'] = $buyer_name;
        $display_trade['buyer_consideration'] = $buyer_consideration;
        $display_trade['is_seller_broker'] = isset($broker_codes[$seller_name]);
        $display_trade['is_buyer_broker'] = isset($broker_codes[$buyer_name]);
        
        $display_trades[] = $display_trade;
    }
    
    // Calculate balance
    $balance = $seller_totals['total'] - $buyer_totals['total'];
    
    // Create PDF
    $orientation = ($filters['orientation'] === 'portrait') ? 'P' : 'L';
    $pdf = new TCPDF($orientation, PDF_UNIT, 'A4', true, 'UTF-8', false);
    
    // Set document information
    $pdf->SetCreator(PDF_CREATOR);
    $pdf->SetAuthor($company_name);
    $pdf->SetTitle('Bonds & Treasury Bills Transactions Summary Report');
    $pdf->SetSubject('Bonds Transactions Report');
    
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
    
    $pdf->Ln(3);
    
    // Report title and date
    $pdf->SetFont('helvetica', 'B', 11);
    $pdf->Cell(0, 5, 'Division : ' . strtoupper($company_division), 0, 1, 'L');
    
    $trade_date_display = !empty($filters['period_to']) ? 
        date('d/m/Y', strtotime($filters['period_to'])) : 
        date('d/m/Y');
    
    $pdf->Cell(0, 5, 'BONDS & TREASURY BILLS TRANSACTIONS SUMMARY REPORT FOR TRADES ON ' . $trade_date_display, 0, 1, 'C');
    
    $pdf->SetFont('helvetica', '', 9);
    $pdf->Cell(0, 4, 'Date : ' . date('d/m/Y') . ' : ' . date('H:i:s'), 0, 1, 'C');
    $pdf->Ln(3);
    
    // Add filter information
    $filters_applied = [];
    
    if (!empty($filters['period_from']) && !empty($filters['period_to'])) {
        $filters_applied[] = 'Period: ' . date('d/m/Y', strtotime($filters['period_from'])) . ' to ' . date('d/m/Y', strtotime($filters['period_to']));
    } elseif (!empty($filters['period_from'])) {
        $filters_applied[] = 'From: ' . date('d/m/Y', strtotime($filters['period_from']));
    } elseif (!empty($filters['period_to'])) {
        $filters_applied[] = 'To: ' . date('d/m/Y', strtotime($filters['period_to']));
    }
    
    if (!empty($filters['broker'])) {
        $filters_applied[] = 'Broker: ' . $filters['broker'];
    }
    
    if (!empty($filters['client'])) {
        $client_stmt = $db->prepare("SELECT client_name FROM trades WHERE client_cds_account = ? LIMIT 1");
        $client_stmt->execute([$filters['client']]);
        $client = $client_stmt->fetch();
        $client_name = $client ? $client['client_name'] : $filters['client'];
        $filters_applied[] = 'Client: ' . $client_name;
    }
    
    if (!empty($filters['security'])) {
        $filters_applied[] = 'Security: ' . $filters['security'];
    }
    
    if (!empty($filters['trade_type'])) {
        $filters_applied[] = 'Type: ' . $filters['trade_type'];
    }
    
    if (!empty($filters_applied)) {
        $pdf->SetFont('helvetica', 'I', 7);
        $pdf->Cell(0, 3, 'Filtered: ' . implode(' | ', $filters_applied), 0, 1, 'L');
        $pdf->Ln(1);
    }
    
    // Transaction table - Calculate dynamic widths
    $page_width = $orientation === 'L' ? 277 : 190; // A4 width in mm minus margins
    $fixed_cols = 5; // #, TRADEREF, SECURITY, PRICE, QUANTITY
    $variable_cols = 6; // 3 seller cols + 3 buyer cols
    
    // Fixed column widths (smaller)
    $col_widths = [
        '#' => 5,
        'traderef' => 18,
        'security' => 12,
        'price' => 12,
        'quantity' => 12
    ];
    
    // Calculate remaining width for variable columns
    $fixed_width = array_sum($col_widths);
    $remaining_width = $page_width - $fixed_width;
    
    // Distribute remaining width between seller and buyer columns
    $seller_cols_width = $remaining_width * 0.45; // 45% for seller
    $buyer_cols_width = $remaining_width * 0.45;  // 45% for buyer
    $middle_gap = $remaining_width * 0.10;        // 10% gap
    
    // Seller columns (3 columns)
    $seller_ac_width = $seller_cols_width * 0.2;   // 20% for A/C
    $seller_name_width = $seller_cols_width * 0.5; // 50% for NAME
    $seller_cons_width = $seller_cols_width * 0.3; // 30% for CONSIDERATION
    
    // Buyer columns (3 columns)
    $buyer_ac_width = $buyer_cols_width * 0.2;     // 20% for A/C
    $buyer_name_width = $buyer_cols_width * 0.5;   // 50% for NAME
    $buyer_cons_width = $buyer_cols_width * 0.3;   // 30% for CONSIDERATION
    
    // Add to col_widths array
    $col_widths['seller_ac'] = $seller_ac_width;
    $col_widths['seller_name'] = $seller_name_width;
    $col_widths['seller_consideration'] = $seller_cons_width;
    $col_widths['buyer_ac'] = $buyer_ac_width;
    $col_widths['buyer_name'] = $buyer_name_width;
    $col_widths['buyer_consideration'] = $buyer_cons_width;
    
    // Header row with SELLER and BUYER labels
    $pdf->SetFont('helvetica', 'B', 8);
    $seller_total_width = $col_widths['seller_ac'] + $col_widths['seller_name'] + $col_widths['seller_consideration'];
    $middle_width = $col_widths['traderef'] + $col_widths['security'] + $col_widths['price'] + $col_widths['quantity'];
    $buyer_total_width = $col_widths['buyer_ac'] + $col_widths['buyer_name'] + $col_widths['buyer_consideration'];
    
    $pdf->Cell($col_widths['#'], 6, '#', 1, 0, 'C');
    $pdf->Cell($seller_total_width, 6, 'SELLER', 1, 0, 'C');
    $pdf->Cell($middle_width, 6, '', 1, 0, 'C');
    $pdf->Cell($buyer_total_width, 6, 'BUYER', 1, 0, 'C');
    $pdf->Ln();
    
    // Sub-header row
    $pdf->Cell($col_widths['#'], 6, '', 1, 0, 'C');
    $pdf->Cell($col_widths['seller_ac'], 6, 'A/C', 1, 0, 'C');
    $pdf->Cell($col_widths['seller_name'], 6, 'NAME', 1, 0, 'C');
    $pdf->Cell($col_widths['seller_consideration'], 6, 'CONSIDERATION', 1, 0, 'C');
    $pdf->Cell($col_widths['traderef'], 6, 'TRADEREF', 1, 0, 'C');
    $pdf->Cell($col_widths['security'], 6, 'SECURITY', 1, 0, 'C');
    $pdf->Cell($col_widths['price'], 6, 'PRICE', 1, 0, 'C');
    $pdf->Cell($col_widths['quantity'], 6, 'QUANTITY', 1, 0, 'C');
    $pdf->Cell($col_widths['buyer_ac'], 6, 'A/C', 1, 0, 'C');
    $pdf->Cell($col_widths['buyer_name'], 6, 'NAME', 1, 0, 'C');
    $pdf->Cell($col_widths['buyer_consideration'], 6, 'CONSIDERATION', 1, 0, 'C');
    $pdf->Ln();
    
    // Transaction data
    $pdf->SetFont('helvetica', '', 7); // Even smaller font for data
    $row_height = 5;
    
    if (empty($display_trades)) {
        $total_cols_width = array_sum($col_widths);
        $pdf->Cell($total_cols_width, 8, 'No bond/treasury bill transactions found for the selected filters.', 1, 0, 'C');
        $pdf->Ln();
    } else {
        foreach ($display_trades as $index => $trade) {
            $row_num = $index + 1;
            
            // Format data to fit
            $seller_name = $trade['seller_name'];
            $buyer_name = $trade['buyer_name'];
            
            // Truncate if necessary
            $seller_ac_display = strlen($trade['seller_ac']) > 8 ? substr($trade['seller_ac'], 0, 8) : $trade['seller_ac'];
            $buyer_ac_display = strlen($trade['buyer_ac']) > 8 ? substr($trade['buyer_ac'], 0, 8) : $trade['buyer_ac'];
            
            // Calculate maximum characters for names based on column width
            $seller_name_chars = floor($col_widths['seller_name'] / 1.5);
            $buyer_name_chars = floor($col_widths['buyer_name'] / 1.5);
            
            $seller_name_display = strlen($seller_name) > $seller_name_chars ? 
                substr($seller_name, 0, $seller_name_chars-3) . '...' : $seller_name;
            $buyer_name_display = strlen($buyer_name) > $buyer_name_chars ? 
                substr($buyer_name, 0, $buyer_name_chars-3) . '...' : $buyer_name;
            
            // Row data
            $pdf->Cell($col_widths['#'], $row_height, $row_num, 1, 0, 'C');
            $pdf->Cell($col_widths['seller_ac'], $row_height, $seller_ac_display, 1, 0, 'C');
            $pdf->Cell($col_widths['seller_name'], $row_height, $seller_name_display, 1, 0, 'L');
            $pdf->Cell($col_widths['seller_consideration'], $row_height, number_format($trade['seller_consideration'], 2), 1, 0, 'R');
            $pdf->Cell($col_widths['traderef'], $row_height, substr($trade['trade_reference'], 0, 12), 1, 0, 'C');
            $pdf->Cell($col_widths['security'], $row_height, substr($trade['security_id'], 0, 6), 1, 0, 'C');
            $pdf->Cell($col_widths['price'], $row_height, number_format($trade['price'], 2), 1, 0, 'R');
            $pdf->Cell($col_widths['quantity'], $row_height, number_format($trade['quantity']), 1, 0, 'R');
            $pdf->Cell($col_widths['buyer_ac'], $row_height, $buyer_ac_display, 1, 0, 'C');
            $pdf->Cell($col_widths['buyer_name'], $row_height, $buyer_name_display, 1, 0, 'L');
            $pdf->Cell($col_widths['buyer_consideration'], $row_height, number_format($trade['buyer_consideration'], 2), 1, 0, 'R');
            $pdf->Ln();
            
            // Check if we need a new page
            if ($pdf->GetY() > 250) {
                $pdf->AddPage();
                
                // Redraw headers on new page
                $pdf->SetFont('helvetica', 'B', 8);
                
                // Header row
                $pdf->Cell($col_widths['#'], 6, '#', 1, 0, 'C');
                $pdf->Cell($seller_total_width, 6, 'SELLER', 1, 0, 'C');
                $pdf->Cell($middle_width, 6, '', 1, 0, 'C');
                $pdf->Cell($buyer_total_width, 6, 'BUYER', 1, 0, 'C');
                $pdf->Ln();
                
                // Sub-header row
                $pdf->Cell($col_widths['#'], 6, '', 1, 0, 'C');
                $pdf->Cell($col_widths['seller_ac'], 6, 'A/C', 1, 0, 'C');
                $pdf->Cell($col_widths['seller_name'], 6, 'NAME', 1, 0, 'C');
                $pdf->Cell($col_widths['seller_consideration'], 6, 'CONSIDERATION', 1, 0, 'C');
                $pdf->Cell($col_widths['traderef'], 6, 'TRADEREF', 1, 0, 'C');
                $pdf->Cell($col_widths['security'], 6, 'SECURITY', 1, 0, 'C');
                $pdf->Cell($col_widths['price'], 6, 'PRICE', 1, 0, 'C');
                $pdf->Cell($col_widths['quantity'], 6, 'QUANTITY', 1, 0, 'C');
                $pdf->Cell($col_widths['buyer_ac'], 6, 'A/C', 1, 0, 'C');
                $pdf->Cell($col_widths['buyer_name'], 6, 'NAME', 1, 0, 'C');
                $pdf->Cell($col_widths['buyer_consideration'], 6, 'CONSIDERATION', 1, 0, 'C');
                $pdf->Ln();
                
                $pdf->SetFont('helvetica', '', 7);
            }
        }
        
        // Add totals rows at the end of the transaction table
        $pdf->SetFont('helvetica', 'B', 7);
        
        // (a) Clients (non-brokers) - SELLER side
        $pdf->Cell($col_widths['#'] + $col_widths['seller_ac'] + $col_widths['seller_name'], $row_height, '(a) Clients', 1, 0, 'R');
        $pdf->Cell($col_widths['seller_consideration'], $row_height, number_format($seller_totals['clients'], 2), 1, 0, 'R');
        $pdf->Cell($middle_width + $col_widths['buyer_ac'] + $col_widths['buyer_name'], $row_height, '', 1, 0, 'C');
        $pdf->Cell($col_widths['buyer_consideration'], $row_height, '', 1, 0, 'C');
        $pdf->Ln();
        
        // (b) Brokers - SELLER side
        $pdf->Cell($col_widths['#'] + $col_widths['seller_ac'] + $col_widths['seller_name'], $row_height, '(b) Brokers', 1, 0, 'R');
        $pdf->Cell($col_widths['seller_consideration'], $row_height, number_format($seller_totals['brokers'], 2), 1, 0, 'R');
        $pdf->Cell($middle_width + $col_widths['buyer_ac'] + $col_widths['buyer_name'], $row_height, '', 1, 0, 'C');
        $pdf->Cell($col_widths['buyer_consideration'], $row_height, '', 1, 0, 'C');
        $pdf->Ln();
        
        // (c) Total (a+b) - SELLER side
        $pdf->Cell($col_widths['#'] + $col_widths['seller_ac'] + $col_widths['seller_name'], $row_height, '(c) Total (a+b)', 1, 0, 'R');
        $pdf->Cell($col_widths['seller_consideration'], $row_height, number_format($seller_totals['total'], 2), 1, 0, 'R');
        $pdf->Cell($middle_width + $col_widths['buyer_ac'] + $col_widths['buyer_name'], $row_height, '', 1, 0, 'C');
        $pdf->Cell($col_widths['buyer_consideration'], $row_height, '', 1, 0, 'C');
        $pdf->Ln();
        
        // (d) Clients (non-brokers) - BUYER side
        $pdf->Cell($col_widths['#'] + $col_widths['seller_ac'] + $col_widths['seller_name'] + $col_widths['seller_consideration'] + $middle_width, $row_height, '(d) Clients', 1, 0, 'R');
        $pdf->Cell($col_widths['buyer_ac'] + $col_widths['buyer_name'], $row_height, '', 1, 0, 'C');
        $pdf->Cell($col_widths['buyer_consideration'], $row_height, number_format($buyer_totals['clients'], 2), 1, 0, 'R');
        $pdf->Ln();
        
        // (e) Brokers - BUYER side
        $pdf->Cell($col_widths['#'] + $col_widths['seller_ac'] + $col_widths['seller_name'] + $col_widths['seller_consideration'] + $middle_width, $row_height, '(e) Brokers', 1, 0, 'R');
        $pdf->Cell($col_widths['buyer_ac'] + $col_widths['buyer_name'], $row_height, '', 1, 0, 'C');
        $pdf->Cell($col_widths['buyer_consideration'], $row_height, number_format($buyer_totals['brokers'], 2), 1, 0, 'R');
        $pdf->Ln();
        
        // (f) Total (d+e) - BUYER side
        $pdf->Cell($col_widths['#'] + $col_widths['seller_ac'] + $col_widths['seller_name'] + $col_widths['seller_consideration'] + $middle_width, $row_height, '(f) Total (d+e)', 1, 0, 'R');
        $pdf->Cell($col_widths['buyer_ac'] + $col_widths['buyer_name'], $row_height, '', 1, 0, 'C');
        $pdf->Cell($col_widths['buyer_consideration'], $row_height, number_format($buyer_totals['total'], 2), 1, 0, 'R');
        $pdf->Ln();
        
        // Balance (c-f)
        $balance_label_width = $col_widths['#'] + $col_widths['seller_ac'] + $col_widths['seller_name'] + $col_widths['seller_consideration'] + $middle_width + $col_widths['buyer_ac'] + $col_widths['buyer_name'];
        $pdf->Cell($balance_label_width, $row_height, '[Net (c-f)]', 1, 0, 'R');
        $pdf->Cell($col_widths['buyer_consideration'], $row_height, number_format($balance, 2), 1, 0, 'R');
        $pdf->Ln();
    }
    
    // Add a new page for broker summary
    $pdf->AddPage();
    
    // Broker summary header
    $pdf->SetFont('helvetica', 'B', 11);
    $pdf->Cell(0, 5, 'Division : ' . strtoupper($company_division), 0, 1, 'L');
    $pdf->Cell(0, 5, 'BROKER SUMMARY (BONDS & TREASURY BILLS)', 0, 1, 'C');
    $pdf->Ln(3);
    
    // Get broker summary for counterpart brokers (brokers trading with our clients)
    $broker_summary_query = "
        SELECT 
            b.broker_code,
            b.broker_name,
            -- Inflow: When broker is BUYING from our client (broker is buyer, pays money to client)
            COALESCE(SUM(CASE 
                WHEN t.trade_side = 'sell' AND t.counterparty_name = b.broker_name 
                THEN t.consideration 
                ELSE 0 
            END), 0) as inflow,
            -- Outflow: When broker is SELLING to our client (broker is seller, receives money from client)
            COALESCE(SUM(CASE 
                WHEN t.trade_side = 'buy' AND t.counterparty_name = b.broker_name 
                THEN t.consideration 
                ELSE 0 
            END), 0) as outflow,
            -- Net: Inflow (money from broker) - Outflow (money to broker)
            COALESCE(SUM(CASE 
                WHEN t.trade_side = 'sell' AND t.counterparty_name = b.broker_name 
                THEN t.consideration 
                ELSE 0 
            END), 0) - 
            COALESCE(SUM(CASE 
                WHEN t.trade_side = 'buy' AND t.counterparty_name = b.broker_name 
                THEN t.consideration 
                ELSE 0 
            END), 0) as net
        FROM brokers b
        INNER JOIN trades t ON (t.counterparty_name = b.broker_name)
        WHERE b.status = 'active'
        AND t.status = 'active' 
        AND (t.asset_class = 'bond' OR t.asset_class = 'treasury_bill' OR t.asset_class = 'corporate_bond')
        AND b.broker_code NOT IN ('MAIN', 'VICT', 'ZANS') -- Exclude system brokers
        " . ($filters['period_from'] ? "AND t.trade_date >= :period_from" : "") . "
        " . ($filters['period_to'] ? "AND t.trade_date <= :period_to" : "") . "
        GROUP BY b.broker_code, b.broker_name
        HAVING inflow != 0 OR outflow != 0
        ORDER BY b.broker_code
    ";
    
    $broker_stmt = $db->prepare($broker_summary_query);
    
    if ($filters['period_from']) {
        $broker_stmt->bindValue(':period_from', $filters['period_from']);
    }
    if ($filters['period_to']) {
        $broker_stmt->bindValue(':period_to', $filters['period_to']);
    }
    
    $broker_stmt->execute();
    $broker_summary = $broker_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calculate broker summary totals
    $broker_total_inflow = 0;
    $broker_total_outflow = 0;
    $broker_total_net = 0;
    
    foreach ($broker_summary as $broker) {
        $broker_total_inflow += $broker['inflow'];
        $broker_total_outflow += $broker['outflow'];
        $broker_total_net += $broker['net'];
    }
    
    // Also get broker summary for our brokers (when they trade as clients)
    $our_brokers_query = "
        SELECT 
            b.broker_code,
            b.broker_name,
            -- Inflow: When our broker is SELLING to counterparty (broker receives money)
            COALESCE(SUM(CASE 
                WHEN t.trade_side = 'sell' AND t.client_name = b.broker_name 
                THEN t.consideration 
                ELSE 0 
            END), 0) as inflow,
            -- Outflow: When our broker is BUYING from counterparty (broker pays money)
            COALESCE(SUM(CASE 
                WHEN t.trade_side = 'buy' AND t.client_name = b.broker_name 
                THEN t.consideration 
                ELSE 0 
            END), 0) as outflow,
            -- Net: Inflow (money to broker) - Outflow (money from broker)
            COALESCE(SUM(CASE 
                WHEN t.trade_side = 'sell' AND t.client_name = b.broker_name 
                THEN t.consideration 
                ELSE 0 
            END), 0) - 
            COALESCE(SUM(CASE 
                WHEN t.trade_side = 'buy' AND t.client_name = b.broker_name 
                THEN t.consideration 
                ELSE 0 
            END), 0) as net
        FROM brokers b
        INNER JOIN trades t ON (t.client_name = b.broker_name)
        WHERE b.status = 'active'
        AND t.status = 'active' 
        AND (t.asset_class = 'bond' OR t.asset_class = 'treasury_bill' OR t.asset_class = 'corporate_bond')
        AND b.broker_code NOT IN ('MAIN', 'VICT', 'ZANS')
        " . ($filters['period_from'] ? "AND t.trade_date >= :period_from" : "") . "
        " . ($filters['period_to'] ? "AND t.trade_date <= :period_to" : "") . "
        GROUP BY b.broker_code, b.broker_name
        HAVING inflow != 0 OR outflow != 0
        ORDER BY b.broker_code
    ";
    
    $our_brokers_stmt = $db->prepare($our_brokers_query);
    
    if ($filters['period_from']) {
        $our_brokers_stmt->bindValue(':period_from', $filters['period_from']);
    }
    if ($filters['period_to']) {
        $our_brokers_stmt->bindValue(':period_to', $filters['period_to']);
    }
    
    $our_brokers_stmt->execute();
    $our_brokers_summary = $our_brokers_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Add a section for counterpart brokers (brokers trading with our clients)
    if (!empty($broker_summary)) {
        // Add header for counterpart brokers
        $pdf->SetFont('helvetica', 'B', 9);
        $pdf->Cell(0, 5, 'COUNTERPART BROKERS (Brokers Trading with Our Clients)', 0, 1, 'L');
        $pdf->Ln(2);
        
        $pdf->SetFont('helvetica', 'I', 7);
        $pdf->Cell(0, 3, 'Note: Inflow = Total SELL by brokers (broker buys from our clients)', 0, 1, 'L');
        $pdf->Cell(0, 3, '      Outflow = Total BUY by brokers (broker sells to our clients)', 0, 1, 'L');
        $pdf->Ln(2);
        
        // Calculate dynamic column widths for broker summary
        $broker_col_widths = [
            '#' => 5,
            'code' => 12,
            'name' => ($orientation === 'L') ? 60 : 45,
            'inflow' => 25,
            'outflow' => 25,
            'net' => 25,
            'balance' => 25
        ];
        
        // Broker summary table header
        $pdf->SetFont('helvetica', 'B', 8);
        
        $pdf->Cell($broker_col_widths['#'], 6, '#', 1, 0, 'C');
        $pdf->Cell($broker_col_widths['code'], 6, 'Code', 1, 0, 'C');
        $pdf->Cell($broker_col_widths['name'], 6, 'Name', 1, 0, 'C');
        $pdf->Cell($broker_col_widths['inflow'], 6, 'Inflow', 1, 0, 'C');
        $pdf->Cell($broker_col_widths['outflow'], 6, 'Outflow', 1, 0, 'C');
        $pdf->Cell($broker_col_widths['net'], 6, 'Net', 1, 0, 'C');
        $pdf->Cell($broker_col_widths['balance'], 6, 'Balance', 1, 0, 'C');
        $pdf->Ln();
        
        // Broker summary data
        $pdf->SetFont('helvetica', '', 7);
        $broker_counter = 1;
        $running_balance = 0;
        
        foreach ($broker_summary as $broker) {
            $running_balance += $broker['net'];
            
            $broker_name_chars = floor($broker_col_widths['name'] / 1.5);
            $broker_name_display = strlen($broker['broker_name']) > $broker_name_chars ? 
                substr($broker['broker_name'], 0, $broker_name_chars-3) . '...' : $broker['broker_name'];
            
            $pdf->Cell($broker_col_widths['#'], $row_height, $broker_counter, 1, 0, 'C');
            $pdf->Cell($broker_col_widths['code'], $row_height, $broker['broker_code'], 1, 0, 'C');
            $pdf->Cell($broker_col_widths['name'], $row_height, $broker_name_display, 1, 0, 'L');
            $pdf->Cell($broker_col_widths['inflow'], $row_height, number_format($broker['inflow'], 2), 1, 0, 'R');
            $pdf->Cell($broker_col_widths['outflow'], $row_height, number_format($broker['outflow'], 2), 1, 0, 'R');
            $pdf->Cell($broker_col_widths['net'], $row_height, number_format($broker['net'], 2), 1, 0, 'R');
            $pdf->Cell($broker_col_widths['balance'], $row_height, number_format($running_balance, 2), 1, 0, 'R');
            $pdf->Ln();
            $broker_counter++;
            
            // Check if we need a new page
            if ($pdf->GetY() > 250) {
                $pdf->AddPage();
                $pdf->SetFont('helvetica', 'B', 8);
                $pdf->Cell($broker_col_widths['#'], 6, '#', 1, 0, 'C');
                $pdf->Cell($broker_col_widths['code'], 6, 'Code', 1, 0, 'C');
                $pdf->Cell($broker_col_widths['name'], 6, 'Name', 1, 0, 'C');
                $pdf->Cell($broker_col_widths['inflow'], 6, 'Inflow', 1, 0, 'C');
                $pdf->Cell($broker_col_widths['outflow'], 6, 'Outflow', 1, 0, 'C');
                $pdf->Cell($broker_col_widths['net'], 6, 'Net', 1, 0, 'C');
                $pdf->Cell($broker_col_widths['balance'], 6, 'Balance', 1, 0, 'C');
                $pdf->Ln();
                $pdf->SetFont('helvetica', '', 7);
            }
        }
        
        // Totals row for counterpart brokers
        $pdf->SetFont('helvetica', 'B', 7);
        $total_label_width = $broker_col_widths['#'] + $broker_col_widths['code'] + $broker_col_widths['name'];
        $pdf->Cell($total_label_width, $row_height, 'COUNTERPART BROKERS TOTAL', 1, 0, 'C');
        $pdf->Cell($broker_col_widths['inflow'], $row_height, number_format($broker_total_inflow, 2), 1, 0, 'R');
        $pdf->Cell($broker_col_widths['outflow'], $row_height, number_format($broker_total_outflow, 2), 1, 0, 'R');
        $pdf->Cell($broker_col_widths['net'], $row_height, number_format($broker_total_net, 2), 1, 0, 'R');
        $pdf->Cell($broker_col_widths['balance'], $row_height, number_format($broker_total_net, 2), 1, 0, 'R');
        $pdf->Ln();
        
        $pdf->Ln(5);
    }
    
    // Add a section for our brokers (when they trade as clients)
    if (!empty($our_brokers_summary)) {
        // Add header for our brokers
        $pdf->SetFont('helvetica', 'B', 9);
        $pdf->Cell(0, 5, 'OUR BROKERS AS CLIENTS (Trading with Counterparties)', 0, 1, 'L');
        $pdf->Ln(2);
        
        $pdf->SetFont('helvetica', 'I', 7);
        $pdf->Cell(0, 3, 'Note: Inflow = Total SELL by our brokers (they receive money)', 0, 1, 'L');
        $pdf->Cell(0, 3, '      Outflow = Total BUY by our brokers (they pay money)', 0, 1, 'L');
        $pdf->Ln(2);
        
        // Calculate totals for our brokers
        $our_brokers_total_inflow = 0;
        $our_brokers_total_outflow = 0;
        $our_brokers_total_net = 0;
        
        foreach ($our_brokers_summary as $broker) {
            $our_brokers_total_inflow += $broker['inflow'];
            $our_brokers_total_outflow += $broker['outflow'];
            $our_brokers_total_net += $broker['net'];
        }
        
        // Our brokers table header
        $pdf->SetFont('helvetica', 'B', 8);
        
        $pdf->Cell($broker_col_widths['#'], 6, '#', 1, 0, 'C');
        $pdf->Cell($broker_col_widths['code'], 6, 'Code', 1, 0, 'C');
        $pdf->Cell($broker_col_widths['name'], 6, 'Name', 1, 0, 'C');
        $pdf->Cell($broker_col_widths['inflow'], 6, 'Inflow', 1, 0, 'C');
        $pdf->Cell($broker_col_widths['outflow'], 6, 'Outflow', 1, 0, 'C');
        $pdf->Cell($broker_col_widths['net'], 6, 'Net', 1, 0, 'C');
        $pdf->Cell($broker_col_widths['balance'], 6, 'Balance', 1, 0, 'C');
        $pdf->Ln();
        
        // Our brokers data
        $pdf->SetFont('helvetica', '', 7);
        $our_broker_counter = 1;
        $our_running_balance = 0;
        
        foreach ($our_brokers_summary as $broker) {
            $our_running_balance += $broker['net'];
            
            $broker_name_chars = floor($broker_col_widths['name'] / 1.5);
            $broker_name_display = strlen($broker['broker_name']) > $broker_name_chars ? 
                substr($broker['broker_name'], 0, $broker_name_chars-3) . '...' : $broker['broker_name'];
            
            $pdf->Cell($broker_col_widths['#'], $row_height, $our_broker_counter, 1, 0, 'C');
            $pdf->Cell($broker_col_widths['code'], $row_height, $broker['broker_code'], 1, 0, 'C');
            $pdf->Cell($broker_col_widths['name'], $row_height, $broker_name_display, 1, 0, 'L');
            $pdf->Cell($broker_col_widths['inflow'], $row_height, number_format($broker['inflow'], 2), 1, 0, 'R');
            $pdf->Cell($broker_col_widths['outflow'], $row_height, number_format($broker['outflow'], 2), 1, 0, 'R');
            $pdf->Cell($broker_col_widths['net'], $row_height, number_format($broker['net'], 2), 1, 0, 'R');
            $pdf->Cell($broker_col_widths['balance'], $row_height, number_format($our_running_balance, 2), 1, 0, 'R');
            $pdf->Ln();
            $our_broker_counter++;
            
            // Check if we need a new page
            if ($pdf->GetY() > 250) {
                $pdf->AddPage();
                $pdf->SetFont('helvetica', 'B', 8);
                $pdf->Cell($broker_col_widths['#'], 6, '#', 1, 0, 'C');
                $pdf->Cell($broker_col_widths['code'], 6, 'Code', 1, 0, 'C');
                $pdf->Cell($broker_col_widths['name'], 6, 'Name', 1, 0, 'C');
                $pdf->Cell($broker_col_widths['inflow'], 6, 'Inflow', 1, 0, 'C');
                $pdf->Cell($broker_col_widths['outflow'], 6, 'Outflow', 1, 0, 'C');
                $pdf->Cell($broker_col_widths['net'], 6, 'Net', 1, 0, 'C');
                $pdf->Cell($broker_col_widths['balance'], 6, 'Balance', 1, 0, 'C');
                $pdf->Ln();
                $pdf->SetFont('helvetica', '', 7);
            }
        }
        
        // Totals row for our brokers
        $pdf->SetFont('helvetica', 'B', 7);
        $pdf->Cell($total_label_width, $row_height, 'OUR BROKERS TOTAL', 1, 0, 'C');
        $pdf->Cell($broker_col_widths['inflow'], $row_height, number_format($our_brokers_total_inflow, 2), 1, 0, 'R');
        $pdf->Cell($broker_col_widths['outflow'], $row_height, number_format($our_brokers_total_outflow, 2), 1, 0, 'R');
        $pdf->Cell($broker_col_widths['net'], $row_height, number_format($our_brokers_total_net, 2), 1, 0, 'R');
        $pdf->Cell($broker_col_widths['balance'], $row_height, number_format($our_brokers_total_net, 2), 1, 0, 'R');
        $pdf->Ln();
    }
    
    // If no broker data at all
    if (empty($broker_summary) && empty($our_brokers_summary)) {
        $pdf->SetFont('helvetica', '', 8);
        $pdf->Cell(0, 8, 'No broker data available for the selected period.', 0, 1, 'C');
    }
    
    // Add summary explanation
    $pdf->Ln(5);
    $pdf->SetFont('helvetica', 'I', 7);
    $pdf->MultiCell(0, 3, 'Summary Explanation:', 0, 'L');
    $pdf->MultiCell(0, 3, '1. Inflow: Total amount received (broker sells to clients or clients sell to broker)', 0, 'L');
    $pdf->MultiCell(0, 3, '2. Outflow: Total amount paid out (broker buys from clients or clients buy from broker)', 0, 'L');
    $pdf->MultiCell(0, 3, '3. Net: Inflow minus Outflow', 0, 'L');
    $pdf->MultiCell(0, 3, '4. Positive Net: More money received than paid', 0, 'L');
    $pdf->MultiCell(0, 3, '5. Negative Net: More money paid out than received', 0, 'L');
    
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
    $filename = 'Bonds_Treasury_Bills_Transactions_Summary_Report_' . date('d_m_Y_H_i_s') . '.pdf';
    
    if ($filters['print_mode'] === '1') {
        $pdf->Output($filename, 'I');
    } elseif ($filters['export_type'] === 'excel') {
        $pdf->Output($filename, 'D');
    } else {
        $pdf->Output($filename, 'I');
    }
}
?>