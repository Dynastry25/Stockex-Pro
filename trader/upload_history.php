<?php
// upload_csd_references.php
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Set error log directory
$log_dir = '../logs/';
if (!file_exists($log_dir)) {
    mkdir($log_dir, 0755, true);
}

// Create log file
$session_id = session_id() ?: 'nosession';
$log_file = $log_dir . 'csd_upload_' . date('Y-m-d') . '_' . substr($session_id, 0, 8) . '.log';
ini_set('error_log', $log_file);

// Start session and authentication
session_start();

if (!file_exists('../config/config.php')) {
    die('Configuration error.');
}

if (!file_exists('../auth/auth_middleware.php')) {
    die('Authentication error.');
}

require_once '../config/config.php';
require_once '../auth/auth_middleware.php';

// Authentication
require_login();
require_mandate();

$current_user = get_logged_in_user();

// Database connection
try {
    $db = getDBConnection();
} catch (Exception $e) {
    die("Database connection failed.");
}

// Variables
$success_message = '';
$error_message = '';
$upload_warning = '';
$preview_data = [];
$processed_count = 0;
$error_count = 0;
$valid_count = 0;

// Core functions
class CSDTransactionParser {
    public static function parseTransactionType($transactionType) {
        $transactionType = strtoupper(trim($transactionType));
        $result = [
            'market_type' => 'unknown',
            'settlement_type' => 'unknown',
            'trade_side' => 'unknown'
        ];
        
        $parts = explode(',', $transactionType);
        $market_part = trim($parts[0] ?? '');
        $settlement_part = trim($parts[1] ?? '');
        
        switch ($market_part) {
            case 'ONEX': $result['market_type'] = 'exchange'; break;
            case 'OFFEX': $result['market_type'] = 'off_exchange'; break;
            case 'TRANS': $result['market_type'] = 'transfer'; break;
            case 'IPO': $result['market_type'] = 'ipo'; break;
            case 'PHR': $result['market_type'] = 'reconciliation'; break;
        }
        
        switch ($settlement_part) {
            case 'DVP':
                $result['settlement_type'] = 'dvp';
                $result['trade_side'] = 'sell';
                break;
            case 'RVP':
                $result['settlement_type'] = 'rvp';
                $result['trade_side'] = 'buy';
                break;
            case 'DFP':
                $result['settlement_type'] = 'dfp';
                $result['trade_side'] = 'transfer_out';
                break;
            case 'RFP':
                $result['settlement_type'] = 'rfp';
                $result['trade_side'] = 'transfer_in';
                break;
        }
        
        return $result;
    }
}

function getOrCreateClient($db, $client_name, $sor_account, $created_by) {
    try {
        // Find by CDS account
        if (!empty($sor_account)) {
            $stmt = $db->prepare("SELECT id FROM clients WHERE cds_account = ? LIMIT 1");
            $stmt->execute([$sor_account]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($existing) {
                return $existing['id'];
            }
        }
        
        // Find by client name
        $stmt = $db->prepare("SELECT id FROM clients WHERE client_name = ? LIMIT 1");
        $stmt->execute([$client_name]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($existing) {
            return $existing['id'];
        }
        
        // Create new client
        $stmt = $db->prepare("
            INSERT INTO clients 
            (client_name, cds_account, status, created_by, created_at, updated_at, client_type, is_active, client_code)
            VALUES (?, ?, 'active', ?, NOW(), NOW(), 'individual', 1, ?)
        ");
        
        $client_code = 'CL' . substr($sor_account, -6) . date('Ymd');
        
        $stmt->execute([
            substr($client_name, 0, 200),
            $sor_account,
            $created_by,
            $client_code
        ]);
        
        return $db->lastInsertId();
        
    } catch (Exception $e) {
        return null;
    }
}

function updateReferenceIDInAllTables($db, $csd_reference, $client_id, $new_reference_data) {
    $updated_tables = [];
    $tables_to_check = [
        'trades' => [
            'csd_column' => 'csd_reference',
            'client_column' => 'client_id',
            'match_column' => 'client_cds_account'
        ],
        'etf_trades' => [
            'csd_column' => 'csd_reference',
            'client_column' => 'client_id',
            'match_column' => 'client_cds_account'
        ],
        'custodians_trades' => [
            'csd_column' => 'csd_reference',
            'client_column' => 'client_id',
            'match_column' => 'client_cds_account'
        ],
        'regulatory_fee_assignments' => [
            'csd_column' => 'csd_reference',
            'client_column' => 'client_id',
            'match_column' => 'client_name'
        ]
    ];
    
    foreach ($tables_to_check as $table => $columns) {
        try {
            $reference_column = $columns['csd_column'];
            $client_column = $columns['client_column'];
            $match_column = $columns['match_column'];
            
            if ($match_column === 'client_cds_account') {
                $sql = "UPDATE {$table} SET {$reference_column} = ?, {$client_column} = ?, updated_at = NOW() 
                        WHERE ({$match_column} = ? OR {$match_column} = ?) 
                        AND ({$reference_column} IS NULL OR {$reference_column} = '')";
                $params = [$csd_reference, $client_id, $new_reference_data['sor_account'], $new_reference_data['sor_account']];
            } elseif ($match_column === 'client_name') {
                $sql = "UPDATE {$table} SET {$reference_column} = ?, {$client_column} = ?, updated_at = NOW() 
                        WHERE {$match_column} = ? 
                        AND ({$reference_column} IS NULL OR {$reference_column} = '')";
                $params = [$csd_reference, $client_id, $new_reference_data['client_name']];
            }
            
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $updated = $stmt->rowCount();
            
            if ($updated > 0) {
                $updated_tables[$table] = $updated;
            }
            
        } catch (Exception $e) {
            // Continue with other tables
        }
    }
    
    return $updated_tables;
}

function findAndUpdateMatchingTrades($db, $csd_data, $client_id) {
    $updates = [];
    $tables_to_check = [
        'trades' => [
            'cds_column' => 'client_cds_account',
            'security_column' => 'security_id',
            'date_column' => 'trade_date'
        ],
        'etf_trades' => [
            'cds_column' => 'client_cds_account',
            'security_column' => 'etf_id',
            'date_column' => 'trade_date'
        ],
        'custodians_trades' => [
            'cds_column' => 'client_cds_account',
            'security_column' => 'security_id',
            'date_column' => 'trade_date'
        ],
        'regulatory_fee_assignments' => [
            'cds_column' => 'client_name',
            'security_column' => 'security_id',
            'date_column' => 'trade_date'
        ]
    ];
    
    foreach ($tables_to_check as $table_name => $columns) {
        try {
            $conditions = [];
            $params = [];
            
            $cds_column = $columns['cds_column'];
            $security_column = $columns['security_column'];
            $date_column = $columns['date_column'];
            
            if (!empty($csd_data['sor_account']) && $cds_column === 'client_cds_account') {
                $clean_cds = $csd_data['sor_account'];
                $conditions[] = "REPLACE({$cds_column}, ' ', '') = ?";
                $params[] = $clean_cds;
            } elseif (!empty($csd_data['client_name']) && $cds_column === 'client_name') {
                $clean_name = trim($csd_data['client_name']);
                $conditions[] = "{$cds_column} = ?";
                $params[] = $clean_name;
            }
            
            if (!empty($csd_data['instrument']) && !empty($security_column)) {
                $clean_instrument = trim($csd_data['instrument']);
                $conditions[] = "({$security_column} = ? OR {$security_column} LIKE ?)";
                $params[] = $clean_instrument;
                $params[] = "%{$clean_instrument}%";
            }
            
            if (!empty($csd_data['quantity']) && $csd_data['quantity'] > 0) {
                $conditions[] = "quantity = ?";
                $params[] = $csd_data['quantity'];
            }
            
            if (!empty($csd_data['trade_date']) && !empty($date_column)) {
                $conditions[] = "{$date_column} = ?";
                $params[] = $csd_data['trade_date'];
            }
            
            $conditions[] = "(csd_reference IS NULL OR csd_reference = '' OR csd_reference = '0')";
            
            if (!empty($conditions)) {
                $where_clause = implode(' AND ', $conditions);
                $sql = "SELECT id FROM {$table_name} WHERE {$where_clause} LIMIT 10";
                $stmt = $db->prepare($sql);
                $stmt->execute($params);
                $matches = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                if (!empty($matches)) {
                    $ids = array_column($matches, 'id');
                    $placeholders = implode(',', array_fill(0, count($ids), '?'));
                    $update_sql = "UPDATE {$table_name} SET csd_reference = ?, client_id = ?, updated_at = NOW() WHERE id IN ({$placeholders})";
                    $update_params = array_merge([$csd_data['csd_ref'], $client_id], $ids);
                    
                    $update_stmt = $db->prepare($update_sql);
                    $update_stmt->execute($update_params);
                    
                    $updates[$table_name] = count($matches);
                }
            }
        } catch (Exception $e) {
            // Continue with other tables
        }
    }
    
    return ['matched' => !empty($updates), 'updates' => $updates];
}

function detectCSVDelimiter($file_path) {
    $delimiters = ["\t" => 0, "," => 0, ";" => 0, "|" => 0];
    
    if (($handle = fopen($file_path, "r")) !== FALSE) {
        $line = fgets($handle);
        fclose($handle);
        
        if ($line !== FALSE) {
            foreach ($delimiters as $delimiter => &$count) {
                $count = count(str_getcsv($line, $delimiter, '"', '\\'));
            }
            return array_search(max($delimiters), $delimiters);
        }
    }
    
    return "\t";
}

function parseCSDCSV($file_path) {
    $rows = [];
    
    if (!file_exists($file_path)) {
        throw new Exception("File not found.");
    }
    
    $delimiter = detectCSVDelimiter($file_path);
    
    if (($handle = fopen($file_path, "r")) !== FALSE) {
        try {
            // Skip header
            fgetcsv($handle, 0, $delimiter, '"', '\\');
            
            $line_number = 1;
            while (($data = fgetcsv($handle, 0, $delimiter, '"', '\\')) !== FALSE) {
                $line_number++;
                
                if ($data === [null] || $data === [] || (count($data) === 1 && trim($data[0]) === '')) {
                    continue;
                }
                
                $data = array_map(function($value, $index) {
                    $value = trim($value);
                    if ($index === 3) { // CLIENT NAME - preserve spaces
                        return $value;
                    }
                    return preg_replace('/\s+/', '', $value);
                }, $data, array_keys($data));
                
                $data = array_pad($data, 13, '');
                
                $rows[] = [
                    'csd_ref' => $data[0] ?? '',
                    'bpid' => $data[1] ?? '',
                    'sor_account' => $data[2] ?? '',
                    'client_name' => $data[3] ?? '',
                    'transaction_type' => $data[4] ?? '',
                    'instrument' => $data[5] ?? '',
                    'quantity' => $data[6] ?? 0,
                    'price' => $data[7] ?? 0,
                    'consideration' => $data[8] ?? 0,
                    'status' => $data[9] ?? '',
                    'trade_date' => $data[10] ?? '',
                    'settlement_date' => $data[11] ?? '',
                    'exchange_reference' => $data[12] ?? '',
                    'line_number' => $line_number
                ];
            }
        } finally {
            fclose($handle);
        }
    } else {
        throw new Exception("Could not open file.");
    }
    
    return $rows;
}

function mapCSDRowToData($row) {
    // Clean spaces from most fields
    $space_removal_fields = ['csd_ref', 'bpid', 'sor_account', 'transaction_type', 'instrument', 'exchange_reference'];
    foreach ($space_removal_fields as $field) {
        if (isset($row[$field]) && is_string($row[$field])) {
            $row[$field] = preg_replace('/\s+/', '', trim($row[$field]));
        }
    }
    
    // Clean numeric fields
    $numeric_fields = ['quantity', 'price', 'consideration'];
    foreach ($numeric_fields as $field) {
        if (isset($row[$field]) && is_string($row[$field])) {
            $row[$field] = preg_replace('/[,\s]/', '', $row[$field]);
        }
    }
    
    // Clean client name
    if (isset($row['client_name'])) {
        $row['client_name'] = trim(preg_replace('/\s+/', ' ', $row['client_name']));
    }
    
    // Parse dates
    $trade_date = parseCSDDate($row['trade_date'] ?? '');
    $settlement_date = parseCSDDate($row['settlement_date'] ?? '');
    
    // Parse transaction type
    $transaction_type = $row['transaction_type'] ?? '';
    $parsed_transaction = CSDTransactionParser::parseTransactionType($transaction_type);
    
    // Handle large numbers as strings
    $quantity = $row['quantity'] ?? 0;
    if (is_numeric($quantity)) {
        $quantity_formatted = (string)$quantity;
    } else {
        $quantity_formatted = '0';
    }
    
    return [
        'csd_ref' => $row['csd_ref'] ?? '',
        'bpid' => $row['bpid'] ?? '',
        'sor_account' => $row['sor_account'] ?? '',
        'client_name' => $row['client_name'] ?? '',
        'transaction_type' => $transaction_type,
        'instrument' => $row['instrument'] ?? '',
        'quantity' => $quantity_formatted,
        'price' => floatval($row['price'] ?? 0),
        'consideration' => floatval($row['consideration'] ?? 0),
        'trade_date' => $trade_date,
        'settlement_date' => $settlement_date,
        'exchange_reference' => $row['exchange_reference'] ?? '',
        'trade_side' => $parsed_transaction['trade_side'],
        'market_type' => $parsed_transaction['market_type'],
        'settlement_type' => $parsed_transaction['settlement_type']
    ];
}

function parseCSDDate($date_str) {
    $date_str = trim($date_str);
    
    if (empty($date_str)) {
        return '';
    }
    
    $date_str = preg_replace('/\s+/', '', $date_str);
    
    // DD/MM/YYYY format
    if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $date_str, $matches)) {
        $day = str_pad($matches[1], 2, '0', STR_PAD_LEFT);
        $month = str_pad($matches[2], 2, '0', STR_PAD_LEFT);
        return $matches[3] . '-' . $month . '-' . $day;
    }
    
    // Already YYYY-MM-DD
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_str)) {
        return $date_str;
    }
    
    return '';
}

function validateCSDData($row, $db) {
    $errors = [];
    
    // Required fields
    $required_fields = [
        'csd_ref' => 'CSD REF',
        'sor_account' => 'SOR ACCOUNT',
        'client_name' => 'CLIENT NAME',
        'instrument' => 'INSTRUMENT',
        'quantity' => 'QUANTITY',
        'trade_date' => 'TRADE DATE'
    ];
    
    foreach ($required_fields as $field => $column_name) {
        $value = $row[$field] ?? '';
        if (empty($value)) {
            $errors[] = "{$column_name} is required";
        }
    }
    
    // Check for duplicate CSD reference
    $csd_ref = $row['csd_ref'] ?? '';
    if (!empty($csd_ref)) {
        $stmt = $db->prepare("SELECT COUNT(*) as count FROM csd_historical_trades WHERE csd_reference = ?");
        $stmt->execute([$csd_ref]);
        if ($stmt->fetch()['count'] > 0) {
            $errors[] = "Duplicate CSD Reference";
        }
    }
    
    // Validate numeric fields
    $price = $row['price'] ?? '';
    if (!empty($price) && !is_numeric($price)) {
        $errors[] = "Invalid Price (must be a number)";
    }
    
    $consideration = $row['consideration'] ?? '';
    if (!empty($consideration) && !is_numeric($consideration)) {
        $errors[] = "Invalid Consideration (must be a number)";
    }
    
    $quantity = $row['quantity'] ?? 0;
    if (!is_numeric($quantity)) {
        $errors[] = "Invalid Quantity (must be a number)";
    } elseif (floatval($quantity) <= 0) {
        $errors[] = "Quantity must be greater than 0";
    }
    
    $trade_date = $row['trade_date'] ?? '';
    if (!empty($trade_date) && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $trade_date)) {
        $errors[] = "Invalid Trade Date format (use DD/MM/YYYY)";
    }
    
    return $errors;
}

// Main processing
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'upload_csd') {
    if (isset($_FILES['csd_file']) && $_FILES['csd_file']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['csd_file'];
        
        // File size check
        $max_size = 10 * 1024 * 1024; // 10MB
        if ($file['size'] > $max_size) {
            $error_message = "File size exceeds 10MB limit.";
        } else {
            try {
                $rows = parseCSDCSV($file['tmp_name']);
                
                if (empty($rows)) {
                    $error_message = "No valid data found in the uploaded file.";
                } else {
                    // Validate all rows
                    foreach ($rows as $row) {
                        $mapped_data = mapCSDRowToData($row);
                        $validation_errors = validateCSDData($mapped_data, $db);
                        $has_error = !empty($validation_errors);
                        
                        $preview_data[] = [
                            'line_number' => $row['line_number'],
                            'mapped_data' => $mapped_data,
                            'has_errors' => $has_error,
                            'errors' => $validation_errors
                        ];
                        
                        if ($has_error) {
                            $error_count++;
                        } else {
                            $valid_count++;
                        }
                    }
                    
                    // Process valid rows
                    if ($valid_count > 0) {
                        $db->beginTransaction();
                        try {
                            $processed = 0;
                            $created_clients = [];
                            $matched_results = [];
                            $unmatched_records = [];
                            
                            foreach ($preview_data as $preview_row) {
                                if (!$preview_row['has_errors']) {
                                    $mapped_data = $preview_row['mapped_data'];
                                    $csd_reference = $mapped_data['csd_ref'];
                                    
                                    // Get or create client
                                    $client_id = getOrCreateClient($db, $mapped_data['client_name'], $mapped_data['sor_account'], $current_user['id']);
                                    
                                    if ($client_id) {
                                        // Insert into historical trades
                                        $stmt = $db->prepare("
                                            INSERT INTO csd_historical_trades 
                                            (csd_reference, bpid, sor_account, client_name, transaction_type,
                                             instrument, quantity, price, consideration,
                                             trade_date, settlement_date, exchange_reference, 
                                             trade_side, market_type, settlement_type,
                                             client_id, uploaded_by, uploaded_at)
                                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                                        ");
                                        
                                        $stmt->execute([
                                            $csd_reference,
                                            $mapped_data['bpid'],
                                            $mapped_data['sor_account'],
                                            substr($mapped_data['client_name'], 0, 255),
                                            substr($mapped_data['transaction_type'], 0, 50),
                                            substr($mapped_data['instrument'], 0, 100),
                                            $mapped_data['quantity'],
                                            $mapped_data['price'],
                                            $mapped_data['consideration'],
                                            $mapped_data['trade_date'],
                                            $mapped_data['settlement_date'],
                                            substr($mapped_data['exchange_reference'], 0, 100),
                                            $mapped_data['trade_side'],
                                            $mapped_data['market_type'],
                                            $mapped_data['settlement_type'],
                                            $client_id,
                                            $current_user['id']
                                        ]);
                                        
                                        $processed++;
                                        
                                        // Find and update matching trades
                                        $update_result = findAndUpdateMatchingTrades($db, $mapped_data, $client_id);
                                        
                                        // Update reference IDs in all tables
                                        updateReferenceIDInAllTables($db, $csd_reference, $client_id, $mapped_data);
                                        
                                        if ($update_result['matched']) {
                                            $matched_results[] = $csd_reference;
                                        } else {
                                            $unmatched_records[] = $csd_reference;
                                        }
                                    }
                                }
                            }
                            
                            $db->commit();
                            $processed_count = $processed;
                            
                            // Build success message
                            $success_message = "<div class='alert alert-success'>";
                            $success_message .= "<strong>Success!</strong> Processed {$processed} CSD records.<br>";
                            
                            if ($error_count > 0) {
                                $success_message .= "<strong>Note:</strong> {$error_count} rows were skipped due to errors.<br>";
                            }
                            
                            if (!empty($matched_results)) {
                                $matched_count = count($matched_results);
                                $success_message .= "Updated {$matched_count} matching records.<br>";
                            }
                            
                            if (!empty($unmatched_records)) {
                                $unmatched_count = count($unmatched_records);
                                $success_message .= "{$unmatched_count} records had no matches.<br>";
                            }
                            
                            $success_message .= "</div>";
                            
                            // Warning message if errors were skipped
                            if ($error_count > 0) {
                                $upload_warning = "<div class='alert alert-warning'>";
                                $upload_warning .= "<strong>Warning:</strong> {$error_count} rows were skipped due to validation errors. ";
                                $upload_warning .= "{$valid_count} valid rows were processed.<br>";
                                $upload_warning .= "</div>";
                            }
                            
                        } catch (Exception $e) {
                            $db->rollBack();
                            $error_message = "Error processing data: " . htmlspecialchars($e->getMessage());
                        }
                    } elseif ($error_count > 0) {
                        $error_message = "<div class='alert alert-danger'>";
                        $error_message .= "<strong>Upload failed!</strong> All {$error_count} rows have validation errors.<br>";
                        $error_message .= "</div>";
                    }
                }
            } catch (Exception $e) {
                $error_message = "Error reading file: " . htmlspecialchars($e->getMessage());
            }
        }
    } else {
        $error_message = "Please select a valid file to upload.";
    }
}

include '../includes/header.php';
?>

<div class="container-fluid">
    <div class="page-header">
        <div class="container">
            <h1 class="page-title">Upload CSD References</h1>
            <p class="page-subtitle">Upload CSD historical data and link to existing trades</p>
        </div>
    </div>

    <div class="container">
        <?php if ($success_message): ?>
            <?php echo $success_message; ?>
        <?php endif; ?>

        <?php if ($upload_warning): ?>
            <?php echo $upload_warning; ?>
        <?php endif; ?>

        <?php if ($error_message): ?>
            <div class="alert alert-danger">
                <?php echo $error_message; ?>
            </div>
        <?php endif; ?>

        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">CSD Reference Upload</h5>
            </div>
            <div class="card-body">
                <form method="POST" enctype="multipart/form-data" id="uploadForm">
                    <input type="hidden" name="action" value="upload_csd">
                    
                    <div class="mb-3">
                        <label for="csd_file" class="form-label">Select CSD History File</label>
                        <input type="file" class="form-control" id="csd_file" name="csd_file" accept=".csv,.txt,.tsv" required>
                        <div class="form-text">
                            <strong>Format:</strong> CSV/TXT with columns: CSD REF, BPID, SOR ACCOUNT, CLIENT NAME, TRANSACTION TYPE, INSTRUMENT, QUANTITY, PRICE, CONSIDERATION, STATUS, TRADE DATE, SETTLEMENT DATE, EXCHANGE REFERENCE<br>
                            <strong>Supports:</strong> Large numbers (billions/trillions)<br>
                            <strong>Matching:</strong> Uses SOR ACCOUNT to match with existing trades<br>
                            <strong>Auto-creates:</strong> Clients if CDS account doesn't exist
                        </div>
                    </div>
                    
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary" id="uploadButton">
                            Upload & Link CSD References
                        </button>
                        <a href="enter_shares" class="btn btn-secondary">
                            Back to Shares
                        </a>
                    </div>
                </form>
            </div>
        </div>
        
        <?php if ($error_count > 0 && !empty($preview_data)): ?>
        <div class="card mt-4">
            <div class="card-header">
                <h5 class="mb-0">Detailed Error Report (<?php echo $error_count; ?> rows with errors)</h5>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Line</th>
                                <th>CSD REF</th>
                                <th>Client</th>
                                <th>SOR/CDS</th>
                                <th>Instrument</th>
                                <th>Qty</th>
                                <th>Amount</th>
                                <th>Date</th>
                                <th>Errors</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($preview_data as $preview_row): 
                                if (!$preview_row['has_errors']) continue;
                                $mapped = $preview_row['mapped_data'];
                            ?>
                            <tr class="table-danger">
                                <td class="fw-bold"><?php echo htmlspecialchars($preview_row['line_number']); ?></td>
                                <td><code><?php echo htmlspecialchars($mapped['csd_ref']); ?></code></td>
                                <td><small><?php echo htmlspecialchars(substr($mapped['client_name'], 0, 20)); ?></small></td>
                                <td><code><?php echo htmlspecialchars($mapped['sor_account']); ?></code></td>
                                <td><small><?php echo htmlspecialchars(substr($mapped['instrument'], 0, 15)); ?></small></td>
                                <td class="text-end"><?php echo number_format(floatval($mapped['quantity']), 0); ?></td>
                                <td class="text-end"><?php echo number_format($mapped['consideration'], 2); ?></td>
                                <td><small><?php echo htmlspecialchars($mapped['trade_date']); ?></small></td>
                                <td>
                                    <?php foreach ($preview_row['errors'] as $err): ?>
                                        <div class="text-danger small">• <?php echo htmlspecialchars($err); ?></div>
                                    <?php endforeach; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const uploadForm = document.getElementById('uploadForm');
    const uploadButton = document.getElementById('uploadButton');
    
    if (uploadForm) {
        uploadForm.addEventListener('submit', function(e) {
            const fileInput = document.getElementById('csd_file');
            const file = fileInput.files[0];
            
            if (file) {
                uploadButton.disabled = true;
                uploadButton.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Processing...';
                
                // File size validation
                const fileSize = file.size / 1024 / 1024;
                if (fileSize > 10) {
                    e.preventDefault();
                    alert('File size must be less than 10MB');
                    uploadButton.disabled = false;
                    uploadButton.innerHTML = 'Upload & Link CSD References';
                    return false;
                }
                
                // File type validation
                const fileName = file.name.toLowerCase();
                if (!fileName.match(/\.(csv|txt|tsv)$/)) {
                    e.preventDefault();
                    alert('Please select a CSV, TXT, or TSV file');
                    uploadButton.disabled = false;
                    uploadButton.innerHTML = 'Upload & Link CSD References';
                    return false;
                }
            }
        });
    }
});
</script>

<?php include '../includes/footer.php'; ?>