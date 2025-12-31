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
?>