-- ============================================================
-- Payroll Accounting Integration - Production Migration
-- ============================================================
-- This script adds HR/Payroll accounts, tables, and GL enum
-- values needed for payroll accounting integration.
--
-- Safe to run multiple times (uses IF NOT EXISTS / checks).
-- ============================================================

-- -----------------------------------------------------------
-- 1. Create salary_previews table (for preview snapshots)
-- -----------------------------------------------------------
CREATE TABLE IF NOT EXISTS `salary_previews` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `preview_data` longtext DEFAULT NULL,
  `pay_period_month` varchar(7) DEFAULT NULL,
  `total_employees` int(11) DEFAULT 0,
  `total_gross` decimal(15,2) DEFAULT 0.00,
  `total_deductions` decimal(15,2) DEFAULT 0.00,
  `total_net` decimal(15,2) DEFAULT 0.00,
  `total_employer_cost` decimal(15,2) DEFAULT 0.00,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- -----------------------------------------------------------
-- 2. Create salary_calculations table (persistent calc records)
-- -----------------------------------------------------------
CREATE TABLE IF NOT EXISTS `salary_calculations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `payment_request_id` int(11) DEFAULT NULL,
  `pay_period_month` varchar(7) DEFAULT NULL,
  `calculation_data` longtext DEFAULT NULL,
  `total_employees` int(11) DEFAULT 0,
  `total_gross` decimal(15,2) DEFAULT 0.00,
  `total_deductions` decimal(15,2) DEFAULT 0.00,
  `total_net` decimal(15,2) DEFAULT 0.00,
  `total_employer_contributions` decimal(15,2) DEFAULT 0.00,
  `total_employer_cost` decimal(15,2) DEFAULT 0.00,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- -----------------------------------------------------------
-- 3. Update general_ledger reference_type ENUM to include 'payroll'
-- -----------------------------------------------------------
-- MySQL does not allow adding values to ENUM inline, so we ALTER.
-- We check if 'payroll' already exists to avoid duplicates.
SET @exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
  WHERE TABLE_SCHEMA = DATABASE() 
  AND TABLE_NAME = 'general_ledger' 
  AND COLUMN_NAME = 'reference_type' 
  AND COLUMN_TYPE LIKE '%payroll%');

SET @sql = IF(@exists = 0, 
  "ALTER TABLE general_ledger 
   MODIFY COLUMN reference_type ENUM(
     'trade','payment','journal','receipt','transfer',
     'adjustment','exchange_rate','dividend','payroll'
   ) DEFAULT 'trade'",
  'SELECT 1');

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- -----------------------------------------------------------
-- 4. Chart of Accounts - Payroll-related accounts
-- -----------------------------------------------------------
-- Helper: Insert if not exists
-- 214 is taken (Short-term Borrowings), so use 216 for Payroll Control.

-- Insert accounts under 212 (Accrued Expenses) for statutory payables
INSERT IGNORE INTO chart_of_accounts 
  (account_code, account_name, account_type, parent_id, level, normal_balance, 
   is_active, is_group_account, description, status)
SELECT '2121', 'NSSF Payable', 'liability', coa.id, 4, 'credit', 1, 0,
       'NSSF statutory deductions and employer contributions payable', 'active'
FROM chart_of_accounts coa WHERE coa.account_code = '212' AND coa.is_active = 1
LIMIT 1;

INSERT IGNORE INTO chart_of_accounts 
  (account_code, account_name, account_type, parent_id, level, normal_balance, 
   is_active, is_group_account, description, status)
SELECT '2122', 'SDL Payable', 'liability', coa.id, 4, 'credit', 1, 0,
       'Skills Development Levy payable', 'active'
FROM chart_of_accounts coa WHERE coa.account_code = '212' AND coa.is_active = 1
LIMIT 1;

INSERT IGNORE INTO chart_of_accounts 
  (account_code, account_name, account_type, parent_id, level, normal_balance, 
   is_active, is_group_account, description, status)
SELECT '2123', 'WCF Payable', 'liability', coa.id, 4, 'credit', 1, 0,
       'Workers Compensation Fund payable', 'active'
FROM chart_of_accounts coa WHERE coa.account_code = '212' AND coa.is_active = 1
LIMIT 1;

INSERT IGNORE INTO chart_of_accounts 
  (account_code, account_name, account_type, parent_id, level, normal_balance, 
   is_active, is_group_account, description, status)
SELECT '2124', 'OSHA Payable', 'liability', coa.id, 4, 'credit', 1, 0,
       'Occupational Safety and Health levy payable', 'active'
FROM chart_of_accounts coa WHERE coa.account_code = '212' AND coa.is_active = 1
LIMIT 1;

INSERT IGNORE INTO chart_of_accounts 
  (account_code, account_name, account_type, parent_id, level, normal_balance, 
   is_active, is_group_account, description, status)
SELECT '2125', 'Health Insurance Payable', 'liability', coa.id, 4, 'credit', 1, 0,
       'Health insurance contributions payable (NHIF/private)', 'active'
FROM chart_of_accounts coa WHERE coa.account_code = '212' AND coa.is_active = 1
LIMIT 1;

INSERT IGNORE INTO chart_of_accounts 
  (account_code, account_name, account_type, parent_id, level, normal_balance, 
   is_active, is_group_account, description, status)
SELECT '2126', 'PAYE Payable', 'liability', coa.id, 4, 'credit', 1, 0,
       'PAYE income tax withheld from employees payable to TRA', 'active'
FROM chart_of_accounts coa WHERE coa.account_code = '212' AND coa.is_active = 1
LIMIT 1;

-- Insert 216 Payroll Control under 21 (CURRENT LIABILITIES)
INSERT IGNORE INTO chart_of_accounts 
  (account_code, account_name, account_type, parent_id, level, normal_balance, 
   is_active, is_group_account, description, status)
SELECT '216', 'Payroll Control', 'liability', coa.id, 3, 'credit', 1, 0,
       'Net salary payable to employees through payroll clearing', 'active'
FROM chart_of_accounts coa WHERE coa.account_code = '21' AND coa.is_active = 1
LIMIT 1;

-- Make 512 (Staff Benefits) a group account if it exists
UPDATE chart_of_accounts 
SET is_group_account = 1 
WHERE account_code = '512' AND is_group_account = 0;

-- Insert expense sub-accounts under 512 (Staff Benefits)
INSERT IGNORE INTO chart_of_accounts 
  (account_code, account_name, account_type, parent_id, level, normal_balance, 
   is_active, is_group_account, description, status)
SELECT '5121', 'NSSF Employer Contribution', 'expense', coa.id, 4, 'debit', 1, 0,
       'Employer portion of NSSF contributions', 'active'
FROM chart_of_accounts coa WHERE coa.account_code = '512' AND coa.is_active = 1
LIMIT 1;

INSERT IGNORE INTO chart_of_accounts 
  (account_code, account_name, account_type, parent_id, level, normal_balance, 
   is_active, is_group_account, description, status)
SELECT '5122', 'SDL Expense', 'expense', coa.id, 4, 'debit', 1, 0,
       'Skills Development Levy expense (employer only)', 'active'
FROM chart_of_accounts coa WHERE coa.account_code = '512' AND coa.is_active = 1
LIMIT 1;

INSERT IGNORE INTO chart_of_accounts 
  (account_code, account_name, account_type, parent_id, level, normal_balance, 
   is_active, is_group_account, description, status)
SELECT '5123', 'WCF Expense', 'expense', coa.id, 4, 'debit', 1, 0,
       'Workers Compensation Fund expense (employer only)', 'active'
FROM chart_of_accounts coa WHERE coa.account_code = '512' AND coa.is_active = 1
LIMIT 1;

INSERT IGNORE INTO chart_of_accounts 
  (account_code, account_name, account_type, parent_id, level, normal_balance, 
   is_active, is_group_account, description, status)
SELECT '5124', 'OSHA Expense', 'expense', coa.id, 4, 'debit', 1, 0,
       'Occupational Safety and Health expense (employer only)', 'active'
FROM chart_of_accounts coa WHERE coa.account_code = '512' AND coa.is_active = 1
LIMIT 1;

INSERT IGNORE INTO chart_of_accounts 
  (account_code, account_name, account_type, parent_id, level, normal_balance, 
   is_active, is_group_account, description, status)
SELECT '5125', 'Health Insurance Expense', 'expense', coa.id, 4, 'debit', 1, 0,
       'Employer portion of health insurance contributions', 'active'
FROM chart_of_accounts coa WHERE coa.account_code = '512' AND coa.is_active = 1
LIMIT 1;

-- -----------------------------------------------------------
-- 5. Verify all accounts were created
-- -----------------------------------------------------------
SELECT account_code, account_name, account_type, normal_balance 
FROM chart_of_accounts 
WHERE account_code IN ('2121','2122','2123','2124','2125','2126','216','512','5121','5122','5123','5124','5125')
ORDER BY account_code;
