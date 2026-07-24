<?php
// Include necessary files for database connection, authentication, and helper functions
require_once '../config/config.php';
require_once '../auth/auth_middleware.php';

// Ensure the user is logged in and is CEO
require_login();
require_ceo(); // Add this function to auth_middleware.php if not exists

// Establish database connection
$db = getDBConnection();

// --- Fetch Dashboard Data ---

// 1-3. Trade overview stats (single query replaces 3 separate queries)
$trades_stats = $db->query("
    SELECT 
        COALESCE(SUM(consideration), 0) AS total_value,
        COUNT(*) AS total_trades,
        COUNT(DISTINCT client_cds_account) AS unique_clients
    FROM trades
")->fetch();
$total_trade_value = $trades_stats['total_value'];
$total_trades = $trades_stats['total_trades'];
$unique_clients = $trades_stats['unique_clients'];

// 4-5. Pending payment requests (count derived from rows, reduces 2 queries to 1)
$pending_requests_query = "
    SELECT pp.*, 
           u.full_name as requested_by_name,
           lt.description as pay_to_desc
    FROM pending_pay pp
    LEFT JOIN users u ON pp.requested_by = u.id
    LEFT JOIN ledger_types lt ON pp.pay_to_type = lt.code
    WHERE pp.status = 'pending' 
    AND pp.ceo_approved_at IS NULL
    ORDER BY pp.requested_at ASC
    LIMIT 5
";
$pending_requests = $db->query($pending_requests_query)->fetchAll();
$pending_payments = count($pending_requests);

// 6. Total Payment Requests (for CEO)
$total_payment_requests = $db->query("
    SELECT COUNT(*) 
    FROM pending_pay 
    WHERE ceo_approved_at IS NOT NULL OR status = 'pending'
")->fetchColumn();

// 7. Recent Trades (last 10)
$recent_trades_query = "
    SELECT *
    FROM trades
    ORDER BY trade_date DESC, id DESC
    LIMIT 10
";
$stmt = $db->query($recent_trades_query);
$recent_trades = $stmt->fetchAll();

// 8. Monthly Trade Value Data for Chart
$monthly_value_query = "
    SELECT
        DATE_FORMAT(trade_date, '%Y-%m') AS month,
        SUM(consideration) AS monthly_total
    FROM trades
    GROUP BY month
    ORDER BY month ASC
    LIMIT 12
";
$stmt = $db->query($monthly_value_query);
$monthly_data = $stmt->fetchAll();

$months = array_column($monthly_data, 'month');
$monthly_totals = array_column($monthly_data, 'monthly_total');

// 9. Top Investors by Transaction Volume
$top_investors_query = "
    SELECT 
        t.client_cds_account,
        t.client_name,
        COUNT(*) as total_transactions,
        SUM(t.consideration) as total_investment,
        SUM(CASE WHEN t.trade_side = 'BUY' THEN t.consideration ELSE 0 END) as total_buys,
        SUM(CASE WHEN t.trade_side = 'SELL' THEN t.consideration ELSE 0 END) as total_sells,
        MAX(t.trade_date) as last_trade_date,
        MIN(t.trade_date) as first_trade_date
    FROM trades t
    WHERE t.client_cds_account IS NOT NULL AND t.client_cds_account != ''
    GROUP BY t.client_cds_account, t.client_name
    ORDER BY total_transactions DESC, total_investment DESC
    LIMIT 10
";
$stmt = $db->query($top_investors_query);
$top_investors = $stmt->fetchAll();

// 10. Current Month Payroll Data from pending_pay table
$current_month = date('Y-m');
$current_payroll = null;
$payroll_employees = [];
$payroll_summary = [
    'total_employees' => 0,
    'total_basic_salary' => 0,
    'total_gross_salary' => 0,
    'total_deductions' => 0,
    'total_net_salary' => 0,
    'total_employer_cost' => 0
];

try {
    $payroll_query = "
        SELECT 
            pp.*,
            u.full_name as hr_manager_name
        FROM pending_pay pp
        LEFT JOIN users u ON pp.requested_by = u.id
        WHERE pp.subject LIKE 'Salary Payment%'
        AND pp.payee_id = 'SALARY'
        AND DATE_FORMAT(pp.requested_at, '%Y-%m') = :current_month
        AND pp.ceo_approved_at IS NOT NULL
        ORDER BY pp.requested_at DESC
        LIMIT 1
    ";
    $stmt = $db->prepare($payroll_query);
    $stmt->execute([':current_month' => $current_month]);
    $current_payroll = $stmt->fetch();
    
    if ($current_payroll) {
        // Get employee details from users table
        $employees_query = "
            SELECT 
                id as user_id,
                full_name as employee_name,
                job_title,
                salary as basic_salary
            FROM users 
            WHERE status = 'active' 
            AND role NOT IN ('system_admin', 'ceo')
            ORDER BY full_name
        ";
        $stmt = $db->query($employees_query);
        $all_employees = $stmt->fetchAll();
        
        $payroll_summary['total_employees'] = count($all_employees);
        
        // Calculate payroll for each employee
        foreach ($all_employees as $emp) {
            $basic_salary = floatval($emp['basic_salary']);
            $payroll_summary['total_basic_salary'] += $basic_salary;
            
            // Calculate values based on standard rates
            $allowances = $basic_salary * 0.10; // 10% of basic as allowance
            $overtime = $basic_salary * 0.05; // 5% as overtime
            $bonuses = $basic_salary * 0.03; // 3% as bonus
            
            $gross_salary = $basic_salary + $allowances + $overtime + $bonuses;
            $payroll_summary['total_gross_salary'] += $gross_salary;
            
            // Calculate deductions (using standard rates)
            $nssf_employee = $basic_salary * 0.10; // 10%
            $paye_tax = calculate_estimated_paye($basic_salary);
            $nhif = $basic_salary * 0.03; // 3%
            $other_deductions = $basic_salary * 0.02; // 2%
            
            $total_deductions = $nssf_employee + $paye_tax + $nhif + $other_deductions;
            $payroll_summary['total_deductions'] += $total_deductions;
            
            $net_salary = $gross_salary - $total_deductions;
            $payroll_summary['total_net_salary'] += $net_salary;
            
            // Employer contributions
            $nssf_employer = $basic_salary * 0.10; // 10%
            $sdl = $gross_salary * 0.035; // 3.5%
            $wcf = $gross_salary * 0.005; // 0.5%
            $osha = $gross_salary * 0.005; // 0.5%
            
            $total_employer_contributions = $nssf_employer + $sdl + $wcf + $osha;
            $total_employer_cost = $gross_salary + $total_employer_contributions;
            $payroll_summary['total_employer_cost'] += $total_employer_cost;
            
            $payroll_employees[] = [
                'employee_name' => $emp['employee_name'],
                'job_title' => $emp['job_title'],
                'basic_salary' => $basic_salary,
                'allowances' => $allowances,
                'overtime' => $overtime,
                'bonuses' => $bonuses,
                'gross_salary' => $gross_salary,
                'nssf_employee' => $nssf_employee,
                'paye_tax' => $paye_tax,
                'nhif' => $nhif,
                'other_deductions' => $other_deductions,
                'total_deductions' => $total_deductions,
                'net_salary' => $net_salary,
                'nssf_employer' => $nssf_employer,
                'sdl' => $sdl,
                'wcf' => $wcf,
                'osha' => $osha,
                'total_employer_contributions' => $total_employer_contributions,
                'total_employer_cost' => $total_employer_cost
            ];
        }
        
        // If we have an approved payroll, update with actual amount
        if ($current_payroll && $current_payroll['amount_paid'] > 0) {
            $payroll_summary['total_employer_cost'] = floatval($current_payroll['amount_paid']);
        }
    }
} catch (Exception $e) {
    error_log("Error fetching payroll data: " . $e->getMessage());
    $current_payroll = null;
}

// Helper function to estimate PAYE tax
function calculate_estimated_paye($basic_salary) {
    $tax = 0;
    
    if ($basic_salary <= 270000) {
        $tax = 0;
    } elseif ($basic_salary <= 520000) {
        $tax = ($basic_salary - 270000) * 0.08;
    } elseif ($basic_salary <= 760000) {
        $tax = (520000 - 270000) * 0.08 + ($basic_salary - 520000) * 0.20;
    } elseif ($basic_salary <= 1000000) {
        $tax = (520000 - 270000) * 0.08 + (760000 - 520000) * 0.20 + ($basic_salary - 760000) * 0.25;
    } else {
        $tax = (520000 - 270000) * 0.08 + (760000 - 520000) * 0.20 + (1000000 - 760000) * 0.25 + ($basic_salary - 1000000) * 0.30;
    }
    
    return $tax;
}

// Handle Export to Excel
if (isset($_GET['export']) && $_GET['export'] === 'excel' && $current_payroll) {
    // Set headers for Excel file download
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="payroll_' . date('F_Y') . '.xls"');
    
    // Create Excel content
    echo "<html>";
    echo "<head>";
    echo "<meta charset=\"UTF-8\">";
    echo "<style>";
    echo "td { border: 1px solid #000; padding: 5px; }";
    echo "th { border: 1px solid #000; padding: 5px; background-color: #f2f2f2; }";
    echo ".total { font-weight: bold; background-color: #e6f3ff; }";
    echo ".subtotal { font-weight: bold; background-color: #f0f0f0; }";
    echo "</style>";
    echo "</head>";
    echo "<body>";
    
    echo "<h2>Payroll Report - " . date('F Y') . "</h2>";
    echo "<p>Generated on: " . date('Y-m-d H:i:s') . "</p>";
    echo "<p>Request No: " . htmlspecialchars($current_payroll['request_no'] ?? 'N/A') . "</p>";
    echo "<p>Status: " . htmlspecialchars($current_payroll['status'] ?? 'N/A') . "</p>";
    
    // Summary Section
    echo "<h3>Payroll Summary</h3>";
    echo "<table border='1'>";
    echo "<tr>";
    echo "<th>Total Employees</th>";
    echo "<th>Total Basic Salary</th>";
    echo "<th>Total Gross Salary</th>";
    echo "<th>Total Deductions</th>";
    echo "<th>Total Net Salary</th>";
    echo "<th>Total Employer Cost</th>";
    echo "</tr>";
    echo "<tr class='total'>";
    echo "<td>" . ($payroll_summary['total_employees'] ?? 0) . "</td>";
    echo "<td>TZS " . number_format($payroll_summary['total_basic_salary'] ?? 0, 2) . "</td>";
    echo "<td>TZS " . number_format($payroll_summary['total_gross_salary'] ?? 0, 2) . "</td>";
    echo "<td>TZS " . number_format($payroll_summary['total_deductions'] ?? 0, 2) . "</td>";
    echo "<td>TZS " . number_format($payroll_summary['total_net_salary'] ?? 0, 2) . "</td>";
    echo "<td>TZS " . number_format($payroll_summary['total_employer_cost'] ?? 0, 2) . "</td>";
    echo "</tr>";
    echo "</table>";
    
    echo "<br>";
    
    // Employee Details
    echo "<h3>Employee Details</h3>";
    echo "<table border='1'>";
    echo "<tr>";
    echo "<th>No</th>";
    echo "<th>Employee Name</th>";
    echo "<th>Job Title</th>";
    echo "<th>Basic Salary</th>";
    echo "<th>Allowances</th>";
    echo "<th>Overtime</th>";
    echo "<th>Bonuses</th>";
    echo "<th>Gross Salary</th>";
    echo "<th>NSSF Employee</th>";
    echo "<th>PAYE Tax</th>";
    echo "<th>NHIF</th>";
    echo "<th>Other Deductions</th>";
    echo "<th>Total Deductions</th>";
    echo "<th>Net Salary</th>";
    echo "<th>NSSF Employer</th>";
    echo "<th>SDL</th>";
    echo "<th>WCF</th>";
    echo "<th>OSHA</th>";
    echo "<th>Total Employer Cost</th>";
    echo "</tr>";
    
    if (!empty($payroll_employees)) {
        $counter = 1;
        foreach ($payroll_employees as $emp) {
            echo "<tr>";
            echo "<td>" . $counter++ . "</td>";
            echo "<td>" . htmlspecialchars($emp['employee_name'] ?? 'Unknown') . "</td>";
            echo "<td>" . htmlspecialchars($emp['job_title'] ?? '') . "</td>";
            echo "<td>TZS " . number_format($emp['basic_salary'] ?? 0, 2) . "</td>";
            echo "<td>TZS " . number_format($emp['allowances'] ?? 0, 2) . "</td>";
            echo "<td>TZS " . number_format($emp['overtime'] ?? 0, 2) . "</td>";
            echo "<td>TZS " . number_format($emp['bonuses'] ?? 0, 2) . "</td>";
            echo "<td>TZS " . number_format($emp['gross_salary'] ?? 0, 2) . "</td>";
            echo "<td>TZS " . number_format($emp['nssf_employee'] ?? 0, 2) . "</td>";
            echo "<td>TZS " . number_format($emp['paye_tax'] ?? 0, 2) . "</td>";
            echo "<td>TZS " . number_format($emp['nhif'] ?? 0, 2) . "</td>";
            echo "<td>TZS " . number_format($emp['other_deductions'] ?? 0, 2) . "</td>";
            echo "<td>TZS " . number_format($emp['total_deductions'] ?? 0, 2) . "</td>";
            echo "<td>TZS " . number_format($emp['net_salary'] ?? 0, 2) . "</td>";
            echo "<td>TZS " . number_format($emp['nssf_employer'] ?? 0, 2) . "</td>";
            echo "<td>TZS " . number_format($emp['sdl'] ?? 0, 2) . "</td>";
            echo "<td>TZS " . number_format($emp['wcf'] ?? 0, 2) . "</td>";
            echo "<td>TZS " . number_format($emp['osha'] ?? 0, 2) . "</td>";
            echo "<td>TZS " . number_format($emp['total_employer_cost'] ?? 0, 2) . "</td>";
            echo "</tr>";
        }
        
        // Totals row
        echo "<tr class='total'>";
        echo "<td colspan='3'><strong>TOTALS</strong></td>";
        echo "<td>TZS " . number_format($payroll_summary['total_basic_salary'] ?? 0, 2) . "</td>";
        echo "<td>TZS " . number_format(array_sum(array_column($payroll_employees, 'allowances')) ?? 0, 2) . "</td>";
        echo "<td>TZS " . number_format(array_sum(array_column($payroll_employees, 'overtime')) ?? 0, 2) . "</td>";
        echo "<td>TZS " . number_format(array_sum(array_column($payroll_employees, 'bonuses')) ?? 0, 2) . "</td>";
        echo "<td>TZS " . number_format($payroll_summary['total_gross_salary'] ?? 0, 2) . "</td>";
        echo "<td>TZS " . number_format(array_sum(array_column($payroll_employees, 'nssf_employee')) ?? 0, 2) . "</td>";
        echo "<td>TZS " . number_format(array_sum(array_column($payroll_employees, 'paye_tax')) ?? 0, 2) . "</td>";
        echo "<td>TZS " . number_format(array_sum(array_column($payroll_employees, 'nhif')) ?? 0, 2) . "</td>";
        echo "<td>TZS " . number_format(array_sum(array_column($payroll_employees, 'other_deductions')) ?? 0, 2) . "</td>";
        echo "<td>TZS " . number_format($payroll_summary['total_deductions'] ?? 0, 2) . "</td>";
        echo "<td>TZS " . number_format($payroll_summary['total_net_salary'] ?? 0, 2) . "</td>";
        echo "<td>TZS " . number_format(array_sum(array_column($payroll_employees, 'nssf_employer')) ?? 0, 2) . "</td>";
        echo "<td>TZS " . number_format(array_sum(array_column($payroll_employees, 'sdl')) ?? 0, 2) . "</td>";
        echo "<td>TZS " . number_format(array_sum(array_column($payroll_employees, 'wcf')) ?? 0, 2) . "</td>";
        echo "<td>TZS " . number_format(array_sum(array_column($payroll_employees, 'osha')) ?? 0, 2) . "</td>";
        echo "<td>TZS " . number_format($payroll_summary['total_employer_cost'] ?? 0, 2) . "</td>";
        echo "</tr>";
    } else {
        echo "<tr><td colspan='19'>No employee data available</td></tr>";
    }
    
    echo "</table>";
    
    echo "</body>";
    echo "</html>";
    
    exit();
}

// Handle AJAX requests for payment approval
$is_ajax_post = $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']);
$is_get_download = $_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'download_attachment';
if ($is_ajax_post || $is_get_download) {
    header('Content-Type: application/json');
    
    $action = $_POST['action'] ?? $_GET['action'] ?? '';
    $request_id = (int)($_POST['request_id'] ?? $_GET['id'] ?? 0);

    if ($is_ajax_post) {
        // Verify CSRF token for POST actions
        if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
            echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
            exit;
        }
    }
    
    if (!$request_id) {
        echo json_encode(['success' => false, 'message' => 'Invalid request ID']);
        exit;
    }
    
    try {
        $db->beginTransaction();
        
        // Get user ID (CEO)
        $user_id = $_SESSION['user_id'] ?? 1;
        
        if ($action === 'approve') {
            $notes = $_POST['notes'] ?? '';
            
            $stmt = $db->prepare("
                UPDATE pending_pay 
                SET ceo_approved_at = NOW(), 
                    ceo_approved_by = ?,
                    ceo_approval_notes = ?,
                    status = 'approved_ceo'
                WHERE id = ? AND status = 'pending'
            ");
            $stmt->execute([$user_id, $notes, $request_id]);
            
            // Log approval in audit trail
            $audit_stmt = $db->prepare("
                INSERT INTO audit_trail (user_id, action, description, ip_address, user_agent)
                VALUES (?, 'ceo_approval', ?, ?, ?)
            ");
            $audit_stmt->execute([
                $user_id,
                "CEO approved payment request #$request_id",
                $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
                $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'
            ]);
            
            $db->commit();
            echo json_encode(['success' => true, 'message' => 'Payment request approved successfully']);
            
        } elseif ($action === 'reject') {
            $rejection_reason = $_POST['rejection_reason'] ?? '';
            
            if (empty($rejection_reason)) {
                echo json_encode(['success' => false, 'message' => 'Rejection reason is required']);
                exit;
            }
            
            $stmt = $db->prepare("
                UPDATE pending_pay 
                SET ceo_approved_at = NOW(), 
                    ceo_approved_by = ?,
                    ceo_approval_notes = ?,
                    rejection_reason = ?,
                    status = 'rejected'
                WHERE id = ? AND status = 'pending'
            ");
            $stmt->execute([$user_id, 'Rejected by CEO', $rejection_reason, $request_id]);
            
            // Log rejection in audit trail
            $audit_stmt = $db->prepare("
                INSERT INTO audit_trail (user_id, action, description, ip_address, user_agent)
                VALUES (?, 'ceo_rejection', ?, ?, ?)
            ");
            $audit_stmt->execute([
                $user_id,
                "CEO rejected payment request #$request_id",
                $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
                $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'
            ]);
            
            $db->commit();
            echo json_encode(['success' => true, 'message' => 'Payment request rejected successfully']);
            
        } elseif ($action === 'get_request_details') {
            // Get detailed request information
            $stmt = $db->prepare("
                SELECT pp.*, 
                       u.username as requested_by_username,
                       u.full_name as requested_by_fullname,
                       lt.description as pay_to_desc
                FROM pending_pay pp
                LEFT JOIN users u ON pp.requested_by = u.id
                LEFT JOIN ledger_types lt ON pp.pay_to_type = lt.code
                WHERE pp.id = ?
            ");
            $stmt->execute([$request_id]);
            $request_details = $stmt->fetch();
            
            if ($request_details) {
                unset($request_details['attachment_data']);
                echo json_encode(['success' => true, 'data' => $request_details]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Request not found']);
            }
        } elseif ($action === 'download_attachment') {
            $stmt = $db->prepare("SELECT attachment_data, attachment_name, attachment_mime FROM pending_pay WHERE id = ?");
            $stmt->execute([$request_id]);
            $att = $stmt->fetch();
            if ($att && !empty($att['attachment_data'])) {
                header_remove('Content-Type');
                header('Content-Type: ' . ($att['attachment_mime'] ?: 'application/octet-stream'));
                header('Content-Disposition: inline; filename="' . ($att['attachment_name'] ?: 'attachment') . '"');
                header('Content-Length: ' . strlen($att['attachment_data']));
                echo $att['attachment_data'];
            } else {
                http_response_code(404);
                echo 'Attachment not found';
            }
        } else {
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
        }
        
    } catch (Exception $e) {
        $db->rollBack();
        error_log("Payment approval error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
    exit;
}

// Generate CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$page_title = 'CEO Dashboard';
include '../includes/header.php';
?>

<!-- Include Chart.js library -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<div class="container-fluid py-5">
    <div class="row">
        <div class="col-12">
            <h1 class="mb-4">
                <i class="bi bi-speedometer2"></i> CEO Dashboard
            </h1>
        </div>
    </div>
    
    <!-- --- KPI Cards --- -->
    <div class="row g-4 mb-5">
        <div class="col-lg-3 col-md-6">
            <div class="card bg-primary text-white h-100 shadow-sm rounded-4">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h5 class="card-title fw-bold">Total Trade Value</h5>
                        <i class="bi bi-cash-stack fs-1 opacity-50"></i>
                    </div>
                    <h2 class="card-text">Tzs<?php echo format_currency($total_trade_value ?? 0, 2); ?></h2>
                </div>
            </div>
        </div>
        <div class="col-lg-3 col-md-6">
            <div class="card bg-info text-white h-100 shadow-sm rounded-4">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h5 class="card-title fw-bold">Total Trades</h5>
                        <i class="bi bi-clipboard-data-fill fs-1 opacity-50"></i>
                    </div>
                    <h2 class="card-text"><?php echo number_format($total_trades ?? 0); ?></h2>
                </div>
            </div>
        </div>
        <div class="col-lg-3 col-md-6">
            <div class="card bg-success text-white h-100 shadow-sm rounded-4">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h5 class="card-title fw-bold">Unique Clients</h5>
                        <i class="bi bi-people-fill fs-1 opacity-50"></i>
                    </div>
                    <h2 class="card-text"><?php echo number_format($unique_clients ?? 0); ?></h2>
                </div>
            </div>
        </div>
        <div class="col-lg-3 col-md-6">
            <div class="card bg-warning text-dark h-100 shadow-sm rounded-4">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h5 class="card-title fw-bold">Pending Approvals</h5>
                        <i class="bi bi-hourglass-split fs-1 opacity-50"></i>
                    </div>
                    <h2 class="card-text"><?php echo number_format($pending_payments ?? 0); ?></h2>
                    <small class="opacity-75">Payment requests awaiting your approval</small>
                </div>
            </div>
        </div>
    </div>
    
    <!-- --- Main Content (Chart & Pending Requests) --- -->
    <div class="row g-4">
        <div class="col-lg-7">
            <div class="card shadow-sm rounded-4 h-100">
                <div class="card-header bg-white border-0">
                    <h5 class="card-title mb-0">Monthly Trade Value Trend</h5>
                </div>
                <div class="card-body">
                    <canvas id="monthlyTradeChart"></canvas>
                </div>
            </div>
        </div>
        <div class="col-lg-5">
            <div class="card shadow-sm rounded-4 h-100">
                <div class="card-header bg-white border-0 d-flex justify-content-between align-items-center">
                    <h5 class="card-title mb-0">Pending Payment Requests</h5>
                    <?php if ($pending_payments > 5): ?>
                        <button type="button" class="btn btn-sm btn-outline-primary" 
                                data-bs-toggle="modal" data-bs-target="#allRequestsModal">
                            View All (<?php echo $pending_payments; ?>)
                        </button>
                    <?php endif; ?>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead>
                                <tr>
                                    <th>Request #</th>
                                    <th>Subject</th>
                                    <th>Amount</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($pending_requests)): ?>
                                    <tr>
                                        <td colspan="4" class="text-center text-muted py-4">
                                            <i class="bi bi-check-circle display-6 text-success"></i>
                                            <p class="mt-2">No pending payment requests</p>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($pending_requests as $request): ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo htmlspecialchars($request['request_no']); ?></strong>
                                                <br>
                                                <small class="text-muted">
                                                    <?php echo date('M d', strtotime($request['requested_at'])); ?>
                                                </small>
                                            </td>
                                            <td>
                                                <div class="text-truncate" style="max-width: 150px;">
                                                    <?php echo htmlspecialchars($request['subject']); ?>
                                                </div>
                                                <small class="text-muted">
                                                    <?php echo htmlspecialchars($request['pay_to_desc'] ?? 'N/A'); ?>
                                                </small>
                                            </td>
                                            <td>
                                                <strong class="text-success">
                                                    <?php echo number_format($request['amount_paid'], 2); ?>
                                                    <?php echo htmlspecialchars($request['currency']); ?>
                                                </strong>
                                                <br>
                                                <small class="text-muted">
                                                    by <?php echo htmlspecialchars($request['requested_by_name'] ?? 'Unknown'); ?>
                                                </small>
                                            </td>
                                            <td>
                                                <div class="btn-group btn-group-sm" role="group">
                                                    <button type="button" class="btn btn-outline-info view-request" 
                                                            data-request-id="<?php echo (int)$request['id']; ?>">
                                                        <i class="bi bi-eye"></i>
                                                    </button>
                                                    <button type="button" class="btn btn-outline-success approve-request" 
                                                            data-request-id="<?php echo (int)$request['id']; ?>">
                                                        <i class="bi bi-check-lg"></i>
                                                    </button>
                                                    <button type="button" class="btn btn-outline-danger reject-request" 
                                                            data-request-id="<?php echo (int)$request['id']; ?>">
                                                        <i class="bi bi-x-lg"></i>
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- --- Top Investors Table --- -->
    <div class="row mt-4">
        <div class="col-12">
            <div class="card shadow-sm rounded-4">
                <div class="card-header bg-primary text-white">
                    <h5 class="card-title mb-0">
                        <i class="bi bi-trophy me-2"></i>Top Investors by Transaction Volume
                    </h5>
                    <small class="opacity-75">Clients with highest number of transactions</small>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Rank</th>
                                    <th>Client Name</th>
                                    <th>CDS Account</th>
                                    <th>Total Transactions</th>
                                    <th>Total Investment</th>
                                    <th>Total Buys</th>
                                    <th>Total Sells</th>
                                    <th>First Trade</th>
                                    <th>Last Trade</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($top_investors)): ?>
                                    <tr>
                                        <td colspan="9" class="text-center text-muted py-4">
                                            <i class="bi bi-people display-6 text-muted"></i>
                                            <p class="mt-2">No investor data available</p>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php 
                                    $rank = 1;
                                    foreach ($top_investors as $investor): 
                                        $total_transactions = $investor['total_transactions'] ?? 0;
                                        $total_investment = $investor['total_investment'] ?? 0;
                                        $total_buys = $investor['total_buys'] ?? 0;
                                        $total_sells = $investor['total_sells'] ?? 0;
                                    ?>
                                        <tr>
                                            <td>
                                                <div class="d-flex align-items-center">
                                                    <span class="badge <?php 
                                                        echo $rank <= 3 ? 'bg-warning text-dark' : 'bg-secondary'; 
                                                    ?> me-2" style="width: 30px;">
                                                        <?php echo $rank; ?>
                                                    </span>
                                                    <?php if ($rank <= 3): ?>
                                                        <i class="bi bi-trophy-fill text-warning"></i>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                            <td>
                                                <strong><?php echo htmlspecialchars($investor['client_name'] ?? 'Unknown'); ?></strong>
                                            </td>
                                            <td>
                                                <code class="text-primary"><?php echo htmlspecialchars($investor['client_cds_account']); ?></code>
                                            </td>
                                            <td class="text-center">
                                                <span class="badge bg-info rounded-pill px-3 py-2">
                                                    <?php echo number_format($total_transactions); ?>
                                                </span>
                                            </td>
                                            <td class="text-success fw-bold">
                                                Tzs<?php echo format_currency($total_investment); ?></td>
                                            <td class="text-success">
                                                Tzs<?php echo format_currency($total_buys); ?></td>
                                            <td class="text-danger">
                                                Tzs<?php echo format_currency($total_sells); ?></td>
                                            <td>
                                                <small class="text-muted">
                                                    <?php echo !empty($investor['first_trade_date']) ? 
                                                        date('M d, Y', strtotime($investor['first_trade_date'])) : 
                                                        'N/A'; ?>
                                                </small>
                                            </td>
                                            <td>
                                                <small class="text-muted">
                                                    <?php echo !empty($investor['last_trade_date']) ? 
                                                        date('M d, Y', strtotime($investor['last_trade_date'])) : 
                                                        'N/A'; ?>
                                                </small>
                                            </td>
                                        </tr>
                                        <?php $rank++; ?>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- --- Current Month Payroll Table (Only if approved by CEO) --- -->
    <div class="row mt-4">
        <div class="col-12">
            <div class="card shadow-sm rounded-4">
                <div class="card-header <?php echo $current_payroll ? 'bg-success text-white' : 'bg-secondary text-white'; ?>">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h5 class="card-title mb-0">
                                <i class="bi bi-currency-exchange me-2"></i>
                                <?php echo date('F Y'); ?> Payroll Summary
                                <?php if ($current_payroll): ?>
                                    <span class="badge bg-light text-dark ms-2">
                                        Request: <?php echo htmlspecialchars($current_payroll['request_no'] ?? 'N/A'); ?>
                                    </span>
                                    <span class="badge bg-info ms-2">
                                        Status: <?php echo htmlspecialchars($current_payroll['status'] ?? 'Pending'); ?>
                                    </span>
                                <?php endif; ?>
                            </h5>
                            <small class="opacity-75">
                                <?php echo $current_payroll ? 
                                    'Generated by ' . htmlspecialchars($current_payroll['hr_manager_name']) . ' on ' . 
                                    date('M d, Y', strtotime($current_payroll['requested_at'])) . 
                                    ' | Approved: ' . date('M d, Y', strtotime($current_payroll['ceo_approved_at'])) : 
                                    'No payroll approved for ' . date('F Y'); ?>
                            </small>
                        </div>
                        <?php if ($current_payroll && !empty($payroll_employees)): ?>
                            <a href="?export=excel" class="btn btn-sm btn-light">
                                <i class="bi bi-file-earmark-excel me-1"></i>Export to Excel
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="card-body p-0">
                    <?php if (!$current_payroll): ?>
                        <div class="text-center text-muted py-5">
                            <i class="bi bi-calculator display-6 text-muted"></i>
                            <p class="mt-2">No payroll data available for <?php echo date('F Y'); ?></p>
                            <p class="text-sm">HR Manager needs to generate payroll and CEO needs to approve it</p>
                        </div>
                    <?php else: ?>
                        <!-- Payroll Summary -->
                        <div class="p-3 bg-light border-bottom">
                            <div class="row">
                                <div class="col-md-3">
                                    <div class="d-flex align-items-center">
                                        <div class="me-3">
                                            <i class="bi bi-people-fill fs-2 text-primary"></i>
                                        </div>
                                        <div>
                                            <div class="text-muted">Total Employees</div>
                                            <div class="fs-4 fw-bold"><?php echo number_format($payroll_summary['total_employees'] ?? 0); ?></div>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="d-flex align-items-center">
                                        <div class="me-3">
                                            <i class="bi bi-cash-stack fs-2 text-success"></i>
                                        </div>
                                        <div>
                                            <div class="text-muted">Gross Salary</div>
                                            <div class="fs-4 fw-bold">Tzs<?php echo format_currency($payroll_summary['total_gross_salary'] ?? 0); ?></div>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="d-flex align-items-center">
                                        <div class="me-3">
                                            <i class="bi bi-cash fs-2 text-danger"></i>
                                        </div>
                                        <div>
                                            <div class="text-muted">Total Deductions</div>
                                            <div class="fs-4 fw-bold">Tzs<?php echo format_currency($payroll_summary['total_deductions'] ?? 0); ?></div>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="d-flex align-items-center">
                                        <div class="me-3">
                                            <i class="bi bi-wallet2 fs-2 text-warning"></i>
                                        </div>
                                        <div>
                                            <div class="text-muted">Net Salary</div>
                                            <div class="fs-4 fw-bold text-success">Tzs<?php echo format_currency($payroll_summary['total_net_salary'] ?? 0); ?></div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row mt-3">
                                <div class="col-md-4">
                                    <div class="alert alert-info mb-0 p-2">
                                        <h6 class="mb-1">Employer Contributions</h6>
                                        <?php 
                                        $total_nssf_employer = array_sum(array_column($payroll_employees, 'nssf_employer'));
                                        $total_sdl = array_sum(array_column($payroll_employees, 'sdl'));
                                        $total_wcf = array_sum(array_column($payroll_employees, 'wcf'));
                                        $total_osha = array_sum(array_column($payroll_employees, 'osha'));
                                        ?>
                                        <small class="d-block">NSSF Employer: Tzs<?php echo format_currency($total_nssf_employer); ?></small>
                                        <small class="d-block">SDL: Tzs<?php echo format_currency($total_sdl); ?></small>
                                        <small class="d-block">WCF: Tzs<?php echo format_currency($total_wcf); ?></small>
                                        <small class="d-block">OSHA: Tzs<?php echo format_currency($total_osha); ?></small>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="alert alert-warning mb-0 p-2">
                                        <h6 class="mb-1">Employee Deductions</h6>
                                        <?php 
                                        $total_nssf_employee = array_sum(array_column($payroll_employees, 'nssf_employee'));
                                        $total_paye_tax = array_sum(array_column($payroll_employees, 'paye_tax'));
                                        $total_nhif = array_sum(array_column($payroll_employees, 'nhif'));
                                        ?>
                                        <small class="d-block">NSSF Employee: Tzs<?php echo format_currency($total_nssf_employee); ?></small>
                                        <small class="d-block">PAYE Tax: Tzs<?php echo format_currency($total_paye_tax); ?></small>
                                        <small class="d-block">NHIF: Tzs<?php echo format_currency($total_nhif); ?></small>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="alert alert-danger mb-0 p-2">
                                        <h6 class="mb-1">Payment Required</h6>
                                        <h4 class="fw-bold mb-0">Tzs<?php echo format_currency($payroll_summary['total_employer_cost']); ?></h4>
                                        <small class="text-muted">Total Employer Cost</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Payroll Details Table -->
                        <div class="table-responsive">
                            <table class="table table-sm table-hover mb-0">
                                <thead class="table-success">
                                    <tr>
                                        <th>Employee</th>
                                        <th>Job Title</th>
                                        <th>Basic Salary</th>
                                        <th>Allowances</th>
                                        <th>Overtime</th>
                                        <th>Gross Salary</th>
                                        <th>Deductions</th>
                                        <th>Net Salary</th>
                                        <th>Employer Cost</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    $total_basic = 0;
                                    $total_allowances = 0;
                                    $total_overtime = 0;
                                    $total_gross = 0;
                                    $total_deductions = 0;
                                    $total_net = 0;
                                    $total_employer_cost = 0;
                                    ?>
                                    
                                    <?php foreach ($payroll_employees as $employee): 
                                        $total_basic += $employee['basic_salary'];
                                        $total_allowances += $employee['allowances'];
                                        $total_overtime += $employee['overtime'];
                                        $total_gross += $employee['gross_salary'];
                                        $total_deductions += $employee['total_deductions'];
                                        $total_net += $employee['net_salary'];
                                        $total_employer_cost += $employee['total_employer_cost'];
                                    ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo htmlspecialchars($employee['employee_name'] ?? 'Unknown'); ?></strong>
                                            </td>
                                            <td>
                                                <small class="text-muted"><?php echo htmlspecialchars($employee['job_title'] ?? ''); ?></small>
                                            </td>
                                            <td class="text-end">Tzs<?php echo format_currency($employee['basic_salary'] ?? 0); ?></td>
                                            <td class="text-end">Tzs<?php echo format_currency($employee['allowances'] ?? 0); ?></td>
                                            <td class="text-end">Tzs<?php echo format_currency($employee['overtime'] ?? 0); ?></td>
                                            <td class="text-end fw-bold">Tzs<?php echo format_currency($employee['gross_salary'] ?? 0); ?></td>
                                            <td class="text-end text-danger">Tzs<?php echo format_currency($employee['total_deductions'] ?? 0); ?></td>
                                            <td class="text-end fw-bold text-success">Tzs<?php echo format_currency($employee['net_salary'] ?? 0); ?></td>
                                            <td class="text-end text-warning fw-bold">Tzs<?php echo format_currency($employee['total_employer_cost'] ?? 0); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                                <tfoot class="table-secondary fw-bold">
                                    <tr>
                                        <td colspan="2" class="text-end">TOTALS:</td>
                                        <td class="text-end">Tzs<?php echo format_currency($total_basic); ?></td>
                                        <td class="text-end">Tzs<?php echo format_currency($total_allowances); ?></td>
                                        <td class="text-end">Tzs<?php echo format_currency($total_overtime); ?></td>
                                        <td class="text-end">Tzs<?php echo format_currency($total_gross); ?></td>
                                        <td class="text-end text-danger">Tzs<?php echo format_currency($total_deductions); ?></td>
                                        <td class="text-end text-success">Tzs<?php echo format_currency($total_net); ?></td>
                                        <td class="text-end text-warning">Tzs<?php echo format_currency($total_employer_cost); ?></td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                        
                        <!-- Payment Status -->
                        <div class="p-3 border-top">
                            <div class="row">
                                <div class="col-md-6">
                                    <h6>Payment Approval Status:</h6>
                                    <div class="progress" style="height: 25px;">
                                        <?php
                                        $status = $current_payroll['status'] ?? 'pending';
                                        $progress = 0;
                                        $progress_class = 'bg-warning';
                                        $progress_text = '';
                                        
                                        if ($status === 'pending') {
                                            $progress = 0;
                                            $progress_text = 'Pending CEO Approval';
                                            $progress_class = 'bg-warning';
                                        } elseif ($status === 'approved_ceo') {
                                            $progress = 50;
                                            $progress_text = 'CEO Approved - Pending Finance';
                                            $progress_class = 'bg-info';
                                        } elseif ($status === 'approved_finance') {
                                            $progress = 75;
                                            $progress_text = 'Finance Approved - Processing';
                                            $progress_class = 'bg-primary';
                                        } elseif ($status === 'paid') {
                                            $progress = 100;
                                            $progress_text = 'Payment Completed';
                                            $progress_class = 'bg-success';
                                        } elseif ($status === 'rejected') {
                                            $progress = 100;
                                            $progress_text = 'Rejected';
                                            $progress_class = 'bg-danger';
                                        }
                                        ?>
                                        <div class="progress-bar <?php echo $progress_class; ?> progress-bar-striped progress-bar-animated" 
                                             role="progressbar" style="width: <?php echo $progress; ?>%;">
                                            <?php echo $progress_text; ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <h6>Timeline:</h6>
                                    <ul class="list-unstyled">
                                        <li class="mb-1">
                                            <i class="bi bi-clock me-2"></i>
                                            <strong>Requested:</strong> 
                                            <?php echo date('M d, Y H:i', strtotime($current_payroll['requested_at'])); ?>
                                        </li>
                                        <?php if ($current_payroll['ceo_approved_at']): ?>
                                            <li class="mb-1">
                                                <i class="bi bi-check-circle text-success me-2"></i>
                                                <strong>CEO Approved:</strong> 
                                                <?php echo date('M d, Y H:i', strtotime($current_payroll['ceo_approved_at'])); ?>
                                                <?php if ($current_payroll['ceo_approval_notes']): ?>
                                                    <br><small class="text-muted">Notes: <?php echo htmlspecialchars($current_payroll['ceo_approval_notes']); ?></small>
                                                <?php endif; ?>
                                            </li>
                                        <?php endif; ?>
                                        <?php if ($current_payroll['finance_approved_at']): ?>
                                            <li class="mb-1">
                                                <i class="bi bi-check-circle text-primary me-2"></i>
                                                <strong>Finance Approved:</strong> 
                                                <?php echo date('M d, Y H:i', strtotime($current_payroll['finance_approved_at'])); ?>
                                            </li>
                                        <?php endif; ?>
                                        <?php if ($current_payroll['paid_at']): ?>
                                            <li class="mb-1">
                                                <i class="bi bi-cash-coin text-success me-2"></i>
                                                <strong>Paid:</strong> 
                                                <?php echo date('M d, Y H:i', strtotime($current_payroll['paid_at'])); ?>
                                            </li>
                                        <?php endif; ?>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- --- Recent Trades Table --- -->
    <div class="row mt-4">
        <div class="col-12">
            <div class="card shadow-sm rounded-4">
                <div class="card-header bg-white border-0">
                    <h5 class="card-title mb-0">Recent Trades</h5>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead>
                                <tr>
                                    <th>Trade #</th>
                                    <th>Client</th>
                                    <th>Security</th>
                                    <th>Side</th>
                                    <th>Value</th>
                                    <th>Date</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($recent_trades)): ?>
                                    <tr>
                                        <td colspan="6" class="text-center text-muted">No recent trades.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($recent_trades as $trade): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($trade['trade_reference']); ?></td>
                                            <td><?php echo htmlspecialchars($trade['client_name']); ?></td>
                                            <td><?php echo htmlspecialchars($trade['security_name']); ?></td>
                                            <td>
                                                <span class="badge <?php echo ($trade['trade_side'] == 'BUY') ? 'bg-success' : 'bg-danger'; ?>">
                                                    <?php echo htmlspecialchars($trade['trade_side']); ?>
                                                </span>
                                            </td>
                                            <td>Tzs<?php echo format_currency($trade['trade_value'] ?? 0); ?></td>
                                            <td><?php echo format_date($trade['trade_date']); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

</div>

<!-- Hidden CSRF token for AJAX requests -->
<input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">

<!-- Payment Request Modals -->
<!-- View Request Modal -->
<div class="modal fade" id="viewRequestModal" tabindex="-1" aria-labelledby="viewRequestModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="viewRequestModalLabel">Payment Request Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="requestDetailsContent">
                <!-- Request details will be loaded here -->
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-success" id="modalApproveBtn" style="display: none;">Approve</button>
                <button type="button" class="btn btn-danger" id="modalRejectBtn" style="display: none;">Reject</button>
            </div>
        </div>
    </div>
</div>

<!-- Approve Request Modal -->
<div class="modal fade" id="approveRequestModal" tabindex="-1" aria-labelledby="approveRequestModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="approveRequestModalLabel">Approve Payment Request</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="approveRequestForm">
                <div class="modal-body">
                    <input type="hidden" name="action" value="approve">
                    <input type="hidden" name="request_id" id="approveRequestId">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    
                    <div id="approveRequestSummary"></div>
                    
                    <div class="mb-3">
                        <label for="approveNotes" class="form-label">Approval Notes (Optional)</label>
                        <textarea class="form-control" id="approveNotes" name="notes" rows="3" placeholder="Add any notes for this approval..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success">Approve Request</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Reject Request Modal -->
<div class="modal fade" id="rejectRequestModal" tabindex="-1" aria-labelledby="rejectRequestModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="rejectRequestModalLabel">Reject Payment Request</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="rejectRequestForm">
                <div class="modal-body">
                    <input type="hidden" name="action" value="reject">
                    <input type="hidden" name="request_id" id="rejectRequestId">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    
                    <div id="rejectRequestSummary"></div>
                    
                    <div class="mb-3">
                        <label for="rejectionReason" class="form-label">Rejection Reason <span class="text-danger">*</span></label>
                        <textarea class="form-control" id="rejectionReason" name="rejection_reason" rows="3" required placeholder="Please provide a reason for rejection..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger">Reject Request</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- All Requests Modal -->
<div class="modal fade" id="allRequestsModal" tabindex="-1" aria-labelledby="allRequestsModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="allRequestsModalLabel">All Pending Payment Requests</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <!-- All requests table will be loaded here via AJAX if needed -->
                <p>All pending requests functionality can be implemented here.</p>
            </div>
        </div>
    </div>
</div>

<!-- JavaScript for the Chart and Payment Request Handling -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Initialize Chart
    const ctx = document.getElementById('monthlyTradeChart').getContext('2d');
    const monthlyTradeChart = new Chart(ctx, {
        type: 'line',
        data: {
            labels: <?php echo json_encode($months); ?>,
            datasets: [{
                label: 'Monthly Trade Value (Tzs)',
                data: <?php echo json_encode($monthly_totals); ?>,
                borderColor: 'rgba(75, 192, 192, 1)',
                backgroundColor: 'rgba(75, 192, 192, 0.2)',
                borderWidth: 2,
                fill: true,
                tension: 0.4
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                x: {
                    grid: {
                        display: false
                    }
                },
                y: {
                    beginAtZero: true,
                    title: {
                        display: true,
                        text: 'Value (Tzs)'
                    },
                    ticks: {
                        callback: function(value, index, values) {
                            return 'Tzs' + (value / 1000) + 'k';
                        }
                    }
                }
            },
            plugins: {
                legend: {
                    display: false
                }
            }
        }
    });

    // Toast notification function
    function showToast(message, type = 'info') {
        let toastContainer = document.querySelector('.toast-container');
        if (!toastContainer) {
            toastContainer = document.createElement('div');
            toastContainer.className = 'toast-container position-fixed top-0 end-0 p-3';
            document.body.appendChild(toastContainer);
        }

        const toast = document.createElement('div');
        toast.className = `toast align-items-center text-bg-${type} border-0`;
        toast.innerHTML = `
            <div class="d-flex">
                <div class="toast-body">
                    ${message}
                </div>
                <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
            </div>
        `;

        toastContainer.appendChild(toast);
        const bsToast = new bootstrap.Toast(toast);
        bsToast.show();

        toast.addEventListener('hidden.bs.toast', () => {
            toast.remove();
        });
    }

    // View Request Details
    document.querySelectorAll('.view-request').forEach(button => {
        button.addEventListener('click', function() {
            const requestId = this.dataset.requestId;
            loadRequestDetails(requestId, true);
        });
    });

    // Load request details
    function loadRequestDetails(requestId, showActionButtons = false) {
        const formData = new FormData();
        formData.append('request_id', requestId);
        formData.append('action', 'get_request_details');
        formData.append('csrf_token', document.querySelector('input[name="csrf_token"]').value);

        fetch('', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const request = data.data;
                const details = `
                    <div class="request-details">
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <h6 class="text-primary">Request Information</h6>
                                <table class="table table-sm table-borderless">
                                    <tr>
                                        <td class="fw-bold" style="width: 40%;">Request No:</td>
                                        <td><strong>${request.request_no}</strong></td>
                                    </tr>
                                    <tr>
                                        <td class="fw-bold">Subject:</td>
                                        <td>${request.subject}</td>
                                    </tr>
                                    <tr>
                                        <td class="fw-bold">Requested Date:</td>
                                        <td>${new Date(request.requested_at).toLocaleString()}</td>
                                    </tr>
                                    <tr>
                                        <td class="fw-bold">Requested By:</td>
                                        <td>${request.requested_by_fullname || request.requested_by_username || 'N/A'}</td>
                                    </tr>
                                    <tr>
                                        <td class="fw-bold">Status:</td>
                                        <td>
                                            <span class="badge bg-warning">
                                                PENDING CEO APPROVAL
                                            </span>
                                        </td>
                                    </tr>
                                </table>
                            </div>
                            <div class="col-md-6">
                                <h6 class="text-primary">Payment Details</h6>
                                <table class="table table-sm table-borderless">
                                    <tr>
                                        <td class="fw-bold" style="width: 40%;">Pay To:</td>
                                        <td>${request.pay_to_desc || request.pay_to_type}</td>
                                    </tr>
                                    <tr>
                                        <td class="fw-bold">Payee Name:</td>
                                        <td>${request.payee_name}</td>
                                    </tr>
                                    <tr>
                                        <td class="fw-bold">Payee ID:</td>
                                        <td>${request.payee_id || 'N/A'}</td>
                                    </tr>
                                    <tr>
                                        <td class="fw-bold">Amount:</td>
                                        <td class="fw-bold text-success">
                                            ${parseFloat(request.amount_paid).toLocaleString()} ${request.currency}
                                        </td>
                                    </tr>
                                    <tr>
                                        <td class="fw-bold">Cheque No:</td>
                                        <td>${request.cheque_no || '-'}</td>
                                    </tr>
                                    <tr>
                                        <td class="fw-bold">Description:</td>
                                        <td>${request.payment_description || '-'}</td>
                                    </tr>
                                </table>
                            </div>
                        </div>
                        
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <h6 class="text-primary">Bank Details</h6>
                                <table class="table table-sm table-borderless">
                                    <tr>
                                        <td class="fw-bold" style="width: 40%;">Bank Name:</td>
                                        <td>${request.payee_bank_name || '-'}</td>
                                    </tr>
                                    <tr>
                                        <td class="fw-bold">Branch:</td>
                                        <td>${request.payee_branch || '-'}</td>
                                    </tr>
                                    <tr>
                                        <td class="fw-bold">Account Name:</td>
                                        <td>${request.payee_account_name || '-'}</td>
                                    </tr>
                                    <tr>
                                        <td class="fw-bold">Account No:</td>
                                        <td>${request.payee_account_no}</td>
                                    </tr>
                                </table>
                            </div>
                        </div>
                    </div>
                `;
                document.getElementById('requestDetailsContent').innerHTML = details;
                
                // Show/hide action buttons
                const approveBtn = document.getElementById('modalApproveBtn');
                const rejectBtn = document.getElementById('modalRejectBtn');
                
                if (showActionButtons && request.status === 'pending') {
                    approveBtn.style.display = 'inline-block';
                    rejectBtn.style.display = 'inline-block';
                    
                    // Set up approve button
                    approveBtn.onclick = function() {
                        showApproveModal(requestId, request);
                    };
                    
                    // Set up reject button
                    rejectBtn.onclick = function() {
                        showRejectModal(requestId, request);
                    };
                } else {
                    approveBtn.style.display = 'none';
                    rejectBtn.style.display = 'none';
                }
                
                // Show the modal
                const viewModal = new bootstrap.Modal(document.getElementById('viewRequestModal'));
                viewModal.show();
            } else {
                showToast(data.message, 'danger');
            }
        })
        .catch(error => {
            console.error('Error loading request:', error);
            showToast('Error loading request details', 'danger');
        });
    }

    // Show Approve Modal
    function showApproveModal(requestId, request) {
        document.getElementById('approveRequestId').value = requestId;
        document.getElementById('approveRequestSummary').innerHTML = `
            <div class="alert alert-info">
                <strong>${request.request_no}</strong> - ${request.subject}<br>
                <strong>Amount:</strong> ${parseFloat(request.amount_paid).toLocaleString()} ${request.currency}<br>
                <strong>Payee:</strong> ${request.payee_name}
            </div>
        `;
        
        const approveModal = new bootstrap.Modal(document.getElementById('approveRequestModal'));
        approveModal.show();
        
        // Close the view modal
        const viewModal = bootstrap.Modal.getInstance(document.getElementById('viewRequestModal'));
        if (viewModal) viewModal.hide();
    }

    // Show Reject Modal
    function showRejectModal(requestId, request) {
        document.getElementById('rejectRequestId').value = requestId;
        document.getElementById('rejectRequestSummary').innerHTML = `
            <div class="alert alert-warning">
                <strong>${request.request_no}</strong> - ${request.subject}<br>
                <strong>Amount:</strong> ${parseFloat(request.amount_paid).toLocaleString()} ${request.currency}<br>
                <strong>Payee:</strong> ${request.payee_name}
            </div>
        `;
        
        const rejectModal = new bootstrap.Modal(document.getElementById('rejectRequestModal'));
        rejectModal.show();
        
        // Close the view modal
        const viewModal = bootstrap.Modal.getInstance(document.getElementById('viewRequestModal'));
        if (viewModal) viewModal.hide();
    }

    // Approve Request
    document.getElementById('approveRequestForm').addEventListener('submit', function(e) {
        e.preventDefault();
        
        const formData = new FormData(this);
        const submitBtn = this.querySelector('button[type="submit"]');
        const originalText = submitBtn.innerHTML;
        submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Approving...';
        submitBtn.disabled = true;
        
        fetch('', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showToast(data.message, 'success');
                setTimeout(() => {
                    location.reload();
                }, 1500);
            } else {
                showToast(data.message, 'danger');
                submitBtn.innerHTML = originalText;
                submitBtn.disabled = false;
            }
        })
        .catch(error => {
            console.error('Error approving request:', error);
            showToast('Error approving request', 'danger');
            submitBtn.innerHTML = originalText;
            submitBtn.disabled = false;
        });
    });

    // Reject Request
    document.getElementById('rejectRequestForm').addEventListener('submit', function(e) {
        e.preventDefault();
        
        if (!this.checkValidity()) {
            this.classList.add('was-validated');
            return;
        }
        
        const formData = new FormData(this);
        const submitBtn = this.querySelector('button[type="submit"]');
        const originalText = submitBtn.innerHTML;
        submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Rejecting...';
        submitBtn.disabled = true;
        
        fetch('', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showToast(data.message, 'success');
                setTimeout(() => {
                    location.reload();
                }, 1500);
            } else {
                showToast(data.message, 'danger');
                submitBtn.innerHTML = originalText;
                submitBtn.disabled = false;
            }
        })
        .catch(error => {
            console.error('Error rejecting request:', error);
            showToast('Error rejecting request', 'danger');
            submitBtn.innerHTML = originalText;
            submitBtn.disabled = false;
        });
    });

    // Approve request from table buttons
    document.querySelectorAll('.approve-request').forEach(button => {
        button.addEventListener('click', function() {
            const requestId = this.dataset.requestId;
            
            // Load request details first
            const formData = new FormData();
            formData.append('request_id', requestId);
            formData.append('action', 'get_request_details');
            formData.append('csrf_token', document.querySelector('input[name="csrf_token"]').value);
            
            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showApproveModal(requestId, data.data);
                } else {
                    showToast(data.message, 'danger');
                }
            })
            .catch(error => {
                console.error('Error loading request:', error);
                showToast('Error loading request details', 'danger');
            });
        });
    });

    // Reject request from table buttons
    document.querySelectorAll('.reject-request').forEach(button => {
        button.addEventListener('click', function() {
            const requestId = this.dataset.requestId;
            
            // Load request details first
            const formData = new FormData();
            formData.append('request_id', requestId);
            formData.append('action', 'get_request_details');
            formData.append('csrf_token', document.querySelector('input[name="csrf_token"]').value);
            
            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showRejectModal(requestId, data.data);
                } else {
                    showToast(data.message, 'danger');
                }
            })
            .catch(error => {
                console.error('Error loading request:', error);
                showToast('Error loading request details', 'danger');
            });
        });
    });
});
</script>

<style>
.toast-container {
    z-index: 9999;
}
.request-details table tr td {
    padding: 4px 8px;
}
.badge {
    font-size: 0.75em;
}
.progress-bar-animated {
    animation: progress-bar-stripes 1s linear infinite;
}
@keyframes progress-bar-stripes {
    0% { background-position: 1rem 0; }
    100% { background-position: 0 0; }
}
</style>

<?php include '../includes/footer.php'; ?>