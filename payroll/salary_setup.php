<?php
// /hr/pay_salary.php - COMPLETE SALARY PAYMENT SYSTEM
require_once '../config/config.php';
require_once '../auth/auth_middleware.php';
require_once '../includes/payroll_helpers.php';

// Check permissions - HR only
$current_user_id = $_SESSION['user_id'];
$current_user_role = $_SESSION['role'];

if ($current_user_role !== 'hr_manager') {
    header('Location: ../dashboard.php');
    exit();
}

$db = getDBConnection();
$page_title = 'Salary Payment System';
$success_message = '';
$error_message = '';

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
    
    $stmt = $db->prepare("
        SELECT request_no FROM pending_pay 
        WHERE request_no LIKE ? 
        ORDER BY id DESC LIMIT 1
    ");
    $stmt->execute(["$prefix$year$month%"]);
    $last = $stmt->fetch();
    
    if ($last) {
        $last_no = intval(substr($last['request_no'], -4));
        $new_no = str_pad($last_no + 1, 4, '0', STR_PAD_LEFT);
    } else {
        $new_no = '0001';
    }
    
    return $prefix . $year . $month . $new_no;
}

function get_deduction_exemptions_for_user($user_id) {
    global $db;
    
    $exemptions = [];
    try {
        $stmt = $db->prepare("
            SELECT deduction_type, exemption_type, exemption_value
            FROM employee_deduction_exemptions 
            WHERE user_id = ? 
            AND status = 'active'
            AND (expiry_date IS NULL OR expiry_date >= CURDATE())
        ");
        $stmt->execute([$user_id]);
        $exemptions_data = $stmt->fetchAll();
        
        foreach ($exemptions_data as $ex) {
            $exemptions[$ex['deduction_type']] = [
                'type' => $ex['exemption_type'],
                'value' => $ex['exemption_value']
            ];
        }
    } catch (Exception $e) {
        // Continue with empty exemptions
    }
    
    return $exemptions;
}

// ==================== POST HANDLERS ====================

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        // === 1. CONFIGURE STATUTORY DEDUCTIONS ===
        if (isset($_POST['configure_statutory'])) {
            $rates = [
                'nssf_employee_rate' => (float)$_POST['nssf_employee_rate'],
                'nssf_employer_rate' => (float)$_POST['nssf_employer_rate'],
                'sdl_rate' => (float)$_POST['sdl_rate'],
                'wcf_rate' => (float)$_POST['wcf_rate'],
                'osha_rate' => (float)$_POST['osha_rate'],
                'overtime_rate_per_hour' => (float)$_POST['overtime_rate_per_hour'],
                'enable_nhif' => isset($_POST['enable_nhif']) ? 1 : 0,
                'nhif_rate' => (float)$_POST['nhif_rate']
            ];
            
            $db->beginTransaction();
            
            foreach ($rates as $key => $value) {
                $stmt = $db->prepare("
                    INSERT INTO system_settings (setting_key, setting_value, description, updated_by, updated_at)
                    VALUES (?, ?, 'Salary system setting', ?, NOW())
                    ON DUPLICATE KEY UPDATE setting_value = ?, updated_by = ?, updated_at = NOW()
                ");
                $stmt->execute([$key, $value, $_SESSION['user_id'], $value, $_SESSION['user_id']]);
            }
            
            $db->commit();
            $success_message = 'Statutory deductions configured successfully.';
            redirect('../hr/pay_salary.php');
        }
        
        // === 2. ADD/UPDATE PAYE BRACKET ===
        elseif (isset($_POST['save_paye_bracket'])) {
            $bracket_min = (float)$_POST['bracket_min'];
            $bracket_max = (float)$_POST['bracket_max'];
            $tax_rate = (float)$_POST['tax_rate'];
            $description = sanitize_input($_POST['description'] ?? '');
            
            $stmt = $db->prepare("
                INSERT INTO paye_tax_brackets (bracket_min, bracket_max, tax_rate, description, created_by)
                VALUES (?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE tax_rate = VALUES(tax_rate), description = VALUES(description), updated_at = NOW()
            ");
            
            $stmt->execute([$bracket_min, $bracket_max, $tax_rate, $description, $_SESSION['user_id']]);
            $success_message = 'PAYE tax bracket saved successfully.';
            redirect('../hr/pay_salary.php');
        }
        
        // === 3. DELETE PAYE BRACKET ===
        elseif (isset($_POST['delete_paye_bracket'])) {
            $bracket_id = (int)$_POST['bracket_id'];
            
            $stmt = $db->prepare("DELETE FROM paye_tax_brackets WHERE id = ?");
            $stmt->execute([$bracket_id]);
            $success_message = 'PAYE tax bracket deleted.';
            redirect('../hr/pay_salary.php');
        }
        
        // === 4. ADD/UPDATE DEDUCTION EXEMPTION ===
        elseif (isset($_POST['save_deduction_exemption'])) {
            $user_id = (int)$_POST['user_id'];
            $deduction_type = sanitize_input($_POST['deduction_type']);
            $exemption_type = sanitize_input($_POST['exemption_type']);
            $exemption_value = $exemption_type === 'partial' ? (float)$_POST['exemption_value'] : NULL;
            $effective_date = $_POST['effective_date'];
            $expiry_date = !empty($_POST['expiry_date']) ? $_POST['expiry_date'] : NULL;
            $reason = sanitize_input($_POST['reason'] ?? '');
            
            $db->beginTransaction();
            
            // Deactivate any existing active exemption for same deduction
            $deactivate_stmt = $db->prepare("
                UPDATE employee_deduction_exemptions 
                SET status = 'cancelled', updated_at = NOW()
                WHERE user_id = ? AND deduction_type = ? AND status = 'active'
            ");
            $deactivate_stmt->execute([$user_id, $deduction_type]);
            
            // Insert new exemption
            $stmt = $db->prepare("
                INSERT INTO employee_deduction_exemptions (
                    user_id, deduction_type, exemption_type, exemption_value,
                    effective_date, expiry_date, reason, created_by
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([
                $user_id, $deduction_type, $exemption_type, $exemption_value,
                $effective_date, $expiry_date, $reason, $_SESSION['user_id']
            ]);
            
            $db->commit();
            $success_message = 'Deduction exemption saved successfully.';
            redirect('../hr/pay_salary.php');
        }
        
        // === 5. REMOVE DEDUCTION EXEMPTION ===
        elseif (isset($_POST['remove_exemption'])) {
            $exemption_id = (int)$_POST['exemption_id'];
            
            $stmt = $db->prepare("
                UPDATE employee_deduction_exemptions 
                SET status = 'cancelled', updated_at = NOW()
                WHERE id = ? AND status = 'active'
            ");
            $stmt->execute([$exemption_id]);
            $success_message = 'Exemption removed successfully.';
            redirect('../hr/pay_salary.php');
        }
        
        // === 6. CALCULATE SALARIES ===
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
            
            // Get statutory rates
            $rate_stmt = $db->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key LIKE '%_rate' OR setting_key = 'enable_nhif'");
            $rates_data = $rate_stmt->fetchAll();
            $rates = [];
            foreach ($rates_data as $r) {
                $rates[$r['setting_key']] = $r['setting_value'];
            }
            
            // Default rates if not set
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
            
            // Get incentives for this month
            $incentive_stmt = $db->prepare("
                SELECT pi.user_id, pi.incentive_type, SUM(pi.amount) as total_amount
                FROM payroll_incentives pi
                WHERE pi.pay_period_month = ? AND pi.status = 'approved'
                GROUP BY pi.user_id, pi.incentive_type
            ");
            $incentive_stmt->execute([$pay_period_month]);
            $incentives = $incentive_stmt->fetchAll();
            
            // Organize incentives by user and type
            $user_incentives = [];
            foreach ($incentives as $inc) {
                $user_id = $inc['user_id'];
                if (!isset($user_incentives[$user_id])) {
                    $user_incentives[$user_id] = [
                        'allowance' => 0,
                        'overtime' => 0,
                        'bonus' => 0,
                        'deduction' => 0
                    ];
                }
                $user_incentives[$user_id][$inc['incentive_type']] = $inc['total_amount'];
            }
            
            // Calculate salaries
            $salary_calculation = [
                'pay_period_month' => $pay_period_month,
                'calculation_date' => date('Y-m-d H:i:s'),
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
            
            foreach ($employees as $emp) {
                $basic_salary = (float)$emp['salary'];
                $emp_inc = $user_incentives[$emp['id']] ?? [
                    'allowance' => 0,
                    'overtime' => 0,
                    'bonus' => 0,
                    'deduction' => 0
                ];
                
                // Get exemptions for this employee
                $exemptions = get_deduction_exemptions_for_user($emp['id']);
                
                // EARNINGS
                $allowances = $emp_inc['allowance'];
                $overtime = $emp_inc['overtime'];
                $bonuses = $emp_inc['bonus'];
                $gross_salary = $basic_salary + $allowances + $overtime + $bonuses;
                
                // DEDUCTIONS (EMPLOYEE) - Apply exemptions
                $nssf_employee = 0;
                if (!isset($exemptions['nssf']) || $exemptions['nssf']['type'] !== 'full') {
                    $nssf_base = ($basic_salary * $rates['nssf_employee_rate']) / 100;
                    if (isset($exemptions['nssf']) && $exemptions['nssf']['type'] === 'partial') {
                        $nssf_employee = max(0, $nssf_base - $exemptions['nssf']['value']);
                    } else {
                        $nssf_employee = $nssf_base;
                    }
                }
                
                $paye_tax = 0;
                if (!isset($exemptions['paye']) || $exemptions['paye']['type'] !== 'full') {
                    $paye_base = calculate_paye_tax($basic_salary);
                    if (isset($exemptions['paye']) && $exemptions['paye']['type'] === 'partial') {
                        $paye_tax = max(0, $paye_base - $exemptions['paye']['value']);
                    } else {
                        $paye_tax = $paye_base;
                    }
                }
                
                $nhif = 0;
                if ($rates['enable_nhif'] && (!isset($exemptions['nhif']) || $exemptions['nhif']['type'] !== 'full')) {
                    $nhif_base = ($basic_salary * $rates['nhif_rate']) / 100;
                    if (isset($exemptions['nhif']) && $exemptions['nhif']['type'] === 'partial') {
                        $nhif = max(0, $nhif_base - $exemptions['nhif']['value']);
                    } else {
                        $nhif = $nhif_base;
                    }
                }
                
                $other_deductions = 0;
                if (!isset($exemptions['all']) || $exemptions['all']['type'] !== 'full') {
                    $other_base = $emp_inc['deduction'];
                    if (isset($exemptions['all']) && $exemptions['all']['type'] === 'partial') {
                        $other_deductions = max(0, $other_base - $exemptions['all']['value']);
                    } else {
                        $other_deductions = $other_base;
                    }
                }
                
                $total_deductions = $nssf_employee + $paye_tax + $nhif + $other_deductions;
                
                // NET PAY
                $net_salary = $gross_salary - $total_deductions;
                
                // EMPLOYER CONTRIBUTIONS
                $nssf_employer = ($basic_salary * $rates['nssf_employer_rate']) / 100;
                $sdl = ($gross_salary * $rates['sdl_rate']) / 100;
                $wcf = ($gross_salary * $rates['wcf_rate']) / 100;
                $osha = ($gross_salary * $rates['osha_rate']) / 100;
                $total_employer_cost = $gross_salary + $nssf_employer + $sdl + $wcf + $osha;
                
                // Add to employee calculation
                $salary_calculation['employees'][] = [
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
                    'total_employer_cost' => $total_employer_cost,
                    'exemptions_applied' => !empty($exemptions) ? $exemptions : []
                ];
                
                // Update summary
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
            redirect('../hr/pay_salary.php?section=payment');
        }
        
        // === 7. GENERATE PAYMENT REQUEST ===
        elseif (isset($_POST['generate_payment_request'])) {
            if (!isset($_SESSION['salary_calculation'])) {
                $error_message = 'No salary calculation found. Please calculate salaries first.';
            } else {
                $salary_calculation = $_SESSION['salary_calculation'];
                $pay_period_month = $_SESSION['pay_period_month'];
                $payment_date = $_POST['payment_date'] ?? date('Y-m-d');
                $payment_method = $_POST['payment_method'] ?? 'bank_transfer';
                $notes = sanitize_input($_POST['notes'] ?? '');
                
                // Calculate total payment (net salary + employer contributions)
                $net_salary = $salary_calculation['summary']['total_net_salary'] ?? 0;
                $employer_contributions = ($salary_calculation['summary']['total_nssf_employer'] ?? 0) +
                                         ($salary_calculation['summary']['total_sdl'] ?? 0) +
                                         ($salary_calculation['summary']['total_wcf'] ?? 0) +
                                         ($salary_calculation['summary']['total_osha'] ?? 0);
                
                $total_payment = $net_salary + $employer_contributions;
                
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
                        ) VALUES (?, ?, 'O', ?, ?, ?, ?, ?, ?, 'TZS', ?, ?, ?, ?, 'pending')
                    ");
                    
                    $subject = "Salary Payment - " . date('F Y', strtotime($pay_period_month . '-01'));
                    $payee_id = 'SALARY';
                    $payee_name = 'Employee Salaries & Statutory Payments';
                    
                    $description = "Salary payment for " . date('F Y', strtotime($pay_period_month . '-01')) . "\n" .
                                  "Employees: " . ($salary_calculation['summary']['total_employees'] ?? 0) . "\n" .
                                  "Net Salary: " . format_payroll_currency($net_salary) . "\n" .
                                  "Employer Contributions: " . format_payroll_currency($employer_contributions) . "\n" .
                                  "Total Payment: " . format_payroll_currency($total_payment) . 
                                  (!empty($notes) ? "\n\nNotes: " . $notes : '');
                    
                    $stmt->execute([
                        $request_no,
                        $subject,
                        $payee_id,
                        $payee_name,
                        'Various Banks',
                        'Various Branches',
                        'Various Employees',
                        'Various Accounts',
                        $total_payment,
                        '',
                        $description,
                        $_SESSION['user_id']
                    ]);
                    
                    $payment_id = $db->lastInsertId();
                    
                    // Save calculation details
                    $calc_stmt = $db->prepare("
                        INSERT INTO salary_calculations (
                            payment_request_id, pay_period_month, calculation_data,
                            total_employees, total_gross, total_deductions, total_net,
                            total_employer_contributions, total_payment, created_by
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
                        $total_payment,
                        $_SESSION['user_id']
                    ]);
                    
                    // Save individual employee payments for reference
                    foreach ($salary_calculation['employees'] as $emp) {
                        $emp_stmt = $db->prepare("
                            INSERT INTO employee_salary_payments (
                                payment_request_id, user_id, basic_salary, allowances,
                                overtime, bonuses, gross_salary, nssf_employee,
                                paye_tax, nhif, other_deductions, total_deductions,
                                net_salary, nssf_employer, sdl, wcf, osha,
                                total_employer_cost, pay_period_month
                            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                        ");
                        
                        $emp_stmt->execute([
                            $payment_id,
                            $emp['user_id'],
                            $emp['basic_salary'],
                            $emp['allowances'],
                            $emp['overtime'],
                            $emp['bonuses'],
                            $emp['gross_salary'],
                            $emp['nssf_employee'],
                            $emp['paye_tax'],
                            $emp['nhif'],
                            $emp['other_deductions'],
                            $emp['total_deductions'],
                            $emp['net_salary'],
                            $emp['nssf_employer'],
                            $emp['sdl'],
                            $emp['wcf'],
                            $emp['osha'],
                            $emp['total_employer_cost'],
                            $pay_period_month
                        ]);
                    }
                    
                    // Log activity
                    $activity_stmt = $db->prepare("
                        INSERT INTO hr_activities (activity_type, entity_type, entity_id, description, performed_by)
                        VALUES ('salary_payment_created', 'payment', ?, ?, ?)
                    ");
                    $activity_stmt->execute([
                        $payment_id,
                        "Created salary payment request {$request_no} - Total: " . format_payroll_currency($total_payment),
                        $_SESSION['user_id']
                    ]);
                    
                    $db->commit();
                    
                    // Clear session
                    unset($_SESSION['salary_calculation']);
                    unset($_SESSION['pay_period_month']);
                    
                    $success_message = "Salary payment request created successfully!<br>
                                      <strong>Request No:</strong> {$request_no}<br>
                                      <strong>Net Salary:</strong> " . format_payroll_currency($net_salary) . "<br>
                                      <strong>Employer Contributions:</strong> " . format_payroll_currency($employer_contributions) . "<br>
                                      <strong>Total Payment:</strong> " . format_payroll_currency($total_payment) . "<br>
                                      <strong>Status:</strong> Sent to CEO for approval";
                    redirect('../hr/pay_salary.php');
                    
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

// Get statutory rates
$rates = [];
try {
    $stmt = $db->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key LIKE '%_rate' OR setting_key = 'enable_nhif'");
    $rates_data = $stmt->fetchAll();
    foreach ($rates_data as $r) {
        $rates[$r['setting_key']] = $r['setting_value'];
    }
} catch (Exception $e) {
    $rates = [];
}

// Set default rates if not exist
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
        SELECT pp.*, sc.total_employees, sc.total_payment, u.full_name as requested_by_name
        FROM pending_pay pp
        LEFT JOIN salary_calculations sc ON pp.id = sc.payment_request_id
        LEFT JOIN users u ON pp.requested_by = u.id
        WHERE pp.subject LIKE 'Salary Payment%'
        AND pp.payee_id = 'SALARY'
        ORDER BY pp.requested_at DESC
        LIMIT 10
    ");
    $salary_payments = $stmt->fetchAll();
} catch (Exception $e) {
    $salary_payments = [];
}

// Get active employees count and total salary
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

// Get active employees for exemption management
$employees = [];
try {
    $stmt = $db->query("
        SELECT id, username, full_name, job_title, salary 
        FROM users 
        WHERE status = 'active' 
        AND role NOT IN ('system_admin', 'ceo')
        ORDER BY full_name
    ");
    $employees = $stmt->fetchAll();
} catch (Exception $e) {
    $employees = [];
}

// Get deduction exemptions
$deduction_exemptions = [];
try {
    $stmt = $db->query("
        SELECT 
            e.*,
            u.full_name,
            u.username
        FROM employee_deduction_exemptions e
        JOIN users u ON e.user_id = u.id
        WHERE e.status = 'active'
        AND (e.expiry_date IS NULL OR e.expiry_date >= CURDATE())
        ORDER BY e.deduction_type, u.full_name
    ");
    $deduction_exemptions = $stmt->fetchAll();
} catch (Exception $e) {
    $deduction_exemptions = [];
}

// Get salary structure items from database
$salary_structure = [];
try {
    // Try to get from database first
    $stmt = $db->query("
        SELECT 
            'EARNINGS' as category_name,
            1 as display_order,
            1 as affects_net_salary,
            0 as is_employer_contribution,
            'Basic Salary' as item_name,
            'Fixed' as calculation_basis,
            'fixed' as calculation_type,
            NULL as calculation_value,
            1 as affects_net_salary_item,
            0 as is_summary_item,
            1 as item_display_order
            
        UNION ALL
        
        SELECT 
            'EARNINGS' as category_name,
            1 as display_order,
            1 as affects_net_salary,
            0 as is_employer_contribution,
            'Allowances' as item_name,
            'Fixed' as calculation_basis,
            'variable' as calculation_type,
            NULL as calculation_value,
            1 as affects_net_salary_item,
            0 as is_summary_item,
            2 as item_display_order
            
        UNION ALL
        
        SELECT 
            'EARNINGS' as category_name,
            1 as display_order,
            1 as affects_net_salary,
            0 as is_employer_contribution,
            'Overtime' as item_name,
            'Fixed' as calculation_basis,
            'variable' as calculation_type,
            NULL as calculation_value,
            1 as affects_net_salary_item,
            0 as is_summary_item,
            3 as item_display_order
            
        UNION ALL
        
        SELECT 
            'EARNINGS' as category_name,
            1 as display_order,
            1 as affects_net_salary,
            0 as is_employer_contribution,
            'Bonus' as item_name,
            'Fixed' as calculation_basis,
            'variable' as calculation_type,
            NULL as calculation_value,
            1 as affects_net_salary_item,
            0 as is_summary_item,
            4 as item_display_order
            
        UNION ALL
        
        SELECT 
            'EARNINGS' as category_name,
            1 as display_order,
            1 as affects_net_salary,
            0 as is_employer_contribution,
            'GROSS SALARY' as item_name,
            'Sum of earnings' as calculation_basis,
            'summary' as calculation_type,
            NULL as calculation_value,
            1 as affects_net_salary_item,
            1 as is_summary_item,
            5 as item_display_order
            
        UNION ALL
        
        SELECT 
            'DEDUCTIONS (EMPLOYEE)' as category_name,
            2 as display_order,
            1 as affects_net_salary,
            0 as is_employer_contribution,
            'NSSF (Employee)' as item_name,
            CONCAT(?, '% of Basic') as calculation_basis,
            'percentage' as calculation_type,
            ? as calculation_value,
            1 as affects_net_salary_item,
            0 as is_summary_item,
            1 as item_display_order
            
        UNION ALL
        
        SELECT 
            'DEDUCTIONS (EMPLOYEE)' as category_name,
            2 as display_order,
            1 as affects_net_salary,
            0 as is_employer_contribution,
            'PAYE (Income Tax)' as item_name,
            '0% – 30% (Progressive)' as calculation_basis,
            'progressive' as calculation_type,
            NULL as calculation_value,
            1 as affects_net_salary_item,
            0 as is_summary_item,
            2 as item_display_order
            
        UNION ALL
        
        SELECT 
            'DEDUCTIONS (EMPLOYEE)' as category_name,
            2 as display_order,
            1 as affects_net_salary,
            0 as is_employer_contribution,
            'NHIF (Optional)' as item_name,
            CASE WHEN ? = 1 THEN CONCAT(?, '% of Basic') ELSE 'Disabled' END as calculation_basis,
            'percentage' as calculation_type,
            ? as calculation_value,
            1 as affects_net_salary_item,
            0 as is_summary_item,
            3 as item_display_order
            
        UNION ALL
        
        SELECT 
            'DEDUCTIONS (EMPLOYEE)' as category_name,
            2 as display_order,
            1 as affects_net_salary,
            0 as is_employer_contribution,
            'Loan / Other' as item_name,
            'Fixed' as calculation_basis,
            'variable' as calculation_type,
            NULL as calculation_value,
            1 as affects_net_salary_item,
            0 as is_summary_item,
            4 as item_display_order
            
        UNION ALL
        
        SELECT 
            'DEDUCTIONS (EMPLOYEE)' as category_name,
            2 as display_order,
            1 as affects_net_salary,
            0 as is_employer_contribution,
            'TOTAL DEDUCTIONS' as item_name,
            'Sum of deductions' as calculation_basis,
            'summary' as calculation_type,
            NULL as calculation_value,
            1 as affects_net_salary_item,
            1 as is_summary_item,
            5 as item_display_order
            
        UNION ALL
        
        SELECT 
            'NET PAY' as category_name,
            3 as display_order,
            1 as affects_net_salary,
            0 as is_employer_contribution,
            'Net Salary' as item_name,
            'Gross – Deductions' as calculation_basis,
            'summary' as calculation_type,
            NULL as calculation_value,
            1 as affects_net_salary_item,
            1 as is_summary_item,
            1 as item_display_order
            
        UNION ALL
        
        SELECT 
            'EMPLOYER CONTRIBUTIONS (INFO ONLY)' as category_name,
            4 as display_order,
            0 as affects_net_salary,
            1 as is_employer_contribution,
            'NSSF (Employer)' as item_name,
            CONCAT(?, '% of Basic') as calculation_basis,
            'percentage' as calculation_type,
            ? as calculation_value,
            0 as affects_net_salary_item,
            0 as is_summary_item,
            1 as item_display_order
            
        UNION ALL
        
        SELECT 
            'EMPLOYER CONTRIBUTIONS (INFO ONLY)' as category_name,
            4 as display_order,
            0 as affects_net_salary,
            1 as is_employer_contribution,
            'SDL' as item_name,
            CONCAT(?, '% of Gross') as calculation_basis,
            'percentage' as calculation_type,
            ? as calculation_value,
            0 as affects_net_salary_item,
            0 as is_summary_item,
            2 as item_display_order
            
        UNION ALL
        
        SELECT 
            'EMPLOYER CONTRIBUTIONS (INFO ONLY)' as category_name,
            4 as display_order,
            0 as affects_net_salary,
            1 as is_employer_contribution,
            'WCF' as item_name,
            CONCAT(?, '% of Gross') as calculation_basis,
            'percentage' as calculation_type,
            ? as calculation_value,
            0 as affects_net_salary_item,
            0 as is_summary_item,
            3 as item_display_order
            
        UNION ALL
        
        SELECT 
            'EMPLOYER CONTRIBUTIONS (INFO ONLY)' as category_name,
            4 as display_order,
            0 as affects_net_salary,
            1 as is_employer_contribution,
            'OSHA' as item_name,
            CONCAT(?, '% of Gross') as calculation_basis,
            'percentage' as calculation_type,
            ? as calculation_value,
            0 as affects_net_salary_item,
            0 as is_summary_item,
            4 as item_display_order
            
        UNION ALL
        
        SELECT 
            'EMPLOYER CONTRIBUTIONS (INFO ONLY)' as category_name,
            4 as display_order,
            0 as affects_net_salary,
            1 as is_employer_contribution,
            'TOTAL EMPLOYER COST' as item_name,
            'Gross + Employer costs' as calculation_basis,
            'summary' as calculation_type,
            NULL as calculation_value,
            0 as affects_net_salary_item,
            1 as is_summary_item,
            5 as item_display_order
        
        ORDER BY display_order, item_display_order
    ");
    
    $stmt->execute([
        $rates['nssf_employee_rate'],
        $rates['nssf_employee_rate'],
        $rates['enable_nhif'],
        $rates['nhif_rate'],
        $rates['nhif_rate'],
        $rates['nssf_employer_rate'],
        $rates['nssf_employer_rate'],
        $rates['sdl_rate'],
        $rates['sdl_rate'],
        $rates['wcf_rate'],
        $rates['wcf_rate'],
        $rates['osha_rate'],
        $rates['osha_rate']
    ]);
    
    $salary_structure = $stmt->fetchAll();
    
} catch (Exception $e) {
    // If database query fails, use default structure
    $salary_structure = [];
}

// Get month incentives for display
$month_incentives = [];
try {
    $stmt = $db->prepare("
        SELECT 
            incentive_type,
            SUM(amount) as total_amount
        FROM payroll_incentives 
        WHERE pay_period_month = ? 
        AND status = 'approved'
        GROUP BY incentive_type
    ");
    $stmt->execute([date('Y-m')]);
    $incentives = $stmt->fetchAll();
    foreach ($incentives as $inc) {
        $month_incentives[$inc['incentive_type']] = $inc['total_amount'];
    }
} catch (Exception $e) {
    $month_incentives = [];
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
            <button class="btn btn-info" onclick="window.location.href='?section=exemptions'">
                <i class="bi bi-person-slash me-2"></i>Manage Exemptions
            </button>
        </div>
    </div>

    <!-- Alerts -->
    <?php if (!empty($error_message)): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <i class="bi bi-exclamation-triangle me-2"></i>
            <?php echo htmlspecialchars($error_message); ?>
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
        <li class="nav-item">
            <a class="nav-link <?php echo $section === 'exemptions' ? 'active' : ''; ?>" 
               href="?section=exemptions">
                <i class="bi bi-person-slash me-1"></i>Deduction Exemptions
            </a>
        </li>
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
                                    <?php 
                                    $total_gross = $total_basic_salary;
                                    $total_deductions = 0;
                                    $total_employer_cost = $total_basic_salary;
                                    $current_category = '';
                                    $category_rowspan = 0;
                                    $category_items = 0;
                                    
                                    // First pass to count items per category
                                    $category_counts = [];
                                    foreach ($salary_structure as $item) {
                                        $category = $item['category_name'];
                                        if (!isset($category_counts[$category])) {
                                            $category_counts[$category] = 0;
                                        }
                                        $category_counts[$category]++;
                                    }
                                    
                                    // Display items
                                    foreach ($salary_structure as $item): 
                                        $category = $item['category_name'];
                                        $is_new_category = $category !== $current_category;
                                        $current_category = $category;
                                        
                                        // Determine row class based on category
                                        $row_class = '';
                                        if ($category === 'EARNINGS') $row_class = 'table-success';
                                        elseif ($category === 'DEDUCTIONS (EMPLOYEE)') $row_class = 'table-danger';
                                        elseif ($category === 'EMPLOYER CONTRIBUTIONS (INFO ONLY)') $row_class = 'table-warning';
                                        elseif ($category === 'NET PAY') $row_class = 'table-primary';
                                        
                                        // Calculate amounts
                                        $amount = 0;
                                        $amount_display = 'Variable';
                                        $calculation_info = htmlspecialchars($item['calculation_basis'] ?? '');
                                        
                                        switch ($item['item_name']) {
                                            case 'Basic Salary':
                                                $amount = $total_basic_salary;
                                                $amount_display = format_payroll_currency($amount);
                                                break;
                                                
                                            case 'Allowances':
                                                $amount = $month_incentives['allowance'] ?? 0;
                                                $total_gross += $amount;
                                                $amount_display = $amount > 0 ? format_payroll_currency($amount) : 'Variable';
                                                break;
                                                
                                            case 'Overtime':
                                                $amount = $month_incentives['overtime'] ?? 0;
                                                $total_gross += $amount;
                                                $rate_display = format_payroll_currency($rates['overtime_rate_per_hour']) . '/hr';
                                                $calculation_info = $rate_display;
                                                $amount_display = $amount > 0 ? format_payroll_currency($amount) : 'Variable';
                                                break;
                                                
                                            case 'Bonus':
                                                $amount = $month_incentives['bonus'] ?? 0;
                                                $total_gross += $amount;
                                                $amount_display = $amount > 0 ? format_payroll_currency($amount) : 'Variable';
                                                break;
                                                
                                            case 'GROSS SALARY':
                                                $amount = $total_gross;
                                                $amount_display = format_payroll_currency($amount);
                                                break;
                                                
                                            case 'NSSF (Employee)':
                                                $amount = ($total_basic_salary * $rates['nssf_employee_rate']) / 100;
                                                $total_deductions += $amount;
                                                $amount_display = format_payroll_currency($amount);
                                                break;
                                                
                                            case 'PAYE (Income Tax)':
                                                $amount = calculate_paye_tax($total_basic_salary);
                                                $total_deductions += $amount;
                                                $amount_display = format_payroll_currency($amount);
                                                break;
                                                
                                            case 'NHIF (Optional)':
                                                if ($rates['enable_nhif']) {
                                                    $amount = ($total_basic_salary * $rates['nhif_rate']) / 100;
                                                    $total_deductions += $amount;
                                                    $amount_display = format_payroll_currency($amount);
                                                } else {
                                                    $amount_display = '-';
                                                }
                                                break;
                                                
                                            case 'Loan / Other':
                                                $amount = $month_incentives['deduction'] ?? 0;
                                                $total_deductions += $amount;
                                                $amount_display = $amount > 0 ? format_payroll_currency($amount) : 'Variable';
                                                break;
                                                
                                            case 'TOTAL DEDUCTIONS':
                                                $amount = $total_deductions;
                                                $amount_display = format_payroll_currency($amount);
                                                break;
                                                
                                            case 'Net Salary':
                                                $amount = $total_gross - $total_deductions;
                                                $amount_display = format_payroll_currency($amount);
                                                break;
                                                
                                            case 'NSSF (Employer)':
                                                $amount = ($total_basic_salary * $rates['nssf_employer_rate']) / 100;
                                                $total_employer_cost += $amount;
                                                $amount_display = format_payroll_currency($amount);
                                                break;
                                                
                                            case 'SDL':
                                                $amount = ($total_gross * $rates['sdl_rate']) / 100;
                                                $total_employer_cost += $amount;
                                                $amount_display = format_payroll_currency($amount);
                                                break;
                                                
                                            case 'WCF':
                                                $amount = ($total_gross * $rates['wcf_rate']) / 100;
                                                $total_employer_cost += $amount;
                                                $amount_display = format_payroll_currency($amount);
                                                break;
                                                
                                            case 'OSHA':
                                                $amount = ($total_gross * $rates['osha_rate']) / 100;
                                                $total_employer_cost += $amount;
                                                $amount_display = format_payroll_currency($amount);
                                                break;
                                                
                                            case 'TOTAL EMPLOYER COST':
                                                $amount = $total_employer_cost;
                                                $amount_display = format_payroll_currency($amount);
                                                break;
                                        }
                                        
                                        // Determine amount display class
                                        $amount_class = '';
                                        if (strpos($item['item_name'], 'DEDUCTION') !== false) $amount_class = 'text-danger';
                                        elseif (strpos($item['item_name'], 'GROSS') !== false) $amount_class = 'text-primary';
                                        elseif (strpos($item['item_name'], 'NET') !== false) $amount_class = 'text-success';
                                        elseif (strpos($item['item_name'], 'COST') !== false) $amount_class = 'text-warning';
                                        
                                        // Check if item name contains summary keywords
                                        $is_summary = $item['is_summary_item'] || 
                                                     strpos($item['item_name'], 'GROSS') !== false ||
                                                     strpos($item['item_name'], 'TOTAL') !== false ||
                                                     strpos($item['item_name'], 'NET') !== false;
                                    ?>
                                    <tr class="<?php echo $row_class . ($is_summary ? ' fw-bold' : ''); ?>">
                                        <?php if ($is_new_category): ?>
                                        <td rowspan="<?php echo $category_counts[$category]; ?>" class="align-middle fw-bold">
                                            <?php echo htmlspecialchars($category); ?>
                                        </td>
                                        <?php endif; ?>
                                        
                                        <td class="<?php echo $is_summary ? 'text-primary' : ''; ?>">
                                            <?php echo htmlspecialchars($item['item_name']); ?>
                                            <?php if ($item['item_name'] === 'NHIF (Optional)' && !$rates['enable_nhif']): ?>
                                                <span class="badge bg-secondary ms-1">Disabled</span>
                                            <?php endif; ?>
                                        </td>
                                        
                                        <td><?php echo $calculation_info; ?></td>
                                        
                                        <td class="text-end <?php echo $amount_class; ?>">
                                            <?php echo $amount_display; ?>
                                        </td>
                                        
                                        <td class="text-center">
                                            <?php if ($item['affects_net_salary_item']): ?>
                                                <i class="bi bi-check-circle-fill text-success"></i> Yes
                                            <?php else: ?>
                                                <i class="bi bi-x-circle-fill text-danger"></i> No
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        
                        <!-- Summary Statistics -->
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
    
    <!-- Statutory Setup Section -->
    <?php elseif ($section === 'statutory'): ?>
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
                                    <strong>Total Employer Cost:</strong> <?php echo format_payroll_currency($salary_calculation['summary']['total_employer_cost']); ?>
                                </div>
                            </div>
                            
                            <!-- Payment Request Form -->
                            <div class="card border-primary">
                                <div class="card-header bg-primary">
                                    <h6 class="mb-0"><i class="bi bi-send-check me-2"></i>Generate Payment Request</h6>
                                </div>
                                <div class="card-body">
                                    <form method="POST">
                                        <div class="row mb-3">
                                            <div class="col-md-4">
                                                <label class="form-label">Payment Date</label>
                                                <input type="date" class="form-control" name="payment_date" 
                                                       value="<?php echo date('Y-m-d'); ?>" required>
                                            </div>
                                            <div class="col-md-4">
                                                <label class="form-label">Payment Method</label>
                                                <select class="form-select" name="payment_method" required>
                                                    <option value="bank_transfer">Bank Transfer</option>
                                                    <option value="mobile_money">Mobile Money</option>
                                                    <option value="cash">Cash</option>
                                                </select>
                                            </div>
                                            <div class="col-md-4">
                                                <label class="form-label">Total Payment</label>
                                                <input type="text" class="form-control bg-light" readonly 
                                                       value="<?php 
                                                           $net_salary = $salary_calculation['summary']['total_net_salary'] ?? 0;
                                                           $employer_contributions = ($salary_calculation['summary']['total_nssf_employer'] ?? 0) +
                                                                                   ($salary_calculation['summary']['total_sdl'] ?? 0) +
                                                                                   ($salary_calculation['summary']['total_wcf'] ?? 0) +
                                                                                   ($salary_calculation['summary']['total_osha'] ?? 0);
                                                           $total_payment = $net_salary + $employer_contributions;
                                                           echo format_payroll_currency($total_payment);
                                                       ?>">
                                                <small class="text-muted">Net Salary + Employer Contributions</small>
                                            </div>
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label">Notes (for approval)</label>
                                            <textarea class="form-control" name="notes" rows="3" 
                                                      placeholder="Add notes for CEO approval..."></textarea>
                                        </div>
                                        <div class="alert alert-warning">
                                            <i class="bi bi-exclamation-triangle me-2"></i>
                                            This will create a payment request for the total amount including employer contributions.
                                            The payment will be sent to the CEO for approval, then to Finance for processing.
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
    
    <!-- Deduction Exemptions Section -->
    <?php elseif ($section === 'exemptions'): ?>
        <div class="row">
            <div class="col-md-8">
                <div class="card shadow mb-4">
                    <div class="card-header bg-warning text-dark">
                        <h5 class="mb-0"><i class="bi bi-person-slash me-2"></i>Deduction Exemptions</h5>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($deduction_exemptions)): ?>
                            <div class="table-responsive">
                                <table class="table table-bordered table-hover">
                                    <thead class="table-warning">
                                        <tr>
                                            <th>Employee</th>
                                            <th>Deduction Type</th>
                                            <th>Exemption Type</th>
                                            <th>Value</th>
                                            <th>Effective Date</th>
                                            <th>Expiry Date</th>
                                            <th>Reason</th>
                                            <th>Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($deduction_exemptions as $exemption): ?>
                                            <tr>
                                                <td>
                                                    <strong><?php echo htmlspecialchars($exemption['full_name']); ?></strong>
                                                    <br><small class="text-muted"><?php echo htmlspecialchars($exemption['username']); ?></small>
                                                </td>
                                                <td>
                                                    <span class="badge bg-danger">
                                                        <?php echo strtoupper($exemption['deduction_type']); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <span class="badge bg-<?php echo $exemption['exemption_type'] === 'full' ? 'success' : 'info'; ?>">
                                                        <?php echo ucfirst($exemption['exemption_type']); ?>
                                                    </span>
                                                </td>
                                                <td class="text-end">
                                                    <?php if ($exemption['exemption_type'] === 'partial' && $exemption['exemption_value']): ?>
                                                        <?php echo format_payroll_currency($exemption['exemption_value']); ?>
                                                    <?php else: ?>
                                                        -
                                                    <?php endif; ?>
                                                </td>
                                                <td><?php echo date('d/m/Y', strtotime($exemption['effective_date'])); ?></td>
                                                <td>
                                                    <?php echo $exemption['expiry_date'] ? date('d/m/Y', strtotime($exemption['expiry_date'])) : 'No expiry'; ?>
                                                </td>
                                                <td><?php echo htmlspecialchars($exemption['reason'] ?? 'N/A'); ?></td>
                                                <td>
                                                    <form method="POST" class="d-inline">
                                                        <input type="hidden" name="exemption_id" value="<?php echo $exemption['id']; ?>">
                                                        <button type="submit" name="remove_exemption" 
                                                                class="btn btn-sm btn-danger"
                                                                onclick="return confirm('Remove this exemption?')">
                                                            <i class="bi bi-trash"></i> Remove
                                                        </button>
                                                    </form>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="text-center py-4">
                                <i class="bi bi-person-slash display-4 text-muted mb-3"></i>
                                <h5>No Active Exemptions</h5>
                                <p class="text-muted">No deduction exemptions have been configured.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <div class="col-md-4">
                <div class="card shadow">
                    <div class="card-header bg-info">
                        <h5 class="mb-0"><i class="bi bi-plus-circle me-2"></i>Add New Exemption</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST" id="exemptionForm">
                            <div class="mb-3">
                                <label class="form-label">Employee</label>
                                <select class="form-select" name="user_id" required>
                                    <option value="">Select Employee</option>
                                    <?php foreach ($employees as $emp): ?>
                                        <option value="<?php echo $emp['id']; ?>">
                                            <?php echo htmlspecialchars($emp['full_name'] . ' (' . $emp['username'] . ')'); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">Deduction Type</label>
                                <select class="form-select" name="deduction_type" id="deductionType" required>
                                    <option value="">Select Deduction</option>
                                    <option value="nssf">NSSF (Employee)</option>
                                    <option value="paye">PAYE (Income Tax)</option>
                                    <option value="nhif">NHIF</option>
                                    <option value="loan">Loan / Other</option>
                                    <option value="all">All Deductions</option>
                                </select>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">Exemption Type</label>
                                <select class="form-select" name="exemption_type" id="exemptionType" required>
                                    <option value="">Select Type</option>
                                    <option value="full">Full Exemption</option>
                                    <option value="partial">Partial Exemption</option>
                                </select>
                            </div>
                            
                            <div class="mb-3" id="exemptionValueDiv" style="display: none;">
                                <label class="form-label">Exemption Value (TZS)</label>
                                <input type="number" class="form-control" name="exemption_value" 
                                       step="0.01" min="0" placeholder="Enter amount">
                                <small class="text-muted">Amount to deduct from the deduction</small>
                            </div>
                            
                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <label class="form-label">Effective Date</label>
                                    <input type="date" class="form-control" name="effective_date" 
                                           value="<?php echo date('Y-m-d'); ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Expiry Date (Optional)</label>
                                    <input type="date" class="form-control" name="expiry_date">
                                    <small class="text-muted">Leave empty for no expiry</small>
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">Reason</label>
                                <textarea class="form-control" name="reason" rows="3" 
                                          placeholder="Reason for exemption..." required></textarea>
                            </div>
                            
                            <div class="text-end">
                                <button type="submit" name="save_deduction_exemption" class="btn btn-primary">
                                    <i class="bi bi-save me-2"></i>Save Exemption
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    
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
                                    <th>Total Payment</th>
                                    <th>Status</th>
                                    <th>Requested By</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($salary_payments as $payment): ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($payment['request_no']); ?></strong></td>
                                        <td><?php echo date('d/m/Y', strtotime($payment['requested_at'])); ?></td>
                                        <td>
                                            <?php 
                                            if (preg_match('/Salary Payment - (\w+ \d{4})/', $payment['subject'], $matches)) {
                                                echo $matches[1];
                                            } else {
                                                echo 'N/A';
                                            }
                                            ?>
                                        </td>
                                        <td class="text-center"><?php echo $payment['total_employees'] ?? 0; ?></td>
                                        <td class="text-end">
                                            <strong class="text-success"><?php echo format_payroll_currency($payment['total_payment'] ?? $payment['amount_paid']); ?></strong>
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
                                        <td><?php echo htmlspecialchars($payment['requested_by_name']); ?></td>
                                        <td>
                                            <div class="btn-group btn-group-sm">
                                                <a href="payment_request.php?request_id=<?php echo $payment['id']; ?>" 
                                                   class="btn btn-outline-primary" title="View Details">
                                                    <i class="bi bi-eye"></i>
                                                </a>
                                                <a href="view_salary_calculation.php?payment_id=<?php echo $payment['id']; ?>" 
                                                   class="btn btn-outline-info" title="View Calculation">
                                                    <i class="bi bi-calculator"></i>
                                                </a>
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
                        Calculation includes employee exemptions and all statutory deductions.
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
    
    // Exemption type toggle
    const exemptionType = document.getElementById('exemptionType');
    const exemptionValueDiv = document.getElementById('exemptionValueDiv');
    
    if (exemptionType && exemptionValueDiv) {
        exemptionType.addEventListener('change', function() {
            exemptionValueDiv.style.display = this.value === 'partial' ? 'block' : 'none';
            if (this.value !== 'partial') {
                exemptionValueDiv.querySelector('input').value = '';
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
    
    // Form validation for exemption
    const exemptionForm = document.getElementById('exemptionForm');
    if (exemptionForm) {
        exemptionForm.addEventListener('submit', function(e) {
            const exemptionType = document.getElementById('exemptionType').value;
            const exemptionValue = document.querySelector('input[name="exemption_value"]');
            
            if (exemptionType === 'partial') {
                if (!exemptionValue.value || parseFloat(exemptionValue.value) <= 0) {
                    e.preventDefault();
                    alert('Please enter a valid exemption value for partial exemption.');
                    exemptionValue.focus();
                }
            }
        });
    }
});
</script>

<?php
include '../includes/footer.php';
$db = null;
?>