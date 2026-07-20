<?php
require_once '../config/config.php';
require_once '../auth/auth_middleware.php';

require_hr();
$db = getDBConnection();

// Handle Add Application
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_application'])) {
    try {
        $first_name = sanitize_input($_POST['first_name']);
        $last_name = sanitize_input($_POST['last_name']);
        $email = sanitize_input($_POST['email']);
        $phone = sanitize_input($_POST['phone']);
        $date_of_birth = !empty($_POST['date_of_birth']) ? $_POST['date_of_birth'] : null;
        $years_of_experience = (float)$_POST['years_of_experience'];
        $job_position_id = (int)$_POST['job_position_id'];
        $cover_letter = sanitize_input($_POST['cover_letter'] ?? '');
        
        // Validate required fields
        if (empty($first_name) || empty($last_name) || empty($email) || empty($phone) || empty($job_position_id)) {
            throw new Exception('All required fields must be filled.');
        }
        
        // Check if email already exists for this position
        $check_stmt = $db->prepare("SELECT id FROM job_applications WHERE email = ? AND job_position_id = ?");
        $check_stmt->execute([$email, $job_position_id]);
        if ($check_stmt->fetch()) {
            throw new Exception('An application with this email already exists for the selected position.');
        }
        
        // Handle file upload
        $resume_path = null;
        if (isset($_FILES['resume']) && $_FILES['resume']['error'] === UPLOAD_ERR_OK) {
            $upload_dir = '../uploads/resumes/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }
            
            $file_extension = pathinfo($_FILES['resume']['name'], PATHINFO_EXTENSION);
            $allowed_extensions = ['pdf', 'doc', 'docx'];
            
            if (!in_array(strtolower($file_extension), $allowed_extensions)) {
                throw new Exception('Only PDF, DOC, and DOCX files are allowed.');
            }
            
            if ($_FILES['resume']['size'] > 5 * 1024 * 1024) { // 5MB
                throw new Exception('File size must be less than 5MB.');
            }
            
            $filename = uniqid() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $_FILES['resume']['name']);
            $resume_path = $upload_dir . $filename;
            
            if (!move_uploaded_file($_FILES['resume']['tmp_name'], $resume_path)) {
                throw new Exception('Failed to upload resume.');
            }
        }
        
        // Insert application
        $stmt = $db->prepare("
            INSERT INTO job_applications 
            (first_name, last_name, email, phone, date_of_birth, years_of_experience, 
             job_position_id, cover_letter, resume_path, status) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')
        ");
        
        $stmt->execute([
            $first_name, 
            $last_name, 
            $email, 
            $phone, 
            $date_of_birth, 
            $years_of_experience, 
            $job_position_id, 
            $cover_letter, 
            $resume_path
        ]);
        
        // Log the activity
        $log_stmt = $db->prepare("INSERT INTO hr_activities (activity_type, description, created_by) VALUES (?, ?, ?)");
        $log_stmt->execute([
            'application_received',
            "Received job application from: {$first_name} {$last_name}",
            $_SESSION['user_id']
        ]);
        
        $_SESSION['success_message'] = 'Job application added successfully!';
        
    } catch (Exception $e) {
        $_SESSION['error_message'] = 'Error adding application: ' . $e->getMessage();
    }
    
    $redirect_url = 'job_applications.php';
    if (isset($_POST['job_position_id']) && !empty($_POST['job_position_id'])) {
        $redirect_url .= '?position_id=' . (int)$_POST['job_position_id'];
    }
    header('Location: ' . $redirect_url);
    exit();
}

// Handle other application actions (view, delete, etc.)
if (isset($_GET['action'])) {
    switch ($_GET['action']) {
        case 'view':
            $application_id = (int)$_GET['id'];
            try {
                $stmt = $db->prepare("
                    SELECT ja.*, jp.title as position_title, d.name as department_name,
                           CONCAT(ja.first_name, ' ', ja.last_name) as full_name
                    FROM job_applications ja
                    LEFT JOIN job_positions jp ON ja.job_position_id = jp.id
                    LEFT JOIN departments d ON jp.department_id = d.id
                    WHERE ja.id = ?
                ");
                $stmt->execute([$application_id]);
                $application = $stmt->fetch();
                
                if ($application) {
                    echo '<h6>Personal Information</h6>';
                    echo '<div class="row mb-3">';
                    echo '<div class="col-md-6"><strong>Name:</strong> ' . htmlspecialchars($application['full_name']) . '</div>';
                    echo '<div class="col-md-6"><strong>Email:</strong> ' . htmlspecialchars($application['email']) . '</div>';
                    echo '</div>';
                    echo '<div class="row mb-3">';
                    echo '<div class="col-md-6"><strong>Phone:</strong> ' . htmlspecialchars($application['phone']) . '</div>';
                    echo '<div class="col-md-6"><strong>Experience:</strong> ' . $application['years_of_experience'] . ' years</div>';
                    echo '</div>';
                    
                    echo '<h6>Application Details</h6>';
                    echo '<div class="row mb-3">';
                    echo '<div class="col-md-6"><strong>Position:</strong> ' . htmlspecialchars($application['position_title']) . '</div>';
                    echo '<div class="col-md-6"><strong>Department:</strong> ' . htmlspecialchars($application['department_name']) . '</div>';
                    echo '</div>';
                    echo '<div class="row mb-3">';
                    echo '<div class="col-md-6"><strong>Status:</strong> <span class="badge bg-primary">' . ucfirst($application['status']) . '</span></div>';
                    echo '<div class="col-md-6"><strong>Applied:</strong> ' . date('M j, Y', strtotime($application['created_at'])) . '</div>';
                    echo '</div>';
                    
                    if (!empty($application['cover_letter'])) {
                        echo '<h6>Cover Letter</h6>';
                        echo '<div class="border p-3 rounded">' . nl2br(htmlspecialchars($application['cover_letter'])) . '</div>';
                    }
                    
                    if (!empty($application['resume_path'])) {
                        echo '<h6 class="mt-3">Resume</h6>';
                        echo '<a href="' . htmlspecialchars($application['resume_path']) . '" class="btn btn-outline-primary btn-sm" target="_blank">View Resume</a>';
                    }
                } else {
                    echo '<div class="alert alert-warning">Application not found.</div>';
                }
            } catch (Exception $e) {
                echo '<div class="alert alert-danger">Error loading application details.</div>';
            }
            exit();
            
        case 'delete':
            $application_id = (int)$_GET['id'];
            try {
                // Get application details for logging
                $stmt = $db->prepare("SELECT first_name, last_name FROM job_applications WHERE id = ?");
                $stmt->execute([$application_id]);
                $application = $stmt->fetch();
                
                // Delete application
                $delete_stmt = $db->prepare("DELETE FROM job_applications WHERE id = ?");
                $delete_stmt->execute([$application_id]);
                
                if ($application) {
                    // Log the activity
                    $log_stmt = $db->prepare("INSERT INTO hr_activities (activity_type, description, created_by) VALUES (?, ?, ?)");
                    $log_stmt->execute([
                        'application_deleted',
                        "Deleted job application from: {$application['first_name']} {$application['last_name']}",
                        $_SESSION['user_id']
                    ]);
                }
                
                $_SESSION['success_message'] = 'Application deleted successfully!';
            } catch (Exception $e) {
                $_SESSION['error_message'] = 'Error deleting application: ' . $e->getMessage();
            }
            
            header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? 'job_applications'));
            exit();
    }
}

// Default redirect
header('Location: job_applications');
exit();