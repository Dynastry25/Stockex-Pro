<?php
require_once '../config/config.php';
require_once '../auth/auth_middleware.php';

require_finance_officer();

$db = getDBConnection();

// Fetch account categories for parent selection
try {
    $categories_stmt = $db->query("
        SELECT id, category_code, category_name, account_type, level 
        FROM account_categories 
        WHERE is_active = 1 
        ORDER BY category_code
    ");
    $categories = $categories_stmt->fetchAll();
} catch (PDOException $e) {
    $categories = [];
    $_SESSION['error_message'] = "Error fetching account categories: " . $e->getMessage();
}

// Fetch account types for the type dropdown
$account_types = [
    'asset' => 'Asset',
    'liability' => 'Liability',
    'equity' => 'Equity',
    'income' => 'Income',
    'expense' => 'Expense'
];

// Function to generate next account code in hierarchy
function generateHierarchicalAccountCode($db, $parent_code, $account_type) {
    if ($parent_code) {
        // This is a child account - get parent account
        $stmt = $db->prepare("
            SELECT account_code, level 
            FROM chart_of_accounts 
            WHERE account_code = ?
        ");
        $stmt->execute([$parent_code]);
        $parent = $stmt->fetch();
        
        if (!$parent) {
            throw new Exception("Parent account not found");
        }
        
        $parent_level = $parent['level'];
        $next_level = $parent_level + 1;
        
        // Get all children of this parent to find next sequence
        $stmt = $db->prepare("
            SELECT account_code 
            FROM chart_of_accounts 
            WHERE account_code LIKE CONCAT(?, '%') 
            AND LENGTH(account_code) = LENGTH(?) + 1
            AND account_code LIKE CONCAT(?, '%')
            ORDER BY account_code DESC 
            LIMIT 1
        ");
        $stmt->execute([$parent_code, $parent_code, $parent_code]);
        $last_child = $stmt->fetch();
        
        if ($last_child) {
            // Extract the last digit/character and increment
            $last_code = $last_child['account_code'];
            $last_sequence = substr($last_code, -1);
            if (is_numeric($last_sequence)) {
                $next_sequence = intval($last_sequence) + 1;
            } else {
                // If it's not numeric, start with 1
                $next_sequence = 1;
            }
        } else {
            $next_sequence = 1;
        }
        
        return $parent_code . $next_sequence;
    } else {
        // This is a top-level account
        // Get the main category code based on account type
        $category_codes = [
            'asset' => '1',
            'liability' => '2', 
            'equity' => '3',
            'income' => '4',
            'expense' => '5'
        ];
        
        $main_code = $category_codes[$account_type] ?? '0';
        
        // Get next top-level account in this category
        $stmt = $db->prepare("
            SELECT account_code 
            FROM chart_of_accounts 
            WHERE account_type = ? 
            AND level = 1 
            ORDER BY account_code DESC 
            LIMIT 1
        ");
        $stmt->execute([$account_type]);
        $last_account = $stmt->fetch();
        
        if ($last_account) {
            $last_code = $last_account['account_code'];
            if (strlen($last_code) == 1 && is_numeric($last_code)) {
                $next_code = intval($last_code) + 1;
            } else {
                $next_code = $main_code . '1';
            }
        } else {
            $next_code = $main_code;
        }
        
        return (string)$next_code;
    }
}

// Handle form submissions
if ($_POST) {
    if (isset($_POST['add_account'])) {
        $account_name = $_POST['account_name'];
        $account_type = $_POST['account_type'];
        $parent_code = $_POST['parent_code'] ?? null;
        $is_group_account = isset($_POST['is_group_account']) ? 1 : 0;
        $description = $_POST['description'] ?? '';
        
        try {
            // Generate hierarchical account code
            $account_code = generateHierarchicalAccountCode($db, $parent_code, $account_type);
            
            // Get parent_id and level if parent_code is provided
            $parent_id = null;
            $level = 1;
            
            if ($parent_code) {
                $stmt = $db->prepare("SELECT id, level FROM chart_of_accounts WHERE account_code = ?");
                $stmt->execute([$parent_code]);
                $parent = $stmt->fetch();
                
                if ($parent) {
                    $parent_id = $parent['id'];
                    $level = $parent['level'] + 1;
                }
            }
            
            // Determine normal balance
            $normal_balance = 'debit'; // Default
            if (in_array($account_type, ['liability', 'equity', 'income'])) {
                $normal_balance = 'credit';
            }
            
            // Insert into database
            $stmt = $db->prepare("
                INSERT INTO chart_of_accounts 
                (account_code, account_name, account_type, parent_id, level, 
                 normal_balance, is_group_account, description, is_active) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)
            ");
            $stmt->execute([
                $account_code, 
                $account_name, 
                $account_type, 
                $parent_id,
                $level,
                $normal_balance,
                $is_group_account,
                $description
            ]);
            
            $_SESSION['success_message'] = "Account added successfully! Account Code: " . $account_code;
        } catch (Exception $e) {
            $_SESSION['error_message'] = "Error adding account: " . $e->getMessage();
        }
    }
    
    if (isset($_POST['update_account'])) {
        $id = $_POST['account_id'];
        $account_name = $_POST['account_name'];
        $account_type = $_POST['account_type'];
        $parent_code = $_POST['parent_code'] ?? null;
        $is_group_account = isset($_POST['is_group_account']) ? 1 : 0;
        $description = $_POST['description'] ?? '';
        
        try {
            // Get current account details
            $stmt = $db->prepare("SELECT account_code FROM chart_of_accounts WHERE id = ?");
            $stmt->execute([$id]);
            $current_account = $stmt->fetch();
            
            if (!$current_account) {
                throw new Exception("Account not found");
            }
            
            $current_code = $current_account['account_code'];
            
            // Only regenerate code if parent changed
            $new_account_code = $current_code;
            $new_parent_id = null;
            $new_level = 1;
            
            if ($parent_code && $parent_code !== $current_code) {
                // Check if trying to make account its own parent
                if (strpos($parent_code, $current_code) === 0) {
                    throw new Exception("Cannot make an account a child of itself or its descendants");
                }
                
                $new_account_code = generateHierarchicalAccountCode($db, $parent_code, $account_type);
                
                // Get new parent details
                $stmt = $db->prepare("SELECT id, level FROM chart_of_accounts WHERE account_code = ?");
                $stmt->execute([$parent_code]);
                $new_parent = $stmt->fetch();
                
                if ($new_parent) {
                    $new_parent_id = $new_parent['id'];
                    $new_level = $new_parent['level'] + 1;
                }
            } else if ($parent_code === '') {
                // Top-level account
                $new_account_code = generateHierarchicalAccountCode($db, null, $account_type);
                $new_parent_id = null;
                $new_level = 1;
            }
            
            // Determine normal balance
            $normal_balance = 'debit'; // Default
            if (in_array($account_type, ['liability', 'equity', 'income'])) {
                $normal_balance = 'credit';
            }
            
            // Update database
            $stmt = $db->prepare("
                UPDATE chart_of_accounts 
                SET account_code = ?, account_name = ?, account_type = ?, 
                    parent_id = ?, level = ?, normal_balance = ?, 
                    is_group_account = ?, description = ? 
                WHERE id = ?
            ");
            $stmt->execute([
                $new_account_code, 
                $account_name, 
                $account_type, 
                $new_parent_id,
                $new_level,
                $normal_balance,
                $is_group_account,
                $description,
                $id
            ]);
            
            $_SESSION['success_message'] = "Account updated successfully! Account Code: " . $new_account_code;
        } catch (Exception $e) {
            $_SESSION['error_message'] = "Error updating account: " . $e->getMessage();
        }
    }
    
    if (isset($_POST['delete_account'])) {
        $id = $_POST['account_id'];
        
        try {
            // Check if account has children
            $stmt = $db->prepare("SELECT COUNT(*) as child_count FROM chart_of_accounts WHERE parent_id = ? AND is_active = 1");
            $stmt->execute([$id]);
            $result = $stmt->fetch();
            
            if ($result['child_count'] > 0) {
                $_SESSION['error_message'] = "Cannot delete account that has child accounts. Delete or move the children first.";
            } else {
                $stmt = $db->prepare("UPDATE chart_of_accounts SET is_active = 0 WHERE id = ?");
                $stmt->execute([$id]);
                $_SESSION['success_message'] = "Account deactivated successfully!";
            }
        } catch (PDOException $e) {
            $_SESSION['error_message'] = "Error deleting account: " . $e->getMessage();
        }
    }
    
    if (isset($_POST['restore_account'])) {
        $id = $_POST['account_id'];
        
        try {
            $stmt = $db->prepare("UPDATE chart_of_accounts SET is_active = 1 WHERE id = ?");
            $stmt->execute([$id]);
            
            $_SESSION['success_message'] = "Account restored successfully!";
        } catch (PDOException $e) {
            $_SESSION['error_message'] = "Error restoring account: " . $e->getMessage();
        }
    }
    
    header("Location: chart_of_accounts");
    exit;
}

// Get filter parameters
$search = $_GET['search'] ?? '';
$account_type_filter = $_GET['account_type'] ?? '';
$status_filter = $_GET['status'] ?? 'active';

// Build query with filters
$query = "
    SELECT c.*, 
           p.account_code as parent_account_code,
           p.account_name as parent_account_name
    FROM chart_of_accounts c
    LEFT JOIN chart_of_accounts p ON c.parent_id = p.id
    WHERE 1=1
";
$params = [];

if (!empty($search)) {
    $query .= " AND (c.account_code LIKE ? OR c.account_name LIKE ?)";
    $search_term = "%$search%";
    $params[] = $search_term;
    $params[] = $search_term;
}

if (!empty($account_type_filter)) {
    $query .= " AND c.account_type = ?";
    $params[] = $account_type_filter;
}

if ($status_filter === 'active') {
    $query .= " AND c.is_active = 1";
} elseif ($status_filter === 'inactive') {
    $query .= " AND c.is_active = 0";
}

$query .= " ORDER BY c.account_code";

// Get existing accounts with filters
try {
    $stmt = $db->prepare($query);
    $stmt->execute($params);
    $accounts = $stmt->fetchAll();
} catch (PDOException $e) {
    $accounts = [];
    $_SESSION['error_message'] = "Error fetching accounts: " . $e->getMessage();
}

// Get account statistics
try {
    $asset_count = $db->query("SELECT COUNT(*) as count FROM chart_of_accounts WHERE account_type = 'asset' AND is_active = 1")->fetch()['count'];
    $liability_count = $db->query("SELECT COUNT(*) as count FROM chart_of_accounts WHERE account_type = 'liability' AND is_active = 1")->fetch()['count'];
    $income_count = $db->query("SELECT COUNT(*) as count FROM chart_of_accounts WHERE account_type = 'income' AND is_active = 1")->fetch()['count'];
    $expense_count = $db->query("SELECT COUNT(*) as count FROM chart_of_accounts WHERE account_type = 'expense' AND is_active = 1")->fetch()['count'];
    $equity_count = $db->query("SELECT COUNT(*) as count FROM chart_of_accounts WHERE account_type = 'equity' AND is_active = 1")->fetch()['count'];
    $total_count = $db->query("SELECT COUNT(*) as count FROM chart_of_accounts WHERE is_active = 1")->fetch()['count'];
    $group_count = $db->query("SELECT COUNT(*) as count FROM chart_of_accounts WHERE is_group_account = 1 AND is_active = 1")->fetch()['count'];
    $detail_count = $db->query("SELECT COUNT(*) as count FROM chart_of_accounts WHERE is_group_account = 0 AND is_active = 1")->fetch()['count'];
} catch (PDOException $e) {
    $asset_count = $liability_count = $income_count = $expense_count = $equity_count = $total_count = $group_count = $detail_count = 0;
}

// Function to get parent account options
function getParentAccountOptions($db, $exclude_id = null) {
    $query = "SELECT account_code, account_name, level FROM chart_of_accounts WHERE is_active = 1";
    if ($exclude_id) {
        $query .= " AND id != ?";
    }
    $query .= " ORDER BY account_code";
    
    $stmt = $db->prepare($query);
    if ($exclude_id) {
        $stmt->execute([$exclude_id]);
    } else {
        $stmt->execute();
    }
    
    return $stmt->fetchAll();
}

$page_title = 'Chart of Accounts';
include '../includes/header.php';
?>

<div class="container-fluid py-4">
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                    <div>
                        <h4 class="mb-0"><i class="bi bi-diagram-3 me-2"></i>Chart of Accounts - Hierarchical</h4>
                        <p class="mb-0">Manage hierarchical financial accounts structure</p>
                    </div>
                    <div class="btn-group">
                        <button type="button" class="btn btn-light" data-bs-toggle="modal" data-bs-target="#addAccountModal">
                            <i class="bi bi-plus-circle me-1"></i>Add New Account
                        </button>
                        <a href="master_data_management.php" class="btn btn-light">
                            <i class="bi bi-arrow-left me-1"></i>Master Data
                        </a>
                    </div>
                </div>
                <div class="card-body">
                    
                    <?php if (isset($_SESSION['success_message'])): ?>
                        <div class="alert alert-success alert-dismissible fade show" role="alert">
                            <?php echo $_SESSION['success_message']; ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                        <?php unset($_SESSION['success_message']); ?>
                    <?php endif; ?>

                    <?php if (isset($_SESSION['error_message'])): ?>
                        <div class="alert alert-danger alert-dismissible fade show" role="alert">
                            <?php echo $_SESSION['error_message']; ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                        <?php unset($_SESSION['error_message']); ?>
                    <?php endif; ?>

                    <!-- Account Statistics -->
                    <div class="row mb-4">
                        <div class="col-md-2">
                            <div class="card border-0 bg-primary text-white">
                                <div class="card-body text-center">
                                    <h4 class="mb-1"><?php echo $total_count; ?></h4>
                                    <small>Total Accounts</small>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-2">
                            <div class="card border-0 bg-success text-white">
                                <div class="card-body text-center">
                                    <h4 class="mb-1"><?php echo $asset_count; ?></h4>
                                    <small>Assets</small>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-2">
                            <div class="card border-0 bg-warning text-white">
                                <div class="card-body text-center">
                                    <h4 class="mb-1"><?php echo $liability_count; ?></h4>
                                    <small>Liabilities</small>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-2">
                            <div class="card border-0 bg-info text-white">
                                <div class="card-body text-center">
                                    <h4 class="mb-1"><?php echo $income_count; ?></h4>
                                    <small>Income</small>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-2">
                            <div class="card border-0 bg-danger text-white">
                                <div class="card-body text-center">
                                    <h4 class="mb-1"><?php echo $expense_count; ?></h4>
                                    <small>Expenses</small>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-2">
                            <div class="card border-0 bg-secondary text-white">
                                <div class="card-body text-center">
                                    <h4 class="mb-1"><?php echo $equity_count; ?></h4>
                                    <small>Equity</small>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Account Type Breakdown -->
                    <div class="row mb-4">
                        <div class="col-md-6">
                            <div class="card border-0 bg-light">
                                <div class="card-body text-center">
                                    <h4 class="mb-1"><?php echo $group_count; ?></h4>
                                    <small>Group Accounts</small>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="card border-0 bg-light">
                                <div class="card-body text-center">
                                    <h4 class="mb-1"><?php echo $detail_count; ?></h4>
                                    <small>Detail Accounts</small>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Hierarchy Structure Info -->
                    <div class="card mb-4">
                        <div class="card-header bg-info text-white">
                            <h5 class="mb-0"><i class="bi bi-diagram-3 me-2"></i>Account Hierarchy Structure</h5>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-6">
                                    <h6>Account Code Structure:</h6>
                                    <ul class="mb-0">
                                        <li><strong>1xxx</strong> - Assets</li>
                                        <li><strong>2xxx</strong> - Liabilities</li>
                                        <li><strong>3xxx</strong> - Equity</li>
                                        <li><strong>4xxx</strong> - Income</li>
                                        <li><strong>5xxx</strong> - Expenses</li>
                                    </ul>
                                </div>
                                <div class="col-md-6">
                                    <h6>Hierarchy Levels:</h6>
                                    <ul class="mb-0">
                                        <li>Each digit represents a level in hierarchy</li>
                                        <li>Example: <code>1213</code> = Level 4 account</li>
                                        <li>Parent-Child relationships maintained</li>
                                        <li>Group accounts cannot have transactions</li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Advanced Filters -->
                    <div class="card mb-4">
                        <div class="card-header bg-secondary text-white">
                            <h5 class="mb-0"><i class="bi bi-funnel me-2"></i>Advanced Filters</h5>
                        </div>
                        <div class="card-body">
                            <form method="GET" class="row g-3">
                                <div class="col-md-4">
                                    <label class="form-label">Search</label>
                                    <input type="text" class="form-control" name="search" placeholder="Account # or Name" value="<?php echo htmlspecialchars($search); ?>">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Account Type</label>
                                    <select class="form-select" name="account_type">
                                        <option value="">All Types</option>
                                        <option value="asset" <?php echo $account_type_filter === 'asset' ? 'selected' : ''; ?>>Asset</option>
                                        <option value="liability" <?php echo $account_type_filter === 'liability' ? 'selected' : ''; ?>>Liability</option>
                                        <option value="equity" <?php echo $account_type_filter === 'equity' ? 'selected' : ''; ?>>Equity</option>
                                        <option value="income" <?php echo $account_type_filter === 'income' ? 'selected' : ''; ?>>Income</option>
                                        <option value="expense" <?php echo $account_type_filter === 'expense' ? 'selected' : ''; ?>>Expense</option>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Status</label>
                                    <select class="form-select" name="status">
                                        <option value="active" <?php echo $status_filter === 'active' ? 'selected' : ''; ?>>Active</option>
                                        <option value="inactive" <?php echo $status_filter === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                                        <option value="">All Status</option>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">&nbsp;</label>
                                    <div class="d-grid gap-2">
                                        <button type="submit" class="btn btn-primary">
                                            <i class="bi bi-filter me-1"></i>Apply Filters
                                        </button>
                                        <a href="chart_of_accounts.php" class="btn btn-outline-secondary">Clear Filters</a>
                                    </div>
                                </div>
                            </form>
                            
                            <!-- Active Filter Badges -->
                            <?php if ($search || $account_type_filter || $status_filter !== 'active'): ?>
                                <div class="mt-3">
                                    <small class="text-muted">Active filters:</small>
                                    <?php if ($search): ?>
                                        <span class="badge bg-info me-1">Search: <?php echo htmlspecialchars($search); ?></span>
                                    <?php endif; ?>
                                    <?php if ($account_type_filter): ?>
                                        <span class="badge bg-primary me-1">Type: <?php echo htmlspecialchars($account_type_filter); ?></span>
                                    <?php endif; ?>
                                    <?php if ($status_filter && $status_filter !== 'active'): ?>
                                        <span class="badge bg-secondary me-1">Status: <?php echo htmlspecialchars($status_filter); ?></span>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Accounts Table -->
                    <div class="card">
                        <div class="card-header bg-success text-white d-flex justify-content-between align-items-center">
                            <h5 class="mb-0">
                                <i class="bi bi-list-ul me-2"></i>
                                Accounts List 
                                <span class="badge bg-light text-dark ms-2"><?php echo count($accounts); ?> accounts</span>
                            </h5>
                            <div class="btn-group">
                                <button type="button" class="btn btn-light btn-sm" data-bs-toggle="modal" data-bs-target="#addAccountModal">
                                    <i class="bi bi-plus-circle me-1"></i>Add Account
                                </button>
                                <button type="button" class="btn btn-light btn-sm" onclick="exportAccounts()">
                                    <i class="bi bi-download me-1"></i>Export
                                </button>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-sm table-striped table-hover">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Account #</th>
                                            <th>Account Name</th>
                                            <th>Type</th>
                                            <th>Parent</th>
                                            <th>Level</th>
                                            <th>Normal Balance</th>
                                            <th>Group</th>
                                            <th>Status</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (!empty($accounts)): ?>
                                            <?php foreach ($accounts as $account): ?>
                                                <tr class="account-level-<?php echo $account['level']; ?>">
                                                    <td>
                                                        <span class="fw-bold text-primary"><?php echo htmlspecialchars($account['account_code']); ?></span>
                                                    </td>
                                                    <td>
                                                        <?php 
                                                        $indent = ($account['level'] - 1) * 20;
                                                        echo '<span style="padding-left: ' . $indent . 'px">';
                                                        echo htmlspecialchars($account['account_name']);
                                                        echo '</span>';
                                                        ?>
                                                    </td>
                                                    <td>
                                                        <span class="badge bg-<?php 
                                                            echo $account['account_type'] == 'income' ? 'info' : 
                                                                 ($account['account_type'] == 'expense' ? 'danger' : 
                                                                 ($account['account_type'] == 'equity' ? 'dark' :
                                                                 ($account['account_type'] == 'asset' ? 'success' : 
                                                                 ($account['account_type'] == 'liability' ? 'warning' : 'secondary')))); 
                                                        ?>">
                                                            <?php echo htmlspecialchars(ucfirst($account['account_type'])); ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <?php if ($account['parent_account_code']): ?>
                                                            <span class="text-muted"><?php echo htmlspecialchars($account['parent_account_code']); ?></span>
                                                        <?php else: ?>
                                                            <span class="text-muted">-</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <span class="badge bg-secondary">Level <?php echo $account['level']; ?></span>
                                                    </td>
                                                    <td>
                                                        <span class="badge bg-<?php echo $account['normal_balance'] == 'debit' ? 'primary' : 'success'; ?>">
                                                            <?php echo htmlspecialchars(ucfirst($account['normal_balance'])); ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <?php if ($account['is_group_account']): ?>
                                                            <span class="badge bg-info">Group</span>
                                                        <?php else: ?>
                                                            <span class="badge bg-light text-dark">Detail</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <span class="badge bg-<?php echo $account['is_active'] == 1 ? 'success' : 'secondary'; ?>">
                                                            <?php echo $account['is_active'] == 1 ? 'Active' : 'Inactive'; ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <div class="btn-group btn-group-sm">
                                                            <button type="button" class="btn btn-outline-primary" 
                                                                    data-bs-toggle="modal" 
                                                                    data-bs-target="#editAccountModal"
                                                                    data-id="<?php echo $account['id']; ?>"
                                                                    data-account_code="<?php echo htmlspecialchars($account['account_code']); ?>"
                                                                    data-account_name="<?php echo htmlspecialchars($account['account_name']); ?>"
                                                                    data-account_type="<?php echo htmlspecialchars($account['account_type']); ?>"
                                                                    data-parent_code="<?php echo htmlspecialchars($account['parent_account_code'] ?? ''); ?>"
                                                                    data-is_group_account="<?php echo $account['is_group_account']; ?>"
                                                                    data-description="<?php echo htmlspecialchars($account['description'] ?? ''); ?>">
                                                                <i class="bi bi-pencil"></i>
                                                            </button>
                                                            <?php if ($account['is_active'] == 1): ?>
                                                                <form method="POST" class="d-inline" onsubmit="return confirm('Are you sure you want to deactivate this account?\n\nNote: Accounts with children cannot be deleted.');">
                                                                    <input type="hidden" name="account_id" value="<?php echo $account['id']; ?>">
                                                                    <button type="submit" name="delete_account" class="btn btn-outline-danger">
                                                                        <i class="bi bi-trash"></i>
                                                                    </button>
                                                                </form>
                                                            <?php else: ?>
                                                                <form method="POST" class="d-inline" onsubmit="return confirm('Are you sure you want to restore this account?');">
                                                                    <input type="hidden" name="account_id" value="<?php echo $account['id']; ?>">
                                                                    <button type="submit" name="restore_account" class="btn btn-outline-success">
                                                                        <i class="bi bi-arrow-clockwise"></i>
                                                                    </button>
                                                                </form>
                                                            <?php endif; ?>
                                                        </div>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <tr>
                                                <td colspan="9" class="text-center py-4">
                                                    <i class="bi bi-inbox display-4 text-muted"></i>
                                                    <p class="mt-3 text-muted">No accounts found matching your criteria.</p>
                                                    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addAccountModal">
                                                        <i class="bi bi-plus-circle me-1"></i>Add First Account
                                                    </button>
                                                </td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Add Account Modal -->
<div class="modal fade" id="addAccountModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Add New Account</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Account Type <span class="text-danger">*</span></label>
                            <select class="form-select" name="account_type" id="account_type" required>
                                <option value="">Select Type</option>
                                <?php foreach ($account_types as $code => $name): ?>
                                    <option value="<?php echo htmlspecialchars($code); ?>">
                                        <?php echo htmlspecialchars($name); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Parent Account (Optional)</label>
                            <select class="form-select" name="parent_code" id="parent_code">
                                <option value="">-- Top Level Account --</option>
                                <?php foreach (getParentAccountOptions($db) as $parent): ?>
                                    <option value="<?php echo htmlspecialchars($parent['account_code']); ?>">
                                        <?php echo str_repeat('&nbsp;&nbsp;', $parent['level'] - 1) . htmlspecialchars($parent['account_code'] . ' - ' . $parent['account_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">Leave empty for top-level account</div>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Account Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="account_name" placeholder="e.g., Cash, Accounts Receivable, Sales Revenue" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Description (Optional)</label>
                            <textarea class="form-control" name="description" rows="2" placeholder="Account description..."></textarea>
                        </div>
                        <div class="col-12">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="is_group_account" id="is_group_account" value="1">
                                <label class="form-check-label" for="is_group_account">
                                    This is a group account (cannot post transactions directly)
                                </label>
                            </div>
                        </div>
                        <div class="col-12">
                            <div class="alert alert-info">
                                <i class="bi bi-info-circle me-2"></i>
                                <strong>Note:</strong> Account code will be automatically generated based on the hierarchy. Normal balance will be set automatically based on account type.
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="add_account" class="btn btn-primary">
                        <i class="bi bi-plus-circle me-1"></i>Add Account
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Account Modal -->
<div class="modal fade" id="editAccountModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Edit Account</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="account_id" id="edit_account_id">
                <input type="hidden" name="current_account_code" id="current_account_code">
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Account Type <span class="text-danger">*</span></label>
                            <select class="form-select" name="account_type" id="edit_account_type" required>
                                <option value="">Select Type</option>
                                <?php foreach ($account_types as $code => $name): ?>
                                    <option value="<?php echo htmlspecialchars($code); ?>">
                                        <?php echo htmlspecialchars($name); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Parent Account (Optional)</label>
                            <select class="form-select" name="parent_code" id="edit_parent_code">
                                <option value="">-- Top Level Account --</option>
                                <?php foreach (getParentAccountOptions($db) as $parent): ?>
                                    <option value="<?php echo htmlspecialchars($parent['account_code']); ?>">
                                        <?php echo str_repeat('&nbsp;&nbsp;', $parent['level'] - 1) . htmlspecialchars($parent['account_code'] . ' - ' . $parent['account_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">Changing parent will generate new account code</div>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Account Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="account_name" id="edit_account_name" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Current Account Code</label>
                            <input type="text" class="form-control" id="display_account_code" readonly>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Description (Optional)</label>
                            <textarea class="form-control" name="description" id="edit_description" rows="2"></textarea>
                        </div>
                        <div class="col-12">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="is_group_account" id="edit_is_group_account" value="1">
                                <label class="form-check-label" for="edit_is_group_account">
                                    This is a group account (cannot post transactions directly)
                                </label>
                            </div>
                        </div>
                        <div class="col-12">
                            <div class="alert alert-warning">
                                <i class="bi bi-exclamation-triangle me-2"></i>
                                <strong>Important:</strong> Changing the parent account will generate a new account code. The old account code will no longer be used.
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="update_account" class="btn btn-primary">Update Account</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Edit modal functionality
    const editModal = document.getElementById('editAccountModal');
    editModal.addEventListener('show.bs.modal', function(event) {
        const button = event.relatedTarget;
        
        // Set form values from data attributes
        document.getElementById('edit_account_id').value = button.getAttribute('data-id');
        document.getElementById('current_account_code').value = button.getAttribute('data-account_code');
        document.getElementById('display_account_code').value = button.getAttribute('data-account_code');
        document.getElementById('edit_account_name').value = button.getAttribute('data-account_name');
        document.getElementById('edit_account_type').value = button.getAttribute('data-account_type');
        document.getElementById('edit_parent_code').value = button.getAttribute('data-parent_code');
        document.getElementById('edit_description').value = button.getAttribute('data-description');
        
        // Set checkbox state
        const isGroupCheckbox = document.getElementById('edit_is_group_account');
        isGroupCheckbox.checked = button.getAttribute('data-is_group_account') === '1';
        
        // Update parent options to exclude current account and its descendants
        updateParentOptions(button.getAttribute('data-account_code'));
    });

    // Function to update parent options in edit modal
    function updateParentOptions(currentAccountCode) {
        const parentSelect = document.getElementById('edit_parent_code');
        const options = parentSelect.options;
        
        // Disable current account and its potential descendants
        for (let i = 0; i < options.length; i++) {
            const option = options[i];
            if (option.value && option.value.startsWith(currentAccountCode)) {
                option.disabled = true;
                option.style.color = '#ccc';
            }
        }
    }
    
    // Style rows based on level
    document.querySelectorAll('.account-level-1').forEach(row => {
        row.style.fontWeight = 'bold';
        row.style.backgroundColor = '#f8f9fa';
    });
    
    document.querySelectorAll('.account-level-2').forEach(row => {
        row.style.fontWeight = '500';
    });
});

// Export function
function exportAccounts() {
    // Build URL with current filter parameters
    let url = 'export_accounts.php?format=csv';
    
    // Get current filter values from the form
    const searchInput = document.querySelector('input[name="search"]');
    const accountTypeSelect = document.querySelector('select[name="account_type"]');
    const statusSelect = document.querySelector('select[name="status"]');
    
    if (searchInput && searchInput.value) {
        url += '&search=' + encodeURIComponent(searchInput.value);
    }
    
    if (accountTypeSelect && accountTypeSelect.value) {
        url += '&account_type=' + encodeURIComponent(accountTypeSelect.value);
    }
    
    if (statusSelect && statusSelect.value) {
        url += '&status=' + encodeURIComponent(statusSelect.value);
    }
    
    window.location.href = url;
}
</script>

<style>
.account-level-1 { border-left: 4px solid #0d6efd; }
.account-level-2 { border-left: 4px solid #198754; }
.account-level-3 { border-left: 4px solid #fd7e14; }
.account-level-4 { border-left: 4px solid #6f42c1; }
.account-level-5 { border-left: 4px solid #20c997; }
.account-level-6 { border-left: 4px solid #dc3545; }
</style>

<?php include '../includes/footer.php'; ?>