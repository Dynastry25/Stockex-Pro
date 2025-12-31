-- Update users with employee data based on their roles
-- CEO users
UPDATE users SET
    employee_id = CONCAT('CEO-', id),
    first_name = CASE
        WHEN id = 5 THEN 'Ernest'
        WHEN id = 10 THEN 'John'
        WHEN id = 15 THEN 'Ceo'
        ELSE CONCAT('CEO', id)
    END,
    last_name = CASE
        WHEN id = 5 THEN 'CEO'
        WHEN id = 10 THEN 'Admin'
        WHEN id = 15 THEN 'Main'
        ELSE 'User'
    END,
    department_id = 1, -- HR Department
    position_id = 1,   -- HR Manager position
    phone = CONCAT('+255700', LPAD(id, 6, '0')),
    hire_date = '2024-01-01',
    basic_salary = 2000000.00,
    currency = 'TZS',
    employment_type = 'full_time'
WHERE id IN (5, 10, 15) AND role = 'ceo';

-- HR Manager users
UPDATE users SET
    employee_id = CONCAT('HRM-', id),
    first_name = CASE
        WHEN id = 8 THEN 'HR'
        WHEN id = 11 THEN 'HR1'
        WHEN id = 14 THEN 'Hr'
        ELSE CONCAT('HR', id)
    END,
    last_name = CASE
        WHEN id = 8 THEN 'Dept'
        WHEN id = 11 THEN 'Manager'
        WHEN id = 14 THEN 'Manager'
        ELSE 'Manager'
    END,
    department_id = 1, -- HR Department
    position_id = 1,   -- HR Officer position
    phone = CONCAT('+255701', LPAD(id, 6, '0')),
    hire_date = '2024-01-15',
    basic_salary = 1200000.00,
    currency = 'TZS',
    employment_type = 'full_time'
WHERE id IN (8, 11, 14) AND role = 'hr_manager';

-- Finance Officer users
UPDATE users SET
    employee_id = CONCAT('FIN-', id),
    first_name = CASE
        WHEN id = 4 THEN 'Admin2'
        WHEN id = 6 THEN 'Ernest'
        WHEN id = 7 THEN 'Finance'
        WHEN id = 9 THEN 'Admin3'
        ELSE CONCAT('Finance', id)
    END,
    last_name = CASE
        WHEN id = 4 THEN 'User'
        WHEN id = 6 THEN 'Finance'
        WHEN id = 7 THEN 'Officer'
        WHEN id = 9 THEN 'User'
        ELSE 'Staff'
    END,
    department_id = 2, -- Finance Department
    position_id = 2,   -- Finance Officer position
    phone = CONCAT('+255702', LPAD(id, 6, '0')),
    hire_date = '2024-02-01',
    basic_salary = 1100000.00,
    currency = 'TZS',
    employment_type = 'full_time'
WHERE id IN (4, 6, 7, 9) AND role = 'finance_officer';

-- Trader users
UPDATE users SET
    employee_id = CONCAT('TRD-', id),
    first_name = CASE
        WHEN id = 3 THEN 'Domina'
        WHEN id = 12 THEN 'Trader'
        WHEN id = 13 THEN 'Fpazza'
        ELSE CONCAT('Trader', id)
    END,
    last_name = CASE
        WHEN id = 3 THEN 'User'
        WHEN id = 12 THEN 'User'
        WHEN id = 13 THEN 'User'
        ELSE 'Staff'
    END,
    department_id = 4, -- Operations Department
    position_id = 4,   -- Operations Manager position
    phone = CONCAT('+255703', LPAD(id, 6, '0')),
    hire_date = '2024-03-01',
    basic_salary = 1500000.00,
    currency = 'TZS',
    employment_type = 'full_time'
WHERE id IN (3, 12, 13) AND role = 'trader';

-- Update full_name for all users where it's empty
UPDATE users
SET full_name = CONCAT(COALESCE(first_name, ''), ' ', COALESCE(last_name, ''))
WHERE (full_name IS NULL OR full_name = '')
  AND (first_name IS NOT NULL OR last_name IS NOT NULL);

SELECT 'Employee data populated successfully!' as status;