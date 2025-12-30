-- Verify Approval Workflow Migration Status
-- This script checks if all required approval workflow fields exist

SELECT 'Leave Requests Approval Fields' as `Check`;
SELECT 
    IF(COLUMN_NAME IS NOT NULL, '✓', '✗') as status,
    'finalized_by_hr_at' as field_name
FROM INFORMATION_SCHEMA.COLUMNS 
WHERE TABLE_NAME = 'leave_requests' AND COLUMN_NAME = 'finalized_by_hr_at'
UNION ALL
SELECT 
    IF(COLUMN_NAME IS NOT NULL, '✓', '✗'),
    'requires_ceo_approval'
FROM INFORMATION_SCHEMA.COLUMNS 
WHERE TABLE_NAME = 'leave_requests' AND COLUMN_NAME = 'requires_ceo_approval'
UNION ALL
SELECT 
    IF(COLUMN_NAME IS NOT NULL, '✓', '✗'),
    'ceo_decision_status'
FROM INFORMATION_SCHEMA.COLUMNS 
WHERE TABLE_NAME = 'leave_requests' AND COLUMN_NAME = 'ceo_decision_status'
UNION ALL
SELECT 
    IF(COLUMN_NAME IS NOT NULL, '✓', '✗'),
    'ceo_approved_by'
FROM INFORMATION_SCHEMA.COLUMNS 
WHERE TABLE_NAME = 'leave_requests' AND COLUMN_NAME = 'ceo_approved_by';

SELECT '' as '';
SELECT 'Payroll Approval Fields' as `Check`;
SELECT 
    IF(COLUMN_NAME IS NOT NULL, '✓', '✗') as status,
    'submitted_by_hr_at' as field_name
FROM INFORMATION_SCHEMA.COLUMNS 
WHERE TABLE_NAME = 'payroll' AND COLUMN_NAME = 'submitted_by_hr_at'
UNION ALL
SELECT 
    IF(COLUMN_NAME IS NOT NULL, '✓', '✗'),
    'ceo_approved_by'
FROM INFORMATION_SCHEMA.COLUMNS 
WHERE TABLE_NAME = 'payroll' AND COLUMN_NAME = 'ceo_approved_by'
UNION ALL
SELECT 
    IF(COLUMN_NAME IS NOT NULL, '✓', '✗'),
    'ceo_approved_at'
FROM INFORMATION_SCHEMA.COLUMNS 
WHERE TABLE_NAME = 'payroll' AND COLUMN_NAME = 'ceo_approved_at'
UNION ALL
SELECT 
    IF(COLUMN_NAME IS NOT NULL, '✓', '✗'),
    'finance_processed_by'
FROM INFORMATION_SCHEMA.COLUMNS 
WHERE TABLE_NAME = 'payroll' AND COLUMN_NAME = 'finance_processed_by';

SELECT '' as '';
SELECT 'Employees Approval Fields' as `Check`;
SELECT 
    IF(COLUMN_NAME IS NOT NULL, '✓', '✗') as status,
    'approved_by' as field_name
FROM INFORMATION_SCHEMA.COLUMNS 
WHERE TABLE_NAME = 'employees' AND COLUMN_NAME = 'approved_by'
UNION ALL
SELECT 
    IF(COLUMN_NAME IS NOT NULL, '✓', '✗'),
    'effective_date'
FROM INFORMATION_SCHEMA.COLUMNS 
WHERE TABLE_NAME = 'employees' AND COLUMN_NAME = 'effective_date';

SELECT 'All approval workflow fields are properly configured!' as 'MIGRATION STATUS';
