<?php
require_once '../../config/config.php';
require_once '../../auth/auth_middleware.php';

// Only HR staff can access
require_hr_staff();

$db = getDBConnection();

if (isset($_GET['request_id'])) {
    $request_id = (int)$_GET['request_id'];
    
    try {
        // Get request details
        $stmt = $db->prepare("SELECT * FROM pending_pay WHERE id = ?");
        $stmt->execute([$request_id]);
        $request = $stmt->fetch();
        
        if (!$request) {
            echo json_encode(['error' => 'Request not found']);
            exit;
        }
        
        // Get user information
        $user_stmt = $db->prepare("SELECT username, full_name FROM users WHERE id = ?");
        
        // Calculate progress
        $progress = 0;
        $next_step = '';
        $estimated_completion = 'N/A';
        
        if ($request['status'] == 'pending') {
            $progress = 0;
            $next_step = 'CEO Approval';
            $estimated_completion = '2-3 business days';
        } elseif ($request['status'] == 'approved_ceo') {
            $progress = 50;
            $next_step = 'Finance Approval';
            $estimated_completion = '1-2 business days';
        } elseif ($request['status'] == 'approved_finance') {
            $progress = 75;
            $next_step = 'Payment Processing';
            $estimated_completion = '1 business day';
        } elseif ($request['status'] == 'paid') {
            $progress = 100;
            $next_step = 'Completed';
            $estimated_completion = 'Completed';
        } elseif ($request['status'] == 'rejected') {
            $progress = 0;
            $next_step = 'Request Rejected';
            $estimated_completion = 'N/A';
        }
        
        // Build timeline steps
        $timeline = [
            'request_no' => $request['request_no'],
            'progress' => $progress,
            'next_step' => $next_step,
            'estimated_completion' => $estimated_completion,
            'steps' => [
                'created' => [
                    'completed' => true,
                    'date' => $request['requested_at'],
                    'by' => '',
                    'current' => false
                ],
                'ceo_approval' => [
                    'completed' => !empty($request['ceo_approved_at']),
                    'date' => $request['ceo_approved_at'],
                    'by' => '',
                    'notes' => $request['rejection_reason'] ?? '',
                    'current' => $request['status'] == 'pending'
                ],
                'finance_approval' => [
                    'completed' => !empty($request['finance_approved_at']),
                    'date' => $request['finance_approved_at'],
                    'by' => '',
                    'notes' => '',
                    'current' => $request['status'] == 'approved_ceo'
                ],
                'payment' => [
                    'completed' => !empty($request['paid_at']),
                    'date' => $request['paid_at'],
                    'by' => '',
                    'current' => $request['status'] == 'approved_finance'
                ]
            ]
        ];
        
        // Get user names for each step
        if ($request['requested_by']) {
            $user_stmt->execute([$request['requested_by']]);
            $user = $user_stmt->fetch();
            $timeline['steps']['created']['by'] = $user['full_name'] ?? $user['username'] ?? 'Unknown';
        }
        
        if ($request['ceo_approved_by']) {
            $user_stmt->execute([$request['ceo_approved_by']]);
            $user = $user_stmt->fetch();
            $timeline['steps']['ceo_approval']['by'] = $user['full_name'] ?? $user['username'] ?? 'Unknown';
        }
        
        if ($request['finance_approved_by']) {
            $user_stmt->execute([$request['finance_approved_by']]);
            $user = $user_stmt->fetch();
            $timeline['steps']['finance_approval']['by'] = $user['full_name'] ?? $user['username'] ?? 'Unknown';
        }
        
        if ($request['paid_by']) {
            $user_stmt->execute([$request['paid_by']]);
            $user = $user_stmt->fetch();
            $timeline['steps']['payment']['by'] = $user['full_name'] ?? $user['username'] ?? 'Unknown';
        }
        
        header('Content-Type: application/json');
        echo json_encode($timeline);
        
    } catch (PDOException $e) {
        error_log("Error tracking request: " . $e->getMessage());
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
    }
} else {
    echo json_encode(['error' => 'No request ID provided']);
}
?>