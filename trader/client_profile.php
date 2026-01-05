<?php
require_once '../config/config.php';
require_once '../auth/auth_middleware.php';
require_once '../tcpdf/tcpdf.php';

// This is for trader access since it's linked from trades.php
require_trader();
require_mandate();

$db = getDBConnection();

$client = null;
$error_message = '';
$success_message = '';
$merged_clients = [];
$all_clients = [];
$selected_cds = null;
$show_individual_cds = false;

// Get company details from database
$company_stmt = $db->query("SELECT * FROM companies WHERE status = 'active' ORDER BY id LIMIT 1");
$company = $company_stmt->fetch();
$company_name = $company ? $company['company_name'] : 'Victory Financial Services LTD';

// Get all active clients for the dropdown (excluding current client if set)
try {
    $stmt = $db->prepare("SELECT id, cds_account, client_name FROM clients WHERE is_active = 1 ORDER BY client_name");
    $stmt->execute();
    $all_clients = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $error_message = "Error fetching clients: " . $e->getMessage();
}

// Handle CDS merging
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['merge_cds'])) {
    $primary_client_id = (int)$_POST['primary_client_id'];
    $merge_client_id = (int)$_POST['merge_client_id'];
    
    if ($primary_client_id && $merge_client_id && $primary_client_id != $merge_client_id) {
        try {
            // Get client details
            $stmt = $db->prepare("SELECT * FROM clients WHERE id = ? AND is_active = 1");
            $stmt->execute([$primary_client_id]);
            $primary_client = $stmt->fetch();
            
            $stmt->execute([$merge_client_id]);
            $merge_client = $stmt->fetch();
            
            if ($primary_client && $merge_client) {
                // Check if merge already exists
                $stmt = $db->prepare("SELECT id FROM merged_cds_accounts WHERE (primary_cds_account = ? AND merged_cds_account = ?) OR (primary_cds_account = ? AND merged_cds_account = ?)");
                $stmt->execute([$primary_client['cds_account'], $merge_client['cds_account'], $merge_client['cds_account'], $primary_client['cds_account']]);
                $existing_merge = $stmt->fetch();
                
                if (!$existing_merge) {
                    // Create merged_cds_accounts table if it doesn't exist
                    $create_table_sql = "CREATE TABLE IF NOT EXISTS merged_cds_accounts (
                        id INT PRIMARY KEY AUTO_INCREMENT,
                        primary_cds_account VARCHAR(50) NOT NULL,
                        merged_cds_account VARCHAR(50) NOT NULL,
                        merged_by INT NOT NULL,
                        merged_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                        status ENUM('active', 'inactive') DEFAULT 'active',
                        FOREIGN KEY (merged_by) REFERENCES users(id) ON DELETE CASCADE,
                        INDEX idx_primary (primary_cds_account),
                        INDEX idx_merged (merged_cds_account)
                    )";
                    $db->exec($create_table_sql);
                    
                    // Insert merge record
                    $stmt = $db->prepare("INSERT INTO merged_cds_accounts (primary_cds_account, merged_cds_account, merged_by) VALUES (?, ?, ?)");
                    $stmt->execute([$primary_client['cds_account'], $merge_client['cds_account'], $_SESSION['user_id']]);
                    
                    $success_message = "CDS accounts merged successfully!";
                } else {
                    $error_message = "These CDS accounts are already merged.";
                }
            } else {
                $error_message = "One or both clients not found.";
            }
        } catch (Exception $e) {
            $error_message = "Error merging CDS accounts: " . $e->getMessage();
        }
    } else {
        $error_message = "Please select two different clients to merge.";
    }
}

// Handle CDS unmerging
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['unmerge_cds'])) {
    $merge_id = (int)$_POST['merge_id'];
    
    if ($merge_id) {
        try {
            $stmt = $db->prepare("DELETE FROM merged_cds_accounts WHERE id = ? AND merged_by = ?");
            $stmt->execute([$merge_id, $_SESSION['user_id']]);
            
            if ($stmt->rowCount() > 0) {
                $success_message = "CDS accounts unmerged successfully!";
            } else {
                $error_message = "Merge record not found or you don't have permission to unmerge.";
            }
        } catch (Exception $e) {
            $error_message = "Error unmerging CDS accounts: " . $e->getMessage();
        }
    }
}

// Handle CDS selection for individual view
if (isset($_GET['view_cds']) && !empty($_GET['view_cds'])) {
    $selected_cds = trim($_GET['view_cds']);
    $show_individual_cds = true;
}

// Handle date filter
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : '';
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : '';

// Handle CSV export
if (isset($_GET['export']) && $_GET['export'] == 'csv') {
    if (isset($_GET['id']) && is_numeric($_GET['id'])) {
        $client_id = (int)$_GET['id'];
        
        // Fetch client details
        $stmt = $db->prepare("SELECT * FROM clients WHERE id = ? AND is_active = 1");
        $stmt->execute([$client_id]);
        $client = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($client) {
            generateClientTransactionCSV($client);
            exit;
        } else {
            $error_message = "Client not found or is inactive.";
        }
    }
}

// Handle filtered CSV export
if (isset($_GET['export_filtered']) && $_GET['export_filtered'] == 'csv') {
    if (isset($_GET['id']) && is_numeric($_GET['id'])) {
        $client_id = (int)$_GET['id'];
        
        // Fetch client details
        $stmt = $db->prepare("SELECT * FROM clients WHERE id = ? AND is_active = 1");
        $stmt->execute([$client_id]);
        $client = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($client) {
            generateFilteredTransactionCSV($client, $date_from, $date_to, $selected_cds);
            exit;
        } else {
            $error_message = "Client not found or is inactive.";
        }
    }
}

// Handle PDF export
if (isset($_GET['export']) && $_GET['export'] == 'pdf') {
    if (isset($_GET['id']) && is_numeric($_GET['id'])) {
        $client_id = (int)$_GET['id'];
        
        // Fetch client details
        $stmt = $db->prepare("SELECT * FROM clients WHERE id = ? AND is_active = 1");
        $stmt->execute([$client_id]);
        $client = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($client) {
            generateClientTransactionPDF($client);
            exit;
        } else {
            $error_message = "Client not found or is inactive.";
        }
    }
}

// Handle filtered PDF export
if (isset($_GET['export_filtered']) && $_GET['export_filtered'] == 'pdf') {
    if (isset($_GET['id']) && is_numeric($_GET['id'])) {
        $client_id = (int)$_GET['id'];
        
        // Fetch client details
        $stmt = $db->prepare("SELECT * FROM clients WHERE id = ? AND is_active = 1");
        $stmt->execute([$client_id]);
        $client = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($client) {
            generateFilteredTransactionPDF($client, $date_from, $date_to, $selected_cds);
            exit;
        } else {
            $error_message = "Client not found or is inactive.";
        }
    }
}

// Handle client retrieval - accept both ID and CDS account
if (isset($_GET['id']) && is_numeric($_GET['id'])) {
    $client_id = (int)$_GET['id'];
    
    // Fetch client details
    try {
        $stmt = $db->prepare("SELECT * FROM clients WHERE id = ? AND is_active = 1");
        $stmt->execute([$client_id]);
        $client = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$client) {
            $error_message = "Client not found or is inactive.";
        } else {
            // Get merged CDS accounts for this client
            $stmt = $db->prepare("
                SELECT c.*, m.id as merge_id
                FROM clients c
                INNER JOIN merged_cds_accounts m ON (
                    m.merged_cds_account = c.cds_account OR 
                    m.primary_cds_account = c.cds_account
                )
                WHERE (
                    m.primary_cds_account = ? OR 
                    m.merged_cds_account = ?
                ) 
                AND c.id != ?
                AND m.status = 'active'
                AND c.is_active = 1
                ORDER BY c.client_name
            ");
            $stmt->execute([$client['cds_account'], $client['cds_account'], $client['id']]);
            $merged_clients = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (Exception $e) {
        $error_message = "Database error: " . $e->getMessage();
    }
} elseif (isset($_GET['cds']) && !empty($_GET['cds'])) {
    $cds_account = trim($_GET['cds']);
    
    // Fetch client details by CDS account
    try {
        $stmt = $db->prepare("SELECT * FROM clients WHERE cds_account = ? AND is_active = 1");
        $stmt->execute([$cds_account]);
        $client = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$client) {
            $error_message = "Client not found or is inactive.";
        } else {
            // Get merged CDS accounts for this client
            $stmt = $db->prepare("
                SELECT c.*, m.id as merge_id
                FROM clients c
                INNER JOIN merged_cds_accounts m ON (
                    m.merged_cds_account = c.cds_account OR 
                    m.primary_cds_account = c.cds_account
                )
                WHERE (
                    m.primary_cds_account = ? OR 
                    m.merged_cds_account = ?
                ) 
                AND c.id != ?
                AND m.status = 'active'
                AND c.is_active = 1
                ORDER BY c.client_name
            ");
            $stmt->execute([$client['cds_account'], $client['cds_account'], $client['id']]);
            $merged_clients = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (Exception $e) {
        $error_message = "Database error: " . $e->getMessage();
    }
} else {
    $error_message = "Invalid client identifier provided.";
}

// Handle client update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_client'])) {
    $client_id = (int)$_POST['client_id'];
    $client_name = trim($_POST['client_name']);
    $phone = trim($_POST['phone']);
    $email = trim($_POST['email']);
    $client_type = trim($_POST['client_type']);
    
    // Input validation
    if (empty($client_name) || empty($phone) || empty($email) || empty($client_type)) {
        $error_message = "All fields are required.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error_message = "Invalid email format.";
    } elseif (!preg_match('/^[0-9+\-\s]+$/', $phone)) {
        $error_message = "Invalid phone number format.";
    } elseif (strlen($client_name) > 255) {
        $error_message = "Client name is too long.";
    } else {
        try {
            $stmt = $db->prepare("UPDATE clients SET client_name = ?, phone = ?, email = ?, client_type = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$client_name, $phone, $email, $client_type, $client_id]);
            
            if ($stmt->rowCount() > 0) {
                $success_message = "Client details updated successfully!";
                
                // Refresh client data
                $stmt = $db->prepare("SELECT * FROM clients WHERE id = ? AND is_active = 1");
                $stmt->execute([$client_id]);
                $client = $stmt->fetch(PDO::FETCH_ASSOC);
            } else {
                $error_message = "No changes were made or client not found.";
            }
        } catch (Exception $e) {
            $error_message = "Error updating client: " . $e->getMessage();
        }
    }
}

// Fetch ALL investments (trades) if client is found - REMOVED uploaded_by restriction
$all_trades = [];
$filtered_trades = [];
$individual_cds_trades = [];
$holdings_by_asset_class = [
    'equity' => ['quantity' => 0, 'value' => 0],
    'bond' => ['quantity' => 0, 'value' => 0],
    'Exchange Traded Funds' => ['quantity' => 0, 'value' => 0]
];
$individual_holdings_by_asset_class = [
    'equity' => ['quantity' => 0, 'value' => 0],
    'bond' => ['quantity' => 0, 'value' => 0],
    'Exchange Traded Funds' => ['quantity' => 0, 'value' => 0]
];

if ($client) {
    try {
        // Build WHERE conditions based on filters
        $where_conditions = [];
        $params = [];
        
        // Get ALL trades for this client (including merged accounts)
        $cds_accounts = [$client['cds_account']];
        foreach ($merged_clients as $merged_client) {
            $cds_accounts[] = $merged_client['cds_account'];
        }
        
        $placeholders = str_repeat('?,', count($cds_accounts) - 1) . '?';
        
        // Base query - REMOVED uploaded_by restriction
        $query = "
            SELECT t.*, t.trade_side,
                   COALESCE(e.stock_name, b.security_id, etf.stock_name) AS asset_name,
                   e.share_type,
                   et.isin as etf_isin,
                   b.coupon_rate,
                   b.maturity_date,
                   c.client_name,
                   c.cds_account
            FROM trades t
            LEFT JOIN equities e ON t.security_id = e.security_id AND t.asset_class = 'equity'
            LEFT JOIN bonds b ON t.security_id = b.security_id AND t.asset_class = 'bond'
            LEFT JOIN equities etf ON t.security_id = etf.security_id AND t.asset_class = 'Exchange Traded Funds'
            LEFT JOIN etf_trades et ON t.trade_reference = et.trade_reference
            LEFT JOIN clients c ON t.client_cds_account = c.cds_account
            WHERE t.client_cds_account IN ($placeholders)
            AND t.status = 'active'
        ";
        
        $params = $cds_accounts;
        
        // Apply date filters if set
        if (!empty($date_from) && !empty($date_to)) {
            $query .= " AND t.trade_date BETWEEN ? AND ?";
            $params[] = $date_from;
            $params[] = $date_to;
        } elseif (!empty($date_from)) {
            $query .= " AND t.trade_date >= ?";
            $params[] = $date_from;
        } elseif (!empty($date_to)) {
            $query .= " AND t.trade_date <= ?";
            $params[] = $date_to;
        }
        
        $query .= " ORDER BY t.trade_date DESC, t.created_at DESC";
        
        $stmt = $db->prepare($query);
        $stmt->execute($params);
        $all_trades = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $filtered_trades = $all_trades; // Initially filtered trades are same as all trades
        
        // Calculate holdings by asset class for combined portfolio
        foreach ($all_trades as $trade) {
            $asset_class = strtolower($trade['asset_class']);
            if ($asset_class === 'exchange traded funds') {
                $asset_class = 'Exchange Traded Funds';
            }
            
            if (isset($holdings_by_asset_class[$asset_class])) {
                if ($trade['trade_side'] === 'buy') {
                    $holdings_by_asset_class[$asset_class]['quantity'] += (float)$trade['quantity'];
                    $holdings_by_asset_class[$asset_class]['value'] += (float)$trade['consideration'];
                } else {
                    $holdings_by_asset_class[$asset_class]['quantity'] -= (float)$trade['quantity'];
                    $holdings_by_asset_class[$asset_class]['value'] -= (float)$trade['consideration'];
                }
            }
        }
        
        // Get individual CDS trades if selected - REMOVED uploaded_by restriction
        if ($show_individual_cds && $selected_cds) {
            $query = "
                SELECT t.*, t.trade_side,
                       COALESCE(e.stock_name, b.security_id, etf.stock_name) AS asset_name,
                       e.share_type,
                       et.isin as etf_isin,
                       b.coupon_rate,
                       b.maturity_date,
                       c.client_name,
                       c.cds_account
                FROM trades t
                LEFT JOIN equities e ON t.security_id = e.security_id AND t.asset_class = 'equity'
                LEFT JOIN bonds b ON t.security_id = b.security_id AND t.asset_class = 'bond'
                LEFT JOIN equities etf ON t.security_id = etf.security_id AND t.asset_class = 'Exchange Traded Funds'
                LEFT JOIN etf_trades et ON t.trade_reference = et.trade_reference
                LEFT JOIN clients c ON t.client_cds_account = c.cds_account
                WHERE t.client_cds_account = ?
                AND t.status = 'active'
            ";
            
            $params = [$selected_cds];
            
            // Apply date filters if set
            if (!empty($date_from) && !empty($date_to)) {
                $query .= " AND t.trade_date BETWEEN ? AND ?";
                $params[] = $date_from;
                $params[] = $date_to;
            } elseif (!empty($date_from)) {
                $query .= " AND t.trade_date >= ?";
                $params[] = $date_from;
            } elseif (!empty($date_to)) {
                $query .= " AND t.trade_date <= ?";
                $params[] = $date_to;
            }
            
            $query .= " ORDER BY t.trade_date DESC, t.created_at DESC";
            
            $stmt = $db->prepare($query);
            $stmt->execute($params);
            $individual_cds_trades = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $filtered_trades = $individual_cds_trades; // Update filtered trades for individual view
            
            // Calculate holdings by asset class for individual CDS
            foreach ($individual_cds_trades as $trade) {
                $asset_class = strtolower($trade['asset_class']);
                if ($asset_class === 'exchange traded funds') {
                    $asset_class = 'Exchange Traded Funds';
                }
                
                if (isset($individual_holdings_by_asset_class[$asset_class])) {
                    if ($trade['trade_side'] === 'buy') {
                        $individual_holdings_by_asset_class[$asset_class]['quantity'] += (float)$trade['quantity'];
                        $individual_holdings_by_asset_class[$asset_class]['value'] += (float)$trade['consideration'];
                    } else {
                        $individual_holdings_by_asset_class[$asset_class]['quantity'] -= (float)$trade['quantity'];
                        $individual_holdings_by_asset_class[$asset_class]['value'] -= (float)$trade['consideration'];
                    }
                }
            }
        }
        
    } catch (Exception $e) {
        $error_message .= " Error fetching investments: " . $e->getMessage();
    }
}

// Function to calculate balance summary
function calculateBalanceSummary($trades) {
    $summary = [];
    
    foreach ($trades as $trade) {
        $security_id = $trade['security_id'];
        $asset_class = $trade['asset_class'];
        $quantity = (float)$trade['quantity'];
        $price = (float)$trade['price'];
        $consideration = (float)$trade['consideration'];
        
        if (!isset($summary[$security_id])) {
            $summary[$security_id] = [
                'security_id' => $security_id,
                'asset_class' => $asset_class,
                'buy_quantity' => 0,
                'buy_value' => 0,
                'sell_quantity' => 0,
                'sell_value' => 0,
                'avg_buy_price' => 0,
                'avg_sell_price' => 0,
                'asset_name' => $trade['asset_name'] ?? $security_id
            ];
        }
        
        if ($trade['trade_side'] === 'buy') {
            $summary[$security_id]['buy_quantity'] += $quantity;
            $summary[$security_id]['buy_value'] += $consideration;
            if ($summary[$security_id]['buy_quantity'] > 0) {
                $summary[$security_id]['avg_buy_price'] = $summary[$security_id]['buy_value'] / $summary[$security_id]['buy_quantity'];
            }
        } else {
            $summary[$security_id]['sell_quantity'] += $quantity;
            $summary[$security_id]['sell_value'] += $consideration;
            if ($summary[$security_id]['sell_quantity'] > 0) {
                $summary[$security_id]['avg_sell_price'] = $summary[$security_id]['sell_value'] / $summary[$security_id]['sell_quantity'];
            }
        }
    }
    
    // Calculate balance for each security
    foreach ($summary as &$item) {
        $item['balance_quantity'] = $item['buy_quantity'] - $item['sell_quantity'];
        $item['balance_value'] = $item['balance_quantity'] * $item['avg_buy_price'];
        $item['realized_pnl'] = $item['sell_value'] - ($item['sell_quantity'] * $item['avg_buy_price']);
        $item['current_value'] = $item['balance_quantity'] * $item['avg_buy_price'];
    }
    
    return array_values($summary);
}

// Calculate balance summary for combined portfolio
$balance_summary = [];
if (!empty($all_trades)) {
    $balance_summary = calculateBalanceSummary($all_trades);
}

// Calculate balance summary for individual CDS
$individual_balance_summary = [];
if ($show_individual_cds && !empty($individual_cds_trades)) {
    $individual_balance_summary = calculateBalanceSummary($individual_cds_trades);
}

// Function to generate transaction CSV - REMOVED uploaded_by restriction
function generateClientTransactionCSV($client) {
    global $db, $company_name;
    
    // Get all trades for this client - REMOVED uploaded_by restriction
    $stmt = $db->prepare("
        SELECT t.*, 
               COALESCE(e.stock_name, b.security_id, etf.stock_name) AS asset_name,
               e.share_type,
               b.coupon_rate,
               b.maturity_date
        FROM trades t
        LEFT JOIN equities e ON t.security_id = e.security_id AND t.asset_class = 'equity'
        LEFT JOIN bonds b ON t.security_id = b.security_id AND t.asset_class = 'bond'
        LEFT JOIN equities etf ON t.security_id = etf.security_id AND t.asset_class = 'Exchange Traded Funds'
        WHERE t.client_cds_account = ? 
        AND t.status = 'active'
        ORDER BY t.trade_date, t.created_at
    ");
    $stmt->execute([$client['cds_account']]);
    $all_trades = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Set headers for CSV download
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="client_transactions_' . $client['cds_account'] . '_' . date('Ymd_His') . '.csv"');
    header('Cache-Control: max-age=0');
    
    // Open output stream
    $output = fopen('php://output', 'w');
    
    // Add BOM for UTF-8
    fwrite($output, "\xEF\xBB\xBF");
    
    // Add headers
    fputcsv($output, ['Transaction Report - ' . $company_name]);
    fputcsv($output, ['Client: ' . $client['client_name']]);
    fputcsv($output, ['CDS Account: ' . $client['cds_account']]);
    fputcsv($output, ['Generated: ' . date('d/m/Y H:i:s')]);
    fputcsv($output, []); // Empty line
    
    // Add summary section
    fputcsv($output, ['SUMMARY']);
    fputcsv($output, ['Asset Class', 'Transactions', 'Buy Quantity', 'Buy Value (TZS)', 'Sell Quantity', 'Sell Value (TZS)', 'Net Quantity', 'Net Value (TZS)']);
    
    if (empty($all_trades)) {
        // Show zeros for each asset class when there are no trades
        $asset_classes = ['Equity', 'Bond', 'ETF'];
        foreach ($asset_classes as $class_name) {
            fputcsv($output, [
                $class_name,
                0,
                '0.00',
                '0.00',
                '0.00',
                '0.00',
                '0.00',
                '0.00'
            ]);
        }
    } else {
        // Separate trades by asset class
        $equity_trades = array_filter($all_trades, fn($t) => strtolower($t['asset_class']) === 'equity');
        $bond_trades = array_filter($all_trades, fn($t) => strtolower($t['asset_class']) === 'bond');
        $etf_trades = array_filter($all_trades, fn($t) => strtolower($t['asset_class']) === 'exchange traded funds');
        
        // Calculate totals for each asset class
        $asset_classes = [
            'Equity' => $equity_trades,
            'Bond' => $bond_trades,
            'ETF' => $etf_trades
        ];
        
        foreach ($asset_classes as $class_name => $trades) {
            $buy_quantity = array_sum(array_column(array_filter($trades, fn($t) => $t['trade_side'] === 'buy'), 'quantity'));
            $buy_value = array_sum(array_column(array_filter($trades, fn($t) => $t['trade_side'] === 'buy'), 'consideration'));
            $sell_quantity = array_sum(array_column(array_filter($trades, fn($t) => $t['trade_side'] === 'sell'), 'quantity'));
            $sell_value = array_sum(array_column(array_filter($trades, fn($t) => $t['trade_side'] === 'sell'), 'consideration'));
            
            fputcsv($output, [
                $class_name,
                count($trades),
                number_format($buy_quantity, 2),
                number_format($buy_value, 2),
                number_format($sell_quantity, 2),
                number_format($sell_value, 2),
                number_format($buy_quantity - $sell_quantity, 2),
                number_format($buy_value - $sell_value, 2)
            ]);
        }
    }
    
    fputcsv($output, []); // Empty line
    
    // Add transactions header
    fputcsv($output, ['Date', 'Security ID', 'Asset Name', 'Asset Class', 'Trade Side', 'Quantity', 'Price', 'Consideration (TZS)', 'Trade Reference', 'Status']);
    
    // Add transactions data (or show "No transactions found" if empty)
    if (empty($all_trades)) {
        fputcsv($output, ['No transactions found for this client']);
    } else {
        foreach ($all_trades as $trade) {
            fputcsv($output, [
                $trade['trade_date'],
                $trade['security_id'],
                $trade['asset_name'] ?? 'N/A',
                $trade['asset_class'],
                $trade['trade_side'],
                number_format($trade['quantity'], 2),
                number_format($trade['price'], 4),
                number_format($trade['consideration'], 2),
                $trade['trade_reference'],
                $trade['status']
            ]);
        }
    }
    
    fclose($output);
    exit;
}

// Function to generate filtered transaction CSV - REMOVED uploaded_by restriction
function generateFilteredTransactionCSV($client, $date_from, $date_to, $selected_cds = null) {
    global $db, $company_name;
    
    // Build query based on filters
    $query = "
        SELECT t.*, 
               COALESCE(e.stock_name, b.security_id, etf.stock_name) AS asset_name,
               e.share_type,
               b.coupon_rate,
               b.maturity_date,
               c.client_name,
               c.cds_account
        FROM trades t
        LEFT JOIN equities e ON t.security_id = e.security_id AND t.asset_class = 'equity'
        LEFT JOIN bonds b ON t.security_id = b.security_id AND t.asset_class = 'bond'
        LEFT JOIN equities etf ON t.security_id = etf.security_id AND t.asset_class = 'Exchange Traded Funds'
        LEFT JOIN clients c ON t.client_cds_account = c.cds_account
        WHERE t.status = 'active'
    ";
    
    $params = [];
    
    if ($selected_cds) {
        $query .= " AND t.client_cds_account = ?";
        $params[] = $selected_cds;
    } else {
        // Get merged accounts
        $merged_clients = [];
        $stmt = $db->prepare("
            SELECT c.* 
            FROM clients c
            INNER JOIN merged_cds_accounts m ON (
                m.merged_cds_account = c.cds_account OR 
                m.primary_cds_account = c.cds_account
            )
            WHERE (
                m.primary_cds_account = ? OR 
                m.merged_cds_account = ?
            ) 
            AND c.id != ?
            AND m.status = 'active'
            AND c.is_active = 1
        ");
        $stmt->execute([$client['cds_account'], $client['cds_account'], $client['id']]);
        $merged_clients = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $cds_accounts = [$client['cds_account']];
        foreach ($merged_clients as $merged_client) {
            $cds_accounts[] = $merged_client['cds_account'];
        }
        
        $placeholders = str_repeat('?,', count($cds_accounts) - 1) . '?';
        $query .= " AND t.client_cds_account IN ($placeholders)";
        $params = array_merge($params, $cds_accounts);
    }
    
    // Apply date filters
    if (!empty($date_from) && !empty($date_to)) {
        $query .= " AND t.trade_date BETWEEN ? AND ?";
        $params[] = $date_from;
        $params[] = $date_to;
    } elseif (!empty($date_from)) {
        $query .= " AND t.trade_date >= ?";
        $params[] = $date_from;
    } elseif (!empty($date_to)) {
        $query .= " AND t.trade_date <= ?";
        $params[] = $date_to;
    }
    
    $query .= " ORDER BY t.trade_date, t.created_at";
    
    $stmt = $db->prepare($query);
    $stmt->execute($params);
    $filtered_trades = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Set headers for CSV download
    header('Content-Type: text/csv');
    $filename = 'filtered_transactions_' . ($selected_cds ? $selected_cds : $client['cds_account']) . '_' . date('Ymd_His') . '.csv';
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0');
    
    // Open output stream
    $output = fopen('php://output', 'w');
    
    // Add BOM for UTF-8
    fwrite($output, "\xEF\xBB\xBF");
    
    // Add headers
    fputcsv($output, ['Filtered Transaction Report - ' . $company_name]);
    fputcsv($output, ['Client: ' . $client['client_name']]);
    fputcsv($output, ['CDS Account: ' . ($selected_cds ? $selected_cds : 'Combined Portfolio')]);
    fputcsv($output, ['Date Range: ' . ($date_from ? $date_from : 'Start') . ' to ' . ($date_to ? $date_to : 'End')]);
    fputcsv($output, ['Generated: ' . date('d/m/Y H:i:s')]);
    fputcsv($output, []); // Empty line
    
    // Add summary section
    fputcsv($output, ['SUMMARY']);
    fputcsv($output, ['Asset Class', 'Transactions', 'Buy Quantity', 'Buy Value (TZS)', 'Sell Quantity', 'Sell Value (TZS)', 'Net Quantity', 'Net Value (TZS)']);
    
    if (empty($filtered_trades)) {
        // Show zeros for each asset class when there are no trades
        $asset_classes = ['Equity', 'Bond', 'ETF'];
        foreach ($asset_classes as $class_name) {
            fputcsv($output, [
                $class_name,
                0,
                '0.00',
                '0.00',
                '0.00',
                '0.00',
                '0.00',
                '0.00'
            ]);
        }
    } else {
        // Separate trades by asset class
        $equity_trades = array_filter($filtered_trades, fn($t) => strtolower($t['asset_class']) === 'equity');
        $bond_trades = array_filter($filtered_trades, fn($t) => strtolower($t['asset_class']) === 'bond');
        $etf_trades = array_filter($filtered_trades, fn($t) => strtolower($t['asset_class']) === 'exchange traded funds');
        
        // Calculate totals for each asset class
        $asset_classes = [
            'Equity' => $equity_trades,
            'Bond' => $bond_trades,
            'ETF' => $etf_trades
        ];
        
        foreach ($asset_classes as $class_name => $trades) {
            $buy_quantity = array_sum(array_column(array_filter($trades, fn($t) => $t['trade_side'] === 'buy'), 'quantity'));
            $buy_value = array_sum(array_column(array_filter($trades, fn($t) => $t['trade_side'] === 'buy'), 'consideration'));
            $sell_quantity = array_sum(array_column(array_filter($trades, fn($t) => $t['trade_side'] === 'sell'), 'quantity'));
            $sell_value = array_sum(array_column(array_filter($trades, fn($t) => $t['trade_side'] === 'sell'), 'consideration'));
            
            fputcsv($output, [
                $class_name,
                count($trades),
                number_format($buy_quantity, 2),
                number_format($buy_value, 2),
                number_format($sell_quantity, 2),
                number_format($sell_value, 2),
                number_format($buy_quantity - $sell_quantity, 2),
                number_format($buy_value - $sell_value, 2)
            ]);
        }
    }
    
    fputcsv($output, []); // Empty line
    
    // Add transactions header
    $headers = ['Date', 'Security ID', 'Asset Name', 'Asset Class', 'Trade Side', 'Quantity', 'Price', 'Consideration (TZS)', 'Trade Reference', 'Status'];
    if (!$selected_cds) {
        array_splice($headers, 3, 0, 'CDS Account');
    }
    fputcsv($output, $headers);
    
    // Add transactions data (or show "No transactions found" if empty)
    if (empty($filtered_trades)) {
        fputcsv($output, ['No transactions found for the selected filters']);
    } else {
        foreach ($filtered_trades as $trade) {
            $row = [
                $trade['trade_date'],
                $trade['security_id'],
                $trade['asset_name'] ?? 'N/A',
                $trade['asset_class'],
                $trade['trade_side'],
                number_format($trade['quantity'], 2),
                number_format($trade['price'], 4),
                number_format($trade['consideration'], 2),
                $trade['trade_reference'],
                $trade['status']
            ];
            
            if (!$selected_cds) {
                array_splice($row, 3, 0, $trade['cds_account']);
            }
            
            fputcsv($output, $row);
        }
    }
    
    fclose($output);
    exit;
}

// Function to generate filtered transaction PDF - REMOVED uploaded_by restriction
function generateFilteredTransactionPDF($client, $date_from, $date_to, $selected_cds = null) {
    global $db, $company_name;
    
    // Build query based on filters
    $query = "
        SELECT t.*, 
               COALESCE(e.stock_name, b.security_id, etf.stock_name) AS asset_name,
               e.share_type,
               b.coupon_rate,
               b.maturity_date,
               c.client_name,
               c.cds_account
        FROM trades t
        LEFT JOIN equities e ON t.security_id = e.security_id AND t.asset_class = 'equity'
        LEFT JOIN bonds b ON t.security_id = b.security_id AND t.asset_class = 'bond'
        LEFT JOIN equities etf ON t.security_id = etf.security_id AND t.asset_class = 'Exchange Traded Funds'
        LEFT JOIN clients c ON t.client_cds_account = c.cds_account
        WHERE t.status = 'active'
    ";
    
    $params = [];
    
    if ($selected_cds) {
        $query .= " AND t.client_cds_account = ?";
        $params[] = $selected_cds;
    } else {
        // Get merged accounts
        $merged_clients = [];
        $stmt = $db->prepare("
            SELECT c.* 
            FROM clients c
            INNER JOIN merged_cds_accounts m ON (
                m.merged_cds_account = c.cds_account OR 
                m.primary_cds_account = c.cds_account
            )
            WHERE (
                m.primary_cds_account = ? OR 
                m.merged_cds_account = ?
            ) 
            AND c.id != ?
            AND m.status = 'active'
            AND c.is_active = 1
        ");
        $stmt->execute([$client['cds_account'], $client['cds_account'], $client['id']]);
        $merged_clients = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $cds_accounts = [$client['cds_account']];
        foreach ($merged_clients as $merged_client) {
            $cds_accounts[] = $merged_client['cds_account'];
        }
        
        $placeholders = str_repeat('?,', count($cds_accounts) - 1) . '?';
        $query .= " AND t.client_cds_account IN ($placeholders)";
        $params = array_merge($params, $cds_accounts);
    }
    
    // Apply date filters
    if (!empty($date_from) && !empty($date_to)) {
        $query .= " AND t.trade_date BETWEEN ? AND ?";
        $params[] = $date_from;
        $params[] = $date_to;
    } elseif (!empty($date_from)) {
        $query .= " AND t.trade_date >= ?";
        $params[] = $date_from;
    } elseif (!empty($date_to)) {
        $query .= " AND t.trade_date <= ?";
        $params[] = $date_to;
    }
    
    $query .= " ORDER BY t.trade_date, t.created_at";
    
    $stmt = $db->prepare($query);
    $stmt->execute($params);
    $filtered_trades = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Separate buy and sell trades
    $buy_trades = [];
    $sell_trades = [];
    
    foreach ($filtered_trades as $trade) {
        $trade['net_amount'] = ($trade['trade_side'] === 'sell') ? 
            $trade['consideration'] - calculateFees($db, $trade['asset_class'], $trade['consideration'], $trade['quantity'], $trade['price'])['total'] :
            $trade['consideration'] + calculateFees($db, $trade['asset_class'], $trade['consideration'], $trade['quantity'], $trade['price'])['total'];
        
        if ($trade['trade_side'] === 'buy') {
            $buy_trades[] = $trade;
        } else {
            $sell_trades[] = $trade;
        }
    }
    
    // Calculate totals
    $total_buy_quantity = array_sum(array_column($buy_trades, 'quantity'));
    $total_buy_value = array_sum(array_column($buy_trades, 'consideration'));
    $total_buy_fees = array_sum(array_map(function($trade) use ($db) {
        return calculateFees($db, $trade['asset_class'], $trade['consideration'], $trade['quantity'], $trade['price'])['total'];
    }, $buy_trades));
    $total_buy_net = array_sum(array_column($buy_trades, 'net_amount'));
    
    $total_sell_quantity = array_sum(array_column($sell_trades, 'quantity'));
    $total_sell_value = array_sum(array_column($sell_trades, 'consideration'));
    $total_sell_fees = array_sum(array_map(function($trade) use ($db) {
        return calculateFees($db, $trade['asset_class'], $trade['consideration'], $trade['quantity'], $trade['price'])['total'];
    }, $sell_trades));
    $total_sell_net = array_sum(array_column($sell_trades, 'net_amount'));
    
    // Calculate balance summary
    $balance_summary = empty($filtered_trades) ? [] : calculateBalanceSummary($filtered_trades);
    $total_balance_value = array_sum(array_column($balance_summary, 'balance_value'));
    $total_realized_pnl = array_sum(array_column($balance_summary, 'realized_pnl'));
    
    // Create PDF
    $pdf = new TCPDF('L', PDF_UNIT, 'A4', true, 'UTF-8', false);
    
    // Set document information
    $pdf->SetCreator(PDF_CREATOR);
    $pdf->SetAuthor($company_name);
    $pdf->SetTitle('Filtered Transaction History - ' . $client['client_name']);
    $pdf->SetSubject('Filtered Transaction History');
    
    // Set default header data
    $pdf->SetHeaderData('', 0, $company_name, 'Filtered Transaction History');
    
    // Set header and footer fonts
    $pdf->setHeaderFont(Array(PDF_FONT_NAME_MAIN, '', PDF_FONT_SIZE_MAIN));
    $pdf->setFooterFont(Array(PDF_FONT_NAME_DATA, '', PDF_FONT_SIZE_DATA));
    
    // Set default monospaced font
    $pdf->SetDefaultMonospacedFont(PDF_FONT_MONOSPACED);
    
    // Set margins
    $pdf->SetMargins(15, 25, 15);
    $pdf->SetHeaderMargin(10);
    $pdf->SetFooterMargin(10);
    
    // Set auto page breaks
    $pdf->SetAutoPageBreak(TRUE, 15);
    
    // Add a page
    $pdf->AddPage();
    
    // Title
    $pdf->SetFont('helvetica', 'B', 16);
    $pdf->Cell(0, 10, 'FILTERED TRANSACTION HISTORY', 0, 1, 'C');
    $pdf->Ln(5);
    
    // Client information
    $pdf->SetFont('helvetica', '', 10);
    $pdf->Cell(0, 6, 'Client: ' . strtoupper($client['client_name']), 0, 1);
    $pdf->Cell(0, 6, 'CDS Account: ' . ($selected_cds ? $selected_cds : 'Combined Portfolio'), 0, 1);
    $pdf->Cell(0, 6, 'Date Range: ' . ($date_from ? $date_from : 'Start') . ' to ' . ($date_to ? $date_to : 'End'), 0, 1);
    $pdf->Cell(0, 6, 'Generated: ' . date('d/m/Y H:i:s'), 0, 1);
    $pdf->Ln(10);
    
    // Summary section
    $pdf->SetFont('helvetica', 'B', 12);
    $pdf->Cell(0, 8, 'TRANSACTION SUMMARY', 0, 1);
    $pdf->SetFont('helvetica', '', 10);
    
    $summary_html = '<table border="1" cellpadding="4" cellspacing="0">
        <thead>
            <tr style="background-color:#f2f2f2;">
                <th width="33%" align="center"><b>BUY TRANSACTIONS</b></th>
                <th width="34%" align="center"><b>SELL TRANSACTIONS</b></th>
                <th width="33%" align="center"><b>BALANCE SUMMARY</b></th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td align="center">' . number_format(count($buy_trades)) . ' Trades</td>
                <td align="center">' . number_format(count($sell_trades)) . ' Trades</td>
                <td align="center">' . number_format(count($balance_summary)) . ' Securities</td>
            </tr>
            <tr>
                <td align="center">Quantity: ' . number_format($total_buy_quantity, 2) . '</td>
                <td align="center">Quantity: ' . number_format($total_sell_quantity, 2) . '</td>
                <td align="center">Balance Qty: ' . number_format($total_buy_quantity - $total_sell_quantity, 2) . '</td>
            </tr>
            <tr>
                <td align="center">Value: TZS ' . number_format($total_buy_value, 2) . '</td>
                <td align="center">Value: TZS ' . number_format($total_sell_value, 2) . '</td>
                <td align="center">Balance Value: TZS ' . number_format($total_balance_value, 2) . '</td>
            </tr>
            <tr>
                <td align="center">Fees: TZS ' . number_format($total_buy_fees, 2) . '</td>
                <td align="center">Fees: TZS ' . number_format($total_sell_fees, 2) . '</td>
                <td align="center">Realized P&L: TZS ' . number_format($total_realized_pnl, 2) . '</td>
            </tr>
            <tr>
                <td align="center">Net Amount: TZS ' . number_format($total_buy_net, 2) . '</td>
                <td align="center">Net Amount: TZS ' . number_format($total_sell_net, 2) . '</td>
                <td align="center">Total Trades: ' . number_format(count($filtered_trades)) . '</td>
            </tr>
        </tbody>
    </table>';
    
    $pdf->writeHTML($summary_html, true, false, true, false, '');
    $pdf->Ln(10);
    
    // Detailed transactions table
    if (!empty($filtered_trades)) {
        $pdf->SetFont('helvetica', '', 12);
        $pdf->Cell(0, 8, 'DETAILED TRANSACTIONS', 0, 1);
        $pdf->SetFont('helvetica', '', 9);
        
        $transactions_html = '<table border="1" cellpadding="3" cellspacing="0">
            <thead>
                <tr style="background-color:#f2f2f2;">
                    <th width="10%" align="center"><b>Date</b></th>';
        
        if (!$selected_cds) {
            $transactions_html .= '<th width="12%" align="center"><b>CDS Account</b></th>';
        }
        
        $transactions_html .= '<th width="12%" align="center"><b>Security</b></th>
                    <th width="12%" align="center"><b>Asset Class</b></th>
                    <th width="8%" align="center"><b>Side</b></th>
                    <th width="10%" align="center"><b>Quantity</b></th>
                    <th width="10%" align="center"><b>Price</b></th>
                    <th width="15%" align="center"><b>Value (TZS)</b></th>
                    <th width="13%" align="center"><b>Trade Ref</b></th>
                </tr>
            </thead>
            <tbody>';
        
        foreach ($filtered_trades as $trade) {
            $transactions_html .= '<tr>
                <td align="center">' . date('d/m/Y', strtotime($trade['trade_date'])) . '</td>';
            
            if (!$selected_cds) {
                $transactions_html .= '<td align="center">' . $trade['cds_account'] . '</td>';
            }
            
            $transactions_html .= '
                <td align="center">' . $trade['security_id'] . '</td>
                <td align="center">' . ucfirst($trade['asset_class']) . '</td>
                <td align="center">' . ucfirst($trade['trade_side']) . '</td>
                <td align="right">' . number_format($trade['quantity'], 2) . '</td>
                <td align="right">' . ($trade['asset_class'] === 'bond' ? $trade['price'] . '%' : 'TZS ' . number_format($trade['price'], 2)) . '</td>
                <td align="right">TZS ' . number_format($trade['consideration'], 2) . '</td>
                <td align="center">' . $trade['trade_reference'] . '</td>
            </tr>';
        }
        
        $transactions_html .= '</tbody></table>';
        
        $pdf->writeHTML($transactions_html, true, false, true, false, '');
    } else {
        $pdf->SetFont('helvetica', 'B', 12);
        $pdf->Cell(0, 10, 'No transactions found for the selected filters.', 0, 1, 'C');
    }
    
    // Balance summary table
    if (!empty($balance_summary)) {
        $pdf->AddPage();
        $pdf->SetFont('helvetica', 'B', 12);
        $pdf->Cell(0, 8, 'PORTFOLIO BALANCE BY SECURITY', 0, 1);
        $pdf->SetFont('helvetica', '', 9);
        
        $balance_html = '<table border="1" cellpadding="3" cellspacing="0">
            <thead>
                <tr style="background-color:#f2f2f2;">
                    <th width="20%" align="center"><b>Security</b></th>
                    <th width="15%" align="center"><b>Asset Class</b></th>
                    <th width="13%" align="center"><b>Buy Qty</b></th>
                    <th width="13%" align="center"><b>Buy Value</b></th>
                    <th width="13%" align="center"><b>Sell Qty</b></th>
                    <th width="13%" align="center"><b>Sell Value</b></th>
                    <th width="13%" align="center"><b>Balance</b></th>
                </tr>
            </thead>
            <tbody>';
        
        foreach ($balance_summary as $item) {
            $balance_html .= '
                <tr>
                    <td align="center">' . $item['security_id'] . '</td>
                    <td align="center">' . ucfirst($item['asset_class']) . '</td>
                    <td align="right">' . number_format($item['buy_quantity'], 2) . '</td>
                    <td align="right">TZS ' . number_format($item['buy_value'], 2) . '</td>
                    <td align="right">' . number_format($item['sell_quantity'], 2) . '</td>
                    <td align="right">TZS ' . number_format($item['sell_value'], 2) . '</td>
                    <td align="right">' . number_format($item['balance_quantity'], 2) . '</td>
                </tr>';
        }
        
        // Add totals
        $balance_html .= '
            <tr style="background-color:#f2f2f2; font-weight:bold;">
                <td colspan="2" align="center">TOTALS:</td>
                <td align="right">' . number_format(array_sum(array_column($balance_summary, 'buy_quantity')), 2) . '</td>
                <td align="right">TZS ' . number_format(array_sum(array_column($balance_summary, 'buy_value')), 2) . '</td>
                <td align="right">' . number_format(array_sum(array_column($balance_summary, 'sell_quantity')), 2) . '</td>
                <td align="right">TZS ' . number_format(array_sum(array_column($balance_summary, 'sell_value')), 2) . '</td>
                <td align="right">' . number_format(array_sum(array_column($balance_summary, 'balance_quantity')), 2) . '</td>
            </tr>
        </tbody></table>';
        
        $pdf->writeHTML($balance_html, true, false, true, false, '');
    }
    
    // Footer note
    $pdf->SetY(-25);
    $pdf->SetFont('helvetica', 'I', 8);
    $pdf->Cell(0, 10, 'This filtered report was generated by ' . $company_name . ' on ' . date('d/m/Y H:i:s'), 0, 0, 'C');
    
    // Output PDF
    $filename = 'filtered_transactions_' . ($selected_cds ? $selected_cds : $client['cds_account']) . '_' . date('Ymd_His') . '.pdf';
    $pdf->Output($filename, 'I');
}

// Function to generate transaction PDF - REMOVED uploaded_by restriction
function generateClientTransactionPDF($client) {
    global $db, $company_name;
    
    // Get all trades for this client - REMOVED uploaded_by restriction
    $stmt = $db->prepare("
        SELECT t.*, 
               COALESCE(e.stock_name, b.security_id, etf.stock_name) AS asset_name,
               e.share_type,
               b.coupon_rate,
               b.maturity_date
        FROM trades t
        LEFT JOIN equities e ON t.security_id = e.security_id AND t.asset_class = 'equity'
        LEFT JOIN bonds b ON t.security_id = b.security_id AND t.asset_class = 'bond'
        LEFT JOIN equities etf ON t.security_id = etf.security_id AND t.asset_class = 'Exchange Traded Funds'
        WHERE t.client_cds_account = ? 
        AND t.status = 'active'
        ORDER BY t.trade_date, t.created_at
    ");
    $stmt->execute([$client['cds_account']]);
    $all_trades = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Separate buy and sell trades
    $buy_trades = [];
    $sell_trades = [];
    
    foreach ($all_trades as $trade) {
        $trade['net_amount'] = ($trade['trade_side'] === 'sell') ? 
            $trade['consideration'] - calculateFees($db, $trade['asset_class'], $trade['consideration'], $trade['quantity'], $trade['price'])['total'] :
            $trade['consideration'] + calculateFees($db, $trade['asset_class'], $trade['consideration'], $trade['quantity'], $trade['price'])['total'];
        
        if ($trade['trade_side'] === 'buy') {
            $buy_trades[] = $trade;
        } else {
            $sell_trades[] = $trade;
        }
    }
    
    // Calculate totals
    $total_buy_quantity = array_sum(array_column($buy_trades, 'quantity'));
    $total_buy_value = array_sum(array_column($buy_trades, 'consideration'));
    $total_buy_fees = array_sum(array_map(function($trade) use ($db) {
        return calculateFees($db, $trade['asset_class'], $trade['consideration'], $trade['quantity'], $trade['price'])['total'];
    }, $buy_trades));
    $total_buy_net = array_sum(array_column($buy_trades, 'net_amount'));
    
    $total_sell_quantity = array_sum(array_column($sell_trades, 'quantity'));
    $total_sell_value = array_sum(array_column($sell_trades, 'consideration'));
    $total_sell_fees = array_sum(array_map(function($trade) use ($db) {
        return calculateFees($db, $trade['asset_class'], $trade['consideration'], $trade['quantity'], $trade['price'])['total'];
    }, $sell_trades));
    $total_sell_net = array_sum(array_column($sell_trades, 'net_amount'));
    
    // Calculate balance summary
    $balance_summary = empty($all_trades) ? [] : calculateBalanceSummary($all_trades);
    $total_balance_value = array_sum(array_column($balance_summary, 'balance_value'));
    $total_realized_pnl = array_sum(array_column($balance_summary, 'realized_pnl'));
    
    // Create PDF
    $pdf = new TCPDF('L', PDF_UNIT, 'A4', true, 'UTF-8', false);
    
    // Set document information
    $pdf->SetCreator(PDF_CREATOR);
    $pdf->SetAuthor($company_name);
    $pdf->SetTitle('Client Transaction History - ' . $client['client_name']);
    $pdf->SetSubject('Transaction History');
    
    // Set default header data
    $pdf->SetHeaderData('', 0, $company_name, 'Client Transaction History');
    
    // Set header and footer fonts
    $pdf->setHeaderFont(Array(PDF_FONT_NAME_MAIN, '', PDF_FONT_SIZE_MAIN));
    $pdf->setFooterFont(Array(PDF_FONT_NAME_DATA, '', PDF_FONT_SIZE_DATA));
    
    // Set default monospaced font
    $pdf->SetDefaultMonospacedFont(PDF_FONT_MONOSPACED);
    
    // Set margins
    $pdf->SetMargins(15, 25, 15);
    $pdf->SetHeaderMargin(10);
    $pdf->SetFooterMargin(10);
    
    // Set auto page breaks
    $pdf->SetAutoPageBreak(TRUE, 15);
    
    // Add a page
    $pdf->AddPage();
    
    // Title
    $pdf->SetFont('helvetica', 'B', 16);
    $pdf->Cell(0, 10, 'CLIENT TRANSACTION HISTORY', 0, 1, 'C');
    $pdf->Ln(5);
    
    // Client information
    $pdf->SetFont('helvetica', '', 10);
    $pdf->Cell(0, 6, 'Client: ' . strtoupper($client['client_name']), 0, 1);
    $pdf->Cell(0, 6, 'CDS Account: ' . $client['cds_account'], 0, 1);
    $pdf->Cell(0, 6, 'Generated: ' . date('d/m/Y H:i:s'), 0, 1);
    $pdf->Ln(10);
    
    // Summary section
    $pdf->SetFont('helvetica', 'B', 12);
    $pdf->Cell(0, 8, 'TRANSACTION SUMMARY', 0, 1);
    $pdf->SetFont('helvetica', '', 10);
    
    $summary_html = '<table border="1" cellpadding="4" cellspacing="0">
        <thead>
            <tr style="background-color:#f2f2f2;">
                <th width="33%" align="center"><b>BUY TRANSACTIONS</b></th>
                <th width="34%" align="center"><b>SELL TRANSACTIONS</b></th>
                <th width="33%" align="center"><b>BALANCE SUMMARY</b></th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td align="center">' . number_format(count($buy_trades)) . ' Trades</td>
                <td align="center">' . number_format(count($sell_trades)) . ' Trades</td>
                <td align="center">' . number_format(count($balance_summary)) . ' Securities</td>
            </tr>
            <tr>
                <td align="center">Quantity: ' . number_format($total_buy_quantity, 2) . '</td>
                <td align="center">Quantity: ' . number_format($total_sell_quantity, 2) . '</td>
                <td align="center">Balance Qty: ' . number_format($total_buy_quantity - $total_sell_quantity, 2) . '</td>
            </tr>
            <tr>
                <td align="center">Value: TZS ' . number_format($total_buy_value, 2) . '</td>
                <td align="center">Value: TZS ' . number_format($total_sell_value, 2) . '</td>
                <td align="center">Balance Value: TZS ' . number_format($total_balance_value, 2) . '</td>
            </tr>
            <tr>
                <td align="center">Fees: TZS ' . number_format($total_buy_fees, 2) . '</td>
                <td align="center">Fees: TZS ' . number_format($total_sell_fees, 2) . '</td>
                <td align="center">Realized P&L: TZS ' . number_format($total_realized_pnl, 2) . '</td>
            </tr>
            <tr>
                <td align="center">Net Amount: TZS ' . number_format($total_buy_net, 2) . '</td>
                <td align="center">Net Amount: TZS ' . number_format($total_sell_net, 2) . '</td>
                <td align="center">Total Trades: ' . number_format(count($all_trades)) . '</td>
            </tr>
        </tbody>
    </table>';
    
    $pdf->writeHTML($summary_html, true, false, true, false, '');
    $pdf->Ln(10);
    
    // Detailed transactions table (only show if there are trades)
    if (!empty($all_trades)) {
        $pdf->SetFont('helvetica', 'B', 12);
        $pdf->Cell(0, 8, 'DETAILED TRANSACTIONS', 0, 1);
        
        // Calculate max rows for side-by-side display
        $max_rows = max(count($buy_trades), count($sell_trades));
        
        // Create side-by-side tables
        $transactions_html = '<table border="1" cellpadding="4" cellspacing="0">
            <thead>
                <tr style="background-color:#f2f2f2;">
                    <th colspan="4" width="50%" align="center"><b>BUY TRANSACTIONS</b></th>
                    <th colspan="4" width="50%" align="center"><b>SELL TRANSACTIONS</b></th>
                </tr>
                <tr style="background-color:#e6e6e6;">
                    <th width="12.5%" align="center"><b>Date</b></th>
                    <th width="12.5%" align="center"><b>Security</b></th>
                    <th width="12.5%" align="center"><b>Quantity</b></th>
                    <th width="12.5%" align="center"><b>Value</b></th>
                    <th width="12.5%" align="center"><b>Date</b></th>
                    <th width="12.5%" align="center"><b>Security</b></th>
                    <th width="12.5%" align="center"><b>Quantity</b></th>
                    <th width="12.5%" align="center"><b>Value</b></th>
                </tr>
            </thead>
            <tbody>';
        
        for ($i = 0; $i < $max_rows; $i++) {
            $transactions_html .= '<tr>';
            
            // Buy side
            if (isset($buy_trades[$i])) {
                $trade = $buy_trades[$i];
                $transactions_html .= '
                    <td align="center">' . date('d/m/Y', strtotime($trade['trade_date'])) . '</td>
                    <td align="center">' . $trade['security_id'] . '</td>
                    <td align="right">' . number_format($trade['quantity'], 2) . '</td>
                    <td align="right">TZS ' . number_format($trade['consideration'], 2) . '</td>';
            } else {
                $transactions_html .= '<td align="center">-</td><td align="center">-</td><td align="center">-</td><td align="center">-</td>';
            }
            
            // Sell side
            if (isset($sell_trades[$i])) {
                $trade = $sell_trades[$i];
                $transactions_html .= '
                    <td align="center">' . date('d/m/Y', strtotime($trade['trade_date'])) . '</td>
                    <td align="center">' . $trade['security_id'] . '</td>
                    <td align="right">' . number_format($trade['quantity'], 2) . '</td>
                    <td align="right">TZS ' . number_format($trade['consideration'], 2) . '</td>';
            } else {
                $transactions_html .= '<td align="center">-</td><td align="center">-</td><td align="center">-</td><td align="center">-</td>';
            }
            
            $transactions_html .= '</tr>';
        }
        
        // Add totals row
        $transactions_html .= '
            <tr style="background-color:#f2f2f2; font-weight:bold;">
                <td colspan="2" align="center">BUY TOTALS:</td>
                <td align="right">' . number_format($total_buy_quantity, 2) . '</td>
                <td align="right">TZS ' . number_format($total_buy_value, 2) . '</td>
                <td colspan="2" align="center">SELL TOTALS:</td>
                <td align="right">' . number_format($total_sell_quantity, 2) . '</td>
                <td align="right">TZS ' . number_format($total_sell_value, 2) . '</td>
            </tr>
            <tr style="background-color:#e6f7ff; font-weight:bold;">
                <td colspan="2" align="center">BALANCE:</td>
                <td align="right">' . number_format($total_buy_quantity - $total_sell_quantity, 2) . '</td>
                <td align="right">TZS ' . number_format($total_buy_value - $total_sell_value, 2) . '</td>
                <td colspan="2" align="center">NET TOTAL:</td>
                <td align="center">-</td>
                <td align="right">TZS ' . number_format($total_buy_net + $total_sell_net, 2) . '</td>
            </tr>
        </tbody></table>';
        
        $pdf->writeHTML($transactions_html, true, false, true, false, '');
        $pdf->Ln(10);
    } else {
        $pdf->SetFont('helvetica', 'B', 12);
        $pdf->Cell(0, 10, 'No transactions found for this client.', 0, 1, 'C');
    }
    
    // Balance summary table (only show if there are trades)
    if (!empty($balance_summary)) {
        $pdf->SetFont('helvetica', 'B', 12);
        $pdf->Cell(0, 8, 'PORTFOLIO BALANCE BY SECURITY', 0, 1);
        
        $balance_html = '<table border="1" cellpadding="4" cellspacing="0">
            <thead>
                <tr style="background-color:#f2f2f2;">
                    <th width="15%" align="center"><b>Security</b></th>
                    <th width="15%" align="center"><b>Asset Class</b></th>
                    <th width="14%" align="center"><b>Buy Qty</b></th>
                    <th width="14%" align="center"><b>Buy Value</b></th>
                    <th width="14%" align="center"><b>Sell Qty</b></th>
                    <th width="14%" align="center"><b>Sell Value</b></th>
                    <th width="14%" align="center"><b>Balance</b></th>
                </tr>
            </thead>
            <tbody>';
        
        foreach ($balance_summary as $item) {
            $balance_html .= '
                <tr>
                    <td align="center">' . $item['security_id'] . '</td>
                    <td align="center">' . ucfirst($item['asset_class']) . '</td>
                    <td align="right">' . number_format($item['buy_quantity'], 2) . '</td>
                    <td align="right">TZS ' . number_format($item['buy_value'], 2) . '</td>
                    <td align="right">' . number_format($item['sell_quantity'], 2) . '</td>
                    <td align="right">TZS ' . number_format($item['sell_value'], 2) . '</td>
                    <td align="right">' . number_format($item['balance_quantity'], 2) . '</td>
                </tr>';
        }
        
        // Add totals
        $balance_html .= '
            <tr style="background-color:#f2f2f2; font-weight:bold;">
                <td colspan="2" align="center">TOTALS:</td>
                <td align="right">' . number_format(array_sum(array_column($balance_summary, 'buy_quantity')), 2) . '</td>
                <td align="right">TZS ' . number_format(array_sum(array_column($balance_summary, 'buy_value')), 2) . '</td>
                <td align="right">' . number_format(array_sum(array_column($balance_summary, 'sell_quantity')), 2) . '</td>
                <td align="right">TZS ' . number_format(array_sum(array_column($balance_summary, 'sell_value')), 2) . '</td>
                <td align="right">' . number_format(array_sum(array_column($balance_summary, 'balance_quantity')), 2) . '</td>
            </tr>
        </tbody></table>';
        
        $pdf->writeHTML($balance_html, true, false, true, false, '');
    }
    
    // Footer note
    $pdf->SetY(-25);
    $pdf->SetFont('helvetica', 'I', 8);
    $pdf->Cell(0, 10, 'This report was generated by ' . $company_name . ' on ' . date('d/m/Y H:i:s'), 0, 0, 'C');
    
    // Output PDF
    $filename = 'transaction_history_' . $client['cds_account'] . '_' . date('Ymd_His') . '.pdf';
    $pdf->Output($filename, 'I');
}

// Function to calculate fees (same as in trades.php)
function calculateFees($db, $asset_class, $consideration, $quantity, $price) {
    $fees = [];
    
    // Simplified fee calculation - you should use the same logic as in trades.php
    $is_bond = ($asset_class === 'bond' || $asset_class === 'treasury_bond');
    
    if ($is_bond) {
        // Bond fees
        $brokerage = $consideration * 0.001; // 0.1% for bonds
        $vat = $brokerage * 0.18; // 18% VAT
        $fees['total'] = $brokerage + $vat;
    } else {
        // Equity/ETF fees
        $brokerage = $consideration * 0.0175; // 1.75% for equities
        $vat = $brokerage * 0.18; // 18% VAT
        $cmsa = $consideration * 0.0014; // 0.14% CMSA
        $dse = $consideration * 0.001652; // 0.1652% DSE
        $fidelity = $consideration * 0.0002; // 0.02% Fidelity
        $csd = $consideration * 0.000708; // 0.0708% CDS
        
        $fees['total'] = $brokerage + $vat + $cmsa + $dse + $fidelity + $csd;
    }
    
    return $fees;
}

include '../includes/header.php';
?>

<div class="page-header">
    <div class="container-fluid">
        <div class="row align-items-center">
            <div class="col-md-8">
                <div class="d-flex align-items-center">
                    <div class="me-3">
                        <div class="d-inline-flex align-items-center justify-content-center rounded-circle shadow-sm" 
                             style="width: 60px; height: 60px; background: linear-gradient(135deg, var(--primary-color) 0%, #3b82f6 100%);">
                            <i class="bi bi-person" style="font-size: 1.5rem;"></i>
                        </div>
                    </div>
                    <div>
                        <h1 class="page-title mb-1">Client Profile</h1>
                        <p class="page-subtitle">View and manage detailed client information - <?php echo htmlspecialchars($company_name); ?></p>
                    </div>
                </div>
            </div>
            <div class="col-md-4 text-end">
                <a href="trades.php" class="btn btn-outline-secondary me-2">
                    <i class="bi bi-arrow-left me-1"></i>
                    Back to Trades
                </a>
                <?php if ($client): ?>
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#exportModal">
                    <i class="bi bi-download me-1"></i>
                    Export Data
                </button>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- CDS Selector and Total Holdings Header -->
        <?php if ($client): ?>
        <div class="row mt-4">
            <div class="col-12">
                <div class="card border-0 shadow-sm">
                    <div class="card-body py-3">
                        <!-- CDS Selector -->
                        <div class="mb-4">
                            <div class="d-flex justify-content-between align-items-center">
                                <h6 class="mb-0 fw-semibold">CDS Account Selector</h6>
                                <div>
                                    <button class="btn btn-outline-secondary btn-sm me-2" data-bs-toggle="modal" data-bs-target="#dateFilterModal">
                                        <i class="bi bi-calendar-range me-1"></i>Date Filter
                                    </button>
                                    <div class="form-check form-switch d-inline-block">
                                        <input class="form-check-input" type="checkbox" id="showIndividualCDS" <?php echo $show_individual_cds ? 'checked' : ''; ?> onchange="toggleCDSView()">
                                        <label class="form-check-label" for="showIndividualCDS">
                                            Show Individual CDS
                                        </label>
                                    </div>
                                </div>
                            </div>
                            <div class="row mt-3">
                                <div class="col-md-6">
                                    <form method="GET" action="" class="d-flex">
                                        <input type="hidden" name="id" value="<?php echo $client['id']; ?>">
                                        <?php if ($date_from): ?><input type="hidden" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>"><?php endif; ?>
                                        <?php if ($date_to): ?><input type="hidden" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>"><?php endif; ?>
                                        <select class="form-select me-2" name="view_cds" id="cdsSelector" onchange="this.form.submit()">
                                            <option value="">View Combined Portfolio (All Merged Accounts)</option>
                                            <option value="<?php echo $client['cds_account']; ?>" <?php echo ($selected_cds == $client['cds_account']) ? 'selected' : ''; ?>>
                                                <?php echo $client['cds_account']; ?> (Primary)
                                            </option>
                                            <?php foreach ($merged_clients as $merged_client): ?>
                                            <option value="<?php echo $merged_client['cds_account']; ?>" <?php echo ($selected_cds == $merged_client['cds_account']) ? 'selected' : ''; ?>>
                                                <?php echo $merged_client['cds_account']; ?>
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </form>
                                    <small class="text-muted">Select a specific CDS account to view its individual investments</small>
                                </div>
                                <div class="col-md-6">
                                    <?php if ($date_from || $date_to): ?>
                                    <div class="alert alert-info py-2">
                                        <i class="bi bi-funnel me-1"></i>
                                        Date Filter Active: 
                                        <?php if ($date_from && $date_to): ?>
                                            <?php echo htmlspecialchars($date_from); ?> to <?php echo htmlspecialchars($date_to); ?>
                                        <?php elseif ($date_from): ?>
                                            From <?php echo htmlspecialchars($date_from); ?>
                                        <?php elseif ($date_to): ?>
                                            Until <?php echo htmlspecialchars($date_to); ?>
                                        <?php endif; ?>
                                        <a href="?id=<?php echo $client['id']; ?><?php echo $selected_cds ? '&view_cds=' . urlencode($selected_cds) : ''; ?>" class="float-end">
                                            <i class="bi bi-x-circle"></i> Clear
                                        </a>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Total Holdings -->
                        <h6 class="mb-3 fw-semibold"><?php echo $show_individual_cds ? 'Individual CDS Holdings' : 'Combined Portfolio Holdings'; ?></h6>
                        <div class="row">
                            <?php
                            $current_holdings = $show_individual_cds ? $individual_holdings_by_asset_class : $holdings_by_asset_class;
                            $current_trades = $show_individual_cds ? $individual_cds_trades : $filtered_trades;
                            ?>
                            <div class="col-md-4 mb-2">
                                <div class="d-flex align-items-center p-3 border rounded" style="background-color: rgba(13, 110, 253, 0.1);">
                                    <div class="me-3">
                                        <div class="d-inline-flex align-items-center justify-content-center rounded-circle" 
                                             style="width: 40px; height: 40px; background-color: rgba(13, 110, 253, 0.2);">
                                            <i class="bi bi-graph-up text-primary"></i>
                                        </div>
                                    </div>
                                    <div>
                                        <div class="fw-bold text-primary fs-5">
                                            <?php echo number_format($current_holdings['equity']['quantity'], 2); ?>
                                        </div>
                                        <div class="text-muted small">Equity Holdings</div>
                                        <div class="fw-semibold text-dark">
                                            TZS <?php echo number_format(abs($current_holdings['equity']['value']), 2); ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4 mb-2">
                                <div class="d-flex align-items-center p-3 border rounded" style="background-color: rgba(255, 193, 7, 0.1);">
                                    <div class="me-3">
                                        <div class="d-inline-flex align-items-center justify-content-center rounded-circle" 
                                             style="width: 40px; height: 40px; background-color: rgba(255, 193, 7, 0.2);">
                                            <i class="bi bi-cash-coin text-warning"></i>
                                        </div>
                                    </div>
                                    <div>
                                        <div class="fw-bold text-warning fs-5">
                                            <?php echo number_format($current_holdings['bond']['quantity'], 2); ?>
                                        </div>
                                        <div class="text-muted small">Bond Holdings</div>
                                        <div class="fw-semibold text-dark">
                                            TZS <?php echo number_format(abs($current_holdings['bond']['value']), 2); ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4 mb-2">
                                <div class="d-flex align-items-center p-3 border rounded" style="background-color: rgba(111, 66, 193, 0.1);">
                                    <div class="me-3">
                                        <div class="d-inline-flex align-items-center justify-content-center rounded-circle" 
                                             style="width: 40px; height: 40px; background-color: rgba(111, 66, 193, 0.2);">
                                            <i class="bi bi-bar-chart-line text-purple"></i>
                                        </div>
                                    </div>
                                    <div>
                                        <div class="fw-bold text-purple fs-5">
                                            <?php echo number_format($current_holdings['Exchange Traded Funds']['quantity'], 2); ?>
                                        </div>
                                        <div class="text-muted small">ETF Holdings</div>
                                        <div class="fw-semibold text-dark">
                                            TZS <?php echo number_format(abs($current_holdings['Exchange Traded Funds']['value']), 2); ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="mt-3 text-muted small">
                            <i class="bi bi-info-circle me-1"></i>
                            Showing <?php echo count($current_trades); ?> trades 
                            <?php if ($date_from || $date_to): ?>
                                with date filter applied
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<div class="container-fluid">
    <?php if ($success_message): ?>
        <div class="alert alert-success border-0 shadow-sm mb-4">
            <div class="d-flex align-items-center">
                <i class="bi bi-check-circle-fill me-2 text-success"></i>
                <span><?php echo htmlspecialchars($success_message); ?></span>
                <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert"></button>
            </div>
        </div>
    <?php endif; ?>
    <?php if ($error_message): ?>
        <div class="alert alert-danger border-0 shadow-sm mb-4">
            <div class="d-flex align-items-center">
                <i class="bi bi-exclamation-triangle-fill me-2 text-danger"></i>
                <span><?php echo htmlspecialchars($error_message); ?></span>
                <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert"></button>
            </div>
        </div>
    <?php endif; ?>
    
    <?php if ($client): ?>
        <!-- Client Details Card -->
        <div class="card dashboard-card mb-4">
            <div class="card-header bg-transparent border-0 d-flex justify-content-between align-items-center">
                <h6 class="mb-0 fw-semibold">Client Details</h6>
                <div class="btn-group">
                    <button class="btn btn-outline-primary btn-sm" data-bs-toggle="modal" data-bs-target="#editClientModal">
                        <i class="bi bi-pencil me-1"></i>Edit
                    </button>
                    <button class="btn btn-outline-success btn-sm" data-bs-toggle="modal" data-bs-target="#mergeCDSModal">
                        <i class="bi bi-link-45deg me-1"></i>Merge CDS
                    </button>
                    <button class="btn btn-outline-dark btn-sm" data-bs-toggle="modal" data-bs-target="#exportModal">
                        <i class="bi bi-download me-1"></i>Export
                    </button>
                </div>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <div class="fw-semibold">Client Name:</div>
                        <p class="mb-0"><?php echo htmlspecialchars($client['client_name']); ?></p>
                    </div>
                    <div class="col-md-6 mb-3">
                        <div class="fw-semibold">CDS Account:</div>
                        <p class="mb-0"><?php echo htmlspecialchars($client['cds_account']); ?></p>
                    </div>
                    <div class="col-md-6 mb-3">
                        <div class="fw-semibold">Client Type:</div>
                        <p class="mb-0"><?php echo ucfirst(htmlspecialchars($client['client_type'])); ?></p>
                    </div>
                    <div class="col-md-6 mb-3">
                        <div class="fw-semibold">Phone:</div>
                        <p class="mb-0"><?php echo htmlspecialchars($client['phone'] ?? 'N/A'); ?></p>
                    </div>
                    <div class="col-md-6 mb-3">
                        <div class="fw-semibold">Email:</div>
                        <p class="mb-0"><?php echo htmlspecialchars($client['email'] ?? 'N/A'); ?></p>
                    </div>
                    <div class="col-md-6 mb-3">
                        <div class="fw-semibold">Total Investments:</div>
                        <p class="mb-0"><?php echo number_format(count($all_trades)); ?> trades</p>
                    </div>
                    <div class="col-md-6 mb-3">
                        <div class="fw-semibold">Account Created:</div>
                        <p class="mb-0"><?php echo date('d/m/Y', strtotime($client['created_at'])); ?></p>
                    </div>
                    <div class="col-md-6 mb-3">
                        <div class="fw-semibold">Last Updated:</div>
                        <p class="mb-0"><?php echo date('d/m/Y H:i', strtotime($client['updated_at'])); ?></p>
                    </div>
                </div>
                
                <!-- Merged CDS Accounts -->
                <?php if (!empty($merged_clients)): ?>
                <div class="mt-4 pt-3 border-top">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h6 class="fw-semibold mb-0">Merged CDS Accounts</h6>
                        <small class="text-muted">Click on any account to view its transactions</small>
                    </div>
                    <div class="row">
                        <?php foreach ($merged_clients as $merged_client): ?>
                        <div class="col-md-6 mb-2">
                            <div class="p-2 border rounded d-flex justify-content-between align-items-center">
                                <div>
                                    <div class="fw-semibold"><?php echo htmlspecialchars($merged_client['client_name']); ?></div>
                                    <small class="text-muted">CDS: <?php echo htmlspecialchars($merged_client['cds_account']); ?></small>
                                </div>
                                <div class="btn-group">
                                    <a href="?id=<?php echo $client['id']; ?>&view_cds=<?php echo urlencode($merged_client['cds_account']); ?>" 
                                       class="btn btn-sm btn-outline-info">
                                        <i class="bi bi-eye"></i>
                                    </a>
                                    <button type="button" class="btn btn-sm btn-outline-danger" 
                                            onclick="confirmUnmerge(<?php echo $merged_client['merge_id']; ?>, '<?php echo htmlspecialchars($merged_client['client_name']); ?>')">
                                        <i class="bi bi-unlink"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <form id="unmergeForm" method="POST" style="display: none;">
                        <input type="hidden" name="unmerge_cds" value="1">
                        <input type="hidden" name="merge_id" id="unmergeMergeId">
                    </form>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Investments Card -->
        <div class="card dashboard-card mb-4">
            <div class="card-header bg-transparent border-0 d-flex justify-content-between align-items-center">
                <h6 class="mb-0 fw-semibold">
                    <?php echo $show_individual_cds ? 'Individual CDS Investments' : 'All Investments (Combined Portfolio)'; ?>
                    <?php if ($show_individual_cds && $selected_cds): ?>
                        <small class="text-muted"> - CDS: <?php echo $selected_cds; ?></small>
                    <?php endif; ?>
                </h6>
                <div>
                    <?php
                    $current_trades = $show_individual_cds ? $individual_cds_trades : $filtered_trades;
                    $buy_count = count(array_filter($current_trades, fn($t) => $t['trade_side'] === 'buy'));
                    $sell_count = count(array_filter($current_trades, fn($t) => $t['trade_side'] === 'sell'));
                    ?>
                    <span class="badge bg-success me-2">Buy: <?php echo $buy_count; ?></span>
                    <span class="badge bg-danger">Sell: <?php echo $sell_count; ?></span>
                </div>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0" id="tradesTable">
                        <thead style="background: linear-gradient(135deg, var(--background-secondary) 0%, var(--background-muted) 100%);">
                            <tr>
                                <th class="border-0 fw-semibold text-dark py-3 sortable" data-sort="date">Date <span class="sort-icon">↕</span></th>
                                <?php if (!$show_individual_cds): ?>
                                <th class="border-0 fw-semibold text-dark py-3 sortable" data-sort="cds">Client/CDS <span class="sort-icon">↕</span></th>
                                <?php endif; ?>
                                <th class="border-0 fw-semibold text-dark py-3 sortable" data-sort="security">Security <span class="sort-icon">↕</span></th>
                                <th class="border-0 fw-semibold text-dark py-3 sortable" data-sort="asset">Asset Class <span class="sort-icon">↕</span></th>
                                <th class="border-0 fw-semibold text-dark py-3 sortable" data-sort="side">Side <span class="sort-icon">↕</span></th>
                                <th class="border-0 fw-semibold text-dark py-3 sortable" data-sort="quantity">Quantity <span class="sort-icon">↕</span></th>
                                <th class="border-0 fw-semibold text-dark py-3 sortable" data-sort="price">Price <span class="sort-icon">↕</span></th>
                                <th class="border-0 fw-semibold text-dark py-3 sortable" data-sort="value">Value <span class="sort-icon">↕</span></th>
                                <th class="border-0 fw-semibold text-dark py-3 sortable" data-sort="status">Status <span class="sort-icon">↕</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($current_trades)): ?>
                                <tr>
                                    <td colspan="<?php echo $show_individual_cds ? '8' : '9'; ?>" class="text-center py-4">
                                        <div class="text-muted">
                                            <i class="bi bi-info-circle me-2"></i>
                                            <?php if ($show_individual_cds): ?>
                                                No investments found for this CDS account.
                                            <?php else: ?>
                                                No investments found for this client.
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($current_trades as $trade): ?>
                                    <tr>
                                        <td class="border-0 py-3"><?php echo date('d/m/Y', strtotime($trade['trade_date'])); ?></td>
                                        <?php if (!$show_individual_cds): ?>
                                        <td class="border-0 py-3">
                                            <div class="fw-semibold"><?php echo htmlspecialchars($trade['client_name']); ?></div>
                                            <small class="text-muted"><?php echo htmlspecialchars($trade['cds_account']); ?></small>
                                        </td>
                                        <?php endif; ?>
                                        <td class="border-0 py-3">
                                            <div class="fw-semibold"><?php echo htmlspecialchars($trade['security_id']); ?></div>
                                            <small class="text-muted"><?php echo htmlspecialchars($trade['asset_name'] ?? 'N/A'); ?></small>
                                        </td>
                                        <td class="border-0 py-3">
                                            <span class="badge <?php 
                                                echo $trade['asset_class'] === 'bond' ? 'bg-warning' : 
                                                    ($trade['asset_class'] === 'Exchange Traded Funds' ? 'bg-purple' : 'bg-info'); 
                                            ?>">
                                                <?php echo $trade['asset_class'] === 'Exchange Traded Funds' ? 'ETF' : ucfirst($trade['asset_class']); ?>
                                            </span>
                                        </td>
                                        <td class="border-0 py-3">
                                            <span class="badge <?php echo ($trade['trade_side'] ?? '') == 'buy' ? 'bg-success' : 'bg-danger'; ?>">
                                                <i class="bi bi-arrow-<?php echo ($trade['trade_side'] ?? '') == 'buy' ? 'down' : 'up'; ?> me-1"></i>
                                                <?php echo htmlspecialchars(ucfirst($trade['trade_side'] ?? '')); ?>
                                            </span>
                                        </td>
                                        <td class="border-0 py-3"><?php echo number_format($trade['quantity'], 2); ?></td>
                                        <td class="border-0 py-3">
                                            <?php if ($trade['asset_class'] === 'bond'): ?>
                                                <?php echo htmlspecialchars($trade['price']); ?>%
                                            <?php else: ?>
                                                TZS <?php echo number_format($trade['price'], 2); ?>
                                            <?php endif; ?>
                                        </td>
                                        <td class="border-0 py-3 text-<?php echo ($trade['trade_side'] ?? '') == 'buy' ? 'danger' : 'success'; ?>">
                                            TZS <?php echo number_format($trade['consideration'], 2); ?>
                                        </td>
                                        <td class="border-0 py-3">
                                            <span class="badge <?php 
                                                echo $trade['status'] == 'active' ? 'bg-success' : 
                                                    ($trade['status'] == 'cancelled' ? 'bg-danger' : 'bg-info'); 
                                            ?>">
                                                <?php echo htmlspecialchars(ucfirst($trade['status'])); ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <?php if (!empty($current_trades)): ?>
                <div class="card-footer bg-transparent border-0 pt-3">
                    <div class="row align-items-center">
                        <div class="col-md-6">
                            <div class="text-muted small">
                                Showing <?php echo count($current_trades); ?> of <?php echo number_format(count($all_trades)); ?> total transactions
                            </div>
                        </div>
                        <div class="col-md-6 text-end">
                            <div class="btn-group">
                                <button class="btn btn-outline-secondary btn-sm" onclick="resetSort()">
                                    <i class="bi bi-arrow-clockwise me-1"></i>Reset Sort
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Balance Summary Card -->
        <?php
        $current_balance_summary = $show_individual_cds ? $individual_balance_summary : $balance_summary;
        if (!empty($current_balance_summary)): ?>
        <div class="card dashboard-card mb-4">
            <div class="card-header bg-transparent border-0 d-flex justify-content-between align-items-center">
                <h6 class="mb-0 fw-semibold">
                    <?php echo $show_individual_cds ? 'Individual CDS Balance Summary' : 'Portfolio Balance Summary'; ?>
                </h6>
                <div class="text-muted small">
                    Realized P&L: 
                    <span class="<?php 
                        $total_pnl = array_sum(array_column($current_balance_summary, 'realized_pnl'));
                        echo $total_pnl >= 0 ? 'text-success fw-bold' : 'text-danger fw-bold';
                    ?>">
                        TZS <?php echo number_format($total_pnl, 2); ?>
                    </span>
                </div>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0" id="balanceTable">
                        <thead style="background: linear-gradient(135deg, var(--background-secondary) 0%, var(--background-muted) 100%);">
                            <tr>
                                <th class="border-0 fw-semibold text-dark py-3 sortable" data-sort="security">Security <span class="sort-icon">↕</span></th>
                                <th class="border-0 fw-semibold text-dark py-3 sortable" data-sort="asset">Asset Class <span class="sort-icon">↕</span></th>
                                <th class="border-0 fw-semibold text-dark py-3 sortable" data-sort="buy_qty">Buy Qty <span class="sort-icon">↕</span></th>
                                <th class="border-0 fw-semibold text-dark py-3 sortable" data-sort="buy_val">Buy Value <span class="sort-icon">↕</span></th>
                                <th class="border-0 fw-semibold text-dark py-3 sortable" data-sort="sell_qty">Sell Qty <span class="sort-icon">↕</span></th>
                                <th class="border-0 fw-semibold text-dark py-3 sortable" data-sort="sell_val">Sell Value <span class="sort-icon">↕</span></th>
                                <th class="border-0 fw-semibold text-dark py-3 sortable" data-sort="balance_qty">Balance Qty <span class="sort-icon">↕</span></th>
                                <th class="border-0 fw-semibold text-dark py-3 sortable" data-sort="balance_val">Balance Value <span class="sort-icon">↕</span></th>
                                <th class="border-0 fw-semibold text-dark py-3 sortable" data-sort="pnl">Realized P&L <span class="sort-icon">↕</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($current_balance_summary as $item): ?>
                                <tr>
                                    <td class="border-0 py-3">
                                        <div class="fw-semibold"><?php echo htmlspecialchars($item['security_id']); ?></div>
                                        <small class="text-muted"><?php echo htmlspecialchars($item['asset_name'] ?? ''); ?></small>
                                    </td>
                                    <td class="border-0 py-3">
                                        <span class="badge <?php 
                                            echo $item['asset_class'] === 'bond' ? 'bg-warning' : 
                                                ($item['asset_class'] === 'Exchange Traded Funds' ? 'bg-purple' : 'bg-info'); 
                                        ?>">
                                            <?php echo $item['asset_class'] === 'Exchange Traded Funds' ? 'ETF' : ucfirst($item['asset_class']); ?>
                                        </span>
                                    </td>
                                    <td class="border-0 py-3 text-end"><?php echo number_format($item['buy_quantity'], 2); ?></td>
                                    <td class="border-0 py-3 text-end text-success">TZS <?php echo number_format($item['buy_value'], 2); ?></td>
                                    <td class="border-0 py-3 text-end"><?php echo number_format($item['sell_quantity'], 2); ?></td>
                                    <td class="border-0 py-3 text-end text-danger">TZS <?php echo number_format($item['sell_value'], 2); ?></td>
                                    <td class="border-0 py-3 text-end <?php echo $item['balance_quantity'] > 0 ? 'text-success fw-bold' : ($item['balance_quantity'] < 0 ? 'text-danger fw-bold' : ''); ?>">
                                        <?php echo number_format($item['balance_quantity'], 2); ?>
                                    </td>
                                    <td class="border-0 py-3 text-end <?php echo $item['balance_value'] > 0 ? 'text-success fw-bold' : ($item['balance_value'] < 0 ? 'text-danger fw-bold' : ''); ?>">
                                        TZS <?php echo number_format($item['balance_value'], 2); ?>
                                    </td>
                                    <td class="border-0 py-3 text-end <?php echo $item['realized_pnl'] > 0 ? 'text-success fw-bold' : ($item['realized_pnl'] < 0 ? 'text-danger fw-bold' : ''); ?>">
                                        TZS <?php echo number_format($item['realized_pnl'], 2); ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <!-- Totals Row -->
                            <tr class="border-top">
                                <td colspan="2" class="border-0 py-3 fw-bold">TOTALS:</td>
                                <td class="border-0 py-3 text-end fw-bold"><?php echo number_format(array_sum(array_column($current_balance_summary, 'buy_quantity')), 2); ?></td>
                                <td class="border-0 py-3 text-end fw-bold text-success">TZS <?php echo number_format(array_sum(array_column($current_balance_summary, 'buy_value')), 2); ?></td>
                                <td class="border-0 py-3 text-end fw-bold"><?php echo number_format(array_sum(array_column($current_balance_summary, 'sell_quantity')), 2); ?></td>
                                <td class="border-0 py-3 text-end fw-bold text-danger">TZS <?php echo number_format(array_sum(array_column($current_balance_summary, 'sell_value')), 2); ?></td>
                                <td class="border-0 py-3 text-end fw-bold <?php echo (array_sum(array_column($current_balance_summary, 'balance_quantity')) > 0) ? 'text-success' : 'text-danger'; ?>">
                                    <?php echo number_format(array_sum(array_column($current_balance_summary, 'balance_quantity')), 2); ?>
                                </td>
                                <td class="border-0 py-3 text-end fw-bold <?php echo (array_sum(array_column($current_balance_summary, 'balance_value')) > 0) ? 'text-success' : 'text-danger'; ?>">
                                    TZS <?php echo number_format(array_sum(array_column($current_balance_summary, 'balance_value')), 2); ?>
                                </td>
                                <td class="border-0 py-3 text-end fw-bold <?php echo (array_sum(array_column($current_balance_summary, 'realized_pnl')) > 0) ? 'text-success' : 'text-danger'; ?>">
                                    TZS <?php echo number_format(array_sum(array_column($current_balance_summary, 'realized_pnl')), 2); ?>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<!-- Edit Client Modal -->
<?php if ($client): ?>
<div class="modal fade" id="editClientModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="">
                <input type="hidden" name="client_id" value="<?php echo htmlspecialchars($client['id'] ?? ''); ?>">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Client Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="client_name" class="form-label">Client Name</label>
                        <input type="text" class="form-control" id="client_name" name="client_name" value="<?php echo htmlspecialchars($client['client_name'] ?? ''); ?>" required maxlength="255">
                    </div>
                    <div class="mb-3">
                        <label for="phone" class="form-label">Phone</label>
                        <input type="text" class="form-control" id="phone" name="phone" value="<?php echo htmlspecialchars($client['phone'] ?? ''); ?>" required pattern="[0-9+\-\s]+" title="Enter a valid phone number">
                    </div>
                    <div class="mb-3">
                        <label for="email" class="form-label">Email</label>
                        <input type="email" class="form-control" id="email" name="email" value="<?php echo htmlspecialchars($client['email'] ?? ''); ?>" required>
                    </div>
                    <div class="mb-3">
                        <label for="client_type" class="form-label">Client Type</label>
                        <select class="form-select" id="client_type" name="client_type" required>
                            <option value="corporate" <?php echo (($client['client_type'] ?? '') === 'corporate') ? 'selected' : ''; ?>>Corporate</option>
                            <option value="individual" <?php echo (($client['client_type'] ?? '') === 'individual') ? 'selected' : ''; ?>>Individual</option>
                            <option value="institutional" <?php echo (($client['client_type'] ?? '') === 'institutional') ? 'selected' : ''; ?>>Institutional</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="submit" name="update_client" class="btn btn-primary">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Merge CDS Modal -->
<div class="modal fade" id="mergeCDSModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="">
                <input type="hidden" name="primary_client_id" value="<?php echo htmlspecialchars($client['id'] ?? ''); ?>">
                <div class="modal-header">
                    <h5 class="modal-title">Merge CDS Accounts</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Primary Client</label>
                        <input type="text" class="form-control" value="<?php echo htmlspecialchars($client['client_name'] ?? ''); ?> (<?php echo htmlspecialchars($client['cds_account'] ?? ''); ?>)" readonly>
                        <small class="text-muted">This client will be the primary account</small>
                    </div>
                    <div class="mb-3">
                        <label for="merge_client_search" class="form-label">Search Client to Merge</label>
                        <input type="text" class="form-control mb-2" id="merge_client_search" placeholder="Type to filter clients..." onkeyup="filterClients()">
                        <select class="form-select" id="merge_client_id" name="merge_client_id" required size="5">
                            <?php foreach ($all_clients as $other_client): ?>
                                <?php if ($other_client['id'] != $client['id']): ?>
                                    <option value="<?php echo htmlspecialchars($other_client['id']); ?>" data-client-name="<?php echo htmlspecialchars($other_client['client_name']); ?>" data-cds="<?php echo htmlspecialchars($other_client['cds_account']); ?>">
                                        <?php echo htmlspecialchars($other_client['client_name']); ?> (<?php echo htmlspecialchars($other_client['cds_account']); ?>)
                                    </option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-muted">Select another client to merge their CDS account with the primary client</small>
                    </div>
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle me-2"></i>
                        Merging CDS accounts will combine all investments from both accounts. The merged account's trades will be visible under the primary client's combined portfolio.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="merge_cds" class="btn btn-primary">Merge Accounts</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Date Filter Modal -->
<div class="modal fade" id="dateFilterModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="GET" action="">
                <input type="hidden" name="id" value="<?php echo $client['id']; ?>">
                <?php if ($selected_cds): ?><input type="hidden" name="view_cds" value="<?php echo htmlspecialchars($selected_cds); ?>"><?php endif; ?>
                <div class="modal-header">
                    <h5 class="modal-title">Filter by Date Range</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="date_from" class="form-label">From Date</label>
                        <input type="date" class="form-control" id="date_from" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>">
                    </div>
                    <div class="mb-3">
                        <label for="date_to" class="form-label">To Date</label>
                        <input type="date" class="form-control" id="date_to" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>">
                    </div>
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle me-2"></i>
                        Leave dates empty to show all transactions. Both dates are inclusive.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Apply Filter</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Export Modal -->
<div class="modal fade" id="exportModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Export Data</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-4">
                    <h6 class="fw-semibold mb-3">Export Options</h6>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <div class="card border">
                                <div class="card-body text-center">
                                    <i class="bi bi-file-earmark-pdf text-danger fs-1 mb-2"></i>
                                    <h6 class="fw-semibold">PDF Export</h6>
                                    <p class="small text-muted">Export as printable PDF document</p>
                                    <div class="d-grid gap-2">
                                        <a href="?id=<?php echo $client['id']; ?>&export=pdf" class="btn btn-outline-danger btn-sm">
                                            <i class="bi bi-download me-1"></i>Full Portfolio PDF
                                        </a>
                                        <a href="?id=<?php echo $client['id']; ?>&export_filtered=pdf<?php echo $date_from ? '&date_from=' . urlencode($date_from) : ''; ?><?php echo $date_to ? '&date_to=' . urlencode($date_to) : ''; ?><?php echo $selected_cds ? '&view_cds=' . urlencode($selected_cds) : ''; ?>" class="btn btn-danger btn-sm">
                                            <i class="bi bi-filter me-1"></i>Filtered Data PDF
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <div class="card border">
                                <div class="card-body text-center">
                                    <i class="bi bi-file-earmark-excel text-success fs-1 mb-2"></i>
                                    <h6 class="fw-semibold">CSV Export</h6>
                                    <p class="small text-muted">Export as spreadsheet (Excel compatible)</p>
                                    <div class="d-grid gap-2">
                                        <a href="?id=<?php echo $client['id']; ?>&export=csv" class="btn btn-outline-success btn-sm">
                                            <i class="bi bi-download me-1"></i>Full Portfolio CSV
                                        </a>
                                        <a href="?id=<?php echo $client['id']; ?>&export_filtered=csv<?php echo $date_from ? '&date_from=' . urlencode($date_from) : ''; ?><?php echo $date_to ? '&date_to=' . urlencode($date_to) : ''; ?><?php echo $selected_cds ? '&view_cds=' . urlencode($selected_cds) : ''; ?>" class="btn btn-success btn-sm">
                                            <i class="bi bi-filter me-1"></i>Filtered Data CSV
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="alert alert-info">
                    <i class="bi bi-info-circle me-2"></i>
                    <strong>Current Selection:</strong><br>
                    • <?php echo $show_individual_cds && $selected_cds ? 'Individual CDS: ' . $selected_cds : 'Combined Portfolio'; ?><br>
                    <?php if ($date_from || $date_to): ?>
                    • Date Range: <?php echo $date_from ? $date_from : 'Start'; ?> to <?php echo $date_to ? $date_to : 'End'; ?><br>
                    <?php endif; ?>
                    • Showing <?php echo count($current_trades); ?> transactions
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<style>
.badge.bg-purple {
    background-color: #6f42c1 !important;
    color: white;
}
.table-responsive {
    max-height: 500px;
    overflow-y: auto;
}
.text-purple {
    color: #6f42c1 !important;
}
#merge_client_id {
    max-height: 200px;
    overflow-y: auto;
}
.sortable {
    cursor: pointer;
    user-select: none;
    position: relative;
}
.sortable:hover {
    background-color: rgba(0,0,0,0.05);
}
.sort-icon {
    font-size: 0.8em;
    margin-left: 5px;
}
.sort-asc .sort-icon::after {
    content: "↑";
}
.sort-desc .sort-icon::after {
    content: "↓";
}
</style>

<script>
// Add table sorting functionality
document.addEventListener('DOMContentLoaded', function() {
    // Initialize tables for sorting
    initTableSorting('#tradesTable');
    initTableSorting('#balanceTable');
    
    // Set today's date as max for date inputs
    const today = new Date().toISOString().split('T')[0];
    document.getElementById('date_from')?.setAttribute('max', today);
    document.getElementById('date_to')?.setAttribute('max', today);
    
    // Validate date range
    const dateFrom = document.getElementById('date_from');
    const dateTo = document.getElementById('date_to');
    
    if (dateFrom && dateTo) {
        dateFrom.addEventListener('change', function() {
            if (dateTo.value && this.value > dateTo.value) {
                dateTo.value = this.value;
            }
        });
        
        dateTo.addEventListener('change', function() {
            if (dateFrom.value && this.value < dateFrom.value) {
                dateFrom.value = this.value;
            }
        });
    }
});

function initTableSorting(tableSelector) {
    const table = document.querySelector(tableSelector);
    if (!table) return;
    
    const headers = table.querySelectorAll('.sortable');
    headers.forEach((header, index) => {
        header.addEventListener('click', () => {
            sortTable(table, index);
        });
    });
}

function sortTable(table, column) {
    const tbody = table.querySelector('tbody');
    const rows = Array.from(tbody.querySelectorAll('tr'));
    const header = table.querySelectorAll('.sortable')[column];
    
    // Remove sort classes from all headers
    table.querySelectorAll('.sortable').forEach(h => {
        h.classList.remove('sort-asc', 'sort-desc');
    });
    
    // Determine if numeric, date, or text
    const sampleCell = rows[0]?.cells[column]?.textContent;
    let sortType = 'text';
    
    if (sampleCell) {
        // Check for currency format
        if (sampleCell.includes('TZS') || sampleCell.match(/[\d,]+\.\d{2}/)) {
            sortType = 'currency';
        }
        // Check for date format (dd/mm/yyyy)
        else if (sampleCell.match(/\d{2}\/\d{2}\/\d{4}/)) {
            sortType = 'date';
        }
        // Check for percentage
        else if (sampleCell.includes('%')) {
            sortType = 'percentage';
        }
        // Check for plain numbers
        else if (sampleCell.replace(/[^0-9.-]/g, '') === sampleCell || 
                 sampleCell.match(/^[\d,]+$/)) {
            sortType = 'number';
        }
    }
    
    // Check current sort direction
    const isAsc = header.classList.contains('sort-asc');
    const newDirection = isAsc ? 'desc' : 'asc';
    
    // Clear all sort classes
    header.classList.remove('sort-asc', 'sort-desc');
    header.classList.add(`sort-${newDirection}`);
    
    rows.sort((a, b) => {
        let aVal = a.cells[column].textContent.trim();
        let bVal = b.cells[column].textContent.trim();
        
        // Skip total rows
        if (a.classList.contains('border-top') || b.classList.contains('border-top')) {
            return 0;
        }
        
        // Convert values based on type
        if (sortType === 'currency') {
            aVal = parseFloat(aVal.replace(/[^0-9.-]+/g, ''));
            bVal = parseFloat(bVal.replace(/[^0-9.-]+/g, ''));
        } else if (sortType === 'date') {
            const parts = aVal.split('/');
            aVal = new Date(parts[2], parts[1] - 1, parts[0]);
            parts = bVal.split('/');
            bVal = new Date(parts[2], parts[1] - 1, parts[0]);
        } else if (sortType === 'percentage') {
            aVal = parseFloat(aVal.replace('%', ''));
            bVal = parseFloat(bVal.replace('%', ''));
        } else if (sortType === 'number') {
            aVal = parseFloat(aVal.replace(/,/g, ''));
            bVal = parseFloat(bVal.replace(/,/g, ''));
        }
        
        if (newDirection === 'asc') {
            return aVal < bVal ? -1 : aVal > bVal ? 1 : 0;
        } else {
            return aVal > bVal ? -1 : aVal < bVal ? 1 : 0;
        }
    });
    
    // Reorder rows, keeping totals at bottom
    const totalRow = rows.find(row => row.classList.contains('border-top'));
    if (totalRow) {
        const index = rows.indexOf(totalRow);
        rows.splice(index, 1);
        rows.push(totalRow);
    }
    
    // Reorder rows in DOM
    rows.forEach(row => tbody.appendChild(row));
}

function resetSort() {
    const tables = ['#tradesTable', '#balanceTable'];
    tables.forEach(selector => {
        const table = document.querySelector(selector);
        if (table) {
            const tbody = table.querySelector('tbody');
            const rows = Array.from(tbody.querySelectorAll('tr'));
            
            // Remove sort classes
            table.querySelectorAll('.sortable').forEach(header => {
                header.classList.remove('sort-asc', 'sort-desc');
            });
            
            // Sort by original order (assuming first column has original order)
            rows.sort((a, b) => {
                const aIndex = Array.from(tbody.children).indexOf(a);
                const bIndex = Array.from(tbody.children).indexOf(b);
                return aIndex - bIndex;
            });
            
            rows.forEach(row => tbody.appendChild(row));
        }
    });
}

function filterClients() {
    const input = document.getElementById('merge_client_search');
    const filter = input.value.toLowerCase();
    const select = document.getElementById('merge_client_id');
    const options = select.getElementsByTagName('option');
    
    for (let i = 0; i < options.length; i++) {
        const option = options[i];
        const clientName = option.getAttribute('data-client-name').toLowerCase();
        const cds = option.getAttribute('data-cds').toLowerCase();
        const text = option.textContent.toLowerCase();
        
        if (clientName.includes(filter) || cds.includes(filter) || text.includes(filter)) {
            option.style.display = '';
        } else {
            option.style.display = 'none';
        }
    }
}

function toggleCDSView() {
    const checkbox = document.getElementById('showIndividualCDS');
    const url = new URL(window.location);
    
    if (checkbox.checked) {
        url.searchParams.set('view_individual', '1');
    } else {
        url.searchParams.delete('view_individual');
        url.searchParams.delete('view_cds');
    }
    
    window.location.href = url.toString();
}

function confirmUnmerge(mergeId, clientName) {
    if (confirm(`Are you sure you want to unmerge ${clientName} from this client?\n\nThis will separate their accounts but will not delete any transaction data.`)) {
        document.getElementById('unmergeMergeId').value = mergeId;
        document.getElementById('unmergeForm').submit();
    }
}

// Auto-hide alerts after 5 seconds
document.addEventListener('DOMContentLoaded', function() {
    const alerts = document.querySelectorAll('.alert');
    alerts.forEach(alert => {
        setTimeout(() => {
            const closeBtn = alert.querySelector('.btn-close');
            if (closeBtn) {
                closeBtn.click();
            }
        }, 5000);
    });
});
</script>

<?php include '../includes/footer.php'; ?>