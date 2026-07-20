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
if (!defined('APPROVAL_PENDING')) define('APPROVAL_PENDING', 'pending');
if (!defined('APPROVAL_APPROVED')) define('APPROVAL_APPROVED', 'approved');
if (!defined('APPROVAL_REJECTED')) define('APPROVAL_REJECTED', 'rejected');
if (!defined('APPROVAL_ESCALATED')) define('APPROVAL_ESCALATED', 'escalated');
if (!defined('APPROVAL_CANCELLED')) define('APPROVAL_CANCELLED', 'cancelled');

// Workflow statuses
if (!defined('WORKFLOW_IN_PROGRESS')) define('WORKFLOW_IN_PROGRESS', 'in_progress');
if (!defined('WORKFLOW_COMPLETED')) define('WORKFLOW_COMPLETED', 'completed');
if (!defined('WORKFLOW_REJECTED')) define('WORKFLOW_REJECTED', 'rejected');
if (!defined('WORKFLOW_CANCELLED')) define('WORKFLOW_CANCELLED', 'cancelled');

// ===============================================
// LEAVE REQUEST WORKFLOW CONSTANTS
// ===============================================

// Leave request statuses
if (!defined('LEAVE_PENDING')) define('LEAVE_PENDING', 'pending');
if (!defined('LEAVE_APPROVED')) define('LEAVE_APPROVED', 'approved');
if (!defined('LEAVE_REJECTED')) define('LEAVE_REJECTED', 'rejected');
if (!defined('LEAVE_CANCELLED')) define('LEAVE_CANCELLED', 'cancelled');

// HR decision statuses
if (!defined('LEAVE_PENDING_HR')) define('LEAVE_PENDING_HR', 'pending_hr');
if (!defined('LEAVE_FINALIZED_BY_HR')) define('LEAVE_FINALIZED_BY_HR', 'finalized_by_hr');
if (!defined('LEAVE_ESCALATED_TO_CEO')) define('LEAVE_ESCALATED_TO_CEO', 'escalated_to_ceo');

// CEO decision statuses  
if (!defined('LEAVE_PENDING_CEO')) define('LEAVE_PENDING_CEO', 'pending_ceo');
if (!defined('LEAVE_CEO_APPROVED')) define('LEAVE_CEO_APPROVED', 'ceo_approved');
if (!defined('LEAVE_CEO_REJECTED')) define('LEAVE_CEO_REJECTED', 'ceo_rejected');

// Combined workflow statuses
if (!defined('LEAVE_APPROVED_BY_HR')) define('LEAVE_APPROVED_BY_HR', 'approved_by_hr');
if (!defined('LEAVE_REJECTED_BY_HR')) define('LEAVE_REJECTED_BY_HR', 'rejected_by_hr');
if (!defined('LEAVE_ESCALATED_PENDING_CEO')) define('LEAVE_ESCALATED_PENDING_CEO', 'escalated_pending_ceo');
if (!defined('LEAVE_FINAL_APPROVED')) define('LEAVE_FINAL_APPROVED', 'final_approved');
if (!defined('LEAVE_FINAL_REJECTED')) define('LEAVE_FINAL_REJECTED', 'final_rejected');

// ===============================================
// PAYROLL WORKFLOW CONSTANTS  
// ===============================================

// Payroll processing stages
if (!defined('PAYROLL_DRAFT')) define('PAYROLL_DRAFT', 'draft');
if (!defined('PAYROLL_CALCULATED')) define('PAYROLL_CALCULATED', 'calculated');
if (!defined('PAYROLL_SUBMITTED_FOR_APPROVAL')) define('PAYROLL_SUBMITTED_FOR_APPROVAL', 'submitted_for_approval');
if (!defined('PAYROLL_PENDING_CEO_APPROVAL')) define('PAYROLL_PENDING_CEO_APPROVAL', 'pending_ceo_approval');
if (!defined('PAYROLL_CEO_APPROVED')) define('PAYROLL_CEO_APPROVED', 'ceo_approved');
if (!defined('PAYROLL_READY_FOR_PAYMENT')) define('PAYROLL_READY_FOR_PAYMENT', 'ready_for_payment');
if (!defined('PAYROLL_PAID')) define('PAYROLL_PAID', 'paid');
if (!defined('PAYROLL_REJECTED')) define('PAYROLL_REJECTED', 'rejected');

// Payroll action statuses
if (!defined('PAYROLL_HR_SUBMITTED')) define('PAYROLL_HR_SUBMITTED', 'hr_submitted');
if (!defined('PAYROLL_CEO_DECISION_PENDING')) define('PAYROLL_CEO_DECISION_PENDING', 'ceo_decision_pending');
if (!defined('PAYROLL_FINANCE_PROCESSING')) define('PAYROLL_FINANCE_PROCESSING', 'finance_processing');
if (!defined('PAYROLL_PAYMENT_COMPLETED')) define('PAYROLL_PAYMENT_COMPLETED', 'payment_completed');

// ===============================================
// RECRUITMENT WORKFLOW CONSTANTS
// ===============================================

// Job application statuses (existing)
if (!defined('APPLICATION_RECEIVED')) define('APPLICATION_RECEIVED', 'received');
if (!defined('APPLICATION_REVIEWING')) define('APPLICATION_REVIEWING', 'reviewing');
if (!defined('APPLICATION_SHORTLISTED')) define('APPLICATION_SHORTLISTED', 'shortlisted');
if (!defined('APPLICATION_INTERVIEWED')) define('APPLICATION_INTERVIEWED', 'interviewed');
if (!defined('APPLICATION_SELECTED')) define('APPLICATION_SELECTED', 'selected');
if (!defined('APPLICATION_REJECTED')) define('APPLICATION_REJECTED', 'rejected');
if (!defined('APPLICATION_HIRED')) define('APPLICATION_HIRED', 'hired');

// HR recommendation statuses
if (!defined('HR_NOT_RECOMMENDED')) define('HR_NOT_RECOMMENDED', 'not_recommended');
if (!defined('HR_RECOMMENDED')) define('HR_RECOMMENDED', 'recommended');
if (!defined('HR_STRONGLY_RECOMMENDED')) define('HR_STRONGLY_RECOMMENDED', 'strongly_recommended');

// CEO hiring approval statuses
if (!defined('HIRE_PENDING_CEO')) define('HIRE_PENDING_CEO', 'pending_ceo');
if (!defined('HIRE_CEO_APPROVED')) define('HIRE_CEO_APPROVED', 'ceo_approved');
if (!defined('HIRE_CEO_REJECTED')) define('HIRE_CEO_REJECTED', 'ceo_rejected');

// ===============================================
// PERFORMANCE TARGETS WORKFLOW CONSTANTS
// ===============================================

// Target statuses (existing)
if (!defined('TARGET_ACTIVE')) define('TARGET_ACTIVE', 'active');
if (!defined('TARGET_COMPLETED')) define('TARGET_COMPLETED', 'completed');
if (!defined('TARGET_CANCELLED')) define('TARGET_CANCELLED', 'cancelled');

// Target approval statuses
if (!defined('TARGET_DRAFT')) define('TARGET_DRAFT', 'draft');
if (!defined('TARGET_PENDING_CEO_APPROVAL')) define('TARGET_PENDING_CEO_APPROVAL', 'pending_ceo_approval');
if (!defined('TARGET_CEO_APPROVED')) define('TARGET_CEO_APPROVED', 'ceo_approved');
if (!defined('TARGET_CEO_REJECTED')) define('TARGET_CEO_REJECTED', 'ceo_rejected');

// ===============================================
// EMPLOYEE CHANGE WORKFLOW CONSTANTS
// ===============================================

// Employee statuses (existing)
if (!defined('EMPLOYEE_ACTIVE')) define('EMPLOYEE_ACTIVE', 'active');
if (!defined('EMPLOYEE_TERMINATED')) define('EMPLOYEE_TERMINATED', 'terminated');
if (!defined('EMPLOYEE_SUSPENDED')) define('EMPLOYEE_SUSPENDED', 'suspended');
if (!defined('EMPLOYEE_ON_LEAVE')) define('EMPLOYEE_ON_LEAVE', 'on_leave');

// Employee change approval statuses
if (!defined('EMPLOYEE_CHANGE_PENDING')) define('EMPLOYEE_CHANGE_PENDING', 'change_pending');
if (!defined('EMPLOYEE_CHANGE_CEO_APPROVED')) define('EMPLOYEE_CHANGE_CEO_APPROVED', 'change_ceo_approved');
if (!defined('EMPLOYEE_CHANGE_CEO_REJECTED')) define('EMPLOYEE_CHANGE_CEO_REJECTED', 'change_ceo_rejected');
if (!defined('EMPLOYEE_CHANGE_EFFECTIVE')) define('EMPLOYEE_CHANGE_EFFECTIVE', 'change_effective');

// ===============================================
// WORKFLOW TYPE CONSTANTS
// ===============================================

if (!defined('WORKFLOW_LEAVE')) define('WORKFLOW_LEAVE', 'leave');
if (!defined('WORKFLOW_PAYROLL')) define('WORKFLOW_PAYROLL', 'payroll');
if (!defined('WORKFLOW_RECRUITMENT')) define('WORKFLOW_RECRUITMENT', 'recruitment');
if (!defined('WORKFLOW_TARGET')) define('WORKFLOW_TARGET', 'target');
if (!defined('WORKFLOW_EMPLOYEE_CHANGE')) define('WORKFLOW_EMPLOYEE_CHANGE', 'employee_change');

// ===============================================
// ENTITY TYPE CONSTANTS  
// ===============================================

if (!defined('ENTITY_LEAVE_REQUEST')) define('ENTITY_LEAVE_REQUEST', 'leave_request');
if (!defined('ENTITY_PAYROLL')) define('ENTITY_PAYROLL', 'payroll');
if (!defined('ENTITY_JOB_APPLICATION')) define('ENTITY_JOB_APPLICATION', 'job_application');
if (!defined('ENTITY_PERFORMANCE_TARGET')) define('ENTITY_PERFORMANCE_TARGET', 'performance_target');
if (!defined('ENTITY_EMPLOYEE')) define('ENTITY_EMPLOYEE', 'employee');

// ===============================================
// APPROVAL STAGE CONSTANTS
// ===============================================

if (!defined('STAGE_HR')) define('STAGE_HR', 'hr');
if (!defined('STAGE_CEO')) define('STAGE_CEO', 'ceo');
if (!defined('STAGE_FINANCE')) define('STAGE_FINANCE', 'finance');

// ===============================================
// NOTIFICATION TYPE CONSTANTS
// ===============================================

if (!defined('NOTIFICATION_ESCALATION')) define('NOTIFICATION_ESCALATION', 'escalation');
if (!defined('NOTIFICATION_APPROVAL_REQUIRED')) define('NOTIFICATION_APPROVAL_REQUIRED', 'approval_required');
if (!defined('NOTIFICATION_APPROVED')) define('NOTIFICATION_APPROVED', 'approved');
if (!defined('NOTIFICATION_REJECTED')) define('NOTIFICATION_REJECTED', 'rejected');
if (!defined('NOTIFICATION_REMINDER')) define('NOTIFICATION_REMINDER', 'reminder');

// ===============================================
// AUDIT ACTION CONSTANTS
// ===============================================

if (!defined('AUDIT_LEAVE_SUBMITTED')) define('AUDIT_LEAVE_SUBMITTED', 'leave_submitted');
if (!defined('AUDIT_LEAVE_FINALIZED')) define('AUDIT_LEAVE_FINALIZED', 'leave_finalized');
if (!defined('AUDIT_LEAVE_ESCALATED')) define('AUDIT_LEAVE_ESCALATED', 'leave_escalated');
if (!defined('AUDIT_LEAVE_CEO_DECISION')) define('AUDIT_LEAVE_CEO_DECISION', 'leave_ceo_decision');

if (!defined('AUDIT_PAYROLL_SUBMITTED')) define('AUDIT_PAYROLL_SUBMITTED', 'payroll_submitted');
if (!defined('AUDIT_PAYROLL_CEO_DECISION')) define('AUDIT_PAYROLL_CEO_DECISION', 'payroll_ceo_decision');
if (!defined('AUDIT_PAYROLL_PAYMENT')) define('AUDIT_PAYROLL_PAYMENT', 'payroll_payment');

if (!defined('AUDIT_RECRUITMENT_RECOMMENDED')) define('AUDIT_RECRUITMENT_RECOMMENDED', 'recruitment_recommended');
if (!defined('AUDIT_RECRUITMENT_CEO_DECISION')) define('AUDIT_RECRUITMENT_CEO_DECISION', 'recruitment_ceo_decision');
if (!defined('AUDIT_EMPLOYEE_CREATED')) define('AUDIT_EMPLOYEE_CREATED', 'employee_created');

if (!defined('AUDIT_TARGET_SUBMITTED')) define('AUDIT_TARGET_SUBMITTED', 'target_submitted');
if (!defined('AUDIT_TARGET_CEO_DECISION')) define('AUDIT_TARGET_CEO_DECISION', 'target_ceo_decision');

// ===============================================
// APPROVAL DECISION REASONS (COMMON)
// ===============================================

if (!defined('REASON_BUSINESS_IMPACT')) define('REASON_BUSINESS_IMPACT', 'business_impact');
if (!defined('REASON_POLICY_COMPLIANCE')) define('REASON_POLICY_COMPLIANCE', 'policy_compliance');
if (!defined('REASON_BUDGET_CONSTRAINTS')) define('REASON_BUDGET_CONSTRAINTS', 'budget_constraints');
if (!defined('REASON_INSUFFICIENT_DOCUMENTATION')) define('REASON_INSUFFICIENT_DOCUMENTATION', 'insufficient_documentation');
if (!defined('REASON_STRATEGIC_ALIGNMENT')) define('REASON_STRATEGIC_ALIGNMENT', 'strategic_alignment');
if (!defined('REASON_RESOURCE_AVAILABILITY')) define('REASON_RESOURCE_AVAILABILITY', 'resource_availability');

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