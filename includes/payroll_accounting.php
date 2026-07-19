<?php
// /includes/payroll_accounting.php - Payroll GL Posting Functions
// Updated to use Chart of Accounts:
// LIABILITIES: 2121 (NSSF), 2122 (SDL), 2123 (WCF), 2124 (OSHA), 2125 (Health Ins), 2126 (PAYE), 212 (Accrued)
// EXPENSES: 511 (Salaries), 5121 (NSSF Emp), 5122 (SDL), 5123 (WCF), 5124 (OSHA), 5125 (Health Ins)

if (!defined('PAYROLL_CONTROL_CODE')) {
    require_once __DIR__ . '/../config/config.php';
    require_once __DIR__ . '/../config/account_mapping.php';
}

/**
 * Account mapping for Payroll to Chart of Accounts
 * 
 * LIABILITY ACCOUNTS (CREDIT):
 * - 212: Accrued Expenses (Net Salary Payable)
 * - 2121: NSSF Payable
 * - 2122: SDL Payable
 * - 2123: WCF Payable
 * - 2124: OSHA Payable
 * - 2125: Health Insurance Payable
 * - 2126: PAYE Payable
 * 
 * EXPENSE ACCOUNTS (DEBIT):
 * - 511: Salaries & Wages
 * - 5121: NSSF Employer Contribution
 * - 5122: SDL Expense
 * - 5123: WCF Expense
 * - 5124: OSHA Expense
 * - 5125: Health Insurance Expense
 */
function getPayrollAccountMap() {
    return [
        // Liability Accounts (Credits)
        'accrued_expenses' => '212',
        'nssf_payable' => '2121',
        'sdl_payable' => '2122',
        'wcf_payable' => '2123',
        'osha_payable' => '2124',
        'health_insurance_payable' => '2125',
        'paye_payable' => '2126',
        
        // Expense Accounts (Debits)
        'salaries_wages' => '511',
        'nssf_employer_expense' => '5121',
        'sdl_expense' => '5122',
        'wcf_expense' => '5123',
        'osha_expense' => '5124',
        'health_insurance_expense' => '5125',
        'staff_benefits' => '512',
    ];
}

/**
 * Map deduction key to payable account code
 */
function getHrPayableAccountCode($key) {
    $map = [
        'nssf' => '2121',
        'sdl' => '2122',
        'wcf' => '2123',
        'osha' => '2124',
        'health_insurance' => '2125',
        'paye' => '2126',
        'paye_tax' => '2126',
        'nhif' => '2125',
    ];
    return $map[strtolower($key)] ?? null;
}

/**
 * Map deduction key to expense account code
 */
function getHrExpenseAccountCode($key) {
    $map = [
        'nssf' => '5121',
        'sdl' => '5122',
        'wcf' => '5123',
        'osha' => '5124',
        'health_insurance' => '5125',
        'nhif' => '5125',
    ];
    return $map[strtolower($key)] ?? null;
}

/**
 * Post payroll entries to General Ledger
 * 
 * @param array $payroll_data {
 *     @type string $reference_no
 *     @type string $employee_name
 *     @type int $employee_id
 *     @type float $gross_salary
 *     @type array $deductions (non-statutory deductions like loans)
 *     @type array $statutory_deductions (employee deductions: nssf, paye, health_insurance)
 *     @type array $statutory_employer (employer contributions: nssf, sdl, wcf, osha, health_insurance)
 *     @type float $net_salary
 *     @type string $period_start
 *     @type string $period_end
 * }
 * @return array ['success' => bool, 'entries' => int, 'debit' => float, 'credit' => float, 'error' => string]
 */
function postPayrollToGL($payroll_data) {
    $db = getDBConnection();
    $db->beginTransaction();

    try {
        // Extract and validate data
        $reference_no = $payroll_data['reference_no'] ?? null;
        $employee_name = $payroll_data['employee_name'] ?? 'Unknown';
        $employee_id = $payroll_data['employee_id'] ?? null;
        $gross_salary = (float)($payroll_data['gross_salary'] ?? 0);
        $deductions = $payroll_data['deductions'] ?? [];
        $statutory_deductions = $payroll_data['statutory_deductions'] ?? [];
        $statutory_employer = $payroll_data['statutory_employer'] ?? [];
        $net_salary = (float)($payroll_data['net_salary'] ?? 0);
        $period_start = $payroll_data['period_start'] ?? date('Y-m-01');
        $period_end = $payroll_data['period_end'] ?? date('Y-m-t');
        $posted_by = $payroll_data['posted_by'] ?? ($_SESSION['username'] ?? 'system');

        if (!$reference_no) {
            throw new Exception("Reference number is required for GL posting");
        }

        $year = date('Y', strtotime($period_start));
        $period = (int)date('m', strtotime($period_start));
        $transaction_date = date('Y-m-d');

        $description = "Payroll $reference_no - $employee_name ($period_start to $period_end)";

        // Get all account codes needed
        $codes_needed = ['511', '212']; // Salaries & Wages, Accrued Expenses
        
        // Add all liability codes (2121-2126)
        for ($i = 1; $i <= 6; $i++) {
            $codes_needed[] = '212' . $i;
        }
        
        // Add all expense codes (5121-5125)
        for ($i = 1; $i <= 5; $i++) {
            $codes_needed[] = '512' . $i;
        }

        // Fetch accounts from chart_of_accounts
        $acct_cache = [];
        $placeholders = implode(',', array_fill(0, count($codes_needed), '?'));
        $stmt = $db->prepare("
            SELECT id, account_code, account_name 
            FROM chart_of_accounts 
            WHERE account_code IN ($placeholders) 
            AND is_active = 1
        ");
        $stmt->execute($codes_needed);
        
        foreach ($stmt as $row) {
            $acct_cache[$row['account_code']] = $row;
        }

        // Helper function to get account info
        $getAccount = function($code) use ($acct_cache) {
            if (!isset($acct_cache[$code])) {
                // Try to find account with wildcard search
                $stmt = $db->prepare("
                    SELECT id, account_code, account_name 
                    FROM chart_of_accounts 
                    WHERE account_code LIKE ? 
                    AND is_active = 1 
                    LIMIT 1
                ");
                $stmt->execute([$code . '%']);
                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($result) {
                    $acct_cache[$code] = $result;
                    return $result;
                }
                throw new Exception("Account '$code' not found in chart_of_accounts");
            }
            return $acct_cache[$code];
        };

        // Helper function to create GL entry
        $createGLEntry = function($account_code, $debit, $credit, $desc, $entity_id = null, $entity_name = null) use ($getAccount, $reference_no, $transaction_date, $year, $period, $posted_by) {
            $account = $getAccount($account_code);
            
            return [
                'transaction_date' => $transaction_date,
                'account_id' => (int)$account['id'],
                'account_code' => $account['account_code'],
                'account_name' => $account['account_name'],
                'debit_amount' => round((float)$debit, 2),
                'credit_amount' => round((float)$credit, 2),
                'description' => substr($desc, 0, 255),
                'reference_no' => $reference_no,
                'reference_type' => 'payroll',
                'entity_id' => $entity_id,
                'entity_name' => $entity_name,
                'entity_type' => $entity_id ? 'E' : 'O',
                'fiscal_year' => $year,
                'fiscal_period' => $period,
                'created_by' => $posted_by,
                'balance_type' => $debit > 0 ? 'debit' : 'credit',
            ];
        };

        $gl_entries = [];

        // ============================================================
        // 1. RECORD GROSS SALARY EXPENSE & LIABILITIES
        // ============================================================
        
        // 1a. DR - Salaries & Wages (511) with Gross Salary
        if ($gross_salary > 0) {
            $gl_entries[] = $createGLEntry(
                '511', 
                $gross_salary, 
                0,
                "$description - Gross Salary",
                $employee_id,
                $employee_name
            );
        }

        // 1b. CR - Accrued Expenses (212) with Net Salary
        if ($net_salary > 0) {
            $gl_entries[] = $createGLEntry(
                '212',
                0,
                $net_salary,
                "$description - Net Salary Payable",
                $employee_id,
                $employee_name
            );
        }

        // 1c. CR - Employee Statutory Deductions to respective Payable accounts
        foreach ($statutory_deductions as $key => $amount) {
            $amount = (float)$amount;
            if ($amount <= 0) continue;
            
            $payable_code = getHrPayableAccountCode($key);
            if (!$payable_code) {
                error_log("No payable account mapping for: $key");
                continue;
            }
            
            $label = ucwords(str_replace('_', ' ', $key));
            $gl_entries[] = $createGLEntry(
                $payable_code,
                0,
                $amount,
                "$description - $label Withholding",
                $employee_id,
                $employee_name
            );
        }

        // 1d. CR - Other Deductions (loans, advances, etc.)
        if (!empty($deductions) && is_array($deductions)) {
            foreach ($deductions as $key => $amount) {
                $amount = (float)$amount;
                if ($amount <= 0) continue;
                
                // Check if this is a statutory deduction (already handled)
                if (isset($statutory_deductions[$key])) continue;
                
                // For non-statutory deductions, credit to Accrued Expenses
                $gl_entries[] = $createGLEntry(
                    '212',
                    0,
                    $amount,
                    "$description - $key Deduction",
                    $employee_id,
                    $employee_name
                );
            }
        }

        // ============================================================
        // 2. RECORD EMPLOYER CONTRIBUTIONS
        // ============================================================
        foreach ($statutory_employer as $key => $amount) {
            $amount = (float)$amount;
            if ($amount <= 0) continue;
            
            $expense_code = getHrExpenseAccountCode($key);
            $payable_code = getHrPayableAccountCode($key);
            
            if (!$expense_code || !$payable_code) {
                error_log("No account mapping for employer: $key");
                continue;
            }
            
            $label = ucwords(str_replace('_', ' ', $key));
            
            // DR - Expense Account
            $gl_entries[] = $createGLEntry(
                $expense_code,
                $amount,
                0,
                "$description - Employer $label",
                $employee_id,
                $employee_name
            );
            
            // CR - Payable Account
            $gl_entries[] = $createGLEntry(
                $payable_code,
                0,
                $amount,
                "$description - Employer $label Payable",
                $employee_id,
                $employee_name
            );
        }

        // ============================================================
        // 3. VALIDATE: Total Debits == Total Credits
        // ============================================================
        $total_debit = array_sum(array_column($gl_entries, 'debit_amount'));
        $total_credit = array_sum(array_column($gl_entries, 'credit_amount'));
        
        if (abs($total_debit - $total_credit) > 0.01) {
            throw new Exception("GL posting unbalanced: debit=$total_debit, credit=$total_credit");
        }

        // ============================================================
        // 4. INSERT ALL GL ENTRIES
        // ============================================================
        $insert_fields = [
            'transaction_date', 'account_id', 'account_code', 'account_name',
            'debit_amount', 'credit_amount', 'description', 'reference_no',
            'reference_type', 'entity_id', 'entity_name', 'entity_type',
            'fiscal_year', 'fiscal_period', 'created_by', 'balance_type',
        ];
        
        $ph = '(' . implode(',', array_fill(0, count($insert_fields), '?')) . ')';
        $sql = "INSERT INTO general_ledger (" . implode(',', $insert_fields) . ") VALUES $ph";
        
        $stmt = $db->prepare($sql);
        foreach ($gl_entries as $entry) {
            $values = [];
            foreach ($insert_fields as $field) {
                $values[] = $entry[$field] ?? null;
            }
            $stmt->execute($values);
        }

        $db->commit();
        
        return [
            'success' => true,
            'entries' => count($gl_entries),
            'debit' => $total_debit,
            'credit' => $total_credit,
            'reference_no' => $reference_no,
            'message' => "GL entries created: " . count($gl_entries) . " entries"
        ];

    } catch (Exception $e) {
        $db->rollBack();
        error_log('Payroll GL posting error: ' . $e->getMessage());
        error_log('Stack trace: ' . $e->getTraceAsString());
        
        return [
            'success' => false,
            'error' => $e->getMessage(),
            'reference_no' => $reference_no ?? null
        ];
    }
}

/**
 * Post salary payment (net salary) to GL when employees are paid
 * 
 * @param PDO $db Database connection
 * @param string $reference_no Payment reference number
 * @param float $amount Net salary amount being paid
 * @param string $payment_date Payment date
 * @param string $posted_by User who processed the payment
 * @return bool
 */
function postSalaryPaymentToGL($db, $reference_no, $amount, $payment_date, $posted_by) {
    try {
        // Get account IDs
        $stmt = $db->prepare("SELECT id FROM chart_of_accounts WHERE account_code = '212' AND is_active = 1");
        $stmt->execute();
        $accrued = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$accrued) {
            error_log("Accrued Expenses account (212) not found");
            return false;
        }
        
        $stmt = $db->prepare("SELECT id FROM chart_of_accounts WHERE account_code = '111' AND is_active = 1");
        $stmt->execute();
        $cash = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // If no cash account found, try '1111' or '1110'
        if (!$cash) {
            $stmt = $db->prepare("SELECT id FROM chart_of_accounts WHERE account_code LIKE '111%' AND is_active = 1 LIMIT 1");
            $stmt->execute();
            $cash = $stmt->fetch(PDO::FETCH_ASSOC);
        }
        
        $year = date('Y', strtotime($payment_date));
        $period = (int)date('m', strtotime($payment_date));
        
        $db->beginTransaction();
        
        // DR - Accrued Expenses (212)
        $stmt = $db->prepare("
            INSERT INTO general_ledger (
                transaction_date, account_id, account_code, account_name,
                debit_amount, credit_amount, description, reference_no,
                reference_type, entity_type, fiscal_year, fiscal_period,
                created_by, balance_type, created_at
            ) VALUES (?, ?, '212', 'Accrued Expenses', ?, 0, ?, ?, 'salary_payment', 'O', ?, ?, ?, 'debit', NOW())
        ");
        $stmt->execute([
            $payment_date,
            $accrued['id'],
            $amount,
            "Salary Payment - $reference_no",
            $reference_no,
            $year,
            $period,
            $posted_by
        ]);
        
        // CR - Cash/Bank
        if ($cash) {
            $stmt = $db->prepare("
                INSERT INTO general_ledger (
                    transaction_date, account_id, account_code, account_name,
                    debit_amount, credit_amount, description, reference_no,
                    reference_type, entity_type, fiscal_year, fiscal_period,
                    created_by, balance_type, created_at
                ) VALUES (?, ?, ?, ?, 0, ?, ?, ?, 'salary_payment', 'O', ?, ?, ?, 'credit', NOW())
            ");
            
            $account_code = $cash['account_code'] ?? '111';
            $account_name = $cash['account_name'] ?? 'Cash at Bank';
            
            $stmt->execute([
                $payment_date,
                $cash['id'],
                $account_code,
                $account_name,
                $amount,
                "Salary Payment - $reference_no",
                $reference_no,
                $year,
                $period,
                $posted_by
            ]);
        }
        
        $db->commit();
        return true;
        
    } catch (Exception $e) {
        $db->rollBack();
        error_log("Salary payment GL posting error: " . $e->getMessage());
        return false;
    }
}

/**
 * Post statutory payments (NSSF, PAYE, SDL, WCF, OSHA, NHIF) to GL
 * 
 * @param PDO $db Database connection
 * @param string $reference_no Payment reference number
 * @param array $payments ['account_code' => amount]
 * @param string $payment_date Payment date
 * @param string $posted_by User who processed the payment
 * @return bool
 */
function postStatutoryPaymentsToGL($db, $reference_no, $payments, $payment_date, $posted_by) {
    try {
        $year = date('Y', strtotime($payment_date));
        $period = (int)date('m', strtotime($payment_date));
        
        $db->beginTransaction();
        
        foreach ($payments as $account_code => $amount) {
            $amount = (float)$amount;
            if ($amount <= 0) continue;
            
            // Get account ID
            $stmt = $db->prepare("SELECT id, account_code, account_name FROM chart_of_accounts WHERE account_code = ? AND is_active = 1");
            $stmt->execute([$account_code]);
            $account = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$account) {
                error_log("Account not found: $account_code");
                continue;
            }
            
            // Get cash account
            $stmt = $db->prepare("SELECT id FROM chart_of_accounts WHERE account_code LIKE '111%' AND is_active = 1 LIMIT 1");
            $stmt->execute();
            $cash = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // DR - Payable Account (reduce liability)
            $stmt = $db->prepare("
                INSERT INTO general_ledger (
                    transaction_date, account_id, account_code, account_name,
                    debit_amount, credit_amount, description, reference_no,
                    reference_type, entity_type, fiscal_year, fiscal_period,
                    created_by, balance_type, created_at
                ) VALUES (?, ?, ?, ?, ?, 0, ?, ?, 'statutory_payment', 'O', ?, ?, ?, 'debit', NOW())
            ");
            $stmt->execute([
                $payment_date,
                $account['id'],
                $account['account_code'],
                $account['account_name'],
                $amount,
                "Statutory Payment - $reference_no - {$account['account_name']}",
                $reference_no,
                $year,
                $period,
                $posted_by
            ]);
            
            // CR - Cash/Bank
            if ($cash) {
                $stmt = $db->prepare("
                    INSERT INTO general_ledger (
                        transaction_date, account_id, account_code, account_name,
                        debit_amount, credit_amount, description, reference_no,
                        reference_type, entity_type, fiscal_year, fiscal_period,
                        created_by, balance_type, created_at
                    ) VALUES (?, ?, ?, ?, 0, ?, ?, ?, 'statutory_payment', 'O', ?, ?, ?, 'credit', NOW())
                ");
                $stmt->execute([
                    $payment_date,
                    $cash['id'],
                    $cash['account_code'] ?? '111',
                    $cash['account_name'] ?? 'Cash at Bank',
                    $amount,
                    "Statutory Payment - $reference_no",
                    $reference_no,
                    $year,
                    $period,
                    $posted_by
                ]);
            }
        }
        
        $db->commit();
        return true;
        
    } catch (Exception $e) {
        $db->rollBack();
        error_log("Statutory payment GL posting error: " . $e->getMessage());
        return false;
    }
}
