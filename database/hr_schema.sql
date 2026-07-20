-- HR Module Database Schema for Stock Exchange System
-- This schema creates all HR-related tables following existing system patterns
-- Created: December 12, 2025

-- Temporarily disable foreign key checks to allow clean table drops
SET FOREIGN_KEY_CHECKS = 0;

-- Drop existing foreign key constraints that might cause issues
ALTER TABLE departments DROP FOREIGN KEY IF EXISTS fk_departments_manager;

-- Drop existing HR tables if they exist (for clean setup)
-- Drop tables in reverse dependency order to avoid foreign key constraints
DROP TABLE IF EXISTS hr_activities;
DROP TABLE IF EXISTS payroll_items;
DROP TABLE IF EXISTS payroll;
DROP TABLE IF EXISTS performance_reviews;
DROP TABLE IF EXISTS target_reviews;
DROP TABLE IF EXISTS performance_targets;
DROP TABLE IF EXISTS employee_targets;
DROP TABLE IF EXISTS job_applications;
DROP TABLE IF EXISTS leave_requests;
DROP TABLE IF EXISTS employee_benefits;
DROP TABLE IF EXISTS employees;
DROP TABLE IF EXISTS job_positions;
DROP TABLE IF EXISTS leave_types;
DROP TABLE IF EXISTS departments;

-- Re-enable foreign key checks
SET FOREIGN_KEY_CHECKS = 1;

-- Departments table
CREATE TABLE departments (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL UNIQUE,
    description TEXT,
    manager_id INT NULL,
    budget DECIMAL(15,2) DEFAULT 0,
    status ENUM('active', 'inactive') DEFAULT 'active',
    created_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id),
    INDEX idx_status (status),
    INDEX idx_name (name)
);

-- Job Positions table
CREATE TABLE job_positions (
    id INT PRIMARY KEY AUTO_INCREMENT,
    title VARCHAR(100) NOT NULL,
    department_id INT NOT NULL,
    description TEXT,
    requirements TEXT,
    salary_min DECIMAL(15,2),
    salary_max DECIMAL(15,2),
    employment_type ENUM('full_time', 'part_time', 'contract', 'internship') DEFAULT 'full_time',
    status ENUM('open', 'closed', 'on_hold') DEFAULT 'open',
    created_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id),
    INDEX idx_department (department_id),
    INDEX idx_status (status),
    INDEX idx_title (title)
);

-- Employees table
CREATE TABLE employees (
    id INT PRIMARY KEY AUTO_INCREMENT,
    employee_id VARCHAR(20) UNIQUE NOT NULL,
    user_id INT NULL,
    first_name VARCHAR(50) NOT NULL,
    last_name VARCHAR(50) NOT NULL,
    middle_name VARCHAR(50),
    email VARCHAR(100) UNIQUE NOT NULL,
    phone VARCHAR(20),
    alternate_phone VARCHAR(20),
    address TEXT,
    national_id VARCHAR(50),
    department_id INT NOT NULL,
    position_id INT NOT NULL,
    hire_date DATE NOT NULL,
    probation_end_date DATE,
    basic_salary DECIMAL(15,2) NOT NULL DEFAULT 0,
    currency VARCHAR(3) DEFAULT 'TZS',
    employment_type ENUM('full_time', 'part_time', 'contract', 'internship') DEFAULT 'full_time',
    status ENUM('active', 'terminated', 'suspended', 'on_leave') DEFAULT 'active',
    termination_date DATE NULL,
    termination_reason TEXT NULL,
    emergency_contact_name VARCHAR(100),
    emergency_contact_phone VARCHAR(20),
    emergency_contact_relationship VARCHAR(50),
    bank_name VARCHAR(100),
    bank_account_number VARCHAR(50),
    tax_identification VARCHAR(50),
    social_security VARCHAR(50),
    created_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (department_id) REFERENCES departments(id),
    FOREIGN KEY (position_id) REFERENCES job_positions(id),
    FOREIGN KEY (created_by) REFERENCES users(id),
    INDEX idx_employee_id (employee_id),
    INDEX idx_department (department_id),
    INDEX idx_position (position_id),
    INDEX idx_status (status),
    INDEX idx_hire_date (hire_date),
    INDEX idx_email (email)
);

-- Leave Types table
CREATE TABLE leave_types (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(50) NOT NULL UNIQUE,
    description TEXT,
    days_per_year INT DEFAULT 0,
    max_consecutive_days INT DEFAULT 0,
    requires_approval BOOLEAN DEFAULT TRUE,
    is_paid BOOLEAN DEFAULT TRUE,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_name (name),
    INDEX idx_active (is_active)
);

-- Leave Requests table
CREATE TABLE leave_requests (
    id INT PRIMARY KEY AUTO_INCREMENT,
    employee_id INT NOT NULL,
    leave_type_id INT NOT NULL,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    total_days INT NOT NULL,
    reason TEXT NOT NULL,
    status ENUM('pending', 'approved', 'rejected', 'cancelled') DEFAULT 'pending',
    approved_by INT NULL,
    approved_at TIMESTAMP NULL,
    rejection_reason TEXT NULL,
    handover_notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,
    FOREIGN KEY (leave_type_id) REFERENCES leave_types(id),
    FOREIGN KEY (approved_by) REFERENCES users(id),
    INDEX idx_employee (employee_id),
    INDEX idx_status (status),
    INDEX idx_dates (start_date, end_date),
    INDEX idx_leave_type (leave_type_id)
);

-- Employee Benefits table
CREATE TABLE employee_benefits (
    id INT PRIMARY KEY AUTO_INCREMENT,
    employee_id INT NOT NULL,
    benefit_type ENUM('health_insurance', 'life_insurance', 'pension', 'allowance', 'bonus', 'other') NOT NULL,
    benefit_name VARCHAR(100) NOT NULL,
    amount DECIMAL(15,2) DEFAULT 0,
    currency VARCHAR(3) DEFAULT 'TZS',
    frequency ENUM('monthly', 'quarterly', 'yearly', 'one_time') DEFAULT 'monthly',
    is_taxable BOOLEAN DEFAULT TRUE,
    start_date DATE NOT NULL,
    end_date DATE NULL,
    is_active BOOLEAN DEFAULT TRUE,
    notes TEXT,
    created_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id),
    INDEX idx_employee (employee_id),
    INDEX idx_benefit_type (benefit_type),
    INDEX idx_active (is_active)
);

-- Job Applications table
CREATE TABLE job_applications (
    id INT PRIMARY KEY AUTO_INCREMENT,
    position_id INT NOT NULL,
    applicant_name VARCHAR(100) NOT NULL,
    email VARCHAR(100) NOT NULL,
    phone VARCHAR(20),
    address TEXT,
    education TEXT,
    experience TEXT,
    skills TEXT,
    cover_letter TEXT,
    resume_path VARCHAR(255),
    expected_salary DECIMAL(15,2),
    available_from DATE,
    status ENUM('received', 'reviewing', 'shortlisted', 'interviewed', 'selected', 'rejected', 'hired') DEFAULT 'received',
    interview_date DATETIME NULL,
    interview_notes TEXT,
    interviewer_id INT NULL,
    rejection_reason TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (position_id) REFERENCES job_positions(id) ON DELETE CASCADE,
    FOREIGN KEY (interviewer_id) REFERENCES users(id),
    INDEX idx_position (position_id),
    INDEX idx_status (status),
    INDEX idx_email (email),
    INDEX idx_interview_date (interview_date)
);

-- Performance Targets table (for targets module)
CREATE TABLE performance_targets (
    id INT PRIMARY KEY AUTO_INCREMENT,
    employee_id INT NOT NULL,
    target_type ENUM('sales', 'quality', 'productivity', 'customer_service', 'training', 'cost_reduction', 'project', 'other') NOT NULL,
    title VARCHAR(200) NOT NULL,
    description TEXT,
    target_value DECIMAL(15,2) NOT NULL,
    current_value DECIMAL(15,2) DEFAULT 0,
    unit VARCHAR(50) NOT NULL,
    progress_percentage DECIMAL(5,2) DEFAULT 0,
    priority ENUM('high', 'medium', 'low') DEFAULT 'medium',
    status ENUM('active', 'completed', 'cancelled') DEFAULT 'active',
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    completion_date DATE NULL,
    progress_notes TEXT,
    completion_notes TEXT,
    created_by INT NOT NULL,
    last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id),
    INDEX idx_employee (employee_id),
    INDEX idx_status (status),
    INDEX idx_target_type (target_type),
    INDEX idx_priority (priority),
    INDEX idx_dates (start_date, end_date)
);

-- Target Reviews table (for performance evaluation)
CREATE TABLE target_reviews (
    id INT PRIMARY KEY AUTO_INCREMENT,
    target_id INT NOT NULL,
    reviewer_id INT NOT NULL,
    rating INT NOT NULL CHECK (rating >= 1 AND rating <= 5),
    feedback TEXT NOT NULL,
    recommendations TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (target_id) REFERENCES performance_targets(id) ON DELETE CASCADE,
    FOREIGN KEY (reviewer_id) REFERENCES users(id),
    INDEX idx_target (target_id),
    INDEX idx_reviewer (reviewer_id),
    INDEX idx_rating (rating)
);

-- Employee Targets table (legacy - keeping for compatibility)
CREATE TABLE employee_targets (
    id INT PRIMARY KEY AUTO_INCREMENT,
    employee_id INT NOT NULL,
    target_period ENUM('monthly', 'quarterly', 'yearly') NOT NULL,
    period_start DATE NOT NULL,
    period_end DATE NOT NULL,
    target_type VARCHAR(100) NOT NULL,
    target_description TEXT,
    target_value DECIMAL(15,2),
    target_unit VARCHAR(50),
    actual_value DECIMAL(15,2) DEFAULT 0,
    achievement_percentage DECIMAL(5,2) DEFAULT 0,
    status ENUM('active', 'completed', 'cancelled', 'overdue') DEFAULT 'active',
    set_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,
    FOREIGN KEY (set_by) REFERENCES users(id),
    INDEX idx_employee (employee_id),
    INDEX idx_period (period_start, period_end),
    INDEX idx_status (status),
    INDEX idx_target_type (target_type)
);

-- Performance Reviews table
CREATE TABLE performance_reviews (
    id INT PRIMARY KEY AUTO_INCREMENT,
    employee_id INT NOT NULL,
    reviewer_id INT NOT NULL,
    review_period_start DATE NOT NULL,
    review_period_end DATE NOT NULL,
    overall_rating ENUM('excellent', 'good', 'satisfactory', 'needs_improvement', 'unsatisfactory') NOT NULL,
    goals_achievement_score DECIMAL(5,2) DEFAULT 0,
    competencies_score DECIMAL(5,2) DEFAULT 0,
    attendance_score DECIMAL(5,2) DEFAULT 0,
    teamwork_score DECIMAL(5,2) DEFAULT 0,
    communication_score DECIMAL(5,2) DEFAULT 0,
    leadership_score DECIMAL(5,2) DEFAULT 0,
    total_score DECIMAL(5,2) DEFAULT 0,
    strengths TEXT,
    areas_for_improvement TEXT,
    development_plan TEXT,
    employee_comments TEXT,
    reviewer_comments TEXT,
    next_review_date DATE,
    status ENUM('draft', 'submitted', 'reviewed', 'approved') DEFAULT 'draft',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,
    FOREIGN KEY (reviewer_id) REFERENCES users(id),
    INDEX idx_employee (employee_id),
    INDEX idx_reviewer (reviewer_id),
    INDEX idx_period (review_period_start, review_period_end),
    INDEX idx_status (status)
);

-- Payroll table
CREATE TABLE payroll (
    id INT PRIMARY KEY AUTO_INCREMENT,
    employee_id INT NOT NULL,
    pay_period_start DATE NOT NULL,
    pay_period_end DATE NOT NULL,
    pay_frequency ENUM('weekly', 'bi_weekly', 'monthly', 'quarterly') DEFAULT 'monthly',
    basic_salary DECIMAL(15,2) NOT NULL DEFAULT 0,
    total_allowances DECIMAL(15,2) DEFAULT 0,
    total_deductions DECIMAL(15,2) DEFAULT 0,
    overtime_hours DECIMAL(5,2) DEFAULT 0,
    overtime_rate DECIMAL(15,2) DEFAULT 0,
    overtime_amount DECIMAL(15,2) DEFAULT 0,
    gross_pay DECIMAL(15,2) NOT NULL DEFAULT 0,
    tax_amount DECIMAL(15,2) DEFAULT 0,
    social_security_amount DECIMAL(15,2) DEFAULT 0,
    net_pay DECIMAL(15,2) NOT NULL DEFAULT 0,
    currency VARCHAR(3) DEFAULT 'TZS',
    status ENUM('draft', 'calculated', 'approved', 'paid', 'cancelled') DEFAULT 'draft',
    payment_date DATE NULL,
    payment_method ENUM('bank_transfer', 'cash', 'cheque', 'mobile_money') DEFAULT 'bank_transfer',
    payment_reference VARCHAR(100),
    processed_by INT NOT NULL,
    approved_by INT NULL,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,
    FOREIGN KEY (processed_by) REFERENCES users(id),
    FOREIGN KEY (approved_by) REFERENCES users(id),
    INDEX idx_employee (employee_id),
    INDEX idx_pay_period (pay_period_start, pay_period_end),
    INDEX idx_status (status),
    INDEX idx_payment_date (payment_date),
    UNIQUE KEY unique_employee_period (employee_id, pay_period_start, pay_period_end)
);

-- Payroll Items table (for detailed breakdown)
CREATE TABLE payroll_items (
    id INT PRIMARY KEY AUTO_INCREMENT,
    payroll_id INT NOT NULL,
    item_type ENUM('allowance', 'deduction', 'bonus', 'overtime', 'tax', 'social_security') NOT NULL,
    item_name VARCHAR(100) NOT NULL,
    amount DECIMAL(15,2) NOT NULL DEFAULT 0,
    is_taxable BOOLEAN DEFAULT TRUE,
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (payroll_id) REFERENCES payroll(id) ON DELETE CASCADE,
    INDEX idx_payroll (payroll_id),
    INDEX idx_item_type (item_type)
);

-- HR Activities table (for audit trail)
CREATE TABLE hr_activities (
    id INT PRIMARY KEY AUTO_INCREMENT,
    activity_type ENUM('employee_created', 'employee_updated', 'employee_terminated', 'leave_request', 'leave_approved', 'leave_rejected', 'payroll_processed', 'performance_review', 'job_application', 'target_set') NOT NULL,
    entity_type ENUM('employee', 'leave', 'payroll', 'application', 'target', 'review') NOT NULL,
    entity_id INT NOT NULL,
    description TEXT NOT NULL,
    performed_by INT NOT NULL,
    performed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (performed_by) REFERENCES users(id),
    INDEX idx_activity_type (activity_type),
    INDEX idx_entity (entity_type, entity_id),
    INDEX idx_performed_by (performed_by),
    INDEX idx_performed_at (performed_at)
);

-- Update departments table to add self-referencing foreign key for manager
ALTER TABLE departments 
ADD CONSTRAINT fk_departments_manager 
FOREIGN KEY (manager_id) REFERENCES employees(id) ON DELETE SET NULL;

-- Insert default leave types
INSERT INTO leave_types (name, description, days_per_year, max_consecutive_days, requires_approval, is_paid) VALUES
('Annual Leave', 'Annual vacation leave', 21, 21, TRUE, TRUE),
('Sick Leave', 'Medical leave for illness', 14, 7, TRUE, TRUE),
('Maternity Leave', 'Maternity leave for new mothers', 84, 84, TRUE, TRUE),
('Paternity Leave', 'Paternity leave for new fathers', 7, 7, TRUE, TRUE),
('Emergency Leave', 'Emergency personal leave', 3, 3, TRUE, FALSE),
('Compassionate Leave', 'Leave for family emergencies', 5, 5, TRUE, TRUE),
('Study Leave', 'Educational development leave', 10, 10, TRUE, FALSE),
('Unpaid Leave', 'Unpaid personal leave', 0, 30, TRUE, FALSE);

-- Insert default departments (matching AVAILABLE_DEPARTMENTS from config)
-- First, get the first available admin user ID or create a system user
SET @admin_user_id = (SELECT MIN(id) FROM users WHERE role IN ('system_admin', 'admin') LIMIT 1);

-- If no admin user exists, we'll insert departments without created_by initially
INSERT INTO departments (name, description, created_by, status) VALUES
('HR Department', 'Human Resources Department', IFNULL(@admin_user_id, 1), 'active'),
('Finance Department', 'Finance and Accounting Department', IFNULL(@admin_user_id, 1), 'active'),
('IT Department', 'Information Technology Department', IFNULL(@admin_user_id, 1), 'active'),
('Operations', 'Operations Department', IFNULL(@admin_user_id, 1), 'active'),
('Sales & Marketing', 'Sales and Marketing Department', IFNULL(@admin_user_id, 1), 'active'),
('Legal Department', 'Legal Affairs Department', IFNULL(@admin_user_id, 1), 'active');

-- Insert default job positions
INSERT INTO job_positions (title, department_id, description, created_by, status) VALUES
-- HR Department positions
('HR Manager', 1, 'Lead Human Resources operations', IFNULL(@admin_user_id, 1), 'open'),
('HR Officer', 1, 'Support HR operations and employee relations', IFNULL(@admin_user_id, 1), 'open'),
('Recruitment Specialist', 1, 'Handle recruitment and talent acquisition', IFNULL(@admin_user_id, 1), 'open'),
-- Finance Department positions
('Finance Manager', 2, 'Lead Finance and Accounting operations', IFNULL(@admin_user_id, 1), 'open'),
('Finance Officer', 2, 'Handle financial transactions and reporting', IFNULL(@admin_user_id, 1), 'open'),
('Accountant', 2, 'Manage accounting and bookkeeping', IFNULL(@admin_user_id, 1), 'open'),
-- IT Department positions
('IT Manager', 3, 'Lead IT operations and systems', IFNULL(@admin_user_id, 1), 'open'),
('Software Developer', 3, 'Develop and maintain software systems', IFNULL(@admin_user_id, 1), 'open'),
('System Administrator', 3, 'Manage IT infrastructure', IFNULL(@admin_user_id, 1), 'open'),
-- Operations positions
('Operations Manager', 4, 'Lead daily operations', IFNULL(@admin_user_id, 1), 'open'),
('Operations Officer', 4, 'Support operational activities', IFNULL(@admin_user_id, 1), 'open'),
-- Sales & Marketing positions
('Sales Manager', 5, 'Lead sales and marketing efforts', IFNULL(@admin_user_id, 1), 'open'),
('Marketing Officer', 5, 'Handle marketing and promotional activities', IFNULL(@admin_user_id, 1), 'open'),
-- Legal Department positions
('Legal Counsel', 6, 'Provide legal advice and support', IFNULL(@admin_user_id, 1), 'open'),
('Compliance Officer', 6, 'Ensure regulatory compliance', IFNULL(@admin_user_id, 1), 'open');

-- Success message
SELECT 'HR Database Schema created successfully!' as message;