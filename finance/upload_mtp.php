<?php
require_once '../config/config.php';
require_once '../auth/auth_middleware.php';

// Enable error reporting for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Security headers
header("X-Frame-Options: DENY");
header("X-Content-Type-Options: nosniff");
header("X-XSS-Protection: 1; mode=block");
header("Referrer-Policy: strict-origin-when-cross-origin");

require_finance_officer();

// Define log file in same directory
$log_file = __DIR__ . '/csv_upload_debug.log';

// Function to write to log file
function writeLog($message, $level = 'INFO') {
    global $log_file;
    $timestamp = date('Y-m-d H:i:s');
    $formatted_message = "[$timestamp] [$level] $message\n";
    
    // Write to file
    file_put_contents($log_file, $formatted_message, FILE_APPEND | LOCK_EX);
    
    // Also write to PHP error log for backup
    error_log("CSV_UPLOAD [$level]: $message");
}

// Function to read log file
function readLog() {
    global $log_file;
    if (file_exists($log_file)) {
        return file_get_contents($log_file);
    }
    return "No log entries yet.";
}

// Function to clear log file
function clearLog() {
    global $log_file;
    if (file_exists($log_file)) {
        file_put_contents($log_file, '');
        return true;
    }
    return false;
}

// ============ Function to parse date from CSV ============
function parseDateFromCSV($date_str) {
    writeLog("Attempting to parse date: '$date_str'", "DATE_PARSE");
    
    if (empty($date_str)) {
        writeLog("Date string is empty", "DATE_PARSE_WARNING");
        return null;
    }
    
    // Clean the date string first
    $date_str = trim($date_str);
    
    // Try multiple date formats
    $formats = [
        'd-M-y',    // 1-Oct-25
        'd-M-Y',    // 1-Oct-2025
        'j-M-y',    // 1-Oct-25 (no leading zero)
        'j-M-Y',    // 1-Oct-2025 (no leading zero)
        'd/m/Y',    // 01/10/2025
        'd-m-Y',    // 01-10-2025
        'Y-m-d',    // 2025-10-01
        'M d, Y',   // Oct 1, 2025
        'F d, Y',   // October 1, 2025
    ];
    
    foreach ($formats as $format) {
        writeLog("Trying format: '$format' for date: '$date_str'", "DATE_PARSE_DEBUG");
        $date = DateTime::createFromFormat($format, $date_str);
        
        if ($date !== false) {
            // Check if the date was parsed correctly
            $errors = DateTime::getLastErrors();
            if ($errors === false || (is_array($errors) && $errors['warning_count'] == 0 && $errors['error_count'] == 0)) {
                writeLog("Successfully parsed date '$date_str' as format '$format' => " . $date->format('Y-m-d'), "DATE_PARSE");
                return $date;
            } else {
                writeLog("Partial match for format '$format' with errors: " . json_encode($errors), "DATE_PARSE_DEBUG");
            }
        }
    }
    
    // Try with strtotime as fallback
    writeLog("Trying strtotime for date: '$date_str'", "DATE_PARSE_DEBUG");
    $timestamp = strtotime($date_str);
    if ($timestamp !== false) {
        $date = new DateTime();
        $date->setTimestamp($timestamp);
        writeLog("Parsed date '$date_str' using strtotime => " . $date->format('Y-m-d'), "DATE_PARSE");
        return $date;
    }
    
    writeLog("Failed to parse date: '$date_str'", "DATE_PARSE_ERROR");
    return null;
}

// ============ Helper Functions ============
function cleanCSVCell($value) {
    if ($value === null) {
        return '';
    }
    
    // Remove all whitespace from beginning and end
    $value = trim($value);
    // Replace multiple spaces/tabs with single space
    $value = preg_replace('/\s+/', ' ', $value);
    // Remove non-breaking spaces and other Unicode spaces
    $value = preg_replace('/\p{Z}+/u', ' ', $value);
    // Remove any remaining control characters
    $value = preg_replace('/[\x00-\x1F\x7F]/', '', $value);
    
    return $value;
}

// ============ Function to auto-create client ============
function autoCreateClient($db, $name, $cds, $phone, $user_name) {
    writeLog("Attempting to auto-create client: Name='$name', CDS='$cds', Phone='$phone'", "CLIENT_CREATE");
    
    try {
        // Insert new client
        $stmt = $db->prepare("
            INSERT INTO clients (
                cds_account, client_name, phone,
                client_type, is_active, created_by, created_at, status
            ) VALUES (?, ?, ?, 'individual', 1, ?, NOW(), 'active')
        ");
        
        $params = [$cds, $name, $phone, $user_name];
        
        writeLog("Creating new client with params: " . json_encode($params), "CLIENT_CREATE");
        
        $result = $stmt->execute($params);
        
        if ($result) {
            $client_id = $db->lastInsertId();
            writeLog("✅ Successfully created new client ID: $client_id, Name: $name, CDS: $cds", "CLIENT_CREATE");
            return $client_id;
        } else {
            $error_info = $stmt->errorInfo();
            writeLog("❌ Failed to create client - SQL error: " . json_encode($error_info), "CLIENT_CREATE_ERROR");
            return false;
        }
        
    } catch (Exception $e) {
        writeLog("❌ Exception creating client: " . $e->getMessage(), "CLIENT_CREATE_ERROR");
        return false;
    }
}

// ============ Function to update bank account balance ============
function updateBankBalance($db, $bank_id, $amount) {
    writeLog("Updating bank balance for bank ID: $bank_id with amount: $amount", "BANK_UPDATE");
    
    try {
        $stmt = $db->prepare("
            UPDATE banks_accounts 
            SET current_balance = current_balance + ?, 
                available_balance = available_balance + ?
            WHERE id = ?
        ");
        
        $result = $stmt->execute([$amount, $amount, $bank_id]);
        
        if ($result) {
            writeLog("✅ Successfully updated bank balance for ID: $bank_id", "BANK_UPDATE");
            return true;
        } else {
            $error_info = $stmt->errorInfo();
            writeLog("❌ Failed to update bank balance - SQL error: " . json_encode($error_info), "BANK_UPDATE_ERROR");
            return false;
        }
    } catch (Exception $e) {
        writeLog("❌ Exception updating bank balance: " . $e->getMessage(), "BANK_UPDATE_ERROR");
        return false;
    }
}

// ============ Function to generate receipt number ============
function generateReceiptNo($db, $date) {
    $year = date('Y', strtotime($date));
    $month = date('m', strtotime($date));
    $day = date('d', strtotime($date));
    
    // Format: RCPYYYYMMDDXXXX
    $base_no = 'RCP' . $year . $month . $day;
    
    // Get last receipt number for this date
    $stmt = $db->prepare("
        SELECT receipt_no FROM receipts 
        WHERE receipt_no LIKE ? 
        ORDER BY receipt_no DESC 
        LIMIT 1
    ");
    $stmt->execute([$base_no . '%']);
    $last = $stmt->fetch();
    
    if ($last) {
        $last_no = $last['receipt_no'];
        $last_seq = intval(substr($last_no, -4));
        $new_seq = str_pad($last_seq + 1, 4, '0', STR_PAD_LEFT);
    } else {
        $new_seq = '0001';
    }
    
    return $base_no . $new_seq;
}

// ============ Function to create receipt ============
function createReceipt($db, $data, $user_name, $user_id) {
    writeLog("Creating receipt for: " . $data['name'], "RECEIPT_CREATE");
    
    try {
        // Generate receipt number if not provided
        if (empty($data['receipt_no'])) {
            $data['receipt_no'] = generateReceiptNo($db, $data['receipt_date']);
        }
        
        // Insert receipt
        $stmt = $db->prepare("
            INSERT INTO receipts (
                receipt_date, payment_mode, account_of, name, name_id,
                ac_debit, receipt_no, currency, account_no, amount,
                narration, record_in_financial, source_type, cds_account,
                status, created_by_username, created_at, bank_name
            ) VALUES (?, 1, 'C', ?, ?, ?, ?, 'Tsh', ?, ?, 'PAYMENT FROM MTP', 
                     'yes', 'CDS Account', ?, 'active', ?, NOW(), ?)
        ");
        
        $params = [
            $data['receipt_date'],
            $data['name'],
            $data['client_id'],
            $data['bank_id'],
            $data['receipt_no'],
            $data['cds'],
            $data['amount'],
            $data['cds'],
            $user_name,
            $data['bank_name']
        ];
        
        writeLog("Inserting receipt with params: " . json_encode($params), "RECEIPT_CREATE");
        
        $result = $stmt->execute($params);
        
        if ($result) {
            $receipt_id = $db->lastInsertId();
            writeLog("✅ Receipt created successfully - ID: $receipt_id, No: " . $data['receipt_no'], "RECEIPT_CREATE");
            
            // Create journal entries for the receipt
            createJournalEntries($db, $receipt_id, $data, $user_name, $user_id);
            
            return $receipt_id;
        } else {
            $error_info = $stmt->errorInfo();
            writeLog("❌ Failed to create receipt - SQL error: " . json_encode($error_info), "RECEIPT_CREATE_ERROR");
            return false;
        }
        
    } catch (Exception $e) {
        writeLog("❌ Exception creating receipt: " . $e->getMessage(), "RECEIPT_CREATE_ERROR");
        return false;
    }
}

// ============ Function to create journal entries ============
function createJournalEntries($db, $receipt_id, $data, $user_name, $user_id) {
    writeLog("Creating journal entries for receipt ID: $receipt_id", "JOURNAL_CREATE");
    
    try {
        // Get bank account details
        $stmt = $db->prepare("SELECT code, bank_name, account_number FROM banks_accounts WHERE id = ?");
        $stmt->execute([$data['bank_id']]);
        $bank = $stmt->fetch();
        
        if (!$bank) {
            throw new Exception("Bank account not found");
        }
        
        // Get fiscal year and period
        $fiscal_year = date('Y', strtotime($data['receipt_date']));
        $fiscal_period = date('m', strtotime($data['receipt_date']));
        
        // Get the 1112 Cash at Bank account from chart_of_accounts
        $stmt = $db->prepare("SELECT account_code, account_name FROM chart_of_accounts WHERE account_code = '1112' AND is_active = 1 LIMIT 1");
        $stmt->execute();
        $cash_at_bank_account = $stmt->fetch();
        
        if (!$cash_at_bank_account) {
            // Fallback if 1112 doesn't exist
            $cash_at_bank_account = [
                'account_code' => '1112',
                'account_name' => 'Cash at Bank'
            ];
            writeLog("Using default 1112 Cash at Bank account", "JOURNAL_CREATE");
        }
        
        // Determine control account based on receipt type (Clients - account_of 'C')
        $control_account_code = '73101'; // CLIENTS CONTROL A/C
        
        // 1. Debit 1112 Cash at Bank Account
        $debit_journal_no = generateJournalNo($db);
        writeLog("Creating debit journal entry (1112 Cash at Bank): $debit_journal_no", "JOURNAL_CREATE");
        
        $debit_stmt = $db->prepare("
            INSERT INTO journal_entries (
                journal_no, transaction_date, reference_no, reference_type, 
                description, account_code, account_name, debit_amount, 
                credit_amount, currency, entity_id, entity_name, entity_type,
                bank_account_id, bank_name, bank_account_number,
                fiscal_year, fiscal_period, posted_by, posted_at,
                status, created_by, created_by_username
            ) VALUES (?, ?, ?, 'receipt', 'Payment received from client', 
                     ?, ?, ?, 0, 'Tsh', ?, ?, 'client',
                     ?, ?, ?, ?, ?, ?, NOW(), 'posted', ?, ?)
        ");
        
        $debit_result = $debit_stmt->execute([
            $debit_journal_no,
            $data['receipt_date'],
            $data['receipt_no'],
            $cash_at_bank_account['account_code'],
            $cash_at_bank_account['account_name'],
            $data['amount'],
            $data['client_id'],
            $data['name'],
            $data['bank_id'],
            $bank['bank_name'],
            $bank['account_number'],
            $fiscal_year,
            $fiscal_period,
            $user_id,
            $user_id,
            $user_name
        ]);
        
        if (!$debit_result) {
            $error_info = $debit_stmt->errorInfo();
            writeLog("❌ Failed to create debit journal entry: " . json_encode($error_info), "JOURNAL_CREATE_ERROR");
            return false;
        }
        
        // 2. Credit Clients Control Account (73101)
        $credit_journal_no = generateJournalNo($db);
        writeLog("Creating credit journal entry (Clients Control A/C): $credit_journal_no", "JOURNAL_CREATE");
        
        $credit_stmt = $db->prepare("
            INSERT INTO journal_entries (
                journal_no, transaction_date, reference_no, reference_type, 
                description, account_code, account_name, debit_amount, 
                credit_amount, currency, entity_id, entity_name, entity_type,
                bank_account_id, bank_name, bank_account_number,
                fiscal_year, fiscal_period, posted_by, posted_at,
                status, created_by, created_by_username
            ) VALUES (?, ?, ?, 'receipt', 'Payment received from client', 
                     ?, ?, 0, ?, 'Tsh', ?, ?, 'client',
                     ?, ?, ?, ?, ?, ?, NOW(), 'posted', ?, ?)
        ");
        
        $credit_result = $credit_stmt->execute([
            $credit_journal_no,
            $data['receipt_date'],
            $data['receipt_no'],
            $control_account_code,
            'Clients Control A/C',
            $data['amount'],
            $data['client_id'],
            $data['name'],
            $data['bank_id'],
            $bank['bank_name'],
            $bank['account_number'],
            $fiscal_year,
            $fiscal_period,
            $user_id,
            $user_id,
            $user_name
        ]);
        
        if (!$credit_result) {
            $error_info = $credit_stmt->errorInfo();
            writeLog("❌ Failed to create credit journal entry: " . json_encode($error_info), "JOURNAL_CREATE_ERROR");
            return false;
        }
        
        writeLog("✅ Journal entries created successfully for receipt: " . $data['receipt_no'], "JOURNAL_CREATE");
        return true;
        
    } catch (Exception $e) {
        writeLog("❌ Exception creating journal entries: " . $e->getMessage(), "JOURNAL_CREATE_ERROR");
        return false;
    }
}

// ============ Function to generate journal number ============
function generateJournalNo($db) {
    $prefix = 'JRNL';
    $year = date('Y');
    $month = date('m');
    
    // Get last journal number for this month
    $stmt = $db->prepare("
        SELECT journal_no FROM journal_entries 
        WHERE journal_no LIKE ? 
        ORDER BY id DESC LIMIT 1
    ");
    $stmt->execute(["$prefix$year$month%"]);
    $last = $stmt->fetch();
    
    if ($last) {
        $last_no = intval(substr($last['journal_no'], -4));
        $new_no = str_pad($last_no + 1, 4, '0', STR_PAD_LEFT);
    } else {
        $new_no = '0001';
    }
    
    return $prefix . $year . $month . $new_no;
}

writeLog("=== CSV Upload Script Started ===");

// CSRF Protection
if (session_status() === PHP_SESSION_NONE) {
    session_start();
    writeLog("Session started - ID: " . session_id());
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    writeLog("CSRF token generated");
}

$db = getDBConnection();
if ($db) {
    writeLog("Database connection established successfully");
} else {
    writeLog("FAILED: Database connection could not be established", "ERROR");
    die("Database connection failed");
}

$success_message = '';
$error_message = '';
$validation_errors = [];
$processed_data = [];
$duplicate_controls = [];
$all_entries = [];
$total_amount = 0;
$valid_count = 0;
$error_count = 0;
$show_upload_button = false;

// Handle log management actions
if (isset($_GET['action'])) {
    switch ($_GET['action']) {
        case 'view_log':
            writeLog("Log file viewed by user");
            break;
        case 'clear_log':
            if (clearLog()) {
                $success_message = "✅ Log file cleared successfully.";
                writeLog("Log file cleared by user", "USER_ACTION");
            } else {
                $error_message = "❌ Failed to clear log file.";
            }
            break;
        case 'download_log':
            if (file_exists($log_file)) {
                header('Content-Type: text/plain');
                header('Content-Disposition: attachment; filename="csv_upload_debug_' . date('Y-m-d_H-i-s') . '.log"');
                readfile($log_file);
                exit;
            }
            break;
        case 'download_clean_csv':
            if (isset($_SESSION['cleaned_csv_data'])) {
                header('Content-Type: text/csv');
                header('Content-Disposition: attachment; filename="cleaned_data_' . date('Y-m-d_H-i-s') . '.csv"');
                echo $_SESSION['cleaned_csv_data'];
                exit;
            }
            break;
    }
}

writeLog("REQUEST METHOD: " . $_SERVER['REQUEST_METHOD']);
writeLog("POST data received: " . json_encode($_POST));
writeLog("FILES data received: " . json_encode($_FILES));

// Determine action from hidden field or button
$action = '';
if (isset($_POST['action'])) {
    $action = $_POST['action'];
    writeLog("Action determined from hidden field: $action", "FORM_SUBMIT");
} elseif (isset($_POST['upload_csv'])) {
    $action = 'upload_csv';
    writeLog("Action determined from button: upload_csv", "FORM_SUBMIT");
} elseif (isset($_POST['confirm_upload'])) {
    $action = 'confirm_upload';
    writeLog("Action determined from button: confirm_upload", "FORM_SUBMIT");
} else {
    writeLog("No action detected in POST data", "WARNING");
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST' && !empty($action)) {
    writeLog("Processing action: $action");
    
    // CSRF validation
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $error_message = "Invalid CSRF token.";
        writeLog("CSRF validation failed - token mismatch", "SECURITY");
    } else {
        writeLog("CSRF validation passed");
        
        if ($action === 'upload_csv') {
            // Handle CSV file upload
            if (isset($_FILES['csv_file']) && $_FILES['csv_file']['error'] == UPLOAD_ERR_OK) {
                $file_tmp_path = $_FILES['csv_file']['tmp_name'];
                $file_name = $_FILES['csv_file']['name'];
                $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
                
                if ($file_ext !== 'csv') {
                    $error_message = "Invalid file type. Only CSV files are allowed.";
                } elseif ($_FILES['csv_file']['size'] > 10485760) {
                    $error_message = "File size exceeds 10MB limit.";
                } else {
                    try {
                        if (!file_exists($file_tmp_path) || !is_readable($file_tmp_path)) {
                            throw new Exception("Temporary file not accessible.");
                        }
                        
                        if (($handle = fopen($file_tmp_path, 'r')) !== false) {
                            writeLog("CSV file opened successfully");
                            
                            // Get existing control numbers
                            $stmt = $db->prepare("SELECT TRIM(receipt_no) FROM receipts WHERE receipt_no LIKE '998550%'");
                            $stmt->execute();
                            $existing_receipts = $stmt->fetchAll(PDO::FETCH_COLUMN);
                            
                            $stmt = $db->prepare("SELECT TRIM(reference_no) FROM journal_entries WHERE reference_no LIKE '998550%'");
                            $stmt->execute();
                            $existing_journals = $stmt->fetchAll(PDO::FETCH_COLUMN);
                            
                            $existing_controls = array_merge($existing_receipts, $existing_journals);
                            $existing_controls_normalized = array_map(function($control) {
                                return preg_replace('/[^0-9]/', '', $control);
                            }, $existing_controls);
                            
                            // Get bank accounts
                            $stmt = $db->query("SELECT id, bank_name, code FROM banks_accounts WHERE status = 'active'");
                            $bank_accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
                            
                            $bank_mapping = [];
                            foreach ($bank_accounts as $bank) {
                                $clean_name = trim($bank['bank_name']);
                                $bank_mapping[$clean_name] = [
                                    'id' => $bank['id'],
                                    'code' => $bank['code']
                                ];
                            }
                            
                            $row_number = 0;
                            $header_skipped = false;
                            $header_row = [];
                            $cleaned_csv_rows = [];
                            
                            while (($row = fgetcsv($handle, 1000, ',', '"', '\\')) !== false) {
                                $row_number++;
                                $row_errors = [];
                                $row_fixes = [];
                                $row_warnings = [];
                                
                                // Clean all cells
                                $row = array_map('cleanCSVCell', $row);
                                $cleaned_csv_rows[] = $row;
                                
                                if (!$header_skipped) {
                                    $header_row = $row;
                                    $header_skipped = true;
                                    continue;
                                }
                                
                                // Map columns - IMPORTANT: Date is in "d-M-y" format (1-Oct-25)
                                $date_str = $row[0] ?? '';
                                $name = $row[1] ?? '';
                                $phone = $row[2] ?? '';
                                $cds = $row[3] ?? '';
                                $amount_str = $row[4] ?? '0';
                                $control_no = $row[5] ?? '';
                                $bank_name = $row[6] ?? '';
                                $broker = $row[7] ?? '';
                                
                                writeLog("Processing row $row_number: Date='$date_str', Name='$name', CDS='$cds'", "CSV_ROW");
                                
                                // Clean specific fields
                                $original_cds = $cds;
                                $cds = trim(preg_replace('/[^A-Z0-9]/', '', strtoupper($cds)));
                                if ($original_cds !== $cds && !empty($cds)) {
                                    $row_fixes[] = "CDS normalized";
                                }
                                
                                $original_control = $control_no;
                                $control_no = preg_replace('/[^0-9]/', '', $control_no);
                                if ($original_control !== $control_no && !empty($control_no)) {
                                    $row_fixes[] = "Control number cleaned";
                                }
                                
                                $original_bank = $bank_name;
                                $bank_name = trim($bank_name);
                                if ($original_bank !== $bank_name && !empty($bank_name)) {
                                    $row_fixes[] = "Bank name normalized";
                                }
                                
                                $original_name = $name;
                                $name = trim($name);
                                if ($original_name !== $name && !empty($name)) {
                                    $row_fixes[] = "Name normalized";
                                }
                                
                                // Validation
                                if (empty($name)) $row_errors[] = "Name is missing";
                                if (empty($control_no)) $row_errors[] = "Control number is missing";
                                if (empty($amount_str)) $row_errors[] = "Amount is missing";
                                if (empty($cds)) $row_errors[] = "CDS account is missing";
                                if (empty($bank_name)) $row_errors[] = "Bank name is missing";
                                
                                // Check for duplicates
                                if (!empty($control_no) && in_array($control_no, $existing_controls_normalized)) {
                                    $row_errors[] = "Duplicate control number";
                                    $duplicate_controls[] = [
                                        'row' => $row_number,
                                        'control_no' => $control_no,
                                        'name' => $name,
                                        'amount' => $amount_str
                                    ];
                                }
                                
                                // Validate bank account
                                $bank_id = null;
                                $bank_code = null;
                                $bank_match_type = 'exact';
                                if (!empty($bank_name)) {
                                    $clean_bank = trim($bank_name);
                                    if (isset($bank_mapping[$clean_bank])) {
                                        $bank_id = $bank_mapping[$clean_bank]['id'];
                                        $bank_code = $bank_mapping[$clean_bank]['code'];
                                    } else {
                                        // Try fuzzy match
                                        foreach ($bank_mapping as $db_bank_name => $bank_data) {
                                            if (stripos($db_bank_name, $clean_bank) !== false || 
                                                stripos($clean_bank, $db_bank_name) !== false) {
                                                $bank_id = $bank_data['id'];
                                                $bank_code = $bank_data['code'];
                                                $bank_match_type = 'fuzzy';
                                                $row_fixes[] = "Bank matched via fuzzy";
                                                break;
                                            }
                                        }
                                    }
                                    
                                    if (!$bank_id) {
                                        $row_errors[] = "Bank not found: $bank_name";
                                    }
                                }
                                
                                // Clean amount
                                $amount = 0;
                                if (!empty($amount_str)) {
                                    $clean_amount = preg_replace('/[^\d.,]/', '', $amount_str);
                                    $clean_amount = str_replace(',', '', $clean_amount);
                                    $clean_amount = preg_replace('/\.(?=.*\.)/', '', $clean_amount);
                                    $amount = floatval($clean_amount);
                                    
                                    if ($amount <= 0) {
                                        $row_errors[] = "Invalid amount: $amount_str";
                                    }
                                }
                                
                                // Parse date from "d-M-y" format (1-Oct-25)
                                $date = null;
                                $date_display = '';
                                $mysql_date = '';
                                if (!empty($date_str)) {
                                    $date = parseDateFromCSV($date_str);
                                    
                                    if (!$date) {
                                        $row_errors[] = "Invalid date format: $date_str (expected: 1-Oct-25)";
                                    } else {
                                        $date_display = $date->format('M d, Y');
                                        $mysql_date = $date->format('Y-m-d');
                                        writeLog("Parsed date '$date_str' => '$mysql_date'", "DATE_SUCCESS");
                                    }
                                }
                                
                                // Create entry
                                $entry_data = [
                                    'row_index' => $row_number,
                                    'date_str' => $date_str,
                                    'date' => $mysql_date,
                                    'date_display' => $date_display,
                                    'name' => $name,
                                    'phone' => $phone,
                                    'cds' => $cds,
                                    'amount' => $amount,
                                    'amount_display' => number_format($amount, 2),
                                    'control_no' => $control_no,
                                    'bank_name' => $bank_name,
                                    'bank_id' => $bank_id,
                                    'bank_code' => $bank_code,
                                    'bank_match_type' => $bank_match_type,
                                    'broker' => $broker,
                                    'errors' => $row_errors,
                                    'warnings' => $row_warnings,
                                    'fixes' => $row_fixes,
                                    'status' => empty($row_errors) ? 'valid' : 'error'
                                ];
                                
                                $all_entries[] = $entry_data;
                                
                                if (empty($row_errors)) {
                                    $processed_data[] = $entry_data;
                                    $total_amount += $amount;
                                    $valid_count++;
                                } else {
                                    $validation_errors[] = [
                                        'row' => $row_number,
                                        'errors' => $row_errors,
                                        'control_no' => $control_no,
                                        'name' => $name
                                    ];
                                    $error_count++;
                                }
                            }
                            
                            fclose($handle);
                            
                            // Store in session
                            $_SESSION['processed_receipts'] = $processed_data;
                            $_SESSION['validation_errors'] = $validation_errors;
                            $_SESSION['duplicate_controls'] = $duplicate_controls;
                            $_SESSION['total_amount'] = $total_amount;
                            $_SESSION['all_entries'] = $all_entries;
                            
                            // Show upload button if there are valid entries
                            $show_upload_button = ($valid_count > 0);
                            
                            $success_message = "✅ CSV processed successfully! ";
                            $success_message .= "Total rows: $row_number, ";
                            $success_message .= "✅ Valid rows: $valid_count, ";
                            $success_message .= "❌ Rows with errors: $error_count";
                            
                            if ($show_upload_button) {
                                $success_message .= "<br><br><strong>✅ Ready to upload $valid_count entries to database!</strong>";
                            }
                            
                        } else {
                            $error_message = "Could not open CSV file.";
                        }
                        
                    } catch (Exception $e) {
                        $error_message = "Error processing CSV file: " . $e->getMessage();
                        writeLog("EXCEPTION: " . $e->getMessage(), "ERROR");
                    }
                }
            } else {
                $error_message = "Please select a CSV file to upload.";
            }
        } 
        elseif ($action === 'confirm_upload') {
            // Handle confirmation and save to database
            writeLog("Processing confirm_upload action", "DATABASE");
            
            if (!isset($_SESSION['processed_receipts']) || empty($_SESSION['processed_receipts'])) {
                $error_message = "No valid data to process.";
            } else {
                $processed_data = $_SESSION['processed_receipts'];
                $saved_count = 0;
                $failed_count = 0;
                $created_clients = [];
                $created_receipts = [];
                
                writeLog("Starting to save " . count($processed_data) . " receipts to database", "DATABASE");
                
                $user_name = $_SESSION['username'] ?? 'System';
                $user_id = $_SESSION['user_id'] ?? null;
                
                try {
                    writeLog("Beginning database transaction");
                    $db->beginTransaction();
                    
                    // Process each entry
                    foreach ($processed_data as $index => $row_data) {
                        try {
                            writeLog("Processing row " . $row_data['row_index'], "DATABASE");
                            
                            // Check if client exists
                            $client_id = null;
                            if (!empty($row_data['cds'])) {
                                $stmt = $db->prepare("SELECT id FROM clients WHERE TRIM(cds_account) = ? AND is_active = 1 LIMIT 1");
                                $stmt->execute([$row_data['cds']]);
                                $client = $stmt->fetch();
                                
                                if (!$client) {
                                    // Auto-create client
                                    writeLog("Creating client for: " . $row_data['name'], "CLIENT_CREATE");
                                    $client_id = autoCreateClient(
                                        $db, 
                                        $row_data['name'], 
                                        $row_data['cds'], 
                                        $row_data['phone'] ?? '', 
                                        $user_name
                                    );
                                    
                                    if ($client_id) {
                                        $created_clients[] = [
                                            'row' => $row_data['row_index'],
                                            'name' => $row_data['name'],
                                            'cds' => $row_data['cds'],
                                            'client_id' => $client_id
                                        ];
                                    } else {
                                        throw new Exception("Failed to create client");
                                    }
                                } else {
                                    $client_id = $client['id'];
                                }
                            }
                            
                            // Create receipt data array
                            $receipt_data = [
                                'receipt_date' => $row_data['date'],
                                'name' => $row_data['name'],
                                'client_id' => $client_id,
                                'bank_id' => $row_data['bank_id'],
                                'bank_name' => $row_data['bank_name'],
                                'receipt_no' => $row_data['control_no'], // Using control number as receipt number
                                'cds' => $row_data['cds'],
                                'amount' => $row_data['amount']
                            ];
                            
                            // Create receipt and all related entries
                            $receipt_id = createReceipt($db, $receipt_data, $user_name, $user_id);
                            
                            if ($receipt_id) {
                                $saved_count++;
                                
                                // Update bank account balance
                                if (!empty($row_data['bank_id'])) {
                                    updateBankBalance($db, $row_data['bank_id'], $row_data['amount']);
                                }
                                
                                $created_receipts[] = [
                                    'row' => $row_data['row_index'],
                                    'receipt_id' => $receipt_id,
                                    'receipt_no' => $receipt_data['receipt_no'],
                                    'name' => $row_data['name'],
                                    'amount' => $row_data['amount'],
                                    'date' => $row_data['date_display']
                                ];
                                
                                writeLog("✅ Saved receipt ID: $receipt_id", "DATABASE");
                            } else {
                                $failed_count++;
                                writeLog("❌ Failed to save receipt", "DATABASE_ERROR");
                            }
                            
                        } catch (Exception $e) {
                            $failed_count++;
                            writeLog("❌ Exception processing row " . $row_data['row_index'] . ": " . $e->getMessage(), "DATABASE_ERROR");
                        }
                    }
                    
                    writeLog("Committing transaction", "DATABASE");
                    $db->commit();
                    
                    // Store created receipts info
                    $_SESSION['created_receipts'] = $created_receipts;
                    $_SESSION['created_clients'] = $created_clients;
                    $_SESSION['receipts_created_count'] = $saved_count;
                    $_SESSION['clients_created_count'] = count($created_clients);
                    
                    // Clear session data
                    unset($_SESSION['processed_receipts']);
                    unset($_SESSION['validation_errors']);
                    unset($_SESSION['duplicate_controls']);
                    unset($_SESSION['total_amount']);
                    unset($_SESSION['all_entries']);
                    unset($_SESSION['cleaned_csv_data']);
                    
                    $success_message = "✅ Successfully processed $saved_count receipts!";
                    
                    if (!empty($created_clients)) {
                        $success_message .= " Created " . count($created_clients) . " new client(s).";
                    }
                    
                    if ($failed_count > 0) {
                        $error_message = "❌ $failed_count receipt(s) failed to save.";
                    }
                    
                } catch (Exception $e) {
                    $db->rollBack();
                    $error_message = "Transaction failed: " . $e->getMessage();
                    writeLog("TRANSACTION FAILED: " . $e->getMessage(), "ERROR");
                }
            }
        }
    }
}

writeLog("=== CSV Upload Script Completed ===");

$page_title = 'CSV Receipt Upload';
include '../includes/header.php';

// Read log file for display
$log_content = readLog();
$log_lines = explode("\n", $log_content);
$log_size = file_exists($log_file) ? filesize($log_file) : 0;

// Show results from previous session
if (isset($_SESSION['created_receipts']) && !empty($_SESSION['created_receipts'])) {
    $created_receipts = $_SESSION['created_receipts'];
    $receipts_created_count = $_SESSION['receipts_created_count'] ?? count($created_receipts);
    
    // Clear after displaying
    unset($_SESSION['created_receipts']);
    unset($_SESSION['receipts_created_count']);
}
?>

<div class="container-fluid py-4">
    <!-- Receipt Creation Results -->
    <?php if (isset($created_receipts) && !empty($created_receipts)): ?>
    <div class="card shadow-sm mb-4">
        <div class="card-header bg-success text-white">
            <h5 class="mb-0"><i class="bi bi-receipt me-2"></i>Receipts Created Successfully</h5>
        </div>
        <div class="card-body">
            <div class="alert alert-success">
                <i class="bi bi-check-circle me-2"></i>
                <strong>✅ Successfully created <?php echo $receipts_created_count; ?> receipt(s) in the database.</strong>
                <br><small>Journal entries follow same pattern as manual receipts: Debit 1112 Cash at Bank, Credit 73101 Clients Control A/C</small>
            </div>
            <div class="table-responsive">
                <table class="table table-sm table-hover">
                    <thead class="table-light">
                        <tr>
                            <th>Row #</th>
                            <th>Receipt No</th>
                            <th>Client Name</th>
                            <th>Date</th>
                            <th>Amount</th>
                            <th>Receipt ID</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($created_receipts as $receipt): ?>
                        <tr>
                            <td class="fw-bold">#<?php echo $receipt['row']; ?></td>
                            <td><code><?php echo htmlspecialchars($receipt['receipt_no']); ?></code></td>
                            <td><?php echo htmlspecialchars($receipt['name']); ?></td>
                            <td><?php echo htmlspecialchars($receipt['date']); ?></td>
                            <td class="fw-bold text-success"><?php echo number_format($receipt['amount'], 2); ?></td>
                            <td><span class="badge bg-info"><?php echo $receipt['receipt_id']; ?></span></td>
                            <td><span class="badge bg-success">Complete</span></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($success_message): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <div class="d-flex align-items-center">
                <i class="bi bi-check-circle-fill me-2"></i>
                <div><?php echo $success_message; ?></div>
            </div>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if ($error_message): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <div class="d-flex align-items-center">
                <i class="bi bi-exclamation-triangle-fill me-2"></i>
                <div><?php echo $error_message; ?></div>
            </div>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="row">
        <div class="col-lg-12">
            <div class="card shadow-sm">
                <div class="card-header bg-primary text-white">
                    <h4 class="mb-0 text-dark"><i class="bi bi-upload me-2"></i>Upload CSV File (MTP Payments)</h4>
                </div>
                <div class="card-body">
                    <div class="mb-4">
                        <div class="alert alert-success">
                            <i class="bi bi-check-circle me-2"></i>
                            <strong>Automatic Features Enabled:</strong>
                            <ul class="mb-0 mt-2">
                                <li><strong>Date Parsing:</strong> Automatically handles "1-Oct-25" format</li>
                                <li><strong>Client Auto-Creation:</strong> Missing clients are automatically created</li>
                                <li><strong>Receipt Generation:</strong> Complete receipts with journal entries</li>
                                <li><strong>Bank Balance Update:</strong> Bank account balances are automatically updated</li>
                                <li><strong>Accounting:</strong> Uses same journal entries as manual receipts: Debit 1112 Cash at Bank, Credit 73101 Clients Control A/C</li>
                            </ul>
                        </div>
                        
                        <div class="alert alert-info">
                            <i class="bi bi-info-circle me-2"></i>
                            <strong>CSV Format (Date in "1-Oct-25" format):</strong> Date, Name, Phone, CDS, Paid Amount, Control No., Channel, Broker
                            <br><small>Example: 1-Oct-25, MATILDA, 255713277662, 635493, 1,177,623, 998550552775, CRDB Bank, VICTORY FI...</small>
                        </div>
                    </div>

                    <form method="POST" enctype="multipart/form-data" id="uploadForm">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
                        <input type="hidden" name="action" value="upload_csv">
                        
                        <div class="mb-3">
                            <label class="form-label">Select CSV File <span class="text-danger">*</span></label>
                            <input type="file" class="form-control" name="csv_file" id="csv_file" accept=".csv" required>
                            <div class="form-text">Only CSV files allowed (Max 10MB). Date format: "1-Oct-25" (day-month-year)</div>
                        </div>
                        
                        <div class="d-grid gap-2">
                            <button type="submit" name="upload_csv" class="btn btn-primary" id="uploadBtn">
                                <i class="bi bi-upload me-1"></i>Upload & Validate
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <?php if (!empty($all_entries)): ?>
    <!-- Validation Results -->
    <div class="card shadow-sm mt-4">
        <div class="card-header bg-info text-white">
            <h5 class="mb-0 text-dark">Validation Results</h5>
            <div>
                <span class="badge bg-success">Valid: <?php echo $valid_count; ?></span>
                <span class="badge bg-danger ms-1">Errors: <?php echo $error_count; ?></span>
            </div>
        </div>
        <div class="card-body">
            
            <!-- Quick Stats -->
            <div class="row mb-4">
                <div class="col-md-4 mb-3">
                    <div class="card border-success">
                        <div class="card-body text-center py-3">
                            <h3 class="text-success mb-1"><?php echo $valid_count; ?></h3>
                            <small class="text-muted">Valid Entries</small>
                        </div>
                    </div>
                </div>
                <div class="col-md-4 mb-3">
                    <div class="card border-danger">
                        <div class="card-body text-center py-3">
                            <h3 class="text-danger mb-1"><?php echo $error_count; ?></h3>
                            <small class="text-muted">Entries with Errors</small>
                        </div>
                    </div>
                </div>
                <div class="col-md-4 mb-3">
                    <div class="card border-primary">
                        <div class="card-body text-center py-3">
                            <h3 class="text-primary mb-1">
                                Tsh <?php echo number_format($total_amount, 2); ?>
                            </h3>
                            <small class="text-muted">Total Amount</small>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Entries Table -->
            <div class="table-responsive">
                <table class="table table-sm table-hover" id="entriesTable">
                    <thead class="table-light">
                        <tr>
                            <th width="40">#</th>
                            <th>Status</th>
                            <th>Name</th>
                            <th>CDS</th>
                            <th>Control No</th>
                            <th>Date</th>
                            <th>Bank</th>
                            <th class="text-end">Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($all_entries as $entry): ?>
                        <tr class="<?php echo $entry['status'] === 'error' ? 'table-danger' : ''; ?>">
                            <td><?php echo $entry['row_index']; ?></td>
                            <td>
                                <?php if ($entry['status'] === 'valid'): ?>
                                <span class="badge bg-success">✓ Valid</span>
                                <?php else: ?>
                                <span class="badge bg-danger">✗ Error</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (empty($entry['name'])): ?>
                                <span class="text-danger">MISSING</span>
                                <?php else: ?>
                                <?php echo htmlspecialchars($entry['name']); ?>
                                <?php endif; ?>
                            </td>
                            <td><?php echo htmlspecialchars($entry['cds']); ?></td>
                            <td><code><?php echo htmlspecialchars($entry['control_no']); ?></code></td>
                            <td><?php echo $entry['date_display']; ?></td>
                            <td>
                                <?php echo htmlspecialchars($entry['bank_name']); ?>
                                <?php if ($entry['bank_match_type'] === 'fuzzy'): ?>
                                <span class="badge bg-info ms-1" title="Bank matched via fuzzy matching">F</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end fw-bold <?php echo $entry['status'] === 'valid' ? 'text-success' : 'text-danger'; ?>">
                                <?php echo number_format($entry['amount'], 2); ?>
                            </td>
                        </tr>
                        <?php if (!empty($entry['errors'])): ?>
                        <tr class="table-warning">
                            <td colspan="8" class="small">
                                <i class="bi bi-exclamation-triangle text-warning me-1"></i>
                                <?php foreach ($entry['errors'] as $error): ?>
                                <span class="badge bg-danger me-1 mb-1"><?php echo htmlspecialchars($error); ?></span>
                                <?php endforeach; ?>
                            </td>
                        </tr>
                        <?php endif; ?>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
        </div>
    </div>
    <?php endif; ?>

</div>

<!-- FLOATING UPLOAD BUTTON -->
<?php if ($show_upload_button && $valid_count > 0): ?>
<div class="floating-upload-container">
    <div class="floating-upload-card">
        <div class="floating-upload-header">
            <i class="bi bi-cloud-upload"></i>
            <span>Ready to Upload</span>
        </div>
        <div class="floating-upload-body">
            <div class="upload-stats">
                <div class="stat-item">
                    <span class="stat-label">Valid Entries:</span>
                    <span class="stat-value text-success"><?php echo $valid_count; ?></span>
                </div>
                <div class="stat-item">
                    <span class="stat-label">Total Amount:</span>
                    <span class="stat-value text-primary">Tsh <?php echo number_format($total_amount, 2); ?></span>
                </div>
            </div>
            <form method="POST" id="floatingUploadForm">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                <input type="hidden" name="action" value="confirm_upload">
                <button type="submit" class="btn btn-success btn-lg w-100" 
                        onclick="return confirmImport(<?php echo $valid_count; ?>, <?php echo $total_amount; ?>)">
                    <i class="bi bi-save me-2"></i>
                    UPLOAD <?php echo $valid_count; ?> ENTRIES
                </button>
                <div class="upload-note">
                    <small><i class="bi bi-info-circle me-1"></i>Will create receipts, journal entries & update bank balances</small>
                    <br><small><i class="bi bi-check-circle me-1"></i>Journal: Debit 1112 Cash at Bank, Credit 73101 Clients Control A/C</small>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<style>
/* Floating Upload Button Styles */
.floating-upload-container {
    position: fixed;
    bottom: 30px;
    right: 30px;
    z-index: 9999;
    animation: slideInUp 0.5s ease-out;
}

.floating-upload-card {
    background: white;
    border-radius: 12px;
    box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
    border: 1px solid #dee2e6;
    width: 350px;
    overflow: hidden;
}

.floating-upload-header {
    background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
    color: white;
    padding: 15px 20px;
    display: flex;
    align-items: center;
    font-weight: 600;
}

.floating-upload-header i {
    font-size: 1.5rem;
    margin-right: 10px;
}

.floating-upload-body {
    padding: 20px;
}

.upload-stats {
    margin-bottom: 20px;
    padding: 15px;
    background: #f8f9fa;
    border-radius: 8px;
}

.stat-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 8px;
}

.stat-item:last-child {
    margin-bottom: 0;
}

.stat-label {
    font-weight: 500;
    color: #6c757d;
}

.stat-value {
    font-weight: 700;
    font-size: 1.1rem;
}

.upload-note {
    margin-top: 10px;
    text-align: center;
    color: #6c757d;
    font-size: 0.85rem;
}

/* Animation */
@keyframes slideInUp {
    from {
        transform: translateY(100px);
        opacity: 0;
    }
    to {
        transform: translateY(0);
        opacity: 1;
    }
}

/* Make sure table is scrollable but floating button stays fixed */
.table-danger {
    background-color: rgba(220, 53, 69, 0.1);
}
.table-warning {
    background-color: rgba(255, 193, 7, 0.1);
}
.card {
    border-radius: 10px;
}
.alert {
    border-radius: 8px;
}
.badge {
    padding: 0.35em 0.65em;
    font-size: 0.75em;
}

/* Responsive adjustments */
@media (max-width: 768px) {
    .floating-upload-container {
        bottom: 20px;
        right: 20px;
        left: 20px;
        width: auto;
    }
    
    .floating-upload-card {
        width: 100%;
    }
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // File validation
    const fileInput = document.getElementById('csv_file');
    const uploadBtn = document.getElementById('uploadBtn');
    const uploadForm = document.getElementById('uploadForm');
    
    fileInput.addEventListener('change', function() {
        const file = this.files[0];
        if (file && file.size > 10485760) {
            alert('File size exceeds 10MB limit.');
            this.value = '';
        }
    });
    
    uploadForm.addEventListener('submit', function(e) {
        if (!fileInput.value) {
            e.preventDefault();
            alert('Please select a CSV file.');
            return false;
        }
        
        const fileExt = fileInput.value.split('.').pop().toLowerCase();
        if (fileExt !== 'csv') {
            e.preventDefault();
            alert('Only CSV files are allowed.');
            return false;
        }
        
        // Show processing state
        const originalHTML = uploadBtn.innerHTML;
        uploadBtn.innerHTML = '<i class="bi bi-hourglass-split me-1"></i>Processing...';
        uploadBtn.disabled = true;
        
        setTimeout(function() {
            uploadBtn.innerHTML = originalHTML;
            uploadBtn.disabled = false;
        }, 5000);
    });
});

// Custom confirmation for import
function confirmImport(entryCount, totalAmount) {
    let message = 'Are you sure you want to upload ' + entryCount + ' entries to the database?\n';
    message += 'Total Amount: Tsh ' + totalAmount.toLocaleString('en-US', {minimumFractionDigits: 2}) + '\n\n';
    
    message += '✅ The system will automatically:\n';
    message += '1. Create receipts in receipts table\n';
    message += '2. Create journal entries:\n';
    message += '   - Debit: 1112 Cash at Bank\n';
    message += '   - Credit: 73101 Clients Control A/C\n';
    message += '3. Update bank account balances\n';
    message += '4. Create clients if they don\'t exist\n\n';
    
    message += 'This action cannot be undone.';
    
    return confirm(message);
}

// Make floating button follow scroll
window.addEventListener('scroll', function() {
    const floatingContainer = document.querySelector('.floating-upload-container');
    if (floatingContainer) {
        // The fixed positioning already handles this, but we add a slight shadow effect
        const scrollTop = window.pageYOffset || document.documentElement.scrollTop;
        if (scrollTop > 100) {
            floatingContainer.style.transform = 'translateY(-10px)';
            floatingContainer.style.transition = 'transform 0.3s ease';
        } else {
            floatingContainer.style.transform = 'translateY(0)';
        }
    }
});
</script>

<?php include '../includes/footer.php'; ?>