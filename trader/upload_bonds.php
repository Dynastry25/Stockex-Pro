<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Start output buffering to catch any accidental output
ob_start();

// Include configuration files
require_once '../config/config.php';
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

// NEW: Generate a unique T+5 alphanumeric reference for bonds without CSD reference
function generateBondTradeReference($db) {
    $characters = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $max_attempts = 100; // Prevent infinite loop
    $attempt = 0;
    
    while ($attempt < $max_attempts) {
        // Generate T + 5 random alphanumeric characters
        $reference = 'T';
        for ($i = 0; $i < 5; $i++) {
            $reference .= $characters[random_int(0, strlen($characters) - 1)];
        }
        
        // Check if reference already exists in trades table
        try {
            $stmt = $db->prepare("SELECT COUNT(*) as count FROM trades WHERE trade_reference = ?");
            $stmt->execute([$reference]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($result['count'] == 0) {
                // Also check custodian trades and other tables if needed
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
    
    // Fallback: Use timestamp-based reference if random generation fails
    $fallback = 'T' . date('YmdHis') . mt_rand(100, 999);
    error_log("Using fallback bond trade reference: {$fallback}");
    return $fallback;
}

// NEW: Check if trade exists in csd_historical_trades and get CSD reference
function getCSDReferenceForTrade($db, $client_cds, $security_id, $trade_date, $quantity, $price, $trade_side, $client_name = '') {
    try {
        // Clean input values
        $client_cds = trim($client_cds);
        $security_id = trim($security_id);
        $client_name = trim($client_name);
        
        // First try exact match with all parameters
        $stmt = $db->prepare("
            SELECT csd_reference, sor_account, client_name, quantity, price, trade_date
            FROM csd_historical_trades 
            WHERE sor_account = ? 
            AND instrument = ? 
            AND trade_date = ?
            AND quantity = ?
            LIMIT 1
        ");
        
        $stmt->execute([
            $client_cds,
            $security_id,
            $trade_date,
            $quantity
        ]);
        
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result && !empty($result['csd_reference'])) {
            error_log("Found exact CSD match for bond trade: {$client_cds} - {$security_id} - {$trade_date} - Qty: {$quantity}");
            return $result['csd_reference'];
        }
        
        // If no exact match, try matching by client name and other parameters
        if (!empty($client_name)) {
            $stmt = $db->prepare("
                SELECT csd_reference 
                FROM csd_historical_trades 
                WHERE client_name LIKE ? 
                AND instrument = ? 
                AND trade_date = ?
                AND quantity = ?
                LIMIT 1
            ");
            
            $stmt->execute([
                "%" . $client_name . "%",
                $security_id,
                $trade_date,
                $quantity
            ]);
            
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($result && !empty($result['csd_reference'])) {
                error_log("Found CSD match by client name: {$client_name} - {$security_id} - {$trade_date} - Qty: {$quantity}");
                return $result['csd_reference'];
            }
        }
        
        // Try matching by just client CDS and security
        $stmt = $db->prepare("
            SELECT csd_reference 
            FROM csd_historical_trades 
            WHERE sor_account = ?
            AND instrument = ? 
            AND trade_date = ?
            LIMIT 1
        ");
        
        $stmt->execute([
            $client_cds,
            $security_id,
            $trade_date
        ]);
        
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result && !empty($result['csd_reference'])) {
            error_log("Found CSD match by client CDS and security: {$client_cds} - {$security_id} - {$trade_date}");
            return $result['csd_reference'];
        }
        
        return null;
        
    } catch (Exception $e) {
        error_log("Error checking CSD historical trades for bond: " . $e->getMessage());
        return null;
    }
}

// REPLACED: Generate trade reference - use CSD reference from historical trades OR generate new T+5 reference
function getTradeReference($db, $client_cds, $security_id, $trade_date, $quantity, $price, $trade_side, $client_name = '') {
    // Check if trade exists in CSD historical trades
    $csd_reference = getCSDReferenceForTrade($db, $client_cds, $security_id, $trade_date, $quantity, $price, $trade_side, $client_name);
    
    if ($csd_reference) {
        error_log("Using CSD reference as bond trade reference: {$csd_reference}");
        return $csd_reference; // Use CSD reference if found
    }
    
    // If no CSD reference found, generate a new T+5 alphanumeric reference
    $new_reference = generateBondTradeReference($db);
    error_log("No CSD reference found for bond trade. Generated new T+5 reference: {$new_reference}");
    return $new_reference;
}

// NEW: Check for duplicate trade
function isDuplicateTrade($db, $client_cds, $security_id, $trade_date, $quantity, $price, $trade_side) {
    try {
        $stmt = $db->prepare("
            SELECT COUNT(*) as count 
            FROM trades 
            WHERE client_cds_account = ? 
            AND security_id = ? 
            AND trade_date = ? 
            AND quantity = ? 
            AND price = ? 
            AND trade_side = ?
            AND asset_class = 'bond'
        ");
        
        $stmt->execute([
            $client_cds,
            $security_id,
            $trade_date,
            $quantity,
            $price,
            $trade_side
        ]);
        
        $count = $stmt->fetch()['count'];
        
        if ($count > 0) {
            error_log("Duplicate bond trade detected: {$client_cds} - {$security_id} - {$trade_date} - Qty: {$quantity} - Price: {$price} - Side: {$trade_side}");
            return true;
        }
        
        return false;
        
    } catch (Exception $e) {
        error_log("Error checking duplicate bond trade: " . $e->getMessage());
        return false; // Don't block on error
    }
}

// NEW: Function to check and insert client if not exists
function checkAndInsertClient($db, $cds_account, $client_name, $created_by = 'system') {
    try {
        // First check if client exists by CDS account
        $stmt = $db->prepare("SELECT id FROM clients WHERE cds_account = ?");
        $stmt->execute([$cds_account]);
        $client = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($client) {
            return $client['id']; // Return existing client ID
        }
        
        // Client doesn't exist, insert new client
        // Generate client code
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
        // First check if bond exists by security_id
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
        
        // Bond doesn't exist, extract details from security_id
        // Extract coupon rate from security_id (e.g., "504-15.49-T5-A1" -> 15.49)
        $coupon_rate = 0.0;
        if (preg_match('/(\d+\.\d+)/', $security_id, $matches)) {
            $coupon_rate = (float)$matches[1];
        } elseif (preg_match('/(\d+)%/i', $security_name, $matches)) {
            // Try to get from bond name (e.g., "15.49% Coupon")
            $coupon_rate = (float)$matches[1];
        }
        
        // Determine issuer based on security_id pattern
        $issuer = 'BOT'; // Default to Bank of Tanzania
        if (strpos($security_id, 'SAMIA') !== false) {
            $issuer = 'CRDB BANK PLC';
        } elseif (preg_match('/^[A-Z]+-/i', $security_id)) {
            // Corporate bond pattern
            $issuer = 'Corporate Issuer';
        }
        
        // Determine security type
        $security_type = 'FXD'; // Fixed rate by default
        if (strpos($security_id, 'T') !== false) {
            $security_type = 'Treasury Bond';
        } elseif (strpos(strtoupper($security_id), 'CORP') !== false) {
            $security_type = 'Corporate Bond';
        }
        
        // Generate a reasonable maturity date (7-25 years from trade date)
        $maturity_years = rand(7, 25); // Random between 7-25 years
        $trade_date_obj = new DateTime($trade_date);
        $trade_date_obj->modify("+{$maturity_years} years");
        $maturity_date = $trade_date_obj->format('Y-m-d');
        
        // Calculate issue date (1-5 years before trade date)
        $issue_years_before = rand(1, 5);
        $trade_date_obj = new DateTime($trade_date);
        $trade_date_obj->modify("-{$issue_years_before} years");
        $issue_date = $trade_date_obj->format('Y-m-d');
        
        // Generate term years
        $term_years = $maturity_years + $issue_years_before;
        
        // Generate ISIN if not present in security_id
        $isin = '';
        if (preg_match('/TZ\d+/', $security_id, $matches)) {
            $isin = $matches[0];
        } else {
            // Generate synthetic ISIN
            $isin = 'TZ' . str_pad(mt_rand(10000000, 99999999), 8, '0', STR_PAD_LEFT);
        }
        
        // Insert new bond
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
        // Return null on error, validation will catch this
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

/**
 * Get account ID by account code using hierarchical chart_of_accounts
 */
function getAccountIdByCode($db, $account_code) {
    try {
        $stmt = $db->prepare("SELECT id, account_code, account_name FROM chart_of_accounts WHERE account_code = ? AND is_active = 1");
        $stmt->execute([$account_code]);
        $account = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$account) {
            // If exact code not found, try to find the closest parent/child
            return findAccountForTransaction($db, $account_code);
        }
        
        return $account['id'];
        
    } catch (Exception $e) {
        return findAccountForTransaction($db, $account_code);
    }
}

/**
 * Find appropriate account for transaction based on hierarchical structure
 */
function findAccountForTransaction($db, $transaction_type) {
    // Map transaction types to hierarchical account codes from NEW COA
    $account_mapping = [
        // Cash accounts (from 111 - Cash and Cash Equivalents)
        'cash_general' => '1111',      // Cash on Hand
        'cash_bank' => '1112',         // Cash at Bank
        'cash_mobile' => '1113',       // Mobile Money
        
        // Brokerage Commission Income (from 411 - Brokerage Commission Income)
        'commission_bond' => '411',    // Bond Trading Commission (use parent 411)
        
        // VAT Accounts (from 213 - VAT Payable)
        'vat_payable_brokerage' => '213', // VAT Payable
        
        // Regulatory Fees Payable (LIABILITIES)
        'cmsa_fees_payable' => '2111',    // CMSA Fees Payable
        'dse_fees_payable' => '2112',     // DSE Fees Payable
        'csdr_fees_payable' => '2113',    // CSDR Fees Payable
        
        // Regulatory Fees Expense (when paid)
        'cmsa_fees_expense' => '561',     // CMSA Fees
        'dse_fees_expense' => '562',      // DSE Fees
        'csdr_fees_expense' => '563',     // CSDR Fees
        
        // Bond Investments
        'bond_investment' => '1251',      // Government Bonds
        'corporate_bond_investment' => '1252', // Corporate Bonds
        
        // Clearing Accounts (Assets)
        'dse_clearing_account' => '1144', // DSE Clearing Account
        'csdr_settlement_account' => '1145', // CSDR Settlement Account
        
        // Default fallbacks
        'default_cash' => '1112',         // Cash at Bank
        'default_commission' => '411',    // Brokerage Commission Income
        'default_vat' => '213',           // VAT Payable
        'default_cmsa' => '2111',         // CMSA Fees Payable
        'default_dse' => '2112',          // DSE Fees Payable
        'default_csdr' => '2113',         // CSDR Fees Payable
        'default_bond_investment' => '1251', // Government Bonds
    ];
    
    // Determine which mapping to use
    $mapping_key = '';
    
    if (strpos($transaction_type, 'cash') !== false) {
        $mapping_key = 'cash_bank';
    } elseif (strpos($transaction_type, 'commission') !== false) {
        $mapping_key = 'commission_bond'; // Bond commission
    } elseif (strpos($transaction_type, 'vat') !== false) {
        $mapping_key = 'vat_payable_brokerage';
    } elseif (strpos($transaction_type, 'cmsa') !== false) {
        if (strpos($transaction_type, 'payable') !== false) {
            $mapping_key = 'cmsa_fees_payable';
        } else {
            $mapping_key = 'cmsa_fees_expense';
        }
    } elseif (strpos($transaction_type, 'dse') !== false) {
        if (strpos($transaction_type, 'payable') !== false) {
            $mapping_key = 'dse_fees_payable';
        } else {
            $mapping_key = 'dse_fees_expense';
        }
    } elseif (strpos($transaction_type, 'csd') !== false || strpos($transaction_type, 'csdr') !== false) {
        if (strpos($transaction_type, 'payable') !== false) {
            $mapping_key = 'csdr_fees_payable';
        } else {
            $mapping_key = 'csdr_fees_expense';
        }
    } elseif (strpos($transaction_type, 'bond_investment') !== false || strpos($transaction_type, 'investment') !== false) {
        $mapping_key = 'bond_investment';
    } else {
        // Use default based on transaction type
        $mapping_key = 'default_cash';
    }
    
    // Get the account code from mapping
    $account_code = $account_mapping[$mapping_key] ?? '1112'; // Default to Cash at Bank
    
    try {
        $stmt = $db->prepare("SELECT id FROM chart_of_accounts WHERE account_code = ? AND is_active = 1");
        $stmt->execute([$account_code]);
        $account = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($account) {
            error_log("Found account {$account_code} for transaction type: {$transaction_type}");
            return $account['id'];
        }
        
        // If specific code not found, get first active account of that type
        $search_pattern = '';
        
        if (strpos($mapping_key, 'cash') !== false) {
            $search_pattern = '111%'; // Cash accounts
        } elseif (strpos($mapping_key, 'commission') !== false) {
            $search_pattern = '411%'; // Commission income
        } elseif (strpos($mapping_key, 'vat') !== false) {
            $search_pattern = '213%'; // VAT accounts
        } elseif (strpos($mapping_key, 'cmsa') !== false || strpos($mapping_key, 'dse') !== false || 
                 strpos($mapping_key, 'csdr') !== false) {
            if (strpos($mapping_key, 'payable') !== false) {
                $search_pattern = '211%'; // Regulatory fees payable
            } else {
                $search_pattern = '56%'; // Regulatory fees expense
            }
        } elseif (strpos($mapping_key, 'investment') !== false) {
            $search_pattern = '125%'; // Bond investments
        } else {
            $search_pattern = '1%'; // Default to assets
        }
        
        $stmt = $db->prepare("SELECT id FROM chart_of_accounts WHERE account_code LIKE ? AND is_active = 1 LIMIT 1");
        $stmt->execute([$search_pattern]);
        $fallback = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return $fallback ? $fallback['id'] : 1;
        
    } catch (Exception $e) {
        error_log("Error finding account for {$transaction_type}: " . $e->getMessage());
        return 1; // Default to account ID 1 as fallback
    }
}

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
        
        // Get account code and name for the ledger entry
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
        $stmt = $db->prepare("
            INSERT INTO regulatory_fee_assignments 
            (trade_reference, client_name, security_id, security_name, trade_date, 
             dse_fee, cmsa_fee, csd_fee, total_fees, consideration, trade_side,
             status, treatment_type, created_by, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', NULL, ?, NOW(), NOW())
        ");
        
        $dse_fee = $fees['dse'] ?? 0;
        $cmsa_fee = $fees['cmsa'] ?? 0;
        $csd_fee = $fees['csd'] ?? 0;
        
        $total_fees = $dse_fee + $cmsa_fee + $csd_fee;
        
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
        
        if ($result) {
            error_log("Successfully inserted regulatory fee assignment for bond trade: {$trade_reference} - DSE: {$dse_fee}, CMSA: {$cmsa_fee}, CSD: {$csd_fee}, Total: {$total_fees}");
        } else {
            error_log("Failed to insert regulatory fee assignment for bond trade: {$trade_reference}");
        }
        
        return $result;
        
    } catch (Exception $e) {
        error_log("Error recording regulatory fee assignment for bond: " . $e->getMessage());
        return false;
    }
}

/**
 * Record company's own bond investment transactions
 */
function recordCompanyBondInvestment($db, $trade_reference, $trade_side, $consideration, $client_name, $trade_date) {
    try {
        // Get hierarchical accounts using new COA structure
        $investment_account = getAccountIdByCode($db, 'bond_investment');        // 1251 - Government Bonds
        $cash_bond_account = getAccountIdByCode($db, 'cash_bank');              // 1112 - Cash at Bank
        
        $entries_created = 0;
        
        if ($trade_side === 'buy') {
            // BUY: Company purchases bonds for its own portfolio
            $entry1 = recordGeneralLedgerEntry(
                $db, $trade_date, $investment_account,
                $consideration, 0,
                "Company bond purchase - {$trade_reference} - {$client_name}",
                $trade_reference, 'company_investment'
            );
            if ($entry1) $entries_created++;
            
            $entry2 = recordGeneralLedgerEntry(
                $db, $trade_date, $cash_bond_account,
                0, $consideration,
                "Cash paid for company bond - {$trade_reference}",
                $trade_reference, 'company_investment'
            );
            if ($entry2) $entries_created++;
            
        } else {
            // SELL: Company sells bonds from its portfolio
            $entry1 = recordGeneralLedgerEntry(
                $db, $trade_date, $cash_bond_account,
                $consideration, 0,
                "Cash from company bond sale - {$trade_reference}",
                $trade_reference, 'company_investment'
            );
            if ($entry1) $entries_created++;
            
            $entry2 = recordGeneralLedgerEntry(
                $db, $trade_date, $investment_account,
                0, $consideration,
                "Company bond sold - {$trade_reference} - {$client_name}",
                $trade_reference, 'company_investment'
            );
            if ($entry2) $entries_created++;
        }
        
        return $entries_created > 0;
        
    } catch (Exception $e) {
        error_log("Error recording company bond investment: " . $e->getMessage());
        return false;
    }
}

/**
 * Bond Accounting - Records ONLY brokerage and VAT (regulatory fees handled separately by accountant)
 */
function createBondAccountingEntries($db, $trade_reference, $consideration, $fees, $trade_side, $client_name, $company_name, $trade_date, $is_custodian_trade = false) {
    try {
        $entries_created = 0;
        
        // Get hierarchical accounts using new COA structure
        $cash_account = getAccountIdByCode($db, 'cash_bank');            // 1112 - Cash at Bank
        $brokerage_income = getAccountIdByCode($db, 'commission_bond');  // 411 - Brokerage Commission Income
        $vat_payable = getAccountIdByCode($db, 'vat_payable_brokerage'); // 213 - VAT Payable
        
        $brokerage_fee = $fees['brokerage'] ?? 0;
        $vat_fee = $fees['vat'] ?? 0;
        
        $total_fees = $brokerage_fee + $vat_fee; // Only brokerage + VAT
        
        if ($total_fees > 0) {
            // Entry 1: Debit Cash (we receive brokerage + VAT)
            $entry1 = recordGeneralLedgerEntry(
                $db, $trade_date, $cash_account,
                $total_fees, 0,
                "Bond brokerage + VAT received - {$trade_reference} - {$client_name}",
                $trade_reference, 'fee'
            );
            if ($entry1) {
                $entries_created++;
                error_log("Cash entry recorded for bond: {$total_fees} for trade {$trade_reference}");
            }
            
            // Entry 2: Credit Brokerage Income (our revenue)
            $entry2 = recordGeneralLedgerEntry(
                $db, $trade_date, $brokerage_income,
                0, $brokerage_fee,
                "Bond brokerage income - {$trade_reference}",
                $trade_reference, 'fee'
            );
            if ($entry2) {
                $entries_created++;
                error_log("Bond brokerage income recorded: {$brokerage_fee} for trade {$trade_reference}");
            }
            
            // Entry 3: Credit VAT Payable (liability to government)
            if ($vat_fee > 0) {
                $entry3 = recordGeneralLedgerEntry(
                    $db, $trade_date, $vat_payable,
                    0, $vat_fee,
                    "VAT on bond brokerage - {$trade_reference}",
                    $trade_reference, 'fee'
                );
                if ($entry3) {
                    $entries_created++;
                    error_log("VAT payable recorded for bond: {$vat_fee} for trade {$trade_reference}");
                }
            }
        }
        
        error_log("Created {$entries_created} accounting entries for bond trade {$trade_reference}");
        return $entries_created > 0;
        
    } catch (Exception $e) {
        error_log("Error creating bond accounting entries: " . $e->getMessage());
        return false;
    }
}

/**
 * Record regulatory fees expense when they are actually paid (by accountant)
 */
function recordRegulatoryFeesPayment($db, $payment_date, $fees_paid, $reference_no, $description = '') {
    try {
        $entries_created = 0;
        
        // Get accounts using new COA structure
        $cash_account = getAccountIdByCode($db, 'cash_bank');           // 1112 - Cash at Bank
        $dse_payable = getAccountIdByCode($db, 'dse_fees_payable');    // 2112 - DSE Fees Payable
        $cmsa_payable = getAccountIdByCode($db, 'cmsa_fees_payable');  // 2111 - CMSA Fees Payable
        $csd_payable = getAccountIdByCode($db, 'csdr_fees_payable');   // 2113 - CSDR Fees Payable
        $dse_expense = getAccountIdByCode($db, 'dse_fees_expense');    // 562 - DSE Fees
        $cmsa_expense = getAccountIdByCode($db, 'cmsa_fees_expense');  // 561 - CMSA Fees
        $csd_expense = getAccountIdByCode($db, 'csdr_fees_expense');   // 563 - CSDR Fees
        
        $dse_fee = $fees_paid['dse'] ?? 0;
        $cmsa_fee = $fees_paid['cmsa'] ?? 0;
        $csd_fee = $fees_paid['csd'] ?? 0;
        
        $total_payment = $dse_fee + $cmsa_fee + $csd_fee;
        
        if ($total_payment > 0) {
            // When paying regulatory fees:
            // 1. Debit the expense accounts (recognize expense)
            // 2. Credit the payable accounts (reduce liability)
            // 3. Credit cash (payment made)
            
            if ($dse_fee > 0) {
                // Recognize DSE expense
                recordGeneralLedgerEntry(
                    $db, $payment_date, $dse_expense,
                    $dse_fee, 0,
                    $description ?: "DSE fees expense - {$reference_no}",
                    $reference_no, 'fee_payment'
                );
                
                // Reduce DSE payable
                recordGeneralLedgerEntry(
                    $db, $payment_date, $dse_payable,
                    0, $dse_fee,
                    "Payment of DSE fees - {$reference_no}",
                    $reference_no, 'fee_payment'
                );
                
                $entries_created += 2;
            }
            
            if ($cmsa_fee > 0) {
                // Recognize CMSA expense
                recordGeneralLedgerEntry(
                    $db, $payment_date, $cmsa_expense,
                    $cmsa_fee, 0,
                    $description ?: "CMSA fees expense - {$reference_no}",
                    $reference_no, 'fee_payment'
                );
                
                // Reduce CMSA payable
                recordGeneralLedgerEntry(
                    $db, $payment_date, $cmsa_payable,
                    0, $cmsa_fee,
                    "Payment of CMSA fees - {$reference_no}",
                    $reference_no, 'fee_payment'
                );
                
                $entries_created += 2;
            }
            
            if ($csd_fee > 0) {
                // Recognize CSDR expense
                recordGeneralLedgerEntry(
                    $db, $payment_date, $csd_expense,
                    $csd_fee, 0,
                    $description ?: "CSDR fees expense - {$reference_no}",
                    $reference_no, 'fee_payment'
                );
                
                // Reduce CSDR payable
                recordGeneralLedgerEntry(
                    $db, $payment_date, $csd_payable,
                    0, $csd_fee,
                    "Payment of CSDR fees - {$reference_no}",
                    $reference_no, 'fee_payment'
                );
                
                $entries_created += 2;
            }
            
            // Record cash payment
            recordGeneralLedgerEntry(
                $db, $payment_date, $cash_account,
                0, $total_payment,
                "Cash payment for regulatory fees - {$reference_no}",
                $reference_no, 'fee_payment'
            );
            
            $entries_created++;
        }
        
        return $entries_created > 0;
        
    } catch (Exception $e) {
        error_log("Error recording regulatory fees payment: " . $e->getMessage());
        return false;
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

// Function to safely format integers
function safe_int_format($value) {
    if ($value === '' || $value === null) {
        return '0';
    }
    $numeric_value = is_numeric($value) ? $value : 0;
    return number_format((int)$numeric_value);
}

// Function to calculate bond fees based on your charges structure
function calculateBondFees($quantity, $price, $consideration) {
    $fees = [];
    
    $face_value = $quantity;
    
    // Brokerage Commission
    $brokerage_first_100m = min($face_value, 100000000) * (0.063132 / 100);
    $brokerage_excess = max($face_value - 100000000, 0) * (0.035 / 100);
    $fees['brokerage'] = $brokerage_first_100m + $brokerage_excess;
    
    // VAT on Brokerage (18%)
    $fees['vat'] = $fees['brokerage'] * 0.18;
    
    // Other fees (for accountant assignment)
    $fees['cmsa'] = $consideration * (0.01 / 100);
    $fees['csd'] = $face_value * (0.0118 / 100);
    $fees['dse'] = $face_value * (0.02006 / 100);
    
    // Total brokerage + VAT only (for immediate accounting)
    $fees['brokerage_vat_total'] = $fees['brokerage'] + $fees['vat'];
    
    // Total regulatory fees (for assignment)
    $fees['regulatory_total'] = $fees['cmsa'] + $fees['csd'] + $fees['dse'];
    
    error_log("Bond fees calculated: Brokerage={$fees['brokerage']}, VAT={$fees['vat']}, CMSA={$fees['cmsa']}, CSD={$fees['csd']}, DSE={$fees['dse']}");
    
    return $fees;
}

// MODIFIED: Updated Map CSV row to database with PROPER date conversion for bonds
function mapCSVRowToDatabaseBond($row) {
    // Trim all values from the row before processing
    $trimmed_row = [];
    foreach ($row as $key => $value) {
        $trimmed_row[$key] = is_string($value) ? trim($value) : $value;
    }
    
    // Convert MM/DD/YYYY date format to YYYY-MM-DD
    $trade_date = '';
    if (!empty($trimmed_row['Trade Date'])) {
        $date_str = trim($trimmed_row['Trade Date']);
        // Check if date is in MM/DD/YYYY format
        if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $date_str, $matches)) {
            $month = str_pad($matches[1], 2, '0', STR_PAD_LEFT);
            $day = str_pad($matches[2], 2, '0', STR_PAD_LEFT);
            $year = $matches[3];
            $trade_date = $year . '-' . $month . '-' . $day;
        } elseif (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_str)) {
            // Already in YYYY-MM-DD format
            $trade_date = $date_str;
        }
    }
    
    $settlement_date = '';
    if (!empty($trimmed_row['Settlement Date'])) {
        $date_str = trim($trimmed_row['Settlement Date']);
        if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $date_str, $matches)) {
            $month = str_pad($matches[1], 2, '0', STR_PAD_LEFT);
            $day = str_pad($matches[2], 2, '0', STR_PAD_LEFT);
            $year = $matches[3];
            $settlement_date = $year . '-' . $month . '-' . $day;
        } elseif (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_str)) {
            $settlement_date = $date_str;
        }
    }
    
    // Try to extract maturity date from other columns
    $maturity_date = '';
    if (!empty($trimmed_row['Maturity Date'])) {
        $date_str = trim($trimmed_row['Maturity Date']);
        if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $date_str, $matches)) {
            $month = str_pad($matches[1], 2, '0', STR_PAD_LEFT);
            $day = str_pad($matches[2], 2, '0', STR_PAD_LEFT);
            $year = $matches[3];
            $maturity_date = $year . '-' . $month . '-' . $day;
        }
    }
    
    // Get client name from appropriate column
    $client_name = $trimmed_row['Name'] ?? 
                   $trimmed_row['Client Name'] ?? 
                   $trimmed_row['Main Principal'] ?? 
                   $trimmed_row['Principal'] ?? '';
    
    // Get CSD account from appropriate column
    $client_cds = $trimmed_row['CSD Account'] ?? 
                  $trimmed_row['CDS Account'] ?? '';
    
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

// UPDATED: Function to validate bond data - CSD reference is optional now
function validateBondData($row, $line_num, $db, $company_code) {
    $errors = [];
    
    $mapped_data = mapCSVRowToDatabaseBond($row);
    
    // Check asset class - must be bond
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
    
    // Validate trade side with case-insensitive comparison
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
    
    // Validate SCA Code exists
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
    
    // REMOVED: The check for CSD reference existence is no longer required
    // We'll generate one automatically if it doesn't exist
    
    // Check for duplicate trade (if we have enough info)
    if (!empty($mapped_data['client_cds']) && !empty($security_id) && !empty($mapped_data['trade_date'])) {
        $quantity = !empty($mapped_data['quantity']) ? (int)$mapped_data['quantity'] : 0;
        $price = !empty($mapped_data['price']) ? (float)$mapped_data['price'] : 0;
        $trade_side = strtolower(trim($mapped_data['trade_side']));
        
        // We need to check for duplicates WITHOUT requiring a CSD reference
        if (isDuplicateTrade($db, $mapped_data['client_cds'], $security_id, $mapped_data['trade_date'], $quantity, $price, $trade_side)) {
            $errors[] = "Duplicate trade detected. This bond trade already exists in the database.";
        }
    }
    
    return $errors;
}

// NEW: Filter bond rows
function filterBondRows($rows) {
    $bond_rows = [];
    
    foreach ($rows as $index => $row) {
        $mapped_data = mapCSVRowToDatabaseBond($row);
        $asset_class = strtolower(trim($mapped_data['asset_class'] ?? ''));
        
        // Log what we're seeing
        error_log("Bond Row {$index}: Asset Class = '{$asset_class}'");
        
        // Process only Bond rows
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
    $allowed_types = ['text/csv', 'application/vnd.ms-excel', 'text/plain'];
    $max_size = 5 * 1024 * 1024; // 5MB
    
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
            // Read header row with explicit parameters
            $header = fgetcsv($handle, 0, ",", '"', '\\');
            if ($header === FALSE) {
                throw new Exception("Could not read CSV header");
            }
            
            // Trim whitespace from header names
            $header = array_map('trim', $header);
            
            $line_number = 1;
            while (($data = fgetcsv($handle, 0, ",", '"', '\\')) !== FALSE) {
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
                    
                    // Pad or truncate data to match header count
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
                    // DEBUG: Log CSV info
                    error_log("=== BOND CSV DEBUG INFO ===");
                    error_log("Total rows in CSV: " . count($rows));
                    
                    $bond_rows = filterBondRows($rows);
                    $total_rows = count($rows);
                    $bond_count = count($bond_rows);
                    
                    if (empty($bond_rows)) {
                        $error_message = "No bond data found in the uploaded file. Please ensure the CSV contains rows with 'Asset Class' set to 'Bond'.";
                    } else {
                        $preview_data = [];
                        $line_number = 2; // Start after header
                        
                        foreach ($bond_rows as $row) {
                            $mapped_data = mapCSVRowToDatabaseBond($row);
                            $is_company_trade = (trim(strtolower($mapped_data['client_name'])) === trim(strtolower($company_name)));
                            $is_custodian_trade = isCustodianTrade($mapped_data['sca_code'], $company_code);
                            
                            // Get trade reference (from CSD historical trades OR generate new T+5)
                            $trade_reference = getTradeReference(
                                $db,
                                $mapped_data['client_cds'],
                                $mapped_data['security_id'],
                                $mapped_data['trade_date'],
                                $mapped_data['quantity'],
                                $mapped_data['price'],
                                $mapped_data['trade_side'],
                                $mapped_data['client_name']
                            );
                            
                            $preview_data[] = [
                                'line_number' => $line_number++,
                                'data' => $row,
                                'mapped_data' => $mapped_data,
                                'trade_reference' => $trade_reference, // Will always have a value now
                                'has_errors' => false, // Will be set during validation
                                'errors' => [],
                                'is_company_trade' => $is_company_trade,
                                'is_custodian_trade' => $is_custodian_trade
                            ];
                        }
                        
                        $has_errors = false;
                        $errors = [];
                        $error_count = 0;
                        $valid_count = 0;
                        
                        // Validate each row
                        foreach ($preview_data as &$preview_row) {
                            $validation_errors = validateBondData($preview_row['data'], $preview_row['line_number'], $db, $company_code);
                            $preview_row['has_errors'] = !empty($validation_errors);
                            $preview_row['errors'] = $validation_errors;
                            
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
                        unset($preview_row); // Break reference
                        
                        // DEBUG: Log validation results
                        error_log("Bond Validation Results: {$valid_count} valid, {$error_count} errors");
                        
                        if (!$has_errors && !empty($preview_data)) {
                            // Debug logging
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
                                $csd_references_used = 0;
                                $generated_references = 0;
                                
                                foreach ($preview_data as $preview_row) {
                                    $mapped_data = $preview_row['mapped_data'];
                                    $trade_reference = $preview_row['trade_reference']; // Use CSD reference or generated T+5
                                    
                                    // Track if we used CSD or generated
                                    if (strpos($trade_reference, 'T') === 0 && strlen($trade_reference) == 6) {
                                        $generated_references++;
                                        error_log("Using GENERATED T+5 reference for bond: {$trade_reference}");
                                    } else {
                                        $csd_references_used++;
                                        error_log("Using CSD reference for bond: {$trade_reference}");
                                    }
                                    
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
                                    
                                    // NEW: Check and insert client if not exists
                                    if (!empty($client_cds) && !empty($client_name)) {
                                        $client_id = checkAndInsertClient($db, $client_cds, $client_name, $current_user['username'] ?? 'system');
                                        if ($client_id) {
                                            error_log("Client ensured for bond: {$client_name} (CDS: {$client_cds})");
                                        }
                                    }
                                    
                                    // NEW: Check and insert bond if not exists
                                    $bond_details = checkAndInsertBond($db, $security_id, $mapped_data['bond_name'], $trade_date, $current_user['username'] ?? 'system');
                                    
                                    if ($bond_details && $bond_details['was_created']) {
                                        $bonds_auto_created++;
                                        error_log("Bond auto-created: {$security_id}");
                                    }
                                    
                                    $bond_name_to_use = $bond_details ? $bond_details['bond_name'] : $security_id;
                                    
                                    // Insert trade record (using CSD reference or generated T+5)
                                    $trade_insert_stmt = $db->prepare("
                                        INSERT INTO trades (
                                            trade_reference, asset_class, security_id, security_name,
                                            client_cds_account, client_name, 
                                            counterparty_name, counterparty_cds_account,
                                            trade_side, quantity, price, consideration,
                                            trade_date, settlement_date, currency, sca_code, status, uploaded_by,
                                            capacity, broker_name, counterparty_broker
                                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
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
                                        substr($counterparty_broker, 0, 100)
                                    ]);
                                    
                                    error_log("Successfully inserted bond trade with reference: {$trade_reference}");
                                    
                                    // Record company investment if it's a company trade
                                    if ($is_company_trade && $consideration > 0) {
                                        if (recordCompanyBondInvestment($db, $trade_reference, $trade_side, $consideration, $client_name, $trade_date)) {
                                            error_log("Company bond investment recorded: {$trade_reference}");
                                        }
                                    }
                                    
                                    // Create financial entries and record regulatory fee assignments
                                    if ($consideration > 0) {
                                        $fees = calculateBondFees($quantity, $price, $consideration);
                                        
                                        // Record regulatory fee assignment for accountant review
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
                                                error_log("Regulatory fee assignment recorded for bond: {$trade_reference}");
                                            }
                                        }
                                        
                                        // Create accounting entries for brokerage + VAT only
                                        if (createBondAccountingEntries($db, $trade_reference, $consideration, $fees, $trade_side, $client_name, $company_name, $trade_date, $is_custodian_trade)) {
                                            $financial_entries_created++;
                                            error_log("Accounting entries created for bond trade: {$trade_reference}");
                                        }
                                        
                                        // Record custodian trade if applicable
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
                                                    error_log("Custodian bond trade recorded: {$trade_reference}");
                                                }
                                            }
                                        }
                                    }
                                    
                                    $processed++;
                                }
                                
                                $db->commit();
                                
                                // Generate trial balance
                                $tb_result = generateTrialBalance($db, date('Y-m-d'));
                                
                                if ($processed > 0) {
                                    $success_message = "Successfully processed {$processed} bond trades.";
                                    
                                    if ($csd_references_used > 0) {
                                        $success_message .= " <strong>{$csd_references_used} trades</strong> used existing CSD references.";
                                    }
                                    
                                    if ($generated_references > 0) {
                                        $success_message .= " <strong>{$generated_references} trades</strong> received new T+5 references.";
                                    }
                                    
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
                                    
                                    if ($regulatory_assignments_created > 0) {
                                        $success_message .= ". <strong>{$regulatory_assignments_created} regulatory fee assignments created</strong> for accountant review.";
                                    }
                                    
                                    $success_message .= "<br><small><strong>Note:</strong> Trade references are either from CSD historical trades or newly generated T+5 alphanumeric codes.</small>";
                                    $preview_data = [];
                                }
                                
                            } catch (Exception $e) {
                                $db->rollBack();
                                $error_message = "Error processing file: " . $e->getMessage();
                                $has_errors = true;
                                // Add detailed logging
                                error_log("BOND UPLOAD TRANSACTION ERROR: " . $e->getMessage());
                                error_log("File: " . $e->getFile() . " Line: " . $e->getLine());
                                error_log("Stack trace: " . $e->getTraceAsString());
                            }
                        } else if ($has_errors) {
                            $error_message = "Validation errors found. Please fix the errors highlighted below before uploading.";
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
                <strong>Trades without CSD references will receive new T+5 alphanumeric references.</strong>
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
                            • <strong>Trade Date</strong> - Date format accepted from your upload file<br>
                            • <strong>Settlement Date</strong> - Settlement value date<br>
                            <strong>Processing rules:</strong> 5MB size limit, duplicate detection, client auto-creation where allowed, 
                            and automatic T+5 reference generation when no CSD reference exists.
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
                                <td colspan="13" class="small">
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
                <h6 class="mb-0">Bond Upload Controls</h6>
            </div>
            <div class="card-body">
                <small class="text-muted"><strong>Validation flow:</strong></small>
                <ul class="mb-3">
                    <li>Only bond rows are processed from the uploaded CSV.</li>
                    <li>Each row gets a trade reference - either from CSD historical trades or a newly generated T+5 reference.</li>
                    <li>New T+5 references follow the format: T followed by 5 alphanumeric characters (e.g., T5A9B2).</li>
                    <li>Duplicate bond trades are blocked before insert.</li>
                    <li>Custodian trades are detected from the SCA code and routed accordingly.</li>
                </ul>
                <small class="text-muted"><strong>Before uploading:</strong></small>
                <ul class="mb-0">
                    <li>Confirm the bond identifiers match your configured bond master data.</li>
                    <li>Ensure dates and quantities are accurate.</li>
                    <li>Use CSV files below 5MB and keep the original column names intact.</li>
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