<?php
require_once '../../config/config.php';
require_once '../../auth/auth_middleware.php';
require_once '../../includes/payroll_helpers.php';

require_hr(); // Only HR can access

$db = getDBConnection();
$page_title = 'Payroll Reports';

// Date range filter
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-t');
$department = $_GET['department'] ?? '';

// Get payroll summary
try {
    $where_conditions = ["p.pay_period_start >= ?", "p.pay_period_end <= ?"];
    $params = [$start_date, $end_date];
    
    if ($department) {
        $where_conditions[] = "u.department = ?";
        $params[] = $department;
    }
    
    $where_clause = implode(' AND ', $where_conditions);
    
    $summary_stmt = $db->prepare("
        SELECT 
            COUNT(*) as total_payrolls,
            COUNT(DISTINCT p.user_id) as total_employees,
            SUM(p.basic_salary) as total_basic_salary,
            SUM(p.total_allowances) as total_allowances,
            SUM(p.total_deductions) as total_deductions,
            SUM(p.tax_amount) as total_tax,
            SUM(p.social_security_amount) as total_ss,
            SUM(p.net_pay) as total_net_pay,
            AVG(p.net_pay) as average_net_pay,
            MIN(p.net_pay) as min_net_pay,
            MAX(p.net_pay) as max_net_pay
        FROM payroll p
        JOIN users u ON p.user_id = u.id
        WHERE {$where_clause}
        AND p.status = 'paid'
    ");
    $summary_stmt->execute($params);
    $summary = $summary_stmt->fetch();
    
    // Get department breakdown
    $dept_stmt = $db->prepare("
        SELECT 
            u.department,
            COUNT(*) as count,
            SUM(p.net_pay) as total_net_pay,
            AVG(p.net_pay) as avg_net_pay
        FROM payroll p
        JOIN users u ON p.user_id = u.id
        WHERE p.pay_period_start >= ? AND p.pay_period_end <= ?
        AND p.status = 'paid'
        GROUP BY u.department
        ORDER BY total_net_pay DESC
    ");
    $dept_stmt->execute([$start_date, $end_date]);
    $department_breakdown = $dept_stmt->fetchAll();
    
    // Get monthly trend
    $trend_stmt = $db->prepare("
        SELECT 
            DATE_FORMAT(p.pay_period_start, '%Y-%m') as month,
            COUNT(*) as payroll_count,
            SUM(p.net_pay) as total_net_pay,
            AVG(p.net_pay) as avg_net_pay
        FROM payroll p
        WHERE p.status = 'paid'
        GROUP BY DATE_FORMAT(p.pay_period_start, '%Y-%m')
        ORDER BY month DESC
        LIMIT 12
    ");
    $trend_stmt->execute();
    $monthly_trend = $trend_stmt->fetchAll();
    
} catch (Exception $e) {
    $summary = [];
    $department_breakdown = [];
    $monthly_trend = [];
    $error_message = 'Error generating report: ' . $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payroll Reports</title>

    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <!-- Custom CSS -->
    <link href="<?php echo BASE_URL; ?>assets/css/style.css" rel="stylesheet">
</head>
<body>
<div class="container-fluid pt-4 px-4">
    <div class="d-sm-flex align-items-center justify-content-between mb-4">
        <h1 class="h3 mb-0 text-gray-800">
            <i class="bi bi-graph-up me-2"></i>Payroll Reports
        </h1>
        <div class="d-flex gap-2">
            <button class="btn btn-primary" onclick="window.print()">
                <i class="bi bi-printer me-2"></i>Print Report
            </button>
            <button class="btn btn-success" id="exportBtn">
                <i class="bi bi-file-earmark-excel me-2"></i>Export to Excel
            </button>
        </div>
    </div>

    <!-- Filter Section -->
    <div class="card shadow mb-4">
        <div class="card-header py-3">
            <h6 class="m-0 font-weight-bold text-primary">
                <i class="bi bi-funnel me-2"></i>Report Filters
            </h6>
        </div>
        <div class="card-body">
            <form method="GET" class="row g-3">
                <div class="col-md-3">
                    <label class="form-label">Start Date</label>
                    <input type="date" name="start_date" class="form-control" 
                           value="<?php echo htmlspecialchars($start_date); ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">End Date</label>
                    <input type="date" name="end_date" class="form-control" 
                           value="<?php echo htmlspecialchars($end_date); ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Department</label>
                    <select name="department" class="form-select">
                        <option value="">All Departments</option>
                        <option value="HR" <?php echo $department == 'HR' ? 'selected' : ''; ?>>HR</option>
                        <option value="Finance" <?php echo $department == 'Finance' ? 'selected' : ''; ?>>Finance</option>
                        <option value="IT" <?php echo $department == 'IT' ? 'selected' : ''; ?>>IT</option>
                        <option value="Sales" <?php echo $department == 'Sales' ? 'selected' : ''; ?>>Sales</option>
                        <option value="Operations" <?php echo $department == 'Operations' ? 'selected' : ''; ?>>Operations</option>
                    </select>
                </div>
                <div class="col-md-3 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="bi bi-filter me-2"></i>Generate Report
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Summary Cards -->
    <div class="row mb-4">
        <div class="col-xl-3 col-md-6 mb-4">
            <div class="card border-left-primary shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">
                                Total Payrolls</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">
                                <?php echo $summary['total_payrolls'] ?? 0; ?>
                            </div>
                        </div>
                        <div class="col-auto">
                            <i class="bi bi-cash-stack fa-2x text-primary"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6 mb-4">
            <div class="card border-left-success shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-success text-uppercase mb-1">
                                Total Net Pay</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">
                                <?php echo format_currency($summary['total_net_pay'] ?? 0); ?>
                            </div>
                        </div>
                        <div class="col-auto">
                            <i class="bi bi-currency-dollar fa-2x text-success"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6 mb-4">
            <div class="card border-left-info shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-info text-uppercase mb-1">
                                Average Net Pay</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">
                                <?php echo format_currency($summary['average_net_pay'] ?? 0); ?>
                            </div>
                        </div>
                        <div class="col-auto">
                            <i class="bi bi-graph-up fa-2x text-info"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6 mb-4">
            <div class="card border-left-warning shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-warning text-uppercase mb-1">
                                Total Employees</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">
                                <?php echo $summary['total_employees'] ?? 0; ?>
                            </div>
                        </div>
                        <div class="col-auto">
                            <i class="bi bi-people fa-2x text-warning"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Detailed Breakdown -->
    <div class="row mb-4">
        <div class="col-md-6">
            <div class="card shadow">
                <div class="card-header py-3">
                    <h6 class="m-0 font-weight-bold text-primary">
                        <i class="bi bi-pie-chart me-2"></i>Department Breakdown
                    </h6>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-bordered">
                            <thead>
                                <tr>
                                    <th>Department</th>
                                    <th class="text-end">Employees</th>
                                    <th class="text-end">Total Net Pay</th>
                                    <th class="text-end">Average</th>
                                    <th class="text-end">% of Total</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $grand_total = $summary['total_net_pay'] ?? 1;
                                foreach ($department_breakdown as $dept): 
                                    $percentage = ($dept['total_net_pay'] / $grand_total) * 100;
                                ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($dept['department']); ?></td>
                                    <td class="text-end"><?php echo $dept['count']; ?></td>
                                    <td class="text-end"><?php echo format_currency($dept['total_net_pay']); ?></td>
                                    <td class="text-end"><?php echo format_currency($dept['avg_net_pay']); ?></td>
                                    <td class="text-end">
                                        <span class="badge bg-info">
                                            <?php echo number_format($percentage, 1); ?>%
                                        </span>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-md-6">
            <div class="card shadow">
                <div class="card-header py-3">
                    <h6 class="m-0 font-weight-bold text-primary">
                        <i class="bi bi-bar-chart me-2"></i>Monthly Trend (Last 12 Months)
                    </h6>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-bordered">
                            <thead>
                                <tr>
                                    <th>Month</th>
                                    <th class="text-end">Payrolls</th>
                                    <th class="text-end">Total Net Pay</th>
                                    <th class="text-end">Average</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($monthly_trend as $month): ?>
                                <tr>
                                    <td><?php echo date('F Y', strtotime($month['month'] . '-01')); ?></td>
                                    <td class="text-end"><?php echo $month['payroll_count']; ?></td>
                                    <td class="text-end"><?php echo format_currency($month['total_net_pay']); ?></td>
                                    <td class="text-end"><?php echo format_currency($month['avg_net_pay']); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Detailed Summary -->
    <div class="card shadow">
        <div class="card-header py-3">
            <h6 class="m-0 font-weight-bold text-primary">
                <i class="bi bi-list-check me-2"></i>Detailed Summary
            </h6>
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-4">
                    <h6 class="text-success">Earnings</h6>
                    <table class="table table-sm">
                        <tr>
                            <td>Basic Salary:</td>
                            <td class="text-end"><?php echo format_currency($summary['total_basic_salary'] ?? 0); ?></td>
                        </tr>
                        <tr>
                            <td>Allowances:</td>
                            <td class="text-end"><?php echo format_currency($summary['total_allowances'] ?? 0); ?></td>
                        </tr>
                        <tr class="table-success">
                            <td><strong>Total Earnings:</strong></td>
                            <td class="text-end"><strong><?php echo format_currency(($summary['total_basic_salary'] ?? 0) + ($summary['total_allowances'] ?? 0)); ?></strong></td>
                        </tr>
                    </table>
                </div>
                
                <div class="col-md-4">
                    <h6 class="text-danger">Deductions</h6>
                    <table class="table table-sm">
                        <tr>
                            <td>Statutory Deductions:</td>
                            <td class="text-end"><?php echo format_currency($summary['total_deductions'] ?? 0); ?></td>
                        </tr>
                        <tr>
                            <td>Income Tax:</td>
                            <td class="text-end"><?php echo format_currency($summary['total_tax'] ?? 0); ?></td>
                        </tr>
                        <tr>
                            <td>Social Security:</td>
                            <td class="text-end"><?php echo format_currency($summary['total_ss'] ?? 0); ?></td>
                        </tr>
                        <tr class="table-danger">
                            <td><strong>Total Deductions:</strong></td>
                            <td class="text-end"><strong><?php echo format_currency(($summary['total_deductions'] ?? 0) + ($summary['total_tax'] ?? 0) + ($summary['total_ss'] ?? 0)); ?></strong></td>
                        </tr>
                    </table>
                </div>
                
                <div class="col-md-4">
                    <h6 class="text-primary">Net Pay Summary</h6>
                    <table class="table table-sm">
                        <tr>
                            <td>Highest Net Pay:</td>
                            <td class="text-end"><?php echo format_currency($summary['max_net_pay'] ?? 0); ?></td>
                        </tr>
                        <tr>
                            <td>Lowest Net Pay:</td>
                            <td class="text-end"><?php echo format_currency($summary['min_net_pay'] ?? 0); ?></td>
                        </tr>
                        <tr>
                            <td>Average Net Pay:</td>
                            <td class="text-end"><?php echo format_currency($summary['average_net_pay'] ?? 0); ?></td>
                        </tr>
                        <tr class="table-primary">
                            <td><strong>Total Net Pay:</strong></td>
                            <td class="text-end"><strong><?php echo format_currency($summary['total_net_pay'] ?? 0); ?></strong></td>
                        </tr>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.getElementById('exportBtn').addEventListener('click', function() {
    // Simple export to CSV
    let csv = 'Payroll Report\n';
    csv += 'Period: <?php echo date("d M Y", strtotime($start_date)) . " to " . date("d M Y", strtotime($end_date)); ?>\n\n';
    
    // Summary
    csv += 'SUMMARY\n';
    csv += 'Total Payrolls,<?php echo $summary["total_payrolls"] ?? 0; ?>\n';
    csv += 'Total Employees,<?php echo $summary["total_employees"] ?? 0; ?>\n';
    csv += 'Total Basic Salary,<?php echo $summary["total_basic_salary"] ?? 0; ?>\n';
    csv += 'Total Allowances,<?php echo $summary["total_allowances"] ?? 0; ?>\n';
    csv += 'Total Net Pay,<?php echo $summary["total_net_pay"] ?? 0; ?>\n\n';
    
    // Department breakdown
    csv += 'DEPARTMENT BREAKDOWN\n';
    csv += 'Department,Employees,Total Net Pay,Average,% of Total\n';
    <?php foreach ($department_breakdown as $dept): ?>
    csv += '<?php echo $dept["department"]; ?>,<?php echo $dept["count"]; ?>,<?php echo $dept["total_net_pay"]; ?>,<?php echo $dept["avg_net_pay"]; ?>,<?php echo number_format(($dept["total_net_pay"] / $grand_total) * 100, 1); ?>%\n';
    <?php endforeach; ?>
    
    // Create download link
    const blob = new Blob([csv], { type: 'text/csv' });
    const url = window.URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'payroll_report_<?php echo date("Y_m_d"); ?>.csv';
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    window.URL.revokeObjectURL(url);
});
</script>

<style>
@media print {
    .card-header, .btn, .form-control, .form-select {
        display: none !important;
    }
    .card {
        border: none !important;
        box-shadow: none !important;
    }
    .table {
        font-size: 12px;
    }
}
</style>

<!-- Bootstrap JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<!-- Custom JS -->
<script src="<?php echo BASE_URL; ?>assets/js/script.js"></script>
</body>
</html>