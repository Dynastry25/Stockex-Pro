-- Fix admin user with correct password hash
-- This script ensures the admin user exists with proper credentials

-- Delete existing admin user if exists
DELETE FROM users WHERE username = 'admin' OR email = 'admin@stockexchange.com';

-- Create admin user with properly hashed password (admin123)
-- Using PHP's password_hash() equivalent for MySQL
INSERT INTO users (username, email, password_hash, role, full_name, is_active, mandate_enabled, created_at) 
VALUES (
    'admin', 
    'admin@stockexchange.com', 
    '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 
    'system_admin', 
    'System Administrator', 
    1, 
    1, 
    NOW()
);

-- Verify the admin user was created
SELECT id, username, email, role, full_name, is_active, mandate_enabled, created_at 
FROM users 
WHERE username = 'admin';
