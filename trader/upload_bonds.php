<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Start output buffering to catch any accidental output
ob_start();

// Include configuration files
require_once '../config/config.php';
require_once '../config/account_mapping.php';
require_once '../auth/auth_middleware.php';

require_login();
require_mandate();
$current_user = get_logged_in_user();
$db = getDBConnection();

$success_message = '';
$error_message = '';
$preview_data = [];
$has_errors = false;

// Initialize variables
$is_custodian_trade = false;
$processed = 0;
$financial_entries_created = 0;
$custodian_trades_processed = 0;
$custodian_trades_recorded = 0;
$regulatory_assignments_created = 0;
$client_trades_skipped = 0; // Track skipped client trades

// =====================================================
// UPDATED: Generate a unique T+5 alphanumeric reference for bonds WITHOUT Exchange Reference
// =====================================================
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

// =====================================================
// UPDATED: Check for duplicate trade using trade_reference
// =====================================================
function isDuplicateTrade($db, $trade_reference) {
    try {
        if (empty($trade_reference)) {
            return false;
        }
        
        // Check in trades table
        $stmt = $db->prepare("SELECT COUNT(*) as count FROM trades WHERE trade_reference = ?");
        $stmt->execute([$trade_reference]);
        $count_trades = $stmt->fetch()['count'];
        
        // Check in etf_trades table
        $stmt = $db->prepare("SELECT COUNT(*) as count FROM etf_trades WHERE trade_reference = ?");
        $stmt->execute([$trade_reference]);
        $count_etf = $stmt->fetch()['count'];
        
        // Check in custodians_trades table
        $stmt = $db->prepare("SELECT COUNT(*) as count FROM custodians_trades WHERE trade_reference = ?");
        $stmt->execute([$trade_reference]);
        $count_custodians = $stmt->fetch()['count'];
        
        $total_count = $count_trades + $count_etf + $count_custodians;
        
        if ($total_count > 0) {
            error_log("Duplicate trade detected by trade_reference: {$trade_reference}");
            return true;
        }
        
        return false;
        
    } catch (Exception $e) {
        error_log("Error checking duplicate trade: " . $e->getMessage());
        return false;
    }
}

// =====================================================
// UPDATED: Get trade reference - use Exchange Reference from CSV OR generate new
// =====================================================
function getTradeReference($db, $exchange_reference) {
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
            error_log("Using Exchange Reference as trade reference: {$exchange_reference}");
            return $exchange_reference;
        } else {
            error_log("Exchange Reference '{$exchange_reference}' already exists. Generating new reference.");
        }
    }
    
    // If no Exchange Reference or it already exists, generate a new T+5 reference
    $new_reference = generateBondTradeReference($db);
    error_log("Generated new T+5 reference: {$new_reference}");
    return $new_reference;
}

// NEW: Function to check and insert client if not exists
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

// NEW: Function to check and insert bond if not exists
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
        $currency = 'USD';
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

/**
 * Get company details from companies table
 */
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

/**
 * Check if trade is through custodian
 */
function isCustodianTrade($sca_code, $company_code) {
    return !empty($sca_code) && $sca_code !== $company_code;
}

// =====================================================
// REMOVED: getAccountIdByCode() - Already in account_mapping.php
// REMOVED: findAccountForTransaction() - Already in account_mapping.php
// =====================================================

/**
 * Simple general ledger entry function
 */
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
            $entry_id = $db->lastInsertId();
            error_log("GL entry #{$entry_id} created: {$description} - Debit: {$debit} Credit: {$credit}");
            return $entry_id;
        }
        
        error_log("Failed to insert GL entry: " . implode(', ', $stmt->errorInfo()));
        return false;
        
    } catch (Exception $e) {
        error_log("Error recording GL entry: " . $e->getMessage());
        return false;
    }
}

/**
 * Insert regulatory fees into assignments table for accountant review
 */
function recordRegulatoryFeeAssignment($db, $trade_reference, $fees, $client_name, $trade_date, $security_id, $security_name, $consideration, $trade_side, $created_by) {
    try {
        $check_stmt = $db->query("SHOW COLUMNS FROM regulatory_fee_assignments LIKE 'dismissed_by'");
        $has_dismissed_by = $check_stmt->rowCount() > 0;
        
        if ($has_dismissed_by) {
            $stmt = $db->prepare("
                INSERT INTO regulatory_fee_assignments 
                (trade_reference, client_name, security_id, security_name, trade_date, 
                 dse_fee, cmsa_fee, csd_fee, total_fees, consideration, trade_side,
                 status, treatment_type, created_by, created_at, updated_at,
                 dismissed_by, dismissed_at, dismissed_reason, is_dismissed)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', NULL, ?, NOW(), NOW(), NULL, NULL, NULL, 0)
            ");
        } else {
            $stmt = $db->prepare("
                INSERT INTO regulatory_fee_assignments 
                (trade_reference, client_name, security_id, security_name, trade_date, 
                 dse_fee, cmsa_fee, csd_fee, total_fees, consideration, trade_side,
                 status, treatment_type, created_by, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', NULL, ?, NOW(), NOW())
            ");
        }
        
        $dse_fee = $fees['dse'] ?? 0;
        $cmsa_fee = $fees['cmsa'] ?? 0;
        $csd_fee = $fees['csd'] ?? 0;
        $total_fees = $dse_fee + $cmsa_fee + $csd_fee;
        
        if ($has_dismissed_by) {
            $result = $stmt->execute([
                $trade_reference,
                substr($client_name, 0, 255),
                substr($security_id, 0, 50),
                substr($security_name, 0, 200),
                $trade_date,
                round($dse_fee, 2),
                round($cmsa_fee, 2),
                round($csd_fee, 2),
                round($total_fees, 2),
                round($consideration, 2),
                $trade_side,
                $created_by
            ]);
        } else {
            $result = $stmt->execute([
                $trade_reference,
                substr($client_name, 0, 255),
                substr($security_id, 0, 50),
                substr($security_name, 0, 200),
                $trade_date,
                round($dse_fee, 2),
                round($cmsa_fee, 2),
                round($csd_fee, 2),
                round($total_fees, 2),
                round($consideration, 2),
                $trade_side,
                $created_by
            ]);
        }
        
        if ($result) {
            error_log("Successfully inserted regulatory fee assignment for bond trade: {$trade_reference}");
        } else {
            error_log("Failed to insert regulatory fee assignment for bond trade: {$trade_reference}");
        }
        
        return $result;
        
    } catch (Exception $e) {
        error_log("Error recording regulatory fee assignment for bond: " . $e->getMessage());
        return false;
    }
}

// =====================================================
// Record company's own bond investment to Marketable Securities - Bonds (1152)
// NO CASH AT BANK - Uses only 1152
// =====================================================
function recordCompanyBondInvestment($db, $trade_reference, $trade_side, $consideration, $client_name, $trade_date) {
    try {
        $investment_account = getAccountIdByCode($db, '1152');
        $entries_created = 0;
        
        if ($trade_side === 'buy') {
            $entry1 = recordGeneralLedgerEntry(
                $db, $trade_date, $investment_account,
                $consideration, 0,
                "Company bond purchase - Marketable Securities (1152) - {$trade_reference} - {$client_name}",
                $trade_reference, 'company_investment'
            );
            if ($entry1) $entries_created++;
        } else {
            $entry1 = recordGeneralLedgerEntry(
                $db, $trade_date, $investment_account,
                0, $consideration,
                "Company bond sold - Marketable Securities (1152) - {$trade_reference} - {$client_name}",
                $trade_reference, 'company_investment'
            );
            if ($entry1) $entries_created++;
        }
        
        return $entries_created > 0;
        
    } catch (Exception $e) {
        error_log("Error recording company bond investment: " . $e->getMessage());
        return false;
    }
}

// =====================================================
// Bond Accounting - NO CASH AT BANK
// Uses: 411 (Brokerage Income), 213 (VAT), 2111 (CMSA), 2112 (DSE), 2113 (CSDR)
// =====================================================
function createBondAccountingEntries($db, $trade_reference, $consideration, $fees, $trade_side, $client_name, $company_name, $trade_date, $is_custodian_trade = false) {
    try {
        $entries_created = 0;
        
        $brokerage_income = getAccountIdByCode($db, '411');
        $vat_payable = getAccountIdByCode($db, '213');
        $cmsa_payable = getAccountIdByCode($db, '2111');
        $dse_payable = getAccountIdByCode($db, '2112');
        $csdr_payable = getAccountIdByCode($db, '2113');
        
        $brokerage_fee = $fees['brokerage'] ?? 0;
        $vat_fee = $fees['vat'] ?? 0;
        $cmsa_fee = $fees['cmsa'] ?? 0;
        $dse_fee = $fees['dse'] ?? 0;
        $csd_fee = $fees['csd'] ?? 0;
        $csdr_fee = $fees['csdr'] ?? $csd_fee;
        
        // 1. Credit Brokerage Income (411)
        if ($brokerage_fee > 0 && !isGLDuplicateEntry($db, $trade_reference, '411', 'Bond brokerage income')) {
            $entry = recordGeneralLedgerEntry(
                $db, $trade_date, $brokerage_income,
                0, $brokerage_fee,
                "Bond brokerage income - {$trade_reference} - {$client_name}",
                $trade_reference, 'fee'
            );
            if ($entry) $entries_created++;
        }
        
        // 2. Credit VAT Payable (213)
        if ($vat_fee > 0 && !isGLDuplicateEntry($db, $trade_reference, '213', 'VAT on bond brokerage')) {
            $entry = recordGeneralLedgerEntry(
                $db, $trade_date, $vat_payable,
                0, $vat_fee,
                "VAT on bond brokerage - {$trade_reference}",
                $trade_reference, 'fee'
            );
            if ($entry) $entries_created++;
        }
        
        // 3. Credit CMSA Payable (2111)
        if ($cmsa_fee > 0 && !isGLDuplicateEntry($db, $trade_reference, '2111', 'CMSA fees payable')) {
            $entry = recordGeneralLedgerEntry(
                $db, $trade_date, $cmsa_payable,
                0, $cmsa_fee,
                "CMSA fees payable - {$trade_reference}",
                $trade_reference, 'fee'
            );
            if ($entry) $entries_created++;
        }
        
        // 4. Credit DSE Payable (2112)
        if ($dse_fee > 0 && !isGLDuplicateEntry($db, $trade_reference, '2112', 'DSE fees payable')) {
            $entry = recordGeneralLedgerEntry(
                $db, $trade_date, $dse_payable,
                0, $dse_fee,
                "DSE fees payable - {$trade_reference}",
                $trade_reference, 'fee'
            );
            if ($entry) $entries_created++;
        }
        
        // 5. Credit CSDR Payable (2113)
        if ($csdr_fee > 0 && !isGLDuplicateEntry($db, $trade_reference, '2113', 'CSDR fees payable')) {
            $entry = recordGeneralLedgerEntry(
                $db, $trade_date, $csdr_payable,
                0, $csdr_fee,
                "CSDR fees payable - {$trade_reference}",
                $trade_reference, 'fee'
            );
            if ($entry) $entries_created++;
        }
        
        error_log("Created {$entries_created} accounting entries for bond trade {$trade_reference}");
        return $entries_created > 0;
        
    } catch (Exception $e) {
        error_log("Error creating bond accounting entries: " . $e->getMessage());
        return false;
    }
}

/**
 * Post bond charges to payable accounts
 */
function postBondChargesToPayables($db, $trade_reference, $fees, $trade_date) {
    try {
        $entries_created = 0;
        
        $charge_map = [
            'cmsa' => ['key' => 'cmsa', 'desc' => 'CMSA', 'code' => '2111'],
            'dse'  => ['key' => 'dse', 'desc' => 'DSE', 'code' => '2112'],
            'csd'  => ['key' => 'csdr', 'desc' => 'CSDR', 'code' => '2113'],
        ];
        
        foreach ($charge_map as $fee_key => $config) {
            $amount = $fees[$fee_key] ?? 0;
            if ($amount <= 0) continue;
            
            $account_id = getAccountIdByCode($db, $config['code']);
            $desc = $config['desc'] . " fees payable - {$trade_reference}";
            
            if (!isGLDuplicateEntry($db, $trade_reference, $config['code'], $desc)) {
                if (recordGeneralLedgerEntry($db, $trade_date, $account_id, 0, $amount, $desc, $trade_reference, 'fee')) {
                    $entries_created++;
                }
            }
        }
        
        return $entries_created;
        
    } catch (Exception $e) {
        error_log("Error posting bond charges to payables: " . $e->getMessage());
        return 0;
    }
}

/**
 * Calculate custodian fees
 */
function calculateCustodianFees($fees) {
    $brokerage_fees = ($fees['brokerage'] ?? 0) + ($fees['vat'] ?? 0);
    $other_fees = ($fees['cmsa'] ?? 0) + ($fees['csd'] ?? 0) + ($fees['dse'] ?? 0);
    $total_fees = $brokerage_fees + $other_fees;
    
    return [
        'brokerage_fees' => round($brokerage_fees, 2),
        'other_fees' => round($other_fees, 2),
        'total_fees' => round($total_fees, 2)
    ];
}

/**
 * Record custodian trade
 */
function recordCustodianTrade($db, $trade_data) {
    try {
        $stmt = $db->prepare("
            INSERT INTO custodians_trades 
            (trade_reference, custodian_code, custodian_name, asset_class, security_id, security_name,
             client_cds_account, client_name, trade_side, quantity, price, consideration,
             trade_date, settlement_date, brokerage_fees, other_fees, total_fees, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        
        $result = $stmt->execute([
            $trade_data['trade_reference'],
            $trade_data['custodian_code'],
            $trade_data['custodian_name'],
            $trade_data['asset_class'],
            $trade_data['security_id'],
            $trade_data['security_name'],
            $trade_data['client_cds_account'],
            $trade_data['client_name'],
            $trade_data['trade_side'],
            $trade_data['quantity'],
            $trade_data['price'],
            $trade_data['consideration'],
            $trade_data['trade_date'],
            $trade_data['settlement_date'],
            $trade_data['brokerage_fees'],
            $trade_data['other_fees'],
            $trade_data['total_fees']
        ]);
        
        return $result;
        
    } catch (Exception $e) {
        error_log("Error recording custodian trade: " . $e->getMessage());
        return false;
    }
}

/**
 * Generate Trial Balance using hierarchical accounts
 */
function generateTrialBalance($db, $period_date = null) {
    try {
        if (!$period_date) {
            $period_date = date('Y-m-d');
        }
        
        $db->beginTransaction();
        
        $delete_stmt = $db->prepare("DELETE FROM trial_balance WHERE period_date = ? AND is_closing = 0");
        $delete_stmt->execute([$period_date]);
        
        $stmt = $db->prepare("
            SELECT 
                coa.id as account_id,
                coa.account_code,
                coa.account_name,
                coa.account_type,
                coa.normal_balance,
                COALESCE(SUM(gl.debit_amount), 0) as total_debit,
                COALESCE(SUM(gl.credit_amount), 0) as total_credit,
                CASE 
                    WHEN coa.normal_balance = 'debit' THEN 
                        (COALESCE(SUM(gl.debit_amount), 0) - COALESCE(SUM(gl.credit_amount), 0))
                    ELSE 
                        (COALESCE(SUM(gl.credit_amount), 0) - COALESCE(SUM(gl.debit_amount), 0))
                END as balance,
                CASE 
                    WHEN coa.normal_balance = 'debit' AND (COALESCE(SUM(gl.debit_amount), 0) - COALESCE(SUM(gl.credit_amount), 0)) > 0 THEN 
                        (COALESCE(SUM(gl.debit_amount), 0) - COALESCE(SUM(gl.credit_amount), 0))
                    ELSE 0
                END as debit_balance,
                CASE 
                    WHEN coa.normal_balance = 'credit' AND (COALESCE(SUM(gl.credit_amount), 0) - COALESCE(SUM(gl.debit_amount), 0)) > 0 THEN 
                        (COALESCE(SUM(gl.credit_amount), 0) - COALESCE(SUM(gl.debit_amount), 0))
                    ELSE 0
                END as credit_balance
            FROM chart_of_accounts coa
            LEFT JOIN general_ledger gl ON coa.id = gl.account_id 
                AND gl.transaction_date <= ?
            WHERE coa.is_active = 1
            GROUP BY coa.id, coa.account_code, coa.account_name, coa.account_type, coa.normal_balance
            ORDER BY coa.account_code
        ");
        
        $stmt->execute([$period_date]);
        $accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $total_debits = 0;
        $total_credits = 0;
        $entries_created = 0;
        
        foreach ($accounts as $account) {
            $debit_balance = round((float)$account['debit_balance'], 2);
            $credit_balance = round((float)$account['credit_balance'], 2);
            
            $insert_stmt = $db->prepare("
                INSERT INTO trial_balance 
                (period_date, account_id, account_code, account_name, debit_balance, credit_balance, created_at)
                VALUES (?, ?, ?, ?, ?, ?, NOW())
            ");
            
            $insert_stmt->execute([
                $period_date,
                $account['account_id'],
                $account['account_code'],
                $account['account_name'],
                $debit_balance,
                $credit_balance
            ]);
            
            $total_debits += $debit_balance;
            $total_credits += $credit_balance;
            $entries_created++;
        }
        
        $db->commit();
        
        $is_balanced = abs($total_debits - $total_credits) < 0.01;
        
        return [
            'success' => true,
            'entries_created' => $entries_created,
            'total_debits' => $total_debits,
            'total_credits' => $total_credits,
            'is_balanced' => $is_balanced
        ];
        
    } catch (Exception $e) {
        $db->rollBack();
        error_log("Error generating trial balance: " . $e->getMessage());
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

// Get company details
$company_details = getCompanyDetails($db);
$company_code = $company_details['company_code'];
$company_name = $company_details['company_name'];

// Function to safely format numbers
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

function getDefaultAccountId($account_code) {
    $default_mapping = [
        '1001' => 1, '1008' => 2, '1009' => 3, '1010' => 4, '1011' => 5,
        '1012' => 6, '1013' => 7, '1014' => 8, '1015' => 9, '1016' => 10,
        '2001' => 11, '2002' => 12, '3007' => 13, '3008' => 14, '3009' => 15,
        '3010' => 16, '3011' => 17, '3012' => 18, '3013' => 19, '3014' => 20,
        '4001' => 21, '4002' => 22, '4003' => 23, '4004' => 24, '4005' => 25,
        '5001' => 26, '5002' => 27, '5003' => 28, '6001' => 29, '6002' => 30,
        '411' => 31, '213' => 32, '2111' => 33, '2112' => 34, '2113' => 35, '2114' => 36,
        '1151' => 37, '1152' => 38, '4101' => 39, '3001' => 40, '3002' => 41,
        '3003' => 42, '3004' => 43, '3005' => 44, '3006' => 45,
    ];
    return $default_mapping[$account_code] ?? 1;
}

function getAccountIdByCode($db, $account_code) {
    try {
        $stmt = $db->prepare("SELECT id, account_code, account_name FROM chart_of_accounts WHERE account_code = ? AND is_active = 1");
        $stmt->execute([$account_code]);
        $account = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$account) {
            return getDefaultAccountId($account_code);
        }
        return $account['id'];
    } catch (Exception $e) {
        return getDefaultAccountId($account_code);
    }
}

// Function to calculate bond fees based on your charges structure
function calculateBondFees($quantity, $price, $consideration) {
    $fees = [];
    $face_value = $quantity;
    
    $brokerage_first_100m = min($face_value, 100000000) * (0.063132 / 100);
    $brokerage_excess = max($face_value - 100000000, 0) * (0.035 / 100);
    $fees['brokerage'] = $brokerage_first_100m + $brokerage_excess;
    $fees['vat'] = $fees['brokerage'] * 0.18;
    $fees['cmsa'] = $consideration * (0.01 / 100);
    $fees['csd'] = $face_value * (0.0118 / 100);
    $fees['dse'] = $face_value * (0.02006 / 100);
    $fees['brokerage_vat_total'] = $fees['brokerage'] + $fees['vat'];
    $fees['regulatory_total'] = $fees['cmsa'] + $fees['csd'] + $fees['dse'];
    
    error_log("Bond fees calculated: Brokerage={$fees['brokerage']}, VAT={$fees['vat']}, CMSA={$fees['cmsa']}, CSD={$fees['csd']}, DSE={$fees['dse']}");
    
    return $fees;
}

// Map CSV row to database with support for YYYY/MM/DD, MM/DD/YYYY, YYYY-MM-DD
function mapCSVRowToDatabaseBond($row) {
    $trimmed_row = [];
    foreach ($row as $key => $value) {
        $trimmed_row[$key] = is_string($value) ? trim($value) : $value;
    }
    
    $trade_date = '';
    if (!empty($trimmed_row['Trade Date'])) {
        $date_str = trim($trimmed_row['Trade Date']);
        
        if (preg_match('/^(\d{4})\/(\d{1,2})\/(\d{1,2})$/', $date_str, $matches)) {
            $year = $matches[1];
            $month = str_pad($matches[2], 2, '0', STR_PAD_LEFT);
            $day = str_pad($matches[3], 2, '0', STR_PAD_LEFT);
            $trade_date = $year . '-' . $month . '-' . $day;
        } elseif (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $date_str, $matches)) {
            $month = str_pad($matches[1], 2, '0', STR_PAD_LEFT);
            $day = str_pad($matches[2], 2, '0', STR_PAD_LEFT);
            $year = $matches[3];
            $trade_date = $year . '-' . $month . '-' . $day;
        } elseif (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_str)) {
            $trade_date = $date_str;
        } elseif (preg_match('/^\d{8}$/', $date_str)) {
            $trade_date = substr($date_str, 0, 4) . '-' . substr($date_str, 4, 2) . '-' . substr($date_str, 6, 2);
        } else {
            error_log("WARNING: Unrecognized Trade Date format: {$date_str}");
        }
    }
    
    $settlement_date = '';
    if (!empty($trimmed_row['Settlement Date'])) {
        $date_str = trim($trimmed_row['Settlement Date']);
        
        if (preg_match('/^(\d{4})\/(\d{1,2})\/(\d{1,2})$/', $date_str, $matches)) {
            $year = $matches[1];
            $month = str_pad($matches[2], 2, '0', STR_PAD_LEFT);
            $day = str_pad($matches[3], 2, '0', STR_PAD_LEFT);
            $settlement_date = $year . '-' . $month . '-' . $day;
        } elseif (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $date_str, $matches)) {
            $month = str_pad($matches[1], 2, '0', STR_PAD_LEFT);
            $day = str_pad($matches[2], 2, '0', STR_PAD_LEFT);
            $year = $matches[3];
            $settlement_date = $year . '-' . $month . '-' . $day;
        } elseif (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_str)) {
            $settlement_date = $date_str;
        } elseif (preg_match('/^\d{8}$/', $date_str)) {
            $settlement_date = substr($date_str, 0, 4) . '-' . substr($date_str, 4, 2) . '-' . substr($date_str, 6, 2);
        } else {
            error_log("WARNING: Unrecognized Settlement Date format: {$date_str}");
        }
    }
    
    $maturity_date = '';
    if (!empty($trimmed_row['Maturity Date'])) {
        $date_str = trim($trimmed_row['Maturity Date']);
        if (preg_match('/^(\d{4})\/(\d{1,2})\/(\d{1,2})$/', $date_str, $matches)) {
            $year = $matches[1];
            $month = str_pad($matches[2], 2, '0', STR_PAD_LEFT);
            $day = str_pad($matches[3], 2, '0', STR_PAD_LEFT);
            $maturity_date = $year . '-' . $month . '-' . $day;
        } elseif (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $date_str, $matches)) {
            $month = str_pad($matches[1], 2, '0', STR_PAD_LEFT);
            $day = str_pad($matches[2], 2, '0', STR_PAD_LEFT);
            $year = $matches[3];
            $maturity_date = $year . '-' . $month . '-' . $day;
        }
    }
    
    $client_name = $trimmed_row['Name'] ?? 
                   $trimmed_row['Client Name'] ?? 
                   $trimmed_row['Main Principal'] ?? 
                   $trimmed_row['Principal'] ?? '';
    
    $client_cds = $trimmed_row['CSD Account'] ?? 
                  $trimmed_row['CDS Account'] ?? '';
    
    error_log("Bond Date Mapping - Trade Date: {$trade_date}, Settlement Date: {$settlement_date}, Maturity Date: {$maturity_date}");
    
    return [
        'security_id' => $trimmed_row['Security'] ?? '',
        'bond_name' => $trimmed_row['Security'] ?? 'Unknown Bond',
        'client_name' => $client_name,
        'client_cds' => $client_cds,
        'trade_side' => strtolower(trim($trimmed_row['Buy\Sell'] ?? '')),
        'quantity' => $trimmed_row['Quantity'] ?? 0,
        'price' => $trimmed_row['Price'] ?? 0,
        'sca_code' => $trimmed_row['SCA Code'] ?? '',
        'trade_date' => $trade_date ?: date('Y-m-d'),
        'settlement_date' => $settlement_date ?: date('Y-m-d', strtotime('+2 days')),
        'maturity_date' => $maturity_date,
        'consideration' => $trimmed_row['Consideration'] ?? 0,
        'counterparty_name' => $trimmed_row['Counterparty Name'] ?? '',
        'counterparty_cds' => $trimmed_row['Counterparty CSD Account'] ?? '',
        'capacity' => $trimmed_row['Capacity'] ?? 'principal',
        'broker_name' => $trimmed_row['Broker'] ?? '',
        'counterparty_broker' => $trimmed_row['Counterparty'] ?? '',
        'exchange_reference' => $trimmed_row['Exchange Reference'] ?? '',
        'origin' => $trimmed_row['Origin'] ?? '',
        'time_executed' => $trimmed_row['Time'] ?? '',
        'asset_class' => $trimmed_row['Asset Class'] ?? '',
        'isin' => $trimmed_row['ISIN'] ?? ''
    ];
}

// Function to validate bond data
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
        'trade_side' => 'Buy\Sell',
        'quantity' => 'Quantity',
        'price' => 'Price',
        'sca_code' => 'SCA Code'
    ];
    
    foreach ($required_fields as $field => $column_name) {
        $value = $mapped_data[$field];
        if (empty($value) || (is_string($value) && trim($value) === '')) {
            $errors[] = "{$column_name} is required";
        }
    }
    
    $trade_side = strtolower(trim($mapped_data['trade_side']));
    if (!in_array($trade_side, ['buy', 'sell'])) {
        $errors[] = "Buy\Sell must be either 'Buy' or 'Sell' (case insensitive)";
    }
    
    $numeric_fields = ['quantity', 'price', 'consideration'];
    foreach ($numeric_fields as $field) {
        $value = $mapped_data[$field];
        $trimmed_value = is_string($value) ? trim($value) : $value;
        
        if (!empty($trimmed_value) && !is_numeric($trimmed_value)) {
            $errors[] = ucfirst($field) . " must be a number";
        } elseif ($trimmed_value < 0) {
            $errors[] = ucfirst($field) . " cannot be negative";
        }
    }
    
    $sca_code = $mapped_data['sca_code'];
    if (!empty($sca_code)) {
        $company_stmt = $db->prepare("SELECT COUNT(*) as count FROM companies WHERE company_code = ? AND is_active = 1");
        $company_stmt->execute([$sca_code]);
        $company_exists = $company_stmt->fetch()['count'] > 0;
        
        $custodian_stmt = $db->prepare("SELECT COUNT(*) as count FROM custodians WHERE custodian_code = ?");
        $custodian_stmt->execute([$sca_code]);
        $custodian_exists = $custodian_stmt->fetch()['count'] > 0;
        
        if (!$company_exists && !$custodian_exists) {
            $errors[] = "SCA Code not found in database: '{$sca_code}'. Must be a valid company or custodian code.";
        }
    }
    
    // Check for duplicate using trade_reference (Exchange Reference from CSV)
    $exchange_reference = $mapped_data['exchange_reference'];
    if (!empty($exchange_reference)) {
        if (isDuplicateTrade($db, $exchange_reference)) {
            $errors[] = "DUPLICATE: Trade Reference '{$exchange_reference}' already exists in the system. This trade will be skipped.";
        }
    }
    
    return $errors;
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

// CSV parsing and validation functions
function validateUploadedFile($file) {
    $errors = [];
    $max_size = 5 * 1024 * 1024;
    
    if ($file['size'] > $max_size) {
        $errors[] = "File size exceeds 5MB limit";
    }
    
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $errors[] = "File upload error: " . $file['error'];
    }
    
    return $errors;
}

function parseCSVSecurely($file_path) {
    $rows = [];
    
    if (!file_exists($file_path)) {
        throw new Exception("File not found: $file_path");
    }
    
    if (($handle = fopen($file_path, "r")) !== FALSE) {
        try {
            $first_line = fgets($handle);
            rewind($handle);
            
            $delimiter = ',';
            if (strpos($first_line, "\t") !== false) {
                $delimiter = "\t";
                error_log("Detected TAB delimiter in bond CSV file");
            } elseif (strpos($first_line, ';') !== false) {
                $delimiter = ';';
                error_log("Detected SEMICOLON delimiter in bond CSV file");
            } else {
                error_log("Using COMMA delimiter in bond CSV file");
            }
            
            $header = fgetcsv($handle, 0, $delimiter, '"', '\\');
            if ($header === FALSE) {
                throw new Exception("Could not read CSV header");
            }
            
            $header = array_map('trim', $header);
            error_log("Bond CSV Headers found: " . implode(' | ', $header));
            
            $line_number = 1;
            while (($data = fgetcsv($handle, 0, $delimiter, '"', '\\')) !== FALSE) {
                $line_number++;
                
                if ($data === [null] || $data === [] || (count($data) === 1 && trim($data[0]) === '')) {
                    continue;
                }
                
                $data = array_map('trim', $data);
                
                $non_empty_count = count(array_filter($data, function($value) {
                    return $value !== '';
                }));
                if ($non_empty_count === 0) {
                    continue;
                }
                
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
                        $error_message = "No bond data found in the uploaded file. Please ensure the CSV contains rows with 'Asset Class' set to 'Bond'.";
                    } else {
                        $preview_data = [];
                        $line_number = 2;
                        
                        foreach ($bond_rows as $row) {
                            $mapped_data = mapCSVRowToDatabaseBond($row);
                            $is_company_trade = (trim(strtolower($mapped_data['client_name'])) === trim(strtolower($company_name)));
                            $is_custodian_trade = isCustodianTrade($mapped_data['sca_code'], $company_code);
                            
                            // =====================================================
                            // UPDATED: Use Exchange Reference from CSV as trade_reference
                            // =====================================================
                            $exchange_reference = $mapped_data['exchange_reference'];
                            $trade_reference = getTradeReference($db, $exchange_reference);
                            
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
                            
                            $is_dup = false;
                            $dup_ref = '';
                            foreach ($validation_errors as $err) {
                                if (strpos($err, 'DUPLICATE:') !== false) {
                                    $is_dup = true;
                                    preg_match("/Trade Reference '([^']+)'/", $err, $matches);
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
                        
                        $has_real_errors = false;
                        foreach ($errors as $err) {
                            if (strpos($err, 'DUPLICATE:') === false) {
                                $has_real_errors = true;
                                break;
                            }
                        }
                        
                        if (!$has_real_errors && !empty($preview_data)) {
                            error_log("=== BOND UPLOAD DEBUG ===");
                            error_log("Starting transaction for " . count($preview_data) . " bond trades");
                            
                            $db->beginTransaction();
                            try {
                                $processed = 0;
                                $financial_entries_created = 0;
                                $custodian_trades_processed = 0;
                                $custodian_trades_recorded = 0;
                                $regulatory_assignments_created = 0;
                                $bonds_auto_created = 0;
                                $company_investments_recorded = 0;
                                $client_trades_skipped = 0;
                                $duplicates_skipped = 0;
                                $duplicate_references = [];
                                
                                foreach ($preview_data as $preview_row) {
                                    // Check if this is a duplicate
                                    if ($preview_row['is_duplicate']) {
                                        $duplicates_skipped++;
                                        $duplicate_references[] = $preview_row['trade_reference'];
                                        error_log("Skipping duplicate bond trade: " . $preview_row['trade_reference']);
                                        continue;
                                    }
                                    
                                    $mapped_data = $preview_row['mapped_data'];
                                    $trade_reference = $preview_row['trade_reference'];
                                    
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
                                    
                                    error_log("Trade {$trade_reference}: Trade Date: {$trade_date}, Settlement Date: {$settlement_date}");
                                    
                                    if (!empty($client_cds) && !empty($client_name)) {
                                        checkAndInsertClient($db, $client_cds, $client_name, $current_user['username'] ?? 'system');
                                    }
                                    
                                    $bond_details = checkAndInsertBond($db, $security_id, $mapped_data['bond_name'], $trade_date, $current_user['username'] ?? 'system');
                                    
                                    if ($bond_details && $bond_details['was_created']) {
                                        $bonds_auto_created++;
                                        error_log("Bond auto-created: {$security_id}");
                                    }
                                    
                                    $bond_name_to_use = $bond_details ? $bond_details['bond_name'] : $security_id;
                                    
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
                                    
                                    // Insert trade record (using Exchange Reference as trade_reference)
                                    $trade_insert_stmt = $db->prepare("
                                        INSERT INTO trades (
                                            trade_reference, asset_class, security_id, security_name,
                                            client_cds_account, client_name, 
                                            counterparty_name, counterparty_cds_account,
                                            trade_side, quantity, price, consideration,
                                            trade_date, settlement_date, currency, sca_code, status, uploaded_by,
                                            capacity, broker_name, counterparty_broker,
                                            brokerage_fee_type, final_brokerage_fee,
                                            exchange_reference, time_executed, origin
                                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                                    ");

                                    $counterparty_name = $mapped_data['counterparty_name'] ?? 'Unknown';
                                    $counterparty_cds = $mapped_data['counterparty_cds'] ?? '';
                                    $broker_name = $mapped_data['broker_name'] ?? '';
                                    $counterparty_broker = $mapped_data['counterparty_broker'] ?? '';
                                    $capacity = $mapped_data['capacity'] ?? 'principal';
                                    
                                    $trade_insert_stmt->execute([
                                        $trade_reference, 
                                        'bond', 
                                        substr($security_id, 0, 50),
                                        substr($bond_name_to_use, 0, 100),
                                        substr($client_cds, 0, 50),
                                        substr($client_name, 0, 100),
                                        substr($counterparty_name, 0, 100),
                                        substr($counterparty_cds, 0, 50),
                                        $trade_side, 
                                        $quantity, 
                                        $price, 
                                        $consideration,
                                        $trade_date, 
                                        $settlement_date, 
                                        'TZS', 
                                        substr($sca_code, 0, 20),
                                        'active', 
                                        $current_user['id'],
                                        $capacity,
                                        substr($broker_name, 0, 100),
                                        substr($counterparty_broker, 0, 100),
                                        $brokerage_fee_type,
                                        round($final_brokerage_fee_amount, 2),
                                        $trade_reference, // Exchange Reference goes into exchange_reference column
                                        $mapped_data['time_executed'] ?? null,
                                        $mapped_data['origin'] ?? null
                                    ]);
                                    
                                    error_log("Successfully inserted bond trade: {$trade_reference}");
                                    
                                    // =====================================================
                                    // ONLY Company trades go to Marketable Securities - Bonds (1152)
                                    // Client trades are SKIPPED - will be entered manually via receipts
                                    // =====================================================
                                    if ($consideration > 0) {
                                        if ($is_company_trade) {
                                            if (recordCompanyBondInvestment($db, $trade_reference, $trade_side, $consideration, $client_name, $trade_date)) {
                                                $company_investments_recorded++;
                                                error_log("Company bond recorded to Marketable Securities (1152): {$trade_reference}");
                                            }
                                        } else {
                                            $client_trades_skipped++;
                                            error_log("Client bond trade skipped (manual receipt entry needed): {$trade_reference} - {$client_name}");
                                        }
                                    }
                                    
                                    // Fees are still recorded for ALL trades
                                    if ($consideration > 0) {
                                        $fees = calculateBondFees($quantity, $price, $consideration);
                                        
                                        if (($fees['dse'] > 0 || $fees['cmsa'] > 0 || $fees['csd'] > 0)) {
                                            $assignment_result = recordRegulatoryFeeAssignment(
                                                $db,
                                                $trade_reference,
                                                $fees,
                                                $client_name,
                                                $trade_date,
                                                $security_id,
                                                $bond_name_to_use,
                                                $consideration,
                                                $trade_side,
                                                $current_user['username'] ?? 'system'
                                            );
                                            
                                            if ($assignment_result) {
                                                $regulatory_assignments_created++;
                                            }
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
                                                    'total_fees' => $custodian_fees['total_fees']
                                                ];
                                                
                                                if (recordCustodianTrade($db, $custodian_trade_data)) {
                                                    $custodian_trades_recorded++;
                                                }
                                            }
                                        }
                                    }
                                    
                                    $processed++;
                                }
                                
                                $db->commit();
                                
                                if ($processed > 0 || $duplicates_skipped > 0 || $client_trades_skipped > 0) {
                                    $success_message = "Successfully processed {$processed} bond trades.";
                                    
                                    if ($bonds_auto_created > 0) {
                                        $success_message .= " <strong>{$bonds_auto_created} bonds auto-created</strong> in database.";
                                    }
                                    
                                    if ($financial_entries_created > 0) {
                                        $success_message .= " Created financial entries for {$financial_entries_created} trades";
                                        if ($custodian_trades_processed > 0) {
                                            $success_message .= " ({$custodian_trades_processed} custodian trades)";
                                            if ($custodian_trades_recorded > 0) {
                                                $success_message .= " - {$custodian_trades_recorded} recorded in custodian trades";
                                            }
                                        }
                                    }
                                    
                                    if ($company_investments_recorded > 0) {
                                        $success_message .= ". <strong>{$company_investments_recorded} company bond trades recorded to Marketable Securities - Bonds (1152)</strong>";
                                    }
                                    
                                    if ($regulatory_assignments_created > 0) {
                                        $success_message .= ". <strong>{$regulatory_assignments_created} regulatory fee assignments created</strong> for accountant review.";
                                    }
                                    
                                    if ($client_trades_skipped > 0) {
                                        $success_message .= "<br><div class='alert alert-info mt-2'><i class='bi bi-info-circle'></i> <strong>{$client_trades_skipped} client bond trades were SKIPPED</strong> (will be entered manually via receipts).";
                                        $success_message .= "<br><small>Only company trades are recorded to Marketable Securities - Bonds (1152). Client trades require manual receipt entry.</small></div>";
                                    }
                                    
                                    if ($duplicates_skipped > 0) {
                                        $success_message .= "<br><div class='alert alert-warning mt-2'><i class='bi bi-exclamation-triangle'></i> <strong>{$duplicates_skipped} duplicate trades were skipped</strong> because their Trade References already exist in the system.";
                                        if (count($duplicate_references) > 0) {
                                            $success_message .= "<br><small>Skipped Trade References: " . implode(', ', array_slice($duplicate_references, 0, 10));
                                            if (count($duplicate_references) > 10) {
                                                $success_message .= " and " . (count($duplicate_references) - 10) . " more...";
                                            }
                                            $success_message .= "</small>";
                                        }
                                        $success_message .= "</div>";
                                    }
                                    
                                    $success_message .= "<br><small><strong>Trade Reference:</strong> Uses the 'Exchange Reference' from your CSV file as the unique trade identifier. If missing, a T+5 reference will be generated.</small>";
                                    $success_message .= "<br><small><strong>Account Changes:</strong> <strong>ONLY Company trades</strong> post to <strong>Marketable Securities - Bonds (1152)</strong>. Client trades are <strong>SKIPPED</strong> for manual receipt entry. No Cash at Bank entries.</small>";
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
                            $error_message = "All rows were duplicates. No new trades were uploaded.";
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

$page_title = 'Upload Bonds';
include '../includes/header.php';
?>

<!-- HTML section remains EXACTLY as it was -->
<div class="container-fluid">
    <div class="page-header">
        <div class="container">
            <h1 class="page-title">Upload Bonds</h1>
            <p class="page-subtitle">Upload bond trade data from CSV files and validate it before it enters the trading workflow.</p>
            <p class="text-muted"><small>
                <strong>Company:</strong> <?php echo htmlspecialchars($company_name); ?> |
                <strong>Code:</strong> <?php echo htmlspecialchars($company_code); ?>
            </small></p>
            <p class="text-info small mb-0">
                <i class="bi bi-info-circle"></i>
                Bond uploads only process rows whose Asset Class is set to Bond. 
                <strong>Trades without Exchange Reference will receive new T+5 alphanumeric references.</strong>
            </p>
            <p class="text-success small mb-0">
                <i class="bi bi-check-circle"></i>
                <strong>Account Update:</strong> All bond trades now post to <strong>Marketable Securities - Bonds (1152)</strong>. No Cash at Bank entries.
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
                        <input type="file" class="form-control" id="bond_file" name="bond_file" accept=".csv" required>
                        <div class="form-text">
                            <strong>Required CSV columns:</strong><br>
                            • <strong>Security</strong> - Bond identifier/security code<br>
                            • <strong>Name</strong> - Client name<br>
                            • <strong>CSD Account</strong> - Client CDS account number<br>
                            • <strong>SCA Code</strong> - Custodian/company code used to determine direct vs custodian trades<br>
                            • <strong>Buy\Sell</strong> - Trade direction (Buy/Sell)<br>
                            • <strong>Quantity</strong> - Nominal units<br>
                            • <strong>Price</strong> - Trade price<br>
                            • <strong>Asset Class</strong> - Must be <strong>Bond</strong><br>
                            • <strong>Trade Date</strong> - Date format: <strong>YYYY/MM/DD</strong>, MM/DD/YYYY, or YYYY-MM-DD<br>
                            • <strong>Settlement Date</strong> - Date format: <strong>YYYY/MM/DD</strong>, MM/DD/YYYY, or YYYY-MM-DD<br>
                            • <strong>Exchange Reference</strong> - Used as the unique trade identifier<br>
                            <strong>Processing rules:</strong> 5MB size limit, duplicate detection, client auto-creation where allowed, 
                            and automatic T+5 reference generation when no Exchange Reference exists.
                            <br><strong>Date Extraction:</strong> Dates are extracted from your CSV file - NOT today's date.
                            <br><strong>Account Changes:</strong> All trades post to <strong>Marketable Securities - Bonds (1152)</strong>. No Cash at Bank entries.
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary" id="uploadButton">
                        <i class="bi bi-upload me-1"></i>Upload Bonds
                    </button>
                    <a href="upload_interface.php" class="btn btn-secondary">Back to Upload Hub</a>
                    <a href="enter_bonds.php" class="btn btn-outline-secondary">Manual Bond Entry</a>
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
                    foreach ($preview_data as $row) {
                        $error_count += count($row['errors']);
                    }
                    echo count($preview_data) . ' rows • ' . $error_count . ' validation errors';
                    ?>
                </span>
            </div>
            <div class="card-body p-0">
                <div class="alert alert-danger m-3">
                    <i class="bi bi-exclamation-triangle me-2"></i>
                    <strong>Upload failed because of validation errors.</strong> Fix the rows below and try again.
                </div>
                <div class="table-responsive">
                    <table class="table table-sm table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Line</th>
                                <th>Security</th>
                                <th>Name</th>
                                <th>SCA Code</th>
                                <th>Trade Type</th>
                                <th>Asset Type</th>
                                <th>CSD Account</th>
                                <th>Buy\Sell</th>
                                <th>Quantity</th>
                                <th>Price</th>
                                <th>Consideration</th>
                                <th>Trade Date</th>
                                <th>Settlement Date</th>
                                <th>Trade Reference</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($preview_data as $preview_row):
                                $mapped_data = $preview_row['mapped_data'];
                                $consideration = !empty($mapped_data['consideration'])
                                    ? (float) $mapped_data['consideration']
                                    : ((float) $mapped_data['quantity'] * (float) $mapped_data['price']);
                                
                                // Determine reference source for display
                                $reference_display = $preview_row['trade_reference'];
                                $reference_class = '';
                                if ($preview_row['trade_reference']) {
                                    if (strpos($preview_row['trade_reference'], 'T') === 0 && strlen($preview_row['trade_reference']) == 6) {
                                        $reference_display = '<span class="badge bg-info">New: ' . htmlspecialchars($preview_row['trade_reference']) . '</span>';
                                    } else {
                                        $reference_display = '<span class="badge bg-success">CSD: ' . htmlspecialchars($preview_row['trade_reference']) . '</span>';
                                    }
                                } else {
                                    $reference_display = '<span class="badge bg-warning">Will generate</span>';
                                }
                            ?>
                            <tr class="<?php echo $preview_row['has_errors'] ? 'table-danger' : 'table-success'; ?>">
                                <td class="fw-bold"><?php echo $preview_row['line_number']; ?></td>
                                <td><?php echo htmlspecialchars($mapped_data['security_id']); ?></td>
                                <td>
                                    <?php echo htmlspecialchars($mapped_data['client_name']); ?>
                                    <?php if ($preview_row['is_company_trade']): ?>
                                        <span class="badge bg-info ms-1">Company</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo htmlspecialchars($mapped_data['sca_code']); ?></td>
                                <td>
                                    <?php if ($preview_row['is_custodian_trade']): ?>
                                        <span class="badge bg-warning">Custodian</span>
                                    <?php else: ?>
                                        <span class="badge bg-primary">Direct</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo htmlspecialchars(ucfirst($mapped_data['asset_class'])); ?></td>
                                <td><?php echo htmlspecialchars($mapped_data['client_cds']); ?></td>
                                <td><?php echo htmlspecialchars(ucfirst($mapped_data['trade_side'])); ?></td>
                                <td><?php echo safe_int_format($mapped_data['quantity']); ?></td>
                                <td>Tsh<?php echo safe_number_format($mapped_data['price']); ?></td>
                                <td>Tsh<?php echo safe_int_format($consideration); ?></td>
                                <td><strong><?php echo htmlspecialchars($mapped_data['trade_date']); ?></strong></td>
                                <td><?php echo htmlspecialchars($mapped_data['settlement_date']); ?></td>
                                <td><?php echo $reference_display; ?></td>
                                <td>
                                    <?php if ($preview_row['has_errors']): ?>
                                        <span class="badge bg-danger">Validation Error</span>
                                    <?php else: ?>
                                        <span class="badge bg-success">Valid</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php if ($preview_row['has_errors']): ?>
                            <tr class="table-warning">
                                <td colspan="15" class="small">
                                    <strong>Error:</strong> <?php echo htmlspecialchars(implode('; ', $preview_row['errors'])); ?>
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

        <div class="card mt-4">
            <div class="card-header">
                <h6 class="mb-0">Bond Upload Controls & Account Mapping</h6>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6">
                        <small class="text-muted"><strong>Validation flow:</strong></small>
                        <ul class="mb-3">
                            <li>Only bond rows are processed from the uploaded CSV.</li>
                            <li>Each row gets a trade reference - uses the <strong>Exchange Reference</strong> from CSV or generates a new T+5 reference.</li>
                            <li>New T+5 references follow the format: T followed by 5 alphanumeric characters (e.g., T5A9B2).</li>
                            <li>Duplicate bond trades are blocked before insert.</li>
                            <li>Custodian trades are detected from the SCA code and routed accordingly.</li>
                            <li><strong>Dates are extracted from the CSV file</strong> - Supports YYYY/MM/DD, MM/DD/YYYY, YYYY-MM-DD, and YYYYMMDD formats.</li>
                        </ul>
                    </div>
                    <div class="col-md-6">
                        <small class="text-muted"><strong>Account Mapping (No Cash):</strong></small>
                        <ul class="mb-0">
                            <li><strong>All Bond Trades</strong>: Marketable Securities - Bonds (1152)</li>
                            <li><strong>Brokerage</strong>: 411 (Income)</li>
                            <li><strong>VAT</strong>: 213 (Liability)</li>
                            <li><strong>CMSA</strong>: 2111 (Liability)</li>
                            <li><strong>DSE</strong>: 2112 (Liability)</li>
                            <li><strong>CSDR</strong>: 2113 (Liability)</li>
                        </ul>
                        <small class="text-info mt-2 d-block"><strong>Note:</strong> No Cash at Bank entries are created for trades or fees.</small>
                    </div>
                </div>
                <hr>
                <small class="text-muted"><strong>Before uploading:</strong></small>
                <ul class="mb-0">
                    <li>Confirm the bond identifiers match your configured bond master data.</li>
                    <li>Ensure dates and quantities are accurate.</li>
                    <li>Use CSV files below 5MB and keep the original column names intact.</li>
                    <li>Check that the <strong>Asset Class</strong> column contains "Bond" for all rows you want to process.</li>
                    <li><strong>Exchange Reference</strong> from CSV will be used as the unique trade reference.</li>
                </ul>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const uploadForm = document.getElementById('uploadForm');
    const uploadButton = document.getElementById('uploadButton');

    if (!uploadForm || !uploadButton) {
        return;
    }

    uploadForm.addEventListener('submit', function(e) {
        const fileInput = document.getElementById('bond_file');
        const file = fileInput.files[0];

        if (!file) {
            return;
        }

        uploadButton.disabled = true;
        uploadButton.innerHTML = '<i class="bi bi-hourglass-split me-1"></i>Processing...';

        const fileSize = file.size / 1024 / 1024;
        if (fileSize > 5) {
            e.preventDefault();
            alert('File size must be less than 5MB');
            uploadButton.disabled = false;
            uploadButton.innerHTML = '<i class="bi bi-upload me-1"></i>Upload Bonds';
            return;
        }

        if (!file.name.toLowerCase().endsWith('.csv')) {
            e.preventDefault();
            alert('Please select a CSV file');
            uploadButton.disabled = false;
            uploadButton.innerHTML = '<i class="bi bi-upload me-1"></i>Upload Bonds';
        }
    });
});
</script>

<?php include '../includes/footer.php'; ?>