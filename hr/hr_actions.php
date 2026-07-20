<?php
require_once '../config/config.php';
require_once '../auth/auth_middleware.php';

require_hr();
$db = getDBConnection();

// Handle Add Job Position
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_job_position'])) {
    try {
        $title = sanitize_input($_POST['title']);
        $department_id = (int)$_POST['department_id'];
        $employment_type = sanitize_input($_POST['employment_type']);
        $salary_range = sanitize_input($_POST['salary_range'] ?? '');
        $description = sanitize_input($_POST['description']);
        $requirements = sanitize_input($_POST['requirements']);
        
        // Validate required fields
        if (empty($title) || empty($department_id) || empty($employment_type) || empty($description) || empty($requirements)) {
            throw new Exception('All required fields must be filled.');
        }
        
        // Insert job position
        $stmt = $db->prepare("
            INSERT INTO job_positions 
            (title, department_id, employment_type, salary_range, description, requirements, status, created_by) 
            VALUES (?, ?, ?, ?, ?, ?, 'open', ?)
        ");
        
        $stmt->execute([
            $title, 
            $department_id, 
            $employment_type, 
            $salary_range, 
            $description, 
            $requirements, 
            $_SESSION['user_id']
        ]);
        
        // Log the activity
        $log_stmt = $db->prepare("INSERT INTO hr_activities (activity_type, description, created_by) VALUES (?, ?, ?)");
        $log_stmt->execute([
            'job_position_created',
            "Created new job position: {$title}",
            $_SESSION['user_id']
        ]);
        
        $_SESSION['success_message'] = 'Job position added successfully!';
        
    } catch (Exception $e) {
        $_SESSION['error_message'] = 'Error adding job position: ' . $e->getMessage();
    }
    
    header('Location: recruitment');
    exit();
}

// Handle other HR actions can be added here...

// Default redirect if no action matched
header('Location: recruitment.php');
exit();
?>