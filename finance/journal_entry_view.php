<?php
// journal_entry_view.php
session_start();
require_once '../config/config.php';

// Check authentication
$user = get_logged_in_user();
if (!$user) {
    redirect('auth/login.php');
}

$allowed_roles = ['finance_officer', 'finance_manager', 'accountant', 'system_admin', 'ceo', 'admin', 'trader', 'hr_manager', 'hr_officer'];
if (!in_array($user['role'], $allowed_roles)) {
    show_alert('You do not have permission to view journal entries.', 'danger');
    redirect('dashboard.php');
}

$db = getDBConnection();

// Get entry ID from URL
$entry_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($entry_id <= 0) {
    show_alert('Invalid journal entry ID.', 'danger');
    redirect('balance_sheet.php');
}

// Get journal entry details - fixed collation issue by removing problematic JOIN
$entry_query = "
    SELECT 
        gl.*,
        gl.id as entry_id,
        gl.reference_no,
        gl.account_code,
        gl.account_name,
        gl.debit_amount,
        gl.credit_amount,
        gl.description,
        gl.transaction_date,
        gl.reference_type,
        gl.entity_id,
        gl.entity_name,
        gl.entity_type,
        gl.currency,
        gl.fiscal_year,
        gl.fiscal_period,
        gl.is_reconciled,
        gl.reconciliation_id,
        gl.status as entry_status,
        gl.notes,
        gl.created_by,
        gl.created_at,
        gl.updated_at,
        gl.created_by_username,
        DATE_FORMAT(gl.transaction_date, '%Y-%m-%d') as transaction_date_formatted,
        DATE_FORMAT(gl.created_at, '%Y-%m-%d %H:%i:%s') as created_at_formatted,
        DATE_FORMAT(gl.updated_at, '%Y-%m-%d %H:%i:%s') as updated_at_formatted,
        CASE 
            WHEN gl.debit_amount > 0 THEN 'Debit'
            WHEN gl.credit_amount > 0 THEN 'Credit'
            ELSE 'N/A'
        END as transaction_side,
        CASE 
            WHEN gl.status = 'reversed' THEN 'Reversed'
            WHEN gl.status = 'cancelled' THEN 'Cancelled'
            ELSE 'Active'
        END as status_display
    FROM general_ledger gl
    WHERE gl.id = ?
    AND gl.status != 'cancelled'
";

$stmt = $db->prepare($entry_query);
$stmt->execute([$entry_id]);
$entry = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$entry) {
    show_alert('Journal entry not found or has been deleted.', 'danger');
    redirect('balance_sheet.php');
}

// Get user who created the entry (separate query to avoid collation issues)
if (!empty($entry['created_by_username'])) {
    $user_query = "
        SELECT 
            full_name,
            role,
            email
        FROM users 
        WHERE username = ? COLLATE utf8mb4_general_ci
        LIMIT 1
    ";
    $stmt = $db->prepare($user_query);
    $stmt->execute([$entry['created_by_username']]);
    $creator_info = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($creator_info) {
        $entry['created_by_fullname'] = $creator_info['full_name'];
        $entry['created_by_role'] = $creator_info['role'];
        $entry['created_by_email'] = $creator_info['email'];
    }
}

// Get chart of accounts info (separate query)
$coa_query = "
    SELECT 
        account_type,
        account_subtype,
        parent_id,
        level,
        normal_balance,
        is_group_account,
        description as account_description
    FROM chart_of_accounts 
    WHERE account_code = ?
    LIMIT 1
";

$stmt = $db->prepare($coa_query);
$stmt->execute([$entry['account_code']]);
$coa_info = $stmt->fetch(PDO::FETCH_ASSOC);

if ($coa_info) {
    $entry = array_merge($entry, $coa_info);
    
    // Get parent account info if exists
    if (!empty($coa_info['parent_id'])) {
        $parent_query = "
            SELECT 
                account_code as parent_account_code,
                account_name as parent_account_name,
                account_type as parent_account_type
            FROM chart_of_accounts 
            WHERE id = ?
            LIMIT 1
        ";
        $stmt = $db->prepare($parent_query);
        $stmt->execute([$coa_info['parent_id']]);
        $parent_info = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($parent_info) {
            $entry = array_merge($entry, $parent_info);
        }
    }
}

// Get account hierarchy for this entry
$account_hierarchy = [];
if (!empty($entry['parent_id'])) {
    $hierarchy_query = "
        WITH RECURSIVE account_tree AS (
            SELECT id, account_code, account_name, parent_id, account_type, level
            FROM chart_of_accounts 
            WHERE id = ?
            UNION ALL
            SELECT p.id, p.account_code, p.account_name, p.parent_id, p.account_type, p.level
            FROM chart_of_accounts p
            INNER JOIN account_tree c ON p.id = c.parent_id
        )
        SELECT * FROM account_tree ORDER BY level
    ";
    $stmt = $db->prepare($hierarchy_query);
    $stmt->execute([$entry['parent_id']]);
    $account_hierarchy = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Get related entries for the same transaction (if part of a double entry)
$related_entries_query = "
    SELECT 
        gl.*,
        gl.id as entry_id,
        gl.account_code,
        gl.account_name,
        gl.debit_amount,
        gl.credit_amount,
        gl.description,
        gl.transaction_date,
        gl.reference_type,
        gl.entity_name,
        gl.currency,
        gl.status as entry_status,
        DATE_FORMAT(gl.transaction_date, '%d/%m/%Y') as transaction_date_display
    FROM general_ledger gl
    WHERE gl.reference_no = ?
    AND gl.id != ?
    AND gl.status != 'cancelled'
    ORDER BY gl.debit_amount DESC, gl.transaction_date, gl.id
";

$stmt = $db->prepare($related_entries_query);
$stmt->execute([$entry['reference_no'], $entry_id]);
$related_entries = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Add account type info to related entries
foreach ($related_entries as &$related) {
    $coa_stmt = $db->prepare($coa_query);
    $coa_stmt->execute([$related['account_code']]);
    $related_coa = $coa_stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($related_coa) {
        $related = array_merge($related, $related_coa);
    }
    
    // Add display fields
    $related['transaction_side'] = $related['debit_amount'] > 0 ? 'Debit' : ($related['credit_amount'] > 0 ? 'Credit' : 'N/A');
    $related['status_display'] = $related['entry_status'] == 'reversed' ? 'Reversed' : ($related['entry_status'] == 'cancelled' ? 'Cancelled' : 'Active');
}
unset($related); // Unset reference

// Calculate totals for the transaction
$total_debit = $entry['debit_amount'];
$total_credit = $entry['credit_amount'];
foreach ($related_entries as $related) {
    $total_debit += $related['debit_amount'];
    $total_credit += $related['credit_amount'];
}

// Check if transaction is balanced
$is_balanced = abs($total_debit - $total_credit) < 0.01;

// Get sub-account details for cash accounts (111 series)
$sub_account_details = [];
if (substr($entry['account_code'], 0, 3) === '111') {
    $sub_accounts_query = "
        SELECT 
            account_code,
            account_name,
            account_type,
            normal_balance,
            level,
            description
        FROM chart_of_accounts 
        WHERE account_code LIKE ? 
        AND account_code != ?
        AND is_active = 1
        ORDER BY account_code
    ";
    $stmt = $db->prepare($sub_accounts_query);
    $stmt->execute([$entry['account_code'] . '%', $entry['account_code']]);
    $sub_account_details = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Check if document_attachments table exists and get attachments
$attachments = [];
try {
    $attachments_query = "
        SELECT 
            da.*,
            DATE_FORMAT(da.uploaded_at, '%d/%m/%Y %H:%i') as uploaded_at_display,
            u.full_name as uploaded_by_name
        FROM document_attachments da
        LEFT JOIN users u ON da.uploaded_by = u.id COLLATE utf8mb4_general_ci
        WHERE da.reference_no = ?
        AND da.status = 'active'
        ORDER BY da.uploaded_at DESC
    ";
    
    $stmt = $db->prepare($attachments_query);
    $stmt->execute([$entry['reference_no']]);
    $attachments = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Table doesn't exist or error, ignore
    $attachments = [];
}

// Check if transaction_comments table exists and get comments
$comments = [];
try {
    $comments_query = "
        SELECT 
            tc.*,
            DATE_FORMAT(tc.created_at, '%d/%m/%Y %H:%i') as created_at_display,
            u.full_name as created_by_name,
            u.role as created_by_role
        FROM transaction_comments tc
        LEFT JOIN users u ON tc.created_by = u.id COLLATE utf8mb4_general_ci
        WHERE tc.reference_no = ?
        AND tc.status = 'active'
        ORDER BY tc.created_at DESC
    ";
    
    $stmt = $db->prepare($comments_query);
    $stmt->execute([$entry['reference_no']]);
    $comments = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Table doesn't exist or error, ignore
    $comments = [];
}

// Check if audit_log table exists and get audit trail
$audit_trail = [];
try {
    $audit_query = "
        SELECT 
            al.*,
            DATE_FORMAT(al.created_at, '%d/%m/%Y %H:%i:%s') as created_at_display,
            u.full_name as user_name,
            u.role as user_role
        FROM audit_log al
        LEFT JOIN users u ON al.user_id = u.id COLLATE utf8mb4_general_ci
        WHERE al.record_id = ?
        AND al.table_name = 'general_ledger'
        AND al.action IN ('CREATE', 'UPDATE', 'DELETE', 'VOID', 'APPROVE', 'REJECT', 'REVERSE')
        ORDER BY al.created_at DESC
        LIMIT 10
    ";
    
    $stmt = $db->prepare($audit_query);
    $stmt->execute([$entry_id]);
    $audit_trail = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Table doesn't exist or error, ignore
    $audit_trail = [];
}

// Get company details
$company_stmt = $db->prepare("
    SELECT 
        COALESCE(company_name, name) as company_name,
        address,
        phone,
        mobile,
        email,
        registration_number,
        currency,
        logo
    FROM companies 
    WHERE status = 'active' OR is_active = 1
    ORDER BY id ASC 
    LIMIT 1
");
$company_stmt->execute();
$company = $company_stmt->fetch(PDO::FETCH_ASSOC) ?: [
    'company_name' => 'Neovam Technologies LTD',
    'address' => 'P.O Box, Dar es Salaam, Tanzania',
    'currency' => 'TSH'
];

// Check if user can edit/delete
$can_edit = in_array($user['role'], ['finance_officer', 'finance_manager', 'accountant', 'system_admin']);
$can_delete = in_array($user['role'], ['finance_manager', 'system_admin']);
$can_reverse = in_array($user['role'], ['finance_manager', 'system_admin', 'ceo']);
$can_add_comment = in_array($user['role'], ['finance_officer', 'finance_manager', 'accountant', 'system_admin', 'ceo']);

// Handle comment submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_comment']) && $can_add_comment) {
    $comment = trim($_POST['comment'] ?? '');
    
    if (!empty($comment)) {
        // Check if transaction_comments table exists, if not create it
        $check_table = "
            CREATE TABLE IF NOT EXISTS transaction_comments (
                id INT AUTO_INCREMENT PRIMARY KEY,
                reference_no VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
                comment TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
                created_by INT NOT NULL,
                status ENUM('active', 'deleted') DEFAULT 'active',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_reference (reference_no),
                INDEX idx_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ";
        $db->exec($check_table);
        
        $insert_comment = "
            INSERT INTO transaction_comments 
            (reference_no, comment, created_by, status, created_at)
            VALUES (?, ?, ?, 'active', NOW())
        ";
        
        $stmt = $db->prepare($insert_comment);
        if ($stmt->execute([$entry['reference_no'], $comment, $user['id']])) {
            // Log the action
            try {
                $audit_log = "
                    INSERT INTO audit_log 
                    (user_id, action, table_name, record_id, details, ip_address, user_agent, created_at)
                    VALUES (?, 'COMMENT', 'general_ledger', ?, ?, ?, ?, NOW())
                ";
                $details = json_encode([
                    'reference_no' => $entry['reference_no'],
                    'comment' => substr($comment, 0, 100)
                ]);
                $stmt = $db->prepare($audit_log);
                $stmt->execute([
                    $user['id'],
                    $entry_id,
                    $details,
                    $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
                    $_SERVER['HTTP_USER_AGENT'] ?? ''
                ]);
            } catch (Exception $e) {
                // Audit log table might not exist, ignore
            }
            
            show_alert('Comment added successfully.', 'success');
            header("Location: journal_entry_view.php?id=$entry_id");
            exit;
        } else {
            show_alert('Failed to add comment. Please try again.', 'danger');
        }
    } else {
        show_alert('Comment cannot be empty.', 'warning');
    }
}

// Handle entry reversal
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reverse_entry']) && $can_reverse) {
    $reverse_reason = trim($_POST['reverse_reason'] ?? '');
    
    if (!empty($reverse_reason)) {
        // Start transaction
        $db->beginTransaction();
        
        try {
            // Create reversal entry
            $reversal_entry = "
                INSERT INTO general_ledger 
                (journal_id, transaction_date, account_id, account_code, account_name, 
                 debit_amount, credit_amount, description, reference_no, reference_type,
                 entity_id, entity_name, entity_type, currency, fiscal_year, fiscal_period,
                 is_reconciled, reconciliation_id, status, notes, created_by, created_by_username,
                 running_balance, balance_type)
                SELECT 
                    journal_id, 
                    CURDATE() as transaction_date,
                    account_id,
                    account_code,
                    account_name,
                    credit_amount as debit_amount, -- Swap debit/credit for reversal
                    debit_amount as credit_amount,
                    CONCAT('REVERSAL: ', description) as description,
                    CONCAT('REV-', reference_no) as reference_no,
                    'adjustment' as reference_type,
                    entity_id,
                    entity_name,
                    entity_type,
                    currency,
                    YEAR(CURDATE()) as fiscal_year,
                    MONTH(CURDATE()) as fiscal_period,
                    0 as is_reconciled,
                    NULL as reconciliation_id,
                    'active' as status,
                    ? as notes,
                    ? as created_by,
                    ? as created_by_username,
                    0 as running_balance,
                    CASE 
                        WHEN debit_amount > 0 THEN 'credit'
                        ELSE 'debit'
                    END as balance_type
                FROM general_ledger 
                WHERE id = ?
            ";
            
            $stmt = $db->prepare($reversal_entry);
            $stmt->execute([
                "Reversal reason: $reverse_reason",
                $user['id'],
                $user['username'],
                $entry_id
            ]);
            
            $reversal_id = $db->lastInsertId();
            
            // Update original entry status
            $update_original = "
                UPDATE general_ledger 
                SET status = 'reversed',
                    notes = CONCAT(COALESCE(notes, ''), '\nReversed on ', CURDATE(), ' by ', ?, '. Reason: ', ?)
                WHERE id = ?
            ";
            
            $stmt = $db->prepare($update_original);
            $stmt->execute([
                $user['username'],
                $reverse_reason,
                $entry_id
            ]);
            
            // Log the reversal
            try {
                $audit_log = "
                    INSERT INTO audit_log 
                    (user_id, action, table_name, record_id, details, ip_address, user_agent, created_at)
                    VALUES (?, 'REVERSE', 'general_ledger', ?, ?, ?, ?, NOW())
                ";
                $details = json_encode([
                    'original_reference' => $entry['reference_no'],
                    'reversal_reference' => 'REV-' . $entry['reference_no'],
                    'reason' => $reverse_reason,
                    'reversal_id' => $reversal_id
                ]);
                $stmt = $db->prepare($audit_log);
                $stmt->execute([
                    $user['id'],
                    $entry_id,
                    $details,
                    $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
                    $_SERVER['HTTP_USER_AGENT'] ?? ''
                ]);
            } catch (Exception $e) {
                // Audit log table might not exist, ignore
            }
            
            $db->commit();
            
            show_alert('Journal entry reversed successfully. Reversal entry created.', 'success');
            header("Location: journal_entry_view.php?id=$entry_id");
            exit;
            
        } catch (Exception $e) {
            $db->rollBack();
            show_alert('Failed to reverse entry: ' . $e->getMessage(), 'danger');
        }
    } else {
        show_alert('Please provide a reason for reversal.', 'warning');
    }
}

include '../includes/header.php';
?>

<div class="container-fluid py-4">
  
    
        
        <div class="card-body">
            <!-- Entry Header Info -->
         
                
               
            </div>
            
            <!-- Account Hierarchy -->
            <?php if (!empty($account_hierarchy)): ?>
            <div class="card mb-4">
                <div class="card-header bg-light">
                    <h5 class="mb-0">
                        <i class="bi bi-diagram-3 me-2"></i>
                        Account Hierarchy
                    </h5>
                </div>
                <div class="card-body">
                    <div class="d-flex align-items-center flex-wrap">
                        <?php foreach ($account_hierarchy as $index => $account): ?>
                            <?php if ($index > 0): ?>
                                <i class="bi bi-chevron-right mx-2 text-muted"></i>
                            <?php endif; ?>
                            <div class="p-2 border rounded bg-white mb-2">
                                <small class="text-muted d-block">Level <?php echo $account['level']; ?></small>
                                <div class="d-flex align-items-center">
                                    <span class="badge bg-<?php echo $account['account_type'] == 'asset' ? 'primary' : ($account['account_type'] == 'liability' ? 'warning' : ($account['account_type'] == 'equity' ? 'success' : ($account['account_type'] == 'income' ? 'info' : 'danger'))); ?> me-2">
                                        <?php echo strtoupper(substr($account['account_type'], 0, 1)); ?>
                                    </span>
                                    <div>
                                        <div class="fw-bold"><?php echo htmlspecialchars($account['account_code']); ?></div>
                                        <div class="small text-muted"><?php echo htmlspecialchars($account['account_name']); ?></div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Account Details -->
            <div class="card mb-4">
                <div class="card-header bg-secondary text-white">
                    <h5 class="mb-0">
                        <i class="bi bi-cash-stack me-2"></i>
                        Account Details
                    </h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-3">
                            <div class="mb-3">
                                <label class="form-label">Account Code</label>
                                <div class="input-group">
                                    <span class="input-group-text">
                                        <i class="bi bi-hash"></i>
                                    </span>
                                    <input type="text" class="form-control" value="<?php echo htmlspecialchars($entry['account_code']); ?>" readonly>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-5">
                            <div class="mb-3">
                                <label class="form-label">Account Name</label>
                                <div class="input-group">
                                    <span class="input-group-text">
                                        <i class="bi bi-card-text"></i>
                                    </span>
                                    <input type="text" class="form-control" value="<?php echo htmlspecialchars($entry['account_name']); ?>" readonly>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-2">
                            <div class="mb-3">
                                <label class="form-label">Account Type</label>
                                <div class="input-group">
                                    <span class="input-group-text">
                                        <i class="bi bi-tag"></i>
                                    </span>
                                    <input type="text" class="form-control" value="<?php echo htmlspecialchars(ucfirst($entry['account_type'] ?? 'N/A')); ?>" readonly>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-2">
                            <div class="mb-3">
                                <label class="form-label">Normal Balance</label>
                                <div class="input-group">
                                    <span class="input-group-text">
                                        <i class="bi bi-arrow-<?php echo ($entry['normal_balance'] ?? 'debit') == 'debit' ? 'up' : 'down'; ?>-circle"></i>
                                    </span>
                                    <input type="text" class="form-control" value="<?php echo htmlspecialchars(ucfirst($entry['normal_balance'] ?? 'N/A')); ?>" readonly>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <?php if (!empty($entry['account_description'])): ?>
                    <div class="row">
                        <div class="col-12">
                            <div class="mb-3">
                                <label class="form-label">Account Description</label>
                                <div class="p-3 bg-light rounded border">
                                    <?php echo nl2br(htmlspecialchars($entry['account_description'])); ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <?php if (!empty($sub_account_details)): ?>
                    <div class="row mt-3">
                        <div class="col-12">
                            <h6 class="border-bottom pb-2">Sub-Accounts in this Group</h6>
                            <div class="table-responsive">
                                <table class="table table-sm table-hover">
                                    <thead>
                                        <tr>
                                            <th>Code</th>
                                            <th>Name</th>
                                            <th>Type</th>
                                            <th>Normal Balance</th>
                                            <th>Level</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($sub_account_details as $sub_account): ?>
                                        <tr>
                                            <td><code><?php echo htmlspecialchars($sub_account['account_code']); ?></code></td>
                                            <td><?php echo htmlspecialchars($sub_account['account_name']); ?></td>
                                            <td>
                                                <span class="badge bg-<?php echo $sub_account['account_type'] == 'asset' ? 'primary' : ($sub_account['account_type'] == 'liability' ? 'warning' : ($sub_account['account_type'] == 'equity' ? 'success' : ($sub_account['account_type'] == 'income' ? 'info' : 'danger'))); ?>">
                                                    <?php echo ucfirst($sub_account['account_type']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <span class="badge bg-<?php echo $sub_account['normal_balance'] == 'debit' ? 'danger' : 'success'; ?>">
                                                    <?php echo ucfirst($sub_account['normal_balance']); ?>
                                                </span>
                                            </td>
                                            <td><?php echo $sub_account['level']; ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Amount Details -->
            <div class="row mb-4">
                <div class="col-md-6">
                    <div class="card <?php echo $entry['debit_amount'] > 0 ? 'border-danger' : 'border-success'; ?>">
                        <div class="card-body text-center">
                            <h6 class="card-title">
                                <?php if ($entry['debit_amount'] > 0): ?>
                                <i class="bi bi-arrow-up-circle text-danger me-2"></i>Debit Amount
                                <?php else: ?>
                                <i class="bi bi-arrow-down-circle text-success me-2"></i>Credit Amount
                                <?php endif; ?>
                            </h6>
                            <h3 class="<?php echo $entry['debit_amount'] > 0 ? 'text-danger' : 'text-success'; ?>">
                                <?php if ($entry['debit_amount'] > 0): ?>
                                <?php echo number_format($entry['debit_amount'], 2); ?>
                                <?php else: ?>
                                <?php echo number_format($entry['credit_amount'], 2); ?>
                                <?php endif; ?>
                                <small><?php echo htmlspecialchars($entry['currency']); ?></small>
                            </h3>
                            <p class="text-muted mb-0"><?php echo $entry['transaction_side']; ?> Entry</p>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-6">
                    <div class="card border-info">
                        <div class="card-body">
                            <h6 class="card-title text-info">
                                <i class="bi bi-file-text me-2"></i>Description
                            </h6>
                            <p class="mb-0"><?php echo nl2br(htmlspecialchars($entry['description'])); ?></p>
                            <?php if (!empty($entry['entity_name'])): ?>
                            <hr>
                            <small class="text-muted">Related Entity:</small>
                            <p class="mb-0">
                                <strong><?php echo htmlspecialchars($entry['entity_name']); ?></strong>
                                <?php if (!empty($entry['entity_type'])): ?>
                                <span class="badge bg-secondary ms-2"><?php echo htmlspecialchars($entry['entity_type']); ?></span>
                                <?php endif; ?>
                            </p>
                            <?php endif; ?>
                            
                            <?php if (!empty($entry['notes'])): ?>
                            <hr>
                            <small class="text-muted">Additional Notes:</small>
                            <p class="mb-0 text-muted small"><?php echo nl2br(htmlspecialchars($entry['notes'])); ?></p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Related Entries (for double-entry transactions) -->
            <?php if (!empty($related_entries)): ?>
            <div class="card mb-4">
                <div class="card-header bg-warning text-dark">
                    <div class="d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">
                            <i class="bi bi-link me-2"></i>
                            Related Journal Entries
                            <span class="badge bg-light text-dark ms-2"><?php echo count($related_entries) + 1; ?> total</span>
                        </h5>
                        <span class="badge bg-<?php echo $is_balanced ? 'success' : 'danger'; ?>">
                            <?php echo $is_balanced ? 'Balanced' : 'Not Balanced'; ?>
                        </span>
                    </div>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover table-sm">
                            <thead class="table-light">
                                <tr>
                                    <th>Entry ID</th>
                                    <th>Account</th>
                                    <th>Account Name</th>
                                    <th>Type</th>
                                    <th class="text-end">Debit</th>
                                    <th class="text-end">Credit</th>
                                    <th>Date</th>
                                    <th>Status</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <!-- Current Entry -->
                                <tr class="table-primary">
                                    <td>
                                        <strong>#<?php echo $entry['entry_id']; ?></strong>
                                        <span class="badge bg-info ms-1">Current</span>
                                    </td>
                                    <td>
                                        <span class="badge bg-secondary"><?php echo htmlspecialchars($entry['account_code']); ?></span>
                                    </td>
                                    <td><?php echo htmlspecialchars($entry['account_name']); ?></td>
                                    <td>
                                        <span class="badge bg-<?php echo ($entry['account_type'] ?? 'asset') == 'asset' ? 'primary' : (($entry['account_type'] ?? 'asset') == 'liability' ? 'warning' : (($entry['account_type'] ?? 'asset') == 'equity' ? 'success' : (($entry['account_type'] ?? 'asset') == 'income' ? 'info' : 'danger'))); ?>">
                                            <?php echo ucfirst($entry['account_type'] ?? 'Asset'); ?>
                                        </span>
                                    </td>
                                    <td class="text-end <?php echo $entry['debit_amount'] > 0 ? 'text-danger fw-bold' : ''; ?>">
                                        <?php echo $entry['debit_amount'] > 0 ? number_format($entry['debit_amount'], 2) : '-'; ?>
                                    </td>
                                    <td class="text-end <?php echo $entry['credit_amount'] > 0 ? 'text-success fw-bold' : ''; ?>">
                                        <?php echo $entry['credit_amount'] > 0 ? number_format($entry['credit_amount'], 2) : '-'; ?>
                                    </td>
                                    <td><?php echo date('d/m/Y', strtotime($entry['transaction_date'])); ?></td>
                                    <td>
                                        <span class="badge bg-<?php echo $entry['entry_status'] == 'active' ? 'success' : 'warning'; ?>">
                                            <?php echo $entry['status_display']; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge bg-primary">Current</span>
                                    </td>
                                </tr>
                                
                                <!-- Related Entries -->
                                <?php foreach ($related_entries as $related): ?>
                                <tr>
                                    <td>
                                        <a href="journal_entry_view.php?id=<?php echo $related['entry_id']; ?>" class="text-decoration-none">
                                            #<?php echo $related['entry_id']; ?>
                                        </a>
                                    </td>
                                    <td>
                                        <span class="badge bg-secondary"><?php echo htmlspecialchars($related['account_code']); ?></span>
                                    </td>
                                    <td><?php echo htmlspecialchars($related['account_name']); ?></td>
                                    <td>
                                        <span class="badge bg-<?php echo ($related['account_type'] ?? 'asset') == 'asset' ? 'primary' : (($related['account_type'] ?? 'asset') == 'liability' ? 'warning' : (($related['account_type'] ?? 'asset') == 'equity' ? 'success' : (($related['account_type'] ?? 'asset') == 'income' ? 'info' : 'danger'))); ?>">
                                            <?php echo ucfirst($related['account_type'] ?? 'Asset'); ?>
                                        </span>
                                    </td>
                                    <td class="text-end <?php echo $related['debit_amount'] > 0 ? 'text-danger fw-bold' : ''; ?>">
                                        <?php echo $related['debit_amount'] > 0 ? number_format($related['debit_amount'], 2) : '-'; ?>
                                    </td>
                                    <td class="text-end <?php echo $related['credit_amount'] > 0 ? 'text-success fw-bold' : ''; ?>">
                                        <?php echo $related['credit_amount'] > 0 ? number_format($related['credit_amount'], 2) : '-'; ?>
                                    </td>
                                    <td><?php echo $related['transaction_date_display']; ?></td>
                                    <td>
                                        <span class="badge bg-<?php echo $related['entry_status'] == 'active' ? 'success' : 'warning'; ?>">
                                            <?php echo $related['status_display']; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <a href="journal_entry_view.php?id=<?php echo $related['entry_id']; ?>" 
                                           class="btn btn-sm btn-outline-primary">
                                            <i class="bi bi-eye"></i>
                                        </a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot class="table-light">
                                <tr>
                                    <td colspan="4" class="text-end"><strong>Transaction Totals:</strong></td>
                                    <td class="text-end">
                                        <strong class="text-danger"><?php echo number_format($total_debit, 2); ?></strong>
                                    </td>
                                    <td class="text-end">
                                        <strong class="text-success"><?php echo number_format($total_credit, 2); ?></strong>
                                    </td>
                                    <td colspan="3"></td>
                                </tr>
                                <tr class="<?php echo $is_balanced ? 'table-success' : 'table-danger'; ?>">
                                    <td colspan="4" class="text-end"><strong>Balance Check:</strong></td>
                                    <td colspan="2" class="text-center">
                                        <strong>
                                            <?php if ($is_balanced): ?>
                                            <i class="bi bi-check-circle text-success me-1"></i>Transaction is Balanced
                                            <?php else: ?>
                                            <i class="bi bi-exclamation-circle text-danger me-1"></i>Transaction is NOT Balanced
                                            <br><small>Difference: <?php echo number_format(abs($total_debit - $total_credit), 2); ?></small>
                                            <?php endif; ?>
                                        </strong>
                                    </td>
                                    <td colspan="3"></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Attachments -->
            <?php if (!empty($attachments)): ?>
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0">
                        <i class="bi bi-paperclip me-2"></i>
                        Document Attachments (<?php echo count($attachments); ?>)
                    </h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <?php foreach ($attachments as $attachment): ?>
                        <div class="col-md-4 mb-3">
                            <div class="card border">
                                <div class="card-body">
                                    <div class="d-flex align-items-start">
                                        <div class="me-3">
                                            <?php 
                                            $file_ext = pathinfo($attachment['file_name'] ?? '', PATHINFO_EXTENSION);
                                            $icon_class = '';
                                            if (in_array($file_ext, ['pdf'])) {
                                                $icon_class = 'bi-file-pdf text-danger';
                                            } elseif (in_array($file_ext, ['doc', 'docx'])) {
                                                $icon_class = 'bi-file-word text-primary';
                                            } elseif (in_array($file_ext, ['xls', 'xlsx'])) {
                                                $icon_class = 'bi-file-excel text-success';
                                            } elseif (in_array($file_ext, ['jpg', 'jpeg', 'png', 'gif'])) {
                                                $icon_class = 'bi-file-image text-info';
                                            } else {
                                                $icon_class = 'bi-file-text text-secondary';
                                            }
                                            ?>
                                            <i class="bi <?php echo $icon_class; ?>" style="font-size: 2rem;"></i>
                                        </div>
                                        <div>
                                            <h6 class="card-title mb-1"><?php echo htmlspecialchars($attachment['document_name'] ?? 'Document'); ?></h6>
                                            <p class="text-muted small mb-1">
                                                <i class="bi bi-calendar me-1"></i>
                                                <?php echo $attachment['uploaded_at_display'] ?? 'N/A'; ?>
                                            </p>
                                            <p class="small mb-2">
                                                <i class="bi bi-person me-1"></i>
                                                <?php echo htmlspecialchars($attachment['uploaded_by_name'] ?? 'Unknown'); ?>
                                            </p>
                                            <?php if (!empty($attachment['file_path'])): ?>
                                            <a href="../uploads/<?php echo htmlspecialchars($attachment['file_path']); ?>" 
                                               class="btn btn-sm btn-outline-primary" target="_blank">
                                                <i class="bi bi-download me-1"></i>Download
                                            </a>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Comments Section -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0">
                        <i class="bi bi-chat-left-text me-2"></i>
                        Comments & Notes
                        <span class="badge bg-secondary"><?php echo count($comments); ?></span>
                    </h5>
                </div>
                <div class="card-body">
                    <!-- Add Comment Form -->
                    <?php if ($can_add_comment): ?>
                    <div class="mb-4">
                        <form method="POST" action="">
                            <div class="mb-3">
                                <label for="comment" class="form-label">Add Comment</label>
                                <textarea class="form-control" id="comment" name="comment" rows="3" 
                                          placeholder="Enter your comment here..." required></textarea>
                            </div>
                            <button type="submit" name="add_comment" class="btn btn-primary">
                                <i class="bi bi-send me-1"></i>Add Comment
                            </button>
                        </form>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Comments List -->
                    <?php if (!empty($comments)): ?>
                    <div class="comments-list">
                        <?php foreach ($comments as $comment): ?>
                        <div class="card mb-3 border">
                            <div class="card-body">
                                <div class="d-flex justify-content-between mb-2">
                                    <div>
                                        <strong><?php echo htmlspecialchars($comment['created_by_name'] ?? 'Unknown'); ?></strong>
                                        <span class="badge bg-secondary ms-2"><?php echo htmlspecialchars($comment['created_by_role'] ?? 'N/A'); ?></span>
                                    </div>
                                    <small class="text-muted">
                                        <i class="bi bi-clock me-1"></i>
                                        <?php echo $comment['created_at_display'] ?? 'N/A'; ?>
                                    </small>
                                </div>
                                <p class="mb-0"><?php echo nl2br(htmlspecialchars($comment['comment'] ?? '')); ?></p>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php else: ?>
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle me-2"></i>
                        No comments yet. Be the first to add one!
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Audit Trail -->
            <?php if (!empty($audit_trail)): ?>
            <div class="card mb-4">
                <div class="card-header bg-dark text-white">
                    <h5 class="mb-0">
                        <i class="bi bi-clock-history me-2"></i>
                        Audit Trail
                    </h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-sm table-hover">
                            <thead class="table-light">
                                <tr>
                                    <th>Date & Time</th>
                                    <th>User</th>
                                    <th>Action</th>
                                    <th>Details</th>
                                    <th>IP Address</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($audit_trail as $audit): 
                                    $details = json_decode($audit['details'] ?? '{}', true);
                                ?>
                                <tr>
                                    <td>
                                        <small><?php echo $audit['created_at_display'] ?? 'N/A'; ?></small>
                                    </td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($audit['user_name'] ?? 'System'); ?></strong>
                                        <br>
                                        <small class="text-muted"><?php echo htmlspecialchars($audit['user_role'] ?? 'N/A'); ?></small>
                                    </td>
                                    <td>
                                        <?php
                                        $action_class = 'secondary';
                                        $action = $audit['action'] ?? '';
                                        if ($action == 'CREATE') $action_class = 'success';
                                        elseif ($action == 'UPDATE') $action_class = 'warning';
                                        elseif ($action == 'DELETE' || $action == 'REVERSE') $action_class = 'danger';
                                        elseif ($action == 'APPROVE') $action_class = 'primary';
                                        ?>
                                        <span class="badge bg-<?php echo $action_class; ?>">
                                            <?php echo htmlspecialchars($action); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if (!empty($details)): ?>
                                        <small>
                                            <?php 
                                            if (isset($details['reason'])) echo "Reason: " . htmlspecialchars($details['reason']);
                                            if (isset($details['field'])) echo "Field: " . htmlspecialchars($details['field']);
                                            if (isset($details['old_value'])) echo "From: " . htmlspecialchars($details['old_value']);
                                            if (isset($details['new_value'])) echo "To: " . htmlspecialchars($details['new_value']);
                                            ?>
                                        </small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <small class="text-muted"><?php echo htmlspecialchars($audit['ip_address'] ?? 'N/A'); ?></small>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Action Buttons -->
            <div class="card">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <a href="javascript:window.history.back()" class="btn btn-outline-secondary">
                                <i class="bi bi-arrow-left me-1"></i>Back
                            </a>
                            <a href="journal_entries.php?reference=<?php echo urlencode($entry['reference_no']); ?>" 
                               class="btn btn-outline-info">
                                <i class="bi bi-search me-1"></i>View All Similar Entries
                            </a>
                        </div>
                        <div>
                            <?php if ($can_edit && $entry['entry_status'] == 'active'): ?>
                            <a href="edit_journal_entry.php?id=<?php echo $entry_id; ?>" 
                               class="btn btn-warning">
                                <i class="bi bi-pencil me-1"></i>Edit
                            </a>
                            <?php endif; ?>
                            
                            <?php if ($can_reverse && $entry['entry_status'] == 'active'): ?>
                            <button type="button" class="btn btn-danger" data-bs-toggle="modal" data-bs-target="#reverseModal">
                                <i class="bi bi-arrow-counterclockwise me-1"></i>Reverse Entry
                            </button>
                            <?php endif; ?>
                            
                            <button onclick="window.print()" class="btn btn-primary">
                                <i class="bi bi-printer me-1"></i>Print
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Reverse Entry Modal -->
<?php if ($can_reverse && $entry['entry_status'] == 'active'): ?>
<div class="modal fade" id="reverseModal" tabindex="-1" aria-labelledby="reverseModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title" id="reverseModalLabel">
                        <i class="bi bi-exclamation-triangle me-2"></i>
                        Reverse Journal Entry
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-warning">
                        <i class="bi bi-exclamation-circle me-2"></i>
                        <strong>Warning:</strong> This action will create a reversal entry and mark this entry as reversed. This cannot be undone.
                    </div>
                    
                    <div class="mb-3">
                        <label for="reverse_reason" class="form-label">Reason for Reversal *</label>
                        <textarea class="form-control" id="reverse_reason" name="reverse_reason" 
                                  rows="3" placeholder="Please provide a reason for reversing this entry..." required></textarea>
                        <div class="form-text">This reason will be recorded in the audit trail.</div>
                    </div>
                    
                    <div class="card border-danger">
                        <div class="card-body">
                            <h6 class="card-title text-danger">Entry to be Reversed</h6>
                            <div class="row">
                                <div class="col-6">
                                    <small class="text-muted">Reference:</small>
                                    <p class="mb-1"><strong><?php echo htmlspecialchars($entry['reference_no']); ?></strong></p>
                                    
                                    <small class="text-muted">Account:</small>
                                    <p class="mb-1"><strong><?php echo htmlspecialchars($entry['account_code'] . ' - ' . $entry['account_name']); ?></strong></p>
                                </div>
                                <div class="col-6">
                                    <small class="text-muted">Amount:</small>
                                    <p class="mb-1">
                                        <strong class="<?php echo $entry['debit_amount'] > 0 ? 'text-danger' : 'text-success'; ?>">
                                            <?php echo number_format($entry['debit_amount'] > 0 ? $entry['debit_amount'] : $entry['credit_amount'], 2); ?>
                                            <?php echo htmlspecialchars($entry['currency']); ?>
                                        </strong>
                                    </p>
                                    
                                    <small class="text-muted">Type:</small>
                                    <p class="mb-1">
                                        <span class="badge bg-<?php echo $entry['debit_amount'] > 0 ? 'danger' : 'success'; ?>">
                                            <?php echo $entry['debit_amount'] > 0 ? 'Debit' : 'Credit'; ?>
                                        </span>
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="reverse_entry" class="btn btn-danger">
                        <i class="bi bi-arrow-counterclockwise me-1"></i>Confirm Reversal
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<style>
    .card-title {
        font-weight: 600;
    }
    .form-control[readonly] {
        background-color: #f8f9fa;
        border-color: #dee2e6;
    }
    .badge {
        font-size: 0.75em;
        font-weight: 500;
    }
    .table th {
        font-weight: 600;
        background-color: #f8f9fa;
    }
    .comments-list .card {
        border-left: 4px solid #0d6efd;
    }
    .account-type-badge {
        font-size: 0.7em;
        padding: 0.25em 0.5em;
    }
    @media print {
        .btn, .modal, .form-control, .card-header, .comments-list, .audit-trail, .action-buttons {
            display: none !important;
        }
        .card {
            border: 1px solid #000 !important;
            margin-bottom: 10px !important;
        }
        .table {
            border: 1px solid #000 !important;
            font-size: 11px !important;
        }
        .table th, .table td {
            border: 1px solid #000 !important;
            padding: 4px !important;
        }
        h4, h5, h6 {
            margin-bottom: 5px !important;
        }
        .container-fluid {
            padding: 0 !important;
        }
        .card-body {
            padding: 10px !important;
        }
    }
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Print functionality
    const printBtn = document.querySelector('[onclick="window.print()"]');
    if (printBtn) {
        printBtn.addEventListener('click', function() {
            window.print();
        });
    }
    
    // Modal handling
    const reverseModal = document.getElementById('reverseModal');
    if (reverseModal) {
        reverseModal.addEventListener('shown.bs.modal', function () {
            document.getElementById('reverse_reason').focus();
        });
    }
    
    // Confirm before leaving page if form has changes
    let formChanged = false;
    const textareas = document.querySelectorAll('textarea');
    textareas.forEach(textarea => {
        textarea.addEventListener('input', function() {
            formChanged = true;
        });
    });
    
    window.addEventListener('beforeunload', function(e) {
        if (formChanged) {
            e.preventDefault();
            e.returnValue = 'You have unsaved changes. Are you sure you want to leave?';
        }
    });
    
    // Add keyboard shortcut for printing (Ctrl+P)
    document.addEventListener('keydown', function(e) {
        if ((e.ctrlKey || e.metaKey) && e.key === 'p') {
            e.preventDefault();
            window.print();
        }
    });
});
</script>

<?php include '../includes/footer.php'; ?>