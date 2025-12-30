-- Insert sample employees and departments for testing HR workflows
-- Run this to populate test data for the HR approval system

-- Insert test departments
INSERT IGNORE INTO departments (id, name, description, budget, status, created_by) VALUES
(1, 'Human Resources', 'Human Resources Department', 50000.00, 'active', 8),
(2, 'Finance', 'Finance and Accounting Department', 75000.00, 'active', 8),
(3, 'IT Department', 'Information Technology Department', 100000.00, 'active', 8),
(4, 'Operations', 'Operations Department', 80000.00, 'active', 8);

-- Insert test positions
INSERT IGNORE INTO job_positions (id, title, department_id, requirements, salary_min, salary_max, status, created_by) VALUES
(1, 'HR Officer', 1, 'Bachelor degree in HR or related field', 800000.00, 1200000.00, 'open', 8),
(2, 'Finance Officer', 2, 'Bachelor degree in Finance/Accounting', 900000.00, 1300000.00, 'open', 8),
(3, 'IT Specialist', 3, 'Bachelor degree in IT/Computer Science', 1000000.00, 1500000.00, 'open', 8),
(4, 'Operations Manager', 4, 'Bachelor degree with management experience', 1200000.00, 1800000.00, 'open', 8);

-- Insert test employees
INSERT INTO employees (
    employee_id, user_id, first_name, last_name, email, phone, 
    department_id, position_id, hire_date, basic_salary, currency, 
    employment_type, status, created_by
) VALUES 
-- HR Staff Employee
('EMP001', NULL, 'John', 'Doe', 'john.doe@company.com', '+255123456789', 
 1, 1, '2024-01-15', 1000000.00, 'TZS', 'full_time', 'active', 8),

-- Finance Staff Employee  
('EMP002', NULL, 'Jane', 'Smith', 'jane.smith@company.com', '+255123456790',
 2, 2, '2024-02-01', 1100000.00, 'TZS', 'full_time', 'active', 8),

-- IT Staff Employee
('EMP003', NULL, 'Mike', 'Johnson', 'mike.johnson@company.com', '+255123456791',
 3, 3, '2024-01-20', 1300000.00, 'TZS', 'full_time', 'active', 8),

-- Operations Staff Employee
('EMP004', NULL, 'Sarah', 'Wilson', 'sarah.wilson@company.com', '+255123456792',
 4, 4, '2024-03-01', 1500000.00, 'TZS', 'full_time', 'active', 8);

-- Success message
SELECT 'Test employees created successfully!' as message;
SELECT 'You can now test leave requests and payroll workflows!' as next_step;