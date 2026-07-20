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

function calculate_paye_tax($taxable_income) {
    global $db;
    
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
            $bracket_range = $bracket['max'] - $bracket['min'] + 1;
            $bracket_amount = min($remaining_income, $bracket_range);
        }
        
        $tax += ($bracket_amount * $bracket['rate'] / 100);
        $remaining_income -= $bracket_amount;
    }
    
    return $tax;
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
        // Fallback if query fails
        return $prefix . $year . $month . '0001';
    }
}

function get_system_rates() {
    global $db;
    
    $rates = [];
    try {
        $stmt = $db->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key LIKE '%_rate' OR setting_key = 'enable_nhif'");
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
        'nhif_rate' => 3,
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
    
    // Set headers for Excel file download
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="salary_calculation_' . date('Y-m-d') . '.xls"');
    
    // Create Excel content
    echo "<html>";
    echo "<head>";
    echo "<meta charset=\"UTF-8\">";
    echo "<style>";
    echo "td { border: 1px solid #000; padding: 5px; }";
    echo "th { border: 1px solid #000; padding: 5px; background-color: #f2f2f2; }";
    echo ".total { font-weight: bold; background-color: #e6f3ff; }";
    echo ".subtotal { font-weight: bold; background-color: #f0f0f0; }";
    echo "</style>";
    echo "</head>";
    echo "<body>";
    
    echo "<h2>Salary Calculation - " . date('F Y', strtotime($salary_calculation['pay_period_month'] . '-01')) . "</h2>";
    echo "<p>Generated on: " . date('Y-m-d H:i:s') . "</p>";
    
    // Summary Section
    echo "<h3>Summary</h3>";
    echo "<table border='1'>";
    echo "<tr>";
    echo "<th>Total Employees</th>";
    echo "<th>Total Basic Salary</th>";
    echo "<th>Total Gross Salary</th>";
    echo "<th>Total Deductions</th>";
    echo "<th>Total Net Salary</th>";
    echo "<th>Total Employer Cost</th>";
    echo "</tr>";
    echo "<tr class='total'>";
    echo "<td>" . ($salary_calculation['summary']['total_employees'] ?? 0) . "</td>";
    echo "<td>" . format_payroll_currency($salary_calculation['summary']['total_basic_salary'] ?? 0, false) . "</td>";
    echo "<td>" . format_payroll_currency($salary_calculation['summary']['total_gross_salary'] ?? 0, false) . "</td>";
    echo "<td>" . format_payroll_currency($salary_calculation['summary']['total_deductions'] ?? 0, false) . "</td>";
    echo "<td>" . format_payroll_currency($salary_calculation['summary']['total_net_salary'] ?? 0, false) . "</td>";
    echo "<td>" . format_payroll_currency($salary_calculation['summary']['total_employer_cost'] ?? 0, false) . "</td>";
    echo "</tr>";
    echo "</table>";
    
    echo "<br>";
    
    // Employer Contributions Breakdown
    echo "<h3>Employer Contributions Breakdown</h3>";
    echo "<table border='1'>";
    echo "<tr>";
    echo "<th>NSSF Employer</th>";
    echo "<th>SDL</th>";
    echo "<th>WCF</th>";
    echo "<th>OSHA</th>";
    echo "<th>Total Employer Contributions</th>";
    echo "</tr>";
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
    echo "</tr>";
    echo "</table>";
    
    echo "<br>";
    
    // Employee Details
    echo "<h3>Employee Details</h3>";
    echo "<table border='1'>";
    echo "<tr>";
    echo "<th>No</th>";
    echo "<th>Employee Name</th>";
    echo "<th>Job Title</th>";
    echo "<th>Basic Salary</th>";
    echo "<th>Allowances</th>";
    echo "<th>Overtime</th>";
    echo "<th>Bonuses</th>";
    echo "<th>Gross Salary</th>";
    echo "<th>NSSF Employee</th>";
    echo "<th>PAYE Tax</th>";
    echo "<th>NHIF</th>";
    echo "<th>Other Deductions</th>";
    echo "<th>Total Deductions</th>";
    echo "<th>Net Salary</th>";
    echo "<th>NSSF Employer</th>";
    echo "<th>SDL</th>";
    echo "<th>WCF</th>";
    echo "<th>OSHA</th>";
    echo "<th>Total Employer Cost</th>";
    echo "</tr>";
    
    if (isset($salary_calculation['employees']) && !empty($salary_calculation['employees'])) {
        $counter = 1;
        foreach ($salary_calculation['employees'] as $emp) {
            echo "<tr>";
            echo "<td>" . $counter++ . "</td>";
            echo "<td>" . htmlspecialchars($emp['employee_name'] ?? 'Unknown') . "</td>";
            echo "<td>" . htmlspecialchars($emp['job_title'] ?? '') . "</td>";
            echo "<td>" . format_payroll_currency($emp['basic_salary'] ?? 0, false) . "</td>";
            echo "<td>" . format_payroll_currency($emp['allowances'] ?? 0, false) . "</td>";
            echo "<td>" . format_payroll_currency($emp['overtime'] ?? 0, false) . "</td>";
            echo "<td>" . format_payroll_currency($emp['bonuses'] ?? 0, false) . "</td>";
            echo "<td>" . format_payroll_currency($emp['gross_salary'] ?? 0, false) . "</td>";
            echo "<td>" . format_payroll_currency($emp['nssf_employee'] ?? 0, false) . "</td>";
            echo "<td>" . format_payroll_currency($emp['paye_tax'] ?? 0, false) . "</td>";
            echo "<td>" . format_payroll_currency($emp['nhif'] ?? 0, false) . "</td>";
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
        
        // Totals row
        echo "<tr class='total'>";
        echo "<td colspan='3'><strong>TOTALS</strong></td>";
        echo "<td>" . format_payroll_currency($salary_calculation['summary']['total_basic_salary'] ?? 0, false) . "</td>";
        echo "<td>" . format_payroll_currency($salary_calculation['summary']['total_allowances'] ?? 0, false) . "</td>";
        echo "<td>" . format_payroll_currency($salary_calculation['summary']['total_overtime'] ?? 0, false) . "</td>";
        echo "<td>" . format_payroll_currency($salary_calculation['summary']['total_bonuses'] ?? 0, false) . "</td>";
        echo "<td>" . format_payroll_currency($salary_calculation['summary']['total_gross_salary'] ?? 0, false) . "</td>";
        echo "<td>" . format_payroll_currency($salary_calculation['summary']['total_nssf_employee'] ?? 0, false) . "</td>";
        echo "<td>" . format_payroll_currency($salary_calculation['summary']['total_paye_tax'] ?? 0, false) . "</td>";
        echo "<td>" . format_payroll_currency($salary_calculation['summary']['total_nhif'] ?? 0, false) . "</td>";
        echo "<td>" . format_payroll_currency($salary_calculation['summary']['total_other_deductions'] ?? 0, false) . "</td>";
        echo "<td>" . format_payroll_currency($salary_calculation['summary']['total_deductions'] ?? 0, false) . "</td>";
        echo "<td>" . format_payroll_currency($salary_calculation['summary']['total_net_salary'] ?? 0, false) . "</td>";
        echo "<td>" . format_payroll_currency($salary_calculation['summary']['total_nssf_employer'] ?? 0, false) . "</td>";
        echo "<td>" . format_payroll_currency($salary_calculation['summary']['total_sdl'] ?? 0, false) . "</td>";
        echo "<td>" . format_payroll_currency($salary_calculation['summary']['total_wcf'] ?? 0, false) . "</td>";
        echo "<td>" . format_payroll_currency($salary_calculation['summary']['total_osha'] ?? 0, false) . "</td>";
        echo "<td>" . format_payroll_currency($salary_calculation['summary']['total_employer_cost'] ?? 0, false) . "</td>";
        echo "</tr>";
    } else {
        echo "<tr><td colspan='19'>No employee data available</td></tr>";
    }
    
    echo "</table>";
    
    echo "</body>";
    echo "</html>";
    
    exit();
}

// ==================== POST HANDLERS ====================

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        // === 1. ADD/EDIT SALARY ITEM ===
        if (isset($_POST['save_salary_item'])) {
            $item_id = isset($_POST['item_id']) ? (int)$_POST['item_id'] : 0;
            $category_id = (int)$_POST['category_id'];
            $item_name = sanitize_input($_POST['item_name']);
            $item_code = sanitize_input($_POST['item_code']);
            $calculation_basis = sanitize_input($_POST['calculation_basis']);
            $calculation_type = sanitize_input($_POST['calculation_type']);
            $calculation_value = !empty($_POST['calculation_value']) ? (float)$_POST['calculation_value'] : NULL;
            $applies_to = sanitize_input($_POST['applies_to']);
            $affects_net_salary = isset($_POST['affects_net_salary']) ? 1 : 0;
            $is_summary_item = isset($_POST['is_summary_item']) ? 1 : 0;
            $display_order = (int)$_POST['display_order'];
            
            if ($item_id > 0) {
                // Update existing item
                $stmt = $db->prepare("
                    UPDATE salary_items 
                    SET category_id = ?, item_name = ?, item_code = ?, 
                        calculation_basis = ?, calculation_type = ?, calculation_value = ?,
                        applies_to = ?, affects_net_salary = ?, is_summary_item = ?,
                        display_order = ?, updated_at = NOW()
                    WHERE id = ?
                ");
                $stmt->execute([
                    $category_id, $item_name, $item_code, $calculation_basis, 
                    $calculation_type, $calculation_value, $applies_to, 
                    $affects_net_salary, $is_summary_item, $display_order, $item_id
                ]);
                $success_message = 'Salary item updated successfully.';
            } else {
                // Insert new item
                $stmt = $db->prepare("
                    INSERT INTO salary_items (
                        category_id, item_name, item_code, calculation_basis, 
                        calculation_type, calculation_value, applies_to, 
                        affects_net_salary, is_summary_item, display_order
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $category_id, $item_name, $item_code, $calculation_basis, 
                    $calculation_type, $calculation_value, $applies_to, 
                    $affects_net_salary, $is_summary_item, $display_order
                ]);
                $success_message = 'Salary item added successfully.';
            }
            
            redirect('hr/pay_salary.php?section=manage');
        }
        
        // === 2. DELETE SALARY ITEM ===
        elseif (isset($_POST['delete_salary_item'])) {
            $item_id = (int)$_POST['item_id'];
            
            $stmt = $db->prepare("UPDATE salary_items SET is_active = 0 WHERE id = ?");
            $stmt->execute([$item_id]);
            $success_message = 'Salary item deleted successfully.';
            redirect('hr/pay_salary.php?section=manage');
        }
        
        // === 3. ADD/EDIT STATUTORY RATE ===
        elseif (isset($_POST['save_statutory_rate'])) {
            $item_code = sanitize_input($_POST['item_code']);
            $country_code = sanitize_input($_POST['country_code']);
            $deduction_name = sanitize_input($_POST['deduction_name']);
            $rate = (float)$_POST['rate'];
            $min_amount = !empty($_POST['min_amount']) ? (float)$_POST['min_amount'] : NULL;
            $max_amount = !empty($_POST['max_amount']) ? (float)$_POST['max_amount'] : NULL;
            $applies_to = sanitize_input($_POST['applies_to']);
            $is_percentage = isset($_POST['is_percentage']) ? 1 : 0;
            $is_mandatory = isset($_POST['is_mandatory']) ? 1 : 0;
            $effective_date = $_POST['effective_date'];
            $expiry_date = !empty($_POST['expiry_date']) ? $_POST['expiry_date'] : NULL;
            
            // Check if rate already exists for this effective date
            $check_stmt = $db->prepare("
                SELECT id FROM statutory_deductions 
                WHERE item_code = ? AND country_code = ? AND effective_date = ?
            ");
            $check_stmt->execute([$item_code, $country_code, $effective_date]);
            $existing = $check_stmt->fetch();
            
            if ($existing) {
                // Update existing
                $stmt = $db->prepare("
                    UPDATE statutory_deductions 
                    SET deduction_name = ?, rate = ?, min_amount = ?, max_amount = ?,
                        applies_to = ?, is_percentage = ?, is_mandatory = ?,
                        expiry_date = ?, updated_at = NOW()
                    WHERE id = ?
                ");
                $stmt->execute([
                    $deduction_name, $rate, $min_amount, $max_amount, $applies_to,
                    $is_percentage, $is_mandatory, $expiry_date, $existing['id']
                ]);
            } else {
                // Insert new
                $stmt = $db->prepare("
                    INSERT INTO statutory_deductions (
                        item_code, country_code, deduction_name, rate, min_amount, max_amount,
                        applies_to, is_percentage, is_mandatory, effective_date, expiry_date
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $item_code, $country_code, $deduction_name, $rate, $min_amount, $max_amount,
                    $applies_to, $is_percentage, $is_mandatory, $effective_date, $expiry_date
                ]);
            }
            
            $success_message = 'Statutory rate saved successfully.';
            redirect('hr/pay_salary.php?section=statutory');
        }
        
        // === 4. CALCULATE SALARIES ===
        elseif (isset($_POST['calculate_salaries'])) {
            $pay_period_month = $_POST['pay_period_month'];
            
            // Get all active employees
            $stmt = $db->query("
                SELECT u.* FROM users u 
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
                    'total_allowances' => 0,
                    'total_overtime' => 0,
                    'total_bonuses' => 0,
                    'total_gross_salary' => 0,
                    'total_nssf_employee' => 0,
                    'total_paye_tax' => 0,
                    'total_nhif' => 0,
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
                
                // Get incentives for this month
                $incentive_stmt = $db->prepare("
                    SELECT incentive_type, SUM(amount) as total_amount
                    FROM payroll_incentives 
                    WHERE user_id = ? AND pay_period_month = ? AND status = 'approved'
                    GROUP BY incentive_type
                ");
                $incentive_stmt->execute([$emp['id'], $pay_period_month]);
                $incentives = $incentive_stmt->fetchAll();
                
                // Organize incentives
                $allowances = 0;
                $overtime = 0;
                $bonuses = 0;
                
                foreach ($incentives as $inc) {
                    $type = strtolower($inc['incentive_type']);
                    $amount = (float)$inc['total_amount'];
                    
                    if ($type === 'allowance') {
                        $allowances = $amount;
                    } elseif ($type === 'overtime') {
                        $overtime = $amount;
                    } elseif ($type === 'bonus') {
                        $bonuses = $amount;
                    }
                }
                
                // Calculate gross salary
                $gross_salary = $basic_salary + $allowances + $overtime + $bonuses;
                
                // Calculate deductions
                $nssf_employee = ($basic_salary * $rates['nssf_employee_rate'] / 100);
                $paye_tax = calculate_paye_tax($basic_salary);
                $nhif = $rates['enable_nhif'] ? ($basic_salary * $rates['nhif_rate'] / 100) : 0;
                $other_deductions = 0; // Could be loans, advances, etc.
                
                $total_deductions = $nssf_employee + $paye_tax + $nhif + $other_deductions;
                $net_salary = $gross_salary - $total_deductions;
                
                // Calculate employer contributions
                $nssf_employer = ($basic_salary * $rates['nssf_employer_rate'] / 100);
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
                    'allowances' => $allowances,
                    'overtime' => $overtime,
                    'bonuses' => $bonuses,
                    'gross_salary' => $gross_salary,
                    'nssf_employee' => $nssf_employee,
                    'paye_tax' => $paye_tax,
                    'nhif' => $nhif,
                    'other_deductions' => $other_deductions,
                    'total_deductions' => $total_deductions,
                    'net_salary' => $net_salary,
                    'nssf_employer' => $nssf_employer,
                    'sdl' => $sdl,
                    'wcf' => $wcf,
                    'osha' => $osha,
                    'total_employer_contributions' => $total_employer_contributions,
                    'total_employer_cost' => $total_employer_cost
                ];
                
                $salary_calculation['employees'][] = $employee_calc;
                
                // Update summary totals
                $salary_calculation['summary']['total_employees']++;
                $salary_calculation['summary']['total_basic_salary'] += $basic_salary;
                $salary_calculation['summary']['total_allowances'] += $allowances;
                $salary_calculation['summary']['total_overtime'] += $overtime;
                $salary_calculation['summary']['total_bonuses'] += $bonuses;
                $salary_calculation['summary']['total_gross_salary'] += $gross_salary;
                $salary_calculation['summary']['total_nssf_employee'] += $nssf_employee;
                $salary_calculation['summary']['total_paye_tax'] += $paye_tax;
                $salary_calculation['summary']['total_nhif'] += $nhif;
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
        
        // === 5. GENERATE PAYMENT REQUEST (USING TOTAL EMPLOYER COST) ===
        elseif (isset($_POST['generate_payment_request'])) {
            if (!isset($_SESSION['salary_calculation'])) {
                $error_message = 'No salary calculation found. Please calculate salaries first.';
            } else {
                $salary_calculation = $_SESSION['salary_calculation'];
                $pay_period_month = $_SESSION['pay_period_month'];
                $payment_date = $_POST['payment_date'] ?? date('Y-m-d');
                $payment_method = $_POST['payment_method'] ?? 'bank_transfer';
                $notes = sanitize_input($_POST['notes'] ?? '');
                
                // IMPORTANT: Use TOTAL EMPLOYER COST for payment
                $total_employer_cost = $salary_calculation['summary']['total_employer_cost'] ?? 0;
                
                // Breakdown
                $net_salary = $salary_calculation['summary']['total_net_salary'] ?? 0;
                $employer_contributions = ($salary_calculation['summary']['total_nssf_employer'] ?? 0) +
                                         ($salary_calculation['summary']['total_sdl'] ?? 0) +
                                         ($salary_calculation['summary']['total_wcf'] ?? 0) +
                                         ($salary_calculation['summary']['total_osha'] ?? 0);
                
                // Start database transaction
                $db->beginTransaction();
                
                try {
                    // Generate request number
                    $request_no = generate_salary_payment_request_no();
                    
                    // Insert into pending_pay - Using TOTAL EMPLOYER COST
                    $stmt = $db->prepare("
                        INSERT INTO pending_pay (
                            request_no, subject, pay_to_type, payee_id, payee_name,
                            payee_bank_name, payee_branch, payee_account_name,
                            payee_account_no, currency, amount_paid, cheque_no,
                            payment_description, requested_by, status
                        ) VALUES (?, ?, 'O', ?, ?, ?, ?, ?, ?, 'TSH', ?, ?, ?, ?, 'pending')
                    ");
                    
                    $subject = "Salary Payment - " . date('F Y', strtotime($pay_period_month . '-01'));
                    $description = "SALARY PAYMENT REQUEST\n" .
                                  "========================\n" .
                                  "Period: " . date('F Y', strtotime($pay_period_month . '-01')) . "\n" .
                                  "Total Employees: " . $salary_calculation['summary']['total_employees'] . "\n\n" .
                                  "PAYMENT BREAKDOWN:\n" .
                                  "1. Net Salary to Employees: " . format_payroll_currency($net_salary) . "\n" .
                                  "2. Employer Contributions:\n" .
                                  "   - NSSF Employer: " . format_payroll_currency($salary_calculation['summary']['total_nssf_employer'] ?? 0) . "\n" .
                                  "   - SDL: " . format_payroll_currency($salary_calculation['summary']['total_sdl'] ?? 0) . "\n" .
                                  "   - WCF: " . format_payroll_currency($salary_calculation['summary']['total_wcf'] ?? 0) . "\n" .
                                  "   - OSHA: " . format_payroll_currency($salary_calculation['summary']['total_osha'] ?? 0) . "\n" .
                                  "   Total Employer Contributions: " . format_payroll_currency($employer_contributions) . "\n\n" .
                                  "TOTAL PAYMENT REQUIRED (TOTAL EMPLOYER COST): " . format_payroll_currency($total_employer_cost) . "\n\n" .
                                  "Payment Method: " . $payment_method . "\n" .
                                  "Notes: " . $notes . "\n\n" .
                                  "This payment includes both employee net salaries and all statutory employer contributions.";
                    
                    $stmt->execute([
                        $request_no,
                        $subject,
                        'SALARY', // Special payee_id for salary payments
                        'Various Payees',
                        'Company Bank',
                        'Main Branch',
                        'Company Account',
                        'COMPANY-001',
                        $total_employer_cost, // Using TOTAL EMPLOYER COST
                        '',
                        $description,
                        $current_user_id
                    ]);
                    
                    $payment_id = $db->lastInsertId();
                    
                    // Save detailed calculation to salary_calculations table if it exists
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
                            $salary_calculation['summary']['total_gross_salary'],
                            $salary_calculation['summary']['total_deductions'],
                            $net_salary,
                            $employer_contributions,
                            $total_employer_cost,
                            $current_user_id
                        ]);
                    } catch (Exception $e) {
                        // Table might not exist, continue without saving calculation
                        error_log("salary_calculations table doesn't exist: " . $e->getMessage());
                    }
                    
                    $db->commit();

                    require_once '../includes/payroll_accounting.php';
                    
                    // Post payroll entries to General Ledger
                    $gl_reference_no = $request_no;
                    foreach ($salary_calculation['employees'] as $emp) {
                        $statutory_deductions = [
                            'nssf'             => $emp['nssf_employee'] ?? 0,
                            'paye'             => $emp['paye_tax'] ?? 0,
                            'health_insurance' => $emp['nhif'] ?? 0,
                        ];
                        $statutory_employer = [
                            'nssf'             => $emp['nssf_employer'] ?? 0,
                            'sdl'              => $emp['sdl'] ?? 0,
                            'wcf'              => $emp['wcf'] ?? 0,
                            'osha'             => $emp['osha'] ?? 0,
                            'health_insurance' => 0,
                        ];
                        $result = postPayrollToGL([
                            'reference_no'         => $gl_reference_no,
                            'employee_name'        => $emp['employee_name'],
                            'employee_id'          => $emp['user_id'],
                            'gross_salary'         => $emp['gross_salary'] ?? 0,
                            'deductions'           => [],
                            'statutory_deductions' => $statutory_deductions,
                            'statutory_employer'   => $statutory_employer,
                            'net_salary'           => $emp['net_salary'] ?? 0,
                            'period_start'         => $pay_period_month . '-01',
                            'period_end'           => date('Y-m-t', strtotime($pay_period_month . '-01')),
                            'posted_by'            => $current_user_id,
                        ]);
                        if (!$result['success']) {
                            error_log('Payroll GL posting error for employee ' . $emp['employee_name'] . ': ' . ($result['error'] ?? 'unknown'));
                        }
                    }
                    
                    // Clear session data
                    unset($_SESSION['salary_calculation']);
                    unset($_SESSION['pay_period_month']);
                    
                    $success_message = "Salary payment request created successfully!<br>
                                      <strong>Request No:</strong> {$request_no}<br>
                                      <strong>Total Employer Cost:</strong> " . format_payroll_currency($total_employer_cost) . "<br>
                                      <strong>Breakdown:</strong><br>
                                      - Net Salary: " . format_payroll_currency($net_salary) . "<br>
                                      - Employer Contributions: " . format_payroll_currency($employer_contributions) . "<br>
                                      <strong>Status:</strong> Sent to CEO for approval";
                    
                    redirect('hr/pay_salary.php?section=history');
                    
                } catch (Exception $e) {
                    $db->rollBack();
                    throw $e;
                }
            }
        }
        
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        $error_message = 'Error: ' . $e->getMessage();
    }
}

// ==================== GET DATA ====================

// Get system rates for display
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

// Get recent salary payments with approval tracking
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

// Check if we have a calculation in session
$salary_calculation = $_SESSION['salary_calculation'] ?? null;
$pay_period_month = $_SESSION['pay_period_month'] ?? date('Y-m');

// Get section from URL
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
            <?php if ($current_user_role === 'hr_manager'): ?>
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
            <?php endif; ?>
        </div>
    </div>

    <!-- Alerts -->
    <?php if (!empty($error_message)): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <i class="bi bi-exclamation-triangle me-2"></i>
            <?php echo $error_message; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if (!empty($success_message)): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <i class="bi bi-check-circle me-2"></i>
            <?php echo $success_message; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Navigation Tabs -->
    <ul class="nav nav-tabs mb-4">
        <li class="nav-item">
            <a class="nav-link <?php echo $section === 'overview' ? 'active' : ''; ?>" 
               href="?section=overview">
                <i class="bi bi-house-door me-1"></i>Overview
            </a>
        </li>
        <?php if ($current_user_role === 'hr_manager'): ?>
        <li class="nav-item">
            <a class="nav-link <?php echo $section === 'statutory' ? 'active' : ''; ?>" 
               href="?section=statutory">
                <i class="bi bi-gear me-1"></i>Statutory Setup
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?php echo $section === 'payment' ? 'active' : ''; ?>" 
               href="?section=payment">
                <i class="bi bi-cash-stack me-1"></i>Generate Payment
            </a>
        </li>
        <?php endif; ?>
        <li class="nav-item">
            <a class="nav-link <?php echo $section === 'history' ? 'active' : ''; ?>" 
               href="?section=history">
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
                            Complete Salary Structure
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
                                        <th>Amount (TZS)</th>
                                        <th>Affects Net Salary</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <!-- EARNINGS -->
                                    <tr class="table-success">
                                        <td rowspan="5" class="align-middle fw-bold">EARNINGS</td>
                                        <td>Basic Salary</td>
                                        <td>Fixed</td>
                                        <td><?php echo format_payroll_currency($total_basic_salary); ?></td>
                                        <td class="text-center"><i class="bi bi-check-circle-fill text-success"></i> Yes</td>
                                    </tr>
                                    <tr class="table-success">
                                        <td>Allowances</td>
                                        <td>Fixed</td>
                                        <td>Variable</td>
                                        <td class="text-center"><i class="bi bi-check-circle-fill text-success"></i> Yes</td>
                                    </tr>
                                    <tr class="table-success">
                                        <td>Overtime</td>
                                        <td>Fixed</td>
                                        <td><?php echo format_payroll_currency($rates['overtime_rate_per_hour']); ?>/hr</td>
                                        <td class="text-center"><i class="bi bi-check-circle-fill text-success"></i> Yes</td>
                                    </tr>
                                    <tr class="table-success">
                                        <td>Bonus</td>
                                        <td>Fixed</td>
                                        <td>Variable</td>
                                        <td class="text-center"><i class="bi bi-check-circle-fill text-success"></i> Yes</td>
                                    </tr>
                                    <tr class="table-success fw-bold">
                                        <td class="text-primary">GROSS SALARY</td>
                                        <td colspan="2">Sum of earnings</td>
                                        <td class="text-primary"><?php echo format_payroll_currency($total_basic_salary); ?> + Variable</td>
                                        <td class="text-center"><i class="bi bi-check-circle-fill text-success"></i> Yes</td>
                                    </tr>
                                    
                                    <!-- DEDUCTIONS (EMPLOYEE) -->
                                    <tr class="table-danger">
                                        <td rowspan="5" class="align-middle fw-bold">DEDUCTIONS (EMPLOYEE)</td>
                                        <td>NSSF (Employee)</td>
                                        <td><?php echo $rates['nssf_employee_rate']; ?>% of Basic</td>
                                        <td><?php echo format_payroll_currency(($total_basic_salary * $rates['nssf_employee_rate'] / 100)); ?></td>
                                        <td class="text-center"><i class="bi bi-check-circle-fill text-success"></i> Yes</td>
                                    </tr>
                                    <tr class="table-danger">
                                        <td>PAYE (Income Tax)</td>
                                        <td>0% – 30% (Progressive)</td>
                                        <td>Calculated per bracket</td>
                                        <td class="text-center"><i class="bi bi-check-circle-fill text-success"></i> Yes</td>
                                    </tr>
                                    <tr class="table-danger">
                                        <td>NHIF (Optional)</td>
                                        <td><?php echo $rates['enable_nhif'] ? $rates['nhif_rate'] . '% of Basic' : 'Disabled'; ?></td>
                                        <td><?php echo $rates['enable_nhif'] ? format_payroll_currency(($total_basic_salary * $rates['nhif_rate'] / 100)) : '-'; ?></td>
                                        <td class="text-center"><i class="bi bi-check-circle-fill text-success"></i> Yes</td>
                                    </tr>
                                    <tr class="table-danger">
                                        <td>Loan / Other</td>
                                        <td>Fixed</td>
                                        <td>Variable</td>
                                        <td class="text-center"><i class="bi bi-check-circle-fill text-success"></i> Yes</td>
                                    </tr>
                                    <tr class="table-danger fw-bold">
                                        <td class="text-danger">TOTAL DEDUCTIONS</td>
                                        <td colspan="2">Sum of deductions</td>
                                        <td class="text-danger">NSSF + PAYE + NHIF + Other</td>
                                        <td class="text-center"><i class="bi bi-check-circle-fill text-success"></i> Yes</td>
                                    </tr>
                                    
                                    <!-- NET PAY -->
                                    <tr class="table-primary fw-bold">
                                        <td>NET PAY</td>
                                        <td colspan="2">Net Salary</td>
                                        <td colspan="2" class="text-success">Gross – Deductions = Final Pay</td>
                                    </tr>
                                    
                                    <!-- EMPLOYER CONTRIBUTIONS -->
                                    <tr class="table-warning">
                                        <td rowspan="5" class="align-middle fw-bold">EMPLOYER CONTRIBUTIONS (INFO ONLY)</td>
                                        <td>NSSF (Employer)</td>
                                        <td><?php echo $rates['nssf_employer_rate']; ?>% of Basic</td>
                                        <td><?php echo format_payroll_currency(($total_basic_salary * $rates['nssf_employer_rate'] / 100)); ?></td>
                                        <td class="text-center"><i class="bi bi-x-circle-fill text-danger"></i> No</td>
                                    </tr>
                                    <tr class="table-warning">
                                        <td>SDL</td>
                                        <td><?php echo $rates['sdl_rate']; ?>% of Gross</td>
                                        <td>3.5% of Gross Salary</td>
                                        <td class="text-center"><i class="bi bi-x-circle-fill text-danger"></i> No</td>
                                    </tr>
                                    <tr class="table-warning">
                                        <td>WCF</td>
                                        <td><?php echo $rates['wcf_rate']; ?>% of Gross</td>
                                        <td>0.5% of Gross Salary</td>
                                        <td class="text-center"><i class="bi bi-x-circle-fill text-danger"></i> No</td>
                                    </tr>
                                    <tr class="table-warning">
                                        <td>OSHA</td>
                                        <td><?php echo $rates['osha_rate']; ?>% of Gross</td>
                                        <td>0.5% of Gross Salary</td>
                                        <td class="text-center"><i class="bi bi-x-circle-fill text-danger"></i> No</td>
                                    </tr>
                                    <tr class="table-warning fw-bold">
                                        <td class="text-warning">TOTAL EMPLOYER COST</td>
                                        <td colspan="2">Gross + Employer costs</td>
                                        <td class="text-warning">Gross + NSSF(Employer) + SDL + WCF + OSHA</td>
                                        <td class="text-center"><i class="bi bi-x-circle-fill text-danger"></i> No</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        
                        <div class="row mt-4">
                            <div class="col-md-3">
                                <div class="card border-start-success shadow h-100">
                                    <div class="card-body">
                                        <div class="d-flex align-items-center">
                                            <div class="me-3">
                                                <i class="bi bi-people fs-1 text-success"></i>
                                            </div>
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
                                            <div class="me-3">
                                                <i class="bi bi-cash-stack fs-1 text-primary"></i>
                                            </div>
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
                                            <div class="me-3">
                                                <i class="bi bi-clock-history fs-1 text-warning"></i>
                                            </div>
                                            <div>
                                                <div class="text-muted">Overtime Rate</div>
                                                <div class="fs-4 fw-bold"><?php echo format_payroll_currency($rates['overtime_rate_per_hour']); ?>/hr</div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="card border-start-info shadow h-100">
                                    <div class="card-body">
                                        <div class="d-flex align-items-center">
                                            <div class="me-3">
                                                <i class="bi bi-building fs-1 text-info"></i>
                                            </div>
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
    
    <!-- Statutory Setup Section (HR only) -->
    <?php elseif ($section === 'statutory' && $current_user_role === 'hr_manager'): ?>
        <div class="row">
            <div class="col-md-8">
                <div class="card shadow mb-4">
                    <div class="card-header bg-primary">
                        <h5 class="mb-0"><i class="bi bi-gear me-2"></i>Configure Statutory Deductions</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST">
                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <label class="form-label">NSSF Employee Rate (%)</label>
                                    <input type="number" class="form-control" name="nssf_employee_rate" 
                                           step="0.1" min="0" max="100" value="<?php echo $rates['nssf_employee_rate']; ?>" required>
                                    <small class="text-muted">Standard: 10% of basic salary</small>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">NSSF Employer Rate (%)</label>
                                    <input type="number" class="form-control" name="nssf_employer_rate" 
                                           step="0.1" min="0" max="100" value="<?php echo $rates['nssf_employer_rate']; ?>" required>
                                    <small class="text-muted">Standard: 10% of basic salary</small>
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
                                        <label class="form-check-label" for="enable_nhif">
                                            Enable NHIF Deduction
                                        </label>
                                    </div>
                                    <div id="nhifSettings" class="mt-2" style="<?php echo $rates['enable_nhif'] ? '' : 'display: none;'; ?>">
                                        <label class="form-label">NHIF Rate (%)</label>
                                        <input type="number" class="form-control" name="nhif_rate" 
                                               step="0.1" min="0" max="100" value="<?php echo $rates['nhif_rate']; ?>" required>
                                        <small class="text-muted">National Health Insurance Fund (3%)</small>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="alert alert-warning">
                                <i class="bi bi-exclamation-triangle me-2"></i>
                                These rates should match current Tanzanian labor laws. Consult legal experts before changing.
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
                                        <tr>
                                            <th>Min</th>
                                            <th>Max</th>
                                            <th>Rate</th>
                                            <th>Action</th>
                                        </tr>
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
                            <p class="text-muted">No PAYE brackets configured. Using default rates.</p>
                        <?php endif; ?>
                        
                        <button type="button" class="btn btn-sm btn-primary w-100 mt-2" data-bs-toggle="modal" data-bs-target="#addPayeBracketModal">
                            <i class="bi bi-plus-circle me-1"></i>Add Tax Bracket
                        </button>
                    </div>
                </div>
            </div>
        </div>
    
    <!-- Generate Payment Section (HR only) -->
    <?php elseif ($section === 'payment' && $current_user_role === 'hr_manager'): ?>
        <?php if ($salary_calculation): ?>
            <div class="row">
                <div class="col-12">
                    <div class="card shadow mb-4">
                        <div class="card-header bg-danger">
                            <h5 class="mb-0">
                                <i class="bi bi-calculator me-2"></i>
                                Salary Calculation - <?php echo date('F Y', strtotime($pay_period_month . '-01')); ?>
                                <?php if ($salary_calculation): ?>
                                    <span class="float-end">
                                        <a href="?export=excel" class="btn btn-sm btn-success">
                                            <i class="bi bi-file-earmark-excel me-1"></i>Export to Excel
                                        </a>
                                    </span>
                                <?php endif; ?>
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
                            
                            <!-- Employer Contributions -->
                            <div class="alert alert-warning mb-4">
                                <div class="row">
                                    <div class="col-md-3">
                                        <strong>NSSF Employer:</strong> <?php echo format_payroll_currency($salary_calculation['summary']['total_nssf_employer'] ?? 0); ?>
                                    </div>
                                    <div class="col-md-3">
                                        <strong>SDL:</strong> <?php echo format_payroll_currency($salary_calculation['summary']['total_sdl'] ?? 0); ?>
                                    </div>
                                    <div class="col-md-3">
                                        <strong>WCF:</strong> <?php echo format_payroll_currency($salary_calculation['summary']['total_wcf'] ?? 0); ?>
                                    </div>
                                    <div class="col-md-3">
                                        <strong>OSHA:</strong> <?php echo format_payroll_currency($salary_calculation['summary']['total_osha'] ?? 0); ?>
                                    </div>
                                </div>
                                <div class="mt-2">
                                    <strong>Total Employer Contributions:</strong> <?php 
                                        $total_employer_contributions = ($salary_calculation['summary']['total_nssf_employer'] ?? 0) +
                                                                       ($salary_calculation['summary']['total_sdl'] ?? 0) +
                                                                       ($salary_calculation['summary']['total_wcf'] ?? 0) +
                                                                       ($salary_calculation['summary']['total_osha'] ?? 0);
                                        echo format_payroll_currency($total_employer_contributions);
                                    ?>
                                </div>
                                <div class="mt-2">
                                    <strong>Total Employer Cost (Payment Required):</strong> 
                                    <span class="fw-bold text-danger"><?php echo format_payroll_currency($salary_calculation['summary']['total_employer_cost'] ?? 0); ?></span>
                                </div>
                            </div>
                            
                            <!-- Detailed Breakdown -->
                            <?php if (isset($salary_calculation['employees']) && !empty($salary_calculation['employees'])): ?>
                            <div class="table-responsive mb-4">
                                <table class="table table-bordered table-sm">
                                    <thead class="table-dark">
                                        <tr>
                                            <th>Employee</th>
                                            <th>Basic</th>
                                            <th>Allowance</th>
                                            <th>Overtime</th>
                                            <th>Bonus</th>
                                            <th>Gross</th>
                                            <th>NSSF</th>
                                            <th>PAYE</th>
                                            <th>NHIF</th>
                                            <th>Other</th>
                                            <th>Total Ded</th>
                                            <th>Net Pay</th>
                                            <th>Employer Cost</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($salary_calculation['employees'] as $emp): ?>
                                            <tr>
                                                <td>
                                                    <small><?php echo htmlspecialchars($emp['employee_name'] ?? 'Unknown'); ?></small><br>
                                                    <small class="text-muted"><?php echo htmlspecialchars($emp['job_title'] ?? ''); ?></small>
                                                </td>
                                                <td class="text-end"><?php echo format_payroll_currency($emp['basic_salary'] ?? 0); ?></td>
                                                <td class="text-end"><?php echo format_payroll_currency($emp['allowances'] ?? 0); ?></td>
                                                <td class="text-end"><?php echo format_payroll_currency($emp['overtime'] ?? 0); ?></td>
                                                <td class="text-end"><?php echo format_payroll_currency($emp['bonuses'] ?? 0); ?></td>
                                                <td class="text-end fw-bold"><?php echo format_payroll_currency($emp['gross_salary'] ?? 0); ?></td>
                                                <td class="text-end text-danger"><?php echo format_payroll_currency($emp['nssf_employee'] ?? 0); ?></td>
                                                <td class="text-end text-danger"><?php echo format_payroll_currency($emp['paye_tax'] ?? 0); ?></td>
                                                <td class="text-end text-danger"><?php echo format_payroll_currency($emp['nhif'] ?? 0); ?></td>
                                                <td class="text-end text-danger"><?php echo format_payroll_currency($emp['other_deductions'] ?? 0); ?></td>
                                                <td class="text-end text-danger fw-bold"><?php echo format_payroll_currency($emp['total_deductions'] ?? 0); ?></td>
                                                <td class="text-end text-success fw-bold"><?php echo format_payroll_currency($emp['net_salary'] ?? 0); ?></td>
                                                <td class="text-end text-warning fw-bold"><?php echo format_payroll_currency($emp['total_employer_cost'] ?? 0); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                    <tfoot class="table-secondary fw-bold">
                                        <tr>
                                            <td>TOTALS</td>
                                            <td class="text-end"><?php echo format_payroll_currency($salary_calculation['summary']['total_basic_salary'] ?? 0); ?></td>
                                            <td class="text-end"><?php echo format_payroll_currency($salary_calculation['summary']['total_allowances'] ?? 0); ?></td>
                                            <td class="text-end"><?php echo format_payroll_currency($salary_calculation['summary']['total_overtime'] ?? 0); ?></td>
                                            <td class="text-end"><?php echo format_payroll_currency($salary_calculation['summary']['total_bonuses'] ?? 0); ?></td>
                                            <td class="text-end"><?php echo format_payroll_currency($salary_calculation['summary']['total_gross_salary'] ?? 0); ?></td>
                                            <td class="text-end"><?php echo format_payroll_currency($salary_calculation['summary']['total_nssf_employee'] ?? 0); ?></td>
                                            <td class="text-end"><?php echo format_payroll_currency($salary_calculation['summary']['total_paye_tax'] ?? 0); ?></td>
                                            <td class="text-end"><?php echo format_payroll_currency($salary_calculation['summary']['total_nhif'] ?? 0); ?></td>
                                            <td class="text-end"><?php echo format_payroll_currency($salary_calculation['summary']['total_other_deductions'] ?? 0); ?></td>
                                            <td class="text-end"><?php echo format_payroll_currency($salary_calculation['summary']['total_deductions'] ?? 0); ?></td>
                                            <td class="text-end text-success"><?php echo format_payroll_currency($salary_calculation['summary']['total_net_salary'] ?? 0); ?></td>
                                            <td class="text-end text-warning"><?php echo format_payroll_currency($salary_calculation['summary']['total_employer_cost'] ?? 0); ?></td>
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
                                            <textarea class="form-control" name="notes" rows="3" 
                                                      placeholder="Add notes for CEO approval..."></textarea>
                                        </div>
                                        <div class="alert alert-danger">
                                            <i class="bi bi-exclamation-triangle me-2"></i>
                                            <?php
                                            $net_salary = $salary_calculation['summary']['total_net_salary'] ?? 0;
                                            $employer_contributions = ($salary_calculation['summary']['total_nssf_employer'] ?? 0) +
                                                                     ($salary_calculation['summary']['total_sdl'] ?? 0) +
                                                                     ($salary_calculation['summary']['total_wcf'] ?? 0) +
                                                                     ($salary_calculation['summary']['total_osha'] ?? 0);
                                            $total_employer_cost = $salary_calculation['summary']['total_employer_cost'] ?? 0;
                                            ?>
                                            <strong>PAYMENT REQUEST SUMMARY:</strong><br>
                                            • <strong>Net Salary to Employees:</strong> <?php echo format_payroll_currency($net_salary); ?><br>
                                            • <strong>Employer Contributions:</strong> <?php echo format_payroll_currency($employer_contributions); ?><br>
                                            • <strong>TOTAL EMPLOYER COST (Payment Required):</strong> 
                                            <span class="fw-bold text-danger"><?php echo format_payroll_currency($total_employer_cost); ?></span><br><br>
                                            <strong>This amount will be sent to CEO for approval, then to Finance for processing.</strong>
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
                                    <th>Employees</th>
                                    <th>Amount</th>
                                    <th>Status</th>
                                    <th>Approval Progress</th>
                                    <th>Requested By</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($salary_payments as $payment): 
                                    // Determine approval progress
                                    $progress = 0;
                                    $progress_text = '';
                                    $progress_class = '';
                                    
                                    if ($payment['status'] == 'pending') {
                                        $progress = 0;
                                        $progress_text = 'CEO Approval Pending';
                                        $progress_class = 'bg-warning';
                                    } elseif ($payment['status'] == 'approved_ceo') {
                                        $progress = 50;
                                        $progress_text = 'Finance Approval Pending';
                                        $progress_class = 'bg-info';
                                    } elseif ($payment['status'] == 'approved_finance') {
                                        $progress = 75;
                                        $progress_text = 'Payment Processing';
                                        $progress_class = 'bg-success';
                                    } elseif ($payment['status'] == 'paid') {
                                        $progress = 100;
                                        $progress_text = 'Completed';
                                        $progress_class = 'bg-primary';
                                    } elseif ($payment['status'] == 'rejected') {
                                        $progress = 0;
                                        $progress_text = 'Rejected';
                                        $progress_class = 'bg-danger';
                                    }
                                    
                                    // Extract period from subject
                                    $period = 'N/A';
                                    if (preg_match('/Salary Payment - (\w+ \d{4})/', $payment['subject'], $matches)) {
                                        $period = $matches[1];
                                    }
                                    
                                    // Extract employees from description
                                    $employees = 'N/A';
                                    if (preg_match('/Total Employees:\s*(\d+)/', $payment['payment_description'] ?? '', $emp_matches)) {
                                        $employees = $emp_matches[1];
                                    }
                                ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($payment['request_no']); ?></strong></td>
                                        <td><?php echo date('d/m/Y', strtotime($payment['requested_at'])); ?></td>
                                        <td><?php echo $period; ?></td>
                                        <td class="text-center"><?php echo $employees; ?></td>
                                        <td class="text-end">
                                            <strong class="text-success"><?php echo format_payroll_currency($payment['amount_paid']); ?></strong>
                                        </td>
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
                                                <div class="progress-bar progress-bar-striped progress-bar-animated <?php echo $progress_class; ?>" 
                                                     role="progressbar" style="width: <?php echo $progress; ?>%;" 
                                                     aria-valuenow="<?php echo $progress; ?>" aria-valuemin="0" aria-valuemax="100">
                                                    <?php echo $progress; ?>%
                                                </div>
                                            </div>
                                            <small class="text-muted"><?php echo $progress_text; ?></small>
                                        </td>
                                        <td><?php echo htmlspecialchars($payment['requested_by_name']); ?></td>
                                        <td>
                                            <div class="btn-group btn-group-sm" role="group">
                                                <a href="../hr/payment_request.php?request_id=<?php echo $payment['id']; ?>" 
                                                   class="btn btn-outline-primary" title="View Details">
                                                    <i class="bi bi-eye"></i>
                                                </a>
                                                <?php if ($current_user_role === 'ceo' && $payment['status'] === 'pending'): ?>
                                                    <a href="../hr/approve_payment.php?request_id=<?php echo $payment['id']; ?>&action=approve" 
                                                       class="btn btn-outline-success" title="Approve"
                                                       onclick="return confirm('Approve this salary payment of <?php echo format_payroll_currency($payment['amount_paid']); ?>?')">
                                                        <i class="bi bi-check"></i>
                                                    </a>
                                                    <a href="../hr/approve_payment.php?request_id=<?php echo $payment['id']; ?>&action=reject" 
                                                       class="btn btn-outline-danger" title="Reject"
                                                       onclick="return confirm('Reject this salary payment request?')">
                                                        <i class="bi bi-x"></i>
                                                    </a>
                                                <?php endif; ?>
                                                <button type="button" class="btn btn-outline-info" 
                                                        onclick="showApprovalDetails(<?php echo $payment['id']; ?>)"
                                                        title="View Approval Details">
                                                    <i class="bi bi-info-circle"></i>
                                                </button>
                                            </div>
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

<!-- === MODALS === -->

<!-- Calculate Salaries Modal -->
<div class="modal fade" id="calculateSalariesModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header bg-primary">
                    <h5 class="modal-title">
                        <i class="bi bi-calculator me-2"></i>Calculate Salaries
                    </h5>
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
                        Calculation includes:
                        <ul class="mb-0 mt-2">
                            <li>Basic salaries</li>
                            <li>Approved allowances, overtime, bonuses</li>
                            <li>NSSF, PAYE, NHIF deductions</li>
                            <li>Other assigned deductions</li>
                            <li>Employer costs (NSSF, SDL, WCF, OSHA)</li>
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
                    <h5 class="modal-title">
                        <i class="bi bi-percent me-2"></i>Add PAYE Tax Bracket
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Minimum Income (TZS) <span class="text-danger">*</span></label>
                            <input type="number" class="form-control" name="bracket_min" 
                                   step="0.01" min="0" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Maximum Income (TZS)</label>
                            <input type="number" class="form-control" name="bracket_max" 
                                   step="0.01" min="0">
                            <small class="text-muted">Leave 0 for unlimited</small>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Tax Rate (%) <span class="text-danger">*</span></label>
                        <input type="number" class="form-control" name="tax_rate" 
                               step="0.1" min="0" max="100" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <input type="text" class="form-control" name="description" 
                               placeholder="e.g., First bracket, Standard rate">
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

<!-- Approval Details Modal -->
<div class="modal fade" id="approvalDetailsModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-info">
                <h5 class="modal-title">
                    <i class="bi bi-check-circle me-2"></i>Approval Details
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="approvalDetailsContent">
                <!-- Approval details will be loaded here -->
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // NHIF toggle
    const enableNHIF = document.getElementById('enable_nhif');
    const nhifSettings = document.getElementById('nhifSettings');
    
    if (enableNHIF && nhifSettings) {
        enableNHIF.addEventListener('change', function() {
            nhifSettings.style.display = this.checked ? 'block' : 'none';
            if (!this.checked) {
                nhifSettings.querySelector('input').value = '3';
            }
        });
    }
    
    // Tab handling
    const currentSection = '<?php echo $section; ?>';
    if (currentSection) {
        const activeTab = document.querySelector(`a[href="?section=${currentSection}"]`);
        if (activeTab) {
            activeTab.classList.add('active');
        }
    }
});

// Show approval details
function showApprovalDetails(requestId) {
    fetch(`../hr/get_payment_approval_details.php?request_id=${requestId}`)
        .then(response => response.json())
        .then(data => {
            let content = '';
            if (data.error) {
                content = `<div class="alert alert-danger">${data.error}</div>`;
            } else {
                content = `
                    <h6>Request: ${data.request_no}</h6>
                    <p><strong>Amount:</strong> ${new Intl.NumberFormat('en-TZ', {
                        style: 'currency',
                        currency: 'TZS',
                        minimumFractionDigits: 0,
                        maximumFractionDigits: 0
                    }).format(data.amount_paid)}</p>
                    
                    <h6 class="mt-3">Approval Timeline:</h6>
                    <ul class="list-group">
                        <li class="list-group-item">
                            <strong>Requested:</strong> ${new Date(data.requested_at).toLocaleString()} 
                            by ${data.requested_by_name || 'Unknown'}
                        </li>
                        ${data.ceo_approved_at ? `
                        <li class="list-group-item list-group-item-success">
                            <strong>CEO Approved:</strong> ${new Date(data.ceo_approved_at).toLocaleString()} 
                            by ${data.ceo_approved_by_name || 'Unknown'}
                            ${data.ceo_approval_notes ? `<br><small>Notes: ${data.ceo_approval_notes}</small>` : ''}
                        </li>` : ''}
                        ${data.finance_approved_at ? `
                        <li class="list-group-item list-group-item-success">
                            <strong>Finance Approved:</strong> ${new Date(data.finance_approved_at).toLocaleString()} 
                            by ${data.finance_approved_by_name || 'Unknown'}
                            ${data.finance_approval_notes ? `<br><small>Notes: ${data.finance_approval_notes}</small>` : ''}
                        </li>` : ''}
                        ${data.paid_at ? `
                        <li class="list-group-item list-group-item-primary">
                            <strong>Paid:</strong> ${new Date(data.paid_at).toLocaleString()} 
                            by ${data.paid_by_name || 'Unknown'}
                        </li>` : ''}
                    </ul>
                    
                    ${data.rejection_reason ? `
                    <div class="alert alert-danger mt-3">
                        <strong>Rejection Reason:</strong> ${data.rejection_reason}
                    </div>` : ''}
                `;
            }
            
            document.getElementById('approvalDetailsContent').innerHTML = content;
            const modal = new bootstrap.Modal(document.getElementById('approvalDetailsModal'));
            modal.show();
        })
        .catch(error => {
            document.getElementById('approvalDetailsContent').innerHTML = 
                `<div class="alert alert-danger">Error loading approval details: ${error.message}</div>`;
            const modal = new bootstrap.Modal(document.getElementById('approvalDetailsModal'));
            modal.show();
        });
}
</script>

<?php
include '../includes/footer.php';
$db = null;
?>