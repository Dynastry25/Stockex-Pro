-- Update Users Table to Support HR Roles
-- This script updates the existing users table to include HR roles
-- Run this script after the main schema

-- Update the role enum to include HR roles
ALTER TABLE users MODIFY COLUMN role ENUM(
    'system_admin', 
    'trader', 
    'ceo', 
    'finance_officer',
    'hr_manager',
    'hr_officer'
) NOT NULL;

-- Success message
SELECT 'User roles updated successfully to include HR roles!' as message;