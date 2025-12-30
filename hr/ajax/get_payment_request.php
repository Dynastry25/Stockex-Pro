<?php
require_once '../config/config.php';
require_once '../auth/auth_middleware.php';

// Only HR staff can access
require_hr_staff();

$db = getDBConnection();

if (isset($_GET['request_id'])) {
    $request_id = (int)$_GET['request_id'];
    
    try {
        // First, get the basic request
        $stmt = $db->prepare("SELECT * FROM pending_pay WHERE id = ?");
        $stmt->execute([$request_id]);
        $request = $stmt->fetch();
        
        if (!$request) {
            echo json_encode(['error' => 'Request not found']);
            exit;
        }
        
        // Get ledger type description
        $ledger_stmt = $db->prepare("SELECT description FROM ledger_types WHERE code = ?");
        $ledger_stmt->execute([$request['pay_to_type']]);
        $ledger = $ledger_stmt->fetch();
        $request['pay_to_desc'] = $ledger['description'] ?? $request['pay_to_type'];
        
        // Get user information
        $user_stmt = $db->prepare("SELECT username, full_name FROM users WHERE id = ?");
        
        // Requested by
        $user_stmt->execute([$request['requested_by']]);
        $requester = $user_stmt->fetch();
        $request['requested_by_name'] = $requester['username'] ?? '';
        $request['requested_by_fullname'] = $requester['full_name'] ?? '';
        
        // CEO approved by
        if ($request['ceo_approved_by']) {
            $user_stmt->execute([$request['ceo_approved_by']]);
            $ceo = $user_stmt->fetch();
            $request['ceo_approved_by_name'] = $ceo['username'] ?? '';
            $request['ceo_approved_by_fullname'] = $ceo['full_name'] ?? '';
        } else {
            $request['ceo_approved_by_name'] = '';
            $request['ceo_approved_by_fullname'] = '';
        }
        
        // Finance approved by
        if ($request['finance_approved_by']) {
            $user_stmt->execute([$request['finance_approved_by']]);
            $finance = $user_stmt->fetch();
            $request['finance_approved_by_name'] = $finance['username'] ?? '';
            $request['finance_approved_by_fullname'] = $finance['full_name'] ?? '';
        } else {
            $request['finance_approved_by_name'] = '';
            $request['finance_approved_by_fullname'] = '';
        }
        
        // Paid by
        if ($request['paid_by']) {
            $user_stmt->execute([$request['paid_by']]);
            $payer = $user_stmt->fetch();
            $request['paid_by_name'] = $payer['username'] ?? '';
            $request['paid_by_fullname'] = $payer['full_name'] ?? '';
        } else {
            $request['paid_by_name'] = '';
            $request['paid_by_fullname'] = '';
        }
        
        header('Content-Type: application/json');
        echo json_encode($request);
        
    } catch (PDOException $e) {
        error_log("Error fetching request: " . $e->getMessage());
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
    }
} else {
    echo json_encode(['error' => 'No request ID provided']);
}
?>