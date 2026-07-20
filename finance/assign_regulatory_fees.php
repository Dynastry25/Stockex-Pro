<?php
require_once '../config/config.php';
require_once '../auth/auth_middleware.php';

require_login();
require_mandate();

$current_user = get_logged_in_user();
$db = getDBConnection();

$success_message = '';
$error_message = '';
$has_errors = false;

// Set consistent collation
$db->exec("SET NAMES utf8mb4 COLLATE utf8mb4_general_ci");

// Initialize additional variables for this page
$action_message = '';
$assignments = [];

// Fee calculation rates
define('DSE_FEE_RATE', 0.003);      // 0.3%
define('CMSA_FEE_RATE', 0.0025);    // 0.25%
define('CSDR_FEE_RATE', 0.001);     // 0.1%

// Filter parameters
$filter_trade_ref = isset($_GET['trade_ref']) ? trim($_GET['trade_ref']) : '';
$filter_client_name = isset($_GET['client_name']) ? trim($_GET['client_name']) : '';
$filter_start_date = isset($_GET['start_date']) ? $_GET['start_date'] : '';
$filter_end_date = isset($_GET['end_date']) ? $_GET['end_date'] : '';
$filter_status = isset($_GET['status']) ? $_GET['status'] : 'pending';

// Pagination parameters
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$limit = 20; // Items per page
$offset = ($page - 1) * $limit;

/**
 * Get company details from companies table
 */
function getCompanyDetails($db) {
    try {
        $stmt = $db->prepare("SELECT company_code, name as company_name FROM companies WHERE is_active = 1 LIMIT 1");
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$result) {
            return ['company_code' => 'B13/C', 'company_name' => 'Neovam LTD'];
        }
        
        return [
            'company_code' => $result['company_code'],
            'company_name' => $result['company_name']
        ];
    } catch (Exception $e) {
        return ['company_code' => 'B13/C', 'company_name' => 'Neovam LTD'];
    }
}

/**
 * Fetch and calculate regulatory fees from trades
 */
function fetchAndCalculateRegulatoryFees($db) {
    try {
        $currentDate = date('Y-m-d');
        $daysBack = 30; // Look back 30 days for new trades
        
        // Query to find trades without fee assignments
        $query = "
            SELECT 
                t.trade_reference,
                t.client_name,
                t.trade_date,
                t.consideration,
                t.security_id,
                t.security_name,
                t.trade_side,
                -- Calculate DSE fee (0.3% of consideration)
                ROUND(t.consideration * " . DSE_FEE_RATE . ", 2) as dse_fee,
                -- Calculate CMSA fee (0.25% of consideration)
                ROUND(t.consideration * " . CMSA_FEE_RATE . ", 2) as cmsa_fee,
                -- Calculate CSDR fee (0.1% of consideration)
                ROUND(t.consideration * " . CSDR_FEE_RATE . ", 2) as csd_fee,
                -- Total fees
                ROUND((t.consideration * " . DSE_FEE_RATE . ") + 
                      (t.consideration * " . CMSA_FEE_RATE . ") + 
                      (t.consideration * " . CSDR_FEE_RATE . "), 2) as total_fees
            FROM trades t
            WHERE t.trade_date >= DATE_SUB(?, INTERVAL ? DAY)
            AND t.trade_reference NOT IN (
                SELECT trade_reference 
                FROM regulatory_fee_assignments 
                WHERE trade_reference IS NOT NULL
            )
            AND t.consideration > 0
            ORDER BY t.trade_date DESC
        ";
        
        $stmt = $db->prepare($query);
        $stmt->execute([$currentDate, $daysBack]);
        $tradesWithFees = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        error_log("Fetched " . count($tradesWithFees) . " trades for fee calculation");
        return $tradesWithFees;
        
    } catch (Exception $e) {
        error_log("Error fetching regulatory fees: " . $e->getMessage());
        return [];
    }
}

/**
 * Create new regulatory fee assignments
 */
function createNewRegulatoryFeeAssignments($db, $tradesWithFees) {
    try {
        $db->beginTransaction();
        $createdCount = 0;
        
        foreach ($tradesWithFees as $trade) {
            // Check if assignment already exists
            $checkStmt = $db->prepare("
                SELECT id FROM regulatory_fee_assignments 
                WHERE trade_reference = ?
            ");
            $checkStmt->execute([$trade['trade_reference']]);
            
            if (!$checkStmt->fetch()) {
                // Insert new assignment
                $insertStmt = $db->prepare("
                    INSERT INTO regulatory_fee_assignments (
                        trade_reference, client_name, trade_date, consideration,
                        security_id, security_name, trade_side,
                        dse_fee, cmsa_fee, csd_fee, total_fees,
                        status, created_by, created_at, updated_at,
                        is_dismissed
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?, NOW(), NOW(), 0)
                ");
                
                $insertStmt->execute([
                    $trade['trade_reference'],
                    $trade['client_name'],
                    $trade['trade_date'],
                    $trade['consideration'],
                    $trade['security_id'] ?? '',
                    $trade['security_name'] ?? '',
                    $trade['trade_side'] ?? '',
                    $trade['dse_fee'],
                    $trade['cmsa_fee'],
                    $trade['csd_fee'],
                    $trade['total_fees'],
                    $_SESSION['username'] ?? 'system'
                ]);
                
                if ($insertStmt->rowCount() > 0) {
                    $createdCount++;
                    error_log("Created fee assignment for trade: " . $trade['trade_reference']);
                }
            }
        }
        
        $db->commit();
        return $createdCount;
        
    } catch (Exception $e) {
        $db->rollBack();
        error_log("Error creating fee assignments: " . $e->getMessage());
        return 0;
    }
}

/**
 * Get account ID by hierarchical account code - UPDATED for new COA structure
 */
function getAccountIdByCode($db, $account_type, $transaction_type = '') {
    try {
        // Map transaction types to hierarchical account codes from NEW COA
        $account_mapping = [
            // Cash accounts (from 111 - Cash and Cash Equivalents)
            'cash' => '1112',      // Cash at Bank
            
            // Expense accounts (from 56 - Regulatory & Compliance Fees)
            'dse_expense' => '562',    // DSE Fees
            'cmsa_expense' => '561',   // CMSA Fees
            'csdr_expense' => '563',   // CSDR Fees
            
            // Liability accounts (from 211 - Trade Payables)
            'dse_payable' => '2112',   // DSE Fees Payable
            'cmsa_payable' => '2111',  // CMSA Fees Payable
            'csdr_payable' => '2113',  // CSDR Fees Payable
            
            // For backward compatibility with old codes
            'dse_exp' => '562',
            'cmsa_exp' => '561',
            'csdr_exp' => '563',
            'dse_pay' => '2112',
            'cmsa_pay' => '2111',
            'csdr_pay' => '2113',
        ];
        
        // Determine which account code to use
        $search_code = '';
        
        if (isset($account_mapping[$account_type])) {
            $search_code = $account_mapping[$account_type];
        } else {
            // Try to find based on account_type pattern
            if (strpos($account_type, 'cash') !== false) {
                $search_code = '1112'; // Cash at Bank
            } elseif (strpos($account_type, 'expense') !== false || strpos($account_type, '_exp') !== false) {
                if (strpos($account_type, 'dse') !== false) {
                    $search_code = '562'; // DSE Fees expense
                } elseif (strpos($account_type, 'cmsa') !== false) {
                    $search_code = '561'; // CMSA Fees expense
                } elseif (strpos($account_type, 'csd') !== false || strpos($account_type, 'csdr') !== false) {
                    $search_code = '563'; // CSDR Fees expense
                } else {
                    $search_code = '562'; // Default to DSE expense
                }
            } elseif (strpos($account_type, 'payable') !== false || strpos($account_type, '_pay') !== false) {
                if (strpos($account_type, 'dse') !== false) {
                    $search_code = '2112'; // DSE Fees payable
                } elseif (strpos($account_type, 'cmsa') !== false) {
                    $search_code = '2111'; // CMSA Fees payable
                } elseif (strpos($account_type, 'csd') !== false || strpos($account_type, 'csdr') !== false) {
                    $search_code = '2113'; // CSDR Fees payable
                } else {
                    $search_code = '2112'; // Default to DSE payable
                }
            } else {
                $search_code = '1112'; // Default to Cash at Bank
            }
        }
        
        error_log("Searching for account: {$account_type} -> Code: {$search_code}");
        
        // First try exact match with the hierarchical code
        $stmt = $db->prepare("
            SELECT id, account_code, account_name, account_type 
            FROM chart_of_accounts 
            WHERE account_code = ? 
            AND is_active = 1
        ");
        $stmt->execute([$search_code]);
        $account = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($account) {
            error_log("Found exact match: {$account['account_code']} - {$account['account_name']} (ID: {$account['id']})");
            return $account['id'];
        }
        
        // If not found, try to find parent account or similar
        if (strpos($search_code, '111') === 0) {
            // Cash accounts - get Cash at Bank (1112)
            $search_pattern = '1112';
        } elseif (strpos($search_code, '56') === 0) {
            // Expense accounts - get the specific one or parent
            $search_pattern = substr($search_code, 0, 3) . '%';
        } elseif (strpos($search_code, '211') === 0) {
            // Payable accounts - get the specific one or parent
            $search_pattern = substr($search_code, 0, 4) . '%';
        } else {
            $search_pattern = $search_code . '%';
        }
        
        $stmt = $db->prepare("
            SELECT id, account_code, account_name 
            FROM chart_of_accounts 
            WHERE account_code LIKE ? 
            AND is_active = 1 
            ORDER BY LENGTH(account_code) ASC 
            LIMIT 1
        ");
        $stmt->execute([$search_pattern]);
        $similar = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($similar) {
            error_log("Found similar account: {$similar['account_code']} - {$similar['account_name']} (ID: {$similar['id']})");
            return $similar['id'];
        }
        
        // Last resort: get any active cash account
        $stmt = $db->prepare("
            SELECT id FROM chart_of_accounts 
            WHERE account_code LIKE '111%' 
            AND is_active = 1 
            LIMIT 1
        ");
        $stmt->execute();
        $default = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($default) {
            error_log("Using default cash account ID: {$default['id']}");
            return $default['id'];
        }
        
        // Ultimate fallback: get first active account
        $stmt = $db->prepare("SELECT id FROM chart_of_accounts WHERE is_active = 1 LIMIT 1");
        $stmt->execute();
        $fallback = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $result = $fallback ? $fallback['id'] : 1112; // Default to Cash at Bank account code
        error_log("Using ultimate fallback ID: {$result} for {$account_type}");
        
        return $result;
        
    } catch (Exception $e) {
        error_log("Error in getAccountIdByCode for {$account_type}: " . $e->getMessage());
        return 1112; // Default to Cash at Bank ID
    }
}

/**
 * Record general ledger entry using hierarchical accounts - UPDATED
 */
function recordGeneralLedgerEntry($db, $transaction_date, $account_id, $debit, $credit, $description, $reference_no, $reference_type = 'fee') {
    try {
        if (!is_numeric($account_id) || $account_id <= 0) {
            error_log("Invalid account ID: " . $account_id);
            return false;
        }
        
        $debit = max(0, (float)$debit);
        $credit = max(0, (float)$credit);
        
        if ($debit == 0 && $credit == 0) {
            return true;
        }
        
        $created_by = isset($_SESSION['username']) ? $_SESSION['username'] : 'system';
        
        // Get account code and name from hierarchical chart of accounts
        $stmt = $db->prepare("
            SELECT account_code, account_name, account_type, normal_balance 
            FROM chart_of_accounts 
            WHERE id = ?
        ");
        $stmt->execute([$account_id]);
        $account = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$account) {
            error_log("Account not found for ID: " . $account_id);
            return false;
        }
        
        // CRITICAL FIX: reference_type must be one of the ENUM values
        $allowed_reference_types = ['trade', 'fee', 'adjustment', 'receipt', 'payment', 'invoice', 'journal', 'transfer', 'expense', 'income'];
        
        if (!in_array($reference_type, $allowed_reference_types)) {
            error_log("Invalid reference_type '{$reference_type}'. Using 'fee' instead.");
            $reference_type = 'fee'; // Use 'fee' for regulatory fees
        }
        
        // Determine balance type based on normal balance
        $balance_type = ($debit > 0 && $account['normal_balance'] == 'debit') || 
                       ($credit > 0 && $account['normal_balance'] == 'credit') ? 
                       $account['normal_balance'] : 'debit';
        
        // Set currency and other required fields
        $currency = 'TSH';
        $status = 'active';
        
        // Prepare the INSERT statement with all required fields
        $stmt = $db->prepare("
            INSERT INTO general_ledger 
            (transaction_date, account_id, account_code, account_name, debit_amount, credit_amount, 
             running_balance, balance_type, description, reference_no, reference_type, 
             currency, status, created_by, created_at, updated_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
        ");
        
        $result = $stmt->execute([
            $transaction_date, 
            $account_id,
            $account['account_code'],
            $account['account_name'],
            round($debit, 2), 
            round($credit, 2), 
            0.00, // running_balance - will be calculated separately
            $balance_type,
            substr(trim($description), 0, 255), 
            substr(trim($reference_no), 0, 100), 
            $reference_type,
            $currency,
            $status,
            $created_by
        ]);
        
        if ($result) {
            $entry_id = $db->lastInsertId();
            error_log("GL entry #{$entry_id} created: " . $description . 
                     " - Debit: " . $debit . " Credit: " . $credit . 
                     " Account: " . $account['account_code'] . " Type: " . $reference_type);
            return $entry_id;
        } else {
            $error_info = $stmt->errorInfo();
            error_log("GL entry failed: " . $description . " - Error: " . print_r($error_info, true));
            return false;
        }
        
    } catch (Exception $e) {
        error_log("General ledger entry error: " . $e->getMessage());
        error_log("Stack trace: " . $e->getTraceAsString());
        return false;
    }
}

/**
 * Create accounting entries for regulatory fees using hierarchical accounts - UPDATED
 */
function createRegulatoryFeeAccountingEntries($db, $trade_reference, $fees, $client_name, $company_name, $trade_date, $treatment_type) {
    try {
        error_log("=== Creating accounting entries for: " . $trade_reference . " ===");
        error_log("Treatment type: " . $treatment_type);
        
        $entries_created = 0;
        
        // Get hierarchical account IDs using new COA structure
        if ($treatment_type === 'expense') {
            // Expense accounts from 56 - Regulatory & Compliance Fees
            $dse_account = getAccountIdByCode($db, 'dse_expense');      // 562 - DSE Fees
            $cmsa_account = getAccountIdByCode($db, 'cmsa_expense');    // 561 - CMSA Fees
            $csd_account = getAccountIdByCode($db, 'csdr_expense');     // 563 - CSDR Fees
        } else {
            // Liability accounts from 211 - Trade Payables
            $dse_account = getAccountIdByCode($db, 'dse_payable');      // 2112 - DSE Fees Payable
            $cmsa_account = getAccountIdByCode($db, 'cmsa_payable');    // 2111 - CMSA Fees Payable
            $csd_account = getAccountIdByCode($db, 'csdr_payable');     // 2113 - CSDR Fees Payable
        }
        
        // Cash account from 111 - Cash and Cash Equivalents
        $cash_account = getAccountIdByCode($db, 'cash');                // 1112 - Cash at Bank
        
        error_log("Account IDs - DSE: $dse_account, CMSA: $cmsa_account, CSD: $csd_account, Cash: $cash_account");
        
        $dse_fee = $fees['dse'] ?? 0;
        $cmsa_fee = $fees['cmsa'] ?? 0;
        $csd_fee = $fees['csd'] ?? 0;
        
        $total_fees = $dse_fee + $cmsa_fee + $csd_fee;
        error_log("Total fees to record: " . $total_fees);
        
        if ($total_fees > 0) {
            if ($treatment_type === 'expense') {
                // Expense treatment: Debit expense, credit cash
                error_log("Recording as expense treatment");
                
                // For expense accounts (normal balance = debit), we debit to increase
                if ($dse_fee > 0) {
                    $desc = "DSE fees expense - {$trade_reference} - {$client_name}";
                    $entry1 = recordGeneralLedgerEntry(
                        $db, $trade_date, $dse_account,
                        $dse_fee, 0,
                        $desc,
                        $trade_reference, 'expense' // Use 'expense' reference_type
                    );
                    if ($entry1) $entries_created++;
                }
                
                if ($cmsa_fee > 0) {
                    $desc = "CMSA fees expense - {$trade_reference} - {$client_name}";
                    $entry2 = recordGeneralLedgerEntry(
                        $db, $trade_date, $cmsa_account,
                        $cmsa_fee, 0,
                        $desc,
                        $trade_reference, 'expense'
                    );
                    if ($entry2) $entries_created++;
                }
                
                if ($csd_fee > 0) {
                    $desc = "CSDR fees expense - {$trade_reference} - {$client_name}";
                    $entry3 = recordGeneralLedgerEntry(
                        $db, $trade_date, $csd_account,
                        $csd_fee, 0,
                        $desc,
                        $trade_reference, 'expense'
                    );
                    if ($entry3) $entries_created++;
                }
                
                // Credit cash (normal balance = debit, so credit decreases)
                $entry4 = recordGeneralLedgerEntry(
                    $db, $trade_date, $cash_account,
                    0, $total_fees,
                    "Cash payment for regulatory fees - {$trade_reference}",
                    $trade_reference, 'expense'
                );
                if ($entry4) $entries_created++;
                
            } else {
                // Liability treatment: Credit payable, debit cash
                error_log("Recording as liability treatment");
                
                // For liability accounts (normal balance = credit), we credit to increase
                if ($dse_fee > 0) {
                    $desc = "DSE fees payable - {$trade_reference} - {$client_name}";
                    $entry1 = recordGeneralLedgerEntry(
                        $db, $trade_date, $dse_account,
                        0, $dse_fee,
                        $desc,
                        $trade_reference, 'fee' // Use 'fee' reference_type for liabilities
                    );
                    if ($entry1) $entries_created++;
                }
                
                if ($cmsa_fee > 0) {
                    $desc = "CMSA fees payable - {$trade_reference} - {$client_name}";
                    $entry2 = recordGeneralLedgerEntry(
                        $db, $trade_date, $cmsa_account,
                        0, $cmsa_fee,
                        $desc,
                        $trade_reference, 'fee'
                    );
                    if ($entry2) $entries_created++;
                }
                
                if ($csd_fee > 0) {
                    $desc = "CSDR fees payable - {$trade_reference} - {$client_name}";
                    $entry3 = recordGeneralLedgerEntry(
                        $db, $trade_date, $csd_account,
                        0, $csd_fee,
                        $desc,
                        $trade_reference, 'fee'
                    );
                    if ($entry3) $entries_created++;
                }
                
                // Debit cash (normal balance = debit, so debit increases)
                $entry4 = recordGeneralLedgerEntry(
                    $db, $trade_date, $cash_account,
                    $total_fees, 0,
                    "Cash received for regulatory fees - {$trade_reference}",
                    $trade_reference, 'fee'
                );
                if ($entry4) $entries_created++;
            }
        }
        
        error_log("Total entries created: " . $entries_created);
        error_log("=== End accounting entries ===");
        
        return $entries_created > 0;
        
    } catch (Exception $e) {
        error_log("Regulatory fee accounting error: " . $e->getMessage());
        error_log("Stack trace: " . $e->getTraceAsString());
        return false;
    }
}

/**
 * Reverse existing accounting entries and create new ones for re-assignment
 */
function reassignRegulatoryFees($db, $assignment_id, $new_treatment_type) {
    try {
        $db->beginTransaction();
        
        // Get assignment details
        $stmt = $db->prepare("
            SELECT trade_reference, dse_fee, cmsa_fee, csd_fee, total_fees, trade_date, client_name, treatment_type
            FROM regulatory_fee_assignments 
            WHERE id = ? AND status = 'processed'
        ");
        $stmt->execute([$assignment_id]);
        $assignment = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$assignment) {
            throw new Exception("Assignment not found or not processed");
        }
        
        $old_treatment_type = $assignment['treatment_type'];
        
        if ($old_treatment_type === $new_treatment_type) {
            throw new Exception("Assignment is already {$new_treatment_type}");
        }
        
        error_log("Reassigning {$assignment['trade_reference']} from {$old_treatment_type} to {$new_treatment_type}");
        
        // First, reverse the old entries using adjustment reference_type
        $reverse_desc = "Reversal - {$assignment['trade_reference']} - {$assignment['client_name']}";
        
        if ($old_treatment_type === 'expense') {
            // Get expense account IDs
            $dse_account = getAccountIdByCode($db, 'dse_expense');
            $cmsa_account = getAccountIdByCode($db, 'cmsa_expense');
            $csd_account = getAccountIdByCode($db, 'csdr_expense');
            $cash_account = getAccountIdByCode($db, 'cash');
            
            // Reverse expense entries (credit expense, debit cash)
            $fees = [
                'dse' => $assignment['dse_fee'],
                'cmsa' => $assignment['cmsa_fee'],
                'csd' => $assignment['csd_fee']
            ];
            
            $total_fees = $assignment['total_fees'];
            
            // Credit expense accounts to reverse (opposite of original debit)
            if ($fees['dse'] > 0) {
                recordGeneralLedgerEntry(
                    $db, $assignment['trade_date'], $dse_account,
                    0, $fees['dse'],
                    "Reversal: " . $reverse_desc,
                    $assignment['trade_reference'], 'adjustment'
                );
            }
            
            if ($fees['cmsa'] > 0) {
                recordGeneralLedgerEntry(
                    $db, $assignment['trade_date'], $cmsa_account,
                    0, $fees['cmsa'],
                    "Reversal: " . $reverse_desc,
                    $assignment['trade_reference'], 'adjustment'
                );
            }
            
            if ($fees['csd'] > 0) {
                recordGeneralLedgerEntry(
                    $db, $assignment['trade_date'], $csd_account,
                    0, $fees['csd'],
                    "Reversal: " . $reverse_desc,
                    $assignment['trade_reference'], 'adjustment'
                );
            }
            
            // Debit cash to reverse (opposite of original credit)
            recordGeneralLedgerEntry(
                $db, $assignment['trade_date'], $cash_account,
                $total_fees, 0,
                "Reversal: Cash payment for fees - {$assignment['trade_reference']}",
                $assignment['trade_reference'], 'adjustment'
            );
            
        } else {
            // Get payable account IDs
            $dse_account = getAccountIdByCode($db, 'dse_payable');
            $cmsa_account = getAccountIdByCode($db, 'cmsa_payable');
            $csd_account = getAccountIdByCode($db, 'csdr_payable');
            $cash_account = getAccountIdByCode($db, 'cash');
            
            // Reverse liability entries (debit payable, credit cash)
            $fees = [
                'dse' => $assignment['dse_fee'],
                'cmsa' => $assignment['cmsa_fee'],
                'csd' => $assignment['csd_fee']
            ];
            
            $total_fees = $assignment['total_fees'];
            
            // Debit payable accounts to reverse (opposite of original credit)
            if ($fees['dse'] > 0) {
                recordGeneralLedgerEntry(
                    $db, $assignment['trade_date'], $dse_account,
                    $fees['dse'], 0,
                    "Reversal: " . $reverse_desc,
                    $assignment['trade_reference'], 'adjustment'
                );
            }
            
            if ($fees['cmsa'] > 0) {
                recordGeneralLedgerEntry(
                    $db, $assignment['trade_date'], $cmsa_account,
                    $fees['cmsa'], 0,
                    "Reversal: " . $reverse_desc,
                    $assignment['trade_reference'], 'adjustment'
                );
            }
            
            if ($fees['csd'] > 0) {
                recordGeneralLedgerEntry(
                    $db, $assignment['trade_date'], $csd_account,
                    $fees['csd'], 0,
                    "Reversal: " . $reverse_desc,
                    $assignment['trade_reference'], 'adjustment'
                );
            }
            
            // Credit cash to reverse (opposite of original debit)
            recordGeneralLedgerEntry(
                $db, $assignment['trade_date'], $cash_account,
                0, $total_fees,
                "Reversal: Cash received - {$assignment['trade_reference']}",
                $assignment['trade_reference'], 'adjustment'
            );
        }
        
        // Now create new entries for the new treatment type
        $company_details = getCompanyDetails($db);
        $fees = [
            'dse' => $assignment['dse_fee'],
            'cmsa' => $assignment['cmsa_fee'],
            'csd' => $assignment['csd_fee']
        ];
        
        $accounting_result = createRegulatoryFeeAccountingEntries(
            $db, 
            $assignment['trade_reference'],
            $fees,
            $assignment['client_name'],
            $company_details['company_name'],
            $assignment['trade_date'],
            $new_treatment_type
        );
        
        if ($accounting_result) {
            // Update the assignment with new treatment type
            $stmt = $db->prepare("
                UPDATE regulatory_fee_assignments 
                SET treatment_type = ?,
                    reassigned_date = NOW(),
                    reassigned_by = ?,
                    updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$new_treatment_type, $_SESSION['username'], $assignment_id]);
            
            $db->commit();
            error_log("Successfully reassigned {$assignment['trade_reference']} to {$new_treatment_type}");
            return true;
        } else {
            $db->rollBack();
            error_log("Failed to create new accounting entries for reassignment");
            return false;
        }
        
    } catch (Exception $e) {
        $db->rollBack();
        error_log("Reassignment error: " . $e->getMessage());
        return false;
    }
}

// Fix any NULL values in is_dismissed column
try {
    $fix_stmt = $db->prepare("
        UPDATE regulatory_fee_assignments 
        SET is_dismissed = 0 
        WHERE is_dismissed IS NULL OR is_dismissed NOT IN (0, 1, 2)
    ");
    $fix_stmt->execute();
} catch (Exception $e) {
    error_log("Error fixing dismissed values: " . $e->getMessage());
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Handle fetch new fees request
    if (isset($_POST['action']) && $_POST['action'] === 'fetch_new_fees') {
        try {
            // Fetch trades with calculated fees
            $tradesWithFees = fetchAndCalculateRegulatoryFees($db);
            
            if (empty($tradesWithFees)) {
                $success_message = "No new trades found for fee calculation.";
            } else {
                // Create new fee assignments
                $createdCount = createNewRegulatoryFeeAssignments($db, $tradesWithFees);
                
                if ($createdCount > 0) {
                    $success_message = "Successfully fetched and created {$createdCount} new fee assignments.";
                    // Auto-redirect to pending status
                    header("Location: assign_regulatory_fees?status=pending");
                    exit();
                } else {
                    $success_message = "No new fee assignments created. All trades may already have fee assignments.";
                }
            }
            
        } catch (Exception $e) {
            $error_message = "Error fetching new fees: " . $e->getMessage();
        }
    }
    
    if (isset($_POST['action']) && $_POST['action'] === 'assign_fees') {
        $assignment_id = (int)$_POST['assignment_id'];
        $treatment_type = $_POST['treatment_type'];
        $notes = trim($_POST['notes'] ?? '');
        
        if (in_array($treatment_type, ['expense', 'liability'])) {
            try {
                $db->beginTransaction();
                
                // Update the assignment
                $stmt = $db->prepare("
                    UPDATE regulatory_fee_assignments 
                    SET status = 'assigned', 
                        treatment_type = ?,
                        assigned_date = NOW(),
                        assigned_by = ?,
                        notes = ?,
                        is_dismissed = 1,
                        updated_at = NOW()
                    WHERE id = ? AND status = 'pending'
                ");
                
                $stmt->execute([
                    $treatment_type,
                    $_SESSION['username'],
                    $notes,
                    $assignment_id
                ]);
                
                if ($stmt->rowCount() > 0) {
                    // Get assignment details
                    $stmt = $db->prepare("
                        SELECT trade_reference, dse_fee, cmsa_fee, csd_fee, total_fees, trade_date, client_name
                        FROM regulatory_fee_assignments 
                        WHERE id = ?
                    ");
                    $stmt->execute([$assignment_id]);
                    $assignment = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($assignment) {
                        error_log("Processing assignment: " . $assignment['trade_reference']);
                        
                        // Record the regulatory fees using hierarchical accounts
                        $fees = [
                            'dse' => $assignment['dse_fee'],
                            'cmsa' => $assignment['cmsa_fee'],
                            'csd' => $assignment['csd_fee']
                        ];
                        
                        $company_details = getCompanyDetails($db);
                        
                        $accounting_result = createRegulatoryFeeAccountingEntries(
                            $db, 
                            $assignment['trade_reference'],
                            $fees,
                            $assignment['client_name'],
                            $company_details['company_name'],
                            $assignment['trade_date'],
                            $treatment_type
                        );
                        
                        if ($accounting_result) {
                            // Mark as processed
                            $stmt = $db->prepare("
                                UPDATE regulatory_fee_assignments 
                                SET status = 'processed',
                                    processed_date = NOW(),
                                    processed_by = ?,
                                    is_dismissed = 1,
                                    updated_at = NOW()
                                WHERE id = ?
                            ");
                            $stmt->execute([$_SESSION['username'], $assignment_id]);
                            
                            $db->commit();
                            
                            $success_message = "Successfully assigned fees for trade {$assignment['trade_reference']} as {$treatment_type}.";
                        } else {
                            $db->rollBack();
                            $error_message = "Failed to record accounting entries. Check error logs for details.";
                        }
                    } else {
                        $db->rollBack();
                        $error_message = "Assignment not found.";
                    }
                } else {
                    $db->rollBack();
                    $error_message = "Assignment could not be updated or already processed.";
                }
                
            } catch (Exception $e) {
                $db->rollBack();
                $error_message = "Database error: " . $e->getMessage();
                error_log("Assignment error: " . $e->getMessage());
            }
        } else {
            $error_message = "Invalid treatment type.";
        }
    }
    
    // Quick assign single assignment
    if (isset($_POST['action']) && $_POST['action'] === 'quick_assign') {
        $assignment_id = (int)$_POST['assignment_id'];
        $treatment_type = $_POST['treatment_type'];
        
        if (in_array($treatment_type, ['expense', 'liability'])) {
            try {
                $db->beginTransaction();
                
                // Get assignment details
                $stmt = $db->prepare("
                    SELECT trade_reference, dse_fee, cmsa_fee, csd_fee, total_fees, trade_date, client_name
                    FROM regulatory_fee_assignments 
                    WHERE id = ? AND status = 'pending'
                ");
                $stmt->execute([$assignment_id]);
                $assignment = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($assignment) {
                    // Record the regulatory fees
                    $fees = [
                        'dse' => $assignment['dse_fee'],
                        'cmsa' => $assignment['cmsa_fee'],
                        'csd' => $assignment['csd_fee']
                    ];
                    
                    $company_details = getCompanyDetails($db);
                    
                    $accounting_result = createRegulatoryFeeAccountingEntries(
                        $db, 
                        $assignment['trade_reference'],
                        $fees,
                        $assignment['client_name'],
                        $company_details['company_name'],
                        $assignment['trade_date'],
                        $treatment_type
                    );
                    
                    if ($accounting_result) {
                        // Mark as processed
                        $stmt = $db->prepare("
                            UPDATE regulatory_fee_assignments 
                            SET status = 'processed',
                                treatment_type = ?,
                                processed_date = NOW(),
                                processed_by = ?,
                                is_dismissed = 1,
                                updated_at = NOW()
                            WHERE id = ?
                        ");
                        $stmt->execute([$treatment_type, $_SESSION['username'], $assignment_id]);
                        
                        $db->commit();
                        
                        $success_message = "Successfully assigned trade {$assignment['trade_reference']} as {$treatment_type}.";
                    } else {
                        $db->rollBack();
                        $error_message = "Failed to record accounting entries.";
                    }
                } else {
                    $db->rollBack();
                    $error_message = "Assignment not found or already processed.";
                }
                
            } catch (Exception $e) {
                $db->rollBack();
                $error_message = "Error: " . $e->getMessage();
            }
        }
    }
    
    // Bulk assign selected trades - FIXED VERSION
    if (isset($_POST['action']) && $_POST['action'] === 'bulk_assign_selected') {
        $selected_ids = isset($_POST['selected_trades']) ? $_POST['selected_trades'] : [];
        $treatment_type = isset($_POST['treatment_type']) ? $_POST['treatment_type'] : '';
        
        error_log("Bulk assign received: " . count($selected_ids) . " trades, treatment type: " . $treatment_type);
        
        if (!empty($selected_ids) && in_array($treatment_type, ['expense', 'liability'])) {
            try {
                $db->beginTransaction();
                
                $processed_count = 0;
                $failed_count = 0;
                $company_details = getCompanyDetails($db);
                
                foreach ($selected_ids as $assignment_id) {
                    $assignment_id = (int)$assignment_id;
                    
                    // Get assignment details
                    $stmt = $db->prepare("
                        SELECT trade_reference, dse_fee, cmsa_fee, csd_fee, total_fees, trade_date, client_name
                        FROM regulatory_fee_assignments 
                        WHERE id = ? AND status = 'pending'
                    ");
                    $stmt->execute([$assignment_id]);
                    $assignment = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($assignment) {
                        error_log("Processing bulk assignment for: " . $assignment['trade_reference']);
                        
                        // Record the regulatory fees
                        $fees = [
                            'dse' => $assignment['dse_fee'],
                            'cmsa' => $assignment['cmsa_fee'],
                            'csd' => $assignment['csd_fee']
                        ];
                        
                        $accounting_result = createRegulatoryFeeAccountingEntries(
                            $db, 
                            $assignment['trade_reference'],
                            $fees,
                            $assignment['client_name'],
                            $company_details['company_name'],
                            $assignment['trade_date'],
                            $treatment_type
                        );
                        
                        if ($accounting_result) {
                            // Mark as processed
                            $stmt = $db->prepare("
                                UPDATE regulatory_fee_assignments 
                                SET status = 'processed',
                                    treatment_type = ?,
                                    processed_date = NOW(),
                                    processed_by = ?,
                                    is_dismissed = 1,
                                    updated_at = NOW()
                                WHERE id = ?
                            ");
                            $stmt->execute([$treatment_type, $_SESSION['username'], $assignment_id]);
                            $processed_count++;
                            error_log("Successfully processed: " . $assignment['trade_reference']);
                        } else {
                            $failed_count++;
                            error_log("Failed to create accounting entries for: " . $assignment['trade_reference']);
                        }
                    } else {
                        $failed_count++;
                        error_log("Assignment not found or already processed: " . $assignment_id);
                    }
                }
                
                if ($processed_count > 0) {
                    $db->commit();
                    
                    $success_message = "Successfully bulk assigned {$processed_count} selected trades as {$treatment_type}.";
                    if ($failed_count > 0) {
                        $success_message .= " {$failed_count} trades failed.";
                    }
                } else {
                    $db->rollBack();
                    $error_message = "No assignments were processed. All selected trades may already be processed.";
                }
                
            } catch (Exception $e) {
                $db->rollBack();
                $error_message = "Error in bulk assignment: " . $e->getMessage();
                error_log("Bulk assignment error: " . $e->getMessage());
            }
        } else {
            if (empty($selected_ids)) {
                $error_message = "Please select trades to assign.";
            } elseif (!in_array($treatment_type, ['expense', 'liability'])) {
                $error_message = "Invalid treatment type specified.";
            }
        }
    }
    
    // Re-assign processed trades
    if (isset($_POST['action']) && $_POST['action'] === 'reassign_trade') {
        $assignment_id = (int)$_POST['assignment_id'];
        $new_treatment_type = $_POST['treatment_type'];
        
        if (in_array($new_treatment_type, ['expense', 'liability'])) {
            $result = reassignRegulatoryFees($db, $assignment_id, $new_treatment_type);
            
            if ($result) {
                $success_message = "Successfully reassigned trade to {$new_treatment_type}.";
            } else {
                $error_message = "Failed to reassign trade. Check error logs.";
            }
        }
    }
    
    // Bulk reassign selected processed trades - FIXED VERSION
    if (isset($_POST['action']) && $_POST['action'] === 'bulk_reassign_selected') {
        $selected_ids = isset($_POST['selected_trades']) ? $_POST['selected_trades'] : [];
        $new_treatment_type = isset($_POST['treatment_type']) ? $_POST['treatment_type'] : '';
        
        error_log("Bulk reassign received: " . count($selected_ids) . " trades, new treatment type: " . $new_treatment_type);
        
        if (!empty($selected_ids) && in_array($new_treatment_type, ['expense', 'liability'])) {
            $processed_count = 0;
            $failed_count = 0;
            
            foreach ($selected_ids as $assignment_id) {
                $assignment_id = (int)$assignment_id;
                $result = reassignRegulatoryFees($db, $assignment_id, $new_treatment_type);
                
                if ($result) {
                    $processed_count++;
                    error_log("Successfully reassigned: " . $assignment_id);
                } else {
                    $failed_count++;
                    error_log("Failed to reassign: " . $assignment_id);
                }
            }
            
            if ($processed_count > 0) {
                $success_message = "Successfully reassigned {$processed_count} trades to {$new_treatment_type}.";
                if ($failed_count > 0) {
                    $success_message .= " {$failed_count} trades failed.";
                }
            } else {
                $error_message = "No trades were reassigned.";
            }
        } else {
            if (empty($selected_ids)) {
                $error_message = "Please select trades to reassign.";
            } elseif (!in_array($new_treatment_type, ['expense', 'liability'])) {
                $error_message = "Invalid treatment type specified.";
            }
        }
    }
    
    // Dismiss entry
    if (isset($_POST['action']) && $_POST['action'] === 'dismiss_entry') {
        $assignment_id = (int)$_POST['assignment_id'];
        
        try {
            $stmt = $db->prepare("
                SELECT id FROM regulatory_fee_assignments 
                WHERE id = ? AND status = 'processed' AND is_dismissed = 1
            ");
            $stmt->execute([$assignment_id]);
            
            if ($stmt->fetch()) {
                $stmt = $db->prepare("
                    UPDATE regulatory_fee_assignments 
                    SET is_dismissed = 2,
                        dismissed_date = NOW(),
                        dismissed_by = ?,
                        updated_at = NOW()
                    WHERE id = ?
                ");
                $stmt->execute([$_SESSION['username'], $assignment_id]);
                
                $action_message = "Entry dismissed.";
            } else {
                $error_message = "Only processed entries can be dismissed.";
            }
            
        } catch (Exception $e) {
            $error_message = "Error dismissing entry.";
        }
    }
    
    // Undismiss entry
    if (isset($_POST['action']) && $_POST['action'] === 'undismiss_entry') {
        $assignment_id = (int)$_POST['assignment_id'];
        
        try {
            $stmt = $db->prepare("
                SELECT id FROM regulatory_fee_assignments 
                WHERE id = ? AND status = 'processed' AND is_dismissed = 2
            ");
            $stmt->execute([$assignment_id]);
            
            if ($stmt->fetch()) {
                $stmt = $db->prepare("
                    UPDATE regulatory_fee_assignments 
                    SET is_dismissed = 1,
                        dismissed_date = NULL,
                        dismissed_by = NULL,
                        updated_at = NOW()
                    WHERE id = ?
                ");
                $stmt->execute([$assignment_id]);
                
                $action_message = "Entry restored.";
            } else {
                $error_message = "Only dismissed entries can be restored.";
            }
            
        } catch (Exception $e) {
            $error_message = "Error restoring entry.";
        }
    }
    
    // Bulk dismiss selected - FIXED VERSION
    if (isset($_POST['action']) && $_POST['action'] === 'bulk_dismiss_selected') {
        $selected_ids = isset($_POST['selected_trades']) ? $_POST['selected_trades'] : [];
        
        error_log("Bulk dismiss received: " . count($selected_ids) . " trades");
        
        if (!empty($selected_ids)) {
            $dismissed_count = 0;
            
            foreach ($selected_ids as $assignment_id) {
                $assignment_id = (int)$assignment_id;
                
                $stmt = $db->prepare("
                    UPDATE regulatory_fee_assignments 
                    SET is_dismissed = 2,
                        dismissed_date = NOW(),
                        dismissed_by = ?,
                        updated_at = NOW()
                    WHERE id = ? AND status = 'processed' AND is_dismissed = 1
                ");
                $stmt->execute([$_SESSION['username'], $assignment_id]);
                
                if ($stmt->rowCount() > 0) {
                    $dismissed_count++;
                    error_log("Successfully dismissed: " . $assignment_id);
                } else {
                    error_log("Failed to dismiss (not processed or already dismissed): " . $assignment_id);
                }
            }
            
            if ($dismissed_count > 0) {
                $action_message = "Dismissed {$dismissed_count} selected entries.";
            } else {
                $error_message = "No entries were dismissed. All selected entries may not be processed or already dismissed.";
            }
        } else {
            $error_message = "Please select entries to dismiss.";
        }
    }
    
    // Bulk assign for client day - FIXED VERSION
    if (isset($_POST['action']) && $_POST['action'] === 'bulk_assign_client_day') {
        $client_name = isset($_POST['client_name']) ? $_POST['client_name'] : '';
        $trade_day = isset($_POST['trade_day']) ? $_POST['trade_day'] : '';
        $treatment_type = isset($_POST['treatment_type']) ? $_POST['treatment_type'] : '';
        
        error_log("Bulk assign client day received: {$client_name}, {$trade_day}, {$treatment_type}");
        
        if (in_array($treatment_type, ['expense', 'liability'])) {
            try {
                $db->beginTransaction();
                
                // Get all pending assignments for this client/day
                $stmt = $db->prepare("
                    SELECT id, trade_reference, dse_fee, cmsa_fee, csd_fee, total_fees, trade_date, client_name
                    FROM regulatory_fee_assignments 
                    WHERE client_name = ? 
                    AND DATE(trade_date) = ?
                    AND status = 'pending'
                    AND is_dismissed = 0
                ");
                $stmt->execute([$client_name, $trade_day]);
                $assignments = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                $processed_count = 0;
                $company_details = getCompanyDetails($db);
                
                error_log("Found " . count($assignments) . " pending assignments for {$client_name} on {$trade_day}");
                
                foreach ($assignments as $assignment) {
                    // Record the regulatory fees
                    $fees = [
                        'dse' => $assignment['dse_fee'],
                        'cmsa' => $assignment['cmsa_fee'],
                        'csd' => $assignment['csd_fee']
                    ];
                    
                    $accounting_result = createRegulatoryFeeAccountingEntries(
                        $db, 
                        $assignment['trade_reference'],
                        $fees,
                        $assignment['client_name'],
                        $company_details['company_name'],
                        $assignment['trade_date'],
                        $treatment_type
                    );
                    
                    if ($accounting_result) {
                        // Mark as processed
                        $stmt = $db->prepare("
                            UPDATE regulatory_fee_assignments 
                            SET status = 'processed',
                                treatment_type = ?,
                                processed_date = NOW(),
                                processed_by = ?,
                                is_dismissed = 1,
                                updated_at = NOW()
                            WHERE id = ?
                        ");
                        $stmt->execute([$treatment_type, $_SESSION['username'], $assignment['id']]);
                        $processed_count++;
                        error_log("Successfully processed: " . $assignment['trade_reference']);
                    } else {
                        error_log("Failed to create accounting entries for: " . $assignment['trade_reference']);
                    }
                }
                
                if ($processed_count > 0) {
                    $db->commit();
                    
                    $success_message = "Successfully bulk assigned {$processed_count} trades for {$client_name} on {$trade_day} as {$treatment_type}.";
                } else {
                    $db->rollBack();
                    $error_message = "No assignments were processed.";
                }
                
            } catch (Exception $e) {
                $db->rollBack();
                $error_message = "Error in bulk assignment: " . $e->getMessage();
                error_log("Bulk assignment error: " . $e->getMessage());
            }
        }
    }
}

// Auto-fetch new fees if no pending assignments
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $filter_status === 'pending' && isset($_GET['autofetch']) && $_GET['autofetch'] == '1') {
    // Check if there are any pending assignments
    $checkPending = $db->prepare("
        SELECT COUNT(*) as pending_count 
        FROM regulatory_fee_assignments 
        WHERE status = 'pending' AND is_dismissed = 0
    ");
    $checkPending->execute();
    $pendingCount = $checkPending->fetch()['pending_count'];
    
    // If no pending assignments, auto-fetch new ones
    if ($pendingCount == 0) {
        $tradesWithFees = fetchAndCalculateRegulatoryFees($db);
        if (!empty($tradesWithFees)) {
            $createdCount = createNewRegulatoryFeeAssignments($db, $tradesWithFees);
            if ($createdCount > 0) {
                $success_message = "Auto-fetched {$createdCount} new fee assignments.";
            }
        }
    }
}

// Build filter conditions
$filter_conditions = [];
$filter_params = [];

if (!empty($filter_trade_ref)) {
    $filter_conditions[] = "rfa.trade_reference LIKE ?";
    $filter_params[] = "%{$filter_trade_ref}%";
}

if (!empty($filter_client_name)) {
    $filter_conditions[] = "rfa.client_name LIKE ?";
    $filter_params[] = "%{$filter_client_name}%";
}

if (!empty($filter_start_date)) {
    $filter_conditions[] = "rfa.trade_date >= ?";
    $filter_params[] = $filter_start_date;
}

if (!empty($filter_end_date)) {
    $filter_conditions[] = "rfa.trade_date <= ?";
    $filter_params[] = $filter_end_date;
}

// Status filter
if ($filter_status === 'pending') {
    $filter_conditions[] = "rfa.status = 'pending' AND rfa.is_dismissed = 0";
} elseif ($filter_status === 'processed') {
    $filter_conditions[] = "rfa.status = 'processed' AND rfa.is_dismissed = 1";
} elseif ($filter_status === 'dismissed') {
    $filter_conditions[] = "rfa.is_dismissed = 2";
} elseif ($filter_status === 'all') {
    $filter_conditions[] = "rfa.is_dismissed IN (0, 1)";
} else {
    $filter_conditions[] = "rfa.status = ? AND rfa.is_dismissed IN (0, 1)";
    $filter_params[] = $filter_status;
}

// Build query to get assignments grouped by client and trade date
$summary_query = "
    SELECT 
        rfa.client_name,
        DATE(rfa.trade_date) as trade_day,
        COUNT(*) as trade_count,
        SUM(rfa.dse_fee) as total_dse_fee,
        SUM(rfa.cmsa_fee) as total_cmsa_fee,
        SUM(rfa.csd_fee) as total_csd_fee,
        SUM(rfa.total_fees) as total_fees,
        GROUP_CONCAT(rfa.id ORDER BY 
            CASE WHEN rfa.status = 'pending' THEN 0 ELSE 1 END,
            rfa.created_at DESC
        ) as assignment_ids
    FROM regulatory_fee_assignments rfa
";

if (!empty($filter_conditions)) {
    $summary_query .= " WHERE " . implode(" AND ", $filter_conditions);
}

$summary_query .= " GROUP BY rfa.client_name, DATE(rfa.trade_date) 
                    ORDER BY 
                        CASE 
                            WHEN MAX(rfa.status) = 'pending' THEN 0 
                            WHEN MAX(rfa.is_dismissed) = 1 THEN 1
                            ELSE 2 
                        END,
                        DATE(rfa.trade_date) DESC, 
                        rfa.client_name ASC
                    LIMIT :limit OFFSET :offset";

// Get total count
$count_query = "
    SELECT COUNT(DISTINCT CONCAT(rfa.client_name, '|', DATE(rfa.trade_date))) as total_groups
    FROM regulatory_fee_assignments rfa
";

if (!empty($filter_conditions)) {
    $count_query .= " WHERE " . implode(" AND ", $filter_conditions);
}

$stmt = $db->prepare($count_query);
$stmt->execute($filter_params);
$total_count = $stmt->fetch()['total_groups'];
$total_pages = ceil($total_count / $limit);

// Get client/day summaries
$stmt = $db->prepare($summary_query);

foreach ($filter_params as $key => $value) {
    $stmt->bindValue($key + 1, $value);
}

$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);

$stmt->execute();
$client_day_summaries = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get company details
$company_details = getCompanyDetails($db);

include '../includes/header.php';
?>

<div class="container-fluid">
    <div class="page-header">
        <div class="container">
            <h1 class="page-title">Assign Regulatory Fees</h1>
            <p class="page-subtitle">Assign DSE/CMSA/CSDR fees as Expense or Liability</p>
            <p class="text-muted">
                <strong>User:</strong> <?php echo htmlspecialchars($_SESSION['username']); ?> | 
                <strong>Company:</strong> <?php echo htmlspecialchars($company_details['company_name']); ?>
            </p>
            <p class="text-info">
                <small><strong>Note:</strong> Using new hierarchical chart of accounts (COA) structure</small>
            </p>
        </div>
    </div>

    <div class="container">
        <?php if ($success_message): ?>
            <div class="alert alert-success">
                <?php echo htmlspecialchars($success_message); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <?php if ($error_message): ?>
            <div class="alert alert-danger">
                <strong>Error:</strong> <?php echo htmlspecialchars($error_message); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <?php if ($action_message): ?>
            <div class="alert alert-info">
                <?php echo htmlspecialchars($action_message); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <!-- Account Structure Info -->
        <div class="alert alert-info mb-4">
            <h6><i class="fas fa-sitemap me-2"></i>Using New Hierarchical Chart of Accounts</h6>
            <ul class="mb-0 small">
                <li><strong>Cash Account:</strong> 1112 - Cash at Bank (Assets → Current Assets → Cash and Cash Equivalents)</li>
                <li><strong>Expense Accounts:</strong> 
                    561 - CMSA Fees, 
                    562 - DSE Fees, 
                    563 - CSDR Fees (Expenses → Regulatory & Compliance Fees)
                </li>
                <li><strong>Liability Accounts:</strong> 
                    2111 - CMSA Fees Payable, 
                    2112 - DSE Fees Payable, 
                    2113 - CSDR Fees Payable (Liabilities → Current Liabilities → Trade Payables)
                </li>
            </ul>
        </div>

        <!-- Fetch New Fees Section -->
        <div class="card mb-4">
            <div class="card-header bg-primary text-white">
                <h6><i class="fas fa-sync-alt me-2"></i>Fetch New Regulatory Fees</h6>
            </div>
            <div class="card-body">
                <form method="POST" action="">
                    <div class="row align-items-center">
                        <div class="col-md-8">
                            <p class="mb-0">
                                <i class="fas fa-info-circle me-2 text-info"></i>
                                Click to fetch and calculate regulatory fees for recent trades from the last 30 days.
                                <br>
                                <small class="text-muted">
                                    <strong>Fee Rates:</strong> DSE: 0.3% | CMSA: 0.25% | CSDR: 0.1% of trade consideration
                                </small>
                            </p>
                        </div>
                        <div class="col-md-4 text-end">
                            <input type="hidden" name="action" value="fetch_new_fees">
                            <button type="submit" class="btn btn-primary" 
                                    onclick="return confirm('Fetch new regulatory fees? This will process recent trades without fee assignments.')">
                                <i class="fas fa-sync-alt me-2"></i> Fetch New Fees
                            </button>
                            <a href="?status=pending&autofetch=1" class="btn btn-outline-primary ms-2">
                                <i class="fas fa-bolt me-1"></i> Auto-fetch
                            </a>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <!-- Bulk Actions Panel -->
        <div class="card mb-4" id="bulkActionsPanel" style="display: none;">
            <div class="card-header bg-primary text-white">
                <h6 class="mb-0"><i class="fas fa-tasks me-2"></i>Bulk Actions</h6>
            </div>
            <div class="card-body">
                <form method="POST" id="bulkActionForm">
                    <input type="hidden" name="action" id="bulkActionType">
                    <div id="selectedTradesInputs"></div>
                    
                    <div class="row align-items-center">
                        <div class="col-md-8">
                            <div class="mb-2">
                                <span id="selectedCount" class="badge bg-info">0</span> trades selected
                            </div>
                            <div class="btn-group" role="group">
                                <!-- For pending trades -->
                                <button type="button" class="btn btn-success" onclick="showBulkAssignModal('expense')">
                                    <i class="fas fa-file-invoice-dollar me-1"></i>Assign as Expense
                                </button>
                                <button type="button" class="btn btn-info" onclick="showBulkAssignModal('liability')">
                                    <i class="fas fa-balance-scale me-1"></i>Assign as Liability
                                </button>
                                
                                <!-- For processed trades -->
                                <button type="button" class="btn btn-warning" onclick="showBulkReassignModal('expense')">
                                    <i class="fas fa-exchange-alt me-1"></i>Reassign to Expense
                                </button>
                                <button type="button" class="btn btn-secondary" onclick="showBulkReassignModal('liability')">
                                    <i class="fas fa-exchange-alt me-1"></i>Reassign to Liability
                                </button>
                                
                                <!-- For processed trades to dismiss -->
                                <button type="button" class="btn btn-outline-danger" onclick="bulkDismissSelected()">
                                    <i class="fas fa-archive me-1"></i>Dismiss Selected
                                </button>
                            </div>
                        </div>
                        <div class="col-md-4 text-end">
                            <button type="button" class="btn btn-outline-secondary" onclick="clearAllSelections()">
                                <i class="fas fa-times me-1"></i>Clear Selection
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <!-- Filter Section -->
        <div class="card mb-4">
            <div class="card-header">
                <h6>Filter Assignments</h6>
            </div>
            <div class="card-body">
                <form method="GET" class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label">Trade Reference</label>
                        <input type="text" class="form-control" name="trade_ref" 
                               value="<?php echo htmlspecialchars($filter_trade_ref); ?>" 
                               placeholder="TRD-XXXXX">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Client Name</label>
                        <input type="text" class="form-control" name="client_name" 
                               value="<?php echo htmlspecialchars($filter_client_name); ?>" 
                               placeholder="Client name">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Start Date</label>
                        <input type="date" class="form-control" name="start_date" 
                               value="<?php echo htmlspecialchars($filter_start_date); ?>">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">End Date</label>
                        <input type="date" class="form-control" name="end_date" 
                               value="<?php echo htmlspecialchars($filter_end_date); ?>">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Status</label>
                        <select class="form-select" name="status">
                            <option value="pending" <?php echo $filter_status === 'pending' ? 'selected' : ''; ?>>Pending (0)</option>
                            <option value="processed" <?php echo $filter_status === 'processed' ? 'selected' : ''; ?>>Processed (1)</option>
                            <option value="dismissed" <?php echo $filter_status === 'dismissed' ? 'selected' : ''; ?>>Dismissed (2)</option>
                            <option value="all" <?php echo $filter_status === 'all' ? 'selected' : ''; ?>>All Active (0 & 1)</option>
                        </select>
                    </div>
                    <div class="col-12">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-filter"></i> Apply Filters
                        </button>
                        <a href="assign_regulatory_fees.php" class="btn btn-secondary">
                            <i class="fas fa-times"></i> Clear Filters
                        </a>
                        <?php if ($filter_status === 'pending' && $total_count > 0): ?>
                            <span class="badge bg-warning ms-2">
                                <i class="fas fa-clock"></i> <?php echo $total_count; ?> pending groups
                            </span>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>

        <?php if (empty($client_day_summaries)): ?>
            <div class="card">
                <div class="card-body text-center py-5">
                    <h4><i class="fas fa-inbox fa-lg mb-3"></i></h4>
                    <h4>No Assignments Found</h4>
                    <p class="text-muted">No regulatory fee assignments match your criteria.</p>
                    <div class="mt-3">
                        <a href="?status=all" class="btn btn-primary">
                            <i class="fas fa-eye"></i> View All Assignments
                        </a>
                        <form method="POST" class="d-inline">
                            <input type="hidden" name="action" value="fetch_new_fees">
                            <button type="submit" class="btn btn-success ms-2">
                                <i class="fas fa-sync-alt me-1"></i> Fetch New Fees
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        <?php else: ?>
            <?php foreach ($client_day_summaries as $summary): 
                // Get individual trades
                $assignment_ids = explode(',', $summary['assignment_ids']);
                $placeholders = str_repeat('?,', count($assignment_ids) - 1) . '?';
                
                $stmt = $db->prepare("
                    SELECT rfa.*, t.security_id, t.security_name, t.trade_side, t.consideration
                    FROM regulatory_fee_assignments rfa
                    LEFT JOIN trades t ON rfa.trade_reference COLLATE utf8mb4_general_ci = t.trade_reference COLLATE utf8mb4_general_ci
                    WHERE rfa.id IN ($placeholders)
                    ORDER BY 
                        CASE WHEN rfa.status = 'pending' THEN 0 ELSE 1 END,
                        rfa.created_at DESC
                ");
                $stmt->execute($assignment_ids);
                $client_trades = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                $pending_trades = array_filter($client_trades, function($trade) {
                    return $trade['status'] === 'pending' && $trade['is_dismissed'] == 0;
                });
                
                $processed_trades = array_filter($client_trades, function($trade) {
                    return $trade['status'] === 'processed' && $trade['is_dismissed'] == 1;
                });
                
                $dismissed_trades = array_filter($client_trades, function($trade) {
                    return $trade['is_dismissed'] == 2;
                });
            ?>
            <div class="card mb-4">
                <div class="card-header bg-light">
                    <div class="d-flex justify-content-between align-items-center">
                        <div class="d-flex align-items-center">
                            <div class="form-check me-3">
                                <input class="form-check-input select-all-group" type="checkbox" 
                                       data-group="group_<?php echo $summary['client_name'] . '_' . $summary['trade_day']; ?>">
                            </div>
                            <div>
                                <h5 class="mb-1"><i class="fas fa-user me-2"></i><?php echo htmlspecialchars($summary['client_name']); ?></h5>
                                <small class="text-muted">
                                    <i class="fas fa-calendar me-1"></i><?php echo date('F j, Y', strtotime($summary['trade_day'])); ?>
                                    • <i class="fas fa-exchange-alt me-1"></i><?php echo $summary['trade_count']; ?> trades
                                </small>
                            </div>
                        </div>
                        <div>
                            <span class="badge bg-dark fs-6">
                                <i class="fas fa-money-bill-wave me-1"></i>Tsh <?php echo number_format($summary['total_fees'], 2); ?>
                            </span>
                        </div>
                    </div>
                </div>
                
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th width="50">
                                        <div class="form-check">
                                            <input class="form-check-input select-all" type="checkbox">
                                        </div>
                                    </th>
                                    <th width="100">Trade Ref</th>
                                    <th width="80">Side</th>
                                    <th>Security</th>
                                    <th width="100" class="text-end">Consideration</th>
                                    <th width="100" class="text-end">DSE Fee</th>
                                    <th width="100" class="text-end">CMSA Fee</th>
                                    <th width="100" class="text-end">CSDR Fee</th>
                                    <th width="100" class="text-end">Total Fees</th>
                                    <th width="100">Status</th>
                                    <th width="180" class="text-center">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $day_total = 0;
                                foreach ($client_trades as $trade): 
                                    $day_total += $trade['total_fees'];
                                    $is_pending = $trade['status'] === 'pending' && $trade['is_dismissed'] == 0;
                                    $is_processed = $trade['status'] === 'processed' && $trade['is_dismissed'] == 1;
                                    $is_dismissed = $trade['is_dismissed'] == 2;
                                ?>
                                <tr>
                                    <td>
                                        <div class="form-check">
                                            <input class="form-check-input trade-checkbox" type="checkbox" 
                                                   value="<?php echo $trade['id']; ?>"
                                                   data-trade-id="<?php echo $trade['id']; ?>"
                                                   data-status="<?php echo $trade['status']; ?>"
                                                   data-treatment="<?php echo $trade['treatment_type'] ?? ''; ?>">
                                        </div>
                                    </td>
                                    <td>
                                        <div><i class="fas fa-hashtag me-1 text-muted"></i><?php echo htmlspecialchars($trade['trade_reference']); ?></div>
                                        <small class="text-muted"><i class="fas fa-clock me-1"></i><?php echo date('H:i', strtotime($trade['trade_date'])); ?></small>
                                    </td>
                                    <td>
                                        <span class="badge bg-<?php echo $trade['trade_side'] === 'BUY' ? 'success' : 'danger'; ?>">
                                            <i class="fas fa-<?php echo $trade['trade_side'] === 'BUY' ? 'arrow-up' : 'arrow-down'; ?> me-1"></i><?php echo htmlspecialchars($trade['trade_side'] ?? 'N/A'); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div><?php echo htmlspecialchars($trade['security_id'] ?? 'N/A'); ?></div>
                                        <?php if (!empty($trade['security_name'])): ?>
                                            <small class="text-muted"><?php echo htmlspecialchars($trade['security_name']); ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end">Tsh <?php echo number_format($trade['consideration'] ?? 0, 2); ?></td>
                                    <td class="text-end">Tsh <?php echo number_format($trade['dse_fee'], 2); ?></td>
                                    <td class="text-end">Tsh <?php echo number_format($trade['cmsa_fee'], 2); ?></td>
                                    <td class="text-end">Tsh <?php echo number_format($trade['csd_fee'], 2); ?></td>
                                    <td class="text-end fw-bold">Tsh <?php echo number_format($trade['total_fees'], 2); ?></td>
                                    <td>
                                        <?php if ($is_pending): ?>
                                            <span class="badge bg-warning"><i class="fas fa-clock me-1"></i>Pending (0)</span>
                                        <?php elseif ($is_processed): ?>
                                            <span class="badge bg-info"><i class="fas fa-check me-1"></i>Processed (1)</span>
                                            <small class="d-block text-muted"><?php echo $trade['treatment_type']; ?></small>
                                        <?php elseif ($is_dismissed): ?>
                                            <span class="badge bg-secondary"><i class="fas fa-archive me-1"></i>Dismissed (2)</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <?php if ($is_pending): ?>
                                            <div class="btn-group btn-group-sm">
                                                <button type="button" class="btn btn-outline-success" 
                                                        data-bs-toggle="modal" data-bs-target="#assignModal"
                                                        data-trade-id="<?php echo $trade['id']; ?>"
                                                        data-trade-ref="<?php echo htmlspecialchars($trade['trade_reference']); ?>"
                                                        data-client="<?php echo htmlspecialchars($trade['client_name']); ?>"
                                                        data-date="<?php echo date('Y-m-d', strtotime($trade['trade_date'])); ?>"
                                                        data-dse="<?php echo $trade['dse_fee']; ?>"
                                                        data-cmsa="<?php echo $trade['cmsa_fee']; ?>"
                                                        data-csd="<?php echo $trade['csd_fee']; ?>"
                                                        data-total="<?php echo $trade['total_fees']; ?>">
                                                    <i class="fas fa-file-invoice-dollar me-1"></i>Expense
                                                </button>
                                                <button type="button" class="btn btn-outline-info"
                                                        onclick="quickAssign(<?php echo $trade['id']; ?>, 'liability')">
                                                    <i class="fas fa-balance-scale me-1"></i>Liability
                                                </button>
                                            </div>
                                        <?php elseif ($is_processed): ?>
                                            <div class="btn-group btn-group-sm">
                                                <form method="POST" class="d-inline">
                                                    <input type="hidden" name="action" value="dismiss_entry">
                                                    <input type="hidden" name="assignment_id" value="<?php echo $trade['id']; ?>">
                                                    <button type="submit" class="btn btn-outline-secondary btn-sm" 
                                                            onclick="return confirm('Dismiss this entry?')">
                                                        <i class="fas fa-archive me-1"></i>Dismiss
                                                    </button>
                                                </form>
                                                <?php if ($trade['treatment_type'] === 'expense'): ?>
                                                    <button type="button" class="btn btn-outline-warning btn-sm"
                                                            onclick="reassignTrade(<?php echo $trade['id']; ?>, 'liability')">
                                                        <i class="fas fa-exchange-alt me-1"></i>To Liability
                                                    </button>
                                                <?php else: ?>
                                                    <button type="button" class="btn btn-outline-warning btn-sm"
                                                            onclick="reassignTrade(<?php echo $trade['id']; ?>, 'expense')">
                                                        <i class="fas fa-exchange-alt me-1"></i>To Expense
                                                    </button>
                                                <?php endif; ?>
                                            </div>
                                        <?php elseif ($is_dismissed): ?>
                                            <form method="POST" class="d-inline">
                                                <input type="hidden" name="action" value="undismiss_entry">
                                                <input type="hidden" name="assignment_id" value="<?php echo $trade['id']; ?>">
                                                <button type="submit" class="btn btn-outline-primary btn-sm" 
                                                        onclick="return confirm('Restore this entry?')">
                                                    <i class="fas fa-undo me-1"></i>Restore
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                
                                <!-- Day Total -->
                                <tr class="table-light fw-bold">
                                    <td colspan="8" class="text-end">
                                        <i class="fas fa-calculator me-2"></i>Day Total:
                                    </td>
                                    <td class="text-end">Tsh <?php echo number_format($day_total, 2); ?></td>
                                    <td colspan="3"></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
                
                <div class="card-footer">
                    <div class="d-flex justify-content-between align-items-center">
                        <small class="text-muted">
                            <i class="fas fa-clock text-warning me-1"></i><?php echo count($pending_trades); ?> pending, 
                            <i class="fas fa-check text-info me-1"></i><?php echo count($processed_trades); ?> processed, 
                            <i class="fas fa-archive text-secondary me-1"></i><?php echo count($dismissed_trades); ?> dismissed
                        </small>
                        <?php if (count($pending_trades) > 0): ?>
                            <div class="btn-group">
                                <button type="button" class="btn btn-sm btn-outline-success"
                                        onclick="bulkAssignClientDay('<?php echo htmlspecialchars($summary['client_name']); ?>', '<?php echo $summary['trade_day']; ?>', 'expense')">
                                    <i class="fas fa-bolt me-1"></i>Bulk Expense
                                </button>
                                <button type="button" class="btn btn-sm btn-outline-info"
                                        onclick="bulkAssignClientDay('<?php echo htmlspecialchars($summary['client_name']); ?>', '<?php echo $summary['trade_day']; ?>', 'liability')">
                                    <i class="fas fa-bolt me-1"></i>Bulk Liability
                                </button>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
            
            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
            <div class="card">
                <div class="card-body">
                    <nav aria-label="Page navigation">
                        <ul class="pagination justify-content-center">
                            <?php if ($page > 1): ?>
                                <li class="page-item">
                                    <a class="page-link" href="?<?php 
                                        echo http_build_query(array_merge($_GET, ['page' => 1]));
                                    ?>"><i class="fas fa-angle-double-left"></i></a>
                                </li>
                                <li class="page-item">
                                    <a class="page-link" href="?<?php 
                                        echo http_build_query(array_merge($_GET, ['page' => $page - 1]));
                                    ?>"><i class="fas fa-angle-left"></i></a>
                                </li>
                            <?php endif; ?>
                            
                            <?php
                            $start_page = max(1, $page - 2);
                            $end_page = min($total_pages, $page + 2);
                            
                            for ($i = $start_page; $i <= $end_page; $i++):
                            ?>
                                <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                                    <a class="page-link" href="?<?php 
                                        echo http_build_query(array_merge($_GET, ['page' => $i]));
                                    ?>"><?php echo $i; ?></a>
                                </li>
                            <?php endfor; ?>
                            
                            <?php if ($page < $total_pages): ?>
                                <li class="page-item">
                                    <a class="page-link" href="?<?php 
                                        echo http_build_query(array_merge($_GET, ['page' => $page + 1]));
                                    ?>"><i class="fas fa-angle-right"></i></a>
                                </li>
                                <li class="page-item">
                                    <a class="page-link" href="?<?php 
                                        echo http_build_query(array_merge($_GET, ['page' => $total_pages]));
                                    ?>"><i class="fas fa-angle-double-right"></i></a>
                                </li>
                            <?php endif; ?>
                        </ul>
                        <p class="text-center text-muted mt-2">
                            Page <?php echo $page; ?> of <?php echo $total_pages; ?> 
                            • <?php echo $total_count; ?> total groups
                        </p>
                    </nav>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Stats Summary -->
            <div class="row mt-4">
                <div class="col-md-4">
                    <div class="card bg-warning bg-opacity-10">
                        <div class="card-body text-center">
                            <h5 class="card-title text-warning"><i class="fas fa-clock me-2"></i>Pending Fees</h5>
                            <h3 class="text-warning">
                                <?php
                                $stmt = $db->prepare("
                                    SELECT COUNT(*) as count, COALESCE(SUM(total_fees), 0) as total
                                    FROM regulatory_fee_assignments 
                                    WHERE status = 'pending' AND is_dismissed = 0
                                ");
                                $stmt->execute();
                                $pending = $stmt->fetch(PDO::FETCH_ASSOC);
                                ?>
                                Tsh <?php echo number_format($pending['total'], 2); ?>
                            </h3>
                            <p class="text-muted"><?php echo $pending['count']; ?> pending assignments</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card bg-info bg-opacity-10">
                        <div class="card-body text-center">
                            <h5 class="card-title text-info"><i class="fas fa-check-circle me-2"></i>Processed Fees</h5>
                            <h3 class="text-info">
                                <?php
                                $stmt = $db->prepare("
                                    SELECT COUNT(*) as count, COALESCE(SUM(total_fees), 0) as total
                                    FROM regulatory_fee_assignments 
                                    WHERE status = 'processed' AND is_dismissed = 1
                                ");
                                $stmt->execute();
                                $processed = $stmt->fetch(PDO::FETCH_ASSOC);
                                ?>
                                Tsh <?php echo number_format($processed['total'], 2); ?>
                            </h3>
                            <p class="text-muted"><?php echo $processed['count']; ?> processed assignments</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card bg-primary bg-opacity-10">
                        <div class="card-body text-center">
                            <h5 class="card-title text-primary"><i class="fas fa-chart-bar me-2"></i>Total Fees</h5>
                            <h3 class="text-primary">
                                <?php
                                $stmt = $db->prepare("
                                    SELECT COUNT(*) as count, COALESCE(SUM(total_fees), 0) as total
                                    FROM regulatory_fee_assignments 
                                    WHERE is_dismissed IN (0, 1)
                                ");
                                $stmt->execute();
                                $all = $stmt->fetch(PDO::FETCH_ASSOC);
                                ?>
                                Tsh <?php echo number_format($all['total'], 2); ?>
                            </h3>
                            <p class="text-muted"><?php echo $all['count']; ?> total assignments</p>
                        </div>
                    </div>
                </div>
            </div>
            
        <?php endif; ?>
    </div>
</div>

<!-- Assign Modal -->
<div class="modal fade" id="assignModal" tabindex="-1" aria-labelledby="assignModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="assignModalLabel"><i class="fas fa-file-invoice-dollar me-2"></i>Assign Regulatory Fees</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" id="assignForm">
                <div class="modal-body">
                    <input type="hidden" name="action" value="assign_fees">
                    <input type="hidden" name="assignment_id" id="modalAssignmentId">
                    
                    <div class="mb-3">
                        <label class="form-label"><i class="fas fa-hashtag me-1"></i>Trade Reference</label>
                        <input type="text" class="form-control" id="modalTradeRef" readonly>
                    </div>
                    
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label"><i class="fas fa-user me-1"></i>Client</label>
                            <input type="text" class="form-control" id="modalClient" readonly>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label"><i class="fas fa-calendar me-1"></i>Trade Date</label>
                            <input type="text" class="form-control" id="modalDate" readonly>
                        </div>
                    </div>
                    
                    <div class="row mb-3">
                        <div class="col-md-3">
                            <label class="form-label">DSE Fee</label>
                            <input type="text" class="form-control" id="modalDseFee" readonly>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">CMSA Fee</label>
                            <input type="text" class="form-control" id="modalCmsaFee" readonly>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">CSDR Fee</label>
                            <input type="text" class="form-control" id="modalCsdFee" readonly>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Total</label>
                            <input type="text" class="form-control fw-bold" id="modalTotalFee" readonly>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label"><i class="fas fa-balance-scale me-1"></i>Treatment Type</label>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="treatment_type" id="expenseType" value="expense" checked>
                            <label class="form-check-label" for="expenseType">
                                <strong><i class="fas fa-file-invoice-dollar me-1"></i>Expense</strong> - Record as expense immediately (Accounts: 561, 562, 563)
                            </label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="treatment_type" id="liabilityType" value="liability">
                            <label class="form-check-label" for="liabilityType">
                                <strong><i class="fas fa-balance-scale me-1"></i>Liability</strong> - Record as payable liability (Accounts: 2111, 2112, 2113)
                            </label>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label"><i class="fas fa-sticky-note me-1"></i>Notes (Optional)</label>
                        <textarea class="form-control" name="notes" rows="3" 
                                  placeholder="Add any notes about this assignment..."></textarea>
                    </div>
                    
                    <div class="alert alert-info">
                        <small>
                            <strong><i class="fas fa-info-circle me-1"></i>Using New COA Structure:</strong><br>
                            • <strong>Cash Account:</strong> 1112 - Cash at Bank<br>
                            • <strong>Expense Accounts:</strong> 561 (CMSA), 562 (DSE), 563 (CSDR)<br>
                            • <strong>Liability Accounts:</strong> 2111 (CMSA), 2112 (DSE), 2113 (CSDR)
                        </small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-1"></i>Cancel
                    </button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-check me-1"></i>Assign Fees
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Bulk Assign Modal -->
<div class="modal fade" id="bulkAssignModal" tabindex="-1" aria-labelledby="bulkAssignModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="bulkAssignModalLabel"><i class="fas fa-tasks me-2"></i>Bulk Assign Selected Trades</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" id="bulkAssignForm">
                <div class="modal-body">
                    <input type="hidden" name="action" value="bulk_assign_selected">
                    <div id="selectedTradesList"></div>
                    
                    <div class="alert alert-info">
                        <p><strong><i class="fas fa-info-circle me-1"></i>You are about to assign <span id="bulkCount" class="fw-bold">0</span> selected trades.</strong></p>
                        <p class="mb-0">This will create accounting entries in the general ledger for all selected trades.</p>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label"><i class="fas fa-balance-scale me-1"></i>Assign as:</label>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="treatment_type" id="bulkExpenseType" value="expense" checked>
                            <label class="form-check-label" for="bulkExpenseType">
                                <strong><i class="fas fa-file-invoice-dollar me-1"></i>Expense</strong> - Record as expense immediately
                            </label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="treatment_type" id="bulkLiabilityType" value="liability">
                            <label class="form-check-label" for="bulkLiabilityType">
                                <strong><i class="fas fa-balance-scale me-1"></i>Liability</strong> - Record as payable liability
                            </label>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-1"></i>Cancel
                    </button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-check me-1"></i>Confirm Bulk Assign
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Bulk Reassign Modal -->
<div class="modal fade" id="bulkReassignModal" tabindex="-1" aria-labelledby="bulkReassignModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="bulkReassignModalLabel"><i class="fas fa-exchange-alt me-2"></i>Bulk Reassign Selected Trades</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" id="bulkReassignForm">
                <div class="modal-body">
                    <input type="hidden" name="action" value="bulk_reassign_selected">
                    <div id="reassignTradesList"></div>
                    
                    <div class="alert alert-warning">
                        <p><strong><i class="fas fa-exclamation-triangle me-1"></i>Warning: You are about to reassign <span id="reassignCount" class="fw-bold">0</span> selected trades.</strong></p>
                        <p class="mb-0">This will reverse existing accounting entries and create new ones for all selected trades.</p>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label"><i class="fas fa-balance-scale me-1"></i>Reassign to:</label>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="treatment_type" id="bulkReassignExpense" value="expense">
                            <label class="form-check-label" for="bulkReassignExpense">
                                <strong><i class="fas fa-file-invoice-dollar me-1"></i>Expense</strong> - Change to expense treatment
                            </label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="treatment_type" id="bulkReassignLiability" value="liability">
                            <label class="form-check-label" for="bulkReassignLiability">
                                <strong><i class="fas fa-balance-scale me-1"></i>Liability</strong> - Change to liability treatment
                            </label>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-1"></i>Cancel
                    </button>
                    <button type="submit" class="btn btn-warning">
                        <i class="fas fa-exchange-alt me-1"></i>Confirm Reassign
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include '../includes/footer.php'; ?>

<script>
// Store selected trade IDs
let selectedTrades = new Set();
let selectedTradeDetails = {};

// Modal initialization
document.addEventListener('DOMContentLoaded', function() {
    var assignModal = document.getElementById('assignModal');
    if (assignModal) {
        assignModal.addEventListener('show.bs.modal', function(event) {
            var button = event.relatedTarget;
            
            document.getElementById('modalAssignmentId').value = button.getAttribute('data-trade-id');
            document.getElementById('modalTradeRef').value = button.getAttribute('data-trade-ref');
            document.getElementById('modalClient').value = button.getAttribute('data-client');
            document.getElementById('modalDate').value = button.getAttribute('data-date');
            document.getElementById('modalDseFee').value = parseFloat(button.getAttribute('data-dse')).toFixed(2);
            document.getElementById('modalCmsaFee').value = parseFloat(button.getAttribute('data-cmsa')).toFixed(2);
            document.getElementById('modalCsdFee').value = parseFloat(button.getAttribute('data-csd')).toFixed(2);
            document.getElementById('modalTotalFee').value = parseFloat(button.getAttribute('data-total')).toFixed(2);
        });
    }
    
    // Initialize checkbox event listeners
    initCheckboxEvents();
    
    // Update bulk actions panel
    updateBulkActionsPanel();
});

// Initialize checkbox events
function initCheckboxEvents() {
    // Individual checkbox change
    document.querySelectorAll('.trade-checkbox').forEach(checkbox => {
        checkbox.addEventListener('change', function() {
            const tradeId = this.value;
            const status = this.dataset.status;
            const treatment = this.dataset.treatment || '';
            
            if (this.checked) {
                selectedTrades.add(tradeId);
                selectedTradeDetails[tradeId] = { status, treatment };
            } else {
                selectedTrades.delete(tradeId);
                delete selectedTradeDetails[tradeId];
            }
            
            updateBulkActionsPanel();
            updateSelectAllCheckboxes();
        });
    });
    
    // Select all checkboxes in table
    document.querySelectorAll('.select-all').forEach(checkbox => {
        checkbox.addEventListener('change', function() {
            const isChecked = this.checked;
            document.querySelectorAll('.trade-checkbox').forEach(cb => {
                cb.checked = isChecked;
                const tradeId = cb.value;
                const status = cb.dataset.status;
                const treatment = cb.dataset.treatment || '';
                
                if (isChecked) {
                    selectedTrades.add(tradeId);
                    selectedTradeDetails[tradeId] = { status, treatment };
                } else {
                    selectedTrades.delete(tradeId);
                    delete selectedTradeDetails[tradeId];
                }
            });
            
            updateBulkActionsPanel();
        });
    });
    
    // Select all in group
    document.querySelectorAll('.select-all-group').forEach(checkbox => {
        checkbox.addEventListener('change', function() {
            const groupClass = this.dataset.group;
            const isChecked = this.checked;
            
            // Find the parent card and check all checkboxes in it
            const card = this.closest('.card');
            card.querySelectorAll('.trade-checkbox').forEach(cb => {
                cb.checked = isChecked;
                const tradeId = cb.value;
                const status = cb.dataset.status;
                const treatment = cb.dataset.treatment || '';
                
                if (isChecked) {
                    selectedTrades.add(tradeId);
                    selectedTradeDetails[tradeId] = { status, treatment };
                } else {
                    selectedTrades.delete(tradeId);
                    delete selectedTradeDetails[tradeId];
                }
            });
            
            updateBulkActionsPanel();
            updateSelectAllCheckboxes();
        });
    });
}

// Update select all checkboxes state
function updateSelectAllCheckboxes() {
    const allCheckboxes = document.querySelectorAll('.trade-checkbox');
    const checkedCount = document.querySelectorAll('.trade-checkbox:checked').length;
    
    // Update main select all
    document.querySelectorAll('.select-all').forEach(checkbox => {
        checkbox.checked = checkedCount > 0 && checkedCount === allCheckboxes.length;
        checkbox.indeterminate = checkedCount > 0 && checkedCount < allCheckboxes.length;
    });
    
    // Update group select all
    document.querySelectorAll('.card').forEach(card => {
        const groupCheckboxes = card.querySelectorAll('.trade-checkbox');
        const groupChecked = card.querySelectorAll('.trade-checkbox:checked').length;
        const groupSelectAll = card.querySelector('.select-all-group');
        
        if (groupSelectAll && groupCheckboxes.length > 0) {
            groupSelectAll.checked = groupChecked === groupCheckboxes.length;
            groupSelectAll.indeterminate = groupChecked > 0 && groupChecked < groupCheckboxes.length;
        }
    });
}

// Update bulk actions panel visibility and content
function updateBulkActionsPanel() {
    const selectedCount = selectedTrades.size;
    const panel = document.getElementById('bulkActionsPanel');
    const countSpan = document.getElementById('selectedCount');
    
    if (selectedCount > 0) {
        panel.style.display = 'block';
        countSpan.textContent = selectedCount;
        
        // Update hidden inputs with selected trades
        const inputsContainer = document.getElementById('selectedTradesInputs');
        if (inputsContainer) {
            inputsContainer.innerHTML = '';
            Array.from(selectedTrades).forEach(tradeId => {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'selected_trades[]';
                input.value = tradeId;
                inputsContainer.appendChild(input);
            });
        }
    } else {
        panel.style.display = 'none';
    }
}

// Clear all selections
function clearAllSelections() {
    selectedTrades.clear();
    selectedTradeDetails = {};
    document.querySelectorAll('.trade-checkbox, .select-all, .select-all-group').forEach(cb => {
        cb.checked = false;
        cb.indeterminate = false;
    });
    updateBulkActionsPanel();
}

// Show bulk assign modal
function showBulkAssignModal(treatmentType) {
    const selectedCount = selectedTrades.size;
    if (selectedCount === 0) {
        alert('Please select trades first.');
        return;
    }
    
    // Check if all selected are pending
    const allPending = Array.from(selectedTrades).every(id => 
        selectedTradeDetails[id] && selectedTradeDetails[id].status === 'pending'
    );
    
    if (!allPending) {
        alert('Bulk assign is only available for pending trades.');
        return;
    }
    
    document.getElementById('bulkCount').textContent = selectedCount;
    
    // Set default radio based on treatmentType parameter
    if (treatmentType === 'expense') {
        document.getElementById('bulkExpenseType').checked = true;
    } else {
        document.getElementById('bulkLiabilityType').checked = true;
    }
    
    // Show selected trades list
    const tradesList = document.getElementById('selectedTradesList');
    tradesList.innerHTML = '<p>Selected Trade IDs: ' + Array.from(selectedTrades).join(', ') + '</p>';
    
    const modal = new bootstrap.Modal(document.getElementById('bulkAssignModal'));
    modal.show();
}

// Show bulk reassign modal
function showBulkReassignModal(treatmentType) {
    const selectedCount = selectedTrades.size;
    if (selectedCount === 0) {
        alert('Please select trades first.');
        return;
    }
    
    // Check if all selected are processed
    const allProcessed = Array.from(selectedTrades).every(id => 
        selectedTradeDetails[id] && selectedTradeDetails[id].status === 'processed'
    );
    
    if (!allProcessed) {
        alert('Bulk reassign is only available for processed trades.');
        return;
    }
    
    document.getElementById('reassignCount').textContent = selectedCount;
    
    // Set default radio based on treatmentType parameter
    if (treatmentType === 'expense') {
        document.getElementById('bulkReassignExpense').checked = true;
    } else {
        document.getElementById('bulkReassignLiability').checked = true;
    }
    
    // Show selected trades list
    const tradesList = document.getElementById('reassignTradesList');
    tradesList.innerHTML = '<p>Selected Trade IDs: ' + Array.from(selectedTrades).join(', ') + '</p>';
    
    const modal = new bootstrap.Modal(document.getElementById('bulkReassignModal'));
    modal.show();
}

// Quick assign function
function quickAssign(assignmentId, treatmentType) {
    if (confirm('Quick assign as ' + treatmentType + '?')) {
        var form = document.createElement('form');
        form.method = 'POST';
        form.style.display = 'none';
        
        var actionInput = document.createElement('input');
        actionInput.type = 'hidden';
        actionInput.name = 'action';
        actionInput.value = 'quick_assign';
        form.appendChild(actionInput);
        
        var idInput = document.createElement('input');
        idInput.type = 'hidden';
        idInput.name = 'assignment_id';
        idInput.value = assignmentId;
        form.appendChild(idInput);
        
        var typeInput = document.createElement('input');
        typeInput.type = 'hidden';
        typeInput.name = 'treatment_type';
        typeInput.value = treatmentType;
        form.appendChild(typeInput);
        
        document.body.appendChild(form);
        form.submit();
    }
}

// Reassign trade function
function reassignTrade(assignmentId, newTreatmentType) {
    if (confirm('Reassign this trade to ' + newTreatmentType + '? This will reverse existing entries and create new ones.')) {
        var form = document.createElement('form');
        form.method = 'POST';
        form.style.display = 'none';
        
        var actionInput = document.createElement('input');
        actionInput.type = 'hidden';
        actionInput.name = 'action';
        actionInput.value = 'reassign_trade';
        form.appendChild(actionInput);
        
        var idInput = document.createElement('input');
        idInput.type = 'hidden';
        idInput.name = 'assignment_id';
        idInput.value = assignmentId;
        form.appendChild(idInput);
        
        var typeInput = document.createElement('input');
        typeInput.type = 'hidden';
        typeInput.name = 'treatment_type';
        typeInput.value = newTreatmentType;
        form.appendChild(typeInput);
        
        document.body.appendChild(form);
        form.submit();
    }
}

// Bulk dismiss selected
function bulkDismissSelected() {
    const selectedCount = selectedTrades.size;
    if (selectedCount === 0) {
        alert('Please select trades first.');
        return;
    }
    
    // Check if all selected are processed
    const allProcessed = Array.from(selectedTrades).every(id => 
        selectedTradeDetails[id] && selectedTradeDetails[id].status === 'processed'
    );
    
    if (!allProcessed) {
        alert('Bulk dismiss is only available for processed trades.');
        return;
    }
    
    if (confirm(`Dismiss ${selectedCount} selected entries?`)) {
        // Create hidden form and submit
        const form = document.createElement('form');
        form.method = 'POST';
        form.style.display = 'none';
        
        const actionInput = document.createElement('input');
        actionInput.type = 'hidden';
        actionInput.name = 'action';
        actionInput.value = 'bulk_dismiss_selected';
        form.appendChild(actionInput);
        
        Array.from(selectedTrades).forEach(tradeId => {
            const idInput = document.createElement('input');
            idInput.type = 'hidden';
            idInput.name = 'selected_trades[]';
            idInput.value = tradeId;
            form.appendChild(idInput);
        });
        
        document.body.appendChild(form);
        form.submit();
    }
}

// Bulk assign function for client/day
function bulkAssignClientDay(clientName, tradeDay, treatmentType) {
    // Show confirmation
    if (!confirm(`Assign ALL pending fees for ${clientName} on ${tradeDay} as ${treatmentType}?`)) {
        return;
    }
    
    // Create hidden form and submit
    const form = document.createElement('form');
    form.method = 'POST';
    form.style.display = 'none';
    
    const actionInput = document.createElement('input');
    actionInput.type = 'hidden';
    actionInput.name = 'action';
    actionInput.value = 'bulk_assign_client_day';
    form.appendChild(actionInput);
    
    const clientInput = document.createElement('input');
    clientInput.type = 'hidden';
    clientInput.name = 'client_name';
    clientInput.value = clientName;
    form.appendChild(clientInput);
    
    const dayInput = document.createElement('input');
    dayInput.type = 'hidden';
    dayInput.name = 'trade_day';
    dayInput.value = tradeDay;
    form.appendChild(dayInput);
    
    const typeInput = document.createElement('input');
    typeInput.type = 'hidden';
    typeInput.name = 'treatment_type';
    typeInput.value = treatmentType;
    form.appendChild(typeInput);
    
    document.body.appendChild(form);
    form.submit();
}

// Form submission handlers - FIXED
document.getElementById('bulkAssignForm')?.addEventListener('submit', function(e) {
    e.preventDefault();
    
    const selectedCount = selectedTrades.size;
    if (selectedCount === 0) {
        alert('Please select trades first.');
        return;
    }
    
    // Get selected treatment type
    const treatmentType = document.querySelector('input[name="treatment_type"]:checked').value;
    
    // Create hidden inputs for selected trades
    const hiddenInputsContainer = document.createElement('div');
    hiddenInputsContainer.style.display = 'none';
    
    Array.from(selectedTrades).forEach(tradeId => {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'selected_trades[]';
        input.value = tradeId;
        hiddenInputsContainer.appendChild(input);
    });
    
    this.appendChild(hiddenInputsContainer);
    
    // Submit the form
    this.submit();
});

document.getElementById('bulkReassignForm')?.addEventListener('submit', function(e) {
    e.preventDefault();
    
    const selectedCount = selectedTrades.size;
    if (selectedCount === 0) {
        alert('Please select trades first.');
        return;
    }
    
    // Get selected treatment type
    const treatmentType = document.querySelector('input[name="treatment_type"]:checked').value;
    
    // Create hidden inputs for selected trades
    const hiddenInputsContainer = document.createElement('div');
    hiddenInputsContainer.style.display = 'none';
    
    Array.from(selectedTrades).forEach(tradeId => {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'selected_trades[]';
        input.value = tradeId;
        hiddenInputsContainer.appendChild(input);
    });
    
    this.appendChild(hiddenInputsContainer);
    
    // Submit the form
    this.submit();
});

// Keyboard shortcuts
document.addEventListener('keydown', function(event) {
    // Ctrl+A to select all
    if (event.ctrlKey && event.key === 'a') {
        event.preventDefault();
        document.querySelectorAll('.trade-checkbox').forEach(cb => {
            cb.checked = true;
            const tradeId = cb.value;
            const status = cb.dataset.status;
            const treatment = cb.dataset.treatment || '';
            
            selectedTrades.add(tradeId);
            selectedTradeDetails[tradeId] = { status, treatment };
        });
        updateBulkActionsPanel();
        updateSelectAllCheckboxes();
    }
    
    // Ctrl+E for expense, Ctrl+L for liability on focused row
    if (event.ctrlKey) {
        var focusedElement = document.activeElement;
        var row = focusedElement.closest('tr');
        
        if (row) {
            var assignBtn = row.querySelector('.btn-outline-success');
            var quickAssignBtn = row.querySelector('.btn-outline-info');
            var assignmentId = assignBtn ? assignBtn.getAttribute('data-trade-id') : null;
            
            if (assignmentId) {
                if (event.key === 'e' || event.key === 'E') {
                    event.preventDefault();
                    quickAssign(assignmentId, 'expense');
                } else if (event.key === 'l' || event.key === 'L') {
                    event.preventDefault();
                    quickAssign(assignmentId, 'liability');
                }
            }
        }
    }
});
</script>

<style>
.table th {
    font-weight: 600;
    background-color: #f8f9fa;
}

.badge {
    font-size: 0.75em;
    padding: 0.35em 0.65em;
}

.btn-group-sm > .btn {
    padding: 0.25rem 0.5rem;
    font-size: 0.75rem;
}

.card-header h5 {
    margin-bottom: 0.25rem;
}

.text-end {
    text-align: right;
}

.table tbody tr:hover {
    background-color: rgba(0, 0, 0, 0.02);
}

.alert .btn-close {
    padding: 1.25rem 1rem;
}

.modal-header {
    background-color: #f8f9fa;
}

.card.bg-light .card-header {
    background-color: rgba(248, 249, 250, 0.8) !important;
}

.form-check-input:indeterminate {
    background-color: #0d6efd;
    border-color: #0d6efd;
}

#bulkActionsPanel {
    border: 2px solid #0d6efd;
}

.table th:first-child,
.table td:first-child {
    width: 50px;
    text-align: center;
}

.select-all-group {
    margin-top: 0;
}

.card.bg-primary .card-header {
    background-color: #0d6efd !important;
}
</style>