-- Complete HR System Setup Script
-- Run this script to set up the HR system properly
-- Make sure to have an admin user first!

-- Step 1: Update user roles to include HR roles
ALTER TABLE users MODIFY COLUMN role ENUM(
    'system_admin', 
    'trader', 
    'ceo', 
    'finance_officer',
    'hr_manager',
    'hr_officer'
) NOT NULL;

-- Step 2: Make created_by temporarily nullable to avoid foreign key issues
ALTER TABLE departments MODIFY COLUMN created_by INT NULL;
ALTER TABLE job_positions MODIFY COLUMN created_by INT NULL;

-- Step 3: Get the first available admin user ID
SET @admin_user_id = (SELECT MIN(id) FROM users WHERE role IN ('system_admin') LIMIT 1);

-- If no admin user exists, create a default one
INSERT IGNORE INTO users (username, email, password_hash, role, full_name, is_active, mandate_enabled)
VALUES ('admin', 'admin@stockex.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'system_admin', 'System Administrator', 1, 0);

-- Update the admin_user_id if a new user was created
SET @admin_user_id = IFNULL(@admin_user_id, (SELECT MIN(id) FROM users WHERE role IN ('system_admin') LIMIT 1));

-- Step 4: Update columns back to NOT NULL with default value
UPDATE departments SET created_by = @admin_user_id WHERE created_by IS NULL;
UPDATE job_positions SET created_by = @admin_user_id WHERE created_by IS NULL;

ALTER TABLE departments MODIFY COLUMN created_by INT NOT NULL;
ALTER TABLE job_positions MODIFY COLUMN created_by INT NOT NULL;

-- Step 5: Success message
SELECT 'HR System setup completed successfully!' as status, 
       'You can now create HR users and use all HR modules.' as message;