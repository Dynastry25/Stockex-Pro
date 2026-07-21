<?php
/**
 * Agent Management System
 * Location: /trader/agent_management.php
 * Access: Finance Officers, System Admins, and Traders with limited permissions
 */

require_once '../config/config.php';
require_once '../auth/auth_middleware.php';

// Security headers
header("X-Frame-Options: DENY");
header("X-Content-Type-Options: nosniff");
header("X-XSS-Protection: 1; mode=block");

// =====================================================
// ACCESS CONTROL - FIXED FOR TRADER FOLDER
// =====================================================

// Check if user is logged in
require_login();

$user_role = $_SESSION['role'] ?? '';

// Define who can access what
$finance_roles = ['finance_officer', 'system_admin'];
$trader_roles = ['trader', 'finance_officer', 'system_admin'];

// Check access based on role
if (!in_array($user_role, $trader_roles)) {
    show_alert('Access denied. You do not have permission to access this page.', 'danger');
    redirect('dashboard.php');
    exit;
}

// Additional check: Traders can VIEW only, Finance can VIEW and MANAGE
$is_finance = in_array($user_role, $finance_roles);
$is_trader = ($user_role === 'trader');

// If trader, restrict to view-only mode
if ($is_trader) {
    // Traders can only view agents, not create/edit/pay
    $view_only_mode = true;
} else {
    $view_only_mode = false;
}

// CSRF Protection
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$db = getDBConnection();
$current_user = $_SESSION['username'] ?? 'system';
$success_message = '';
$error_message = '';

// =====================================================
// DATABASE SETUP - CREATE AGENT TABLES
// =====================================================

try {
    // Agents table
    $db->exec("CREATE TABLE IF NOT EXISTS agents (
        id INT AUTO_INCREMENT PRIMARY KEY,
        agent_code VARCHAR(50) UNIQUE NOT NULL,
        name VARCHAR(255) NOT NULL,
        contact_person VARCHAR(255),
        phone VARCHAR(50),
        email VARCHAR(100),
        address TEXT,
        city VARCHAR(100),
        country VARCHAR(50) DEFAULT 'Tanzania',
        id_number VARCHAR(50),
        id_type ENUM('national_id', 'passport', 'driver_license', 'voter_id') DEFAULT 'national_id',
        tin VARCHAR(50),
        commission_rate DECIMAL(5,4) DEFAULT 0.1000,
        first_trade_commission_rate DECIMAL(5,4) DEFAULT 0.2500,
        notes TEXT,
        status ENUM('active', 'inactive', 'suspended') DEFAULT 'active',
        created_by VARCHAR(100),
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_agent_code (agent_code),
        INDEX idx_status (status)
    )");
    
    // Agent-Client linking table
    $db->exec("CREATE TABLE IF NOT EXISTS agent_clients (
        id INT AUTO_INCREMENT PRIMARY KEY,
        agent_id INT NOT NULL,
        client_cds_account VARCHAR(50) NOT NULL,
        client_name VARCHAR(255),
        linked_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        linked_by VARCHAR(100),
        status ENUM('active', 'inactive') DEFAULT 'active',
        UNIQUE KEY unique_agent_client (agent_id, client_cds_account),
        INDEX idx_agent_id (agent_id),
        INDEX idx_client_cds (client_cds_account),
        FOREIGN KEY (agent_id) REFERENCES agents(id) ON DELETE CASCADE
    )");
    
    // Agent Commission table
    $db->exec("CREATE TABLE IF NOT EXISTS agent_commissions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        agent_id INT NOT NULL,
        trade_id INT NOT NULL,
        trade_reference VARCHAR(50),
        client_cds_account VARCHAR(50),
        trade_consideration DECIMAL(15,2),
        brokerage_commission DECIMAL(15,2),
        agent_commission DECIMAL(15,2),
        commission_rate DECIMAL(5,4),
        is_first_trade BOOLEAN DEFAULT FALSE,
        trade_date DATE,
        calculation_date DATETIME DEFAULT CURRENT_TIMESTAMP,
        status ENUM('pending', 'approved', 'paid') DEFAULT 'pending',
        payment_id INT DEFAULT NULL,
        notes TEXT,
        INDEX idx_agent_id (agent_id),
        INDEX idx_trade_id (trade_id),
        INDEX idx_status (status),
        INDEX idx_payment_id (payment_id),
        FOREIGN KEY (agent_id) REFERENCES agents(id) ON DELETE CASCADE
    )");
    
    // Agent commission payments table
    $db->exec("CREATE TABLE IF NOT EXISTS agent_commission_payments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        payment_no VARCHAR(50) UNIQUE NOT NULL,
        agent_id INT NOT NULL,
        agent_name VARCHAR(255),
        total_amount DECIMAL(15,2),
        commission_ids TEXT,
        trade_count INT DEFAULT 0,
        first_trade_count INT DEFAULT 0,
        regular_trade_count INT DEFAULT 0,
        payment_date DATE,
        payment_mode VARCHAR(50),
        bank_account_id INT,
        bank_name VARCHAR(255),
        bank_account_number VARCHAR(50),
        transaction_reference VARCHAR(100),
        notes TEXT,
        status ENUM('pending', 'paid', 'cancelled') DEFAULT 'pending',
        created_by VARCHAR(100),
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_agent_id (agent_id),
        INDEX idx_payment_no (payment_no),
        INDEX idx_status (status)
    )");
    
    // Add commission tracking to trades table if missing
    $db->exec("ALTER TABLE trades ADD COLUMN IF NOT EXISTS agent_id INT DEFAULT NULL AFTER final_brokerage_fee");
    $db->exec("ALTER TABLE trades ADD COLUMN IF NOT EXISTS agent_commission_calculated TINYINT DEFAULT 0 AFTER agent_id");
    $db->exec("ALTER TABLE trades ADD COLUMN IF NOT EXISTS agent_commission_amount DECIMAL(15,2) DEFAULT 0.00 AFTER agent_commission_calculated");
    $db->exec("ALTER TABLE trades ADD COLUMN IF NOT EXISTS agent_commission_rate DECIMAL(5,4) DEFAULT 0.0000 AFTER agent_commission_amount");
    $db->exec("ALTER TABLE trades ADD COLUMN IF NOT EXISTS is_first_agent_trade TINYINT DEFAULT 0 AFTER agent_commission_rate");
    
    // Add indexes
    $db->exec("ALTER TABLE trades ADD INDEX IF NOT EXISTS idx_agent_id (agent_id)");
    $db->exec("ALTER TABLE trades ADD INDEX IF NOT EXISTS idx_agent_commission_calculated (agent_commission_calculated)");
    
} catch (Exception $e) {
    error_log("Agent table setup error: " . $e->getMessage());
    $error_message = "Database setup error: " . $e->getMessage();
}

// =====================================================
// HELPER FUNCTIONS
// =====================================================

function generateAgentCode($db) {
    $prefix = 'AGT';
    $year = date('Y');
    
    $stmt = $db->prepare("SELECT MAX(CAST(SUBSTRING(agent_code, 8) AS UNSIGNED)) as max_seq 
                          FROM agents WHERE agent_code LIKE ?");
    $stmt->execute([$prefix . $year . '%']);
    $result = $stmt->fetch();
    
    $seq = ($result && $result['max_seq']) ? (int)$result['max_seq'] + 1 : 1;
    return $prefix . $year . str_pad($seq, 4, '0', STR_PAD_LEFT);
}

function getAgentCommissionRate($db, $agent_id, $is_first_trade = false) {
    $stmt = $db->prepare("SELECT first_trade_commission_rate, commission_rate FROM agents WHERE id = ? AND status = 'active'");
    $stmt->execute([$agent_id]);
    $agent = $stmt->fetch();
    
    if (!$agent) return 0;
    
    return $is_first_trade ? 
        ($agent['first_trade_commission_rate'] ?? 0.2500) : 
        ($agent['commission_rate'] ?? 0.1000);
}

function calculateAgentCommission($brokerage_commission, $rate) {
    return $brokerage_commission * $rate;
}

function getAgentClients($db, $agent_id) {
    $stmt = $db->prepare("
        SELECT ac.*, 
               (SELECT COUNT(*) FROM trades WHERE client_cds_account = ac.client_cds_account AND status = 'active') as trade_count
        FROM agent_clients ac
        WHERE ac.agent_id = ?
        AND ac.status = 'active'
        ORDER BY ac.client_name
    ");
    $stmt->execute([$agent_id]);
    return $stmt->fetchAll();
}

function getUnlinkedClients($db) {
    $stmt = $db->query("
        SELECT cds_account, client_name 
        FROM clients 
        WHERE status = 'active'
        AND cds_account NOT IN (
            SELECT DISTINCT client_cds_account 
            FROM agent_clients 
            WHERE status = 'active'
        )
        ORDER BY client_name
    ");
    return $stmt->fetchAll();
}

function getAgentCommissionSummary($db, $agent_id) {
    $stmt = $db->prepare("
        SELECT 
            COUNT(CASE WHEN is_first_trade = 1 THEN 1 END) as first_trades,
            COUNT(CASE WHEN is_first_trade = 0 THEN 1 END) as regular_trades,
            COUNT(*) as total_trades,
            SUM(agent_commission) as total_commission,
            SUM(CASE WHEN status = 'pending' THEN agent_commission ELSE 0 END) as pending_commission,
            SUM(CASE WHEN status = 'approved' THEN agent_commission ELSE 0 END) as approved_commission,
            SUM(CASE WHEN status = 'paid' THEN agent_commission ELSE 0 END) as paid_commission
        FROM agent_commissions
        WHERE agent_id = ?
    ");
    $stmt->execute([$agent_id]);
    return $stmt->fetch();
}

function calculateAndSaveAgentCommission($db, $trade_id, $agent_id) {
    try {
        $stmt = $db->prepare("
            SELECT t.*, 
                   (SELECT COUNT(*) FROM trades t2 
                    WHERE t2.client_cds_account = t.client_cds_account 
                    AND t2.agent_id = t.agent_id
                    AND t2.id < t.id
                    AND t2.agent_commission_calculated = 1) as previous_trade_count
            FROM trades t
            WHERE t.id = ?
        ");
        $stmt->execute([$trade_id]);
        $trade = $stmt->fetch();
        
        if (!$trade) return false;
        if ($trade['agent_commission_calculated'] == 1) return true;
        
        $is_first_trade = ($trade['previous_trade_count'] == 0);
        $rate = getAgentCommissionRate($db, $agent_id, $is_first_trade);
        
        $brokerage_commission = $trade['final_brokerage_fee'] ?? 0;
        $agent_commission = calculateAgentCommission($brokerage_commission, $rate);
        
        $stmt = $db->prepare("
            UPDATE trades 
            SET agent_id = ?, 
                agent_commission_calculated = 1,
                agent_commission_amount = ?,
                agent_commission_rate = ?,
                is_first_agent_trade = ?
            WHERE id = ?
        ");
        $stmt->execute([$agent_id, $agent_commission, $rate, $is_first_trade ? 1 : 0, $trade_id]);
        
        $stmt = $db->prepare("
            INSERT INTO agent_commissions (
                agent_id, trade_id, trade_reference, client_cds_account,
                trade_consideration, brokerage_commission, agent_commission,
                commission_rate, is_first_trade, trade_date, status
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')
        ");
        $stmt->execute([
            $agent_id,
            $trade_id,
            $trade['trade_reference'],
            $trade['client_cds_account'],
            $trade['consideration'],
            $brokerage_commission,
            $agent_commission,
            $rate,
            $is_first_trade ? 1 : 0,
            $trade['trade_date']
        ]);
        
        return true;
    } catch (Exception $e) {
        error_log("Agent commission calculation error: " . $e->getMessage());
        return false;
    }
}

// =====================================================
// PROCESS FUNCTIONS - ONLY FOR FINANCE USERS
// =====================================================

function processAgentCommissionPayment($db, $data) {
    // This function requires finance permissions
    if (!in_array($_SESSION['role'] ?? '', ['finance_officer', 'system_admin'])) {
        throw new Exception("Only finance officers can process commission payments");
    }
    
    try {
        $db->beginTransaction();
        
        $payment_no = 'AGTPMT' . date('Ymd') . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
        
        $stmt = $db->prepare("
            INSERT INTO agent_commission_payments (
                payment_no, agent_id, agent_name, total_amount,
                commission_ids, trade_count, first_trade_count, regular_trade_count,
                payment_date, payment_mode, bank_account_id, bank_name,
                bank_account_number, transaction_reference, notes, status,
                created_by
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?)
        ");
        
        $stmt->execute([
            $payment_no,
            $data['agent_id'],
            $data['agent_name'],
            $data['total_amount'],
            $data['commission_ids'],
            $data['trade_count'],
            $data['first_trade_count'],
            $data['regular_trade_count'],
            $data['payment_date'],
            $data['payment_mode'],
            $data['bank_account_id'],
            $data['bank_name'],
            $data['bank_account_number'],
            $data['transaction_reference'] ?? null,
            $data['notes'] ?? null,
            $_SESSION['username'] ?? 'system'
        ]);
        
        $payment_id = $db->lastInsertId();
        
        $ids = explode(',', $data['commission_ids']);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $db->prepare("UPDATE agent_commissions SET status = 'approved', payment_id = ? WHERE id IN ($placeholders)");
        $stmt->execute(array_merge([$payment_id], $ids));
        
        $db->commit();
        
        return [
            'success' => true,
            'payment_no' => $payment_no,
            'payment_id' => $payment_id
        ];
        
    } catch (Exception $e) {
        $db->rollBack();
        error_log("Agent commission payment error: " . $e->getMessage());
        throw $e;
    }
}

// =====================================================
// POST HANDLING - RESTRICTED FOR FINANCE USERS
// =====================================================

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Only finance users can POST (create/edit/pay)
    if (!in_array($user_role, $finance_roles)) {
        $error_message = "You do not have permission to perform this action.";
    } elseif (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $error_message = "CSRF token validation failed. Please try again.";
    } else {
        // ============ CREATE/UPDATE AGENT ============
        if (isset($_POST['save_agent'])) {
            $agent_id = (int)$_POST['agent_id'] ?? 0;
            $name = trim($_POST['name'] ?? '');
            $contact_person = trim($_POST['contact_person'] ?? '');
            $phone = trim($_POST['phone'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $address = trim($_POST['address'] ?? '');
            $city = trim($_POST['city'] ?? '');
            $country = trim($_POST['country'] ?? 'Tanzania');
            $id_number = trim($_POST['id_number'] ?? '');
            $id_type = $_POST['id_type'] ?? 'national_id';
            $tin = trim($_POST['tin'] ?? '');
            $commission_rate = (float)($_POST['commission_rate'] ?? 0.1000);
            $first_trade_commission_rate = (float)($_POST['first_trade_commission_rate'] ?? 0.2500);
            $notes = trim($_POST['notes'] ?? '');
            $status = $_POST['status'] ?? 'active';
            
            if (empty($name)) {
                $error_message = "Agent name is required.";
            } else {
                try {
                    if ($agent_id > 0) {
                        $stmt = $db->prepare("
                            UPDATE agents SET
                                name = ?, contact_person = ?, phone = ?, email = ?,
                                address = ?, city = ?, country = ?, id_number = ?,
                                id_type = ?, tin = ?, commission_rate = ?,
                                first_trade_commission_rate = ?, notes = ?, status = ?
                            WHERE id = ?
                        ");
                        $stmt->execute([
                            $name, $contact_person, $phone, $email,
                            $address, $city, $country, $id_number,
                            $id_type, $tin, $commission_rate,
                            $first_trade_commission_rate, $notes, $status,
                            $agent_id
                        ]);
                        $success_message = "Agent updated successfully!";
                    } else {
                        $agent_code = generateAgentCode($db);
                        $stmt = $db->prepare("
                            INSERT INTO agents (
                                agent_code, name, contact_person, phone, email,
                                address, city, country, id_number, id_type,
                                tin, commission_rate, first_trade_commission_rate,
                                notes, status, created_by
                            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                        ");
                        $stmt->execute([
                            $agent_code, $name, $contact_person, $phone, $email,
                            $address, $city, $country, $id_number, $id_type,
                            $tin, $commission_rate, $first_trade_commission_rate,
                            $notes, $status, $_SESSION['username'] ?? 'system'
                        ]);
                        $success_message = "Agent created successfully! Agent Code: $agent_code";
                    }
                    
                    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                } catch (Exception $e) {
                    $error_message = "Error saving agent: " . $e->getMessage();
                }
            }
        }
        
        // ============ LINK CLIENTS ============
        if (isset($_POST['link_clients'])) {
            $agent_id = (int)$_POST['agent_id'] ?? 0;
            $client_cds_list = $_POST['client_cds'] ?? [];
            
            if (empty($agent_id)) {
                $error_message = "Please select an agent.";
            } elseif (empty($client_cds_list)) {
                $error_message = "Please select at least one client to link.";
            } else {
                try {
                    $linked_count = 0;
                    foreach ($client_cds_list as $cds_account) {
                        if (empty($cds_account)) continue;
                        
                        $stmt = $db->prepare("SELECT client_name FROM clients WHERE cds_account = ?");
                        $stmt->execute([$cds_account]);
                        $client = $stmt->fetch();
                        
                        $stmt = $db->prepare("SELECT id FROM agent_clients WHERE agent_id = ? AND client_cds_account = ?");
                        $stmt->execute([$agent_id, $cds_account]);
                        
                        if (!$stmt->fetch()) {
                            $stmt = $db->prepare("
                                INSERT INTO agent_clients (agent_id, client_cds_account, client_name, linked_by)
                                VALUES (?, ?, ?, ?)
                            ");
                            $stmt->execute([
                                $agent_id,
                                $cds_account,
                                $client['client_name'] ?? '',
                                $_SESSION['username'] ?? 'system'
                            ]);
                            $linked_count++;
                        }
                    }
                    
                    $success_message = "$linked_count client(s) linked successfully!";
                    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                } catch (Exception $e) {
                    $error_message = "Error linking clients: " . $e->getMessage();
                }
            }
        }
        
        // ============ UNLINK CLIENT ============
        if (isset($_POST['unlink_client'])) {
            $agent_id = (int)$_POST['agent_id'] ?? 0;
            $client_cds = $_POST['client_cds'] ?? '';
            
            try {
                $stmt = $db->prepare("UPDATE agent_clients SET status = 'inactive' WHERE agent_id = ? AND client_cds_account = ?");
                $stmt->execute([$agent_id, $client_cds]);
                $success_message = "Client unlinked successfully!";
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            } catch (Exception $e) {
                $error_message = "Error unlinking client: " . $e->getMessage();
            }
        }
        
        // ============ CALCULATE COMMISSIONS ============
        if (isset($_POST['calculate_commissions'])) {
            $agent_id = (int)$_POST['agent_id'] ?? 0;
            
            try {
                $stmt = $db->prepare("SELECT client_cds_account FROM agent_clients WHERE agent_id = ? AND status = 'active'");
                $stmt->execute([$agent_id]);
                $clients = $stmt->fetchAll();
                
                $calculated = 0;
                foreach ($clients as $client) {
                    $stmt = $db->prepare("
                        SELECT id FROM trades 
                        WHERE client_cds_account = ? 
                        AND agent_commission_calculated = 0
                        AND status = 'active'
                    ");
                    $stmt->execute([$client['client_cds_account']]);
                    $trades = $stmt->fetchAll();
                    
                    foreach ($trades as $trade) {
                        if (calculateAndSaveAgentCommission($db, $trade['id'], $agent_id)) {
                            $calculated++;
                        }
                    }
                }
                
                $success_message = "Calculated commissions for $calculated trade(s)!";
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            } catch (Exception $e) {
                $error_message = "Error calculating commissions: " . $e->getMessage();
            }
        }
        
        // ============ PAY COMMISSIONS - FINANCE ONLY ============
        if (isset($_POST['pay_commissions'])) {
            // Only finance officers can pay commissions
            if (!in_array($user_role, $finance_roles)) {
                $error_message = "Only finance officers can process commission payments.";
            } else {
                $agent_id = (int)$_POST['agent_id'] ?? 0;
                $commission_ids = $_POST['commission_ids'] ?? [];
                $payment_date = $_POST['payment_date'] ?? date('Y-m-d');
                $payment_mode = (int)($_POST['payment_mode'] ?? 0);
                $bank_account_id = (int)($_POST['ac_credit'] ?? 0);
                $transaction_reference = trim($_POST['transaction_reference'] ?? '');
                $notes = trim($_POST['payment_notes'] ?? '');
                
                if (empty($commission_ids)) {
                    $error_message = "Please select at least one commission to pay.";
                } elseif ($bank_account_id <= 0) {
                    $error_message = "Please select a bank account.";
                } else {
                    try {
                        $placeholders = implode(',', array_fill(0, count($commission_ids), '?'));
                        $stmt = $db->prepare("
                            SELECT c.*, a.name as agent_name
                            FROM agent_commissions c
                            INNER JOIN agents a ON c.agent_id = a.id
                            WHERE c.id IN ($placeholders)
                            AND c.status = 'pending'
                        ");
                        $stmt->execute($commission_ids);
                        $commissions = $stmt->fetchAll();
                        
                        if (empty($commissions)) {
                            $error_message = "No pending commissions found to pay.";
                        } else {
                            $total_amount = array_sum(array_column($commissions, 'agent_commission'));
                            $trade_count = count($commissions);
                            $first_trade_count = count(array_filter($commissions, function($c) {
                                return $c['is_first_trade'] == 1;
                            }));
                            $regular_trade_count = $trade_count - $first_trade_count;
                            $commission_id_string = implode(',', $commission_ids);
                            $agent_name = $commissions[0]['agent_name'];
                            
                            $bank = getBankAccountDetails($db, $bank_account_id);
                            
                            $payment_data = [
                                'agent_id' => $agent_id,
                                'agent_name' => $agent_name,
                                'total_amount' => $total_amount,
                                'commission_ids' => $commission_id_string,
                                'trade_count' => $trade_count,
                                'first_trade_count' => $first_trade_count,
                                'regular_trade_count' => $regular_trade_count,
                                'payment_date' => $payment_date,
                                'payment_mode' => $payment_mode,
                                'bank_account_id' => $bank_account_id,
                                'bank_name' => $bank['bank_name'] ?? '',
                                'bank_account_number' => $bank['account_number'] ?? '',
                                'transaction_reference' => $transaction_reference,
                                'notes' => $notes
                            ];
                            
                            $result = processAgentCommissionPayment($db, $payment_data);
                            
                            if ($result['success']) {
                                $success_message = "Commission payment processed successfully! Payment No: {$result['payment_no']}";
                                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                            }
                        }
                    } catch (Exception $e) {
                        $error_message = "Error processing payment: " . $e->getMessage();
                    }
                }
            }
        }
    }
}

// =====================================================
// GET DATA FOR DISPLAY
// =====================================================

// Get all agents
$agents = [];
try {
    $stmt = $db->query("SELECT * FROM agents ORDER BY name");
    $agents = $stmt->fetchAll();
} catch (Exception $e) {
    error_log("Error fetching agents: " . $e->getMessage());
}

// Get selected agent data
$selected_agent_id = isset($_GET['agent_id']) ? (int)$_GET['agent_id'] : 0;
$selected_agent = null;
$agent_clients = [];
$agent_commissions = [];
$agent_commission_summary = null;
$unlinked_clients = [];
$payment_methods = [];
$bank_accounts = [];

if ($selected_agent_id > 0) {
    try {
        $stmt = $db->prepare("SELECT * FROM agents WHERE id = ?");
        $stmt->execute([$selected_agent_id]);
        $selected_agent = $stmt->fetch();
        
        $agent_clients = getAgentClients($db, $selected_agent_id);
        $agent_commission_summary = getAgentCommissionSummary($db, $selected_agent_id);
        
        $status_filter = isset($_GET['commission_status']) ? $_GET['commission_status'] : null;
        $sql = "SELECT * FROM agent_commissions WHERE agent_id = ?";
        $params = [$selected_agent_id];
        if ($status_filter && in_array($status_filter, ['pending', 'approved', 'paid'])) {
            $sql .= " AND status = ?";
            $params[] = $status_filter;
        }
        $sql .= " ORDER BY trade_date DESC LIMIT 500";
        
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $agent_commissions = $stmt->fetchAll();
        
        $unlinked_clients = getUnlinkedClients($db);
        
        // Get payment methods and bank accounts (only for finance users)
        if (in_array($user_role, $finance_roles)) {
            $stmt = $db->query("SELECT id, code, description FROM payment_methods WHERE status = 'active' ORDER BY priority");
            $payment_methods = $stmt->fetchAll();
            
            $stmt = $db->query("SELECT id, bank_name, account_name, account_number, currency FROM banks_accounts WHERE status = 'active'");
            $bank_accounts = $stmt->fetchAll();
        }
        
    } catch (Exception $e) {
        error_log("Error fetching agent data: " . $e->getMessage());
    }
}

// Get all unlinked clients for the modal
$all_unlinked_clients = [];
try {
    $all_unlinked_clients = getUnlinkedClients($db);
} catch (Exception $e) {
    error_log("Error fetching unlinked clients: " . $e->getMessage());
}

$page_title = 'Agent Management';
include '../includes/header.php';
?>

<!-- REST OF THE HTML - SAME AS BEFORE BUT WITH ACCESS CONTROLS -->
<!-- Add a note about view-only mode for traders -->

<div class="container-fluid">
    
    <!-- Access Notice for Traders -->
    <?php if ($view_only_mode): ?>
        <div class="alert alert-info alert-dismissible fade show">
            <i class="bi bi-info-circle me-2"></i>
            <strong>View-Only Mode:</strong> You are viewing agent information. 
            Only Finance Officers can create, edit, or process payments.
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Alerts -->
    <?php if (!empty($success_message)): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <i class="bi bi-check-circle-fill me-2"></i>
            <?php echo $success_message; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    
    <?php if (!empty($error_message)): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <i class="bi bi-exclamation-triangle-fill me-2"></i>
            <?php echo $error_message; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    
    <!-- Page Header -->
    <div class="row mb-3">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h4 class="mb-0"><i class="bi bi-person-badge text-primary me-2"></i>Agent Management</h4>
                    <small class="text-muted">
                        <?php if ($view_only_mode): ?>
                            View-only access (Agent commissions and client linking)
                        <?php else: ?>
                            Full management (Create, edit, link clients, pay commissions)
                        <?php endif; ?>
                    </small>
                </div>
                <div>
                    <?php if (!$view_only_mode): ?>
                        <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#agentModal">
                            <i class="bi bi-plus-circle me-1"></i>New Agent
                        </button>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Agent Selector -->
    <div class="row mb-4">
        <div class="col-md-8">
            <div class="card">
                <div class="card-body">
                    <div class="row g-3 align-items-end">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Select Agent</label>
                            <select class="form-select" id="agentSelector" onchange="window.location.href='?agent_id='+this.value">
                                <option value="">-- Select Agent --</option>
                                <?php foreach ($agents as $agent): ?>
                                    <option value="<?php echo $agent['id']; ?>" <?php echo $selected_agent_id == $agent['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($agent['agent_code'] . ' - ' . $agent['name']); ?>
                                        <?php if ($agent['status'] !== 'active'): ?>
                                            (<?php echo $agent['status']; ?>)
                                        <?php endif; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php if ($selected_agent_id > 0 && !$view_only_mode): ?>
                            <div class="col-md-6 text-end">
                                <button class="btn btn-outline-primary btn-sm" data-bs-toggle="modal" data-bs-target="#editAgentModal">
                                    <i class="bi bi-pencil me-1"></i>Edit Agent
                                </button>
                                <button class="btn btn-outline-success btn-sm" data-bs-toggle="modal" data-bs-target="#linkClientsModal">
                                    <i class="bi bi-link-45deg me-1"></i>Link Clients
                                </button>
                                <button class="btn btn-outline-warning btn-sm" onclick="calculateCommissions(<?php echo $selected_agent_id; ?>)">
                                    <i class="bi bi-calculator me-1"></i>Calc Commissions
                                </button>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        <?php if ($selected_agent_id > 0 && $selected_agent): ?>
            <div class="col-md-4">
                <div class="card h-100 bg-primary text-white">
                    <div class="card-body">
                        <div class="d-flex justify-content-between">
                            <div>
                                <h6 class="mb-1 text-white-50">Agent Code</h6>
                                <h5 class="mb-0"><?php echo htmlspecialchars($selected_agent['agent_code']); ?></h5>
                            </div>
                            <div class="text-end">
                                <h6 class="mb-1 text-white-50">Status</h6>
                                <span class="badge bg-<?php echo $selected_agent['status'] === 'active' ? 'success' : 'warning'; ?>">
                                    <?php echo ucfirst($selected_agent['status']); ?>
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <?php if ($selected_agent_id > 0 && $selected_agent): ?>
        
        <!-- Agent Stats -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="agent-stat">
                    <div class="number"><?php echo count($agent_clients); ?></div>
                    <div class="label">Linked Clients</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="agent-stat">
                    <div class="number"><?php echo number_format($agent_commission_summary['total_trades'] ?? 0); ?></div>
                    <div class="label">Total Trades</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="agent-stat">
                    <div class="number">Tsh <?php echo number_format($agent_commission_summary['total_commission'] ?? 0, 2); ?></div>
                    <div class="label">Total Commission</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="agent-stat">
                    <div class="number" style="color: #28a745;">Tsh <?php echo number_format(($agent_commission_summary['pending_commission'] ?? 0), 2); ?></div>
                    <div class="label">Pending Payment</div>
                </div>
            </div>
        </div>

        <!-- Tabs -->
        <ul class="nav nav-tabs mb-3" id="agentTabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active" id="clients-tab" data-bs-toggle="tab" data-bs-target="#clients" type="button" role="tab">
                    <i class="bi bi-people me-1"></i>Clients (<?php echo count($agent_clients); ?>)
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="commissions-tab" data-bs-toggle="tab" data-bs-target="#commissions" type="button" role="tab">
                    <i class="bi bi-currency-dollar me-1"></i>Commissions (<?php echo count($agent_commissions); ?>)
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="payments-tab" data-bs-toggle="tab" data-bs-target="#payments" type="button" role="tab">
                    <i class="bi bi-cash-stack me-1"></i>Payments
                </button>
            </li>
        </ul>

        <div class="tab-content">
            <!-- Clients Tab -->
            <div class="tab-pane fade show active" id="clients" role="tabpanel">
                <div class="card">
                    <div class="card-body">
                        <?php if (empty($agent_clients)): ?>
                            <div class="text-center py-4">
                                <i class="bi bi-people" style="font-size: 48px; color: #dee2e6;"></i>
                                <p class="text-muted mt-2">No clients linked to this agent.</p>
                                <?php if (!$view_only_mode): ?>
                                    <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#linkClientsModal">
                                        <i class="bi bi-link-45deg me-1"></i>Link Clients
                                    </button>
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-sm table-hover">
                                    <thead>
                                        <tr>
                                            <th>Client Name</th>
                                            <th>CDS Account</th>
                                            <th>Linked Date</th>
                                            <th>Trades</th>
                                            <?php if (!$view_only_mode): ?>
                                                <th>Actions</th>
                                            <?php endif; ?>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($agent_clients as $client): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($client['client_name']); ?></td>
                                                <td><code><?php echo htmlspecialchars($client['client_cds_account']); ?></code></td>
                                                <td><?php echo date('d/m/Y H:i', strtotime($client['linked_at'])); ?></td>
                                                <td><?php echo $client['trade_count'] ?? 0; ?></td>
                                                <?php if (!$view_only_mode): ?>
                                                    <td>
                                                        <form method="POST" style="display:inline;" onsubmit="return confirm('Unlink this client?')">
                                                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                                            <input type="hidden" name="unlink_client" value="1">
                                                            <input type="hidden" name="agent_id" value="<?php echo $selected_agent_id; ?>">
                                                            <input type="hidden" name="client_cds" value="<?php echo htmlspecialchars($client['client_cds_account']); ?>">
                                                            <button type="submit" class="btn btn-outline-danger btn-sm">
                                                                <i class="bi bi-link-45deg"></i> Unlink
                                                            </button>
                                                        </form>
                                                    </td>
                                                <?php endif; ?>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Commissions Tab -->
            <div class="tab-pane fade" id="commissions" role="tabpanel">
                <div class="card">
                    <div class="card-body">
                        <!-- Commission Filters -->
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <form method="GET" class="row g-2">
                                    <input type="hidden" name="agent_id" value="<?php echo $selected_agent_id; ?>">
                                    <div class="col-auto">
                                        <select class="form-select form-select-sm" name="commission_status" onchange="this.form.submit()">
                                            <option value="">All Status</option>
                                            <option value="pending" <?php echo ($_GET['commission_status'] ?? '') === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                            <option value="approved" <?php echo ($_GET['commission_status'] ?? '') === 'approved' ? 'selected' : ''; ?>>Approved</option>
                                            <option value="paid" <?php echo ($_GET['commission_status'] ?? '') === 'paid' ? 'selected' : ''; ?>>Paid</option>
                                        </select>
                                    </div>
                                    <?php if (!$view_only_mode): ?>
                                        <div class="col-auto">
                                            <button class="btn btn-outline-warning btn-sm" onclick="calculateCommissions(<?php echo $selected_agent_id; ?>)">
                                                <i class="bi bi-calculator me-1"></i>Calculate
                                            </button>
                                        </div>
                                    <?php endif; ?>
                                </form>
                            </div>
                            <?php if (!$view_only_mode): ?>
                                <div class="col-md-6 text-end">
                                    <button class="btn btn-success btn-sm" data-bs-toggle="modal" data-bs-target="#payCommissionsModal">
                                        <i class="bi bi-cash-coin me-1"></i>Pay Selected
                                    </button>
                                </div>
                            <?php endif; ?>
                        </div>

                        <?php if (empty($agent_commissions)): ?>
                            <div class="text-center py-4">
                                <i class="bi bi-currency-dollar" style="font-size: 48px; color: #dee2e6;"></i>
                                <p class="text-muted mt-2">No commissions found for this agent.</p>
                                <?php if (!$view_only_mode): ?>
                                    <button class="btn btn-warning btn-sm" onclick="calculateCommissions(<?php echo $selected_agent_id; ?>)">
                                        <i class="bi bi-calculator me-1"></i>Calculate Commissions
                                    </button>
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-sm table-hover">
                                    <thead>
                                        <tr>
                                            <?php if (!$view_only_mode): ?>
                                                <th><input type="checkbox" id="selectAllCommissions" onchange="toggleAllCommissions()"></th>
                                            <?php endif; ?>
                                            <th>Trade Ref</th>
                                            <th>Client</th>
                                            <th>Trade Date</th>
                                            <th>Consideration</th>
                                            <th>Brokerage</th>
                                            <th>Rate</th>
                                            <th>Agent Commission</th>
                                            <th>Type</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($agent_commissions as $commission): ?>
                                            <tr>
                                                <?php if (!$view_only_mode): ?>
                                                    <td>
                                                        <?php if ($commission['status'] === 'pending'): ?>
                                                            <input type="checkbox" class="commission-checkbox" value="<?php echo $commission['id']; ?>">
                                                        <?php endif; ?>
                                                    </td>
                                                <?php endif; ?>
                                                <td><code><?php echo htmlspecialchars($commission['trade_reference']); ?></code></td>
                                                <td><?php echo htmlspecialchars($commission['client_cds_account']); ?></td>
                                                <td><?php echo date('d/m/Y', strtotime($commission['trade_date'])); ?></td>
                                                <td>Tsh <?php echo number_format($commission['trade_consideration'], 2); ?></td>
                                                <td>Tsh <?php echo number_format($commission['brokerage_commission'], 2); ?></td>
                                                <td><?php echo number_format($commission['commission_rate'] * 100, 2); ?>%</td>
                                                <td class="fw-bold">Tsh <?php echo number_format($commission['agent_commission'], 2); ?></td>
                                                <td>
                                                    <?php if ($commission['is_first_trade']): ?>
                                                        <span class="badge badge-first-trade">First Trade</span>
                                                    <?php else: ?>
                                                        <span class="badge badge-regular-trade">Regular</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <span class="badge commission-status-<?php echo $commission['status']; ?>">
                                                        <?php echo ucfirst($commission['status']); ?>
                                                    </span>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                    <tfoot>
                                        <tr>
                                            <td <?php echo $view_only_mode ? 'colspan="8"' : 'colspan="7"'; ?> class="text-end fw-bold">Total:</td>
                                            <td class="fw-bold text-success">Tsh <?php echo number_format(array_sum(array_column($agent_commissions, 'agent_commission')), 2); ?></td>
                                            <td colspan="2"></td>
                                        </tr>
                                        <?php if ($agent_commission_summary): ?>
                                            <tr>
                                                <td colspan="10" class="text-muted small">
                                                    <span class="me-3">First Trades: <?php echo $agent_commission_summary['first_trades'] ?? 0; ?></span>
                                                    <span class="me-3">Regular Trades: <?php echo $agent_commission_summary['regular_trades'] ?? 0; ?></span>
                                                    <span class="me-3">Pending: Tsh <?php echo number_format($agent_commission_summary['pending_commission'] ?? 0, 2); ?></span>
                                                    <span class="me-3">Paid: Tsh <?php echo number_format($agent_commission_summary['paid_commission'] ?? 0, 2); ?></span>
                                                </td>
                                            </tr>
                                        <?php endif; ?>
                                    </tfoot>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Payments Tab -->
            <div class="tab-pane fade" id="payments" role="tabpanel">
                <div class="card">
                    <div class="card-body">
                        <?php
                        $stmt = $db->prepare("SELECT * FROM agent_commission_payments WHERE agent_id = ? ORDER BY created_at DESC");
                        $stmt->execute([$selected_agent_id]);
                        $payments = $stmt->fetchAll();
                        ?>
                        
                        <?php if (empty($payments)): ?>
                            <div class="text-center py-4">
                                <i class="bi bi-cash-stack" style="font-size: 48px; color: #dee2e6;"></i>
                                <p class="text-muted mt-2">No payment history for this agent.</p>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-sm table-hover">
                                    <thead>
                                        <tr>
                                            <th>Payment No</th>
                                            <th>Date</th>
                                            <th>Total Amount</th>
                                            <th>Trades</th>
                                            <th>First/Regular</th>
                                            <th>Status</th>
                                            <th>Bank</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($payments as $payment): ?>
                                            <tr>
                                                <td><code><?php echo htmlspecialchars($payment['payment_no']); ?></code></td>
                                                <td><?php echo date('d/m/Y', strtotime($payment['payment_date'])); ?></td>
                                                <td class="fw-bold">Tsh <?php echo number_format($payment['total_amount'], 2); ?></td>
                                                <td><?php echo $payment['trade_count']; ?></td>
                                                <td>
                                                    <span class="badge badge-first-trade"><?php echo $payment['first_trade_count']; ?></span>
                                                    <span class="badge badge-regular-trade"><?php echo $payment['regular_trade_count']; ?></span>
                                                </td>
                                                <td>
                                                    <span class="badge bg-<?php echo $payment['status'] === 'paid' ? 'success' : 'warning'; ?>">
                                                        <?php echo ucfirst($payment['status']); ?>
                                                    </span>
                                                </td>
                                                <td><?php echo htmlspecialchars($payment['bank_name']); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                    <tfoot>
                                        <tr>
                                            <td colspan="2" class="text-end fw-bold">Total Paid:</td>
                                            <td class="fw-bold text-success">
                                                Tsh <?php echo number_format(array_sum(array_column($payments, 'total_amount')), 2); ?>
                                            </td>
                                            <td colspan="4"></td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

    <?php endif; ?>
</div>

<!-- ===================================================== -->
<!-- MODALS - Only show to Finance users -->
<!-- ===================================================== -->

<!-- New Agent Modal -->
<div class="modal fade" id="agentModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="bi bi-person-plus me-2"></i>New Agent</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    <input type="hidden" name="save_agent" value="1">
                    <input type="hidden" name="agent_id" value="0">
                    
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Full Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="name" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Contact Person</label>
                            <input type="text" class="form-control" name="contact_person">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Phone</label>
                            <input type="text" class="form-control" name="phone">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Email</label>
                            <input type="email" class="form-control" name="email">
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold">Address</label>
                            <textarea class="form-control" name="address" rows="2"></textarea>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">City</label>
                            <input type="text" class="form-control" name="city">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Country</label>
                            <input type="text" class="form-control" name="country" value="Tanzania">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Status</label>
                            <select class="form-select" name="status">
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                                <option value="suspended">Suspended</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">ID Type</label>
                            <select class="form-select" name="id_type">
                                <option value="national_id">National ID</option>
                                <option value="passport">Passport</option>
                                <option value="driver_license">Driver's License</option>
                                <option value="voter_id">Voter ID</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">ID Number</label>
                            <input type="text" class="form-control" name="id_number">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">TIN (Tax ID)</label>
                            <input type="text" class="form-control" name="tin">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">Commission Rate (Regular)</label>
                            <div class="input-group">
                                <input type="number" class="form-control" name="commission_rate" step="0.0001" min="0" max="1" value="0.1000">
                                <span class="input-group-text">%</span>
                            </div>
                            <small class="text-muted">Default: 10% (0.1000)</small>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">First Trade Rate</label>
                            <div class="input-group">
                                <input type="number" class="form-control" name="first_trade_commission_rate" step="0.0001" min="0" max="1" value="0.2500">
                                <span class="input-group-text">%</span>
                            </div>
                            <small class="text-muted">Default: 25% (0.2500)</small>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold">Notes</label>
                            <textarea class="form-control" name="notes" rows="2"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Create Agent</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Agent Modal -->
<div class="modal fade" id="editAgentModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="bi bi-pencil me-2"></i>Edit Agent</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <?php if ($selected_agent): ?>
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        <input type="hidden" name="save_agent" value="1">
                        <input type="hidden" name="agent_id" value="<?php echo $selected_agent['id']; ?>">
                        
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Full Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="name" value="<?php echo htmlspecialchars($selected_agent['name']); ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Contact Person</label>
                                <input type="text" class="form-control" name="contact_person" value="<?php echo htmlspecialchars($selected_agent['contact_person'] ?? ''); ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Phone</label>
                                <input type="text" class="form-control" name="phone" value="<?php echo htmlspecialchars($selected_agent['phone'] ?? ''); ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Email</label>
                                <input type="email" class="form-control" name="email" value="<?php echo htmlspecialchars($selected_agent['email'] ?? ''); ?>">
                            </div>
                            <div class="col-12">
                                <label class="form-label fw-semibold">Address</label>
                                <textarea class="form-control" name="address" rows="2"><?php echo htmlspecialchars($selected_agent['address'] ?? ''); ?></textarea>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-semibold">City</label>
                                <input type="text" class="form-control" name="city" value="<?php echo htmlspecialchars($selected_agent['city'] ?? ''); ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-semibold">Country</label>
                                <input type="text" class="form-control" name="country" value="<?php echo htmlspecialchars($selected_agent['country'] ?? 'Tanzania'); ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-semibold">Status</label>
                                <select class="form-select" name="status">
                                    <option value="active" <?php echo $selected_agent['status'] === 'active' ? 'selected' : ''; ?>>Active</option>
                                    <option value="inactive" <?php echo $selected_agent['status'] === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                                    <option value="suspended" <?php echo $selected_agent['status'] === 'suspended' ? 'selected' : ''; ?>>Suspended</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">ID Type</label>
                                <select class="form-select" name="id_type">
                                    <option value="national_id" <?php echo ($selected_agent['id_type'] ?? '') === 'national_id' ? 'selected' : ''; ?>>National ID</option>
                                    <option value="passport" <?php echo ($selected_agent['id_type'] ?? '') === 'passport' ? 'selected' : ''; ?>>Passport</option>
                                    <option value="driver_license" <?php echo ($selected_agent['id_type'] ?? '') === 'driver_license' ? 'selected' : ''; ?>>Driver's License</option>
                                    <option value="voter_id" <?php echo ($selected_agent['id_type'] ?? '') === 'voter_id' ? 'selected' : ''; ?>>Voter ID</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">ID Number</label>
                                <input type="text" class="form-control" name="id_number" value="<?php echo htmlspecialchars($selected_agent['id_number'] ?? ''); ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">TIN (Tax ID)</label>
                                <input type="text" class="form-control" name="tin" value="<?php echo htmlspecialchars($selected_agent['tin'] ?? ''); ?>">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label fw-semibold">Commission Rate (Regular)</label>
                                <div class="input-group">
                                    <input type="number" class="form-control" name="commission_rate" step="0.0001" min="0" max="1" value="<?php echo number_format($selected_agent['commission_rate'] ?? 0.1000, 4); ?>">
                                    <span class="input-group-text">%</span>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label fw-semibold">First Trade Rate</label>
                                <div class="input-group">
                                    <input type="number" class="form-control" name="first_trade_commission_rate" step="0.0001" min="0" max="1" value="<?php echo number_format($selected_agent['first_trade_commission_rate'] ?? 0.2500, 4); ?>">
                                    <span class="input-group-text">%</span>
                                </div>
                            </div>
                            <div class="col-12">
                                <label class="form-label fw-semibold">Notes</label>
                                <textarea class="form-control" name="notes" rows="2"><?php echo htmlspecialchars($selected_agent['notes'] ?? ''); ?></textarea>
                            </div>
                        </div>
                        <div class="mt-3 text-end">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-primary">Update Agent</button>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Link Clients Modal -->
<div class="modal fade" id="linkClientsModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title"><i class="bi bi-link-45deg me-2"></i>Link Clients to Agent</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    <input type="hidden" name="link_clients" value="1">
                    <input type="hidden" name="agent_id" value="<?php echo $selected_agent_id; ?>">
                    
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle me-2"></i>
                        Select clients to link to <strong><?php echo htmlspecialchars($selected_agent['name'] ?? ''); ?></strong>.
                        Once linked, the agent will earn commissions on all trades from these clients.
                    </div>
                    
                    <?php if (empty($all_unlinked_clients)): ?>
                        <div class="text-center py-4">
                            <i class="bi bi-check-circle" style="font-size: 48px; color: #28a745;"></i>
                            <p class="text-success mt-2">All clients are already linked to agents!</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-sm table-hover">
                                <thead>
                                    <tr>
                                        <th><input type="checkbox" id="selectAllClients" onchange="toggleAllClients()"></th>
                                        <th>Client Name</th>
                                        <th>CDS Account</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($all_unlinked_clients as $client): ?>
                                        <tr>
                                            <td><input type="checkbox" name="client_cds[]" value="<?php echo htmlspecialchars($client['cds_account']); ?>"></td>
                                            <td><?php echo htmlspecialchars($client['client_name']); ?></td>
                                            <td><code><?php echo htmlspecialchars($client['cds_account']); ?></code></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <?php if (!empty($all_unlinked_clients)): ?>
                        <button type="submit" class="btn btn-success">Link Selected Clients</button>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Pay Commissions Modal - Finance Only -->
<?php if (!$view_only_mode): ?>
<div class="modal fade" id="payCommissionsModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-warning text-dark">
                <h5 class="modal-title"><i class="bi bi-cash-coin me-2"></i>Pay Agent Commissions</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    <input type="hidden" name="pay_commissions" value="1">
                    <input type="hidden" name="agent_id" value="<?php echo $selected_agent_id; ?>">
                    
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle me-2"></i>
                        Pay pending commissions for <strong><?php echo htmlspecialchars($selected_agent['name'] ?? ''); ?></strong>.
                        Only selected commissions will be paid.
                    </div>
                    
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Payment Date <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" name="payment_date" value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Payment Mode <span class="text-danger">*</span></label>
                            <select class="form-select" name="payment_mode" required>
                                <option value="">Select Mode</option>
                                <?php foreach ($payment_methods as $method): ?>
                                    <option value="<?php echo $method['id']; ?>"><?php echo htmlspecialchars($method['description']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Bank Account <span class="text-danger">*</span></label>
                            <select class="form-select" name="ac_credit" required>
                                <option value="">Select Bank</option>
                                <?php foreach ($bank_accounts as $bank): ?>
                                    <option value="<?php echo $bank['id']; ?>">
                                        <?php echo htmlspecialchars($bank['bank_name'] . ' - ' . $bank['account_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Transaction Reference</label>
                            <input type="text" class="form-control" name="transaction_reference" placeholder="e.g., Bank transfer reference">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Record in Financial</label>
                            <select class="form-select" name="record_in_financial">
                                <option value="yes">Yes</option>
                                <option value="no">No</option>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold">Notes</label>
                            <input type="text" class="form-control" name="payment_notes" placeholder="Additional notes">
                        </div>
                    </div>
                    
                    <hr>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="alert alert-secondary">
                                <strong>Selected Commissions:</strong>
                                <span id="selectedCommissionCount">0</span> commissions
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="alert alert-success">
                                <strong>Total Amount:</strong>
                                <span id="selectedCommissionTotal">Tsh 0.00</span>
                            </div>
                        </div>
                    </div>
                    
                    <div id="selectedCommissionIds"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success">Process Payment</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Styles -->
<style>
.agent-stat {
    text-align: center;
    padding: 12px;
    background: #f8f9fa;
    border-radius: 8px;
}
.agent-stat .number {
    font-size: 24px;
    font-weight: bold;
    color: #0d6efd;
}
.agent-stat .label {
    font-size: 12px;
    color: #6c757d;
}
.commission-status-pending { background: #fff3cd; color: #856404; }
.commission-status-approved { background: #cce5ff; color: #004085; }
.commission-status-paid { background: #d4edda; color: #155724; }
.badge-first-trade { background: #ffc107; color: #212529; }
.badge-regular-trade { background: #17a2b8; color: white; }
</style>

<script>
// =====================================================
// JAVASCRIPT
// =====================================================

document.addEventListener('DOMContentLoaded', function() {
    <?php if (isset($_GET['show_modal']) && $_GET['show_modal'] == 'link'): ?>
        new bootstrap.Modal(document.getElementById('linkClientsModal')).show();
    <?php endif; ?>
});

function toggleAllCommissions() {
    const selectAll = document.getElementById('selectAllCommissions');
    const checkboxes = document.querySelectorAll('.commission-checkbox');
    checkboxes.forEach(cb => cb.checked = selectAll.checked);
    updateSelectedCommissionSummary();
}

function toggleAllClients() {
    const selectAll = document.getElementById('selectAllClients');
    const checkboxes = document.querySelectorAll('input[name="client_cds[]"]');
    checkboxes.forEach(cb => cb.checked = selectAll.checked);
}

function updateSelectedCommissionSummary() {
    const checkboxes = document.querySelectorAll('.commission-checkbox:checked');
    const count = checkboxes.length;
    let total = 0;
    let ids = [];
    
    checkboxes.forEach(cb => {
        const row = cb.closest('tr');
        const amountCell = row.querySelector('td:nth-child(8)');
        if (amountCell) {
            const amountText = amountCell.textContent.replace('Tsh ', '').replace(/,/g, '');
            total += parseFloat(amountText) || 0;
        }
        ids.push(cb.value);
    });
    
    document.getElementById('selectedCommissionCount').textContent = count;
    document.getElementById('selectedCommissionTotal').textContent = 'Tsh ' + total.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    document.getElementById('selectedCommissionIds').innerHTML = ids.map(id => 
        `<input type="hidden" name="commission_ids[]" value="${id}">`
    ).join('');
}

// Add event listeners to commission checkboxes
document.addEventListener('change', function(e) {
    if (e.target.classList.contains('commission-checkbox')) {
        updateSelectedCommissionSummary();
    }
});

function calculateCommissions(agentId) {
    if (!confirm('Calculate commissions for all linked clients? This may take a moment.')) return;
    
    const btn = event.target;
    const originalHtml = btn.innerHTML;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Calculating...';
    btn.disabled = true;
    
    fetch(`?ajax=calculate_commissions&agent_id=${agentId}`)
        .then(response => response.json())
        .then(data => {
            if (data.error) {
                alert('Error: ' + data.error);
            } else {
                alert(data.message);
                window.location.reload();
            }
        })
        .catch(error => {
            alert('Error calculating commissions: ' + error);
        })
        .finally(() => {
            btn.innerHTML = originalHtml;
            btn.disabled = false;
        });
}
</script>

<?php include '../includes/footer.php'; ?>
