-- ========================================================================
-- READY-TO-RUN MIGRATION QUERY
-- Copy and paste this entire script into phpMyAdmin or MySQL CLI
-- ========================================================================
-- Database: jrozqhmy_stock_exchange_db
-- Purpose: Consolidate employees table into users table
-- 
-- IMPORTANT: 
-- 1. Backup your database first!
-- 2. Test on a development environment first
-- 3. This script will drop the employees table
-- ========================================================================

-- Start transaction for atomicity
START TRANSACTION;

-- ========================================================================
-- STEP 1: Add all missing employee-related columns to users table
-- ========================================================================
ALTER TABLE `users` 
ADD COLUMN `employee_id` varchar(20) UNIQUE,
ADD COLUMN `first_name` varchar(50),
ADD COLUMN `last_name` varchar(50),
ADD COLUMN `middle_name` varchar(50),
ADD COLUMN `phone` varchar(20),
ADD COLUMN `alternate_phone` varchar(20),
ADD COLUMN `address` text,
ADD COLUMN `national_id` varchar(50) UNIQUE,
ADD COLUMN `department_id` int,
ADD COLUMN `position_id` int,
ADD COLUMN `probation_end_date` date,
ADD COLUMN `employment_type` enum('full_time','part_time','contract','internship') DEFAULT 'full_time',
ADD COLUMN `termination_date` date,
ADD COLUMN `termination_reason` text,
ADD COLUMN `approved_by` int,
ADD COLUMN `approved_at` timestamp NULL,
ADD COLUMN `effective_date` date,
ADD COLUMN `change_reason` varchar(500),
ADD COLUMN `emergency_contact_name` varchar(100),
ADD COLUMN `emergency_contact_phone` varchar(20),
ADD COLUMN `emergency_contact_relationship` varchar(50),
ADD COLUMN `bank_name` varchar(100),
ADD COLUMN `bank_account_number` varchar(50),
ADD COLUMN `tax_identification` varchar(50),
ADD COLUMN `social_security` varchar(50);

-- ========================================================================
-- STEP 2: Migrate employee data from employees table to users table
-- ========================================================================
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

-- ========================================================================
-- STEP 3: Update full_name if empty but first_name and last_name exist
-- ========================================================================
UPDATE users 
SET full_name = CONCAT(COALESCE(first_name, ''), ' ', COALESCE(last_name, ''))
WHERE (full_name IS NULL OR full_name = '') 
  AND (first_name IS NOT NULL AND first_name != '');

-- ========================================================================
-- STEP 4: Update employee_benefits table to use user_id instead of employee_id
-- ========================================================================
-- Drop old foreign key if it exists
ALTER TABLE employee_benefits 
DROP FOREIGN KEY IF EXISTS employee_benefits_ibfk_1;

-- Rename the column
ALTER TABLE employee_benefits 
CHANGE COLUMN employee_id user_id int NOT NULL;

-- Add new foreign key constraint
ALTER TABLE employee_benefits 
ADD CONSTRAINT employee_benefits_ibfk_1 
FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE;

-- ========================================================================
-- STEP 5: Add foreign key constraints to users table
-- ========================================================================
ALTER TABLE users 
ADD CONSTRAINT users_department_fk FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE SET NULL,
ADD CONSTRAINT users_position_fk FOREIGN KEY (position_id) REFERENCES job_positions(id) ON DELETE SET NULL;

-- ========================================================================
-- STEP 6: Drop the obsolete employees table
-- ========================================================================
DROP TABLE IF EXISTS employees;

-- ========================================================================
-- STEP 7: Commit the transaction
-- ========================================================================
COMMIT;

-- ========================================================================
-- POST-MIGRATION VERIFICATION QUERIES
-- ========================================================================
-- Run these to verify the migration was successful:

-- Check 1: Count total users
SELECT COUNT(*) as total_users FROM users;

-- Check 2: Count users with employee data
SELECT COUNT(*) as users_with_employee_data FROM users WHERE first_name IS NOT NULL;

-- Check 3: Verify employees table is gone
SELECT 'SUCCESS - employees table dropped' as status
WHERE NOT EXISTS (
  SELECT 1 FROM INFORMATION_SCHEMA.TABLES 
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'employees'
);

-- Check 4: Verify employee_benefits foreign keys
SELECT 
  CONSTRAINT_NAME,
  COLUMN_NAME,
  REFERENCED_TABLE_NAME,
  REFERENCED_COLUMN_NAME
FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE 
WHERE TABLE_NAME = 'employee_benefits' 
AND CONSTRAINT_SCHEMA = DATABASE();

-- Check 5: Sample data - verify migration worked
SELECT 
  id,
  username,
  full_name,
  first_name,
  last_name,
  email,
  phone,
  department_id,
  position_id,
  hire_date,
  salary,
  status
FROM users 
WHERE first_name IS NOT NULL
LIMIT 5;

-- ========================================================================
-- END OF MIGRATION SCRIPT
-- ========================================================================
-- If you see any errors, DO NOT CONTINUE with PHP updates
-- Contact your database administrator for troubleshooting
-- ========================================================================
