<?php
/**
 * Agent Management System
 * Location: /trader/agent_management.php
 * Access: All roles with appropriate permissions
 */

require_once '../config/config.php';
require_once '../auth/auth_middleware.php';

// Security headers
header("X-Frame-Options: DENY");
header("X-Content-Type-Options: nosniff");
header("X-XSS-Protection: 1; mode=block");

// =====================================================
// ACCESS CONTROL - ALL ROLES CAN ACCESS
// =====================================================

// Check if user is logged in
require_login();

$user_role = $_SESSION['role'] ?? '';

// Define roles and permissions
$finance_roles = ['finance_officer', 'system_admin'];
$all_roles = ['trader', 'operations', 'finance_officer', 'system_admin'];

// Check access - all logged-in users can access
if (!in_array($user_role, $all_roles)) {
    show_alert('Access denied. You do not have permission to access this page.', 'danger');
    redirect('dashboard.php');
    exit;
}

// Determine permissions - ALL USERS CAN DO EVERYTHING EXCEPT PAY COMMISSIONS
$is_finance = in_array($user_role, $finance_roles);  // Can pay commissions
$can_manage = true;  // All users can manage agents and clients
$can_pay_commissions = $is_finance;  // Only finance can pay commissions
$view_only_mode = false;  // No view-only mode anymore

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
    // 1. Agents table
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
    
    // 2. Agent clients audit table
    $db->exec("CREATE TABLE IF NOT EXISTS agent_clients (
        id INT AUTO_INCREMENT PRIMARY KEY,
        agent_id INT NOT NULL,
        client_cds_account VARCHAR(50) NOT NULL,
        client_name VARCHAR(255),
        linked_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        linked_by VARCHAR(100),
        unlinked_at DATETIME DEFAULT NULL,
        unlinked_by VARCHAR(100) DEFAULT NULL,
        status ENUM('active', 'inactive') DEFAULT 'active',
        INDEX idx_agent_id (agent_id),
        INDEX idx_client_cds (client_cds_account),
        INDEX idx_status (status)
    )");
    
    // 3. Agent commissions table
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
        is_first_trade TINYINT(1) DEFAULT 0,
        trade_date DATE,
        calculation_date DATETIME DEFAULT CURRENT_TIMESTAMP,
        status ENUM('pending', 'approved', 'paid') DEFAULT 'pending',
        payment_id INT DEFAULT NULL,
        notes TEXT,
        INDEX idx_agent_id (agent_id),
        INDEX idx_trade_id (trade_id),
        INDEX idx_status (status),
        INDEX idx_payment_id (payment_id)
    )");
    
    // 4. Agent commission payments table
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
    
    // 5. Add columns to clients table
    try {
        $check = $db->query("SHOW COLUMNS FROM clients LIKE 'linked_to_agent'");
        if ($check->rowCount() == 0) {
            $db->exec("ALTER TABLE clients ADD COLUMN linked_to_agent TINYINT(1) DEFAULT 0 AFTER is_active");
            $db->exec("ALTER TABLE clients ADD COLUMN agent_id INT NULL AFTER linked_to_agent");
            $db->exec("ALTER TABLE clients ADD INDEX idx_linked_to_agent (linked_to_agent)");
            $db->exec("ALTER TABLE clients ADD INDEX idx_agent_id (agent_id)");
            error_log("Added linked_to_agent and agent_id columns to clients table");
            
            // Migrate existing data from agent_clients if any
            $check_tbl = $db->query("SHOW TABLES LIKE 'agent_clients'");
            if ($check_tbl->rowCount() > 0) {
                $stmt = $db->query("SELECT DISTINCT agent_id, client_cds_account FROM agent_clients WHERE status = 'active'");
                $linked = $stmt->fetchAll();
                foreach ($linked as $link) {
                    $stmt = $db->prepare("UPDATE clients SET linked_to_agent = 1, agent_id = ? WHERE cds_account = ?");
                    $stmt->execute([$link['agent_id'], $link['client_cds_account']]);
                }
                error_log("Migrated " . count($linked) . " existing agent-client links");
            }
        }
    } catch (Exception $e) {
        error_log("Migration warning: " . $e->getMessage());
    }
    
    // 6. Add columns to trades table
    try {
        $check = $db->query("SHOW COLUMNS FROM trades LIKE 'agent_id'");
        if ($check->rowCount() == 0) {
            $db->exec("ALTER TABLE trades ADD COLUMN agent_id INT DEFAULT NULL");
            $db->exec("ALTER TABLE trades ADD COLUMN agent_commission_calculated TINYINT DEFAULT 0");
            $db->exec("ALTER TABLE trades ADD COLUMN agent_commission_amount DECIMAL(15,2) DEFAULT 0.00");
            $db->exec("ALTER TABLE trades ADD COLUMN agent_commission_rate DECIMAL(5,4) DEFAULT 0.0000");
            $db->exec("ALTER TABLE trades ADD COLUMN is_first_agent_trade TINYINT DEFAULT 0");
            $db->exec("ALTER TABLE trades ADD INDEX idx_agent_id (agent_id)");
            $db->exec("ALTER TABLE trades ADD INDEX idx_agent_commission_calculated (agent_commission_calculated)");
        }
    } catch (Exception $e) {
        error_log("Trades table migration warning: " . $e->getMessage());
    }
    
} catch (Exception $e) {
    error_log("Agent table setup error: " . $e->getMessage());
    $error_message = "Database setup warning: " . $e->getMessage();
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

function getUnlinkedClients($db) {
    try {
        $sql = "
            SELECT 
                cds_account, 
                client_name,
                status,
                is_active,
                linked_to_agent
            FROM clients 
            WHERE status = 'active'
            AND is_active = 1
            AND (linked_to_agent = 0 OR linked_to_agent IS NULL)
            ORDER BY client_name
        ";
        
        $stmt = $db->query($sql);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
        
    } catch (Exception $e) {
        error_log("Error in getUnlinkedClients: " . $e->getMessage());
        return [];
    }
}

function getAgentClients($db, $agent_id) {
    try {
        $stmt = $db->prepare("
            SELECT 
                c.cds_account, 
                c.client_name,
                c.linked_to_agent,
                c.agent_id,
                (SELECT COUNT(*) FROM trades WHERE client_cds_account = c.cds_account AND status = 'active' AND agent_commission_calculated = 1) as calculated_trades,
                (SELECT COUNT(*) FROM trades WHERE client_cds_account = c.cds_account AND status = 'active') as total_trades,
                ac.linked_at,
                ac.linked_by
            FROM clients c
            LEFT JOIN agent_clients ac ON c.cds_account = ac.client_cds_account AND ac.status = 'active'
            WHERE c.agent_id = ?
            AND c.linked_to_agent = 1
            AND c.status = 'active'
            AND c.is_active = 1
            ORDER BY c.client_name
        ");
        $stmt->execute([$agent_id]);
        return $stmt->fetchAll();
    } catch (Exception $e) {
        error_log("Error getting agent clients: " . $e->getMessage());
        return [];
    }
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

function getClientTrades($db, $cds_account, $agent_id = null) {
    $sql = "
        SELECT 
            id, 
            trade_reference, 
            trade_date, 
            security_id, 
            trade_side, 
            quantity, 
            price, 
            consideration,
            final_brokerage_fee,
            agent_commission_calculated,
            agent_commission_amount,
            agent_commission_rate,
            is_first_agent_trade,
            agent_id
        FROM trades 
        WHERE client_cds_account = ? 
        AND status = 'active'
    ";
    
    $params = [$cds_account];
    
    if ($agent_id !== null) {
        $sql .= " AND (agent_id = ? OR agent_id IS NULL)";
        $params[] = $agent_id;
    }
    
    $sql .= " ORDER BY trade_date DESC";
    
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function linkClientsToAgent($db, $agent_id, $client_cds_list) {
    $linked_count = 0;
    $errors = [];
    
    foreach ($client_cds_list as $cds_account) {
        if (empty($cds_account)) continue;
        
        try {
            $db->beginTransaction();
            
            // Get client details
            $stmt = $db->prepare("SELECT client_name FROM clients WHERE cds_account = ? AND status = 'active' AND is_active = 1");
            $stmt->execute([$cds_account]);
            $client = $stmt->fetch();
            
            if (!$client) {
                $errors[] = "Client $cds_account not found or inactive";
                $db->rollBack();
                continue;
            }
            
            // Check if already linked to any agent
            $stmt = $db->prepare("SELECT linked_to_agent, agent_id FROM clients WHERE cds_account = ?");
            $stmt->execute([$cds_account]);
            $check = $stmt->fetch();
            
            if ($check && $check['linked_to_agent'] == 1) {
                if ($check['agent_id'] == $agent_id) {
                    $db->rollBack();
                    continue;
                } else {
                    $errors[] = "Client $cds_account is already linked to another agent";
                    $db->rollBack();
                    continue;
                }
            }
            
            // Update clients table
            $stmt = $db->prepare("
                UPDATE clients 
                SET linked_to_agent = 1, 
                    agent_id = ?,
                    updated_at = NOW()
                WHERE cds_account = ?
            ");
            $stmt->execute([$agent_id, $cds_account]);
            
            // Insert into agent_clients for audit trail
            $stmt = $db->prepare("
                INSERT INTO agent_clients (agent_id, client_cds_account, client_name, linked_by)
                VALUES (?, ?, ?, ?)
            ");
            $stmt->execute([
                $agent_id,
                $cds_account,
                $client['client_name'],
                $_SESSION['username'] ?? 'system'
            ]);
            
            $db->commit();
            $linked_count++;
            
        } catch (Exception $e) {
            $db->rollBack();
            error_log("Error linking client $cds_account: " . $e->getMessage());
            $errors[] = "Failed to link $cds_account";
        }
    }
    
    return [
        'linked_count' => $linked_count,
        'errors' => $errors
    ];
}

function unlinkClientFromAgent($db, $agent_id, $cds_account) {
    try {
        $db->beginTransaction();
        
        $stmt = $db->prepare("
            SELECT cds_account, client_name 
            FROM clients 
            WHERE cds_account = ? 
            AND agent_id = ?
            AND linked_to_agent = 1
        ");
        $stmt->execute([$cds_account, $agent_id]);
        $client = $stmt->fetch();
        
        if (!$client) {
            throw new Exception("Client not linked to this agent");
        }
        
        // Update clients table
        $stmt = $db->prepare("
            UPDATE clients 
            SET linked_to_agent = 0, 
                agent_id = NULL,
                updated_at = NOW()
            WHERE cds_account = ?
        ");
        $stmt->execute([$cds_account]);
        
        // Also remove agent_id from trades for this client (optional - we keep the commission records)
        $stmt = $db->prepare("
            UPDATE trades 
            SET agent_id = NULL 
            WHERE client_cds_account = ? 
            AND agent_id = ?
        ");
        $stmt->execute([$cds_account, $agent_id]);
        
        // Update agent_clients audit trail
        $stmt = $db->prepare("
            UPDATE agent_clients 
            SET status = 'inactive',
                unlinked_at = NOW(),
                unlinked_by = ?
            WHERE agent_id = ? 
            AND client_cds_account = ?
            AND status = 'active'
        ");
        $stmt->execute([
            $_SESSION['username'] ?? 'system',
            $agent_id,
            $cds_account
        ]);
        
        $db->commit();
        return true;
        
    } catch (Exception $e) {
        $db->rollBack();
        error_log("Error unlinking client: " . $e->getMessage());
        throw $e;
    }
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

function processAgentCommissionPayment($db, $data) {
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
// POST HANDLING - ALL USERS CAN PERFORM ACTIONS
// =====================================================

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // All users can perform actions except paying commissions
    $action = '';
    if (isset($_POST['save_agent'])) $action = 'save_agent';
    if (isset($_POST['link_clients'])) $action = 'link_clients';
    if (isset($_POST['unlink_client'])) $action = 'unlink_client';
    if (isset($_POST['calculate_commissions'])) $action = 'calculate_commissions';
    if (isset($_POST['pay_commissions'])) $action = 'pay_commissions';
    
    // Only finance can pay commissions
    if ($action === 'pay_commissions' && !in_array($user_role, $finance_roles)) {
        $error_message = "Only Finance Officers can process commission payments.";
    }
    elseif (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
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
                    $result = linkClientsToAgent($db, $agent_id, $client_cds_list);
                    
                    if ($result['linked_count'] > 0) {
                        $success_message = $result['linked_count'] . " client(s) linked successfully!";
                        if (!empty($result['errors'])) {
                            $success_message .= " (Errors: " . implode(", ", $result['errors']) . ")";
                        }
                    } else {
                        if (empty($result['errors'])) {
                            $error_message = "No new clients were linked. They may already be linked.";
                        } else {
                            $error_message = "Failed to link clients: " . implode(", ", $result['errors']);
                        }
                    }
                    
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
            
            if (empty($agent_id) || empty($client_cds)) {
                $error_message = "Invalid request.";
            } else {
                try {
                    if (unlinkClientFromAgent($db, $agent_id, $client_cds)) {
                        $success_message = "Client unlinked successfully!";
                    }
                    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                } catch (Exception $e) {
                    $error_message = "Error unlinking client: " . $e->getMessage();
                }
            }
        }
        
        // ============ CALCULATE COMMISSIONS ============
        if (isset($_POST['calculate_commissions'])) {
            $agent_id = (int)$_POST['agent_id'] ?? 0;
            $trade_ids = $_POST['trade_ids'] ?? [];
            
            if (empty($trade_ids)) {
                $error_message = "Please select at least one trade to calculate commission.";
            } else {
                try {
                    $calculated = 0;
                    foreach ($trade_ids as $trade_id) {
                        if (calculateAndSaveAgentCommission($db, $trade_id, $agent_id)) {
                            $calculated++;
                        }
                    }
                    
                    $success_message = "Calculated commissions for $calculated trade(s)!";
                    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                } catch (Exception $e) {
                    $error_message = "Error calculating commissions: " . $e->getMessage();
                }
            }
        }
        
        // ============ PAY COMMISSIONS - FINANCE ONLY ============
        if (isset($_POST['pay_commissions'])) {
            // This is already protected by the finance check above
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
                        
                        // Get bank account details
                        $stmt = $db->prepare("SELECT bank_name, account_number FROM banks_accounts WHERE id = ?");
                        $stmt->execute([$bank_account_id]);
                        $bank = $stmt->fetch();
                        
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

// =====================================================
// AJAX HANDLERS
// =====================================================

if (isset($_GET['ajax']) && $_GET['ajax'] == 'get_unlinked_clients') {
    header('Content-Type: application/json');
    try {
        $unlinked = getUnlinkedClients($db);
        echo json_encode([
            'success' => true,
            'count' => count($unlinked),
            'clients' => $unlinked
        ]);
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ]);
    }
    exit;
}

if (isset($_GET['ajax']) && $_GET['ajax'] == 'get_client_name') {
    header('Content-Type: application/json');
    $cds = $_GET['cds'] ?? '';
    
    try {
        $stmt = $db->prepare("SELECT client_name FROM clients WHERE cds_account = ?");
        $stmt->execute([$cds]);
        $client = $stmt->fetch();
        echo json_encode([
            'success' => true,
            'client_name' => $client['client_name'] ?? $cds
        ]);
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ]);
    }
    exit;
}

if (isset($_GET['ajax']) && $_GET['ajax'] == 'get_client_trades') {
    header('Content-Type: application/json');
    $cds_account = $_GET['cds_account'] ?? '';
    $agent_id = isset($_GET['agent_id']) ? (int)$_GET['agent_id'] : null;
    
    try {
        $trades = getClientTrades($db, $cds_account, $agent_id);
        echo json_encode([
            'success' => true,
            'trades' => $trades
        ]);
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ]);
    }
    exit;
}

if (isset($_GET['ajax']) && $_GET['ajax'] == 'calculate_commissions') {
    header('Content-Type: application/json');
    $agent_id = (int)$_GET['agent_id'] ?? 0;
    $trade_ids = isset($_GET['trade_ids']) ? explode(',', $_GET['trade_ids']) : [];
    
    try {
        $calculated = 0;
        foreach ($trade_ids as $trade_id) {
            if (calculateAndSaveAgentCommission($db, $trade_id, $agent_id)) {
                $calculated++;
            }
        }
        
        echo json_encode([
            'success' => true,
            'calculated' => $calculated,
            'message' => "Calculated commissions for $calculated trade(s)"
        ]);
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ]);
    }
    exit;
}

if (isset($_GET['ajax']) && $_GET['ajax'] == 'calculate_all_commissions') {
    header('Content-Type: application/json');
    $agent_id = (int)$_GET['agent_id'] ?? 0;
    
    try {
        // Get all clients linked to this agent
        $stmt = $db->prepare("SELECT cds_account FROM clients WHERE agent_id = ? AND linked_to_agent = 1 AND status = 'active'");
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
            $stmt->execute([$client['cds_account']]);
            $trades = $stmt->fetchAll();
            
            foreach ($trades as $trade) {
                if (calculateAndSaveAgentCommission($db, $trade['id'], $agent_id)) {
                    $calculated++;
                }
            }
        }
        
        echo json_encode([
            'success' => true,
            'calculated' => $calculated,
            'message' => "Calculated commissions for $calculated trade(s)"
        ]);
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ]);
    }
    exit;
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
        
        if ($selected_agent) {
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
            
            // Only fetch payment methods for finance users
            if ($can_pay_commissions) {
                $stmt = $db->query("SELECT id, code, description FROM payment_methods WHERE status = 'active' ORDER BY priority");
                $payment_methods = $stmt->fetchAll();
                
                $stmt = $db->query("SELECT id, bank_name, account_name, account_number, currency FROM banks_accounts WHERE status = 'active'");
                $bank_accounts = $stmt->fetchAll();
            }
        }
        
    } catch (Exception $e) {
        error_log("Error fetching agent data: " . $e->getMessage());
    }
}

$all_unlinked_clients = [];
try {
    $all_unlinked_clients = getUnlinkedClients($db);
} catch (Exception $e) {
    error_log("Error fetching unlinked clients: " . $e->getMessage());
}

$page_title = 'Agent Management';
include '../includes/header.php';
?>

<!-- ===================================================== -->
<!-- HTML CONTENT -->
<!-- ===================================================== -->

<style>
/* Agent Management Styles */
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

/* Searchable dropdown styles */
.agent-search-wrapper {
    position: relative;
}
.agent-search-wrapper .form-control {
    padding-right: 35px;
}
.agent-search-wrapper .clear-search {
    position: absolute;
    right: 10px;
    top: 50%;
    transform: translateY(-50%);
    cursor: pointer;
    color: #6c757d;
    z-index: 10;
    background: transparent;
    border: none;
}
.agent-search-wrapper .clear-search:hover {
    color: #dc3545;
}
.agent-dropdown {
    position: absolute;
    top: 100%;
    left: 0;
    right: 0;
    max-height: 300px;
    overflow-y: auto;
    background: white;
    border: 1px solid #ddd;
    border-radius: 4px;
    z-index: 1000;
    display: none;
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
}
.agent-dropdown .agent-item {
    padding: 8px 12px;
    cursor: pointer;
    border-bottom: 1px solid #f0f0f0;
    transition: background 0.2s;
}
.agent-dropdown .agent-item:hover {
    background: #f0f4ff;
}
.agent-dropdown .agent-item .agent-code {
    font-size: 11px;
    color: #6c757d;
}
.agent-dropdown .agent-item .agent-status {
    font-size: 10px;
    padding: 2px 6px;
    border-radius: 3px;
}
.agent-dropdown .agent-item .agent-status.active { background: #d4edda; color: #155724; }
.agent-dropdown .agent-item .agent-status.inactive { background: #f8d7da; color: #721c24; }
.agent-dropdown .agent-item .agent-status.suspended { background: #fff3cd; color: #856404; }
.agent-dropdown .no-results {
    padding: 12px;
    text-align: center;
    color: #6c757d;
}

/* Client row hover effects */
.client-row {
    cursor: pointer;
    transition: background 0.2s;
}
.client-row:hover {
    background: #f0f4ff;
}
.client-row.selected {
    background: #d4edda;
}

/* Trade selection styles */
.trade-checkbox {
    cursor: pointer;
}
.trade-row:hover {
    background: #f8f9fa;
}
.trade-row.selected {
    background: #cce5ff;
}

/* Filter input styles */
.filter-input {
    border: 1px solid #dee2e6;
    border-radius: 4px;
    padding: 6px 12px;
    width: 100%;
}
.filter-input:focus {
    border-color: #0d6efd;
    outline: none;
    box-shadow: 0 0 0 2px rgba(13,110,253,0.25);
}

/* Permission badges */
.permission-badge {
    font-size: 10px;
    padding: 2px 8px;
    border-radius: 10px;
}
.permission-badge.finance { background: #cce5ff; color: #004085; }
.permission-badge.all { background: #d4edda; color: #155724; }
</style>

<div class="container-fluid">
    
    <!-- Access Notice -->
    <?php if ($can_pay_commissions): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <i class="bi bi-check-circle me-2"></i>
            <strong>Full Access:</strong> You can manage agents, link clients, calculate commissions, and process payments.
            <span class="permission-badge finance">Finance Access</span>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php else: ?>
        <div class="alert alert-info alert-dismissible fade show">
            <i class="bi bi-info-circle me-2"></i>
            <strong>Full Management Access:</strong> You can manage agents, link clients, and calculate commissions.
            <span class="permission-badge all">Full Access</span>
            <span class="text-warning ms-2">Commission payments require Finance approval.</span>
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
                        Full management access
                        <?php if ($can_pay_commissions): ?>
                            <span class="permission-badge finance">Finance Access</span>
                        <?php else: ?>
                            <span class="permission-badge all">Full Access</span>
                        <?php endif; ?>
                    </small>
                </div>
                <div>
                    <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#agentModal">
                        <i class="bi bi-plus-circle me-1"></i>New Agent
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Agent Selector - SEARCHABLE DROPDOWN -->
    <div class="row mb-4">
        <div class="col-md-8">
            <div class="card">
                <div class="card-body">
                    <div class="row g-3 align-items-end">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Select Agent</label>
                            <div class="agent-search-wrapper">
                                <input type="text" class="form-control" id="agentSearchInput" 
                                       placeholder="Type to search agents..." 
                                       autocomplete="off"
                                       onkeyup="filterAgents()"
                                       onfocus="showAgentDropdown()">
                                <button type="button" class="clear-search" onclick="clearAgentSearch()" style="display:none;" id="clearSearchBtn">
                                    <i class="bi bi-x-circle"></i>
                                </button>
                                <div id="agentDropdown" class="agent-dropdown">
                                    <?php foreach ($agents as $agent): ?>
                                        <div class="agent-item" data-agent-id="<?php echo $agent['id']; ?>" 
                                             onclick="selectAgent(<?php echo $agent['id']; ?>, '<?php echo htmlspecialchars($agent['name']); ?>', '<?php echo htmlspecialchars($agent['agent_code']); ?>')">
                                            <div>
                                                <strong><?php echo htmlspecialchars($agent['name']); ?></strong>
                                                <span class="agent-code">(<?php echo htmlspecialchars($agent['agent_code']); ?>)</span>
                                                <span class="agent-status <?php echo $agent['status']; ?>"><?php echo ucfirst($agent['status']); ?></span>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                    <?php if (empty($agents)): ?>
                                        <div class="no-results">No agents found. Create one first.</div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <input type="hidden" id="selectedAgentId" value="<?php echo $selected_agent_id; ?>">
                        </div>
                        <div class="col-md-6 text-end">
                            <?php if ($selected_agent_id > 0): ?>
                                <button class="btn btn-outline-primary btn-sm" data-bs-toggle="modal" data-bs-target="#editAgentModal">
                                    <i class="bi bi-pencil me-1"></i>Edit Agent
                                </button>
                                <button class="btn btn-outline-success btn-sm" data-bs-toggle="modal" data-bs-target="#linkClientsModal">
                                    <i class="bi bi-link-45deg me-1"></i>Link Clients
                                </button>
                            <?php endif; ?>
                        </div>
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
                                <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#linkClientsModal">
                                    <i class="bi bi-link-45deg me-1"></i>Link Clients
                                </button>
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
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($agent_clients as $client): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($client['client_name']); ?></td>
                                                <td><code><?php echo htmlspecialchars($client['cds_account']); ?></code></td>
                                                <td><?php echo date('d/m/Y H:i', strtotime($client['linked_at'] ?? 'now')); ?></td>
                                                <td>
                                                    <?php echo ($client['calculated_trades'] ?? 0); ?> / <?php echo ($client['total_trades'] ?? 0); ?>
                                                    <button class="btn btn-outline-info btn-sm ms-1" onclick="viewClientTrades('<?php echo htmlspecialchars($client['cds_account']); ?>')" title="View Trades">
                                                        <i class="bi bi-eye"></i>
                                                    </button>
                                                </td>
                                                <td>
                                                    <form method="POST" style="display:inline;" onsubmit="return confirm('Unlink this client from the agent?')">
                                                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                                        <input type="hidden" name="unlink_client" value="1">
                                                        <input type="hidden" name="agent_id" value="<?php echo $selected_agent_id; ?>">
                                                        <input type="hidden" name="client_cds" value="<?php echo htmlspecialchars($client['cds_account']); ?>">
                                                        <button type="submit" class="btn btn-outline-danger btn-sm">
                                                            <i class="bi bi-link-45deg"></i> Unlink
                                                        </button>
                                                    </form>
                                                </td>
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
                                </form>
                            </div>
                            <?php if ($can_pay_commissions): ?>
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
                                <button class="btn btn-warning btn-sm" onclick="calculateAllCommissions(<?php echo $selected_agent_id; ?>)">
                                    <i class="bi bi-calculator me-1"></i>Calculate All Commissions
                                </button>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-sm table-hover">
                                    <thead>
                                        <tr>
                                            <?php if ($can_pay_commissions): ?>
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
                                                <?php if ($can_pay_commissions): ?>
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
                                            <td <?php echo $can_pay_commissions ? 'colspan="7"' : 'colspan="8"'; ?> class="text-end fw-bold">Total:</td>
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
<!-- MODALS -->
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
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title"><i class="bi bi-link-45deg me-2"></i>Link Clients to Agent</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="linkClientsBody">
                <div class="text-center py-4">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                    <p class="text-muted mt-2">Loading available clients...</p>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-success" id="linkClientsBtn" onclick="submitLinkClients()">Link Selected Clients</button>
            </div>
        </div>
    </div>
</div>

<!-- View Client Trades Modal -->
<div class="modal fade" id="clientTradesModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title"><i class="bi bi-list-check me-2"></i>Client Trades - <span id="clientNameDisplay">Loading...</span></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="clientTradesBody">
                <div class="text-center py-4">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                    <p class="text-muted mt-2">Loading trades...</p>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-warning" id="calculateSelectedTradesBtn" onclick="calculateSelectedTrades()">
                    <i class="bi bi-calculator me-1"></i>Calculate Commission for Selected
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Pay Commissions Modal - FINANCE ONLY -->
<?php if ($can_pay_commissions): ?>
<div class="modal fade" id="payCommissionsModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-warning text-dark">
                <h5 class="modal-title"><i class="bi bi-cash-coin me-2"></i>Pay Agent Commissions</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="payCommissionsForm">
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

<script>
// =====================================================
// JAVASCRIPT - ENHANCED WITH FILTER AND CLICK SELECT
// =====================================================

document.addEventListener('DOMContentLoaded', function() {
    // Set initial selected agent if any
    <?php if ($selected_agent_id > 0 && $selected_agent): ?>
        document.getElementById('agentSearchInput').value = '<?php echo htmlspecialchars($selected_agent['name']); ?>';
        document.getElementById('selectedAgentId').value = '<?php echo $selected_agent_id; ?>';
    <?php endif; ?>
    
    // Close dropdown when clicking outside
    document.addEventListener('click', function(e) {
        const wrapper = document.querySelector('.agent-search-wrapper');
        if (wrapper && !wrapper.contains(e.target)) {
            document.getElementById('agentDropdown').style.display = 'none';
        }
    });
    
    // Link clients modal - load clients when opened
    const linkModal = document.getElementById('linkClientsModal');
    if (linkModal) {
        linkModal.addEventListener('show.bs.modal', function() {
            refreshUnlinkedClients();
        });
    }
    
    // Commission checkboxes
    document.addEventListener('change', function(e) {
        if (e.target.classList.contains('commission-checkbox')) {
            updateSelectedCommissionSummary();
        }
    });
});

// =====================================================
// AGENT SEARCH FUNCTIONS
// =====================================================

function showAgentDropdown() {
    document.getElementById('agentDropdown').style.display = 'block';
    filterAgents();
}

function filterAgents() {
    const input = document.getElementById('agentSearchInput');
    const filter = input.value.toLowerCase().trim();
    const dropdown = document.getElementById('agentDropdown');
    const items = dropdown.querySelectorAll('.agent-item');
    const clearBtn = document.getElementById('clearSearchBtn');
    
    let hasResults = false;
    
    items.forEach(item => {
        const text = item.textContent.toLowerCase();
        if (text.includes(filter) || filter === '') {
            item.style.display = 'block';
            hasResults = true;
        } else {
            item.style.display = 'none';
        }
    });
    
    clearBtn.style.display = filter.length > 0 ? 'block' : 'none';
    
    let noResults = dropdown.querySelector('.no-results');
    if (!hasResults) {
        if (!noResults) {
            noResults = document.createElement('div');
            noResults.className = 'no-results';
            noResults.textContent = 'No agents found matching "' + filter + '"';
            dropdown.appendChild(noResults);
        }
        noResults.style.display = 'block';
    } else if (noResults) {
        noResults.style.display = 'none';
    }
    
    dropdown.style.display = 'block';
}

function clearAgentSearch() {
    document.getElementById('agentSearchInput').value = '';
    document.getElementById('clearSearchBtn').style.display = 'none';
    document.getElementById('selectedAgentId').value = '';
    filterAgents();
    window.location.href = window.location.pathname;
}

function selectAgent(agentId, agentName, agentCode) {
    document.getElementById('agentSearchInput').value = agentName;
    document.getElementById('selectedAgentId').value = agentId;
    document.getElementById('agentDropdown').style.display = 'none';
    document.getElementById('clearSearchBtn').style.display = 'block';
    
    const url = new URL(window.location.href);
    url.searchParams.set('agent_id', agentId);
    window.location.href = url.toString();
}

// =====================================================
// ENHANCED: UNLINKED CLIENTS WITH FILTER AND CLICK SELECT
// =====================================================

let selectedClients = new Set();
let clientFilterTimeout = null;

function refreshUnlinkedClients() {
    const body = document.getElementById('linkClientsBody');
    selectedClients = new Set();
    
    body.innerHTML = `
        <div class="text-center py-4">
            <div class="spinner-border text-primary" role="status">
                <span class="visually-hidden">Loading...</span>
            </div>
            <p class="text-muted mt-2">Loading available clients...</p>
        </div>
    `;
    
    fetch('?ajax=get_unlinked_clients')
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                if (data.count === 0) {
                    body.innerHTML = `
                        <div class="text-center py-4">
                            <i class="bi bi-check-circle" style="font-size: 48px; color: #28a745;"></i>
                            <p class="text-success mt-2">All clients are already linked to agents!</p>
                            <p class="text-muted small">Found ${data.count} unlinked clients</p>
                        </div>
                        <div class="text-center">
                            <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Close</button>
                        </div>
                    `;
                } else {
                    let html = `
                        <div class="alert alert-info">
                            <i class="bi bi-info-circle me-2"></i>
                            Found <strong>${data.count}</strong> clients available to link.
                            <span class="text-muted">(Click on a row to select/deselect it)</span>
                        </div>
                        <div class="mb-3">
                            <input type="text" class="filter-input" id="clientFilterInput" 
                                   placeholder="🔍 Filter clients by name or CDS account..."
                                   onkeyup="filterClientList()">
                        </div>
                        <div class="table-responsive" style="max-height: 400px; overflow-y: auto;">
                            <table class="table table-sm table-hover" id="clientListTable">
                                <thead class="sticky-top bg-white">
                                    <tr>
                                        <th style="width:40px;">
                                            <input type="checkbox" id="selectAllClients" onchange="toggleAllClients()">
                                        </th>
                                        <th>Client Name</th>
                                        <th>CDS Account</th>
                                    </tr>
                                </thead>
                                <tbody>
                    `;
                    
                    data.clients.forEach((client, index) => {
                        const rowId = 'client_' + index;
                        html += `
                            <tr id="${rowId}" class="client-row" onclick="toggleClientSelection('${rowId}', '${escapeHtml(client.cds_account)}')">
                                <td>
                                    <input type="checkbox" class="client-checkbox" value="${escapeHtml(client.cds_account)}" 
                                           onclick="event.stopPropagation(); toggleClientCheckbox('${rowId}', this)">
                                </td>
                                <td>${escapeHtml(client.client_name)}</td>
                                <td><code>${escapeHtml(client.cds_account)}</code></td>
                            </tr>
                        `;
                    });
                    
                    html += `
                                </tbody>
                            </table>
                        </div>
                        <div class="mt-2 d-flex justify-content-between">
                            <div>
                                <span id="selectedCountDisplay">0</span> clients selected
                            </div>
                            <div>
                                <button type="button" class="btn btn-sm btn-outline-primary" onclick="selectAllVisibleClients()">
                                    <i class="bi bi-check-all me-1"></i>Select All Visible
                                </button>
                                <button type="button" class="btn btn-sm btn-outline-secondary" onclick="deselectAllVisibleClients()">
                                    <i class="bi bi-x-circle me-1"></i>Deselect All Visible
                                </button>
                            </div>
                        </div>
                    `;
                    
                    body.innerHTML = html;
                    
                    // Store client data for reference
                    body.dataset.clients = JSON.stringify(data.clients);
                }
            } else {
                body.innerHTML = `
                    <div class="alert alert-danger">
                        <i class="bi bi-exclamation-triangle me-2"></i>
                        Error loading clients: ${escapeHtml(data.error || 'Unknown error')}
                        <button type="button" class="btn btn-sm btn-outline-danger mt-2" onclick="refreshUnlinkedClients()">
                            <i class="bi bi-arrow-repeat me-1"></i>Try Again
                        </button>
                    </div>
                `;
            }
        })
        .catch(error => {
            console.error('Error:', error);
            body.innerHTML = `
                <div class="alert alert-danger">
                    <i class="bi bi-exclamation-triangle me-2"></i>
                    Failed to load clients. Please try again.
                    <button type="button" class="btn btn-sm btn-outline-danger mt-2" onclick="refreshUnlinkedClients()">
                        <i class="bi bi-arrow-repeat me-1"></i>Try Again
                    </button>
                </div>
            `;
        });
}

function filterClientList() {
    const filter = document.getElementById('clientFilterInput').value.toLowerCase().trim();
    const rows = document.querySelectorAll('#clientListTable tbody tr');
    
    let visibleCount = 0;
    rows.forEach(row => {
        const text = row.textContent.toLowerCase();
        if (text.includes(filter) || filter === '') {
            row.style.display = '';
            visibleCount++;
        } else {
            row.style.display = 'none';
        }
    });
    
    updateSelectAllState();
}

function toggleClientSelection(rowId, cdsAccount) {
    const row = document.getElementById(rowId);
    if (!row) return;
    
    const checkbox = row.querySelector('.client-checkbox');
    if (!checkbox) return;
    
    checkbox.checked = !checkbox.checked;
    
    if (checkbox.checked) {
        row.classList.add('selected');
        selectedClients.add(cdsAccount);
    } else {
        row.classList.remove('selected');
        selectedClients.delete(cdsAccount);
    }
    
    updateSelectedCount();
    updateSelectAllState();
}

function toggleClientCheckbox(rowId, checkbox) {
    const row = document.getElementById(rowId);
    if (!row) return;
    
    if (checkbox.checked) {
        row.classList.add('selected');
        selectedClients.add(checkbox.value);
    } else {
        row.classList.remove('selected');
        selectedClients.delete(checkbox.value);
    }
    
    updateSelectedCount();
    updateSelectAllState();
}

function toggleAllClients() {
    const selectAll = document.getElementById('selectAllClients');
    if (!selectAll) return;
    
    const visibleRows = document.querySelectorAll('#clientListTable tbody tr:not([style*="display: none"])');
    const checkboxes = visibleRows.querySelectorAll('.client-checkbox');
    
    checkboxes.forEach(cb => {
        cb.checked = selectAll.checked;
        const row = cb.closest('tr');
        if (row) {
            if (selectAll.checked) {
                row.classList.add('selected');
                selectedClients.add(cb.value);
            } else {
                row.classList.remove('selected');
                selectedClients.delete(cb.value);
            }
        }
    });
    
    updateSelectedCount();
}

function selectAllVisibleClients() {
    const visibleRows = document.querySelectorAll('#clientListTable tbody tr:not([style*="display: none"])');
    const checkboxes = visibleRows.querySelectorAll('.client-checkbox');
    
    checkboxes.forEach(cb => {
        cb.checked = true;
        const row = cb.closest('tr');
        if (row) {
            row.classList.add('selected');
            selectedClients.add(cb.value);
        }
    });
    
    updateSelectedCount();
    updateSelectAllState();
}

function deselectAllVisibleClients() {
    const visibleRows = document.querySelectorAll('#clientListTable tbody tr:not([style*="display: none"])');
    const checkboxes = visibleRows.querySelectorAll('.client-checkbox');
    
    checkboxes.forEach(cb => {
        cb.checked = false;
        const row = cb.closest('tr');
        if (row) {
            row.classList.remove('selected');
            selectedClients.delete(cb.value);
        }
    });
    
    updateSelectedCount();
    updateSelectAllState();
}

function updateSelectedCount() {
    const countDisplay = document.getElementById('selectedCountDisplay');
    if (countDisplay) {
        countDisplay.textContent = selectedClients.size;
    }
}

function updateSelectAllState() {
    const selectAll = document.getElementById('selectAllClients');
    if (!selectAll) return;
    
    const visibleRows = document.querySelectorAll('#clientListTable tbody tr:not([style*="display: none"])');
    const checkboxes = visibleRows.querySelectorAll('.client-checkbox');
    
    if (checkboxes.length === 0) {
        selectAll.checked = false;
        selectAll.indeterminate = false;
        return;
    }
    
    const checkedCount = Array.from(checkboxes).filter(cb => cb.checked).length;
    
    if (checkedCount === checkboxes.length) {
        selectAll.checked = true;
        selectAll.indeterminate = false;
    } else if (checkedCount === 0) {
        selectAll.checked = false;
        selectAll.indeterminate = false;
    } else {
        selectAll.checked = false;
        selectAll.indeterminate = true;
    }
}

function submitLinkClients() {
    const checkboxes = document.querySelectorAll('.client-checkbox:checked');
    if (checkboxes.length === 0) {
        alert('Please select at least one client to link.');
        return;
    }
    
    if (!confirm(`Link ${checkboxes.length} client(s) to this agent?`)) {
        return;
    }
    
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = window.location.href;
    
    const csrfInput = document.createElement('input');
    csrfInput.type = 'hidden';
    csrfInput.name = 'csrf_token';
    csrfInput.value = '<?php echo $_SESSION['csrf_token']; ?>';
    form.appendChild(csrfInput);
    
    const actionInput = document.createElement('input');
    actionInput.type = 'hidden';
    actionInput.name = 'link_clients';
    actionInput.value = '1';
    form.appendChild(actionInput);
    
    const agentInput = document.createElement('input');
    agentInput.type = 'hidden';
    agentInput.name = 'agent_id';
    agentInput.value = '<?php echo $selected_agent_id; ?>';
    form.appendChild(agentInput);
    
    checkboxes.forEach(cb => {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'client_cds[]';
        input.value = cb.value;
        form.appendChild(input);
    });
    
    document.body.appendChild(form);
    form.submit();
}

// =====================================================
// VIEW CLIENT TRADES MODAL
// =====================================================

let currentClientCds = '';
let currentClientTrades = [];

function viewClientTrades(cdsAccount) {
    currentClientCds = cdsAccount;
    
    const modal = new bootstrap.Modal(document.getElementById('clientTradesModal'));
    const body = document.getElementById('clientTradesBody');
    const nameDisplay = document.getElementById('clientNameDisplay');
    
    nameDisplay.textContent = 'Loading client info...';
    body.innerHTML = `
        <div class="text-center py-4">
            <div class="spinner-border text-primary" role="status">
                <span class="visually-hidden">Loading...</span>
            </div>
            <p class="text-muted mt-2">Loading trades for client...</p>
        </div>
    `;
    
    modal.show();
    
    // Get client name
    fetch(`?ajax=get_client_name&cds=${encodeURIComponent(cdsAccount)}`)
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                nameDisplay.textContent = data.client_name || cdsAccount;
            }
        })
        .catch(() => {});
    
    // Get trades
    fetch(`?ajax=get_client_trades&cds_account=${encodeURIComponent(cdsAccount)}&agent_id=<?php echo $selected_agent_id; ?>`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                currentClientTrades = data.trades;
                renderClientTrades(data.trades);
            } else {
                body.innerHTML = `<div class="alert alert-danger">${escapeHtml(data.error || 'Error loading trades')}</div>`;
            }
        })
        .catch(error => {
            console.error('Error:', error);
            body.innerHTML = `<div class="alert alert-danger">Failed to load trades</div>`;
        });
}

function renderClientTrades(trades) {
    const body = document.getElementById('clientTradesBody');
    
    if (trades.length === 0) {
        body.innerHTML = `
            <div class="text-center py-4">
                <i class="bi bi-inbox" style="font-size: 48px; color: #dee2e6;"></i>
                <p class="text-muted mt-2">No trades found for this client.</p>
            </div>
        `;
        return;
    }
    
    let html = `
        <div class="mb-3">
            <span class="text-muted">Found <strong>${trades.length}</strong> trades. Select trades to calculate commission.</span>
            <button class="btn btn-sm btn-outline-primary ms-2" onclick="selectAllTrades()">Select All</button>
            <button class="btn btn-sm btn-outline-secondary ms-1" onclick="deselectAllTrades()">Deselect All</button>
        </div>
        <div class="table-responsive" style="max-height: 400px; overflow-y: auto;">
            <table class="table table-sm table-hover">
                <thead class="sticky-top bg-white">
                    <tr>
                        <th style="width:40px;"><input type="checkbox" id="selectAllTradesCheckbox" onchange="toggleAllTrades()"></th>
                        <th>Trade Ref</th>
                        <th>Date</th>
                        <th>Security</th>
                        <th>Side</th>
                        <th>Qty</th>
                        <th>Price</th>
                        <th>Consideration</th>
                        <th>Brokerage</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
    `;
    
    trades.forEach((trade, index) => {
        const isCalculated = trade.agent_commission_calculated == 1;
        const statusBadge = isCalculated ? 
            '<span class="badge bg-success">Calculated</span>' : 
            '<span class="badge bg-warning">Pending</span>';
        
        const rowId = 'trade_' + index;
        html += `
            <tr id="${rowId}" class="trade-row ${isCalculated ? 'calculated' : ''}" onclick="toggleTradeSelection('${rowId}')">
                <td>
                    <input type="checkbox" class="trade-checkbox" value="${trade.id}" 
                           onclick="event.stopPropagation();" 
                           ${isCalculated ? 'disabled' : ''}>
                </td>
                <td><code>${escapeHtml(trade.trade_reference)}</code></td>
                <td>${escapeHtml(trade.trade_date)}</td>
                <td>${escapeHtml(trade.security_id)}</td>
                <td><span class="badge ${trade.trade_side === 'buy' ? 'bg-success' : 'bg-danger'}">${escapeHtml(trade.trade_side)}</span></td>
                <td>${Number(trade.quantity).toLocaleString()}</td>
                <td>${Number(trade.price).toFixed(2)}</td>
                <td>Tsh ${Number(trade.consideration).toLocaleString()}</td>
                <td>Tsh ${Number(trade.final_brokerage_fee || 0).toFixed(2)}</td>
                <td>${statusBadge}</td>
            </tr>
        `;
    });
    
    html += `
                </tbody>
            </table>
        </div>
        <div class="mt-2">
            <span id="selectedTradesCount">0</span> trades selected for commission calculation
            ${currentClientTrades.some(t => t.agent_commission_calculated == 1) ? 
                '<span class="text-muted ms-2">(Some trades already calculated)</span>' : ''}
        </div>
    `;
    
    body.innerHTML = html;
    updateSelectedTradesCount();
}

function toggleTradeSelection(rowId) {
    const row = document.getElementById(rowId);
    if (!row) return;
    
    const checkbox = row.querySelector('.trade-checkbox');
    if (!checkbox || checkbox.disabled) return;
    
    checkbox.checked = !checkbox.checked;
    if (checkbox.checked) {
        row.classList.add('selected');
    } else {
        row.classList.remove('selected');
    }
    
    updateSelectedTradesCount();
    updateSelectAllTradesState();
}

function toggleAllTrades() {
    const selectAll = document.getElementById('selectAllTradesCheckbox');
    if (!selectAll) return;
    
    const checkboxes = document.querySelectorAll('.trade-checkbox:not([disabled])');
    checkboxes.forEach(cb => {
        cb.checked = selectAll.checked;
        const row = cb.closest('tr');
        if (row) {
            if (selectAll.checked) {
                row.classList.add('selected');
            } else {
                row.classList.remove('selected');
            }
        }
    });
    
    updateSelectedTradesCount();
}

function selectAllTrades() {
    const checkboxes = document.querySelectorAll('.trade-checkbox:not([disabled])');
    checkboxes.forEach(cb => {
        cb.checked = true;
        const row = cb.closest('tr');
        if (row) row.classList.add('selected');
    });
    updateSelectedTradesCount();
    updateSelectAllTradesState();
}

function deselectAllTrades() {
    const checkboxes = document.querySelectorAll('.trade-checkbox:not([disabled])');
    checkboxes.forEach(cb => {
        cb.checked = false;
        const row = cb.closest('tr');
        if (row) row.classList.remove('selected');
    });
    updateSelectedTradesCount();
    updateSelectAllTradesState();
}

function updateSelectedTradesCount() {
    const count = document.querySelectorAll('.trade-checkbox:checked').length;
    const display = document.getElementById('selectedTradesCount');
    if (display) display.textContent = count;
}

function updateSelectAllTradesState() {
    const selectAll = document.getElementById('selectAllTradesCheckbox');
    if (!selectAll) return;
    
    const checkboxes = document.querySelectorAll('.trade-checkbox:not([disabled])');
    if (checkboxes.length === 0) {
        selectAll.checked = false;
        selectAll.indeterminate = false;
        return;
    }
    
    const checkedCount = Array.from(checkboxes).filter(cb => cb.checked).length;
    
    if (checkedCount === checkboxes.length) {
        selectAll.checked = true;
        selectAll.indeterminate = false;
    } else if (checkedCount === 0) {
        selectAll.checked = false;
        selectAll.indeterminate = false;
    } else {
        selectAll.checked = false;
        selectAll.indeterminate = true;
    }
}

function calculateSelectedTrades() {
    const checkboxes = document.querySelectorAll('.trade-checkbox:checked');
    if (checkboxes.length === 0) {
        alert('Please select at least one trade to calculate commission.');
        return;
    }
    
    const tradeIds = Array.from(checkboxes).map(cb => cb.value);
    
    if (!confirm(`Calculate commission for ${tradeIds.length} trade(s)?`)) {
        return;
    }
    
    const btn = document.getElementById('calculateSelectedTradesBtn');
    const originalHtml = btn.innerHTML;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Calculating...';
    btn.disabled = true;
    
    fetch(`?ajax=calculate_commissions&agent_id=<?php echo $selected_agent_id; ?>&trade_ids=${tradeIds.join(',')}`)
        .then(response => response.json())
        .then(data => {
            if (data.error) {
                alert('Error: ' + data.error);
            } else {
                alert(data.message);
                // Refresh the trades list
                viewClientTrades(currentClientCds);
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

function calculateAllCommissions(agentId) {
    if (!confirm('Calculate commissions for all linked clients? This may take a moment.')) return;
    
    const btn = event.target;
    const originalHtml = btn.innerHTML;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Calculating...';
    btn.disabled = true;
    
    fetch(`?ajax=calculate_all_commissions&agent_id=${agentId}`)
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

// =====================================================
// COMMISSION FUNCTIONS
// =====================================================

function toggleAllCommissions() {
    const selectAll = document.getElementById('selectAllCommissions');
    const checkboxes = document.querySelectorAll('.commission-checkbox');
    checkboxes.forEach(cb => cb.checked = selectAll.checked);
    updateSelectedCommissionSummary();
}

function updateSelectedCommissionSummary() {
    const checkboxes = document.querySelectorAll('.commission-checkbox:checked');
    const count = checkboxes.length;
    let total = 0;
    let ids = [];
    
    checkboxes.forEach(cb => {
        const row = cb.closest('tr');
        const cells = row.querySelectorAll('td');
        if (cells.length >= 8) {
            const amountText = cells[7].textContent.replace('Tsh ', '').replace(/,/g, '');
            total += parseFloat(amountText) || 0;
        }
        ids.push(cb.value);
    });
    
    const countEl = document.getElementById('selectedCommissionCount');
    const totalEl = document.getElementById('selectedCommissionTotal');
    const idsContainer = document.getElementById('selectedCommissionIds');
    
    if (countEl) countEl.textContent = count;
    if (totalEl) totalEl.textContent = 'Tsh ' + total.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    if (idsContainer) {
        idsContainer.innerHTML = ids.map(id => 
            `<input type="hidden" name="commission_ids[]" value="${id}">`
        ).join('');
    }
}

// =====================================================
// UTILITY FUNCTIONS
// =====================================================

function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Auto-dismiss alerts
document.querySelectorAll('.alert').forEach(el => {
    setTimeout(() => {
        const bsAlert = bootstrap.Alert.getOrCreateInstance(el);
        if (bsAlert) bsAlert.close();
    }, 5000);
});
</script>

<?php include '../includes/footer.php'; ?>
