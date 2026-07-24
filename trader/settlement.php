<?php
// ============================================
// SETTLEMENT.PHP - COMPLETE WITH ALL MODALS
// ============================================

require_once '../config/config.php';
require_once '../auth/auth_middleware.php';
require_once '../includes/dealing_sheet_helpers.php';
require_once '../includes/financial_helpers.php';

// Check user permissions
$user_role = $_SESSION['role'] ?? '';
$allowed_roles = ['finance_officer', 'system_admin', 'trader'];

require_login();

if (!in_array($user_role, $allowed_roles)) {
    show_alert('Access denied. You do not have permission to access the settlement page.', 'danger');
    redirect('index.php');
}

$db = getDBConnection();
$current_user = get_logged_in_user() ?: get_session_user();
$success_message = '';
$error_message = '';

if (function_exists('dealingSheetEnsureSchema')) {
    try {
        dealingSheetEnsureSchema($db);
    } catch (Exception $e) {
        error_log('Unable to initialize dealing sheet schema on settlement page: ' . $e->getMessage());
    }
}

// ============================================
// HELPER FUNCTIONS
// ============================================

function syncSettlementTradeToDealingSheetSafely($db, $tradeId, $user) {
    if (!function_exists('dealingSheetSyncTradeLifecycle')) {
        return;
    }
    try {
        dealingSheetSyncTradeLifecycle($db, (int) $tradeId, $user, false);
    } catch (Exception $e) {
        error_log('Failed to sync settlement status for trade ' . (int) $tradeId . ': ' . $e->getMessage());
    }
}

function calculateBankCharge($consideration) {
    if ($consideration < 100000) {
        return 250;
    } elseif ($consideration < 10000000) {
        return 2000;
    } elseif ($consideration < 50000000) {
        return 6000;
    } else {
        return 12000;
    }
}

function recordBankChargesForTrade($db, $trade_id, $consideration, $trade_date) {
    $bank_charge = calculateBankCharge($consideration);
    if ($bank_charge <= 0) {
        return true;
    }
    
    $cash_account = getAccountIdByCode($db, '1001');
    $bank_charge_account = getAccountIdByCode($db, '425');
    
    if (!$cash_account || !$bank_charge_account) {
        error_log("Bank charges GL: missing account for trade $trade_id");
        return false;
    }
    
    $reference_no = 'SETTLE-' . str_pad($trade_id, 6, '0', STR_PAD_LEFT);
    $description = "Bank charges - Trade #$trade_id (Consideration: " . number_format($consideration, 2) . ")";
    
    $entry1 = recordGeneralLedgerEntry($db, $trade_date, $cash_account, $bank_charge, 0,
        "Bank charges collected - Trade Ref: $reference_no", $reference_no, 'fee');
    
    $entry2 = recordGeneralLedgerEntry($db, $trade_date, $bank_charge_account, 0, $bank_charge,
        "Bank charges income - Trade Ref: $reference_no", $reference_no, 'fee');
    
    if ($entry1 && $entry2) {
        error_log("Bank charges recorded: TZS " . number_format($bank_charge, 2) . " for trade $trade_id");
        return true;
    }
    return false;
}

function generateUniquePaymentNo($db) {
    $prefix = 'PMT';
    $year = date('Y');
    $month = date('m');
    $day = date('d');
    
    $base_no = $prefix . $year . $month . $day;
    $seq = 1;
    
    do {
        $payment_no = $base_no . str_pad($seq, 4, '0', STR_PAD_LEFT);
        $stmt = $db->prepare("SELECT COUNT(*) as count FROM payments WHERE payment_no = ?");
        $stmt->execute([$payment_no]);
        $result = $stmt->fetch();
        
        if ($result['count'] == 0) {
            return $payment_no;
        }
        $seq++;
        if ($seq > 9999) {
            return $prefix . $year . $month . $day . '_' . time();
        }
    } while (true);
}

function updateBankBalance($db, $bank_id, $amount, $is_payment_out = true) {
    try {
        if ($is_payment_out) {
            $stmt = $db->prepare("UPDATE banks_accounts SET current_balance = current_balance - ? WHERE id = ?");
        } else {
            $stmt = $db->prepare("UPDATE banks_accounts SET current_balance = current_balance + ? WHERE id = ?");
        }
        return $stmt->execute([$amount, $bank_id]);
    } catch (Exception $e) {
        error_log("Error updating bank balance: " . $e->getMessage());
        return false;
    }
}

function createJournalEntry($db, $payment_no, $trade, $bank_account, $amount, $description) {
    try {
        $journal_no = 'JRNL' . date('Ymd') . '_' . uniqid();
        $fiscal_year = date('Y');
        $fiscal_period = date('m');
        $current_user = $_SESSION['username'] ?? 'system';
        $user_id = $_SESSION['user_id'] ?? null;
        
        if ($trade['trade_side'] === 'sell') {
            $debit_account = $bank_account['code'] ?? '111';
            $credit_account = '41';
            $debit_account_name = 'Bank Account';
            $credit_account_name = 'Sales Revenue';
        } else {
            $debit_account = '11';
            $credit_account = $bank_account['code'] ?? '111';
            $debit_account_name = 'Investment Account';
            $credit_account_name = 'Bank Account';
        }
        
        try {
            $stmt = $db->prepare("SELECT account_name FROM chart_of_accounts WHERE account_code = ?");
            $stmt->execute([$debit_account]);
            $debit_account_info = $stmt->fetch();
            if ($debit_account_info) {
                $debit_account_name = $debit_account_info['account_name'];
            }
            $stmt->execute([$credit_account]);
            $credit_account_info = $stmt->fetch();
            if ($credit_account_info) {
                $credit_account_name = $credit_account_info['account_name'];
            }
        } catch (Exception $e) {
            error_log("Error fetching account names: " . $e->getMessage());
        }
        
        $debit_stmt = $db->prepare("
            INSERT INTO journal_entries (
                journal_no, transaction_date, reference_no, reference_type, 
                description, account_code, account_name, debit_amount, 
                credit_amount, currency, entity_id, entity_name, entity_type,
                bank_account_id, bank_name, bank_account_number,
                fiscal_year, fiscal_period, posted_by, posted_at,
                status, created_by, created_by_username
            ) VALUES (?, NOW(), ?, 'payment', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), 'posted', ?, ?)
        ");
        
        $debit_stmt->execute([
            $journal_no,
            $payment_no,
            $description,
            $debit_account,
            $debit_account_name,
            $trade['trade_side'] === 'sell' ? $amount : 0,
            $trade['trade_side'] === 'sell' ? 0 : $amount,
            'Tsh',
            $trade['id'],
            $trade['trade_side'] === 'sell' ? $trade['counterparty_name'] : $trade['client_name'],
            $trade['trade_side'] === 'sell' ? 'counterparty' : 'client',
            $bank_account['id'] ?? null,
            $bank_account['bank_name'] ?? '',
            $bank_account['account_number'] ?? '',
            $fiscal_year,
            $fiscal_period,
            $user_id,
            $user_id,
            $current_user
        ]);
        
        $credit_stmt = $db->prepare("
            INSERT INTO journal_entries (
                journal_no, transaction_date, reference_no, reference_type, 
                description, account_code, account_name, debit_amount, 
                credit_amount, currency, entity_id, entity_name, entity_type,
                bank_account_id, bank_name, bank_account_number,
                fiscal_year, fiscal_period, posted_by, posted_at,
                status, created_by, created_by_username
            ) VALUES (?, NOW(), ?, 'payment', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), 'posted', ?, ?)
        ");
        
        $credit_stmt->execute([
            $journal_no,
            $payment_no,
            $description,
            $credit_account,
            $credit_account_name,
            $trade['trade_side'] === 'sell' ? 0 : $amount,
            $trade['trade_side'] === 'sell' ? $amount : 0,
            'Tsh',
            $trade['id'],
            $trade['trade_side'] === 'sell' ? $trade['counterparty_name'] : $trade['client_name'],
            $trade['trade_side'] === 'sell' ? 'counterparty' : 'client',
            $bank_account['id'] ?? null,
            $bank_account['bank_name'] ?? '',
            $bank_account['account_number'] ?? '',
            $fiscal_year,
            $fiscal_period,
            $user_id,
            $user_id,
            $current_user
        ]);
        
        return $journal_no;
    } catch (Exception $e) {
        error_log("Error creating journal entry: " . $e->getMessage());
        return false;
    }
}

// ============================================
// UPDATE ORDER_SHEET STATUS
// ============================================
function updateOrderSheetStatus($db, $trade_id, $status, $notes = '', $linked_trade_id = null, $linked_trade_ref = null) {
    try {
        $check_stmt = $db->prepare("SELECT id FROM order_sheet WHERE trade_id = ?");
        $check_stmt->execute([$trade_id]);
        $order_sheet = $check_stmt->fetch();
        
        if ($order_sheet) {
            if ($status === 'linked' && $linked_trade_id) {
                $update_stmt = $db->prepare("
                    UPDATE order_sheet 
                    SET settlement_status = ?, 
                        settlement_notes = CONCAT(COALESCE(settlement_notes, ''), ?),
                        settled_at = NOW(),
                        settled_by = ?,
                        linked_trade_id = ?,
                        linked_trade_ref = ?
                    WHERE trade_id = ?
                ");
                $update_stmt->execute([
                    $status, 
                    "\n" . $notes, 
                    $_SESSION['username'] ?? 'system', 
                    $linked_trade_id,
                    $linked_trade_ref,
                    $trade_id
                ]);
            } else {
                $update_stmt = $db->prepare("
                    UPDATE order_sheet 
                    SET settlement_status = ?, 
                        settlement_notes = CONCAT(COALESCE(settlement_notes, ''), ?),
                        settled_at = NOW(),
                        settled_by = ?
                    WHERE trade_id = ?
                ");
                $update_stmt->execute([$status, "\n" . $notes, $_SESSION['username'] ?? 'system', $trade_id]);
            }
            return true;
        }
        return false;
    } catch (Exception $e) {
        error_log("Error updating order_sheet: " . $e->getMessage());
        return false;
    }
}

// ============================================
// GET GROUPED TRADES
// ============================================
function getGroupedTrades($db, $date_from, $date_to, $hide_buy_orders = true, $trade_side_filter = 'sell_only') {
    $today = date('Y-m-d');
    
    $sql = "
        SELECT 
            MIN(t.id) as id,
            t.client_name,
            t.client_cds_account,
            t.security_id,
            t.security_name,
            t.asset_class,
            t.trade_side,
            DATE(t.trade_date) as trade_date,
            MIN(t.settlement_date) as settlement_date,
            SUM(t.quantity) as total_quantity,
            AVG(t.price) as avg_price,
            SUM(t.consideration) as total_consideration,
            COUNT(t.id) as trade_count,
            GROUP_CONCAT(t.id SEPARATOR ',') as trade_ids,
            GROUP_CONCAT(t.trade_reference SEPARATOR ',') as trade_references,
            MIN(t.exchange_reference) as exchange_reference,
            MIN(t.additional_reference) as additional_reference,
            MAX(t.counterparty_name) as counterparty_name,
            MAX(t.counterparty_cds_account) as counterparty_cds_account,
            MAX(t.settlement_status) as settlement_status,
            MAX(t.settled_by) as settled_by,
            MAX(t.settled_at) as settled_at,
            MAX(t.failure_reason) as failure_reason,
            MAX(t.action_needed) as action_needed,
            MAX(t.settlement_notes) as settlement_notes,
            MAX(t.linked_trade_id) as linked_trade_id,
            MAX(t.linked_trade_ref) as linked_trade_ref
        FROM trades t
        WHERE t.status = 'active'
        AND t.settlement_date IS NOT NULL 
        AND t.settlement_date BETWEEN ? AND ?
        AND (t.settlement_status IS NULL OR t.settlement_status != 'cancelled')
    ";
    
    $params = [$date_from, $date_to];
    
    if ($hide_buy_orders) {
        $sql .= " AND t.trade_side = 'sell' ";
    } elseif ($trade_side_filter === 'buy') {
        $sql .= " AND t.trade_side = 'buy' ";
    } elseif ($trade_side_filter === 'sell') {
        $sql .= " AND t.trade_side = 'sell' ";
    }
    
    $sql .= " GROUP BY 
                t.client_name, 
                t.client_cds_account,
                t.security_id,
                t.security_name,
                t.asset_class,
                t.trade_side,
                DATE(t.trade_date)
              ORDER BY MIN(t.settlement_date) ASC, MIN(t.trade_date) ASC";
    
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ============================================
// AJAX HANDLERS
// ============================================
// ============================================
// AJAX HANDLERS - FIXED
// ============================================
if (isset($_GET['ajax'])) {
    // Clear any output buffers
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    
    header('Content-Type: application/json');
    header('Cache-Control: no-cache, must-revalidate');
    
    if ($_GET['ajax'] == 'get_trade_details') {
        $trade_id = (int)$_GET['trade_id'];
        try {
            $stmt = $db->prepare("
                SELECT t.*, 
                       COUNT(t2.id) as trade_count,
                       GROUP_CONCAT(t2.trade_reference SEPARATOR ',') as all_references
                FROM trades t
                LEFT JOIN trades t2 ON t2.client_name = t.client_name 
                    AND t2.security_id = t.security_id 
                    AND DATE(t2.trade_date) = DATE(t.trade_date)
                    AND t2.id != t.id
                    AND t2.status = 'active'
                WHERE t.id = ?
                GROUP BY t.id
            ");
            $stmt->execute([$trade_id]);
            $trade = $stmt->fetch();
            echo json_encode($trade ?: []);
            exit;
        } catch (Exception $e) {
            error_log("get_trade_details error: " . $e->getMessage());
            echo json_encode(['error' => $e->getMessage()]);
            exit;
        }
    }
    
    if ($_GET['ajax'] == 'get_grouped_buy_trades') {
        $trade_id = (int)$_GET['trade_id'];
        try {
            error_log("=== get_grouped_buy_trades called for trade_id: $trade_id ===");
            
            // Get the sale trade to find the client
            $stmt = $db->prepare("SELECT client_name FROM trades WHERE id = ?");
            $stmt->execute([$trade_id]);
            $sale_trade = $stmt->fetch();
            
            if (!$sale_trade) {
                error_log("Sale trade not found for ID: $trade_id");
                echo json_encode([]);
                exit;
            }
            
            $client_name = $sale_trade['client_name'];
            error_log("Client name: $client_name");
            
            // Get grouped buy trades for this client - ONLY NUMERIC ADDITIONAL REFERENCE
            $sql = "
                SELECT 
                    MIN(t.id) as id,
                    t.client_name,
                    t.client_cds_account,
                    t.security_id,
                    t.security_name,
                    t.asset_class,
                    t.trade_side,
                    DATE(t.trade_date) as trade_date,
                    MIN(t.settlement_date) as settlement_date,
                    SUM(t.quantity) as total_quantity,
                    AVG(t.price) as avg_price,
                    SUM(t.consideration) as total_consideration,
                    COUNT(t.id) as trade_count,
                    GROUP_CONCAT(t.id SEPARATOR ',') as trade_ids,
                    GROUP_CONCAT(t.trade_reference SEPARATOR ',') as trade_references,
                    MIN(t.exchange_reference) as exchange_reference,
                    MIN(t.additional_reference) as additional_reference,
                    MAX(t.settlement_status) as settlement_status
                FROM trades t
                WHERE t.client_name = ?
                AND t.trade_side = 'buy'
                AND t.status = 'active'
                AND t.additional_reference REGEXP '^[0-9]+$'
                AND t.additional_reference IS NOT NULL
                AND t.additional_reference != ''
                AND (t.settlement_status IS NULL OR t.settlement_status NOT IN ('settled', 'linked', 'paid'))
                AND t.id != ?
                GROUP BY 
                    t.client_name, 
                    t.client_cds_account,
                    t.security_id,
                    t.security_name,
                    t.asset_class,
                    DATE(t.trade_date)
                ORDER BY MIN(t.settlement_date) ASC, MIN(t.trade_date) ASC
            ";
            
            error_log("SQL: " . $sql);
            error_log("Params: client_name=$client_name, trade_id=$trade_id");
            
            $stmt = $db->prepare($sql);
            $stmt->execute([$client_name, $trade_id]);
            $trades = $stmt->fetchAll();
            
            error_log("Found " . count($trades) . " buy trades");
            
            // Return empty array if no trades found (not an error)
            echo json_encode($trades);
            exit;
        } catch (Exception $e) {
            error_log("get_grouped_buy_trades error: " . $e->getMessage());
            error_log("Stack trace: " . $e->getTraceAsString());
            echo json_encode([]);
            exit;
        }
    }
    
   
    if ($_GET['ajax'] == 'get_grouped_trade_details') {
        $trade_id = (int)$_GET['trade_id'];
        try {
            $stmt = $db->prepare("
                SELECT client_name, security_id, DATE(trade_date) as trade_date 
                FROM trades WHERE id = ?
            ");
            $stmt->execute([$trade_id]);
            $trade = $stmt->fetch();
            
            if (!$trade) {
                echo json_encode([]);
                exit;
            }
            
            $sql = "
                SELECT * FROM trades 
                WHERE client_name = ? 
                AND security_id = ? 
                AND DATE(trade_date) = ? 
                AND status = 'active'
                ORDER BY trade_date ASC, id ASC
            ";
            $stmt = $db->prepare($sql);
            $stmt->execute([$trade['client_name'], $trade['security_id'], $trade['trade_date']]);
            $trades = $stmt->fetchAll();
            echo json_encode($trades);
            exit;
        } catch (Exception $e) {
            error_log("Error fetching grouped trade details: " . $e->getMessage());
            echo json_encode([]);
            exit;
        }
    }
    
    echo json_encode([]);
    exit;
}

// ============================================
// GET COMPANY AND BANK DETAILS
// ============================================
$company_stmt = $db->query("SELECT * FROM companies WHERE status = 'active' ORDER BY id LIMIT 1");
$company = $company_stmt->fetch();
$company_name = $company ? $company['company_name'] : 'Neovam LTD';

try {
    $bank_accounts_stmt = $db->query("SELECT id, bank_name, account_name, account_number, currency, current_balance FROM banks_accounts WHERE status = 'active' ORDER BY bank_name, account_name");
    $bank_accounts = $bank_accounts_stmt->fetchAll();
} catch (PDOException $e) {
    $bank_accounts = [];
    error_log("Error fetching bank accounts: " . $e->getMessage());
}

try {
    $payment_methods_stmt = $db->query("SELECT id, code, description, cashbook, priority, status FROM payment_methods WHERE status = 'active' ORDER BY priority");
    $payment_methods = $payment_methods_stmt->fetchAll();
} catch (PDOException $e) {
    $payment_methods = [];
    error_log("Error fetching payment methods: " . $e->getMessage());
}

// ============================================
// HANDLE SINGLE PAYMENT
// ============================================
if (isset($_POST['single_payment']) && isset($_POST['trade_id'])) {
    $trade_id = (int)$_POST['trade_id'];
    $bank_account_id = isset($_POST['bank_account']) ? (int)$_POST['bank_account'] : 0;
    $payment_mode = (int)$_POST['payment_mode'];
    $narration = sanitize_input($_POST['narration'] ?? '');
    $user_id = $_SESSION['user_id'];
    $username = $_SESSION['username'] ?? 'system';
    
    $payment_method_stmt = $db->prepare("SELECT description FROM payment_methods WHERE id = ?");
    $payment_method_stmt->execute([$payment_mode]);
    $payment_method = $payment_method_stmt->fetch();
    $payment_method_desc = $payment_method['description'] ?? '';
    
    $bank_account = null;
    if ($bank_account_id > 0) {
        $bank_stmt = $db->prepare("SELECT * FROM banks_accounts WHERE id = ?");
        $bank_stmt->execute([$bank_account_id]);
        $bank_account = $bank_stmt->fetch();
    }
    
    $stmt = $db->prepare("SELECT * FROM trades WHERE id = ?");
    $stmt->execute([$trade_id]);
    $trade = $stmt->fetch();
    
    if ($trade) {
        if ($trade['settlement_status'] === 'paid' || $trade['settlement_status'] === 'linked') {
            $error_message = "Trade is already settled (Status: " . $trade['settlement_status'] . ")";
        } else {
            $db->beginTransaction();
            try {
                $current_time = date('Y-m-d H:i:s');
                $notes = "\nPaid using " . $payment_method_desc . " by user $username on $current_time";
                if ($bank_account) {
                    $notes .= " - Bank: " . $bank_account['bank_name'] . " (" . $bank_account['account_number'] . ")";
                }
                if ($narration) {
                    $notes .= "\nNarration: " . $narration;
                }
                
                $existing_payment_stmt = $db->prepare("SELECT id, payment_no FROM payments WHERE source_id = ? AND source_type = 'trade_settlement'");
                $existing_payment_stmt->execute([$trade_id]);
                $existing_payment = $existing_payment_stmt->fetch();
                
                $payment_no = '';
                if ($existing_payment) {
                    $update_payment_stmt = $db->prepare("
                        UPDATE payments 
                        SET status = 'active',
                            updated_at = NOW(),
                            payment_mode = ?,
                            ac_credit = ?,
                            narration = ?
                        WHERE id = ?
                    ");
                    $update_payment_stmt->execute([$payment_mode, $bank_account_id > 0 ? $bank_account_id : null, $narration, $existing_payment['id']]);
                    $payment_no = $existing_payment['payment_no'];
                } else {
                    $payment_no = generateUniquePaymentNo($db);
                }
                
                $update_stmt = $db->prepare("
                    UPDATE trades 
                    SET settlement_status = 'paid', 
                        settled_by = ?, 
                        settlement_notes = CONCAT(COALESCE(settlement_notes, ''), ?),
                        settled_at = NOW()
                    WHERE id = ?
                ");
                
                if ($update_stmt->execute([$user_id, $notes, $trade_id])) {
                    updateOrderSheetStatus($db, $trade_id, 'settled', $notes);
                    
                    syncSettlementTradeToDealingSheetSafely($db, $trade_id, $current_user);
                    recordBankChargesForTrade($db, $trade_id, $trade['consideration'], $trade['settlement_date'] ?: $trade['trade_date']);
                    
                    if (!$existing_payment) {
                        $amount = $trade['consideration'];
                        $description = $narration ?: "Payment for " . $trade['security_id'] . " shares " . ($trade['trade_side'] === 'sell' ? 'sold' : 'purchased');
                        
                        if ($trade['trade_side'] === 'sell') {
                            $payee_name = $trade['counterparty_name'];
                            $payee_type = 'C';
                        } else {
                            $payee_name = $trade['client_name'];
                            $payee_type = 'C';
                        }
                        
                        $ac_credit_id = $bank_account_id > 0 ? $bank_account_id : 1;
                        if ($bank_account_id <= 0) {
                            $default_bank_stmt = $db->query("SELECT id FROM banks_accounts WHERE status = 'active' LIMIT 1");
                            $default_bank = $default_bank_stmt->fetch();
                            if ($default_bank) {
                                $ac_credit_id = $default_bank['id'];
                            }
                        }
                        
                        $payment_stmt = $db->prepare("
                            INSERT INTO payments (
                                payment_no, payment_date, payment_mode, paid_to,
                                name, record_in_financial, ac_credit,
                                currency, amount, narration,
                                trade_reference, payment_type, created_by_username, created_at, status,
                                bank_name, bank_account_number, source_type, source_id
                            ) VALUES (?, NOW(), ?, ?, ?, 'yes', ?, ?, ?, ?, ?, ?, ?, NOW(), 'active', ?, ?, 'trade_settlement', ?)
                        ");
                        
                        $payment_stmt->execute([
                            $payment_no,
                            $payment_mode,
                            $payee_type,
                            $payee_name,
                            $ac_credit_id,
                            'Tsh',
                            $amount,
                            $description,
                            $trade['trade_reference'] ?? null,
                            'settlement',
                            $username,
                            $bank_account ? $bank_account['bank_name'] : '',
                            $bank_account ? $bank_account['account_number'] : '',
                            $trade_id
                        ]);
                    }
                    
                    if ($bank_account_id > 0 && $bank_account) {
                        $is_payment_out = ($trade['trade_side'] === 'sell');
                        updateBankBalance($db, $bank_account_id, $trade['consideration'], $is_payment_out);
                        if (!$existing_payment) {
                            createJournalEntry($db, $payment_no, $trade, $bank_account, $trade['consideration'], 
                                $narration ?: "Payment for " . $trade['security_id'] . " shares " . ($trade['trade_side'] === 'sell' ? 'sold' : 'purchased'));
                        }
                    }
                    
                    $db->commit();
                    $success_message = 'Payment recorded successfully! Payment No: ' . $payment_no;
                } else {
                    throw new Exception("Error updating trade as paid.");
                }
            } catch (Exception $e) {
                $db->rollBack();
                $error_message = "Error: " . $e->getMessage();
                error_log("Payment error: " . $e->getMessage());
            }
        }
    } else {
        $error_message = 'Trade not found.';
    }
    
    header('Location: settlement.php?message=' . urlencode($success_message ?: $error_message) . '&type=' . ($success_message ? 'success' : 'danger'));
    exit;
}

// ============================================
// HANDLE LINK TRADE
// ============================================
if (isset($_POST['link_trade']) && isset($_POST['trade_id']) && isset($_POST['linked_trade_ids'])) {
    $trade_id = (int)$_POST['trade_id'];
    $linked_trade_ids = $_POST['linked_trade_ids'];
    $user_id = $_SESSION['user_id'];
    $username = $_SESSION['username'] ?? 'system';
    $linked_refs = [];
    $linked_ids = [];
    
    try {
        $db->beginTransaction();
        
        $trade_stmt = $db->prepare("SELECT * FROM trades WHERE id = ?");
        $trade_stmt->execute([$trade_id]);
        $trade = $trade_stmt->fetch();
        
        if (!$trade) {
            throw new Exception("Sale trade not found.");
        }
        
        foreach ($linked_trade_ids as $linked_trade_id) {
            $linked_trade_id = (int)$linked_trade_id;
            $linked_trade_stmt = $db->prepare("SELECT * FROM trades WHERE id = ?");
            $linked_trade_stmt->execute([$linked_trade_id]);
            $linked_trade = $linked_trade_stmt->fetch();
            
            if (!$linked_trade || $linked_trade['trade_side'] !== 'buy') {
                continue;
            }
            
            if ($linked_trade['client_name'] !== $trade['client_name']) {
                continue;
            }
            
            $link_stmt = $db->prepare("
                INSERT INTO linked_trades (trade_id, linked_trade_id, linked_by, linked_at)
                VALUES (?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE linked_trade_id = ?, linked_at = NOW()
            ");
            $link_stmt->execute([$trade_id, $linked_trade_id, $user_id, $linked_trade_id]);
            
            $update_buy = $db->prepare("
                UPDATE trades 
                SET settlement_status = 'linked',
                    linked_trade_id = ?,
                    linked_trade_ref = ?,
                    settlement_notes = CONCAT(COALESCE(settlement_notes, ''), ?),
                    settled_by = ?,
                    settled_at = NOW()
                WHERE id = ?
            ");
            $update_buy->execute([
                $trade_id,
                $trade['trade_reference'],
                "\nLinked to sale trade ID: $trade_id (Ref: " . $trade['trade_reference'] . ") by user $username on " . date('Y-m-d H:i:s'),
                $user_id,
                $linked_trade_id
            ]);
            
            updateOrderSheetStatus($db, $linked_trade_id, 'linked', 
                "\nLinked to sale trade ID: $trade_id (Ref: " . $trade['trade_reference'] . ") by user $username",
                $trade_id,
                $trade['trade_reference']
            );
            
            $linked_ids[] = $linked_trade_id;
            $linked_refs[] = $linked_trade['trade_reference'];
            
            syncSettlementTradeToDealingSheetSafely($db, $linked_trade_id, $current_user);
        }
        
        $all_linked_refs = implode(', ', $linked_refs);
        $sale_notes = "\nLinked to buy trades: $all_linked_refs by user $username on " . date('Y-m-d H:i:s');
        $update_sale = $db->prepare("
            UPDATE trades 
            SET settlement_status = 'linked',
                linked_trade_id = ?,
                linked_trade_ref = ?,
                settlement_notes = CONCAT(COALESCE(settlement_notes, ''), ?),
                settled_by = ?,
                settled_at = NOW()
            WHERE id = ?
        ");
        $update_sale->execute([
            $linked_ids[0] ?? null,
            $all_linked_refs,
            $sale_notes,
            $user_id,
            $trade_id
        ]);
        
        updateOrderSheetStatus($db, $trade_id, 'linked', 
            "\nLinked to buy trades: $all_linked_refs by user $username",
            $linked_ids[0] ?? null,
            $all_linked_refs
        );
        
        syncSettlementTradeToDealingSheetSafely($db, $trade_id, $current_user);
        
        $db->commit();
        $success_message = "Trade successfully linked! Sale trade #$trade_id linked to " . count($linked_trade_ids) . " buy trade(s).";
        
    } catch (Exception $e) {
        $db->rollBack();
        $error_message = "Error linking trades: " . $e->getMessage();
        error_log("Link trade error: " . $e->getMessage());
    }
    
    header('Location: settlement.php?message=' . urlencode($success_message ?: $error_message) . '&type=' . ($success_message ? 'success' : 'danger'));
    exit;
}

// ============================================
// HANDLE MARK AS UNPAID
// ============================================
if (isset($_POST['mark_unpaid']) && isset($_POST['trade_id'])) {
    $trade_id = (int)$_POST['trade_id'];
    $user_id = $_SESSION['user_id'];
    $username = $_SESSION['username'] ?? 'system';
    
    try {
        $trade_stmt = $db->prepare("SELECT * FROM trades WHERE id = ?");
        $trade_stmt->execute([$trade_id]);
        $trade = $trade_stmt->fetch();
        
        if ($trade) {
            $notes = "\nPayment undone by user $username on " . date('Y-m-d H:i:s');
            $update_stmt = $db->prepare("
                UPDATE trades 
                SET settlement_status = 'unpaid', 
                    settlement_notes = CONCAT(COALESCE(settlement_notes, ''), ?),
                    settled_by = NULL,
                    settled_at = NULL
                WHERE id = ?
            ");
            
            if ($update_stmt->execute([$notes, $trade_id])) {
                updateOrderSheetStatus($db, $trade_id, 'unpaid', $notes);
                syncSettlementTradeToDealingSheetSafely($db, $trade_id, $current_user);
                
                $payment_stmt = $db->prepare("
                    UPDATE payments 
                    SET status = 'inactive',
                        updated_at = NOW()
                    WHERE source_id = ? AND source_type = 'trade_settlement'
                ");
                $payment_stmt->execute([$trade_id]);
                
                $success_message = 'Payment undone successfully. Trade marked as unpaid.';
            } else {
                $error_message = 'Error marking trade as unpaid.';
            }
        } else {
            $error_message = 'Trade not found.';
        }
    } catch (Exception $e) {
        $error_message = 'Error: ' . $e->getMessage();
    }
    
    header('Location: settlement.php?message=' . urlencode($success_message ?: $error_message) . '&type=' . ($success_message ? 'success' : 'danger'));
    exit;
}

// ============================================
// HANDLE MARK AS FAILED
// ============================================
if (isset($_POST['mark_failed']) && isset($_POST['trade_id'])) {
    $trade_id = (int)$_POST['trade_id'];
    $failure_reason = sanitize_input($_POST['failure_reason'] ?? '');
    $action_needed = sanitize_input($_POST['action_needed'] ?? '');
    $user_id = $_SESSION['user_id'];
    $username = $_SESSION['username'] ?? 'system';
    
    try {
        $notes = "\nMarked as failed by user $username on " . date('Y-m-d H:i:s') . ": $failure_reason | Action: $action_needed";
        
        $stmt = $db->prepare("
            UPDATE trades 
            SET settlement_status = 'failed', 
                failure_reason = ?, 
                action_needed = ?,
                settlement_notes = CONCAT(COALESCE(settlement_notes, ''), ?),
                settled_by = ?
            WHERE id = ?
        ");
        
        if ($stmt->execute([$failure_reason, $action_needed, $notes, $user_id, $trade_id])) {
            updateOrderSheetStatus($db, $trade_id, 'failed', $notes);
            syncSettlementTradeToDealingSheetSafely($db, $trade_id, $current_user);
            $success_message = 'Trade marked as failed with reason.';
        } else {
            $error_message = 'Error marking trade as failed.';
        }
    } catch (Exception $e) {
        $error_message = 'Error: ' . $e->getMessage();
    }
    
    header('Location: settlement.php?message=' . urlencode($success_message ?: $error_message) . '&type=' . ($success_message ? 'success' : 'danger'));
    exit;
}

// ============================================
// HANDLE RETRY FAILED
// ============================================
if (isset($_POST['retry_failed']) && isset($_POST['trade_id'])) {
    $trade_id = (int)$_POST['trade_id'];
    $username = $_SESSION['username'] ?? 'system';
    
    try {
        $notes = "\nRetried from failed status by user $username on " . date('Y-m-d H:i:s');
        
        $stmt = $db->prepare("
            UPDATE trades 
            SET settlement_status = 'unpaid', 
                failure_reason = NULL,
                action_needed = NULL,
                settlement_notes = CONCAT(COALESCE(settlement_notes, ''), ?),
                settled_by = NULL
            WHERE id = ?
        ");
        
        if ($stmt->execute([$notes, $trade_id])) {
            updateOrderSheetStatus($db, $trade_id, 'unpaid', $notes);
            syncSettlementTradeToDealingSheetSafely($db, $trade_id, $current_user);
            $success_message = 'Trade ready for payment retry.';
        } else {
            $error_message = 'Error resetting failed trade.';
        }
    } catch (Exception $e) {
        $error_message = 'Error: ' . $e->getMessage();
    }
    
    header('Location: settlement.php?message=' . urlencode($success_message ?: $error_message) . '&type=' . ($success_message ? 'success' : 'danger'));
    exit;
}

// ============================================
// GET FILTER VALUES AND DATA
// ============================================
$today = date('Y-m-d');
$two_days_ago = date('Y-m-d', strtotime('-30 days'));
$next_30_days = date('Y-m-d', strtotime('+30 days'));
$trade_side_filter = isset($_GET['side']) ? $_GET['side'] : 'sell_only';
$hide_buy_orders = isset($_GET['hide_buy']) ? $_GET['hide_buy'] : '1';
$filter_tab = isset($_GET['tab']) ? $_GET['tab'] : 'all';

$records_per_page = 50;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) $page = 1;
$offset = ($page - 1) * $records_per_page;

$grouped_trades = getGroupedTrades($db, $two_days_ago, $next_30_days, $hide_buy_orders, $trade_side_filter);

// Filter by tab
$filtered_trades = [];
foreach ($grouped_trades as $trade) {
    $status = $trade['settlement_status'] ?? 'pending';
    
    if ($filter_tab === 'all') {
        $filtered_trades[] = $trade;
    } elseif ($filter_tab === 'overdue') {
        if ($status !== 'paid' && $status !== 'linked' && $status !== 'failed') {
            $settlement_date = $trade['settlement_date'] ?? $trade['trade_date'];
            if ($settlement_date < $today) {
                $filtered_trades[] = $trade;
            }
        }
    } elseif ($filter_tab === 'today') {
        if ($status !== 'paid' && $status !== 'linked' && $status !== 'failed') {
            $settlement_date = $trade['settlement_date'] ?? $trade['trade_date'];
            if ($settlement_date == $today) {
                $filtered_trades[] = $trade;
            }
        }
    } elseif ($filter_tab === 'paid') {
        if ($status === 'paid') {
            $filtered_trades[] = $trade;
        }
    } elseif ($filter_tab === 'linked') {
        if ($status === 'linked') {
            $filtered_trades[] = $trade;
        }
    } elseif ($filter_tab === 'failed') {
        if ($status === 'failed') {
            $filtered_trades[] = $trade;
        }
    }
}

$total_records = count($filtered_trades);
$total_pages = ceil($total_records / $records_per_page);
$paginated_trades = array_slice($filtered_trades, $offset, $records_per_page);

// Calculate stats from ALL trades
$stats = [
    'total_count' => 0,
    'total_value' => 0,
    'paid_count' => 0,
    'paid_value' => 0,
    'linked_count' => 0,
    'linked_value' => 0,
    'failed_count' => 0,
    'failed_value' => 0,
    'overdue_count' => 0,
    'overdue_value' => 0,
    'today_count' => 0,
    'today_value' => 0,
    'upcoming_count' => 0,
    'upcoming_value' => 0
];

foreach ($grouped_trades as $trade) {
    $stats['total_count']++;
    $stats['total_value'] += floatval($trade['total_consideration']);
    
    if ($trade['settlement_status'] === 'paid') {
        $stats['paid_count']++;
        $stats['paid_value'] += floatval($trade['total_consideration']);
    } elseif ($trade['settlement_status'] === 'linked') {
        $stats['linked_count']++;
        $stats['linked_value'] += floatval($trade['total_consideration']);
    } elseif ($trade['settlement_status'] === 'failed') {
        $stats['failed_count']++;
        $stats['failed_value'] += floatval($trade['total_consideration']);
    } else {
        $settlement_date = $trade['settlement_date'] ?? $trade['trade_date'];
        if ($settlement_date < $today) {
            $stats['overdue_count']++;
            $stats['overdue_value'] += floatval($trade['total_consideration']);
        } elseif ($settlement_date == $today) {
            $stats['today_count']++;
            $stats['today_value'] += floatval($trade['total_consideration']);
        } else {
            $stats['upcoming_count']++;
            $stats['upcoming_value'] += floatval($trade['total_consideration']);
        }
    }
}

$page_title = 'Trade Settlement';

// Output JS early (echo here is safe: POST/AJAX handlers already redirected/exited)
if (!isset($_GET['ajax'])) {
    echo '<script>' . "\n";
    echo file_get_contents(__DIR__ . '/../assets/js/settlement.js');
    echo "\n" . '</script>' . "\n";
}

include '../includes/header.php';
?>

<style>
    .floating-bulk-payment {
        position: fixed;
        bottom: 30px;
        right: 30px;
        z-index: 1000;
    }
    .floating-bulk-payment .btn {
        width: 60px;
        height: 60px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.5rem;
    }
    .floating-bulk-payment .badge {
        font-size: 0.7rem;
        padding: 0.25em 0.5em;
    }
    .badge-group {
        background-color: #e9ecef;
        color: #495057;
        font-size: 10px;
        padding: 2px 6px;
        border-radius: 10px;
        margin-left: 4px;
    }
    .grouped-trade-details {
        font-size: 12px;
        color: #6c757d;
    }
    .trade-checkbox:checked {
        background-color: var(--success-color);
        border-color: var(--success-color);
    }
    .pagination .page-item.active .page-link {
        background-color: var(--success-color);
        border-color: var(--success-color);
    }
    .linked-badge {
        background: #6f42c1;
        color: white;
        padding: 2px 8px;
        border-radius: 4px;
        font-size: 11px;
        font-weight: 600;
    }
    .linked-badge i {
        margin-right: 4px;
    }
</style>

<div class="page-header">
    <div class="container-fluid">
        <div class="row align-items-center">
            <div class="col-md-8">
                <div class="d-flex align-items-center">
                    <div class="me-3">
                        <div class="d-inline-flex align-items-center justify-content-center rounded-circle shadow-sm" 
                             style="width: 60px; height: 60px; background: linear-gradient(135deg, var(--success-color) 0%, #10b981 100%);">
                            <i class="bi bi-cash-coin" style="font-size: 1.5rem;"></i>
                        </div>
                    </div>
                    <div>
                        <h1 class="page-title mb-1">Trade Settlement</h1>
                        <p class="page-subtitle">Manage trade settlements and payments - <?php echo htmlspecialchars($company_name); ?></p>
                        <small class="text-muted">Trades are grouped by Client, Security, and Trade Date</small>
                    </div>
                </div>
            </div>
            <div class="col-md-4 text-end">
                <div class="d-flex gap-2 justify-content-end flex-wrap">
                    <a href="trades" class="btn btn-outline-secondary d-flex align-items-center">
                        <i class="bi bi-arrow-left me-2"></i>
                        <span class="d-none d-sm-inline">Back to Trades</span>
                    </a>
                    <button type="button" class="btn btn-primary d-flex align-items-center" data-bs-toggle="modal" data-bs-target="#exportModal">
                        <i class="bi bi-download me-2"></i>
                        <span class="d-none d-sm-inline">Export Report</span>
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="container-fluid">
    <?php if (isset($_GET['message'])): ?>
        <div class="alert alert-<?php echo $_GET['type'] ?? 'info'; ?> alert-dismissible fade show mb-4" role="alert">
            <?php echo htmlspecialchars($_GET['message']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <!-- Filter Section -->
    <div class="card mb-4">
        <div class="card-header bg-light">
            <div class="row align-items-center">
                <div class="col-md-6">
                    <h5 class="mb-0">Filters</h5>
                </div>
                <div class="col-md-6 text-end">
                    <form method="GET" class="d-inline">
                        <input type="hidden" name="page" value="1">
                        <input type="hidden" name="tab" value="<?php echo htmlspecialchars($filter_tab); ?>">
                        <div class="row g-2 justify-content-end">
                            <div class="col-auto">
                                <select class="form-select form-select-sm" name="side" onchange="this.form.submit()">
                                    <option value="all" <?php echo $trade_side_filter === 'all' ? 'selected' : ''; ?>>All Trades</option>
                                    <option value="sell_only" <?php echo $trade_side_filter === 'sell_only' ? 'selected' : ''; ?>>Sell Only</option>
                                </select>
                            </div>
                            <div class="col-auto">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="hide_buy" value="1" id="hideBuyCheck" 
                                           <?php echo $hide_buy_orders === '1' ? 'checked' : ''; ?> onchange="this.form.submit()">
                                    <label class="form-check-label" for="hideBuyCheck">
                                        Hide Buy Orders
                                    </label>
                                </div>
                            </div>
                            <div class="col-auto">
                                <button type="button" class="btn btn-sm btn-outline-secondary" onclick="resetFilters()">
                                    <i class="bi bi-x-circle"></i> Reset
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Summary Cards -->
    <div class="row mb-4">
        <div class="col-xl-2 col-md-4 mb-4">
            <div class="card border-left-primary shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs fw-bold text-primary text-uppercase mb-1">Total Value</div>
                            <div class="h5 mb-0 fw-bold text-gray-800">TZS <?php echo number_format($stats['total_value'], 2); ?></div>
                            <div class="mt-2 text-muted small"><?php echo $stats['total_count']; ?> trade groups</div>
                        </div>
                        <div class="col-auto">
                            <i class="bi bi-currency-exchange fa-2x text-gray-300"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-2 col-md-4 mb-4">
            <div class="card border-left-danger shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs fw-bold text-danger text-uppercase mb-1">Overdue</div>
                            <div class="h5 mb-0 fw-bold text-gray-800"><?php echo $stats['overdue_count']; ?></div>
                            <div class="mt-2 text-muted small">TZS <?php echo number_format($stats['overdue_value'], 2); ?></div>
                        </div>
                        <div class="col-auto">
                            <i class="bi bi-exclamation-triangle fa-2x text-gray-300"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-2 col-md-4 mb-4">
            <div class="card border-left-warning shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs fw-bold text-warning text-uppercase mb-1">Due Today</div>
                            <div class="h5 mb-0 fw-bold text-gray-800"><?php echo $stats['today_count']; ?></div>
                            <div class="mt-2 text-muted small">TZS <?php echo number_format($stats['today_value'], 2); ?></div>
                        </div>
                        <div class="col-auto">
                            <i class="bi bi-calendar-day fa-2x text-gray-300"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-2 col-md-4 mb-4">
            <div class="card border-left-success shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs fw-bold text-success text-uppercase mb-1">Paid</div>
                            <div class="h5 mb-0 fw-bold text-gray-800"><?php echo $stats['paid_count']; ?></div>
                            <div class="mt-2 text-muted small">TZS <?php echo number_format($stats['paid_value'], 2); ?></div>
                        </div>
                        <div class="col-auto">
                            <i class="bi bi-check-circle fa-2x text-gray-300"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-2 col-md-4 mb-4">
            <div class="card border-left-info shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs fw-bold text-info text-uppercase mb-1">Linked</div>
                            <div class="h5 mb-0 fw-bold text-gray-800"><?php echo $stats['linked_count']; ?></div>
                            <div class="mt-2 text-muted small">TZS <?php echo number_format($stats['linked_value'], 2); ?></div>
                        </div>
                        <div class="col-auto">
                            <i class="bi bi-link fa-2x text-gray-300"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-2 col-md-4 mb-4">
            <div class="card border-left-dark shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs fw-bold text-dark text-uppercase mb-1">Failed</div>
                            <div class="h5 mb-0 fw-bold text-gray-800"><?php echo $stats['failed_count']; ?></div>
                            <div class="mt-2 text-muted small">TZS <?php echo number_format($stats['failed_value'], 2); ?></div>
                        </div>
                        <div class="col-auto">
                            <i class="bi bi-x-circle fa-2x text-gray-300"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Floating Bulk Payment Button -->
    <?php if ($user_role !== 'trader'): ?>
    <div class="floating-bulk-payment" id="floatingBulkPayment" style="display: none;">
        <button type="button" class="btn btn-success btn-lg rounded-circle shadow-lg" onclick="showBulkPaymentModal()" data-bs-toggle="tooltip" data-bs-placement="left" title="Pay Selected Trades">
            <i class="bi bi-cash-coin"></i>
            <span class="badge bg-danger position-absolute top-0 start-100 translate-middle" id="selectedCountBadge">0</span>
        </button>
    </div>
    <?php endif; ?>

    <!-- Tabs -->
    <div class="card mb-4">
        <div class="card-header bg-transparent border-0">
            <ul class="nav nav-tabs nav-tabs-custom" id="settlementTabs" role="tablist">
                <li class="nav-item">
                    <a class="nav-link <?php echo $filter_tab === 'all' ? 'active' : ''; ?>" href="?tab=all&side=<?php echo urlencode($trade_side_filter); ?>&hide_buy=<?php echo urlencode($hide_buy_orders); ?>" role="tab">
                        <i class="bi bi-list-check me-2"></i>All Settlements
                        <span class="badge bg-primary ms-2"><?php echo $stats['total_count']; ?></span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo $filter_tab === 'overdue' ? 'active' : ''; ?>" href="?tab=overdue&side=<?php echo urlencode($trade_side_filter); ?>&hide_buy=<?php echo urlencode($hide_buy_orders); ?>" role="tab">
                        <i class="bi bi-exclamation-triangle me-2"></i>Overdue
                        <span class="badge bg-danger ms-2"><?php echo $stats['overdue_count']; ?></span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo $filter_tab === 'today' ? 'active' : ''; ?>" href="?tab=today&side=<?php echo urlencode($trade_side_filter); ?>&hide_buy=<?php echo urlencode($hide_buy_orders); ?>" role="tab">
                        <i class="bi bi-calendar-day me-2"></i>Due Today
                        <span class="badge bg-warning ms-2"><?php echo $stats['today_count']; ?></span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo $filter_tab === 'paid' ? 'active' : ''; ?>" href="?tab=paid&side=<?php echo urlencode($trade_side_filter); ?>&hide_buy=<?php echo urlencode($hide_buy_orders); ?>" role="tab">
                        <i class="bi bi-check-circle me-2"></i>Paid
                        <span class="badge bg-success ms-2"><?php echo $stats['paid_count']; ?></span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo $filter_tab === 'linked' ? 'active' : ''; ?>" href="?tab=linked&side=<?php echo urlencode($trade_side_filter); ?>&hide_buy=<?php echo urlencode($hide_buy_orders); ?>" role="tab">
                        <i class="bi bi-link me-2"></i>Linked
                        <span class="badge bg-info ms-2"><?php echo $stats['linked_count']; ?></span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo $filter_tab === 'failed' ? 'active' : ''; ?>" href="?tab=failed&side=<?php echo urlencode($trade_side_filter); ?>&hide_buy=<?php echo urlencode($hide_buy_orders); ?>" role="tab">
                        <i class="bi bi-x-circle me-2"></i>Failed
                        <span class="badge bg-dark ms-2"><?php echo $stats['failed_count']; ?></span>
                    </a>
                </li>
            </ul>
        </div>
        
        <div class="card-body">
            <div class="tab-content">
                <div class="tab-pane fade show active" role="tabpanel">
                    <?php if (empty($paginated_trades)): ?>
                        <div class="text-center py-5">
                            <i class="bi bi-check-circle text-muted" style="font-size: 4rem; opacity: 0.3;"></i>
                            <h5 class="text-muted mt-3">No <?php echo $filter_tab === 'all' ? '' : $filter_tab; ?> trades found</h5>
                            <p class="text-muted"><?php echo $filter_tab === 'all' ? 'All trades are settled or no settlements due within the period.' : 'No trades match this filter.'; ?></p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover" id="allSettlementsTable">
                                <thead>
                                    <tr>
                                        <th width="30">
                                            <input type="checkbox" id="selectAll" onchange="toggleSelectAll(this)">
                                        </th>
                                        <th>Trade Ref</th>
                                        <th>Client</th>
                                        <th>Security</th>
                                        <th>Side</th>
                                        <th class="text-end">Quantity</th>
                                        <th class="text-end">Amount</th>
                                        <th>Settlement Date</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($paginated_trades as $trade): 
                                        $status_color = '';
                                        $status_icon = '';
                                        $status_text = '';
                                        $trade_count = (int)($trade['trade_count'] ?? 1);
                                        $isLinked = ($trade['settlement_status'] === 'linked' && !empty($trade['linked_trade_id']));
                                        
                                        if ($trade['settlement_status'] === 'paid') {
                                            $status_color = 'success';
                                            $status_icon = 'bi-check-circle';
                                            $status_text = 'Paid';
                                        } elseif ($trade['settlement_status'] === 'linked') {
                                            $status_color = 'info';
                                            $status_icon = 'bi-link';
                                            $status_text = 'Linked';
                                        } elseif ($trade['settlement_status'] === 'failed') {
                                            $status_color = 'dark';
                                            $status_icon = 'bi-x-circle';
                                            $status_text = 'Failed';
                                        } elseif ($trade['settlement_status'] === 'unpaid') {
                                            $status_color = 'secondary';
                                            $status_icon = 'bi-arrow-counterclockwise';
                                            $status_text = 'Unpaid';
                                        } else {
                                            $settlement_date = $trade['settlement_date'] ?? $trade['trade_date'];
                                            if ($settlement_date < $today) {
                                                $status_color = 'danger';
                                                $status_icon = 'bi-exclamation-triangle';
                                                $status_text = 'Overdue';
                                            } elseif ($settlement_date == $today) {
                                                $status_color = 'warning';
                                                $status_icon = 'bi-calendar-day';
                                                $status_text = 'Due Today';
                                            } else {
                                                $status_color = 'info';
                                                $status_icon = 'bi-calendar';
                                                $status_text = 'Upcoming';
                                            }
                                        }
                                    ?>
                                        <tr>
                                            <td>
                                                <input type="checkbox" class="trade-checkbox" value="<?php echo $trade['id']; ?>" onchange="updateBulkActions()">
                                            </td>
                                            <td>
                                                <div class="fw-semibold">
                                                    <?php 
                                                    $refs = explode(',', $trade['trade_references'] ?? '');
                                                    if ($trade_count > 1) {
                                                        echo htmlspecialchars($refs[0] ?? '') . ' <span class="badge-group">+' . ($trade_count - 1) . ' more</span>';
                                                    } else {
                                                        echo htmlspecialchars($trade['trade_reference'] ?? '');
                                                    }
                                                    ?>
                                                </div>
                                                <?php if ($trade_count > 1): ?>
                                                    <div class="grouped-trade-details">
                                                        <i class="bi bi-layers"></i> <?php echo $trade_count; ?> trades grouped
                                                        <button type="button" class="btn btn-link btn-sm p-0" onclick="showGroupedTrades(<?php echo $trade['id']; ?>)">
                                                            <i class="bi bi-eye"></i> View all
                                                        </button>
                                                    </div>
                                                <?php endif; ?>
                                                <?php if ($isLinked): ?>
                                                    <div class="mt-1">
                                                        <span class="linked-badge"><i class="bi bi-link-45deg"></i> LINKED</span>
                                                        <small class="text-muted d-block">To: <?php echo htmlspecialchars($trade['linked_trade_ref'] ?? '#' . $trade['linked_trade_id']); ?></small>
                                                    </div>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div class="fw-medium"><?php echo htmlspecialchars($trade['client_name']); ?></div>
                                                <small class="text-muted"><?php echo htmlspecialchars($trade['client_cds_account']); ?></small>
                                            </td>
                                            <td>
                                                <div class="fw-medium"><?php echo htmlspecialchars($trade['security_id']); ?></div>
                                                <small class="text-muted">
                                                    <?php 
                                                    $asset_class = $trade['asset_class'];
                                                    echo ($asset_class === 'Exchange Traded Funds') ? 'ETF' : ucfirst($asset_class);
                                                    ?>
                                                </small>
                                            </td>
                                            <td>
                                                <span class="badge bg-<?php echo strtolower($trade['trade_side']) == 'buy' ? 'success' : 'danger'; ?>">
                                                    <?php echo ucfirst($trade['trade_side']); ?>
                                                </span>
                                            </td>
                                            <td class="text-end">
                                                <?php 
                                                $qty = floatval($trade['total_quantity'] ?? 0);
                                                if ($trade['asset_class'] === 'bond') {
                                                    echo 'TZS ' . number_format($qty, 2);
                                                } else {
                                                    echo number_format($qty, 0);
                                                }
                                                ?>
                                                <?php if ($trade_count > 1): ?>
                                                    <br><small class="text-muted">avg: <?php echo number_format(floatval($trade['avg_price'] ?? 0), 2); ?></small>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-end">
                                                <div class="fw-bold text-success">TZS <?php echo number_format($trade['total_consideration'], 2); ?></div>
                                            </td>
                                            <td>
                                                <div class="fw-medium">
                                                    <?php 
                                                    if (!empty($trade['settlement_date']) && $trade['settlement_date'] != '0000-00-00') {
                                                        echo date('Y-m-d', strtotime($trade['settlement_date']));
                                                    } else {
                                                        echo 'N/A';
                                                    }
                                                    ?>
                                                </div>
                                                <small class="text-muted">
                                                    <?php 
                                                    if (!empty($trade['settlement_date']) && $trade['settlement_date'] != '0000-00-00') {
                                                        $days_diff = (strtotime($trade['settlement_date']) - strtotime($today)) / (60 * 60 * 24);
                                                        if ($days_diff < 0) {
                                                            echo abs($days_diff) . ' days overdue';
                                                        } elseif ($days_diff == 0) {
                                                            echo 'Today';
                                                        } else {
                                                            echo $days_diff . ' days';
                                                        }
                                                    } else {
                                                        echo 'No date set';
                                                    }
                                                    ?>
                                                </small>
                                            </td>
                                            <td>
                                                <span class="badge bg-<?php echo $status_color; ?>">
                                                    <i class="bi <?php echo $status_icon; ?> me-1"></i>
                                                    <?php echo $status_text; ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php if ($trade['settlement_status'] === 'linked'): ?>
                                                    <div class="btn-group btn-group-sm">
                                                        <button type="button" class="btn btn-outline-info btn-sm" onclick="showLinkedDetails(<?php echo $trade['id']; ?>)">
                                                            <i class="bi bi-eye"></i> View Link
                                                        </button>
                                                        <a href="trades.php?action=contract_note&id=<?php echo $trade['id']; ?>" class="btn btn-outline-primary btn-sm" title="Contract Note - Sold">
                                                            <i class="bi bi-file-earmark-text"></i>
                                                        </a>
                                                        <a href="trades.php?action=contract_note&id=<?php echo $trade['linked_trade_id']; ?>" class="btn btn-outline-success btn-sm" title="Contract Note - Bought">
                                                            <i class="bi bi-file-earmark-text"></i>
                                                        </a>
                                                    </div>
                                                <?php elseif ($trade['settlement_status'] === 'paid'): ?>
                                                    <div class="btn-group btn-group-sm">
                                                        <button type="button" class="btn btn-outline-secondary btn-sm" onclick="markAsUnpaid(<?php echo $trade['id']; ?>)">
                                                            <i class="bi bi-arrow-counterclockwise"></i> Undo
                                                        </button>
                                                        <a href="trades.php?action=contract_note&id=<?php echo $trade['id']; ?>" class="btn btn-outline-primary btn-sm" title="Generate Contract Note">
                                                            <i class="bi bi-file-earmark-text"></i>
                                                        </a>
                                                    </div>
                                                <?php elseif ($trade['settlement_status'] === 'failed'): ?>
                                                    <div class="btn-group btn-group-sm">
                                                        <button type="button" class="btn btn-outline-warning btn-sm" data-bs-toggle="modal" data-bs-target="#failureDetailsModal" 
                                                                 onclick="showFailureDetails(<?php echo $trade['id']; ?>, '<?php echo addslashes($trade['failure_reason'] ?? ''); ?>', '<?php echo addslashes($trade['action_needed'] ?? ''); ?>')">
                                                            <i class="bi bi-info-circle"></i> Details
                                                        </button>
                                                        <button type="button" class="btn btn-outline-success btn-sm" onclick="retryFailed(<?php echo $trade['id']; ?>)">
                                                            <i class="bi bi-arrow-repeat"></i> Retry
                                                        </button>
                                                        <a href="trades.php?action=contract_note&id=<?php echo $trade['id']; ?>" class="btn btn-outline-primary btn-sm" title="Generate Contract Note">
                                                            <i class="bi bi-file-earmark-text"></i>
                                                        </a>
                                                    </div>
                                                <?php elseif ($trade['trade_side'] === 'sell'): ?>
                                                    <div class="btn-group btn-group-sm">
                                                        <?php if ($user_role !== 'trader'): ?>
                                                        <button type="button" class="btn btn-outline-success" onclick="showPaymentModal(<?php echo $trade['id']; ?>)">
                                                            <i class="bi bi-cash-coin"></i> Pay
                                                        </button>
                                                        <?php endif; ?>
                                                        <button type="button" class="btn btn-outline-info" onclick="showLinkTradeModal(<?php echo $trade['id']; ?>)">
                                                            <i class="bi bi-link"></i> Link
                                                        </button>
                                                        <button type="button" class="btn btn-outline-danger" onclick="markAsFailed(<?php echo $trade['id']; ?>)">
                                                            <i class="bi bi-x-lg"></i> Cancel
                                                        </button>
                                                        <a href="trades.php?action=contract_note&id=<?php echo $trade['id']; ?>" class="btn btn-outline-primary btn-sm" title="Generate Contract Note">
                                                            <i class="bi bi-file-earmark-text"></i>
                                                        </a>
                                                    </div>
                                                <?php else: ?>
                                                    <div class="btn-group btn-group-sm">
                                                        <?php if ($user_role !== 'trader'): ?>
                                                        <button type="button" class="btn btn-outline-success" onclick="showPaymentModal(<?php echo $trade['id']; ?>)">
                                                            <i class="bi bi-cash-coin"></i> Pay
                                                        </button>
                                                        <?php endif; ?>
                                                        <button type="button" class="btn btn-outline-danger" onclick="markAsFailed(<?php echo $trade['id']; ?>)">
                                                            <i class="bi bi-x-lg"></i> Cancel
                                                        </button>
                                                        <a href="trades.php?action=contract_note&id=<?php echo $trade['id']; ?>" class="btn btn-outline-primary btn-sm" title="Generate Contract Note">
                                                            <i class="bi bi-file-earmark-text"></i>
                                                        </a>
                                                    </div>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        
                        <?php if ($total_pages > 1): ?>
                        <nav aria-label="Page navigation">
                            <ul class="pagination justify-content-center">
                                <?php if ($page > 1): ?>
                                    <li class="page-item">
                                        <a class="page-link" href="?page=1&tab=<?php echo urlencode($filter_tab); ?>&side=<?php echo urlencode($trade_side_filter); ?>&hide_buy=<?php echo urlencode($hide_buy_orders); ?>" aria-label="First">
                                            <span aria-hidden="true">&laquo;&laquo;</span>
                                        </a>
                                    </li>
                                    <li class="page-item">
                                        <a class="page-link" href="?page=<?php echo $page - 1; ?>&tab=<?php echo urlencode($filter_tab); ?>&side=<?php echo urlencode($trade_side_filter); ?>&hide_buy=<?php echo urlencode($hide_buy_orders); ?>" aria-label="Previous">
                                            <span aria-hidden="true">&laquo;</span>
                                        </a>
                                    </li>
                                <?php endif; ?>
                                
                                <?php
                                $start_page = max(1, $page - 2);
                                $end_page = min($total_pages, $start_page + 4);
                                
                                for ($i = $start_page; $i <= $end_page; $i++): ?>
                                    <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                                        <a class="page-link" href="?page=<?php echo $i; ?>&tab=<?php echo urlencode($filter_tab); ?>&side=<?php echo urlencode($trade_side_filter); ?>&hide_buy=<?php echo urlencode($hide_buy_orders); ?>">
                                            <?php echo $i; ?>
                                        </a>
                                    </li>
                                <?php endfor; ?>
                                
                                <?php if ($page < $total_pages): ?>
                                    <li class="page-item">
                                        <a class="page-link" href="?page=<?php echo $page + 1; ?>&tab=<?php echo urlencode($filter_tab); ?>&side=<?php echo urlencode($trade_side_filter); ?>&hide_buy=<?php echo urlencode($hide_buy_orders); ?>" aria-label="Next">
                                            <span aria-hidden="true">&raquo;</span>
                                        </a>
                                    </li>
                                    <li class="page-item">
                                        <a class="page-link" href="?page=<?php echo $total_pages; ?>&tab=<?php echo urlencode($filter_tab); ?>&side=<?php echo urlencode($trade_side_filter); ?>&hide_buy=<?php echo urlencode($hide_buy_orders); ?>" aria-label="Last">
                                            <span aria-hidden="true">&raquo;&raquo;</span>
                                        </a>
                                    </li>
                                <?php endif; ?>
                            </ul>
                            <div class="text-center text-muted small mt-2">
                                Showing <?php echo min($records_per_page, count($paginated_trades)); ?> of <?php echo $total_records; ?> trade groups
                            </div>
                        </nav>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
                
                <?php $settlement_trades = $grouped_trades; ?>
                <!-- Overdue Tab -->
                <div class="tab-pane fade" id="overdue" role="tabpanel">
                    <?php 
                    $overdue_trades = array_filter($settlement_trades, function($trade) {
                        return ($trade['settlement_status_category'] ?? '') === 'overdue' && 
                               $trade['settlement_status'] !== 'paid' && 
                               $trade['settlement_status'] !== 'failed' &&
                               $trade['settlement_status'] !== 'linked';
                    });
                    ?>
                    <?php if (empty($overdue_trades)): ?>
                        <div class="text-center py-5">
                            <i class="bi bi-check-circle text-muted" style="font-size: 4rem; opacity: 0.3;"></i>
                            <h5 class="text-muted mt-3">No Overdue Settlements</h5>
                            <p class="text-muted">Great! All settlements are up to date.</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Trade Ref</th>
                                        <th>Client</th>
                                        <th>Counterparty</th>
                                        <th>Security</th>
                                        <th>Side</th>
                                        <th>Amount</th>
                                        <th>Settlement Date</th>
                                        <th>Days Overdue</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($overdue_trades as $trade): 
                                        if (!empty($trade['settlement_date']) && $trade['settlement_date'] != '0000-00-00') {
                                            $days_overdue = (strtotime($today) - strtotime($trade['settlement_date'])) / (60 * 60 * 24);
                                        } else {
                                            $days_overdue = 0;
                                        }
                                    ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($trade['trade_reference']); ?></td>
                                            <td><?php echo htmlspecialchars($trade['client_name']); ?></td>
                                            <td><?php echo htmlspecialchars($trade['counterparty_name']); ?></td>
                                            <td><?php echo htmlspecialchars($trade['security_id']); ?></td>
                                            <td>
                                                <span class="badge bg-<?php echo strtolower($trade['trade_side']) == 'buy' ? 'success' : 'danger'; ?>">
                                                    <?php echo ucfirst($trade['trade_side']); ?>
                                                </span>
                                            </td>
                                            <td class="fw-bold text-danger">TZS <?php echo number_format($trade['consideration'], 2); ?></td>
                                            <td>
                                                <?php 
                                                if (!empty($trade['settlement_date']) && $trade['settlement_date'] != '0000-00-00') {
                                                    echo date('Y-m-d', strtotime($trade['settlement_date']));
                                                } else {
                                                    echo 'N/A';
                                                }
                                                ?>
                                            </td>
                                            <td>
                                                <span class="badge bg-danger"><?php echo $days_overdue; ?> days</span>
                                            </td>
                                            <td>
                                                <?php if ($trade['trade_side'] === 'sell'): ?>
                                                    <div class="btn-group btn-group-sm">
                                                        <?php if ($user_role !== 'trader'): ?>
                                                        <button type="button" class="btn btn-success" onclick="showPaymentModal(<?php echo $trade['id']; ?>)">
                                                            <i class="bi bi-cash-coin"></i> Pay Now
                                                        </button>
                                                        <?php endif; ?>
                                                        <button type="button" class="btn btn-info" onclick="showLinkTradeModal(<?php echo $trade['id']; ?>)">
                                                            <i class="bi bi-link"></i> Link
                                                        </button>
                                                        <button type="button" class="btn btn-danger" onclick="markAsFailed(<?php echo $trade['id']; ?>)">
                                                            <i class="bi bi-x-lg"></i> Cancel
                                                        </button>
                                                        <a href="trades.php?action=contract_note&id=<?php echo $trade['id']; ?>" class="btn btn-outline-primary btn-sm" title="Generate Contract Note">
                                                            <i class="bi bi-file-earmark-text"></i>
                                                        </a>
                                                    </div>
                                                <?php else: ?>
                                                    <div class="btn-group btn-group-sm">
                                                        <?php if ($user_role !== 'trader'): ?>
                                                        <button type="button" class="btn btn-success" onclick="showPaymentModal(<?php echo $trade['id']; ?>)">
                                                            <i class="bi bi-cash-coin"></i> Pay Now
                                                        </button>
                                                        <?php endif; ?>
                                                        <button type="button" class="btn btn-danger" onclick="markAsFailed(<?php echo $trade['id']; ?>)">
                                                            <i class="bi bi-x-lg"></i> Cancel
                                                        </button>
                                                        <a href="trades.php?action=contract_note&id=<?php echo $trade['id']; ?>" class="btn btn-outline-primary btn-sm" title="Generate Contract Note">
                                                            <i class="bi bi-file-earmark-text"></i>
                                                        </a>
                                                    </div>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
                
                <!-- Today Tab -->
                <div class="tab-pane fade" id="today" role="tabpanel">
                    <?php 
                    $today_trades = array_filter($settlement_trades, function($trade) {
                        return ($trade['settlement_status_category'] ?? '') === 'today' && 
                               $trade['settlement_status'] !== 'paid' && 
                               $trade['settlement_status'] !== 'failed' &&
                               $trade['settlement_status'] !== 'linked';
                    });
                    ?>
                    <?php if (empty($today_trades)): ?>
                        <div class="text-center py-5">
                            <i class="bi bi-check-circle text-muted" style="font-size: 4rem; opacity: 0.3;"></i>
                            <h5 class="text-muted mt-3">No Settlements Due Today</h5>
                            <p class="text-muted">All today's settlements are processed.</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Trade Ref</th>
                                        <th>Client</th>
                                        <th>Counterparty</th>
                                        <th>Security</th>
                                        <th>Side</th>
                                        <th>Amount</th>
                                        <th>Settlement Date</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($today_trades as $trade): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($trade['trade_reference']); ?></td>
                                            <td><?php echo htmlspecialchars($trade['client_name']); ?></td>
                                            <td><?php echo htmlspecialchars($trade['counterparty_name']); ?></td>
                                            <td><?php echo htmlspecialchars($trade['security_id']); ?></td>
                                            <td>
                                                <span class="badge bg-<?php echo strtolower($trade['trade_side']) == 'buy' ? 'success' : 'danger'; ?>">
                                                    <?php echo ucfirst($trade['trade_side']); ?>
                                                </span>
                                            </td>
                                            <td class="fw-bold text-warning">TZS <?php echo number_format($trade['consideration'], 2); ?></td>
                                            <td>
                                                <?php 
                                                if (!empty($trade['settlement_date']) && $trade['settlement_date'] != '0000-00-00') {
                                                    echo date('Y-m-d', strtotime($trade['settlement_date']));
                                                } else {
                                                    echo 'N/A';
                                                }
                                                ?>
                                            </td>
                                            <td>
                                                <?php if ($trade['trade_side'] === 'sell'): ?>
                                                    <div class="btn-group btn-group-sm">
                                                        <?php if ($user_role !== 'trader'): ?>
                                                        <button type="button" class="btn btn-success" onclick="showPaymentModal(<?php echo $trade['id']; ?>)">
                                                            <i class="bi bi-cash-coin"></i> Pay Now
                                                        </button>
                                                        <?php endif; ?>
                                                        <button type="button" class="btn btn-info" onclick="showLinkTradeModal(<?php echo $trade['id']; ?>)">
                                                            <i class="bi bi-link"></i> Link
                                                        </button>
                                                        <button type="button" class="btn btn-danger" onclick="markAsFailed(<?php echo $trade['id']; ?>)">
                                                            <i class="bi bi-x-lg"></i> Failed
                                                        </button>
                                                        <a href="trades.php?action=contract_note&id=<?php echo $trade['id']; ?>" class="btn btn-outline-primary btn-sm" title="Generate Contract Note">
                                                            <i class="bi bi-file-earmark-text"></i>
                                                        </a>
                                                    </div>
                                                <?php else: ?>
                                                    <div class="btn-group btn-group-sm">
                                                        <?php if ($user_role !== 'trader'): ?>
                                                        <button type="button" class="btn btn-success" onclick="showPaymentModal(<?php echo $trade['id']; ?>)">
                                                            <i class="bi bi-cash-coin"></i> Pay Now
                                                        </button>
                                                        <?php endif; ?>
                                                        <button type="button" class="btn btn-danger" onclick="markAsFailed(<?php echo $trade['id']; ?>)">
                                                            <i class="bi bi-x-lg"></i> Cancel
                                                        </button>
                                                        <a href="trades.php?action=contract_note&id=<?php echo $trade['id']; ?>" class="btn btn-outline-primary btn-sm" title="Generate Contract Note">
                                                            <i class="bi bi-file-earmark-text"></i>
                                                        </a>
                                                    </div>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
                
                <!-- Paid Tab -->
                <div class="tab-pane fade" id="paid" role="tabpanel">
                    <?php 
                    $paid_trades = array_filter($settlement_trades, function($trade) {
                        return $trade['settlement_status'] === 'paid';
                    });
                    ?>
                    <?php if (empty($paid_trades)): ?>
                        <div class="text-center py-5">
                            <i class="bi bi-check-circle text-muted" style="font-size: 4rem; opacity: 0.3;"></i>
                            <h5 class="text-muted mt-3">No Paid Settlements</h5>
                            <p class="text-muted">No settlements have been marked as paid yet.</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Trade Ref</th>
                                        <th>Client</th>
                                        <th>Counterparty</th>
                                        <th>Security</th>
                                        <th>Side</th>
                                        <th>Amount</th>
                                        <th>Settlement Date</th>
                                        <th>Paid By</th>
                                        <th>Paid Date</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($paid_trades as $trade): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($trade['trade_reference']); ?></td>
                                            <td><?php echo htmlspecialchars($trade['client_name']); ?></td>
                                            <td><?php echo htmlspecialchars($trade['counterparty_name']); ?></td>
                                            <td><?php echo htmlspecialchars($trade['security_id']); ?></td>
                                            <td>
                                                <span class="badge bg-<?php echo strtolower($trade['trade_side']) == 'buy' ? 'success' : 'danger'; ?>">
                                                    <?php echo ucfirst($trade['trade_side']); ?>
                                                </span>
                                            </td>
                                            <td class="fw-bold text-success">TZS <?php echo number_format($trade['consideration'], 2); ?></td>
                                            <td>
                                                <?php 
                                                if (!empty($trade['settlement_date']) && $trade['settlement_date'] != '0000-00-00') {
                                                    echo date('Y-m-d', strtotime($trade['settlement_date']));
                                                } else {
                                                    echo 'N/A';
                                                }
                                                ?>
                                            </td>
                                            <td><?php echo $trade['settled_by_username'] ?? 'N/A'; ?></td>
                                            <td>
                                                <?php 
                                                if (!empty($trade['settled_at'])) {
                                                    echo date('Y-m-d H:i', strtotime($trade['settled_at']));
                                                } else {
                                                    echo 'N/A';
                                                }
                                                ?>
                                            </td>
                                            <td>
                                                <div class="btn-group btn-group-sm">
                                                    <button type="button" class="btn btn-outline-secondary btn-sm" onclick="markAsUnpaid(<?php echo $trade['id']; ?>)">
                                                        <i class="bi bi-arrow-counterclockwise"></i> Undo
                                                    </button>
                                                    <a href="trades.php?action=contract_note&id=<?php echo $trade['id']; ?>" class="btn btn-outline-primary btn-sm" title="Generate Contract Note">
                                                        <i class="bi bi-file-earmark-text"></i>
                                                    </a>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
                
                <!-- Linked Tab -->
                <div class="tab-pane fade" id="linked" role="tabpanel">
                    <?php 
                    $linked_trades = array_filter($settlement_trades, function($trade) {
                        return $trade['settlement_status'] === 'linked';
                    });
                    ?>
                    <?php if (empty($linked_trades)): ?>
                        <div class="text-center py-5">
                            <i class="bi bi-check-circle text-muted" style="font-size: 4rem; opacity: 0.3;"></i>
                            <h5 class="text-muted mt-3">No Linked Settlements</h5>
                            <p class="text-muted">No sell trades have been linked to buy trades.</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Trade Ref</th>
                                        <th>Client</th>
                                        <th>Counterparty</th>
                                        <th>Security</th>
                                        <th>Amount</th>
                                        <th>Settlement Date</th>
                                        <th>Linked To</th>
                                        <th>Linked Security</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($linked_trades as $trade): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($trade['trade_reference']); ?></td>
                                            <td><?php echo htmlspecialchars($trade['client_name']); ?></td>
                                            <td><?php echo htmlspecialchars($trade['counterparty_name']); ?></td>
                                            <td><?php echo htmlspecialchars($trade['security_id']); ?></td>
                                            <td class="fw-bold text-info">TZS <?php echo number_format($trade['consideration'], 2); ?></td>
                                            <td>
                                                <?php 
                                                if (!empty($trade['settlement_date']) && $trade['settlement_date'] != '0000-00-00') {
                                                    echo date('Y-m-d', strtotime($trade['settlement_date']));
                                                } else {
                                                    echo 'N/A';
                                                }
                                                ?>
                                            </td>
                                            <td>
                                                <?php if ($trade['linked_trade_ref']): ?>
                                                    <?php echo htmlspecialchars($trade['linked_trade_ref']); ?>
                                                <?php else: ?>
                                                    Buy #<?php echo $trade['linked_trade_id']; ?>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($trade['linked_security']): ?>
                                                    <?php echo htmlspecialchars($trade['linked_security']); ?>
                                                <?php else: ?>
                                                    N/A
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div class="btn-group btn-group-sm">
                                                    <button type="button" class="btn btn-outline-info btn-sm" onclick="showLinkedDetails(<?php echo $trade['id']; ?>, <?php echo $trade['linked_trade_id']; ?>, '<?php echo addslashes($trade['linked_trade_ref']); ?>', '<?php echo addslashes($trade['linked_client_name']); ?>', '<?php echo addslashes($trade['linked_security']); ?>', '<?php echo $trade['linked_amount']; ?>')">
                                                        <i class="bi bi-eye"></i> View Link
                                                    </button>
                                                    <a href="trades.php?action=contract_note&id=<?php echo $trade['id']; ?>" class="btn btn-outline-primary btn-sm" title="Generate Contract Note">
                                                        <i class="bi bi-file-earmark-text"></i>
                                                    </a>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
                
                <!-- Failed Tab -->
                <div class="tab-pane fade" id="failed" role="tabpanel">
                    <?php 
                    $failed_trades = array_filter($settlement_trades, function($trade) {
                        return $trade['settlement_status'] === 'failed';
                    });
                    ?>
                    <?php if (empty($failed_trades)): ?>
                        <div class="text-center py-5">
                            <i class="bi bi-check-circle text-muted" style="font-size: 4rem; opacity: 0.3;"></i>
                            <h5 class="text-muted mt-3">No Failed Settlements</h5>
                            <p class="text-muted">All settlements are processed successfully.</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Trade Ref</th>
                                        <th>Client</th>
                                        <th>Counterparty</th>
                                        <th>Security</th>
                                        <th>Side</th>
                                        <th>Amount</th>
                                        <th>Settlement Date</th>
                                        <th>Failure Reason</th>
                                        <th>Action Needed</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($failed_trades as $trade): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($trade['trade_reference']); ?></td>
                                            <td><?php echo htmlspecialchars($trade['client_name']); ?></td>
                                            <td><?php echo htmlspecialchars($trade['counterparty_name']); ?></td>
                                            <td><?php echo htmlspecialchars($trade['security_id']); ?></td>
                                            <td>
                                                <span class="badge bg-<?php echo strtolower($trade['trade_side']) == 'buy' ? 'success' : 'danger'; ?>">
                                                    <?php echo ucfirst($trade['trade_side']); ?>
                                                </span>
                                            </td>
                                            <td class="fw-bold text-dark">TZS <?php echo number_format($trade['consideration'], 2); ?></td>
                                            <td>
                                                <?php 
                                                if (!empty($trade['settlement_date']) && $trade['settlement_date'] != '0000-00-00') {
                                                    echo date('Y-m-d', strtotime($trade['settlement_date']));
                                                } else {
                                                    echo 'N/A';
                                                }
                                                ?>
                                            </td>
                                            <td><?php echo htmlspecialchars($trade['failure_reason']); ?></td>
                                            <td><?php echo htmlspecialchars($trade['action_needed']); ?></td>
                                            <td>
                                                <div class="btn-group btn-group-sm">
                                                    <button type="button" class="btn btn-outline-warning btn-sm" data-bs-toggle="modal" data-bs-target="#failureDetailsModal" 
                                                            onclick="showFailureDetails(<?php echo $trade['id']; ?>, '<?php echo addslashes($trade['failure_reason']); ?>', '<?php echo addslashes($trade['action_needed']); ?>')">
                                                        <i class="bi bi-info-circle"></i> Details
                                                    </button>
                                                    <button type="button" class="btn btn-outline-success btn-sm" onclick="retryFailed(<?php echo $trade['id']; ?>)">
                                                        <i class="bi bi-arrow-repeat"></i> Retry
                                                    </button>
                                                    <a href="trades.php?action=contract_note&id=<?php echo $trade['id']; ?>" class="btn btn-outline-primary btn-sm" title="Generate Contract Note">
                                                        <i class="bi bi-file-earmark-text"></i>
                                                    </a>
                                                </div>
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
    </div>
</div>

<!-- ============================================ -->
<!-- ALL MODALS -->
<!-- ============================================ -->

<!-- Link Trade Modal -->
<div class="modal fade" id="linkTradeModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-info">
                <h5 class="modal-title"><i class="bi bi-link me-2"></i>Link Sale to Buy Trade(s)</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="linkTradeForm" method="POST">
                <input type="hidden" name="trade_id" id="linkTradeId">
                <input type="hidden" name="link_trade" value="1">
                
                <div class="modal-body">
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle me-2"></i>
                        <strong>Important:</strong> Linking a sale to buy trade(s) will:
                        <ul class="mb-0 mt-2">
                            <li>Mark both trades as <strong>linked</strong> in the settlement page</li>
                            <li>Update the <strong>order_sheet</strong> status to "linked"</li>
                            <li>The buy trade(s) will <strong>NOT</strong> require a receipt upload in order_sheet</li>
                            <li>Two contract notes will be available: Sold & Bought</li>
                        </ul>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label fw-bold">Current Sale Trade:</label>
                        <div class="p-3 bg-light rounded" id="currentSaleDetails">
                            <span class="text-muted">Loading sale trade details...</span>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label fw-bold">Available Buy Trades for this Client:</label>
                        <div id="buyTradesContainer">
                            <div class="text-center py-3">
                                <div class="spinner-border spinner-border-sm text-primary" role="status"></div>
                                <span class="ms-2">Loading buy trades...</span>
                            </div>
                        </div>
                    </div>
                    
                    <div class="alert alert-warning" id="noBuyTradesWarning" style="display: none;">
                        <i class="bi bi-exclamation-triangle me-2"></i>
                        No available buy trades found for this client.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-info" id="linkSubmitBtn" disabled>Select at least one trade</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Grouped Trades Modal -->
<div class="modal fade" id="groupedTradesModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary">
                <h5 class="modal-title"><i class="bi bi-layers me-2"></i>Grouped Trade Details</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="groupedTradesContent">
                <div class="text-center py-3">
                    <div class="spinner-border spinner-border-sm text-primary" role="status"></div>
                    <span class="ms-2">Loading trade details...</span>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Payment Modal -->
<div class="modal fade" id="paymentModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-success">
                <h5 class="modal-title">Record Payment</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="paymentForm" method="POST">
                <input type="hidden" name="trade_id" id="paymentTradeId">
                <input type="hidden" name="single_payment" value="1">
                
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="payment_mode" class="form-label">Payment Method <span class="text-danger">*</span></label>
                        <select class="form-select" id="payment_mode" name="payment_mode" required onchange="toggleBankSelection()">
                            <option value="">Select Payment Method</option>
                            <?php foreach ($payment_methods as $method): ?>
                                <option value="<?php echo (int)$method['id']; ?>">
                                    <?php echo htmlspecialchars($method['code'] . ' - ' . $method['description']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="mb-3" id="bankAccountField" style="display: none;">
                        <label for="bank_account" class="form-label">Select Bank Account <span class="text-danger">*</span></label>
                        <select class="form-select" id="bank_account" name="bank_account">
                            <option value="">Select Bank Account</option>
                            <?php foreach ($bank_accounts as $bank): ?>
                                <option value="<?php echo (int)$bank['id']; ?>"
                                        data-balance="<?php echo $bank['current_balance']; ?>"
                                        data-currency="<?php echo htmlspecialchars($bank['currency']); ?>">
                                    <?php echo htmlspecialchars($bank['bank_name'] . ' - ' . $bank['account_name'] . ' (' . $bank['account_number'] . ') - ' . number_format($bank['current_balance'], 2) . ' ' . $bank['currency']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-muted" id="bankBalanceInfo"></small>
                    </div>
                    
                    <div class="mb-3">
                        <label for="narration" class="form-label">Payment Narration</label>
                        <textarea class="form-control" id="narration" name="narration" rows="2" placeholder="Enter payment description..."></textarea>
                    </div>
                    
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle me-2"></i>
                        Payment will be recorded in the payment book and bank balance will be updated accordingly.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success">Record Payment</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Bulk Payment Modal -->
<div class="modal fade" id="bulkPaymentModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-success">
                <h5 class="modal-title">Bulk Payment for Selected Trades</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="bulkPaymentForm" method="POST">
                <input type="hidden" name="bulk_payment" value="1">
                <div id="bulkPaymentTradeIds"></div>
                
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="bulk_payment_mode" class="form-label">Payment Method <span class="text-danger">*</span></label>
                        <select class="form-select" id="bulk_payment_mode" name="payment_mode" required onchange="toggleBulkBankSelection()">
                            <option value="">Select Payment Method</option>
                            <?php foreach ($payment_methods as $method): ?>
                                <option value="<?php echo (int)$method['id']; ?>">
                                    <?php echo htmlspecialchars($method['code'] . ' - ' . $method['description']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="mb-3" id="bulkBankAccountField" style="display: none;">
                        <label for="bulk_bank_account" class="form-label">Select Bank Account <span class="text-danger">*</span></label>
                        <select class="form-select" id="bulk_bank_account" name="bank_account">
                            <option value="">Select Bank Account</option>
                            <?php foreach ($bank_accounts as $bank): ?>
                                <option value="<?php echo (int)$bank['id']; ?>"
                                        data-balance="<?php echo $bank['current_balance']; ?>"
                                        data-currency="<?php echo htmlspecialchars($bank['currency']); ?>">
                                    <?php echo htmlspecialchars($bank['bank_name'] . ' - ' . $bank['account_name'] . ' (' . $bank['account_number'] . ') - ' . number_format($bank['current_balance'], 2) . ' ' . $bank['currency']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-muted" id="bulkBankBalanceInfo"></small>
                    </div>
                    
                    <div class="mb-3">
                        <label for="bulk_narration" class="form-label">Payment Narration (Applied to all)</label>
                        <textarea class="form-control" id="bulk_narration" name="narration" rows="2" placeholder="Enter payment description for all selected trades..."></textarea>
                    </div>
                    
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle me-2"></i>
                        <span id="bulkPaymentCount">0</span> trades will be paid.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success">Process Bulk Payment</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Linked Details Modal -->
<div class="modal fade" id="linkedDetailsModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-info">
                <h5 class="modal-title">Trade Link Details</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label fw-bold">Sale Trade:</label>
                    <div class="p-3 bg-light rounded" id="linkedSaleDetails"></div>
                </div>
                
                <div class="mb-3">
                    <label class="form-label fw-bold">Linked Buy Trade:</label>
                    <div class="p-3 bg-light rounded" id="linkedBuyDetails"></div>
                </div>
                
                <div class="alert alert-info">
                    <i class="bi bi-info-circle me-2"></i>
                    These trades are linked. The sale proceeds were used to purchase the linked instrument.
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Failure Modal -->
<div class="modal fade" id="failureModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-warning">
                <h5 class="modal-title">Mark Settlement as Failed</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="failureForm" method="POST">
                <input type="hidden" name="trade_id" id="failureTradeId">
                <input type="hidden" name="mark_failed" value="1">
                
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="failure_reason" class="form-label">Failure Reason <span class="text-danger">*</span></label>
                        <textarea class="form-control" id="failure_reason" name="failure_reason" rows="3" required 
                                  placeholder="Explain why the payment failed..."></textarea>
                    </div>
                    
                    <div class="mb-3">
                        <label for="action_needed" class="form-label">Action Needed <span class="text-danger">*</span></label>
                        <select class="form-select" id="action_needed" name="action_needed" required>
                            <option value="">Select required action</option>
                            <option value="retry_payment">Retry Payment</option>
                            <option value="contact_client">Contact Client</option>
                            <option value="contact_counterparty">Contact Counterparty</option>
                            <option value="investigate_discrepancy">Investigate Discrepancy</option>
                            <option value="update_account_details">Update Account Details</option>
                            <option value="escalate_to_manager">Escalate to Manager</option>
                            <option value="other">Other</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-warning">Mark as Failed</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Failure Details Modal -->
<div class="modal fade" id="failureDetailsModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-dark text-white">
                <h5 class="modal-title">Failure Details</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label fw-bold">Failure Reason:</label>
                    <div class="p-3 bg-light rounded" id="detailsFailureReason"></div>
                </div>
                
                <div class="mb-3">
                    <label class="form-label fw-bold">Action Needed:</label>
                    <div class="p-3 bg-light rounded" id="detailsActionNeeded"></div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<style>
.floating-bulk-payment {
    position: fixed;
    bottom: 30px;
    right: 30px;
    z-index: 1000;
}

.floating-bulk-payment .btn {
    width: 60px;
    height: 60px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.5rem;
}

.floating-bulk-payment .badge {
    font-size: 0.7rem;
    padding: 0.25em 0.5em;
}

.trade-checkbox:checked {
    background-color: var(--success-color);
    border-color: var(--success-color);
}

.pagination .page-item.active .page-link {
    background-color: var(--success-color);
    border-color: var(--success-color);
}
</style>

<!-- Export Modal -->
<div class="modal fade" id="exportModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="bi bi-download me-2"></i>Export Settlement Report</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="export_settlement_contracts">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-bold">Export Type <span class="text-danger">*</span></label>
                        <div class="d-flex gap-3">
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="export_type" id="exportTypeContract" value="contract_notes" checked>
                                <label class="form-check-label" for="exportTypeContract">
                                    <i class="bi bi-file-earmark-text text-primary me-1"></i> Contract Notes
                                </label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="export_type" id="exportTypeClient" value="client_list">
                                <label class="form-check-label" for="exportTypeClient">
                                    <i class="bi bi-people text-info me-1"></i> Client List
                                </label>
                            </div>
                        </div>
                        <small class="text-muted" id="exportTypeHelp">Combined contract notes for each client who traded on the selected date.</small>
                    </div>

                    <div class="mb-3">
                        <label for="export_date" class="form-label fw-bold">Settlement Date <span class="text-danger">*</span></label>
                        <input type="date" class="form-control" id="export_date" name="export_date" value="<?php echo date('Y-m-d'); ?>" required>
                        <small class="text-muted">Select the settlement date to export.</small>
                    </div>

                    <div class="mb-3">
                        <label for="cds_filter" class="form-label fw-bold">CDS Account (Optional)</label>
                        <input type="text" class="form-control" id="cds_filter" name="cds_filter" placeholder="Leave blank for all clients">
                        <small class="text-muted">Filter by a specific CDS account number.</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-download me-1"></i> Export PDF
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Hidden Forms -->
<form id="unpaidForm" method="POST" style="display: none;">
    <input type="hidden" name="trade_id" id="unpaidTradeId">
    <input type="hidden" name="mark_unpaid" value="1">
</form>

<form id="retryFailedForm" method="POST" style="display: none;">
    <input type="hidden" name="trade_id" id="retryTradeId">
    <input type="hidden" name="retry_failed" value="1">
</form>

<?php include '../includes/footer.php'; ?>
