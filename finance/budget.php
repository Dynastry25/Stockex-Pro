<?php
// /finance/budget.php - Budget Management System
require_once '../config/config.php';
require_once '../auth/auth_middleware.php';

// Security headers
header("X-Frame-Options: DENY");
header("X-Content-Type-Options: nosniff");
header("X-XSS-Protection: 1; mode=block");
header("Referrer-Policy: strict-origin-when-cross-origin");
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://fonts.googleapis.com; font-src 'self' https://cdn.jsdelivr.net https://fonts.gstatic.com");

require_finance_officer();

// CSRF Protection
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$db = getDBConnection();
$success_message = '';
$error_message = '';

$user_role = $_SESSION['role'] ?? '';
$user_id = $_SESSION['user_id'] ?? null;
$username = $_SESSION['username'] ?? 'system';

// =====================================================
// DATABASE TABLE CREATION (Run once)
// =====================================================
function createBudgetTables($db) {
    try {
        // Budget Plans Table
        $db->exec("
            CREATE TABLE IF NOT EXISTS budgets (
                id INT PRIMARY KEY AUTO_INCREMENT,
                budget_code VARCHAR(20) UNIQUE NOT NULL,
                fiscal_year YEAR NOT NULL,
                fiscal_period VARCHAR(20) NOT NULL,
                budget_type ENUM('annual', 'quarterly', 'monthly') DEFAULT 'annual',
                description TEXT,
                total_budget DECIMAL(15,2) DEFAULT 0,
                total_actual DECIMAL(15,2) DEFAULT 0,
                total_variance DECIMAL(15,2) DEFAULT 0,
                variance_percentage DECIMAL(10,2) DEFAULT 0,
                status ENUM('draft', 'pending', 'under_review', 'approved', 'rejected', 'active', 'closed') DEFAULT 'draft',
                created_by INT,
                created_by_username VARCHAR(100),
                reviewed_by INT,
                reviewed_by_username VARCHAR(100),
                reviewed_at DATETIME,
                approved_by INT,
                approved_by_username VARCHAR(100),
                approved_at DATETIME,
                rejection_reason TEXT,
                notes TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_fiscal (fiscal_year, fiscal_period),
                INDEX idx_status (status)
            )
        ");

        // Budget Categories Table
        $db->exec("
            CREATE TABLE IF NOT EXISTS budget_categories (
                id INT PRIMARY KEY AUTO_INCREMENT,
                budget_id INT NOT NULL,
                category_code VARCHAR(50) NOT NULL,
                category_name VARCHAR(200) NOT NULL,
                category_type ENUM('income', 'expense', 'goal') DEFAULT 'expense',
                parent_category_id INT DEFAULT NULL,
                budget_amount DECIMAL(15,2) DEFAULT 0,
                actual_amount DECIMAL(15,2) DEFAULT 0,
                variance DECIMAL(15,2) DEFAULT 0,
                variance_percentage DECIMAL(10,2) DEFAULT 0,
                progress_percentage DECIMAL(10,2) DEFAULT 0,
                is_group BOOLEAN DEFAULT FALSE,
                group_level INT DEFAULT 1,
                description TEXT,
                status ENUM('active', 'inactive') DEFAULT 'active',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (budget_id) REFERENCES budgets(id) ON DELETE CASCADE,
                INDEX idx_category (category_code),
                INDEX idx_type (category_type)
            )
        ");

        // Budget Goals Table
        $db->exec("
            CREATE TABLE IF NOT EXISTS budget_goals (
                id INT PRIMARY KEY AUTO_INCREMENT,
                budget_id INT NOT NULL,
                goal_code VARCHAR(50) UNIQUE NOT NULL,
                goal_name VARCHAR(200) NOT NULL,
                category_id INT,
                goal_type ENUM('financial', 'operational', 'strategic', 'performance') DEFAULT 'financial',
                target_value DECIMAL(15,2) DEFAULT 0,
                target_unit VARCHAR(50) DEFAULT 'Tsh',
                actual_value DECIMAL(15,2) DEFAULT 0,
                achievement_percentage DECIMAL(10,2) DEFAULT 0,
                start_date DATE,
                end_date DATE,
                priority ENUM('low', 'medium', 'high', 'critical') DEFAULT 'medium',
                status ENUM('draft', 'active', 'achieved', 'failed', 'archived') DEFAULT 'draft',
                progress_notes TEXT,
                created_by INT,
                created_by_username VARCHAR(100),
                approved_by INT,
                approved_by_username VARCHAR(100),
                approved_at DATETIME,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (budget_id) REFERENCES budgets(id) ON DELETE CASCADE,
                FOREIGN KEY (category_id) REFERENCES budget_categories(id) ON DELETE SET NULL,
                INDEX idx_goal_type (goal_type),
                INDEX idx_status (status)
            )
        ");

        // Budget Monitoring Table
        $db->exec("
            CREATE TABLE IF NOT EXISTS budget_monitoring (
                id INT PRIMARY KEY AUTO_INCREMENT,
                budget_id INT NOT NULL,
                category_id INT NOT NULL,
                monitoring_date DATE NOT NULL,
                actual_amount DECIMAL(15,2) DEFAULT 0,
                cumulative_actual DECIMAL(15,2) DEFAULT 0,
                budget_amount DECIMAL(15,2) DEFAULT 0,
                variance DECIMAL(15,2) DEFAULT 0,
                variance_percentage DECIMAL(10,2) DEFAULT 0,
                source_type ENUM('receipt', 'payment', 'manual') DEFAULT 'manual',
                source_reference VARCHAR(100),
                notes TEXT,
                recorded_by INT,
                recorded_by_username VARCHAR(100),
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (budget_id) REFERENCES budgets(id) ON DELETE CASCADE,
                FOREIGN KEY (category_id) REFERENCES budget_categories(id) ON DELETE CASCADE,
                INDEX idx_date (monitoring_date),
                INDEX idx_source (source_type, source_reference)
            )
        ");

        // Budget History Table
        $db->exec("
            CREATE TABLE IF NOT EXISTS budget_history (
                id INT PRIMARY KEY AUTO_INCREMENT,
                budget_id INT NOT NULL,
                action VARCHAR(50) NOT NULL,
                field_name VARCHAR(100),
                old_value TEXT,
                new_value TEXT,
                notes TEXT,
                performed_by INT,
                performed_by_username VARCHAR(100),
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (budget_id) REFERENCES budgets(id) ON DELETE CASCADE,
                INDEX idx_action (action)
            )
        ");

        return true;
    } catch (PDOException $e) {
        error_log("Error creating budget tables: " . $e->getMessage());
        return false;
    }
}

// Create tables on first run
createBudgetTables($db);

// =====================================================
// HELPER FUNCTIONS
// =====================================================

function validateDate($date, $format = 'Y-m-d') {
    $d = DateTime::createFromFormat($format, $date);
    return $d && $d->format($format) === $date;
}

function sanitizeInput($data) {
    if (is_array($data)) {
        return array_map('sanitizeInput', $data);
    }
    return htmlspecialchars(trim($data), ENT_QUOTES, 'UTF-8');
}

function validateAmount($amount) {
    return is_numeric($amount) && $amount >= 0 && $amount <= 99999999999.99;
}

function generateBudgetCode($db, $fiscal_year, $period) {
    $prefix = 'BGT';
    $year = date('Y', strtotime($fiscal_year . '-01-01'));
    $period_code = strtoupper(substr($period, 0, 3));
    
    $stmt = $db->prepare("
        SELECT budget_code FROM budgets 
        WHERE budget_code LIKE ? 
        ORDER BY id DESC LIMIT 1
    ");
    $stmt->execute([$prefix . $year . $period_code . '%']);
    $last = $stmt->fetch();
    
    if ($last) {
        $last_seq = intval(substr($last['budget_code'], -4));
        $new_seq = str_pad($last_seq + 1, 4, '0', STR_PAD_LEFT);
    } else {
        $new_seq = '0001';
    }
    
    return $prefix . $year . $period_code . $new_seq;
}

function getBudgetStatusBadge($status) {
    $badges = [
        'draft' => 'bg-secondary',
        'pending' => 'bg-warning',
        'under_review' => 'bg-info',
        'approved' => 'bg-success',
        'rejected' => 'bg-danger',
        'active' => 'bg-primary',
        'closed' => 'bg-dark'
    ];
    return $badges[$status] ?? 'bg-secondary';
}

function getBudgetStatusLabel($status) {
    $labels = [
        'draft' => 'Draft',
        'pending' => 'Pending Review',
        'under_review' => 'Under Review',
        'approved' => 'Approved',
        'rejected' => 'Rejected',
        'active' => 'Active',
        'closed' => 'Closed'
    ];
    return $labels[$status] ?? ucfirst($status);
}

function getPriorityBadge($priority) {
    $badges = [
        'low' => 'bg-secondary',
        'medium' => 'bg-info',
        'high' => 'bg-warning',
        'critical' => 'bg-danger'
    ];
    return $badges[$priority] ?? 'bg-secondary';
}

function getGoalTypeBadge($type) {
    $badges = [
        'financial' => 'bg-success',
        'operational' => 'bg-primary',
        'strategic' => 'bg-info',
        'performance' => 'bg-warning'
    ];
    return $badges[$type] ?? 'bg-secondary';
}

function logBudgetHistory($db, $budget_id, $action, $field_name = null, $old_value = null, $new_value = null, $notes = null) {
    $stmt = $db->prepare("
        INSERT INTO budget_history (
            budget_id, action, field_name, old_value, new_value, notes,
            performed_by, performed_by_username
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");
    
    $user_id = $_SESSION['user_id'] ?? null;
    $username = $_SESSION['username'] ?? 'system';
    
    return $stmt->execute([
        $budget_id,
        $action,
        $field_name,
        $old_value,
        $new_value,
        $notes,
        $user_id,
        $username
    ]);
}

function getActualAmountsForBudget($db, $budget_id, $category_id = null) {
    $params = [$budget_id];
    $category_condition = '';
    
    if ($category_id) {
        $category_condition = " AND category_id = ?";
        $params[] = $category_id;
    }
    
    $stmt = $db->prepare("
        SELECT 
            COALESCE(SUM(actual_amount), 0) as total_actual,
            COUNT(*) as records_count
        FROM budget_monitoring 
        WHERE budget_id = ?
        $category_condition
    ");
    $stmt->execute($params);
    return $stmt->fetch();
}

function calculateBudgetVariance($budget_amount, $actual_amount) {
    $variance = $actual_amount - $budget_amount;
    $variance_percentage = 0;
    
    if ($budget_amount > 0) {
        $variance_percentage = ($variance / $budget_amount) * 100;
    }
    
    return [
        'variance' => $variance,
        'variance_percentage' => $variance_percentage
    ];
}

function getCategoryTypeColor($type) {
    $colors = [
        'income' => 'text-success',
        'expense' => 'text-danger',
        'goal' => 'text-primary'
    ];
    return $colors[$type] ?? 'text-secondary';
}

function getFiscalPeriods() {
    return [
        'Q1' => 'January - March',
        'Q2' => 'April - June',
        'Q3' => 'July - September',
        'Q4' => 'October - December'
    ];
}

function getFiscalYears($year = null) {
    $current = $year ?? date('Y');
    $years = [];
    for ($i = -2; $i <= 2; $i++) {
        $years[] = $current + $i;
    }
    sort($years);
    return $years;
}

function updateBudgetTotals($db, $budget_id) {
    try {
        // Update category totals
        $stmt = $db->prepare("
            UPDATE budget_categories c
            SET actual_amount = (
                SELECT COALESCE(SUM(actual_amount), 0)
                FROM budget_monitoring
                WHERE category_id = c.id
            ),
            variance = budget_amount - (
                SELECT COALESCE(SUM(actual_amount), 0)
                FROM budget_monitoring
                WHERE category_id = c.id
            ),
            variance_percentage = CASE 
                WHEN budget_amount > 0 THEN 
                    ((budget_amount - (SELECT COALESCE(SUM(actual_amount), 0) FROM budget_monitoring WHERE category_id = c.id)) / budget_amount) * 100
                ELSE 0
            END,
            progress_percentage = CASE 
                WHEN budget_amount > 0 THEN 
                    ((SELECT COALESCE(SUM(actual_amount), 0) FROM budget_monitoring WHERE category_id = c.id) / budget_amount) * 100
                ELSE 0
            END
            WHERE budget_id = ?
        ");
        $stmt->execute([$budget_id]);
        
        // Update budget totals
        $stmt = $db->prepare("
            UPDATE budgets b
            SET 
                total_budget = (
                    SELECT COALESCE(SUM(budget_amount), 0)
                    FROM budget_categories
                    WHERE budget_id = b.id AND category_type != 'goal'
                ),
                total_actual = (
                    SELECT COALESCE(SUM(actual_amount), 0)
                    FROM budget_categories
                    WHERE budget_id = b.id AND category_type != 'goal'
                ),
                total_variance = (
                    SELECT COALESCE(SUM(budget_amount - actual_amount), 0)
                    FROM budget_categories
                    WHERE budget_id = b.id AND category_type != 'goal'
                ),
                variance_percentage = CASE 
                    WHEN (
                        SELECT COALESCE(SUM(budget_amount), 0)
                        FROM budget_categories
                        WHERE budget_id = b.id AND category_type != 'goal'
                    ) > 0 THEN 
                        ((
                            SELECT COALESCE(SUM(budget_amount - actual_amount), 0)
                            FROM budget_categories
                            WHERE budget_id = b.id AND category_type != 'goal'
                        ) / (
                            SELECT COALESCE(SUM(budget_amount), 0)
                            FROM budget_categories
                            WHERE budget_id = b.id AND category_type != 'goal'
                        )) * 100
                    ELSE 0
                END,
                updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$budget_id]);
        
        return true;
    } catch (PDOException $e) {
        error_log("Error updating budget totals: " . $e->getMessage());
        return false;
    }
}

// =====================================================
// CHART OF ACCOUNTS INTEGRATION FUNCTIONS
// =====================================================

function getChartAccountsForBudget($db, $account_type = null, $level_min = 3) {
    $params = [];
    $conditions = ["is_active = 1", "(is_group_account = 0 OR level >= ?)"];
    $params[] = $level_min;
    
    if ($account_type) {
        $conditions[] = "account_type = ?";
        $params[] = $account_type;
    }
    
    $where = implode(" AND ", $conditions);
    
    $stmt = $db->prepare("
        SELECT account_code, account_name, account_type, level, normal_balance, id
        FROM chart_of_accounts 
        WHERE $where
        ORDER BY account_type, account_code
    ");
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function getIncomeAccounts($db) {
    return getChartAccountsForBudget($db, 'income', 3);
}

function getExpenseAccounts($db) {
    return getChartAccountsForBudget($db, 'expense', 3);
}

function getAssetAccounts($db) {
    return getChartAccountsForBudget($db, 'asset', 3);
}

function getLiabilityAccounts($db) {
    return getChartAccountsForBudget($db, 'liability', 3);
}

function getEquityAccounts($db) {
    return getChartAccountsForBudget($db, 'equity', 3);
}

function mapAccountTypeToBudgetType($account_type) {
    $mapping = [
        'income' => 'income',
        'expense' => 'expense',
        'asset' => 'goal',
        'liability' => 'goal',
        'equity' => 'goal'
    ];
    return $mapping[$account_type] ?? 'expense';
}

function createBudgetWithChartAccounts($db, $data) {
    try {
        $db->beginTransaction();
        
        // Create budget
        $budget_code = generateBudgetCode($db, $data['fiscal_year'], $data['fiscal_period']);
        
        $stmt = $db->prepare("
            INSERT INTO budgets (
                budget_code, fiscal_year, fiscal_period, budget_type,
                description, status, notes,
                created_by, created_by_username, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([
            $budget_code, $data['fiscal_year'], $data['fiscal_period'], $data['budget_type'],
            $data['description'], $data['status'], $data['notes'],
            $data['user_id'], $data['username']
        ]);
        
        $budget_id = $db->lastInsertId();
        
        // Fetch categories from chart of accounts
        $income_accounts = getIncomeAccounts($db);
        $expense_accounts = getExpenseAccounts($db);
        $added = 0;
        
        // Create income categories
        foreach ($income_accounts as $account) {
            $stmt = $db->prepare("
                INSERT INTO budget_categories (
                    budget_id, category_code, category_name, category_type,
                    budget_amount, actual_amount, description, status, created_at
                ) VALUES (?, ?, ?, 'income', 0, 0, ?, 'active', NOW())
            ");
            $stmt->execute([
                $budget_id,
                $account['account_code'],
                $account['account_name'],
                "Auto-created from chart: Income - " . $account['account_type']
            ]);
            $added++;
        }
        
        // Create expense categories
        foreach ($expense_accounts as $account) {
            $stmt = $db->prepare("
                INSERT INTO budget_categories (
                    budget_id, category_code, category_name, category_type,
                    budget_amount, actual_amount, description, status, created_at
                ) VALUES (?, ?, ?, 'expense', 0, 0, ?, 'active', NOW())
            ");
            $stmt->execute([
                $budget_id,
                $account['account_code'],
                $account['account_name'],
                "Auto-created from chart: Expense - " . $account['account_type']
            ]);
            $added++;
        }
        
        logBudgetHistory($db, $budget_id, 'create', null, null, null, "Budget created from chart of accounts: $budget_code");
        
        $db->commit();
        
        return [
            'success' => true,
            'budget_id' => $budget_id,
            'budget_code' => $budget_code,
            'categories_added' => $added
        ];
        
    } catch (Exception $e) {
        $db->rollBack();
        error_log("Budget creation error: " . $e->getMessage());
        throw $e;
    }
}

function syncBudgetCategoriesFromChart($db, $budget_id) {
    try {
        $db->beginTransaction();
        
        $income_accounts = getIncomeAccounts($db);
        $expense_accounts = getExpenseAccounts($db);
        $all_accounts = array_merge($income_accounts, $expense_accounts);
        $added = 0;
        
        foreach ($all_accounts as $account) {
            // Check if category already exists
            $stmt = $db->prepare("
                SELECT id FROM budget_categories 
                WHERE budget_id = ? AND category_code = ?
            ");
            $stmt->execute([$budget_id, $account['account_code']]);
            $existing = $stmt->fetch();
            
            if (!$existing) {
                $type = in_array($account, $income_accounts) ? 'income' : 'expense';
                $stmt = $db->prepare("
                    INSERT INTO budget_categories (
                        budget_id, category_code, category_name, category_type,
                        budget_amount, actual_amount, description, status, created_at
                    ) VALUES (?, ?, ?, ?, 0, 0, ?, 'active', NOW())
                ");
                $stmt->execute([
                    $budget_id,
                    $account['account_code'],
                    $account['account_name'],
                    $type,
                    "Synced from chart of accounts"
                ]);
                $added++;
            }
        }
        
        $db->commit();
        return $added;
    } catch (Exception $e) {
        $db->rollBack();
        throw $e;
    }
}

// =====================================================
// AJAX HANDLERS
// =====================================================

if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    
    // ============ GET BUDGET DETAILS ============
    if ($_GET['ajax'] == 'get_budget') {
        $budget_id = $_GET['budget_id'] ?? 0;
        
        try {
            $stmt = $db->prepare("
                SELECT b.*, 
                       u1.username as created_by_name,
                       u2.username as reviewed_by_name,
                       u3.username as approved_by_name
                FROM budgets b
                LEFT JOIN users u1 ON b.created_by = u1.id
                LEFT JOIN users u2 ON b.reviewed_by = u2.id
                LEFT JOIN users u3 ON b.approved_by = u3.id
                WHERE b.id = ?
            ");
            $stmt->execute([$budget_id]);
            $budget = $stmt->fetch();
            
            if ($budget) {
                // Get categories
                $cat_stmt = $db->prepare("
                    SELECT * FROM budget_categories 
                    WHERE budget_id = ? AND status = 'active'
                    ORDER BY category_type, category_code
                ");
                $cat_stmt->execute([$budget_id]);
                $budget['categories'] = $cat_stmt->fetchAll();
                
                // Get goals
                $goal_stmt = $db->prepare("
                    SELECT * FROM budget_goals 
                    WHERE budget_id = ? AND status != 'archived'
                    ORDER BY priority DESC, goal_code
                ");
                $goal_stmt->execute([$budget_id]);
                $budget['goals'] = $goal_stmt->fetchAll();
                
                echo json_encode($budget);
            } else {
                echo json_encode(['error' => 'Budget not found']);
            }
        } catch (PDOException $e) {
            echo json_encode(['error' => $e->getMessage()]);
        }
        exit;
    }
    
    // ============ GET CATEGORY DETAILS ============
    if ($_GET['ajax'] == 'get_category') {
        $category_id = $_GET['category_id'] ?? 0;
        
        try {
            $stmt = $db->prepare("
                SELECT * FROM budget_categories WHERE id = ?
            ");
            $stmt->execute([$category_id]);
            $category = $stmt->fetch();
            
            if ($category) {
                $actual_data = getActualAmountsForBudget($db, $category['budget_id'], $category['id']);
                $category['actual_amount'] = $actual_data['total_actual'];
                $category['records_count'] = $actual_data['records_count'];
                
                echo json_encode($category);
            } else {
                echo json_encode(['error' => 'Category not found']);
            }
        } catch (PDOException $e) {
            echo json_encode(['error' => $e->getMessage()]);
        }
        exit;
    }
    
    // ============ GET MONITORING DATA ============
    if ($_GET['ajax'] == 'get_monitoring') {
        $budget_id = $_GET['budget_id'] ?? 0;
        $category_id = $_GET['category_id'] ?? null;
        
        try {
            $params = [$budget_id];
            $category_condition = '';
            
            if ($category_id) {
                $category_condition = " AND category_id = ?";
                $params[] = $category_id;
            }
            
            $stmt = $db->prepare("
                SELECT m.*, 
                       c.category_code, c.category_name, c.category_type,
                       u.username as recorded_by_name
                FROM budget_monitoring m
                LEFT JOIN budget_categories c ON m.category_id = c.id
                LEFT JOIN users u ON m.recorded_by = u.id
                WHERE m.budget_id = ?
                $category_condition
                ORDER BY m.monitoring_date DESC, m.created_at DESC
                LIMIT 100
            ");
            $stmt->execute($params);
            $data = $stmt->fetchAll();
            
            echo json_encode($data);
        } catch (PDOException $e) {
            echo json_encode(['error' => $e->getMessage()]);
        }
        exit;
    }
    
    // ============ GET BUDGET SUMMARY ============
    if ($_GET['ajax'] == 'get_summary') {
        $budget_id = $_GET['budget_id'] ?? 0;
        
        try {
            $stmt = $db->prepare("
                SELECT 
                    COALESCE(SUM(budget_amount), 0) as total_budget,
                    COALESCE(SUM(actual_amount), 0) as total_actual
                FROM budget_categories 
                WHERE budget_id = ? AND status = 'active' AND category_type != 'goal'
            ");
            $stmt->execute([$budget_id]);
            $totals = $stmt->fetch();
            
            $stmt = $db->prepare("
                SELECT 
                    category_type,
                    COALESCE(SUM(budget_amount), 0) as total_budget,
                    COALESCE(SUM(actual_amount), 0) as total_actual
                FROM budget_categories 
                WHERE budget_id = ? AND status = 'active' AND category_type != 'goal'
                GROUP BY category_type
            ");
            $stmt->execute([$budget_id]);
            $breakdown = $stmt->fetchAll();
            
            $stmt = $db->prepare("
                SELECT 
                    COUNT(*) as total_goals,
                    SUM(CASE WHEN status = 'achieved' THEN 1 ELSE 0 END) as achieved_goals,
                    COALESCE(AVG(achievement_percentage), 0) as avg_progress
                FROM budget_goals 
                WHERE budget_id = ? AND status != 'archived'
            ");
            $stmt->execute([$budget_id]);
            $goal_summary = $stmt->fetch();
            
            echo json_encode([
                'totals' => $totals,
                'breakdown' => $breakdown,
                'goals' => $goal_summary
            ]);
        } catch (PDOException $e) {
            echo json_encode(['error' => $e->getMessage()]);
        }
        exit;
    }
}

// =====================================================
// EXPORT FUNCTIONS
// =====================================================

function exportBudgetToExcel($db, $budget_id) {
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="budget_' . date('Y-m-d_H-i-s') . '.xls"');
    header('Pragma: no-cache');
    header('Expires: 0');

    echo "\xEF\xBB\xBF";
    
    $stmt = $db->prepare("SELECT * FROM budgets WHERE id = ?");
    $stmt->execute([$budget_id]);
    $budget = $stmt->fetch();
    
    if (!$budget) {
        echo "Budget not found";
        exit;
    }
    
    echo '<h2>BUDGET REPORT</h2>';
    echo "<h3>{$budget['budget_code']} - {$budget['fiscal_year']} ({$budget['fiscal_period']})</h3>";
    echo "<p>Status: " . getBudgetStatusLabel($budget['status']) . "</p>";
    echo "<p>Total Budget: " . number_format($budget['total_budget'], 2) . "</p>";
    echo "<p>Total Actual: " . number_format($budget['total_actual'], 2) . "</p>";
    echo "<p>Variance: " . number_format($budget['total_variance'], 2) . "</p>";
    echo "<hr>";
    
    // Categories
    echo '<h3>Budget Categories</h3>';
    echo '<table border="1">';
    echo '<tr>';
    echo '<th>Category Code</th>';
    echo '<th>Category Name</th>';
    echo '<th>Type</th>';
    echo '<th>Budget Amount</th>';
    echo '<th>Actual Amount</th>';
    echo '<th>Variance</th>';
    echo '<th>Variance %</th>';
    echo '<th>Progress %</th>';
    echo '</tr>';
    
    $cat_stmt = $db->prepare("
        SELECT * FROM budget_categories 
        WHERE budget_id = ? AND status = 'active'
        ORDER BY category_type, category_code
    ");
    $cat_stmt->execute([$budget_id]);
    
    while ($cat = $cat_stmt->fetch()) {
        echo '<tr>';
        echo '<td>' . htmlspecialchars($cat['category_code']) . '</td>';
        echo '<td>' . htmlspecialchars($cat['category_name']) . '</td>';
        echo '<td>' . htmlspecialchars($cat['category_type']) . '</td>';
        echo '<td>' . number_format($cat['budget_amount'], 2) . '</td>';
        echo '<td>' . number_format($cat['actual_amount'], 2) . '</td>';
        echo '<td>' . number_format($cat['variance'], 2) . '</td>';
        echo '<td>' . number_format($cat['variance_percentage'], 2) . '%</td>';
        echo '<td>' . number_format($cat['progress_percentage'], 2) . '%</td>';
        echo '</tr>';
    }
    echo '</table>';
    echo '<hr>';
    
    // Goals
    echo '<h3>Budget Goals</h3>';
    echo '<table border="1">';
    echo '<tr>';
    echo '<th>Goal Code</th>';
    echo '<th>Goal Name</th>';
    echo '<th>Type</th>';
    echo '<th>Target</th>';
    echo '<th>Actual</th>';
    echo '<th>Achievement %</th>';
    echo '<th>Priority</th>';
    echo '<th>Status</th>';
    echo '</tr>';
    
    $goal_stmt = $db->prepare("
        SELECT * FROM budget_goals 
        WHERE budget_id = ? AND status != 'archived'
        ORDER BY priority DESC, goal_code
    ");
    $goal_stmt->execute([$budget_id]);
    
    while ($goal = $goal_stmt->fetch()) {
        echo '<tr>';
        echo '<td>' . htmlspecialchars($goal['goal_code']) . '</td>';
        echo '<td>' . htmlspecialchars($goal['goal_name']) . '</td>';
        echo '<td>' . htmlspecialchars($goal['goal_type']) . '</td>';
        echo '<td>' . number_format($goal['target_value'], 2) . ' ' . htmlspecialchars($goal['target_unit']) . '</td>';
        echo '<td>' . number_format($goal['actual_value'], 2) . '</td>';
        echo '<td>' . number_format($goal['achievement_percentage'], 2) . '%</td>';
        echo '<td>' . htmlspecialchars($goal['priority']) . '</td>';
        echo '<td>' . htmlspecialchars($goal['status']) . '</td>';
        echo '</tr>';
    }
    echo '</table>';
    exit;
}

function exportBudgetToPDF($db, $budget_id) {
    require_once '../tcpdf/tcpdf.php';
    
    $stmt = $db->prepare("SELECT * FROM budgets WHERE id = ?");
    $stmt->execute([$budget_id]);
    $budget = $stmt->fetch();
    
    if (!$budget) {
        die('Budget not found');
    }
    
    $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
    $pdf->SetCreator('Stock Exchange System');
    $pdf->SetAuthor('Finance Department');
    $pdf->SetTitle('Budget Report - ' . $budget['budget_code']);
    $pdf->SetMargins(10, 15, 10);
    $pdf->AddPage();
    
    $pdf->SetFont('helvetica', 'B', 16);
    $pdf->Cell(0, 10, 'BUDGET REPORT', 0, 1, 'C');
    $pdf->SetFont('helvetica', 'B', 14);
    $pdf->Cell(0, 8, $budget['budget_code'] . ' - ' . $budget['fiscal_year'] . ' (' . $budget['fiscal_period'] . ')', 0, 1, 'C');
    $pdf->Ln(5);
    
    $pdf->SetFont('helvetica', 'B', 12);
    $pdf->Cell(0, 8, 'Budget Summary', 0, 1, 'L');
    $pdf->SetFont('helvetica', '', 10);
    $pdf->Cell(40, 6, 'Status:', 0, 0);
    $pdf->Cell(80, 6, getBudgetStatusLabel($budget['status']), 0, 1);
    $pdf->Cell(40, 6, 'Total Budget:', 0, 0);
    $pdf->Cell(80, 6, number_format($budget['total_budget'], 2), 0, 1);
    $pdf->Cell(40, 6, 'Total Actual:', 0, 0);
    $pdf->Cell(80, 6, number_format($budget['total_actual'], 2), 0, 1);
    $pdf->Cell(40, 6, 'Variance:', 0, 0);
    $pdf->Cell(80, 6, number_format($budget['total_variance'], 2), 0, 1);
    $pdf->Ln(5);
    
    // Categories Table
    $pdf->SetFont('helvetica', 'B', 11);
    $pdf->Cell(0, 8, 'Budget Categories', 0, 1, 'L');
    
    $pdf->SetFont('helvetica', 'B', 8);
    $pdf->Cell(30, 6, 'Code', 1, 0, 'C');
    $pdf->Cell(50, 6, 'Category Name', 1, 0, 'C');
    $pdf->Cell(25, 6, 'Type', 1, 0, 'C');
    $pdf->Cell(30, 6, 'Budget', 1, 0, 'R');
    $pdf->Cell(30, 6, 'Actual', 1, 0, 'R');
    $pdf->Cell(25, 6, 'Variance', 1, 0, 'R');
    $pdf->Cell(25, 6, 'Progress', 1, 1, 'R');
    
    $cat_stmt = $db->prepare("
        SELECT * FROM budget_categories 
        WHERE budget_id = ? AND status = 'active'
        ORDER BY category_type, category_code
    ");
    $cat_stmt->execute([$budget_id]);
    
    $pdf->SetFont('helvetica', '', 8);
    while ($cat = $cat_stmt->fetch()) {
        $pdf->Cell(30, 5, $cat['category_code'], 1, 0);
        $pdf->Cell(50, 5, substr($cat['category_name'], 0, 25), 1, 0);
        $pdf->Cell(25, 5, $cat['category_type'], 1, 0);
        $pdf->Cell(30, 5, number_format($cat['budget_amount'], 0), 1, 0, 'R');
        $pdf->Cell(30, 5, number_format($cat['actual_amount'], 0), 1, 0, 'R');
        $pdf->Cell(25, 5, number_format($cat['variance'], 0), 1, 0, 'R');
        $pdf->Cell(25, 5, number_format($cat['progress_percentage'], 0) . '%', 1, 1, 'R');
    }
    $pdf->Ln(5);
    
    // Goals Table
    $pdf->SetFont('helvetica', 'B', 11);
    $pdf->Cell(0, 8, 'Budget Goals', 0, 1, 'L');
    
    $pdf->SetFont('helvetica', 'B', 8);
    $pdf->Cell(30, 6, 'Goal Code', 1, 0, 'C');
    $pdf->Cell(50, 6, 'Goal Name', 1, 0, 'C');
    $pdf->Cell(25, 6, 'Type', 1, 0, 'C');
    $pdf->Cell(25, 6, 'Target', 1, 0, 'R');
    $pdf->Cell(25, 6, 'Achieved', 1, 0, 'R');
    $pdf->Cell(25, 6, 'Progress', 1, 0, 'R');
    $pdf->Cell(25, 6, 'Status', 1, 1, 'C');
    
    $goal_stmt = $db->prepare("
        SELECT * FROM budget_goals 
        WHERE budget_id = ? AND status != 'archived'
        ORDER BY priority DESC, goal_code
    ");
    $goal_stmt->execute([$budget_id]);
    
    $pdf->SetFont('helvetica', '', 8);
    while ($goal = $goal_stmt->fetch()) {
        $pdf->Cell(30, 5, $goal['goal_code'], 1, 0);
        $pdf->Cell(50, 5, substr($goal['goal_name'], 0, 20), 1, 0);
        $pdf->Cell(25, 5, $goal['goal_type'], 1, 0);
        $pdf->Cell(25, 5, number_format($goal['target_value'], 0), 1, 0, 'R');
        $pdf->Cell(25, 5, number_format($goal['actual_value'], 0), 1, 0, 'R');
        $pdf->Cell(25, 5, number_format($goal['achievement_percentage'], 0) . '%', 1, 0, 'R');
        $pdf->Cell(25, 5, getBudgetStatusLabel($goal['status']), 1, 1, 'C');
    }
    
    $pdf->Output('budget_' . $budget['budget_code'] . '.pdf', 'I');
    exit;
}

// =====================================================
// POST HANDLING
// =====================================================

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $error_message = "CSRF token validation failed. Please try again.";
    } else {
        // ============ CREATE/UPDATE BUDGET ============
        if (isset($_POST['save_budget'])) {
            $budget_id = (int)($_POST['budget_id'] ?? 0);
            $fiscal_year = (int)($_POST['fiscal_year'] ?? date('Y'));
            $fiscal_period = sanitizeInput($_POST['fiscal_period'] ?? 'Q1');
            $budget_type = sanitizeInput($_POST['budget_type'] ?? 'annual');
            $description = sanitizeInput($_POST['description'] ?? '');
            $status = sanitizeInput($_POST['status'] ?? 'draft');
            $notes = sanitizeInput($_POST['notes'] ?? '');
            
            try {
                if ($budget_id > 0) {
                    // Update existing budget
                    $stmt = $db->prepare("
                        UPDATE budgets SET
                            fiscal_year = ?,
                            fiscal_period = ?,
                            budget_type = ?,
                            description = ?,
                            status = ?,
                            notes = ?,
                            updated_at = NOW()
                        WHERE id = ?
                    ");
                    $stmt->execute([$fiscal_year, $fiscal_period, $budget_type, $description, $status, $notes, $budget_id]);
                    
                    logBudgetHistory($db, $budget_id, 'update', 'budget', null, null, "Budget updated: $description");
                    $success_message = "Budget updated successfully!";
                    
                } else {
                    // Create new budget with chart of accounts
                    $result = createBudgetWithChartAccounts($db, [
                        'fiscal_year' => $fiscal_year,
                        'fiscal_period' => $fiscal_period,
                        'budget_type' => $budget_type,
                        'description' => $description,
                        'status' => $status,
                        'notes' => $notes,
                        'user_id' => $user_id,
                        'username' => $username
                    ]);
                    
                    if ($result['success']) {
                        $success_message = "Budget created successfully! Budget Code: {$result['budget_code']}<br>";
                        $success_message .= "Categories added: {$result['categories_added']} from chart of accounts.";
                        $budget_id = $result['budget_id'];
                    }
                }
                
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            } catch (PDOException $e) {
                $error_message = "Database error: " . $e->getMessage();
            } catch (Exception $e) {
                $error_message = "Error: " . $e->getMessage();
            }
        }
        
        // ============ SAVE CATEGORY ============
        if (isset($_POST['save_category'])) {
            $category_id = (int)($_POST['category_id'] ?? 0);
            $budget_id = (int)($_POST['budget_id'] ?? 0);
            $category_code = sanitizeInput($_POST['category_code'] ?? '');
            $category_name = sanitizeInput($_POST['category_name'] ?? '');
            $category_type = sanitizeInput($_POST['category_type'] ?? 'expense');
            $budget_amount = (float)($_POST['budget_amount'] ?? 0);
            $description = sanitizeInput($_POST['category_description'] ?? '');
            $parent_category_id = !empty($_POST['parent_category_id']) ? (int)$_POST['parent_category_id'] : null;
            $is_group = isset($_POST['is_group']) ? 1 : 0;
            
            if (empty($category_code) || empty($category_name)) {
                $error_message = "Category code and name are required.";
            } else {
                try {
                    if ($category_id > 0) {
                        $stmt = $db->prepare("
                            UPDATE budget_categories SET
                                category_code = ?,
                                category_name = ?,
                                category_type = ?,
                                budget_amount = ?,
                                description = ?,
                                parent_category_id = ?,
                                is_group = ?,
                                updated_at = NOW()
                            WHERE id = ? AND budget_id = ?
                        ");
                        $stmt->execute([
                            $category_code, $category_name, $category_type,
                            $budget_amount, $description, $parent_category_id,
                            $is_group, $category_id, $budget_id
                        ]);
                        $success_message = "Category updated successfully!";
                    } else {
                        $stmt = $db->prepare("
                            INSERT INTO budget_categories (
                                budget_id, category_code, category_name, category_type,
                                budget_amount, description, parent_category_id, is_group,
                                status, created_at
                            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'active', NOW())
                        ");
                        $stmt->execute([
                            $budget_id, $category_code, $category_name, $category_type,
                            $budget_amount, $description, $parent_category_id, $is_group
                        ]);
                        $success_message = "Category created successfully!";
                    }
                    
                    updateBudgetTotals($db, $budget_id);
                    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                } catch (PDOException $e) {
                    $error_message = "Database error: " . $e->getMessage();
                }
            }
        }
        
        // ============ SYNC CATEGORIES FROM CHART ============
        if (isset($_POST['sync_categories'])) {
            $budget_id = (int)($_POST['sync_budget_id'] ?? 0);
            
            try {
                $added = syncBudgetCategoriesFromChart($db, $budget_id);
                if ($added > 0) {
                    $success_message = "Synced $added new categories from chart of accounts.";
                } else {
                    $success_message = "No new categories to sync. All chart accounts already exist.";
                }
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            } catch (Exception $e) {
                $error_message = "Error syncing categories: " . $e->getMessage();
            }
        }
        
        // ============ SAVE GOAL ============
        if (isset($_POST['save_goal'])) {
            $goal_id = (int)($_POST['goal_id'] ?? 0);
            $budget_id = (int)($_POST['goal_budget_id'] ?? 0);
            $goal_code = sanitizeInput($_POST['goal_code'] ?? '');
            $goal_name = sanitizeInput($_POST['goal_name'] ?? '');
            $goal_type = sanitizeInput($_POST['goal_type'] ?? 'financial');
            $category_id = !empty($_POST['goal_category_id']) ? (int)$_POST['goal_category_id'] : null;
            $target_value = (float)($_POST['target_value'] ?? 0);
            $target_unit = sanitizeInput($_POST['target_unit'] ?? 'Tsh');
            $start_date = sanitizeInput($_POST['start_date'] ?? '');
            $end_date = sanitizeInput($_POST['end_date'] ?? '');
            $priority = sanitizeInput($_POST['priority'] ?? 'medium');
            $status = sanitizeInput($_POST['goal_status'] ?? 'draft');
            $progress_notes = sanitizeInput($_POST['progress_notes'] ?? '');
            
            if (empty($goal_code) || empty($goal_name)) {
                $error_message = "Goal code and name are required.";
            } else {
                try {
                    if ($goal_id > 0) {
                        $stmt = $db->prepare("
                            UPDATE budget_goals SET
                                goal_code = ?,
                                goal_name = ?,
                                goal_type = ?,
                                category_id = ?,
                                target_value = ?,
                                target_unit = ?,
                                start_date = ?,
                                end_date = ?,
                                priority = ?,
                                status = ?,
                                progress_notes = ?,
                                updated_at = NOW()
                            WHERE id = ? AND budget_id = ?
                        ");
                        $stmt->execute([
                            $goal_code, $goal_name, $goal_type,
                            $category_id, $target_value, $target_unit,
                            $start_date, $end_date, $priority,
                            $status, $progress_notes,
                            $goal_id, $budget_id
                        ]);
                        $success_message = "Goal updated successfully!";
                    } else {
                        $stmt = $db->prepare("
                            INSERT INTO budget_goals (
                                budget_id, goal_code, goal_name, goal_type,
                                category_id, target_value, target_unit,
                                start_date, end_date, priority, status,
                                progress_notes, created_by, created_by_username, created_at
                            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                        ");
                        $stmt->execute([
                            $budget_id, $goal_code, $goal_name, $goal_type,
                            $category_id, $target_value, $target_unit,
                            $start_date, $end_date, $priority, $status,
                            $progress_notes, $user_id, $username
                        ]);
                        $success_message = "Goal created successfully!";
                    }
                    
                    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                } catch (PDOException $e) {
                    $error_message = "Database error: " . $e->getMessage();
                }
            }
        }
        
        // ============ UPDATE BUDGET STATUS ============
        if (isset($_POST['update_status'])) {
            $budget_id = (int)($_POST['budget_id'] ?? 0);
            $new_status = sanitizeInput($_POST['new_status'] ?? '');
            $rejection_reason = sanitizeInput($_POST['rejection_reason'] ?? '');
            
            if (empty($new_status)) {
                $error_message = "Invalid status.";
            } else {
                try {
                    $can_approve = in_array($user_role, ['system_admin', 'ceo', 'managing_director']);
                    $can_review = in_array($user_role, ['system_admin', 'ceo', 'managing_director', 'finance_manager']);
                    
                    if ($new_status == 'approved' && !$can_approve) {
                        $error_message = "You don't have permission to approve budgets.";
                    } elseif ($new_status == 'under_review' && !$can_review) {
                        $error_message = "You don't have permission to review budgets.";
                    } else {
                        $update_fields = "status = ?, updated_at = NOW()";
                        $params = [$new_status];
                        
                        if ($new_status == 'under_review') {
                            $update_fields .= ", reviewed_by = ?, reviewed_by_username = ?, reviewed_at = NOW()";
                            $params[] = $user_id;
                            $params[] = $username;
                        } elseif ($new_status == 'approved') {
                            $update_fields .= ", approved_by = ?, approved_by_username = ?, approved_at = NOW()";
                            $params[] = $user_id;
                            $params[] = $username;
                        } elseif ($new_status == 'rejected') {
                            $update_fields .= ", rejection_reason = ?";
                            $params[] = $rejection_reason;
                        }
                        
                        $params[] = $budget_id;
                        
                        $stmt = $db->prepare("UPDATE budgets SET $update_fields WHERE id = ?");
                        $stmt->execute($params);
                        
                        logBudgetHistory($db, $budget_id, 'status_change', 'status', null, $new_status, 
                            "Status changed to: " . getBudgetStatusLabel($new_status) . ($rejection_reason ? " - Reason: $rejection_reason" : ""));
                        
                        $success_message = "Budget status updated to: " . getBudgetStatusLabel($new_status);
                    }
                    
                    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                } catch (PDOException $e) {
                    $error_message = "Database error: " . $e->getMessage();
                }
            }
        }
        
        // ============ ADD MONITORING RECORD ============
        if (isset($_POST['add_monitoring'])) {
            $budget_id = (int)($_POST['monitoring_budget_id'] ?? 0);
            $category_id = (int)($_POST['monitoring_category_id'] ?? 0);
            $monitoring_date = sanitizeInput($_POST['monitoring_date'] ?? date('Y-m-d'));
            $actual_amount = (float)($_POST['monitoring_actual_amount'] ?? 0);
            $source_type = sanitizeInput($_POST['monitoring_source_type'] ?? 'manual');
            $source_reference = sanitizeInput($_POST['monitoring_source_reference'] ?? '');
            $notes = sanitizeInput($_POST['monitoring_notes'] ?? '');
            
            if ($category_id <= 0 || $actual_amount <= 0) {
                $error_message = "Please select a category and enter a valid amount.";
            } else {
                try {
                    $cat_stmt = $db->prepare("SELECT actual_amount, budget_amount FROM budget_categories WHERE id = ?");
                    $cat_stmt->execute([$category_id]);
                    $category = $cat_stmt->fetch();
                    
                    if (!$category) {
                        $error_message = "Category not found.";
                    } else {
                        $new_actual = $category['actual_amount'] + $actual_amount;
                        
                        $stmt = $db->prepare("
                            INSERT INTO budget_monitoring (
                                budget_id, category_id, monitoring_date,
                                actual_amount, cumulative_actual, budget_amount,
                                variance, variance_percentage,
                                source_type, source_reference, notes,
                                recorded_by, recorded_by_username, created_at
                            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                        ");
                        
                        $variance_data = calculateBudgetVariance($category['budget_amount'], $new_actual);
                        
                        $stmt->execute([
                            $budget_id, $category_id, $monitoring_date,
                            $actual_amount, $new_actual, $category['budget_amount'],
                            $variance_data['variance'], $variance_data['variance_percentage'],
                            $source_type, $source_reference, $notes,
                            $user_id, $username
                        ]);
                        
                        $update_stmt = $db->prepare("
                            UPDATE budget_categories SET
                                actual_amount = ?,
                                variance = ?,
                                variance_percentage = ?,
                                progress_percentage = CASE 
                                    WHEN budget_amount > 0 THEN (actual_amount / budget_amount) * 100 
                                    ELSE 0 
                                END,
                                updated_at = NOW()
                            WHERE id = ?
                        ");
                        $update_stmt->execute([
                            $new_actual,
                            $variance_data['variance'],
                            $variance_data['variance_percentage'],
                            $category_id
                        ]);
                        
                        updateBudgetTotals($db, $budget_id);
                        $success_message = "Monitoring record added successfully!";
                    }
                    
                    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                } catch (PDOException $e) {
                    $error_message = "Database error: " . $e->getMessage();
                }
            }
        }
        
        // ============ UPDATE GOAL PROGRESS ============
        if (isset($_POST['update_goal_progress'])) {
            $goal_id = (int)($_POST['goal_progress_id'] ?? 0);
            $actual_value = (float)($_POST['goal_actual_value'] ?? 0);
            $progress_notes = sanitizeInput($_POST['goal_progress_notes'] ?? '');
            
            if ($goal_id <= 0) {
                $error_message = "Invalid goal selected.";
            } else {
                try {
                    $goal_stmt = $db->prepare("SELECT target_value, achievement_percentage, budget_id FROM budget_goals WHERE id = ?");
                    $goal_stmt->execute([$goal_id]);
                    $goal = $goal_stmt->fetch();
                    
                    if (!$goal) {
                        $error_message = "Goal not found.";
                    } else {
                        $achievement = 0;
                        if ($goal['target_value'] > 0) {
                            $achievement = ($actual_value / $goal['target_value']) * 100;
                        }
                        
                        $status = 'active';
                        if ($achievement >= 100) {
                            $status = 'achieved';
                        } elseif ($achievement < 0) {
                            $status = 'failed';
                        }
                        
                        $stmt = $db->prepare("
                            UPDATE budget_goals SET
                                actual_value = ?,
                                achievement_percentage = ?,
                                status = ?,
                                progress_notes = CONCAT(IFNULL(progress_notes, ''), '\n', DATE_FORMAT(NOW(), '%Y-%m-%d'), ': ', ?),
                                updated_at = NOW()
                            WHERE id = ?
                        ");
                        $stmt->execute([
                            $actual_value,
                            $achievement,
                            $status,
                            $progress_notes,
                            $goal_id
                        ]);
                        
                        $success_message = "Goal progress updated! Achievement: " . number_format($achievement, 2) . "%";
                    }
                    
                    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                } catch (PDOException $e) {
                    $error_message = "Database error: " . $e->getMessage();
                }
            }
        }
    }
}

// =====================================================
// FETCH DATA FOR DISPLAY
// =====================================================

// Get all budgets
try {
    $budgets_stmt = $db->prepare("
        SELECT b.*, 
               u1.username as created_by_name,
               u2.username as reviewed_by_name,
               u3.username as approved_by_name,
               COUNT(DISTINCT bc.id) as category_count,
               COUNT(DISTINCT bg.id) as goal_count
        FROM budgets b
        LEFT JOIN users u1 ON b.created_by = u1.id
        LEFT JOIN users u2 ON b.reviewed_by = u2.id
        LEFT JOIN users u3 ON b.approved_by = u3.id
        LEFT JOIN budget_categories bc ON b.id = bc.budget_id
        LEFT JOIN budget_goals bg ON b.id = bg.budget_id
        GROUP BY b.id
        ORDER BY b.fiscal_year DESC, b.created_at DESC
    ");
    $budgets_stmt->execute();
    $all_budgets = $budgets_stmt->fetchAll();
} catch (PDOException $e) {
    $all_budgets = [];
    $error_message = "Error fetching budgets: " . $e->getMessage();
}

// Get budget details for viewing
$selected_budget_id = isset($_GET['view']) ? (int)$_GET['view'] : (isset($_GET['edit']) ? (int)$_GET['edit'] : 0);
$selected_budget = null;
$selected_categories = [];
$selected_goals = [];
$selected_monitoring = [];

if ($selected_budget_id > 0) {
    try {
        $stmt = $db->prepare("
            SELECT b.*, 
                   u1.username as created_by_name,
                   u2.username as reviewed_by_name,
                   u3.username as approved_by_name
            FROM budgets b
            LEFT JOIN users u1 ON b.created_by = u1.id
            LEFT JOIN users u2 ON b.reviewed_by = u2.id
            LEFT JOIN users u3 ON b.approved_by = u3.id
            WHERE b.id = ?
        ");
        $stmt->execute([$selected_budget_id]);
        $selected_budget = $stmt->fetch();
        
        if ($selected_budget) {
            // Get categories
            $cat_stmt = $db->prepare("
                SELECT * FROM budget_categories 
                WHERE budget_id = ? AND status = 'active'
                ORDER BY category_type, category_code
            ");
            $cat_stmt->execute([$selected_budget_id]);
            $selected_categories = $cat_stmt->fetchAll();
            
            // Get goals
            $goal_stmt = $db->prepare("
                SELECT bg.*, bc.category_name as category_name
                FROM budget_goals bg
                LEFT JOIN budget_categories bc ON bg.category_id = bc.id
                WHERE bg.budget_id = ? AND bg.status != 'archived'
                ORDER BY bg.priority DESC, bg.goal_code
            ");
            $goal_stmt->execute([$selected_budget_id]);
            $selected_goals = $goal_stmt->fetchAll();
            
            // Get monitoring data
            $mon_stmt = $db->prepare("
                SELECT m.*, 
                       c.category_code, c.category_name, c.category_type
                FROM budget_monitoring m
                LEFT JOIN budget_categories c ON m.category_id = c.id
                WHERE m.budget_id = ?
                ORDER BY m.monitoring_date DESC, m.created_at DESC
                LIMIT 100
            ");
            $mon_stmt->execute([$selected_budget_id]);
            $selected_monitoring = $mon_stmt->fetchAll();
        }
    } catch (PDOException $e) {
        $error_message = "Error fetching budget details: " . $e->getMessage();
    }
}

// For edit mode
$edit_budget = null;
if (isset($_GET['edit']) && $selected_budget_id > 0) {
    $edit_budget = $selected_budget;
}

$page_title = 'Budget Management';
include '../includes/header.php';
?>

<style>
    .form-control-sm { height: calc(1.5em + 0.5rem + 2px); padding: 0.25rem 0.5rem; font-size: 0.875rem; }
    .clickable-row { cursor: pointer; transition: background-color 0.2s; }
    .clickable-row:hover { background-color: #f8f9fa; }
    .badge { padding: 0.35em 0.65em; font-size: 0.75em; }
    
    .status-draft { background-color: #6c757d; color: white; }
    .status-pending { background-color: #ffc107; color: #212529; }
    .status-under_review { background-color: #0dcaf0; color: #212529; }
    .status-approved { background-color: #198754; color: white; }
    .status-rejected { background-color: #dc3545; color: white; }
    .status-active { background-color: #0d6efd; color: white; }
    .status-closed { background-color: #212529; color: white; }
    
    .budget-card {
        border-radius: 10px;
        transition: transform 0.3s ease, box-shadow 0.3s ease;
    }
    .budget-card:hover {
        transform: translateY(-3px);
        box-shadow: 0 8px 25px rgba(0,0,0,0.15);
    }
    
    .progress-budget {
        height: 8px;
        border-radius: 4px;
        background-color: #e9ecef;
        margin-top: 5px;
    }
    .progress-budget-bar {
        height: 100%;
        border-radius: 4px;
        transition: width 0.5s ease;
    }
    .progress-budget-bar.over-budget {
        background: linear-gradient(90deg, #dc3545, #ff6b6b);
    }
    .progress-budget-bar.under-budget {
        background: linear-gradient(90deg, #198754, #51cf66);
    }
    
    .goal-card {
        border-left: 4px solid #0d6efd;
        margin-bottom: 10px;
    }
    .goal-card.achieved { border-left-color: #198754; background-color: #d4edda; }
    .goal-card.failed { border-left-color: #dc3545; background-color: #f8d7da; }
    .goal-card.active { border-left-color: #0d6efd; }
    .goal-card.draft { border-left-color: #6c757d; background-color: #f8f9fa; }
    
    .stat-card {
        border-radius: 10px;
        padding: 15px;
        text-align: center;
        background: white;
        box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    }
    .stat-card .number {
        font-size: 1.8rem;
        font-weight: bold;
    }
    .stat-card .label {
        font-size: 0.85rem;
        color: #6c757d;
        margin-top: 5px;
    }
</style>

<div class="container-fluid">
    <?php if (!empty($success_message)): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="bi bi-check-circle-fill me-2"></i>
            <?php echo $success_message; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if (!empty($error_message)): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="bi bi-exclamation-triangle-fill me-2"></i>
            <?php echo $error_message; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- ============================================ -->
    <!-- BUDGET LIST & STATS -->
    <!-- ============================================ -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h4><i class="bi bi-graph-up-arrow me-2"></i>Budget Management</h4>
                <div>
                    <?php if (in_array($user_role, ['finance_officer', 'hr_officer', 'system_admin'])): ?>
                        <a href="?new=1" class="btn btn-success btn-sm">
                            <i class="bi bi-plus-circle me-1"></i>New Budget
                        </a>
                    <?php endif; ?>
                    <?php if (isset($_GET['view']) && $selected_budget): ?>
                        <a href="?export_excel=<?php echo $selected_budget_id; ?>" class="btn btn-success btn-sm">
                            <i class="bi bi-file-excel me-1"></i>Excel
                        </a>
                        <a href="?export_pdf=<?php echo $selected_budget_id; ?>" class="btn btn-danger btn-sm">
                            <i class="bi bi-file-pdf me-1"></i>PDF
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Stats Cards -->
    <div class="row g-3 mb-4">
        <div class="col-md-2 col-6">
            <div class="stat-card">
                <div class="number text-primary"><?php echo count($all_budgets); ?></div>
                <div class="label">Total Budgets</div>
            </div>
        </div>
        <div class="col-md-2 col-6">
            <div class="stat-card">
                <div class="number text-warning">
                    <?php 
                        $pending = array_filter($all_budgets, function($b) { return $b['status'] == 'pending' || $b['status'] == 'under_review'; });
                        echo count($pending);
                    ?>
                </div>
                <div class="label">Pending Review</div>
            </div>
        </div>
        <div class="col-md-2 col-6">
            <div class="stat-card">
                <div class="number text-success">
                    <?php 
                        $approved = array_filter($all_budgets, function($b) { return $b['status'] == 'approved' || $b['status'] == 'active'; });
                        echo count($approved);
                    ?>
                </div>
                <div class="label">Approved</div>
            </div>
        </div>
        <div class="col-md-2 col-6">
            <div class="stat-card">
                <div class="number text-danger">
                    <?php 
                        $rejected = array_filter($all_budgets, function($b) { return $b['status'] == 'rejected'; });
                        echo count($rejected);
                    ?>
                </div>
                <div class="label">Rejected</div>
            </div>
        </div>
        <div class="col-md-2 col-6">
            <div class="stat-card">
                <div class="number text-info">
                    <?php 
                        $active = array_filter($all_budgets, function($b) { return $b['status'] == 'active'; });
                        echo count($active);
                    ?>
                </div>
                <div class="label">Active</div>
            </div>
        </div>
        <div class="col-md-2 col-6">
            <div class="stat-card">
                <div class="number text-success">
                    <?php 
                        $closed = array_filter($all_budgets, function($b) { return $b['status'] == 'closed'; });
                        echo count($closed);
                    ?>
                </div>
                <div class="label">Closed</div>
            </div>
        </div>
    </div>

    <!-- Budget List -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="card">
                <div class="card-header bg-light d-flex justify-content-between align-items-center">
                    <h6 class="mb-0"><i class="bi bi-list-ul me-2"></i>All Budgets</h6>
                    <div class="d-flex gap-2">
                        <input type="text" class="form-control form-control-sm" id="budgetSearch" placeholder="Search budgets..." style="width: 200px;">
                        <select class="form-select form-select-sm" id="budgetStatusFilter" style="width: 150px;">
                            <option value="">All Status</option>
                            <option value="draft">Draft</option>
                            <option value="pending">Pending Review</option>
                            <option value="under_review">Under Review</option>
                            <option value="approved">Approved</option>
                            <option value="rejected">Rejected</option>
                            <option value="active">Active</option>
                            <option value="closed">Closed</option>
                        </select>
                    </div>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm table-hover mb-0" id="budgetsTable">
                            <thead class="table-light">
                                <tr>
                                    <th>Budget Code</th>
                                    <th>Fiscal Year</th>
                                    <th>Period</th>
                                    <th>Description</th>
                                    <th>Total Budget</th>
                                    <th>Total Actual</th>
                                    <th>Variance</th>
                                    <th>Status</th>
                                    <th>Categories</th>
                                    <th>Goals</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($all_budgets)): ?>
                                    <tr><td colspan="11" class="text-center py-3 text-muted">No budgets found. Create your first budget!</td></tr>
                                <?php else: foreach ($all_budgets as $budget): ?>
                                    <tr>
                                        <td><code class="fw-bold"><?php echo htmlspecialchars($budget['budget_code']); ?></code></td>
                                        <td><?php echo htmlspecialchars($budget['fiscal_year']); ?></td>
                                        <td><?php echo htmlspecialchars($budget['fiscal_period']); ?></td>
                                        <td><?php echo htmlspecialchars($budget['description'] ?? '-'); ?></td>
                                        <td class="fw-bold text-primary"><?php echo number_format($budget['total_budget'] ?? 0, 2); ?></td>
                                        <td class="fw-bold <?php echo ($budget['total_actual'] ?? 0) > ($budget['total_budget'] ?? 0) ? 'text-danger' : 'text-success'; ?>">
                                            <?php echo number_format($budget['total_actual'] ?? 0, 2); ?>
                                        </td>
                                        <td class="fw-bold <?php echo ($budget['total_variance'] ?? 0) < 0 ? 'text-success' : 'text-danger'; ?>">
                                            <?php echo number_format($budget['total_variance'] ?? 0, 2); ?>
                                            <?php if (($budget['total_budget'] ?? 0) > 0): ?>
                                                <br><small>(<?php echo number_format($budget['variance_percentage'] ?? 0, 2); ?>%)</small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="badge <?php echo getBudgetStatusBadge($budget['status']); ?>">
                                                <?php echo getBudgetStatusLabel($budget['status']); ?>
                                            </span>
                                        </td>
                                        <td><?php echo (int)($budget['category_count'] ?? 0); ?></td>
                                        <td><?php echo (int)($budget['goal_count'] ?? 0); ?></td>
                                        <td>
                                            <div class="btn-group btn-group-sm">
                                                <a href="?view=<?php echo (int)$budget['id']; ?>" class="btn btn-outline-primary">
                                                    <i class="bi bi-eye"></i>
                                                </a>
                                                <?php if (in_array($user_role, ['finance_officer', 'hr_officer', 'system_admin']) && $budget['status'] != 'closed' && $budget['status'] != 'approved'): ?>
                                                    <a href="?edit=<?php echo (int)$budget['id']; ?>" class="btn btn-outline-warning">
                                                        <i class="bi bi-pencil"></i>
                                                    </a>
                                                <?php endif; ?>
                                                <?php if (in_array($user_role, ['finance_officer', 'hr_officer', 'system_admin']) && in_array($budget['status'], ['draft', 'pending', 'rejected'])): ?>
                                                    <button class="btn btn-outline-danger delete-budget" data-id="<?php echo (int)$budget['id']; ?>" 
                                                            data-code="<?php echo htmlspecialchars($budget['budget_code']); ?>">
                                                        <i class="bi bi-trash"></i>
                                                    </button>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php if ($selected_budget): ?>
        <!-- ============================================ -->
        <!-- BUDGET DETAILS VIEW -->
        <!-- ============================================ -->
        <div class="row mt-4">
            <div class="col-12">
                <div class="card">
                    <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">
                            <i class="bi bi-file-earmark-text me-2"></i>
                            <?php echo htmlspecialchars($selected_budget['budget_code']); ?> - 
                            <?php echo htmlspecialchars($selected_budget['fiscal_year']); ?> (<?php echo htmlspecialchars($selected_budget['fiscal_period']); ?>)
                        </h5>
                        <div>
                            <span class="badge <?php echo getBudgetStatusBadge($selected_budget['status']); ?> me-2" style="font-size: 0.9rem;">
                                <?php echo getBudgetStatusLabel($selected_budget['status']); ?>
                            </span>
                            <?php if ($selected_budget['status'] == 'pending' || $selected_budget['status'] == 'under_review'): ?>
                                <?php if (in_array($user_role, ['system_admin', 'ceo', 'managing_director'])): ?>
                                    <button class="btn btn-sm btn-success" onclick="updateBudgetStatus(<?php echo $selected_budget['id']; ?>, 'approved')">
                                        <i class="bi bi-check-circle me-1"></i>Approve
                                    </button>
                                    <button class="btn btn-sm btn-danger" onclick="updateBudgetStatus(<?php echo $selected_budget['id']; ?>, 'rejected')">
                                        <i class="bi bi-x-circle me-1"></i>Reject
                                    </button>
                                <?php endif; ?>
                            <?php endif; ?>
                            <?php if ($selected_budget['status'] == 'approved' && in_array($user_role, ['system_admin', 'ceo', 'managing_director', 'finance_manager'])): ?>
                                <button class="btn btn-sm btn-primary" onclick="updateBudgetStatus(<?php echo $selected_budget['id']; ?>, 'active')">
                                    <i class="bi bi-play-circle me-1"></i>Activate
                                </button>
                            <?php endif; ?>
                            <?php if (in_array($user_role, ['finance_officer', 'hr_officer', 'system_admin']) && in_array($selected_budget['status'], ['draft', 'pending', 'rejected'])): ?>
                                <a href="budget_delete.php?id=<?php echo $selected_budget['id']; ?>" class="btn btn-sm btn-danger" onclick="return confirm('Are you sure you want to delete this budget? This will delete all associated data.')">
                                    <i class="bi bi-trash me-1"></i>Delete
                                </a>
                            <?php endif; ?>
                            <?php if (in_array($user_role, ['finance_officer', 'system_admin'])): ?>
                                <form method="POST" class="d-inline">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                    <input type="hidden" name="sync_budget_id" value="<?php echo $selected_budget['id']; ?>">
                                    <button type="submit" name="sync_categories" class="btn btn-sm btn-info">
                                        <i class="bi bi-arrow-repeat me-1"></i>Sync Categories
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="card-body">
                        <!-- Budget Summary -->
                        <div class="row g-3 mb-4">
                            <div class="col-md-3">
                                <div class="stat-card">
                                    <div class="number text-primary"><?php echo number_format($selected_budget['total_budget'] ?? 0, 2); ?></div>
                                    <div class="label">Total Budget</div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="stat-card">
                                    <div class="number <?php echo ($selected_budget['total_actual'] ?? 0) > ($selected_budget['total_budget'] ?? 0) ? 'text-danger' : 'text-success'; ?>">
                                        <?php echo number_format($selected_budget['total_actual'] ?? 0, 2); ?>
                                    </div>
                                    <div class="label">Total Actual</div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="stat-card">
                                    <div class="number <?php echo ($selected_budget['total_variance'] ?? 0) < 0 ? 'text-success' : 'text-danger'; ?>">
                                        <?php echo number_format($selected_budget['total_variance'] ?? 0, 2); ?>
                                    </div>
                                    <div class="label">Variance (<?php echo number_format($selected_budget['variance_percentage'] ?? 0, 2); ?>%)</div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="stat-card">
                                    <div class="number text-info"><?php echo count($selected_goals); ?></div>
                                    <div class="label">Goals (<?php 
                                        $achieved = array_filter($selected_goals, function($g) { return $g['status'] == 'achieved'; });
                                        echo count($achieved);
                                    ?> achieved)</div>
                                </div>
                            </div>
                        </div>

                        <!-- Budget Progress -->
                        <div class="row mb-4">
                            <div class="col-12">
                                <h6>Overall Budget Progress</h6>
                                <?php 
                                    $progress = 0;
                                    if (($selected_budget['total_budget'] ?? 0) > 0) {
                                        $progress = min(100, (($selected_budget['total_actual'] ?? 0) / ($selected_budget['total_budget'] ?? 0)) * 100);
                                    }
                                    $bar_class = $progress > 100 ? 'over-budget' : ($progress > 80 ? 'under-budget' : '');
                                ?>
                                <div class="progress-budget">
                                    <div class="progress-budget-bar <?php echo $bar_class; ?>" style="width: <?php echo min(100, $progress); ?>%;">
                                        <?php if ($progress > 5): ?>
                                            <span class="small" style="position: relative; top: -18px; left: 5px; color: <?php echo $progress > 100 ? '#dc3545' : '#198754'; ?>;">
                                                <?php echo number_format($progress, 1); ?>%
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <small class="text-muted"><?php echo number_format($selected_budget['total_actual'] ?? 0, 2); ?> of <?php echo number_format($selected_budget['total_budget'] ?? 0, 2); ?></small>
                            </div>
                        </div>

                        <!-- Tabs -->
                        <ul class="nav nav-tabs" id="budgetTabs" role="tablist">
                            <li class="nav-item" role="presentation">
                                <button class="nav-link active" id="categories-tab" data-bs-toggle="tab" data-bs-target="#categories" type="button" role="tab">
                                    <i class="bi bi-tags me-1"></i>Categories (<?php echo count($selected_categories); ?>)
                                </button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" id="goals-tab" data-bs-toggle="tab" data-bs-target="#goals" type="button" role="tab">
                                    <i class="bi bi-bullseye me-1"></i>Goals (<?php echo count($selected_goals); ?>)
                                </button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" id="monitoring-tab" data-bs-toggle="tab" data-bs-target="#monitoring" type="button" role="tab">
                                    <i class="bi bi-graph-up me-1"></i>Monitoring (<?php echo count($selected_monitoring); ?>)
                                </button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" id="history-tab" data-bs-toggle="tab" data-bs-target="#history" type="button" role="tab">
                                    <i class="bi bi-clock-history me-1"></i>History
                                </button>
                            </li>
                        </ul>

                        <div class="tab-content p-3 border border-top-0 rounded-bottom" id="budgetTabContent">
                            <!-- ========== CATEGORIES TAB ========== -->
                            <div class="tab-pane fade show active" id="categories" role="tabpanel">
                                <?php if (in_array($user_role, ['finance_officer', 'hr_officer', 'system_admin']) && $selected_budget['status'] != 'closed' && $selected_budget['status'] != 'approved'): ?>
                                    <div class="mb-3">
                                        <button class="btn btn-sm btn-primary" data-bs-toggle="collapse" data-bs-target="#addCategoryForm">
                                            <i class="bi bi-plus-circle me-1"></i>Add Category
                                        </button>
                                        <small class="text-muted ms-2">Categories are automatically synced from Chart of Accounts</small>
                                    </div>
                                    <div class="collapse mb-3" id="addCategoryForm">
                                        <div class="card card-body p-2">
                                            <form method="POST">
                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                                <input type="hidden" name="budget_id" value="<?php echo $selected_budget['id']; ?>">
                                                <input type="hidden" name="save_category" value="1">
                                                
                                                <div class="row g-2">
                                                    <div class="col-md-2">
                                                        <label class="form-label">Category Code</label>
                                                        <input type="text" class="form-control form-control-sm" name="category_code" required placeholder="e.g., 411">
                                                    </div>
                                                    <div class="col-md-3">
                                                        <label class="form-label">Category Name</label>
                                                        <input type="text" class="form-control form-control-sm" name="category_name" required placeholder="e.g., Brokerage Income">
                                                    </div>
                                                    <div class="col-md-2">
                                                        <label class="form-label">Type</label>
                                                        <select class="form-select form-select-sm" name="category_type">
                                                            <option value="income">Income</option>
                                                            <option value="expense" selected>Expense</option>
                                                            <option value="goal">Goal</option>
                                                        </select>
                                                    </div>
                                                    <div class="col-md-2">
                                                        <label class="form-label">Budget Amount</label>
                                                        <input type="number" class="form-control form-control-sm" name="budget_amount" step="0.01" min="0" placeholder="0.00">
                                                    </div>
                                                    <div class="col-md-3">
                                                        <label class="form-label">Description</label>
                                                        <input type="text" class="form-control form-control-sm" name="category_description">
                                                    </div>
                                                    <div class="col-12">
                                                        <div class="form-check">
                                                            <input class="form-check-input" type="checkbox" name="is_group" value="1">
                                                            <label class="form-check-label">This is a group category</label>
                                                        </div>
                                                    </div>
                                                    <div class="col-12">
                                                        <button type="submit" class="btn btn-sm btn-success">Save Category</button>
                                                        <button type="button" class="btn btn-sm btn-secondary" data-bs-toggle="collapse" data-bs-target="#addCategoryForm">Cancel</button>
                                                    </div>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                <?php endif; ?>

                                <div class="table-responsive">
                                    <table class="table table-sm table-striped">
                                        <thead>
                                            <tr>
                                                <th>Code</th>
                                                <th>Category Name</th>
                                                <th>Type</th>
                                                <th>Budget</th>
                                                <th>Actual</th>
                                                <th>Variance</th>
                                                <th>Progress</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (empty($selected_categories)): ?>
                                                <tr><td colspan="8" class="text-center text-muted">No categories defined.</td></tr>
                                            <?php else: foreach ($selected_categories as $cat): ?>
                                                <tr>
                                                    <td><code><?php echo htmlspecialchars($cat['category_code']); ?></code></td>
                                                    <td><?php echo htmlspecialchars($cat['category_name']); ?></td>
                                                    <td>
                                                        <span class="badge <?php echo $cat['category_type'] == 'income' ? 'bg-success' : ($cat['category_type'] == 'goal' ? 'bg-primary' : 'bg-danger'); ?>">
                                                            <?php echo ucfirst($cat['category_type']); ?>
                                                        </span>
                                                    </td>
                                                    <td class="text-primary fw-bold"><?php echo number_format($cat['budget_amount'] ?? 0, 2); ?></td>
                                                    <td class="<?php echo ($cat['actual_amount'] ?? 0) > ($cat['budget_amount'] ?? 0) ? 'text-danger' : 'text-success'; ?>">
                                                        <?php echo number_format($cat['actual_amount'] ?? 0, 2); ?>
                                                    </td>
                                                    <td class="<?php echo ($cat['variance'] ?? 0) < 0 ? 'text-success' : 'text-danger'; ?>">
                                                        <?php echo number_format($cat['variance'] ?? 0, 2); ?>
                                                    </td>
                                                    <td>
                                                        <?php 
                                                            $pct = min(100, $cat['progress_percentage'] ?? 0);
                                                            $bar_color = $pct > 100 ? 'danger' : ($pct > 80 ? 'success' : 'warning');
                                                        ?>
                                                        <div class="d-flex align-items-center">
                                                            <div class="flex-grow-1 me-2">
                                                                <div class="progress" style="height: 6px;">
                                                                    <div class="progress-bar bg-<?php echo $bar_color; ?>" style="width: <?php echo min(100, $pct); ?>%;"></div>
                                                                </div>
                                                            </div>
                                                            <span class="small"><?php echo number_format($pct, 0); ?>%</span>
                                                        </div>
                                                    </td>
                                                    <td>
                                                        <?php if (in_array($user_role, ['finance_officer', 'hr_officer', 'system_admin']) && $selected_budget['status'] != 'closed'): ?>
                                                            <div class="btn-group btn-group-sm">
                                                                <button class="btn btn-outline-primary edit-category" data-id="<?php echo (int)$cat['id']; ?>">
                                                                    <i class="bi bi-pencil"></i>
                                                                </button>
                                                                <button class="btn btn-outline-success add-monitoring" data-category-id="<?php echo (int)$cat['id']; ?>">
                                                                    <i class="bi bi-plus-circle"></i>
                                                                </button>
                                                            </div>
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; endif; ?>
                                        </tbody>
                                        <tfoot>
                                            <tr class="table-light fw-bold">
                                                <td colspan="3" class="text-end">TOTALS:</td>
                                                <td class="text-primary"><?php echo number_format(array_sum(array_column($selected_categories, 'budget_amount')), 2); ?></td>
                                                <td><?php echo number_format(array_sum(array_column($selected_categories, 'actual_amount')), 2); ?></td>
                                                <td><?php echo number_format(array_sum(array_column($selected_categories, 'variance')), 2); ?></td>
                                                <td colspan="2"></td>
                                            </tr>
                                        </tfoot>
                                    </table>
                                </div>
                            </div>

                            <!-- ========== GOALS TAB ========== -->
                            <div class="tab-pane fade" id="goals" role="tabpanel">
                                <?php if (in_array($user_role, ['finance_officer', 'hr_officer', 'system_admin']) && $selected_budget['status'] != 'closed' && $selected_budget['status'] != 'approved'): ?>
                                    <div class="mb-3">
                                        <button class="btn btn-sm btn-primary" data-bs-toggle="collapse" data-bs-target="#addGoalForm">
                                            <i class="bi bi-plus-circle me-1"></i>Add Goal
                                        </button>
                                    </div>
                                    <div class="collapse mb-3" id="addGoalForm">
                                        <div class="card card-body p-2">
                                            <form method="POST">
                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                                <input type="hidden" name="goal_budget_id" value="<?php echo $selected_budget['id']; ?>">
                                                <input type="hidden" name="save_goal" value="1">
                                                
                                                <div class="row g-2">
                                                    <div class="col-md-2">
                                                        <label class="form-label">Goal Code</label>
                                                        <input type="text" class="form-control form-control-sm" name="goal_code" required>
                                                    </div>
                                                    <div class="col-md-3">
                                                        <label class="form-label">Goal Name</label>
                                                        <input type="text" class="form-control form-control-sm" name="goal_name" required>
                                                    </div>
                                                    <div class="col-md-2">
                                                        <label class="form-label">Goal Type</label>
                                                        <select class="form-select form-select-sm" name="goal_type">
                                                            <option value="financial">Financial</option>
                                                            <option value="operational">Operational</option>
                                                            <option value="strategic">Strategic</option>
                                                            <option value="performance">Performance</option>
                                                        </select>
                                                    </div>
                                                    <div class="col-md-2">
                                                        <label class="form-label">Category</label>
                                                        <select class="form-select form-select-sm" name="goal_category_id">
                                                            <option value="">None</option>
                                                            <?php foreach ($selected_categories as $cat): ?>
                                                                <option value="<?php echo (int)$cat['id']; ?>">
                                                                    <?php echo htmlspecialchars($cat['category_name']); ?>
                                                                </option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </div>
                                                    <div class="col-md-3">
                                                        <label class="form-label">Target Value</label>
                                                        <div class="input-group input-group-sm">
                                                            <input type="number" class="form-control" name="target_value" step="0.01" min="0">
                                                            <select class="form-select" name="target_unit" style="max-width: 70px;">
                                                                <option value="Tsh">Tsh</option>
                                                                <option value="USD">USD</option>
                                                                <option value="%">%</option>
                                                                <option value="units">Units</option>
                                                            </select>
                                                        </div>
                                                    </div>
                                                    <div class="col-md-2">
                                                        <label class="form-label">Start Date</label>
                                                        <input type="date" class="form-control form-control-sm" name="start_date">
                                                    </div>
                                                    <div class="col-md-2">
                                                        <label class="form-label">End Date</label>
                                                        <input type="date" class="form-control form-control-sm" name="end_date">
                                                    </div>
                                                    <div class="col-md-2">
                                                        <label class="form-label">Priority</label>
                                                        <select class="form-select form-select-sm" name="priority">
                                                            <option value="low">Low</option>
                                                            <option value="medium" selected>Medium</option>
                                                            <option value="high">High</option>
                                                            <option value="critical">Critical</option>
                                                        </select>
                                                    </div>
                                                    <div class="col-md-3">
                                                        <label class="form-label">Status</label>
                                                        <select class="form-select form-select-sm" name="goal_status">
                                                            <option value="draft">Draft</option>
                                                            <option value="active">Active</option>
                                                        </select>
                                                    </div>
                                                    <div class="col-md-3">
                                                        <label class="form-label">Notes</label>
                                                        <input type="text" class="form-control form-control-sm" name="progress_notes">
                                                    </div>
                                                    <div class="col-12">
                                                        <button type="submit" class="btn btn-sm btn-success">Save Goal</button>
                                                        <button type="button" class="btn btn-sm btn-secondary" data-bs-toggle="collapse" data-bs-target="#addGoalForm">Cancel</button>
                                                    </div>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                <?php endif; ?>

                                <div class="row">
                                    <?php if (empty($selected_goals)): ?>
                                        <div class="col-12 text-center text-muted py-4">No goals defined for this budget.</div>
                                    <?php else: foreach ($selected_goals as $goal): ?>
                                        <div class="col-md-6">
                                            <div class="goal-card <?php echo $goal['status']; ?> card p-3">
                                                <div class="d-flex justify-content-between align-items-start">
                                                    <div>
                                                        <h6 class="mb-1">
                                                            <code><?php echo htmlspecialchars($goal['goal_code']); ?></code>
                                                            <?php echo htmlspecialchars($goal['goal_name']); ?>
                                                        </h6>
                                                        <small class="text-muted">
                                                            <?php echo ucfirst($goal['goal_type']); ?>
                                                            <?php if ($goal['category_name']): ?>
                                                                • <?php echo htmlspecialchars($goal['category_name']); ?>
                                                            <?php endif; ?>
                                                        </small>
                                                        <br>
                                                        <small>
                                                            Target: <?php echo number_format($goal['target_value'], 2); ?> <?php echo htmlspecialchars($goal['target_unit']); ?>
                                                            <?php if ($goal['start_date']): ?>
                                                                • From <?php echo date('M d, Y', strtotime($goal['start_date'])); ?>
                                                            <?php endif; ?>
                                                            <?php if ($goal['end_date']): ?>
                                                                to <?php echo date('M d, Y', strtotime($goal['end_date'])); ?>
                                                            <?php endif; ?>
                                                        </small>
                                                    </div>
                                                    <div class="text-end">
                                                        <span class="badge <?php echo getPriorityBadge($goal['priority']); ?>">
                                                            <?php echo ucfirst($goal['priority']); ?>
                                                        </span>
                                                        <span class="badge <?php echo $goal['status'] == 'achieved' ? 'bg-success' : ($goal['status'] == 'failed' ? 'bg-danger' : 'bg-secondary'); ?>">
                                                            <?php echo getBudgetStatusLabel($goal['status']); ?>
                                                        </span>
                                                    </div>
                                                </div>
                                                
                                                <div class="mt-2">
                                                    <div class="d-flex justify-content-between align-items-center">
                                                        <span class="small">Progress: <?php echo number_format($goal['achievement_percentage'], 1); ?>%</span>
                                                        <span class="small">
                                                            <?php echo number_format($goal['actual_value'], 2); ?> / <?php echo number_format($goal['target_value'], 2); ?>
                                                        </span>
                                                    </div>
                                                    <div class="progress" style="height: 6px;">
                                                        <div class="progress-bar <?php echo $goal['achievement_percentage'] >= 100 ? 'bg-success' : 'bg-primary'; ?>" 
                                                             style="width: <?php echo min(100, $goal['achievement_percentage']); ?>%;">
                                                        </div>
                                                    </div>
                                                </div>

                                                <?php if (in_array($user_role, ['finance_officer', 'hr_officer', 'system_admin']) && $selected_budget['status'] != 'closed'): ?>
                                                    <div class="mt-2">
                                                        <button class="btn btn-sm btn-outline-primary update-goal" data-goal-id="<?php echo (int)$goal['id']; ?>">
                                                            <i class="bi bi-arrow-up-circle me-1"></i>Update Progress
                                                        </button>
                                                        <?php if ($goal['progress_notes']): ?>
                                                            <small class="text-muted d-block mt-1"><?php echo nl2br(htmlspecialchars(substr($goal['progress_notes'], 0, 200))); ?></small>
                                                        <?php endif; ?>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    <?php endforeach; endif; ?>
                                </div>
                            </div>

                            <!-- ========== MONITORING TAB ========== -->
                            <div class="tab-pane fade" id="monitoring" role="tabpanel">
                                <div class="row">
                                    <div class="col-md-4">
                                        <div class="card">
                                            <div class="card-header">
                                                <h6 class="mb-0">Add Monitoring Record</h6>
                                            </div>
                                            <div class="card-body p-2">
                                                <form method="POST">
                                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                                    <input type="hidden" name="monitoring_budget_id" value="<?php echo $selected_budget['id']; ?>">
                                                    <input type="hidden" name="add_monitoring" value="1">
                                                    
                                                    <div class="mb-2">
                                                        <label class="form-label">Category</label>
                                                        <select class="form-select form-select-sm" name="monitoring_category_id" required>
                                                            <option value="">Select Category</option>
                                                            <?php foreach ($selected_categories as $cat): ?>
                                                                <option value="<?php echo (int)$cat['id']; ?>">
                                                                    <?php echo htmlspecialchars($cat['category_code'] . ' - ' . $cat['category_name']); ?>
                                                                </option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </div>
                                                    <div class="mb-2">
                                                        <label class="form-label">Date</label>
                                                        <input type="date" class="form-control form-control-sm" name="monitoring_date" value="<?php echo date('Y-m-d'); ?>" required>
                                                    </div>
                                                    <div class="mb-2">
                                                        <label class="form-label">Amount</label>
                                                        <input type="number" class="form-control form-control-sm" name="monitoring_actual_amount" step="0.01" min="0.01" required>
                                                    </div>
                                                    <div class="mb-2">
                                                        <label class="form-label">Source Type</label>
                                                        <select class="form-select form-select-sm" name="monitoring_source_type">
                                                            <option value="manual">Manual Entry</option>
                                                            <option value="receipt">From Receipt</option>
                                                            <option value="payment">From Payment</option>
                                                        </select>
                                                    </div>
                                                    <div class="mb-2">
                                                        <label class="form-label">Reference</label>
                                                        <input type="text" class="form-control form-control-sm" name="monitoring_source_reference" placeholder="Receipt/Payment no">
                                                    </div>
                                                    <div class="mb-2">
                                                        <label class="form-label">Notes</label>
                                                        <input type="text" class="form-control form-control-sm" name="monitoring_notes">
                                                    </div>
                                                    <button type="submit" class="btn btn-sm btn-success w-100">Add Record</button>
                                                </form>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-8">
                                        <div class="table-responsive" style="max-height: 400px; overflow-y: auto;">
                                            <table class="table table-sm table-striped">
                                                <thead class="table-light">
                                                    <tr>
                                                        <th>Date</th>
                                                        <th>Category</th>
                                                        <th>Type</th>
                                                        <th>Amount</th>
                                                        <th>Cumulative</th>
                                                        <th>Source</th>
                                                        <th>Reference</th>
                                                        <th>Notes</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php if (empty($selected_monitoring)): ?>
                                                        <tr><td colspan="8" class="text-center text-muted">No monitoring records.</td></tr>
                                                    <?php else: foreach ($selected_monitoring as $record): ?>
                                                        <tr>
                                                            <td><?php echo date('M d, Y', strtotime($record['monitoring_date'])); ?></td>
                                                            <td><?php echo htmlspecialchars($record['category_name'] ?? 'N/A'); ?></td>
                                                            <td>
                                                                <span class="badge <?php echo $record['category_type'] == 'income' ? 'bg-success' : 'bg-danger'; ?>">
                                                                    <?php echo ucfirst($record['category_type'] ?? ''); ?>
                                                                </span>
                                                            </td>
                                                            <td class="fw-bold"><?php echo number_format($record['actual_amount'], 2); ?></td>
                                                            <td><?php echo number_format($record['cumulative_actual'], 2); ?></td>
                                                            <td><?php echo ucfirst($record['source_type'] ?? 'manual'); ?></td>
                                                            <td><?php echo htmlspecialchars($record['source_reference'] ?? '-'); ?></td>
                                                            <td><?php echo htmlspecialchars($record['notes'] ?? '-'); ?></td>
                                                        </tr>
                                                    <?php endforeach; endif; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- ========== HISTORY TAB ========== -->
                            <div class="tab-pane fade" id="history" role="tabpanel">
                                <?php
                                    $hist_stmt = $db->prepare("
                                        SELECT * FROM budget_history 
                                        WHERE budget_id = ? 
                                        ORDER BY created_at DESC 
                                        LIMIT 50
                                    ");
                                    $hist_stmt->execute([$selected_budget['id']]);
                                    $history = $hist_stmt->fetchAll();
                                ?>
                                <?php if (empty($history)): ?>
                                    <div class="text-center text-muted py-4">No history records.</div>
                                <?php else: ?>
                                    <div class="table-responsive">
                                        <table class="table table-sm table-striped">
                                            <thead>
                                                <tr>
                                                    <th>Date</th>
                                                    <th>Action</th>
                                                    <th>Field</th>
                                                    <th>Old Value</th>
                                                    <th>New Value</th>
                                                    <th>Notes</th>
                                                    <th>By</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($history as $h): ?>
                                                    <tr>
                                                        <td><?php echo date('M d, Y H:i', strtotime($h['created_at'])); ?></td>
                                                        <td>
                                                            <span class="badge bg-secondary"><?php echo htmlspecialchars($h['action']); ?></span>
                                                        </td>
                                                        <td><?php echo htmlspecialchars($h['field_name'] ?? '-'); ?></td>
                                                        <td><?php echo htmlspecialchars(substr($h['old_value'] ?? '', 0, 50)); ?></td>
                                                        <td><?php echo htmlspecialchars(substr($h['new_value'] ?? '', 0, 50)); ?></td>
                                                        <td><?php echo htmlspecialchars($h['notes'] ?? '-'); ?></td>
                                                        <td><?php echo htmlspecialchars($h['performed_by_username'] ?? 'system'); ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <?php if (isset($_GET['new']) || isset($_GET['edit'])): ?>
        <!-- ============================================ -->
        <!-- BUDGET EDIT/CREATE FORM -->
        <!-- ============================================ -->
        <div class="row mt-4">
            <div class="col-12">
                <div class="card">
                    <div class="card-header bg-success text-white">
                        <h5 class="mb-0">
                            <i class="bi bi-pencil-square me-2"></i>
                            <?php echo isset($_GET['edit']) ? 'Edit Budget' : 'Create New Budget'; ?>
                        </h5>
                    </div>
                    <div class="card-body">
                        <form method="POST">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                            <input type="hidden" name="save_budget" value="1">
                            <?php if ($edit_budget): ?>
                                <input type="hidden" name="budget_id" value="<?php echo (int)$edit_budget['id']; ?>">
                            <?php endif; ?>
                            
                            <div class="row g-3">
                                <div class="col-md-3">
                                    <label class="form-label">Fiscal Year <span class="text-danger">*</span></label>
                                    <select class="form-select" name="fiscal_year" required>
                                        <?php foreach (getFiscalYears() as $year): ?>
                                            <option value="<?php echo $year; ?>" 
                                                <?php echo ($edit_budget ? $edit_budget['fiscal_year'] : date('Y')) == $year ? 'selected' : ''; ?>>
                                                <?php echo $year; ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Fiscal Period <span class="text-danger">*</span></label>
                                    <select class="form-select" name="fiscal_period" required>
                                        <?php foreach (getFiscalPeriods() as $code => $desc): ?>
                                            <option value="<?php echo $code; ?>" 
                                                <?php echo ($edit_budget ? $edit_budget['fiscal_period'] : 'Q1') == $code ? 'selected' : ''; ?>>
                                                <?php echo $code; ?> (<?php echo $desc; ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Budget Type</label>
                                    <select class="form-select" name="budget_type">
                                        <option value="annual" <?php echo ($edit_budget && $edit_budget['budget_type'] == 'annual') ? 'selected' : ''; ?>>Annual</option>
                                        <option value="quarterly" <?php echo ($edit_budget && $edit_budget['budget_type'] == 'quarterly') ? 'selected' : ''; ?>>Quarterly</option>
                                        <option value="monthly" <?php echo ($edit_budget && $edit_budget['budget_type'] == 'monthly') ? 'selected' : ''; ?>>Monthly</option>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Status</label>
                                    <select class="form-select" name="status">
                                        <option value="draft" <?php echo ($edit_budget && $edit_budget['status'] == 'draft') ? 'selected' : ''; ?>>Draft</option>
                                        <option value="pending" <?php echo ($edit_budget && $edit_budget['status'] == 'pending') ? 'selected' : ''; ?>>Pending Review</option>
                                        <?php if (in_array($user_role, ['system_admin', 'ceo', 'managing_director'])): ?>
                                            <option value="approved" <?php echo ($edit_budget && $edit_budget['status'] == 'approved') ? 'selected' : ''; ?>>Approved</option>
                                        <?php endif; ?>
                                    </select>
                                </div>
                                <div class="col-md-8">
                                    <label class="form-label">Description</label>
                                    <input type="text" class="form-control" name="description" 
                                           value="<?php echo $edit_budget ? htmlspecialchars($edit_budget['description'] ?? '') : ''; ?>"
                                           placeholder="Budget description">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">Notes</label>
                                    <input type="text" class="form-control" name="notes" 
                                           value="<?php echo $edit_budget ? htmlspecialchars($edit_budget['notes'] ?? '') : ''; ?>"
                                           placeholder="Additional notes">
                                </div>
                                <div class="col-12">
                                    <div class="alert alert-info">
                                        <i class="bi bi-info-circle me-2"></i>
                                        <strong>Note:</strong> When creating a new budget, categories will be automatically created from the Chart of Accounts (Income and Expense accounts with level ≥ 3).
                                    </div>
                                </div>
                                <div class="col-12">
                                    <hr>
                                    <div class="d-flex justify-content-end gap-2">
                                        <a href="budget.php" class="btn btn-secondary btn-sm">Cancel</a>
                                        <button type="submit" class="btn btn-success btn-sm">
                                            <i class="bi bi-save me-1"></i>
                                            <?php echo isset($_GET['edit']) ? 'Update Budget' : 'Create Budget'; ?>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<!-- ============================================ -->
<!-- MODALS -->
<!-- ============================================ -->

<!-- Update Goal Modal -->
<div class="modal fade" id="updateGoalModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="bi bi-arrow-up-circle me-2"></i>Update Goal Progress</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="updateGoalForm">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                    <input type="hidden" name="update_goal_progress" value="1">
                    <input type="hidden" name="goal_progress_id" id="goalProgressId">
                    
                    <div class="mb-3">
                        <label class="form-label">Current Goal</label>
                        <p id="goalProgressName" class="fw-bold"></p>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Target Value</label>
                        <p id="goalProgressTarget"></p>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Current Achievement</label>
                        <p id="goalProgressCurrent"></p>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Actual Value Achieved <span class="text-danger">*</span></label>
                        <input type="number" class="form-control" name="goal_actual_value" id="goalActualValue" 
                               step="0.01" min="0" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Progress Notes</label>
                        <textarea class="form-control" name="goal_progress_notes" id="goalProgressNotes" rows="2"></textarea>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary btn-sm" id="saveGoalProgressBtn">
                    <i class="bi bi-save me-1"></i>Update Progress
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Update Status Modal -->
<div class="modal fade" id="updateStatusModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-warning">
                <h5 class="modal-title"><i class="bi bi-arrow-right-circle me-2"></i>Update Budget Status</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="updateStatusForm">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                    <input type="hidden" name="update_status" value="1">
                    <input type="hidden" name="budget_id" id="statusBudgetId">
                    <input type="hidden" name="new_status" id="statusNewStatus">
                    
                    <p>Are you sure you want to change the status of this budget?</p>
                    <div class="mb-3">
                        <label class="form-label">Rejection Reason (if rejecting)</label>
                        <textarea class="form-control" name="rejection_reason" id="rejectionReason" rows="2"></textarea>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-warning btn-sm" id="confirmStatusUpdate">
                    <i class="bi bi-check-circle me-1"></i>Confirm
                </button>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // =============== SEARCH AND FILTER ===============
    const searchInput = document.getElementById('budgetSearch');
    const statusFilter = document.getElementById('budgetStatusFilter');
    const table = document.getElementById('budgetsTable');
    
    function filterTable() {
        const search = searchInput.value.toLowerCase();
        const status = statusFilter.value;
        const rows = table.querySelectorAll('tbody tr');
        
        rows.forEach(row => {
            const text = row.textContent.toLowerCase();
            const rowStatus = row.querySelector('td:nth-child(8)')?.textContent?.toLowerCase() || '';
            
            let show = true;
            if (search && !text.includes(search)) show = false;
            if (status && !rowStatus.includes(status.toLowerCase())) show = false;
            
            row.style.display = show ? '' : 'none';
        });
    }
    
    searchInput.addEventListener('input', filterTable);
    statusFilter.addEventListener('change', filterTable);

    // =============== UPDATE GOAL PROGRESS ===============
    document.querySelectorAll('.update-goal').forEach(btn => {
        btn.addEventListener('click', function() {
            const goalId = this.dataset.goalId;
            const row = this.closest('.goal-card');
            const name = row.querySelector('h6')?.textContent || '';
            const target = row.querySelector('.small')?.textContent || '';
            const achievement = row.querySelector('.progress-bar')?.style?.width || '0%';
            
            document.getElementById('goalProgressId').value = goalId;
            document.getElementById('goalProgressName').textContent = name;
            document.getElementById('goalProgressTarget').textContent = target;
            document.getElementById('goalProgressCurrent').textContent = 'Progress: ' + achievement;
            document.getElementById('goalActualValue').value = '';
            document.getElementById('goalProgressNotes').value = '';
            
            new bootstrap.Modal(document.getElementById('updateGoalModal')).show();
        });
    });
    
    document.getElementById('saveGoalProgressBtn').addEventListener('click', function() {
        const form = document.getElementById('updateGoalForm');
        const formData = new FormData(form);
        
        fetch('budget.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.text())
        .then(() => {
            location.reload();
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Error updating goal progress');
        });
    });

    // =============== UPDATE BUDGET STATUS ===============
    window.updateBudgetStatus = function(budgetId, status) {
        document.getElementById('statusBudgetId').value = budgetId;
        document.getElementById('statusNewStatus').value = status;
        document.getElementById('rejectionReason').value = '';
        
        const modal = new bootstrap.Modal(document.getElementById('updateStatusModal'));
        modal.show();
    };
    
    document.getElementById('confirmStatusUpdate').addEventListener('click', function() {
        const form = document.getElementById('updateStatusForm');
        const formData = new FormData(form);
        
        fetch('budget.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.text())
        .then(() => {
            location.reload();
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Error updating budget status');
        });
    });

    // =============== DELETE BUDGET ===============
    document.querySelectorAll('.delete-budget').forEach(btn => {
        btn.addEventListener('click', function() {
            const budgetId = this.dataset.id;
            const budgetCode = this.dataset.code;
            
            if (confirm(`Are you sure you want to delete budget ${budgetCode}? This will delete all associated categories, goals, monitoring records, and history.`)) {
                window.location.href = `budget_delete.php?id=${budgetId}`;
            }
        });
    });

    // =============== ADD MONITORING ===============
    document.querySelectorAll('.add-monitoring').forEach(btn => {
        btn.addEventListener('click', function() {
            const categoryId = this.dataset.categoryId;
            const select = document.querySelector('select[name="monitoring_category_id"]');
            if (select) {
                select.value = categoryId;
                document.getElementById('monitoring-tab').click();
            }
        });
    });

    // =============== KEYBOARD SHORTCUTS ===============
    document.addEventListener('keydown', function(e) {
        if (e.ctrlKey && e.key === 'n') {
            window.location.href = '?new=1';
            e.preventDefault();
        }
        if (e.ctrlKey && e.key === 'f') {
            document.getElementById('budgetSearch').focus();
            e.preventDefault();
        }
    });

    // =============== AUTO-CLOSE ALERTS ===============
    setTimeout(function() {
        document.querySelectorAll('.alert').forEach(alert => {
            setTimeout(() => {
                alert.style.transition = 'opacity 0.5s';
                alert.style.opacity = '0';
                setTimeout(() => alert.remove(), 500);
            }, 5000);
        });
    }, 1000);
});
</script>

<?php include '../includes/footer.php'; ?>
