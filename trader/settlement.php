<?php
require_once '../config/config.php';
require_once '../auth/auth_middleware.php';
require_once '../includes/dealing_sheet_helpers.php';

// Check user permissions - finance, admin, and operations can access
$user_role = $_SESSION['role'] ?? '';
$allowed_roles = ['finance_officer', 'system_admin', 'trader'];

require_login();

// Check if user has any of the allowed roles
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

// Get company details
$company_stmt = $db->query("SELECT * FROM companies WHERE status = 'active' ORDER BY id LIMIT 1");
$company = $company_stmt->fetch();
$company_name = $company ? $company['company_name'] : 'Neovam LTD';

// Fetch bank accounts for payment
try {
    $bank_accounts_stmt = $db->query("SELECT id, bank_name, account_name, account_number, currency, current_balance FROM banks_accounts WHERE status = 'active' ORDER BY bank_name, account_name");
    $bank_accounts = $bank_accounts_stmt->fetchAll();
} catch (PDOException $e) {
    $bank_accounts = [];
    error_log("Error fetching bank accounts: " . $e->getMessage());
}

// Fetch payment methods
try {
    $payment_methods_stmt = $db->query("SELECT id, code, description, cashbook, priority, status FROM payment_methods WHERE status = 'active' ORDER BY priority");
    $payment_methods = $payment_methods_stmt->fetchAll();
} catch (PDOException $e) {
    $payment_methods = [];
    error_log("Error fetching payment methods: " . $e->getMessage());
}

// Fetch linked trades (for linking sales to buys)
try {
    $linked_trades_stmt = $db->query("
        SELECT t.*, lt.linked_trade_id as linked_to 
        FROM trades t 
        LEFT JOIN linked_trades lt ON t.id = lt.trade_id 
        WHERE t.status = 'active'
        ORDER BY t.created_at DESC
    ");
    $all_trades = $linked_trades_stmt->fetchAll();
} catch (PDOException $e) {
    $all_trades = [];
    error_log("Error fetching trades: " . $e->getMessage());
}

// Function to generate a single unique payment number
function generateUniquePaymentNo($db) {
    $prefix = 'PMT';
    $year = date('Y');
    $month = date('m');
    $day = date('d');
    
    $base_no = $prefix . $year . $month . $day;
    
    // Start with sequence 0001
    $seq = 1;
    
    // Try to find a unique payment number
    do {
        $payment_no = $base_no . str_pad($seq, 4, '0', STR_PAD_LEFT);
        
        // Check if this payment number already exists
        $stmt = $db->prepare("SELECT COUNT(*) as count FROM payments WHERE payment_no = ?");
        $stmt->execute([$payment_no]);
        $result = $stmt->fetch();
        
        if ($result['count'] == 0) {
            // Found a unique number
            return $payment_no;
        }
        
        $seq++;
        
        // Safety check - prevent infinite loop
        if ($seq > 9999) {
            // If we run out of numbers for today, add timestamp
            return $prefix . $year . $month . $day . '_' . time();
        }
    } while (true);
}

// Function to generate multiple unique payment numbers
function generateUniquePaymentNos($db, $count) {
    $payment_nos = [];
    
    for ($i = 0; $i < $count; $i++) {
        $payment_nos[] = generateUniquePaymentNo($db);
    }
    
    return $payment_nos;
}

// Function to update bank balance
function updateBankBalance($db, $bank_id, $amount, $is_payment_out = true) {
    try {
        if ($is_payment_out) {
            // Money out - decrease balance
            $stmt = $db->prepare("
                UPDATE banks_accounts 
                SET current_balance = current_balance - ?
                WHERE id = ?
            ");
        } else {
            // Money in - increase balance
            $stmt = $db->prepare("
                UPDATE banks_accounts 
                SET current_balance = current_balance + ?
                WHERE id = ?
            ");
        }
        
        return $stmt->execute([$amount, $bank_id]);
    } catch (Exception $e) {
        error_log("Error updating bank balance: " . $e->getMessage());
        return false;
    }
}

// Function to create journal entry for payment
function createJournalEntry($db, $payment_no, $trade, $bank_account, $amount, $description) {
    try {
        // Generate unique journal number
        $journal_no = 'JRNL' . date('Ymd') . '_' . uniqid();
        $fiscal_year = date('Y');
        $fiscal_period = date('m');
        $current_user = $_SESSION['username'] ?? 'system';
        $user_id = $_SESSION['user_id'] ?? null;
        
        // Determine accounts based on trade side
        if ($trade['trade_side'] === 'sell') {
            // For sell trades: Debit Bank, Credit Sales/Revenue
            $debit_account = $bank_account['code'] ?? '111'; // Bank account
            $credit_account = '41'; // Sales/Revenue account (example)
            $debit_account_name = 'Bank Account';
            $credit_account_name = 'Sales Revenue';
        } else {
            // For buy trades: Debit Investment/Asset, Credit Bank
            $debit_account = '11'; // Investment account (example)
            $credit_account = $bank_account['code'] ?? '111'; // Bank account
            $debit_account_name = 'Investment Account';
            $credit_account_name = 'Bank Account';
        }
        
        // Get account names from chart_of_accounts if available
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
            // Use default names if chart_of_accounts lookup fails
            error_log("Error fetching account names: " . $e->getMessage());
        }
        
        // Insert debit entry
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
        
        // Insert credit entry
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

// Handle bulk payment with bank selection
if (isset($_POST['bulk_payment']) && isset($_POST['trade_ids'])) {
    $trade_ids = $_POST['trade_ids'];
    $bank_account_id = isset($_POST['bank_account']) ? (int)$_POST['bank_account'] : 0;
    $payment_mode = (int)$_POST['payment_mode'];
    $narration = sanitize_input($_POST['narration'] ?? '');
    $user_id = $_SESSION['user_id'];
    $processed = 0;
    $failed = 0;
    $payment_nos = [];
    $failed_trades = [];
    
    // Get payment method details
    $payment_method_stmt = $db->prepare("SELECT description FROM payment_methods WHERE id = ?");
    $payment_method_stmt->execute([$payment_mode]);
    $payment_method = $payment_method_stmt->fetch();
    $payment_method_desc = $payment_method['description'] ?? '';
    
    // Get bank account details if bank transfer is selected
    $bank_account = null;
    if ($bank_account_id > 0) {
        $bank_stmt = $db->prepare("SELECT * FROM banks_accounts WHERE id = ?");
        $bank_stmt->execute([$bank_account_id]);
        $bank_account = $bank_stmt->fetch();
    }
    
    if (!empty($trade_ids) && is_array($trade_ids)) {
        // Generate unique payment numbers for all trades
        $payment_nos = generateUniquePaymentNos($db, count($trade_ids));
        
        // Start transaction for bulk payment
        $db->beginTransaction();
        
        try {
            foreach ($trade_ids as $index => $trade_id) {
                $trade_id = (int)$trade_id;
                
                // Verify trade exists and is active
                $stmt = $db->prepare("SELECT * FROM trades WHERE id = ?");
                $stmt->execute([$trade_id]);
                $trade = $stmt->fetch();
                
                if ($trade && $trade['settlement_status'] !== 'paid' && $trade['settlement_status'] !== 'linked') {
                    $current_time = date('Y-m-d H:i:s');
                    $payment_no = $payment_nos[$index];
                    
                    $notes = "\nPaid via bulk payment using " . $payment_method_desc . " by user $user_id on $current_time";
                    if ($bank_account) {
                        $notes .= " - Bank: " . $bank_account['bank_name'] . " (" . $bank_account['account_number'] . ")";
                    }
                    if ($narration) {
                        $notes .= "\nNarration: " . $narration;
                    }
                    
                    // Update trade as paid
                    $update_stmt = $db->prepare("
                        UPDATE trades 
                        SET settlement_status = 'paid', 
                            settled_by = ?, 
                            settlement_notes = CONCAT(COALESCE(settlement_notes, ''), ?),
                            settled_at = NOW()
                        WHERE id = ?
                    ");
                    
                    if ($update_stmt->execute([$user_id, $notes, $trade_id])) {
                        syncSettlementTradeToDealingSheetSafely($db, $trade_id, $current_user);
                        // Create payment record in payment book
                        $amount = $trade['consideration'];
                        $description = $narration ?: "Payment for " . $trade['security_id'] . " shares " . ($trade['trade_side'] === 'sell' ? 'sold' : 'purchased') . " - Trade Ref: " . $trade['trade_reference'];
                        
                        // Get payee information based on trade side
                        if ($trade['trade_side'] === 'sell') {
                            $payee_name = $trade['counterparty_name'];
                            $payee_type = 'C'; // Customer/Client
                        } else {
                            $payee_name = $trade['client_name'];
                            $payee_type = 'C'; // Customer/Client
                        }
                        
                        // Check if payment already exists for this trade
                        $existing_stmt = $db->prepare("SELECT id FROM payments WHERE source_id = ? AND source_type = 'trade_settlement'");
                        $existing_stmt->execute([$trade_id]);
                        $existing_payment = $existing_stmt->fetch();
                        
                        if (!$existing_payment) {
                            // Determine ac_credit: use selected bank account or get default
                            $ac_credit_id = $bank_account_id > 0 ? $bank_account_id : 1;
                            if ($bank_account_id <= 0) {
                                $default_bank_stmt = $db->query("SELECT id FROM banks_accounts WHERE status = 'active' LIMIT 1");
                                $default_bank = $default_bank_stmt->fetch();
                                if ($default_bank) {
                                    $ac_credit_id = $default_bank['id'];
                                }
                            }
                            
                            // Insert new payment into payments table
                            $payment_stmt = $db->prepare("
                                INSERT INTO payments (
                                    payment_no, payment_date, payment_mode, paid_to,
                                    name, record_in_financial, ac_credit,
                                    currency, amount, narration,
                                    created_by_username, created_at, status,
                                    bank_name, bank_account_number, source_type, source_id
                                ) VALUES (?, NOW(), ?, ?, ?, 'yes', ?, ?, ?, ?, ?, NOW(), 'active', ?, ?, 'trade_settlement', ?)
                            ");
                            
                            $payment_result = $payment_stmt->execute([
                                $payment_no,
                                $payment_mode,
                                $payee_type,
                                $payee_name,
                                $ac_credit_id,
                                'Tsh',
                                $amount,
                                $description,
                                $_SESSION['username'],
                                $bank_account ? $bank_account['bank_name'] : '',
                                $bank_account ? $bank_account['account_number'] : '',
                                $trade_id
                            ]);
                            
                            if ($payment_result) {
                                // Update bank balance if bank account is selected
                                if ($bank_account_id > 0 && $bank_account) {
                                    $is_payment_out = ($trade['trade_side'] === 'sell');
                                    $balance_updated = updateBankBalance($db, $bank_account_id, $amount, $is_payment_out);
                                    
                                    if (!$balance_updated) {
                                        throw new Exception("Failed to update bank balance for trade ID: $trade_id");
                                    }
                                }
                                
                                // Create journal entry if bank account is selected
                                if ($bank_account_id > 0 && $bank_account) {
                                    createJournalEntry($db, $payment_no, $trade, $bank_account, $amount, $description);
                                }
                                $processed++;
                            } else {
                                throw new Exception("Failed to create payment record for trade ID: $trade_id");
                            }
                        } else {
                            // Update existing payment to active
                            // Determine ac_credit: use selected bank account or get default
                            $ac_credit_id = $bank_account_id > 0 ? $bank_account_id : 1;
                            if ($bank_account_id <= 0) {
                                $default_bank_stmt = $db->query("SELECT id FROM banks_accounts WHERE status = 'active' LIMIT 1");
                                $default_bank = $default_bank_stmt->fetch();
                                if ($default_bank) {
                                    $ac_credit_id = $default_bank['id'];
                                }
                            }
                            
                            $update_payment_stmt = $db->prepare("
                                UPDATE payments 
                                SET status = 'active',
                                    updated_at = NOW(),
                                    payment_mode = ?,
                                    ac_credit = ?,
                                    narration = ?
                                WHERE id = ?
                            ");
                            $update_payment_stmt->execute([$payment_mode, $ac_credit_id, $narration, $existing_payment['id']]);
                            
                            // Update bank balance if bank account is selected
                            if ($bank_account_id > 0 && $bank_account) {
                                $is_payment_out = ($trade['trade_side'] === 'sell');
                                $balance_updated = updateBankBalance($db, $bank_account_id, $amount, $is_payment_out);
                                
                                if (!$balance_updated) {
                                    throw new Exception("Failed to update bank balance for existing payment of trade ID: $trade_id");
                                }
                            }
                            $processed++;
                        }
                    } else {
                        throw new Exception("Failed to update trade status for trade ID: $trade_id");
                    }
                } else {
                    $failed++;
                    $status = $trade ? $trade['settlement_status'] : 'not found';
                    $failed_trades[] = "Trade ID $trade_id: Already paid/linked or not found (Status: $status)";
                }
            }
            
            $db->commit();
            if ($processed > 0) {
                $success_message = "Successfully processed $processed payments.";
                if (count($payment_nos) <= 5) {
                    $success_message .= " Payment Numbers: " . implode(', ', $payment_nos);
                } else {
                    $success_message .= " First payment: " . $payment_nos[0] . ", last payment: " . $payment_nos[count($payment_nos)-1];
                }
            }
            if ($failed > 0) {
                $error_message = "Failed to process $failed payments. " . implode('; ', $failed_trades);
            }
        } catch (Exception $e) {
            $db->rollBack();
            $error_message = "Error processing bulk payment: " . $e->getMessage();
            error_log("Bulk payment error: " . $e->getMessage());
        }
    } else {
        $error_message = "No trades selected for payment.";
    }
    
    // Redirect with messages
    $message = $success_message ?: $error_message;
    $type = $success_message ? 'success' : 'danger';
    header('Location: settlement.php?message=' . urlencode($message) . '&type=' . $type);
    exit;
}

// Handle single payment with bank selection
if (isset($_POST['single_payment']) && isset($_POST['trade_id'])) {
    $trade_id = (int)$_POST['trade_id'];
    $bank_account_id = isset($_POST['bank_account']) ? (int)$_POST['bank_account'] : 0;
    $payment_mode = (int)$_POST['payment_mode'];
    $narration = sanitize_input($_POST['narration'] ?? '');
    $user_id = $_SESSION['user_id'];
    
    // Get payment method details
    $payment_method_stmt = $db->prepare("SELECT description FROM payment_methods WHERE id = ?");
    $payment_method_stmt->execute([$payment_mode]);
    $payment_method = $payment_method_stmt->fetch();
    $payment_method_desc = $payment_method['description'] ?? '';
    
    // Get bank account details if bank transfer is selected
    $bank_account = null;
    if ($bank_account_id > 0) {
        $bank_stmt = $db->prepare("SELECT * FROM banks_accounts WHERE id = ?");
        $bank_stmt->execute([$bank_account_id]);
        $bank_account = $bank_stmt->fetch();
    }
    
    // Verify trade exists and is active
    $stmt = $db->prepare("SELECT * FROM trades WHERE id = ?");
    $stmt->execute([$trade_id]);
    $trade = $stmt->fetch();
    
    if ($trade) {
        // Check if trade is already paid or linked
        if ($trade['settlement_status'] === 'paid' || $trade['settlement_status'] === 'linked') {
            $error_message = "Trade is already settled (Status: " . $trade['settlement_status'] . ")";
        } else {
            $current_time = date('Y-m-d H:i:s');
            $notes = "\nPaid using " . $payment_method_desc . " by user $user_id on $current_time";
            if ($bank_account) {
                $notes .= " - Bank: " . $bank_account['bank_name'] . " (" . $bank_account['account_number'] . ")";
            }
            if ($narration) {
                $notes .= "\nNarration: " . $narration;
            }
            
            // Check if payment already exists for this trade
            $existing_payment_stmt = $db->prepare("SELECT id, payment_no, status FROM payments WHERE source_id = ? AND source_type = 'trade_settlement'");
            $existing_payment_stmt->execute([$trade_id]);
            $existing_payment = $existing_payment_stmt->fetch();
            
            $payment_no = '';
            
            if ($existing_payment) {
                // Update existing payment to active
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
                // Generate new payment number
                $payment_no = generateUniquePaymentNo($db);
            }
            
            // Update trade as paid
            $update_stmt = $db->prepare("
                UPDATE trades 
                SET settlement_status = 'paid', 
                    settled_by = ?, 
                    settlement_notes = CONCAT(COALESCE(settlement_notes, ''), ?),
                    settled_at = NOW()
                WHERE id = ?
            ");
            
            if ($update_stmt->execute([$user_id, $notes, $trade_id])) {
                syncSettlementTradeToDealingSheetSafely($db, $trade_id, $current_user);
                if (!$existing_payment) {
                    // Update payment record in payment book
                    $amount = $trade['consideration'];
                    $description = $narration ?: "Payment for " . $trade['security_id'] . " shares " . ($trade['trade_side'] === 'sell' ? 'sold' : 'purchased') . " - Trade Ref: " . $trade['trade_reference'];
                    
                    // Get payee information based on trade side
                    if ($trade['trade_side'] === 'sell') {
                        $payee_name = $trade['counterparty_name'];
                        $payee_type = 'C'; // Customer/Client
                    } else {
                        $payee_name = $trade['client_name'];
                        $payee_type = 'C'; // Customer/Client
                    }
                    
                    // Determine ac_credit: use selected bank account or get default
                    $ac_credit_id = $bank_account_id > 0 ? $bank_account_id : 1;
                    if ($bank_account_id <= 0) {
                        $default_bank_stmt = $db->query("SELECT id FROM banks_accounts WHERE status = 'active' LIMIT 1");
                        $default_bank = $default_bank_stmt->fetch();
                        if ($default_bank) {
                            $ac_credit_id = $default_bank['id'];
                        }
                    }
                    
                    // Insert into payments table
                    $payment_stmt = $db->prepare("
                        INSERT INTO payments (
                            payment_no, payment_date, payment_mode, paid_to,
                            name, record_in_financial, ac_credit,
                            currency, amount, narration,
                            created_by_username, created_at, status,
                            bank_name, bank_account_number, source_type, source_id
                        ) VALUES (?, NOW(), ?, ?, ?, 'yes', ?, ?, ?, ?, ?, NOW(), 'active', ?, ?, 'trade_settlement', ?)
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
                        $_SESSION['username'],
                        $bank_account ? $bank_account['bank_name'] : '',
                        $bank_account ? $bank_account['account_number'] : '',
                        $trade_id
                    ]);
                }
                
                // Update bank balance if bank account is selected
                if ($bank_account_id > 0 && $bank_account) {
                    $is_payment_out = ($trade['trade_side'] === 'sell');
                    $balance_updated = updateBankBalance($db, $bank_account_id, $trade['consideration'], $is_payment_out);
                    
                    if (!$balance_updated) {
                        $error_message = 'Failed to update bank balance.';
                    } else {
                        if (!$existing_payment) {
                            // Create journal entry only for new payments
                            createJournalEntry($db, $payment_no, $trade, $bank_account, $trade['consideration'], 
                                $narration ?: "Payment for " . $trade['security_id'] . " shares " . ($trade['trade_side'] === 'sell' ? 'sold' : 'purchased'));
                        }
                        
                        $success_message = 'Payment recorded successfully! Payment No: ' . $payment_no;
                    }
                } else {
                    $success_message = 'Payment recorded successfully! Payment No: ' . $payment_no;
                }
            } else {
                $error_message = 'Error updating trade as paid.';
            }
        }
    } else {
        $error_message = 'Trade not found.';
    }
    
    // Redirect to avoid form resubmission
    header('Location: settlement.php?message=' . urlencode($success_message ?: $error_message) . '&type=' . ($success_message ? 'success' : 'danger'));
    exit;
}

// Handle linking trade (sale to buy)
if (isset($_POST['link_trade']) && isset($_POST['trade_id']) && isset($_POST['linked_trade_id'])) {
    $trade_id = (int)$_POST['trade_id'];
    $linked_trade_id = (int)$_POST['linked_trade_id'];
    $user_id = $_SESSION['user_id'];
    
    try {
        // Check if both trades exist
        $trade_stmt = $db->prepare("SELECT * FROM trades WHERE id = ?");
        $trade_stmt->execute([$trade_id]);
        $trade = $trade_stmt->fetch();
        
        $linked_trade_stmt = $db->prepare("SELECT * FROM trades WHERE id = ?");
        $linked_trade_stmt->execute([$linked_trade_id]);
        $linked_trade = $linked_trade_stmt->fetch();
        
        if ($trade && $linked_trade) {
            // Check if sale is being linked to a buy trade
            if ($trade['trade_side'] === 'sell' && $linked_trade['trade_side'] === 'buy') {
                // Check if trades are for same client
                if ($trade['client_name'] === $linked_trade['client_name']) {
                    // Insert link record
                    $link_stmt = $db->prepare("
                        INSERT INTO linked_trades (trade_id, linked_trade_id, linked_by, linked_at)
                        VALUES (?, ?, ?, NOW())
                        ON DUPLICATE KEY UPDATE linked_trade_id = ?
                    ");
                    
                    if ($link_stmt->execute([$trade_id, $linked_trade_id, $user_id, $linked_trade_id])) {
                        // Update trade notes
                        $notes = "\nLinked to buy trade ID: $linked_trade_id (Ref: " . $linked_trade['trade_reference'] . ") by user $user_id on " . date('Y-m-d H:i:s');
                        $update_stmt = $db->prepare("
                            UPDATE trades 
                            SET settlement_status = 'linked',
                                settlement_notes = CONCAT(COALESCE(settlement_notes, ''), ?),
                                settled_by = ?,
                                settled_at = NOW()
                            WHERE id = ?
                        ");
                        
                        if ($update_stmt->execute([$notes, $user_id, $trade_id])) {
                            syncSettlementTradeToDealingSheetSafely($db, $trade_id, $current_user);
                            $success_message = "Trade successfully linked! Sale linked to buy trade.";
                        } else {
                            $error_message = "Error updating trade status.";
                        }
                    } else {
                        $error_message = "Error creating trade link.";
                    }
                } else {
                    $error_message = "Cannot link trades from different clients.";
                }
            } else {
                $error_message = "Can only link sell trades to buy trades.";
            }
        } else {
            $error_message = "One or both trades not found.";
        }
    } catch (Exception $e) {
        $error_message = "Error linking trades: " . $e->getMessage();
    }
    
    header('Location: settlement.php?message=' . urlencode($success_message ?: $error_message) . '&type=' . ($success_message ? 'success' : 'danger'));
    exit;
}

// Handle mark as unpaid (undo payment)
if (isset($_POST['mark_unpaid']) && isset($_POST['trade_id'])) {
    $trade_id = (int)$_POST['trade_id'];
    $user_id = $_SESSION['user_id'];
    
    try {
        // Get trade details
        $trade_stmt = $db->prepare("SELECT * FROM trades WHERE id = ?");
        $trade_stmt->execute([$trade_id]);
        $trade = $trade_stmt->fetch();
        
        if ($trade) {
            // Update trade as unpaid
            $notes = "\nPayment undone by user $user_id on " . date('Y-m-d H:i:s');
            $update_stmt = $db->prepare("
                UPDATE trades 
                SET settlement_status = 'unpaid', 
                    settlement_notes = CONCAT(COALESCE(settlement_notes, ''), ?),
                    settled_by = NULL,
                    settled_at = NULL
                WHERE id = ?
            ");
            
            if ($update_stmt->execute([$notes, $trade_id])) {
                syncSettlementTradeToDealingSheetSafely($db, $trade_id, $current_user);
                // Deactivate payment record if exists
                $payment_stmt = $db->prepare("
                    UPDATE payments 
                    SET status = 'inactive',
                        updated_at = NOW()
                    WHERE source_id = ? AND source_type = 'trade_settlement'
                ");
                $payment_stmt->execute([$trade_id]);
                
                // Reverse bank balance if payment was through bank
                $bank_stmt = $db->prepare("
                    SELECT ba.id, ba.current_balance 
                    FROM payments p 
                    JOIN banks_accounts ba ON p.ac_credit = ba.id 
                    WHERE p.source_id = ? AND p.source_type = 'trade_settlement' 
                    ORDER BY p.id DESC LIMIT 1
                ");
                $bank_stmt->execute([$trade_id]);
                $payment_bank = $bank_stmt->fetch();
                
                if ($payment_bank && $trade) {
                    $is_payment_out = ($trade['trade_side'] === 'sell');
                    // Reverse: if it was payment out, now add back; if it was payment in, now subtract
                    updateBankBalance($db, $payment_bank['id'], $trade['consideration'], !$is_payment_out);
                }
                
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

// Handle mark as failed and allow retry
if (isset($_POST['mark_failed']) && isset($_POST['trade_id'])) {
    $trade_id = (int)$_POST['trade_id'];
    $failure_reason = sanitize_input($_POST['failure_reason'] ?? '');
    $action_needed = sanitize_input($_POST['action_needed'] ?? '');
    $user_id = $_SESSION['user_id'];
    
    try {
        $notes = "\nMarked as failed by user $user_id on " . date('Y-m-d H:i:s') . ": $failure_reason | Action: $action_needed";
        
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

// Handle retry failed payment
if (isset($_POST['retry_failed']) && isset($_POST['trade_id'])) {
    $trade_id = (int)$_POST['trade_id'];
    
    try {
        $notes = "\nRetried from failed status by user " . $_SESSION['user_id'] . " on " . date('Y-m-d H:i:s');
        
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

// Handle other bulk actions
if (isset($_POST['bulk_action']) && isset($_POST['trade_ids'])) {
    $bulk_action = $_POST['bulk_action'];
    $trade_ids = $_POST['trade_ids'];
    $user_id = $_SESSION['user_id'];
    $processed = 0;
    $failed = 0;
    
    if (!empty($trade_ids) && is_array($trade_ids)) {
        foreach ($trade_ids as $trade_id) {
            $trade_id = (int)$trade_id;
            
            $stmt = $db->prepare("SELECT * FROM trades WHERE id = ?");
            $stmt->execute([$trade_id]);
            $trade = $stmt->fetch();
            
            if ($trade) {
                $current_time = date('Y-m-d H:i:s');
                
                switch ($bulk_action) {
                    case 'mark_unpaid_bulk':
                        $notes = "\nMarked as unpaid (bulk) by user $user_id on $current_time";
                        $stmt = $db->prepare("
                            UPDATE trades 
                            SET settlement_status = 'unpaid', 
                                settled_by = NULL, 
                                settlement_notes = CONCAT(COALESCE(settlement_notes, ''), ?)
                            WHERE id = ?
                        ");
                        if ($stmt->execute([$notes, $trade_id])) {
                            syncSettlementTradeToDealingSheetSafely($db, $trade_id, $current_user);
                            $processed++;
                        } else {
                            $failed++;
                        }
                        break;
                        
                    case 'mark_failed_bulk':
                        $failure_reason = sanitize_input($_POST['bulk_failure_reason'] ?? '');
                        $action_needed = sanitize_input($_POST['bulk_action_needed'] ?? '');
                        
                        $notes = "\nMarked as failed (bulk) by user $user_id on $current_time: $failure_reason | Action: $action_needed";
                        
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
                            syncSettlementTradeToDealingSheetSafely($db, $trade_id, $current_user);
                            $processed++;
                        } else {
                            $failed++;
                        }
                        break;
                }
            } else {
                $failed++;
            }
        }
        
        if ($processed > 0) {
            $success_message = "Successfully processed $processed trades";
            if ($failed > 0) {
                $error_message = "Failed to process $failed trades";
            }
        } else {
            $error_message = "Failed to process any trades";
        }
    }
    
    $message = $success_message ?: $error_message;
    $type = $success_message ? 'success' : 'danger';
    header('Location: settlement.php?message=' . urlencode($message) . '&type=' . $type);
    exit;
}

// Get filter values
$trade_side_filter = isset($_GET['side']) ? $_GET['side'] : 'sell_only';
$hide_buy_orders = isset($_GET['hide_buy']) ? $_GET['hide_buy'] : '1';

// Get date range for settlement
$today = date('Y-m-d');
$two_days_ago = date('Y-m-d', strtotime('-30 days'));
$next_30_days = date('Y-m-d', strtotime('+30 days'));

// Debug: Show date ranges
error_log("Settlement Date Range: $two_days_ago to $next_30_days");

// PAGINATION SETUP
$records_per_page = 50; // Number of records per page
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) $page = 1;
$offset = ($page - 1) * $records_per_page;

// Get total count for pagination
$count_query = "
    SELECT COUNT(*) as total
    FROM trades t
    LEFT JOIN users u ON t.settled_by = u.id
    LEFT JOIN companies c_buyer ON t.client_name = c_buyer.company_name
    LEFT JOIN companies c_seller ON t.counterparty_name = c_seller.company_name
    LEFT JOIN linked_trades lt ON t.id = lt.trade_id
    LEFT JOIN trades lt2 ON lt.linked_trade_id = lt2.id
    WHERE t.settlement_date IS NOT NULL 
    AND t.settlement_date BETWEEN ? AND ?
    AND t.status = 'active'
    AND (t.settlement_status IS NULL OR t.settlement_status != 'cancelled')
";

// Add trade side filter if needed
if ($hide_buy_orders === '1') {
    $count_query .= " AND t.trade_side = 'sell' ";
} elseif ($trade_side_filter !== 'all') {
    $count_query .= " AND t.trade_side = ? ";
}

$count_stmt = $db->prepare($count_query);

if ($hide_buy_orders === '1') {
    $count_stmt->execute([$two_days_ago, $next_30_days]);
} elseif ($trade_side_filter !== 'all') {
    $count_stmt->execute([$two_days_ago, $next_30_days, $trade_side_filter]);
} else {
    $count_stmt->execute([$two_days_ago, $next_30_days]);
}

$total_records = $count_stmt->fetch()['total'];
$total_pages = ceil($total_records / $records_per_page);

// Get settlement trades - from 2 days ago to 30 days in the future
$query = "
    SELECT t.*, 
           u.username as settled_by_username,
           c_buyer.company_name as buyer_company_name,
           c_seller.company_name as seller_company_name,
           lt.linked_trade_id,
           lt2.trade_reference as linked_trade_ref,
           lt2.client_name as linked_client_name,
           lt2.security_id as linked_security,
           lt2.consideration as linked_amount,
           CASE 
               WHEN t.settlement_date < ? THEN 'overdue'
               WHEN t.settlement_date = ? THEN 'today'
               ELSE 'upcoming'
           END as settlement_status_category
    FROM trades t
    LEFT JOIN users u ON t.settled_by = u.id
    LEFT JOIN companies c_buyer ON t.client_name = c_buyer.company_name
    LEFT JOIN companies c_seller ON t.counterparty_name = c_seller.company_name
    LEFT JOIN linked_trades lt ON t.id = lt.trade_id
    LEFT JOIN trades lt2 ON lt.linked_trade_id = lt2.id
    WHERE t.settlement_date IS NOT NULL 
    AND t.settlement_date BETWEEN ? AND ?
    AND t.status = 'active'
    AND (t.settlement_status IS NULL OR t.settlement_status != 'cancelled')
";

// Add trade side filter if needed
if ($hide_buy_orders === '1') {
    $query .= " AND t.trade_side = 'sell' ";
} elseif ($trade_side_filter !== 'all') {
    $query .= " AND t.trade_side = ? ";
}

$query .= " ORDER BY 
    CASE 
        WHEN t.settlement_date < ? THEN 1
        WHEN t.settlement_date = ? THEN 2
        ELSE 3
    END,
    t.settlement_date ASC,
    t.created_at DESC
    LIMIT " . (int)$records_per_page . " OFFSET " . (int)$offset; // FIXED: Direct concatenation with casting

// Prepare and execute query
$stmt = $db->prepare($query);

if ($hide_buy_orders === '1') {
    $stmt->execute([$today, $today, $two_days_ago, $next_30_days, $today, $today]);
} elseif ($trade_side_filter !== 'all') {
    $stmt->execute([$today, $today, $two_days_ago, $next_30_days, $trade_side_filter, $today, $today]);
} else {
    $stmt->execute([$today, $today, $two_days_ago, $next_30_days, $today, $today]);
}

$settlement_trades = $stmt->fetchAll();

// Debug: Log how many trades were fetched
error_log("Total settlement trades fetched: " . count($settlement_trades) . " (Page: $page, Offset: $offset)");

// Calculate summary statistics (we need to fetch all for stats, but this should be quick)
$stats_query = "
    SELECT 
        COUNT(*) as total_count,
        SUM(CASE WHEN t.settlement_status = 'paid' THEN 1 ELSE 0 END) as paid_count,
        SUM(CASE WHEN t.settlement_status = 'failed' THEN 1 ELSE 0 END) as failed_count,
        SUM(CASE WHEN t.settlement_status = 'linked' THEN 1 ELSE 0 END) as linked_count,
        SUM(CASE WHEN t.settlement_status = 'paid' THEN t.consideration ELSE 0 END) as paid_value,
        SUM(CASE WHEN t.settlement_status = 'failed' THEN t.consideration ELSE 0 END) as failed_value,
        SUM(CASE WHEN t.settlement_status = 'linked' THEN t.consideration ELSE 0 END) as linked_value,
        SUM(CASE WHEN t.settlement_status NOT IN ('paid', 'failed', 'linked') AND t.settlement_date < ? THEN 1 ELSE 0 END) as overdue_count,
        SUM(CASE WHEN t.settlement_status NOT IN ('paid', 'failed', 'linked') AND t.settlement_date < ? THEN t.consideration ELSE 0 END) as overdue_value,
        SUM(CASE WHEN t.settlement_status NOT IN ('paid', 'failed', 'linked') AND t.settlement_date = ? THEN 1 ELSE 0 END) as today_count,
        SUM(CASE WHEN t.settlement_status NOT IN ('paid', 'failed', 'linked') AND t.settlement_date = ? THEN t.consideration ELSE 0 END) as today_value,
        SUM(CASE WHEN t.settlement_status NOT IN ('paid', 'failed', 'linked') AND t.settlement_date > ? THEN 1 ELSE 0 END) as upcoming_count,
        SUM(CASE WHEN t.settlement_status NOT IN ('paid', 'failed', 'linked') AND t.settlement_date > ? THEN t.consideration ELSE 0 END) as upcoming_value,
        SUM(t.consideration) as total_value
    FROM trades t
    WHERE t.settlement_date IS NOT NULL 
    AND t.settlement_date BETWEEN ? AND ?
    AND t.status = 'active'
    AND (t.settlement_status IS NULL OR t.settlement_status != 'cancelled')
";

// Add trade side filter if needed
if ($hide_buy_orders === '1') {
    $stats_query .= " AND t.trade_side = 'sell' ";
} elseif ($trade_side_filter !== 'all') {
    $stats_query .= " AND t.trade_side = ? ";
}

$stats_stmt = $db->prepare($stats_query);

if ($hide_buy_orders === '1') {
    $stats_stmt->execute([$today, $today, $today, $today, $today, $today, $two_days_ago, $next_30_days]);
} elseif ($trade_side_filter !== 'all') {
    $stats_stmt->execute([$today, $today, $today, $today, $today, $today, $two_days_ago, $next_30_days, $trade_side_filter]);
} else {
    $stats_stmt->execute([$today, $today, $today, $today, $today, $today, $two_days_ago, $next_30_days]);
}

$stats = $stats_stmt->fetch();

// Assign stats to variables
$total_trades = $stats['total_count'] ?? 0;
$total_value = $stats['total_value'] ?? 0;
$overdue_count = $stats['overdue_count'] ?? 0;
$overdue_value = $stats['overdue_value'] ?? 0;
$today_count = $stats['today_count'] ?? 0;
$today_value = $stats['today_value'] ?? 0;
$upcoming_count = $stats['upcoming_count'] ?? 0;
$upcoming_value = $stats['upcoming_value'] ?? 0;
$paid_count = $stats['paid_count'] ?? 0;
$paid_value = $stats['paid_value'] ?? 0;
$failed_count = $stats['failed_count'] ?? 0;
$failed_value = $stats['failed_value'] ?? 0;
$linked_count = $stats['linked_count'] ?? 0;
$linked_value = $stats['linked_value'] ?? 0;

$page_title = 'Trade Settlement';
include '../includes/header.php';
?>

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
                        <!-- Debug info -->
                        <div style="display: none;" id="debugInfo">
                            <small class="text-muted">
                                Date Range: <?php echo $two_days_ago; ?> to <?php echo $next_30_days; ?> | 
                                Total Trades: <?php echo $total_trades; ?>
                            </small>
                        </div>
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
                        <div class="row g-2 justify-content-end">
                            <div class="col-auto">
                                <select class="form-select form-select-sm" name="side" onchange="this.form.submit()">
                                    <option value="all" <?php echo $trade_side_filter === 'all' ? 'selected' : ''; ?>>All Trades</option>
                                    <option value="buy" <?php echo $trade_side_filter === 'buy' ? 'selected' : ''; ?>>Buy</option>
                                    <option value="sell" <?php echo $trade_side_filter === 'sell' ? 'selected' : ''; ?>>Sell</option>
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
                            <div class="text-xs fw-bold text-primary text-uppercase mb-1">Total Settlement Value</div>
                            <div class="h5 mb-0 fw-bold text-gray-800">TZS <?php echo number_format($total_value, 2); ?></div>
                            <div class="mt-2 text-muted small"><?php echo $total_trades; ?> trades</div>
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
                            <div class="text-xs fw-bold text-danger text-uppercase mb-1">Overdue Settlements</div>
                            <div class="h5 mb-0 fw-bold text-gray-800"><?php echo $overdue_count; ?> trades</div>
                            <div class="mt-2 text-muted small">TZS <?php echo number_format($overdue_value, 2); ?></div>
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
                            <div class="h5 mb-0 fw-bold text-gray-800"><?php echo $today_count; ?> trades</div>
                            <div class="mt-2 text-muted small">TZS <?php echo number_format($today_value, 2); ?></div>
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
                            <div class="text-xs fw-bold text-success text-uppercase mb-1">Paid Settlements</div>
                            <div class="h5 mb-0 fw-bold text-gray-800"><?php echo $paid_count; ?> trades</div>
                            <div class="mt-2 text-muted small">TZS <?php echo number_format($paid_value, 2); ?></div>
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
                            <div class="text-xs fw-bold text-info text-uppercase mb-1">Linked Settlements</div>
                            <div class="h5 mb-0 fw-bold text-gray-800"><?php echo $linked_count; ?> trades</div>
                            <div class="mt-2 text-muted small">TZS <?php echo number_format($linked_value, 2); ?></div>
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
                            <div class="text-xs fw-bold text-dark text-uppercase mb-1">Failed Settlements</div>
                            <div class="h5 mb-0 fw-bold text-gray-800"><?php echo $failed_count; ?> trades</div>
                            <div class="mt-2 text-muted small">TZS <?php echo number_format($failed_value, 2); ?></div>
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
    <div class="floating-bulk-payment" id="floatingBulkPayment" style="display: none;">
        <button type="button" class="btn btn-success btn-lg rounded-circle shadow-lg" onclick="showBulkPaymentModal()" data-bs-toggle="tooltip" data-bs-placement="left" title="Pay Selected Trades">
            <i class="bi bi-cash-coin"></i>
            <span class="badge bg-danger position-absolute top-0 start-100 translate-middle" id="selectedCountBadge">0</span>
        </button>
    </div>

    <!-- Status Filter Tabs -->
    <div class="card mb-4">
        <div class="card-header bg-transparent border-0">
            <ul class="nav nav-tabs nav-tabs-custom" id="settlementTabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" id="all-tab" data-bs-toggle="tab" data-bs-target="#all" type="button" role="tab">
                        <i class="bi bi-list-check me-2"></i>All Settlements
                        <span class="badge bg-primary ms-2"><?php echo $total_trades; ?></span>
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="overdue-tab" data-bs-toggle="tab" data-bs-target="#overdue" type="button" role="tab">
                        <i class="bi bi-exclamation-triangle me-2"></i>Overdue
                        <span class="badge bg-danger ms-2"><?php echo $overdue_count; ?></span>
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="today-tab" data-bs-toggle="tab" data-bs-target="#today" type="button" role="tab">
                        <i class="bi bi-calendar-day me-2"></i>Due Today
                        <span class="badge bg-warning ms-2"><?php echo $today_count; ?></span>
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="paid-tab" data-bs-toggle="tab" data-bs-target="#paid" type="button" role="tab">
                        <i class="bi bi-check-circle me-2"></i>Paid
                        <span class="badge bg-success ms-2"><?php echo $paid_count; ?></span>
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="linked-tab" data-bs-toggle="tab" data-bs-target="#linked" type="button" role="tab">
                        <i class="bi bi-link me-2"></i>Linked
                        <span class="badge bg-info ms-2"><?php echo $linked_count; ?></span>
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="failed-tab" data-bs-toggle="tab" data-bs-target="#failed" type="button" role="tab">
                        <i class="bi bi-x-circle me-2"></i>Failed
                        <span class="badge bg-dark ms-2"><?php echo $failed_count; ?></span>
                    </button>
                </li>
            </ul>
        </div>
        
        <div class="card-body">
            <div class="tab-content" id="settlementTabsContent">
                <!-- All Settlements Tab -->
                <div class="tab-pane fade show active" id="all" role="tabpanel">
                    <?php if (empty($settlement_trades)): ?>
                        <div class="text-center py-5">
                            <i class="bi bi-check-circle text-muted" style="font-size: 4rem; opacity: 0.3;"></i>
                            <h5 class="text-muted mt-3">No Settlements Due</h5>
                            <p class="text-muted">All trades are settled or no settlements due within the period.</p>
                            <div class="mt-3">
                                <small class="text-info">
                                    <i class="bi bi-info-circle"></i> Date range: <?php echo $two_days_ago; ?> to <?php echo $next_30_days; ?>
                                </small>
                            </div>
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
                                        <th>Counterparty</th>
                                        <th>Security</th>
                                        <th>Side</th>
                                        <th>Amount</th>
                                        <th>Settlement Date</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($settlement_trades as $trade): 
                                        $status_color = '';
                                        $status_icon = '';
                                        $status_text = '';
                                        
                                        if ($trade['settlement_status'] === 'paid') {
                                            $status_color = 'success';
                                            $status_icon = 'bi-check-circle';
                                            $status_text = 'Paid';
                                        } elseif ($trade['settlement_status'] === 'failed') {
                                            $status_color = 'dark';
                                            $status_icon = 'bi-x-circle';
                                            $status_text = 'Failed';
                                        } elseif ($trade['settlement_status'] === 'linked') {
                                            $status_color = 'info';
                                            $status_icon = 'bi-link';
                                            $status_text = 'Linked';
                                        } elseif ($trade['settlement_status_category'] === 'overdue') {
                                            $status_color = 'danger';
                                            $status_icon = 'bi-exclamation-triangle';
                                            $status_text = 'Overdue';
                                        } elseif ($trade['settlement_status_category'] === 'today') {
                                            $status_color = 'warning';
                                            $status_icon = 'bi-calendar-day';
                                            $status_text = 'Due Today';
                                        } else {
                                            $status_color = 'info';
                                            $status_icon = 'bi-calendar';
                                            $status_text = 'Upcoming';
                                        }
                                    ?>
                                        <tr>
                                            <td>
                                                <input type="checkbox" class="trade-checkbox" value="<?php echo $trade['id']; ?>" onchange="updateBulkActions()">
                                            </td>
                                            <td>
                                                <div class="fw-semibold"><?php echo htmlspecialchars($trade['trade_reference']); ?></div>
                                                <small class="text-muted">Trade ID: <?php echo $trade['id']; ?></small>
                                            </td>
                                            <td>
                                                <div class="fw-medium"><?php echo htmlspecialchars($trade['client_name']); ?></div>
                                                <small class="text-muted"><?php echo htmlspecialchars($trade['client_cds_account']); ?></small>
                                            </td>
                                            <td>
                                                <div class="fw-medium"><?php echo htmlspecialchars($trade['counterparty_name']); ?></div>
                                                <small class="text-muted"><?php echo htmlspecialchars($trade['counterparty_cds_account']); ?></small>
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
                                            <td>
                                                <div class="fw-bold text-success">TZS <?php echo number_format($trade['consideration'], 2); ?></div>
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
                                                <?php if ($trade['settled_by_username']): ?>
                                                    <small class="d-block text-muted">By: <?php echo $trade['settled_by_username']; ?></small>
                                                <?php endif; ?>
                                                <?php if ($trade['linked_trade_id']): ?>
                                                    <small class="d-block text-info">
                                                        <i class="bi bi-link"></i> Linked to Buy #<?php echo $trade['linked_trade_id']; ?>
                                                    </small>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($trade['settlement_status'] === 'linked'): ?>
                                                    <button type="button" class="btn btn-outline-info btn-sm" onclick="showLinkedDetails(<?php echo $trade['id']; ?>, <?php echo $trade['linked_trade_id']; ?>, '<?php echo addslashes($trade['linked_trade_ref']); ?>', '<?php echo addslashes($trade['linked_client_name']); ?>', '<?php echo addslashes($trade['linked_security']); ?>', '<?php echo $trade['linked_amount']; ?>')">
                                                        <i class="bi bi-eye"></i> View Link
                                                    </button>
                                                <?php elseif ($trade['settlement_status'] === 'paid'): ?>
                                                    <button type="button" class="btn btn-outline-secondary btn-sm" onclick="markAsUnpaid(<?php echo $trade['id']; ?>)">
                                                        <i class="bi bi-arrow-counterclockwise"></i> Undo
                                                    </button>
                                                <?php elseif ($trade['settlement_status'] === 'failed'): ?>
                                                    <div class="btn-group btn-group-sm">
                                                        <button type="button" class="btn btn-outline-warning btn-sm" data-bs-toggle="modal" data-bs-target="#failureDetailsModal" 
                                                                onclick="showFailureDetails(<?php echo $trade['id']; ?>, '<?php echo addslashes($trade['failure_reason']); ?>', '<?php echo addslashes($trade['action_needed']); ?>')">
                                                            <i class="bi bi-info-circle"></i> Details
                                                        </button>
                                                        <button type="button" class="btn btn-outline-success btn-sm" onclick="retryFailed(<?php echo $trade['id']; ?>)">
                                                            <i class="bi bi-arrow-repeat"></i> Retry
                                                        </button>
                                                    </div>
                                                <?php elseif ($trade['trade_side'] === 'sell'): ?>
                                                    <div class="btn-group btn-group-sm">
                                                        <button type="button" class="btn btn-outline-success" onclick="showPaymentModal(<?php echo $trade['id']; ?>)">
                                                            <i class="bi bi-cash-coin"></i> Pay
                                                        </button>
                                                        <button type="button" class="btn btn-outline-info" onclick="showLinkTradeModal(<?php echo $trade['id']; ?>)">
                                                            <i class="bi bi-link"></i> Link
                                                        </button>
                                                        <button type="button" class="btn btn-outline-danger" onclick="markAsFailed(<?php echo $trade['id']; ?>)">
                                                            <i class="bi bi-x-lg"></i> Failed
                                                        </button>
                                                    </div>
                                                <?php else: ?>
                                                    <div class="btn-group btn-group-sm">
                                                        <button type="button" class="btn btn-outline-success" onclick="showPaymentModal(<?php echo $trade['id']; ?>)">
                                                            <i class="bi bi-cash-coin"></i> Pay
                                                        </button>
                                                        <button type="button" class="btn btn-outline-danger" onclick="markAsFailed(<?php echo $trade['id']; ?>)">
                                                            <i class="bi bi-x-lg"></i> Failed
                                                        </button>
                                                    </div>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        
                        <!-- Pagination -->
                        <?php if ($total_pages > 1): ?>
                        <nav aria-label="Page navigation">
                            <ul class="pagination justify-content-center">
                                <?php if ($page > 1): ?>
                                    <li class="page-item">
                                        <a class="page-link" href="?page=1&side=<?php echo $trade_side_filter; ?>&hide_buy=<?php echo $hide_buy_orders; ?>" aria-label="First">
                                            <span aria-hidden="true">&laquo;&laquo;</span>
                                        </a>
                                    </li>
                                    <li class="page-item">
                                        <a class="page-link" href="?page=<?php echo $page - 1; ?>&side=<?php echo $trade_side_filter; ?>&hide_buy=<?php echo $hide_buy_orders; ?>" aria-label="Previous">
                                            <span aria-hidden="true">&laquo;</span>
                                        </a>
                                    </li>
                                <?php endif; ?>
                                
                                <?php
                                $start_page = max(1, $page - 2);
                                $end_page = min($total_pages, $start_page + 4);
                                
                                if ($end_page - $start_page < 4) {
                                    $start_page = max(1, $end_page - 4);
                                }
                                
                                for ($i = $start_page; $i <= $end_page; $i++): ?>
                                    <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                                        <a class="page-link" href="?page=<?php echo $i; ?>&side=<?php echo $trade_side_filter; ?>&hide_buy=<?php echo $hide_buy_orders; ?>">
                                            <?php echo $i; ?>
                                        </a>
                                    </li>
                                <?php endfor; ?>
                                
                                <?php if ($page < $total_pages): ?>
                                    <li class="page-item">
                                        <a class="page-link" href="?page=<?php echo $page + 1; ?>&side=<?php echo $trade_side_filter; ?>&hide_buy=<?php echo $hide_buy_orders; ?>" aria-label="Next">
                                            <span aria-hidden="true">&raquo;</span>
                                        </a>
                                    </li>
                                    <li class="page-item">
                                        <a class="page-link" href="?page=<?php echo $total_pages; ?>&side=<?php echo $trade_side_filter; ?>&hide_buy=<?php echo $hide_buy_orders; ?>" aria-label="Last">
                                            <span aria-hidden="true">&raquo;&raquo;</span>
                                        </a>
                                    </li>
                                <?php endif; ?>
                            </ul>
                            <div class="text-center text-muted small mt-2">
                                Showing <?php echo min($records_per_page, count($settlement_trades)); ?> of <?php echo $total_records; ?> records
                            </div>
                        </nav>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
                
                <!-- Overdue Tab -->
                <div class="tab-pane fade" id="overdue" role="tabpanel">
                    <?php 
                    $overdue_trades = array_filter($settlement_trades, function($trade) {
                        return $trade['settlement_status_category'] === 'overdue' && 
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
                                                        <button type="button" class="btn btn-success" onclick="showPaymentModal(<?php echo $trade['id']; ?>)">
                                                            <i class="bi bi-cash-coin"></i> Pay Now
                                                        </button>
                                                        <button type="button" class="btn btn-info" onclick="showLinkTradeModal(<?php echo $trade['id']; ?>)">
                                                            <i class="bi bi-link"></i> Link
                                                        </button>
                                                        <button type="button" class="btn btn-danger" onclick="markAsFailed(<?php echo $trade['id']; ?>)">
                                                            <i class="bi bi-x-lg"></i> Failed
                                                        </button>
                                                    </div>
                                                <?php else: ?>
                                                    <div class="btn-group btn-group-sm">
                                                        <button type="button" class="btn btn-success" onclick="showPaymentModal(<?php echo $trade['id']; ?>)">
                                                            <i class="bi bi-cash-coin"></i> Pay Now
                                                        </button>
                                                        <button type="button" class="btn btn-danger" onclick="markAsFailed(<?php echo $trade['id']; ?>)">
                                                            <i class="bi bi-x-lg"></i> Failed
                                                        </button>
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
                        return $trade['settlement_status_category'] === 'today' && 
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
                                                        <button type="button" class="btn btn-success" onclick="showPaymentModal(<?php echo $trade['id']; ?>)">
                                                            <i class="bi bi-cash-coin"></i> Pay Now
                                                        </button>
                                                        <button type="button" class="btn btn-info" onclick="showLinkTradeModal(<?php echo $trade['id']; ?>)">
                                                            <i class="bi bi-link"></i> Link
                                                        </button>
                                                        <button type="button" class="btn btn-danger" onclick="markAsFailed(<?php echo $trade['id']; ?>)">
                                                            <i class="bi bi-x-lg"></i> Failed
                                                        </button>
                                                    </div>
                                                <?php else: ?>
                                                    <div class="btn-group btn-group-sm">
                                                        <button type="button" class="btn btn-success" onclick="showPaymentModal(<?php echo $trade['id']; ?>)">
                                                            <i class="bi bi-cash-coin"></i> Pay Now
                                                        </button>
                                                        <button type="button" class="btn btn-danger" onclick="markAsFailed(<?php echo $trade['id']; ?>)">
                                                            <i class="bi bi-x-lg"></i> Failed
                                                        </button>
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
                                                <button type="button" class="btn btn-outline-secondary btn-sm" onclick="markAsUnpaid(<?php echo $trade['id']; ?>)">
                                                    <i class="bi bi-arrow-counterclockwise"></i> Undo
                                                </button>
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
                                                <button type="button" class="btn btn-outline-info btn-sm" onclick="showLinkedDetails(<?php echo $trade['id']; ?>, <?php echo $trade['linked_trade_id']; ?>, '<?php echo addslashes($trade['linked_trade_ref']); ?>', '<?php echo addslashes($trade['linked_client_name']); ?>', '<?php echo addslashes($trade['linked_security']); ?>', '<?php echo $trade['linked_amount']; ?>')">
                                                    <i class="bi bi-eye"></i> View Link
                                                </button>
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

<!-- Single Payment Modal -->
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
                        <span id="bulkPaymentCount">0</span> trades will be paid. Each payment will be recorded with a unique payment number.
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

<!-- Link Trade Modal -->
<div class="modal fade" id="linkTradeModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-info">
                <h5 class="modal-title">Link Sale to Buy Trade</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="linkTradeForm" method="POST">
                <input type="hidden" name="trade_id" id="linkTradeId">
                <input type="hidden" name="link_trade" value="1">
                
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="linked_trade_id" class="form-label">Select Buy Trade to Link <span class="text-danger">*</span></label>
                        <select class="form-select" id="linked_trade_id" name="linked_trade_id" required>
                            <option value="">Select Buy Trade</option>
                            <?php 
                            $buy_trades = array_filter($all_trades, function($trade) {
                                return $trade['trade_side'] === 'buy' && $trade['settlement_status'] !== 'linked';
                            });
                            foreach ($buy_trades as $trade): ?>
                                <option value="<?php echo $trade['id']; ?>">
                                    <?php echo htmlspecialchars($trade['trade_reference'] . ' - ' . $trade['client_name'] . ' - ' . $trade['security_id'] . ' - TZS ' . number_format($trade['consideration'], 2)); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle me-2"></i>
                        Linking this sale to a buy trade will mark it as settled without payment. The buy trade will not require separate payment receipt.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-info">Link Trade</button>
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
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
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
            <div class="modal-header bg-dark">
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

<!-- Forms for other actions -->
<form id="unpaidForm" method="POST" style="display: none;">
    <input type="hidden" name="trade_id" id="unpaidTradeId">
    <input type="hidden" name="mark_unpaid" value="1">
</form>

<form id="retryFailedForm" method="POST" style="display: none;">
    <input type="hidden" name="trade_id" id="retryTradeId">
    <input type="hidden" name="retry_failed" value="1">
</form>

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

<script>
// Trade ID for current actions
let currentTradeId = null;
let selectedTradeIds = [];

// Bulk selection functions
function toggleSelectAll(checkbox) {
    const checkboxes = document.querySelectorAll('.trade-checkbox');
    checkboxes.forEach(cb => {
        cb.checked = checkbox.checked;
        if (checkbox.checked) {
            if (!selectedTradeIds.includes(cb.value)) {
                selectedTradeIds.push(cb.value);
            }
        } else {
            const index = selectedTradeIds.indexOf(cb.value);
            if (index > -1) {
                selectedTradeIds.splice(index, 1);
            }
        }
    });
    updateBulkActions();
}

function updateBulkActions() {
    const checkboxes = document.querySelectorAll('.trade-checkbox:checked');
    selectedTradeIds = Array.from(checkboxes).map(cb => cb.value);
    const selectedCount = selectedTradeIds.length;
    const floatingBtn = document.getElementById('floatingBulkPayment');
    const badge = document.getElementById('selectedCountBadge');
    
    badge.textContent = selectedCount;
    
    if (selectedCount > 0) {
        floatingBtn.style.display = 'block';
    } else {
        floatingBtn.style.display = 'none';
    }
}

function clearSelection() {
    const checkboxes = document.querySelectorAll('.trade-checkbox');
    checkboxes.forEach(cb => cb.checked = false);
    document.getElementById('selectAll').checked = false;
    selectedTradeIds = [];
    updateBulkActions();
}

function resetFilters() {
    window.location.href = 'settlement';
}

// Single payment functions
function showPaymentModal(tradeId) {
    currentTradeId = tradeId;
    document.getElementById('paymentTradeId').value = tradeId;
    document.getElementById('paymentForm').reset();
    document.getElementById('bankAccountField').style.display = 'none';
    document.getElementById('bankBalanceInfo').textContent = '';
    
    const paymentModal = new bootstrap.Modal(document.getElementById('paymentModal'));
    paymentModal.show();
}

function toggleBankSelection() {
    const paymentMode = document.getElementById('payment_mode').value;
    const bankField = document.getElementById('bankAccountField');
    const bankSelect = document.getElementById('bank_account');
    const balanceInfo = document.getElementById('bankBalanceInfo');
    
    // Show bank selection only for methods that require bank transfer
    // Bank transfer methods typically have IDs like 1, 2, 3, etc.
    // You need to update these based on your payment_methods table
    const bankMethods = ['1', '2', '3']; // Example IDs for bank transfer methods
    
    if (bankMethods.includes(paymentMode)) {
        bankField.style.display = 'block';
        bankSelect.required = true;
        
        bankSelect.addEventListener('change', function() {
            const selectedOption = this.options[this.selectedIndex];
            if (selectedOption && selectedOption.value) {
                const balance = selectedOption.getAttribute('data-balance');
                const currency = selectedOption.getAttribute('data-currency');
                balanceInfo.textContent = `Current Balance: ${currency} ${parseFloat(balance).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}`;
            } else {
                balanceInfo.textContent = '';
            }
        });
        
        // Reset and trigger change
        bankSelect.selectedIndex = 0;
        bankSelect.dispatchEvent(new Event('change'));
    } else {
        bankField.style.display = 'none';
        bankSelect.required = false;
        balanceInfo.textContent = '';
    }
}

// Bulk payment functions
function showBulkPaymentModal() {
    if (selectedTradeIds.length === 0) {
        alert('Please select at least one trade to pay.');
        return;
    }
    
    // Validate that selected trades are not already paid/linked
    let validTrades = [];
    let invalidTrades = [];
    
    const checkboxes = document.querySelectorAll('.trade-checkbox:checked');
    checkboxes.forEach(cb => {
        const row = cb.closest('tr');
        const statusBadge = row.querySelector('.badge');
        if (statusBadge) {
            const statusText = statusBadge.textContent.trim();
            if (statusText.includes('Paid') || statusText.includes('Linked') || statusText.includes('Failed')) {
                const tradeRef = row.querySelector('td:nth-child(2) .fw-semibold').textContent;
                invalidTrades.push(tradeRef);
            } else {
                validTrades.push(cb.value);
            }
        }
    });
    
    if (invalidTrades.length > 0) {
        alert('Some selected trades are already paid, linked, or failed:\n' + invalidTrades.join(', ') + '\n\nOnly unpaid trades will be processed.');
    }
    
    if (validTrades.length === 0) {
        alert('No valid unpaid trades selected.');
        return;
    }
    
    // Create hidden inputs for trade IDs
    const container = document.getElementById('bulkPaymentTradeIds');
    container.innerHTML = '';
    
    validTrades.forEach(tradeId => {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'trade_ids[]';
        input.value = tradeId;
        container.appendChild(input);
    });
    
    // Update count
    document.getElementById('bulkPaymentCount').textContent = validTrades.length;
    
    // Reset form and show modal
    document.getElementById('bulkPaymentForm').reset();
    document.getElementById('bulkBankAccountField').style.display = 'none';
    document.getElementById('bulkBankBalanceInfo').textContent = '';
    
    const bulkPaymentModal = new bootstrap.Modal(document.getElementById('bulkPaymentModal'));
    bulkPaymentModal.show();
}

function toggleBulkBankSelection() {
    const paymentMode = document.getElementById('bulk_payment_mode').value;
    const bankField = document.getElementById('bulkBankAccountField');
    const bankSelect = document.getElementById('bulk_bank_account');
    const balanceInfo = document.getElementById('bulkBankBalanceInfo');
    
    // Show bank selection only for methods that require bank transfer
    const bankMethods = ['1', '2', '3']; // Example IDs for bank transfer methods
    
    if (bankMethods.includes(paymentMode)) {
        bankField.style.display = 'block';
        bankSelect.required = true;
        
        bankSelect.addEventListener('change', function() {
            const selectedOption = this.options[this.selectedIndex];
            if (selectedOption && selectedOption.value) {
                const balance = selectedOption.getAttribute('data-balance');
                const currency = selectedOption.getAttribute('data-currency');
                balanceInfo.textContent = `Current Balance: ${currency} ${parseFloat(balance).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}`;
            } else {
                balanceInfo.textContent = '';
            }
        });
        
        // Reset and trigger change
        bankSelect.selectedIndex = 0;
        bankSelect.dispatchEvent(new Event('change'));
    } else {
        bankField.style.display = 'none';
        bankSelect.required = false;
        balanceInfo.textContent = '';
    }
}

// Link trade functions
function showLinkTradeModal(tradeId) {
    currentTradeId = tradeId;
    document.getElementById('linkTradeId').value = tradeId;
    document.getElementById('linkTradeForm').reset();
    
    const linkModal = new bootstrap.Modal(document.getElementById('linkTradeModal'));
    linkModal.show();
}

function showLinkedDetails(tradeId, linkedTradeId, linkedRef, linkedClient, linkedSecurity, linkedAmount) {
    document.getElementById('linkedSaleDetails').innerHTML = `
        <strong>Trade ID:</strong> ${tradeId}<br>
        <strong>Status:</strong> Linked<br>
        <strong>Linked To:</strong> Buy Trade #${linkedTradeId}
    `;
    
    document.getElementById('linkedBuyDetails').innerHTML = `
        <strong>Trade Reference:</strong> ${linkedRef || 'N/A'}<br>
        <strong>Client:</strong> ${linkedClient || 'N/A'}<br>
        <strong>Security:</strong> ${linkedSecurity || 'N/A'}<br>
        <strong>Amount:</strong> TZS ${parseFloat(linkedAmount).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2}) || 'N/A'}<br>
        <strong>Trade ID:</strong> ${linkedTradeId}
    `;
    
    const linkedModal = new bootstrap.Modal(document.getElementById('linkedDetailsModal'));
    linkedModal.show();
}

// Single action functions
function markAsUnpaid(tradeId) {
    if (confirm('Are you sure you want to undo this payment? This will mark the trade as unpaid and deactivate the payment record.')) {
        document.getElementById('unpaidTradeId').value = tradeId;
        document.getElementById('unpaidForm').submit();
    }
}

function markAsFailed(tradeId) {
    currentTradeId = tradeId;
    document.getElementById('failureTradeId').value = tradeId;
    document.getElementById('failureForm').reset();
    
    const failureModal = new bootstrap.Modal(document.getElementById('failureModal'));
    failureModal.show();
}

function retryFailed(tradeId) {
    if (confirm('Reset this failed trade for payment retry?')) {
        document.getElementById('retryTradeId').value = tradeId;
        document.getElementById('retryFailedForm').submit();
    }
}

function showFailureDetails(tradeId, reason, action) {
    document.getElementById('detailsFailureReason').textContent = reason || 'No reason provided';
    document.getElementById('detailsActionNeeded').textContent = action || 'No action specified';
    
    const detailsModal = new bootstrap.Modal(document.getElementById('failureDetailsModal'));
    detailsModal.show();
}

// Initialize when page loads
document.addEventListener('DOMContentLoaded', function() {
    // Initialize tooltips
    const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });
    
    // Initialize checkboxes
    const checkboxes = document.querySelectorAll('.trade-checkbox');
    checkboxes.forEach(cb => {
        cb.addEventListener('change', updateBulkActions);
    });
    
    // Update bulk actions on page load
    updateBulkActions();
    
    // Show debug info on Ctrl+Shift+D
    document.addEventListener('keydown', function(e) {
        if (e.ctrlKey && e.shiftKey && e.key === 'D') {
            e.preventDefault();
            const debugInfo = document.getElementById('debugInfo');
            if (debugInfo) {
                debugInfo.style.display = debugInfo.style.display === 'none' ? 'block' : 'none';
            }
        }
    });
});
</script>

<?php include '../includes/footer.php'; ?>
