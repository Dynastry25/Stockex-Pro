<?php
// /hr/pay_salary.php - DYNAMIC SALARY PAYMENT SYSTEM
require_once '../config/config.php';
require_once '../auth/auth_middleware.php';
require_once '../includes/payroll_helpers.php';

// Check permissions - HR only
$current_user_id = $_SESSION['user_id'];
$current_user_role = $_SESSION['role'];

if ($current_user_role !== 'hr_manager') {
    header('Location: ./dashboard.php');
    exit();
}

$db = getDBConnection();
$page_title = 'Salary Payment System';
$success_message = '';
$error_message = '';

// Country configuration (can be changed via settings)
$current_country = 'TZ'; // Default to Tanzania

// ==================== HELPER FUNCTIONS ====================

/**
 * Calculate PAYE Tax based on taxable income (Gross - NSSF)
 * Using progressive tax brackets
 */
function calculate_paye_tax($taxable_income) {
    global $db;
    
    // Default Tanzania PAYE brackets (2024)
    $default_brackets = [
        ['min' => 0, 'max' => 270000, 'rate' => 0],
        ['min' => 270001, 'max' => 520000, 'rate' => 8],
        ['min' => 520001, 'max' => 760000, 'rate' => 20],
        ['min' => 760001, 'max' => 1000000, 'rate' => 25],
        ['min' => 1000001, 'max' => 0, 'rate' => 30]
    ];
    
    try {
        $stmt = $db->query("SELECT bracket_min as min, bracket_max as max, tax_rate as rate 
                           FROM paye_tax_brackets WHERE status = 'active' ORDER BY bracket_min");
        $brackets = $stmt->fetchAll();
        
        if (empty($brackets)) {
            $brackets = $default_brackets;
        }
    } catch (Exception $e) {
        $brackets = $default_brackets;
    }
    
    $tax = 0;
    $remaining_income = $taxable_income;
    
    foreach ($brackets as $bracket) {
        if ($remaining_income <= 0) break;
        
        if ($bracket['max'] == 0) {
            $bracket_amount = $remaining_income;
        } else {
            $bracket_range = $bracket['max'] - $bracket['min'];
            $bracket_amount = min($remaining_income, $bracket_range);
        }
        
        $tax += ($bracket_amount * $bracket['rate'] / 100);
        $remaining_income -= $bracket_amount;
    }
    
    return $tax;
}

/**
 * Get employee NHIF rate from profile
 */
function getEmployeeNHIFRate($db, $user_id) {
    try {
        $stmt = $db->prepare("
            SELECT nhif_rate FROM employee_profiles 
            WHERE user_id = ? 
            LIMIT 1
        ");
        $stmt->execute([$user_id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result && isset($result['nhif_rate'])) {
            return (float)$result['nhif_rate'];
        }
        
        // Default NHIF rate from system settings
        $stmt = $db->query("SELECT setting_value FROM system_settings WHERE setting_key = 'default_nhif_rate'");
        $default = $stmt->fetch();
        return $default ? (float)$default['setting_value'] : 3.0;
        
    } catch (Exception $e) {
        return 3.0; // Default NHIF rate
    }
}

/**
 * Get employee loan deductions
 */
function getEmployeeLoanDeductions($db, $user_id, $pay_period_month) {
    $total_loans = 0;
    $loan_details = [];
    
    try {
        // Get active loans with monthly deductions
        $stmt = $db->prepare("
            SELECT l.*, 
                   COALESCE(
                       (SELECT SUM(amount_paid) FROM loan_payments 
                        WHERE loan_id = l.id AND pay_period_month = ?),
                       0
                   ) as paid_this_month
            FROM loans l
            WHERE l.user_id = ? 
            AND l.status = 'active'
            AND l.monthly_deduction > 0
        ");
        $stmt->execute([$pay_period_month, $user_id]);
        $loans = $stmt->fetchAll();
        
        foreach ($loans as $loan) {
            $remaining = $loan['monthly_deduction'] - $loan['paid_this_month'];
            if ($remaining > 0) {
                $total_loans += $remaining;
                $loan_details[] = [
                    'loan_id' => $loan['id'],
                    'loan_type' => $loan['loan_type'],
                    'amount' => $remaining,
                    'balance' => $loan['balance']
                ];
            }
        }
    } catch (Exception $e) {
        error_log("Error getting loan deductions: " . $e->getMessage());
    }
    
    return [
        'total' => $total_loans,
        'details' => $loan_details
    ];
}

/**
 * Get employee allowance/overtime/bonus for the month
 */
function getEmployeeIncentives($db, $user_id, $pay_period_month) {
    $incentives = [
        'allowances' => 0,
        'overtime' => 0,
        'bonuses' => 0,
        'details' => []
    ];
    
    try {
        $stmt = $db->prepare("
            SELECT incentive_type, amount, description
            FROM payroll_incentives 
            WHERE user_id = ? AND pay_period_month = ? AND status = 'approved'
        ");
        $stmt->execute([$user_id, $pay_period_month]);
        $results = $stmt->fetchAll();
        
        foreach ($results as $row) {
            $type = strtolower(trim($row['incentive_type']));
            $amount = (float)$row['amount'];
            
            if ($type === 'allowance' || $type === 'allowances') {
                $incentives['allowances'] += $amount;
            } elseif ($type === 'overtime') {
                $incentives['overtime'] += $amount;
            } elseif ($type === 'bonus' || $type === 'bonuses') {
                $incentives['bonuses'] += $amount;
            }
            
            $incentives['details'][] = [
                'type' => $type,
                'amount' => $amount,
                'description' => $row['description']
            ];
        }
    } catch (Exception $e) {
        error_log("Error getting incentives: " . $e->getMessage());
    }
    
    return $incentives;
}

/**
 * Get house allowance from employee profile or default
 */
function getHouseAllowance($db, $user_id) {
    try {
        $stmt = $db->prepare("
            SELECT house_allowance FROM employee_profiles 
            WHERE user_id = ? 
            LIMIT 1
        ");
        $stmt->execute([$user_id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result && isset($result['house_allowance'])) {
            return (float)$result['house_allowance'];
        }
    } catch (Exception $e) {
        // Table might not exist
    }
    
    // Default: 10% of basic salary
    return 0;
}

function generate_salary_payment_request_no() {
    global $db;
    $prefix = 'SAL';
    $year = date('Y');
    $month = date('m');
    
    try {
        $stmt = $db->prepare("
            SELECT request_no FROM pending_pay 
            WHERE request_no LIKE ? 
            ORDER BY id DESC LIMIT 1
        ");
        $stmt->execute(["$prefix$year$month%"]);
        $last = $stmt->fetch();
        
        if ($last && isset($last['request_no'])) {
            $last_no = intval(substr($last['request_no'], -4));
            $new_no = str_pad($last_no + 1, 4, '0', STR_PAD_LEFT);
        } else {
            $new_no = '0001';
        }
        
        return $prefix . $year . $month . $new_no;
    } catch (Exception $e) {
        return $prefix . $year . $month . '0001';
    }
}

function get_system_rates() {
    global $db;
    
    $rates = [];
    try {
        $stmt = $db->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key LIKE '%_rate' OR setting_key IN ('enable_nhif', 'default_nhif_rate', 'paye_basis')");
        $rates_data = $stmt->fetchAll();
        foreach ($rates_data as $r) {
            $rates[$r['setting_key']] = $r['setting_value'];
        }
    } catch (Exception $e) {
        // Table might not exist, use defaults
    }
    
    $default_rates = [
        'nssf_employee_rate' => 10,
        'nssf_employer_rate' => 10,
        'sdl_rate' => 3.5,
        'wcf_rate' => 0.5,
        'osha_rate' => 0.5,
        'enable_nhif' => 0,
        'default_nhif_rate' => 3,
        'paye_basis' => 'gross_less_nssf', // or 'basic_salary'
        'overtime_rate_per_hour' => 5000
    ];
    
    foreach ($default_rates as $key => $value) {
        if (!isset($rates[$key])) $rates[$key] = $value;
    }
    
    return $rates;
}

// ==================== EXPORT TO EXCEL ====================

if (isset($_GET['export']) && $_GET['export'] === 'excel' && isset($_SESSION['salary_calculation'])) {
    $salary_calculation = $_SESSION['salary_calculation'];
    
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="salary_calculation_' . date('Y-m-d') . '.xls"');
    
    echo "<html><head><meta charset=\"UTF-8\">";
    echo "<style>td,th{border:1px solid #000;padding:5px;}th{background:#f2f2f2;}.total{font-weight:bold;background:#e6f3ff;}.subtotal{font-weight:bold;background:#f0f0f0;}</style>";
    echo "</head><body>";
    
    echo "<h2>Salary Calculation - " . date('F Y', strtotime($salary_calculation['pay_period_month'] . '-01')) . "</h2>";
    echo "<p>Generated on: " . date('Y-m-d H:i:s') . "</p>";
    
    // Summary
    echo "<h3>Summary</h3>";
    echo "<table border='1'>";
    echo "<tr><th>Total Employees</th><th>Total Gross Salary</th><th>Total NSSF</th><th>Total PAYE</th><th>Total Other Deductions</th><th>Total Deductions</th><th>Total Net Salary</th><th>Total Employer Cost</th></tr>";
    echo "<tr class='total'>";
    echo "<td>" . ($salary_calculation['summary']['total_employees'] ?? 0) . "</td>";
    echo "<td>" . format_payroll_currency($salary_calculation['summary']['total_gross_salary'] ?? 0, false) . "</td>";
    echo "<td>" . format_payroll_currency($salary_calculation['summary']['total_nssf_employee'] ?? 0, false) . "</td>";
    echo "<td>" . format_payroll_currency($salary_calculation['summary']['total_paye_tax'] ?? 0, false) . "</td>";
    echo "<td>" . format_payroll_currency($salary_calculation['summary']['total_other_deductions'] ?? 0, false) . "</td>";
    echo "<td>" . format_payroll_currency($salary_calculation['summary']['total_deductions'] ?? 0, false) . "</td>";
    echo "<td>" . format_payroll_currency($salary_calculation['summary']['total_net_salary'] ?? 0, false) . "</td>";
    echo "<td>" . format_payroll_currency($salary_calculation['summary']['total_employer_cost'] ?? 0, false) . "</td>";
    echo "</tr></table><br>";
    
    // Employer Contributions
    echo "<h3>Employer Contributions</h3>";
    echo "<table border='1'>";
    echo "<tr><th>NSSF Employer</th><th>SDL</th><th>WCF</th><th>OSHA</th><th>Total Employer Contributions</th></tr>";
    echo "<tr class='subtotal'>";
    echo "<td>" . format_payroll_currency($salary_calculation['summary']['total_nssf_employer'] ?? 0, false) . "</td>";
    echo "<td>" . format_payroll_currency($salary_calculation['summary']['total_sdl'] ?? 0, false) . "</td>";
    echo "<td>" . format_payroll_currency($salary_calculation['summary']['total_wcf'] ?? 0, false) . "</td>";
    echo "<td>" . format_payroll_currency($salary_calculation['summary']['total_osha'] ?? 0, false) . "</td>";
    echo "<td>" . format_payroll_currency(
        ($salary_calculation['summary']['total_nssf_employer'] ?? 0) +
        ($salary_calculation['summary']['total_sdl'] ?? 0) +
        ($salary_calculation['summary']['total_wcf'] ?? 0) +
        ($salary_calculation['summary']['total_osha'] ?? 0), 
        false
    ) . "</td>";
    echo "</tr></table><br>";
    
    // Employee Details
    echo "<h3>Employee Details</h3>";
    echo "<table border='1'>";
    echo "<tr>";
    echo "<th>No</th><th>Employee</th><th>Job Title</th><th>Basic</th><th>House Allowance</th>";
    echo "<th>Allowances</th><th>Overtime</th><th>Bonuses</th><th>Gross</th>";
    echo "<th>NSSF Emp</th><th>PAYE</th><th>NHIF</th><th>Loans</th><th>Other Ded</th>";
    echo "<th>Total Ded</th><th>Net Pay</th>";
    echo "<th>NSSF Emp'r</th><th>SDL</th><th>WCF</th><th>OSHA</th>";
    echo "<th>Employer Cost</th>";
    echo "</tr>";
    
    if (isset($salary_calculation['employees']) && !empty($salary_calculation['employees'])) {
        $counter = 1;
        foreach ($salary_calculation['employees'] as $emp) {
            echo "<tr>";
            echo "<td>" . $counter++ . "</td>";
            echo "<td>" . htmlspecialchars($emp['employee_name'] ?? 'Unknown') . "</td>";
            echo "<td>" . htmlspecialchars($emp['job_title'] ?? '') . "</td>";
            echo "<td>" . format_payroll_currency($emp['basic_salary'] ?? 0, false) . "</td>";
            echo "<td>" . format_payroll_currency($emp['house_allowance'] ?? 0, false) . "</td>";
            echo "<td>" . format_payroll_currency($emp['allowances'] ?? 0, false) . "</td>";
            echo "<td>" . format_payroll_currency($emp['overtime'] ?? 0, false) . "</td>";
            echo "<td>" . format_payroll_currency($emp['bonuses'] ?? 0, false) . "</td>";
            echo "<td>" . format_payroll_currency($emp['gross_salary'] ?? 0, false) . "</td>";
            echo "<td>" . format_payroll_currency($emp['nssf_employee'] ?? 0, false) . "</td>";
            echo "<td>" . format_payroll_currency($emp['paye_tax'] ?? 0, false) . "</td>";
            echo "<td>" . format_payroll_currency($emp['nhif'] ?? 0, false) . "</td>";
            echo "<td>" . format_payroll_currency($emp['loans'] ?? 0, false) . "</td>";
            echo "<td>" . format_payroll_currency($emp['other_deductions'] ?? 0, false) . "</td>";
            echo "<td>" . format_payroll_currency($emp['total_deductions'] ?? 0, false) . "</td>";
            echo "<td>" . format_payroll_currency($emp['net_salary'] ?? 0, false) . "</td>";
            echo "<td>" . format_payroll_currency($emp['nssf_employer'] ?? 0, false) . "</td>";
            echo "<td>" . format_payroll_currency($emp['sdl'] ?? 0, false) . "</td>";
            echo "<td>" . format_payroll_currency($emp['wcf'] ?? 0, false) . "</td>";
            echo "<td>" . format_payroll_currency($emp['osha'] ?? 0, false) . "</td>";
            echo "<td>" . format_payroll_currency($emp['total_employer_cost'] ?? 0, false) . "</td>";
            echo "</tr>";
        }
        
        // Totals
        echo "<tr class='total'>";
        echo "<td colspan='4'><strong>TOTALS</strong></td>";
        echo "<td>" . format_payroll_currency($salary_calculation['summary']['total_house_allowance'] ?? 0, false) . "</td>";
        echo "<td>" . format_payroll_currency($salary_calculation['summary']['total_allowances'] ?? 0, false) . "</td>";
        echo "<td>" . format_payroll_currency($salary_calculation['summary']['total_overtime'] ?? 0, false) . "</td>";
        echo "<td>" . format_payroll_currency($salary_calculation['summary']['total_bonuses'] ?? 0, false) . "</td>";
        echo "<td>" . format_payroll_currency($salary_calculation['summary']['total_gross_salary'] ?? 0, false) . "</td>";
        echo "<td>" . format_payroll_currency($salary_calculation['summary']['total_nssf_employee'] ?? 0, false) . "</td>";
        echo "<td>" . format_payroll_currency($salary_calculation['summary']['total_paye_tax'] ?? 0, false) . "</td>";
        echo "<td>" . format_payroll_currency($salary_calculation['summary']['total_nhif'] ?? 0, false) . "</td>";
        echo "<td>" . format_payroll_currency($salary_calculation['summary']['total_loans'] ?? 0, false) . "</td>";
        echo "<td>" . format_payroll_currency($salary_calculation['summary']['total_other_deductions'] ?? 0, false) . "</td>";
        echo "<td>" . format_payroll_currency($salary_calculation['summary']['total_deductions'] ?? 0, false) . "</td>";
        echo "<td>" . format_payroll_currency($salary_calculation['summary']['total_net_salary'] ?? 0, false) . "</td>";
        echo "<td>" . format_payroll_currency($salary_calculation['summary']['total_nssf_employer'] ?? 0, false) . "</td>";
        echo "<td>" . format_payroll_currency($salary_calculation['summary']['total_sdl'] ?? 0, false) . "</td>";
        echo "<td>" . format_payroll_currency($salary_calculation['summary']['total_wcf'] ?? 0, false) . "</td>";
        echo "<td>" . format_payroll_currency($salary_calculation['summary']['total_osha'] ?? 0, false) . "</td>";
        echo "<td>" . format_payroll_currency($salary_calculation['summary']['total_employer_cost'] ?? 0, false) . "</td>";
        echo "</tr>";
    }
    echo "</table></body></html>";
    exit();
}

// ==================== POST HANDLERS ====================

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        // === 1. CALCULATE SALARIES ===
        if (isset($_POST['calculate_salaries'])) {
            $pay_period_month = $_POST['pay_period_month'];
            
            // Get all active employees (excluding system_admin and ceo)
            $stmt = $db->query("
                SELECT u.*, 
                       ep.house_allowance,
                       ep.nhif_rate,
                       ep.other_deductions as fixed_deductions
                FROM users u
                LEFT JOIN employee_profiles ep ON u.id = ep.user_id
                WHERE u.status = 'active' 
                AND u.role NOT IN ('system_admin', 'ceo')
                ORDER BY u.full_name
            ");
            $employees = $stmt->fetchAll();
            
            // Get system rates
            $rates = get_system_rates();
            
            // Initialize calculation structure
            $salary_calculation = [
                'pay_period_month' => $pay_period_month,
                'calculation_date' => date('Y-m-d H:i:s'),
                'country' => $current_country,
                'employees' => [],
                'summary' => [
                    'total_employees' => 0,
                    'total_basic_salary' => 0,
                    'total_house_allowance' => 0,
                    'total_allowances' => 0,
                    'total_overtime' => 0,
                    'total_bonuses' => 0,
                    'total_gross_salary' => 0,
                    'total_nssf_employee' => 0,
                    'total_paye_tax' => 0,
                    'total_nhif' => 0,
                    'total_loans' => 0,
                    'total_other_deductions' => 0,
                    'total_deductions' => 0,
                    'total_net_salary' => 0,
                    'total_nssf_employer' => 0,
                    'total_sdl' => 0,
                    'total_wcf' => 0,
                    'total_osha' => 0,
                    'total_employer_cost' => 0
                ]
            ];
            
            // Calculate for each employee
            foreach ($employees as $emp) {
                $basic_salary = (float)$emp['salary'];
                $house_allowance = (float)($emp['house_allowance'] ?? getHouseAllowance($db, $emp['id']));
                
                // Get incentives for this month
                $incentives = getEmployeeIncentives($db, $emp['id'], $pay_period_month);
                
                // Get loan deductions
                $loan_data = getEmployeeLoanDeductions($db, $emp['id'], $pay_period_month);
                
                // Calculate GROSS SALARY = Basic + House Allowance + Allowances + Overtime + Bonuses
                $gross_salary = $basic_salary + $house_allowance + 
                               $incentives['allowances'] + 
                               $incentives['overtime'] + 
                               $incentives['bonuses'];
                
                // === EMPLOYEE DEDUCTIONS ===
                
                // 1. NSSF Employee: 10% of GROSS SALARY (matches Excel)
                $nssf_employee = ($gross_salary * $rates['nssf_employee_rate'] / 100);
                
                // 2. PAYE: Calculated on (Gross - NSSF) - matches Excel
                $taxable_income = $gross_salary - $nssf_employee;
                $paye_tax = calculate_paye_tax($taxable_income);
                
                // 3. NHIF: Get employee-specific rate (matches Excel variable rates)
                $nhif_rate = (float)($emp['nhif_rate'] ?? getEmployeeNHIFRate($db, $emp['id']));
                $nhif = ($nhif_rate > 0) ? ($gross_salary * $nhif_rate / 100) : 0;
                
                // 4. Loans/Other deductions
                $loans = $loan_data['total'];
                $other_deductions = (float)($emp['fixed_deductions'] ?? 0);
                
                // Total employee deductions
                $total_deductions = $nssf_employee + $paye_tax + $nhif + $loans + $other_deductions;
                
                // Net Salary
                $net_salary = $gross_salary - $total_deductions;
                
                // === EMPLOYER CONTRIBUTIONS ===
                $nssf_employer = ($gross_salary * $rates['nssf_employer_rate'] / 100);
                $sdl = ($gross_salary * $rates['sdl_rate'] / 100);
                $wcf = ($gross_salary * $rates['wcf_rate'] / 100);
                $osha = ($gross_salary * $rates['osha_rate'] / 100);
                
                $total_employer_contributions = $nssf_employer + $sdl + $wcf + $osha;
                $total_employer_cost = $gross_salary + $total_employer_contributions;
                
                // Store employee calculation
                $employee_calc = [
                    'user_id' => $emp['id'],
                    'employee_name' => $emp['full_name'],
                    'job_title' => $emp['job_title'],
                    'basic_salary' => $basic_salary,
                    'house_allowance' => $house_allowance,
                    'allowances' => $incentives['allowances'],
                    'overtime' => $incentives['overtime'],
                    'bonuses' => $incentives['bonuses'],
                    'gross_salary' => $gross_salary,
                    'nssf_employee' => $nssf_employee,
                    'paye_tax' => $paye_tax,
                    'nhif' => $nhif,
                    'loans' => $loans,
                    'loan_details' => $loan_data['details'],
                    'other_deductions' => $other_deductions,
                    'total_deductions' => $total_deductions,
                    'net_salary' => $net_salary,
                    'nssf_employer' => $nssf_employer,
                    'sdl' => $sdl,
                    'wcf' => $wcf,
                    'osha' => $osha,
                    'total_employer_contributions' => $total_employer_contributions,
                    'total_employer_cost' => $total_employer_cost,
                    'nhif_rate' => $nhif_rate
                ];
                
                $salary_calculation['employees'][] = $employee_calc;
                
                // Update summary totals
                $salary_calculation['summary']['total_employees']++;
                $salary_calculation['summary']['total_basic_salary'] += $basic_salary;
                $salary_calculation['summary']['total_house_allowance'] += $house_allowance;
                $salary_calculation['summary']['total_allowances'] += $incentives['allowances'];
                $salary_calculation['summary']['total_overtime'] += $incentives['overtime'];
                $salary_calculation['summary']['total_bonuses'] += $incentives['bonuses'];
                $salary_calculation['summary']['total_gross_salary'] += $gross_salary;
                $salary_calculation['summary']['total_nssf_employee'] += $nssf_employee;
                $salary_calculation['summary']['total_paye_tax'] += $paye_tax;
                $salary_calculation['summary']['total_nhif'] += $nhif;
                $salary_calculation['summary']['total_loans'] += $loans;
                $salary_calculation['summary']['total_other_deductions'] += $other_deductions;
                $salary_calculation['summary']['total_deductions'] += $total_deductions;
                $salary_calculation['summary']['total_net_salary'] += $net_salary;
                $salary_calculation['summary']['total_nssf_employer'] += $nssf_employer;
                $salary_calculation['summary']['total_sdl'] += $sdl;
                $salary_calculation['summary']['total_wcf'] += $wcf;
                $salary_calculation['summary']['total_osha'] += $osha;
                $salary_calculation['summary']['total_employer_cost'] += $total_employer_cost;
            }
            
            // Store calculation in session
            $_SESSION['salary_calculation'] = $salary_calculation;
            $_SESSION['pay_period_month'] = $pay_period_month;
            
            $success_message = 'Salaries calculated successfully. Total Net Pay: ' . 
                             format_payroll_currency($salary_calculation['summary']['total_net_salary']);
            redirect('hr/pay_salary.php?section=payment');
        }
        
        // === 2. GENERATE PAYMENT REQUEST WITH GL POSTING ===
        elseif (isset($_POST['generate_payment_request'])) {
            if (!isset($_SESSION['salary_calculation'])) {
                $error_message = 'No salary calculation found. Please calculate salaries first.';
            } else {
                $salary_calculation = $_SESSION['salary_calculation'];
                $pay_period_month = $_SESSION['pay_period_month'];
                $payment_date = $_POST['payment_date'] ?? date('Y-m-d');
                $payment_method = $_POST['payment_method'] ?? 'bank_transfer';
                $notes = sanitize_input($_POST['notes'] ?? '');
                
                $total_employer_cost = $salary_calculation['summary']['total_employer_cost'] ?? 0;
                $net_salary = $salary_calculation['summary']['total_net_salary'] ?? 0;
                $total_gross = $salary_calculation['summary']['total_gross_salary'] ?? 0;
                
                // Summary of statutory deductions
                $total_nssf_employee = $salary_calculation['summary']['total_nssf_employee'] ?? 0;
                $total_paye = $salary_calculation['summary']['total_paye_tax'] ?? 0;
                $total_nhif = $salary_calculation['summary']['total_nhif'] ?? 0;
                $total_loans = $salary_calculation['summary']['total_loans'] ?? 0;
                $total_other_ded = $salary_calculation['summary']['total_other_deductions'] ?? 0;
                
                $total_nssf_employer = $salary_calculation['summary']['total_nssf_employer'] ?? 0;
                $total_sdl = $salary_calculation['summary']['total_sdl'] ?? 0;
                $total_wcf = $salary_calculation['summary']['total_wcf'] ?? 0;
                $total_osha = $salary_calculation['summary']['total_osha'] ?? 0;
                
                // Start database transaction
                $db->beginTransaction();
                
                try {
                    // Generate request number
                    $request_no = generate_salary_payment_request_no();
                    
                    // Insert into pending_pay
                    $stmt = $db->prepare("
                        INSERT INTO pending_pay (
                            request_no, subject, pay_to_type, payee_id, payee_name,
                            payee_bank_name, payee_branch, payee_account_name,
                            payee_account_no, currency, amount_paid, cheque_no,
                            payment_description, requested_by, status
                        ) VALUES (?, ?, 'O', ?, ?, ?, ?, ?, ?, 'TSH', ?, ?, ?, ?, 'pending')
                    ");
                    
                    $subject = "Salary Payment - " . date('F Y', strtotime($pay_period_month . '-01'));
                    
                    // Build detailed description with all deductions
                    $description = "SALARY PAYMENT REQUEST\n";
                    $description .= "========================\n";
                    $description .= "Period: " . date('F Y', strtotime($pay_period_month . '-01')) . "\n";
                    $description .= "Total Employees: " . $salary_calculation['summary']['total_employees'] . "\n\n";
                    
                    $description .= "PAYMENT BREAKDOWN:\n";
                    $description .= "1. Gross Salary: " . format_payroll_currency($total_gross) . "\n";
                    $description .= "2. Employee Deductions:\n";
                    $description .= "   - NSSF (Employee): " . format_payroll_currency($total_nssf_employee) . "\n";
                    $description .= "   - PAYE: " . format_payroll_currency($total_paye) . "\n";
                    $description .= "   - NHIF: " . format_payroll_currency($total_nhif) . "\n";
                    $description .= "   - Loans: " . format_payroll_currency($total_loans) . "\n";
                    $description .= "   - Other Deductions: " . format_payroll_currency($total_other_ded) . "\n";
                    $description .= "3. Net Salary to Employees: " . format_payroll_currency($net_salary) . "\n\n";
                    
                    $description .= "EMPLOYER CONTRIBUTIONS:\n";
                    $description .= "   - NSSF (Employer): " . format_payroll_currency($total_nssf_employer) . "\n";
                    $description .= "   - SDL: " . format_payroll_currency($total_sdl) . "\n";
                    $description .= "   - WCF: " . format_payroll_currency($total_wcf) . "\n";
                    $description .= "   - OSHA: " . format_payroll_currency($total_osha) . "\n\n";
                    
                    $description .= "TOTAL PAYMENT REQUIRED (TOTAL EMPLOYER COST): " . format_payroll_currency($total_employer_cost) . "\n\n";
                    $description .= "Payment Method: " . $payment_method . "\n";
                    $description .= "Notes: " . $notes;
                    
                    $stmt->execute([
                        $request_no,
                        $subject,
                        'SALARY',
                        'Various Payees',
                        'Company Bank',
                        'Main Branch',
                        'Company Account',
                        'COMPANY-001',
                        $total_employer_cost,
                        '',
                        $description,
                        $current_user_id
                    ]);
                    
                    $payment_id = $db->lastInsertId();
                    
                    // === POST TO GENERAL LEDGER ===
                    require_once '../includes/payroll_accounting.php';
                    
                    $gl_reference_no = $request_no;
                    $gl_entries = [];
                    
                    foreach ($salary_calculation['employees'] as $emp) {
                        // Prepare GL posting for each employee
                        $statutory_deductions = [
                            'nssf' => $emp['nssf_employee'] ?? 0,
                            'paye' => $emp['paye_tax'] ?? 0,
                            'health_insurance' => $emp['nhif'] ?? 0,
                        ];
                        
                        $statutory_employer = [
                            'nssf' => $emp['nssf_employer'] ?? 0,
                            'sdl' => $emp['sdl'] ?? 0,
                            'wcf' => $emp['wcf'] ?? 0,
                            'osha' => $emp['osha'] ?? 0,
                            'health_insurance' => 0,
                        ];
                        
                        $result = postPayrollToGL([
                            'reference_no' => $gl_reference_no,
                            'employee_name' => $emp['employee_name'],
                            'employee_id' => $emp['user_id'],
                            'gross_salary' => $emp['gross_salary'] ?? 0,
                            'deductions' => [
                                'loans' => $emp['loans'] ?? 0,
                                'other' => $emp['other_deductions'] ?? 0,
                            ],
                            'statutory_deductions' => $statutory_deductions,
                            'statutory_employer' => $statutory_employer,
                            'net_salary' => $emp['net_salary'] ?? 0,
                            'period_start' => $pay_period_month . '-01',
                            'period_end' => date('Y-m-t', strtotime($pay_period_month . '-01')),
                            'posted_by' => $current_user_id,
                        ]);
                        
                        if (!$result['success']) {
                            error_log('Payroll GL posting error for employee ' . $emp['employee_name'] . ': ' . ($result['error'] ?? 'unknown'));
                        }
                    }
                    
                    // Save calculation to salary_calculations table
                    try {
                        $calc_stmt = $db->prepare("
                            INSERT INTO salary_calculations (
                                payment_request_id, pay_period_month, calculation_data,
                                total_employees, total_gross, total_deductions, total_net,
                                total_employer_contributions, total_employer_cost, created_by
                            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                        ");
                        
                        $calc_stmt->execute([
                            $payment_id,
                            $pay_period_month,
                            json_encode($salary_calculation),
                            $salary_calculation['summary']['total_employees'],
                            $total_gross,
                            $salary_calculation['summary']['total_deductions'],
                            $net_salary,
                            $total_nssf_employer + $total_sdl + $total_wcf + $total_osha,
                            $total_employer_cost,
                            $current_user_id
                        ]);
                    } catch (Exception $e) {
                        error_log("salary_calculations table doesn't exist: " . $e->getMessage());
                    }
                    
                    $db->commit();
                    
                    // Clear session data
                    unset($_SESSION['salary_calculation']);
                    unset($_SESSION['pay_period_month']);
                    
                    $success_message = "Salary payment request created successfully!<br>
                                      <strong>Request No:</strong> {$request_no}<br>
                                      <strong>Total Employer Cost:</strong> " . format_payroll_currency($total_employer_cost) . "<br>
                                      <strong>Breakdown:</strong><br>
                                      - Gross Salary: " . format_payroll_currency($total_gross) . "<br>
                                      - Employee Deductions: " . format_payroll_currency($salary_calculation['summary']['total_deductions']) . "<br>
                                      - Net Salary: " . format_payroll_currency($net_salary) . "<br>
                                      - Employer Contributions: " . format_payroll_currency($total_nssf_employer + $total_sdl + $total_wcf + $total_osha) . "<br>
                                      <strong>Status:</strong> Sent to CEO for approval";
                    
                    redirect('hr/pay_salary.php?section=history');
                    
                } catch (Exception $e) {
                    $db->rollBack();
                    throw $e;
                }
            }
        }
        
        // === 3. CONFIGURE STATUTORY RATES ===
        elseif (isset($_POST['configure_statutory'])) {
            // Update rates in system_settings
            $rate_keys = [
                'nssf_employee_rate', 'nssf_employer_rate', 'sdl_rate', 
                'wcf_rate', 'osha_rate', 'overtime_rate_per_hour', 'default_nhif_rate'
            ];
            
            foreach ($rate_keys as $key) {
                if (isset($_POST[$key])) {
                    $value = (float)$_POST[$key];
                    // Update or insert
                    $stmt = $db->prepare("
                        INSERT INTO system_settings (setting_key, setting_value, updated_at) 
                        VALUES (?, ?, NOW()) 
                        ON DUPLICATE KEY UPDATE setting_value = ?, updated_at = NOW()
                    ");
                    $stmt->execute([$key, $value, $value]);
                }
            }
            
            // Handle NHIF enable
            $enable_nhif = isset($_POST['enable_nhif']) ? 1 : 0;
            $stmt = $db->prepare("
                INSERT INTO system_settings (setting_key, setting_value, updated_at) 
                VALUES ('enable_nhif', ?, NOW()) 
                ON DUPLICATE KEY UPDATE setting_value = ?, updated_at = NOW()
            ");
            $stmt->execute([$enable_nhif, $enable_nhif]);
            
            $success_message = 'Statutory rates configured successfully.';
            redirect('hr/pay_salary.php?section=statutory');
        }
        
        // === 4. SAVE PAYE BRACKET ===
        elseif (isset($_POST['save_paye_bracket'])) {
            $bracket_min = (float)$_POST['bracket_min'];
            $bracket_max = (float)$_POST['bracket_max'];
            $tax_rate = (float)$_POST['tax_rate'];
            $description = sanitize_input($_POST['description'] ?? '');
            
            $stmt = $db->prepare("
                INSERT INTO paye_tax_brackets (bracket_min, bracket_max, tax_rate, description, status)
                VALUES (?, ?, ?, ?, 'active')
            ");
            $stmt->execute([$bracket_min, $bracket_max, $tax_rate, $description]);
            
            $success_message = 'PAYE bracket added successfully.';
            redirect('hr/pay_salary.php?section=statutory');
        }
        
        // === 5. DELETE PAYE BRACKET ===
        elseif (isset($_POST['delete_paye_bracket'])) {
            $bracket_id = (int)$_POST['bracket_id'];
            
            $stmt = $db->prepare("UPDATE paye_tax_brackets SET status = 'inactive' WHERE id = ?");
            $stmt->execute([$bracket_id]);
            
            $success_message = 'PAYE bracket deleted successfully.';
            redirect('hr/pay_salary.php?section=statutory');
        }
        
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        $error_message = 'Error: ' . $e->getMessage();
    }
}

// ==================== GET DATA ====================

// Get system rates
$rates = get_system_rates();

// Get active employees
$active_employees = 0;
$total_basic_salary = 0;
try {
    $stmt = $db->query("
        SELECT COUNT(*) as count, SUM(salary) as total_salary 
        FROM users 
        WHERE status = 'active' 
        AND role NOT IN ('system_admin', 'ceo')
    ");
    $emp_data = $stmt->fetch();
    $active_employees = $emp_data['count'] ?? 0;
    $total_basic_salary = $emp_data['total_salary'] ?? 0;
} catch (Exception $e) {
    $active_employees = 0;
    $total_basic_salary = 0;
}

// Get PAYE brackets
$paye_brackets = [];
try {
    $stmt = $db->query("SELECT * FROM paye_tax_brackets WHERE status = 'active' ORDER BY bracket_min");
    $paye_brackets = $stmt->fetchAll();
} catch (Exception $e) {
    $paye_brackets = [];
}

// Get recent salary payments
$salary_payments = [];
try {
    $stmt = $db->query("
        SELECT pp.*, 
               u.full_name as requested_by_name,
               ceo_u.full_name as ceo_approved_by_name,
               finance_u.full_name as finance_approved_by_name,
               paid_u.full_name as paid_by_name
        FROM pending_pay pp
        LEFT JOIN users u ON pp.requested_by = u.id
        LEFT JOIN users ceo_u ON pp.ceo_approved_by = ceo_u.id
        LEFT JOIN users finance_u ON pp.finance_approved_by = finance_u.id
        LEFT JOIN users paid_u ON pp.paid_by = paid_u.id
        WHERE pp.subject LIKE 'Salary Payment%'
        AND pp.payee_id = 'SALARY'
        ORDER BY pp.requested_at DESC
        LIMIT 10
    ");
    $salary_payments = $stmt->fetchAll();
} catch (Exception $e) {
    $salary_payments = [];
}

$salary_calculation = $_SESSION['salary_calculation'] ?? null;
$pay_period_month = $_SESSION['pay_period_month'] ?? date('Y-m');
$section = $_GET['section'] ?? 'overview';

ob_start();
include '../includes/header.php';
?>

<div class="container-fluid pt-4 px-4">
    <div class="d-sm-flex align-items-center justify-content-between mb-4">
        <h1 class="h3 mb-0 text-gray-800">
            <i class="bi bi-cash-coin me-2"></i>Salary Payment System
            <span class="badge bg-primary">HR Manager</span>
        </h1>
        <div class="d-flex gap-2">
            <button class="btn btn-primary" onclick="window.location.href='?section=statutory'">
                <i class="bi bi-gear me-2"></i>Configure Rates
            </button>
            <button class="btn btn-warning" data-bs-toggle="modal" data-bs-target="#calculateSalariesModal">
                <i class="bi bi-calculator me-2"></i>Calculate Salaries
            </button>
            <?php if ($salary_calculation): ?>
                <button class="btn btn-danger" onclick="window.location.href='?section=payment'">
                    <i class="bi bi-send-check me-2"></i>Generate Payment
                </button>
            <?php endif; ?>
        </div>
    </div>

    <?php if (!empty($error_message)): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <i class="bi bi-exclamation-triangle me-2"></i><?php echo $error_message; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if (!empty($success_message)): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <i class="bi bi-check-circle me-2"></i><?php echo $success_message; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Navigation Tabs -->
    <ul class="nav nav-tabs mb-4">
        <li class="nav-item">
            <a class="nav-link <?php echo $section === 'overview' ? 'active' : ''; ?>" href="?section=overview">
                <i class="bi bi-house-door me-1"></i>Overview
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?php echo $section === 'statutory' ? 'active' : ''; ?>" href="?section=statutory">
                <i class="bi bi-gear me-1"></i>Statutory Setup
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?php echo $section === 'payment' ? 'active' : ''; ?>" href="?section=payment">
                <i class="bi bi-cash-stack me-1"></i>Generate Payment
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?php echo $section === 'history' ? 'active' : ''; ?>" href="?section=history">
                <i class="bi bi-clock-history me-1"></i>Payment History
            </a>
        </li>
    </ul>

    <!-- Overview Section -->
    <?php if ($section === 'overview'): ?>
        <div class="row">
            <div class="col-12">
                <div class="card shadow mb-4">
                    <div class="card-header bg-primary">
                        <h5 class="mb-0">
                            <i class="bi bi-table me-2"></i>
                            Complete Salary Structure (Excel Matching)
                            <small class="float-end">Tanzanian Labor Laws</small>
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-bordered table-sm">
                                <thead class="table-light">
                                    <tr>
                                        <th>Category</th>
                                        <th>Item</th>
                                        <th>% / Basis</th>
                                        <th>GL Account</th>
                                        <th>Affects Net</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <!-- EARNINGS -->
                                    <tr class="table-success">
                                        <td rowspan="5" class="align-middle fw-bold">EARNINGS</td>
                                        <td>Basic Salary</td>
                                        <td>Fixed</td>
                                        <td>511 - Salaries & Wages</td>
                                        <td><i class="bi bi-check-circle-fill text-success"></i></td>
                                    </tr>
                                    <tr class="table-success">
                                        <td>House Allowance</td>
                                        <td>Fixed</td>
                                        <td>511 - Salaries & Wages</td>
                                        <td><i class="bi bi-check-circle-fill text-success"></i></td>
                                    </tr>
                                    <tr class="table-success">
                                        <td>Other Allowances</td>
                                        <td>Fixed</td>
                                        <td>511 - Salaries & Wages</td>
                                        <td><i class="bi bi-check-circle-fill text-success"></i></td>
                                    </tr>
                                    <tr class="table-success">
                                        <td>Overtime</td>
                                        <td><?php echo format_payroll_currency($rates['overtime_rate_per_hour']); ?>/hr</td>
                                        <td>511 - Salaries & Wages</td>
                                        <td><i class="bi bi-check-circle-fill text-success"></i></td>
                                    </tr>
                                    <tr class="table-success fw-bold">
                                        <td class="text-primary">GROSS SALARY</td>
                                        <td>Sum of all earnings</td>
                                        <td>511 - Salaries & Wages</td>
                                        <td><i class="bi bi-check-circle-fill text-success"></i></td>
                                    </tr>
                                    
                                    <!-- EMPLOYEE DEDUCTIONS -->
                                    <tr class="table-danger">
                                        <td rowspan="6" class="align-middle fw-bold">EMPLOYEE DEDUCTIONS</td>
                                        <td>NSSF (Employee)</td>
                                        <td><?php echo $rates['nssf_employee_rate']; ?>% of Gross</td>
                                        <td>2121 - NSSF Payable</td>
                                        <td><i class="bi bi-check-circle-fill text-success"></i></td>
                                    </tr>
                                    <tr class="table-danger">
                                        <td>PAYE (Income Tax)</td>
                                        <td>0% – 30% (Gross - NSSF)</td>
                                        <td>2126 - PAYE Payable</td>
                                        <td><i class="bi bi-check-circle-fill text-success"></i></td>
                                    </tr>
                                    <tr class="table-danger">
                                        <td>NHIF</td>
                                        <td>Variable (per employee)</td>
                                        <td>2125 - Health Insurance Payable</td>
                                        <td><i class="bi bi-check-circle-fill text-success"></i></td>
                                    </tr>
                                    <tr class="table-danger">
                                        <td>Loans</td>
                                        <td>Fixed</td>
                                        <td>Various</td>
                                        <td><i class="bi bi-check-circle-fill text-success"></i></td>
                                    </tr>
                                    <tr class="table-danger">
                                        <td>Other Deductions</td>
                                        <td>Fixed</td>
                                        <td>Various</td>
                                        <td><i class="bi bi-check-circle-fill text-success"></i></td>
                                    </tr>
                                    <tr class="table-danger fw-bold">
                                        <td class="text-danger">TOTAL DEDUCTIONS</td>
                                        <td>Sum of all deductions</td>
                                        <td>-</td>
                                        <td><i class="bi bi-check-circle-fill text-success"></i></td>
                                    </tr>
                                    
                                    <!-- NET PAY -->
                                    <tr class="table-primary fw-bold">
                                        <td>NET PAY</td>
                                        <td>Gross - Deductions</td>
                                        <td>212 - Accrued Expenses</td>
                                        <td class="text-success"><strong>Final Pay</strong></td>
                                    </tr>
                                    
                                    <!-- EMPLOYER CONTRIBUTIONS -->
                                    <tr class="table-warning">
                                        <td rowspan="5" class="align-middle fw-bold">EMPLOYER CONTRIBUTIONS</td>
                                        <td>NSSF (Employer)</td>
                                        <td><?php echo $rates['nssf_employer_rate']; ?>% of Gross</td>
                                        <td>5121 - NSSF Employer</td>
                                        <td><i class="bi bi-x-circle-fill text-danger"></i></td>
                                    </tr>
                                    <tr class="table-warning">
                                        <td>SDL</td>
                                        <td><?php echo $rates['sdl_rate']; ?>% of Gross</td>
                                        <td>5122 - SDL Expense</td>
                                        <td><i class="bi bi-x-circle-fill text-danger"></i></td>
                                    </tr>
                                    <tr class="table-warning">
                                        <td>WCF</td>
                                        <td><?php echo $rates['wcf_rate']; ?>% of Gross</td>
                                        <td>5123 - WCF Expense</td>
                                        <td><i class="bi bi-x-circle-fill text-danger"></i></td>
                                    </tr>
                                    <tr class="table-warning">
                                        <td>OSHA</td>
                                        <td><?php echo $rates['osha_rate']; ?>% of Gross</td>
                                        <td>5124 - OSHA Expense</td>
                                        <td><i class="bi bi-x-circle-fill text-danger"></i></td>
                                    </tr>
                                    <tr class="table-warning fw-bold">
                                        <td class="text-warning">TOTAL EMPLOYER COST</td>
                                        <td>Gross + Employer Costs</td>
                                        <td>-</td>
                                        <td><i class="bi bi-x-circle-fill text-danger"></i></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        
                        <!-- Quick Stats -->
                        <div class="row mt-4">
                            <div class="col-md-3">
                                <div class="card border-start-success shadow h-100">
                                    <div class="card-body">
                                        <div class="d-flex align-items-center">
                                            <div class="me-3"><i class="bi bi-people fs-1 text-success"></i></div>
                                            <div>
                                                <div class="text-muted">Active Employees</div>
                                                <div class="fs-4 fw-bold"><?php echo $active_employees; ?></div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="card border-start-primary shadow h-100">
                                    <div class="card-body">
                                        <div class="d-flex align-items-center">
                                            <div class="me-3"><i class="bi bi-cash-stack fs-1 text-primary"></i></div>
                                            <div>
                                                <div class="text-muted">Total Basic Salary</div>
                                                <div class="fs-4 fw-bold"><?php echo format_payroll_currency($total_basic_salary); ?></div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="card border-start-warning shadow h-100">
                                    <div class="card-body">
                                        <div class="d-flex align-items-center">
                                            <div class="me-3"><i class="bi bi-clock-history fs-1 text-warning"></i></div>
                                            <div>
                                                <div class="text-muted">PAYE Basis</div>
                                                <div class="fs-6 fw-bold">Gross - NSSF (Excel Match)</div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="card border-start-info shadow h-100">
                                    <div class="card-body">
                                        <div class="d-flex align-items-center">
                                            <div class="me-3"><i class="bi bi-building fs-1 text-info"></i></div>
                                            <div>
                                                <div class="text-muted">NSSF Rate</div>
                                                <div class="fs-4 fw-bold"><?php echo $rates['nssf_employee_rate']; ?>% / <?php echo $rates['nssf_employer_rate']; ?>%</div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    
    <!-- Statutory Setup Section -->
    <?php elseif ($section === 'statutory'): ?>
        <div class="row">
            <div class="col-md-8">
                <div class="card shadow mb-4">
                    <div class="card-header bg-primary">
                        <h5 class="mb-0"><i class="bi bi-gear me-2"></i>Configure Statutory Rates</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST">
                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <label class="form-label">NSSF Employee Rate (%)</label>
                                    <input type="number" class="form-control" name="nssf_employee_rate" 
                                           step="0.1" min="0" max="100" value="<?php echo $rates['nssf_employee_rate']; ?>" required>
                                    <small class="text-muted">% of Gross Salary (Excel: 10%)</small>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">NSSF Employer Rate (%)</label>
                                    <input type="number" class="form-control" name="nssf_employer_rate" 
                                           step="0.1" min="0" max="100" value="<?php echo $rates['nssf_employer_rate']; ?>" required>
                                    <small class="text-muted">% of Gross Salary (Excel: 10%)</small>
                                </div>
                            </div>
                            
                            <div class="row mb-3">
                                <div class="col-md-4">
                                    <label class="form-label">SDL Rate (%)</label>
                                    <input type="number" class="form-control" name="sdl_rate" 
                                           step="0.1" min="0" max="100" value="<?php echo $rates['sdl_rate']; ?>" required>
                                    <small class="text-muted">Skills Development Levy (3.5%)</small>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">WCF Rate (%)</label>
                                    <input type="number" class="form-control" name="wcf_rate" 
                                           step="0.01" min="0" max="100" value="<?php echo $rates['wcf_rate']; ?>" required>
                                    <small class="text-muted">Workers Compensation Fund (0.5%)</small>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">OSHA Rate (%)</label>
                                    <input type="number" class="form-control" name="osha_rate" 
                                           step="0.01" min="0" max="100" value="<?php echo $rates['osha_rate']; ?>" required>
                                    <small class="text-muted">Occupational Safety & Health (0.5%)</small>
                                </div>
                            </div>
                            
                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <label class="form-label">Overtime Rate per Hour (TZS)</label>
                                    <input type="number" class="form-control" name="overtime_rate_per_hour" 
                                           step="100" min="0" value="<?php echo $rates['overtime_rate_per_hour']; ?>" required>
                                    <small class="text-muted">Per hour overtime payment</small>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-check form-switch mt-4">
                                        <input class="form-check-input" type="checkbox" name="enable_nhif" 
                                               id="enable_nhif" value="1" <?php echo $rates['enable_nhif'] ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="enable_nhif">Enable NHIF Deduction</label>
                                    </div>
                                    <div id="nhifSettings" class="mt-2" style="<?php echo $rates['enable_nhif'] ? '' : 'display: none;'; ?>">
                                        <label class="form-label">Default NHIF Rate (%)</label>
                                        <input type="number" class="form-control" name="default_nhif_rate" 
                                               step="0.1" min="0" max="100" value="<?php echo $rates['default_nhif_rate']; ?>">
                                        <small class="text-muted">Default rate if employee has no specific rate</small>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="alert alert-warning">
                                <i class="bi bi-exclamation-triangle me-2"></i>
                                <strong>Excel Match:</strong> NSSF = 10% of Gross, PAYE = Tax on (Gross - NSSF)
                            </div>
                            
                            <div class="text-end">
                                <button type="submit" name="configure_statutory" class="btn btn-primary">
                                    <i class="bi bi-save me-2"></i>Save Configuration
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
            
            <div class="col-md-4">
                <div class="card shadow">
                    <div class="card-header bg-info">
                        <h5 class="mb-0"><i class="bi bi-percent me-2"></i>PAYE Tax Brackets</h5>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($paye_brackets)): ?>
                            <div class="table-responsive">
                                <table class="table table-sm table-bordered">
                                    <thead>
                                        <tr><th>Min</th><th>Max</th><th>Rate</th><th>Action</th></tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($paye_brackets as $bracket): ?>
                                            <tr>
                                                <td><?php echo format_payroll_currency($bracket['bracket_min']); ?></td>
                                                <td><?php echo $bracket['bracket_max'] > 0 ? format_payroll_currency($bracket['bracket_max']) : 'Above'; ?></td>
                                                <td><?php echo $bracket['tax_rate']; ?>%</td>
                                                <td>
                                                    <form method="POST" class="d-inline">
                                                        <input type="hidden" name="bracket_id" value="<?php echo $bracket['id']; ?>">
                                                        <button type="submit" name="delete_paye_bracket" 
                                                                class="btn btn-sm btn-danger" 
                                                                onclick="return confirm('Delete this bracket?')">
                                                            <i class="bi bi-trash"></i>
                                                        </button>
                                                    </form>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <p class="text-muted">Default brackets:</p>
                            <ul class="small">
                                <li>0 - 270,000: 0%</li>
                                <li>270,001 - 520,000: 8%</li>
                                <li>520,001 - 760,000: 20%</li>
                                <li>760,001 - 1,000,000: 25%</li>
                                <li>Above 1,000,000: 30%</li>
                            </ul>
                        <?php endif; ?>
                        
                        <button type="button" class="btn btn-sm btn-primary w-100 mt-2" data-bs-toggle="modal" data-bs-target="#addPayeBracketModal">
                            <i class="bi bi-plus-circle me-1"></i>Add Tax Bracket
                        </button>
                    </div>
                </div>
            </div>
        </div>
    
    <!-- Generate Payment Section -->
    <?php elseif ($section === 'payment'): ?>
        <?php if ($salary_calculation): ?>
            <div class="row">
                <div class="col-12">
                    <div class="card shadow mb-4">
                        <div class="card-header bg-danger">
                            <h5 class="mb-0">
                                <i class="bi bi-calculator me-2"></i>
                                Salary Calculation - <?php echo date('F Y', strtotime($pay_period_month . '-01')); ?>
                                <span class="float-end">
                                    <a href="?export=excel" class="btn btn-sm btn-success">
                                        <i class="bi bi-file-earmark-excel me-1"></i>Export to Excel
                                    </a>
                                </span>
                            </h5>
                        </div>
                        <div class="card-body">
                            <!-- Summary -->
                            <div class="alert alert-info mb-4">
                                <div class="row">
                                    <div class="col-md-3">
                                        <strong>Employees:</strong> <?php echo $salary_calculation['summary']['total_employees']; ?>
                                    </div>
                                    <div class="col-md-3">
                                        <strong>Gross Salary:</strong> <?php echo format_payroll_currency($salary_calculation['summary']['total_gross_salary']); ?>
                                    </div>
                                    <div class="col-md-3">
                                        <strong>Total Deductions:</strong> <?php echo format_payroll_currency($salary_calculation['summary']['total_deductions']); ?>
                                    </div>
                                    <div class="col-md-3">
                                        <strong>Net Pay:</strong> <span class="fw-bold text-success"><?php echo format_payroll_currency($salary_calculation['summary']['total_net_salary']); ?></span>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Employee Deduction Summary -->
                            <div class="alert alert-danger mb-4">
                                <div class="row">
                                    <div class="col-md-3">
                                        <strong>NSSF Employee:</strong> <?php echo format_payroll_currency($salary_calculation['summary']['total_nssf_employee']); ?>
                                    </div>
                                    <div class="col-md-3">
                                        <strong>PAYE:</strong> <?php echo format_payroll_currency($salary_calculation['summary']['total_paye_tax']); ?>
                                    </div>
                                    <div class="col-md-3">
                                        <strong>NHIF:</strong> <?php echo format_payroll_currency($salary_calculation['summary']['total_nhif']); ?>
                                    </div>
                                    <div class="col-md-3">
                                        <strong>Loans:</strong> <?php echo format_payroll_currency($salary_calculation['summary']['total_loans']); ?>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Employer Contributions -->
                            <div class="alert alert-warning mb-4">
                                <div class="row">
                                    <div class="col-md-3">
                                        <strong>NSSF Employer:</strong> <?php echo format_payroll_currency($salary_calculation['summary']['total_nssf_employer']); ?>
                                    </div>
                                    <div class="col-md-3">
                                        <strong>SDL:</strong> <?php echo format_payroll_currency($salary_calculation['summary']['total_sdl']); ?>
                                    </div>
                                    <div class="col-md-3">
                                        <strong>WCF:</strong> <?php echo format_payroll_currency($salary_calculation['summary']['total_wcf']); ?>
                                    </div>
                                    <div class="col-md-3">
                                        <strong>OSHA:</strong> <?php echo format_payroll_currency($salary_calculation['summary']['total_osha']); ?>
                                    </div>
                                </div>
                                <div class="mt-2">
                                    <strong>Total Employer Cost (Payment Required):</strong> 
                                    <span class="fw-bold text-danger"><?php echo format_payroll_currency($salary_calculation['summary']['total_employer_cost']); ?></span>
                                </div>
                            </div>
                            
                            <!-- Employee Details Table -->
                            <?php if (isset($salary_calculation['employees']) && !empty($salary_calculation['employees'])): ?>
                            <div class="table-responsive mb-4">
                                <table class="table table-bordered table-sm table-striped">
                                    <thead class="table-dark">
                                        <tr>
                                            <th>Employee</th>
                                            <th>Basic</th>
                                            <th>House Allow</th>
                                            <th>Other</th>
                                            <th>O/T</th>
                                            <th>Bonus</th>
                                            <th>Gross</th>
                                            <th>NSSF</th>
                                            <th>PAYE</th>
                                            <th>NHIF</th>
                                            <th>Loans</th>
                                            <th>Other</th>
                                            <th>Total Ded</th>
                                            <th>Net Pay</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($salary_calculation['employees'] as $emp): ?>
                                            <tr>
                                                <td><small><?php echo htmlspecialchars($emp['employee_name']); ?></small></td>
                                                <td class="text-end"><?php echo format_payroll_currency($emp['basic_salary']); ?></td>
                                                <td class="text-end"><?php echo format_payroll_currency($emp['house_allowance'] ?? 0); ?></td>
                                                <td class="text-end"><?php echo format_payroll_currency($emp['allowances'] ?? 0); ?></td>
                                                <td class="text-end"><?php echo format_payroll_currency($emp['overtime'] ?? 0); ?></td>
                                                <td class="text-end"><?php echo format_payroll_currency($emp['bonuses'] ?? 0); ?></td>
                                                <td class="text-end fw-bold"><?php echo format_payroll_currency($emp['gross_salary']); ?></td>
                                                <td class="text-end text-danger"><?php echo format_payroll_currency($emp['nssf_employee']); ?></td>
                                                <td class="text-end text-danger"><?php echo format_payroll_currency($emp['paye_tax']); ?></td>
                                                <td class="text-end text-danger"><?php echo format_payroll_currency($emp['nhif']); ?></td>
                                                <td class="text-end text-danger"><?php echo format_payroll_currency($emp['loans'] ?? 0); ?></td>
                                                <td class="text-end text-danger"><?php echo format_payroll_currency($emp['other_deductions']); ?></td>
                                                <td class="text-end text-danger fw-bold"><?php echo format_payroll_currency($emp['total_deductions']); ?></td>
                                                <td class="text-end text-success fw-bold"><?php echo format_payroll_currency($emp['net_salary']); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                    <tfoot class="table-secondary fw-bold">
                                        <tr>
                                            <td>TOTALS</td>
                                            <td class="text-end"><?php echo format_payroll_currency($salary_calculation['summary']['total_basic_salary']); ?></td>
                                            <td class="text-end"><?php echo format_payroll_currency($salary_calculation['summary']['total_house_allowance']); ?></td>
                                            <td class="text-end"><?php echo format_payroll_currency($salary_calculation['summary']['total_allowances']); ?></td>
                                            <td class="text-end"><?php echo format_payroll_currency($salary_calculation['summary']['total_overtime']); ?></td>
                                            <td class="text-end"><?php echo format_payroll_currency($salary_calculation['summary']['total_bonuses']); ?></td>
                                            <td class="text-end"><?php echo format_payroll_currency($salary_calculation['summary']['total_gross_salary']); ?></td>
                                            <td class="text-end"><?php echo format_payroll_currency($salary_calculation['summary']['total_nssf_employee']); ?></td>
                                            <td class="text-end"><?php echo format_payroll_currency($salary_calculation['summary']['total_paye_tax']); ?></td>
                                            <td class="text-end"><?php echo format_payroll_currency($salary_calculation['summary']['total_nhif']); ?></td>
                                            <td class="text-end"><?php echo format_payroll_currency($salary_calculation['summary']['total_loans']); ?></td>
                                            <td class="text-end"><?php echo format_payroll_currency($salary_calculation['summary']['total_other_deductions']); ?></td>
                                            <td class="text-end"><?php echo format_payroll_currency($salary_calculation['summary']['total_deductions']); ?></td>
                                            <td class="text-end text-success"><?php echo format_payroll_currency($salary_calculation['summary']['total_net_salary']); ?></td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                            <?php endif; ?>
                            
                            <!-- Payment Request Form -->
                            <div class="card border-primary">
                                <div class="card-header bg-primary">
                                    <h6 class="mb-0"><i class="bi bi-send-check me-2"></i>Generate Payment Request</h6>
                                </div>
                                <div class="card-body">
                                    <form method="POST">
                                        <div class="row mb-3">
                                            <div class="col-md-6">
                                                <label class="form-label">Payment Date</label>
                                                <input type="date" class="form-control" name="payment_date" 
                                                       value="<?php echo date('Y-m-d'); ?>" required>
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label">Payment Method</label>
                                                <select class="form-select" name="payment_method" required>
                                                    <option value="bank_transfer">Bank Transfer</option>
                                                    <option value="mobile_money">Mobile Money</option>
                                                    <option value="cash">Cash</option>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label">Notes (for approval)</label>
                                            <textarea class="form-control" name="notes" rows="3"></textarea>
                                        </div>
                                        
                                        <!-- GL Posting Summary -->
                                        <div class="alert alert-info">
                                            <h6><i class="bi bi-journal me-2"></i>General Ledger Posting</h6>
                                            <div class="row">
                                                <div class="col-md-6">
                                                    <strong>Expense Accounts (DR):</strong><br>
                                                    <span class="small">511 - Salaries & Wages: <?php echo format_payroll_currency($salary_calculation['summary']['total_gross_salary']); ?></span><br>
                                                    <span class="small">5121 - NSSF Employer: <?php echo format_payroll_currency($salary_calculation['summary']['total_nssf_employer']); ?></span><br>
                                                    <span class="small">5122 - SDL Expense: <?php echo format_payroll_currency($salary_calculation['summary']['total_sdl']); ?></span><br>
                                                    <span class="small">5123 - WCF Expense: <?php echo format_payroll_currency($salary_calculation['summary']['total_wcf']); ?></span><br>
                                                    <span class="small">5124 - OSHA Expense: <?php echo format_payroll_currency($salary_calculation['summary']['total_osha']); ?></span>
                                                </div>
                                                <div class="col-md-6">
                                                    <strong>Liability Accounts (CR):</strong><br>
                                                    <span class="small">2121 - NSSF Payable: <?php echo format_payroll_currency($salary_calculation['summary']['total_nssf_employee'] + $salary_calculation['summary']['total_nssf_employer']); ?></span><br>
                                                    <span class="small">2126 - PAYE Payable: <?php echo format_payroll_currency($salary_calculation['summary']['total_paye_tax']); ?></span><br>
                                                    <span class="small">2125 - Health Insurance: <?php echo format_payroll_currency($salary_calculation['summary']['total_nhif']); ?></span><br>
                                                    <span class="small">2122 - SDL Payable: <?php echo format_payroll_currency($salary_calculation['summary']['total_sdl']); ?></span><br>
                                                    <span class="small">2123 - WCF Payable: <?php echo format_payroll_currency($salary_calculation['summary']['total_wcf']); ?></span><br>
                                                    <span class="small">2124 - OSHA Payable: <?php echo format_payroll_currency($salary_calculation['summary']['total_osha']); ?></span><br>
                                                    <span class="small">212 - Accrued Expenses: <?php echo format_payroll_currency($salary_calculation['summary']['total_net_salary']); ?></span>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <div class="alert alert-danger">
                                            <i class="bi bi-exclamation-triangle me-2"></i>
                                            <strong>PAYMENT REQUEST SUMMARY:</strong><br>
                                            • <strong>Net Salary to Employees:</strong> <?php echo format_payroll_currency($salary_calculation['summary']['total_net_salary']); ?><br>
                                            • <strong>Employer Contributions:</strong> <?php echo format_payroll_currency(
                                                $salary_calculation['summary']['total_nssf_employer'] + 
                                                $salary_calculation['summary']['total_sdl'] + 
                                                $salary_calculation['summary']['total_wcf'] + 
                                                $salary_calculation['summary']['total_osha']
                                            ); ?><br>
                                            • <strong>TOTAL EMPLOYER COST:</strong> 
                                            <span class="fw-bold text-danger"><?php echo format_payroll_currency($salary_calculation['summary']['total_employer_cost']); ?></span>
                                        </div>
                                        
                                        <div class="text-end">
                                            <button type="submit" name="generate_payment_request" class="btn btn-primary">
                                                <i class="bi bi-send-check me-2"></i>Generate Payment Request
                                            </button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        <?php else: ?>
            <div class="text-center py-5">
                <i class="bi bi-calculator display-4 text-muted mb-3"></i>
                <h5>No Salary Calculation Found</h5>
                <p class="text-muted">Calculate salaries first to generate payment request.</p>
                <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#calculateSalariesModal">
                    <i class="bi bi-calculator me-2"></i>Calculate Salaries
                </button>
            </div>
        <?php endif; ?>
    
    <!-- Payment History Section -->
    <?php elseif ($section === 'history'): ?>
        <div class="card shadow">
            <div class="card-header bg-info">
                <h5 class="mb-0"><i class="bi bi-clock-history me-2"></i>Salary Payment History</h5>
            </div>
            <div class="card-body">
                <?php if (!empty($salary_payments)): ?>
                    <div class="table-responsive">
                        <table class="table table-bordered table-hover">
                            <thead class="table-info">
                                <tr>
                                    <th>Request No</th>
                                    <th>Date</th>
                                    <th>Period</th>
                                    <th>Amount</th>
                                    <th>Status</th>
                                    <th>Approval</th>
                                    <th>Requested By</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($salary_payments as $payment): 
                                    $progress = 0;
                                    $progress_class = '';
                                    if ($payment['status'] == 'pending') { $progress = 0; $progress_class = 'bg-warning'; }
                                    elseif ($payment['status'] == 'approved_ceo') { $progress = 50; $progress_class = 'bg-info'; }
                                    elseif ($payment['status'] == 'approved_finance') { $progress = 75; $progress_class = 'bg-success'; }
                                    elseif ($payment['status'] == 'paid') { $progress = 100; $progress_class = 'bg-primary'; }
                                    elseif ($payment['status'] == 'rejected') { $progress = 0; $progress_class = 'bg-danger'; }
                                ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($payment['request_no']); ?></strong></td>
                                        <td><?php echo date('d/m/Y', strtotime($payment['requested_at'])); ?></td>
                                        <td><?php 
                                            if (preg_match('/Salary Payment - (\w+ \d{4})/', $payment['subject'], $matches)) {
                                                echo $matches[1];
                                            }
                                        ?></td>
                                        <td class="text-end"><strong class="text-success"><?php echo format_payroll_currency($payment['amount_paid']); ?></strong></td>
                                        <td>
                                            <span class="badge bg-<?php 
                                                echo $payment['status'] === 'pending' ? 'warning' : 
                                                     ($payment['status'] === 'approved_ceo' ? 'info' : 
                                                     ($payment['status'] === 'approved_finance' ? 'success' : 
                                                     ($payment['status'] === 'paid' ? 'primary' : 'danger'))); 
                                            ?>">
                                                <?php echo ucfirst(str_replace('_', ' ', $payment['status'])); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="progress" style="height: 20px;">
                                                <div class="progress-bar progress-bar-striped <?php echo $progress_class; ?>" 
                                                     style="width: <?php echo $progress; ?>%;">
                                                    <?php echo $progress; ?>%
                                                </div>
                                            </div>
                                        </td>
                                        <td><?php echo htmlspecialchars($payment['requested_by_name']); ?></td>
                                        <td>
                                            <a href="../hr/payment_request.php?request_id=<?php echo $payment['id']; ?>" 
                                               class="btn btn-sm btn-outline-primary">
                                                <i class="bi bi-eye"></i>
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="text-center py-5">
                        <i class="bi bi-inbox display-4 text-muted mb-3"></i>
                        <h5>No Salary Payments</h5>
                        <p class="text-muted">No salary payment requests have been generated yet.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</div>

<!-- Calculate Salaries Modal -->
<div class="modal fade" id="calculateSalariesModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header bg-primary">
                    <h5 class="modal-title"><i class="bi bi-calculator me-2"></i>Calculate Salaries</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Pay Period Month <span class="text-danger">*</span></label>
                        <input type="month" name="pay_period_month" class="form-control" 
                               value="<?php echo date('Y-m'); ?>" max="<?php echo date('Y-m'); ?>" required>
                    </div>
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle me-2"></i>
                        <strong>Calculation Method (Excel Match):</strong>
                        <ul class="mb-0 mt-2">
                            <li><strong>Gross =</strong> Basic + House Allowance + Allowances + Overtime + Bonuses</li>
                            <li><strong>NSSF =</strong> 10% of Gross (Employee & Employer)</li>
                            <li><strong>PAYE =</strong> Tax on (Gross - NSSF) using progressive brackets</li>
                            <li><strong>NHIF =</strong> Variable rate per employee</li>
                            <li><strong>SDL</strong> = 3.5% of Gross</li>
                            <li><strong>WCF</strong> = 0.5% of Gross</li>
                            <li><strong>OSHA</strong> = 0.5% of Gross</li>
                        </ul>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="calculate_salaries" class="btn btn-primary">
                        <i class="bi bi-calculator me-2"></i>Calculate Now
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Add PAYE Bracket Modal -->
<div class="modal fade" id="addPayeBracketModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header bg-primary">
                    <h5 class="modal-title"><i class="bi bi-percent me-2"></i>Add PAYE Tax Bracket</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Minimum (TZS) <span class="text-danger">*</span></label>
                            <input type="number" class="form-control" name="bracket_min" step="0.01" min="0" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Maximum (TZS)</label>
                            <input type="number" class="form-control" name="bracket_max" step="0.01" min="0">
                            <small class="text-muted">0 = unlimited</small>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Tax Rate (%) <span class="text-danger">*</span></label>
                        <input type="number" class="form-control" name="tax_rate" step="0.1" min="0" max="100" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <input type="text" class="form-control" name="description" placeholder="e.g., First bracket">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="save_paye_bracket" class="btn btn-primary">
                        <i class="bi bi-save me-2"></i>Save Bracket
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const enableNHIF = document.getElementById('enable_nhif');
    const nhifSettings = document.getElementById('nhifSettings');
    
    if (enableNHIF && nhifSettings) {
        enableNHIF.addEventListener('change', function() {
            nhifSettings.style.display = this.checked ? 'block' : 'none';
        });
    }
});
</script>

<?php
include '../includes/footer.php';
$db = null;
?>
