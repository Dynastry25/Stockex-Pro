<?php
// /finance/budget_delete.php - Delete Budget and All Related Data
require_once '../config/config.php';
require_once '../auth/auth_middleware.php';

// Security headers
header("X-Frame-Options: DENY");
header("X-Content-Type-Options: nosniff");
header("X-XSS-Protection: 1; mode=block");
header("Referrer-Policy: strict-origin-when-cross-origin");

require_finance_officer();

// CSRF Protection
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if ID is provided
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    $_SESSION['error_message'] = "Invalid budget ID provided.";
    header('Location: budget.php');
    exit;
}

$budget_id = (int)$_GET['id'];
$db = getDBConnection();

// Get budget details for confirmation
$stmt = $db->prepare("SELECT budget_code, fiscal_year, fiscal_period, status FROM budgets WHERE id = ?");
$stmt->execute([$budget_id]);
$budget = $stmt->fetch();

if (!$budget) {
    $_SESSION['error_message'] = "Budget not found.";
    header('Location: budget.php');
    exit;
}

// Check if user has permission to delete
$user_role = $_SESSION['role'] ?? '';
$allowed_roles = ['system_admin', 'finance_officer', 'finance_manager'];

if (!in_array($user_role, $allowed_roles)) {
    $_SESSION['error_message'] = "You don't have permission to delete budgets.";
    header('Location: budget.php');
    exit;
}

// Check if budget is in a deletable state (can't delete approved/active/closed budgets)
$deletable_statuses = ['draft', 'pending', 'rejected'];
if (!in_array($budget['status'], $deletable_statuses) && $user_role !== 'system_admin') {
    $_SESSION['error_message'] = "Cannot delete a budget with status: " . $budget['status'] . ". Only draft, pending, or rejected budgets can be deleted.";
    header('Location: budget.php?view=' . $budget_id);
    exit;
}

// Handle confirmation
if (isset($_POST['confirm_delete']) && isset($_POST['csrf_token']) && $_POST['csrf_token'] === $_SESSION['csrf_token']) {
    try {
        $db->beginTransaction();
        
        // Log the deletion for audit trail
        $log_stmt = $db->prepare("
            INSERT INTO budget_history (
                budget_id, action, notes, performed_by, performed_by_username, created_at
            ) VALUES (?, 'delete', ?, ?, ?, NOW())
        ");
        $log_stmt->execute([
            $budget_id,
            "Budget deleted: {$budget['budget_code']} - {$budget['fiscal_year']} ({$budget['fiscal_period']})",
            $_SESSION['user_id'] ?? null,
            $_SESSION['username'] ?? 'system'
        ]);
        
        // Delete monitoring records (these are linked to budget_id)
        $stmt = $db->prepare("DELETE FROM budget_monitoring WHERE budget_id = ?");
        $stmt->execute([$budget_id]);
        
        // Delete goals (these are linked to budget_id)
        $stmt = $db->prepare("DELETE FROM budget_goals WHERE budget_id = ?");
        $stmt->execute([$budget_id]);
        
        // Delete categories (these are linked to budget_id)
        $stmt = $db->prepare("DELETE FROM budget_categories WHERE budget_id = ?");
        $stmt->execute([$budget_id]);
        
        // Delete history (these are linked to budget_id)
        $stmt = $db->prepare("DELETE FROM budget_history WHERE budget_id = ?");
        $stmt->execute([$budget_id]);
        
        // Finally, delete the budget itself
        $stmt = $db->prepare("DELETE FROM budgets WHERE id = ?");
        $stmt->execute([$budget_id]);
        
        $db->commit();
        
        $_SESSION['success_message'] = "Budget {$budget['budget_code']} and all associated data (categories, goals, monitoring, history) have been deleted successfully.";
        header('Location: budget.php');
        exit;
        
    } catch (PDOException $e) {
        $db->rollBack();
        error_log("Budget deletion error: " . $e->getMessage());
        $_SESSION['error_message'] = "Error deleting budget: " . $e->getMessage();
        header('Location: budget.php?view=' . $budget_id);
        exit;
    }
}

// If user clicked "Delete" from the view page but didn't confirm yet
if (!isset($_POST['confirm_delete'])) {
    // Show confirmation page
    $page_title = 'Delete Budget';
    include '../includes/header.php';
    ?>
    <style>
        .delete-container {
            max-width: 600px;
            margin: 40px auto;
            padding: 30px;
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.1);
            text-align: center;
        }
        .delete-icon {
            font-size: 64px;
            color: #dc3545;
            margin-bottom: 20px;
        }
        .delete-title {
            font-size: 24px;
            font-weight: 700;
            color: #1f2937;
            margin-bottom: 10px;
        }
        .delete-warning {
            color: #dc3545;
            font-weight: 600;
            margin-bottom: 20px;
        }
        .delete-details {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 20px;
            text-align: left;
            margin: 20px 0;
        }
        .delete-details table {
            width: 100%;
            font-size: 14px;
        }
        .delete-details td {
            padding: 6px 10px;
        }
        .delete-details .label {
            font-weight: 600;
            color: #6b7280;
        }
        .delete-details .value {
            color: #1f2937;
        }
        .delete-actions {
            display: flex;
            gap: 12px;
            justify-content: center;
            margin-top: 24px;
        }
        .btn-cancel {
            padding: 10px 30px;
            background: #6b7280;
            color: white;
            border: none;
            border-radius: 6px;
            font-weight: 600;
            text-decoration: none;
            transition: background 0.3s;
        }
        .btn-cancel:hover {
            background: #4b5563;
            color: white;
        }
        .btn-delete {
            padding: 10px 30px;
            background: #dc3545;
            color: white;
            border: none;
            border-radius: 6px;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.3s;
        }
        .btn-delete:hover {
            background: #c82333;
        }
        .associated-data {
            margin-top: 15px;
            padding: 15px;
            background: #fff3cd;
            border-radius: 6px;
            border: 1px solid #ffc107;
            font-size: 14px;
            color: #856404;
            text-align: left;
        }
        .associated-data i {
            margin-right: 8px;
        }
    </style>
    
    <div class="container">
        <div class="delete-container">
            <div class="delete-icon">
                <i class="bi bi-exclamation-triangle-fill"></i>
            </div>
            <div class="delete-title">Delete Budget?</div>
            <p class="delete-warning">This action cannot be undone!</p>
            
            <div class="delete-details">
                <table>
                    <tr>
                        <td class="label">Budget Code:</td>
                        <td class="value"><strong><?php echo htmlspecialchars($budget['budget_code']); ?></strong></td>
                    </tr>
                    <tr>
                        <td class="label">Fiscal Year:</td>
                        <td class="value"><?php echo htmlspecialchars($budget['fiscal_year']); ?></td>
                    </tr>
                    <tr>
                        <td class="label">Fiscal Period:</td>
                        <td class="value"><?php echo htmlspecialchars($budget['fiscal_period']); ?></td>
                    </tr>
                    <tr>
                        <td class="label">Status:</td>
                        <td class="value">
                            <span class="badge <?php echo getBudgetStatusBadge($budget['status']); ?>">
                                <?php echo getBudgetStatusLabel($budget['status']); ?>
                            </span>
                        </td>
                    </tr>
                </table>
            </div>
            
            <div class="associated-data">
                <i class="bi bi-info-circle"></i>
                <strong>This will delete:</strong>
                <ul style="margin: 8px 0 0 20px; padding-left: 0; list-style: none;">
                    <?php
                    // Count associated records
                    $count_stmt = $db->prepare("SELECT COUNT(*) as count FROM budget_categories WHERE budget_id = ?");
                    $count_stmt->execute([$budget_id]);
                    $cat_count = $count_stmt->fetchColumn();
                    
                    $count_stmt = $db->prepare("SELECT COUNT(*) as count FROM budget_goals WHERE budget_id = ?");
                    $count_stmt->execute([$budget_id]);
                    $goal_count = $count_stmt->fetchColumn();
                    
                    $count_stmt = $db->prepare("SELECT COUNT(*) as count FROM budget_monitoring WHERE budget_id = ?");
                    $count_stmt->execute([$budget_id]);
                    $mon_count = $count_stmt->fetchColumn();
                    
                    $count_stmt = $db->prepare("SELECT COUNT(*) as count FROM budget_history WHERE budget_id = ?");
                    $count_stmt->execute([$budget_id]);
                    $hist_count = $count_stmt->fetchColumn();
                    ?>
                    <li>• <strong><?php echo $cat_count; ?></strong> budget categories</li>
                    <li>• <strong><?php echo $goal_count; ?></strong> budget goals</li>
                    <li>• <strong><?php echo $mon_count; ?></strong> monitoring records</li>
                    <li>• <strong><?php echo $hist_count; ?></strong> history records</li>
                    <li>• The budget itself</li>
                </ul>
            </div>
            
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                <input type="hidden" name="confirm_delete" value="1">
                
                <div class="delete-actions">
                    <a href="budget.php?view=<?php echo $budget_id; ?>" class="btn-cancel">
                        <i class="bi bi-x-circle me-1"></i> Cancel
                    </a>
                    <button type="submit" class="btn-delete">
                        <i class="bi bi-trash me-1"></i> Permanently Delete
                    </button>
                </div>
            </form>
        </div>
    </div>
    
    <?php include '../includes/footer.php'; ?>
    <?php
    exit;
}
?>
