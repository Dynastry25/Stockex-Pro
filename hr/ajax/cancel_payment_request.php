<?php
require_once '../config/config.php';
require_once '../auth/auth_middleware.php';

// Only HR staff can access
require_hr_staff();

// CSRF Protection
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$db = getDBConnection();

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // CSRF validation
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        echo json_encode(['error' => 'Security token validation failed']);
        exit;
    }
    
    $request_id = (int)($_POST['request_id'] ?? 0);
    
    if ($request_id <= 0) {
        echo json_encode(['error' => 'Invalid request ID']);
        exit;
    }
    
    try {
        // Get current user ID
        $user_id = $_SESSION['user_id'] ?? 0;
        
        // Check if request exists and is pending
        $stmt = $db->prepare("SELECT status, request_no FROM pending_pay WHERE id = ? AND requested_by = ?");
        $stmt->execute([$request_id, $user_id]);
        $request = $stmt->fetch();
        
        if (!$request) {
            echo json_encode(['error' => 'Request not found or unauthorized']);
            exit;
        }
        
        if ($request['status'] != 'pending') {
            echo json_encode(['error' => 'Only pending requests can be cancelled']);
            exit;
        }
        
        // Update status to rejected
        $update_stmt = $db->prepare("UPDATE pending_pay SET status = 'rejected', rejection_reason = 'Cancelled by requester' WHERE id = ?");
        $update_stmt->execute([$request_id]);
        
        echo json_encode(['success' => true, 'message' => 'Request cancelled successfully']);
        
    } catch (PDOException $e) {
        error_log("Error cancelling request: " . $e->getMessage());
        echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
    }
} else {
    echo json_encode(['error' => 'Invalid request method']);
}
?>