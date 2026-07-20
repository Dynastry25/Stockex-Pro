<?php
require_once '../config/config.php';
require_once '../auth/auth_middleware.php';

require_hr();
$db = getDBConnection();

$page_title = 'HR Reports';
include '../includes/header.php';

// Get report data
$department_stats = $db->query("
    SELECT d.name, COUNT(u.id) as employee_count,
           AVG(u.salary) as avg_salary
    FROM departments d
    LEFT JOIN users u ON d.id = u.department_id AND u.status = 'active'
    GROUP BY d.id
")->fetchAll();

$turnover_rate = $db->query("SELECT COUNT(CASE WHEN status = 'terminated' THEN 1 END) as `terminated_count`, COUNT(*) as `total_employees`, ROUND((COUNT(CASE WHEN status = 'terminated' THEN 1 END) * 100.0 / COUNT(*)), 2) as `turnover_rate` FROM users")->fetch();
?>

<div class="container-fluid pt-4 px-4">
    <div class="d-sm-flex align-items-center justify-content-between mb-4">
        <h1 class="h3 mb-0 text-gray-800">HR Reports & Analytics</h1>
        <div class="btn-group">
            <button class="btn btn-primary" onclick="window.print()">
                <i class="bi bi-printer me-2"></i>Print Report
            </button>
            <button class="btn btn-success">
                <i class="bi bi-download me-2"></i>Export Excel
            </button>
        </div>
    </div>

    <div class="row">
        <!-- Department Statistics -->
        <div class="col-md-6">
            <div class="card shadow mb-4">
                <div class="card-header py-3">
                    <h6 class="m-0 font-weight-bold text-primary">Department Statistics</h6>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-bordered">
                            <thead>
                                <tr>
                                    <th>Department</th>
                                    <th>Employees</th>
                                    <th>Avg Salary</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($department_stats as $stat): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($stat['name']); ?></td>
                                    <td><?php echo $stat['employee_count']; ?></td>
                                    <td>TZS <?php echo format_currency($stat['avg_salary']); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- Turnover Analytics -->
        <div class="col-md-6">
            <div class="card shadow mb-4">
                <div class="card-header py-3">
                    <h6 class="m-0 font-weight-bold text-primary">Employee Turnover</h6>
                </div>
                <div class="card-body">
                    <div class="text-center">
                        <h4 class="text-<?php echo $turnover_rate['turnover_rate'] < 10 ? 'success' : 'danger'; ?>">
                            <?php echo $turnover_rate['turnover_rate']; ?>%
                        </h4>
                        <p class="text-muted">Turnover Rate</p>
                    </div>
                    <div class="row text-center">
                        <div class="col-6">
                            <h6><?php echo $turnover_rate['total_employees']; ?></h6>
                            <small class="text-muted">Total Employees</small>
                        </div>
                        <div class="col-6">
                            <h6><?php echo $turnover_rate['terminated_count']; ?></h6>
                            <small class="text-muted">Terminated</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Leave Reports -->
    <div class="row">
        <div class="col-md-12">
            <div class="card shadow mb-4">
                <div class="card-header py-3">
                    <h6 class="m-0 font-weight-bold text-primary">Leave Analysis</h6>
                </div>
                <div class="card-body">
                    <?php
                    $leave_stats = $db->query("
                        SELECT lt.name, COUNT(lr.id) as request_count,
                               COALESCE(AVG(lr.total_days), 0.0) as avg_days
                        FROM leave_types lt
                        LEFT JOIN leave_requests lr ON lt.id = lr.leave_type_id
                        GROUP BY lt.id
                    ")->fetchAll();
                    ?>
                    <div class="table-responsive">
                        <table class="table table-bordered">
                            <thead>
                                <tr>
                                    <th>Leave Type</th>
                                    <th>Total Requests</th>
                                    <th>Average Days</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($leave_stats as $stat): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($stat['name']); ?></td>
                                    <td><?php echo $stat['request_count']; ?></td>
                                    <td><?php echo number_format($stat['avg_days'], 1); ?> days</td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include '../includes/footer.php'; ?>