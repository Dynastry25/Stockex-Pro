<?php
require_once 'config/config.php';
require_once 'auth/auth_middleware.php';

require_login();
$db = getDBConnection();
$user = get_logged_in_user();

$page_title = 'My Performance Targets';

// Get employee record for this user
$emp_stmt = $db->prepare("
    SELECT u.id as employee_id, u.first_name, u.last_name, d.name as department_name
    FROM users u
    LEFT JOIN departments d ON u.department_id = d.id
    WHERE u.id = ? AND u.employee_id IS NOT NULL
    LIMIT 1
");
$emp_stmt->execute([$user['id']]);
$employee = $emp_stmt->fetch();

// If user is not an employee, redirect with message
if (!$employee) {
    show_alert('This page is only available for employees.', 'warning');
    redirect('index.php');
}

// Get all targets for this employee
$targets_stmt = $db->prepare("
    SELECT 
        pt.id,
        pt.title,
        pt.description,
        pt.target_type,
        pt.target_value,
        pt.unit,
        pt.current_value,
        pt.progress_percentage,
        pt.start_date,
        pt.end_date,
        pt.priority,
        pt.status,
        pt.ceo_decision_status,
        pt.created_at,
        u.full_name as created_by_name
    FROM performance_targets pt
    LEFT JOIN users u ON pt.created_by = u.id
    WHERE pt.employee_id = ?
    ORDER BY 
        CASE 
            WHEN pt.status = 'active' THEN 0
            WHEN pt.status = 'in_progress' THEN 1
            ELSE 2
        END,
        CASE 
            WHEN pt.priority = 'high' THEN 0
            WHEN pt.priority = 'medium' THEN 1
            ELSE 2
        END,
        pt.end_date ASC
");
$targets_stmt->execute([$employee['employee_id']]);
$all_targets = $targets_stmt->fetchAll();

// Separate targets by status
$active_targets = array_filter($all_targets, fn($t) => $t['status'] === 'active');
$in_progress_targets = array_filter($all_targets, fn($t) => $t['status'] === 'in_progress');
$completed_targets = array_filter($all_targets, fn($t) => $t['status'] === 'completed');

include 'includes/header.php';
?>

<div class="container-fluid px-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h3 mb-0 text-gray-800">
                <i class="bi bi-bullseye me-2"></i>My Performance Targets
            </h1>
            <p class="text-muted mt-2">
                <i class="bi bi-person me-1"></i><?php echo htmlspecialchars($employee['first_name'] . ' ' . $employee['last_name']); ?> 
                <i class="bi bi-building ms-2 me-1"></i><?php echo htmlspecialchars($employee['department_name']); ?>
            </p>
        </div>
        <div class="d-flex gap-2">
            <button class="btn btn-outline-secondary" onclick="window.location.reload();">
                <i class="bi bi-arrow-clockwise me-1"></i>Refresh
            </button>
            <a href="<?php echo BASE_URL; ?>profile.php" class="btn btn-outline-primary">
                <i class="bi bi-person-circle me-1"></i>Back to Profile
            </a>
        </div>
    </div>

    <!-- Summary Statistics -->
    <div class="row mb-4">
        <div class="col-md-3 mb-3">
            <div class="card border-left-primary shadow h-100">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">Total Targets</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo count($all_targets); ?></div>
                        </div>
                        <div class="col-auto">
                            <i class="bi bi-bullseye fs-2 text-primary"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3 mb-3">
            <div class="card border-left-warning shadow h-100">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-warning text-uppercase mb-1">Active Targets</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo count($active_targets); ?></div>
                        </div>
                        <div class="col-auto">
                            <i class="bi bi-circle-fill fs-2 text-warning"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3 mb-3">
            <div class="card border-left-info shadow h-100">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-info text-uppercase mb-1">In Progress</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo count($in_progress_targets); ?></div>
                        </div>
                        <div class="col-auto">
                            <i class="bi bi-hourglass-split fs-2 text-info"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3 mb-3">
            <div class="card border-left-success shadow h-100">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-success text-uppercase mb-1">Completed</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo count($completed_targets); ?></div>
                        </div>
                        <div class="col-auto">
                            <i class="bi bi-check-circle fs-2 text-success"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php if (empty($all_targets)): ?>
        <!-- No Targets Message -->
        <div class="card shadow">
            <div class="card-body text-center py-5">
                <i class="bi bi-bullseye display-1 text-muted mb-3"></i>
                <h4 class="text-muted">No Performance Targets Set Yet</h4>
                <p class="text-muted">Your performance targets will appear here once they're set by HR.</p>
            </div>
        </div>
    <?php else: ?>

        <!-- Active Targets -->
        <?php if (!empty($active_targets)): ?>
        <div class="mb-4">
            <h5 class="mb-3">
                <i class="bi bi-circle-fill text-warning me-2"></i>Active Targets (<?php echo count($active_targets); ?>)
            </h5>
            <div class="row">
                <?php foreach ($active_targets as $target): 
                    $progress = $target['progress_percentage'] ?? 0;
                    $priority_color = $target['priority'] === 'high' ? 'danger' : ($target['priority'] === 'medium' ? 'warning' : 'info');
                    $priority_bg = $target['priority'] === 'high' ? 'bg-danger' : ($target['priority'] === 'medium' ? 'bg-warning' : 'bg-info');
                    $daysRemaining = (strtotime($target['end_date']) - time()) / 86400;
                    $isOverdue = $daysRemaining < 0;
                    $ceo_status_badge = '';
                    
                    if ($target['ceo_decision_status'] === 'pending_ceo') {
                        $ceo_status_badge = '<span class="badge bg-secondary ms-2">Pending Approval</span>';
                    } elseif ($target['ceo_decision_status'] === 'approved') {
                        $ceo_status_badge = '<span class="badge bg-success ms-2">CEO Approved</span>';
                    } elseif ($target['ceo_decision_status'] === 'rejected') {
                        $ceo_status_badge = '<span class="badge bg-danger ms-2">CEO Rejected</span>';
                    }
                ?>
                <div class="col-lg-6 mb-4">
                    <div class="card h-100 shadow-sm border-left-<?php echo $priority_color; ?> transition-all">
                        <div class="card-header bg-light">
                            <div class="d-flex justify-content-between align-items-start">
                                <h6 class="mb-0"><?php echo htmlspecialchars($target['title']); ?></h6>
                                <span class="badge <?php echo $priority_bg; ?>"><?php echo ucfirst($target['priority']); ?> Priority</span>
                            </div>
                        </div>
                        <div class="card-body">
                            <?php if ($target['description']): ?>
                            <p class="text-muted small mb-3"><?php echo htmlspecialchars($target['description']); ?></p>
                            <?php endif; ?>
                            
                            <!-- Progress Bar -->
                            <div class="mb-3">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <span class="small text-muted">Progress</span>
                                    <strong class="small"><?php echo round($progress); ?>%</strong>
                                </div>
                                <div class="progress" style="height: 8px;">
                                    <div class="progress-bar" 
                                         role="progressbar" 
                                         style="width: <?php echo $progress; ?>%; background: linear-gradient(90deg, #4CAF50 0%, #45a049 100%);" 
                                         aria-valuenow="<?php echo $progress; ?>" 
                                         aria-valuemin="0" 
                                         aria-valuemax="100"></div>
                                </div>
                            </div>
                            
                            <!-- Current vs Target Values -->
                            <div class="row g-2 mb-3">
                                <div class="col-6">
                                    <div class="p-2 bg-light rounded text-center">
                                        <small class="text-muted d-block">Current Value</small>
                                        <strong><?php echo number_format($target['current_value'] ?? 0, 2); ?></strong>
                                        <small class="text-muted"><?php echo htmlspecialchars($target['unit']); ?></small>
                                    </div>
                                </div>
                                <div class="col-6">
                                    <div class="p-2 bg-light rounded text-center">
                                        <small class="text-muted d-block">Target Value</small>
                                        <strong><?php echo number_format($target['target_value'], 2); ?></strong>
                                        <small class="text-muted"><?php echo htmlspecialchars($target['unit']); ?></small>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Target Type and Dates -->
                            <div class="row g-2 mb-3 small">
                                <div class="col-6">
                                    <i class="bi bi-tag text-muted me-1"></i>
                                    <span class="badge bg-light text-dark"><?php echo ucfirst(str_replace('_', ' ', $target['target_type'])); ?></span>
                                </div>
                                <div class="col-6 text-end">
                                    <i class="bi bi-calendar text-muted me-1"></i>
                                    <span><?php echo format_date($target['start_date']); ?> - <?php echo format_date($target['end_date']); ?></span>
                                </div>
                            </div>
                            
                            <!-- Deadline Status -->
                            <div class="mb-3 p-2 rounded <?php echo $isOverdue ? 'bg-danger bg-opacity-10 text-danger' : 'bg-info bg-opacity-10 text-info'; ?> small">
                                <?php if ($isOverdue): ?>
                                    <i class="bi bi-exclamation-circle me-1"></i>
                                    <strong>Overdue by <?php echo abs(intval($daysRemaining)); ?> day<?php echo abs(intval($daysRemaining)) !== 1 ? 's' : ''; ?></strong>
                                <?php else: ?>
                                    <i class="bi bi-clock me-1"></i>
                                    <strong><?php echo intval($daysRemaining); ?> day<?php echo intval($daysRemaining) !== 1 ? 's' : ''; ?> remaining</strong>
                                <?php endif; ?>
                            </div>
                            
                            <!-- CEO Approval Status -->
                            <?php if ($ceo_status_badge): ?>
                            <div class="mb-3">
                                <small class="text-muted">Approval Status:</small>
                                <?php echo $ceo_status_badge; ?>
                            </div>
                            <?php endif; ?>
                            
                            <!-- Set By -->
                            <div class="text-muted border-top pt-2 small">
                                <i class="bi bi-person text-muted me-1"></i>
                                <span>Set by: <?php echo htmlspecialchars($target['created_by_name']); ?></span>
                                <br>
                                <i class="bi bi-calendar text-muted me-1"></i>
                                <span><?php echo format_date($target['created_at']); ?></span>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- In Progress Targets -->
        <?php if (!empty($in_progress_targets)): ?>
        <div class="mb-4">
            <h5 class="mb-3">
                <i class="bi bi-hourglass-split text-info me-2"></i>In Progress Targets (<?php echo count($in_progress_targets); ?>)
            </h5>
            <div class="row">
                <?php foreach ($in_progress_targets as $target): 
                    $progress = $target['progress_percentage'] ?? 0;
                ?>
                <div class="col-lg-6 mb-4">
                    <div class="card h-100 shadow-sm border-left-info">
                        <div class="card-header bg-light">
                            <h6 class="mb-0"><?php echo htmlspecialchars($target['title']); ?></h6>
                        </div>
                        <div class="card-body">
                            <div class="mb-3">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <span class="small text-muted">Progress</span>
                                    <strong class="small"><?php echo round($progress); ?>%</strong>
                                </div>
                                <div class="progress" style="height: 8px;">
                                    <div class="progress-bar bg-info" 
                                         role="progressbar" 
                                         style="width: <?php echo $progress; ?>%;" 
                                         aria-valuenow="<?php echo $progress; ?>" 
                                         aria-valuemin="0" 
                                         aria-valuemax="100"></div>
                                </div>
                            </div>
                            <p class="text-muted small"><?php echo htmlspecialchars($target['description'] ?? 'No description'); ?></p>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Completed Targets -->
        <?php if (!empty($completed_targets)): ?>
        <div class="mb-4">
            <h5 class="mb-3">
                <i class="bi bi-check-circle text-success me-2"></i>Completed Targets (<?php echo count($completed_targets); ?>)
            </h5>
            <div class="row">
                <?php foreach ($completed_targets as $target): ?>
                <div class="col-lg-6 mb-4">
                    <div class="card h-100 shadow-sm border-left-success opacity-75">
                        <div class="card-header bg-light">
                            <div class="d-flex justify-content-between align-items-center">
                                <h6 class="mb-0"><i class="bi bi-check-circle text-success me-2"></i><?php echo htmlspecialchars($target['title']); ?></h6>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="mb-3">
                                <div class="progress" style="height: 8px;">
                                    <div class="progress-bar bg-success" 
                                         role="progressbar" 
                                         style="width: 100%;" 
                                         aria-valuenow="100" 
                                         aria-valuemin="0" 
                                         aria-valuemax="100"></div>
                                </div>
                            </div>
                            <div class="row g-2 small">
                                <div class="col-6">
                                    <small class="text-muted d-block">Achieved</small>
                                    <strong><?php echo number_format($target['current_value'] ?? 0, 2); ?> <?php echo htmlspecialchars($target['unit']); ?></strong>
                                </div>
                                <div class="col-6">
                                    <small class="text-muted d-block">Target</small>
                                    <strong><?php echo number_format($target['target_value'], 2); ?> <?php echo htmlspecialchars($target['unit']); ?></strong>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

    <?php endif; ?>
</div>

<script>
// Auto-refresh page every 5 minutes
setInterval(function() {
    if (document.visibilityState === 'visible') {
        location.reload();
    }
}, 300000);
</script>

<?php include 'includes/footer.php'; ?>
