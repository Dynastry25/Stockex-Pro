# Migration Guide: Consolidate Employees → Users Table

## Overview
This migration consolidates the `employees` table into the `users` table, eliminating data duplication and simplifying the employee management system.

## Database Changes Summary

### Columns Added to `users` Table
- `employee_id` varchar(20) - Unique employee identifier
- `first_name` varchar(50) - Employee first name
- `last_name` varchar(50) - Employee last name
- `middle_name` varchar(50) - Employee middle name
- `phone` varchar(20) - Primary phone number
- `alternate_phone` varchar(20) - Alternative phone number
- `address` text - Employee address
- `national_id` varchar(50) - National ID (unique)
- `department_id` int - Reference to departments table
- `position_id` int - Reference to job_positions table
- `hire_date` date - Employment start date
- `probation_end_date` date - Probation end date
- `employment_type` enum('full_time','part_time','contract','internship') - Type of employment
- `termination_date` date - Employment end date (if applicable)
- `termination_reason` text - Reason for termination
- `approved_by` int - User ID of approver
- `approved_at` timestamp - Approval timestamp
- `effective_date` date - Effective date of changes
- `change_reason` varchar(500) - Reason for changes
- `emergency_contact_name` varchar(100) - Emergency contact name
- `emergency_contact_phone` varchar(20) - Emergency contact phone
- `emergency_contact_relationship` varchar(50) - Relationship to employee
- `bank_name` varchar(100) - Bank name for salary payments
- `bank_account_number` varchar(50) - Bank account number
- `tax_identification` varchar(50) - Tax ID
- `social_security` varchar(50) - Social security number

### Table Dropped
- `employees` table (after data migration)

### Foreign Keys Updated
- `employee_benefits.user_id` → `users.id`
- `users.department_id` → `departments.id`
- `users.position_id` → `job_positions.id`

---

## PHP Files Requiring Updates

### 1. **hr/employees.php**
**Changes needed:**
- Line ~28: Change `employees` table queries to `users`
- Line ~45: Update INSERT/UPDATE statements
- Line ~615: Change `SELECT * FROM employees` to `SELECT * FROM users`
- Update all field selections to use new column names

**Key replacements:**
```php
// OLD
SELECT * FROM employees WHERE status = 'active'
// NEW
SELECT * FROM users WHERE status = 'active'

// OLD
UPDATE employees SET ... WHERE id = ?
// NEW
UPDATE users SET ... WHERE id = ?

// OLD
INSERT INTO employees (...) VALUES (...)
// NEW
INSERT INTO users (...) VALUES (...)
```

### 2. **hr/employee_details.php**
**Changes needed:**
- Line ~20: Update the LEFT JOIN to use `users` table
- All field references must match the new `users` column names
- Update first_name/last_name handling (no longer in separate columns in employees table)

**Key update:**
```php
// OLD
SELECT u.*, e.employee_id, e.first_name, e.last_name FROM users u
LEFT JOIN employees e ON u.id = e.user_id WHERE u.id = ?

// NEW
SELECT * FROM users WHERE id = ?
```

### 3. **hr/payroll.php**
**Changes needed:**
- Line ~50: Update user selection query
- Update all references from `employees` to `users` table
- Ensure proper column mapping for salary, department, position

**Key changes:**
```php
// OLD
SELECT u.*, e.salary, e.department_id FROM users u
LEFT JOIN employees e ON u.id = e.user_id

// NEW
SELECT * FROM users WHERE id = ?
```

### 4. **hr/dashboard.php**
**Changes needed:**
- Line ~13: Update employee count query
- Line ~76: Change validation query
- Line ~262, 338, 469, 615: Update all employee queries

**Key changes:**
```php
// OLD
$total_employees = $db->query("SELECT COUNT(*) FROM employees WHERE status = 'active'")->fetchColumn();

// NEW
$total_employees = $db->query("SELECT COUNT(*) FROM users WHERE status = 'active'")->fetchColumn();
```

### 5. **hr/leave_management.php**
**Changes needed:**
- Line ~109: Update employee existence check
- Line ~158, 198, 216: Update JOIN clauses

**Key change:**
```php
// OLD
SELECT e.id FROM employees e WHERE e.id = ?

// NEW
SELECT id FROM users WHERE id = ?
```

### 6. **hr/reports.php**
**Changes needed:**
- Line ~16: Update LEFT JOIN to use users table
- Line ~20: Update turnover rate query

**Key changes:**
```php
// OLD
LEFT JOIN employees e ON d.id = e.department_id AND e.status = 'active'
FROM employees

// NEW
LEFT JOIN users u ON d.id = u.department_id AND u.status = 'active'
FROM users
```

### 7. **hr/targets.php**
**Changes needed:**
- Line ~125, 144: Update employee JOIN queries
- Line ~139: Update employee dropdown population

**Key changes:**
```php
// OLD
FROM employees e
JOIN employees e ON pt.employee_id = e.id

// NEW
FROM users
WHERE role IN ('trader', 'trader', 'hr_manager', 'finance_officer')
```

### 8. **hr/recruitment.php**
**Changes needed:**
- Line ~107, 126: Update LEFT JOIN to use users table instead of employees

**Key changes:**
```php
// OLD
LEFT JOIN employees e ON u.id = e.user_id

// NEW
-- Already using users table, just remove the join or reference users directly
```

### 9. **hr/test_payroll.php**
**Changes needed:**
- Line ~58: Update employee selection query

**Key change:**
```php
// OLD
FROM employees e

// NEW
FROM users WHERE salary > 0
```

---

## Database Execution Steps

1. **Backup your database:**
   ```bash
   mysqldump -u root -p jrozqhmy_stock_exchange_db > backup_$(date +%Y%m%d_%H%M%S).sql
   ```

2. **Review the migration script:**
   - Open: `database/MIGRATION_EMPLOYEES_TO_USERS.sql`
   - Verify all statements are appropriate for your environment

3. **Execute the migration:**
   ```bash
   mysql -u root -p jrozqhmy_stock_exchange_db < database/MIGRATION_EMPLOYEES_TO_USERS.sql
   ```

4. **Verify the migration:**
   ```sql
   -- Check users table has employee data
   SELECT COUNT(*) as total_users FROM users;
   SELECT COUNT(*) as users_with_employee_data FROM users WHERE first_name IS NOT NULL;
   
   -- Verify employees table is gone
   SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES 
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'employees';
   
   -- Should return empty result set
   ```

---

## Testing Checklist

After migration, verify these functionality:

- [ ] Employee listing page displays all employees correctly
- [ ] Employee details view shows complete information
- [ ] Payroll generation works with new user structure
- [ ] Leave management functionality intact
- [ ] Reports display employee data correctly
- [ ] HR dashboard shows accurate employee counts
- [ ] All forms update employee information correctly
- [ ] No SQL errors in error logs
- [ ] Employee search/filter works correctly

---

## Rollback Plan

If issues arise, restore from backup:
```bash
mysql -u root -p jrozqhmy_stock_exchange_db < backup_YYYYMMDD_HHMMSS.sql
```

---

## Important Notes

1. **Column Name Mapping:**
   - The `users` table now has `first_name` and `last_name` separate columns
   - The `full_name` field is still maintained for backward compatibility
   - Update your application code to use first_name/last_name where appropriate

2. **Foreign Key Relationships:**
   - `employee_benefits` now uses `user_id` instead of `employee_id`
   - Ensure other tables referencing employees are updated

3. **Salary Field:**
   - The `salary` column already exists in `users` table
   - The migration uses the existing `salary` field from `users`

4. **Status Field:**
   - The `status` field in `users` now serves both user status and employment status
   - Consider: `active` = actively employed, `inactive` = terminated or inactive

5. **No Manual Data Entry:**
   - All employee data is automatically migrated
   - No need to manually create user records

---

## Support

For issues during migration:
1. Check the error log output
2. Verify the database backup exists
3. Review the PHP file changes needed
4. Test on a development environment first

