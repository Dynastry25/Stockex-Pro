<?php
/**
 * RECEIPT UPLOAD WITH COMMISSION CALCULATION
 * Based on actual fee structure from trades.php
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Includes
require_once '../config/config.php';
require_once '../auth/auth_middleware.php';

// Security
require_login();
$user_role = $_SESSION['role'] ?? '';
$allowed_roles = ['finance_officer', 'system_admin', 'trader'];
if (!in_array($user_role, $allowed_roles)) {
    redirect('auth/login.php');
    exit;
}
if ($user_role !== 'system_admin') {
    require_mandate();
}

$db = getDBConnection();
$current_user = get_logged_in_user() ?: get_session_user();
$user_name = $current_user['username'] ?? 'System';
$user_id = $current_user['id'] ?? null;

// ============================================
// FEE CONFIGURATION FROM trades.php
// ============================================

// Bond Fee Structure
// Brokerage: First 100M @ 0.063132%, Excess @ 0.035%
function calculateBondBrokerageFee($face_value) {
    $brokerage_first_100m = min($face_value, 100000000) * (0.063132 / 100);
    $brokerage_excess = max($face_value - 100000000, 0) * (0.035 / 100);
    return $brokerage_first_100m + $brokerage_excess;
}

// Bond: Liberty on excess only (first 100M standard rate)
function calculateBondBrokerageWithLibertyExcess($face_value, $liberty_rate) {
    $brokerage_first_100m = min($face_value, 100000000) * (0.063132 / 100);
    $brokerage_excess = max($face_value - 100000000, 0) * ($liberty_rate / 100);
    return $brokerage_first_100m + $brokerage_excess;
}

// Bond: Liberty replaces entire calculation
function calculateBondBrokerageWithLibertyReplaceAll($face_value, $liberty_rate) {
    return $face_value * ($liberty_rate / 100);
}

// Equity Fee Structure (Tiered)
function calculateEquityBrokerageFee($consideration) {
    $rate1 = 1.7000; // First 10M
    $rate2 = 1.5000; // Next 40M
    $rate3 = 0.8000; // Excess over 50M
    
    if ($consideration <= 10000000) {
        return $consideration * ($rate1 / 100);
    } elseif ($consideration <= 50000000) {
        return 10000000 * ($rate1 / 100) + ($consideration - 10000000) * ($rate2 / 100);
    } else {
        return 10000000 * ($rate1 / 100) + 40000000 * ($rate2 / 100) + ($consideration - 50000000) * ($rate3 / 100);
    }
}

// Equity: Liberty tier override (first 10M standard, excess liberty)
function calculateEquityBrokerageWithLibertyTierOverride($consideration, $liberty_rate) {
    $standard_rate = 1.7000;
    $standard_rate_decimal = $standard_rate / 100;
    $liberty_rate_decimal = $liberty_rate / 100;
    
    if ($consideration <= 10000000) {
        return $consideration * $standard_rate_decimal;
    } else {
        $first_tier = 10000000 * $standard_rate_decimal;
        $excess = ($consideration - 10000000) * $liberty_rate_decimal;
        return $first_tier + $excess;
    }
}

// Equity: Liberty replaces entire calculation
function calculateEquityBrokerageWithLibertyReplaceAll($consideration, $liberty_rate) {
    return $consideration * ($liberty_rate / 100);
}

// ============================================
// MAIN COMMISSION CALCULATION FUNCTION
// ============================================

function calculateFullFees($trade, $effective_rate, $liberty_mode = 'replace_all', $is_liberty = false) {
    $is_bond = ($trade['asset_class'] === 'bond' || $trade['asset_class'] === 'treasury_bond');
    $consideration = floatval($trade['consideration']);
    $quantity = floatval($trade['quantity']);
    $price = floatval($trade['price']);
    $face_value = $quantity;
    
    $fees = [];
    $fees['tier_details'] = [];
    
    if ($is_bond) {
        // --- BOND CALCULATION ---
        if ($is_liberty && $effective_rate > 0) {
            if ($liberty_mode === 'excess_only') {
                // Liberty on excess only
                $brokerage_first_100m = min($face_value, 100000000) * (0.063132 / 100);
                $brokerage_excess = max($face_value - 100000000, 0) * ($effective_rate / 100);
                $fees['brokerage'] = $brokerage_first_100m + $brokerage_excess;
                
                if ($face_value > 100000000) {
                    $fees['tier_details'][] = [
                        'amount' => 100000000,
                        'rate' => 0.063132,
                        'fee' => $brokerage_first_100m,
                        'label' => 'First 100M @ 0.063132%'
                    ];
                    $fees['tier_details'][] = [
                        'amount' => $face_value - 100000000,
                        'rate' => $effective_rate,
                        'fee' => $brokerage_excess,
                        'label' => 'Excess @ ' . number_format($effective_rate, 4) . '% (Liberty)'
                    ];
                } else {
                    $fees['tier_details'][] = [
                        'amount' => $face_value,
                        'rate' => 0.063132,
                        'fee' => $brokerage_first_100m,
                        'label' => 'Full Amount @ 0.063132%'
                    ];
                }
            } else {
                // Liberty replace all
                $fees['brokerage'] = $face_value * ($effective_rate / 100);
                $fees['tier_details'][] = [
                    'amount' => $face_value,
                    'rate' => $effective_rate,
                    'fee' => $fees['brokerage'],
                    'label' => 'Full Amount @ ' . number_format($effective_rate, 4) . '% (Liberty)'
                ];
            }
        } else {
            // Standard bond calculation
            $brokerage_first_100m = min($face_value, 100000000) * (0.063132 / 100);
            $brokerage_excess = max($face_value - 100000000, 0) * (0.035 / 100);
            $fees['brokerage'] = $brokerage_first_100m + $brokerage_excess;
            
            if ($face_value <= 100000000) {
                $fees['tier_details'][] = [
                    'amount' => $face_value,
                    'rate' => 0.063132,
                    'fee' => $brokerage_first_100m,
                    'label' => 'Full Amount @ 0.063132%'
                ];
            } else {
                $fees['tier_details'][] = [
                    'amount' => 100000000,
                    'rate' => 0.063132,
                    'fee' => $brokerage_first_100m,
                    'label' => 'First 100M @ 0.063132%'
                ];
                $fees['tier_details'][] = [
                    'amount' => $face_value - 100000000,
                    'rate' => 0.035,
                    'fee' => $brokerage_excess,
                    'label' => 'Excess @ 0.035%'
                ];
            }
        }
        
        // Bond other fees
        $fees['vat'] = $fees['brokerage'] * 0.18; // 18% VAT on brokerage
        $fees['cmsa'] = $consideration * (0.01 / 100); // 0.01% of consideration
        $fees['dse'] = $face_value * (0.02006 / 100); // 0.02006% of face value (VAT inclusive)
        $fees['csd'] = $face_value * (0.0118 / 100); // 0.0118% of face value (VAT inclusive)
        $fees['fidelity'] = 0.00;
        
    } else {
        // --- EQUITY / ETF CALCULATION ---
        if ($is_liberty && $effective_rate > 0) {
            if ($liberty_mode === 'tier_override') {
                // Tier override: first 10M standard, excess liberty
                $standard_rate = 1.7000;
                $standard_rate_decimal = $standard_rate / 100;
                $liberty_rate_decimal = $effective_rate / 100;
                
                if ($consideration <= 10000000) {
                    $fees['brokerage'] = $consideration * $standard_rate_decimal;
                    $fees['tier_details'][] = [
                        'amount' => $consideration,
                        'rate' => $standard_rate,
                        'fee' => $fees['brokerage'],
                        'label' => 'Up to 10M @ ' . number_format($standard_rate, 4) . '% (Standard)'
                    ];
                } else {
                    $first_tier = 10000000 * $standard_rate_decimal;
                    $excess = ($consideration - 10000000) * $liberty_rate_decimal;
                    $fees['brokerage'] = $first_tier + $excess;
                    
                    $fees['tier_details'][] = [
                        'amount' => 10000000,
                        'rate' => $standard_rate,
                        'fee' => $first_tier,
                        'label' => 'First 10M @ ' . number_format($standard_rate, 4) . '% (Standard)'
                    ];
                    $fees['tier_details'][] = [
                        'amount' => $consideration - 10000000,
                        'rate' => $effective_rate,
                        'fee' => $excess,
                        'label' => 'Excess @ ' . number_format($effective_rate, 4) . '% (Liberty)'
                    ];
                }
            } else {
                // Liberty replace all
                $fees['brokerage'] = $consideration * ($effective_rate / 100);
                $fees['tier_details'][] = [
                    'amount' => $consideration,
                    'rate' => $effective_rate,
                    'fee' => $fees['brokerage'],
                    'label' => 'Full Consideration @ ' . number_format($effective_rate, 4) . '% (Liberty)'
                ];
            }
        } else {
            // Standard equity tiered calculation
            $rate1 = 1.7000; // First 10M
            $rate2 = 1.5000; // Next 40M
            $rate3 = 0.8000; // Excess over 50M

            if ($consideration <= 10000000) {
                $fees['brokerage'] = $consideration * ($rate1 / 100);
                $fees['tier_details'][] = [
                    'amount' => $consideration,
                    'rate' => $rate1,
                    'fee' => $fees['brokerage'],
                    'label' => 'Up to 10M @ ' . number_format($rate1, 4) . '%'
                ];
            } elseif ($consideration <= 50000000) {
                $tier1 = 10000000 * ($rate1 / 100);
                $tier2 = ($consideration - 10000000) * ($rate2 / 100);
                $fees['brokerage'] = $tier1 + $tier2;

                $fees['tier_details'][] = [
                    'amount' => 10000000,
                    'rate' => $rate1,
                    'fee' => $tier1,
                    'label' => 'First 10M @ ' . number_format($rate1, 4) . '%'
                ];
                $fees['tier_details'][] = [
                    'amount' => $consideration - 10000000,
                    'rate' => $rate2,
                    'fee' => $tier2,
                    'label' => 'Next ' . number_format(($consideration - 10000000)/1000000, 1) . 'M @ ' . number_format($rate2, 4) . '%'
                ];
            } else {
                $tier1 = 10000000 * ($rate1 / 100);
                $tier2 = 40000000 * ($rate2 / 100);
                $tier3 = ($consideration - 50000000) * ($rate3 / 100);
                $fees['brokerage'] = $tier1 + $tier2 + $tier3;

                $fees['tier_details'][] = [
                    'amount' => 10000000,
                    'rate' => $rate1,
                    'fee' => $tier1,
                    'label' => 'First 10M @ ' . number_format($rate1, 4) . '%'
                ];
                $fees['tier_details'][] = [
                    'amount' => 40000000,
                    'rate' => $rate2,
                    'fee' => $tier2,
                    'label' => 'Next 40M @ ' . number_format($rate2, 4) . '%'
                ];
                $fees['tier_details'][] = [
                    'amount' => $consideration - 50000000,
                    'rate' => $rate3,
                    'fee' => $tier3,
                    'label' => 'Excess @ ' . number_format($rate3, 4) . '%'
                ];
            }
        }
        
        // Equity other fees
        $fees['vat'] = $fees['brokerage'] * 0.18; // 18% VAT on brokerage
        $fees['cmsa'] = $consideration * (0.1400 / 100); // 0.14% of consideration
        $fees['dse'] = $consideration * (0.1652 / 100); // 0.1652% of consideration (VAT inclusive)
        $fees['fidelity'] = $consideration * (0.0200 / 100); // 0.02% of consideration
        $fees['csd'] = $consideration * (0.0708 / 100); // 0.0708% of consideration (VAT inclusive)
    }
    
    // Calculate total
    $fees['total'] = array_sum([
        $fees['brokerage'],
        $fees['vat'],
        $fees['cmsa'],
        $fees['dse'],
        $fees['fidelity'] ?? 0,
        $fees['csd']
    ]);
    
    // Determine if deducted or included based on trade side
    $trade_side = strtolower($trade['trade_side'] ?? 'buy');
    $fees['is_deducted'] = ($trade_side === 'sell');
    $fees['operation'] = $fees['is_deducted'] ? 'deducted' : 'added';
    $fees['label'] = $fees['is_deducted'] ? 'Proceeds - Commission' : 'Cost + Commission';
    $fees['net_amount'] = $fees['is_deducted'] ? 
        $consideration - $fees['total'] : 
        $consideration + $fees['total'];
    
    // Add rate info for display
    $fees['effective_rate'] = $effective_rate;
    $fees['is_liberty'] = $is_liberty;
    $fees['liberty_mode'] = $liberty_mode;
    $fees['asset_class'] = $is_bond ? 'bond' : 'equity';
    
    return $fees;
}

// ============================================
// TABLE SETUP
// ============================================
try {
    $db->exec("CREATE TABLE IF NOT EXISTS numeric_trade_receipts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        trade_id INT NOT NULL,
        trade_type VARCHAR(50) DEFAULT 'trade',
        payment_receipt TEXT,
        commission_receipt TEXT,
        comment TEXT,
        is_approved TINYINT DEFAULT 0,
        approved_by VARCHAR(100),
        approved_at DATETIME,
        uploaded_by VARCHAR(100),
        created_at DATETIME,
        updated_at DATETIME,
        INDEX idx_trade_id (trade_id)
    )");
    
    $db->exec("CREATE TABLE IF NOT EXISTS receipt_files (
        id INT AUTO_INCREMENT PRIMARY KEY,
        trade_id INT NOT NULL,
        receipt_type VARCHAR(20) NOT NULL DEFAULT 'payment',
        file_data LONGBLOB NOT NULL,
        file_name VARCHAR(255) NOT NULL,
        file_size INT NOT NULL DEFAULT 0,
        mime_type VARCHAR(100) NOT NULL DEFAULT '',
        uploaded_by VARCHAR(100),
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        KEY trade_id (trade_id)
    )");
    
    $db->exec("ALTER TABLE numeric_trade_receipts ADD COLUMN IF NOT EXISTS commission_receipt TEXT AFTER payment_receipt");
    $db->exec("ALTER TABLE numeric_trade_receipts ADD COLUMN IF NOT EXISTS comment TEXT AFTER commission_receipt");
    $db->exec("ALTER TABLE trades ADD COLUMN IF NOT EXISTS approval_status ENUM('pending','approved','rejected') DEFAULT 'pending' AFTER status");
    
} catch (Exception $e) {
    error_log("Table setup error: " . $e->getMessage());
}

// ============================================
// RECEIPT HELPERS
// ============================================
function getReceiptUrl($ref) {
    if (empty($ref)) return '';
    if (strpos($ref, 'db_') === 0) {
        $id = (int)substr($ref, 3);
        return 'serve_receipt.php?id=' . $id;
    }
    return '../uploads/numeric_receipts/' . $ref;
}

function isImageReceipt($ref) {
    if (empty($ref)) return false;
    $ext = strtolower(pathinfo($ref, PATHINFO_EXTENSION));
    return in_array($ext, ['jpg', 'jpeg', 'png', 'gif']);
}

// ============================================
// HANDLE UPLOAD
// ============================================
if (isset($_POST['upload_receipt'])) {
    $trade_id = (int)$_POST['trade_id'];
    $receipt_type = $_POST['receipt_type'] ?? 'payment';
    $comment = trim($_POST['receipt_comment'] ?? '');
    $errors = [];
    $uploaded = [];
    
    $stmt = $db->prepare("SELECT payment_receipt, commission_receipt, comment FROM numeric_trade_receipts WHERE trade_id = ? AND trade_type = 'trade'");
    $stmt->execute([$trade_id]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $existing_payment = !empty($existing['payment_receipt']) ? explode(',', $existing['payment_receipt']) : [];
    $existing_commission = !empty($existing['commission_receipt']) ? explode(',', $existing['commission_receipt']) : [];
    $existing_comment = $existing['comment'] ?? '';
    
    if (isset($_FILES['receipt_files']) && !empty($_FILES['receipt_files']['name'][0])) {
        $files = $_FILES['receipt_files'];
        $allowed = ['jpg', 'jpeg', 'png', 'gif', 'pdf'];
        $max_size = 5 * 1024 * 1024;
        
        for ($i = 0; $i < count($files['name']); $i++) {
            if ($files['error'][$i] !== UPLOAD_ERR_OK) {
                $errors[] = "Error uploading: " . $files['name'][$i];
                continue;
            }
            
            $ext = strtolower(pathinfo($files['name'][$i], PATHINFO_EXTENSION));
            if (!in_array($ext, $allowed)) {
                $errors[] = "Invalid type: " . $files['name'][$i];
                continue;
            }
            
            if ($files['size'][$i] > $max_size) {
                $errors[] = "File too large: " . $files['name'][$i];
                continue;
            }
            
            $stmt = $db->prepare("INSERT INTO receipt_files (trade_id, receipt_type, file_data, file_name, file_size, mime_type, uploaded_by) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $trade_id,
                $receipt_type,
                file_get_contents($files['tmp_name'][$i]),
                $files['name'][$i],
                $files['size'][$i],
                $files['type'][$i] ?: 'application/octet-stream',
                $user_name
            ]);
            
            $file_id = $db->lastInsertId();
            $uploaded[] = 'db_' . $file_id . '.' . $ext;
        }
    }
    
    $full_comment = $existing_comment;
    if (!empty($comment)) {
        $timestamp = date('Y-m-d H:i:s');
        $new_entry = "[" . $timestamp . "] " . $user_name . ": " . $comment;
        $full_comment = $existing_comment ? $existing_comment . "\n---\n" . $new_entry : $new_entry;
    }
    
    if (!empty($uploaded)) {
        if ($receipt_type === 'commission') {
            $all = array_merge($existing_commission, $uploaded);
            $field = 'commission_receipt';
        } else {
            $all = array_merge($existing_payment, $uploaded);
            $field = 'payment_receipt';
        }
        
        $receipts_str = implode(',', $all);
        
        if ($existing) {
            $stmt = $db->prepare("UPDATE numeric_trade_receipts SET $field = ?, comment = ?, updated_at = NOW(), uploaded_by = ? WHERE trade_id = ? AND trade_type = 'trade'");
            $stmt->execute([$receipts_str, $full_comment, $user_name, $trade_id]);
        } else {
            $stmt = $db->prepare("INSERT INTO numeric_trade_receipts (trade_id, trade_type, $field, comment, uploaded_by, created_at, updated_at) VALUES (?, 'trade', ?, ?, ?, NOW(), NOW())");
            $stmt->execute([$trade_id, $receipts_str, $full_comment, $user_name]);
        }
        
        $_SESSION['alert'] = ['Receipt(s) uploaded successfully!', 'success'];
    } elseif (!empty($comment)) {
        if ($existing) {
            $stmt = $db->prepare("UPDATE numeric_trade_receipts SET comment = ?, updated_at = NOW() WHERE trade_id = ? AND trade_type = 'trade'");
            $stmt->execute([$full_comment, $trade_id]);
        } else {
            $stmt = $db->prepare("INSERT INTO numeric_trade_receipts (trade_id, trade_type, comment, uploaded_by, created_at, updated_at) VALUES (?, 'trade', ?, ?, NOW(), NOW())");
            $stmt->execute([$trade_id, $full_comment, $user_name]);
        }
        $_SESSION['alert'] = ['Comment added successfully!', 'success'];
    } else {
        $_SESSION['alert'] = ['No files selected.', 'warning'];
    }
    
    $redirect = 'dealing_sheet.php?' . http_build_query(array_filter([
        'filter' => $_GET['filter'] ?? 'pending',
        'asset_class' => $_GET['asset_class'] ?? 'all',
        'search' => $_GET['search'] ?? ''
    ]));
    header('Location: ' . $redirect);
    exit;
}

// ============================================
// HANDLE DELETE & APPROVAL
// ============================================
if (isset($_GET['delete_receipt'])) {
    $trade_id = (int)$_GET['trade_id'];
    $file_to_delete = $_GET['file'];
    
    $stmt = $db->prepare("SELECT is_approved FROM numeric_trade_receipts WHERE trade_id = ? AND trade_type = 'trade'");
    $stmt->execute([$trade_id]);
    $record = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($record && $record['is_approved'] == 1) {
        $_SESSION['alert'] = ['Cannot delete approved receipts.', 'warning'];
    } else {
        if (strpos($file_to_delete, 'db_') === 0) {
            $parts = explode('.', $file_to_delete);
            $file_id = (int)substr($parts[0], 3);
            $db->prepare("DELETE FROM receipt_files WHERE id = ? AND trade_id = ?")->execute([$file_id, $trade_id]);
        }
        
        $stmt = $db->prepare("SELECT payment_receipt, commission_receipt FROM numeric_trade_receipts WHERE trade_id = ? AND trade_type = 'trade'");
        $stmt->execute([$trade_id]);
        $record = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($record) {
            foreach (['payment_receipt', 'commission_receipt'] as $field) {
                if (!empty($record[$field])) {
                    $list = explode(',', $record[$field]);
                    if (($key = array_search($file_to_delete, $list)) !== false) {
                        unset($list[$key]);
                        $new_str = !empty($list) ? implode(',', $list) : null;
                        $db->prepare("UPDATE numeric_trade_receipts SET $field = ? WHERE trade_id = ? AND trade_type = 'trade'")->execute([$new_str, $trade_id]);
                        $_SESSION['alert'] = ['Receipt deleted.', 'success'];
                        break;
                    }
                }
            }
        }
    }
    
    header('Location: dealing_sheet.php?' . http_build_query(array_filter([
        'filter' => $_GET['filter'] ?? 'pending',
        'asset_class' => $_GET['asset_class'] ?? 'all',
        'search' => $_GET['search'] ?? ''
    ])));
    exit;
}

if (isset($_POST['approve_trade'])) {
    $trade_id = (int)$_POST['trade_id'];
    $action = $_POST['approve_action'] ?? 'approve';
    $is_approved = ($action === 'approve') ? 1 : 2;
    
    $stmt = $db->prepare("SELECT id FROM numeric_trade_receipts WHERE trade_id = ? AND trade_type = 'trade'");
    $stmt->execute([$trade_id]);
    
    if ($stmt->fetch()) {
        $stmt = $db->prepare("UPDATE numeric_trade_receipts SET is_approved = ?, approved_by = ?, approved_at = NOW() WHERE trade_id = ? AND trade_type = 'trade'");
        $stmt->execute([$is_approved, $user_name, $trade_id]);
    } else {
        $stmt = $db->prepare("INSERT INTO numeric_trade_receipts (trade_id, trade_type, is_approved, approved_by, approved_at) VALUES (?, 'trade', ?, ?, NOW())");
        $stmt->execute([$trade_id, $is_approved, $user_name]);
    }
    
    $status = ($action === 'approve') ? 'approved' : 'rejected';
    $db->prepare("UPDATE trades SET approval_status = ? WHERE id = ?")->execute([$status, $trade_id]);
    
    $_SESSION['alert'] = [ucfirst($action) . 'd successfully!', 'success'];
    header('Location: dealing_sheet.php?' . http_build_query(array_filter([
        'filter' => $_GET['filter'] ?? 'pending',
        'asset_class' => $_GET['asset_class'] ?? 'all',
        'search' => $_GET['search'] ?? ''
    ])));
    exit;
}

// ============================================
// GET DATA
// ============================================
$filter = $_GET['filter'] ?? 'pending';
$asset_class_filter = $_GET['asset_class'] ?? 'all';
$search = $_GET['search'] ?? '';

$sql = "
    SELECT 
        t.id,
        t.trade_reference,
        t.asset_class,
        t.security_id,
        t.security_name,
        t.client_name,
        t.trade_side,
        t.quantity,
        t.price,
        t.consideration,
        t.trade_date,
        t.additional_reference,
        t.brokerage_fee_type,
        t.custom_brokerage_fee,
        t.liberty_mode,
        t.final_brokerage_fee,
        tr.payment_receipt,
        tr.commission_receipt,
        tr.comment as receipt_comment,
        tr.is_approved,
        cl.fee_type as client_fee_type,
        cl.default_brokerage_fee,
        cl.liberty_mode as client_liberty_mode
    FROM trades t
    LEFT JOIN numeric_trade_receipts tr ON t.id = tr.trade_id AND tr.trade_type = 'trade'
    LEFT JOIN clients cl ON t.client_cds_account = cl.cds_account
    WHERE t.additional_reference REGEXP '^[0-9]+$'
    AND t.additional_reference IS NOT NULL
    AND t.additional_reference != ''
";

$params = [];

if ($asset_class_filter === 'all') {
    $sql .= " AND ((t.asset_class = 'bond') OR (t.asset_class IN ('equity', 'Exchange Traded Funds') AND LOWER(t.trade_side) = 'buy'))";
} elseif ($asset_class_filter === 'bond') {
    $sql .= " AND t.asset_class = 'bond'";
} elseif ($asset_class_filter === 'equity') {
    $sql .= " AND t.asset_class = 'equity' AND LOWER(t.trade_side) = 'buy'";
} elseif ($asset_class_filter === 'etf') {
    $sql .= " AND t.asset_class = 'Exchange Traded Funds' AND LOWER(t.trade_side) = 'buy'";
}

if ($filter === 'pending') {
    $sql .= " AND (tr.is_approved IS NULL OR tr.is_approved = 0)";
} elseif ($filter === 'approved') {
    $sql .= " AND tr.is_approved = 1";
} elseif ($filter === 'rejected') {
    $sql .= " AND tr.is_approved = 2";
}

if (!empty($search)) {
    $sql .= " AND (t.client_name LIKE ? OR t.security_id LIKE ? OR t.additional_reference LIKE ?)";
    $search_param = "%$search%";
    $params = array_merge($params, [$search_param, $search_param, $search_param]);
}

$sql .= " ORDER BY t.trade_date DESC, t.id DESC LIMIT 500";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$trades = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Group trades manually in PHP
$grouped_trades = [];
foreach ($trades as $trade) {
    $group_key = ($trade['client_name'] ?? '') . '|' . ($trade['security_id'] ?? '') . '|' . ($trade['trade_date'] ?? '');
    
    if (!isset($grouped_trades[$group_key])) {
        $grouped_trades[$group_key] = [
            'id' => $trade['id'] ?? 0,
            'trade_reference' => $trade['trade_reference'] ?? '',
            'asset_class' => $trade['asset_class'] ?? '',
            'security_id' => $trade['security_id'] ?? '',
            'security_name' => $trade['security_name'] ?? '',
            'client_name' => $trade['client_name'] ?? '',
            'trade_side' => $trade['trade_side'] ?? '',
            'quantity' => 0,
            'price' => 0,
            'consideration' => 0,
            'trade_date' => $trade['trade_date'] ?? '',
            'additional_reference' => $trade['additional_reference'] ?? '',
            'brokerage_fee_type' => $trade['brokerage_fee_type'] ?? 'normal',
            'custom_brokerage_fee' => $trade['custom_brokerage_fee'] ?? null,
            'liberty_mode' => $trade['liberty_mode'] ?? $trade['client_liberty_mode'] ?? 'replace_all',
            'final_brokerage_fee' => 0,
            'payment_receipt' => $trade['payment_receipt'] ?? '',
            'commission_receipt' => $trade['commission_receipt'] ?? '',
            'receipt_comment' => $trade['receipt_comment'] ?? '',
            'is_approved' => $trade['is_approved'] ?? 0,
            'client_fee_type' => $trade['client_fee_type'] ?? 'normal',
            'client_default_brokerage_fee' => $trade['default_brokerage_fee'] ?? null,
            'trade_count' => 0,
            'price_sum' => 0,
            'price_count' => 0,
            'fee_sum' => 0
        ];
    }
    
    // Aggregate
    $grouped_trades[$group_key]['quantity'] += (float)($trade['quantity'] ?? 0);
    $grouped_trades[$group_key]['consideration'] += (float)($trade['consideration'] ?? 0);
    $grouped_trades[$group_key]['price_sum'] += (float)($trade['price'] ?? 0);
    $grouped_trades[$group_key]['price_count']++;
    $grouped_trades[$group_key]['trade_count']++;
    $grouped_trades[$group_key]['fee_sum'] += (float)($trade['final_brokerage_fee'] ?? 0);
    
    // Keep the most recent receipt info and fee type
    if (!empty($trade['payment_receipt'])) {
        $grouped_trades[$group_key]['payment_receipt'] = $trade['payment_receipt'];
    }
    if (!empty($trade['commission_receipt'])) {
        $grouped_trades[$group_key]['commission_receipt'] = $trade['commission_receipt'];
    }
    if (!empty($trade['receipt_comment'])) {
        $grouped_trades[$group_key]['receipt_comment'] = $trade['receipt_comment'];
    }
    if (($trade['is_approved'] ?? 0) > ($grouped_trades[$group_key]['is_approved'] ?? 0)) {
        $grouped_trades[$group_key]['is_approved'] = $trade['is_approved'];
    }
}

// Calculate averages and fees
$final_trades = [];
foreach ($grouped_trades as $group) {
    $group['price'] = $group['price_count'] > 0 ? $group['price_sum'] / $group['price_count'] : 0;
    unset($group['price_sum'], $group['price_count']);
    
    // Determine effective rate
    $is_liberty = false;
    $effective_rate = 0;
    $liberty_mode = $group['liberty_mode'] ?? 'replace_all';
    
    if ($group['brokerage_fee_type'] === 'liberty' || $group['brokerage_fee_type'] === 'this_trade') {
        $is_liberty = true;
        // Use custom rate or client default
        $effective_rate = $group['custom_brokerage_fee'] ?? $group['client_default_brokerage_fee'] ?? 1.5;
    }
    
    // Calculate full fees
    $group['fees'] = calculateFullFees($group, $effective_rate, $liberty_mode, $is_liberty);
    
    $final_trades[] = $group;
}

// Sort by trade date descending
usort($final_trades, function($a, $b) {
    return strtotime($b['trade_date'] ?? '1970-01-01') - strtotime($a['trade_date'] ?? '1970-01-01');
});

// Get stats
$stats = ['total' => 0, 'pending' => 0, 'approved' => 0, 'rejected' => 0];
try {
    $stmt = $db->query("
        SELECT 
            COUNT(DISTINCT CONCAT(client_name, '|', security_id, '|', DATE(trade_date))) as total,
            SUM(CASE WHEN tr.is_approved IS NULL OR tr.is_approved = 0 THEN 1 ELSE 0 END) as pending,
            SUM(CASE WHEN tr.is_approved = 1 THEN 1 ELSE 0 END) as approved,
            SUM(CASE WHEN tr.is_approved = 2 THEN 1 ELSE 0 END) as rejected
        FROM trades t
        LEFT JOIN numeric_trade_receipts tr ON t.id = tr.trade_id AND tr.trade_type = 'trade'
        WHERE t.additional_reference REGEXP '^[0-9]+$'
        AND t.additional_reference IS NOT NULL
        AND t.additional_reference != ''
    ");
    $stats = $stmt->fetch(PDO::FETCH_ASSOC) ?: $stats;
} catch (Exception $e) {
    error_log("Stats error: " . $e->getMessage());
}

// Helper function for safe htmlspecialchars
function safeHtml($string) {
    return htmlspecialchars($string ?? '', ENT_QUOTES, 'UTF-8');
}

$page_title = 'Receipt Upload';
include '../includes/header.php';
?>

<style>
.receipt-thumbnails {
    display: flex;
    gap: 4px;
    flex-wrap: wrap;
    align-items: center;
}
.thumbnail {
    position: relative;
    width: 45px;
    height: 45px;
    border: 1px solid #ddd;
    border-radius: 4px;
    overflow: hidden;
    cursor: pointer;
    background: #f8f9fa;
}
.thumbnail img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}
.thumbnail .file-icon {
    display: flex;
    align-items: center;
    justify-content: center;
    height: 100%;
    font-size: 20px;
    color: #666;
}
.thumbnail .delete-btn {
    position: absolute;
    top: -5px;
    right: -5px;
    background: #dc3545;
    color: white;
    border-radius: 50%;
    width: 17px;
    height: 17px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 11px;
    text-decoration: none;
    z-index: 2;
}
.thumbnail .delete-btn:hover {
    transform: scale(1.1);
    color: white;
}
.thumbnail .view-overlay {
    position: absolute;
    top: 0; left: 0; right: 0; bottom: 0;
    background: rgba(0,0,0,0.3);
    display: flex;
    align-items: center;
    justify-content: center;
    opacity: 0;
    transition: opacity 0.2s;
    color: white;
}
.thumbnail:hover .view-overlay {
    opacity: 1;
}
.more-badge {
    background: #6c757d;
    color: white;
    border-radius: 50%;
    width: 28px;
    height: 28px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 11px;
    font-weight: bold;
}
.badge-status {
    font-size: 11px;
    padding: 2px 10px;
    border-radius: 12px;
}
.badge-status.pending {
    background: #fff3cd;
    color: #856404;
}
.badge-status.approved {
    background: #d4edda;
    color: #155724;
}
.badge-status.rejected {
    background: #f8d7da;
    color: #721c24;
}
.badge-asset {
    font-size: 10px;
    padding: 1px 8px;
    border-radius: 3px;
    background: #e9ecef;
}
.stat-box {
    background: #f8f9fa;
    border: 1px solid #e9ecef;
    border-radius: 6px;
    padding: 8px 12px;
    text-align: center;
}
.stat-box .stat-number {
    font-size: 20px;
    font-weight: 600;
}
.stat-box .stat-label {
    font-size: 11px;
    color: #666;
}
.comment-display {
    background: #f8f9fa;
    border-left: 2px solid #6c757d;
    padding: 4px 8px;
    border-radius: 3px;
    font-size: 12px;
    max-width: 180px;
    margin-top: 4px;
}
.comment-display .comment-text {
    white-space: pre-wrap;
    word-wrap: break-word;
    max-height: 50px;
    overflow-y: auto;
}
.filter-section {
    background: #f8f9fa;
    padding: 12px 15px;
    border-radius: 6px;
    margin-bottom: 15px;
}
.filter-section .form-label {
    font-size: 12px;
    font-weight: 600;
    margin-bottom: 2px;
}
.commission-display {
    font-size: 11px;
    padding: 2px 0;
}
.commission-display .badge {
    font-size: 9px;
    padding: 2px 6px;
}
.commission-details {
    font-size: 9px;
    color: #6c757d;
    margin-top: 2px;
    line-height: 1.3;
}
.commission-details .tier-row {
    padding-left: 8px;
    border-left: 2px solid #dee2e6;
    margin: 1px 0;
}
.commission-total {
    font-weight: 600;
    font-size: 12px;
    margin-top: 3px;
    padding-top: 3px;
    border-top: 1px solid #dee2e6;
}
</style>

<div class="container-fluid">

    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h4 class="mb-0"><i class="bi bi-receipt"></i> Receipt Upload</h4>
            <small class="text-muted">Numeric Reference Trades with Full Fee Calculation</small>
        </div>
        <div>
            <span class="badge bg-secondary"><?php echo count($final_trades); ?> trades</span>
        </div>
    </div>

    <!-- Alert -->
    <?php if (isset($_SESSION['alert'])): ?>
        <div class="alert alert-<?php echo safeHtml($_SESSION['alert'][1]); ?> alert-dismissible fade show">
            <?php echo safeHtml($_SESSION['alert'][0]); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php unset($_SESSION['alert']); ?>
    <?php endif; ?>

    <!-- Stats -->
    <div class="row g-2 mb-3">
        <div class="col-3 col-md-2">
            <div class="stat-box">
                <div class="stat-number"><?php echo (int)($stats['total'] ?? 0); ?></div>
                <div class="stat-label">Total</div>
            </div>
        </div>
        <div class="col-3 col-md-2">
            <div class="stat-box">
                <div class="stat-number" style="color:#856404;"><?php echo (int)($stats['pending'] ?? 0); ?></div>
                <div class="stat-label">Pending</div>
            </div>
        </div>
        <div class="col-3 col-md-2">
            <div class="stat-box">
                <div class="stat-number" style="color:#155724;"><?php echo (int)($stats['approved'] ?? 0); ?></div>
                <div class="stat-label">Approved</div>
            </div>
        </div>
        <div class="col-3 col-md-2">
            <div class="stat-box">
                <div class="stat-number" style="color:#721c24;"><?php echo (int)($stats['rejected'] ?? 0); ?></div>
                <div class="stat-label">Rejected</div>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <div class="filter-section">
        <form method="GET" class="row g-2">
            <div class="col-6 col-md-3">
                <label class="form-label">Status</label>
                <select class="form-select form-select-sm" name="filter" onchange="this.form.submit()">
                    <option value="pending" <?php echo $filter === 'pending' ? 'selected' : ''; ?>>Pending</option>
                    <option value="approved" <?php echo $filter === 'approved' ? 'selected' : ''; ?>>Approved</option>
                    <option value="rejected" <?php echo $filter === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                    <option value="all" <?php echo $filter === 'all' ? 'selected' : ''; ?>>All</option>
                </select>
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label">Asset</label>
                <select class="form-select form-select-sm" name="asset_class" onchange="this.form.submit()">
                    <option value="all" <?php echo $asset_class_filter === 'all' ? 'selected' : ''; ?>>All</option>
                    <option value="bond" <?php echo $asset_class_filter === 'bond' ? 'selected' : ''; ?>>Bond</option>
                    <option value="equity" <?php echo $asset_class_filter === 'equity' ? 'selected' : ''; ?>>Equity</option>
                    <option value="etf" <?php echo $asset_class_filter === 'etf' ? 'selected' : ''; ?>>ETF</option>
                </select>
            </div>
            <div class="col-6 col-md-4">
                <label class="form-label">Search</label>
                <input type="text" class="form-control form-control-sm" name="search" placeholder="Client, Security, Ref..." value="<?php echo safeHtml($search); ?>">
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label">&nbsp;</label>
                <button type="submit" class="btn btn-secondary btn-sm w-100">Apply</button>
            </div>
        </form>
    </div>

    <!-- Trades Table -->
    <div class="card">
        <div class="card-header">
            <h6 class="mb-0"><i class="bi bi-table"></i> Trades with Fee Breakdown</h6>
        </div>
        <div class="card-body p-0">
            <?php if (empty($final_trades)): ?>
                <div class="text-center py-5">
                    <i class="bi bi-inbox" style="font-size:40px;color:#dee2e6;"></i>
                    <p class="text-muted mt-2">No trades found.</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Client</th>
                                <th>Security</th>
                                <th>Side</th>
                                <th class="text-end">Qty</th>
                                <th class="text-end">Value</th>
                                <th class="text-end" style="min-width:180px;">Fees Breakdown</th>
                                <th>Receipts</th>
                                <th>Status</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($final_trades as $trade): 
                                $isBond = ($trade['asset_class'] ?? '') === 'bond';
                                $payment_receipts = !empty($trade['payment_receipt']) ? explode(',', $trade['payment_receipt']) : [];
                                $commission_receipts = !empty($trade['commission_receipt']) ? explode(',', $trade['commission_receipt']) : [];
                                $hasPayment = !empty($payment_receipts[0]);
                                $hasCommission = $isBond && !empty($commission_receipts[0]);
                                $isApproved = isset($trade['is_approved']) ? (int)$trade['is_approved'] : 0;
                                $statusText = $isApproved === 1 ? 'Approved' : ($isApproved === 2 ? 'Rejected' : 'Pending');
                                $statusClass = $isApproved === 1 ? 'approved' : ($isApproved === 2 ? 'rejected' : 'pending');
                                
                                $displayQty = $isBond ? 'TZS ' . number_format($trade['quantity'] ?? 0, 2) : number_format($trade['quantity'] ?? 0);
                                $displayValue = 'TZS ' . number_format($trade['consideration'] ?? 0, 2);
                                
                                $fees = $trade['fees'] ?? [];
                                $isSell = strtolower($trade['trade_side'] ?? '') === 'sell';
                            ?>
                                <tr>
                                    <td><?php echo safeHtml($trade['client_name'] ?? ''); ?></td>
                                    <td>
                                        <?php echo safeHtml($trade['security_id'] ?? ''); ?>
                                        <span class="badge-asset"><?php echo $isBond ? 'Bond' : ucfirst($trade['asset_class'] ?? ''); ?></span>
                                        <?php if ($trade['brokerage_fee_type'] !== 'normal'): ?>
                                            <span class="badge bg-warning ms-1" style="font-size:8px;">LIBERTY</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge <?php echo $isSell ? 'bg-danger' : 'bg-success'; ?>">
                                            <?php echo strtoupper($trade['trade_side'] ?? ''); ?>
                                        </span>
                                    </td>
                                    <td class="text-end"><?php echo $displayQty; ?></td>
                                    <td class="text-end fw-bold"><?php echo $displayValue; ?></td>
                                    <td class="text-end">
                                        <?php if (!empty($fees) && isset($fees['total'])): ?>
                                            <div class="commission-display">
                                                <span class="badge <?php echo $fees['is_deducted'] ? 'bg-danger' : 'bg-success'; ?>">
                                                    <?php echo $fees['is_deducted'] ? '➖ Deducted' : '➕ Included'; ?>
                                                    <?php if ($fees['is_liberty'] ?? false): ?>
                                                        <i class="bi bi-star-fill ms-1"></i>
                                                    <?php endif; ?>
                                                </span>
                                                <div class="fw-bold mt-1">
                                                    TZS <?php echo number_format($fees['total'], 2); ?>
                                                </div>
                                                <div class="commission-details">
                                                    <?php if (!empty($fees['tier_details'])): ?>
                                                        <?php foreach ($fees['tier_details'] as $tier): ?>
                                                            <div class="tier-row">
                                                                <?php echo safeHtml($tier['label']); ?>:
                                                                TZS <?php echo number_format($tier['fee'], 2); ?>
                                                            </div>
                                                        <?php endforeach; ?>
                                                    <?php endif; ?>
                                                    <div>VAT: TZS <?php echo number_format($fees['vat'] ?? 0, 2); ?></div>
                                                    <div>CMSA: TZS <?php echo number_format($fees['cmsa'] ?? 0, 2); ?></div>
                                                    <div>DSE: TZS <?php echo number_format($fees['dse'] ?? 0, 2); ?></div>
                                                    <?php if (isset($fees['fidelity']) && $fees['fidelity'] > 0): ?>
                                                        <div>Fidelity: TZS <?php echo number_format($fees['fidelity'], 2); ?></div>
                                                    <?php endif; ?>
                                                    <div>CDS: TZS <?php echo number_format($fees['csd'] ?? 0, 2); ?></div>
                                                    <div class="commission-total">
                                                        Net: TZS <?php echo number_format($fees['net_amount'] ?? 0, 2); ?>
                                                        <br>
                                                        <small class="text-muted">(<?php echo $fees['label'] ?? ''; ?>)</small>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <!-- Payment Receipts -->
                                        <?php if ($hasPayment): ?>
                                            <div class="receipt-thumbnails">
                                                <?php 
                                                $count = 0;
                                                foreach ($payment_receipts as $file):
                                                    $file = trim($file);
                                                    if (empty($file) || $count >= 3) continue;
                                                    $count++;
                                                    $url = getReceiptUrl($file);
                                                    $isImg = isImageReceipt($file);
                                                ?>
                                                    <div class="thumbnail" onclick="viewReceipt('<?php echo $url; ?>')">
                                                        <?php if ($isImg): ?>
                                                            <img src="<?php echo $url; ?>" alt="receipt">
                                                        <?php else: ?>
                                                            <div class="file-icon"><i class="bi bi-file-pdf"></i></div>
                                                        <?php endif; ?>
                                                        <div class="view-overlay"><i class="bi bi-eye"></i></div>
                                                        <?php if ($isApproved !== 1): ?>
                                                            <a href="dealing_sheet.php?delete_receipt=1&trade_id=<?php echo $trade['id']; ?>&file=<?php echo urlencode($file); ?>&filter=<?php echo urlencode($filter); ?>&asset_class=<?php echo urlencode($asset_class_filter); ?>&search=<?php echo urlencode($search); ?>" 
                                                               class="delete-btn" onclick="event.stopPropagation(); return confirm('Delete?')">
                                                                <i class="bi bi-x"></i>
                                                            </a>
                                                        <?php endif; ?>
                                                    </div>
                                                <?php endforeach; ?>
                                                <?php if (count($payment_receipts) > 3): ?>
                                                    <div class="more-badge">+<?php echo count($payment_receipts) - 3; ?></div>
                                                <?php endif; ?>
                                            </div>
                                            <small class="text-muted">Payment</small>
                                        <?php endif; ?>
                                        
                                        <!-- Commission Receipts (Bonds only) -->
                                        <?php if ($hasCommission): ?>
                                            <div class="receipt-thumbnails mt-1">
                                                <?php 
                                                $count = 0;
                                                foreach ($commission_receipts as $file):
                                                    $file = trim($file);
                                                    if (empty($file) || $count >= 3) continue;
                                                    $count++;
                                                    $url = getReceiptUrl($file);
                                                    $isImg = isImageReceipt($file);
                                                ?>
                                                    <div class="thumbnail" onclick="viewReceipt('<?php echo $url; ?>')">
                                                        <?php if ($isImg): ?>
                                                            <img src="<?php echo $url; ?>" alt="receipt">
                                                        <?php else: ?>
                                                            <div class="file-icon"><i class="bi bi-file-pdf"></i></div>
                                                        <?php endif; ?>
                                                        <div class="view-overlay"><i class="bi bi-eye"></i></div>
                                                        <?php if ($isApproved !== 1): ?>
                                                            <a href="dealing_sheet.php?delete_receipt=1&trade_id=<?php echo $trade['id']; ?>&file=<?php echo urlencode($file); ?>&filter=<?php echo urlencode($filter); ?>&asset_class=<?php echo urlencode($asset_class_filter); ?>&search=<?php echo urlencode($search); ?>" 
                                                               class="delete-btn" onclick="event.stopPropagation(); return confirm('Delete?')">
                                                                <i class="bi bi-x"></i>
                                                            </a>
                                                        <?php endif; ?>
                                                    </div>
                                                <?php endforeach; ?>
                                                <?php if (count($commission_receipts) > 3): ?>
                                                    <div class="more-badge">+<?php echo count($commission_receipts) - 3; ?></div>
                                                <?php endif; ?>
                                            </div>
                                            <small class="text-muted">Commission</small>
                                        <?php endif; ?>
                                        
                                        <!-- Comment -->
                                        <?php if (!empty($trade['receipt_comment'])): ?>
                                            <div class="comment-display">
                                                <div class="comment-text"><?php echo nl2br(safeHtml($trade['receipt_comment'])); ?></div>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge-status <?php echo $statusClass; ?>"><?php echo $statusText; ?></span>
                                    </td>
                                    <td class="text-end">
                                        <div class="btn-group btn-group-sm">
                                            <?php if ($isApproved !== 1): ?>
                                                <button class="btn btn-outline-secondary" onclick="openUpload(<?php echo $trade['id']; ?>, 'payment')" title="Upload Payment">
                                                    <i class="bi bi-cash"></i>
                                                </button>
                                                <?php if ($isBond): ?>
                                                    <button class="btn btn-outline-secondary" onclick="openUpload(<?php echo $trade['id']; ?>, 'commission')" title="Upload Commission">
                                                        <i class="bi bi-percent"></i>
                                                    </button>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                            <?php if ($user_role === 'finance_officer' && $isApproved !== 1): ?>
                                                <form method="POST" style="display:inline" onsubmit="return confirm('Approve?')">
                                                    <input type="hidden" name="trade_id" value="<?php echo $trade['id']; ?>">
                                                    <input type="hidden" name="approve_trade" value="1">
                                                    <input type="hidden" name="approve_action" value="approve">
                                                    <button type="submit" class="btn btn-outline-success" title="Approve">
                                                        <i class="bi bi-check-lg"></i>
                                                    </button>
                                                </form>
                                                <form method="POST" style="display:inline" onsubmit="return confirm('Reject?')">
                                                    <input type="hidden" name="trade_id" value="<?php echo $trade['id']; ?>">
                                                    <input type="hidden" name="approve_trade" value="1">
                                                    <input type="hidden" name="approve_action" value="reject">
                                                    <button type="submit" class="btn btn-outline-danger" title="Reject">
                                                        <i class="bi bi-x-lg"></i>
                                                    </button>
                                                </form>
                                            <?php endif; ?>
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

<!-- Upload Modal -->
<div class="modal fade" id="uploadModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-upload"></i> Upload Receipt</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" enctype="multipart/form-data">
                <div class="modal-body">
                    <input type="hidden" name="trade_id" id="modal_trade_id" value="">
                    <input type="hidden" name="upload_receipt" value="1">
                    <input type="hidden" name="receipt_type" id="modal_receipt_type" value="payment">
                    <input type="hidden" name="filter" value="<?php echo safeHtml($filter); ?>">
                    <input type="hidden" name="asset_class" value="<?php echo safeHtml($asset_class_filter); ?>">
                    <input type="hidden" name="search" value="<?php echo safeHtml($search); ?>">
                    
                    <div class="alert alert-info">
                        <strong id="modal_receipt_label">Payment Receipt</strong>
                        <span class="text-muted">- Upload confirmation</span>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Select Files</label>
                        <input type="file" class="form-control" name="receipt_files[]" accept="image/*,.pdf" multiple>
                        <div class="form-text">JPG, PNG, GIF, PDF (Max 5MB each)</div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Comment (Optional)</label>
                        <textarea class="form-control" name="receipt_comment" rows="2" placeholder="Add a note..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-secondary">Upload</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Receipt Viewer Modal -->
<div class="modal fade" id="viewerModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Receipt</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body text-center" id="viewerContent">
                <div class="py-3">Loading...</div>
            </div>
        </div>
    </div>
</div>

<script>
// View receipt
function viewReceipt(url) {
    const content = document.getElementById('viewerContent');
    const isImage = url.match(/\.(jpg|jpeg|png|gif)$/i);
    
    if (isImage) {
        content.innerHTML = `<img src="${url}" class="img-fluid" style="max-height:70vh;object-fit:contain;">`;
    } else {
        content.innerHTML = `
            <div class="py-4">
                <i class="bi bi-file-pdf" style="font-size:48px;color:#dc3545;"></i>
                <p class="mt-2">PDF Document</p>
                <a href="${url}" target="_blank" class="btn btn-danger">View PDF</a>
            </div>
        `;
    }
    
    new bootstrap.Modal(document.getElementById('viewerModal')).show();
}

// Open upload modal
function openUpload(tradeId, type) {
    document.getElementById('modal_trade_id').value = tradeId;
    document.getElementById('modal_receipt_type').value = type;
    
    const label = document.getElementById('modal_receipt_label');
    if (type === 'commission') {
        label.textContent = 'Commission Receipt';
    } else {
        label.textContent = 'Payment Receipt';
    }
    
    new bootstrap.Modal(document.getElementById('uploadModal')).show();
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
