<?php
require_once '../config/config.php';
require_once '../auth/auth_middleware.php';

require_hr();
$db = getDBConnection();

$page_title = 'Recruitment Management';
$success_message = '';
$error_message = '';

// Handle recruitment actions
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        if (isset($_POST['add_job_position'])) {
            // Add new job position
            $title = sanitize_input($_POST['title']);
            $department_id = (int)$_POST['department_id'];
            $description = sanitize_input($_POST['description']);
            $requirements = sanitize_input($_POST['requirements']);
            $salary_min = (float)$_POST['salary_min'];
            $salary_max = (float)$_POST['salary_max'];
            $employment_type = sanitize_input($_POST['employment_type']);
            
            $stmt = $db->prepare("
                INSERT INTO job_positions (title, department_id, description, requirements, 
                                         salary_min, salary_max, employment_type, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            if ($stmt->execute([$title, $department_id, $description, $requirements,
                              $salary_min, $salary_max, $employment_type, $_SESSION['user_id']])) {
                show_alert('Job position created successfully.', 'success');
                redirect('hr/recruitment.php');
            } else {
                $error_message = 'Error creating job position.';
            }
            
        } elseif (isset($_POST['update_application_status'])) {
            // Update application status
            $application_id = (int)$_POST['application_id'];
            $status = sanitize_input($_POST['status']);
            $interview_date = $_POST['interview_date'] ?: null;
            $interview_notes = sanitize_input($_POST['interview_notes']);
            $rejection_reason = sanitize_input($_POST['rejection_reason']);
            
            if ($status == 'interviewed') {
                $stmt = $db->prepare("
                    UPDATE job_applications 
                    SET status = ?, interview_date = ?, interview_notes = ?, interviewer_id = ?
                    WHERE id = ?
                ");
                $stmt->execute([$status, $interview_date, $interview_notes, $_SESSION['user_id'], $application_id]);
            } elseif ($status == 'rejected') {
                $stmt = $db->prepare("
                    UPDATE job_applications 
                    SET status = ?, rejection_reason = ?
                    WHERE id = ?
                ");
                $stmt->execute([$status, $rejection_reason, $application_id]);
            } else {
                $stmt = $db->prepare("
                    UPDATE job_applications 
                    SET status = ?
                    WHERE id = ?
                ");
                $stmt->execute([$status, $application_id]);
            }
            
            show_alert('Application status updated successfully.', 'success');
            redirect('hr/recruitment.php');
            
        } elseif (isset($_POST['toggle_position'])) {
            // Toggle position status (open/close)
            $position_id = (int)$_POST['position_id'];
            $new_status = $_POST['new_status'];
            
            if (in_array($new_status, ['open', 'closed'])) {
                $stmt = $db->prepare("UPDATE job_positions SET status = ? WHERE id = ?");
                if ($stmt->execute([$new_status, $position_id])) {
                    $action = $new_status === 'open' ? 'opened' : 'closed';
                    show_alert("Job position $action successfully.", 'success');
                } else {
                    $error_message = 'Error updating job position status.';
                }
            } else {
                $error_message = 'Invalid status provided.';
            }
            redirect('hr/recruitment.php');
        }
    } catch (Exception $e) {
        $error_message = 'Database error: ' . $e->getMessage();
    }
}

// Get job positions with application counts
try {
    $positions_stmt = $db->query("
        SELECT jp.*, d.name as department_name,
               COUNT(ja.id) as application_count,
               SUM(CASE WHEN ja.status = 'received' THEN 1 ELSE 0 END) as new_applications,
               COALESCE(CONCAT(e.first_name, ' ', e.last_name), u.full_name) as created_by_name
        FROM job_positions jp
        JOIN departments d ON jp.department_id = d.id
        LEFT JOIN job_applications ja ON jp.id = ja.position_id
        LEFT JOIN users u ON jp.created_by = u.id
        LEFT JOIN employees e ON u.id = e.user_id
        GROUP BY jp.id
        ORDER BY jp.created_at DESC
    ");
    $job_positions = $positions_stmt->fetchAll();
} catch (Exception $e) {
    $job_positions = [];
    $error_message = 'Error loading job positions: ' . $e->getMessage();
}

// Get recent applications
try {
    $applications_stmt = $db->query("
        SELECT ja.*, jp.title as position_title, d.name as department_name,
               COALESCE(CONCAT(e.first_name, ' ', e.last_name), u.full_name) as interviewer_name
        FROM job_applications ja
        JOIN job_positions jp ON ja.position_id = jp.id
        JOIN departments d ON jp.department_id = d.id
        LEFT JOIN users u ON ja.interviewer_id = u.id
        LEFT JOIN employees e ON u.id = e.user_id
        ORDER BY ja.created_at DESC
        LIMIT 50
    ");
    $applications = $applications_stmt->fetchAll();
} catch (Exception $e) {
    $applications = [];
}

// Get departments for dropdown
try {
    $dept_stmt = $db->query("SELECT id, name FROM departments WHERE status = 'active' ORDER BY name");
    $departments = $dept_stmt->fetchAll();
} catch (Exception $e) {
    $departments = [];
}

// Get recruitment statistics
try {
    $total_positions = $db->query("SELECT COUNT(*) FROM job_positions")->fetchColumn();
    $open_positions = $db->query("SELECT COUNT(*) FROM job_positions WHERE status = 'open'")->fetchColumn();
    $total_applications = $db->query("SELECT COUNT(*) FROM job_applications")->fetchColumn();
    $pending_applications = $db->query("SELECT COUNT(*) FROM job_applications WHERE status IN ('received', 'reviewing')")->fetchColumn();
} catch (Exception $e) {
    $total_positions = $open_positions = $total_applications = $pending_applications = 0;
}

include '../includes/header.php';
?>

<div class="container-fluid pt-4 px-4">
    <div class="d-sm-flex align-items-center justify-content-between mb-4">
        <h1 class="h3 mb-0 text-gray-800">
            <i class="bi bi-person-badge me-2"></i>Recruitment Management
        </h1>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addJobModal">
            <i class="bi bi-plus-circle me-2"></i>Add Job Position
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
        <div class="col-md-3">
            <div class="card border-left-primary shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">Total Positions</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo $total_positions; ?></div>
                        </div>
                        <div class="col-auto">
                            <i class="bi bi-briefcase fs-2 text-primary"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-left-success shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-success text-uppercase mb-1">Open Positions</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo $open_positions; ?></div>
                        </div>
                        <div class="col-auto">
                            <i class="bi bi-door-open fs-2 text-success"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-left-info shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-info text-uppercase mb-1">Total Applications</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo $total_applications; ?></div>
                        </div>
                        <div class="col-auto">
                            <i class="bi bi-file-person fs-2 text-info"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-left-warning shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-warning text-uppercase mb-1">Pending Review</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo $pending_applications; ?></div>
                        </div>
                        <div class="col-auto">
                            <i class="bi bi-clock-history fs-2 text-warning"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Job Positions Section -->
    <div class="card shadow mb-4">
        <div class="card-header py-3">
            <h6 class="m-0 font-weight-bold text-primary">Job Positions</h6>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-bordered table-hover" id="jobPositionsTable">
                    <thead class="table-light">
                        <tr>
                            <th>Position Title</th>
                            <th>Department</th>
                            <th>Employment Type</th>
                            <th>Salary Range</th>
                            <th>Applications</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($job_positions)): ?>
                            <tr>
                                <td colspan="7" class="text-center text-muted py-4">
                                    <i class="bi bi-briefcase display-4"></i>
                                    <p class="mt-2 mb-0">No job positions found. Create your first position to start recruiting.</p>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($job_positions as $position): ?>
                                <tr>
                                    <td>
                                        <strong><?php echo htmlspecialchars($position['title']); ?></strong>
                                        <?php if (!empty($position['description'])): ?>
                                            <br><small class="text-muted">
                                                <?php echo strlen($position['description']) > 100 ? 
                                                    substr(htmlspecialchars($position['description']), 0, 100) . '...' : 
                                                    htmlspecialchars($position['description']); ?>
                                            </small>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($position['department_name']); ?></td>
                                    <td>
                                        <span class="badge bg-secondary">
                                            <?php echo ucwords(str_replace('_', ' ', $position['employment_type'])); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($position['salary_min'] > 0 || $position['salary_max'] > 0): ?>
                                            TZS <?php echo number_format($position['salary_min'], 0); ?> - 
                                            TZS <?php echo number_format($position['salary_max'], 0); ?>
                                        <?php else: ?>
                                            <span class="text-muted">Not specified</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge bg-info"><?php echo $position['application_count']; ?> Total</span>
                                        <?php if ($position['new_applications'] > 0): ?>
                                            <br><span class="badge bg-warning"><?php echo $position['new_applications']; ?> New</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php
                                        $status_colors = [
                                            'open' => 'success',
                                            'closed' => 'danger',
                                            'on_hold' => 'warning'
                                        ];
                                        $color = $status_colors[$position['status']] ?? 'secondary';
                                        ?>
                                        <span class="badge bg-<?php echo $color; ?>">
                                            <?php echo ucwords(str_replace('_', ' ', $position['status'])); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="btn-group btn-group-sm">
                                            <button class="btn btn-outline-primary" 
                                                    data-job='<?php echo json_encode($position); ?>'
                                                    onclick="viewJobDetails(this)"
                                                    title="View Details">
                                                <i class="bi bi-eye"></i>
                                            </button>
                                            <button class="btn btn-outline-info" 
                                                    onclick="viewApplications(<?php echo $position['id']; ?>, '<?php echo htmlspecialchars($position['title']); ?>')"
                                                    title="View Applications">
                                                <i class="bi bi-file-person"></i>
                                            </button>
                                            <button class="btn btn-outline-secondary" 
                                                    onclick="shareJobLink('<?php echo htmlspecialchars($position['title']); ?>')"
                                                    title="Share Job Link">
                                                <i class="bi bi-share"></i>
                                            </button>
                                            <button class="btn btn-outline-<?php echo $position['status'] == 'open' ? 'warning' : 'success'; ?>" 
                                                    onclick="togglePositionStatus(<?php echo $position['id']; ?>, '<?php echo htmlspecialchars($position['title']); ?>', '<?php echo $position['status']; ?>')"
                                                    title="<?php echo $position['status'] == 'open' ? 'Close Position' : 'Open Position'; ?>">
                                                <i class="bi bi-<?php echo $position['status'] == 'open' ? 'door-closed' : 'door-open'; ?>"></i>
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

    <!-- Recent Applications Section -->
    <div class="card shadow mb-4">
        <div class="card-header py-3 d-flex justify-content-between align-items-center">
            <h6 class="m-0 font-weight-bold text-primary">Recent Applications</h6>
            <input type="text" class="form-control form-control-sm" id="applicationsSearch" 
                   placeholder="Search applications..." style="width: 200px;">
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-bordered table-hover" id="applicationsTable">
                    <thead class="table-light">
                        <tr>
                            <th>Applicant</th>
                            <th>Position</th>
                            <th>Applied Date</th>
                            <th>Status</th>
                            <th>Interview</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($applications)): ?>
                            <tr>
                                <td colspan="6" class="text-center text-muted py-4">
                                    <i class="bi bi-file-person display-4"></i>
                                    <p class="mt-2 mb-0">No applications received yet.</p>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($applications as $app): ?>
                                <tr>
                                    <td>
                                        <div>
                                            <strong><?php echo htmlspecialchars($app['applicant_name']); ?></strong>
                                            <br><small class="text-muted"><?php echo htmlspecialchars($app['email']); ?></small>
                                            <?php if (!empty($app['phone'])): ?>
                                                <br><small class="text-muted"><?php echo htmlspecialchars($app['phone']); ?></small>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($app['position_title']); ?></strong>
                                        <br><small class="text-muted"><?php echo htmlspecialchars($app['department_name']); ?></small>
                                    </td>
                                    <td><?php echo format_date($app['created_at']); ?></td>
                                    <td>
                                        <?php
                                        $status_colors = [
                                            'received' => 'primary',
                                            'reviewing' => 'info',
                                            'shortlisted' => 'warning',
                                            'interviewed' => 'secondary',
                                            'selected' => 'success',
                                            'rejected' => 'danger',
                                            'hired' => 'success'
                                        ];
                                        $color = $status_colors[$app['status']] ?? 'secondary';
                                        ?>
                                        <span class="badge bg-<?php echo $color; ?>">
                                            <?php echo ucfirst($app['status']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if (!empty($app['interview_date'])): ?>
                                            <small>
                                                <?php echo date('d/m/Y H:i', strtotime($app['interview_date'])); ?>
                                                <?php if (!empty($app['interviewer_name'])): ?>
                                                    <br>by <?php echo htmlspecialchars($app['interviewer_name']); ?>
                                                <?php endif; ?>
                                            </small>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="btn-group btn-group-sm">
                                            <button class="btn btn-outline-primary" 
                                                    data-app='<?php echo json_encode($app); ?>'
                                                    onclick="viewApplicationDetails(this)"
                                                    title="View Details">
                                                <i class="bi bi-eye"></i>
                                            </button>
                                            <?php if (!in_array($app['status'], ['hired', 'rejected'])): ?>
                                                <button class="btn btn-outline-success" 
                                                        onclick="updateApplicationStatus(<?php echo $app['id']; ?>, '<?php echo htmlspecialchars($app['applicant_name']); ?>')"
                                                        title="Update Status">
                                                    <i class="bi bi-pencil"></i>
                                                </button>
                                            <?php endif; ?>
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

<!-- Add Job Position Modal -->
<div class="modal fade" id="addJobModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" action="" onsubmit="return validateAddJobForm()">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="bi bi-plus-circle me-2"></i>Add Job Position
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-8 mb-3">
                            <label class="form-label">Position Title <span class="text-danger">*</span></label>
                            <input type="text" name="title" class="form-control" required 
                                   placeholder="e.g., Senior Software Developer">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Department <span class="text-danger">*</span></label>
                            <select name="department_id" class="form-select" required>
                                <option value="">Select Department</option>
                                <?php foreach ($departments as $dept): ?>
                                    <option value="<?php echo $dept['id']; ?>">
                                        <?php echo htmlspecialchars($dept['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Employment Type</label>
                            <select name="employment_type" class="form-select">
                                <option value="full_time">Full Time</option>
                                <option value="part_time">Part Time</option>
                                <option value="contract">Contract</option>
                                <option value="internship">Internship</option>
                            </select>
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label">Min Salary (TZS)</label>
                            <input type="number" name="salary_min" class="form-control" step="1000" min="0">
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label">Max Salary (TZS)</label>
                            <input type="number" name="salary_max" class="form-control" step="1000" min="0">
                        </div>
                        <div class="col-12 mb-3">
                            <label class="form-label">Job Description</label>
                            <textarea name="description" class="form-control" rows="4" 
                                      placeholder="Describe the role, responsibilities, and company culture..."></textarea>
                        </div>
                        <div class="col-12 mb-3">
                            <label class="form-label">Requirements</label>
                            <textarea name="requirements" class="form-control" rows="3" 
                                      placeholder="List education, experience, skills, and other requirements..."></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="add_job_position" class="btn btn-primary">
                        <i class="bi bi-plus-circle me-1"></i>Create Position
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Update Application Status Modal -->
<div class="modal fade" id="updateStatusModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="" onsubmit="return validateStatusForm()">
                <input type="hidden" name="application_id" id="statusApplicationId">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="bi bi-pencil me-2"></i>Update Application Status
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle me-2"></i>
                        Update status for <strong id="statusApplicantName"></strong>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Status <span class="text-danger">*</span></label>
                        <select name="status" class="form-select" required id="applicationStatus">
                            <option value="received">Received</option>
                            <option value="reviewing">Under Review</option>
                            <option value="shortlisted">Shortlisted</option>
                            <option value="interviewed">Interviewed</option>
                            <option value="selected">Selected</option>
                            <option value="rejected">Rejected</option>
                            <option value="hired">Hired</option>
                        </select>
                    </div>
                    <div class="mb-3" id="interviewSection" style="display: none;">
                        <label class="form-label">Interview Date & Time</label>
                        <input type="datetime-local" name="interview_date" class="form-control">
                    </div>
                    <div class="mb-3" id="interviewNotesSection" style="display: none;">
                        <label class="form-label">Interview Notes</label>
                        <textarea name="interview_notes" class="form-control" rows="3" 
                                  placeholder="Notes from the interview..."></textarea>
                    </div>
                    <div class="mb-3" id="rejectionSection" style="display: none;">
                        <label class="form-label">Reason for Rejection</label>
                        <textarea name="rejection_reason" class="form-control" rows="2" 
                                  placeholder="Provide reason for rejection..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="update_application_status" class="btn btn-primary">
                        <i class="bi bi-check-lg me-1"></i>Update Status
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Job Details Modal -->
<div class="modal fade" id="jobDetailsModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="bi bi-eye me-2"></i>Job Position Details
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="jobDetailsContent">
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Application Details Modal -->
<div class="modal fade" id="applicationDetailsModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="bi bi-file-person me-2"></i>Application Details
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="applicationDetailsContent">
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Toggle Position Status Modal -->
<div class="modal fade" id="togglePositionModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="" onsubmit="return validateTogglePositionForm()">
                <input type="hidden" name="position_id" id="togglePositionId">
                <input type="hidden" name="new_status" id="toggleNewStatus">
                <div class="modal-header">
                    <h5 class="modal-title" id="toggleModalTitle">
                        <i class="bi bi-door-closed me-2"></i>Toggle Position Status
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-warning">
                        <i class="bi bi-exclamation-triangle me-2"></i>
                        Are you sure you want to <span id="toggleActionText"></span> the position "<strong id="togglePositionTitle"></strong>"?
                        <br><small id="toggleActionDescription"></small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="toggle_position" class="btn" id="toggleSubmitBtn">
                        <i class="bi bi-check-lg me-1"></i><span id="toggleSubmitText">Confirm</span>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Position Applications Modal -->
<div class="modal fade" id="positionApplicationsModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="bi bi-file-person me-2"></i>Applications for <span id="positionTitle"></span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="positionApplicationsContent">
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script>
const applications = <?php echo json_encode($applications); ?>;
</script>

<script>
// Form validation functions
function validateAddJobForm() {
    const title = document.querySelector('#addJobModal input[name="title"]').value.trim();
    const departmentId = document.querySelector('#addJobModal select[name="department_id"]').value;
    const salaryMin = parseFloat(document.querySelector('#addJobModal input[name="salary_min"]').value) || 0;
    const salaryMax = parseFloat(document.querySelector('#addJobModal input[name="salary_max"]').value) || 0;
    
    if (!title) {
        alert('Please enter a job title.');
        return false;
    }
    
    if (!departmentId) {
        alert('Please select a department.');
        return false;
    }
    
    if (salaryMin < 0 || salaryMax < 0) {
        alert('Salary must be a positive number.');
        return false;
    }
    
    if (salaryMin > 0 && salaryMax > 0 && salaryMin > salaryMax) {
        alert('Minimum salary cannot be greater than maximum salary.');
        return false;
    }
    
    return true;
}

function validateStatusForm() {
    const status = document.getElementById('applicationStatus').value;
    
    if (!status) {
        alert('Please select a status.');
        return false;
    }
    
    if (status === 'interviewed') {
        const interviewDate = document.querySelector('#updateStatusModal input[name="interview_date"]').value;
        if (!interviewDate) {
            alert('Please enter an interview date and time.');
            return false;
        }
    } else if (status === 'rejected') {
        const rejectionReason = document.querySelector('#updateStatusModal textarea[name="rejection_reason"]').value.trim();
        if (!rejectionReason) {
            alert('Please provide a reason for rejection.');
            return false;
        }
    }
    
    return true;
}

function validateTogglePositionForm() {
    return confirm('Are you sure you want to change this position\'s status?');
}

// Update application status function
function updateApplicationStatus(applicationId, applicantName) {
    document.getElementById('statusApplicationId').value = applicationId;
    document.getElementById('statusApplicantName').textContent = applicantName;
    
    new bootstrap.Modal(document.getElementById('updateStatusModal')).show();
}

// View job details function
function viewJobDetails(button) {
    const job = JSON.parse(button.dataset.job);
    const content = `
        <div class="row">
            <div class="col-md-6">
                <h6 class="text-primary">Position Information</h6>
                <p><strong>Title:</strong> ${job.title}</p>
                <p><strong>Department:</strong> ${job.department_name}</p>
                <p><strong>Employment Type:</strong> ${job.employment_type.replace('_', ' ').replace(/\b\w/g, l => l.toUpperCase())}</p>
                <p><strong>Created By:</strong> ${job.created_by_name || 'N/A'}</p>
                <p><strong>Created On:</strong> ${new Date(job.created_at).toLocaleDateString()}</p>
            </div>
            <div class="col-md-6">
                <h6 class="text-primary">Salary & Status</h6>
                <p><strong>Salary Range:</strong> 
                ${job.salary_min > 0 || job.salary_max > 0 ? 
                  `TZS ${parseFloat(job.salary_min).toLocaleString()} - TZS ${parseFloat(job.salary_max).toLocaleString()}` : 
                  'Not specified'}
                </p>
                <p><strong>Status:</strong> <span class="badge bg-${job.status === 'open' ? 'success' : (job.status === 'closed' ? 'danger' : 'warning')}">${job.status.replace('_', ' ').replace(/\b\w/g, l => l.toUpperCase())}</span></p>
                <p><strong>Applications:</strong> ${job.application_count}</p>
                ${job.new_applications > 0 ? `<p><strong>New Applications:</strong> <span class="badge bg-warning">${job.new_applications}</span></p>` : ''}
            </div>
        </div>
        <hr>
        <h6 class="text-primary">Job Description</h6>
        <p class="mb-3">${job.description || 'No description provided'}</p>
        
        <h6 class="text-primary">Requirements</h6>
        <p class="mb-3">${job.requirements || 'No requirements specified'}</p>
    `;
    
    document.getElementById('jobDetailsContent').innerHTML = content;
    new bootstrap.Modal(document.getElementById('jobDetailsModal')).show();
}

// View application details function
function viewApplicationDetails(button) {
    const application = JSON.parse(button.dataset.app);
    const content = `
        <div class="row">
            <div class="col-md-6">
                <h6 class="text-primary">Applicant Information</h6>
                <p><strong>Name:</strong> ${application.applicant_name}</p>
                <p><strong>Email:</strong> ${application.email}</p>
                <p><strong>Phone:</strong> ${application.phone || 'N/A'}</p>
                <p><strong>Applied For:</strong> ${application.position_title}</p>
                <p><strong>Department:</strong> ${application.department_name}</p>
                <p><strong>Applied On:</strong> ${new Date(application.created_at).toLocaleDateString()}</p>
            </div>
            <div class="col-md-6">
                <h6 class="text-primary">Application Status</h6>
                <p><strong>Current Status:</strong> <span class="badge bg-secondary">${application.status.charAt(0).toUpperCase() + application.status.slice(1)}</span></p>
                <p><strong>Expected Salary:</strong> ${application.expected_salary ? 'TZS ' + parseFloat(application.expected_salary).toLocaleString() : 'Not specified'}</p>
                <p><strong>Available From:</strong> ${application.available_from ? new Date(application.available_from).toLocaleDateString() : 'Not specified'}</p>
                ${application.interview_date ? `<p><strong>Interview:</strong> ${new Date(application.interview_date).toLocaleString()}</p>` : ''}
                ${application.interviewer_name ? `<p><strong>Interviewer:</strong> ${application.interviewer_name}</p>` : ''}
            </div>
        </div>
        <hr>
        ${application.address ? `<h6 class="text-primary">Address</h6><p class="mb-3">${application.address}</p>` : ''}
        
        ${application.education ? `<h6 class="text-primary">Education</h6><p class="mb-3">${application.education}</p>` : ''}
        
        ${application.experience ? `<h6 class="text-primary">Experience</h6><p class="mb-3">${application.experience}</p>` : ''}
        
        ${application.skills ? `<h6 class="text-primary">Skills</h6><p class="mb-3">${application.skills}</p>` : ''}
        
        ${application.cover_letter ? `<h6 class="text-primary">Cover Letter</h6><p class="mb-3">${application.cover_letter}</p>` : ''}
        
        ${application.interview_notes ? `<h6 class="text-primary">Interview Notes</h6><div class="alert alert-info">${application.interview_notes}</div>` : ''}
        
        ${application.rejection_reason ? `<h6 class="text-danger">Rejection Reason</h6><div class="alert alert-danger">${application.rejection_reason}</div>` : ''}
    `;
    
    document.getElementById('applicationDetailsContent').innerHTML = content;
    new bootstrap.Modal(document.getElementById('applicationDetailsModal')).show();
}

// Toggle position status function
function togglePositionStatus(positionId, positionTitle, currentStatus) {
    const newStatus = currentStatus === 'open' ? 'closed' : 'open';
    const actionText = newStatus === 'open' ? 'open' : 'close';
    const actionDescription = newStatus === 'open' 
        ? 'This will make the position visible to applicants on the public job openings page.' 
        : 'This will hide the position from the public job openings page and stop accepting new applications.';
    
    document.getElementById('togglePositionId').value = positionId;
    document.getElementById('toggleNewStatus').value = newStatus;
    document.getElementById('togglePositionTitle').textContent = positionTitle;
    document.getElementById('toggleActionText').textContent = actionText;
    document.getElementById('toggleActionDescription').textContent = actionDescription;
    
    const modalTitle = document.getElementById('toggleModalTitle');
    const submitBtn = document.getElementById('toggleSubmitBtn');
    const submitText = document.getElementById('toggleSubmitText');
    
    if (newStatus === 'open') {
        modalTitle.innerHTML = '<i class="bi bi-door-open me-2"></i>Open Job Position';
        submitBtn.className = 'btn btn-success';
        submitText.textContent = 'Open Position';
    } else {
        modalTitle.innerHTML = '<i class="bi bi-door-closed me-2"></i>Close Job Position';
        submitBtn.className = 'btn btn-warning';
        submitText.textContent = 'Close Position';
    }
    
    new bootstrap.Modal(document.getElementById('togglePositionModal')).show();
}

// View applications for a position function
function viewApplications(positionId, title) {
    document.getElementById('positionTitle').textContent = title;
    const content = document.getElementById('positionApplicationsContent');
    
    // Filter applications for this position
    const filteredApps = applications.filter(app => app.position_id == positionId);
    
    if (filteredApps.length === 0) {
        content.innerHTML = '<p class="text-center text-muted">No applications for this position yet.</p>';
    } else {
        let html = '<div class="table-responsive"><table class="table table-bordered table-hover"><thead class="table-light"><tr><th>Applicant</th><th>Applied Date</th><th>Status</th><th>Actions</th></tr></thead><tbody>';
        filteredApps.forEach(app => {
            const statusColors = {
                'received': 'primary',
                'reviewing': 'info',
                'shortlisted': 'warning',
                'interviewed': 'secondary',
                'selected': 'success',
                'rejected': 'danger',
                'hired': 'success'
            };
            const color = statusColors[app.status] || 'secondary';
            html += `<tr>
                <td><strong>${app.applicant_name}</strong><br><small>${app.email}</small></td>
                <td>${new Date(app.created_at).toLocaleDateString()}</td>
                <td><span class="badge bg-${color}">${app.status.charAt(0).toUpperCase() + app.status.slice(1)}</span></td>
                <td><button class="btn btn-sm btn-outline-primary" data-app='${JSON.stringify(app).replace(/'/g, "\\'").replace(/"/g, '&quot;')}' onclick="viewApplicationDetails(this)">View</button></td>
            </tr>`;
        });
        html += '</tbody></table></div>';
        content.innerHTML = html;
    }
    
    new bootstrap.Modal(document.getElementById('positionApplicationsModal')).show();
}

// Share job link function
function shareJobLink(positionTitle) {
    const url = `${window.location.origin}${window.location.pathname.replace('recruitment.php', 'job_openings.php')}`;
    navigator.clipboard.writeText(url).then(() => {
        // Show success message
        const alert = document.createElement('div');
        alert.className = 'alert alert-success alert-dismissible fade show position-fixed';
        alert.style.cssText = 'top: 20px; right: 20px; z-index: 9999;';
        alert.innerHTML = `
            <i class="bi bi-check-circle me-2"></i>Job openings link copied to clipboard!
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        `;
        document.body.appendChild(alert);
        setTimeout(() => alert.remove(), 3000);
    }).catch(() => {
        // Fallback for older browsers
        const textArea = document.createElement('textarea');
        textArea.value = url;
        document.body.appendChild(textArea);
        textArea.select();
        document.execCommand('copy');
        document.body.removeChild(textArea);
        alert('Job openings link copied to clipboard!');
    });
}

// Search functionality for applications
document.getElementById('applicationsSearch').addEventListener('keyup', function() {
    const searchTerm = this.value.toLowerCase();
    const table = document.getElementById('applicationsTable');
    const rows = table.getElementsByTagName('tbody')[0].getElementsByTagName('tr');
    let visibleCount = 0;
    
    for (let i = 0; i < rows.length; i++) {
        const row = rows[i];
        const text = row.textContent.toLowerCase();
        const isVisible = text.includes(searchTerm);
        row.style.display = isVisible ? '' : 'none';
        if (isVisible) visibleCount++;
    }
    
    // Show a message if no results found
    if (visibleCount === 0 && searchTerm !== '') {
        // Add message if it doesn't exist
        let msg = document.getElementById('noSearchResults');
        if (!msg) {
            msg = document.createElement('tr');
            msg.id = 'noSearchResults';
            msg.innerHTML = '<td colspan="6" class="text-center text-muted py-3">No matching applications found.</td>';
            table.getElementsByTagName('tbody')[0].appendChild(msg);
        }
    } else {
        let msg = document.getElementById('noSearchResults');
        if (msg) msg.remove();
    }
});

// Initialize tooltips
document.addEventListener('DOMContentLoaded', function() {
    // Validate modal elements exist before setting up event listeners
    const statusSelect = document.getElementById('applicationStatus');
    if (statusSelect) {
        statusSelect.addEventListener('change', function() {
            const status = this.value;
            const interviewSection = document.getElementById('interviewSection');
            const interviewNotesSection = document.getElementById('interviewNotesSection');
            const rejectionSection = document.getElementById('rejectionSection');
            
            if (interviewSection && interviewNotesSection && rejectionSection) {
                // Hide all sections first
                interviewSection.style.display = 'none';
                interviewNotesSection.style.display = 'none';
                rejectionSection.style.display = 'none';
                
                if (status === 'interviewed') {
                    interviewSection.style.display = 'block';
                    interviewNotesSection.style.display = 'block';
                } else if (status === 'rejected') {
                    rejectionSection.style.display = 'block';
                }
            }
        });
    }
});
</script>

<?php include '../includes/footer.php'; ?>