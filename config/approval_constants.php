<?php
/**
 * Approval Workflow Status Constants
 * 
 * This file defines all status constants used throughout the approval workflows
 * for HR modules. This ensures consistency and makes status changes easier to manage.
 */

// ===============================================
// GENERAL APPROVAL CONSTANTS
// ===============================================

// Basic approval decisions
define('APPROVAL_PENDING', 'pending');
define('APPROVAL_APPROVED', 'approved');
define('APPROVAL_REJECTED', 'rejected');
define('APPROVAL_ESCALATED', 'escalated');
define('APPROVAL_CANCELLED', 'cancelled');

// Workflow statuses
define('WORKFLOW_IN_PROGRESS', 'in_progress');
define('WORKFLOW_COMPLETED', 'completed');
define('WORKFLOW_REJECTED', 'rejected');
define('WORKFLOW_CANCELLED', 'cancelled');

// ===============================================
// LEAVE REQUEST WORKFLOW CONSTANTS
// ===============================================

// Leave request statuses
define('LEAVE_PENDING', 'pending');
define('LEAVE_APPROVED', 'approved');
define('LEAVE_REJECTED', 'rejected');
define('LEAVE_CANCELLED', 'cancelled');

// HR decision statuses
define('LEAVE_PENDING_HR', 'pending_hr');
define('LEAVE_FINALIZED_BY_HR', 'finalized_by_hr');
define('LEAVE_ESCALATED_TO_CEO', 'escalated_to_ceo');

// CEO decision statuses  
define('LEAVE_PENDING_CEO', 'pending_ceo');
define('LEAVE_CEO_APPROVED', 'ceo_approved');
define('LEAVE_CEO_REJECTED', 'ceo_rejected');

// Combined workflow statuses
define('LEAVE_APPROVED_BY_HR', 'approved_by_hr');
define('LEAVE_REJECTED_BY_HR', 'rejected_by_hr');
define('LEAVE_ESCALATED_PENDING_CEO', 'escalated_pending_ceo');
define('LEAVE_FINAL_APPROVED', 'final_approved');
define('LEAVE_FINAL_REJECTED', 'final_rejected');

// ===============================================
// PAYROLL WORKFLOW CONSTANTS  
// ===============================================

// Payroll processing stages
define('PAYROLL_DRAFT', 'draft');
define('PAYROLL_CALCULATED', 'calculated');
define('PAYROLL_SUBMITTED_FOR_APPROVAL', 'submitted_for_approval');
define('PAYROLL_PENDING_CEO_APPROVAL', 'pending_ceo_approval');
define('PAYROLL_CEO_APPROVED', 'ceo_approved');
define('PAYROLL_READY_FOR_PAYMENT', 'ready_for_payment');
define('PAYROLL_PAID', 'paid');
define('PAYROLL_REJECTED', 'rejected');

// Payroll action statuses
define('PAYROLL_HR_SUBMITTED', 'hr_submitted');
define('PAYROLL_CEO_DECISION_PENDING', 'ceo_decision_pending');
define('PAYROLL_FINANCE_PROCESSING', 'finance_processing');
define('PAYROLL_PAYMENT_COMPLETED', 'payment_completed');

// ===============================================
// RECRUITMENT WORKFLOW CONSTANTS
// ===============================================

// Job application statuses (existing)
define('APPLICATION_RECEIVED', 'received');
define('APPLICATION_REVIEWING', 'reviewing');
define('APPLICATION_SHORTLISTED', 'shortlisted');
define('APPLICATION_INTERVIEWED', 'interviewed');
define('APPLICATION_SELECTED', 'selected');
define('APPLICATION_REJECTED', 'rejected');
define('APPLICATION_HIRED', 'hired');

// HR recommendation statuses
define('HR_NOT_RECOMMENDED', 'not_recommended');
define('HR_RECOMMENDED', 'recommended');
define('HR_STRONGLY_RECOMMENDED', 'strongly_recommended');

// CEO hiring approval statuses
define('HIRE_PENDING_CEO', 'pending_ceo');
define('HIRE_CEO_APPROVED', 'ceo_approved');
define('HIRE_CEO_REJECTED', 'ceo_rejected');

// ===============================================
// PERFORMANCE TARGETS WORKFLOW CONSTANTS
// ===============================================

// Target statuses (existing)
define('TARGET_ACTIVE', 'active');
define('TARGET_COMPLETED', 'completed');
define('TARGET_CANCELLED', 'cancelled');

// Target approval statuses
define('TARGET_DRAFT', 'draft');
define('TARGET_PENDING_CEO_APPROVAL', 'pending_ceo_approval');
define('TARGET_CEO_APPROVED', 'ceo_approved');
define('TARGET_CEO_REJECTED', 'ceo_rejected');

// ===============================================
// EMPLOYEE CHANGE WORKFLOW CONSTANTS
// ===============================================

// Employee statuses (existing)
define('EMPLOYEE_ACTIVE', 'active');
define('EMPLOYEE_TERMINATED', 'terminated');
define('EMPLOYEE_SUSPENDED', 'suspended');
define('EMPLOYEE_ON_LEAVE', 'on_leave');

// Employee change approval statuses
define('EMPLOYEE_CHANGE_PENDING', 'change_pending');
define('EMPLOYEE_CHANGE_CEO_APPROVED', 'change_ceo_approved');
define('EMPLOYEE_CHANGE_CEO_REJECTED', 'change_ceo_rejected');
define('EMPLOYEE_CHANGE_EFFECTIVE', 'change_effective');

// ===============================================
// WORKFLOW TYPE CONSTANTS
// ===============================================

define('WORKFLOW_LEAVE', 'leave');
define('WORKFLOW_PAYROLL', 'payroll');
define('WORKFLOW_RECRUITMENT', 'recruitment');
define('WORKFLOW_TARGET', 'target');
define('WORKFLOW_EMPLOYEE_CHANGE', 'employee_change');

// ===============================================
// ENTITY TYPE CONSTANTS  
// ===============================================

define('ENTITY_LEAVE_REQUEST', 'leave_request');
define('ENTITY_PAYROLL', 'payroll');
define('ENTITY_JOB_APPLICATION', 'job_application');
define('ENTITY_PERFORMANCE_TARGET', 'performance_target');
define('ENTITY_EMPLOYEE', 'employee');

// ===============================================
// APPROVAL STAGE CONSTANTS
// ===============================================

define('STAGE_HR', 'hr');
define('STAGE_CEO', 'ceo');
define('STAGE_FINANCE', 'finance');

// ===============================================
// NOTIFICATION TYPE CONSTANTS
// ===============================================

define('NOTIFICATION_ESCALATION', 'escalation');
define('NOTIFICATION_APPROVAL_REQUIRED', 'approval_required');
define('NOTIFICATION_APPROVED', 'approved');
define('NOTIFICATION_REJECTED', 'rejected');
define('NOTIFICATION_REMINDER', 'reminder');

// ===============================================
// AUDIT ACTION CONSTANTS
// ===============================================

define('AUDIT_LEAVE_SUBMITTED', 'leave_submitted');
define('AUDIT_LEAVE_FINALIZED', 'leave_finalized');
define('AUDIT_LEAVE_ESCALATED', 'leave_escalated');
define('AUDIT_LEAVE_CEO_DECISION', 'leave_ceo_decision');

define('AUDIT_PAYROLL_SUBMITTED', 'payroll_submitted');
define('AUDIT_PAYROLL_CEO_DECISION', 'payroll_ceo_decision');
define('AUDIT_PAYROLL_PAYMENT', 'payroll_payment');

define('AUDIT_RECRUITMENT_RECOMMENDED', 'recruitment_recommended');
define('AUDIT_RECRUITMENT_CEO_DECISION', 'recruitment_ceo_decision');
define('AUDIT_EMPLOYEE_CREATED', 'employee_created');

define('AUDIT_TARGET_SUBMITTED', 'target_submitted');
define('AUDIT_TARGET_CEO_DECISION', 'target_ceo_decision');

// ===============================================
// APPROVAL DECISION REASONS (COMMON)
// ===============================================

define('REASON_BUSINESS_IMPACT', 'business_impact');
define('REASON_POLICY_COMPLIANCE', 'policy_compliance');
define('REASON_BUDGET_CONSTRAINTS', 'budget_constraints');
define('REASON_INSUFFICIENT_DOCUMENTATION', 'insufficient_documentation');
define('REASON_STRATEGIC_ALIGNMENT', 'strategic_alignment');
define('REASON_RESOURCE_AVAILABILITY', 'resource_availability');

// ===============================================
// HELPER ARRAYS FOR VALIDATION & DISPLAY
// ===============================================

/**
 * Get all valid statuses for a workflow type
 */
function get_valid_workflow_statuses($workflow_type) {
    $statuses = [
        WORKFLOW_LEAVE => [
            LEAVE_PENDING, LEAVE_APPROVED, LEAVE_REJECTED, LEAVE_CANCELLED,
            LEAVE_PENDING_HR, LEAVE_FINALIZED_BY_HR, LEAVE_ESCALATED_TO_CEO,
            LEAVE_PENDING_CEO, LEAVE_CEO_APPROVED, LEAVE_CEO_REJECTED
        ],
        WORKFLOW_PAYROLL => [
            PAYROLL_DRAFT, PAYROLL_CALCULATED, PAYROLL_SUBMITTED_FOR_APPROVAL,
            PAYROLL_PENDING_CEO_APPROVAL, PAYROLL_CEO_APPROVED, 
            PAYROLL_READY_FOR_PAYMENT, PAYROLL_PAID, PAYROLL_REJECTED
        ],
        WORKFLOW_RECRUITMENT => [
            APPLICATION_RECEIVED, APPLICATION_REVIEWING, APPLICATION_SHORTLISTED,
            APPLICATION_INTERVIEWED, APPLICATION_SELECTED, APPLICATION_REJECTED,
            APPLICATION_HIRED, HIRE_PENDING_CEO, HIRE_CEO_APPROVED, HIRE_CEO_REJECTED
        ],
        WORKFLOW_TARGET => [
            TARGET_DRAFT, TARGET_PENDING_CEO_APPROVAL, TARGET_CEO_APPROVED,
            TARGET_CEO_REJECTED, TARGET_ACTIVE, TARGET_COMPLETED, TARGET_CANCELLED
        ],
        WORKFLOW_EMPLOYEE_CHANGE => [
            EMPLOYEE_CHANGE_PENDING, EMPLOYEE_CHANGE_CEO_APPROVED,
            EMPLOYEE_CHANGE_CEO_REJECTED, EMPLOYEE_CHANGE_EFFECTIVE
        ]
    ];
    
    return $statuses[$workflow_type] ?? [];
}

/**
 * Get user-friendly status display names
 */
function get_status_display_name($status) {
    $display_names = [
        // Leave statuses
        LEAVE_PENDING => 'Pending Review',
        LEAVE_APPROVED => 'Approved',
        LEAVE_REJECTED => 'Rejected',
        LEAVE_CANCELLED => 'Cancelled',
        LEAVE_PENDING_HR => 'Pending HR Decision',
        LEAVE_FINALIZED_BY_HR => 'Finalized by HR',
        LEAVE_ESCALATED_TO_CEO => 'Escalated to CEO',
        LEAVE_PENDING_CEO => 'Pending CEO Approval',
        LEAVE_CEO_APPROVED => 'CEO Approved',
        LEAVE_CEO_REJECTED => 'CEO Rejected',
        
        // Payroll statuses
        PAYROLL_DRAFT => 'Draft',
        PAYROLL_CALCULATED => 'Calculated',
        PAYROLL_SUBMITTED_FOR_APPROVAL => 'Submitted for Approval',
        PAYROLL_PENDING_CEO_APPROVAL => 'Pending CEO Approval',
        PAYROLL_CEO_APPROVED => 'CEO Approved',
        PAYROLL_READY_FOR_PAYMENT => 'Ready for Payment',
        PAYROLL_PAID => 'Paid',
        PAYROLL_REJECTED => 'Rejected',
        
        // Recruitment statuses
        APPLICATION_RECEIVED => 'Application Received',
        APPLICATION_REVIEWING => 'Under Review',
        APPLICATION_SHORTLISTED => 'Shortlisted',
        APPLICATION_INTERVIEWED => 'Interviewed',
        APPLICATION_SELECTED => 'Selected',
        APPLICATION_REJECTED => 'Rejected',
        APPLICATION_HIRED => 'Hired',
        HIRE_PENDING_CEO => 'Pending CEO Hire Approval',
        HIRE_CEO_APPROVED => 'CEO Approved for Hire',
        HIRE_CEO_REJECTED => 'CEO Rejected Hire',
        
        // Target statuses
        TARGET_DRAFT => 'Draft',
        TARGET_PENDING_CEO_APPROVAL => 'Pending CEO Approval',
        TARGET_CEO_APPROVED => 'CEO Approved',
        TARGET_CEO_REJECTED => 'CEO Rejected',
        TARGET_ACTIVE => 'Active',
        TARGET_COMPLETED => 'Completed',
        TARGET_CANCELLED => 'Cancelled'
    ];
    
    return $display_names[$status] ?? ucfirst(str_replace('_', ' ', $status));
}

/**
 * Get bootstrap badge class for status
 */
function get_status_badge_class($status) {
    $badge_classes = [
        // Success states (green)
        LEAVE_APPROVED => 'bg-success',
        LEAVE_CEO_APPROVED => 'bg-success',
        PAYROLL_CEO_APPROVED => 'bg-success',
        PAYROLL_PAID => 'bg-success',
        APPLICATION_HIRED => 'bg-success',
        HIRE_CEO_APPROVED => 'bg-success',
        TARGET_CEO_APPROVED => 'bg-success',
        TARGET_ACTIVE => 'bg-success',
        TARGET_COMPLETED => 'bg-success',
        
        // Pending states (warning/yellow)
        LEAVE_PENDING => 'bg-warning',
        LEAVE_PENDING_HR => 'bg-warning',
        LEAVE_PENDING_CEO => 'bg-warning',
        PAYROLL_PENDING_CEO_APPROVAL => 'bg-warning',
        HIRE_PENDING_CEO => 'bg-warning',
        TARGET_PENDING_CEO_APPROVAL => 'bg-warning',
        
        // In progress states (info/blue)
        LEAVE_ESCALATED_TO_CEO => 'bg-info',
        PAYROLL_SUBMITTED_FOR_APPROVAL => 'bg-info',
        PAYROLL_READY_FOR_PAYMENT => 'bg-info',
        APPLICATION_REVIEWING => 'bg-info',
        APPLICATION_SHORTLISTED => 'bg-info',
        APPLICATION_INTERVIEWED => 'bg-info',
        
        // Rejected states (danger/red)
        LEAVE_REJECTED => 'bg-danger',
        LEAVE_CEO_REJECTED => 'bg-danger',
        PAYROLL_REJECTED => 'bg-danger',
        APPLICATION_REJECTED => 'bg-danger',
        HIRE_CEO_REJECTED => 'bg-danger',
        TARGET_CEO_REJECTED => 'bg-danger',
        
        // Draft/inactive states (secondary/gray)
        PAYROLL_DRAFT => 'bg-secondary',
        TARGET_DRAFT => 'bg-secondary',
        LEAVE_CANCELLED => 'bg-secondary',
        TARGET_CANCELLED => 'bg-secondary',
        
        // Special states
        PAYROLL_CALCULATED => 'bg-light text-dark',
        APPLICATION_RECEIVED => 'bg-light text-dark'
    ];
    
    return $badge_classes[$status] ?? 'bg-light text-dark';
}

/**
 * Check if status represents a final state (no further actions possible)
 */
function is_final_status($status) {
    $final_statuses = [
        LEAVE_APPROVED, LEAVE_REJECTED, LEAVE_CEO_APPROVED, LEAVE_CEO_REJECTED, LEAVE_CANCELLED,
        PAYROLL_PAID, PAYROLL_REJECTED,
        APPLICATION_HIRED, APPLICATION_REJECTED, HIRE_CEO_REJECTED,
        TARGET_COMPLETED, TARGET_CANCELLED, TARGET_CEO_REJECTED,
        EMPLOYEE_CHANGE_EFFECTIVE, EMPLOYEE_CHANGE_CEO_REJECTED
    ];
    
    return in_array($status, $final_statuses);
}

/**
 * Check if status requires CEO action
 */
function requires_ceo_action($status) {
    $ceo_action_statuses = [
        LEAVE_PENDING_CEO,
        PAYROLL_PENDING_CEO_APPROVAL,
        HIRE_PENDING_CEO,
        TARGET_PENDING_CEO_APPROVAL,
        EMPLOYEE_CHANGE_PENDING
    ];
    
    return in_array($status, $ceo_action_statuses);
}

/**
 * Check if status requires finance action
 */
function requires_finance_action($status) {
    $finance_action_statuses = [
        PAYROLL_CEO_APPROVED,
        PAYROLL_READY_FOR_PAYMENT
    ];
    
    return in_array($status, $finance_action_statuses);
}
?>