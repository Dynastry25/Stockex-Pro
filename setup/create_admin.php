<?php
/**
 * Admin Setup Script
 * Creates or resets the system administrator account
 */

require_once '../config/database.php';

// Admin credentials
$admin_username = 'admin';
$admin_email = 'admin@stockexchange.com';
$admin_password = 'admin123';
$admin_full_name = 'System Administrator';

try {
    $db = getDBConnection();
    
    // Check if admin user already exists
    $check_stmt = $db->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
    $check_stmt->execute([$admin_username, $admin_email]);
    $existing_admin = $check_stmt->fetch();
    
    // Hash the password properly
    $password_hash = password_hash($admin_password, PASSWORD_DEFAULT);
    
    if ($existing_admin) {
        // Update existing admin user
        $update_stmt = $db->prepare("
            UPDATE users 
            SET password_hash = ?, 
                email = ?, 
                full_name = ?, 
                role = 'system_admin', 
                is_active = 1, 
                mandate_enabled = 1,
                updated_at = CURRENT_TIMESTAMP
            WHERE username = ?
        ");
        
        if ($update_stmt->execute([$password_hash, $admin_email, $admin_full_name, $admin_username])) {
            echo "<div class='alert alert-success'>Admin user updated successfully!</div>";
        } else {
            echo "<div class='alert alert-danger'>Error updating admin user.</div>";
        }
    } else {
        // Create new admin user
        $insert_stmt = $db->prepare("
            INSERT INTO users (username, email, password_hash, role, full_name, is_active, mandate_enabled) 
            VALUES (?, ?, ?, 'system_admin', ?, 1, 1)
        ");
        
        if ($insert_stmt->execute([$admin_username, $admin_email, $password_hash, $admin_full_name])) {
            echo "<div class='alert alert-success'>Admin user created successfully!</div>";
        } else {
            echo "<div class='alert alert-danger'>Error creating admin user.</div>";
        }
    }
    
    // Verify the admin user
    $verify_stmt = $db->prepare("SELECT * FROM users WHERE username = ?");
    $verify_stmt->execute([$admin_username]);
    $admin_user = $verify_stmt->fetch();
    
    if ($admin_user && password_verify($admin_password, $admin_user['password_hash'])) {
        echo "<div class='alert alert-info'>
            <h5>Admin Login Credentials:</h5>
            <strong>Username:</strong> {$admin_username}<br>
            <strong>Password:</strong> {$admin_password}<br>
            <strong>Email:</strong> {$admin_email}
        </div>";
        echo "<div class='alert alert-success'>Password verification successful!</div>";
    } else {
        echo "<div class='alert alert-danger'>Password verification failed!</div>";
    }
    
} catch (Exception $e) {
    echo "<div class='alert alert-danger'>Database error: " . $e->getMessage() . "</div>";
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Setup - Stock Exchange System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
    <div class="container mt-5">
        <div class="row justify-content-center">
            <div class="col-md-8">
                <div class="card">
                    <div class="card-header">
                        <h3 class="mb-0">Admin Setup Complete</h3>
                    </div>
                    <div class="card-body">
                        <p>The system administrator account has been set up. You can now login with the credentials shown above.</p>
                        <div class="mt-4">
                            <a href="../auth/login.php" class="btn btn-primary">Go to Login</a>
                            <a href="../index.php" class="btn btn-secondary">Go to Home</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
