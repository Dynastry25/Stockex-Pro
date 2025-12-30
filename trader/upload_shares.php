<?php
// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', '../logs/upload_shares_errors.log');

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

$success_message = '';
$error_message = '';
$preview_data = [];
$has_errors = false;

$processed = 0;
$financial_entries_created = 0;
$custodian_trades_processed = 0;
$custodian_trades_recorded = 0;
$company_investments_recorded = 0;
$etf_trades_recorded = 0;
$regulatory_assignments_created = 0;

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
        return ['company_code' => 'B13/C', 'company_name' => 'Victory Financial Services'];
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

// NEW: Generate unique short trade reference (6-7 characters)
function generateShortTradeReference($db) {
    $max_attempts = 10;
    $attempt = 0;
    
    while ($attempt < $max_attempts) {
        // Generate timestamp-based reference (6-7 chars)
        $timestamp = time();
        $short_hash = substr(hash('crc32', $timestamp . mt_rand()), 0, 6);
        $trade_ref = 'T' . $short_hash; // T + 6 chars = 7 chars total
        
        // Check if reference already exists
        $stmt = $db->prepare("SELECT COUNT(*) as count FROM trades WHERE trade_reference = ?");
        $stmt->execute([$trade_ref]);
        $exists = $stmt->fetch()['count'] > 0;
        
        if (!$exists) {
            return $trade_ref;
        }
        
        $attempt++;
    }
    
    // Fallback to longer reference if all attempts fail
    return generate_reference_number('TRD');
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
        // Generate client code (you might want to customize this)
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
        error_log("New client inserted: {$client_name} (CDS: {$cds_account}) with ID: {$client_id}");
        
        return $client_id;
        
    } catch (Exception $e) {
        error_log("Error checking/inserting client: " . $e->getMessage());
        // Return null on error, but don't stop the process
        return null;
    }
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
            error_log("Duplicate trade detected: {$client_cds} - {$security_id} - {$trade_date} - Qty: {$quantity} - Price: {$price} - Side: {$trade_side}");
            return true;
        }
        
        return false;
        
    } catch (Exception $e) {
        error_log("Error checking duplicate trade: " . $e->getMessage());
        return false; // Don't block on error
    }
}

// Check if trade is through custodian
function isCustodianTrade($sca_code, $company_code) {
    return !empty($sca_code) && $sca_code !== $company_code;
}

// Get account ID by account code using hierarchical chart_of_accounts
function getAccountIdByCode($db, $account_code) {
    try {
        $stmt = $db->prepare("SELECT id, account_code, account_name FROM chart_of_accounts WHERE account_code = ? AND is_active = 1");
        $stmt->execute([$account_code]);
        $account = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$account) {
            return findAccountForEquityTransaction($db, $account_code);
        }
        
        return $account['id'];
        
    } catch (Exception $e) {
        return findAccountForEquityTransaction($db, $account_code);
    }
}

/**
 * Find appropriate account for equity transaction based on hierarchical structure
 */
function findAccountForEquityTransaction($db, $transaction_type) {
    // Map transaction types to NEW hierarchical account codes from COA
    $account_mapping = [
        // Cash accounts (from 111 - Cash and Cash Equivalents)
        'cash_general' => '1111',      // Cash on Hand
        'cash_bank' => '1112',         // Cash at Bank
        'cash_equity_ops' => '1112',   // Cash for equity operations
        'cash_mobile' => '1113',       // Mobile Money
        
        // Equity Commission Income (from 411 - Brokerage Commission Income)
        'commission_equity' => '411',  // Equity Trading Commission
        'commission_etf' => '411',     // ETF Commission (use same as equity)
        
        // VAT Accounts (from 213 - VAT Payable)
        'vat_payable_brokerage' => '213', // VAT Payable
        
        // Regulatory Fees Payable (LIABILITIES) - NEW CODES
        'cmsa_fees_payable' => '2111',    // CMSA Fees Payable
        'dse_fees_payable' => '2112',     // DSE Fees Payable
        'csdr_fees_payable' => '2113',    // CSDR Fees Payable
        'vrf_fees_payable' => '2114',     // Value Retention Fees Payable (VRF)
        
        // Regulatory Fees Expense (when paid) - NEW CODES
        'cmsa_fees_expense' => '561',     // CMSA Fees
        'dse_fees_expense' => '562',      // DSE Fees
        'csdr_fees_expense' => '563',     // CSDR Fees
        'vrf_fees_expense' => '564',      // Value Retention Fees (VRF)
        
        // Equity Investments
        'equity_investments' => '1253',   // Equity Investments
        
        // Default fallbacks
        'default_cash' => '1112',         // Cash at Bank
        'default_commission' => '411',    // Brokerage Commission Income
        'default_vat' => '213',           // VAT Payable
        'default_cmsa' => '2111',         // CMSA Fees Payable
        'default_dse' => '2112',          // DSE Fees Payable
        'default_csdr' => '2113',         // CSDR Fees Payable
        'default_vrf' => '2114',          // VRF Fees Payable
    ];
    
    // Determine which mapping to use
    $mapping_key = '';
    
    if (strpos($transaction_type, 'cash') !== false) {
        $mapping_key = 'cash_bank';
    } elseif (strpos($transaction_type, 'commission') !== false) {
        $mapping_key = 'commission_equity';
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
    } elseif (strpos($transaction_type, 'vrf') !== false) {
        if (strpos($transaction_type, 'payable') !== false) {
            $mapping_key = 'vrf_fees_payable';
        } else {
            $mapping_key = 'vrf_fees_expense';
        }
    } elseif (strpos($transaction_type, 'investment') !== false || strpos($transaction_type, 'equity_inv') !== false) {
        $mapping_key = 'equity_investments';
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
                 strpos($mapping_key, 'csdr') !== false || strpos($mapping_key, 'vrf') !== false) {
            if (strpos($mapping_key, 'payable') !== false) {
                $search_pattern = '211%'; // Regulatory fees payable
            } else {
                $search_pattern = '56%'; // Regulatory fees expense
            }
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

// Calculate equity fees including ALL regulatory fees - TIERED BROKERAGE
function calculateEquityFees($db, $consideration) {
    try {
        $fees = [];
        
        // Get tiered brokerage rates from database or use defaults
        $tier1_rate = 1.7;
        $tier2_rate = 1.5; 
        $tier3_rate = 0.8;
        
        // Calculate tiered brokerage
        if ($consideration <= 10000000) {
            $brokerage = $consideration * ($tier1_rate / 100);
        } elseif ($consideration <= 40000000) {
            $brokerage = $consideration * ($tier2_rate / 100);
        } else {
            $brokerage = $consideration * ($tier3_rate / 100);
        }
        
        $fees['brokerage'] = $brokerage;
        
        // VAT on Brokerage (18%)
        $fees['vat'] = $brokerage * 0.18;
        
        // Regulatory fees - ALL INCLUDED (for accountant assignment)
        $fees['cmsa'] = $consideration * (0.01 / 100);      // CMSA Fee
        $fees['csd'] = $consideration * (0.0118 / 100);     // CSD&R Fee
        $fees['dse'] = $consideration * (0.02006 / 100);    // DSE Fee
        $fees['vrf'] = $consideration * (0.0025 / 100);     // VRF Fee
        
        // Total ALL fees (for display purposes only)
        $fees['total_all_fees'] = $fees['brokerage'] + $fees['vat'] + $fees['cmsa'] + $fees['csd'] + $fees['dse'] + $fees['vrf'];
        
        // Total brokerage + VAT only (for immediate accounting)
        $fees['brokerage_vat_total'] = $fees['brokerage'] + $fees['vat'];
        
        // Total regulatory fees (for assignment) - INCLUDING VRF
        $fees['regulatory_total'] = $fees['cmsa'] + $fees['csd'] + $fees['dse'] + $fees['vrf'];
        
        error_log("Fees calculated for {$consideration}: Brokerage={$fees['brokerage']}, VAT={$fees['vat']}, CMSA={$fees['cmsa']}, CSD={$fees['csd']}, DSE={$fees['dse']}, VRF={$fees['vrf']}");
        
        return $fees;
        
    } catch (Exception $e) {
        error_log("Error calculating equity fees: " . $e->getMessage());
        return [
            'brokerage' => 0,
            'vat' => 0,
            'cmsa' => 0,
            'csd' => 0, 
            'dse' => 0,
            'vrf' => 0,
            'total_all_fees' => 0,
            'brokerage_vat_total' => 0,
            'regulatory_total' => 0
        ];
    }
}

// Equity Accounting - Records ONLY brokerage and VAT (regulatory fees handled separately by accountant)
function createEquityAccountingEntries($db, $trade_reference, $consideration, $fees, $trade_side, $client_name, $company_name, $trade_date, $is_custodian_trade = false) {
    try {
        $entries_created = 0;
        
        // Get hierarchical accounts using new COA structure
        $cash_account = getAccountIdByCode($db, 'cash_bank');            // 1112 - Cash at Bank
        $brokerage_income = getAccountIdByCode($db, 'commission_equity'); // 411 - Brokerage Commission Income
        $vat_payable = getAccountIdByCode($db, 'vat_payable_brokerage');  // 213 - VAT Payable
        
        $brokerage_fee = $fees['brokerage'] ?? 0;
        $vat_fee = $fees['vat'] ?? 0;
        
        $total_fees = $brokerage_fee + $vat_fee; // Only brokerage + VAT
        
        if ($total_fees > 0) {
            // Entry 1: Debit Cash (we receive brokerage + VAT)
            $entry1 = recordGeneralLedgerEntry(
                $db, $trade_date, $cash_account,
                $total_fees, 0,
                "Equity brokerage + VAT received - {$trade_reference} - {$client_name}",
                $trade_reference, 'fee'
            );
            if ($entry1) {
                $entries_created++;
                error_log("Cash entry recorded: {$total_fees} for trade {$trade_reference}");
            }
            
            // Entry 2: Credit Brokerage Income (our revenue)
            $entry2 = recordGeneralLedgerEntry(
                $db, $trade_date, $brokerage_income,
                0, $brokerage_fee,
                "Equity brokerage income - {$trade_reference}",
                $trade_reference, 'fee'
            );
            if ($entry2) {
                $entries_created++;
                error_log("Brokerage income recorded: {$brokerage_fee} for trade {$trade_reference}");
            }
            
            // Entry 3: Credit VAT Payable (liability to government)
            if ($vat_fee > 0) {
                $entry3 = recordGeneralLedgerEntry(
                    $db, $trade_date, $vat_payable,
                    0, $vat_fee,
                    "VAT on equity brokerage - {$trade_reference}",
                    $trade_reference, 'fee'
                );
                if ($entry3) {
                    $entries_created++;
                    error_log("VAT payable recorded: {$vat_fee} for trade {$trade_reference}");
                }
            }
        }
        
        error_log("Created {$entries_created} accounting entries for trade {$trade_reference}");
        return $entries_created > 0;
        
    } catch (Exception $e) {
        error_log("Error creating equity accounting entries: " . $e->getMessage());
        return false;
    }
}

// Insert regulatory fees into assignments table for accountant review - INCLUDING VRF
function recordRegulatoryFeeAssignment($db, $trade_reference, $fees, $client_name, $trade_date, $security_id, $security_name, $consideration, $trade_side, $created_by) {
    try {
        $stmt = $db->prepare("
            INSERT INTO regulatory_fee_assignments 
            (trade_reference, client_name, security_id, security_name, trade_date, 
             dse_fee, cmsa_fee, csd_fee, vrf_fee, total_fees, consideration, trade_side,
             status, treatment_type, created_by, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', NULL, ?, NOW(), NOW())
        ");
        
        $dse_fee = $fees['dse'] ?? 0;
        $cmsa_fee = $fees['cmsa'] ?? 0;
        $csd_fee = $fees['csd'] ?? 0;
        $vrf_fee = $fees['vrf'] ?? 0; // INCLUDING VRF NOW
        
        $total_fees = $dse_fee + $cmsa_fee + $csd_fee + $vrf_fee;
        
        $result = $stmt->execute([
            $trade_reference,
            substr($client_name, 0, 255),
            substr($security_id, 0, 50),
            substr($security_name, 0, 200),
            $trade_date,
            round($dse_fee, 2),
            round($cmsa_fee, 2),
            round($csd_fee, 2),
            round($vrf_fee, 2),
            round($total_fees, 2),
            round($consideration, 2),
            $trade_side,
            $created_by
        ]);
        
        if ($result) {
            error_log("Successfully inserted regulatory fee assignment for trade: {$trade_reference} - DSE: {$dse_fee}, CMSA: {$cmsa_fee}, CSD: {$csd_fee}, VRF: {$vrf_fee}, Total: {$total_fees}");
        } else {
            error_log("Failed to insert regulatory fee assignment for trade: {$trade_reference}");
        }
        
        return $result;
        
    } catch (Exception $e) {
        error_log("Error recording regulatory fee assignment: " . $e->getMessage());
        return false;
    }
}

// Record company's own equity investment transactions using hierarchical accounts
function recordCompanyEquityInvestment($db, $trade_reference, $consideration, $trade_side, $trade_date) {
    try {
        // Get hierarchical accounts using new COA structure
        $investment_account = getAccountIdByCode($db, 'equity_investments'); // 1253 - Equity Investments
        $cash_equity_account = getAccountIdByCode($db, 'cash_bank');         // 1112 - Cash at Bank
        
        $entries_created = 0;
        
        if ($trade_side === 'buy') {
            // BUY: Company purchases equities for its own portfolio
            $entry1 = recordGeneralLedgerEntry(
                $db, $trade_date, $investment_account,
                $consideration, 0,
                "Company equity purchase - {$trade_reference}",
                $trade_reference, 'company_investment'
            );
            if ($entry1) $entries_created++;
            
            $entry2 = recordGeneralLedgerEntry(
                $db, $trade_date, $cash_equity_account,
                0, $consideration,
                "Cash paid for company equity - {$trade_reference}",
                $trade_reference, 'company_investment'
            );
            if ($entry2) $entries_created++;
            
        } else {
            // SELL: Company sells equities from its portfolio
            $entry1 = recordGeneralLedgerEntry(
                $db, $trade_date, $cash_equity_account,
                $consideration, 0,
                "Cash from company equity sale - {$trade_reference}",
                $trade_reference, 'company_investment'
            );
            if ($entry1) $entries_created++;
            
            $entry2 = recordGeneralLedgerEntry(
                $db, $trade_date, $investment_account,
                0, $consideration,
                "Company equity sold - {$trade_reference}",
                $trade_reference, 'company_investment'
            );
            if ($entry2) $entries_created++;
        }
        
        return $entries_created > 0;
        
    } catch (Exception $e) {
        error_log("Error recording company equity investment: " . $e->getMessage());
        return false;
    }
}

// Calculate custodian fees
function calculateCustodianFees($fees) {
    $brokerage_fees = ($fees['brokerage'] ?? 0) + ($fees['vat'] ?? 0);
    $other_fees = ($fees['cmsa'] ?? 0) + ($fees['csd'] ?? 0) + ($fees['dse'] ?? 0) + ($fees['vrf'] ?? 0);
    $total_fees = $brokerage_fees + $other_fees;
    
    return [
        'brokerage_fees' => round($brokerage_fees, 2),
        'other_fees' => round($other_fees, 2),
        'total_fees' => round($total_fees, 2)
    ];
}

// Record custodian trade
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

// Record ETF trade in etf_trades table
function recordETFTrade($db, $trade_data) {
    try {
        $stmt = $db->prepare("
            INSERT INTO etf_trades 
            (trade_reference, etf_id, etf_name, isin, client_cds_account, client_name, 
             trade_side, quantity, price, consideration, trade_date, settlement_date, 
             currency, sca_code, status) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $result = $stmt->execute([
            $trade_data['trade_reference'],
            $trade_data['etf_id'],
            $trade_data['etf_name'],
            $trade_data['isin'],
            $trade_data['client_cds_account'],
            $trade_data['client_name'],
            $trade_data['trade_side'],
            $trade_data['quantity'],
            $trade_data['price'],
            $trade_data['consideration'],
            $trade_data['trade_date'],
            $trade_data['settlement_date'],
            $trade_data['currency'],
            $trade_data['sca_code'],
            'active'
        ]);
        
        return $result;
        
    } catch (Exception $e) {
        error_log("Error recording ETF trade: " . $e->getMessage());
        return false;
    }
}

// Generate Trial Balance using hierarchical accounts
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
                (period_date, account_id, account_code, account_name, debit_balance, credit_balance, is_closing, transaction_date)
                VALUES (?, ?, ?, ?, ?, ?, 0, NOW())
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

// MODIFIED: UPDATED Map CSV row to database with PROPER date conversion
function mapCSVRowToDatabase($row) {
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
    
    // Use other possible date column names if primary ones are empty
    if (empty($trade_date) && !empty($trimmed_row['Date'])) {
        $date_str = trim($trimmed_row['Date']);
        if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $date_str, $matches)) {
            $month = str_pad($matches[1], 2, '0', STR_PAD_LEFT);
            $day = str_pad($matches[2], 2, '0', STR_PAD_LEFT);
            $year = $matches[3];
            $trade_date = $year . '-' . $month . '-' . $day;
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
        'stock_name' => $trimmed_row['Security'] ?? 'Unknown Stock',
        'client_name' => $client_name,
        'client_cds' => $client_cds,
        'trade_side' => strtolower(trim($trimmed_row['Buy\Sell'] ?? '')),
        'quantity' => $trimmed_row['Quantity'] ?? 0,
        'price' => $trimmed_row['Price'] ?? 0,
        'sca_code' => $trimmed_row['SCA Code'] ?? '',
        'trade_date' => $trade_date ?: date('Y-m-d'),
        'settlement_date' => $settlement_date ?: date('Y-m-d', strtotime('+2 days')),
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
        'isin' => $trimmed_row['ISIN'] ?? '' // Added for ETFs
    ];
}

// MODIFIED: Updated ETF validation function with client check
function validateEquityData($row, $line_num, $db, $company_code) {
    $errors = [];
    
    $mapped_data = mapCSVRowToDatabase($row);
    
    // Check asset class - process both Equity and Exchange Traded Funds
    $asset_class = strtolower(trim($mapped_data['asset_class'] ?? ''));
    if (!in_array($asset_class, ['equity', 'exchange traded funds'])) {
        $errors[] = "Only Equity and Exchange Traded Funds asset classes supported. Found: '{$asset_class}'";
        return $errors;
    }
    
    $is_etf = ($asset_class === 'exchange traded funds');
    
    // Check for required fields with proper whitespace handling
    $security_id = $mapped_data['security_id'];
    if (empty($security_id) || trim($security_id) === '') {
        $errors[] = "Security ID is required";
    } else {
        // For ETFs, we're more lenient - they might not exist in equities_settings yet
        if (!$is_etf) {
            // For regular equities, check if they exist in equities_settings
            $stmt = $db->prepare("SELECT COUNT(*) as count FROM equities_settings WHERE TRIM(security_id) = ?");
            $stmt->execute([$security_id]);
            $security_exists = $stmt->fetch()['count'] > 0;
            
            if (!$security_exists) {
                $errors[] = "Security ID '{$security_id}' not found in equities settings";
            }
        } else {
            // For ETFs, check if they exist, if not we'll auto-create them
            $stmt = $db->prepare("SELECT COUNT(*) as count FROM equities_settings WHERE TRIM(security_id) = ?");
            $stmt->execute([$security_id]);
            $security_exists = $stmt->fetch()['count'] > 0;
            
            if (!$security_exists) {
                // Auto-create ETF in equities_settings
                try {
                    $create_stmt = $db->prepare("INSERT INTO equities_settings (security_id, description, isin, costing_basis, market_price, valuation_price, share_type, market_segment, is_active) VALUES (?, ?, ?, 'WAUC', 0, 0, 'ETF', 'MIM', 1)");
                    $create_stmt->execute([
                        $security_id, 
                        $security_id, 
                        $mapped_data['isin'] ?? ''
                    ]);
                    error_log("Auto-created ETF in equities_settings: {$security_id}");
                } catch (Exception $e) {
                    error_log("Error auto-creating ETF {$security_id}: " . $e->getMessage());
                }
            }
        }
    }
    
    // For ETFs, ISIN is required
    if ($is_etf && (empty($mapped_data['isin']) || trim($mapped_data['isin']) === '')) {
        $errors[] = "ISIN is required for ETFs";
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
    
    // Check for duplicate trade
    if (!empty($mapped_data['client_cds']) && !empty($security_id) && !empty($mapped_data['trade_date'])) {
        $quantity = !empty($mapped_data['quantity']) ? (int)$mapped_data['quantity'] : 0;
        $price = !empty($mapped_data['price']) ? (float)$mapped_data['price'] : 0;
        $trade_side = strtolower(trim($mapped_data['trade_side']));
        
        if (isDuplicateTrade($db, $mapped_data['client_cds'], $security_id, $mapped_data['trade_date'], $quantity, $price, $trade_side)) {
            $errors[] = "Duplicate trade detected. This trade already exists in the database.";
        }
    }
    
    return $errors;
}

// UPDATED: Filter equity rows with debug logging
function filterEquityRows($rows) {
    $equity_rows = [];
    
    foreach ($rows as $index => $row) {
        $mapped_data = mapCSVRowToDatabase($row);
        $asset_class = strtolower(trim($mapped_data['asset_class'] ?? ''));
        
        // Log what we're seeing
        error_log("Row {$index}: Asset Class = '{$asset_class}'");
        
        // Process both Equity and Exchange Traded Funds
        if (in_array($asset_class, ['equity', 'exchange traded funds'])) {
            $equity_rows[] = $row;
            error_log("  -> ACCEPTED as Equity/ETF");
        } else {
            error_log("  -> REJECTED (not Equity or ETF)");
        }
    }
    
    error_log("Total rows: " . count($rows) . ", Equity/ETF rows: " . count($equity_rows));
    return $equity_rows;
}

function processDataInChunks($equity_rows) {
    $preview_data = [];
    $line_number = 2; // Start after header
    
    foreach ($equity_rows as $row) {
        $mapped_data = mapCSVRowToDatabase($row);
        $is_company_trade = (trim(strtolower($mapped_data['client_name'])) === trim(strtolower($GLOBALS['company_name'])));
        $is_custodian_trade = isCustodianTrade($mapped_data['sca_code'], $GLOBALS['company_code']);
        $asset_class = strtolower(trim($mapped_data['asset_class'] ?? ''));
        $is_etf = ($asset_class === 'exchange traded funds');
        
        $preview_data[] = [
            'line_number' => $line_number++,
            'data' => $row,
            'mapped_data' => $mapped_data,
            'has_errors' => false, // Will be set during validation
            'errors' => [],
            'is_company_trade' => $is_company_trade,
            'is_custodian_trade' => $is_custodian_trade,
            'is_etf' => $is_etf
        ];
    }
    
    return $preview_data;
}

// Main file processing logic
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_FILES['share_file']) && $_FILES['share_file']['error'] === UPLOAD_ERR_OK) {
        $file_errors = validateUploadedFile($_FILES['share_file']);
        if (!empty($file_errors)) {
            $error_message = "File validation failed: " . implode(', ', $file_errors);
        } else {
            $file = $_FILES['share_file'];
            try {
                $rows = parseCSVSecurely($file['tmp_name']);
                if (empty($rows)) {
                    $error_message = "No valid data found in the uploaded file.";
                } else {
                    // DEBUG: Log CSV info
                    error_log("=== CSV DEBUG INFO ===");
                    error_log("Total rows in CSV: " . count($rows));
                    
                    $equity_rows = filterEquityRows($rows);
                    $total_rows = count($rows);
                    $equity_count = count($equity_rows);
                    
                    if (empty($equity_rows)) {
                        $error_message = "No equity or ETF data found in the uploaded file. Please ensure the CSV contains rows with 'Asset Class' set to 'Equity' or 'Exchange Traded Funds'.";
                    } else {
                        $preview_data = processDataInChunks($equity_rows);
                        $has_errors = false;
                        $errors = [];
                        $error_count = 0;
                        $valid_count = 0;
                        
                        // Validate each row
                        foreach ($preview_data as &$preview_row) {
                            $validation_errors = validateEquityData($preview_row['data'], $preview_row['line_number'], $db, $company_code);
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
                        error_log("Validation Results: {$valid_count} valid, {$error_count} errors");
                        foreach ($preview_data as $preview_row) {
                            if ($preview_row['has_errors']) {
                                error_log("Line {$preview_row['line_number']} errors: " . implode(', ', $preview_row['errors']));
                            }
                        }
                        
                        if (!$has_errors && !empty($preview_data)) {
                            // Debug logging
                            error_log("=== EQUITY/ETF UPLOAD DEBUG ===");
                            error_log("Starting transaction for " . count($preview_data) . " trades");
                            
                            $db->beginTransaction();
                            try {
                                $processed = 0;
                                $financial_entries_created = 0;
                                $custodian_trades_processed = 0;
                                $custodian_trades_recorded = 0;
                                $company_investments_recorded = 0;
                                $etf_trades_recorded = 0;
                                $regulatory_assignments_created = 0;
                                
                                foreach ($preview_data as $preview_row) {
                                    $mapped_data = $preview_row['mapped_data'];
                                    
                                    $security_id = $mapped_data['security_id'];
                                    $trade_date = $mapped_data['trade_date'];
                                    $client_cds = $mapped_data['client_cds'];
                                    $client_name = $mapped_data['client_name'];
                                    $sca_code = $mapped_data['sca_code'];
                                    $is_custodian_trade = isCustodianTrade($sca_code, $company_code);
                                    $is_company_trade = (trim(strtolower($client_name)) === trim(strtolower($company_name)));
                                    $is_etf = $preview_row['is_etf'];
                                    $trade_side = $mapped_data['trade_side'];
                                    $quantity = !empty($mapped_data['quantity']) ? (int)$mapped_data['quantity'] : 0;
                                    $price = !empty($mapped_data['price']) ? (float)$mapped_data['price'] : 0;
                                    $consideration = !empty($mapped_data['consideration']) ? (float)$mapped_data['consideration'] : ($quantity * $price);
                                    $settlement_date = $mapped_data['settlement_date'];
                                    
                                    // NEW: Check and insert client if not exists
                                    if (!empty($client_cds) && !empty($client_name)) {
                                        $client_id = checkAndInsertClient($db, $client_cds, $client_name, $current_user['username'] ?? 'system');
                                        if ($client_id) {
                                            error_log("Client ensured: {$client_name} (CDS: {$client_cds})");
                                        }
                                    }
                                    
                                    // NEW: Generate unique short trade reference
                                    $trade_reference = generateShortTradeReference($db);
                                    error_log("Generated trade reference: {$trade_reference}");
                                    
                                    // FIXED: Use exact enum values from your database schema
                                    $asset_class = $is_etf ? 'Exchange Traded Funds' : 'equity';
                                    
                                    // Insert trade record
                                    $trade_insert_stmt = $db->prepare("
                                        INSERT INTO trades (
                                            trade_reference, asset_class, security_id, security_name,
                                            client_cds_account, client_name, 
                                            counterparty_name, counterparty_cds_account,
                                            trade_side, quantity, price, consideration,
                                            trade_date, settlement_date, currency, sca_code, status, uploaded_by,
                                            capacity, broker_name, counterparty_broker, brokerage_fee_type, final_brokerage_fee
                                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                                    ");

                                    $counterparty_name = $mapped_data['counterparty_name'] ?? 'Unknown';
                                    $counterparty_cds = $mapped_data['counterparty_cds'] ?? '';
                                    $broker_name = $mapped_data['broker_name'] ?? '';
                                    $counterparty_broker = $mapped_data['counterparty_broker'] ?? '';
                                    $capacity = $mapped_data['capacity'] ?? 'principal';
                                    
                                    $trade_insert_stmt->execute([
                                        $trade_reference, 
                                        $asset_class, 
                                        substr($security_id, 0, 50),
                                        substr($mapped_data['stock_name'], 0, 100),
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
                                        'normal',
                                        0.00
                                    ]);
                                    
                                    error_log("Successfully inserted trade: {$trade_reference} - Asset Class: {$asset_class}");
                                    
                                    // Record ETF trade in etf_trades table if it's an ETF
                                    if ($is_etf) {
                                        $etf_trade_data = [
                                            'trade_reference' => $trade_reference,
                                            'etf_id' => substr($security_id, 0, 20),
                                            'etf_name' => substr($mapped_data['stock_name'], 0, 200),
                                            'isin' => substr($mapped_data['isin'] ?? '', 0, 50),
                                            'client_cds_account' => substr($client_cds, 0, 50),
                                            'client_name' => substr($client_name, 0, 255),
                                            'trade_side' => $trade_side,
                                            'quantity' => $quantity,
                                            'price' => $price,
                                            'consideration' => $consideration,
                                            'trade_date' => $trade_date,
                                            'settlement_date' => $settlement_date,
                                            'currency' => 'TZS',
                                            'sca_code' => substr($sca_code, 0, 20)
                                        ];
                                        
                                        if (recordETFTrade($db, $etf_trade_data)) {
                                            $etf_trades_recorded++;
                                            error_log("Successfully recorded ETF trade: {$trade_reference}");
                                        } else {
                                            error_log("Failed to record ETF trade: {$trade_reference}");
                                        }
                                    }
                                    
                                    // Record company investment if it's a company trade
                                    if ($is_company_trade && $consideration > 0) {
                                        if (recordCompanyEquityInvestment($db, $trade_reference, $consideration, $trade_side, $trade_date)) {
                                            $company_investments_recorded++;
                                            error_log("Company investment recorded: {$trade_reference}");
                                        }
                                    }
                                    
                                    // Create financial entries and record regulatory fee assignments
                                    if ($consideration > 0) {
                                        $fees = calculateEquityFees($db, $consideration);
                                        
                                        // Record regulatory fee assignment for accountant review - INCLUDING VRF
                                        if (($fees['dse'] > 0 || $fees['cmsa'] > 0 || $fees['csd'] > 0 || $fees['vrf'] > 0)) {
                                            $assignment_result = recordRegulatoryFeeAssignment(
                                                $db,
                                                $trade_reference,
                                                $fees,
                                                $client_name,
                                                $trade_date,
                                                $security_id,
                                                $mapped_data['stock_name'],
                                                $consideration,
                                                $trade_side,
                                                $current_user['username'] ?? 'system'
                                            );
                                            
                                            if ($assignment_result) {
                                                $regulatory_assignments_created++;
                                                error_log("Regulatory fee assignment recorded: {$trade_reference} (Includes VRF)");
                                            }
                                        }
                                        
                                        // Create accounting entries for brokerage + VAT only
                                        if (createEquityAccountingEntries($db, $trade_reference, $consideration, $fees, $trade_side, $client_name, $company_name, $trade_date, $is_custodian_trade)) {
                                            $financial_entries_created++;
                                            error_log("Accounting entries created for trade: {$trade_reference}");
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
                                                    'asset_class' => $asset_class, // Use the exact enum value
                                                    'security_id' => substr($security_id, 0, 100),
                                                    'security_name' => substr($mapped_data['stock_name'], 0, 255),
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
                                                    error_log("Custodian trade recorded: {$trade_reference}");
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
                                    $success_message = "Successfully processed {$processed} trades";
                                    if ($etf_trades_recorded > 0) {
                                        $success_message .= " ({$etf_trades_recorded} ETF trades)";
                                    }
                                    
                                    if ($financial_entries_created > 0) {
                                        $success_message .= ". Created financial entries for {$financial_entries_created} trades";
                                        if ($company_investments_recorded > 0) {
                                            $success_message .= " ({$company_investments_recorded} company investments recorded)";
                                        }
                                        if ($custodian_trades_processed > 0) {
                                            $success_message .= " ({$custodian_trades_processed} custodian trades)";
                                            if ($custodian_trades_recorded > 0) {
                                                $success_message .= " - {$custodian_trades_recorded} recorded in custodian trades";
                                            }
                                        }
                                    }
                                    
                                    if ($regulatory_assignments_created > 0) {
                                        $success_message .= ". <strong>{$regulatory_assignments_created} regulatory fee assignments created</strong> for accountant review (includes VRF fees).";
                                    }
                                    
                                    $success_message .= "<br><small><strong>Trade References Generated:</strong> " . count($preview_data) . " unique references (6-7 chars each)</small>";
                                    $preview_data = [];
                                }
                                
                            } catch (Exception $e) {
                                $db->rollBack();
                                $error_message = "Error processing file: " . $e->getMessage();
                                $has_errors = true;
                                // Add detailed logging
                                error_log("EQUITY/ETF UPLOAD TRANSACTION ERROR: " . $e->getMessage());
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
        if (isset($_FILES['share_file'])) {
            switch ($_FILES['share_file']['error']) {
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
            <h1 class="page-title">Upload Shares & ETFs</h1>
            <p class="page-subtitle">Upload equity and ETF data from CSV files - Processes rows with Asset Class = 'Equity' or 'Exchange Traded Funds'</p>
            <p class="text-muted"><small>
                <strong>Company:</strong> <?php echo htmlspecialchars($company_name); ?> | 
                <strong>Code:</strong> <?php echo htmlspecialchars($company_code); ?>
            </small></p>
            <p class="text-info small">
                <i class="bi bi-info-circle"></i> <strong>NEW FEATURES:</strong> Auto-creates clients, prevents duplicate trades, generates short trade references (6-7 chars)
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
                <h5 class="mb-0">Share & ETF Upload</h5>
            </div>
            <div class="card-body">
                <form method="POST" enctype="multipart/form-data" id="uploadForm">
                    <div class="mb-3">
                        <label for="share_file" class="form-label">Select Share/ETF CSV File</label>
                        <input type="file" class="form-control" id="share_file" name="share_file" accept=".csv" required>
                        <div class="form-text">
                            <strong>IMPORTANT:</strong> Only rows with <strong>"Asset Class = Equity"</strong> or <strong>"Asset Class = Exchange Traded Funds"</strong> will be processed<br>
                            <strong>Required CSV Columns (from your file format):</strong><br>
                            • <strong>Security</strong> - Security identifier (must match equities_settings)<br>
                            • <strong>Name</strong> - Client name<br>
                            • <strong>CSD Account</strong> - Client CDS account number<br>
                            • <strong>SCA Code</strong> - Custodian code (required)<br>
                            • <strong>Buy\Sell</strong> - Trade direction (Buy/Sell)<br>
                            • <strong>Quantity</strong> - Number of shares/units<br>
                            • <strong>Price</strong> - Price per share/unit<br>
                            • <strong>Asset Class</strong> - Must be 'Equity' or 'Exchange Traded Funds'<br>
                            • <strong>ISIN</strong> - Required for ETFs (optional for equities)<br>
                            • <strong>Trade Date</strong> - Date format: MM/DD/YYYY (will be converted automatically)<br>
                            • <strong>Settlement Date</strong> - Date format: MM/DD/YYYY (will be converted automatically)<br>
                            <strong>Security Features:</strong> 5MB limit, automatic validation, ETF auto-creation
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary" id="uploadButton">
                        <i class="bi bi-upload me-1"></i>Upload Shares & ETFs
                    </button>
                    <a href="enter_shares.php" class="btn btn-secondary">Back to Shares</a>
                </form>
            </div>
        </div>
        
        <?php if ($has_errors && !empty($preview_data)): ?>
        <div class="card mt-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0">Validation Errors Found - Equity & ETF Rows Only</h5>
                <span class="badge bg-danger">
                    <?php 
                    $error_count = 0;
                    $etf_count = 0;
                    foreach ($preview_data as $row) {
                        $error_count += count($row['errors']);
                        if ($row['is_etf']) {
                            $etf_count++;
                        }
                    }
                    echo count($preview_data) . ' rows (' . $etf_count . ' ETFs) • ' . $error_count . ' validation errors'; 
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
                                <th>Financial Entry</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($preview_data as $preview_row): 
                                $mapped_data = $preview_row['mapped_data'];
                                $consideration = !empty($mapped_data['consideration']) ? (float)$mapped_data['consideration'] : ((float)$mapped_data['quantity'] * (float)$mapped_data['price']);
                            ?>
                            <tr class="<?php echo $preview_row['has_errors'] ? 'table-danger' : 'table-success'; ?>">
                                <td class="fw-bold"><?php echo $preview_row['line_number']; ?></td>
                                <td>
                                    <?php echo htmlspecialchars($mapped_data['security_id']); ?>
                                    <?php if ($preview_row['is_etf']): ?>
                                        <span class="badge bg-purple ms-1">ETF</span>
                                    <?php endif; ?>
                                </td>
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
                                <td>
                                    <?php echo htmlspecialchars(ucfirst($mapped_data['asset_class'])); ?>
                                </td>
                                <td><?php echo htmlspecialchars($mapped_data['client_cds']); ?></td>
                                <td><?php echo htmlspecialchars(ucfirst($mapped_data['trade_side'])); ?></td>
                                <td><?php echo safe_int_format($mapped_data['quantity']); ?></td>
                                <td>Tsh<?php echo safe_number_format($mapped_data['price']); ?></td>
                                <td>Tsh<?php echo safe_int_format($consideration); ?></td>
                                <td>
                                    <?php if ($consideration > 0 && !$preview_row['has_errors']): ?>
                                        <span class="badge bg-success">Yes</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary">No</span>
                                    <?php endif; ?>
                                </td>
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
                <h6 class="mb-0">Equity & ETF Fee Structure - TIERED BROKERAGE</h6>
            </div>
            <div class="card-body">
                <small class="text-muted"><strong>TIERED BROKERAGE COMMISSION (Same for both Equity and ETFs):</strong></small>
                <ul class="mb-3">
                    <li><strong>Tier 1 (≤ 10M TZS)</strong>: 1.7%</li>
                    <li><strong>Tier 2 (10M - 40M TZS)</strong>: 1.5%</li>
                    <li><strong>Tier 3 (> 40M TZS)</strong>: 0.8%</li>
                </ul>
                <small class="text-muted"><strong>REGULATORY FEES (for accountant assignment):</strong></small>
                <ul class="mb-0">
                    <li><strong>VAT</strong>: 18% on brokerage (recorded immediately in GL)</li>
                    <li><strong>CMSA Fee</strong>: 0.01% on consideration (assigned later)</li>
                    <li><strong>CSD&R Fee</strong>: 0.0118% on consideration (assigned later)</li>
                    <li><strong>DSE Fee</strong>: 0.02006% on consideration (assigned later)</li>
                    <li><strong>VRF Fee</strong>: 0.0025% on consideration (assigned later - NOW INCLUDED)</li>
                </ul>
                <hr>
                <small class="text-success"><strong>NEW AUTO-FEATURES:</strong></small>
                <ul class="small">
                    <li><strong>Client Auto-Creation:</strong> Creates new clients in database if CDS account doesn't exist</li>
                    <li><strong>Duplicate Prevention:</strong> Checks for identical trades before inserting</li>
                    <li><strong>Short References:</strong> Generates 6-7 character trade references (e.g., T1A2B3C)</li>
                    <li><strong>Date Conversion:</strong> Automatically converts MM/DD/YYYY to YYYY-MM-DD</li>
                </ul>
                <div class="mt-2">
                    <small class="text-warning"><strong>Note:</strong> All regulatory fees (CMSA, CSDR, DSE, VRF) are recorded in <code>regulatory_fee_assignments</code> table for accountant review. The accountant will assign them as either Expense or Liability using the NEW hierarchical accounts.</small>
                </div>
                <div class="mt-2">
                    <small class="text-info"><strong>New Account Structure:</strong></small>
                    <ul class="small">
                        <li><strong>Payable Accounts:</strong> 2111 (CMSA), 2112 (DSE), 2113 (CSDR), 2114 (VRF)</li>
                        <li><strong>Expense Accounts:</strong> 561 (CMSA), 562 (DSE), 563 (CSDR), 564 (VRF)</li>
                        <li><strong>Cash:</strong> 1112 (Cash at Bank)</li>
                        <li><strong>Commission Income:</strong> 411 (Brokerage Commission Income)</li>
                        <li><strong>VAT:</strong> 213 (VAT Payable)</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    document.getElementById('uploadForm').addEventListener('submit', function(e) {
        var fileInput = document.getElementById('share_file');
        var file = fileInput.files[0];
        var uploadButton = document.getElementById('uploadButton');
        if (file) {
            uploadButton.disabled = true;
            uploadButton.innerHTML = '<i class="bi bi-hourglass-split me-1"></i>Processing...';
            var fileSize = file.size / 1024 / 1024;
            if (fileSize > 5) {
                e.preventDefault();
                alert('File size must be less than 5MB');
                uploadButton.disabled = false;
                uploadButton.innerHTML = '<i class="bi bi-upload me-1"></i>Upload Shares & ETFs';
                return false;
            }
            var fileName = file.name.toLowerCase();
            if (!fileName.endsWith('.csv')) {
                e.preventDefault();
                alert('Please select a CSV file');
                uploadButton.disabled = false;
                uploadButton.innerHTML = '<i class="bi bi-upload me-1"></i>Upload Shares & ETFs';
                return false;
            }
        }
    });
});
</script>

<style>
.badge.bg-purple {
    background-color: #6f42c1 !important;
    color: white;
}
</style>

<?php include '../includes/footer.php'; ?>