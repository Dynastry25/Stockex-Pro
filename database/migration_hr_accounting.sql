-- =============================================================================
-- HR / Payroll Accounting Migration Script
-- =============================================================================
-- Run order:
--   1. Backup database
--   2. Run preflight checks (section A)
--   3. Deploy code files
--   4. Run forward migration (section B)
--   5. Verify (section C)
-- =============================================================================

-- =============================================================================
-- A. PREFLIGHT / BACKUP CHECKS
-- =============================================================================
-- Run these SELECT statements BEFORE making any changes to verify current state:

-- Check current general_ledger.reference_type enum
SELECT COLUMN_TYPE AS current_reference_type_enum
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'general_ledger'
  AND COLUMN_NAME = 'reference_type';

-- Check if payroll is already in the enum
SELECT COLUMN_TYPE LIKE '%payroll%' AS payroll_already_in_enum
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'general_ledger'
  AND COLUMN_NAME = 'reference_type';

-- Check existing chart of accounts for HR payable codes 2121-2126
SELECT id, account_code, account_name, account_type, normal_balance
FROM chart_of_accounts
WHERE account_code IN ('2121','2122','2123','2124','2125','2126','2127','2128');

-- Check existing expense accounts (51x series)
SELECT id, account_code, account_name, account_type, normal_balance, parent_id
FROM chart_of_accounts
WHERE account_code LIKE '51%' OR account_code IN ('51','512')
ORDER BY account_code;

-- Check if control/clearing accounts exist
SELECT id, account_code, account_name, account_type, normal_balance
FROM chart_of_accounts
WHERE account_code IN ('72715','72700','72114','72100','73113','73100','73101','72114','72115')
ORDER BY account_code;

-- Check if salary_previews table exists
SELECT TABLE_NAME 
FROM information_schema.TABLES 
WHERE TABLE_SCHEMA = DATABASE() 
  AND TABLE_NAME = 'salary_previews';

-- Check if salary_calculations table exists
SELECT TABLE_NAME 
FROM information_schema.TABLES 
WHERE TABLE_SCHEMA = DATABASE() 
  AND TABLE_NAME = 'salary_calculations';

-- Check existing user tables related to payroll
SELECT TABLE_NAME 
FROM information_schema.TABLES 
WHERE TABLE_SCHEMA = DATABASE() 
  AND TABLE_NAME IN ('salary_previews','salary_calculations','payroll','payroll_items','payroll_incentives','pending_pay')
ORDER BY TABLE_NAME;

-- Check existing indexes on general_ledger used for idempotency
SHOW INDEX FROM general_ledger WHERE Column_name IN ('reference_no','reference_type','account_code');

-- =============================================================================
-- B. FORWARD PRODUCTION SQL
-- =============================================================================

-- --------------------------------------------------
-- B1. Add 'payroll' to general_ledger.reference_type
-- --------------------------------------------------
ALTER TABLE general_ledger 
MODIFY COLUMN reference_type ENUM(
    'trade','fee','adjustment','receipt','payment',
    'invoice','journal','transfer','expense','income',
    'payroll'
) DEFAULT 'trade';

-- --------------------------------------------------
-- B2. Create salary_previews table (for batch payroll lifecycle)
-- --------------------------------------------------
CREATE TABLE IF NOT EXISTS salary_previews (
    id INT PRIMARY KEY AUTO_INCREMENT,
    preview_name VARCHAR(255) NOT NULL,
    pay_period_month VARCHAR(7) NOT NULL COMMENT 'YYYY-MM format',
    preview_data JSON NOT NULL COMMENT 'Full payroll calculation data',
    total_gross DECIMAL(15,2) DEFAULT 0,
    total_deductions DECIMAL(15,2) DEFAULT 0,
    total_net DECIMAL(15,2) DEFAULT 0,
    total_employer_contributions DECIMAL(15,2) DEFAULT 0,
    total_employer_cost DECIMAL(15,2) DEFAULT 0,
    status ENUM('draft','calculated','approved','posted','cancelled') DEFAULT 'draft',
    gl_posted_at DATETIME NULL COMMENT 'When GL entries were created',
    gl_posted_by INT NULL COMMENT 'User who posted to GL',
    gl_reference_no VARCHAR(100) NULL COMMENT 'Payroll batch reference for GL',
    notes TEXT,
    created_by INT NOT NULL,
    approved_by INT NULL,
    approved_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id),
    FOREIGN KEY (approved_by) REFERENCES users(id),
    FOREIGN KEY (gl_posted_by) REFERENCES users(id),
    INDEX idx_pay_period (pay_period_month),
    INDEX idx_status (status),
    INDEX idx_gl_posted (gl_posted_at),
    INDEX idx_created_by (created_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------
-- B3. Create salary_calculations table (for detailed calc data)
-- --------------------------------------------------
CREATE TABLE IF NOT EXISTS salary_calculations (
    id INT PRIMARY KEY AUTO_INCREMENT,
    payment_request_id INT NULL COMMENT 'FK to pending_pay',
    salary_preview_id INT NULL COMMENT 'FK to salary_previews',
    pay_period_month VARCHAR(7) NOT NULL,
    calculation_data JSON NOT NULL COMMENT 'Full per-employee breakdown',
    total_employees INT DEFAULT 0,
    total_gross DECIMAL(15,2) DEFAULT 0,
    total_deductions DECIMAL(15,2) DEFAULT 0,
    total_net DECIMAL(15,2) DEFAULT 0,
    total_employer_contributions DECIMAL(15,2) DEFAULT 0,
    total_employer_cost DECIMAL(15,2) DEFAULT 0,
    created_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id),
    INDEX idx_pay_period (pay_period_month),
    INDEX idx_preview (salary_preview_id),
    INDEX idx_payment_request (payment_request_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------
-- B4. Add chart-of-accounts for HR payable accounts
-- --------------------------------------------------
-- Check parent 212 (Accrued Expenses) exists
INSERT IGNORE INTO chart_of_accounts 
    (account_code, account_name, account_type, parent_id, level, normal_balance, 
     is_active, is_group_account, description, status)
SELECT '212', 'Accrued Expenses', 'liability', 
    (SELECT id FROM chart_of_accounts WHERE account_code = '21' LIMIT 1), 
    3, 'credit', 1, 0, 
    'Expenses incurred but not yet paid including statutory payroll liabilities', 
    'active'
WHERE NOT EXISTS (
    SELECT 1 FROM chart_of_accounts WHERE account_code = '212'
);

-- Insert HR Payable accounts (2121-2126) under 212
INSERT INTO chart_of_accounts 
    (account_code, account_name, account_type, parent_id, level, normal_balance, 
     is_active, is_group_account, description, status)
SELECT * FROM (
    SELECT '2121' AS account_code, 'NSSF Payable' AS account_name, 'liability' AS account_type,
        (SELECT id FROM chart_of_accounts WHERE account_code = '212' LIMIT 1) AS parent_id,
        4 AS level, 'credit' AS normal_balance,
        1 AS is_active, 0 AS is_group_account,
        'NSSF statutory deductions and employer contributions payable to NSSF' AS description,
        'active' AS status
) tmp
WHERE NOT EXISTS (SELECT 1 FROM chart_of_accounts WHERE account_code = '2121');

INSERT INTO chart_of_accounts 
    (account_code, account_name, account_type, parent_id, level, normal_balance, 
     is_active, is_group_account, description, status)
SELECT * FROM (
    SELECT '2122' AS account_code, 'SDL Payable' AS account_name, 'liability' AS account_type,
        (SELECT id FROM chart_of_accounts WHERE account_code = '212' LIMIT 1) AS parent_id,
        4 AS level, 'credit' AS normal_balance,
        1 AS is_active, 0 AS is_group_account,
        'Skills Development Levy payable to relevant authority' AS description,
        'active' AS status
) tmp
WHERE NOT EXISTS (SELECT 1 FROM chart_of_accounts WHERE account_code = '2122');

INSERT INTO chart_of_accounts 
    (account_code, account_name, account_type, parent_id, level, normal_balance, 
     is_active, is_group_account, description, status)
SELECT * FROM (
    SELECT '2123' AS account_code, 'WCF Payable' AS account_name, 'liability' AS account_type,
        (SELECT id FROM chart_of_accounts WHERE account_code = '212' LIMIT 1) AS parent_id,
        4 AS level, 'credit' AS normal_balance,
        1 AS is_active, 0 AS is_group_account,
        'Workers Compensation Fund payable' AS description,
        'active' AS status
) tmp
WHERE NOT EXISTS (SELECT 1 FROM chart_of_accounts WHERE account_code = '2123');

INSERT INTO chart_of_accounts 
    (account_code, account_name, account_type, parent_id, level, normal_balance, 
     is_active, is_group_account, description, status)
SELECT * FROM (
    SELECT '2124' AS account_code, 'OSHA Payable' AS account_name, 'liability' AS account_type,
        (SELECT id FROM chart_of_accounts WHERE account_code = '212' LIMIT 1) AS parent_id,
        4 AS level, 'credit' AS normal_balance,
        1 AS is_active, 0 AS is_group_account,
        'Occupational Safety and Health Authority levy payable' AS description,
        'active' AS status
) tmp
WHERE NOT EXISTS (SELECT 1 FROM chart_of_accounts WHERE account_code = '2124');

INSERT INTO chart_of_accounts 
    (account_code, account_name, account_type, parent_id, level, normal_balance, 
     is_active, is_group_account, description, status)
SELECT * FROM (
    SELECT '2125' AS account_code, 'Health Insurance Payable' AS account_name, 'liability' AS account_type,
        (SELECT id FROM chart_of_accounts WHERE account_code = '212' LIMIT 1) AS parent_id,
        4 AS level, 'credit' AS normal_balance,
        1 AS is_active, 0 AS is_group_account,
        'Health insurance contributions payable' AS description,
        'active' AS status
) tmp
WHERE NOT EXISTS (SELECT 1 FROM chart_of_accounts WHERE account_code = '2125');

INSERT INTO chart_of_accounts 
    (account_code, account_name, account_type, parent_id, level, normal_balance, 
     is_active, is_group_account, description, status)
SELECT * FROM (
    SELECT '2126' AS account_code, 'PAYE Payable' AS account_name, 'liability' AS account_type,
        (SELECT id FROM chart_of_accounts WHERE account_code = '212' LIMIT 1) AS parent_id,
        4 AS level, 'credit' AS normal_balance,
        1 AS is_active, 0 AS is_group_account,
        'Pay As You Earn tax payable to TRA' AS description,
        'active' AS status
) tmp
WHERE NOT EXISTS (SELECT 1 FROM chart_of_accounts WHERE account_code = '2126');

-- --------------------------------------------------
-- B5. Add Net Salary Payable / Payroll Control account
-- --------------------------------------------------
-- Under 21 (CURRENT LIABILITIES) as a sibling to 211, 212
-- We'll use code 213 for Payroll Control
INSERT INTO chart_of_accounts 
    (account_code, account_name, account_type, parent_id, level, normal_balance, 
     is_active, is_group_account, description, status)
SELECT * FROM (
    SELECT '213' AS account_code, 'Payroll Control' AS account_name, 'liability' AS account_type,
        (SELECT id FROM chart_of_accounts WHERE account_code = '21' LIMIT 1) AS parent_id,
        3 AS level, 'credit' AS normal_balance,
        1 AS is_active, 0 AS is_group_account,
        'Net salary payable to employees through payroll clearing. Credited on payroll posting, debited on salary payment.' AS description,
        'active' AS status
) tmp
WHERE NOT EXISTS (SELECT 1 FROM chart_of_accounts WHERE account_code = '213');

-- --------------------------------------------------
-- B6. Add employer contribution expense accounts
-- --------------------------------------------------
-- Under 51 (Employee Costs) as siblings to 511, 512
-- Create sub-accounts under 512 (Staff Benefits) for each statutory type
-- First make 512 a group account if it's not already
UPDATE chart_of_accounts 
SET is_group_account = 1 
WHERE account_code = '512' AND is_group_account = 0;

-- Add NSSF Employer Contribution Expense (5121 under 512)
INSERT INTO chart_of_accounts 
    (account_code, account_name, account_type, parent_id, level, normal_balance, 
     is_active, is_group_account, description, status)
SELECT * FROM (
    SELECT '5121' AS account_code, 'NSSF Employer Contribution' AS account_name, 'expense' AS account_type,
        (SELECT id FROM chart_of_accounts WHERE account_code = '512' LIMIT 1) AS parent_id,
        4 AS level, 'debit' AS normal_balance,
        1 AS is_active, 0 AS is_group_account,
        'Employer portion of NSSF contributions (expense)' AS description,
        'active' AS status
) tmp
WHERE NOT EXISTS (SELECT 1 FROM chart_of_accounts WHERE account_code = '5121');

-- Add SDL Expense (5122 under 512)
INSERT INTO chart_of_accounts 
    (account_code, account_name, account_type, parent_id, level, normal_balance, 
     is_active, is_group_account, description, status)
SELECT * FROM (
    SELECT '5122' AS account_code, 'SDL Expense' AS account_name, 'expense' AS account_type,
        (SELECT id FROM chart_of_accounts WHERE account_code = '512' LIMIT 1) AS parent_id,
        4 AS level, 'debit' AS normal_balance,
        1 AS is_active, 0 AS is_group_account,
        'Skills Development Levy expense (employer only)' AS description,
        'active' AS status
) tmp
WHERE NOT EXISTS (SELECT 1 FROM chart_of_accounts WHERE account_code = '5122');

-- Add WCF Expense (5123 under 512)
INSERT INTO chart_of_accounts 
    (account_code, account_name, account_type, parent_id, level, normal_balance, 
     is_active, is_group_account, description, status)
SELECT * FROM (
    SELECT '5123' AS account_code, 'WCF Expense' AS account_name, 'expense' AS account_type,
        (SELECT id FROM chart_of_accounts WHERE account_code = '512' LIMIT 1) AS parent_id,
        4 AS level, 'debit' AS normal_balance,
        1 AS is_active, 0 AS is_group_account,
        'Workers Compensation Fund expense (employer only)' AS description,
        'active' AS status
) tmp
WHERE NOT EXISTS (SELECT 1 FROM chart_of_accounts WHERE account_code = '5123');

-- Add OSHA Expense (5124 under 512)
INSERT INTO chart_of_accounts 
    (account_code, account_name, account_type, parent_id, level, normal_balance, 
     is_active, is_group_account, description, status)
SELECT * FROM (
    SELECT '5124' AS account_code, 'OSHA Expense' AS account_name, 'expense' AS account_type,
        (SELECT id FROM chart_of_accounts WHERE account_code = '512' LIMIT 1) AS parent_id,
        4 AS level, 'debit' AS normal_balance,
        1 AS is_active, 0 AS is_group_account,
        'Occupational Safety and Health expense (employer only)' AS description,
        'active' AS status
) tmp
WHERE NOT EXISTS (SELECT 1 FROM chart_of_accounts WHERE account_code = '5124');

-- Add Employer Health Insurance Expense (5125 under 512)
INSERT INTO chart_of_accounts 
    (account_code, account_name, account_type, parent_id, level, normal_balance, 
     is_active, is_group_account, description, status)
SELECT * FROM (
    SELECT '5125' AS account_code, 'Health Insurance Expense' AS account_name, 'expense' AS account_type,
        (SELECT id FROM chart_of_accounts WHERE account_code = '512' LIMIT 1) AS parent_id,
        4 AS level, 'debit' AS normal_balance,
        1 AS is_active, 0 AS is_group_account,
        'Employer portion of health insurance contributions' AS description,
        'active' AS status
) tmp
WHERE NOT EXISTS (SELECT 1 FROM chart_of_accounts WHERE account_code = '5125');

-- =============================================================================
-- C. VERIFICATION QUERIES (run after migration)
-- =============================================================================

-- Verify payroll reference_type was added
SELECT COLUMN_TYPE LIKE '%payroll%' AS payroll_enum_added
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'general_ledger'
  AND COLUMN_NAME = 'reference_type';

-- Verify HR payable accounts exist
SELECT account_code, account_name, account_type, normal_balance
FROM chart_of_accounts
WHERE account_code IN ('2121','2122','2123','2124','2125','2126')
ORDER BY account_code;

-- Verify Payroll Control account exists
SELECT account_code, account_name, account_type, normal_balance
FROM chart_of_accounts
WHERE account_code = '213';

-- Verify expense accounts exist
SELECT account_code, account_name, account_type, normal_balance
FROM chart_of_accounts
WHERE account_code IN ('5121','5122','5123','5124','5125')
ORDER BY account_code;

-- Verify salary_previews table exists
SELECT TABLE_NAME, CREATE_TIME
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME IN ('salary_previews','salary_calculations');

-- Verify all accounts are active
SELECT account_code, account_name, is_active, status
FROM chart_of_accounts
WHERE account_code IN ('2121','2122','2123','2124','2125','2126','213','5121','5122','5123','5124','5125')
ORDER BY account_code;

-- Verify parent-child relationships
SELECT 
    child.account_code AS child_code,
    child.account_name AS child_name,
    parent.account_code AS parent_code,
    parent.account_name AS parent_name
FROM chart_of_accounts child
LEFT JOIN chart_of_accounts parent ON child.parent_id = parent.id
WHERE child.account_code IN ('2121','2122','2123','2124','2125','2126','213','5121','5122','5123','5124','5125')
ORDER BY child.account_code;

-- Check for orphan accounts (no parent)
SELECT account_code, account_name, parent_id
FROM chart_of_accounts
WHERE account_code IN ('2121','2122','2123','2124','2125','2126','213','5121','5122','5123','5124','5125')
  AND parent_id IS NULL;

-- =============================================================================
-- D. ROLLBACK SQL (use with caution)
-- =============================================================================

-- Rollback 1: Revert reference_type enum (MySQL 8.0+)
-- WARNING: This will fail if any general_ledger rows have reference_type='payroll'
-- You must first delete/update those rows before running this:
-- UPDATE general_ledger SET reference_type = 'journal' WHERE reference_type = 'payroll';
-- UPDATE journal_entries SET reference_type = 'journal' WHERE reference_type = 'payroll';

-- ALTER TABLE general_ledger 
-- MODIFY COLUMN reference_type ENUM(
--     'trade','fee','adjustment','receipt','payment',
--     'invoice','journal','transfer','expense','income'
-- ) DEFAULT 'trade';

-- Rollback 2: Drop tables
-- DROP TABLE IF EXISTS salary_calculations;
-- DROP TABLE IF EXISTS salary_previews;

-- Rollback 3: Remove chart-of-accounts entries
-- NOTE: Only run these if no ledger entries reference these accounts!
-- DELETE FROM chart_of_accounts WHERE account_code IN ('5125','5124','5123','5122','5121');
-- DELETE FROM chart_of_accounts WHERE account_code IN ('2126','2125','2124','2123','2122','2121');
-- DELETE FROM chart_of_accounts WHERE account_code = '213';
-- If 212 was newly created:
-- DELETE FROM chart_of_accounts WHERE account_code = '212' AND id NOT IN (SELECT parent_id FROM chart_of_accounts WHERE parent_id IS NOT NULL);
-- Revert 512 back to detail account if no children remain
-- UPDATE chart_of_accounts SET is_group_account = 0 WHERE account_code = '512' 
--   AND (SELECT COUNT(*) FROM chart_of_accounts WHERE parent_id = (SELECT id FROM chart_of_accounts parent WHERE parent.account_code = '512')) = 0;
