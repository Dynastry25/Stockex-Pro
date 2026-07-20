<?php
require_once '../config/config.php';
require_once '../auth/auth_middleware.php';

// This is a public page, no auth required
// require_hr(); // Remove this for public access

$db = getDBConnection();

$page_title = 'Job Openings';
$success_message = '';
$error_message = '';

// Handle job application submission
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['submit_application'])) {
    try {
        $position_id = (int)$_POST['position_id'];
        $applicant_name = sanitize_input($_POST['applicant_name']);
        $email = sanitize_input($_POST['email']);
        $phone = sanitize_input($_POST['phone']);
        $address = sanitize_input($_POST['address']);
        $education = sanitize_input($_POST['education']);
        $experience = sanitize_input($_POST['experience']);
        $skills = sanitize_input($_POST['skills']);
        $cover_letter = sanitize_input($_POST['cover_letter']);
        $expected_salary = (float)$_POST['expected_salary'];
        $available_from = $_POST['available_from'] ?: null;

        // Basic validation
        if (empty($applicant_name) || empty($email) || empty($position_id)) {
            $error_message = 'Please fill in all required fields.';
        } else {
            // Check if email already applied for this position
            $stmt = $db->prepare("SELECT id FROM job_applications WHERE position_id = ? AND email = ?");
            $stmt->execute([$position_id, $email]);
            if ($stmt->fetch()) {
                $error_message = 'You have already applied for this position.';
            } else {
                // Insert application
                $stmt = $db->prepare("
                    INSERT INTO job_applications (
                        position_id, applicant_name, email, phone, address, education,
                        experience, skills, cover_letter, expected_salary, available_from, status
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'received')
                ");
                $stmt->execute([
                    $position_id, $applicant_name, $email, $phone, $address, $education,
                    $experience, $skills, $cover_letter, $expected_salary, $available_from
                ]);

                $success_message = 'Your application has been submitted successfully! We will review it and get back to you soon.';
            }
        }
    } catch (Exception $e) {
        $error_message = 'Error submitting application: ' . $e->getMessage();
    }
}

// Get open job positions
try {
    $stmt = $db->query("
        SELECT jp.*, d.name as department_name,
               COUNT(ja.id) as application_count
        FROM job_positions jp
        JOIN departments d ON jp.department_id = d.id
        LEFT JOIN job_applications ja ON jp.id = ja.position_id
        WHERE jp.status = 'open'
        GROUP BY jp.id
        ORDER BY jp.created_at DESC
    ");
    $open_positions = $stmt->fetchAll();
} catch (Exception $e) {
    $open_positions = [];
    $error_message = 'Error loading job positions.';
}

include '../includes/header.php';
?>

<div class="container-fluid pt-4 px-4">
    <div class="text-center mb-4">
        <h1 class="h2 mb-2 text-primary">Career Opportunities</h1>
        <p class="text-muted">Join our team and be part of something great</p>
    </div>

    <?php if (!empty($success_message)): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <i class="bi bi-check-circle me-2"></i>
            <?php echo htmlspecialchars($success_message); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if (!empty($error_message)): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <i class="bi bi-exclamation-triangle me-2"></i>
            <?php echo htmlspecialchars($error_message); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if (empty($open_positions)): ?>
        <div class="text-center py-5">
            <i class="bi bi-briefcase display-4 text-muted mb-3"></i>
            <h3 class="text-muted">No Open Positions</h3>
            <p class="text-muted">Check back later for new opportunities.</p>
        </div>
    <?php else: ?>
        <div class="row">
            <?php foreach ($open_positions as $position): ?>
                <div class="col-lg-6 col-xl-4 mb-4">
                    <div class="card h-100 shadow-sm">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-start mb-2">
                                <h5 class="card-title text-primary mb-1">
                                    <?php echo htmlspecialchars($position['title']); ?>
                                </h5>
                                <span class="badge bg-success">Open</span>
                            </div>
                            <p class="text-muted mb-2">
                                <i class="bi bi-building me-1"></i><?php echo htmlspecialchars($position['department_name']); ?>
                            </p>
                            <p class="text-muted mb-2">
                                <i class="bi bi-clock me-1"></i><?php echo ucwords(str_replace('_', ' ', $position['employment_type'])); ?>
                            </p>
                            <?php if ($position['salary_min'] > 0 || $position['salary_max'] > 0): ?>
                                <p class="text-muted mb-2">
                                    <i class="bi bi-cash me-1"></i>
                                    TZS <?php echo number_format($position['salary_min'], 0); ?> -
                                    TZS <?php echo number_format($position['salary_max'], 0); ?>
                                </p>
                            <?php endif; ?>
                            <p class="card-text mb-3">
                                <?php echo strlen($position['description']) > 150 ?
                                    substr(htmlspecialchars($position['description']), 0, 150) . '...' :
                                    htmlspecialchars($position['description']); ?>
                            </p>
                            <div class="d-flex justify-content-between align-items-center">
                                <small class="text-muted">
                                    <?php echo $position['application_count']; ?> applicants
                                </small>
                                <button class="btn btn-primary btn-sm" onclick="applyForPosition(<?php echo $position['id']; ?>, '<?php echo htmlspecialchars($position['title']); ?>')">
                                    <i class="bi bi-send me-1"></i>Apply Now
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<!-- Job Application Modal -->
<div class="modal fade" id="applyModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" action="" onsubmit="return validateApplicationForm()">
                <input type="hidden" name="position_id" id="applyPositionId">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="bi bi-send me-2"></i>Apply for <span id="applyPositionTitle"></span>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Full Name <span class="text-danger">*</span></label>
                            <input type="text" name="applicant_name" class="form-control" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Email <span class="text-danger">*</span></label>
                            <input type="email" name="email" class="form-control" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Phone</label>
                            <input type="tel" name="phone" class="form-control">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Expected Salary (TZS)</label>
                            <input type="number" name="expected_salary" class="form-control" step="1000" min="0">
                        </div>
                        <div class="col-12 mb-3">
                            <label class="form-label">Address</label>
                            <textarea name="address" class="form-control" rows="2"></textarea>
                        </div>
                        <div class="col-12 mb-3">
                            <label class="form-label">Education</label>
                            <textarea name="education" class="form-control" rows="2" placeholder="Your educational background..."></textarea>
                        </div>
                        <div class="col-12 mb-3">
                            <label class="form-label">Work Experience</label>
                            <textarea name="experience" class="form-control" rows="3" placeholder="Your work experience..."></textarea>
                        </div>
                        <div class="col-12 mb-3">
                            <label class="form-label">Skills</label>
                            <textarea name="skills" class="form-control" rows="2" placeholder="Your skills and competencies..."></textarea>
                        </div>
                        <div class="col-12 mb-3">
                            <label class="form-label">Available From</label>
                            <input type="date" name="available_from" class="form-control">
                        </div>
                        <div class="col-12 mb-3">
                            <label class="form-label">Cover Letter</label>
                            <textarea name="cover_letter" class="form-control" rows="4" placeholder="Tell us why you're interested in this position..."></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="submit_application" class="btn btn-primary">
                        <i class="bi bi-send me-1"></i>Submit Application
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Apply for position function
function applyForPosition(positionId, positionTitle) {
    document.getElementById('applyPositionId').value = positionId;
    document.getElementById('applyPositionTitle').textContent = positionTitle;
    new bootstrap.Modal(document.getElementById('applyModal')).show();
}

// Form validation
function validateApplicationForm() {
    const name = document.querySelector('#applyModal input[name="applicant_name"]').value.trim();
    const email = document.querySelector('#applyModal input[name="email"]').value.trim();

    if (!name) {
        alert('Please enter your full name.');
        return false;
    }

    if (!email) {
        alert('Please enter your email address.');
        return false;
    }

    // Basic email validation
    const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    if (!emailRegex.test(email)) {
        alert('Please enter a valid email address.');
        return false;
    }

    return confirm('Are you sure you want to submit this application?');
}
</script>

<?php include '../includes/footer.php'; ?>