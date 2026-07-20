-- Approval Workflows Audit Table
-- This table provides comprehensive audit trail for all approval workflows
-- Run this after approval_workflow_migrations.sql

-- ===============================================
-- APPROVAL WORKFLOWS - Comprehensive audit table
-- ===============================================

CREATE TABLE approval_workflows (
    id INT PRIMARY KEY AUTO_INCREMENT,
    
    -- Workflow identification
    workflow_type ENUM('leave', 'payroll', 'recruitment', 'target', 'employee_change') NOT NULL,
    entity_type VARCHAR(50) NOT NULL,  -- leave_request, payroll, job_application, performance_target, employee
    entity_id INT NOT NULL,
    
    -- Workflow metadata
    initiated_by INT NOT NULL,
    initiated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    current_status VARCHAR(50) NOT NULL,
    workflow_status ENUM('in_progress', 'completed', 'rejected', 'cancelled') DEFAULT 'in_progress',
    
    -- HR Action Stage
    hr_action_status ENUM('pending', 'approved', 'escalated', 'rejected') DEFAULT 'pending',
    hr_action_by INT NULL,
    hr_action_at TIMESTAMP NULL,
    hr_action_reason VARCHAR(500) NULL,
    
    -- CEO Action Stage  
    ceo_action_required BOOLEAN DEFAULT FALSE,
    ceo_action_status ENUM('pending', 'approved', 'rejected') DEFAULT NULL,
    ceo_action_by INT NULL,
    ceo_action_at TIMESTAMP NULL,
    ceo_action_reason VARCHAR(500) NULL,
    
    -- Finance Action Stage (for payroll)
    finance_action_required BOOLEAN DEFAULT FALSE,
    finance_action_status ENUM('pending', 'processed', 'rejected') DEFAULT NULL,
    finance_action_by INT NULL,
    finance_action_at TIMESTAMP NULL,
    finance_action_notes VARCHAR(500) NULL,
    
    -- Audit metadata
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    -- Foreign keys
    FOREIGN KEY (initiated_by) REFERENCES users(id),
    FOREIGN KEY (hr_action_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (ceo_action_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (finance_action_by) REFERENCES users(id) ON DELETE SET NULL,
    
    -- Indexes for performance
    INDEX idx_workflow_entity (workflow_type, entity_type, entity_id),
    INDEX idx_workflow_status (workflow_status),
    INDEX idx_current_status (current_status),
    INDEX idx_ceo_pending (ceo_action_required, ceo_action_status),
    INDEX idx_finance_pending (finance_action_required, finance_action_status),
    INDEX idx_initiated_by (initiated_by),
    INDEX idx_created_at (created_at)
);

-- ===============================================
-- APPROVAL NOTIFICATIONS - For future notification system
-- ===============================================

CREATE TABLE approval_notifications (
    id INT PRIMARY KEY AUTO_INCREMENT,
    
    -- Notification details
    workflow_id INT NOT NULL,
    notification_type ENUM('escalation', 'approval_required', 'approved', 'rejected', 'reminder') NOT NULL,
    recipient_user_id INT NOT NULL,
    recipient_role VARCHAR(50) NOT NULL,
    
    -- Message content
    title VARCHAR(255) NOT NULL,
    message TEXT NOT NULL,
    action_url VARCHAR(500) NULL,
    
    -- Status tracking
    status ENUM('pending', 'sent', 'read', 'dismissed') DEFAULT 'pending',
    sent_at TIMESTAMP NULL,
    read_at TIMESTAMP NULL,
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    -- Foreign keys
    FOREIGN KEY (workflow_id) REFERENCES approval_workflows(id) ON DELETE CASCADE,
    FOREIGN KEY (recipient_user_id) REFERENCES users(id) ON DELETE CASCADE,
    
    -- Indexes
    INDEX idx_recipient (recipient_user_id, status),
    INDEX idx_workflow (workflow_id),
    INDEX idx_status (status),
    INDEX idx_notification_type (notification_type)
);

-- ===============================================
-- APPROVAL SETTINGS - Configuration for approval rules
-- ===============================================

CREATE TABLE approval_settings (
    id INT PRIMARY KEY AUTO_INCREMENT,
    
    -- Setting identification
    setting_key VARCHAR(100) NOT NULL UNIQUE,
    setting_name VARCHAR(200) NOT NULL,
    setting_description TEXT,
    
    -- Setting value and metadata
    setting_value TEXT NOT NULL,
    setting_type ENUM('boolean', 'integer', 'string', 'json') DEFAULT 'string',
    is_active BOOLEAN DEFAULT TRUE,
    
    -- Audit
    created_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_by INT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (created_by) REFERENCES users(id),
    FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL,
    
    INDEX idx_setting_key (setting_key),
    INDEX idx_is_active (is_active)
);

-- ===============================================
-- INSERT DEFAULT APPROVAL SETTINGS
-- ===============================================

INSERT INTO approval_settings (setting_key, setting_name, setting_description, setting_value, setting_type, created_by) VALUES
('leave_auto_ceo_escalation_days', 'Auto CEO Escalation Days', 'Automatically require CEO approval for leaves exceeding this many days', '0', 'integer', 1),
('payroll_ceo_approval_required', 'Payroll CEO Approval Required', 'Whether all payroll submissions require CEO approval', 'true', 'boolean', 1),
('target_ceo_approval_required', 'Target CEO Approval Required', 'Whether performance targets require CEO approval by default', 'false', 'boolean', 1),
('recruitment_ceo_hire_approval', 'Recruitment CEO Hire Approval', 'Whether hiring decisions require CEO approval', 'true', 'boolean', 1),
('employee_change_ceo_approval', 'Employee Change CEO Approval', 'Whether employee changes (salary, position) require CEO approval', 'true', 'boolean', 1),
('notification_enabled', 'Notifications Enabled', 'Whether approval notifications are sent', 'true', 'boolean', 1),
('audit_retention_days', 'Audit Retention Days', 'Number of days to retain audit records', '2555', 'integer', 1);

-- Success message
SELECT 'Approval workflows audit system created successfully!' as message;
SELECT 'Created tables: approval_workflows, approval_notifications, approval_settings' as details;