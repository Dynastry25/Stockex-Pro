<?php
// /includes/payroll_accounting.php - SIMPLIFIED VERSION FOR DEBUGGING

error_reporting(E_ALL);
ini_set('display_errors', 1);

if (!defined('PAYROLL_CONTROL_CODE')) {
    require_once __DIR__ . '/../config/config.php';
}

/**
 * SIMPLIFIED: Post payroll entries to General Ledger
 */
function postPayrollToGL($payroll_data) {
    // Log that function was called
    error_log("=== postPayrollToGL called ===");
    error_log("Data: " . print_r($payroll_data, true));
    
    try {
        $db = getDBConnection();
        error_log("Database connection obtained");
        
        // Start transaction
        $db->beginTransaction();
        error_log("Transaction started");
        
        // Extract data
        $reference_no = $payroll_data['reference_no'] ?? 'UNKNOWN';
        $employee_name = $payroll_data['employee_name'] ?? 'Unknown';
        $employee_id = $payroll_data['employee_id'] ?? null;
        $gross_salary = (float)($payroll_data['gross_salary'] ?? 0);
        $net_salary = (float)($payroll_data['net_salary'] ?? 0);
        $statutory_deductions = $payroll_data['statutory_deductions'] ?? [];
        $statutory_employer = $payroll_data['statutory_employer'] ?? [];
        $period_start = $payroll_data['period_start'] ?? date('Y-m-01');
        $posted_by = $payroll_data['posted_by'] ?? 'system';
        
        error_log("Processing: $reference_no - $employee_name - Gross: $gross_salary");
        
        // Get account IDs
        $stmt = $db->prepare("SELECT id, account_code FROM chart_of_accounts WHERE account_code IN ('511', '212') AND is_active = 1");
        $stmt->execute();
        $accounts = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $accounts[$row['account_code']] = $row['id'];
        }
        
        error_log("Found accounts: " . print_r($accounts, true));
        
        // Check if we have the required accounts
        if (!isset($accounts['511'])) {
            throw new Exception("Account 511 (Salaries & Wages) not found");
        }
        if (!isset($accounts['212'])) {
            throw new Exception("Account 212 (Accrued Expenses) not found");
        }
        
        $entries_count = 0;
        
        // 1. DR - Salaries & Wages (511)
        if ($gross_salary > 0) {
            $stmt = $db->prepare("
                INSERT INTO general_ledger (
                    transaction_date, account_id, account_code, account_name,
                    debit_amount, credit_amount, description, reference_no,
                    reference_type, created_by, created_at
                ) VALUES (NOW(), ?, '511', 'Salaries & Wages', ?, 0, ?, ?, 'payroll', ?, NOW())
            ");
            $stmt->execute([
                $accounts['511'],
                $gross_salary,
                "Payroll $reference_no - $employee_name - Gross Salary",
                $reference_no,
                $posted_by
            ]);
            $entries_count++;
            error_log("Created DR entry for Salaries & Wages: $gross_salary");
        }
        
        // 2. CR - Accrued Expenses (212) for Net Salary
        if ($net_salary > 0) {
            $stmt = $db->prepare("
                INSERT INTO general_ledger (
                    transaction_date, account_id, account_code, account_name,
                    debit_amount, credit_amount, description, reference_no,
                    reference_type, created_by, created_at
                ) VALUES (NOW(), ?, '212', 'Accrued Expenses', 0, ?, ?, ?, 'payroll', ?, NOW())
            ");
            $stmt->execute([
                $accounts['212'],
                $net_salary,
                "Payroll $reference_no - $employee_name - Net Salary Payable",
                $reference_no,
                $posted_by
            ]);
            $entries_count++;
            error_log("Created CR entry for Accrued Expenses: $net_salary");
        }
        
        // 3. Handle Statutory Deductions (Employee)
        foreach ($statutory_deductions as $key => $amount) {
            $amount = (float)$amount;
            if ($amount <= 0) continue;
            
            // Map to account code
            $account_code = getPayableAccountCode($key);
            if (!$account_code) {
                error_log("No account mapping for: $key");
                continue;
            }
            
            // Get account ID
            $stmt = $db->prepare("SELECT id, account_name FROM chart_of_accounts WHERE account_code = ? AND is_active = 1");
            $stmt->execute([$account_code]);
            $account = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$account) {
                error_log("Account not found: $account_code");
                continue;
            }
            
            $stmt = $db->prepare("
                INSERT INTO general_ledger (
                    transaction_date, account_id, account_code, account_name,
                    debit_amount, credit_amount, description, reference_no,
                    reference_type, created_by, created_at
                ) VALUES (NOW(), ?, ?, ?, 0, ?, ?, ?, 'payroll', ?, NOW())
            ");
            $stmt->execute([
                $account['id'],
                $account_code,
                $account['account_name'],
                $amount,
                "Payroll $reference_no - $employee_name - " . ucfirst($key) . " Withholding",
                $reference_no,
                $posted_by
            ]);
            $entries_count++;
            error_log("Created CR entry for $key: $amount");
        }
        
        // 4. Handle Employer Contributions
        foreach ($statutory_employer as $key => $amount) {
            $amount = (float)$amount;
            if ($amount <= 0) continue;
            
            $expense_code = getExpenseAccountCode($key);
            $payable_code = getPayableAccountCode($key);
            
            if (!$expense_code || !$payable_code) {
                error_log("No account mapping for employer: $key");
                continue;
            }
            
            // Get expense account
            $stmt = $db->prepare("SELECT id, account_name FROM chart_of_accounts WHERE account_code = ? AND is_active = 1");
            $stmt->execute([$expense_code]);
            $expense_account = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Get payable account
            $stmt = $db->prepare("SELECT id, account_name FROM chart_of_accounts WHERE account_code = ? AND is_active = 1");
            $stmt->execute([$payable_code]);
            $payable_account = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$expense_account || !$payable_account) {
                error_log("Accounts not found for employer: $key");
                continue;
            }
            
            // DR - Expense
            $stmt = $db->prepare("
                INSERT INTO general_ledger (
                    transaction_date, account_id, account_code, account_name,
                    debit_amount, credit_amount, description, reference_no,
                    reference_type, created_by, created_at
                ) VALUES (NOW(), ?, ?, ?, ?, 0, ?, ?, 'payroll', ?, NOW())
            ");
            $stmt->execute([
                $expense_account['id'],
                $expense_code,
                $expense_account['account_name'],
                $amount,
                "Payroll $reference_no - $employee_name - Employer " . ucfirst($key),
                $reference_no,
                $posted_by
            ]);
            
            // CR - Payable
            $stmt = $db->prepare("
                INSERT INTO general_ledger (
                    transaction_date, account_id, account_code, account_name,
                    debit_amount, credit_amount, description, reference_no,
                    reference_type, created_by, created_at
                ) VALUES (NOW(), ?, ?, ?, 0, ?, ?, ?, 'payroll', ?, NOW())
            ");
            $stmt->execute([
                $payable_account['id'],
                $payable_code,
                $payable_account['account_name'],
                $amount,
                "Payroll $reference_no - $employee_name - Employer " . ucfirst($key) . " Payable",
                $reference_no,
                $posted_by
            ]);
            
            $entries_count += 2;
            error_log("Created employer entries for $key: $amount");
        }
        
        $db->commit();
        error_log("Transaction committed. Total entries: $entries_count");
        
        return [
            'success' => true,
            'entries' => $entries_count,
            'message' => "Created $entries_count GL entries"
        ];
        
    } catch (Exception $e) {
        if (isset($db) && $db->inTransaction()) {
            $db->rollBack();
        }
        error_log("ERROR in postPayrollToGL: " . $e->getMessage());
        error_log("Stack trace: " . $e->getTraceAsString());
        return [
            'success' => false,
            'error' => $e->getMessage()
        ];
    }
}

/**
 * Get payable account code for a deduction key
 */
function getPayableAccountCode($key) {
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
 * Get expense account code for a deduction key
 */
function getExpenseAccountCode($key) {
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
