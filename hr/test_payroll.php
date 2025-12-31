<?php
require_once '../config/config.php';
require_once '../auth/auth_middleware.php';
require_once '../includes/approval_helpers.php';
require_once '../config/approval_constants.php';

require_hr();
$db = getDBConnection();

echo "Testing payroll generation...\n";

// Simulate POST data
$_POST['pay_period_start'] = '2024-12-01';
$_POST['pay_period_end'] = '2024-12-31';
$_POST['employee_ids'] = ['15']; // Employee with salary

$_POST['generate_payroll'] = '1';

// Simulate session
$_SESSION['user_id'] = 1; // Assuming admin user

// Include the payroll generation logic
if (isset($_POST['generate_payroll'])) {
    $pay_period_start = $_POST['pay_period_start'];
    $pay_period_end = $_POST['pay_period_end'];
    $selected_employees = $_POST['employee_ids'] ?? [];

    echo "Selected employees: " . implode(', ', $selected_employees) . "\n";

    if (empty($selected_employees)) {
        echo "ERROR: No employees selected\n";
        exit;
    }

    $generated_count = 0;
    $db->beginTransaction();

    foreach ($selected_employees as $employee_id) {
        echo "Processing employee ID: $employee_id\n";

        // Check if payroll already exists
        $check_stmt = $db->prepare("
            SELECT id FROM payroll
            WHERE employee_id = ? AND pay_period_start = ? AND pay_period_end = ?
        ");
        $check_stmt->execute([$employee_id, $pay_period_start, $pay_period_end]);

        if (!$check_stmt->fetch()) {
            echo "No existing payroll found, creating new one...\n";

            // Get employee basic salary and benefits
            $emp_stmt = $db->prepare("
                SELECT u.salary, u.currency,
                       COALESCE(SUM(CASE WHEN eb.benefit_type IN ('allowance', 'bonus')
                                        THEN eb.amount ELSE 0 END), 0) as total_allowances,
                       COALESCE(SUM(CASE WHEN eb.benefit_type NOT IN ('allowance', 'bonus')
                                        THEN eb.amount ELSE 0 END), 0) as total_deductions
                FROM users u
                LEFT JOIN employee_benefits eb ON u.id = eb.employee_id
                    AND eb.is_active = 1
                    AND (eb.end_date IS NULL OR eb.end_date >= ?)
                WHERE u.id = ?
                GROUP BY u.id
            ");
            $emp_stmt->execute([$pay_period_start, $employee_id]);
            $employee = $emp_stmt->fetch();

            if ($employee) {
                echo "Employee data found: " . json_encode($employee) . "\n";

                $basic_salary = $employee['salary'];
                $total_allowances = $employee['total_allowances'];
                $total_deductions = $employee['total_deductions'];

                $gross_pay = $basic_salary + $total_allowances;
                $tax_amount = 0;
                $social_security = 0;
                $net_pay = $gross_pay - $total_deductions;

                echo "Calculated: gross_pay=$gross_pay, net_pay=$net_pay\n";

                // Insert payroll record
                $payroll_stmt = $db->prepare("
                    INSERT INTO payroll (employee_id, pay_period_start, pay_period_end,
                                       basic_salary, total_allowances, total_deductions,
                                       gross_pay, tax_amount, social_security_amount,
                                       net_pay, currency, processed_by, status)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");

                $result = $payroll_stmt->execute([
                    $employee_id, $pay_period_start, $pay_period_end,
                    $basic_salary, $total_allowances, $total_deductions,
                    $gross_pay, $tax_amount, $social_security,
                    $net_pay, $employee['currency'], $_SESSION['user_id'], PAYROLL_DRAFT
                ]);

                if ($result) {
                    echo "Payroll record inserted successfully\n";
                    $payroll_id = $db->lastInsertId();
                    $generated_count++;

                    // Create workflow
                    $workflow_id = create_approval_workflow(
                        WORKFLOW_PAYROLL, ENTITY_PAYROLL, $payroll_id,
                        $_SESSION['user_id'], PAYROLL_DRAFT
                    );

                    echo "Workflow created: $workflow_id\n";
                } else {
                    echo "ERROR: Failed to insert payroll record\n";
                }
            } else {
                echo "ERROR: Employee data not found\n";
            }
        } else {
            echo "Payroll already exists for this period\n";
        }
    }

    $db->commit();
    echo "Generated $generated_count payroll records\n";
} else {
    echo "generate_payroll not set\n";
}
?>