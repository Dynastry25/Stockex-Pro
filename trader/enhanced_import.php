<?php
require_once '../config/config.php';
require_once '../auth/auth_middleware.php';

require_trader();
require_mandate();

$db = getDBConnection();

function auto_create_client($db, $cds_account, $client_name) {
    // Check if client exists
    $stmt = $db->prepare("SELECT id FROM clients WHERE cds_account = ?");
    $stmt->execute([$cds_account]);
    $client = $stmt->fetch();
    
    if (!$client) {
        // Auto-create client
        $stmt = $db->prepare("INSERT INTO clients (cds_account, client_name) VALUES (?, ?)");
        $stmt->execute([$cds_account, $client_name]);
        return $db->lastInsertId();
    }
    
    return $client['id'];
}

function auto_create_bond($db, $security_id, $asset_class) {
    if (strtolower($asset_class) !== 'bond') {
        error_log("Ignoring non-bond asset for bond creation: Asset Class = '$asset_class', Security = '$security_id'");
        return null;
    }
    
    error_log("DEBUG: Starting bond creation for security: $security_id");
    
    if (!preg_match('/^\d+-\d+(\.\d+)?-T\d+-A\d+$/', $security_id)) {
        error_log("DEBUG: Bond format validation failed for: $security_id (Expected format: XXX-XX-TXX-AX)");
        // Continue anyway to try parsing
    }
    
    // Check if bond exists
    $stmt = $db->prepare("SELECT id FROM bonds WHERE security_id = ?");
    $stmt->execute([$security_id]);
    $bond = $stmt->fetch();
    
    if ($bond) {
        error_log("DEBUG: Found existing bond: $security_id with ID: " . $bond['id']);
        return $bond['id'];
    }
    
    error_log("DEBUG: Bond not found, creating new bond for: $security_id");
    
    $parts = explode('-', $security_id);
    $bond_number = $parts[0] ?? '675';
    $coupon_rate = isset($parts[1]) ? (float)$parts[1] : 15.0;
    $term = isset($parts[2]) ? (int)str_replace('T', '', $parts[2]) : 16;
    $auction = $parts[3] ?? 'A1';
    
    error_log("DEBUG: Parsed bond data - Number: $bond_number, Coupon: $coupon_rate%, Term: $term years, Auction: $auction");
    
    $bond_name = "Government Bond " . $security_id;
    $issuer = "Government Treasury";
    $issue_date = date('Y-m-d');
    $maturity_date = date('Y-m-d', strtotime("+{$term} years"));
    
    error_log("DEBUG: Bond details - Name: $bond_name, Issuer: $issuer, Issue: $issue_date, Maturity: $maturity_date");
    
    try {
        $stmt = $db->prepare("
            INSERT INTO bonds (security_id, bond_name, issuer, coupon_rate, face_value, issue_date, maturity_date, status) 
            VALUES (?, ?, ?, ?, 1000, ?, ?, 'active')
        ");
        
        $params = [$security_id, $bond_name, $issuer, $coupon_rate, $issue_date, $maturity_date];
        error_log("DEBUG: Executing bond insert with params: " . json_encode($params));
        
        if ($stmt->execute($params)) {
            $bond_id = $db->lastInsertId();
            error_log("DEBUG: Successfully created bond: $security_id with ID: $bond_id");
            return $bond_id;
        } else {
            $error_info = $stmt->errorInfo();
            error_log("DEBUG: Failed to insert bond: $security_id - SQL Error: " . json_encode($error_info));
            return null;
        }
    } catch (Exception $e) {
        error_log("DEBUG: Exception creating bond $security_id: " . $e->getMessage());
        error_log("DEBUG: Exception trace: " . $e->getTraceAsString());
        return null;
    }
}

function auto_create_equity($db, $security_id, $company_name = null) {
    // Check if equity exists
    $stmt = $db->prepare("SELECT id FROM equities WHERE security_id = ?");
    $stmt->execute([$security_id]);
    $equity = $stmt->fetch();
    
    if (!$equity && $company_name) {
        // Auto-create equity
        $stmt = $db->prepare("
            INSERT INTO equities (security_id, stock_name, company_name) 
            VALUES (?, ?, ?)
        ");
        
        if ($stmt->execute([$security_id, $company_name . ' Stock', $company_name])) {
            return $db->lastInsertId();
        }
    }
    
    return $equity ? $equity['id'] : null;
}

function auto_generate_receipt_invoice($db, $trade_id, $user_id) {
    // Get trade details
    $stmt = $db->prepare("SELECT * FROM trades WHERE id = ?");
    $stmt->execute([$trade_id]);
    $trade = $stmt->fetch();
    
    if (!$trade) return;
    
    $receipt_number = generate_reference_number('RCP');
    $invoice_number = generate_reference_number('INV');
    
    // Calculate fees and taxes (2% fees, 1% tax)
    $fees = $trade['consideration'] * 0.02;
    $taxes = $trade['consideration'] * 0.01;
    
    if ($trade['trade_side'] === 'buy') {
        // Generate receipt for buyer
        $net_amount = $trade['consideration'] + $fees + $taxes;
        
        $stmt = $db->prepare("
            INSERT INTO trade_receipts (receipt_number, trade_id, client_cds_account, client_name, 
                                      security_name, quantity, unit_price, gross_amount, fees, taxes, 
                                      net_amount, currency, receipt_date, generated_by) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $receipt_number, $trade_id, $trade['client_cds_account'], $trade['client_name'],
            $trade['security_name'], $trade['quantity'], $trade['price'], $trade['consideration'],
            $fees, $taxes, $net_amount, $trade['currency'], $trade['trade_date'], $user_id
        ]);
    } else {
        // Generate invoice for seller
        $net_amount = $trade['consideration'] - $fees - $taxes;
        
        $stmt = $db->prepare("
            INSERT INTO trade_invoices (invoice_number, trade_id, client_cds_account, client_name, 
                                      security_name, quantity, unit_price, gross_amount, fees, taxes, 
                                      net_amount, currency, invoice_date, generated_by) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $invoice_number, $trade_id, $trade['client_cds_account'], $trade['client_name'],
            $trade['security_name'], $trade['quantity'], $trade['price'], $trade['consideration'],
            $fees, $taxes, $net_amount, $trade['currency'], $trade['trade_date'], $user_id
        ]);
    }
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['import_csv'])) {
    $file_path = $_FILES['csv_file']['tmp_name'];
    $file_handle = fopen($file_path, 'r');
    
    if ($file_handle) {
        $imported = 0;
        $errors = 0;
        $dumped = 0;
        $error_details = [];
        
        $header = fgetcsv($file_handle);
        
        try {
            $db->beginTransaction();
            
            $row_number = 1;
            while (($row = fgetcsv($file_handle)) !== FALSE) {
                $row_number++;
                $data = array_combine($header, $row);
                
                // Determine asset class
                $asset_class = strtolower(trim($data['Asset Class'] ?? 'equity'));
                $security_id = trim($data['Security'] ?? '');
                
                $instrument_created = false;
                
                if ($asset_class === 'bond') {
                    error_log("DEBUG: Processing bond row $row_number: Asset Class = '$asset_class', Security = '$security_id'");
                    $instrument_id = auto_create_bond($db, $security_id, $asset_class);
                    if (!$instrument_id) {
                        error_log("DEBUG: Bond creation failed for security: $security_id in row $row_number");
                        $error_details[] = "Row $row_number: Bond '$security_id' creation failed - Check error log for details";
                        $dumped++;
                        continue;
                    }
                    $instrument_created = true;
                    error_log("DEBUG: Bond processed successfully: $security_id with ID: $instrument_id");
                } elseif ($asset_class === 'equity') {
                    error_log("Processing equity: Asset Class = '$asset_class', Security = '$security_id'");
                    $instrument_id = auto_create_equity($db, $security_id, $data['Name'] ?? '');
                    if (!$instrument_id) {
                        $error_details[] = "Row $row_number: Equity '$security_id' - Not found and auto-creation failed";
                        $dumped++;
                        continue;
                    }
                    $instrument_created = true;
                } else {
                    error_log("Ignoring unsupported asset class: '$asset_class' for security: '$security_id' in row $row_number");
                    $error_details[] = "Row $row_number: Unsupported asset class '$asset_class' - Only 'bond' and 'equity' are supported";
                    $dumped++;
                    continue;
                }
                
                // Auto-create client
                $cds_account = $data['CSD Account'] ?? '';
                $client_name = $data['Name'] ?? '';
                
                if (empty($cds_account) || empty($client_name)) {
                    $error_details[] = "Row $row_number: Missing client information (CDS: '$cds_account', Name: '$client_name')";
                    $dumped++;
                    continue;
                }
                
                auto_create_client($db, $cds_account, $client_name);
                
                $trade_reference = generate_reference_number('TRD');
                $trade_side = strtolower($data['Buy\\Sell']) === 'buy' ? 'buy' : 'sell';
                $quantity = (int)($data['Quantity'] ?? 0);
                $price = (float)($data['Price'] ?? 0);
                $rate = (float)($data['Rate'] ?? $price);
                $consideration = (float)($data['Consideration'] ?? ($quantity * $price));
                
                if ($quantity <= 0 || $price <= 0) {
                    $error_details[] = "Row $row_number: Invalid quantity ($quantity) or price ($price)";
                    $dumped++;
                    continue;
                }
                
                $stmt = $db->prepare("
                    INSERT INTO trades (
                        trade_reference, asset_class, security_id, security_name,
                        client_cds_account, client_name, capacity,
                        broker_name, counterparty_broker, counterparty_name, counterparty_cds_account,
                        trade_side, quantity, price, rate, consideration,
                        trade_date, settlement_date, maturity_date,
                        exchange_reference, origin, time_executed, currency,
                        uploaded_by
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                
                $values = [
                    $trade_reference, $asset_class, $security_id, $data['Security'],
                    $cds_account, $client_name, $data['Capacity'] ?? 'agency',
                    $data['Broker'] ?? 'MAIN', $data['Counterparty'] ?? 'Unknown', 
                    $data['Counterparty Name'] ?? 'Unknown', $data['Counterparty CSD Account'] ?? '',
                    $trade_side, $quantity, $price, $rate, $consideration,
                    date('Y-m-d', strtotime($data['Trade Date'])), 
                    date('Y-m-d', strtotime($data['Settlement Date'])),
                    isset($data['Maturity Date']) && !empty($data['Maturity Date']) ? date('Y-m-d', strtotime($data['Maturity Date'])) : null,
                    $data['Exchange Reference'] ?? '', $data['Origin'] ?? 'CSV Import',
                    $data['Time'] ?? null, 'USD', $_SESSION['user_id']
                ];
                
                if ($stmt->execute($values)) {
                    $trade_id = $db->lastInsertId();
                    
                    // Auto-generate receipt/invoice
                    auto_generate_receipt_invoice($db, $trade_id, $_SESSION['user_id']);
                    
                    $imported++;
                } else {
                    $error_details[] = "Row $row_number: Trade insertion failed - " . implode(', ', $stmt->errorInfo());
                    $errors++;
                }
            }
            
            $db->commit();
            
            $success_message = "Import completed: $imported imported, $dumped dumped, $errors errors";
            
        } catch (Exception $e) {
            $db->rollBack();
            $error_message = 'Import failed: ' . $e->getMessage();
            $error_details[] = "System error: " . $e->getMessage();
        }
        
        fclose($file_handle);
    }
}

?>
