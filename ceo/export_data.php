<?php
require_once '../config/config.php';
require_once '../auth/auth_middleware.php';
require_once '../includes/payroll_helpers.php';
require_once dirname(__DIR__) . '/reports/traits/ReportHeaderTrait.php';

// Only CEO can access
require_ceo();

$db = getDBConnection();

// Get parameters
$export_type = $_GET['type'] ?? 'pending';
$export_format = $_GET['format'] ?? 'excel';
$month = $_GET['month'] ?? date('Y-m');
$include_headers = $_GET['headers'] ?? 1;
$include_totals = $_GET['totals'] ?? 1;
$selected_ids = $_GET['selected'] ?? '';
$package_type = $_GET['package'] ?? 'all'; // all, summary, detailed

// Get data based on export type
$data = [];
$filename = '';
$title = '';

switch ($export_type) {
    case 'pending':
        $data = getPendingIncentives($db, $month, $selected_ids);
        $filename = 'pending_incentives_' . date('Y-m-d');
        $title = 'Pending Incentives - ' . date('F Y', strtotime($month . '-01'));
        break;
    
    case 'approved':
        $data = getApprovedIncentives($db, $month);
        $filename = 'approved_incentives_' . date('Y-m-d');
        $title = 'Approved Incentives - ' . date('F Y', strtotime($month . '-01'));
        break;
    
    case 'rejected':
        $data = getRejectedIncentives($db, $month);
        $filename = 'rejected_incentives_' . date('Y-m-d');
        $title = 'Rejected Incentives - ' . date('F Y', strtotime($month . '-01'));
        break;
    
    case 'salary':
        $data = getEmployeeSalaries($db);
        $filename = 'employee_salaries_' . date('Y-m-d');
        $title = 'Employee Salary Overview';
        break;
    
    case 'all_incentives':
        $data = getAllIncentives($db, $month);
        $filename = 'all_incentives_' . date('Y-m-d');
        $title = 'All Incentives - ' . date('F Y', strtotime($month . '-01'));
        break;
    
    case 'salary_package':
        $data = getSalaryPackage($db, $month, $package_type);
        $filename = 'salary_package_' . $package_type . '_' . date('Y-m-d');
        $title = 'Salary Package Report - ' . date('F Y', strtotime($month . '-01'));
        break;
}

// Export based on format
switch ($export_format) {
    case 'excel':
        exportExcel($data, $filename, $title, $export_type, $include_headers, $include_totals);
        break;
    
    case 'csv':
        exportCSV($data, $filename, $title, $export_type, $include_headers, $include_totals);
        break;
    
    case 'pdf':
        exportPDFWithTCPDF($data, $filename, $title, $export_type, $month, $include_headers, $include_totals, $package_type);
        break;
    
    default:
        exportExcel($data, $filename, $title, $export_type, $include_headers, $include_totals);
}

// Data retrieval functions
function getPendingIncentives($db, $month, $selected_ids = '') {
    $query = "
        SELECT pi.*, u.full_name, u.username, u.job_title, u.department,
               creator.full_name as created_by_name, creator.username as created_by_username,
               u.salary as employee_salary
        FROM payroll_incentives pi
        JOIN users u ON pi.user_id = u.id
        JOIN users creator ON pi.created_by = creator.id
        WHERE pi.status = 'pending'
        AND pi.pay_period_month = ?
    ";
    
    $params = [$month];
    
    if (!empty($selected_ids)) {
        $ids = explode(',', $selected_ids);
        $placeholders = str_repeat('?,', count($ids) - 1) . '?';
        $query .= " AND pi.id IN ($placeholders)";
        $params = array_merge($params, $ids);
    }
    
    $query .= " ORDER BY pi.created_at DESC, u.full_name";
    
    $stmt = $db->prepare($query);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function getApprovedIncentives($db, $month) {
    $query = "
        SELECT pi.*, u.full_name, u.username, u.job_title,
               approver.full_name as approved_by_name
        FROM payroll_incentives pi
        JOIN users u ON pi.user_id = u.id
        LEFT JOIN users approver ON pi.approved_by = approver.id
        WHERE pi.status = 'approved'
        AND pi.pay_period_month = ?
        ORDER BY pi.incentive_type, u.full_name
    ";
    
    $stmt = $db->prepare($query);
    $stmt->execute([$month]);
    return $stmt->fetchAll();
}

function getRejectedIncentives($db, $month) {
    $query = "
        SELECT pi.*, u.full_name, u.username, u.job_title,
               approver.full_name as approved_by_name
        FROM payroll_incentives pi
        JOIN users u ON pi.user_id = u.id
        LEFT JOIN users approver ON pi.approved_by = approver.id
        WHERE pi.status = 'rejected'
        AND pi.pay_period_month = ?
        ORDER BY pi.created_at DESC
    ";
    
    $stmt = $db->prepare($query);
    $stmt->execute([$month]);
    return $stmt->fetchAll();
}

function getEmployeeSalaries($db) {
    $query = "
        SELECT u.id, u.username, u.full_name, 
               u.salary, u.currency, u.job_title,
               u.salary_level, u.hire_date, u.last_salary_review,
               u.status, u.department
        FROM users u
        WHERE u.status = 'active'
        AND u.role NOT IN ('system_admin')
        ORDER BY u.full_name
    ";
    
    $stmt = $db->query($query);
    return $stmt->fetchAll();
}

function getAllIncentives($db, $month) {
    $query = "
        SELECT pi.*, u.full_name, u.username, u.job_title,
               creator.full_name as created_by_name,
               approver.full_name as approved_by_name
        FROM payroll_incentives pi
        JOIN users u ON pi.user_id = u.id
        JOIN users creator ON pi.created_by = creator.id
        LEFT JOIN users approver ON pi.approved_by = approver.id
        WHERE pi.pay_period_month = ?
        ORDER BY pi.status, pi.incentive_type, u.full_name
    ";
    
    $stmt = $db->prepare($query);
    $stmt->execute([$month]);
    return $stmt->fetchAll();
}

function getSalaryPackage($db, $month, $package_type = 'all') {
    $data = [];
    
    // Get basic employee data
    $employees = getEmployeeSalaries($db);
    
    // Get approved incentives for the month
    $incentives_query = "
        SELECT pi.*, u.full_name
        FROM payroll_incentives pi
        JOIN users u ON pi.user_id = u.id
        WHERE pi.status = 'approved'
        AND pi.pay_period_month = ?
    ";
    
    $incentives_stmt = $db->prepare($incentives_query);
    $incentives_stmt->execute([$month]);
    $all_incentives = $incentives_stmt->fetchAll();
    
    // Get pending incentives
    $pending_query = "
        SELECT pi.*, u.full_name
        FROM payroll_incentives pi
        JOIN users u ON pi.user_id = u.id
        WHERE pi.status = 'pending'
        AND pi.pay_period_month = ?
    ";
    
    $pending_stmt = $db->prepare($pending_query);
    $pending_stmt->execute([$month]);
    $pending_incentives = $pending_stmt->fetchAll();
    
    // Organize data by employee
    foreach ($employees as $employee) {
        $employee_id = $employee['id'];
        $salary = (float)$employee['salary'];
        
        // Get incentives for this employee
        $employee_incentives = array_filter($all_incentives, function($inc) use ($employee_id) {
            return $inc['user_id'] == $employee_id;
        });
        
        $employee_pending = array_filter($pending_incentives, function($inc) use ($employee_id) {
            return $inc['user_id'] == $employee_id;
        });
        
        // Calculate totals
        $total_bonus = 0;
        $total_allowance = 0;
        $total_commission = 0;
        $total_overtime = 0;
        $total_deduction = 0;
        $total_incentives = 0;
        
        foreach ($employee_incentives as $inc) {
            $amount = (float)$inc['amount'];
            switch ($inc['incentive_type']) {
                case 'bonus':
                    $total_bonus += $amount;
                    break;
                case 'allowance':
                    $total_allowance += $amount;
                    break;
                case 'commission':
                    $total_commission += $amount;
                    break;
                case 'overtime':
                    $total_overtime += $amount;
                    break;
                case 'deduction':
                    $total_deduction += $amount;
                    break;
            }
            $total_incentives += $inc['incentive_type'] == 'deduction' ? -$amount : $amount;
        }
        
        // Calculate pending amounts
        $pending_total = 0;
        foreach ($employee_pending as $inc) {
            $amount = (float)$inc['amount'];
            $pending_total += $inc['incentive_type'] == 'deduction' ? -$amount : $amount;
        }
        
        // Calculate net salary
        $gross_salary = $salary + $total_incentives;
        $net_salary = $gross_salary - $total_deduction;
        
        $data[] = [
            'employee' => $employee,
            'salary' => $salary,
            'incentives' => $employee_incentives,
            'pending_incentives' => $employee_pending,
            'totals' => [
                'bonus' => $total_bonus,
                'allowance' => $total_allowance,
                'commission' => $total_commission,
                'overtime' => $total_overtime,
                'deduction' => $total_deduction,
                'incentives_total' => $total_incentives,
                'pending_total' => $pending_total,
                'gross_salary' => $gross_salary,
                'net_salary' => $net_salary
            ]
        ];
    }
    
    return $data;
}

// Export functions
function exportExcel($data, $filename, $title, $type, $include_headers, $include_totals) {
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="' . $filename . '.xls"');
    
    // ... existing Excel export code ...
    // (Keep your existing Excel export function)
}

function exportCSV($data, $filename, $title, $type, $include_headers, $include_totals) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
    
    // ... existing CSV export code ...
    // (Keep your existing CSV export function)
}

function exportPDFWithTCPDF($data, $filename, $title, $type, $month, $include_headers, $include_totals, $package_type = 'all') {
    // Include TCPDF library
    require_once('../vendor/autoload.php'); // Adjust path as needed
    
    // Create new PDF document
    $pdf = new TCPDF('L', 'mm', 'A4', true, 'UTF-8', false);
    
    // Set document information
    $pdf->SetCreator('Payroll System');
    $pdf->SetAuthor('CEO');
    $pdf->SetTitle($title);
    $pdf->SetSubject('Payroll Export');
    
    // Remove default header/footer
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    
    // Set margins
    $pdf->SetMargins(10, 30, 10);
    $pdf->SetAutoPageBreak(TRUE, 15);
    
    // Add a page
    $pdf->AddPage();
    renderVfslPdfHeader($pdf);
    
    // Set font
    $pdf->SetFont('helvetica', '', 10);
    
    // Add title
    $pdf->SetFont('helvetica', 'B', 14);
    $pdf->Cell(0, 10, $title, 0, 1, 'C');
    $pdf->SetFont('helvetica', '', 10);
    $pdf->Ln(5);
    
    $explanation = '<div style="background-color: #f0f4f8; border-left: 4px solid #002e92; padding: 8px 12px; margin: 5px 0 10px 0; font-size: 8px; color: #333;">
        <strong>Report Overview:</strong> This Payroll Report provides a detailed breakdown of employee salary packages, 
        including basic salary, allowances, statutory deductions (PAYE, SDL, CSF), and net pay for the specified period.<br/>
        <strong>How to Read:</strong> Each employee entry shows the gross salary components, followed by all applicable deductions, 
        and the final net amount payable. All amounts are in Tanzanian Shillings (TZS).<br/>
        <strong>Purpose:</strong> Use this for payroll verification, budget planning, and regulatory compliance.
    </div>';
    $pdf->writeHTML($explanation, true, false, true, false, '');
    
    switch ($type) {
        case 'salary_package':
            exportSalaryPackagePDF($pdf, $data, $package_type, $month);
            break;
            
        case 'pending':
        case 'approved':
        case 'rejected':
        case 'all_incentives':
            exportIncentivesPDF($pdf, $data, $type, $include_headers, $include_totals);
            break;
            
        case 'salary':
            exportSalariesPDF($pdf, $data, $include_headers, $include_totals);
            break;
    }
    
    // Close and output PDF document
    renderVfslPdfFooter($pdf);
    $pdf->Output($filename . '.pdf', 'D');
    exit;
}

function exportSalaryPackagePDF($pdf, $data, $package_type, $month) {
    $pdf->SetFont('helvetica', 'B', 12);
    $pdf->Cell(0, 8, 'Salary Package Report - ' . ucfirst($package_type), 0, 1, 'L');
    $pdf->SetFont('helvetica', '', 10);
    $pdf->Ln(5);
    
    $total_employees = count($data);
    $total_base_salary = 0;
    $total_gross_salary = 0;
    $total_net_salary = 0;
    $total_bonus = 0;
    $total_allowance = 0;
    $total_commission = 0;
    $total_overtime = 0;
    $total_deduction = 0;
    $total_incentives = 0;
    $total_pending = 0;
    
    if ($package_type == 'summary') {
        // Summary table
        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->Cell(40, 7, 'Employee', 1, 0, 'C');
        $pdf->Cell(30, 7, 'Base Salary', 1, 0, 'C');
        $pdf->Cell(30, 7, 'Bonuses', 1, 0, 'C');
        $pdf->Cell(30, 7, 'Allowances', 1, 0, 'C');
        $pdf->Cell(30, 7, 'Overtime', 1, 0, 'C');
        $pdf->Cell(30, 7, 'Deductions', 1, 0, 'C');
        $pdf->Cell(30, 7, 'Net Salary', 1, 1, 'C');
        
        $pdf->SetFont('helvetica', '', 9);
        
        foreach ($data as $item) {
            $employee = $item['employee'];
            $totals = $item['totals'];
            
            $total_base_salary += $item['salary'];
            $total_gross_salary += $totals['gross_salary'];
            $total_net_salary += $totals['net_salary'];
            $total_bonus += $totals['bonus'];
            $total_allowance += $totals['allowance'];
            $total_commission += $totals['commission'];
            $total_overtime += $totals['overtime'];
            $total_deduction += $totals['deduction'];
            $total_incentives += $totals['incentives_total'];
            $total_pending += $totals['pending_total'];
            
            $pdf->Cell(40, 7, substr($employee['full_name'], 0, 20), 1, 0, 'L');
            $pdf->Cell(30, 7, formatCurrencyPDF($item['salary']), 1, 0, 'R');
            $pdf->Cell(30, 7, formatCurrencyPDF($totals['bonus']), 1, 0, 'R');
            $pdf->Cell(30, 7, formatCurrencyPDF($totals['allowance']), 1, 0, 'R');
            $pdf->Cell(30, 7, formatCurrencyPDF($totals['overtime']), 1, 0, 'R');
            $pdf->Cell(30, 7, formatCurrencyPDF($totals['deduction']), 1, 0, 'R');
            $pdf->Cell(30, 7, formatCurrencyPDF($totals['net_salary']), 1, 1, 'R');
        }
        
        // Totals row
        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->Cell(40, 7, 'TOTALS (' . $total_employees . ' employees)', 1, 0, 'L');
        $pdf->Cell(30, 7, formatCurrencyPDF($total_base_salary), 1, 0, 'R');
        $pdf->Cell(30, 7, formatCurrencyPDF($total_bonus), 1, 0, 'R');
        $pdf->Cell(30, 7, formatCurrencyPDF($total_allowance), 1, 0, 'R');
        $pdf->Cell(30, 7, formatCurrencyPDF($total_overtime), 1, 0, 'R');
        $pdf->Cell(30, 7, formatCurrencyPDF($total_deduction), 1, 0, 'R');
        $pdf->Cell(30, 7, formatCurrencyPDF($total_net_salary), 1, 1, 'R');
        
        $pdf->Ln(10);
        
        // Summary statistics
        $pdf->SetFont('helvetica', 'B', 11);
        $pdf->Cell(0, 8, 'Package Summary Statistics', 0, 1, 'L');
        $pdf->SetFont('helvetica', '', 10);
        
        $pdf->Cell(80, 6, 'Total Base Salary:', 0, 0, 'L');
        $pdf->Cell(40, 6, formatCurrencyPDF($total_base_salary), 0, 1, 'R');
        
        $pdf->Cell(80, 6, 'Total Incentives & Bonuses:', 0, 0, 'L');
        $pdf->Cell(40, 6, formatCurrencyPDF($total_incentives + $total_deduction), 0, 1, 'R');
        
        $pdf->Cell(80, 6, 'Total Gross Salary:', 0, 0, 'L');
        $pdf->Cell(40, 6, formatCurrencyPDF($total_gross_salary), 0, 1, 'R');
        
        $pdf->Cell(80, 6, 'Total Deductions:', 0, 0, 'L');
        $pdf->Cell(40, 6, formatCurrencyPDF($total_deduction), 0, 1, 'R');
        
        $pdf->Cell(80, 6, 'Total Net Salary Package:', 0, 0, 'L');
        $pdf->Cell(40, 6, formatCurrencyPDF($total_net_salary), 0, 1, 'R');
        
        $pdf->Cell(80, 6, 'Average Net Salary per Employee:', 0, 0, 'L');
        $pdf->Cell(40, 6, formatCurrencyPDF($total_employees > 0 ? $total_net_salary / $total_employees : 0), 0, 1, 'R');
        
        $pdf->Cell(80, 6, 'Pending Approvals Total:', 0, 0, 'L');
        $pdf->Cell(40, 6, formatCurrencyPDF($total_pending), 0, 1, 'R');
        
    } else {
        // Detailed package - one page per employee
        foreach ($data as $index => $item) {
            if ($index > 0) {
                $pdf->AddPage();
                renderVfslPdfHeader($pdf);
            }
            
            $employee = $item['employee'];
            $totals = $item['totals'];
            
            // Employee header
            $pdf->SetFont('helvetica', 'B', 14);
            $pdf->Cell(0, 10, $employee['full_name'], 0, 1, 'L');
            $pdf->SetFont('helvetica', '', 10);
            $pdf->Cell(0, 6, 'Job Title: ' . $employee['job_title'], 0, 1, 'L');
            $pdf->Cell(0, 6, 'Department: ' . $employee['department'], 0, 1, 'L');
            $pdf->Cell(0, 6, 'Salary Level: ' . $employee['salary_level'], 0, 1, 'L');
            $pdf->Ln(5);
            
            // Base salary
            $pdf->SetFont('helvetica', 'B', 11);
            $pdf->Cell(0, 8, 'Base Salary:', 0, 1, 'L');
            $pdf->SetFont('helvetica', '', 10);
            $pdf->Cell(100, 6, 'Monthly Basic Salary', 0, 0, 'L');
            $pdf->Cell(40, 6, formatCurrencyPDF($item['salary']), 0, 1, 'R');
            $pdf->Ln(3);
            
            // Approved Incentives
            if (!empty($item['incentives'])) {
                $pdf->SetFont('helvetica', 'B', 11);
                $pdf->Cell(0, 8, 'Approved Incentives:', 0, 1, 'L');
                $pdf->SetFont('helvetica', '', 10);
                
                $pdf->Cell(60, 6, 'Type', 1, 0, 'C');
                $pdf->Cell(80, 6, 'Description', 1, 0, 'C');
                $pdf->Cell(40, 6, 'Amount', 1, 1, 'C');
                
                foreach ($item['incentives'] as $inc) {
                    $pdf->Cell(60, 6, get_incentive_type_display($inc['incentive_type']), 1, 0, 'L');
                    $pdf->Cell(80, 6, substr($inc['description'], 0, 40), 1, 0, 'L');
                    $pdf->Cell(40, 6, formatCurrencyPDF($inc['amount']), 1, 1, 'R');
                }
                $pdf->Ln(5);
            }
            
            // Pending Incentives
            if (!empty($item['pending_incentives'])) {
                $pdf->SetFont('helvetica', 'B', 11);
                $pdf->Cell(0, 8, 'Pending Approval:', 0, 1, 'L');
                $pdf->SetFont('helvetica', '', 10);
                
                $pdf->Cell(60, 6, 'Type', 1, 0, 'C');
                $pdf->Cell(80, 6, 'Description', 1, 0, 'C');
                $pdf->Cell(40, 6, 'Amount', 1, 1, 'C');
                
                foreach ($item['pending_incentives'] as $inc) {
                    $pdf->Cell(60, 6, get_incentive_type_display($inc['incentive_type']), 1, 0, 'L');
                    $pdf->Cell(80, 6, substr($inc['description'], 0, 40), 1, 0, 'L');
                    $pdf->Cell(40, 6, formatCurrencyPDF($inc['amount']), 1, 1, 'R');
                }
                $pdf->Ln(5);
            }
            
            // Salary calculation
            $pdf->SetFont('helvetica', 'B', 12);
            $pdf->Cell(0, 10, 'Salary Calculation:', 0, 1, 'L');
            $pdf->SetFont('helvetica', '', 10);
            
            $pdf->Cell(120, 7, 'Base Salary', 0, 0, 'L');
            $pdf->Cell(40, 7, formatCurrencyPDF($item['salary']), 0, 1, 'R');
            
            if ($totals['bonus'] > 0) {
                $pdf->Cell(120, 7, '+ Bonuses', 0, 0, 'L');
                $pdf->Cell(40, 7, formatCurrencyPDF($totals['bonus']), 0, 1, 'R');
            }
            
            if ($totals['allowance'] > 0) {
                $pdf->Cell(120, 7, '+ Allowances', 0, 0, 'L');
                $pdf->Cell(40, 7, formatCurrencyPDF($totals['allowance']), 0, 1, 'R');
            }
            
            if ($totals['commission'] > 0) {
                $pdf->Cell(120, 7, '+ Commissions', 0, 0, 'L');
                $pdf->Cell(40, 7, formatCurrencyPDF($totals['commission']), 0, 1, 'R');
            }
            
            if ($totals['overtime'] > 0) {
                $pdf->Cell(120, 7, '+ Overtime', 0, 0, 'L');
                $pdf->Cell(40, 7, formatCurrencyPDF($totals['overtime']), 0, 1, 'R');
            }
            
            $pdf->Cell(120, 7, 'Gross Salary', 'T', 0, 'L');
            $pdf->Cell(40, 7, formatCurrencyPDF($totals['gross_salary']), 'T', 1, 'R');
            
            if ($totals['deduction'] > 0) {
                $pdf->Cell(120, 7, '- Deductions', 0, 0, 'L');
                $pdf->Cell(40, 7, formatCurrencyPDF($totals['deduction']), 0, 1, 'R');
            }
            
            $pdf->SetFont('helvetica', 'B', 11);
            $pdf->Cell(120, 8, 'NET SALARY PAYABLE', 0, 0, 'L');
            $pdf->Cell(40, 8, formatCurrencyPDF($totals['net_salary']), 0, 1, 'R');
            
            if ($totals['pending_total'] != 0) {
                $pdf->SetFont('helvetica', 'I', 10);
                $pdf->Cell(120, 6, 'Pending Approvals (not included)', 0, 0, 'L');
                $pdf->Cell(40, 6, formatCurrencyPDF($totals['pending_total']), 0, 1, 'R');
            }
            
            $pdf->Ln(10);
            
            // Footer note
            $pdf->SetFont('helvetica', 'I', 8);
            $pdf->Cell(0, 5, 'Generated on ' . date('Y-m-d H:i:s') . ' | Page ' . $pdf->PageNo(), 0, 1, 'C');
        }
    }
}

function exportIncentivesPDF($pdf, $data, $type, $include_headers, $include_totals) {
    $pdf->SetFont('helvetica', 'B', 10);
    
    // Table header
    $pdf->Cell(40, 7, 'Employee', 1, 0, 'C');
    $pdf->Cell(30, 7, 'Job Title', 1, 0, 'C');
    $pdf->Cell(25, 7, 'Type', 1, 0, 'C');
    $pdf->Cell(50, 7, 'Description', 1, 0, 'C');
    $pdf->Cell(25, 7, 'Amount', 1, 0, 'C');
    $pdf->Cell(30, 7, 'Status', 1, 0, 'C');
    $pdf->Cell(30, 7, 'Pay Period', 1, 1, 'C');
    
    $pdf->SetFont('helvetica', '', 9);
    
    $total_amount = 0;
    $count = 0;
    
    foreach ($data as $row) {
        $count++;
        $amount = (float)($row['amount'] ?? 0);
        $total_amount += $amount;
        
        $pdf->Cell(40, 7, substr($row['full_name'] ?? '', 0, 20), 1, 0, 'L');
        $pdf->Cell(30, 7, substr($row['job_title'] ?? '', 0, 15), 1, 0, 'L');
        $pdf->Cell(25, 7, get_incentive_type_display($row['incentive_type'] ?? ''), 1, 0, 'C');
        $pdf->Cell(50, 7, substr($row['description'] ?? '', 0, 30), 1, 0, 'L');
        $pdf->Cell(25, 7, formatCurrencyPDF($amount), 1, 0, 'R');
        $pdf->Cell(30, 7, $row['status'] ?? '', 1, 0, 'C');
        $pdf->Cell(30, 7, $row['pay_period_month'] ?? '', 1, 1, 'C');
    }
    
    if ($include_totals) {
        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->Cell(145, 7, 'TOTAL (' . $count . ' records)', 1, 0, 'R');
        $pdf->Cell(85, 7, formatCurrencyPDF($total_amount), 1, 1, 'R');
    }
}

function exportSalariesPDF($pdf, $data, $include_headers, $include_totals) {
    $pdf->SetFont('helvetica', 'B', 10);
    
    // Table header
    $pdf->Cell(50, 7, 'Employee', 1, 0, 'C');
    $pdf->Cell(30, 7, 'Job Title', 1, 0, 'C');
    $pdf->Cell(30, 7, 'Department', 1, 0, 'C');
    $pdf->Cell(30, 7, 'Salary Level', 1, 0, 'C');
    $pdf->Cell(40, 7, 'Salary', 1, 0, 'C');
    $pdf->Cell(30, 7, 'Last Review', 1, 0, 'C');
    $pdf->Cell(30, 7, 'Hire Date', 1, 1, 'C');
    
    $pdf->SetFont('helvetica', '', 9);
    
    $total_salary = 0;
    $count = 0;
    
    foreach ($data as $row) {
        $count++;
        $salary = (float)($row['salary'] ?? 0);
        $total_salary += $salary;
        
        $pdf->Cell(50, 7, substr($row['full_name'] ?? '', 0, 25), 1, 0, 'L');
        $pdf->Cell(30, 7, substr($row['job_title'] ?? '', 0, 15), 1, 0, 'L');
        $pdf->Cell(30, 7, substr($row['department'] ?? '', 0, 15), 1, 0, 'L');
        $pdf->Cell(30, 7, substr($row['salary_level'] ?? '', 0, 10), 1, 0, 'C');
        $pdf->Cell(40, 7, formatCurrencyPDF($salary), 1, 0, 'R');
        $pdf->Cell(30, 7, $row['last_salary_review'] ?? '', 1, 0, 'C');
        $pdf->Cell(30, 7, $row['hire_date'] ?? '', 1, 1, 'C');
    }
    
    if ($include_totals) {
        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->Cell(140, 7, 'TOTAL (' . $count . ' employees)', 1, 0, 'R');
        $pdf->Cell(100, 7, formatCurrencyPDF($total_salary), 1, 1, 'R');
        
        // Add average
        $pdf->Cell(140, 7, 'AVERAGE SALARY', 1, 0, 'R');
        $pdf->Cell(100, 7, formatCurrencyPDF($count > 0 ? $total_salary / $count : 0), 1, 1, 'R');
    }
}

function formatCurrencyPDF($amount) {
    return 'TZS ' . number_format($amount, 2);
}
?>