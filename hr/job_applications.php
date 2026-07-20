<?php
require_once '../config/config.php';
require_once '../auth/auth_middleware.php';

require_hr();
$db = getDBConnection();

$page_title = 'Job Applications';
include '../includes/header.php';

// Get position ID from URL
$position_id = isset($_GET['position_id']) ? (int)$_GET['position_id'] : 0;

// Get position details
$position = null;
if ($position_id > 0) {
    try {
        $stmt = $db->prepare("
            SELECT jp.*, d.name as department_name 
            FROM job_positions jp 
            LEFT JOIN departments d ON jp.department_id = d.id 
            WHERE jp.id = ?
        ");
        $stmt->execute([$position_id]);
        $position = $stmt->fetch();
    } catch (Exception $e) {
        error_log("Error fetching position: " . $e->getMessage());
    }
}

// Get applications with filters
$status_filter = $_GET['status'] ?? 'all';
$search_term = $_GET['search'] ?? '';

try {
    $query = "
        SELECT ja.*, 
               jp.title as position_title,
               CONCAT(ja.first_name, ' ', ja.last_name) as full_name,
               TIMESTAMPDIFF(YEAR, ja.date_of_birth, CURDATE()) as age
        FROM job_applications ja
        LEFT JOIN job_positions jp ON ja.job_position_id = jp.id
        WHERE 1=1
    ";
    
    $params = [];
    
    if ($position_id > 0) {
        $query .= " AND ja.job_position_id = ?";
        $params[] = $position_id;
    }
    
    if ($status_filter !== 'all') {
        $query .= " AND ja.status = ?";
        $params[] = $status_filter;
    }
    
    if (!empty($search_term)) {
        $query .= " AND (ja.first_name LIKE ? OR ja.last_name LIKE ? OR ja.email LIKE ?)";
        $search_param = "%$search_term%";
        $params[] = $search_param;
        $params[] = $search_param;
        $params[] = $search_param;
    }
    
    $query .= " ORDER BY ja.created_at DESC";
    
    $stmt = $db->prepare($query);
    $stmt->execute($params);
    $applications = $stmt->fetchAll();
} catch (Exception $e) {
    $applications = [];
    echo '<div class="alert alert-danger">Error loading applications: ' . htmlspecialchars($e->getMessage()) . '</div>';
}

// Display messages
if (isset($_SESSION['success_message'])) {
    echo '<div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="bi bi-check-circle-fill me-2"></i>'
            . $_SESSION['success_message'] .
            '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
          </div>';
    unset($_SESSION['success_message']);
}

if (isset($_SESSION['error_message'])) {
    echo '<div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="bi bi-exclamation-triangle-fill me-2"></i>'
            . $_SESSION['error_message'] .
            '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
          </div>';
    unset($_SESSION['error_message']);
}
?>

<div class="container-fluid pt-4 px-4">
    <div class="d-sm-flex align-items-center justify-content-between mb-4">
        <div>
            <h1 class="h3 mb-0 text-gray-800">
                Job Applications
                <?php if ($position): ?>
                    <small class="text-muted">- <?php echo htmlspecialchars($position['title']); ?></small>
                <?php endif; ?>
            </h1>
            <?php if ($position): ?>
                <p class="text-muted mb-0">
                    Department: <?php echo htmlspecialchars($position['department_name']); ?> | 
                    Type: <?php echo ucfirst($position['employment_type']); ?>
                </p>
            <?php endif; ?>
        </div>
        <div>
            <?php if (!$position): ?>
                <a href="recruitment" class="btn btn-secondary">
                    <i class="bi bi-arrow-left me-2"></i>Back to Recruitment
                </a>
            <?php endif; ?>
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addApplicationModal">
                <i class="bi bi-plus-circle me-2"></i>Add Application
            </button>
        </div>
    </div>

    <!-- Filters and Search -->
    <div class="card shadow mb-4">
        <div class="card-body">
            <form method="GET" class="row g-3">
                <?php if ($position_id): ?>
                    <input type="hidden" name="position_id" value="<?php echo $position_id; ?>">
                <?php endif; ?>
                <div class="col-md-4">
                    <label class="form-label">Status</label>
                    <select name="status" class="form-select" onchange="this.form.submit()">
                        <option value="all" <?php echo $status_filter === 'all' ? 'selected' : ''; ?>>All Statuses</option>
                        <option value="pending" <?php echo $status_filter === 'pending' ? 'selected' : ''; ?>>Pending</option>
                        <option value="reviewed" <?php echo $status_filter === 'reviewed' ? 'selected' : ''; ?>>Reviewed</option>
                        <option value="interview" <?php echo $status_filter === 'interview' ? 'selected' : ''; ?>>Interview</option>
                        <option value="accepted" <?php echo $status_filter === 'accepted' ? 'selected' : ''; ?>>Accepted</option>
                        <option value="rejected" <?php echo $status_filter === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Search</label>
                    <div class="input-group">
                        <input type="text" name="search" class="form-control" placeholder="Search by name or email..." value="<?php echo htmlspecialchars($search_term); ?>">
                        <button class="btn btn-outline-primary" type="submit">
                            <i class="bi bi-search"></i>
                        </button>
                    </div>
                </div>
                <div class="col-md-2">
                    <label class="form-label">&nbsp;</label>
                    <a href="?<?php echo $position_id ? "position_id=$position_id" : ''; ?>" class="btn btn-outline-secondary d-block">Reset</a>
                </div>
            </form>
        </div>
    </div>

    <!-- Applications List -->
    <div class="card shadow">
        <div class="card-header py-3">
            <h6 class="m-0 font-weight-bold text-primary">
                Applications (<?php echo count($applications); ?>)
            </h6>
        </div>
        <div class="card-body">
            <?php if (empty($applications)): ?>
                <div class="text-center py-5">
                    <i class="bi bi-person-x display-1 text-muted"></i>
                    <h5 class="text-muted mt-3">No Applications Found</h5>
                    <p class="text-muted">No job applications match your current filters.</p>
                    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addApplicationModal">
                        <i class="bi bi-plus-circle me-2"></i>Add First Application
                    </button>
                </div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-bordered table-hover">
                    <thead>
                        <tr>
                            <th>Applicant</th>
                            <th>Contact</th>
                            <th>Position</th>
                            <th>Experience</th>
                            <th>Status</th>
                            <th>Applied Date</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($applications as $application): ?>
                        <tr>
                            <td>
                                <div class="d-flex align-items-center">
                                    <div class="flex-shrink-0">
                                        <div class="bg-primary rounded-circle d-flex align-items-center justify-content-center" 
                                             style="width: 40px; height: 40px;">
                                            <span class="fw-bold">
                                                <?php echo strtoupper(substr($application['first_name'], 0, 1) . substr($application['last_name'], 0, 1)); ?>
                                            </span>
                                        </div>
                                    </div>
                                    <div class="flex-grow-1 ms-3">
                                        <h6 class="mb-0"><?php echo htmlspecialchars($application['full_name']); ?></h6>
                                        <small class="text-muted">Age: <?php echo $application['age']; ?></small>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <div><?php echo htmlspecialchars($application['email']); ?></div>
                                <small class="text-muted"><?php echo htmlspecialchars($application['phone']); ?></small>
                            </td>
                            <td>
                                <?php if (!$position): ?>
                                    <span class="fw-semibold"><?php echo htmlspecialchars($application['position_title']); ?></span>
                                <?php else: ?>
                                    <span class="text-muted">Current Position</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge bg-light text-dark">
                                    <?php echo $application['years_of_experience']; ?> years
                                </span>
                            </td>
                            <td>
                                <span class="badge bg-<?php 
                                    switch($application['status']) {
                                        case 'pending': echo 'warning'; break;
                                        case 'reviewed': echo 'info'; break;
                                        case 'interview': echo 'primary'; break;
                                        case 'accepted': echo 'success'; break;
                                        case 'rejected': echo 'danger'; break;
                                        default: echo 'secondary';
                                    }
                                ?>">
                                    <?php echo ucfirst($application['status']); ?>
                                </span>
                            </td>
                            <td>
                                <?php echo date('M j, Y', strtotime($application['created_at'])); ?>
                            </td>
                            <td>
                                <div class="btn-group btn-group-sm">
                                    <button class="btn btn-info view-application" 
                                            data-id="<?php echo $application['id']; ?>"
                                            title="View Details">
                                        <i class="bi bi-eye"></i>
                                    </button>
                                    <button class="btn btn-warning edit-application" 
                                            data-id="<?php echo $application['id']; ?>"
                                            title="Edit Application">
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                    <button class="btn btn-danger delete-application" 
                                            data-id="<?php echo $application['id']; ?>"
                                            data-name="<?php echo htmlspecialchars($application['full_name']); ?>"
                                            title="Delete Application">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Add Application Modal -->
<div class="modal fade" id="addApplicationModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" action="hr_application_actions" enctype="multipart/form-data">
                <div class="modal-header">
                    <h5 class="modal-title">Add Job Application</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">First Name *</label>
                            <input type="text" class="form-control" name="first_name" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Last Name *</label>
                            <input type="text" class="form-control" name="last_name" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Email *</label>
                            <input type="email" class="form-control" name="email" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Phone *</label>
                            <input type="tel" class="form-control" name="phone" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Date of Birth</label>
                            <input type="date" class="form-control" name="date_of_birth">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Years of Experience</label>
                            <input type="number" class="form-control" name="years_of_experience" min="0" step="0.5" value="0">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Job Position *</label>
                            <select class="form-select" name="job_position_id" required>
                                <option value="">Select Position</option>
                                <?php
                                try {
                                    $positions = $db->query("
                                        SELECT id, title, department_id 
                                        FROM job_positions 
                                        WHERE status='open'
                                        ORDER BY title
                                    ")->fetchAll();
                                    foreach ($positions as $pos): 
                                        $selected = $position_id == $pos['id'] ? 'selected' : '';
                                ?>
                                    <option value="<?php echo $pos['id']; ?>" <?php echo $selected; ?>>
                                        <?php echo htmlspecialchars($pos['title']); ?>
                                    </option>
                                <?php endforeach;
                                } catch (Exception $e) {
                                    echo '<option value="">No positions available</option>';
                                }
                                ?>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Cover Letter</label>
                            <textarea class="form-control" name="cover_letter" rows="4" 
                                      placeholder="Optional cover letter..."></textarea>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Resume/CV</label>
                            <input type="file" class="form-control" name="resume" accept=".pdf,.doc,.docx">
                            <small class="form-text text-muted">Accepted formats: PDF, DOC, DOCX (Max: 5MB)</small>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="add_application" class="btn btn-primary">
                        <i class="bi bi-plus-circle me-2"></i>Add Application
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- View Application Modal -->
<div class="modal fade" id="viewApplicationModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Application Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="applicationDetails">
                <!-- Content will be loaded via AJAX -->
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<?php include '../includes/footer.php'; ?>

<script>
// View Application Details
document.addEventListener('DOMContentLoaded', function() {
    // View application details
    document.querySelectorAll('.view-application').forEach(button => {
        button.addEventListener('click', function() {
            const applicationId = this.getAttribute('data-id');
            fetch(`hr_application_actions.php?action=view&id=${applicationId}`)
                .then(response => response.text())
                .then(html => {
                    document.getElementById('applicationDetails').innerHTML = html;
                    new bootstrap.Modal(document.getElementById('viewApplicationModal')).show();
                })
                .catch(error => console.error('Error:', error));
        });
    });

    // Delete application confirmation
    document.querySelectorAll('.delete-application').forEach(button => {
        button.addEventListener('click', function() {
            const applicationId = this.getAttribute('data-id');
            const applicantName = this.getAttribute('data-name');
            
            if (confirm(`Are you sure you want to delete the application for ${applicantName}?`)) {
                window.location.href = `hr_application_actions?action=delete&id=${applicationId}`;
            }
        });
    });
});
</script>