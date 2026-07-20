<?php
// Disable time limit for large uploads
set_time_limit(0);

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', '../logs/upload_bonds_errors.log');

// Check if required files exist
if (!file_exists('../config/config.php')) {
    die('config.php not found');
}
if (!file_exists('../auth/auth_middleware.php')) {
    die('auth_middleware.php not found');
}

require_once '../config/config.php';
require_once '../config/account_mapping.php';
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

$success_message = '';
$error_message = '';
$preview_data = [];
$has_errors = false;

$processed = 0;
$financial_entries_created = 0;
$custodian_trades_processed = 0;
$custodian_trades_recorded = 0;
$company_investments_recorded = 0;
$regulatory_assignments_created = 0;
$bonds_auto_created = 0;
$duplicates_skipped = 0;
$duplicate_references = [];
$client_trades_skipped = 0; // Track skipped client trades

// Get company details
function getCompanyDetails($db) {
    try {
        $stmt = $db->prepare("SELECT company_code, name as company_name FROM companies WHERE is_active = 1 LIMIT 1");
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$result) {
            return ['company_code' => 'B13/C', 'company_name' => 'Neovam Technologies LTD'];
        }
        
        return [
            'company_code' => $result['company_code'],
            'company_name' => $result['company_name']
        ];
    } catch (Exception $e) {
        return ['company_code' => 'B13/C', 'company_name' => 'Neovam Technologies LTD'];
    }
}

$company_details = getCompanyDetails($db);
$company_code = $company_details['company_code'];
$company_name = $company_details['company_name'];

// Helper functions
function safe_number_format($value, $decimals = 2) {
    if ($value === '' || $value === null) {
        return '0.00';
    }
    $numeric_value = is_numeric($value) ? $value : 0;
    return number_format((float)$numeric_value, $decimals);
}

function safe_int_format($value) {
    if ($value === '' || $value === null) {
        return '0';
    }
    $numeric_value = is_numeric($value) ? $value : 0;
    return number_format((int)$numeric_value);
}

function getAccountIdByCode($db, $account_code) {
    try {
        $stmt = $db->prepare("SELECT id, account_code, account_name FROM chart_of_accounts WHERE account_code = ? AND is_active = 1");
        $stmt->execute([$account_code]);
        $account = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$account) {
            error_log("Account not found for code: $account_code");
            return null;
        }
        
        return $account['id'];
        
    } catch (Exception $e) {
        error_log("Error getting account ID for code $account_code: " . $e->getMessage());
        return null;
    }
}

// ==================== FILE VALIDATION FUNCTIONS ====================

// Validate uploaded file
function validateUploadedFile($file) {
    $errors = [];
    $max_size = 5 * 1024 * 1024; // 5MB
    
    if ($file['size'] > $max_size) {
        $errors[] = "File size exceeds 5MB limit";
    }
    
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $errors[] = "File upload error: " . $file['error'];
    }
    
    return $errors;
}

// CSV parsing with AUTO-DETECTION of delimiter (TAB or COMMA)
function parseCSVSecurely($file_path) {
    $rows = [];
    
    if (!file_exists($file_path)) {
        throw new Exception("File not found: $file_path");
    }
    
    if (($handle = fopen($file_path, "r")) !== FALSE) {
        try {
            // Read first line to detect delimiter
            $first_line = fgets($handle);
            rewind($handle);
            
            $delimiter = ',';
            if (strpos($first_line, "\t") !== false) {
                $delimiter = "\t";
                error_log("Detected TAB delimiter in CSV file");
            } elseif (strpos($first_line, ';') !== false) {
                $delimiter = ';';
                error_log("Detected SEMICOLON delimiter in CSV file");
            } else {
                error_log("Using COMMA delimiter in CSV file");
            }
            
            // Read header row with detected delimiter
            $header = fgetcsv($handle, 0, $delimiter, '"', '\\');
            if ($header === FALSE) {
                throw new Exception("Could not read CSV header");
            }
            
            // Trim whitespace from header names
            $header = array_map('trim', $header);
            error_log("CSV Headers found: " . implode(' | ', $header));
            
            $line_number = 1;
            while (($data = fgetcsv($handle, 0, $delimiter, '"', '\\')) !== FALSE) {
                $line_number++;
                
                // Skip empty rows
                if ($data === [null] || $data === [] || (count($data) === 1 && trim($data[0]) === '')) {
                    continue;
                }
                
                // Trim whitespace from all data fields
                $data = array_map('trim', $data);
                
                // Skip rows where all fields are empty after trimming
                $non_empty_count = count(array_filter($data, function($value) {
                    return $value !== '';
                }));
                if ($non_empty_count === 0) {
                    continue;
                }
                
                // Ensure header and data have same number of columns
                if (count($header) !== count($data)) {
                    error_log("CSV line $line_number: Column count mismatch. Header: " . count($header) . ", Data: " . count($data));
                    
                    if (count($data) < count($header)) {
                        $data = array_pad($data, count($header), '');
                    } else {
                        $data = array_slice($data, 0, count($header));
                    }
                }
                
                $rows[] = array_combine($header, $data);
            }
        } finally {
            fclose($handle);
        }
    } else {
        throw new Exception("Could not open file: $file_path");
    }
    
    return $rows;
}

// Map CSV row to database with PROPER date conversion - supports YYYY/MM/DD, MM/DD/YYYY, YYYY-MM-DD
function mapCSVRowToDatabaseBond($row) {
    // Trim all values from the row before processing
    $trimmed_row = [];
    foreach ($row as $key => $value) {
        $trimmed_row[trim($key)] = is_string($value) ? trim($value) : $value;
    }
    
    // DEBUG: Log available columns
    error_log("Available columns in bond row: " . implode(', ', array_keys($trimmed_row)));
    
    // Convert Trade Date - supports YYYY/MM/DD, MM/DD/YYYY, YYYY-MM-DD formats
    $trade_date = '';
    if (!empty($trimmed_row['Trade Date'])) {
        $date_str = trim($trimmed_row['Trade Date']);
        
        if (preg_match('/^(\d{4})\/(\d{1,2})\/(\d{1,2})$/', $date_str, $matches)) {
            $year = $matches[1];
            $month = str_pad($matches[2], 2, '0', STR_PAD_LEFT);
            $day = str_pad($matches[3], 2, '0', STR_PAD_LEFT);
            $trade_date = $year . '-' . $month . '-' . $day;
            error_log("Converted Bond Trade Date (YYYY/MM/DD): {$date_str} -> {$trade_date}");
        }
        elseif (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $date_str, $matches)) {
            $month = str_pad($matches[1], 2, '0', STR_PAD_LEFT);
            $day = str_pad($matches[2], 2, '0', STR_PAD_LEFT);
            $year = $matches[3];
            $trade_date = $year . '-' . $month . '-' . $day;
            error_log("Converted Bond Trade Date (MM/DD/YYYY): {$date_str} -> {$trade_date}");
        } 
        elseif (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_str)) {
            $trade_date = $date_str;
            error_log("Bond Trade Date already in YYYY-MM-DD: {$trade_date}");
        } 
        elseif (preg_match('/^\d{8}$/', $date_str)) {
            $trade_date = substr($date_str, 0, 4) . '-' . substr($date_str, 4, 2) . '-' . substr($date_str, 6, 2);
            error_log("Converted Bond Trade Date (YYYYMMDD): {$date_str} -> {$trade_date}");
        } else {
            error_log("WARNING: Unrecognized Bond Trade Date format: {$date_str}");
        }
    } else {
        error_log("WARNING: No Bond Trade Date column found or value is empty! Using today's date as fallback.");
    }
    
    // Convert Settlement Date - supports YYYY/MM/DD, MM/DD/YYYY, YYYY-MM-DD formats
    $settlement_date = '';
    if (!empty($trimmed_row['Settlement Date'])) {
        $date_str = trim($trimmed_row['Settlement Date']);
        
        if (preg_match('/^(\d{4})\/(\d{1,2})\/(\d{1,2})$/', $date_str, $matches)) {
            $year = $matches[1];
            $month = str_pad($matches[2], 2, '0', STR_PAD_LEFT);
            $day = str_pad($matches[3], 2, '0', STR_PAD_LEFT);
            $settlement_date = $year . '-' . $month . '-' . $day;
            error_log("Converted Bond Settlement Date (YYYY/MM/DD): {$date_str} -> {$settlement_date}");
        }
        elseif (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $date_str, $matches)) {
            $month = str_pad($matches[1], 2, '0', STR_PAD_LEFT);
            $day = str_pad($matches[2], 2, '0', STR_PAD_LEFT);
            $year = $matches[3];
            $settlement_date = $year . '-' . $month . '-' . $day;
            error_log("Converted Bond Settlement Date (MM/DD/YYYY): {$date_str} -> {$settlement_date}");
        } 
        elseif (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_str)) {
            $settlement_date = $date_str;
            error_log("Bond Settlement Date already in YYYY-MM-DD: {$settlement_date}");
        } 
        elseif (preg_match('/^\d{8}$/', $date_str)) {
            $settlement_date = substr($date_str, 0, 4) . '-' . substr($date_str, 4, 2) . '-' . substr($date_str, 6, 2);
            error_log("Converted Bond Settlement Date (YYYYMMDD): {$date_str} -> {$settlement_date}");
        } else {
            error_log("WARNING: Unrecognized Bond Settlement Date format: {$date_str}");
        }
    } else {
        error_log("WARNING: No Bond Settlement Date column found or value is empty! Using T+2 as fallback.");
    }
    
    // Get client name from appropriate column
    $client_name = $trimmed_row['Name'] ?? 
                   $trimmed_row['Client Name'] ?? 
                   $trimmed_row['Main Principal'] ?? 
                   $trimmed_row['Principal'] ?? 
                   $trimmed_row['Trader'] ?? '';
    
    // Get CSD account from appropriate column
    $client_cds = $trimmed_row['CSD Account'] ?? 
                  $trimmed_row['CDS Account'] ?? 
                  $trimmed_row['CSD_Account'] ?? '';
    
    // Get SCA code
    $sca_code = $trimmed_row['SCA Code'] ?? 
                $trimmed_row['SCA_Code'] ?? 
                $trimmed_row['SCA'] ?? '';
    
    // Get trade side
    $trade_side = $trimmed_row['Buy\\Sell'] ?? 
                  $trimmed_row['Buy/Sell'] ?? 
                  $trimmed_row['Buy_Sell'] ?? 
                  $trimmed_row['Trade Side'] ?? '';
    
    // Get security ID
    $security_id = $trimmed_row['Security'] ?? 
                   $trimmed_row['Asset'] ?? 
                   $trimmed_row['Instrument'] ?? '';
    
    // Get Exchange Reference
    $exchange_reference = $trimmed_row['Exchange Reference'] ?? '';
    
    // =====================================================
    // NEW: Get Additional Reference
    // This column can contain: a number, empty, or "MTP"
    // =====================================================
    $additional_reference = $trimmed_row['Additional Reference'] ?? '';
    
    error_log("Mapped bond data - Security: {$security_id}, Client: {$client_name}, CDS: {$client_cds}, Trade Date: {$trade_date}, Exchange Ref: {$exchange_reference}, Additional Ref: {$additional_reference}");
    
    return [
        'security_id' => $security_id,
        'bond_name' => $security_id ?: 'Unknown Bond',
        'client_name' => $client_name,
        'client_cds' => $client_cds,
        'trade_side' => strtolower(trim($trade_side)),
        'quantity' => $trimmed_row['Quantity'] ?? 0,
        'price' => $trimmed_row['Price'] ?? 0,
        'sca_code' => $sca_code,
        'trade_date' => $trade_date ?: date('Y-m-d'),
        'settlement_date' => $settlement_date ?: date('Y-m-d', strtotime('+2 days')),
        'consideration' => $trimmed_row['Consideration'] ?? 0,
        'counterparty_name' => $trimmed_row['Counterparty Name'] ?? $trimmed_row['Counterparty'] ?? '',
        'counterparty_cds' => $trimmed_row['Counterparty CSD Account'] ?? '',
        'capacity' => $trimmed_row['Capacity'] ?? 'principal',
        'broker_name' => $trimmed_row['Broker'] ?? '',
        'counterparty_broker' => $trimmed_row['Counterparty'] ?? '',
        'exchange_reference' => $exchange_reference,
        'additional_reference' => $additional_reference, // NEW: Additional Reference
        'origin' => $trimmed_row['Origin'] ?? '',
        'time_executed' => $trimmed_row['Time'] ?? '',
        'asset_class' => strtolower(trim($trimmed_row['Asset Class'] ?? '')),
        'isin' => $trimmed_row['ISIN'] ?? ''
    ];
}

// Generate unique T+5 reference for bonds
function generateBondTradeReference($db) {
    $characters = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $max_attempts = 100;
    $attempt = 0;
    
    while ($attempt < $max_attempts) {
        $reference = 'T';
        for ($i = 0; $i < 5; $i++) {
            $reference .= $characters[random_int(0, strlen($characters) - 1)];
        }
        
        try {
            $stmt = $db->prepare("SELECT COUNT(*) as count FROM trades WHERE trade_reference = ?");
            $stmt->execute([$reference]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($result['count'] == 0) {
                $stmt2 = $db->prepare("SELECT COUNT(*) as count FROM custodians_trades WHERE trade_reference = ?");
                $stmt2->execute([$reference]);
                $result2 = $stmt2->fetch(PDO::FETCH_ASSOC);
                
                if ($result2['count'] == 0) {
                    error_log("Generated unique bond trade reference: {$reference}");
                    return $reference;
                }
            }
        } catch (Exception $e) {
            error_log("Error checking trade reference uniqueness: " . $e->getMessage());
        }
        
        $attempt++;
    }
    
    $fallback = 'T' . date('YmdHis') . mt_rand(100, 999);
    error_log("Using fallback bond trade reference: {$fallback}");
    return $fallback;
}

// Get trade reference for bonds
function getBondTradeReference($db, $exchange_reference) {
    // If Exchange Reference exists, use it
    if (!empty($exchange_reference)) {
        // Check if it already exists in the database
        $stmt = $db->prepare("SELECT COUNT(*) as count FROM trades WHERE trade_reference = ?");
        $stmt->execute([$exchange_reference]);
        $exists_in_trades = $stmt->fetch()['count'] > 0;
        
        $stmt = $db->prepare("SELECT COUNT(*) as count FROM etf_trades WHERE trade_reference = ?");
        $stmt->execute([$exchange_reference]);
        $exists_in_etf = $stmt->fetch()['count'] > 0;
        
        $stmt = $db->prepare("SELECT COUNT(*) as count FROM custodians_trades WHERE trade_reference = ?");
        $stmt->execute([$exchange_reference]);
        $exists_in_custodians = $stmt->fetch()['count'] > 0;
        
        if (!$exists_in_trades && !$exists_in_etf && !$exists_in_custodians) {
            error_log("Using Exchange Reference as bond trade reference: {$exchange_reference}");
            return $exchange_reference;
        } else {
            error_log("Exchange Reference '{$exchange_reference}' already exists. Generating new bond reference.");
        }
    }
    
    // If no Exchange Reference or it already exists, generate a new T+5 reference
    $new_reference = generateBondTradeReference($db);
    error_log("Generated new T+5 bond reference: {$new_reference}");
    return $new_reference;
}

// Check for duplicate trade using Exchange Reference
function isDuplicateTradeBond($db, $exchange_reference) {
    try {
        if (empty($exchange_reference)) {
            return false;
        }
        
        // Check in trades table
        $stmt = $db->prepare("SELECT COUNT(*) as count FROM trades WHERE exchange_reference = ?");
        $stmt->execute([$exchange_reference]);
        $count_trades = $stmt->fetch()['count'];
        
        // Check in etf_trades table
        $stmt = $db->prepare("SELECT COUNT(*) as count FROM etf_trades WHERE exchange_reference = ?");
        $stmt->execute([$exchange_reference]);
        $count_etf = $stmt->fetch()['count'];
        
        // Check in custodians_trades table
        $stmt = $db->prepare("SELECT COUNT(*) as count FROM custodians_trades WHERE exchange_reference = ?");
        $stmt->execute([$exchange_reference]);
        $count_custodians = $stmt->fetch()['count'];
        
        $total_count = $count_trades + $count_etf + $count_custodians;
        
        if ($total_count > 0) {
            error_log("Duplicate bond trade detected by Exchange Reference: {$exchange_reference}");
            return true;
        }
        
        return false;
        
    } catch (Exception $e) {
        error_log("Error checking duplicate bond trade: " . $e->getMessage());
        return false;
    }
}

// Check and insert client if not exists
function checkAndInsertClient($db, $cds_account, $client_name, $created_by = 'system') {
    try {
        $stmt = $db->prepare("SELECT id FROM clients WHERE cds_account = ?");
        $stmt->execute([$cds_account]);
        $client = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($client) {
            return $client['id'];
        }
        
        $client_code = 'CL' . substr($cds_account, -6) . date('Ymd');
        
        $stmt = $db->prepare("
            INSERT INTO clients 
            (cds_account, client_name, client_type, client_code, status, fee_type, created_by, created_at, updated_at)
            VALUES (?, ?, 'individual', ?, 'active', 'normal', ?, NOW(), NOW())
        ");
        
        $stmt->execute([
            $cds_account,
            $client_name,
            $client_code,
            $created_by
        ]);
        
        $client_id = $db->lastInsertId();
        error_log("New client inserted for bond: {$client_name} (CDS: {$cds_account}) with ID: {$client_id}");
        
        return $client_id;
        
    } catch (Exception $e) {
        error_log("Error checking/inserting client for bond: " . $e->getMessage());
        return null;
    }
}

// Check and insert bond if not exists
function checkAndInsertBond($db, $security_id, $security_name, $trade_date, $created_by = 'system') {
    try {
        $stmt = $db->prepare("SELECT id, bond_name FROM bonds WHERE security_id = ?");
        $stmt->execute([$security_id]);
        $bond = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($bond) {
            error_log("Bond found in database: {$security_id}");
            return [
                'id' => $bond['id'],
                'bond_name' => $bond['bond_name'],
                'was_created' => false
            ];
        }
        
        // Auto-create bond with default values
        $coupon_rate = 0.0;
        if (preg_match('/(\d+\.\d+)/', $security_id, $matches)) {
            $coupon_rate = (float)$matches[1];
        } elseif (preg_match('/(\d+)%/i', $security_name, $matches)) {
            $coupon_rate = (float)$matches[1];
        }
        
        $issuer = 'BOT';
        if (strpos($security_id, 'SAMIA') !== false) {
            $issuer = 'CRDB BANK PLC';
        } elseif (preg_match('/^[A-Z]+-/i', $security_id)) {
            $issuer = 'Corporate Issuer';
        }
        
        $security_type = 'FXD';
        if (strpos($security_id, 'T') !== false) {
            $security_type = 'Treasury Bond';
        } elseif (strpos(strtoupper($security_id), 'CORP') !== false) {
            $security_type = 'Corporate Bond';
        }
        
        $maturity_years = rand(7, 25);
        $trade_date_obj = new DateTime($trade_date);
        $trade_date_obj->modify("+{$maturity_years} years");
        $maturity_date = $trade_date_obj->format('Y-m-d');
        
        $issue_years_before = rand(1, 5);
        $trade_date_obj = new DateTime($trade_date);
        $trade_date_obj->modify("-{$issue_years_before} years");
        $issue_date = $trade_date_obj->format('Y-m-d');
        
        $term_years = $maturity_years + $issue_years_before;
        
        $isin = '';
        if (preg_match('/TZ\d+/', $security_id, $matches)) {
            $isin = $matches[0];
        } else {
            $isin = 'TZ' . str_pad(mt_rand(10000000, 99999999), 8, '0', STR_PAD_LEFT);
        }
        
        $stmt = $db->prepare("
            INSERT INTO bonds 
            (security_id, bond_name, issuer, coupon_rate, issue_date, maturity_date, term_years,
             security_type, isin, economic_sector, payment_frequency, currency, status, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        
        $economic_sector = ($issuer === 'BOT') ? 'G' : 'Corporate';
        $payment_frequency = 'Semi-Annual';
        $currency = 'TZS';
        $status = 'active';
        
        $stmt->execute([
            $security_id,
            $security_name,
            $issuer,
            $coupon_rate,
            $issue_date,
            $maturity_date,
            $term_years,
            $security_type,
            $isin,
            $economic_sector,
            $payment_frequency,
            $currency,
            $status
        ]);
        
        $bond_id = $db->lastInsertId();
        error_log("New bond auto-created: {$security_id} - {$security_name} - Coupon: {$coupon_rate}% - Maturity: {$maturity_date}");
        
        return [
            'id' => $bond_id,
            'bond_name' => $security_name,
            'was_created' => true
        ];
        
    } catch (Exception $e) {
        error_log("Error checking/inserting bond {$security_id}: " . $e->getMessage());
        return null;
    }
}

// Check if trade is through custodian
function isCustodianTrade($sca_code, $company_code) {
    return !empty($sca_code) && $sca_code !== $company_code;
}

// Simple general ledger entry function
function recordGeneralLedgerEntry($db, $transaction_date, $account_id, $debit, $credit, $description, $reference_no, $reference_type = 'trade') {
    try {
        if (!is_numeric($account_id) || $account_id <= 0) {
            error_log("Invalid account ID: $account_id for GL entry");
            return false;
        }
        
        $debit = max(0, (float)$debit);
        $credit = max(0, (float)$credit);
        
        if ($debit == 0 && $credit == 0) {
            return true;
        }
        
        $created_by = isset($_SESSION['username']) ? $_SESSION['username'] : 'system';
        
        $stmt = $db->prepare("SELECT account_code, account_name FROM chart_of_accounts WHERE id = ?");
        $stmt->execute([$account_id]);
        $account = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$account) {
            error_log("Account not found for ID: $account_id");
            return false;
        }
        
        $stmt = $db->prepare("
            INSERT INTO general_ledger 
            (transaction_date, account_id, account_code, account_name, debit_amount, credit_amount, 
             description, reference_no, reference_type, created_at, created_by) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)
        ");
        
        $result = $stmt->execute([
            $transaction_date, 
            $account_id,
            $account['account_code'],
            $account['account_name'],
            round($debit, 2), 
            round($credit, 2), 
            substr(trim($description), 0, 255), 
            substr(trim($reference_no), 0, 100), 
            $reference_type,
            $created_by
        ]);
        
        if ($result) {
            error_log("GL entry created: {$description} - Debit: {$debit} Credit: {$credit}");
            return $db->lastInsertId();
        }
        
        return false;
        
    } catch (Exception $e) {
        error_log("Error recording GL entry: " . $e->getMessage());
        return false;
    }
}

// Calculate bond fees
function calculateBondFees($quantity, $price, $consideration) {
    try {
        $fees = [];
        $face_value = $quantity;
        
        // Brokerage: 0.063132% on first 100M, 0.035% on excess
        $brokerage_first_100m = min($face_value, 100000000) * (0.063132 / 100);
        $brokerage_excess = max($face_value - 100000000, 0) * (0.035 / 100);
        $fees['brokerage'] = $brokerage_first_100m + $brokerage_excess;
        
        // VAT: 18% on brokerage
        $fees['vat'] = $fees['brokerage'] * 0.18;
        
        // CMSA: 0.0100% on consideration
        $fees['cmsa'] = $consideration * (0.0100 / 100);
        
        // CSD: 0.0118% on face value
        $fees['csd'] = $face_value * (0.0118 / 100);
        
        // DSE: 0.02006% on face value
        $fees['dse'] = $face_value * (0.02006 / 100);
        
        // Calculate total
        $fees['total'] = $fees['brokerage'] + $fees['vat'] + $fees['cmsa'] + $fees['dse'] + $fees['csd'];
        
        return $fees;
        
    } catch (Exception $e) {
        error_log("Error calculating bond fees: " . $e->getMessage());
        return [
            'brokerage' => 0, 'vat' => 0, 'cmsa' => 0, 'csd' => 0, 'dse' => 0, 'total' => 0
        ];
    }
}

// =====================================================
// Create bond accounting entries - NO CASH AT BANK
// Uses: 411 (Brokerage Income), 213 (VAT), 2111 (CMSA), 
// 2112 (DSE), 2113 (CSDR)
// =====================================================
function createBondAccountingEntries($db, $trade_reference, $consideration, $fees, $trade_side, $client_name, $company_name, $trade_date, $is_custodian_trade = false) {
    try {
        $entries_created = 0;
        
        // Get account IDs
        $brokerage_income = getAccountIdByCode($db, BROKERAGE_COMMISSION_INCOME_CODE);  // 411
        $vat_payable = getAccountIdByCode($db, VAT_PAYABLE_CODE);                        // 213
        $cmsa_payable = getAccountIdByCode($db, CMSA_PAYABLE_CODE);                      // 2111
        $dse_payable = getAccountIdByCode($db, DSE_PAYABLE_CODE);                        // 2112
        $csdr_payable = getAccountIdByCode($db, CSDR_PAYABLE_CODE);                      // 2113
        
        $brokerage_fee = $fees['brokerage'] ?? 0;
        $vat_fee = $fees['vat'] ?? 0;
        $cmsa_fee = $fees['cmsa'] ?? 0;
        $dse_fee = $fees['dse'] ?? 0;
        $csd_fee = $fees['csd'] ?? 0;
        
        // CSDR fee uses the csd value
        $csdr_fee = $fees['csdr'] ?? $csd_fee;
        
        // 1. Credit Brokerage Income (411)
        if ($brokerage_fee > 0 && !isGLDuplicateEntry($db, $trade_reference, BROKERAGE_COMMISSION_INCOME_CODE, 'Bond brokerage income')) {
            if (recordGeneralLedgerEntry($db, $trade_date, $brokerage_income, 0, $brokerage_fee, 
                "Bond brokerage income - {$trade_reference} - {$client_name}", $trade_reference, 'fee')) {
                $entries_created++;
            }
        }
        
        // 2. Credit VAT Payable (213)
        if ($vat_fee > 0 && !isGLDuplicateEntry($db, $trade_reference, VAT_PAYABLE_CODE, 'VAT on bond brokerage')) {
            if (recordGeneralLedgerEntry($db, $trade_date, $vat_payable, 0, $vat_fee, 
                "VAT on bond brokerage - {$trade_reference}", $trade_reference, 'fee')) {
                $entries_created++;
            }
        }
        
        // 3. Credit CMSA Payable (2111)
        if ($cmsa_fee > 0 && !isGLDuplicateEntry($db, $trade_reference, CMSA_PAYABLE_CODE, 'CMSA fees payable')) {
            if (recordGeneralLedgerEntry($db, $trade_date, $cmsa_payable, 0, $cmsa_fee, 
                "CMSA fees payable - {$trade_reference}", $trade_reference, 'fee')) {
                $entries_created++;
            }
        }
        
        // 4. Credit DSE Payable (2112)
        if ($dse_fee > 0 && !isGLDuplicateEntry($db, $trade_reference, DSE_PAYABLE_CODE, 'DSE fees payable')) {
            if (recordGeneralLedgerEntry($db, $trade_date, $dse_payable, 0, $dse_fee, 
                "DSE fees payable - {$trade_reference}", $trade_reference, 'fee')) {
                $entries_created++;
            }
        }
        
        // 5. Credit CSDR Payable (2113)
        if ($csdr_fee > 0 && !isGLDuplicateEntry($db, $trade_reference, CSDR_PAYABLE_CODE, 'CSDR fees payable')) {
            if (recordGeneralLedgerEntry($db, $trade_date, $csdr_payable, 0, $csdr_fee, 
                "CSDR fees payable - {$trade_reference}", $trade_reference, 'fee')) {
                $entries_created++;
            }
        }
        
        return $entries_created > 0;
        
    } catch (Exception $e) {
        error_log("Error creating bond accounting entries: " . $e->getMessage());
        return false;
    }
}

// =====================================================
// Record Company bond trades to Marketable Securities - Bonds (1152)
// Client trades are SKIPPED - will be entered manually via receipts
// NO CASH AT BANK
// =====================================================
function recordCompanyBondInvestment($db, $trade_reference, $consideration, $trade_side, $trade_date) {
    try {
        $entries_created = 0;
        // Use Marketable Securities - Bonds (1152)
        $investment_account = getAccountIdByCode($db, MARKETABLE_SECURITIES_BONDS_CODE);
        
        if ($consideration <= 0) {
            return true;
        }
        
        if ($trade_side === 'buy') {
            // Buy: Debit Marketable Securities - Bonds (1152)
            if (!isGLDuplicateEntry($db, $trade_reference, MARKETABLE_SECURITIES_BONDS_CODE, 'Marketable Securities - Bond purchase')) {
                if (recordGeneralLedgerEntry($db, $trade_date, $investment_account, $consideration, 0, 
                    "Marketable Securities - Bond purchase - {$trade_reference}", $trade_reference, 'company_investment')) {
                    $entries_created++;
                }
            }
        } else {
            // Sell: Credit Marketable Securities - Bonds (1152)
            if (!isGLDuplicateEntry($db, $trade_reference, MARKETABLE_SECURITIES_BONDS_CODE, 'Marketable Securities - Bond sale')) {
                if (recordGeneralLedgerEntry($db, $trade_date, $investment_account, 0, $consideration, 
                    "Marketable Securities - Bond sale - {$trade_reference}", $trade_reference, 'company_investment')) {
                    $entries_created++;
                }
            }
        }
        
        return $entries_created > 0;
        
    } catch (Exception $e) {
        error_log("Error recording company bond investment: " . $e->getMessage());
        return false;
    }
}

// Record regulatory fee assignment
function recordRegulatoryFeeAssignment($db, $trade_reference, $fees, $client_name, $trade_date, $security_id, $security_name, $consideration, $trade_side, $created_by, $additional_reference = '') {
    try {
        $check_stmt = $db->prepare("SELECT COUNT(*) as count FROM regulatory_fee_assignments WHERE trade_reference = ?");
        $check_stmt->execute([$trade_reference]);
        if ($check_stmt->fetch()['count'] > 0) {
            return true;
        }
        
        $stmt = $db->prepare("
            INSERT INTO regulatory_fee_assignments 
            (trade_reference, client_name, security_id, security_name, trade_date, 
             dse_fee, cmsa_fee, csd_fee, total_fees, consideration, trade_side,
             additional_reference, status, created_by, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?, NOW(), NOW())
        ");
        
        $dse_fee = $fees['dse'] ?? 0;
        $cmsa_fee = $fees['cmsa'] ?? 0;
        $csd_fee = $fees['csd'] ?? 0;
        $total_fees = $dse_fee + $cmsa_fee + $csd_fee;
        
        return $stmt->execute([
            $trade_reference, substr($client_name, 0, 255), substr($security_id, 0, 50),
            substr($security_name, 0, 200), $trade_date, round($dse_fee, 2), round($cmsa_fee, 2),
            round($csd_fee, 2), round($total_fees, 2), round($consideration, 2),
            $trade_side, substr($additional_reference, 0, 100), $created_by
        ]);
        
    } catch (Exception $e) {
        error_log("Error recording regulatory fee assignment: " . $e->getMessage());
        return false;
    }
}

// Record custodian trade
function recordCustodianTrade($db, $trade_data) {
    try {
        $stmt = $db->prepare("
            INSERT INTO custodians_trades 
            (trade_reference, custodian_code, custodian_name, asset_class, security_id, security_name,
             client_cds_account, client_name, trade_side, quantity, price, consideration,
             trade_date, settlement_date, brokerage_fees, other_fees, total_fees, created_at,
             exchange_reference, additional_reference)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, ?)
        ");
        
        return $stmt->execute([
            $trade_data['trade_reference'], $trade_data['custodian_code'], $trade_data['custodian_name'],
            $trade_data['asset_class'], $trade_data['security_id'], $trade_data['security_name'],
            $trade_data['client_cds_account'], $trade_data['client_name'], $trade_data['trade_side'],
            $trade_data['quantity'], $trade_data['price'], $trade_data['consideration'],
            $trade_data['trade_date'], $trade_data['settlement_date'],
            $trade_data['brokerage_fees'], $trade_data['other_fees'], $trade_data['total_fees'],
            $trade_data['exchange_reference'], $trade_data['additional_reference']
        ]);
        
    } catch (Exception $e) {
        error_log("Error recording custodian trade: " . $e->getMessage());
        return false;
    }
}

// Calculate custodian fees
function calculateCustodianFees($fees) {
    $brokerage_fees = ($fees['brokerage'] ?? 0) + ($fees['vat'] ?? 0);
    $other_fees = ($fees['cmsa'] ?? 0) + ($fees['csd'] ?? 0) + ($fees['dse'] ?? 0);
    
    return [
        'brokerage_fees' => round($brokerage_fees, 2),
        'other_fees' => round($other_fees, 2),
        'total_fees' => round($brokerage_fees + $other_fees, 2)
    ];
}

// Filter bond rows
function filterBondRows($rows) {
    $bond_rows = [];
    
    foreach ($rows as $index => $row) {
        $mapped_data = mapCSVRowToDatabaseBond($row);
        $asset_class = strtolower(trim($mapped_data['asset_class'] ?? ''));
        
        error_log("Bond Row {$index}: Asset Class = '{$asset_class}'");
        
        if ($asset_class === 'bond') {
            $bond_rows[] = $row;
            error_log("  -> ACCEPTED as Bond");
        } else {
            error_log("  -> REJECTED (not Bond)");
        }
    }
    
    error_log("Total rows: " . count($rows) . ", Bond rows: " . count($bond_rows));
    return $bond_rows;
}

// Validate bond data
function validateBondData($row, $line_num, $db, $company_code) {
    $errors = [];
    $mapped_data = mapCSVRowToDatabaseBond($row);
    
    $asset_class = strtolower(trim($mapped_data['asset_class'] ?? ''));
    if ($asset_class !== 'bond') {
        $errors[] = "Only Bond asset class supported. Found: '{$asset_class}'";
        return $errors;
    }
    
    $security_id = $mapped_data['security_id'];
    if (empty($security_id) || trim($security_id) === '') {
        $errors[] = "Security ID is required";
    }
    
    $required_fields = [
        'security_id' => 'Security', 
        'client_name' => 'Name', 
        'client_cds' => 'CSD Account',
        'trade_side' => 'Buy\\Sell', 
        'quantity' => 'Quantity', 
        'price' => 'Price', 
        'sca_code' => 'SCA Code',
        'exchange_reference' => 'Exchange Reference'
    ];
    
    foreach ($required_fields as $field => $column_name) {
        $value = $mapped_data[$field];
        if (empty($value) || (is_string($value) && trim($value) === '')) {
            $errors[] = "{$column_name} is required";
        }
    }
    
    $trade_side = strtolower(trim($mapped_data['trade_side']));
    if (!in_array($trade_side, ['buy', 'sell'])) {
        $errors[] = "Buy\\Sell must be either 'Buy' or 'Sell'";
    }
    
    // Check for duplicate using Exchange Reference
    $exchange_reference = $mapped_data['exchange_reference'];
    if (!empty($exchange_reference)) {
        if (isDuplicateTradeBond($db, $exchange_reference)) {
            $errors[] = "DUPLICATE: Exchange Reference '{$exchange_reference}' already exists in the system. This trade will be skipped.";
        }
    }
    
    return $errors;
}

function processBondDataInChunks($bond_rows) {
    global $db, $company_code, $company_name;
    $preview_data = [];
    $line_number = 2;
    
    foreach ($bond_rows as $row) {
        $mapped_data = mapCSVRowToDatabaseBond($row);
        $is_company_trade = (trim(strtolower($mapped_data['client_name'])) === trim(strtolower($company_name)));
        $is_custodian_trade = isCustodianTrade($mapped_data['sca_code'], $company_code);
        $asset_class = strtolower(trim($mapped_data['asset_class'] ?? ''));
        
        // Use Exchange Reference as the trade reference
        $exchange_reference = $mapped_data['exchange_reference'];
        $trade_reference = getBondTradeReference($db, $exchange_reference);
        
        $preview_data[] = [
            'line_number' => $line_number++,
            'data' => $row,
            'mapped_data' => $mapped_data,
            'trade_reference' => $trade_reference,
            'has_errors' => false,
            'errors' => [],
            'is_company_trade' => $is_company_trade,
            'is_custodian_trade' => $is_custodian_trade,
            'is_duplicate' => false
        ];
    }
    
    return $preview_data;
}

// ==================== MAIN PROCESSING LOGIC ====================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_FILES['bond_file']) && $_FILES['bond_file']['error'] === UPLOAD_ERR_OK) {
        $file_errors = validateUploadedFile($_FILES['bond_file']);
        if (!empty($file_errors)) {
            $error_message = "File validation failed: " . implode(', ', $file_errors);
        } else {
            $file = $_FILES['bond_file'];
            try {
                $rows = parseCSVSecurely($file['tmp_name']);
                if (empty($rows)) {
                    $error_message = "No valid data found in the uploaded file.";
                } else {
                    error_log("=== BOND CSV DEBUG INFO ===");
                    error_log("Total rows in CSV: " . count($rows));
                    
                    $bond_rows = filterBondRows($rows);
                    
                    if (empty($bond_rows)) {
                        $error_message = "No bond data found. Ensure 'Asset Class' is 'Bond'.";
                    } else {
                        $preview_data = processBondDataInChunks($bond_rows);
                        $has_errors = false;
                        $errors = [];
                        $error_count = 0;
                        $valid_count = 0;
                        $duplicate_count = 0;
                        $duplicate_refs = [];
                        
                        foreach ($preview_data as &$preview_row) {
                            $validation_errors = validateBondData($preview_row['data'], $preview_row['line_number'], $db, $company_code);
                            $preview_row['has_errors'] = !empty($validation_errors);
                            $preview_row['errors'] = $validation_errors;
                            
                            // Check if this is a duplicate error
                            $is_dup = false;
                            $dup_ref = '';
                            foreach ($validation_errors as $err) {
                                if (strpos($err, 'DUPLICATE:') !== false) {
                                    $is_dup = true;
                                    preg_match("/Exchange Reference '([^']+)'/", $err, $matches);
                                    $dup_ref = $matches[1] ?? 'Unknown';
                                    break;
                                }
                            }
                            
                            if ($is_dup) {
                                $duplicate_count++;
                                $duplicate_refs[] = $dup_ref;
                                $preview_row['is_duplicate'] = true;
                            }
                            
                            if ($preview_row['has_errors']) {
                                $has_errors = true;
                                $error_count++;
                                foreach ($validation_errors as $error) {
                                    $errors[] = "Line {$preview_row['line_number']}: {$error}";
                                }
                            } else {
                                $valid_count++;
                            }
                        }
                        unset($preview_row);
                        
                        error_log("Bond Validation Results: {$valid_count} valid, {$error_count} errors, {$duplicate_count} duplicates");
                        
                        // If there are ONLY duplicate errors, we should still proceed but warn the user
                        $has_real_errors = false;
                        foreach ($errors as $err) {
                            if (strpos($err, 'DUPLICATE:') === false) {
                                $has_real_errors = true;
                                break;
                            }
                        }
                        
                        if (!$has_real_errors && !empty($preview_data)) {
                            // Only duplicates found - proceed with upload but warn
                            error_log("=== BOND UPLOAD DEBUG ===");
                            error_log("Starting transaction for " . count($preview_data) . " bond trades (with duplicates)");
                            
                            $db->beginTransaction();
                            try {
                                $processed = 0;
                                $financial_entries_created = 0;
                                $custodian_trades_processed = 0;
                                $custodian_trades_recorded = 0;
                                $company_investments_recorded = 0;
                                $regulatory_assignments_created = 0;
                                $bonds_auto_created = 0;
                                $duplicates_skipped = 0;
                                $duplicate_references = [];
                                $client_trades_skipped = 0;
                                
                                foreach ($preview_data as $preview_row) {
                                    // Check if this is a duplicate
                                    if ($preview_row['is_duplicate']) {
                                        $duplicates_skipped++;
                                        $duplicate_references[] = $preview_row['mapped_data']['exchange_reference'];
                                        error_log("Skipping duplicate bond trade: " . $preview_row['mapped_data']['exchange_reference']);
                                        continue;
                                    }
                                    
                                    $mapped_data = $preview_row['mapped_data'];
                                    $trade_reference = $preview_row['trade_reference'];
                                    $exchange_reference = $mapped_data['exchange_reference'];
                                    $additional_reference = $mapped_data['additional_reference'] ?? ''; // NEW: Get Additional Reference
                                    
                                    $security_id = $mapped_data['security_id'];
                                    $trade_date = $mapped_data['trade_date'];
                                    $client_cds = $mapped_data['client_cds'];
                                    $client_name = $mapped_data['client_name'];
                                    $sca_code = $mapped_data['sca_code'];
                                    $is_custodian_trade = isCustodianTrade($sca_code, $company_code);
                                    $is_company_trade = (trim(strtolower($client_name)) === trim(strtolower($company_name)));
                                    $trade_side = $mapped_data['trade_side'];
                                    $quantity = !empty($mapped_data['quantity']) ? (int)$mapped_data['quantity'] : 0;
                                    $price = !empty($mapped_data['price']) ? (float)$mapped_data['price'] : 0;
                                    $consideration = !empty($mapped_data['consideration']) ? (float)$mapped_data['consideration'] : ($quantity * $price);
                                    $settlement_date = $mapped_data['settlement_date'];
                                    
                                    error_log("Bond Trade {$trade_reference}: Exchange Ref: {$exchange_reference}, Additional Ref: {$additional_reference}, Trade Date: {$trade_date}, Settlement Date: {$settlement_date}");
                                    
                                    if (!empty($client_cds) && !empty($client_name)) {
                                        checkAndInsertClient($db, $client_cds, $client_name, $current_user['username'] ?? 'system');
                                    }
                                    
                                    // Check and insert bond
                                    $bond_details = checkAndInsertBond($db, $security_id, $mapped_data['bond_name'], $trade_date, $current_user['username'] ?? 'system');
                                    
                                    if ($bond_details && $bond_details['was_created']) {
                                        $bonds_auto_created++;
                                        error_log("Bond auto-created: {$security_id}");
                                    }
                                    
                                    $bond_name_to_use = $bond_details ? $bond_details['bond_name'] : $security_id;
                                    
                                    // Calculate brokerage fee for commission reporting
                                    $brokerage_fee_type = 'normal';
                                    $final_brokerage_fee_amount = 0.00;
                                    
                                    if ($consideration > 0) {
                                        $fee_calc = calculateBondFees($quantity, $price, $consideration);
                                        $final_brokerage_fee_amount = $fee_calc['brokerage'] ?? 0;
                                        
                                        if (!empty($client_cds)) {
                                            $cf_stmt = $db->prepare("SELECT fee_type, default_brokerage_fee FROM clients WHERE cds_account = ?");
                                            $cf_stmt->execute([$client_cds]);
                                            $client_fee = $cf_stmt->fetch(PDO::FETCH_ASSOC);
                                            if ($client_fee && $client_fee['fee_type'] === 'liberty' && ($client_fee['default_brokerage_fee'] ?: 0) > 0) {
                                                $brokerage_fee_type = 'liberty';
                                                $final_brokerage_fee_amount = $consideration * ((float)$client_fee['default_brokerage_fee'] / 100);
                                            }
                                        }
                                    }
                                    
                                    // Check if trade already exists by exchange reference (double-check)
                                    $check_exchange_stmt = $db->prepare("SELECT COUNT(*) as count FROM trades WHERE exchange_reference = ?");
                                    $check_exchange_stmt->execute([$exchange_reference]);
                                    $trade_exists = $check_exchange_stmt->fetch()['count'] > 0;
                                    
                                    if (!$trade_exists) {
                                        $trade_insert_stmt = $db->prepare("
                                            INSERT INTO trades (
                                                trade_reference, asset_class, security_id, security_name,
                                                client_cds_account, client_name, counterparty_name, counterparty_cds_account,
                                                trade_side, quantity, price, consideration, trade_date, settlement_date,
                                                currency, sca_code, status, uploaded_by, capacity, broker_name, 
                                                counterparty_broker, brokerage_fee_type, final_brokerage_fee,
                                                exchange_reference, additional_reference, time_executed, origin
                                            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                                        ");
                                        
                                        $trade_insert_stmt->execute([
                                            $trade_reference, 'bond', substr($security_id, 0, 50),
                                            substr($bond_name_to_use, 0, 100), substr($client_cds, 0, 50),
                                            substr($client_name, 0, 100), substr($mapped_data['counterparty_name'] ?? 'Unknown', 0, 100),
                                            substr($mapped_data['counterparty_cds'] ?? '', 0, 50), $trade_side, $quantity, $price,
                                            $consideration, $trade_date, $settlement_date, 'TZS', substr($sca_code, 0, 20),
                                            'active', $current_user['id'], $mapped_data['capacity'] ?? 'principal',
                                            substr($mapped_data['broker_name'] ?? '', 0, 100), substr($mapped_data['counterparty_broker'] ?? '', 0, 100),
                                            $brokerage_fee_type, round($final_brokerage_fee_amount, 2),
                                            $exchange_reference, substr($additional_reference, 0, 100),
                                            $mapped_data['time_executed'] ?? null,
                                            $mapped_data['origin'] ?? null
                                        ]);
                                        
                                        error_log("Successfully inserted bond trade: {$trade_reference} - Exchange Ref: {$exchange_reference} - Additional Ref: {$additional_reference} - Trade Date: {$trade_date}");
                                    } else {
                                        error_log("Trade with Exchange Reference '{$exchange_reference}' already exists. Skipping.");
                                        $duplicates_skipped++;
                                        $duplicate_references[] = $exchange_reference;
                                        continue;
                                    }
                                    
                                    // =====================================================
                                    // UPDATED: ONLY Company trades go to Marketable Securities - Bonds (1152)
                                    // Client trades are SKIPPED - will be entered manually via receipts
                                    // =====================================================
                                    if ($consideration > 0) {
                                        if ($is_company_trade) {
                                            // Company trades: record to Marketable Securities - Bonds (1152)
                                            if (recordCompanyBondInvestment($db, $trade_reference, $consideration, $trade_side, $trade_date)) {
                                                $company_investments_recorded++;
                                                error_log("Company bond recorded to Marketable Securities - Bonds (1152): {$trade_reference}");
                                            }
                                        } else {
                                            // Client trades: SKIP - will be entered manually via receipts
                                            $client_trades_skipped++;
                                            error_log("Client bond trade skipped (manual receipt entry needed): {$trade_reference} - {$client_name}");
                                        }
                                    }
                                    
                                    // Fees are still recorded for ALL trades (both company and client)
                                    if ($consideration > 0) {
                                        $fees = calculateBondFees($quantity, $price, $consideration);
                                        
                                        if (recordRegulatoryFeeAssignment($db, $trade_reference, $fees, $client_name, $trade_date, 
                                            $security_id, $bond_name_to_use, $consideration, $trade_side, 
                                            $current_user['username'] ?? 'system', $additional_reference)) {
                                            $regulatory_assignments_created++;
                                        }
                                        
                                        if (createBondAccountingEntries($db, $trade_reference, $consideration, $fees, $trade_side, 
                                            $client_name, $company_name, $trade_date, $is_custodian_trade)) {
                                            $financial_entries_created++;
                                        }
                                        
                                        if ($is_custodian_trade) {
                                            $custodian_trades_processed++;
                                            $stmt = $db->prepare("SELECT custodian_code, custodian_name FROM custodians WHERE custodian_code = ?");
                                            $stmt->execute([$sca_code]);
                                            $custodian = $stmt->fetch(PDO::FETCH_ASSOC);
                                            
                                            if ($custodian) {
                                                $custodian_fees = calculateCustodianFees($fees);
                                                $custodian_trade_data = [
                                                    'trade_reference' => $trade_reference,
                                                    'custodian_code' => substr($custodian['custodian_code'], 0, 50),
                                                    'custodian_name' => substr($custodian['custodian_name'], 0, 255),
                                                    'asset_class' => 'bond', 
                                                    'security_id' => substr($security_id, 0, 100),
                                                    'security_name' => substr($bond_name_to_use, 0, 255),
                                                    'client_cds_account' => substr($client_cds, 0, 100),
                                                    'client_name' => substr($client_name, 0, 255), 
                                                    'trade_side' => $trade_side,
                                                    'quantity' => $quantity, 
                                                    'price' => $price, 
                                                    'consideration' => $consideration,
                                                    'trade_date' => $trade_date, 
                                                    'settlement_date' => $settlement_date,
                                                    'brokerage_fees' => $custodian_fees['brokerage_fees'],
                                                    'other_fees' => $custodian_fees['other_fees'], 
                                                    'total_fees' => $custodian_fees['total_fees'],
                                                    'exchange_reference' => $exchange_reference,
                                                    'additional_reference' => $additional_reference
                                                ];
                                                if (recordCustodianTrade($db, $custodian_trade_data)) $custodian_trades_recorded++;
                                            }
                                        }
                                    }
                                    
                                    $processed++;
                                }
                                
                                $db->commit();
                                
                                if ($processed > 0 || $duplicates_skipped > 0 || $client_trades_skipped > 0) {
                                    $success_message = "Successfully processed {$processed} bond trades";
                                    if ($bonds_auto_created > 0) $success_message .= " <strong>{$bonds_auto_created} bonds auto-created</strong>";
                                    if ($financial_entries_created > 0) $success_message .= ". Created financial entries for {$financial_entries_created} trades";
                                    if ($company_investments_recorded > 0) $success_message .= ". <strong>{$company_investments_recorded} company trades recorded to Marketable Securities - Bonds (1152)</strong>";
                                    if ($regulatory_assignments_created > 0) $success_message .= ". <strong>{$regulatory_assignments_created} regulatory fee assignments created</strong>";
                                    
                                    // Show client trades skipped message
                                    if ($client_trades_skipped > 0) {
                                        $success_message .= "<br><div class='alert alert-info mt-2'><i class='bi bi-info-circle'></i> <strong>{$client_trades_skipped} client bond trades were SKIPPED</strong> (will be entered manually via receipts).";
                                        $success_message .= "<br><small>Only company trades are recorded to Marketable Securities - Bonds (1152). Client trades require manual receipt entry.</small></div>";
                                    }
                                    
                                    if ($duplicates_skipped > 0) {
                                        $success_message .= "<br><div class='alert alert-warning mt-2'><i class='bi bi-exclamation-triangle'></i> <strong>{$duplicates_skipped} duplicate trades were skipped</strong> because their Exchange References already exist in the system.";
                                        if (count($duplicate_references) > 0) {
                                            $success_message .= "<br><small>Skipped Exchange References: " . implode(', ', array_slice($duplicate_references, 0, 10));
                                            if (count($duplicate_references) > 10) {
                                                $success_message .= " and " . (count($duplicate_references) - 10) . " more...";
                                            }
                                            $success_message .= "</small>";
                                        }
                                        $success_message .= "</div>";
                                    }
                                    
                                    $success_message .= "<br><small><strong>Additional Reference:</strong> Supports numbers, empty values, or 'MTP' for Mobile Trading Platform payments.</small>";
                                    $preview_data = [];
                                }
                                
                            } catch (Exception $e) {
                                $db->rollBack();
                                $error_message = "Error processing file: " . $e->getMessage();
                                $has_errors = true;
                                error_log("BOND UPLOAD TRANSACTION ERROR: " . $e->getMessage());
                                error_log("Stack trace: " . $e->getTraceAsString());
                            }
                        } else if ($has_errors && $has_real_errors) {
                            $error_message = "Validation errors found. Please fix the errors highlighted below before uploading.";
                        } else {
                            // Only duplicate errors - but we already handled this
                            $error_message = "All rows were duplicates. No new bond trades were uploaded.";
                        }
                    }
                }
            } catch (Exception $e) {
                $error_message = "Error reading file: " . $e->getMessage();
            }
        }
    } else {
        $error_message = "Please select a valid CSV file to upload.";
        if (isset($_FILES['bond_file'])) {
            switch ($_FILES['bond_file']['error']) {
                case UPLOAD_ERR_INI_SIZE:
                case UPLOAD_ERR_FORM_SIZE:
                    $error_message .= " File size too large (max 5MB).";
                    break;
                case UPLOAD_ERR_PARTIAL:
                    $error_message .= " File upload was incomplete.";
                    break;
                case UPLOAD_ERR_NO_FILE:
                    $error_message .= " No file was selected.";
                    break;
            }
        }
    }
}

include '../includes/header.php';
?>

<div class="container-fluid">
    <div class="page-header">
        <div class="container">
            <h1 class="page-title">Upload Bonds</h1>
            <p class="text-muted"><small>
                <strong>Company:</strong> <?php echo htmlspecialchars($company_name); ?> | 
                <strong>Code:</strong> <?php echo htmlspecialchars($company_code); ?>
            </small></p>
            
            <p class="text-warning small">
                <i class="bi bi-exclamation-triangle"></i> <strong>Duplicate Detection:</strong> If you upload a file with trades that already exist (matching Exchange Reference), they will be <strong>SKIPPED</strong> and you will be alerted with the count and list of skipped references.
            </p>
            <p class="text-info small">
                <i class="bi bi-info-circle"></i> <strong>Accounting Changes:</strong> <strong>ONLY Company trades</strong> post to <strong>Marketable Securities - Bonds (1152)</strong>. Client trades are <strong>SKIPPED</strong> for manual receipt entry. <strong>No Cash at Bank entries</strong> are created for trades or fees.
            </p>
            <p class="text-success small">
                <i class="bi bi-plus-circle"></i> <strong>NEW:</strong> <strong>Additional Reference</strong> column now supported - can contain a number, be empty, or contain "MTP".
            </p>
        </div>
    </div>

    <div class="container">
        <?php if ($success_message): ?>
            <div class="alert alert-success">
                <i class="bi bi-check-circle me-2"></i>
                <?php echo $success_message; ?>
            </div>
        <?php endif; ?>

        <?php if ($error_message && !$has_errors): ?>
            <div class="alert alert-danger">
                <i class="bi bi-exclamation-triangle me-2"></i>
                <?php echo nl2br(htmlspecialchars($error_message)); ?>
            </div>
        <?php endif; ?>

        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">Bond Upload</h5>
            </div>
            <div class="card-body">
                <form method="POST" enctype="multipart/form-data" id="uploadForm">
                    <div class="mb-3">
                        <label for="bond_file" class="form-label">Select Bond CSV File</label>
                        <input type="file" class="form-control" id="bond_file" name="bond_file" accept=".csv, .txt" required>
                        <div class="form-text">
                            <strong>IMPORTANT:</strong> Only rows with <strong>"Asset Class = Bond"</strong> will be processed<br>
                            <strong>ACCOUNT UPDATE:</strong> <strong>ONLY Company trades</strong> post to <strong>Marketable Securities - Bonds (1152)</strong>. Client trades are <strong>SKIPPED</strong> for manual receipt entry.<br>
                            <strong>NEW:</strong> <strong>Additional Reference</strong> column is now supported - values can be a number, empty, or "MTP".
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary" id="uploadButton">
                        <i class="bi bi-upload me-1"></i>Upload Bonds
                    </button>
                    <a href="enter_bonds" class="btn btn-secondary">Back to Bonds</a>
                </form>
            </div>
        </div>
        
        <?php if ($has_errors && !empty($preview_data)): ?>
        <div class="card mt-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0">Validation Errors Found - Bond Rows Only</h5>
                <span class="badge bg-danger">
                    <?php 
                    $error_count = 0;
                    $dup_count = 0;
                    foreach ($preview_data as $row) {
                        $error_count += count($row['errors']);
                        if ($row['is_duplicate']) $dup_count++;
                    }
                    echo count($preview_data) . ' rows • ' . $error_count . ' errors (' . $dup_count . ' duplicates)'; 
                    ?>
                </span>
            </div>
            <div class="card-body p-0">
                <div class="alert alert-danger m-3">
                    <i class="bi bi-exclamation-triangle me-2"></i>
                    <strong>Upload failed because of validation errors.</strong> Please fix the errors below and try again.
                </div>
                <div class="table-responsive">
                    <table class="table table-sm table-hover mb-0">
                        <thead class="table-light">
                            <tr><th>Line</th><th>Security</th><th>Name</th><th>SCA Code</th><th>Trade Type</th>
                            <th>Asset Type</th><th>CSD Account</th><th>Buy\Sell</th><th>Quantity</th><th>Price</th>
                            <th>Consideration</th><th>Trade Date</th><th>Settlement Date</th><th>Exchange Ref</th>
                            <th>Additional Ref</th><th>Status</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($preview_data as $preview_row): 
                                $mapped_data = $preview_row['mapped_data'];
                                $consideration = !empty($mapped_data['consideration']) ? (float)$mapped_data['consideration'] : ((float)$mapped_data['quantity'] * (float)$mapped_data['price']);
                                $is_dup = $preview_row['is_duplicate'] ?? false;
                            ?>
                            <tr class="<?php echo $preview_row['has_errors'] ? ($is_dup ? 'table-warning' : 'table-danger') : 'table-success'; ?>">
                                <td class="fw-bold"><?php echo $preview_row['line_number']; ?></td>
                                <td><?php echo htmlspecialchars($mapped_data['security_id']); ?></td>
                                <td><?php echo htmlspecialchars($mapped_data['client_name']); ?><?php if ($preview_row['is_company_trade']): ?><span class="badge bg-info ms-1">Company</span><?php endif; ?></td>
                                <td><?php echo htmlspecialchars($mapped_data['sca_code']); ?></td>
                                <td><?php if ($preview_row['is_custodian_trade']): ?><span class="badge bg-warning">Custodian</span><?php else: ?><span class="badge bg-primary">Direct</span><?php endif; ?></td>
                                <td><?php echo htmlspecialchars(ucfirst($mapped_data['asset_class'])); ?></td>
                                <td><?php echo htmlspecialchars($mapped_data['client_cds']); ?></td>
                                <td><?php echo htmlspecialchars(ucfirst($mapped_data['trade_side'])); ?></td>
                                <td><?php echo safe_int_format($mapped_data['quantity']); ?></td>
                                <td>Tsh <?php echo safe_number_format($mapped_data['price']); ?></td>
                                <td>Tsh <?php echo safe_int_format($consideration); ?></td>
                                <td><strong><?php echo htmlspecialchars($mapped_data['trade_date']); ?></strong></td>
                                <td><?php echo htmlspecialchars($mapped_data['settlement_date']); ?></td>
                                <td><code class="small"><?php echo htmlspecialchars($mapped_data['exchange_reference'] ?: 'N/A'); ?></code></td>
                                <td><code class="small"><?php echo htmlspecialchars($mapped_data['additional_reference'] ?: 'N/A'); ?></code></td>
                                <td>
                                    <?php if ($is_dup): ?>
                                        <span class="badge bg-warning">Duplicate</span>
                                    <?php elseif ($preview_row['has_errors']): ?>
                                        <span class="badge bg-danger">Error</span>
                                    <?php else: ?>
                                        <span class="badge bg-success">Valid</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php if ($preview_row['has_errors']): ?>
                            <tr class="table-warning"><td colspan="16" class="small"><strong>Error:</strong> <?php echo htmlspecialchars(implode('; ', $preview_row['errors'])); ?></td></tr>
                            <?php endif; ?>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <div class="card mt-4">
            <div class="card-header">
                <h6 class="mb-0">Date Extraction & Fee Structure</h6>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6">
                        <small class="text-muted"><strong>How Date Extraction Works:</strong></small>
                        <ul class="mb-3">
                            <li>Auto-detects TAB or COMMA delimiters</li>
                            <li><strong>Trade Date</strong> extracted from 'Trade Date' column</li>
                            <li><strong>Settlement Date</strong> extracted from 'Settlement Date' column</li>
                            <li>Supported formats: <strong>YYYY/MM/DD</strong> (e.g., 2026/01/05), <code>MM/DD/YYYY</code>, <code>YYYY-MM-DD</code>, <code>YYYYMMDD</code></li>
                            <li>If dates missing, falls back to today (Trade Date) or T+2 (Settlement Date)</li>
                        </ul>
                        <small class="text-muted"><strong>NEW: Additional Reference Column</strong></small>
                        <ul class="mb-0">
                            <li>Can contain a <strong>number</strong> (e.g., 12345)</li>
                            <li>Can be <strong>empty</strong> (no value)</li>
                            <li>Can contain the word <strong>"MTP"</strong> (Mobile Trading Platform payments)</li>
                            <li>Stored in database for reference and tracking</li>
                        </ul>
                    </div>
                    <div class="col-md-6">
                        <small class="text-muted"><strong>BOND FEES:</strong></small>
                        <ul class="mb-0">
                            <li><strong>Brokerage (Tiered):</strong></li>
                            <li>• First 100M: 0.063132%</li>
                            <li>• Excess: 0.035%</li>
                            <li><strong>VAT</strong>: 18% on brokerage</li>
                            <li><strong>CMSA</strong>: 0.0100%</li>
                            <li><strong>CSD</strong>: 0.0118% (on Face Value)</li>
                            <li><strong>DSE</strong>: 0.02006% (on Face Value)</li>
                        </ul>
                        <hr>
                        <small class="text-muted"><strong>ACCOUNT MAPPING (No Cash):</strong></small>
                        <ul class="mb-0">
                            <li><strong>Company Trades</strong>: Marketable Securities - Bonds (1152)</li>
                            <li><strong>Client Trades</strong>: SKIPPED (Manual Receipt Entry)</li>
                            <li><strong>Brokerage</strong>: 411 (Income)</li>
                            <li><strong>VAT</strong>: 213 (Liability)</li>
                            <li><strong>CMSA</strong>: 2111 (Liability)</li>
                            <li><strong>DSE</strong>: 2112 (Liability)</li>
                            <li><strong>CSDR</strong>: 2113 (Liability)</li>
                        </ul>
                    </div>
                </div>
                <div class="mt-2 alert alert-info">
                    <small><strong>Example CSV format that works:</strong></small>
                    <pre class="mt-2 mb-0 small"><code>Security    Asset Class    Trade Date    Settlement Date    Quantity    Price    Buy\Sell    Exchange Reference    Additional Reference
TBILL-2026  Bond           2026/01/05    2026/01/06         10000000    98.5000  Buy         BOND-001              12345
SAMIA-2028  Bond           2026/01/06    2026/01/07         25000000    99.2000  Sell        BOND-002              MTP
T-BOND-2030 Bond           2026/01/07    2026/01/08         50000000    97.8000  Buy         BOND-003              </code></pre>
                    <small class="text-success"><strong>Note:</strong> Exchange Reference must be unique for each trade to prevent duplicates.</small>
                    <small class="text-warning"><strong>Duplicate Alert:</strong> If a trade with the same Exchange Reference already exists, it will be <strong>SKIPPED</strong> and you will be notified.</small>
                    <small class="text-info"><strong>Account Update:</strong> <strong>ONLY Company trades</strong> post to <strong>Marketable Securities - Bonds (1152)</strong>. Client trades are <strong>SKIPPED</strong> for manual receipt entry. No Cash at Bank entries.</small>
                    <small class="text-primary"><strong>Additional Reference:</strong> Supports numbers, empty values, or "MTP" for Mobile Trading Platform payments.</small>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.getElementById('uploadForm').addEventListener('submit', function(e) {
    var fileInput = document.getElementById('bond_file');
    var file = fileInput.files[0];
    var uploadButton = document.getElementById('uploadButton');
    if (file) {
        uploadButton.disabled = true;
        uploadButton.innerHTML = '<i class="bi bi-hourglass-split me-1"></i>Processing...';
        if (file.size / 1024 / 1024 > 5) {
            e.preventDefault();
            alert('File size must be less than 5MB');
            uploadButton.disabled = false;
            uploadButton.innerHTML = '<i class="bi bi-upload me-1"></i>Upload Bonds';
        }
    }
});
</script>

<?php include '../includes/footer.php'; ?>
