<?php

if (!defined('PAYROLL_CONTROL_CODE')) {
    // Only require if not already loaded by parent
    require_once __DIR__ . '/../config/config.php';
    require_once __DIR__ . '/../config/account_mapping.php';
}

function postPayrollToGL($payroll_data) {
    $db = getDBConnection();
    $db->beginTransaction();

    try {
        $reference_no     = $payroll_data['reference_no'];
        $employee_name    = $payroll_data['employee_name'];
        $employee_id      = $payroll_data['employee_id'];
        $gross_salary     = $payroll_data['gross_salary'];
        $deductions       = $payroll_data['deductions'];
        $statutory_deductions = $payroll_data['statutory_deductions'];
        $statutory_employer   = $payroll_data['statutory_employer'];
        $net_salary       = $payroll_data['net_salary'];
        $period_start     = $payroll_data['period_start'];
        $period_end       = $payroll_data['period_end'];

        $year   = date('Y', strtotime($period_start));
        $period = (int)date('m', strtotime($period_start));

        $description = "Payroll $reference_no - $employee_name ($period_start to $period_end)";

        $created_by = 'system';
        if (isset($_SESSION['username'])) {
            $created_by = $_SESSION['username'];
        }

        // Pre-fetch account info for all codes used
        $codes_needed = [SALARIES_EXPENSE_CODE, PAYROLL_CONTROL_CODE];
        foreach (getHrPayableAccountMap() as $c) $codes_needed[] = $c;
        foreach (getHrExpenseAccountMap() as $c) $codes_needed[] = $c;

        $acct_cache = [];
        $placeholders = implode(',', array_fill(0, count($codes_needed), '?'));
        $stmt = $db->prepare("SELECT id, account_code, account_name FROM chart_of_accounts WHERE account_code IN ($placeholders) AND is_active = 1");
        $stmt->execute($codes_needed);
        foreach ($stmt as $row) {
            $acct_cache[$row['account_code']] = $row;
        }

        $mk_gl = function($info, $da, $cr, $desc, $ref, $eid, $en, $et, $yr, $per, $by, $bt) {
            return [
                'transaction_date' => date('Y-m-d'),
                'account_id'       => (int)$info['id'],
                'account_code'     => $info['account_code'],
                'account_name'     => $info['account_name'],
                'debit_amount'     => round((float)$da, 2),
                'credit_amount'    => round((float)$cr, 2),
                'description'      => $desc,
                'reference_no'     => $ref,
                'reference_type'   => 'payroll',
                'entity_id'        => $eid,
                'entity_name'      => $en,
                'entity_type'      => $et,
                'fiscal_year'      => $yr,
                'fiscal_period'    => $per,
                'created_by'       => $by,
                'balance_type'     => $bt,
            ];
        };

        $acct = function(&$cache, $code) {
            if (!isset($cache[$code])) throw new Exception("Account '$code' not found in chart_of_accounts");
            return $cache[$code];
        };

        $gl_entries = [];

        // 1) Debit Salaries Expense (511) with gross salary
        $gl_entries[] = $mk_gl($acct($acct_cache, SALARIES_EXPENSE_CODE), $gross_salary, 0,
            "$description - Gross Salary", $reference_no, $employee_id, $employee_name, 'E',
            $year, $period, $created_by, 'debit');

        // 2) Credit Payroll Control (216) with net salary
        $gl_entries[] = $mk_gl($acct($acct_cache, PAYROLL_CONTROL_CODE), 0, $net_salary,
            "$description - Net Salary Payable", $reference_no, $employee_id, $employee_name, 'E',
            $year, $period, $created_by, 'credit');

        // 3) Collect all unique keys from both employee and employer arrays
        $all_keys = array_unique(array_merge(
            array_keys($statutory_deductions),
            array_keys($statutory_employer)
        ));

        foreach ($all_keys as $key) {
            $employee_amt = isset($statutory_deductions[$key]) ? (float)$statutory_deductions[$key] : 0;
            $employer_amt = isset($statutory_employer[$key]) ? (float)$statutory_employer[$key] : 0;
            if ($employee_amt <= 0 && $employer_amt <= 0) continue;

            $payable_code = getHrPayableAccountCode($key);
            if (!$payable_code) continue;

            $total_payable = $employee_amt + $employer_amt;
            $label = ucwords(str_replace('_', ' ', $key));

            $gl_entries[] = $mk_gl($acct($acct_cache, $payable_code), 0, $total_payable,
                "$description - $label Payable", $reference_no, null, $label, 'O',
                $year, $period, $created_by, 'credit');

            $expense_code = getHrExpenseAccountCode($key);
            if ($expense_code && isset($acct_cache[$expense_code]) && $employer_amt > 0) {
                $gl_entries[] = $mk_gl($acct($acct_cache, $expense_code), $employer_amt, 0,
                    "$description - Employer $label", $reference_no, null, $label, 'O',
                    $year, $period, $created_by, 'debit');
            }
        }

        // 4) Non-statutory deductions
        if (!empty($deductions) && is_array($deductions)) {
            foreach ($deductions as $dk => $dv) {
                if ($dv <= 0) continue;
                $key = strtolower(trim($dk));
                if (isset($statutory_deductions[$key])) continue;
                $gl_entries[] = $mk_gl($acct($acct_cache, SALARIES_EXPENSE_CODE), $dv, 0,
                    "$description - $dk deduction", $reference_no, $employee_id, $employee_name, 'E',
                    $year, $period, $created_by, 'debit');
            }
        }

        // Validate: total debits == total credits
        $total_debit  = array_sum(array_column($gl_entries, 'debit_amount'));
        $total_credit = array_sum(array_column($gl_entries, 'credit_amount'));
        if (abs($total_debit - $total_credit) > 0.01) {
            throw new Exception("GL posting unbalanced: debit=$total_debit, credit=$total_credit");
        }

        // Insert all entries
        $insert_fields = [
            'transaction_date', 'account_id', 'account_code', 'account_name',
            'debit_amount', 'credit_amount', 'description', 'reference_no',
            'reference_type', 'entity_id', 'entity_name', 'entity_type',
            'fiscal_year', 'fiscal_period', 'created_by', 'balance_type',
        ];
        $ph  = '(' . implode(',', array_fill(0, count($insert_fields), '?')) . ')';
        $sql = "INSERT INTO general_ledger (" . implode(',', $insert_fields) . ") VALUES $ph";

        $stmt = $db->prepare($sql);
        foreach ($gl_entries as $entry) {
            $stmt->execute(array_values($entry));
        }

        $db->commit();
        return [
            'success' => true,
            'entries' => count($gl_entries),
            'debit'   => $total_debit,
            'credit'  => $total_credit,
        ];

    } catch (Exception $e) {
        $db->rollBack();
        error_log('Payroll GL posting error: ' . $e->getMessage());
        return ['success' => false, 'error' => $e->getMessage()];
    }
}
