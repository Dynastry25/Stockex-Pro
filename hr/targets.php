<?php
require_once '../config/config.php';
require_once '../auth/auth_middleware.php';

require_hr();
$db = getDBConnection();

$page_title = 'Target Setting & Fulfillment Tracking';
$success_message = '';
$error_message = '';

// Handle target actions
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        if (isset($_POST['add_target'])) {
            // Add new target
            $employee_id = (int)$_POST['employee_id'];
            $target_type = sanitize_input($_POST['target_type']);
            $title = sanitize_input($_POST['title']);
            $description = sanitize_input($_POST['description']);
            $target_value = (float)$_POST['target_value'];
            $unit = sanitize_input($_POST['unit']);
            $start_date = $_POST['start_date'];
            $end_date = $_POST['end_date'];
            $priority = sanitize_input($_POST['priority']);
            
            $stmt = $db->prepare("
                INSERT INTO performance_targets (employee_id, target_type, title, description, 
                                               target_value, unit, start_date, end_date, priority, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            if ($stmt->execute([$employee_id, $target_type, $title, $description,
                              $target_value, $unit, $start_date, $end_date, $priority, $_SESSION['user_id']])) {
                show_alert('Performance target created successfully.', 'success');
                redirect('hr/targets.php');
            } else {
                $error_message = 'Error creating target.';
            }
            
        } elseif (isset($_POST['update_progress'])) {
            // Update target progress
            $target_id = (int)$_POST['target_id'];
            $current_value = (float)$_POST['current_value'];
            $notes = sanitize_input($_POST['notes']);
            
            // Calculate progress percentage
            $target_stmt = $db->prepare("SELECT target_value FROM performance_targets WHERE id = ?");
            $target_stmt->execute([$target_id]);
            $target = $target_stmt->fetch();
            
            if ($target) {
                $progress_percentage = ($current_value / $target['target_value']) * 100;
                $progress_percentage = min($progress_percentage, 100); // Cap at 100%
                
                $stmt = $db->prepare("
                    UPDATE performance_targets 
                    SET current_value = ?, progress_percentage = ?, progress_notes = ?, last_updated = NOW()
                    WHERE id = ?
                ");
                
                if ($stmt->execute([$current_value, $progress_percentage, $notes, $target_id])) {
                    show_alert('Target progress updated successfully.', 'success');
                } else {
                    $error_message = 'Error updating progress.';
                }
            } else {
                $error_message = 'Target not found.';
            }
            redirect('hr/targets.php');
            
        } elseif (isset($_POST['complete_target'])) {
            // Complete target
            $target_id = (int)$_POST['target_id'];
            $completion_notes = sanitize_input($_POST['completion_notes']);
            
            $stmt = $db->prepare("
                UPDATE performance_targets 
                SET status = 'completed', completion_date = NOW(), completion_notes = ?, progress_percentage = 100
                WHERE id = ?
            ");
            
            if ($stmt->execute([$completion_notes, $target_id])) {
                show_alert('Target marked as completed successfully.', 'success');
            } else {
                $error_message = 'Error completing target.';
            }
            redirect('hr/targets.php');
            
        } elseif (isset($_POST['add_review'])) {
            // Add target review
            $target_id = (int)$_POST['target_id'];
            $rating = (int)$_POST['rating'];
            $feedback = sanitize_input($_POST['feedback']);
            $recommendations = sanitize_input($_POST['recommendations']);
            
            $stmt = $db->prepare("
                INSERT INTO target_reviews (target_id, reviewer_id, rating, feedback, recommendations)
                VALUES (?, ?, ?, ?, ?)
            ");
            
            if ($stmt->execute([$target_id, $_SESSION['user_id'], $rating, $feedback, $recommendations])) {
                show_alert('Target review added successfully.', 'success');
            } else {
                $error_message = 'Error adding review.';
            }
            redirect('hr/targets.php');
        }
    } catch (Exception $e) {
        $error_message = 'Database error: ' . $e->getMessage();
    }
}

// Get targets with employee and reviewer information
try {
    $targets_stmt = $db->query("
        SELECT pt.*, 
               CONCAT(e.first_name, ' ', e.last_name) as employee_name,
               jp.title as employee_position,
               d.name as department_name,
               u.full_name as created_by_name,
               AVG(tr.rating) as avg_rating,
               COUNT(tr.id) as review_count
        FROM performance_targets pt
        JOIN employees e ON pt.employee_id = e.id
        JOIN departments d ON e.department_id = d.id
        JOIN job_positions jp ON e.position_id = jp.id
        LEFT JOIN users u ON pt.created_by = u.id
        LEFT JOIN target_reviews tr ON pt.id = tr.target_id
        GROUP BY pt.id
        ORDER BY pt.created_at DESC
    ");
    $targets = $targets_stmt->fetchAll();
} catch (Exception $e) {
    $targets = [];
    $error_message = 'Error loading targets: ' . $e->getMessage();
}

// Get employees for dropdown
try {
    $emp_stmt = $db->query("
        SELECT e.id, CONCAT(e.first_name, ' ', e.last_name) as name, 
               jp.title as position, d.name as department
        FROM employees e
        JOIN departments d ON e.department_id = d.id
        JOIN job_positions jp ON e.position_id = jp.id
        WHERE e.status = 'active'
        ORDER BY e.first_name, e.last_name
    ");
    $employees = $emp_stmt->fetchAll();
} catch (Exception $e) {
    $employees = [];
}

// Get target statistics
try {
    $total_targets = $db->query("SELECT COUNT(*) FROM performance_targets")->fetchColumn();
    $active_targets = $db->query("SELECT COUNT(*) FROM performance_targets WHERE status = 'active'")->fetchColumn();
    $completed_targets = $db->query("SELECT COUNT(*) FROM performance_targets WHERE status = 'completed'")->fetchColumn();
    $overdue_targets = $db->query("SELECT COUNT(*) FROM performance_targets WHERE status = 'active' AND end_date < CURDATE()")->fetchColumn();
    
    // Calculate overall completion rate
    $completion_rate = $total_targets > 0 ? ($completed_targets / $total_targets) * 100 : 0;
    
    // Calculate average progress for active targets
    $avg_progress = $db->query("
        SELECT AVG(progress_percentage) 
        FROM performance_targets 
        WHERE status = 'active'
    ")->fetchColumn() ?: 0;
} catch (Exception $e) {
    $total_targets = $active_targets = $completed_targets = $overdue_targets = 0;
    $completion_rate = $avg_progress = 0;
}

include '../includes/header.php';
?>

<div class="container-fluid pt-4 px-4">
    <div class="d-sm-flex align-items-center justify-content-between mb-4">
        <h1 class="h3 mb-0 text-gray-800">
            <i class="bi bi-bullseye me-2"></i>Target Setting & Fulfillment Tracking
        </h1>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addTargetModal">
            <i class="bi bi-plus-circle me-2"></i>Set New Target
        </button>
    </div>

    <?php if (!empty($error_message)): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <i class="bi bi-exclamation-triangle me-2"></i>
            <?php echo htmlspecialchars($error_message); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Statistics Cards -->
    <div class="row mb-4">
        <div class="col-md-2">
            <div class="card border-left-primary shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">Total Targets</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo $total_targets; ?></div>
                        </div>
                        <div class="col-auto">
                            <i class="bi bi-bullseye fs-2 text-primary"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card border-left-warning shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-warning text-uppercase mb-1">Active Targets</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo $active_targets; ?></div>
                        </div>
                        <div class="col-auto">
                            <i class="bi bi-play-circle fs-2 text-warning"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card border-left-success shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-success text-uppercase mb-1">Completed</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo $completed_targets; ?></div>
                        </div>
                        <div class="col-auto">
                            <i class="bi bi-check-circle fs-2 text-success"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card border-left-danger shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-danger text-uppercase mb-1">Overdue</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo $overdue_targets; ?></div>
                        </div>
                        <div class="col-auto">
                            <i class="bi bi-exclamation-triangle fs-2 text-danger"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card border-left-info shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-info text-uppercase mb-1">Completion Rate</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo round($completion_rate, 1); ?>%</div>
                        </div>
                        <div class="col-auto">
                            <i class="bi bi-graph-up fs-2 text-info"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card border-left-secondary shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-secondary text-uppercase mb-1">Avg Progress</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo round($avg_progress, 1); ?>%</div>
                        </div>
                        <div class="col-auto">
                            <i class="bi bi-speedometer2 fs-2 text-secondary"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <div class="card shadow mb-4">
        <div class="card-body">
            <div class="row align-items-center">
                <div class="col-md-3">
                    <label class="form-label">Filter by Employee:</label>
                    <select class="form-select form-select-sm" id="employeeFilter">
                        <option value="">All Employees</option>
                        <?php foreach ($employees as $emp): ?>
                            <option value="<?php echo $emp['id']; ?>">
                                <?php echo htmlspecialchars($emp['name'] . ' - ' . $emp['position']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Status:</label>
                    <select class="form-select form-select-sm" id="statusFilter">
                        <option value="">All Statuses</option>
                        <option value="active">Active</option>
                        <option value="completed">Completed</option>
                        <option value="cancelled">Cancelled</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Priority:</label>
                    <select class="form-select form-select-sm" id="priorityFilter">
                        <option value="">All Priorities</option>
                        <option value="high">High</option>
                        <option value="medium">Medium</option>
                        <option value="low">Low</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Search:</label>
                    <input type="text" class="form-control form-control-sm" id="searchFilter" 
                           placeholder="Search targets...">
                </div>
                <div class="col-md-2">
                    <label class="form-label">&nbsp;</label>
                    <button class="btn btn-outline-secondary btn-sm w-100" onclick="clearFilters()">
                        <i class="bi bi-arrow-clockwise me-1"></i>Clear Filters
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Targets List -->
    <div class="card shadow mb-4">
        <div class="card-header py-3">
            <h6 class="m-0 font-weight-bold text-primary">Performance Targets</h6>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-bordered table-hover" id="targetsTable">
                    <thead class="table-light">
                        <tr>
                            <th>Target</th>
                            <th>Employee</th>
                            <th>Type & Priority</th>
                            <th>Timeline</th>
                            <th>Progress</th>
                            <th>Status</th>
                            <th>Rating</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($targets)): ?>
                            <tr>
                                <td colspan="8" class="text-center text-muted py-4">
                                    <i class="bi bi-bullseye display-4"></i>
                                    <p class="mt-2 mb-0">No performance targets found. Set your first target to start tracking performance.</p>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($targets as $target): ?>
                                <tr data-employee="<?php echo $target['employee_id']; ?>" 
                                    data-status="<?php echo $target['status']; ?>" 
                                    data-priority="<?php echo $target['priority']; ?>">
                                    <td>
                                        <strong><?php echo htmlspecialchars($target['title']); ?></strong>
                                        <?php if (!empty($target['description'])): ?>
                                            <br><small class="text-muted">
                                                <?php echo strlen($target['description']) > 100 ? 
                                                    substr(htmlspecialchars($target['description']), 0, 100) . '...' : 
                                                    htmlspecialchars($target['description']); ?>
                                            </small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($target['employee_name']); ?></strong>
                                        <br><small class="text-muted"><?php echo htmlspecialchars($target['employee_position']); ?></small>
                                        <br><small class="text-muted"><?php echo htmlspecialchars($target['department_name']); ?></small>
                                    </td>
                                    <td>
                                        <span class="badge bg-info mb-1"><?php echo ucfirst($target['target_type']); ?></span>
                                        <br>
                                        <?php
                                        $priority_colors = ['high' => 'danger', 'medium' => 'warning', 'low' => 'success'];
                                        $color = $priority_colors[$target['priority']] ?? 'secondary';
                                        ?>
                                        <span class="badge bg-<?php echo $color; ?>"><?php echo ucfirst($target['priority']); ?> Priority</span>
                                    </td>
                                    <td>
                                        <small>
                                            <strong>Start:</strong> <?php echo format_date($target['start_date']); ?><br>
                                            <strong>End:</strong> <?php echo format_date($target['end_date']); ?>
                                            <?php if ($target['status'] == 'active' && strtotime($target['end_date']) < time()): ?>
                                                <br><span class="text-danger"><i class="bi bi-exclamation-triangle"></i> Overdue</span>
                                            <?php endif; ?>
                                        </small>
                                    </td>
                                    <td>
                                        <div class="mb-1">
                                            <small><?php echo number_format($target['current_value'], 1); ?> / <?php echo number_format($target['target_value'], 1); ?> <?php echo htmlspecialchars($target['unit']); ?></small>
                                        </div>
                                        <div class="progress" style="height: 20px;">
                                            <?php
                                            $progress = $target['progress_percentage'];
                                            $progress_color = 'bg-primary';
                                            if ($progress >= 100) $progress_color = 'bg-success';
                                            elseif ($progress >= 75) $progress_color = 'bg-info';
                                            elseif ($progress >= 50) $progress_color = 'bg-warning';
                                            elseif ($progress >= 25) $progress_color = 'bg-orange';
                                            else $progress_color = 'bg-danger';
                                            ?>
                                            <div class="progress-bar <?php echo $progress_color; ?>" 
                                                 style="width: <?php echo $progress; ?>%">
                                                <?php echo round($progress, 1); ?>%
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <?php
                                        $status_colors = ['active' => 'warning', 'completed' => 'success', 'cancelled' => 'danger'];
                                        $status_color = $status_colors[$target['status']] ?? 'secondary';
                                        ?>
                                        <span class="badge bg-<?php echo $status_color; ?>">
                                            <?php echo ucfirst($target['status']); ?>
                                        </span>
                                        <?php if ($target['completion_date']): ?>
                                            <br><small class="text-muted"><?php echo format_date($target['completion_date']); ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($target['avg_rating']): ?>
                                            <div class="mb-1">
                                                <?php 
                                                $rating = round($target['avg_rating'], 1);
                                                $stars = '';
                                                for ($i = 1; $i <= 5; $i++) {
                                                    $stars .= $i <= $rating ? '<i class="bi bi-star-fill text-warning"></i>' : '<i class="bi bi-star text-muted"></i>';
                                                }
                                                echo $stars;
                                                ?>
                                            </div>
                                            <small><?php echo $rating; ?>/5 (<?php echo $target['review_count']; ?> reviews)</small>
                                        <?php else: ?>
                                            <span class="text-muted">No reviews</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="btn-group btn-group-sm">
                                            <button class="btn btn-outline-primary" 
                                                    onclick="viewTargetDetails(<?php echo json_encode($target); ?>)"
                                                    title="View Details">
                                                <i class="bi bi-eye"></i>
                                            </button>
                                            <?php if ($target['status'] == 'active'): ?>
                                                <button class="btn btn-outline-success" 
                                                        onclick="updateProgress(<?php echo $target['id']; ?>, '<?php echo htmlspecialchars($target['title']); ?>', <?php echo $target['current_value']; ?>, <?php echo $target['target_value']; ?>, '<?php echo $target['unit']; ?>')"
                                                        title="Update Progress">
                                                    <i class="bi bi-pencil"></i>
                                                </button>
                                                <button class="btn btn-outline-warning" 
                                                        onclick="completeTarget(<?php echo $target['id']; ?>, '<?php echo htmlspecialchars($target['title']); ?>')"
                                                        title="Mark Complete">
                                                    <i class="bi bi-check-lg"></i>
                                                </button>
                                            <?php endif; ?>
                                            <button class="btn btn-outline-info" 
                                                    onclick="addReview(<?php echo $target['id']; ?>, '<?php echo htmlspecialchars($target['title']); ?>')"
                                                    title="Add Review">
                                                <i class="bi bi-star"></i>
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

<!-- Add Target Modal -->
<div class="modal fade" id="addTargetModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" action="">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="bi bi-plus-circle me-2"></i>Set New Performance Target
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Employee <span class="text-danger">*</span></label>
                            <select name="employee_id" class="form-select" required>
                                <option value="">Select Employee</option>
                                <?php foreach ($employees as $emp): ?>
                                    <option value="<?php echo $emp['id']; ?>">
                                        <?php echo htmlspecialchars($emp['name'] . ' - ' . $emp['position']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Target Type <span class="text-danger">*</span></label>
                            <select name="target_type" class="form-select" required>
                                <option value="">Select Type</option>
                                <option value="sales">Sales</option>
                                <option value="quality">Quality</option>
                                <option value="productivity">Productivity</option>
                                <option value="customer_service">Customer Service</option>
                                <option value="training">Training & Development</option>
                                <option value="cost_reduction">Cost Reduction</option>
                                <option value="project">Project Delivery</option>
                                <option value="other">Other</option>
                            </select>
                        </div>
                        <div class="col-12 mb-3">
                            <label class="form-label">Target Title <span class="text-danger">*</span></label>
                            <input type="text" name="title" class="form-control" required 
                                   placeholder="e.g., Increase monthly sales by 15%">
                        </div>
                        <div class="col-12 mb-3">
                            <label class="form-label">Description</label>
                            <textarea name="description" class="form-control" rows="3" 
                                      placeholder="Detailed description of the target and expectations..."></textarea>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Target Value <span class="text-danger">*</span></label>
                            <input type="number" name="target_value" class="form-control" step="0.01" required>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Unit <span class="text-danger">*</span></label>
                            <input type="text" name="unit" class="form-control" required 
                                   placeholder="e.g., TZS, Units, %">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Priority <span class="text-danger">*</span></label>
                            <select name="priority" class="form-select" required>
                                <option value="medium">Medium</option>
                                <option value="high">High</option>
                                <option value="low">Low</option>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Start Date <span class="text-danger">*</span></label>
                            <input type="date" name="start_date" class="form-control" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">End Date <span class="text-danger">*</span></label>
                            <input type="date" name="end_date" class="form-control" required>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="add_target" class="btn btn-primary">
                        <i class="bi bi-plus-circle me-1"></i>Set Target
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Update Progress Modal -->
<div class="modal fade" id="updateProgressModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="" onsubmit="return validateProgressForm()">
                <input type="hidden" name="target_id" id="progressTargetId">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="bi bi-pencil me-2"></i>Update Target Progress
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle me-2"></i>
                        Update progress for: <strong id="progressTargetTitle"></strong>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Current Achievement <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <input type="number" name="current_value" id="progressCurrentValue" 
                                   class="form-control" step="0.01" required>
                            <span class="input-group-text" id="progressUnit"></span>
                        </div>
                        <div class="form-text">
                            Target: <span id="progressTargetValue"></span> <span id="progressTargetUnit"></span>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Progress Notes</label>
                        <textarea name="notes" class="form-control" rows="3" 
                                  placeholder="Add notes about progress, challenges, or achievements..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="update_progress" class="btn btn-primary">
                        <i class="bi bi-check-lg me-1"></i>Update Progress
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Complete Target Modal -->
<div class="modal fade" id="completeTargetModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="" onsubmit="return validateCompleteForm()">
                <input type="hidden" name="target_id" id="completeTargetId">
                <div class="modal-header">
                    <h5 class="modal-title text-success">
                        <i class="bi bi-check-lg me-2"></i>Mark Target as Complete
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-success">
                        <i class="bi bi-check-circle me-2"></i>
                        Mark target as completed: <strong id="completeTargetTitle"></strong>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Completion Notes</label>
                        <textarea name="completion_notes" class="form-control" rows="4" 
                                  placeholder="Add final notes about the target completion, achievements, and lessons learned..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="complete_target" class="btn btn-success">
                        <i class="bi bi-check-lg me-1"></i>Mark Complete
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Add Review Modal -->
<div class="modal fade" id="addReviewModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="" onsubmit="return validateReviewForm()">
                <input type="hidden" name="target_id" id="reviewTargetId">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="bi bi-star me-2"></i>Add Target Review
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle me-2"></i>
                        Add review for: <strong id="reviewTargetTitle"></strong>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Rating <span class="text-danger">*</span></label>
                        <select name="rating" class="form-select" required>
                            <option value="">Select Rating</option>
                            <option value="5">5 Stars - Excellent Performance</option>
                            <option value="4">4 Stars - Good Performance</option>
                            <option value="3">3 Stars - Satisfactory Performance</option>
                            <option value="2">2 Stars - Below Expectations</option>
                            <option value="1">1 Star - Poor Performance</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Feedback <span class="text-danger">*</span></label>
                        <textarea name="feedback" class="form-control" rows="4" required
                                  placeholder="Provide detailed feedback about the target performance..."></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Recommendations</label>
                        <textarea name="recommendations" class="form-control" rows="3" 
                                  placeholder="Suggestions for improvement or future targets..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="add_review" class="btn btn-primary">
                        <i class="bi bi-star me-1"></i>Add Review
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Target Details Modal -->
<div class="modal fade" id="targetDetailsModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="bi bi-eye me-2"></i>Target Details
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="targetDetailsContent">
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script>
// Update progress function
function updateProgress(targetId, title, currentValue, targetValue, unit) {
    document.getElementById('progressTargetId').value = targetId;
    document.getElementById('progressTargetTitle').textContent = title;
    document.getElementById('progressCurrentValue').value = currentValue;
    document.getElementById('progressTargetValue').textContent = targetValue;
    document.getElementById('progressTargetUnit').textContent = unit;
    document.getElementById('progressUnit').textContent = unit;
    
    new bootstrap.Modal(document.getElementById('updateProgressModal')).show();
}

// Complete target function
function completeTarget(targetId, title) {
    document.getElementById('completeTargetId').value = targetId;
    document.getElementById('completeTargetTitle').textContent = title;
    
    new bootstrap.Modal(document.getElementById('completeTargetModal')).show();
}

// Add review function
function addReview(targetId, title) {
    document.getElementById('reviewTargetId').value = targetId;
    document.getElementById('reviewTargetTitle').textContent = title;
    
    new bootstrap.Modal(document.getElementById('addReviewModal')).show();
}

// Validation functions
function validateProgressForm() {
    const currentValue = document.getElementById('progressCurrentValue').value;
    const targetValue = parseFloat(document.getElementById('progressTargetValue').textContent);
    
    if (!currentValue || isNaN(currentValue) || parseFloat(currentValue) < 0) {
        alert('Please enter a valid current achievement value.');
        return false;
    }
    
    if (parseFloat(currentValue) > targetValue * 2) {
        return confirm('The current value seems unusually high. Are you sure?');
    }
    
    return true;
}

function validateCompleteForm() {
    const notes = document.querySelector('#completeTargetModal textarea[name="completion_notes"]').value.trim();
    
    if (!notes) {
        alert('Please provide completion notes.');
        return false;
    }
    
    return confirm('Are you sure you want to mark this target as completed?');
}

function validateReviewForm() {
    const rating = document.querySelector('#addReviewModal select[name="rating"]').value;
    const feedback = document.querySelector('#addReviewModal textarea[name="feedback"]').value.trim();
    
    if (!rating) {
        alert('Please select a rating.');
        return false;
    }
    
    if (!feedback) {
        alert('Please provide feedback.');
        return false;
    }
    
    return true;
}

// View target details function
function viewTargetDetails(target) {
    const content = `
        <div class="row">
            <div class="col-md-6">
                <h6 class="text-primary">Target Information</h6>
                <p><strong>Title:</strong> ${target.title}</p>
                <p><strong>Type:</strong> ${target.target_type.replace('_', ' ').replace(/\b\w/g, l => l.toUpperCase())}</p>
                <p><strong>Employee:</strong> ${target.employee_name}</p>
                <p><strong>Position:</strong> ${target.employee_position}</p>
                <p><strong>Department:</strong> ${target.department_name}</p>
                <p><strong>Created By:</strong> ${target.created_by_name || 'N/A'}</p>
            </div>
            <div class="col-md-6">
                <h6 class="text-primary">Target Metrics</h6>
                <p><strong>Target Value:</strong> ${parseFloat(target.target_value).toLocaleString()} ${target.unit}</p>
                <p><strong>Current Value:</strong> ${parseFloat(target.current_value).toLocaleString()} ${target.unit}</p>
                <p><strong>Progress:</strong> ${parseFloat(target.progress_percentage).toFixed(1)}%</p>
                <p><strong>Priority:</strong> <span class="badge bg-${target.priority === 'high' ? 'danger' : (target.priority === 'medium' ? 'warning' : 'success')}">${target.priority.charAt(0).toUpperCase() + target.priority.slice(1)}</span></p>
                <p><strong>Status:</strong> <span class="badge bg-${target.status === 'active' ? 'warning' : (target.status === 'completed' ? 'success' : 'danger')}">${target.status.charAt(0).toUpperCase() + target.status.slice(1)}</span></p>
                <p><strong>Average Rating:</strong> ${target.avg_rating ? parseFloat(target.avg_rating).toFixed(1) + '/5' : 'No ratings yet'}</p>
            </div>
        </div>
        <div class="row">
            <div class="col-md-6">
                <h6 class="text-primary">Timeline</h6>
                <p><strong>Start Date:</strong> ${new Date(target.start_date).toLocaleDateString()}</p>
                <p><strong>End Date:</strong> ${new Date(target.end_date).toLocaleDateString()}</p>
                ${target.completion_date ? `<p><strong>Completed On:</strong> ${new Date(target.completion_date).toLocaleDateString()}</p>` : ''}
                <p><strong>Last Updated:</strong> ${target.last_updated ? new Date(target.last_updated).toLocaleDateString() : 'Never'}</p>
            </div>
            <div class="col-md-6">
                <h6 class="text-primary">Progress Visualization</h6>
                <div class="progress mb-2" style="height: 25px;">
                    <div class="progress-bar bg-${target.progress_percentage >= 100 ? 'success' : (target.progress_percentage >= 75 ? 'info' : (target.progress_percentage >= 50 ? 'warning' : 'danger'))}" 
                         style="width: ${target.progress_percentage}%">
                        ${parseFloat(target.progress_percentage).toFixed(1)}%
                    </div>
                </div>
            </div>
        </div>
        <hr>
        <h6 class="text-primary">Description</h6>
        <p class="mb-3">${target.description || 'No description provided'}</p>
        
        ${target.progress_notes ? `<h6 class="text-primary">Latest Progress Notes</h6><div class="alert alert-info">${target.progress_notes}</div>` : ''}
        
        ${target.completion_notes ? `<h6 class="text-primary">Completion Notes</h6><div class="alert alert-success">${target.completion_notes}</div>` : ''}
    `;
    
    document.getElementById('targetDetailsContent').innerHTML = content;
    new bootstrap.Modal(document.getElementById('targetDetailsModal')).show();
}

// Filter functions
function clearFilters() {
    document.getElementById('employeeFilter').value = '';
    document.getElementById('statusFilter').value = '';
    document.getElementById('priorityFilter').value = '';
    document.getElementById('searchFilter').value = '';
    filterTable();
}

function filterTable() {
    const employeeFilter = document.getElementById('employeeFilter').value;
    const statusFilter = document.getElementById('statusFilter').value;
    const priorityFilter = document.getElementById('priorityFilter').value;
    const searchFilter = document.getElementById('searchFilter').value.toLowerCase();
    
    const table = document.getElementById('targetsTable');
    const rows = table.getElementsByTagName('tbody')[0].getElementsByTagName('tr');
    
    for (let i = 0; i < rows.length; i++) {
        const row = rows[i];
        const employee = row.getAttribute('data-employee');
        const status = row.getAttribute('data-status');
        const priority = row.getAttribute('data-priority');
        const text = row.textContent.toLowerCase();
        
        let show = true;
        
        if (employeeFilter && employee !== employeeFilter) show = false;
        if (statusFilter && status !== statusFilter) show = false;
        if (priorityFilter && priority !== priorityFilter) show = false;
        if (searchFilter && !text.includes(searchFilter)) show = false;
        
        row.style.display = show ? '' : 'none';
    }
}

// Add event listeners for filters
document.getElementById('employeeFilter').addEventListener('change', filterTable);
document.getElementById('statusFilter').addEventListener('change', filterTable);
document.getElementById('priorityFilter').addEventListener('change', filterTable);
document.getElementById('searchFilter').addEventListener('keyup', filterTable);

// Set default dates for new targets
document.addEventListener('DOMContentLoaded', function() {
    const today = new Date().toISOString().split('T')[0];
    const endDate = new Date();
    endDate.setMonth(endDate.getMonth() + 3); // Default to 3 months from now
    
    document.querySelector('input[name="start_date"]').value = today;
    document.querySelector('input[name="end_date"]').value = endDate.toISOString().split('T')[0];
});
</script>

<?php include '../includes/footer.php'; ?>