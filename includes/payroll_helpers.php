<?php
// Payroll-specific helper functions
// DO NOT include functions that are already defined in config.php

/**
 * Calculate tax amount based on Tanzanian tax brackets
 */
function calculate_tax_amount($gross_pay, $user_id = null) {
    // Tanzanian tax brackets (2024 rates)
    $tax_brackets = [
        ['min' => 0, 'max' => 270000, 'rate' => 0.00],
        ['min' => 270001, 'max' => 520000, 'rate' => 0.08],
        ['min' => 520001, 'max' => 760000, 'rate' => 0.20],
        ['min' => 760001, 'max' => 1000000, 'rate' => 0.25],
        ['min' => 1000001, 'max' => PHP_INT_MAX, 'rate' => 0.30]
    ];
    
    $annual_salary = $gross_pay * 12;
    $tax = 0;
    
    foreach ($tax_brackets as $bracket) {
        if ($annual_salary > $bracket['min']) {
            $taxable_in_bracket = min($annual_salary, $bracket['max']) - $bracket['min'];
            $tax += $taxable_in_bracket * $bracket['rate'];
        }
        if ($annual_salary <= $bracket['max']) break;
    }
    
    // Return monthly tax rounded to 2 decimals
    return round($tax / 12, 2);
}

/**
 * Calculate social security amount
 */
function calculate_social_security($basic_salary) {
    $max_monthly = 2000000 / 12; // NSSF annual maximum / 12 months
    $contributable = min($basic_salary, $max_monthly);
    return round($contributable * 0.10, 2); // 10% employee contribution
}

/**
 * Get payroll status display name
 */
function get_payroll_status_display($status) {
    if (function_exists('get_status_display_name')) {
        return get_status_display_name($status);
    }
    
    // Fallback if function doesn't exist
    $status_names = [
        'draft' => 'Draft',
        'calculated' => 'Calculated',
        'pending_ceo_approval' => 'Pending CEO Approval',
        'ceo_approved' => 'CEO Approved',
        'ready_for_payment' => 'Ready for Payment',
        'paid' => 'Paid',
        'rejected' => 'Rejected'
    ];
    return $status_names[$status] ?? ucfirst(str_replace('_', ' ', $status));
}

/**
 * Get payroll status badge class
 */
function get_payroll_status_badge($status) {
    if (function_exists('get_status_badge_class')) {
        return get_status_badge_class($status);
    }
    
    // Fallback if function doesn't exist
    $badge_classes = [
        'draft' => 'bg-secondary',
        'calculated' => 'bg-warning',
        'pending_ceo_approval' => 'bg-danger',
        'ceo_approved' => 'bg-success',
        'ready_for_payment' => 'bg-info',
        'paid' => 'bg-primary',
        'rejected' => 'bg-danger'
    ];
    return $badge_classes[$status] ?? 'bg-secondary';
}

/**
 * Get incentive type display
 */
function get_incentive_type_display($type) {
    $types = [
        'bonus' => 'Bonus',
        'overtime' => 'Overtime',
        'allowance' => 'Allowance',
        'commission' => 'Commission',
        'deduction' => 'Deduction',
        'other' => 'Other'
    ];
    return $types[$type] ?? ucfirst($type);
}

/**
 * Get incentive status badge
 */
function get_incentive_status_badge($status) {
    $classes = [
        'pending' => 'bg-warning',
        'approved' => 'bg-success',
        'paid' => 'bg-primary',
        'rejected' => 'bg-danger'
    ];
    return $classes[$status] ?? 'bg-secondary';
}

/**
 * Format currency for payroll display
 */
function format_payroll_currency($amount, $currency = 'TZS') {
    $amount = $amount ?? 0;
    $currency = $currency ?? 'TZS';
    
    // Use the existing format_currency function from config.php
    if (function_exists('format_currency')) {
        return format_currency($amount) . ' ' . $currency;
    }
    
    // Fallback if function doesn't exist
    return number_format((float)$amount, 2) . ' ' . $currency;
}

/**
 * Calculate salary change percentage
 */
function calculate_salary_change_percent($old_salary, $new_salary) {
    $old_salary = (float)($old_salary ?? 0);
    $new_salary = (float)($new_salary ?? 0);
    
    if ($old_salary == 0) return 0;
    return round((($new_salary - $old_salary) / $old_salary) * 100, 2);
}

/**
 * Get salary levels array
 */
function get_salary_levels() {
    $salary_levels = [
        'Entry' => 'Entry Level',
        'Junior' => 'Junior',
        'Mid' => 'Mid Level',
        'Senior' => 'Senior',
        'Lead' => 'Lead',
        'Manager' => 'Manager',
        'Director' => 'Director',
        'Executive' => 'Executive'
    ];
    return $salary_levels;
}

/**
 * HR submits payroll for CEO approval
 */
function hr_submit_payroll_for_approval($payroll_ids, $hr_user_id, $submission_reason) {
    global $db;
    
    try {
        $db->beginTransaction();
        
        $success_count = 0;
        foreach ($payroll_ids as $payroll_id) {
            $payroll_id = (int)$payroll_id;
            
            // Update payroll status
            $stmt = $db->prepare("
                UPDATE payroll 
                SET status = ?, hr_submitted_at = NOW(), submission_reason = ?
                WHERE id = ? AND status IN (?, ?)
            ");
            
            if ($stmt->execute([
                'pending_ceo_approval',
                $submission_reason,
                $payroll_id,
                'draft',
                'calculated'
            ])) {
                if ($stmt->rowCount() > 0) {
                    $success_count++;
                    
                    // Update workflow if functions exist
                    if (function_exists('get_approval_workflow') && function_exists('update_approval_workflow_status')) {
                        $workflow = get_approval_workflow('payroll', $payroll_id);
                        if ($workflow) {
                            update_approval_workflow_status(
                                $workflow['id'], 
                                'pending_ceo_approval', 
                                'hr', 
                                $hr_user_id, 
                                "Submitted for CEO approval: " . substr($submission_reason, 0, 100)
                            );
                        }
                    }
                    
                    // Log activity
                    $activity_stmt = $db->prepare("
                        INSERT INTO hr_activities (activity_type, entity_type, entity_id, description, performed_by)
                        VALUES ('payroll_submitted', 'payroll', ?, ?, ?)
                    ");
                    $activity_stmt->execute([
                        $payroll_id,
                        "Payroll submitted for CEO approval",
                        $hr_user_id
                    ]);
                }
            }
        }
        
        $db->commit();
        
        return [
            'success' => true,
            'message' => "{$success_count} payroll records submitted for CEO approval."
        ];
        
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        return [
            'success' => false,
            'message' => 'Database error: ' . $e->getMessage()
        ];
    }
}

/**
 * Get payroll details with items
 */
function get_payroll_details($payroll_id) {
    global $db;
    
    try {
        // Get payroll main record
        $stmt = $db->prepare("
            SELECT p.*, 
                   u.full_name, u.username, u.job_title,
                   hr.full_name as hr_submitted_by_name,
                   ceo.full_name as ceo_approved_by_name,
                   finance.full_name as finance_processed_by_name
            FROM payroll p
            JOIN users u ON p.user_id = u.id
            LEFT JOIN users hr ON p.processed_by = hr.id
            LEFT JOIN users ceo ON p.ceo_approved_by = ceo.id
            LEFT JOIN users finance ON p.finance_processed_by = finance.id
            WHERE p.id = ?
        ");
        $stmt->execute([$payroll_id]);
        $payroll = $stmt->fetch();
        
        if (!$payroll) {
            return null;
        }
        
        // Get payroll items
        $items_stmt = $db->prepare("
            SELECT * FROM payroll_items 
            WHERE payroll_id = ?
            ORDER BY 
                CASE item_type 
                    WHEN 'salary' THEN 1
                    WHEN 'allowance' THEN 2
                    WHEN 'bonus' THEN 3
                    WHEN 'overtime' THEN 4
                    WHEN 'commission' THEN 5
                    WHEN 'deduction' THEN 6
                    WHEN 'tax' THEN 7
                    WHEN 'social_security' THEN 8
                    ELSE 9
                END
        ");
        $items_stmt->execute([$payroll_id]);
        $payroll['items'] = $items_stmt->fetchAll();
        
        return $payroll;
        
    } catch (Exception $e) {
        return null;
    }
}
















// payroll_helpers.php
function generate_salary_preview($pay_period_month) {
    global $db;
    
    $preview_data = [
        'employees' => [],
        'summary' => [
            'total_employees' => 0,
            'total_basic' => 0,
            'total_allowances' => 0,
            'total_overtime' => 0,
            'total_bonus' => 0,
            'total_gross' => 0,
            'total_employee_deductions' => 0,
            'total_employer_contributions' => 0,
            'total_net' => 0,
            'total_employer_cost' => 0,
            'deduction_breakdown' => [],
            'total_employer_nssf' => 0,
            'total_employer_sdl' => 0,
            'total_employer_wcf' => 0,
            'total_employer_osha' => 0
        ]
    ];
    
    // Get all active employees
    $employee_stmt = $db->query("
        SELECT u.*, d.department_name
        FROM users u
        LEFT JOIN departments d ON u.department_id = d.id
        WHERE u.status = 'active'
        AND u.role NOT IN ('system_admin', 'ceo')
        ORDER BY u.full_name
    ");
    $employees = $employee_stmt->fetchAll();
    
    // Get all deduction types
    $deduction_stmt = $db->query("
        SELECT dt.*, de.user_id
        FROM deduction_types dt
        LEFT JOIN deduction_employees de ON dt.id = de.deduction_type_id 
            AND de.status = 'active'
        WHERE dt.status = 'active'
    ");
    $all_deductions = $deduction_stmt->fetchAll();
    
    // Organize deductions by employee
    $employee_deductions = [];
    foreach ($all_deductions as $ded) {
        if ($ded['applies_to'] === 'all') {
            // Apply to all employees
            foreach ($employees as $emp) {
                $employee_deductions[$emp['id']][] = $ded;
            }
        } elseif ($ded['user_id']) {
            // Apply to specific employee
            $employee_deductions[$ded['user_id']][] = $ded;
        }
    }
    
    foreach ($employees as $employee) {
        $basic_salary = (float)$employee['salary'];
        $user_id = $employee['id'];
        
        // Get approved incentives for this month
        $incentive_stmt = $db->prepare("
            SELECT incentive_type, SUM(amount) as total_amount 
            FROM payroll_incentives 
            WHERE user_id = ? 
            AND pay_period_month = ? 
            AND status = 'approved'
            GROUP BY incentive_type
        ");
        $incentive_stmt->execute([$user_id, $pay_period_month]);
        $incentives = $incentive_stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        
        // Calculate earnings
        $allowances = (float)($incentives['allowance'] ?? 0);
        $overtime = (float)($incentives['overtime'] ?? 0);
        $bonus = (float)($incentives['bonus'] ?? 0);
        $commission = (float)($incentives['commission'] ?? 0);
        $gross_salary = $basic_salary + $allowances + $overtime + $bonus + $commission;
        
        // Calculate deductions
        $deductions = [];
        $total_employee_deductions = 0;
        $total_employer_contributions = 0;
        
        $emp_deductions = $employee_deductions[$user_id] ?? [];
        foreach ($emp_deductions as $ded) {
            if ($ded['deduction_type'] === 'percentage') {
                $amount = ($basic_salary * $ded['deduction_value']) / 100;
            } else {
                $amount = $ded['deduction_value'];
            }
            
            if ($ded['is_employer_contribution']) {
                $total_employer_contributions += $amount;
                $preview_data['summary']['total_employer_contributions'] += $amount;
                
                // Add to employer breakdown
                if (!isset($preview_data['summary']['deduction_breakdown'][$ded['deduction_name']])) {
                    $preview_data['summary']['deduction_breakdown'][$ded['deduction_name']] = 0;
                }
                $preview_data['summary']['deduction_breakdown'][$ded['deduction_name']] += $amount;
            } else {
                $deductions[$ded['deduction_name']] = $amount;
                $total_employee_deductions += $amount;
                $preview_data['summary']['total_employee_deductions'] += $amount;
                
                // Add to employee deduction breakdown
                if (!isset($preview_data['summary']['deduction_breakdown'][$ded['deduction_name']])) {
                    $preview_data['summary']['deduction_breakdown'][$ded['deduction_name']] = 0;
                }
                $preview_data['summary']['deduction_breakdown'][$ded['deduction_name']] += $amount;
            }
        }
        
        // Calculate statutory deductions
        $nssf_employee = calculate_nssf($basic_salary);
        $paye = calculate_paye($basic_salary + $allowances + $bonus);
        $nhif = calculate_nhif($basic_salary + $allowances + $bonus);
        
        // Add statutory to deductions
        $deductions['NSSF (Employee)'] = $nssf_employee;
        $deductions['PAYE'] = $paye;
        $deductions['NHIF'] = $nhif;
        
        $total_employee_deductions += $nssf_employee + $paye + $nhif;
        $preview_data['summary']['total_employee_deductions'] += $nssf_employee + $paye + $nhif;
        
        // Calculate employer statutory contributions
        $nssf_employer = calculate_nssf_employer($basic_salary);
        $sdl = calculate_sdl($gross_salary);
        $wcf = calculate_wcf($gross_salary);
        $osha = calculate_osha($gross_salary);
        
        $total_employer_contributions += $nssf_employer + $sdl + $wcf + $osha;
        $preview_data['summary']['total_employer_contributions'] += $nssf_employer + $sdl + $wcf + $osha;
        
        // Add to employer totals
        $preview_data['summary']['total_employer_nssf'] += $nssf_employer;
        $preview_data['summary']['total_employer_sdl'] += $sdl;
        $preview_data['summary']['total_employer_wcf'] += $wcf;
        $preview_data['summary']['total_employer_osha'] += $osha;
        
        $net_salary = $gross_salary - $total_employee_deductions;
        $total_cost_to_employer = $gross_salary + $total_employer_contributions;
        
        // Add employee data
        $preview_data['employees'][] = [
            'id' => $user_id,
            'full_name' => $employee['full_name'],
            'job_title' => $employee['job_title'],
            'department' => $employee['department_name'],
            'basic_salary' => $basic_salary,
            'allowances' => $allowances,
            'overtime' => $overtime,
            'bonus' => $bonus,
            'commission' => $commission,
            'gross_salary' => $gross_salary,
            'deductions' => $deductions,
            'total_deductions' => $total_employee_deductions,
            'net_salary' => $net_salary,
            'employer_contributions' => [
                'nssf' => $nssf_employer,
                'sdl' => $sdl,
                'wcf' => $wcf,
                'osha' => $osha,
                'other' => $total_employer_contributions - ($nssf_employer + $sdl + $wcf + $osha)
            ],
            'total_employer_contributions' => $total_employer_contributions,
            'total_cost_to_employer' => $total_cost_to_employer
        ];
        
        // Update summary totals
        $preview_data['summary']['total_employees']++;
        $preview_data['summary']['total_basic'] += $basic_salary;
        $preview_data['summary']['total_allowances'] += $allowances;
        $preview_data['summary']['total_overtime'] += $overtime;
        $preview_data['summary']['total_bonus'] += $bonus;
        $preview_data['summary']['total_gross'] += $gross_salary;
        $preview_data['summary']['total_net'] += $net_salary;
        $preview_data['summary']['total_employer_cost'] += $total_cost_to_employer;
    }
    
    return $preview_data;
}

function create_payment_request_from_preview($preview_id, $created_by) {
    global $db;
    
    // Get preview data
    $preview_stmt = $db->prepare("
        SELECT sp.*, sp.preview_data
        FROM salary_previews sp
        WHERE sp.id = ?
    ");
    $preview_stmt->execute([$preview_id]);
    $preview = $preview_stmt->fetch();
    
    if (!$preview) {
        throw new Exception('Preview not found');
    }
    
    $preview_data = json_decode($preview['preview_data'], true);
    
    // Generate payment request number
    $request_no = generate_payment_request_no();
    
    // Calculate total amount (net salary)
    $total_amount = $preview_data['summary']['total_net'] ?? 0;
    
    // Create payment request
    $payment_stmt = $db->prepare("
        INSERT INTO pending_pay (
            request_no, subject, pay_to_type, payee_id, payee_name,
            payee_bank_name, payee_branch, payee_account_name,
            payee_account_no, currency, amount_paid, cheque_no,
            payment_description, requested_by, status, salary_preview_id
        ) VALUES (?, ?, 'O', ?, ?, ?, ?, ?, ?, 'TZS', ?, ?, ?, ?, 'pending', ?)
    ");
    
    $subject = "Salary Payment - " . date('F Y', strtotime($preview['pay_period_month'] . '-01'));
    $payee_id = 'SALARY'; // Special code for salary payments
    $payee_name = 'Employee Salaries';
    $description = "Salary payment for " . date('F Y', strtotime($preview['pay_period_month'] . '-01')) . 
                   " - " . $preview_data['summary']['total_employees'] . " employees";
    
    $payment_stmt->execute([
        $request_no,
        $subject,
        $payee_id,
        $payee_name,
        '', // bank name
        '', // branch
        'Various Accounts', // account name
        'Various', // account number
        $total_amount,
        '', // cheque no
        $description,
        $created_by,
        $preview_id
    ]);
    
    $payment_id = $db->lastInsertId();
    
    // Create detailed payment records for each employee
    foreach ($preview_data['employees'] ?? [] as $employee) {
        $detail_stmt = $db->prepare("
            INSERT INTO payment_details (
                payment_request_id, user_id, employee_name, job_title,
                basic_salary, allowances, overtime, bonus, gross_salary,
                total_deductions, net_salary, bank_name, account_number,
                payment_method
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'bank_transfer')
        ");
        
        $detail_stmt->execute([
            $payment_id,
            $employee['id'],
            $employee['full_name'],
            $employee['job_title'],
            $employee['basic_salary'],
            $employee['allowances'],
            $employee['overtime'],
            $employee['bonus'],
            $employee['gross_salary'],
            $employee['total_deductions'],
            $employee['net_salary'],
            'Various Banks',
            'Various Accounts'
        ]);
    }
    
    return $payment_id;
}

function generate_payment_request_no() {
    global $db;
    
    $prefix = 'SAL';
    $year = date('Y');
    $month = date('m');
    
    // Get last salary payment request number for this month
    $stmt = $db->prepare("
        SELECT request_no FROM pending_pay 
        WHERE request_no LIKE ? 
        AND subject LIKE 'Salary Payment%'
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

// Other helper functions remain the same as before...
// calculate_nssf, calculate_paye, calculate_nhif, calculate_sdl, calculate_wcf, calculate_osha
?>