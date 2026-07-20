<?php
require_once '../config/config.php';
require_once '../auth/auth_middleware.php';
require_once '../includes/bond_validation.php';

require_trader();
require_mandate();

$response = ['valid' => false, 'message' => ''];

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    
    // ============ CHECK FOR DUPLICATE BY EXCHANGE REFERENCE ============
    if (isset($_POST['exchange_reference']) && !empty($_POST['exchange_reference'])) {
        $exchange_reference = sanitize_input($_POST['exchange_reference']);
        
        if (empty($exchange_reference)) {
            $response['message'] = 'Exchange Reference is required.';
        } else {
            $db = getDBConnection();
            
            try {
                // Check if exchange reference already exists in any trade table
                $stmt = $db->prepare("
                    SELECT COUNT(*) as count FROM trades WHERE exchange_reference = ?
                    UNION ALL
                    SELECT COUNT(*) as count FROM etf_trades WHERE exchange_reference = ?
                    UNION ALL
                    SELECT COUNT(*) as count FROM custodians_trades WHERE exchange_reference = ?
                ");
                $stmt->execute([$exchange_reference, $exchange_reference, $exchange_reference]);
                $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                $total_count = 0;
                foreach ($results as $row) {
                    $total_count += $row['count'];
                }
                
                if ($total_count > 0) {
                    $response['valid'] = false;
                    $response['message'] = "Duplicate detected. Exchange Reference '{$exchange_reference}' already exists in the system.";
                    $response['is_duplicate'] = true;
                } else {
                    $response['valid'] = true;
                    $response['message'] = 'Exchange Reference is unique.';
                    $response['is_duplicate'] = false;
                }
                
            } catch (Exception $e) {
                $response['message'] = 'Database error: ' . $e->getMessage();
            }
        }
    }
    
    // ============ BOND ATS CODE VALIDATION ============
    elseif (isset($_POST['ats_code']) && !empty($_POST['ats_code'])) {
        $ats_code = sanitize_input($_POST['ats_code']);
        
        if (empty($ats_code)) {
            $response['message'] = 'ATS code is required.';
        } else {
            $db = getDBConnection();
            $validation = verify_bond_exists($ats_code, $db);
            
            if ($validation['exists']) {
                $response['valid'] = true;
                $response['message'] = 'Bond validated successfully.';
                $response['bond'] = $validation['bond'];
            } else {
                $response['message'] = $validation['error'];
            }
        }
    }
    
    // ============ CHECK DUPLICATE BY MULTIPLE FIELDS (FALLBACK) ============
    elseif (isset($_POST['check_duplicate']) && $_POST['check_duplicate'] == '1') {
        $client_cds = sanitize_input($_POST['client_cds'] ?? '');
        $security_id = sanitize_input($_POST['security_id'] ?? '');
        $trade_date = sanitize_input($_POST['trade_date'] ?? '');
        $quantity = (float)($_POST['quantity'] ?? 0);
        $price = (float)($_POST['price'] ?? 0);
        $trade_side = sanitize_input($_POST['trade_side'] ?? '');
        $counterparty_name = sanitize_input($_POST['counterparty_name'] ?? '');
        
        if (empty($client_cds) || empty($security_id) || empty($trade_date)) {
            $response['message'] = 'Missing required fields for duplicate check.';
        } else {
            $db = getDBConnection();
            
            try {
                // Check for duplicate by multiple fields (used when Exchange Reference is not available)
                $stmt = $db->prepare("
                    SELECT COUNT(*) as count 
                    FROM trades 
                    WHERE client_cds_account = ? 
                    AND security_id = ? 
                    AND trade_date = ? 
                    AND quantity = ? 
                    AND price = ? 
                    AND trade_side = ?
                    AND counterparty_name = ?
                ");
                $stmt->execute([
                    $client_cds,
                    $security_id,
                    $trade_date,
                    $quantity,
                    $price,
                    $trade_side,
                    $counterparty_name
                ]);
                
                $count = $stmt->fetchColumn();
                
                if ($count > 0) {
                    $response['valid'] = false;
                    $response['message'] = 'Duplicate trade detected. This trade already exists in the system.';
                    $response['is_duplicate'] = true;
                } else {
                    $response['valid'] = true;
                    $response['message'] = 'Trade is unique.';
                    $response['is_duplicate'] = false;
                }
                
            } catch (Exception $e) {
                $response['message'] = 'Database error: ' . $e->getMessage();
            }
        }
    }
    
    // ============ VALIDATE BOND EXISTS BY SECURITY ID ============
    elseif (isset($_POST['security_id']) && !empty($_POST['security_id'])) {
        $security_id = sanitize_input($_POST['security_id']);
        $db = getDBConnection();
        
        try {
            $stmt = $db->prepare("SELECT * FROM bonds WHERE security_id = ? AND status = 'active'");
            $stmt->execute([$security_id]);
            $bond = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($bond) {
                $response['valid'] = true;
                $response['message'] = 'Bond found.';
                $response['bond'] = $bond;
            } else {
                $response['message'] = "Bond with Security ID '{$security_id}' not found.";
            }
        } catch (Exception $e) {
            $response['message'] = 'Database error: ' . $e->getMessage();
        }
    }
    
    // ============ VALIDATE CLIENT EXISTS ============
    elseif (isset($_POST['client_cds']) && !empty($_POST['client_cds'])) {
        $client_cds = sanitize_input($_POST['client_cds']);
        $db = getDBConnection();
        
        try {
            $stmt = $db->prepare("SELECT * FROM clients WHERE cds_account = ? AND is_active = 1");
            $stmt->execute([$client_cds]);
            $client = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($client) {
                $response['valid'] = true;
                $response['message'] = 'Client found.';
                $response['client'] = $client;
            } else {
                $response['message'] = "Client with CDS Account '{$client_cds}' not found.";
            }
        } catch (Exception $e) {
            $response['message'] = 'Database error: ' . $e->getMessage();
        }
    }
    
    // ============ GET CLIENT TRADES WITH EXCHANGE REFERENCES ============
    elseif (isset($_POST['get_client_trades']) && $_POST['get_client_trades'] == '1') {
        $client_cds = sanitize_input($_POST['client_cds'] ?? '');
        $limit = isset($_POST['limit']) ? (int)$_POST['limit'] : 50;
        
        if (empty($client_cds)) {
            $response['message'] = 'Client CDS account is required.';
        } else {
            $db = getDBConnection();
            
            try {
                $stmt = $db->prepare("
                    SELECT 
                        id,
                        trade_reference,
                        exchange_reference,
                        security_id,
                        asset_class,
                        trade_date,
                        quantity,
                        price,
                        consideration,
                        trade_side,
                        counterparty_name,
                        brokerage_fee_type,
                        final_brokerage_fee
                    FROM trades 
                    WHERE client_cds_account = ? 
                    AND status = 'active'
                    ORDER BY trade_date DESC, id DESC
                    LIMIT ?
                ");
                $stmt->execute([$client_cds, $limit]);
                $trades = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                $response['valid'] = true;
                $response['trades'] = $trades;
                $response['count'] = count($trades);
                $response['message'] = 'Found ' . count($trades) . ' trades.';
                
            } catch (Exception $e) {
                $response['message'] = 'Database error: ' . $e->getMessage();
            }
        }
    }
    
    // ============ CHECK BULK DUPLICATES ============
    elseif (isset($_POST['check_bulk_duplicates']) && $_POST['check_bulk_duplicates'] == '1') {
        $exchange_references = $_POST['exchange_references'] ?? [];
        
        if (empty($exchange_references)) {
            $response['message'] = 'No exchange references provided.';
        } else {
            $db = getDBConnection();
            $duplicates = [];
            $unique_refs = [];
            
            try {
                // Check each exchange reference
                foreach ($exchange_references as $ref) {
                    $ref = sanitize_input($ref);
                    if (empty($ref)) continue;
                    
                    $stmt = $db->prepare("
                        SELECT COUNT(*) as count FROM trades WHERE exchange_reference = ?
                        UNION ALL
                        SELECT COUNT(*) as count FROM etf_trades WHERE exchange_reference = ?
                        UNION ALL
                        SELECT COUNT(*) as count FROM custodians_trades WHERE exchange_reference = ?
                    ");
                    $stmt->execute([$ref, $ref, $ref]);
                    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    $total_count = 0;
                    foreach ($results as $row) {
                        $total_count += $row['count'];
                    }
                    
                    if ($total_count > 0) {
                        $duplicates[] = $ref;
                    } else {
                        $unique_refs[] = $ref;
                    }
                }
                
                $response['valid'] = true;
                $response['duplicates'] = $duplicates;
                $response['unique'] = $unique_refs;
                $response['duplicate_count'] = count($duplicates);
                $response['unique_count'] = count($unique_refs);
                $response['message'] = 'Checked ' . count($exchange_references) . ' references. ' . count($duplicates) . ' duplicates found.';
                
            } catch (Exception $e) {
                $response['message'] = 'Database error: ' . $e->getMessage();
            }
        }
    }
    
    // ============ DEFAULT RESPONSE ============
    else {
        $response['message'] = 'No valid action specified.';
    }
}

header('Content-Type: application/json');
echo json_encode($response);
?>