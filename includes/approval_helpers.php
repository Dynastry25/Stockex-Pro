<?php
/**
 * Approval Workflow Helper Functions
 *
 * This file contains helper functions for managing approval workflows
 * across different HR modules (leave requests, payroll, recruitment, etc.)
 */

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
 * @param int $workflow_id The workflow ID
 * @param string $new_status New status for the workflow
 * @param int $action_by User ID performing the action
 * @param string|null $action_reason Optional reason for the action
 * @return bool Success status
 */
function update_approval_workflow_status($workflow_id, $new_status, $action_by, $action_reason = null) {
    global $db;

    try {
        // Get current workflow info
        $workflow_stmt = $db->prepare("SELECT * FROM approval_workflows WHERE id = ?");
        $workflow_stmt->execute([$workflow_id]);
        $workflow = $workflow_stmt->fetch();

        if (!$workflow) {
            return false;
        }

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
?>