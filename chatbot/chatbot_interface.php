<?php
// chatbot_interface.php - FIXED VERSION WITH NULL HANDLING
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Include configuration
require_once '../config/config.php';

// Check authentication
if (!is_logged_in()) {
    redirect('auth/login.php');
}

// Get logged in user
$user = get_logged_in_user();
if (!$user) {
    redirect('auth/login.php');
}

// Get database connection
$db = getDBConnection();

// Initialize chat history if not exists
if (!isset($_SESSION['chat_history'])) {
    $_SESSION['chat_history'] = [];
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['message'])) {
    $user_message = trim($_POST['message']);
    
    if (!empty($user_message)) {
        // Add user message to history
        $_SESSION['chat_history'][] = [
            'type' => 'user',
            'user' => $user_message,
            'time' => date('H:i:s')
        ];
        
        // Process message and generate response
        $bot_response = processMessage($user_message, $db, $user);
        
        // Add bot response to history
        $_SESSION['chat_history'][] = [
            'type' => 'bot',
            'bot' => $bot_response,
            'time' => date('H:i:s')
        ];
        
        // Redirect to prevent form resubmission
        header('Location: chatbot_interface.php');
        exit();
    }
}

// Handle clear chat
if (isset($_GET['clear'])) {
    $_SESSION['chat_history'] = [];
    header('Location: chatbot_interface.php');
    exit();
}

// ========== FINANCIAL REPORTING FUNCTIONS ==========

function getCompanyInfo($db) {
    $stmt = $db->prepare("
        SELECT 
            company_code,
            COALESCE(company_name, name) as company_name,
            address,
            phone,
            mobile,
            email,
            registration_number,
            currency,
            country
        FROM companies 
        WHERE (status = 'active' OR is_active = 1)
        ORDER BY id ASC 
        LIMIT 1
    ");
    $stmt->execute();
    $company = $stmt->fetch(PDO::FETCH_ASSOC);
    
    return $company ?: [
        'company_name' => 'Trading Company',
        'address' => '',
        'registration_number' => '',
        'currency' => 'TZS'
    ];
}

function safeNumberFormat($value, $decimals = 2) {
    if ($value === null || $value === '' || !is_numeric($value)) {
        return number_format(0, $decimals);
    }
    return number_format((float)$value, $decimals);
}

function getFinancialMetrics($db, $period = 'current_month') {
    $metrics = [
        'trades' => 0,
        'trade_value' => 0,
        'commission' => 0,
        'vat' => 0,
        'active_clients' => 0,
        'active_trading_clients' => 0,
        'cash_position' => 0,
        'receivables' => 0,
        'payables' => 0
    ];
    
    // Define date ranges
    $date_ranges = [
        'today' => [
            'start' => date('Y-m-d'),
            'end' => date('Y-m-d'),
            'label' => 'Today'
        ],
        'yesterday' => [
            'start' => date('Y-m-d', strtotime('-1 day')),
            'end' => date('Y-m-d', strtotime('-1 day')),
            'label' => 'Yesterday'
        ],
        'current_week' => [
            'start' => date('Y-m-d', strtotime('monday this week')),
            'end' => date('Y-m-d'),
            'label' => 'This Week'
        ],
        'current_month' => [
            'start' => date('Y-m-01'),
            'end' => date('Y-m-d'),
            'label' => 'This Month'
        ],
        'last_month' => [
            'start' => date('Y-m-01', strtotime('-1 month')),
            'end' => date('Y-m-t', strtotime('-1 month')),
            'label' => 'Last Month'
        ],
        'current_quarter' => [
            'start' => date('Y-m-01', strtotime(date('Y') . '-' . ((ceil(date('n')/3)-1)*3+1) . '-01')),
            'end' => date('Y-m-d'),
            'label' => 'Current Quarter'
        ],
        'current_year' => [
            'start' => date('Y-01-01'),
            'end' => date('Y-m-d'),
            'label' => 'Year to Date'
        ]
    ];
    
    $range = $date_ranges[$period] ?? $date_ranges['current_month'];
    
    // Trading Metrics
    try {
        // Check if trades table exists
        $stmt_check = $db->query("SHOW TABLES LIKE 'trades'");
        if ($stmt_check->rowCount() > 0) {
            // Total trades
            $stmt = $db->prepare("
                SELECT COUNT(*) as count, COALESCE(SUM(quantity * price), 0) as value 
                FROM trades 
                WHERE trade_date BETWEEN ? AND ? 
                AND status = 'completed'
            ");
            $stmt->execute([$range['start'], $range['end']]);
            $trade_data = $stmt->fetch(PDO::FETCH_ASSOC);
            $metrics['trades'] = (int)($trade_data['count'] ?? 0);
            $metrics['trade_value'] = (float)($trade_data['value'] ?? 0);
            
            // Commission
            $stmt = $db->prepare("
                SELECT COALESCE(SUM(commission_amount), 0) as commission 
                FROM trades 
                WHERE trade_date BETWEEN ? AND ? 
                AND status = 'completed'
            ");
            $stmt->execute([$range['start'], $range['end']]);
            $commission_data = $stmt->fetch(PDO::FETCH_ASSOC);
            $metrics['commission'] = (float)($commission_data['commission'] ?? 0);
            
            // VAT on commission (assuming 18%)
            $metrics['vat'] = $metrics['commission'] * 0.18;
            
            // Clients with recent trades
            $stmt = $db->prepare("
                SELECT COUNT(DISTINCT client_id) as count 
                FROM trades 
                WHERE trade_date BETWEEN ? AND ? 
                AND status = 'completed'
            ");
            $stmt->execute([$range['start'], $range['end']]);
            $metrics['active_trading_clients'] = (int)$stmt->fetchColumn();
        }
    } catch (Exception $e) {
        error_log("Trading metrics error: " . $e->getMessage());
    }
    
    // Client Metrics
    try {
        // Check if clients table exists
        $stmt_check = $db->query("SHOW TABLES LIKE 'clients'");
        if ($stmt_check->rowCount() > 0) {
            // Active clients
            $stmt = $db->prepare("SELECT COUNT(*) as count FROM clients WHERE status = 'active'");
            $stmt->execute();
            $metrics['active_clients'] = (int)$stmt->fetchColumn();
        }
    } catch (Exception $e) {
        error_log("Client metrics error: " . $e->getMessage());
    }
    
    // Financial Position (from general ledger)
    try {
        // Check if general_ledger table exists
        $stmt_check = $db->query("SHOW TABLES LIKE 'general_ledger'");
        if ($stmt_check->rowCount() > 0) {
            // Cash position
            $stmt = $db->prepare("
                SELECT COALESCE(SUM(
                    CASE WHEN gl.debit_amount > 0 THEN gl.debit_amount
                         WHEN gl.credit_amount > 0 THEN -gl.credit_amount
                         ELSE 0 END
                ), 0) as balance
                FROM general_ledger gl
                JOIN chart_of_accounts coa ON gl.account_id = coa.id
                WHERE gl.transaction_date <= ?
                AND gl.status = 'active'
                AND coa.account_code LIKE '111%'
            ");
            $stmt->execute([$range['end']]);
            $metrics['cash_position'] = (float)$stmt->fetchColumn();
            
            // Receivables
            $stmt = $db->prepare("
                SELECT COALESCE(SUM(
                    CASE WHEN gl.debit_amount > 0 THEN gl.debit_amount
                         WHEN gl.credit_amount > 0 THEN -gl.credit_amount
                         ELSE 0 END
                ), 0) as balance
                FROM general_ledger gl
                JOIN chart_of_accounts coa ON gl.account_id = coa.id
                WHERE gl.transaction_date <= ?
                AND gl.status = 'active'
                AND coa.account_code LIKE '112%'
            ");
            $stmt->execute([$range['end']]);
            $metrics['receivables'] = (float)$stmt->fetchColumn();
            
            // Payables
            $stmt = $db->prepare("
                SELECT COALESCE(SUM(
                    CASE WHEN gl.credit_amount > 0 THEN gl.credit_amount
                         WHEN gl.debit_amount > 0 THEN -gl.debit_amount
                         ELSE 0 END
                ), 0) as balance
                FROM general_ledger gl
                JOIN chart_of_accounts coa ON gl.account_id = coa.id
                WHERE gl.transaction_date <= ?
                AND gl.status = 'active'
                AND coa.account_code LIKE '211%'
            ");
            $stmt->execute([$range['end']]);
            $metrics['payables'] = (float)$stmt->fetchColumn();
        }
    } catch (Exception $e) {
        error_log("Financial position error: " . $e->getMessage());
    }
    
    $metrics['period'] = $range['label'];
    $metrics['start_date'] = $range['start'];
    $metrics['end_date'] = $range['end'];
    
    return $metrics;
}

function getTopPerformers($db, $limit = 5) {
    $performers = [
        'equities' => [],
        'bonds' => [],
        'clients' => []
    ];
    
    // Top equities by trade volume
    try {
        $stmt_check = $db->query("SHOW TABLES LIKE 'equities_settings'");
        if ($stmt_check->rowCount() > 0) {
            $stmt = $db->prepare("
                SELECT 
                    t.security_code,
                    COALESCE(e.description, t.security_code) as security_name,
                    COUNT(t.id) as trade_count,
                    COALESCE(SUM(t.quantity), 0) as total_quantity,
                    COALESCE(SUM(t.quantity * t.price), 0) as total_value,
                    COALESCE(AVG(t.price), 0) as avg_price
                FROM trades t
                LEFT JOIN equities_settings e ON t.security_code = e.security_id
                WHERE t.security_type = 'equity'
                AND t.status = 'completed'
                AND t.trade_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                GROUP BY t.security_code
                ORDER BY total_value DESC
                LIMIT ?
            ");
            $stmt->execute([$limit]);
            $performers['equities'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (Exception $e) {
        // Silently fail, return empty array
    }
    
    // Top bonds by trade volume
    try {
        $stmt_check = $db->query("SHOW TABLES LIKE 'bonds'");
        if ($stmt_check->rowCount() > 0) {
            $stmt = $db->prepare("
                SELECT 
                    t.security_code,
                    COALESCE(b.bond_name, t.security_code) as security_name,
                    COUNT(t.id) as trade_count,
                    COALESCE(SUM(t.quantity), 0) as total_quantity,
                    COALESCE(SUM(t.quantity * t.price), 0) as total_value,
                    COALESCE(AVG(t.price), 0) as avg_price
                FROM trades t
                LEFT JOIN bonds b ON t.security_code = b.security_id
                WHERE t.security_type = 'bond'
                AND t.status = 'completed'
                AND t.trade_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                GROUP BY t.security_code
                ORDER BY total_value DESC
                LIMIT ?
            ");
            $stmt->execute([$limit]);
            $performers['bonds'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (Exception $e) {
        // Silently fail, return empty array
    }
    
    // Top clients by trade value
    try {
        $stmt_check = $db->query("SHOW TABLES LIKE 'clients'");
        if ($stmt_check->rowCount() > 0) {
            $stmt = $db->prepare("
                SELECT 
                    c.client_name,
                    c.client_code,
                    COUNT(t.id) as trade_count,
                    COALESCE(SUM(t.quantity * t.price), 0) as total_value,
                    COALESCE(SUM(t.commission_amount), 0) as total_commission
                FROM trades t
                JOIN clients c ON t.client_id = c.id
                WHERE t.status = 'completed'
                AND t.trade_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                GROUP BY c.id, c.client_name, c.client_code
                ORDER BY total_value DESC
                LIMIT ?
            ");
            $stmt->execute([$limit]);
            $performers['clients'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (Exception $e) {
        // Silently fail, return empty array
    }
    
    return $performers;
}

function generateReportURL($report_type, $params = []) {
    $base_urls = [
        'balance_sheet' => '../balance_sheet_final_working.php',
        'statutory_bonds' => '../reports.php?generateStatutoryReport=bonds_statutory',
        'statutory_equities' => '../reports.php?generateStatutoryReport=equities_statutory',
        'statutory_all' => '../reports.php?generateStatutoryReport=all_statutory',
        'bonds_summary' => '../reports.php?generateBondsReport=bonds_summary',
        'transaction_summary' => '../reports.php?generateShareReport=transaction_summary',
        'broker_summary' => '../reports.php?generateShareReport=broker_summary',
        'contract_notes' => '../reports.php?generateContractNote=contract_notes',
        'commission_summary' => '../reports.php?generateCommission=commission_summary',
        'portfolio_analysis' => '../reports.php?generateReport=portfolio_analysis',
        'asset_class_summary' => '../reports.php?generateReport=asset_class_summary'
    ];
    
    if (!isset($base_urls[$report_type])) {
        return '../reports.php';
    }
    
    $url = $base_urls[$report_type];
    
    // Add parameters if any
    if (!empty($params)) {
        if (strpos($url, '?') === false) {
            $url .= '?';
        } else {
            $url .= '&';
        }
        $url .= http_build_query($params);
    }
    
    return $url;
}

// ========== UPDATED PROCESS MESSAGE FUNCTION ==========

function processMessage($message, $db, $user) {
    $message_lower = strtolower(trim($message));
    $company = getCompanyInfo($db);
    $currency = $company['currency'] ?? 'TZS';
    
    // 1. Generate contract note (existing)
    if (strpos($message_lower, 'generate contract note') !== false || 
        strpos($message_lower, 'contract note for') !== false ||
        strpos($message_lower, 'create contract note') !== false) {
        
        // ... (existing contract note code - keep as is) ...
        // For brevity, keeping the existing implementation
        return processContractNote($message, $db);
    }
    
    // 2. Client info (existing)
    elseif (strpos($message_lower, 'client info') !== false || 
            strpos($message_lower, 'client for') !== false ||
            strpos($message_lower, 'find client') !== false) {
        // ... (existing client info code - keep as is) ...
        return processClientInfo($message, $db);
    }
    
    // 3. Search equities (existing)
    elseif (strpos($message_lower, 'search equities') !== false || 
            strpos($message_lower, 'stocks for') !== false ||
            strpos($message_lower, 'equity') !== false) {
        // ... (existing equities code - keep as is) ...
        return processEquitySearch($message, $db);
    }
    
    // 4. Search bonds (existing)
    elseif (strpos($message_lower, 'search bonds') !== false || 
            strpos($message_lower, 'bonds for') !== false ||
            strpos($message_lower, 'bond') !== false) {
        // ... (existing bonds code - keep as is) ...
        return processBondSearch($message, $db);
    }
    
    // 5. Enhanced Portfolio summary with financial metrics
    elseif (strpos($message_lower, 'portfolio') !== false || 
            strpos($message_lower, 'summary') !== false ||
            strpos($message_lower, 'dashboard') !== false) {
        
        // Determine period from message
        $period = 'current_month';
        if (strpos($message_lower, 'today') !== false) $period = 'today';
        elseif (strpos($message_lower, 'yesterday') !== false) $period = 'yesterday';
        elseif (strpos($message_lower, 'week') !== false) $period = 'current_week';
        elseif (strpos($message_lower, 'month') !== false) $period = 'current_month';
        elseif (strpos($message_lower, 'quarter') !== false) $period = 'current_quarter';
        elseif (strpos($message_lower, 'year') !== false) $period = 'current_year';
        
        $metrics = getFinancialMetrics($db, $period);
        $performers = getTopPerformers($db, 3);
        
        $response = "📈 **Financial Dashboard - {$metrics['period']}**\n\n";
        
        // Trading Summary
        $response .= "**Trading Activity:**\n";
        $response .= "• Total Trades: " . number_format($metrics['trades']) . "\n";
        $response .= "• Trade Value: {$currency} " . safeNumberFormat($metrics['trade_value']) . "\n";
        $response .= "• Commission: {$currency} " . safeNumberFormat($metrics['commission']) . "\n";
        $response .= "• VAT (18%): {$currency} " . safeNumberFormat($metrics['vat']) . "\n";
        $response .= "• Net Revenue: {$currency} " . safeNumberFormat($metrics['commission'] + $metrics['vat']) . "\n\n";
        
        // Client Summary
        $response .= "**Client Activity:**\n";
        $response .= "• Active Clients: " . number_format($metrics['active_clients']) . "\n";
        $response .= "• Trading Clients: " . number_format($metrics['active_trading_clients']) . "\n\n";
        
        // Financial Position
        if ($metrics['cash_position'] > 0 || $metrics['receivables'] > 0 || $metrics['payables'] > 0) {
            $response .= "**Financial Position:**\n";
            if ($metrics['cash_position'] > 0) {
                $response .= "• Cash Position: {$currency} " . safeNumberFormat($metrics['cash_position']) . "\n";
            }
            if ($metrics['receivables'] > 0) {
                $response .= "• Receivables: {$currency} " . safeNumberFormat($metrics['receivables']) . "\n";
            }
            if ($metrics['payables'] > 0) {
                $response .= "• Payables: {$currency} " . safeNumberFormat($metrics['payables']) . "\n";
            }
            $response .= "\n";
        }
        
        // Top Performers
        $has_performers = false;
        if (!empty($performers['equities'])) $has_performers = true;
        if (!empty($performers['bonds'])) $has_performers = true;
        if (!empty($performers['clients'])) $has_performers = true;
        
        if ($has_performers) {
            $response .= "**Top Performers (Last 30 Days):**\n";
            
            if (!empty($performers['equities'])) {
                $response .= "**Top Equities:**\n";
                foreach ($performers['equities'] as $equity) {
                    if ($equity['total_value'] > 0) {
                        $response .= "• {$equity['security_code']}: {$currency} " . safeNumberFormat($equity['total_value']) . "\n";
                    }
                }
                $response .= "\n";
            }
            
            if (!empty($performers['clients'])) {
                $response .= "**Top Clients:**\n";
                foreach ($performers['clients'] as $client) {
                    if ($client['total_value'] > 0) {
                        $response .= "• {$client['client_name']}: {$currency} " . safeNumberFormat($client['total_value']) . "\n";
                    }
                }
                $response .= "\n";
            }
        }
        
        $response .= "📎 **Actions:**\n";
        $response .= "[button:View Detailed Reports|../reports.php]\n";
        $response .= "[button:Balance Sheet|../balance_sheet_final_working.php]\n";
        $response .= "[button:Commission Report|" . generateReportURL('commission_summary') . "]\n";
        $response .= "[button:Top Performers Report|../reports.php?generateReport=portfolio_analysis]\n";
        $response .= "[button:Export to Excel|../reports.php?export=excel&report=summary]";
        
        return $response;
    }
    
    // 6. Calculate fees (existing)
    elseif (strpos($message_lower, 'calculate') !== false || 
            strpos($message_lower, 'commission') !== false ||
            strpos($message_lower, 'fees for') !== false ||
            strpos($message_lower, 'how much') !== false) {
        // ... (existing fees calculation code - keep as is) ...
        return processFeeCalculation($message);
    }
    
    // 7. Financial Reports Section
    elseif (strpos($message_lower, 'financial report') !== false || 
            strpos($message_lower, 'balance sheet') !== false ||
            strpos($message_lower, 'income statement') !== false ||
            strpos($message_lower, 'financial position') !== false) {
        
        $response = "💰 **Financial Reports**\n\n";
        $response .= "I can generate comprehensive financial reports:\n\n";
        
        $response .= "**Statutory Reports:**\n";
        $response .= "• Bonds Statutory Deductions\n";
        $response .= "• Equities Statutory Deductions\n";
        $response .= "• All Statutory Deductions\n\n";
        
        $response .= "**Trading Reports:**\n";
        $response .= "• Transaction Summary\n";
        $response .= "• Broker Summary\n";
        $response .= "• Bonds Transactions Summary\n";
        $response .= "• Commission Summary\n\n";
        
        $response .= "**Financial Statements:**\n";
        $response .= "• Statement of Financial Position (Balance Sheet)\n";
        $response .= "• Portfolio Analysis\n";
        $response .= "• Asset Class Summary\n\n";
        
        $response .= "📎 **Quick Access:**\n";
        $response .= "[button:Balance Sheet|../balance_sheet_final_working.php]\n";
        $response .= "[button:All Reports|../reports.php]\n";
        $response .= "[button:Generate Contract Notes|" . generateReportURL('contract_notes') . "]\n";
        $response .= "[button:Commission Report|" . generateReportURL('commission_summary') . "]";
        
        return $response;
    }
    
    // 8. Specific Report Generation
    elseif (strpos($message_lower, 'generate report') !== false || 
            strpos($message_lower, 'create report') !== false ||
            strpos($message_lower, 'show report') !== false) {
        
        $response = "📊 **Report Generation**\n\n";
        
        // Extract report type from message
        if (strpos($message_lower, 'statutory') !== false) {
            if (strpos($message_lower, 'bond') !== false) {
                $url = generateReportURL('statutory_bonds');
                $response .= "Generating Bonds Statutory Deductions Report...\n\n";
                $response .= "📎 **Action:**\n";
                $response .= "[button:Generate Now|{$url}]\n";
            } elseif (strpos($message_lower, 'equity') !== false) {
                $url = generateReportURL('statutory_equities');
                $response .= "Generating Equities Statutory Deductions Report...\n\n";
                $response .= "📎 **Action:**\n";
                $response .= "[button:Generate Now|{$url}]\n";
            } else {
                $url = generateReportURL('statutory_all');
                $response .= "Generating All Statutory Deductions Report...\n\n";
                $response .= "📎 **Action:**\n";
                $response .= "[button:Generate Now|{$url}]\n";
            }
        }
        elseif (strpos($message_lower, 'commission') !== false) {
            $url = generateReportURL('commission_summary');
            $response .= "Generating Commission Summary Report...\n\n";
            $response .= "📎 **Action:**\n";
            $response .= "[button:Generate Now|{$url}]\n";
        }
        elseif (strpos($message_lower, 'balance sheet') !== false || 
                strpos($message_lower, 'financial position') !== false) {
            $url = generateReportURL('balance_sheet');
            $response .= "Generating Statement of Financial Position...\n\n";
            $response .= "📎 **Action:**\n";
            $response .= "[button:Generate Now|{$url}]\n";
        }
        elseif (strpos($message_lower, 'transaction') !== false) {
            $url = generateReportURL('transaction_summary');
            $response .= "Generating Transaction Summary Report...\n\n";
            $response .= "📎 **Action:**\n";
            $response .= "[button:Generate Now|{$url}]\n";
        }
        elseif (strpos($message_lower, 'broker') !== false) {
            $url = generateReportURL('broker_summary');
            $response .= "Generating Broker Summary Report...\n\n";
            $response .= "📎 **Action:**\n";
            $response .= "[button:Generate Now|{$url}]\n";
        }
        else {
            $response .= "Please specify which report you need:\n\n";
            $response .= "• 'Generate statutory report for bonds'\n";
            $response .= "• 'Create commission report'\n";
            $response .= "• 'Show balance sheet'\n";
            $response .= "• 'Generate transaction summary'\n";
            $response .= "• 'Create broker report'\n\n";
            
            $response .= "📎 **Quick Links:**\n";
            $response .= "[button:All Reports|../reports.php]\n";
            $response .= "[button:Balance Sheet|../balance_sheet_final_working.php]\n";
            $response .= "[button:Commission Report|" . generateReportURL('commission_summary') . "]";
        }
        
        return $response;
    }
    
    // 9. Financial Ratios and Analysis
    elseif (strpos($message_lower, 'ratio') !== false || 
            strpos($message_lower, 'analysis') !== false ||
            strpos($message_lower, 'performance') !== false ||
            strpos($message_lower, 'metrics') !== false) {
        
        $metrics = getFinancialMetrics($db, 'current_month');
        
        $response = "📊 **Financial Ratios & Analysis**\n\n";
        
        // Calculate ratios if data available
        $current_assets = ($metrics['cash_position'] ?? 0) + ($metrics['receivables'] ?? 0);
        $current_liabilities = $metrics['payables'] ?? 0;
        
        if ($current_liabilities > 0) {
            $current_ratio = $current_assets / $current_liabilities;
            $response .= "**Liquidity Analysis:**\n";
            $response .= "• Current Ratio: " . number_format($current_ratio, 2) . "\n";
            $response .= "  (Current Assets: {$currency} " . safeNumberFormat($current_assets) . ")\n";
            $response .= "  (Current Liabilities: {$currency} " . safeNumberFormat($current_liabilities) . ")\n\n";
        }
        
        if ($metrics['trade_value'] > 0) {
            $commission_rate = ($metrics['commission'] / $metrics['trade_value']) * 100;
            $response .= "**Trading Efficiency:**\n";
            $response .= "• Commission Rate: " . number_format($commission_rate, 2) . "%\n";
            $avg_trade_value = $metrics['trades'] > 0 ? $metrics['trade_value'] / $metrics['trades'] : 0;
            $response .= "• Average Trade Value: {$currency} " . safeNumberFormat($avg_trade_value) . "\n\n";
        }
        
        if ($metrics['active_clients'] > 0) {
            $response .= "**Client Metrics:**\n";
            $avg_value_per_client = $metrics['active_trading_clients'] > 0 ? $metrics['trade_value'] / $metrics['active_trading_clients'] : 0;
            $response .= "• Avg Value per Client: {$currency} " . safeNumberFormat($avg_value_per_client) . "\n";
            $trading_client_percent = $metrics['active_clients'] > 0 ? ($metrics['active_trading_clients'] / $metrics['active_clients']) * 100 : 0;
            $response .= "• Trading Client %: " . number_format($trading_client_percent, 1) . "%\n\n";
        }
        
        $response .= "📎 **Detailed Analysis:**\n";
        $response .= "[button:Portfolio Analysis|" . generateReportURL('portfolio_analysis') . "]\n";
        $response .= "[button:Asset Class Summary|" . generateReportURL('asset_class_summary') . "]\n";
        $response .= "[button:Balance Sheet|../balance_sheet_final_working.php]\n";
        $response .= "[button:Export Analysis|../reports.php?export=excel&report=ratios]";
        
        return $response;
    }
    
    // 10. Top Performers Query
    elseif (strpos($message_lower, 'top') !== false || 
            strpos($message_lower, 'best') !== false ||
            strpos($message_lower, 'most active') !== false ||
            strpos($message_lower, 'leader') !== false) {
        
        // Extract what type of top performers
        $type = 'all';
        if (strpos($message_lower, 'client') !== false) $type = 'clients';
        elseif (strpos($message_lower, 'equity') !== false || strpos($message_lower, 'stock') !== false) $type = 'equities';
        elseif (strpos($message_lower, 'bond') !== false) $type = 'bonds';
        
        $performers = getTopPerformers($db, 5);
        $currency = getCompanyInfo($db)['currency'] ?? 'TZS';
        
        $response = "🏆 **Top Performers**\n\n";
        
        if (($type === 'all' || $type === 'clients') && !empty($performers['clients'])) {
            $response .= "**Top Clients by Trade Value (Last 30 Days):**\n";
            foreach ($performers['clients'] as $index => $client) {
                $rank = $index + 1;
                $response .= "{$rank}. **" . ($client['client_name'] ?? 'Unknown') . "**\n";
                $response .= "   Code: " . ($client['client_code'] ?? 'N/A') . "\n";
                $response .= "   Trades: " . number_format($client['trade_count'] ?? 0) . "\n";
                $response .= "   Value: {$currency} " . safeNumberFormat($client['total_value'] ?? 0) . "\n";
                $response .= "   Commission: {$currency} " . safeNumberFormat($client['total_commission'] ?? 0) . "\n\n";
            }
        }
        
        if (($type === 'all' || $type === 'equities') && !empty($performers['equities'])) {
            $response .= "**Top Equities by Volume (Last 30 Days):**\n";
            foreach ($performers['equities'] as $index => $equity) {
                $rank = $index + 1;
                $response .= "{$rank}. **" . ($equity['security_code'] ?? 'Unknown') . "** - " . ($equity['security_name'] ?? 'N/A') . "\n";
                $response .= "   Trades: " . number_format($equity['trade_count'] ?? 0) . "\n";
                $response .= "   Quantity: " . safeNumberFormat($equity['total_quantity'] ?? 0, 0) . "\n";
                $response .= "   Value: {$currency} " . safeNumberFormat($equity['total_value'] ?? 0) . "\n";
                $response .= "   Avg Price: {$currency} " . safeNumberFormat($equity['avg_price'] ?? 0) . "\n\n";
            }
        }
        
        if (($type === 'all' || $type === 'bonds') && !empty($performers['bonds'])) {
            $response .= "**Top Bonds by Volume (Last 30 Days):**\n";
            foreach ($performers['bonds'] as $index => $bond) {
                $rank = $index + 1;
                $response .= "{$rank}. **" . ($bond['security_code'] ?? 'Unknown') . "** - " . ($bond['security_name'] ?? 'N/A') . "\n";
                $response .= "   Trades: " . number_format($bond['trade_count'] ?? 0) . "\n";
                $response .= "   Quantity: " . safeNumberFormat($bond['total_quantity'] ?? 0, 0) . "\n";
                $response .= "   Value: {$currency} " . safeNumberFormat($bond['total_value'] ?? 0) . "\n";
                $response .= "   Avg Price: {$currency} " . safeNumberFormat($bond['avg_price'] ?? 0) . "\n\n";
            }
        }
        
        $response .= "📎 **Actions:**\n";
        $response .= "[button:Full Portfolio Analysis|" . generateReportURL('portfolio_analysis') . "]\n";
        $response .= "[button:Export Top Performers|../reports.php?export=excel&report=topperformers]\n";
        $response .= "[button:View All Reports|../reports.php]";
        
        return $response;
    }
    
    // 11. System help - UPDATED with financial reporting
    elseif (strpos($message_lower, 'help') !== false || 
            strpos($message_lower, 'what can you do') !== false ||
            strpos($message_lower, 'commands') !== false) {
        
        $response = "🆘 **I can help you with:**\n\n";
        
        $response .= "📄 **Document Generation**\n";
        $response .= "  • 'Generate contract note for John Mgini'\n";
        $response .= "  • 'Create client statement'\n\n";
        
        $response .= "👤 **Client Information**\n";
        $response .= "  • 'Client info for John Mgini'\n";
        $response .= "  • 'Find client John'\n";
        $response .= "  • 'List clients'\n\n";
        
        $response .= "📈 **Equity Information**\n";
        $response .= "  • 'Search equities for NMB'\n";
        $response .= "  • 'Show all equities'\n";
        $response .= "  • 'Equity prices'\n\n";
        
        $response .= "💰 **Bond Information**\n";
        $response .= "  • 'Search bonds for 675'\n";
        $response .= "  • 'Show all bonds'\n";
        $response .= "  • 'Bond details'\n\n";
        
        $response .= "📊 **Financial Reports**\n";
        $response .= "  • 'Generate statutory report'\n";
        $response .= "  • 'Show balance sheet'\n";
        $response .= "  • 'Create commission report'\n";
        $response .= "  • 'Generate transaction summary'\n";
        $response .= "  • 'Portfolio analysis'\n\n";
        
        $response .= "📈 **Financial Analysis**\n";
        $response .= "  • 'Show financial ratios'\n";
        $response .= "  • 'Top performers'\n";
        $response .= "  • 'Dashboard summary'\n";
        $response .= "  • 'Performance metrics'\n\n";
        
        $response .= "🧮 **Calculations**\n";
        $response .= "  • 'Calculate fees for 5,000,000'\n";
        $response .= "  • 'Commission for 10,000,000 bond'\n";
        $response .= "  • 'Fees calculation'\n\n";
        
        $response .= "📎 **Quick Actions:**\n";
        $response .= "[button:Go to Reports|../reports.php]\n";
        $response .= "[button:Balance Sheet|../balance_sheet_final_working.php]\n";
        $response .= "[button:Go to Trades|../trades.php]\n";
        $response .= "[button:Go to Clients|../client_management.php]";
        
        return $response;
    }
    
    // 12. System overview - UPDATED
    elseif (strpos($message_lower, 'system overview') !== false ||
            strpos($message_lower, 'about system') !== false) {
        return "🏢 **System Overview:**\n\n" .
               "**Stock Exchange Trading & Financial System**\n" .
               "• Client Management with CDS accounts\n" .
               "• Equity & Bond Trading\n" .
               "• Commission & Fee Calculations\n" .
               "• Contract Note Generation\n" .
               "• **Financial Reporting Suite**\n" .
               "  - Statement of Financial Position\n" .
               "  - Statutory Deductions Reports\n" .
               "  - Commission & Transaction Reports\n" .
               "  - Portfolio Analysis\n" .
               "• Real-time Financial Metrics\n" .
               "• Performance Analytics\n\n" .
               "📎 **Quick Links:**\n" .
               "[button:Financial Reports|../reports.php]\n" .
               "[button:Balance Sheet|../balance_sheet_final_working.php]\n" .
               "[button:Client Management|../client_management.php]\n" .
               "[button:Trades|../trades.php]";
    }
    
    // 13. Real-time metrics query
    elseif (strpos($message_lower, 'how are we doing') !== false ||
            strpos($message_lower, 'current status') !== false ||
            strpos($message_lower, 'real-time') !== false ||
            strpos($message_lower, 'live data') !== false) {
        
        $metrics = getFinancialMetrics($db, 'today');
        $yesterday_metrics = getFinancialMetrics($db, 'yesterday');
        $currency = getCompanyInfo($db)['currency'] ?? 'TZS';
        
        $response = "📊 **Real-Time Trading Status - Today**\n\n";
        
        $response .= "**Today's Activity:**\n";
        $response .= "• Trades: " . number_format($metrics['trades']) . "\n";
        $response .= "• Value: {$currency} " . safeNumberFormat($metrics['trade_value']) . "\n";
        $response .= "• Commission: {$currency} " . safeNumberFormat($metrics['commission']) . "\n\n";
        
        if ($yesterday_metrics['trades'] > 0) {
            $trade_growth = (($metrics['trades'] - $yesterday_metrics['trades']) / $yesterday_metrics['trades']) * 100;
            $value_growth = (($metrics['trade_value'] - $yesterday_metrics['trade_value']) / max(1, $yesterday_metrics['trade_value'])) * 100;
            
            $response .= "**vs Yesterday:**\n";
            $response .= "• Trades: " . ($trade_growth >= 0 ? "🟢 +" : "🔴 ") . number_format(abs($trade_growth), 1) . "%\n";
            $response .= "• Value: " . ($value_growth >= 0 ? "🟢 +" : "🔴 ") . number_format(abs($value_growth), 1) . "%\n\n";
        }
        
        $response .= "**Active Now:**\n";
        $response .= "• Active Clients: " . number_format($metrics['active_clients']) . "\n";
        $response .= "• Trading Clients: " . number_format($metrics['active_trading_clients']) . "\n\n";
        
        $response .= "📎 **Actions:**\n";
        $response .= "[button:Live Dashboard|../dashboard.php]\n";
        $response .= "[button:Today's Reports|../reports.php?period=today]\n";
        $response .= "[button:Real-time Monitor|../trades.php?filter=today]";
        
        return $response;
    }
    
    // 14. Period-specific queries
    elseif (preg_match('/(today|yesterday|this week|this month|this quarter|this year|last month)/i', $message_lower, $period_match)) {
        $period = strtolower(str_replace(' ', '_', $period_match[1]));
        $metrics = getFinancialMetrics($db, $period);
        $currency = getCompanyInfo($db)['currency'] ?? 'TZS';
        
        $period_label = ucwords(str_replace('_', ' ', $period));
        
        $response = "📅 **{$period_label} Summary**\n\n";
        $response .= "**Trading Activity:**\n";
        $response .= "• Period: {$metrics['period']}\n";
        $response .= "• Trades: " . number_format($metrics['trades']) . "\n";
        $response .= "• Trade Value: {$currency} " . safeNumberFormat($metrics['trade_value']) . "\n";
        $response .= "• Commission: {$currency} " . safeNumberFormat($metrics['commission']) . "\n";
        $response .= "• VAT (18%): {$currency} " . safeNumberFormat($metrics['vat']) . "\n";
        $response .= "• Net Revenue: {$currency} " . safeNumberFormat($metrics['commission'] + $metrics['vat']) . "\n\n";
        
        if ($metrics['cash_position'] > 0) {
            $response .= "**Financial Position:**\n";
            $response .= "• Cash: {$currency} " . safeNumberFormat($metrics['cash_position']) . "\n";
            if ($metrics['receivables'] > 0) {
                $response .= "• Receivables: {$currency} " . safeNumberFormat($metrics['receivables']) . "\n";
            }
            $response .= "\n";
        }
        
        $response .= "📎 **Actions:**\n";
        $response .= "[button:Generate Report|../reports.php?period=" . urlencode($period) . "]\n";
        $response .= "[button:Export Data|../reports.php?export=excel&period=" . urlencode($period) . "]\n";
        $response .= "[button:Compare Periods|../reports.php?compare=true]";
        
        return $response;
    }
    
    // 15. Default response
    else {
        $responses = [
            "I can help with financial reports, client info, equities, bonds, and calculations. Try 'help' for examples.",
            "Ask me about: financial reports, balance sheet, commissions, or portfolio analysis.",
            "Try: 'Generate statutory report' or 'Show balance sheet'",
            "Need financial insights? Try 'Show today's summary' or 'Generate commission report'",
            "I can generate financial reports, analyze performance, calculate fees, and provide trading insights. What do you need?"
        ];
        return $responses[array_rand($responses)];
    }
}

// ========== HELPER FUNCTIONS FOR ORIGINAL FEATURES ==========

function processContractNote($message, $db) {
    // Extract client name
    $client_name = "";
    if (preg_match('/for\s+([A-Za-z\s]+)/i', $message, $matches)) {
        $client_name = trim($matches[1]);
    }
    
    if (empty($client_name)) {
        return "Please specify a client name. Example: 'Generate contract note for John Mgini'";
    }
    
    // Get client from database
    $stmt = $db->prepare("SELECT * FROM clients WHERE client_name LIKE ? AND status = 'active' LIMIT 1");
    $stmt->execute(["%$client_name%"]);
    $client = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$client) {
        return "❌ Client '$client_name' not found in active clients.";
    }
    
    // Get client's recent trades
    $trades = [];
    try {
        $stmt_check = $db->query("SHOW TABLES LIKE 'trades'");
        if ($stmt_check->rowCount() > 0) {
            $stmt2 = $db->prepare("
                SELECT t.*, 
                       CASE 
                           WHEN t.security_type = 'equity' THEN e.description
                           WHEN t.security_type = 'bond' THEN b.bond_name
                           ELSE 'Unknown'
                       END as security_name,
                       CASE 
                           WHEN t.security_type = 'equity' THEN e.security_id
                           WHEN t.security_type = 'bond' THEN b.security_id
                           ELSE 'Unknown'
                       END as security_code
                FROM trades t 
                LEFT JOIN equities_settings e ON t.security_code = e.security_id AND t.security_type = 'equity'
                LEFT JOIN bonds b ON t.security_code = b.security_id AND t.security_type = 'bond'
                WHERE t.client_id = ? 
                AND t.status = 'completed'
                ORDER BY t.trade_date DESC 
                LIMIT 5
            ");
            $stmt2->execute([$client['id']]);
            $trades = $stmt2->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (Exception $e) {
        error_log("Trades table error: " . $e->getMessage());
    }
    
    $response = "📄 **Contract Note for {$client['client_name']}**\n\n";
    $response .= "**Client Details:**\n";
    $response .= "• Name: {$client['client_name']}\n";
    $response .= "• CDS Account: {$client['cds_account']}\n";
    $response .= "• Client Code: {$client['client_code']}\n";
    $response .= "• Type: " . ucfirst($client['client_type'] ?? '') . "\n";
    $response .= "• Phone: " . ($client['phone'] ?? 'N/A') . "\n";
    $response .= "• Email: " . ($client['email'] ?? 'N/A') . "\n";
    $response .= "• Fee Type: " . ucfirst(str_replace('_', ' ', $client['fee_type'] ?? '')) . "\n";
    if (!empty($client['default_brokerage_fee'])) {
        $response .= "• Brokerage Fee: {$client['default_brokerage_fee']}%\n";
    }
    $response .= "\n";
    
    if (!empty($trades)) {
        $response .= "**Recent Trades:**\n";
        $total_value = 0;
        $commission_total = 0;
        
        foreach ($trades as $trade) {
            $type = ($trade['trade_type'] ?? 'buy') == 'buy' ? '🟢 BUY' : '🔴 SELL';
            $price = $trade['price'] ?? 0;
            $quantity = $trade['quantity'] ?? 0;
            $value = $price * $quantity;
            $total_value += $value;
            
            $commission_rate = !empty($client['default_brokerage_fee']) ? ($client['default_brokerage_fee'] / 100) : 0.015;
            $commission = $value * $commission_rate;
            $commission_total += $commission;
            
            $response .= "• {$trade['security_name']} ({$trade['security_code']})\n";
            $response .= "  Type: {$type} | Qty: " . number_format($quantity) . "\n";
            $response .= "  Price: TZS " . safeNumberFormat($price) . "\n";
            $response .= "  Value: TZS " . safeNumberFormat($value) . "\n";
            $response .= "  Commission: TZS " . safeNumberFormat($commission) . "\n\n";
        }
        
        $vat = $commission_total * 0.18;
        $total_fees = $commission_total + $vat;
        
        $response .= "**Financial Summary:**\n";
        $response .= "• Total Trade Value: TZS " . safeNumberFormat($total_value) . "\n";
        $response .= "• Total Commission: TZS " . safeNumberFormat($commission_total) . "\n";
        $response .= "• VAT (18%): TZS " . safeNumberFormat($vat) . "\n";
        $response .= "• **Total Fees:** TZS " . safeNumberFormat($total_fees) . "\n";
        $response .= "• **Net Amount:** TZS " . safeNumberFormat($total_value + $total_fees) . "\n\n";
    } else {
        $response .= "**Note:** No trades found for this client.\n\n";
    }
    
    $response .= "📎 **Actions:**\n";
    $response .= "[button:Generate PDF Contract|../reports/contract_note_pdf.php?client_id={$client['id']}]\n";
    $response .= "[button:View Client Details|../client_management.php?id={$client['id']}]\n";
    $response .= "[button:Add Trade for Client|../trades.php?action=add&client_id={$client['id']}]";
    
    return $response;
}

function processClientInfo($message, $db) {
    $client_name = "";
    if (preg_match('/for\s+([A-Za-z\s]+)/i', $message, $matches)) {
        $client_name = trim($matches[1]);
    }
    
    if (empty($client_name)) {
        return "Please specify client name. Example: 'Client info for John Mgini'";
    }
    
    $stmt = $db->prepare("
        SELECT * FROM clients 
        WHERE client_name LIKE ? 
        ORDER BY 
            CASE WHEN status = 'active' THEN 1 ELSE 2 END,
            client_name
        LIMIT 5
    ");
    $stmt->execute(["%$client_name%"]);
    $clients = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($clients)) {
        return "❌ No clients found matching '$client_name'";
    }
    
    $response = "👤 **Client Information:**\n\n";
    foreach ($clients as $client) {
        $trade_count = 0;
        $total_value = 0;
        try {
            $stmt_check = $db->query("SHOW TABLES LIKE 'trades'");
            if ($stmt_check->rowCount() > 0) {
                $stmt2 = $db->prepare("SELECT COUNT(*) as count FROM trades WHERE client_id = ? AND status = 'completed'");
                $stmt2->execute([$client['id']]);
                $trade_count = $stmt2->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;
                
                $stmt3 = $db->prepare("SELECT SUM(quantity * price) as total FROM trades WHERE client_id = ? AND status = 'completed'");
                $stmt3->execute([$client['id']]);
                $total_result = $stmt3->fetch(PDO::FETCH_ASSOC);
                $total_value = $total_result['total'] ?? 0;
            }
        } catch (Exception $e) {}
        
        $status_badge = ($client['status'] ?? '') == 'active' ? '🟢' : '🔴';
        
        $response .= "{$status_badge} **{$client['client_name']}**\n";
        $response .= "  CDS: {$client['cds_account']} | Code: {$client['client_code']}\n";
        $response .= "  Type: " . ucfirst($client['client_type'] ?? '') . "\n";
        $response .= "  Phone: " . ($client['phone'] ?? 'N/A') . "\n";
        $response .= "  Email: " . ($client['email'] ?? 'N/A') . "\n";
        $response .= "  Fee: " . (!empty($client['default_brokerage_fee']) ? $client['default_brokerage_fee'] . '%' : '1.5%') . " ({$client['fee_type']})\n";
        $response .= "  Trades: {$trade_count} | Value: TZS " . safeNumberFormat($total_value) . "\n\n";
    }
    
    $response .= "📎 **Actions:**\n";
    $response .= "[button:View All Clients|../client_management.php?search=" . urlencode($client_name) . "]\n";
    $response .= "[button:Add New Client|../client_management.php?action=add]";
    
    return $response;
}

function processEquitySearch($message, $db) {
    $search = "";
    if (preg_match('/for\s+([A-Za-z0-9\s]+)/i', $message, $matches)) {
        $search = trim($matches[1]);
    } else {
        $search = "";
    }
    
    if (empty($search)) {
        $stmt = $db->prepare("
            SELECT * FROM equities_settings 
            WHERE is_active = 1 
            ORDER BY security_id 
            LIMIT 15
        ");
        $stmt->execute();
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($results)) {
            return "❌ No active equities found.";
        }
        
        $response = "📈 **Active Equities (Stocks):**\n\n";
        foreach ($results as $equity) {
            $response .= "• **{$equity['security_id']}** - {$equity['description']}\n";
            $response .= "  Price: TZS " . safeNumberFormat($equity['market_price']) . "\n";
            $response .= "  ISIN: {$equity['isin']}\n";
            $response .= "  Sector: {$equity['economic_sector']}\n\n";
        }
        
        $response .= "📎 **Actions:**\n";
        $response .= "[button:View All Equities|../equities_settings.php]\n";
        $response .= "[button:Add New Equity|../equities_settings.php?action=add]";
        
        return $response;
    } else {
        $stmt = $db->prepare("
            SELECT * FROM equities_settings 
            WHERE (security_id LIKE ? OR description LIKE ?) 
            AND is_active = 1 
            ORDER BY security_id 
            LIMIT 10
        ");
        $stmt->execute(["%$search%", "%$search%"]);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($results)) {
            return "❌ No equities found matching '$search'";
        }
        
        $response = "📈 **Equity Search Results for '$search':**\n\n";
        foreach ($results as $equity) {
            $response .= "• **{$equity['security_id']}** - {$equity['description']}\n";
            $response .= "  Market Price: TZS " . safeNumberFormat($equity['market_price']) . "\n";
            $response .= "  Valuation: TZS " . safeNumberFormat($equity['valuation_price']) . "\n";
            $response .= "  ISIN: {$equity['isin']}\n";
            $response .= "  Sector: {$equity['economic_sector']}\n\n";
        }
        
        $response .= "📎 **Actions:**\n";
        $response .= "[button:Trade this Equity|../trades.php?security={$results[0]['security_id']}]\n";
        $response .= "[button:View Details|../equities_settings.php?id={$results[0]['id']}]";
        
        return $response;
    }
}

function processBondSearch($message, $db) {
    $search = "";
    if (preg_match('/for\s+([A-Za-z0-9\s]+)/i', $message, $matches)) {
        $search = trim($matches[1]);
    } else {
        $search = "";
    }
    
    if (empty($search)) {
        $stmt = $db->prepare("
            SELECT * FROM bonds 
            WHERE status = 'active' 
            ORDER BY id DESC 
            LIMIT 10
        ");
        $stmt->execute();
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($results)) {
            return "❌ No active bonds found.";
        }
        
        $response = "💰 **Recent Active Bonds:**\n\n";
        foreach ($results as $bond) {
            $maturity_date = (!empty($bond['maturity_date']) && $bond['maturity_date'] != '0000-00-00') ? date('d/m/Y', strtotime($bond['maturity_date'])) : 'N/A';
            $issue_date = (!empty($bond['issue_date']) && $bond['issue_date'] != '0000-00-00') ? date('d/m/Y', strtotime($bond['issue_date'])) : 'N/A';
            
            $response .= "• **{$bond['security_id']}** - {$bond['bond_name']}\n";
            $response .= "  Coupon: {$bond['coupon_rate']}%\n";
            $response .= "  Issuer: {$bond['issuer']}\n";
            $response .= "  Issue: {$issue_date} | Maturity: {$maturity_date}\n";
            $response .= "  ISIN: " . ($bond['isin'] ?? 'N/A') . "\n\n";
        }
        
        $response .= "📎 **Actions:**\n";
        $response .= "[button:View All Bonds|../bonds.php]\n";
        $response .= "[button:Upload New Bonds|../enter_bonds.php]";
        
        return $response;
    } else {
        $stmt = $db->prepare("
            SELECT * FROM bonds 
            WHERE (security_id LIKE ? OR bond_name LIKE ? OR issuer LIKE ? OR isin LIKE ?) 
            AND status = 'active' 
            ORDER BY security_id 
            LIMIT 10
        ");
        $stmt->execute(["%$search%", "%$search%", "%$search%", "%$search%"]);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($results)) {
            return "❌ No bonds found matching '$search'";
        }
        
        $response = "💰 **Bond Search Results for '$search':**\n\n";
        foreach ($results as $bond) {
            $maturity_date = (!empty($bond['maturity_date']) && $bond['maturity_date'] != '0000-00-00') ? date('d/m/Y', strtotime($bond['maturity_date'])) : 'N/A';
            $issue_date = (!empty($bond['issue_date']) && $bond['issue_date'] != '0000-00-00') ? date('d/m/Y', strtotime($bond['issue_date'])) : 'N/A';
            
            $response .= "• **{$bond['security_id']}** - {$bond['bond_name']}\n";
            $response .= "  Coupon: {$bond['coupon_rate']}%\n";
            $response .= "  Issuer: {$bond['issuer']}\n";
            $response .= "  Issue: {$issue_date} | Maturity: {$maturity_date}\n";
            $response .= "  Term: {$bond['term_years']} years\n";
            $response .= "  ISIN: " . ($bond['isin'] ?? 'N/A') . "\n\n";
        }
        
        $response .= "📎 **Actions:**\n";
        $response .= "[button:Trade this Bond|../trades.php?bond_id={$results[0]['security_id']}]\n";
        $response .= "[button:View Details|../bonds.php?id={$results[0]['id']}]";
        
        return $response;
    }
}

function processFeeCalculation($message) {
    $amount = 0;
    if (preg_match('/[\d,]+(\.\d+)?/', $message, $matches)) {
        $amount = floatval(str_replace(',', '', $matches[0]));
    }
    
    if ($amount <= 0) {
        return "Please specify amount. Example: 'Calculate fees for 5,000,000'";
    }
    
    $is_bond = strpos(strtolower($message), 'bond') !== false;
    
    if ($is_bond) {
        $commission_rate = 0.005;
        $min_commission = 25000;
        $trade_type = 'Bond Trade';
    } else {
        $commission_rate = 0.015;
        $min_commission = 10000;
        $trade_type = 'Equity Trade';
    }
    
    $commission = $amount * $commission_rate;
    if ($commission < $min_commission) {
        $commission = $min_commission;
    }
    
    $vat = $commission * 0.18;
    $total_fees = $commission + $vat;
    $net_amount = $amount + ($is_bond ? -$total_fees : $total_fees);
    
    $response = "🧮 **Fee Calculation for {$trade_type}**\n\n";
    $response .= "• Amount: TZS " . safeNumberFormat($amount) . "\n";
    $response .= "• Commission (" . ($commission_rate * 100) . "%): TZS " . safeNumberFormat($commission);
    if ($commission == $min_commission) {
        $response .= " (minimum)";
    }
    $response .= "\n";
    $response .= "• VAT (18%): TZS " . safeNumberFormat($vat) . "\n";
    $response .= "• **Total Fees:** TZS " . safeNumberFormat($total_fees) . "\n";
    $response .= "• **Net Amount:** TZS " . safeNumberFormat($net_amount);
    if ($is_bond) {
        $response .= " (received for sale)";
    } else {
        $response .= " (payable for purchase)";
    }
    
    $response .= "\n\n📎 **Actions:**\n";
    $response .= "[button:Record New Trade|../trades.php?action=add]\n";
    $response .= "[button:View Fee Structure|../reports/fee_structure.php]";
    
    return $response;
}

// Get user info for display
$username = $user['username'] ?? 'User';
$user_role = $user['role'] ?? 'Guest';

// Include header
include '../includes/header.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Stock Exchange & Financial Assistant</title>
    
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    
    <style>
        body {
            background: #f8f9fa;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
        }
        
        .chat-container {
            min-height: 500px;
            max-height: 500px;
            overflow-y: auto;
            padding: 20px;
            background: #fff;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            scroll-behavior: smooth;
        }
        
        .user-message {
            margin: 15px 0;
            display: flex;
            justify-content: flex-end;
        }
        
        .bot-message {
            margin: 15px 0;
            display: flex;
            justify-content: flex-start;
        }
        
        .user-bubble {
            background: linear-gradient(135deg, #007bff, #0056b3);
            color: white;
            padding: 12px 18px;
            border-radius: 18px 18px 4px 18px;
            max-width: 80%;
            word-wrap: break-word;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }
        
        .bot-bubble {
            background: #f1f3f4;
            color: #202124;
            padding: 12px 18px;
            border-radius: 18px 18px 18px 4px;
            max-width: 80%;
            word-wrap: break-word;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
            line-height: 1.5;
        }
        
        .bot-bubble a.action-button {
            color: #1a73e8;
            text-decoration: none;
            font-weight: 500;
            background: #e8f0fe;
            padding: 4px 12px;
            border-radius: 16px;
            margin: 2px 4px;
            display: inline-block;
            border: 1px solid #d2e3fc;
            transition: all 0.2s;
        }
        
        .bot-bubble a.action-button:hover {
            background: #d2e3fc;
            text-decoration: none;
            transform: translateY(-1px);
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .message-time {
            font-size: 11px;
            color: #6c757d;
            margin-top: 4px;
            display: flex;
            align-items: center;
        }
        
        .chat-input {
            border-radius: 24px;
            border: 2px solid #dee2e6;
            padding: 12px 20px;
            font-size: 16px;
            transition: all 0.3s;
        }
        
        .chat-input:focus {
            border-color: #007bff;
            box-shadow: 0 0 0 3px rgba(0,123,255,0.1);
        }
        
        .btn-send {
            border-radius: 24px;
            padding: 12px 30px;
            font-weight: 600;
            background: linear-gradient(135deg, #007bff, #0056b3);
            border: none;
        }
        
        .btn-send:hover {
            background: linear-gradient(135deg, #0056b3, #004085);
        }
        
        .quick-btn {
            border-radius: 20px;
            padding: 8px 16px;
            margin: 3px;
            font-size: 14px;
            transition: all 0.2s;
        }
        
        .quick-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }
        
        .recent-chat {
            cursor: pointer;
            padding: 8px 12px;
            border-radius: 8px;
            margin-bottom: 5px;
            border-left: 3px solid #007bff;
            transition: all 0.2s;
        }
        
        .recent-chat:hover {
            background: #f8f9fa;
            transform: translateX(2px);
        }
        
        .card {
            border: none;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            border-radius: 12px;
        }
        
        .card-header {
            border-radius: 12px 12px 0 0 !important;
        }
        
        /* Action buttons in messages */
        .action-buttons {
            margin-top: 10px;
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }
        
        .action-btn {
            background: #e8f0fe;
            border: 1px solid #d2e3fc;
            color: #1a73e8;
            padding: 6px 12px;
            border-radius: 16px;
            font-size: 13px;
            text-decoration: none;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        
        .action-btn:hover {
            background: #d2e3fc;
            text-decoration: none;
            transform: translateY(-1px);
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .action-btn i {
            font-size: 12px;
        }
        
        /* Financial highlight colors */
        .positive {
            color: #28a745;
        }
        
        .negative {
            color: #dc3545;
        }
        
        .highlight {
            background: linear-gradient(120deg, #e3f2fd 0%, #f3e5f5 100%);
            border-left: 4px solid #007bff;
            padding: 10px;
            border-radius: 0 8px 8px 0;
            margin: 5px 0;
        }
    </style>
</head>
<body>
    <div class="container-fluid py-4">
        <div class="row">
            <!-- Sidebar -->
            <div class="col-md-3">
                <div class="card mb-3">
                    <div class="card-header bg-primary">
                        <h5 class="mb-0"><i class="bi bi-robot me-2"></i>Financial & Trading Assistant</h5>
                    </div>
                    <div class="card-body">
                        <!-- User Info -->
                        <div class="d-flex align-items-center mb-3">
                            <div class="bg-primary rounded-circle p-2 me-3">
                                <i class="bi bi-person-fill fs-5"></i>
                            </div>
                            <div>
                                <h6 class="mb-0"><?php echo htmlspecialchars($username ?? 'User'); ?></h6>
                                <small class="text-white-50"><?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $user_role ?? 'Guest'))); ?></small>
                            </div>
                        </div>
                        
                        <hr class="my-3">
                        
                        <!-- Quick Actions -->
                        <h6 class="text-muted mb-2"><i class="bi bi-lightning me-1"></i>Quick Actions</h6>
                        <div class="d-grid gap-2 mb-3">
                            <button class="btn btn-outline-primary quick-btn" onclick="setMessage('Generate contract note for')">
                                <i class="bi bi-file-earmark-pdf me-1"></i>Contract Note
                            </button>
                            <button class="btn btn-outline-success quick-btn" onclick="setMessage('Show balance sheet')">
                                <i class="bi bi-graph-up me-1"></i>Balance Sheet
                            </button>
                            <button class="btn btn-outline-info quick-btn" onclick="setMessage('Generate commission report')">
                                <i class="bi bi-cash-stack me-1"></i>Commission
                            </button>
                            <button class="btn btn-outline-warning quick-btn" onclick="setMessage('Today\'s summary')">
                                <i class="bi bi-bar-chart me-1"></i>Today's Summary
                            </button>
                        </div>
                        
                        <!-- Financial Quick Stats -->
                        <div class="mt-3 p-2 bg-light rounded">
                            <h6 class="text-muted mb-2"><i class="bi bi-speedometer2 me-1"></i>Quick Stats</h6>
                            <?php
                            try {
                                $today_metrics = getFinancialMetrics($db, 'today');
                                $company = getCompanyInfo($db);
                                $currency = $company['currency'] ?? 'TZS';
                            ?>
                            <div class="small">
                                <div class="d-flex justify-content-between">
                                    <span>Today's Trades:</span>
                                    <strong><?php echo number_format($today_metrics['trades']); ?></strong>
                                </div>
                                <div class="d-flex justify-content-between">
                                    <span>Today's Value:</span>
                                    <strong><?php echo $currency . ' ' . safeNumberFormat($today_metrics['trade_value'], 0); ?></strong>
                                </div>
                                <div class="d-flex justify-content-between">
                                    <span>Active Clients:</span>
                                    <strong><?php echo number_format($today_metrics['active_clients']); ?></strong>
                                </div>
                            </div>
                            <?php } catch (Exception $e) { ?>
                            <div class="text-center text-muted small">
                                <i class="bi bi-info-circle"></i> Stats loading...
                            </div>
                            <?php } ?>
                        </div>
                        
                        <!-- Recent Chats -->
                        <h6 class="text-muted mb-2 mt-3"><i class="bi bi-clock-history me-1"></i>Recent Chats</h6>
                        <div id="recentChats" class="mb-3">
                            <?php
                            $recent = array_slice($_SESSION['chat_history'], -6);
                            foreach(array_reverse($recent) as $chat):
                                if($chat['type'] == 'user'):
                                    $text = substr($chat['user'], 0, 25);
                                    if(strlen($chat['user']) > 25) $text .= '...';
                            ?>
                            <div class="recent-chat" onclick="setMessage('<?php echo addslashes($chat['user']); ?>')">
                                <div class="d-flex justify-content-between">
                                    <small class="text-primary fw-medium"><?php echo htmlspecialchars($text); ?></small>
                                    <small class="text-muted"><?php echo $chat['time']; ?></small>
                                </div>
                            </div>
                            <?php endif; endforeach; 
                            
                            if(empty(array_filter($recent, fn($c) => $c['type'] == 'user'))):
                            ?>
                            <div class="text-center text-muted py-3">
                                <small>No recent chats</small>
                            </div>
                            <?php endif; ?>
                        </div>
                        
                        <hr class="my-3">
                        
                        <!-- Actions -->
                        <div class="d-grid gap-2">
                            <a href="?clear=1" class="btn btn-outline-danger quick-btn" onclick="return confirm('Clear all chat history?')">
                                <i class="bi bi-trash me-1"></i>Clear Chat
                            </a>
                            <a href="../dashboard.php" class="btn btn-outline-secondary quick-btn">
                                <i class="bi bi-arrow-left me-1"></i>Dashboard
                            </a>
                        </div>
                    </div>
                </div>
                
                <!-- Quick Links -->
                <div class="card">
                    <div class="card-header bg-info text-white">
                        <h6 class="mb-0"><i class="bi bi-link-45deg me-2"></i>Financial Quick Links</h6>
                    </div>
                    <div class="list-group list-group-flush">
                        <a href="../reports.php" class="list-group-item list-group-item-action d-flex align-items-center">
                            <i class="bi bi-file-text text-primary me-2"></i>
                            <span>All Reports</span>
                        </a>
                        <a href="../balance_sheet_final_working.php" class="list-group-item list-group-item-action d-flex align-items-center">
                            <i class="bi bi-graph-up text-success me-2"></i>
                            <span>Balance Sheet</span>
                        </a>
                        <a href="../trades.php" class="list-group-item list-group-item-action d-flex align-items-center">
                            <i class="bi bi-arrow-left-right text-warning me-2"></i>
                            <span>Trades</span>
                        </a>
                        <a href="../client_management.php" class="list-group-item list-group-item-action d-flex align-items-center">
                            <i class="bi bi-people text-info me-2"></i>
                            <span>Clients</span>
                        </a>
                        <a href="../dashboard.php" class="list-group-item list-group-item-action d-flex align-items-center">
                            <i class="bi bi-speedometer2 text-danger me-2"></i>
                            <span>Dashboard</span>
                        </a>
                    </div>
                </div>
            </div>
            
            <!-- Main Chat Area -->
            <div class="col-md-9">
                <div class="card h-100">
                    <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center">
                        <div>
                            <h5 class="mb-0"><i class="bi bi-chat-left-text me-2"></i>Financial & Trading Assistant</h5>
                            <small class="text-white-50">Access financial reports, trading data, and analytics</small>
                        </div>
                        <span class="badge bg-warning text-dark">
                            <i class="bi bi-database me-1"></i>Live Financial Data
                        </span>
                    </div>
                    
                    <!-- Chat Messages -->
                    <div class="chat-container" id="chatMessages">
                        <?php if(empty($_SESSION['chat_history'])): ?>
                        <!-- Welcome Message -->
                        <div class="text-center my-5 py-5">
                            <div class="mb-4">
                                <i class="bi bi-robot display-1 text-primary"></i>
                            </div>
                            <h3 class="mb-3">Financial & Trading Assistant</h3>
                            <p class="text-muted mb-4">Ask me about financial reports, trading data, client info, or generate documents</p>
                            <div class="d-flex justify-content-center flex-wrap gap-2">
                                <button class="btn btn-sm btn-outline-primary" onclick="setMessage('Show balance sheet')">
                                    Balance Sheet
                                </button>
                                <button class="btn btn-sm btn-outline-success" onclick="setMessage('Generate commission report')">
                                    Commission
                                </button>
                                <button class="btn btn-sm btn-outline-info" onclick="setMessage('Today\'s summary')">
                                    Today's Stats
                                </button>
                                <button class="btn btn-sm btn-outline-warning" onclick="setMessage('Top performers')">
                                    Top Performers
                                </button>
                                <button class="btn btn-sm btn-outline-secondary" onclick="setMessage('Generate statutory report')">
                                    Statutory
                                </button>
                                <button class="btn btn-sm btn-outline-dark" onclick="setMessage('Financial ratios')">
                                    Ratios
                                </button>
                            </div>
                        </div>
                        <?php else: ?>
                            <?php foreach($_SESSION['chat_history'] as $message): ?>
                            <div class="<?php echo $message['type'] == 'user' ? 'user-message' : 'bot-message'; ?>">
                                <div class="<?php echo $message['type'] == 'user' ? 'user-bubble' : 'bot-bubble'; ?>">
                                    <?php 
                                    if($message['type'] == 'user') {
                                        echo htmlspecialchars($message['user']);
                                    } else {
                                        $content = $message['bot'] ?? '';
                                        // First, extract button links and replace with placeholders
                                        $button_matches = [];
                                        preg_match_all('/\[button:(.*?)\|(.*?)\]/', $content, $button_matches, PREG_SET_ORDER);
                                        
                                        // Remove button markers from content
                                        $content = preg_replace('/\[button:(.*?)\|(.*?)\]/', '', $content);
                                        
                                        // Format markdown-like syntax
                                        $content = preg_replace('/\*\*(.*?)\*\*/', '<strong>$1</strong>', $content);
                                        $content = preg_replace('/\*(.*?)\*/', '<em>$1</em>', $content);
                                        $content = preg_replace('/📄|👤|📈|💰|📊|🧮|🏢|🆘|💡|📎|❌|🟢|🔴|📋|📅|🏆|📊/', '<span style="font-size:1.2em">$0</span>', $content);
                                        $content = str_replace("\n", '<br>', $content);
                                        
                                        echo $content;
                                        
                                        // Add action buttons if any
                                        if (!empty($button_matches)) {
                                            echo '<div class="action-buttons mt-2">';
                                            foreach ($button_matches as $match) {
                                                $button_text = $match[1];
                                                $button_url = $match[2];
                                                echo '<a href="' . htmlspecialchars($button_url) . '" class="action-btn">';
                                                echo '<i class="bi bi-box-arrow-up-right"></i> ' . htmlspecialchars($button_text);
                                                echo '</a>';
                                            }
                                            echo '</div>';
                                        }
                                    }
                                    ?>
                                </div>
                                <div class="message-time">
                                    <?php echo $message['time']; ?>
                                    <?php if($message['type'] == 'bot'): ?>
                                    <i class="bi bi-robot ms-2"></i>
                                    <?php else: ?>
                                    <i class="bi bi-person-fill ms-2"></i>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Chat Input -->
                    <div class="card-footer">
                        <form method="POST" id="chatForm" class="d-flex align-items-center">
                            <input type="text" 
                                   class="form-control chat-input me-2 flex-grow-1" 
                                   name="message" 
                                   placeholder="Ask about financial reports, trading data, client info, or analytics..." 
                                   required
                                   id="messageInput"
                                   autocomplete="off"
                                   autofocus>
                            <button class="btn btn-primary btn-send" type="submit" id="submitBtn">
                                <i class="bi bi-send me-1"></i>Send
                            </button>
                        </form>
                        
                        <!-- Quick Examples -->
                        <div class="mt-3 d-flex flex-wrap justify-content-center gap-2">
                            <button class="btn btn-sm btn-outline-primary" onclick="setMessage('Show balance sheet')">
                                <i class="bi bi-graph-up me-1"></i>Balance Sheet
                            </button>
                            <button class="btn btn-sm btn-outline-success" onclick="setMessage('Generate commission report')">
                                <i class="bi bi-cash-stack me-1"></i>Commission
                            </button>
                            <button class="btn btn-sm btn-outline-info" onclick="setMessage('Today\'s summary')">
                                <i class="bi bi-calendar-day me-1"></i>Today
                            </button>
                            <button class="btn btn-sm btn-outline-warning" onclick="setMessage('Top performers')">
                                <i class="bi bi-trophy me-1"></i>Top
                            </button>
                            <button class="btn btn-sm btn-outline-secondary" onclick="setMessage('Generate statutory report')">
                                <i class="bi bi-shield-check me-1"></i>Statutory
                            </button>
                            <button class="btn btn-sm btn-outline-dark" onclick="setMessage('Financial ratios')">
                                <i class="bi bi-percent me-1"></i>Ratios
                            </button>
                            <button class="btn btn-sm btn-outline-danger" onclick="setMessage('Help')">
                                <i class="bi bi-question-circle me-1"></i>Help
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
    function scrollToBottom() {
        const chatMessages = document.getElementById('chatMessages');
        chatMessages.scrollTop = chatMessages.scrollHeight;
    }
    
    function setMessage(message) {
        const input = document.getElementById('messageInput');
        input.value = message;
        input.focus();
        
        if (message.endsWith(' for') || message.endsWith(' for ')) {
            const len = message.length;
            input.setSelectionRange(len, len);
        }
    }
    
    // Scroll to bottom on load
    document.addEventListener('DOMContentLoaded', function() {
        scrollToBottom();
        
        // Handle Enter key
        document.getElementById('messageInput').addEventListener('keypress', function(e) {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                document.getElementById('chatForm').submit();
            }
        });
        
        // Show loading on submit
        document.getElementById('chatForm').addEventListener('submit', function() {
            const btn = document.getElementById('submitBtn');
            const originalHTML = btn.innerHTML;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Processing...';
            btn.disabled = true;
            scrollToBottom();
            
            // Restore button after 5 seconds
            setTimeout(() => {
                if (btn.disabled) {
                    btn.innerHTML = originalHTML;
                    btn.disabled = false;
                }
            }, 5000);
        });
        
        // Auto-focus input
        document.getElementById('messageInput').focus();
        
        // Handle action button clicks
        document.addEventListener('click', function(e) {
            if (e.target.classList.contains('action-btn')) {
                // Add loading indicator
                const originalText = e.target.innerHTML;
                e.target.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Loading...';
                e.target.disabled = true;
                
                // Restore button after 2 seconds
                setTimeout(() => {
                    e.target.innerHTML = originalText;
                    e.target.disabled = false;
                }, 2000);
            }
        });
    });
    
    // Check for page navigation (back/forward)
    window.addEventListener('pageshow', function(event) {
        if (event.persisted) {
            location.reload();
        }
    });
    
    // Auto-suggestions based on input
    document.getElementById('messageInput').addEventListener('input', function(e) {
        const value = this.value.toLowerCase();
        const suggestions = {
            'bal': 'Show balance sheet',
            'com': 'Generate commission report',
            'tod': 'Today\'s summary',
            'top': 'Top performers',
            'sta': 'Generate statutory report',
            'rat': 'Financial ratios',
            'rep': 'Generate report',
            'cli': 'Client info for',
            'tra': 'Transaction summary',
            'por': 'Portfolio analysis'
        };
        
        for (const [key, suggestion] of Object.entries(suggestions)) {
            if (value.startsWith(key)) {
                // Could show suggestion tooltip here
                break;
            }
        }
    });
    </script>
    
    <?php include '../includes/footer.php'; ?>
</body>
</html>