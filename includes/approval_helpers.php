<?php
/**
 * Approval Workflow Helper Functions
 * 
 * This file contains all helper functions for managing approval workflows
 * across HR modules including leaves, payroll, recruitment, targets, and employee changes.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../auth/auth_middleware.php';

// ===============================================
// APPROVAL WORKFLOW CORE FUNCTIONS
// ===============================================

/**
 * Create a new approval workflow record
 * 
 * @param string $workflow_type - leave, payroll, recruitment, target, employee_change
 * @param string $entity_type - leave_request, payroll, job_application, etc
 * @param int $entity_id - ID of the entity being approved
 * @param int $initiated_by - User ID who started the workflow
 * @param string $initial_status - Initial status of the workflow
 * @return int|false - Workflow ID on success, false on failure
 */
function create_approval_workflow($workflow_type, $entity_type, $entity_id, $initiated_by, $initial_status) {
    try {
        $db = getDBConnection();
        
        $stmt = $db->prepare("
            INSERT INTO approval_workflows (
                workflow_type, entity_type, entity_id, initiated_by, 
                current_status, workflow_status
            ) VALUES (?, ?, ?, ?, ?, 'in_progress')
        ");
        
        if ($stmt->execute([$workflow_type, $entity_type, $entity_id, $initiated_by, $initial_status])) {
            return $db->lastInsertId();
        }
        
        return false;
    } catch (Exception $e) {
        error_log("Error creating approval workflow: " . $e->getMessage());
        return false;
    }
}

/**
 * Update approval workflow status
 */
function update_approval_workflow_status($workflow_id, $new_status, $stage = null, $action_by = null, $reason = null) {
    try {
        $db = getDBConnection();
        
        // Update current status
        $stmt = $db->prepare("
            UPDATE approval_workflows 
            SET current_status = ?, updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$new_status, $workflow_id]);
        
        // Update specific stage if provided
        if ($stage && $action_by) {
            switch ($stage) {
                case 'hr':
                    $stmt = $db->prepare("
                        UPDATE approval_workflows 
                        SET hr_action_status = ?, hr_action_by = ?, 
                            hr_action_at = NOW(), hr_action_reason = ?
                        WHERE id = ?
                    ");
                    $stmt->execute([
                        ($new_status === 'escalated_to_ceo') ? 'escalated' : 
                        (($new_status === 'approved_by_hr') ? 'approved' : 'rejected'),
                        $action_by, $reason, $workflow_id
                    ]);
                    break;
                    
                case 'ceo':
                    $stmt = $db->prepare("
                        UPDATE approval_workflows 
                        SET ceo_action_status = ?, ceo_action_by = ?, 
                            ceo_action_at = NOW(), ceo_action_reason = ?,
                            workflow_status = ?
                        WHERE id = ?
                    ");
                    $workflow_complete = in_array($new_status, ['ceo_approved', 'ceo_rejected']) ? 'completed' : 'in_progress';
                    $ceo_status = ($new_status === 'ceo_approved') ? 'approved' : 'rejected';
                    $stmt->execute([$ceo_status, $action_by, $reason, $workflow_complete, $workflow_id]);
                    break;
                    
                case 'finance':
                    $stmt = $db->prepare("
                        UPDATE approval_workflows 
                        SET finance_action_status = 'processed', finance_action_by = ?, 
                            finance_action_at = NOW(), finance_action_notes = ?,
                            workflow_status = 'completed'
                        WHERE id = ?
                    ");
                    $stmt->execute([$action_by, $reason, $workflow_id]);
                    break;
            }
        }
        
        return true;
    } catch (Exception $e) {
        error_log("Error updating approval workflow: " . $e->getMessage());
        return false;
    }
}

/**
 * Get workflow by entity
 */
function get_approval_workflow($entity_type, $entity_id) {
    try {
        $db = getDBConnection();
        
        $stmt = $db->prepare("
            SELECT aw.*, 
                   u1.full_name as initiated_by_name,
                   u2.full_name as hr_action_by_name,
                   u3.full_name as ceo_action_by_name,
                   u4.full_name as finance_action_by_name
            FROM approval_workflows aw
            LEFT JOIN users u1 ON aw.initiated_by = u1.id
            LEFT JOIN users u2 ON aw.hr_action_by = u2.id  
            LEFT JOIN users u3 ON aw.ceo_action_by = u3.id
            LEFT JOIN users u4 ON aw.finance_action_by = u4.id
            WHERE aw.entity_type = ? AND aw.entity_id = ?
            ORDER BY aw.created_at DESC
            LIMIT 1
        ");
        
        $stmt->execute([$entity_type, $entity_id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Error getting approval workflow: " . $e->getMessage());
        return false;
    }
}

// ===============================================
// LEAVE APPROVAL FUNCTIONS
// ===============================================

/**
 * HR finalizes leave (approve/reject without CEO)
 */
function hr_finalize_leave($leave_id, $decision, $hr_user_id, $reason = '') {
    try {
        $db = getDBConnection();
        
        // Validate HR user has permission
        if (!check_session_permission('hr_manager') && !check_session_permission('hr_officer')) {
            return ['success' => false, 'message' => 'Access denied: HR permission required'];
        }
        
        $db->beginTransaction();
        
        // Update leave request
        $status = ($decision === 'approve') ? 'approved' : 'rejected';
        $stmt = $db->prepare("
            UPDATE leave_requests 
            SET status = ?, finalized_by_hr_at = NOW(), finality_reason = ?, 
                approved_by = ?, approved_at = NOW(),
                rejection_reason = CASE WHEN ? = 'rejected' THEN ? ELSE rejection_reason END
            WHERE id = ?
        ");
        
        $stmt->execute([
            $status, $reason, $hr_user_id, 
            $status, $reason, $leave_id
        ]);
        
        // Update/create workflow
        $workflow = get_approval_workflow('leave_request', $leave_id);
        if ($workflow) {
            update_approval_workflow_status(
                $workflow['id'], 
                "finalized_by_hr_as_{$status}", 
                'hr', 
                $hr_user_id, 
                $reason
            );
        } else {
            create_approval_workflow(
                'leave', 'leave_request', $leave_id, $hr_user_id, 
                "finalized_by_hr_as_{$status}"
            );
        }
        
        // Log HR activity
        $activity_stmt = $db->prepare("
            INSERT INTO hr_activities (activity_type, entity_type, entity_id, description, performed_by)
            VALUES (?, 'leave', ?, ?, ?)
        ");
        $activity_type = ($status === 'approved') ? 'leave_approved' : 'leave_rejected';
        $description = "Leave {$status} by HR" . ($reason ? ": {$reason}" : '');
        $activity_stmt->execute([$activity_type, $leave_id, $description, $hr_user_id]);
        
        $db->commit();
        return ['success' => true, 'message' => "Leave {$status} successfully"];
        
    } catch (Exception $e) {
        $db->rollback();
        error_log("Error finalizing leave: " . $e->getMessage());
        return ['success' => false, 'message' => 'Error processing leave decision'];
    }
}

/**
 * HR escalates leave to CEO
 */
function hr_escalate_leave_to_ceo($leave_id, $hr_user_id, $escalation_reason) {
    try {
        $db = getDBConnection();
        
        // Validate HR user has permission
        if (!check_session_permission('hr_manager') && !check_session_permission('hr_officer')) {
            return ['success' => false, 'message' => 'Access denied: HR permission required'];
        }
        
        $db->beginTransaction();
        
        // Update leave request for CEO approval
        $stmt = $db->prepare("
            UPDATE leave_requests 
            SET requires_ceo_approval = TRUE, 
                ceo_decision_status = 'pending_ceo',
                finalized_by_hr_at = NOW(),
                finality_reason = ?
            WHERE id = ?
        ");
        $stmt->execute([$escalation_reason, $leave_id]);
        
        // Update/create workflow
        $workflow = get_approval_workflow('leave_request', $leave_id);
        if ($workflow) {
            update_approval_workflow_status(
                $workflow['id'], 
                'escalated_to_ceo', 
                'hr', 
                $hr_user_id, 
                $escalation_reason
            );
            
            // Mark CEO action required
            $stmt = $db->prepare("
                UPDATE approval_workflows 
                SET ceo_action_required = TRUE
                WHERE id = ?
            ");
            $stmt->execute([$workflow['id']]);
        } else {
            $workflow_id = create_approval_workflow(
                'leave', 'leave_request', $leave_id, $hr_user_id, 'escalated_to_ceo'
            );
            
            if ($workflow_id) {
                $stmt = $db->prepare("
                    UPDATE approval_workflows 
                    SET ceo_action_required = TRUE
                    WHERE id = ?
                ");
                $stmt->execute([$workflow_id]);
            }
        }
        
        // Log activity
        $activity_stmt = $db->prepare("
            INSERT INTO hr_activities (activity_type, entity_type, entity_id, description, performed_by)
            VALUES ('leave_request', 'leave', ?, ?, ?)
        ");
        $activity_stmt->execute([
            $leave_id, 
            "Leave escalated to CEO: {$escalation_reason}", 
            $hr_user_id
        ]);
        
        $db->commit();
        return ['success' => true, 'message' => 'Leave escalated to CEO for approval'];
        
    } catch (Exception $e) {
        $db->rollback();
        error_log("Error escalating leave: " . $e->getMessage());
        return ['success' => false, 'message' => 'Error escalating leave to CEO'];
    }
}

/**
 * CEO approves/rejects escalated leave
 */
function ceo_approve_leave($leave_id, $decision, $ceo_user_id, $reason = '') {
    try {
        $db = getDBConnection();
        
        // Validate CEO permission and mandate
        if (!check_session_permission('ceo')) {
            return ['success' => false, 'message' => 'Access denied: CEO permission required'];
        }
        
        if (!check_mandate_enabled()) {
            return ['success' => false, 'message' => 'Mandate not enabled for approval authority'];
        }
        
        $db->beginTransaction();
        
        // Update leave request with CEO decision
        $final_status = ($decision === 'approve') ? 'approved' : 'rejected';
        $ceo_status = ($decision === 'approve') ? 'ceo_approved' : 'ceo_rejected';
        
        $stmt = $db->prepare("
            UPDATE leave_requests 
            SET status = ?, 
                ceo_decision_status = ?,
                ceo_approved_by = ?, 
                ceo_approved_at = NOW(),
                approved_by = ?,
                approved_at = NOW(),
                ceo_rejection_reason = CASE WHEN ? = 'ceo_rejected' THEN ? ELSE ceo_rejection_reason END
            WHERE id = ? AND requires_ceo_approval = TRUE
        ");
        
        $stmt->execute([
            $final_status, $ceo_status, $ceo_user_id, $ceo_user_id,
            $ceo_status, $reason, $leave_id
        ]);
        
        if ($stmt->rowCount() === 0) {
            throw new Exception("Leave not found or not pending CEO approval");
        }
        
        // Update workflow
        $workflow = get_approval_workflow('leave_request', $leave_id);
        if ($workflow) {
            update_approval_workflow_status(
                $workflow['id'], 
                $ceo_status, 
                'ceo', 
                $ceo_user_id, 
                $reason
            );
        }
        
        // Log activity
        $activity_stmt = $db->prepare("
            INSERT INTO hr_activities (activity_type, entity_type, entity_id, description, performed_by)
            VALUES (?, 'leave', ?, ?, ?)
        ");
        $activity_type = ($final_status === 'approved') ? 'leave_approved' : 'leave_rejected';
        $description = "Leave {$final_status} by CEO" . ($reason ? ": {$reason}" : '');
        $activity_stmt->execute([$activity_type, $leave_id, $description, $ceo_user_id]);
        
        $db->commit();
        return ['success' => true, 'message' => "Leave {$final_status} by CEO"];
        
    } catch (Exception $e) {
        $db->rollback();
        error_log("Error processing CEO leave decision: " . $e->getMessage());
        return ['success' => false, 'message' => 'Error processing CEO decision'];
    }
}

// ===============================================
// PAYROLL APPROVAL FUNCTIONS
// ===============================================

/**
 * HR submits payroll for CEO approval
 */
function hr_submit_payroll_for_approval($payroll_ids, $hr_user_id, $submission_reason) {
    try {
        $db = getDBConnection();
        
        if (!check_session_permission('hr_manager')) {
            return ['success' => false, 'message' => 'Access denied: HR Manager permission required'];
        }
        
        if (empty($payroll_ids)) {
            return ['success' => false, 'message' => 'No payroll selected'];
        }
        
        $db->beginTransaction();
        
        $submitted_count = 0;
        foreach ($payroll_ids as $payroll_id) {
            // Update payroll status
            $stmt = $db->prepare("
                UPDATE payroll 
                SET status = 'pending_ceo_approval', 
                    submitted_by_hr_at = NOW(),
                    submission_reason = ?
                WHERE id = ? AND status IN ('draft', 'calculated')
            ");
            
            if ($stmt->execute([$submission_reason, $payroll_id])) {
                if ($stmt->rowCount() > 0) {
                    $submitted_count++;
                    
                    // Create workflow
                    $workflow_id = create_approval_workflow(
                        'payroll', 'payroll', $payroll_id, $hr_user_id, 'pending_ceo_approval'
                    );
                    
                    if ($workflow_id) {
                        $stmt = $db->prepare("
                            UPDATE approval_workflows 
                            SET ceo_action_required = TRUE
                            WHERE id = ?
                        ");
                        $stmt->execute([$workflow_id]);
                    }
                    
                    // Log activity
                    $activity_stmt = $db->prepare("
                        INSERT INTO hr_activities (activity_type, entity_type, entity_id, description, performed_by)
                        VALUES ('payroll_processed', 'payroll', ?, ?, ?)
                    ");
                    $activity_stmt->execute([
                        $payroll_id, 
                        "Payroll submitted for CEO approval: {$submission_reason}", 
                        $hr_user_id
                    ]);
                }
            }
        }
        
        $db->commit();
        
        if ($submitted_count > 0) {
            return ['success' => true, 'message' => "{$submitted_count} payroll(s) submitted for CEO approval"];
        } else {
            return ['success' => false, 'message' => 'No payroll was submitted (may already be in process)'];
        }
        
    } catch (Exception $e) {
        $db->rollback();
        error_log("Error submitting payroll: " . $e->getMessage());
        return ['success' => false, 'message' => 'Error submitting payroll for approval'];
    }
}

/**
 * CEO approves/rejects payroll
 */
function ceo_approve_payroll($payroll_id, $decision, $ceo_user_id, $reason = '') {
    try {
        $db = getDBConnection();
        
        // Validate CEO permission and mandate
        if (!check_session_permission('ceo')) {
            return ['success' => false, 'message' => 'Access denied: CEO permission required'];
        }
        
        if (!check_mandate_enabled()) {
            return ['success' => false, 'message' => 'Mandate not enabled for approval authority'];
        }
        
        $db->beginTransaction();
        
        $new_status = ($decision === 'approve') ? 'ceo_approved' : 'rejected';
        
        // Update payroll
        $stmt = $db->prepare("
            UPDATE payroll 
            SET status = ?, 
                ceo_approved_by = ?, 
                ceo_approved_at = NOW(),
                ceo_rejection_reason = CASE WHEN ? = 'rejected' THEN ? ELSE ceo_rejection_reason END
            WHERE id = ? AND status = 'pending_ceo_approval'
        ");
        
        $stmt->execute([$new_status, $ceo_user_id, $new_status, $reason, $payroll_id]);
        
        if ($stmt->rowCount() === 0) {
            throw new Exception("Payroll not found or not pending CEO approval");
        }
        
        // Update workflow
        $workflow = get_approval_workflow('payroll', $payroll_id);
        if ($workflow) {
            update_approval_workflow_status(
                $workflow['id'], 
                $new_status, 
                'ceo', 
                $ceo_user_id, 
                $reason
            );
            
            // If approved, mark finance action required
            if ($decision === 'approve') {
                $stmt = $db->prepare("
                    UPDATE approval_workflows 
                    SET finance_action_required = TRUE
                    WHERE id = ?
                ");
                $stmt->execute([$workflow['id']]);
            }
        }
        
        // Log activity
        $activity_stmt = $db->prepare("
            INSERT INTO hr_activities (activity_type, entity_type, entity_id, description, performed_by)
            VALUES ('payroll_processed', 'payroll', ?, ?, ?)
        ");
        $decision_text = ($decision === 'approve') ? 'approved' : 'rejected';
        $description = "Payroll {$decision_text} by CEO" . ($reason ? ": {$reason}" : '');
        $activity_stmt->execute([$payroll_id, $description, $ceo_user_id]);
        
        $db->commit();
        return ['success' => true, 'message' => "Payroll {$decision_text} by CEO"];
        
    } catch (Exception $e) {
        $db->rollback();
        error_log("Error processing CEO payroll decision: " . $e->getMessage());
        return ['success' => false, 'message' => 'Error processing CEO decision'];
    }
}

// ===============================================
// CEO DASHBOARD FUNCTIONS
// ===============================================

/**
 * Get CEO pending approvals summary
 */
function get_ceo_pending_approvals() {
    try {
        $db = getDBConnection();
        
        $approvals = [];
        
        // Pending leave approvals
        $stmt = $db->query("
            SELECT COUNT(*) as count 
            FROM leave_requests 
            WHERE requires_ceo_approval = TRUE AND ceo_decision_status = 'pending_ceo'
        ");
        $approvals['leaves'] = $stmt->fetch()['count'];
        
        // Pending payroll approvals  
        $stmt = $db->query("
            SELECT COUNT(*) as count 
            FROM payroll 
            WHERE status = 'pending_ceo_approval'
        ");
        $approvals['payroll'] = $stmt->fetch()['count'];
        
        // Pending recruitment approvals
        $stmt = $db->query("
            SELECT COUNT(*) as count 
            FROM job_applications 
            WHERE ceo_approval_status = 'pending_ceo'
        ");
        $approvals['recruitment'] = $stmt->fetch()['count'];
        
        // Pending target approvals
        $stmt = $db->query("
            SELECT COUNT(*) as count 
            FROM performance_targets 
            WHERE requires_ceo_approval = TRUE AND ceo_decision_status = 'pending_ceo'
        ");
        $approvals['targets'] = $stmt->fetch()['count'];
        
        $approvals['total'] = $approvals['leaves'] + $approvals['payroll'] + 
                             $approvals['recruitment'] + $approvals['targets'];
        
        return $approvals;
        
    } catch (Exception $e) {
        error_log("Error getting CEO pending approvals: " . $e->getMessage());
        return ['leaves' => 0, 'payroll' => 0, 'recruitment' => 0, 'targets' => 0, 'total' => 0];
    }
}

/**
 * Get CEO pending leave approvals with details
 */
function get_ceo_pending_leaves() {
    try {
        $db = getDBConnection();
        
        $stmt = $db->query("
            SELECT lr.*, CONCAT(e.first_name, ' ', e.last_name) as employee_name, e.employee_id as employee_code,
                   lt.name as leave_type_name, d.name as department_name,
                   hr_user.full_name as escalated_by_name
            FROM leave_requests lr
            JOIN employees e ON lr.employee_id = e.id
            JOIN leave_types lt ON lr.leave_type_id = lt.id
            JOIN departments d ON e.department_id = d.id
            LEFT JOIN users hr_user ON lr.approved_by = hr_user.id
            WHERE lr.requires_ceo_approval = TRUE 
              AND lr.ceo_decision_status = 'pending_ceo'
            ORDER BY lr.finalized_by_hr_at ASC
        ");
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
        
    } catch (Exception $e) {
        error_log("Error getting CEO pending leaves: " . $e->getMessage());
        return [];
    }
}

/**
 * Get finance visible payroll (CEO approved only)
 */
function get_finance_visible_payroll($filters = []) {
    try {
        $db = getDBConnection();
        
        $where_conditions = ["p.status IN ('ceo_approved', 'ready_for_payment', 'paid')"];
        $params = [];
        
        // Add filters
        if (!empty($filters['period_start'])) {
            $where_conditions[] = "p.pay_period_start >= ?";
            $params[] = $filters['period_start'];
        }
        
        if (!empty($filters['period_end'])) {
            $where_conditions[] = "p.pay_period_end <= ?";
            $params[] = $filters['period_end'];
        }
        
        if (!empty($filters['status'])) {
            $where_conditions = ["p.status = ?"]; // Override to specific status
            $params = [$filters['status']];
        }
        
        $where_clause = implode(' AND ', $where_conditions);
        
        $stmt = $db->prepare("
            SELECT p.*, e.full_name as employee_name, e.employee_code,
                   ceo_user.full_name as ceo_approved_by_name,
                   finance_user.full_name as processed_by_name
            FROM payroll p
            JOIN employees e ON p.employee_id = e.id
            LEFT JOIN users ceo_user ON p.ceo_approved_by = ceo_user.id
            LEFT JOIN users finance_user ON p.finance_processed_by = finance_user.id
            WHERE {$where_clause}
            ORDER BY p.ceo_approved_at DESC, p.pay_period_start DESC
        ");
        
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
        
    } catch (Exception $e) {
        error_log("Error getting finance visible payroll: " . $e->getMessage());
        return [];
    }
}

// ===============================================
// UTILITY FUNCTIONS
// ===============================================

/**
 * Get approval setting value
 */
function get_approval_setting($key, $default = null) {
    try {
        $db = getDBConnection();
        
        $stmt = $db->prepare("
            SELECT setting_value, setting_type 
            FROM approval_settings 
            WHERE setting_key = ? AND is_active = TRUE
        ");
        $stmt->execute([$key]);
        $setting = $stmt->fetch();
        
        if (!$setting) {
            return $default;
        }
        
        switch ($setting['setting_type']) {
            case 'boolean':
                return filter_var($setting['setting_value'], FILTER_VALIDATE_BOOLEAN);
            case 'integer':
                return (int)$setting['setting_value'];
            case 'json':
                return json_decode($setting['setting_value'], true);
            default:
                return $setting['setting_value'];
        }
        
    } catch (Exception $e) {
        error_log("Error getting approval setting: " . $e->getMessage());
        return $default;
    }
}

/**
 * Check if user can perform approval action
 */
function can_perform_approval($action_type, $user_role = null) {
    if (!$user_role) {
        $user_role = get_current_user_role();
    }
    
    $permissions = [
        'hr_finalize_leave' => ['hr_manager', 'hr_officer'],
        'hr_escalate_leave' => ['hr_manager', 'hr_officer'],
        'hr_submit_payroll' => ['hr_manager'],
        'ceo_approve_leave' => ['ceo'],
        'ceo_approve_payroll' => ['ceo'],
        'ceo_approve_recruitment' => ['ceo'],
        'finance_process_payment' => ['finance_officer']
    ];
    
    if (!isset($permissions[$action_type])) {
        return false;
    }
    
    return in_array($user_role, $permissions[$action_type]) || $user_role === 'system_admin';
}
?>