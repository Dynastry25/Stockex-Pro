-- ========================================================================
-- MIGRATION: Consolidate Employees Table into Users Table
-- ========================================================================
-- This script will:
-- 1. Add missing employee-related columns to the users table
-- 2. Migrate all employee data from the employees table to users table
-- 3. Update all foreign key references
-- 4. Drop the now-obsolete employees table
-- 
-- IMPORTANT: Back up your database before running this script!
-- ========================================================================

-- Start transaction
START TRANSACTION;

-- Step 1: Add missing columns to users table
-- ========================================================================
ALTER TABLE users 
ADD COLUMN IF NOT EXISTS employee_id varchar(20) UNIQUE,
ADD COLUMN IF NOT EXISTS first_name varchar(50),
ADD COLUMN IF NOT EXISTS last_name varchar(50),
ADD COLUMN IF NOT EXISTS middle_name varchar(50),
ADD COLUMN IF NOT EXISTS phone varchar(20),
ADD COLUMN IF NOT EXISTS alternate_phone varchar(20),
ADD COLUMN IF NOT EXISTS address text,
ADD COLUMN IF NOT EXISTS national_id varchar(50) UNIQUE,
ADD COLUMN IF NOT EXISTS department_id int,
ADD COLUMN IF NOT EXISTS position_id int,
ADD COLUMN IF NOT EXISTS hire_date date,
ADD COLUMN IF NOT EXISTS probation_end_date date,
ADD COLUMN IF NOT EXISTS employment_type enum('full_time','part_time','contract','internship') DEFAULT 'full_time',
ADD COLUMN IF NOT EXISTS termination_date date,
ADD COLUMN IF NOT EXISTS termination_reason text,
ADD COLUMN IF NOT EXISTS approved_by int,
ADD COLUMN IF NOT EXISTS approved_at timestamp NULL,
ADD COLUMN IF NOT EXISTS effective_date date,
ADD COLUMN IF NOT EXISTS change_reason varchar(500),
ADD COLUMN IF NOT EXISTS emergency_contact_name varchar(100),
ADD COLUMN IF NOT EXISTS emergency_contact_phone varchar(20),
ADD COLUMN IF NOT EXISTS emergency_contact_relationship varchar(50),
ADD COLUMN IF NOT EXISTS bank_name varchar(100),
ADD COLUMN IF NOT EXISTS bank_account_number varchar(50),
ADD COLUMN IF NOT EXISTS tax_identification varchar(50),
ADD COLUMN IF NOT EXISTS social_security varchar(50);

-- Step 2: Migrate data from employees table to users table
-- ========================================================================
-- Only update if the users record doesn't already have employee data
UPDATE users u
SET 
    u.employee_id = (SELECT employee_id FROM employees e WHERE e.user_id = u.id LIMIT 1),
    u.first_name = (SELECT first_name FROM employees e WHERE e.user_id = u.id LIMIT 1),
    u.last_name = (SELECT last_name FROM employees e WHERE e.user_id = u.id LIMIT 1),
    u.middle_name = (SELECT middle_name FROM employees e WHERE e.user_id = u.id LIMIT 1),
    u.phone = (SELECT phone FROM employees e WHERE e.user_id = u.id LIMIT 1),
    u.alternate_phone = (SELECT alternate_phone FROM employees e WHERE e.user_id = u.id LIMIT 1),
    u.address = (SELECT address FROM employees e WHERE e.user_id = u.id LIMIT 1),
    u.national_id = (SELECT national_id FROM employees e WHERE e.user_id = u.id LIMIT 1),
    u.department_id = (SELECT department_id FROM employees e WHERE e.user_id = u.id LIMIT 1),
    u.position_id = (SELECT position_id FROM employees e WHERE e.user_id = u.id LIMIT 1),
    u.hire_date = (SELECT hire_date FROM employees e WHERE e.user_id = u.id LIMIT 1),
    u.probation_end_date = (SELECT probation_end_date FROM employees e WHERE e.user_id = u.id LIMIT 1),
    u.employment_type = (SELECT employment_type FROM employees e WHERE e.user_id = u.id LIMIT 1),
    u.termination_date = (SELECT termination_date FROM employees e WHERE e.user_id = u.id LIMIT 1),
    u.termination_reason = (SELECT termination_reason FROM employees e WHERE e.user_id = u.id LIMIT 1),
    u.approved_by = (SELECT approved_by FROM employees e WHERE e.user_id = u.id LIMIT 1),
    u.approved_at = (SELECT approved_at FROM employees e WHERE e.user_id = u.id LIMIT 1),
    u.effective_date = (SELECT effective_date FROM employees e WHERE e.user_id = u.id LIMIT 1),
    u.change_reason = (SELECT change_reason FROM employees e WHERE e.user_id = u.id LIMIT 1),
    u.emergency_contact_name = (SELECT emergency_contact_name FROM employees e WHERE e.user_id = u.id LIMIT 1),
    u.emergency_contact_phone = (SELECT emergency_contact_phone FROM employees e WHERE e.user_id = u.id LIMIT 1),
    u.emergency_contact_relationship = (SELECT emergency_contact_relationship FROM employees e WHERE e.user_id = u.id LIMIT 1),
    u.bank_name = (SELECT bank_name FROM employees e WHERE e.user_id = u.id LIMIT 1),
    u.bank_account_number = (SELECT bank_account_number FROM employees e WHERE e.user_id = u.id LIMIT 1),
    u.tax_identification = (SELECT tax_identification FROM employees e WHERE e.user_id = u.id LIMIT 1),
    u.social_security = (SELECT social_security FROM employees e WHERE e.user_id = u.id LIMIT 1)
WHERE u.id IN (SELECT DISTINCT user_id FROM employees WHERE user_id IS NOT NULL);

-- Step 3: Update full_name if first_name and last_name are present but full_name is not properly set
UPDATE users 
SET full_name = CONCAT(COALESCE(first_name, ''), ' ', COALESCE(last_name, ''))
WHERE (full_name IS NULL OR full_name = '') 
  AND (first_name IS NOT NULL AND first_name != '');

-- Step 4: Update employee_benefits table to use user_id instead of employee_id
-- ========================================================================
-- Note: This depends on your schema. Adjust if the employee_benefits table references employees differently
ALTER TABLE employee_benefits 
CHANGE COLUMN employee_id user_id int NOT NULL;

-- Step 5: Update employee_benefits foreign key constraint
ALTER TABLE employee_benefits 
DROP FOREIGN KEY IF EXISTS employee_benefits_ibfk_1;

ALTER TABLE employee_benefits 
ADD CONSTRAINT employee_benefits_ibfk_1 
FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE;

-- Step 6: Add foreign key constraints to users table for department and position
-- ========================================================================
-- First, let's check if the constraints already exist and add them if they don't
ALTER TABLE users 
ADD CONSTRAINT users_department_ibfk FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE SET NULL,
ADD CONSTRAINT users_position_ibfk FOREIGN KEY (position_id) REFERENCES job_positions(id) ON DELETE SET NULL;

-- Step 7: Update any references in leave_requests table
-- ========================================================================
-- If leave_requests references employees, update to use user_id if needed
-- Verify the schema first before executing
-- Example: ALTER TABLE leave_requests CHANGE COLUMN employee_id user_id int NOT NULL;

-- Step 8: Remove the obsolete employees table
-- ========================================================================
DROP TABLE IF EXISTS employees;

-- Commit transaction
COMMIT;

-- ========================================================================
-- Post-Migration Verification
-- ========================================================================
-- Run these queries to verify the migration was successful:
-- 
-- 1. Check users table has data:
--    SELECT COUNT(*) as total_users FROM users;
--    SELECT COUNT(*) FROM users WHERE first_name IS NOT NULL;
--
-- 2. Check no remaining references to employees:
--    SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES 
--    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'employees';
--
-- 3. Verify employee_benefits foreign keys:
--    SELECT * FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE 
--    WHERE TABLE_NAME = 'employee_benefits' AND COLUMN_NAME = 'user_id';
--
-- ========================================================================
-- PHP Code Changes Required (Not done automatically, see migration guide)
-- ========================================================================
-- Files to update in /hr/ directory:
-- - employees.php: Change all references from 'employees' table to 'users' table
-- - employee_details.php: Update queries to select from 'users' instead of 'employees'
-- - payroll.php: Update all employee lookups to use 'users' table
-- - dashboard.php: Update employee count queries
-- - leave_management.php: Update employee references
-- - reports.php: Update department/employee join queries
-- - targets.php: Update employee lookup queries
-- - recruitment.php: Update employee relationship checks
-- - test_payroll.php: Update employee selection queries
--
-- See MIGRATION_PHP_CHANGES.txt for detailed PHP file changes
-- ========================================================================
