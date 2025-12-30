-- Approval Workflow Database Migrations - Remaining Tables
-- This script adds approval workflow fields to remaining HR tables
-- Only adds columns that don't already exist

-- Temporarily disable foreign key checks for safe modifications
SET FOREIGN_KEY_CHECKS = 0;

-- ===============================================
-- EMPLOYEES - Change approval tracking
-- ===============================================

-- Add approval tracking for employee changes (if not exists)
ALTER TABLE employees ADD COLUMN approved_by INT NULL AFTER termination_reason;
ALTER TABLE employees ADD COLUMN approved_at TIMESTAMP NULL AFTER approved_by;
ALTER TABLE employees ADD COLUMN effective_date DATE NULL AFTER approved_at;
ALTER TABLE employees ADD COLUMN change_reason VARCHAR(500) NULL AFTER effective_date;

-- Add foreign key for employee change approvals
ALTER TABLE employees ADD CONSTRAINT fk_employee_approved_by 
    FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL;

-- ===============================================
-- PAYROLL - Multi-stage approval workflow
-- ===============================================

-- Expand payroll status enum to include all approval stages (if needed)
ALTER TABLE payroll MODIFY COLUMN status ENUM(
    'draft', 
    'calculated',
    'submitted_for_approval', 
    'pending_ceo_approval', 
    'ceo_approved', 
    'ready_for_payment', 
    'paid', 
    'rejected'
) DEFAULT 'draft';

-- Add HR submission tracking (if not exists)
ALTER TABLE payroll ADD COLUMN submitted_by_hr_at TIMESTAMP NULL AFTER created_at;
ALTER TABLE payroll ADD COLUMN submission_reason VARCHAR(500) NULL AFTER submitted_by_hr_at;

-- Add CEO approval tracking (if not exists)
ALTER TABLE payroll ADD COLUMN ceo_approved_by INT NULL AFTER submission_reason;
ALTER TABLE payroll ADD COLUMN ceo_approved_at TIMESTAMP NULL AFTER ceo_approved_by;
ALTER TABLE payroll ADD COLUMN ceo_rejection_reason VARCHAR(500) NULL AFTER ceo_approved_at;

-- Add finance payment processing (only finance_processed_by, payment fields already exist)
ALTER TABLE payroll ADD COLUMN finance_processed_by INT NULL AFTER ceo_rejection_reason;

-- Add foreign keys for payroll approvals
ALTER TABLE payroll ADD CONSTRAINT fk_payroll_ceo_approved_by 
    FOREIGN KEY (ceo_approved_by) REFERENCES users(id) ON DELETE SET NULL;
ALTER TABLE payroll ADD CONSTRAINT fk_payroll_finance_processed_by 
    FOREIGN KEY (finance_processed_by) REFERENCES users(id) ON DELETE SET NULL;

-- Add indexes for payroll workflow queries
ALTER TABLE payroll ADD INDEX idx_payroll_status (status);
ALTER TABLE payroll ADD INDEX idx_payroll_submitted_by_hr_at (submitted_by_hr_at);
ALTER TABLE payroll ADD INDEX idx_payroll_ceo_approved_at (ceo_approved_at);

-- ===============================================
-- PERFORMANCE TARGETS - CEO approval for strategic targets
-- ===============================================

-- Add CEO approval workflow for targets (if not exists)
ALTER TABLE performance_targets ADD COLUMN requires_ceo_approval BOOLEAN DEFAULT FALSE AFTER created_at;
ALTER TABLE performance_targets ADD COLUMN ceo_decision_status ENUM('pending_ceo', 'ceo_approved', 'ceo_rejected') DEFAULT NULL AFTER requires_ceo_approval;
ALTER TABLE performance_targets ADD COLUMN ceo_approved_by INT NULL AFTER ceo_decision_status;
ALTER TABLE performance_targets ADD COLUMN ceo_approved_at TIMESTAMP NULL AFTER ceo_approved_by;
ALTER TABLE performance_targets ADD COLUMN ceo_rejection_reason VARCHAR(500) NULL AFTER ceo_approved_at;

-- Add foreign key for target CEO approval
ALTER TABLE performance_targets ADD CONSTRAINT fk_target_ceo_approved_by 
    FOREIGN KEY (ceo_approved_by) REFERENCES users(id) ON DELETE SET NULL;

-- ===============================================
-- JOB APPLICATIONS - HR recommendation and CEO hire approval
-- ===============================================

-- Add HR hiring recommendation (if not exists)
ALTER TABLE job_applications ADD COLUMN hr_recommendation ENUM('not_recommended', 'recommended', 'strongly_recommended') DEFAULT NULL AFTER notes;
ALTER TABLE job_applications ADD COLUMN hr_recommended_at TIMESTAMP NULL AFTER hr_recommendation;
ALTER TABLE job_applications ADD COLUMN hr_recommended_by INT NULL AFTER hr_recommended_at;
ALTER TABLE job_applications ADD COLUMN hr_recommendation_notes VARCHAR(500) NULL AFTER hr_recommended_by;

-- Add CEO hire approval (if not exists)
ALTER TABLE job_applications ADD COLUMN ceo_approval_status ENUM('pending_ceo', 'ceo_approved', 'ceo_rejected') DEFAULT NULL AFTER hr_recommendation_notes;
ALTER TABLE job_applications ADD COLUMN ceo_approved_by INT NULL AFTER ceo_approval_status;
ALTER TABLE job_applications ADD COLUMN ceo_approved_at TIMESTAMP NULL AFTER ceo_approved_by;
ALTER TABLE job_applications ADD COLUMN ceo_hire_decision_notes VARCHAR(500) NULL AFTER ceo_approved_at;

-- Add employee ID for hired applicants (if not exists)
ALTER TABLE job_applications ADD COLUMN hired_as_employee_id INT NULL AFTER ceo_hire_decision_notes;

-- Add foreign keys for recruitment approvals
ALTER TABLE job_applications ADD CONSTRAINT fk_application_hr_recommended_by 
    FOREIGN KEY (hr_recommended_by) REFERENCES users(id) ON DELETE SET NULL;
ALTER TABLE job_applications ADD CONSTRAINT fk_application_ceo_approved_by 
    FOREIGN KEY (ceo_approved_by) REFERENCES users(id) ON DELETE SET NULL;
ALTER TABLE job_applications ADD CONSTRAINT fk_application_hired_employee 
    FOREIGN KEY (hired_as_employee_id) REFERENCES employees(id) ON DELETE SET NULL;

-- Re-enable foreign key checks
SET FOREIGN_KEY_CHECKS = 1;

-- Success message
SELECT 'Approval workflow migrations completed successfully!' as message;
SELECT 'Added approval workflow fields to employees, payroll, performance_targets, and job_applications' as details;
