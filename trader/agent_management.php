<?php
/**
 * Agent Management System - COMPLETE FIXED VERSION
 * Location: /finance/agent_management.php
 * Access: Finance Officers, Operations, and Traders
 */

require_once '../config/config.php';
require_once '../auth/auth_middleware.php';

// Security headers
header("X-Frame-Options: DENY");
header("X-Content-Type-Options: nosniff");
header("X-XSS-Protection: 1; mode=block");

// =====================================================
// ACCESS CONTROL
// =====================================================

require_login();

$user_role = $_SESSION['role'] ?? '';
$finance_roles = ['finance_officer', 'system_admin'];
$allowed_roles = ['finance_officer', 'system_admin', 'operations', 'trader'];

if (!in_array($user_role, $allowed_roles)) {
    show_alert('Access denied. You do not have permission to access this page.', 'danger');
    redirect('dashboard.php');
    exit;
}

$can_pay_commissions = in_array($user_role, $finance_roles);
$can_manage = true;

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
// DATABASE SETUP
// =====================================================

try {
    // 1. Agents table with full KYC and bank details
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
        bank_name VARCHAR(255),
        bank_account_number VARCHAR(50),
        bank_account_name VARCHAR(255),
        bank_branch VARCHAR(100),
        bank_swift_code VARCHAR(50),
        contract_file VARCHAR(255),
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
    
    // 2. Agent clients linking table
    $db->exec("CREATE TABLE IF NOT EXISTS agent_clients (
        id INT AUTO_INCREMENT PRIMARY KEY,
        agent_id INT NOT NULL,
        client_cds_account VARCHAR(50) NOT NULL,
        client_name VARCHAR(255),
        linked_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        linked_by VARCHAR(100),
        status ENUM('active', 'inactive') DEFAULT 'active',
        UNIQUE KEY unique_client (client_cds_account),
        INDEX idx_agent_id (agent_id),
        INDEX idx_client_cds (client_cds_account),
        FOREIGN KEY (agent_id) REFERENCES agents(id) ON DELETE CASCADE
    )");
    
    // 3. Agent trades selection table
    $db->exec("CREATE TABLE IF NOT EXISTS agent_trades_selection (
        id INT AUTO_INCREMENT PRIMARY KEY,
        agent_id INT NOT NULL,
        trade_id INT NOT NULL,
        selected_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        selected_by VARCHAR(100),
        UNIQUE KEY unique_trade (trade_id),
        INDEX idx_agent_id (agent_id),
        INDEX idx_trade_id (trade_id),
        FOREIGN KEY (agent_id) REFERENCES agents(id) ON DELETE CASCADE
    )");
    
    // 4. Agent commission ledger
    $db->exec("CREATE TABLE IF NOT EXISTS agent_commission_ledger (
        id INT AUTO_INCREMENT PRIMARY KEY,
        agent_id INT NOT NULL,
        trade_id INT NOT NULL,
        trade_reference VARCHAR(50),
        client_cds_account VARCHAR(50),
        trade_date DATE,
        trade_consideration DECIMAL(15,2),
        brokerage_commission DECIMAL(15,2),
        agent_commission DECIMAL(15,2),
        commission_rate DECIMAL(5,4),
        is_first_trade TINYINT(1) DEFAULT 0,
        is_paid TINYINT(1) DEFAULT 0,
        payment_id INT DEFAULT NULL,
        payment_no VARCHAR(50) DEFAULT NULL,
        paid_at DATETIME DEFAULT NULL,
        calculation_date DATETIME DEFAULT CURRENT_TIMESTAMP,
        notes TEXT,
        INDEX idx_agent_id (agent_id),
        INDEX idx_trade_id (trade_id),
        INDEX idx_is_paid (is_paid),
        INDEX idx_payment_id (payment_id)
    )");
    
    // 5. Agent payments table
    $db->exec("CREATE TABLE IF NOT EXISTS agent_payments (
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
    
    // 6. Add columns to clients table
    try {
        $check = $db->query("SHOW COLUMNS FROM clients LIKE 'agent_id'");
        if ($check->rowCount() == 0) {
            $db->exec("ALTER TABLE clients ADD COLUMN agent_id INT NULL AFTER is_active");
            $db->exec("ALTER TABLE clients ADD COLUMN linked_to_agent TINYINT(1) DEFAULT 0 AFTER agent_id");
            $db->exec("ALTER TABLE clients ADD INDEX idx_agent_id (agent_id)");
            $db->exec("ALTER TABLE clients ADD INDEX idx_linked_to_agent (linked_to_agent)");
        }
    } catch (Exception $e) {
        error_log("Clients table migration warning: " . $e->getMessage());
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

// =====================================================
// COMMISSION CALCULATION FUNCTIONS
// =====================================================

function calculateBrokerageCommission($trade) {
    $is_bond = ($trade['asset_class'] === 'bond' || $trade['asset_class'] === 'treasury_bond');
    $consideration = floatval($trade['consideration']);
    $quantity = floatval($trade['quantity']);
    $face_value = $quantity;
    $is_liberty = ($trade['brokerage_fee_type'] === 'liberty' || $trade['brokerage_fee_type'] === 'this_trade');
    $liberty_rate = floatval($trade['custom_brokerage_fee'] ?? 0);
    $liberty_mode = $trade['liberty_mode'] ?? 'replace_all';
    
    if ($is_bond) {
        if ($is_liberty && $liberty_rate > 0) {
            if ($liberty_mode === 'excess_only') {
                $brokerage_first_100m = min($face_value, 100000000) * (0.063132 / 100);
                $brokerage_excess = max($face_value - 100000000, 0) * ($liberty_rate / 100);
                return $brokerage_first_100m + $brokerage_excess;
            } else {
                return $face_value * ($liberty_rate / 100);
            }
        } else {
            $brokerage_first_100m = min($face_value, 100000000) * (0.063132 / 100);
            $brokerage_excess = max($face_value - 100000000, 0) * (0.035 / 100);
            return $brokerage_first_100m + $brokerage_excess;
        }
    } else {
        // Equity/ETF
        if ($is_liberty && $liberty_rate > 0) {
            if ($liberty_mode === 'tier_override') {
                $standard_rate = 1.7000;
                if ($consideration <= 10000000) {
                    return $consideration * ($standard_rate / 100);
                } else {
                    $first_tier = 10000000 * ($standard_rate / 100);
                    $excess = ($consideration - 10000000) * ($liberty_rate / 100);
                    return $first_tier + $excess;
                }
            } else {
                return $consideration * ($liberty_rate / 100);
            }
        } else {
            // Standard tiered
            if ($consideration <= 10000000) {
                return $consideration * (1.7000 / 100);
            } elseif ($consideration <= 50000000) {
                return 10000000 * (1.7000 / 100) + ($consideration - 10000000) * (1.5000 / 100);
            } else {
                return 10000000 * (1.7000 / 100) + 40000000 * (1.5000 / 100) + ($consideration - 50000000) * (0.8000 / 100);
            }
        }
    }
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

function getAgentUnpaidBalance($db, $agent_id) {
    $stmt = $db->prepare("
        SELECT COALESCE(SUM(agent_commission), 0) as total_due
        FROM agent_commission_ledger
        WHERE agent_id = ? AND is_paid = 0
    ");
    $stmt->execute([$agent_id]);
    $result = $stmt->fetch();
    return $result['total_due'] ?? 0;
}

function getAgentLedgerSummary($db, $agent_id) {
    $stmt = $db->prepare("
        SELECT 
            COUNT(*) as total_trades,
            SUM(CASE WHEN is_first_trade = 1 THEN 1 ELSE 0 END) as first_trades,
            SUM(CASE WHEN is_first_trade = 0 THEN 1 ELSE 0 END) as regular_trades,
            SUM(CASE WHEN is_paid = 0 THEN agent_commission ELSE 0 END) as due_balance,
            SUM(CASE WHEN is_paid = 1 THEN agent_commission ELSE 0 END) as paid_balance,
            SUM(agent_commission) as total_commission
        FROM agent_commission_ledger
        WHERE agent_id = ?
    ");
    $stmt->execute([$agent_id]);
    return $stmt->fetch();
}

// =====================================================
// PROCESS AGENT COMMISSION FOR SELECTED TRADES
// =====================================================

function processSelectedTradesCommission($db, $agent_id, $trade_ids) {
    try {
        $db->beginTransaction();
        
        $calculated = 0;
        $errors = [];
        
        foreach ($trade_ids as $trade_id) {
            // Get trade details
            $stmt = $db->prepare("
                SELECT t.*, c.linked_to_agent, c.agent_id as client_agent_id
                FROM trades t
                INNER JOIN clients c ON t.client_cds_account = c.cds_account
                WHERE t.id = ? AND t.status = 'active'
            ");
            $stmt->execute([$trade_id]);
            $trade = $stmt->fetch();
            
            if (!$trade) {
                $errors[] = "Trade $trade_id not found";
                continue;
            }
            
            // Verify client is linked to this agent
            if ($trade['client_agent_id'] != $agent_id || $trade['linked_to_agent'] != 1) {
                $errors[] = "Client {$trade['client_cds_account']} is not linked to agent $agent_id";
                continue;
            }
            
            // Check if already in ledger
            $stmt = $db->prepare("SELECT id FROM agent_commission_ledger WHERE trade_id = ? AND agent_id = ?");
            $stmt->execute([$trade_id, $agent_id]);
            if ($stmt->fetch()) {
                $errors[] = "Trade $trade_id already has commission calculated";
                continue;
            }
            
            // Check if this is the first trade for this agent
            $stmt = $db->prepare("
                SELECT COUNT(*) as count 
                FROM agent_commission_ledger 
                WHERE agent_id = ? AND is_paid = 0
            ");
            $stmt->execute([$agent_id]);
            $existing = $stmt->fetch();
            $is_first_trade = ($existing['count'] == 0);
            
            // Get agent commission rate
            $rate = getAgentCommissionRate($db, $agent_id, $is_first_trade);
            
            // Calculate brokerage commission
            $brokerage_commission = calculateBrokerageCommission($trade);
            $agent_commission = $brokerage_commission * $rate;
            
            // Save to ledger
            $stmt = $db->prepare("
                INSERT INTO agent_commission_ledger (
                    agent_id, trade_id, trade_reference, client_cds_account,
                    trade_date, trade_consideration, brokerage_commission,
                    agent_commission, commission_rate, is_first_trade, is_paid
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0)
            ");
            $stmt->execute([
                $agent_id,
                $trade_id,
                $trade['trade_reference'],
                $trade['client_cds_account'],
                $trade['trade_date'],
                $trade['consideration'],
                $brokerage_commission,
                $agent_commission,
                $rate,
                $is_first_trade ? 1 : 0
            ]);
            
            // Add to agent_trades_selection
            $stmt = $db->prepare("
                INSERT IGNORE INTO agent_trades_selection (agent_id, trade_id, selected_by)
                VALUES (?, ?, ?)
            ");
            $stmt->execute([$agent_id, $trade_id, $_SESSION['username'] ?? 'system']);
            
            $calculated++;
        }
        
        $db->commit();
        
        return [
            'success' => true,
            'calculated' => $calculated,
            'errors' => $errors
        ];
        
    } catch (Exception $e) {
        $db->rollBack();
        error_log("Commission calculation error: " . $e->getMessage());
        return [
            'success' => false,
            'error' => $e->getMessage()
        ];
    }
}

// =====================================================
// PROCESS PAYMENT WITH GENERAL LEDGER INTEGRATION
// =====================================================

function processAgentPayment($db, $data) {
    if (!in_array($_SESSION['role'] ?? '', ['finance_officer', 'system_admin'])) {
        throw new Exception("Only finance officers can process commission payments");
    }
    
    try {
        $db->beginTransaction();
        
        $payment_no = 'AGTPMT' . date('Ymd') . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
        
        // Create payment record
        $stmt = $db->prepare("
            INSERT INTO agent_payments (
                payment_no, agent_id, agent_name, total_amount,
                commission_ids, trade_count, first_trade_count, regular_trade_count,
                payment_date, payment_mode, bank_account_id, bank_name,
                bank_account_number, transaction_reference, notes, status,
                created_by
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'paid', ?)
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
        
        // Update ledger entries as paid
        $ids = explode(',', $data['commission_ids']);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $db->prepare("
            UPDATE agent_commission_ledger 
            SET is_paid = 1, 
                payment_id = ?,
                payment_no = ?,
                paid_at = NOW()
            WHERE id IN ($placeholders)
        ");
        $stmt->execute(array_merge([$payment_id, $payment_no], $ids));
        
        // =====================================================
        // CREATE JOURNAL ENTRY - INTEGRATE WITH GENERAL LEDGER
        // =====================================================
        
        $bank_account = getBankAccountDetails($db, $data['bank_account_id']);
        $agent_name = $data['agent_name'];
        $total_amount = $data['total_amount'];
        $payment_date = $data['payment_date'];
        $user_id = $_SESSION['user_id'] ?? null;
        $username = $_SESSION['username'] ?? 'system';
        
        // Create Journal Entry
        $journal_no = generateJournalNo($db);
        $fiscal_year = date('Y', strtotime($payment_date));
        $fiscal_period = date('m', strtotime($payment_date));
        
        // Get the agent commission payable account (2127)
        $payable_account = getValidAccountCode($db, '2127', '2110');
        $bank_account_code = $bank_account['code'] ?? getValidAccountCode($db, '1110', '1110');
        
        // Journal Entry 1: DR - Agent Commission Payable
        $journal1 = createJournalEntry($db, [
            'transaction_date' => $payment_date,
            'reference_no' => $payment_no,
            'reference_type' => 'agent_commission',
            'description' => "Agent Commission Payment - $agent_name ($payment_no)",
            'account_code' => $payable_account,
            'debit_amount' => $total_amount,
            'credit_amount' => 0,
            'currency' => 'Tsh',
            'entity_id' => $data['agent_id'],
            'entity_name' => $agent_name,
            'entity_type' => 'agent',
            'bank_account_id' => $data['bank_account_id'],
            'bank_name' => $bank_account['bank_name'] ?? '',
            'bank_account_number' => $bank_account['account_number'] ?? ''
        ]);
        
        // Journal Entry 2: CR - Bank Account
        $journal2 = createJournalEntry($db, [
            'transaction_date' => $payment_date,
            'reference_no' => $payment_no,
            'reference_type' => 'agent_commission',
            'description' => "Agent Commission Payment - $agent_name ($payment_no)",
            'account_code' => $bank_account_code,
            'debit_amount' => 0,
            'credit_amount' => $total_amount,
            'currency' => 'Tsh',
            'entity_id' => $data['agent_id'],
            'entity_name' => $agent_name,
            'entity_type' => 'agent',
            'bank_account_id' => $data['bank_account_id'],
            'bank_name' => $bank_account['bank_name'] ?? '',
            'bank_account_number' => $bank_account['account_number'] ?? ''
        ]);
        
        // Update bank balance
        updateBankBalance($db, $data['bank_account_id'], $total_amount);
        
        $db->commit();
        
        return [
            'success' => true,
            'payment_no' => $payment_no,
            'payment_id' => $payment_id,
            'journal_no' => $journal1
        ];
        
    } catch (Exception $e) {
        $db->rollBack();
        error_log("Agent payment error: " . $e->getMessage());
        throw $e;
    }
}

// =====================================================
// AJAX HANDLERS
// =====================================================

if (isset($_GET['ajax']) && $_GET['ajax'] == 'get_unlinked_clients') {
    header('Content-Type: application/json');
    try {
        $search = isset($_GET['search']) ? '%' . $_GET['search'] . '%' : '%';
        
        $sql = "
            SELECT cds_account, client_name 
            FROM clients 
            WHERE status = 'active' 
            AND is_active = 1 
            AND (linked_to_agent = 0 OR linked_to_agent IS NULL)
            AND (agent_id IS NULL)
            AND (client_name LIKE ? OR cds_account LIKE ?)
            ORDER BY client_name
            LIMIT 50
        ";
        
        $stmt = $db->prepare($sql);
        $stmt->execute([$search, $search]);
        $clients = $stmt->fetchAll();
        
        echo json_encode(['success' => true, 'clients' => $clients]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

if (isset($_GET['ajax']) && $_GET['ajax'] == 'get_client_trades') {
    header('Content-Type: application/json');
    $cds_account = $_GET['cds_account'] ?? '';
    $agent_id = isset($_GET['agent_id']) ? (int)$_GET['agent_id'] : 0;
    
    try {
        // Get all trades for this client
        $sql = "
            SELECT id, trade_reference, trade_date, security_id, trade_side, 
                   quantity, price, consideration, final_brokerage_fee,
                   asset_class, brokerage_fee_type, custom_brokerage_fee, liberty_mode
            FROM trades 
            WHERE client_cds_account = ? 
            AND status = 'active'
            AND (agent_commission_calculated = 0 OR agent_commission_calculated IS NULL)
            ORDER BY trade_date DESC
        ";
        
        $stmt = $db->prepare($sql);
        $stmt->execute([$cds_account]);
        $trades = $stmt->fetchAll();
        
        // Check which ones are already selected for this agent
        if ($agent_id > 0) {
            $selected_ids = [];
            $stmt = $db->prepare("SELECT trade_id FROM agent_trades_selection WHERE agent_id = ?");
            $stmt->execute([$agent_id]);
            while ($row = $stmt->fetch()) {
                $selected_ids[] = $row['trade_id'];
            }
            
            foreach ($trades as &$trade) {
                $trade['is_selected'] = in_array($trade['id'], $selected_ids);
            }
        }
        
        echo json_encode(['success' => true, 'trades' => $trades]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

if (isset($_GET['ajax']) && $_GET['ajax'] == 'get_agent_ledger') {
    header('Content-Type: application/json');
    $agent_id = (int)$_GET['agent_id'] ?? 0;
    
    try {
        $stmt = $db->prepare("
            SELECT * FROM agent_commission_ledger 
            WHERE agent_id = ? 
            ORDER BY trade_date DESC
        ");
        $stmt->execute([$agent_id]);
        $ledger = $stmt->fetchAll();
        echo json_encode(['success' => true, 'ledger' => $ledger]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

if (isset($_GET['ajax']) && $_GET['ajax'] == 'calculate_selected_trades') {
    header('Content-Type: application/json');
    $agent_id = (int)$_GET['agent_id'] ?? 0;
    $trade_ids = isset($_GET['trade_ids']) ? explode(',', $_GET['trade_ids']) : [];
    
    if (empty($trade_ids)) {
        echo json_encode(['success' => false, 'error' => 'No trades selected']);
        exit;
    }
    
    try {
        $result = processSelectedTradesCommission($db, $agent_id, $trade_ids);
        
        if ($result['success']) {
            $message = "Calculated commissions for {$result['calculated']} trade(s)";
            if (!empty($result['errors'])) {
                $message .= " (Errors: " . implode(', ', $result['errors']) . ")";
            }
            echo json_encode([
                'success' => true,
                'calculated' => $result['calculated'],
                'errors' => $result['errors'],
                'message' => $message
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'error' => $result['error'] ?? 'Failed to calculate commissions'
            ]);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

if (isset($_GET['ajax']) && $_GET['ajax'] == 'save_trade_selection') {
    header('Content-Type: application/json');
    $agent_id = (int)$_GET['agent_id'] ?? 0;
    $trade_ids = isset($_GET['trade_ids']) ? explode(',', $_GET['trade_ids']) : [];
    $action = $_GET['action'] ?? 'add';
    
    try {
        $db->beginTransaction();
        
        if ($action === 'remove') {
            $placeholders = implode(',', array_fill(0, count($trade_ids), '?'));
            $stmt = $db->prepare("DELETE FROM agent_trades_selection WHERE agent_id = ? AND trade_id IN ($placeholders)");
            $stmt->execute(array_merge([$agent_id], $trade_ids));
        } else {
            foreach ($trade_ids as $trade_id) {
                $stmt = $db->prepare("
                    INSERT IGNORE INTO agent_trades_selection (agent_id, trade_id, selected_by)
                    VALUES (?, ?, ?)
                ");
                $stmt->execute([$agent_id, $trade_id, $_SESSION['username'] ?? 'system']);
            }
        }
        
        $db->commit();
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        $db->rollBack();
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// =====================================================
// POST HANDLING
// =====================================================

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
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
            $bank_name = trim($_POST['bank_name'] ?? '');
            $bank_account_number = trim($_POST['bank_account_number'] ?? '');
            $bank_account_name = trim($_POST['bank_account_name'] ?? '');
            $bank_branch = trim($_POST['bank_branch'] ?? '');
            $bank_swift_code = trim($_POST['bank_swift_code'] ?? '');
            $commission_rate = (float)($_POST['commission_rate'] ?? 0.1000);
            $first_trade_commission_rate = (float)($_POST['first_trade_commission_rate'] ?? 0.2500);
            $notes = trim($_POST['notes'] ?? '');
            $status = $_POST['status'] ?? 'active';
            $contract_file = '';
            
            // Handle contract file upload
            if (isset($_FILES['contract_file']) && $_FILES['contract_file']['error'] == UPLOAD_ERR_OK) {
                $upload_dir = __DIR__ . '/../uploads/agent_contracts/';
                if (!file_exists($upload_dir)) {
                    mkdir($upload_dir, 0777, true);
                }
                
                $allowed_exts = ['pdf', 'doc', 'docx'];
                $file_ext = strtolower(pathinfo($_FILES['contract_file']['name'], PATHINFO_EXTENSION));
                if (in_array($file_ext, $allowed_exts) && $_FILES['contract_file']['size'] <= 10 * 1024 * 1024) {
                    $contract_file = 'contract_' . time() . '_' . preg_replace('/[^a-zA-Z0-9_\-\.]/', '', $_FILES['contract_file']['name']);
                    $file_path = $upload_dir . $contract_file;
                    if (move_uploaded_file($_FILES['contract_file']['tmp_name'], $file_path)) {
                        // Success
                    }
                }
            }
            
            if (empty($name)) {
                $error_message = "Agent name is required.";
            } else {
                try {
                    if ($agent_id > 0) {
                        // Update existing agent
                        $sql = "
                            UPDATE agents SET
                                name = ?, contact_person = ?, phone = ?, email = ?,
                                address = ?, city = ?, country = ?, id_number = ?,
                                id_type = ?, tin = ?, bank_name = ?, 
                                bank_account_number = ?, bank_account_name = ?,
                                bank_branch = ?, bank_swift_code = ?,
                                commission_rate = ?, first_trade_commission_rate = ?,
                                notes = ?, status = ?
                        ";
                        $params = [
                            $name, $contact_person, $phone, $email,
                            $address, $city, $country, $id_number,
                            $id_type, $tin, $bank_name,
                            $bank_account_number, $bank_account_name,
                            $bank_branch, $bank_swift_code,
                            $commission_rate, $first_trade_commission_rate,
                            $notes, $status
                        ];
                        
                        if (!empty($contract_file)) {
                            $sql .= ", contract_file = ?";
                            $params[] = $contract_file;
                        }
                        
                        $sql .= " WHERE id = ?";
                        $params[] = $agent_id;
                        
                        $stmt = $db->prepare($sql);
                        $stmt->execute($params);
                        $success_message = "Agent updated successfully!";
                    } else {
                        // Create new agent
                        $agent_code = generateAgentCode($db);
                        $stmt = $db->prepare("
                            INSERT INTO agents (
                                agent_code, name, contact_person, phone, email,
                                address, city, country, id_number, id_type,
                                tin, bank_name, bank_account_number, bank_account_name,
                                bank_branch, bank_swift_code, commission_rate,
                                first_trade_commission_rate, notes, status, 
                                contract_file, created_by
                            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                        ");
                        $stmt->execute([
                            $agent_code, $name, $contact_person, $phone, $email,
                            $address, $city, $country, $id_number, $id_type,
                            $tin, $bank_name, $bank_account_number, $bank_account_name,
                            $bank_branch, $bank_swift_code, $commission_rate,
                            $first_trade_commission_rate, $notes, $status,
                            $contract_file, $_SESSION['username'] ?? 'system'
                        ]);
                        $success_message = "Agent created successfully! Agent Code: $agent_code";
                    }
                    
                    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                } catch (Exception $e) {
                    $error_message = "Error saving agent: " . $e->getMessage();
                }
            }
        }
        
        // ============ LINK CLIENT ============
        if (isset($_POST['link_client'])) {
            $agent_id = (int)$_POST['agent_id'] ?? 0;
            $client_cds = $_POST['client_cds'] ?? '';
            
            if (empty($agent_id) || empty($client_cds)) {
                $error_message = "Please select both agent and client.";
            } else {
                try {
                    $db->beginTransaction();
                    
                    // Check if client is already linked
                    $stmt = $db->prepare("SELECT linked_to_agent, agent_id FROM clients WHERE cds_account = ?");
                    $stmt->execute([$client_cds]);
                    $client = $stmt->fetch();
                    
                    if ($client && $client['linked_to_agent'] == 1) {
                        $error_message = "Client is already linked to another agent.";
                        $db->rollBack();
                    } else {
                        // Update client
                        $stmt = $db->prepare("
                            UPDATE clients 
                            SET linked_to_agent = 1, 
                                agent_id = ?,
                                updated_at = NOW()
                            WHERE cds_account = ?
                        ");
                        $stmt->execute([$agent_id, $client_cds]);
                        
                        // Get client name
                        $stmt = $db->prepare("SELECT client_name FROM clients WHERE cds_account = ?");
                        $stmt->execute([$client_cds]);
                        $client_name = $stmt->fetchColumn();
                        
                        // Insert into agent_clients
                        $stmt = $db->prepare("
                            INSERT INTO agent_clients (agent_id, client_cds_account, client_name, linked_by)
                            VALUES (?, ?, ?, ?)
                        ");
                        $stmt->execute([
                            $agent_id,
                            $client_cds,
                            $client_name,
                            $_SESSION['username'] ?? 'system'
                        ]);
                        
                        $db->commit();
                        $success_message = "Client linked successfully!";
                        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                    }
                } catch (Exception $e) {
                    $db->rollBack();
                    $error_message = "Error linking client: " . $e->getMessage();
                }
            }
        }
        
        // ============ UNLINK CLIENT ============
        if (isset($_POST['unlink_client'])) {
            $agent_id = (int)$_POST['agent_id'] ?? 0;
            $client_cds = $_POST['client_cds'] ?? '';
            
            try {
                $db->beginTransaction();
                
                // Update client
                $stmt = $db->prepare("
                    UPDATE clients 
                    SET linked_to_agent = 0, 
                        agent_id = NULL,
                        updated_at = NOW()
                    WHERE cds_account = ? AND agent_id = ?
                ");
                $stmt->execute([$client_cds, $agent_id]);
                
                // Update agent_clients
                $stmt = $db->prepare("
                    UPDATE agent_clients 
                    SET status = 'inactive'
                    WHERE agent_id = ? AND client_cds_account = ?
                ");
                $stmt->execute([$agent_id, $client_cds]);
                
                $db->commit();
                $success_message = "Client unlinked successfully!";
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            } catch (Exception $e) {
                $db->rollBack();
                $error_message = "Error unlinking client: " . $e->getMessage();
            }
        }
        
        // ============ PAY COMMISSIONS - FIXED ============
        if (isset($_POST['pay_commissions']) && $can_pay_commissions) {
            $agent_id = (int)$_POST['agent_id'] ?? 0;
            $commission_ids = isset($_POST['commission_ids']) ? $_POST['commission_ids'] : [];
            
            // Handle both array and single value
            if (is_array($commission_ids)) {
                $commission_ids = array_filter($commission_ids);
            } else {
                $commission_ids = array_filter([$commission_ids]);
            }
            
            $payment_date = $_POST['payment_date'] ?? date('Y-m-d');
            $payment_mode = (int)($_POST['payment_mode'] ?? 0);
            $bank_account_id = (int)($_POST['ac_credit'] ?? 0);
            $transaction_reference = trim($_POST['transaction_reference'] ?? '');
            $notes = trim($_POST['payment_notes'] ?? '');
            
            error_log("=== PAY COMMISSIONS DEBUG ===");
            error_log("Agent ID: $agent_id");
            error_log("Commission IDs: " . print_r($commission_ids, true));
            error_log("Bank Account ID: $bank_account_id");
            
            if (empty($commission_ids)) {
                $error_message = "Please select at least one commission to pay.";
            } elseif ($bank_account_id <= 0) {
                $error_message = "Please select a bank account.";
            } else {
                try {
                    // Get commission details
                    $placeholders = implode(',', array_fill(0, count($commission_ids), '?'));
                    $stmt = $db->prepare("
                        SELECT l.*, a.name as agent_name, a.bank_name as agent_bank_name,
                               a.bank_account_number as agent_bank_account,
                               a.bank_account_name as agent_account_name
                        FROM agent_commission_ledger l
                        INNER JOIN agents a ON l.agent_id = a.id
                        WHERE l.id IN ($placeholders)
                        AND l.is_paid = 0
                    ");
                    $stmt->execute($commission_ids);
                    $commissions = $stmt->fetchAll();
                    
                    error_log("Found " . count($commissions) . " commissions to pay");
                    
                    if (empty($commissions)) {
                        $error_message = "No unpaid commissions found to pay.";
                    } else {
                        $total_amount = array_sum(array_column($commissions, 'agent_commission'));
                        $trade_count = count($commissions);
                        $first_trade_count = count(array_filter($commissions, function($c) {
                            return $c['is_first_trade'] == 1;
                        }));
                        $regular_trade_count = $trade_count - $first_trade_count;
                        $commission_id_string = implode(',', $commission_ids);
                        $agent_name = $commissions[0]['agent_name'];
                        
                        // Get bank details
                        $stmt = $db->prepare("SELECT bank_name, account_number, code FROM banks_accounts WHERE id = ?");
                        $stmt->execute([$bank_account_id]);
                        $bank = $stmt->fetch();
                        
                        if (!$bank) {
                            $error_message = "Bank account not found.";
                        } else {
                            error_log("Total amount to pay: $total_amount");
                            
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
                            
                            $result = processAgentPayment($db, $payment_data);
                            
                            if ($result['success']) {
                                $success_message = "Commission payment processed successfully!<br>";
                                $success_message .= "Payment No: <strong>{$result['payment_no']}</strong><br>";
                                $success_message .= "Journal No: <strong>{$result['journal_no']}</strong><br>";
                                $success_message .= "Amount: <strong>Tsh " . number_format($total_amount, 2) . "</strong>";
                                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                            }
                        }
                    }
                } catch (Exception $e) {
                    error_log("Payment error: " . $e->getMessage());
                    $error_message = "Error processing payment: " . $e->getMessage();
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
$agent_ledger = [];
$agent_ledger_summary = null;
$unpaid_balance = 0;
$payment_methods = [];
$bank_accounts = [];
$selected_trade_ids = [];

if ($selected_agent_id > 0) {
    try {
        $stmt = $db->prepare("SELECT * FROM agents WHERE id = ?");
        $stmt->execute([$selected_agent_id]);
        $selected_agent = $stmt->fetch();
        
        if ($selected_agent) {
            // Get linked clients
            $stmt = $db->prepare("
                SELECT c.cds_account, c.client_name, c.linked_to_agent, c.agent_id,
                       (SELECT COUNT(*) FROM trades WHERE client_cds_account = c.cds_account AND status = 'active') as total_trades
                FROM clients c
                WHERE c.agent_id = ? AND c.linked_to_agent = 1
                ORDER BY c.client_name
            ");
            $stmt->execute([$selected_agent_id]);
            $agent_clients = $stmt->fetchAll();
            
            // Get ledger
            $stmt = $db->prepare("
                SELECT * FROM agent_commission_ledger 
                WHERE agent_id = ? 
                ORDER BY trade_date DESC
            ");
            $stmt->execute([$selected_agent_id]);
            $agent_ledger = $stmt->fetchAll();
            
            // Get summary
            $agent_ledger_summary = getAgentLedgerSummary($db, $selected_agent_id);
            $unpaid_balance = getAgentUnpaidBalance($db, $selected_agent_id);
            
            // Get selected trade IDs for this agent
            $stmt = $db->prepare("SELECT trade_id FROM agent_trades_selection WHERE agent_id = ?");
            $stmt->execute([$selected_agent_id]);
            $selected_trade_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            // Get payment methods
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

// Get unlinked clients for linking
$unlinked_clients = [];
try {
    $stmt = $db->query("
        SELECT cds_account, client_name 
        FROM clients 
        WHERE status = 'active' 
        AND is_active = 1 
        AND (linked_to_agent = 0 OR linked_to_agent IS NULL)
        AND (agent_id IS NULL)
        ORDER BY client_name
        LIMIT 100
    ");
    $unlinked_clients = $stmt->fetchAll();
} catch (Exception $e) {
    error_log("Error fetching unlinked clients: " . $e->getMessage());
}

$page_title = 'Agent Management';
include '../includes/header.php';
?>

<style>
/* Agent Management Styles */
.agent-stat {
    text-align: center;
    padding: 15px;
    background: #f8f9fa;
    border-radius: 8px;
    border: 1px solid #e9ecef;
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
.agent-stat .number.due { color: #dc3545; }
.agent-stat .number.paid { color: #28a745; }
.agent-stat .number.total { color: #0d6efd; }

.ledger-row.paid { background: #d4edda; }
.ledger-row.unpaid { background: #fff3cd; }
.ledger-row .badge-first { background: #ffc107; color: #212529; }
.ledger-row .badge-regular { background: #17a2b8; color: white; }
.ledger-row .badge-paid { background: #28a745; color: white; }
.ledger-row .badge-unpaid { background: #dc3545; color: white; }

.commission-details { font-size: 12px; margin-top: 4px; }
.commission-details .tier { padding: 2px 6px; background: #f1f3f5; border-radius: 3px; margin: 1px 0; }

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

.permission-badge {
    font-size: 10px;
    padding: 2px 8px;
    border-radius: 10px;
}
.permission-badge.finance { background: #cce5ff; color: #004085; }
.permission-badge.ops { background: #d4edda; color: #155724; }

.client-select-row {
    cursor: pointer;
    transition: background 0.2s;
}
.client-select-row:hover {
    background: #f0f4ff;
}
.client-select-row.selected {
    background: #d4edda;
}

.trade-select-row {
    cursor: pointer;
}
.trade-select-row.selected {
    background: #cce5ff;
}
.trade-select-row:hover {
    background: #f8f9fa;
}

/* Searchable dropdown for clients */
.client-search-wrapper {
    position: relative;
}
.client-search-wrapper .form-control {
    padding-right: 35px;
}
.client-search-wrapper .clear-search {
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
.client-search-wrapper .clear-search:hover {
    color: #dc3545;
}
.client-dropdown {
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
.client-dropdown .client-item {
    padding: 8px 12px;
    cursor: pointer;
    border-bottom: 1px solid #f0f0f0;
    transition: background 0.2s;
}
.client-dropdown .client-item:hover {
    background: #f0f4ff;
}
.client-dropdown .client-item .client-cds {
    font-size: 11px;
    color: #6c757d;
}
.client-dropdown .no-results {
    padding: 12px;
    text-align: center;
    color: #6c757d;
}
</style>

<div class="container-fluid">
    
    <!-- Access Notice -->
    <?php if ($can_pay_commissions): ?>
        <div class="alert alert-success">
            <i class="bi bi-check-circle me-2"></i>
            <strong>Full Access:</strong> You can manage agents, link clients, select trades, calculate commissions, and process payments.
            <span class="permission-badge finance">Finance Access</span>
        </div>
    <?php else: ?>
        <div class="alert alert-info">
            <i class="bi bi-info-circle me-2"></i>
            <strong>Management Access:</strong> You can manage agents, link clients, select trades, and calculate commissions.
            <span class="permission-badge ops">Ops Access</span>
            <span class="text-warning ms-2">Commission payments require Finance approval.</span>
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
                    <small class="text-muted">Manage agents, link clients, select trades, track commissions</small>
                </div>
                <div>
                    <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#agentModal">
                        <i class="bi bi-plus-circle me-1"></i>New Agent
                    </button>
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
                                <button class="btn btn-outline-success btn-sm" data-bs-toggle="modal" data-bs-target="#linkClientModal">
                                    <i class="bi bi-link-45deg me-1"></i>Link Client
                                </button>
                                <button class="btn btn-outline-info btn-sm" data-bs-toggle="modal" data-bs-target="#selectTradesModal">
                                    <i class="bi bi-check2-square me-1"></i>Select Trades
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
                        <?php if (!empty($selected_agent['phone'])): ?>
                            <div class="mt-2"><small><i class="bi bi-phone me-1"></i><?php echo htmlspecialchars($selected_agent['phone']); ?></small></div>
                        <?php endif; ?>
                        <?php if (!empty($selected_agent['email'])): ?>
                            <div><small><i class="bi bi-envelope me-1"></i><?php echo htmlspecialchars($selected_agent['email']); ?></small></div>
                        <?php endif; ?>
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
                    <div class="number"><?php echo number_format($agent_ledger_summary['total_trades'] ?? 0); ?></div>
                    <div class="label">Total Trades</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="agent-stat">
                    <div class="number due">Tsh <?php echo number_format($unpaid_balance, 2); ?></div>
                    <div class="label">Due Balance</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="agent-stat">
                    <div class="number paid">Tsh <?php echo number_format($agent_ledger_summary['paid_balance'] ?? 0, 2); ?></div>
                    <div class="label">Paid Commission</div>
                </div>
            </div>
        </div>

        <!-- Tabs -->
        <ul class="nav nav-tabs mb-3" id="agentTabs" role="tablist">
            <li class="nav-item">
                <button class="nav-link active" id="clients-tab" data-bs-toggle="tab" data-bs-target="#clients" type="button">
                    <i class="bi bi-people me-1"></i>Clients (<?php echo count($agent_clients); ?>)
                </button>
            </li>
            <li class="nav-item">
                <button class="nav-link" id="ledger-tab" data-bs-toggle="tab" data-bs-target="#ledger" type="button">
                    <i class="bi bi-book me-1"></i>Ledger (<?php echo count($agent_ledger); ?>)
                </button>
            </li>
            <li class="nav-item">
                <button class="nav-link" id="payments-tab" data-bs-toggle="tab" data-bs-target="#payments" type="button">
                    <i class="bi bi-cash-stack me-1"></i>Payments
                </button>
            </li>
            <li class="nav-item">
                <button class="nav-link" id="details-tab" data-bs-toggle="tab" data-bs-target="#details" type="button">
                    <i class="bi bi-info-circle me-1"></i>Agent Details
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
                                <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#linkClientModal">
                                    <i class="bi bi-link-45deg me-1"></i>Link Client
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
                                                <td><?php echo htmlspecialchars($client['client_name'] ?? ''); ?></td>
                                                <td><code><?php echo htmlspecialchars($client['cds_account'] ?? ''); ?></code></td>
                                                <td>
                                                    <?php 
                                                    $link_stmt = $db->prepare("SELECT linked_at FROM agent_clients WHERE client_cds_account = ? AND agent_id = ? AND status = 'active' LIMIT 1");
                                                    $link_stmt->execute([$client['cds_account'], $selected_agent_id]);
                                                    $link_data = $link_stmt->fetch();
                                                    echo date('d/m/Y H:i', strtotime($link_data['linked_at'] ?? 'now')); 
                                                    ?>
                                                </td>
                                                <td>
                                                    <?php echo $client['total_trades'] ?? 0; ?>
                                                    <button class="btn btn-outline-info btn-sm ms-1" onclick="viewClientTrades('<?php echo htmlspecialchars($client['cds_account']); ?>')" title="View Trades">
                                                        <i class="bi bi-eye"></i>
                                                    </button>
                                                </td>
                                                <td>
                                                    <form method="POST" style="display:inline;" onsubmit="return confirm('Unlink this client?')">
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

            <!-- Ledger Tab -->
            <div class="tab-pane fade" id="ledger" role="tabpanel">
                <div class="card">
                    <div class="card-body">
                        <div class="row mb-3">
                            <div class="col-md-8">
                                <small class="text-muted">
                                    <span class="badge bg-warning me-2">Pending</span> = Unpaid
                                    <span class="badge bg-success ms-3 me-2">Paid</span> = Paid
                                    <span class="badge bg-info ms-3 me-2">First Trade</span> = 25% Commission
                                    <span class="badge bg-secondary ms-3 me-2">Regular</span> = 10% Commission
                                </small>
                            </div>
                            <div class="col-md-4 text-end">
                                <button class="btn btn-warning btn-sm" onclick="calculateSelectedTrades()">
                                    <i class="bi bi-calculator me-1"></i>Calculate Selected
                                </button>
                            </div>
                        </div>

                        <?php if (empty($agent_ledger)): ?>
                            <div class="text-center py-4">
                                <i class="bi bi-book" style="font-size: 48px; color: #dee2e6;"></i>
                                <p class="text-muted mt-2">No commission entries yet.</p>
                                <button class="btn btn-warning btn-sm" onclick="calculateSelectedTrades()">
                                    <i class="bi bi-calculator me-1"></i>Calculate Selected Trades
                                </button>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-sm table-hover">
                                    <thead>
                                        <tr>
                                            <th>Trade Ref</th>
                                            <th>Client</th>
                                            <th>Date</th>
                                            <th class="text-end">Consideration</th>
                                            <th class="text-end">Brokerage</th>
                                            <th class="text-end">Rate</th>
                                            <th class="text-end">Commission</th>
                                            <th>Type</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($agent_ledger as $entry): ?>
                                            <tr class="ledger-row <?php echo $entry['is_paid'] ? 'paid' : 'unpaid'; ?>">
                                                <td><code><?php echo htmlspecialchars($entry['trade_reference']); ?></code></td>
                                                <td><?php echo htmlspecialchars($entry['client_cds_account']); ?></td>
                                                <td><?php echo date('d/m/Y', strtotime($entry['trade_date'])); ?></td>
                                                <td class="text-end">Tsh <?php echo number_format($entry['trade_consideration'], 2); ?></td>
                                                <td class="text-end">Tsh <?php echo number_format($entry['brokerage_commission'], 2); ?></td>
                                                <td class="text-end"><?php echo number_format($entry['commission_rate'] * 100, 2); ?>%</td>
                                                <td class="text-end fw-bold">Tsh <?php echo number_format($entry['agent_commission'], 2); ?></td>
                                                <td>
                                                    <?php if ($entry['is_first_trade']): ?>
                                                        <span class="badge badge-first">First Trade</span>
                                                    <?php else: ?>
                                                        <span class="badge badge-regular">Regular</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if ($entry['is_paid']): ?>
                                                        <span class="badge badge-paid">
                                                            Paid
                                                            <?php if (!empty($entry['payment_no'])): ?>
                                                                <br><small><?php echo htmlspecialchars($entry['payment_no']); ?></small>
                                                            <?php endif; ?>
                                                        </span>
                                                    <?php else: ?>
                                                        <span class="badge badge-unpaid">Pending</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                    <tfoot>
                                        <tr>
                                            <td colspan="6" class="text-end fw-bold">Total Due:</td>
                                            <td class="text-end fw-bold text-danger">Tsh <?php echo number_format($unpaid_balance, 2); ?></td>
                                            <td colspan="2"></td>
                                        </tr>
                                        <tr>
                                            <td colspan="6" class="text-end fw-bold">Total Paid:</td>
                                            <td class="text-end fw-bold text-success">Tsh <?php echo number_format($agent_ledger_summary['paid_balance'] ?? 0, 2); ?></td>
                                            <td colspan="2"></td>
                                        </tr>
                                        <tr>
                                            <td colspan="6" class="text-end fw-bold">Total Commission:</td>
                                            <td class="text-end fw-bold">Tsh <?php echo number_format($agent_ledger_summary['total_commission'] ?? 0, 2); ?></td>
                                            <td colspan="2"></td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                            
                            <?php if (!$can_pay_commissions): ?>
                                <div class="alert alert-info mt-3">
                                    <i class="bi bi-info-circle me-2"></i>
                                    Commission payments can only be processed by Finance Officers.
                                </div>
                            <?php elseif ($unpaid_balance > 0): ?>
                                <div class="mt-3">
                                    <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#payCommissionsModal">
                                        <i class="bi bi-cash-coin me-1"></i>Pay Selected Commissions
                                    </button>
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Payments Tab -->
            <div class="tab-pane fade" id="payments" role="tabpanel">
                <div class="card">
                    <div class="card-body">
                        <?php
                        $stmt = $db->prepare("SELECT * FROM agent_payments WHERE agent_id = ? ORDER BY created_at DESC");
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
                                            <th>Transaction Ref</th>
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
                                                    <span class="badge badge-first"><?php echo $payment['first_trade_count']; ?></span>
                                                    <span class="badge badge-regular"><?php echo $payment['regular_trade_count']; ?></span>
                                                </td>
                                                <td>
                                                    <span class="badge bg-<?php echo $payment['status'] === 'paid' ? 'success' : 'warning'; ?>">
                                                        <?php echo ucfirst($payment['status']); ?>
                                                    </span>
                                                </td>
                                                <td><?php echo htmlspecialchars($payment['bank_name']); ?></td>
                                                <td><?php echo htmlspecialchars($payment['transaction_reference'] ?? '-'); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                    <tfoot>
                                        <tr>
                                            <td colspan="2" class="text-end fw-bold">Total Paid:</td>
                                            <td class="fw-bold text-success">
                                                Tsh <?php echo number_format(array_sum(array_column($payments, 'total_amount')), 2); ?>
                                            </td>
                                            <td colspan="5"></td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Agent Details Tab -->
            <div class="tab-pane fade" id="details" role="tabpanel">
                <div class="card">
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <h6 class="fw-bold"><i class="bi bi-person me-2"></i>Personal Information</h6>
                                <table class="table table-sm">
                                    <tr><td><strong>Name:</strong></td><td><?php echo htmlspecialchars($selected_agent['name']); ?></td></tr>
                                    <tr><td><strong>Contact Person:</strong></td><td><?php echo htmlspecialchars($selected_agent['contact_person'] ?? '-'); ?></td></tr>
                                    <tr><td><strong>Phone:</strong></td><td><?php echo htmlspecialchars($selected_agent['phone'] ?? '-'); ?></td></tr>
                                    <tr><td><strong>Email:</strong></td><td><?php echo htmlspecialchars($selected_agent['email'] ?? '-'); ?></td></tr>
                                    <tr><td><strong>Address:</strong></td><td><?php echo htmlspecialchars($selected_agent['address'] ?? '-'); ?></td></tr>
                                    <tr><td><strong>ID Type:</strong></td><td><?php echo ucfirst(str_replace('_', ' ', $selected_agent['id_type'] ?? '-')); ?></td></tr>
                                    <tr><td><strong>ID Number:</strong></td><td><?php echo htmlspecialchars($selected_agent['id_number'] ?? '-'); ?></td></tr>
                                    <tr><td><strong>TIN:</strong></td><td><?php echo htmlspecialchars($selected_agent['tin'] ?? '-'); ?></td></tr>
                                </table>
                            </div>
                            <div class="col-md-6">
                                <h6 class="fw-bold"><i class="bi bi-bank me-2"></i>Bank Details</h6>
                                <table class="table table-sm">
                                    <tr><td><strong>Bank Name:</strong></td><td><?php echo htmlspecialchars($selected_agent['bank_name'] ?? '-'); ?></td></tr>
                                    <tr><td><strong>Account Name:</strong></td><td><?php echo htmlspecialchars($selected_agent['bank_account_name'] ?? '-'); ?></td></tr>
                                    <tr><td><strong>Account Number:</strong></td><td><?php echo htmlspecialchars($selected_agent['bank_account_number'] ?? '-'); ?></td></tr>
                                    <tr><td><strong>Branch:</strong></td><td><?php echo htmlspecialchars($selected_agent['bank_branch'] ?? '-'); ?></td></tr>
                                    <tr><td><strong>SWIFT Code:</strong></td><td><?php echo htmlspecialchars($selected_agent['bank_swift_code'] ?? '-'); ?></td></tr>
                                </table>
                                
                                <h6 class="fw-bold mt-3"><i class="bi bi-percent me-2"></i>Commission Rates</h6>
                                <table class="table table-sm">
                                    <tr><td><strong>First Trade Commission:</strong></td><td><?php echo number_format($selected_agent['first_trade_commission_rate'] * 100, 2); ?>%</td></tr>
                                    <tr><td><strong>Regular Commission:</strong></td><td><?php echo number_format($selected_agent['commission_rate'] * 100, 2); ?>%</td></tr>
                                </table>
                                
                                <?php if (!empty($selected_agent['contract_file'])): ?>
                                    <h6 class="fw-bold mt-3"><i class="bi bi-file-pdf me-2"></i>Contract</h6>
                                    <a href="../uploads/agent_contracts/<?php echo htmlspecialchars($selected_agent['contract_file']); ?>" target="_blank" class="btn btn-outline-primary btn-sm">
                                        <i class="bi bi-file-earmark-pdf me-1"></i>View Contract
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
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
            <form method="POST" enctype="multipart/form-data">
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    <input type="hidden" name="save_agent" value="1">
                    <input type="hidden" name="agent_id" value="0">
                    
                    <ul class="nav nav-tabs mb-3" role="tablist">
                        <li class="nav-item"><a class="nav-link active" data-bs-toggle="tab" href="#tabPersonal">Personal</a></li>
                        <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tabBank">Bank & Commission</a></li>
                        <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tabContract">Contract</a></li>
                    </ul>
                    
                    <div class="tab-content">
                        <div class="tab-pane fade show active" id="tabPersonal">
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
                            </div>
                        </div>
                        <div class="tab-pane fade" id="tabBank">
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">Bank Name</label>
                                    <input type="text" class="form-control" name="bank_name">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">Account Name</label>
                                    <input type="text" class="form-control" name="bank_account_name">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">Account Number</label>
                                    <input type="text" class="form-control" name="bank_account_number">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">Bank Branch</label>
                                    <input type="text" class="form-control" name="bank_branch">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">SWIFT Code</label>
                                    <input type="text" class="form-control" name="bank_swift_code">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label fw-semibold">First Trade Rate</label>
                                    <div class="input-group">
                                        <input type="number" class="form-control" name="first_trade_commission_rate" step="0.0001" min="0" max="1" value="0.2500">
                                        <span class="input-group-text">%</span>
                                    </div>
                                    <small class="text-muted">Default: 25%</small>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label fw-semibold">Regular Rate</label>
                                    <div class="input-group">
                                        <input type="number" class="form-control" name="commission_rate" step="0.0001" min="0" max="1" value="0.1000">
                                        <span class="input-group-text">%</span>
                                    </div>
                                    <small class="text-muted">Default: 10%</small>
                                </div>
                            </div>
                        </div>
                        <div class="tab-pane fade" id="tabContract">
                            <div class="row g-3">
                                <div class="col-12">
                                    <label class="form-label fw-semibold">Contract/Agreement (PDF)</label>
                                    <input type="file" class="form-control" name="contract_file" accept=".pdf,.doc,.docx">
                                    <small class="text-muted">Max size: 10MB. Allowed: PDF, DOC, DOCX</small>
                                </div>
                                <div class="col-12">
                                    <label class="form-label fw-semibold">Notes</label>
                                    <textarea class="form-control" name="notes" rows="3"></textarea>
                                </div>
                            </div>
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
                    <form method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        <input type="hidden" name="save_agent" value="1">
                        <input type="hidden" name="agent_id" value="<?php echo $selected_agent['id']; ?>">
                        
                        <ul class="nav nav-tabs mb-3" role="tablist">
                            <li class="nav-item"><a class="nav-link active" data-bs-toggle="tab" href="#editTabPersonal">Personal</a></li>
                            <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#editTabBank">Bank & Commission</a></li>
                            <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#editTabContract">Contract</a></li>
                        </ul>
                        
                        <div class="tab-content">
                            <div class="tab-pane fade show active" id="editTabPersonal">
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
                                </div>
                            </div>
                            <div class="tab-pane fade" id="editTabBank">
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label class="form-label fw-semibold">Bank Name</label>
                                        <input type="text" class="form-control" name="bank_name" value="<?php echo htmlspecialchars($selected_agent['bank_name'] ?? ''); ?>">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label fw-semibold">Account Name</label>
                                        <input type="text" class="form-control" name="bank_account_name" value="<?php echo htmlspecialchars($selected_agent['bank_account_name'] ?? ''); ?>">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label fw-semibold">Account Number</label>
                                        <input type="text" class="form-control" name="bank_account_number" value="<?php echo htmlspecialchars($selected_agent['bank_account_number'] ?? ''); ?>">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label fw-semibold">Bank Branch</label>
                                        <input type="text" class="form-control" name="bank_branch" value="<?php echo htmlspecialchars($selected_agent['bank_branch'] ?? ''); ?>">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label fw-semibold">SWIFT Code</label>
                                        <input type="text" class="form-control" name="bank_swift_code" value="<?php echo htmlspecialchars($selected_agent['bank_swift_code'] ?? ''); ?>">
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label fw-semibold">First Trade Rate</label>
                                        <div class="input-group">
                                            <input type="number" class="form-control" name="first_trade_commission_rate" step="0.0001" min="0" max="1" value="<?php echo number_format($selected_agent['first_trade_commission_rate'] ?? 0.2500, 4); ?>">
                                            <span class="input-group-text">%</span>
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label fw-semibold">Regular Rate</label>
                                        <div class="input-group">
                                            <input type="number" class="form-control" name="commission_rate" step="0.0001" min="0" max="1" value="<?php echo number_format($selected_agent['commission_rate'] ?? 0.1000, 4); ?>">
                                            <span class="input-group-text">%</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="tab-pane fade" id="editTabContract">
                                <div class="row g-3">
                                    <div class="col-12">
                                        <?php if (!empty($selected_agent['contract_file'])): ?>
                                            <div class="mb-2">
                                                <label class="form-label fw-semibold">Current Contract</label>
                                                <div>
                                                    <a href="../uploads/agent_contracts/<?php echo htmlspecialchars($selected_agent['contract_file']); ?>" target="_blank" class="btn btn-outline-primary btn-sm">
                                                        <i class="bi bi-file-earmark-pdf me-1"></i>View Current Contract
                                                    </a>
                                                </div>
                                            </div>
                                        <?php endif; ?>
                                        <label class="form-label fw-semibold">Upload New Contract (PDF)</label>
                                        <input type="file" class="form-control" name="contract_file" accept=".pdf,.doc,.docx">
                                        <small class="text-muted">Max size: 10MB. Leave empty to keep existing.</small>
                                    </div>
                                    <div class="col-12">
                                        <label class="form-label fw-semibold">Notes</label>
                                        <textarea class="form-control" name="notes" rows="3"><?php echo htmlspecialchars($selected_agent['notes'] ?? ''); ?></textarea>
                                    </div>
                                </div>
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

<!-- Link Client Modal - WITH SEARCHABLE DROPDOWN -->
<div class="modal fade" id="linkClientModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title"><i class="bi bi-link-45deg me-2"></i>Link Client to Agent</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    <input type="hidden" name="link_client" value="1">
                    <input type="hidden" name="agent_id" value="<?php echo $selected_agent_id; ?>">
                    
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle me-2"></i>
                        Link a client to <strong><?php echo htmlspecialchars($selected_agent['name'] ?? ''); ?></strong>.
                        Each client can only be linked to ONE agent.
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Search Client</label>
                        <div class="client-search-wrapper">
                            <input type="text" class="form-control" id="clientSearchInput" 
                                   placeholder="Type to search clients by name or CDS..." 
                                   autocomplete="off"
                                   onkeyup="filterClients()"
                                   onfocus="showClientDropdown()">
                            <button type="button" class="clear-search" onclick="clearClientSearch()" style="display:none;" id="clearClientSearchBtn">
                                <i class="bi bi-x-circle"></i>
                            </button>
                            <div id="clientDropdown" class="client-dropdown">
                                <?php foreach ($unlinked_clients as $client): ?>
                                    <div class="client-item" onclick="selectClient('<?php echo htmlspecialchars($client['cds_account']); ?>', '<?php echo htmlspecialchars($client['client_name']); ?>')">
                                        <strong><?php echo htmlspecialchars($client['client_name']); ?></strong>
                                        <span class="client-cds">(<?php echo htmlspecialchars($client['cds_account']); ?>)</span>
                                    </div>
                                <?php endforeach; ?>
                                <?php if (empty($unlinked_clients)): ?>
                                    <div class="no-results">No unlinked clients available.</div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <input type="hidden" name="client_cds" id="selectedClientCds" value="">
                        <div id="selectedClientDisplay" class="mt-2 text-muted" style="display:none;">
                            <span class="badge bg-success">Selected:</span>
                            <span id="selectedClientName"></span>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success" id="linkClientBtn" disabled>Link Client</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Select Trades Modal -->
<div class="modal fade" id="selectTradesModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title"><i class="bi bi-check2-square me-2"></i>Select Trades for Commission</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row">
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Select Client</label>
                            <select class="form-select" id="clientSelectForTrades" onchange="loadClientTrades()">
                                <option value="">Select a client</option>
                                <?php foreach ($agent_clients as $client): ?>
                                    <option value="<?php echo htmlspecialchars($client['cds_account']); ?>">
                                        <?php echo htmlspecialchars($client['client_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-6 text-end">
                        <button class="btn btn-warning btn-sm" onclick="calculateSelectedTrades()">
                            <i class="bi bi-calculator me-1"></i>Calculate Selected
                        </button>
                        <button class="btn btn-success btn-sm" onclick="saveTradeSelection()">
                            <i class="bi bi-save me-1"></i>Save Selection
                        </button>
                    </div>
                </div>
                
                <div id="tradesListContainer">
                    <div class="text-center py-4">
                        <i class="bi bi-inbox" style="font-size: 48px; color: #dee2e6;"></i>
                        <p class="text-muted mt-2">Select a client to view their trades.</p>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Pay Commissions Modal -->
<?php if ($can_pay_commissions && $selected_agent_id > 0 && $unpaid_balance > 0): ?>
<div class="modal fade" id="payCommissionsModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title"><i class="bi bi-cash-coin me-2"></i>Pay Agent Commissions</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="payCommissionsForm">
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    <input type="hidden" name="pay_commissions" value="1">
                    <input type="hidden" name="agent_id" value="<?php echo $selected_agent_id; ?>">
                    
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle me-2"></i>
                        Pay pending commissions for <strong><?php echo htmlspecialchars($selected_agent['name'] ?? ''); ?></strong>.
                        Total due: <strong class="text-danger">Tsh <?php echo number_format($unpaid_balance, 2); ?></strong>
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
                    
                    <div class="table-responsive" style="max-height: 300px; overflow-y: auto;">
                        <table class="table table-sm table-hover">
                            <thead>
                                <tr>
                                    <th><input type="checkbox" id="selectAllCommissions" onchange="toggleAllCommissions()"></th>
                                    <th>Trade Ref</th>
                                    <th>Date</th>
                                    <th>Commission</th>
                                    <th>Type</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($agent_ledger as $entry): ?>
                                    <?php if (!$entry['is_paid']): ?>
                                        <tr>
                                            <td><input type="checkbox" class="commission-checkbox" value="<?php echo $entry['id']; ?>"></td>
                                            <td><code><?php echo htmlspecialchars($entry['trade_reference']); ?></code></td>
                                            <td><?php echo date('d/m/Y', strtotime($entry['trade_date'])); ?></td>
                                            <td>Tsh <?php echo number_format($entry['agent_commission'], 2); ?></td>
                                            <td>
                                                <?php if ($entry['is_first_trade']): ?>
                                                    <span class="badge badge-first">First Trade</span>
                                                <?php else: ?>
                                                    <span class="badge badge-regular">Regular</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <div class="row mt-2">
                        <div class="col-md-6">
                            <div class="alert alert-secondary">
                                <strong>Selected Commissions:</strong>
                                <span id="selectedCommissionCount">0</span>
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
// JAVASCRIPT
// =====================================================

document.addEventListener('DOMContentLoaded', function() {
    <?php if ($selected_agent_id > 0 && $selected_agent): ?>
        document.getElementById('agentSearchInput').value = '<?php echo htmlspecialchars($selected_agent['name']); ?>';
        document.getElementById('selectedAgentId').value = '<?php echo $selected_agent_id; ?>';
    <?php endif; ?>
    
    // Close dropdowns when clicking outside
    document.addEventListener('click', function(e) {
        const agentWrapper = document.querySelector('.agent-search-wrapper');
        if (agentWrapper && !agentWrapper.contains(e.target)) {
            document.getElementById('agentDropdown').style.display = 'none';
        }
        const clientWrapper = document.querySelector('.client-search-wrapper');
        if (clientWrapper && !clientWrapper.contains(e.target)) {
            document.getElementById('clientDropdown').style.display = 'none';
        }
    });
    
    // Commission checkboxes for payment
    document.querySelectorAll('.commission-checkbox').forEach(cb => {
        cb.addEventListener('change', updateSelectedCommissionSummary);
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
// CLIENT SEARCH FUNCTIONS FOR LINKING
// =====================================================

function showClientDropdown() {
    document.getElementById('clientDropdown').style.display = 'block';
    filterClients();
}

function filterClients() {
    const input = document.getElementById('clientSearchInput');
    const filter = input.value.toLowerCase().trim();
    const dropdown = document.getElementById('clientDropdown');
    const items = dropdown.querySelectorAll('.client-item');
    const clearBtn = document.getElementById('clearClientSearchBtn');
    
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
            noResults.textContent = 'No clients found matching "' + filter + '"';
            dropdown.appendChild(noResults);
        }
        noResults.style.display = 'block';
    } else if (noResults) {
        noResults.style.display = 'none';
    }
    
    dropdown.style.display = 'block';
}

function clearClientSearch() {
    document.getElementById('clientSearchInput').value = '';
    document.getElementById('clearClientSearchBtn').style.display = 'none';
    document.getElementById('selectedClientCds').value = '';
    document.getElementById('selectedClientDisplay').style.display = 'none';
    document.getElementById('linkClientBtn').disabled = true;
    filterClients();
}

function selectClient(cdsAccount, clientName) {
    document.getElementById('clientSearchInput').value = clientName;
    document.getElementById('selectedClientCds').value = cdsAccount;
    document.getElementById('clientDropdown').style.display = 'none';
    document.getElementById('clearClientSearchBtn').style.display = 'block';
    document.getElementById('selectedClientDisplay').style.display = 'block';
    document.getElementById('selectedClientName').textContent = clientName + ' (' + cdsAccount + ')';
    document.getElementById('linkClientBtn').disabled = false;
}

// =====================================================
// TRADE SELECTION FUNCTIONS
// =====================================================

let currentClientTrades = [];
let selectedTrades = [];

function loadClientTrades() {
    const clientSelect = document.getElementById('clientSelectForTrades');
    const cdsAccount = clientSelect.value;
    const container = document.getElementById('tradesListContainer');
    
    if (!cdsAccount) {
        container.innerHTML = `
            <div class="text-center py-4">
                <i class="bi bi-inbox" style="font-size: 48px; color: #dee2e6;"></i>
                <p class="text-muted mt-2">Select a client to view their trades.</p>
            </div>
        `;
        return;
    }
    
    container.innerHTML = `
        <div class="text-center py-4">
            <div class="spinner-border text-primary" role="status">
                <span class="visually-hidden">Loading...</span>
            </div>
            <p class="text-muted mt-2">Loading trades...</p>
        </div>
    `;
    
    fetch(`?ajax=get_client_trades&cds_account=${encodeURIComponent(cdsAccount)}&agent_id=<?php echo $selected_agent_id; ?>`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                currentClientTrades = data.trades;
                renderTradesTable(data.trades);
            } else {
                container.innerHTML = `<div class="alert alert-danger">${data.error || 'Error loading trades'}</div>`;
            }
        })
        .catch(error => {
            container.innerHTML = `<div class="alert alert-danger">Failed to load trades: ${error}</div>`;
        });
}

function renderTradesTable(trades) {
    const container = document.getElementById('tradesListContainer');
    
    if (trades.length === 0) {
        container.innerHTML = `
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
            <span class="ms-3 text-muted" id="selectedTradesCount">0 selected</span>
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
                        <th class="text-end">Qty</th>
                        <th class="text-end">Price</th>
                        <th class="text-end">Consideration</th>
                        <th class="text-end">Brokerage</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
    `;
    
    trades.forEach((trade, index) => {
        const isSelected = trade.is_selected || false;
        const isCalculated = false; // Check if in ledger
        const rowId = 'trade_' + index;
        
        html += `
            <tr id="${rowId}" class="trade-select-row ${isSelected ? 'selected' : ''}" onclick="toggleTradeSelection('${rowId}')">
                <td>
                    <input type="checkbox" class="trade-checkbox" value="${trade.id}" 
                           onclick="event.stopPropagation();" 
                           ${isSelected ? 'checked' : ''}>
                </td>
                <td><code>${escapeHtml(trade.trade_reference)}</code></td>
                <td>${escapeHtml(trade.trade_date)}</td>
                <td>${escapeHtml(trade.security_id)}</td>
                <td><span class="badge ${trade.trade_side === 'buy' ? 'bg-success' : 'bg-danger'}">${escapeHtml(trade.trade_side)}</span></td>
                <td class="text-end">${Number(trade.quantity).toLocaleString()}</td>
                <td class="text-end">${Number(trade.price).toFixed(2)}</td>
                <td class="text-end">Tsh ${Number(trade.consideration).toLocaleString()}</td>
                <td class="text-end">Tsh ${Number(trade.final_brokerage_fee || 0).toFixed(2)}</td>
                <td>
                    ${isSelected ? '<span class="badge bg-info">Selected</span>' : '<span class="badge bg-secondary">Not Selected</span>'}
                </td>
            </tr>
        `;
    });
    
    html += `
                </tbody>
            </table>
        </div>
    `;
    
    container.innerHTML = html;
    updateSelectedTradesCount();
}

function toggleTradeSelection(rowId) {
    const row = document.getElementById(rowId);
    if (!row) return;
    
    const checkbox = row.querySelector('.trade-checkbox');
    if (!checkbox) return;
    
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
    
    const checkboxes = document.querySelectorAll('.trade-checkbox');
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
    const checkboxes = document.querySelectorAll('.trade-checkbox');
    checkboxes.forEach(cb => {
        cb.checked = true;
        const row = cb.closest('tr');
        if (row) row.classList.add('selected');
    });
    updateSelectedTradesCount();
    updateSelectAllTradesState();
}

function deselectAllTrades() {
    const checkboxes = document.querySelectorAll('.trade-checkbox');
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
    if (display) display.textContent = count + ' selected';
}

function updateSelectAllTradesState() {
    const selectAll = document.getElementById('selectAllTradesCheckbox');
    if (!selectAll) return;
    
    const checkboxes = document.querySelectorAll('.trade-checkbox');
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

function saveTradeSelection() {
    const checkboxes = document.querySelectorAll('.trade-checkbox:checked');
    const tradeIds = Array.from(checkboxes).map(cb => cb.value);
    
    if (tradeIds.length === 0) {
        alert('Please select at least one trade.');
        return;
    }
    
    fetch(`?ajax=save_trade_selection&agent_id=<?php echo $selected_agent_id; ?>&trade_ids=${tradeIds.join(',')}&action=add`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert('Trade selection saved successfully!');
            } else {
                alert('Error saving selection: ' + data.error);
            }
        })
        .catch(error => {
            alert('Error saving selection: ' + error);
        });
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
    
    fetch(`?ajax=calculate_selected_trades&agent_id=<?php echo $selected_agent_id; ?>&trade_ids=${tradeIds.join(',')}`)
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
        });
}

// =====================================================
// COMMISSION PAYMENT FUNCTIONS
// =====================================================

function toggleAllCommissions() {
    const selectAll = document.getElementById('selectAllCommissions');
    if (!selectAll) return;
    
    document.querySelectorAll('.commission-checkbox').forEach(cb => {
        cb.checked = selectAll.checked;
    });
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
        if (cells.length >= 4) {
            const amountText = cells[3].textContent.replace('Tsh ', '').replace(/,/g, '');
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
