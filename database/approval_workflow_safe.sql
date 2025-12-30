-- Approval Workflow Database Migrations - Safe Addition
-- Only adds columns that don't already exist

SET FOREIGN_KEY_CHECKS = 0;

-- ===============================================
-- PERFORMANCE TARGETS - CEO approval for strategic targets
-- ===============================================

-- Add CEO approval workflow for targets (only if not exists)
ALTER TABLE performance_targets 
ADD COLUMN IF NOT EXISTS requires_ceo_approval BOOLEAN DEFAULT FALSE AFTER created_at;

ALTER TABLE performance_targets 
ADD COLUMN IF NOT EXISTS ceo_decision_status ENUM('pending_ceo', 'ceo_approved', 'ceo_rejected') DEFAULT NULL AFTER requires_ceo_approval;

ALTER TABLE performance_targets 
ADD COLUMN IF NOT EXISTS ceo_approved_by INT NULL AFTER ceo_decision_status;

ALTER TABLE performance_targets 
ADD COLUMN IF NOT EXISTS ceo_approved_at TIMESTAMP NULL AFTER ceo_approved_by;

ALTER TABLE performance_targets 
ADD COLUMN IF NOT EXISTS ceo_rejection_reason VARCHAR(500) NULL AFTER ceo_approved_at;

-- ===============================================
-- JOB APPLICATIONS - HR recommendation and CEO hire approval
-- ===============================================

-- Add HR hiring recommendation (only if not exists)
ALTER TABLE job_applications 
ADD COLUMN IF NOT EXISTS hr_recommendation ENUM('not_recommended', 'recommended', 'strongly_recommended') DEFAULT NULL AFTER notes;

ALTER TABLE job_applications 
ADD COLUMN IF NOT EXISTS hr_recommended_at TIMESTAMP NULL AFTER hr_recommendation;

ALTER TABLE job_applications 
ADD COLUMN IF NOT EXISTS hr_recommended_by INT NULL AFTER hr_recommended_at;

ALTER TABLE job_applications 
ADD COLUMN IF NOT EXISTS hr_recommendation_notes VARCHAR(500) NULL AFTER hr_recommended_by;

-- Add CEO hire approval (only if not exists)
ALTER TABLE job_applications 
ADD COLUMN IF NOT EXISTS ceo_approval_status ENUM('pending_ceo', 'ceo_approved', 'ceo_rejected') DEFAULT NULL AFTER hr_recommendation_notes;

ALTER TABLE job_applications 
ADD COLUMN IF NOT EXISTS ceo_approved_by INT NULL AFTER ceo_approval_status;

ALTER TABLE job_applications 
ADD COLUMN IF NOT EXISTS ceo_approved_at TIMESTAMP NULL AFTER ceo_approved_by;

ALTER TABLE job_applications 
ADD COLUMN IF NOT EXISTS ceo_hire_decision_notes VARCHAR(500) NULL AFTER ceo_approved_at;

-- Add employee ID for hired applicants (only if not exists)
ALTER TABLE job_applications 
ADD COLUMN IF NOT EXISTS hired_as_employee_id INT NULL AFTER ceo_hire_decision_notes;

-- Add foreign keys if they don't exist
ALTER TABLE payroll 
ADD CONSTRAINT IF NOT EXISTS fk_payroll_ceo_approved_by 
FOREIGN KEY (ceo_approved_by) REFERENCES users(id) ON DELETE SET NULL;

ALTER TABLE payroll 
ADD CONSTRAINT IF NOT EXISTS fk_payroll_finance_processed_by 
FOREIGN KEY (finance_processed_by) REFERENCES users(id) ON DELETE SET NULL;

ALTER TABLE performance_targets 
ADD CONSTRAINT IF NOT EXISTS fk_target_ceo_approved_by 
FOREIGN KEY (ceo_approved_by) REFERENCES users(id) ON DELETE SET NULL;

ALTER TABLE job_applications 
ADD CONSTRAINT IF NOT EXISTS fk_application_hr_recommended_by 
FOREIGN KEY (hr_recommended_by) REFERENCES users(id) ON DELETE SET NULL;

ALTER TABLE job_applications 
ADD CONSTRAINT IF NOT EXISTS fk_application_ceo_approved_by 
FOREIGN KEY (ceo_approved_by) REFERENCES users(id) ON DELETE SET NULL;

ALTER TABLE job_applications 
ADD CONSTRAINT IF NOT EXISTS fk_application_hired_employee 
FOREIGN KEY (hired_as_employee_id) REFERENCES employees(id) ON DELETE SET NULL;

SET FOREIGN_KEY_CHECKS = 1;

SELECT 'Approval workflow migration completed!' as message;
SELECT 'All missing approval workflow fields have been added to the database.' as status;
