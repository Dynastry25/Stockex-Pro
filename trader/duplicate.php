<?php
// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', '../logs/duplicate_trades_errors.log');

// Check if required files exist
if (!file_exists('../config/config.php')) {
    die('config.php not found');
}
if (!file_exists('../auth/auth_middleware.php')) {
    die('auth_middleware.php not found');
}

require_once '../config/config.php';
require_once '../auth/auth_middleware.php';

// Check if session is started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_login();
require_mandate();

$current_user = get_logged_in_user();

try {
    $db = getDBConnection();
    // Test the connection
    $db->query("SELECT 1");
} catch (Exception $e) {
    die("Database connection failed: " . $e->getMessage());
}

$message = '';
$error = '';
$duplicate_groups = [];
$show_all = isset($_GET['show_all']) && $_GET['show_all'] == 'true';
$export_high_risk = isset($_GET['export']) && $_GET['export'] == 'high_risk_excel';

// Get company details
function getCompanyDetails($db) {
    try {
        $stmt = $db->prepare("SELECT company_code, name as company_name FROM companies WHERE is_active = 1 LIMIT 1");
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$result) {
            return ['company_code' => 'B13/C', 'company_name' => 'Victory Financial Services'];
        }
        
        return [
            'company_code' => $result['company_code'],
            'company_name' => $result['company_name']
        ];
    } catch (Exception $e) {
        return ['company_code' => 'B000/C', 'company_name' => 'Neovam Technologies LTD'];
    }
}

$company_details = getCompanyDetails($db);
$company_code = $company_details['company_code'];
$company_name = $company_details['company_name'];

// Handle Excel export - MOVE THIS AFTER COMPANY DETAILS ARE SET
if (isset($_GET['export'])) {
    if ($_GET['export'] == 'excel') {
        exportDuplicatesToExcel($db, $show_all, $company_details, $current_user);
        exit;
    } elseif ($_GET['export'] == 'high_risk_excel') {
        exportHighRiskDuplicatesToExcel($db, $company_details, $current_user);
        exit;
    }
}

// Helper function for safe number formatting
function safe_number_format($value, $decimals = 2) {
    if ($value === '' || $value === null) {
        return '0.00';
    }
    $numeric_value = is_numeric($value) ? $value : 0;
    return number_format((float)$numeric_value, $decimals);
}

// Helper function for safe integer formatting
function safe_int_format($value) {
    if ($value === '' || $value === null) {
        return '0';
    }
    $numeric_value = is_numeric($value) ? $value : 0;
    return number_format((int)$numeric_value);
}

// Function to check if ONE receipt matches MULTIPLE duplicate trades
function checkSingleReceiptForDuplicateTrades($db, $client_cds_account, $consideration, $duplicate_count) {
    try {
        // Check if receipts table exists
        $table_check = $db->query("SHOW TABLES LIKE 'receipts'");
        if ($table_check->rowCount() == 0) {
            return null; // No receipts table
        }
        
        // Calculate consideration + 2.4%
        $consideration_with_margin = $consideration * 1.024;
        
        // Allow some tolerance (+/- 0.5%)
        $tolerance = 0.005;
        $min_amount = $consideration_with_margin * (1 - $tolerance);
        $max_amount = $consideration_with_margin * (1 + $tolerance);
        
        // Based on your table structure, we know the exact column names
        $cds_column = 'cds_account'; // From your table structure
        $amount_column = 'amount'; // From your table structure
        $receipt_column = 'receipt_no'; // From your table structure
        $receipt_date_column = 'receipt_date'; // From your table structure
        
        // Query to find receipts for this client with matching amount
        // IGNORING DATES - only matching CDS account and amount
        $query = "
            SELECT 
                {$receipt_column} as receipt_no, 
                {$amount_column} as amount,
                DATE({$receipt_date_column}) as receipt_date,
                COUNT(*) as receipt_count
            FROM receipts 
            WHERE {$cds_column} = :cds_account 
            AND {$amount_column} BETWEEN :min_amount AND :max_amount
            GROUP BY {$receipt_column}, {$amount_column}
            HAVING receipt_count = 1
        ";
        
        $stmt = $db->prepare($query);
        $stmt->execute([
            ':cds_account' => $client_cds_account,
            ':min_amount' => $min_amount,
            ':max_amount' => $max_amount
        ]);
        
        $receipts = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (count($receipts) === 1) {
            // EXACTLY ONE receipt matches this amount for this client
            $receipt = $receipts[0];
            
            // This is CRITICAL: 1 receipt for multiple duplicate trades
            return [
                'receipt_no' => $receipt['receipt_no'],
                'amount' => $receipt['amount'],
                'receipt_date' => $receipt['receipt_date'],
                'trade_consideration' => $consideration,
                'expected_amount' => $consideration_with_margin,
                'difference' => abs($receipt['amount'] - $consideration_with_margin),
                'difference_percentage' => abs(($receipt['amount'] - $consideration_with_margin) / $consideration_with_margin * 100),
                'receipt_count' => 1,
                'trade_duplicate_count' => $duplicate_count,
                'risk_level' => 'critical',
                'warning_message' => "CRITICAL: 1 RECEIPT for " . $duplicate_count . " DUPLICATE TRADES! Client paid once but recorded " . $duplicate_count . " times!"
            ];
        } elseif (count($receipts) > 1) {
            // Multiple receipts match - check if total receipts = duplicate trades
            $receipt = $receipts[0]; // Take the first one
            
            if (count($receipts) == $duplicate_count) {
                // Number of receipts equals number of duplicate trades - this is OK
                return [
                    'receipt_no' => $receipt['receipt_no'] . ' (+' . (count($receipts)-1) . ' more)',
                    'amount' => $receipt['amount'],
                    'receipt_date' => $receipt['receipt_date'],
                    'trade_consideration' => $consideration,
                    'expected_amount' => $consideration_with_margin,
                    'difference' => abs($receipt['amount'] - $consideration_with_margin),
                    'difference_percentage' => abs(($receipt['amount'] - $consideration_with_margin) / $consideration_with_margin * 100),
                    'receipt_count' => count($receipts),
                    'trade_duplicate_count' => $duplicate_count,
                    'risk_level' => 'medium',
                    'warning_message' => count($receipts) . " receipts found for " . $duplicate_count . " trades"
                ];
            } else if (count($receipts) < $duplicate_count) {
                // More trades than receipts - HIGH RISK
                return [
                    'receipt_no' => $receipt['receipt_no'] . ' (+' . (count($receipts)-1) . ' more)',
                    'amount' => $receipt['amount'],
                    'receipt_date' => $receipt['receipt_date'],
                    'trade_consideration' => $consideration,
                    'expected_amount' => $consideration_with_margin,
                    'difference' => abs($receipt['amount'] - $consideration_with_margin),
                    'difference_percentage' => abs(($receipt['amount'] - $consideration_with_margin) / $consideration_with_margin * 100),
                    'receipt_count' => count($receipts),
                    'trade_duplicate_count' => $duplicate_count,
                    'risk_level' => 'high',
                    'warning_message' => "HIGH RISK: " . count($receipts) . " receipts for " . $duplicate_count . " trades (missing receipts!)"
                ];
            }
        }
        
        // No matching receipts found
        return null;
    } catch (Exception $e) {
        error_log("Error checking matching receipt: " . $e->getMessage());
        return null;
    }
}

// Function to export only high-risk duplicates to Excel
function exportHighRiskDuplicatesToExcel($db, $company_details, $current_user) {
    $duplicates = getDuplicateGroups($db, true); // Get all duplicates
    
    // Filter only critical and high-risk duplicates
    $high_risk_duplicates = [];
    foreach ($duplicates as $group) {
        // Check for matching receipt
        $matching_receipt = checkSingleReceiptForDuplicateTrades(
            $db, 
            $group['client_cds_account'], 
            $group['consideration'],
            $group['duplicate_count']
        );
        
        if ($matching_receipt && ($matching_receipt['risk_level'] == 'critical' || $matching_receipt['risk_level'] == 'high')) {
            $group['matching_receipt'] = $matching_receipt;
            $high_risk_duplicates[] = $group;
        }
    }
    
    if (empty($high_risk_duplicates)) {
        // No high-risk duplicates found
        header('Content-Type: application/vnd.ms-excel');
        header('Content-Disposition: attachment; filename="high_risk_duplicates_none_' . date('Y-m-d_H-i-s') . '.xls"');
        echo "No high-risk duplicate trades found.";
        exit;
    }
    
    // Set headers for Excel download
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="high_risk_duplicates_' . date('Y-m-d_H-i-s') . '.xls"');
    header('Pragma: no-cache');
    header('Expires: 0');
    
    // Use safe values for company details
    $company_name = isset($company_details['company_name']) ? $company_details['company_name'] : 'Company Not Found';
    $company_code = isset($company_details['company_code']) ? $company_details['company_code'] : 'N/A';
    $username = isset($current_user['username']) ? $current_user['username'] : 'Unknown User';
    
    // Start Excel file with enhanced styling
    echo "<html>";
    echo "<head>";
    echo "<style>";
    echo "table { border-collapse: collapse; width: 100%; font-family: Arial, sans-serif; }";
    echo "th { background-color: #f2f2f2; font-weight: bold; padding: 8px; border: 1px solid #ddd; }";
    echo "td { padding: 8px; border: 1px solid #ddd; }";
    echo ".critical { background-color: #ffcccc; }";
    echo ".high { background-color: #ffe6cc; }";
    echo ".warning { color: #cc0000; font-weight: bold; }";
    echo "</style>";
    echo "</head>";
    echo "<body>";
    
    echo "<h2>HIGH RISK DUPLICATE TRADES REPORT</h2>";
    echo "<p><strong>Generated:</strong> " . date('Y-m-d H:i:s') . "</p>";
    echo "<p><strong>Generated by:</strong> " . htmlspecialchars($username) . "</p>";
    echo "<p><strong>Total High-Risk Groups:</strong> " . count($high_risk_duplicates) . "</p>";
    
    echo "<table border='1'>";
    
    // Header row
    echo "<tr>";
    echo "<th>No.</th>";
    echo "<th>Risk Level</th>";
    echo "<th>Client CDS</th>";
    echo "<th>Client Name</th>";
    echo "<th>Trade Consideration</th>";
    echo "<th>Expected (+2.4%)</th>";
    echo "<th>Duplicate Trade Count</th>";
    echo "<th>Matching Receipt No</th>";
    echo "<th>Receipt Amount</th>";
    echo "<th>Receipt Date</th>";
    echo "<th>Difference</th>";
    echo "<th>Receipt Count</th>";
    echo "<th>Trade References</th>";
    echo "<th>Trade Dates</th>";
    echo "<th>WARNING</th>";
    echo "</tr>";
    
    // Data rows
    $row_number = 1;
    foreach ($high_risk_duplicates as $duplicate) {
        $trade_refs = explode(',', $duplicate['all_refs']);
        
        // Determine row class based on risk level
        $row_class = '';
        if (isset($duplicate['matching_receipt']['risk_level'])) {
            $row_class = $duplicate['matching_receipt']['risk_level'];
        }
        
        echo "<tr class='{$row_class}'>";
        echo "<td>" . $row_number++ . "</td>";
        
        // Risk Level
        $risk_level = isset($duplicate['matching_receipt']['risk_level']) ? 
            strtoupper($duplicate['matching_receipt']['risk_level']) : 'HIGH';
        $risk_display = $risk_level;
        if ($risk_level == 'CRITICAL') {
            $risk_display = '<span class="warning">🚨 ' . $risk_level . '</span>';
        }
        echo "<td>" . $risk_display . "</td>";
        
        echo "<td>" . htmlspecialchars($duplicate['client_cds_account']) . "</td>";
        echo "<td>" . htmlspecialchars($duplicate['client_name']) . "</td>";
        echo "<td>" . number_format($duplicate['consideration'], 2) . " TZS</td>";
        
        // Expected amount
        $expected_amount = $duplicate['consideration'] * 1.024;
        echo "<td>" . number_format($expected_amount, 2) . " TZS</td>";
        
        echo "<td>" . $duplicate['duplicate_count'] . "</td>";
        
        // Receipt information
        if (isset($duplicate['matching_receipt'])) {
            echo "<td><strong>" . htmlspecialchars($duplicate['matching_receipt']['receipt_no']) . "</strong></td>";
            echo "<td>" . number_format($duplicate['matching_receipt']['amount'], 2) . " TZS</td>";
            echo "<td>" . htmlspecialchars($duplicate['matching_receipt']['receipt_date']) . "</td>";
            echo "<td>" . number_format($duplicate['matching_receipt']['difference'], 2) . " TZS (" . 
                 number_format($duplicate['matching_receipt']['difference_percentage'], 2) . "%)</td>";
            echo "<td>" . ($duplicate['matching_receipt']['receipt_count'] ?? 'N/A') . "</td>";
        } else {
            echo "<td></td><td></td><td></td><td></td><td></td>";
        }
        
        // Trade references (all)
        $all_refs = implode(', ', array_map('htmlspecialchars', $trade_refs));
        echo "<td>" . $all_refs . "</td>";
        
        // Trade dates (all are the same in duplicate group)
        echo "<td>" . $duplicate['trade_date'] . "</td>";
        
        // Warning message
        $warning = isset($duplicate['matching_receipt']['warning_message']) ? 
            $duplicate['matching_receipt']['warning_message'] : 
            "High Risk: Payment-receipt mismatch detected";
        $warning_display = $warning;
        if (isset($duplicate['matching_receipt']['risk_level']) && $duplicate['matching_receipt']['risk_level'] == 'critical') {
            $warning_display = '<span class="warning">🚨 ' . $warning . '</span>';
        }
        echo "<td>" . $warning_display . "</td>";
        
        echo "</tr>";
    }
    
    echo "</table>";
    
    // Summary section
    echo "<h3>RISK ANALYSIS SUMMARY</h3>";
    
    // Count risk levels
    $critical_count = 0;
    $high_count = 0;
    foreach ($high_risk_duplicates as $duplicate) {
        if (isset($duplicate['matching_receipt']['risk_level'])) {
            if ($duplicate['matching_receipt']['risk_level'] == 'critical') {
                $critical_count++;
            } elseif ($duplicate['matching_receipt']['risk_level'] == 'high') {
                $high_count++;
            }
        }
    }
    
    echo "<table border='1'>";
    echo "<tr><th>Risk Level</th><th>Count</th><th>Description</th><th>Recommended Action</th></tr>";
    
    if ($critical_count > 0) {
        echo "<tr class='critical'>";
        echo "<td><strong>CRITICAL</strong></td>";
        echo "<td>" . $critical_count . "</td>";
        echo "<td>1 receipt payment for multiple duplicate trades. Client paid once but recorded multiple times!</td>";
        echo "<td><strong>IMMEDIATE ACTION REQUIRED:</strong> Contact client, verify payment, correct records</td>";
        echo "</tr>";
    }
    
    if ($high_count > 0) {
        echo "<tr class='high'>";
        echo "<td><strong>HIGH</strong></td>";
        echo "<td>" . $high_count . "</td>";
        echo "<td>Missing receipts for duplicate trades. Some trades may not have matching payments.</td>";
        echo "<td>Investigate missing receipts, contact finance department</td>";
        echo "</tr>";
    }
    
    // Total financial risk calculation
    $total_risk_amount = 0;
    foreach ($high_risk_duplicates as $duplicate) {
        if (isset($duplicate['matching_receipt']['risk_level']) && $duplicate['matching_receipt']['risk_level'] == 'critical') {
            // For critical risk, calculate potential overcharge
            $potential_overcharge = $duplicate['consideration'] * ($duplicate['duplicate_count'] - 1);
            $total_risk_amount += $potential_overcharge;
        }
    }
    
    echo "</table>";
    
    if ($total_risk_amount > 0) {
        echo "<h3 class='warning'>FINANCIAL RISK ASSESSMENT</h3>";
        echo "<p><strong>Potential Overcharge Amount (Critical Risk Only):</strong> " . number_format($total_risk_amount, 2) . " TZS</p>";
        echo "<p><strong>This represents potential financial loss to clients that requires immediate attention!</strong></p>";
    }
    
    echo "<h3>INSTRUCTIONS FOR INVESTIGATION</h3>";
    echo "<ol>";
    echo "<li><strong>Critical Risk Cases (1 receipt for multiple trades):</strong>";
    echo "<ul>";
    echo "<li>Contact the client immediately to verify the single payment</li>";
    echo "<li>Check bank statements for the receipt amount</li>";
    echo "<li>Verify which trade(s) are legitimate</li>";
    echo "<li>Mark all but one trade as duplicates</li>";
    echo "<li>Document the investigation for audit purposes</li>";
    echo "</ul></li>";
    
    echo "<li><strong>High Risk Cases (missing receipts):</strong>";
    echo "<ul>";
    echo "<li>Check with accounts department for missing receipts</li>";
    echo "<li>Verify if payments are pending or delayed</li>";
    echo "<li>Follow up with client for missing payments if needed</li>";
    echo "</ul></li>";
    echo "</ol>";
    
    echo "<p><em>Report generated by: " . htmlspecialchars($company_name) . " (" . htmlspecialchars($company_code) . ")</em></p>";
    
    echo "</body></html>";
    exit;
}

// Function to export all duplicates to Excel
function exportDuplicatesToExcel($db, $include_reconciled = false, $company_details, $current_user) {
    $duplicates = getDuplicateGroupsForExport($db, $include_reconciled);
    
    // Check receipts for each duplicate
    foreach ($duplicates as &$duplicate) {
        $matching_receipt = checkSingleReceiptForDuplicateTrades(
            $db, 
            $duplicate['client_cds_account'], 
            $duplicate['consideration'],
            $duplicate['duplicate_count']
        );
        
        if ($matching_receipt) {
            $duplicate['matching_receipt'] = $matching_receipt;
        }
    }
    
    // Set headers for Excel download
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="duplicate_trades_' . date('Y-m-d_H-i-s') . '.xls"');
    header('Pragma: no-cache');
    header('Expires: 0');
    
    // Use safe values
    $company_name = isset($company_details['company_name']) ? $company_details['company_name'] : 'Company Not Found';
    $company_code = isset($company_details['company_code']) ? $company_details['company_code'] : 'N/A';
    $username = isset($current_user['username']) ? $current_user['username'] : 'Unknown User';
    
    echo "<html>";
    echo "<head>";
    echo "<style>";
    echo "table { border-collapse: collapse; width: 100%; }";
    echo "th { background-color: #f2f2f2; font-weight: bold; }";
    echo ".critical { background-color: #ffcccc; }";
    echo ".high { background-color: #ffe6cc; }";
    echo ".medium { background-color: #ffffcc; }";
    echo ".warning { color: #cc0000; font-weight: bold; }";
    echo "</style>";
    echo "</head>";
    echo "<body>";
    
    // Title and summary
    echo "<h2>DUPLICATE TRADES REPORT</h2>";
    echo "<p><strong>Generated:</strong> " . date('Y-m-d H:i:s') . " | <strong>By:</strong> " . htmlspecialchars($username) . "</p>";
    
    // Risk summary
    $critical_count = 0;
    $high_count = 0;
    $medium_count = 0;
    $total_duplicate_trades = 0;
    
    foreach ($duplicates as $duplicate) {
        $total_duplicate_trades += $duplicate['duplicate_count'];
        if (isset($duplicate['matching_receipt'])) {
            if ($duplicate['matching_receipt']['risk_level'] == 'critical') {
                $critical_count++;
            } elseif ($duplicate['matching_receipt']['risk_level'] == 'high') {
                $high_count++;
            } else {
                $medium_count++;
            }
        } else {
            $medium_count++;
        }
    }
    
    echo "<p><strong>Total Groups:</strong> " . count($duplicates) . " | ";
    echo "<strong>Total Duplicate Trades:</strong> " . $total_duplicate_trades . "</p>";
    
    echo "<p><strong>Risk Breakdown:</strong> ";
    echo "<span style='color: #dc3545;'>Critical: " . $critical_count . "</span> | ";
    echo "<span style='color: #ffc107;'>High: " . $high_count . "</span> | ";
    echo "<span style='color: #6c757d;'>Medium: " . $medium_count . "</span></p>";
    
    echo "<table border='1'>";
    
    // Header row
    echo "<tr>";
    echo "<th>No.</th>";
    echo "<th>Risk</th>";
    echo "<th>Client CDS</th>";
    echo "<th>Client Name</th>";
    echo "<th>Security</th>";
    echo "<th>Trade Side</th>";
    echo "<th>Quantity</th>";
    echo "<th>Price</th>";
    echo "<th>Consideration</th>";
    echo "<th>+2.4% Expected</th>";
    echo "<th>Duplicates</th>";
    echo "<th>Receipt No</th>";
    echo "<th>Receipt Amount</th>";
    echo "<th>Receipt Date</th>";
    echo "<th>Difference</th>";
    echo "<th>Trade Date</th>";
    echo "<th>Trade Ref</th>";
    echo "<th>Warning</th>";
    echo "</tr>";
    
    // Data rows
    $row_number = 1;
    foreach ($duplicates as $duplicate) {
        $trade_refs = explode(',', $duplicate['all_refs']);
        $created_times = explode(',', $duplicate['created_times']);
        
        // Determine risk and styling
        $risk_level = 'medium';
        $row_class = 'medium';
        if (isset($duplicate['matching_receipt'])) {
            $risk_level = $duplicate['matching_receipt']['risk_level'];
            $row_class = $risk_level;
        }
        
        // Calculate expected amount
        $expected_amount = $duplicate['consideration'] * 1.024;
        
        for ($i = 0; $i < count($trade_refs); $i++) {
            echo "<tr class='{$row_class}'>";
            echo "<td>" . $row_number++ . "</td>";
            
            // Risk level
            $risk_display = strtoupper($risk_level);
            if ($risk_level == 'critical') {
                $risk_display = '<span class="warning">🚨 CRITICAL</span>';
            }
            echo "<td>" . $risk_display . "</td>";
            
            echo "<td>" . htmlspecialchars($duplicate['client_cds_account']) . "</td>";
            echo "<td>" . htmlspecialchars($duplicate['client_name']) . "</td>";
            echo "<td>" . htmlspecialchars($duplicate['security_name']) . "</td>";
            echo "<td>" . ucfirst($duplicate['trade_side']) . "</td>";
            echo "<td>" . number_format($duplicate['quantity']) . "</td>";
            echo "<td>" . number_format($duplicate['price'], 2) . "</td>";
            echo "<td>" . number_format($duplicate['consideration'], 2) . " TZS</td>";
            echo "<td>" . number_format($expected_amount, 2) . " TZS</td>";
            echo "<td>" . $duplicate['duplicate_count'] . "</td>";
            
            // Receipt information (show only for first row of each group)
            if ($i == 0 && isset($duplicate['matching_receipt'])) {
                echo "<td>" . htmlspecialchars($duplicate['matching_receipt']['receipt_no']) . "</td>";
                echo "<td>" . number_format($duplicate['matching_receipt']['amount'], 2) . " TZS</td>";
                echo "<td>" . htmlspecialchars($duplicate['matching_receipt']['receipt_date']) . "</td>";
                echo "<td>" . number_format($duplicate['matching_receipt']['difference'], 2) . " TZS<br>(" . 
                     number_format($duplicate['matching_receipt']['difference_percentage'], 2) . "%)</td>";
            } else {
                echo "<td></td><td></td><td></td><td></td>";
            }
            
            echo "<td>" . $duplicate['trade_date'] . "</td>";
            echo "<td>" . htmlspecialchars($trade_refs[$i] ?? '') . "</td>";
            
            // Warning message (only for first row)
            if ($i == 0) {
                $warning = '';
                if (isset($duplicate['matching_receipt'])) {
                    $warning = $duplicate['matching_receipt']['warning_message'];
                    if ($risk_level == 'critical') {
                        $warning = '<span class="warning">🚨 ' . $warning . '</span>';
                    }
                }
                echo "<td>" . $warning . "</td>";
            } else {
                echo "<td></td>";
            }
            
            echo "</tr>";
        }
    }
    
    echo "</table>";
    
    // Footer notes
    echo "<h3>REPORT NOTES</h3>";
    echo "<ul>";
    echo "<li><strong>Critical Risk:</strong> 1 receipt for multiple duplicate trades - Requires immediate investigation</li>";
    echo "<li><strong>High Risk:</strong> Missing receipts for some duplicate trades</li>";
    echo "<li><strong>Medium Risk:</strong> No receipt matching issues detected</li>";
    echo "<li><strong>Matching Logic:</strong> Trade consideration + 2.4% = Receipt amount (±0.5% tolerance)</li>";
    echo "<li>Dates are ignored in matching - payment dates may differ from trade dates</li>";
    echo "<li><strong>Only BUY trades are considered</strong> - SELL trades are excluded from this analysis</li>";
    echo "</ul>";
    
    echo "<p><em>Generated by " . htmlspecialchars($company_name) . " (" . htmlspecialchars($company_code) . ")</em></p>";
    
    echo "</body></html>";
    exit;
}

// Function to get duplicate groups for export (ONLY BUY TRADES)
function getDuplicateGroupsForExport($db, $include_reconciled = false) {
    // Temporarily disable ONLY_FULL_GROUP_BY mode
    $original_sql_mode = '';
    try {
        $mode_stmt = $db->query("SELECT @@sql_mode as sql_mode");
        $original_sql_mode = $mode_stmt->fetch(PDO::FETCH_ASSOC)['sql_mode'];
        $db->exec("SET SESSION sql_mode=(SELECT REPLACE(@@sql_mode,'ONLY_FULL_GROUP_BY',''))");
    } catch (Exception $e) {
        error_log("Could not get/set sql_mode: " . $e->getMessage());
    }
    
    // Check if reconciliation_status column exists
    $reconciliation_condition = "";
    try {
        $checkStmt = $db->query("SHOW COLUMNS FROM trades LIKE 'reconciliation_status'");
        if ($checkStmt->rowCount() > 0) {
            if (!$include_reconciled) {
                $reconciliation_condition = " AND (t1.reconciliation_status IS NULL OR t1.reconciliation_status = 'pending')";
            }
        }
    } catch (Exception $e) {
        // Column doesn't exist or error checking
    }
    
    // IMPORTANT: Only include BUY trades, not SELL trades
    $query = "
        SELECT 
            t1.client_cds_account,
            t1.client_name,
            t1.security_id,
            t1.security_name,
            t1.trade_side,
            t1.quantity,
            t1.price,
            DATE(t1.trade_date) as trade_date,
            t1.asset_class,
            t1.status,
            
            MIN(t1.id) as keep_id,
            GROUP_CONCAT(t1.id ORDER BY t1.created_at) as all_ids,
            GROUP_CONCAT(t1.trade_reference ORDER BY t1.created_at) as all_refs,
            GROUP_CONCAT(DATE_FORMAT(t1.created_at, '%Y-%m-%d %H:%i') ORDER BY t1.created_at) as created_times,
            
            MAX(t1.consideration) as consideration,
            SUM(t1.consideration) as total_consideration,
            COUNT(*) as duplicate_count,
            MIN(t1.created_at) as first_created,
            MAX(t1.created_at) as last_created
            
        FROM trades t1
        WHERE t1.status = 'active'
        AND t1.trade_side = 'buy'  -- ONLY BUY TRADES
        {$reconciliation_condition}
        GROUP BY 
            t1.client_cds_account,
            t1.security_id,
            DATE(t1.trade_date),
            t1.quantity,
            t1.price,
            t1.trade_side,
            t1.asset_class,
            t1.status
            
        HAVING COUNT(*) > 1
        ORDER BY duplicate_count DESC, total_consideration DESC, last_created DESC
    ";
    
    try {
        $stmt = $db->prepare($query);
        $stmt->execute();
        $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Restore original sql_mode if we changed it
        if ($original_sql_mode) {
            $db->exec("SET SESSION sql_mode='$original_sql_mode'");
        }
        
        return $result;
    } catch (Exception $e) {
        error_log("Error in getDuplicateGroupsForExport: " . $e->getMessage());
        error_log("Query: " . $query);
        
        // Try to restore sql_mode even on error
        if ($original_sql_mode) {
            try {
                $db->exec("SET SESSION sql_mode='$original_sql_mode'");
            } catch (Exception $e2) {
                // Ignore restore error
            }
        }
        
        return [];
    }
}

// Process actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        $transaction_started = false;
        
        try {
            // Check if we need to add reconciliation_status column
            try {
                $checkStmt = $db->query("SHOW COLUMNS FROM trades LIKE 'reconciliation_status'");
                if ($checkStmt->rowCount() == 0) {
                    $db->exec("ALTER TABLE trades ADD COLUMN reconciliation_status ENUM('pending', 'reconciled') DEFAULT 'pending' AFTER status");
                }
            } catch (Exception $e) {
                error_log("Could not check/add reconciliation_status column: " . $e->getMessage());
            }
            
            if ($_POST['action'] === 'mark_selected') {
                // Mark selected trades as duplicates (keep only selected ones)
                if (!empty($_POST['keep_trades'])) {
                    $db->beginTransaction();
                    $transaction_started = true;
                    $keep_ids = $_POST['keep_trades'];
                    
                    // Get all trade IDs in each duplicate group
                    $all_duplicates = getDuplicateGroups($db, true); // Get all including reconciled
                    
                    foreach ($all_duplicates as $group) {
                        $group_ids = explode(',', $group['all_ids']);
                        
                        // Find which IDs in this group should be kept
                        $keep_in_group = array_intersect($group_ids, $keep_ids);
                        
                        if (count($keep_in_group) === 0) {
                            // If no trades selected in this group, keep the earliest one
                            $keep_in_group = [$group['keep_id']];
                        }
                        
                        // Mark all others as duplicates
                        $mark_ids = array_diff($group_ids, $keep_in_group);
                        
                        if (!empty($mark_ids)) {
                            $placeholders = implode(',', array_fill(0, count($mark_ids), '?'));
                            
                            // Check if notes column exists, add if not
                            $notes_column = '';
                            try {
                                $checkStmt = $db->query("SHOW COLUMNS FROM trades LIKE 'notes'");
                                if ($checkStmt->rowCount() > 0) {
                                    $notes_column = "notes = CONCAT(COALESCE(notes, ''), ' | Marked as duplicate on ', NOW(), ' by ', ?),";
                                } else {
                                    // Add notes column if it doesn't exist
                                    $db->exec("ALTER TABLE trades ADD COLUMN notes TEXT NULL AFTER failure_reason");
                                    $notes_column = "notes = CONCAT('Marked as duplicate on ', NOW(), ' by ', ?),";
                                }
                            } catch (Exception $e) {
                                $notes_column = "";
                            }
                            
                            $stmt = $db->prepare("
                                UPDATE trades 
                                SET status = 'duplicate', 
                                    $notes_column
                                    updated_at = NOW()
                                WHERE id IN ($placeholders) AND status = 'active'
                            ");
                            
                            $params = [$current_user['username']];
                            $params = array_merge($params, $mark_ids);
                            $stmt->execute($params);
                        }
                    }
                    
                    $message = "Successfully processed duplicate trades. Selected trades were kept, others marked as duplicates.";
                }
                
            } elseif ($_POST['action'] === 'mark_all_duplicates') {
                // Mark all except first in each group as duplicates
                $db->beginTransaction();
                $transaction_started = true;
                
                $all_duplicates = getDuplicateGroups($db, true); // Get all including reconciled
                $marked_count = 0;
                
                foreach ($all_duplicates as $group) {
                    $group_ids = explode(',', $group['all_ids']);
                    $keep_id = $group['keep_id']; // Earliest trade
                    
                    // Mark all except the earliest
                    $mark_ids = array_diff($group_ids, [$keep_id]);
                    
                    if (!empty($mark_ids)) {
                        $placeholders = implode(',', array_fill(0, count($mark_ids), '?'));
                        
                        // Check if notes column exists
                        $notes_column = '';
                        try {
                            $checkStmt = $db->query("SHOW COLUMNS FROM trades LIKE 'notes'");
                            if ($checkStmt->rowCount() > 0) {
                                $notes_column = "notes = CONCAT(COALESCE(notes, ''), ' | Auto-marked as duplicate on ', NOW(), ' (earliest kept: ', ? , ')'),";
                            }
                        } catch (Exception $e) {
                            $notes_column = "";
                        }
                        
                        $stmt = $db->prepare("
                            UPDATE trades 
                            SET status = 'duplicate', 
                                $notes_column
                                updated_at = NOW()
                            WHERE id IN ($placeholders) AND status = 'active'
                        ");
                        
                        $params = [$group['all_refs']];
                        $params = array_merge($params, $mark_ids);
                        $stmt->execute($params);
                        $marked_count += $stmt->rowCount();
                    }
                }
                
                $message = "Marked {$marked_count} trades as duplicates (kept earliest trade in each group).";
                
            } elseif ($_POST['action'] === 'reconcile_selected') {
                // RECONCILE SELECTED TRADES MANUALLY
                if (!empty($_POST['reconcile_trades'])) {
                    $db->beginTransaction();
                    $transaction_started = true;
                    
                    $reconcile_ids = $_POST['reconcile_trades'];
                    $placeholders = implode(',', array_fill(0, count($reconcile_ids), '?'));
                    
                    // Update reconciliation status to 'reconciled'
                    $stmt = $db->prepare("
                        UPDATE trades 
                        SET reconciliation_status = 'reconciled',
                            updated_at = NOW()
                        WHERE id IN ($placeholders)
                    ");
                    
                    $stmt->execute($reconcile_ids);
                    $reconciled_count = $stmt->rowCount();
                    
                    $message = "Successfully reconciled {$reconciled_count} trades manually.";
                }
            } elseif ($_POST['action'] === 'unreconcile_selected') {
                // UNRECONCILE SELECTED TRADES
                if (!empty($_POST['reconcile_trades'])) {
                    $db->beginTransaction();
                    $transaction_started = true;
                    
                    $unreconcile_ids = $_POST['reconcile_trades'];
                    $placeholders = implode(',', array_fill(0, count($unreconcile_ids), '?'));
                    
                    // Update reconciliation status back to 'pending'
                    $stmt = $db->prepare("
                        UPDATE trades 
                        SET reconciliation_status = 'pending',
                            updated_at = NOW()
                        WHERE id IN ($placeholders)
                    ");
                    
                    $stmt->execute($unreconcile_ids);
                    $unreconciled_count = $stmt->rowCount();
                    
                    $message = "Successfully marked {$unreconciled_count} trades as pending (un-reconciled).";
                }
            }
            
            if ($transaction_started) {
                $db->commit();
            }
            
        } catch (Exception $e) {
            if ($transaction_started) {
                try {
                    $db->rollBack();
                } catch (Exception $rollback_e) {
                    // Ignore rollback error
                }
            }
            $error = "Error processing request: " . $e->getMessage();
            error_log("Duplicate trade processing error: " . $e->getMessage());
        }
    }
}

// Function to get duplicate groups (ONLY BUY TRADES)
function getDuplicateGroups($db, $include_reconciled = false) {
    // Temporarily disable ONLY_FULL_GROUP_BY mode
    $original_sql_mode = '';
    try {
        $mode_stmt = $db->query("SELECT @@sql_mode as sql_mode");
        $original_sql_mode = $mode_stmt->fetch(PDO::FETCH_ASSOC)['sql_mode'];
        $db->exec("SET SESSION sql_mode=(SELECT REPLACE(@@sql_mode,'ONLY_FULL_GROUP_BY',''))");
    } catch (Exception $e) {
        error_log("Could not get/set sql_mode: " . $e->getMessage());
    }
    
    // Check if reconciliation_status column exists
    $reconciliation_condition = "";
    try {
        $checkStmt = $db->query("SHOW COLUMNS FROM trades LIKE 'reconciliation_status'");
        if ($checkStmt->rowCount() > 0) {
            if (!$include_reconciled) {
                $reconciliation_condition = " AND (reconciliation_status IS NULL OR reconciliation_status = 'pending')";
            }
        }
    } catch (Exception $e) {
        // Column doesn't exist or error checking
    }
    
    // IMPORTANT: Only include BUY trades, not SELL trades
    $query = "
        SELECT 
            t1.client_cds_account,
            t1.client_name,
            t1.security_id,
            t1.security_name,
            t1.trade_side,
            t1.quantity,
            t1.price,
            DATE(t1.trade_date) as trade_date,
            t1.asset_class,
            
            MIN(t1.id) as keep_id,
            GROUP_CONCAT(t1.id ORDER BY t1.created_at) as all_ids,
            GROUP_CONCAT(t1.trade_reference ORDER BY t1.created_at) as all_refs,
            GROUP_CONCAT(DATE_FORMAT(t1.created_at, '%Y-%m-%d %H:%i') ORDER BY t1.created_at) as created_times,
            
            MAX(t1.consideration) as consideration,
            SUM(t1.consideration) as total_consideration,
            COUNT(*) as duplicate_count,
            MIN(t1.created_at) as first_created,
            MAX(t1.created_at) as last_created
            
        FROM trades t1
        WHERE t1.status = 'active'
        AND t1.trade_side = 'buy'  -- ONLY BUY TRADES
        {$reconciliation_condition}
        GROUP BY 
            t1.client_cds_account,
            t1.security_id,
            DATE(t1.trade_date),
            t1.quantity,
            t1.price,
            t1.trade_side,
            t1.asset_class
            
        HAVING COUNT(*) > 1
        ORDER BY duplicate_count DESC, total_consideration DESC, last_created DESC
    ";
    
    try {
        $stmt = $db->prepare($query);
        $stmt->execute();
        $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Restore original sql_mode if we changed it
        if ($original_sql_mode) {
            $db->exec("SET SESSION sql_mode='$original_sql_mode'");
        }
        
        return $result;
    } catch (Exception $e) {
        error_log("Error in getDuplicateGroups: " . $e->getMessage());
        error_log("Query: " . $query);
        
        // Try to restore sql_mode even on error
        if ($original_sql_mode) {
            try {
                $db->exec("SET SESSION sql_mode='$original_sql_mode'");
            } catch (Exception $e2) {
                // Ignore restore error
            }
        }
        
        return [];
    }
}

// Get duplicate groups (ONLY BUY TRADES)
$duplicate_groups = getDuplicateGroups($db, $show_all);
$total_duplicate_trades = 0;
$total_groups = count($duplicate_groups);
$total_consideration_at_risk = 0;

// Check for receipt matches - IGNORING DATES, only money movement
$duplicate_groups_with_receipts = [];
$trades_with_receipt_count = 0;
$critical_risk_count = 0; // 1 receipt for multiple trades
$high_risk_count = 0;     // Missing receipts
$potential_overcharge_total = 0;

foreach ($duplicate_groups as &$group) {
    $total_duplicate_trades += $group['duplicate_count'];
    
    // Calculate risk
    if (isset($group['total_consideration']) && isset($group['duplicate_count'])) {
        $avg_consideration = $group['total_consideration'] / $group['duplicate_count'];
        $total_consideration_at_risk += ($group['total_consideration'] - $avg_consideration);
    }
    
    // Check for matching receipt - IGNORING DATES, only money
    if (isset($group['consideration']) && isset($group['client_cds_account'])) {
        $matching_receipt = checkSingleReceiptForDuplicateTrades(
            $db, 
            $group['client_cds_account'], 
            $group['consideration'],
            $group['duplicate_count']
        );
        
        if ($matching_receipt) {
            $group['matching_receipt'] = $matching_receipt;
            $group['has_receipt_match'] = true;
            $trades_with_receipt_count++;
            
            // Set risk level
            if ($matching_receipt['risk_level'] == 'critical') {
                $group['risk_level'] = 'critical';
                $group['risk_color'] = 'danger';
                $group['risk_icon'] = 'exclamation-octagon';
                $critical_risk_count++;
                
                // Calculate potential overcharge
                $potential_overcharge = $group['consideration'] * ($group['duplicate_count'] - 1);
                $potential_overcharge_total += $potential_overcharge;
                $group['potential_overcharge'] = $potential_overcharge;
                
            } elseif ($matching_receipt['risk_level'] == 'high') {
                $group['risk_level'] = 'high';
                $group['risk_color'] = 'warning';
                $group['risk_icon'] = 'exclamation-triangle';
                $high_risk_count++;
            } else {
                $group['risk_level'] = 'medium';
                $group['risk_color'] = 'info';
                $group['risk_icon'] = 'info-circle';
            }
        } else {
            $group['has_receipt_match'] = false;
            $group['risk_level'] = 'medium';
            $group['risk_color'] = 'secondary';
            $group['risk_icon'] = 'exclamation-circle';
        }
    }
}

// Get total active BUY trades count
$total_active_buy_trades = 0;
try {
    $stmt = $db->query("SELECT COUNT(*) FROM trades WHERE status = 'active' AND trade_side = 'buy'");
    $total_active_buy_trades = $stmt->fetchColumn();
} catch (Exception $e) {
    error_log("Error counting active buy trades: " . $e->getMessage());
}

include '../includes/header.php';
?>

<div class="container-fluid">

        <?php if ($message): ?>
            <div class="alert alert-success">
                <i class="bi bi-check-circle me-2"></i>
                <?php echo $message; ?>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert alert-danger">
                <i class="bi bi-exclamation-triangle me-2"></i>
                <?php echo nl2br(htmlspecialchars($error)); ?>
            </div>
        <?php endif; ?>

        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">Duplicate BUY Trade Detection & Risk Analysis</h5>
                <small class="text-muted">
                    <span class="badge bg-danger">Critical Risk</span>: 1 receipt payment for multiple duplicate BUY trades | 
                    <span class="badge bg-warning">High Risk</span>: Missing receipts | 
                    <span class="badge bg-secondary">Medium Risk</span>: Normal duplicate BUY trades
                </small>
            </div>
            <div class="card-body">
                <!-- Quick Actions Row with Financial Impact -->
                <div class="row mb-4">
                    <div class="col-md-2">
                        <div class="stats-card danger-stat">
                            <h6 class="text-muted mb-2">Duplicate Groups</h6>
                            <h3 class="mb-0"><?php echo $total_groups; ?></h3>
                            <small class="text-danger">
                                <i class="bi bi-collection me-1"></i>Groups of identical BUY trades
                            </small>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="stats-card warning-stat">
                            <h6 class="text-muted mb-2">Total Duplicate Trades</h6>
                            <h3 class="mb-0"><?php echo $total_duplicate_trades; ?></h3>
                            <small class="text-warning">
                                <i class="bi bi-copy me-1"></i>All duplicate BUY entries
                            </small>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="stats-card <?php echo $critical_risk_count > 0 ? 'danger-stat' : 'info-stat'; ?>">
                            <h6 class="text-muted mb-2">Critical Risk</h6>
                            <h3 class="mb-0"><?php echo $critical_risk_count; ?></h3>
                            <small class="<?php echo $critical_risk_count > 0 ? 'text-danger' : 'text-info'; ?>">
                                <i class="bi bi-exclamation-octagon me-1"></i>
                                1 receipt, multiple BUY trades
                            </small>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stats-card warning-stat">
                            <h6 class="text-muted mb-2">Potential Overcharge</h6>
                            <h3 class="mb-0"><?php echo number_format($potential_overcharge_total, 2); ?> TZS</h3>
                            <small class="text-warning">
                                <i class="bi bi-cash-stack me-1"></i>
                                From critical risk cases
                            </small>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stats-card success-stat">
                            <h6 class="text-muted mb-2">Total Active BUY Trades</h6>
                            <h3 class="mb-0"><?php echo number_format($total_active_buy_trades); ?></h3>
                            <small class="text-success">
                                <i class="bi bi-shield-check me-1"></i>
                                All BUY trades in system
                            </small>
                        </div>
                    </div>
                </div>

                <!-- Action Buttons -->
                <div class="d-flex flex-wrap gap-2 mb-4">
                    <div class="btn-group">
                        <a href="?export=excel<?php echo $show_all ? '&show_all=true' : ''; ?>" class="btn btn-success">
                            <i class="bi bi-file-excel me-2"></i>Export All
                        </a>
                        <a href="?export=high_risk_excel" class="btn btn-danger">
                            <i class="bi bi-file-excel me-2"></i>Export High Risk Only
                        </a>
                    </div>
                    <button type="button" class="btn btn-primary" onclick="selectAllLatestTrades()">
                        <i class="bi bi-check-all me-2"></i>Select All Latest Trades
                    </button>
                    <button type="button" class="btn btn-secondary" onclick="deselectAllTrades()">
                        <i class="bi bi-x-circle me-2"></i>Clear All Selections
                    </button>
                    <button type="button" class="btn btn-outline-danger" onclick="selectAllCriticalRiskTrades()">
                        <i class="bi bi-exclamation-octagon me-2"></i>Select Critical Risk Only
                    </button>
                    <button type="button" class="btn btn-outline-warning" onclick="selectAllHighRiskTrades()">
                        <i class="bi bi-exclamation-triangle me-2"></i>Select All High Risk
                    </button>
                    <button type="button" class="btn btn-danger" onclick="autoResolveCriticalRisk()">
                        <i class="bi bi-shield-check me-2"></i>Auto-resolve Critical Risk
                    </button>
                    <a href="dashboard.php" class="btn btn-outline-primary">
                        <i class="bi bi-house me-2"></i>Dashboard
                    </a>
                </div>

                <?php if ($critical_risk_count > 0): ?>
                    <!-- Critical Risk Alert Banner -->
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <h4 class="alert-heading"><i class="bi bi-exclamation-octagon me-2"></i>CRITICAL RISK ALERT!</h4>
                        <p>
                            <strong><?php echo $critical_risk_count; ?> critical risk cases detected!</strong> 
                            Clients have made SINGLE payments but were recorded with MULTIPLE BUY trades.
                        </p>
                        <p class="mb-0">
                            <strong>Potential Financial Impact:</strong> <?php echo number_format($potential_overcharge_total, 2); ?> TZS
                            | <strong>Required Action:</strong> Immediate investigation and correction
                        </p>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <?php if ($total_groups > 0): ?>
                    <!-- Quick Actions for Critical Risk -->
                    <?php if ($critical_risk_count > 0): ?>
                    <div class="card mb-4 border-danger">
                        <div class="card-header bg-danger text-white">
                            <h5 class="mb-0"><i class="bi bi-lightning me-2"></i>CRITICAL RISK QUICK ACTIONS</h5>
                        </div>
                        <div class="card-body">
                            <p class="text-danger"><strong>🚨 URGENT: These cases require immediate attention!</strong></p>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <button type="button" class="btn btn-outline-danger w-100" onclick="autoResolveCriticalRisk()">
                                        <i class="bi bi-shield-check me-2"></i>
                                        Auto-resolve Critical Risk
                                    </button>
                                    <small class="form-text text-muted">Keep earliest BUY trade, mark others as duplicates for ALL critical risk cases</small>
                                </div>
                                <div class="col-md-6">
                                    <a href="?export=high_risk_excel" class="btn btn-danger w-100">
                                        <i class="bi bi-file-excel me-2"></i>
                                        Export Critical Risk Report
                                    </a>
                                    <small class="form-text text-muted">Export detailed report for investigation and audit trail</small>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Reconciliation Form -->
                    <form method="POST" id="mainForm">
                        <?php foreach ($duplicate_groups as $index => $group): 
                            $trade_ids = explode(',', $group['all_ids']);
                            $trade_refs = explode(',', $group['all_refs']);
                            $created_times = explode(',', $group['created_times']);
                            $group_id = 'group_' . $index;
                            
                            // Determine group styling based on risk level
                            $group_color_class = '';
                            $receipt_badge = '';
                            $receipt_info = '';
                            $group_border = '';
                            $group_background = '';
                            
                            if (isset($group['has_receipt_match']) && $group['has_receipt_match']) {
                                if ($group['risk_level'] == 'critical') {
                                    $group_color_class = 'critical-highlight';
                                    $group_border = 'border: 3px solid #dc3545;';
                                    $group_background = 'background: linear-gradient(to right, rgba(255, 255, 255, 0.9), rgba(255, 230, 230, 0.8));';
                                    $receipt_badge = '<span class="badge bg-danger ms-2"><i class="bi bi-exclamation-octagon me-1"></i>🚨 CRITICAL: 1 Payment for ' . $group['duplicate_count'] . ' BUY Trades</span>';
                                    
                                    if (isset($group['potential_overcharge'])) {
                                        $receipt_badge .= ' <span class="badge bg-dark ms-1">Potential Overcharge: ' . number_format($group['potential_overcharge'], 2) . ' TZS</span>';
                                    }
                                    
                                    $receipt_info = '
                                        <div class="alert alert-danger mt-2 p-2">
                                            <small>
                                                <i class="bi bi-receipt me-1"></i>
                                                <strong>🚨 CRITICAL FINANCIAL RISK:</strong> 
                                                ' . ($group['matching_receipt']['warning_message'] ?? '') . '<br>
                                                <strong>Receipt No:</strong> <code>' . htmlspecialchars($group['matching_receipt']['receipt_no'] ?? '') . '</code> | 
                                                <strong>Amount:</strong> ' . safe_number_format($group['matching_receipt']['amount'] ?? 0, 2) . ' TZS |
                                                <strong>Expected:</strong> ' . safe_number_format($group['matching_receipt']['expected_amount'] ?? 0, 2) . ' TZS |
                                                <strong>Payment Date:</strong> ' . htmlspecialchars($group['matching_receipt']['receipt_date'] ?? '') . ' |
                                                <strong>Trade Date:</strong> ' . $group['trade_date'] . '
                                            </small>
                                        </div>
                                    ';
                                } else {
                                    $group_color_class = 'danger-highlight';
                                    $group_border = 'border: 2px solid #ffc107;';
                                    $receipt_badge = '<span class="badge bg-warning text-dark ms-2"><i class="bi bi-exclamation-triangle me-1"></i>High Risk: ' . ($group['matching_receipt']['receipt_count'] ?? 'N/A') . ' Receipt(s)</span>';
                                    
                                    $receipt_info = '
                                        <div class="alert alert-warning mt-2 p-2">
                                            <small>
                                                <i class="bi bi-receipt me-1"></i>
                                                <strong>High Risk:</strong> 
                                                ' . ($group['matching_receipt']['warning_message'] ?? '') . ' |
                                                Receipt No: <code>' . htmlspecialchars($group['matching_receipt']['receipt_no'] ?? '') . '</code> | 
                                                Amount: ' . safe_number_format($group['matching_receipt']['amount'] ?? 0, 2) . ' TZS |
                                                Payment Date: ' . htmlspecialchars($group['matching_receipt']['receipt_date'] ?? '') . '
                                            </small>
                                        </div>
                                    ';
                                }
                            }
                        ?>
                        <div class="duplicate-group <?php echo $group_color_class; ?>" id="<?php echo $group_id; ?>" style="<?php echo $group_border . $group_background; ?>">
                            <div class="group-header collapsed" data-bs-toggle="collapse" 
                                 data-bs-target="#collapse_<?php echo $index; ?>" aria-expanded="false">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div class="d-flex align-items-center">
                                        <i class="bi bi-chevron-down collapse-icon me-3"></i>
                                        <div class="form-check me-3">
                                            <input class="form-check-input reconcile-checkbox" 
                                                   type="checkbox" 
                                                   name="reconcile_trades[]" 
                                                   value="<?php echo $trade_ids[0]; ?>"
                                                   id="reconcile_<?php echo $index; ?>">
                                            <label class="form-check-label visually-hidden" for="reconcile_<?php echo $index; ?>">
                                                Reconcile this trade
                                            </label>
                                        </div>
                                        <div>
                                            <h6 class="mb-1">
                                                <span class="badge bg-<?php echo $group['risk_color'] ?? 'danger'; ?> me-2">
                                                    <i class="bi bi-<?php echo $group['risk_icon'] ?? 'exclamation-triangle'; ?> me-1"></i>
                                                    <?php echo $group['duplicate_count']; ?> duplicate BUY trades
                                                </span>
                                                <?php echo htmlspecialchars($group['client_name']); ?>
                                                <small class="text-muted ms-2">(CDS: <?php echo htmlspecialchars($group['client_cds_account']); ?>)</small>
                                                <?php echo $receipt_badge; ?>
                                            </h6>
                                            <div class="small">
                                                <span class="me-3">
                                                    <i class="bi bi-shield me-1"></i>
                                                    <?php echo htmlspecialchars($group['security_name']); ?>
                                                </span>
                                                <span class="me-3">
                                                    <i class="bi bi-arrow-left-right me-1"></i>
                                                    <?php echo ucfirst($group['trade_side']); ?>
                                                </span>
                                                <span class="me-3">
                                                    <i class="bi bi-calendar me-1"></i>
                                                    <?php echo $group['trade_date']; ?>
                                                </span>
                                                <span>
                                                    <i class="bi bi-cash-coin me-1"></i>
                                                    <?php echo safe_number_format($group['total_consideration'], 2); ?> TZS
                                                </span>
                                            </div>
                                        </div>
                                    </div>
                                    <div>
                                        <span class="badge bg-<?php echo $group['risk_color'] ?? 'warning'; ?> risk-badge">
                                            <i class="bi bi-<?php echo $group['risk_icon'] ?? 'exclamation-triangle'; ?> me-1"></i>
                                            <?php echo strtoupper($group['risk_level'] ?? 'medium'); ?> RISK
                                        </span>
                                    </div>
                                </div>
                                <?php echo $receipt_info; ?>
                            </div>
                            
                            <div class="collapse" id="collapse_<?php echo $index; ?>">
                                <div class="p-3">
                                    <div class="table-responsive">
                                        <table class="table table-sm mb-0">
                                            <thead>
                                                <tr class="table-<?php echo $group['risk_color'] ?? 'light'; ?>">
                                                    <th width="40"></th>
                                                    <th>Trade Reference</th>
                                                    <th>Created Time</th>
                                                    <th>Quantity</th>
                                                    <th>Price</th>
                                                    <th>Consideration</th>
                                                    <th>+2.4% Expected</th>
                                                    <th>Age</th>
                                                    <th>Select to Keep</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php for ($i = 0; $i < count($trade_ids); $i++): 
                                                    $trade_id = $trade_ids[$i];
                                                    $is_earliest = ($trade_id == $group['keep_id']);
                                                    $created_time = $created_times[$i] ?? '';
                                                    $age_minutes = $created_time ? 
                                                        round((time() - strtotime($created_time)) / 60) : 0;
                                                    
                                                    // Calculate expected amount (+2.4%)
                                                    $expected_amount = $group['consideration'] * 1.024;
                                                    
                                                    // Style row based on risk level
                                                    $row_class = $is_earliest ? 'table-info' : '';
                                                    if (isset($group['has_receipt_match']) && $group['has_receipt_match']) {
                                                        if ($group['risk_level'] == 'critical') {
                                                            $row_class .= ' critical-risk-row';
                                                        } else {
                                                            $row_class .= ' high-risk-row';
                                                        }
                                                    }
                                                ?>
                                                <tr class="trade-item <?php echo $row_class; ?>" 
                                                    data-trade-id="<?php echo $trade_id; ?>"
                                                    data-risk-level="<?php echo $group['risk_level'] ?? 'medium'; ?>"
                                                    data-has-receipt="<?php echo isset($group['has_receipt_match']) && $group['has_receipt_match'] ? 'true' : 'false'; ?>">
                                                    <td>
                                                        <?php if ($is_earliest): ?>
                                                            <span class="badge bg-info">
                                                                <i class="bi bi-star-fill"></i> Earliest
                                                            </span>
                                                        <?php endif; ?>
                                                        <?php if (isset($group['has_receipt_match']) && $group['has_receipt_match'] && $i == 0): ?>
                                                            <span class="badge bg-<?php echo $group['risk_color'] ?? 'danger'; ?>" 
                                                                  title="<?php echo $group['matching_receipt']['warning_message'] ?? 'Matching receipt found'; ?>">
                                                                <i class="bi bi-receipt"></i>
                                                                <?php echo $group['risk_level'] == 'critical' ? '🚨' : ''; ?>
                                                            </span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <code><?php echo htmlspecialchars($trade_refs[$i] ?? ''); ?></code>
                                                    </td>
                                                    <td>
                                                        <span class="time-badge">
                                                            <i class="bi bi-clock me-1"></i>
                                                            <?php echo $created_time; ?>
                                                        </span>
                                                    </td>
                                                    <td><?php echo safe_int_format($group['quantity']); ?></td>
                                                    <td><?php echo safe_number_format($group['price'], 2); ?></td>
                                                    <td>
                                                        <strong><?php echo safe_number_format($group['consideration'], 2); ?> TZS</strong>
                                                    </td>
                                                    <td>
                                                        <span class="<?php echo isset($group['has_receipt_match']) && $group['has_receipt_match'] ? 'text-danger fw-bold' : 'text-muted'; ?>">
                                                            <?php echo safe_number_format($expected_amount, 2); ?> TZS
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <?php if ($age_minutes < 60): ?>
                                                            <span class="badge bg-success"><?php echo $age_minutes; ?>m ago</span>
                                                        <?php elseif ($age_minutes < 1440): ?>
                                                            <span class="badge bg-warning text-dark"><?php echo floor($age_minutes/60); ?>h ago</span>
                                                        <?php else: ?>
                                                            <span class="badge bg-secondary"><?php echo floor($age_minutes/1440); ?>d ago</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td class="checkbox-container">
                                                        <div class="form-check form-check-inline">
                                                            <input class="form-check-input trade-checkbox" 
                                                                   type="checkbox" 
                                                                   name="keep_trades[]" 
                                                                   value="<?php echo $trade_id; ?>"
                                                                   id="keep_<?php echo $trade_id; ?>"
                                                                   <?php echo ($i === 0) ? 'checked' : ''; ?>
                                                                   data-risk-level="<?php echo $group['risk_level'] ?? 'medium'; ?>"
                                                                   data-receipt-match="<?php echo isset($group['has_receipt_match']) && $group['has_receipt_match'] ? 'true' : 'false'; ?>">
                                                            <label class="form-check-label visually-hidden" for="keep_<?php echo $trade_id; ?>">
                                                                Keep this BUY trade
                                                            </label>
                                                        </div>
                                                    </td>
                                                </tr>
                                                <?php endfor; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                    
                                    <div class="mt-3 pt-3 border-top">
                                        <div class="row">
                                            <div class="col-md-6">
                                                <small class="text-muted">
                                                    <i class="bi bi-info-circle me-1"></i>
                                                    Select which BUY trade(s) to keep. Unselected trades will be marked as duplicates.
                                                    <?php if (isset($group['has_receipt_match']) && $group['has_receipt_match']): ?>
                                                        <span class="text-<?php echo $group['risk_color'] ?? 'danger'; ?>">
                                                            <i class="bi bi-<?php echo $group['risk_icon'] ?? 'exclamation-triangle'; ?> me-1"></i>
                                                            <?php echo $group['risk_level'] == 'critical' ? '🚨 CRITICAL RISK:' : 'High Risk:'; ?> 
                                                            <?php echo $group['matching_receipt']['warning_message'] ?? ''; ?>
                                                        </span>
                                                    <?php endif; ?>
                                                </small>
                                            </div>
                                            <div class="col-md-6 text-end">
                                                <div class="btn-group btn-group-sm">
                                                    <button type="button" class="btn btn-outline-success" 
                                                            onclick="selectAllInGroup('<?php echo $group_id; ?>')">
                                                        <i class="bi bi-check-square me-1"></i>All in Group
                                                    </button>
                                                    <button type="button" class="btn btn-outline-secondary" 
                                                            onclick="selectEarliestInGroup('<?php echo $group_id; ?>')">
                                                        <i class="bi bi-star me-1"></i>Earliest Only
                                                    </button>
                                                    <button type="button" class="btn btn-outline-primary" 
                                                            onclick="selectLatestInGroup('<?php echo $group_id; ?>')">
                                                        <i class="bi bi-clock-history me-1"></i>Latest Only
                                                    </button>
                                                    <button type="button" class="btn btn-outline-danger" 
                                                            onclick="deselectAllInGroup('<?php echo $group_id; ?>')">
                                                        <i class="bi bi-x-square me-1"></i>None
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>

                        <!-- Action Buttons -->
                        <div class="action-buttons mt-4">
                            <div class="row g-3">
                                <div class="col-md-3">
                                    <button type="submit" name="action" value="mark_selected" 
                                            class="btn btn-success w-100" onclick="return validateMarkSelected()">
                                        <i class="bi bi-check-circle me-2"></i>
                                        Keep Selected & Mark Others
                                    </button>
                                    <small class="form-text text-muted">Keep selected BUY trades, mark others as duplicates</small>
                                </div>
                                <div class="col-md-3">
                                    <button type="submit" name="action" value="reconcile_selected" 
                                            class="btn btn-warning w-100" onclick="return validateReconcileSelected()">
                                        <i class="bi bi-check2-all me-2"></i>
                                        Reconcile Selected
                                    </button>
                                    <small class="form-text text-muted">Mark selected BUY trades as reconciled</small>
                                </div>
                                <div class="col-md-3">
                                    <button type="submit" name="action" value="unreconcile_selected" 
                                            class="btn btn-secondary w-100" onclick="return confirm('Mark selected BUY trades as pending (un-reconcile)?')">
                                        <i class="bi bi-arrow-counterclockwise me-2"></i>
                                        Un-Reconcile Selected
                                    </button>
                                    <small class="form-text text-muted">Mark selected BUY trades back as pending</small>
                                </div>
                                <div class="col-md-3">
                                    <button type="button" class="btn btn-danger w-100" onclick="autoResolveCriticalRisk()">
                                        <i class="bi bi-shield-check me-2"></i>
                                        Auto-resolve Critical Risk
                                    </button>
                                    <small class="form-text text-muted">For critical risk BUY trades only</small>
                                </div>
                            </div>
                        </div>
                    </form>

                    <script>
                    // Toggle trade selection
                    document.querySelectorAll('.trade-item').forEach(row => {
                        row.addEventListener('click', function(e) {
                            if (!e.target.closest('.form-check-input') && !e.target.closest('button')) {
                                const checkbox = this.querySelector('.trade-checkbox');
                                if (checkbox) {
                                    checkbox.checked = !checkbox.checked;
                                    this.classList.toggle('selected', checkbox.checked);
                                }
                            }
                        });
                    });

                    // Group selection functions
                    function selectAllInGroup(groupId) {
                        const group = document.getElementById(groupId);
                        group.querySelectorAll('.trade-checkbox').forEach(cb => {
                            cb.checked = true;
                            cb.closest('.trade-item').classList.add('selected');
                        });
                    }

                    function deselectAllInGroup(groupId) {
                        const group = document.getElementById(groupId);
                        group.querySelectorAll('.trade-checkbox').forEach(cb => {
                            cb.checked = false;
                            cb.closest('.trade-item').classList.remove('selected');
                        });
                    }

                    function selectEarliestInGroup(groupId) {
                        const group = document.getElementById(groupId);
                        group.querySelectorAll('.trade-checkbox').forEach(cb => {
                            const isEarliest = cb.closest('.trade-item').querySelector('.badge.bg-info');
                            cb.checked = isEarliest !== null;
                            cb.closest('.trade-item').classList.toggle('selected', isEarliest !== null);
                        });
                    }

                    function selectLatestInGroup(groupId) {
                        const group = document.getElementById(groupId);
                        const checkboxes = group.querySelectorAll('.trade-checkbox');
                        if (checkboxes.length > 0) {
                            checkboxes.forEach(cb => cb.checked = false);
                            checkboxes[checkboxes.length - 1].checked = true;
                            
                            group.querySelectorAll('.trade-item').forEach(row => row.classList.remove('selected'));
                            checkboxes[checkboxes.length - 1].closest('.trade-item').classList.add('selected');
                        }
                    }

                    // Select all critical risk trades (1 receipt for multiple trades)
                    function selectAllCriticalRiskTrades() {
                        let count = 0;
                        document.querySelectorAll('.trade-item[data-risk-level="critical"]').forEach(row => {
                            const checkbox = row.querySelector('.trade-checkbox');
                            if (checkbox) {
                                checkbox.checked = true;
                                row.classList.add('selected');
                                count++;
                            }
                        });
                        
                        if (count > 0) {
                            alert('Selected ' + count + ' critical risk BUY trades. These have 1 receipt for multiple duplicate BUY trades.');
                        } else {
                            alert('No critical risk BUY trades found.');
                        }
                    }

                    // Select all high risk trades (with receipt matches)
                    function selectAllHighRiskTrades() {
                        let count = 0;
                        document.querySelectorAll('.trade-item[data-has-receipt="true"]').forEach(row => {
                            const checkbox = row.querySelector('.trade-checkbox');
                            if (checkbox) {
                                checkbox.checked = true;
                                row.classList.add('selected');
                                count++;
                            }
                        });
                        
                        if (count > 0) {
                            alert('Selected ' + count + ' high risk BUY trades (with receipt matches).');
                        } else {
                            alert('No high risk BUY trades found.');
                        }
                    }

                    // Auto-resolve critical risk cases
                    function autoResolveCriticalRisk() {
                        let criticalGroups = [];
                        document.querySelectorAll('.duplicate-group').forEach(group => {
                            const firstTrade = group.querySelector('.trade-item[data-risk-level="critical"]');
                            if (firstTrade) {
                                criticalGroups.push({
                                    id: group.id,
                                    name: group.querySelector('.client-name')?.textContent || 'Unknown',
                                    count: group.querySelectorAll('.trade-checkbox').length
                                });
                            }
                        });
                        
                        if (criticalGroups.length === 0) {
                            alert('No critical risk duplicate BUY trade groups found.');
                            return;
                        }
                        
                        let message = '🚨 CRITICAL RISK AUTO-RESOLUTION 🚨\n\n';
                        message += 'This will automatically resolve ' + criticalGroups.length + ' critical risk cases:\n\n';
                        message += '• Keep the EARLIEST BUY trade in each critical risk group\n';
                        message += '• Mark all other BUY trades as duplicates\n';
                        message += '• Create audit trail notes\n\n';
                        message += 'Critical Risk Groups to resolve:\n';
                        
                        criticalGroups.forEach(group => {
                            message += '• ' + group.name + ' (' + group.count + ' BUY trades)\n';
                        });
                        
                        message += '\n\nWARNING: This action is IRREVERSIBLE for critical risk cases!\n';
                        message += 'These cases involve potential financial loss to clients.\n\n';
                        message += 'Do you want to proceed with auto-resolution?';
                        
                        if (confirm(message)) {
                            // Uncheck all checkboxes first
                            document.querySelectorAll('.trade-checkbox').forEach(cb => {
                                cb.checked = false;
                                cb.closest('.trade-item').classList.remove('selected');
                            });
                            
                            // Check only earliest trades in critical risk groups
                            criticalGroups.forEach(groupInfo => {
                                const group = document.getElementById(groupInfo.id);
                                const earliestCheckbox = group.querySelector('.trade-item .badge.bg-info + .trade-checkbox');
                                if (earliestCheckbox) {
                                    earliestCheckbox.checked = true;
                                    earliestCheckbox.closest('.trade-item').classList.add('selected');
                                } else {
                                    // If no earliest badge, select first trade
                                    const firstCheckbox = group.querySelector('.trade-checkbox');
                                    if (firstCheckbox) {
                                        firstCheckbox.checked = true;
                                        firstCheckbox.closest('.trade-item').classList.add('selected');
                                    }
                                }
                            });
                            
                            // Show final confirmation
                            if (confirm('Ready to submit. This will resolve ' + criticalGroups.length + ' critical risk BUY trade cases.\n\nClick OK to proceed with marking duplicates.')) {
                                // Submit the form
                                document.querySelector('button[name="action"][value="mark_selected"]').click();
                            }
                        }
                    }

                    function selectAllLatestTrades() {
                        document.querySelectorAll('.duplicate-group').forEach(group => {
                            const checkboxes = group.querySelectorAll('.trade-checkbox');
                            if (checkboxes.length > 0) {
                                checkboxes.forEach(cb => cb.checked = false);
                                checkboxes[checkboxes.length - 1].checked = true;
                                
                                group.querySelectorAll('.trade-item').forEach(row => row.classList.remove('selected'));
                                checkboxes[checkboxes.length - 1].closest('.trade-item').classList.add('selected');
                            }
                        });
                    }

                    function deselectAllTrades() {
                        document.querySelectorAll('.trade-checkbox').forEach(cb => {
                            cb.checked = false;
                            cb.closest('.trade-item').classList.remove('selected');
                        });
                        document.querySelectorAll('.reconcile-checkbox').forEach(cb => {
                            cb.checked = false;
                        });
                    }

                    // Form validation with enhanced warnings for critical risk
                    function validateMarkSelected() {
                        const checkboxes = document.querySelectorAll('.trade-checkbox:checked');
                        if (checkboxes.length === 0) {
                            alert('Please select at least one BUY trade to keep in each group.');
                            return false;
                        }
                        
                        // Check each group has at least one selected trade
                        let allGroupsValid = true;
                        let criticalRiskGroups = [];
                        let highRiskGroups = [];
                        
                        document.querySelectorAll('.duplicate-group').forEach(group => {
                            const checkedCount = group.querySelectorAll('.trade-checkbox:checked').length;
                            if (checkedCount === 0) {
                                allGroupsValid = false;
                                const groupId = group.id;
                                const riskLevel = group.querySelector('.trade-item')?.dataset.riskLevel || 'medium';
                                const hasReceipt = group.querySelector('.trade-item[data-has-receipt="true"]');
                                const clientName = group.querySelector('h6')?.textContent?.split('(')[0]?.trim() || 'Unknown';
                                
                                let message = 'ERROR: No BUY trade selected in group:\n';
                                message += '• Client: ' + clientName + '\n';
                                message += '• Group ID: ' + groupId + '\n\n';
                                
                                if (hasReceipt) {
                                    if (riskLevel === 'critical') {
                                        criticalRiskGroups.push({id: groupId, name: clientName});
                                        message += '🚨 CRITICAL FINANCIAL RISK DETECTED!\n';
                                        message += '• This client made ONE payment but has ' + group.querySelectorAll('.trade-checkbox').length + ' duplicate BUY trades\n';
                                        message += '• This indicates POTENTIAL DOUBLE CHARGING!\n';
                                        message += '• IMMEDIATE ACTION REQUIRED!\n\n';
                                        message += 'Please select which BUY trade(s) to keep.';
                                    } else {
                                        highRiskGroups.push(groupId);
                                        message += '⚠️ HIGH RISK: This group has matching receipt(s)!\n';
                                        message += 'Please select which BUY trade(s) to keep.';
                                    }
                                } else {
                                    message += 'Please select which BUY trade(s) to keep.';
                                }
                                
                                alert(message);
                                
                                // Scroll to problematic group
                                group.scrollIntoView({ behavior: 'smooth', block: 'center' });
                                
                                // Expand if collapsed
                                const header = group.querySelector('.group-header');
                                if (header.classList.contains('collapsed')) {
                                    header.click();
                                }
                            }
                        });
                        
                        if (!allGroupsValid) return false;
                        
                        const totalTrades = document.querySelectorAll('.trade-checkbox').length;
                        const unselectedCount = totalTrades - checkboxes.length;
                        
                        // Check for critical and high risk trades
                        const criticalRiskTrades = document.querySelectorAll('.trade-checkbox[data-risk-level="critical"]:checked');
                        const highRiskTrades = document.querySelectorAll('.trade-checkbox[data-risk-level="high"]:checked');
                        
                        let warningMessage = '';
                        
                        if (criticalRiskTrades.length > 0) {
                            warningMessage = `\n\n🚨 CRITICAL FINANCIAL RISK ALERT: ${criticalRiskTrades.length} selected BUY trade(s)!\n`;
                            warningMessage += `• These involve SINGLE PAYMENTS for MULTIPLE BUY TRADES\n`;
                            warningMessage += `• Potential CLIENT OVERCHARGING detected\n`;
                            warningMessage += `• REQUIRES IMMEDIATE VERIFICATION with client\n`;
                            warningMessage += `• Document all actions for audit trail\n`;
                        }
                        
                        if (highRiskTrades.length > 0) {
                            warningMessage += `\n\n⚠️ HIGH RISK: ${highRiskTrades.length} selected BUY trade(s) have receipt matching issues.\n`;
                            warningMessage += `Please verify with accounts department.`;
                        }
                        
                        if (warningMessage) {
                            warningMessage += `\n\nAre you SURE you want to proceed with marking ${unselectedCount} BUY trades as duplicates?`;
                            return confirm(warningMessage);
                        }
                        
                        return confirm(`This will mark ${unselectedCount} BUY trades as duplicates. Continue?`);
                    }

                    function validateReconcileSelected() {
                        const reconcileTrades = document.querySelectorAll('.reconcile-checkbox:checked');
                        if (reconcileTrades.length === 0) {
                            alert('Please select at least one BUY trade to reconcile.');
                            return false;
                        }
                        
                        return confirm(`This will mark ${reconcileTrades.length} BUY trades as reconciled. Continue?`);
                    }

                    // Auto-expand groups with critical risk or high risk
                    document.addEventListener('DOMContentLoaded', function() {
                        document.querySelectorAll('.duplicate-group').forEach(group => {
                            const hasCriticalRisk = group.querySelector('.trade-item[data-risk-level="critical"]');
                            const hasHighRisk = group.querySelector('.trade-item[data-risk-level="high"]');
                            
                            let shouldExpand = hasCriticalRisk !== null; // Always expand if critical risk
                            
                            if (!shouldExpand && hasHighRisk !== null) {
                                shouldExpand = true; // Expand high risk too
                            }
                            
                            if (shouldExpand) {
                                const header = group.querySelector('.group-header');
                                if (header.classList.contains('collapsed')) {
                                    header.click();
                                }
                            }
                        });
                        
                        // Initialize selected state
                        document.querySelectorAll('.trade-checkbox:checked').forEach(cb => {
                            cb.closest('.trade-item').classList.add('selected');
                        });
                    });
                    </script>

                <?php else: ?>
                    <!-- No duplicates found -->
                    <div class="card text-center py-5">
                        <div class="card-body">
                            <div class="display-1 text-success mb-4">
                                <i class="bi bi-check-circle"></i>
                            </div>
                            <h3 class="card-title mb-3">No Duplicate BUY Trades Found!</h3>
                            <p class="card-text text-muted mb-4">
                                Your database is clean. No duplicate BUY trades were detected using the exact matching criteria.
                            </p>
                            <div class="d-flex justify-content-center gap-3">
                                <a href="dashboard.php" class="btn btn-primary">
                                    <i class="bi bi-house me-2"></i>Go to Dashboard
                                </a>
                                <a href="upload_shares.php" class="btn btn-outline-secondary">
                                    <i class="bi bi-upload me-2"></i>Upload New Trades
                                </a>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="card mt-4">
            <div class="card-header">
                <h6 class="mb-0">BUY Trade Risk Detection Logic & Instructions</h6>
            </div>
            <div class="card-body">
                <small class="text-muted"><strong>CRITICAL RISK DETECTION LOGIC (BUY TRADES ONLY):</strong></small>
                <ul class="mb-3">
                    <li><strong>Filter:</strong> Only BUY trades are considered (SELL trades are excluded)</li>
                    <li><strong>Condition:</strong> EXACTLY ONE receipt payment matches the BUY trade consideration amount (+2.4% with ±0.5% tolerance)</li>
                    <li><strong>AND:</strong> There are MULTIPLE duplicate BUY trades with that same consideration amount</li>
                    <li><strong>Meaning:</strong> Client made ONE payment but was recorded with MULTIPLE BUY trades</li>
                    <li><strong>Financial Impact:</strong> Potential overcharging of client by: <code>(Number of duplicate BUY trades - 1) × Trade consideration</code></li>
                    <li><strong>Dates Ignored:</strong> Payment date doesn't matter - only money movement is considered</li>
                    <li><strong>Receipt Column:</strong> Correctly uses <code>receipt_no</code> column from receipts table</li>
                </ul>
                
                <small class="text-muted"><strong>IMMEDIATE ACTIONS FOR CRITICAL RISK:</strong></small>
                <ol class="mb-3">
                    <li><strong>Contact the client</strong> immediately to verify the single payment</li>
                    <li><strong>Check bank statements</strong> for the exact receipt amount</li>
                    <li><strong>Determine which BUY trade is legitimate</strong> (usually the earliest one)</li>
                    <li><strong>Mark all other BUY trades as duplicates</strong></li>
                    <li><strong>Document everything</strong> for audit trail and compliance</li>
                    <li><strong>Consider refund</strong> if client was overcharged for duplicate BUY trades</li>
                </ol>
                
                <small class="text-muted"><strong>WHY ONLY BUY TRADES?</strong></small>
                <ul class="mb-3">
                    <li><strong>BUY trades</strong> require client payments - these need to match receipts</li>
                    <li><strong>SELL trades</strong> generate payments TO clients - different risk profile</li>
                    <li><strong>Financial Risk:</strong> Duplicate BUY trades can lead to client overpayment</li>
                    <li><strong>Regulatory Focus:</strong> Client protection regulations focus on buy-side transactions</li>
                </ul>
                
                <small class="text-muted"><strong>EXPORT FUNCTIONALITY:</strong></small>
                <ul class="mb-3">
                    <li><strong>Export All:</strong> Complete report of all duplicate BUY trades with risk assessment</li>
                    <li><strong>Export High Risk Only:</strong> Focused report on critical and high-risk BUY trade cases only</li>
                    <li><strong>Export Includes:</strong> Receipt numbers, amounts, risk levels, and recommended actions</li>
                    <li><strong>Use High Risk Export for:</strong> Investigations, audits, management reports, compliance documentation</li>
                </ul>
                
                <small class="text-muted"><strong>COLOR CODING SYSTEM:</strong></small>
                <ul class="mb-0">
                    <li><span class="badge bg-danger">Red</span>: <strong>CRITICAL RISK</strong> - 1 payment for multiple BUY trades - Requires immediate action</li>
                    <li><span class="badge bg-warning text-dark">Yellow</span>: <strong>HIGH RISK</strong> - Missing receipts for BUY trades</li>
                    <li><span class="badge bg-secondary">Gray</span>: <strong>MEDIUM RISK</strong> - Normal duplicate BUY trades without payment issues</li>
                    <li><span class="badge bg-info">Blue</span>: Earliest BUY trade in group (recommended to keep)</li>
                </ul>
                
                <hr>
                <small class="text-danger">
                    <i class="bi bi-exclamation-octagon me-1"></i>
                    <strong>LEGAL & COMPLIANCE WARNING:</strong> Critical risk BUY trade cases may involve regulatory violations, client overcharging, and potential legal liability. These MUST be resolved immediately and documented thoroughly. Failure to address these issues can result in financial penalties, loss of license, and legal action.
                </small>
            </div>
        </div>
    </div>
</div>

<style>
/* ALL CSS STYLES REMAIN THE SAME AS BEFORE */
body {
    background-color: #f8f9fa;
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
}
.page-header {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    padding: 20px 0;
    margin-bottom: 20px;
    border-radius: 10px;
}
.stats-card {
    background: white;
    border-radius: 10px;
    padding: 15px;
    margin-bottom: 15px;
    box-shadow: 0 3px 10px rgba(0,0,0,0.05);
    border-left: 4px solid;
}
.danger-stat { border-left-color: #dc3545; }
.warning-stat { border-left-color: #ffc107; }
.info-stat { border-left-color: #17a2b8; }
.success-stat { border-left-color: #28a745; }
.duplicate-group {
    background: white;
    border-radius: 10px;
    margin-bottom: 15px;
    box-shadow: 0 3px 10px rgba(0,0,0,0.05);
    border: 1px solid #e9ecef;
    overflow: hidden;
}
.duplicate-group.critical-highlight {
    border: 3px solid #dc3545 !important;
    box-shadow: 0 0 20px rgba(220, 53, 69, 0.4);
    background: linear-gradient(to right, rgba(255, 255, 255, 0.95), rgba(255, 230, 230, 0.9));
    animation: pulse-critical 2s infinite;
}
@keyframes pulse-critical {
    0% { box-shadow: 0 0 0 0 rgba(220, 53, 69, 0.4); }
    70% { box-shadow: 0 0 0 10px rgba(220, 53, 69, 0); }
    100% { box-shadow: 0 0 0 0 rgba(220, 53, 69, 0); }
}
.duplicate-group.danger-highlight {
    border: 2px solid #ffc107 !important;
    box-shadow: 0 0 15px rgba(255, 193, 7, 0.2);
}
.group-header {
    background: linear-gradient(135deg, #f8d7da 0%, #fff3cd 100%);
    padding: 12px 15px;
    border-bottom: 2px solid #ffc107;
    cursor: pointer;
    transition: all 0.3s ease;
}
.group-header:hover {
    background: linear-gradient(135deg, #f5c6cb 0%, #ffeaa7 100%);
}
.group-header.collapsed {
    border-bottom: none;
}
.trade-item {
    padding: 10px 15px;
    border-bottom: 1px solid #f1f1f1;
    transition: all 0.2s ease;
}
.trade-item:hover {
    background-color: #f8f9fa;
}
.trade-item.selected {
    background-color: #e8f5e8;
    border-left: 4px solid #28a745;
}
.critical-risk-row {
    background-color: rgba(220, 53, 69, 0.1) !important;
    border-left: 4px solid #dc3545 !important;
    animation: subtle-pulse 3s infinite;
}
.critical-risk-row:hover {
    background-color: rgba(220, 53, 69, 0.2) !important;
}
.critical-risk-row.selected {
    background-color: rgba(40, 167, 69, 0.2) !important;
    border-left: 4px solid #28a745 !important;
}
@keyframes subtle-pulse {
    0% { background-color: rgba(220, 53, 69, 0.1); }
    50% { background-color: rgba(220, 53, 69, 0.15); }
    100% { background-color: rgba(220, 53, 69, 0.1); }
}
.high-risk-row {
    background-color: rgba(255, 193, 7, 0.08) !important;
    border-left: 3px solid #ffc107 !important;
}
.high-risk-row:hover {
    background-color: rgba(255, 193, 7, 0.15) !important;
}
.high-risk-row.selected {
    background-color: rgba(40, 167, 69, 0.15) !important;
    border-left: 4px solid #28a745 !important;
}
.checkbox-container {
    display: flex;
    align-items: center;
    justify-content: center;
    padding-right: 15px;
}
.risk-badge {
    font-size: 0.8rem;
    padding: 6px 12px;
    border-radius: 12px;
    font-weight: bold;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}
.time-badge {
    font-size: 0.7rem;
    padding: 2px 6px;
    border-radius: 8px;
    background-color: #e9ecef;
    color: #495057;
}
.action-buttons {
    background: white;
    padding: 20px;
    border-radius: 10px;
    box-shadow: 0 3px 10px rgba(0,0,0,0.05);
    border: 1px solid #e9ecef;
}
.form-check-input:checked {
    background-color: #28a745;
    border-color: #28a745;
}
.collapse-icon {
    transition: transform 0.3s ease;
}
.collapsed .collapse-icon {
    transform: rotate(-90deg);
}
.table-info {
    background-color: rgba(13, 110, 253, 0.05);
}
.badge.bg-danger {
    background-color: #dc3545 !important;
    font-weight: bold;
}
.badge.bg-warning {
    background-color: #ffc107 !important;
    color: #000 !important;
}
.text-danger {
    color: #dc3545 !important;
}
.text-warning {
    color: #ffc107 !important;
}
.alert-danger {
    background-color: rgba(220, 53, 69, 0.1);
    border-color: rgba(220, 53, 69, 0.3);
    border-left: 4px solid #dc3545;
}
.alert-warning {
    background-color: rgba(255, 193, 7, 0.1);
    border-color: rgba(255, 193, 7, 0.3);
    border-left: 4px solid #ffc107;
}
</style>

<?php include '../includes/footer.php'; ?>