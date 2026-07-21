<?php
/**
 * Agent Management System - WITH GL POSTING
 * Location: /finance/agent_management.php
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
        is_posted_to_gl TINYINT(1) DEFAULT 0,
        gl_journal_no VARCHAR(50),
        payment_id INT DEFAULT NULL,
        payment_no VARCHAR(50) DEFAULT NULL,
        paid_at DATETIME DEFAULT NULL,
        posted_to_gl_at DATETIME DEFAULT NULL,
        calculation_date DATETIME DEFAULT CURRENT_TIMESTAMP,
        notes TEXT,
        INDEX idx_agent_id (agent_id),
        INDEX idx_trade_id (trade_id),
        INDEX idx_is_paid (is_paid),
        INDEX idx_is_posted_to_gl (is_posted_to_gl),
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
        gl_journal_no VARCHAR(50),
        gl_posted_at DATETIME DEFAULT NULL,
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
            SUM(CASE WHEN is_posted_to_gl = 1 THEN agent_commission ELSE 0 END) as posted_to_gl,
            SUM(agent_commission) as total_commission
        FROM agent_commission_ledger
        WHERE agent_id = ?
    ");
    $stmt->execute([$agent_id]);
    return $stmt->fetch();
}

// =====================================================
// GL POSTING FUNCTION - SIMILAR TO SALARY SYSTEM
// =====================================================

function postAgentCommissionToGL($db, $agent_id, $commission_ids, $posting_date = null) {
    if (!$posting_date) {
        $posting_date = date('Y-m-d');
    }
    
    try {
        $db->beginTransaction();
        
        // Get commission details
        $placeholders = implode(',', array_fill(0, count($commission_ids), '?'));
        $stmt = $db->prepare("
            SELECT l.*, a.name as agent_name, a.agent_code
            FROM agent_commission_ledger l
            INNER JOIN agents a ON l.agent_id = a.id
            WHERE l.id IN ($placeholders)
            AND l.is_posted_to_gl = 0
        ");
        $stmt->execute($commission_ids);
        $commissions = $stmt->fetchAll();
        
        if (empty($commissions)) {
            throw new Exception("No commissions found to post to GL");
        }
        
        $total_amount = array_sum(array_column($commissions, 'agent_commission'));
        $agent_name = $commissions[0]['agent_name'];
        $agent_code = $commissions[0]['agent_code'];
        $trade_count = count($commissions);
        
        // Generate GL reference number
        $gl_reference = 'AGT' . date('Ymd') . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
        
        // Get account codes
        // DR: Agent Commission Expense (like 5125 - Agent Commission Expense)
        $expense_account = getValidAccountCode($db, '5125', '51100');
        // CR: Agent Commission Payable (2127)
        $payable_account = getValidAccountCode($db, '2127', '2110');
        
        // =====================================================
        // JOURNAL ENTRY 1: DR - Agent Commission Expense
        // =====================================================
        $journal1 = createJournalEntry($db, [
            'transaction_date' => $posting_date,
            'reference_no' => $gl_reference,
            'reference_type' => 'agent_commission',
            'description' => "Agent Commission - $agent_name ($agent_code) - $trade_count trade(s)",
            'account_code' => $expense_account,
            'debit_amount' => $total_amount,
            'credit_amount' => 0,
            'currency' => 'Tsh',
            'entity_id' => $agent_id,
            'entity_name' => $agent_name,
            'entity_type' => 'agent'
        ]);
        
        // =====================================================
        // JOURNAL ENTRY 2: CR - Agent Commission Payable
        // =====================================================
        $journal2 = createJournalEntry($db, [
            'transaction_date' => $posting_date,
            'reference_no' => $gl_reference,
            'reference_type' => 'agent_commission',
            'description' => "Agent Commission Payable - $agent_name ($agent_code) - $trade_count trade(s)",
            'account_code' => $payable_account,
            'debit_amount' => 0,
            'credit_amount' => $total_amount,
            'currency' => 'Tsh',
            'entity_id' => $agent_id,
            'entity_name' => $agent_name,
            'entity_type' => 'agent'
        ]);
        
        // Update ledger entries as posted to GL
        $stmt = $db->prepare("
            UPDATE agent_commission_ledger 
            SET is_posted_to_gl = 1,
                gl_journal_no = ?,
                posted_to_gl_at = NOW()
            WHERE id IN ($placeholders)
        ");
        $stmt->execute(array_merge([$gl_reference], $commission_ids));
        
        $db->commit();
        
        return [
            'success' => true,
            'gl_reference' => $gl_reference,
            'total_amount' => $total_amount,
            'trade_count' => $trade_count,
            'journal1' => $journal1,
            'journal2' => $journal2
        ];
        
    } catch (Exception $e) {
        $db->rollBack();
        error_log("GL Posting error: " . $e->getMessage());
        throw $e;
    }
}

// =====================================================
// POST TO GL WHEN PAYMENT IS MADE
// =====================================================

function postAgentPaymentToGL($db, $payment_id) {
    try {
        $db->beginTransaction();
        
        // Get payment details
        $stmt = $db->prepare("
            SELECT p.*, a.name as agent_name, a.agent_code
            FROM agent_payments p
            INNER JOIN agents a ON p.agent_id = a.id
            WHERE p.id = ? AND p.status = 'paid'
        ");
        $stmt->execute([$payment_id]);
        $payment = $stmt->fetch();
        
        if (!$payment) {
            throw new Exception("Payment not found or not paid");
        }
        
        // Generate GL reference
        $gl_reference = 'AGTPMT' . date('Ymd') . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
        
        // Get account codes
        $payable_account = getValidAccountCode($db, '2127', '2110');
        $bank_account = getValidAccountCode($db, $payment['bank_account_id'] ?? '1110', '1110');
        
        // =====================================================
        // JOURNAL ENTRY 1: DR - Agent Commission Payable
        // =====================================================
        $journal1 = createJournalEntry($db, [
            'transaction_date' => $payment['payment_date'],
            'reference_no' => $payment['payment_no'],
            'reference_type' => 'agent_payment',
            'description' => "Agent Commission Payment - {$payment['agent_name']} ({$payment['payment_no']})",
            'account_code' => $payable_account,
            'debit_amount' => $payment['total_amount'],
            'credit_amount' => 0,
            'currency' => 'Tsh',
            'entity_id' => $payment['agent_id'],
            'entity_name' => $payment['agent_name'],
            'entity_type' => 'agent',
            'bank_account_id' => $payment['bank_account_id'],
            'bank_name' => $payment['bank_name'],
            'bank_account_number' => $payment['bank_account_number']
        ]);
        
        // =====================================================
        // JOURNAL ENTRY 2: CR - Bank Account
        // =====================================================
        $journal2 = createJournalEntry($db, [
            'transaction_date' => $payment['payment_date'],
            'reference_no' => $payment['payment_no'],
            'reference_type' => 'agent_payment',
            'description' => "Agent Commission Payment - {$payment['agent_name']} ({$payment['payment_no']})",
            'account_code' => $bank_account,
            'debit_amount' => 0,
            'credit_amount' => $payment['total_amount'],
            'currency' => 'Tsh',
            'entity_id' => $payment['agent_id'],
            'entity_name' => $payment['agent_name'],
            'entity_type' => 'agent',
            'bank_account_id' => $payment['bank_account_id'],
            'bank_name' => $payment['bank_name'],
            'bank_account_number' => $payment['bank_account_number']
        ]);
        
        // Update payment record
        $stmt = $db->prepare("
            UPDATE agent_payments 
            SET gl_journal_no = ?,
                gl_posted_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$gl_reference, $payment_id]);
        
        $db->commit();
        
        return [
            'success' => true,
            'gl_reference' => $gl_reference,
            'journal1' => $journal1,
            'journal2' => $journal2
        ];
        
    } catch (Exception $e) {
        $db->rollBack();
        error_log("Payment GL Posting error: " . $e->getMessage());
        throw $e;
    }
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
            
            // Save to ledger (NOT posted to GL yet)
            $stmt = $db->prepare("
                INSERT INTO agent_commission_ledger (
                    agent_id, trade_id, trade_reference, client_cds_account,
                    trade_date, trade_consideration, brokerage_commission,
                    agent_commission, commission_rate, is_first_trade, 
                    is_paid, is_posted_to_gl
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 0)
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

function getAgentCommissionRate($db, $agent_id, $is_first_trade = false) {
    $stmt = $db->prepare("SELECT first_trade_commission_rate, commission_rate FROM agents WHERE id = ? AND status = 'active'");
    $stmt->execute([$agent_id]);
    $agent = $stmt->fetch();
    
    if (!$agent) return 0;
    
    return $is_first_trade ? 
        ($agent['first_trade_commission_rate'] ?? 0.2500) : 
        ($agent['commission_rate'] ?? 0.1000);
}

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

// =====================================================
// PAYMENT PROCESSING WITH GL POSTING
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
        
        // Post payment to GL
        $gl_result = postAgentPaymentToGL($db, $payment_id);
        
        $db->commit();
        
        return [
            'success' => true,
            'payment_no' => $payment_no,
            'payment_id' => $payment_id,
            'gl_reference' => $gl_result['gl_reference']
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

if (isset($_GET['ajax']) && $_GET['ajax'] == 'post_to_gl') {
    header('Content-Type: application/json');
    
    // Only finance can post to GL
    if (!in_array($user_role, $finance_roles)) {
        echo json_encode(['success' => false, 'error' => 'Only finance officers can post to GL']);
        exit;
    }
    
    $agent_id = (int)$_GET['agent_id'] ?? 0;
    $commission_ids = isset($_GET['commission_ids']) ? explode(',', $_GET['commission_ids']) : [];
    $posting_date = $_GET['posting_date'] ?? date('Y-m-d');
    
    if (empty($commission_ids)) {
        echo json_encode(['success' => false, 'error' => 'No commissions selected']);
        exit;
    }
    
    try {
        $result = postAgentCommissionToGL($db, $agent_id, $commission_ids, $posting_date);
        
        echo json_encode([
            'success' => true,
            'gl_reference' => $result['gl_reference'],
            'total_amount' => $result['total_amount'],
            'trade_count' => $result['trade_count'],
            'message' => "Posted {$result['trade_count']} commission(s) to GL. Reference: {$result['gl_reference']}"
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

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
        
        if ($agent_id > 0) {
            $selected_ids = [];
            $stmt = $db->prepare("SELECT trade_id FROM agent_trades_selection WHERE agent_id = ?");
            $stmt->execute([$agent_id]);
            while ($row = $stmt->fetch()) {
                $selected_ids[] = $row['trade_id'];
            }
            
            foreach ($trades as &$trade) {
                $trade['is_selected'] = in_array($trade['id'], $selected_ids);
                
                // Check if already in ledger
                $stmt = $db->prepare("SELECT id FROM agent_commission_ledger WHERE trade_id = ? AND agent_id = ?");
                $stmt->execute([$trade['id'], $agent_id]);
                $trade['is_calculated'] = $stmt->fetch() ? true : false;
            }
        }
        
        echo json_encode(['success' => true, 'trades' => $trades]);
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

// =====================================================
// POST HANDLING
// =====================================================

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $error_message = "CSRF token validation failed. Please try again.";
    } else {
        // ============ CREATE/UPDATE AGENT ============
        if (isset($_POST['save_agent'])) {
            // ... (keep existing code)
        }
        
        // ============ LINK CLIENT ============
        if (isset($_POST['link_client'])) {
            // ... (keep existing code)
        }
        
        // ============ UNLINK CLIENT ============
        if (isset($_POST['unlink_client'])) {
            // ... (keep existing code)
        }
        
        // ============ PAY COMMISSIONS ============
        if (isset($_POST['pay_commissions']) && $can_pay_commissions) {
            $agent_id = (int)$_POST['agent_id'] ?? 0;
            $commission_ids = isset($_POST['commission_ids']) ? $_POST['commission_ids'] : [];
            
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
            
            if (empty($commission_ids)) {
                $error_message = "Please select at least one commission to pay.";
            } elseif ($bank_account_id <= 0) {
                $error_message = "Please select a bank account.";
            } else {
                try {
                    $placeholders = implode(',', array_fill(0, count($commission_ids), '?'));
                    $stmt = $db->prepare("
                        SELECT l.*, a.name as agent_name, a.bank_name as agent_bank_name,
                               a.bank_account_number as agent_bank_account
                        FROM agent_commission_ledger l
                        INNER JOIN agents a ON l.agent_id = a.id
                        WHERE l.id IN ($placeholders)
                        AND l.is_paid = 0
                    ");
                    $stmt->execute($commission_ids);
                    $commissions = $stmt->fetchAll();
                    
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
                        
                        $stmt = $db->prepare("SELECT bank_name, account_number, code FROM banks_accounts WHERE id = ?");
                        $stmt->execute([$bank_account_id]);
                        $bank = $stmt->fetch();
                        
                        if (!$bank) {
                            $error_message = "Bank account not found.";
                        } else {
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
                                $success_message .= "GL Reference: <strong>{$result['gl_reference']}</strong><br>";
                                $success_message .= "Amount: <strong>Tsh " . number_format($total_amount, 2) . "</strong>";
                                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                            }
                        }
                    }
                } catch (Exception $e) {
                    $error_message = "Error processing payment: " . $e->getMessage();
                }
            }
        }
        
        // ============ POST TO GL ============
        if (isset($_POST['post_to_gl']) && $can_pay_commissions) {
            $agent_id = (int)$_POST['agent_id'] ?? 0;
            $commission_ids = isset($_POST['commission_ids']) ? $_POST['commission_ids'] : [];
            $posting_date = $_POST['posting_date'] ?? date('Y-m-d');
            
            if (is_array($commission_ids)) {
                $commission_ids = array_filter($commission_ids);
            } else {
                $commission_ids = array_filter([$commission_ids]);
            }
            
            if (empty($commission_ids)) {
                $error_message = "Please select at least one commission to post to GL.";
            } else {
                try {
                    $result = postAgentCommissionToGL($db, $agent_id, $commission_ids, $posting_date);
                    
                    if ($result['success']) {
                        $success_message = "Commissions posted to General Ledger successfully!<br>";
                        $success_message .= "GL Reference: <strong>{$result['gl_reference']}</strong><br>";
                        $success_message .= "Total Amount: <strong>Tsh " . number_format($result['total_amount'], 2) . "</strong><br>";
                        $success_message .= "Trades: <strong>{$result['trade_count']}</strong>";
                        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                    }
                } catch (Exception $e) {
                    $error_message = "Error posting to GL: " . $e->getMessage();
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
$unposted_balance = 0;
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
            
            // Calculate unposted balance
            $unposted_balance = 0;
            foreach ($agent_ledger as $entry) {
                if (!$entry['is_posted_to_gl'] && !$entry['is_paid']) {
                    $unposted_balance += $entry['agent_commission'];
                }
            }
            
            // Get selected trade IDs
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

// Get unlinked clients
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

$page_title = 'Agent Management - GL Integration';
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
.agent-stat .number.gl { color: #6f42c1; }

.ledger-row.paid { background: #d4edda; }
.ledger-row.unpaid { background: #fff3cd; }
.ledger-row.gl-posted { background: #cce5ff; }
.ledger-row .badge-first { background: #ffc107; color: #212529; }
.ledger-row .badge-regular { background: #17a2b8; color: white; }
.ledger-row .badge-paid { background: #28a745; color: white; }
.ledger-row .badge-unpaid { background: #dc3545; color: white; }
.ledger-row .badge-gl { background: #6f42c1; color: white; }

.action-btn-group .btn { font-size: 12px; padding: 2px 8px; }

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

.trade-select-row {
    cursor: pointer;
}
.trade-select-row.selected {
    background: #cce5ff;
}
.trade-select-row:hover {
    background: #f8f9fa;
}
.trade-select-row.calculated {
    opacity: 0.6;
    cursor: not-allowed;
}
</style>

<div class="container-fluid">
    
    <!-- Access Notice -->
    <?php if ($can_pay_commissions): ?>
        <div class="alert alert-success">
            <i class="bi bi-check-circle me-2"></i>
            <strong>Full Access:</strong> You can manage agents, link clients, select trades, calculate commissions, post to GL, and process payments.
            <span class="permission-badge finance">Finance Access</span>
        </div>
    <?php else: ?>
        <div class="alert alert-info">
            <i class="bi bi-info-circle me-2"></i>
            <strong>Management Access:</strong> You can manage agents, link clients, select trades, and calculate commissions.
            <span class="permission-badge ops">Ops Access</span>
            <span class="text-warning ms-2">GL posting and payments require Finance approval.</span>
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
                    <small class="text-muted">Manage agents, link clients, select trades, calculate commissions, post to GL</small>
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
                    <div class="number total"><?php echo number_format($agent_ledger_summary['total_trades'] ?? 0); ?></div>
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
                    <div class="number gl">Tsh <?php echo number_format($unposted_balance, 2); ?></div>
                    <div class="label">Unposted to GL</div>
                </div>
            </div>
        </div>

        <!-- GL Action Buttons -->
        <?php if ($can_pay_commissions && $unposted_balance > 0): ?>
            <div class="row mb-3">
                <div class="col-12">
                    <div class="alert alert-warning">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <i class="bi bi-exclamation-triangle me-2"></i>
                                <strong>Unposted Commissions:</strong> 
                                Tsh <?php echo number_format($unposted_balance, 2); ?> needs to be posted to General Ledger.
                            </div>
                            <div>
                                <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#postToGLModal">
                                    <i class="bi bi-journal me-1"></i>Post to GL
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

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
                                    <span class="badge bg-info ms-3 me-2">GL Posted</span> = Posted to Ledger
                                    <span class="badge bg-info ms-3 me-2">First Trade</span> = 25% Commission
                                    <span class="badge bg-secondary ms-3 me-2">Regular</span> = 10% Commission
                                </small>
                            </div>
                            <div class="col-md-4 text-end">
                                <?php if ($can_pay_commissions): ?>
                                    <button class="btn btn-primary btn-sm me-1" data-bs-toggle="modal" data-bs-target="#postToGLModal">
                                        <i class="bi bi-journal me-1"></i>Post to GL
                                    </button>
                                <?php endif; ?>
                                <button class="btn btn-warning btn-sm" onclick="calculateSelectedTrades()">
                                    <i class="bi bi-calculator me-1"></i>Calculate
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
                                            <th>GL Status</th>
                                            <th>Payment Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($agent_ledger as $entry): ?>
                                            <tr class="ledger-row <?php echo $entry['is_paid'] ? 'paid' : 'unpaid'; ?> <?php echo $entry['is_posted_to_gl'] ? 'gl-posted' : ''; ?>">
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
                                                    <?php if ($entry['is_posted_to_gl']): ?>
                                                        <span class="badge badge-gl">
                                                            Posted
                                                            <?php if (!empty($entry['gl_journal_no'])): ?>
                                                                <br><small><?php echo htmlspecialchars($entry['gl_journal_no']); ?></small>
                                                            <?php endif; ?>
                                                        </span>
                                                    <?php else: ?>
                                                        <span class="badge badge-unpaid">Not Posted</span>
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
                                            <td colspan="3"></td>
                                        </tr>
                                        <tr>
                                            <td colspan="6" class="text-end fw-bold">Total Unposted:</td>
                                            <td class="text-end fw-bold text-warning">Tsh <?php echo number_format($unposted_balance, 2); ?></td>
                                            <td colspan="3"></td>
                                        </tr>
                                        <tr>
                                            <td colspan="6" class="text-end fw-bold">Total Commission:</td>
                                            <td class="text-end fw-bold">Tsh <?php echo number_format($agent_ledger_summary['total_commission'] ?? 0, 2); ?></td>
                                            <td colspan="3"></td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                            
                            <?php if ($can_pay_commissions && $unpaid_balance > 0): ?>
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
                                            <th>GL Reference</th>
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
                                                    <span class="badge badge-first"><?php echo $payment['first_trade_count']; ?></span>
                                                    <span class="badge badge-regular"><?php echo $payment['regular_trade_count']; ?></span>
                                                </td>
                                                <td>
                                                    <?php if (!empty($payment['gl_journal_no'])): ?>
                                                        <code><?php echo htmlspecialchars($payment['gl_journal_no']); ?></code>
                                                    <?php else: ?>
                                                        <span class="text-muted">-</span>
                                                    <?php endif; ?>
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
                <!-- Keep existing details display -->
            </div>
        </div>

    <?php endif; ?>
</div>

<!-- ===================================================== -->
<!-- MODALS -->
<!-- ===================================================== -->

<!-- New Agent Modal -->
<div class="modal fade" id="agentModal" tabindex="-1">
    <!-- Keep existing -->
</div>

<!-- Edit Agent Modal -->
<div class="modal fade" id="editAgentModal" tabindex="-1">
    <!-- Keep existing -->
</div>

<!-- Link Client Modal -->
<div class="modal fade" id="linkClientModal" tabindex="-1">
    <!-- Keep existing -->
</div>

<!-- Select Trades Modal -->
<div class="modal fade" id="selectTradesModal" tabindex="-1">
    <!-- Keep existing -->
</div>

<!-- Post to GL Modal -->
<?php if ($can_pay_commissions && $selected_agent_id > 0): ?>
<div class="modal fade" id="postToGLModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="bi bi-journal me-2"></i>Post Commissions to General Ledger</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="postToGLForm">
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    <input type="hidden" name="post_to_gl" value="1">
                    <input type="hidden" name="agent_id" value="<?php echo $selected_agent_id; ?>">
                    
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle me-2"></i>
                        Post commissions for <strong><?php echo htmlspecialchars($selected_agent['name'] ?? ''); ?></strong> to General Ledger.
                        <br><strong>Total Unposted:</strong> <span class="text-warning">Tsh <?php echo number_format($unposted_balance, 2); ?></span>
                    </div>
                    
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Posting Date <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" name="posting_date" value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">GL Reference</label>
                            <input type="text" class="form-control" value="AGT<?php echo date('Ymd'); ?>XXXX" disabled>
                            <small class="text-muted">Auto-generated</small>
                        </div>
                    </div>
                    
                    <hr>
                    
                    <div class="table-responsive" style="max-height: 300px; overflow-y: auto;">
                        <table class="table table-sm table-hover">
                            <thead>
                                <tr>
                                    <th><input type="checkbox" id="selectAllGL" onchange="toggleAllGL()"></th>
                                    <th>Trade Ref</th>
                                    <th>Date</th>
                                    <th>Commission</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($agent_ledger as $entry): ?>
                                    <?php if (!$entry['is_posted_to_gl'] && !$entry['is_paid']): ?>
                                        <tr>
                                            <td><input type="checkbox" class="gl-checkbox" value="<?php echo $entry['id']; ?>"></td>
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
                                <strong>Selected:</strong>
                                <span id="selectedGLCount">0</span> commissions
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="alert alert-success">
                                <strong>Total Amount:</strong>
                                <span id="selectedGLTotal">Tsh 0.00</span>
                            </div>
                        </div>
                    </div>
                    
                    <div id="selectedGLIds"></div>
                    
                    <div class="alert alert-warning mt-3">
                        <i class="bi bi-exclamation-triangle me-2"></i>
                        <strong>GL Posting Impact:</strong>
                        <ul class="mb-0 mt-2">
                            <li><strong>DR:</strong> Agent Commission Expense (5125)</li>
                            <li><strong>CR:</strong> Agent Commission Payable (2127)</li>
                        </ul>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Post to GL</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Pay Commissions Modal -->
<?php if ($can_pay_commissions && $selected_agent_id > 0 && $unpaid_balance > 0): ?>
<div class="modal fade" id="payCommissionsModal" tabindex="-1">
    <!-- Keep existing with updated GL info -->
</div>
<?php endif; ?>

<script>
// =====================================================
// JAVASCRIPT - UPDATED WITH GL FUNCTIONS
// =====================================================

document.addEventListener('DOMContentLoaded', function() {
    <?php if ($selected_agent_id > 0 && $selected_agent): ?>
        document.getElementById('agentSearchInput').value = '<?php echo htmlspecialchars($selected_agent['name']); ?>';
        document.getElementById('selectedAgentId').value = '<?php echo $selected_agent_id; ?>';
    <?php endif; ?>
    
    // Close dropdowns
    document.addEventListener('click', function(e) {
        const agentWrapper = document.querySelector('.agent-search-wrapper');
        if (agentWrapper && !agentWrapper.contains(e.target)) {
            document.getElementById('agentDropdown').style.display = 'none';
        }
    });
    
    // GL checkboxes
    document.querySelectorAll('.gl-checkbox').forEach(cb => {
        cb.addEventListener('change', updateGLSummary);
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
// GL POSTING FUNCTIONS
// =====================================================

function toggleAllGL() {
    const selectAll = document.getElementById('selectAllGL');
    if (!selectAll) return;
    
    document.querySelectorAll('.gl-checkbox').forEach(cb => {
        cb.checked = selectAll.checked;
    });
    updateGLSummary();
}

function updateGLSummary() {
    const checkboxes = document.querySelectorAll('.gl-checkbox:checked');
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
    
    document.getElementById('selectedGLCount').textContent = count;
    document.getElementById('selectedGLTotal').textContent = 'Tsh ' + total.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    document.getElementById('selectedGLIds').innerHTML = ids.map(id => 
        `<input type="hidden" name="commission_ids[]" value="${id}">`
    ).join('');
}

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
// TRADE SELECTION FUNCTIONS
// =====================================================

// ... (keep existing trade selection functions)

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

function loadClientTrades() {
    // ... (keep existing)
}

function renderTradesTable(trades) {
    // ... (keep existing)
}

function toggleTradeSelection(rowId) {
    // ... (keep existing)
}

function toggleAllTrades() {
    // ... (keep existing)
}

function selectAllTrades() {
    // ... (keep existing)
}

function deselectAllTrades() {
    // ... (keep existing)
}

function updateSelectedTradesCount() {
    // ... (keep existing)
}

function updateSelectAllTradesState() {
    // ... (keep existing)
}

function saveTradeSelection() {
    // ... (keep existing)
}

function viewClientTrades(cdsAccount) {
    // ... (keep existing)
}

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
