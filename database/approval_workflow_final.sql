-- Final Approval Workflow Database Migrations
-- Only adds columns that don't already exist

SET FOREIGN_KEY_CHECKS = 0;

-- ===============================================
-- JOB APPLICATIONS - HR recommendation and CEO hire approval
-- ===============================================

-- Add HR hiring recommendation (only if not exists)
ALTER TABLE job_applications 
ADD COLUMN IF NOT EXISTS hr_recommendation ENUM('not_recommended', 'recommended', 'strongly_recommended') DEFAULT NULL AFTER interview_notes;

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

-- Foreign keys already exist or will be added manually if needed
-- The columns are all set up for approval workflows

-- Add indexes for approval workflow queries
ALTER TABLE payroll ADD INDEX IF NOT EXISTS idx_payroll_status (status);
ALTER TABLE payroll ADD INDEX IF NOT EXISTS idx_payroll_submitted_by_hr_at (submitted_by_hr_at);
ALTER TABLE payroll ADD INDEX IF NOT EXISTS idx_payroll_ceo_approved_at (ceo_approved_at);

ALTER TABLE leave_requests ADD INDEX IF NOT EXISTS idx_requires_ceo_approval (requires_ceo_approval);
ALTER TABLE leave_requests ADD INDEX IF NOT EXISTS idx_ceo_decision_status (ceo_decision_status);

SET FOREIGN_KEY_CHECKS = 1;

SELECT 'All approval workflow migrations completed successfully!' as message;
SELECT 'Database is now fully configured for multi-stage approval workflows.' as status;
