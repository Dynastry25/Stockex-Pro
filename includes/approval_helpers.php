<?php
/**
 * Approval Workflow Helper Functions
 *
 * This file contains helper functions for managing approval workflows
 * across different HR modules (leave requests, payroll, recruitment, etc.)
 */

// Define constants if they don't exist
if (!defined('WORKFLOW_LEAVE')) define('WORKFLOW_LEAVE', 'leave');
if (!defined('WORKFLOW_PAYROLL')) define('WORKFLOW_PAYROLL', 'payroll');
if (!defined('WORKFLOW_RECRUITMENT')) define('WORKFLOW_RECRUITMENT', 'recruitment');
if (!defined('WORKFLOW_PAYMENT')) define('WORKFLOW_PAYMENT', 'payment');

if (!defined('ENTITY_LEAVE_REQUEST')) define('ENTITY_LEAVE_REQUEST', 'leave_request');
if (!defined('ENTITY_PAYROLL')) define('ENTITY_PAYROLL', 'payroll');
if (!defined('ENTITY_RECRUITMENT')) define('ENTITY_RECRUITMENT', 'recruitment');
if (!defined('ENTITY_PAYMENT')) define('ENTITY_PAYMENT', 'payment_request');

if (!defined('WORKFLOW_PENDING')) define('WORKFLOW_PENDING', 'pending');
if (!defined('WORKFLOW_IN_PROGRESS')) define('WORKFLOW_IN_PROGRESS', 'in_progress');
if (!defined('WORKFLOW_COMPLETED')) define('WORKFLOW_COMPLETED', 'completed');
if (!defined('WORKFLOW_REJECTED')) define('WORKFLOW_REJECTED', 'rejected');

// Leave status constants
if (!defined('LEAVE_PENDING_HR')) define('LEAVE_PENDING_HR', 'pending_hr');
if (!defined('LEAVE_APPROVED_BY_HR')) define('LEAVE_APPROVED_BY_HR', 'approved_by_hr');
if (!defined('LEAVE_REJECTED_BY_HR')) define('LEAVE_REJECTED_BY_HR', 'rejected_by_hr');
if (!defined('LEAVE_PENDING_CEO')) define('LEAVE_PENDING_CEO', 'pending_ceo');
if (!defined('LEAVE_APPROVED_BY_CEO')) define('LEAVE_APPROVED_BY_CEO', 'approved_by_ceo');
if (!defined('LEAVE_REJECTED_BY_CEO')) define('LEAVE_REJECTED_BY_CEO', 'rejected_by_ceo');

/**
 * Create a new approval workflow
 *
 * @param string $workflow_type The type of workflow (WORKFLOW_LEAVE, WORKFLOW_PAYROLL, etc.)
 * @param string $entity_type The entity type (ENTITY_LEAVE_REQUEST, ENTITY_PAYROLL, etc.)
 * @param int $entity_id The ID of the entity being approved
 * @param int $initiated_by User ID who initiated the workflow
 * @param string $initial_status Initial status for the workflow
 * @return int|bool The workflow ID on success, false on failure
 */
function create_approval_workflow($workflow_type, $entity_type, $entity_id, $initiated_by, $initial_status) {
    global $db;

    try {
        // Determine if CEO approval is required based on workflow type and rules
        $ceo_required = 0;
        $finance_required = 0;

        // For leave requests, CEO approval might be required for certain conditions
        if ($workflow_type === WORKFLOW_LEAVE) {
            // Check if leave requires CEO approval (e.g., extended leave, executive positions)
            $leave_stmt = $db->prepare("
                SELECT lr.total_days, u.role
                FROM leave_requests lr
                JOIN users u ON lr.employee_id = u.id
                WHERE lr.id = ?
            ");
            $leave_stmt->execute([$entity_id]);
            $leave_info = $leave_stmt->fetch();

            if ($leave_info) {
                // CEO approval required for leaves > 10 days or for CEO/executive roles
                if ($leave_info['total_days'] > 10 || in_array($leave_info['role'], ['ceo', 'hr_manager'])) {
                    $ceo_required = 1;
                }
            }
        }

        // For payroll, finance processing is always required
        if ($workflow_type === WORKFLOW_PAYROLL) {
            $finance_required = 1;
        }

        $stmt = $db->prepare("
            INSERT INTO approval_workflows (
                workflow_type, entity_type, entity_id, initiated_by,
                current_status, hr_action_status, ceo_action_required,
                finance_action_required, created_at, updated_at
            ) VALUES (?, ?, ?, ?, ?, 'pending', ?, ?, NOW(), NOW())
        ");

        $stmt->execute([
            $workflow_type,
            $entity_type,
            $entity_id,
            $initiated_by,
            $initial_status,
            $ceo_required,
            $finance_required
        ]);

        return $db->lastInsertId();

    } catch (Exception $e) {
        error_log("Error creating approval workflow: " . $e->getMessage());
        return false;
    }
}

/**
 * Get approval workflow for an entity
 *
 * @param string $entity_type The entity type (ENTITY_LEAVE_REQUEST, ENTITY_PAYROLL, etc.)
 * @param int $entity_id The ID of the entity
 * @return array|null Workflow data or null if not found
 */
function get_approval_workflow($entity_type, $entity_id) {
    global $db;

    try {
        $stmt = $db->prepare("
            SELECT * FROM approval_workflows
            WHERE entity_type = ? AND entity_id = ?
            ORDER BY created_at DESC LIMIT 1
        ");

        $stmt->execute([$entity_type, $entity_id]);
        return $stmt->fetch();

    } catch (Exception $e) {
        error_log("Error getting approval workflow: " . $e->getMessage());
        return null;
    }
}

/**
 * Update approval workflow status
 *
 * @param string $entity_type The entity type
 * @param int $entity_id The entity ID
 * @param string $new_status New status for the workflow
 * @param int $action_by User ID performing the action
 * @param string|null $action_reason Optional reason for the action
 * @return bool Success status
 */
function update_approval_workflow_status($entity_type, $entity_id, $new_status, $action_by, $action_reason = null) {
    global $db;

    try {
        // Get current workflow info
        $workflow_stmt = $db->prepare("SELECT * FROM approval_workflows WHERE entity_type = ? AND entity_id = ?");
        $workflow_stmt->execute([$entity_type, $entity_id]);
        $workflow = $workflow_stmt->fetch();

        if (!$workflow) {
            return false;
        }

        $workflow_id = $workflow['id'];

        // Determine which action field to update based on user role and current status
        $update_fields = [];
        $update_values = [];

        // Get user role
        $user_stmt = $db->prepare("SELECT role FROM users WHERE id = ?");
        $user_stmt->execute([$action_by]);
        $user_role = $user_stmt->fetchColumn();

        // Update current status
        $update_fields[] = "current_status = ?";
        $update_values[] = $new_status;

        $update_fields[] = "updated_at = NOW()";
        $update_values[] = null; // Placeholder for NOW()

        // Handle HR actions
        if (in_array($user_role, ['hr_manager', 'hr_officer'])) {
            $update_fields[] = "hr_action_status = ?";
            $update_values[] = $new_status;

            $update_fields[] = "hr_action_by = ?";
            $update_values[] = $action_by;

            $update_fields[] = "hr_action_at = NOW()";
            $update_values[] = null;

            if ($action_reason) {
                $update_fields[] = "hr_action_reason = ?";
                $update_values[] = $action_reason;
            }

            // Update workflow status based on HR decision
            if ($new_status === 'approved' || $new_status === 'escalated') {
                $update_fields[] = "workflow_status = ?";
                $update_values[] = WORKFLOW_IN_PROGRESS;
            } elseif ($new_status === 'rejected') {
                $update_fields[] = "workflow_status = ?";
                $update_values[] = WORKFLOW_REJECTED;
            }
        }

        // Handle CEO actions
        elseif ($user_role === 'ceo') {
            $update_fields[] = "ceo_action_status = ?";
            $update_values[] = $new_status;

            $update_fields[] = "ceo_action_by = ?";
            $update_values[] = $action_by;

            $update_fields[] = "ceo_action_at = NOW()";
            $update_values[] = null;

            if ($action_reason) {
                $update_fields[] = "ceo_action_reason = ?";
                $update_values[] = $action_reason;
            }

            // Update workflow status based on CEO decision
            if ($new_status === 'approved') {
                $update_fields[] = "workflow_status = ?";
                $update_values[] = WORKFLOW_COMPLETED;
            } elseif ($new_status === 'rejected') {
                $update_fields[] = "workflow_status = ?";
                $update_values[] = WORKFLOW_REJECTED;
            }
        }

        // Handle Finance actions
        elseif (in_array($user_role, ['finance_officer', 'ceo'])) {
            $update_fields[] = "finance_action_status = ?";
            $update_values[] = $new_status;

            $update_fields[] = "finance_action_by = ?";
            $update_values[] = $action_by;

            $update_fields[] = "finance_action_at = NOW()";
            $update_values[] = null;

            if ($action_reason) {
                $update_fields[] = "finance_action_notes = ?";
                $update_values[] = $action_reason;
            }

            // For payroll, finance processing completion might finalize the workflow
            if ($workflow['workflow_type'] === WORKFLOW_PAYROLL && $new_status === 'processed') {
                $update_fields[] = "workflow_status = ?";
                $update_values[] = WORKFLOW_COMPLETED;
            }
        }

        // Remove null placeholders
        $update_values = array_filter($update_values, function($value) {
            return $value !== null;
        });

        $sql = "UPDATE approval_workflows SET " . implode(', ', $update_fields) . " WHERE id = ?";
        $update_values[] = $workflow_id;

        $stmt = $db->prepare($sql);
        return $stmt->execute($update_values);

    } catch (Exception $e) {
        error_log("Error updating approval workflow status: " . $e->getMessage());
        return false;
    }
}

/**
 * Check if a workflow requires CEO approval
 *
 * @param string $workflow_type The workflow type
 * @param int $entity_id The entity ID
 * @return bool Whether CEO approval is required
 */
function requires_ceo_approval($workflow_type, $entity_id) {
    global $db;

    try {
        if ($workflow_type === WORKFLOW_LEAVE) {
            // Check leave duration and user role
            $stmt = $db->prepare("
                SELECT lr.total_days, u.role
                FROM leave_requests lr
                JOIN users u ON lr.employee_id = u.id
                WHERE lr.id = ?
            ");
            $stmt->execute([$entity_id]);
            $result = $stmt->fetch();

            return $result && ($result['total_days'] > 10 || in_array($result['role'], ['ceo', 'hr_manager']));
        }

        return false;

    } catch (Exception $e) {
        error_log("Error checking CEO approval requirement: " . $e->getMessage());
        return false;
    }
}

/**
 * Get pending workflows for a user based on their role
 *
 * @param int $user_id The user ID
 * @param string $user_role The user role
 * @return array Array of pending workflows
 */
function get_pending_workflows_for_user($user_id, $user_role) {
    global $db;

    try {
        $workflows = [];

        if (in_array($user_role, ['hr_manager', 'hr_officer'])) {
            // HR users see workflows pending HR action
            $stmt = $db->prepare("
                SELECT aw.*, u.full_name as initiated_by_name
                FROM approval_workflows aw
                JOIN users u ON aw.initiated_by = u.id
                WHERE aw.workflow_status = 'in_progress'
                AND aw.hr_action_status = 'pending'
                ORDER BY aw.created_at DESC
            ");
            $stmt->execute();
            $workflows = $stmt->fetchAll();
        }

        elseif ($user_role === 'ceo') {
            // CEO sees workflows requiring CEO approval
            $stmt = $db->prepare("
                SELECT aw.*, u.full_name as initiated_by_name
                FROM approval_workflows aw
                JOIN users u ON aw.initiated_by = u.id
                WHERE aw.workflow_status = 'in_progress'
                AND aw.ceo_action_required = 1
                AND aw.ceo_action_status IS NULL
                ORDER BY aw.created_at DESC
            ");
            $stmt->execute();
            $workflows = $stmt->fetchAll();
        }

        elseif (in_array($user_role, ['finance_officer'])) {
            // Finance users see workflows requiring finance action
            $stmt = $db->prepare("
                SELECT aw.*, u.full_name as initiated_by_name
                FROM approval_workflows aw
                JOIN users u ON aw.initiated_by = u.id
                WHERE aw.workflow_status = 'in_progress'
                AND aw.finance_action_required = 1
                AND aw.finance_action_status IS NULL
                ORDER BY aw.created_at DESC
            ");
            $stmt->execute();
            $workflows = $stmt->fetchAll();
        }

        return $workflows;

    } catch (Exception $e) {
        error_log("Error getting pending workflows: " . $e->getMessage());
        return [];
    }
}

/**
 * Get workflow history for an entity
 *
 * @param string $entity_type The entity type
 * @param int $entity_id The entity ID
 * @return array Array of workflow history
 */
function get_workflow_history($entity_type, $entity_id) {
    global $db;

    try {
        $stmt = $db->prepare("
            SELECT 
                wh.*,
                u.full_name as action_by_name,
                u.role as action_by_role
            FROM workflow_history wh
            LEFT JOIN users u ON wh.action_by = u.id
            WHERE wh.entity_type = ? AND wh.entity_id = ?
            ORDER BY wh.action_date DESC
        ");

        $stmt->execute([$entity_type, $entity_id]);
        return $stmt->fetchAll();

    } catch (Exception $e) {
        error_log("Error getting workflow history: " . $e->getMessage());
        return [];
    }
}

/**
 * Log workflow action to history
 *
 * @param string $entity_type The entity type
 * @param int $entity_id The entity ID
 * @param string $action_type Action type (approve, reject, escalate, etc.)
 * @param int $action_by User ID performing the action
 * @param string|null $action_notes Optional notes about the action
 * @return bool Success status
 */
function log_workflow_action($entity_type, $entity_id, $action_type, $action_by, $action_notes = null) {
    global $db;

    try {
        $stmt = $db->prepare("
            INSERT INTO workflow_history (
                entity_type, entity_id, action_type, action_by, action_notes, action_date
            ) VALUES (?, ?, ?, ?, ?, NOW())
        ");

        return $stmt->execute([$entity_type, $entity_id, $action_type, $action_by, $action_notes]);

    } catch (Exception $e) {
        error_log("Error logging workflow action: " . $e->getMessage());
        return false;
    }
}

/**
 * Get summary of pending CEO approvals
 *
 * @return array Array with counts of pending approvals by type
 */
function get_ceo_pending_approvals() {
    global $db;

    try {
        $summary = [
            'leaves' => 0,
            'payroll' => 0,
            'recruitment' => 0,
            'targets' => 0,
            'total' => 0
        ];

        // Count pending leave approvals
        $stmt = $db->query("SELECT COUNT(*) FROM leave_requests WHERE requires_ceo_approval = 1 AND ceo_decision_status = 'pending_ceo'");
        $summary['leaves'] = $stmt->fetchColumn();

        // Count pending payroll approvals
        $stmt = $db->query("SELECT COUNT(*) FROM payroll WHERE status = 'pending_ceo_approval'");
        $summary['payroll'] = $stmt->fetchColumn();

        // Count pending recruitment approvals
        $stmt = $db->query("SELECT COUNT(*) FROM job_applications WHERE ceo_approval_status = 'pending_ceo'");
        $summary['recruitment'] = $stmt->fetchColumn();

        // Count pending target approvals
        $stmt = $db->query("SELECT COUNT(*) FROM performance_targets WHERE requires_ceo_approval = TRUE AND ceo_decision_status = 'pending_ceo'");
        $summary['targets'] = $stmt->fetchColumn();

        $summary['total'] = $summary['leaves'] + $summary['payroll'] + $summary['recruitment'] + $summary['targets'];

        return $summary;

    } catch (Exception $e) {
        error_log("Error getting CEO pending approvals summary: " . $e->getMessage());
        return [
            'leaves' => 0,
            'payroll' => 0,
            'recruitment' => 0,
            'targets' => 0,
            'total' => 0
        ];
    }
}

/**
 * Get detailed pending leave approvals for CEO
 *
 * @return array Array of pending leave requests
 */
function get_ceo_pending_leaves() {
    global $db;

    try {
        $stmt = $db->query("
            SELECT lr.*, 
                   CONCAT(e.first_name, ' ', e.last_name) as employee_name,
                   e.employee_id as employee_code,
                   d.name as department_name, 
                   jp.title as position_name,
                   lt.name as leave_type_name,
                   hr_user.full_name as hr_action_by_name,
                   lr.escalation_reason as escalation_reason,
                   lr.finality_reason as escalation_reason_alt
            FROM leave_requests lr
            JOIN users e ON lr.employee_id = e.id
            LEFT JOIN departments d ON e.department_id = d.id
            LEFT JOIN job_positions jp ON e.position_id = jp.id
            LEFT JOIN leave_types lt ON lr.leave_type_id = lt.id
            LEFT JOIN users hr_user ON lr.hr_action_by = hr_user.id
            WHERE lr.requires_ceo_approval = 1 
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
 * CEO approve or reject a payroll
 *
 * @param int $payroll_id The payroll ID
 * @param string $decision 'approve' or 'reject'
 * @param int $ceo_user_id The CEO user ID
 * @param string $reason Optional reason for rejection
 * @return array Result array with success and message
 */
function ceo_approve_payroll($payroll_id, $decision, $ceo_user_id, $reason = '') {
    global $db;
    
    try {
        $db->beginTransaction();
        
        // Get current payroll details
        $stmt = $db->prepare("
            SELECT p.*, CONCAT(u.first_name, ' ', u.last_name) as employee_name, u.employee_id
            FROM payroll p
            JOIN users u ON p.user_id = u.id
            WHERE p.id = ? AND p.status = 'pending_ceo_approval'
        ");
        $stmt->execute([$payroll_id]);
        $payroll = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$payroll) {
            return [
                'success' => false,
                'message' => 'Payroll not found or not pending CEO approval.'
            ];
        }
        
        // Update payroll status
        $new_status = ($decision === 'approve') ? 'ceo_approved' : 'rejected';
        
        $update_stmt = $db->prepare("
            UPDATE payroll 
            SET status = ?,
                ceo_approved_by = ?,
                ceo_approved_at = NOW(),
                ceo_rejection_reason = ?
            WHERE id = ?
        ");
        
        $rejection_reason = ($decision === 'reject') ? $reason : NULL;
        $update_stmt->execute([
            $new_status,
            $ceo_user_id,
            $rejection_reason,
            $payroll_id
        ]);
        
        // Log CEO activity
        $activity_type = ($decision === 'approve') ? 'ceo_payroll_approved' : 'ceo_payroll_rejected';
        $description = ($decision === 'approve') 
            ? "Payroll approved by CEO" 
            : "Payroll rejected by CEO: " . substr($reason, 0, 200);
        
        $activity_stmt = $db->prepare("
            INSERT INTO hr_activities (activity_type, entity_type, entity_id, description, performed_by, performed_at, created_at)
            VALUES (?, 'payroll', ?, ?, ?, NOW(), NOW())
        ");
        $activity_stmt->execute([$activity_type, $payroll_id, $description, $ceo_user_id]);
        
        // Update approval workflow if function exists
        if (function_exists('update_approval_workflow_status')) {
            $workflow_status = ($decision === 'approve') ? 'approved_by_ceo' : 'rejected_by_ceo';
            update_approval_workflow_status('payroll', $payroll_id, $workflow_status, $ceo_user_id);
        }
        
        $db->commit();
        
        return [
            'success' => true,
            'message' => "Payroll has been {$decision}d by CEO successfully."
        ];
        
    } catch (Exception $e) {
        $db->rollBack();
        return [
            'success' => false,
            'message' => 'Error processing CEO decision: ' . $e->getMessage()
        ];
    }
}

?>