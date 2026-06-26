<?php
/**
 * Financial Helpers for Trade Processing System
 * Contains all financial calculation and accounting functions
 */

/**
 * Get company details from companies table
 */
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

/**
 * Check if trade is through custodian
 */
function isCustodianTrade($sca_code, $company_code) {
    return !empty($sca_code) && $sca_code !== $company_code;
}

/**
 * Get account ID by account code
 */
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

/**
 * Get default account ID for fallback
 */
function getDefaultAccountId($account_code) {
    $default_mapping = [
        '1001' => 1,  // Cash and Cash Equivalents
        '1008' => 2,  // Cash - Bond Operations
        '1009' => 3,  // Cash - Equity Operations
        '1010' => 4,  // Accounts Receivable
        '1011' => 5,  // Bond Investments
        '1012' => 6,  // Equity Investments
        '1013' => 7,  // Bank Account - CRDB
        '1014' => 8,  // Bank Account - NMB
        '1015' => 9,  // Prepaid Expenses
        '1016' => 10, // Staff Receivables
        '2001' => 11, // Property, Plant & Equipment
        '2002' => 12, // Accumulated Depreciation
        '3007' => 13, // VAT Payable
        '3008' => 14, // CMSA Fees Payable
        '3009' => 15, // CSD&R Fees Payable
        '3010' => 16, // DSE Fees Payable
        '3011' => 17, // Client Payable - Bond Proceeds
        '3012' => 18, // Client Payable - Equity Proceeds
        '3013' => 19, // Withholding Tax Payable
        '3014' => 20, // Income Tax Payable
        '3015' => 21, // Accrued Expenses
        '5001' => 22, // Owner's Capital
        '5002' => 23, // Retained Earnings
        '5003' => 24, // Current Year Profit/Loss
        '6001' => 25, // Brokerage Commission Income
        '6002' => 26, // Advisory Income
        '6003' => 27, // Interest Income
        '6004' => 28, // Other Operating Income
        '7001' => 29, // Salaries and Wages
        '7002' => 30, // Office Rent
        '7003' => 31, // Utilities Expense
        '7004' => 32, // Internet & Communication
        '7005' => 33, // Transport & Travel
        '7006' => 34, // Office Supplies
        '7007' => 35, // Bank Charges
        '7008' => 36, // Audit & Legal Fees
        '7009' => 37  // Depreciation Expense
    ];
    
    return $default_mapping[$account_code] ?? 1; // Default to Cash
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
        
        $user_id = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
        $created_by = isset($_SESSION['username']) ? $_SESSION['username'] : 'system';
        
        $stmt = $db->prepare("
            INSERT INTO general_ledger 
            (transaction_date, account_id, debit_amount, credit_amount, description, reference_no, reference_type, created_at, created_by) 
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), ?)
        ");
        
        $result = $stmt->execute([
            $transaction_date, 
            $account_id, 
            round($debit, 2), 
            round($credit, 2), 
            substr(trim($description), 0, 255), 
            substr(trim($reference_no), 0, 100), 
            $reference_type,
            $created_by
        ]);
        
        if ($result) {
            return $db->lastInsertId();
        }
        
        error_log("Failed to insert GL entry: " . implode(', ', $stmt->errorInfo()));
        return false;
        
    } catch (Exception $e) {
        error_log("Error recording GL entry: " . $e->getMessage());
        return false;
    }
}

/**
 * Calculate bond fees based on charges structure - INCLUDES ALL REGULATORY FEES
 */
function calculateBondFees($quantity, $price, $consideration) {
    try {
        $fees = [];
        
        $face_value = $quantity;
        
        // Brokerage Commission (Tiered)
        $brokerage_first_100m = min($face_value, 100000000) * (0.063132 / 100);
        $brokerage_excess = max($face_value - 100000000, 0) * (0.035 / 100);
        $fees['brokerage'] = $brokerage_first_100m + $brokerage_excess;
        
        // VAT on Brokerage (18%)
        $fees['vat'] = $fees['brokerage'] * 0.18;
        
        // Regulatory fees
        $fees['cmsa'] = $consideration * (0.01 / 100);
        $fees['csd'] = $face_value * (0.0118 / 100);
        $fees['dse'] = $face_value * (0.02006 / 100);
        
        // Total fees
        $total_fees = $fees['brokerage'] + $fees['vat'] + $fees['cmsa'] + $fees['csd'] + $fees['dse'];
        $fees['client_net'] = $consideration - $total_fees;
        $fees['total_fees'] = $total_fees;
        
        return $fees;
        
    } catch (Exception $e) {
        error_log("Error calculating bond fees: " . $e->getMessage());
        return [
            'brokerage' => 0,
            'vat' => 0,
            'cmsa' => 0,
            'csd' => 0,
            'dse' => 0,
            'client_net' => $consideration,
            'total_fees' => 0
        ];
    }
}

/**
 * Bond Accounting - Records ALL commissions and fees for ALL bond trades
 */
function createBondAccountingEntries($db, $trade_reference, $consideration, $fees, $trade_side, $client_name, $company_name, $trade_date, $is_custodian_trade = false) {
    try {
        $entries_created = 0;
        
        $cash_account = getAccountIdByCode($db, '1001');
        $brokerage_income = getAccountIdByCode($db, '6001');
        $vat_payable = getAccountIdByCode($db, '3007');
        $cmsa_payable = getAccountIdByCode($db, '3008');
        $csd_payable = getAccountIdByCode($db, '3009');
        $dse_payable = getAccountIdByCode($db, '3010');
        
        $brokerage_fee = $fees['brokerage'] ?? 0;
        $vat_fee = $fees['vat'] ?? 0;
        $cmsa_fee = $fees['cmsa'] ?? 0;
        $csd_fee = $fees['csd'] ?? 0;
        $dse_fee = $fees['dse'] ?? 0;
        
        $total_fees = $brokerage_fee + $vat_fee + $cmsa_fee + $csd_fee + $dse_fee;
        
        if ($total_fees > 0) {
            // Entry 1: Debit Cash (we receive ALL fees)
            $entry1 = recordGeneralLedgerEntry(
                $db, $trade_date, $cash_account,
                $total_fees, 0,
                "Total bond fees received - {$trade_reference} - {$client_name}",
                $trade_reference, 'fee'
            );
            if ($entry1) {
                $entries_created++;
            }
            
            // Entry 2: Credit Brokerage Income (our revenue)
            $entry2 = recordGeneralLedgerEntry(
                $db, $trade_date, $brokerage_income,
                0, $brokerage_fee,
                "Brokerage income - {$trade_reference}",
                $trade_reference, 'fee'
            );
            if ($entry2) {
                $entries_created++;
            }
            
            // Entry 3: Credit VAT Payable (liability to government)
            if ($vat_fee > 0) {
                $entry3 = recordGeneralLedgerEntry(
                    $db, $trade_date, $vat_payable,
                    0, $vat_fee,
                    "VAT on brokerage - {$trade_reference}",
                    $trade_reference, 'fee'
                );
                if ($entry3) {
                    $entries_created++;
                }
            }
            
            // Entry 4: Credit CMSA Fees Payable (liability to CMSA)
            if ($cmsa_fee > 0) {
                $entry4 = recordGeneralLedgerEntry(
                    $db, $trade_date, $cmsa_payable,
                    0, $cmsa_fee,
                    "CMSA fees payable - {$trade_reference}",
                    $trade_reference, 'fee'
                );
                if ($entry4) {
                    $entries_created++;
                }
            }
            
            // Entry 5: Credit CSD&R Fees Payable (liability to CSD)
            if ($csd_fee > 0) {
                $entry5 = recordGeneralLedgerEntry(
                    $db, $trade_date, $csd_payable,
                    0, $csd_fee,
                    "CSD&R fees payable - {$trade_reference}",
                    $trade_reference, 'fee'
                );
                if ($entry5) {
                    $entries_created++;
                }
            }
            
            // Entry 6: Credit DSE Fees Payable (liability to DSE)
            if ($dse_fee > 0) {
                $entry6 = recordGeneralLedgerEntry(
                    $db, $trade_date, $dse_payable,
                    0, $dse_fee,
                    "DSE fees payable - {$trade_reference}",
                    $trade_reference, 'fee'
                );
                if ($entry6) {
                    $entries_created++;
                }
            }
        }
        
        return $entries_created > 0;
        
    } catch (Exception $e) {
        error_log("Error creating bond accounting entries: " . $e->getMessage());
        return false;
    }
}

/**
 * Calculate equity fees including ALL regulatory fees - TIERED BROKERAGE
 */
function calculateEquityFees($db, $consideration) {
    try {
        $fees = [];
        
        // Get tiered brokerage rates
        $brokerage_rates = getTieredBrokerageRates($db);
        $tier1 = $brokerage_rates['BROKERAGE_TIER1'] ?? ['rate' => 1.7, 'is_rebated' => false, 'rebate_percentage' => 0];
        $tier2 = $brokerage_rates['BROKERAGE_TIER2'] ?? ['rate' => 1.5, 'is_rebated' => false, 'rebate_percentage' => 0];
        $tier3 = $brokerage_rates['BROKERAGE_TIER3'] ?? ['rate' => 0.8, 'is_rebated' => false, 'rebate_percentage' => 0];
        
        // Calculate tiered brokerage
        if ($consideration <= 10000000) {
            $brokerage = $consideration * ($tier1['rate'] / 100);
        } elseif ($consideration <= 40000000) {
            $brokerage = 10000000 * ($tier1['rate'] / 100) + ($consideration - 10000000) * ($tier2['rate'] / 100);
        } else {
            $brokerage = 10000000 * ($tier1['rate'] / 100) + 30000000 * ($tier2['rate'] / 100) + ($consideration - 40000000) * ($tier3['rate'] / 100);
        }
        
        $fees['brokerage'] = $brokerage;
        
        // VAT on Brokerage (18%)
        $fees['vat'] = $brokerage * 0.18;
        
        // Regulatory fees - ALL INCLUDED
        $fees['cmsa'] = $consideration * (0.01 / 100);      // CMSA Fee
        $fees['csd'] = $consideration * (0.0118 / 100);     // CSD&R Fee
        $fees['dse'] = $consideration * (0.02006 / 100);    // DSE Fee
        $fees['vrf'] = $consideration * (0.0025 / 100);     // VRF Fee
        
        // Total fees
        $fees['total_fees'] = $fees['brokerage'] + $fees['vat'] + $fees['cmsa'] + $fees['csd'] + $fees['dse'] + $fees['vrf'];
        
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
            'total_fees' => 0
        ];
    }
}

/**
 * Equity Accounting - Records ALL commissions and fees for ALL equity trades
 */
function createEquityAccountingEntries($db, $trade_reference, $consideration, $fees, $trade_side, $client_name, $company_name, $trade_date, $is_custodian_trade = false) {
    try {
        $entries_created = 0;
        
        $cash_account = getAccountIdByCode($db, '1001');
        $brokerage_income = getAccountIdByCode($db, '6001');
        $vat_payable = getAccountIdByCode($db, '3007');
        $cmsa_payable = getAccountIdByCode($db, '3008');
        $csd_payable = getAccountIdByCode($db, '3009');
        $dse_payable = getAccountIdByCode($db, '3010');
        // Note: VRF uses the same account as DSE or create separate account if needed
        
        $brokerage_fee = $fees['brokerage'] ?? 0;
        $vat_fee = $fees['vat'] ?? 0;
        $cmsa_fee = $fees['cmsa'] ?? 0;
        $csd_fee = $fees['csd'] ?? 0;
        $dse_fee = $fees['dse'] ?? 0;
        $vrf_fee = $fees['vrf'] ?? 0;
        
        $total_fees = $brokerage_fee + $vat_fee + $cmsa_fee + $csd_fee + $dse_fee + $vrf_fee;
        
        if ($total_fees > 0) {
            // Entry 1: Debit Cash (we receive ALL fees)
            $entry1 = recordGeneralLedgerEntry(
                $db, $trade_date, $cash_account,
                $total_fees, 0,
                "Total equity fees received - {$trade_reference} - {$client_name}",
                $trade_reference, 'fee'
            );
            if ($entry1) {
                $entries_created++;
            }
            
            // Entry 2: Credit Brokerage Income (our revenue)
            $entry2 = recordGeneralLedgerEntry(
                $db, $trade_date, $brokerage_income,
                0, $brokerage_fee,
                "Brokerage income - {$trade_reference}",
                $trade_reference, 'fee'
            );
            if ($entry2) {
                $entries_created++;
            }
            
            // Entry 3: Credit VAT Payable (liability to government)
            if ($vat_fee > 0) {
                $entry3 = recordGeneralLedgerEntry(
                    $db, $trade_date, $vat_payable,
                    0, $vat_fee,
                    "VAT on brokerage - {$trade_reference}",
                    $trade_reference, 'fee'
                );
                if ($entry3) {
                    $entries_created++;
                }
            }
            
            // Entry 4: Credit CMSA Fees Payable (liability to CMSA)
            if ($cmsa_fee > 0) {
                $entry4 = recordGeneralLedgerEntry(
                    $db, $trade_date, $cmsa_payable,
                    0, $cmsa_fee,
                    "CMSA fees payable - {$trade_reference}",
                    $trade_reference, 'fee'
                );
                if ($entry4) {
                    $entries_created++;
                }
            }
            
            // Entry 5: Credit CSD&R Fees Payable (liability to CSD)
            if ($csd_fee > 0) {
                $entry5 = recordGeneralLedgerEntry(
                    $db, $trade_date, $csd_payable,
                    0, $csd_fee,
                    "CSD&R fees payable - {$trade_reference}",
                    $trade_reference, 'fee'
                );
                if ($entry5) {
                    $entries_created++;
                }
            }
            
            // Entry 6: Credit DSE Fees Payable (liability to DSE)
            if ($dse_fee > 0) {
                $entry6 = recordGeneralLedgerEntry(
                    $db, $trade_date, $dse_payable,
                    0, $dse_fee,
                    "DSE fees payable - {$trade_reference}",
                    $trade_reference, 'fee'
                );
                if ($entry6) {
                    $entries_created++;
                }
            }
            
            // Entry 7: Credit VRF Fees (if separate account exists, otherwise include in DSE)
            if ($vrf_fee > 0) {
                // Use DSE account for VRF if no separate account, or create separate VRF account
                $entry7 = recordGeneralLedgerEntry(
                    $db, $trade_date, $dse_payable, // Using DSE account for VRF
                    0, $vrf_fee,
                    "VRF fees payable - {$trade_reference}",
                    $trade_reference, 'fee'
                );
                if ($entry7) {
                    $entries_created++;
                }
            }
        }
        
        return $entries_created > 0;
        
    } catch (Exception $e) {
        error_log("Error creating equity accounting entries: " . $e->getMessage());
        return false;
    }
}

/**
 * Record company's own bond investment transactions
 */
function recordCompanyBondInvestment($db, $trade_reference, $trade_side, $consideration, $client_name, $trade_date) {
    try {
        // Account 1011 = Bond Investments
        $investment_account = getAccountIdByCode($db, '1011');
        // Account 1008 = Cash - Bond Operations
        $cash_bond_account = getAccountIdByCode($db, '1008');
        
        $entries_created = 0;
        
        if ($trade_side === 'buy') {
            // BUY: Company purchases bonds for its own portfolio
            // Debit: Bond Investment (asset increases)
            // Credit: Cash (asset decreases)
            
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
            // Debit: Cash (asset increases)
            // Credit: Bond Investment (asset decreases)
            
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
 * Record company's own equity investment transactions
 */
function recordCompanyEquityInvestment($db, $trade_reference, $consideration, $trade_side, $trade_date) {
    try {
        // Account 1012 = Equity Investments
        $investment_account = getAccountIdByCode($db, '1012');
        // Account 1009 = Cash - Equity Operations
        $cash_equity_account = getAccountIdByCode($db, '1009');
        
        $entries_created = 0;
        
        if ($trade_side === 'buy') {
            // BUY: Company purchases equities for its own portfolio
            // Debit: Equity Investment (asset increases)
            // Credit: Cash (asset decreases)
            
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
            // Debit: Cash (asset increases)
            // Credit: Equity Investment (asset decreases)
            
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

/**
 * Calculate custodian fees
 */
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
 * Generate Trial Balance
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

/**
 * Get tiered brokerage rates from database
 */
function getTieredBrokerageRates($db) {
    try {
        $stmt = $db->prepare("
            SELECT rate_name, rate_value, is_rebated, rebate_percentage 
            FROM brokerage_rates 
            WHERE is_active = 1
        ");
        $stmt->execute();
        $rates = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $result = [];
        foreach ($rates as $rate) {
            $result[$rate['rate_name']] = [
                'rate' => (float)$rate['rate_value'],
                'is_rebated' => (bool)$rate['is_rebated'],
                'rebate_percentage' => (float)$rate['rebate_percentage']
            ];
        }
        
        return $result;
        
    } catch (Exception $e) {
        error_log("Error getting brokerage rates: " . $e->getMessage());
        return [
            'BROKERAGE_TIER1' => ['rate' => 1.7, 'is_rebated' => false, 'rebate_percentage' => 0],
            'BROKERAGE_TIER2' => ['rate' => 1.5, 'is_rebated' => false, 'rebate_percentage' => 0],
            'BROKERAGE_TIER3' => ['rate' => 0.8, 'is_rebated' => false, 'rebate_percentage' => 0]
        ];
    }
}

/**
 * Safe number formatting
 */
function safe_number_format($value, $decimals = 2) {
    if ($value === '' || $value === null) {
        return '0.00';
    }
    $numeric_value = is_numeric($value) ? $value : 0;
    return number_format((float)$numeric_value, $decimals);
}

/**
 * Safe integer formatting
 */
function safe_int_format($value) {
    if ($value === '' || $value === null) {
        return '0';
    }
    $numeric_value = is_numeric($value) ? $value : 0;
    return number_format((int)$numeric_value);
}
?>